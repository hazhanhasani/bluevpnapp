from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def replace_once(path: Path, old: str, new: str) -> None:
    text = path.read_text(encoding="utf-8")
    if old not in text:
        raise SystemExit(f"marker not found in {path}: {old[:140]!r}")
    path.write_text(text.replace(old, new, 1), encoding="utf-8")


# 1) Manager: expose all bounded Android connection policy controls in the existing App tab.
cc = ROOT / "bluevpn-manager/includes/class-bluevpn-control-center.php"
replace_once(
    cc,
    '''        self::input('owner','GitHub Owner',$cfg['owner'],true);self::input('repo','Repository',$cfg['repo'],true);self::input('minimum_version','حداقل نسخه قابل استفاده',$s['minimum_version'],true);self::input('support_url','لینک پشتیبانی',$s['support_url']);self::input('title_override','عنوان آپدیت (اختیاری)',$cfg['title_override']);self::textarea('message_override','متن آپدیت (اختیاری)',$cfg['message_override']);\n''',
    '''        self::input('owner','GitHub Owner',$cfg['owner'],true);self::input('repo','Repository',$cfg['repo'],true);self::input('minimum_version','حداقل نسخه قابل استفاده',$s['minimum_version'],true);self::input('support_url','لینک پشتیبانی',$s['support_url']);self::input('title_override','عنوان آپدیت (اختیاری)',$cfg['title_override']);self::textarea('message_override','متن آپدیت (اختیاری)',$cfg['message_override']);\n        self::input('android_recovery_window_seconds','پنجره Recovery شبکه (ثانیه)',(int)($s['android_recovery_window_seconds']??60),true);\n        self::input('android_connection_gate_wait_ms','انتظار Runtime Gate (میلی‌ثانیه)',(int)($s['android_connection_gate_wait_ms']??2500),true);\n        self::input('android_candidate_start_timeout_seconds','مهلت شروع هر سرور (ثانیه)',(int)($s['android_candidate_start_timeout_seconds']??12),true);\n        self::input('android_verification_timeout_seconds','مهلت تأیید اینترنت (ثانیه)',(int)($s['android_verification_timeout_seconds']??28),true);\n''',
)
replace_once(
    cc,
    '''        $s['minimum_version']=$min;$s['support_url']=esc_url_raw(wp_unslash($_POST['support_url']??''));$s['auto_update_stable']=isset($_POST['auto_update_stable']);$s['auto_update_beta']=isset($_POST['auto_update_beta']);$s['auto_update']=$s['auto_update_stable'];$s['maintenance']=isset($_POST['maintenance']);\n''',
    '''        $s['minimum_version']=$min;$s['support_url']=esc_url_raw(wp_unslash($_POST['support_url']??''));$s['auto_update_stable']=isset($_POST['auto_update_stable']);$s['auto_update_beta']=isset($_POST['auto_update_beta']);$s['auto_update']=$s['auto_update_stable'];$s['maintenance']=isset($_POST['maintenance']);\n        $s['android_recovery_window_seconds']=max(15,min(180,(int)($_POST['android_recovery_window_seconds']??60)));\n        $s['android_connection_gate_wait_ms']=max(500,min(8000,(int)($_POST['android_connection_gate_wait_ms']??2500)));\n        $s['android_candidate_start_timeout_seconds']=max(6,min(20,(int)($_POST['android_candidate_start_timeout_seconds']??12)));\n        $s['android_verification_timeout_seconds']=max(10,min(45,(int)($_POST['android_verification_timeout_seconds']??28)));\n''',
)
replace_once(
    cc,
    '''        echo '<label><input type="checkbox" name="maintenance" value="1" '.checked(!empty($s['maintenance']),true,false).'> حالت تعمیرات</label>';\n        echo '</div>';submit_button('ذخیره سیاست بروزرسانی','primary','submit',false);echo '</form></div>';\n''',
    '''        echo '<label><input type="checkbox" name="maintenance" value="1" '.checked(!empty($s['maintenance']),true,false).'> حالت تعمیرات</label>';\n        echo '</div><p class="bvc-note">سیاست اتصال Android در خود اپ نیز محدود می‌شود: Recovery بین ۱۵–۱۸۰ ثانیه، Runtime Gate بین ۵۰۰–۸۰۰۰ms، شروع سرور بین ۶–۲۰ ثانیه و تأیید اینترنت بین ۱۰–۴۵ ثانیه.</p>';submit_button('ذخیره سیاست بروزرسانی','primary','submit',false);echo '</form></div>';\n''',
)

# 2) Runtime audit: add stable, privacy-safe event taxonomy while preserving old generic events.
audit = ROOT / "android-source/BlueVpnRuntimeAudit.kt"
replace_once(
    audit,
    '''        CONTROL_PLANE_FAILOVER,\n        CONTROL_PLANE_FAILURE,\n        CONNECTION_PHASE,\n''',
    '''        CONTROL_PLANE_FAILOVER,\n        CONTROL_PLANE_FAILURE,\n        API_PRIMARY_FAILED,\n        API_FAILOVER_USED,\n        UPDATE_CHECK_FAILED,\n        VPN_VERIFICATION_FAILED,\n        CONNECTION_PHASE,\n''',
)

