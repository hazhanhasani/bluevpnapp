using System.Diagnostics;
using System.Net.NetworkInformation;
using System.Windows;
using System.Windows.Input;
using System.Windows.Media;
using System.Windows.Controls;
using System.Windows.Threading;
using BlueVPN.Windows.Models;
using BlueVPN.Windows.Services;
using Microsoft.Web.WebView2.Core;

namespace BlueVPN.Windows;

public partial class MainWindow : Window
{
    private readonly BlueVpnApiClient _api;
    private readonly ConnectionOrchestrator _connection;
    private readonly AppSettings _settings;
    private readonly AdvertisementService _ads;
    private readonly AppUpdateService _appUpdater;
    private readonly RuntimeUpdateService _runtimeUpdater;
    private readonly WindowsThemeService _theme;

    private readonly CancellationTokenSource _lifetimeCts = new();
    private readonly DispatcherTimer _adTimer = new();
    private readonly DispatcherTimer _maintenanceTimer = new();
    private readonly SemaphoreSlim _updateGate = new(1, 1);

    private Account? _account;
    private CancellationTokenSource? _connectCts;
    private CancellationTokenSource? _adImageCts;
    private Task? _metricsLoop;
    private bool _connectionOperationRunning;
    private bool _accountOperationRunning;
    private bool _maintenanceRunning;
    private bool _themeUiReady;
    private bool _purchaseOperationRunning;
    private bool _supportOperationRunning;
    private bool _supportSelectionChanging;
    private DateTimeOffset? _connectedAt;
    private long _lastReceivedBytes;
    private long _lastSentBytes;
    private DateTimeOffset _lastByteSample = DateTimeOffset.UtcNow;
    private int _adIndex;
    private double _adImageAspectRatio;
    private bool _tapsellWebInitialized;
    private CoreWebView2Environment? _tapsellWebEnvironment;
    private Microsoft.Web.WebView2.Wpf.WebView2CompositionControl? _tapsellWebView;
    private UpdateCandidate? _pendingUpdate;
    private long? _remainingSecondsAtSnapshot;
    private DateTimeOffset _accountSnapshotAt = DateTimeOffset.UtcNow;
    private string _authMode = "sms";
    private bool _emailRegisterMode;
    private string _otpPhone = "";
    private string _preferredLocationKey = "";
    private string _preferredLocationLabel = "انتخاب خودکار";

    public MainWindow()
    {
        InitializeComponent();
        AppServices.EnsureInitialized();
        _settings = AppServices.Settings;
        _api = AppServices.Api!;
        _connection = AppServices.Connection!;
        _ads = AppServices.Advertisements!;
        _appUpdater = AppServices.AppUpdater!;
        _runtimeUpdater = AppServices.RuntimeUpdater!;
        _theme = new WindowsThemeService();
        _theme.Apply(this, RootGrid);
        InitializeThemeUi();

        VersionText.Text = _settings.Version;
        MenuVersionText.Text = _settings.Version;
        CoreVersionText.Text = "BlueVPN Core";
        TechnicalText.Text = _connection.RuntimeStatus;
        MenuTechnicalText.Text = $"{_connection.RuntimeStatus} • {_connection.AiStatus}";
        ApplyAuthModeUi();

        // Restore the encrypted Windows session immediately so closing/reopening
        // BlueVPN does not look like a logout. The server copy is refreshed on load.
        _account = _api.CachedAccount;
        if (_account is not null)
        {
            ApplyAccount();
            AuthStatusText.Text = "حساب ذخیره‌شده بازیابی شد.";
        }

        _adTimer.Tick += async (_, _) =>
        {
            try { await AdvanceAdAsync(); }
            catch (OperationCanceledException) when (_lifetimeCts.IsCancellationRequested) { }
            catch (Exception ex)
            {
                _adTimer.Stop();
                SetTapsellWebVisibility(Visibility.Collapsed);
                TapsellLoadingPanel.Visibility = Visibility.Collapsed;
                AdProviderLabel.Visibility = Visibility.Collapsed;
                FooterStatus.Text = "چرخش تبلیغ متوقف شد؛ اتصال BlueVPN ادامه دارد. " + ShortUiError(ex.Message);
            }
        };
        _maintenanceTimer.Interval = TimeSpan.FromHours(4);
        _maintenanceTimer.Tick += MaintenanceTimer_Tick;

        Loaded += MainWindow_Loaded;
        Closing += MainWindow_Closing;
        // Tapsell is optional UI. Never construct WebView2 inside XAML startup.
        TryCreateTapsellWebSurface();
        MaxHeight = Math.Max(560, SystemParameters.WorkArea.Height);
        Height = Math.Min(760, Math.Max(560, SystemParameters.WorkArea.Height - 18));
        MaxWidth = Math.Max(620, SystemParameters.WorkArea.Width);
    }

    private bool TryCreateTapsellWebSurface()
    {
        if (_tapsellWebView is not null) return true;
        try
        {
            var webView = new Microsoft.Web.WebView2.Wpf.WebView2CompositionControl
            {
                Visibility = Visibility.Collapsed,
                HorizontalAlignment = HorizontalAlignment.Stretch,
                VerticalAlignment = VerticalAlignment.Stretch
            };
            TapsellWebHost.Children.Clear();
            TapsellWebHost.Children.Add(webView);
            _tapsellWebView = webView;
            return true;
        }
        catch
        {
            _tapsellWebView = null;
            TapsellWebHost.Visibility = Visibility.Collapsed;
            return false;
        }
    }

    private void SetTapsellWebVisibility(Visibility visibility)
    {
        TapsellWebHost.Visibility = visibility;
        if (_tapsellWebView is not null) _tapsellWebView.Visibility = visibility;
    }

    private async void MainWindow_Loaded(object sender, RoutedEventArgs e)
    {
        _metricsLoop ??= RunMetricsLoopAsync(_lifetimeCts.Token);

        // Startup hydration must never be able to terminate the WPF dispatcher.
        // Account/IP already fail closed; ads now use the same contract because
        // provider/network/WebView failures are optional UI, never app-fatal.
        try
        {
            await Task.WhenAll(
                RestoreAccountSessionSafeAsync(),
                LoadAdsSafeAsync(),
                RefreshPublicIpAsync());
        }
        catch (OperationCanceledException) when (_lifetimeCts.IsCancellationRequested) { }
        catch (Exception ex)
        {
            FooterStatus.Text = "BlueVPN اجرا شد؛ بخشی از اطلاعات اولیه بعداً دوباره بروزرسانی می‌شود. " + ShortUiError(ex.Message);
        }

        _ = CheckRuntimeUpdateSafeAsync();
        if (_settings.AutoUpdate) _ = CheckAppUpdateSafeAsync(silentWhenCurrent: true, userInitiated: false);
        _maintenanceTimer.Start();
    }

    private void MainWindow_Closing(object? sender, System.ComponentModel.CancelEventArgs e)
    {
        _lifetimeCts.Cancel();
        _connectCts?.Cancel();
        _adImageCts?.Cancel();
        _adTimer.Stop();
        _maintenanceTimer.Stop();
        _connection.Disconnect();
    }

    private async void MaintenanceTimer_Tick(object? sender, EventArgs e)
    {
        if (_maintenanceRunning) return;
        _maintenanceRunning = true;
        try
        {
            if (!_connection.IsConnected)
                await CheckRuntimeUpdateSafeAsync();
            if (_settings.AutoUpdate)
                await CheckAppUpdateSafeAsync(silentWhenCurrent: true, userInitiated: false);
            await LoadAdsSafeAsync();
        }
        catch (OperationCanceledException) when (_lifetimeCts.IsCancellationRequested) { }
        catch (Exception ex)
        {
            FooterStatus.Text = "بروزرسانی دوره‌ای کامل نشد؛ برنامه فعال می‌ماند. " + ShortUiError(ex.Message);
        }
        finally { _maintenanceRunning = false; }
    }

    private async Task LoadAdsSafeAsync()
    {
        try
        {
            await LoadAdsAsync();
        }
        catch (OperationCanceledException) when (_lifetimeCts.IsCancellationRequested) { }
        catch (Exception ex)
        {
            _adTimer.Stop();
            SetTapsellWebVisibility(Visibility.Collapsed);
            TapsellLoadingPanel.Visibility = Visibility.Collapsed;
            AdProviderLabel.Visibility = Visibility.Collapsed;
            AdImage.Source = null;
            AdFallbackPanel.Visibility = Visibility.Visible;
            AdCard.Visibility = Visibility.Collapsed;
            FooterStatus.Text = "تبلیغات فعلاً در دسترس نیست؛ BlueVPN بدون تبلیغ ادامه می‌دهد. " + ShortUiError(ex.Message);
        }
    }

    private async Task LoadAdsAsync()
    {
        await _ads.RefreshAsync(_lifetimeCts.Token);
        _adIndex = 0;
        _adImageAspectRatio = 0;
        ApplyAdCardHeight();
        await ShowCurrentAdAsync();

        _adTimer.Stop();
        var items = _ads.BannerItems;
        if ((_ads.BannerAutoplay && items.Count > 1) || _ads.WindowsWeb.Enabled)
        {
            _adTimer.Interval = TimeSpan.FromMilliseconds(_ads.BannerIntervalMs);
            _adTimer.Start();
        }
    }

    private async Task AdvanceAdAsync()
    {
        var items = _ads.BannerItems;
        if (items.Count == 0)
        {
            await ShowCurrentAdAsync();
            return;
        }
        if (_adIndex >= items.Count - 1 && !_ads.BannerLoop)
        {
            _adTimer.Stop();
            return;
        }
        _adIndex = (_adIndex + 1) % items.Count;
        await ShowCurrentAdAsync();
    }

