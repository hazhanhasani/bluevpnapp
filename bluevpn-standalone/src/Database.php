<?php
declare(strict_types=1);

namespace BlueVPN\Standalone;

use PDO;
use RuntimeException;

final class Database
{
    private PDO $pdo;
    private string $prefix;

    public const TABLES = [
        'standalone_admins','customers','customer_sessions','plans','subscriptions',
        'orders','payment_events','server_locations','providers','support_tickets',
        'settings','rate_limits','cron_locks','releases','audit_events'
    ];

    public function __construct(array $config)
    {
        foreach (['host','name','username','password'] as $field) {
            if (!isset($config[$field])) throw new RuntimeException('Incomplete database configuration');
        }
        $this->prefix = (string)($config['prefix'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_]{0,24}$/D', $this->prefix)) {
            throw new RuntimeException('Invalid database prefix');
        }
        $port = (int)($config['port'] ?? 3306);
        if ($port < 1 || $port > 65535) throw new RuntimeException('Invalid database port');
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['host'], $port, $config['name']);
        $this->pdo = new PDO($dsn, (string)$config['username'], (string)$config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $this->pdo->exec("SET time_zone = '+00:00'");
    }

    public function connection(): PDO { return $this->pdo; }

    public function table(string $name): string
    {
        if (!in_array($name, self::TABLES, true)) throw new RuntimeException('Unrecognized database table');
        return $this->prefix . 'bluevpn_' . $name;
    }

    public function adminsTable(): string { return $this->table('standalone_admins'); }

    public function exists(string $table): bool
    {
        $q = $this->pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1');
        $q->execute([$table]);
        return $q->fetchColumn() !== false;
    }

    public function installed(): bool
    {
        if (!$this->exists($this->adminsTable())) return false;
        return (int)$this->pdo->query('SELECT COUNT(*) FROM ' . $this->adminsTable())->fetchColumn() > 0;
    }

    /** Initial installation is intentionally empty: no WordPress users or tables. */
    public function installAdmin(string $email, string $password): void
    {
        $locked = (int)$this->pdo->query("SELECT GET_LOCK('bluevpn_standalone_setup', 5)")->fetchColumn();
        if ($locked !== 1) throw new RuntimeException('Installation locked');
        try {
            $this->installSchema();
            if ($this->installed()) throw new RuntimeException('Already installed');
            $q = $this->pdo->prepare('INSERT INTO '.$this->adminsTable().' (email,password_hash) VALUES (?,?)');
            $q->execute([strtolower(trim($email)),password_hash($password,PASSWORD_DEFAULT)]);
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('bluevpn_standalone_setup')");
        }
    }

