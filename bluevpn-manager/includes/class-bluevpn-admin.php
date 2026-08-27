<?php
if (!defined('ABSPATH')) exit;
final class BlueVPN_Admin {
    public static function init(): void {
        // Register BlueVPN pages before later admin_menu customizers so WordPress
        // has every route in its access registry before admin.php resolves page=...
        add_action('admin_menu',[self::class,'menu'],1);
        add_action('admin_post_bluevpn_save_settings',[self::class,'save_settings']);
        add_action('admin_post_bluevpn_add_plan',[self::class,'add_plan']);
        add_action('admin_post_bluevpn_toggle_plan',[self::class,'toggle_plan']);
        add_action('admin_post_bluevpn_toggle_customer',[self::class,'toggle_customer']);
        add_action('admin_post_bluevpn_repair',[self::class,'repair']);
        add_action('admin_post_bluevpn_save_github_updater',[self::class,'save_github_updater']);
        add_action('admin_post_bluevpn_check_github_update',[self::class,'check_github_update']);
        add_action('admin_post_bluevpn_test_app_connection',[self::class,'test_app_connection']);
        add_action('admin_post_bluevpn_enable_app_cutover',[self::class,'enable_app_cutover']);
    }
    private static function guard(): void { if(!current_user_can('manage_options')) wp_die('دسترسی ندارید.'); }
    public static function menu(): void {
        add_menu_page('BlueVPN Control Center','BlueVPN','manage_options','bluevpn-manager',[BlueVPN_Control_Center::class,'page'],'dashicons-shield-alt',3);
        add_submenu_page('bluevpn-manager','نمای کلی','نمای کلی','manage_options','bluevpn-manager',[BlueVPN_Control_Center::class,'page']);
        $sections=[
            ['bluevpn-blueai','BlueAI','blueai'],
            ['bluevpn-ads','تبلیغات','ads'],
            ['bluevpn-free-access','اتصال رایگان','free'],
            ['bluevpn-database','دیتابیس','database'],
            ['bluevpn-production','سلامت و Backup','production'],
            ['bluevpn-pasarguard','PasarGuard','panels'],
            ['bluevpn-marzban','Marzban','marzban'],
            ['bluevpn-shahrah','Shahrah','shahrah'],
            ['bluevpn-guardcore','GuardCore','guardcore'],
            ['bluevpn-guardcore-queue','صف GuardCore','guardcore-manual'],
            ['bluevpn-subscription-sources','Sourceهای اشتراک','sources'],
            ['bluevpn-gateway','Gateway Metering','gateway'],
            ['bluevpn-plans','پلن‌ها','plans'],
            ['bluevpn-payments','پرداخت / بلوپال','blupal'],
            ['bluevpn-manual','فعال‌سازی دستی','manual'],
            ['bluevpn-customers','کاربران','customers'],
            ['bluevpn-manual-customers','مشتریان دستی','manual-customers'],
            ['bluevpn-orders','پرداخت‌ها','orders'],
            ['bluevpn-sms','SMS / OTP','sms'],
            ['bluevpn-app-update','اپ و آپدیت','app'],
        ];
        foreach($sections as [$slug,$label,$section]){
            add_submenu_page('bluevpn-manager',$label,$label,'manage_options',$slug,static function() use($section){ BlueVPN_Control_Center::render_section($section); });
        }
        add_submenu_page('bluevpn-manager','اتصال اپلیکیشن','اتصال اپلیکیشن','manage_options','bluevpn-app-connection',[self::class,'app_connection_page']);
        add_submenu_page('bluevpn-manager','تنظیمات BlueVPN','تنظیمات','manage_options','bluevpn-settings',[self::class,'settings_page']);
        add_submenu_page('bluevpn-manager','وضعیت انتقال','وضعیت انتقال','manage_options','bluevpn-migration',[self::class,'migration_page']);
        add_submenu_page('bluevpn-manager','آپدیت افزونه','آپدیت افزونه','manage_options','bluevpn-github-updater',[self::class,'github_updater_page']);

        // Register every page exposed by the unified BlueVPN sidebar here, on the
        // same admin_menu pass as the parent. WordPress otherwise may reject a
        // direct admin.php?page=... request before a later-priority specialist
        // class gets a chance to register its submenu.
        add_submenu_page('bluevpn-manager','پشتیبانی آنلاین','پشتیبانی آنلاین','manage_options','bluevpn-support',[BlueVPN_Support::class,'admin_page']);
        add_submenu_page('bluevpn-manager','ربات تلگرام','ربات تلگرام','manage_options','bluevpn-telegram-bot',[BlueVPN_Telegram_Bot::class,'admin_page']);
        add_submenu_page('bluevpn-manager','خطاها و مانیتورینگ','خطاها و مانیتورینگ','manage_options','bluevpn-error-monitor',[BlueVPN_Error_Monitor::class,'admin_page']);

        // Do not mutate $submenu after registration. The custom BlueVPN sidebar is
        // already the primary navigation, while keeping native submenu entries
        // intact guarantees WordPress keeps every registered page addressable.
    }
    private static function head(string $title): void { BlueVPN_Unified_UI::shell_open($title); echo '<div class="wrap" dir="rtl"><style>.bvp-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;max-width:1100px}.bvp-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:18px}.bvp-ok{color:#34d399;font-weight:700}.bvp-warn{color:#fbbf24;font-weight:700}.bvp-code{direction:ltr;text-align:left;background:#f6f7f7;padding:10px;border-radius:6px;overflow:auto}.bvp-table{background:#fff;max-width:1200px}.bvp-table th,.bvp-table td{text-align:right}</style>'; }
    private static function foot(): void { echo '</div>'; BlueVPN_Unified_UI::shell_close(); }
    public static function dashboard(): void {
        self::guard();
        $s = BlueVPN_DB::status();
        $counts = $s['ready'] ? BlueVPN_DB::counts() : [];
        $cutover = get_option('bluevpn_manager_cutover_ready','0') === '1';
        $appUrl = admin_url('admin.php?page=bluevpn-app-connection');
        self::head('BlueVPN Manager');
        echo '<div class="notice notice-success"><p><strong>✅ انتقال کامل است.</strong> WordPress/MySQL تنها Control Plane فعال BlueVPN است. <a href="'.esc_url($appUrl).'">بررسی اتصال اپلیکیشن</a></p></div>';
        echo '<div class="bvp-grid">';
        echo '<div class="bvp-card"><h3>MySQL</h3><p class="'.($s['ready']?'bvp-ok':'bvp-warn').'">'.($s['ready']?'آماده':'نیاز به تعمیر').'</p><small>Schema '.esc_html($s['schema_version']).'</small></div>';
        echo '<div class="bvp-card"><h3>جداول BlueVPN</h3><p><strong>'.count(array_filter($counts,fn($v)=>$v>=0)).' / '.count(BlueVPN_DB::table_names()).'</strong></p></div>';
        echo '<div class="bvp-card"><h3>اتصال اپلیکیشن</h3><div class="bvp-code">'.esc_html(untrailingslashit(home_url('/'))).'</div><p><a class="button button-primary" href="'.esc_url($appUrl).'">تست و دریافت Endpoint</a></p></div>';
        echo '<div class="bvp-card"><h3>Health API</h3><div class="bvp-code">'.esc_html(home_url('/health')).'</div></div></div>';
        echo '<h2>وضعیت داده‌ها</h2><table class="widefat striped bvp-table"><thead><tr><th>جدول</th><th>رکورد</th></tr></thead><tbody>';
        foreach($counts as $k=>$v) echo '<tr><td>'.esc_html($k).'</td><td>'.esc_html((string)$v).'</td></tr>';
        echo '</tbody></table>';
        self::foot();
    }
    public static function settings_page(): void {
        self::guard();
        $s=BlueVPN_DB::settings();
        self::head('تنظیمات عمومی BlueVPN');
        if(isset($_GET['saved'])) echo '<div class="notice notice-success"><p>تنظیمات ذخیره شد.</p></div>';
        echo '<div class="notice notice-info"><p>تنظیمات عملیاتی، Backup، WARP، تبلیغات و انتشار از این صفحه جدا شده‌اند و در بخش تخصصی خودشان مدیریت می‌شوند.</p></div>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('bluevpn_save_settings');
        echo '<input type="hidden" name="action" value="bluevpn_save_settings">';
        echo '<div class="bluevpn-settings-grid">';

        echo '<section class="bluevpn-settings-section"><h2>هویت اپلیکیشن</h2><p>نام و آدرس عمومی Control Plane.</p><table class="form-table">';
        echo '<tr><th>نام اپ</th><td><input class="regular-text" type="text" name="app_name" value="'.esc_attr((string)$s['app_name']).'"></td></tr>';
        echo '<tr><th>Base URL عمومی</th><td><input class="regular-text" dir="ltr" type="url" name="public_base_url" value="'.esc_attr((string)$s['public_base_url']).'"><p class="description">نسخه و فایل‌های نصب در «اپ و انتشار» مدیریت می‌شوند.</p></td></tr>';
        echo '</table></section>';

        echo '<section class="bluevpn-settings-section"><h2>اعلان سراسری</h2><p>پیامی که در کلاینت‌ها نمایش داده می‌شود.</p><table class="form-table">';
        echo '<tr><th>وضعیت</th><td><label><input type="checkbox" name="announcement_enabled" value="1" '.checked(!empty($s['announcement_enabled']),true,false).'> نمایش اعلان فعال باشد</label></td></tr>';
        echo '<tr><th>عنوان</th><td><input class="regular-text" type="text" name="announcement_title" value="'.esc_attr((string)$s['announcement_title']).'"></td></tr>';
        echo '<tr><th>متن</th><td><textarea name="announcement_message" rows="4">'.esc_textarea((string)$s['announcement_message']).'</textarea></td></tr>';
        echo '</table></section>';

        echo '<section class="bluevpn-settings-section"><h2>تنظیمات تخصصی</h2><p>هر حوزه تنظیمات مستقل خودش را دارد.</p>';
        $links=[
            'سلامت و Backup'=>'bluevpn-production',
            'اتصال رایگان / WARP'=>'bluevpn-free-access',
            'تبلیغات'=>'bluevpn-ads',
            'اپ و انتشار'=>'bluevpn-app-update',
            'خطاها و مانیتورینگ'=>'bluevpn-error-monitor',
            'آپدیت Manager'=>'bluevpn-github-updater',
        ];
        echo '<div class="bvc-actions">';
        foreach($links as $label=>$page) echo '<a class="button" href="'.esc_url(admin_url('admin.php?page='.$page)).'">'.esc_html($label).'</a>';
        echo '</div></section>';

        echo '<section class="bluevpn-settings-section"><h2>Endpointهای سیستم</h2><p>برای عیب‌یابی و اتصال سرویس‌ها.</p>';
        echo '<div class="bvp-code">'.esc_html(untrailingslashit(home_url('/'))).'</div>';
        echo '<p><a class="button" href="'.esc_url(admin_url('admin.php?page=bluevpn-app-connection')).'">بررسی اتصال اپلیکیشن</a></p></section>';

        echo '<div class="bluevpn-settings-actions">';
        submit_button('ذخیره تنظیمات عمومی','primary','submit',false);
        echo '</div></div></form>';
        self::foot();
    }

