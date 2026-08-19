using System.IO;
using System.IO.Compression;
using System.Net.Http;
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

    public async Task DownloadVerifiedAsync(string url, string destination, string digest, CancellationToken ct)
    {
        Directory.CreateDirectory(Path.GetDirectoryName(destination)!);
        var temp = destination + ".part";
        try
        {
            using var response = await _http.GetAsync(url, HttpCompletionOption.ResponseHeadersRead, ct).ConfigureAwait(false);
            response.EnsureSuccessStatusCode();
            await using (var src = await response.Content.ReadAsStreamAsync(ct).ConfigureAwait(false))
            await using (var dst = new FileStream(temp, FileMode.Create, FileAccess.Write, FileShare.None))
                await src.CopyToAsync(dst, ct).ConfigureAwait(false);

            if (string.IsNullOrWhiteSpace(digest) || !digest.StartsWith("sha256:", StringComparison.OrdinalIgnoreCase))
                throw new InvalidDataException("GitHub برای فایل بروزرسانی SHA256 معتبر ارائه نکرد؛ بروزرسانی برای امنیت متوقف شد.");

            var expected = digest[7..].Trim();
            if (expected.Length != 64 || expected.Any(ch => !Uri.IsHexDigit(ch)))
                throw new InvalidDataException("SHA256 اعلام‌شده برای فایل بروزرسانی معتبر نیست.");

            var actual = await Sha256Async(temp, ct).ConfigureAwait(false);
            if (!actual.Equals(expected, StringComparison.OrdinalIgnoreCase))
                throw new InvalidDataException("SHA256 فایل بروزرسانی با GitHub تطابق ندارد.");

            File.Move(temp, destination, true);
        }
        finally
        {
            try { if (File.Exists(temp)) File.Delete(temp); } catch { }
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
