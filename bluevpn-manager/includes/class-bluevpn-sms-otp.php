<?php
if (!defined('ABSPATH')) exit;

final class BlueVPN_SMS_OTP {
    private const OTP_LENGTH = 6;
    private const PURPOSE_AUTH = 'auth';
    private const PURPOSE_BIND = 'bind_phone';
    private const MAX_ATTEMPTS = 5;

    public static function settings(): array {
        global $wpdb;
        $table = BlueVPN_DB::table('sms_settings');
        $row = $wpdb->get_row("SELECT * FROM {$table} WHERE id=1", ARRAY_A);
        if (is_array($row) && (int)($row['otp_length'] ?? 0) !== self::OTP_LENGTH) {
            $wpdb->update($table, ['otp_length' => self::OTP_LENGTH, 'updated_at' => BlueVPN_Utils::now_mysql()], ['id' => 1]);
            $row['otp_length'] = self::OTP_LENGTH;
        }
        return is_array($row) ? $row : [];
    }

    public static function is_ready(): bool {
        $s = self::settings();
        if (!$s || empty($s['active'])) return false;
        if (strtolower(trim((string)($s['provider'] ?? ''))) !== 'iranpayamak') return false;
        if (BlueVPN_Utils::decrypt_secret((string)($s['api_key_enc'] ?? '')) === '') return false;
        if (trim((string)($s['pattern_code'] ?? '')) === '') return false;
        if (trim((string)($s['from_number'] ?? '')) === '') return false;
        return true;
    }

    public static function public_config(): array {
        $s = self::settings();
        return [
            'provider' => 'iranpayamak',
            'ready' => self::is_ready(),
            'otp_length' => self::OTP_LENGTH,
            'otp_ttl_seconds' => max(60, min(600, (int)($s['otp_ttl_seconds'] ?? 120))),
            'resend_seconds' => max(30, min(600, (int)($s['resend_seconds'] ?? 60))),
        ];
    }

    private static function normalize_phone(string $raw): string {
        $phone = BlueVPN_Utils::sanitize_phone($raw);
        if (!preg_match('/^\+989\d{9}$/', $phone)) {
            throw new BlueVPN_Auth_Exception(422, 'PHONE_INVALID', 'شماره موبایل معتبر نیست.');
        }
        return $phone;
    }

    private static function client_ip(): string {
        $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        return preg_match('/^[0-9a-f:.]+$/i', $ip) ? $ip : 'unknown';
    }

    private static function rate_limit(string $phone): void {
        $window = 10 * MINUTE_IN_SECONDS;
        $phoneKey = 'bluevpn_otp_phone_' . substr(hash('sha256', $phone), 0, 24);
        $ipKey = 'bluevpn_otp_ip_' . substr(hash('sha256', self::client_ip()), 0, 24);
        foreach ([[$phoneKey, 5], [$ipKey, 30]] as [$key, $limit]) {
            $count = (int)get_transient($key);
            if ($count >= $limit) {
                throw new BlueVPN_Auth_Exception(429, 'OTP_RATE_LIMITED', 'تعداد درخواست کد زیاد است؛ چند دقیقه دیگر دوباره تلاش کنید.', ['retry_after_seconds' => 600]);
            }
            set_transient($key, $count + 1, $window);
        }
    }

    private static function otp_hash(string $challengeId, string $phone, string $code): string {
        $secret = hash('sha256', wp_salt('auth') . '|' . wp_salt('secure_auth') . '|bluevpn-otp-v1', true);
        return 'otp_hmac_sha256$' . hash_hmac('sha256', $challengeId . ':' . $phone . ':' . $code, $secret);
    }

    private static function generate_code(): string {
        return (string)random_int(100000, 999999);
    }

    private static function clean_code(string $raw): string {
        $raw = strtr(trim($raw), '۰۱۲۳۴۵۶۷۸۹', '0123456789');
        $digits = preg_replace('/\D+/', '', $raw) ?: '';
        return substr($digits, 0, self::OTP_LENGTH);
    }

