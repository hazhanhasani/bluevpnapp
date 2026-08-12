from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def text(rel: str) -> str:
    return (ROOT / rel).read_text(encoding="utf-8")


def section(source: str, start: str, end: str) -> str:
    return source.split(start, 1)[1].split(end, 1)[0]


def test_ui_entitlement_never_enumerates_mmkv_pool():
    source = text("android-source/BlueVpnEntitlement.kt")
    ui = section(source, "fun resolveUi(context: Context)", "/**\n     * Reconcile")
    assert "preferredServerGuids" not in ui
    assert "decodeSubscriptions" not in ui
    assert "Looper.myLooper() == Looper.getMainLooper()" in source


def test_locations_render_does_not_deep_resolve_per_row():
    source = text("android-source/BlueVpnServersActivity.kt")
    render = section(source, "private fun renderLocationsNow", "private fun createLocationSection")
    card = section(source, "private fun createLocationSection", "private fun createServerEntry")
    assert "resolveUi(this)" in render
    assert "BlueVpnEntitlement." not in card
    assert "createLocationSection(group, active, manualSelectionAllowed)" in render


def test_visible_location_health_does_not_run_full_selector():
    source = text("android-source/BlueVpnLocationUtil.kt")
    health = section(source, "fun locationHealthScore", "data class CandidatePreflight")
    assert "BlueVpnSmartSelector" not in health
    assert "BlueVpnExperience.healthScore" not in health


def test_connection_pool_and_ranking_are_prepared_off_main():
    source = text("android-source/BlueVpnHomeActivity.kt")
    prepare = section(source, "private fun startSmartConnectionWithCandidates", "private fun applyPreparedConnectionQueue")
    assert "lifecycleScope.launch(Dispatchers.Default)" in prepare
    assert "preferredServerGuids" in prepare
    assert "connectionEntitlementGuids = prepared.first" in prepare
    current = section(source, "private fun startCurrentCandidate", "private fun")
    assert "guid !in connectionEntitlementGuids" in current
    assert "connectionEntitlementGuids," in current


def test_selector_resolves_entitlement_once_per_batch():
    source = text("android-source/BlueVpnSmartSelector.kt")
    rank = section(source, "fun rank(", "/**\n     * Orders candidates")
    assert rank.count("BlueVpnEntitlement.resolve(context)") == 1
    assert "scoreKnownAllowed(context, it)" in rank
    assert "fun scoreTrusted" in source


def test_version_4034_is_real_runtime_change():
    app = text("branding/app.json")
    assert '"version_name": "4.0.35"' in app
    assert '"version_code": 40035' in app
    workflow = text(".github/workflows/build-apk.yml")
    assert "validate_main_thread_anr_fix.py" in workflow
