using System.Text.Json.Serialization;

namespace BlueVPN.Windows.Models;

public sealed record ConnectivitySnapshot(
    bool Reachable,
    string PublicIp,
    string Country,
    string Warp,
    DateTimeOffset CheckedAt,
    string Error = "");

public sealed record TunnelVerificationResult(
    bool Success,
    string PublicIp,
    string Country,
    string Warp,
    string AdapterName,
    string Detail);

public sealed class MobileConfigResponse
{
    [JsonPropertyName("advertising")] public AdvertisingConfig Advertising { get; set; } = new();
    [JsonPropertyName("ads")] public AdvertisingConfig Ads { get; set; } = new();
    [JsonPropertyName("free_story_ads")] public FreeStoryAdsConfig FreeStoryAds { get; set; } = new();
}

public sealed class AdvertisingConfig
{
    [JsonPropertyName("enabled")] public bool Enabled { get; set; }
    [JsonPropertyName("autoplay")] public bool Autoplay { get; set; } = true;
    [JsonPropertyName("loop")] public bool Loop { get; set; } = true;
    [JsonPropertyName("interval_ms")] public int IntervalMs { get; set; } = 6000;
    [JsonPropertyName("items")] public List<AdvertisementItem> Items { get; set; } = [];
}

public sealed class AdvertisementItem
{
    [JsonPropertyName("id")] public string Id { get; set; } = "";
    [JsonPropertyName("title")] public string Title { get; set; } = "";
    [JsonPropertyName("subtitle")] public string Subtitle { get; set; } = "";
    [JsonPropertyName("image_url")] public string ImageUrl { get; set; } = "";
    [JsonPropertyName("media_url")] public string MediaUrl { get; set; } = "";
    [JsonPropertyName("media_type")] public string MediaType { get; set; } = "image";
    [JsonPropertyName("target_action")] public string TargetAction { get; set; } = "";
    [JsonPropertyName("deep_link")] public string DeepLink { get; set; } = "";
    [JsonPropertyName("target_url")] public string TargetUrl { get; set; } = "";
    [JsonPropertyName("button_text")] public string ButtonText { get; set; } = "";
    [JsonPropertyName("image_duration_seconds")] public int ImageDurationSeconds { get; set; }
}

public sealed class FreeStoryAdsConfig
{
    [JsonPropertyName("enabled")] public bool Enabled { get; set; }
    [JsonPropertyName("required")] public bool Required { get; set; }
    [JsonPropertyName("free_only")] public bool FreeOnly { get; set; } = true;
    [JsonPropertyName("random")] public bool Random { get; set; } = true;
    [JsonPropertyName("every_connection")] public bool EveryConnection { get; set; } = true;
    [JsonPropertyName("image_duration_seconds")] public int ImageDurationSeconds { get; set; } = 6;
    [JsonPropertyName("load_timeout_ms")] public int LoadTimeoutMs { get; set; } = 5000;
    [JsonPropertyName("max_video_seconds")] public int MaxVideoSeconds { get; set; } = 15;
    [JsonPropertyName("items")] public List<AdvertisementItem> Items { get; set; } = [];
}

public sealed record RuntimeVersionInfo(string Version, string Source, string RootPath);

public sealed record UpdateCandidate(string Version, string DownloadUrl, string Digest, string AssetName, string ReleaseUrl);