    public static function save_settings(): void { self::guard();check_admin_referer('bluevpn_save_settings');$s=BlueVPN_DB::settings();foreach(['app_name','public_base_url','announcement_title','announcement_message'] as $k)$s[$k]=sanitize_text_field(wp_unslash($_POST[$k]??''));$s['announcement_enabled']=isset($_POST['announcement_enabled']);BlueVPN_DB::save_settings($s);wp_safe_redirect(admin_url('admin.php?page=bluevpn-settings&saved=1'));exit; }
    private static function app_connection_redirect(string $msg='', string $error=''): void {
        $args=['page'=>'bluevpn-app-connection'];
        if($msg!=='')$args['msg']=$msg;
        if($error!=='')$args['error']=$error;
        wp_safe_redirect(add_query_arg($args,admin_url('admin.php')));exit;
    }
    public static function app_connection_page(): void {
        self::guard();
        $site=untrailingslashit(home_url('/'));
        $cutover=get_option('bluevpn_manager_cutover_ready','0')==='1';
        $enabled=get_option('bluevpn_manager_app_cutover_enabled','0')==='1';
        $test=get_transient('bluevpn_app_connection_test_'.get_current_user_id());
        if($test!==false)delete_transient('bluevpn_app_connection_test_'.get_current_user_id());
        self::head('اتصال اپلیکیشن BlueVPN');
        echo '<style>.bvp-endpoint{font-family:monospace;direction:ltr;text-align:left;background:#f6f7f7;border:1px solid #dcdcde;border-radius:8px;padding:11px;word-break:break-all}.bvp-check{margin:8px 0;padding:10px;border-radius:8px;background:#f6f7f7}.bvp-check.ok{background:#edfaef;color:#135e24}.bvp-check.bad{background:#fcf0f1;color:#8a2424}</style>';
        if(isset($_GET['msg']))echo '<div class="notice notice-success"><p>'.esc_html(sanitize_text_field(wp_unslash($_GET['msg']))).'</p></div>';
        if(isset($_GET['error']))echo '<div class="notice notice-error"><p>'.esc_html(sanitize_text_field(wp_unslash($_GET['error']))).'</p></div>';
        echo '<div class="notice notice-success"><p><strong>✅ WordPress/MySQL مقصد نهایی فعال است.</strong> مسیر Legacy از Runtime خارج شده و این API مرجع Android و Windows است.</p></div>';
        echo '<div class="bvp-grid">';
        echo '<div class="bvp-card"><h3>API Base URL برای Android</h3><div class="bvp-endpoint">'.esc_html($site).'</div><p class="description">همین مقدار باید در <code>branding/app.json → api_base_url</code> قرار بگیرد. اپ خودش <code>/api/v1/...</code> را اضافه می‌کند.</p></div>';
        echo '<div class="bvp-card"><h3>وضعیت اتصال اپ</h3><p class="'.($enabled?'bvp-ok':'bvp-warn').'">'.($enabled?'فعال روی WordPress':'هنوز Cutover اپ تأیید نشده').'</p><small>نسخه افزونه '.esc_html(BLUEVPN_MANAGER_VERSION).'</small></div>';
        echo '<div class="bvp-card"><h3>REST API مستقیم</h3><div class="bvp-endpoint">'.esc_html(untrailingslashit(rest_url('bluevpn/v1'))).'</div></div>';
        echo '<div class="bvp-card"><h3>کلید API ثابت</h3><p><strong>لازم نیست</strong></p><small>ورود کاربر Token نشست خودش را دریافت می‌کند.</small></div>';
        echo '</div>';
        echo '<h2>Endpointهای آماده</h2><table class="widefat striped bvp-table"><tbody>';
        $eps=[
          'Health'=>$site.'/health',
          'Mobile Config'=>$site.'/api/v1/mobile/config',
          'Login'=>$site.'/api/v1/auth/login',
          'Register'=>$site.'/api/v1/auth/register',
          'Plans'=>$site.'/api/v1/plans',
          'System Info'=>rest_url('bluevpn-system/v1/app-connection'),
        ];
        foreach($eps as $label=>$url)echo '<tr><th>'.esc_html($label).'</th><td><div class="bvp-endpoint">'.esc_html($url).'</div></td></tr>';
        echo '</tbody></table>';
        if(is_array($test)){
            echo '<h2>نتیجه آخرین تست</h2>';
            foreach($test as $name=>$row){$ok=!empty($row['ok']);echo '<div class="bvp-check '.($ok?'ok':'bad').'"><strong>'.esc_html($name).': '.($ok?'✅ موفق':'❌ ناموفق').'</strong><br><span>'.esc_html((string)($row['message']??'')).'</span></div>';}
        }
        echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:18px">';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_test_app_connection');echo '<input type="hidden" name="action" value="bluevpn_test_app_connection">';submit_button('تست کامل API وردپرس','secondary','submit',false);echo '</form>';
        echo '</div>';
        echo '<div class="bvp-card" style="max-width:900px;margin-top:18px"><h2>Control Plane فعال</h2><pre class="bvp-endpoint">"api_base_url": "'.esc_html($site).'"</pre><p>این Endpoint تنها مرجع فعال BlueVPN است؛ Migration Bridge بازنشسته شده است.</p></div>';
        self::foot();
    }
    public static function test_app_connection(): void {
        self::guard();check_admin_referer('bluevpn_test_app_connection');
        $site=untrailingslashit(home_url('/'));
        $targets=[
          'Health سازگار'=>$site.'/health',
          'Mobile Config سازگار'=>$site.'/api/v1/mobile/config',
          'REST مستقیم'=>rest_url('bluevpn/v1/mobile/config'),
        ];
        $out=[];$mobileJson=null;
        foreach($targets as $name=>$url){
            $r=wp_remote_get($url,['timeout'=>10,'redirection'=>3,'sslverify'=>true,'headers'=>['Cache-Control'=>'no-cache']]);
            if(is_wp_error($r)){$out[$name]=['ok'=>false,'message'=>$r->get_error_message()];continue;}
            $code=(int)wp_remote_retrieve_response_code($r);$body=(string)wp_remote_retrieve_body($r);
            $json=json_decode($body,true);
            $ok=$code>=200&&$code<300&&is_array($json);
            $out[$name]=['ok'=>$ok,'message'=>'HTTP '.$code.($ok?' · JSON معتبر':' · پاسخ API معتبر نیست')];
            if($name==='Mobile Config سازگار'&&$ok)$mobileJson=$json;
        }
        if(is_array($mobileJson)){
            $ad=$mobileJson['advertising']??null;$adOk=is_array($ad)&&array_key_exists('enabled',$ad)&&isset($ad['interval_ms'])&&is_array($ad['items']??null);
            $out['قرارداد تبلیغات Android']=['ok'=>$adOk,'message'=>$adOk?'advertising + interval_ms + items آماده است.':'ساختار advertising برای Android ناقص است.'];
            $ai=$mobileJson['blueai']??null;$aiOk=is_array($ai)&&array_key_exists('enabled',$ai)&&array_key_exists('collective',$ai)&&array_key_exists('auto_heal',$ai);
            $out['قرارداد BlueAI']=['ok'=>$aiOk,'message'=>$aiOk?('BlueAI API contract آماده است · '.(!empty($ai['enabled'])?'فعال':'فعلاً غیرفعال در تنظیمات')):'ساختار BlueAI در mobile/config ناقص است.'];
        }
        global $wpdb;
        $assetTable=BlueVPN_DB::table('ad_assets');$assetId=(string)$wpdb->get_var("SELECT id FROM {$assetTable} ORDER BY created_at DESC LIMIT 1");
        if($assetId!==''){
            $r=wp_remote_get($site.'/api/v1/ad-assets/'.rawurlencode($assetId),['timeout'=>10,'redirection'=>0,'sslverify'=>true]);
            $code=is_wp_error($r)?0:(int)wp_remote_retrieve_response_code($r);$type=is_wp_error($r)?'':(string)wp_remote_retrieve_header($r,'content-type');
            $body=is_wp_error($r)?'':(string)wp_remote_retrieve_body($r);$imageInfo=$body!==''?@getimagesizefromstring($body):false;
            $ok=!is_wp_error($r)&&$code===200&&str_starts_with(strtolower($type),'image/')&&is_array($imageInfo)&&!empty($imageInfo[0])&&!empty($imageInfo[1]);
            $out['Asset تبلیغات MySQL']=['ok'=>$ok,'message'=>$ok?('تصویر قابل Decode است · '.$imageInfo[0].'×'.$imageInfo[1].' · '.strlen($body).' bytes'):(is_wp_error($r)?$r->get_error_message():'HTTP '.$code.' · '.$type.' · body='.strlen($body).' bytes')];
        } else {
            $out['Asset تبلیغات MySQL']=['ok'=>true,'message'=>'هنوز تصویر محلی ثبت نشده؛ بعد از افزودن بنر این تست فعال می‌شود.'];
        }
        $aiTables=['ai_connection_events','ai_live_connections','ai_route_aggregates','ai_feedback'];$missing=[];foreach($aiTables as $name){$table=BlueVPN_DB::table($name);if((string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table)$missing[]=$name;}
        $out['جداول BlueAI MySQL']=['ok'=>!$missing,'message'=>$missing?('جدول‌های مفقود: '.implode(', ',$missing)):'هر چهار جدول BlueAI آماده‌اند.'];
        set_transient('bluevpn_app_connection_test_'.get_current_user_id(),$out,10*MINUTE_IN_SECONDS);
        $failed=array_filter($out,fn($x)=>empty($x['ok']));
        self::app_connection_redirect($failed?'تست انجام شد؛ بعضی Endpointها نیاز به بررسی دارند.':'تست کامل API، تبلیغات و BlueAI با موفقیت انجام شد.');
    }
    public static function enable_app_cutover(): void {
        self::guard();check_admin_referer('bluevpn_enable_app_cutover');
        if(get_option('bluevpn_manager_cutover_ready','0')!=='1')self::app_connection_redirect('','ابتدا مهاجرت باید به مرحله آماده Cutover برسد.');
        $s=BlueVPN_DB::settings();
        $s['public_base_url']=untrailingslashit(home_url('/'));
        $s['updated_at']=BlueVPN_Utils::iso_now();
        BlueVPN_DB::save_settings($s);
        update_option('bluevpn_manager_app_cutover_enabled','1',false);
        BlueVPN_Compat::register_rewrites();flush_rewrite_rules(false);
        self::app_connection_redirect('اتصال WordPress برای APK تأیید شد. Build بعدی باید با API Base URL همین صفحه ساخته شود.');
    }
    public static function plans_page(): void { self::guard();global $wpdb;$t=BlueVPN_DB::table('plans');$rows=$wpdb->get_results("SELECT * FROM {$t} WHERE deleted=0 ORDER BY sort_order,id",ARRAY_A);self::head('پلن‌های BlueVPN');echo '<div class="bvp-card" style="max-width:900px"><h2>افزودن پلن</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_add_plan');echo '<input type="hidden" name="action" value="bluevpn_add_plan"><input name="title" placeholder="عنوان" required> <input type="number" min="0.000001" step="0.000001" name="usd_price" placeholder="قیمت USD" required> <input type="number" name="duration_days" placeholder="روز" required> <input type="number" name="data_limit_gb" placeholder="گیگ" value="0"> <input type="number" name="device_limit" placeholder="دستگاه" value="1"><br><br><textarea name="description" placeholder="توضیحات" rows="3" style="width:100%"></textarea>';submit_button('افزودن پلن','primary','submit',false);echo '</form></div><h2>لیست</h2><table class="widefat striped bvp-table"><tr><th>ID</th><th>عنوان</th><th>قیمت USD</th><th>معادل تومان</th><th>روز</th><th>حجم</th><th>دستگاه</th><th>وضعیت</th></tr>';foreach($rows as $x){$url=wp_nonce_url(admin_url('admin-post.php?action=bluevpn_toggle_plan&id='.(int)$x['id']),'bluevpn_toggle_plan_'.$x['id']);echo '<tr><td>'.$x['id'].'</td><td>'.esc_html($x['title']).'</td><td>'.((int)($x['usd_managed']??0)===1?esc_html((string)$x['usd_price']):'تنظیم نشده').'</td><td>'.number_format((int)$x['price_toman']).'</td><td>'.$x['duration_days'].'</td><td>'.$x['data_limit_gb'].'</td><td>'.$x['device_limit'].'</td><td><a href="'.esc_url($url).'">'.((int)$x['active']?'فعال':'غیرفعال').'</a></td></tr>';}echo '</table>';self::foot(); }
    public static function add_plan(): void {
        self::guard();check_admin_referer('bluevpn_add_plan');global $wpdb;
        $ids=function(string $name): array {$out=[];foreach(preg_split('/[,\s]+/',(string)wp_unslash($_POST[$name]??''))?:[] as $v){$n=(int)$v;if($n>0&&!in_array($n,$out,true))$out[]=$n;}return array_slice($out,0,200);};
        $postedIds=function(string $arrayName,string $legacyName): array {$raw=$_POST[$arrayName]??null;if(is_array($raw))$vals=$raw;elseif($arrayName==='group_ids_selected'&&isset($_POST['pasarguard_access_picker_present']))$vals=[];else$vals=preg_split('/[,\s]+/',(string)wp_unslash($_POST[$legacyName]??''))?:[];$out=[];foreach($vals as $v){$n=(int)$v;if($n>0&&!in_array($n,$out,true))$out[]=$n;}return array_slice($out,0,200);};
        $mzSelected=function(): array {$raw=$_POST['marzban_inbounds_selected']??[];if(!is_array($raw))return [];$out=[];foreach($raw as $token){$parts=explode('|',(string)wp_unslash($token),2);if(count($parts)!==2)continue;$proto=sanitize_key($parts[0]);$tag=sanitize_text_field($parts[1]);if(!in_array($proto,['vless','vmess','trojan','shadowsocks'],true)||$tag==='')continue;if(!in_array($tag,$out[$proto]??[],true))$out[$proto][]=$tag;}return $out;};
        $pg=max(0,(int)($_POST['panel_id']??0));$mz=max(0,(int)($_POST['marzban_panel_id']??0));$gc=max(0,(int)($_POST['guardcore_panel_id']??0));$services=$postedIds('guardcore_service_ids_selected','guardcore_service_ids');$groups=$postedIds('group_ids_selected','group_ids');$marzbanInbounds=$mzSelected();$mode=sanitize_key((string)wp_unslash($_POST['multi_provider_quota_mode']??'split'));$mode=in_array($mode,['split','full'],true)?$mode:'split';$trafficMode=sanitize_key((string)wp_unslash($_POST['traffic_mode']??'provider_reported'));$trafficMode=$trafficMode==='gateway_metered'?'gateway_metered':'provider_reported';$gatewayReplicas=max(1,min(3,(int)($_POST['gateway_replica_count']??2)));$sourceIds=$postedIds('source_ids_selected','source_ids');
        foreach([[$pg,'pasarguard_panels'],[$mz,'marzban_panels'],[$gc,'guardcore_panels']] as [$id,$table])if($id>0&&!$wpdb->get_var($wpdb->prepare('SELECT id FROM '.BlueVPN_DB::table($table).' WHERE id=%d',$id))){wp_safe_redirect(add_query_arg('cc_error','Provider انتخاب‌شده پیدا نشد.',admin_url('admin.php?page=bluevpn-plans')));exit;}
        if($gc>0){$auth=$wpdb->get_var($wpdb->prepare('SELECT auth_mode FROM '.BlueVPN_DB::table('guardcore_panels').' WHERE id=%d',$gc));if($auth&&$auth!=='manual'&&!$services){wp_safe_redirect(add_query_arg('cc_error','برای GuardCore خودکار حداقل یک Service ID وارد کن.',admin_url('admin.php?page=bluevpn-plans')));exit;}}
        $usd=round(max(0,(float)($_POST['usd_price']??0)),6);try{$price=BlueVPN_Dollar_Pricing::quote_toman($usd);}catch(Throwable $e){wp_safe_redirect(add_query_arg('cc_error',$e->getMessage(),admin_url('admin.php?page=bluevpn-plans')));exit;}
        $ok=$wpdb->insert(BlueVPN_DB::table('plans'),['title'=>sanitize_text_field(wp_unslash($_POST['title']??'')),'description'=>sanitize_textarea_field(wp_unslash($_POST['description']??'')),'price_toman'=>$price,'usd_price'=>$usd,'usd_managed'=>1,'usd_last_price_toman'=>$price,'usd_updated_at'=>BlueVPN_Utils::now_mysql(),'duration_days'=>max(0,(int)($_POST['duration_days']??0)),'data_limit_gb'=>max(0,(int)($_POST['data_limit_gb']??0)),'device_limit'=>max(1,min(10,(int)($_POST['device_limit']??1))),'group_ids_json'=>BlueVPN_Utils::json_encode($groups),'marzban_inbounds_json'=>BlueVPN_Utils::json_encode($marzbanInbounds),'active'=>1,'deleted'=>0,'sort_order'=>(int)($_POST['sort_order']??0),'panel_id'=>$pg?:null,'marzban_panel_id'=>$mz?:null,'marzban_quota_mode'=>$mode,'guardcore_panel_id'=>$gc?:null,'guardcore_service_ids_json'=>BlueVPN_Utils::json_encode($services),'multi_provider_quota_mode'=>$mode,'traffic_mode'=>$trafficMode,'gateway_replica_count'=>$gatewayReplicas,'source_ids_json'=>BlueVPN_Utils::json_encode($sourceIds),'created_at'=>BlueVPN_Utils::now_mysql()]);
        wp_safe_redirect(add_query_arg($ok===false?'cc_error':'cc_msg',$ok===false?'افزودن پلن ناموفق بود.':'پلن اضافه شد.',admin_url('admin.php?page=bluevpn-plans')));exit;
    }
    public static function toggle_plan(): void { self::guard();$id=(int)($_GET['id']??0);check_admin_referer('bluevpn_toggle_plan_'.$id);global $wpdb;$t=BlueVPN_DB::table('plans');$v=(int)$wpdb->get_var($wpdb->prepare("SELECT active FROM {$t} WHERE id=%d",$id));$wpdb->update($t,['active'=>$v?0:1],['id'=>$id]);wp_safe_redirect(admin_url('admin.php?page=bluevpn-plans'));exit; }
    public static function customers_page(): void { self::guard();global $wpdb;$t=BlueVPN_DB::table('customers');$rows=$wpdb->get_results("SELECT id,email,phone,active,plan_id,subscription_status,created_at FROM {$t} ORDER BY id DESC LIMIT 200",ARRAY_A);self::head('کاربران BlueVPN');echo '<table class="widefat striped bvp-table"><tr><th>ID</th><th>کاربر</th><th>پلن</th><th>اشتراک</th><th>وضعیت</th></tr>';foreach($rows as $x){$u=wp_nonce_url(admin_url('admin-post.php?action=bluevpn_toggle_customer&id='.(int)$x['id']),'bluevpn_toggle_customer_'.$x['id']);echo '<tr><td>'.$x['id'].'</td><td>'.esc_html($x['phone']?:$x['email']).'</td><td>'.esc_html((string)$x['plan_id']).'</td><td>'.esc_html($x['subscription_status']).'</td><td><a href="'.esc_url($u).'">'.((int)$x['active']?'فعال':'غیرفعال').'</a></td></tr>';}echo '</table>';self::foot(); }
    public static function toggle_customer(): void {
        self::guard();$id=(int)($_GET['id']??0);check_admin_referer('bluevpn_toggle_customer_'.$id);global $wpdb;$t=BlueVPN_DB::table('customers');
        $c=$wpdb->get_row($wpdb->prepare("SELECT id,phone,active FROM {$t} WHERE id=%d",$id),ARRAY_A);if(!$c){wp_safe_redirect(admin_url('admin.php?page=bluevpn-customers'));exit;}
        $new=(int)$c['active']?0:1;$wpdb->update($t,['active'=>$new],['id'=>$id]);
        if(!empty($c['phone'])&&class_exists('BlueVPN_SMS_Notifications')){try{BlueVPN_SMS_Notifications::queue($new?'account_unblocked':'account_temporarily_blocked',(string)$c['phone'],[],$id,null,'account-status:'.$id.':'.$new.':'.gmdate('YmdHi'));}catch(Throwable $e){BlueVPN_Error_Monitor::legacy_error_log('BlueVPN account status SMS: '.$e->getMessage());}}
        wp_safe_redirect(admin_url('admin.php?page=bluevpn-customers'));exit;
    }
    public static function migration_page(): void {
        self::guard();
        if (class_exists('BlueVPN_Production')) BlueVPN_Production::ensure_native_control_plane();
        $db=BlueVPN_DB::status();$counts=BlueVPN_DB::counts();
        $finalized=(string)get_option('bluevpn_manager_production_finalized_at','');
        self::head('وضعیت انتقال BlueVPN');
        echo '<div class="notice notice-success"><p><strong>✅ مهاجرت نهایی شده است.</strong> بک‌اند Legacy از Runtime حذف شده و WordPress/MySQL تنها Control Plane فعال BlueVPN است.</p></div>';
        echo '<div class="bvp-grid">';
        echo '<div class="bvp-card"><h3>Control Plane</h3><p class="bvp-ok">WordPress / MySQL</p><small>حالت Native و دائمی</small></div>';
        echo '<div class="bvp-card"><h3>Legacy Bridge</h3><p class="bvp-ok">خاموش و بازنشسته</p><small>Migration Cron، Source URL و Token فعال نیست</small></div>';
        echo '<div class="bvp-card"><h3>Schema</h3><p class="'.(!empty($db['ready'])?'bvp-ok':'bvp-warn').'">'.(!empty($db['ready'])?'آماده':'نیازمند بررسی').'</p><small>'.esc_html((string)($db['schema_version']??'')).'</small></div>';
        echo '<div class="bvp-card"><h3>نهایی‌سازی</h3><p>'.esc_html($finalized!==''?BlueVPN_Utils::tehran_datetime_fa($finalized):'در حال ثبت').'</p></div>';
        echo '</div>';
        echo '<h2>Endpoint فعال</h2><div class="bvp-code">'.esc_html(untrailingslashit(home_url('/'))).'</div>';
        echo '<h2>داده‌های محلی</h2><table class="widefat striped bvp-table"><thead><tr><th>جدول</th><th>رکورد</th></tr></thead><tbody>';
        foreach($counts as $name=>$count) echo '<tr><td><code>'.esc_html($name).'</code></td><td>'.number_format_i18n((int)$count).'</td></tr>';
        echo '</tbody></table>';
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
        echo '<div class="bvp-card"><h3>آپدیت خودکار واقعی</h3><p class="'.(!empty($cfg['auto_update'])?'bvp-ok':'bvp-warn').'">'.(!empty($cfg['auto_update'])?'فعال؛ بررسی و نصب بدون دخالت مدیر':'غیرفعال').'</p></div>';
        $diag = BlueVPN_GitHub_Updater::diagnostics();
        $auth = !empty($diag['authenticated']);
        echo '<div class="bvp-card"><h3>دسترسی GitHub</h3><p class="'.($auth?'bvp-ok':'bvp-warn').'"><strong>'.($auth?'Authenticated':'Unauthenticated').'</strong></p><small>'.($auth?'از GITHUB_TOKEN مهاجرت‌شده ربات برای Release API و دانلود Asset استفاده می‌شود.':'Updater بدون Token است؛ مخزن خصوصی یا Rate Limit می‌تواند آپدیت را متوقف کند.').'</small></div>';
        $last_bg = BlueVPN_GitHub_Updater::last_background_check();
        echo '<div class="bvp-card"><h3>بررسی خودکار GitHub</h3><p class="bvp-ok"><strong>هر ۲ دقیقه</strong></p><small>'.($last_bg ? 'آخرین اجرا: '.esc_html(BlueVPN_Utils::tehran_datetime_fa((int)$last_bg)) : 'در انتظار اولین اجرای پس‌زمینه').'</small></div>';
        $auto_status = BlueVPN_GitHub_Updater::auto_update_status();
        $auto_class = in_array((string)$auto_status['status'], ['installed','up_to_date'], true) ? 'bvp-ok' : (((string)$auto_status['status'] === 'never') ? 'bvp-warn' : 'bvp-warn');
        echo '<div class="bvp-card"><h3>آخرین نصب خودکار</h3><p class="'.esc_attr($auto_class).'"><strong>'.esc_html((string)$auto_status['status']).'</strong></p><small>'.esc_html((string)$auto_status['message']).($auto_status['at'] ? ' · '.esc_html(BlueVPN_Utils::tehran_datetime_fa((int)$auto_status['at'])) : '').'</small></div>';
        echo '</div>';

        echo '<div class="bvp-card" style="max-width:900px;margin-top:18px"><h2>تنظیمات Updater</h2>';
        echo '<p>بررسی و نصب نسخه جدید به‌صورت خودکار در پس‌زمینه انجام می‌شود؛ دکمه بررسی دستی فقط برای عیب‌یابی اضطراری است. برای اینکه Releaseهای Android با افزونه قاطی نشوند، فقط Tagهایی با پیشوند تعیین‌شده و Asset دقیق زیر پذیرفته می‌شوند.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('bluevpn_save_github_updater');
        echo '<input type="hidden" name="action" value="bluevpn_save_github_updater">';
        echo '<table class="form-table">';
        echo '<tr><th>GitHub Owner</th><td><input class="regular-text" dir="ltr" name="owner" value="'.esc_attr($cfg['owner']).'"></td></tr>';
        echo '<tr><th>Repository</th><td><input class="regular-text" dir="ltr" name="repo" value="'.esc_attr($cfg['repo']).'"></td></tr>';
        echo '<tr><th>Tag Prefix</th><td><input class="regular-text" dir="ltr" name="tag_prefix" value="'.esc_attr($cfg['tag_prefix']).'"><p class="description">مثال: bluevpn-manager-vX.Y.Z</p></td></tr>';
        echo '<tr><th>Release Asset</th><td><input class="regular-text" dir="ltr" name="asset_name" value="'.esc_attr($cfg['asset_name']).'"></td></tr>';
        echo '<tr><th>آپدیت خودکار</th><td><label><input type="checkbox" name="auto_update" value="1" '.checked(!empty($cfg['auto_update']),true,false).'> BlueVPN Manager نسخه جدید را خودش پیدا کند و بدون ورود مدیر همان لحظه نصب کند.</label></td></tr>';
        echo '</table>';
        submit_button('ذخیره تنظیمات GitHub');
        echo '</form>';
        echo '<hr><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('bluevpn_check_github_update');
        echo '<input type="hidden" name="action" value="bluevpn_check_github_update">';
        submit_button('همین حالا GitHub را بررسی کن','secondary');
        echo '</form>';
        echo '<p><strong>قرارداد Release:</strong> Tag باید مانند <code>bluevpn-manager-vX.Y.Z</code> و فایل Release باید <code>bluevpn-manager.zip</code> باشد.</p></div>';
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
    public static function repair(): void { self::guard();check_admin_referer('bluevpn_repair');BlueVPN_DB::install_schema();BlueVPN_DB::seed_defaults();BlueVPN_DB::seed_release_channels();BlueVPN_Compat::register_rewrites();flush_rewrite_rules(false);wp_safe_redirect(admin_url('admin.php?page=bluevpn-migration&repaired=1'));exit; }
}
