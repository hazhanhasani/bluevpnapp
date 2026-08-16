<?php
if (!defined('ABSPATH')) exit;

final class BlueVPN_Site_SEO {
    private const SEED_VERSION = '2';
    private const SEED_OPTION = 'bluevpn_site_seo_seed_version';

    public static function init(): void {
        add_action('after_switch_theme', [__CLASS__, 'activate'], 30);
        add_action('admin_init', [__CLASS__, 'maybe_seed']);
        add_filter('wp_robots', [__CLASS__, 'robots_meta']);
        add_filter('robots_txt', [__CLASS__, 'robots_txt'], 20, 2);
        add_action('template_redirect', [__CLASS__, 'maybe_serve_llms'], 0);
        add_action('wp_head', [__CLASS__, 'output_fallback_meta'], 2);
        add_action('wp_head', [__CLASS__, 'output_schema'], 30);
        add_filter('document_title_parts', [__CLASS__, 'document_title_parts'], 20);
        add_action('init', [__CLASS__, 'performance_cleanup'], 1);
        add_action('wp_enqueue_scripts', [__CLASS__, 'frontend_cleanup'], 100);
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_post_bluevpn_seo_reseed', [__CLASS__, 'handle_reseed']);
        add_filter('wp_sitemaps_posts_query_args', [__CLASS__, 'core_sitemap_query_args'], 10, 2);
        add_filter('wpseo_exclude_from_sitemap_by_post_ids', [__CLASS__, 'yoast_sitemap_exclusions']);
        add_filter('wpseo_opengraph_image', [__CLASS__, 'yoast_social_image']);
        add_filter('wpseo_twitter_image', [__CLASS__, 'yoast_social_image']);
    }

    public static function activate(): void {
        self::cleanup_sample_content();
        self::seed_defaults(true);
    }

    public static function maybe_seed(): void {
        if (!current_user_can('manage_options')) return;
        if ((string)get_option(self::SEED_OPTION, '') === self::SEED_VERSION) return;
        self::seed_defaults(false);
    }

    private static function seo_plugin_active(): bool {
        return defined('WPSEO_VERSION')
            || defined('RANK_MATH_VERSION')
            || defined('AIOSEO_VERSION')
            || class_exists('WPSEO_Options')
            || class_exists('RankMath\\Helper')
            || class_exists('AIOSEO\\Plugin\\AIOSEO');
    }

    private static function page_map(): array {
        return [
            'home' => [
                'title' => 'BlueVPN | اتصال سریع و ساده VPN برای اندروید',
                'description' => 'BlueVPN برای اندروید؛ لوکیشن را انتخاب کن و با یک تجربه ساده و یکپارچه متصل شو. دانلود اپ، مشاهده پلن‌ها و مدیریت حساب از سایت رسمی.',
                'index' => true,
            ],
            'plans' => [
                'title' => 'پلن‌های BlueVPN | اشتراک Premium',
                'description' => 'پلن‌های BlueVPN را بر اساس مدت، حجم و تعداد دستگاه مقایسه کن و اشتراک مناسب خودت را انتخاب کن.',
                'index' => true,
            ],
            'download' => [
                'title' => 'دانلود BlueVPN برای اندروید | آخرین نسخه APK',
                'description' => 'آخرین نسخه BlueVPN برای Android را از صفحه رسمی دانلود دریافت کن. نسخه فعلی APK و اطلاعات انتشار همیشه در همین صفحه بروزرسانی می‌شود.',
                'index' => true,
            ],
            'support' => [
                'title' => 'راهنما و پشتیبانی BlueVPN',
                'description' => 'راهنمای استفاده و پشتیبانی BlueVPN برای ورود، اتصال، اشتراک، دانلود و مشکلات متداول.',
                'index' => true,
            ],
            'account' => [
                'title' => 'حساب کاربری BlueVPN',
                'description' => 'ورود و مدیریت حساب کاربری BlueVPN.',
                'index' => false,
            ],
        ];
    }

