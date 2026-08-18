using System.IO;
using System.IO.Compression;
using System.Runtime.InteropServices;
using System.Text.Json;

namespace BlueVPN.Windows.Services;

public sealed class RuntimeUpdateService
{
    private readonly AppSettings _settings;
    private readonly RuntimeLocator _runtime;

    public RuntimeUpdateService(AppSettings settings, RuntimeLocator runtime)
    {
        _settings = settings;
        _runtime = runtime;
    }

    public async Task<string> CheckAndUpdateAsync(bool connected, CancellationToken ct = default)
    {
        if (!_settings.AutoUpdateRuntime || connected) return "";
        using var gh = new GitHubReleaseClient($"BlueVPN-Runtime/{_settings.Version}");
        using var doc = await gh.GetReleasesAsync(_settings.V2RayNRepository, 12, ct);
        var current = ParseVersion(_runtime.CurrentRuntime().Version);
        var archName = RuntimeInformation.ProcessArchitecture == Architecture.Arm64 ? "v2rayN-windows-arm64.zip" : "v2rayN-windows-64.zip";

        foreach (var release in doc.RootElement.EnumerateArray())
        {
            if (release.TryGetProperty("draft", out var draft) && draft.GetBoolean()) continue;
            if (release.TryGetProperty("prerelease", out var pre) && pre.GetBoolean()) continue; // runtime stays stable even on BlueVPN beta
            var tag = release.GetProperty("tag_name").GetString() ?? "";
            var version = ParseVersion(tag);
            if (version <= current) continue;

            foreach (var asset in release.GetProperty("assets").EnumerateArray())
            {
                if (!string.Equals(asset.GetProperty("name").GetString(), archName, StringComparison.OrdinalIgnoreCase)) continue;
                var url = asset.GetProperty("browser_download_url").GetString() ?? "";
                var digest = asset.TryGetProperty("digest", out var d) ? d.GetString() ?? "" : "";
                await InstallRuntimeAsync(gh, tag.TrimStart('v'), url, digest, ct);
                return tag.TrimStart('v');
            }
        }
        return "";
    }

    private async Task InstallRuntimeAsync(GitHubReleaseClient gh, string version, string url, string digest, CancellationToken ct)
    {
        var root = Path.Combine(_runtime.OverrideRoot, version);
        if (File.Exists(Path.Combine(root, ".validated"))) return;
        var tempRoot = root + ".tmp";
        var zipPath = tempRoot + ".zip";
        try
        {
            if (Directory.Exists(tempRoot)) Directory.Delete(tempRoot, true);
            Directory.CreateDirectory(tempRoot);
            await gh.DownloadVerifiedAsync(url, zipPath, digest, ct);
            ZipFile.ExtractToDirectory(zipPath, tempRoot, overwriteFiles: true);

            if (!Find(tempRoot, "xray.exe") || !Find(tempRoot, "sing-box.exe") || !Find(tempRoot, "wintun.dll"))
                throw new InvalidDataException("بسته v2rayN جدید Coreهای لازم BlueVPN را ندارد.");

            var normalized = Path.Combine(tempRoot, "bluevpn-core");
            Directory.CreateDirectory(normalized);
            foreach (var name in new[] { "xray.exe", "sing-box.exe", "wintun.dll", "geoip.dat", "geosite.dat" })
            {
                var src = First(tempRoot, name);
                if (src is not null) File.Copy(src, Path.Combine(normalized, name), true);
            }
            File.WriteAllText(Path.Combine(tempRoot, ".validated"), $"v2rayN={version}\nvalidated={DateTimeOffset.UtcNow:O}\n");
            if (Directory.Exists(root)) Directory.Delete(root, true);
            Directory.Move(tempRoot, root);
        }
        finally
        {
            try { if (File.Exists(zipPath)) File.Delete(zipPath); } catch { }
            try { if (Directory.Exists(tempRoot)) Directory.Delete(tempRoot, true); } catch { }
        }
    }

    private static bool Find(string root, string name) => Directory.EnumerateFiles(root, name, SearchOption.AllDirectories).Any();
    private static string? First(string root, string name) => Directory.EnumerateFiles(root, name, SearchOption.AllDirectories).FirstOrDefault();
    private static Version ParseVersion(string? value) => Version.TryParse(value?.TrimStart('v'), out var v) ? v : new Version(0, 0);
}
