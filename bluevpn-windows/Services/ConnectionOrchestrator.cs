using BlueVPN.Windows.Models;

namespace BlueVPN.Windows.Services;

public sealed class ConnectionOrchestrator : IDisposable
{
    private readonly AppSettings _settings;
    private readonly BlueVpnApiClient _api;
    private readonly RuntimeLocator _runtime;
    private readonly XrayProcessController _xray;
    private readonly WarpConnectionController _warp;
    private bool _verifiedConnected;

    public ConnectionOrchestrator(AppSettings settings, BlueVpnApiClient api, RuntimeLocator runtime)
    {
        _settings = settings;
        _api = api;
        _runtime = runtime;
        _xray = new XrayProcessController(runtime);
        _warp = new WarpConnectionController(settings, runtime);
    }

    public bool IsConnected => _verifiedConnected;
    public string RuntimeStatus => _runtime.RuntimeStatus();
    public ProxyEndpoint? ActiveEndpoint { get; private set; }
    public string ActiveEngine { get; private set; } = "";
    public TunnelVerificationResult? LastVerification { get; private set; }

    public async Task<ConnectionResult> ConnectAsync(Account? account, IProgress<string>? progress = null, CancellationToken ct = default)
    {
        Disconnect();
        var before = await ConnectivityProbe.SnapshotAsync(_settings.ProbeUrl, ct);
        var premium = account?.Subscription.Active == true && !string.IsNullOrWhiteSpace(account.Subscription.Url);

        if (!premium && _settings.Warp.Enabled && _warp.IsSupported)
        {
            try
            {
                progress?.Report("اتصال رایگان سریع با WARP…");
                await _warp.StartAsync(progress, ct);
                progress?.Report("تأیید IP و مسیر سیستم…");
                var verified = await SystemTunnelVerifier.VerifyAsync(before, _settings.ProbeUrl, requireWarp: true, rejectIran: _settings.Warp.RejectIrExit, ct);
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
                return new ConnectionResult(true, false, warpEndpoint, "WARP", verified);
            }
            catch (OperationCanceledException) { throw; }
            catch (Exception ex)
            {
                _warp.Stop();
                if (!_settings.Warp.FallbackToFreePool) throw;
                progress?.Report($"WARP آماده نشد؛ مسیر جایگزین در حال بررسی… ({Short(ex.Message)})");
            }
        }

        progress?.Report(premium ? "دریافت اشتراک ویژه…" : "دریافت مسیرهای رایگان…");
        var text = premium && account is not null
            ? await _api.GetPremiumSubscriptionAsync(account, ct)
            : await _api.GetFreeSubscriptionAsync(ct);

        var endpoints = SubscriptionParser.Parse(text);
        if (endpoints.Count == 0)
            throw new InvalidOperationException("هیچ کانفیگ قابل استفاده‌ای در اشتراک دریافت نشد.");

        progress?.Report($"بررسی سریع {Math.Min(endpoints.Count, 40)} مسیر…");
        var ranked = await EndpointSelector.RankAsync(endpoints, ct);
        var candidates = ranked.Where(x => x.ProbeLatencyMs < int.MaxValue).Take(8).ToList();
        if (candidates.Count == 0) candidates = ranked.Take(4).ToList();

        Exception? lastError = null;
        foreach (var endpoint in candidates)
        {
            ct.ThrowIfCancellationRequested();
            try
            {
                progress?.Report($"اتصال به {endpoint.DisplayName}…");
                var config = XrayConfigBuilder.Build(endpoint, _settings);
                await _xray.StartAsync(config, ct);
                progress?.Report("تأیید VPN سراسری…");
                var verified = await SystemTunnelVerifier.VerifyAsync(before, _settings.ProbeUrl, requireWarp: false, rejectIran: false, ct);
                if (!verified.Success)
                    throw new InvalidOperationException($"تونل کامل نشد: {verified.Detail}");

                _verifiedConnected = true;
                ActiveEndpoint = endpoint;
                ActiveEngine = "v2rayN/Xray";
                LastVerification = verified;
                return new ConnectionResult(true, premium, endpoint, ActiveEngine, verified);
            }
            catch (OperationCanceledException) { throw; }
            catch (Exception ex)
            {
                lastError = ex;
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
        _warp.Stop();
        _xray.Stop();
    }

    public void Dispose()
    {
        Disconnect();
        _warp.Dispose();
        _xray.Dispose();
    }

    private static string Short(string value) => value.Length <= 80 ? value : value[..80] + "…";
}

public sealed record ConnectionResult(bool Success, bool Premium, ProxyEndpoint Endpoint, string Engine, TunnelVerificationResult Verification);
