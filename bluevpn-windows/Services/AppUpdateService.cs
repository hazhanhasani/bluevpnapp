using System.Diagnostics;
using System.IO;
using System.Runtime.InteropServices;
using BlueVPN.Windows.Models;

namespace BlueVPN.Windows.Services;

public sealed class AppUpdateService
{
    private readonly AppSettings _settings;
    private readonly BlueVpnApiClient _api;

    public AppUpdateService(AppSettings settings, BlueVpnApiClient api)
    {
        _settings = settings;
        _api = api;
    }

    public async Task<UpdateCandidate?> CheckAsync(CancellationToken ct = default)
    {
        var arch = RuntimeInformation.ProcessArchitecture == System.Runtime.InteropServices.Architecture.Arm64 ? "win-arm64" : "win-x64";
        var info = await _api.GetWindowsUpdateAsync(_settings.Version, arch, ct).ConfigureAwait(false);
        if (!info.Available || !info.UpdateAvailable) return null;
        if (!Version.TryParse(_settings.Version.TrimStart('v'), out var currentVersion) ||
            !Version.TryParse(info.LatestVersion?.TrimStart('v'), out var latestVersion))
            throw new InvalidDataException("نسخه فعلی یا نسخه جدید Windows معتبر نیست؛ بروزرسانی متوقف شد.");
        if (latestVersion <= currentVersion) return null;
        if (!Uri.TryCreate(info.DownloadUrl, UriKind.Absolute, out var downloadUri) ||
            !string.Equals(downloadUri.Scheme, Uri.UriSchemeHttps, StringComparison.OrdinalIgnoreCase))
            throw new InvalidDataException("آدرس دانلود Installer ویندوز باید HTTPS باشد؛ بروزرسانی متوقف شد.");
        if (!string.IsNullOrWhiteSpace(info.ReleaseUrl) &&
            (!Uri.TryCreate(info.ReleaseUrl, UriKind.Absolute, out var releaseUri) ||
             !string.Equals(releaseUri.Scheme, Uri.UriSchemeHttps, StringComparison.OrdinalIgnoreCase)))
            throw new InvalidDataException("آدرس صفحه انتشار Windows معتبر نیست؛ بروزرسانی متوقف شد.");
        if (string.IsNullOrWhiteSpace(info.DownloadUrl) || string.IsNullOrWhiteSpace(info.Filename))
            throw new InvalidDataException($"فایل نصب {arch} برای نسخه {info.LatestVersion} در پنل انتشار Windows کامل نیست.");
        if (string.IsNullOrWhiteSpace(info.Sha256) || info.Sha256.Length != 64 || info.Sha256.Any(ch => !Uri.IsHexDigit(ch)))
            throw new InvalidDataException("SHA-256 فایل بروزرسانی Windows در پنل معتبر نیست؛ بروزرسانی برای امنیت متوقف شد.");

        return new UpdateCandidate(
            info.LatestVersion,
            info.DownloadUrl,
            "sha256:" + info.Sha256.ToLowerInvariant(),
            info.Filename,
            info.ReleaseUrl,
            Math.Max(0, info.Size),
            info.AutoUpdate,
            info.ForceUpdate,
            info.ReleaseChannel,
            info.Message
        );
    }

    public async Task<string> DownloadAsync(UpdateCandidate candidate, IProgress<double>? progress = null, CancellationToken ct = default)
    {
        var storage = UpdateStorageManager.PrepareAppUpdate(candidate.Version, candidate.AssetName, candidate.SizeBytes);
        var path = storage.DestinationPath;
        var expected = candidate.Digest.StartsWith("sha256:", StringComparison.OrdinalIgnoreCase) ? candidate.Digest[7..] : "";

        if (File.Exists(path) && expected.Length == 64)
        {
            try
            {
                var current = await GitHubReleaseClient.Sha256Async(path, ct).ConfigureAwait(false);
                if (current.Equals(expected, StringComparison.OrdinalIgnoreCase) &&
                    (!GitHubReleaseClient.HasAuthenticodeSignature(path) || GitHubReleaseClient.VerifyAuthenticode(path, "BlueVPN"))) return path;
            }
            catch { }
            try { File.Delete(path); } catch { }
        }

        Exception? last = null;
        for (var attempt = 1; attempt <= 3; attempt++)
        {
            ct.ThrowIfCancellationRequested();
            try
            {
                progress?.Report((attempt - 1) / 3d);
                using var gh = new GitHubReleaseClient($"BlueVPN-Windows/{_settings.Version}");
                await gh.DownloadVerifiedAsync(candidate.DownloadUrl, path, candidate.Digest, ct, candidate.SizeBytes, progress).ConfigureAwait(false);
                if (GitHubReleaseClient.HasAuthenticodeSignature(path) && !GitHubReleaseClient.VerifyAuthenticode(path, "BlueVPN"))
                {
                    try { File.Delete(path); } catch { }
                    throw new InvalidDataException("امضای دیجیتال Installer ویندوز وجود دارد اما ناشر آن BlueVPN نیست.");
                }
                progress?.Report(1d);
                return path;
            }
            catch (OperationCanceledException) { throw; }
            catch (InsufficientUpdateSpaceException) { throw; }
            catch (IOException ex) when (IsDiskPressure(path, candidate.SizeBytes))
            {
                var required = Math.Max(candidate.SizeBytes, 768L * 1024L * 1024L);
                UpdateStorageManager.EnsureFreeSpaceForPath(path, required);
                throw new InvalidOperationException("فضای ذخیره‌سازی هنگام دریافت بروزرسانی تمام شد. فایل نیمه‌کاره پاک شد؛ کمی فضا آزاد کن و دوباره تلاش کن.", ex);
            }
            catch (Exception ex)
            {
                last = ex;
                try { if (File.Exists(path)) File.Delete(path); } catch { }
                if (attempt < 3) await Task.Delay(TimeSpan.FromSeconds(attempt * 2), ct).ConfigureAwait(false);
            }
        }
        throw new InvalidOperationException($"دریافت بروزرسانی Windows پس از ۳ تلاش ناموفق بود: {last?.Message}", last);
    }

    private static bool IsDiskPressure(string path, long payloadBytes)
    {
        try
        {
            var required = Math.Max(256L * 1024L * 1024L, payloadBytes / 4);
            UpdateStorageManager.EnsureFreeSpaceForPath(path, required);
            return false;
        }
        catch (InsufficientUpdateSpaceException) { return true; }
        catch { return false; }
    }

    public static bool LaunchInstaller(string installerPath, string expectedDigest)
    {
        if (!File.Exists(installerPath)) return false;
        var expected = expectedDigest.StartsWith("sha256:", StringComparison.OrdinalIgnoreCase) ? expectedDigest[7..] : expectedDigest;
        if (expected.Length != 64) return false;
        try
        {
            using var stream = File.OpenRead(installerPath);
            var actual = Convert.ToHexString(System.Security.Cryptography.SHA256.HashData(stream)).ToLowerInvariant();
            if (!actual.Equals(expected, StringComparison.OrdinalIgnoreCase)) return false;
        }
        catch { return false; }
        if (GitHubReleaseClient.HasAuthenticodeSignature(installerPath) && !GitHubReleaseClient.VerifyAuthenticode(installerPath, "BlueVPN")) return false;
        Process.Start(new ProcessStartInfo
        {
            FileName = installerPath,
            Arguments = "/VERYSILENT /SUPPRESSMSGBOXES /NORESTART /CLOSEAPPLICATIONS /RESTARTAPPLICATIONS",
            UseShellExecute = true,
            Verb = "runas"
        });
        return true;
    }
}
