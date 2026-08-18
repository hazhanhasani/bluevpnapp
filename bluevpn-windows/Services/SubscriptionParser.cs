using System.Text;
using System.Text.Json;
using BlueVPN.Windows.Models;

namespace BlueVPN.Windows.Services;

public static class SubscriptionParser
{
    public static IReadOnlyList<ProxyEndpoint> Parse(string raw)
    {
        var text = NormalizeSubscription(raw);
        var result = new List<ProxyEndpoint>();
        foreach (var line in text.Replace("\r", "").Split('\n', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries))
        {
            try
            {
                var endpoint = ParseLine(line);
                if (endpoint is not null && endpoint.Port is > 0 and <= 65535 && !string.IsNullOrWhiteSpace(endpoint.Host))
                    result.Add(endpoint);
            }
            catch
            {
                // A malformed URI must never block the rest of the subscription.
            }
        }
        return result
            .GroupBy(x => $"{x.Protocol}|{x.Host}|{x.Port}|{x.UserId}|{x.Password}", StringComparer.OrdinalIgnoreCase)
            .Select(g => g.First())
            .ToList();
    }

    private static ProxyEndpoint? ParseLine(string line)
    {
        if (line.StartsWith("vless://", StringComparison.OrdinalIgnoreCase)) return ParseVless(line);
        if (line.StartsWith("trojan://", StringComparison.OrdinalIgnoreCase)) return ParseTrojan(line);
        if (line.StartsWith("vmess://", StringComparison.OrdinalIgnoreCase)) return ParseVmess(line);
        if (line.StartsWith("ss://", StringComparison.OrdinalIgnoreCase)) return ParseShadowsocks(line);
        return null;
    }

    private static ProxyEndpoint ParseVless(string line)
    {
        var uri = new Uri(line);
        var query = ParseQuery(uri.Query);
        return new ProxyEndpoint
        {
            Protocol = "vless",
            Name = Uri.UnescapeDataString(uri.Fragment.TrimStart('#')),
            Host = uri.Host,
            Port = uri.Port,
            UserId = Uri.UnescapeDataString(uri.UserInfo),
            Encryption = Get(query, "encryption", "none"),
            Flow = Get(query, "flow"),
            Transport = NormalizeTransport(Get(query, "type", "tcp")),
            Security = Get(query, "security", "none"),
            Sni = Get(query, "sni"),
            HostHeader = Get(query, "host"),
            Path = Get(query, "path"),
            ServiceName = Get(query, "serviceName"),
            Fingerprint = Get(query, "fp", "chrome"),
            PublicKey = Get(query, "pbk"),
            ShortId = Get(query, "sid"),
            SpiderX = Get(query, "spx"),
            Mode = Get(query, "mode"),
            Alpn = SplitCsv(Get(query, "alpn"))
        };
    }

    private static ProxyEndpoint ParseTrojan(string line)
    {
        var uri = new Uri(line);
        var query = ParseQuery(uri.Query);
        return new ProxyEndpoint
        {
            Protocol = "trojan",
            Name = Uri.UnescapeDataString(uri.Fragment.TrimStart('#')),
            Host = uri.Host,
            Port = uri.Port,
            Password = Uri.UnescapeDataString(uri.UserInfo),
            Transport = NormalizeTransport(Get(query, "type", "tcp")),
            Security = Get(query, "security", "tls"),
            Sni = Get(query, "sni"),
            HostHeader = Get(query, "host"),
            Path = Get(query, "path"),
            ServiceName = Get(query, "serviceName"),
            Fingerprint = Get(query, "fp", "chrome"),
            PublicKey = Get(query, "pbk"),
            ShortId = Get(query, "sid"),
            SpiderX = Get(query, "spx"),
            Mode = Get(query, "mode"),
            Alpn = SplitCsv(Get(query, "alpn"))
        };
    }

    private static ProxyEndpoint ParseVmess(string line)
    {
        var payload = line["vmess://".Length..].Trim();
        var json = Encoding.UTF8.GetString(Convert.FromBase64String(PadBase64(payload)));
        using var doc = JsonDocument.Parse(json);
        var root = doc.RootElement;
        string S(string key, string fallback = "") => root.TryGetProperty(key, out var value) ? value.ToString() : fallback;
        int I(string key, int fallback = 0) => int.TryParse(S(key), out var value) ? value : fallback;

        return new ProxyEndpoint
        {
            Protocol = "vmess",
            Name = S("ps"),
            Host = S("add"),
            Port = I("port"),
            UserId = S("id"),
            AlterId = I("aid"),
            Encryption = S("scy", "auto"),
            Transport = NormalizeTransport(S("net", "tcp")),
            Security = S("tls", "none"),
            Sni = S("sni"),
            HostHeader = S("host"),
            Path = S("path"),
            ServiceName = S("path"),
            Fingerprint = S("fp", "chrome"),
            Alpn = SplitCsv(S("alpn"))
        };
    }

