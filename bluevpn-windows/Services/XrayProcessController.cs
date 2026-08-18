using System.IO;
using System.Diagnostics;

namespace BlueVPN.Windows.Services;

public sealed class XrayProcessController : IDisposable
{
    private Process? _process;
    private readonly string _runtimeDir;
    private readonly string _stateDir;

    public XrayProcessController()
    {
        _runtimeDir = Path.Combine(AppContext.BaseDirectory, "runtime");
        _stateDir = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "BlueVPN", "runtime");
        Directory.CreateDirectory(_stateDir);
    }

    public bool IsRunning => _process is { HasExited: false };
    public string XrayPath => Path.Combine(_runtimeDir, "xray.exe");
    public string WintunPath => Path.Combine(_runtimeDir, "wintun.dll");

    public string RuntimeStatus()
    {
        if (!File.Exists(XrayPath)) return "xray.exe موجود نیست";
        if (!File.Exists(WintunPath)) return "wintun.dll موجود نیست";
        return "Xray + Wintun آماده";
    }

    public async Task StartAsync(string configJson, CancellationToken ct = default)
    {
        Stop();
        if (!File.Exists(XrayPath) || !File.Exists(WintunPath))
            throw new FileNotFoundException("Runtime ویندوز BlueVPN ناقص است. Build رسمی GitHub را نصب کنید.");

        var configPath = Path.Combine(_stateDir, "config.json");
        await File.WriteAllTextAsync(configPath, configJson, ct);

        var start = new ProcessStartInfo
        {
            FileName = XrayPath,
            WorkingDirectory = _runtimeDir,
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true
        };
        start.ArgumentList.Add("run");
        start.ArgumentList.Add("-c");
        start.ArgumentList.Add(configPath);

        _process = new Process { StartInfo = start, EnableRaisingEvents = true };
        _process.Start();
        await Task.Delay(1200, ct);
        if (_process.HasExited)
        {
            var error = await _process.StandardError.ReadToEndAsync(ct);
            throw new InvalidOperationException(string.IsNullOrWhiteSpace(error) ? "Xray بلافاصله متوقف شد." : error.Trim());
        }
    }

    public void Stop()
    {
        var process = _process;
        _process = null;
        if (process is null) return;
        try
        {
            if (!process.HasExited)
            {
                process.Kill(entireProcessTree: true);
                process.WaitForExit(2500);
            }
        }
        catch { }
        finally
        {
            process.Dispose();
        }
    }

    public void Dispose() => Stop();
}
