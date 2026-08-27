from pathlib import Path
import json, subprocess

OLD_VERSION="6.0.0"
NEW_VERSION="6.0.1"
OLD_CODE="60000"
NEW_CODE="60001"

def replace_once(path, old, new, label):
    p=Path(path); s=p.read_text(encoding="utf-8")
    if old not in s: raise SystemExit(f"{label} marker not found in {path}")
    p.write_text(s.replace(old,new,1),encoding="utf-8")

# ---------------- Admin information architecture ----------------
ui=Path("bluevpn-manager/includes/class-bluevpn-unified-ui.php")
s=ui.read_text(encoding="utf-8")
a=s.index("    private static function nav(): array {")
b=s.index("    private static function icon(string $name): string {",a)
nav=r'''    private static function nav(): array {
        return [
            'داشبورد' => [
                ['bluevpn-manager', 'نمای کلی', 'dashboard'],
            ],
            'کاربران و فروش' => [
                ['bluevpn-customers', 'کاربران', 'users'],
                ['bluevpn-manual-customers', 'مشتریان دستی', 'manual'],
                ['bluevpn-plans', 'پلن‌ها', 'plans'],
                ['bluevpn-orders', 'سفارش‌ها و پرداخت‌ها', 'orders'],
                ['bluevpn-payments', 'درگاه و بلوپال', 'wallet'],
                ['bluevpn-manual', 'فعال‌سازی دستی', 'manual'],
                ['bluevpn-sms', 'SMS / OTP', 'sms'],
                ['bluevpn-support', 'پشتیبانی آنلاین', 'support'],
                ['bluevpn-telegram-bot', 'ربات تلگرام', 'bot'],
            ],
            'شبکه و سرویس' => [
                ['bluevpn-free-access', 'اتصال رایگان / WARP', 'free'],
                ['bluevpn-subscription-sources', 'Sourceهای اشتراک', 'link'],
                ['bluevpn-pasarguard', 'PasarGuard', 'server'],
                ['bluevpn-marzban', 'Marzban', 'server'],
                ['bluevpn-guardcore', 'GuardCore', 'shield'],
                ['bluevpn-guardcore-queue', 'صف GuardCore', 'queue'],
                ['bluevpn-gateway', 'Gateway Metering', 'shield'],
            ],
            'محصول و هوشمندی' => [
                ['bluevpn-blueai', 'BlueAI', 'ai'],
                ['bluevpn-ads', 'تبلیغات', 'ads'],
                ['bluevpn-app-update', 'اپ و انتشار', 'app'],
                ['bluevpn-app-connection', 'اتصال اپلیکیشن', 'link'],
            ],
            'سیستم و عملیات' => [
                ['bluevpn-production', 'سلامت و Backup', 'shield'],
                ['bluevpn-error-monitor', 'خطاها و مانیتورینگ', 'shield'],
                ['bluevpn-database', 'دیتابیس', 'db'],
                ['bluevpn-settings', 'تنظیمات عمومی', 'settings'],
                ['bluevpn-github-updater', 'آپدیت Manager', 'update'],
                ['bluevpn-migration', 'Migration', 'migration'],
            ],
        ];
    }

    private static function current_group(string $slug): string {
        foreach (self::nav() as $group => $items) {
            foreach ($items as $item) if (($item[0] ?? '') === $slug) return $group;
        }
        return 'BlueVPN';
    }

'''
s=s[:a]+nav+s[b:]
old="""        echo '<nav class="bluevpn-nav">';
        foreach (self::nav() as $group => $items) {
"""
new="""        echo '<div class="bluevpn-nav-search"><span>'.self::icon('search').'</span><input id="bluevpnNavSearch" type="search" autocomplete="off" placeholder="جستجو در پنل…"></div>';
        echo '<nav class="bluevpn-nav" id="bluevpnNav">';
        foreach (self::nav() as $group => $items) {
"""
if old not in s: raise SystemExit("admin nav shell marker missing")
s=s.replace(old,new,1)
old="""        echo '<header class="bluevpn-topbar"><div class="bluevpn-top-title"><button type="button" class="bluevpn-mobile-menu" id="bluevpnMenuToggle" aria-label="باز کردن منو">'.self::icon('menu').'</button><div><span class="bluevpn-kicker">BLUEVPN CONTROL CENTER</span><h1>'.esc_html($title).'</h1><p>'.esc_html($subtitle).'</p></div></div>';
"""
new="""        $group = self::current_group($current);
        echo '<header class="bluevpn-topbar"><div class="bluevpn-top-title"><button type="button" class="bluevpn-mobile-menu" id="bluevpnMenuToggle" aria-label="باز کردن منو">'.self::icon('menu').'</button><div><span class="bluevpn-kicker">'.esc_html($group).' • BLUEVPN CONTROL CENTER</span><h1>'.esc_html($title).'</h1><p><a class="bluevpn-breadcrumb-home" href="'.esc_url(admin_url('admin.php?page=bluevpn-manager')).'">داشبورد</a><span> / </span>'.esc_html($group).'<span> / </span>'.esc_html($title).'</p></div></div>';
"""
if old not in s: raise SystemExit("admin topbar marker missing")
s=s.replace(old,new,1)
# add search icon
needle="""            'dashboard' => '<path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z"/>',
"""
if needle not in s: raise SystemExit("icon insertion marker missing")
s=s.replace(needle,needle+"""            'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
""",1)
ui.write_text(s,encoding="utf-8")

