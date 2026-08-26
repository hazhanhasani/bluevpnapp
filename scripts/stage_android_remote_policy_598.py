from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def replace_once(path: Path, old: str, new: str) -> None:
    text = path.read_text(encoding="utf-8")
    if old not in text:
        raise SystemExit(f"marker not found in {path}: {old[:120]!r}")
    path.write_text(text.replace(old, new, 1), encoding="utf-8")


recovery = ROOT / "android-source/BlueVpnNetworkRecoveryManager.kt"
replace_once(recovery, "import android.net.NetworkCapabilities\n", "import android.net.NetworkCapabilities\nimport org.json.JSONObject\n")
replace_once(
    recovery,
    '''    private const val KEY_RECOVERY_UNTIL = "recovery_until"\n    private const val RECOVERY_WINDOW_MS = 60_000L\n''',
    '''    private const val KEY_RECOVERY_UNTIL = "recovery_until"\n    private const val KEY_POLICY_RECOVERY_WINDOW_MS = "policy_recovery_window_ms"\n    private const val KEY_POLICY_GATE_WAIT_MS = "policy_gate_wait_ms"\n    private const val DEFAULT_RECOVERY_WINDOW_MS = 60_000L\n    private const val DEFAULT_GATE_WAIT_MS = 2_500L\n\n    data class ConnectionPolicy(\n        val recoveryWindowMs: Long,\n        val connectionGateWaitMs: Long,\n    )\n''',
)
replace_once(
    recovery,
    '''    private fun prefs(context: Context) = context.applicationContext\n        .getSharedPreferences(PREFS, Context.MODE_PRIVATE)\n\n''',
    '''    private fun prefs(context: Context) = context.applicationContext\n        .getSharedPreferences(PREFS, Context.MODE_PRIVATE)\n\n    fun policy(context: Context): ConnectionPolicy {\n        val p = prefs(context)\n        return ConnectionPolicy(\n            recoveryWindowMs = p.getLong(KEY_POLICY_RECOVERY_WINDOW_MS, DEFAULT_RECOVERY_WINDOW_MS)\n                .coerceIn(15_000L, 180_000L),\n            connectionGateWaitMs = p.getLong(KEY_POLICY_GATE_WAIT_MS, DEFAULT_GATE_WAIT_MS)\n                .coerceIn(500L, 8_000L),\n        )\n    }\n\n    fun connectionGateWaitMs(context: Context): Long = policy(context).connectionGateWaitMs\n\n    fun applyRemotePolicy(context: Context, config: JSONObject): Boolean {\n        val remote = config.optJSONObject("connection_policy") ?: return false\n        val recoverySeconds = remote.optLong("recovery_window_seconds", 60L).coerceIn(15L, 180L)\n        val gateWaitMs = remote.optLong("connection_gate_wait_ms", DEFAULT_GATE_WAIT_MS).coerceIn(500L, 8_000L)\n        prefs(context).edit()\n            .putLong(KEY_POLICY_RECOVERY_WINDOW_MS, recoverySeconds * 1_000L)\n            .putLong(KEY_POLICY_GATE_WAIT_MS, gateWaitMs)\n            .apply()\n        return true\n    }\n\n''',
)
replace_once(
    recovery,
    '''                    val lastLost = p.getLong(KEY_LAST_LOST_AT, 0L)\n                    if (lastLost > 0L && System.currentTimeMillis() - lastLost in 0..RECOVERY_WINDOW_MS) {\n                        p.edit().putLong(KEY_RECOVERY_UNTIL, System.currentTimeMillis() + RECOVERY_WINDOW_MS).apply()\n                    }\n''',
    '''                    val lastLost = p.getLong(KEY_LAST_LOST_AT, 0L)\n                    val recoveryWindowMs = policy(app).recoveryWindowMs\n                    if (lastLost > 0L && System.currentTimeMillis() - lastLost in 0..recoveryWindowMs) {\n                        p.edit().putLong(KEY_RECOVERY_UNTIL, System.currentTimeMillis() + recoveryWindowMs).apply()\n                    }\n''',
)
replace_once(
    recovery,
    '''                    val now = System.currentTimeMillis()\n                    prefs(app).edit()\n                        .putLong(KEY_LAST_LOST_AT, now)\n                        .putLong(KEY_RECOVERY_UNTIL, now + RECOVERY_WINDOW_MS)\n''',
    '''                    val now = System.currentTimeMillis()\n                    val recoveryWindowMs = policy(app).recoveryWindowMs\n                    prefs(app).edit()\n                        .putLong(KEY_LAST_LOST_AT, now)\n                        .putLong(KEY_RECOVERY_UNTIL, now + recoveryWindowMs)\n''',
)

