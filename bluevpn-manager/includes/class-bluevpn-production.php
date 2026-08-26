<?php
if (!defined('ABSPATH')) exit;

final class BlueVPN_Production {
    public const BACKUP_HOOK = 'bluevpn_daily_private_backup';
    private const BACKUP_RETENTION = 7;
    private const BACKUP_OPTION = 'bluevpn_manager_last_backup';
    private const BACKUP_STATE_OPTION = 'bluevpn_manager_backup_state_v2';
    private const BACKUP_RECOVERY_LOCK = 'bluevpn_manager_backup_recovery_lock_v2';
    private const BACKUP_FRESH_SECONDS = 172800;
    private const BACKUP_RECOVERY_RETRY_SECONDS = 21600;
    private const BACKUP_CRON_OVERDUE_GRACE = 900;
    private const RESTORE_OPTION = 'bluevpn_manager_last_restore';
    private const CONTROL_PLANE_OPTION = 'bluevpn_manager_control_plane_mode';
    private const NATIVE_CONTROL_PLANE = 'wordpress_mysql_native';
    private const NATIVE_RECONCILE_OPTION = 'bluevpn_manager_native_reconcile_4166';
    private const NATIVE_RECONCILE_HOOK = 'bluevpn_native_cutover_reconcile';
    private const NATIVE_CUTOVER_REVISION_OPTION = 'bluevpn_manager_native_cutover_revision';
    private const NATIVE_CUTOVER_REVISION = 41607;

    public static function init(): void {
        add_action(self::BACKUP_HOOK, [self::class, 'cron_backup']);
        add_action(self::NATIVE_RECONCILE_HOOK, [self::class, 'reconcile_legacy_paid_orders_once']);
        self::ensure_native_control_plane();
        self::ensure_schedule();
    }

    public static function activate(): void {
        self::ensure_native_control_plane();
        self::ensure_schedule();
    }

    public static function native_control_plane(): bool {
        return (string)get_option(self::CONTROL_PLANE_OPTION, '') === self::NATIVE_CONTROL_PLANE
            && get_option('bluevpn_manager_legacy_bridge_disabled','0') === '1';
    }

    /**
     * 4.16.6 is the irreversible WordPress/MySQL-native cutover. The legacy
     * migration source is no longer part of runtime operation. We keep the old
     * migration code only as dormant recovery tooling for old backups; all
     * schedules, source URL and tokens are retired here.
     */
    public static function ensure_native_control_plane(): void {
        try {
            $db = BlueVPN_DB::status();
            if (empty($db['ready'])) return;

            // 4.17.5: first repair legacy non-grant retries/reconcile calls that
            // older provision_customer() versions incorrectly treated as renewals,
            // then keep the narrower duplicate-attempt repair as a second guard.
            if (class_exists('BlueVPN_Providers')) {
                BlueVPN_Providers::repair_legacy_non_grant_expiry_inflation();
                BlueVPN_Providers::repair_duplicate_provision_expiry_inflation();
            }

            $cutoverRevision = (int)get_option(self::NATIVE_CUTOVER_REVISION_OPTION, 0);
            $needsRetirementPass = $cutoverRevision < self::NATIVE_CUTOVER_REVISION;

            update_option(self::CONTROL_PLANE_OPTION, self::NATIVE_CONTROL_PLANE, false);
            update_option('bluevpn_manager_cutover_ready', '1', false);
            update_option('bluevpn_manager_app_cutover_enabled', '1', false);
            update_option('bluevpn_manager_legacy_bridge_disabled', '1', false);
            if ((string)get_option('bluevpn_manager_production_finalized_at','') === '') {
                update_option('bluevpn_manager_production_finalized_at', BlueVPN_Utils::iso_now(), false);
            }

            if ($needsRetirementPass && class_exists('BlueVPN_Migration')) {
                BlueVPN_Migration::sync_cron_schedule(false);
                BlueVPN_Migration::sync_auto_schedule(false);
                $cfg = BlueVPN_Migration::settings();
                $cfg['source_url'] = '';
                $cfg['token_enc'] = '';
                $cfg['auto_migrate'] = false;
                $cfg['auto_sync'] = false;
                update_option(BlueVPN_Migration::SETTINGS_OPTION, $cfg, false);
                delete_transient(BlueVPN_Migration::AUTO_LOCK);

                $state = BlueVPN_Migration::state();
                $state['phase'] = 'retired_native';
                $state['current_table'] = '';
                $state['last_error'] = '';
                $state['auto_last_message'] = 'مهاجرت Legacy بازنشسته شد؛ WordPress/MySQL تنها Control Plane فعال BlueVPN است.';
                $state['auto_completed_at'] = $state['auto_completed_at'] ?: BlueVPN_Utils::iso_now();
                update_option(BlueVPN_Migration::STATE_OPTION, $state, false);
                update_option(self::NATIVE_CUTOVER_REVISION_OPTION, self::NATIVE_CUTOVER_REVISION, false);
            }

            $reconcile = get_option(self::NATIVE_RECONCILE_OPTION, []);
            $completed = is_array($reconcile) && !empty($reconcile['completed']);
            $attempts = is_array($reconcile) ? (int)($reconcile['attempts'] ?? 0) : 0;
            if (!$completed && $attempts < 3 && !wp_next_scheduled(self::NATIVE_RECONCILE_HOOK)) {
                wp_schedule_single_event(time() + 15, self::NATIVE_RECONCILE_HOOK);
                BlueVPN_Utils::kick_wp_cron();
            }
            if (class_exists('BlueVPN_Error_Monitor')) {
                BlueVPN_Error_Monitor::resolve_matching('migration', 'native_cutover', 'NATIVE_CUTOVER_FINALIZE_FAILED');
            }
        } catch (Throwable $e) {
            if (class_exists('BlueVPN_Error_Monitor')) {
                BlueVPN_Error_Monitor::report('migration','native_cutover','error','NATIVE_CUTOVER_FINALIZE_FAILED',$e->getMessage(),[]);
            }
        }
    }

