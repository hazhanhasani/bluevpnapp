using System.Windows;
using System.Windows.Media;
using BlueVPN.Windows.Models;
using BlueVPN.Windows.Services;

namespace BlueVPN.Windows;

public partial class MainWindow : Window
{
    private readonly BlueVpnApiClient _api;
    private readonly ConnectionOrchestrator _connection;
    private readonly AppSettings _settings;
    private Account? _account;
    private CancellationTokenSource? _connectCts;

    public MainWindow()
    {
        InitializeComponent();
        AppServices.EnsureInitialized();
        _settings = AppServices.Settings;
        _api = AppServices.Api!;
        _connection = AppServices.Connection!;
        VersionText.Text = $"v{_settings.Version}";
        CoreVersionText.Text = $"Xray {_settings.XrayVersion}";
        TechnicalText.Text = _connection.RuntimeStatus;
        Loaded += async (_, _) => await RefreshPlansSafeAsync();
    }

    private async void Login_Click(object sender, RoutedEventArgs e)
    {
        await BusyAsync("در حال ورود…", async ct =>
        {
            var result = await _api.LoginAsync(EmailBox.Text.Trim(), PasswordBox.Password, ct);
            _account = result.Account ?? await _api.GetAccountAsync(ct);
            ApplyAccount();
            await RefreshPlansSafeAsync();
        });
    }

    private async void Register_Click(object sender, RoutedEventArgs e)
    {
        await BusyAsync("در حال ساخت حساب…", async ct =>
        {
            var result = await _api.RegisterAsync(EmailBox.Text.Trim(), PasswordBox.Password, ct);
            _account = result.Account ?? await _api.GetAccountAsync(ct);
            ApplyAccount();
            await RefreshPlansSafeAsync();
        });
    }

    private async void RequestOtp_Click(object sender, RoutedEventArgs e)
    {
        await BusyAsync("ارسال کد پیامک…", async ct =>
        {
            await _api.RequestOtpAsync(PhoneBox.Text.Trim(), ct);
            FooterStatus.Text = "کد پیامک ارسال شد.";
        });
    }

    private async void VerifyOtp_Click(object sender, RoutedEventArgs e)
    {
        await BusyAsync("تأیید کد…", async ct =>
        {
            var result = await _api.VerifyOtpAsync(PhoneBox.Text.Trim(), OtpBox.Text.Trim(), ct);
            _account = result.Account ?? await _api.GetAccountAsync(ct);
            ApplyAccount();
            await RefreshPlansSafeAsync();
        });
    }

    private async void RefreshAccount_Click(object sender, RoutedEventArgs e)
    {
        await BusyAsync("بروزرسانی حساب…", async ct =>
        {
            _account = await _api.GetAccountAsync(ct);
            ApplyAccount();
            await RefreshPlansSafeAsync();
        });
    }

    private void Logout_Click(object sender, RoutedEventArgs e)
    {
        _connection.Disconnect();
        _api.Logout();
        _account = null;
        LoginPanel.Visibility = Visibility.Visible;
        AccountPanel.Visibility = Visibility.Collapsed;
        PlansList.ItemsSource = null;
        SetDisconnectedUi();
        TierText.Text = "پلن رایگان";
        FooterStatus.Text = "از حساب خارج شدید.";
    }

    private async void Connect_Click(object sender, RoutedEventArgs e)
    {
        if (_connection.IsConnected)
        {
            _connectCts?.Cancel();
            _connection.Disconnect();
            SetDisconnectedUi();
            FooterStatus.Text = "اتصال قطع شد.";
            return;
        }

        _connectCts?.Cancel();
        _connectCts = new CancellationTokenSource();
        var progress = new Progress<string>(message =>
        {
            ConnectionStatusText.Text = message;
            FooterStatus.Text = message;
        });

        ConnectButton.IsEnabled = false;
        OrbText.Text = "اتصال…";
        StatusOrb.Background = new SolidColorBrush(Color.FromRgb(18, 48, 74));
        try
        {
            var result = await _connection.ConnectAsync(_account, progress, _connectCts.Token);
            OrbText.Text = "متصل";
            StatusOrb.Background = new SolidColorBrush(Color.FromRgb(21, 128, 61));
            ConnectionStatusText.Text = result.Premium ? "اتصال ویژه برقرار شد" : "اتصال رایگان برقرار شد";
            EndpointText.Text = $"{result.Endpoint.DisplayName} • {FormatLatency(result.Endpoint.ProbeLatencyMs)}";
            TierText.Text = result.Premium ? "Premium" : "Free";
            ConnectButton.Content = "قطع اتصال";
            TechnicalText.Text = $"TUN: {_settings.Tun.Name} • Xray {_settings.XrayVersion} • Wintun";
            FooterStatus.Text = "اینترنت از تونل BlueVPN تأیید شد.";
        }
        catch (OperationCanceledException)
        {
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
            ConnectButton.IsEnabled = true;
        }
    }

    private void ApplyAccount()
    {
        if (_account is null) return;
        LoginPanel.Visibility = Visibility.Collapsed;
        AccountPanel.Visibility = Visibility.Visible;
        IdentityText.Text = string.IsNullOrWhiteSpace(_account.DisplayIdentity) ? "حساب BlueVPN" : _account.DisplayIdentity;
        PlanText.Text = _account.Subscription.Active
            ? $"پلن: {(_account.PlanTitle.Length > 0 ? _account.PlanTitle : "ویژه")}" 
            : "اشتراک ویژه فعال نیست — اتصال رایگان در دسترس است";
        ExpiryText.Text = _account.Subscription.Active
            ? $"اعتبار تا: {_account.Subscription.ExpireFa}"
            : "";
        TrafficText.Text = FormatTraffic(_account.Subscription);
        TierText.Text = _account.Subscription.Active ? "Premium" : "Free";
    }

    private async Task RefreshPlansSafeAsync()
    {
        try
        {
            PlansList.ItemsSource = await _api.GetPlansAsync();
        }
        catch
        {
            PlansList.ItemsSource = null;
        }
    }

    private async Task BusyAsync(string status, Func<CancellationToken, Task> action)
    {
        FooterStatus.Text = status;
        IsEnabled = false;
        try
        {
            await action(CancellationToken.None);
            FooterStatus.Text = "آماده";
        }
        catch (Exception ex)
        {
            MessageBox.Show(ex.Message, "BlueVPN", MessageBoxButton.OK, MessageBoxImage.Warning);
            FooterStatus.Text = ex.Message;
        }
        finally
        {
            IsEnabled = true;
        }
    }

    private void SetDisconnectedUi()
    {
        OrbText.Text = "آماده";
        StatusOrb.Background = new SolidColorBrush(Color.FromRgb(18, 48, 74));
        ConnectionStatusText.Text = "برای اتصال دکمه زیر را بزنید";
        EndpointText.Text = "";
        ConnectButton.Content = "اتصال";
        TechnicalText.Text = _connection.RuntimeStatus;
    }

    private static string FormatLatency(int ms) => ms == int.MaxValue ? "بدون Ping" : $"{ms} ms";

    private static string FormatTraffic(SubscriptionInfo info)
    {
        if (info.DataLimitBytes <= 0) return "حجم: نامحدود";
        static string Gb(long bytes) => $"{bytes / 1024d / 1024d / 1024d:0.##} GB";
        return $"مصرف: {Gb(info.UsedTrafficBytes)} از {Gb(info.DataLimitBytes)}";
    }
}
