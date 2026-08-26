from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def replace_once(path: Path, old: str, new: str) -> None:
    text = path.read_text(encoding="utf-8")
    if old not in text:
        raise SystemExit(f"marker not found in {path}: {old!r}")
    path.write_text(text.replace(old, new, 1), encoding="utf-8")


current = ROOT / "tests/test_current_release.py"
replace_once(
    current,
    '        self.assertIn("handler.postDelayed(attemptTimeout, 12_000L)", exact)\n',
    '''        self.assertIn(\n            "BlueVpnNetworkRecoveryManager.policy(this).candidateStartTimeoutMs",\n            exact,\n        )\n        recovery = (ROOT / "android-source/BlueVpnNetworkRecoveryManager.kt").read_text(encoding="utf-8")\n        self.assertIn("coerceIn(6_000L, 20_000L)", recovery)\n''',
)

premium = ROOT / "tests/test_premium_verification_recovery_4102.py"
replace_once(
    premium,
    '        self.assertIn("handler.postDelayed(verificationTimeout, 28_000L)",s)\n',
    '''        self.assertIn(\n            "handler.postDelayed(verificationTimeout, BlueVpnNetworkRecoveryManager.policy(this).verificationTimeoutMs)",\n            s,\n        )\n        recovery=self.text("android-source/BlueVpnNetworkRecoveryManager.kt")\n        self.assertIn("coerceIn(10_000L, 45_000L)", recovery)\n''',
)

print("legacy Android timeout regression guards migrated")
