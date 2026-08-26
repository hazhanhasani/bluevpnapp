using System.IO;
using System.Runtime.InteropServices;
using System.Text.Json;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Interop;
using System.Windows.Media;
using Microsoft.Web.WebView2.Wpf;
using Microsoft.Win32;

namespace BlueVPN.Windows.Services;

public sealed class WindowsThemeService
{
    private const int DwmUseImmersiveDarkMode = 20;
    private const int DwmUseImmersiveDarkModeBefore20H1 = 19;
    private static bool _globalHooksInstalled;

    private readonly string _path;
    public string Preference { get; private set; } = "system";

    public WindowsThemeService()
    {
        var dir = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "BlueVPN");
        Directory.CreateDirectory(dir);
        _path = Path.Combine(dir, "ui-preferences.json");
        Preference = LoadPreference();

        // WebView2 defaults to an opaque white surface. Set the process default
        // before any controller/environment is created so collapsed/loading web
        // surfaces can never flash a white rectangle over BlueVPN Dark mode.
        Environment.SetEnvironmentVariable(
            "WEBVIEW2_DEFAULT_BACKGROUND_COLOR",
            "00000000",
            EnvironmentVariableTarget.Process);
        EnsureGlobalSurfaceHooks();
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
            Set("BlueVpnOverlay", "#99000000");
            Set("BlueVpnAdBg", "#FF071328");
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
            Set("BlueVpnOverlay", "#66000000");
            Set("BlueVpnAdBg", "#FF071328");
        }

        window.Background = Get("BlueVpnBg");
        root.Background = Get("BlueVpnBg");

        // Theme every open BlueVPN window, not only MainWindow. This keeps
        // account/support/story/future dialogs and embedded web surfaces in sync
        // when the user switches theme while they are already open.
        foreach (Window openWindow in Application.Current.Windows)
            ApplyWindowSurfaces(openWindow, dark);
    }

    private static void EnsureGlobalSurfaceHooks()
    {
        if (_globalHooksInstalled) return;
        _globalHooksInstalled = true;

        EventManager.RegisterClassHandler(
            typeof(Window),
            FrameworkElement.LoadedEvent,
            new RoutedEventHandler((sender, _) =>
            {
                if (sender is Window window)
                    ApplyWindowSurfaces(window, CurrentPaletteIsDark());
            }));

        EventManager.RegisterClassHandler(
            typeof(WebView2),
            FrameworkElement.LoadedEvent,
            new RoutedEventHandler((sender, _) =>
            {
                if (sender is WebView2 webView)
                    MakeWebViewThemeSafe(webView);
            }));
    }

    private static void ApplyWindowSurfaces(Window window, bool dark)
    {
        ApplyNativeChrome(window, dark);
        ApplyWebViewSurfaces(window);
    }

    private static void ApplyWebViewSurfaces(DependencyObject root)
    {
        if (root is WebView2 webView) MakeWebViewThemeSafe(webView);

        int count;
        try { count = VisualTreeHelper.GetChildrenCount(root); }
        catch { return; }

        for (var i = 0; i < count; i++)
        {
            var child = VisualTreeHelper.GetChild(root, i);
            ApplyWebViewSurfaces(child);
        }
    }

    private static void MakeWebViewThemeSafe(WebView2 webView)
    {
        // Transparent is supported by WebView2 on current Windows versions and
        // lets the themed WPF card beneath remain visible while a page is empty,
        // loading, rejected by the provider, or intentionally transparent.
        webView.DefaultBackgroundColor = System.Drawing.Color.Transparent;
    }

    private static void ApplyNativeChrome(Window window, bool dark)
    {
        if (!OperatingSystem.IsWindows() || window.WindowStyle == WindowStyle.None) return;

        var helper = new WindowInteropHelper(window);
        var handle = helper.Handle;
        if (handle == IntPtr.Zero)
        {
            void OnSourceInitialized(object? sender, EventArgs args)
            {
                window.SourceInitialized -= OnSourceInitialized;
                ApplyNativeChrome(window, dark);
            }
            window.SourceInitialized += OnSourceInitialized;
            return;
        }

        var enabled = dark ? 1 : 0;
        // Attribute 20 is the current contract; 19 is the compatibility value on
        // older Windows 10 builds. Failure is non-fatal and leaves OS chrome as-is.
        if (DwmSetWindowAttribute(handle, DwmUseImmersiveDarkMode, ref enabled, sizeof(int)) != 0)
            _ = DwmSetWindowAttribute(handle, DwmUseImmersiveDarkModeBefore20H1, ref enabled, sizeof(int));
    }

    private static bool CurrentPaletteIsDark()
    {
        if (Application.Current.Resources["BlueVpnBg"] is not SolidColorBrush brush) return false;
        var color = brush.Color;
        // Perceptual luminance approximation; only used to pick native title-bar mode.
        var luminance = (299 * color.R + 587 * color.G + 114 * color.B) / 1000;
        return luminance < 128;
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

    [DllImport("dwmapi.dll")]
    private static extern int DwmSetWindowAttribute(IntPtr hwnd, int dwAttribute, ref int pvAttribute, int cbAttribute);
}
