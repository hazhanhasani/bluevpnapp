using System.Diagnostics;
using System.Windows;
using System.Windows.Threading;
using BlueVPN.Windows.Models;
using BlueVPN.Windows.Services;

namespace BlueVPN.Windows;

public partial class StoryAdWindow : Window
{
    private readonly AdvertisementItem _item;
    private readonly int _durationSeconds;
    private readonly int _loadTimeoutMs;
    private readonly int _maxVideoSeconds;
    private readonly DispatcherTimer _timer = new();
    private readonly CancellationTokenSource _lifetime = new();
    private bool _mediaReady;

    public StoryAdWindow(AdvertisementItem item, int durationSeconds, int loadTimeoutMs, int maxVideoSeconds)
    {
        InitializeComponent();
        _item = item;
        _durationSeconds = Math.Clamp(durationSeconds, 3, 30);
        _loadTimeoutMs = Math.Clamp(loadTimeoutMs, 3000, 15000);
        _maxVideoSeconds = Math.Clamp(maxVideoSeconds, 5, 60);
        StoryTitle.Text = item.Title;
        StorySubtitle.Text = item.Subtitle;
        ActionButton.Content = string.IsNullOrWhiteSpace(item.ButtonText) ? "مشاهده" : item.ButtonText;
        ActionButton.Visibility = ResolveTarget().Length > 0 ? Visibility.Visible : Visibility.Collapsed;

        _timer.Tick += (_, _) => Close();
        Loaded += StoryAdWindow_Loaded;
        Closed += StoryAdWindow_Closed;
    }

    private async void StoryAdWindow_Loaded(object sender, RoutedEventArgs e)
    {
        var media = string.IsNullOrWhiteSpace(_item.MediaUrl) ? _item.ImageUrl : _item.MediaUrl;
        if (_item.MediaType.Equals("video", StringComparison.OrdinalIgnoreCase) && Uri.TryCreate(media, UriKind.Absolute, out var videoUri))
        {
            StoryVideo.Source = videoUri;
            StoryVideo.Visibility = Visibility.Visible;
            LoadingText.Visibility = Visibility.Visible;
            // Give the media only the configured load window to become playable.
            // The content-duration timer starts only after MediaOpened, so a black/
            // stalled video can never sit on top of the VPN for maxVideoSeconds.
            _ = EnforceMediaLoadTimeoutAsync();
            StoryVideo.Play();
            return;
        }

        using var timeout = CancellationTokenSource.CreateLinkedTokenSource(_lifetime.Token);
        timeout.CancelAfter(_loadTimeoutMs);
        try
        {
            var image = await MediaAssetLoader.LoadImageAsync(media, timeout.Token);
            if (image is null) { Close(); return; }
            StoryImage.Source = image;
            StoryImage.Visibility = Visibility.Visible;
            LoadingText.Visibility = Visibility.Collapsed;
            _mediaReady = true;
            _timer.Stop();
            _timer.Interval = TimeSpan.FromSeconds(_durationSeconds);
            _timer.Start();
        }
        catch { Close(); }
    }

    private async Task EnforceMediaLoadTimeoutAsync()
    {
        try
        {
            await Task.Delay(_loadTimeoutMs, _lifetime.Token);
            if (!_mediaReady && IsLoaded)
            {
                // Fail-open: an ad that never becomes renderable must not block the UI.
                Close();
            }
        }
        catch (OperationCanceledException)
        {
            // Window closed or media lifecycle cancelled.
        }
    }

    private void StoryVideo_MediaOpened(object sender, RoutedEventArgs e)
    {
        _mediaReady = true;
        LoadingText.Visibility = Visibility.Collapsed;
        _timer.Stop();
        var natural = StoryVideo.NaturalDuration.HasTimeSpan ? StoryVideo.NaturalDuration.TimeSpan.TotalSeconds : _maxVideoSeconds;
        _timer.Interval = TimeSpan.FromSeconds(Math.Clamp(natural, 1, _maxVideoSeconds));
        _timer.Start();
    }

    private void StoryVideo_MediaFailed(object sender, ExceptionRoutedEventArgs e)
    {
        // Fail-open. A codec/network issue must not keep a black story window over the VPN.
        Close();
    }

    private void StoryAdWindow_Closed(object? sender, EventArgs e)
    {
        _lifetime.Cancel();
        _timer.Stop();
        try { StoryVideo.Stop(); } catch { }
        _lifetime.Dispose();
    }

    private string ResolveTarget() => !string.IsNullOrWhiteSpace(_item.TargetUrl) ? _item.TargetUrl : _item.DeepLink;

    private void Action_Click(object sender, RoutedEventArgs e)
    {
        var target = ResolveTarget();
        if (!Uri.TryCreate(target, UriKind.Absolute, out var uri) || uri.Scheme.Equals("bluevpn", StringComparison.OrdinalIgnoreCase)) return;
        try { Process.Start(new ProcessStartInfo(uri.ToString()) { UseShellExecute = true }); } catch { }
    }

    private void Close_Click(object sender, RoutedEventArgs e) => Close();
    private void StoryVideo_MediaEnded(object sender, RoutedEventArgs e) => Close();
}
