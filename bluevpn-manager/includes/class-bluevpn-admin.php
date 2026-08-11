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
        add_action('admin_post_bluevpn_migration_auto_start',[self::class,'migration_auto_start']);
        add_action('admin_post_bluevpn_migration_auto_stop',[self::class,'migration_auto_stop']);
        add_action('wp_ajax_bluevpn_migration_pump',[self::class,'migration_pump']);
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
        $status = BlueVPN_Migration::dashboard_status(true);
        $readiness = $status['readiness'];
        $state = BlueVPN_Migration::state();
        $comparison = $readiness['comparison'];
        $cutover = !empty($readiness['ready']);
        $phase = (string)$status['phase'];
        $autoActive = !empty($cfg['auto_migrate']) && !$cutover && $phase !== 'paused';

        self::head('مهاجرت Railway → WordPress');
        echo '<style>
        .bvp-mig-shell{max-width:1180px}.bvp-mig-hero{background:linear-gradient(135deg,#0f172a,#172554);color:#fff;border-radius:18px;padding:22px;margin:14px 0 18px;box-shadow:0 10px 30px rgba(15,23,42,.12)}
        .bvp-mig-hero h2{color:#fff;margin:0 0 8px}.bvp-mig-hero p{margin:5px 0;color:#dbeafe}.bvp-mig-progress{height:14px;background:rgba(255,255,255,.16);border-radius:999px;overflow:hidden;margin:18px 0 8px}.bvp-mig-progress>span{display:block;height:100%;background:linear-gradient(90deg,#38bdf8,#22c55e);transition:width .35s ease}
        .bvp-mig-stepper{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px;margin:16px 0 20px}.bvp-mig-step{border:1px solid #dcdcde;background:#fff;border-radius:14px;padding:12px;text-align:center;min-height:72px}.bvp-mig-step strong{display:block;margin-bottom:5px}.bvp-mig-step.done{border-color:#86efac;background:#f0fdf4}.bvp-mig-step.active{border-color:#60a5fa;background:#eff6ff;box-shadow:0 0 0 2px rgba(59,130,246,.08)}
        .bvp-mig-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:12px 0}.bvp-mig-metric{background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:15px}.bvp-mig-metric small{display:block;color:#646970}.bvp-mig-metric strong{font-size:20px;display:block;margin-top:5px;word-break:break-word}
        .bvp-mig-live{border:1px solid #bfdbfe;background:#eff6ff;border-radius:14px;padding:16px;margin:14px 0}.bvp-mig-live.error{border-color:#fecaca;background:#fef2f2}.bvp-mig-live.warn{border-color:#fde68a;background:#fffbeb}.bvp-mig-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.bvp-mig-actions form{margin:0}.bvp-mig-table-wrap{overflow:auto;background:#fff;border:1px solid #dcdcde;border-radius:14px}.bvp-mig-table-wrap table{border:0;margin:0}.bvp-mig-pill{display:inline-block;border-radius:999px;padding:3px 9px;font-size:12px;font-weight:600}.bvp-mig-pill.ok{background:#dcfce7;color:#166534}.bvp-mig-pill.wait{background:#fef3c7;color:#92400e}.bvp-mig-pill.err{background:#fee2e2;color:#991b1b}.bvp-mig-pill.info{background:#dbeafe;color:#1e40af}
        @media(max-width:900px){.bvp-mig-stepper{grid-template-columns:repeat(2,minmax(0,1fr))}.bvp-mig-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:560px){.bvp-mig-stepper,.bvp-mig-metrics{grid-template-columns:1fr}.bvp-mig-hero{padding:16px}.bvp-mig-actions .button{width:100%;text-align:center}}
        </style>';
        echo '<div class="bvp-mig-shell">';

        if (isset($_GET['msg'])) echo '<div class="notice notice-info"><p>'.esc_html(sanitize_text_field(wp_unslash($_GET['msg']))).'</p></div>';
        if (isset($_GET['error'])) echo '<div class="notice notice-error"><p>'.esc_html(sanitize_text_field(wp_unslash($_GET['error']))).'</p></div>';
        echo '<div class="notice notice-warning"><p><strong>Railway را هنوز خاموش نکن.</strong> Cutover فقط وقتی امن است که مرحله ۶ سبز شود و اپ روی WordPress/MySQL تست شود.</p></div>';

        $generatedToken = get_transient('bluevpn_migration_token_once_'.get_current_user_id());
        if (is_string($generatedToken) && $generatedToken !== '') {
            delete_transient('bluevpn_migration_token_once_'.get_current_user_id());
            echo '<div class="notice notice-success"><p><strong>Migration Token ساخته شد.</strong> این مقدار را با نام <code>WORDPRESS_MIGRATION_TOKEN</code> در Railway ذخیره کن.</p><p><input dir="ltr" readonly onclick="this.select()" style="width:100%;max-width:760px;font-family:monospace" value="'.esc_attr($generatedToken).'"></p></div>';
        }

        $dataPercent = (int)$status['data_percent'];
        echo '<div class="bvp-mig-hero">';
        echo '<h2 id="bvp-mig-stage">مرحله '.($status['step'] ?: '—').' از ۶ — '.esc_html((string)$status['step_label']).'</h2>';
        echo '<p id="bvp-mig-stage-desc">'.esc_html((string)$status['step_description']).'</p>';
        echo '<div class="bvp-mig-progress"><span id="bvp-mig-progress-bar" style="width:'.$dataPercent.'%"></span></div>';
        echo '<p><strong id="bvp-mig-progress-text">پوشش داده‌ها: '.$dataPercent.'٪ · '.number_format_i18n((int)$status['covered_total']).' / '.number_format_i18n((int)$status['source_total']).' رکورد</strong></p>';
        echo '</div>';

        $steps = ['اسکن مبدا','انتقال اولیه','بررسی اولیه','Resync / ترمیم','بررسی نهایی','آماده Cutover'];
        echo '<div class="bvp-mig-stepper">';
        foreach ($steps as $i => $label) {
            $n = $i + 1;
            $class = $cutover || ($status['step'] > $n) ? 'done' : (($status['step'] === $n) ? 'active' : '');
            $icon = $cutover || ($status['step'] > $n) ? '✅' : (($status['step'] === $n) ? '🔵' : '⚪');
            echo '<div class="bvp-mig-step '.$class.'"><strong>'.$icon.' '.$n.'</strong><span>'.esc_html($label).'</span></div>';
        }
        echo '</div>';

        $etaText = '—';
        if ($status['eta_seconds'] !== null) {
            $sec = max(0,(int)$status['eta_seconds']);
            $etaText = $sec < 60 ? $sec.' ثانیه' : ($sec < 3600 ? ceil($sec/60).' دقیقه' : round($sec/3600,1).' ساعت');
        }
        echo '<div class="bvp-mig-metrics">';
        echo '<div class="bvp-mig-metric"><small>جدول‌های همگام</small><strong id="bvp-mig-table-count">'.(int)$status['tables_synced'].' از '.(int)$status['tables_total'].'</strong></div>';
        echo '<div class="bvp-mig-metric"><small>کسری واقعی</small><strong id="bvp-mig-missing">'.number_format_i18n((int)$status['missing_rows']).' رکورد</strong></div>';
        echo '<div class="bvp-mig-metric"><small>جدول جاری</small><strong id="bvp-mig-current">'.esc_html($status['current_table'] ?: '—').'</strong></div>';
        echo '<div class="bvp-mig-metric"><small>سرعت / ETA</small><strong id="bvp-mig-speed">'.($status['rows_per_second']>0?esc_html((string)$status['rows_per_second']).' ردیف/ث · ':'').esc_html($etaText).'</strong></div>';
        echo '</div>';

        $liveClass = !empty($status['last_error']) ? ' error' : (!empty($status['stalled']) ? ' warn' : '');
        echo '<div id="bvp-mig-live-box" class="bvp-mig-live'.$liveClass.'">';
        $needsAttention = in_array((string)($status['phase'] ?? ''), ['needs_attention','verification_failed','error'], true);
        echo '<strong id="bvp-mig-live-title">'.($cutover?'✅ انتقال تکمیل شده':($needsAttention?'🟠 نیاز به ترمیم دقیق':($autoActive?'🟢 Runner هوشمند فعال':'⏸ Runner متوقف/آماده'))).'</strong>';
        echo '<p id="bluevpn-migration-live">'.esc_html((string)($status['message'] ?: 'در انتظار شروع انتقال')).'</p>';
        if (!empty($status['stalled'])) echo '<p id="bvp-mig-stall"><strong>⚠️ بیش از ۳ دقیقه Progress جدید ثبت نشده.</strong> ادامه امن را بزن؛ Cursorها پاک نمی‌شوند.</p>'; else echo '<p id="bvp-mig-stall" style="display:none"></p>';
        if (!empty($status['last_error'])) echo '<p id="bvp-mig-error"><code>'.esc_html((string)$status['last_error']).'</code></p>'; else echo '<p id="bvp-mig-error" style="display:none"></p>';
        echo '<small>آخرین Progress: <span id="bvp-mig-last-progress">'.esc_html((string)($status['last_progress_at'] ?: '—')).'</span> · آخرین Verify: <span id="bvp-mig-last-verify">'.esc_html((string)($status['last_verified_at'] ?: '—')).'</span></small>';
        if (!empty($status['exact_repair_last_message'])) echo '<p style="margin-bottom:0"><small>🔎 '.esc_html((string)$status['exact_repair_last_message']).'</small></p>';
        echo '</div>';

        echo '<div class="bvp-mig-actions">';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('bluevpn_migration_auto_start'); echo '<input type="hidden" name="action" value="bluevpn_migration_auto_start">'; submit_button($cutover?'بررسی مجدد وضعیت':'▶ شروع / ادامه هوشمند','primary','submit',false); echo '</form>';
        if ($autoActive) { echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('bluevpn_migration_auto_stop'); echo '<input type="hidden" name="action" value="bluevpn_migration_auto_stop">'; submit_button('⏸ توقف موقت','secondary','submit',false); echo '</form>'; }
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('bluevpn_migration_test'); echo '<input type="hidden" name="action" value="bluevpn_migration_test">'; submit_button('تست اتصال Railway','secondary','submit',false); echo '</form>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('bluevpn_migration_resync'); echo '<input type="hidden" name="action" value="bluevpn_migration_resync">'; submit_button('ترمیم فقط اختلاف‌ها','secondary','submit',false); echo '</form>';
        echo '</div>';

        if ($autoActive && !$cutover) {
            $pumpNonce = wp_create_nonce('bluevpn_migration_pump');
            $ajaxUrl = admin_url('admin-ajax.php');
            echo '<script>(function(){const url='.wp_json_encode($ajaxUrl).';const nonce='.wp_json_encode($pumpNonce).';let stopped=false,failures=0;function t(id,v){const e=document.getElementById(id);if(e)e.textContent=v;}function render(d){t("bvp-mig-stage","مرحله "+(d.step||"—")+" از ۶ — "+(d.step_label||d.phase||""));t("bvp-mig-stage-desc",d.step_description||"");const bar=document.getElementById("bvp-mig-progress-bar");if(bar)bar.style.width=(d.data_percent||0)+"%";t("bvp-mig-progress-text","پوشش داده‌ها: "+(d.data_percent||0)+"٪ · "+(d.covered_total||0)+" / "+(d.source_total||0)+" رکورد");t("bvp-mig-table-count",(d.tables_synced||0)+" از "+(d.tables_total||0));t("bvp-mig-missing",(d.missing_rows||0)+" رکورد");t("bvp-mig-current",d.current_table||"—");let speed=d.rows_per_second>0?(d.rows_per_second+" ردیف/ث · "):"";let eta="—";if(d.eta_seconds!==null&&d.eta_seconds!==undefined){eta=d.eta_seconds<60?d.eta_seconds+" ثانیه":(d.eta_seconds<3600?Math.ceil(d.eta_seconds/60)+" دقیقه":(d.eta_seconds/3600).toFixed(1)+" ساعت");}t("bvp-mig-speed",speed+eta);t("bluevpn-migration-live",d.message||"در حال انتقال");t("bvp-mig-last-progress",d.last_progress_at||"—");t("bvp-mig-last-verify",d.last_verified_at||"—");const box=document.getElementById("bvp-mig-live-box");if(box)box.className="bvp-mig-live"+(d.last_error?" error":(d.stalled?" warn":""));const er=document.getElementById("bvp-mig-error");if(er){er.style.display=d.last_error?"block":"none";er.textContent=d.last_error||"";}const st=document.getElementById("bvp-mig-stall");if(st){st.style.display=d.stalled?"block":"none";st.textContent=d.stalled?"⚠️ بیش از ۳ دقیقه Progress جدید ثبت نشده؛ ادامه امن را بزن.":"";}if(d.tables){Object.keys(d.tables).forEach(function(name){const tr=document.querySelector("tr[data-mig-table=\""+CSS.escape(name)+"\"]");if(!tr)return;const x=d.tables[name]||{};const c=tr.querySelectorAll("[data-col]");c.forEach(function(el){const k=el.getAttribute("data-col");if(k==="source")el.textContent=x.source===null?"—":x.source;if(k==="local")el.textContent=x.local===null?"—":x.local;if(k==="missing")el.textContent=x.missing===null?"—":x.missing;if(k==="status")el.textContent=x.status==="synced"?"✅ همگام":(x.status==="error"?"❌ خطا":(x.status==="checking"?"⏳ بررسی":"⏳ نیاز به Sync"));if(k==="updated")el.textContent=x.updated_at||"—";if(k==="error")el.textContent=x.error||"";});});}}
            async function pump(){if(stopped)return;try{const body=new URLSearchParams();body.set("action","bluevpn_migration_pump");body.set("_ajax_nonce",nonce);const r=await fetch(url,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},body:body.toString()});const j=await r.json();if(!j||!j.success)throw new Error((j&&j.data&&j.data.message)||"پاسخ نامعتبر Runner");failures=0;const d=j.data||{};render(d);if(d.complete||d.stopped){stopped=true;if(d.complete)setTimeout(()=>location.reload(),1200);return;}setTimeout(pump,2500);}catch(e){failures++;t("bluevpn-migration-live","Runner مرورگر: "+e.message+" — تلاش مجدد "+failures);if(failures<12)setTimeout(pump,Math.min(15000,2000*failures));}}setTimeout(pump,900);})();</script>';
        }

        echo '<h2 style="margin-top:26px">وضعیت واقعی جدول‌ها</h2>';
        echo '<p class="description">این جدول فقط شمار واقعی مبدا/مقصد را نشان می‌دهد؛ شمارنده تکراری Resync دیگر به‌عنوان Progress نمایش داده نمی‌شود.</p>';
        echo '<div class="bvp-mig-table-wrap"><table class="widefat striped"><thead><tr><th>جدول</th><th>Railway</th><th>MySQL</th><th>کسری</th><th>وضعیت</th><th>آخرین تغییر</th><th>خطا</th></tr></thead><tbody>';
        foreach (BlueVPN_Migration::table_order() as $name) {
            $x = $status['tables'][$name];
            $label = $x['status']==='synced'?'✅ همگام':($x['status']==='error'?'❌ خطا':($x['status']==='checking'?'⏳ بررسی':'⏳ نیاز به Sync'));
            echo '<tr data-mig-table="'.esc_attr($name).'"><td><code>'.esc_html($name).'</code></td><td data-col="source">'.esc_html($x['source']===null?'—':(string)$x['source']).'</td><td data-col="local">'.esc_html($x['local']===null?'—':(string)$x['local']).'</td><td data-col="missing">'.esc_html($x['missing']===null?'—':(string)$x['missing']).'</td><td data-col="status">'.esc_html($label).'</td><td data-col="updated">'.esc_html($x['updated_at']?:'—').'</td><td data-col="error">'.esc_html($x['error']).'</td></tr>';
        }
        echo '</tbody></table></div>';

        echo '<div class="bvp-card" style="max-width:1180px;margin-top:18px"><h2>مرحله نهایی و Cutover</h2>';
        if ($cutover) {
            echo '<div class="notice notice-success inline"><p><strong>✅ مهاجرت آماده Cutover است.</strong> یک Resync ایمنی و Verify نهایی انجام شده و جدول‌ها Railway را پوشش می‌دهند. Railway خودکار خاموش نمی‌شود.</p></div>';
        } else {
            $missing = array_keys((array)($readiness['mismatches']??[]));
            $errors = array_keys((array)($readiness['table_errors']??[]));
            $why = $errors ? 'جدول‌های دارای خطا: '.implode(', ',$errors) : ($missing ? 'جدول‌های دارای کسری: '.implode(', ',$missing) : 'فرایند هنوز به Verify نهایی نرسیده است.');
            echo '<div class="notice notice-warning inline"><p><strong>⏳ هنوز Cutover نکن.</strong> '.esc_html($why).'</p></div>';
        }
        echo '<p>بعد از سبزشدن این بخش: اپ را با Endpoint وردپرس تست کن، سپس Railway را از مدار اصلی خارج کن. حذف Railway بخشی از این ابزار نیست.</p>';
        echo '</div>';

        echo '<details class="bvp-card" style="max-width:1180px;margin-top:16px"><summary style="cursor:pointer;font-weight:700;font-size:16px">تنظیمات اتصال و ابزارهای پیشرفته</summary><div style="padding-top:14px">';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('bluevpn_migration_save');
        echo '<input type="hidden" name="action" value="bluevpn_migration_save"><table class="form-table">';
        echo '<tr><th>Railway Backend URL</th><td><input class="regular-text" dir="ltr" name="source_url" placeholder="https://bluevpnapp-production.up.railway.app" value="'.esc_attr($cfg['source_url']).'"></td></tr>';
        echo '<tr><th>Migration Token</th><td><input class="regular-text" dir="ltr" type="password" name="token" value="" placeholder="'.(BlueVPN_Migration::has_token()?'ذخیره شده؛ برای تغییر مقدار جدید وارد کن':'Token را وارد کن').'"></td></tr>';
        echo '<tr><th>Batch Size</th><td><input type="number" min="100" max="5000" name="batch_size" value="'.(int)$cfg['batch_size'].'"><p class="description">برای جدول AI تا ۵۰۰۰ رکورد در هر درخواست استفاده می‌شود.</p></td></tr>';
        echo '<tr><th>SSL Verify</th><td><label><input type="checkbox" name="verify_tls" value="1" '.checked(!empty($cfg['verify_tls']),true,false).'> بررسی TLS</label></td></tr>';
        echo '<tr><th>انتقال خودکار</th><td><label><input type="checkbox" name="auto_migrate" value="1" '.checked(!empty($cfg['auto_migrate']),true,false).'> Runner و Retry خودکار فعال باشد</label></td></tr>';
        echo '<tr><th>Dual Sync</th><td><label><input type="checkbox" name="auto_sync" value="1" '.checked(!empty($cfg['auto_sync']),true,false).'> بعد از آمادگی Cutover، تغییرات جدید Railway هر ۵ دقیقه Sync شوند</label></td></tr>';
        echo '</table>'; submit_button('ذخیره تنظیمات Bridge'); echo '</form>';
        echo '<div class="bvp-mig-actions">';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('bluevpn_migration_generate_token'); echo '<input type="hidden" name="action" value="bluevpn_migration_generate_token">'; submit_button('ساخت Migration Token','secondary','submit',false); echo '</form>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('bluevpn_migration_manifest'); echo '<input type="hidden" name="action" value="bluevpn_migration_manifest">'; submit_button('بازخوانی Manifest','secondary','submit',false); echo '</form>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" onsubmit="return confirm(\'Progress و Cursorها از ابتدا شوند؟ داده‌های MySQL حذف نمی‌شوند.\')">'; wp_nonce_field('bluevpn_migration_reset'); echo '<input type="hidden" name="action" value="bluevpn_migration_reset">'; submit_button('ریست Progress (اضطراری)','delete','submit',false); echo '</form>';
        echo '</div></div></details>';
        echo '</div>';
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
            'verify_tls'=>$cfg['verify_tls'], 'auto_migrate'=>$cfg['auto_migrate'], 'auto_sync'=>$cfg['auto_sync']
        ]);
        set_transient('bluevpn_migration_token_once_'.get_current_user_id(), $token, 5 * MINUTE_IN_SECONDS);
        self::migration_redirect('Token ساخته و داخل WordPress ذخیره شد؛ آن را در Railway Variables ثبت کن.');
    }
    public static function migration_save(): void {
        self::guard(); check_admin_referer('bluevpn_migration_save');
        BlueVPN_Migration::save_settings([
            'source_url'=>wp_unslash($_POST['source_url']??''), 'token'=>wp_unslash($_POST['token']??''),
            'batch_size'=>(int)($_POST['batch_size']??1000), 'verify_tls'=>isset($_POST['verify_tls']),
            'auto_migrate'=>isset($_POST['auto_migrate']), 'auto_sync'=>isset($_POST['auto_sync'])
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
        self::guard(); check_admin_referer('bluevpn_migration_run'); BlueVPN_Migration::start_auto(); $r=BlueVPN_Migration::run(12);
        if (empty($r['success'])) self::migration_redirect('', implode(' | ', $r['errors']??['خطای نامشخص']));
        self::migration_redirect(!empty($r['complete'])?'این مرحله کامل شد؛ ادامه و Resync نهایی از اینجا به بعد خودکار است.':'انتقال خودکار فعال شد؛ '.(int)$r['rows_imported'].' رکورد همین حالا پردازش شد و ادامه بدون کلیک انجام می‌شود.');
    }
    public static function migration_resync(): void {
        self::guard();
        check_admin_referer('bluevpn_migration_resync');
        $manifest = BlueVPN_Migration::refresh_manifest();
        if (is_wp_error($manifest)) self::migration_redirect('', $manifest->get_error_message());
        $status = BlueVPN_Migration::dashboard_status(false);
        $targets = array_values(array_unique(array_merge(
            array_keys((array)($status['readiness']['mismatches'] ?? [])),
            array_keys((array)($status['readiness']['table_errors'] ?? []))
        )));
        if (!$targets) {
            BlueVPN_Migration::start_auto();
            BlueVPN_Migration::auto_step(4);
            self::migration_redirect('اختلاف شمارشی پیدا نشد؛ فرایند از مرحله Verify فعلی ادامه پیدا کرد.');
        }

        $exactImported = 0;
        $remainingTargets = [];
        foreach ($targets as $target) {
            $cmp = (array)($status['readiness']['comparison'][$target] ?? []);
            $sourceCount = (int)($cmp['source'] ?? 0);
            $localCount = (int)($cmp['local'] ?? 0);
            $gap = max(0, $sourceCount - $localCount);
            if ($gap > 0 && $gap <= 500 && $sourceCount <= 100000) {
                $exact = BlueVPN_Migration::repair_exact_missing($target, min(500, max(20, $gap + 20)));
                if (!empty($exact['success'])) {
                    $exactImported += (int)($exact['imported'] ?? 0);
                    continue;
                }
            }
            $remainingTargets[] = $target;
        }

        $manifest = BlueVPN_Migration::refresh_manifest();
        if (is_wp_error($manifest)) self::migration_redirect('', $manifest->get_error_message());
        $after = BlueVPN_Migration::dashboard_status(false);
        $still = array_values(array_unique(array_merge(
            array_keys((array)($after['readiness']['mismatches'] ?? [])),
            array_keys((array)($after['readiness']['table_errors'] ?? []))
        )));
        if (!$still) {
            BlueVPN_Migration::start_auto();
            BlueVPN_Migration::auto_step(2);
            self::migration_redirect('ترمیم دقیق موفق بود؛ '.$exactImported.' رکورد گمشده بازیابی شد و Verify نهایی ادامه دارد.');
        }

        BlueVPN_Migration::start_resync(false, $still, 'delta');
        BlueVPN_Migration::start_auto();
        BlueVPN_Migration::run(8);
        self::migration_redirect(($exactImported>0 ? $exactImported.' رکورد با ID دقیق بازیابی شد؛ ' : '').'برای '.count($still).' جدول باقی‌مانده ترمیم هدفمند ادامه دارد.');
    }
    public static function migration_auto_start(): void {
        self::guard();
        check_admin_referer('bluevpn_migration_auto_start');

        $before = BlueVPN_Migration::dashboard_status(false);
        if (!empty($before['readiness']['ready'])) {
            $manifest = BlueVPN_Migration::refresh_manifest();
            if (is_wp_error($manifest)) self::migration_redirect('', $manifest->get_error_message());
            $fresh = BlueVPN_Migration::dashboard_status(true);
            if (!empty($fresh['readiness']['ready'])) {
                self::migration_redirect('بررسی مجدد انجام شد؛ Railway از آخرین Verify رکورد جدیدی ندارد و Cutover همچنان آماده است.');
            }
        }

        BlueVPN_Migration::start_auto();
        BlueVPN_Migration::auto_step(4);
        $status = BlueVPN_Migration::dashboard_status(true);
        self::migration_redirect(!empty($status['readiness']['ready'])
            ? 'مهاجرت تکمیل است و Cutover آماده مانده.'
            : 'Runner هوشمند فعال شد و از آخرین Cursor معتبر ادامه می‌دهد؛ Progress قبلی پاک نشد.');
    }
    public static function migration_pump(): void {
        self::guard();
        check_ajax_referer('bluevpn_migration_pump');
        $cfg = BlueVPN_Migration::settings();
        if (empty($cfg['auto_migrate'])) {
            $status = BlueVPN_Migration::dashboard_status(true);
            $status['stopped'] = true;
            $status['complete'] = !empty($status['readiness']['ready']);
            wp_send_json_success($status);
        }

        // Short work slices keep mobile/shared-hosting requests responsive. A
        // concurrent WP-Cron invocation is ignored by the migration lock, while
        // this endpoint still returns fresh read-only status to the UI.
        BlueVPN_Migration::auto_step(3);
        $status = BlueVPN_Migration::dashboard_status(true);
        $status['stopped'] = false;
        $status['complete'] = !empty($status['readiness']['ready']);
        unset($status['readiness']);
        wp_send_json_success($status);
    }

    public static function migration_auto_stop(): void {
        self::guard(); check_admin_referer('bluevpn_migration_auto_stop');
        BlueVPN_Migration::stop_auto();
        self::migration_redirect('انتقال خودکار متوقف شد.');
    }

    public static function migration_reset(): void {
        self::guard(); check_admin_referer('bluevpn_migration_reset'); BlueVPN_Migration::reset(true); BlueVPN_Migration::mark_cutover_ready(false); self::migration_redirect('Progress مهاجرت ریست شد؛ داده‌های MySQL حذف نشدند.');
    }
    public static function migration_cutover(): void {
        self::guard(); check_admin_referer('bluevpn_migration_cutover');
        $ready=!empty($_POST['ready']);
        if ($ready && !BlueVPN_Migration::mark_cutover_ready(true)) {
            self::migration_redirect('', 'Cutover فقط بعد از Resync نهایی و تطبیق واقعی Railway/MySQL فعال می‌شود.');
        }
        BlueVPN_Migration::mark_cutover_ready($ready);
        self::migration_redirect($ready?'Cutover با بررسی ایمنی تأیید شد.':'آمادگی Cutover لغو شد.');
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
            $remote = (string)($release['base_version'] ?? $release['version'] ?? '0.0.0');
            $repair = BlueVPN_GitHub_Updater::update_available($release) && version_compare($remote, BLUEVPN_MANAGER_VERSION, '==');
            if (version_compare($remote, BLUEVPN_MANAGER_VERSION, '>')) {
                $releaseClass = 'bvp-warn'; $releaseNote = 'نسخه جدید موجود است';
            } elseif (version_compare($remote, BLUEVPN_MANAGER_VERSION, '<')) {
                $releaseClass = 'bvp-warn'; $releaseNote = 'Release گیت‌هاب از نسخه نصب‌شده عقب‌تر است';
            } elseif ($repair) {
                $releaseClass = 'bvp-warn'; $releaseNote = 'بسته اصلاحی جدید برای همین نسخه موجود است';
            } else {
                $releaseClass = 'bvp-ok'; $releaseNote = 'به‌روز است';
            }
            echo '<div class="bvp-card"><h3>آخرین Release افزونه</h3><p class="'.esc_attr($releaseClass).'"><strong>'.esc_html($remote).'</strong></p><small>'.esc_html($releaseNote).'</small></div>';
        } else {
            echo '<div class="bvp-card"><h3>آخرین Release افزونه</h3><p class="bvp-warn">هنوز Release مخصوص افزونه پیدا نشد.</p></div>';
        }
        echo '<div class="bvp-card"><h3>آپدیت خودکار</h3><p class="'.(!empty($cfg['auto_update'])?'bvp-ok':'bvp-warn').'">'.(!empty($cfg['auto_update'])?'فعال':'غیرفعال').'</p></div>';
        $last_bg = BlueVPN_GitHub_Updater::last_background_check();
        echo '<div class="bvp-card"><h3>بررسی خودکار GitHub</h3><p class="bvp-ok"><strong>هر ۵ دقیقه</strong></p><small>'.($last_bg ? 'آخرین اجرا: '.esc_html(wp_date('Y-m-d H:i:s', $last_bg)) : 'در انتظار اولین اجرای پس‌زمینه').'</small></div>';
        echo '</div>';

        echo '<div class="bvp-card" style="max-width:900px;margin-top:18px"><h2>تنظیمات Updater</h2>';
        echo '<p>بررسی نسخه جدید به‌صورت خودکار در پس‌زمینه انجام می‌شود و دکمه بررسی دستی فقط برای مواقع اضطراری است. برای اینکه Releaseهای Android با افزونه قاطی نشوند، فقط Tagهایی با پیشوند تعیین‌شده و Asset دقیق زیر پذیرفته می‌شوند.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('bluevpn_save_github_updater');
        echo '<input type="hidden" name="action" value="bluevpn_save_github_updater">';
        echo '<table class="form-table">';
        echo '<tr><th>GitHub Owner</th><td><input class="regular-text" dir="ltr" name="owner" value="'.esc_attr($cfg['owner']).'"></td></tr>';
        echo '<tr><th>Repository</th><td><input class="regular-text" dir="ltr" name="repo" value="'.esc_attr($cfg['repo']).'"></td></tr>';
        echo '<tr><th>Tag Prefix</th><td><input class="regular-text" dir="ltr" name="tag_prefix" value="'.esc_attr($cfg['tag_prefix']).'"><p class="description">مثال: bluevpn-manager-v4.0.0</p></td></tr>';
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
        echo '<p><strong>قرارداد Release:</strong> Tag باید مانند <code>bluevpn-manager-v4.0.0</code> و فایل Release باید <code>bluevpn-manager.zip</code> باشد.</p></div>';
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
        } else {
            $remote = (string)($release['base_version'] ?? $release['version'] ?? '0.0.0');
            if (version_compare($remote, BLUEVPN_MANAGER_VERSION, '>')) {
                $message = 'نسخه '.$remote.' موجود است؛ از صفحه افزونه‌ها قابل نصب است.';
            } elseif (version_compare($remote, BLUEVPN_MANAGER_VERSION, '<')) {
                $message = 'Release گیت‌هاب هنوز '.$remote.' است و از نسخه نصب‌شده '.BLUEVPN_MANAGER_VERSION.' عقب‌تر است.';
            } elseif (BlueVPN_GitHub_Updater::update_available($release)) {
                $message = 'بسته اصلاحی جدید برای نسخه '.$remote.' موجود است.';
            } else {
                $message = 'BlueVPN Manager به‌روز است ('.$remote.').';
            }
        }
        wp_safe_redirect(add_query_arg(['page'=>'bluevpn-github-updater','checked'=>$message], admin_url('admin.php')));
        exit;
    }
    public static function repair(): void { self::guard();check_admin_referer('bluevpn_repair');BlueVPN_DB::install_schema();BlueVPN_DB::seed_defaults();BlueVPN_Compat::register_rewrites();flush_rewrite_rules(false);wp_safe_redirect(admin_url('admin.php?page=bluevpn-migration&repaired=1'));exit; }
}
