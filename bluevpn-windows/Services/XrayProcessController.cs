using System.IO;
using System.Net.Sockets;

namespace BlueVPN.Windows.Services;

/// <summary>
/// Starts the v2rayN-sourced Xray core as a localhost proxy and the v2rayN-sourced
/// sing-box core as the Windows TUN owner. A connection is not considered ready
/// until the local Xray SOCKS port is listening and sing-box has stayed alive.
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

    public async Task StartAsync(string configJson, CancellationToken ct = default)
    {
        Stop();
        var xray = _runtime.ResolveXray();
        var singBox = _runtime.ResolveSingBox();
        _ = _runtime.ResolveWintun();

        var xrayConfig = Path.Combine(_stateDir, "xray-local-proxy.json");
        await File.WriteAllTextAsync(xrayConfig, configJson, ct);
        await _xray.StartAsync(xray, ["run", "-c", xrayConfig], Path.GetDirectoryName(xray), ct);
        await WaitForPortAsync("127.0.0.1", XrayConfigBuilder.LocalSocksPort, TimeSpan.FromSeconds(12), ct);

        var tunConfig = Path.Combine(_stateDir, "sing-box-v2rayn-tun.json");
        await File.WriteAllTextAsync(tunConfig, V2RayNTunConfigBuilder.Build(_settings, XrayConfigBuilder.LocalSocksPort), ct);
        await _singBox.StartAsync(singBox, ["run", "-c", tunConfig], Path.GetDirectoryName(singBox), ct);
        await Task.Delay(1200, ct);
        if (!IsRunning) throw new InvalidOperationException("هسته TUN ویندوز پایدار نماند.");
    }

    public void Stop()
    {
        // TUN owner first so Windows restores its routes before Xray disappears.
        _singBox.Stop();
        _xray.Stop();
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
                await tcp.ConnectAsync(host, port, linked.Token);
                if (tcp.Connected) return;
            }
            catch (Exception ex) { last = ex; }
            await Task.Delay(350, ct);
        }
        throw new InvalidOperationException($"Xray local SOCKS آماده نشد: {last?.Message ?? "port unavailable"}");
    }

    public void Dispose()
    {
        Stop();
        _singBox.Dispose();
        _xray.Dispose();
    }
}
