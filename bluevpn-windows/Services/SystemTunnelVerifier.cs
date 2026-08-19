using System.Diagnostics;
using System.Net.NetworkInformation;
using System.Text.Json;
using BlueVPN.Windows.Models;

namespace BlueVPN.Windows.Services;

public static class SystemTunnelVerifier
{
    private static readonly string[] AdapterHints = ["BlueVPN", "sing-box", "Wintun"];

    public static async Task<TunnelVerificationResult> VerifyAsync(
        ConnectivitySnapshot before,
        string probeUrl,
        bool requireWarp,
        IReadOnlyCollection<string> blockedCountries,
        CancellationToken ct = default)
    {
        if (!before.Reachable || string.IsNullOrWhiteSpace(before.PublicIp))
            return new(false, "", "", "", "", "IP پایه قبل از VPN معتبر نیست؛ Connected تأیید نشد.");

        var stop = DateTimeOffset.UtcNow + TimeSpan.FromSeconds(30);
        ConnectivitySnapshot after = new(false, "", "", "", DateTimeOffset.UtcNow);
        RouteEvidence route = new(false, true, "", "", "no route evidence");
        string adapter = "";
        var consecutive = 0;
        var nextRouteProbe = DateTimeOffset.MinValue;

        while (DateTimeOffset.UtcNow < stop)
        {
            ct.ThrowIfCancellationRequested();

            // PowerShell route inspection is intentionally throttled. Launching it
            // every 800 ms caused visible desktop stutter on low/mid-range systems.
            if (DateTimeOffset.UtcNow >= nextRouteProbe)
            {
                adapter = await Task.Run(FindTunnelAdapter, ct).ConfigureAwait(false);
                route = await DefaultRouteEvidenceAsync(ct).ConfigureAwait(false);
                nextRouteProbe = DateTimeOffset.UtcNow + TimeSpan.FromSeconds(2);
            }

            after = await ConnectivityProbe.SnapshotAsync(probeUrl, ct).ConfigureAwait(false);

            var adapterOk = adapter.Length > 0;
            var routeOk = route.Ipv4ThroughTunnel && route.Ipv6Safe;
            var ipChanged = after.Reachable && !string.IsNullOrWhiteSpace(after.PublicIp) &&
                !string.Equals(before.PublicIp, after.PublicIp, StringComparison.OrdinalIgnoreCase);
            var warpOk = !requireWarp || after.Warp.Equals("on", StringComparison.OrdinalIgnoreCase) || after.Warp.Equals("plus", StringComparison.OrdinalIgnoreCase);
            var countryBlocked = blockedCountries.Any(x => x.Equals(after.Country, StringComparison.OrdinalIgnoreCase));

            if (adapterOk && routeOk && ipChanged && warpOk && !countryBlocked)
            {
                consecutive++;
                if (consecutive >= 2)
                {
                    return new(true, after.PublicIp, after.Country, after.Warp, adapter,
                        $"tun={adapter}; v4={route.Ipv4Alias}; v6={route.Ipv6Alias}; ip={after.PublicIp}; loc={after.Country}; warp={after.Warp}");
                }
            }
            else consecutive = 0;

            await Task.Delay(850, ct).ConfigureAwait(false);
        }

        var reason = !after.Reachable ? "اینترنت از مسیر TUN پاسخ نداد"
            : string.IsNullOrWhiteSpace(adapter) ? "آداپتور BlueVPN TUN بالا نیامد"
            : !route.Ipv4ThroughTunnel ? $"مسیر پیش‌فرض IPv4 هنوز زیر VPN نیست ({route.Ipv4Alias})"
            : !route.Ipv6Safe ? $"IPv6 می‌تواند VPN را دور بزند ({route.Ipv6Alias})"
            : requireWarp && !(after.Warp.Equals("on", StringComparison.OrdinalIgnoreCase) || after.Warp.Equals("plus", StringComparison.OrdinalIgnoreCase)) ? "WARP در خروجی تأیید نشد"
            : blockedCountries.Any(x => x.Equals(after.Country, StringComparison.OrdinalIgnoreCase)) ? $"خروجی VPN در کشور مسدودشده {after.Country} است"
            : string.Equals(before.PublicIp, after.PublicIp, StringComparison.OrdinalIgnoreCase) ? "IP سیستم تغییر نکرد؛ اتصال به‌عنوان VPN پذیرفته نشد"
            : route.Detail;
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

    private static async Task<RouteEvidence> DefaultRouteEvidenceAsync(CancellationToken ct)
    {
        try
        {
            var script = @"
function Effective([object]$r,[string]$family) {
  $i = Get-NetIPInterface -InterfaceIndex $r.InterfaceIndex -AddressFamily $family -ErrorAction SilentlyContinue | Select-Object -First 1
  if ($i) { return ([int]$r.RouteMetric + [int]$i.InterfaceMetric) }
  return [int]$r.RouteMetric
}
$v4all = @(Get-NetRoute -AddressFamily IPv4 -ErrorAction SilentlyContinue | Where-Object { $_.DestinationPrefix -in @('0.0.0.0/0','0.0.0.0/1','128.0.0.0/1') })
$v6all = @(Get-NetRoute -AddressFamily IPv6 -ErrorAction SilentlyContinue | Where-Object { $_.DestinationPrefix -in @('::/0','::/1','8000::/1') })
$v4def = @($v4all | Where-Object DestinationPrefix -eq '0.0.0.0/0' | Sort-Object @{Expression={Effective $_ 'IPv4'}})[0]
$v6def = @($v6all | Where-Object DestinationPrefix -eq '::/0' | Sort-Object @{Expression={Effective $_ 'IPv6'}})[0]
$pat = 'BlueVPN|sing-box|Wintun'
$v4full = @($v4all | Where-Object { $_.DestinationPrefix -eq '0.0.0.0/0' -and $_.InterfaceAlias -match $pat }).Count -gt 0
$v4a = @($v4all | Where-Object { $_.DestinationPrefix -eq '0.0.0.0/1' -and $_.InterfaceAlias -match $pat }).Count -gt 0
$v4b = @($v4all | Where-Object { $_.DestinationPrefix -eq '128.0.0.0/1' -and $_.InterfaceAlias -match $pat }).Count -gt 0
$v6full = @($v6all | Where-Object { $_.DestinationPrefix -eq '::/0' -and $_.InterfaceAlias -match $pat }).Count -gt 0
$v6a = @($v6all | Where-Object { $_.DestinationPrefix -eq '::/1' -and $_.InterfaceAlias -match $pat }).Count -gt 0
$v6b = @($v6all | Where-Object { $_.DestinationPrefix -eq '8000::/1' -and $_.InterfaceAlias -match $pat }).Count -gt 0
$v6physical = $null -ne $v6def -and $v6def.InterfaceAlias -notmatch $pat
[pscustomobject]@{
  v4=if($v4def){$v4def.InterfaceAlias}else{''};
  v6=if($v6def){$v6def.InterfaceAlias}else{''};
  v4ok=($v4full -or ($v4a -and $v4b));
  v6safe=($v6full -or ($v6a -and $v6b) -or -not $v6physical)
} | ConvertTo-Json -Compress
";
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
            psi.ArgumentList.Add(script);
            using var p = Process.Start(psi);
            if (p is null) return new(false, false, "", "", "route inspection failed");
            var output = await p.StandardOutput.ReadToEndAsync(ct).ConfigureAwait(false);
            await p.WaitForExitAsync(ct).ConfigureAwait(false);
            if (string.IsNullOrWhiteSpace(output)) return new(false, false, "", "", "default route missing");
            using var doc = JsonDocument.Parse(output);
            var v4 = doc.RootElement.TryGetProperty("v4", out var v4e) ? v4e.GetString() ?? "" : "";
            var v6 = doc.RootElement.TryGetProperty("v6", out var v6e) ? v6e.GetString() ?? "" : "";
            var v4Tun = doc.RootElement.TryGetProperty("v4ok", out var v4ok) && v4ok.GetBoolean();
            var v6Safe = doc.RootElement.TryGetProperty("v6safe", out var v6ok) && v6ok.GetBoolean();
            return new(v4Tun, v6Safe, v4, v6, $"v4={v4}; v6={v6}; v4ok={v4Tun}; v6safe={v6Safe}");
        }
        catch (Exception ex) { return new(false, false, "", "", ex.Message); }
    }

    private sealed record RouteEvidence(bool Ipv4ThroughTunnel, bool Ipv6Safe, string Ipv4Alias, string Ipv6Alias, string Detail);
}
