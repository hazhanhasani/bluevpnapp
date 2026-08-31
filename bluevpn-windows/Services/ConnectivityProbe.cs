using System.Diagnostics;
using System.Net;
using System.Net.Http;
using System.Net.Sockets;
using BlueVPN.Windows.Models;

namespace BlueVPN.Windows.Services;

public static class ConnectivityProbe
{
    private static readonly string[] FallbackTraceUrls =
    [
        "https://www.cloudflare.com/cdn-cgi/trace",
        "https://1.1.1.1/cdn-cgi/trace"
    ];

    private static readonly string[] PublicIpUrls =
    [
        "https://api.ipify.org",
        "https://icanhazip.com"
    ];

    public static Task<ConnectivitySnapshot> SnapshotAsync(string url, CancellationToken ct = default) =>
        SnapshotFirstAsync(BuildUrls(url, includePlainIp: true), proxy: null, TimeSpan.FromSeconds(4), ct);

    public static Task<ConnectivitySnapshot> SnapshotTraceAsync(string url, CancellationToken ct = default) =>
        SnapshotFirstAsync(BuildTraceUrls(url), proxy: null, TimeSpan.FromSeconds(4), ct);

    public static Task<ConnectivitySnapshot> SnapshotViaSocksAsync(string url, string host, int port, TimeSpan timeout, CancellationToken ct = default)
    {
        var proxy = new WebProxy(new Uri($"socks5://{host}:{port}"));
        // WARP validation must be decided by a real Cloudflare trace response.
        // Racing a plain-IP endpoint here can return a valid IP with no warp=
        // field first and falsely reject an otherwise healthy WARP data plane.
        return SnapshotFirstAsync(BuildTraceUrls(url), proxy,
            TimeSpan.FromSeconds(Math.Clamp(timeout.TotalSeconds, 3, 5)), ct);
    }

    public static Task<ConnectivitySnapshot> SnapshotViaHttpProxyAsync(string url, string host, int port, TimeSpan timeout, CancellationToken ct = default)
    {
        var proxy = new WebProxy(new Uri($"http://{host}:{port}"));
        return SnapshotFirstAsync(BuildUrls(url, includePlainIp: true), proxy,
            TimeSpan.FromSeconds(Math.Clamp(timeout.TotalSeconds, 3, 5)), ct);
    }

    /// <summary>
    /// Captures a reliable pre-VPN IP. Multiple independent endpoints are raced,
    /// so a slow/filtered Cloudflare trace no longer blocks the connect button.
    /// </summary>
    public static Task<ConnectivitySnapshot> CaptureBaselineAsync(string preferredUrl, CancellationToken ct = default) =>
        SnapshotFirstAsync(BuildUrls(preferredUrl, includePlainIp: true), proxy: null, TimeSpan.FromSeconds(3), ct);

    public static async Task<TunnelProbeMeasurement> MeasureDirectAsync(string url, CancellationToken ct = default)
    {
        var sw = Stopwatch.StartNew();
        var snapshot = await SnapshotFirstAsync(BuildUrls(url, includePlainIp: false), proxy: null, TimeSpan.FromSeconds(3), ct).ConfigureAwait(false);
        sw.Stop();
        return new(snapshot.Reachable, (int)Math.Clamp(sw.ElapsedMilliseconds, 1, 10_000), snapshot, "cloudflare-trace");
    }

    public static async Task<TunnelProbeMeasurement> MeasureViaHttpProxyAsync(string url, string host, int port, CancellationToken ct = default)
    {
        var sw = Stopwatch.StartNew();
        var proxy = new WebProxy(new Uri($"http://{host}:{port}"));
        var snapshot = await SnapshotFirstAsync(BuildUrls(url, includePlainIp: false), proxy, TimeSpan.FromSeconds(3), ct).ConfigureAwait(false);
        sw.Stop();
        return new(snapshot.Reachable, (int)Math.Clamp(sw.ElapsedMilliseconds, 1, 10_000), snapshot, "xray-http-probe");
    }

    public static async Task<ConnectivitySnapshot> WaitForSnapshotAsync(string url, TimeSpan timeout, CancellationToken ct = default)
    {
        var stop = DateTimeOffset.UtcNow + timeout;
        ConnectivitySnapshot last = new(false, "", "", "", DateTimeOffset.UtcNow, "timeout");
        while (DateTimeOffset.UtcNow < stop)
        {
            ct.ThrowIfCancellationRequested();
            last = await SnapshotAsync(url, ct).ConfigureAwait(false);
            if (last.Reachable) return last;
            await Task.Delay(350, ct).ConfigureAwait(false);
        }
        return last;
    }

