using System.Text.Json.Serialization;

namespace BlueVPN.Windows.Models;

public sealed class AuthResponse
{
    [JsonPropertyName("success")] public bool Success { get; set; }
    [JsonPropertyName("token")] public string Token { get; set; } = "";
    [JsonPropertyName("refresh_token")] public string RefreshToken { get; set; } = "";
    [JsonPropertyName("account")] public Account? Account { get; set; }
}

public sealed class AccountResponse
{
    [JsonPropertyName("success")] public bool Success { get; set; }
    [JsonPropertyName("account")] public Account? Account { get; set; }
}

public sealed class PlansResponse
{
    [JsonPropertyName("success")] public bool Success { get; set; }
    [JsonPropertyName("plans")] public List<Plan> Plans { get; set; } = [];
}

public sealed class OtpRequestResponse
{
    [JsonPropertyName("success")] public bool Success { get; set; }
    [JsonPropertyName("challenge_id")] public string ChallengeId { get; set; } = "";
    [JsonPropertyName("expires_in")] public int ExpiresIn { get; set; }
}

public sealed class Account
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("email")] public string Email { get; set; } = "";
    [JsonPropertyName("phone_display")] public string PhoneDisplay { get; set; } = "";
    [JsonPropertyName("display_identity")] public string DisplayIdentity { get; set; } = "";
    [JsonPropertyName("plan_title")] public string PlanTitle { get; set; } = "";
    [JsonPropertyName("expires_at_fa")] public string ExpiresAtFa { get; set; } = "";
    [JsonPropertyName("subscription")] public SubscriptionInfo Subscription { get; set; } = new();
}

public sealed class SubscriptionInfo
{
    [JsonPropertyName("active")] public bool Active { get; set; }
    [JsonPropertyName("status")] public string Status { get; set; } = "inactive";
    [JsonPropertyName("url")] public string Url { get; set; } = "";
    [JsonPropertyName("expire_fa")] public string ExpireFa { get; set; } = "";
    [JsonPropertyName("remaining_seconds")] public long? RemainingSeconds { get; set; }
    [JsonPropertyName("data_limit_bytes")] public long DataLimitBytes { get; set; }
    [JsonPropertyName("used_traffic_bytes")] public long UsedTrafficBytes { get; set; }
    [JsonPropertyName("remaining_bytes")] public long RemainingBytes { get; set; }
}

public sealed class Plan
{
    [JsonPropertyName("id")] public int Id { get; set; }
    [JsonPropertyName("title")] public string Title { get; set; } = "";
    [JsonPropertyName("description")] public string Description { get; set; } = "";
    [JsonPropertyName("price_toman")] public int PriceToman { get; set; }
    [JsonPropertyName("duration_days")] public int DurationDays { get; set; }
    [JsonPropertyName("data_limit_gb")] public int DataLimitGb { get; set; }
    [JsonPropertyName("device_limit")] public int DeviceLimit { get; set; }
}


public sealed class AiRecommendationsResponse
{
    [JsonPropertyName("success")] public bool Success { get; set; }
    [JsonPropertyName("enabled")] public bool Enabled { get; set; }
    [JsonPropertyName("tier_enabled")] public bool TierEnabled { get; set; }
    [JsonPropertyName("plan_tier")] public string PlanTier { get; set; } = "free";
    [JsonPropertyName("engine_version")] public string EngineVersion { get; set; } = "";
    [JsonPropertyName("schema_version")] public int SchemaVersion { get; set; }
    [JsonPropertyName("shadow_mode")] public bool ShadowMode { get; set; } = true;
    [JsonPropertyName("predictive_failover")] public bool PredictiveFailover { get; set; } = true;
    [JsonPropertyName("recommendations")] public List<AiRecommendation> Recommendations { get; set; } = [];
}

public sealed class AiRecommendation
{
    [JsonPropertyName("config_key")] public string ConfigKey { get; set; } = "";
    [JsonPropertyName("location_key")] public string LocationKey { get; set; } = "";
    [JsonPropertyName("score")] public int Score { get; set; } = 50;
    [JsonPropertyName("confidence")] public double Confidence { get; set; }
    [JsonPropertyName("consecutive_failures")] public int ConsecutiveFailures { get; set; }
}

public sealed class AiEventResponse
{
    [JsonPropertyName("success")] public bool Success { get; set; }
    [JsonPropertyName("accepted")] public bool Accepted { get; set; }
    [JsonPropertyName("live")] public bool Live { get; set; }
    [JsonPropertyName("verified")] public bool Verified { get; set; }
    [JsonPropertyName("reason")] public string Reason { get; set; } = "";
    [JsonPropertyName("route_score")] public int RouteScore { get; set; }
}

public sealed class WindowsUpdateResponse
{
    [JsonPropertyName("success")] public bool Success { get; set; }
    [JsonPropertyName("platform")] public string Platform { get; set; } = "windows";
    [JsonPropertyName("available")] public bool Available { get; set; }
    [JsonPropertyName("current_version")] public string CurrentVersion { get; set; } = "";
    [JsonPropertyName("latest_version")] public string LatestVersion { get; set; } = "";
    [JsonPropertyName("latest_version_code")] public int LatestVersionCode { get; set; }
    [JsonPropertyName("update_available")] public bool UpdateAvailable { get; set; }
    [JsonPropertyName("minimum_version")] public string MinimumVersion { get; set; } = "0.0.0";
    [JsonPropertyName("below_minimum")] public bool BelowMinimum { get; set; }
    [JsonPropertyName("force_update")] public bool ForceUpdate { get; set; }
    [JsonPropertyName("auto_update")] public bool AutoUpdate { get; set; }
    [JsonPropertyName("release_channel")] public string ReleaseChannel { get; set; } = "stable";
    [JsonPropertyName("beta_tester")] public bool BetaTester { get; set; }
    [JsonPropertyName("title")] public string Title { get; set; } = "";
    [JsonPropertyName("message")] public string Message { get; set; } = "";
    [JsonPropertyName("release_url")] public string ReleaseUrl { get; set; } = "";
    [JsonPropertyName("architecture")] public string Architecture { get; set; } = "win-x64";
    [JsonPropertyName("download_url")] public string DownloadUrl { get; set; } = "";
    [JsonPropertyName("filename")] public string Filename { get; set; } = "";
    [JsonPropertyName("sha256")] public string Sha256 { get; set; } = "";
    [JsonPropertyName("size")] public long Size { get; set; }
}
