from __future__ import annotations

import base64
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_release_version_347():
    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    app = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
    assert release["version"] == "3.0.75"
    assert release["version_code"] == 30075
    assert app["version_name"] == "3.0.75"
    assert app["version_code"] == 30075


def test_server_emits_backward_compatible_ad_assets():
    source = (ROOT / "server/main.py").read_text(encoding="utf-8")
    assert "def _public_origin(request:Request|None" in source
    assert "'image_path':raw_image if raw_image.startswith('/media/ads/') else ''" in source
    assert "advertising_payload(s,_public_origin(request,s),_bluevpn_client_version(request))" in source


def test_android_refreshes_ads_and_supports_both_url_forms():
    source = (ROOT / "android-source/BlueVpnAdsCarouselView.kt").read_text(encoding="utf-8")
    assert "private val refreshRunnable" in source
    assert "handler.postDelayed(refreshRunnable, 60_000L)" in source
    assert '.ifBlank { imageAssetUrl(row.optString("image_url")) }' in source
    assert "fetchInFlight" in source


def test_generated_source_matches_snapshot_v347():
    script = (ROOT / "scripts/prepare_android.py").read_text(encoding="utf-8")
    match = re.search(r'BLUEVPN_ADS_CAROUSEL_B64 = "([^"]+)"', script)
    assert match
    decoded = base64.b64decode(match.group(1)).decode("utf-8")
    snapshot = (ROOT / "android-source/BlueVpnAdsCarouselView.kt").read_text(encoding="utf-8")
    assert decoded == snapshot
