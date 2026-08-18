using System.Diagnostics;
using System.Net.NetworkInformation;
using BlueVPN.Windows.Models;

namespace BlueVPN.Windows.Services;

public static class SystemTunnelVerifier
{
    private static readonly string[] AdapterHints = ["BlueVPN", "sing-box", "Wintun", "Xray", "v2rayN"];

    public static async Task<TunnelVerificationResult> VerifyAsync(
        ConnectivitySnapshot before,
        string probeUrl,
        bool requireWarp,
        bool rejectIran,
        CancellationToken ct = default)
    {
        var stop = DateTimeOffset.UtcNow + TimeSpan.FromSeconds(18);
        ConnectivitySnapshot after = new(false, "", "", "", DateTimeOffset.UtcNow);
        string adapter = "";
        string routeDetail = "";

        while (DateTimeOffset.UtcNow < stop)
        {
            ct.ThrowIfCancellationRequested();
            adapter = FindTunnelAdapter();
            routeDetail = await DefaultRouteEvidenceAsync(ct);
            after = await ConnectivityProbe.SnapshotAsync(probeUrl, ct);

            var routeOk = adapter.Length > 0 || routeDetail.Contains("BlueVPN", StringComparison.OrdinalIgnoreCase)
                || routeDetail.Contains("sing-box", StringComparison.OrdinalIgnoreCase)
                || routeDetail.Contains("Wintun", StringComparison.OrdinalIgnoreCase);
            var ipChanged = after.Reachable && (string.IsNullOrWhiteSpace(before.PublicIp)
                || !string.Equals(before.PublicIp, after.PublicIp, StringComparison.OrdinalIgnoreCase));
            var warpOk = !requireWarp || after.Warp.Equals("on", StringComparison.OrdinalIgnoreCase)
                || after.Warp.Equals("plus", StringComparison.OrdinalIgnoreCase);
            var countryOk = !rejectIran || !after.Country.Equals("IR", StringComparison.OrdinalIgnoreCase);

            if (routeOk && ipChanged && warpOk && countryOk)
            {
                return new(true, after.PublicIp, after.Country, after.Warp, adapter,
                    $"route=ok; ip={after.PublicIp}; loc={after.Country}; warp={after.Warp}");
            }
            await Task.Delay(850, ct);
        }

        var reason = !after.Reachable ? "اینترنت از تونل پاسخ نداد"
            : requireWarp && !(after.Warp.Equals("on", StringComparison.OrdinalIgnoreCase) || after.Warp.Equals("plus", StringComparison.OrdinalIgnoreCase)) ? "WARP در خروجی تأیید نشد"
            : rejectIran && after.Country.Equals("IR", StringComparison.OrdinalIgnoreCase) ? "خروجی WARP ایران است"
            : !string.IsNullOrWhiteSpace(before.PublicIp) && string.Equals(before.PublicIp, after.PublicIp, StringComparison.OrdinalIgnoreCase) ? "IP سیستم تغییر نکرد"
            : "مسیر پیش‌فرض Windows زیر TUN تأیید نشد";
        return new(false, after.PublicIp, after.Country, after.Warp, adapter, reason);
    }

    private static string FindTunnelAdapter()
    {
        try
        {
            foreach (var nic in NetworkInterface.GetAllNetworkInterfaces())
            {
                if (nic.OperationalStatus != OperationalStatus.Up) continue;
                var hay = $"{nic.Name} {nic.Description}";
                if (AdapterHints.Any(h => hay.Contains(h, StringComparison.OrdinalIgnoreCase))) return nic.Name;
            }
        }
        catch { }
        return "";
    }

    private static async Task<string> DefaultRouteEvidenceAsync(CancellationToken ct)
    {
        try
        {
            var psi = new ProcessStartInfo
            {
                FileName = "powershell.exe",
                UseShellExecute = false,
                CreateNoWindow = true,
                RedirectStandardOutput = true,
                RedirectStandardError = true
            };
            psi.ArgumentList.Add("-NoProfile");
            psi.ArgumentList.Add("-NonInteractive");
            psi.ArgumentList.Add("-Command");
            psi.ArgumentList.Add("Get-NetRoute -DestinationPrefix '0.0.0.0/0','::/0' -ErrorAction SilentlyContinue | Sort-Object RouteMetric | Select-Object -First 8 InterfaceAlias,DestinationPrefix,NextHop,RouteMetric | ConvertTo-Json -Compress");
            using var p = Process.Start(psi);
            if (p is null) return "";
            var output = await p.StandardOutput.ReadToEndAsync(ct);
            await p.WaitForExitAsync(ct);
            return output;
        }
        catch { return ""; }
    }
}
