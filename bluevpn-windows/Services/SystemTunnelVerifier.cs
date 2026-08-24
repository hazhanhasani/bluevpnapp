using System.Diagnostics;
using System.Net.NetworkInformation;
using System.Text.Json;
using BlueVPN.Windows.Models;

namespace BlueVPN.Windows.Services;

public static class SystemTunnelVerifier
{
    public static async Task<TunnelVerificationResult> VerifyAsync(
        ConnectivitySnapshot before,
        string probeUrl,
        bool requireWarp,
        IReadOnlyCollection<string> blockedCountries,
        string expectedTunnelName = "BlueVPN",
        CancellationToken ct = default)
    {
        if (!before.Reachable || string.IsNullOrWhiteSpace(before.PublicIp))
            return new(false, "", "", "", "", "IP پایه قبل از VPN معتبر نیست؛ Connected تأیید نشد.");

        var tunnelName = string.IsNullOrWhiteSpace(expectedTunnelName) ? "BlueVPN" : expectedTunnelName.Trim();
        // Wintun route installation is frequently slower on the first run (AV,
        // driver approval and Windows network classification all add latency).
        var stop = DateTimeOffset.UtcNow + TimeSpan.FromSeconds(12);
        ConnectivitySnapshot after = new(false, "", "", "", DateTimeOffset.UtcNow);
        RouteEvidence route = new(false, true, "", "", "no route evidence");
        string adapter = "";
        var consecutive = 0;
        var nextRouteProbe = DateTimeOffset.MinValue;

        while (DateTimeOffset.UtcNow < stop)
        {
            ct.ThrowIfCancellationRequested();
            if (DateTimeOffset.UtcNow >= nextRouteProbe)
            {
                adapter = await Task.Run(() => FindTunnelAdapter(tunnelName), ct).ConfigureAwait(false);
                route = await DefaultRouteEvidenceAsync(tunnelName, ct).ConfigureAwait(false);
                nextRouteProbe = DateTimeOffset.UtcNow + TimeSpan.FromMilliseconds(700);
            }

            after = requireWarp
                ? await ConnectivityProbe.SnapshotTraceAsync(probeUrl, ct).ConfigureAwait(false)
                : await ConnectivityProbe.SnapshotAsync(probeUrl, ct).ConfigureAwait(false);

            var adapterOk = adapter.Length > 0 || await TunnelProcessEvidenceAsync(ct).ConfigureAwait(false);
            // IPv4 route + changed public IP proves the user's primary Internet
            // path is going through BlueVPN. IPv6 safety stays diagnostic instead
            // of turning a healthy IPv4 tunnel into a false-negative on machines
            // where Windows keeps an unused physical IPv6 default route around.
            var routeOk = route.Ipv4ThroughTunnel;
            var ipChanged = after.Reachable && !string.IsNullOrWhiteSpace(after.PublicIp) &&
                !string.Equals(before.PublicIp, after.PublicIp, StringComparison.OrdinalIgnoreCase);
            var warpOk = !requireWarp || after.Warp.Equals("on", StringComparison.OrdinalIgnoreCase) || after.Warp.Equals("plus", StringComparison.OrdinalIgnoreCase);
            var countryBlocked = blockedCountries.Any(x => x.Equals(after.Country, StringComparison.OrdinalIgnoreCase));

            // A verified changed IPv4 egress through the TUN is authoritative.
            // A leftover physical IPv6 default route is diagnostic: requiring
            // it to disappear caused healthy IPv4 tunnels to be rejected on
            // otherwise common dual-stack Windows installations.
            if (adapterOk && routeOk && ipChanged && warpOk && !countryBlocked)
            {
                consecutive++;
                if (consecutive >= 2)
                    return new(true, after.PublicIp, after.Country, after.Warp, adapter,
                        $"tun={adapter}; v4={route.Ipv4Alias}; v6={route.Ipv6Alias}; v6safe={route.Ipv6Safe}; ip={after.PublicIp}; loc={after.Country}; warp={after.Warp}");
            }
            else consecutive = 0;
            await Task.Delay(180, ct).ConfigureAwait(false);
        }

        var reason = !after.Reachable ? "اینترنت از مسیر TUN پاسخ نداد"
            : string.IsNullOrWhiteSpace(adapter) ? $"آداپتور TUN اختصاصی {tunnelName} بالا نیامد"
            : !route.Ipv4ThroughTunnel ? $"مسیر IPv4 هنوز از BlueVPN عبور نمی‌کند ({route.Ipv4Alias})"
            : requireWarp && !(after.Warp.Equals("on", StringComparison.OrdinalIgnoreCase) || after.Warp.Equals("plus", StringComparison.OrdinalIgnoreCase)) ? "WARP در خروجی تأیید نشد"
            : blockedCountries.Any(x => x.Equals(after.Country, StringComparison.OrdinalIgnoreCase)) ? $"خروجی VPN در کشور مسدودشده {after.Country} است"
            : string.Equals(before.PublicIp, after.PublicIp, StringComparison.OrdinalIgnoreCase) ? "IP سیستم تغییر نکرد؛ مسیر TUN اعمال نشده است"
            : route.Detail;
        return new(false, after.PublicIp, after.Country, after.Warp, adapter, reason);
    }

