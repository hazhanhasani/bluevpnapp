from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def replace(path: str, old: str, new: str) -> None:
    p = ROOT / path
    text = p.read_text(encoding="utf-8")
    if old not in text:
        raise SystemExit(f"marker not found in {path}: {old[:120]!r}")
    p.write_text(text.replace(old, new, 1), encoding="utf-8")


# 1) WordPress: idempotent POST replay so Android can safely fail over between
# both control-plane domains without duplicating orders/OTP/support mutations.
replace(
    "bluevpn-manager/includes/class-bluevpn-api.php",
    """        add_action('rest_api_init', [self::class, 'register_routes']);\n        add_filter('rest_post_dispatch', [self::class, 'headers'], 10, 3);""",
    """        add_action('rest_api_init', [self::class, 'register_routes']);\n        add_filter('rest_pre_dispatch', [self::class, 'idempotency_pre_dispatch'], 10, 3);\n        add_filter('rest_post_dispatch', [self::class, 'headers'], 10, 3);""",
)
replace(
    "bluevpn-manager/includes/class-bluevpn-api.php",
    """    public static function headers($response,$server,$request){\n        if ($request instanceof WP_REST_Request && (str_starts_with($request->get_route(),'/bluevpn/') || str_starts_with($request->get_route(),'/bluevpn-system/'))) {\n            $response->header('Content-Language','fa-IR'); $response->header('X-BlueVPN-Timezone','Asia/Tehran'); $response->header('X-BlueVPN-Calendar','jalali');\n            $headers = $response->get_headers();\n            if (($headers['X-BlueVPN-Raw'] ?? '') !== '1') $response->header('Cache-Control','no-store');\n        }\n        return $response;\n    }""",
    """    public static function headers($response,$server,$request){\n        if ($request instanceof WP_REST_Request && (str_starts_with($request->get_route(),'/bluevpn/') || str_starts_with($request->get_route(),'/bluevpn-system/'))) {\n            $response->header('Content-Language','fa-IR'); $response->header('X-BlueVPN-Timezone','Asia/Tehran'); $response->header('X-BlueVPN-Calendar','jalali');\n            $headers = $response->get_headers();\n            if (($headers['X-BlueVPN-Raw'] ?? '') !== '1') $response->header('Cache-Control','no-store');\n            self::remember_idempotent_response($response, $request);\n        }\n        return $response;\n    }\n\n    /**\n     * Android retries the exact same mutating request on the secondary control\n     * plane only after a transport/502/503/504 failure. Both domains terminate\n     * on this WordPress database, so a short-lived replay record makes that\n     * failover at-most-once from the application's point of view.\n     */\n    private static function idempotency_context(WP_REST_Request $request): ?array {\n        if (strtoupper($request->get_method()) !== 'POST') return null;\n        $route = (string)$request->get_route();\n        if (!str_starts_with($route, '/bluevpn/')) return null;\n        $requestId = trim((string)$request->get_header('x-bluevpn-request-id'));\n        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,95}$/', $requestId)) return null;\n        $device = trim((string)$request->get_header('x-device-id'));\n        $key = 'bluevpn_idem_' . substr(hash('sha256', $requestId . '|' . $device . '|' . $route), 0, 48);\n        $fingerprint = hash('sha256', $route . '|' . (string)$request->get_body());\n        return [$key, $fingerprint];\n    }\n\n    public static function idempotency_pre_dispatch($result,$server,$request){\n        if ($result !== null || !($request instanceof WP_REST_Request)) return $result;\n        $ctx = self::idempotency_context($request);\n        if ($ctx === null) return $result;\n        [$key,$fingerprint] = $ctx;\n        $saved = get_transient($key);\n        if (!is_array($saved)) return $result;\n        if (!hash_equals((string)($saved['fingerprint'] ?? ''), $fingerprint)) {\n            return self::ok(['detail'=>['code'=>'IDEMPOTENCY_CONFLICT','message'=>'شناسه درخواست قبلاً برای عملیات دیگری استفاده شده است.']],409);\n        }\n        $response = new WP_REST_Response($saved['data'] ?? [], (int)($saved['status'] ?? 200));\n        foreach ((array)($saved['headers'] ?? []) as $name=>$value) $response->header((string)$name,(string)$value);\n        $response->header('X-BlueVPN-Idempotent-Replay','1');\n        return $response;\n    }\n\n    private static function remember_idempotent_response($response, WP_REST_Request $request): void {\n        $ctx = self::idempotency_context($request);\n        if ($ctx === null || !is_object($response) || !method_exists($response,'get_status') || !method_exists($response,'get_data')) return;\n        $status = (int)$response->get_status();\n        // Never freeze transient infrastructure failures; the secondary domain\n        // must still get a chance to execute the operation.\n        if ($status >= 500) return;\n        [$key,$fingerprint] = $ctx;\n        set_transient($key,[\n            'fingerprint'=>$fingerprint,\n            'status'=>$status,\n            'data'=>$response->get_data(),\n            'headers'=>method_exists($response,'get_headers') ? $response->get_headers() : [],\n        ],10 * MINUTE_IN_SECONDS);\n    }""",
)

