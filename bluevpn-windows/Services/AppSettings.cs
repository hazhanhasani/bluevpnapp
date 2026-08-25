using System.IO;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace BlueVPN.Windows.Services;

public sealed class AppSettings
{
    [JsonPropertyName("app_name")] public string AppName { get; set; } = "BlueVPN";
    [JsonPropertyName("version")] public string Version { get; set; } = "";
    [JsonPropertyName("api_base_url")] public string ApiBaseUrl { get; set; } = "";
    [JsonPropertyName("api_base_urls")] public List<string> ApiBaseUrls { get; set; } = [];
    [JsonPropertyName("free_subscription_path")] public string FreeSubscriptionPath { get; set; } = "/wp-json/bluevpn/v1/free/curated?limit=100";
    [JsonPropertyName("mobile_config_path")] public string MobileConfigPath { get; set; } = "/wp-json/bluevpn/v1/mobile/config";
    [JsonPropertyName("windows_update_path")] public string WindowsUpdatePath { get; set; } = "/wp-json/bluevpn/v1/windows/update";
    [JsonPropertyName("probe_url")] public string ProbeUrl { get; set; } = "https://www.cloudflare.com/cdn-cgi/trace";
    [JsonPropertyName("v2rayn_version")] public string V2RayNVersion { get; set; } = "7.24.4";
    [JsonPropertyName("v2rayn_repository")] public string V2RayNRepository { get; set; } = "2dust/v2rayN";
    [JsonPropertyName("windows_update_repository")] public string WindowsUpdateRepository { get; set; } = "hazhanhasani/bluevpnapp";
    [JsonPropertyName("windows_release_prefix")] public string WindowsReleasePrefix { get; set; } = "bluevpn-windows-v";
    [JsonPropertyName("auto_update")] public bool AutoUpdate { get; set; } = true;
    [JsonPropertyName("auto_update_runtime")] public bool AutoUpdateRuntime { get; set; } = true;
    [JsonPropertyName("windows_channel")] public string WindowsChannel { get; set; } = "beta";
    [JsonPropertyName("warp")] public WarpSettings Warp { get; set; } = new();
    [JsonPropertyName("tun")] public TunSettings Tun { get; set; } = new();

    public static AppSettings Load()
    {
        var path = Path.Combine(AppContext.BaseDirectory, "appsettings.json");
        var json = File.ReadAllText(path);
        return JsonSerializer.Deserialize<AppSettings>(json, JsonOptions())
            ?? throw new InvalidOperationException("BlueVPN appsettings.json is invalid.");
    }

    public IReadOnlyList<string> ControlPlaneBases() => ApiBaseUrls
        .Prepend(ApiBaseUrl)
        .Select(value => value?.Trim().TrimEnd('/') ?? "")
        .Where(value => Uri.TryCreate(value, UriKind.Absolute, out var uri) && uri.Scheme == Uri.UriSchemeHttps)
        .Distinct(StringComparer.OrdinalIgnoreCase)
        .ToArray();

    public static JsonSerializerOptions JsonOptions() => new()
    {
        PropertyNameCaseInsensitive = true,
        WriteIndented = true
    };
}

public sealed class WarpSettings
{
    [JsonPropertyName("enabled")] public bool Enabled { get; set; } = true;
    [JsonPropertyName("reject_ir_exit")] public bool RejectIrExit { get; set; } = true;
    [JsonPropertyName("fallback_to_free_pool")] public bool FallbackToFreePool { get; set; } = true;
    [JsonPropertyName("socks_port")] public int SocksPort { get; set; } = 1819;
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
