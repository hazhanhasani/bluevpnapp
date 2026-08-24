using System.IO;
using System.Text.Json;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using Microsoft.Win32;

namespace BlueVPN.Windows.Services;

public sealed class WindowsThemeService
{
    private readonly string _path;
    public string Preference { get; private set; } = "system";

    public WindowsThemeService()
    {
        var dir = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "BlueVPN");
        Directory.CreateDirectory(dir);
        _path = Path.Combine(dir, "ui-preferences.json");
        Preference = LoadPreference();
    }

    public void SetPreference(string value, Window window, Panel root)
    {
        Preference = Normalize(value);
        SavePreference();
        Apply(window, root);
    }

    public void Apply(Window window, Panel root)
    {
        var dark = Preference == "dark" || (Preference == "system" && IsWindowsDark());
        if (dark)
        {
            Set("BlueVpnBg", "#FF0A1020");
            Set("BlueVpnSurface", "#FF111A2D");
            Set("BlueVpnSurfaceStrong", "#FF17233A");
            Set("BlueVpnSurfaceSoft", "#FF1C2942");
            Set("BlueVpnStroke", "#FF2A3853");
            Set("BlueVpnText", "#FFF4F7FF");
            Set("BlueVpnTextSecondary", "#FFC1CBE0");
            Set("BlueVpnMuted", "#FF8E9AB2");
            Set("BlueVpnBlue", "#FF5B86FF");
            Set("BlueVpnBlue2", "#FF8AABFF");
            Set("BlueVpnCard", "#FF111A2D");
            Set("BlueVpnCard2", "#FF17233A");
        }
        else
        {
            Set("BlueVpnBg", "#FFF6F8FC");
            Set("BlueVpnSurface", "#FFFFFFFF");
            Set("BlueVpnSurfaceStrong", "#FFEEF2FA");
            Set("BlueVpnSurfaceSoft", "#FFE8EDF7");
            Set("BlueVpnStroke", "#FFC8D1E2");
            Set("BlueVpnText", "#FF141824");
            Set("BlueVpnTextSecondary", "#FF3E4758");
            Set("BlueVpnMuted", "#FF667085");
            Set("BlueVpnBlue", "#FF356DF1");
            Set("BlueVpnBlue2", "#FF2455CC");
            Set("BlueVpnCard", "#FFFFFFFF");
            Set("BlueVpnCard2", "#FFEEF2FA");
        }
        window.Background = Get("BlueVpnBg");
        root.Background = Get("BlueVpnBg");
    }

    private static void Set(string key, string hex)
    {
        Application.Current.Resources[key] = new SolidColorBrush((Color)ColorConverter.ConvertFromString(hex)!);
    }

    private static Brush Get(string key) => Application.Current.Resources[key] as Brush ?? Brushes.Transparent;

    private string LoadPreference()
    {
        try
        {
            if (!File.Exists(_path)) return "system";
            using var doc = JsonDocument.Parse(File.ReadAllText(_path));
            return Normalize(doc.RootElement.TryGetProperty("theme", out var e) ? e.GetString() ?? "system" : "system");
        }
        catch { return "system"; }
    }

    private void SavePreference()
    {
        try
        {
            var temp = _path + ".tmp";
            File.WriteAllText(temp, JsonSerializer.Serialize(new { theme = Preference }, AppSettings.JsonOptions()));
            File.Move(temp, _path, overwrite: true);
        }
        catch { }
    }

    private static string Normalize(string value) => value?.Trim().ToLowerInvariant() switch
    {
        "light" => "light",
        "dark" => "dark",
        _ => "system"
    };

    private static bool IsWindowsDark()
    {
        try
        {
            using var key = Registry.CurrentUser.OpenSubKey(@"Software\Microsoft\Windows\CurrentVersion\Themes\Personalize");
            return Convert.ToInt32(key?.GetValue("AppsUseLightTheme", 1)) == 0;
        }
        catch { return false; }
    }
}
