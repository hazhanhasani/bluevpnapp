using BlueVPN.Windows.Models;

namespace BlueVPN.Windows.Services;

/// <summary>
/// Canonical Windows ad adapter. The WordPress API intentionally returns local
/// BlueVPN ad assets as relative paths (Android resolves them against apiBaseUrl),
/// so Windows must do the same before handing them to WPF imaging/media APIs.
/// </summary>
public sealed class AdvertisementService
{
    private readonly BlueVpnApiClient _api;
    private readonly AppSettings _settings;
    private readonly Random _random = new();

    public AdvertisementService(BlueVpnApiClient api, AppSettings settings)
    {
        _api = api;
        _settings = settings;
    }

    public MobileConfigResponse Current { get; private set; } = new();

    public async Task RefreshAsync(CancellationToken ct = default)
    {
        try
        {
            var config = await _api.GetMobileConfigAsync(ct).ConfigureAwait(false);
            Normalize(config.Advertising.Items);
            Normalize(config.Ads.Items);
            Normalize(config.FreeStoryAds.Items);
            Current = config;
        }
        catch
        {
            // Ads are fail-open: a transient control-plane problem must never
            // block the home screen or VPN lifecycle.
            Current = new MobileConfigResponse();
        }
    }

    public IReadOnlyList<AdvertisementItem> BannerItems
    {
        get
        {
            var primary = Current.Advertising.Enabled ? Current.Advertising : Current.Ads;
            return primary.Enabled ? primary.Items.Where(IsUsable).ToList() : [];
        }
    }

    public bool BannerAutoplay
    {
        get
        {
            var primary = Current.Advertising.Enabled ? Current.Advertising : Current.Ads;
            return primary.Enabled && primary.Autoplay;
        }
    }

    public bool BannerLoop
    {
        get
        {
            var primary = Current.Advertising.Enabled ? Current.Advertising : Current.Ads;
            return primary.Loop;
        }
    }

    public int BannerHeight => Math.Clamp(
        Current.Advertising.HeightDp > 0 ? Current.Advertising.HeightDp : Current.Ads.HeightDp,
        116,
        160);

    public int BannerIntervalMs => Math.Clamp(
        Current.Advertising.IntervalMs > 0 ? Current.Advertising.IntervalMs : Current.Ads.IntervalMs,
        3000,
        30000);

    public AdvertisementItem? PickFreeStory()
    {
        var cfg = Current.FreeStoryAds;
        var items = cfg.Enabled ? cfg.Items.Where(IsUsable).ToList() : [];
        if (items.Count == 0) return null;
        if (!cfg.Random) return items[0];

        // Match Android/control-plane weighted random behaviour.
        var total = items.Sum(x => Math.Clamp(x.Weight, 1, 100));
        var ticket = _random.Next(1, Math.Max(2, total + 1));
        foreach (var item in items)
        {
            ticket -= Math.Clamp(item.Weight, 1, 100);
            if (ticket <= 0) return item;
        }
        return items[^1];
    }

    public int StoryDurationSeconds(AdvertisementItem item)
    {
        var v = item.ImageDurationSeconds > 0 ? item.ImageDurationSeconds : Current.FreeStoryAds.ImageDurationSeconds;
        return Math.Clamp(v <= 0 ? 6 : v, 3, 30);
    }

    public int StoryLoadTimeoutMs => Math.Clamp(Current.FreeStoryAds.LoadTimeoutMs, 3000, 15000);
    public int StoryMaxVideoSeconds => Math.Clamp(Current.FreeStoryAds.MaxVideoSeconds, 5, 60);

    public string ResolveUrl(string value)
    {
        value = (value ?? "").Trim();
        if (value.Length == 0) return "";
        if (Uri.TryCreate(value, UriKind.Absolute, out var absolute)) return absolute.ToString();
        if (!value.StartsWith('/')) value = "/" + value;
        return _settings.ApiBaseUrl.TrimEnd('/') + value;
    }

    private void Normalize(IEnumerable<AdvertisementItem> items)
    {
        foreach (var item in items)
        {
            item.ImageUrl = ResolveUrl(item.ImageUrl);
            item.ImagePath = ResolveUrl(item.ImagePath);
            item.MediaUrl = ResolveUrl(item.MediaUrl);
            // target_url may deliberately be empty while deep_link is BlueVPN-internal.
            if (!string.IsNullOrWhiteSpace(item.TargetUrl)) item.TargetUrl = ResolveUrl(item.TargetUrl);
        }
    }

    private static bool IsUsable(AdvertisementItem item) =>
        !string.IsNullOrWhiteSpace(item.ImageUrl) || !string.IsNullOrWhiteSpace(item.MediaUrl);
}
