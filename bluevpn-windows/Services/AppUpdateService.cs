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
        var arch = RuntimeInformation.ProcessArchitecture == Architecture.Arm64 ? "win-arm64" : "win-x64";
        var info = await _api.GetWindowsUpdateAsync(_settings.Version, arch, ct);
        if (!info.Available || !info.UpdateAvailable) return null;
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
            info.AutoUpdate,
            info.ForceUpdate,
            info.ReleaseChannel,
            info.Message
        );
    }

    public async Task<string> DownloadAsync(UpdateCandidate candidate, CancellationToken ct = default)
    {
        var root = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "BlueVPN", "updates", candidate.Version);
        Directory.CreateDirectory(root);
        var path = Path.Combine(root, candidate.AssetName);
        using var gh = new GitHubReleaseClient($"BlueVPN-Windows/{_settings.Version}");
        await gh.DownloadVerifiedAsync(candidate.DownloadUrl, path, candidate.Digest, ct);
        return path;
    }

    public static bool LaunchInstaller(string installerPath)
    {
        if (!File.Exists(installerPath)) return false;
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
