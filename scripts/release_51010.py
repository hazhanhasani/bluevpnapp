from pathlib import Path
import json
import subprocess

OLD_VERSION = "5.10.9"
NEW_VERSION = "5.10.10"
OLD_CODE = "51009"
NEW_CODE = "51010"

production = Path("bluevpn-manager/includes/class-bluevpn-production.php")
p = production.read_text(encoding="utf-8")

p = p.replace(
"""    private const BACKUP_OPTION = 'bluevpn_manager_last_backup';
    private const RESTORE_OPTION = 'bluevpn_manager_last_restore';
""",
"""    private const BACKUP_OPTION = 'bluevpn_manager_last_backup';
    private const BACKUP_STATE_OPTION = 'bluevpn_manager_backup_state_v2';
    private const BACKUP_RECOVERY_LOCK = 'bluevpn_manager_backup_recovery_lock_v2';
    private const BACKUP_FRESH_SECONDS = 172800;
    private const BACKUP_RECOVERY_RETRY_SECONDS = 21600;
    private const BACKUP_CRON_OVERDUE_GRACE = 900;
    private const RESTORE_OPTION = 'bluevpn_manager_last_restore';
""",
1,
)

old_schedule = """    public static function ensure_schedule(): void {
        if (!wp_next_scheduled(self::BACKUP_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::BACKUP_HOOK);
        }
    }
"""
new_schedule = """    public static function ensure_schedule(): void {
        $now = time();
        $next = wp_next_scheduled(self::BACKUP_HOOK);

        // A scheduled event can remain stuck in the past when WP-Cron misses a run.
        // Repair only after a grace period so normal spawn_cron processing gets a
        // chance to execute first.
        if ($next && $next < ($now - self::BACKUP_CRON_OVERDUE_GRACE)) {
            self::unschedule();
            $next = false;
            self::update_backup_state([
                'schedule_repaired_at' => BlueVPN_Utils::iso_now(),
                'schedule_repair_reason' => 'overdue_event',
            ]);
        }

        if (!$next) {
            wp_schedule_event($now + 300, 'daily', self::BACKUP_HOOK);
            $next = wp_next_scheduled(self::BACKUP_HOOK);
        }

        self::update_backup_state([
            'next_scheduled_ts' => (int)($next ?: 0),
            'next_scheduled_at' => $next ? gmdate('c', (int)$next) : '',
        ]);
    }
"""
if old_schedule not in p:
    raise SystemExit("backup ensure_schedule block not found")
p = p.replace(old_schedule, new_schedule, 1)

