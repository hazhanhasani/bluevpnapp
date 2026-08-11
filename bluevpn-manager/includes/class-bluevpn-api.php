<?php
if (!defined('ABSPATH')) exit;

final class BlueVPN_API {
    public static function init(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_filter('rest_post_dispatch', [self::class, 'headers'], 10, 3);
    }
    public static function register_routes(): void {
        register_rest_route('bluevpn-system/v1','/health',['methods'=>'GET','callback'=>[self::class,'health'],'permission_callback'=>'__return_true']);
        register_rest_route('bluevpn-system/v1','/app-connection',['methods'=>'GET','callback'=>[self::class,'app_connection'],'permission_callback'=>'__return_true']);
        $routes = [
            ['/mobile/config','GET','mobile_config'],
            ['/ad-assets/(?P<asset_id>[A-Za-z0-9_-]{6,64})','GET','ad_asset'],
            ['/free/subscription','GET','free_subscription'],
            ['/free/subscriptions/(?P<item_id>[A-Za-z0-9_-]{1,64})','GET','free_subscription'],
            ['/auth/register','POST','register'], ['/auth/login','POST','login'],
            ['/auth/otp/request','POST','otp_request'], ['/auth/otp/verify','POST','otp_verify'],
            ['/auth/refresh','POST','refresh'], ['/auth/logout','POST','logout'],
            ['/account/phone/otp/request','POST','bind_phone_otp_request'], ['/account/phone/otp/verify','POST','bind_phone_otp_verify'],
            ['/plans','GET','plans'], ['/account','GET','account'], ['/account/sync','POST','account_sync'],
            ['/server-locations/resolve','POST','resolve_locations'], ['/server-locations/verify','POST','verify_location'],
            ['/ai/events','POST','ai_event'], ['/ai/recommendations','GET','ai_recommendations'], ['/ai/dashboard','GET','ai_dashboard'], ['/feedback','POST','feedback'],
            ['/orders','POST','create_order'],
            ['/orders/(?P<order_id>[A-Za-z0-9_-]{8,80})','GET','order_status'],
            ['/orders/(?P<order_id>[A-Za-z0-9_-]{8,80})/checkout/open','POST','checkout_open'],
            ['/orders/(?P<order_id>[A-Za-z0-9_-]{8,80})/checkout/heartbeat','POST','checkout_heartbeat'],
            ['/orders/(?P<order_id>[A-Za-z0-9_-]{8,80})/checkout/close','POST','checkout_close'],
            ['/orders/(?P<order_id>[A-Za-z0-9_-]{8,80})/check-after-success','GET','check_after_success'],
            ['/webhooks/bluepay','POST','bluepay_webhook'],
            ['/sub/(?P<token>[A-Za-z0-9_-]{10,100})','GET','subscription'],
        ];
        foreach ($routes as [$route,$method,$handler]) register_rest_route('bluevpn/v1',$route,['methods'=>$method,'callback'=>[self::class,$handler],'permission_callback'=>'__return_true']);
    }
    public static function headers($response,$server,$request){
        if ($request instanceof WP_REST_Request && (str_starts_with($request->get_route(),'/bluevpn/') || str_starts_with($request->get_route(),'/bluevpn-system/'))) {
            $response->header('Content-Language','fa-IR'); $response->header('X-BlueVPN-Timezone','Asia/Tehran'); $response->header('X-BlueVPN-Calendar','jalali');
            $headers = $response->get_headers();
            if (($headers['X-BlueVPN-Raw'] ?? '') !== '1') $response->header('Cache-Control','no-store');
        }
        return $response;
    }
    private static function ok(array $data,int $status=200): WP_REST_Response { return new WP_REST_Response($data,$status); }
    private static function fail(BlueVPN_Auth_Exception $e): WP_REST_Response { return self::ok(['detail'=>array_merge(['code'=>$e->error_code,'message'=>$e->getMessage()],$e->extra)],$e->http_status); }
    private static function body(WP_REST_Request $r): array { $b=$r->get_json_params(); return is_array($b)?$b:[]; }
    public static function health(): WP_REST_Response { $db=BlueVPN_DB::status(); return self::ok(['status'=>$db['ready']?'ok':'error','service'=>'bluevpn-wordpress-platform','version'=>BLUEVPN_MANAGER_VERSION,'server_time'=>BlueVPN_Utils::iso_now(),'server_time_fa'=>BlueVPN_Utils::tehran_datetime_fa(),'calendar'=>'jalali','timezone'=>'Asia/Tehran','database'=>$db,'counts'=>$db['ready']?BlueVPN_DB::counts():[],'migration'=>['phase'=>2,'state'=>BlueVPN_Migration::state()['phase'],'cutover_ready'=>get_option('bluevpn_manager_cutover_ready','0')==='1']]); }
    public static function app_connection(): WP_REST_Response {
        $site = untrailingslashit(home_url('/'));
        return self::ok([
            'status' => 'ok',
            'service' => 'bluevpn-wordpress-app-connection',
            'version' => BLUEVPN_MANAGER_VERSION,
            // Android appends /api/v1/* to this root URL.
            'api_base_url' => $site,
            'compat_api_prefix' => '/api/v1',
            'rest_api_base_url' => untrailingslashit(rest_url('bluevpn/v1')),
            'health_url' => $site . '/health',
            'mobile_config_url' => $site . '/api/v1/mobile/config',
            'login_url' => $site . '/api/v1/auth/login',
            'register_url' => $site . '/api/v1/auth/register',
            'otp_request_url' => $site . '/api/v1/auth/otp/request',
            'otp_verify_url' => $site . '/api/v1/auth/otp/verify',
            'web_login_url' => $site . '/bluevpn-login/',
            'features' => [
                'advertising' => true, 'ad_assets' => true, 'tapsell' => true, 'free_access' => true,
                'blueai_events' => true, 'blueai_recommendations' => true, 'blueai_dashboard' => true,
                'orders' => true, 'bluepay_webhook' => true, 'bind_phone_otp' => true, 'provider_sync' => true,
            ],
            'static_api_key_required' => false,
            'cutover_ready' => get_option('bluevpn_manager_cutover_ready','0') === '1',
            'app_cutover_enabled' => get_option('bluevpn_manager_app_cutover_enabled','0') === '1',
        ]);
    }
    public static function mobile_config(WP_REST_Request $r): WP_REST_Response {
        $forced = rest_sanitize_boolean($r->get_param('refresh'));
        if ($forced && class_exists('BlueVPN_App_Release_Manager')) {
            // Many phones can request a manual refresh at the same time. One
            // GitHub check per minute is enough for all clients.
            if ((time() - BlueVPN_App_Release_Manager::last_sync()) >= 60) {
                BlueVPN_App_Release_Manager::sync_now(true, 'android_forced_refresh');
            }
        } elseif (class_exists('BlueVPN_App_Release_Manager')) {
            BlueVPN_App_Release_Manager::maybe_kick();
        }
        $s=BlueVPN_DB::settings();
        $publishedMysql=BlueVPN_Utils::mysql_from_iso((string)($s['release_published_at']??''));
        return self::ok([
            'app_name'=>$s['app_name'],
            'maintenance'=>(bool)$s['maintenance'],
            'support_url'=>$s['support_url'],
            'minimum_version'=>$s['minimum_version'],
            'force_update'=>(bool)$s['force_update'],
            'auto_update'=>(bool)$s['auto_update'],
            'account_required'=>true,
            'latest_version'=>$s['latest_version'],
            'latest_version_code'=>(int)$s['latest_version_code'],
            'apk_url'=>$s['apk_url'],
            'apk_assets'=>is_array($s['apk_assets']??null)?$s['apk_assets']:[],
            'apk_asset_meta'=>is_array($s['apk_asset_meta']??null)?$s['apk_asset_meta']:[],
            'update_title'=>$s['update_title'],
            'update_message'=>$s['update_message'],
            'release_url'=>(string)($s['release_url']??''),
            'release_published_at'=>(string)($s['release_published_at']??''),
            'release_published_at_fa'=>$publishedMysql?BlueVPN_Utils::tehran_datetime_fa($publishedMysql):'',
            'release_build_number'=>(int)($s['release_build_number']??0),
            'release_commit'=>(string)($s['release_commit']??''),
            'update_source'=>(string)($s['update_source']??'wordpress_settings'),
            'github_repository'=>(string)($s['github_repository']??''),
            'github_error'=>(string)($s['github_error']??''),
            'release_cache_seconds'=>(int)($s['release_cache_seconds']??15),
            'release_refresh_forced'=>$forced,
            'auth'=>array_merge(['mode'=>'phone_otp_or_email_password','password_login'=>true,'email_login'=>true,'email_registration'=>true],BlueVPN_SMS_OTP::public_config(),['request_url'=>untrailingslashit(home_url('/')).'/api/v1/auth/otp/request','verify_url'=>untrailingslashit(home_url('/')).'/api/v1/auth/otp/verify','login_url'=>untrailingslashit(home_url('/')).'/api/v1/auth/login','register_url'=>untrailingslashit(home_url('/')).'/api/v1/auth/register']),
            'blueai'=>['enabled'=>(bool)$s['blueai_enabled'],'collective'=>(bool)$s['blueai_collective'],'auto_heal'=>(bool)$s['blueai_auto_heal'],'min_samples'=>(int)$s['blueai_min_samples'],'privacy_message'=>$s['blueai_privacy_message']],
            'announcement'=>['enabled'=>(bool)$s['announcement_enabled'],'id'=>$s['announcement_id'],'title'=>$s['announcement_title'],'message'=>$s['announcement_message']],
            'advertising'=>BlueVPN_Ads::advertising_payload($s,$r),
            'tapsell'=>BlueVPN_Ads::tapsell_payload($s),
            'free_access'=>BlueVPN_Ads::free_access_payload($s),
            'updated_at'=>$s['updated_at'],
            'updated_at_fa'=>BlueVPN_Utils::tehran_datetime_fa(),
            'calendar'=>'jalali','timezone'=>'Asia/Tehran','migration_phase'=>2,'migration_state'=>BlueVPN_Migration::state()['phase'],'cutover_ready'=>get_option('bluevpn_manager_cutover_ready','0')==='1'
        ]);
    }
    public static function ad_asset(WP_REST_Request $r): WP_REST_Response { return BlueVPN_Ads::asset_response($r); }
    public static function free_subscription(WP_REST_Request $r): WP_REST_Response { return BlueVPN_Ads::free_subscription($r); }