    private static ProxyEndpoint ParseShadowsocks(string line)
    {
        var payload = line["ss://".Length..];
        var fragmentIndex = payload.IndexOf('#');
        var name = fragmentIndex >= 0 ? Uri.UnescapeDataString(payload[(fragmentIndex + 1)..]) : "";
        if (fragmentIndex >= 0) payload = payload[..fragmentIndex];
        var queryIndex = payload.IndexOf('?');
        if (queryIndex >= 0) payload = payload[..queryIndex];

        string credentials;
        string hostPort;
        if (payload.Contains('@'))
        {
            var at = payload.LastIndexOf('@');
            credentials = payload[..at];
            hostPort = payload[(at + 1)..];
            if (!credentials.Contains(':'))
                credentials = Encoding.UTF8.GetString(Convert.FromBase64String(PadBase64(credentials)));
        }
        else
        {
            var decoded = Encoding.UTF8.GetString(Convert.FromBase64String(PadBase64(payload)));
            var at = decoded.LastIndexOf('@');
            if (at < 0) throw new FormatException("Invalid Shadowsocks URI");
            credentials = decoded[..at];
            hostPort = decoded[(at + 1)..];
        }

        var colon = credentials.IndexOf(':');
        if (colon <= 0) throw new FormatException("Invalid Shadowsocks credentials");
        var (host, port) = ParseHostPort(hostPort);
        return new ProxyEndpoint
        {
            Protocol = "shadowsocks",
            Name = name,
            Host = host,
            Port = port,
            Method = Uri.UnescapeDataString(credentials[..colon]),
            Password = Uri.UnescapeDataString(credentials[(colon + 1)..])
        };
    }

    private static string NormalizeSubscription(string raw)
    {
        var text = raw.Trim();
        if (text.Contains("://", StringComparison.Ordinal)) return text;
        try
        {
            var decoded = Encoding.UTF8.GetString(Convert.FromBase64String(PadBase64(text)));
            return decoded.Contains("://", StringComparison.Ordinal) ? decoded : text;
        }
        catch { return text; }
    }

    private static Dictionary<string, string> ParseQuery(string query)
    {
        var result = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
        foreach (var part in query.TrimStart('?').Split('&', StringSplitOptions.RemoveEmptyEntries))
        {
            var index = part.IndexOf('=');
            var key = Uri.UnescapeDataString(index >= 0 ? part[..index] : part);
            var value = Uri.UnescapeDataString(index >= 0 ? part[(index + 1)..] : "");
            result[key] = value;
        }
        return result;
    }

    private static string Get(IReadOnlyDictionary<string, string> source, string key, string fallback = "") =>
        source.TryGetValue(key, out var value) && !string.IsNullOrWhiteSpace(value) ? value : fallback;

    private static string NormalizeTransport(string value) => value.ToLowerInvariant() switch
    {
        "http" => "http",
        "h2" => "http",
        "ws" => "ws",
        "grpc" => "grpc",
        "xhttp" => "xhttp",
        "splithttp" => "xhttp",
        "httpupgrade" => "httpupgrade",
        "kcp" => "kcp",
        "quic" => "quic",
        _ => "tcp"
    };

    private static IReadOnlyList<string> SplitCsv(string value) => value
        .Split(',', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries)
        .ToList();

    private static string PadBase64(string value)
    {
        var normalized = value.Replace('-', '+').Replace('_', '/');
        return normalized.PadRight(normalized.Length + ((4 - normalized.Length % 4) % 4), '=');
    }

    private static (string Host, int Port) ParseHostPort(string value)
    {
        if (value.StartsWith('['))
        {
            var end = value.IndexOf(']');
            if (end < 0 || end + 2 > value.Length) throw new FormatException("Invalid IPv6 endpoint");
            return (value[1..end], int.Parse(value[(end + 2)..]));
        }
        var colon = value.LastIndexOf(':');
        if (colon <= 0) throw new FormatException("Invalid endpoint");
        return (value[..colon], int.Parse(value[(colon + 1)..]));
    }
}