    private static function current_slug(): string {
        if (is_front_page()) return 'home';
        if (is_singular('page')) {
            $post = get_queried_object();
            if ($post instanceof WP_Post) return (string)$post->post_name;
        }
        return '';
    }

    private static function current_meta(): array {
        $map = self::page_map();
        $slug = self::current_slug();
        if ($slug !== '' && isset($map[$slug])) return $map[$slug];

        if (is_singular()) {
            $title = wp_strip_all_tags((string)get_the_title());
            $excerpt = trim(wp_strip_all_tags((string)get_the_excerpt()));
            if ($excerpt === '') $excerpt = 'BlueVPN؛ تجربه ساده برای اتصال، دانلود، حساب کاربری و مدیریت اشتراک.';
            return [
                'title' => $title !== '' ? $title . ' | BlueVPN' : 'BlueVPN',
                'description' => wp_html_excerpt($excerpt, 155, '…'),
                'index' => true,
            ];
        }
        return [
            'title' => 'BlueVPN',
            'description' => 'BlueVPN برای Android؛ اتصال ساده، دانلود، پلن‌ها و مدیریت حساب کاربری.',
            'index' => !is_search() && !is_404(),
        ];
    }

    private static function canonical_url(): string {
        if (is_front_page()) return trailingslashit(home_url('/'));
        if (is_singular()) {
            $url = get_permalink();
            return is_string($url) ? $url : '';
        }
        return '';
    }

    private static function social_image_url(): string {
        return BLUEVPN_SITE_URL . '/assets/images/bluevpn-social.png';
    }

    public static function document_title_parts(array $parts): array {
        if (self::seo_plugin_active() || is_admin()) return $parts;
        $meta = self::current_meta();
        if (!empty($meta['title'])) {
            $parts['title'] = (string)$meta['title'];
            unset($parts['site'], $parts['tagline']);
        }
        return $parts;
    }

    public static function robots_meta(array $robots): array {
        $slug = self::current_slug();
        $map = self::page_map();
        if (($slug !== '' && isset($map[$slug]) && empty($map[$slug]['index'])) || is_search() || is_404()) {
            $robots['noindex'] = true;
            $robots['noarchive'] = true;
        }
        return $robots;
    }

    public static function robots_txt(string $output, bool $public): string {
        $lines = preg_split('/\\r?\\n/', trim($output));
        if (!is_array($lines)) $lines = [];
        $append = [
            'User-agent: *',
            'Disallow: /account/',
            'Disallow: /wp-admin/',
            'Allow: /wp-admin/admin-ajax.php',
        ];
        if (defined('AIOSEO_VERSION') || class_exists('AIOSEO\\Plugin\\AIOSEO')) $sitemap = home_url('/sitemap.xml');
        elseif (defined('WPSEO_VERSION') || defined('RANK_MATH_VERSION') || class_exists('WPSEO_Options') || class_exists('RankMath\\Helper')) $sitemap = home_url('/sitemap_index.xml');
        else $sitemap = home_url('/wp-sitemap.xml');
        $append[] = 'Sitemap: ' . esc_url_raw($sitemap);
        foreach ($append as $line) {
            if (!in_array($line, $lines, true)) $lines[] = $line;
        }
        return implode("\n", array_filter($lines, static fn($v) => trim((string)$v) !== '')) . "\n";
    }

    public static function maybe_serve_llms(): void {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string)wp_unslash($_SERVER['REQUEST_URI']) : '';
        $path = (string)wp_parse_url($uri, PHP_URL_PATH);
        if (untrailingslashit($path) !== '/llms.txt') return;

