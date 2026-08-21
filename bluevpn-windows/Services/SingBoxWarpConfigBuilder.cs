using System.Text.Json;

namespace BlueVPN.Windows.Services;

public static class SingBoxWarpConfigBuilder
{
    public static string Build(AppSettings settings, int socksPort = 1819)
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
                    strict_route = false,
                    stack = "system",
                    sniff = true,
                    route_exclude_address = new[] { "127.0.0.0/8", "::1/128" }
                }
            },
            outbounds = new object[]
            {
                new { type = "socks", tag = "warp-socks", server = "127.0.0.1", server_port = socksPort, version = "5" },
                new { type = "direct", tag = "direct" },
                new { type = "block", tag = "block" }
            },
            route = new
            {
                auto_detect_interface = true,
                rules = new object[]
                {
                    new { process_name = new[] { "aether.exe" }, action = "route", outbound = "direct" },
                    new { ip_is_private = true, action = "route", outbound = "direct" }
                },
                final = "warp-socks"
            }
        };
        return JsonSerializer.Serialize(config, AppSettings.JsonOptions());
    }
}
