from pathlib import Path
import json
import unittest

ROOT = Path(__file__).resolve().parents[1]

def text(rel: str) -> str:
    return (ROOT / rel).read_text(encoding="utf-8")

class AndroidCompileHotfix522Tests(unittest.TestCase):
    def test_release_is_522(self):
        r = json.loads(text("release.json"))
        self.assertEqual(r["version"], "5.6.3")
        self.assertEqual(r["version_code"], 50603)
        self.assertEqual(r["android_version"], "5.6.3")
        self.assertEqual(r["android_version_code"], 50603)

    def test_home_explicitly_imports_ircf_intelligence(self):
        home = text("android-source/BlueVpnHomeActivity.kt")
        self.assertIn("import com.v2ray.ang.bluevpn.BlueVpnIrcfIntelligence", home)
        self.assertIn("BlueVpnIrcfIntelligence.adaptiveProbeUrls", home)

    def test_adaptive_probe_insertion_avoids_overloaded_method_reference(self):
        home = text("android-source/BlueVpnHomeActivity.kt")
        self.assertNotIn(".forEach(::add)", home)
        self.assertIn(".forEach { url -> add(url) }", home)

    def test_ircf_source_package_matches_import(self):
        src = text("android-source/BlueVpnIrcfIntelligence.kt")
        self.assertTrue(src.startswith("package com.v2ray.ang.bluevpn\n"))
        self.assertIn("object BlueVpnIrcfIntelligence", src)

    def test_prepare_android_copies_and_validates_ircf_source(self):
        prep = text("scripts/prepare_android.py")
        self.assertIn('bluevpn_dir / "BlueVpnIrcfIntelligence.kt": ROOT / "android-source/BlueVpnIrcfIntelligence.kt"', prep)
        self.assertIn("references BlueVpnIrcfIntelligence without the explicit import", prep)
        self.assertIn("Adaptive probe insertion must use an explicit lambda", prep)

if __name__ == "__main__":
    unittest.main()
