<?php
declare(strict_types=1);

use BlueVPN\Standalone\Database;
use BlueVPN\Standalone\Api;

$root=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($root):void {
    $prefix='BlueVPN\\Standalone\\';
    if (str_starts_with($class,$prefix)) require $root.'/src/'.substr($class,strlen($prefix)).'.php';
});
$db=new Database([
    'host'=>getenv('BLUEVPN_TEST_DB_HOST')?:'127.0.0.1',
    'port'=>3306,'name'=>getenv('BLUEVPN_TEST_DB_NAME')?:'bluevpn_tests',
    'username'=>getenv('BLUEVPN_TEST_DB_USER')?:'bluevpn',
    'password'=>getenv('BLUEVPN_TEST_DB_PASSWORD')?:'bluevpn_testing_only',
    'prefix'=>'',
]);
function check(bool $result,string $description): void {
    if (!$result) {fwrite(STDERR,"FAILED: {$description}\n");exit(1);}
}
check(!$db->installed(),'uninstalled on empty database');
$db->installAdmin('admin@example.test','correct-horse-battery-staple');
check($db->installed(),'fresh install succeeds');
$inventory=$db->snapshot();
check(count($inventory['found_tables'])===count(Database::TABLES),'all tables created');
check($inventory['missing_tables']===[],'schema is complete');
try {
    $db->installAdmin('second@example.test','correct-horse-battery-staple');
    check(false,'second installation must fail');
} catch (\RuntimeException $e) { check(true,'second install rejected'); }
$db->setSetting('site_title','BlueVPN Native');
check($db->setting('site_title')==='BlueVPN Native','settings persistence');
$db->connection()->prepare('INSERT INTO '.$db->table('plans').
    ' (title,price_toman,duration_days,data_limit_gb,device_limit,active) VALUES (?,?,?,?,?,1)')
    ->execute(['Trial',0,30,1,1]);
$api=new Api($db,['app'=>['base_url'=>'https://vpn.example.test',
    'registration_enabled'=>true]]);
ob_start();$api->handle('/api/v1/mobile/config','GET');$mobile=json_decode((string)ob_get_clean(),true);
check(is_array($mobile)&&$mobile['account_required']===true,'mobile config contract');
check($mobile['auth']['phone_otp_enabled']===false,'SMS is disabled until configured');
ob_start();$api->handle('/wp-json/bluevpn-system/v1/app-connection','GET');
$connection=json_decode((string)ob_get_clean(),true);
check(($connection['compat_api_prefix']??null)==='/api/v1','control-plane discovery');
ob_start();$api->handle('/api/v1/windows/update','GET');
$update=json_decode((string)ob_get_clean(),true);
check(($update['update_available']??null)===false,'no fake available update');
check($db->count('customers')===0,'clean installation contains no customers');
echo "MySQL installation, schema, settings and API smoke checks passed\n";