css=Path("bluevpn-manager/assets/admin-unified.css")
c=css.read_text(encoding="utf-8")
c += r'''

/* 6.0.1 — administration information architecture */
.bluevpn-nav-search{display:flex;align-items:center;gap:8px;margin:10px 10px 4px;padding:8px 10px;border:1px solid var(--bvu-line);border-radius:11px;background:rgba(7,11,18,.58)}
.bluevpn-nav-search svg{width:15px;height:15px;flex:0 0 15px;color:#64748b}
body.bluevpn-standalone-admin .bluevpn-nav-search input[type=search]{width:100%!important;min-width:0!important;min-height:28px!important;height:28px!important;margin:0!important;padding:0!important;border:0!important;background:transparent!important;color:#dce7f4!important;font-size:10px!important;box-shadow:none!important}
.bluevpn-nav-search input::placeholder{color:#526176}
.bluevpn-nav-group.is-filter-hidden,.bluevpn-nav-item.is-filter-hidden{display:none!important}
.bluevpn-nav-group{padding-top:2px;border-top:1px solid rgba(148,163,184,.045)}
.bluevpn-nav-group:first-child{border-top:0}
.bluevpn-nav-label{color:#66778d!important;text-transform:none!important;font-size:9px!important}
.bluevpn-breadcrumb-home{color:#7dd3fc!important;text-decoration:none!important}
.bluevpn-topbar p span{color:#3f4d61;padding-inline:3px}
.bluevpn-settings-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;max-width:1120px}
.bluevpn-settings-section{background:rgba(14,22,38,.72);border:1px solid var(--bvu-line);border-radius:15px;padding:16px}
.bluevpn-settings-section h2{margin:0 0 4px!important;color:#fff!important;font-size:14px!important}
.bluevpn-settings-section>p{margin:0 0 13px!important;color:#708097!important;font-size:9px!important}
.bluevpn-settings-section .form-table{margin:0!important}
.bluevpn-settings-section .form-table th{width:145px!important;padding:9px 0!important}
.bluevpn-settings-section .form-table td{padding:7px 0!important}
.bluevpn-settings-actions{grid-column:1/-1;display:flex;justify-content:flex-start;padding-top:2px}
@media(max-width:900px){.bluevpn-settings-grid{grid-template-columns:1fr}}
@media(max-width:782px){.bluevpn-nav-search{margin:8px 10px 2px}.bluevpn-topbar p{display:none!important}.bluevpn-settings-section{padding:13px}.bluevpn-settings-section .form-table th,.bluevpn-settings-section .form-table td{display:block!important;width:100%!important;padding:4px 0!important}}
'''
css.write_text(c,encoding="utf-8")