# 2) Android: one request id per logical POST and the same id across domain
# attempts. GET and POST now share the dual-domain transport policy.
replace(
    "android-source/BlueVpnAccountManager.kt",
    """        val bases = if (method == \"GET\") apiBaseUrls() else listOf(apiBaseUrl())\n        var lastError: ApiException? = null\n        for (base in bases) {\n            try {\n                return requestAgainstBase(c, method, path, body, auth, accessOverride, base)\n            } catch (error: ApiException) {\n                lastError = error\n                if (error.status != 0 && error.status !in listOf(502, 503, 504)) throw error\n            }\n        }""",
    """        val bases = apiBaseUrls()\n        val requestId = if (method != \"GET\") UUID.randomUUID().toString() else null\n        var lastError: ApiException? = null\n        for ((index, base) in bases.withIndex()) {\n            try {\n                return requestAgainstBase(c, method, path, body, auth, accessOverride, base, requestId)\n            } catch (error: ApiException) {\n                lastError = error\n                val retryable = error.status == 0 || error.status in listOf(502, 503, 504)\n                if (!retryable) throw error\n                if (index < bases.lastIndex) {\n                    BlueVpnRuntimeAudit.record(\n                        c.applicationContext,\n                        BlueVpnRuntimeAudit.Event.CONTROL_PLANE_FAILOVER,\n                        \"${method.uppercase(Locale.ROOT)}:${path.substringBefore('?')}:${index + 1}\",\n                    )\n                }\n            }\n        }""",
)
replace(
    "android-source/BlueVpnAccountManager.kt",
    """        accessOverride: String?,\n        baseUrl: String,\n    ): JSONObject {""",
    """        accessOverride: String?,\n        baseUrl: String,\n        requestId: String?,\n    ): JSONObject {""",
)
replace(
    "android-source/BlueVpnAccountManager.kt",
    """            connection.setRequestProperty(\"Accept-Charset\", \"utf-8\")\n            connection.setRequestProperty(\n                \"X-Device-ID\",""",
    """            connection.setRequestProperty(\"Accept-Charset\", \"utf-8\")\n            if (!requestId.isNullOrBlank()) {\n                connection.setRequestProperty(\"X-BlueVPN-Request-ID\", requestId)\n            }\n            connection.setRequestProperty(\n                \"X-Device-ID\",""",
)

