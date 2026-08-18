namespace BlueVPN.Windows.Services;

public static class AppServices
{
    public static AppSettings Settings { get; } = AppSettings.Load();
    public static RuntimeLocator Runtime { get; } = new(Settings);
    public static BlueVpnApiClient? Api { get; set; }
    public static ConnectionOrchestrator? Connection { get; set; }
    public static AdvertisementService? Advertisements { get; set; }
    public static AppUpdateService? AppUpdater { get; set; }
    public static RuntimeUpdateService? RuntimeUpdater { get; set; }

    public static void EnsureInitialized()
    {
        Api ??= new BlueVpnApiClient(Settings);
        Connection ??= new ConnectionOrchestrator(Settings, Api, Runtime);
        Advertisements ??= new AdvertisementService(Api);
        AppUpdater ??= new AppUpdateService(Settings);
        RuntimeUpdater ??= new RuntimeUpdateService(Settings, Runtime);
    }
}
