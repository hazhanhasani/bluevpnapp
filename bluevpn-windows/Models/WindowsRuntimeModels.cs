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
    [JsonPropertyName("tapsell")] public TapsellConfig Tapsell { get; set; } = new();
    [JsonPropertyName("free_access")] public FreeAccessConfig FreeAccess { get; set; } = new();
    [JsonPropertyName("blueai")] public BlueAiRuntimeConfig BlueAi { get; set; } = new();
}

public sealed class BlueAiRuntimeConfig
{
    [JsonPropertyName("enabled")] public bool Enabled { get; set; } = true;
    [JsonPropertyName("free_enabled")] public bool FreeEnabled { get; set; } = true;
    [JsonPropertyName("premium_enabled")] public bool PremiumEnabled { get; set; } = true;
    [JsonPropertyName("collective")] public bool Collective { get; set; } = true;
    [JsonPropertyName("auto_heal")] public bool AutoHeal { get; set; } = true;
    [JsonPropertyName("shadow_mode")] public bool ShadowMode { get; set; } = true;
    [JsonPropertyName("predictive_failover")] public bool PredictiveFailover { get; set; } = true;
    [JsonPropertyName("anomaly_detection")] public bool AnomalyDetection { get; set; } = true;
    [JsonPropertyName("engine_version")] public string EngineVersion { get; set; } = "";
    [JsonPropertyName("schema_version")] public int SchemaVersion { get; set; }
}

public sealed record TunnelProbeMeasurement(
    bool Success,
    int LatencyMs,
    ConnectivitySnapshot Snapshot,
    string Source);

public sealed class AdvertisingConfig
{
    [JsonPropertyName("enabled")] public bool Enabled { get; set; }
    [JsonPropertyName("autoplay")] public bool Autoplay { get; set; } = true;
    [JsonPropertyName("loop")] public bool Loop { get; set; } = true;
    [JsonPropertyName("interval_ms")] public int IntervalMs { get; set; } = 6000;
    [JsonPropertyName("height_dp")] public int HeightDp { get; set; } = 146;
    [JsonPropertyName("aspect_ratio")] public string AspectRatio { get; set; } = "20:9";
    [JsonPropertyName("disabled_reason")] public string DisabledReason { get; set; } = "";
    [JsonPropertyName("items")] public List<AdvertisementItem> Items { get; set; } = [];
}