    public function installSchema(): void
    {
        $t = fn(string $name): string => $this->table($name);
        $ddl = [
            "CREATE TABLE IF NOT EXISTS {$t('standalone_admins')} (
                id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                email VARCHAR(255) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS {$t('customers')} (
                id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                email VARCHAR(255) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                phone VARCHAR(24) NULL UNIQUE,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS {$t('customer_sessions')} (
                id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                customer_id BIGINT UNSIGNED NOT NULL,
                device_id VARCHAR(180) NOT NULL,
                device_name VARCHAR(180) NOT NULL DEFAULT '',
                token_hash CHAR(64) NOT NULL UNIQUE,
                refresh_hash CHAR(64) NOT NULL UNIQUE,
                expires_at DATETIME NOT NULL,
                refresh_expires_at DATETIME NOT NULL,
                revoked_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_customer_device (customer_id,device_id),
                INDEX idx_session_expiry (expires_at)
            )",
            "CREATE TABLE IF NOT EXISTS {$t('plans')} (
                id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                title VARCHAR(150) NOT NULL,
                description TEXT NULL,
                price_toman BIGINT UNSIGNED NOT NULL DEFAULT 0,
                duration_days INT UNSIGNED NOT NULL DEFAULT 30,
                data_limit_gb INT UNSIGNED NOT NULL DEFAULT 0,
                device_limit INT UNSIGNED NOT NULL DEFAULT 1,
                active TINYINT(1) NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS {$t('subscriptions')} (
                id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                customer_id BIGINT UNSIGNED NOT NULL UNIQUE,
                plan_id BIGINT UNSIGNED NULL,
                status VARCHAR(24) NOT NULL DEFAULT 'inactive',
                subscription_url TEXT NULL,
                data_limit_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
                used_traffic_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
                expires_at DATETIME NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS {$t('orders')} (
                id CHAR(36) PRIMARY KEY,
                customer_id BIGINT UNSIGNED NOT NULL,
                plan_id BIGINT UNSIGNED NOT NULL,
                price_toman BIGINT UNSIGNED NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'pending',
                payment_reference VARCHAR(128) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                paid_at DATETIME NULL,
                UNIQUE KEY uq_payment_reference (payment_reference),
                INDEX idx_customer_orders (customer_id,created_at)
            )",
            "CREATE TABLE IF NOT EXISTS {$t('payment_events')} (
                id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                order_id CHAR(36) NOT NULL,
                provider VARCHAR(32) NOT NULL,
                event_key VARCHAR(190) NOT NULL,
                payload_sha256 CHAR(64) NOT NULL,
                received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_provider_event (provider,event_key)
            )",
            "CREATE TABLE IF NOT EXISTS {$t('server_locations')} (
                id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                country_code CHAR(2) NOT NULL,
                country_name VARCHAR(120) NOT NULL,
                display_name VARCHAR(150) NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS {$t('providers')} (
                id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                provider_type VARCHAR(60) NOT NULL,
                display_name VARCHAR(120) NOT NULL,
                base_url VARCHAR(2048) NOT NULL DEFAULT '',
                enabled TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS {$t('support_tickets')} (
                id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                customer_id BIGINT UNSIGNED NOT NULL,
                subject VARCHAR(200) NOT NULL,
                message TEXT NOT NULL,
                status VARCHAR(24) NOT NULL DEFAULT 'open',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_customer_tickets (customer_id,created_at)
            )",
            "CREATE TABLE IF NOT EXISTS {$t('settings')} (
                setting_key VARCHAR(100) PRIMARY KEY,
                setting_value TEXT NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )",
            "CREATE TABLE IF NOT EXISTS {$t('rate_limits')} (
                limiter_key CHAR(64) PRIMARY KEY,
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                reset_at DATETIME NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS {$t('cron_locks')} (
                job_name VARCHAR(90) PRIMARY KEY,
                last_started_at DATETIME NULL,
                last_completed_at DATETIME NULL
            )",
            "CREATE TABLE IF NOT EXISTS {$t('releases')} (
                id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                platform VARCHAR(32) NOT NULL,
                version VARCHAR(32) NOT NULL,
                version_code INT UNSIGNED NOT NULL DEFAULT 0,
                channel VARCHAR(16) NOT NULL DEFAULT 'stable',
                file_url TEXT NULL,
                sha256 CHAR(64) NULL,
                active TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_platform_active (platform,active,channel)
            )",
            "CREATE TABLE IF NOT EXISTS {$t('audit_events')} (
                id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                actor_type VARCHAR(30) NOT NULL,
                actor_id BIGINT UNSIGNED NULL,
                action VARCHAR(100) NOT NULL,
                context_json TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_audit_created (created_at)
            )",
        ];
        foreach ($ddl as $sql) {
            $this->pdo->exec($sql.' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        }
    }

    public function setting(string $key, string $default = ''): string
    {
        $q=$this->pdo->prepare('SELECT setting_value FROM '.$this->table('settings').' WHERE setting_key=?');
        $q->execute([$key]);
        $value=$q->fetchColumn();
        return $value===false ? $default : (string)$value;
    }

    public function setSetting(string $key, string $value): void
    {
        $q=$this->pdo->prepare('INSERT INTO '.$this->table('settings').' (setting_key,setting_value) VALUES (?,?)
            ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
        $q->execute([$key,$value]);
    }

    public function count(string $name): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM '.$this->table($name))->fetchColumn();
    }

    public function snapshot(): array
    {
        $available=[]; $missing=[]; $counts=[];
        foreach (self::TABLES as $logical) {
            if ($this->exists($this->table($logical))) {
                $available[]=$logical;
                if (in_array($logical,['customers','plans','orders','subscriptions','server_locations'],true)) {
                    $counts[$logical]=$this->count($logical);
                }
            } else $missing[]=$logical;
        }
        return [
            'found_tables'=>$available,'missing_tables'=>$missing,
            'sample_counts'=>$counts,'schema_coverage'=>count($available).'/'.count(self::TABLES),
            'wordpress_required'=>false,'fresh_install'=>true,
        ];
    }
}
