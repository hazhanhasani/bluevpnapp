<?php
if (!defined('ABSPATH')) exit;

define('BLUEVPN_SITE_VERSION', '1.0.4');

define('BLUEVPN_SITE_DIR', get_template_directory());
define('BLUEVPN_SITE_URL', get_template_directory_uri());

require_once BLUEVPN_SITE_DIR . '/inc/helpers.php';
require_once BLUEVPN_SITE_DIR . '/inc/class-bluevpn-site-updater.php';

BlueVPN_Site_Updater::init();

function bluevpn_site_setup(): void {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('custom-logo', ['height'=>120,'width'=>420,'flex-height'=>true,'flex-width'=>true]);
    add_theme_support('html5', ['search-form','gallery','caption','style','script']);
    register_nav_menus(['primary' => 'منوی اصلی BlueVPN']);
}
add_action('after_setup_theme', 'bluevpn_site_setup');

function bluevpn_site_assets(): void {
    wp_enqueue_style('bluevpn-site', BLUEVPN_SITE_URL . '/assets/css/site.css', [], BLUEVPN_SITE_VERSION);
    wp_enqueue_script('bluevpn-site', BLUEVPN_SITE_URL . '/assets/js/site.js', [], BLUEVPN_SITE_VERSION, true);
    wp_localize_script('bluevpn-site', 'BlueVPNSite', [
        'restBase' => untrailingslashit(rest_url('bluevpn/v1')),
        'systemBase' => untrailingslashit(rest_url('bluevpn-system/v1')),
        'homeUrl' => home_url('/'),
        'loginUrl' => home_url('/account/'),
        'nonce' => wp_create_nonce('wp_rest'),
        'siteName' => get_bloginfo('name') ?: 'BlueVPN',
    ]);
}
add_action('wp_enqueue_scripts', 'bluevpn_site_assets');

function bluevpn_site_body_classes(array $classes): array {
    $classes[] = 'bluevpn-site';
    return $classes;
}
add_filter('body_class', 'bluevpn_site_body_classes');

function bluevpn_site_activate(): void {
    $pages = [
        ['title'=>'خانه','slug'=>'home','template'=>'default'],
        ['title'=>'پلن‌ها','slug'=>'plans','template'=>'page-plans.php'],
        ['title'=>'دانلود','slug'=>'download','template'=>'page-download.php'],
        ['title'=>'حساب کاربری','slug'=>'account','template'=>'page-account.php'],
        ['title'=>'پشتیبانی','slug'=>'support','template'=>'page-support.php'],
    ];
    $home_id = 0;
    foreach ($pages as $page) {
        $existing = get_page_by_path($page['slug']);
        if ($existing) { $id = $existing->ID; }
        else {
            $id = wp_insert_post([
                'post_title'=>$page['title'], 'post_name'=>$page['slug'], 'post_status'=>'publish',
                'post_type'=>'page', 'post_content'=>'',
            ]);
        }
        if ($id && !is_wp_error($id) && $page['template'] !== 'default') update_post_meta($id, '_wp_page_template', $page['template']);
        if ($page['slug'] === 'home' && $id && !is_wp_error($id)) $home_id = (int)$id;
    }
    if ($home_id) {
        update_option('show_on_front', 'page');
        update_option('page_on_front', $home_id);
    }
}
add_action('after_switch_theme', 'bluevpn_site_activate');

function bluevpn_site_admin_notice(): void {
    if (!current_user_can('manage_options')) return;
    if (!defined('BLUEVPN_MANAGER_VERSION')) {
        echo '<div class="notice notice-warning"><p><strong>BlueVPN:</strong> سرویس‌های حساب کاربری و پرداخت در دسترس نیستند. هسته خدمات سایت را فعال کنید.</p></div>';
    }
}
add_action('admin_notices', 'bluevpn_site_admin_notice');
