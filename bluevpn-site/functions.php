<?php
if (!defined('ABSPATH')) exit;

define('BLUEVPN_SITE_VERSION', '5.3.10');

define('BLUEVPN_SITE_DIR', get_template_directory());
define('BLUEVPN_SITE_URL', get_template_directory_uri());

function bluevpn_site_log(string $message, string $severity = 'error', string $code = 'THEME_RUNTIME_LOG', array $context = []): void {
    error_log($message);
    if (class_exists('BlueVPN_Error_Monitor') && in_array($severity, ['warning', 'error', 'critical'], true)) {
        BlueVPN_Error_Monitor::report('theme', 'site', $severity, $code, $message, $context);
    }
}

function bluevpn_site_error_log(string $message, array $context = []): void {
    bluevpn_site_log($message, 'error', 'THEME_RUNTIME_LOG', $context);
}

/**
 * Successful Elementor fallbacks are diagnostics, not runtime failures.
 * Keep them in the PHP log for troubleshooting without waking Sentinel.
 */
function bluevpn_site_diagnostic_log(string $message): void {
    error_log($message);
}


function bluevpn_site_visual_url(string $file): string {
    $safe = preg_replace('/[^a-z0-9._-]/i', '', $file);
    return BLUEVPN_SITE_URL . '/assets/images/illustrations/' . $safe;
}


function bluevpn_site_app_screenshot_url(string $slot = 'home'): string {
    $map = [
        'home' => 'bluevpn_app_screenshot_id',
        'locations' => 'bluevpn_app_locations_screenshot_id',
        'account' => 'bluevpn_app_account_screenshot_id',
        'support' => 'bluevpn_app_support_screenshot_id',
    ];
    $setting = $map[$slot] ?? $map['home'];
    $id = (int) get_theme_mod($setting, 0);
    if ($id > 0) {
        $url = wp_get_attachment_image_url($id, 'large');
        if (is_string($url) && $url !== '') return $url;
    }
    $fallbacks = [
        'home' => BLUEVPN_SITE_URL . '/assets/images/app-home-connection-clean.png',
        'locations' => BLUEVPN_SITE_URL . '/assets/images/app-locations-clean.png',
        'support' => BLUEVPN_SITE_URL . '/assets/images/app-support-real.jpg',
    ];
    return $fallbacks[$slot] ?? '';
}

function bluevpn_site_customize_register($wp_customize): void {
    if (!class_exists('WP_Customize_Media_Control')) return;
    $wp_customize->add_section('bluevpn_app_media', [
        'title' => 'BlueVPN • تصاویر واقعی اپلیکیشن',
        'description' => 'این تصاویر مستقیماً در صفحه اصلی استفاده می‌شوند. برای نتیجه حرفه‌ای، اسکرین‌شات واقعی و بدون برش از خود BlueVPN انتخاب کن.',
        'priority' => 31,
    ]);
    $slots = [
        'bluevpn_app_screenshot_id' => ['صفحه اصلی / اتصال', 'تصویر اصلی Hero و صفحه دانلود.'],
        'bluevpn_app_locations_screenshot_id' => ['صفحه لوکیشن‌ها', 'در بخش نمایش واقعی اپ کنار تصویر اصلی استفاده می‌شود.'],
        'bluevpn_app_account_screenshot_id' => ['صفحه حساب / اشتراک', 'در بخش حساب و اشتراک استفاده می‌شود.'],
        'bluevpn_app_support_screenshot_id' => ['صفحه پشتیبانی', 'برای بخش‌های پشتیبانی و توسعه‌های بعدی قالب.'],
    ];
    foreach ($slots as $id => $meta) {
        $wp_customize->add_setting($id, ['default'=>0,'sanitize_callback'=>'absint']);
        $wp_customize->add_control(new WP_Customize_Media_Control($wp_customize, $id, [
            'label'=>$meta[0], 'description'=>$meta[1], 'section'=>'bluevpn_app_media', 'mime_type'=>'image'
        ]));
    }
}
add_action('customize_register', 'bluevpn_site_customize_register');

require_once BLUEVPN_SITE_DIR . '/inc/helpers.php';
require_once BLUEVPN_SITE_DIR . '/inc/class-bluevpn-site-updater.php';
require_once BLUEVPN_SITE_DIR . '/inc/class-bluevpn-seo.php';
require_once BLUEVPN_SITE_DIR . '/inc/class-bluevpn-elementor.php';

BlueVPN_Site_Updater::init();

function bluevpn_site_setup(): void {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('custom-logo', ['height'=>120,'width'=>420,'flex-height'=>true,'flex-width'=>true]);
    add_theme_support('html5', ['search-form','gallery','caption','style','script']);
    add_theme_support('elementor');
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
        'monitorEndpoint' => rest_url('bluevpn-system/v1/monitor/client-error'),
        'monitorToken' => class_exists('BlueVPN_Error_Monitor') ? BlueVPN_Error_Monitor::client_token() : '',
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



// BlueVPN Site 5.2.2 cache/debug marker.
add_filter('body_class', static function($classes){ $classes[] = 'bluevpn-site-v4-16-9'; return $classes; });
