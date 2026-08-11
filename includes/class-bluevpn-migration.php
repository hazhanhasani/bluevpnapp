<?php
if (!defined('ABSPATH')) exit;

final class BlueVPN_Migration {
    const SETTINGS_OPTION = 'bluevpn_migration_settings';
    const STATE_OPTION = 'bluevpn_migration_state';
    const CRON_HOOK = 'bluevpn_migration_sync';
    const AUTO_HOOK = 'bluevpn_migration_auto_runner';
    const DEFAULT_BATCH_SIZE = 1000;
    const FAST_EXPORT_LIMIT = 5000;
    const BULK_WRITE_CHUNK = 500;
    const AUTO_LOCK = 'bluevpn_migration_auto_lock';
    const STALL_SECONDS = 180;

    public static function init(): void {
        add_action(self::CRON_HOOK, [self::class, 'cron_sync']);
        add_action(self::AUTO_HOOK, [self::class, 'auto_step']);
        self::maybe_resume_auto();
    }

    public static function table_order(): array {
        return [
            'app_settings', 'ad_assets', 'server_locations', 'pasarguard_panels',
            'marzban_panels', 'guardcore_panels', 'plans', 'customers',
            'otp_challenges', 'customer_sessions', 'customer_devices', 'sms_settings',
            'sms_templates', 'payment_settings', 'orders', 'sms_deliveries',
            'webhook_deliveries', 'ai_connection_events', 'ai_live_connections',
            'ai_route_aggregates', 'ai_feedback',
        ];
    }

    public static function settings(): array {
        $raw = get_option(self::SETTINGS_OPTION, []);
        if (!is_array($raw)) $raw = [];
        return array_merge([
            'source_url' => '',
            'token_enc' => '',
            'verify_tls' => true,
            'batch_size' => self::DEFAULT_BATCH_SIZE,
            'auto_migrate' => true,
            'auto_sync' => false,
        ], $raw);
    }

    public static function save_settings(array $input): void {
        $current = self::settings();
        $source = untrailingslashit(esc_url_raw((string)($input['source_url'] ?? '')));
        $plainToken = trim((string)($input['token'] ?? ''));
        $settings = [
            'source_url' => $source,
            'token_enc' => $plainToken !== '' ? BlueVPN_Utils::encrypt_secret($plainToken) : (string)$current['token_enc'],
            'verify_tls' => array_key_exists('verify_tls', $input) ? !empty($input['verify_tls']) : !empty($current['verify_tls']),
            'batch_size' => max(100, min(self::FAST_EXPORT_LIMIT, (int)($input['batch_size'] ?? $current['batch_size'] ?? self::DEFAULT_BATCH_SIZE))),
            'auto_migrate' => array_key_exists('auto_migrate', $input) ? !empty($input['auto_migrate']) : !empty($current['auto_migrate']),
            'auto_sync' => array_key_exists('auto_sync', $input) ? !empty($input['auto_sync']) : !empty($current['auto_sync']),
        ];
        update_option(self::SETTINGS_OPTION, $settings, false);
        self::sync_cron_schedule($settings['auto_sync']);
        self::sync_auto_schedule(!empty($settings['auto_migrate']));
    }

    public static function clear_token(): void {
        $settings = self::settings();
        $settings['token_enc'] = '';
        update_option(self::SETTINGS_OPTION, $settings, false);
    }

    public static function has_token(): bool {
        return self::token() !== '';
    }

    private static function token(): string {
        $settings = self::settings();
        return BlueVPN_Utils::decrypt_secret((string)$settings['token_enc']);
    }

    public static function state(): array {
        $raw = get_option(self::STATE_OPTION, []);
        if (!is_array($raw)) $raw = [];
        $tables = [];
        foreach (self::table_order() as $name) {
            $row = isset($raw['tables'][$name]) && is_array($raw['tables'][$name]) ? $raw['tables'][$name] : [];
            $tables[$name] = array_merge([
                'cursor' => '', 'imported' => 0, 'cycle_imported' => 0, 'source_count' => null,
                'done' => false, 'last_error' => '', 'updated_at' => '',
                'retry_count' => 0, 'last_batch_rows' => 0, 'last_batch_ms' => 0,
            ], $row);
        }
        return array_merge([
            'phase' => 'not_started',
            'source_version' => '',
            'source_schema_version' => '',
            'source_database_mode' => '',
            'source_counts' => [],
            'source_primary_keys' => [],
            'last_manifest_at' => '',
            'last_run_at' => '',
            'last_error' => '',
            'initial_completed_at' => '',
            'last_full_sync_at' => '',
            'auto_started_at' => '',
            'auto_completed_at' => '',
            'auto_last_message' => '',
            'auto_retry_count' => 0,
            'auto_resync_cycles' => 0,
            'final_resync_started_at' => '',
            'verification_failures' => 0,
            'cycle_kind' => '',
            'current_table' => '',
            'last_progress_at' => '',
            'last_verified_at' => '',
            'final_verify_passes' => 0,
            'paused_from_phase' => '',
            'tables' => $tables,
        ], $raw, ['tables' => $tables]);
    }

    private static function save_state(array $state): void {
        update_option(self::STATE_OPTION, $state, false);
    }

