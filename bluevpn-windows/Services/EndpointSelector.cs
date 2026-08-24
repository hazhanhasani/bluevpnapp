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
        var limited = endpoints.Take(48).ToList();
        using var gate = new SemaphoreSlim(24);
        var tasks = limited.Select(async endpoint =>
        {
            await gate.WaitAsync(ct).ConfigureAwait(false);
            try
            {
                var quality = await ProbeQualityAsync(endpoint.Host, endpoint.Port, ct).ConfigureAwait(false);
                endpoint.ProbeLatencyMs = quality.LatencyMs;
                endpoint.ProbeJitterMs = quality.JitterMs;
                endpoint.ProbeSuccessCount = quality.Successes;
                endpoint.ProbeSampleCount = quality.Samples;
            }
            finally
            {
                gate.Release();
            }
            return endpoint;
        });

        var ranked = await Task.WhenAll(tasks).ConfigureAwait(false);
        return ranked
            .OrderBy(x => x.ProbeSuccessCount == 0)
            .ThenBy(QualityCost)
            .ThenBy(x => x.Protocol)
            .ToList();
    }

    private static int QualityCost(ProxyEndpoint endpoint)
    {
        if (endpoint.ProbeLatencyMs == int.MaxValue) return int.MaxValue;
        var jitter = endpoint.ProbeJitterMs == int.MaxValue ? 300 : Math.Clamp(endpoint.ProbeJitterMs, 0, 2_000);
        var missed = Math.Max(0, endpoint.ProbeSampleCount - endpoint.ProbeSuccessCount);
        return (int)Math.Clamp((long)endpoint.ProbeLatencyMs + jitter * 2L + missed * 350L, 1L, int.MaxValue - 1L);
    }

    private static async Task<ProbeQuality> ProbeQualityAsync(string host, int port, CancellationToken ct)
    {
        // Two bounded TCP samples provide a cheap jitter/reliability signal.
        // The second sample runs only after a real first success, keeping dead
        // endpoint rejection as fast as the previous single-probe implementation.
        var first = await ProbeOnceAsync(host, port, 900, ct).ConfigureAwait(false);
        if (first == int.MaxValue) return new ProbeQuality(int.MaxValue, int.MaxValue, 0, 1);

        var second = await ProbeOnceAsync(host, port, 650, ct).ConfigureAwait(false);
        if (second == int.MaxValue) return new ProbeQuality(first, 300, 1, 2);

        var jitter = Math.Abs(first - second);
        if (jitter >= 90)
        {
            // Close/jittery routes receive one extra bounded sample so a single
            // scheduler/network spike does not push a healthy endpoint to the top.
            var third = await ProbeOnceAsync(host, port, 650, ct).ConfigureAwait(false);
            if (third == int.MaxValue)
                return new ProbeQuality((first + second) / 2, Math.Max(jitter, 300), 2, 3);
            var samples = new[] { first, second, third };
            var latency3 = (int)samples.Average();
            var jitter3 = samples.Max() - samples.Min();
            return new ProbeQuality(Math.Max(1, latency3), jitter3, 3, 3);
        }

        var latency = (first + second) / 2;
        return new ProbeQuality(Math.Max(1, latency), jitter, 2, 2);
    }

    private static async Task<int> ProbeOnceAsync(string host, int port, int timeoutMs, CancellationToken ct)
    {
        var sw = Stopwatch.StartNew();
        try
        {
            using var client = new TcpClient();
            using var timeout = CancellationTokenSource.CreateLinkedTokenSource(ct);
            timeout.CancelAfter(TimeSpan.FromMilliseconds(timeoutMs));
            await client.ConnectAsync(host, port, timeout.Token).ConfigureAwait(false);
            sw.Stop();
            return (int)Math.Clamp(sw.ElapsedMilliseconds, 1, 60_000);
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested)
        {
            throw;
        }
        catch
        {
            return int.MaxValue;
        }
    }

    private readonly record struct ProbeQuality(int LatencyMs, int JitterMs, int Successes, int Samples);
}
