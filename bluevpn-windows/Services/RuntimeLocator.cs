using System.IO;
using System.Runtime.InteropServices;
using System.Security.Cryptography;
using System.Text.Json;
using BlueVPN.Windows.Models;

namespace BlueVPN.Windows.Services;

/// <summary>
/// Resolves the v2rayN runtime bundle. BlueVPN keeps its own UI and account model,
/// while core binaries are sourced from the pinned/validated official v2rayN package.
/// A validated per-user runtime downloaded by RuntimeUpdateService wins over the
/// bundled runtime shipped by the installer.
/// </summary>
public sealed class RuntimeLocator
{
    private readonly AppSettings _settings;
    private readonly string _bundledRoot;
    private readonly string _overrideRoot;

    public RuntimeLocator(AppSettings settings)
    {
        _settings = settings;
        _bundledRoot = Path.Combine(AppContext.BaseDirectory, "runtime", "v2rayn");
        _overrideRoot = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "BlueVPN", "runtime", "v2rayn");
    }

    public string Architecture => RuntimeInformation.ProcessArchitecture == System.Runtime.InteropServices.Architecture.Arm64 ? "arm64" : "x64";
    public string BundledRoot => _bundledRoot;
    public string OverrideRoot => _overrideRoot;

    // Xray system-proxy mode must not depend on optional sing-box/Wintun files.
    public string ResolveXray() => ResolveExecutable("xray.exe");
    public IReadOnlyList<string> ResolveXrayCandidates()
    {
        var result = new List<string>();
        var active = ActiveOverrideDirectory();
        if (!string.IsNullOrWhiteSpace(active))
        {
            var updated = FindFirst(active, "xray.exe");
            if (updated is not null) result.Add(updated);
        }
        var bundled = FindFirst(_bundledRoot, "xray.exe");
        if (bundled is not null && !result.Contains(bundled, StringComparer.OrdinalIgnoreCase)) result.Add(bundled);
        if (result.Count == 0) throw new FileNotFoundException("xray.exe در Runtime ویندوز BlueVPN پیدا نشد.");
        return result;
    }

    public void RejectOverrideContaining(string executable, string reason)
    {
        try
        {
            var full = Path.GetFullPath(executable);
            var root = Path.GetFullPath(_overrideRoot) + Path.DirectorySeparatorChar;
            if (!full.StartsWith(root, StringComparison.OrdinalIgnoreCase)) return;
            var versionDir = Directory.EnumerateDirectories(_overrideRoot)
                .OrderByDescending(x => x.Length)
                .FirstOrDefault(x => full.StartsWith(Path.GetFullPath(x) + Path.DirectorySeparatorChar, StringComparison.OrdinalIgnoreCase));
            if (versionDir is null) return;
            File.WriteAllText(Path.Combine(versionDir, ".rejected"), $"{DateTimeOffset.UtcNow:O}\n{reason}");
        }
        catch { }
    }
    public string ResolveSingBox() => ResolveV2RayNBundle().SingBoxPath;
    public string ResolveWintun() => ResolveV2RayNBundle().WintunPath;
    public string ResolveV2rayN() => ResolveV2RayNBundle().V2RayNPath;

    /// <summary>
    /// Resolve one coherent official v2rayN runtime root. BlueVPN never mixes
    /// Xray/sing-box/Wintun files from different installs or update versions.
    /// The upstream GUI executable is retained and validated as part of the
    /// bundle, but customer interaction stays exclusively in BlueVPN UI.
    /// </summary>
    public V2RayNRuntimeBundle ResolveV2RayNBundle()
    {
        var root = ActiveRuntimeRoot();
        var v2rayN = FindFirst(root, "v2rayN.exe");
        var xray = FindFirst(root, "xray.exe");
        var singBox = FindFirst(root, "sing-box.exe");
        var wintun = FindFirst(root, "wintun.dll");
        var geoIp = FindFirst(root, "geoip.dat");
        var geoSite = FindFirst(root, "geosite.dat");
        if (v2rayN is null || xray is null || singBox is null || wintun is null || geoIp is null || geoSite is null)
            throw new FileNotFoundException("بسته کامل هسته اتصال BlueVPN/v2rayN پیدا نشد.");
        return new V2RayNRuntimeBundle(root, v2rayN, xray, singBox, wintun, geoIp, geoSite);
    }

    public string ResolveAether()
    {
        var path = FindFirst(Path.Combine(AppContext.BaseDirectory, "runtime", "aether"), "aether.exe");
        return path ?? "";
    }

    public RuntimeVersionInfo CurrentRuntime()
    {
        var active = ActiveOverrideDirectory();
        if (!string.IsNullOrWhiteSpace(active))
        {
            return new RuntimeVersionInfo(Path.GetFileName(active), "updated", active);
        }
        return new RuntimeVersionInfo(_settings.V2RayNVersion, "bundled", _bundledRoot);
    }

    public string RuntimeStatus()
    {
        try
        {
            _ = ResolveV2RayNBundle();
            var warp = ResolveAether().Length > 0 ? "مسیر رایگان آماده" : (Architecture == "arm64" ? "مسیر رایگان جایگزین" : "مسیر رایگان موجود نیست");
            return $"BlueVPN Core آماده • {warp}";
        }
        catch
        {
            return "هسته اتصال ویندوز ناقص است";
        }
    }

    public string ActiveRuntimeRoot()
    {
        var active = ActiveOverrideDirectory();
        return string.IsNullOrWhiteSpace(active) ? _bundledRoot : active;
    }

    private string ResolveExecutable(string name, bool required = true)
    {
        var path = TryResolve(name);
        if (path.Length > 0) return path;
        if (!required) return "";
        throw new FileNotFoundException($"{name} در Runtime ویندوز BlueVPN پیدا نشد.");
    }

    private string ResolveFile(string name)
    {
        var path = TryResolve(name);
        if (path.Length > 0) return path;
        throw new FileNotFoundException($"{name} در Runtime ویندوز BlueVPN پیدا نشد.");
    }

    private string TryResolve(string name)
    {
        var active = ActiveOverrideDirectory();
        if (!string.IsNullOrWhiteSpace(active))
        {
            var found = FindFirst(active, name);
            if (found is not null) return found;
        }
        return FindFirst(_bundledRoot, name) ?? "";
    }

    private string ActiveOverrideDirectory()
    {
        try
        {
            if (!Directory.Exists(_overrideRoot)) return "";
            return Directory.EnumerateDirectories(_overrideRoot)
                .Where(IsValidatedRuntime)
                .OrderByDescending(dir => ParseVersion(Path.GetFileName(dir)))
                .FirstOrDefault() ?? "";
        }
        catch { return ""; }
    }

    private static bool IsValidatedRuntime(string dir)
    {
        try
        {
            if (File.Exists(Path.Combine(dir, ".rejected"))) return false;
            if (!File.Exists(Path.Combine(dir, ".validated"))) return false;
            var manifestPath = Path.Combine(dir, ".manifest.json");
            if (!File.Exists(manifestPath)) return false;
            using var doc = JsonDocument.Parse(File.ReadAllText(manifestPath));
            if (!doc.RootElement.TryGetProperty("files", out var files) || files.ValueKind != JsonValueKind.Object) return false;
            foreach (var name in new[] { "v2rayN.exe", "xray.exe", "sing-box.exe", "wintun.dll", "geoip.dat", "geosite.dat" })
            {
                if (!files.TryGetProperty(name, out var hashElement)) return false;
                var expected = hashElement.GetString() ?? "";
                var path = Path.Combine(dir, "bluevpn-core", name);
                if (!File.Exists(path) || expected.Length != 64) return false;
                using var stream = File.OpenRead(path);
                var actual = Convert.ToHexString(SHA256.HashData(stream)).ToLowerInvariant();
                if (!actual.Equals(expected, StringComparison.OrdinalIgnoreCase)) return false;
            }
            return true;
        }
        catch { return false; }
    }

    private static Version ParseVersion(string? value) => Version.TryParse(value?.TrimStart('v'), out var v) ? v : new Version(0, 0);

    private static string? FindFirst(string root, string name)
    {
        try
        {
            if (!Directory.Exists(root)) return null;
            return Directory.EnumerateFiles(root, name, SearchOption.AllDirectories).FirstOrDefault();
        }
        catch { return null; }
    }
}

public sealed record V2RayNRuntimeBundle(string RootPath, string V2RayNPath, string XrayPath, string SingBoxPath, string WintunPath, string GeoIpPath, string GeoSitePath);