    private async Task ShowCurrentAdAsync()
    {
        var items = _ads.BannerItems;
        var premium = _account?.Subscription.Active == true;
        if (_ads.TryReserveWindowsWebImpression(premium, items.Count == 0) && await ShowTapsellWebAdAsync())
        {
            _ads.CommitWindowsWebImpression();
            return;
        }

        SetTapsellWebVisibility(Visibility.Collapsed);
        TapsellLoadingPanel.Visibility = Visibility.Collapsed;
        AdProviderLabel.Visibility = Visibility.Collapsed;
        AdCard.Cursor = Cursors.Hand;
        if (items.Count == 0)
        {
            AdCard.Visibility = Visibility.Collapsed;
            AdImage.Source = null;
            AdFallbackPanel.Visibility = Visibility.Visible;
            _adImageAspectRatio = 0;
            return;
        }

        var index = Math.Clamp(_adIndex, 0, items.Count - 1);
        var item = items[index];
        AdTitle.Text = item.Title;
        AdSubtitle.Text = item.Subtitle;
        AdActionText.Text = string.IsNullOrWhiteSpace(item.ButtonText) ? "مشاهده ←" : item.ButtonText + " ←";
        AdCard.Tag = item;
        AdCard.Visibility = Visibility.Visible;
        AdFallbackPanel.Visibility = Visibility.Visible;
        _adImageAspectRatio = 0;
        ApplyAdCardHeight();

        _adImageCts?.Cancel();
        _adImageCts?.Dispose();
        _adImageCts = CancellationTokenSource.CreateLinkedTokenSource(_lifetimeCts.Token);
        var token = _adImageCts.Token;

        AdImage.Source = null;
        var imageUrl = !string.IsNullOrWhiteSpace(item.ImageUrl)
            ? item.ImageUrl
            : (!string.IsNullOrWhiteSpace(item.ImagePath) ? item.ImagePath : item.MediaUrl);
        var image = await MediaAssetLoader.LoadImageAsync(imageUrl, token);
        if (token.IsCancellationRequested || index != _adIndex) return;
        AdImage.Source = image;
        if (image is not null && image.PixelWidth > 0 && image.PixelHeight > 0)
        {
            _adImageAspectRatio = image.PixelWidth / (double)image.PixelHeight;
            AdFallbackPanel.Visibility = Visibility.Collapsed;
        }
        else
        {
            _adImageAspectRatio = 0;
            AdFallbackPanel.Visibility = Visibility.Visible;
        }
        ApplyAdCardHeight();

        if (items.Count > 1)
        {
            var next = items[(index + 1) % items.Count];
            var nextImage = !string.IsNullOrWhiteSpace(next.ImageUrl)
                ? next.ImageUrl
                : (!string.IsNullOrWhiteSpace(next.ImagePath) ? next.ImagePath : next.MediaUrl);
            MediaAssetLoader.Preload(nextImage);
        }
        MediaAssetLoader.Trim();
    }

    private async Task<bool> ShowTapsellWebAdAsync()
    {
        var cfg = _ads.WindowsWeb;
        try
        {
            if (!_tapsellWebInitialized)
            {
                var installProgress = new Progress<string>(text => FooterStatus.Text = text);
                if (!await WebView2RuntimeInstaller.EnsureInstalledAsync(installProgress, _lifetimeCts.Token)) return false;
                if (!TryCreateTapsellWebSurface() || _tapsellWebView is null) return false;
                var webView = _tapsellWebView;
                _tapsellWebEnvironment = await WebView2RuntimeInstaller.CreatePerUserEnvironmentAsync(_lifetimeCts.Token);
                webView.DefaultBackgroundColor = System.Drawing.Color.Transparent;
                await webView.EnsureCoreWebView2Async(_tapsellWebEnvironment);
                if (webView.CoreWebView2 is null) return false;
                webView.CoreWebView2.Settings.AreDevToolsEnabled = false;
                webView.CoreWebView2.Settings.AreDefaultContextMenusEnabled = false;
                webView.CoreWebView2.Settings.IsStatusBarEnabled = false;
                webView.CoreWebView2.Settings.IsWebMessageEnabled = false;
                webView.CoreWebView2.SetVirtualHostNameToFolderMapping(
                    WebView2RuntimeInstaller.VirtualHost,
                    WebView2RuntimeInstaller.ContentFolder,
                    CoreWebView2HostResourceAccessKind.Allow);
                _tapsellWebInitialized = true;
            }

            var html = "<!doctype html><html dir=\"rtl\"><head><meta charset=\"utf-8\">" +
                "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><style>" +
                "html,body{margin:0;width:100%;height:100%;overflow:hidden;background:transparent}" +
                "#bluevpn-ad{width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:transparent}" +
                "#bluevpn-loading{position:fixed;inset:0;display:grid;place-items:center;color:#8e9ab2;font:12px sans-serif}" +
                ".bluevpn-ad-label{position:fixed;left:8px;top:6px;z-index:2147483647;color:#fff;background:#66000100;border-radius:9px;padding:3px 7px;font:9px sans-serif}" +
                "iframe,img,video,canvas{max-width:100%;max-height:100%;border:0}</style></head><body>" +
                "<div id=\"bluevpn-loading\">در حال دریافت تبلیغ…</div><span class=\"bluevpn-ad-label\">تبلیغ</span>" +
                "<div id=\"bluevpn-ad\">" + cfg.ScriptHtml + "</div>" +
                "<script>new MutationObserver(function(){var a=document.getElementById('bluevpn-ad');" +
                "if(a&&a.querySelector(':scope > :not(script):not(style)'))document.getElementById('bluevpn-loading').style.display='none';" +
                "}).observe(document.getElementById('bluevpn-ad'),{childList:true,subtree:true});</script></body></html>";

            AdCard.Tag = null;
            AdImage.Source = null;
            AdCard.Visibility = Visibility.Visible;
            AdCard.Cursor = Cursors.Arrow;
            AdCard.Height = Math.Clamp(cfg.Height, 90, 220);
            // CompositionControl remains visible so Mediaad receives a real viewport.
            // The WPF loading panel is above it and hides the web surface until the
            // official mediaad-* widget contains renderable provider content.
            SetTapsellWebVisibility(Visibility.Visible);
            AdFallbackPanel.Visibility = Visibility.Collapsed;
            TapsellLoadingPanel.Visibility = Visibility.Visible;
            AdProviderLabel.Visibility = Visibility.Visible;
            var address = Uri.TryCreate(cfg.BridgeUrl, UriKind.Absolute, out var bridge) &&
                          bridge.Scheme.Equals(Uri.UriSchemeHttps, StringComparison.OrdinalIgnoreCase)
                ? bridge.ToString()
                : await WebView2RuntimeInstaller.WriteAdDocumentAsync(html, _lifetimeCts.Token);
            if (!await NavigateTapsellAsync(address, _lifetimeCts.Token))
            {
                SetTapsellWebVisibility(Visibility.Collapsed);
                TapsellLoadingPanel.Visibility = Visibility.Collapsed;
                AdProviderLabel.Visibility = Visibility.Collapsed;
                return false;
            }
            if (!await WaitForTapsellContentAsync(_lifetimeCts.Token))
            {
                SetTapsellWebVisibility(Visibility.Collapsed);
                TapsellLoadingPanel.Visibility = Visibility.Collapsed;
                AdProviderLabel.Visibility = Visibility.Collapsed;
                return false;
            }
            TapsellLoadingPanel.Visibility = Visibility.Collapsed;
            SetTapsellWebVisibility(Visibility.Visible);
            return true;
        }
        catch (Exception ex)
        {
            SetTapsellWebVisibility(Visibility.Collapsed);
            TapsellLoadingPanel.Visibility = Visibility.Collapsed;
            AdProviderLabel.Visibility = Visibility.Collapsed;
            FooterStatus.Text = $"تبلیغ وب بارگذاری نشد؛ بنر BlueVPN نمایش داده می‌شود. {ShortUiError(ex.Message)}";
            return false;
        }
    }

    private async Task<bool> NavigateTapsellAsync(string address, CancellationToken ct)
    {
        var webView = _tapsellWebView;
        if (webView?.CoreWebView2 is null) return false;
        var completion = new TaskCompletionSource<bool>(TaskCreationOptions.RunContinuationsAsynchronously);
        void Completed(object? _, CoreWebView2NavigationCompletedEventArgs args) => completion.TrySetResult(args.IsSuccess);
        webView.CoreWebView2.NavigationCompleted += Completed;
        try
        {
            webView.CoreWebView2.Navigate(address);
            using var timeout = CancellationTokenSource.CreateLinkedTokenSource(ct);
            timeout.CancelAfter(TimeSpan.FromSeconds(12));
            return await completion.Task.WaitAsync(timeout.Token);
        }
        finally
        {
            webView.CoreWebView2.NavigationCompleted -= Completed;
        }
    }

