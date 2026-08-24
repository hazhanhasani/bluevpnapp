using System.Text.Json;

namespace BlueVPN.Windows.Services;

public static class SingBoxWarpConfigBuilder
{
    public static string Build(AppSettings settings, int socksPort = 1819, bool enableIpv6 = false)
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
                    address = enableIpv6 ? new[] { settings.Tun.GatewayV4, settings.Tun.GatewayV6 } : new[] { settings.Tun.GatewayV4 },
                    // 1361 is Cloudflare's recommended minimum IPv4 MASQUE MTU.
                    // It avoids common mobile/ISP fragmentation without the severe
                    // throughput loss of forcing the absolute QUIC minimum.
                    mtu = Math.Clamp(settings.Tun.Mtu, 1361, 1400),
                    auto_route = true,
                    strict_route = true,
                    stack = "system",
                    route_exclude_address = new[] { "127.0.0.0/8", "::1/128" }
                }
            },
            outbounds = new object[]
            {
                new { type = "socks", tag = "warp-socks", server = "127.0.0.1", server_port = socksPort, version = "5" },
                new { type = "direct", tag = "direct" }
            },
            route = new
            {
                auto_detect_interface = true,
                rules = new object[]
                {
                    // sing-box 1.13+: legacy inbound sniff fields were removed.
                    new { inbound = new[] { "bluevpn-tun" }, action = "sniff" },
                    new { process_name = new[] { "aether.exe" }, action = "route", outbound = "direct" },
                    new { ip_is_private = true, action = "route", outbound = "direct" }
                },
                final = "warp-socks"
            }
        };
        return JsonSerializer.Serialize(config, AppSettings.JsonOptions());
    }
}
