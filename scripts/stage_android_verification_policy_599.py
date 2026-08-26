from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def replace_once(path: Path, old: str, new: str) -> None:
    text = path.read_text(encoding="utf-8")
    if old not in text:
        raise SystemExit(f"marker not found in {path}: {old[:120]!r}")
    path.write_text(text.replace(old, new, 1), encoding="utf-8")


recovery = ROOT / "android-source/BlueVpnNetworkRecoveryManager.kt"
replace_once(
    recovery,
    '''    private const val KEY_POLICY_GATE_WAIT_MS = "policy_gate_wait_ms"\n    private const val DEFAULT_RECOVERY_WINDOW_MS = 60_000L\n    private const val DEFAULT_GATE_WAIT_MS = 2_500L\n\n    data class ConnectionPolicy(\n        val recoveryWindowMs: Long,\n        val connectionGateWaitMs: Long,\n    )\n''',
    '''    private const val KEY_POLICY_GATE_WAIT_MS = "policy_gate_wait_ms"\n    private const val KEY_POLICY_CANDIDATE_START_MS = "policy_candidate_start_ms"\n    private const val KEY_POLICY_VERIFICATION_MS = "policy_verification_ms"\n    private const val DEFAULT_RECOVERY_WINDOW_MS = 60_000L\n    private const val DEFAULT_GATE_WAIT_MS = 2_500L\n    private const val DEFAULT_CANDIDATE_START_MS = 12_000L\n    private const val DEFAULT_VERIFICATION_MS = 28_000L\n\n    data class ConnectionPolicy(\n        val recoveryWindowMs: Long,\n        val connectionGateWaitMs: Long,\n        val candidateStartTimeoutMs: Long,\n        val verificationTimeoutMs: Long,\n    )\n''',
)
replace_once(
    recovery,
    '''            connectionGateWaitMs = p.getLong(KEY_POLICY_GATE_WAIT_MS, DEFAULT_GATE_WAIT_MS)\n                .coerceIn(500L, 8_000L),\n        )\n''',
    '''            connectionGateWaitMs = p.getLong(KEY_POLICY_GATE_WAIT_MS, DEFAULT_GATE_WAIT_MS)\n                .coerceIn(500L, 8_000L),\n            candidateStartTimeoutMs = p.getLong(KEY_POLICY_CANDIDATE_START_MS, DEFAULT_CANDIDATE_START_MS)\n                .coerceIn(6_000L, 20_000L),\n            verificationTimeoutMs = p.getLong(KEY_POLICY_VERIFICATION_MS, DEFAULT_VERIFICATION_MS)\n                .coerceIn(10_000L, 45_000L),\n        )\n''',
)
replace_once(
    recovery,
    '''        val gateWaitMs = remote.optLong("connection_gate_wait_ms", DEFAULT_GATE_WAIT_MS).coerceIn(500L, 8_000L)\n        prefs(context).edit()\n            .putLong(KEY_POLICY_RECOVERY_WINDOW_MS, recoverySeconds * 1_000L)\n            .putLong(KEY_POLICY_GATE_WAIT_MS, gateWaitMs)\n            .apply()\n''',
    '''        val gateWaitMs = remote.optLong("connection_gate_wait_ms", DEFAULT_GATE_WAIT_MS).coerceIn(500L, 8_000L)\n        val candidateStartSeconds = remote.optLong("candidate_start_timeout_seconds", 12L).coerceIn(6L, 20L)\n        val verificationSeconds = remote.optLong("verification_timeout_seconds", 28L).coerceIn(10L, 45L)\n        prefs(context).edit()\n            .putLong(KEY_POLICY_RECOVERY_WINDOW_MS, recoverySeconds * 1_000L)\n            .putLong(KEY_POLICY_GATE_WAIT_MS, gateWaitMs)\n            .putLong(KEY_POLICY_CANDIDATE_START_MS, candidateStartSeconds * 1_000L)\n            .putLong(KEY_POLICY_VERIFICATION_MS, verificationSeconds * 1_000L)\n            .apply()\n''',
)

home = ROOT / "android-source/BlueVpnHomeActivity.kt"
replace_once(
    home,
    "        handler.postDelayed(attemptTimeout, 12_000L)\n",
    "        handler.postDelayed(attemptTimeout, BlueVpnNetworkRecoveryManager.policy(this).candidateStartTimeoutMs)\n",
)
replace_once(
    home,
    "            handler.postDelayed(verificationTimeout, 28_000L)\n",
    "            handler.postDelayed(verificationTimeout, BlueVpnNetworkRecoveryManager.policy(this).verificationTimeoutMs)\n",
)

settings = ROOT / "android-source/BlueVpnSettingsActivity.kt"
replace_once(
    settings,
    '''            appendLine("Connection gate wait: ${connectionPolicy.connectionGateWaitMs}ms")\n            appendLine("Account session: ${if (account.email.isNotBlank()) "signed-in" else "guest"}")\n''',
    '''            appendLine("Connection gate wait: ${connectionPolicy.connectionGateWaitMs}ms")\n            appendLine("Candidate start timeout: ${connectionPolicy.candidateStartTimeoutMs / 1_000L}s")\n            appendLine("Verification timeout: ${connectionPolicy.verificationTimeoutMs / 1_000L}s")\n            appendLine("Account session: ${if (account.email.isNotBlank()) "signed-in" else "guest"}")\n''',
)

api = ROOT / "bluevpn-manager/includes/class-bluevpn-api.php"
replace_once(
    api,
    '''                'recovery_window_seconds'=>max(15,min(180,(int)($s['android_recovery_window_seconds'] ?? 60))),\n                'connection_gate_wait_ms'=>max(500,min(8000,(int)($s['android_connection_gate_wait_ms'] ?? 2500))),\n            ],\n''',
    '''                'recovery_window_seconds'=>max(15,min(180,(int)($s['android_recovery_window_seconds'] ?? 60))),\n                'connection_gate_wait_ms'=>max(500,min(8000,(int)($s['android_connection_gate_wait_ms'] ?? 2500))),\n                'candidate_start_timeout_seconds'=>max(6,min(20,(int)($s['android_candidate_start_timeout_seconds'] ?? 12))),\n                'verification_timeout_seconds'=>max(10,min(45,(int)($s['android_verification_timeout_seconds'] ?? 28))),\n            ],\n''',
)

test = ROOT / "tests/test_dual_control_plane_581.py"
replace_once(
    test,
    '''        self.assertIn("Recovery window:", settings)\n        self.assertIn("Connection gate wait:", settings)\n''',
    '''        self.assertIn("Recovery window:", settings)\n        self.assertIn("Connection gate wait:", settings)\n        self.assertIn("candidate_start_timeout_seconds", api)\n        self.assertIn("verification_timeout_seconds", api)\n        self.assertIn("coerceIn(6_000L, 20_000L)", recovery)\n        self.assertIn("coerceIn(10_000L, 45_000L)", recovery)\n        home = (ROOT / "android-source/BlueVpnHomeActivity.kt").read_text(encoding="utf-8")\n        self.assertIn("candidateStartTimeoutMs", home)\n        self.assertIn("verificationTimeoutMs", home)\n        self.assertNotIn("postDelayed(attemptTimeout, 12_000L)", home)\n        self.assertNotIn("postDelayed(verificationTimeout, 28_000L)", home)\n''',
)

print("Android verification policy patch applied")
