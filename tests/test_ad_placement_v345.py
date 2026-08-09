from __future__ import annotations

import base64
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_release_345():
    release=json.loads((ROOT/'release.json').read_text(encoding='utf-8'))
    app=json.loads((ROOT/'branding/app.json').read_text(encoding='utf-8'))
    assert release['version']=='3.0.59'
    assert release['version_code']==30059
    assert app['version_name']=='3.0.59'
    assert app['version_code']==30059


def test_ad_is_compact_and_placed_above_server_selection():
    home=(ROOT/'android-source/BlueVpnHomeActivity.kt').read_text(encoding='utf-8')
    carousel=(ROOT/'android-source/BlueVpnAdsCarouselView.kt').read_text(encoding='utf-8')
    assert home.index('BlueVpnAdsCarouselView(this)') < home.index('createServerCard()')
    assert '/ 2.222f' in carousel
    assert 'coerceIn(dp(116), dp(160))' in carousel
    assert 'private fun hideBanner()' in carousel


def test_admin_documents_recommended_ad_size():
    html=(ROOT/'server/templates/admin.html').read_text(encoding='utf-8')
    assert '۱۲۰۰×۵۴۰' in html
    assert 'نسبت ۲۰:۹' in html
    assert 'min="116" max="160"' in html


def test_generated_sources_match_snapshots_v345():
    script=(ROOT/'scripts/prepare_android.py').read_text(encoding='utf-8')
    for name,path in [('BLUEVPN_ADS_CAROUSEL_B64','android-source/BlueVpnAdsCarouselView.kt'),('BLUEVPN_HOME_ACTIVITY_B64','android-source/BlueVpnHomeActivity.kt')]:
        match=re.search(rf'{name} = "([^"]+)"',script)
        assert match
        assert base64.b64decode(match.group(1)).decode('utf-8')==(ROOT/path).read_text(encoding='utf-8')
