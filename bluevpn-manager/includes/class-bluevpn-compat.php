<?php
if (!defined('ABSPATH')) exit;
final class BlueVPN_Compat {
    public static function init(): void { add_action('init',[self::class,'register_rewrites']); add_filter('query_vars',[self::class,'query_vars']); add_action('template_redirect',[self::class,'template_redirect'],0); }
    public static function register_rewrites(): void {
        add_rewrite_rule('^health/?$','index.php?rest_route=/bluevpn-system/v1/health','top');
        add_rewrite_rule('^webhooks/blupal/?$','index.php?rest_route=/bluevpn/v1/webhooks/blupal','top');
        add_rewrite_rule('^api/v1/webhooks/blupal/?$','index.php?rest_route=/bluevpn/v1/webhooks/blupal','top');
        add_rewrite_rule('^bluevpn/payment/callback/?$','index.php?bluevpn_blupal_callback=1','top');
        add_rewrite_rule('^api/v1/(.*)$','index.php?rest_route=/bluevpn/v1/$matches[1]','top');
        add_rewrite_rule('^sub/([^/]+)/?$','index.php?bluevpn_sub=$matches[1]','top');
        add_rewrite_rule('^bluevpn-login/?$','index.php?bluevpn_login=1','top');
    }
    public static function query_vars(array $vars): array { $vars[]='bluevpn_sub'; $vars[]='bluevpn_login'; $vars[]='bluevpn_blupal_callback'; return $vars; }
    public static function template_redirect(): void {
        if ((int)get_query_var('bluevpn_blupal_callback') === 1) {
            BlueVPN_Payments::render_callback_page();
        }
    }
}
