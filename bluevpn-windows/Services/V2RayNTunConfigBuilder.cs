using System.Text.Json;

namespace BlueVPN.Windows.Services;

/// <summary>
/// System-wide Windows TUN layer modeled after v2rayN's modern split-core design:
/// Xray owns the protocol session on localhost and sing-box owns the Windows TUN.
/// Traffic created by xray.exe is routed directly by sing-box to avoid a TUN loop;
/// every other non-private flow is sent to the local Xray SOCKS inbound.
/// </summary>
public static class V2RayNTunConfigBuilder
{
    public static string Build(AppSettings settings, int localSocksPort)
    {
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
                    auto_route = true,
                    strict_route = true,
                    stack = "system"
                }
            },
            outbounds = new object[]
            {
                new { type = "socks", tag = "xray-local", server = "127.0.0.1", server_port = localSocksPort, version = "5" },
                new { type = "direct", tag = "direct" },
                new { type = "block", tag = "block" }
            },
            route = new
            {
                auto_detect_interface = true,
                rules = new object[]
                {
                    // Critical loop guard: Xray's connection to the remote VPN server
                    // must leave on the physical NIC instead of re-entering BlueVPN TUN.
                    new { process_name = new[] { "xray.exe" }, action = "route", outbound = "direct" },
                    new { ip_is_private = true, action = "route", outbound = "direct" }
                },
                final = "xray-local"
            }
        };
        return JsonSerializer.Serialize(config, AppSettings.JsonOptions());
    }
}
