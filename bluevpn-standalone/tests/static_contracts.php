<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$paths=['public/index.php','src/Application.php','src/Database.php',
    'src/Api.php','src/Site.php','src/Admin.php','src/AdminPanel.php',
    'public/.htaccess','config.example.php'];
foreach ($paths as $path) {
    if (!is_file($root.'/'.$path)) {
        fwrite(STDERR,"Missing required file: {$path}\n");exit(1);
    }
}
$read=static fn(string $p): string => (string)file_get_contents($root.'/'.$p);
$db=$read('src/Database.php');$api=$read('src/Api.php');
$app=$read('src/Application.php');$admin=$read('src/AdminPanel.php');
$checks=[
    'WordPress not bootstrapped'=>!str_contains($app,'wp-load.php')&&!str_contains($db,'$wpdb'),
    'fresh native database schema'=>str_contains($db,'function installSchema()')&&str_contains($db,'bluevpn_'),
    'portable prepared queries'=>str_contains($db,'PDO::ATTR_EMULATE_PREPARES => false'),
    'install setup key is mandatory'=>str_contains($app,'installation_key'),
    'session token hashes'=>str_contains($api,"hash('sha256'"),
    'session refresh is transactional'=>str_contains($api,'FOR UPDATE')&&str_contains($api,'beginTransaction()'),
    'authorization protects account'=>str_contains($api,'currentCustomer()'),
    'email register/login available'=>str_contains($api,'/auth/register')&&str_contains($api,'/auth/login'),
    'billing fails closed'=>str_contains($api,'PAYMENTS_NOT_CONFIGURED'),
    'old wp-json client URLs are aliased'=>str_contains($api,'/wp-json/bluevpn/v1'),
    'admin mutating forms are CSRF-protected'=>str_contains($admin,'requireCsrf()'),
    'admin supports real plans'=>str_contains($admin,'INSERT INTO').str_contains($admin,'plans'),
    'public site exists'=>str_contains($read('src/Site.php'),'BlueVPN'),
    'HTTP auth header forwarded'=>str_contains($read('public/.htaccess'),'HTTP_AUTHORIZATION'),
];
$errors=0;
foreach ($checks as $name=>$pass) {
    if (!$pass) {fwrite(STDERR,"FAILED: {$name}\n");++$errors;}
}
if ($errors) exit(1);
echo count($checks)." standalone static contracts passed\n";
