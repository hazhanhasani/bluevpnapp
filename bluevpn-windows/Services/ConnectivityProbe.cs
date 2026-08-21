using System.Net;
using System.Net.Http;
using BlueVPN.Windows.Models;

namespace BlueVPN.Windows.Services;

public static class ConnectivityProbe
{
    private static readonly string[] FallbackTraceUrls =
    [
        "https://www.cloudflare.com/cdn-cgi/trace",
        "https://1.1.1.1/cdn-cgi/trace"
    ];

    public static Task<ConnectivitySnapshot> SnapshotAsync(string url, CancellationToken ct = default) =>
        SnapshotCoreAsync(url, proxy: null, TimeSpan.FromSeconds(8), ct);

    public static async Task<ConnectivitySnapshot> SnapshotViaSocksAsync(string url, string host, int port, TimeSpan timeout, CancellationToken ct = default)
    {
        var proxy = new WebProxy(new Uri($"socks5://{host}:{port}"));
        var urls = new[] { url }.Concat(FallbackTraceUrls)
            .Where(x => !string.IsNullOrWhiteSpace(x))
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .ToArray();
        ConnectivitySnapshot last = new(false, "", "", "", DateTimeOffset.UtcNow, "tunnel probe unavailable");
        var perAttempt = TimeSpan.FromSeconds(Math.Clamp(timeout.TotalSeconds, 4, 8));
        for (var round = 0; round < 2; round++)
        {
            foreach (var candidate in urls)
            {
                ct.ThrowIfCancellationRequested();
                last = await SnapshotCoreAsync(candidate, proxy, perAttempt, ct).ConfigureAwait(false);
                if (last.Reachable && !string.IsNullOrWhiteSpace(last.PublicIp)) return last;
            }
            await Task.Delay(300, ct).ConfigureAwait(false);
        }
        return last with { Error = FriendlyProbeError(last.Error) };
    }

    /// <summary>
    /// A valid pre-VPN public IP is mandatory. Accepting an empty baseline made
    /// any post-connect IP look "changed" and could produce false CONNECTED.
    /// </summary>
    public static async Task<ConnectivitySnapshot> CaptureBaselineAsync(string preferredUrl, CancellationToken ct = default)
    {
        var urls = new[] { preferredUrl }.Concat(FallbackTraceUrls)
            .Where(x => !string.IsNullOrWhiteSpace(x))
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .ToArray();

        ConnectivitySnapshot last = new(false, "", "", "", DateTimeOffset.UtcNow, "baseline unavailable");
        for (var round = 0; round < 2; round++)
        {
            foreach (var url in urls)
            {
                ct.ThrowIfCancellationRequested();
                last = await SnapshotCoreAsync(url, null, TimeSpan.FromSeconds(6), ct).ConfigureAwait(false);
                if (last.Reachable && !string.IsNullOrWhiteSpace(last.PublicIp)) return last;
            }
            await Task.Delay(350, ct).ConfigureAwait(false);
        }
        return last;
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
            await Task.Delay(750, ct).ConfigureAwait(false);
        }
        return last;
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
        http.DefaultRequestHeaders.UserAgent.ParseAdd("BlueVPN-Windows-Probe/5.0.4");
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

            fields.TryGetValue("ip", out var ip);
            fields.TryGetValue("loc", out var loc);
            fields.TryGetValue("warp", out var warp);
            return new(!string.IsNullOrWhiteSpace(ip), ip ?? "", loc ?? "", warp ?? "", DateTimeOffset.UtcNow);
        }
        catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException)
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
        return value.Length > 96 ? value[..96] + "…" : value;
    }
}
