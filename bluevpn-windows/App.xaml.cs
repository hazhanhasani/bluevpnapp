using System.IO;
using System.Text;
using System.Windows;
using System.Windows.Threading;

namespace BlueVPN.Windows;

public partial class App : Application
{
    private static readonly string LogDirectory = Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "BlueVPN",
        "logs");

    protected override void OnStartup(StartupEventArgs e)
    {
        base.OnStartup(e);

        DispatcherUnhandledException += App_DispatcherUnhandledException;
        AppDomain.CurrentDomain.UnhandledException += CurrentDomain_UnhandledException;
        TaskScheduler.UnobservedTaskException += TaskScheduler_UnobservedTaskException;

        try
        {
            Directory.CreateDirectory(LogDirectory);
            WriteCrashLog("startup", null, "BlueVPN startup begin");

            var window = new MainWindow();
            MainWindow = window;
            window.Show();

            WriteCrashLog("startup", null, "BlueVPN MainWindow shown");

            // CI launches the actual published executable with this switch.
            // It exercises WPF/XAML, service construction and Loaded startup,
            // then exits cleanly without requiring user interaction.
            if (e.Args.Any(arg => string.Equals(arg, "--startup-smoke", StringComparison.OrdinalIgnoreCase)))
            {
                var smokeTimer = new DispatcherTimer { Interval = TimeSpan.FromSeconds(4) };
                smokeTimer.Tick += (_, _) =>
                {
                    smokeTimer.Stop();
                    WriteCrashLog("startup-smoke", null, "Published executable stayed alive");
                    Shutdown(0);
                };
                smokeTimer.Start();
            }
        }
        catch (Exception ex)
        {
            WriteCrashLog("startup-fatal", ex, "MainWindow construction failed");

            var message =
                "BlueVPN نتوانست رابط ویندوز را کامل اجرا کند.\n\n" +
                "برنامه دیگر بی‌صدا بسته نمی‌شود و گزارش خطا ذخیره شده است.\n\n" +
                $"گزارش: {LatestLogPath()}\n\n" +
                Short(ex);

            try
            {
                MessageBox.Show(
                    message,
                    "خطای شروع BlueVPN",
                    MessageBoxButton.OK,
                    MessageBoxImage.Error);
            }
            catch { }

            Shutdown(-1);
        }
    }

    private void App_DispatcherUnhandledException(
        object sender,
        DispatcherUnhandledExceptionEventArgs e)
    {
        WriteCrashLog("dispatcher", e.Exception, "Unhandled WPF dispatcher exception");

        try
        {
            MessageBox.Show(
                "بخشی از رابط BlueVPN با خطا روبه‌رو شد، اما برنامه از بسته‌شدن ناگهانی جلوگیری کرد.\n\n" +
                $"گزارش: {LatestLogPath()}\n\n{Short(e.Exception)}",
                "BlueVPN",
                MessageBoxButton.OK,
                MessageBoxImage.Warning);
        }
        catch { }

        e.Handled = true;
    }

    private static void CurrentDomain_UnhandledException(
        object? sender,
        UnhandledExceptionEventArgs e)
    {
        WriteCrashLog(
            "appdomain",
            e.ExceptionObject as Exception,
            $"AppDomain terminating={e.IsTerminating}");
    }

    private static void TaskScheduler_UnobservedTaskException(
        object? sender,
        UnobservedTaskExceptionEventArgs e)
    {
        WriteCrashLog("task", e.Exception, "Unobserved task exception");
        e.SetObserved();
    }

    private static void WriteCrashLog(string scope, Exception? ex, string note)
    {
        try
        {
            Directory.CreateDirectory(LogDirectory);
            var path = LatestLogPath();
            var sb = new StringBuilder();
            sb.AppendLine("========================================");
            sb.AppendLine($"time_utc={DateTimeOffset.UtcNow:O}");
            sb.AppendLine($"scope={scope}");
            sb.AppendLine($"note={note}");
            sb.AppendLine($"os={Environment.OSVersion}");
            sb.AppendLine($"process_arch={System.Runtime.InteropServices.RuntimeInformation.ProcessArchitecture}");
            sb.AppendLine($"framework={System.Runtime.InteropServices.RuntimeInformation.FrameworkDescription}");
            sb.AppendLine($"base_directory={AppContext.BaseDirectory}");
            if (ex is not null)
            {
                sb.AppendLine($"exception={ex.GetType().FullName}");
                sb.AppendLine($"message={ex.Message}");
                sb.AppendLine(ex.ToString());
            }
            File.AppendAllText(path, sb.ToString(), new UTF8Encoding(false));
        }
        catch { }
    }

    private static string LatestLogPath() =>
        Path.Combine(LogDirectory, "startup.log");

    private static string Short(Exception ex)
    {
        var text = ex.Message?.Trim();
        if (string.IsNullOrWhiteSpace(text)) text = ex.GetType().Name;
        return text.Length <= 320 ? text : text[..320] + "…";
    }

    protected override void OnExit(ExitEventArgs e)
    {
        Services.AppServices.Connection?.Dispose();
        Services.AppServices.Api?.Dispose();
        base.OnExit(e);
    }
}
