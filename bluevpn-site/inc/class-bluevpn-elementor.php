<?php
if (!defined('ABSPATH')) exit;

final class BlueVPN_Elementor_Integration {
    private const SEED_VERSION = '5';
    private const HEADER_OPTION = 'bluevpn_elementor_header_template_id';
    private const FOOTER_OPTION = 'bluevpn_elementor_footer_template_id';
    private const SEED_OPTION = 'bluevpn_elementor_seed_version';

    public static function init(): void {
        add_action('elementor/loaded', [__CLASS__, 'on_elementor_loaded']);
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_init', [__CLASS__, 'maybe_seed']);
        add_action('admin_post_bluevpn_elementor_rebuild', [__CLASS__, 'handle_rebuild']);
        add_action('admin_notices', [__CLASS__, 'elementor_notice']);
    }

    public static function is_available(): bool {
        return did_action('elementor/loaded') > 0 || class_exists('\Elementor\Plugin');
    }

    public static function on_elementor_loaded(): void {
        add_action('elementor/widgets/register', [__CLASS__, 'register_widgets']);
        add_action('elementor/theme/register_locations', [__CLASS__, 'register_locations']);
    }

    public static function register_widgets($widgets_manager): void {
        if (!is_object($widgets_manager) || !method_exists($widgets_manager, 'register')) return;
        if (!class_exists('\Elementor\Widget_Base') || !class_exists('\Elementor\Controls_Manager')) return;

        try {
            require_once BLUEVPN_SITE_DIR . '/inc/elementor/widgets.php';
            if (!function_exists('bluevpn_elementor_widget_instances')) return;
            foreach (bluevpn_elementor_widget_instances() as $widget) {
                if ($widget instanceof \Elementor\Widget_Base) {
                    $widgets_manager->register($widget);
                }
            }
        } catch (Throwable $e) {
            // Never take the Elementor editor down because a BlueVPN widget failed to boot.
            error_log('BlueVPN Elementor widget registration failed: '.$e->getMessage());
        }
    }

    public static function register_locations($manager): void {
        if (is_object($manager) && method_exists($manager, 'register_all_core_location')) {
            $manager->register_all_core_location();
        }
    }

    public static function elementor_notice(): void {
        if (!current_user_can('manage_options') || self::is_available()) return;
        $install = admin_url('plugin-install.php?s=Elementor&tab=search&type=term');
        echo '<div class="notice notice-warning"><p><strong>BlueVPN Site:</strong> برای ویرایش کامل سایت با کشیدن و رها کردن، افزونه Elementor را نصب و فعال کنید. <a href="'.esc_url($install).'">نصب Elementor</a></p></div>';
    }

    public static function admin_menu(): void {
        add_theme_page('BlueVPN Elementor', 'BlueVPN Elementor', 'manage_options', 'bluevpn-elementor', [__CLASS__, 'admin_page']);
    }