    public static function bind_phone_otp_request(WP_REST_Request $r): WP_REST_Response {
        try { $c=BlueVPN_Auth::current_customer($r); $b=self::body($r); return self::ok(BlueVPN_SMS_OTP::request_bind($c,(string)($b['phone']??''),(string)($b['device_id']??''))); }
        catch(BlueVPN_Auth_Exception $e){ return self::fail($e); }
    }
    public static function bind_phone_otp_verify(WP_REST_Request $r): WP_REST_Response {
        try { $c=BlueVPN_Auth::current_customer($r); $b=self::body($r); return self::ok(BlueVPN_SMS_OTP::verify_bind($c,(string)($b['phone']??''),(string)($b['challenge_id']??''),(string)($b['code']??''),(string)($b['device_id']??''))); }
        catch(BlueVPN_Auth_Exception $e){ return self::fail($e); }
    }

    public static function ai_event(WP_REST_Request $r): WP_REST_Response {
        try {
            $c=BlueVPN_Auth::current_customer($r); $s=BlueVPN_DB::settings();
            if(empty($s['blueai_enabled'])) return self::ok(['success'=>true,'accepted'=>false,'reason'=>'disabled']);
            $b=self::body($r); if(($b['consent']??null)!==true) return self::ok(['success'=>true,'accepted'=>false,'reason'=>'consent_required']);
            try { $result=BlueVPN_AI::submit_event($c,$b); } catch(InvalidArgumentException $e) { throw new BlueVPN_Auth_Exception(422,'AI_EVENT_INVALID',$e->getMessage()); }
            return self::ok(array_merge(['success'=>true],$result));
        } catch(BlueVPN_Auth_Exception $e){ return self::fail($e); }
    }
    public static function ai_recommendations(WP_REST_Request $r): WP_REST_Response {
        try {
            BlueVPN_Auth::current_customer($r); $s=BlueVPN_DB::settings(); $enabled=!empty($s['blueai_enabled']);
            $rows=$enabled?BlueVPN_AI::recommendations((string)($r->get_param('operator')??'unknown'),(string)($r->get_param('network_type')??'unknown'),(string)($r->get_param('mode')??'balanced'),$r->get_param('hour'),30):[];
            return self::ok(['success'=>true,'enabled'=>$enabled,'collective'=>!empty($s['blueai_collective']),'recommendations'=>$rows,'generated_at'=>BlueVPN_Utils::iso_now(),'generated_at_fa'=>BlueVPN_Utils::tehran_datetime_fa(),'calendar'=>'jalali','timezone'=>'Asia/Tehran']);
        } catch(BlueVPN_Auth_Exception $e){ return self::fail($e); }
    }
    public static function ai_dashboard(WP_REST_Request $r): WP_REST_Response { try{$c=BlueVPN_Auth::current_customer($r);return self::ok(['success'=>true,'dashboard'=>BlueVPN_AI::dashboard((int)$c['id'])]);}catch(BlueVPN_Auth_Exception $e){return self::fail($e);} }
    public static function feedback(WP_REST_Request $r): WP_REST_Response { try{$c=BlueVPN_Auth::current_customer($r);return self::ok(array_merge(['success'=>true],BlueVPN_AI::feedback((int)$c['id'],self::body($r))));}catch(BlueVPN_Auth_Exception $e){return self::fail($e);} }