    private static string[] BuildUrls(string preferred, bool includePlainIp)
    {
        IEnumerable<string> urls = new[] { preferred }.Concat(FallbackTraceUrls);
        if (includePlainIp) urls = urls.Concat(PublicIpUrls);
        return urls.Where(x => !string.IsNullOrWhiteSpace(x)).Distinct(StringComparer.OrdinalIgnoreCase).ToArray();
    }

    private static string[] BuildTraceUrls(string preferred)
    {
        var urls = new List<string>();
        if (!string.IsNullOrWhiteSpace(preferred) &&
            preferred.Contains("/cdn-cgi/trace", StringComparison.OrdinalIgnoreCase))
            urls.Add(preferred);
        urls.AddRange(FallbackTraceUrls);
        return urls.Distinct(StringComparer.OrdinalIgnoreCase).ToArray();
    }

    private static async Task<ConnectivitySnapshot> SnapshotFirstAsync(string[] urls, IWebProxy? proxy, TimeSpan timeout, CancellationToken ct)
    {
        if (urls.Length == 0) return new(false, "", "", "", DateTimeOffset.UtcNow, "probe unavailable");
        using var linked = CancellationTokenSource.CreateLinkedTokenSource(ct);
        var tasks = urls.Select(url => SnapshotCoreAsync(url, proxy, timeout, linked.Token)).ToList();
        ConnectivitySnapshot last = new(false, "", "", "", DateTimeOffset.UtcNow, "probe unavailable");
        try
        {
            while (tasks.Count > 0)
            {
                var done = await Task.WhenAny(tasks).ConfigureAwait(false);
                tasks.Remove(done);
                try { last = await done.ConfigureAwait(false); }
                catch (OperationCanceledException) when (!ct.IsCancellationRequested) { continue; }
                if (last.Reachable && !string.IsNullOrWhiteSpace(last.PublicIp))
                {
                    linked.Cancel();
                    return last;
                }
            }
        }
        finally
        {
            linked.Cancel();
        }
        return last with { Error = FriendlyProbeError(last.Error) };
    }

    private static async Task<ConnectivitySnapshot> SnapshotCoreAsync(string url, IWebProxy? proxy, TimeSpan timeout, CancellationToken ct)
    {
        using var handler = new HttpClientHandler
        {
            AutomaticDecompression = DecompressionMethods.All,
            UseProxy = proxy is not null,
            Proxy = proxy,
            AllowAutoRedirect = false
        };
        using var http = new HttpClient(handler) { Timeout = timeout };
        http.DefaultRequestHeaders.UserAgent.ParseAdd("BlueVPN-Windows-Probe/6.1.6");
        try
        {
            using var response = await http.GetAsync(url, HttpCompletionOption.ResponseContentRead, ct).ConfigureAwait(false);
            var text = await response.Content.ReadAsStringAsync(ct).ConfigureAwait(false);
            if (!response.IsSuccessStatusCode)
                return new(false, "", "", "", DateTimeOffset.UtcNow, $"HTTP {(int)response.StatusCode}");

            var fields = text.Split('\n', StringSplitOptions.RemoveEmptyEntries)
                .Select(line => line.Split('=', 2))
                .Where(parts => parts.Length == 2)
                .ToDictionary(parts => parts[0].Trim(), parts => parts[1].Trim(), StringComparer.OrdinalIgnoreCase);

            if (fields.Count > 0)
            {
                fields.TryGetValue("ip", out var ip);
                fields.TryGetValue("loc", out var loc);
                fields.TryGetValue("warp", out var warp);
                if (IPAddress.TryParse(ip?.Trim(), out var parsed))
                    return new(true, parsed.ToString(), loc ?? "", warp ?? "", DateTimeOffset.UtcNow);
            }

            var plain = text.Trim();
            if (IPAddress.TryParse(plain, out var plainIp))
                return new(true, plainIp.ToString(), "", "", DateTimeOffset.UtcNow);

            return new(false, "", "", "", DateTimeOffset.UtcNow, "پاسخ IP معتبر نبود");
        }
        catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException or SocketException)
        {
            return new(false, "", "", "", DateTimeOffset.UtcNow, FriendlyProbeError(ex.Message));
        }
    }

    private static string FriendlyProbeError(string value)
    {
        if (string.IsNullOrWhiteSpace(value)) return "پاسخی از مسیر دریافت نشد";
        if (value.Contains("timeout", StringComparison.OrdinalIgnoreCase) || value.Contains("timed out", StringComparison.OrdinalIgnoreCase))
            return "پاسخ مسیر بیش از حد طول کشید";
        if (value.Contains("refused", StringComparison.OrdinalIgnoreCase)) return "هسته اتصال پاسخ نداد";
        if (value.Contains("name", StringComparison.OrdinalIgnoreCase) && value.Contains("resolve", StringComparison.OrdinalIgnoreCase))
            return "DNS مسیر پاسخ نداد";
        return value.Length > 96 ? value[..96] + "…" : value;
    }
}
