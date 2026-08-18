using System.Diagnostics;
using System.Net.Sockets;
using BlueVPN.Windows.Models;

namespace BlueVPN.Windows.Services;

public static class EndpointSelector
{
    public static async Task<IReadOnlyList<ProxyEndpoint>> RankAsync(
        IReadOnlyList<ProxyEndpoint> endpoints,
        CancellationToken ct = default)
    {
        var limited = endpoints.Take(40).ToList();
        using var gate = new SemaphoreSlim(8);
        var tasks = limited.Select(async endpoint =>
        {
            await gate.WaitAsync(ct);
            try
            {
                endpoint.ProbeLatencyMs = await ProbeAsync(endpoint.Host, endpoint.Port, ct);
            }
            finally
            {
                gate.Release();
            }
            return endpoint;
        });

        var ranked = await Task.WhenAll(tasks);
        return ranked
            .OrderBy(x => x.ProbeLatencyMs)
            .ThenBy(x => x.Protocol)
            .ToList();
    }

    private static async Task<int> ProbeAsync(string host, int port, CancellationToken ct)
    {
        var sw = Stopwatch.StartNew();
        try
        {
            using var client = new TcpClient();
            using var timeout = CancellationTokenSource.CreateLinkedTokenSource(ct);
            timeout.CancelAfter(TimeSpan.FromSeconds(3));
            await client.ConnectAsync(host, port, timeout.Token);
            sw.Stop();
            return (int)Math.Clamp(sw.ElapsedMilliseconds, 1, 60_000);
        }
        catch
        {
            return int.MaxValue;
        }
    }
}
