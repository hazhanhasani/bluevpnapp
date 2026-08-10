from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def test_generated_android_build_explicitly_provides_listenablefuture_provider():
    prepare = (ROOT / "scripts" / "prepare_android.py").read_text(encoding="utf-8")
    assert 'implementation("com.google.guava:guava:33.6.0-android")' in prepare
    assert 'implementation("ir.tapsell.plus:tapsell-plus-sdk-android:2.3.3")' in prepare
    assert "required_dependencies" in prepare


def test_do_not_force_standalone_listenablefuture_artifact():
    prepare = (ROOT / "scripts" / "prepare_android.py").read_text(encoding="utf-8")
    # The 1.0 artifact can collide with full Guava when Gradle also resolves Guava's
    # 9999.0 empty compatibility marker. We intentionally provide the class via Guava.
    assert 'implementation("com.google.guava:listenablefuture:1.0")' not in prepare
