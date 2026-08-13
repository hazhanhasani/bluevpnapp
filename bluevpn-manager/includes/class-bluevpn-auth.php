<?php
if (!defined('ABSPATH')) {
    exit;
}

final class BlueVPN_Auth_Exception extends Exception {
    public int $http_status;
    public string $error_code;
    public array $extra;

    public function __construct(int $http_status, string $error_code, string $message, array $extra = []) {
        parent::__construct($message);
        $this->http_status = $http_status;
        $this->error_code = $error_code;
        $this->extra = $extra;
    }
}

final class BlueVPN_Auth {
    private const PASSWORD_ROUNDS = 390000;
    private const SESSION_DAYS = 120;
    private const REFRESH_DAYS = 3650;
    private const PREVIOUS_REFRESH_MINUTES = 10;

    public static function normalize_email(string $raw): string {
        $email = strtolower(trim(sanitize_email($raw)));
        if (!$email || !is_email($email) || strlen($email) > 255) {
            throw new BlueVPN_Auth_Exception(422, 'EMAIL_INVALID', 'ایمیل معتبر نیست.');
        }
        return $email;
    }

    public static function validate_password(string $password): string {
        $len = strlen($password);
        if ($len < 8) {
            throw new BlueVPN_Auth_Exception(422, 'WEAK_PASSWORD', 'رمز عبور باید حداقل ۸ کاراکتر باشد.');
        }
        if ($len > 128) {
            throw new BlueVPN_Auth_Exception(422, 'PASSWORD_TOO_LONG', 'رمز عبور بیش از حد طولانی است.');
        }
        return $password;
    }

    /** Compatible with the Python format: pbkdf2_sha256$390000$urlsafe-b64-salt$urlsafe-b64-digest */
    public static function password_hash_compat(string $password): string {
        $salt = random_bytes(18);
        $digest = hash_pbkdf2('sha256', $password, $salt, self::PASSWORD_ROUNDS, 32, true);
        return 'pbkdf2_sha256$' . self::PASSWORD_ROUNDS . '$' .
            BlueVPN_Utils::base64url_encode_with_padding($salt) . '$' .
            BlueVPN_Utils::base64url_encode_with_padding($digest);
    }