    public static function create_order(WP_REST_Request $r): WP_REST_Response { try{$c=BlueVPN_Auth::current_customer($r);return self::ok(BlueVPN_Payments::create($c,self::body($r)));}catch(BlueVPN_Auth_Exception $e){return self::fail($e);} }
    public static function order_status(WP_REST_Request $r): WP_REST_Response { try{$c=BlueVPN_Auth::current_customer($r);return self::ok(BlueVPN_Payments::get($c,(string)$r['order_id'],true));}catch(BlueVPN_Auth_Exception $e){return self::fail($e);} }
    public static function checkout_open(WP_REST_Request $r): WP_REST_Response { try{$c=BlueVPN_Auth::current_customer($r);return self::ok(BlueVPN_Payments::checkout($c,(string)$r['order_id'],'open'));}catch(BlueVPN_Auth_Exception $e){return self::fail($e);} }
    public static function checkout_heartbeat(WP_REST_Request $r): WP_REST_Response { try{$c=BlueVPN_Auth::current_customer($r);return self::ok(BlueVPN_Payments::checkout($c,(string)$r['order_id'],'heartbeat'));}catch(BlueVPN_Auth_Exception $e){return self::fail($e);} }
    public static function checkout_close(WP_REST_Request $r): WP_REST_Response { try{$c=BlueVPN_Auth::current_customer($r);return self::ok(BlueVPN_Payments::checkout($c,(string)$r['order_id'],'close'));}catch(BlueVPN_Auth_Exception $e){return self::fail($e);} }
    public static function check_after_success(WP_REST_Request $r): WP_REST_Response {
        try{$c=BlueVPN_Auth::current_customer($r);$result=BlueVPN_Payments::get($c,(string)$r['order_id'],true);$status=(string)($result['order']['status']??'');$pending=in_array($status,['','pending','created','creating','creating_invoice','processing','waiting','unpaid','paid','paid_needs_sync','partial_needs_sync'],true);return self::ok(array_merge(['success'=>true,'confirmed'=>$status==='activated','pending'=>$pending,'attempts'=>1,'elapsed_seconds'=>0,'retry_after_seconds'=>$pending?5:0,'server_time'=>BlueVPN_Utils::iso_now(),'server_time_fa'=>BlueVPN_Utils::tehran_datetime_fa(),'calendar'=>'jalali','timezone'=>'Asia/Tehran'],$result));}catch(BlueVPN_Auth_Exception $e){return self::fail($e);}
    }
    public static function bluepay_webhook(WP_REST_Request $r): WP_REST_Response { return BlueVPN_Payments::webhook($r); }

