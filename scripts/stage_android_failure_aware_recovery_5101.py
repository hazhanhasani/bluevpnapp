#!/usr/bin/env python3
from pathlib import Path

home_path = Path('android-source/BlueVpnHomeActivity.kt')
audit_path = Path('android-source/BlueVpnRuntimeAudit.kt')
test_path = Path('tests/test_android_xray_json_failover_545.py')

home = home_path.read_text(encoding='utf-8')
audit = audit_path.read_text(encoding='utf-8')
test = test_path.read_text(encoding='utf-8')

anchor = '''    private fun failCurrentAndTryNext(reason: String) {
        if (!failoverActive || userDisconnecting) return

        lastCandidateFailureReason = reason.trim().ifBlank { "اتصال فعلی پاسخ نداد" }
        val failedGuid = attemptedGuid
        lifecycleScope.launch(Dispatchers.IO) {
            BlueVpnAi.recordFailure(
                this@BlueVpnHomeActivity,
                failedGuid,
                reason,
            )
        }
'''
replacement = '''    private enum class ConnectionFailureClass {
        CONFIG_INVALID,
        DNS,
        TLS_HANDSHAKE,
        CORE_START_TIMEOUT,
        SERVER_UNREACHABLE,
        EGRESS_VERIFICATION,
        UNKNOWN,
    }

    private data class ConnectionFailurePolicy(
        val failureClass: ConnectionFailureClass,
        val hardPenalty: Boolean,
        val retryDelayMs: Long,
    )

    private fun classifyConnectionFailure(reason: String): ConnectionFailurePolicy {
        val lower = reason.lowercase(Locale.US)
        val failureClass = when {
            "کانفیگ این مسیر نامعتبر" in reason ||
                "کانفیگ از pool فعال خارج شده" in lower ||
                "failed to parse json" in lower ||
                "parse json config" in lower ||
                "invalid character" in lower -> ConnectionFailureClass.CONFIG_INVALID
            "تأیید اینترنت" in reason ||
                "تست واقعی اینترنت" in reason ||
                "اینترنت واقعی" in reason ||
                "verification" in lower -> ConnectionFailureClass.EGRESS_VERIFICATION
            "هسته xray در زمان مجاز شروع نشد" in lower -> ConnectionFailureClass.CORE_START_TIMEOUT
            "dns" in lower ||
                "no such host" in lower ||
                "name resolution" in lower ||
                "unknownhost" in lower ||
                "lookup " in lower -> ConnectionFailureClass.DNS
            "tls" in lower ||
                "ssl" in lower ||
                "handshake" in lower ||
                "x509" in lower ||
                "certificate" in lower -> ConnectionFailureClass.TLS_HANDSHAKE
            "connection refused" in lower ||
                "connection reset" in lower ||
                "network is unreachable" in lower ||
                "network unreachable" in lower ||
                "no route to host" in lower ||
                "dial tcp" in lower ||
                "i/o timeout" in lower -> ConnectionFailureClass.SERVER_UNREACHABLE
            else -> ConnectionFailureClass.UNKNOWN
        }
        return when (failureClass) {
            ConnectionFailureClass.CONFIG_INVALID -> ConnectionFailurePolicy(failureClass, true, 900L)
            ConnectionFailureClass.DNS -> ConnectionFailurePolicy(failureClass, false, 650L)
            ConnectionFailureClass.TLS_HANDSHAKE -> ConnectionFailurePolicy(failureClass, true, 700L)
            ConnectionFailureClass.CORE_START_TIMEOUT -> ConnectionFailurePolicy(failureClass, true, 600L)
            ConnectionFailureClass.SERVER_UNREACHABLE -> ConnectionFailurePolicy(failureClass, true, 450L)
            ConnectionFailureClass.EGRESS_VERIFICATION -> ConnectionFailurePolicy(failureClass, false, 350L)
            ConnectionFailureClass.UNKNOWN -> ConnectionFailurePolicy(failureClass, false, 500L)
        }
    }

    private fun failCurrentAndTryNext(reason: String) {
        if (!failoverActive || userDisconnecting) return

        lastCandidateFailureReason = reason.trim().ifBlank { "اتصال فعلی پاسخ نداد" }
        val failurePolicy = classifyConnectionFailure(lastCandidateFailureReason)
        val failedGuid = attemptedGuid
        BlueVpnRuntimeAudit.record(
            applicationContext,
            BlueVpnRuntimeAudit.Event.VPN_FAILURE_CLASSIFIED,
            "${failurePolicy.failureClass.name}:${if (failurePolicy.hardPenalty) "hard" else "soft"}",
        )
        if (failurePolicy.hardPenalty) {
            lifecycleScope.launch(Dispatchers.IO) {
                BlueVpnAi.recordFailure(
                    this@BlueVpnHomeActivity,
                    failedGuid,
                    reason,
                )
            }
        }
'''
if home.count(anchor) != 1:
    raise SystemExit(f'failover anchor count={home.count(anchor)}')
home = home.replace(anchor, replacement, 1)

