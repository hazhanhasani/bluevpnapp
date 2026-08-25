from pathlib import Path


def replace_once(text: str, old: str, new: str, label: str) -> str:
    if new in text:
        return text
    if old not in text:
        raise SystemExit(f'{label} marker not found')
    return text.replace(old, new, 1)


service = Path('bluevpn-windows/Services/AdvertisementService.cs')
s = service.read_text(encoding='utf-8')
field_old = '    private DateTimeOffset _windowsWebLastShown = DateTimeOffset.MinValue;\n'
field_new = field_old + '    private DateTimeOffset _windowsWebLastAttempt = DateTimeOffset.MinValue;\n    private const int WindowsWebStateSchema = 2;\n'
s = replace_once(s, field_old, field_new, 'state fields')

if '    public void MarkWindowsWebImpressionShown()' not in s:
    start = s.index('    public bool TryReserveWindowsWebImpression(bool premium, bool noFirstPartyBanner)')
    end = s.index('    private void LoadWindowsWebState()', start)
    replacement = '''    public bool TryReserveWindowsWebImpression(bool premium, bool noFirstPartyBanner)
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
        string pathAndQuery = "";

        if (Uri.TryCreate(cfg.BridgeUrl, UriKind.Absolute, out var bridge) &&
            bridge.Scheme.Equals(Uri.UriSchemeHttps, StringComparison.OrdinalIgnoreCase))
        {
            candidates.Add(bridge.ToString());
            pathAndQuery = bridge.PathAndQuery;
        }

        if (!string.IsNullOrWhiteSpace(pathAndQuery))
        {
            foreach (var baseUrl in _settings.ControlPlaneBases())
            {
                if (!Uri.TryCreate(baseUrl.TrimEnd('/') + pathAndQuery, UriKind.Absolute, out var candidate) ||
                    !candidate.Scheme.Equals(Uri.UriSchemeHttps, StringComparison.OrdinalIgnoreCase)) continue;
                if (!candidates.Contains(candidate.ToString(), StringComparer.OrdinalIgnoreCase))
                    candidates.Add(candidate.ToString());
            }
        }

        return candidates;
    }

'''
    s = s[:start] + replacement + s[end:]

state_old = '            if (state is null || state.Day != DateOnly.FromDateTime(DateTime.Now)) return;\n            _windowsWebDay = state.Day;\n'
state_new = '            // Schema 1 counted attempts before render success. Ignore it once\n            // so old false reservations cannot suppress the repaired client.\n            if (state is null || state.Schema < WindowsWebStateSchema || state.Day != DateOnly.FromDateTime(DateTime.Now)) return;\n            _windowsWebDay = state.Day;\n'
s = replace_once(s, state_old, state_new, 'state migration')
s = replace_once(
    s,
    '                new WindowsWebAdState(_windowsWebDay, _windowsWebDailyCount, _windowsWebLastShown), AppSettings.JsonOptions()));\n',
    '                new WindowsWebAdState(WindowsWebStateSchema, _windowsWebDay, _windowsWebDailyCount, _windowsWebLastShown), AppSettings.JsonOptions()));\n',
    'state save schema',
)
s = replace_once(
    s,
    '    private sealed record WindowsWebAdState(DateOnly Day, int DailyCount, DateTimeOffset LastShown);\n',
    '    private sealed record WindowsWebAdState(int Schema, DateOnly Day, int DailyCount, DateTimeOffset LastShown);\n',
    'state record schema',
)
service.write_text(s, encoding='utf-8')

main = Path('bluevpn-windows/MainWindow.xaml.cs')
s = main.read_text(encoding='utf-8')
reserve_old = '        if (_ads.TryReserveWindowsWebImpression(premium, items.Count == 0) && await ShowTapsellWebAdAsync())\n            return;\n'
reserve_new = '        if (_ads.TryReserveWindowsWebImpression(premium, items.Count == 0) && await ShowTapsellWebAdAsync())\n        {\n            _ads.MarkWindowsWebImpressionShown();\n            return;\n        }\n'
s = replace_once(s, reserve_old, reserve_new, 'main success accounting')

