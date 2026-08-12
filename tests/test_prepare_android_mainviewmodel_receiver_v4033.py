import importlib.util
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PREPARE = ROOT / "scripts" / "prepare_android.py"


def load_prepare_module():
    spec = importlib.util.spec_from_file_location("bluevpn_prepare_android", PREPARE)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


def test_mainviewmodel_receiver_patch_is_structural_not_exact_body_match():
    module = load_prepare_module()
    source = '''class MainViewModel {
    private val mMsgReceiver = object : BroadcastReceiver() {
        override fun onReceive(ctx: Context?, intent: Intent?) {
            // Upstream can add comments or whitespace without breaking BlueVPN prepare.
            when (intent?.getIntExtra("key", 0)) {
                AppConfig.MSG_STATE_RUNNING -> {
                    isRunning.value = true
                }
                AppConfig.MSG_STATE_START_FAILURE -> {
                    val errorMessage = intent.getStringExtra("content")
                    isRunning.value = false
                }
            }
        }
    }
}
'''
    replacement = '''        override fun onReceive(ctx: Context?, intent: Intent?) {
            when (intent?.getIntExtra("key", 0)) {
                AppConfig.MSG_STATE_RUNNING -> {
                    runningServerGuid.value = intent.getStringExtra("content")
                    isRunning.value = true
                }
            }
        }
'''
    pattern = (
        r"^[ \t]*override[ \t]+fun[ \t]+onReceive[ \t]*"
        r"\([ \t]*ctx[ \t]*:[ \t]*Context\?[ \t]*,[ \t]*"
        r"intent[ \t]*:[ \t]*Intent\?[ \t]*\)[ \t]*\{"
    )
    patched = module._replace_kotlin_function(
        source,
        pattern,
        replacement,
        "MainViewModel receiver fixture",
    )
    assert "runningServerGuid.value" in patched
    assert "Upstream can add comments" not in patched
    assert "private val mMsgReceiver" in patched


def test_prepare_script_no_long_exact_receiver_replacement():
    source = PREPARE.read_text(encoding="utf-8")
    assert '"MainViewModel runtime identity receiver"' in source
    assert '"MainViewModel exact runtime identity receiver"' not in source
    assert "new_vm_on_receive" in source
    assert "_replace_kotlin_function(" in source
