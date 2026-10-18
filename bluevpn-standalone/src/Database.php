<?php
declare(strict_types=1);

namespace BlueVPN\Standalone;

use PDO;
use RuntimeException;

final class Database
{
    private PDO $pdo;
    private string $prefix;

    public const LEGACY_TABLES = [
        'customers', 'plans', 'orders', 'customer_sessions', 'customer_devices',
        'otp_challenges', 'payment_events', 'payment_settings', 'sms_settings',
        'server_locations', 'pasarguard_panels', 'marzban_panels', 'hiddify_panels',
        'threexui_panels', 'guardcore_panels', 'gateway_nodes', 'subscription_sources',
        'free_configs', 'error_events', 'support_conversations',
    ];

    public function __construct(array $config)
    {
        foreach (['host', 'name', 'username', 'password', 'legacy_prefix'] as $field) {
            if (!array_key_exists($field, $config)) {
                throw new RuntimeException('Missing database configuration');
            }
        }
        $this->prefix = (string) $config['legacy_prefix'];
        if (!preg_match('/^[A-Za-z0-9_]{0,32}$/D', $this->prefix)) {
            throw new RuntimeException('Invalid table prefix');
        }
        $port = (int) ($config['port'] ?? 3306);
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('Invalid database port');
        }
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            (string) $config['host'], $port, (string) $config['name']);
        $this->pdo = new PDO($dsn, (string) $config['username'], (string) $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    }

    public function connection(): PDO
    {
        return $this->pdo;
    }

    public function table(string $logical): string
    {
        if (!in_array($logical, self::LEGACY_TABLES, true)) {
            throw new RuntimeException('Unrecognized legacy table');
        }
        return $this->prefix . 'bluevpn_' . $logical;
    }

    public function adminsTable(): string
    {
        return $this->prefix . 'bluevpn_standalone_admins';
    }

    public function exists(string $table): bool
    {
        $query = $this->pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
        $query->execute([$table]);
        return $query->fetchColumn() !== false;
    }

    public function installed(): bool
    {
        return $this->exists($this->adminsTable());
    }

    public function installAdmin(string $email, string $password): void
    {
        $table = $this->adminsTable();
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS `{$table}` (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        if ($count !== 0) {
            throw new RuntimeException('Administrator already installed');
        }
        $statement = $this->pdo->prepare("INSERT INTO `{$table}` (email,password_hash) VALUES (?,?)");
        $statement->execute([$email, password_hash($password, PASSWORD_DEFAULT)]);
    }

    public function snapshot(): array
    {
        $available = [];
        $missing = [];
        foreach (self::LEGACY_TABLES as $logical) {
            $actual = $this->table($logical);
            ($this->exists($actual) ? $available : $missing)[] = $logical;
        }
        $counts = [];
        foreach (['customers', 'plans', 'orders'] as $logical) {
            if (in_array($logical, $available, true)) {
                $table = $this->table($logical);
                $counts[$logical] = (int) $this->pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
            }
        }
        return [
            'found_tables' => $available,
            'missing_tables' => $missing,
            'sample_counts' => $counts,
            'legacy_coverage' => count($available) . '/' . count(self::LEGACY_TABLES),
            // Inventory does NOT imply feature parity or safe cutover.
            'cutover_ready' => false,
        ];
    }
}
