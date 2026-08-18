using System.Text.Json;
using System.Text.Json.Serialization;

namespace BlueVPN.Windows.Services;

public sealed class AppSettings
{
    [JsonPropertyName("app_name")] public string AppName { get; set; } = "BlueVPN";
    [JsonPropertyName("version")] public string Version { get; set; } = "";
    [JsonPropertyName("api_base_url")] public string ApiBaseUrl { get; set; } = "";
    [JsonPropertyName("free_subscription_path")] public string FreeSubscriptionPath { get; set; } = "/wp-json/bluevpn/v1/free/curated?limit=100";
    [JsonPropertyName("probe_url")] public string ProbeUrl { get; set; } = "https://www.cloudflare.com/cdn-cgi/trace";
    [JsonPropertyName("xray_version")] public string XrayVersion { get; set; } = "";
    [JsonPropertyName("tun")] public TunSettings Tun { get; set; } = new();

    public static AppSettings Load()
    {
        var path = Path.Combine(AppContext.BaseDirectory, "appsettings.json");
        var json = File.ReadAllText(path);
        return JsonSerializer.Deserialize<AppSettings>(json, JsonOptions())
            ?? throw new InvalidOperationException("BlueVPN appsettings.json is invalid.");
    }

    public static JsonSerializerOptions JsonOptions() => new()
    {
        PropertyNameCaseInsensitive = true,
        WriteIndented = true
    };
}

public sealed class TunSettings
{
    [JsonPropertyName("name")] public string Name { get; set; } = "BlueVPN";
    [JsonPropertyName("mtu")] public int Mtu { get; set; } = 1400;
    [JsonPropertyName("gateway_v4")] public string GatewayV4 { get; set; } = "10.66.0.1/24";
    [JsonPropertyName("gateway_v6")] public string GatewayV6 { get; set; } = "fd66::1/64";
    [JsonPropertyName("dns_v4")] public string DnsV4 { get; set; } = "1.1.1.1";
    [JsonPropertyName("dns_v6")] public string DnsV6 { get; set; } = "2606:4700:4700::1111";
}
