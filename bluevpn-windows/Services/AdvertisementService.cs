using BlueVPN.Windows.Models;

namespace BlueVPN.Windows.Services;

public sealed class AdvertisementService
{
    private readonly BlueVpnApiClient _api;
    private readonly Random _random = new();

    public AdvertisementService(BlueVpnApiClient api) => _api = api;

    public MobileConfigResponse Current { get; private set; } = new();

    public async Task RefreshAsync(CancellationToken ct = default)
    {
        try { Current = await _api.GetMobileConfigAsync(ct); }
        catch { Current = new MobileConfigResponse(); }
    }

    public IReadOnlyList<AdvertisementItem> BannerItems
    {
        get
        {
            var primary = Current.Advertising.Enabled ? Current.Advertising : Current.Ads;
            return primary.Enabled ? primary.Items.Where(IsUsable).ToList() : [];
        }
    }

    public int BannerIntervalMs => Math.Clamp(Current.Advertising.IntervalMs > 0 ? Current.Advertising.IntervalMs : 6000, 3000, 30000);

    public AdvertisementItem? PickFreeStory()
    {
        var cfg = Current.FreeStoryAds;
        var items = cfg.Enabled ? cfg.Items.Where(IsUsable).ToList() : [];
        if (items.Count == 0) return null;
        return cfg.Random ? items[_random.Next(items.Count)] : items[0];
    }

    public int StoryDurationSeconds(AdvertisementItem item)
    {
        var v = item.ImageDurationSeconds > 0 ? item.ImageDurationSeconds : Current.FreeStoryAds.ImageDurationSeconds;
        return Math.Clamp(v <= 0 ? 6 : v, 3, 30);
    }

    private static bool IsUsable(AdvertisementItem item) =>
        !string.IsNullOrWhiteSpace(item.ImageUrl) || !string.IsNullOrWhiteSpace(item.MediaUrl);
}
