using System.Diagnostics;
using System.Net.NetworkInformation;
using System.Windows;
using System.Windows.Input;
using System.Windows.Media;
using System.Windows.Controls;
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
    private double _adImageAspectRatio;
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

        _adTimer.Tick += async (_, _) => await AdvanceAdAsync();
        _maintenanceTimer.Interval = TimeSpan.FromHours(4);
        _maintenanceTimer.Tick += MaintenanceTimer_Tick;

        Loaded += MainWindow_Loaded;
        Closing += MainWindow_Closing;
        MaxHeight = Math.Max(600, SystemParameters.WorkArea.Height);
        Height = Math.Min(760, Math.Max(600, SystemParameters.WorkArea.Height - 18));
        MaxWidth = Math.Max(620, SystemParameters.WorkArea.Width);
    }

    private async void MainWindow_Loaded(object sender, RoutedEventArgs e)
    {
        _metricsLoop ??= RunMetricsLoopAsync(_lifetimeCts.Token);

        // Do not serialize startup network calls on the dispatcher. The Android
        // home renders first and hydrates account/ads/IP independently; Windows
        // now follows the same non-blocking behaviour.
        await Task.WhenAll(
            RestoreAccountSessionSafeAsync(),
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
        _adImageAspectRatio = 0;
        ApplyAdCardHeight();
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


    private void AdCard_SizeChanged(object sender, SizeChangedEventArgs e)
    {
        if (AdCard.Visibility != Visibility.Visible || Math.Abs(e.NewSize.Width - e.PreviousSize.Width) < 1) return;
        ApplyAdCardHeight();
    }

    private void ApplyAdCardHeight()
    {
        // Keep campaign artwork in its native proportion on wide desktop windows.
        // The previous 0.58 multiplier flattened a 116–160dp campaign into a tiny
        // 76–96px strip, which also pulled the rows below it into the home content.
        var width = AdCard.ActualWidth;
        if (width < 240) width = Math.Max(320, ActualWidth - 52);
        var ratio = _adImageAspectRatio > 0.25 ? _adImageAspectRatio : _ads.BannerAspectRatio;
        var configuredFloor = Math.Clamp((double)_ads.BannerHeight, 116, 160);
        var ratioHeight = ratio > 0.25 ? width / ratio : configuredFloor;
        AdCard.Height = Math.Clamp(ratioHeight, configuredFloor, 280);
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
        MenuTechnicalText.Text = $"{_connection.RuntimeStatus} • {_connection.AiStatus}";
        MenuIpText.Text = $"IP: {IpValue.Text}";
    }

    private void CloseDrawers_Click(object sender, RoutedEventArgs e)
    {
        AccountDrawer.Visibility = Visibility.Collapsed;
        MenuDrawer.Visibility = Visibility.Collapsed;
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
        button.Background = new SolidColorBrush((Color)ColorConverter.ConvertFromString(selected ? "#FFEAF1FF" : "#FFF5F7FC"));
        button.Foreground = new SolidColorBrush((Color)ColorConverter.ConvertFromString(selected ? "#FF2455CC" : "#FF667085"));
        button.BorderBrush = new SolidColorBrush((Color)ColorConverter.ConvertFromString(selected ? "#FFB9C9F4" : "#FFD9DFEC"));
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
                : await _api.GetFreeSubscriptionAsync(_lifetimeCts.Token);
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

            var root = new DockPanel { Margin = new Thickness(18), Background = Brushes.White };
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
                    Background = Brushes.White,
                    Foreground = (Brush)FindResource("BlueVpnText"),
                    BorderBrush = new SolidColorBrush(Color.FromRgb(190, 205, 238)),
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
            OrbHalo.Background = new SolidColorBrush(Color.FromArgb(232, 235, 243, 255));
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
            return message.Length > 220 ? message[..220] + "…" : message;
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