    private async Task<bool> WaitForTapsellContentAsync(CancellationToken ct)
    {
        var webView = _tapsellWebView;
        if (webView?.CoreWebView2 is null) return false;
        for (var attempt = 0; attempt < 24; attempt++)
        {
            await Task.Delay(350, ct);
            var result = await webView.CoreWebView2.ExecuteScriptAsync(
                "(()=>{const root=document.getElementById('bluevpn-ad')||document.getElementById('bluevpn-tapsell-root')||document.body;if(!root)return false;" +
                "const visible=n=>{if(!(n instanceof Element))return false;const b=n.getBoundingClientRect(),s=getComputedStyle(n);" +
                "return b.width>20&&b.height>20&&s.display!=='none'&&s.visibility!=='hidden'&&Number(s.opacity||1)>0;};" +
                "const rendered=n=>{if(!visible(n))return false;const s=getComputedStyle(n);" +
                "if(['IFRAME','IMG','VIDEO','CANVAS','OBJECT','EMBED'].includes(n.tagName))return true;" +
                "if(s.backgroundImage&&s.backgroundImage!=='none')return true;" +
                "if(n.id&&n.id.startsWith('mediaad-')&&(n.childElementCount>0||n.textContent.trim().length>0))return true;" +
                "if(n.shadowRoot){for(const c of n.shadowRoot.querySelectorAll('*'))if(rendered(c))return true;}return false;};" +
                "for(const n of root.querySelectorAll('*'))if(rendered(n))return true;return false;})()");
            if (string.Equals(result?.Trim(), "true", StringComparison.OrdinalIgnoreCase)) return true;
        }
        FooterStatus.Text = "تپسل محتوای قابل نمایش برنگرداند؛ بنر BlueVPN جایگزین شد.";
        TapsellLoadingPanel.Visibility = Visibility.Collapsed;
        AdProviderLabel.Visibility = Visibility.Collapsed;
        return false;
    }

    private static string ShortUiError(string value)
    {
        if (string.IsNullOrWhiteSpace(value)) return "";
        var compact = string.Join(" ", value.Split((char[]?)null, StringSplitOptions.RemoveEmptyEntries));
        return compact.Length <= 120 ? compact : compact[..120] + "…";
    }


    private void AdCard_SizeChanged(object sender, SizeChangedEventArgs e)
    {
        if (AdCard.Visibility != Visibility.Visible || Math.Abs(e.NewSize.Width - e.PreviousSize.Width) < 1) return;
        ApplyAdCardHeight();
    }

    private void ApplyAdCardHeight()
    {
        // HomeContentGrid uses a fixed 584-DIP design surface and its Viewbox scales
        // the whole dashboard uniformly. Keep the artwork at its own 20:9/native
        // ratio inside that surface: DPI or window resizing can no longer flatten
        // the banner independently from the surrounding UI.
        var width = AdCard.ActualWidth;
        if (width < 240) width = 440;
        var ratio = _adImageAspectRatio > 0.25 ? _adImageAspectRatio : _ads.BannerAspectRatio;
        var configuredFloor = Math.Clamp((double)_ads.BannerHeight, 116, 160);
        var ratioHeight = ratio > 0.25 ? width / ratio : configuredFloor;
        AdCard.Height = Math.Clamp(ratioHeight, configuredFloor, 220);
    }

    private void AdCard_Click(object sender, MouseButtonEventArgs e)
    {
        if (AdCard.Tag is not AdvertisementItem item) return;

        // BlueVPN deep-links are handled inside the app; web targets use the
        // default browser. This matches Android and avoids invalid shell calls.
        if (item.TargetAction.Equals("purchase", StringComparison.OrdinalIgnoreCase) ||
            item.DeepLink.StartsWith("bluevpn://purchase", StringComparison.OrdinalIgnoreCase))
        {
            MenuDrawer.Visibility = Visibility.Collapsed;
            AccountDrawer.Visibility = Visibility.Visible;
            return;
        }

        var target = !string.IsNullOrWhiteSpace(item.TargetUrl) ? item.TargetUrl : item.DeepLink;
        if (!Uri.TryCreate(target, UriKind.Absolute, out var uri) || uri.Scheme.Equals("bluevpn", StringComparison.OrdinalIgnoreCase)) return;
        try { Process.Start(new ProcessStartInfo(uri.ToString()) { UseShellExecute = true }); } catch { }
    }

    private void AccountButton_Click(object sender, RoutedEventArgs e)
    {
        MenuDrawer.Visibility = Visibility.Collapsed;
        SupportDrawer.Visibility = Visibility.Collapsed;
        AccountDrawer.Visibility = AccountDrawer.Visibility == Visibility.Visible ? Visibility.Collapsed : Visibility.Visible;
    }

    private void MenuButton_Click(object sender, RoutedEventArgs e)
    {
        AccountDrawer.Visibility = Visibility.Collapsed;
        SupportDrawer.Visibility = Visibility.Collapsed;
        MenuDrawer.Visibility = MenuDrawer.Visibility == Visibility.Visible ? Visibility.Collapsed : Visibility.Visible;
        MenuTechnicalText.Text = $"{_connection.RuntimeStatus} • {_connection.AiStatus}";
        MenuIpText.Text = $"IP: {IpValue.Text}";
    }

    private void CloseDrawers_Click(object sender, RoutedEventArgs e)
    {
        AccountDrawer.Visibility = Visibility.Collapsed;
        MenuDrawer.Visibility = Visibility.Collapsed;
        SupportDrawer.Visibility = Visibility.Collapsed;
    }

    private void OpenAccountFromMenu_Click(object sender, RoutedEventArgs e)
    {
        MenuDrawer.Visibility = Visibility.Collapsed;
        SupportDrawer.Visibility = Visibility.Collapsed;
        AccountDrawer.Visibility = Visibility.Visible;
    }

    private async void OpenSupport_Click(object sender, RoutedEventArgs e)
    {
        if (!_api.IsAuthenticated)
        {
            MenuDrawer.Visibility = Visibility.Collapsed;
            SupportDrawer.Visibility = Visibility.Collapsed;
            AccountDrawer.Visibility = Visibility.Visible;
            AuthStatusText.Text = "برای استفاده از پشتیبانی ابتدا وارد حساب BlueVPN شوید.";
            return;
        }
        MenuDrawer.Visibility = Visibility.Collapsed;
        AccountDrawer.Visibility = Visibility.Collapsed;
        SupportDrawer.Visibility = Visibility.Visible;
        await LoadSupportAsync(selectConversationId: null);
    }

    private void InitializeThemeUi()
    {
        foreach (var item in ThemeComboBox.Items.OfType<ComboBoxItem>())
        {
            if (string.Equals(Convert.ToString(item.Tag), _theme.Preference, StringComparison.OrdinalIgnoreCase))
            {
                ThemeComboBox.SelectedItem = item;
                break;
            }
        }
        if (ThemeComboBox.SelectedIndex < 0) ThemeComboBox.SelectedIndex = 0;
        _themeUiReady = true;
    }

