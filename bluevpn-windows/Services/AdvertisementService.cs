using System.IO;
using System.Text.Json;
using BlueVPN.Windows.Models;

namespace BlueVPN.Windows.Services;

/// <summary>
/// Canonical Windows ad adapter. It consumes the same /mobile/config contract as
/// Android, resolves relative BlueVPN assets, and keeps a last-known-good copy so
/// a temporary TLS/control-plane outage does not turn the ad slot into an empty box.
/// Third-party mobile SDK payloads (for example Tapsell Mediation) are parsed for
/// capability diagnostics, but are never impersonated by the WPF client.
/// </summary>
public sealed class AdvertisementService
{
    private readonly BlueVpnApiClient _api;
    private readonly AppSettings _settings;
    private readonly Random _random = new();
    private readonly string _cachePath;

    public AdvertisementService(BlueVpnApiClient api, AppSettings settings)
    {
        _api = api;
        _settings = settings;
        var cacheRoot = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "BlueVPN",
            "cache");
        _cachePath = Path.Combine(cacheRoot, "mobile-config.json");
        Current = LoadCached();
    }

    public MobileConfigResponse Current { get; private set; }
    public string LastRefreshError { get; private set; } = "";

    public async Task RefreshAsync(CancellationToken ct = default)
    {
        try
        {
            var config = await _api.GetMobileConfigAsync(ct).ConfigureAwait(false);
            NormalizeAll(config);
            Current = config;
            LastRefreshError = "";
            SaveCached(config);
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested)
        {
            throw;
        }
        catch (Exception ex)
        {
            // Fail-open, but keep last-known-good instead of replacing it with an
            // empty payload. This matters on Windows Server/VPS machines whose TLS
            // trust/proxy state can temporarily block the control plane.
            LastRefreshError = ex.Message;
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

    public double BannerAspectRatio
    {
        get
        {
            var primary = Current.Advertising.Enabled ? Current.Advertising : Current.Ads;
            var raw = (primary.AspectRatio ?? "").Trim();
            var parts = raw.Split(':', StringSplitOptions.TrimEntries | StringSplitOptions.RemoveEmptyEntries);
            if (parts.Length == 2 &&
                double.TryParse(parts[0], System.Globalization.NumberStyles.Float, System.Globalization.CultureInfo.InvariantCulture, out var width) &&
                double.TryParse(parts[1], System.Globalization.NumberStyles.Float, System.Globalization.CultureInfo.InvariantCulture, out var height) &&
                width > 0 && height > 0)
            {
                return Math.Clamp(width / height, 0.75, 6.0);
            }
            return 20d / 9d;
        }
    }

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

    /// <summary>
    /// Tapsell's current Mediation payload is a mobile-SDK contract. Windows WPF
    /// deliberately reports that fact instead of silently pretending a zone was
    /// shown. A separate web-publisher placement is required for a real Windows ad.
    /// </summary>
    public bool HasMobileOnlyThirdPartyAds => Current.Tapsell.Enabled;

    public string ResolveUrl(string value)
    {
        value = (value ?? "").Trim();
        if (value.Length == 0) return "";
        if (Uri.TryCreate(value, UriKind.Absolute, out var absolute)) return absolute.ToString();
        if (!value.StartsWith('/')) value = "/" + value;
        return _settings.ApiBaseUrl.TrimEnd('/') + value;
    }

    private void NormalizeAll(MobileConfigResponse config)
    {
        Normalize(config.Advertising.Items);
        Normalize(config.Ads.Items);
        Normalize(config.FreeStoryAds.Items);
    }

    private void Normalize(IEnumerable<AdvertisementItem> items)
    {
        foreach (var item in items)
        {
            item.ImageUrl = ResolveUrl(item.ImageUrl);
            item.ImagePath = ResolveUrl(item.ImagePath);
            item.MediaUrl = ResolveUrl(item.MediaUrl);
            if (!string.IsNullOrWhiteSpace(item.TargetUrl)) item.TargetUrl = ResolveUrl(item.TargetUrl);
        }
    }

    private MobileConfigResponse LoadCached()
    {
        try
        {
            if (!File.Exists(_cachePath)) return new MobileConfigResponse();
            var json = File.ReadAllText(_cachePath);
            var config = JsonSerializer.Deserialize<MobileConfigResponse>(json, AppSettings.JsonOptions()) ?? new MobileConfigResponse();
            NormalizeAll(config);
            return config;
        }
        catch
        {
            return new MobileConfigResponse();
        }
    }

    private void SaveCached(MobileConfigResponse config)
    {
        try
        {
            var dir = Path.GetDirectoryName(_cachePath);
            if (!string.IsNullOrWhiteSpace(dir)) Directory.CreateDirectory(dir);
            var temp = _cachePath + ".tmp";
            File.WriteAllText(temp, JsonSerializer.Serialize(config, AppSettings.JsonOptions()));
            File.Move(temp, _cachePath, true);
        }
        catch
        {
            // Cache persistence is optional and must never own the ad/UI lifecycle.
        }
    }

    private static bool IsUsable(AdvertisementItem item) =>
        !string.IsNullOrWhiteSpace(item.ImageUrl) ||
        !string.IsNullOrWhiteSpace(item.ImagePath) ||
        !string.IsNullOrWhiteSpace(item.MediaUrl) ||
        !string.IsNullOrWhiteSpace(item.Title) ||
        !string.IsNullOrWhiteSpace(item.Subtitle);
}
