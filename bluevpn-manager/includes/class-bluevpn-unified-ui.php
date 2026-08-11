<?php
if (!defined('ABSPATH')) exit;

final class BlueVPN_Unified_UI {
    public static function init(): void {
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets']);
        add_filter('admin_body_class', [self::class, 'body_class']);
    }

    private static function page_slug(): string {
        return sanitize_key((string)($_GET['page'] ?? ''));
    }

    public static function is_bluevpn_page(): bool {
        return str_starts_with(self::page_slug(), 'bluevpn');
    }

    public static function enqueue_admin_assets(): void {
        if (!self::is_bluevpn_page()) return;
        wp_enqueue_style('bluevpn-unified-admin', BLUEVPN_MANAGER_URL . 'assets/admin-unified.css', [], BLUEVPN_MANAGER_VERSION);
        wp_enqueue_script('bluevpn-unified-admin', BLUEVPN_MANAGER_URL . 'assets/admin-unified.js', [], BLUEVPN_MANAGER_VERSION, true);
    }

    public static function body_class(string $classes): string {
        return self::is_bluevpn_page() ? $classes . ' bluevpn-admin-unified' : $classes;
    }

    private static function nav(): array {
        return [
            'اصلی' => [
                ['bluevpn-manager', 'داشبورد'], ['bluevpn-customers', 'کاربران'], ['bluevpn-plans', 'پلن‌ها'], ['bluevpn-orders', 'پرداخت‌ها'], ['bluevpn-blueai', 'BlueAI'],
            ],
            'زیرساخت' => [
                ['bluevpn-pasarguard', 'PasarGuard'], ['bluevpn-marzban', 'Marzban'], ['bluevpn-guardcore', 'GuardCore'], ['bluevpn-guardcore-queue', 'صف GuardCore'], ['bluevpn-database', 'دیتابیس'],
            ],
            'سرویس‌ها' => [
                ['bluevpn-sms', 'SMS / OTP'], ['bluevpn-bluepay', 'BluePay'], ['bluevpn-telegram-bot', 'ربات تلگرام'], ['bluevpn-app-update', 'اپ و آپدیت'], ['bluevpn-app-connection', 'اتصال اپلیکیشن'],
            ],
            'مدیریت' => [
                ['bluevpn-manual', 'فعال‌سازی دستی'], ['bluevpn-migration', 'مهاجرت'], ['bluevpn-settings', 'تنظیمات'], ['bluevpn-github-updater', 'آپدیت افزونه'],
            ],
        ];
    }

    public static function shell_open(string $title, string $subtitle = 'BlueVPN • WordPress / MySQL'): void {
        $current = self::page_slug();
        $user = wp_get_current_user();
        echo '<div class="bluevpn-admin-app" dir="rtl">';
        echo '<button type="button" class="bluevpn-mobile-menu" id="bluevpnMenuToggle" aria-label="باز کردن منو">☰</button>';
        echo '<div class="bluevpn-sidebar-overlay" id="bluevpnSidebarOverlay"></div>';
        echo '<aside class="bluevpn-sidebar" id="bluevpnSidebar">';
        echo '<div class="bluevpn-brand"><span class="bluevpn-brand-mark">B</span><div><strong>BlueVPN</strong><small>Control Center</small></div><button type="button" class="bluevpn-sidebar-close" id="bluevpnSidebarClose">×</button></div>';
        echo '<nav class="bluevpn-nav">';
        foreach (self::nav() as $group => $items) {
            echo '<div class="bluevpn-nav-group"><span class="bluevpn-nav-label">'.esc_html($group).'</span>';
            foreach ($items as [$slug, $label]) {
                $active = $current === $slug || ($current === '' && $slug === 'bluevpn-manager');
                echo '<a class="bluevpn-nav-item'.($active?' is-active':'').'" href="'.esc_url(admin_url('admin.php?page='.$slug)).'"><span class="bluevpn-nav-dot"></span>'.esc_html($label).'</a>';
            }
            echo '</div>';
        }
        echo '</nav>';
        echo '<div class="bluevpn-sidebar-status"><span class="bluevpn-live-dot"></span><div><strong>WordPress Backend</strong><small>MySQL • v'.esc_html(BLUEVPN_MANAGER_VERSION).'</small></div></div>';
        echo '</aside>';
        echo '<section class="bluevpn-main"><header class="bluevpn-topbar"><div><h1>'.esc_html($title).'</h1><p>'.esc_html($subtitle).'</p></div><div class="bluevpn-top-meta"><span id="bluevpnLiveClock">'.esc_html(BlueVPN_Utils::tehran_datetime_fa()).'</span><span class="bluevpn-user-chip">'.esc_html($user->display_name ?: $user->user_login).'</span></div></header><main class="bluevpn-content">';
    }

    public static function shell_close(): void {
        echo '</main></section></div>';
    }
}