runtime = ROOT / "android-source/BlueVpnRuntimeGate.kt"
replace_once(
    runtime,
    "    fun beginConnection(context: Context, timeoutMs: Long = 2_500L): Boolean {\n",
    "    fun beginConnection(context: Context, timeoutMs: Long = BlueVpnNetworkRecoveryManager.connectionGateWaitMs(context)): Boolean {\n",
)

account = ROOT / "android-source/BlueVpnAccountManager.kt"
replace_once(
    account,
    '''    fun applyRemoteMobileConfig(c: Context, config: JSONObject): Boolean {\n        val appContext = c.applicationContext\n        val free = config.optJSONObject("free_access") ?: return false\n''',
    '''    fun applyRemoteMobileConfig(c: Context, config: JSONObject): Boolean {\n        val appContext = c.applicationContext\n        BlueVpnNetworkRecoveryManager.applyRemotePolicy(appContext, config)\n        val free = config.optJSONObject("free_access") ?: return false\n''',
)

settings = ROOT / "android-source/BlueVpnSettingsActivity.kt"
replace_once(
    settings,
    "import com.v2ray.ang.bluevpn.BlueVpnPalette\n",
    "import com.v2ray.ang.bluevpn.BlueVpnNetworkRecoveryManager\nimport com.v2ray.ang.bluevpn.BlueVpnPalette\n",
)
replace_once(
    settings,
    '''        val phase = BlueVpnRuntimeGate.connectionPhase(this).name\n        val account = BlueVpnAccountManager.snapshot(this)\n''',
    '''        val phase = BlueVpnRuntimeGate.connectionPhase(this).name\n        val connectionPolicy = BlueVpnNetworkRecoveryManager.policy(this)\n        val account = BlueVpnAccountManager.snapshot(this)\n''',
)
replace_once(
    settings,
    '''            appendLine("Connection phase: $phase")\n            appendLine("Account session: ${if (account.email.isNotBlank()) "signed-in" else "guest"}")\n''',
    '''            appendLine("Connection phase: $phase")\n            appendLine("Recovery window: ${connectionPolicy.recoveryWindowMs / 1_000L}s")\n            appendLine("Connection gate wait: ${connectionPolicy.connectionGateWaitMs}ms")\n            appendLine("Account session: ${if (account.email.isNotBlank()) "signed-in" else "guest"}")\n''',
)

api = ROOT / "bluevpn-manager/includes/class-bluevpn-api.php"
replace_once(
    api,
    '''            'update_policy'=>[\n                'channel'=>$channel,\n                'automatic_download'=>$autoUpdate,\n                'force_update'=>$forceUpdate,\n                'beta_tester'=>(bool)($selection['beta_tester'] ?? false),\n            ],\n''',
    '''            'update_policy'=>[\n                'channel'=>$channel,\n                'automatic_download'=>$autoUpdate,\n                'force_update'=>$forceUpdate,\n                'beta_tester'=>(bool)($selection['beta_tester'] ?? false),\n            ],\n            'connection_policy'=>[\n                // Server-authored but client-bounded. Bad panel values cannot\n                // create infinite recovery windows or connection-gate stalls.\n                'recovery_window_seconds'=>max(15,min(180,(int)($s['android_recovery_window_seconds'] ?? 60))),\n                'connection_gate_wait_ms'=>max(500,min(8000,(int)($s['android_connection_gate_wait_ms'] ?? 2500))),\n            ],\n''',
)

test = ROOT / "tests/test_dual_control_plane_581.py"
replace_once(
    test,
    '''    def test_health_monitor_probes_both_domains(self):\n''',
    '''    def test_android_connection_policy_is_remote_but_safely_bounded(self):\n        api = (ROOT / "bluevpn-manager/includes/class-bluevpn-api.php").read_text(encoding="utf-8")\n        account = (ROOT / "android-source/BlueVpnAccountManager.kt").read_text(encoding="utf-8")\n        recovery = (ROOT / "android-source/BlueVpnNetworkRecoveryManager.kt").read_text(encoding="utf-8")\n        runtime = (ROOT / "android-source/BlueVpnRuntimeGate.kt").read_text(encoding="utf-8")\n        settings = (ROOT / "android-source/BlueVpnSettingsActivity.kt").read_text(encoding="utf-8")\n        self.assertIn("'connection_policy'=>[", api)\n        self.assertIn("android_recovery_window_seconds", api)\n        self.assertIn("android_connection_gate_wait_ms", api)\n        self.assertIn("BlueVpnNetworkRecoveryManager.applyRemotePolicy", account)\n        self.assertIn("coerceIn(15L, 180L)", recovery)\n        self.assertIn("coerceIn(500L, 8_000L)", recovery)\n        self.assertIn("BlueVpnNetworkRecoveryManager.connectionGateWaitMs(context)", runtime)\n        self.assertIn("Recovery window:", settings)\n        self.assertIn("Connection gate wait:", settings)\n\n    def test_health_monitor_probes_both_domains(self):\n''',
)

print("Android remote connection policy patch applied")
