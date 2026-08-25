using System.Diagnostics;
using System.IO;

namespace BlueVPN.Windows.Services;

public sealed class ManagedCoreProcess : IDisposable
{
    private Process? _process;
    private readonly string _name;
    private readonly string _logPath;

    public ManagedCoreProcess(string name)
    {
        _name = name;
        var logDir = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "BlueVPN", "logs");
        Directory.CreateDirectory(logDir);
        _logPath = Path.Combine(logDir, $"{name}.log");
    }

    public bool IsRunning => _process is { HasExited: false };
    public int? ProcessId => IsRunning ? _process!.Id : null;
    public string LogPath => _logPath;

    public async Task StartAsync(string executable, IEnumerable<string> args, string? workingDirectory = null, CancellationToken ct = default)
    {
        Stop();
        if (!File.Exists(executable)) throw new FileNotFoundException($"{_name}: فایل اجرایی پیدا نشد.", executable);

        var psi = new ProcessStartInfo
        {
            FileName = executable,
            WorkingDirectory = workingDirectory ?? Path.GetDirectoryName(executable) ?? AppContext.BaseDirectory,
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            RedirectStandardInput = true
        };
        foreach (var arg in args) psi.ArgumentList.Add(arg);

        _process = new Process { StartInfo = psi, EnableRaisingEvents = true };
        _process.OutputDataReceived += (_, e) => AppendLog("OUT", e.Data);
        _process.ErrorDataReceived += (_, e) => AppendLog("ERR", e.Data);
        if (!_process.Start()) throw new InvalidOperationException($"{_name} شروع نشد.");
        _process.BeginOutputReadLine();
        _process.BeginErrorReadLine();
        try { _process.StandardInput.Close(); } catch { }

        await Task.Delay(550, ct);
        if (_process.HasExited)
        {
            var code = _process.ExitCode;
            throw new InvalidOperationException($"{_name} بلافاصله متوقف شد (code={code}). {ReadLogTail()}");
        }
    }

    public static async Task ValidateAsync(
        string executable,
        IEnumerable<string> args,
        string? workingDirectory = null,
        CancellationToken ct = default)
    {
        var psi = new ProcessStartInfo
        {
            FileName = executable,
            WorkingDirectory = workingDirectory ?? Path.GetDirectoryName(executable) ?? AppContext.BaseDirectory,
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true
        };
        foreach (var arg in args) psi.ArgumentList.Add(arg);
        using var process = Process.Start(psi) ?? throw new InvalidOperationException("آزمون هسته شروع نشد.");
        var stdoutTask = process.StandardOutput.ReadToEndAsync(ct);
        var stderrTask = process.StandardError.ReadToEndAsync(ct);
        await process.WaitForExitAsync(ct).ConfigureAwait(false);
        var output = $"{await stderrTask.ConfigureAwait(false)}\n{await stdoutTask.ConfigureAwait(false)}".Trim();
        if (process.ExitCode != 0)
            throw new InvalidOperationException($"کانفیگ یا Runtime هسته معتبر نیست (code={process.ExitCode}): {Compact(output)}");
    }

    public void Stop()
    {
        var p = _process;
        _process = null;
        if (p is null) return;
        try
        {
            if (!p.HasExited)
            {
                p.Kill(entireProcessTree: true);
                p.WaitForExit(3000);
            }
        }
        catch { }
        finally { p.Dispose(); }
    }

    private void AppendLog(string stream, string? line)
    {
        if (string.IsNullOrWhiteSpace(line)) return;
        try
        {
            File.AppendAllText(_logPath, $"{DateTimeOffset.Now:O} [{stream}] {line}{Environment.NewLine}");
            var info = new FileInfo(_logPath);
            if (info.Exists && info.Length > 1024 * 1024)
            {
                var text = File.ReadAllText(_logPath);
                File.WriteAllText(_logPath, text[^Math.Min(text.Length, 512 * 1024)..]);
            }
        }
        catch { }
    }

    private string ReadLogTail()
    {
        try
        {
            if (!File.Exists(_logPath)) return "لاگ هسته تولید نشد.";
            var lines = File.ReadLines(_logPath).TakeLast(8).Select(Compact).Where(x => x.Length > 0);
            var tail = string.Join(" | ", lines);
            return tail.Length > 0 ? tail : "لاگ هسته خالی است.";
        }
        catch { return $"جزئیات در {_logPath}"; }
    }

    private static string Compact(string? value)
    {
        if (string.IsNullOrWhiteSpace(value)) return "";
        var compact = string.Join(" ", value.Split((char[]?)null, StringSplitOptions.RemoveEmptyEntries));
        return compact.Length <= 700 ? compact : compact[^700..];
    }

    public void Dispose() => Stop();
}
