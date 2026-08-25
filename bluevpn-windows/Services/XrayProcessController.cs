using System.IO;
using System.Net.Sockets;
using BlueVPN.Windows.Models;

namespace BlueVPN.Windows.Services;

/// <summary>
/// Xray is the sole protocol and routing core for normal Windows connections.
/// BlueVPN exposes its verified local HTTP/SOCKS inbounds through Windows System
/// Proxy and never starts sing-box/Wintun in this path.
/// </summary>
public sealed class XrayProcessController : IDisposable
{
    private readonly ManagedCoreProcess _xray = new("xray");
    private readonly WindowsSystemProxyController _systemProxy = new();
    private readonly RuntimeLocator _runtime;
    private readonly AppSettings _settings;
    private readonly string _stateDir;

    public XrayProcessController(RuntimeLocator runtime, AppSettings settings)
    {
        _runtime = runtime;
        _settings = settings;
        _stateDir = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "BlueVPN", "runtime-state");
        Directory.CreateDirectory(_stateDir);
    }

    public bool IsRunning => _xray.IsRunning && _systemProxy.IsActive;
    public string RoutingMode { get; private set; } = "none";
    public string RuntimeStatus() => _runtime.RuntimeStatus();

    public async Task StartAsync(string configJson, ProxyEndpoint endpoint, CancellationToken ct = default)
    {
        Stop();
        // Xray is the primary and only connection core. Normal connections do
        // not start sing-box/Wintun and therefore do not require elevation.
        var xrayConfig = Path.Combine(_stateDir, "xray-local-proxy.json");
        await File.WriteAllTextAsync(xrayConfig, configJson, ct).ConfigureAwait(false);
        var xray = _runtime.ResolveXray();
        var candidates = new[] { xray }.Concat(_runtime.ResolveXrayCandidates())
            .Distinct(StringComparer.OrdinalIgnoreCase);
        var failures = new List<string>();
        var started = false;
        foreach (var candidate in candidates)
        {
            try
            {
                var workDir = Path.GetDirectoryName(candidate);
                await ManagedCoreProcess.ValidateAsync(candidate, ["run", "-test", "-c", xrayConfig], workDir, ct).ConfigureAwait(false);
                await _xray.StartAsync(candidate, ["run", "-c", xrayConfig], workDir, ct).ConfigureAwait(false);
                started = true;
                break;
            }
            catch (OperationCanceledException) { throw; }
            catch (Exception ex)
            {
                _xray.Stop();
                failures.Add($"{Path.GetFileName(Path.GetDirectoryName(candidate))}: {ex.Message}");
                _runtime.RejectOverrideContaining(candidate, ex.Message);
            }
        }
        if (!started)
            throw new InvalidOperationException($"هیچ Runtime سالمی برای Xray اجرا نشد. {string.Join(" || ", failures)}");
        await WaitForPortAsync("127.0.0.1", XrayConfigBuilder.LocalSocksPort, TimeSpan.FromSeconds(10), ct).ConfigureAwait(false);
        await WaitForPortAsync("127.0.0.1", XrayConfigBuilder.LocalHttpPort, TimeSpan.FromSeconds(8), ct).ConfigureAwait(false);

        // Validate Xray before touching the default route. Multiple Cloudflare
        // trace endpoints are raced by ConnectivityProbe, so a single filtered
        // host no longer makes a healthy server look dead.
        var proxyTrace = await ConnectivityProbe.SnapshotViaSocksAsync(
            _settings.ProbeUrl,
            "127.0.0.1",
            XrayConfigBuilder.LocalSocksPort,
            TimeSpan.FromSeconds(6),
            ct).ConfigureAwait(false);
        if (!proxyTrace.Reachable || string.IsNullOrWhiteSpace(proxyTrace.PublicIp))
            throw new InvalidOperationException($"هسته Xray به سرور رسید ولی اینترنت از آن عبور نکرد: {proxyTrace.Error}");

        RoutingMode = "xray_ready";
    }

    public async Task<TunnelVerificationResult> FallbackToSystemProxyAsync(
        ConnectivitySnapshot before,
        string probeUrl,
        CancellationToken ct = default)
    {
        // Enable the OS proxy only after Xray's own SOCKS egress was verified.
        if (!_xray.IsRunning)
            return new(false, "", "", "", "", "هسته Xray متوقف شده است.");

        _systemProxy.Enable(XrayConfigBuilder.LocalHttpPort, XrayConfigBuilder.LocalSocksPort);
        RoutingMode = "system_proxy";
        var verified = await SystemTunnelVerifier.VerifySystemProxyAsync(
            before, probeUrl, XrayConfigBuilder.LocalHttpPort, ct).ConfigureAwait(false);
        if (!verified.Success)
        {
            _systemProxy.Restore();
            RoutingMode = "none";
        }
        return verified;
    }

    public void Stop()
    {
        _systemProxy.Restore();
        _xray.Stop();
        RoutingMode = "none";
    }

    private static async Task WaitForPortAsync(string host, int port, TimeSpan timeout, CancellationToken ct)
    {
        var stop = DateTimeOffset.UtcNow + timeout;
        Exception? last = null;
        while (DateTimeOffset.UtcNow < stop)
        {
            ct.ThrowIfCancellationRequested();
            try
            {
                using var tcp = new TcpClient();
                using var linked = CancellationTokenSource.CreateLinkedTokenSource(ct);
                linked.CancelAfter(TimeSpan.FromSeconds(1));
                await tcp.ConnectAsync(host, port, linked.Token).ConfigureAwait(false);
                if (tcp.Connected) return;
            }
            catch (Exception ex) { last = ex; }
            await Task.Delay(180, ct).ConfigureAwait(false);
        }
        throw new InvalidOperationException($"پروکسی داخلی BlueVPN آماده نشد: {last?.Message ?? "port unavailable"}");
    }

    public void Dispose()
    {
        Stop();
        _xray.Dispose();
    }
}
