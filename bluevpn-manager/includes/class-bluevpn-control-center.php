<?php
if (!defined('ABSPATH')) exit;

final class BlueVPN_Control_Center {
    private const TABS=[
        'overview'=>'نمای کلی','blueai'=>'BlueAI','ads'=>'تبلیغات','free'=>'اتصال رایگان','database'=>'دیتابیس','production'=>'سلامت و Backup','panels'=>'PasarGuard','marzban'=>'Marzban','guardcore'=>'GuardCore','guardcore-manual'=>'صف GuardCore','plans'=>'پلن‌ها','bluepay'=>'BluePay','manual'=>'فعال‌سازی دستی','customers'=>'کاربران','orders'=>'پرداخت‌ها','app'=>'اپ و آپدیت','sms'=>'SMS / OTP'
    ];
    private const PAGE_SLUGS=[
        'overview'=>'bluevpn-manager',
        'blueai'=>'bluevpn-blueai',
        'ads'=>'bluevpn-ads',
        'free'=>'bluevpn-free-access',
        'database'=>'bluevpn-database',
        'production'=>'bluevpn-production',
        'panels'=>'bluevpn-pasarguard',
        'marzban'=>'bluevpn-marzban',
        'guardcore'=>'bluevpn-guardcore',
        'guardcore-manual'=>'bluevpn-guardcore-queue',
        'plans'=>'bluevpn-plans',
        'bluepay'=>'bluevpn-bluepay',
        'manual'=>'bluevpn-manual',
        'customers'=>'bluevpn-customers',
        'orders'=>'bluevpn-orders',
        'app'=>'bluevpn-app-update',
        'sms'=>'bluevpn-sms',
    ];
    public static function init(): void {
        foreach([
            'bluevpn_cc_save_provider'=>'save_provider','bluevpn_cc_toggle_provider'=>'toggle_provider','bluevpn_cc_delete_provider'=>'delete_provider','bluevpn_cc_test_provider'=>'test_provider',
            'bluevpn_cc_save_payment'=>'save_payment','bluevpn_cc_save_sms'=>'save_sms','bluevpn_cc_refresh_sms_patterns'=>'refresh_sms_patterns','bluevpn_cc_smart_assign_sms_patterns'=>'smart_assign_sms_patterns','bluevpn_cc_save_sms_templates'=>'save_sms_templates','bluevpn_cc_test_sms_template'=>'test_sms_template','bluevpn_cc_process_sms'=>'process_sms','bluevpn_cc_broadcast_sms'=>'broadcast_sms','bluevpn_cc_retry_sms'=>'retry_sms','bluevpn_cc_sync_customer'=>'sync_customer','bluevpn_cc_repair_customer_providers'=>'repair_customer_providers','bluevpn_cc_save_plan_routing'=>'save_plan_routing',
            'bluevpn_cc_manual_activate'=>'manual_activate','bluevpn_cc_attach_guardcore'=>'attach_guardcore',
            'bluevpn_cc_refresh_guardcore_stats'=>'refresh_guardcore_stats',
            'bluevpn_cc_guardcore_refresh_catalog'=>'guardcore_refresh_catalog',
            'bluevpn_cc_guardcore_bootstrap_key'=>'guardcore_bootstrap_key',
            'bluevpn_cc_guardcore_subscription_action'=>'guardcore_subscription_action',
            'bluevpn_cc_guardcore_node_action'=>'guardcore_node_action',
            'bluevpn_cc_export_backup'=>'export_backup',
            'bluevpn_cc_create_private_backup'=>'create_private_backup','bluevpn_cc_restore_backup'=>'restore_backup','bluevpn_cc_finalize_cutover'=>'finalize_cutover',
            'bluevpn_cc_save_plan'=>'save_plan','bluevpn_cc_delete_plan'=>'delete_plan','bluevpn_cc_restore_plan'=>'restore_plan',
            'bluevpn_cc_revoke_device'=>'revoke_device','bluevpn_cc_revoke_session'=>'revoke_session','bluevpn_cc_logout_customer'=>'logout_customer','bluevpn_cc_set_customer_status'=>'set_customer_status',
            'bluevpn_cc_save_app_update_policy'=>'save_app_update_policy','bluevpn_cc_sync_app_release'=>'sync_app_release',
            'bluevpn_cc_release_promote'=>'release_promote','bluevpn_cc_release_stop'=>'release_stop','bluevpn_cc_release_resume'=>'release_resume','bluevpn_cc_release_force'=>'release_force'
        ] as $hook=>$method)add_action('admin_post_'.$hook,[self::class,$method]);
        add_action('wp_ajax_bluevpn_cc_repair_missing_provider_subscriptions',[self::class,'repair_missing_provider_subscriptions']);
        add_action('wp_ajax_bluevpn_cc_provider_access_catalog',[self::class,'provider_access_catalog']);
    }
    private static function guard(): void { if(!current_user_can('manage_options'))wp_die('دسترسی ندارید.'); }
    private static function url(string $tab,array $args=[]): string { $slug=self::PAGE_SLUGS[$tab]??self::PAGE_SLUGS['overview'];return add_query_arg(array_merge(['page'=>$slug],$args),admin_url('admin.php')); }
    private static function redirect(string $tab,string $message,bool $error=false): void { $key=$error?'cc_error':'cc_msg';wp_safe_redirect(self::url($tab,[$key=>$message]));exit; }
    private static function esc($v): string { return esc_html((string)$v); }
    private static function fmt_bytes($v): string { $n=max(0,(float)$v);foreach(['B','KB','MB','GB','TB'] as $u){if($n<1024||$u==='TB')return number_format($n,$n<10&&$u!=='B'?2:0).' '.$u;$n/=1024;}return '0 B'; }
    private static function tab(): string {
        $t=sanitize_key((string)($_GET['tab']??'overview'));
        return isset(self::TABS[$t])?$t:'overview';
    }
    public static function page(): void { self::render_section('overview'); }
    public static function render_section(string $tab): void {
        self::guard();
        if(!isset(self::TABS[$tab]))$tab='overview';
        $title=self::TABS[$tab];
        BlueVPN_Unified_UI::shell_open($title);
        echo '<div class="wrap bvc-wrap" dir="rtl"><style>
        .bvc-wrap{max-width:1450px}.bvc-hero{background:linear-gradient(135deg,#071b38,#13284f);color:#fff;border-radius:18px;padding:22px;margin:16px 0;display:flex;justify-content:space-between;gap:16px;align-items:center}.bvc-hero h1{color:#fff;margin:0 0 5px}.bvc-hero small{color:#b9c9e6}.bvc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:13px}.bvc-card{background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:16px;margin-bottom:14px}.bvc-card h2,.bvc-card h3{margin-top:0}.bvc-kpi strong{font-size:25px;display:block;margin-top:8px}.bvc-ok{color:#087c2c;font-weight:700}.bvc-bad{color:#b32d2e;font-weight:700}.bvc-warn{color:#9a6700;font-weight:700}.bvc-table{background:#fff;border-radius:12px;overflow:hidden}.bvc-table th,.bvc-table td{text-align:right;vertical-align:top}.bvc-table code{word-break:break-all}.bvc-actions{display:flex;gap:6px;flex-wrap:wrap}.bvc-form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}.bvc-form-grid label{display:block;font-weight:650}.bvc-form-grid input,.bvc-form-grid select,.bvc-form-grid textarea{width:100%;margin-top:5px}.bvc-note{background:#f0f6fc;border-right:4px solid #2271b1;padding:12px;border-radius:8px;margin:10px 0}.bvc-code{direction:ltr;text-align:left;background:#f6f7f7;padding:8px;border-radius:7px;word-break:break-all}.bvc-badge{display:inline-block;padding:3px 8px;border-radius:999px;background:#eef2f6}.bvc-danger{background:#fcf0f1;border-color:#d63638}.bvc-success{background:#edfaef;border-color:#00a32a}.bvc-section-home{display:inline-flex;align-items:center;gap:5px;color:#fff;text-decoration:none;margin-top:8px;font-weight:600}.bvc-section-home:hover{color:#dbeafe}@media(max-width:782px){.bvc-hero{display:block}.bvc-table{display:block;max-width:100%;overflow-x:auto;white-space:normal}}
        </style>';
        echo '<div class="bvc-hero"><div><h1>'.self::esc($title).'</h1><small>BlueVPN Control Center • نسخه '.self::esc(BLUEVPN_MANAGER_VERSION).' • WordPress/MySQL</small>';
        if($tab!=='overview')echo '<div><a class="bvc-section-home" href="'.esc_url(self::url('overview')).'">← بازگشت به نمای کلی</a></div>';
        echo '</div><div><span class="bvc-badge">'.self::esc(BlueVPN_Utils::tehran_datetime_fa()).'</span></div></div>';
        if(isset($_GET['cc_msg']))echo '<div class="notice notice-success"><p>'.self::esc(sanitize_text_field(wp_unslash($_GET['cc_msg']))).'</p></div>';
        if(isset($_GET['cc_error']))echo '<div class="notice notice-error"><p>'.self::esc(sanitize_text_field(wp_unslash($_GET['cc_error']))).'</p></div>';
        $method='tab_'.str_replace('-','_',$tab);
        if(method_exists(self::class,$method))self::{$method}();
        echo '</div>';
        BlueVPN_Unified_UI::shell_close();
    }
    private static function count(string $table,string $where='1=1'): int { global $wpdb;$t=BlueVPN_DB::table($table);return (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE {$where}"); }
    private static function tab_overview(): void {
        global $wpdb;$stats=[
            ['کاربران',self::count('customers')],['اشتراک فعال',self::count('customers',"active=1 AND subscription_status='active'")],['پرداخت موفق',self::count('orders',"status IN ('paid','activated','paid_needs_sync','partial_needs_sync')")],['پلن فعال',self::count('plans','active=1 AND deleted=0')],['Provider فعال',self::count('pasarguard_panels','active=1')+self::count('marzban_panels','active=1')+self::count('guardcore_panels','active=1')],['اتصال زنده',self::count('ai_live_connections','connected=1 AND verified=1')]
        ];
        echo '<div class="bvc-grid">';foreach($stats as [$l,$v])echo '<div class="bvc-card bvc-kpi"><span>'.self::esc($l).'</span><strong>'.number_format($v).'</strong></div>';echo '</div>';
        $db=BlueVPN_DB::status();$cut=get_option('bluevpn_manager_cutover_ready','0')==='1';$app=get_option('bluevpn_manager_app_cutover_enabled','0')==='1';
        echo '<div class="bvc-grid"><div class="bvc-card"><h3>دیتابیس</h3><p class="'.($db['ready']?'bvc-ok':'bvc-bad').'">'.($db['ready']?'✅ MySQL آماده':'❌ نیاز به تعمیر').'</p><small>Schema '.self::esc($db['schema_version']).'</small></div><div class="bvc-card"><h3>مهاجرت</h3><p class="'.($cut?'bvc-ok':'bvc-warn').'">'.($cut?'✅ Verify نهایی انجام شده':'⏳ هنوز آماده نیست').'</p></div><div class="bvc-card"><h3>Backend اپ</h3><p class="'.($app?'bvc-ok':'bvc-warn').'">'.($app?'WordPress انتخاب شده':'هنوز Cutover اپ تأیید نشده').'</p><div class="bvc-code">'.self::esc(untrailingslashit(home_url('/'))).'</div></div></div>';
        $orders=$wpdb->get_results("SELECT order_code,status,amount_toman,created_at FROM ".BlueVPN_DB::table('orders')." ORDER BY created_at DESC LIMIT 8",ARRAY_A);
        echo '<div class="bvc-card"><h2>آخرین پرداخت‌ها</h2><table class="widefat striped bvc-table"><tr><th>سفارش</th><th>مبلغ</th><th>وضعیت</th><th>زمان</th></tr>';foreach($orders as $x)echo '<tr><td>'.self::esc($x['order_code']).'</td><td>'.number_format((int)$x['amount_toman']).'</td><td>'.self::esc($x['status']).'</td><td>'.self::esc($x['created_at']).'</td></tr>';echo '</table></div>';
    }
    private static function tab_blueai(): void { BlueVPN_AI::render_admin(); }
    private static function tab_ads(): void { BlueVPN_Ads::render_admin(); }
    private static function tab_free(): void { BlueVPN_Ads::render_free_admin(); }
    private static function tab_database(): void {
        $s=BlueVPN_DB::status();$counts=BlueVPN_DB::counts();echo '<div class="bvc-grid"><div class="bvc-card"><h3>وضعیت</h3><p class="'.($s['ready']?'bvc-ok':'bvc-bad').'">'.($s['ready']?'✅ آماده':'❌ ناقص').'</p><small>MySQL '.self::esc($s['mysql_version']).'</small></div><div class="bvc-card"><h3>Schema</h3><strong>'.self::esc($s['schema_version']).'</strong></div><div class="bvc-card"><h3>Cutover</h3><strong>'.(get_option('bluevpn_manager_cutover_ready','0')==='1'?'آماده':'ناآماده').'</strong></div></div>';
        echo '<div class="bvc-card"><div class="bvc-actions"><a class="button" href="'.esc_url(admin_url('admin.php?page=bluevpn-migration')).'">ابزار مهاجرت</a><a class="button button-primary" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=bluevpn_cc_export_backup'),'bluevpn_cc_export_backup')).'">دانلود Backup JSON</a></div></div><table class="widefat striped bvc-table"><tr><th>جدول</th><th>تعداد</th></tr>';foreach($counts as $k=>$v)echo '<tr><td><code>'.self::esc($k).'</code></td><td>'.number_format((int)$v).'</td></tr>';echo '</table>';
    }
    private static function tab_production(): void {
        $health=BlueVPN_Production::health_summary();$backup=BlueVPN_Production::backup_status();$restore=BlueVPN_Production::restore_status();$finalized=get_option('bluevpn_manager_legacy_bridge_disabled','0')==='1';
        echo '<div class="bvc-grid">';
        echo '<div class="bvc-card bvc-kpi"><span>Production Health</span><strong>'.(int)$health['score'].'%</strong><small>'.(!empty($health['ok'])?'همه بررسی‌ها سبز است':'چند مورد نیازمند توجه است').'</small></div>';
        echo '<div class="bvc-card"><h3>Backup خودکار</h3><p class="'.(!empty($backup['ok'])?'bvc-ok':'bvc-warn').'">'.(!empty($backup['ok'])?'فعال و سالم':'هنوز Backup سالم ثبت نشده').'</p><small>'.self::esc($backup['created_at']??'—').'</small></div>';
        echo '<div class="bvc-card"><h3>Restore</h3><p class="'.(!empty($restore['ok'])?'bvc-ok':'bvc-warn').'">'.(!empty($restore['ok'])?'آخرین Restore موفق':'Restore اجرا نشده یا آخرین اجرا ناموفق بوده').'</p><small>'.self::esc($restore['at']??'—').'</small></div>';
        echo '<div class="bvc-card"><h3>Railway Bridge</h3><p class="'.($finalized?'bvc-ok':'bvc-warn').'">'.($finalized?'خاموش و نهایی شده':'هنوز برای Recovery نگه داشته شده').'</p></div>';
        echo '</div>';
        echo '<div class="bvc-card"><h2>بررسی‌های سلامت</h2><table class="widefat striped bvc-table"><tr><th>بخش</th><th>وضعیت</th><th>جزئیات</th></tr>';
        $labels=['database'=>'دیتابیس','sms_queue'=>'صف SMS','payments'=>'پرداخت‌ها','backup'=>'Backup','cron'=>'Cron','cutover'=>'Cutover اپ','providers'=>'Providerها'];
        foreach($health['checks'] as $key=>$row)echo '<tr><td>'.self::esc($labels[$key]??$key).'</td><td class="'.(!empty($row['ok'])?'bvc-ok':'bvc-bad').'">'.(!empty($row['ok'])?'✅ سالم':'⚠️ نیازمند توجه').'</td><td>'.self::esc($row['message']??'').'</td></tr>';
        echo '</table></div>';
        echo '<div class="bvc-grid"><div class="bvc-card"><h2>Backup خصوصی</h2><p>روزانه یک Snapshot از تمام جدول‌های BlueVPN ساخته می‌شود و ۷ نسخه اخیر نگه داشته می‌شود. قبل از هر Restore هم یک Backup خودکار گرفته می‌شود.</p>';
        if(!empty($backup['filename']))echo '<p><code>'.self::esc($backup['filename']).'</code><br><small>'.number_format((int)($backup['size']??0)).' bytes</small></p>';
        echo '<div class="bvc-actions"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_create_private_backup');echo '<input type="hidden" name="action" value="bluevpn_cc_create_private_backup"><button class="button button-primary">ساخت Backup همین حالا</button></form><a class="button" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=bluevpn_cc_export_backup'),'bluevpn_cc_export_backup')).'">دانلود Export JSON</a></div></div>';
        echo '<div class="bvc-card bvc-danger"><h2>Restore واقعی</h2><p>Restore تمام رکوردهای موجود در فایل را روی MySQL برمی‌گرداند. قبل از اجرا Snapshot فعلی خودکار ذخیره می‌شود.</p><form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_restore_backup');echo '<input type="hidden" name="action" value="bluevpn_cc_restore_backup"><p><input type="file" name="backup_file" accept="application/json,.json" required></p><p><label>برای تأیید بنویس <code>RESTORE BLUEVPN</code><input style="width:100%" name="confirm" required></label></p><button class="button button-primary">اعتبارسنجی و Restore</button></form></div></div>';
        if(!$finalized){
            echo '<div class="bvc-card bvc-danger"><h2>نهایی‌کردن انتقال از Railway</h2><p>فقط وقتی اپ روی WordPress تست شده و Cutover سبز است اجرا کن. این عملیات قبل از خاموش‌کردن Bridge یک Backup خصوصی می‌سازد، Cron مهاجرت را متوقف و Migration Token را پاک می‌کند.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_finalize_cutover');echo '<input type="hidden" name="action" value="bluevpn_cc_finalize_cutover"><label><input type="checkbox" name="confirmed" value="1" required> تأیید می‌کنم اپ از WordPress/MySQL استفاده می‌کند.</label><p><button class="button">نهایی‌کردن Production Cutover</button></p></form></div>';
        }
    }

    private static function provider_tab(string $provider): void {
        global $wpdb;$maps=['pasarguard'=>['pasarguard_panels','PasarGuard'],'marzban'=>['marzban_panels','Marzban'],'guardcore'=>['guardcore_panels','GuardCore']];[$table,$title]=$maps[$provider];$t=BlueVPN_DB::table($table);$rows=$wpdb->get_results("SELECT * FROM {$t} ORDER BY id DESC",ARRAY_A);$edit=(int)($_GET['edit']??0);$current=$edit?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d",$edit),ARRAY_A):[];
        echo '<div class="bvc-card"><h2>'.self::esc($title).' — افزودن / ویرایش</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_save_provider');echo '<input type="hidden" name="action" value="bluevpn_cc_save_provider"><input type="hidden" name="provider" value="'.self::esc($provider).'"><input type="hidden" name="id" value="'.(int)$edit.'"><div class="bvc-form-grid">';
        self::input('name','نام',$current['name']??'',true);self::input('base_url','Base URL',$current['base_url']??'',true);
        if($provider==='pasarguard'){self::select('auth_mode','روش ورود',['api_key'=>'API Key','password'=>'Username / Password'],$current['auth_mode']??'api_key');self::input('api_key','API Key','',false,'password');self::input('username','نام کاربری','',false);self::input('password','رمز','',false,'password');self::textarea('proxy_settings_json','Proxy Settings JSON',$current['proxy_settings_json']??'{"vless":{}}');}
        elseif($provider==='marzban'){self::input('username','نام کاربری مدیر','',false);self::input('password','رمز مدیر','',false,'password');self::textarea('proxies_json','Proxies JSON',$current['proxies_json']??'{}');self::textarea('inbounds_json','Inbounds JSON',$current['inbounds_json']??'{}');}
        else{
            self::select('auth_mode','حالت',['manual'=>'Manual','api_key'=>'API Key','password'=>'Username / Password'],$current['auth_mode']??'manual');
            self::input('global_subscription_url','Global Subscription',$current['global_subscription_url']??'');
            self::input('api_key','API Key','',false,'password');
            self::input('username','نام کاربری','');
            self::input('password','رمز','',false,'password');
            self::select('usage_unit','واحد limit_usage',['bytes'=>'Bytes','gb'=>'GB'],$current['usage_unit']??'bytes');
            self::select('expire_mode','واحد limit_expire',['days'=>'Days','seconds'=>'Seconds','timestamp'=>'Timestamp'],$current['expire_mode']??'days');
            if($edit){
                echo '<div class="bvc-note" style="grid-column:1/-1">GuardCore API 0.13: برای حساب‌های دارای TOTP، بعد از ذخیره Username/Password از دکمه «دریافت API Key با TOTP» استفاده کن. BlueVPN سپس برای عملیات خودکار از X-API-Key استفاده می‌کند.</div>';
            }
        }
        echo '<label><input type="checkbox" name="verify_tls" value="1" '.checked(!isset($current['verify_tls'])||(int)($current['verify_tls']??1)===1,true,false).'> SSL Verify</label><label><input type="checkbox" name="active" value="1" '.checked(!isset($current['active'])||(int)($current['active']??1)===1,true,false).'> فعال</label></div>';submit_button($edit?'ذخیره تغییرات':'افزودن پنل','primary','submit',false);echo '</form></div>';
        if($provider==='guardcore'&&$edit){
            echo '<div class="bvc-grid">';
            echo '<div class="bvc-card"><h3>GuardCore API 0.13</h3><p>نسخه شناسایی‌شده: <code>'.self::esc($current['api_version']??'نامشخص').'</code></p>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_guardcore_refresh_catalog_'.$edit);echo '<input type="hidden" name="action" value="bluevpn_cc_guardcore_refresh_catalog"><input type="hidden" name="panel_id" value="'.$edit.'"><button class="button button-primary">همگام‌سازی Service / Node / Stats</button></form></div>';
            echo '<div class="bvc-card"><h3>TOTP → API Key</h3><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_guardcore_bootstrap_key_'.$edit);echo '<input type="hidden" name="action" value="bluevpn_cc_guardcore_bootstrap_key"><input type="hidden" name="panel_id" value="'.$edit.'"><label>کد TOTP فعلی<input name="totp_code" inputmode="numeric" pattern="[0-9]{6,8}" maxlength="8" placeholder="123456"></label><p><button class="button">دریافت و ذخیره API Key</button></p></form></div>';
            echo '</div>';
        }
        echo '<table class="widefat striped bvc-table"><tr><th>ID</th><th>نام</th><th>URL</th><th>وضعیت</th><th>آخرین تست</th><th>عملیات</th></tr>';foreach($rows as $x){$test=wp_nonce_url(admin_url('admin-post.php?action=bluevpn_cc_test_provider&provider='.$provider.'&id='.(int)$x['id']),'bluevpn_cc_test_provider_'.$provider.'_'.$x['id']);$toggle=wp_nonce_url(admin_url('admin-post.php?action=bluevpn_cc_toggle_provider&provider='.$provider.'&id='.(int)$x['id']),'bluevpn_cc_toggle_provider_'.$provider.'_'.$x['id']);$delete=wp_nonce_url(admin_url('admin-post.php?action=bluevpn_cc_delete_provider&provider='.$provider.'&id='.(int)$x['id']),'bluevpn_cc_delete_provider_'.$provider.'_'.$x['id']);echo '<tr><td>'.(int)$x['id'].'</td><td>'.self::esc($x['name']).'</td><td><code>'.self::esc($x['base_url']).'</code></td><td>'.((int)$x['active']?'<span class="bvc-ok">فعال</span>':'<span class="bvc-bad">غیرفعال</span>').'</td><td>'.((int)$x['last_test_ok']?'<span class="bvc-ok">✅</span>':'<span class="bvc-bad">'.($x['last_test_at']?'❌':'—').'</span>').' '.self::esc($x['last_test_message']??'').'</td><td><div class="bvc-actions"><a class="button" href="'.esc_url(self::url($provider==='pasarguard'?'panels':$provider,['edit'=>(int)$x['id']])).'">ویرایش</a><a class="button" href="'.esc_url($test).'">تست</a><a class="button" href="'.esc_url($toggle).'">'.((int)$x['active']?'غیرفعال':'فعال').'‌کردن</a><a class="button button-link-delete" href="'.esc_url($delete).'" onclick="return confirm(&quot;این پنل از BlueVPN حذف شود؟ اتصال پلن‌ها و کاربران به این پنل نیز پاک می‌شود؛ کاربران فعال در اولین Sync/Provision به Provider جایگزین منتقل می‌شوند.&quot;)">حذف</a></div></td></tr>';}echo '</table>';
    }
    private static function input(string $name,string $label,$value='',bool $required=false,string $type='text'): void { echo '<label>'.self::esc($label).'<input type="'.$type.'" name="'.esc_attr($name).'" value="'.esc_attr((string)$value).'" '.($required?'required':'').'></label>'; }
    private static function textarea(string $name,string $label,$value=''): void { echo '<label>'.self::esc($label).'<textarea name="'.esc_attr($name).'" rows="4">'.self::esc($value).'</textarea></label>'; }
    private static function select(string $name,string $label,array $options,$value): void { echo '<label>'.self::esc($label).'<select name="'.esc_attr($name).'">';foreach($options as $k=>$v)echo '<option value="'.esc_attr($k).'" '.selected((string)$value,(string)$k,false).'>'.self::esc($v).'</option>';echo '</select></label>'; }
    private static function sms_pattern_select(string $name,string $label,string $selected,array $patterns,bool $includeEmpty=true): void {
        echo '<label>'.self::esc($label).'<select name="'.esc_attr($name).'" style="width:100%">';
        if($includeEmpty)echo '<option value="">— انتخاب پترن فعال —</option>';
        $found=false;
        foreach($patterns as $pattern){
            $code=trim((string)($pattern['code']??''));if($code==='')continue;
            if(hash_equals($selected,$code))$found=true;
            $vars=is_array($pattern['variables']??null)?$pattern['variables']:[];
            $summary=trim((string)($pattern['description']??''));if($summary==='')$summary=trim((string)($pattern['text']??''));
            $summary=mb_substr(preg_replace('/\s+/u',' ',$summary)?:'',0,72);
            $caption=$code.($summary!==''?' — '.$summary:'').($vars?' ['.implode(', ',$vars).']':'');
            echo '<option value="'.esc_attr($code).'" '.selected($selected,$code,false).'>'.self::esc($caption).'</option>';
        }
        if($selected!==''&&!$found)echo '<option value="'.esc_attr($selected).'" selected>⚠ ذخیره‌شده ولی در فهرست فعال نیست — '.self::esc($selected).'</option>';
        echo '</select></label>';
    }
    private static function reconcile_sms_pattern_selections(array $validCodes): int {
        if(!$validCodes)return 0;
        global $wpdb;$changed=0;
        $settingsTable=BlueVPN_DB::table('sms_settings');$settings=$wpdb->get_row("SELECT pattern_code,active FROM {$settingsTable} WHERE id=1",ARRAY_A)?:[];
        $selected=trim((string)($settings['pattern_code']??''));
        if($selected!==''&&!in_array($selected,$validCodes,true)){
            $wpdb->update($settingsTable,['pattern_code'=>'','active'=>0,'updated_at'=>BlueVPN_Utils::now_mysql()],['id'=>1]);$changed++;
        }
        $templateTable=BlueVPN_DB::table('sms_templates');$rows=$wpdb->get_results("SELECT `key`,pattern_code,enabled FROM {$templateTable}",ARRAY_A)?:[];
        foreach($rows as $row){$code=trim((string)($row['pattern_code']??''));if($code!==''&&!in_array($code,$validCodes,true)){$wpdb->update($templateTable,['pattern_code'=>'','enabled'=>0,'updated_at'=>BlueVPN_Utils::now_mysql()],['key'=>(string)$row['key']]);$changed++;}}
        return $changed;
    }
    private static function tab_panels(): void { self::provider_tab('pasarguard'); }
    private static function tab_marzban(): void { self::provider_tab('marzban'); }
    private static function tab_guardcore(): void {
        self::provider_tab('guardcore');
        self::guardcore_api_dashboard();
        self::guardcore_assignments_panel();
    }

    private static function guardcore_api_dashboard(): void {
        global $wpdb;
        $t=BlueVPN_DB::table('guardcore_panels');
        $panels=$wpdb->get_results("SELECT * FROM {$t} WHERE active=1 ORDER BY id",ARRAY_A)?:[];
        if(!$panels)return;

        echo '<div class="bvc-card" style="margin-top:18px"><h2>GuardCore API 0.13 — وضعیت زنده</h2>';
        foreach($panels as $panel){
            if(($panel['auth_mode']??'manual')==='manual')continue;
            $catalog=BlueVPN_Providers::guardcore_catalog((int)$panel['id'],false);
            echo '<div style="margin:14px 0;border:1px solid #dcdcde;border-radius:12px;padding:14px">';
            echo '<div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center"><div><strong>'.self::esc($panel['name']).'</strong> <code>#'.(int)$panel['id'].'</code><br><small>API: '.self::esc($catalog['version']??$panel['api_version']??'نامشخص').'</small></div>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_guardcore_refresh_catalog_'.(int)$panel['id']);echo '<input type="hidden" name="action" value="bluevpn_cc_guardcore_refresh_catalog"><input type="hidden" name="panel_id" value="'.(int)$panel['id'].'"><button class="button">Refresh API</button></form></div>';

            if(empty($catalog['ok'])){
                echo '<p class="bvc-bad">'.self::esc($catalog['message']??'خطای GuardCore').'</p></div>';
                continue;
            }

            $services=(array)($catalog['services']??[]);
            $nodes=(array)($catalog['nodes']??[]);
            $stats=(array)($catalog['stats']??[]);
            $sub=(array)($stats['subscriptions']??[]);
            $status=(array)($stats['status']??[]);
            $nodeStats=(array)($stats['nodes']??[]);
            $admin=(array)($stats['admin']??[]);

            echo '<div class="bvc-grid" style="margin-top:12px">';
            foreach([
                ['Serviceها',count($services)],
                ['Nodeها',count($nodes)],
                ['Subscription کل',(int)($sub['total']??0)],
                ['فعال',(int)($status['active']??$sub['active']??0)],
                ['آنلاین',(int)($status['online']??0)],
                ['مصرف کل',self::fmt_bytes((int)($status['total_usage']??$sub['total_usage']??0))],
                ['مصرف ۷ روز',self::fmt_bytes((int)(($stats['usage_7d']['total']??0)))],
            ] as $metric){
                echo '<div class="bvc-card"><strong>'.self::esc($metric[0]).'</strong><div style="font-size:22px;margin-top:4px">'.self::esc($metric[1]).'</div></div>';
            }
            echo '</div>';

            echo '<div class="bvc-grid">';
            echo '<div><h3>Serviceها</h3><table class="widefat striped bvc-table"><tr><th>ID</th><th>نام</th><th>Nodeها</th><th>کاربر</th></tr>';
            foreach($services as $service){
                echo '<tr><td><code>'.(int)($service['id']??0).'</code></td><td>'.self::esc($service['remark']??'').'</td><td>'.self::esc(implode(',',array_map('intval',(array)($service['node_ids']??[])))).'</td><td>'.(int)($service['users_count']??0).'</td></tr>';
            }
            echo '</table></div>';

            echo '<div><h3>Nodeها</h3><table class="widefat striped bvc-table"><tr><th>ID</th><th>نام</th><th>نوع</th><th>وضعیت</th><th>مصرف</th><th>عملیات</th></tr>';
            foreach($nodes as $node){
                $nodeId=(int)($node['id']??0);$enabled=!empty($node['enabled']);
                echo '<tr><td><code>'.$nodeId.'</code></td><td>'.self::esc($node['remark']??'').'</td><td>'.self::esc($node['category']??'').'</td><td>'.($enabled?'<span class="bvc-ok">فعال</span>':'<span class="bvc-bad">غیرفعال</span>').'</td><td>'.self::fmt_bytes((int)($node['current_usage']??0)).'</td><td>';
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_guardcore_node_action_'.(int)$panel['id'].'_'.$nodeId);echo '<input type="hidden" name="action" value="bluevpn_cc_guardcore_node_action"><input type="hidden" name="panel_id" value="'.(int)$panel['id'].'"><input type="hidden" name="node_id" value="'.$nodeId.'"><input type="hidden" name="enable" value="'.($enabled?'0':'1').'"><button class="button button-small">'.($enabled?'غیرفعال‌کردن':'فعال‌کردن').'</button></form>';
                echo '</td></tr>';
            }
            echo '</table></div>';
            echo '</div>';

            $reached=BlueVPN_Providers::guardcore_reached((int)$panel['id'],10);
            if($reached){
                echo '<h3>آخرین Subscriptionهای Limit/Expire شده</h3><table class="widefat striped bvc-table"><tr><th>Username</th><th>Limited</th><th>Expired</th><th>زمان</th></tr>';
                foreach($reached as $row){
                    echo '<tr><td><code>'.self::esc($row['username']??'').'</code></td><td>'.(!empty($row['limited'])?'بله':'خیر').'</td><td>'.(!empty($row['expired'])?'بله':'خیر').'</td><td>'.self::esc($row['reached_at']??'').'</td></tr>';
                }
                echo '</table>';
            }

            echo '<p><small>Admin: '.self::esc($admin['username']??'').' • Role: '.self::esc($admin['role']??'').' • Node active: '.(int)($nodeStats['active_nodes']??0).'/'.(int)($nodeStats['total_nodes']??count($nodes)).' • TOTP: '.(!empty($admin['totp_status'])?'فعال':'غیرفعال').'</small></p>';
            echo '</div>';
        }
        echo '</div>';
    }

    private static function guardcore_assignments_panel(): void {
        global $wpdb;
        $customers=BlueVPN_DB::table('customers');
        $panels=BlueVPN_DB::table('guardcore_panels');

        $rows=$wpdb->get_results(
            "SELECT c.id,c.email,c.phone,c.active,c.subscription_status,
                    c.guardcore_panel_id,c.guardcore_username,c.guardcore_subscription_id,
                    c.guardcore_subscription_url,c.guardcore_status,c.guardcore_expire,
                    c.guardcore_last_error,p.name panel_name,p.auth_mode,p.global_subscription_url
             FROM {$customers} c
             LEFT JOIN {$panels} p ON p.id=c.guardcore_panel_id
             WHERE c.guardcore_panel_id IS NOT NULL
                OR (c.guardcore_subscription_url IS NOT NULL AND TRIM(c.guardcore_subscription_url)<>'')
             ORDER BY c.id DESC
             LIMIT 500",
            ARRAY_A
        )?:[];

        $byPanel=[];
        foreach($rows as $row){
            $pid=(int)($row['guardcore_panel_id']??0);
            $key=$pid>0?(string)$pid:'manual-unbound';
            if(!isset($byPanel[$key]))$byPanel[$key]=[
                'panel_id'=>$pid,
                'panel_name'=>(string)($row['panel_name']??'GuardCore Manual'),
                'auth_mode'=>(string)($row['auth_mode']??'manual'),
                'global_url'=>(string)($row['global_subscription_url']??''),
                'users'=>[],
            ];
            $stats=BlueVPN_Providers::subscription_snapshot_stats((int)$row['id']);
            $row['_snapshot']=$stats;
            $byPanel[$key]['users'][]=$row;
        }

        echo '<div class="bvc-card" style="margin-top:18px">';
        echo '<div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap">';
        echo '<div><h2 style="margin-bottom:4px">آمار تخصیص GuardCore</h2><p style="margin:0">تعداد کانفیگ‌های آخرین Snapshot و کاربرانی که هر Subscription به آن‌ها اختصاص داده شده است.</p></div>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('bluevpn_cc_refresh_guardcore_stats');
        echo '<input type="hidden" name="action" value="bluevpn_cc_refresh_guardcore_stats">';
        echo '<button class="button button-primary">بروزرسانی آمار GuardCore</button></form></div>';

        if(!$byPanel){
            echo '<div class="bvc-note" style="margin-top:14px">هنوز هیچ کاربری به GuardCore متصل نشده است.</div></div>';
            return;
        }

        foreach($byPanel as $group){
            $users=(array)$group['users'];
            $counts=[];
            $fresh=0;
            foreach($users as $u){
                $st=(array)$u['_snapshot'];
                if(!empty($st['updated_at']))$fresh++;
                if(!empty($st['guardcore_ok']))$counts[]=(int)$st['guardcore_count'];
            }
            $globalCount=$counts?max($counts):0;
            $sameGlobal=(string)$group['global_url']!=='';

            echo '<div style="margin-top:16px;border:1px solid #dcdcde;border-radius:12px;overflow:hidden">';
            echo '<div style="padding:12px 14px;background:#f6f7f7;display:flex;gap:16px;justify-content:space-between;align-items:center;flex-wrap:wrap">';
            echo '<div><strong>'.self::esc($group['panel_name']).'</strong> <code>#'.(int)$group['panel_id'].'</code><br><small>حالت: '.self::esc($group['auth_mode']).'</small></div>';
            echo '<div><strong>'.count($users).'</strong> کاربر • ';
            if($sameGlobal){
                echo '<strong>'.(int)$globalCount.'</strong> کانفیگ در Global Subscription';
            }else{
                echo '<strong>'.count($counts).'</strong> Snapshot دارای آمار';
            }
            echo '</div></div>';

            echo '<table class="widefat striped bvc-table"><thead><tr>';
            echo '<th>کاربر</th><th>Username</th><th>Subscription ID</th><th>وضعیت</th><th>کانفیگ GuardCore</th><th>کل کانفیگ تجمیعی</th><th>آخرین Snapshot</th><th>عملیات API</th><th>خطا</th>';
            echo '</tr></thead><tbody>';

            foreach($users as $u){
                $st=(array)$u['_snapshot'];
                $label=trim((string)($u['phone']?:$u['email']))?:('#'.(int)$u['id']);
                $updated=(int)($st['updated_at']??0);
                $gcCount=(int)($st['guardcore_count']??0);
                $total=(int)($st['total_count']??0);
                $gcOk=!empty($st['guardcore_ok']);

                echo '<tr>';
                echo '<td>#'.(int)$u['id'].' '.self::esc($label).'</td>';
                echo '<td><code>'.self::esc($u['guardcore_username']).'</code></td>';
                echo '<td>'.(!empty($u['guardcore_subscription_id'])?'<code>'.(int)$u['guardcore_subscription_id'].'</code>':'—').'</td>';
                echo '<td>'.self::esc($u['guardcore_status']).'</td>';
                echo '<td>'.($updated?($gcOk?'<strong>'.(int)$gcCount.'</strong>':'<span class="bvc-bad">ناموفق</span>'):'در انتظار Snapshot').'</td>';
                echo '<td>'.($updated?'<strong>'.(int)$total.'</strong>':'—').'</td>';
                echo '<td>'.($updated?esc_html(wp_date('Y-m-d H:i:s',$updated)):'—').'</td>';
                echo '<td>';
                if((int)$group['panel_id']>0&&($group['auth_mode']??'manual')!=='manual'&&!empty($u['guardcore_username'])){
                    echo '<a class="button button-small" style="margin:2px" href="'.esc_url(self::url('guardcore',['gc_panel'=>(int)$group['panel_id'],'gc_user'=>(string)$u['guardcore_username']])).'">جزئیات</a>';
                    foreach(['enable'=>'فعال','disable'=>'غیرفعال','revoke'=>'Revoke','reset'=>'Reset'] as $op=>$labelOp){
                        echo '<form style="display:inline-block;margin:2px" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
                        wp_nonce_field('bluevpn_cc_guardcore_subscription_action_'.(int)$group['panel_id'].'_'.(int)$u['id']);
                        echo '<input type="hidden" name="action" value="bluevpn_cc_guardcore_subscription_action"><input type="hidden" name="panel_id" value="'.(int)$group['panel_id'].'"><input type="hidden" name="customer_id" value="'.(int)$u['id'].'"><input type="hidden" name="username" value="'.esc_attr((string)$u['guardcore_username']).'"><input type="hidden" name="operation" value="'.esc_attr($op).'"><button class="button button-small">'.esc_html($labelOp).'</button></form>';
                    }
                }else echo '—';
                echo '</td>';
                echo '<td>'.(!empty($u['guardcore_last_error'])?'<span class="bvc-bad">'.self::esc(mb_substr((string)$u['guardcore_last_error'],0,120)).'</span>':'—').'</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        }
        $inspectPanel=(int)($_GET['gc_panel']??0);
        $inspectUser=sanitize_text_field((string)($_GET['gc_user']??''));
        if($inspectPanel>0&&$inspectUser!==''){
            $detail=BlueVPN_Providers::guardcore_subscription_detail($inspectPanel,$inspectUser);
            echo '<div class="bvc-card" style="margin-top:16px"><h2>جزئیات GuardCore — <code>'.self::esc($inspectUser).'</code></h2>';
            if(empty($detail['ok']))echo '<p class="bvc-bad">'.self::esc($detail['message']??'خطا').'</p>';
            else{
                $d=(array)$detail['subscription'];
                echo '<div class="bvc-grid">';
                foreach([
                    ['وضعیت',$d['status']??''],
                    ['آنلاین',!empty($d['is_online'])?'بله':'خیر'],
                    ['آخرین درخواست',$d['last_request_at']??'—'],
                    ['Agent',$d['last_client_agent']??'—'],
                    ['مصرف جاری',self::fmt_bytes((int)($d['used_traffic']??0))],
                    ['مصرف کل',self::fmt_bytes((int)($d['total_usage']??0))],
                    ['انقضا',$d['expire']??'—'],
                    ['Serviceها',implode(',',array_map('intval',(array)($d['service_ids']??[])))],
                ] as $metric)echo '<div class="bvc-card"><strong>'.self::esc($metric[0]).'</strong><div>'.self::esc($metric[1]).'</div></div>';
                echo '</div>';
                $logs=(array)($detail['usages']??[]);
                if($logs){echo '<h3>Usage Log</h3><table class="widefat striped bvc-table"><tr><th>زمان</th><th>مصرف</th></tr>';foreach(array_slice(array_reverse($logs),0,30) as $log)echo '<tr><td>'.self::esc($log['created_at']??'').'</td><td>'.self::fmt_bytes((int)($log['usage']??0)).'</td></tr>';echo '</table>';}
            }
            echo '</div>';
        }
        echo '</div>';
    }

    private static function tab_guardcore_manual(): void {
        global $wpdb;$t=BlueVPN_DB::table('customers');$rows=$wpdb->get_results("SELECT id,email,phone,guardcore_username,guardcore_status,guardcore_subscription_url,guardcore_last_error FROM {$t} WHERE guardcore_status IN ('manual_pending','pending') OR (guardcore_panel_id IS NOT NULL AND guardcore_subscription_url='') ORDER BY id DESC LIMIT 100",ARRAY_A);
        echo '<div class="bvc-note">برای GuardCoreهای Manual، لینک اشتراک را اینجا ثبت کن؛ بعد وضعیت کاربر Active می‌شود.</div><table class="widefat striped bvc-table"><tr><th>کاربر</th><th>Username</th><th>وضعیت</th><th>لینک</th></tr>';foreach($rows as $x){echo '<tr><td>#'.(int)$x['id'].' '.self::esc($x['phone']?:$x['email']).'</td><td><code>'.self::esc($x['guardcore_username']).'</code></td><td>'.self::esc($x['guardcore_status']).'</td><td><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_attach_guardcore_'.$x['id']);echo '<input type="hidden" name="action" value="bluevpn_cc_attach_guardcore"><input type="hidden" name="customer_id" value="'.(int)$x['id'].'"><input class="regular-text" name="subscription_url" value="'.esc_attr((string)$x['guardcore_subscription_url']).'"> <button class="button button-primary">ثبت</button></form></td></tr>';}echo '</table>';
    }
    private static function access_picker(string $provider,string $panelSelectName,array $selected=[]): void {
        $isPg=$provider==='pasarguard';$title=$isPg?'گروه‌های PasarGuard':'Inboundهای Marzban';$hint=$isPg?'گروه':'Inbound';
        $selected=array_values(array_unique(array_filter(array_map('strval',$selected),static fn($v)=>$v!=='')));
        echo '<div class="bvc-access-picker" data-bluevpn-access-picker data-provider="'.esc_attr($provider).'" data-panel-select="'.esc_attr($panelSelectName).'" data-selected="'.esc_attr(wp_json_encode($selected,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)).'">';if($isPg)echo '<input type="hidden" name="pasarguard_access_picker_present" value="1">';
        echo '<div class="bvc-access-picker-head"><div><strong>'.self::esc($title).'</strong><small>پنل را انتخاب کن و «دریافت لیست» را بزن. اگر هیچ موردی انتخاب نشود، همه '.$hint.'‌های فعال استفاده می‌شوند.</small></div><button type="button" class="button" data-access-load>دریافت لیست</button></div>';
        echo '<div class="bvc-access-status" data-access-status>'.($selected?'انتخاب ذخیره‌شده: '.count($selected).' مورد':'حالت خودکار: همه موارد فعال').'</div>';
        echo '<div class="bvc-access-items" data-access-items>';
        foreach($selected as $value){
            $name=$isPg?'group_ids_selected[]':'marzban_inbounds_selected[]';
            echo '<label class="bvc-access-chip is-saved"><input type="checkbox" name="'.esc_attr($name).'" value="'.esc_attr($value).'" checked><span>'.self::esc($value).'</span><small>ذخیره‌شده</small></label>';
        }
        echo '</div></div>';
    }
    private static function guardcore_service_picker(string $panelSelectName,array $panels,array $selected=[]): void {
        $selected=array_values(array_unique(array_filter(array_map('intval',$selected),static fn($v)=>$v>0)));
        $catalog=[];
        foreach($panels as $panel){
            $services=BlueVPN_Utils::json_decode_array((string)($panel['services_json']??''),[]);
            $catalog[(int)$panel['id']]=array_values(array_map(static function($service){
                return [
                    'id'=>(int)($service['id']??0),
                    'remark'=>(string)($service['remark']??'Service'),
                    'users_count'=>(int)($service['users_count']??0),
                ];
            },$services));
        }
        $uid='gc_picker_'.substr(md5($panelSelectName.'|'.wp_json_encode($selected).'|'.wp_rand()),0,8);
        echo '<div id="'.esc_attr($uid).'" class="bvc-access-picker" data-gc-service-picker data-panel-select="'.esc_attr($panelSelectName).'" data-selected="'.esc_attr(wp_json_encode($selected)).'" data-catalog="'.esc_attr(wp_json_encode($catalog,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)).'">';
        echo '<div class="bvc-access-picker-head"><div><strong>Serviceهای GuardCore</strong><small>از API رسمی GuardCore دریافت می‌شوند. Serviceهای این پلن را انتخاب کن.</small></div></div>';
        echo '<div class="bvc-access-items" data-gc-items></div></div>';
        echo '<script>(function(){var root=document.getElementById('.wp_json_encode($uid).');if(!root)return;var form=root.closest("form");var select=form&&form.querySelector("select[name=\\"'.esc_js($panelSelectName).'\\"]");var items=root.querySelector("[data-gc-items]");var catalog=JSON.parse(root.dataset.catalog||"{}");var selected=(JSON.parse(root.dataset.selected||"[]")||[]).map(String);function render(){items.innerHTML="";var rows=catalog[String(select?select.value:0)]||[];if(!rows.length){items.innerHTML="<span class=\\"bvc-note\\">Service ذخیره‌شده‌ای برای این پنل نیست؛ ابتدا GuardCore را Refresh API کن.</span>";return;}rows.forEach(function(x){var l=document.createElement("label");l.className="bvc-access-chip";var c=document.createElement("input");c.type="checkbox";c.name="guardcore_service_ids_selected[]";c.value=String(x.id);c.checked=selected.indexOf(String(x.id))>=0;var span=document.createElement("span");span.textContent=x.remark+" (#"+x.id+")";var small=document.createElement("small");small.textContent=(x.users_count||0)+" کاربر";l.append(c,span,small);items.appendChild(l);});}if(select)select.addEventListener("change",function(){selected=[];render();});render();})();</script>';
    }

    private static function posted_ids(string $arrayName,string $legacyName): array {
        $raw=$_POST[$arrayName]??null;$values=[];
        if(is_array($raw))$values=$raw;elseif($arrayName==='group_ids_selected'&&isset($_POST['pasarguard_access_picker_present']))$values=[];else{$legacy=(string)wp_unslash($_POST[$legacyName]??'');$values=preg_split('/[,\s]+/',$legacy)?:[];}
        $out=[];foreach($values as $v){$n=(int)$v;if($n>0&&!in_array($n,$out,true))$out[]=$n;}return array_slice($out,0,200);
    }
    private static function posted_marzban_inbounds(): array {
        $raw=$_POST['marzban_inbounds_selected']??[];if(!is_array($raw))return [];$out=[];
        foreach($raw as $token){$token=(string)wp_unslash($token);$parts=explode('|',$token,2);if(count($parts)!==2)continue;$proto=sanitize_key($parts[0]);$tag=sanitize_text_field($parts[1]);if(!in_array($proto,['vless','vmess','trojan','shadowsocks'],true)||$tag==='')continue;if(!in_array($tag,$out[$proto]??[],true))$out[$proto][]=$tag;}
        return $out;
    }
    public static function provider_access_catalog(): void {
        self::guard();check_ajax_referer('bluevpn_provider_access_catalog','nonce');$provider=sanitize_key((string)($_POST['provider']??''));$id=max(0,(int)($_POST['panel_id']??0));
        if(!in_array($provider,['pasarguard','marzban'],true)||$id<=0)wp_send_json_error(['message'=>'ابتدا یک پنل معتبر انتخاب کن.'],400);
        try{$catalog=BlueVPN_Providers::access_catalog($provider,$id,12);wp_send_json_success($catalog);}catch(Throwable $e){wp_send_json_error(['message'=>mb_substr($e->getMessage(),0,500)],502);}
    }

    private static function tab_plans(): void {
        global $wpdb;
        $p=BlueVPN_DB::table('plans');$pg=BlueVPN_DB::table('pasarguard_panels');$mz=BlueVPN_DB::table('marzban_panels');$gc=BlueVPN_DB::table('guardcore_panels');
        $pgRows=$wpdb->get_results("SELECT id,name,active FROM {$pg} ORDER BY name,id",ARRAY_A);
        $mzRows=$wpdb->get_results("SELECT id,name,active FROM {$mz} ORDER BY name,id",ARRAY_A);
        $gcRows=$wpdb->get_results("SELECT id,name,auth_mode,active,services_json FROM {$gc} ORDER BY name,id",ARRAY_A);
        $rows=$wpdb->get_results("SELECT p.*,pg.name pg_name,mz.name mz_name,gc.name gc_name,gc.auth_mode gc_auth_mode FROM {$p} p LEFT JOIN {$pg} pg ON pg.id=p.panel_id LEFT JOIN {$mz} mz ON mz.id=p.marzban_panel_id LEFT JOIN {$gc} gc ON gc.id=p.guardcore_panel_id WHERE p.deleted=0 ORDER BY p.sort_order,p.id",ARRAY_A);
        $deletedRows=$wpdb->get_results("SELECT id,title,deleted_at FROM {$p} WHERE deleted=1 ORDER BY deleted_at DESC,id DESC LIMIT 50",ARRAY_A)?:[];

        $select=function(string $name,array $items,$current=0,string $none='بدون Provider'){
            echo '<select name="'.esc_attr($name).'"><option value="0">'.esc_html($none).'</option>';
            foreach($items as $x){
                echo '<option value="'.(int)$x['id'].'" '.selected((int)$current,(int)$x['id'],false).'>'.esc_html($x['name']).((int)$x['active']?'':' • غیرفعال').'</option>';
            }
            echo '</select>';
        };
        $provider_badge=function(string $label,$name){
            if(!$name)return '<span class="bvc-provider-pill is-empty">'.$label.': خاموش</span>';
            return '<span class="bvc-provider-pill is-on">'.$label.': '.esc_html((string)$name).'</span>';
        };

        echo '<div class="bvc-page-tools">';
        echo '<div><h2 class="bvc-section-title">مدیریت پلن‌ها</h2><p class="bvc-section-subtitle">پلن، قیمت و مسیر Providerها را بدون جدول شلوغ مدیریت کن.</p></div>';
        echo '<a class="button button-primary bvc-primary-action" href="#bvc-new-plan">＋ پلن جدید</a>';
        echo '</div>';

        echo '<details id="bvc-new-plan" class="bvc-card bvc-disclosure bvc-new-plan" '.(!$rows?'open':'').'>';
        echo '<summary><span><strong>افزودن پلن جدید</strong><small>مشخصات فروش، محدودیت‌ها و مسیر Provider</small></span><span class="bvc-summary-chevron">⌄</span></summary>';
        echo '<div class="bvc-disclosure-body"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('bluevpn_add_plan');
        echo '<input type="hidden" name="action" value="bluevpn_add_plan">';

        echo '<div class="bvc-form-section"><div class="bvc-form-section-head"><strong>مشخصات اصلی</strong><small>اطلاعاتی که کاربر در اپ می‌بیند</small></div><div class="bvc-form-grid bvc-form-grid-compact">';
        self::input('title','عنوان پلن','',true);
        self::input('price_toman','قیمت (تومان)','',true,'number');
        self::input('duration_days','اعتبار (روز)','30',true,'number');
        self::input('data_limit_gb','حجم (GB؛ صفر = نامحدود)','0',false,'number');
        self::input('device_limit','تعداد دستگاه','1',false,'number');
        self::input('sort_order','ترتیب نمایش','0',false,'number');
        echo '</div></div>';

        echo '<div class="bvc-form-section"><div class="bvc-form-section-head"><strong>مسیر سرویس</strong><small>Providerهای مورد استفاده برای این پلن</small></div><div class="bvc-form-grid">';
        echo '<label>PasarGuard';$select('panel_id',$pgRows,0);echo '</label>';
        echo '<label>Marzban';$select('marzban_panel_id',$mzRows,0);echo '</label>';
        echo '<label>GuardCore';$select('guardcore_panel_id',$gcRows,0);echo '</label>';
        self::select('multi_provider_quota_mode','نحوه اعمال حجم',['split'=>'تقسیم حجم بین Providerها','full'=>'حجم کامل روی هر Provider'],'split');
        echo '</div>';self::access_picker('pasarguard','panel_id',[]);self::access_picker('marzban','marzban_panel_id',[]);self::guardcore_service_picker('guardcore_panel_id',$gcRows,[]);echo '</div>';

        echo '<details class="bvc-advanced-options"><summary>تنظیمات پیشرفته Provider</summary><div class="bvc-helper">GuardCore Serviceها از API رسمی پنل خوانده می‌شوند؛ ID دستی دیگر لازم نیست.</div></details>';

        echo '<div class="bvc-form-section"><label class="bvc-field-full">توضیحات<textarea name="description" rows="3" placeholder="توضیح کوتاه برای نمایش یا مدیریت پلن"></textarea></label></div>';
        echo '<div class="bvc-form-actions"><button class="button button-primary">افزودن پلن</button></div>';
        echo '</form></div></details>';

        if(!$rows){
            echo '<div class="bvc-empty-state"><strong>پلن فعالی در لیست وجود ندارد.</strong><span>از «پلن جدید» شروع کن یا یک پلن حذف‌شده را بازیابی کن.</span></div>';
        }

        if($rows) echo '<div class="bvc-plan-list">';
        foreach($rows as $x){
            $id=(int)$x['id'];
            $toggle=wp_nonce_url(admin_url('admin-post.php?action=bluevpn_toggle_plan&id='.$id),'bluevpn_toggle_plan_'.$id);
            $serviceIds=implode(',',array_map('intval',BlueVPN_Utils::json_decode_array((string)($x['guardcore_service_ids_json']??''),[])));
            $groups=implode(',',array_map('intval',BlueVPN_Utils::json_decode_array((string)($x['group_ids_json']??''),[])));
            $groupSelected=array_map('strval',BlueVPN_Utils::json_decode_array((string)($x['group_ids_json']??''),[]));
            $mzSelected=[];foreach(BlueVPN_Utils::json_decode_array((string)($x['marzban_inbounds_json']??''),[]) as $proto=>$tags)if(is_array($tags))foreach($tags as $tag)$mzSelected[]=(string)$proto.'|'.(string)$tag;
            $quotaMode=(string)($x['multi_provider_quota_mode']??'split');
            $active=(int)$x['active']===1;

            echo '<article class="bvc-plan-card '.($active?'is-active':'is-inactive').'">';
            echo '<header class="bvc-plan-head"><div class="bvc-plan-title-wrap">';
            echo '<span class="bvc-plan-id">#'.$id.'</span><div><h3>'.self::esc($x['title']).'</h3>';
            if(trim((string)$x['description'])!=='')echo '<p>'.self::esc($x['description']).'</p>';
            echo '</div></div>';
            echo '<span class="bvc-status-pill '.($active?'is-active':'is-inactive').'">'.($active?'فعال':'غیرفعال').'</span></header>';

            echo '<div class="bvc-plan-metrics">';
            echo '<div><span>قیمت</span><strong>'.number_format((int)$x['price_toman']).' <small>تومان</small></strong></div>';
            echo '<div><span>اعتبار</span><strong>'.(int)$x['duration_days'].' <small>روز</small></strong></div>';
            echo '<div><span>حجم</span><strong>'.((int)$x['data_limit_gb']>0?(int)$x['data_limit_gb'].' GB':'نامحدود').'</strong></div>';
            echo '<div><span>دستگاه</span><strong>'.(int)$x['device_limit'].'</strong></div>';
            echo '</div>';

            echo '<div class="bvc-plan-providers">';
            echo $provider_badge('PG',$x['pg_name']??'');
            echo $provider_badge('MZ',$x['mz_name']??'');
            echo $provider_badge('GC',$x['gc_name']??'');
            echo '<span class="bvc-provider-pill is-neutral">Quota: '.($quotaMode==='full'?'Full':'Split').'</span>';
            echo '</div>';

            echo '<details class="bvc-plan-routing">';
            echo '<summary><span>تنظیم مسیر و Provider</span><span class="bvc-summary-chevron">⌄</span></summary>';
            echo '<div class="bvc-plan-routing-body"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('bluevpn_cc_save_plan_routing_'.$id);
            echo '<input type="hidden" name="action" value="bluevpn_cc_save_plan_routing"><input type="hidden" name="plan_id" value="'.$id.'">';

            echo '<div class="bvc-form-grid">';
            echo '<label>PasarGuard';$select('panel_id',$pgRows,(int)$x['panel_id']);echo '</label>';
            echo '<label>Marzban';$select('marzban_panel_id',$mzRows,(int)$x['marzban_panel_id']);echo '</label>';
            echo '<label>GuardCore';$select('guardcore_panel_id',$gcRows,(int)$x['guardcore_panel_id']);echo '</label>';
            echo '<label>نحوه اعمال حجم<select name="multi_provider_quota_mode"><option value="split" '.selected($quotaMode,'split',false).'>تقسیم بین Providerها</option><option value="full" '.selected($quotaMode,'full',false).'>حجم کامل روی هر Provider</option></select></label>';
            echo '</div>';self::access_picker('pasarguard','panel_id',$groupSelected);self::access_picker('marzban','marzban_panel_id',$mzSelected);

            echo '<details class="bvc-advanced-options"><summary>Group ID / Service ID</summary><div class="bvc-form-grid">';
            echo '</div>';self::guardcore_service_picker('guardcore_panel_id',$gcRows,BlueVPN_Utils::json_decode_array((string)($x['guardcore_service_ids_json']??''),[]));echo '<div class="bvc-form-grid">';
            echo '</div></details>';

            echo '<div class="bvc-form-actions"><button class="button button-primary">ذخیره مسیر</button></div>';
            echo '</form></div></details>';

            echo '<details class="bvc-plan-routing"><summary><span>ویرایش کامل پلن</span><span class="bvc-summary-chevron">⌄</span></summary><div class="bvc-plan-routing-body"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('bluevpn_cc_save_plan_'.$id);echo '<input type="hidden" name="action" value="bluevpn_cc_save_plan"><input type="hidden" name="plan_id" value="'.$id.'">';
            echo '<div class="bvc-form-grid">';
            echo '<label>عنوان<input name="title" value="'.esc_attr((string)$x['title']).'" required></label>';
            echo '<label>قیمت تومان<input type="number" min="0" name="price_toman" value="'.(int)$x['price_toman'].'" required></label>';
            echo '<label>اعتبار روز<input type="number" min="0" max="3650" name="duration_days" value="'.(int)$x['duration_days'].'" required></label>';
            echo '<label>حجم GB<input type="number" min="0" name="data_limit_gb" value="'.(int)$x['data_limit_gb'].'"></label>';
            echo '<label>تعداد دستگاه<input type="number" min="1" max="20" name="device_limit" value="'.(int)$x['device_limit'].'"></label>';
            echo '<label>ترتیب<input type="number" name="sort_order" value="'.(int)$x['sort_order'].'"></label>';
            echo '<label>PasarGuard';$select('panel_id',$pgRows,(int)$x['panel_id']);echo '</label>';
            echo '<label>Marzban';$select('marzban_panel_id',$mzRows,(int)$x['marzban_panel_id']);echo '</label>';
            echo '<label>GuardCore';$select('guardcore_panel_id',$gcRows,(int)$x['guardcore_panel_id']);echo '</label>';
            echo '<label>اعمال حجم<select name="multi_provider_quota_mode"><option value="split" '.selected($quotaMode,'split',false).'>Split</option><option value="full" '.selected($quotaMode,'full',false).'>Full</option></select></label>';
            echo '</div>';self::guardcore_service_picker('guardcore_panel_id',$gcRows,BlueVPN_Utils::json_decode_array((string)($x['guardcore_service_ids_json']??''),[]));echo '<div class="bvc-form-grid">';
            echo '<label><input type="checkbox" name="active" value="1" '.checked($active,true,false).'> فعال</label>';
            echo '<label style="grid-column:1/-1">توضیحات<textarea name="description" rows="3">'.esc_textarea((string)$x['description']).'</textarea></label>';
            echo '</div>';self::access_picker('pasarguard','panel_id',$groupSelected);self::access_picker('marzban','marzban_panel_id',$mzSelected);echo '<div class="bvc-form-actions"><button class="button button-primary">ذخیره همه تغییرات</button></div></form></div></details>';

            echo '<footer class="bvc-plan-footer">';
            echo '<span class="bvc-plan-order">ترتیب نمایش: '.(int)$x['sort_order'].'</span>';
            echo '<a class="button '.($active?'button-link-delete':'').'" href="'.esc_url($toggle).'">'.($active?'غیرفعال‌کردن':'فعال‌کردن').'</a>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" onsubmit="return confirm(&quot;پلن حذف نرم شود؟&quot;)">';wp_nonce_field('bluevpn_cc_delete_plan_'.$id);echo '<input type="hidden" name="action" value="bluevpn_cc_delete_plan"><input type="hidden" name="plan_id" value="'.$id.'"><button class="button button-link-delete">حذف نرم</button></form>';
            echo '</footer>';
            echo '</article>';
        }
        if($rows) echo '</div>';
        if($deletedRows){
            echo '<div class="bvc-card"><h2>پلن‌های حذف‌شده</h2><table class="widefat striped bvc-table"><tr><th>ID</th><th>عنوان</th><th>زمان حذف</th><th>عملیات</th></tr>';
            foreach($deletedRows as $d){$did=(int)$d['id'];echo '<tr><td>#'.$did.'</td><td>'.self::esc($d['title']).'</td><td>'.self::esc($d['deleted_at']).'</td><td><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_restore_plan_'.$did);echo '<input type="hidden" name="action" value="bluevpn_cc_restore_plan"><input type="hidden" name="plan_id" value="'.$did.'"><button class="button">بازیابی</button></form></td></tr>';}
            echo '</table></div>';
        }
    }

    private static function tab_bluepay(): void {
        global $wpdb;$t=BlueVPN_DB::table('payment_settings');$s=$wpdb->get_row("SELECT * FROM {$t} WHERE id=1",ARRAY_A)?:[];$orders=BlueVPN_DB::table('orders');$pending=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$orders} WHERE status IN ('created','creating_invoice','pending','paid_needs_sync','partial_needs_sync')");
        echo '<div class="bvc-grid"><div class="bvc-card bvc-kpi"><span>سفارش‌های نیازمند پیگیری</span><strong>'.$pending.'</strong></div><div class="bvc-card"><h3>Callback</h3><div class="bvc-code">'.self::esc(home_url('/api/v1/webhooks/bluepay')).'</div></div></div><div class="bvc-card"><h2>تنظیمات BluePay</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_save_payment');echo '<input type="hidden" name="action" value="bluevpn_cc_save_payment"><div class="bvc-form-grid">';self::input('base_url','Base URL',$s['base_url']??'https://bluepay-production.up.railway.app',true);self::input('api_key','API Key (خالی = حفظ فعلی)','',false,'password');self::input('callback_secret','Callback Secret (خالی = حفظ فعلی)','',false,'password');self::select('fee_mode','Fee Mode',['default'=>'Default','merchant'=>'Merchant','customer'=>'Customer','split'=>'Split'],$s['fee_mode']??'default');self::input('ttl_minutes','TTL دقیقه',$s['ttl_minutes']??30,true,'number');echo '<label><input type="checkbox" name="active" value="1" '.checked((int)($s['active']??0),1,false).'> فعال</label></div>';submit_button('ذخیره BluePay','primary','submit',false);echo '</form></div>';
    }
    private static function tab_manual(): void {
        global $wpdb;$ct=BlueVPN_DB::table('customers');$pt=BlueVPN_DB::table('plans');$customers=$wpdb->get_results("SELECT id,email,phone,subscription_status,plan_id FROM {$ct} ORDER BY id DESC LIMIT 200",ARRAY_A);$plans=$wpdb->get_results("SELECT id,title FROM {$pt} WHERE active=1 AND deleted=0 ORDER BY sort_order,id",ARRAY_A);
        echo '<div class="bvc-card"><h2>فعال‌سازی / تمدید دستی روی Providerها</h2><div class="bvc-note">این عملیات از تنظیمات همان پلن استفاده می‌کند و PasarGuard/Marzban را مستقیم Provision می‌کند. GuardCore Manual وارد صف می‌شود.</div><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_manual_activate');echo '<input type="hidden" name="action" value="bluevpn_cc_manual_activate"><div class="bvc-form-grid"><label>کاربر<select name="customer_id" required><option value="">انتخاب…</option>';foreach($customers as $c)echo '<option value="'.(int)$c['id'].'">#'.(int)$c['id'].' '.self::esc($c['phone']?:$c['email']).' — '.self::esc($c['subscription_status']).'</option>';echo '</select></label><label>پلن<select name="plan_id" required><option value="">انتخاب…</option>';foreach($plans as $p)echo '<option value="'.(int)$p['id'].'">#'.(int)$p['id'].' '.self::esc($p['title']).'</option>';echo '</select></label></div>';submit_button('فعال‌سازی / تمدید','primary','submit',false);echo '</form></div>';
    }
    private static function tab_customers(): void {
        global $wpdb;
        $detailId=max(0,(int)($_GET['customer_id']??0));
        if($detailId>0){self::customer_detail($detailId);return;}
        $t=BlueVPN_DB::table('customers');$q=sanitize_text_field(wp_unslash($_GET['q']??''));$where='1=1';if($q!==''){$like='%'.$wpdb->esc_like($q).'%';$where=$wpdb->prepare('(email LIKE %s OR phone LIKE %s OR pg_username LIKE %s OR marzban_username LIKE %s)',$like,$like,$like,$like);} $rows=$wpdb->get_results("SELECT * FROM {$t} WHERE {$where} ORDER BY id DESC LIMIT 250",ARRAY_A);
        $repairable=BlueVPN_Providers::repairable_customer_count();$lastRepair=get_option('bluevpn_provider_repair_last_result',[]);if(!is_array($lastRepair))$lastRepair=[];$repairNonce=wp_create_nonce('bluevpn_cc_repair_missing_provider_subscriptions');
        echo '<div class="bvc-card" id="bvc-provider-repair-card"><div style="display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap"><div><h2 style="margin-bottom:6px">🧩 همگام‌سازی اشتراک‌های گمشده Provider</h2><div class="bvc-note">کاربران دارای اشتراک فعال را روی PasarGuard و Marzban واقعی بررسی می‌کند. اگر کاربر روی Provider ساخته نشده باشد، با <strong>همان تاریخ انقضا، حجم و محدودیت دستگاه فعلی</strong> ساخته می‌شود؛ اگر موجود باشد هم دسترسی او دوباره همگام می‌شود. دسترسی PasarGuard/Marzban طبق انتخاب همان پلن همگام می‌شود؛ اگر انتخاب پلن خالی باشد همه Group/Inboundهای فعال استفاده می‌شوند. این عملیات اشتراک را تمدید نمی‌کند و مصرف را Reset نمی‌کند.</div></div><div class="bvc-kpi"><span>کاربر قابل اسکن</span><strong>'.number_format($repairable).'</strong></div></div>';
        echo '<div class="bvc-actions"><button type="button" class="button button-primary" id="bvc-provider-repair-start" '.($repairable<1?'disabled':'').'>اسکن و ساخت اشتراک‌های گمشده</button></div>';
        echo '<div id="bvc-provider-repair-progress" style="display:none;margin-top:14px"><div style="height:12px;background:#e5e7eb;border-radius:999px;overflow:hidden"><span id="bvc-provider-repair-bar" style="display:block;height:100%;width:0;background:#2271b1;transition:width .25s ease"></span></div><p id="bvc-provider-repair-text" style="margin:8px 0 0">آماده…</p><div id="bvc-provider-repair-errors" class="bvc-bad"></div></div>';
        if(!empty($lastRepair['finished_at']))echo '<p style="margin:12px 0 0"><small>آخرین اجرا: '.self::esc($lastRepair['finished_at']).' • اسکن '.number_format((int)($lastRepair['processed']??0)).' • ساخته‌شده '.number_format((int)($lastRepair['created']??0)).' • اتصال بازیابی‌شده '.number_format((int)($lastRepair['attached']??0)).' • بدون تغییر '.number_format((int)($lastRepair['existing']??0)).' • خطا '.number_format((int)($lastRepair['error_count']??0)).'</small></p>';
        echo '</div>';
        echo '<script>(function(){const btn=document.getElementById("bvc-provider-repair-start");if(!btn)return;const box=document.getElementById("bvc-provider-repair-progress"),bar=document.getElementById("bvc-provider-repair-bar"),txt=document.getElementById("bvc-provider-repair-text"),errs=document.getElementById("bvc-provider-repair-errors");let stopped=false;async function step(cursor,job){if(stopped)return;const body=new URLSearchParams();body.set("action","bluevpn_cc_repair_missing_provider_subscriptions");body.set("_ajax_nonce",'.wp_json_encode($repairNonce).');body.set("cursor",String(cursor||0));if(job)body.set("job_id",job);try{const r=await fetch('.wp_json_encode(admin_url('admin-ajax.php')).',{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},body:body.toString()});const j=await r.json();if(!j||!j.success)throw new Error((j&&j.data&&j.data.message)||"پاسخ نامعتبر سرور");const d=j.data||{},s=d.summary||{};const total=Math.max(1,Number(s.total||0)),processed=Number(s.processed||0);bar.style.width=Math.min(100,Math.round(processed*100/total))+"%";txt.textContent="اسکن "+processed+" از "+Number(s.total||0)+" • ساخته‌شده "+Number(s.created||0)+" • اتصال بازیابی‌شده "+Number(s.attached||0)+" • موجود "+Number(s.existing||0)+" • خطا "+Number(s.error_count||0);if(Array.isArray(s.errors)&&s.errors.length)errs.textContent=s.errors.slice(-4).join(" | ");if(d.done){stopped=true;btn.disabled=false;btn.textContent="اسکن دوباره";txt.textContent="✅ همگام‌سازی کامل شد — "+txt.textContent;return;}setTimeout(()=>step(Number(d.next_cursor||0),String(d.job_id||job||"")),180);}catch(e){txt.textContent="⚠️ "+e.message+" — تلاش مجدد…";setTimeout(()=>step(cursor,job),1800);}}btn.addEventListener("click",function(){if(!confirm("تمام کاربران دارای اشتراک فعال روی PasarGuard و Marzban بررسی شوند، اشتراک‌های گمشده ساخته و دسترسی Group/Inbound طبق تنظیم پلن دوباره تخصیص داده شود؟\n\nاین عملیات تاریخ اشتراک را تمدید نمی‌کند."))return;stopped=false;btn.disabled=true;box.style.display="block";errs.textContent="";bar.style.width="0";txt.textContent="شروع اسکن…";step(0,"");});})();</script>';
        echo '<form method="get" class="bvc-card"><input type="hidden" name="page" value="bluevpn-customers"><input name="q" value="'.esc_attr($q).'" placeholder="ایمیل، موبایل، username"> <button class="button">جستجو</button></form><table class="widefat striped bvc-table"><tr><th>کاربر</th><th>کانال اپ</th><th>اشتراک</th><th>مصرف</th><th>Provider</th><th>آخرین Sync</th><th>عملیات</th></tr>';
        foreach($rows as $x){$sync=wp_nonce_url(admin_url('admin-post.php?action=bluevpn_cc_sync_customer&customer_id='.(int)$x['id']),'bluevpn_cc_sync_customer_'.$x['id']);$repair=wp_nonce_url(admin_url('admin-post.php?action=bluevpn_cc_repair_customer_providers&customer_id='.(int)$x['id']),'bluevpn_cc_repair_customer_providers_'.$x['id']);$detail=self::url('customers',['customer_id'=>(int)$x['id']]);echo '<tr><td>#'.(int)$x['id'].'<br>'.self::esc($x['phone']?:$x['email']).'<br><small>'.self::esc($x['email']).'</small></td><td>'.(!empty($x['beta_tester'])?'<span class="bvc-badge">🧪 Beta</span>':'Stable').'</td><td>'.self::esc($x['subscription_status']).'<br><small>'.self::esc($x['subscription_expire']).'</small></td><td>'.self::fmt_bytes($x['used_traffic_bytes']).' / '.self::fmt_bytes($x['data_limit_bytes']).'</td><td><small>PG '.self::esc($x['pg_username']).'<br>MZ '.self::esc($x['marzban_username']).'<br>GC '.self::esc($x['guardcore_status']).'</small></td><td>'.self::esc($x['last_sync_at']).'<br><small class="bvc-bad">'.self::esc($x['last_sync_error']).'</small></td><td><div class="bvc-actions"><a class="button button-primary" href="'.esc_url($detail).'">جزئیات</a><a class="button" href="'.esc_url($sync).'">Sync</a><a class="button" href="'.esc_url($repair).'">ترمیم Provider</a></div></td></tr>';}
        echo '</table>';
    }

    private static function customer_detail(int $customerId): void {
        global $wpdb;$ct=BlueVPN_DB::table('customers');$pt=BlueVPN_DB::table('plans');$dt=BlueVPN_DB::table('customer_devices');$st=BlueVPN_DB::table('customer_sessions');$ot=BlueVPN_DB::table('orders');
        $c=$wpdb->get_row($wpdb->prepare("SELECT c.*,p.title AS plan_title FROM {$ct} c LEFT JOIN {$pt} p ON p.id=c.plan_id WHERE c.id=%d LIMIT 1",$customerId),ARRAY_A);if(!$c){echo '<div class="notice notice-error"><p>کاربر پیدا نشد.</p></div>';return;}
        $devices=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$dt} WHERE customer_id=%d ORDER BY last_seen_at DESC,id DESC",$customerId),ARRAY_A)?:[];
        $sessions=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$st} WHERE customer_id=%d ORDER BY created_at DESC LIMIT 100",$customerId),ARRAY_A)?:[];
        $orders=$wpdb->get_results($wpdb->prepare("SELECT o.*,p.title plan_title FROM {$ot} o LEFT JOIN {$pt} p ON p.id=o.plan_id WHERE o.customer_id=%d ORDER BY o.created_at DESC LIMIT 40",$customerId),ARRAY_A)?:[];
        $plans=$wpdb->get_results("SELECT id,title FROM {$pt} WHERE active=1 AND deleted=0 ORDER BY sort_order,id",ARRAY_A)?:[];
        $activeSessions=0;$activeWebSessions=0;foreach($sessions as $ss){if(empty($ss['revoked_at'])&&!empty($ss['expires_at'])&&strtotime((string)$ss['expires_at'].' UTC')>time()){$activeSessions++;if(($ss['client_type']??'app')==='web')$activeWebSessions++;}}$vpnDevices=array_values(array_filter($devices,static fn($d)=>(string)($d['client_type']??'app')!=='web'));
        echo '<div class="bvc-actions" style="margin-bottom:12px"><a class="button" href="'.esc_url(self::url('customers')).'">← لیست کاربران</a></div>';
        echo '<div class="bvc-grid"><div class="bvc-card"><h3>هویت</h3><strong>#'.(int)$c['id'].'</strong><p>'.self::esc($c['phone']?:'بدون موبایل').'<br>'.self::esc($c['email']?:'بدون ایمیل').'</p><small>روش ورود: '.self::esc($c['auth_method']).'</small></div><div class="bvc-card"><h3>اشتراک</h3><strong>'.self::esc($c['subscription_status']).'</strong><p>'.self::esc($c['plan_title']?:'بدون پلن').'</p><small>انقضا: '.self::esc($c['subscription_expire']).'</small></div><div class="bvc-card"><h3>دستگاه‌های VPN</h3><strong>'.number_format(count($vpnDevices)).'</strong><small>سقف پلن: '.(int)$c['device_limit'].' • نشست وب: '.number_format($activeWebSessions).'</small></div><div class="bvc-card"><h3>مصرف</h3><strong>'.self::fmt_bytes($c['used_traffic_bytes']).'</strong><small>از '.self::fmt_bytes($c['data_limit_bytes']).'</small></div></div>';
        echo '<div class="bvc-grid"><div class="bvc-card"><h2>وضعیت حساب</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_set_customer_status_'.$customerId);echo '<input type="hidden" name="action" value="bluevpn_cc_set_customer_status"><input type="hidden" name="customer_id" value="'.$customerId.'"><label><input type="checkbox" name="active" value="1" '.checked((int)$c['active'],1,false).'> حساب فعال باشد</label><br><br><label><input type="checkbox" name="beta_tester" value="1" '.checked((int)($c['beta_tester']??0),1,false).'> 🧪 آزمایش‌کننده Beta — نسخه‌های آزمایشی را دریافت کند</label><p><button class="button button-primary">ذخیره وضعیت</button></p></form><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_logout_customer_'.$customerId);echo '<input type="hidden" name="action" value="bluevpn_cc_logout_customer"><input type="hidden" name="customer_id" value="'.$customerId.'"><button class="button">خروج اجباری از همه نشست‌ها</button></form></div>';
        echo '<div class="bvc-card"><h2>پلن / تمدید دستی</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_manual_activate');echo '<input type="hidden" name="action" value="bluevpn_cc_manual_activate"><input type="hidden" name="customer_id" value="'.$customerId.'"><label>پلن<select name="plan_id" required><option value="">انتخاب…</option>';foreach($plans as $p)echo '<option value="'.(int)$p['id'].'" '.selected((int)$c['plan_id'],(int)$p['id'],false).'>'.self::esc($p['title']).'</option>';echo '</select></label><p><button class="button button-primary">فعال‌سازی / تمدید / تغییر پلن</button></p></form><hr><p><strong>ترمیم Provider بدون تمدید</strong></p><p><small>PasarGuard و Marzban را بررسی می‌کند؛ اشتراک گمشده را با اعتبار فعلی می‌سازد و برای اشتراک موجود نیز دسترسی Group/Inbound ذخیره‌شده در پلن را همگام می‌کند؛ انتخاب خالی یعنی همه موارد فعال.</small></p><a class="button" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=bluevpn_cc_repair_customer_providers&customer_id='.$customerId),'bluevpn_cc_repair_customer_providers_'.$customerId)).'">ترمیم اشتراک Provider</a></div></div>';
        echo '<div class="bvc-card"><h2>دستگاه‌ها</h2><table class="widefat striped bvc-table"><tr><th>دستگاه</th><th>نوع</th><th>وضعیت</th><th>اولین مشاهده</th><th>آخرین مشاهده</th><th>عملیات</th></tr>';
        foreach($devices as $d){$hash=hash('sha256',(string)$d['device_id']);echo '<tr><td><strong>'.self::esc($d['device_name']?:'بدون نام').'</strong><br><code>'.self::esc($d['device_id']).'</code></td><td>'.(((string)($d['client_type']??'app')==='web')?'وب':'اپ').'</td><td class="'.((int)$d['active']?'bvc-ok':'bvc-warn').'">'.((int)$d['active']?'فعال':'غیرفعال').'</td><td>'.self::esc($d['first_seen_at']).'</td><td>'.self::esc($d['last_seen_at']).'</td><td>';if((int)$d['active']){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_revoke_device_'.$customerId.'_'.$hash);echo '<input type="hidden" name="action" value="bluevpn_cc_revoke_device"><input type="hidden" name="customer_id" value="'.$customerId.'"><input type="hidden" name="device_id" value="'.esc_attr((string)$d['device_id']).'"><button class="button button-link-delete">قطع دستگاه</button></form>';}else echo '—';echo '</td></tr>';}
        echo '</table></div>';
        echo '<div class="bvc-card"><h2>نشست‌ها</h2><table class="widefat striped bvc-table"><tr><th>ID</th><th>Device ID</th><th>ساخته‌شده</th><th>آخرین استفاده</th><th>انقضا</th><th>وضعیت</th><th>عملیات</th></tr>';
        foreach($sessions as $ss){$sid=(int)$ss['id'];$live=empty($ss['revoked_at'])&&!empty($ss['expires_at'])&&strtotime((string)$ss['expires_at'].' UTC')>time();echo '<tr><td>#'.$sid.'</td><td><code>'.self::esc($ss['device_id']).'</code></td><td>'.self::esc($ss['created_at']).'</td><td>'.self::esc($ss['last_seen_at']).'</td><td>'.self::esc($ss['expires_at']).'</td><td class="'.($live?'bvc-ok':'bvc-warn').'">'.($live?'فعال':'باطل/منقضی').'</td><td>';if($live){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_revoke_session_'.$customerId.'_'.$sid);echo '<input type="hidden" name="action" value="bluevpn_cc_revoke_session"><input type="hidden" name="customer_id" value="'.$customerId.'"><input type="hidden" name="session_id" value="'.$sid.'"><button class="button">ابطال نشست</button></form>';}else echo '—';echo '</td></tr>';}
        echo '</table></div>';
        echo '<div class="bvc-card"><h2>آخرین سفارش‌ها</h2><table class="widefat striped bvc-table"><tr><th>فاکتور</th><th>پلن</th><th>مبلغ</th><th>وضعیت</th><th>ایجاد</th><th>فعال‌سازی</th></tr>';foreach($orders as $o)echo '<tr><td><code>'.self::esc($o['order_code']).'</code></td><td>'.self::esc($o['plan_title']).'</td><td>'.number_format((int)$o['amount_toman']).'</td><td>'.self::esc($o['status']).'</td><td>'.self::esc($o['created_at']).'</td><td>'.self::esc($o['activated_at']).'</td></tr>';echo '</table></div>';
    }

    private static function tab_orders(): void {
        global $wpdb;$o=BlueVPN_DB::table('orders');$c=BlueVPN_DB::table('customers');$p=BlueVPN_DB::table('plans');$rows=$wpdb->get_results("SELECT o.*,c.email,c.phone,p.title plan_title FROM {$o} o LEFT JOIN {$c} c ON c.id=o.customer_id LEFT JOIN {$p} p ON p.id=o.plan_id ORDER BY o.created_at DESC LIMIT 250",ARRAY_A);
        echo '<table class="widefat striped bvc-table"><tr><th>سفارش</th><th>کاربر</th><th>پلن</th><th>مبلغ</th><th>وضعیت</th><th>پرداخت</th><th>خطا</th><th>زمان</th></tr>';foreach($rows as $x)echo '<tr><td><code>'.self::esc($x['order_code']).'</code></td><td>'.self::esc($x['phone']?:$x['email']).'</td><td>'.self::esc($x['plan_title']).'</td><td>'.number_format((int)$x['amount_toman']).'</td><td>'.self::esc($x['status']).'</td><td><code>'.self::esc($x['payment_id']).'</code></td><td><small class="bvc-bad">'.self::esc($x['activation_error']).'</small></td><td>'.self::esc($x['created_at']).'</td></tr>';echo '</table>';
    }
    private static function tab_app(): void {
        global $wpdb;
        $s=BlueVPN_DB::settings();
        $cfg=BlueVPN_App_Release_Manager::settings();
        $status=BlueVPN_App_Release_Manager::status();
        $last=BlueVPN_App_Release_Manager::last_sync();
        $stable=BlueVPN_App_Release_Manager::stable_release();
        $beta=BlueVPN_App_Release_Manager::beta_release();
        $releases=BlueVPN_App_Release_Manager::releases(60);
        $betaCount=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.BlueVPN_DB::table('customers').' WHERE active=1 AND beta_tester=1');
        $statusClass=in_array((string)($status['status']??''),['synced','up_to_date'],true)?'bvc-ok':(((string)($status['status']??'')==='never')?'bvc-warn':'bvc-bad');

        echo '<div class="bvc-note"><strong>Release Channels فعال است.</strong> هر Build جدید GitHub به‌صورت پیش‌فرض فقط وارد کانال <strong>Beta</strong> می‌شود. کاربران عادی تا زمانی که روی «انتشار رسمی» نزنید همان Stable قبلی را می‌گیرند.</div>';
        echo '<div class="bvc-grid">';
        echo '<div class="bvc-card bvc-kpi"><span>نسخه رسمی Stable</span><strong>'.self::esc($stable['version']??'—').'</strong><small>'.($stable?'Build #'.(int)$stable['build_number']:'هنوز انتشار رسمی ندارد').'</small></div>';
        echo '<div class="bvc-card bvc-kpi"><span>آخرین Beta فعال</span><strong>'.self::esc($beta['version']??'—').'</strong><small>'.($beta?'فقط برای آزمایش‌کنندگان':'Beta فعالی وجود ندارد').'</small></div>';
        echo '<div class="bvc-card bvc-kpi"><span>آزمایش‌کننده Beta</span><strong>'.number_format($betaCount).'</strong><small>از بخش کاربران قابل تعیین است</small></div>';
        echo '<div class="bvc-card"><h3>همگام‌سازی GitHub</h3><p class="'.esc_attr($statusClass).'">'.self::esc((string)($status['message']??'در انتظار اولین همگام‌سازی')).'</p><small>'.($last?'آخرین بررسی: '.self::esc(wp_date('Y-m-d H:i:s',$last)):'هنوز اجرا نشده').'</small></div>';
        echo '</div>';

        echo '<div class="bvc-card"><h2>نسخه‌ها و کانال انتشار</h2>';
        if(!$releases){echo '<p class="bvc-warn">هنوز Releaseای ثبت نشده است. اگر نسخه رسمی فعلی در تنظیمات قبلی وجود داشته باشد، با ارتقای Schema به‌عنوان Stable Seed می‌شود.</p>';}
        else{
            echo '<table class="widefat striped bvc-table"><tr><th>نسخه</th><th>کانال</th><th>Build / Commit</th><th>Force</th><th>تاریخ</th><th>عملیات</th></tr>';
            foreach($releases as $r){
                $state=(string)$r['state'];
                $label=['stable'=>'🟢 Stable / رسمی','beta'=>'🟡 Beta / آزمایشی','stopped'=>'⛔ Beta متوقف','archived'=>'⚪ آرشیو'][$state]??$state;
                echo '<tr><td><strong>'.self::esc($r['version']).'</strong><br><small>Code '.(int)$r['version_code'].'</small></td><td>'.self::esc($label).'</td><td>#'.(int)$r['build_number'].'<br><code>'.self::esc(substr((string)$r['commit_sha'],0,12)?:'—').'</code></td><td>'.(!empty($r['force_update'])?'<span class="bvc-bad">اجباری</span>':'اختیاری').'</td><td>'.self::esc((string)$r['release_published_at']).'</td><td><div class="bvc-actions">';
                if(!empty($r['release_url']))echo '<a class="button" target="_blank" rel="noopener" href="'.esc_url($r['release_url']).'">GitHub</a>';
                if($state!=='stable'){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_release_promote_'.(int)$r['id']);echo '<input type="hidden" name="action" value="bluevpn_cc_release_promote"><input type="hidden" name="release_id" value="'.(int)$r['id'].'"><button class="button button-primary">انتشار رسمی</button></form>';}
                if($state==='beta'){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_release_stop_'.(int)$r['id']);echo '<input type="hidden" name="action" value="bluevpn_cc_release_stop"><input type="hidden" name="release_id" value="'.(int)$r['id'].'"><button class="button">توقف Beta</button></form>';}
                elseif(in_array($state,['stopped','archived'],true)){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_release_resume_'.(int)$r['id']);echo '<input type="hidden" name="action" value="bluevpn_cc_release_resume"><input type="hidden" name="release_id" value="'.(int)$r['id'].'"><button class="button">فعال‌سازی Beta</button></form>';}
                if(in_array($state,['stable','beta'],true)){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_release_force_'.(int)$r['id']);echo '<input type="hidden" name="action" value="bluevpn_cc_release_force"><input type="hidden" name="release_id" value="'.(int)$r['id'].'"><button class="button">'.(!empty($r['force_update'])?'لغو اجبار':'اجباری‌کردن').'</button></form>';}
                echo '</div></td></tr>';
            }
            echo '</table>';
        }
        echo '</div>';

        echo '<div class="bvc-card"><h2>سیاست بروزرسانی</h2><p>Build شدن APK با انتشار عمومی یکی نیست. Beta Testerها همان بررسی و دانلود خودکار Stable را دارند؛ Auto Update هر کانال مستقل است. Beta Force فقط روی همان Release آزمایشی اثر می‌گذارد و Force نسخه Stable برای همه کاربران است.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('bluevpn_cc_save_app_update_policy');echo '<input type="hidden" name="action" value="bluevpn_cc_save_app_update_policy"><div class="bvc-form-grid">';
        self::input('owner','GitHub Owner',$cfg['owner'],true);self::input('repo','Repository',$cfg['repo'],true);self::input('minimum_version','حداقل نسخه قابل استفاده',$s['minimum_version'],true);self::input('support_url','لینک پشتیبانی',$s['support_url']);self::input('title_override','عنوان آپدیت (اختیاری)',$cfg['title_override']);self::textarea('message_override','متن آپدیت (اختیاری)',$cfg['message_override']);
        echo '<label><input type="checkbox" name="app_auto_sync" value="1" '.checked(!empty($cfg['auto_sync']),true,false).'> Sync خودکار Releaseهای GitHub</label>';
        echo '<label><input type="checkbox" name="auto_update_stable" value="1" '.checked(!empty($s['auto_update_stable']),true,false).'> دانلود خودکار Stable برای کاربران عادی</label>';
        echo '<label><input type="checkbox" name="auto_update_beta" value="1" '.checked(!empty($s['auto_update_beta']),true,false).'> 🧪 دانلود خودکار Beta برای آزمایش‌کنندگان</label>';
        echo '<label><input type="checkbox" name="maintenance" value="1" '.checked(!empty($s['maintenance']),true,false).'> حالت تعمیرات</label>';
        echo '</div>';submit_button('ذخیره سیاست بروزرسانی','primary','submit',false);echo '</form></div>';

        echo '<div class="bvc-card"><h2>اتوماسیون انتشار</h2><div class="bvc-grid"><div><strong>① Build</strong><p>Deploy Bot/GitHub APK را می‌سازد.</p></div><div><strong>② Beta</strong><p>WordPress نسخه جدید را خودکار Beta ثبت می‌کند؛ فقط Beta Testerها می‌بینند.</p></div><div><strong>③ Stable</strong><p>با دکمه «انتشار رسمی» همان APK بدون Build مجدد برای همه منتشر می‌شود.</p></div></div>';
        echo '<p><strong>API:</strong></p><div class="bvc-code">'.self::esc(untrailingslashit(home_url('/')).'/api/v1/mobile/config').'</div>';
        echo '<div class="bvc-actions" style="margin-top:12px"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_sync_app_release');echo '<input type="hidden" name="action" value="bluevpn_cc_sync_app_release">';submit_button('همگام‌سازی همین حالا','secondary','submit',false);echo '</form><a class="button" href="'.esc_url(self::url('customers')).'">مدیریت Beta Testerها</a></div></div>';
        echo '<div class="bvc-note"><strong>نکته راه‌اندازی اولیه:</strong> نسخه‌های قبل از 4.3.1 هنگام درخواست تنظیمات، توکن حساب را ارسال نمی‌کنند. بنابراین اولین Beta Testerها باید APK 4.3.1 را یک‌بار دستی نصب کنند؛ از 4.3.1 به بعد دریافت Beta کاملاً خودکار و بر اساس حساب پنل است.</div>';
    }

    private static function tab_sms(): void {
        global $wpdb;
        $t=BlueVPN_DB::table('sms_settings');$s=$wpdb->get_row("SELECT * FROM {$t} WHERE id=1",ARRAY_A)?:[];
        BlueVPN_SMS_Notifications::seed_templates();
        $templates=BlueVPN_SMS_Notifications::templates();
        $patternCache=BlueVPN_SMS_OTP::pattern_cache();$providerPatterns=is_array($patternCache['patterns']??null)?$patternCache['patterns']:[];$smartReport=BlueVPN_SMS_Notifications::smart_assignment_report();$smartByKey=[];foreach((array)($smartReport['mappings']??[]) as $sm){if(!empty($sm['key']))$smartByKey[(string)$sm['key']]=$sm;}
        $d=BlueVPN_DB::table('sms_deliveries');
        $recent=$wpdb->get_results("SELECT id,event_key,phone,status,attempts,max_attempts,provider_message_id,provider_delivery_status,provider_delivery_at,last_error,sent_at,created_at FROM {$d} ORDER BY created_at DESC LIMIT 100",ARRAY_A)?:[];
        $stats=[];foreach(['pending','retry','sending','sent','failed','skipped'] as $st)$stats[$st]=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$d} WHERE status=%s",$st));
        echo '<div class="bvc-grid"><div class="bvc-card bvc-kpi"><span>در صف</span><strong>'.number_format($stats['pending']+$stats['retry']+$stats['sending']).'</strong></div><div class="bvc-card bvc-kpi"><span>موفق</span><strong>'.number_format($stats['sent']).'</strong></div><div class="bvc-card bvc-kpi"><span>ناموفق</span><strong>'.number_format($stats['failed']).'</strong></div><div class="bvc-card bvc-kpi"><span>ردشده/غیرفعال</span><strong>'.number_format($stats['skipped']).'</strong></div></div>';
        if(!empty($s['last_test_at'])){
            $ok=(int)($s['last_test_ok']??0)===1;
            echo '<div class="bvc-card"><strong class="'.($ok?'bvc-ok':'bvc-bad').'">'.($ok?'✅ آخرین ارتباط SMS موفق':'❌ آخرین ارتباط SMS ناموفق').'</strong><p class="bvc-note">'.self::esc((string)($s['last_test_message']??'')).'</p><small>'.self::esc((string)$s['last_test_at']).' UTC</small></div>';
        }
        echo '<div class="bvc-card"><div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap"><div><h2 style="margin-bottom:5px">پترن‌های ایران‌پیامک</h2><p style="margin:0">فهرست از API رسمی حساب دریافت می‌شود؛ BlueVPN می‌تواند پترن‌ها را بر اساس متن و قرارداد متغیرها هوشمند روی پیام‌ها جایگذاری کند.</p></div><div class="bvc-actions"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_refresh_sms_patterns');echo '<input type="hidden" name="action" value="bluevpn_cc_refresh_sms_patterns"><button class="button button-primary">↻ تازه‌سازی + جایگذاری هوشمند</button></form><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_smart_assign_sms_patterns');echo '<input type="hidden" name="action" value="bluevpn_cc_smart_assign_sms_patterns"><input type="hidden" name="overwrite" value="1"><button class="button" onclick="return confirm(&quot;بازچینی کامل، انتخاب‌های فعلی پترن را با تطبیق هوشمند جایگزین می‌کند. ادامه؟&quot;)">🧠 بازچینی کامل</button></form></div></div>';
        if($providerPatterns){
            echo '<p class="bvc-success" style="padding:10px;border:1px solid #00a32a;border-radius:8px;margin-top:12px">✅ '.number_format(count($providerPatterns)).' پترن فعال بارگذاری شده'.(!empty($patternCache['fetched_at'])?' • آخرین Sync: '.self::esc($patternCache['fetched_at']).' UTC':'').'</p>';
            echo '<div class="table-wrap"><table class="widefat striped bvc-table"><tr><th>Code</th><th>متن / توضیح</th><th>متغیرها</th></tr>';
            foreach($providerPatterns as $p){$vars=is_array($p['variables']??null)?$p['variables']:[];$desc=trim((string)($p['description']??''));if($desc==='')$desc=trim((string)($p['text']??''));echo '<tr><td><code>'.self::esc($p['code']??'').'</code></td><td>'.self::esc(mb_substr($desc,0,220)).'</td><td><code>'.self::esc($vars?implode(' ، ',$vars):'بدون متغیر').'</code></td></tr>';}
            echo '</table></div>';
        }else{
            echo '<p class="bvc-note">هنوز پترنی از ایران‌پیامک Cache نشده است. API Key را ذخیره کن و «تازه‌سازی پترن‌ها» را بزن. اگر کلید جدید ذخیره شود، BlueVPN به‌صورت خودکار یک Sync اولیه هم انجام می‌دهد.</p>';
        }
        if($smartReport){
            $assigned=(int)($smartReport['assigned']??0);$ambiguous=count((array)($smartReport['ambiguous']??[]));$unmatched=count((array)($smartReport['unmatched']??[]));
            echo '<div class="bvc-note"><strong>🧠 آخرین جایگذاری هوشمند:</strong> '.number_format($assigned).' تطبیق انجام شد • '.number_format($ambiguous).' مورد مبهم • '.number_format($unmatched).' بدون تطبیق امن'.(!empty($smartReport['generated_at'])?' • '.self::esc($smartReport['generated_at']).' UTC':'').'.<br><small>انتخاب دستی معتبر هرگز در حالت عادی بازنویسی نمی‌شود؛ «بازچینی کامل» فقط با تأیید مدیر همه انتخاب‌ها را دوباره می‌چیند.</small></div>';
        }
        echo '</div>';

        echo '<div class="bvc-card"><h2>SMS / OTP</h2><p class="bvc-note">OTP با پترن فعال انتخاب‌شده ارسال می‌شود. اگر پترن تنها یک متغیر داشته باشد، نام پارامتر OTP به‌صورت خودکار با همان متغیر همگام می‌شود.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_save_sms');echo '<input type="hidden" name="action" value="bluevpn_cc_save_sms"><div class="bvc-form-grid">';self::input('provider','Provider',$s['provider']??'iranpayamak');self::input('base_url','Base URL',$s['base_url']??'');self::input('api_key','API Key (خالی = حفظ)','',false,'password');self::input('from_number','شماره ارسال',$s['from_number']??'');self::sms_pattern_select('pattern_code','Pattern ورود / OTP',(string)($s['pattern_code']??''),$providerPatterns,true);self::input('parameter_name','نام پارامتر OTP',$s['parameter_name']??'code');echo '<label>طول OTP<input type="number" value="6" readonly disabled><small style="display:block;margin-top:5px">ورود BlueVPN همیشه ۶ رقمی است.</small></label><input type="hidden" name="otp_length" value="6">';self::input('otp_ttl_seconds','TTL ثانیه',$s['otp_ttl_seconds']??120,true,'number');self::input('resend_seconds','ارسال مجدد بعد از (ثانیه)',$s['resend_seconds']??60,true,'number');self::input('reminder_days','یادآوری روزهای باقی‌مانده',implode(',',BlueVPN_Utils::json_decode_array((string)($s['reminder_days_json']??'[3,2,1]'),[3,2,1])));self::input('low_volume_threshold_gb','هشدار حجم کمتر از GB',$s['low_volume_threshold_gb']??5,true,'number');self::input('retry_max_attempts','حداکثر تلاش ارسال',$s['retry_max_attempts']??3,true,'number');echo '<label><input type="checkbox" name="active" value="1" '.checked((int)($s['active']??0),1,false).'> OTP فعال</label><label><input type="checkbox" name="notification_active" value="1" '.checked((int)($s['notification_active']??0),1,false).'> اعلان‌ها فعال</label><label><input type="checkbox" name="verify_tls" value="1" '.checked(!isset($s['verify_tls'])||(int)$s['verify_tls']===1,true,false).'> بررسی TLS</label></div>';submit_button('ذخیره تنظیمات SMS','primary','submit',false);echo '</form></div>';

        echo '<div class="bvc-card"><h2>پترن‌های پیام</h2><p>برای هر رویداد یکی از پترن‌های فعال همان API Key را انتخاب کن. ستون «متغیرهای موردنیاز» باید با متغیرهای پترن Provider سازگار باشد.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_save_sms_templates');echo '<input type="hidden" name="action" value="bluevpn_cc_save_sms_templates"><div class="table-wrap"><table class="widefat striped bvc-table"><tr><th>فعال</th><th>پیام</th><th>Runtime</th><th>متغیرهای موردنیاز</th><th>پترن ایران‌پیامک</th></tr>';
        foreach($templates as $row){$vars=BlueVPN_Utils::json_decode_array((string)($row['variables_json']??'[]'),[]);$names=[];foreach($vars as $v)if(!empty($v['name']))$names[]=(string)$v['name'];$runtime=BlueVPN_SMS_Notifications::runtime_supports((string)$row['key']);echo '<tr><td><input type="checkbox" name="enabled['.esc_attr($row['key']).']" value="1" '.checked((int)$row['enabled'],1,false).'></td><td><strong>'.self::esc($row['title']).'</strong><br><small>'.self::esc($row['category']).'</small></td><td class="'.($runtime?'bvc-ok':'bvc-warn').'">'.($runtime?'خودکار':'فقط دستی / Feature ندارد').'</td><td><code>'.self::esc($names?implode(' ، ',$names):'بدون متغیر').'</code></td><td>';self::sms_pattern_select('pattern['.(string)$row['key'].']','',(string)$row['pattern_code'],$providerPatterns,true);$pv=BlueVPN_SMS_OTP::pattern_variables((string)$row['pattern_code']);if($pv)echo '<small>Provider vars: '.self::esc(implode(' ، ',$pv)).'</small>';if(isset($smartByKey[(string)$row['key']])&&hash_equals((string)$row['pattern_code'],(string)($smartByKey[(string)$row['key']]['code']??''))){$sm=$smartByKey[(string)$row['key']];echo '<small class="bvc-ok" style="display:block;margin-top:4px">🧠 تطبیق هوشمند '.(int)($sm['confidence']??0).'% • '.self::esc((string)($sm['reason']??'')).'</small>';}echo '</td></tr>';}
        echo '</table></div>';submit_button('ذخیره همه پترن‌ها','primary','submit',false);echo '</form></div>';

        echo '<div class="bvc-grid"><div class="bvc-card"><h3>تست یک پیام</h3><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_test_sms_template');echo '<input type="hidden" name="action" value="bluevpn_cc_test_sms_template"><label>پترن<select name="event_key" style="width:100%">';foreach($templates as $row)echo '<option value="'.esc_attr($row['key']).'">'.self::esc($row['title']).'</option>';echo '</select></label><label>شماره تست<input name="test_phone" placeholder="09123456789" required style="width:100%"></label><label>پارامترها JSON<textarea name="params_json" rows="4" style="width:100%" placeholder=\'{"plan":"یک ماهه","expire_date":"1405/06/20"}\'>{}</textarea></label>';submit_button('ارسال تست','secondary','submit',false);echo '</form></div>';
        $broadcast=array_values(array_filter($templates,fn($x)=>(int)($x['broadcast']??0)===1));echo '<div class="bvc-card"><h3>ارسال عمومی</h3><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_broadcast_sms');echo '<input type="hidden" name="action" value="bluevpn_cc_broadcast_sms"><label>نوع پیام<select name="event_key" style="width:100%">';foreach($broadcast as $row)echo '<option value="'.esc_attr($row['key']).'">'.self::esc($row['title']).'</option>';echo '</select></label><label>مخاطب<select name="audience" style="width:100%"><option value="active">فقط کاربران فعال</option><option value="all">همه کاربران دارای شماره</option></select></label><label>پارامترها JSON<textarea name="params_json" rows="4" style="width:100%">{}</textarea></label>';submit_button('قرار دادن در صف','secondary','submit',false);echo '</form></div></div>';

        echo '<div class="bvc-card"><div class="bvc-actions"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_process_sms');echo '<input type="hidden" name="action" value="bluevpn_cc_process_sms">';submit_button('پردازش فوری صف و یادآوری‌ها','secondary','submit',false);echo '</form></div><h2>گزارش ارسال</h2><p class="bvc-note">وضعیت <strong>provider_accepted</strong> یعنی API رسمی ایران‌پیامک درخواست را پذیرفته است؛ آن را با تحویل قطعی به گوشی یکی نمی‌گیریم.</p><table class="widefat striped bvc-table"><tr><th>رویداد</th><th>موبایل</th><th>صف</th><th>Provider</th><th>Message ID</th><th>تلاش</th><th>خطا</th><th>زمان</th><th>عملیات</th></tr>';
        foreach($recent as $x){echo '<tr><td>'.self::esc($x['event_key']).'</td><td>'.self::esc($x['phone']).'</td><td>'.self::esc($x['status']).'</td><td>'.self::esc($x['provider_delivery_status']?:'unknown').'</td><td><code>'.self::esc($x['provider_message_id']?:'—').'</code></td><td>'.self::esc($x['attempts']).'/'.self::esc($x['max_attempts']).'</td><td>'.self::esc(mb_substr((string)$x['last_error'],0,220)).'</td><td>'.self::esc($x['sent_at']?:$x['created_at']).'</td><td>';if(in_array($x['status'],['failed','skipped'],true)){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_retry_sms_'.$x['id']);echo '<input type="hidden" name="action" value="bluevpn_cc_retry_sms"><input type="hidden" name="delivery_id" value="'.esc_attr($x['id']).'"><button class="button">ارسال مجدد</button></form>';}echo '</td></tr>';}
        echo '</table></div>';
    }
    public static function save_provider(): void {
        self::guard();check_admin_referer('bluevpn_cc_save_provider');global $wpdb;$provider=sanitize_key((string)($_POST['provider']??''));$maps=['pasarguard'=>'pasarguard_panels','marzban'=>'marzban_panels','guardcore'=>'guardcore_panels'];if(!isset($maps[$provider]))self::redirect('overview','Provider نامعتبر است.',true);$id=(int)($_POST['id']??0);$t=BlueVPN_DB::table($maps[$provider]);$old=$id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d",$id),ARRAY_A):[];$data=['name'=>sanitize_text_field(wp_unslash($_POST['name']??'')),'base_url'=>untrailingslashit(esc_url_raw(wp_unslash($_POST['base_url']??''))),'verify_tls'=>isset($_POST['verify_tls'])?1:0,'active'=>isset($_POST['active'])?1:0];
        $secret=function(string $post,string $col)use($old){$v=trim((string)wp_unslash($_POST[$post]??''));return $v!==''?BlueVPN_Utils::encrypt_secret($v):(string)($old[$col]??'');};
        if($provider==='pasarguard'){$data+=['auth_mode'=>sanitize_key((string)($_POST['auth_mode']??'api_key')),'api_key_enc'=>$secret('api_key','api_key_enc'),'username_enc'=>$secret('username','username_enc'),'password_enc'=>$secret('password','password_enc'),'proxy_settings_json'=>self::sanitize_json($_POST['proxy_settings_json']??'{}','{}')];}
        elseif($provider==='marzban'){$data+=['username_enc'=>$secret('username','username_enc'),'password_enc'=>$secret('password','password_enc'),'proxies_json'=>self::sanitize_json($_POST['proxies_json']??'{}','{}'),'inbounds_json'=>self::sanitize_json($_POST['inbounds_json']??'{}','{}')];}
        else{$data+=['global_subscription_url'=>esc_url_raw(wp_unslash($_POST['global_subscription_url']??'')),'auth_mode'=>sanitize_key((string)($_POST['auth_mode']??'manual')),'api_key_enc'=>$secret('api_key','api_key_enc'),'username_enc'=>$secret('username','username_enc'),'password_enc'=>$secret('password','password_enc'),'usage_unit'=>sanitize_key((string)($_POST['usage_unit']??'bytes')),'expire_mode'=>sanitize_key((string)($_POST['expire_mode']??'days'))];}
        if($id)$ok=$wpdb->update($t,$data,['id'=>$id]);else{$data['created_at']=BlueVPN_Utils::now_mysql();$ok=$wpdb->insert($t,$data);}self::redirect($provider==='pasarguard'?'panels':$provider,$ok===false?'ذخیره پنل ناموفق بود.':'پنل ذخیره شد.',$ok===false);
    }
    private static function sanitize_json($value,string $fallback): string { $raw=trim((string)wp_unslash($value));$d=json_decode($raw,true);return is_array($d)?wp_json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):$fallback; }
    public static function toggle_provider(): void { self::guard();global $wpdb;$p=sanitize_key((string)($_GET['provider']??''));$id=(int)($_GET['id']??0);check_admin_referer('bluevpn_cc_toggle_provider_'.$p.'_'.$id);$map=['pasarguard'=>'pasarguard_panels','marzban'=>'marzban_panels','guardcore'=>'guardcore_panels'];if(!isset($map[$p]))self::redirect('overview','Provider نامعتبر.',true);$t=BlueVPN_DB::table($map[$p]);$v=(int)$wpdb->get_var($wpdb->prepare("SELECT active FROM {$t} WHERE id=%d",$id));$wpdb->update($t,['active'=>$v?0:1],['id'=>$id]);self::redirect($p==='pasarguard'?'panels':$p,'وضعیت Provider تغییر کرد.'); }
    public static function delete_provider(): void {
        self::guard();global $wpdb;$p=sanitize_key((string)($_GET['provider']??''));$id=(int)($_GET['id']??0);check_admin_referer('bluevpn_cc_delete_provider_'.$p.'_'.$id);
        $map=['pasarguard'=>'pasarguard_panels','marzban'=>'marzban_panels','guardcore'=>'guardcore_panels'];if(!isset($map[$p])||$id<=0)self::redirect('overview','Provider نامعتبر.',true);
        $t=BlueVPN_DB::table($map[$p]);$exists=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE id=%d",$id));if(!$exists)self::redirect($p==='pasarguard'?'panels':$p,'پنل قبلاً حذف شده است.');
        $plans=BlueVPN_DB::table('plans');$customers=BlueVPN_DB::table('customers');$wpdb->query('START TRANSACTION');
        try{
            if($p==='pasarguard'){
                if($wpdb->query($wpdb->prepare("UPDATE {$plans} SET panel_id=NULL,group_ids_json='[]' WHERE panel_id=%d",$id))===false)throw new RuntimeException('پاک‌سازی مسیر پلن‌های PasarGuard ناموفق بود.');
                if($wpdb->query($wpdb->prepare("UPDATE {$customers} SET panel_id=NULL,pg_user_id=NULL,pg_username='',pasarguard_subscription_url='',last_sync_at=NULL WHERE panel_id=%d",$id))===false)throw new RuntimeException('پاک‌سازی کاربران PasarGuard ناموفق بود.');
            }elseif($p==='marzban'){
                if($wpdb->query($wpdb->prepare("UPDATE {$plans} SET marzban_panel_id=NULL,marzban_inbounds_json='{}' WHERE marzban_panel_id=%d",$id))===false)throw new RuntimeException('پاک‌سازی مسیر پلن‌های Marzban ناموفق بود.');
                if($wpdb->query($wpdb->prepare("UPDATE {$customers} SET marzban_panel_id=NULL,marzban_user_id=NULL,marzban_username='',marzban_subscription_url='',marzban_status='inactive',marzban_last_error='',last_sync_at=NULL WHERE marzban_panel_id=%d",$id))===false)throw new RuntimeException('پاک‌سازی کاربران Marzban ناموفق بود.');
            }else{
                if($wpdb->query($wpdb->prepare("UPDATE {$plans} SET guardcore_panel_id=NULL WHERE guardcore_panel_id=%d",$id))===false)throw new RuntimeException('پاک‌سازی مسیر پلن‌های GuardCore ناموفق بود.');
                if($wpdb->query($wpdb->prepare("UPDATE {$customers} SET guardcore_panel_id=NULL,guardcore_subscription_id=NULL,guardcore_username='',guardcore_subscription_url='',guardcore_status='inactive',guardcore_last_error='',last_sync_at=NULL WHERE guardcore_panel_id=%d",$id))===false)throw new RuntimeException('پاک‌سازی کاربران GuardCore ناموفق بود.');
            }
            if($wpdb->delete($t,['id'=>$id],['%d'])===false)throw new RuntimeException('حذف پنل از دیتابیس ناموفق بود.');$wpdb->query('COMMIT');
        }catch(Throwable $e){$wpdb->query('ROLLBACK');self::redirect($p==='pasarguard'?'panels':$p,'حذف پنل ناموفق: '.$e->getMessage(),true);}
        self::redirect($p==='pasarguard'?'panels':$p,'پنل حذف شد و وابستگی‌های محلی آن پاک شدند.');
    }
    public static function test_provider(): void { self::guard();$p=sanitize_key((string)($_GET['provider']??''));$id=(int)($_GET['id']??0);check_admin_referer('bluevpn_cc_test_provider_'.$p.'_'.$id);$r=BlueVPN_Providers::test($p,$id);self::redirect($p==='pasarguard'?'panels':$p,$r['message'],!$r['ok']); }
    public static function save_plan_routing(): void {
        self::guard();global $wpdb;$id=(int)($_POST['plan_id']??0);check_admin_referer('bluevpn_cc_save_plan_routing_'.$id);$pt=BlueVPN_DB::table('plans');$plan=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$pt} WHERE id=%d AND deleted=0",$id),ARRAY_A);if(!$plan)self::redirect('plans','پلن پیدا نشد.',true);
        $ids=function(string $name): array {$raw=(string)wp_unslash($_POST[$name]??'');$out=[];foreach(preg_split('/[,\s]+/',$raw)?:[] as $v){$n=(int)$v;if($n>0&&!in_array($n,$out,true))$out[]=$n;}return array_slice($out,0,200);};
        $pg=max(0,(int)($_POST['panel_id']??0));$mz=max(0,(int)($_POST['marzban_panel_id']??0));$gc=max(0,(int)($_POST['guardcore_panel_id']??0));$services=self::posted_ids('guardcore_service_ids_selected','guardcore_service_ids');$groups=self::posted_ids('group_ids_selected','group_ids');$mzInbounds=self::posted_marzban_inbounds();$mode=in_array((string)($_POST['multi_provider_quota_mode']??'split'),['split','full'],true)?(string)$_POST['multi_provider_quota_mode']:'split';
        foreach([[$pg,'pasarguard_panels','PasarGuard'],[$mz,'marzban_panels','Marzban'],[$gc,'guardcore_panels','GuardCore']] as [$panelId,$table,$label])if($panelId>0&&!$wpdb->get_var($wpdb->prepare('SELECT id FROM '.BlueVPN_DB::table($table).' WHERE id=%d',$panelId)))self::redirect('plans',$label.' انتخاب‌شده پیدا نشد.',true);
        if($gc>0){$g=$wpdb->get_row($wpdb->prepare('SELECT auth_mode FROM '.BlueVPN_DB::table('guardcore_panels').' WHERE id=%d',$gc),ARRAY_A);if($g&&($g['auth_mode']??'manual')!=='manual'&&!$services)self::redirect('plans','برای GuardCore خودکار حداقل یک Service ID وارد کن.',true);}
        $ok=$wpdb->update($pt,['panel_id'=>$pg?:null,'marzban_panel_id'=>$mz?:null,'guardcore_panel_id'=>$gc?:null,'group_ids_json'=>BlueVPN_Utils::json_encode($groups),'marzban_inbounds_json'=>BlueVPN_Utils::json_encode($mzInbounds),'guardcore_service_ids_json'=>BlueVPN_Utils::json_encode($services),'marzban_quota_mode'=>$mode,'multi_provider_quota_mode'=>$mode],['id'=>$id]);self::redirect('plans',$ok===false?'ذخیره Routing ناموفق بود.':'Routing پلن ذخیره شد.',$ok===false);
    }
    public static function save_plan(): void {
        self::guard();global $wpdb;$id=(int)($_POST['plan_id']??0);check_admin_referer('bluevpn_cc_save_plan_'.$id);$pt=BlueVPN_DB::table('plans');
        $plan=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$pt} WHERE id=%d AND deleted=0",$id),ARRAY_A);if(!$plan)self::redirect('plans','پلن پیدا نشد.',true);
        $title=sanitize_text_field(wp_unslash($_POST['title']??''));if($title==='')self::redirect('plans','عنوان پلن نمی‌تواند خالی باشد.',true);
        $ids=function(string $name): array {$raw=(string)wp_unslash($_POST[$name]??'');$out=[];foreach(preg_split('/[,\s]+/',$raw)?:[] as $v){$n=(int)$v;if($n>0&&!in_array($n,$out,true))$out[]=$n;}return array_slice($out,0,200);};
        $pg=max(0,(int)($_POST['panel_id']??0));$mz=max(0,(int)($_POST['marzban_panel_id']??0));$gc=max(0,(int)($_POST['guardcore_panel_id']??0));$services=self::posted_ids('guardcore_service_ids_selected','guardcore_service_ids');$groups=self::posted_ids('group_ids_selected','group_ids');$mzInbounds=self::posted_marzban_inbounds();$mode=in_array((string)($_POST['multi_provider_quota_mode']??'split'),['split','full'],true)?(string)$_POST['multi_provider_quota_mode']:'split';
        foreach([[$pg,'pasarguard_panels','PasarGuard'],[$mz,'marzban_panels','Marzban'],[$gc,'guardcore_panels','GuardCore']] as [$panelId,$table,$label])if($panelId>0&&!$wpdb->get_var($wpdb->prepare('SELECT id FROM '.BlueVPN_DB::table($table).' WHERE id=%d',$panelId)))self::redirect('plans',$label.' انتخاب‌شده پیدا نشد.',true);
        if($gc>0){$g=$wpdb->get_row($wpdb->prepare('SELECT auth_mode FROM '.BlueVPN_DB::table('guardcore_panels').' WHERE id=%d',$gc),ARRAY_A);if($g&&($g['auth_mode']??'manual')!=='manual'&&!$services)self::redirect('plans','برای GuardCore خودکار حداقل یک Service ID وارد کن.',true);}
        $data=[
            'title'=>$title,'description'=>sanitize_textarea_field(wp_unslash($_POST['description']??'')),'price_toman'=>max(0,(int)($_POST['price_toman']??0)),
            'duration_days'=>max(0,min(3650,(int)($_POST['duration_days']??0))),'data_limit_gb'=>max(0,(int)($_POST['data_limit_gb']??0)),'device_limit'=>max(1,min(20,(int)($_POST['device_limit']??1))),
            'sort_order'=>(int)($_POST['sort_order']??0),'active'=>isset($_POST['active'])?1:0,'panel_id'=>$pg?:null,'marzban_panel_id'=>$mz?:null,'guardcore_panel_id'=>$gc?:null,
            'group_ids_json'=>BlueVPN_Utils::json_encode($groups),'marzban_inbounds_json'=>BlueVPN_Utils::json_encode($mzInbounds),'guardcore_service_ids_json'=>BlueVPN_Utils::json_encode($services),'marzban_quota_mode'=>$mode,'multi_provider_quota_mode'=>$mode,
        ];
        $ok=$wpdb->update($pt,$data,['id'=>$id]);self::redirect('plans',$ok===false?'ویرایش پلن ناموفق بود: '.mb_substr((string)$wpdb->last_error,0,180):'پلن با موفقیت ویرایش شد.',$ok===false);
    }

    public static function delete_plan(): void {
        self::guard();global $wpdb;$id=(int)($_POST['plan_id']??0);check_admin_referer('bluevpn_cc_delete_plan_'.$id);$t=BlueVPN_DB::table('plans');
        $used=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.BlueVPN_DB::table('customers').' WHERE plan_id=%d',$id));
        $ok=$wpdb->update($t,['deleted'=>1,'active'=>0,'deleted_at'=>BlueVPN_Utils::now_mysql()],['id'=>$id]);
        self::redirect('plans',$ok===false?'حذف نرم پلن ناموفق بود.':'پلن حذف نرم شد'.($used>0?'؛ '.$used.' کاربر فعلی همچنان سابقه این پلن را حفظ می‌کنند.':'.'),$ok===false);
    }

    public static function restore_plan(): void {
        self::guard();global $wpdb;$id=(int)($_POST['plan_id']??0);check_admin_referer('bluevpn_cc_restore_plan_'.$id);$ok=$wpdb->update(BlueVPN_DB::table('plans'),['deleted'=>0,'deleted_at'=>null,'active'=>0],['id'=>$id]);self::redirect('plans',$ok===false?'بازیابی پلن ناموفق بود.':'پلن بازیابی شد و برای بررسی اولیه غیرفعال است.',$ok===false);
    }

    public static function revoke_device(): void {
        self::guard();$customerId=(int)($_POST['customer_id']??0);$deviceId=sanitize_text_field(wp_unslash($_POST['device_id']??''));check_admin_referer('bluevpn_cc_revoke_device_'.$customerId.'_'.hash('sha256',$deviceId));
        $ok=BlueVPN_Auth::revoke_device($customerId,$deviceId,true);self::redirect('customers',$ok?'دستگاه غیرفعال و نشست‌های آن باطل شد.':'دستگاه پیدا نشد.',!$ok);
    }

    public static function revoke_session(): void {
        self::guard();$customerId=(int)($_POST['customer_id']??0);$sessionId=(int)($_POST['session_id']??0);check_admin_referer('bluevpn_cc_revoke_session_'.$customerId.'_'.$sessionId);$ok=BlueVPN_Auth::revoke_session($customerId,$sessionId);self::redirect('customers',$ok?'نشست انتخاب‌شده باطل شد.':'نشست پیدا نشد.',!$ok);
    }

    public static function logout_customer(): void {
        self::guard();$customerId=(int)($_POST['customer_id']??0);check_admin_referer('bluevpn_cc_logout_customer_'.$customerId);$count=BlueVPN_Auth::revoke_all_sessions($customerId,false);self::redirect('customers','خروج اجباری انجام شد؛ '.number_format($count).' نشست فعال باطل شد.');
    }

    public static function set_customer_status(): void {
        self::guard();global $wpdb;$customerId=(int)($_POST['customer_id']??0);check_admin_referer('bluevpn_cc_set_customer_status_'.$customerId);$active=isset($_POST['active'])?1:0;$betaTester=isset($_POST['beta_tester'])?1:0;$t=BlueVPN_DB::table('customers');$c=$wpdb->get_row($wpdb->prepare("SELECT id,phone FROM {$t} WHERE id=%d",$customerId),ARRAY_A);if(!$c)self::redirect('customers','کاربر پیدا نشد.',true);
        $wpdb->update($t,['active'=>$active,'beta_tester'=>$betaTester],['id'=>$customerId]);if(!$active)BlueVPN_Auth::revoke_all_sessions($customerId,true);
        if(!empty($c['phone'])){try{BlueVPN_SMS_Notifications::queue('account_status_changed',(string)$c['phone'],['status'=>$active?'فعال':'غیرفعال'],$customerId,null,'account-status-admin:'.$customerId.':'.$active.':'.gmdate('YmdHi'));}catch(Throwable $e){}}
        self::redirect('customers',$active?('حساب فعال شد؛ کانال بروزرسانی: '.($betaTester?'Beta 🧪':'Stable')).'':'حساب غیرفعال شد و تمام دستگاه‌ها/نشست‌ها قطع شدند.');
    }

    public static function create_private_backup(): void {
        self::guard();check_admin_referer('bluevpn_cc_create_private_backup');try{$r=BlueVPN_Production::create_backup('manual-admin');self::redirect('production','Backup خصوصی ساخته شد: '.(string)$r['filename']);}catch(Throwable $e){self::redirect('production','Backup ناموفق: '.$e->getMessage(),true);}
    }

    public static function restore_backup(): void {
        self::guard();check_admin_referer('bluevpn_cc_restore_backup');$confirm=trim((string)wp_unslash($_POST['confirm']??''));if($confirm!=='RESTORE BLUEVPN')self::redirect('production','عبارت تأیید Restore صحیح نیست.',true);
        if(empty($_FILES['backup_file'])||!is_array($_FILES['backup_file'])||(int)($_FILES['backup_file']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)self::redirect('production','فایل Backup دریافت نشد.',true);
        $size=(int)($_FILES['backup_file']['size']??0);if($size<=0||$size>100*1024*1024)self::redirect('production','حجم فایل Backup معتبر نیست.',true);
        $tmp=(string)($_FILES['backup_file']['tmp_name']??'');$json=@file_get_contents($tmp);if(!is_string($json))self::redirect('production','خواندن فایل Backup ناموفق بود.',true);
        try{$r=BlueVPN_Production::restore_from_json($json);self::redirect('production','Restore با موفقیت انجام شد؛ نسخه مبدا '.(string)$r['source_version'].'، Backup قبل از Restore: '.(string)$r['pre_restore_backup']);}catch(Throwable $e){self::redirect('production','Restore ناموفق و Transaction برگشت داده شد: '.$e->getMessage(),true);}
    }

    public static function finalize_cutover(): void {
        self::guard();check_admin_referer('bluevpn_cc_finalize_cutover');if(empty($_POST['confirmed']))self::redirect('production','تأیید نهایی لازم است.',true);try{$r=BlueVPN_Production::finalize_cutover();self::redirect('production','Production Cutover نهایی شد؛ Migration Token پاک شد. Backup: '.(string)$r['backup']);}catch(Throwable $e){self::redirect('production','نهایی‌سازی انجام نشد: '.$e->getMessage(),true);}
    }

    public static function save_payment(): void { self::guard();check_admin_referer('bluevpn_cc_save_payment');global $wpdb;$t=BlueVPN_DB::table('payment_settings');$old=$wpdb->get_row("SELECT * FROM {$t} WHERE id=1",ARRAY_A)?:[];$api=trim((string)wp_unslash($_POST['api_key']??''));$cb=trim((string)wp_unslash($_POST['callback_secret']??''));$wpdb->replace($t,['id'=>1,'base_url'=>untrailingslashit(esc_url_raw(wp_unslash($_POST['base_url']??''))),'api_key_enc'=>$api!==''?BlueVPN_Utils::encrypt_secret($api):(string)($old['api_key_enc']??''),'callback_secret_enc'=>$cb!==''?BlueVPN_Utils::encrypt_secret($cb):(string)($old['callback_secret_enc']??''),'fee_mode'=>sanitize_key((string)($_POST['fee_mode']??'default')),'ttl_minutes'=>max(5,min(60,(int)($_POST['ttl_minutes']??30))),'active'=>isset($_POST['active'])?1:0,'updated_at'=>BlueVPN_Utils::now_mysql()]);self::redirect('bluepay','تنظیمات BluePay ذخیره شد.'); }
    public static function save_sms(): void {
        self::guard();check_admin_referer('bluevpn_cc_save_sms');global $wpdb;
        $t=BlueVPN_DB::table('sms_settings');$old=$wpdb->get_row("SELECT * FROM {$t} WHERE id=1",ARRAY_A)?:[];
        $api=trim((string)wp_unslash($_POST['api_key']??''));$apiChanged=$api!=='';
        $base=untrailingslashit(esc_url_raw(wp_unslash($_POST['base_url']??'https://api.iranpayamak.com/ws/v1')));
        if($base===''||stripos($base,'edge.ippanel.com')!==false)$base='https://api.iranpayamak.com/ws/v1';
        $days=[];foreach(preg_split('/[,،;\s]+/',strtr((string)wp_unslash($_POST['reminder_days']??'3,2,1'),'۰۱۲۳۴۵۶۷۸۹','0123456789'))?:[] as $v){$n=(int)$v;if($n>=1&&$n<=30&&!in_array($n,$days,true))$days[]=$n;}if(!$days)$days=[3,2,1];sort($days,SORT_NUMERIC);$days=array_reverse($days);
        $data=$old+['id'=>1];$data['id']=1;$data['provider']='iranpayamak';$data['base_url']=$base;
        $data['api_key_enc']=$api!==''?BlueVPN_Utils::encrypt_secret($api):(string)($old['api_key_enc']??'');
        $data['from_number']=sanitize_text_field(wp_unslash($_POST['from_number']??''));
        $data['pattern_code']=sanitize_text_field(wp_unslash($_POST['pattern_code']??''));
        $requestedParam=sanitize_key((string)($_POST['parameter_name']??'code'))?:'code';
        $data['parameter_name']=BlueVPN_SMS_OTP::preferred_otp_parameter($data['pattern_code'],$requestedParam);$data['otp_length']=6;
        $data['otp_ttl_seconds']=max(60,min(600,(int)($_POST['otp_ttl_seconds']??120)));$data['resend_seconds']=max(30,min(600,(int)($_POST['resend_seconds']??60)));
        $data['active']=isset($_POST['active'])?1:0;$data['notification_active']=isset($_POST['notification_active'])?1:0;
        $data['reminder_days_json']=BlueVPN_Utils::json_encode($days);$data['low_volume_threshold_gb']=max(1,min(9999,(int)($_POST['low_volume_threshold_gb']??5)));
        $data['retry_max_attempts']=max(1,min(5,(int)($_POST['retry_max_attempts']??3)));$data['verify_tls']=isset($_POST['verify_tls'])?1:0;$data['updated_at']=BlueVPN_Utils::now_mysql();
        $validBefore=BlueVPN_SMS_OTP::active_pattern_codes();if(!$apiChanged&&$data['pattern_code']!==''&&$validBefore&&!in_array($data['pattern_code'],$validBefore,true))self::redirect('sms','پترن OTP انتخاب‌شده دیگر فعال نیست؛ فهرست پترن‌ها را تازه‌سازی و دوباره انتخاب کنید.',true);if($data['active']&&$data['pattern_code']==='')self::redirect('sms','برای فعال‌کردن OTP ابتدا یک پترن فعال انتخاب کنید.',true);
        $ok=$wpdb->replace($t,$data);
        if($ok===false)self::redirect('sms','ذخیره تنظیمات SMS ناموفق بود: '.mb_substr((string)$wpdb->last_error,0,180),true);
        if($apiChanged)BlueVPN_SMS_OTP::clear_pattern_cache();
        BlueVPN_SMS_Notifications::seed_templates();
        $wpdb->update(BlueVPN_DB::table('sms_templates'),['pattern_code'=>$data['pattern_code'],'enabled'=>$data['active'],'updated_at'=>BlueVPN_Utils::now_mysql()],['key'=>'auth_otp']);
        BlueVPN_SMS_Notifications::schedule();
        $message='تنظیمات SMS ذخیره شد.';
        $cached=BlueVPN_SMS_OTP::pattern_cache();
        if($apiChanged||empty($cached['patterns'])){
            try{$sync=BlueVPN_SMS_OTP::refresh_patterns(true);$valid=BlueVPN_SMS_OTP::active_pattern_codes();$removed=self::reconcile_sms_pattern_selections($valid);$smart=BlueVPN_SMS_Notifications::smart_assign_patterns((array)($sync['patterns']??[]),false);$message.=' '.number_format((int)($sync['count']??0)).' پترن فعال از ایران‌پیامک همگام شد.'.($removed?' '.number_format($removed).' انتخاب قدیمی/غیرفعال پاک شد.':'').' 🧠 '.number_format((int)($smart['assigned']??0)).' پترن به‌صورت هوشمند جایگذاری شد.';}
            catch(Throwable $e){$message.=' همگام‌سازی پترن‌ها ناموفق بود: '.$e->getMessage();}
        }
        self::redirect('sms',$message);
    }

    public static function refresh_sms_patterns(): void {
        self::guard();check_admin_referer('bluevpn_cc_refresh_sms_patterns');
        try{$r=BlueVPN_SMS_OTP::refresh_patterns(true);$valid=BlueVPN_SMS_OTP::active_pattern_codes();$removed=self::reconcile_sms_pattern_selections($valid);$smart=BlueVPN_SMS_Notifications::smart_assign_patterns((array)($r['patterns']??[]),false);self::redirect('sms',number_format((int)($r['count']??0)).' پترن فعال از ایران‌پیامک دریافت شد'.($removed?' و '.number_format($removed).' انتخاب قدیمی/غیرفعال پاک شد':'').'؛ 🧠 '.number_format((int)($smart['assigned']??0)).' پترن خالی هوشمند جایگذاری شد'.(!empty($smart['ambiguous'])?' و '.number_format(count((array)$smart['ambiguous'])).' مورد مبهم برای انتخاب دستی باقی ماند':'').'.');}
        catch(Throwable $e){self::redirect('sms','تازه‌سازی پترن‌ها ناموفق بود: '.$e->getMessage(),true);}
    }

    public static function smart_assign_sms_patterns(): void {
        self::guard();check_admin_referer('bluevpn_cc_smart_assign_sms_patterns');
        try{$cache=BlueVPN_SMS_OTP::pattern_cache();$patterns=(array)($cache['patterns']??[]);if(!$patterns)throw new RuntimeException('ابتدا فهرست پترن‌های ایران‌پیامک را تازه‌سازی کنید.');$overwrite=!empty($_POST['overwrite']);$smart=BlueVPN_SMS_Notifications::smart_assign_patterns($patterns,$overwrite);self::redirect('sms','🧠 جایگذاری هوشمند انجام شد: '.number_format((int)($smart['assigned']??0)).' تطبیق'.(!empty($smart['ambiguous'])?'، '.number_format(count((array)$smart['ambiguous'])).' مورد مبهم':'').(!empty($smart['unmatched'])?'، '.number_format(count((array)$smart['unmatched'])).' بدون تطبیق امن':'').'.');}
        catch(Throwable $e){self::redirect('sms','جایگذاری هوشمند ناموفق بود: '.$e->getMessage(),true);}
    }

    private static function sms_params_from_post(): array {
        $raw=trim((string)wp_unslash($_POST['params_json']??'{}'));if($raw==='')$raw='{}';$decoded=json_decode($raw,true);
        if(!is_array($decoded)||json_last_error()!==JSON_ERROR_NONE)throw new RuntimeException('JSON پارامترهای پیام معتبر نیست.');
        return $decoded;
    }

    public static function save_sms_templates(): void {
        self::guard();check_admin_referer('bluevpn_cc_save_sms_templates');global $wpdb;
        BlueVPN_SMS_Notifications::seed_templates();$table=BlueVPN_DB::table('sms_templates');
        $patterns=is_array($_POST['pattern']??null)?wp_unslash($_POST['pattern']):[];$enabled=is_array($_POST['enabled']??null)?$_POST['enabled']:[];$count=0;$validCodes=BlueVPN_SMS_OTP::active_pattern_codes();
        foreach(BlueVPN_SMS_Notifications::templates() as $row){$key=(string)$row['key'];$pattern=sanitize_text_field((string)($patterns[$key]??''));$on=isset($enabled[$key])?1:0;if($pattern!==''&&$validCodes&&!in_array($pattern,$validCodes,true))self::redirect('sms','پترن انتخاب‌شده برای «'.(string)$row['title'].'» دیگر در فهرست فعال ایران‌پیامک نیست؛ ابتدا فهرست را تازه‌سازی کنید.',true);$wpdb->update($table,['pattern_code'=>$pattern,'enabled'=>$on,'updated_at'=>BlueVPN_Utils::now_mysql()],['key'=>$key]);$count++;}
        $auth=$wpdb->get_row($wpdb->prepare("SELECT pattern_code,enabled FROM {$table} WHERE `key`=%s LIMIT 1",'auth_otp'),ARRAY_A);
        if($auth){$param=BlueVPN_SMS_OTP::preferred_otp_parameter((string)$auth['pattern_code'],'code');$wpdb->update(BlueVPN_DB::table('sms_settings'),['pattern_code'=>(string)$auth['pattern_code'],'active'=>(int)$auth['enabled'],'parameter_name'=>$param,'updated_at'=>BlueVPN_Utils::now_mysql()],['id'=>1]);}
        BlueVPN_SMS_Notifications::schedule();self::redirect('sms',number_format($count).' پترن ذخیره شد.');
    }

    public static function test_sms_template(): void {
        self::guard();check_admin_referer('bluevpn_cc_test_sms_template');
        try{$event=sanitize_key((string)($_POST['event_key']??''));$phone=sanitize_text_field(wp_unslash($_POST['test_phone']??''));$params=self::sms_params_from_post();$r=BlueVPN_SMS_Notifications::send_template_now($event,$phone,$params);self::redirect('sms','پیام تست با موفقیت به Provider تحویل شد'.(!empty($r['message_id'])?'؛ ID: '.sanitize_text_field((string)$r['message_id']):'').'.');}
        catch(Throwable $e){self::redirect('sms','ارسال تست ناموفق: '.$e->getMessage(),true);}
    }

    public static function process_sms(): void {
        self::guard();check_admin_referer('bluevpn_cc_process_sms');
        try{$a=BlueVPN_SMS_Notifications::scan_subscription_notifications();$o=BlueVPN_SMS_Notifications::scan_order_notifications();$r=BlueVPN_SMS_Notifications::process(100);self::redirect('sms','صف پردازش شد؛ ارسال موفق: '.(int)$r['sent'].'، خطا/Retry: '.(int)$r['failed'].'، اعلان جدید: '.((int)$a['queued']+(int)$o['queued']).'.');}
        catch(Throwable $e){self::redirect('sms','پردازش صف ناموفق: '.$e->getMessage(),true);}
    }

    public static function broadcast_sms(): void {
        self::guard();check_admin_referer('bluevpn_cc_broadcast_sms');
        try{$event=sanitize_key((string)($_POST['event_key']??''));$params=self::sms_params_from_post();$only=(string)($_POST['audience']??'active')!=='all';$count=BlueVPN_SMS_Notifications::broadcast($event,$params,$only);self::redirect('sms',number_format($count).' پیام در صف ارسال عمومی قرار گرفت.');}
        catch(Throwable $e){self::redirect('sms','ارسال عمومی ناموفق: '.$e->getMessage(),true);}
    }

    public static function retry_sms(): void {
        self::guard();$id=sanitize_text_field(wp_unslash($_POST['delivery_id']??''));check_admin_referer('bluevpn_cc_retry_sms_'.$id);
        if($id===''||!BlueVPN_SMS_Notifications::retry($id))self::redirect('sms','پیام برای Retry پیدا نشد.',true);
        try{BlueVPN_SMS_Notifications::process(10);}catch(Throwable $e){}
        self::redirect('sms','پیام برای ارسال مجدد فعال شد.');
    }
    public static function repair_customer_providers(): void {
        self::guard();$id=(int)($_GET['customer_id']??0);check_admin_referer('bluevpn_cc_repair_customer_providers_'.$id);
        $r=BlueVPN_Providers::repair_customer_missing_providers($id);
        self::redirect('customers',(string)($r['message']??'ترمیم Provider انجام شد.'),empty($r['ok']));
    }

    public static function repair_missing_provider_subscriptions(): void {
        self::guard();check_ajax_referer('bluevpn_cc_repair_missing_provider_subscriptions');
        $cursor=max(0,(int)($_POST['cursor']??0));$jobId=sanitize_key((string)($_POST['job_id']??''));$uid=get_current_user_id();
        if($jobId==='')$jobId=str_replace('-','',wp_generate_uuid4());
        $key='bluevpn_provider_repair_'.substr(sha1($uid.':'.$jobId),0,32);
        $summary=get_transient($key);if(!is_array($summary))$summary=['total'=>BlueVPN_Providers::repairable_customer_count(),'processed'=>0,'created'=>0,'attached'=>0,'existing'=>0,'skipped'=>0,'error_count'=>0,'errors'=>[],'seen'=>[],'started_at'=>BlueVPN_Utils::tehran_datetime_fa()];
        $ids=BlueVPN_Providers::repair_candidate_ids_after($cursor,1);
        if(!$ids){
            $summary['finished_at']=BlueVPN_Utils::tehran_datetime_fa();$final=$summary;unset($final['seen']);update_option('bluevpn_provider_repair_last_result',$final,false);delete_transient($key);
            wp_send_json_success(['done'=>true,'job_id'=>$jobId,'next_cursor'=>$cursor,'summary'=>$final]);
        }
        $id=(int)$ids[0];$seen=is_array($summary['seen']??null)?$summary['seen']:[];
        if(empty($seen[(string)$id])){
            $r=BlueVPN_Providers::repair_customer_missing_providers($id);$seen[(string)$id]=1;$summary['seen']=$seen;$summary['processed']++;
            if(empty($r['eligible']))$summary['skipped']++;
            $summary['created']+=(int)($r['created']??0);$summary['attached']+=(int)($r['attached']??0);$summary['existing']+=(int)($r['existing']??0);
            if(empty($r['ok'])){$summary['error_count']++;$msg='کاربر #'.$id.': '.(string)($r['message']??'خطای نامشخص');$summary['errors'][]=mb_substr($msg,0,500);$summary['errors']=array_slice($summary['errors'],-20);}
        }
        set_transient($key,$summary,HOUR_IN_SECONDS);
        $next=BlueVPN_Providers::repair_candidate_ids_after($id,1);$done=empty($next);
        if($done){$summary['finished_at']=BlueVPN_Utils::tehran_datetime_fa();$final=$summary;unset($final['seen']);update_option('bluevpn_provider_repair_last_result',$final,false);delete_transient($key);$summary=$final;}
        else{$public=$summary;unset($public['seen']);$summary=$public;}
        wp_send_json_success(['done'=>$done,'job_id'=>$jobId,'next_cursor'=>$id,'summary'=>$summary]);
    }

    public static function sync_customer(): void { self::guard();$id=(int)($_GET['customer_id']??0);check_admin_referer('bluevpn_cc_sync_customer_'.$id);$r=BlueVPN_Providers::sync_customer($id,true);self::redirect('customers',$r['message'],!$r['ok']); }
    public static function manual_activate(): void {
        self::guard();check_admin_referer('bluevpn_cc_manual_activate');global $wpdb;
        $customerId=(int)($_POST['customer_id']??0);$planId=(int)($_POST['plan_id']??0);$r=BlueVPN_Providers::provision_customer($customerId,$planId);
        if(($r['ok']||($r['partial']??false))&&class_exists('BlueVPN_SMS_Notifications')){
            try{$c=$wpdb->get_row($wpdb->prepare('SELECT id,phone,subscription_expire FROM '.BlueVPN_DB::table('customers').' WHERE id=%d',$customerId),ARRAY_A);$p=$wpdb->get_row($wpdb->prepare('SELECT title FROM '.BlueVPN_DB::table('plans').' WHERE id=%d',$planId),ARRAY_A);if($c&&!empty($c['phone'])&&$p)BlueVPN_SMS_Notifications::queue('admin_subscription_activated',(string)$c['phone'],['plan'=>mb_substr((string)$p['title'],0,40),'expire_date'=>BlueVPN_SMS_Notifications::jalali_date((string)($c['subscription_expire']??''))],$customerId,null,'admin-subscription:'.$customerId.':'.$planId.':'.(string)($c['subscription_expire']??''));}catch(Throwable $e){error_log('BlueVPN manual activation SMS: '.$e->getMessage());}
        }
        self::redirect('manual',$r['message'],!$r['ok']&&!($r['partial']??false));
    }
    public static function guardcore_refresh_catalog(): void {
        self::guard();
        $id=(int)($_POST['panel_id']??0);
        check_admin_referer('bluevpn_cc_guardcore_refresh_catalog_'.$id);
        $r=BlueVPN_Providers::guardcore_catalog($id,true);
        self::redirect('guardcore',$r['ok']?'GuardCore API همگام شد: '.($r['version']??''):'همگام‌سازی GuardCore ناموفق: '.($r['message']??''),empty($r['ok']));
    }

    public static function guardcore_bootstrap_key(): void {
        self::guard();
        $id=(int)($_POST['panel_id']??0);
        check_admin_referer('bluevpn_cc_guardcore_bootstrap_key_'.$id);
        $code=preg_replace('/\D+/','',(string)wp_unslash($_POST['totp_code']??''))?:'';
        $r=BlueVPN_Providers::guardcore_bootstrap_api_key($id,$code);
        self::redirect('guardcore',$r['message'],empty($r['ok']));
    }

    public static function guardcore_node_action(): void {
        self::guard();
        $panelId=(int)($_POST['panel_id']??0);
        $nodeId=(int)($_POST['node_id']??0);
        check_admin_referer('bluevpn_cc_guardcore_node_action_'.$panelId.'_'.$nodeId);
        $enable=!empty($_POST['enable']);
        $r=BlueVPN_Providers::guardcore_node_action($panelId,$nodeId,$enable);
        self::redirect('guardcore',$r['message'],empty($r['ok']));
    }

    public static function guardcore_subscription_action(): void {
        self::guard();
        $panelId=(int)($_POST['panel_id']??0);
        $customerId=(int)($_POST['customer_id']??0);
        $username=sanitize_text_field(wp_unslash($_POST['username']??''));
        $op=sanitize_key((string)($_POST['operation']??''));
        check_admin_referer('bluevpn_cc_guardcore_subscription_action_'.$panelId.'_'.$customerId);
        $r=BlueVPN_Providers::guardcore_subscription_action($panelId,$username,$op);
        if(!empty($r['ok'])&&$customerId>0)BlueVPN_Providers::request_background_sync($customerId);
        self::redirect('guardcore',$r['message'],empty($r['ok']));
    }

    public static function refresh_guardcore_stats(): void {
        self::guard();
        check_admin_referer('bluevpn_cc_refresh_guardcore_stats');
        global $wpdb;
        $t=BlueVPN_DB::table('customers');
        $ids=$wpdb->get_col(
            "SELECT id FROM {$t}
             WHERE active=1
               AND (
                    guardcore_panel_id IS NOT NULL
                    OR (guardcore_subscription_url IS NOT NULL AND TRIM(guardcore_subscription_url)<>'')
               )
             ORDER BY id DESC
             LIMIT 500"
        )?:[];
        $queued=0;
        foreach($ids as $id){
            if(BlueVPN_Providers::request_background_snapshot((int)$id))$queued++;
        }
        self::redirect('guardcore','بروزرسانی آمار GuardCore برای '.$queued.' کاربر در صف قرار گرفت.');
    }

    public static function attach_guardcore(): void { self::guard();$id=(int)($_POST['customer_id']??0);check_admin_referer('bluevpn_cc_attach_guardcore_'.$id);$r=BlueVPN_Providers::attach_guardcore($id,(string)wp_unslash($_POST['subscription_url']??''));self::redirect('guardcore-manual',$r['message'],!$r['ok']); }
    public static function save_app_update_policy(): void {
        self::guard();check_admin_referer('bluevpn_cc_save_app_update_policy');
        $owner=sanitize_text_field(wp_unslash($_POST['owner']??''));$repo=sanitize_text_field(wp_unslash($_POST['repo']??''));
        BlueVPN_App_Release_Manager::save_settings(['owner'=>$owner,'repo'=>$repo,'auto_sync'=>isset($_POST['app_auto_sync']),'title_override'=>sanitize_text_field(wp_unslash($_POST['title_override']??'')),'message_override'=>sanitize_textarea_field(wp_unslash($_POST['message_override']??''))]);
        $s=BlueVPN_DB::settings();
        $min=sanitize_text_field(wp_unslash($_POST['minimum_version']??'0.0.0'));if(!preg_match('/^\d+\.\d+\.\d+$/',$min))$min='0.0.0';
        $s['minimum_version']=$min;$s['support_url']=esc_url_raw(wp_unslash($_POST['support_url']??''));$s['auto_update_stable']=isset($_POST['auto_update_stable']);$s['auto_update_beta']=isset($_POST['auto_update_beta']);$s['auto_update']=$s['auto_update_stable'];$s['maintenance']=isset($_POST['maintenance']);
        BlueVPN_DB::save_settings($s);BlueVPN_App_Release_Manager::ensure_schedule();
        self::redirect('app','سیاست بروزرسانی اپ ذخیره شد.');
    }
    public static function release_promote(): void {
        self::guard();$id=(int)($_POST['release_id']??0);check_admin_referer('bluevpn_cc_release_promote_'.$id);$r=BlueVPN_App_Release_Manager::promote_to_stable($id);self::redirect('app',(string)$r['message'],empty($r['ok']));
    }
    public static function release_stop(): void {
        self::guard();$id=(int)($_POST['release_id']??0);check_admin_referer('bluevpn_cc_release_stop_'.$id);$r=BlueVPN_App_Release_Manager::stop_beta($id);self::redirect('app',(string)$r['message'],empty($r['ok']));
    }
    public static function release_resume(): void {
        self::guard();$id=(int)($_POST['release_id']??0);check_admin_referer('bluevpn_cc_release_resume_'.$id);$r=BlueVPN_App_Release_Manager::resume_beta($id);self::redirect('app',(string)$r['message'],empty($r['ok']));
    }
    public static function release_force(): void {
        self::guard();$id=(int)($_POST['release_id']??0);check_admin_referer('bluevpn_cc_release_force_'.$id);$r=BlueVPN_App_Release_Manager::toggle_force_update($id);self::redirect('app',(string)$r['message'],empty($r['ok']));
    }

    public static function sync_app_release(): void {
        self::guard();check_admin_referer('bluevpn_cc_sync_app_release');
        $r=BlueVPN_App_Release_Manager::sync_now(true,'wordpress_admin');
        self::redirect('app',(string)($r['message']??'همگام‌سازی انجام شد.'),empty($r['ok']));
    }
    public static function export_backup(): void { self::guard();check_admin_referer('bluevpn_cc_export_backup');global $wpdb;nocache_headers();header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="bluevpn-wordpress-backup-'.gmdate('Ymd-His').'.json"');echo '{"meta":'.wp_json_encode(['version'=>BLUEVPN_MANAGER_VERSION,'exported_at'=>BlueVPN_Utils::iso_now(),'site'=>home_url('/')]).',"tables":{';$first=true;foreach(BlueVPN_DB::table_names() as $name){if(!$first)echo ',';$first=false;echo wp_json_encode($name).':';$rows=$wpdb->get_results('SELECT * FROM '.BlueVPN_DB::table($name),ARRAY_A);echo wp_json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}echo '}}';exit; }
}