    /// <summary>
    /// v2rayN-compatible fallback: if the local Xray proxy works but Windows TUN
    /// cannot be installed/routed on a particular machine, enable Windows system
    /// proxy and verify the actual public IP through the local HTTP inbound.
    /// </summary>
    public static async Task<TunnelVerificationResult> VerifySystemProxyAsync(
        ConnectivitySnapshot before,
        string probeUrl,
        int httpPort,
        CancellationToken ct = default)
    {
        if (!before.Reachable || string.IsNullOrWhiteSpace(before.PublicIp))
            return new(false, "", "", "", "", "IP پایه قبل از اتصال معتبر نیست.");

        var stop = DateTimeOffset.UtcNow + TimeSpan.FromSeconds(9);
        ConnectivitySnapshot after = new(false, "", "", "", DateTimeOffset.UtcNow);
        while (DateTimeOffset.UtcNow < stop)
        {
            ct.ThrowIfCancellationRequested();
            after = await ConnectivityProbe.SnapshotViaHttpProxyAsync(
                probeUrl, "127.0.0.1", httpPort, TimeSpan.FromSeconds(3), ct).ConfigureAwait(false);
            if (after.Reachable && !string.IsNullOrWhiteSpace(after.PublicIp) &&
                !string.Equals(before.PublicIp, after.PublicIp, StringComparison.OrdinalIgnoreCase))
            {
                return new(true, after.PublicIp, after.Country, after.Warp, "Windows System Proxy",
                    $"proxy=127.0.0.1:{httpPort}; ip={after.PublicIp}; baseline={before.PublicIp}");
            }
            await Task.Delay(300, ct).ConfigureAwait(false);
        }
        return new(false, after.PublicIp, after.Country, after.Warp, "Windows System Proxy",
            after.Reachable ? "IP از مسیر سازگار BlueVPN تغییر نکرد." : "مسیر سازگار BlueVPN اینترنت معتبر نداد.");
    }

    private static async Task<bool> TunnelProcessEvidenceAsync(CancellationToken ct)
    {
        try
        {
            var psi = new ProcessStartInfo
            {
                FileName = "powershell.exe", UseShellExecute = false, CreateNoWindow = true, RedirectStandardOutput = true
            };
            psi.ArgumentList.Add("-NoProfile");
            psi.ArgumentList.Add("-Command");
            psi.ArgumentList.Add("(Get-Process sing-box,xray,warp-plus -ErrorAction SilentlyContinue).Count -gt 0");
            using var p = Process.Start(psi);
            if (p == null) return false;
            var output = await p.StandardOutput.ReadToEndAsync(ct).ConfigureAwait(false);
            await p.WaitForExitAsync(ct).ConfigureAwait(false);
            return output.Trim().Equals("True", StringComparison.OrdinalIgnoreCase);
        }
        catch { return false; }
    }

    private static string FindTunnelAdapter(string expectedName)
    {
        try
        {
            foreach (var nic in NetworkInterface.GetAllNetworkInterfaces())
            {
                if (nic.OperationalStatus != OperationalStatus.Up) continue;
                if (string.Equals(nic.Name, expectedName, StringComparison.OrdinalIgnoreCase) ||
                    nic.Name.StartsWith(expectedName + " ", StringComparison.OrdinalIgnoreCase) ||
                    nic.Description.Contains(expectedName, StringComparison.OrdinalIgnoreCase) ||
                    nic.Name.Contains("Wintun", StringComparison.OrdinalIgnoreCase) ||
                    nic.Description.Contains("Wintun", StringComparison.OrdinalIgnoreCase) ||
                    nic.Name.Contains("sing-box", StringComparison.OrdinalIgnoreCase) ||
                    nic.Description.Contains("TUN", StringComparison.OrdinalIgnoreCase) ||
                    nic.Name.Contains("BlueVPN-TUN", StringComparison.OrdinalIgnoreCase) ||
                    nic.Description.Contains("WireGuard", StringComparison.OrdinalIgnoreCase)) return nic.Name;
            }
        }
        catch { }
        return "";
    }

