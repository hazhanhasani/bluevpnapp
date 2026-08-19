using System.Net;
using System.Net.Http;
using BlueVPN.Windows.Models;

namespace BlueVPN.Windows.Services;

public static class ConnectivityProbe
{
    public static async Task<ConnectivitySnapshot> SnapshotAsync(string url, CancellationToken ct = default)
    {
        using var handler = new HttpClientHandler
        {
            AutomaticDecompression = DecompressionMethods.All,
            UseProxy = false
        };
        using var http = new HttpClient(handler) { Timeout = TimeSpan.FromSeconds(10) };
        http.DefaultRequestHeaders.UserAgent.ParseAdd("BlueVPN-Windows-Probe/4.17.6");
        try
        {
            using var response = await http.GetAsync(url, HttpCompletionOption.ResponseContentRead, ct);
            var text = await response.Content.ReadAsStringAsync(ct);
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
            return new(false, "", "", "", DateTimeOffset.UtcNow, ex.Message);
        }
    }

    public static async Task<ConnectivitySnapshot> WaitForSnapshotAsync(string url, TimeSpan timeout, CancellationToken ct = default)
    {
        var stop = DateTimeOffset.UtcNow + timeout;
        ConnectivitySnapshot last = new(false, "", "", "", DateTimeOffset.UtcNow, "timeout");
        while (DateTimeOffset.UtcNow < stop)
        {
            ct.ThrowIfCancellationRequested();
            last = await SnapshotAsync(url, ct);
            if (last.Reachable) return last;
            await Task.Delay(750, ct);
        }
        return last;
    }
}