    private void ThemeComboBox_SelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        if (!_themeUiReady || ThemeComboBox.SelectedItem is not ComboBoxItem item) return;
        var pref = Convert.ToString(item.Tag) ?? "system";
        _theme.SetPreference(pref, this, RootGrid);
        FooterStatus.Text = pref switch
        {
            "dark" => "تم تیره فعال شد.",
            "light" => "تم روشن فعال شد.",
            _ => "تم برنامه با Windows هماهنگ شد."
        };
    }

    private async void PurchasePlan_Click(object sender, RoutedEventArgs e)
    {
        if ((sender as FrameworkElement)?.Tag is not Plan plan || _purchaseOperationRunning) return;
        if (!_api.IsAuthenticated)
        {
            PurchaseStatusText.Text = "برای خرید سرویس ابتدا وارد حساب شوید.";
            LoginPanel.Visibility = Visibility.Visible;
            AccountPanel.Visibility = Visibility.Collapsed;
            return;
        }

        _purchaseOperationRunning = true;
        PurchaseStatusText.Text = $"در حال ساخت فاکتور امن برای {plan.Title}…";
        try
        {
            var created = await _api.CreateOrderAsync(plan.Id, _lifetimeCts.Token);
            var order = created.Order;
            if (string.IsNullOrWhiteSpace(order.Id)) throw new InvalidOperationException("شناسه فاکتور از سرور دریافت نشد.");

            var opened = await _api.OpenCheckoutAsync(order.Id, _lifetimeCts.Token);
            if (!string.IsNullOrWhiteSpace(opened.Order.PaymentUrl)) order = opened.Order;
            if (!Uri.TryCreate(order.PaymentUrl, UriKind.Absolute, out var paymentUri) ||
                !paymentUri.Scheme.Equals(Uri.UriSchemeHttps, StringComparison.OrdinalIgnoreCase))
                throw new InvalidOperationException("لینک پرداخت امن معتبر نیست.");

            Process.Start(new ProcessStartInfo(paymentUri.ToString()) { UseShellExecute = true });
            PurchaseStatusText.Text = "درگاه پرداخت در مرورگر باز شد؛ BlueVPN وضعیت فعال‌سازی را خودکار بررسی می‌کند.";

            var timeoutSeconds = Math.Clamp(created.PollTimeoutSeconds <= 0 ? 45 : created.PollTimeoutSeconds, 20, 90);
            var intervalSeconds = Math.Clamp(created.PollIntervalSeconds <= 0 ? 5 : created.PollIntervalSeconds, 3, 10);
            var stop = DateTimeOffset.UtcNow + TimeSpan.FromSeconds(timeoutSeconds);
            while (DateTimeOffset.UtcNow < stop)
            {
                await Task.Delay(TimeSpan.FromSeconds(intervalSeconds), _lifetimeCts.Token);
                try { _ = await _api.HeartbeatCheckoutAsync(order.Id, _lifetimeCts.Token); } catch { }
                var status = await _api.CheckOrderAfterSuccessAsync(order.Id, _lifetimeCts.Token);
                order = status.Order;
                if (status.Confirmed || string.Equals(order.Status, "activated", StringComparison.OrdinalIgnoreCase))
                {
                    _account = order.Account ?? await _api.GetAccountAsync(_lifetimeCts.Token);
                    ApplyAccount();
                    await RefreshPlansSafeAsync();
                    try { _ = await _api.CloseCheckoutAsync(order.Id, _lifetimeCts.Token); } catch { }
                    PurchaseStatusText.Text = "پرداخت تأیید شد و اشتراک BlueVPN فعال است.";
                    MessageBox.Show(this, "پرداخت تأیید شد و سرویس شما فعال شد.", "BlueVPN", MessageBoxButton.OK, MessageBoxImage.Information);
                    return;
                }
                if (!status.Pending)
                {
                    var detail = string.IsNullOrWhiteSpace(order.ActivationError) ? "پرداخت نهایی نشد." : order.ActivationError;
                    PurchaseStatusText.Text = detail;
                    return;
                }
            }
            PurchaseStatusText.Text = "پرداخت هنوز در حال بررسی است؛ پس از بازگشت از درگاه، «بروزرسانی حساب» را بزنید.";
        }
        catch (OperationCanceledException) when (_lifetimeCts.IsCancellationRequested) { }
        catch (Exception ex)
        {
            PurchaseStatusText.Text = $"خرید انجام نشد: {FriendlyUiError(ex.Message)}";
        }
        finally { _purchaseOperationRunning = false; }
    }

    private async void RefreshSupport_Click(object sender, RoutedEventArgs e)
    {
        var selected = SupportConversationBox.SelectedItem as SupportConversation;
        await LoadSupportAsync(selected?.Id);
    }

    private async Task LoadSupportAsync(int? selectConversationId)
    {
        if (_supportOperationRunning || !_api.IsAuthenticated) return;
        _supportOperationRunning = true;
        SupportStatusText.Text = "در حال دریافت پشتیبانی…";
        try
        {
            var departmentsTask = _api.GetSupportDepartmentsAsync(_lifetimeCts.Token);
            var conversationsTask = _api.GetSupportConversationsAsync(_lifetimeCts.Token);
            await Task.WhenAll(departmentsTask, conversationsTask);
            var departments = departmentsTask.Result.Departments;
            var conversations = conversationsTask.Result.Conversations;
            SupportDepartmentBox.ItemsSource = departments;
            if (SupportDepartmentBox.SelectedIndex < 0 && departments.Count > 0) SupportDepartmentBox.SelectedIndex = 0;

            _supportSelectionChanging = true;
            SupportConversationBox.ItemsSource = conversations;
            SupportConversationBox.SelectedItem = conversations.FirstOrDefault(x => x.Id == selectConversationId) ?? conversations.FirstOrDefault();
            _supportSelectionChanging = false;
            SupportMenuButton.Content = conversations.Sum(x => x.Unread) > 0 ? $"پشتیبانی ({conversations.Sum(x => x.Unread)})" : "پشتیبانی";
            if (SupportConversationBox.SelectedItem is SupportConversation selected)
                await LoadSupportMessagesAsync(selected.Id);
            else
            {
                SupportMessagesList.ItemsSource = null;
                SupportStatusText.Text = "هنوز گفتگویی ندارید؛ یک درخواست جدید ایجاد کنید.";
            }
        }
        catch (OperationCanceledException) when (_lifetimeCts.IsCancellationRequested) { }
        catch (Exception ex) { SupportStatusText.Text = FriendlyUiError(ex.Message); }
        finally
        {
            _supportSelectionChanging = false;
            _supportOperationRunning = false;
        }
    }

    private async void SupportConversation_SelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        if (_supportSelectionChanging || SupportConversationBox.SelectedItem is not SupportConversation conv) return;
        await LoadSupportMessagesAsync(conv.Id);
    }

    private async Task LoadSupportMessagesAsync(int conversationId)
    {
        try
        {
            var result = await _api.GetSupportMessagesAsync(conversationId, _lifetimeCts.Token);
            SupportMessagesList.ItemsSource = result.Messages;
            SupportStatusText.Text = $"{result.Conversation.StatusLabel} • {result.Conversation.Department?.Name ?? "پشتیبانی"}";
            SupportReplyBox.IsEnabled = !string.Equals(result.Conversation.Status, "closed", StringComparison.OrdinalIgnoreCase);
        }
        catch (Exception ex) { SupportStatusText.Text = FriendlyUiError(ex.Message); }
    }

    private async void CreateSupport_Click(object sender, RoutedEventArgs e)
    {
        if (_supportOperationRunning || SupportDepartmentBox.SelectedItem is not SupportDepartment dept) return;
        var message = SupportNewMessageBox.Text.Trim();
        if (message.Length < 3)
        {
            SupportStatusText.Text = "شرح درخواست را وارد کنید.";
            return;
        }
        _supportOperationRunning = true;
        try
        {
            var created = await _api.CreateSupportConversationAsync(dept.Id, SupportSubjectBox.Text.Trim(), message, _lifetimeCts.Token);
            SupportSubjectBox.Clear();
            SupportNewMessageBox.Clear();
            SupportStatusText.Text = "درخواست پشتیبانی ثبت شد.";
            _supportOperationRunning = false;
            await LoadSupportAsync(created.Conversation.Id);
        }
        catch (Exception ex)
        {
            SupportStatusText.Text = FriendlyUiError(ex.Message);
            _supportOperationRunning = false;
        }
    }

    private async void SendSupport_Click(object sender, RoutedEventArgs e)
    {
        if (_supportOperationRunning || SupportConversationBox.SelectedItem is not SupportConversation conv) return;
        var message = SupportReplyBox.Text.Trim();
        if (message.Length == 0) return;
        _supportOperationRunning = true;
        try
        {
            await _api.SendSupportMessageAsync(conv.Id, message, _lifetimeCts.Token);
            SupportReplyBox.Clear();
            SupportStatusText.Text = "پیام ارسال شد.";
            await LoadSupportMessagesAsync(conv.Id);
        }
        catch (Exception ex) { SupportStatusText.Text = FriendlyUiError(ex.Message); }
        finally { _supportOperationRunning = false; }
    }

    private async void CloseSupportConversation_Click(object sender, RoutedEventArgs e)
    {
        if (_supportOperationRunning || SupportConversationBox.SelectedItem is not SupportConversation conv) return;
        _supportOperationRunning = true;
        try
        {
            await _api.CloseSupportConversationAsync(conv.Id, _lifetimeCts.Token);
            SupportStatusText.Text = "گفتگو بسته شد.";
            _supportOperationRunning = false;
            await LoadSupportAsync(conv.Id);
        }
        catch (Exception ex)
        {
            SupportStatusText.Text = FriendlyUiError(ex.Message);
            _supportOperationRunning = false;
        }
    }

    private void AuthSmsMode_Click(object sender, RoutedEventArgs e)
    {
        _authMode = "sms";
        ApplyAuthModeUi();
    }

    private void AuthEmailMode_Click(object sender, RoutedEventArgs e)
    {
        _authMode = "email";
        ApplyAuthModeUi();
    }

    private void EmailLoginMode_Click(object sender, RoutedEventArgs e)
    {
        _emailRegisterMode = false;
        ApplyAuthModeUi();
    }

    private void EmailRegisterMode_Click(object sender, RoutedEventArgs e)
    {
        _emailRegisterMode = true;
        ApplyAuthModeUi();
    }

    private void ChangePhone_Click(object sender, RoutedEventArgs e)
    {
        _otpPhone = "";
        OtpBox.Text = "";
        SmsPhoneStage.Visibility = Visibility.Visible;
        SmsOtpStage.Visibility = Visibility.Collapsed;
        AuthStatusText.Text = "شماره موبایل را وارد کنید تا کد ورود ارسال شود.";
    }

    private async void ResendOtp_Click(object sender, RoutedEventArgs e) => await RequestOtpCoreAsync();

    private async void EmailSubmit_Click(object sender, RoutedEventArgs e)
    {
        if (_emailRegisterMode) await RegisterCoreAsync();
        else await LoginCoreAsync();
    }

    // Backward-compatible handlers retained for older XAML/release patches.
    private async void Login_Click(object sender, RoutedEventArgs e) => await LoginCoreAsync();
    private async void Register_Click(object sender, RoutedEventArgs e) => await RegisterCoreAsync();

    private Task LoginCoreAsync() => BusyAsync("در حال ورود…", async ct =>
    {
        var email = EmailBox.Text.Trim();
        var password = PasswordBox.Password;
        if (!LooksLikeEmail(email)) throw new InvalidOperationException("ایمیل معتبر وارد کنید.");
        if (password.Length < 8) throw new InvalidOperationException("رمز عبور باید حداقل ۸ کاراکتر باشد.");
        AuthStatusText.Text = "در حال ورود به حساب…";
        var result = await _api.LoginAsync(email, password, ct);
        _account = result.Account ?? await _api.GetAccountAsync(ct);
        PasswordBox.Password = "";
        ApplyAccount();
        await RefreshPlansSafeAsync();
        if (_settings.AutoUpdate) _ = CheckAppUpdateSafeAsync(silentWhenCurrent: true, userInitiated: false);
    });

    private Task RegisterCoreAsync() => BusyAsync("در حال ساخت حساب…", async ct =>
    {
        var email = EmailBox.Text.Trim();
        var password = PasswordBox.Password;
        if (!LooksLikeEmail(email)) throw new InvalidOperationException("ایمیل معتبر وارد کنید.");
        if (password.Length < 8) throw new InvalidOperationException("رمز عبور باید حداقل ۸ کاراکتر باشد.");
        AuthStatusText.Text = "در حال ساخت حساب…";
        var result = await _api.RegisterAsync(email, password, ct);
        _account = result.Account ?? await _api.GetAccountAsync(ct);
        PasswordBox.Password = "";
        ApplyAccount();
        await RefreshPlansSafeAsync();
        if (_settings.AutoUpdate) _ = CheckAppUpdateSafeAsync(silentWhenCurrent: true, userInitiated: false);
    });

    private async void RequestOtp_Click(object sender, RoutedEventArgs e) => await RequestOtpCoreAsync();

    private Task RequestOtpCoreAsync() => BusyAsync("ارسال کد پیامک…", async ct =>
    {
        var phone = NormalizePhone(PhoneBox.Text);
        if (phone.Length < 10) throw new InvalidOperationException("شماره موبایل معتبر وارد کنید.");
        PhoneBox.Text = phone;
        AuthStatusText.Text = "در حال ارسال کد تأیید…";
        await _api.RequestOtpAsync(phone, ct);
        _otpPhone = phone;
        OtpTargetText.Text = $"کد ۶ رقمی ارسال‌شده به {PrettyPhone(phone)} را وارد کنید.";
        SmsPhoneStage.Visibility = Visibility.Collapsed;
        SmsOtpStage.Visibility = Visibility.Visible;
        AuthStatusText.Text = "کد تأیید ارسال شد؛ منتظر پیامک باشید.";
        OtpBox.Focus();
        FooterStatus.Text = "کد پیامک ارسال شد.";
    });

    private async void VerifyOtp_Click(object sender, RoutedEventArgs e) => await BusyAsync("تأیید کد…", async ct =>
    {
        var phone = string.IsNullOrWhiteSpace(_otpPhone) ? NormalizePhone(PhoneBox.Text) : _otpPhone;
        var code = new string(OtpBox.Text.Where(char.IsDigit).Take(6).ToArray());
        if (code.Length != 6) throw new InvalidOperationException("کد تأیید باید ۶ رقمی باشد.");
        AuthStatusText.Text = "در حال تأیید کد…";
        var result = await _api.VerifyOtpAsync(phone, code, ct);
        _account = result.Account ?? await _api.GetAccountAsync(ct);
        OtpBox.Text = "";
        _otpPhone = "";
        ApplyAccount();
        await RefreshPlansSafeAsync();
        if (_settings.AutoUpdate) _ = CheckAppUpdateSafeAsync(silentWhenCurrent: true, userInitiated: false);
    });

    private void ApplyAuthModeUi()
    {
        var sms = _authMode == "sms";
        SmsAuthPanel.Visibility = sms ? Visibility.Visible : Visibility.Collapsed;
        EmailAuthPanel.Visibility = sms ? Visibility.Collapsed : Visibility.Visible;
        AuthHintText.Text = sms ? "ورود امن با کد یک‌بارمصرف ۶ رقمی" : "ورود یا ثبت‌نام با ایمیل";
        SetSegmentVisual(SmsModeButton, sms);
        SetSegmentVisual(EmailModeButton, !sms);
        SetSegmentVisual(EmailLoginModeButton, !_emailRegisterMode);
        SetSegmentVisual(EmailRegisterModeButton, _emailRegisterMode);
        EmailTitleText.Text = _emailRegisterMode ? "ساخت حساب کاربری" : "ورود با ایمیل";
        EmailSubtitleText.Text = _emailRegisterMode
            ? "ایمیل و یک رمز عبور حداقل ۸ کاراکتری تعیین کنید."
            : "ایمیل و رمز عبور حساب BlueVPN خود را وارد کنید.";
        EmailSubmitButton.Content = _emailRegisterMode ? "ثبت‌نام و ورود" : "ورود به BlueVPN";
    }

    private static void SetSegmentVisual(System.Windows.Controls.Button button, bool selected)
    {
        Brush R(string key) => Application.Current.Resources[key] as Brush ?? Brushes.Transparent;
        button.Background = R(selected ? "BlueVpnSurfaceStrong" : "BlueVpnBg");
        button.Foreground = R(selected ? "BlueVpnBlue2" : "BlueVpnMuted");
        button.BorderBrush = R(selected ? "BlueVpnBlue2" : "BlueVpnStroke");
    }

    private static bool LooksLikeEmail(string value)
    {
        var at = value.IndexOf('@');
        return at > 0 && at < value.Length - 3 && value.IndexOf('.', at + 2) > at + 1;
    }

    private static string NormalizePhone(string value)
    {
        var raw = (value ?? "").Trim();
        var plus = raw.StartsWith('+');
        var digits = new string(raw.Where(char.IsDigit).ToArray());
        if (digits.StartsWith("0098")) return "+98" + digits[4..];
        if (digits.StartsWith("98") && digits.Length >= 12) return "+" + digits;
        return plus ? "+" + digits : digits;
    }

    private static string PrettyPhone(string value)
    {
        if (value.Length <= 7) return value;
        return value[..Math.Min(4, value.Length)] + "•••" + value[^4..];
    }

    private async void RefreshAccount_Click(object sender, RoutedEventArgs e) => await BusyAsync("بروزرسانی حساب…", async ct =>
    {
        _account = await _api.GetAccountAsync(ct);
        ApplyAccount();
        await RefreshPlansSafeAsync();
    });

    private async void Logout_Click(object sender, RoutedEventArgs e)
    {
        _connectCts?.Cancel();
        _connection.Disconnect();
        FooterStatus.Text = "در حال خروج از حساب…";
        try
        {
            using var logoutCts = new CancellationTokenSource(TimeSpan.FromSeconds(6));
            await _api.LogoutAsync(logoutCts.Token);
        }
        catch (Exception ex)
        {
            // Do not silently convert a failed server logout into a successful UI logout.
            // The device slot may still be occupied server-side. Keep the session so the
            // user can retry logout instead of creating a hidden DEVICE_LIMIT_REACHED loop.
            AuthStatusText.Text = $"خروج از حساب روی سرور انجام نشد: {Short(ex.Message)}";
            FooterStatus.Text = "خروج ناموفق بود؛ اتصال شبکه را بررسی کنید و دوباره تلاش کنید.";
            return;
        }
        _account = null;
        LoginPanel.Visibility = Visibility.Visible;
        AccountPanel.Visibility = Visibility.Collapsed;
        PlansList.ItemsSource = null;
        SupportMessagesList.ItemsSource = null;
        SupportConversationBox.ItemsSource = null;
        SupportDepartmentBox.ItemsSource = null;
        SupportMenuButton.Content = "پشتیبانی";
        PurchaseStatusText.Text = "برای خرید، وارد حساب شوید و پلن موردنظر را انتخاب کنید.";
        _remainingSecondsAtSnapshot = null;
        _authMode = "sms";
        _emailRegisterMode = false;
        _otpPhone = "";
        SmsPhoneStage.Visibility = Visibility.Visible;
        SmsOtpStage.Visibility = Visibility.Collapsed;
        ApplyAuthModeUi();
        AuthStatusText.Text = "از حساب خارج شدید؛ می‌توانید دوباره وارد شوید.";
        SetDisconnectedUi();
        TierText.Text = "رایگان";
        FooterStatus.Text = "از حساب خارج شدید.";
    }

    private async void LocationCard_Click(object sender, MouseButtonEventArgs e)
    {
        if (_connectionOperationRunning)
        {
            FooterStatus.Text = "تا پایان عملیات اتصال، تغییر لوکیشن ممکن نیست.";
            return;
        }
        if (_connection.IsConnected)
        {
            MessageBox.Show("برای تغییر لوکیشن ابتدا اتصال را قطع کنید.", "BlueVPN", MessageBoxButton.OK, MessageBoxImage.Information);
            return;
        }

        try
        {
            FooterStatus.Text = "در حال آماده‌سازی لیست لوکیشن‌ها…";
            var raw = _account?.Subscription.Active == true && !string.IsNullOrWhiteSpace(_account.Subscription.Url)
                ? await _api.GetPremiumSubscriptionAsync(_account, _lifetimeCts.Token)
                : await _api.GetFreeSubscriptionAsync(
                    (await _api.GetMobileConfigAsync(_lifetimeCts.Token)).FreeAccess,
                    _lifetimeCts.Token);
            var endpoints = SubscriptionParser.Parse(raw);
            var locations = LocationCatalog.Available(endpoints);

            var pickerHeight = Math.Min(SystemParameters.WorkArea.Height * 0.78, Math.Clamp(190 + ((locations.Count + 1) * 58), 320, 610));
            var picker = new Window
            {
                Owner = this,
                Title = "انتخاب لوکیشن BlueVPN",
                Width = 440,
                Height = pickerHeight,
                MinHeight = 300,
                WindowStartupLocation = WindowStartupLocation.CenterOwner,
                ResizeMode = ResizeMode.CanResize,
                Background = (Brush)FindResource("BlueVpnBg"),
                FlowDirection = FlowDirection.RightToLeft,
                ShowInTaskbar = false
            };

            var root = new DockPanel { Margin = new Thickness(18), Background = (Brush)FindResource("BlueVpnBg") };
            var title = new TextBlock
            {
                Text = "لوکیشن موردنظر را انتخاب کنید",
                FontSize = 22,
                FontWeight = FontWeights.Bold,
                Foreground = (Brush)FindResource("BlueVpnText"),
                Margin = new Thickness(0, 0, 0, 14)
            };
            DockPanel.SetDock(title, Dock.Top);
            root.Children.Add(title);

            var panel = new StackPanel();
            var autoButton = new Button
            {
                Content = "انتخاب خودکار  •  پیشنهادی",
                Height = 52,
                Margin = new Thickness(0, 0, 0, 10),
                Background = (Brush)FindResource("BlueVpnBlue"),
                Foreground = Brushes.White,
                BorderBrush = (Brush)FindResource("BlueVpnBlue2"),
                FontWeight = FontWeights.SemiBold,
                FontSize = 14,
                HorizontalContentAlignment = HorizontalAlignment.Right
            };
            autoButton.Click += (_, _) =>
            {
                _preferredLocationKey = "";
                _preferredLocationLabel = "انتخاب خودکار";
                picker.DialogResult = true;
                picker.Close();
            };
            panel.Children.Add(autoButton);

            foreach (var location in locations)
            {
                var button = new Button
                {
                    Content = $"{location.Title}    {location.Key.ToUpperInvariant()}",
                    Height = 50,
                    Margin = new Thickness(0, 0, 0, 8),
                    Tag = location,
                    Background = (Brush)FindResource("BlueVpnSurface"),
                    Foreground = (Brush)FindResource("BlueVpnText"),
                    BorderBrush = (Brush)FindResource("BlueVpnStroke"),
                    BorderThickness = new Thickness(1.2),
                    FontWeight = FontWeights.SemiBold,
                    FontSize = 14,
                    HorizontalContentAlignment = HorizontalAlignment.Right
                };
                button.Click += (_, _) =>
                {
                    _preferredLocationKey = location.Key;
                    _preferredLocationLabel = location.Display;
                    picker.DialogResult = true;
                    picker.Close();
                };
                panel.Children.Add(button);
            }

            if (locations.Count == 0)
            {
                panel.Children.Add(new TextBlock
                {
                    Text = "لوکیشن مشخصی از مسیرهای فعلی شناسایی نشد؛ انتخاب خودکار همچنان قابل استفاده است.",
                    TextWrapping = TextWrapping.Wrap,
                    Margin = new Thickness(4, 8, 4, 4),
                    Foreground = (Brush)FindResource("BlueVpnMuted")
                });
            }

            var scroll = new ScrollViewer
            {
                VerticalScrollBarVisibility = ScrollBarVisibility.Auto,
                HorizontalScrollBarVisibility = ScrollBarVisibility.Disabled,
                Content = panel
            };
            root.Children.Add(scroll);
            picker.Content = root;
            picker.ShowDialog();

            EndpointText.Text = _preferredLocationLabel;
            EngineText.Text = string.IsNullOrWhiteSpace(_preferredLocationKey)
                ? "بهترین مسیر همان لوکیشن به‌صورت خودکار انتخاب می‌شود"
                : "بهترین مسیر مخفی این لوکیشن به‌صورت خودکار انتخاب می‌شود";
            FooterStatus.Text = string.IsNullOrWhiteSpace(_preferredLocationKey)
                ? "انتخاب خودکار فعال شد."
                : $"لوکیشن {_preferredLocationLabel} انتخاب شد.";
        }
        catch (OperationCanceledException) { }
        catch (Exception ex)
        {
            FooterStatus.Text = FriendlyUiError(ex.Message);
        }
    }

    private async void Connect_Click(object sender, RoutedEventArgs e)
    {
        if (_connectionOperationRunning)
        {
            _connectCts?.Cancel();
            ConnectingStageText.Text = "در حال لغو اتصال…";
            FooterStatus.Text = "در حال لغو اتصال…";
            return;
        }

        if (_connection.IsConnected)
        {
            _connectCts?.Cancel();
            _connection.Disconnect();
            SetDisconnectedUi();
            FooterStatus.Text = "اتصال قطع شد.";
            _ = InstallPendingUpdateAfterDisconnectAsync();
            return;
        }

        _connectionOperationRunning = true;
        _connectCts?.Cancel();
        _connectCts?.Dispose();
        _connectCts = CancellationTokenSource.CreateLinkedTokenSource(_lifetimeCts.Token);
        var progress = new Progress<string>(message =>
        {
            ConnectionStatusText.Text = message;
            ConnectingStageText.Text = message;
            FooterStatus.Text = message;
        });
        SetConnectingUi();

        try
        {
            var result = await _connection.ConnectAsync(_account, progress, _connectCts.Token, _preferredLocationKey);
            _connectedAt = DateTimeOffset.UtcNow;
            ConnectingOverlay.Visibility = Visibility.Collapsed;
            StatusText.Text = "متصل هستید";
            OrbText.Text = "قطع اتصال";
            StatusDot.Fill = (Brush)FindResource("BlueVpnGreen");
            StatusOrb.Background = (Brush)FindResource("BlueVpnBlue");
            StatusOrb.BorderBrush = (Brush)FindResource("BlueVpnBlue2");
            OrbHalo.Background = (Brush)FindResource("BlueVpnSurfaceSoft");
            var compatibilityProxy = result.Verification.AdapterName.Equals("Windows System Proxy", StringComparison.OrdinalIgnoreCase);
            ConnectionStatusText.Text = compatibilityProxy
                ? "مسیر سازگار ویندوز برقرار شد"
                : result.Premium ? "اتصال ویژه برقرار شد" : (result.Engine == "WARP" ? "اتصال رایگان WARP برقرار شد" : "اتصال رایگان برقرار شد");
            EndpointText.Text = result.Premium
                ? PublicRouteLabel("ویژه", result.Verification.Country)
                : PublicRouteLabel("رایگان", result.Verification.Country);
            EngineText.Text = compatibilityProxy
                ? "متصل • Windows System Proxy روی BlueVPN Core"
                : "متصل • مسیر فعال در پس‌زمینه مدیریت می‌شود";
            ServerStatusText.Text = compatibilityProxy
                ? $"اتصال سازگار تأیید شد • {result.Engine}"
                : $"اتصال سراسری تأیید شد • {result.Engine}";
            TierText.Text = result.Premium ? "Premium" : "Free";
            SetPowerIconState(true);
            IpValue.Text = result.Verification.PublicIp.Length > 0 ? result.Verification.PublicIp : "—";
            PingValue.Text = FormatLatency(result.Endpoint.ProbeLatencyMs);
            LocationBadge.Text = result.Verification.Country.Length > 0 ? result.Verification.Country : "VPN";
            TechnicalText.Text = compatibilityProxy
                ? $"Windows System Proxy تأیید شد • {_connection.AiStatus}"
                : $"VPN سراسری تأیید شد • {_connection.AiStatus}";
            MenuTechnicalText.Text = TechnicalText.Text;
            MenuIpText.Text = $"IP: {IpValue.Text}";
            FooterStatus.Text = compatibilityProxy
                ? "IP خروجی BlueVPN تأیید شد؛ این دستگاه از مسیر سازگار Windows Proxy استفاده می‌کند."
                : "IP و مسیر سیستم از داخل BlueVPN تأیید شد.";
            if (!result.Premium) ShowFreeStoryAdSafe();
        }
        catch (OperationCanceledException)
        {
            _connection.Disconnect();
            SetDisconnectedUi();
            FooterStatus.Text = "اتصال لغو شد.";
        }
        catch (Exception ex)
        {
            _connection.Disconnect();
            SetDisconnectedUi();
            var message = FriendlyUiError(ex.Message);
            StatusText.Text = "اتصال برقرار نشد";
            StatusDot.Fill = (Brush)FindResource("BlueVpnRed");
            ConnectionStatusText.Text = message;
            FooterStatus.Text = message;
        }
        finally
        {
            _connectionOperationRunning = false;
            ConnectButton.IsEnabled = true;
            if (!_connection.IsConnected) ConnectingOverlay.Visibility = Visibility.Collapsed;
        }
    }

    private void CancelConnection_Click(object sender, RoutedEventArgs e)
    {
        _connectCts?.Cancel();
        ConnectingStageText.Text = "در حال لغو اتصال…";
    }

    private void ShowFreeStoryAdSafe()
    {
        try
        {
            var item = _ads.PickFreeStory();
            if (item is null) return;
            var window = new StoryAdWindow(item, _ads.StoryDurationSeconds(item), _ads.StoryLoadTimeoutMs, _ads.StoryMaxVideoSeconds) { Owner = this };
            window.Show(); // fail-open: ad never owns or blocks the VPN lifecycle
        }
        catch { }
    }

    private async void Update_Click(object sender, RoutedEventArgs e) =>
        await CheckAppUpdateSafeAsync(silentWhenCurrent: false, userInitiated: true);

    private async Task CheckAppUpdateSafeAsync(bool silentWhenCurrent, bool userInitiated)
    {
        var acquired = false;
        if (userInitiated)
        {
            UpdateButton.Content = "در حال بروزرسانی…";
            UpdateStatusText.Text = "یک عملیات بروزرسانی در حال اجراست؛ وضعیت آن همین‌جا نمایش داده می‌شود.";
            await _updateGate.WaitAsync(_lifetimeCts.Token);
            acquired = true;
        }
        else
        {
            acquired = await _updateGate.WaitAsync(0);
            if (!acquired) return;
        }
        try
        {
            ReportUpdateStatus("بررسی بروزرسانی BlueVPN…");
            var candidate = await _appUpdater.CheckAsync(_lifetimeCts.Token);
            if (candidate is null)
            {
                UpdateButton.Content = "بررسی بروزرسانی";
                UpdateProgressBar.Visibility = Visibility.Collapsed;
                UpdateProgressBar.Value = 0;
                ReportUpdateStatus("BlueVPN به‌روز است.");
                if (!silentWhenCurrent) MessageBox.Show("BlueVPN به‌روز است.", "BlueVPN", MessageBoxButton.OK, MessageBoxImage.Information);
                return;
            }

            UpdateButton.Content = $"بروزرسانی {candidate.Version}";
            var channel = candidate.Channel.Equals("beta", StringComparison.OrdinalIgnoreCase) ? "Beta" : "Stable";

            if (candidate.ForceUpdate)
            {
                await DownloadAndInstallUpdateAsync(candidate, forced: true);
                return;
            }

            if (userInitiated)
            {
                var message = string.IsNullOrWhiteSpace(candidate.Message)
                    ? $"نسخه {candidate.Version} ({channel}) آماده است. دریافت و نصب شود؟"
                    : $"نسخه {candidate.Version} ({channel})\n\n{candidate.Message}\n\nدریافت و نصب شود؟";
                if (MessageBox.Show(message, "BlueVPN Update", MessageBoxButton.YesNo, MessageBoxImage.Information) != MessageBoxResult.Yes)
                {
                    FooterStatus.Text = "بروزرسانی به درخواست کاربر به تعویق افتاد.";
                    return;
                }
                await DownloadAndInstallUpdateAsync(candidate, forced: false);
                return;
            }

            // Silent checks never download or prompt when the panel disabled
            // automatic delivery for this Stable/Beta channel.
            if (!candidate.AutoUpdate)
            {
                ReportUpdateStatus($"نسخه {candidate.Version} موجود است؛ نصب خودکار این کانال خاموش است.");
                return;
            }

            if (_connectionOperationRunning || _connection.IsConnected)
            {
                _pendingUpdate = candidate;
                ReportUpdateStatus($"نسخه {candidate.Version} آماده است و پس از پایان اتصال نصب می‌شود.");
                return;
            }

            await DownloadAndInstallUpdateAsync(candidate, forced: false);
        }
        catch (OperationCanceledException) { }
        catch (InsufficientUpdateSpaceException ex)
        {
            FooterStatus.Text = "فضای کافی برای بروزرسانی وجود ندارد";
            UpdateButton.Content = "فضا آزاد کن و دوباره بزن";
            if (!silentWhenCurrent || userInitiated)
                MessageBox.Show(ex.Message, "بروزرسانی BlueVPN", MessageBoxButton.OK, MessageBoxImage.Warning);
        }
        catch (Exception ex)
        {
            FooterStatus.Text = "بررسی بروزرسانی انجام نشد";
            if (!silentWhenCurrent || userInitiated)
                MessageBox.Show(ex.Message, "بروزرسانی BlueVPN", MessageBoxButton.OK, MessageBoxImage.Warning);
        }
        finally { if (acquired) _updateGate.Release(); }
    }

    private async Task DownloadAndInstallUpdateAsync(UpdateCandidate candidate, bool forced)
    {
        ReportUpdateStatus($"دریافت نسخه {candidate.Version}…", 0);
        var progress = new Progress<double>(value =>
        {
            var percent = Math.Clamp((int)Math.Round(value * 100d), 0, 100);
            ReportUpdateStatus($"دریافت نسخه {candidate.Version}… {percent}%", percent);
        });
        var installer = await _appUpdater.DownloadAsync(candidate, progress, _lifetimeCts.Token);
        // A background update check may finish after the user has already pressed Connect.
        // Never terminate the app or tear down a live/in-flight tunnel in that race.
        if (_connectionOperationRunning || _connection.IsConnected)
        {
            _pendingUpdate = candidate;
            ReportUpdateStatus($"نسخه {candidate.Version} دریافت شد و پس از قطع اتصال نصب می‌شود.", 100);
            return;
        }
        ReportUpdateStatus("بروزرسانی تأیید شد؛ در حال اجرای نصب…", 100);
        _pendingUpdate = null;
        _connectCts?.Cancel();
        _connection.Disconnect();
        if (AppUpdateService.LaunchInstaller(installer, candidate.Digest))
        {
            Application.Current.Shutdown();
            return;
        }
        if (forced) throw new InvalidOperationException("Installer بروزرسانی اجرا نشد.");
    }

    private async Task InstallPendingUpdateAfterDisconnectAsync()
    {
        var pending = _pendingUpdate;
        if (pending is null) return;
        if (!await _updateGate.WaitAsync(0)) return;
        try { await DownloadAndInstallUpdateAsync(pending, forced: pending.ForceUpdate); }
        catch (Exception ex) { FooterStatus.Text = $"نصب بروزرسانی انجام نشد: {ex.Message}"; }
        finally { _updateGate.Release(); }
    }

    private async Task CheckRuntimeUpdateSafeAsync()
    {
        try
        {
            var version = await _runtimeUpdater.CheckAndUpdateAsync(_connection.IsConnected, _lifetimeCts.Token);
            if (version.Length > 0)
            {
                CoreVersionText.Text = "BlueVPN Core";
                TechnicalText.Text = "هسته اتصال بروزرسانی شد؛ در اتصال بعدی استفاده می‌شود.";
            }
        }
        catch { }
    }

    private void ApplyAccount()
    {
        if (_account is null) return;
        LoginPanel.Visibility = Visibility.Collapsed;
        AccountPanel.Visibility = Visibility.Visible;
        IdentityText.Text = string.IsNullOrWhiteSpace(_account.DisplayIdentity) ? "حساب BlueVPN" : _account.DisplayIdentity;
        PlanText.Text = _account.Subscription.Active ? $"پلن: {(_account.PlanTitle.Length > 0 ? _account.PlanTitle : "ویژه")}" : "اشتراک ویژه فعال نیست";
        ExpiryText.Text = _account.Subscription.Active ? $"اعتبار تا: {_account.Subscription.ExpireFa}" : "اتصال رایگان در دسترس است";
        TrafficText.Text = FormatTraffic(_account.Subscription);
        TierText.Text = _account.Subscription.Active ? "Premium" : "Free";
        SubscriptionSummaryText.Text = _account.Subscription.Active
            ? $"فعال • {(_account.PlanTitle.Length > 0 ? _account.PlanTitle : "Premium")}"
            : "اتصال رایگان BlueVPN";
        RemainingVolumeValue.Text = FormatRemainingVolume(_account.Subscription);
        _remainingSecondsAtSnapshot = _account.Subscription.RemainingSeconds;
        _accountSnapshotAt = DateTimeOffset.UtcNow;
        RemainingTimeValue.Text = FormatRemainingTime(_account.Subscription);
        PurchaseStatusText.Text = _account.Subscription.Active
            ? "اشتراک ویژه فعال است؛ برای تمدید می‌توانید یکی از پلن‌ها را خریداری کنید."
            : "پلن موردنظر را انتخاب کنید؛ پرداخت امن در مرورگر باز می‌شود و فعال‌سازی خودکار بررسی خواهد شد.";
    }

    private async Task RestoreAccountSessionSafeAsync()
    {
        if (!_api.IsAuthenticated)
        {
            PlansList.ItemsSource = null;
            return;
        }

        try
        {
            var fresh = await _api.GetAccountAsync(_lifetimeCts.Token);
            _account = fresh;
            ApplyAccount();
            await RefreshPlansSafeAsync();
            AuthStatusText.Text = "حساب BlueVPN آماده است.";
        }
        catch (OperationCanceledException) when (_lifetimeCts.IsCancellationRequested) { }
        catch (Exception ex)
        {
            // Network loss on startup must not erase a valid remembered login.
            // Keep the encrypted cached snapshot visible and retry on the next refresh.
            if (_account is not null)
            {
                ApplyAccount();
                FooterStatus.Text = "حساب ذخیره‌شده فعال است؛ بروزرسانی آنلاین اطلاعات بعداً دوباره انجام می‌شود.";
            }
            else
            {
                AuthStatusText.Text = FriendlyUiError(ex.Message);
                FooterStatus.Text = "نشست ورود ذخیره شده است؛ برای دریافت اطلاعات حساب به اینترنت متصل شوید.";
            }
        }
    }

    private async Task RefreshPlansSafeAsync()
    {
        try { PlansList.ItemsSource = await _api.GetPlansAsync(_lifetimeCts.Token); }
        catch { PlansList.ItemsSource = null; }
    }

    private async Task RefreshPublicIpAsync()
    {
        try
        {
            var snapshot = await ConnectivityProbe.SnapshotAsync(_settings.ProbeUrl, _lifetimeCts.Token);
            if (!_connection.IsConnected && snapshot.Reachable)
            {
                IpValue.Text = snapshot.PublicIp;
                MenuIpText.Text = $"IP: {snapshot.PublicIp}";
            }
        }
        catch { }
    }

    private async Task BusyAsync(string status, Func<CancellationToken, Task> action)
    {
        if (_accountOperationRunning) return;
        _accountOperationRunning = true;
        FooterStatus.Text = status;
        try
        {
            await action(_lifetimeCts.Token);
            FooterStatus.Text = "آماده";
        }
        catch (OperationCanceledException) { }
        catch (Exception ex)
        {
            var message = FriendlyUiError(ex.Message);
            AuthStatusText.Text = message;
            FooterStatus.Text = message;
        }
        finally { _accountOperationRunning = false; }
    }

    private static string FriendlyUiError(string message)
    {
        if (string.IsNullOrWhiteSpace(message)) return "عملیات انجام نشد؛ دوباره تلاش کنید.";
        if (message.Contains("TUN", StringComparison.OrdinalIgnoreCase) ||
            message.Contains("تونل", StringComparison.OrdinalIgnoreCase) ||
            message.Contains("IP سیستم تغییر نکرد", StringComparison.OrdinalIgnoreCase) ||
            message.Contains("مسیر پیش‌فرض", StringComparison.OrdinalIgnoreCase) ||
            message.Contains("Administrator", StringComparison.OrdinalIgnoreCase) ||
            message.Contains("WARP", StringComparison.OrdinalIgnoreCase))
            return "مسیر اتصال این سرور کامل نشد؛ BlueVPN مسیرهای جایگزین را بررسی کرد. دوباره تلاش کنید.";
        if (message.Contains("HttpClient.Timeout", StringComparison.OrdinalIgnoreCase) ||
            message.Contains("configured HttpClient.Timeout", StringComparison.OrdinalIgnoreCase) ||
            message.Contains("timed out", StringComparison.OrdinalIgnoreCase) ||
            message.Contains("timeout", StringComparison.OrdinalIgnoreCase))
            return "پاسخ مسیر اتصال دیر رسید. BlueVPN مسیر جایگزین را بررسی کرد؛ دوباره اتصال را امتحان کنید.";
        if (message.Contains("SSL", StringComparison.OrdinalIgnoreCase) ||
            message.Contains("TLS", StringComparison.OrdinalIgnoreCase) ||
            message.Contains("CONTROL_PLANE_TLS", StringComparison.OrdinalIgnoreCase))
            return "ارتباط امن با سرور BlueVPN برقرار نشد؛ تاریخ و ساعت ویندوز و اتصال شبکه را بررسی کنید.";
        return message.Length > 220 ? message[..220] + "…" : message;
    }


    private void ReportUpdateStatus(string text, int? percent = null)
    {
        FooterStatus.Text = text;
        UpdateStatusText.Text = text;
        if (percent is int value)
        {
            UpdateProgressBar.Visibility = Visibility.Visible;
            UpdateProgressBar.Value = Math.Clamp(value, 0, 100);
        }
    }

    private void SetPowerIconState(bool connected)
    {
        var brush = connected ? Brushes.White : Brushes.White;
        PowerArc.Stroke = brush;
        PowerStem.Stroke = brush;
        ConnectButton.ToolTip = connected ? "قطع اتصال" : "اتصال";
    }

    private void SetConnectingUi()
    {
        ConnectingOverlay.Visibility = Visibility.Visible;
        ConnectingStageText.Text = "در حال انتخاب بهترین اتصال";
        StatusText.Text = "در حال اتصال";
        OrbText.Text = "در حال اتصال…";
        StatusDot.Fill = (Brush)FindResource("BlueVpnBlue");
        StatusOrb.BorderBrush = (Brush)FindResource("BlueVpnBlue2");
        OrbHalo.Background = (Brush)FindResource("BlueVpnSurfaceSoft");
        ServerStatusText.Text = "در حال بررسی مسیر و IP سیستم…";
    }

    private void SetDisconnectedUi()
    {
        _connectedAt = null;
        ConnectingOverlay.Visibility = Visibility.Collapsed;
        StatusText.Text = "آماده اتصال";
        OrbText.Text = "برای اتصال لمس کنید";
        StatusDot.Fill = (Brush)FindResource("BlueVpnMuted");
        StatusOrb.Background = (Brush)FindResource("BlueVpnBlue");
        StatusOrb.BorderBrush = (Brush)FindResource("BlueVpnBlue2");
        OrbHalo.Background = (Brush)FindResource("BlueVpnSurfaceSoft");
        ConnectionStatusText.Text = "بهترین اتصال به‌صورت خودکار انتخاب می‌شود";
        EndpointText.Text = _preferredLocationLabel;
        EngineText.Text = string.IsNullOrWhiteSpace(_preferredLocationKey)
            ? "بهترین مسیر همان لوکیشن به‌صورت خودکار انتخاب می‌شود"
            : "بهترین مسیر مخفی این لوکیشن به‌صورت خودکار انتخاب می‌شود";
        ServerStatusText.Text = "آماده اتصال";
        SetPowerIconState(false);
        ConnectButton.IsEnabled = true;
        PingValue.Text = "—";
        DurationValue.Text = "00:00:00";
        SpeedValue.Text = "0 KB/s";
        UploadSpeedValue.Text = "0 KB/s";
        DownloadSpeedValue.Text = "0 KB/s";
        LocationBadge.Text = "AUTO";
        _lastReceivedBytes = 0;
        _lastSentBytes = 0;
        _lastByteSample = DateTimeOffset.UtcNow;
        TechnicalText.Text = $"{_connection.RuntimeStatus} • {_connection.AiStatus}";
        MenuTechnicalText.Text = TechnicalText.Text;
        _ = RefreshPublicIpAsync();
    }

    private async Task RunMetricsLoopAsync(CancellationToken ct)
    {
        using var timer = new PeriodicTimer(TimeSpan.FromSeconds(1));
        try
        {
            while (await timer.WaitForNextTickAsync(ct).ConfigureAwait(false))
            {
                if (!_connection.IsConnected)
                {
                    await Dispatcher.InvokeAsync(UpdateRemainingTimeOnly, DispatcherPriority.Background);
                    continue;
                }

                var sample = await Task.Run(NetworkBytes, ct).ConfigureAwait(false);
                var now = DateTimeOffset.UtcNow;
                var elapsed = Math.Max(0.5, (now - _lastByteSample).TotalSeconds);
                var down = _lastReceivedBytes > 0 && sample.Received >= _lastReceivedBytes ? (sample.Received - _lastReceivedBytes) / elapsed : 0;
                var up = _lastSentBytes > 0 && sample.Sent >= _lastSentBytes ? (sample.Sent - _lastSentBytes) / elapsed : 0;
                _lastReceivedBytes = sample.Received;
                _lastSentBytes = sample.Sent;
                _lastByteSample = now;

                await Dispatcher.InvokeAsync(() =>
                {
                    if (_connectedAt is not null) DurationValue.Text = (DateTimeOffset.UtcNow - _connectedAt.Value).ToString(@"hh\:mm\:ss");
                    var downText = FormatRate(down);
                    var upText = FormatRate(up);
                    if (DownloadSpeedValue.Text != downText) DownloadSpeedValue.Text = downText;
                    if (UploadSpeedValue.Text != upText) UploadSpeedValue.Text = upText;
                    SpeedValue.Text = downText;
                    MenuTechnicalText.Text = $"{_connection.RuntimeStatus} • {_connection.AiStatus}";
                    UpdateRemainingTimeOnly();
                }, DispatcherPriority.Background);
            }
        }
        catch (OperationCanceledException) { }
    }

    private void UpdateRemainingTimeOnly()
    {
        if (_remainingSecondsAtSnapshot is not long baseSeconds || baseSeconds < 0 || _account?.Subscription.Active != true) return;
        var elapsed = Math.Max(0, (long)(DateTimeOffset.UtcNow - _accountSnapshotAt).TotalSeconds);
        var current = Math.Max(0, baseSeconds - elapsed);
        var value = FormatRemainingSeconds(current);
        if (RemainingTimeValue.Text != value) RemainingTimeValue.Text = value;
    }

    private static (long Received, long Sent) NetworkBytes()
    {
        long received = 0, sent = 0;
        try
        {
            var active = NetworkInterface.GetAllNetworkInterfaces()
                .Where(nic => nic.NetworkInterfaceType != NetworkInterfaceType.Loopback && nic.OperationalStatus == OperationalStatus.Up)
                .ToArray();
            var tun = active.Where(nic => ($"{nic.Name} {nic.Description}").Contains("BlueVPN", StringComparison.OrdinalIgnoreCase) ||
                                          ($"{nic.Name} {nic.Description}").Contains("sing-box", StringComparison.OrdinalIgnoreCase) ||
                                          ($"{nic.Name} {nic.Description}").Contains("Wintun", StringComparison.OrdinalIgnoreCase)).ToArray();
            var selected = tun.Length > 0 ? tun : active;
            foreach (var nic in selected)
            {
                var stats = nic.GetIPv4Statistics();
                received += stats.BytesReceived;
                sent += stats.BytesSent;
            }
        }
        catch { }
        return (received, sent);
    }

    private static string FormatRate(double rate) => rate >= 1024 * 1024
        ? $"{rate / 1024 / 1024:0.0} MB/s"
        : $"{Math.Max(0, rate) / 1024:0.0} KB/s";

    private static string FormatRemainingVolume(SubscriptionInfo info)
    {
        if (!info.Active) return "—";
        if (info.DataLimitBytes <= 0) return "نامحدود";
        var remaining = info.RemainingBytes > 0 ? info.RemainingBytes : Math.Max(0, info.DataLimitBytes - info.UsedTrafficBytes);
        return remaining >= 1024L * 1024 * 1024 ? $"{remaining / 1024d / 1024d / 1024d:0.##} GB" : $"{remaining / 1024d / 1024d:0} MB";
    }

    private static string FormatRemainingTime(SubscriptionInfo info)
    {
        if (!info.Active) return "—";
        if (info.RemainingSeconds is not long seconds || seconds < 0) return info.ExpireFa.Length > 0 ? info.ExpireFa : "—";
        return FormatRemainingSeconds(seconds);
    }

    private static string FormatRemainingSeconds(long seconds)
    {
        var days = (long)Math.Ceiling(Math.Max(0, seconds) / 86400d);
        return days <= 0 ? "کمتر از یک روز" : $"{days} روز";
    }

    private static string PublicRouteLabel(string tierLabel, string? country)
    {
        var safeTier = string.IsNullOrWhiteSpace(tierLabel) ? "اتصال" : tierLabel.Trim();
        var safeCountry = string.IsNullOrWhiteSpace(country)
            ? "اتصال هوشمند"
            : country.Trim().ToUpperInvariant();

        // Never surface imported subscription remarks or runtime endpoint names here.
        // The country value comes only from the post-TUN public-IP verification result.
        return $"BlueVPN • {safeTier} • {safeCountry}";
    }

    private static string Short(string value) => string.IsNullOrWhiteSpace(value) ? "خطای نامشخص" : (value.Length <= 120 ? value : value[..120] + "…");

    private static string FormatLatency(int ms) => ms == int.MaxValue ? "—" : $"{ms} ms";

    private static string FormatTraffic(SubscriptionInfo info)
    {
        if (info.DataLimitBytes <= 0) return "حجم: نامحدود";
        static string Gb(long bytes) => $"{bytes / 1024d / 1024d / 1024d:0.##} GB";
        return $"مصرف: {Gb(info.UsedTrafficBytes)} از {Gb(info.DataLimitBytes)}";
    }
}
