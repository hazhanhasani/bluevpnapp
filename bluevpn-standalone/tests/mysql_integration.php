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
// Authentication regression: new users, session rotation, account, logout.
$reflect=new ReflectionClass(Api::class);
$register=$reflect->getMethod('register');
$login=$reflect->getMethod('login');
$refresh=$reflect->getMethod('refresh');
$logout=$reflect->getMethod('logout');
$current=$reflect->getMethod('currentCustomer');
$credentials=['email'=>'alice@example.test','password'=>'strong-test-password',
    'device_id'=>'test-device-01','device_name'=>'PHP CI'];
ob_start();$register->invoke($api,$credentials);
$created=json_decode((string)ob_get_clean(),true);
check(($created['success']??false)===true,'registration success');
check(strlen($created['token']??'')===64,'token is random hex');
check($db->count('customers')===1,'customer persisted');
check(($created['account']['subscription']['active']??null)===false,'new customer has no active subscription');
$_SERVER['HTTP_AUTHORIZATION']='Bearer '.$created['token'];
check((int)$current->invoke($api)['id']>0,'bearer token authenticates');
ob_start();$login->invoke($api,$credentials);
$loggedIn=json_decode((string)ob_get_clean(),true);
check(($loggedIn['success']??false)===true,'login success');
check($loggedIn['token']!==$created['token'],'new login rotates access token');
$_SERVER['HTTP_AUTHORIZATION']='Bearer '.$created['token'];
try {$current->invoke($api);check(false,'old token must be revoked');}
catch (BlueVPN\Standalone\ApiFailure $e) {check($e->httpStatus===401,'old session rejected');}
ob_start();$refresh->invoke($api,['device_id'=>'test-device-01','identity'=>'alice@example.test',
    'refresh_token'=>$loggedIn['refresh_token']]);
$renewed=json_decode((string)ob_get_clean(),true);
check(($renewed['success']??false)===true,'refresh success');
$_SERVER['HTTP_AUTHORIZATION']='Bearer '.$renewed['token'];
check((int)$current->invoke($api)['id']>0,'refreshed bearer token works');
ob_start();$logout->invoke($api);$loggedOut=json_decode((string)ob_get_clean(),true);
check(($loggedOut['success']??false)===true,'logout success');
try {$current->invoke($api);check(false,'logout must revoke access');}
catch (BlueVPN\Standalone\ApiFailure $e) {check($e->httpStatus===401,'logged out session rejected');}
// Android releases are blank until an administrator publishes a signed asset.
ob_start();$api->handle('/api/v1/mobile/config','GET');
$unpublished=json_decode((string)ob_get_clean(),true);
check(($unpublished['release_metadata_ready']??true)===false,'unpublished release not advertised');
check(($unpublished['free_access']['enabled']??true)===false,'old free policy is explicitly disabled');
check(($unpublished['auth']['phone_otp_enabled']??true)===false,'OTP is hidden until configured');
$releaseSha=str_repeat('a',64);
$db->connection()->prepare('INSERT INTO '.$db->table('releases').
    ' (platform,version,version_code,channel,file_url,sha256,active) VALUES (?,?,?,?,?,?,1)')
    ->execute(['android','6.3.6',60306,'stable','https://example.test/bluevpn-universal.apk',$releaseSha]);
ob_start();$api->handle('/api/v1/mobile/config','GET');
$published=json_decode((string)ob_get_clean(),true);
check(($published['release_metadata_ready']??false)===true,'verified stable release is published');
check(($published['auto_update']??false)===true,'published release enables updater');
check(($published['apk_sha256']??'')===$releaseSha,'expected APK checksum is present');
check(($published['apk_url']??'')==='https://example.test/bluevpn-universal.apk',
    'new release URL is sent to Android');
echo "MySQL installation, schema, API, native release and authentication lifecycle checks passed\n";


