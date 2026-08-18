namespace BlueVPN.Windows.Services;

public static class AppServices
{
    public static AppSettings Settings { get; } = AppSettings.Load();
    public static BlueVpnApiClient? Api { get; set; }
    public static ConnectionOrchestrator? Connection { get; set; }

    public static void EnsureInitialized()
    {
        Api ??= new BlueVpnApiClient(Settings);
        Connection ??= new ConnectionOrchestrator(Settings, Api);
    }
}
