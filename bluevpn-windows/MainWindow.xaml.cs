using System.Diagnostics;
using System.Net.NetworkInformation;
using System.Windows;
using System.Windows.Input;
using System.Windows.Media;
using System.Windows.Media.Imaging;
using System.Windows.Threading;
using BlueVPN.Windows.Models;
using BlueVPN.Windows.Services;

namespace BlueVPN.Windows;

public partial class MainWindow : Window
{
    private readonly BlueVpnApiClient _api;
    private readonly ConnectionOrchestrator _connection;
    private readonly AppSettings _settings;
    private readonly AdvertisementService _ads;
    private readonly AppUpdateService _appUpdater;
    private readonly RuntimeUpdateService _runtimeUpdater;
    private Account? _account;
    private CancellationTokenSource? _connectCts;
    private readonly DispatcherTimer _metricsTimer = new();
    private readonly DispatcherTimer _adTimer = new();
    private readonly DispatcherTimer _maintenanceTimer = new();
    private bool _maintenanceRunning;
    private DateTimeOffset? _connectedAt;
    private long _lastReceivedBytes;
    private long _lastSentBytes;
    private DateTimeOffset _lastByteSample = DateTimeOffset.UtcNow;
    private int _adIndex;

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
        VersionText.Text = _settings.Version;
        MenuVersionText.Text = _settings.Version;
        CoreVersionText.Text = $"v2rayN {_settings.V2RayNVersion}";
        TechnicalText.Text = _connection.RuntimeStatus;
        MenuTechnicalText.Text = _connection.RuntimeStatus;

        _metricsTimer.Interval = TimeSpan.FromSeconds(1);
        _metricsTimer.Tick += (_, _) => RefreshMetrics();
        _metricsTimer.Start();
        _adTimer.Tick += (_, _) => AdvanceAd();
        _maintenanceTimer.Interval = TimeSpan.FromHours(4);
        _maintenanceTimer.Tick += MaintenanceTimer_Tick;

