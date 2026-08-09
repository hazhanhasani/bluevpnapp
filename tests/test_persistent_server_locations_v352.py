from __future__ import annotations

import base64
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_release_version_352():
    release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
    app = json.loads((ROOT / "branding/app.json").read_text(encoding="utf-8"))
    assert release["version"] == "3.0.60"
    assert release["version_code"] == 30060
    assert app["version_name"] == "3.0.60"
    assert app["version_code"] == 30060


def test_server_location_database_and_api_exist():
    models = (ROOT / "server/models.py").read_text(encoding="utf-8")
    main = (ROOT / "server/main.py").read_text(encoding="utf-8")
    database = (ROOT / "server/database.py").read_text(encoding="utf-8")
    assert 'class ServerLocation(Base):' in models
    assert '__tablename__ = "server_locations"' in models
    assert "@app.post('/api/v1/server-locations/resolve')" in main
    assert "@app.post('/api/v1/server-locations/verify')" in main
    assert 'SCHEMA_VERSION = "18"' in database


def test_android_persists_and_syncs_detected_country():
    location = (ROOT / "android-source/BlueVpnLocationUtil.kt").read_text(encoding="utf-8")
    account = (ROOT / "android-source/BlueVpnAccountManager.kt").read_text(encoding="utf-8")
    servers = (ROOT / "android-source/BlueVpnServersActivity.kt").read_text(encoding="utf-8")
    home = (ROOT / "android-source/BlueVpnHomeActivity.kt").read_text(encoding="utf-8")
    assert 'fun serverIdentity(profile: ProfileItem)' in location
    assert 'fun syncCloudLocations(' in location
    assert 'fun reportVerifiedCountry(' in location
    assert 'fun resolveServerLocations(' in account
    assert 'fun reportServerLocation(' in account
    assert 'title = "در حال شناسایی"' in location
    assert 'شناسایی کشور در پس‌زمینه' in servers
    assert 'locationSyncRunnable' in servers
    assert 'endpoint.contains("/cdn-cgi/trace")' in home


def test_generated_android_sources_match_snapshots_v352():
    script = (ROOT / "scripts/prepare_android.py").read_text(encoding="utf-8")
    pairs = {
        "BLUEVPN_HOME_ACTIVITY_B64": "BlueVpnHomeActivity.kt",
        "BLUEVPN_ACCOUNT_MANAGER_B64": "BlueVpnAccountManager.kt",
        "BLUEVPN_LOCATION_UTIL_B64": "BlueVpnLocationUtil.kt",
        "BLUEVPN_SERVERS_ACTIVITY_B64": "BlueVpnServersActivity.kt",
    }
    for constant, filename in pairs.items():
        match = re.search(rf'{constant} = "([^"]+)"', script)
        assert match, constant
        decoded = base64.b64decode(match.group(1)).decode("utf-8")
        assert decoded == (ROOT / "android-source" / filename).read_text(encoding="utf-8")