js=Path("bluevpn-manager/assets/admin-unified.js")
j=js.read_text(encoding="utf-8")
insert="""
const navSearch=q('#bluevpnNavSearch');
if(navSearch){
  const normalize=v=>String(v||'').trim().toLocaleLowerCase('fa');
  navSearch.addEventListener('input',()=>{
    const term=normalize(navSearch.value);
    document.querySelectorAll('.bluevpn-nav-group').forEach(group=>{
      let visible=0;
      group.querySelectorAll('.bluevpn-nav-item').forEach(item=>{
        const hit=!term||normalize(item.textContent).includes(term);
        item.classList.toggle('is-filter-hidden',!hit);
        if(hit)visible++;
      });
      group.classList.toggle('is-filter-hidden',visible===0);
    });
  });
}
"""
anchor="const clock=q('#bluevpnLiveClock');"
if anchor not in j: raise SystemExit("admin js insertion point missing")
j=j.replace(anchor,insert+"\n"+anchor,1)
js.write_text(j,encoding="utf-8")

admin=Path("bluevpn-manager/includes/class-bluevpn-admin.php")
a=admin.read_text(encoding="utf-8")
old_start="    public static function settings_page(): void {"
start=a.index(old_start)
end=a.index("    public static function save_settings(): void {",start)
new_settings=r'''    public static function settings_page(): void {
        self::guard();
        $s=BlueVPN_DB::settings();
        self::head('تنظیمات عمومی BlueVPN');
        if(isset($_GET['saved'])) echo '<div class="notice notice-success"><p>تنظیمات ذخیره شد.</p></div>';
        echo '<div class="notice notice-info"><p>تنظیمات عملیاتی، Backup، WARP، تبلیغات و انتشار از این صفحه جدا شده‌اند و در بخش تخصصی خودشان مدیریت می‌شوند.</p></div>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('bluevpn_save_settings');
        echo '<input type="hidden" name="action" value="bluevpn_save_settings">';
        echo '<div class="bluevpn-settings-grid">';

        echo '<section class="bluevpn-settings-section"><h2>هویت اپلیکیشن</h2><p>نام و آدرس عمومی Control Plane.</p><table class="form-table">';
        echo '<tr><th>نام اپ</th><td><input class="regular-text" type="text" name="app_name" value="'.esc_attr((string)$s['app_name']).'"></td></tr>';
        echo '<tr><th>Base URL عمومی</th><td><input class="regular-text" dir="ltr" type="url" name="public_base_url" value="'.esc_attr((string)$s['public_base_url']).'"><p class="description">نسخه و فایل‌های نصب در «اپ و انتشار» مدیریت می‌شوند.</p></td></tr>';
        echo '</table></section>';

        echo '<section class="bluevpn-settings-section"><h2>اعلان سراسری</h2><p>پیامی که در کلاینت‌ها نمایش داده می‌شود.</p><table class="form-table">';
        echo '<tr><th>وضعیت</th><td><label><input type="checkbox" name="announcement_enabled" value="1" '.checked(!empty($s['announcement_enabled']),true,false).'> نمایش اعلان فعال باشد</label></td></tr>';
        echo '<tr><th>عنوان</th><td><input class="regular-text" type="text" name="announcement_title" value="'.esc_attr((string)$s['announcement_title']).'"></td></tr>';
        echo '<tr><th>متن</th><td><textarea name="announcement_message" rows="4">'.esc_textarea((string)$s['announcement_message']).'</textarea></td></tr>';
        echo '</table></section>';

        echo '<section class="bluevpn-settings-section"><h2>تنظیمات تخصصی</h2><p>هر حوزه تنظیمات مستقل خودش را دارد.</p>';
        $links=[
            'سلامت و Backup'=>'bluevpn-production',
            'اتصال رایگان / WARP'=>'bluevpn-free-access',
            'تبلیغات'=>'bluevpn-ads',
            'اپ و انتشار'=>'bluevpn-app-update',
            'خطاها و مانیتورینگ'=>'bluevpn-error-monitor',
            'آپدیت Manager'=>'bluevpn-github-updater',
        ];
        echo '<div class="bvc-actions">';
        foreach($links as $label=>$page) echo '<a class="button" href="'.esc_url(admin_url('admin.php?page='.$page)).'">'.esc_html($label).'</a>';
        echo '</div></section>';

        echo '<section class="bluevpn-settings-section"><h2>Endpointهای سیستم</h2><p>برای عیب‌یابی و اتصال سرویس‌ها.</p>';
        echo '<div class="bvp-code">'.esc_html(untrailingslashit(home_url('/'))).'</div>';
        echo '<p><a class="button" href="'.esc_url(admin_url('admin.php?page=bluevpn-app-connection')).'">بررسی اتصال اپلیکیشن</a></p></section>';

        echo '<div class="bluevpn-settings-actions">';
        submit_button('ذخیره تنظیمات عمومی','primary','submit',false);
        echo '</div></div></form>';
        self::foot();
    }

'''
a=a[:start]+new_settings+a[end:]
admin.write_text(a,encoding="utf-8")

