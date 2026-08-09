from __future__ import annotations

import base64
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_release_version_342():
    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    app = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
    assert release["version"] == "3.0.42"
    assert release["version_code"] == 30042
    assert app["version_name"] == "3.0.42"
    assert app["version_code"] == 30042


def test_backend_exposes_and_manages_advertising():
    main = (ROOT / "server/main.py").read_text(encoding="utf-8")
    assert "'advertising':advertising_payload(s)" in main
    assert "@app.post('/admin/ads')" in main
    assert "@app.post('/admin/ads/{ad_id}/edit')" in main
    assert "@app.post('/admin/ads/{ad_id}/delete')" in main
    assert "ImageOps.exif_transpose" in main
    assert "MAX_AD_IMAGE_BYTES" in main
    assert "parsed.scheme.lower() not in {'http','https'}" in main


def test_admin_has_ad_tab_upload_and_schedule_controls():
    html = (ROOT / "server/templates/admin.html").read_text(encoding="utf-8")
    assert 'data-tab="ads"' in html
    assert 'id="ads"' in html
    assert 'name="image_file"' in html
    assert 'name="target_url"' in html
    assert 'name="start_at"' in html
    assert 'name="end_at"' in html
    assert 'name="interval_seconds"' in html


def test_android_carousel_is_safe_and_automatic():
    source = (ROOT / "android-source/BlueVpnAdsCarouselView.kt").read_text(encoding="utf-8")
    home = (ROOT / "android-source/BlueVpnHomeActivity.kt").read_text(encoding="utf-8")
    assert 'optJSONObject("advertising")' in source
    assert "slideRunnable" in source
    assert "handler.postDelayed(slideRunnable, intervalMs)" in source
    assert 'scheme == "http" || scheme == "https"' in source
    assert "LruCache<String, Bitmap>" in source
    assert "BlueVpnAdsCarouselView(this)" in home
    assert "adsCarousel.start()" in home
    assert "adsCarousel.stop()" in home
    assert "adsCarousel.release()" in home


def test_generated_carousel_source_matches_snapshot():
    script = (ROOT / "scripts/prepare_android.py").read_text(encoding="utf-8")
    match = re.search(r'BLUEVPN_ADS_CAROUSEL_B64 = "([^"]+)"', script)
    assert match
    decoded = base64.b64decode(match.group(1)).decode("utf-8")
    snapshot = (ROOT / "android-source/BlueVpnAdsCarouselView.kt").read_text(encoding="utf-8")
    assert decoded == snapshot
    assert 'bluevpn_dir / "BlueVpnAdsCarouselView.kt": BLUEVPN_ADS_CAROUSEL_B64' in script
