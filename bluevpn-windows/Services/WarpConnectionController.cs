using System.IO;
using System.Net.Sockets;
using System.Runtime.InteropServices;
using System.Security.Principal;
using BlueVPN.Windows.Models;

namespace BlueVPN.Windows.Services;

public sealed class WarpConnectionController : IDisposable
{
    private readonly AppSettings _settings;
    private readonly RuntimeLocator _runtime;
    private readonly ManagedCoreProcess _aether = new("aether");
    private readonly ManagedCoreProcess _singBox = new("sing-box-warp");
    private readonly string _stateDir;
    private readonly string _lastGoodTransportPath;

    public WarpConnectionController(AppSettings settings, RuntimeLocator runtime)
    {
        _settings = settings;
        _runtime = runtime;
        _stateDir = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "BlueVPN", "warp");
        Directory.CreateDirectory(_stateDir);
        _lastGoodTransportPath = Path.Combine(_stateDir, "last-good-transport.txt");
    }

    public bool IsSupported => RuntimeInformation.ProcessArchitecture != System.Runtime.InteropServices.Architecture.Arm64 && File.Exists(_runtime.ResolveAether());
    public bool IsRunning => _aether.IsRunning && _singBox.IsRunning;

    public async Task<ConnectivitySnapshot> StartAsync(WarpRuntimePolicy policy, IProgress<string>? progress, CancellationToken ct)
    {
        Stop();
        EnsureElevatedForTun();
        if (!IsSupported) throw new PlatformNotSupportedException("WARP فعلاً برای این معماری در دسترس نیست.");
        if (!policy.Enabled) throw new InvalidOperationException("WARP از پنل BlueVPN غیرفعال است.");

        using var total = CancellationTokenSource.CreateLinkedTokenSource(ct);
        total.CancelAfter(TimeSpan.FromSeconds(Math.Clamp(policy.TotalTimeoutSeconds, 30, 90)));
        var token = total.Token;

        var aether = _runtime.ResolveAether();
        var socksPort = Math.Clamp(_settings.Warp.SocksPort, 1024, 65535);
        var startTimeout = Math.Clamp(policy.StartTimeoutSeconds, 3, 40);
        var traceTimeout = TimeSpan.FromSeconds(Math.Clamp(policy.EndpointProbeSeconds, 3, 8));
        var masqueAllowed = policy.AllowedTransports.Count == 0 || policy.AllowedTransports.Any(x =>
            x.Equals("h3", StringComparison.OrdinalIgnoreCase) || x.Equals("h2", StringComparison.OrdinalIgnoreCase) || x.Equals("h2_fragment", StringComparison.OrdinalIgnoreCase));
        var wireGuardAllowed = policy.WireguardEnabled && (policy.AllowedTransports.Count == 0 || policy.AllowedTransports.Any(x => x.Equals("wireguard", StringComparison.OrdinalIgnoreCase)));

        ConnectivitySnapshot trace = new(false, "", "", "", DateTimeOffset.UtcNow, "WARP unavailable");
        Exception? transportError = null;
        foreach (var transport in BuildTransportOrder(policy, masqueAllowed, wireGuardAllowed, ReadLastGoodTransport()))
        {
            token.ThrowIfCancellationRequested();
            _aether.Stop();
            progress?.Report(transport switch
            {
                "masque-h3" => "WARP • اتصال سریع MASQUE/HTTP3…",
                "masque-h2" => "WARP • مسیر MASQUE/HTTP2 برای شبکه محدود…",
                "masque-h2-fragment" => "WARP • مسیر MASQUE/HTTP2 مقاوم…",
                _ => "WARP • مسیر WireGuard جایگزین…"
            });
            try
            {
                await _aether.StartAsync(aether, BuildAetherArgs(policy, socksPort, transport), _stateDir, token).ConfigureAwait(false);
                await WaitForPortAsync("127.0.0.1", socksPort, TimeSpan.FromSeconds(startTimeout), token).ConfigureAwait(false);
                progress?.Report("WARP • تأیید خروجی Cloudflare…");
                trace = await ConnectivityProbe.SnapshotViaSocksAsync(_settings.ProbeUrl, "127.0.0.1", socksPort, traceTimeout, token).ConfigureAwait(false);
                // A listening SOCKS port is not a working WARP data plane. Never
                // continue to TUN (or report connected) without real egress.
                if (!trace.Reachable || string.IsNullOrWhiteSpace(trace.PublicIp))
                    throw new InvalidOperationException($"WARP اینترنت معتبر نداد: {trace.Error}");
                if (!(trace.Warp.Equals("on", StringComparison.OrdinalIgnoreCase) || trace.Warp.Equals("plus", StringComparison.OrdinalIgnoreCase)))
                    throw new InvalidOperationException("Cloudflare مسیر WARP را تأیید نکرد.");
                if (trace.Reachable && policy.BlockedExitCountries.Any(x => x.Equals(trace.Country, StringComparison.OrdinalIgnoreCase)))
                    throw new InvalidOperationException($"خروجی WARP در کشور مسدودشده {trace.Country} قرار گرفت.");
                transportError = null;
                WriteLastGoodTransport(transport);
                break;
            }
            catch (OperationCanceledException) { throw; }
            catch (Exception ex)
            {
                transportError = ex;
                _aether.Stop();
            }
        }
        if (transportError is not null || !_aether.IsRunning)
            throw new InvalidOperationException($"WARP با MASQUE/WireGuard آماده نشد: {transportError?.Message ?? trace.Error}", transportError);

        progress?.Report("فعال‌سازی VPN سراسری…");
        var configPath = Path.Combine(_stateDir, "sing-box-warp.json");
        // Iranian access networks frequently advertise unusable IPv6. Keep the
        // device tunnel IPv4-only unless the panel explicitly opts into dual.
        var enableIpv6 = policy.IpMode.Equals("dual", StringComparison.OrdinalIgnoreCase);
        await File.WriteAllTextAsync(configPath, SingBoxWarpConfigBuilder.Build(_settings, socksPort, enableIpv6), token).ConfigureAwait(false);
        var sing = _runtime.ResolveSingBox();
        var work = Path.GetDirectoryName(sing) ?? _runtime.ActiveRuntimeRoot();
        await _singBox.StartAsync(sing, ["run", "-c", configPath], work, token).ConfigureAwait(false);
        await Task.Delay(900, token).ConfigureAwait(false);
        if (!IsRunning) throw new InvalidOperationException("هسته WARP/TUN پایدار نماند.");
        return trace;
    }

    public void Stop()
    {
        _singBox.Stop();
        _aether.Stop();
    }

    private static string[] BuildAetherArgs(WarpRuntimePolicy policy, int socksPort, string transport)
    {
        var args = new List<string> { "--bind", $"127.0.0.1:{socksPort}" };

        // Aether's known transport switch: MASQUE is optional; without it the
        // runtime can use its legacy/WireGuard path. Never invent unsupported CLI flags.
        if (transport.StartsWith("masque", StringComparison.Ordinal)) args.Add("--masque");
        if (transport is "masque-h2" or "masque-h2-fragment") args.Add("--h2");
        if (transport == "masque-h2-fragment")
        {
            args.Add("--fragment");
            args.Add("--fragment-size");
            args.Add(policy.FragmentSize);
            args.Add("--fragment-delay");
            args.Add(policy.FragmentDelay);
        }
        if (transport == "wireguard") args.Add("--wg");

        if (!policy.IpMode.Equals("dual", StringComparison.OrdinalIgnoreCase)) args.Add("-4");

        args.Add("--scan");
        args.Add(NormalizeScanMode(policy.ScanMode));

        if (!policy.NoizeProfile.Equals("off", StringComparison.OrdinalIgnoreCase))
        {
            args.Add("--noize");
            args.Add(NormalizeNoize(policy.NoizeProfile));
        }
        if (policy.QuickReconnect) args.Add("--quick-reconnect");
        return args.ToArray();
    }


    private static IReadOnlyList<string> BuildTransportOrder(WarpRuntimePolicy policy, bool masqueAllowed, bool wireGuardAllowed, string? lastGood)
    {
        var order = new List<string>();
        if (masqueAllowed && policy.AllowedTransports.Contains("h3", StringComparer.OrdinalIgnoreCase)) order.Add("masque-h3");
        if (masqueAllowed && policy.H2Enabled && policy.AllowedTransports.Contains("h2", StringComparer.OrdinalIgnoreCase)) order.Add("masque-h2");
        if (masqueAllowed && policy.H2Enabled && policy.FragmentEnabled && policy.AllowedTransports.Contains("h2_fragment", StringComparer.OrdinalIgnoreCase)) order.Add("masque-h2-fragment");
        if (wireGuardAllowed) order.Add("wireguard");
        if (order.Count == 0) order.Add("masque-h3");
        if (policy.QuickReconnect && !string.IsNullOrWhiteSpace(lastGood) && order.Remove(lastGood)) order.Insert(0, lastGood);
        return order;
    }

    private string? ReadLastGoodTransport()
    {
        try { return File.Exists(_lastGoodTransportPath) ? File.ReadAllText(_lastGoodTransportPath).Trim() : null; }
        catch { return null; }
    }

    private void WriteLastGoodTransport(string transport)
    {
        try { File.WriteAllText(_lastGoodTransportPath, transport); } catch { }
    }

    private static string NormalizeScanMode(string value) => value.ToLowerInvariant() switch
    {
        "balanced" => "balanced",
        "thorough" => "thorough",
        "stealth" => "stealth",
        "ironclad" => "ironclad",
        _ => "turbo"
    };

    private static string NormalizeNoize(string value) => value.ToLowerInvariant() switch
    {
        "light" => "light",
        "balanced" => "balanced",
        "aggressive" => "aggressive",
        "gfw" => "gfw",
        _ => "firewall"
    };

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
            await Task.Delay(350, ct).ConfigureAwait(false);
        }
        throw new InvalidOperationException($"WARP آماده نشد: {last?.Message ?? $"SOCKS {port} unavailable"}");
    }


    private static void EnsureElevatedForTun()
    {
        if (!OperatingSystem.IsWindows()) return;
        try
        {
            using var identity = WindowsIdentity.GetCurrent();
            var principal = new WindowsPrincipal(identity);
            if (!principal.IsInRole(WindowsBuiltInRole.Administrator))
                throw new InvalidOperationException("برای فعال‌سازی WARP سراسری، BlueVPN باید با دسترسی Administrator اجرا شود.");
        }
        catch (InvalidOperationException) { throw; }
        catch { }
    }

    public void Dispose() => Stop();
}
