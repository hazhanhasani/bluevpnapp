<?php
if (!defined('ABSPATH')) exit;
final class BlueVPN_Admin {
    public static function init(): void {
        add_action('admin_menu',[self::class,'menu']);
        add_action('admin_post_bluevpn_save_settings',[self::class,'save_settings']);
        add_action('admin_post_bluevpn_add_plan',[self::class,'add_plan']);
        add_action('admin_post_bluevpn_toggle_plan',[self::class,'toggle_plan']);
        add_action('admin_post_bluevpn_toggle_customer',[self::class,'toggle_customer']);
        add_action('admin_post_bluevpn_repair',[self::class,'repair']);
        add_action('admin_post_bluevpn_save_github_updater',[self::class,'save_github_updater']);
        add_action('admin_post_bluevpn_check_github_update',[self::class,'check_github_update']);
    }
    private static function guard(): void { if(!current_user_can('manage_options')) wp_die('دسترسی ندارید.'); }
    public static function menu(): void {
        add_menu_page('BlueVPN Manager','BlueVPN','manage_options','bluevpn-manager',[self::class,'dashboard'],'dashicons-shield-alt',3);
        add_submenu_page('bluevpn-manager','تنظیمات BlueVPN','تنظیمات','manage_options','bluevpn-settings',[self::class,'settings_page']);
        add_submenu_page('bluevpn-manager','پلن‌ها','پلن‌ها','manage_options','bluevpn-plans',[self::class,'plans_page']);
        add_submenu_page('bluevpn-manager','کاربران','کاربران','manage_options','bluevpn-customers',[self::class,'customers_page']);
        add_submenu_page('bluevpn-manager','ابزار مهاجرت','ابزار مهاجرت','manage_options','bluevpn-migration',[self::class,'migration_page']);
        add_submenu_page('bluevpn-manager','آپدیت GitHub','آپدیت GitHub','manage_options','bluevpn-github-updater',[self::class,'github_updater_page']);
    }
    private static function head(string $title): void { echo '<div class="wrap" dir="rtl"><h1>'.esc_html($title).'</h1><style>.bvp-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;max-width:1100px}.bvp-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:18px}.bvp-ok{color:#008a20;font-weight:700}.bvp-warn{color:#b26200;font-weight:700}.bvp-code{direction:ltr;text-align:left;background:#f6f7f7;padding:10px;border-radius:6px;overflow:auto}.bvp-table{background:#fff;max-width:1200px}.bvp-table th,.bvp-table td{text-align:right}</style>'; }
    private static function foot(): void { echo '</div>'; }
    public static function dashboard(): void { self::guard();$s=BlueVPN_DB::status();$counts=$s['ready']?BlueVPN_DB::counts():[];self::head('BlueVPN Manager');echo '<div class="notice notice-warning"><p><strong>مرحله ۱ مهاجرت:</strong> فعلاً Railway را خاموش نکنید و Base URL اپ را تغییر ندهید.</p></div><div class="bvp-grid">';echo '<div class="bvp-card"><h3>MySQL</h3><p class="'.($s['ready']?'bvp-ok':'bvp-warn').'">'.($s['ready']?'آماده':'نیاز به تعمیر').'</p><small>Schema '.esc_html($s['schema_version']).'</small></div>';echo '<div class="bvp-card"><h3>جداول BlueVPN</h3><p><strong>'.count(array_filter($counts,fn($v)=>$v>=0)).' / '.count(BlueVPN_DB::table_names()).'</strong></p></div>';echo '<div class="bvp-card"><h3>API جدید</h3><div class="bvp-code">'.esc_html(rest_url('bluevpn-system/v1/health')).'</div></div>';echo '<div class="bvp-card"><h3>مسیر سازگار قدیمی</h3><div class="bvp-code">'.esc_html(home_url('/health')).'</div></div></div>';echo '<h2>وضعیت داده‌ها</h2><table class="widefat striped bvp-table"><thead><tr><th>جدول</th><th>رکورد</th></tr></thead><tbody>';foreach($counts as $k=>$v)echo '<tr><td>'.esc_html($k).'</td><td>'.esc_html((string)$v).'</td></tr>';echo '</tbody></table>';self::foot(); }
    public static function settings_page(): void { self::guard();$s=BlueVPN_DB::settings();self::head('تنظیمات BlueVPN');if(isset($_GET['saved']))echo '<div class="notice notice-success"><p>ذخیره شد.</p></div>';echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_save_settings');echo '<input type="hidden" name="action" value="bluevpn_save_settings"><table class="form-table">';$fields=['app_name'=>'نام اپ','public_base_url'=>'Base URL عمومی','support_url'=>'لینک پشتیبانی','minimum_version'=>'حداقل نسخه','latest_version'=>'آخرین نسخه','latest_version_code'=>'Version Code','apk_url'=>'لینک APK','update_title'=>'عنوان آپدیت','update_message'=>'متن آپدیت','announcement_title'=>'عنوان اعلان','announcement_message'=>'متن اعلان'];foreach($fields as $k=>$label){$type=$k==='latest_version_code'?'number':'text';echo '<tr><th>'.esc_html($label).'</th><td><input class="regular-text" type="'.$type.'" name="'.$k.'" value="'.esc_attr((string)$s[$k]).'"></td></tr>';}foreach(['maintenance'=>'حالت تعمیرات','force_update'=>'آپدیت اجباری','auto_update'=>'آپدیت خودکار','announcement_enabled'=>'نمایش اعلان'] as $k=>$label)echo '<tr><th>'.esc_html($label).'</th><td><label><input type="checkbox" name="'.$k.'" value="1" '.checked(!empty($s[$k]),true,false).'> فعال</label></td></tr>';echo '</table>';submit_button('ذخیره تنظیمات');echo '</form>';self::foot(); }
    public static function save_settings(): void { self::guard();check_admin_referer('bluevpn_save_settings');$s=BlueVPN_DB::settings();foreach(['app_name','public_base_url','support_url','minimum_version','latest_version','apk_url','update_title','update_message','announcement_title','announcement_message'] as $k)$s[$k]=sanitize_text_field(wp_unslash($_POST[$k]??''));$s['latest_version_code']=max(0,(int)($_POST['latest_version_code']??0));foreach(['maintenance','force_update','auto_update','announcement_enabled'] as $k)$s[$k]=isset($_POST[$k]);BlueVPN_DB::save_settings($s);wp_safe_redirect(admin_url('admin.php?page=bluevpn-settings&saved=1'));exit; }
    public static function plans_page(): void { self::guard();global $wpdb;$t=BlueVPN_DB::table('plans');$rows=$wpdb->get_results("SELECT * FROM {$t} WHERE deleted=0 ORDER BY sort_order,id",ARRAY_A);self::head('پلن‌های BlueVPN');echo '<div class="bvp-card" style="max-width:900px"><h2>افزودن پلن</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_add_plan');echo '<input type="hidden" name="action" value="bluevpn_add_plan"><input name="title" placeholder="عنوان" required> <input type="number" name="price_toman" placeholder="قیمت تومان" required> <input type="number" name="duration_days" placeholder="روز" required> <input type="number" name="data_limit_gb" placeholder="گیگ" value="0"> <input type="number" name="device_limit" placeholder="دستگاه" value="1"><br><br><textarea name="description" placeholder="توضیحات" rows="3" style="width:100%"></textarea>';submit_button('افزودن پلن','primary','submit',false);echo '</form></div><h2>لیست</h2><table class="widefat striped bvp-table"><tr><th>ID</th><th>عنوان</th><th>قیمت</th><th>روز</th><th>حجم</th><th>دستگاه</th><th>وضعیت</th></tr>';foreach($rows as $x){$url=wp_nonce_url(admin_url('admin-post.php?action=bluevpn_toggle_plan&id='.(int)$x['id']),'bluevpn_toggle_plan_'.$x['id']);echo '<tr><td>'.$x['id'].'</td><td>'.esc_html($x['title']).'</td><td>'.number_format((int)$x['price_toman']).'</td><td>'.$x['duration_days'].'</td><td>'.$x['data_limit_gb'].'</td><td>'.$x['device_limit'].'</td><td><a href="'.esc_url($url).'">'.((int)$x['active']?'فعال':'غیرفعال').'</a></td></tr>';}echo '</table>';self::foot(); }
    public static function add_plan(): void { self::guard();check_admin_referer('bluevpn_add_plan');global $wpdb;$wpdb->insert(BlueVPN_DB::table('plans'),['title'=>sanitize_text_field(wp_unslash($_POST['title']??'')),'description'=>sanitize_textarea_field(wp_unslash($_POST['description']??'')),'price_toman'=>max(0,(int)($_POST['price_toman']??0)),'duration_days'=>max(0,(int)($_POST['duration_days']??0)),'data_limit_gb'=>max(0,(int)($_POST['data_limit_gb']??0)),'device_limit'=>max(1,(int)($_POST['device_limit']??1)),'group_ids_json'=>'[]','active'=>1,'deleted'=>0,'sort_order'=>0,'marzban_quota_mode'=>'split','guardcore_service_ids_json'=>'[]','multi_provider_quota_mode'=>'split','created_at'=>BlueVPN_Utils::now_mysql()]);wp_safe_redirect(admin_url('admin.php?page=bluevpn-plans'));exit; }
    public static function toggle_plan(): void { self::guard();$id=(int)($_GET['id']??0);check_admin_referer('bluevpn_toggle_plan_'.$id);global $wpdb;$t=BlueVPN_DB::table('plans');$v=(int)$wpdb->get_var($wpdb->prepare("SELECT active FROM {$t} WHERE id=%d",$id));$wpdb->update($t,['active'=>$v?0:1],['id'=>$id]);wp_safe_redirect(admin_url('admin.php?page=bluevpn-plans'));exit; }
    public static function customers_page(): void { self::guard();global $wpdb;$t=BlueVPN_DB::table('customers');$rows=$wpdb->get_results("SELECT id,email,phone,active,plan_id,subscription_status,created_at FROM {$t} ORDER BY id DESC LIMIT 200",ARRAY_A);self::head('کاربران BlueVPN');echo '<table class="widefat striped bvp-table"><tr><th>ID</th><th>کاربر</th><th>پلن</th><th>اشتراک</th><th>وضعیت</th></tr>';foreach($rows as $x){$u=wp_nonce_url(admin_url('admin-post.php?action=bluevpn_toggle_customer&id='.(int)$x['id']),'bluevpn_toggle_customer_'.$x['id']);echo '<tr><td>'.$x['id'].'</td><td>'.esc_html($x['phone']?:$x['email']).'</td><td>'.esc_html((string)$x['plan_id']).'</td><td>'.esc_html($x['subscription_status']).'</td><td><a href="'.esc_url($u).'">'.((int)$x['active']?'فعال':'غیرفعال').'</a></td></tr>';}echo '</table>';self::foot(); }
    public static function toggle_customer(): void { self::guard();$id=(int)($_GET['id']??0);check_admin_referer('bluevpn_toggle_customer_'.$id);global $wpdb;$t=BlueVPN_DB::table('customers');$v=(int)$wpdb->get_var($wpdb->prepare("SELECT active FROM {$t} WHERE id=%d",$id));$wpdb->update($t,['active'=>$v?0:1],['id'=>$id]);wp_safe_redirect(admin_url('admin.php?page=bluevpn-customers'));exit; }
    public static function migration_page(): void { self::guard();self::head('ابزار مهاجرت BlueVPN');echo '<div class="notice notice-warning"><p><strong>Railway را هنوز حذف نکن.</strong> این نسخه لایه پایه MySQL و API را آماده می‌کند. PasarGuard/Marzban/GuardCore، BluePay، OTP/SMS، AI و Telegram در مرحله بعد متصل می‌شوند.</p></div><div class="bvp-card" style="max-width:900px"><h2>تست‌ها</h2><p>Health REST: <code>'.esc_html(rest_url('bluevpn-system/v1/health')).'</code></p><p>Health سازگار: <code>'.esc_html(home_url('/health')).'</code></p><p>Mobile Config: <code>'.esc_html(home_url('/api/v1/mobile/config')).'</code></p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_repair');echo '<input type="hidden" name="action" value="bluevpn_repair">';submit_button('تعمیر Schema و Rewrite Rules');echo '</form></div>';self::foot(); }

    public static function github_updater_page(): void {
        self::guard();
        $cfg = BlueVPN_GitHub_Updater::settings();
        $release = BlueVPN_GitHub_Updater::latest_release(false);
        self::head('آپدیت BlueVPN Manager از GitHub');
        if (isset($_GET['saved'])) echo '<div class="notice notice-success"><p>تنظیمات GitHub ذخیره شد.</p></div>';
        if (isset($_GET['checked'])) {
            $msg = sanitize_text_field(wp_unslash($_GET['checked']));
            echo '<div class="notice notice-info"><p>'.esc_html($msg).'</p></div>';
        }
        echo '<div class="bvp-grid">';
        echo '<div class="bvp-card"><h3>نسخه نصب‌شده</h3><p><strong>'.esc_html(BLUEVPN_MANAGER_VERSION).'</strong></p></div>';
        echo '<div class="bvp-card"><h3>مخزن</h3><p><a href="'.esc_url(BlueVPN_GitHub_Updater::repository_url()).'" target="_blank" rel="noopener">'.esc_html($cfg['owner'].'/'.$cfg['repo']).'</a></p></div>';
        if (is_wp_error($release)) {
            echo '<div class="bvp-card"><h3>آخرین بررسی</h3><p class="bvp-warn">'.esc_html($release->get_error_message()).'</p></div>';
        } elseif (is_array($release)) {
            $new = version_compare($release['version'], BLUEVPN_MANAGER_VERSION, '>');
            echo '<div class="bvp-card"><h3>آخرین Release افزونه</h3><p class="'.($new?'bvp-warn':'bvp-ok').'"><strong>'.esc_html($release['version']).'</strong></p><small>'.($new?'نسخه جدید موجود است':'به‌روز است').'</small></div>';
        } else {
            echo '<div class="bvp-card"><h3>آخرین Release افزونه</h3><p class="bvp-warn">هنوز Release مخصوص افزونه پیدا نشد.</p></div>';
        }
        echo '<div class="bvp-card"><h3>آپدیت خودکار</h3><p class="'.(!empty($cfg['auto_update'])?'bvp-ok':'bvp-warn').'">'.(!empty($cfg['auto_update'])?'فعال':'غیرفعال').'</p></div>';
        echo '</div>';

        echo '<div class="bvp-card" style="max-width:900px;margin-top:18px"><h2>تنظیمات Updater</h2>';
        echo '<p>برای اینکه Releaseهای Android با افزونه قاطی نشوند، فقط Tagهایی با پیشوند تعیین‌شده و Asset دقیق زیر پذیرفته می‌شوند.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('bluevpn_save_github_updater');
        echo '<input type="hidden" name="action" value="bluevpn_save_github_updater">';
        echo '<table class="form-table">';
        echo '<tr><th>GitHub Owner</th><td><input class="regular-text" dir="ltr" name="owner" value="'.esc_attr($cfg['owner']).'"></td></tr>';
        echo '<tr><th>Repository</th><td><input class="regular-text" dir="ltr" name="repo" value="'.esc_attr($cfg['repo']).'"></td></tr>';
        echo '<tr><th>Tag Prefix</th><td><input class="regular-text" dir="ltr" name="tag_prefix" value="'.esc_attr($cfg['tag_prefix']).'"><p class="description">مثال: bluevpn-manager-v1.2.0</p></td></tr>';
        echo '<tr><th>Release Asset</th><td><input class="regular-text" dir="ltr" name="asset_name" value="'.esc_attr($cfg['asset_name']).'"></td></tr>';
        echo '<tr><th>آپدیت خودکار</th><td><label><input type="checkbox" name="auto_update" value="1" '.checked(!empty($cfg['auto_update']),true,false).'> وردپرس اجازه داشته باشد BlueVPN Manager را در پس‌زمینه آپدیت کند.</label></td></tr>';
        echo '</table>';
        submit_button('ذخیره تنظیمات GitHub');
        echo '</form>';
        echo '<hr><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('bluevpn_check_github_update');
        echo '<input type="hidden" name="action" value="bluevpn_check_github_update">';
        submit_button('همین حالا GitHub را بررسی کن','secondary');
        echo '</form>';
        echo '<p><strong>قرارداد Release:</strong> Tag باید مانند <code>bluevpn-manager-v1.2.0</code> و فایل Release باید <code>bluevpn-manager.zip</code> باشد.</p></div>';
        self::foot();
    }

    public static function save_github_updater(): void {
        self::guard();
        check_admin_referer('bluevpn_save_github_updater');
        BlueVPN_GitHub_Updater::save_settings([
            'owner' => sanitize_text_field(wp_unslash($_POST['owner'] ?? '')),
            'repo' => sanitize_text_field(wp_unslash($_POST['repo'] ?? '')),
            'tag_prefix' => sanitize_text_field(wp_unslash($_POST['tag_prefix'] ?? '')),
            'asset_name' => sanitize_file_name(wp_unslash($_POST['asset_name'] ?? '')),
            'auto_update' => isset($_POST['auto_update']),
        ]);
        delete_site_transient('update_plugins');
        wp_safe_redirect(admin_url('admin.php?page=bluevpn-github-updater&saved=1'));
        exit;
    }

    public static function check_github_update(): void {
        self::guard();
        check_admin_referer('bluevpn_check_github_update');
        BlueVPN_GitHub_Updater::clear_cache();
        $release = BlueVPN_GitHub_Updater::latest_release(true);
        delete_site_transient('update_plugins');
        if (!function_exists('wp_update_plugins')) require_once ABSPATH . 'wp-includes/update.php';
        wp_update_plugins();
        if (is_wp_error($release)) {
            $message = 'خطا: '.$release->get_error_message();
        } elseif (!is_array($release)) {
            $message = 'هنوز Release مخصوص BlueVPN Manager در GitHub پیدا نشد.';
        } elseif (version_compare($release['version'], BLUEVPN_MANAGER_VERSION, '>')) {
            $message = 'نسخه '.$release['version'].' موجود است؛ از صفحه افزونه‌ها قابل نصب است.';
        } else {
            $message = 'BlueVPN Manager به‌روز است ('.$release['version'].').';
        }
        wp_safe_redirect(add_query_arg(['page'=>'bluevpn-github-updater','checked'=>$message], admin_url('admin.php')));
        exit;
    }
    public static function repair(): void { self::guard();check_admin_referer('bluevpn_repair');BlueVPN_DB::install_schema();BlueVPN_DB::seed_defaults();BlueVPN_Compat::register_rewrites();flush_rewrite_rules(false);wp_safe_redirect(admin_url('admin.php?page=bluevpn-migration&repaired=1'));exit; }
}
