using System.IO;
using System.Net.NetworkInformation;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using BlueVPN.Windows.Models;

namespace BlueVPN.Windows.Services;

/// <summary>
/// Windows BlueAI closed loop. It is deliberately best-effort: AI can improve
/// route ordering and report live tunnel health, but it can never block a VPN
/// connection when the control plane is slow or unavailable.
/// </summary>
public sealed class WindowsBlueAiService : IDisposable
{
    private const int AiSchemaVersion = 8;
    private const int MaxCloudScores = 800;
    private static readonly TimeSpan HeartbeatInterval = TimeSpan.FromSeconds(15);
    private static readonly TimeSpan RecommendationBudget = TimeSpan.FromMilliseconds(1400);

    private readonly BlueVpnApiClient _api;
    private readonly AppSettings _settings;
    private readonly object _sync = new();
    private readonly string _statePath;
    private AiState _state;
    private BlueAiRuntimeConfig _policy = new();
    private bool _premium;
    private CancellationTokenSource? _sessionCts;
    private ActiveSession? _session;
    private string _status = "BlueAI آماده";

    public WindowsBlueAiService(BlueVpnApiClient api, AppSettings settings)
    {
        _api = api;
        _settings = settings;
        var dir = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "BlueVPN");
        Directory.CreateDirectory(dir);
        _statePath = Path.Combine(dir, "blueai-windows.json");
        _state = LoadState();
    }

    public string Status
    {
        get { lock (_sync) return _status; }
    }

    private bool Enabled
    {
        get
        {
            lock (_sync)
                return _policy.Enabled && (_premium ? _policy.PremiumEnabled : _policy.FreeEnabled);
        }
    }

    public void UpdatePolicy(BlueAiRuntimeConfig? policy, bool premium)
    {
        lock (_sync)
        {
            _policy = policy ?? new BlueAiRuntimeConfig();
            _premium = premium;
            _status = Enabled ? "BlueAI فعال • بهینه‌سازی مسیر" : "BlueAI از پنل غیرفعال";
        }
    }

    public async Task RefreshRecommendationsAsync(bool premium, CancellationToken ct)
    {
        UpdatePremiumOnly(premium);
        if (!Enabled) return;

        var network = NetworkContext.Capture();
        try
        {
            using var budget = CancellationTokenSource.CreateLinkedTokenSource(ct);
            budget.CancelAfter(RecommendationBudget);
            var response = await _api.GetAiRecommendationsAsync(
                network.Operator,
                network.Type,
                "balanced",
                premium ? "premium" : "free",
                budget.Token).ConfigureAwait(false);

            if (!response.Enabled || !response.TierEnabled) return;
            lock (_sync)
            {
                _state.CloudScores.Clear();
                foreach (var row in response.Recommendations
                    .OrderByDescending(x => x.Score)
                    .Take(MaxCloudScores))
                {
                    if (!string.IsNullOrWhiteSpace(row.ConfigKey))
                        _state.CloudScores[row.ConfigKey] = Math.Clamp(row.Score, 0, 100);
                    if (!string.IsNullOrWhiteSpace(row.LocationKey))
                    {
                        var key = "location:" + row.LocationKey.Trim().ToLowerInvariant();
                        if (!_state.CloudScores.TryGetValue(key, out var old) || row.Score > old)
                            _state.CloudScores[key] = Math.Clamp(row.Score, 0, 100);
                    }
                }
                _state.LastCloudSync = DateTimeOffset.UtcNow;
                _status = response.Recommendations.Count > 0
                    ? $"BlueAI فعال • {response.Recommendations.Count} سیگنال مسیر"
                    : "BlueAI فعال • یادگیری محلی";
                SaveStateLocked();
            }
        }
        catch (OperationCanceledException) when (!ct.IsCancellationRequested)
        {
            lock (_sync) _status = "BlueAI فعال • کش محلی";
        }
        catch
        {
            lock (_sync) _status = "BlueAI فعال • حالت آفلاین";
        }
    }

    /// <summary>
    /// Uses remembered success/failure/cloud signals before the TCP probe fan-out.
    /// A small exploration tail prevents BlueAI from permanently hiding new routes.
    /// </summary>
    public IReadOnlyList<ProxyEndpoint> Preselect(IReadOnlyList<ProxyEndpoint> endpoints, int limit = 16)
    {
        if (endpoints.Count <= limit || !Enabled) return endpoints;
        limit = Math.Clamp(limit, 8, 24);
        var scored = endpoints
            .Select((endpoint, index) => new { endpoint, index, score = HistoricalScore(endpoint) })
            .OrderByDescending(x => x.score)
            .ThenBy(x => x.index)
            .ToList();

        var explore = Math.Min(4, limit / 4);
        var chosen = scored.Take(limit - explore).Select(x => x.endpoint).ToList();
        foreach (var row in scored.AsEnumerable().Reverse())
        {
            if (chosen.Contains(row.endpoint)) continue;
            chosen.Add(row.endpoint);
            if (chosen.Count >= limit) break;
        }
        return chosen;
    }

    /// <summary>
    /// Blends real TCP latency with BlueAI cloud/local reliability. Live latency
    /// remains the strongest input so stale AI data can never override a dead route.
    /// </summary>
    public IReadOnlyList<ProxyEndpoint> Reorder(IReadOnlyList<ProxyEndpoint> ranked)
    {
        if (!Enabled) return ranked;
        return ranked
            .OrderByDescending(CombinedScore)
            .ThenBy(x => x.ProbeLatencyMs)
            .ToList();
    }

    public void RecordFailure(ProxyEndpoint endpoint, bool premium, string reason)
    {
        UpdatePremiumOnly(premium);
        var key = Fingerprint(endpoint);
        var location = LocationCatalog.Detect(endpoint);
        lock (_sync)
        {
            var row = GetPersonalLocked(key);
            row.Failures++;
            row.LastFailure = DateTimeOffset.UtcNow;
            SaveStateLocked();
            _status = "BlueAI • مسیر ناموفق ثبت شد";
        }

        FireAndForget(PostEventSafeAsync(new
        {
            consent = true,
            event_type = "failure",
            success = false,
            connected = false,
            device_id = DeviceIdentity.GetOrCreate(),
            device_model = DeviceIdentity.FriendlyName,
            app_version = _settings.Version,
            ai_client_version = _settings.Version,
            ai_schema_version = AiSchemaVersion,
            ai_engine_family = "blueai-control-plane-v3",
            config_key = key,
            location_key = location?.Key ?? "unknown",
            location_title = location?.Title ?? "Windows",
            @operator = NetworkContext.Capture().Operator,
            network_type = NetworkContext.Capture().Type,
            mode = "balanced",
            plan_tier = premium ? "premium" : "free",
            failure_reason = Short(reason, 380),
            failure_class = "windows_connect",
            network_signature = NetworkSignature(),
            hour_bucket = DateTimeOffset.Now.Hour
        }, CancellationToken.None));
    }

    public void StartConnectedSession(ProxyEndpoint endpoint, TunnelVerificationResult verification, bool premium, bool warp)
    {
        StopConnectedSession("replaced", success: true);
        UpdatePremiumOnly(premium);
        if (!Enabled) return;

        var location = LocationCatalog.Detect(endpoint);
        var country = verification.Country.Trim().ToLowerInvariant();
        var locationKey = location?.Key ?? (country.Length == 2 ? country : (warp ? "warp" : "unknown"));
        var locationTitle = location?.Title ?? (warp ? "WARP" : (verification.Country.Length > 0 ? verification.Country : "Windows"));
        var session = new ActiveSession
        {
            Id = Guid.NewGuid().ToString("N"),
            StartedAt = DateTimeOffset.UtcNow,
            Endpoint = endpoint,
            ConfigKey = Fingerprint(endpoint, locationKey),
            LocationKey = locationKey,
            LocationTitle = locationTitle,
            Premium = premium,
            Warp = warp,
            Sequence = 0,
            LastKnownPing = endpoint.ProbeLatencyMs == int.MaxValue ? 0 : endpoint.ProbeLatencyMs
        };

        lock (_sync)
        {
            _session = session;
            _sessionCts = new CancellationTokenSource();
            _status = "BlueAI • اتصال شناسایی شد";
        }
        FireAndForget(RunHeartbeatLoopAsync(session, _sessionCts.Token));
    }

    public void StopConnectedSession(string reason, bool success)
    {
        ActiveSession? session;
        CancellationTokenSource? cts;
        lock (_sync)
        {
            session = _session;
            cts = _sessionCts;
            _session = null;
            _sessionCts = null;
            if (Enabled) _status = "BlueAI فعال • آماده اتصال بعدی";
        }
        if (session is null) return;
        try { cts?.Cancel(); } catch { }
        cts?.Dispose();

        var duration = Math.Max(0L, (long)(DateTimeOffset.UtcNow - session.StartedAt).TotalSeconds);
        lock (_sync)
        {
            var personal = GetPersonalLocked(session.ConfigKey);
            personal.DurationSeconds += duration;
            if (success) personal.Successes++; else personal.Failures++;
            if (success) personal.LastSuccess = DateTimeOffset.UtcNow; else personal.LastFailure = DateTimeOffset.UtcNow;
            SaveStateLocked();
        }

        var network = NetworkContext.Capture();
        FireAndForget(PostEventSafeAsync(new
        {
            consent = true,
            event_type = "session",
            live_state = "disconnected",
            connected = false,
            success,
            duration_seconds = duration,
            failure_reason = success ? "" : Short(reason, 380),
            device_id = DeviceIdentity.GetOrCreate(),
            device_model = DeviceIdentity.FriendlyName,
            app_version = _settings.Version,
            ai_client_version = _settings.Version,
            ai_schema_version = AiSchemaVersion,
            ai_engine_family = "blueai-control-plane-v3",
            session_id = session.Id,
            config_key = session.ConfigKey,
            location_key = session.LocationKey,
            location_title = session.LocationTitle,
            @operator = network.Operator,
            network_type = network.Type,
            mode = "balanced",
            plan_tier = session.Premium ? "premium" : "free",
            ping_ms = Math.Clamp(session.LastKnownPing, 0, 10_000),
            network_signature = NetworkSignature(),
            hour_bucket = DateTimeOffset.Now.Hour
        }, CancellationToken.None));
    }

    private async Task RunHeartbeatLoopAsync(ActiveSession session, CancellationToken ct)
    {
        // Immediate heartbeat makes the Manager dashboard reflect CONNECTED in
        // seconds instead of waiting for the first periodic interval.
        await SendHeartbeatSafeAsync(session, ct).ConfigureAwait(false);
        using var timer = new PeriodicTimer(HeartbeatInterval);
        try
        {
            while (await timer.WaitForNextTickAsync(ct).ConfigureAwait(false))
                await SendHeartbeatSafeAsync(session, ct).ConfigureAwait(false);
        }
        catch (OperationCanceledException) { }
    }

    private async Task SendHeartbeatSafeAsync(ActiveSession session, CancellationToken ct)
    {
        if (!Enabled) return;
        try
        {
            TunnelProbeMeasurement measurement = session.Warp
                ? await ConnectivityProbe.MeasureDirectAsync(_settings.ProbeUrl, ct).ConfigureAwait(false)
                : await ConnectivityProbe.MeasureViaHttpProxyAsync(_settings.ProbeUrl, "127.0.0.1", XrayConfigBuilder.LocalHttpPort, ct).ConfigureAwait(false);
            if (!measurement.Success) return;

            var network = NetworkContext.Capture();
            var seq = Interlocked.Increment(ref session.Sequence);
            session.LastKnownPing = measurement.LatencyMs;
            var duration = Math.Max(0L, (long)(DateTimeOffset.UtcNow - session.StartedAt).TotalSeconds);
            var health = HealthScore(measurement.LatencyMs);
            var response = await _api.PostAiEventAsync(new
            {
                consent = true,
                event_type = "heartbeat",
                success = true,
                connected = true,
                tunnel_running = true,
                vpn_transport = true,
                internet_verified = true,
                verification_source = measurement.Source,
                probe_age_ms = 0,
                heartbeat_seq = seq,
                traffic_active = false,
                duration_seconds = duration,
                ping_ms = measurement.LatencyMs,
                ping_min_ms = measurement.LatencyMs,
                ping_max_ms = measurement.LatencyMs,
                jitter_ms = 0,
                packet_loss_x100 = 0,
                ping_samples = 1,
                health_score = health,
                device_id = DeviceIdentity.GetOrCreate(),
                device_model = DeviceIdentity.FriendlyName,
                app_version = _settings.Version,
                ai_client_version = _settings.Version,
                ai_schema_version = AiSchemaVersion,
                ai_engine_family = "blueai-control-plane-v3",
                session_id = session.Id,
                config_key = session.ConfigKey,
                location_key = session.LocationKey,
                location_title = session.LocationTitle,
                @operator = network.Operator,
                network_type = network.Type,
                mode = "balanced",
                plan_tier = session.Premium ? "premium" : "free",
                network_signature = NetworkSignature(),
                hour_bucket = DateTimeOffset.Now.Hour
            }, ct).ConfigureAwait(false);

            lock (_sync)
                _status = response.Live && response.Verified
                    ? $"BlueAI زنده • {measurement.LatencyMs} ms"
                    : "BlueAI • اتصال ثبت شد";
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested) { }
        catch
        {
            // Telemetry must never tear down or delay a working tunnel.
            lock (_sync) _status = "BlueAI فعال • همگام‌سازی بعدی";
        }
    }

    private async Task PostEventSafeAsync(object payload, CancellationToken ct)
    {
        try { _ = await _api.PostAiEventAsync(payload, ct).ConfigureAwait(false); }
        catch { }
    }

    private void UpdatePremiumOnly(bool premium)
    {
        lock (_sync) _premium = premium;
    }

    private int HistoricalScore(ProxyEndpoint endpoint)
    {
        var key = Fingerprint(endpoint);
        var location = LocationCatalog.Detect(endpoint)?.Key ?? "unknown";
        lock (_sync)
        {
            var cloud = _state.CloudScores.TryGetValue(key, out var exact)
                ? exact
                : (_state.CloudScores.TryGetValue("location:" + location, out var loc) ? loc : 50);
            var personal = PersonalReliabilityLocked(key);
            var penalty = RecentFailurePenaltyLocked(key);
            return Math.Clamp((cloud * 55 + personal * 45) / 100 - penalty, 0, 100);
        }
    }

    private int CombinedScore(ProxyEndpoint endpoint)
    {
        var latencyScore = endpoint.ProbeLatencyMs == int.MaxValue
            ? 0
            : endpoint.ProbeLatencyMs switch
            {
                <= 70 => 100,
                <= 120 => 90,
                <= 200 => 76,
                <= 350 => 60,
                <= 600 => 42,
                _ => 25
            };
        var jitterPenalty = endpoint.ProbeJitterMs switch
        {
            int.MaxValue => 500,
            <= 20 => 0,
            <= 50 => 120,
            <= 120 => 280,
            <= 250 => 520,
            _ => 760
        };
        var samplePenalty = Math.Max(0, endpoint.ProbeSampleCount - endpoint.ProbeSuccessCount) * 420;
        var history = HistoricalScore(endpoint);
        // A just-working endpoint receives a bounded sticky bonus, matching the
        // recovery strategy used by resilient clients: retry recent success
        // before a full rescan, but never override a dead live probe.
        var stickyBonus = endpoint.ProbeLatencyMs == int.MaxValue ? 0 : RecentSuccessBonus(endpoint);
        return latencyScore * 55 + history * 30 + stickyBonus + SuccessRateBonus(endpoint) - jitterPenalty - samplePenalty;
    }

    private int SuccessRateBonus(ProxyEndpoint endpoint)
    {
        if (endpoint.ProbeSampleCount <= 0) return 0;
        var rate = (double)endpoint.ProbeSuccessCount / endpoint.ProbeSampleCount;
        return (int)Math.Clamp(rate * 600, 0, 600);
    }

    private int RecentSuccessBonus(ProxyEndpoint endpoint)
    {
        var key = Fingerprint(endpoint);
        lock (_sync)
        {
            if (!_state.Personal.TryGetValue(key, out var row) || row.LastSuccess is null) return 0;
            if (row.LastFailure is not null && row.LastFailure >= row.LastSuccess) return 0;
            var age = DateTimeOffset.UtcNow - row.LastSuccess.Value;
            if (age < TimeSpan.Zero) return 0;
            if (age < TimeSpan.FromMinutes(3)) return 650;
            if (age < TimeSpan.FromMinutes(15)) return 350;
            if (age < TimeSpan.FromHours(2)) return 140;
            return 0;
        }
    }

    private int PersonalReliabilityLocked(string key)
    {
        if (!_state.Personal.TryGetValue(key, out var row)) return 50;
        var total = row.Successes + row.Failures;
        if (total <= 0) return 50;
        var reliability = row.Successes * 100 / Math.Max(1, total);
        var loyalty = (int)Math.Clamp(row.DurationSeconds / 900L, 0, 12);
        return Math.Clamp(reliability * 88 / 100 + loyalty, 0, 100);
    }

    private int RecentFailurePenaltyLocked(string key)
    {
        if (!_state.Personal.TryGetValue(key, out var row) || row.LastFailure is null) return 0;
        var age = DateTimeOffset.UtcNow - row.LastFailure.Value;
        if (age < TimeSpan.FromMinutes(3)) return 42;
        if (age < TimeSpan.FromMinutes(15)) return 26;
        if (age < TimeSpan.FromHours(2)) return 12;
        return 0;
    }

    private PersonalRoute GetPersonalLocked(string key)
    {
        if (!_state.Personal.TryGetValue(key, out var row))
        {
            row = new PersonalRoute();
            _state.Personal[key] = row;
        }
        return row;
    }

    public static string Fingerprint(ProxyEndpoint endpoint, string? locationOverride = null)
    {
        var location = locationOverride ?? LocationCatalog.Detect(endpoint)?.Key ?? "unknown";
        var raw = $"{endpoint.Host}|{endpoint.Name}|{location}";
        var digest = SHA256.HashData(Encoding.UTF8.GetBytes(raw));
        return Convert.ToHexString(digest).ToLowerInvariant()[..40];
    }

    private string NetworkSignature()
    {
        var network = NetworkContext.Capture();
        var raw = $"windows|{network.Type}|{network.Operator}";
        return Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(raw))).ToLowerInvariant()[..40];
    }

    private static int HealthScore(int latencyMs) => latencyMs switch
    {
        <= 80 => 96,
        <= 140 => 90,
        <= 220 => 80,
        <= 350 => 68,
        <= 600 => 52,
        _ => 35
    };

    private AiState LoadState()
    {
        try
        {
            if (!File.Exists(_statePath)) return new AiState();
            return JsonSerializer.Deserialize<AiState>(File.ReadAllText(_statePath), AppSettings.JsonOptions()) ?? new AiState();
        }
        catch { return new AiState(); }
    }

    private void SaveStateLocked()
    {
        try
        {
            var temp = _statePath + ".tmp";
            File.WriteAllText(temp, JsonSerializer.Serialize(_state, AppSettings.JsonOptions()));
            File.Move(temp, _statePath, overwrite: true);
        }
        catch { }
    }

    private static void FireAndForget(Task task) => _ = task.ContinueWith(
        _ => { }, CancellationToken.None, TaskContinuationOptions.ExecuteSynchronously, TaskScheduler.Default);

    private static string Short(string value, int max) => string.IsNullOrWhiteSpace(value)
        ? "unknown"
        : (value.Length <= max ? value : value[..max]);

    public void Dispose()
    {
        StopConnectedSession("dispose", success: true);
    }

    private sealed class AiState
    {
        public Dictionary<string, int> CloudScores { get; set; } = new(StringComparer.OrdinalIgnoreCase);
        public Dictionary<string, PersonalRoute> Personal { get; set; } = new(StringComparer.OrdinalIgnoreCase);
        public DateTimeOffset? LastCloudSync { get; set; }
    }

    private sealed class PersonalRoute
    {
        public int Successes { get; set; }
        public int Failures { get; set; }
        public long DurationSeconds { get; set; }
        public DateTimeOffset? LastSuccess { get; set; }
        public DateTimeOffset? LastFailure { get; set; }
    }

    private sealed class ActiveSession
    {
        public string Id { get; init; } = "";
        public DateTimeOffset StartedAt { get; init; }
        public ProxyEndpoint Endpoint { get; init; } = new();
        public string ConfigKey { get; init; } = "";
        public string LocationKey { get; init; } = "unknown";
        public string LocationTitle { get; init; } = "Windows";
        public bool Premium { get; init; }
        public bool Warp { get; init; }
        public long Sequence;
        public int LastKnownPing;
    }

    private sealed record NetworkContext(string Operator, string Type)
    {
        public static NetworkContext Capture()
        {
            try
            {
                var active = NetworkInterface.GetAllNetworkInterfaces()
                    .Where(x => x.OperationalStatus == OperationalStatus.Up && x.NetworkInterfaceType != NetworkInterfaceType.Loopback)
                    .Where(x => x.GetIPProperties().GatewayAddresses.Any(g => g.Address is not null))
                    .ToArray();
                if (active.Any(x => x.NetworkInterfaceType == NetworkInterfaceType.Wireless80211))
                    return new("windows", "wifi");
                if (active.Any(x => x.NetworkInterfaceType is NetworkInterfaceType.Ethernet or NetworkInterfaceType.GigabitEthernet or NetworkInterfaceType.FastEthernetFx or NetworkInterfaceType.FastEthernetT))
                    return new("windows", "ethernet");
                if (active.Any(x => x.NetworkInterfaceType == NetworkInterfaceType.Ppp))
                    return new("windows", "mobile");
            }
            catch { }
            return new("windows", "unknown");
        }
    }
}
