<?php
if (!defined('ABSPATH')) exit;

final class BlueVPN_Migration {
    const SETTINGS_OPTION = 'bluevpn_migration_settings';
    const STATE_OPTION = 'bluevpn_migration_state';
    const CRON_HOOK = 'bluevpn_migration_sync';
    const DEFAULT_BATCH_SIZE = 250;

    public static function init(): void {
        add_action(self::CRON_HOOK, [self::class, 'cron_sync']);
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
            'verify_tls' => !empty($input['verify_tls']),
            'batch_size' => max(25, min(1000, (int)($input['batch_size'] ?? self::DEFAULT_BATCH_SIZE))),
            'auto_sync' => !empty($input['auto_sync']),
        ];
        update_option(self::SETTINGS_OPTION, $settings, false);
        self::sync_cron_schedule($settings['auto_sync']);
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
                'cursor' => '', 'imported' => 0, 'source_count' => null,
                'done' => false, 'last_error' => '', 'updated_at' => '',
            ], $row);
        }
        return array_merge([
            'phase' => 'not_started',
            'source_version' => '',
            'source_schema_version' => '',
            'source_database_mode' => '',
            'source_counts' => [],
            'last_manifest_at' => '',
            'last_run_at' => '',
            'last_error' => '',
            'initial_completed_at' => '',
            'last_full_sync_at' => '',
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
            'last_manifest_at' => $keepManifest ? $old['last_manifest_at'] : '',
            'last_run_at' => '', 'last_error' => '', 'initial_completed_at' => '',
            'last_full_sync_at' => '', 'tables' => [],
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
        $maxBatches = max(1, min(40, $maxBatches));
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
        while ($batches < $maxBatches && (microtime(true) - $started) < 18.0) {
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

    public static function start_resync(): void {
        $state = self::state();
        foreach ($state['tables'] as &$row) {
            $row['cursor'] = '';
            $row['imported'] = 0;
            $row['done'] = false;
            $row['last_error'] = '';
        }
        unset($row);
        $state['phase'] = 'resyncing';
        $state['last_error'] = '';
        self::save_state($state);
        update_option('bluevpn_manager_cutover_ready', '0', false);
    }

    public static function compare_counts(): array {
        $source = self::state()['source_counts'];
        $local = BlueVPN_DB::counts();
        $result = [];
        foreach (self::table_order() as $name) {
            $s = array_key_exists($name, $source) ? (int)$source[$name] : null;
            $l = array_key_exists($name, $local) ? (int)$local[$name] : null;
            $result[$name] = ['source' => $s, 'local' => $l, 'delta' => ($s !== null && $l !== null) ? $l - $s : null, 'match' => $s !== null && $l !== null && $s === $l];
        }
        return $result;
    }

    public static function mark_cutover_ready(bool $ready): void {
        update_option('bluevpn_manager_cutover_ready', $ready ? '1' : '0', false);
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
        $effectiveLimit = $table === 'ad_assets' ? min(5, (int)$settings['batch_size']) : (int)$settings['batch_size'];
        $query = ['limit' => $effectiveLimit];
        if ($cursor !== '') $query['after'] = $cursor;
        $path = '/internal/migration/v1/export/' . rawurlencode($table) . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $payload = self::request($path, ['timeout' => 45]);
        if (is_wp_error($payload)) return $payload;
        $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
        $imported = 0;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $decoded = self::decode_row($row);
            $result = self::upsert($table, $decoded);
            if ($result === false) {
                global $wpdb;
                return new WP_Error('mysql_import_failed', 'خطای MySQL در جدول '.$table.': '.$wpdb->last_error);
            }
            $imported++;
        }
        $state = self::state();
        $state['tables'][$table]['cursor'] = (string)($payload['next_cursor'] ?? '');
        $state['tables'][$table]['imported'] = (int)$state['tables'][$table]['imported'] + $imported;
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

    private static function upsert(string $logicalTable, array $row) {
        global $wpdb;
        $table = BlueVPN_DB::table($logicalTable);
        if (!$row) return true;
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
        if (!is_array($columns) || !$columns) return false;
        $allowed = array_flip($columns);
        $clean = [];
        foreach ($row as $key => $value) {
            if (isset($allowed[$key])) $clean[$key] = $value;
        }
        if (!$clean) return true;
        return $wpdb->replace($table, $clean);
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

    public static function cron_sync(): void {
        $settings = self::settings();
        if (empty($settings['auto_sync'])) return;
        $state = self::state();
        if (in_array($state['phase'], ['initial_complete', 'sync_complete'], true)) self::start_resync();
        self::run(4);
    }
}
