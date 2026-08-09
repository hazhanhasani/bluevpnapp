from __future__ import annotations

import base64
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_release_version_344():
    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    app = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
    assert release["version"] == "3.0.71"
    assert release["version_code"] == 30071
    assert app["version_name"] == "3.0.71"
    assert app["version_code"] == 30071


def test_local_media_paths_and_blank_fallback_are_hardened():
    source = (ROOT / "android-source/BlueVpnAdsCarouselView.kt").read_text(encoding="utf-8")
    main = (ROOT / "server/main.py").read_text(encoding="utf-8")
    assert "private fun imageAssetUrl" in source
    assert 'trimmed.startsWith("/media/ads/")' in source
    assert "dropBrokenCurrentItem" in source
    assert "override fun onMeasure" in source
    assert "return value" in main.split("def _public_ad_image_url", 1)[1]


def test_generated_carousel_source_matches_snapshot_v344():
    script = (ROOT / "scripts/prepare_android.py").read_text(encoding="utf-8")
    match = re.search(r'BLUEVPN_ADS_CAROUSEL_B64 = "([^"]+)"', script)
    assert match
    decoded = base64.b64decode(match.group(1)).decode("utf-8")
    snapshot = (ROOT / "android-source/BlueVpnAdsCarouselView.kt").read_text(encoding="utf-8")
    assert decoded == snapshot
