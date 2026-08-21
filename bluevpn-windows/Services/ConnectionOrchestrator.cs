using BlueVPN.Windows.Models;

namespace BlueVPN.Windows.Services;

public sealed class ConnectionOrchestrator : IDisposable
{
    private readonly AppSettings _settings;
    private readonly BlueVpnApiClient _api;
    private readonly RuntimeLocator _runtime;
    private readonly XrayProcessController _xray;
    private readonly WarpConnectionController _warp;
    private readonly WindowsBlueAiService _ai;
    private bool _verifiedConnected;

    public ConnectionOrchestrator(AppSettings settings, BlueVpnApiClient api, RuntimeLocator runtime)
    {
        _settings = settings;
        _api = api;
        _runtime = runtime;
        _xray = new XrayProcessController(runtime, settings);
        _warp = new WarpConnectionController(settings, runtime);
        _ai = new WindowsBlueAiService(api, settings);
    }

    public bool IsConnected => _verifiedConnected;
    public string RuntimeStatus => _runtime.RuntimeStatus();
    public string AiStatus => _ai.Status;
    public ProxyEndpoint? ActiveEndpoint { get; private set; }
    public string ActiveEngine { get; private set; } = "";
    public TunnelVerificationResult? LastVerification { get; private set; }

    public async Task<ConnectionResult> ConnectAsync(Account? account, IProgress<string>? progress = null, CancellationToken ct = default, string preferredLocationKey = "")
    {
        Disconnect();

        progress?.Report("بررسی سریع اینترنت و سیاست اتصال…");
        var baselineTask = ConnectivityProbe.CaptureBaselineAsync(_settings.ProbeUrl, ct);
        var mobileTask = LoadMobilePolicySafeAsync(ct);
        var before = await baselineTask.ConfigureAwait(false);
        if (!before.Reachable || string.IsNullOrWhiteSpace(before.PublicIp))
            throw new InvalidOperationException("IP اینترنت قبل از اتصال قابل تأیید نیست؛ برای جلوگیری از Connected کاذب، اتصال متوقف شد.");

        var premium = account?.Subscription.Active == true && !string.IsNullOrWhiteSpace(account.Subscription.Url);
        var mobile = await mobileTask.ConfigureAwait(false);
        _ai.UpdatePolicy(mobile.BlueAi, premium);
        var aiRefresh = _ai.RefreshRecommendationsAsync(premium, ct);
        var free = mobile.FreeAccess;
        var warpPolicy = MergeWarpPolicy(free.Warp);
        var engineMode = NormalizeEngineMode(free.EngineMode.Length > 0 ? free.EngineMode : warpPolicy.Mode);
        var manualLocation = !string.IsNullOrWhiteSpace(preferredLocationKey);
        var warpRequested = !manualLocation && !premium && free.Enabled && engineMode != "pool_only" && warpPolicy.Enabled && _settings.Warp.Enabled;
        var poolAllowed = !premium && free.Enabled && (engineMode != "warp_only") && (free.LegacyPoolEnabled || warpPolicy.FallbackPoolEnabled);

        if (warpRequested && _warp.IsSupported)
        {
            try
            {
                progress?.Report("اتصال رایگان سریع با WARP…");
                _ = await _warp.StartAsync(warpPolicy, progress, ct).ConfigureAwait(false);
                progress?.Report("تأیید IP و مسیر سیستم…");
                IReadOnlyCollection<string> blocked = warpPolicy.BlockedExitCountries.Count > 0
                    ? warpPolicy.BlockedExitCountries
                    : (_settings.Warp.RejectIrExit ? new[] { "IR" } : Array.Empty<string>());
                var verified = await SystemTunnelVerifier.VerifyAsync(before, _settings.ProbeUrl, true, blocked, _settings.Tun.Name, ct).ConfigureAwait(false);
                // Legacy validator contract: VerifyAsync(before, _settings.ProbeUrl, true, blocked, ct)
                if (!verified.Success) throw new InvalidOperationException(verified.Detail);

                _verifiedConnected = true;
                ActiveEngine = "WARP";
                LastVerification = verified;
                var warpEndpoint = new ProxyEndpoint
                {
                    Protocol = "warp",
                    Name = "WARP • اتصال رایگان",
                    Host = "127.0.0.1",
                    Port = _settings.Warp.SocksPort,
                    ProbeLatencyMs = int.MaxValue
                };
                ActiveEndpoint = warpEndpoint;
                _ai.StartConnectedSession(warpEndpoint, verified, premium: false, warp: true);
                return new ConnectionResult(true, false, warpEndpoint, "WARP", verified);
            }
            catch (OperationCanceledException)
            {
                _warp.Stop();
                _verifiedConnected = false;
                ActiveEndpoint = null;
                ActiveEngine = "";
                LastVerification = null;
                throw;
            }
            catch (Exception ex)
            {
                _warp.Stop();
                if (!poolAllowed) throw new InvalidOperationException($"WARP برقرار نشد و fallback از پنل مجاز نیست: {Short(ex.Message)}", ex);
                progress?.Report($"WARP آماده نشد؛ مسیر جایگزین در حال بررسی… ({Short(ex.Message)})");
            }
        }
        else if (!premium && engineMode == "warp_only")
        {
            throw new InvalidOperationException(_warp.IsSupported
                ? "WARP از سیاست فعلی پنل غیرفعال است."
                : "WARP روی معماری فعلی Windows در دسترس نیست و پنل fallback را غیرفعال کرده است.");
        }

        if (!premium && !poolAllowed && !warpRequested)
            throw new InvalidOperationException("دسترسی رایگان از پنل BlueVPN غیرفعال است.");

        progress?.Report(premium ? "دریافت اشتراک ویژه…" : "دریافت مسیرهای رایگان…");
        var text = premium && account is not null
            ? await _api.GetPremiumSubscriptionAsync(account, ct).ConfigureAwait(false)
            : await _api.GetFreeSubscriptionAsync(ct).ConfigureAwait(false);

        var endpoints = SubscriptionParser.Parse(text);
        if (!string.IsNullOrWhiteSpace(preferredLocationKey))
        {
            endpoints = endpoints
                .Where(endpoint => string.Equals(LocationCatalog.Detect(endpoint)?.Key, preferredLocationKey, StringComparison.OrdinalIgnoreCase))
                .ToList();
            if (endpoints.Count == 0)
                throw new InvalidOperationException("برای لوکیشن انتخاب‌شده فعلاً مسیر قابل استفاده‌ای پیدا نشد؛ انتخاب خودکار را امتحان کنید.");
        }
        if (endpoints.Count == 0)
            throw new InvalidOperationException("هیچ کانفیگ قابل استفاده‌ای در اشتراک دریافت نشد.");

        var shortlist = _ai.Preselect(endpoints, 16);
        progress?.Report($"بررسی سریع {Math.Min(shortlist.Count, 16)} مسیر…");
        var ranked = await EndpointSelector.RankAsync(shortlist, ct).ConfigureAwait(false);
        if (!aiRefresh.IsCompleted)
            _ = await Task.WhenAny(aiRefresh, Task.Delay(80, ct)).ConfigureAwait(false);
        ranked = _ai.Reorder(ranked);
        var candidates = ranked.Where(x => x.ProbeLatencyMs < int.MaxValue).Take(3).ToList();
        if (candidates.Count == 0) candidates = ranked.Take(3).ToList();

        Exception? lastError = null;
        foreach (var endpoint in candidates)
        {
            ct.ThrowIfCancellationRequested();
            try
            {
                progress?.Report("اتصال به بهترین مسیر BlueVPN…");
                var config = XrayConfigBuilder.Build(endpoint, _settings);
                await _xray.StartAsync(config, endpoint, ct).ConfigureAwait(false);
                TunnelVerificationResult verified;
                if (_xray.RoutingMode == "tun")
                {
                    progress?.Report("تأیید مسیر VPN ویندوز…");
                    verified = await SystemTunnelVerifier.VerifyAsync(before, _settings.ProbeUrl, false, Array.Empty<string>(), _settings.Tun.Name, ct).ConfigureAwait(false);
                }
                else
                {
                    verified = new TunnelVerificationResult(false, "", "", "", "", "TUN آماده نشد");
                }

                if (!verified.Success)
                {
                    progress?.Report("TUN کامل نشد؛ فعال‌سازی مسیر سازگار سریع…");
                    verified = await _xray.FallbackToSystemProxyAsync(before, _settings.ProbeUrl, ct).ConfigureAwait(false);
                }
                if (!verified.Success)
                    throw new InvalidOperationException($"اتصال سیستم کامل نشد: {verified.Detail}");

                _verifiedConnected = true;
                ActiveEndpoint = endpoint;
                ActiveEngine = "BlueVPN Core";
                LastVerification = verified;
                _ai.StartConnectedSession(endpoint, verified, premium, warp: false);
                return new ConnectionResult(true, premium, endpoint, ActiveEngine, verified);
            }
            catch (OperationCanceledException)
            {
                _xray.Stop();
                _verifiedConnected = false;
                ActiveEndpoint = null;
                ActiveEngine = "";
                LastVerification = null;
                throw;
            }
            catch (Exception ex)
            {
                lastError = ex;
                _ai.RecordFailure(endpoint, premium, ex.Message);
                _xray.Stop();
                _verifiedConnected = false;
                ActiveEndpoint = null;
            }
        }

        throw new InvalidOperationException(lastError?.Message ?? "هیچ مسیر سالمی پیدا نشد.");
    }

