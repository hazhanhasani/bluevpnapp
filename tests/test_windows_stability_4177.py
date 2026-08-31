import json
import pathlib
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
def text(p): return (ROOT/p).read_text(encoding='utf-8')

class WindowsStability4177Tests(unittest.TestCase):
    def test_release_version(self):
        r=json.loads(text('release.json'))
        self.assertEqual(r['version'],'6.2.9')
        self.assertEqual(r['version_code'],60209)

    def test_ui_is_android_order_and_non_blocking_metrics(self):
        x=text('bluevpn-windows/MainWindow.xaml')
        cs=text('bluevpn-windows/MainWindow.xaml.cs')
        for token in ('SubscriptionSummaryText','StatusText','OrbHalo','DownloadSpeedValue','DurationValue','UploadSpeedValue','AdCard','EndpointText','RemainingVolumeValue','RemainingTimeValue'):
            self.assertIn(token,x)
        self.assertIn('Grid.Row="4" x:Name="AdCard"',x)
        self.assertIn('Grid.Row="5"',x)
        self.assertIn('PeriodicTimer(TimeSpan.FromSeconds(1))',cs)
        self.assertIn('Task.Run(NetworkBytes',cs)
        self.assertNotIn('_metricsTimer',cs)
        self.assertNotIn('IsEnabled = false',cs)
        self.assertIn('ConnectingOverlay',x)
        self.assertIn('CancelConnection_Click',cs)

    def test_home_layout_and_ads_do_not_flatten_or_overlap(self):
        x=text('bluevpn-windows/MainWindow.xaml')
        ui=text('bluevpn-windows/MainWindow.xaml.cs')
        ads=text('bluevpn-windows/Services/AdvertisementService.cs')
        # All seven home rows must size to their content; fixed 48/62/188/58/78/56
        # heights caused the Persian status/brand text to paint into adjacent rows.
        home=x[x.index('<Grid x:Name="HomeContentGrid"'):x.index('<!-- Internal fields retained for diagnostics/menu only. -->')]
        self.assertGreaterEqual(home.count('<RowDefinition Height="Auto"/>'), 10)
        self.assertNotIn('Height="88" MaxHeight="96"', home)
        self.assertIn('MinHeight="90" MaxHeight="360"', home)
        self.assertIn('SizeChanged="AdCard_SizeChanged"', home)
        self.assertIn('Stretch="Uniform"', home)
        self.assertIn('TextWrapping="Wrap" LineHeight="17"', home)
        self.assertNotIn('BannerHeight * 0.58', ui)
        self.assertIn('_adImageAspectRatio', ui)
        self.assertIn('image.PixelWidth / (double)image.PixelHeight', ui)
        self.assertIn('AdCard.Height = Math.Clamp(ratioHeight, 96, 280)', ui)
        self.assertIn('BLUEVPN_TAPSELL_SIZE:', ui)
        self.assertIn('public double BannerAspectRatio', ads)

    def test_ads_resolve_relative_assets_and_do_not_decode_on_dispatcher(self):
        ads=text('bluevpn-windows/Services/AdvertisementService.cs')
        media=text('bluevpn-windows/Services/MediaAssetLoader.cs')
        ui=text('bluevpn-windows/MainWindow.xaml.cs')
        self.assertIn('_settings.ApiBaseUrl.TrimEnd',ads)
        self.assertIn('item.ImageUrl = ResolveUrl',ads)
        self.assertIn('BannerAutoplay',ads)
        self.assertIn('BannerLoop',ads)
        self.assertIn('Task.Run<BitmapSource?>',media)
        self.assertIn('bmp.Freeze()',media)
        self.assertIn('MediaAssetLoader.LoadImageAsync',ui)
        self.assertIn('MediaAssetLoader.Preload',ui)

    def test_update_does_not_download_when_channel_auto_update_is_off(self):
        ui=text('bluevpn-windows/MainWindow.xaml.cs')
        updater=text('bluevpn-windows/Services/AppUpdateService.cs')
        self.assertIn('if (!candidate.AutoUpdate)',ui)
        self.assertIn('if (userInitiated)',ui)
        self.assertIn('_pendingUpdate = candidate',ui)
        self.assertIn('candidate.ForceUpdate',ui)
        # The manual path asks before DownloadAndInstallUpdateAsync.
        self.assertLess(ui.index('MessageBox.Show(message'), ui.index('await DownloadAndInstallUpdateAsync(candidate, forced: false);'))
        self.assertIn('for (var attempt = 1; attempt <= 3; attempt++)',updater)
        self.assertIn('Sha256Async(path',updater)

    def test_connected_requires_real_baseline_route_and_ip_change(self):
        probe=text('bluevpn-windows/Services/ConnectivityProbe.cs')
        verify=text('bluevpn-windows/Services/SystemTunnelVerifier.cs')
        conn=text('bluevpn-windows/Services/ConnectionOrchestrator.cs')
        self.assertIn('CaptureBaselineAsync',probe)
        self.assertIn('IP پایه قبل از VPN معتبر نیست',verify)
        self.assertIn('!string.Equals(before.PublicIp, after.PublicIp',verify)
        self.assertIn('consecutive >= 2',verify)
        self.assertIn('CaptureBaselineAsync',conn)
        self.assertIn('IP اینترنت قبل از اتصال قابل تأیید نیست',conn)

    def test_premium_loop_guard_is_endpoint_aware(self):
        tun=text('bluevpn-windows/Services/V2RayNTunConfigBuilder.cs')
        core=text('bluevpn-windows/Services/XrayProcessController.cs')
        self.assertIn('ip_cidr = ipCidrs',tun)
        self.assertIn('domain = new[] { remoteHost }',tun)
        self.assertIn('process_name = new[] { "xray.exe" }',tun)
        self.assertIn('SnapshotViaSocksAsync',core)
        self.assertNotIn('_singBox.StartAsync',core)
        self.assertIn('SnapshotViaSocksAsync',core)

    def test_warp_uses_panel_policy_and_validates_socks_before_tun(self):
        models=text('bluevpn-windows/Models/WindowsRuntimeModels.cs')
        conn=text('bluevpn-windows/Services/ConnectionOrchestrator.cs')
        warp=text('bluevpn-windows/Services/WarpConnectionController.cs')
        self.assertIn('free_access',models)
        self.assertIn('blocked_exit_countries',models)
        self.assertIn('endpoint_probe_seconds',models)
        self.assertIn('LoadMobilePolicySafeAsync',conn)
        self.assertIn('warpPolicy',conn)
        self.assertIn('!premium && free.Enabled && (engineMode != "warp_only")',conn)
        self.assertNotIn('warpPolicy.FallbackPoolEnabled || _settings.Warp.FallbackToFreePool',conn)
        self.assertIn('catch (OperationCanceledException) when (ct.IsCancellationRequested) { throw; }',conn)
        self.assertIn('_warp.Stop();',conn)
        self.assertIn('_xray.Stop();',conn)
        self.assertIn('BuildAetherArgs(policy',warp)
        self.assertIn('SnapshotViaSocksAsync',warp)
        self.assertIn('BlockedExitCountries',warp)

    def test_runtime_update_heavy_work_is_off_ui_thread(self):
        runtime=text('bluevpn-windows/Services/RuntimeUpdateService.cs')
        self.assertIn('ConfigureAwait(false)',runtime)
        self.assertIn('await Task.Run(() =>',runtime)
        self.assertIn('ZipFile.ExtractToDirectory',runtime)

if __name__ == '__main__': unittest.main()
