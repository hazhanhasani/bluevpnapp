<?php
if (!defined('ABSPATH')) exit;

define('BLUEVPN_SITE_VERSION', '6.3.2');

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

/** Lightweight outline icon system; avoids emoji/platform-dependent glyphs. */
function bluevpn_site_icon(string $name, string $label = ''): string {
    $paths = [
        'bolt' => '<path d="M13 2 4.8 13h6.1L10 22l8.2-12h-6.1L13 2Z"/>',
        'radar' => '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3"/><path d="M12 4v3m8 5h-3m-5 8v-3m-8-5h3"/>',
        'account' => '<circle cx="12" cy="8" r="4"/><path d="M4.5 21c.7-4.1 3.2-6 7.5-6s6.8 1.9 7.5 6"/>',
        'refresh' => '<path d="M20 7v5h-5M4 17v-5h5"/><path d="M18.2 9A7 7 0 0 0 6 6.4L4 9m16 6-2 2.6A7 7 0 0 1 5.8 15"/>',
        'shield' => '<path d="M12 2.5 20 6v5.5c0 5-3.2 8.4-8 10-4.8-1.6-8-5-8-10V6l8-3.5Z"/><path d="m8.5 12 2.2 2.2 4.8-5"/>',
        'location' => '<path d="M20 10c0 5.4-8 12-8 12S4 15.4 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/>',
        'login' => '<path d="M10 4H5v16h5m4-4 4-4-4-4m4 4H9"/>',
        'support' => '<path d="M4 14v-2a8 8 0 0 1 16 0v2"/><path d="M4 13H2v5h4v-5H4Zm16 0h2v5h-4v-5h2Zm0 5c0 2-2 3-5 3"/>',
        'windows' => '<path d="m3 5 8-1v8H3V5Zm10-1 8-1v9h-8V4ZM3 14h8v8l-8-1v-7Zm10 0h8v9l-8-1v-8Z"/>',
        'android' => '<path d="M6 9h12v10H6V9Zm2-3-2-3m10 3 2-3M5 9a7 7 0 0 1 14 0M4 10v7m16-7v7M9 19v3m6-3v3"/>',
        'check' => '<path d="m5 12 4.2 4.2L19 6.5"/>',
    ];
    $path = $paths[$name] ?? $paths['shield'];
    $aria = $label === '' ? ' aria-hidden="true"' : ' role="img" aria-label="' . esc_attr($label) . '"';
    return '<svg class="bv-icon bv-icon-' . esc_attr($name) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"' . $aria . '>' . $path . '</svg>';
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
        'home' => BLUEVPN_SITE_URL . '/assets/images/app-home-connection-clean.webp',
        'locations' => BLUEVPN_SITE_URL . '/assets/images/app-locations-clean.webp',
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
    wp_enqueue_style('bluevpn-site-design-v2', BLUEVPN_SITE_URL . '/assets/css/design-system-v2.css', ['bluevpn-site'], BLUEVPN_SITE_VERSION);
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


/**
 * Tapsell Web bridge for the Windows client.
 *
 * The publisher authorization was issued for blluepanel.ir. The Mediaad loader
 * must therefore execute inside a real document whose origin is blluepanel.ir;
 * loading the same script from bot.blluepanel.ir would still present the wrong
 * publisher origin to the ad network.
 */
function bluevpn_site_windows_tapsell_bridge(): void {
    if ((string)($_GET['bluevpn_tapsell_windows'] ?? '') !== '1') return;

    $slotRaw = html_entity_decode((string)wp_unslash($_GET['slot'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $slot = '';
    if (preg_match('/mediaad-[A-Za-z0-9_-]{2,120}/', $slotRaw, $slotMatch)) {
        $slot = (string)$slotMatch[0];
    } else {
        $plainSlot = trim(wp_strip_all_tags($slotRaw));
        if ($plainSlot !== '' && preg_match('/^[A-Za-z0-9_-]{2,120}$/', $plainSlot)) {
            $slot = str_starts_with($plainSlot, 'mediaad-') ? $plainSlot : 'mediaad-' . $plainSlot;
        }
    }

    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Pragma: no-cache");
    header("Content-Security-Policy: default-src 'self' https: data: blob:; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://s1.mediaad.org https:; style-src 'self' 'unsafe-inline' https:; img-src 'self' https: data: blob:; frame-src https:; connect-src https: wss:;");
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Robots-Tag: noindex, nofollow, noarchive');

    if (!preg_match('/^mediaad-[A-Za-z0-9_-]{2,120}$/', $slot)) {
        status_header(400);
        echo '<!doctype html><html><body style="margin:0;background:transparent" data-bluevpn-loader-state="invalid_slot"></body></html>';
        exit;
    }

    echo '<!doctype html><html dir="rtl" data-bluevpn-loader-state="loading"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<style>html,body{margin:0;width:100%;min-height:1px;height:auto;overflow:hidden;background:transparent}';
    echo '#bluevpn-tapsell-root{width:100%;min-height:1px;height:auto;display:block}';
    echo '[id^="mediaad-"]{width:100%;min-height:1px;height:auto;display:block;text-align:center}';
    echo 'iframe,img,video,canvas,object,embed{max-width:100%;border:0;box-sizing:border-box}img,video{height:auto}</style>';
    echo '</head><body><div id="bluevpn-tapsell-root"><div id="' . esc_attr($slot) . '"></div></div>';
    echo '<script type="text/javascript">(function (){';
    echo 'const root=document.documentElement,slot=document.getElementById(' . wp_json_encode($slot) . '),head=document.head;';
    echo 'let lastHeight=0;const reportSize=function(){const h=Math.ceil(Math.max(document.body.scrollHeight,document.documentElement.scrollHeight,slot?slot.getBoundingClientRect().height:0));if(h>0&&Math.abs(h-lastHeight)>=2){lastHeight=h;try{if(window.chrome&&window.chrome.webview)window.chrome.webview.postMessage("BLUEVPN_TAPSELL_SIZE:"+h);}catch(e){}}};';
    echo 'let finished=false;const send=function(state){if(finished&&state==="READY")return;root.dataset.bluevpnLoaderState=state.toLowerCase();';
    echo 'try{if(window.chrome&&window.chrome.webview)window.chrome.webview.postMessage("BLUEVPN_TAPSELL_"+state);}catch(e){}';
    echo 'if(state==="READY"||state==="NO_FILL"||state==="LOAD_ERROR"||state==="TIMEOUT")finished=true;};';
    echo 'const visible=function(n){if(!(n instanceof Element))return false;const b=n.getBoundingClientRect(),s=getComputedStyle(n);return b.width>20&&b.height>20&&s.display!=="none"&&s.visibility!=="hidden"&&Number(s.opacity||1)>0;};';
    echo 'const rendered=function(n){if(!visible(n))return false;const s=getComputedStyle(n);if(["IFRAME","IMG","VIDEO","CANVAS","OBJECT","EMBED"].includes(n.tagName))return true;if(s.backgroundImage&&s.backgroundImage!=="none")return true;if(n!==slot&&(n.childElementCount>0||String(n.textContent||"").trim().length>0))return true;if(n.shadowRoot){for(const c of n.shadowRoot.querySelectorAll("*"))if(rendered(c))return true;}return false;};';
    echo 'const check=function(){if(finished||!slot)return;reportSize();if(rendered(slot)){send("READY");reportSize();observer.disconnect();clearInterval(poll);clearTimeout(deadline);}};';
    echo 'const observer=new MutationObserver(function(){reportSize();check();});observer.observe(slot,{childList:true,subtree:true,attributes:true});';
    echo 'if(window.ResizeObserver){new ResizeObserver(reportSize).observe(slot);}window.addEventListener("resize",reportSize);';
    echo 'const poll=setInterval(check,300);const deadline=setTimeout(function(){if(!finished)send("NO_FILL");observer.disconnect();clearInterval(poll);},10000);';
    echo 'const script=document.createElement("script");script.type="text/javascript";script.async=true;script.src="https://s1.mediaad.org/serve/blluepanel.ir/loader.js";';
    echo 'const loadTimeout=setTimeout(function(){if(!finished)send("TIMEOUT");},15000);';
    echo 'script.onload=function(){clearTimeout(loadTimeout);root.dataset.bluevpnLoaderState="loaded";check();};';
    echo 'script.onerror=function(){clearTimeout(loadTimeout);send("LOAD_ERROR");};head.appendChild(script);';
    echo '})();</script></body></html>';
    exit;
}
add_action('template_redirect', 'bluevpn_site_windows_tapsell_bridge', 0);
