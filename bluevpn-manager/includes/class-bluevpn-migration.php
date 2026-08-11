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
            'final_resync_started_at' => '', 'verification_failures' => 0, 'tables' => [],
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
            if (is_wp_error($manifest)) return ['success' => false, 'error' => $manifest->get_error_message(), 'batches' => 0];
            $state = self::state();
        }
        $state['phase'] = 'running';
        $state['last_error'] = '';
        self::save_state($state);

        $batches = 0;
        $rowsImported = 0;
        $errors = [];
        while ($batches < $maxBatches && (microtime(true) - $started) < self::execution_budget_seconds()) {
            $state = self::state();
            $table = self::next_table($state);
            if ($table === null) {
                $wasResync = !empty($state['initial_completed_at']);
                $state['phase'] = $wasResync ? 'sync_complete' : 'initial_complete';
                if (!$state['initial_completed_at']) $state['initial_completed_at'] = BlueVPN_Utils::iso_now();
                $state['last_full_sync_at'] = BlueVPN_Utils::iso_now();
                $state['last_run_at'] = BlueVPN_Utils::iso_now();
                self::save_state($state);
                update_option('bluevpn_manager_cutover_ready', '0', false);
                return ['success' => true, 'complete' => true, 'batches' => $batches, 'rows_imported' => $rowsImported, 'errors' => []];
            }

            $result = self::import_batch($table, $state['tables'][$table]['cursor']);
            $batches++;
            if (is_wp_error($result)) {
                $message = $result->get_error_message();
                $state = self::state();
                $state['phase'] = 'error';
                $state['last_error'] = $message;
                $state['last_run_at'] = BlueVPN_Utils::iso_now();
                $state['tables'][$table]['last_error'] = $message;
                $state['tables'][$table]['updated_at'] = BlueVPN_Utils::iso_now();
                self::save_state($state);
                $errors[] = $message;
                break;
            }
            $rowsImported += (int)$result['imported'];
        }
        $state = self::state();
        $state['last_run_at'] = BlueVPN_Utils::iso_now();
        if (!$errors) $state['phase'] = 'running';
        self::save_state($state);
        return ['success' => !$errors, 'complete' => false, 'batches' => $batches, 'rows_imported' => $rowsImported, 'errors' => $errors];
    }

    public static function start_resync(bool $incremental = true, ?array $onlyTables = null): void {
        $state = self::state();
        $filter = null;
        if (is_array($onlyTables)) {
            $filter = array_fill_keys(array_values(array_intersect(self::table_order(), $onlyTables)), true);
        }
        foreach ($state['tables'] as $name => &$row) {
            // Progress in `imported` is intentionally cumulative. Older releases reset it
            // to zero at every verification pass, which made a completed migration look
            // as if it had restarted or lost data. `cycle_imported` is the per-pass counter.
            $row['cycle_imported'] = 0;
            $row['last_error'] = '';

            if ($filter !== null && !isset($filter[$name])) {
                $row['done'] = true;
                continue;
            }

            // ai_connection_events is append-only and can be very large. Resume from the
            // highest local primary key instead of retransferring the entire history.
            if ($incremental && $name === 'ai_connection_events') {
                $row['cursor'] = self::local_max_primary_key($name, $state);
            } else {
                $row['cursor'] = '';
            }
            $row['done'] = false;
        }
        unset($row);
        $state['phase'] = 'resyncing';
        $state['last_error'] = '';
        $state['auto_resync_cycles'] = (int)($state['auto_resync_cycles'] ?? 0) + 1;
        if (empty($state['final_resync_started_at'])) $state['final_resync_started_at'] = BlueVPN_Utils::iso_now();
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
        $finalPassCompleted = (
            !empty($state['initial_completed_at'])
            && (int)($state['auto_resync_cycles'] ?? 0) >= 1
            && in_array((string)$state['phase'], ['sync_complete', 'ready_for_cutover'], true)
        );
        $ready = $finalPassCompleted && !$mismatches;

        if ($mutate) {
            $storedReady = get_option('bluevpn_manager_cutover_ready', '0') === '1';
            if ($ready) {
                if (!$storedReady) update_option('bluevpn_manager_cutover_ready', '1', false);
                if ($state['phase'] !== 'ready_for_cutover') {
                    $state['phase'] = 'ready_for_cutover';
                    $state['auto_completed_at'] = $state['auto_completed_at'] ?: BlueVPN_Utils::iso_now();
                    $state['auto_last_message'] = 'انتقال و بررسی نهایی کامل است؛ شمار Railway و MySQL پوشش داده شده و Cutover به‌صورت خودکار آماده است.';
                    $state['last_error'] = '';
                    self::save_state($state);
                }
            } else {
                if ($storedReady) update_option('bluevpn_manager_cutover_ready', '0', false);
                if ($state['phase'] === 'ready_for_cutover') {
                    $state['phase'] = 'verification_needed';
                    $state['auto_completed_at'] = '';
                    $state['auto_last_message'] = $mismatches
                        ? 'پس از آخرین Manifest تغییر جدیدی در Railway دیده شد؛ فقط جدول‌های دارای کسری دوباره همگام می‌شوند.'
                        : 'بررسی نهایی هنوز کامل نشده است.';
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
            'comparison' => $comparison,
            'source_total' => $sourceTotal,
            'covered_total' => $coveredTotal,
            'percent' => $percent,
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
        $state['auto_last_message'] = 'انتقال خودکار فعال شد؛ WP-Cron و Runner صفحه مدیریت آماده‌اند.';
        $state['auto_retry_count'] = 0;
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
        $state['auto_last_message'] = 'انتقال خودکار متوقف شد.';
        self::save_state($state);
    }

    private static function maybe_resume_auto(): void {
        $settings = self::settings();
        if (empty($settings['auto_migrate']) || empty($settings['source_url']) || !self::has_token()) return;
        $state = self::state();
        $hasProgress = !empty($state['source_counts']) && $state['phase'] !== 'not_started';
        if (!$hasProgress) return;
        $readiness = self::reconcile_readiness(true);
        if (empty($readiness['ready'])) self::sync_auto_schedule(true);
    }

    public static function auto_step(int $maxBatches = 40): void {
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

            if ($state['phase'] === 'not_started') {
                $manifest = self::refresh_manifest();
                if (is_wp_error($manifest)) {
                    self::auto_error($manifest->get_error_message());
                    return;
                }
            }

            $state = self::state();
            if ($state['phase'] === 'initial_complete') {
                $manifest = self::refresh_manifest();
                if (is_wp_error($manifest)) {
                    self::auto_error($manifest->get_error_message());
                    return;
                }
                // Exactly one final verification pass is useful for rows that changed
                // while the first long transfer was running. Keep cumulative progress
                // intact so the UI never jumps back to zero.
                if ((int)($state['auto_resync_cycles'] ?? 0) < 1) {
                    self::start_resync(true);
                    $state = self::state();
                    $state['auto_last_message'] = 'انتقال اولیه ۱۰۰٪ شد؛ یک دور نهاییِ بررسی اختلافات در حال اجراست و Progress صفر نمی‌شود.';
                    self::save_state($state);
                }
            }

            $state = self::state();
            if ($state['phase'] === 'sync_complete') {
                $manifest = self::refresh_manifest();
                if (is_wp_error($manifest)) {
                    self::auto_error($manifest->get_error_message());
                    return;
                }
                $comparison = self::compare_counts();
                $mismatches = self::coverage_mismatches($comparison);
                if (!$mismatches) {
                    $state = self::state();
                    $state['phase'] = 'sync_complete';
                    $state['auto_completed_at'] = BlueVPN_Utils::iso_now();
                    $state['auto_last_message'] = 'انتقال و بررسی نهایی کامل شد؛ شمار واقعی Railway و MySQL تأیید شد و Progress روی ۱۰۰٪ می‌ماند.';
                    $state['auto_retry_count'] = 0;
                    $state['verification_failures'] = 0;
                    $state['last_error'] = '';
                    self::save_state($state);
                    self::reconcile_readiness(true);
                    self::sync_auto_schedule(false);
                    return;
                }

                $state = self::state();
                $failures = (int)($state['verification_failures'] ?? 0) + 1;
                $state['verification_failures'] = $failures;
                self::save_state($state);
                if ($failures >= 3) {
                    $names = implode(', ', array_keys($mismatches));
                    $state = self::state();
                    $state['phase'] = 'verification_failed';
                    $state['last_error'] = 'پس از سه تلاش، این جدول‌ها هنوز از Railway رکورد کمتری دارند: '.$names;
                    $state['auto_last_message'] = 'انتقال متوقف شد تا از حلقه تکرار جلوگیری شود. فقط جدول‌های دارای کسری نیاز به بررسی دارند: '.$names;
                    self::save_state($state);
                    self::sync_auto_schedule(false);
                    return;
                }

                // Retry only tables that are actually missing source rows. Do not
                // retransmit every completed table and do not reset cumulative counters.
                self::start_resync(false, array_keys($mismatches));
                $state = self::state();
                $state['auto_last_message'] = count($mismatches).' جدول کسری واقعی دارد؛ فقط همان جدول‌ها دوباره بررسی می‌شوند (تلاش '.$failures.'/3).';
                self::save_state($state);
            }

            $result = self::run(max(1, min(40, $maxBatches)));
            if (empty($result['success'])) {
                $errors = $result['errors'] ?? [];
                self::auto_error($errors ? implode(' | ', $errors) : 'خطای نامشخص در انتقال خودکار');
                return;
            }

            $state = self::state();
            $state['auto_retry_count'] = 0;
            $state['auto_last_message'] = !empty($result['complete'])
                ? 'یک مرحله انتقال کامل شد؛ مرحله بعد خودکار اجرا می‌شود.'
                : ((int)$result['rows_imported'].' رکورد در این اجرای خودکار منتقل شد.');
            self::save_state($state);
        } finally {
            delete_transient(self::AUTO_LOCK);
        }
    }

    private static function auto_error(string $message): void {
        $state = self::state();
        $state['auto_retry_count'] = min(20, (int)($state['auto_retry_count'] ?? 0) + 1);
        $state['auto_last_message'] = 'Retry خودکار: '.$message;
        $state['last_error'] = $message;
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

        self::start_resync(true, $mismatches);
        self::sync_auto_schedule(true); // one-minute runner finishes only the changed tables
        self::run(6);
    }
}
