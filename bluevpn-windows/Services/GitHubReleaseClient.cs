using System.IO;
using System.IO.Compression;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Runtime.InteropServices;
using System.Text;
using System.Security.Cryptography;
using System.Security.Cryptography.X509Certificates;
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

            HttpResponseMessage? response = null;
            var append = false;
            try
            {
                using var request = new HttpRequestMessage(HttpMethod.Get, url);
                if (existing > 0) request.Headers.Range = new RangeHeaderValue(existing, null);
                response = await _http.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, ct).ConfigureAwait(false);
                if (existing > 0 && response.StatusCode == System.Net.HttpStatusCode.PartialContent)
                    append = response.Content.Headers.ContentRange?.From == existing;

                if (existing > 0 && !append)
                {
                    response.Dispose();
                    response = null;
                    existing = 0;
                    using var restart = new HttpRequestMessage(HttpMethod.Get, url);
                    response = await _http.SendAsync(restart, HttpCompletionOption.ResponseHeadersRead, ct).ConfigureAwait(false);
                }

                response.EnsureSuccessStatusCode();
                await WriteDownloadAsync(response, temp, append, existing, expectedSize, progress, ct).ConfigureAwait(false);
            }
            finally
            {
                response?.Dispose();
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
            throw;
        }
        catch (IOException)
        {
            try { if (File.Exists(temp)) File.Delete(temp); } catch { }
            throw;
        }
    }

    private static async Task WriteDownloadAsync(HttpResponseMessage response, string temp, bool append, long existing, long expectedSize, IProgress<double>? progress, CancellationToken ct)
    {
        var total = expectedSize > 0
            ? expectedSize
            : (response.Content.Headers.ContentLength.HasValue ? existing + response.Content.Headers.ContentLength.Value : 0);
        await using var src = await response.Content.ReadAsStreamAsync(ct).ConfigureAwait(false);
        await using var dst = new FileStream(temp, append ? FileMode.Append : FileMode.Create, FileAccess.Write, FileShare.None, 1024 * 1024, useAsync: true);
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

    public static bool HasAuthenticodeSignature(string path)
    {
        if (!OperatingSystem.IsWindows() || !File.Exists(path)) return false;
        try { _ = X509Certificate.CreateFromSignedFile(path); return true; }
        catch { return false; }
    }

    public static bool VerifyAuthenticode(string path, string expectedPublisher)
    {
        if (!OperatingSystem.IsWindows() || !File.Exists(path)) return false;
        if (!string.Equals(Path.GetExtension(path), ".exe", StringComparison.OrdinalIgnoreCase) &&
            !string.Equals(Path.GetExtension(path), ".msi", StringComparison.OrdinalIgnoreCase))
            return true; // archives are verified by SHA-256; contained binaries are validated by their installer/runtime checks.

        var fileInfo = new WINTRUST_FILE_INFO(path);
        var guid = WINTRUST_ACTION_GENERIC_VERIFY_V2;
        var data = new WINTRUST_DATA(ref fileInfo);
        try
        {
            var status = WinVerifyTrust(IntPtr.Zero, ref guid, ref data);
            if (status != 0) return false;
            if (string.IsNullOrWhiteSpace(expectedPublisher)) return true;
            var cert = X509Certificate.CreateFromSignedFile(path);
            return cert.Subject.IndexOf(expectedPublisher, StringComparison.OrdinalIgnoreCase) >= 0;
        }
        catch { return false; }
        finally { data.Dispose(); fileInfo.Dispose(); }
    }

    private static readonly Guid WINTRUST_ACTION_GENERIC_VERIFY_V2 = new("00AAC56B-CD44-11d0-8CC2-00C04FC295EE");

    [DllImport("wintrust.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    private static extern uint WinVerifyTrust(IntPtr hwnd, ref Guid pgActionID, ref WINTRUST_DATA pWVTData);

    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    private sealed class WINTRUST_FILE_INFO : IDisposable
    {
        public uint cbStruct = (uint)Marshal.SizeOf<WINTRUST_FILE_INFO>();
        public IntPtr pcwszFilePath;
        public IntPtr hFile = IntPtr.Zero;
        public IntPtr pgKnownSubject = IntPtr.Zero;
        public WINTRUST_FILE_INFO(string path) => pcwszFilePath = Marshal.StringToCoTaskMemUni(path);
        public void Dispose() { if (pcwszFilePath != IntPtr.Zero) Marshal.FreeCoTaskMem(pcwszFilePath); pcwszFilePath = IntPtr.Zero; }
    }

    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    private struct WINTRUST_DATA : IDisposable
    {
        public uint cbStruct;
        public IntPtr pPolicyCallbackData;
        public IntPtr pSIPClientData;
        public uint dwUIChoice;
        public uint fdwRevocationChecks;
        public uint dwUnionChoice;
        public IntPtr pFile;
        public uint dwStateAction;
        public IntPtr hWVTStateData;
        public IntPtr pwszURLReference;
        public uint dwProvFlags;
        public uint dwUIContext;
        public WINTRUST_DATA(ref WINTRUST_FILE_INFO file)
        {
            cbStruct = (uint)Marshal.SizeOf<WINTRUST_DATA>();
            pPolicyCallbackData = IntPtr.Zero; pSIPClientData = IntPtr.Zero; dwUIChoice = 2;
            fdwRevocationChecks = 0; dwUnionChoice = 1; pFile = Marshal.AllocCoTaskMem(Marshal.SizeOf<WINTRUST_FILE_INFO>());
            Marshal.StructureToPtr(file, pFile, false); dwStateAction = 0; hWVTStateData = IntPtr.Zero;
            pwszURLReference = IntPtr.Zero; dwProvFlags = 0x00000010; dwUIContext = 0;
        }
        public void Dispose()
        {
            dwStateAction = 1; // WTD_STATEACTION_CLOSE
            try { var guid = WINTRUST_ACTION_GENERIC_VERIFY_V2; WinVerifyTrust(IntPtr.Zero, ref guid, ref this); } catch { }
            if (pFile != IntPtr.Zero) { Marshal.FreeCoTaskMem(pFile); pFile = IntPtr.Zero; }
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