# ---------------- Backup state machine ----------------
prod=Path("bluevpn-manager/includes/class-bluevpn-production.php")
p=prod.read_text(encoding="utf-8")
p=p.replace(
"    private const BACKUP_RECOVERY_RETRY_SECONDS = 21600;\n",
"    private const BACKUP_RECOVERY_RETRY_SECONDS = 21600;\n    private const BACKUP_RECOVERY_RUNNING_SECONDS = 2700;\n",
1)
p=p.replace(
"""            'last_attempt_ok' => null,
            'last_error' => '',
""",
"""            'last_attempt_ok' => null,
            'last_attempt_state' => 'running',
            'last_error' => '',
""",1)
p=p.replace(
"""                'last_attempt_ok' => true,
                'last_error' => '',
""",
"""                'last_attempt_ok' => true,
                'last_attempt_state' => 'succeeded',
                'last_error' => '',
""",1)
p=p.replace(
"""                'last_attempt_ok' => false,
                'last_error' => $e->getMessage(),
""",
"""                'last_attempt_ok' => false,
                'last_attempt_state' => 'failed',
                'last_error' => $e->getMessage(),
""",1)
old="""        $state = self::backup_state();
        $attemptTs = !empty($state['last_attempt_at']) ? strtotime((string)$state['last_attempt_at']) : 0;
        if ($attemptTs && $attemptTs > time() - self::BACKUP_RECOVERY_RETRY_SECONDS) {
            return ['attempted'=>false,'ok'=>!empty($state['last_attempt_ok']),'reason'=>'cooldown','error'=>(string)($state['last_error']??'')];
        }
"""
new="""        $state = self::backup_state();
        $attemptTs = !empty($state['last_attempt_at']) ? strtotime((string)$state['last_attempt_at']) : 0;
        $attemptState = (string)($state['last_attempt_state'] ?? '');
        $legacyRunning = array_key_exists('last_attempt_ok', $state) && $state['last_attempt_ok'] === null;
        if ($attemptTs && ($attemptState === 'running' || $legacyRunning) && $attemptTs > time() - self::BACKUP_RECOVERY_RUNNING_SECONDS) {
            return ['attempted'=>false,'ok'=>true,'reason'=>'running','started_at'=>(string)($state['last_attempt_at']??'')];
        }
        if ($attemptTs && $attemptTs > time() - self::BACKUP_RECOVERY_RETRY_SECONDS) {
            return ['attempted'=>false,'ok'=>!empty($state['last_attempt_ok']),'reason'=>'cooldown','error'=>(string)($state['last_error']??'')];
        }
"""
if old not in p: raise SystemExit("backup recovery cooldown marker missing")
p=p.replace(old,new,1)
old="""            'last_attempt_ok'=>$backupState['last_attempt_ok']??null,
            'last_error'=>(string)($backupState['last_error']??''),
"""
new="""            'last_attempt_ok'=>$backupState['last_attempt_ok']??null,
            'last_attempt_state'=>(string)($backupState['last_attempt_state']??''),
            'last_error'=>(string)($backupState['last_error']??''),
"""
p=p.replace(old,new,1)
old="""        $backupMessage = 'Backup بیش از ۴۸ ساعت قدیمی است؛ بازیابی خودکار فعال است.';
        if (!$backupFresh && !empty($statePublic['last_error'])) {
            $backupMessage = 'Backup بیش از ۴۸ ساعت قدیمی است؛ آخرین تلاش ناموفق بود: '.mb_substr($statePublic['last_error'], 0, 220);
        }
        $checks['backup'] = [
            'ok'=>(bool)$backupFresh,
            'severity'=>'warning',
            'code'=>'BACKUP_STALE',
            'message'=>$backupFresh?'Backup اخیر موجود است':$backupMessage,
"""
new="""        $attemptTs = !empty($statePublic['last_attempt_at']) ? strtotime((string)$statePublic['last_attempt_at']) : 0;
        $recoveryRunning = !$backupFresh && $attemptTs &&
            (($statePublic['last_attempt_state'] ?? '') === 'running' || $statePublic['last_attempt_ok'] === null) &&
            $attemptTs > time()-self::BACKUP_RECOVERY_RUNNING_SECONDS;
        $recoveryFailed = !$backupFresh && (($statePublic['last_attempt_state'] ?? '') === 'failed' || $statePublic['last_attempt_ok'] === false);
        $backupMessage = $recoveryRunning
            ? 'Recovery Backup در حال اجرا است.'
            : ($recoveryFailed
                ? 'آخرین Recovery Backup ناموفق بود: '.mb_substr((string)$statePublic['last_error'], 0, 220)
                : 'Backup بیش از ۴۸ ساعت قدیمی است؛ بازیابی خودکار فعال است.');
        $checks['backup'] = [
            'ok'=>(bool)($backupFresh || $recoveryRunning),
            'severity'=>$recoveryRunning?'info':'warning',
            'code'=>$backupFresh?'BACKUP_RECOVERED':($recoveryRunning?'BACKUP_RECOVERY_RUNNING':($recoveryFailed?'BACKUP_RECOVERY_FAILED':'BACKUP_STALE')),
            'message'=>$backupFresh?'Backup اخیر موجود است':$backupMessage,
"""
if old not in p: raise SystemExit("backup health message marker missing")
p=p.replace(old,new,1)
prod.write_text(p,encoding="utf-8")

