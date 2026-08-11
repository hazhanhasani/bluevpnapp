<?php
if (!defined('ABSPATH')) exit;
final class BlueVPN_Compat {
    public static function init(): void { add_action('init',[self::class,'register_rewrites']); add_filter('query_vars',[self::class,'query_vars']); }
    public static function register_rewrites(): void {
        add_rewrite_rule('^health/?$','index.php?rest_route=/bluevpn-system/v1/health','top');
        add_rewrite_rule('^webhooks/bluepay/?$','index.php?rest_route=/bluevpn/v1/webhooks/bluepay','top');
        add_rewrite_rule('^api/v1/(.*)$','index.php?rest_route=/bluevpn/v1/$matches[1]','top');
        add_rewrite_rule('^sub/([^/]+)/?$','index.php?bluevpn_sub=$matches[1]','top');
        add_rewrite_rule('^bluevpn-login/?$','index.php?bluevpn_login=1','top');
    }
    public static function query_vars(array $vars): array { $vars[]='bluevpn_sub'; $vars[]='bluevpn_login'; return $vars; }
}