    public static function reset(bool $keepManifest = true): void {
        $old = self::state();
        $state = [
            'phase' => 'not_started',
            'source_version' => $keepManifest ? $old['source_version'] : '',
            'source_schema_version' => $keepManifest ? $old['source_schema_version'] : '',
            'source_database_mode' => $keepManifest ? $old['source_database_mode'] : '',
            'source_counts' => $keepManifest ? $old['source_counts'] : [],
            'source_primary_keys' => $keepManifest ? ($old['source_primary_keys'] ?? []) : [],
            'last_manifest_at' => $keepManifest ? $old['last_manifest_at'] : '',
            'last_run_at' => '', 'last_error' => '', 'initial_completed_at' => '',
            'last_full_sync_at' => '', 'auto_started_at' => '', 'auto_completed_at' => '',
            'auto_last_message' => '', 'auto_retry_count' => 0, 'auto_resync_cycles' => 0,
            'final_resync_started_at' => '', 'verification_failures' => 0,
            'cycle_kind' => '', 'current_table' => '', 'last_progress_at' => '',
            'last_verified_at' => '', 'final_verify_passes' => 0, 'paused_from_phase' => '',
            'tables' => [],
        ];
        self::save_state($state);
    }

    public static function test_connection() {
        return self::request('/internal/migration/v1/health', ['timeout' => 15]);
    }

    public static function refresh_manifest() {
        $result = self::request('/internal/migration/v1/manifest', ['timeout' => 25]);
        if (is_wp_error($result)) return $result;
        $state = self::state();
        $state['source_version'] = (string)($result['backend_version'] ?? '');
        $state['source_schema_version'] = (string)($result['schema_version'] ?? '');
        $state['source_database_mode'] = (string)($result['database_mode'] ?? '');
        $state['source_counts'] = is_array($result['table_counts'] ?? null) ? $result['table_counts'] : [];
        $state['source_primary_keys'] = is_array($result['primary_keys'] ?? null) ? $result['primary_keys'] : [];
        $state['last_manifest_at'] = BlueVPN_Utils::iso_now();
        foreach ($state['tables'] as $name => &$row) {
            if (array_key_exists($name, $state['source_counts'])) $row['source_count'] = (int)$state['source_counts'][$name];
        }
        unset($row);
        self::save_state($state);
        return $result;
    }

    public static function run(int $maxBatches = 8): array {
        $started = microtime(true);
        $maxBatches = max(1, min(60, $maxBatches));
        $state = self::state();
        if (empty($state['source_counts'])) {
            $manifest = self::refresh_manifest();
            if (is_wp_error($manifest)) {
                return ['success' => false, 'error' => $manifest->get_error_message(), 'batches' => 0, 'rows_imported' => 0, 'errors' => [$manifest->get_error_message()]];
            }
            $state = self::state();
        }

        if (!in_array((string)$state['phase'], ['resyncing', 'copying'], true)) {
            $state['phase'] = empty($state['initial_completed_at']) ? 'copying' : 'resyncing';
        }
        $state['last_error'] = '';
        self::save_state($state);

        $batches = 0;
        $rowsImported = 0;
        $errors = [];
        while ($batches < $maxBatches && (microtime(true) - $started) < self::execution_budget_seconds()) {
            $state = self::state();
            $table = self::next_table($state);
            if ($table === null) {
                $firstPass = empty($state['initial_completed_at']);
                if ($firstPass) {
                    $state['initial_completed_at'] = BlueVPN_Utils::iso_now();
                    $state['phase'] = 'initial_verify';
                    $state['auto_last_message'] = 'انتقال اولیه تمام شد؛ در حال بررسی Manifest و آماده‌سازی دور نهایی است.';
                } else {
                    $state['phase'] = 'final_verify';
                    $state['last_full_sync_at'] = BlueVPN_Utils::iso_now();
                    $state['auto_last_message'] = 'دور همگام‌سازی تمام شد؛ در حال بررسی نهایی اختلاف Railway و MySQL است.';
                }
                $state['current_table'] = '';
                $state['last_run_at'] = BlueVPN_Utils::iso_now();
                self::save_state($state);
                update_option('bluevpn_manager_cutover_ready', '0', false);
                return ['success' => true, 'complete' => true, 'batches' => $batches, 'rows_imported' => $rowsImported, 'errors' => []];
            }

            $state['current_table'] = $table;
            self::save_state($state);
            $beforeDone = !empty($state['tables'][$table]['done']);
            $result = self::import_batch($table, (string)$state['tables'][$table]['cursor']);
            $batches++;
            if (is_wp_error($result)) {
                $message = $result->get_error_message();
                $state = self::state();
                $state['phase'] = empty($state['initial_completed_at']) ? 'copying' : 'resyncing';
                $state['last_error'] = $message;
                $state['last_run_at'] = BlueVPN_Utils::iso_now();
                $state['tables'][$table]['last_error'] = $message;
                $state['tables'][$table]['retry_count'] = min(99, (int)($state['tables'][$table]['retry_count'] ?? 0) + 1);
                $state['tables'][$table]['updated_at'] = BlueVPN_Utils::iso_now();
                self::save_state($state);
                $errors[] = $message;
                break;
            }

            $rowsImported += (int)$result['imported'];
            $state = self::state();
            if ((int)$result['imported'] > 0 || (!$beforeDone && !empty($result['done']))) {
                $state['last_progress_at'] = BlueVPN_Utils::iso_now();
                $state['tables'][$table]['retry_count'] = 0;
                self::save_state($state);
            }
        }

        $state = self::state();
        $state['last_run_at'] = BlueVPN_Utils::iso_now();
        if (!$errors && !in_array((string)$state['phase'], ['resyncing', 'copying'], true)) {
            $state['phase'] = empty($state['initial_completed_at']) ? 'copying' : 'resyncing';
        }
        self::save_state($state);
        return ['success' => !$errors, 'complete' => false, 'batches' => $batches, 'rows_imported' => $rowsImported, 'errors' => $errors];
    }