    public static function otp_request(WP_REST_Request $r): WP_REST_Response { try{$b=self::body($r);return self::ok(BlueVPN_SMS_OTP::request((string)($b['phone']??''),(string)($b['device_id']??'')));}catch(BlueVPN_Auth_Exception $e){return self::fail($e);} }
    public static function otp_verify(WP_REST_Request $r): WP_REST_Response { try{$b=self::body($r);return self::ok(BlueVPN_SMS_OTP::verify((string)($b['phone']??''),(string)($b['challenge_id']??''),(string)($b['code']??''),(string)($b['device_id']??''),(string)($b['device_name']??'')));}catch(BlueVPN_Auth_Exception $e){return self::fail($e);} }
    public static function register(WP_REST_Request $r): WP_REST_Response { try{$b=self::body($r);$email=BlueVPN_Auth::normalize_email((string)($b['email']??''));$pass=BlueVPN_Auth::validate_password((string)($b['password']??''));$c=BlueVPN_Auth::create_customer($email,$pass);$tok=BlueVPN_Auth::issue_session($c,(string)($b['device_id']??''),(string)($b['device_name']??''));return self::ok(['success'=>true,'is_new_account'=>true,'token'=>$tok['token'],'refresh_token'=>$tok['refresh_token'],'account'=>BlueVPN_Auth::account_payload($c)]);}catch(BlueVPN_Auth_Exception $e){return self::fail($e);} }
    public static function login(WP_REST_Request $r): WP_REST_Response { try{$b=self::body($r);$email=BlueVPN_Auth::normalize_email((string)($b['email']??''));$c=BlueVPN_Auth::customer_by_email($email);if(!$c||!BlueVPN_Auth::password_verify_compat((string)($b['password']??''),(string)$c['password_hash'])) throw new BlueVPN_Auth_Exception(401,'INVALID_CREDENTIALS','ایمیل یا رمز عبور نادرست است.');if(!(int)$c['active']) throw new BlueVPN_Auth_Exception(401,'ACCOUNT_DISABLED','این حساب غیرفعال شده است.');$tok=BlueVPN_Auth::issue_session($c,(string)($b['device_id']??''),(string)($b['device_name']??''));return self::ok(['success'=>true,'is_new_account'=>false,'token'=>$tok['token'],'refresh_token'=>$tok['refresh_token'],'account'=>BlueVPN_Auth::account_payload($c)]);}catch(BlueVPN_Auth_Exception $e){return self::fail($e);} }
    public static function refresh(WP_REST_Request $r): WP_REST_Response { try{$b=self::body($r);$x=BlueVPN_Auth::refresh_session((string)($b['phone']??$b['identity']??$b['email']??''),(string)($b['device_id']??''),(string)($b['refresh_token']??''),(string)($b['device_name']??''));return self::ok(['success'=>true,'token'=>$x['tokens']['token'],'refresh_token'=>$x['tokens']['refresh_token'],'account'=>BlueVPN_Auth::account_payload($x['customer'])]);}catch(BlueVPN_Auth_Exception $e){return self::fail($e);} }
    public static function logout(WP_REST_Request $r): WP_REST_Response { BlueVPN_Auth::logout($r); return self::ok(['success'=>true]); }
    public static function plans(WP_REST_Request $r): WP_REST_Response { try{BlueVPN_Auth::current_customer($r);global $wpdb;$t=BlueVPN_DB::table('plans');$rows=$wpdb->get_results("SELECT id,title,description,price_toman,duration_days,data_limit_gb,device_limit FROM {$t} WHERE active=1 AND deleted=0 ORDER BY sort_order,price_toman",ARRAY_A);foreach($rows as &$x){foreach(['id','price_toman','duration_days','data_limit_gb','device_limit'] as $k)$x[$k]=(int)$x[$k];}return self::ok(['success'=>true,'plans'=>$rows]);}catch(BlueVPN_Auth_Exception $e){return self::fail($e);} }
    public static function account(WP_REST_Request $r): WP_REST_Response { try{$c=BlueVPN_Auth::current_customer($r);BlueVPN_Providers::sync_customer((int)$c['id']);$fresh=BlueVPN_Auth::get_customer((int)$c['id']);return self::ok(['success'=>true,'account'=>BlueVPN_Auth::account_payload($fresh)]);}catch(BlueVPN_Auth_Exception $e){return self::fail($e);} }
    public static function account_sync(WP_REST_Request $r): WP_REST_Response { try{$c=BlueVPN_Auth::current_customer($r);$sync=BlueVPN_Providers::sync_customer((int)$c['id']);$fresh=BlueVPN_Auth::get_customer((int)$c['id']);return self::ok(['success'=>true,'sync'=>$sync,'account'=>BlueVPN_Auth::account_payload($fresh)]);}catch(BlueVPN_Auth_Exception $e){return self::fail($e);} }
    public static function resolve_locations(WP_REST_Request $r): WP_REST_Response { try{BlueVPN_Auth::current_customer($r);$b=self::body($r);$keys=array_values(array_unique(array_filter(array_map(fn($v)=>strtolower(trim((string)$v)),is_array($b['keys']??null)?array_slice($b['keys'],0,600):[]),fn($v)=>preg_match('/^[a-f0-9]{40}$/',$v))));if(!$keys)return self::ok(['success'=>true,'locations'=>[],'count'=>0]);global $wpdb;$t=BlueVPN_DB::table('server_locations');$ph=implode(',',array_fill(0,count($keys),'%s'));$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE config_key IN ($ph)",...$keys),ARRAY_A);$out=[];foreach($rows as $x)$out[]=self::location_payload($x);return self::ok(['success'=>true,'locations'=>$out,'count'=>count($out)]);}catch(BlueVPN_Auth_Exception $e){return self::fail($e);} }
    public static function verify_location(WP_REST_Request $r): WP_REST_Response { try{BlueVPN_Auth::current_customer($r);$b=self::body($r);$key=strtolower(trim((string)($b['config_key']??'')));$cc=strtolower(trim((string)($b['country_code']??'')));if(!preg_match('/^[a-f0-9]{40}$/',$key))throw new BlueVPN_Auth_Exception(422,'SERVER_LOCATION_KEY_INVALID','شناسه سرور معتبر نیست');if(!preg_match('/^[a-z]{2}$/',$cc))throw new BlueVPN_Auth_Exception(422,'SERVER_COUNTRY_INVALID','کد کشور معتبر نیست');global $wpdb;$t=BlueVPN_DB::table('server_locations');$now=BlueVPN_Utils::now_mysql();$wpdb->replace($t,['config_key'=>$key,'country_code'=>$cc,'source'=>mb_substr(sanitize_key((string)($b['source']??'client_trace')),0,40),'confidence'=>100,'verified_at'=>$now,'updated_at'=>$now]);$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE config_key=%s",$key),ARRAY_A);return self::ok(['success'=>true,'location'=>self::location_payload($row)]);}catch(BlueVPN_Auth_Exception $e){return self::fail($e);} }
    private static function location_payload(array $x): array { return ['config_key'=>$x['config_key'],'country_code'=>$x['country_code'],'source'=>$x['source'],'confidence'=>(int)$x['confidence'],'verified_at'=>BlueVPN_Utils::iso_from_mysql($x['verified_at']??null),'updated_at'=>BlueVPN_Utils::iso_from_mysql($x['updated_at']??null)]; }
    public static function subscription(WP_REST_Request $r): WP_REST_Response { global $wpdb;$t=BlueVPN_DB::table('customers');$token=(string)$r['token'];$c=$wpdb->get_row($wpdb->prepare("SELECT subscription_url FROM {$t} WHERE subscription_token=%s AND active=1 LIMIT 1",$token),ARRAY_A);if(!$c)return self::ok(['detail'=>['code'=>'SUBSCRIPTION_NOT_FOUND','message'=>'اشتراک پیدا نشد']],404);$res=self::ok(['success'=>true,'subscription_url'=>home_url('/sub/'.$token)],302);$res->header('Location',home_url('/sub/'.$token));return $res; }
}
