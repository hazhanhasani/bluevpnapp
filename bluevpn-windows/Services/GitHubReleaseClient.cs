using System.IO;
using System.IO.Compression;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Runtime.InteropServices;
using System.Security.Cryptography;
using System.Text.Json;
using BlueVPN.Windows.Models;

namespace BlueVPN.Windows.Services;

public sealed class GitHubReleaseClient : IDisposable
{
    private readonly HttpClient _http;

    public GitHubReleaseClient(string userAgent)
    {
        _http = new HttpClient { Timeout = TimeSpan.FromSeconds(45) };
        _http.DefaultRequestHeaders.UserAgent.ParseAdd(userAgent);
        _http.DefaultRequestHeaders.Accept.ParseAdd("application/vnd.github+json");
    }

    public async Task<JsonDocument> GetReleasesAsync(string repository, int limit, CancellationToken ct)
    {
        var url = $"https://api.github.com/repos/{repository}/releases?per_page={Math.Clamp(limit, 1, 30)}";
        var bytes = await _http.GetByteArrayAsync(url, ct).ConfigureAwait(false);
        return JsonDocument.Parse(bytes);
    }

    public async Task DownloadVerifiedAsync(string url, string destination, string digest, CancellationToken ct, long expectedSize = 0, IProgress<double>? progress = null)
    {
        Directory.CreateDirectory(Path.GetDirectoryName(destination)!);
        var temp = destination + ".part";
        try
        {
            long existing = 0;
            try { if (File.Exists(temp)) existing = new FileInfo(temp).Length; } catch { existing = 0; }
            if (expectedSize > 0 && existing >= expectedSize)
            {
                try { File.Delete(temp); } catch { }
                existing = 0;
            }

            using var request = new HttpRequestMessage(HttpMethod.Get, url);
            if (existing > 0) request.Headers.Range = new RangeHeaderValue(existing, null);
            using var response = await _http.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, ct).ConfigureAwait(false);

            var append = existing > 0 && response.StatusCode == System.Net.HttpStatusCode.PartialContent;
            if (!append && existing > 0) existing = 0; // server ignored Range; safely restart
            response.EnsureSuccessStatusCode();

            var total = expectedSize > 0
                ? expectedSize
                : (response.Content.Headers.ContentLength.HasValue ? existing + response.Content.Headers.ContentLength.Value : 0);

            await using (var src = await response.Content.ReadAsStreamAsync(ct).ConfigureAwait(false))
            await using (var dst = new FileStream(temp, append ? FileMode.Append : FileMode.Create, FileAccess.Write, FileShare.None, 1024 * 1024, useAsync: true))
            {
                var buffer = new byte[1024 * 1024];
                long written = existing;
                while (true)
                {
                    var read = await src.ReadAsync(buffer.AsMemory(0, buffer.Length), ct).ConfigureAwait(false);
                    if (read <= 0) break;
                    await dst.WriteAsync(buffer.AsMemory(0, read), ct).ConfigureAwait(false);
                    written += read;
                    if (total > 0) progress?.Report(Math.Clamp(written / (double)total, 0d, 1d));
                }
                await dst.FlushAsync(ct).ConfigureAwait(false);
            }

            if (expectedSize > 0)
            {
                var actualSize = new FileInfo(temp).Length;
                if (actualSize != expectedSize)
                    throw new InvalidDataException($"حجم فایل بروزرسانی کامل نیست (expected={expectedSize}, received={actualSize}).");
            }

            if (string.IsNullOrWhiteSpace(digest) || !digest.StartsWith("sha256:", StringComparison.OrdinalIgnoreCase))
                throw new InvalidDataException("GitHub برای فایل بروزرسانی SHA256 معتبر ارائه نکرد؛ بروزرسانی برای امنیت متوقف شد.");

            var expected = digest[7..].Trim();
            if (expected.Length != 64 || expected.Any(ch => !Uri.IsHexDigit(ch)))
                throw new InvalidDataException("SHA256 اعلام‌شده برای فایل بروزرسانی معتبر نیست.");

            var actual = await Sha256Async(temp, ct).ConfigureAwait(false);
            if (!actual.Equals(expected, StringComparison.OrdinalIgnoreCase))
                throw new InvalidDataException("SHA256 فایل بروزرسانی با GitHub تطابق ندارد.");

            File.Move(temp, destination, true);
            progress?.Report(1d);
        }
        catch (OperationCanceledException)
        {
            // Keep a partial file for a user-requested retry/resume. Old partials are
            // cleaned by UpdateStorageManager on a new application version.
            throw;
        }
        catch (IOException)
        {
            // Disk errors must not leave a poisoned partial that retriggers the same
            // failure forever. The caller converts low-space failures to Persian UI.
            try { if (File.Exists(temp)) File.Delete(temp); } catch { }
            throw;
        }
        catch
        {
            // Network/server failures keep the partial so the next retry can use HTTP Range.
            throw;
        }
    }

    public static async Task<string> Sha256Async(string path, CancellationToken ct)
    {
        await using var stream = File.OpenRead(path);
        var hash = await SHA256.HashDataAsync(stream, ct).ConfigureAwait(false);
        return Convert.ToHexString(hash).ToLowerInvariant();
    }

    public static string ArchitectureAssetToken() => RuntimeInformation.ProcessArchitecture == System.Runtime.InteropServices.Architecture.Arm64 ? "win-arm64" : "win-x64";

    public void Dispose() => _http.Dispose();
}
