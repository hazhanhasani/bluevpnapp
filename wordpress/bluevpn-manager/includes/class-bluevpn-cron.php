<?php
if (!defined('ABSPATH')) exit;
final class BlueVPN_Cron {
    const HOOK='bluevpn_manager_cleanup';
    public static function init(): void { add_filter('cron_schedules',[self::class,'schedules']); add_action(self::HOOK,[self::class,'cleanup']); if(!wp_next_scheduled(self::HOOK)) self::schedule(); }
    public static function schedules(array $s): array { $s['bluevpn_five_minutes']=['interval'=>300,'display'=>'BlueVPN every 5 minutes']; return $s; }
    public static function schedule(): void { if(!wp_next_scheduled(self::HOOK)) wp_schedule_event(time()+60,'bluevpn_five_minutes',self::HOOK); }
    public static function unschedule(): void { $ts=wp_next_scheduled(self::HOOK); if($ts) wp_unschedule_event($ts,self::HOOK); }
    public static function cleanup(): void { global $wpdb;$now=BlueVPN_Utils::now_mysql();$sessions=BlueVPN_DB::table('customer_sessions');$otp=BlueVPN_DB::table('otp_challenges');$live=BlueVPN_DB::table('ai_live_connections');$wpdb->query($wpdb->prepare("DELETE FROM {$sessions} WHERE expires_at IS NOT NULL AND expires_at < %s",$now));$wpdb->query($wpdb->prepare("DELETE FROM {$otp} WHERE created_at IS NOT NULL AND created_at < %s",gmdate('Y-m-d H:i:s',time()-7*DAY_IN_SECONDS)));$wpdb->query($wpdb->prepare("UPDATE {$live} SET connected=0,verified=0 WHERE expires_at IS NOT NULL AND expires_at < %s",$now)); }
}
