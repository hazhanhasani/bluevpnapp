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
        key.SetValue("ProxyOverride", "localhost;127.*;10.*;172.16.*;172.17.*;172.18.*;172.19.*;172.2*;192.168.*;<local>", RegistryValueKind.String);
        RefreshInternetSettings();
        IsActive = true;
    }

    public void Restore()
    {
        if (!OperatingSystem.IsWindows()) { IsActive = false; return; }
        RestoreFromFile(deleteAfter: true);
        IsActive = false;
    }

    private void RecoverStaleState()
    {
        if (!OperatingSystem.IsWindows() || !File.Exists(_statePath)) return;
        RestoreFromFile(deleteAfter: true);
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
        File.WriteAllText(_statePath, JsonSerializer.Serialize(state, AppSettings.JsonOptions()));
    }

    private void RestoreFromFile(bool deleteAfter)
    {
        try
        {
            if (!File.Exists(_statePath)) return;
            var state = JsonSerializer.Deserialize<ProxyState>(File.ReadAllText(_statePath), AppSettings.JsonOptions());
            if (state is null) return;
            using var key = Registry.CurrentUser.OpenSubKey(InternetSettings, writable: true);
            if (key is null) return;
            key.SetValue("ProxyEnable", state.ProxyEnable, RegistryValueKind.DWord);
            key.SetValue("ProxyServer", state.ProxyServer ?? "", RegistryValueKind.String);
            key.SetValue("ProxyOverride", state.ProxyOverride ?? "", RegistryValueKind.String);
            RefreshInternetSettings();
        }
        catch { }
        finally
        {
            if (deleteAfter)
            {
                try { if (File.Exists(_statePath)) File.Delete(_statePath); } catch { }
            }
        }
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