# ---------------- Android AUTO location-aware queue ----------------
home=Path("android-source/BlueVpnHomeActivity.kt")
h=home.read_text(encoding="utf-8")
marker="""    private fun applyPreparedConnectionQueue(
        scoredQueue: List<BlueVpnSmartSelector.ScoredCandidate>,
"""
helper="""    private fun locationAwareAutoQueue(
        queue: List<BlueVpnSmartSelector.ScoredCandidate>,
    ): List<BlueVpnSmartSelector.ScoredCandidate> {
        if (queue.size <= 1) return queue
        val groups = queue.groupBy { it.candidate.location.key }
        val locationOrder = queue.map { it.candidate.location.key }.distinct()
        return buildList {
            locationOrder.forEach { key ->
                addAll(groups[key].orEmpty())
            }
        }
    }

"""
if marker not in h: raise SystemExit("android queue method marker missing")
h=h.replace(marker,helper+marker,1)
old="""        val orderedGuids = connectionReadyQueue.map { it.candidate.guid }.distinct()
        when (selectionMode) {
"""
new="""        val effectiveQueue = if (selectionMode == BlueVpnSelectionMode.AUTO) {
            locationAwareAutoQueue(connectionReadyQueue)
        } else {
            connectionReadyQueue
        }
        val orderedGuids = effectiveQueue.map { it.candidate.guid }.distinct()
        when (selectionMode) {
"""
if old not in h: raise SystemExit("android ordered queue marker missing")
h=h.replace(old,new,1)
h=h.replace("        val chosen = connectionReadyQueue.first()\n","        val chosen = effectiveQueue.first()\n",1)
# give generic core shutdown a bounded drain window; avoids poisoning next route
h=h.replace(
"""        ) 900L else 350L
""",
"""        ) 900L else 650L
""",1)
home.write_text(h,encoding="utf-8")

# ---------------- Windows bounded fast connect ----------------
orc=Path("bluevpn-windows/Services/ConnectionOrchestrator.cs")
w=orc.read_text(encoding="utf-8")
w=w.replace("attempt.CancelAfter(TimeSpan.FromSeconds(72));","attempt.CancelAfter(TimeSpan.FromSeconds(45));",1)
w=w.replace("var candidates = ranked.Where(x => x.ProbeLatencyMs < int.MaxValue).Take(8).ToList();","var candidates = ranked.Where(x => x.ProbeLatencyMs < int.MaxValue).Take(5).ToList();",1)
w=w.replace("if (candidates.Count == 0) candidates = ranked.Take(8).ToList();","if (candidates.Count == 0) candidates = ranked.Take(5).ToList();",1)
w=w.replace("candidateBudget.CancelAfter(TimeSpan.FromSeconds(24));","candidateBudget.CancelAfter(TimeSpan.FromSeconds(candidateIndex == 1 ? 10 : 12));",1)
w=w.replace("lastError = new TimeoutException($\"مسیر {candidateIndex} در ۲۴ ثانیه آماده نشد.\");","lastError = new TimeoutException($\"مسیر {candidateIndex} در زمان سریع اتصال آماده نشد.\");",1)
# WARP verification: use strict trace only when policy needs it.
w=w.replace(
"""                var verified = await SystemTunnelVerifier.VerifyAsync(before, _settings.ProbeUrl, true, blocked, _settings.Tun.Name, ct).ConfigureAwait(false);
""",
"""                var strictWarpTrace = warpPolicy.RequireExitTrace || blocked.Count > 0;
                var verified = await SystemTunnelVerifier.VerifyAsync(before, _settings.ProbeUrl, strictWarpTrace, blocked, _settings.Tun.Name, ct, 8).ConfigureAwait(false);
""",1)
orc.write_text(w,encoding="utf-8")

