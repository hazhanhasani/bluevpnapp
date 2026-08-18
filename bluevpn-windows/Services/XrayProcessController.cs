using System.IO;

namespace BlueVPN.Windows.Services;

public sealed class XrayProcessController : IDisposable
{
    private readonly ManagedCoreProcess _process = new("xray");
    private readonly RuntimeLocator _runtime;
    private readonly string _stateDir;

    public XrayProcessController(RuntimeLocator runtime)
    {
        _runtime = runtime;
        _stateDir = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "BlueVPN", "runtime-state");
        Directory.CreateDirectory(_stateDir);
    }

    public bool IsRunning => _process.IsRunning;
    public string RuntimeStatus() => _runtime.RuntimeStatus();

    public async Task StartAsync(string configJson, CancellationToken ct = default)
    {
        Stop();
        var xray = _runtime.ResolveXray();
        _ = _runtime.ResolveWintun();
        var configPath = Path.Combine(_stateDir, "xray-config.json");
        await File.WriteAllTextAsync(configPath, configJson, ct);
        await _process.StartAsync(xray, ["run", "-c", configPath], Path.GetDirectoryName(xray), ct);
        await Task.Delay(850, ct);
    }

    public void Stop() => _process.Stop();
    public void Dispose() => _process.Dispose();
}
