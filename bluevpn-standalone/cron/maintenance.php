<?php
declare(strict_types=1);

// Execute exclusively via cPanel Cron Jobs / PHP CLI.
// Example: /usr/local/bin/php /home/USERNAME/bluevpn-standalone/cron/maintenance.php
if (PHP_SAPI !== 'cli') {http_response_code(404);exit;}

$root=dirname(__DIR__);
if (!is_file($root.'/config.php')) {fwrite(STDERR,"Configuration missing\n");exit(1);}
$config=require $root.'/config.php';
spl_autoload_register(static function(string $class) use($root):void {
    $prefix='BlueVPN\\Standalone\\';
    if (!str_starts_with($class,$prefix)) return;
    $name=substr($class,strlen($prefix));
    if (preg_match('/^[A-Za-z]+$/D',$name)) require $root.'/src/'.$name.'.php';
});
try {
    $db=new BlueVPN\Standalone\Database($config['database']);
    if (!$db->installed()) throw new RuntimeException('Installation is not complete');
    $pdo=$db->connection();
    $lock=(int)$pdo->query("SELECT GET_LOCK('bluevpn_native_maintenance',0)")->fetchColumn();
    if ($lock!==1) {echo "Another job is running\n";exit(0);}
    try {
        // Bound cleanup without touching active customer entitlements.
        $expired=$pdo->exec('DELETE FROM '.$db->table('customer_sessions').
            ' WHERE expires_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY) '.
            'OR refresh_expires_at<UTC_TIMESTAMP() OR revoked_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY)');
        $limits=$pdo->exec('DELETE FROM '.$db->table('rate_limits').
            ' WHERE reset_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY)');
        $out=['success'=>true,'job'=>'maintenance','expired_sessions'=>$expired,
            'expired_rate_limits'=>$limits,'completed_at'=>gmdate(DATE_ATOM)];
        echo json_encode($out,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('bluevpn_native_maintenance')");
    }
} catch (Throwable $e) {
    error_log('BlueVPN maintenance job failed: '.get_class($e));
    fwrite(STDERR,"BlueVPN maintenance failed\n");
    exit(1);
}