    private static function log_delivery(string $challengeId, string $phone, string $status, string $error = '', array $response = [], string $purpose = self::PURPOSE_AUTH, ?int $customerId = null): void {
        global $wpdb;
        $table = BlueVPN_DB::table('sms_deliveries');
        $wpdb->replace($table, [
            'id' => BlueVPN_Utils::random_uuid4(),
            'event_key' => $purpose === self::PURPOSE_BIND ? 'bind_phone_otp' : 'auth_otp',
            'customer_id' => $customerId,
            'order_id' => null,
            'phone' => $phone,
            'params_json' => BlueVPN_Utils::json_encode(['purpose' => $purpose, 'challenge_id' => $challengeId]),
            'dedupe_key' => $purpose . ':' . $challengeId,
            'status' => $status,
            'attempts' => 1,
            'max_attempts' => 1,
            'provider_message_id' => self::provider_message_id($response),
            'response_json' => $response ? BlueVPN_Utils::json_encode($response) : '',
            'last_error' => mb_substr($error, 0, 1000),
            'next_attempt_at' => null,
            'sent_at' => $status === 'sent' ? BlueVPN_Utils::now_mysql() : null,
            'created_at' => BlueVPN_Utils::now_mysql(),
        ]);
    }

    private static function provider_message_id(array $response): string {
        foreach (['message_id', 'messageId', 'id', 'uid'] as $key) {
            if (isset($response[$key]) && is_scalar($response[$key])) return mb_substr((string)$response[$key], 0, 180);
        }
        if (isset($response['data']) && is_array($response['data'])) {
            foreach (['message_id', 'messageId', 'id', 'uid'] as $key) {
                if (isset($response['data'][$key]) && is_scalar($response['data'][$key])) return mb_substr((string)$response['data'][$key], 0, 180);
            }
        }
        return '';
    }

    private static function provider_error_message(array $payload, string $fallback): string {
        if (isset($payload['meta']) && is_array($payload['meta']) && !empty($payload['meta']['message'])) return mb_substr(wp_strip_all_tags((string)$payload['meta']['message']), 0, 300);
        foreach (['message', 'error', 'messages'] as $key) {
            if (!empty($payload[$key]) && is_scalar($payload[$key])) return mb_substr(wp_strip_all_tags((string)$payload[$key]), 0, 300);
        }
        return $fallback;
    }

