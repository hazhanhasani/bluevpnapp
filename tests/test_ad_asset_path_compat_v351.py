from __future__ import annotations

import base64
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_release_version_351():
    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    app = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
    assert release["version"] == "3.0.59"
    assert release["version_code"] == 30059
    assert app["version_name"] == "3.0.59"
    assert app["version_code"] == 30059


def test_db_assets_use_absolute_url_for_legacy_clients():
    main = (ROOT / "server/main.py").read_text(encoding="utf-8")
    assert "'image_path':raw_image if raw_image.startswith('/media/ads/') else ''" in main
    assert "if value.startswith('/api/v1/ad-assets/')" in main


def test_android_accepts_db_asset_paths_and_falls_back_to_absolute_url():
    source = (ROOT / "android-source/BlueVpnAdsCarouselView.kt").read_text(encoding="utf-8")
    assert 'trimmed.startsWith("/api/v1/ad-assets/")' in source
    assert '.ifBlank { imageAssetUrl(row.optString("image_url")) }' in source


def test_generated_carousel_matches_v351_snapshot():
    script = (ROOT / "scripts/prepare_android.py").read_text(encoding="utf-8")
    match = re.search(r'BLUEVPN_ADS_CAROUSEL_B64 = "([^"]+)"', script)
    assert match
    decoded = base64.b64decode(match.group(1)).decode("utf-8")
    source = (ROOT / "android-source/BlueVpnAdsCarouselView.kt").read_text(encoding="utf-8")
    assert decoded == source
