<?php
if (!defined('ABSPATH')) exit;

final class BlueVPN_API {
    public static function init(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_filter('rest_post_dispatch', [self::class, 'headers'], 10, 3);
    }
    public static function register_routes(): void {
        register_rest_route('bluevpn-system/v1','/health',['methods'=>'GET','callback'=>[self::class,'health'],'permission_callback'=>'__return_true']);
        register_rest_route('bluevpn-system/v1','/health/details',['methods'=>'GET','callback'=>[self::class,'health_details'],'permission_callback'=>[self::class,'admin_permission']]);
        register_rest_route('bluevpn-system/v1','/app-connection',['methods'=>'GET','callback'=>[self::class,'app_connection'],'permission_callback'=>'__return_true']);
        $routes = [
            ['/mobile/config','GET','mobile_config'],
            ['/windows/update','GET','windows_update'],
            ['/windows/release-sync','POST','windows_release_sync'],
            ['/ad-assets/(?P<asset_id>[A-Za-z0-9_-]{6,64})','GET','ad_asset'],
            ['/free/subscription','GET','free_subscription'],
            ['/free/subscriptions/(?P<item_id>[A-Za-z0-9_-]{1,64})','GET','free_subscription'],
            ['/free/curated','GET','free_curated'], ['/free/probes','POST','free_probe'],
            ['/free/reward/claim','POST','free_reward_claim'],
            ['/auth/register','POST','register'], ['/auth/login','POST','login'],
            ['/auth/otp/request','POST','otp_request'], ['/auth/otp/verify','POST','otp_verify'],
            ['/auth/refresh','POST','refresh'], ['/auth/logout','POST','logout'],
            ['/account/phone/otp/request','POST','bind_phone_otp_request'], ['/account/phone/otp/verify','POST','bind_phone_otp_verify'],
            ['/plans','GET','plans'], ['/account','GET','account'], ['/account/sync','POST','account_sync'],
            ['/server-locations/resolve','POST','resolve_locations'], ['/server-locations/verify','POST','verify_location'],
            ['/ai/events','POST','ai_event'], ['/ai/recommendations','GET','ai_recommendations'], ['/ai/dashboard','GET','ai_dashboard'], ['/feedback','POST','feedback'],
            ['/support/departments','GET','support_departments'],
            ['/support/conversations','GET','support_conversations'],
            ['/support/conversations','POST','support_create'],
            ['/support/conversations/(?P<id>\d+)/messages','GET','support_messages'],
            ['/support/conversations/(?P<id>\d+)/messages','POST','support_send'],
            ['/support/conversations/(?P<id>\d+)/attachments','POST','support_attachment'],
            ['/support/unread','GET','support_unread'],
            ['/support/conversations/(?P<id>\d+)/close','POST','support_close'],
            ['/orders','POST','create_order'],
            ['/orders/(?P<order_id>[A-Za-z0-9_-]{8,80})','GET','order_status'],
            ['/orders/(?P<order_id>[A-Za-z0-9_-]{8,80})/checkout/open','POST','checkout_open'],
            ['/orders/(?P<order_id>[A-Za-z0-9_-]{8,80})/checkout/heartbeat','POST','checkout_heartbeat'],
            ['/orders/(?P<order_id>[A-Za-z0-9_-]{8,80})/checkout/close','POST','checkout_close'],
            ['/orders/(?P<order_id>[A-Za-z0-9_-]{8,80})/check-after-success','GET','check_after_success'],
            ['/webhooks/blupal','POST','blupal_webhook'],
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
    private static function unexpected(Throwable $e,string $scope): WP_REST_Response {
        $trace=substr(hash('sha256',$scope.'|'.microtime(true).'|'.wp_rand()),0,12);
        BlueVPN_Error_Monitor::legacy_error_log('BlueVPN '.$scope.' ['.$trace.']: '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
        return self::ok(['detail'=>['code'=>'SERVER_INTERNAL_ERROR','message'=>'خطای داخلی سرور هنگام پردازش درخواست رخ داد.','trace_id'=>$trace]],500);
    }
    private static function body(WP_REST_Request $r): array {
        $b = $r->get_json_params();
        if (is_array($b)) return $b;

        // Compatibility fallback for hardened clients that may retry a request
        // through an intermediary without an application/json content type.
        // WordPress only performs strict JSON pre-parsing for JSON content types;
        // decoding the raw body here keeps the API deterministic on cPanel/Apache
        // rewrite stacks while still rejecting non-array payloads.
        $raw = trim((string)$r->get_body());
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && json_last_error() === JSON_ERROR_NONE) return $decoded;
        }

        $form = $r->get_body_params();
        return is_array($form) ? $form : [];
    }
    public static function admin_permission(): bool { return current_user_can('manage_options'); }
    public static function health(): WP_REST_Response {
        $db = BlueVPN_DB::status();
        $production = class_exists('BlueVPN_Production') ? BlueVPN_Production::health_summary() : [];
        return self::ok([
            'status' => ($db['ready'] && (!isset($production['score']) || $production['score'] >= 70)) ? 'ok' : 'degraded',
            'service' => 'bluevpn-wordpress-platform',
            'version' => BLUEVPN_MANAGER_VERSION,
            'server_time' => BlueVPN_Utils::iso_now(),
        ]);
    }
    public static function health_details(): WP_REST_Response {
        $db = BlueVPN_DB::status();
        return self::ok([
            'status' => $db['ready'] ? 'ok' : 'degraded',
            'service' => 'bluevpn-wordpress-platform',
            'version' => BLUEVPN_MANAGER_VERSION,
            'database' => $db,
            'counts' => $db['ready'] ? BlueVPN_DB::counts() : [],
            'production' => class_exists('BlueVPN_Production') ? BlueVPN_Production::health_summary() : [],
            'github_updater' => class_exists('BlueVPN_GitHub_Updater') ? BlueVPN_GitHub_Updater::diagnostics() : [],
            'migration' => [
                'phase' => 2,
                'state' => BlueVPN_Migration::state()['phase'],
                'cutover_ready' => get_option('bluevpn_manager_cutover_ready','0') === '1',
                'finalized' => get_option('bluevpn_manager_legacy_bridge_disabled','0') === '1',
            ],
        ]);
    }
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
                'advertising' => true, 'ad_assets' => true, 'tapsell' => true, 'free_story_ads' => true, 'free_access' => true,
                'blueai_events' => true, 'blueai_recommendations' => true, 'blueai_dashboard' => true, 'blueai_live_tier_monitoring' => true,
                'orders' => true, 'blupal_webhook' => true, 'bind_phone_otp' => true, 'provider_sync' => true, 'live_support' => true, 'support_attachments' => true, 'support_sla' => true, 'support_background_notifications' => true,
            ],
            'static_api_key_required' => false,
            'cutover_ready' => get_option('bluevpn_manager_cutover_ready','0') === '1',
            'app_cutover_enabled' => get_option('bluevpn_manager_app_cutover_enabled','0') === '1',
        ]);
    }
    public static function mobile_config(WP_REST_Request $r): WP_REST_Response {
        $forced = rest_sanitize_boolean($r->get_param('refresh'));
        // The mobile config endpoint must never depend on a live GitHub request.
        // A manual "check for update" therefore only queues a background refresh
        // and immediately serves the last verified release metadata from MySQL.
        // This keeps the Android update check available even when GitHub/cURL/DNS
        // is slow or temporarily unavailable on shared cPanel hosting.
        if (class_exists('BlueVPN_App_Release_Manager')) {
            try { BlueVPN_App_Release_Manager::maybe_kick($forced); }
            catch (Throwable $e) {
                BlueVPN_Error_Monitor::legacy_error_log('BlueVPN mobile_config release refresh queue: '.$e->getMessage());
            }
        }

        $customer = null;
        $authHeader = trim((string)$r->get_header('authorization'));
        $releaseAuthState = $authHeader === '' ? 'anonymous' : 'invalid';
        $releaseAuthError = '';
        if ($authHeader !== '') {
            try {
                $customer = BlueVPN_Auth::current_customer($r);
                $releaseAuthState = 'authenticated';
            } catch (BlueVPN_Auth_Exception $e) {
                $customer = null;
                $releaseAuthState = 'invalid';
                $releaseAuthError = sanitize_key($e->error_code);
            }
        }

        $s = BlueVPN_DB::settings();
        $selection = ['release'=>null,'channel'=>'stable','beta_tester'=>false];
        if (class_exists('BlueVPN_App_Release_Manager')) {
            try {
                $selection = BlueVPN_App_Release_Manager::release_for_customer($customer);
            } catch (Throwable $e) {
                // Release-channel state is an enhancement, not a single point of
                // failure for /mobile/config. Fall back to the last Stable values
                // already stored in app_settings and keep the client update path alive.
                BlueVPN_Error_Monitor::legacy_error_log('BlueVPN mobile_config release selection fallback: '.$e->getMessage());
            }
        }
        $release = is_array($selection['release'] ?? null) ? $selection['release'] : [];
        $published = (string)($release['release_published_at'] ?? ($s['release_published_at'] ?? ''));
        $publishedMysql = BlueVPN_Utils::mysql_from_iso($published);
        $channel = (string)($selection['channel'] ?? 'stable');
        $releaseForce = !empty($release['force_update']);
        // Legacy global force applies only to Stable. Beta force is controlled per release.
        $forceUpdate = $releaseForce || ($channel === 'stable' && !empty($s['force_update']));
        // Automatic delivery is channel-aware. Beta testers should receive the same
        // check/download/install pipeline as Stable users, while administrators can
        // pause automatic Beta delivery without affecting the public Stable channel.
        $stableAutoUpdate = array_key_exists('auto_update_stable', $s) ? !empty($s['auto_update_stable']) : !empty($s['auto_update']);
        $betaAutoUpdate = array_key_exists('auto_update_beta', $s) ? !empty($s['auto_update_beta']) : !empty($s['auto_update']);
        $autoUpdate = $channel === 'beta' ? $betaAutoUpdate : $stableAutoUpdate;

        // Build advertising payloads once and expose the canonical Android keys.
        // `advertising` is the contract consumed by BlueVpnAdsCarouselView.
        // `ads` remains as a compatibility alias for intermediate clients/control-plane code.
        $advertising = BlueVPN_Ads::advertising_payload($s, $r);
        $tapsell = BlueVPN_Ads::tapsell_payload($s);
        $freeStoryAds = BlueVPN_Ads::free_story_payload($s);

        return self::ok([
            'app_name'=>$s['app_name'],
            'maintenance'=>(bool)$s['maintenance'],
            'support_url'=>$s['support_url'],
            'minimum_version'=>$s['minimum_version'],
            'force_update'=>$forceUpdate,
            'auto_update'=>$autoUpdate,
            'auto_update_stable'=>$stableAutoUpdate,
            'auto_update_beta'=>$betaAutoUpdate,
            'account_required'=>true,
            'latest_version'=>(string)($release['version'] ?? $s['latest_version']),
            'latest_version_code'=>(int)($release['version_code'] ?? $s['latest_version_code']),
            'apk_url'=>(string)($release['apk_url'] ?? $s['apk_url']),
            'apk_assets'=>is_array($release['apk_assets']??null)?$release['apk_assets']:(is_array($s['apk_assets']??null)?$s['apk_assets']:[]),
            'apk_asset_meta'=>is_array($release['apk_asset_meta']??null)?$release['apk_asset_meta']:(is_array($s['apk_asset_meta']??null)?$s['apk_asset_meta']:[]),
            'update_title'=>(string)($release['title'] ?? $s['update_title']),
            'update_message'=>(string)($release['message'] ?? $s['update_message']),
            'release_url'=>(string)($release['release_url'] ?? ($s['release_url']??'')),
            'release_published_at'=>$published,
            'release_published_at_fa'=>$publishedMysql?BlueVPN_Utils::tehran_datetime_fa($publishedMysql):'',
            'release_build_number'=>(int)($release['build_number'] ?? ($s['release_build_number']??0)),
            'release_commit'=>(string)($release['commit_sha'] ?? ($s['release_commit']??'')),
            'update_source'=>(string)($release['source'] ?? ($s['update_source']??'wordpress_settings')),
            'release_channel'=>$channel,
            'beta_tester'=>(bool)($selection['beta_tester'] ?? false),
            'release_auth_state'=>$releaseAuthState,
            'release_auth_error'=>$releaseAuthError,
            'update_policy'=>[
                'channel'=>$channel,
                'automatic_download'=>$autoUpdate,
                'force_update'=>$forceUpdate,
                'beta_tester'=>(bool)($selection['beta_tester'] ?? false),
            ],
            'github_repository'=>(string)($s['github_repository']??''),
            'github_error'=>(string)($s['github_error']??''),
            'release_cache_seconds'=>(int)($s['release_cache_seconds']??15),
            'release_refresh_forced'=>$forced,
            'release_refresh_mode'=>'background_cache_first',
            'auth'=>array_merge(['mode'=>'phone_otp_or_email_password','password_login'=>true,'email_login'=>true,'email_registration'=>true],BlueVPN_SMS_OTP::public_config(),['request_url'=>untrailingslashit(home_url('/')).'/api/v1/auth/otp/request','verify_url'=>untrailingslashit(home_url('/')).'/api/v1/auth/otp/verify','login_url'=>untrailingslashit(home_url('/')).'/api/v1/auth/login','register_url'=>untrailingslashit(home_url('/')).'/api/v1/auth/register']),
            'blueai'=>['enabled'=>(bool)$s['blueai_enabled'],'free_enabled'=>!isset($s['blueai_free_enabled'])||!empty($s['blueai_free_enabled']),'premium_enabled'=>!isset($s['blueai_premium_enabled'])||!empty($s['blueai_premium_enabled']),'collective'=>(bool)$s['blueai_collective'],'auto_heal'=>(bool)$s['blueai_auto_heal'],'shadow_mode'=>!isset($s['blueai_shadow_mode'])||!empty($s['blueai_shadow_mode']),'predictive_failover'=>!isset($s['blueai_predictive_failover'])||!empty($s['blueai_predictive_failover']),'anomaly_detection'=>!isset($s['blueai_anomaly_detection'])||!empty($s['blueai_anomaly_detection']),'min_samples'=>(int)$s['blueai_min_samples'],'engine_version'=>BlueVPN_AI::ENGINE_VERSION,'schema_version'=>BlueVPN_AI::SCHEMA_VERSION,'capabilities'=>BlueVPN_AI::capabilities(),'privacy_message'=>$s['blueai_privacy_message']],
            'ircf_intelligence'=>[
                'enabled'=>!isset($s['blueai_ircf_enabled'])||!empty($s['blueai_ircf_enabled']),
                'subscription_refiner'=>!isset($s['blueai_ircf_refiner'])||!empty($s['blueai_ircf_refiner']),
                'adaptive_test_urls'=>!isset($s['blueai_ircf_test_urls'])||!empty($s['blueai_ircf_test_urls']),
                'cloudflare_intelligence'=>!isset($s['blueai_ircf_cloudflare'])||!empty($s['blueai_ircf_cloudflare']),
                'fragment_scoring'=>!isset($s['blueai_ircf_fragment'])||!empty($s['blueai_ircf_fragment']),
                'warp_endpoint_profiles'=>!isset($s['blueai_ircf_endpoints'])||!empty($s['blueai_ircf_endpoints']),
                'sources'=>[
                    'test_urls'=>'https://github.com/ircfspace/testUrl',
                    'cloudflare_ranges'=>'https://github.com/ircfspace/cf-ip-ranges',
                    'warp_endpoints'=>'https://github.com/ircfspace/endpoint',
                    'refiner_model'=>'https://github.com/ircfspace/XrayRefiner',
                    'fragment_model'=>'https://github.com/ircfspace/fragment',
                ],
            ],
            'announcement'=>['enabled'=>(bool)$s['announcement_enabled'],'id'=>$s['announcement_id'],'title'=>$s['announcement_title'],'message'=>$s['announcement_message']],
            'advertising'=>$advertising,
            'ads'=>$advertising,
            'tapsell'=>$tapsell,
            'free_story_ads'=>$freeStoryAds,
            'free_access'=>BlueVPN_Ads::free_access_payload($s),
            'free_engine'=>[
                'primary'=>'aether_warp',
                'premium_engine'=>'xray_v2rayng',
                'isolated'=>true,
                'guest_supported'=>true,
                'schema'=>1,
            ],
            'updated_at'=>$s['updated_at'],
            'updated_at_fa'=>BlueVPN_Utils::tehran_datetime_fa(),
        ]);
    }
    public static function windows_release_sync(WP_REST_Request $r): WP_REST_Response {
        try {
            if (!class_exists('BlueVPN_Windows_Release_Manager') || !class_exists('BlueVPN_Telegram_Bot') || !method_exists('BlueVPN_Telegram_Bot','release_sync_secret_for_internal_requests')) {
                return self::ok(['ok'=>false,'detail'=>['code'=>'WINDOWS_RELEASE_SYNC_UNAVAILABLE','message'=>'سرویس همگام‌سازی Windows آماده نیست.'],'manager_version'=>BLUEVPN_MANAGER_VERSION],503);
            }
            $secrets = method_exists('BlueVPN_Telegram_Bot','release_sync_secrets_for_internal_requests')
                ? BlueVPN_Telegram_Bot::release_sync_secrets_for_internal_requests()
                : [trim((string)BlueVPN_Telegram_Bot::release_sync_secret_for_internal_requests())];
            $secrets = array_values(array_filter(array_map(static fn($v)=>trim((string)$v), $secrets), static fn($v)=>$v!==''));
            if (!$secrets) return self::ok(['ok'=>false,'detail'=>['code'=>'WINDOWS_RELEASE_SYNC_SECRET_MISSING','message'=>'کلید امن همگام‌سازی Windows تنظیم نشده است.'],'manager_version'=>BLUEVPN_MANAGER_VERSION],503);
            $timestamp = trim((string)$r->get_header('x-bluevpn-release-timestamp'));
            $signature = strtolower(trim((string)$r->get_header('x-bluevpn-release-signature')));
            if (!preg_match('/^\d{10}$/',$timestamp) || abs(time()-(int)$timestamp)>300 || !preg_match('/^[a-f0-9]{64}$/',$signature)) {
                return self::ok(['ok'=>false,'detail'=>['code'=>'WINDOWS_RELEASE_SYNC_AUTH_INVALID','message'=>'امضای همگام‌سازی Windows معتبر نیست.'],'manager_version'=>BLUEVPN_MANAGER_VERSION,'server_time'=>time()],403);
            }
            $raw = (string)$r->get_body();
            $signatureOk = false;
            foreach ($secrets as $secret) {
                $expected = hash_hmac('sha256', $timestamp . "\n" . $raw, $secret);
                if (hash_equals($expected,$signature)) { $signatureOk = true; break; }
            }
            if (!$signatureOk) {
                return self::ok(['ok'=>false,'detail'=>['code'=>'WINDOWS_RELEASE_SYNC_AUTH_INVALID','message'=>'امضای همگام‌سازی Windows معتبر نیست.'],'manager_version'=>BLUEVPN_MANAGER_VERSION,'server_time'=>time()],403);
            }
            $payload = json_decode($raw,true);
            if (!is_array($payload)) return self::ok(['ok'=>false,'detail'=>['code'=>'WINDOWS_RELEASE_SYNC_JSON_INVALID','message'=>'Metadata نسخه Windows معتبر نیست.']],422);
            $result = BlueVPN_Windows_Release_Manager::ingest_direct_payload($payload,'github_signed_push');
            $stable = BlueVPN_Windows_Release_Manager::stable_release();
            $beta = BlueVPN_Windows_Release_Manager::beta_release();
            $ok = !empty($result['ok']);
            $message = (string)($result['message']??'');
            return self::ok([
                'ok'=>$ok,
                'message'=>$message,
                'detail'=>$ok ? ['code'=>'WINDOWS_RELEASE_SYNC_OK','message'=>'Windows release metadata synchronized.'] : ['code'=>'WINDOWS_RELEASE_SYNC_PAYLOAD_INVALID','message'=>$message !== '' ? $message : 'Metadata نسخه Windows ناقص یا نامعتبر است.'],
                'stable_version'=>(string)($stable['version']??''),
                'beta_version'=>(string)($beta['version']??''),
                'pending_stable_version'=>BlueVPN_Windows_Release_Manager::pending_stable_version(),
                'source'=>'github_signed_push',
                'manager_version'=>BLUEVPN_MANAGER_VERSION,
            ], $ok?200:422);
        } catch (Throwable $e) {
            return self::unexpected($e,'windows_release_sync');
        }
    }

    public static function windows_update(WP_REST_Request $r): WP_REST_Response {
        $forced = rest_sanitize_boolean($r->get_param('refresh'));
        $refreshResult = null;
        if (class_exists('BlueVPN_Windows_Release_Manager')) {
            try {
                if ($forced) $refreshResult = BlueVPN_Windows_Release_Manager::sync_now(true, 'rest_force_refresh');
                else BlueVPN_Windows_Release_Manager::maybe_kick(false);
            } catch (Throwable $e) {
                BlueVPN_Error_Monitor::legacy_error_log('BlueVPN windows release refresh: '.$e->getMessage());
                $refreshResult = ['ok'=>false,'message'=>$e->getMessage()];
            }
        }
        $customer=null;$authState='anonymous';$authError='';$authHeader=trim((string)$r->get_header('authorization'));
        if($authHeader!==''){
            try{$customer=BlueVPN_Auth::current_customer($r);$authState='authenticated';}
            catch(BlueVPN_Auth_Exception $e){$customer=null;$authState='invalid';$authError=sanitize_key($e->error_code);}
        }
        $selection=['release'=>null,'channel'=>'stable','beta_tester'=>false];
        if(class_exists('BlueVPN_Windows_Release_Manager')){
            try{$selection=BlueVPN_Windows_Release_Manager::release_for_customer($customer);}
            catch(Throwable $e){BlueVPN_Error_Monitor::legacy_error_log('BlueVPN windows release selection: '.$e->getMessage());}
        }
        $release=is_array($selection['release']??null)?$selection['release']:[];
        $channel=(string)($selection['channel']??'stable');
        $cfg=class_exists('BlueVPN_Windows_Release_Manager')?BlueVPN_Windows_Release_Manager::settings():[];
        $arch=sanitize_key((string)($r->get_param('arch')?:'win-x64'));
        $asset=$release&&class_exists('BlueVPN_Windows_Release_Manager')?BlueVPN_Windows_Release_Manager::installer_for_arch($release,$arch):['architecture'=>$arch,'url'=>'','filename'=>'','sha256'=>'','size'=>0];
        $current=trim((string)($r->get_param('current_version')?:'0.0.0'));if(!preg_match('/^\d+\.\d+\.\d+$/',$current))$current='0.0.0';
        $latest=(string)($release['version']??'');
        $minimum=(string)($cfg[$channel==='beta'?'minimum_version_beta':'minimum_version_stable']??'0.0.0');
        $updateAvailable=$latest!==''&&version_compare($latest,$current,'>');
        $belowMinimum=$minimum!=='0.0.0'&&version_compare($current,$minimum,'<');
        $force=!empty($release['force_update'])||$belowMinimum;
        $auto=$channel==='beta'?!empty($cfg['auto_update_beta']):!empty($cfg['auto_update_stable']);
        $published=(string)($release['release_published_at']??'');$publishedMysql=BlueVPN_Utils::mysql_from_iso($published);
        return self::ok([
            'platform'=>'windows','available'=>(bool)$release,'current_version'=>$current,
            'latest_version'=>$latest,'latest_version_code'=>(int)($release['version_code']??0),'update_available'=>$updateAvailable,
            'minimum_version'=>$minimum,'below_minimum'=>$belowMinimum,'force_update'=>$force,'auto_update'=>$auto,
            'release_channel'=>$channel,'beta_tester'=>(bool)($selection['beta_tester']??false),
            'title'=>(string)($release['title']??''),'message'=>(string)($release['message']??''),
            'release_url'=>(string)($release['release_url']??''),'release_published_at'=>$published,
            'release_published_at_fa'=>$publishedMysql?BlueVPN_Utils::tehran_datetime_fa($publishedMysql):'',
            'architecture'=>(string)($asset['architecture']??$arch),'download_url'=>(string)($asset['url']??''),
            'filename'=>(string)($asset['filename']??''),'sha256'=>(string)($asset['sha256']??''),'size'=>(int)($asset['size']??0),
            'auth_state'=>$authState,'auth_error'=>$authError,'source'=>'wordpress_windows_release_channels',
            'release_refresh_mode'=>$forced?'synchronous_force_refresh':'cache_first_background',
            'release_refresh_ok'=>$refreshResult===null?null:!empty($refreshResult['ok']),
            'release_refresh_message'=>$refreshResult===null?'':(string)($refreshResult['message']??''),
            'pending_stable_version'=>class_exists('BlueVPN_Windows_Release_Manager')?BlueVPN_Windows_Release_Manager::pending_stable_version():'',
            'release_last_sync'=>class_exists('BlueVPN_Windows_Release_Manager')?BlueVPN_Windows_Release_Manager::last_sync():0,
        ]);
    }

    public static function ad_asset(WP_REST_Request $r): WP_REST_Response { return BlueVPN_Ads::asset_response($r); }
    public static function free_subscription(WP_REST_Request $r): WP_REST_Response { return BlueVPN_Ads::free_subscription($r); }
    public static function free_curated(WP_REST_Request $r): WP_REST_Response { $limit=max(10,min(300,(int)($r->get_param('limit')?:80)));$text=BlueVPN_Free_Sources::subscription_text($limit);$res=new WP_REST_Response($text,200);$res->header('Content-Type','text/plain; charset=utf-8');$res->header('X-BlueVPN-Raw','1');$res->header('Cache-Control','public, max-age=60');return $res; }
    public static function free_probe(WP_REST_Request $r): WP_REST_Response { $device=(string)$r->get_header('x-device-id');$app=(string)$r->get_header('user-agent');return self::ok(['success'=>true,'result'=>BlueVPN_Free_Sources::report(self::body($r),$device,$app)]); }
    public static function free_reward_claim(WP_REST_Request $r): WP_REST_Response {
        try {
            global $wpdb;
            $body = self::body($r);
            $eventId = strtolower(trim((string)($body['event_id'] ?? '')));
            $deviceId = mb_substr(trim((string)($body['device_id'] ?? $r->get_header('x-device-id'))), 0, 180);
            $appVersion = mb_substr(trim((string)($body['app_version'] ?? '')), 0, 40);

            if (!preg_match('/^[a-z0-9][a-z0-9._:-]{15,63}$/', $eventId)) {
                throw new BlueVPN_Auth_Exception(422, 'REWARD_EVENT_INVALID', 'شناسه رویداد جایزه معتبر نیست');
            }
            if ($deviceId === '' || mb_strlen($deviceId) < 6) {
                throw new BlueVPN_Auth_Exception(422, 'REWARD_DEVICE_REQUIRED', 'شناسه دستگاه لازم است');
            }

            $customer = null;
            $bearer = BlueVPN_Auth::bearer_token($r);
            if ($bearer !== '') {
                // An invalid bearer must never silently downgrade to guest.
                $customer = BlueVPN_Auth::current_customer($r);
                $account = BlueVPN_Auth::account_payload($customer);
                if (!empty($account['subscription']['active'])) {
                    throw new BlueVPN_Auth_Exception(409, 'REWARD_FREE_ONLY', 'جایزه تبلیغاتی فقط برای پلن رایگان است');
                }
            }

            $settings = BlueVPN_DB::settings();
            $tapsell = BlueVPN_Ads::tapsell_payload($settings);
            $policy = is_array($tapsell['placements']['rewarded_video'] ?? null)
                ? $tapsell['placements']['rewarded_video']
                : [];

            if (empty($tapsell['enabled']) || empty($policy['enabled'])) {
                throw new BlueVPN_Auth_Exception(409, 'REWARD_DISABLED', 'ویدئوی جایزه‌ای غیرفعال است');
            }

            $minutes = max(1, min(180, (int)($tapsell['rewarded_bonus_minutes'] ?? 15)));
            $interval = max(0, min(86400, (int)($policy['min_interval_seconds'] ?? 300)));
            $dailyCap = max(0, min(1000, (int)($policy['daily_cap'] ?? 5)));
            $table = BlueVPN_DB::table('free_reward_claims');

            $existing = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT event_id,granted_minutes,created_at FROM {$table} WHERE event_id=%s LIMIT 1",
                    $eventId
                ),
                ARRAY_A
            );
            if (is_array($existing)) {
                return self::ok([
                    'success' => true,
                    'applied' => true,
                    'duplicate' => true,
                    'event_id' => $eventId,
                    'granted_minutes' => (int)$existing['granted_minutes'],
                    'server_time' => BlueVPN_Utils::iso_now(),
                ]);
            }

            $customerId = $customer ? (int)$customer['id'] : 0;
            $identitySql = $customerId > 0
                ? $wpdb->prepare("customer_id=%d", $customerId)
                : $wpdb->prepare("customer_id=0 AND device_id=%s", $deviceId);

            if ($interval > 0) {
                $lastAt = (string)$wpdb->get_var(
                    "SELECT created_at FROM {$table} WHERE placement='rewarded_video' AND {$identitySql} ORDER BY created_at DESC LIMIT 1"
                );
                if ($lastAt !== '') {
                    $lastTs = strtotime($lastAt . ' UTC') ?: 0;
                    $retryAfter = max(0, $interval - (time() - $lastTs));
                    if ($retryAfter > 0) {
                        throw new BlueVPN_Auth_Exception(
                            429,
                            'REWARD_COOLDOWN',
                            'برای دریافت جایزه بعدی کمی صبر کنید',
                            ['retry_after_seconds' => $retryAfter]
                        );
                    }
                }
            }

            if ($dailyCap > 0) {
                $dayStart = gmdate('Y-m-d 00:00:00');
                $todayCount = (int)$wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$table} WHERE placement='rewarded_video' AND created_at>=%s AND {$identitySql}",
                        $dayStart
                    )
                );
                if ($todayCount >= $dailyCap) {
                    throw new BlueVPN_Auth_Exception(429, 'REWARD_DAILY_CAP', 'سقف دریافت جایزه امروز تکمیل شده است');
                }
            }

            $inserted = $wpdb->insert(
                $table,
                [
                    'event_id' => $eventId,
                    'customer_id' => $customerId > 0 ? $customerId : 0,
                    'device_id' => $deviceId,
                    'placement' => 'rewarded_video',
                    'granted_minutes' => $minutes,
                    'app_version' => $appVersion,
                    'created_at' => BlueVPN_Utils::now_mysql(),
                ],
                ['%s','%d','%s','%s','%d','%s','%s']
            );

            if ($inserted === false) {
                // Concurrent duplicate event: return the original grant instead
                // of ever creating a second reward.
                $race = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT event_id,granted_minutes FROM {$table} WHERE event_id=%s LIMIT 1",
                        $eventId
                    ),
                    ARRAY_A
                );
                if (is_array($race)) {
                    return self::ok([
                        'success' => true,
                        'applied' => true,
                        'duplicate' => true,
                        'event_id' => $eventId,
                        'granted_minutes' => (int)$race['granted_minutes'],
                        'server_time' => BlueVPN_Utils::iso_now(),
                    ]);
                }
                throw new RuntimeException('REWARD_LEDGER_INSERT_FAILED');
            }

            return self::ok([
                'success' => true,
                'applied' => true,
                'duplicate' => false,
                'event_id' => $eventId,
                'granted_minutes' => $minutes,
                'server_time' => BlueVPN_Utils::iso_now(),
            ]);
        } catch (BlueVPN_Auth_Exception $e) {
            return self::fail($e);
        } catch (Throwable $e) {
            return self::unexpected($e, 'free_reward_claim');
        }
    }

    public static function bind_phone_otp_request(WP_REST_Request $r): WP_REST_Response {
        try { $c=BlueVPN_Auth::current_customer($r); $b=self::body($r); return self::ok(BlueVPN_SMS_OTP::request_bind($c,(string)($b['phone']??''),(string)($b['device_id']??''))); }
        catch(BlueVPN_Auth_Exception $e){ return self::fail($e); }
        catch(Throwable $e){ return self::unexpected($e,'bind_phone_otp_request'); }
    }
    public static function bind_phone_otp_verify(WP_REST_Request $r): WP_REST_Response {
        try { $c=BlueVPN_Auth::current_customer($r); $b=self::body($r); return self::ok(BlueVPN_SMS_OTP::verify_bind($c,(string)($b['phone']??''),(string)($b['challenge_id']??''),(string)($b['code']??''),(string)($b['device_id']??''))); }
        catch(BlueVPN_Auth_Exception $e){ return self::fail($e); }
        catch(Throwable $e){ return self::unexpected($e,'bind_phone_otp_verify'); }
    }

    public static function ai_event(WP_REST_Request $r): WP_REST_Response {
        try {
            $s=BlueVPN_DB::settings();
            if(empty($s['blueai_enabled'])) return self::ok(['success'=>true,'accepted'=>false,'reason'=>'disabled']);
            $b=self::body($r); if(($b['consent']??null)!==true) return self::ok(['success'=>true,'accepted'=>false,'reason'=>'consent_required']);
            $bearer=BlueVPN_Auth::bearer_token($r);
            if($bearer!==''){
                $c=BlueVPN_Auth::current_customer($r);
            }else{
                $device=substr(sanitize_text_field((string)($b['device_id']??$r->get_header('x-device-id'))),0,80);
                if($device==='')throw new BlueVPN_Auth_Exception(422,'AI_DEVICE_REQUIRED','شناسه دستگاه لازم است');
                $key='bluevpn_ai_guest_'.sha1($device.'|'.($_SERVER['REMOTE_ADDR']??''));$count=(int)get_transient($key);
                if($count>=120)return self::ok(['success'=>true,'accepted'=>false,'reason'=>'rate_limited','retry_after_seconds'=>max(1,(int)(HOUR_IN_SECONDS))]);
                set_transient($key,$count+1,HOUR_IN_SECONDS);$c=['id'=>0];$b['device_id']=$device;
            }
            try { $result=BlueVPN_AI::submit_event($c,$b); } catch(InvalidArgumentException $e) { throw new BlueVPN_Auth_Exception(422,'AI_EVENT_INVALID',$e->getMessage()); }
            return self::ok(array_merge(['success'=>true,'guest'=>(int)$c['id']===0],$result));
        } catch(BlueVPN_Auth_Exception $e){ return self::fail($e); }
    }
    public static function ai_recommendations(WP_REST_Request $r): WP_REST_Response {
        $s=BlueVPN_DB::settings();$enabled=!empty($s['blueai_enabled']);$customer=['id'=>0];
        if(BlueVPN_Auth::bearer_token($r)!==''){try{$customer=BlueVPN_Auth::current_customer($r);}catch(BlueVPN_Auth_Exception $e){$customer=['id'=>0];}}
        $tier=BlueVPN_AI::plan_tier_for_customer($customer,(string)($r->get_param('plan_tier')??'free'));
        $tierEnabled=$enabled&&BlueVPN_AI::tier_enabled($tier,$s);
        $rows=$tierEnabled?BlueVPN_AI::recommendations((string)($r->get_param('operator')??'unknown'),(string)($r->get_param('network_type')??'unknown'),(string)($r->get_param('mode')??'balanced'),$r->get_param('hour'),30,$tier):[];
        return self::ok(['success'=>true,'enabled'=>$enabled,'tier_enabled'=>$tierEnabled,'plan_tier'=>$tier,'engine_version'=>BlueVPN_AI::ENGINE_VERSION,'schema_version'=>BlueVPN_AI::SCHEMA_VERSION,'capabilities'=>BlueVPN_AI::capabilities(),'collective'=>!empty($s['blueai_collective']),'shadow_mode'=>!isset($s['blueai_shadow_mode'])||!empty($s['blueai_shadow_mode']),'predictive_failover'=>!isset($s['blueai_predictive_failover'])||!empty($s['blueai_predictive_failover']),'anomaly_detection'=>!isset($s['blueai_anomaly_detection'])||!empty($s['blueai_anomaly_detection']),'recommendations'=>$rows,'generated_at'=>BlueVPN_Utils::iso_now(),'generated_at_fa'=>BlueVPN_Utils::tehran_datetime_fa(),'calendar'=>'jalali','timezone'=>'Asia/Tehran']);
    }
    public static function ai_dashboard(WP_REST_Request $r): WP_REST_Response { try{$bearer=BlueVPN_Auth::bearer_token($r);if($bearer!==''){$c=BlueVPN_Auth::current_customer($r);$data=BlueVPN_AI::dashboard((int)$c['id']);$guest=false;}else{$device=substr(sanitize_text_field((string)$r->get_header('x-device-id')),0,80);if($device==='')throw new BlueVPN_Auth_Exception(422,'AI_DEVICE_REQUIRED','شناسه دستگاه لازم است');$data=BlueVPN_AI::dashboard_device($device);$guest=true;}return self::ok(['success'=>true,'guest'=>$guest,'engine_version'=>BlueVPN_AI::ENGINE_VERSION,'schema_version'=>BlueVPN_AI::SCHEMA_VERSION,'dashboard'=>$data]);}catch(BlueVPN_Auth_Exception $e){return self::fail($e);} }
    public static function feedback(WP_REST_Request $r): WP_REST_Response { try{$c=BlueVPN_Auth::current_customer($r);return self::ok(array_merge(['success'=>true],BlueVPN_AI::feedback((int)$c['id'],self::body($r))));}catch(BlueVPN_Auth_Exception $e){return self::fail($e);} }

    public static function create_order(WP_REST_Request $r): WP_REST_Response { try{$c=BlueVPN_Auth::current_customer($r);return self::ok(BlueVPN_Payments::create($c,self::body($r)));}catch(BlueVPN_Auth_Exception $e){return self::fail($e);} }
    public static function order_status(WP_REST_Request $r): WP_REST_Response { try{$c=BlueVPN_Auth::current_customer($r);return self::ok(BlueVPN_Payments::get($c,(string)$r['order_id'],true));}catch(BlueVPN_Auth_Exception $e){return self::fail($e);} }
    public static function checkout_open(WP_REST_Request $r): WP_REST_Response { try{$c=BlueVPN_Auth::current_customer($r);return self::ok(BlueVPN_Payments::checkout($c,(string)$r['order_id'],'open'));}catch(BlueVPN_Auth_Exception $e){return self::fail($e);} }
    public static function checkout_heartbeat(WP_REST_Request $r): WP_REST_Response { try{$c=BlueVPN_Auth::current_customer($r);return self::ok(BlueVPN_Payments::checkout($c,(string)$r['order_id'],'heartbeat'));}catch(BlueVPN_Auth_Exception $e){return self::fail($e);} }
    public static function checkout_close(WP_REST_Request $r): WP_REST_Response { try{$c=BlueVPN_Auth::current_customer($r);return self::ok(BlueVPN_Payments::checkout($c,(string)$r['order_id'],'close'));}catch(BlueVPN_Auth_Exception $e){return self::fail($e);} }
    public static function check_after_success(WP_REST_Request $r): WP_REST_Response {
        try{$c=BlueVPN_Auth::current_customer($r);$result=BlueVPN_Payments::get($c,(string)$r['order_id'],true);$status=(string)($result['order']['status']??'');$pending=in_array($status,['','pending','created','creating','creating_invoice','processing','waiting','unpaid','paid','paid_needs_sync','partial_needs_sync'],true);return self::ok(array_merge(['success'=>true,'confirmed'=>$status==='activated','pending'=>$pending,'attempts'=>1,'elapsed_seconds'=>0,'retry_after_seconds'=>$pending?5:0,'server_time'=>BlueVPN_Utils::iso_now(),'server_time_fa'=>BlueVPN_Utils::tehran_datetime_fa(),'calendar'=>'jalali','timezone'=>'Asia/Tehran'],$result));}catch(BlueVPN_Auth_Exception $e){return self::fail($e);}
    }
    public static function blupal_webhook(WP_REST_Request $r): WP_REST_Response { return BlueVPN_Payments::webhook($r); }

    public static function otp_request(WP_REST_Request $r): WP_REST_Response { try{$b=self::body($r);return self::ok(BlueVPN_SMS_OTP::request((string)($b['phone']??''),(string)($b['device_id']??'')));}catch(BlueVPN_Auth_Exception $e){return self::fail($e);}catch(Throwable $e){return self::unexpected($e,'otp_request');} }
    public static function otp_verify(WP_REST_Request $r): WP_REST_Response { try{$b=self::body($r);return self::ok(BlueVPN_SMS_OTP::verify((string)($b['phone']??''),(string)($b['challenge_id']??''),(string)($b['code']??''),(string)($b['device_id']??''),(string)($b['device_name']??'')));}catch(BlueVPN_Auth_Exception $e){return self::fail($e);}catch(Throwable $e){return self::unexpected($e,'otp_verify');} }
    public static function register(WP_REST_Request $r): WP_REST_Response {
        $rl='';
        try{
            $b=self::body($r);$email=BlueVPN_Auth::normalize_email((string)($b['email']??''));
            $deviceId=mb_substr(trim((string)($b['device_id']??'')),0,180);
            if ($deviceId==='') throw new BlueVPN_Auth_Exception(422,'DEVICE_ID_REQUIRED','شناسه دستگاه لازم است');
            $rl=BlueVPN_Auth::enforce_rate_limit('register',$email,5,15*MINUTE_IN_SECONDS);
            $pass=BlueVPN_Auth::validate_password((string)($b['password']??''));$c=BlueVPN_Auth::create_customer($email,$pass);$tok=BlueVPN_Auth::issue_session($c,$deviceId,(string)($b['device_name']??''));BlueVPN_Auth::clear_rate_limit($rl);
            return self::ok(['success'=>true,'is_new_account'=>true,'token'=>$tok['token'],'refresh_token'=>$tok['refresh_token'],'account'=>BlueVPN_Auth::account_payload($c)]);
        }catch(BlueVPN_Auth_Exception $e){return self::fail($e);}
    }
    public static function login(WP_REST_Request $r): WP_REST_Response {
        $rl='';
        try{
            $b=self::body($r);$email=BlueVPN_Auth::normalize_email((string)($b['email']??''));$rl=BlueVPN_Auth::enforce_rate_limit('login',$email,10,10*MINUTE_IN_SECONDS);
            $c=BlueVPN_Auth::customer_by_email($email);if(!$c||!BlueVPN_Auth::password_verify_compat((string)($b['password']??''),(string)$c['password_hash'])) throw new BlueVPN_Auth_Exception(401,'INVALID_CREDENTIALS','ایمیل یا رمز عبور نادرست است.');if(!(int)$c['active']) throw new BlueVPN_Auth_Exception(401,'ACCOUNT_DISABLED','این حساب غیرفعال شده است.');$tok=BlueVPN_Auth::issue_session($c,(string)($b['device_id']??''),(string)($b['device_name']??''));BlueVPN_Auth::clear_rate_limit($rl);
            return self::ok(['success'=>true,'is_new_account'=>false,'token'=>$tok['token'],'refresh_token'=>$tok['refresh_token'],'account'=>BlueVPN_Auth::account_payload($c)]);
        }catch(BlueVPN_Auth_Exception $e){return self::fail($e);}
    }
    public static function refresh(WP_REST_Request $r): WP_REST_Response { try{$b=self::body($r);$x=BlueVPN_Auth::refresh_session((string)($b['phone']??$b['identity']??$b['email']??''),(string)($b['device_id']??''),(string)($b['refresh_token']??''),(string)($b['device_name']??''));return self::ok(['success'=>true,'token'=>$x['tokens']['token'],'refresh_token'=>$x['tokens']['refresh_token'],'account'=>BlueVPN_Auth::account_payload($x['customer'])]);}catch(BlueVPN_Auth_Exception $e){return self::fail($e);} }
    public static function logout(WP_REST_Request $r): WP_REST_Response { BlueVPN_Auth::logout($r); return self::ok(['success'=>true]); }
    public static function plans(WP_REST_Request $r): WP_REST_Response { try{BlueVPN_Auth::current_customer($r);global $wpdb;$t=BlueVPN_DB::table('plans');$rows=$wpdb->get_results("SELECT id,title,description,price_toman,duration_days,data_limit_gb,device_limit FROM {$t} WHERE active=1 AND deleted=0 AND usd_managed=1 AND usd_price>0 ORDER BY sort_order,price_toman",ARRAY_A);foreach($rows as &$x){foreach(['id','price_toman','duration_days','data_limit_gb','device_limit'] as $k)$x[$k]=(int)$x[$k];}return self::ok(['success'=>true,'plans'=>$rows]);}catch(BlueVPN_Auth_Exception $e){return self::fail($e);} }
    public static function account(WP_REST_Request $r): WP_REST_Response { try{$c=BlueVPN_Auth::current_customer($r);$fresh=BlueVPN_Auth::get_customer((int)$c['id']);return self::ok(['success'=>true,'account'=>BlueVPN_Auth::account_payload($fresh),'source'=>'wordpress_snapshot']);}catch(BlueVPN_Auth_Exception $e){return self::fail($e);} }
    public static function account_sync(WP_REST_Request $r): WP_REST_Response { try{$c=BlueVPN_Auth::current_customer($r);$queued=BlueVPN_Providers::request_background_sync((int)$c['id']);$fresh=BlueVPN_Auth::get_customer((int)$c['id']);return self::ok(['success'=>true,'sync'=>['queued'=>$queued,'mode'=>'background','message'=>'Provider sync در پس‌زمینه انجام می‌شود.'],'account'=>BlueVPN_Auth::account_payload($fresh)]);}catch(BlueVPN_Auth_Exception $e){return self::fail($e);} }
    public static function resolve_locations(WP_REST_Request $r): WP_REST_Response { try{BlueVPN_Auth::current_customer($r);$b=self::body($r);$keys=array_values(array_unique(array_filter(array_map(fn($v)=>strtolower(trim((string)$v)),is_array($b['keys']??null)?array_slice($b['keys'],0,600):[]),fn($v)=>preg_match('/^[a-f0-9]{40}$/',$v))));if(!$keys)return self::ok(['success'=>true,'locations'=>[],'count'=>0]);global $wpdb;$t=BlueVPN_DB::table('server_locations');$ph=implode(',',array_fill(0,count($keys),'%s'));$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE config_key IN ($ph)",...$keys),ARRAY_A);$out=[];foreach($rows as $x)$out[]=self::location_payload($x);return self::ok(['success'=>true,'locations'=>$out,'count'=>count($out)]);}catch(BlueVPN_Auth_Exception $e){return self::fail($e);} }
    public static function verify_location(WP_REST_Request $r): WP_REST_Response {
        try {
            BlueVPN_Auth::current_customer($r);
            $b=self::body($r);
            $key=strtolower(trim((string)($b['config_key']??'')));
            $cc=strtolower(trim((string)($b['country_code']??'')));
            if(!preg_match('/^[a-f0-9]{40}$/',$key)) throw new BlueVPN_Auth_Exception(422,'SERVER_LOCATION_KEY_INVALID','شناسه سرور معتبر نیست');
            if(!preg_match('/^[a-z]{2}$/',$cc)) throw new BlueVPN_Auth_Exception(422,'SERVER_COUNTRY_INVALID','کد کشور معتبر نیست');
            global $wpdb;
            $t=BlueVPN_DB::table('server_locations');
            $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE config_key=%s LIMIT 1",$key),ARRAY_A);
            if(!$row){
                $now=BlueVPN_Utils::now_mysql();
                $inserted=$wpdb->insert($t,['config_key'=>$key,'country_code'=>$cc,'source'=>'client_trace','confidence'=>85,'verified_at'=>$now,'updated_at'=>$now]);
                if($inserted===false) throw new BlueVPN_Auth_Exception(500,'SERVER_LOCATION_SAVE_FAILED','ذخیره مکان تأییدشده سرور ناموفق بود.');
                $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE config_key=%s LIMIT 1",$key),ARRAY_A);
            }
            $stored=strtolower(trim((string)($row['country_code']??'')));
            if($stored==='' || $stored!==$cc) throw new BlueVPN_Auth_Exception(409,'SERVER_LOCATION_MISMATCH','کشور گزارش‌شده با مکان ثبت‌شده سرور مطابقت ندارد.');
            $now=BlueVPN_Utils::now_mysql();
            $confidence=max(0,min(100,(int)($row['confidence']??0)));
            $wpdb->update($t,['verified_at'=>$now,'updated_at'=>$now,'source'=>'client_confirmed','confidence'=>$confidence],['config_key'=>$key]);
            $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE config_key=%s LIMIT 1",$key),ARRAY_A);
            return self::ok(['success'=>true,'location'=>self::location_payload($row)]);
        } catch(BlueVPN_Auth_Exception $e) { return self::fail($e); }
    }
    private static function location_payload(array $x): array { return ['config_key'=>$x['config_key'],'country_code'=>$x['country_code'],'source'=>$x['source'],'confidence'=>(int)$x['confidence'],'verified_at'=>BlueVPN_Utils::iso_from_mysql($x['verified_at']??null),'updated_at'=>BlueVPN_Utils::iso_from_mysql($x['updated_at']??null)]; }
    public static function subscription(WP_REST_Request $r): WP_REST_Response { global $wpdb;$t=BlueVPN_DB::table('customers');$token=(string)$r['token'];$c=$wpdb->get_row($wpdb->prepare("SELECT subscription_url FROM {$t} WHERE subscription_token=%s AND active=1 LIMIT 1",$token),ARRAY_A);if(!$c)return self::ok(['detail'=>['code'=>'SUBSCRIPTION_NOT_FOUND','message'=>'اشتراک پیدا نشد']],404);$res=self::ok(['success'=>true,'subscription_url'=>home_url('/sub/'.$token)],302);$res->header('Location',home_url('/sub/'.$token));return $res; }

    public static function support_departments(WP_REST_Request $r): WP_REST_Response { return BlueVPN_Support::api_departments($r); }
    public static function support_conversations(WP_REST_Request $r): WP_REST_Response { return BlueVPN_Support::api_conversations($r); }
    public static function support_create(WP_REST_Request $r): WP_REST_Response { return BlueVPN_Support::api_create($r); }
    public static function support_messages(WP_REST_Request $r): WP_REST_Response { return BlueVPN_Support::api_messages($r); }
    public static function support_send(WP_REST_Request $r): WP_REST_Response { return BlueVPN_Support::api_send($r); }
    public static function support_attachment(WP_REST_Request $r): WP_REST_Response { return BlueVPN_Support::api_attachment($r); }
    public static function support_unread(WP_REST_Request $r): WP_REST_Response { return BlueVPN_Support::api_unread($r); }
    public static function support_close(WP_REST_Request $r): WP_REST_Response { return BlueVPN_Support::api_close($r); }
}