    private static async Task<RouteEvidence> DefaultRouteEvidenceAsync(string expectedTunnelName, CancellationToken ct)
    {
        try
        {
            var escapedName = System.Text.RegularExpressions.Regex.Escape(expectedTunnelName.Replace("'", "''", StringComparison.Ordinal));
            var script = $@"
function Effective([object]$r,[string]$family) {{
  $i = Get-NetIPInterface -InterfaceIndex $r.InterfaceIndex -AddressFamily $family -ErrorAction SilentlyContinue | Select-Object -First 1
  if ($i) {{ return ([int]$r.RouteMetric + [int]$i.InterfaceMetric) }}
  return [int]$r.RouteMetric
}}
$pat = '^{escapedName}(?: [0-9]+)?$'
$v4all = @(Get-NetRoute -AddressFamily IPv4 -ErrorAction SilentlyContinue | Where-Object {{ $_.DestinationPrefix -in @('0.0.0.0/0','0.0.0.0/1','128.0.0.0/1') }})
$v6all = @(Get-NetRoute -AddressFamily IPv6 -ErrorAction SilentlyContinue | Where-Object {{ $_.DestinationPrefix -in @('::/0','::/1','8000::/1') }})
$v4def = @($v4all | Where-Object DestinationPrefix -eq '0.0.0.0/0' | Sort-Object @{{Expression={{Effective $_ 'IPv4'}}}})[0]
$v6def = @($v6all | Where-Object DestinationPrefix -eq '::/0' | Sort-Object @{{Expression={{Effective $_ 'IPv6'}}}})[0]
$v4full = @($v4all | Where-Object {{ $_.DestinationPrefix -eq '0.0.0.0/0' -and $_.InterfaceAlias -match $pat }}).Count -gt 0
$v4a = @($v4all | Where-Object {{ $_.DestinationPrefix -eq '0.0.0.0/1' -and $_.InterfaceAlias -match $pat }}).Count -gt 0
$v4b = @($v4all | Where-Object {{ $_.DestinationPrefix -eq '128.0.0.0/1' -and $_.InterfaceAlias -match $pat }}).Count -gt 0
$v6full = @($v6all | Where-Object {{ $_.DestinationPrefix -eq '::/0' -and $_.InterfaceAlias -match $pat }}).Count -gt 0
$v6a = @($v6all | Where-Object {{ $_.DestinationPrefix -eq '::/1' -and $_.InterfaceAlias -match $pat }}).Count -gt 0
$v6b = @($v6all | Where-Object {{ $_.DestinationPrefix -eq '8000::/1' -and $_.InterfaceAlias -match $pat }}).Count -gt 0
$v6physical = $null -ne $v6def -and $v6def.InterfaceAlias -notmatch $pat
[pscustomobject]@{{
  v4=if($v4def){{$v4def.InterfaceAlias}}else{{''}};
  v6=if($v6def){{$v6def.InterfaceAlias}}else{{''}};
  v4ok=($v4full -or ($v4a -and $v4b));
  v6safe=(($v6full -or ($v6a -and $v6b) -or -not $v6physical))
}} | ConvertTo-Json -Compress
";
            var psi = new ProcessStartInfo
            {
                FileName = "powershell.exe", UseShellExecute = false, CreateNoWindow = true,
                RedirectStandardOutput = true, RedirectStandardError = true
            };
            psi.ArgumentList.Add("-NoProfile");
            psi.ArgumentList.Add("-NonInteractive");
            psi.ArgumentList.Add("-Command");
            psi.ArgumentList.Add(script);
            using var p = Process.Start(psi);
            if (p is null) return new(false, false, "", "", "route inspection failed");
            using var timeout = CancellationTokenSource.CreateLinkedTokenSource(ct);
            timeout.CancelAfter(TimeSpan.FromSeconds(3));
            var output = await p.StandardOutput.ReadToEndAsync(timeout.Token).ConfigureAwait(false);
            await p.WaitForExitAsync(timeout.Token).ConfigureAwait(false);
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