warp=Path("bluevpn-windows/Services/WarpConnectionController.cs")
ww=warp.read_text(encoding="utf-8")
ww=ww.replace(
"""                if (!(trace.Warp.Equals("on", StringComparison.OrdinalIgnoreCase) || trace.Warp.Equals("plus", StringComparison.OrdinalIgnoreCase)))
                    throw new InvalidOperationException("Cloudflare مسیر WARP را تأیید نکرد.");
""",
"""                var traceConfirmsWarp = trace.Warp.Equals("on", StringComparison.OrdinalIgnoreCase) || trace.Warp.Equals("plus", StringComparison.OrdinalIgnoreCase);
                if (policy.RequireExitTrace && !traceConfirmsWarp && policy.BlockedExitCountries.Count > 0)
                    throw new InvalidOperationException("Cloudflare مسیر WARP را برای سیاست خروجی سخت‌گیرانه تأیید نکرد.");
""",1)
warp.write_text(ww,encoding="utf-8")

# Android WARP: data-plane is authoritative when no blocked-exit policy exists.
awe=Path("android-source/BlueVpnWarpEngine.kt")
aw=awe.read_text(encoding="utf-8")
aw=aw.replace(
"""        if (policy.warpRequireExitTrace && (!traceSeen || country == null)) throw Failure(ErrorCode.EXIT_VALIDATION_FAILED, state, strategy, "Exit country could not be validated")
        if (policy.warpRequireExitTrace && !traceWarp) throw Failure(ErrorCode.EXIT_VALIDATION_FAILED, state, strategy, "Cloudflare trace did not confirm WARP")
""",
"""        val strictExitTrace = policy.warpRequireExitTrace && policy.warpBlockedExitCountries.isNotEmpty()
        if (strictExitTrace && (!traceSeen || country == null)) throw Failure(ErrorCode.EXIT_VALIDATION_FAILED, state, strategy, "Exit country could not be validated")
        if (strictExitTrace && !traceWarp) throw Failure(ErrorCode.EXIT_VALIDATION_FAILED, state, strategy, "Cloudflare trace did not confirm WARP")
""",1)
awe.write_text(aw,encoding="utf-8")

# ---------------- Regression contracts ----------------
Path("tests/test_admin_information_architecture_60001.py").write_text(r'''from pathlib import Path
import json, unittest
ROOT=Path(__file__).resolve().parents[1]

class AdminInformationArchitecture60001Tests(unittest.TestCase):
    def test_release(self):
        d=json.loads((ROOT/"release.json").read_text(encoding="utf-8"))
        self.assertEqual((d["version"],d["version_code"]),("6.0.1",60001))

    def test_admin_navigation_is_domain_grouped_and_searchable(self):
        ui=(ROOT/"bluevpn-manager/includes/class-bluevpn-unified-ui.php").read_text(encoding="utf-8")
        for token in ["کاربران و فروش","شبکه و سرویس","محصول و هوشمندی","سیستم و عملیات","bluevpn-production","bluevpnNavSearch","current_group"]:
            self.assertIn(token,ui)
        js=(ROOT/"bluevpn-manager/assets/admin-unified.js").read_text(encoding="utf-8")
        self.assertIn("bluevpnNavSearch",js)
        self.assertIn("is-filter-hidden",js)

    def test_general_settings_are_separated_from_operational_settings(self):
        admin=(ROOT/"bluevpn-manager/includes/class-bluevpn-admin.php").read_text(encoding="utf-8")
        self.assertIn("تنظیمات عمومی BlueVPN",admin)
        self.assertIn("تنظیمات تخصصی",admin)
        self.assertIn("سلامت و Backup",admin)
        self.assertIn("اتصال رایگان / WARP",admin)

if __name__=="__main__": unittest.main()
''',encoding="utf-8")

