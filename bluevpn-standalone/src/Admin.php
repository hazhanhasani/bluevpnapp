<?php
declare(strict_types=1);

namespace BlueVPN\Standalone;

use RuntimeException;

final class Admin
{
    public function __construct(private Database $db) {}

    public static function startSession(bool $https): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name('bluevpn_admin');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(24));
        }
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="csrf" value="' .
            htmlspecialchars((string) ($_SESSION['csrf'] ?? ''), ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function requireCsrf(): void
    {
        $received = (string) ($_POST['csrf'] ?? '');
        if ($received === '' || !hash_equals((string) ($_SESSION['csrf'] ?? ''), $received)) {
            throw new RuntimeException('Invalid form token', 403);
        }
    }

    public static function authorized(): bool
    {
        return !empty($_SESSION['admin_id']) && is_int($_SESSION['admin_id']);
    }

    public function authenticate(string $email, string $password): bool
    {
        // Per-session throttle is a secondary guard; configure server/WAF IP rate limiting too.
        $until = (int) ($_SESSION['login_block_until'] ?? 0);
        if (time() < $until) {
            return false;
        }
        $query = $this->db->connection()->prepare(
            'SELECT id,password_hash FROM `' . $this->db->adminsTable() . '` WHERE email=? LIMIT 1'
        );
        $query->execute([strtolower(trim($email))]);
        $row = $query->fetch();
        if (!is_array($row) || !password_verify($password, (string) $row['password_hash'])) {
            $attempts = (int) ($_SESSION['login_attempts'] ?? 0) + 1;
            $_SESSION['login_attempts'] = $attempts;
            if ($attempts >= 5) {
                $_SESSION['login_block_until'] = time() + 900;
            }
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
        $_SESSION['admin_id'] = (int) $row['id'];
        unset($_SESSION['login_attempts'], $_SESSION['login_block_until']);
        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $cookie = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 3600,
                'path' => $cookie['path'],
                'secure' => $cookie['secure'],
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
        }
        session_destroy();
    }
}
