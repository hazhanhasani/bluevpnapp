using System.IO;
using System.Net.Sockets;
using System.Runtime.InteropServices;

namespace BlueVPN.Windows.Services;

public sealed class WarpConnectionController : IDisposable
{
    private readonly AppSettings _settings;
    private readonly RuntimeLocator _runtime;
    private readonly ManagedCoreProcess _aether = new("aether");
    private readonly ManagedCoreProcess _singBox = new("sing-box-warp");
    private readonly string _stateDir;

    public WarpConnectionController(AppSettings settings, RuntimeLocator runtime)
    {
        _settings = settings;
        _runtime = runtime;
        _stateDir = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "BlueVPN", "warp");
        Directory.CreateDirectory(_stateDir);
    }

    public bool IsSupported => RuntimeInformation.ProcessArchitecture != System.Runtime.InteropServices.Architecture.Arm64 && File.Exists(_runtime.ResolveAether());
    public bool IsRunning => _aether.IsRunning && _singBox.IsRunning;

    public async Task StartAsync(IProgress<string>? progress, CancellationToken ct)
    {
        Stop();
        if (!IsSupported) throw new PlatformNotSupportedException("WARP فعلاً برای این معماری در دسترس نیست.");

        var aether = _runtime.ResolveAether();
        progress?.Report("آماده‌سازی WARP…");
        var socksPort = Math.Clamp(_settings.Warp.SocksPort, 1024, 65535);
        var args = new[]
        {
            "--bind", $"127.0.0.1:{socksPort}",
            "--masque", "-4",
            "--scan", "turbo",
            "--noize", "firewall",
            "--quick-reconnect"
        };
        await _aether.StartAsync(aether, args, _stateDir, ct);
        await WaitForPortAsync("127.0.0.1", socksPort, TimeSpan.FromSeconds(32), ct);

        progress?.Report("فعال‌سازی VPN سراسری…");
        var configPath = Path.Combine(_stateDir, "sing-box-warp.json");
        await File.WriteAllTextAsync(configPath, SingBoxWarpConfigBuilder.Build(_settings, socksPort), ct);
        var sing = _runtime.ResolveSingBox();
        var work = Path.GetDirectoryName(sing) ?? _runtime.ActiveRuntimeRoot();
        await _singBox.StartAsync(sing, ["run", "-c", configPath], work, ct);
        await Task.Delay(1200, ct);
    }

    public void Stop()
    {
        _singBox.Stop();
        _aether.Stop();
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
            await Task.Delay(500, ct);
        }
        throw new InvalidOperationException($"WARP آماده نشد: {last?.Message ?? "SOCKS 1819 unavailable"}");
    }

    public void Dispose() => Stop();
}
