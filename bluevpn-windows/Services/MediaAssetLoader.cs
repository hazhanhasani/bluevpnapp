using System.Collections.Concurrent;
using System.IO;
using System.Net.Http;
using System.Windows.Media.Imaging;

namespace BlueVPN.Windows.Services;

/// <summary>
/// Non-blocking image loader for the home/banner UI. WPF's BitmapImage(Uri)
/// can perform network work on the dispatcher and cause visible stutter; this
/// class downloads bytes off-thread, decodes from memory and freezes the image
/// so it can safely be assigned on the UI thread.
/// </summary>
public static class MediaAssetLoader
{
    private static readonly HttpClient Http = new(new HttpClientHandler
    {
        AutomaticDecompression = System.Net.DecompressionMethods.All,
        AllowAutoRedirect = true
    })
    {
        Timeout = TimeSpan.FromSeconds(12)
    };

    private static readonly ConcurrentDictionary<string, Lazy<Task<BitmapSource?>>> Cache = new(StringComparer.OrdinalIgnoreCase);

    static MediaAssetLoader()
    {
        Http.DefaultRequestHeaders.UserAgent.ParseAdd("BlueVPN-Windows-Media/4.17.8");
    }

    public static Task<BitmapSource?> LoadImageAsync(string url, CancellationToken ct = default)
    {
        if (!Uri.TryCreate(url, UriKind.Absolute, out _)) return Task.FromResult<BitmapSource?>(null);
        var lazy = Cache.GetOrAdd(url, key => new Lazy<Task<BitmapSource?>>(() => LoadCoreAsync(key, CancellationToken.None)));
        return AwaitWithCancellationAsync(lazy.Value, ct);
    }

    public static void Preload(string url)
    {
        if (!Uri.TryCreate(url, UriKind.Absolute, out _)) return;
        _ = Cache.GetOrAdd(url, key => new Lazy<Task<BitmapSource?>>(() => LoadCoreAsync(key, CancellationToken.None))).Value;
    }

    public static void Trim(int maxEntries = 24)
    {
        if (Cache.Count <= maxEntries) return;
        foreach (var key in Cache.Keys.Take(Math.Max(0, Cache.Count - maxEntries))) Cache.TryRemove(key, out _);
    }

    private static async Task<BitmapSource?> LoadCoreAsync(string url, CancellationToken ct)
    {
        try
        {
            using var response = await Http.GetAsync(url, HttpCompletionOption.ResponseHeadersRead, ct).ConfigureAwait(false);
            if (!response.IsSuccessStatusCode) return null;
            var bytes = await response.Content.ReadAsByteArrayAsync(ct).ConfigureAwait(false);
            if (bytes.Length == 0 || bytes.Length > 12 * 1024 * 1024) return null;

            return await Task.Run<BitmapSource?>(() =>
            {
                try
                {
                    using var ms = new MemoryStream(bytes, writable: false);
                    var bmp = new BitmapImage();
                    bmp.BeginInit();
                    bmp.CacheOption = BitmapCacheOption.OnLoad;
                    bmp.CreateOptions = BitmapCreateOptions.IgnoreColorProfile;
                    bmp.StreamSource = ms;
                    bmp.EndInit();
                    bmp.Freeze();
                    return bmp;
                }
                catch { return null; }
            }, ct).ConfigureAwait(false);
        }
        catch { return null; }
    }

    private static async Task<T> AwaitWithCancellationAsync<T>(Task<T> task, CancellationToken ct)
    {
        if (!ct.CanBeCanceled) return await task.ConfigureAwait(false);
        var cancel = new TaskCompletionSource<bool>(TaskCreationOptions.RunContinuationsAsynchronously);
        using var reg = ct.Register(static state => ((TaskCompletionSource<bool>)state!).TrySetResult(true), cancel);
        if (task != await Task.WhenAny(task, cancel.Task).ConfigureAwait(false)) throw new OperationCanceledException(ct);
        return await task.ConfigureAwait(false);
    }
}
