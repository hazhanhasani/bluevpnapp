<?php
if (!defined('ABSPATH')) exit;
final class BlueVPN_Compat {
    public static function init(): void { add_action('init',[self::class,'register_rewrites']); }
    public static function register_rewrites(): void {
        add_rewrite_rule('^health/?$','index.php?rest_route=/bluevpn-system/v1/health','top');
        add_rewrite_rule('^api/v1/(.*)$','index.php?rest_route=/bluevpn/v1/$matches[1]','top');
        add_rewrite_rule('^sub/([^/]+)/?$','index.php?rest_route=/bluevpn/v1/sub/$matches[1]','top');
    }
}