        status_header(200);
        nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');
        $urls = [
            'Home' => home_url('/'),
            'Plans' => home_url('/plans/'),
            'Download' => home_url('/download/'),
            'Support' => home_url('/support/'),
        ];
        echo "# BlueVPN\n\n";
        echo "BlueVPN is an Android VPN application with a public website for product information, plans, downloads and support.\n\n";
        echo "## Important public pages\n";
        foreach ($urls as $label => $url) echo '- ' . $label . ': ' . esc_url_raw($url) . "\n";
        echo "\n## Private areas\n- Account pages are private user areas and should not be indexed or summarized as public documentation.\n";
        exit;
    }

    public static function output_fallback_meta(): void {
        if (is_admin() || self::seo_plugin_active()) return;
        $meta = self::current_meta();
        $canonical = self::canonical_url();
        $image = self::social_image_url();
        $title = (string)($meta['title'] ?? 'BlueVPN');
        $description = (string)($meta['description'] ?? '');
        if ($description !== '') echo '<meta name="description" content="' . esc_attr($description) . '">' . "\n";
        if ($canonical !== '') echo '<link rel="canonical" href="' . esc_url($canonical) . '">' . "\n";
        echo '<meta property="og:locale" content="' . esc_attr(get_locale()) . '">' . "\n";
        echo '<meta property="og:type" content="' . (is_singular('post') ? 'article' : 'website') . '">' . "\n";
        echo '<meta property="og:site_name" content="BlueVPN">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($title) . '">' . "\n";
        if ($description !== '') echo '<meta property="og:description" content="' . esc_attr($description) . '">' . "\n";
        if ($canonical !== '') echo '<meta property="og:url" content="' . esc_url($canonical) . '">' . "\n";
        echo '<meta property="og:image" content="' . esc_url($image) . '">' . "\n";
        echo '<meta property="og:image:width" content="1200">' . "\n";
        echo '<meta property="og:image:height" content="630">' . "\n";
        echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr($title) . '">' . "\n";
        if ($description !== '') echo '<meta name="twitter:description" content="' . esc_attr($description) . '">' . "\n";
        echo '<meta name="twitter:image" content="' . esc_url($image) . '">' . "\n";
    }

    public static function output_schema(): void {
        if (is_admin() || is_search() || is_404() || self::current_slug() === 'account') return;

        $nodes = [];
        $home = trailingslashit(home_url('/'));
        $logo = BLUEVPN_SITE_URL . '/assets/images/bluevpn-icon.png';
        $cfg = bluevpn_site_mobile_config();
        $version = trim((string)($cfg['latest_version'] ?? ''));
        $apk = trim((string)($cfg['apk_url'] ?? ''));

        if (!self::seo_plugin_active()) {
            $nodes[] = [
                '@type' => 'Organization', '@id' => $home . '#organization', 'name' => 'BlueVPN',
                'url' => $home, 'logo' => ['@type'=>'ImageObject','url'=>$logo],
            ];
            $nodes[] = [
                '@type' => 'WebSite', '@id' => $home . '#website', 'url' => $home, 'name' => 'BlueVPN',
                'publisher' => ['@id' => $home . '#organization'], 'inLanguage' => 'fa-IR',
            ];
        }

        if (is_front_page() || self::current_slug() === 'download') {
            $software = [
                '@type' => 'SoftwareApplication',
                '@id' => home_url('/download/') . '#software',
                'name' => 'BlueVPN',
                'applicationCategory' => 'UtilitiesApplication',
                'operatingSystem' => 'Android',
                'url' => home_url('/download/'),
                'image' => self::social_image_url(),
                'description' => 'BlueVPN برای Android؛ تجربه ساده برای انتخاب لوکیشن و اتصال.',
            ];
            if ($version !== '' && $version !== '0.0.0') $software['softwareVersion'] = $version;
            if ($apk !== '' && wp_http_validate_url($apk)) $software['downloadUrl'] = $apk;
            $nodes[] = $software;
        }

        if (is_front_page()) {
            $nodes[] = [
                '@type' => 'FAQPage',
                '@id' => $home . '#faq',
                'mainEntity' => [
                    ['@type'=>'Question','name'=>'آیا برای اتصال باید تنظیمات فنی انجام بدهم؟','acceptedAnswer'=>['@type'=>'Answer','text'=>'خیر. فقط لوکیشن را انتخاب می‌کنی و BlueVPN جزئیات اتصال را در پس‌زمینه مدیریت می‌کند.']],
                    ['@type'=>'Question','name'=>'برای خرید پلن باید از کجا شروع کنم؟','acceptedAnswer'=>['@type'=>'Answer','text'=>'از صفحه پلن‌ها وارد حساب شو، پلن مناسب را انتخاب کن و مراحل خرید را ادامه بده.']],
                    ['@type'=>'Question','name'=>'نسخه جدید اپ را از کجا بگیرم؟','acceptedAnswer'=>['@type'=>'Answer','text'=>'صفحه دانلود سایت BlueVPN آخرین نسخه آماده نصب را نمایش می‌دهد.']],
                ],
            ];
        }

        $slug = self::current_slug();
        if (!self::seo_plugin_active() && in_array($slug, ['plans','download','support'], true)) {
            $map = self::page_map();
            $nodes[] = [
                '@type' => 'BreadcrumbList',
                '@id' => self::canonical_url() . '#breadcrumb',
                'itemListElement' => [
                    ['@type'=>'ListItem','position'=>1,'name'=>'خانه','item'=>$home],
                    ['@type'=>'ListItem','position'=>2,'name'=>wp_strip_all_tags((string)($map[$slug]['title'] ?? get_the_title())),'item'=>self::canonical_url()],
                ],
            ];
        }

        if (!$nodes) return;
        $schema = ['@context'=>'https://schema.org','@graph'=>$nodes];
        echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
    }

    private static function seed_defaults(bool $force): void {
        $map = self::page_map();
        foreach ($map as $slug => $cfg) {
            $page = get_page_by_path($slug);
            if (!$page instanceof WP_Post) continue;
            if (defined('WPSEO_VERSION') || class_exists('WPSEO_Options')) {
                $titleKey = '_yoast_wpseo_title';
                $descKey = '_yoast_wpseo_metadesc';
                if ($force || (string)get_post_meta($page->ID, $titleKey, true) === '') update_post_meta($page->ID, $titleKey, (string)$cfg['title']);
                if ($force || (string)get_post_meta($page->ID, $descKey, true) === '') update_post_meta($page->ID, $descKey, (string)$cfg['description']);
                if (empty($cfg['index'])) update_post_meta($page->ID, '_yoast_wpseo_meta-robots-noindex', '1');
            }
            if ((string)$page->post_excerpt === '' && !empty($cfg['description'])) {
                wp_update_post(['ID'=>$page->ID,'post_excerpt'=>(string)$cfg['description']]);
            }
        }
        update_option(self::SEED_OPTION, self::SEED_VERSION, false);
    }

    private static function cleanup_sample_content(): void {
        $hello = get_page_by_path('hello-world', OBJECT, 'post');
        if ($hello instanceof WP_Post && get_post_status($hello) === 'publish') {
            $title = trim(wp_strip_all_tags((string)$hello->post_title));
            if ($title === 'Hello world!' || $title === 'سلام دنیا!') wp_trash_post($hello->ID);
        }
        $sample = get_page_by_path('sample-page', OBJECT, 'page');
        if ($sample instanceof WP_Post && get_post_status($sample) === 'publish') {
            $title = trim(wp_strip_all_tags((string)$sample->post_title));
            if ($title === 'Sample Page' || $title === 'برگه نمونه') wp_trash_post($sample->ID);
        }
    }



    public static function core_sitemap_query_args(array $args, string $post_type): array {
        if ($post_type !== 'page') return $args;
        $account = get_page_by_path('account');
        if ($account instanceof WP_Post) {
            $excluded = isset($args['post__not_in']) && is_array($args['post__not_in']) ? $args['post__not_in'] : [];
            $excluded[] = (int)$account->ID;
            $args['post__not_in'] = array_values(array_unique(array_map('intval', $excluded)));
        }
        return $args;
    }

    public static function yoast_sitemap_exclusions(array $ids): array {
        $account = get_page_by_path('account');
        if ($account instanceof WP_Post) $ids[] = (int)$account->ID;
        return array_values(array_unique(array_map('intval', $ids)));
    }

    public static function yoast_social_image($url): string {
        $url = is_string($url) ? trim($url) : '';
        return $url !== '' ? $url : self::social_image_url();
    }

    public static function admin_menu(): void {
        add_theme_page('BlueVPN SEO', 'BlueVPN SEO', 'manage_options', 'bluevpn-seo', [__CLASS__, 'admin_page']);
    }

    public static function admin_page(): void {
        if (!current_user_can('manage_options')) return;
        $plugin = self::seo_plugin_active() ? 'فعال' : 'بدون افزونه SEO؛ fallback پوسته فعال است';
        if (defined('AIOSEO_VERSION') || class_exists('AIOSEO\\Plugin\\AIOSEO')) $sitemap = home_url('/sitemap.xml');
        elseif (defined('WPSEO_VERSION') || defined('RANK_MATH_VERSION') || class_exists('WPSEO_Options') || class_exists('RankMath\\Helper')) $sitemap = home_url('/sitemap_index.xml');
        else $sitemap = home_url('/wp-sitemap.xml');
        $account = get_page_by_path('account');
        $accountNoindex = $account instanceof WP_Post ? 'فعال' : 'صفحه حساب پیدا نشد';
        $nonce = wp_create_nonce('bluevpn_seo_reseed');
        $reseed = admin_url('admin-post.php?action=bluevpn_seo_reseed&_wpnonce=' . $nonce);
        echo '<div class="wrap"><h1>BlueVPN SEO</h1>';
        echo '<p>وضعیت پایه سئوی سایت، متادیتا، Sitemap، صفحات خصوصی و داده‌های ساختاریافته.</p>';
        echo '<table class="widefat striped" style="max-width:980px"><tbody>';
        echo '<tr><th style="width:240px">موتور SEO</th><td>' . esc_html($plugin) . '</td></tr>';
        echo '<tr><th>Sitemap</th><td><a target="_blank" rel="noopener" href="' . esc_url($sitemap) . '">' . esc_html($sitemap) . '</a></td></tr>';
        echo '<tr><th>robots.txt</th><td><a target="_blank" rel="noopener" href="' . esc_url(home_url('/robots.txt')) . '">' . esc_html(home_url('/robots.txt')) . '</a></td></tr>';
        echo '<tr><th>llms.txt</th><td><a target="_blank" rel="noopener" href="' . esc_url(home_url('/llms.txt')) . '">' . esc_html(home_url('/llms.txt')) . '</a></td></tr>';
        echo '<tr><th>حساب کاربری</th><td>noindex + خارج از Sitemap: ' . esc_html($accountNoindex) . '</td></tr>';
        echo '<tr><th>Open Graph</th><td>تصویر اجتماعی 1200×630 + fallback خودکار</td></tr>';
        echo '<tr><th>Schema</th><td>SoftwareApplication + FAQPage؛ و در نبود افزونه SEO، Organization/WebSite/Breadcrumb</td></tr>';
        echo '<tr><th>Layout موبایل</th><td>طبق تنظیم فعلی BlueVPN، چیدمان دسکتاپ روی موبایل حفظ شده است.</td></tr>';
        echo '</tbody></table>';
        echo '<p style="margin-top:18px"><a class="button button-primary" href="' . esc_url($reseed) . '">بازسازی متادیتای پیش‌فرض SEO</a></p>';
        echo '<p><em>بازسازی فقط مقادیر پیش‌فرض خالی را پر می‌کند و محتوای Elementor را تغییر نمی‌دهد.</em></p>';
        echo '</div>';
    }

    public static function handle_reseed(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('bluevpn_seo_reseed');
        self::seed_defaults(false);
        wp_safe_redirect(admin_url('themes.php?page=bluevpn-seo&updated=1'));
        exit;
    }

    public static function performance_cleanup(): void {
        if (is_admin()) return;
        remove_action('wp_head', 'wp_generator');
        remove_action('wp_head', 'rsd_link');
        remove_action('wp_head', 'wlwmanifest_link');
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('wp_print_styles', 'print_emoji_styles');
    }

    public static function frontend_cleanup(): void {
        if (!is_user_logged_in()) wp_dequeue_style('dashicons');
    }
}

BlueVPN_Site_SEO::init();
