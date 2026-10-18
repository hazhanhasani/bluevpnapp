<?php
declare(strict_types=1);

use BlueVPN\Standalone\Application;

$root = dirname(__DIR__);
$configFile = $root . '/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'BlueVPN standalone is not configured. See README.md.';
    exit;
}
$config = require $configFile;
if (!is_array($config) || !is_array($config['app'] ?? null) || !is_array($config['database'] ?? null)) {
    http_response_code(503);
    exit('Invalid application configuration.');
}
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'BlueVPN\\Standalone\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $name = substr($class, strlen($prefix));
    if (preg_match('/^[A-Za-z]+$/D', $name)) {
        $path = $root . '/src/' . $name . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});
try {
    (new Application($config))->run();
} catch (Throwable $exception) {
    error_log('BlueVPN standalone startup error [' . get_class($exception) . ']');
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo '{"detail":{"code":"SERVICE_UNAVAILABLE","message":"Service is unavailable"}}';
}
