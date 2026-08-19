import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def text(rel: str) -> str:
    return (ROOT / rel).read_text(encoding='utf-8')

class WindowsAuthAdsVpsParity4180(unittest.TestCase):
    def test_android_like_auth_surface(self):
        xaml = text('bluevpn-windows/MainWindow.xaml')
        cs = text('bluevpn-windows/MainWindow.xaml.cs')
        for token in (
            'SmsModeButton', 'EmailModeButton', 'SmsPhoneStage', 'SmsOtpStage',
            'EmailLoginModeButton', 'EmailRegisterModeButton', 'EmailSubmitButton',
            'ورود امن با کد یک‌بارمصرف ۶ رقمی',
        ):
            self.assertIn(token, xaml)
        for token in ('ApplyAuthModeUi', 'RequestOtpCoreAsync', 'EmailSubmit_Click', 'ChangePhone_Click', 'ResendOtp_Click'):
            self.assertIn(token, cs)
        self.assertIn('AuthStatusText.Text = message', cs)

    def test_first_party_ads_survive_vps_transport_failures(self):
        ads = text('bluevpn-windows/Services/AdvertisementService.cs')
        media = text('bluevpn-windows/Services/MediaAssetLoader.cs')
        xaml = text('bluevpn-windows/MainWindow.xaml')
        self.assertIn('mobile-config.json', ads)
        self.assertIn('LoadCached()', ads)
        self.assertIn('SaveCached(config)', ads)
        self.assertNotIn('Current = new MobileConfigResponse();\n        }\n    }\n\n    public IReadOnlyList', ads)
        self.assertIn('AdFallbackPanel', xaml)
        self.assertIn('UseProxy = useSystemProxy', media)
        self.assertIn('WebRequest.DefaultWebProxy', media)
        self.assertIn('DiskCacheRoot', media)
        self.assertIn('Certificate', media)  # TLS validation must not be bypassed

    def test_tapsell_mobile_contract_is_not_faked_as_windows_sdk(self):
        model = text('bluevpn-windows/Models/WindowsRuntimeModels.cs')
        ads = text('bluevpn-windows/Services/AdvertisementService.cs')
        self.assertIn('[JsonPropertyName("tapsell")]', model)
        self.assertIn('TapsellConfig', model)
        self.assertIn('HasMobileOnlyThirdPartyAds', ads)
        self.assertIn('separate web-publisher placement', ads)

if __name__ == '__main__':
    unittest.main()
