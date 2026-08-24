<?php
if (!defined('ABSPATH')) exit;

/** Native USD pricing for BlueVPN plans using Bitpin's USDT/IRT market. */
final class BlueVPN_Dollar_Pricing {
    private const HOOK = 'bluevpn_dollar_pricing_hourly';
    private const ENDPOINTS = [
        'https://api.bitpin.market/api/v1/mkt/tickers/',
        'https://api.bitpin.market/v1/mkt/markets/',
    ];
    private const OPTION = 'bluevpn_dollar_pricing_settings';
    private const LOCK = 'bluevpn_dollar_pricing_lock';

    public static function init(): void {
        add_action(self::HOOK, [self::class, 'cron']);
        add_action('admin_menu', [self::class, 'menu'], 40);
        add_action('admin_post_bluevpn_dollar_save_settings', [self::class, 'save_settings']);
        add_action('admin_post_bluevpn_dollar_save_plan', [self::class, 'save_plan']);
        add_action('admin_post_bluevpn_dollar_refresh', [self::class, 'manual_refresh']);
        self::ensure_schedule();
    }

    public static function activate(): void { self::ensure_schedule(); }
    public static function deactivate(): void { wp_clear_scheduled_hook(self::HOOK); }
    public static function ensure_schedule(): void {
        if (!wp_next_scheduled(self::HOOK)) wp_schedule_event(time() + 300, 'hourly', self::HOOK);
    }

    private static function settings(): array {
        return wp_parse_args((array)get_option(self::OPTION, []), [
            'adjust_percent' => 0.0, 'round_to' => 1000, 'timeout' => 12,
        ]);
    }

    private static function guard(): void {
        if (!current_user_can('manage_options')) wp_die('دسترسی ندارید.');
    }

    public static function menu(): void {
        add_submenu_page('bluevpn-manager', 'قیمت دلاری پلن‌ها', 'قیمت دلاری', 'manage_options', 'bluevpn-dollar-pricing', [self::class, 'page']);
    }

    private static function redirect(string $message, bool $error = false): void {
        wp_safe_redirect(add_query_arg($error ? 'bvd_error' : 'bvd_msg', rawurlencode($message), admin_url('admin.php?page=bluevpn-dollar-pricing')));
        exit;
    }

    public static function save_settings(): void {
        self::guard(); check_admin_referer('bluevpn_dollar_save_settings');
        $adjust = max(-50.0, min(200.0, (float)($_POST['adjust_percent'] ?? 0)));
        $round = max(1, min(1000000, (int)($_POST['round_to'] ?? 1000)));
        $timeout = max(5, min(30, (int)($_POST['timeout'] ?? 12)));
        update_option(self::OPTION, ['adjust_percent'=>$adjust, 'round_to'=>$round, 'timeout'=>$timeout], false);
        self::redirect('تنظیمات قیمت دلاری ذخیره شد.');
    }

    public static function save_plan(): void {
        self::guard();
        $id = max(0, (int)($_POST['plan_id'] ?? 0));
        check_admin_referer('bluevpn_dollar_save_plan_'.$id);
        $raw = trim((string)wp_unslash($_POST['usd_price'] ?? ''));
        $usd = $raw === '' ? null : round(max(0.0, (float)$raw), 6);
        global $wpdb; $table = BlueVPN_DB::table('plans');
        $exists = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE id=%d AND deleted=0", $id));
        if (!$exists) self::redirect('پلن پیدا نشد.', true);
        $data = $usd === null || $usd <= 0
            ? ['usd_price'=>null, 'usd_managed'=>0, 'usd_updated_at'=>BlueVPN_Utils::now_mysql()]
            : ['usd_price'=>$usd, 'usd_managed'=>1, 'usd_updated_at'=>BlueVPN_Utils::now_mysql()];
        if ($wpdb->update($table, $data, ['id'=>$id]) === false) self::redirect('ذخیره قیمت دلاری ناموفق بود.', true);
        if ($usd !== null && $usd > 0) {
            $rate = (float)get_option('bluevpn_dollar_last_rate', 0);
            if ($rate > 0) self::update_plan($id, $usd, $rate, self::settings());
        }
        self::redirect($usd ? 'قیمت دلاری پلن ذخیره شد.' : 'مدیریت دلاری این پلن غیرفعال شد.');
    }

    public static function manual_refresh(): void {
        self::guard(); check_admin_referer('bluevpn_dollar_refresh');
        $result = self::update_all(true);
        self::redirect((string)$result['message'], empty($result['success']));
    }

    public static function cron(): void { self::update_all(false); }

