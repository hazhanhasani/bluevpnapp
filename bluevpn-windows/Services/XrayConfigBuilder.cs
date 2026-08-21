using System.Text.Json;
using BlueVPN.Windows.Models;

namespace BlueVPN.Windows.Services;

public static class XrayConfigBuilder
{
    public const int LocalSocksPort = 20808;
    public const int LocalHttpPort = 20809;

    public static string Build(ProxyEndpoint endpoint, AppSettings settings)
    {
        // BlueVPN Windows follows v2rayN's split-core TUN approach: Xray handles
        // protocol/transport and exposes localhost SOCKS; sing-box owns the TUN.
        var root = new Dictionary<string, object?>
        {
            ["log"] = new Dictionary<string, object?> { ["loglevel"] = "warning" },
            ["inbounds"] = new object[]
            {
                new Dictionary<string, object?>
                {
                    ["tag"] = "bluevpn-local-socks",
                    ["listen"] = "127.0.0.1",
                    ["port"] = LocalSocksPort,
                    ["protocol"] = "socks",
                    ["settings"] = new Dictionary<string, object?>
                    {
                        ["auth"] = "noauth",
                        ["udp"] = true,
                        ["ip"] = "127.0.0.1"
                    },
                    ["sniffing"] = new Dictionary<string, object?>
                    {
                        ["enabled"] = true,
                        ["destOverride"] = new[] { "http", "tls", "quic" },
                        ["routeOnly"] = true
                    }
                },
                new Dictionary<string, object?>
                {
                    ["tag"] = "bluevpn-local-http",
                    ["listen"] = "127.0.0.1",
                    ["port"] = LocalHttpPort,
                    ["protocol"] = "http",
                    ["settings"] = new Dictionary<string, object?>(),
                    ["sniffing"] = new Dictionary<string, object?>
                    {
                        ["enabled"] = true,
                        ["destOverride"] = new[] { "http", "tls" },
                        ["routeOnly"] = true
                    }
                }
            },
            ["outbounds"] = new object[]
            {
                BuildProxyOutbound(endpoint),
                new Dictionary<string, object?>
                {
                    ["tag"] = "direct", ["protocol"] = "freedom",
                    ["settings"] = new Dictionary<string, object?>()
                },
                new Dictionary<string, object?>
                {
                    ["tag"] = "blocked", ["protocol"] = "blackhole",
                    ["settings"] = new Dictionary<string, object?>()
                }
            },
            ["routing"] = new Dictionary<string, object?>
            {
                ["domainStrategy"] = "IPIfNonMatch",
                ["rules"] = new object[]
                {
                    new Dictionary<string, object?>
                    {
                        ["type"] = "field", ["ip"] = new[] { "geoip:private" }, ["outboundTag"] = "direct"
                    }
                }
            }
        };
        return JsonSerializer.Serialize(root, AppSettings.JsonOptions());
    }

    private static Dictionary<string, object?> BuildProxyOutbound(ProxyEndpoint endpoint)
    {
        var outbound = endpoint.Protocol switch
        {
            "vless" => BuildVless(endpoint),
            "vmess" => BuildVmess(endpoint),
            "trojan" => BuildTrojan(endpoint),
            "shadowsocks" => BuildShadowsocks(endpoint),
            _ => throw new NotSupportedException($"پروتکل {endpoint.Protocol} در نسخه ویندوز پشتیبانی نمی‌شود.")
        };
        outbound["tag"] = "proxy";

        if (endpoint.Protocol is not "shadowsocks")
            outbound["streamSettings"] = BuildStreamSettings(endpoint);
        return outbound;
    }

    private static Dictionary<string, object?> BuildVless(ProxyEndpoint e) => new()
    {
        ["protocol"] = "vless",
        ["settings"] = new Dictionary<string, object?>
        {
            ["vnext"] = new object[]
            {
                new Dictionary<string, object?>
                {
                    ["address"] = e.Host,
                    ["port"] = e.Port,
                    ["users"] = new object[]
                    {
                        new Dictionary<string, object?>
                        {
                            ["id"] = e.UserId,
                            ["encryption"] = string.IsNullOrWhiteSpace(e.Encryption) ? "none" : e.Encryption,
                            ["flow"] = e.Flow
                        }
                    }
                }
            }
        }
    };

