import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

class WindowsStoryAdReadiness4178Tests(unittest.TestCase):
    def test_version(self):
        r=json.loads((ROOT/'release.json').read_text())
        self.assertEqual(r['version'],'5.7.11')
        self.assertEqual(r['version_code'],50711)

    def test_media_ready_is_runtime_gate_not_dead_field(self):
        s=(ROOT/'bluevpn-windows/StoryAdWindow.xaml.cs').read_text()
        self.assertIn('private bool _mediaReady;', s)
        self.assertIn('if (!_mediaReady && IsLoaded)', s)
        self.assertIn('EnforceMediaLoadTimeoutAsync()', s)
        self.assertIn('await Task.Delay(_loadTimeoutMs, _lifetime.Token)', s)
        self.assertIn('_mediaReady = true;', s)

    def test_video_hard_cap_starts_after_media_opened(self):
        s=(ROOT/'bluevpn-windows/StoryAdWindow.xaml.cs').read_text()
        loaded=s.split('private async void StoryAdWindow_Loaded',1)[1].split('private async Task EnforceMediaLoadTimeoutAsync',1)[0]
        self.assertIn('_ = EnforceMediaLoadTimeoutAsync();', loaded)
        self.assertNotIn('_timer.Start(); // hard cap', loaded)
        opened=s.split('private void StoryVideo_MediaOpened',1)[1].split('private void StoryVideo_MediaFailed',1)[0]
        self.assertIn('_mediaReady = true;', opened)
        self.assertIn('_timer.Start();', opened)

if __name__ == '__main__':
    unittest.main()
