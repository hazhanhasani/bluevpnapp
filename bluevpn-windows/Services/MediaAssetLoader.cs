using System.Collections.Concurrent;
using System.IO;
using System.Net;
using System.Net.Http;
using System.Security.Cryptography;
using System.Text;
using System.Windows.Media.Imaging;

namespace BlueVPN.Windows.Services;

/// <summary>
/// Non-blocking, transport-resilient media loader for BlueVPN campaign assets.
/// It mirrors the control-plane strategy: direct HTTPS first, Windows system
/// proxy second, then a persistent last-known-good disk copy. Certificate
/// validation is never disabled.
/// </summary>
public static class MediaAssetLoader
{
    private static readonly HttpClient DirectHttp = CreateHttp(useSystemProxy: false);
    private static readonly HttpClient SystemProxyHttp = CreateHttp(useSystemProxy: true);
    private static readonly ConcurrentDictionary<string, Lazy<Task<BitmapSource?>>> Cache = new(StringComparer.OrdinalIgnoreCase);
    private static readonly string DiskCacheRoot = Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "BlueVPN",
        "cache",
        "media");

    public static Task<BitmapSource?> LoadImageAsync(string url, CancellationToken ct = default)
    {
        if (!Uri.TryCreate(url, UriKind.Absolute, out var uri) ||
            (uri.Scheme != Uri.UriSchemeHttps && uri.Scheme != Uri.UriSchemeHttp))
            return Task.FromResult<BitmapSource?>(null);

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

    private static HttpClient CreateHttp(bool useSystemProxy)
    {
        var handler = new HttpClientHandler
        {
            AutomaticDecompression = DecompressionMethods.All,
            AllowAutoRedirect = true,
            UseProxy = useSystemProxy,
            Proxy = useSystemProxy ? WebRequest.DefaultWebProxy : null
        };
        var client = new HttpClient(handler) { Timeout = TimeSpan.FromSeconds(12) };
        client.DefaultRequestHeaders.UserAgent.ParseAdd("BlueVPN-Windows-Media/6.0.5");
        client.DefaultRequestHeaders.Add("X-BlueVPN-Platform", "windows");
        return client;
    }

    private static async Task<BitmapSource?> LoadCoreAsync(string url, CancellationToken ct)
    {
        var cachePath = CachePath(url);
        var disk = await TryReadDiskAsync(cachePath, ct).ConfigureAwait(false);
        if (disk is not null) return disk;

        var bytes = await TryDownloadAsync(DirectHttp, url, ct).ConfigureAwait(false)
            ?? await TryDownloadAsync(SystemProxyHttp, url, ct).ConfigureAwait(false);
        if (bytes is null) return null;

        TryWriteDisk(cachePath, bytes);
        return await DecodeAsync(bytes, ct).ConfigureAwait(false);
    }

    private static async Task<byte[]?> TryDownloadAsync(HttpClient http, string url, CancellationToken ct)
    {
        try
        {
            using var response = await http.GetAsync(url, HttpCompletionOption.ResponseHeadersRead, ct).ConfigureAwait(false);
            if (!response.IsSuccessStatusCode) return null;
            var mediaType = response.Content.Headers.ContentType?.MediaType ?? "";
            if (mediaType.Length > 0 && !mediaType.StartsWith("image/", StringComparison.OrdinalIgnoreCase) &&
                !mediaType.Equals("application/octet-stream", StringComparison.OrdinalIgnoreCase)) return null;
            var bytes = await response.Content.ReadAsByteArrayAsync(ct).ConfigureAwait(false);
            return bytes.Length is > 0 and <= 12 * 1024 * 1024 ? bytes : null;
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested)
        {
            throw;
        }
        catch
        {
            return null;
        }
    }

    private static async Task<BitmapSource?> TryReadDiskAsync(string path, CancellationToken ct)
    {
        try
        {
            if (!File.Exists(path)) return null;
            var info = new FileInfo(path);
            if (info.Length <= 0 || info.Length > 12 * 1024 * 1024) return null;
            var bytes = await File.ReadAllBytesAsync(path, ct).ConfigureAwait(false);
            return await DecodeAsync(bytes, ct).ConfigureAwait(false);
        }
        catch
        {
            return null;
        }
    }

    private static void TryWriteDisk(string path, byte[] bytes)
    {
        try
        {
            Directory.CreateDirectory(DiskCacheRoot);
            var temp = path + ".tmp";
            File.WriteAllBytes(temp, bytes);
            File.Move(temp, path, true);
        }
        catch { }
    }

    private static string CachePath(string url)
    {
        var hash = Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(url))).ToLowerInvariant();
        return Path.Combine(DiskCacheRoot, hash + ".bin");
    }

    private static Task<BitmapSource?> DecodeAsync(byte[] bytes, CancellationToken ct) => Task.Run<BitmapSource?>(() =>
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
    }, ct);

    private static async Task<T> AwaitWithCancellationAsync<T>(Task<T> task, CancellationToken ct)
    {
        if (!ct.CanBeCanceled) return await task.ConfigureAwait(false);
        var cancel = new TaskCompletionSource<bool>(TaskCreationOptions.RunContinuationsAsynchronously);
        using var reg = ct.Register(static state => ((TaskCompletionSource<bool>)state!).TrySetResult(true), cancel);
        if (task != await Task.WhenAny(task, cancel.Task).ConfigureAwait(false)) throw new OperationCanceledException(ct);
        return await task.ConfigureAwait(false);
    }
}
