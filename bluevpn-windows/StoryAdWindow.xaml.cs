using System.Diagnostics;
using System.Windows;
using System.Windows.Media.Imaging;
using System.Windows.Threading;
using BlueVPN.Windows.Models;

namespace BlueVPN.Windows;

public partial class StoryAdWindow : Window
{
    private readonly AdvertisementItem _item;
    private readonly DispatcherTimer _timer = new();

    public StoryAdWindow(AdvertisementItem item, int durationSeconds)
    {
        InitializeComponent();
        _item = item;
        StoryTitle.Text = item.Title;
        StorySubtitle.Text = item.Subtitle;
        ActionButton.Content = string.IsNullOrWhiteSpace(item.ButtonText) ? "مشاهده" : item.ButtonText;
        ActionButton.Visibility = ResolveTarget().Length > 0 ? Visibility.Visible : Visibility.Collapsed;

        var media = string.IsNullOrWhiteSpace(item.MediaUrl) ? item.ImageUrl : item.MediaUrl;
        if (item.MediaType.Equals("video", StringComparison.OrdinalIgnoreCase) && Uri.TryCreate(media, UriKind.Absolute, out var videoUri))
        {
            StoryVideo.Source = videoUri;
            StoryVideo.Visibility = Visibility.Visible;
            Loaded += (_, _) => StoryVideo.Play();
        }
        else if (Uri.TryCreate(media, UriKind.Absolute, out var imageUri))
        {
            try
            {
                StoryImage.Source = new BitmapImage(imageUri);
                StoryImage.Visibility = Visibility.Visible;
            }
            catch { }
        }

        _timer.Interval = TimeSpan.FromSeconds(Math.Clamp(durationSeconds, 3, 30));
        _timer.Tick += (_, _) => Close();
        Loaded += (_, _) => _timer.Start();
        Closed += (_, _) => { _timer.Stop(); try { StoryVideo.Stop(); } catch { } };
    }

    private string ResolveTarget() => !string.IsNullOrWhiteSpace(_item.TargetUrl) ? _item.TargetUrl : _item.DeepLink;

    private void Action_Click(object sender, RoutedEventArgs e)
    {
        var target = ResolveTarget();
        if (target.Length == 0) return;
        try { Process.Start(new ProcessStartInfo(target) { UseShellExecute = true }); } catch { }
    }

    private void Close_Click(object sender, RoutedEventArgs e) => Close();
    private void StoryVideo_MediaEnded(object sender, RoutedEventArgs e) => Close();
}
