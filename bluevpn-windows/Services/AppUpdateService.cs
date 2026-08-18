using System.Diagnostics;
using System.IO;
using System.Runtime.InteropServices;
using System.Text.Json;
using BlueVPN.Windows.Models;

namespace BlueVPN.Windows.Services;

public sealed class AppUpdateService
{
    private readonly AppSettings _settings;
    public AppUpdateService(AppSettings settings) => _settings = settings;

    public async Task<UpdateCandidate?> CheckAsync(CancellationToken ct = default)
    {
        using var gh = new GitHubReleaseClient($"BlueVPN-Windows/{_settings.Version}");
        using var doc = await gh.GetReleasesAsync(_settings.WindowsUpdateRepository, 20, ct);
        var current = ParseVersion(_settings.Version);
        var arch = RuntimeInformation.ProcessArchitecture == Architecture.Arm64 ? "win-arm64" : "win-x64";
        UpdateCandidate? best = null;
        Version bestVersion = current;

        foreach (var release in doc.RootElement.EnumerateArray())
        {
            if (release.TryGetProperty("draft", out var draft) && draft.GetBoolean()) continue;
            var prerelease = release.TryGetProperty("prerelease", out var pre) && pre.GetBoolean();
            if (!_settings.WindowsChannel.Equals("beta", StringComparison.OrdinalIgnoreCase) && prerelease) continue;
            var tag = release.GetProperty("tag_name").GetString() ?? "";
            if (!tag.StartsWith(_settings.WindowsReleasePrefix, StringComparison.OrdinalIgnoreCase)) continue;
            var versionText = tag[_settings.WindowsReleasePrefix.Length..];
            var version = ParseVersion(versionText);
            if (version <= bestVersion) continue;

            foreach (var asset in release.GetProperty("assets").EnumerateArray())
            {
                var name = asset.GetProperty("name").GetString() ?? "";
                if (!name.Equals($"BlueVPN-Setup-{versionText}-{arch}.exe", StringComparison.OrdinalIgnoreCase)) continue;
                var url = asset.GetProperty("browser_download_url").GetString() ?? "";
                var digest = asset.TryGetProperty("digest", out var d) ? d.GetString() ?? "" : "";
                var html = release.TryGetProperty("html_url", out var h) ? h.GetString() ?? "" : "";
                best = new(versionText, url, digest, name, html);
                bestVersion = version;
                break;
            }
        }
        return best;
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

    private static Version ParseVersion(string? value) => Version.TryParse(value?.TrimStart('v'), out var v) ? v : new Version(0, 0);
}
