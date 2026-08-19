using System.IO;
using System.Net;
using System.Net.Sockets;
using BlueVPN.Windows.Models;

namespace BlueVPN.Windows.Services;

/// <summary>
/// Starts the v2rayN-sourced Xray core as a localhost proxy and the v2rayN-sourced
/// sing-box core as the Windows TUN owner. The remote endpoint is resolved before
/// the TUN comes up and routed directly to make loop prevention deterministic.
/// </summary>
public sealed class XrayProcessController : IDisposable
{
    private readonly ManagedCoreProcess _xray = new("xray");
    private readonly ManagedCoreProcess _singBox = new("sing-box-tun");
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

    public bool IsRunning => _xray.IsRunning && _singBox.IsRunning;
    public string RuntimeStatus() => _runtime.RuntimeStatus();

    public async Task StartAsync(string configJson, ProxyEndpoint endpoint, CancellationToken ct = default)
    {
        Stop();
        var bundle = _runtime.ResolveV2RayNBundle();
        var xray = bundle.XrayPath;
        var singBox = bundle.SingBoxPath;
        _ = bundle.WintunPath;

        var xrayConfig = Path.Combine(_stateDir, "xray-local-proxy.json");
        await File.WriteAllTextAsync(xrayConfig, configJson, ct).ConfigureAwait(false);
        await _xray.StartAsync(xray, ["run", "-c", xrayConfig], Path.GetDirectoryName(xray), ct).ConfigureAwait(false);
        await WaitForPortAsync("127.0.0.1", XrayConfigBuilder.LocalSocksPort, TimeSpan.FromSeconds(12), ct).ConfigureAwait(false);

        // Validate the selected proxy before touching Windows routes. This keeps
        // a dead endpoint from briefly hijacking the whole system through TUN.
        var proxyTrace = await ConnectivityProbe.SnapshotViaSocksAsync(
            _settings.ProbeUrl,
            "127.0.0.1",
            XrayConfigBuilder.LocalSocksPort,
            TimeSpan.FromSeconds(7),
            ct).ConfigureAwait(false);
        if (!proxyTrace.Reachable || string.IsNullOrWhiteSpace(proxyTrace.PublicIp))
            throw new InvalidOperationException($"هسته اتصال مسیر را باز کرد اما اینترنت از تونل عبور نکرد: {proxyTrace.Error}");

        var directIps = await ResolveEndpointIpsAsync(endpoint.Host, ct).ConfigureAwait(false);
        var tunConfig = Path.Combine(_stateDir, "sing-box-v2rayn-tun.json");
        await File.WriteAllTextAsync(tunConfig, V2RayNTunConfigBuilder.Build(_settings, XrayConfigBuilder.LocalSocksPort, endpoint.Host, directIps), ct).ConfigureAwait(false);
        await _singBox.StartAsync(singBox, ["run", "-c", tunConfig], Path.GetDirectoryName(singBox), ct).ConfigureAwait(false);
        await Task.Delay(900, ct).ConfigureAwait(false);
        if (!IsRunning) throw new InvalidOperationException("هسته TUN ویندوز پایدار نماند.");
    }

    public void Stop()
    {
        // TUN owner first so Windows restores its routes before Xray disappears.
        _singBox.Stop();
        _xray.Stop();
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
                linked.CancelAfter(TimeSpan.FromSeconds(2));
                await tcp.ConnectAsync(host, port, linked.Token).ConfigureAwait(false);
                if (tcp.Connected) return;
            }
            catch (Exception ex) { last = ex; }
            await Task.Delay(300, ct).ConfigureAwait(false);
        }
        throw new InvalidOperationException($"پروکسی داخلی BlueVPN آماده نشد: {last?.Message ?? "port unavailable"}");
    }

    public void Dispose()
    {
        Stop();
        _singBox.Dispose();
        _xray.Dispose();
    }
}