    private static function send_code(string $phone, string $code): array {
        $s = self::settings();
        if (!self::is_ready()) {
            throw new BlueVPN_Auth_Exception(503, 'SMS_NOT_CONFIGURED', 'سامانه ایران‌پیامک هنوز در پنل مدیریت تنظیم یا فعال نشده است.');
        }
        $apiKey = BlueVPN_Utils::decrypt_secret((string)$s['api_key_enc']);
        $base = untrailingslashit((string)($s['base_url'] ?: 'https://api.iranpayamak.com/ws/v1'));
        if ($base === '' || stripos($base, 'edge.ippanel.com') !== false) $base = 'https://api.iranpayamak.com/ws/v1';
        $line = preg_replace('/\s+/', '', strtr((string)$s['from_number'], '۰۱۲۳۴۵۶۷۸۹', '0123456789')) ?: '';
        if (!preg_match('/^[+0-9A-Za-z_-]{3,32}$/', $line)) {
            throw new BlueVPN_Auth_Exception(503, 'SMS_LINE_REQUIRED', 'شماره خط ارسال ایران‌پیامک معتبر نیست.');
        }
        $param = sanitize_key((string)($s['parameter_name'] ?: 'code')) ?: 'code';
        $payload = [
            'code' => trim((string)$s['pattern_code']),
            'attributes' => [$param => $code],
            'recipient' => BlueVPN_Utils::local_phone($phone),
            'number_format' => 'english',
            'line_number' => $line,
        ];
        $res = wp_remote_post($base . '/sms/pattern', [
            'timeout' => 15,
            'redirection' => 2,
            'sslverify' => !isset($s['verify_tls']) || (bool)$s['verify_tls'],
            'headers' => [
                'Api-Key' => $apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => 'BlueVPN-WordPress-SMS/' . BLUEVPN_MANAGER_VERSION,
            ],
            'body' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        if (is_wp_error($res)) {
            throw new BlueVPN_Auth_Exception(503, 'SMS_PROVIDER_TEMPORARY_UNAVAILABLE', 'ارتباط با ایران‌پیامک موقتاً برقرار نشد؛ دوباره تلاش کنید.', ['retryable' => true]);
        }
        $status = (int)wp_remote_retrieve_response_code($res);
        $body = (string)wp_remote_retrieve_body($res);
        $decoded = json_decode($body, true);
        $data = is_array($decoded) ? $decoded : [];
        if ($status < 200 || $status >= 300) {
            $fallback = in_array($status, [401,403], true)
                ? 'کلید API ایران‌پیامک معتبر نیست یا مجوز ارسال پترن ندارد.'
                : 'ارسال کد ورود توسط ایران‌پیامک انجام نشد.';
            throw new BlueVPN_Auth_Exception(in_array($status,[429,500,502,503,504],true) ? 503 : 502, 'SMS_SEND_FAILED', self::provider_error_message($data, $fallback), ['provider_status' => $status]);
        }
        if ((isset($data['success']) && $data['success'] === false) || (isset($data['status']) && $data['status'] === false) || (isset($data['meta']['status']) && $data['meta']['status'] === false)) {
            throw new BlueVPN_Auth_Exception(502, 'SMS_SEND_FAILED', self::provider_error_message($data, 'ایران‌پیامک ارسال کد را رد کرد.'));
        }
        return $data ?: ['success' => true, 'status_code' => $status];
    }

    public static function request(string $phoneRaw, string $deviceId): array {
        global $wpdb;
        $phone = self::normalize_phone($phoneRaw);
        $deviceId = mb_substr(trim($deviceId), 0, 180);
        if ($deviceId === '') throw new BlueVPN_Auth_Exception(422, 'DEVICE_ID_REQUIRED', 'شناسه دستگاه لازم است.');
        self::rate_limit($phone);
        $s = self::settings();
        if (!self::is_ready()) throw new BlueVPN_Auth_Exception(503, 'SMS_NOT_CONFIGURED', 'سامانه ایران‌پیامک هنوز در پنل مدیریت تنظیم یا فعال نشده است.');

        $table = BlueVPN_DB::table('otp_challenges');
        $resend = max(30, min(600, (int)($s['resend_seconds'] ?? 60)));
        $latest = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE phone=%s AND purpose=%s AND consumed_at IS NULL ORDER BY created_at DESC LIMIT 1",
            $phone, self::PURPOSE_AUTH
        ), ARRAY_A);
        if ($latest && !empty($latest['created_at'])) {
            $age = max(0, time() - (int)strtotime($latest['created_at'] . ' UTC'));
            if ($age < $resend) {
                $wait = $resend - $age;
                throw new BlueVPN_Auth_Exception(429, 'OTP_RESEND_WAIT', "{$wait} ثانیه تا ارسال دوباره کد صبر کنید.", ['retry_after_seconds' => $wait]);
            }
        }
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET consumed_at=%s WHERE phone=%s AND purpose=%s AND consumed_at IS NULL",
            BlueVPN_Utils::now_mysql(), $phone, self::PURPOSE_AUTH
        ));
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE expires_at < %s", gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS)));

        $challengeId = BlueVPN_Utils::random_uuid4();
        $code = self::generate_code();
        $ttl = max(60, min(600, (int)($s['otp_ttl_seconds'] ?? 120)));
        $inserted = $wpdb->insert($table, [
            'id' => $challengeId,
            'phone' => $phone,
            'purpose' => self::PURPOSE_AUTH,
            'customer_id' => null,
            'device_id' => $deviceId,
            'code_hash' => self::otp_hash($challengeId, $phone, $code),
            'attempts' => 0,
            'max_attempts' => self::MAX_ATTEMPTS,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttl),
            'consumed_at' => null,
            'created_at' => BlueVPN_Utils::now_mysql(),
        ]);
        if ($inserted === false) throw new BlueVPN_Auth_Exception(500, 'OTP_CREATE_FAILED', 'ساخت درخواست کد ورود انجام نشد.');

        try {
            $provider = self::send_code($phone, $code);
            self::log_delivery($challengeId, $phone, 'sent', '', $provider);
        } catch (BlueVPN_Auth_Exception $e) {
            $wpdb->delete($table, ['id' => $challengeId]);
            self::log_delivery($challengeId, $phone, 'failed', $e->getMessage(), ['code' => $e->error_code]);
            throw $e;
        }
        return [
            'success' => true,
            'challenge_id' => $challengeId,
            'phone' => BlueVPN_Utils::local_phone($phone),
            'otp_length' => self::OTP_LENGTH,
            'expires_in_seconds' => $ttl,
            'resend_after_seconds' => $resend,
            'message' => 'کد تأیید ۶ رقمی برای شماره شما ارسال شد.',
        ];
    }

    public static function verify(string $phoneRaw, string $challengeId, string $codeRaw, string $deviceId, string $deviceName = ''): array {
        global $wpdb;
        $phone = self::normalize_phone($phoneRaw);
        $challengeId = trim($challengeId);
        $deviceId = mb_substr(trim($deviceId), 0, 180);
        if ($deviceId === '') throw new BlueVPN_Auth_Exception(422, 'DEVICE_ID_REQUIRED', 'شناسه دستگاه لازم است.');
        $code = self::clean_code($codeRaw);
        if (strlen($code) !== self::OTP_LENGTH) throw new BlueVPN_Auth_Exception(422, 'OTP_INVALID_FORMAT', 'کد ورود باید ۶ رقمی باشد.');
        $table = BlueVPN_DB::table('otp_challenges');
        $challenge = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id=%s AND phone=%s AND purpose=%s LIMIT 1",
            $challengeId, $phone, self::PURPOSE_AUTH
        ), ARRAY_A);
        if (!$challenge) throw new BlueVPN_Auth_Exception(404, 'OTP_NOT_FOUND', 'درخواست کد تأیید پیدا نشد.');
        if (!hash_equals((string)$challenge['device_id'], $deviceId)) throw new BlueVPN_Auth_Exception(401, 'OTP_DEVICE_MISMATCH', 'کد باید روی همان دستگاه درخواست‌کننده تأیید شود.');
        if (!empty($challenge['consumed_at'])) throw new BlueVPN_Auth_Exception(410, 'OTP_ALREADY_USED', 'این کد قبلاً استفاده شده است.');
        if (empty($challenge['expires_at']) || strtotime($challenge['expires_at'] . ' UTC') <= time()) {
            $wpdb->update($table, ['consumed_at' => BlueVPN_Utils::now_mysql()], ['id' => $challengeId]);
            throw new BlueVPN_Auth_Exception(410, 'OTP_EXPIRED', 'مهلت کد تأیید پایان یافته است؛ کد جدید بگیرید.');
        }
        $attempts = (int)$challenge['attempts'];
        $maxAttempts = max(1, (int)$challenge['max_attempts']);
        if ($attempts >= $maxAttempts) {
            $wpdb->update($table, ['consumed_at' => BlueVPN_Utils::now_mysql()], ['id' => $challengeId]);
            throw new BlueVPN_Auth_Exception(429, 'OTP_LOCKED', 'تعداد تلاش‌های ناموفق زیاد بود؛ کد جدید بگیرید.');
        }
        $attempts++;
        $expected = self::otp_hash($challengeId, $phone, $code);
        if (!hash_equals((string)$challenge['code_hash'], $expected)) {
            $update = ['attempts' => $attempts];
            if ($attempts >= $maxAttempts) $update['consumed_at'] = BlueVPN_Utils::now_mysql();
            $wpdb->update($table, $update, ['id' => $challengeId]);
            if ($attempts >= $maxAttempts && class_exists('BlueVPN_SMS_Notifications')) {
                try { $owner=$wpdb->get_row($wpdb->prepare('SELECT id,phone FROM '.BlueVPN_DB::table('customers').' WHERE phone=%s LIMIT 1',$phone),ARRAY_A);if($owner)BlueVPN_SMS_Notifications::queue('suspicious_login',$phone,[],(int)$owner['id'],null,'otp-lock:'.$challengeId); } catch(Throwable $e) { error_log('BlueVPN suspicious login SMS: '.$e->getMessage()); }
            }
            throw new BlueVPN_Auth_Exception(401, 'INVALID_OTP', 'کد تأیید نادرست است.', ['remaining_attempts' => max(0, $maxAttempts - $attempts)]);
        }
        $wpdb->update($table, ['attempts' => $attempts, 'consumed_at' => BlueVPN_Utils::now_mysql()], ['id' => $challengeId]);

        $customers = BlueVPN_DB::table('customers');
        $customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$customers} WHERE phone=%s LIMIT 1", $phone), ARRAY_A);
        $isNew = false;
        if (!$customer) {
            $isNew = true;
            $subscriptionToken = BlueVPN_Utils::random_token(32);
            $ok = $wpdb->insert($customers, [
                'email' => null,
                'password_hash' => 'phone_otp_only$' . BlueVPN_Utils::random_token(32),
                'phone' => $phone,
                'phone_verified_at' => BlueVPN_Utils::now_mysql(),
                'auth_method' => 'phone_otp',
                'active' => 1,
                'subscription_token' => $subscriptionToken,
                'subscription_url' => '',
                'subscription_status' => 'inactive',
                'data_limit_bytes' => 0,
                'used_traffic_bytes' => 0,
                'device_limit' => 1,
                'last_sync_error' => '',
                'created_at' => BlueVPN_Utils::now_mysql(),
            ]);
            if ($ok === false) throw new BlueVPN_Auth_Exception(500, 'ACCOUNT_CREATE_FAILED', 'ساخت حساب انجام نشد.');
            $customer = BlueVPN_Auth::get_customer((int)$wpdb->insert_id);
        } else {
            if (!(int)$customer['active']) throw new BlueVPN_Auth_Exception(401, 'ACCOUNT_DISABLED', 'این حساب غیرفعال شده است.');
            $wpdb->update($customers, [
                'phone_verified_at' => !empty($customer['phone_verified_at']) ? $customer['phone_verified_at'] : BlueVPN_Utils::now_mysql(),
                'auth_method' => 'phone_otp',
            ], ['id' => (int)$customer['id']]);
            $customer = BlueVPN_Auth::get_customer((int)$customer['id']);
        }
        $tokens = BlueVPN_Auth::issue_session($customer, $deviceId, $deviceName);
        if ($isNew && !empty($customer['phone']) && class_exists('BlueVPN_SMS_Notifications')) {
            BlueVPN_SMS_Notifications::queue('welcome', (string)$customer['phone'], ['name'=>BlueVPN_Utils::local_phone((string)$customer['phone'])], (int)$customer['id'], null, 'welcome:'.(int)$customer['id']);
        }
        return [
            'success' => true,
            'is_new_account' => $isNew,
            'token' => $tokens['token'],
            'refresh_token' => $tokens['refresh_token'],
            'account' => BlueVPN_Auth::account_payload($customer),
        ];
    }

    public static function request_bind(array $customer, string $phoneRaw, string $deviceId): array {
        global $wpdb;
        $phone = self::normalize_phone($phoneRaw);
        $customerId = (int)($customer['id'] ?? 0);
        if ($customerId <= 0) throw new BlueVPN_Auth_Exception(401, 'AUTH_REQUIRED', 'ورود لازم است.');
        $deviceId = mb_substr(trim($deviceId), 0, 180);
        if ($deviceId === '') throw new BlueVPN_Auth_Exception(422, 'DEVICE_ID_REQUIRED', 'شناسه دستگاه لازم است.');
        $customers = BlueVPN_DB::table('customers');
        $owner = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$customers} WHERE phone=%s AND id<>%d LIMIT 1", $phone, $customerId));
        if ($owner) throw new BlueVPN_Auth_Exception(409, 'PHONE_ALREADY_USED', 'این شماره قبلاً به حساب دیگری متصل شده است.');
        self::rate_limit($phone);
        $s = self::settings();
        if (!self::is_ready()) throw new BlueVPN_Auth_Exception(503, 'SMS_NOT_CONFIGURED', 'سامانه ایران‌پیامک هنوز در پنل مدیریت تنظیم یا فعال نشده است.');
        $table = BlueVPN_DB::table('otp_challenges');
        $resend = max(30, min(600, (int)($s['resend_seconds'] ?? 60)));
        $latest = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE phone=%s AND purpose=%s AND customer_id=%d AND consumed_at IS NULL ORDER BY created_at DESC LIMIT 1", $phone, self::PURPOSE_BIND, $customerId), ARRAY_A);
        if ($latest && !empty($latest['created_at'])) {
            $age = max(0, time() - (int)strtotime($latest['created_at'] . ' UTC'));
            if ($age < $resend) {
                $wait = $resend - $age;
                throw new BlueVPN_Auth_Exception(429, 'OTP_RESEND_WAIT', "{$wait} ثانیه تا ارسال دوباره کد صبر کنید.", ['retry_after_seconds' => $wait]);
            }
        }
        $wpdb->query($wpdb->prepare("UPDATE {$table} SET consumed_at=%s WHERE phone=%s AND purpose=%s AND customer_id=%d AND consumed_at IS NULL", BlueVPN_Utils::now_mysql(), $phone, self::PURPOSE_BIND, $customerId));
        $challengeId = BlueVPN_Utils::random_uuid4();
        $code = self::generate_code();
        $ttl = max(60, min(600, (int)($s['otp_ttl_seconds'] ?? 120)));
        $ok = $wpdb->insert($table, [
            'id'=>$challengeId, 'phone'=>$phone, 'purpose'=>self::PURPOSE_BIND, 'customer_id'=>$customerId,
            'device_id'=>$deviceId, 'code_hash'=>self::otp_hash($challengeId,$phone,$code), 'attempts'=>0,
            'max_attempts'=>self::MAX_ATTEMPTS, 'expires_at'=>gmdate('Y-m-d H:i:s', time()+$ttl),
            'consumed_at'=>null, 'created_at'=>BlueVPN_Utils::now_mysql(),
        ]);
        if ($ok === false) throw new BlueVPN_Auth_Exception(500, 'OTP_CREATE_FAILED', 'ساخت درخواست کد تأیید انجام نشد.');
        try {
            $provider = self::send_code($phone, $code);
            self::log_delivery($challengeId, $phone, 'sent', '', $provider, self::PURPOSE_BIND, $customerId);
        } catch (BlueVPN_Auth_Exception $e) {
            $wpdb->delete($table, ['id'=>$challengeId]);
            self::log_delivery($challengeId, $phone, 'failed', $e->getMessage(), ['code'=>$e->error_code], self::PURPOSE_BIND, $customerId);
            throw $e;
        }
        return ['success'=>true,'challenge_id'=>$challengeId,'phone'=>BlueVPN_Utils::local_phone($phone),'otp_length'=>self::OTP_LENGTH,'expires_in_seconds'=>$ttl,'resend_after_seconds'=>$resend,'message'=>'کد تأیید ۶ رقمی برای شماره شما ارسال شد.'];
    }

    public static function verify_bind(array $customer, string $phoneRaw, string $challengeId, string $codeRaw, string $deviceId): array {
        global $wpdb;
        $phone=self::normalize_phone($phoneRaw); $customerId=(int)($customer['id']??0); $challengeId=trim($challengeId); $deviceId=mb_substr(trim($deviceId),0,180);
        if($customerId<=0)throw new BlueVPN_Auth_Exception(401,'AUTH_REQUIRED','ورود لازم است.');
        if($deviceId==='')throw new BlueVPN_Auth_Exception(422,'DEVICE_ID_REQUIRED','شناسه دستگاه لازم است.');
        $code=self::clean_code($codeRaw);if(strlen($code)!==self::OTP_LENGTH)throw new BlueVPN_Auth_Exception(422,'OTP_INVALID_FORMAT','کد ورود باید ۶ رقمی باشد.');
        $table=BlueVPN_DB::table('otp_challenges');
        $ch=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%s AND phone=%s AND purpose=%s AND customer_id=%d LIMIT 1",$challengeId,$phone,self::PURPOSE_BIND,$customerId),ARRAY_A);
        if(!$ch)throw new BlueVPN_Auth_Exception(404,'OTP_NOT_FOUND','درخواست کد تأیید پیدا نشد.');
        if(!hash_equals((string)$ch['device_id'],$deviceId))throw new BlueVPN_Auth_Exception(401,'OTP_DEVICE_MISMATCH','کد باید روی همان دستگاه درخواست‌کننده تأیید شود.');
        if(!empty($ch['consumed_at']))throw new BlueVPN_Auth_Exception(410,'OTP_ALREADY_USED','این کد قبلاً استفاده شده است.');
        if(empty($ch['expires_at'])||strtotime($ch['expires_at'].' UTC')<=time()){$wpdb->update($table,['consumed_at'=>BlueVPN_Utils::now_mysql()],['id'=>$challengeId]);throw new BlueVPN_Auth_Exception(410,'OTP_EXPIRED','مهلت کد تأیید پایان یافته است؛ کد جدید بگیرید.');}
        $attempts=(int)$ch['attempts'];$max=max(1,(int)$ch['max_attempts']);if($attempts>=$max){$wpdb->update($table,['consumed_at'=>BlueVPN_Utils::now_mysql()],['id'=>$challengeId]);throw new BlueVPN_Auth_Exception(429,'OTP_LOCKED','تعداد تلاش‌های ناموفق زیاد بود؛ کد جدید بگیرید.');}
        $attempts++;if(!hash_equals((string)$ch['code_hash'],self::otp_hash($challengeId,$phone,$code))){$up=['attempts'=>$attempts];if($attempts>=$max)$up['consumed_at']=BlueVPN_Utils::now_mysql();$wpdb->update($table,$up,['id'=>$challengeId]);throw new BlueVPN_Auth_Exception(401,'INVALID_OTP','کد تأیید نادرست است.',['remaining_attempts'=>max(0,$max-$attempts)]);}
        $customers=BlueVPN_DB::table('customers');$owner=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$customers} WHERE phone=%s AND id<>%d LIMIT 1",$phone,$customerId));if($owner)throw new BlueVPN_Auth_Exception(409,'PHONE_ALREADY_USED','این شماره قبلاً به حساب دیگری متصل شده است.');
        $wpdb->update($table,['attempts'=>$attempts,'consumed_at'=>BlueVPN_Utils::now_mysql()],['id'=>$challengeId]);
        $wpdb->update($customers,['phone'=>$phone,'phone_verified_at'=>BlueVPN_Utils::now_mysql(),'auth_method'=>'phone_otp'],['id'=>$customerId]);
        if (class_exists('BlueVPN_SMS_Notifications')) BlueVPN_SMS_Notifications::queue('phone_changed',$phone,[],$customerId,null,'phone-changed:'.$customerId.':'.$phone);
        return ['success'=>true,'account'=>BlueVPN_Auth::account_payload(BlueVPN_Auth::get_customer($customerId))];
    }

}