# 3) Runtime Gate: expose an explicit connection phase contract. Existing engine
# ownership stays intact; phase is additive and safe for UI/diagnostics/recovery.
replace(
    "android-source/BlueVpnRuntimeGate.kt",
    """object BlueVpnRuntimeGate {\n    private const val PREFS = \"bluevpn_runtime_gate\"\n    private const val KEY_CONNECTION_ACTIVE = \"connection_active\"""",
    """object BlueVpnRuntimeGate {\n    enum class ConnectionPhase { IDLE, PREPARING, CONNECTING, VERIFYING, CONNECTED, RECOVERING, FAILED }\n\n    private const val PREFS = \"bluevpn_runtime_gate\"\n    private const val KEY_CONNECTION_ACTIVE = \"connection_active\"\n    private const val KEY_CONNECTION_PHASE = \"connection_phase\"""",
)
replace(
    "android-source/BlueVpnRuntimeGate.kt",
    """    fun subscriptionMutationActive(): Boolean = subscriptionMutationActive\n\n    /**""",
    """    fun subscriptionMutationActive(): Boolean = subscriptionMutationActive\n\n    fun connectionPhase(context: Context): ConnectionPhase {\n        if (!connectionActive(context)) return ConnectionPhase.IDLE\n        return runCatching {\n            ConnectionPhase.valueOf(prefs(context).getString(KEY_CONNECTION_PHASE, ConnectionPhase.CONNECTED.name).orEmpty())\n        }.getOrDefault(ConnectionPhase.CONNECTED)\n    }\n\n    private fun setPhase(context: Context, phase: ConnectionPhase, detail: String = \"\") {\n        prefs(context).edit().putString(KEY_CONNECTION_PHASE, phase.name).apply()\n        runCatching {\n            BlueVpnRuntimeAudit.record(\n                context.applicationContext,\n                BlueVpnRuntimeAudit.Event.CONNECTION_PHASE,\n                if (detail.isBlank()) phase.name else \"${phase.name}:$detail\",\n            )\n        }\n    }\n\n    fun markConnecting(context: Context) = setPhase(context, ConnectionPhase.CONNECTING)\n    fun markVerifying(context: Context) = setPhase(context, ConnectionPhase.VERIFYING)\n    fun markRecovering(context: Context, detail: String = \"network_change\") = setPhase(context, ConnectionPhase.RECOVERING, detail)\n    fun markFailed(context: Context, detail: String = \"runtime\") = setPhase(context, ConnectionPhase.FAILED, detail)\n\n    /**""",
)
replace(
    "android-source/BlueVpnRuntimeGate.kt",
    """                .putInt(KEY_CONNECTION_OWNER_PID, Process.myPid())\n                .apply()\n            return true""",
    """                .putInt(KEY_CONNECTION_OWNER_PID, Process.myPid())\n                .putString(KEY_CONNECTION_PHASE, ConnectionPhase.PREPARING.name)\n                .apply()\n            setPhase(context, ConnectionPhase.PREPARING)\n            return true""",
)
replace(
    "android-source/BlueVpnRuntimeGate.kt",
    """                .putInt(KEY_CONNECTION_OWNER_PID, Process.myPid())\n                .apply()\n            monitor.notifyAll()""",
    """                .putInt(KEY_CONNECTION_OWNER_PID, Process.myPid())\n                .putString(KEY_CONNECTION_PHASE, ConnectionPhase.CONNECTED.name)\n                .apply()\n            setPhase(context, ConnectionPhase.CONNECTED)\n            monitor.notifyAll()""",
)
replace(
    "android-source/BlueVpnRuntimeGate.kt",
    """                .remove(KEY_CONNECTION_OWNER_PID)\n                .apply()\n            monitor.notifyAll()""",
    """                .remove(KEY_CONNECTION_OWNER_PID)\n                .putString(KEY_CONNECTION_PHASE, ConnectionPhase.IDLE.name)\n                .apply()\n            setPhase(context, ConnectionPhase.IDLE)\n            monitor.notifyAll()""",
)
# Stale cross-process gate recovery should clear phase too.
replace(
    "android-source/BlueVpnRuntimeGate.kt",
    """            .remove(KEY_CONNECTION_OWNER_PID)\n            .apply()\n        runCatching {""",
    """            .remove(KEY_CONNECTION_OWNER_PID)\n            .putString(KEY_CONNECTION_PHASE, ConnectionPhase.IDLE.name)\n            .apply()\n        runCatching {""",
)

