from __future__ import annotations

import base64
import json
import re
from pathlib import Path

from server.main import _client_supports_safe_ads, advertising_payload

ROOT = Path(__file__).resolve().parents[1]


def test_release_version_348():
    release=json.loads((ROOT/'release.json').read_text(encoding='utf-8'))
    app=json.loads((ROOT/'branding/app.json').read_text(encoding='utf-8'))
    assert release['version']=='3.0.65'
    assert release['version_code']==30065
    assert app['version_name']=='3.0.65'
    assert app['version_code']==30065


def test_broken_old_ad_layout_is_disabled_server_side():
    assert not _client_supports_safe_ads('3.0.42')
    assert not _client_supports_safe_ads('3.0.47')
    assert _client_supports_safe_ads('3.0.48')
    assert _client_supports_safe_ads('3.1.0')


def test_payload_is_disabled_for_old_clients_and_enabled_for_fixed_clients():
    settings={
        'ads_enabled':True,
        'ads_autoplay':True,
        'ads_loop':True,
        'ads_interval_seconds':6,
        'ads_height_dp':146,
        'ads_items':[{'id':'adtest1','active':True,'sort_order':0,'image_url':'https://example.com/banner.webp'}],
    }
    old=advertising_payload(settings,'https://example.test','3.0.42')
    fixed=advertising_payload(settings,'https://example.test','3.0.48')
    assert old['enabled'] is False
    assert old['disabled_reason']=='old_client_layout'
    assert old['items']==[]
    assert fixed['enabled'] is True
    assert len(fixed['items'])==1


def test_banner_only_appears_after_real_image_decode():
    source=(ROOT/'android-source/BlueVpnAdsCarouselView.kt').read_text(encoding='utf-8')
    assert 'private fun revealBitmap' in source
    assert 'private fun hideBanner' in source
    assert 'params.height = targetHeight' in source
    home=(ROOT/'android-source/BlueVpnHomeActivity.kt').read_text(encoding='utf-8')
    assert 'ViewGroup.LayoutParams.MATCH_PARENT,\n                0,' in home
    assert 'MeasureSpec.makeMeasureSpec(0, MeasureSpec.EXACTLY)' in source
    assert 'if (id.isBlank() || imageUrl.isBlank()) continue' in source
    assert '.ifBlank { imageAssetUrl(row.optString("image_url")) }' in source


def test_server_gates_old_clients_and_requires_existing_images():
    source=(ROOT/'server/main.py').read_text(encoding='utf-8')
    assert 'MIN_SAFE_AD_CLIENT_VERSION = "3.0.48"' in source
    assert "'disabled_reason':'old_client_layout'" in source
    assert "if not (ADS_DIR/filename).is_file()" in source
    assert 'advertising_payload(s,_public_origin(request,s),_bluevpn_client_version(request))' in source


def test_generated_ad_source_matches_snapshot_v348():
    script=(ROOT/'scripts/prepare_android.py').read_text(encoding='utf-8')
    match=re.search(r'BLUEVPN_ADS_CAROUSEL_B64 = "([^"]+)"',script)
    assert match
    assert base64.b64decode(match.group(1)).decode('utf-8')==(ROOT/'android-source/BlueVpnAdsCarouselView.kt').read_text(encoding='utf-8')
