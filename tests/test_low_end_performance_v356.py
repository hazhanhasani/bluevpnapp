from __future__ import annotations

import base64
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_release_version_356():
    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    app = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
    assert release["version"] == "3.0.58"
    assert release["version_code"] == 30058
    assert app["version_name"] == "3.0.58"
    assert app["version_code"] == 30058


def test_low_ram_profile_disables_permanent_animation():
    theme = (ROOT / "android-source/BlueVpnTheme.kt").read_text(encoding="utf-8")
    home = (ROOT / "android-source/BlueVpnHomeActivity.kt").read_text(encoding="utf-8")
    assert "object BlueVpnPerformance" in theme
    assert "isLowRamDevice" in theme
    assert "BlueVpnPerformance.isLowEnd(context)" in theme
    assert "!enabled || BlueVpnPerformance.isLowEnd(this)" in home
    assert "BlueVpnPerformance.statsIntervalMs" in home
    assert "!BlueVpnPerformance.isLowEnd(this)" in home


def test_expensive_server_work_is_cached_and_off_main_thread():
    source = (ROOT / "android-source/BlueVpnLocationUtil.kt").read_text(encoding="utf-8")
    home = (ROOT / "android-source/BlueVpnHomeActivity.kt").read_text(encoding="utf-8")
    assert "CANDIDATE_CACHE_TTL_MS = 10_000L" in source
    assert "contextCandidateCache" in source
    assert "cloudExecutor.execute" in source
    assert "Candidate decoding and SHA-256 identity generation" in source
    assert "warmCandidatesThenRefresh" in home
    assert "Dispatchers.Default" in home


def test_ad_images_are_sampled_and_memory_is_trimmed():
    ads = (ROOT / "android-source/BlueVpnAdsCarouselView.kt").read_text(encoding="utf-8")
    home = (ROOT / "android-source/BlueVpnHomeActivity.kt").read_text(encoding="utf-8")
    assert "decodeAdBitmap" in ads
    assert "inSampleSize" in ads
    assert "Bitmap.Config.RGB_565" in ads
    assert "fun trimMemory()" in ads
    assert "override fun onTrimMemory" in home


def test_location_screen_uses_debounce_and_single_candidate_snapshot():
    servers = (ROOT / "android-source/BlueVpnServersActivity.kt").read_text(encoding="utf-8")
    assert "searchHandler.postDelayed" in servers
    assert "BlueVpnPerformance.locationSyncIntervalMs" in servers
    assert "val candidates = BlueVpnLocationUtil.allCandidates(this)" in servers
    assert servers.count("val candidates = BlueVpnLocationUtil.allCandidates(this)") == 1


def test_modified_android_snapshots_match_generator():
    script = (ROOT / "scripts/prepare_android.py").read_text(encoding="utf-8")
    pairs = {
        "BLUEVPN_HOME_ACTIVITY_B64": "BlueVpnHomeActivity.kt",
        "BLUEVPN_ADS_CAROUSEL_B64": "BlueVpnAdsCarouselView.kt",
        "BLUEVPN_LOCATION_UTIL_B64": "BlueVpnLocationUtil.kt",
        "BLUEVPN_SERVERS_ACTIVITY_B64": "BlueVpnServersActivity.kt",
        "BLUEVPN_THEME_B64": "BlueVpnTheme.kt",
        "BLUEVPN_LIVE_REPORTER_B64": "BlueVpnLiveReporter.kt",
    }
    for constant, filename in pairs.items():
        match = re.search(rf'{constant} = "([^"]+)"', script)
        assert match, constant
        decoded = base64.b64decode(match.group(1)).decode("utf-8")
        assert decoded == (ROOT / "android-source" / filename).read_text(encoding="utf-8")
