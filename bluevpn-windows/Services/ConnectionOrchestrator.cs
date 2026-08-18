using BlueVPN.Windows.Models;

namespace BlueVPN.Windows.Services;

public sealed class ConnectionOrchestrator : IDisposable
{
    private readonly AppSettings _settings;
    private readonly BlueVpnApiClient _api;
    private readonly XrayProcessController _xray;

    public ConnectionOrchestrator(AppSettings settings, BlueVpnApiClient api)
    {
        _settings = settings;
        _api = api;
        _xray = new XrayProcessController();
    }

    public bool IsConnected => _xray.IsRunning;
    public string RuntimeStatus => _xray.RuntimeStatus();
    public ProxyEndpoint? ActiveEndpoint { get; private set; }

    public async Task<ConnectionResult> ConnectAsync(Account? account, IProgress<string>? progress = null, CancellationToken ct = default)
    {
        Disconnect();
        var premium = account?.Subscription.Active == true && !string.IsNullOrWhiteSpace(account.Subscription.Url);
        progress?.Report(premium ? "دریافت اشتراک ویژه…" : "دریافت Pool رایگان…");
        var text = premium && account is not null
            ? await _api.GetPremiumSubscriptionAsync(account, ct)
            : await _api.GetFreeSubscriptionAsync(ct);

        var endpoints = SubscriptionParser.Parse(text);
        if (endpoints.Count == 0)
            throw new InvalidOperationException("هیچ کانفیگ قابل استفاده‌ای در اشتراک دریافت نشد.");

        progress?.Report($"بررسی سریع {Math.Min(endpoints.Count, 40)} مسیر…");
        var ranked = await EndpointSelector.RankAsync(endpoints, ct);
        var candidates = ranked.Where(x => x.ProbeLatencyMs < int.MaxValue).Take(6).ToList();
        if (candidates.Count == 0) candidates = ranked.Take(3).ToList();

        Exception? lastError = null;
        foreach (var endpoint in candidates)
        {
            ct.ThrowIfCancellationRequested();
            try
            {
                progress?.Report($"اتصال به {endpoint.DisplayName}…");
                var config = XrayConfigBuilder.Build(endpoint, _settings);
                await _xray.StartAsync(config, ct);
                await Task.Delay(1600, ct);
                if (!await ConnectivityProbe.VerifyAsync(_settings.ProbeUrl, ct))
                    throw new InvalidOperationException("تونل بالا آمد اما دسترسی اینترنت تأیید نشد.");

                ActiveEndpoint = endpoint;
                return new ConnectionResult(true, premium, endpoint);
            }
            catch (Exception ex)
            {
                lastError = ex;
                _xray.Stop();
                ActiveEndpoint = null;
            }
        }

        throw new InvalidOperationException(lastError?.Message ?? "هیچ مسیر سالمی پیدا نشد.");
    }

    public void Disconnect()
    {
        ActiveEndpoint = null;
        _xray.Stop();
    }

    public void Dispose() => _xray.Dispose();
}

public sealed record ConnectionResult(bool Success, bool Premium, ProxyEndpoint Endpoint);