    public static function reconcile_legacy_paid_orders_once(): void {
        $state=get_option(self::NATIVE_RECONCILE_OPTION,[]);if(!is_array($state))$state=[];
        if(!empty($state['completed']))return;
        $attemptNo=max(0,(int)($state['attempts']??0))+1;
        if($attemptNo>3)return;
        global $wpdb;
        $orders = BlueVPN_DB::table('orders');
        $attempts = BlueVPN_DB::table('provisioning_attempts');
        $rows = $wpdb->get_results("SELECT * FROM {$orders} WHERE status IN ('paid_needs_sync','partial_needs_sync') AND customer_id IS NOT NULL AND plan_id IS NOT NULL ORDER BY created_at ASC LIMIT 20", ARRAY_A);
        $summary = ['checked'=>0,'activated'=>0,'partial'=>0,'failed'=>0,'attempt'=>$attemptNo,'orders'=>[]];
        foreach ((array)$rows as $order) {
            $summary['checked']++;
            $orderId=(string)$order['id'];$customerId=(int)$order['customer_id'];$planId=(int)$order['plan_id'];
            $provisionAttempt=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*)+1 FROM {$attempts} WHERE order_id=%s",$orderId));
            $wpdb->insert($attempts,['order_id'=>$orderId,'customer_id'=>$customerId,'plan_id'=>$planId,'trigger_source'=>'native_cutover_reconcile','attempt_no'=>$provisionAttempt,'status'=>'started','started_at'=>BlueVPN_Utils::now_mysql(),'created_at'=>BlueVPN_Utils::now_mysql()]);
            $attemptId=(int)$wpdb->insert_id;
            try {
                $r=BlueVPN_Providers::provision_customer($customerId,$planId);
                $status=!empty($r['ok'])?'activated':(!empty($r['partial'])?'partial_needs_sync':'paid_needs_sync');
                $err=!empty($r['ok'])?'':mb_substr((string)($r['message']??'Provision ناقص'),0,2000);
                $wpdb->update($orders,['status'=>$status,'activated_at'=>$status==='activated'?BlueVPN_Utils::now_mysql():$order['activated_at'],'activation_error'=>$err],['id'=>$orderId]);
                $wpdb->update($attempts,['status'=>$status==='activated'?'success':($status==='partial_needs_sync'?'partial':'failed'),'result_json'=>BlueVPN_Utils::json_encode($r),'error_message'=>$err,'finished_at'=>BlueVPN_Utils::now_mysql()],['id'=>$attemptId]);
                $bucket=$status==='activated'?'activated':($status==='partial_needs_sync'?'partial':'failed');$summary[$bucket]++;
                $summary['orders'][]=['order_code'=>(string)($order['order_code']?:$orderId),'status'=>$status,'message'=>(string)($r['message']??'')];
            } catch (Throwable $e) {
                $summary['failed']++;
                $wpdb->update($attempts,['status'=>'failed','error_message'=>mb_substr($e->getMessage(),0,2000),'finished_at'=>BlueVPN_Utils::now_mysql()],['id'=>$attemptId]);
                $summary['orders'][]=['order_code'=>(string)($order['order_code']?:$orderId),'status'=>'failed','message'=>$e->getMessage()];
            }
        }
        $remaining=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$orders} WHERE status IN ('paid_needs_sync','partial_needs_sync') AND customer_id IS NOT NULL AND plan_id IS NOT NULL");
        $completed=$remaining===0 || $summary['checked']===0;
        update_option(self::NATIVE_RECONCILE_OPTION,['completed'=>$completed,'attempts'=>$attemptNo,'remaining'=>$remaining,'last_at'=>BlueVPN_Utils::iso_now(),'summary'=>$summary],false);
        if ($summary['checked'] > 0 && class_exists('BlueVPN_Error_Monitor')) {
            $severity=$remaining>0?'warning':'notice';
            BlueVPN_Error_Monitor::report('migration','order_reconcile',$severity,'NATIVE_CUTOVER_ORDER_RECONCILE',$remaining>0?'بازبینی سفارش‌های قدیمی انجام شد اما برخی هنوز نیازمند Sync هستند.':'بازبینی سفارش‌های قدیمی پس از Cutover با موفقیت کامل شد.',$summary+['remaining'=>$remaining]);
        }
        if(!$completed && $attemptNo<3 && !wp_next_scheduled(self::NATIVE_RECONCILE_HOOK))wp_schedule_single_event(time()+30*MINUTE_IN_SECONDS,self::NATIVE_RECONCILE_HOOK);
    }

    public static function deactivate(): void { self::unschedule(); }

    public static function ensure_schedule(): void {
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

    public static function unschedule(): void {
        $ts = wp_next_scheduled(self::BACKUP_HOOK);
        while ($ts) {
            wp_unschedule_event($ts, self::BACKUP_HOOK);
            $ts = wp_next_scheduled(self::BACKUP_HOOK);
        }
    }

    private static function backup_dir(): string {
        $preferred = trailingslashit(dirname(ABSPATH)) . 'bluevpn-private-backups';
        if ((is_dir($preferred) && is_writable($preferred)) || @wp_mkdir_p($preferred)) {
            return untrailingslashit($preferred);
        }
        $u = wp_upload_dir();
        $fallback = trailingslashit((string)$u['basedir']) . 'bluevpn-private-backups';
        if (!is_dir($fallback)) wp_mkdir_p($fallback);
        // Apache protection + directory-index protection. On nginx the randomized
        // filenames still avoid predictable public paths; admins should prefer the
        // parent-of-ABSPATH location when permissions allow it.
        if (is_dir($fallback)) {
            @file_put_contents($fallback.'/.htaccess', "Require all denied\nDeny from all\n");
            @file_put_contents($fallback.'/index.php', "<?php http_response_code(404); exit;\n");
        }
        return untrailingslashit($fallback);
    }

    /**
     * Persistent WordPress options that are part of the BlueVPN control plane.
     * Runtime locks/transients and backup-status options are intentionally excluded.
     */
    private static function option_names(): array {
        return [
            'bluevpn_manager_cutover_ready',
            'bluevpn_manager_app_cutover_enabled',
            'bluevpn_manager_legacy_bridge_disabled',
            'bluevpn_manager_production_finalized_at',
            'bluevpn_migration_settings',
            'bluevpn_migration_state',
            'bluevpn_migration_runtime_secret_state',
            'bluevpn_github_updater_settings',
            'bluevpn_github_installed_release_v2',
            'bluevpn_app_release_manager_settings_v1',
            'bluevpn_app_release_manager_status_v1',
            'bluevpn_app_release_fingerprint_v1',
            'bluevpn_app_release_last_sync_v1',
            'bluevpn_windows_release_manager_settings_v1',
            'bluevpn_windows_release_manager_status_v1',
            'bluevpn_windows_release_last_sync_v1',
            'bluevpn_bot_runtime_migrated_at',
            'bluevpn_sms_catalog_version',
            'bluevpn_support_schema',
        ];
    }

    private static function canonical_payload(): array {
        global $wpdb;
        $tables = [];
        foreach (BlueVPN_DB::table_names() as $name) {
            $table = BlueVPN_DB::table($name);
            $tables[$name] = $wpdb->get_results("SELECT * FROM {$table}", ARRAY_A) ?: [];
        }
        $options = [];
        foreach (self::option_names() as $name) {
            $value = get_option($name, null);
            if ($value !== null) $options[$name] = $value;
        }
        return [
            'meta' => [
                'format' => 'bluevpn-wordpress-backup-v3',
                'version' => BLUEVPN_MANAGER_VERSION,
                'schema_version' => BLUEVPN_MANAGER_SCHEMA_VERSION,
                'site' => home_url('/'),
                'created_at' => BlueVPN_Utils::iso_now(),
                'table_count' => count($tables),
                'option_count' => count($options),
            ],
            'tables' => $tables,
            'options' => $options,
        ];
    }

    private static function encode_backup(array $payload): string {
        $core = wp_json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if (!is_string($core)) throw new RuntimeException('ساخت JSON بکاپ ناموفق بود.');
        $wrapper = [
            'checksum' => hash('sha256', $core),
            'payload' => $payload,
        ];
        $json = wp_json_encode($wrapper, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) throw new RuntimeException('ساخت فایل بکاپ ناموفق بود.');
        return $json;
    }

    private static function update_backup_state(array $patch): array {
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

    private static function prune_backups(): void {
        $dir = self::backup_dir();
        $files = glob(trailingslashit($dir).'bluevpn-*.json') ?: [];
        usort($files, static fn($a,$b)=>(@filemtime($b)?:0)<=> (@filemtime($a)?:0));
        foreach (array_slice($files, self::BACKUP_RETENTION) as $file) @unlink($file);
    }

    public static function backup_status(): array {
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
        $last = get_option(self::RESTORE_OPTION, []);
        return is_array($last) ? $last : [];
    }

    public static function validate_backup_json(string $json): array {
        if (strlen($json) < 20) throw new RuntimeException('فایل Backup خالی یا ناقص است.');
        $wrapper = json_decode($json, true);
        if (!is_array($wrapper) || !isset($wrapper['payload']) || !is_array($wrapper['payload'])) throw new RuntimeException('ساختار Backup معتبر نیست.');
        $payload = $wrapper['payload'];
        $format = (string)($payload['meta']['format'] ?? '');
        if (!in_array($format, ['bluevpn-wordpress-backup-v2','bluevpn-wordpress-backup-v3'], true)) throw new RuntimeException('فرمت Backup پشتیبانی نمی‌شود.');
        if (!isset($payload['tables']) || !is_array($payload['tables'])) throw new RuntimeException('بخش جداول در Backup وجود ندارد.');
        if (isset($payload['options']) && !is_array($payload['options'])) throw new RuntimeException('بخش تنظیمات WordPress در Backup معتبر نیست.');
        $core = wp_json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if (!is_string($core) || empty($wrapper['checksum']) || !hash_equals((string)$wrapper['checksum'], hash('sha256',$core))) throw new RuntimeException('Checksum فایل Backup معتبر نیست.');
        $allowed = array_flip(BlueVPN_DB::table_names());
        foreach ($payload['tables'] as $name=>$rows) {
            if (!isset($allowed[$name])) throw new RuntimeException('جدول ناشناخته در Backup: '.$name);
            if (!is_array($rows)) throw new RuntimeException('داده جدول '.$name.' معتبر نیست.');
            foreach ($rows as $row) if (!is_array($row)) throw new RuntimeException('ردیف نامعتبر در جدول '.$name.'.');
        }
        if (!empty($payload['options'])) {
            $allowedOptions = array_flip(self::option_names());
            foreach ($payload['options'] as $name=>$value) {
                if (!isset($allowedOptions[$name])) throw new RuntimeException('Option ناشناخته در Backup: '.$name);
                // WordPress options can be scalars/arrays; resources/objects are never valid JSON backup values.
                if (is_object($value) || is_resource($value)) throw new RuntimeException('Option نامعتبر در Backup: '.$name);
            }
        }
        return $payload;
    }

    public static function restore_from_json(string $json): array {
        global $wpdb;
        $payload = self::validate_backup_json($json);
        // Always take a rollback snapshot before a destructive restore.
        $pre = self::create_backup('pre-restore');
        $wpdb->query('START TRANSACTION');
        try {
            foreach ($payload['tables'] as $name=>$rows) {
                $table = BlueVPN_DB::table((string)$name);
                $columns = $wpdb->get_col("DESCRIBE {$table}", 0) ?: [];
                if (!$columns) throw new RuntimeException('جدول مقصد پیدا نشد: '.$name);
                $allowedCols = array_flip(array_map('strval',$columns));
                if ($wpdb->query("DELETE FROM {$table}") === false) throw new RuntimeException('پاک‌سازی جدول '.$name.' ناموفق بود: '.$wpdb->last_error);
                foreach ($rows as $row) {
                    $clean = [];
                    foreach ($row as $k=>$v) if (isset($allowedCols[$k])) $clean[$k] = $v;
                    if (!$clean) continue;
                    if ($wpdb->insert($table, $clean) === false) throw new RuntimeException('Restore جدول '.$name.' ناموفق بود: '.$wpdb->last_error);
                }
            }
            $wpdb->query('COMMIT');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            update_option(self::RESTORE_OPTION, ['ok'=>false,'error'=>$e->getMessage(),'at'=>BlueVPN_Utils::iso_now(),'pre_restore_backup'=>$pre['filename']??''], false);
            throw $e;
        }
        // Bring an older snapshot forward to the current schema without deleting
        // restored data that belongs to known tables.
        BlueVPN_DB::install_schema();
        BlueVPN_DB::seed_defaults();
        BlueVPN_DB::seed_release_channels();
        BlueVPN_DB::enforce_six_digit_otp();
        BlueVPN_SMS_Notifications::seed_templates();
        BlueVPN_SMS_Notifications::schedule();
        // Restore the BlueVPN control-plane options only after the current schema
        // is installed. Never roll the schema marker itself back to an old value.
        if (!empty($payload['options']) && is_array($payload['options'])) {
            $allowedOptions = array_flip(self::option_names());
            foreach ($payload['options'] as $name=>$value) {
                if (isset($allowedOptions[$name])) update_option((string)$name, $value, false);
            }
        }
        update_option('bluevpn_manager_schema_version', BLUEVPN_MANAGER_SCHEMA_VERSION, false);
        // 4.16.6+: restoring an old backup must never resurrect the retired
        // migration bridge. Re-assert the native WordPress/MySQL control plane.
        self::ensure_native_control_plane();
        self::ensure_schedule();
        $result = ['ok'=>true,'at'=>BlueVPN_Utils::iso_now(),'source_version'=>(string)($payload['meta']['version']??''),'source_schema'=>(string)($payload['meta']['schema_version']??''),'pre_restore_backup'=>$pre['filename']??'','restored_options'=>count((array)($payload['options']??[]))];
        update_option(self::RESTORE_OPTION, $result, false);
        return $result;
    }

    public static function health_summary(): array {
        global $wpdb;
        $checks = [];
        $db = BlueVPN_DB::status();
        $checks['database'] = ['ok'=>!empty($db['ready']),'message'=>!empty($db['ready'])?'MySQL و Schema آماده‌اند':'Schema دیتابیس ناقص است'];

        $sms = BlueVPN_DB::table('sms_deliveries');
        $smsFailed = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$sms} WHERE status='failed'");
        $smsStuck = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$sms} WHERE status='sending' AND sending_started_at IS NOT NULL AND sending_started_at<%s", gmdate('Y-m-d H:i:s',time()-10*MINUTE_IN_SECONDS)));
        $checks['sms_queue'] = ['ok'=>$smsStuck===0 && $smsFailed<25,'message'=>'failed='.$smsFailed.'، stuck='.$smsStuck,'failed'=>$smsFailed,'stuck'=>$smsStuck];

        $orders = BlueVPN_DB::table('orders');
        $old = gmdate('Y-m-d H:i:s', time()-2*HOUR_IN_SECONDS);
        $stuckRows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,order_code,status,created_at,activation_error FROM {$orders} WHERE status IN ('creating_invoice','paid_needs_sync','partial_needs_sync') AND created_at<%s ORDER BY created_at ASC LIMIT 10",
            $old
        ), ARRAY_A);
        $stuckOrders = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$orders} WHERE status IN ('creating_invoice','paid_needs_sync','partial_needs_sync') AND created_at<%s",$old));
        $paymentItems = [];
        foreach ((array)$stuckRows as $row) {
            $status = (string)($row['status'] ?? '');
            $reason = match ($status) {
                'creating_invoice' => 'ایجاد فاکتور بیش از ۲ ساعت تکمیل نشده است',
                'paid_needs_sync' => 'پرداخت ثبت شده اما همگام‌سازی/فعال‌سازی کامل نشده است',
                'partial_needs_sync' => 'پرداخت یا فعال‌سازی ناقص است و به همگام‌سازی مجدد نیاز دارد',
                default => 'سفارش بیش از حد مجاز در وضعیت میانی مانده است',
            };
            $created = (string)($row['created_at'] ?? '');
            $createdTs = $created !== '' ? strtotime($created . ' UTC') : false;
            $paymentItems[] = [
                'order_code' => (string)($row['order_code'] ?: $row['id']),
                'status' => $status,
                'age_minutes' => $createdTs ? max(0, (int)floor((time() - $createdTs) / 60)) : 0,
                'created_at_fa' => $created !== '' ? BlueVPN_Utils::tehran_datetime_fa($created) : '',
                'reason' => $reason,
                'activation_error' => trim((string)($row['activation_error'] ?? '')),
            ];
        }
        $checks['payments'] = [
            'ok' => $stuckOrders === 0,
            'severity' => 'warning',
            'code' => 'PAYMENT_STUCK_ORDERS',
            'message' => $stuckOrders === 0 ? 'سفارش گیرکرده قدیمی وجود ندارد' : $stuckOrders . ' سفارش بیش از ۲ ساعت در وضعیت میانی مانده است',
            'stuck' => $stuckOrders,
            'items' => $paymentItems,
            'action' => 'BlueVPN Manager ← پرداخت / بلوپال: وضعیت این سفارش‌ها را بررسی و در صورت پرداخت موفق، همگام‌سازی/فعال‌سازی را دوباره اجرا کن.',
        ];

        $backup = self::backup_status();
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

        $cut = get_option('bluevpn_manager_cutover_ready','0')==='1';
        $app = get_option('bluevpn_manager_app_cutover_enabled','0')==='1';
        $native = self::native_control_plane();
        $checks['cutover'] = [
            'ok' => $native && $cut && $app,
            'severity' => $native ? 'notice' : 'error',
            'code' => $native ? 'WORDPRESS_NATIVE_CONTROL_PLANE' : 'CONTROL_PLANE_NOT_FINALIZED',
            'message' => $native ? 'مهاجرت پایان یافته است؛ WordPress/MySQL تنها Control Plane فعال است.' : 'Control Plane نهایی WordPress/MySQL هنوز تثبیت نشده است.',
            'migration_cutover_ready' => $cut,
            'app_cutover_enabled' => $app,
            'legacy_bridge_disabled' => get_option('bluevpn_manager_legacy_bridge_disabled','0')==='1',
            'control_plane_mode' => (string)get_option(self::CONTROL_PLANE_OPTION,''),
            'action' => $native ? '' : 'BlueVPN Manager را به 4.16.6 یا بالاتر ارتقا بده تا Cutover نهایی WordPress/MySQL اعمال شود.',
        ];

        $providers = 0; $providerBad = 0;
        foreach (['pasarguard_panels','marzban_panels','guardcore_panels'] as $logical) {
            $t = BlueVPN_DB::table($logical);
            $providers += (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE active=1");
            $providerBad += (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE active=1 AND last_test_at IS NOT NULL AND last_test_ok=0");
        }
        $checks['providers'] = ['ok'=>$providerBad===0,'message'=>$providers.' Provider فعال؛ '.$providerBad.' تست ناموفق','active'=>$providers,'failed'=>$providerBad];

        $okCount = count(array_filter($checks, static fn($x)=>!empty($x['ok'])));
        $score = (int)round($okCount * 100 / max(1,count($checks)));
        return ['ok'=>$score===100,'score'=>$score,'checks'=>$checks,'generated_at'=>BlueVPN_Utils::iso_now(),'generated_at_fa'=>BlueVPN_Utils::tehran_datetime_fa(),'timezone'=>'Asia/Tehran'];
    }

    public static function finalize_cutover(): array {
        $backup = self::create_backup('pre-final-cutover');
        self::ensure_native_control_plane();
        return ['ok'=>self::native_control_plane(),'backup'=>$backup['filename']??'','finalized_at'=>(string)get_option('bluevpn_manager_production_finalized_at',BlueVPN_Utils::iso_now())];
    }
}
