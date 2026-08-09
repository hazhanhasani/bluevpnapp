from __future__ import annotations

import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_release_version_350():
    release=json.loads((ROOT/'release.json').read_text(encoding='utf-8'))
    app=json.loads((ROOT/'branding/app.json').read_text(encoding='utf-8'))
    assert release['version']=='3.0.74'
    assert release['version_code']==30074
    assert app['version_name']=='3.0.74'
    assert app['version_code']==30074


def test_ad_uploads_are_persisted_in_database_not_ephemeral_disk():
    models=(ROOT/'server/models.py').read_text(encoding='utf-8')
    main=(ROOT/'server/main.py').read_text(encoding='utf-8')
    database=(ROOT/'server/database.py').read_text(encoding='utf-8')
    assert 'class AdAsset(Base):' in models
    assert '__tablename__ = "ad_assets"' in models
    assert 'payload: Mapped[bytes] = mapped_column(LargeBinary)' in models
    assert 'SCHEMA_VERSION = "18"' in database
    assert 'def _store_ad_asset' in main
    assert "@app.get('/api/v1/ad-assets/{asset_id}')" in main
    assert 'return _store_ad_asset(db,encoded' in main
    assert '_repair_legacy_ad_assets(db,s)' in main


def test_bundled_recovery_banner_exists_for_current_missing_legacy_asset():
    recovery=ROOT/'server/static/ads/bluevpn-vip-upgraded-recovery.webp'
    assert recovery.is_file()
    assert recovery.stat().st_size > 10_000
