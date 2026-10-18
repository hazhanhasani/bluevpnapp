<?php
declare(strict_types=1);

// Runs without credentials, database or WordPress.
$root = dirname(__DIR__);
$files = ['public/index.php', 'src/Application.php', 'src/Database.php', 'src/Admin.php', 'public/.htaccess'];
foreach ($files as $path) {
    if (!is_file($root . '/' . $path)) {
        fwrite(STDERR, "Missing: {$path}\n");
        exit(1);
    }
}
$app = file_get_contents($root . '/src/Application.php');
$db = file_get_contents($root . '/src/Database.php');
$assertions = [
    "no WordPress bootstrap" => !str_contains($app, "wp-load.php"),
    "compatibility health route" => str_contains($app, "/wp-json/bluevpn-system/v1/health"),
    "unported APIs fail closed" => str_contains($app, "ENDPOINT_NOT_PORTED"),
    "cutover not claimed" => str_contains($db, "'cutover_ready' => false"),
    "native PDO" => str_contains($db, "PDO::ATTR_EMULATE_PREPARES => false"),
    "legacy data preservation" => str_contains($db, "bluevpn_"),
];
foreach ($assertions as $name => $result) {
    if (!$result) {
        fwrite(STDERR, "FAILED: {$name}\n");
        exit(1);
    }
}
echo "Standalone static contract checks passed\n";