    public static function admin_page(): void {
        if (!current_user_can('manage_options')) return;
        $ready = self::is_available();
        $home = get_page_by_path('home');
        $plans = get_page_by_path('plans');
        $download = get_page_by_path('download');
        $account = get_page_by_path('account');
        $support = get_page_by_path('support');
        echo '<div class="wrap"><h1>BlueVPN + Elementor</h1>';
        echo '<p>صفحات عمومی BlueVPN و قالب‌های Header/Footer از داخل Elementor قابل ویرایش هستند. اطلاعات داینامیک حساب، پلن‌ها و دانلود همچنان از BlueVPN Manager خوانده می‌شوند.</p>';
        echo '<table class="widefat striped" style="max-width:900px"><tbody>';
        echo '<tr><th>Elementor</th><td>'.($ready?'<strong style="color:#12815d">فعال</strong>':'<strong style="color:#b32d2e">فعال نیست</strong>').'</td></tr>';
        echo '<tr><th>قالب Header</th><td>'.self::template_link((int)get_option(self::HEADER_OPTION,0)).'</td></tr>';
        echo '<tr><th>قالب Footer</th><td>'.self::template_link((int)get_option(self::FOOTER_OPTION,0)).'</td></tr>';
        echo '</tbody></table>';
        if ($ready) {
            echo '<h2>صفحات</h2><p>';
            foreach ([$home,$plans,$download,$account,$support] as $page) {
                if (!$page) continue;
                $url = admin_url('post.php?post='.(int)$page->ID.'&action=elementor');
                echo '<a class="button button-secondary" style="margin:0 0 8px 8px" href="'.esc_url($url).'">ویرایش '.esc_html($page->post_title).' با Elementor</a>';
            }
            echo '</p>';
            $nonce = wp_create_nonce('bluevpn_elementor_rebuild');
            $rebuild = admin_url('admin-post.php?action=bluevpn_elementor_rebuild&_wpnonce='.$nonce);
            echo '<p><a class="button button-primary" href="'.esc_url($rebuild).'" onclick="return confirm(\'قالب‌های پیش‌فرض Elementor بازسازی شوند؟ تغییرات Elementor صفحات BlueVPN بازنشانی می‌شوند.\')">بازسازی قالب‌های Elementor</a></p>';
            echo '<p><em>آپدیت‌های بعدی پوسته، طراحی‌هایی را که خودت در Elementor تغییر داده‌ای بازنویسی نمی‌کنند.</em></p>';
        }
        echo '</div>';
    }

    private static function template_link(int $id): string {
        if (!$id || get_post_status($id) === false) return 'هنوز ساخته نشده';
        return '<a href="'.esc_url(admin_url('post.php?post='.$id.'&action=elementor')).'">ویرایش با Elementor</a>';
    }

    public static function handle_rebuild(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('bluevpn_elementor_rebuild');
        if (!self::is_available()) wp_die('Elementor is not active.');
        self::seed(true);
        wp_safe_redirect(admin_url('themes.php?page=bluevpn-elementor&rebuilt=1'));
        exit;
    }

    public static function maybe_seed(): void {
        if (!self::is_available() || !current_user_can('manage_options')) return;
        if ((string)get_option(self::SEED_OPTION, '') === self::SEED_VERSION) return;
        self::seed(false);
    }


    /**
     * Elementor preview/editor requests must be rendered through WordPress' normal
     * the_content pipeline. Calling get_builder_content_for_display() recursively
     * from the active page template can trigger a fatal/500 inside the editor iframe.
     */
    private static function is_editor_request(): bool {
        if (isset($_GET['elementor-preview']) || (isset($_GET['action']) && $_GET['action'] === 'elementor')) return true;
        if (!class_exists('\Elementor\Plugin')) return false;
        try {
            $plugin = \Elementor\Plugin::instance();
            if (isset($plugin->editor) && is_object($plugin->editor) && method_exists($plugin->editor, 'is_edit_mode') && $plugin->editor->is_edit_mode()) return true;
            if (isset($plugin->preview) && is_object($plugin->preview) && method_exists($plugin->preview, 'is_preview_mode') && $plugin->preview->is_preview_mode()) return true;
        } catch (Throwable $e) {
            error_log('BlueVPN Elementor editor detection failed: '.$e->getMessage());
        }
        return false;
    }

    private static function render_editor_page(): bool {
        get_header();
        if (have_posts()) {
            while (have_posts()) {
                the_post();
                the_content();
            }
        } else {
            $post_id = (int)get_queried_object_id();
            if ($post_id > 0) {
                $post = get_post($post_id);
                if ($post) {
                    setup_postdata($post);
                    echo apply_filters('the_content', $post->post_content); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    wp_reset_postdata();
                }
            }
        }
        get_footer();
        return true;
    }