    private static function acquire_lock(): bool {
        $now = time(); $existing = (int)get_option(self::LOCK, 0);
        if ($existing > 0 && $existing > $now - 180) return false;
        if ($existing > 0) delete_option(self::LOCK);
        return add_option(self::LOCK, $now, '', false);
    }

    private static function fetch_rate(): float {
        $s = self::settings();
        $errors = [];
        foreach (self::ENDPOINTS as $endpoint) {
            $response = wp_safe_remote_get($endpoint, [
                'timeout'=>(int)$s['timeout'], 'redirection'=>0,
                'headers'=>['Accept'=>'application/json'],
                'user-agent'=>'BlueVPN-Manager/'.BLUEVPN_MANAGER_VERSION,
            ]);
            if (is_wp_error($response)) { $errors[]=$response->get_error_message(); continue; }
            $code = wp_remote_retrieve_response_code($response);
            if ($code < 200 || $code >= 300) { $errors[]='HTTP '.$code; continue; }
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($data)) { $errors[]='JSON نامعتبر'; continue; }
            $rate = self::extract_rate($data);
            if ($rate >= 1000 && $rate <= 10000000) return $rate;
            $errors[]='نرخ USDT/IRT نامعتبر';
        }
        throw new RuntimeException('دریافت نرخ Bitpin از همه مسیرهای امن ناموفق بود: '.implode(' | ', array_slice($errors, 0, count(self::ENDPOINTS))));
    }

    private static function extract_rate(array $data): float {
        $items = isset($data['results']) && is_array($data['results']) ? $data['results'] : $data;
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $symbol = strtoupper((string)($item['symbol'] ?? $item['code'] ?? $item['market'] ?? $item['pair'] ?? ''));
            if ($symbol === 'USDT_IRT') {
                foreach (['price','last_price','last','close'] as $key) if (isset($item[$key]) && is_numeric($item[$key])) return (float)$item[$key];
            }
        }
        foreach ($data as $key=>$value) {
            if (strtoupper((string)$key) === 'USDT_IRT' && is_array($value)) {
                foreach (['price','last_price','last','close'] as $field) if (isset($value[$field]) && is_numeric($value[$field])) return (float)$value[$field];
            }
            if (is_array($value)) { $found = self::extract_rate($value); if ($found > 0) return $found; }
        }
        return 0.0;
    }

    private static function calculated_price(float $usd, float $rate, array $s): int {
        $price = $usd * $rate * (1 + ((float)$s['adjust_percent'] / 100));
        $round = max(1, (int)$s['round_to']);
        return max(0, (int)(round($price / $round) * $round));
    }

    public static function quote_toman(float $usd): int {
        if ($usd <= 0) throw new InvalidArgumentException('قیمت دلاری پلن باید بیشتر از صفر باشد.');
        $rate = (float)get_option('bluevpn_dollar_last_rate', 0);
        if ($rate <= 0) {
            $rate = self::fetch_rate();
            update_option('bluevpn_dollar_last_rate', $rate, false);
            update_option('bluevpn_dollar_last_update', time(), false);
        }
        return self::calculated_price($usd, $rate, self::settings());
    }

    private static function update_plan(int $id, float $usd, float $rate, array $s): bool {
        global $wpdb; $table = BlueVPN_DB::table('plans');
        $price = self::calculated_price($usd, $rate, $s);
        return $wpdb->update($table, ['price_toman'=>$price, 'usd_last_price_toman'=>$price, 'usd_updated_at'=>BlueVPN_Utils::now_mysql()], ['id'=>$id, 'usd_managed'=>1]) !== false;
    }

    public static function update_all(bool $manual = false): array {
        if (!self::acquire_lock()) return ['success'=>false, 'message'=>'به‌روزرسانی قیمت هم‌اکنون در حال اجراست.'];
        try {
            $rate = self::fetch_rate(); $s = self::settings();
            global $wpdb; $table = BlueVPN_DB::table('plans'); $after = 0; $updated = 0;
            do {
                $rows = $wpdb->get_results($wpdb->prepare("SELECT id,usd_price FROM {$table} WHERE usd_managed=1 AND deleted=0 AND id>%d ORDER BY id ASC LIMIT 100", $after), ARRAY_A) ?: [];
                foreach ($rows as $row) { $after=(int)$row['id']; if (self::update_plan($after, (float)$row['usd_price'], $rate, $s)) $updated++; }
            } while (count($rows) === 100);
            update_option('bluevpn_dollar_last_rate', $rate, false);
            update_option('bluevpn_dollar_last_update', time(), false);
            delete_option('bluevpn_dollar_last_error');
            if (class_exists('BlueVPN_Error_Monitor')) BlueVPN_Error_Monitor::resolve_matching('external_http', 'bitpin', 'BITPIN_RATE_FETCH_FAILED');
            return ['success'=>true, 'message'=>'نرخ Bitpin دریافت و قیمت '.$updated.' پلن بروزرسانی شد.'];
        } catch (Throwable $e) {
            update_option('bluevpn_dollar_last_error', mb_substr($e->getMessage(), 0, 500), false);
            if (class_exists('BlueVPN_Error_Monitor')) BlueVPN_Error_Monitor::report('external_http', 'bitpin', 'warning', 'BITPIN_RATE_FETCH_FAILED', $e->getMessage(), ['last_good_rate'=>(float)get_option('bluevpn_dollar_last_rate',0)]);
            return ['success'=>false, 'message'=>'قیمت‌ها بدون تغییر ماندند؛ '.$e->getMessage()];
        } finally { delete_option(self::LOCK); }
    }

    public static function page(): void {
        self::guard(); global $wpdb; $table=BlueVPN_DB::table('plans'); $rows=$wpdb->get_results("SELECT id,title,price_toman,usd_price,usd_managed,usd_last_price_toman,usd_updated_at FROM {$table} WHERE deleted=0 ORDER BY sort_order,id", ARRAY_A) ?: [];
        $s=self::settings(); $rate=(float)get_option('bluevpn_dollar_last_rate',0); $last=(int)get_option('bluevpn_dollar_last_update',0); $error=(string)get_option('bluevpn_dollar_last_error','');
        BlueVPN_Unified_UI::shell_open('قیمت دلاری پلن‌ها'); echo '<div class="wrap" dir="rtl"><h1>قیمت دلاری پلن‌های BlueVPN</h1>';
        if(isset($_GET['bvd_msg'])) echo '<div class="notice notice-success"><p>'.esc_html((string)wp_unslash($_GET['bvd_msg'])).'</p></div>';
        if(isset($_GET['bvd_error'])) echo '<div class="notice notice-error"><p>'.esc_html((string)wp_unslash($_GET['bvd_error'])).'</p></div>';
        echo '<div class="card" style="max-width:1000px"><p><strong>نرخ سالم فعلی:</strong> '.($rate>0?number_format_i18n($rate).' تومان':'هنوز دریافت نشده').'</p><p><strong>آخرین بروزرسانی:</strong> '.($last?esc_html(wp_date('Y-m-d H:i:s',$last)):'—').'</p>'.($error?'<p style="color:#b32d2e">'.esc_html($error).'</p>':'').'<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('bluevpn_dollar_refresh','_wpnonce',true,false).'<input type="hidden" name="action" value="bluevpn_dollar_refresh"><button class="button button-primary">دریافت نرخ و بروزرسانی الآن</button></form></div>';
        echo '<div class="card" style="max-width:1000px"><h2>تنظیمات</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('bluevpn_dollar_save_settings','_wpnonce',true,false).'<input type="hidden" name="action" value="bluevpn_dollar_save_settings"><label>تعدیل درصدی <input type="number" step="0.01" name="adjust_percent" value="'.esc_attr((string)$s['adjust_percent']).'"></label> &nbsp; <label>گردکردن <input type="number" min="1" name="round_to" value="'.(int)$s['round_to'].'"></label> &nbsp; <label>Timeout <input type="number" min="5" max="30" name="timeout" value="'.(int)$s['timeout'].'"></label> <button class="button">ذخیره</button></form><p><code>'.esc_html(self::ENDPOINTS[0]).'</code> با مسیر پشتیبان امن فعال است.</p></div>';
        echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>پلن</th><th>قیمت فعلی</th><th>USD</th><th>آخرین قیمت محاسبه‌شده</th><th>عملیات</th></tr></thead><tbody>';
        foreach($rows as $row){$id=(int)$row['id'];echo '<tr><td>#'.$id.' '.esc_html((string)$row['title']).'</td><td>'.number_format_i18n((int)$row['price_toman']).' تومان</td><td>'.(!empty($row['usd_managed'])?esc_html((string)$row['usd_price']).' USD':'دستی').'</td><td>'.(!empty($row['usd_last_price_toman'])?number_format_i18n((int)$row['usd_last_price_toman']).' تومان':'—').'</td><td><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('bluevpn_dollar_save_plan_'.$id,'_wpnonce',true,false).'<input type="hidden" name="action" value="bluevpn_dollar_save_plan"><input type="hidden" name="plan_id" value="'.$id.'"><input type="number" min="0" step="0.000001" name="usd_price" value="'.esc_attr(!empty($row['usd_managed'])?(string)$row['usd_price']:'').'" placeholder="خالی = قیمت دستی"><button class="button">ذخیره</button></form></td></tr>';}
        echo '</tbody></table></div>'; BlueVPN_Unified_UI::shell_close();
    }
}
