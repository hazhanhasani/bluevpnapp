namespace BlueVPN.Windows.Services;

public static class ConnectivityProbe
{
    public static async Task<bool> VerifyAsync(string url, CancellationToken ct = default)
    {
        using var handler = new HttpClientHandler
        {
            AutomaticDecompression = System.Net.DecompressionMethods.All
        };
        using var http = new HttpClient(handler) { Timeout = TimeSpan.FromSeconds(8) };
        http.DefaultRequestHeaders.UserAgent.ParseAdd("BlueVPN-Windows-Probe/1");
        try
        {
            var text = await http.GetStringAsync(url, ct);
            return text.Contains("ip=", StringComparison.OrdinalIgnoreCase)
                || text.Contains("warp=", StringComparison.OrdinalIgnoreCase);
        }
        catch
        {
            return false;
        }
    }
}
