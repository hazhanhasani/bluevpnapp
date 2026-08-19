using System.Diagnostics;
using System.Net.NetworkInformation;
using System.Windows;
using System.Windows.Input;
using System.Windows.Media;
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
    private DateTimeOffset? _connectedAt;
    private long _lastReceivedBytes;
    private long _lastSentBytes;
    private DateTimeOffset _lastByteSample = DateTimeOffset.UtcNow;
    private int _adIndex;
    private UpdateCandidate? _pendingUpdate;
    private long? _remainingSecondsAtSnapshot;
    private DateTimeOffset _accountSnapshotAt = DateTimeOffset.UtcNow;

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
        CoreVersionText.Text = "BlueVPN Core";
        TechnicalText.Text = _connection.RuntimeStatus;
        MenuTechnicalText.Text = _connection.RuntimeStatus;

        _adTimer.Tick += async (_, _) => await AdvanceAdAsync();
        _maintenanceTimer.Interval = TimeSpan.FromHours(4);
        _maintenanceTimer.Tick += MaintenanceTimer_Tick;

        Loaded += MainWindow_Loaded;
        Closing += MainWindow_Closing;
    }

    private async void MainWindow_Loaded(object sender, RoutedEventArgs e)
    {
        _metricsLoop ??= RunMetricsLoopAsync(_lifetimeCts.Token);

        // Do not serialize startup network calls on the dispatcher. The Android
        // home renders first and hydrates account/ads/IP independently; Windows
        // now follows the same non-blocking behaviour.
        await Task.WhenAll(
            RefreshPlansSafeAsync(),
            LoadAdsAsync(),
            RefreshPublicIpAsync());

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
            await LoadAdsAsync();
        }
        finally { _maintenanceRunning = false; }
    }

    private async Task LoadAdsAsync()
    {
        await _ads.RefreshAsync(_lifetimeCts.Token);
        _adIndex = 0;
        AdCard.Height = Math.Clamp(_ads.BannerHeight * 0.88, 112, 140);
        await ShowCurrentAdAsync();

        _adTimer.Stop();
        var items = _ads.BannerItems;
        if (_ads.BannerAutoplay && items.Count > 1)
        {
            _adTimer.Interval = TimeSpan.FromMilliseconds(_ads.BannerIntervalMs);
            _adTimer.Start();
        }
    }

    private async Task AdvanceAdAsync()
    {
        var items = _ads.BannerItems;
        if (items.Count == 0) return;
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
        if (items.Count == 0)
        {
            AdCard.Visibility = Visibility.Collapsed;
            AdImage.Source = null;
            return;
        }

        var index = Math.Clamp(_adIndex, 0, items.Count - 1);
        var item = items[index];
        AdTitle.Text = item.Title;
        AdSubtitle.Text = item.Subtitle;
        AdActionText.Text = string.IsNullOrWhiteSpace(item.ButtonText) ? "مشاهده ←" : item.ButtonText + " ←";
        AdCard.Tag = item;
        AdCard.Visibility = Visibility.Visible;

        _adImageCts?.Cancel();
        _adImageCts?.Dispose();
        _adImageCts = CancellationTokenSource.CreateLinkedTokenSource(_lifetimeCts.Token);
        var token = _adImageCts.Token;

        AdImage.Source = null;
        var imageUrl = !string.IsNullOrWhiteSpace(item.ImageUrl) ? item.ImageUrl : item.MediaUrl;
        var image = await MediaAssetLoader.LoadImageAsync(imageUrl, token);
        if (token.IsCancellationRequested || index != _adIndex) return;
        AdImage.Source = image;

        if (items.Count > 1)
        {
            var next = items[(index + 1) % items.Count];
            MediaAssetLoader.Preload(!string.IsNullOrWhiteSpace(next.ImageUrl) ? next.ImageUrl : next.MediaUrl);
        }
        MediaAssetLoader.Trim();
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
        ApplyAccount();
        await RefreshPlansSafeAsync();
        if (_settings.AutoUpdate) _ = CheckAppUpdateSafeAsync(silentWhenCurrent: true, userInitiated: false);
    });

    private async void Register_Click(object sender, RoutedEventArgs e) => await BusyAsync("در حال ساخت حساب…", async ct =>
    {
        var result = await _api.RegisterAsync(EmailBox.Text.Trim(), PasswordBox.Password, ct);
        _account = result.Account ?? await _api.GetAccountAsync(ct);
        ApplyAccount();
        await RefreshPlansSafeAsync();
        if (_settings.AutoUpdate) _ = CheckAppUpdateSafeAsync(silentWhenCurrent: true, userInitiated: false);
    });

    private async void RequestOtp_Click(object sender, RoutedEventArgs e) => await BusyAsync("ارسال کد پیامک…", async ct =>
    {
        await _api.RequestOtpAsync(PhoneBox.Text.Trim(), ct);
        FooterStatus.Text = "کد پیامک ارسال شد.";
    });

    private async void VerifyOtp_Click(object sender, RoutedEventArgs e) => await BusyAsync("تأیید کد…", async ct =>
    {
        var result = await _api.VerifyOtpAsync(PhoneBox.Text.Trim(), OtpBox.Text.Trim(), ct);
        _account = result.Account ?? await _api.GetAccountAsync(ct);
        ApplyAccount();
        await RefreshPlansSafeAsync();
        if (_settings.AutoUpdate) _ = CheckAppUpdateSafeAsync(silentWhenCurrent: true, userInitiated: false);
    });

    private async void RefreshAccount_Click(object sender, RoutedEventArgs e) => await BusyAsync("بروزرسانی حساب…", async ct =>
    {
        _account = await _api.GetAccountAsync(ct);
        ApplyAccount();
        await RefreshPlansSafeAsync();
    });

    private void Logout_Click(object sender, RoutedEventArgs e)
    {
        _connectCts?.Cancel();
        _connection.Disconnect();
        _api.Logout();
        _account = null;
        LoginPanel.Visibility = Visibility.Visible;
        AccountPanel.Visibility = Visibility.Collapsed;
        PlansList.ItemsSource = null;
        _remainingSecondsAtSnapshot = null;
        SetDisconnectedUi();
        TierText.Text = "رایگان";
        FooterStatus.Text = "از حساب خارج شدید.";
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
            var result = await _connection.ConnectAsync(_account, progress, _connectCts.Token);
            _connectedAt = DateTimeOffset.UtcNow;
            ConnectingOverlay.Visibility = Visibility.Collapsed;
            StatusText.Text = "متصل هستید";
            OrbText.Text = "قطع اتصال";
            StatusDot.Fill = (Brush)FindResource("BlueVpnGreen");
            StatusOrb.Background = (Brush)FindResource("BlueVpnBlue");
            StatusOrb.BorderBrush = (Brush)FindResource("BlueVpnBlue2");
            OrbHalo.Background = new SolidColorBrush(Color.FromArgb(232, 235, 243, 255));
            ConnectionStatusText.Text = result.Premium ? "اتصال ویژه برقرار شد" : (result.Engine == "WARP" ? "اتصال رایگان WARP برقرار شد" : "اتصال رایگان برقرار شد");
            EndpointText.Text = result.Premium
                ? PublicRouteLabel("ویژه", result.Verification.Country)
                : PublicRouteLabel("رایگان", result.Verification.Country);
            EngineText.Text = "متصل • مسیر فعال در پس‌زمینه مدیریت می‌شود";
            ServerStatusText.Text = $"اتصال سراسری تأیید شد • {result.Engine}";
            TierText.Text = result.Premium ? "Premium" : "Free";
            ConnectButton.Content = "⏻";
            IpValue.Text = result.Verification.PublicIp.Length > 0 ? result.Verification.PublicIp : "—";
            PingValue.Text = FormatLatency(result.Endpoint.ProbeLatencyMs);
            LocationBadge.Text = result.Verification.Country.Length > 0 ? result.Verification.Country : "VPN";
            TechnicalText.Text = "VPN سراسری تأیید شد • مسیر سیستم امن است";
            MenuTechnicalText.Text = TechnicalText.Text;
            MenuIpText.Text = $"IP: {IpValue.Text}";
            FooterStatus.Text = "IP و مسیر سیستم از داخل BlueVPN تأیید شد.";
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
            MessageBox.Show(ex.Message, "BlueVPN", MessageBoxButton.OK, MessageBoxImage.Warning);
            FooterStatus.Text = ex.Message;
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
        if (!await _updateGate.WaitAsync(0)) return;
        try
        {
            FooterStatus.Text = "بررسی بروزرسانی BlueVPN…";
            var candidate = await _appUpdater.CheckAsync(_lifetimeCts.Token);
            if (candidate is null)
            {
                UpdateButton.Content = "بررسی بروزرسانی";
                if (!silentWhenCurrent) MessageBox.Show("BlueVPN به‌روز است.", "BlueVPN", MessageBoxButton.OK, MessageBoxImage.Information);
                FooterStatus.Text = "آماده";
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
                FooterStatus.Text = $"نسخه {candidate.Version} موجود است؛ نصب خودکار این کانال خاموش است.";
                return;
            }

            if (_connection.IsConnected)
            {
                _pendingUpdate = candidate;
                FooterStatus.Text = $"نسخه {candidate.Version} آماده است و پس از قطع اتصال نصب می‌شود.";
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
        finally { _updateGate.Release(); }
    }

    private async Task DownloadAndInstallUpdateAsync(UpdateCandidate candidate, bool forced)
    {
        FooterStatus.Text = $"دریافت نسخه {candidate.Version}…";
        var progress = new Progress<double>(value =>
        {
            var percent = Math.Clamp((int)Math.Round(value * 100d), 0, 100);
            FooterStatus.Text = $"دریافت نسخه {candidate.Version}… {percent}%";
        });
        var installer = await _appUpdater.DownloadAsync(candidate, progress, _lifetimeCts.Token);
        FooterStatus.Text = "بروزرسانی تأیید شد؛ در حال اجرای نصب…";
        _pendingUpdate = null;
        _connectCts?.Cancel();
        _connection.Disconnect();
        if (AppUpdateService.LaunchInstaller(installer))
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
            MessageBox.Show(ex.Message, "BlueVPN", MessageBoxButton.OK, MessageBoxImage.Warning);
            FooterStatus.Text = ex.Message;
        }
        finally { _accountOperationRunning = false; }
    }

    private void SetConnectingUi()
    {
        ConnectingOverlay.Visibility = Visibility.Visible;
        ConnectingStageText.Text = "در حال انتخاب بهترین اتصال";
        StatusText.Text = "در حال اتصال";
        OrbText.Text = "در حال اتصال…";
        StatusDot.Fill = (Brush)FindResource("BlueVpnBlue");
        StatusOrb.BorderBrush = (Brush)FindResource("BlueVpnBlue2");
        OrbHalo.Background = new SolidColorBrush(Color.FromArgb(228, 235, 250, 255));
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
        OrbHalo.Background = new SolidColorBrush(Color.FromArgb(221, 232, 248, 255));
        ConnectionStatusText.Text = "بهترین اتصال به‌صورت خودکار انتخاب می‌شود";
        EndpointText.Text = "انتخاب خودکار";
        EngineText.Text = "بهترین مسیر همان لوکیشن به‌صورت خودکار انتخاب می‌شود";
        ServerStatusText.Text = "آماده اتصال";
        ConnectButton.Content = "⏻";
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
        TechnicalText.Text = _connection.RuntimeStatus;
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

    private static string FormatLatency(int ms) => ms == int.MaxValue ? "—" : $"{ms} ms";

    private static string FormatTraffic(SubscriptionInfo info)
    {
        if (info.DataLimitBytes <= 0) return "حجم: نامحدود";
        static string Gb(long bytes) => $"{bytes / 1024d / 1024d / 1024d:0.##} GB";
        return $"مصرف: {Gb(info.UsedTrafficBytes)} از {Gb(info.DataLimitBytes)}";
    }
}