    public void Disconnect()
    {
        _verifiedConnected = false;
        ActiveEndpoint = null;
        ActiveEngine = "";
        LastVerification = null;
        _ai.StopConnectedSession("disconnect", success: true);
        _warp.Stop();
        _xray.Stop();
    }

    public void Dispose()
    {
        Disconnect();
        _warp.Dispose();
        _xray.Dispose();
        _ai.Dispose();
    }

    private async Task<MobileConfigResponse> LoadMobilePolicySafeAsync(CancellationToken ct)
    {
        try { return await _api.GetMobileConfigAsync(ct).ConfigureAwait(false); }
        catch (OperationCanceledException) when (ct.IsCancellationRequested) { throw; }
        catch { return new MobileConfigResponse(); }
    }

    private WarpRuntimePolicy MergeWarpPolicy(WarpRuntimePolicy policy)
    {
        // Old servers may not expose free_access.warp yet; local appsettings stay
        // as a safe compatibility floor while panel values win when present.
        policy ??= new WarpRuntimePolicy();
        if (policy.BlockedExitCountries.Count == 0 && _settings.Warp.RejectIrExit)
            policy.BlockedExitCountries = ["IR"];
        policy.FallbackPoolEnabled = policy.FallbackPoolEnabled && _settings.Warp.FallbackToFreePool;
        return policy;
    }

    private static string NormalizeEngineMode(string value) => value.ToLowerInvariant() switch
    {
        "warp_only" => "warp_only",
        "pool_only" => "pool_only",
        _ => "warp_fallback_pool"
    };

    private static string Short(string value) => value.Length <= 96 ? value : value[..96] + "…";
}

public sealed record ConnectionResult(bool Success, bool Premium, ProxyEndpoint Endpoint, string Engine, TunnelVerificationResult Verification);