Path("tests/test_auto_location_failover_60001.py").write_text(r'''from pathlib import Path
import unittest
ROOT=Path(__file__).resolve().parents[1]
class AutoLocationFailover60001Tests(unittest.TestCase):
    def test_auto_groups_hidden_routes_by_location(self):
        s=(ROOT/"android-source/BlueVpnHomeActivity.kt").read_text(encoding="utf-8")
        self.assertIn("locationAwareAutoQueue",s)
        self.assertIn("groupBy { it.candidate.location.key }",s)
        self.assertIn("val effectiveQueue = if (selectionMode == BlueVpnSelectionMode.AUTO)",s)
        self.assertIn(") 900L else 650L",s)
if __name__=="__main__": unittest.main()
''',encoding="utf-8")

# update existing expectations
bt=Path("tests/test_backup_self_healing_51010.py")
b=bt.read_text(encoding="utf-8").replace('"6.0.0"','"6.0.1"').replace("60000","60001")
b=b.replace('"BACKUP_STALE",','"BACKUP_STALE",\n            "BACKUP_RECOVERY_RUNNING",\n            "BACKUP_RECOVERY_FAILED",')
bt.write_text(b,encoding="utf-8")

wt=Path("tests/test_windows_bounded_connect_failover_548.py")
x=wt.read_text(encoding="utf-8")
x=x.replace('attempt.CancelAfter(TimeSpan.FromSeconds(72))','attempt.CancelAfter(TimeSpan.FromSeconds(45))')
x=x.replace('candidateBudget.CancelAfter(TimeSpan.FromSeconds(24))','candidateBudget.CancelAfter(TimeSpan.FromSeconds(candidateIndex == 1 ? 10 : 12))')
wt.write_text(x,encoding="utf-8")

cw=Path("tests/test_connection_stability_warp_exhaustive_498.py")
x=cw.read_text(encoding="utf-8")
x=x.replace('self.assertIn("Exit country could not be validated", s)','self.assertIn("strictExitTrace", s)\n        self.assertIn("No tunneled HTTPS probe succeeded", s)')
cw.write_text(x,encoding="utf-8")

manifest=Path("tests/release_test_manifest.json")
m=json.loads(manifest.read_text(encoding="utf-8"))
tests=list(m.get("tests") or [])
for name in ["test_admin_information_architecture_60001.py","test_auto_location_failover_60001.py"]:
    if name not in tests: tests.append(name)
m["tests"]=sorted(tests)
manifest.write_text(json.dumps(m,ensure_ascii=False,indent=2)+"\n",encoding="utf-8")

# ---------------- Version 6.0.1 ----------------
vf=Path("version.json")
v=json.loads(vf.read_text(encoding="utf-8"))
v["version"]=NEW_VERSION
v["version_code"]=int(NEW_CODE)
v["components"]={k:NEW_VERSION for k in v.get("components",{})}
vf.write_text(json.dumps(v,ensure_ascii=False,indent=2)+"\n",encoding="utf-8")
subprocess.run(["python","scripts/sync_version.py"],check=True)

# synchronize current-version assertions/markers outside workflows
for raw in subprocess.check_output(["git","ls-files","-z"]).decode().split("\0"):
    if not raw or raw.startswith(".github/workflows/"): continue
    path=Path(raw)
    try: data=path.read_text(encoding="utf-8")
    except (UnicodeDecodeError,OSError): continue
    updated=data.replace(OLD_VERSION,NEW_VERSION).replace(OLD_CODE,NEW_CODE)
    if updated!=data: path.write_text(updated,encoding="utf-8")

subprocess.run(["python","scripts/sync_version.py","--check"],check=True)
subprocess.run(["python","scripts/validate_release.py"],check=True)
subprocess.run(["python","scripts/validate_windows.py"],check=True)
subprocess.run(["python","scripts/validate_php_release.py"],check=True)
