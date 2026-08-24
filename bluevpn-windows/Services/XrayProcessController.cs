using System.IO;
using System.Net;
using System.Net.Sockets;
using System.Security.Principal;
using BlueVPN.Windows.Models;

namespace BlueVPN.Windows.Services;

/// <summary>
/// Xray is always the protocol core. sing-box is used only as the Windows TUN
/// owner. If a particular Windows build/driver blocks TUN routing, BlueVPN falls
/// back to the same Xray core through Windows system proxy instead of leaving the
/// user with a fake "connected" state or no Internet.
/// </summary>
public sealed class XrayProcessController : IDisposable
{
    private readonly ManagedCoreProcess _xray = new("xray");
    private readonly ManagedCoreProcess _singBox = new("sing-box-tun");
    private readonly WindowsSystemProxyController _systemProxy = new();
    private readonly RuntimeLocator _runtime;
    private readonly AppSettings _settings;
    private readonly string _stateDir;
    private string _singBoxPath = "";
    private string _tunConfigPath = "";
    private string _remoteHost = "";
    private IReadOnlyList<string> _remoteIps = Array.Empty<string>();
    private int _tunStackIndex;
    private static readonly string[] TunStacks = ["mixed", "gvisor"];

    public XrayProcessController(RuntimeLocator runtime, AppSettings settings)
    {
        _runtime = runtime;
        _settings = settings;
        _stateDir = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "BlueVPN", "runtime-state");
        Directory.CreateDirectory(_stateDir);
    }

    public bool IsRunning => _xray.IsRunning && (_singBox.IsRunning || _systemProxy.IsActive);
    public string RoutingMode { get; private set; } = "none";
    public string ActiveTunStack => RoutingMode == "tun" ? TunStacks[_tunStackIndex] : "none";
    public string RuntimeStatus() => _runtime.RuntimeStatus();

    public async Task StartAsync(string configJson, ProxyEndpoint endpoint, CancellationToken ct = default)
    {
        Stop();
        EnsureElevatedForTun();
        var bundle = _runtime.ResolveV2RayNBundle();
        var xray = bundle.XrayPath;
        _singBoxPath = bundle.SingBoxPath;
        _ = bundle.WintunPath;

        var xrayConfig = Path.Combine(_stateDir, "xray-local-proxy.json");
        await File.WriteAllTextAsync(xrayConfig, configJson, ct).ConfigureAwait(false);
        await _xray.StartAsync(xray, ["run", "-c", xrayConfig], Path.GetDirectoryName(xray), ct).ConfigureAwait(false);
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

        _remoteHost = endpoint.Host;
        _remoteIps = await ResolveEndpointIpsAsync(endpoint.Host, ct).ConfigureAwait(false);
        _tunConfigPath = Path.Combine(_stateDir, "sing-box-v2rayn-tun.json");
        _tunStackIndex = 0;
        await StartTunStackAsync(TunStacks[_tunStackIndex], ct).ConfigureAwait(false);
        await Task.Delay(350, ct).ConfigureAwait(false);
        if (!_singBox.IsRunning)
        {
            RoutingMode = "tun_unavailable";
            return;
        }
        RoutingMode = "tun";
    }

    public async Task<bool> TryAlternateTunStackAsync(CancellationToken ct = default)
    {
        if (!_xray.IsRunning || _tunStackIndex + 1 >= TunStacks.Length) return false;
        _tunStackIndex++;
        _singBox.Stop();
        await Task.Delay(180, ct).ConfigureAwait(false);
        await StartTunStackAsync(TunStacks[_tunStackIndex], ct).ConfigureAwait(false);
        await Task.Delay(350, ct).ConfigureAwait(false);
        RoutingMode = _singBox.IsRunning ? "tun" : "tun_unavailable";
        return RoutingMode == "tun";
    }

    private async Task StartTunStackAsync(string stack, CancellationToken ct)
    {
        var json = V2RayNTunConfigBuilder.Build(_settings, XrayConfigBuilder.LocalSocksPort, _remoteHost, _remoteIps, stack);
        await File.WriteAllTextAsync(_tunConfigPath, json, ct).ConfigureAwait(false);
        await _singBox.StartAsync(_singBoxPath, ["run", "-c", _tunConfigPath], Path.GetDirectoryName(_singBoxPath), ct).ConfigureAwait(false);
    }

    public async Task<TunnelVerificationResult> FallbackToSystemProxyAsync(
        ConnectivitySnapshot before,
        string probeUrl,
        CancellationToken ct = default)
    {
        // Remove broken TUN routes before enabling the compatibility path.
        _singBox.Stop();
        await Task.Delay(250, ct).ConfigureAwait(false);
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
        // TUN owner first so Windows restores routes before Xray disappears.
        _singBox.Stop();
        _xray.Stop();
        RoutingMode = "none";
    }

    private static async Task<IReadOnlyList<string>> ResolveEndpointIpsAsync(string host, CancellationToken ct)
    {
        if (IPAddress.TryParse(host, out var literal)) return [literal.ToString()];
        try
        {
            var addresses = await Dns.GetHostAddressesAsync(host, ct).ConfigureAwait(false);
            return addresses.Select(x => x.ToString()).Distinct(StringComparer.OrdinalIgnoreCase).ToArray();
        }
        catch
        {
            // process_name + domain direct rules remain as fallback.
            return [];
        }
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

    private static void EnsureElevatedForTun()
    {
        if (!OperatingSystem.IsWindows()) return;
        try
        {
            using var identity = WindowsIdentity.GetCurrent();
            var principal = new WindowsPrincipal(identity);
            if (!principal.IsInRole(WindowsBuiltInRole.Administrator))
                throw new InvalidOperationException("برای فعال‌سازی VPN سراسری، BlueVPN باید با دسترسی Administrator اجرا شود.");
        }
        catch (InvalidOperationException) { throw; }
        catch { /* app.manifest is the final elevation guard */ }
    }

    public void Dispose()
    {
        Stop();
        _singBox.Dispose();
        _xray.Dispose();
    }
}
