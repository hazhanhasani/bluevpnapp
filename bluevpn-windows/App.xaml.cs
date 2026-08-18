using System.Windows;

namespace BlueVPN.Windows;

public partial class App : Application
{
    protected override void OnExit(ExitEventArgs e)
    {
        Services.AppServices.Connection?.Dispose();
        Services.AppServices.Api?.Dispose();
        base.OnExit(e);
    }
}
