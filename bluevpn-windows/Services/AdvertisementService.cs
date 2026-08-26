using System.IO;
using System.Text.Json;
using System.Text.RegularExpressions;
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
    private readonly string _windowsWebStatePath;
    private int _windowsWebSlideCounter;
    private int _windowsWebDailyCount;
    private DateOnly _windowsWebDay = DateOnly.FromDateTime(DateTime.Now);
    private DateTimeOffset _windowsWebLastShown = DateTimeOffset.MinValue;
    private DateTimeOffset _windowsWebLastAttempt = DateTimeOffset.MinValue;
    private const int WindowsWebStateSchema = 2;

    public AdvertisementService(BlueVpnApiClient api, AppSettings settings)
    {
        _api = api;
        _settings = settings;
        var cacheRoot = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "BlueVPN",
            "cache");
        _cachePath = Path.Combine(cacheRoot, "mobile-config.json");
        _windowsWebStatePath = Path.Combine(cacheRoot, "tapsell-windows-state.json");
        Current = LoadCached();
        LoadWindowsWebState();
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
    /// Android uses Tapsell Mediation. Windows consumes the separately configured
    /// Tapsell Web Publisher script and never treats an Android zone id as a web placement.
    /// The Windows publisher code may be delivered by Tapsell through mediaad.org.
    /// </summary>
    public bool HasMobileOnlyThirdPartyAds => Current.Tapsell.Enabled;

    public TapsellWindowsWebConfig WindowsWeb => Current.Tapsell.WindowsWeb;

    /// <summary>
    /// Tapsell's Windows/Web publisher snippet can contain a MediaAd loader such as
    /// https://s1.mediaad.org/serve/blluepanel.ir/loader.js. The segment between
    /// /serve/ and /loader.js is the publisher origin and must be preferred by the
    /// embedded browser. Loading the same snippet first on bot.blluepanel.ir or a
    /// synthetic local host can legitimately produce an empty placement.
    /// </summary>
    public string WindowsWebPublisherHost()
    {
        var html = WindowsWeb.ScriptHtml ?? "";
        if (string.IsNullOrWhiteSpace(html)) return "";
        var match = Regex.Match(
            html,
            "https://s\\d+\\.mediaad\\.org/serve/(?<publisher>[^/\\\"'<>\\s]+)/loader\\.js",
            RegexOptions.IgnoreCase | RegexOptions.CultureInvariant);
        if (!match.Success) return "";
        var host = Uri.UnescapeDataString(match.Groups["publisher"].Value).Trim().Trim('.');
        return Uri.CheckHostName(host) == UriHostNameType.Unknown ? "" : host.ToLowerInvariant();
    }

    public bool TryReserveWindowsWebImpression(bool premium, bool noFirstPartyBanner)
    {
        var cfg = WindowsWeb;
        // Eligibility is intentionally separate from accounting. A failed
        // WebView/provider load must never consume daily cap or start the
        // successful-impression cooldown.
        var hasHttpsBridge = Uri.TryCreate(cfg.BridgeUrl, UriKind.Absolute, out var bridge) &&
                             bridge.Scheme.Equals(Uri.UriSchemeHttps, StringComparison.OrdinalIgnoreCase);
        var hasRenderableSource = hasHttpsBridge || !string.IsNullOrWhiteSpace(cfg.ScriptHtml);
        if (!cfg.Enabled || !hasRenderableSource || (cfg.FreeOnly && premium)) return false;
        var today = DateOnly.FromDateTime(DateTime.Now);
        if (today != _windowsWebDay) { _windowsWebDay = today; _windowsWebDailyCount = 0; _windowsWebLastShown = DateTimeOffset.MinValue; }
        _windowsWebSlideCounter++;
        if (!noFirstPartyBanner && _windowsWebSlideCounter % Math.Clamp(cfg.EverySlides, 1, 20) != 0) return false;
        if (cfg.DailyCap > 0 && _windowsWebDailyCount >= Math.Clamp(cfg.DailyCap, 1, 1000)) return false;
        if ((DateTimeOffset.Now - _windowsWebLastShown).TotalSeconds < Math.Clamp(cfg.MinIntervalSeconds, 0, 86400)) return false;

        // Do not hammer the provider when there is no first-party banner,
        // but never count a failed request as an impression.
        if ((DateTimeOffset.Now - _windowsWebLastAttempt).TotalSeconds < 20) return false;
        _windowsWebLastAttempt = DateTimeOffset.Now;
        return true;
    }

    public void MarkWindowsWebImpressionShown()
    {
        var today = DateOnly.FromDateTime(DateTime.Now);
        if (today != _windowsWebDay) { _windowsWebDay = today; _windowsWebDailyCount = 0; }
        _windowsWebDailyCount++;
        _windowsWebLastShown = DateTimeOffset.Now;
        SaveWindowsWebState();
    }

    public IReadOnlyList<string> WindowsWebBridgeCandidates()
    {
        var cfg = WindowsWeb;
        var candidates = new List<string>();
        var derived = new List<Uri>();
        Uri? bridge = null;
        string pathAndQuery = "";

        if (Uri.TryCreate(cfg.BridgeUrl, UriKind.Absolute, out var parsedBridge) &&
            parsedBridge.Scheme.Equals(Uri.UriSchemeHttps, StringComparison.OrdinalIgnoreCase))
        {
            bridge = parsedBridge;
            pathAndQuery = parsedBridge.PathAndQuery;
        }

        if (!string.IsNullOrWhiteSpace(pathAndQuery))
        {
            foreach (var baseUrl in _settings.ControlPlaneBases())
            {
                if (!Uri.TryCreate(baseUrl.TrimEnd('/') + pathAndQuery, UriKind.Absolute, out var candidate) ||
                    !candidate.Scheme.Equals(Uri.UriSchemeHttps, StringComparison.OrdinalIgnoreCase)) continue;
                if (!derived.Any(x => x.ToString().Equals(candidate.ToString(), StringComparison.OrdinalIgnoreCase)))
                    derived.Add(candidate);
            }
        }

        var publisherHost = WindowsWebPublisherHost();
        if (!string.IsNullOrWhiteSpace(publisherHost))
        {
            // The Tapsell/MediaAd loader is publisher-origin aware. Prefer only the
            // real registered publisher host first; this avoids wasting a full ad
            // timeout on bot.blluepanel.ir before trying blluepanel.ir.
            foreach (var candidate in derived.Where(x => x.IdnHost.Equals(publisherHost, StringComparison.OrdinalIgnoreCase)))
                candidates.Add(candidate.ToString());
            if (bridge is not null && bridge.IdnHost.Equals(publisherHost, StringComparison.OrdinalIgnoreCase) &&
                !candidates.Contains(bridge.ToString(), StringComparer.OrdinalIgnoreCase))
                candidates.Add(bridge.ToString());

            // If a publisher host is explicitly encoded in the official snippet,
            // do not intentionally run it first on a different origin. The caller
            // remains fail-open and will use BlueVPN's own banner if the canonical
            // publisher endpoint is unavailable.
            return candidates;
        }

        if (bridge is not null) candidates.Add(bridge.ToString());
        foreach (var candidate in derived)
        {
            if (!candidates.Contains(candidate.ToString(), StringComparer.OrdinalIgnoreCase))
                candidates.Add(candidate.ToString());
        }
        return candidates;
    }

    private void LoadWindowsWebState()
    {
        try
        {
            if (!File.Exists(_windowsWebStatePath)) return;
            var state = JsonSerializer.Deserialize<WindowsWebAdState>(File.ReadAllText(_windowsWebStatePath), AppSettings.JsonOptions());
            // Schema 1 counted attempts before render success. Ignore it once
            // so old false reservations cannot suppress the repaired client.
            if (state is null || state.Schema < WindowsWebStateSchema || state.Day != DateOnly.FromDateTime(DateTime.Now)) return;
            _windowsWebDay = state.Day;
            _windowsWebDailyCount = Math.Max(0, state.DailyCount);
            _windowsWebLastShown = state.LastShown;
        }
        catch { }
    }

    private void SaveWindowsWebState()
    {
        try
        {
            var dir = Path.GetDirectoryName(_windowsWebStatePath);
            if (!string.IsNullOrWhiteSpace(dir)) Directory.CreateDirectory(dir);
            File.WriteAllText(_windowsWebStatePath, JsonSerializer.Serialize(
                new WindowsWebAdState(WindowsWebStateSchema, _windowsWebDay, _windowsWebDailyCount, _windowsWebLastShown), AppSettings.JsonOptions()));
        }
        catch { }
    }

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

    private sealed record WindowsWebAdState(int Schema, DateOnly Day, int DailyCount, DateTimeOffset LastShown);
}
