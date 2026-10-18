<?php
declare(strict_types=1);

// Save as config.php OUTSIDE public/; never commit production secrets.
return [
    'app' => [
        'environment' => 'production',
        'base_url' => 'https://vpn.example.com',
        'enforce_https' => true,
        'installation_key' => 'REPLACE_WITH_64_RANDOM_HEX_CHARACTERS',
        'registration_enabled' => true,
    ],
    'database' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'cpanel_bluevpn',
        'username' => 'cpanel_bluevpn',
        'password' => 'REPLACE_WITH_A_LONG_PASSWORD',
        'prefix' => '',
    ],
];
