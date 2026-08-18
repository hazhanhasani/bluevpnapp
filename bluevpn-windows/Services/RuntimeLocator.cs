using System.IO;
using System.Runtime.InteropServices;
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

    public string Architecture => RuntimeInformation.ProcessArchitecture == Architecture.Arm64 ? "arm64" : "x64";
    public string BundledRoot => _bundledRoot;
    public string OverrideRoot => _overrideRoot;

    public string ResolveXray() => ResolveExecutable("xray.exe");
    public string ResolveSingBox() => ResolveExecutable("sing-box.exe");
    public string ResolveWintun() => ResolveFile("wintun.dll");
    public string ResolveV2rayN() => ResolveExecutable("v2rayN.exe", required: false);

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
        var xray = TryResolve("xray.exe");
        var sing = TryResolve("sing-box.exe");
        var wt = TryResolve("wintun.dll");
        if (xray.Length == 0 || sing.Length == 0 || wt.Length == 0)
            return "هسته اتصال ویندوز ناقص است";
        var info = CurrentRuntime();
        var warp = ResolveAether().Length > 0 ? "WARP آماده" : (Architecture == "arm64" ? "WARP: مسیر جایگزین" : "WARP موجود نیست");
        return $"v2rayN {info.Version} • Xray + sing-box • {warp}";
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
                .Where(dir => File.Exists(Path.Combine(dir, ".validated")))
                .OrderByDescending(dir => ParseVersion(Path.GetFileName(dir)))
                .FirstOrDefault() ?? "";
        }
        catch { return ""; }
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