old_backup = """    public static function create_backup(string $reason='manual'): array {
        $dir = self::backup_dir();
        if (!is_dir($dir) || !is_writable($dir)) throw new RuntimeException('مسیر خصوصی Backup قابل نوشتن نیست.');
        $json = self::encode_backup(self::canonical_payload());
        $suffix = substr(hash('sha256', wp_generate_password(32, true, true).microtime(true)), 0, 12);
        $name = 'bluevpn-'.gmdate('Ymd-His').'-'.$suffix.'.json';
        $path = trailingslashit($dir).$name;
        if (@file_put_contents($path, $json, LOCK_EX) === false) throw new RuntimeException('نوشتن فایل Backup ناموفق بود.');
        @chmod($path, 0600);
        $info = ['ok'=>true,'path'=>$path,'filename'=>$name,'size'=>filesize($path)?:strlen($json),'reason'=>$reason,'created_at'=>BlueVPN_Utils::iso_now(),'checksum'=>hash_file('sha256',$path)?:''];
        update_option(self::BACKUP_OPTION, $info, false);
        self::prune_backups();
        return $info;
    }

    public static function cron_backup(): void {
        try { self::create_backup('scheduled'); }
        catch (Throwable $e) {
            update_option(self::BACKUP_OPTION, ['ok'=>false,'error'=>$e->getMessage(),'reason'=>'scheduled','created_at'=>BlueVPN_Utils::iso_now()], false);
            BlueVPN_Error_Monitor::legacy_error_log('BlueVPN scheduled backup: '.$e->getMessage());
        }
    }
"""
new_backup = """    private static function update_backup_state(array $patch): array {
        $state = get_option(self::BACKUP_STATE_OPTION, []);
        if (!is_array($state)) $state = [];
        $state = array_merge($state, $patch);
        update_option(self::BACKUP_STATE_OPTION, $state, false);
        return $state;
    }

    public static function backup_state(): array {
        $state = get_option(self::BACKUP_STATE_OPTION, []);
        return is_array($state) ? $state : [];
    }

    public static function create_backup(string $reason='manual'): array {
        self::update_backup_state([
            'last_attempt_at' => BlueVPN_Utils::iso_now(),
            'last_attempt_reason' => $reason,
            'last_attempt_ok' => null,
            'last_error' => '',
        ]);

        $tmp = '';
        try {
            $dir = self::backup_dir();
            if (!is_dir($dir) || !is_writable($dir)) throw new RuntimeException('مسیر خصوصی Backup قابل نوشتن نیست.');

            $json = self::encode_backup(self::canonical_payload());
            $suffix = substr(hash('sha256', wp_generate_password(32, true, true).microtime(true)), 0, 12);
            $name = 'bluevpn-'.gmdate('Ymd-His').'-'.$suffix.'.json';
            $path = trailingslashit($dir).$name;
            $tmp = $path.'.tmp';

            $written = @file_put_contents($tmp, $json, LOCK_EX);
            if ($written === false || (int)$written !== strlen($json)) {
                throw new RuntimeException('نوشتن کامل فایل Backup ناموفق بود.');
            }
            @chmod($tmp, 0600);
            if (!@rename($tmp, $path)) {
                throw new RuntimeException('نهایی‌سازی اتمیک فایل Backup ناموفق بود.');
            }
            $tmp = '';

            $info = [
                'ok'=>true,
                'path'=>$path,
                'filename'=>$name,
                'size'=>filesize($path)?:strlen($json),
                'reason'=>$reason,
                'created_at'=>BlueVPN_Utils::iso_now(),
                'checksum'=>hash_file('sha256',$path)?:'',
            ];
            update_option(self::BACKUP_OPTION, $info, false);
            self::update_backup_state([
                'last_attempt_at' => (string)$info['created_at'],
                'last_attempt_reason' => $reason,
                'last_attempt_ok' => true,
                'last_error' => '',
                'last_success_at' => (string)$info['created_at'],
                'last_success_filename' => $name,
                'last_success_size' => (int)$info['size'],
            ]);
            self::prune_backups();
            return $info;
        } catch (Throwable $e) {
            if ($tmp !== '' && is_file($tmp)) @unlink($tmp);
            self::update_backup_state([
                'last_attempt_at' => BlueVPN_Utils::iso_now(),
                'last_attempt_reason' => $reason,
                'last_attempt_ok' => false,
                'last_error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public static function cron_backup(): void {
        try { self::create_backup('scheduled'); }
        catch (Throwable $e) {
            // Preserve BACKUP_OPTION as the last known-good snapshot. Failure
            // metadata lives in BACKUP_STATE_OPTION so health can show both.
            BlueVPN_Error_Monitor::legacy_error_log('BlueVPN scheduled backup: '.$e->getMessage());
        }
    }
"""
if old_backup not in p:
    raise SystemExit("backup create/cron block not found")
p = p.replace(old_backup, new_backup, 1)

old_status = """    public static function backup_status(): array {
        $last = get_option(self::BACKUP_OPTION, []);
        return is_array($last) ? $last : [];
    }

    public static function restore_status(): array {
"""
new_status = """    public static function backup_status(): array {
        $last = get_option(self::BACKUP_OPTION, []);
        return is_array($last) ? $last : [];
    }

    public static function recover_stale_backup_if_needed(): array {
        self::ensure_schedule();

        $last = self::backup_status();
        $lastTs = !empty($last['created_at']) ? strtotime((string)$last['created_at']) : 0;
        if (!empty($last['ok']) && $lastTs && $lastTs > time() - self::BACKUP_FRESH_SECONDS) {
            return ['attempted'=>false,'ok'=>true,'reason'=>'fresh'];
        }

        $state = self::backup_state();
        $attemptTs = !empty($state['last_attempt_at']) ? strtotime((string)$state['last_attempt_at']) : 0;
        if ($attemptTs && $attemptTs > time() - self::BACKUP_RECOVERY_RETRY_SECONDS) {
            return ['attempted'=>false,'ok'=>!empty($state['last_attempt_ok']),'reason'=>'cooldown','error'=>(string)($state['last_error']??'')];
        }
        if (get_transient(self::BACKUP_RECOVERY_LOCK)) {
            return ['attempted'=>false,'ok'=>false,'reason'=>'locked'];
        }

        // Set the cooldown before starting the potentially large snapshot so a
        // fatal timeout cannot cause minute-by-minute retry storms.
        set_transient(self::BACKUP_RECOVERY_LOCK, '1', self::BACKUP_RECOVERY_RETRY_SECONDS);
        try {
            $info = self::create_backup('health-recovery');
            return ['attempted'=>true,'ok'=>true,'reason'=>'recovered','backup'=>$info['filename']??''];
        } catch (Throwable $e) {
            BlueVPN_Error_Monitor::legacy_error_log('BlueVPN backup health recovery: '.$e->getMessage());
            return ['attempted'=>true,'ok'=>false,'reason'=>'failed','error'=>$e->getMessage()];
        }
    }

    public static function restore_status(): array {
"""
if old_status not in p:
    raise SystemExit("backup status block not found")