replace(
    "android-source/BlueVpnRuntimeAudit.kt",
    """        RUNTIME_FAILURE,\n        RUNTIME_GATE_RECOVERY,""",
    """        RUNTIME_FAILURE,\n        RUNTIME_GATE_RECOVERY,\n        CONTROL_PLANE_FAILOVER,\n        CONTROL_PLANE_FAILURE,\n        CONNECTION_PHASE,""",
)

# Network loss moves an active session into RECOVERING but never claims CONNECTED
# on onAvailable; only the verified VPN engine is allowed to do that.
replace(
    "android-source/BlueVpnNetworkRecoveryManager.kt",
    """                    BlueVpnRuntimeAudit.record(\n                        app,\n                        BlueVpnRuntimeAudit.Event.NETWORK_CHANGE,\n                        \"lost\"\n                    )""",
    """                    BlueVpnRuntimeAudit.record(\n                        app,\n                        BlueVpnRuntimeAudit.Event.NETWORK_CHANGE,\n                        \"lost\"\n                    )\n                    if (BlueVpnRuntimeGate.connectionActive(app)) {\n                        BlueVpnRuntimeGate.markRecovering(app, \"physical_network_lost\")\n                    }""",
)

# Extend existing regression files (no new test file => release manifest remains exact).
replace(
    "tests/test_dual_control_plane_581.py",
    """        self.assertIn(\"requestAgainstBase\", account)""",
    """        self.assertIn(\"requestAgainstBase\", account)\n        self.assertIn(\"val bases = apiBaseUrls()\", account)\n        self.assertIn(\"X-BlueVPN-Request-ID\", account)\n        self.assertIn(\"UUID.randomUUID().toString()\", account)\n\n    def test_manager_deduplicates_mutating_failover_requests(self):\n        api = (ROOT / \"bluevpn-manager/includes/class-bluevpn-api.php\").read_text(encoding=\"utf-8\")\n        self.assertIn(\"idempotency_pre_dispatch\", api)\n        self.assertIn(\"x-bluevpn-request-id\", api.lower())\n        self.assertIn(\"IDEMPOTENCY_CONFLICT\", api)\n        self.assertIn(\"X-BlueVPN-Idempotent-Replay\", api)\n        self.assertIn(\"10 * MINUTE_IN_SECONDS\", api)""",
)

# Existing recovery regression gets state-machine guarantees as well.
p = ROOT / "tests/test_stability_auto_recovery_517.py"
text = p.read_text(encoding="utf-8")
needle = "class StabilityAutoRecovery517Tests(unittest.TestCase):"
if needle not in text:
    raise SystemExit("stability test class marker not found")
insert = '''class StabilityAutoRecovery517Tests(unittest.TestCase):\n    def test_connection_engine_v2_exposes_explicit_phases_and_recovery(self):\n        gate = (ROOT / "android-source/BlueVpnRuntimeGate.kt").read_text(encoding="utf-8")\n        recovery = (ROOT / "android-source/BlueVpnNetworkRecoveryManager.kt").read_text(encoding="utf-8")\n        audit = (ROOT / "android-source/BlueVpnRuntimeAudit.kt").read_text(encoding="utf-8")\n        for phase in ("IDLE", "PREPARING", "CONNECTING", "VERIFYING", "CONNECTED", "RECOVERING", "FAILED"):\n            self.assertIn(phase, gate)\n        self.assertIn("markRecovering", recovery)\n        self.assertIn("physical_network_lost", recovery)\n        self.assertIn("CONNECTION_PHASE", audit)\n        self.assertIn("CONTROL_PLANE_FAILOVER", audit)\n'''
p.write_text(text.replace(needle, insert, 1), encoding="utf-8")

print("Android Connection Engine v2 patch applied")
