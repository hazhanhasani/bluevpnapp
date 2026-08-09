from __future__ import annotations

import base64
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_version_3066():
    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    app = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
    assert release["version"] == "3.0.70"
    assert release["version_code"] == 30070
    assert app["version_name"] == "3.0.70"
    assert app["version_code"] == 30070


def test_free_connecting_screen_and_countdown_present():
    home = (ROOT / "android-source/BlueVpnHomeActivity.kt").read_text(encoding="utf-8")
    assert "class ConnectingGlobeView" in home
    assert "showConnectingOverlay" in home
    assert '"رایگان %02d:%02d"' in home
    assert "handler.postDelayed(this, 1_000L)" in home
    assert "اتصال رایگان تا ۶۰ دقیقه" in home


def test_known_flag_and_city_location_never_stays_unknown():
    location = (ROOT / "android-source/BlueVpnLocationUtil.kt").read_text(encoding="utf-8")
    assert "countryCodeFromFlag" in location
    assert '"marseille"' in location
    assert '"gravelines"' in location
    assert 'candidate.location.key != "unknown"' in location
    assert "markVerifiedCountryKey" in location


def test_embedded_android_sources_match_snapshots_v366():
    script = (ROOT / "scripts/prepare_android.py").read_text(encoding="utf-8")
    for const, path in [
        ("BLUEVPN_HOME_ACTIVITY_B64", "android-source/BlueVpnHomeActivity.kt"),
        ("BLUEVPN_LOCATION_UTIL_B64", "android-source/BlueVpnLocationUtil.kt"),
    ]:
        match = re.search(rf'{const} = "([^"]+)"', script)
        assert match, const
        decoded = base64.b64decode(match.group(1)).decode("utf-8")
        assert decoded == (ROOT / path).read_text(encoding="utf-8")
