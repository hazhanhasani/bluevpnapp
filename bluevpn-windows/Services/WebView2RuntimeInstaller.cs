using System.Diagnostics;
using System.IO;
using System.Net;
using Microsoft.Web.WebView2.Core;

namespace BlueVPN.Windows.Services;

/// <summary>Installs Microsoft's architecture-aware Evergreen bootstrapper only when required.</summary>
public static class WebView2RuntimeInstaller
{
    private const string BootstrapperUrl = "https://go.microsoft.com/fwlink/p/?LinkId=2124703";
    private static readonly SemaphoreSlim Gate = new(1, 1);

    public static async Task<bool> EnsureInstalledAsync(IProgress<string>? progress = null, CancellationToken ct = default)
    {
        if (IsInstalled()) return true;
        await Gate.WaitAsync(ct).ConfigureAwait(false);
        try
        {
            if (IsInstalled()) return true;
            progress?.Report("نصب خودکار WebView2 برای تبلیغات…");
            var temp = Path.Combine(Path.GetTempPath(), $"BlueVPN-WebView2-{Guid.NewGuid():N}.exe");
            try
            {
                await DownloadMicrosoftBootstrapperAsync(temp, ct).ConfigureAwait(false);
                if (!await HasValidMicrosoftSignatureAsync(temp, ct).ConfigureAwait(false)) return false;
                var psi = new ProcessStartInfo(temp) { UseShellExecute = false, CreateNoWindow = true };
                psi.ArgumentList.Add("/silent");
                psi.ArgumentList.Add("/install");
                using var process = Process.Start(psi);
                if (process is null) return false;
                using var timeout = CancellationTokenSource.CreateLinkedTokenSource(ct);
                timeout.CancelAfter(TimeSpan.FromMinutes(4));
                await process.WaitForExitAsync(timeout.Token).ConfigureAwait(false);
                return (process.ExitCode == 0 || process.ExitCode == 3010) && IsInstalled();
            }
            finally
            {
                try { if (File.Exists(temp)) File.Delete(temp); } catch { }
            }
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested) { throw; }
        catch { return false; }
        finally { Gate.Release(); }
    }

    public static bool IsInstalled()
    {
        try { return !string.IsNullOrWhiteSpace(CoreWebView2Environment.GetAvailableBrowserVersionString()); }
        catch (WebView2RuntimeNotFoundException) { return false; }
        catch { return false; }
    }

    private static async Task DownloadMicrosoftBootstrapperAsync(string destination, CancellationToken ct)
    {
        using var handler = new HttpClientHandler { AllowAutoRedirect = false, AutomaticDecompression = DecompressionMethods.All };
        using var client = new HttpClient(handler) { Timeout = TimeSpan.FromSeconds(45) };
        var current = new Uri(BootstrapperUrl);
        for (var redirect = 0; redirect < 6; redirect++)
        {
            if (!IsMicrosoftDownloadUri(current)) throw new InvalidOperationException("دامنه نصب WebView2 معتبر نیست.");
            using var response = await client.GetAsync(current, HttpCompletionOption.ResponseHeadersRead, ct).ConfigureAwait(false);
            if ((int)response.StatusCode is >= 300 and < 400)
            {
                var next = response.Headers.Location ?? throw new InvalidOperationException("مسیر دانلود WebView2 ناقص است.");
                current = next.IsAbsoluteUri ? next : new Uri(current, next);
                continue;
            }
            response.EnsureSuccessStatusCode();
            if (response.Content.Headers.ContentLength is long length && (length < 100_000 || length > 20_000_000))
                throw new InvalidOperationException("اندازه Bootstrapper معتبر نیست.");
            await using var input = await response.Content.ReadAsStreamAsync(ct).ConfigureAwait(false);
            await using var output = new FileStream(destination, FileMode.CreateNew, FileAccess.Write, FileShare.None, 81920, true);
            var buffer = new byte[81920];
            long total = 0;
            int read;
            while ((read = await input.ReadAsync(buffer, ct).ConfigureAwait(false)) > 0)
            {
                total += read;
                if (total > 20_000_000) throw new InvalidOperationException("Bootstrapper بیش از حد مجاز است.");
                await output.WriteAsync(buffer.AsMemory(0, read), ct).ConfigureAwait(false);
            }
            if (total < 100_000) throw new InvalidOperationException("Bootstrapper ناقص دریافت شد.");
            return;
        }
        throw new InvalidOperationException("Redirect دانلود WebView2 بیش از حد است.");
    }

    private static bool IsMicrosoftDownloadUri(Uri uri)
    {
        if (!uri.Scheme.Equals(Uri.UriSchemeHttps, StringComparison.OrdinalIgnoreCase)) return false;
        var host = uri.IdnHost.ToLowerInvariant();
        return host == "go.microsoft.com" || host == "download.microsoft.com" ||
               host.EndsWith(".download.microsoft.com", StringComparison.Ordinal) ||
               host.EndsWith(".delivery.mp.microsoft.com", StringComparison.Ordinal);
    }

    private static async Task<bool> HasValidMicrosoftSignatureAsync(string path, CancellationToken ct)
    {
        var psi = new ProcessStartInfo("powershell.exe") { UseShellExecute = false, CreateNoWindow = true };
        psi.ArgumentList.Add("-NoProfile");
        psi.ArgumentList.Add("-NonInteractive");
        psi.ArgumentList.Add("-Command");
        psi.ArgumentList.Add("$s=Get-AuthenticodeSignature -LiteralPath $args[0]; if($s.Status -ne 'Valid' -or $s.SignerCertificate.Subject -notmatch 'Microsoft Corporation'){exit 7}");
        psi.ArgumentList.Add(path);
        using var process = Process.Start(psi);
        if (process is null) return false;
        await process.WaitForExitAsync(ct).ConfigureAwait(false);
        return process.ExitCode == 0;
    }
}