penalty = '''        if (failedGuid.isNotBlank()) {
            // Hard quarantine for this connect cycle. The next explicit connect
            // attempt clears only this temporary flag, while failedRecently()
            // keeps a short-lived score penalty so the same route is not picked
            // first again immediately.
            BlueVpnPreferences.markSessionInactive(this, failedGuid)
            BlueVpnPreferences.markServerFailure(this, failedGuid)
            BlueVpnRouteIntelligence.recordFailure(this, failedGuid, reason)
            BlueVpnIntelligenceCore.resolveDecision(
                context = this,
                guid = failedGuid,
                success = false,
                failureReason = reason,
            )
            MmkvManager.encodeServerTestDelayMillis(failedGuid, -1L)
        }
'''
penalty_new = '''        if (failedGuid.isNotBlank()) {
            // Every failed candidate leaves the current connection cycle so the
            // queue advances. Only route-specific failures poison persistent
            // scoring/history. DNS and egress-proof failures are frequently
            // physical-network or probe-target noise and therefore stay soft.
            BlueVpnPreferences.markSessionInactive(this, failedGuid)
            if (failurePolicy.hardPenalty) {
                BlueVpnPreferences.markServerFailure(this, failedGuid)
                BlueVpnRouteIntelligence.recordFailure(this, failedGuid, reason)
                BlueVpnIntelligenceCore.resolveDecision(
                    context = this,
                    guid = failedGuid,
                    success = false,
                    failureReason = reason,
                )
                MmkvManager.encodeServerTestDelayMillis(failedGuid, -1L)
            }
        }
'''
if home.count(penalty) != 1:
    raise SystemExit(f'penalty anchor count={home.count(penalty)}')
home = home.replace(penalty, penalty_new, 1)

retry = '''        val retryDelayMs = if (
            reason.contains("کانفیگ این مسیر نامعتبر بود") ||
            reason.contains("failed to parse json", ignoreCase = true)
        ) 900L else 350L
        handler.postDelayed({
'''
retry_new = '''        val retryDelayMs = failurePolicy.retryDelayMs
        handler.postDelayed({
'''
if home.count(retry) != 1:
    raise SystemExit(f'retry anchor count={home.count(retry)}')
home = home.replace(retry, retry_new, 1)

if '        VPN_FAILURE_CLASSIFIED,\n' not in audit:
    enum_anchor = '        VPN_VERIFICATION_FAILED,\n'
    if audit.count(enum_anchor) != 1:
        raise SystemExit(f'audit anchor count={audit.count(enum_anchor)}')
    audit = audit.replace(enum_anchor, enum_anchor + '        VPN_FAILURE_CLASSIFIED,\n', 1)

old_test = '''    def test_xray_teardown_gets_drain_window_before_next_guid(self):
        self.assertIn("val retryDelayMs = if", self.home)
        self.assertIn(") 900L else 350L", self.home)
        self.assertIn("handler.postDelayed({", self.home)
        self.assertIn("if (failoverActive) startCurrentCandidate()", self.home)
'''
new_test = '''    def test_xray_teardown_uses_failure_class_backoff_before_next_guid(self):
        self.assertIn("val retryDelayMs = failurePolicy.retryDelayMs", self.home)
        self.assertIn("ConnectionFailureClass.CONFIG_INVALID -> ConnectionFailurePolicy(failureClass, true, 900L)", self.home)
        self.assertIn("ConnectionFailureClass.EGRESS_VERIFICATION -> ConnectionFailurePolicy(failureClass, false, 350L)", self.home)
        self.assertIn("handler.postDelayed({", self.home)
        self.assertIn("if (failoverActive) startCurrentCandidate()", self.home)

    def test_transient_network_failures_do_not_poison_persistent_server_history(self):
        self.assertIn("ConnectionFailureClass.DNS -> ConnectionFailurePolicy(failureClass, false, 650L)", self.home)
        self.assertIn("ConnectionFailureClass.UNKNOWN -> ConnectionFailurePolicy(failureClass, false, 500L)", self.home)
        self.assertIn("if (failurePolicy.hardPenalty) {", self.home)
        self.assertIn("BlueVpnPreferences.markServerFailure(this, failedGuid)", self.home)
        self.assertIn("BlueVpnRouteIntelligence.recordFailure(this, failedGuid, reason)", self.home)

    def test_failure_classification_is_privacy_safe_audit_metadata(self):
        audit = (ROOT / "android-source/BlueVpnRuntimeAudit.kt").read_text()
        self.assertIn("VPN_FAILURE_CLASSIFIED", audit)
        self.assertIn("failurePolicy.failureClass.name", self.home)
        self.assertIn('if (failurePolicy.hardPenalty) "hard" else "soft"', self.home)
'''
if test.count(old_test) != 1:
    raise SystemExit(f'test anchor count={test.count(old_test)}')
test = test.replace(old_test, new_test, 1)

home_path.write_text(home, encoding='utf-8')
audit_path.write_text(audit, encoding='utf-8')
test_path.write_text(test, encoding='utf-8')
print('Applied failure-aware recovery patch')
