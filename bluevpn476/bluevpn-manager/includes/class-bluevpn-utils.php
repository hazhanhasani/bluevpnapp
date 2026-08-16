<?php
if (!defined('ABSPATH')) {
    exit;
}

final class BlueVPN_Utils {
    public static function now_mysql(): string {
        return gmdate('Y-m-d H:i:s');
    }

    public static function iso_now(): string {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    public static function iso_from_mysql(?string $value): string {
        if (!$value) {
            return '';
        }
        $ts = strtotime($value . ' UTC');
        return $ts ? gmdate('Y-m-d\TH:i:s\Z', $ts) : '';
    }

    public static function boolish($value): bool {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int)$value !== 0;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    public static function random_token(int $bytes = 48): string {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function random_uuid4(): string {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function base64url_encode_with_padding(string $raw): string {
        return strtr(base64_encode($raw), '+/', '-_');
    }

    public static function base64url_decode(string $value): string|false {
        $value = strtr($value, '-_', '+/');
        $pad = strlen($value) % 4;
        if ($pad) {
            $value .= str_repeat('=', 4 - $pad);
        }
        return base64_decode($value, true);
    }

    public static function json_decode_array(?string $value, array $fallback = []): array {
        if (!$value) {
            return $fallback;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : $fallback;
    }

    public static function json_encode($value): string {
        $encoded = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($encoded) ? $encoded : '{}';
    }

    public static function tehran_datetime_fa(?string $utcMysql = null, bool $withSeconds = true): string {
        try {
            $dt = $utcMysql
                ? new DateTime($utcMysql, new DateTimeZone('UTC'))
                : new DateTime('now', new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone('Asia/Tehran'));
            [$jy, $jm, $jd] = self::gregorian_to_jalali(
                (int)$dt->format('Y'),
                (int)$dt->format('n'),
                (int)$dt->format('j')
            );
            $time = $withSeconds ? $dt->format('H:i:s') : $dt->format('H:i');
            return sprintf('%04d/%02d/%02d، %s', $jy, $jm, $jd, $time);
        } catch (Throwable $e) {
            return '';
        }
    }

    public static function gregorian_to_jalali(int $gy, int $gm, int $gd): array {
        $gdm = [0,31,59,90,120,151,181,212,243,273,304,334];
        $gy2 = ($gm > 2) ? $gy + 1 : $gy;
        $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100)
            + intdiv($gy2 + 399, 400) + $gd + $gdm[$gm - 1];
        $jy = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        if ($days < 186) {
            $jm = 1 + intdiv($days, 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + intdiv($days - 186, 30);
            $jd = 1 + (($days - 186) % 30);
        }
        return [$jy, $jm, $jd];
    }

    public static function sanitize_phone(string $value): string {
        $value = strtr(trim($value), '۰۱۲۳۴۵۶۷۸۹', '0123456789');
        $digits = preg_replace('/\D+/', '', $value) ?: '';
        if (str_starts_with($digits, '0098')) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, '98') && strlen($digits) === 12) {
            return '+' . $digits;
        }
        if (str_starts_with($digits, '09') && strlen($digits) === 11) {
            return '+98' . substr($digits, 1);
        }
        return $value;
    }

    public static function local_phone(string $phone): string {
        if (str_starts_with($phone, '+98') && strlen($phone) === 13) {
            return '0' . substr($phone, 3);
        }
        return $phone;
    }

    public static function mysql_from_iso(?string $value): ?string {
        $value = trim((string)$value);
        if ($value === '') return null;
        try {
            $dt = new DateTimeImmutable($value);
            return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function encrypt_secret(string $plain): string {
        if ($plain === '') return '';
        if (!function_exists('openssl_encrypt')) {
            return 'plain64:' . base64_encode($plain);
        }
        $key = hash('sha256', wp_salt('auth') . '|' . wp_salt('secure_auth') . '|bluevpn-manager', true);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'bluevpn-manager-v1');
        if ($cipher === false) return '';
        return 'gcm1:' . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt_secret(string $encoded): string {
        if ($encoded === '') return '';
        if (str_starts_with($encoded, 'plain64:')) {
            $plain = base64_decode(substr($encoded, 8), true);
            return $plain === false ? '' : $plain;
        }
        if (!str_starts_with($encoded, 'gcm1:') || !function_exists('openssl_decrypt')) return '';
        $raw = base64_decode(substr($encoded, 5), true);
        if ($raw === false || strlen($raw) < 29) return '';
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $key = hash('sha256', wp_salt('auth') . '|' . wp_salt('secure_auth') . '|bluevpn-manager', true);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'bluevpn-manager-v1');
        return $plain === false ? '' : $plain;
    }

}
