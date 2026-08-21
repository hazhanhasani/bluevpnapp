using System.IO;
using System.Runtime.InteropServices;
using System.Text.Json;
using Microsoft.Win32;

namespace BlueVPN.Windows.Services;

/// <summary>
/// Compatibility fallback for Windows builds where the TUN adapter cannot be
/// installed or routed reliably. It mirrors v2rayN's system-proxy fallback:
/// Xray remains the protocol core and Windows/WinINET points HTTP/HTTPS/SOCKS
/// traffic at the local Xray inbounds. Previous user settings are restored on
/// disconnect and also recovered at next startup after an unclean shutdown.
/// </summary>
public sealed class WindowsSystemProxyController
{
    private const string InternetSettings = @"Software\Microsoft\Windows\CurrentVersion\Internet Settings";
    private const int InternetOptionSettingsChanged = 39;
    private const int InternetOptionRefresh = 37;
    private readonly string _statePath;

    public WindowsSystemProxyController()
    {
        var dir = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "BlueVPN", "runtime-state");
        Directory.CreateDirectory(dir);
        _statePath = Path.Combine(dir, "system-proxy-restore.json");
        RecoverStaleState();
    }

    public bool IsActive { get; private set; }

    public void Enable(int httpPort, int socksPort)
    {
        if (!OperatingSystem.IsWindows()) return;
        if (!IsActive) SaveCurrentState();

        using var key = Registry.CurrentUser.OpenSubKey(InternetSettings, writable: true)
            ?? throw new InvalidOperationException("تنظیمات Proxy ویندوز در دسترس نیست.");
        key.SetValue("ProxyEnable", 1, RegistryValueKind.DWord);
        key.SetValue("ProxyServer", $"http=127.0.0.1:{httpPort};https=127.0.0.1:{httpPort};socks=127.0.0.1:{socksPort}", RegistryValueKind.String);
        key.SetValue("ProxyOverride", PrivateBypassList(), RegistryValueKind.String);
        RefreshInternetSettings();
        IsActive = true;
    }

    public void Restore()
    {
        if (!OperatingSystem.IsWindows()) { IsActive = false; return; }
        var restored = RestoreFromFile(deleteAfterSuccess: true);
        IsActive = !restored && File.Exists(_statePath);
    }

    private void RecoverStaleState()
    {
        if (!OperatingSystem.IsWindows() || !File.Exists(_statePath)) return;
        _ = RestoreFromFile(deleteAfterSuccess: true);
    }

    private void SaveCurrentState()
    {
        using var key = Registry.CurrentUser.OpenSubKey(InternetSettings, writable: false)
            ?? throw new InvalidOperationException("تنظیمات Proxy ویندوز در دسترس نیست.");
        var state = new ProxyState
        {
            ProxyEnable = Convert.ToInt32(key.GetValue("ProxyEnable", 0)),
            ProxyServer = Convert.ToString(key.GetValue("ProxyServer", "")) ?? "",
            ProxyOverride = Convert.ToString(key.GetValue("ProxyOverride", "")) ?? "",
        };
        var temp = _statePath + ".tmp";
        File.WriteAllText(temp, JsonSerializer.Serialize(state, AppSettings.JsonOptions()));
        File.Move(temp, _statePath, overwrite: true);
    }

    private bool RestoreFromFile(bool deleteAfterSuccess)
    {
        var restored = false;
        try
        {
            if (!File.Exists(_statePath)) return true;
            var state = JsonSerializer.Deserialize<ProxyState>(File.ReadAllText(_statePath), AppSettings.JsonOptions());
            if (state is null) return false;
            using var key = Registry.CurrentUser.OpenSubKey(InternetSettings, writable: true);
            if (key is null) return false;
            key.SetValue("ProxyEnable", state.ProxyEnable, RegistryValueKind.DWord);
            key.SetValue("ProxyServer", state.ProxyServer ?? "", RegistryValueKind.String);
            key.SetValue("ProxyOverride", state.ProxyOverride ?? "", RegistryValueKind.String);
            RefreshInternetSettings();
            restored = true;
            return true;
        }
        catch { return false; }
        finally
        {
            if (restored && deleteAfterSuccess)
            {
                try { if (File.Exists(_statePath)) File.Delete(_statePath); } catch { }
            }
        }
    }

    private static string PrivateBypassList()
    {
        var ranges = Enumerable.Range(16, 16).Select(x => $"172.{x}.*");
        return string.Join(';', new[] { "localhost", "127.*", "10.*" }.Concat(ranges).Concat(new[] { "192.168.*", "<local>" }));
    }

    private static void RefreshInternetSettings()
    {
        if (!OperatingSystem.IsWindows()) return;
        InternetSetOption(IntPtr.Zero, InternetOptionSettingsChanged, IntPtr.Zero, 0);
        InternetSetOption(IntPtr.Zero, InternetOptionRefresh, IntPtr.Zero, 0);
    }

    [DllImport("wininet.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool InternetSetOption(IntPtr hInternet, int dwOption, IntPtr lpBuffer, int dwBufferLength);

    private sealed class ProxyState
    {
        public int ProxyEnable { get; set; }
        public string ProxyServer { get; set; } = "";
        public string ProxyOverride { get; set; } = "";
    }
}
