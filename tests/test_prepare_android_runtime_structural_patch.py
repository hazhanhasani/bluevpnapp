import importlib.util
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def _load_prepare_android():
    spec = importlib.util.spec_from_file_location(
        "bluevpn_prepare_android",
        ROOT / "scripts" / "prepare_android.py",
    )
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


def test_structural_kotlin_function_replacement_tolerates_runtime_body_changes():
    module = _load_prepare_android()
    source = '''object CoreServiceManager {\n    fun startVService(context: Context, guid: String? = null) {\n        LogUtil.i(AppConfig.TAG, "Start ${context::class.java.simpleName}")\n        if (guid != null) {\n            MmkvManager.setSelectServer(guid)\n        }\n        // harmless upstream body/comment change { }\n        try {\n            startContextService(context)\n        } catch (e: Exception) {\n            context.toast("${e.message}")\n        }\n    }\n    fun stopVService(context: Context) {}\n}\n'''
    replacement = '''    fun startVService(context: Context, guid: String? = null) {\n        startVServiceExact(context, guid)\n    }\n\n    fun startVServiceExact(context: Context, guid: String? = null): Boolean {\n        return true\n    }\n'''
    pattern = r"^[ \t]*fun[ \t]+startVService[ \t]*\([ \t]*context[ \t]*:[ \t]*Context[ \t]*,[ \t]*guid[ \t]*:[ \t]*String\?[ \t]*=[ \t]*null[ \t]*\)[ \t]*\{"

    patched = module._replace_kotlin_function(
        source,
        pattern,
        replacement,
        "exact start entry point",
        already_marker="fun startVServiceExact(",
    )

    assert "fun startVServiceExact(" in patched
    assert "MmkvManager.setSelectServer(guid)" not in patched
    assert "fun stopVService(context: Context)" in patched

    patched_again = module._replace_kotlin_function(
        patched,
        pattern,
        replacement,
        "exact start entry point",
        already_marker="fun startVServiceExact(",
    )
    assert patched_again == patched


def test_runtime_patch_no_longer_uses_exact_whole_start_function_match():
    text = (ROOT / "scripts" / "prepare_android.py").read_text(encoding="utf-8")
    assert '_replace_kotlin_function(' in text
    assert 'already_marker="fun startVServiceExact("' in text
    assert 'core = replace_exact(core, old_start, new_start, "exact start entry point")' not in text