    public static function page_ready(int $post_id = 0): bool {
        $post_id = $post_id ?: (int)get_queried_object_id();
        return $post_id > 0
            && self::is_available()
            && get_post_meta($post_id, '_elementor_edit_mode', true) === 'builder'
            && trim((string)get_post_meta($post_id, '_elementor_data', true)) !== '';
    }

    /**
     * Render the current Elementor document only when it produced meaningful
     * public output. Returning false lets the PHP page template continue as a
     * fail-safe instead of serving an empty dark shell.
     */
    public static function render_page(int $post_id = 0): bool {
        $post_id = $post_id ?: (int)get_queried_object_id();
        if (!self::page_ready($post_id)) return false;

        if (self::is_editor_request()) {
            return self::render_editor_page();
        }

        try {
            $html = \Elementor\Plugin::instance()->frontend->get_builder_content_for_display($post_id, true);
            if (!self::has_meaningful_output($html)) {
                error_log('BlueVPN Elementor page fallback: empty output for post '.$post_id);
                return false;
            }

            get_header();
            echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            get_footer();
            return true;
        } catch (Throwable $e) {
            error_log('BlueVPN Elementor page render failed: '.$e->getMessage());
            return false;
        }
    }

    public static function render_location(string $location): bool {
        // Keep the editor iframe deterministic and avoid nested template rendering.
        if (self::is_editor_request()) return false;

        if (function_exists('elementor_theme_do_location')) {
            ob_start();
            $handled = false;
            try {
                $handled = (bool)elementor_theme_do_location($location);
            } catch (Throwable $e) {
                error_log('BlueVPN Elementor theme location failed: '.$e->getMessage());
            }
            $location_html = (string)ob_get_clean();
            if ($handled && self::has_meaningful_output($location_html)) {
                echo $location_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                return true;
            }
        }

        $option = $location === 'header' ? self::HEADER_OPTION : ($location === 'footer' ? self::FOOTER_OPTION : '');
        if (!$option || !self::is_available()) return false;
        $id = (int)get_option($option, 0);
        if (!$id || get_post_status($id) === false) return false;
        try {
            $html = \Elementor\Plugin::instance()->frontend->get_builder_content_for_display($id, true);
            if (self::has_meaningful_output($html)) {
                echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                return true;
            }
            error_log('BlueVPN Elementor '.$location.' fallback: template '.$id.' returned empty output');
        } catch (Throwable $e) {
            error_log('BlueVPN Elementor render failed: '.$e->getMessage());
        }
        return false;
    }

    private static function has_meaningful_output($html): bool {
        if (!is_string($html) || trim($html) === '') return false;

        $text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, get_bloginfo('charset') ?: 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', trim((string)$text));
        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        if ($length >= 8) return true;

        return (bool)preg_match('/<(?:img|svg|video|canvas|form|input|button)\b/i', $html);
    }

