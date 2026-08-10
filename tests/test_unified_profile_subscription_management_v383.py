from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def text(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def test_profile_catalogue_covers_requested_formats_and_engines():
    source = text("android-source/BlueVpnProfileManager.kt")
    for token in (
        "SHARE_LINK",
        "XRAY_JSON",
        "SING_BOX_JSON",
        "SSH_URI",
        "Protocol.SSH",
        "EngineRoute.SING_BOX",
        "EngineRoute.XRAY",
    ):
        assert token in source


def test_semantic_fingerprint_ignores_display_fragment_and_mmkv_guid():
    source = text("android-source/BlueVpnProfileManager.kt")
    assert "identityGetters" in source
    assert '"getRemarks"' not in source
    assert '"getSubscriptionId"' not in source
    assert "fragment is display-only" in source
    assert "canonicalizeJson" in source
    assert '"getFingerPrint"' in source
    assert '"getXhttpExtra"' in source
    assert '"getPreSharedKey"' in source
    assert "canonicalizeUri" in source


def test_candidate_catalogue_collapses_semantic_duplicates_without_deleting_profiles():
    source = text("android-source/BlueVpnLocationUtil.kt")
    assert "seenFingerprints" in source
    assert "BlueVpnProfileManager.fingerprint(profile, raw)" in source
    assert "selectedGuid" in source and "orderedGuids" in source
    # De-duplication must be view/catalogue-only, not a destructive MMKV cleanup.
    block = source.split("val seenFingerprints", 1)[1].split("synchronized(this)", 1)[0]
    assert "removeServer" not in block


def test_subscription_refresh_preserves_semantic_selected_server():
    source = text("android-source/BlueVpnAccountManager.kt")
    assert source.count("captureSelectedFingerprint") >= 2
    assert source.count("restoreSelectedFingerprint") >= 2
    assert "refreshedServerGuids" in source


def test_ssh_and_singbox_sources_compile_to_native_validated_local_proxy_profile():
    compiler = text("android-source/BlueVpnSingBoxProfileCompiler.kt")
    process = text("android-source/BlueVpnSingBoxProcess.kt")
    assert 'startsWith("ssh://"' in compiler
    assert '.put("type", "ssh")' in compiler
    assert '.put("type", "mixed")' in compiler
    assert "LOCAL_MIXED_PORT = 21080" in compiler
    assert "installManagedSource" in process
    assert "BlueVpnSingBoxProfileCompiler.compile(raw)" in process
    assert "installProfile(context, compiled.json).getOrThrow()" in process


def test_pinned_v2rayng_shadowsocks_sip002_transport_query_fix_is_applied_at_build_time():
    prepare = text("scripts/prepare_android.py")
    assert "def patch_shadowsocks_transport_queries" in prepare
    assert 'marker = "getItemFormQuery(config, queryParam)"' in prepare
    assert '"return toUri(config, Utils.encode(pw, true), getQueryDic(config))"' in prepare
    assert "patch_shadowsocks_transport_queries()" in prepare.split("def main()", 1)[1]


def test_runtime_pins_remain_controlled_during_subscription_refactor():
    import json

    app = json.loads(text("branding/app.json"))
    assert app["upstream_ref"] == "2.2.6"
    assert app["sing_box_ref"] == "v1.13.16"
