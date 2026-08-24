using System.Text.Json;

namespace BlueVPN.Windows.Services;

/// <summary>
/// System-wide Windows TUN layer modeled after v2rayN's split-core design:
/// Xray owns the protocol session on localhost and sing-box owns the Windows TUN.
/// Remote server addresses are also routed directly so loop prevention does not
/// depend solely on Windows process attribution.
/// </summary>
public static class V2RayNTunConfigBuilder
{
    public static string Build(AppSettings settings, int localSocksPort, string remoteHost, IReadOnlyList<string> remoteIps, string stack = "mixed")
    {
        var rules = new List<object>();
        // sing-box 1.13 removed inbound.sniff; sniffing is now a route action.
        rules.Add(new { inbound = new[] { "bluevpn-tun" }, action = "sniff" });

        var ipCidrs = remoteIps
            .Where(x => System.Net.IPAddress.TryParse(x, out _))
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .Select(x => x.Contains(':') ? x + "/128" : x + "/32")
            .ToArray();
        var routeExclusions = ipCidrs
            .Concat(new[] { "127.0.0.0/8", "::1/128" })
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .ToArray();
        if (ipCidrs.Length > 0)
            rules.Add(new { ip_cidr = ipCidrs, action = "route", outbound = "direct" });

        if (!string.IsNullOrWhiteSpace(remoteHost) && !System.Net.IPAddress.TryParse(remoteHost, out _))
            rules.Add(new { domain = new[] { remoteHost }, action = "route", outbound = "direct" });

        // Keep process-based protection as a secondary guard, not the only one.
        rules.Add(new { process_name = new[] { "xray.exe" }, action = "route", outbound = "direct" });
        rules.Add(new { ip_is_private = true, action = "route", outbound = "direct" });

        var config = new
        {
            log = new { level = "warn", timestamp = true },
            inbounds = new object[]
            {
                new
                {
                    type = "tun",
                    tag = "bluevpn-tun",
                    interface_name = settings.Tun.Name,
                    address = new[] { settings.Tun.GatewayV4, settings.Tun.GatewayV6 },
                    mtu = settings.Tun.Mtu,
                    endpoint_independent_nat = true,
                    auto_route = true,
                    // auto_route owns the default route. strict_route is enabled so Windows
                    // multi-homed DNS/IPv6 traffic cannot bypass the BlueVPN TUN.
                    strict_route = true,
                    stack,
                    route_exclude_address = routeExclusions
                }
            },
            outbounds = new object[]
            {
                new { type = "socks", tag = "xray-local", server = "127.0.0.1", server_port = localSocksPort, version = "5", udp_fragment = true },
                new { type = "direct", tag = "direct" }
            },
            route = new
            {
                auto_detect_interface = true,
                rules = rules.ToArray(),
                final = "xray-local"
            }
        };
        return JsonSerializer.Serialize(config, AppSettings.JsonOptions());
    }
}