    private static function seed(bool $force): void {
        bluevpn_site_activate();
        $pages = [
            'home' => ['title'=>'خانه','widgets'=>['bluevpn-home-v2']],
            'plans' => ['title'=>'پلن‌ها','widgets'=>['bluevpn-plans']],
            'download' => ['title'=>'دانلود','widgets'=>['bluevpn-download']],
            'account' => ['title'=>'حساب کاربری','widgets'=>['bluevpn-account']],
            'support' => ['title'=>'پشتیبانی','widgets'=>['bluevpn-support']],
        ];
        $previous_seed = (string)get_option(self::SEED_OPTION, '');
        foreach ($pages as $slug => $cfg) {
            $page = get_page_by_path($slug);
            if (!$page) continue;
            $already = get_post_meta($page->ID, '_elementor_edit_mode', true) === 'builder';
            $home_seed = (string)get_post_meta($page->ID, '_bluevpn_home_seed_version', true);
            $elementor_data = (string)get_post_meta($page->ID, '_elementor_data', true);
            $looks_bluevpn_seeded = $slug === 'home' && (
                strpos($elementor_data, 'bluevpn-home-v2') !== false ||
                strpos($elementor_data, 'bluevpn-hero') !== false ||
                strpos($elementor_data, 'bluevpn-features') !== false
            );
            $home_migration = (!$force && $slug === 'home' && $previous_seed !== self::SEED_VERSION && $home_seed !== self::SEED_VERSION && (!$already || $looks_bluevpn_seeded));
            if (!$force && $already && !$home_migration) continue;
            update_post_meta($page->ID, '_wp_page_template', 'default');
            self::write_elementor_document((int)$page->ID, self::page_data($cfg['widgets']), 'wp-page');
            if ($slug === 'home') {
                update_post_meta($page->ID, '_bluevpn_home_v2_migrated', '1');
                update_post_meta($page->ID, '_bluevpn_home_seed_version', self::SEED_VERSION);
            }
        }
        $header_id = self::ensure_library_template('BlueVPN Header', 'bluevpn-site-header', ['bluevpn-header'], (int)get_option(self::HEADER_OPTION,0), $force);
        $footer_id = self::ensure_library_template('BlueVPN Footer', 'bluevpn-site-footer', ['bluevpn-footer'], (int)get_option(self::FOOTER_OPTION,0), $force);
        if ($header_id) update_option(self::HEADER_OPTION, $header_id, false);
        if ($footer_id) update_option(self::FOOTER_OPTION, $footer_id, false);
        update_option(self::SEED_OPTION, self::SEED_VERSION, false);
        if (class_exists('\Elementor\Plugin')) {
            try { \Elementor\Plugin::instance()->files_manager->clear_cache(); } catch (Throwable $e) {}
        }
    }

    private static function ensure_library_template(string $title, string $slug, array $widgets, int $existing_id, bool $force): int {
        $id = $existing_id;
        if (!$id || get_post_status($id) === false) {
            $found = get_page_by_path($slug, OBJECT, 'elementor_library');
            $id = $found ? (int)$found->ID : 0;
        }
        if (!$id) {
            $created = wp_insert_post(['post_type'=>'elementor_library','post_status'=>'publish','post_title'=>$title,'post_name'=>$slug]);
            if (is_wp_error($created)) return 0;
            $id = (int)$created;
        }
        $already = get_post_meta($id, '_elementor_edit_mode', true) === 'builder';
        if ($force || !$already) self::write_elementor_document($id, self::page_data($widgets), 'section');
        return $id;
    }

    private static function page_data(array $widgets): array {
        $out = [];
        foreach ($widgets as $index => $widget_type) {
            $out[] = [
                'id' => self::eid('c'.$index.$widget_type),
                'elType' => 'container',
                'isInner' => false,
                'settings' => [
                    'content_width' => 'full',
                    'width' => ['unit'=>'%','size'=>100,'sizes'=>[]],
                    'padding' => ['unit'=>'px','top'=>'0','right'=>'0','bottom'=>'0','left'=>'0','isLinked'=>true],
                    'gap' => ['unit'=>'px','size'=>0,'column'=>'0','row'=>'0','isLinked'=>true],
                ],
                'elements' => [[
                    'id' => self::eid('w'.$index.$widget_type),
                    'elType' => 'widget',
                    'widgetType' => $widget_type,
                    'settings' => [],
                    'elements' => [],
                ]],
            ];
        }
        return $out;
    }

    private static function write_elementor_document(int $post_id, array $data, string $template_type): void {
        update_post_meta($post_id, '_elementor_edit_mode', 'builder');
        update_post_meta($post_id, '_elementor_template_type', $template_type);
        update_post_meta($post_id, '_elementor_data', wp_slash(wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        update_post_meta($post_id, '_elementor_page_settings', []);
        if (defined('ELEMENTOR_VERSION')) update_post_meta($post_id, '_elementor_version', ELEMENTOR_VERSION);
    }

    private static function eid(string $seed): string {
        return substr(md5('bluevpn-'.$seed), 0, 8);
    }
}

BlueVPN_Elementor_Integration::init();
