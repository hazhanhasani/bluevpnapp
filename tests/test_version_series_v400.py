import json
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def test_v4_version_metadata():
    app=json.loads((ROOT/'branding/app.json').read_text(encoding='utf-8'))
    release=json.loads((ROOT/'release.json').read_text(encoding='utf-8'))
    assert app['version_name']=='4.0.0'
    assert app['version_code']==40000
    assert release['version']=='4.0.0'
    assert release['version_code']==40000
    assert release['android_version']=='4.0.0'
    assert release['android_version_code']==40000

def test_workflow_uses_v4_series():
    wf=(ROOT/'.github/workflows/build-apk.yml').read_text(encoding='utf-8')
    assert 'base = (4, 0, 0)' in wf
    assert 'major * 10000 + minor * 100 + patch' in wf
