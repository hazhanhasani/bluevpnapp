from pathlib import Path
import json, unittest

ROOT = Path(__file__).resolve().parents[1]

class FreeStoryVideoRender4125(unittest.TestCase):
    def test_release_version(self):
        app=json.loads((ROOT/'branding/app.json').read_text())
        rel=json.loads((ROOT/'release.json').read_text())
        self.assertEqual((app['version_name'],app['version_code']),(rel['version'],rel['version_code']))
        self.assertEqual((rel['version'],rel['version_code']),(app['version_name'],app['version_code']))

    def test_android_waits_for_real_video_frame(self):
        s=(ROOT/'android-source/BlueVpnFreeStoryAdGate.kt').read_text()
        self.assertIn('TextureView',s)
        self.assertIn('MediaPlayer.MEDIA_INFO_VIDEO_RENDERING_START',s)
        self.assertIn('downloadVideo(item.mediaUrl)',s)
        self.assertIn('File.createTempFile("bluevpn-story-"',s)
        self.assertIn('if (active && !mediaStarted)',s)
        self.assertNotIn('VideoView(activity)',s)

    def test_manager_recommends_android_safe_video(self):
        s=(ROOT/'bluevpn-manager/includes/class-bluevpn-ads.php').read_text()
        self.assertIn('H.264/AVC',s)
        self.assertIn('AAC',s)
        self.assertIn('video/mp4',s)
        self.assertNotIn('accept="image/webp,image/jpeg,image/png,video/mp4,video/webm"',s)

    def test_latest_theme_is_bundled(self):
        s=(ROOT/'bluevpn-site/style.css').read_text()
        self.assertIn('Version: 6.1.2',s)

if __name__ == '__main__': unittest.main()