p = p.replace(old_status, new_status, 1)

old_health = """        $backup = self::backup_status();
        $backupTs = !empty($backup['created_at']) ? strtotime((string)$backup['created_at']) : false;
        $backupFresh = !empty($backup['ok']) && $backupTs && $backupTs > time()-2*DAY_IN_SECONDS;
        $backupPublic=['ok'=>!empty($backup['ok']),'filename'=>(string)($backup['filename']??''),'size'=>(int)($backup['size']??0),'created_at'=>(string)($backup['created_at']??''),'error'=>(string)($backup['error']??'')];
        $checks['backup'] = ['ok'=>(bool)$backupFresh,'message'=>$backupFresh?'Backup اخیر موجود است':'Backup سالم در ۴۸ ساعت اخیر ثبت نشده','last'=>$backupPublic];

        $cron = wp_next_scheduled(self::BACKUP_HOOK);
        $checks['cron'] = ['ok'=>(bool)$cron,'message'=>$cron?'Backup cron برنامه‌ریزی شده است':'Backup cron زمان‌بندی نشده است','next'=>$cron?:0];
"""
new_health = """        $backup = self::backup_status();
        $backupState = self::backup_state();
        $backupTs = !empty($backup['created_at']) ? strtotime((string)$backup['created_at']) : false;
        $backupFresh = !empty($backup['ok']) && $backupTs && $backupTs > time()-self::BACKUP_FRESH_SECONDS;
        $backupPublic=[
            'ok'=>!empty($backup['ok']),
            'filename'=>(string)($backup['filename']??''),
            'size'=>(int)($backup['size']??0),
            'created_at'=>(string)($backup['created_at']??''),
            'error'=>(string)($backup['error']??''),
        ];
        $statePublic=[
            'last_attempt_at'=>(string)($backupState['last_attempt_at']??''),
            'last_attempt_reason'=>(string)($backupState['last_attempt_reason']??''),
            'last_attempt_ok'=>$backupState['last_attempt_ok']??null,
            'last_error'=>(string)($backupState['last_error']??''),
            'last_success_at'=>(string)($backupState['last_success_at']??''),
            'next_scheduled_at'=>(string)($backupState['next_scheduled_at']??''),
        ];
        $backupMessage = 'Backup بیش از ۴۸ ساعت قدیمی است؛ بازیابی خودکار فعال است.';
        if (!$backupFresh && !empty($statePublic['last_error'])) {
            $backupMessage = 'Backup بیش از ۴۸ ساعت قدیمی است؛ آخرین تلاش ناموفق بود: '.mb_substr($statePublic['last_error'], 0, 220);
        }
        $checks['backup'] = [
            'ok'=>(bool)$backupFresh,
            'severity'=>'warning',
            'code'=>'BACKUP_STALE',
            'message'=>$backupFresh?'Backup اخیر موجود است':$backupMessage,
            'last'=>$backupPublic,
            'state'=>$statePublic,
            'action'=>'Recovery خودکار حداکثر هر ۶ ساعت تلاش می‌کند؛ در صورت تداوم خطا، دسترسی نوشتن و فضای دیسک مسیر Backup بررسی شود.',
        ];

        $cron = wp_next_scheduled(self::BACKUP_HOOK);
        $cronOverdue = $cron && $cron < time()-self::BACKUP_CRON_OVERDUE_GRACE;
        $checks['cron'] = [
            'ok'=>(bool)($cron && !$cronOverdue),
            'severity'=>'warning',
            'code'=>$cronOverdue?'BACKUP_CRON_OVERDUE':'BACKUP_CRON_MISSING',
            'message'=>$cronOverdue?'Backup cron از زمان اجرای برنامه‌ریزی‌شده عبور کرده است':($cron?'Backup cron برنامه‌ریزی شده است':'Backup cron زمان‌بندی نشده است'),
            'next'=>$cron?:0,
            'next_at'=>$cron?gmdate('c',(int)$cron):'',
        ];
"""
if old_health not in p:
    raise SystemExit("backup health block not found")