    public static function start_resync(bool $incremental = true, ?array $onlyTables = null, string $kind = 'final'): void {
        $state = self::state();
        $filter = null;
        if (is_array($onlyTables)) {
            $filter = array_fill_keys(array_values(array_intersect(self::table_order(), $onlyTables)), true);
        }
        foreach ($state['tables'] as $name => &$row) {
            $row['cycle_imported'] = 0;
            $row['last_error'] = '';
            $row['last_batch_rows'] = 0;
            $row['last_batch_ms'] = 0;

            if ($filter !== null && !isset($filter[$name])) {
                $row['done'] = true;
                continue;
            }

            if ($incremental && $name === 'ai_connection_events') {
                $row['cursor'] = self::local_max_primary_key($name, $state);
            } else {
                $row['cursor'] = '';
            }
            $row['done'] = false;
        }
        unset($row);
        $state['phase'] = 'resyncing';
        $state['cycle_kind'] = in_array($kind, ['final', 'delta'], true) ? $kind : 'final';
        $state['current_table'] = '';
        $state['last_error'] = '';
        $state['auto_resync_cycles'] = (int)($state['auto_resync_cycles'] ?? 0) + 1;
        if ($state['cycle_kind'] === 'final' && empty($state['final_resync_started_at'])) {
            $state['final_resync_started_at'] = BlueVPN_Utils::iso_now();
        }
        $state['auto_last_message'] = $state['cycle_kind'] === 'delta'
            ? 'فقط جدول‌های دارای اختلاف در حال ترمیم هستند؛ داده‌های کامل دوباره منتقل نمی‌شوند.'
            : 'دور نهایی همگام‌سازی در حال اجراست؛ Progress انتقال اولیه حفظ می‌شود.';
        self::save_state($state);
        update_option('bluevpn_manager_cutover_ready', '0', false);
    }

