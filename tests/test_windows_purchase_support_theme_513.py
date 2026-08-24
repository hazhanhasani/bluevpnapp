import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class WindowsPurchaseSupportTheme513Tests(unittest.TestCase):
    def test_windows_purchase_support_theme_contract(self):
        xaml = (ROOT / 'bluevpn-windows' / 'MainWindow.xaml').read_text(encoding='utf-8')
        code = (ROOT / 'bluevpn-windows' / 'MainWindow.xaml.cs').read_text(encoding='utf-8')
        api = (ROOT / 'bluevpn-windows' / 'Services' / 'BlueVpnApiClient.cs').read_text(encoding='utf-8')
        theme = (ROOT / 'bluevpn-windows' / 'Services' / 'WindowsThemeService.cs').read_text(encoding='utf-8')
        support = (ROOT / 'bluevpn-manager' / 'includes' / 'class-bluevpn-support.php').read_text(encoding='utf-8')

        self.assertIn('Click="PurchasePlan_Click"', xaml)
        self.assertIn('CreateOrderAsync', api)
        self.assertIn('/checkout/open', api)
        self.assertIn('/check-after-success', api)
        self.assertIn('HeartbeatCheckoutAsync', code)
        self.assertIn('paymentUri.Scheme.Equals(Uri.UriSchemeHttps', code)

        self.assertIn('x:Name="SupportDrawer"', xaml)
        self.assertIn('CreateSupportConversationAsync', api)
        self.assertIn('GetSupportConversationsAsync', api)
        self.assertIn('SendSupportMessageAsync', api)
        self.assertIn('CloseSupportConversationAsync', api)
        self.assertIn("get_header('x-bluevpn-platform')", support)
        self.assertIn("['windows','android','web']", support)
        self.assertNotIn("'source'=>'android'", support)

        self.assertIn('x:Name="ThemeComboBox"', xaml)
        self.assertIn('Tag="system"', xaml)
        self.assertIn('Tag="light"', xaml)
        self.assertIn('Tag="dark"', xaml)
        self.assertIn('AppsUseLightTheme', theme)
        self.assertIn('ui-preferences.json', theme)
        self.assertIn('{DynamicResource BlueVpnBg}', xaml)

        # Third-party branding stays out of customer-facing text while the
        # publisher script runs in its isolated WebView2 surface.
        self.assertNotIn('Text="Tapsell', xaml)
        self.assertIn('ShowTapsellWebAdAsync', code)
        self.assertIn('EnsureCoreWebView2Async', code)


if __name__ == '__main__':
    unittest.main()