        Loaded += MainWindow_Loaded;
        Closing += (_, _) => _connection.Disconnect();
    }

    private async void MainWindow_Loaded(object sender, RoutedEventArgs e)
    {
        await RefreshPlansSafeAsync();
        await LoadAdsAsync();
        await RefreshPublicIpAsync();
        _ = CheckRuntimeUpdateSafeAsync();
        if (_settings.AutoUpdate) _ = CheckAppUpdateSafeAsync(silentWhenCurrent: true);
        _maintenanceTimer.Start();
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
                await CheckAppUpdateSafeAsync(silentWhenCurrent: true);
        }
        finally { _maintenanceRunning = false; }
    }

    private async Task LoadAdsAsync()
    {
        await _ads.RefreshAsync();
        _adIndex = 0;
        ShowCurrentAd();
        var items = _ads.BannerItems;
        if (items.Count > 1)
        {
            _adTimer.Interval = TimeSpan.FromMilliseconds(_ads.BannerIntervalMs);
            _adTimer.Start();
        }
    }

    private void AdvanceAd()
    {
        var items = _ads.BannerItems;
        if (items.Count == 0) return;
        _adIndex = (_adIndex + 1) % items.Count;
        ShowCurrentAd();
    }

    private void ShowCurrentAd()
    {
        var items = _ads.BannerItems;
        if (items.Count == 0) { AdCard.Visibility = Visibility.Collapsed; return; }
        var item = items[Math.Clamp(_adIndex, 0, items.Count - 1)];
        AdTitle.Text = item.Title;
        AdSubtitle.Text = item.Subtitle;
        AdActionText.Text = string.IsNullOrWhiteSpace(item.ButtonText) ? "مشاهده ←" : item.ButtonText + " ←";
        AdImage.Source = null;
        if (Uri.TryCreate(item.ImageUrl, UriKind.Absolute, out var uri))
        {
            try
            {
                var bmp = new BitmapImage();
                bmp.BeginInit(); bmp.UriSource = uri; bmp.CacheOption = BitmapCacheOption.OnLoad; bmp.EndInit();
                AdImage.Source = bmp;
            }
            catch { }
        }
        AdCard.Tag = item;
        AdCard.Visibility = Visibility.Visible;
    }

    private void AdCard_Click(object sender, MouseButtonEventArgs e)
    {
        if (AdCard.Tag is not AdvertisementItem item) return;
        var target = !string.IsNullOrWhiteSpace(item.TargetUrl) ? item.TargetUrl : item.DeepLink;
        if (target.Length == 0) return;
        try { Process.Start(new ProcessStartInfo(target) { UseShellExecute = true }); } catch { }
    }

    private void AccountButton_Click(object sender, RoutedEventArgs e)
    {
        MenuDrawer.Visibility = Visibility.Collapsed;
        AccountDrawer.Visibility = AccountDrawer.Visibility == Visibility.Visible ? Visibility.Collapsed : Visibility.Visible;
    }

    private void MenuButton_Click(object sender, RoutedEventArgs e)
    {
        AccountDrawer.Visibility = Visibility.Collapsed;
        MenuDrawer.Visibility = MenuDrawer.Visibility == Visibility.Visible ? Visibility.Collapsed : Visibility.Visible;
        MenuTechnicalText.Text = _connection.RuntimeStatus;
        MenuIpText.Text = $"IP: {IpValue.Text}";
    }

    private void CloseDrawers_Click(object sender, RoutedEventArgs e)
    {
        AccountDrawer.Visibility = Visibility.Collapsed;
        MenuDrawer.Visibility = Visibility.Collapsed;
    }

    private async void Login_Click(object sender, RoutedEventArgs e) => await BusyAsync("در حال ورود…", async ct =>
    {
        var result = await _api.LoginAsync(EmailBox.Text.Trim(), PasswordBox.Password, ct);
        _account = result.Account ?? await _api.GetAccountAsync(ct);
        ApplyAccount(); await RefreshPlansSafeAsync();
        if (_settings.AutoUpdate) _ = CheckAppUpdateSafeAsync(silentWhenCurrent: true);
    });

    private async void Register_Click(object sender, RoutedEventArgs e) => await BusyAsync("در حال ساخت حساب…", async ct =>
    {
        var result = await _api.RegisterAsync(EmailBox.Text.Trim(), PasswordBox.Password, ct);
        _account = result.Account ?? await _api.GetAccountAsync(ct);
        ApplyAccount(); await RefreshPlansSafeAsync();
        if (_settings.AutoUpdate) _ = CheckAppUpdateSafeAsync(silentWhenCurrent: true);
    });

    private async void RequestOtp_Click(object sender, RoutedEventArgs e) => await BusyAsync("ارسال کد پیامک…", async ct =>
    {
        await _api.RequestOtpAsync(PhoneBox.Text.Trim(), ct); FooterStatus.Text = "کد پیامک ارسال شد.";
    });

    private async void VerifyOtp_Click(object sender, RoutedEventArgs e) => await BusyAsync("تأیید کد…", async ct =>
    {
        var result = await _api.VerifyOtpAsync(PhoneBox.Text.Trim(), OtpBox.Text.Trim(), ct);
        _account = result.Account ?? await _api.GetAccountAsync(ct);
        ApplyAccount(); await RefreshPlansSafeAsync();
        if (_settings.AutoUpdate) _ = CheckAppUpdateSafeAsync(silentWhenCurrent: true);
    });

    private async void RefreshAccount_Click(object sender, RoutedEventArgs e) => await BusyAsync("بروزرسانی حساب…", async ct =>
    {
        _account = await _api.GetAccountAsync(ct); ApplyAccount(); await RefreshPlansSafeAsync();
    });

    private void Logout_Click(object sender, RoutedEventArgs e)
    {
        _connection.Disconnect(); _api.Logout(); _account = null;
        LoginPanel.Visibility = Visibility.Visible; AccountPanel.Visibility = Visibility.Collapsed;
        PlansList.ItemsSource = null; SetDisconnectedUi(); TierText.Text = "رایگان"; FooterStatus.Text = "از حساب خارج شدید.";
    }

    private async void Connect_Click(object sender, RoutedEventArgs e)
    {
        if (_connection.IsConnected)
        {
            _connectCts?.Cancel(); _connection.Disconnect(); SetDisconnectedUi(); FooterStatus.Text = "اتصال قطع شد."; return;
        }

        _connectCts?.Cancel(); _connectCts = new CancellationTokenSource();
        var progress = new Progress<string>(message => { ConnectionStatusText.Text = message; FooterStatus.Text = message; });
        SetConnectingUi();
        try
        {
            var result = await _connection.ConnectAsync(_account, progress, _connectCts.Token);
            _connectedAt = DateTimeOffset.UtcNow;
            StatusText.Text = "متصل هستید"; OrbText.Text = "قطع اتصال";
            StatusDot.Fill = (Brush)FindResource("BlueVpnGreen");
            StatusOrb.Background = (Brush)FindResource("BlueVpnBlue");
            StatusOrb.BorderBrush = (Brush)FindResource("BlueVpnBlue2");
            OrbHalo.Background = new SolidColorBrush(Color.FromArgb(232, 235, 243, 255));
            ConnectionStatusText.Text = result.Premium ? "اتصال ویژه برقرار شد" : (result.Engine == "WARP" ? "اتصال رایگان WARP برقرار شد" : "اتصال رایگان برقرار شد");
            EndpointText.Text = result.Endpoint.DisplayName;
            EngineText.Text = "متصل • مسیر فعال در پس‌زمینه مدیریت می‌شود";
            ServerStatusText.Text = $"اتصال سراسری تأیید شد • {result.Engine}";
            TierText.Text = result.Premium ? "Premium" : "Free"; ConnectButton.Content = "⏻";
            IpValue.Text = result.Verification.PublicIp.Length > 0 ? result.Verification.PublicIp : "—";
            PingValue.Text = FormatLatency(result.Endpoint.ProbeLatencyMs);
            LocationBadge.Text = result.Verification.Country.Length > 0 ? result.Verification.Country : "VPN";
            TechnicalText.Text = $"VPN سراسری تأیید شد • {result.Engine} • {result.Verification.Detail}";
            MenuTechnicalText.Text = TechnicalText.Text;
            MenuIpText.Text = $"IP: {IpValue.Text}";
            FooterStatus.Text = "IP و مسیر سیستم از داخل BlueVPN تأیید شد.";
            if (!result.Premium) ShowFreeStoryAdSafe();
        }
        catch (OperationCanceledException) { SetDisconnectedUi(); FooterStatus.Text = "اتصال لغو شد."; }
        catch (Exception ex)
        {
            _connection.Disconnect(); SetDisconnectedUi();
            MessageBox.Show(ex.Message, "BlueVPN", MessageBoxButton.OK, MessageBoxImage.Warning); FooterStatus.Text = ex.Message;
        }
        finally { ConnectButton.IsEnabled = true; }
    }

    private void ShowFreeStoryAdSafe()
    {
        try
        {
            var item = _ads.PickFreeStory(); if (item is null) return;
            var window = new StoryAdWindow(item, _ads.StoryDurationSeconds(item)) { Owner = this };
            window.Show(); // fail-open: ad never owns or blocks the VPN lifecycle
        }
        catch { }
    }

    private async void Update_Click(object sender, RoutedEventArgs e) => await CheckAppUpdateSafeAsync(silentWhenCurrent: false);

    private async Task CheckAppUpdateSafeAsync(bool silentWhenCurrent)
    {
        try
        {
            FooterStatus.Text = "بررسی بروزرسانی BlueVPN…";
            var candidate = await _appUpdater.CheckAsync();
            if (candidate is null)
            {
                if (!silentWhenCurrent) MessageBox.Show("BlueVPN به‌روز است.", "BlueVPN", MessageBoxButton.OK, MessageBoxImage.Information);
                FooterStatus.Text = "آماده"; return;
            }
            FooterStatus.Text = $"دریافت نسخه {candidate.Version}…";
            var installer = await _appUpdater.DownloadAsync(candidate);
            FooterStatus.Text = "بروزرسانی آماده نصب است.";
            if (candidate.AutoUpdate || candidate.ForceUpdate || MessageBox.Show($"نسخه {candidate.Version} ({(candidate.Channel == "beta" ? "Beta" : "Stable")}) آماده است. نصب شود؟", "BlueVPN", MessageBoxButton.YesNo, MessageBoxImage.Information) == MessageBoxResult.Yes)
            {
                _connection.Disconnect();
                if (AppUpdateService.LaunchInstaller(installer)) Application.Current.Shutdown();
            }
        }
        catch (Exception ex)
        {
            FooterStatus.Text = "بررسی بروزرسانی انجام نشد";
            if (!silentWhenCurrent) MessageBox.Show(ex.Message, "BlueVPN Update", MessageBoxButton.OK, MessageBoxImage.Warning);
        }
    }

    private async Task CheckRuntimeUpdateSafeAsync()
    {
        try
        {
            var version = await _runtimeUpdater.CheckAndUpdateAsync(_connection.IsConnected);
            if (version.Length > 0)
            {
                CoreVersionText.Text = $"v2rayN {version}";
                TechnicalText.Text = $"هسته v2rayN {version} دریافت شد؛ در اتصال بعدی استفاده می‌شود.";
            }
        }
        catch { }
    }

    private void ApplyAccount()
    {
        if (_account is null) return;
        LoginPanel.Visibility = Visibility.Collapsed; AccountPanel.Visibility = Visibility.Visible;
        IdentityText.Text = string.IsNullOrWhiteSpace(_account.DisplayIdentity) ? "حساب BlueVPN" : _account.DisplayIdentity;
        PlanText.Text = _account.Subscription.Active ? $"پلن: {(_account.PlanTitle.Length > 0 ? _account.PlanTitle : "ویژه")}" : "اشتراک ویژه فعال نیست";
        ExpiryText.Text = _account.Subscription.Active ? $"اعتبار تا: {_account.Subscription.ExpireFa}" : "اتصال رایگان در دسترس است";
        TrafficText.Text = FormatTraffic(_account.Subscription); TierText.Text = _account.Subscription.Active ? "Premium" : "Free";
        SubscriptionSummaryText.Text = _account.Subscription.Active
            ? $"فعال • {(_account.PlanTitle.Length > 0 ? _account.PlanTitle : "Premium")}"
            : "اتصال رایگان BlueVPN";
        RemainingVolumeValue.Text = FormatRemainingVolume(_account.Subscription);
        RemainingTimeValue.Text = FormatRemainingTime(_account.Subscription);
    }

    private async Task RefreshPlansSafeAsync() { try { PlansList.ItemsSource = await _api.GetPlansAsync(); } catch { PlansList.ItemsSource = null; } }
    private async Task RefreshPublicIpAsync() { try { var snapshot = await ConnectivityProbe.SnapshotAsync(_settings.ProbeUrl); if (!_connection.IsConnected && snapshot.Reachable) { IpValue.Text = snapshot.PublicIp; MenuIpText.Text = $"IP: {snapshot.PublicIp}"; } } catch { } }

    private async Task BusyAsync(string status, Func<CancellationToken, Task> action)
    {
        FooterStatus.Text = status; IsEnabled = false;
        try { await action(CancellationToken.None); FooterStatus.Text = "آماده"; }
        catch (Exception ex) { MessageBox.Show(ex.Message, "BlueVPN", MessageBoxButton.OK, MessageBoxImage.Warning); FooterStatus.Text = ex.Message; }
        finally { IsEnabled = true; }
    }

    private void SetConnectingUi()
    {
        ConnectButton.IsEnabled = false; StatusText.Text = "در حال اتصال"; OrbText.Text = "لطفاً صبر کنید";
        StatusDot.Fill = (Brush)FindResource("BlueVpnBlue");
        StatusOrb.BorderBrush = (Brush)FindResource("BlueVpnBlue2"); OrbHalo.Background = new SolidColorBrush(Color.FromArgb(228, 235, 250, 255));
        ServerStatusText.Text = "در حال بررسی مسیر و IP سیستم…";
    }

    private void SetDisconnectedUi()
    {
        _connectedAt = null; StatusText.Text = "آماده اتصال"; OrbText.Text = "برای اتصال لمس کنید";
        StatusDot.Fill = (Brush)FindResource("BlueVpnMuted");
        StatusOrb.Background = (Brush)FindResource("BlueVpnBlue");
        StatusOrb.BorderBrush = (Brush)FindResource("BlueVpnBlue2"); OrbHalo.Background = new SolidColorBrush(Color.FromArgb(221, 232, 248, 255));
        ConnectionStatusText.Text = "بهترین اتصال به‌صورت خودکار انتخاب می‌شود"; EndpointText.Text = "انتخاب خودکار";
        EngineText.Text = "بهترین مسیر همان لوکیشن به‌صورت خودکار انتخاب می‌شود"; ServerStatusText.Text = "آماده اتصال";
        ConnectButton.Content = "⏻"; ConnectButton.IsEnabled = true;
        PingValue.Text = "—"; DurationValue.Text = "00:00:00"; SpeedValue.Text = "0 KB/s"; UploadSpeedValue.Text = "0 KB/s"; DownloadSpeedValue.Text = "0 KB/s"; LocationBadge.Text = "AUTO";
        TechnicalText.Text = _connection.RuntimeStatus; MenuTechnicalText.Text = TechnicalText.Text;
        _ = RefreshPublicIpAsync();
    }

    private void RefreshMetrics()
    {
        if (_connectedAt is not null) DurationValue.Text = (DateTimeOffset.UtcNow - _connectedAt.Value).ToString(@"hh\:mm\:ss");
        var (received, sent) = NetworkBytes();
        var now = DateTimeOffset.UtcNow;
        var elapsed = Math.Max(0.25, (now - _lastByteSample).TotalSeconds);
        if (_lastReceivedBytes > 0 && received >= _lastReceivedBytes)
            DownloadSpeedValue.Text = FormatRate((received - _lastReceivedBytes) / elapsed);
        if (_lastSentBytes > 0 && sent >= _lastSentBytes)
            UploadSpeedValue.Text = FormatRate((sent - _lastSentBytes) / elapsed);
        SpeedValue.Text = DownloadSpeedValue.Text;
        _lastReceivedBytes = received;
        _lastSentBytes = sent;
        _lastByteSample = now;
    }

    private static (long Received, long Sent) NetworkBytes()
    {
        long received = 0, sent = 0;
        try
        {
            foreach (var nic in NetworkInterface.GetAllNetworkInterfaces())
            {
                if (nic.NetworkInterfaceType == NetworkInterfaceType.Loopback || nic.OperationalStatus != OperationalStatus.Up) continue;
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
        : $"{rate / 1024:0.0} KB/s";

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
        var days = (long)Math.Ceiling(seconds / 86400d);
        return days <= 0 ? "کمتر از یک روز" : $"{days} روز";
    }

    private static string FormatLatency(int ms) => ms == int.MaxValue ? "—" : $"{ms} ms";
    private static string FormatTraffic(SubscriptionInfo info)
    {
        if (info.DataLimitBytes <= 0) return "حجم: نامحدود";
        static string Gb(long bytes) => $"{bytes / 1024d / 1024d / 1024d:0.##} GB";
        return $"مصرف: {Gb(info.UsedTrafficBytes)} از {Gb(info.DataLimitBytes)}";
    }
}