    private static function local_max_primary_key(string $logicalTable, array $state): string {
        global $wpdb;
        $pk = (string)(($state['source_primary_keys'][$logicalTable] ?? '') ?: 'id');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $pk)) return '';
        $table = BlueVPN_DB::table($logicalTable);
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
        if (!is_array($columns) || !in_array($pk, $columns, true)) return '';
        $max = $wpdb->get_var("SELECT MAX(`{$pk}`) FROM {$table}");
        return $max === null ? '' : (string)$max;
    }

    public static function compare_counts(): array {
        $source = self::state()['source_counts'];
        $local = BlueVPN_DB::counts();
        $result = [];
        foreach (self::table_order() as $name) {
            $s = array_key_exists($name, $source) ? (int)$source[$name] : null;
            $l = array_key_exists($name, $local) ? (int)$local[$name] : null;
            $result[$name] = [
                'source' => $s,
                'local' => $l,
                'delta' => ($s !== null && $l !== null) ? $l - $s : null,
                'match' => $s !== null && $l !== null && $s === $l,
                // WordPress may legitimately have local-only rows (defaults, sessions,
                // health/runtime records). Exact equality therefore must not force an
                // endless full resync. A completed import covers Railway when MySQL has
                // at least the source row count.
                'covered' => $s !== null && $l !== null && $l >= $s,
            ];
        }
        return $result;
    }

    private static function coverage_mismatches(array $comparison): array {
        return array_filter($comparison, static function ($row): bool {
            return empty($row['covered']);
        });
    }

    /**
     * Derive Cutover readiness from real Railway/MySQL coverage instead of a
     * manually toggled/stale option. This also repairs state left by older
     * plugin versions as soon as the migration page/runner is opened.
     */
    public static function reconcile_readiness(bool $mutate = true): array {
        $state = self::state();
        $comparison = self::compare_counts();
        $mismatches = self::coverage_mismatches($comparison);
        $tableErrors = [];
        foreach ($state['tables'] as $name => $row) {
            if (!empty($row['last_error'])) $tableErrors[$name] = (string)$row['last_error'];
        }

        $finalPassCompleted = (
            !empty($state['initial_completed_at'])
            && (int)($state['auto_resync_cycles'] ?? 0) >= 1
            && (int)($state['final_verify_passes'] ?? 0) >= 1
            && !empty($state['last_verified_at'])
        );
        $ready = $finalPassCompleted && !$mismatches && !$tableErrors;

        if ($mutate) {
            $storedReady = get_option('bluevpn_manager_cutover_ready', '0') === '1';
            if ($ready) {
                if (!$storedReady) update_option('bluevpn_manager_cutover_ready', '1', false);
                if ($state['phase'] !== 'ready_for_cutover') {
                    $state['phase'] = 'ready_for_cutover';
                    $state['current_table'] = '';
                    $state['auto_completed_at'] = $state['auto_completed_at'] ?: BlueVPN_Utils::iso_now();
                    $state['auto_last_message'] = 'انتقال، Resync و بررسی نهایی کامل است؛ Railway فعلاً روشن بماند تا Cutover را خودت انجام بدهی.';
                    $state['last_error'] = '';
                    self::save_state($state);
                }
            } else {
                if ($storedReady) update_option('bluevpn_manager_cutover_ready', '0', false);
                if ($state['phase'] === 'ready_for_cutover') {
                    $state['phase'] = 'final_verify';
                    $state['auto_completed_at'] = '';
                    $state['auto_last_message'] = $mismatches
                        ? 'داده جدیدی در Railway دیده شد؛ فقط اختلاف‌ها دوباره همگام می‌شوند.'
                        : ($tableErrors ? 'یک یا چند جدول خطا دارد و قبل از Cutover باید ترمیم شود.' : 'بررسی نهایی دوباره لازم است.');
                    self::save_state($state);
                }
            }
        }

        $sourceTotal = 0;
        $coveredTotal = 0;
        foreach ($comparison as $row) {
            if ($row['source'] === null) continue;
            $source = max(0, (int)$row['source']);
            $local = max(0, (int)($row['local'] ?? 0));
            $sourceTotal += $source;
            $coveredTotal += min($source, $local);
        }
        $percent = $sourceTotal > 0
            ? min(100, (int)floor(($coveredTotal * 100) / $sourceTotal))
            : ($ready ? 100 : 0);

        return [
            'ready' => $ready,
            'final_pass_completed' => $finalPassCompleted,
            'mismatches' => $mismatches,
            'table_errors' => $tableErrors,
            'comparison' => $comparison,
            'source_total' => $sourceTotal,
            'covered_total' => $coveredTotal,
            'percent' => $percent,
        ];
    }

    public static function dashboard_status(bool $mutate = true): array {
        $readiness = self::reconcile_readiness($mutate);
        $state = self::state();
        $comparison = $readiness['comparison'];
        $phase = (string)$state['phase'];

        $phaseMap = [
            'not_started' => [1, 'آماده‌سازی', 'در انتظار شروع'],
            'scanning' => [1, 'اسکن مبدا', 'در حال دریافت Manifest و شمار جدول‌ها'],
            'copying' => [2, 'انتقال اولیه', 'در حال کپی داده‌ها به MySQL'],
            'initial_verify' => [3, 'بررسی اولیه', 'انتقال اولیه تمام شده و اختلاف‌ها بررسی می‌شوند'],
            'resyncing' => [4, (($state['cycle_kind'] ?? '') === 'delta' ? 'ترمیم اختلاف‌ها' : 'Resync نهایی'), (($state['cycle_kind'] ?? '') === 'delta' ? 'فقط جدول‌های ناقص دوباره همگام می‌شوند' : 'تغییرات حین انتقال اولیه دوباره همگام می‌شوند')],
            'final_verify' => [5, 'بررسی نهایی', 'شمار Railway و MySQL در حال تأیید نهایی است'],
            'ready_for_cutover' => [6, 'آماده Cutover', 'انتقال کامل است و Backend قدیمی هنوز خودکار خاموش نشده'],
            'paused' => [0, 'متوقف موقت', 'ادامه انتقال با دکمه شروع/ادامه امکان‌پذیر است'],
            'needs_attention' => [0, 'نیاز به بررسی', 'Retry خودکار متوقف شده تا حلقه تکرار ایجاد نشود'],
            'verification_failed' => [0, 'نیاز به بررسی', 'بررسی نهایی پس از چند Retry هنوز اختلاف دارد'],
            'error' => [0, 'خطا', 'آخرین مرحله با خطا متوقف شده؛ ادامه امن امکان‌پذیر است'],
            // Compatibility with states written by older 4.0.x releases.
            'running' => [2, 'انتقال اولیه', 'در حال کپی داده‌ها به MySQL'],
            'initial_complete' => [3, 'بررسی اولیه', 'انتقال اولیه تمام شده و اختلاف‌ها بررسی می‌شوند'],
            'sync_complete' => [5, 'بررسی نهایی', 'شمار Railway و MySQL در حال تأیید نهایی است'],
            'verification_needed' => [5, 'بررسی نهایی', 'داده جدیدی در Railway دیده شده است'],
        ];
        [$step, $label, $description] = $phaseMap[$phase] ?? [0, $phase ?: 'نامشخص', ''];

        $syncedTables = 0;
        $pendingTables = 0;
        $errorTables = 0;
        $missingRows = 0;
        $tables = [];
        foreach (self::table_order() as $name) {
            $cmp = $comparison[$name] ?? ['source'=>null,'local'=>null,'covered'=>false,'delta'=>null];
            $row = $state['tables'][$name] ?? [];
            $source = $cmp['source'];
            $local = $cmp['local'];
            $missing = ($source !== null && $local !== null) ? max(0, (int)$source - (int)$local) : null;
            if (!empty($row['last_error'])) {
                $tableStatus = 'error';
                $errorTables++;
            } elseif ($source === null || $local === null) {
                $tableStatus = 'checking';
                $pendingTables++;
            } elseif (!empty($cmp['covered'])) {
                $tableStatus = 'synced';
                $syncedTables++;
            } else {
                $tableStatus = 'pending';
                $pendingTables++;
                $missingRows += (int)$missing;
            }
            $tables[$name] = [
                'source' => $source,
                'local' => $local,
                'missing' => $missing,
                'status' => $tableStatus,
                'error' => (string)($row['last_error'] ?? ''),
                'updated_at' => (string)($row['updated_at'] ?? ''),
                'cycle_imported' => (int)($row['cycle_imported'] ?? 0),
                'last_batch_rows' => (int)($row['last_batch_rows'] ?? 0),
                'last_batch_ms' => (int)($row['last_batch_ms'] ?? 0),
                'retry_count' => (int)($row['retry_count'] ?? 0),
            ];
        }

        $currentTable = (string)($state['current_table'] ?? '');
        if ($currentTable === '' && in_array($phase, ['copying','resyncing','running'], true)) {
            $currentTable = (string)(self::next_table($state) ?? '');
        }
        $speed = 0.0;
        if ($currentTable !== '' && isset($tables[$currentTable])) {
            $ms = max(0, (int)$tables[$currentTable]['last_batch_ms']);
            $rows = max(0, (int)$tables[$currentTable]['last_batch_rows']);
            if ($ms > 0 && $rows > 0) $speed = round(($rows * 1000) / $ms, 1);
        }
        $etaSeconds = null;
        if ($speed > 0 && $missingRows > 0) $etaSeconds = (int)ceil($missingRows / $speed);

        $lastProgressAt = (string)($state['last_progress_at'] ?? '');
        $stalled = false;
        if ($lastProgressAt !== '' && in_array($phase, ['copying','resyncing','running'], true)) {
            $ts = strtotime($lastProgressAt);
            if ($ts !== false) $stalled = (time() - $ts) > self::STALL_SECONDS;
        }

        return [
            'readiness' => $readiness,
            'phase' => $phase,
            'step' => $step,
            'step_label' => $label,
            'step_description' => $description,
            'data_percent' => (int)$readiness['percent'],
            'source_total' => (int)$readiness['source_total'],
            'covered_total' => (int)$readiness['covered_total'],
            'missing_rows' => $missingRows,
            'tables_total' => count(self::table_order()),
            'tables_synced' => $syncedTables,
            'tables_pending' => $pendingTables,
            'tables_error' => $errorTables,
            'current_table' => $currentTable,
            'rows_per_second' => $speed,
            'eta_seconds' => $etaSeconds,
            'stalled' => $stalled,
            'last_progress_at' => $lastProgressAt,
            'last_run_at' => (string)($state['last_run_at'] ?? ''),
            'last_verified_at' => (string)($state['last_verified_at'] ?? ''),
            'message' => (string)($state['auto_last_message'] ?? ''),
            'last_error' => (string)($state['last_error'] ?? ''),
            'tables' => $tables,
        ];
    }

    public static function mark_cutover_ready(bool $ready): bool {
        if (!$ready) {
            update_option('bluevpn_manager_cutover_ready', '0', false);
            return true;
        }
        $status = self::reconcile_readiness(false);
        if (empty($status['ready'])) {
            update_option('bluevpn_manager_cutover_ready', '0', false);
            return false;
        }
        update_option('bluevpn_manager_cutover_ready', '1', false);
        return true;
    }

    private static function next_table(array $state): ?string {
        foreach (self::table_order() as $name) {
            if (empty($state['tables'][$name]['done'])) return $name;
        }
        return null;
    }

    private static function import_batch(string $table, string $cursor) {
        $batchStarted = microtime(true);
        if (!in_array($table, self::table_order(), true)) return new WP_Error('invalid_table', 'جدول مهاجرت معتبر نیست.');
        $settings = self::settings();
        if ($table === 'ad_assets') {
            $effectiveLimit = min(20, (int)$settings['batch_size']);
        } elseif ($table === 'ai_connection_events') {
            // History is append-only and is by far the largest table. Pull several
            // thousand rows per request, then write them to MySQL in bulk below.
            $effectiveLimit = self::FAST_EXPORT_LIMIT;
        } elseif (in_array($table, ['ai_live_connections', 'ai_route_aggregates', 'ai_feedback'], true)) {
            $effectiveLimit = min(2500, self::FAST_EXPORT_LIMIT);
        } else {
            $effectiveLimit = min(max(1000, (int)$settings['batch_size']), self::FAST_EXPORT_LIMIT);
        }
        $query = ['limit' => $effectiveLimit];
        if ($cursor !== '') $query['after'] = $cursor;
        $path = '/internal/migration/v1/export/' . rawurlencode($table) . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $payload = self::request($path, ['timeout' => 45]);
        // Rolling-deploy compatibility: if WordPress updates a few seconds before
        // Railway and the old Bridge still caps pages at 1000, keep migrating
        // instead of stopping with HTTP 422. The next run will automatically use 5k.
        if (is_wp_error($payload) && $effectiveLimit > 1000) {
            $data = $payload->get_error_data();
            if (is_array($data) && (int)($data['status'] ?? 0) === 422) {
                $query['limit'] = 1000;
                $fallbackPath = '/internal/migration/v1/export/' . rawurlencode($table) . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
                $payload = self::request($fallbackPath, ['timeout' => 45]);
            }
        }
        if (is_wp_error($payload)) return $payload;
        $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
        $decodedRows = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $decodedRows[] = self::decode_row($row);
        }
        $imported = count($decodedRows);
        if ($decodedRows) {
            $result = self::bulk_upsert($table, $decodedRows);
            if ($result === false) {
                global $wpdb;
                return new WP_Error('mysql_import_failed', 'خطای MySQL در جدول '.$table.': '.$wpdb->last_error);
            }
        }
        $state = self::state();
        $nextCursor = (string)($payload['next_cursor'] ?? '');
        if ($nextCursor === '' && $rows) {
            $pk = (string)($payload['primary_key'] ?? 'id');
            $last = end($rows);
            if (is_array($last) && array_key_exists($pk, $last)) $nextCursor = (string)$last[$pk];
        }
        $state['tables'][$table]['cursor'] = $nextCursor;
        $state['tables'][$table]['imported'] = (int)$state['tables'][$table]['imported'] + $imported;
        $state['tables'][$table]['cycle_imported'] = (int)($state['tables'][$table]['cycle_imported'] ?? 0) + $imported;
        $state['tables'][$table]['done'] = !empty($payload['done']);
        $state['tables'][$table]['last_error'] = '';
        $state['tables'][$table]['updated_at'] = BlueVPN_Utils::iso_now();
        $state['tables'][$table]['last_batch_rows'] = $imported;
        $state['tables'][$table]['last_batch_ms'] = max(1, (int)round((microtime(true) - $batchStarted) * 1000));
        if ($imported > 0 || !empty($payload['done'])) $state['last_progress_at'] = BlueVPN_Utils::iso_now();
        if (isset($payload['total'])) $state['tables'][$table]['source_count'] = (int)$payload['total'];
        self::save_state($state);
        return ['imported' => $imported, 'done' => !empty($payload['done'])];
    }

    private static function decode_row(array $row): array {
        foreach ($row as $key => $value) {
            if (is_array($value) && array_key_exists('__bytes_b64', $value)) {
                $decoded = base64_decode((string)$value['__bytes_b64'], true);
                $row[$key] = $decoded === false ? '' : $decoded;
            } elseif (is_array($value) && array_key_exists('__secret_plain', $value)) {
                $row[$key] = BlueVPN_Utils::encrypt_secret((string)$value['__secret_plain']);
            } elseif (is_array($value) && array_key_exists('__datetime', $value)) {
                $row[$key] = BlueVPN_Utils::mysql_from_iso((string)$value['__datetime']);
            }
        }
        return $row;
    }

    /**
     * Bulk REPLACE is dramatically faster than calling $wpdb->replace() once per row.
     * It preserves the exact semantics of the old importer (primary/unique conflicts are
     * replaced) while reducing thousands of MySQL round-trips to a few dozen queries.
     */
    private static function bulk_upsert(string $logicalTable, array $rows): bool {
        global $wpdb;
        if (!$rows) return true;
        $table = BlueVPN_DB::table($logicalTable);
        $columns = self::table_columns($logicalTable);
        if (!$columns) return false;
        $allowed = array_flip($columns);

        $usable = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            foreach ($row as $key => $_) {
                if (isset($allowed[$key])) $usable[$key] = true;
            }
        }
        $usableColumns = array_keys($usable);
        if (!$usableColumns) return true;

        $quotedColumns = implode(',', array_map(static fn($name) => '`'.str_replace('`', '``', $name).'`', $usableColumns));
        foreach (array_chunk($rows, self::BULK_WRITE_CHUNK) as $chunk) {
            $valueRows = [];
            foreach ($chunk as $row) {
                if (!is_array($row)) continue;
                $parts = [];
                foreach ($usableColumns as $column) {
                    $value = array_key_exists($column, $row) ? $row[$column] : null;
                    if ($value === null) {
                        $parts[] = 'NULL';
                    } else {
                        // %s is intentional for every non-NULL value. MySQL safely coerces
                        // numeric/date target columns while wpdb performs correct escaping.
                        $parts[] = $wpdb->prepare('%s', $value);
                    }
                }
                $valueRows[] = '('.implode(',', $parts).')';
            }
            if (!$valueRows) continue;
            $sql = "REPLACE INTO {$table} ({$quotedColumns}) VALUES ".implode(',', $valueRows);
            if ($wpdb->query($sql) === false) return false;
        }
        return true;
    }

    private static function table_columns(string $logicalTable): array {
        static $cache = [];
        if (isset($cache[$logicalTable])) return $cache[$logicalTable];
        global $wpdb;
        $table = BlueVPN_DB::table($logicalTable);
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
        $cache[$logicalTable] = is_array($columns) ? array_values($columns) : [];
        return $cache[$logicalTable];
    }

    private static function execution_budget_seconds(): float {
        $configured = (int)ini_get('max_execution_time');
        // Keep enough headroom for PHP/FPM/hosting termination while allowing one
        // turbo run to finish several 5k-row pages in a single cron invocation.
        if ($configured <= 0) return 45.0;
        return (float)max(18, min(45, $configured - 5));
    }

    private static function request(string $path, array $args = []) {
        $settings = self::settings();
        $source = untrailingslashit((string)$settings['source_url']);
        $token = self::token();
        if ($source === '') return new WP_Error('migration_source_missing', 'آدرس Backend فعلی Railway ثبت نشده است.');
        if ($token === '') return new WP_Error('migration_token_missing', 'Migration Token در WordPress ثبت نشده است. ابتدا Token را بساز/ذخیره کن.');
        $url = $source . '/' . ltrim($path, '/');
        $defaults = [
            'timeout' => 30,
            'redirection' => 2,
            'sslverify' => !empty($settings['verify_tls']),
            'headers' => [
                'Accept' => 'application/json',
                'X-BlueVPN-Migration-Token' => $token,
                'User-Agent' => 'BlueVPN-Manager/' . BLUEVPN_MANAGER_VERSION . ' WordPress/' . get_bloginfo('version'),
            ],
        ];
        $response = wp_remote_get($url, array_merge($defaults, $args));
        if (is_wp_error($response)) {
            return new WP_Error(
                'migration_transport_error',
                'ارتباط WordPress با Railway برقرار نشد: '.$response->get_error_message().' | URL: '.$url,
                ['url' => $url, 'original_code' => $response->get_error_code()]
            );
        }
        $status = (int)wp_remote_retrieve_response_code($response);
        $body = (string)wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        if ($status < 200 || $status >= 300) {
            $detail = is_array($decoded) ? trim((string)($decoded['detail'] ?? $decoded['message'] ?? '')) : '';
            if ($status === 404) {
                $message = 'Backend Railway در دسترس است، اما Migration Bridge روی Deployment فعلی ثبت نشده است (HTTP 404). Guard/Bridge را روی main فعال کن و منتظر Deploy جدید Railway بمان.';
            } elseif ($status === 401) {
                $message = 'Migration Token بین WordPress و Railway یکسان نیست (HTTP 401). مقدار WORDPRESS_MIGRATION_TOKEN را با Token ذخیره‌شده در WordPress یکسان کن و Railway را Redeploy کن.';
            } elseif ($status === 503 && stripos($detail, 'WORDPRESS_MIGRATION_TOKEN') !== false) {
                $message = 'متغیر WORDPRESS_MIGRATION_TOKEN در Railway تنظیم نشده یا کمتر از 32 کاراکتر است (HTTP 503).';
            } elseif ($status === 403) {
                $message = 'درخواست Migration Bridge توسط سرور/فایروال رد شد (HTTP 403).';
            } elseif ($status >= 500) {
                $message = 'Migration Bridge در Railway خطای داخلی دارد (HTTP '.$status.').'.($detail !== '' ? ' '.$detail : '');
            } else {
                $message = 'Railway Migration API: '.($detail !== '' ? $detail : 'HTTP '.$status);
            }
            return new WP_Error('migration_http_error', $message.' | URL: '.$url, ['status' => $status, 'url' => $url]);
        }
        if (!is_array($decoded)) {
            return new WP_Error('migration_invalid_json', 'Railway پاسخ HTTP 200 داد اما پاسخ Migration API از نوع JSON معتبر نیست. احتمالاً URL به سرویس اشتباه اشاره می‌کند. | URL: '.$url, ['status' => $status, 'url' => $url]);
        }
        return $decoded;
    }

    public static function sync_cron_schedule(bool $enabled): void {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if (!$enabled && $timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
            return;
        }
        if ($enabled && !$timestamp) wp_schedule_event(time() + 120, 'bluevpn_five_minutes', self::CRON_HOOK);
    }

    public static function sync_auto_schedule(bool $enabled): void {
        $timestamp = wp_next_scheduled(self::AUTO_HOOK);
        if (!$enabled && $timestamp) {
            wp_unschedule_event($timestamp, self::AUTO_HOOK);
            return;
        }
        if ($enabled && !$timestamp) {
            wp_schedule_event(time(), 'bluevpn_one_minute', self::AUTO_HOOK);
        }
    }

    public static function start_auto(): void {
        $settings = self::settings();
        $settings['auto_migrate'] = true;
        update_option(self::SETTINGS_OPTION, $settings, false);
        $state = self::state();
        if (empty($state['auto_started_at'])) $state['auto_started_at'] = BlueVPN_Utils::iso_now();
        $state['auto_completed_at'] = '';
        $state['auto_retry_count'] = 0;
        if (in_array((string)$state['phase'], ['paused','error','needs_attention','verification_failed'], true)) {
            $resume = (string)($state['paused_from_phase'] ?? '');
            if (!in_array($resume, ['copying','initial_verify','resyncing','final_verify'], true)) {
                $resume = empty($state['initial_completed_at']) ? 'copying' : ((int)($state['auto_resync_cycles'] ?? 0) > 0 ? 'final_verify' : 'initial_verify');
            }
            $state['phase'] = $resume;
            $state['paused_from_phase'] = '';
            $state['last_error'] = '';
        }
        $state['auto_last_message'] = 'Runner هوشمند فعال است؛ انتقال از آخرین نقطه معتبر ادامه پیدا می‌کند.';
        self::save_state($state);
        self::sync_auto_schedule(true);
        if (function_exists('spawn_cron')) spawn_cron(time());
    }

    public static function stop_auto(): void {
        $settings = self::settings();
        $settings['auto_migrate'] = false;
        update_option(self::SETTINGS_OPTION, $settings, false);
        self::sync_auto_schedule(false);
        $state = self::state();
        if (!in_array((string)$state['phase'], ['ready_for_cutover','not_started'], true)) {
            $state['paused_from_phase'] = (string)$state['phase'];
            $state['phase'] = 'paused';
        }
        $state['auto_last_message'] = 'انتقال موقتاً متوقف شد؛ Progress و Cursorها حفظ شده‌اند.';
        self::save_state($state);
    }

    private static function maybe_resume_auto(): void {
        $settings = self::settings();
        if (empty($settings['auto_migrate']) || empty($settings['source_url']) || !self::has_token()) return;
        $state = self::state();
        $hasProgress = !empty($state['source_counts']) && $state['phase'] !== 'not_started';
        if (!$hasProgress) return;
        $readiness = self::reconcile_readiness(true);
        if (empty($readiness['ready']) && $state['phase'] !== 'paused') self::sync_auto_schedule(true);
    }

    public static function auto_step(int $maxBatches = 20): void {
        $settings = self::settings();
        if (empty($settings['auto_migrate']) || empty($settings['source_url']) || !self::has_token()) return;
        if (get_transient(self::AUTO_LOCK)) return;
        set_transient(self::AUTO_LOCK, '1', 55);
        try {
            $state = self::state();
            if (empty($state['auto_started_at'])) {
                $state['auto_started_at'] = BlueVPN_Utils::iso_now();
                self::save_state($state);
            }

            // Normalize state names written by older 4.0.x releases without
            // discarding cursor/progress information.
            $legacy = [
                'running' => 'copying',
                'initial_complete' => 'initial_verify',
                'sync_complete' => 'final_verify',
                'verification_needed' => 'final_verify',
                'verification_failed' => 'needs_attention',
            ];
            $state = self::state();
            if (isset($legacy[$state['phase']])) {
                $state['phase'] = $legacy[$state['phase']];
                self::save_state($state);
            }

            $state = self::state();
            if ($state['phase'] === 'ready_for_cutover') {
                self::sync_auto_schedule(false);
                return;
            }
            if ($state['phase'] === 'paused') return;

            if ($state['phase'] === 'not_started') {
                $state['phase'] = 'scanning';
                $state['auto_last_message'] = 'در حال اسکن Railway و دریافت Manifest جدول‌ها…';
                self::save_state($state);
            }

            $state = self::state();
            if ($state['phase'] === 'scanning') {
                $manifest = self::refresh_manifest();
                if (is_wp_error($manifest)) {
                    self::auto_error($manifest->get_error_message());
                    return;
                }
                $state = self::state();
                $state['phase'] = 'copying';
                $state['last_error'] = '';
                $state['auto_retry_count'] = 0;
                $state['last_progress_at'] = BlueVPN_Utils::iso_now();
                $state['auto_last_message'] = 'Manifest دریافت شد؛ انتقال اولیه داده‌ها شروع شد.';
                self::save_state($state);
            }

            $state = self::state();
            if ($state['phase'] === 'copying') {
                $result = self::run(max(1, min(20, $maxBatches)));
                if (empty($result['success'])) {
                    $errors = $result['errors'] ?? [];
                    self::auto_error($errors ? implode(' | ', $errors) : 'خطای نامشخص در انتقال اولیه');
                    return;
                }
                $state = self::state();
                $state['auto_retry_count'] = 0;
                if ($state['phase'] === 'copying') {
                    $state['auto_last_message'] = (int)$result['rows_imported'].' رکورد در این نوبت منتقل شد؛ Runner از همین Cursor ادامه می‌دهد.';
                    self::save_state($state);
                }
                return;
            }

            $state = self::state();
            if ($state['phase'] === 'initial_verify') {
                $manifest = self::refresh_manifest();
                if (is_wp_error($manifest)) {
                    self::auto_error($manifest->get_error_message());
                    return;
                }
                // One full safety pass is deliberate: count equality alone cannot
                // detect rows that changed in place while the first copy was running.
                if ((int)($state['auto_resync_cycles'] ?? 0) < 1) {
                    self::start_resync(true, null, 'final');
                    return;
                }
                $state = self::state();
                $state['phase'] = 'final_verify';
                self::save_state($state);
            }

            $state = self::state();
            if ($state['phase'] === 'resyncing') {
                $result = self::run(max(1, min(20, $maxBatches)));
                if (empty($result['success'])) {
                    $errors = $result['errors'] ?? [];
                    self::auto_error($errors ? implode(' | ', $errors) : 'خطای نامشخص در Resync');
                    return;
                }
                $state = self::state();
                $state['auto_retry_count'] = 0;
                if ($state['phase'] === 'resyncing') {
                    $state['auto_last_message'] = (int)$result['rows_imported'].' رکورد در این دور همگام شد؛ فقط از Cursorهای باقی‌مانده ادامه می‌دهیم.';
                    self::save_state($state);
                }
                return;
            }

            $state = self::state();
            if ($state['phase'] === 'final_verify') {
                $manifest = self::refresh_manifest();
                if (is_wp_error($manifest)) {
                    self::auto_error($manifest->get_error_message());
                    return;
                }
                $readiness = self::reconcile_readiness(false);
                $mismatches = (array)($readiness['mismatches'] ?? []);
                $tableErrors = (array)($readiness['table_errors'] ?? []);

                if (!$mismatches && !$tableErrors) {
                    $state = self::state();
                    $state['final_verify_passes'] = (int)($state['final_verify_passes'] ?? 0) + 1;
                    $state['last_verified_at'] = BlueVPN_Utils::iso_now();
                    $state['auto_completed_at'] = BlueVPN_Utils::iso_now();
                    $state['verification_failures'] = 0;
                    $state['auto_retry_count'] = 0;
                    $state['last_error'] = '';
                    $state['auto_last_message'] = 'بررسی نهایی موفق بود؛ همه جدول‌ها Railway را پوشش می‌دهند و Cutover آماده است.';
                    self::save_state($state);
                    self::reconcile_readiness(true);
                    self::sync_auto_schedule(false);
                    return;
                }

                $targets = array_values(array_unique(array_merge(array_keys($mismatches), array_keys($tableErrors))));
                $state = self::state();
                $failures = (int)($state['verification_failures'] ?? 0) + 1;
                $state['verification_failures'] = $failures;
                self::save_state($state);
                if ($failures >= 4) {
                    $state = self::state();
                    $state['phase'] = 'needs_attention';
                    $state['paused_from_phase'] = 'final_verify';
                    $state['last_error'] = 'پس از چهار تلاش هنوز اختلاف باقی مانده است: '.implode(', ', $targets);
                    $state['auto_last_message'] = 'Retry خودکار متوقف شد تا حلقه ایجاد نشود. فقط جدول‌های مشخص‌شده نیاز به ترمیم/بررسی دارند.';
                    self::save_state($state);
                    self::sync_auto_schedule(false);
                    return;
                }

                self::start_resync(false, $targets, 'delta');
                $state = self::state();
                $state['auto_last_message'] = count($targets).' جدول نیاز به ترمیم دارد؛ فقط همان‌ها دوباره منتقل می‌شوند (تلاش '.$failures.'/4).';
                self::save_state($state);
                return;
            }

            if (in_array((string)self::state()['phase'], ['error','needs_attention'], true)) {
                // Do not silently loop forever. The page shows a single explicit
                // Resume action that keeps all cursors intact.
                self::sync_auto_schedule(false);
            }
        } finally {
            delete_transient(self::AUTO_LOCK);
        }
    }

    private static function auto_error(string $message): void {
        $state = self::state();
        $retry = min(20, (int)($state['auto_retry_count'] ?? 0) + 1);
        $state['auto_retry_count'] = $retry;
        $state['auto_last_message'] = 'خطای موقت؛ Retry خودکار '.$retry.'/5: '.$message;
        $state['last_error'] = $message;
        if ($retry >= 5) {
            $resume = (string)$state['phase'];
            if (!in_array($resume, ['copying','initial_verify','resyncing','final_verify','scanning'], true)) {
                $resume = empty($state['initial_completed_at']) ? 'copying' : ((int)($state['auto_resync_cycles'] ?? 0) > 0 ? 'final_verify' : 'initial_verify');
            }
            $state['paused_from_phase'] = $resume;
            $state['phase'] = 'needs_attention';
            $state['auto_last_message'] = 'پس از ۵ Retry انتقال متوقف شد تا سرور تحت فشار نرود. خطا: '.$message;
            self::sync_auto_schedule(false);
        }
        self::save_state($state);
    }

    public static function cron_sync(): void {
        $settings = self::settings();
        if (empty($settings['auto_sync'])) return;
        $state = self::state();
        if (!in_array((string)$state['phase'], ['ready_for_cutover', 'verification_needed'], true)) return;
        $manifest = self::refresh_manifest();
        if (is_wp_error($manifest)) {
            self::auto_error($manifest->get_error_message());
            return;
        }

        // If Railway did not gain any rows, keep the verified state stable and
        // do not start a pointless resync that makes the UI flicker/reset.
        $readiness = self::reconcile_readiness(true);
        if (!empty($readiness['ready'])) return;
        $mismatches = array_keys((array)($readiness['mismatches'] ?? []));
        if (!$mismatches) return;

        self::start_resync(false, $mismatches, 'delta');
        self::sync_auto_schedule(true); // one-minute runner finishes only the changed tables
        self::run(6);
    }
}
