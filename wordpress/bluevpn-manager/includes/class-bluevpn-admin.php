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
        add_action('admin_post_bluevpn_migration_save',[self::class,'migration_save']);
        add_action('admin_post_bluevpn_migration_generate_token',[self::class,'migration_generate_token']);
        add_action('admin_post_bluevpn_migration_test',[self::class,'migration_test']);
        add_action('admin_post_bluevpn_migration_manifest',[self::class,'migration_manifest']);
        add_action('admin_post_bluevpn_migration_run',[self::class,'migration_run']);
        add_action('admin_post_bluevpn_migration_resync',[self::class,'migration_resync']);
        add_action('admin_post_bluevpn_migration_reset',[self::class,'migration_reset']);
        add_action('admin_post_bluevpn_migration_cutover',[self::class,'migration_cutover']);
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
    public static function dashboard(): void { self::guard();$s=BlueVPN_DB::status();$counts=$s['ready']?BlueVPN_DB::counts():[];self::head('BlueVPN Manager');echo '<div class="notice notice-warning"><p><strong>مرحله ۲ مهاجرت:</strong> فعلاً Railway را خاموش نکنید و Base URL اپ را تغییر ندهید.</p></div><div class="bvp-grid">';echo '<div class="bvp-card"><h3>MySQL</h3><p class="'.($s['ready']?'bvp-ok':'bvp-warn').'">'.($s['ready']?'آماده':'نیاز به تعمیر').'</p><small>Schema '.esc_html($s['schema_version']).'</small></div>';echo '<div class="bvp-card"><h3>جداول BlueVPN</h3><p><strong>'.count(array_filter($counts,fn($v)=>$v>=0)).' / '.count(BlueVPN_DB::table_names()).'</strong></p></div>';echo '<div class="bvp-card"><h3>API جدید</h3><div class="bvp-code">'.esc_html(rest_url('bluevpn-system/v1/health')).'</div></div>';echo '<div class="bvp-card"><h3>مسیر سازگار قدیمی</h3><div class="bvp-code">'.esc_html(home_url('/health')).'</div></div></div>';echo '<h2>وضعیت داده‌ها</h2><table class="widefat striped bvp-table"><thead><tr><th>جدول</th><th>رکورد</th></tr></thead><tbody>';foreach($counts as $k=>$v)echo '<tr><td>'.esc_html($k).'</td><td>'.esc_html((string)$v).'</td></tr>';echo '</tbody></table>';self::foot(); }
    public static function settings_page(): void { self::guard();$s=BlueVPN_DB::settings();self::head('تنظیمات BlueVPN');if(isset($_GET['saved']))echo '<div class="notice notice-success"><p>ذخیره شد.</p></div>';echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_save_settings');echo '<input type="hidden" name="action" value="bluevpn_save_settings"><table class="form-table">';$fields=['app_name'=>'نام اپ','public_base_url'=>'Base URL عمومی','support_url'=>'لینک پشتیبانی','minimum_version'=>'حداقل نسخه','latest_version'=>'آخرین نسخه','latest_version_code'=>'Version Code','apk_url'=>'لینک APK','update_title'=>'عنوان آپدیت','update_message'=>'متن آپدیت','announcement_title'=>'عنوان اعلان','announcement_message'=>'متن اعلان'];foreach($fields as $k=>$label){$type=$k==='latest_version_code'?'number':'text';echo '<tr><th>'.esc_html($label).'</th><td><input class="regular-text" type="'.$type.'" name="'.$k.'" value="'.esc_attr((string)$s[$k]).'"></td></tr>';}foreach(['maintenance'=>'حالت تعمیرات','force_update'=>'آپدیت اجباری','auto_update'=>'آپدیت خودکار','announcement_enabled'=>'نمایش اعلان'] as $k=>$label)echo '<tr><th>'.esc_html($label).'</th><td><label><input type="checkbox" name="'.$k.'" value="1" '.checked(!empty($s[$k]),true,false).'> فعال</label></td></tr>';echo '</table>';submit_button('ذخیره تنظیمات');echo '</form>';self::foot(); }
    public static function save_settings(): void { self::guard();check_admin_referer('bluevpn_save_settings');$s=BlueVPN_DB::settings();foreach(['app_name','public_base_url','support_url','minimum_version','latest_version','apk_url','update_title','update_message','announcement_title','announcement_message'] as $k)$s[$k]=sanitize_text_field(wp_unslash($_POST[$k]??''));$s['latest_version_code']=max(0,(int)($_POST['latest_version_code']??0));foreach(['maintenance','force_update','auto_update','announcement_enabled'] as $k)$s[$k]=isset($_POST[$k]);BlueVPN_DB::save_settings($s);wp_safe_redirect(admin_url('admin.php?page=bluevpn-settings&saved=1'));exit; }
    public static function plans_page(): void { self::guard();global $wpdb;$t=BlueVPN_DB::table('plans');$rows=$wpdb->get_results("SELECT * FROM {$t} WHERE deleted=0 ORDER BY sort_order,id",ARRAY_A);self::head('پلن‌های BlueVPN');echo '<div class="bvp-card" style="max-width:900px"><h2>افزودن پلن</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_add_plan');echo '<input type="hidden" name="action" value="bluevpn_add_plan"><input name="title" placeholder="عنوان" required> <input type="number" name="price_toman" placeholder="قیمت تومان" required> <input type="number" name="duration_days" placeholder="روز" required> <input type="number" name="data_limit_gb" placeholder="گیگ" value="0"> <input type="number" name="device_limit" placeholder="دستگاه" value="1"><br><br><textarea name="description" placeholder="توضیحات" rows="3" style="width:100%"></textarea>';submit_button('افزودن پلن','primary','submit',false);echo '</form></div><h2>لیست</h2><table class="widefat striped bvp-table"><tr><th>ID</th><th>عنوان</th><th>قیمت</th><th>روز</th><th>حجم</th><th>دستگاه</th><th>وضعیت</th></tr>';foreach($rows as $x){$url=wp_nonce_url(admin_url('admin-post.php?action=bluevpn_toggle_plan&id='.(int)$x['id']),'bluevpn_toggle_plan_'.$x['id']);echo '<tr><td>'.$x['id'].'</td><td>'.esc_html($x['title']).'</td><td>'.number_format((int)$x['price_toman']).'</td><td>'.$x['duration_days'].'</td><td>'.$x['data_limit_gb'].'</td><td>'.$x['device_limit'].'</td><td><a href="'.esc_url($url).'">'.((int)$x['active']?'فعال':'غیرفعال').'</a></td></tr>';}echo '</table>';self::foot(); }
    public static function add_plan(): void { self::guard();check_admin_referer('bluevpn_add_plan');global $wpdb;$wpdb->insert(BlueVPN_DB::table('plans'),['title'=>sanitize_text_field(wp_unslash($_POST['title']??'')),'description'=>sanitize_textarea_field(wp_unslash($_POST['description']??'')),'price_toman'=>max(0,(int)($_POST['price_toman']??0)),'duration_days'=>max(0,(int)($_POST['duration_days']??0)),'data_limit_gb'=>max(0,(int)($_POST['data_limit_gb']??0)),'device_limit'=>max(1,(int)($_POST['device_limit']??1)),'group_ids_json'=>'[]','active'=>1,'deleted'=>0,'sort_order'=>0,'marzban_quota_mode'=>'split','guardcore_service_ids_json'=>'[]','multi_provider_quota_mode'=>'split','created_at'=>BlueVPN_Utils::now_mysql()]);wp_safe_redirect(admin_url('admin.php?page=bluevpn-plans'));exit; }
    public static function toggle_plan(): void { self::guard();$id=(int)($_GET['id']??0);check_admin_referer('bluevpn_toggle_plan_'.$id);global $wpdb;$t=BlueVPN_DB::table('plans');$v=(int)$wpdb->get_var($wpdb->prepare("SELECT active FROM {$t} WHERE id=%d",$id));$wpdb->update($t,['active'=>$v?0:1],['id'=>$id]);wp_safe_redirect(admin_url('admin.php?page=bluevpn-plans'));exit; }
    public static function customers_page(): void { self::guard();global $wpdb;$t=BlueVPN_DB::table('customers');$rows=$wpdb->get_results("SELECT id,email,phone,active,plan_id,subscription_status,created_at FROM {$t} ORDER BY id DESC LIMIT 200",ARRAY_A);self::head('کاربران BlueVPN');echo '<table class="widefat striped bvp-table"><tr><th>ID</th><th>کاربر</th><th>پلن</th><th>اشتراک</th><th>وضعیت</th></tr>';foreach($rows as $x){$u=wp_nonce_url(admin_url('admin-post.php?action=bluevpn_toggle_customer&id='.(int)$x['id']),'bluevpn_toggle_customer_'.$x['id']);echo '<tr><td>'.$x['id'].'</td><td>'.esc_html($x['phone']?:$x['email']).'</td><td>'.esc_html((string)$x['plan_id']).'</td><td>'.esc_html($x['subscription_status']).'</td><td><a href="'.esc_url($u).'">'.((int)$x['active']?'فعال':'غیرفعال').'</a></td></tr>';}echo '</table>';self::foot(); }
    public static function toggle_customer(): void { self::guard();$id=(int)($_GET['id']??0);check_admin_referer('bluevpn_toggle_customer_'.$id);global $wpdb;$t=BlueVPN_DB::table('customers');$v=(int)$wpdb->get_var($wpdb->prepare("SELECT active FROM {$t} WHERE id=%d",$id));$wpdb->update($t,['active'=>$v?0:1],['id'=>$id]);wp_safe_redirect(admin_url('admin.php?page=bluevpn-customers'));exit; }
    public static function migration_page(): void {
        self::guard();
        $cfg = BlueVPN_Migration::settings();
        $state = BlueVPN_Migration::state();
        $comparison = BlueVPN_Migration::compare_counts();
        $cutover = get_option('bluevpn_manager_cutover_ready','0') === '1';
        self::head('مهاجرت Railway → WordPress');
        if (isset($_GET['msg'])) echo '<div class="notice notice-info"><p>'.esc_html(sanitize_text_field(wp_unslash($_GET['msg']))).'</p></div>';
        if (isset($_GET['error'])) echo '<div class="notice notice-error"><p>'.esc_html(sanitize_text_field(wp_unslash($_GET['error']))).'</p></div>';
        echo '<div class="notice notice-warning"><p><strong>Railway را هنوز خاموش نکن.</strong> تا وقتی انتقال اولیه، Resync و تست اپ کامل نشده، Backend فعلی باید فعال بماند.</p></div>';
        $generatedToken = get_transient('bluevpn_migration_token_once_'.get_current_user_id());
        if (is_string($generatedToken) && $generatedToken !== '') {
            delete_transient('bluevpn_migration_token_once_'.get_current_user_id());
            echo '<div class="notice notice-success"><p><strong>Migration Token ساخته شد.</strong> همین حالا این مقدار را در Railway با نام <code>WORDPRESS_MIGRATION_TOKEN</code> ثبت کن؛ بعد از بستن صفحه دوباره نمایش داده نمی‌شود.</p><p><input dir="ltr" readonly onclick="this.select()" style="width:100%;max-width:760px;font-family:monospace" value="'.esc_attr($generatedToken).'"></p></div>';
        }

        echo '<div class="bvp-grid">';
        echo '<div class="bvp-card"><h3>مرحله</h3><p><strong>'.esc_html((string)$state['phase']).'</strong></p><small>آخرین اجرا: '.esc_html((string)$state['last_run_at']).'</small></div>';
        echo '<div class="bvp-card"><h3>Backend مبدا</h3><p><strong>'.esc_html($state['source_version'] ?: 'نامشخص').'</strong></p><small>'.esc_html($state['source_database_mode'] ?: '—').'</small></div>';
        echo '<div class="bvp-card"><h3>Cutover</h3><p class="'.($cutover?'bvp-ok':'bvp-warn').'">'.($cutover?'آماده علامت‌گذاری شده':'هنوز آماده نیست').'</p></div>';
        echo '<div class="bvp-card"><h3>Auto / Dual Sync</h3><p class="'.(!empty($cfg['auto_sync'])?'bvp-ok':'bvp-warn').'">'.(!empty($cfg['auto_sync'])?'فعال':'غیرفعال').'</p></div>';
        echo '</div>';

        echo '<div class="bvp-card" style="max-width:1000px;margin-top:18px"><h2>اتصال امن به Railway</h2>';
        echo '<p>در Railway یک Variable با نام <code>WORDPRESS_MIGRATION_TOKEN</code> بساز و همان مقدار را اینجا وارد کن. Token در WordPress به‌صورت رمز‌شده ذخیره می‌شود.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('bluevpn_migration_save');
        echo '<input type="hidden" name="action" value="bluevpn_migration_save"><table class="form-table">';
        echo '<tr><th>Railway Backend URL</th><td><input class="regular-text" dir="ltr" name="source_url" placeholder="https://bluevpnapp-production.up.railway.app" value="'.esc_attr($cfg['source_url']).'"></td></tr>';
        echo '<tr><th>Migration Token</th><td><input class="regular-text" dir="ltr" type="password" name="token" value="" placeholder="'.(BlueVPN_Migration::has_token()?'ذخیره شده؛ برای تغییر مقدار جدید وارد کن':'Token را وارد کن').'"></td></tr>';
        echo '<tr><th>Batch Size</th><td><input type="number" min="25" max="1000" name="batch_size" value="'.(int)$cfg['batch_size'].'"></td></tr>';
        echo '<tr><th>SSL Verify</th><td><label><input type="checkbox" name="verify_tls" value="1" '.checked(!empty($cfg['verify_tls']),true,false).'> بررسی گواهی TLS</label></td></tr>';
        echo '<tr><th>Dual Sync آزمایشی</th><td><label><input type="checkbox" name="auto_sync" value="1" '.checked(!empty($cfg['auto_sync']),true,false).'> بعد از انتقال اولیه هر ۵ دقیقه Resync اجرا شود</label></td></tr>';
        echo '</table>'; submit_button('ذخیره تنظیمات Bridge'); echo '</form>';
        echo '<div style="display:flex;gap:8px;flex-wrap:wrap">';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline">';
        wp_nonce_field('bluevpn_migration_generate_token'); echo '<input type="hidden" name="action" value="bluevpn_migration_generate_token">'; submit_button('ساخت Migration Token','secondary','submit',false); echo '</form>';
        foreach ([
            ['bluevpn_migration_test','تست اتصال'],['bluevpn_migration_manifest','خواندن Manifest'],['bluevpn_migration_run','شروع / ادامه انتقال'],['bluevpn_migration_resync','شروع Resync کامل']
        ] as [$action,$label]) {
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline">';
            wp_nonce_field($action); echo '<input type="hidden" name="action" value="'.esc_attr($action).'">';
            submit_button($label, $action==='bluevpn_migration_run'?'primary':'secondary', 'submit', false);
            echo '</form>';
        }
        echo '</div></div>';

        echo '<h2>وضعیت انتقال جدول‌ها</h2><table class="widefat striped bvp-table"><thead><tr><th>جدول</th><th>Railway</th><th>MySQL</th><th>این اجرا</th><th>وضعیت</th><th>خطا</th></tr></thead><tbody>';
        foreach (BlueVPN_Migration::table_order() as $name) {
            $row=$state['tables'][$name]; $cmp=$comparison[$name];
            $status=!empty($row['done'])?'✅ کامل':'⏳ در انتظار';
            if (!empty($row['last_error'])) $status='❌ خطا';
            echo '<tr><td><code>'.esc_html($name).'</code></td><td>'.esc_html($cmp['source']===null?'—':(string)$cmp['source']).'</td><td>'.esc_html($cmp['local']===null?'—':(string)$cmp['local']).'</td><td>'.esc_html((string)$row['imported']).'</td><td>'.esc_html($status).'</td><td>'.esc_html((string)$row['last_error']).'</td></tr>';
        }
        echo '</tbody></table>';

        echo '<div class="bvp-card" style="max-width:1000px;margin-top:18px"><h2>مرحله نهایی</h2>';
        echo '<p>بعد از یک Resync کامل و برابر شدن داده‌های حیاتی، می‌توانی وضعیت Cutover را علامت بزنی. این دکمه Railway را خاموش نمی‌کند و فقط وضعیت داخلی افزونه را تغییر می‌دهد.</p>';
        echo '<div style="display:flex;gap:8px;flex-wrap:wrap">';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('bluevpn_migration_cutover'); echo '<input type="hidden" name="action" value="bluevpn_migration_cutover"><input type="hidden" name="ready" value="'.($cutover?'0':'1').'">'; submit_button($cutover?'لغو آمادگی Cutover':'علامت‌گذاری آماده Cutover','secondary','submit',false); echo '</form>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" onsubmit="return confirm(\'وضعیت مهاجرت از ابتدا شود؟ داده‌های MySQL حذف نمی‌شوند.\')">'; wp_nonce_field('bluevpn_migration_reset'); echo '<input type="hidden" name="action" value="bluevpn_migration_reset">'; submit_button('ریست Progress مهاجرت','delete','submit',false); echo '</form>';
        echo '</div></div>';
        self::foot();
    }

    private static function migration_redirect(string $msg='', string $error=''): void {
        $args=['page'=>'bluevpn-migration'];
        if ($msg!=='') $args['msg']=$msg;
        if ($error!=='') $args['error']=$error;
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php'))); exit;
    }
    public static function migration_generate_token(): void {
        self::guard(); check_admin_referer('bluevpn_migration_generate_token');
        $token = bin2hex(random_bytes(32));
        $cfg = BlueVPN_Migration::settings();
        BlueVPN_Migration::save_settings([
            'source_url'=>$cfg['source_url'], 'token'=>$token, 'batch_size'=>$cfg['batch_size'],
            'verify_tls'=>$cfg['verify_tls'], 'auto_sync'=>$cfg['auto_sync']
        ]);
        set_transient('bluevpn_migration_token_once_'.get_current_user_id(), $token, 5 * MINUTE_IN_SECONDS);
        self::migration_redirect('Token ساخته و داخل WordPress ذخیره شد؛ آن را در Railway Variables ثبت کن.');
    }
    public static function migration_save(): void {
        self::guard(); check_admin_referer('bluevpn_migration_save');
        BlueVPN_Migration::save_settings([
            'source_url'=>wp_unslash($_POST['source_url']??''), 'token'=>wp_unslash($_POST['token']??''),
            'batch_size'=>(int)($_POST['batch_size']??250), 'verify_tls'=>isset($_POST['verify_tls']), 'auto_sync'=>isset($_POST['auto_sync'])
        ]);
        self::migration_redirect('تنظیمات Migration Bridge ذخیره شد.');
    }
    public static function migration_test(): void {
        self::guard(); check_admin_referer('bluevpn_migration_test'); $r=BlueVPN_Migration::test_connection();
        if (is_wp_error($r)) self::migration_redirect('', $r->get_error_message());
        self::migration_redirect('اتصال امن به Railway برقرار است؛ Backend '.(string)($r['backend_version']??'').' / '.(string)($r['database_mode']??''));
    }
    public static function migration_manifest(): void {
        self::guard(); check_admin_referer('bluevpn_migration_manifest'); $r=BlueVPN_Migration::refresh_manifest();
        if (is_wp_error($r)) self::migration_redirect('', $r->get_error_message());
        self::migration_redirect('Manifest دریافت شد و تعداد جدول‌ها به‌روزرسانی شد.');
    }
    public static function migration_run(): void {
        self::guard(); check_admin_referer('bluevpn_migration_run'); $r=BlueVPN_Migration::run(12);
        if (empty($r['success'])) self::migration_redirect('', implode(' | ', $r['errors']??['خطای نامشخص']));
        self::migration_redirect(!empty($r['complete'])?'انتقال اولیه کامل شد. اکنون یک Resync نهایی انجام بده.':'انتقال ادامه یافت؛ '.(int)$r['rows_imported'].' رکورد در '.(int)$r['batches'].' Batch پردازش شد.');
    }
    public static function migration_resync(): void {
        self::guard(); check_admin_referer('bluevpn_migration_resync'); BlueVPN_Migration::refresh_manifest(); BlueVPN_Migration::start_resync(); $r=BlueVPN_Migration::run(12);
        if (empty($r['success'])) self::migration_redirect('', implode(' | ', $r['errors']??['خطای نامشخص']));
        self::migration_redirect('Resync شروع شد؛ برای ادامه دوباره «شروع / ادامه انتقال» را بزن یا Dual Sync را فعال کن.');
    }
    public static function migration_reset(): void {
        self::guard(); check_admin_referer('bluevpn_migration_reset'); BlueVPN_Migration::reset(true); BlueVPN_Migration::mark_cutover_ready(false); self::migration_redirect('Progress مهاجرت ریست شد؛ داده‌های MySQL حذف نشدند.');
    }
    public static function migration_cutover(): void {
        self::guard(); check_admin_referer('bluevpn_migration_cutover'); BlueVPN_Migration::mark_cutover_ready(!empty($_POST['ready'])); self::migration_redirect('وضعیت Cutover به‌روزرسانی شد.');
    }


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
        echo '<tr><th>Tag Prefix</th><td><input class="regular-text" dir="ltr" name="tag_prefix" value="'.esc_attr($cfg['tag_prefix']).'"><p class="description">مثال: bluevpn-manager-v1.2.1</p></td></tr>';
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
        echo '<p><strong>قرارداد Release:</strong> Tag باید مانند <code>bluevpn-manager-v1.2.1</code> و فایل Release باید <code>bluevpn-manager.zip</code> باشد.</p></div>';
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