if 'foreach (var address in _ads.WindowsWebBridgeCandidates())' not in s:
    nav_start = s.index('            var address = Uri.TryCreate(cfg.BridgeUrl, UriKind.Absolute, out var bridge) &&')
    nav_end_marker = '            if (!await WaitForTapsellContentAsync(_lifetimeCts.Token))\n                return false;\n'
    nav_end = s.index(nav_end_marker, nav_start) + len(nav_end_marker)
    nav = '''            var rendered = false;
            foreach (var address in _ads.WindowsWebBridgeCandidates())
            {
                if (!await NavigateTapsellAsync(address, _lifetimeCts.Token)) continue;
                if (await WaitForTapsellContentAsync(_lifetimeCts.Token))
                {
                    rendered = true;
                    break;
                }
            }

            // Keep the old local-document path only as a final compatibility
            // fallback. Real WordPress origins are preferred because publisher
            // validation can reject synthetic/local origins.
            if (!rendered && !string.IsNullOrWhiteSpace(cfg.ScriptHtml))
            {
                var localAddress = await WebView2RuntimeInstaller.WriteAdDocumentAsync(html, _lifetimeCts.Token);
                if (await NavigateTapsellAsync(localAddress, _lifetimeCts.Token))
                    rendered = await WaitForTapsellContentAsync(_lifetimeCts.Token);
            }

            if (!rendered)
            {
                TapsellWebView.Visibility = Visibility.Collapsed;
                TapsellLoadingPanel.Visibility = Visibility.Collapsed;
                AdProviderLabel.Visibility = Visibility.Collapsed;
                return false;
            }
'''
    s = s[:nav_start] + nav + s[nav_end:]

if 'backgroundImage' not in s[s.index('private async Task<bool> WaitForTapsellContentAsync'):s.index('private static string ShortUiError')]:
    detector_start = s.index('            var result = await TapsellWebView.CoreWebView2.ExecuteScriptAsync(', s.index('private async Task<bool> WaitForTapsellContentAsync'))
    detector_end = s.index('            if (string.Equals(result?.Trim(), "true", StringComparison.OrdinalIgnoreCase)) return true;', detector_start)
    detector = '''            var result = await TapsellWebView.CoreWebView2.ExecuteScriptAsync(
                "(()=>{const r=document.getElementById('bluevpn-ad')||document.getElementById('bluevpn-tapsell-root')||document.body;if(!r)return false;" +
                "const visible=n=>{if(!n||!n.getBoundingClientRect)return false;const b=n.getBoundingClientRect(),s=getComputedStyle(n);" +
                "return b.width>20&&b.height>20&&s.display!=='none'&&s.visibility!=='hidden'&&Number(s.opacity||1)>0;};" +
                "const media=[...r.querySelectorAll('iframe,img,video,canvas,object,embed')];if(media.some(visible))return true;" +
                "const nodes=[...r.querySelectorAll('*')].filter(n=>!['SCRIPT','STYLE','LINK','META','NOSCRIPT'].includes(n.tagName));" +
                "if(nodes.some(n=>visible(n)&&(((n.innerText||'').trim().length>0)||(getComputedStyle(n).backgroundImage||'none')!=='none')))return true;" +
                "const hosts=nodes.filter(n=>n.shadowRoot);return hosts.some(h=>visible(h)&&[...h.shadowRoot.querySelectorAll('*')].some(visible));})()");
'''
    s = s[:detector_start] + detector + s[detector_end:]
main.write_text(s, encoding='utf-8')

test = Path('tests/test_windows_tapsell_web_553.py')
s = test.read_text(encoding='utf-8')
if 'def test_windows_bridge_failover_and_success_only_accounting' not in s:
    needle = '\nif __name__ == "__main__":\n    unittest.main()\n'
    addition = '''
    def test_windows_bridge_failover_and_success_only_accounting(self):
        service = (ROOT / "bluevpn-windows/Services/AdvertisementService.cs").read_text(encoding="utf-8")
        reserve = service[service.index("public bool TryReserveWindowsWebImpression"):service.index("public void MarkWindowsWebImpressionShown")]
        self.assertNotIn("_windowsWebDailyCount++", reserve)
        self.assertNotIn("SaveWindowsWebState()", reserve)
        self.assertIn("WindowsWebBridgeCandidates", service)
        self.assertIn("_settings.ControlPlaneBases()", service)
        self.assertIn("WindowsWebStateSchema = 2", service)
        main = (ROOT / "bluevpn-windows/MainWindow.xaml.cs").read_text(encoding="utf-8")
        self.assertIn("_ads.MarkWindowsWebImpressionShown();", main)
        self.assertIn("foreach (var address in _ads.WindowsWebBridgeCandidates())", main)
        detector = main[main.index("private async Task<bool> WaitForTapsellContentAsync"):main.index("private static string ShortUiError")]
        self.assertIn("backgroundImage", detector)
        self.assertIn("shadowRoot", detector)
'''
    if needle not in s:
        raise SystemExit('test insertion marker not found')
    s = s.replace(needle, addition + needle, 1)
test.write_text(s, encoding='utf-8')
