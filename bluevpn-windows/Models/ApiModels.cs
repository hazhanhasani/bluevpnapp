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