    private static Dictionary<string, object?> BuildVmess(ProxyEndpoint e) => new()
    {
        ["protocol"] = "vmess",
        ["settings"] = new Dictionary<string, object?>
        {
            ["vnext"] = new object[]
            {
                new Dictionary<string, object?>
                {
                    ["address"] = e.Host,
                    ["port"] = e.Port,
                    ["users"] = new object[]
                    {
                        new Dictionary<string, object?>
                        {
                            ["id"] = e.UserId,
                            ["alterId"] = e.AlterId,
                            ["security"] = string.IsNullOrWhiteSpace(e.Encryption) ? "auto" : e.Encryption
                        }
                    }
                }
            }
        }
    };

    private static Dictionary<string, object?> BuildTrojan(ProxyEndpoint e) => new()
    {
        ["protocol"] = "trojan",
        ["settings"] = new Dictionary<string, object?>
        {
            ["servers"] = new object[]
            {
                new Dictionary<string, object?>
                {
                    ["address"] = e.Host,
                    ["port"] = e.Port,
                    ["password"] = e.Password
                }
            }
        }
    };

    private static Dictionary<string, object?> BuildShadowsocks(ProxyEndpoint e) => new()
    {
        ["protocol"] = "shadowsocks",
        ["settings"] = new Dictionary<string, object?>
        {
            ["servers"] = new object[]
            {
                new Dictionary<string, object?>
                {
                    ["address"] = e.Host,
                    ["port"] = e.Port,
                    ["method"] = e.Method,
                    ["password"] = e.Password
                }
            }
        }
    };

    private static Dictionary<string, object?> BuildStreamSettings(ProxyEndpoint e)
    {
        var stream = new Dictionary<string, object?>
        {
            ["network"] = e.Transport,
            ["security"] = string.IsNullOrWhiteSpace(e.Security) ? "none" : e.Security
        };

        if (e.Security.Equals("tls", StringComparison.OrdinalIgnoreCase))
        {
            stream["tlsSettings"] = new Dictionary<string, object?>
            {
                ["serverName"] = string.IsNullOrWhiteSpace(e.Sni) ? e.Host : e.Sni,
                ["fingerprint"] = e.Fingerprint,
                ["alpn"] = e.Alpn
            };
        }
        else if (e.Security.Equals("reality", StringComparison.OrdinalIgnoreCase))
        {
            stream["realitySettings"] = new Dictionary<string, object?>
            {
                ["serverName"] = string.IsNullOrWhiteSpace(e.Sni) ? e.Host : e.Sni,
                ["fingerprint"] = e.Fingerprint,
                ["publicKey"] = e.PublicKey,
                ["shortId"] = e.ShortId,
                ["spiderX"] = e.SpiderX
            };
        }

        switch (e.Transport)
        {
            case "ws":
                stream["wsSettings"] = new Dictionary<string, object?>
                {
                    ["path"] = string.IsNullOrWhiteSpace(e.Path) ? "/" : e.Path,
                    ["headers"] = string.IsNullOrWhiteSpace(e.HostHeader)
                        ? new Dictionary<string, string>()
                        : new Dictionary<string, string> { ["Host"] = e.HostHeader }
                };
                break;
            case "grpc":
                stream["grpcSettings"] = new Dictionary<string, object?>
                {
                    ["serviceName"] = e.ServiceName,
                    ["multiMode"] = e.Mode.Equals("multi", StringComparison.OrdinalIgnoreCase)
                };
                break;
            case "xhttp":
                stream["xhttpSettings"] = new Dictionary<string, object?>
                {
                    ["path"] = string.IsNullOrWhiteSpace(e.Path) ? "/" : e.Path,
                    ["host"] = e.HostHeader,
                    ["mode"] = e.Mode
                };
                break;
            case "httpupgrade":
                stream["httpupgradeSettings"] = new Dictionary<string, object?>
                {
                    ["path"] = string.IsNullOrWhiteSpace(e.Path) ? "/" : e.Path,
                    ["host"] = e.HostHeader
                };
                break;
            case "http":
                stream["httpSettings"] = new Dictionary<string, object?>
                {
                    ["path"] = string.IsNullOrWhiteSpace(e.Path) ? "/" : e.Path,
                    ["host"] = string.IsNullOrWhiteSpace(e.HostHeader) ? Array.Empty<string>() : new[] { e.HostHeader }
                };
                break;
        }

        return stream;
    }
}