p = p.replace(old_health, new_health, 1)
production.write_text(p, encoding="utf-8")

monitor = Path("bluevpn-manager/includes/class-bluevpn-error-monitor.php")
m = monitor.read_text(encoding="utf-8")
old_monitor = """            if (class_exists('BlueVPN_Production')) {
                $health = BlueVPN_Production::health_summary();
"""
new_monitor = """            if (class_exists('BlueVPN_Production')) {
                // Sentinel itself is already running, so WP-Cron is alive enough
                // to perform a bounded recovery attempt for a stale daily backup.
                BlueVPN_Production::recover_stale_backup_if_needed();
                $health = BlueVPN_Production::health_summary();
"""
if old_monitor not in m:
    raise SystemExit("sentinel production health block not found")
m = m.replace(old_monitor, new_monitor, 1)
monitor.write_text(m, encoding="utf-8")

test = Path("tests/test_backup_self_healing_51010.py")
test.write_text("""from pathlib import Path
import json
import unittest

ROOT = Path(__file__).resolve().parents[1]

class BackupSelfHealing51010Tests(unittest.TestCase):
    def test_release_version(self):
        release = json.loads((ROOT / "release.json").read_text(encoding="utf-8"))
        self.assertEqual(release["version"], "5.10.10")
        self.assertEqual(release["version_code"], 51010)

    def test_backup_has_state_atomic_write_and_bounded_recovery(self):
        src = (ROOT / "bluevpn-manager/includes/class-bluevpn-production.php").read_text(encoding="utf-8")
        for token in [
            "BACKUP_STATE_OPTION",
            "BACKUP_RECOVERY_LOCK",
            "BACKUP_RECOVERY_RETRY_SECONDS",
            "recover_stale_backup_if_needed",
            "last_attempt_at",
            "last_success_at",
            "health-recovery",
            "$path.'.tmp'",
            "@rename($tmp, $path)",
            "BACKUP_STALE",
            "BACKUP_CRON_OVERDUE",
            "schedule_repaired_at",
        ]:
            self.assertIn(token, src)
        self.assertNotIn("update_option(self::BACKUP_OPTION, ['ok'=>false", src)

    def test_sentinel_attempts_recovery_before_reporting_backup_health(self):
        src = (ROOT / "bluevpn-manager/includes/class-bluevpn-error-monitor.php").read_text(encoding="utf-8")
        recovery = src.index("BlueVPN_Production::recover_stale_backup_if_needed();")
        health = src.index("BlueVPN_Production::health_summary();")
        self.assertLess(recovery, health)

if __name__ == "__main__":
    unittest.main()
""", encoding="utf-8")

vf = Path("version.json")
v = json.loads(vf.read_text(encoding="utf-8"))
v["version"] = NEW_VERSION
v["version_code"] = int(NEW_CODE)
v["components"] = {k: NEW_VERSION for k in v.get("components", {})}
vf.write_text(json.dumps(v, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

subprocess.run(["python", "scripts/sync_version.py"], check=True)

# Keep all current version assertions/markers synchronized, excluding workflow
# files because GitHub's Actions token cannot modify workflows.
paths = subprocess.check_output(["git", "ls-files", "-z"]).decode().split("\0")
for raw in paths:
    if not raw or raw.startswith(".github/workflows/"):
        continue
    path = Path(raw)
    try:
        data = path.read_text(encoding="utf-8")
    except (UnicodeDecodeError, OSError):
        continue
    updated = data.replace(OLD_VERSION, NEW_VERSION).replace(OLD_CODE, NEW_CODE)
    if updated != data:
        path.write_text(updated, encoding="utf-8")

subprocess.run(["python", "scripts/sync_version.py", "--check"], check=True)
subprocess.run(["python", "scripts/validate_release.py"], check=True)
subprocess.run(["python", "scripts/validate_windows.py"], check=True)
subprocess.run(["python", "scripts/validate_php_release.py"], check=True)