    public static function password_verify_compat(string $password, string $stored): bool {
        try {
            $parts = explode('$', $stored, 4);
            if (count($parts) !== 4 || $parts[0] !== 'pbkdf2_sha256') {
                return false;
            }
            $rounds = (int)$parts[1];
            if ($rounds < 10000 || $rounds > 2000000) {
                return false;
            }
            $salt = BlueVPN_Utils::base64url_decode($parts[2]);
            $expected = BlueVPN_Utils::base64url_decode($parts[3]);
            if ($salt === false || $expected === false) {
                return false;
            }
            $actual = hash_pbkdf2('sha256', $password, $salt, $rounds, strlen($expected), true);
            return hash_equals($expected, $actual);
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function token_hash(string $raw): string {
        return hash('sha256', $raw);
    }

    public static function create_customer(string $email, string $password): array {
        global $wpdb;
        $table = BlueVPN_DB::table('customers');
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE email=%s LIMIT 1", $email));
        if ($existing) {
            throw new BlueVPN_Auth_Exception(409, 'EMAIL_ALREADY_USED', 'این ایمیل قبلاً ثبت شده است؛ وارد حساب شوید.');
        }

        $token = BlueVPN_Utils::random_token(32);
        $now = BlueVPN_Utils::now_mysql();
        $ok = $wpdb->insert($table, [
            'email' => $email,
            'password_hash' => self::password_hash_compat($password),
            'phone' => null,
            'auth_method' => 'email_password',
            'active' => 1,
            'subscription_token' => $token,
            'subscription_url' => '',
            'subscription_status' => 'inactive',
            'data_limit_bytes' => 0,
            'used_traffic_bytes' => 0,
            'device_limit' => 1,
            'last_sync_error' => '',
            'created_at' => $now,
        ]);
        if ($ok === false) {
            throw new BlueVPN_Auth_Exception(500, 'ACCOUNT_CREATE_FAILED', 'ساخت حساب انجام نشد.');
        }
        return self::get_customer((int)$wpdb->insert_id);
    }

    public static function get_customer(int $id): array {
        global $wpdb;
        $table = BlueVPN_DB::table('customers');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d LIMIT 1", $id), ARRAY_A);
        if (!$row) {
            throw new BlueVPN_Auth_Exception(404, 'ACCOUNT_NOT_FOUND', 'حساب کاربر پیدا نشد.');
        }
        return $row;
    }

    public static function customer_by_email(string $email): ?array {
        global $wpdb;
        $table = BlueVPN_DB::table('customers');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE email=%s LIMIT 1", $email), ARRAY_A);
        return $row ?: null;
    }

    public static function customer_by_identity(string $identity): ?array {
        global $wpdb;
        $table = BlueVPN_DB::table('customers');
        $identity = trim($identity);
        if ($identity === '') {
            return null;
        }
        if (str_contains($identity, '@')) {
            return self::customer_by_email(strtolower($identity));
        }
        $phone = BlueVPN_Utils::sanitize_phone($identity);
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE phone=%s LIMIT 1", $phone), ARRAY_A);
        return $row ?: null;
    }

    private static function client_type(string $device_id, string $device_name = ''): string {
        $id = strtolower(trim($device_id));
        $name = strtolower(trim($device_name));
        if (str_starts_with($id, 'web-') || str_contains($name, 'bluevpn web')) return 'web';
        return 'app';
    }

    public static function issue_session(array $customer, string $device_id, string $device_name = '', bool $rotate_refresh = true): array {
        global $wpdb;
        $device_id = mb_substr(trim($device_id), 0, 180);
        if ($device_id === '') {
            throw new BlueVPN_Auth_Exception(422, 'DEVICE_ID_REQUIRED', 'شناسه دستگاه لازم است');
        }
        $device_name = mb_substr(trim($device_name), 0, 180);
        $client_type = self::client_type($device_id, $device_name);
        $devices = BlueVPN_DB::table('customer_devices');
        $sessions = BlueVPN_DB::table('customer_sessions');
        $customer_id = (int)$customer['id'];
        $device = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$devices} WHERE customer_id=%d AND device_id=%s LIMIT 1",
            $customer_id,
            $device_id
        ), ARRAY_A);
        $is_new_device = !$device;
        $active_count = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$devices} WHERE customer_id=%d AND active=1 AND client_type='app'",
            $customer_id
        ));
        $limit = max(1, (int)($customer['device_limit'] ?? 1));
        if ($client_type === 'app' && !$device && $active_count >= $limit) {
            throw new BlueVPN_Auth_Exception(409, 'DEVICE_LIMIT_REACHED', "حداکثر {$limit} دستگاه برای این حساب مجاز است");
        }

        $now = BlueVPN_Utils::now_mysql();
        if (!$device) {
            $wpdb->insert($devices, [
                'customer_id' => $customer_id,
                'device_id' => $device_id,
                'device_name' => $device_name,
                'client_type' => $client_type,
                'active' => 1,
                'refresh_token_hash' => '',
                'previous_refresh_token_hash' => '',
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ]);
            $device = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$devices} WHERE id=%d",
                (int)$wpdb->insert_id
            ), ARRAY_A);
        } else {
            $wpdb->update($devices, [
                'active' => 1,
                'client_type' => $client_type,
                'device_name' => $device_name !== '' ? $device_name : (string)$device['device_name'],
                'last_seen_at' => $now,
            ], ['id' => (int)$device['id']]);
            $device['active'] = 1;
            $device['last_seen_at'] = $now;
            if ($device_name !== '') {
                $device['device_name'] = $device_name;
            }
        }

        $session_raw = BlueVPN_Utils::random_token(48);
        $session_hash = self::token_hash($session_raw);
        $session_expiry = gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS * self::SESSION_DAYS);
        $wpdb->insert($sessions, [
            'customer_id' => $customer_id,
            'token_hash' => $session_hash,
            'device_id' => $device_id,
            'client_type' => $client_type,
            'expires_at' => $session_expiry,
            'revoked_at' => null,
            'last_seen_at' => $now,
            'created_at' => $now,
        ]);

        $refresh_raw = '';
        $current_refresh_hash = (string)($device['refresh_token_hash'] ?? '');
        if ($rotate_refresh || $current_refresh_hash === '') {
            $refresh_raw = BlueVPN_Utils::random_token(48);
            $refresh_hash = self::token_hash($refresh_raw);
            $update = [
                'refresh_token_hash' => $refresh_hash,
                'refresh_expires_at' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS * self::REFRESH_DAYS),
                'last_seen_at' => $now,
            ];
            if ($current_refresh_hash !== '') {
                $update['previous_refresh_token_hash'] = $current_refresh_hash;
                $update['previous_refresh_expires_at'] = gmdate('Y-m-d H:i:s', time() + MINUTE_IN_SECONDS * self::PREVIOUS_REFRESH_MINUTES);
            }
            $wpdb->update($devices, $update, ['id' => (int)$device['id']]);
        }
        if ($client_type === 'app' && $is_new_device && !empty($customer['phone']) && class_exists('BlueVPN_SMS_Notifications')) {
            $date = str_replace('، ', ' ', BlueVPN_Utils::tehran_datetime_fa(null, false));
            BlueVPN_SMS_Notifications::queue(
                'new_device_login',
                (string)$customer['phone'],
                ['device'=>mb_substr($device_name !== '' ? $device_name : 'دستگاه جدید',0,30),'date'=>mb_substr($date,0,16)],
                $customer_id, null, 'new-device:'.$customer_id.':'.$device_id
            );
        }
        return ['token' => $session_raw, 'refresh_token' => $refresh_raw];
    }

    public static function refresh_session(string $identity, string $device_id, string $refresh_token, string $device_name = ''): array {
        global $wpdb;
        if (trim($device_id) === '' || trim($refresh_token) === '') {
            throw new BlueVPN_Auth_Exception(401, 'REFRESH_REQUIRED', 'اطلاعات تمدید ورود کامل نیست');
        }
        $customer = self::customer_by_identity($identity);
        if (!$customer || !(int)$customer['active']) {
            throw new BlueVPN_Auth_Exception(401, 'ACCOUNT_DISABLED', 'حساب در دسترس نیست');
        }
        $devices = BlueVPN_DB::table('customer_devices');
        $device = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$devices} WHERE customer_id=%d AND device_id=%s LIMIT 1",
            (int)$customer['id'], mb_substr(trim($device_id), 0, 180)
        ), ARRAY_A);
        if (!$device || !(int)$device['active']) {
            throw new BlueVPN_Auth_Exception(401, 'DEVICE_DISABLED', 'این دستگاه غیرفعال شده است');
        }
        $submitted = self::token_hash($refresh_token);
        $now = time();
        $current_valid = !empty($device['refresh_token_hash']) && hash_equals((string)$device['refresh_token_hash'], $submitted)
            && !empty($device['refresh_expires_at']) && strtotime($device['refresh_expires_at'] . ' UTC') > $now;
        $previous_valid = !empty($device['previous_refresh_token_hash']) && hash_equals((string)$device['previous_refresh_token_hash'], $submitted)
            && !empty($device['previous_refresh_expires_at']) && strtotime($device['previous_refresh_expires_at'] . ' UTC') > $now;
        if (!$current_valid && !$previous_valid) {
            throw new BlueVPN_Auth_Exception(401, 'INVALID_REFRESH', 'مجوز تمدید ورود معتبر نیست');
        }
        $tokens = self::issue_session($customer, $device_id, $device_name, true);
        return ['customer' => $customer, 'tokens' => $tokens];
    }

    public static function authorization_header(?WP_REST_Request $request = null): string {
        if ($request) {
            $value = trim((string)$request->get_header('authorization'));
            if ($value !== '') {
                return $value;
            }
        }
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (!empty($_SERVER[$key])) {
                return trim((string)wp_unslash($_SERVER[$key]));
            }
        }
        return '';
    }

    public static function bearer_token(?WP_REST_Request $request = null): string {
        $header = self::authorization_header($request);
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    public static function current_customer(WP_REST_Request $request): array {
        global $wpdb;
        $raw = self::bearer_token($request);
        if ($raw === '') {
            throw new BlueVPN_Auth_Exception(401, 'AUTH_REQUIRED', 'ورود لازم است');
        }
        $sessions = BlueVPN_DB::table('customer_sessions');
        $customers = BlueVPN_DB::table('customers');
        $devices = BlueVPN_DB::table('customer_devices');
        $hash = self::token_hash($raw);
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$sessions} WHERE token_hash=%s LIMIT 1",
            $hash
        ), ARRAY_A);
        if (!$session || !empty($session['revoked_at']) || empty($session['expires_at']) || strtotime($session['expires_at'] . ' UTC') <= time()) {
            throw new BlueVPN_Auth_Exception(401, 'INVALID_SESSION', 'نشست معتبر نیست');
        }
        $customer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$customers} WHERE id=%d LIMIT 1",
            (int)$session['customer_id']
        ), ARRAY_A);
        if (!$customer || !(int)$customer['active']) {
            throw new BlueVPN_Auth_Exception(401, 'INVALID_SESSION', 'نشست معتبر نیست');
        }
        $xDevice = trim((string)$request->get_header('x-device-id'));
        if ($xDevice !== '' && !hash_equals((string)$session['device_id'], $xDevice)) {
            throw new BlueVPN_Auth_Exception(401, 'DEVICE_MISMATCH', 'شناسه دستگاه معتبر نیست');
        }
        $device = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$devices} WHERE customer_id=%d AND device_id=%s LIMIT 1",
            (int)$customer['id'], (string)$session['device_id']
        ), ARRAY_A);
        if (!$device || !(int)$device['active']) {
            throw new BlueVPN_Auth_Exception(401, 'DEVICE_DISABLED', 'این دستگاه غیرفعال شده است');
        }
        $now = BlueVPN_Utils::now_mysql();
        $wpdb->update($sessions, [
            'last_seen_at' => $now,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS * self::SESSION_DAYS),
        ], ['id' => (int)$session['id']]);
        $wpdb->update($devices, ['last_seen_at' => $now], ['id' => (int)$device['id']]);
        return $customer;
    }

    public static function logout(WP_REST_Request $request): void {
        global $wpdb;
        $raw = self::bearer_token($request);
        if ($raw === '') {
            return;
        }
        $sessions = BlueVPN_DB::table('customer_sessions');
        $devices = BlueVPN_DB::table('customer_devices');
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$sessions} WHERE token_hash=%s LIMIT 1",
            self::token_hash($raw)
        ), ARRAY_A);
        if (!$session) {
            return;
        }
        $wpdb->update($sessions, ['revoked_at' => BlueVPN_Utils::now_mysql()], ['id' => (int)$session['id']]);
        $deviceId = trim((string)$request->get_header('x-device-id')) ?: (string)$session['device_id'];
        $device = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$devices} WHERE customer_id=%d AND device_id=%s LIMIT 1",
            (int)$session['customer_id'], $deviceId
        ), ARRAY_A);
        if ($device) {
            $wpdb->update($devices, [
                'refresh_token_hash' => '',
                'refresh_expires_at' => null,
                'previous_refresh_token_hash' => '',
                'previous_refresh_expires_at' => null,
            ], ['id' => (int)$device['id']]);
        }
    }

    private static function rate_limit_key(string $scope, string $identity): string {
        $ip = sanitize_text_field((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        return 'bluevpn_rl_' . substr(hash('sha256', $scope.'|'.$ip.'|'.mb_strtolower(trim($identity))), 0, 48);
    }

    /** Fixed-window protection for password endpoints; keyed by source IP + identity. */
    public static function enforce_rate_limit(string $scope, string $identity, int $maxAttempts, int $windowSeconds): string {
        $key = self::rate_limit_key($scope, $identity);
        $state = get_transient($key);
        if (!is_array($state)) $state = ['hits'=>0,'started'=>time()];
        $started = (int)($state['started'] ?? time());
        if ($started + $windowSeconds <= time()) $state = ['hits'=>0,'started'=>time()];
        $hits = (int)($state['hits'] ?? 0);
        if ($hits >= $maxAttempts) {
            $retry = max(1, ($started + $windowSeconds) - time());
            throw new BlueVPN_Auth_Exception(429, 'RATE_LIMITED', 'تعداد تلاش‌ها بیش از حد مجاز است؛ کمی بعد دوباره تلاش کنید.', ['retry_after'=>$retry]);
        }
        $state['hits'] = $hits + 1;
        set_transient($key, $state, $windowSeconds);
        return $key;
    }

    public static function clear_rate_limit(string $key): void {
        if ($key !== '') delete_transient($key);
    }

    public static function revoke_session(int $customerId, int $sessionId): bool {
        global $wpdb;
        if ($customerId <= 0 || $sessionId <= 0) return false;
        return $wpdb->update(BlueVPN_DB::table('customer_sessions'), ['revoked_at'=>BlueVPN_Utils::now_mysql()], ['id'=>$sessionId,'customer_id'=>$customerId]) !== false;
    }

    public static function revoke_device(int $customerId, string $deviceId, bool $notify=true): bool {
        global $wpdb;
        $deviceId = mb_substr(trim($deviceId),0,180);
        if ($customerId <= 0 || $deviceId === '') return false;
        $devices = BlueVPN_DB::table('customer_devices');
        $sessions = BlueVPN_DB::table('customer_sessions');
        $device = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$devices} WHERE customer_id=%d AND device_id=%s LIMIT 1",$customerId,$deviceId),ARRAY_A);
        if (!$device) return false;
        $now = BlueVPN_Utils::now_mysql();
        $wpdb->update($devices,[
            'active'=>0,'refresh_token_hash'=>'','refresh_expires_at'=>null,
            'previous_refresh_token_hash'=>'','previous_refresh_expires_at'=>null,'last_seen_at'=>$now,
        ],['id'=>(int)$device['id']]);
        $wpdb->query($wpdb->prepare("UPDATE {$sessions} SET revoked_at=%s WHERE customer_id=%d AND device_id=%s AND revoked_at IS NULL",$now,$customerId,$deviceId));
        if ($notify && class_exists('BlueVPN_SMS_Notifications')) {
            $c = $wpdb->get_row($wpdb->prepare('SELECT phone FROM '.BlueVPN_DB::table('customers').' WHERE id=%d',$customerId),ARRAY_A);
            if ($c && !empty($c['phone'])) {
                try { BlueVPN_SMS_Notifications::queue('device_removed',(string)$c['phone'],['device'=>mb_substr((string)($device['device_name'] ?: 'دستگاه'),0,30)],$customerId,null,'device-removed:'.$customerId.':'.hash('sha256',$deviceId).':'.gmdate('YmdHi')); } catch (Throwable $e) {}
            }
        }
        return true;
    }

    public static function revoke_all_sessions(int $customerId, bool $disableDevices=false): int {
        global $wpdb;
        if ($customerId <= 0) return 0;
        $now = BlueVPN_Utils::now_mysql();
        $sessions = BlueVPN_DB::table('customer_sessions');
        $devices = BlueVPN_DB::table('customer_devices');
        $count = (int)$wpdb->query($wpdb->prepare("UPDATE {$sessions} SET revoked_at=%s WHERE customer_id=%d AND revoked_at IS NULL",$now,$customerId));
        $data = ['refresh_token_hash'=>'','refresh_expires_at'=>null,'previous_refresh_token_hash'=>'','previous_refresh_expires_at'=>null];
        if ($disableDevices) $data['active']=0;
        $wpdb->update($devices,$data,['customer_id'=>$customerId]);
        return max(0,$count);
    }

    public static function account_payload(array $c): array {
        global $wpdb;
        $expiry = !empty($c['subscription_expire']) ? strtotime($c['subscription_expire'] . ' UTC') : false;
        $status = strtolower(trim((string)($c['subscription_status'] ?? 'inactive'))) ?: 'inactive';
        $terminal = in_array($status, ['disabled', 'expired', 'limited', 'blocked', 'deleted'], true);
        $hasUrl = trim((string)($c['subscription_url'] ?? '')) !== '';
        $unlimited = !$expiry && $hasUrl && !$terminal && $status === 'active';
        $withinExpiry = $unlimited || ($expiry && $expiry > time() - 120);
        $limitBytes = (int)($c['data_limit_bytes'] ?? 0);
        $usedBytes = (int)($c['used_traffic_bytes'] ?? 0);
        $withinTraffic = $limitBytes <= 0 || $usedBytes < $limitBytes;
        $syncError = trim((string)($c['last_sync_error'] ?? ''));
        $hasEntitlementRecord = !empty($c['plan_id']);
        // Provider/network uncertainty is fail-open for an otherwise valid paid
        // entitlement. This repairs accounts that older builds temporarily wrote
        // as inactive after a remote timeout without ever exposing an expired or
        // quota-exhausted subscription.
        $providerUncertain = $syncError !== '' && $hasEntitlementRecord;
        $active = (bool)((int)$c['active'] && $hasUrl && $withinExpiry && $withinTraffic && !$terminal && ($status === 'active' || $providerUncertain));
        $expireIso = $unlimited ? '2099-12-31T23:59:59Z' : ($expiry ? gmdate('Y-m-d\TH:i:s\Z', $expiry) : '');
        $remainingSeconds = $unlimited ? null : ($expiry ? max(0, $expiry - time()) : 0);
        $email = (string)($c['email'] ?? '');
        $phone = (string)($c['phone'] ?? '');
        $display = $phone !== '' ? BlueVPN_Utils::local_phone($phone) : $email;
        $entitlementOrderId = null;
        $entitlementPlanId = $c['plan_id'] !== null ? (int)$c['plan_id'] : null;
        $entitlementActive = (bool)($active && $entitlementPlanId);
        if ($active) {
            try {
                $order = $wpdb->get_row($wpdb->prepare(
                    "SELECT id,plan_id FROM ".BlueVPN_DB::table('orders')." WHERE customer_id=%d AND (status='activated' OR activated_at IS NOT NULL) ORDER BY activated_at DESC,created_at DESC LIMIT 1",
                    (int)$c['id']
                ), ARRAY_A);
                if ($order) {
                    $entitlementOrderId = (string)$order['id'];
                    $entitlementPlanId = $order['plan_id'] !== null ? (int)$order['plan_id'] : $entitlementPlanId;
                    $entitlementActive = true;
                }
            } catch (Throwable $e) {
                // Migrated/manual subscriptions may not have a historical order.
            }
        }

        // Stable ownership fingerprint: account reads can change usage/expiry
        // without forcing Android to re-import v2rayNG subscriptions. This value
        // changes only when the actual paid pool/provider ownership changes.
        $poolIdentity = hash('sha256', implode('|', [
            (string)($c['id'] ?? 0),
            (string)($entitlementPlanId ?? 0),
            trim((string)($c['subscription_url'] ?? '')),
            (string)($c['panel_id'] ?? 0),
            (string)($c['marzban_panel_id'] ?? 0),
            (string)($c['guardcore_panel_id'] ?? 0),
        ]));
        return [
            'id' => (int)$c['id'],
            'email' => $email,
            'phone' => $phone,
            'phone_display' => $phone !== '' ? BlueVPN_Utils::local_phone($phone) : '',
            'phone_verified' => (bool)($phone !== '' && !empty($c['phone_verified_at'])),
            'auth_method' => (string)($c['auth_method'] ?: 'legacy_email'),
            'display_identity' => $display,
            'active' => (bool)(int)$c['active'],
            'plan_id' => $c['plan_id'] !== null ? (int)$c['plan_id'] : null,
            'server_time' => BlueVPN_Utils::iso_now(),
            'server_time_fa' => BlueVPN_Utils::tehran_datetime_fa(),
            'calendar' => 'jalali',
            'timezone' => 'Asia/Tehran',
            'subscription' => [
                'active' => $active,
                'status' => $active ? 'active' : $status,
                'active_reason' => $active ? ($status === 'active' ? 'stored_status' : 'provider_fail_open') : 'inactive',
                'entitlement_active' => $entitlementActive,
                'entitlement_order_id' => $entitlementOrderId,
                'entitlement_plan_id' => $entitlementPlanId,
                'pool_identity' => $poolIdentity,
                'url' => (string)($c['subscription_url'] ?? ''),
                'expire' => $expireIso,
                'expires_at' => $expireIso,
                'expire_fa' => $unlimited ? 'نامحدود' : (!empty($c['subscription_expire']) ? BlueVPN_Utils::tehran_datetime_fa((string)$c['subscription_expire'], false) : ''),
                'expires_at_fa' => $unlimited ? 'نامحدود' : (!empty($c['subscription_expire']) ? BlueVPN_Utils::tehran_datetime_fa((string)$c['subscription_expire'], false) : ''),
                'remaining_seconds' => $remainingSeconds,
                'calendar' => 'jalali',
                'timezone' => 'Asia/Tehran',
                'expire_mode' => $unlimited ? 'unlimited' : ($expiry ? 'fixed' : 'none'),
                'unlimited' => $unlimited,
                'clock_skew_tolerance_seconds' => 120,
                'data_limit_bytes' => $limitBytes,
                'used_traffic_bytes' => $usedBytes,
                'remaining_bytes' => $limitBytes > 0 ? max(0, $limitBytes - $usedBytes) : 0,
                'device_limit' => max(1, (int)($c['device_limit'] ?? 1)),
                'last_sync_at' => BlueVPN_Utils::iso_from_mysql($c['last_sync_at'] ?? null),
                'last_sync_at_fa' => !empty($c['last_sync_at']) ? BlueVPN_Utils::tehran_datetime_fa((string)$c['last_sync_at']) : '',
                'sync_error' => (string)($c['last_sync_error'] ?? ''),
            ],
        ];
    }
}
