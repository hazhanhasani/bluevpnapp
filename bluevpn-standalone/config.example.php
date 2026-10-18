<?php
declare(strict_types=1);

// Copy to config.php. Keep this file OUTSIDE your public web document root.
return [
    'app' => [
        'environment' => 'staging',
        'base_url' => 'https://staging.example.com',
        'enforce_https' => true,
        // Generate with: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
        'installation_key' => 'REPLACE_WITH_A_RANDOM_64_CHARACTER_HEX_KEY',
    ],
    'database' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'cpanel_bluevpn',
        'username' => 'cpanel_bluevpn',
        'password' => 'REPLACE_ME',
        // Imported WordPress BlueVPN tables retain their original prefix.
        // Example: wp_bluevpn_customers. No WordPress runtime is needed.
        'legacy_prefix' => 'wp_',
    ],
];