account = ROOT / "android-source/BlueVpnAccountManager.kt"
replace_once(
    account,
    '''                if (index < bases.lastIndex) {\n                    BlueVpnRuntimeAudit.record(\n                        c.applicationContext,\n                        BlueVpnRuntimeAudit.Event.CONTROL_PLANE_FAILOVER,\n                        "${method.uppercase(Locale.ROOT)}:${path.substringBefore('?')}:${index + 1}",\n                    )\n                }\n''',
    '''                if (index < bases.lastIndex) {\n                    val safeRoute = path.substringBefore('?')\n                    if (index == 0) {\n                        BlueVpnRuntimeAudit.record(\n                            c.applicationContext,\n                            BlueVpnRuntimeAudit.Event.API_PRIMARY_FAILED,\n                            "${method.uppercase(Locale.ROOT)}:$safeRoute:${error.status}",\n                        )\n                    }\n                    BlueVpnRuntimeAudit.record(\n                        c.applicationContext,\n                        BlueVpnRuntimeAudit.Event.API_FAILOVER_USED,\n                        "${method.uppercase(Locale.ROOT)}:$safeRoute:${index + 1}",\n                    )\n                    BlueVpnRuntimeAudit.record(\n                        c.applicationContext,\n                        BlueVpnRuntimeAudit.Event.CONTROL_PLANE_FAILOVER,\n                        "${method.uppercase(Locale.ROOT)}:$safeRoute:${index + 1}",\n                    )\n                }\n''',
)

update = ROOT / "android-source/BlueVpnUpdateManager.kt"
replace_once(
    update,
    '''            }.onFailure { error ->\n                activity.runOnUiThread {\n''',
    '''            }.onFailure { error ->\n                BlueVpnRuntimeAudit.record(\n                    activity.applicationContext,\n                    BlueVpnRuntimeAudit.Event.UPDATE_CHECK_FAILED,\n                    error.javaClass.simpleName,\n                )\n                activity.runOnUiThread {\n''',
)

# Verification timeout is an authoritative failed proof, not merely a UI timeout.
home = ROOT / "android-source/BlueVpnHomeActivity.kt"
replace_once(
    home,
    '''    private val verificationTimeout = Runnable {\n        val guid = verificationDeadlineGuid\n''',
    '''    private val verificationTimeout = Runnable {\n        val guid = verificationDeadlineGuid\n''',
)
# Insert audit directly before the terminal verification failover call, using a stable reason only.
text = home.read_text(encoding="utf-8")
needle = 'failCurrentAndTryNext("اتصال برقرار شد اما اینترنت تأیید نشد")'
if needle in text:
    text = text.replace(
        needle,
        '''BlueVpnRuntimeAudit.record(\n            applicationContext,\n            BlueVpnRuntimeAudit.Event.VPN_VERIFICATION_FAILED,\n            "verification_timeout",\n        )\n        ''' + needle,
        1,
    )
else:
    # Some upstream revisions use a different Persian copy. Anchor to verification timeout runnable
    # and its first failCurrentAndTryNext call without depending on presentation text.
    start = text.find("    private val verificationTimeout = Runnable {")
    if start < 0:
        raise SystemExit("verificationTimeout runnable not found")
    end = text.find("\n    }", start)
    block = text[start:end]
    call = "failCurrentAndTryNext("
    call_pos = block.find(call)
    if call_pos < 0:
        raise SystemExit("verification timeout failover call not found")
    absolute = start + call_pos
    insert = '''BlueVpnRuntimeAudit.record(\n            applicationContext,\n            BlueVpnRuntimeAudit.Event.VPN_VERIFICATION_FAILED,\n            "verification_timeout",\n        )\n        '''
    text = text[:absolute] + insert + text[absolute:]
home.write_text(text, encoding="utf-8")

# 3) Extend existing regression module to avoid release-manifest churn.
test = ROOT / "tests/test_dual_control_plane_581.py"
insert = '''    def test_android_manager_exposes_bounded_connection_policy_controls(self):\n        cc = (ROOT / "bluevpn-manager/includes/class-bluevpn-control-center.php").read_text(encoding="utf-8")\n        for key in (\n            "android_recovery_window_seconds",\n            "android_connection_gate_wait_ms",\n            "android_candidate_start_timeout_seconds",\n            "android_verification_timeout_seconds",\n        ):\n            self.assertIn(key, cc)\n        self.assertIn("max(15,min(180", cc)\n        self.assertIn("max(500,min(8000", cc)\n        self.assertIn("max(6,min(20", cc)\n        self.assertIn("max(10,min(45", cc)\n\n    def test_android_runtime_audit_has_actionable_privacy_safe_taxonomy(self):\n        audit = (ROOT / "android-source/BlueVpnRuntimeAudit.kt").read_text(encoding="utf-8")\n        account = (ROOT / "android-source/BlueVpnAccountManager.kt").read_text(encoding="utf-8")\n        updater = (ROOT / "android-source/BlueVpnUpdateManager.kt").read_text(encoding="utf-8")\n        home = (ROOT / "android-source/BlueVpnHomeActivity.kt").read_text(encoding="utf-8")\n        for event in ("API_PRIMARY_FAILED", "API_FAILOVER_USED", "UPDATE_CHECK_FAILED", "VPN_VERIFICATION_FAILED"):\n            self.assertIn(event, audit)\n        self.assertIn("Event.API_PRIMARY_FAILED", account)\n        self.assertIn("Event.API_FAILOVER_USED", account)\n        self.assertIn("Event.UPDATE_CHECK_FAILED", updater)\n        self.assertIn("Event.VPN_VERIFICATION_FAILED", home)\n        self.assertIn('replace(Regex("https?://', audit)\n        self.assertIn('"<ip>"', audit)\n\n'''
marker = "    def test_health_monitor_probes_both_domains(self):\n"
replace_once(test, marker, insert + marker)

print("Android policy UI + telemetry patch applied")