public sealed class AdvertisementItem
{
    [JsonPropertyName("id")] public string Id { get; set; } = "";
    [JsonPropertyName("title")] public string Title { get; set; } = "";
    [JsonPropertyName("subtitle")] public string Subtitle { get; set; } = "";
    [JsonPropertyName("image_url")] public string ImageUrl { get; set; } = "";
    [JsonPropertyName("image_path")] public string ImagePath { get; set; } = "";
    [JsonPropertyName("media_url")] public string MediaUrl { get; set; } = "";
    [JsonPropertyName("media_type")] public string MediaType { get; set; } = "image";
    [JsonPropertyName("target_action")] public string TargetAction { get; set; } = "";
    [JsonPropertyName("target_plan_id")] public int TargetPlanId { get; set; }
    [JsonPropertyName("deep_link")] public string DeepLink { get; set; } = "";
    [JsonPropertyName("target_url")] public string TargetUrl { get; set; } = "";
    [JsonPropertyName("button_text")] public string ButtonText { get; set; } = "";
    [JsonPropertyName("weight")] public int Weight { get; set; } = 1;
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


public sealed class TapsellConfig
{
    [JsonPropertyName("enabled")] public bool Enabled { get; set; }
    [JsonPropertyName("sdk")] public string Sdk { get; set; } = "";
    [JsonPropertyName("sdk_version")] public string SdkVersion { get; set; } = "";
    [JsonPropertyName("app_id")] public string AppId { get; set; } = "";
    [JsonPropertyName("show_after_connect")] public bool ShowAfterConnect { get; set; }
    [JsonPropertyName("free_only")] public bool FreeOnly { get; set; } = true;
    [JsonPropertyName("disabled_reason")] public string DisabledReason { get; set; } = "";
    [JsonPropertyName("placements")] public Dictionary<string, TapsellPlacementConfig> Placements { get; set; } = new(StringComparer.OrdinalIgnoreCase);
    [JsonPropertyName("windows_web")] public TapsellWindowsWebConfig WindowsWeb { get; set; } = new();
}

public sealed class TapsellWindowsWebConfig
{
    [JsonPropertyName("schema_version")] public int SchemaVersion { get; set; } = 1;
    [JsonPropertyName("publisher_host")] public string PublisherHost { get; set; } = "";
    [JsonPropertyName("master_enabled")] public bool MasterEnabled { get; set; }
    [JsonPropertyName("enabled_placement_count")] public int EnabledPlacementCount { get; set; }
    [JsonPropertyName("placements")] public Dictionary<string, TapsellWindowsWebPlacementConfig> Placements { get; set; } = new(StringComparer.OrdinalIgnoreCase);
    [JsonPropertyName("enabled")] public bool Enabled { get; set; }
    [JsonPropertyName("placement_id")] public string PlacementId { get; set; } = "";
    [JsonPropertyName("script_html")] public string ScriptHtml { get; set; } = "";
    [JsonPropertyName("bridge_url")] public string BridgeUrl { get; set; } = "";
    [JsonPropertyName("free_only")] public bool FreeOnly { get; set; }
    [JsonPropertyName("min_interval_seconds")] public int MinIntervalSeconds { get; set; } = 300;
    [JsonPropertyName("daily_cap")] public int DailyCap { get; set; } = 10;
    [JsonPropertyName("every_slides")] public int EverySlides { get; set; } = 3;
    [JsonPropertyName("height")] public int Height { get; set; } = 146;
}

public sealed class TapsellWindowsWebPlacementConfig
{
    [JsonPropertyName("enabled")] public bool Enabled { get; set; }
    [JsonPropertyName("placement_id")] public string PlacementId { get; set; } = "";
    [JsonPropertyName("bridge_url")] public string BridgeUrl { get; set; } = "";
    [JsonPropertyName("publisher_host")] public string PublisherHost { get; set; } = "";
    [JsonPropertyName("surface")] public string Surface { get; set; } = "";
    [JsonPropertyName("render_mode")] public string RenderMode { get; set; } = "";
    [JsonPropertyName("min_interval_seconds")] public int MinIntervalSeconds { get; set; }
    [JsonPropertyName("daily_cap")] public int DailyCap { get; set; }
    [JsonPropertyName("free_only")] public bool FreeOnly { get; set; }
}

public sealed class TapsellPlacementConfig
{
    [JsonPropertyName("enabled")] public bool Enabled { get; set; }
    [JsonPropertyName("zone_id")] public string ZoneId { get; set; } = "";
    [JsonPropertyName("surface")] public string Surface { get; set; } = "";
    [JsonPropertyName("min_interval_seconds")] public int MinIntervalSeconds { get; set; }
    [JsonPropertyName("daily_cap")] public int DailyCap { get; set; }
}

public sealed class FreeAccessConfig
{
    [JsonPropertyName("enabled")] public bool Enabled { get; set; } = true;
    [JsonPropertyName("engine_mode")] public string EngineMode { get; set; } = "warp_fallback_pool";
    [JsonPropertyName("legacy_pool_enabled")] public bool LegacyPoolEnabled { get; set; } = true;
    [JsonPropertyName("label")] public string Label { get; set; } = "اتصال رایگان WARP";
    [JsonPropertyName("subscription_url")] public string SubscriptionUrl { get; set; } = "";
    [JsonPropertyName("subscriptions")] public List<FreeSubscriptionSource> Subscriptions { get; set; } = [];
    [JsonPropertyName("warp")] public WarpRuntimePolicy Warp { get; set; } = new();
}

public sealed class FreeSubscriptionSource
{
    [JsonPropertyName("id")] public string Id { get; set; } = "";
    [JsonPropertyName("name")] public string Name { get; set; } = "";
    [JsonPropertyName("subscription_url")] public string SubscriptionUrl { get; set; } = "";
    [JsonPropertyName("priority")] public int Priority { get; set; }
}

public sealed class WarpRuntimePolicy
{
    [JsonPropertyName("enabled")] public bool Enabled { get; set; } = true;
    [JsonPropertyName("mode")] public string Mode { get; set; } = "warp_fallback_pool";
    [JsonPropertyName("fallback_pool_enabled")] public bool FallbackPoolEnabled { get; set; } = true;
    [JsonPropertyName("schema")] public int Schema { get; set; } = 2;
    [JsonPropertyName("start_timeout_seconds")] public int StartTimeoutSeconds { get; set; } = 30;
    [JsonPropertyName("adaptive_strategy_enabled")] public bool AdaptiveStrategyEnabled { get; set; } = true;
    [JsonPropertyName("endpoint_racing_enabled")] public bool EndpointRacingEnabled { get; set; } = true;
    [JsonPropertyName("endpoint_race_breadth")] public int EndpointRaceBreadth { get; set; } = 8;
    [JsonPropertyName("endpoint_probe_seconds")] public int EndpointProbeSeconds { get; set; } = 5;
    [JsonPropertyName("quick_reconnect")] public bool QuickReconnect { get; set; } = true;
    [JsonPropertyName("allowed_transports")] public List<string> AllowedTransports { get; set; } = ["h3", "h2", "h2_fragment", "wireguard"];
    [JsonPropertyName("scan_mode")] public string ScanMode { get; set; } = "turbo";
    [JsonPropertyName("ip_mode")] public string IpMode { get; set; } = "v4";
    [JsonPropertyName("h2_enabled")] public bool H2Enabled { get; set; } = true;
    [JsonPropertyName("fragment_enabled")] public bool FragmentEnabled { get; set; } = true;
    [JsonPropertyName("fragment_size")] public string FragmentSize { get; set; } = "8-24";
    [JsonPropertyName("fragment_delay")] public string FragmentDelay { get; set; } = "5-15";
    [JsonPropertyName("wireguard_enabled")] public bool WireguardEnabled { get; set; } = true;
    [JsonPropertyName("warp_in_warp_enabled")] public bool WarpInWarpEnabled { get; set; }
    [JsonPropertyName("warm_timeout_seconds")] public int WarmTimeoutSeconds { get; set; } = 8;
    [JsonPropertyName("cold_timeout_seconds")] public int ColdTimeoutSeconds { get; set; } = 30;
    [JsonPropertyName("total_timeout_seconds")] public int TotalTimeoutSeconds { get; set; } = 75;
    [JsonPropertyName("noize_profile")] public string NoizeProfile { get; set; } = "firewall";
    [JsonPropertyName("require_exit_trace")] public bool RequireExitTrace { get; set; } = true;
    [JsonPropertyName("blocked_exit_countries")] public List<string> BlockedExitCountries { get; set; } = [];
}

public sealed record RuntimeVersionInfo(string Version, string Source, string RootPath);

public sealed record UpdateCandidate(string Version, string DownloadUrl, string Digest, string AssetName, string ReleaseUrl, long SizeBytes, bool AutoUpdate, bool ForceUpdate, string Channel, string Message);
