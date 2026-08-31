<?php
if (!defined('ABSPATH')) exit;

/**
 * Shahrah VaaS reseller API client.
 *
 * API documentation supplied by the operator:
 *   Base: https://shahrah.top/api/vaas/reseller
 *   Auth: X-API-KEY
 *   GET  /me
 *   GET  /traffic
 *   GET  /plans
 *   GET  /services
 *   POST /services/create
 *   GET  /services/{slug}
 *   POST /services/{slug}/renew
 *   POST /services/{slug}/disable
 *   POST /services/{slug}/enable
 */
final class BlueVPN_Shahrah {
    public const BASE_URL = 'https://shahrah.top/api/vaas/reseller';

    public static function init(): void {
        add_action('admin_post_bluevpn_shahrah_save',[self::class,'admin_save']);
        add_action('admin_post_bluevpn_shahrah_sync',[self::class,'admin_sync']);
        add_action('admin_post_bluevpn_shahrah_toggle',[self::class,'admin_toggle']);
        add_action('admin_post_bluevpn_shahrah_delete',[self::class,'admin_delete']);
    }

    private static function guard(): void {
        if(!current_user_can('manage_options')) wp_die('دسترسی ندارید.');
    }

    private static function redirect(string $message,bool $error=false): void {
        wp_safe_redirect(add_query_arg([$error?'cc_error':'cc_msg'=>$message],admin_url('admin.php?page=bluevpn-shahrah')));
        exit;
    }

    public static function panel(int $id): ?array {
        if($id<=0)return null;
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".BlueVPN_DB::table('shahrah_panels')." WHERE id=%d",$id),ARRAY_A);
        return is_array($row)?$row:null;
    }

    public static function panel_api_key(array $panel): string {
        return BlueVPN_Utils::decrypt_secret((string)($panel['api_key_enc']??''));
    }

    private static function collect_plan_rows($node,array &$out): void {
        if(!is_array($node))return;
        $slug='';
        foreach(['slug','planSlug','plan_slug'] as $key){
            if(isset($node[$key])&&is_scalar($node[$key])&&trim((string)$node[$key])!==''){$slug=trim((string)$node[$key]);break;}
        }
        if($slug!==''){
            $name='';
            foreach(['name','title','label','remark'] as $key){
                if(isset($node[$key])&&is_scalar($node[$key])&&trim((string)$node[$key])!==''){$name=trim((string)$node[$key]);break;}
            }
            $out[$slug]=[
                'slug'=>$slug,
                'name'=>$name!==''?$name:$slug,
                'raw'=>$node,
            ];
        }
        foreach($node as $value)if(is_array($value))self::collect_plan_rows($value,$out);
    }

    public static function normalize_plans(array $response): array {
        $out=[];
        self::collect_plan_rows($response,$out);
        return array_values($out);
    }

    public static function sync_panel(int $panelId): array {
        $panel=self::panel($panelId);
        if(!$panel)return ['ok'=>false,'message'=>'اتصال شاهراه پیدا نشد.'];
        $apiKey=self::panel_api_key($panel);
        if($apiKey==='')return ['ok'=>false,'message'=>'API KEY شاهراه تنظیم نشده است.'];

        try{
            $me=self::me($apiKey);
            $traffic=self::traffic($apiKey);
            $plans=self::plans($apiKey);
            $services=self::services($apiKey,['limit'=>100,'page'=>1]);
            $normalized=self::normalize_plans((array)$plans['json']);
            global $wpdb;
            $wpdb->update(BlueVPN_DB::table('shahrah_panels'),[
                'me_json'=>BlueVPN_Utils::json_encode((array)$me['json']),
                'traffic_json'=>BlueVPN_Utils::json_encode((array)$traffic['json']),
                'plans_json'=>BlueVPN_Utils::json_encode($normalized),
                'services_json'=>BlueVPN_Utils::json_encode((array)$services['json']),
                'last_test_ok'=>1,
                'last_test_message'=>'اتصال موفق؛ '.count($normalized).' پلن از شاهراه همگام شد.',
                'last_test_at'=>BlueVPN_Utils::now_mysql(),
                'last_sync_at'=>BlueVPN_Utils::now_mysql(),
                'updated_at'=>BlueVPN_Utils::now_mysql(),
            ],['id'=>$panelId]);
            return ['ok'=>true,'message'=>'شاهراه همگام شد؛ '.count($normalized).' پلن دریافت شد.','plans'=>$normalized];
        }catch(Throwable $e){
            global $wpdb;
            $wpdb->update(BlueVPN_DB::table('shahrah_panels'),[
                'last_test_ok'=>0,
                'last_test_message'=>mb_substr($e->getMessage(),0,1200),
                'last_test_at'=>BlueVPN_Utils::now_mysql(),
                'updated_at'=>BlueVPN_Utils::now_mysql(),
            ],['id'=>$panelId]);
            return ['ok'=>false,'message'=>$e->getMessage()];
        }
    }

    public static function plan_catalog(bool $activeOnly=true): array {
        global $wpdb;
        $where=$activeOnly?' WHERE active=1':'';
        $rows=$wpdb->get_results("SELECT id,name,active,plans_json,last_sync_at FROM ".BlueVPN_DB::table('shahrah_panels').$where." ORDER BY name,id",ARRAY_A)?:[];
        $out=[];
        foreach($rows as $row){
            $plans=BlueVPN_Utils::json_decode_array((string)($row['plans_json']??''),[]);
            foreach($plans as $plan){
                $slug=trim((string)($plan['slug']??''));
                if($slug==='')continue;
                $out[]=[
                    'panel_id'=>(int)$row['id'],
                    'panel_name'=>(string)$row['name'],
                    'slug'=>$slug,
                    'name'=>(string)($plan['name']??$slug),
                    'raw'=>(array)($plan['raw']??[]),
                ];
            }
        }
        return $out;
    }

    public static function plan_exists(int $panelId,string $slug,bool $activeOnly=true): bool {
        $slug=trim($slug);
        if($panelId<=0||$slug==='')return false;
        foreach(self::plan_catalog($activeOnly) as $plan){
            if((int)$plan['panel_id']===$panelId&&hash_equals((string)$plan['slug'],$slug))return true;
        }
        return false;
    }

    public static function inspect_panel_customer(int $panelId,int $customerId): array {
        $panel=self::panel($panelId);
        if(!$panel||!(int)($panel['active']??0))return ['ok'=>false,'active'=>false,'message'=>'اتصال شاهراه غیرفعال یا حذف شده است.'];
        $apiKey=self::panel_api_key($panel);
        if($apiKey==='')return ['ok'=>false,'active'=>false,'message'=>'API KEY شاهراه تنظیم نشده است.'];
        $mapping=self::panel_mapping($panelId,$customerId);
        $slug=trim((string)($mapping['service_slug']??''));
        if($slug==='')return ['ok'=>false,'active'=>false,'message'=>'سرویس شاهراه برای این کاربر هنوز ساخته نشده است.'];
        try{
            $response=self::service($apiKey,$slug);
            $json=(array)$response['json'];
            $status=strtolower(self::find_first_key($json,['status','state']));
            $inactive=in_array($status,['disabled','inactive','expired','suspended','blocked'],true);
            $configs=self::extract_configs($json);
            return [
                'ok'=>true,
                'active'=>!$inactive,
                'status'=>$status!==''?$status:'unknown',
                'service_slug'=>$slug,
                'config_count'=>count($configs),
                'json'=>$json,
            ];
        }catch(Throwable $e){
            return ['ok'=>false,'active'=>false,'service_slug'=>$slug,'message'=>$e->getMessage()];
        }
    }

    public static function admin_save(): void {
        self::guard();check_admin_referer('bluevpn_shahrah_save');
        global $wpdb;$t=BlueVPN_DB::table('shahrah_panels');
        $id=max(0,(int)($_POST['id']??0));
        $old=$id>0?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d",$id),ARRAY_A):[];
        $name=sanitize_text_field(wp_unslash((string)($_POST['name']??'Shahrah')));
        $api=trim((string)wp_unslash($_POST['api_key']??''));
        $enc=$api!==''?BlueVPN_Utils::encrypt_secret($api):(string)($old['api_key_enc']??'');
        if($enc==='')self::redirect('API KEY شاهراه را وارد کن.',true);
        $data=[
            'name'=>$name!==''?$name:'Shahrah',
            'base_url'=>self::BASE_URL,
            'api_key_enc'=>$enc,
            'active'=>isset($_POST['active'])?1:0,
            'updated_at'=>BlueVPN_Utils::now_mysql(),
        ];
        if($id>0)$ok=$wpdb->update($t,$data,['id'=>$id]);
        else{$data['created_at']=BlueVPN_Utils::now_mysql();$ok=$wpdb->insert($t,$data);$id=(int)$wpdb->insert_id;}
        if($ok===false)self::redirect('ذخیره اتصال شاهراه ناموفق بود.',true);
        $sync=self::sync_panel($id);
        self::redirect($sync['ok']?'اتصال شاهراه ذخیره و پلن‌ها خودکار Sync شدند.':'اتصال ذخیره شد ولی Sync ناموفق بود: '.$sync['message'],!$sync['ok']);
    }

    public static function admin_sync(): void {
        self::guard();$id=max(0,(int)($_GET['id']??0));check_admin_referer('bluevpn_shahrah_sync_'.$id);
        $r=self::sync_panel($id);self::redirect($r['message'],empty($r['ok']));
    }

    public static function admin_toggle(): void {
        self::guard();$id=max(0,(int)($_GET['id']??0));check_admin_referer('bluevpn_shahrah_toggle_'.$id);
        global $wpdb;$t=BlueVPN_DB::table('shahrah_panels');$v=(int)$wpdb->get_var($wpdb->prepare("SELECT active FROM {$t} WHERE id=%d",$id));
        $wpdb->update($t,['active'=>$v?0:1,'updated_at'=>BlueVPN_Utils::now_mysql()],['id'=>$id]);
        self::redirect($v?'اتصال شاهراه غیرفعال شد.':'اتصال شاهراه فعال شد.');
    }

    public static function admin_delete(): void {
        self::guard();$id=max(0,(int)($_GET['id']??0));check_admin_referer('bluevpn_shahrah_delete_'.$id);
        global $wpdb;$pt=BlueVPN_DB::table('plans');$wpdb->update($pt,['shahrah_panel_id'=>null,'shahrah_plan_slug'=>''],['shahrah_panel_id'=>$id]);
        $wpdb->delete(BlueVPN_DB::table('shahrah_panels'),['id'=>$id]);
        self::redirect('اتصال شاهراه حذف شد؛ مسیر پلن‌های وابسته نیز پاک شد.');
    }

    public static function render_admin_tab(): void {
        global $wpdb;$t=BlueVPN_DB::table('shahrah_panels');
        $rows=$wpdb->get_results("SELECT * FROM {$t} ORDER BY id",ARRAY_A)?:[];
        $edit=max(0,(int)($_GET['edit']??0));$current=$edit?self::panel($edit):null;
        echo '<div class="bvc-card"><h2>اتصال اختصاصی Shahrah</h2><p>API KEY را یک‌بار ثبت کن؛ BlueVPN پلن‌ها، ترافیک و سرویس‌ها را مستقیماً از API شاهراه همگام می‌کند. planSlug دستی لازم نیست.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_shahrah_save');
        echo '<input type="hidden" name="action" value="bluevpn_shahrah_save"><input type="hidden" name="id" value="'.(int)($current['id']??0).'">';
        echo '<div class="bvc-form-grid"><label>نام اتصال<input name="name" value="'.esc_attr((string)($current['name']??'Shahrah')).'" required></label>';
        echo '<label>API KEY<input type="password" name="api_key" autocomplete="new-password" placeholder="'.($current?'خالی = کلید فعلی حفظ شود':'X-API-KEY').'" '.($current?'':'required').'></label>';
        echo '<label>Base URL<input value="'.esc_attr(self::BASE_URL).'" readonly></label>';
        echo '<label><input type="checkbox" name="active" value="1" '.checked(!$current||(int)($current['active']??1)===1,true,false).'> فعال</label></div>';
        submit_button($current?'ذخیره و Sync':'افزودن و Sync','primary','submit',false);echo '</form></div>';

        echo '<div class="bvc-card"><h2>اتصال‌ها</h2><table class="widefat striped bvc-table"><tr><th>ID</th><th>نام</th><th>پلن‌های Sync شده</th><th>وضعیت</th><th>آخرین Sync</th><th>عملیات</th></tr>';
        foreach($rows as $row){
            $plans=BlueVPN_Utils::json_decode_array((string)($row['plans_json']??''),[]);
            $sync=wp_nonce_url(admin_url('admin-post.php?action=bluevpn_shahrah_sync&id='.(int)$row['id']),'bluevpn_shahrah_sync_'.$row['id']);
            $toggle=wp_nonce_url(admin_url('admin-post.php?action=bluevpn_shahrah_toggle&id='.(int)$row['id']),'bluevpn_shahrah_toggle_'.$row['id']);
            $delete=wp_nonce_url(admin_url('admin-post.php?action=bluevpn_shahrah_delete&id='.(int)$row['id']),'bluevpn_shahrah_delete_'.$row['id']);
            echo '<tr><td>'.(int)$row['id'].'</td><td>'.esc_html((string)$row['name']).'</td><td>'.count($plans).'</td><td>'.((int)$row['active']?'<span class="bvc-ok">فعال</span>':'<span class="bvc-bad">غیرفعال</span>').'<br><small>'.esc_html((string)($row['last_test_message']??'')).'</small></td><td>'.esc_html((string)($row['last_sync_at']??'—')).'</td><td><div class="bvc-actions"><a class="button" href="'.esc_url(admin_url('admin.php?page=bluevpn-shahrah&edit='.(int)$row['id'])).'">ویرایش</a><a class="button button-primary" href="'.esc_url($sync).'">Sync API</a><a class="button" href="'.esc_url($toggle).'">'.((int)$row['active']?'غیرفعال':'فعال').'‌کردن</a><a class="button button-link-delete" href="'.esc_url($delete).'" onclick="return confirm(&quot;اتصال شاهراه حذف شود؟&quot;)">حذف</a></div></td></tr>';
        }
        echo '</table></div>';

        $catalog=self::plan_catalog(false);
        echo '<div class="bvc-card"><h2>پلن‌های دریافت‌شده از Shahrah</h2>';
        if(!$catalog)echo '<p class="bvc-note">هنوز پلنی Sync نشده. بعد از ثبت API KEY روی «Sync API» بزن.</p>';
        else{echo '<table class="widefat striped bvc-table"><tr><th>اتصال</th><th>نام پلن</th><th>slug</th></tr>';foreach($catalog as $plan)echo '<tr><td>'.esc_html($plan['panel_name']).'</td><td>'.esc_html($plan['name']).'</td><td><code>'.esc_html($plan['slug']).'</code></td></tr>';echo '</table>';}
        echo '</div>';
    }

    private static function clean_key(string $apiKey): string {
        $apiKey = trim($apiKey);
        if ($apiKey === '' || strlen($apiKey) > 512) {
            throw new RuntimeException('API KEY شاهراه وارد نشده یا معتبر نیست.');
        }
        return $apiKey;
    }

    private static function clean_slug(string $value, string $label): string {
        $value = trim($value);
        if ($value === '' || strlen($value) > 180 || !preg_match('/^[A-Za-z0-9._-]+$/', $value)) {
            throw new RuntimeException($label . ' شاهراه معتبر نیست.');
        }
        return $value;
    }

    private static function error_message(int $code, array $json): string {
        $remote = trim((string)($json['message'] ?? ''));
        if ($code === 400) return 'شاهراه: داده ارسالی ناقص یا نامعتبر است.' . ($remote !== '' ? ' (' . mb_substr($remote, 0, 300) . ')' : '');
        if ($code === 401) return 'شاهراه: API KEY ارسال نشده یا معتبر نیست.';
        if ($code === 404) return 'شاهراه: برند، بسته یا سرویس پیدا نشد.';
        if ($code >= 500) return 'شاهراه: خطای داخلی هنگام پردازش درخواست.' . ($remote !== '' ? ' (' . mb_substr($remote, 0, 300) . ')' : '');
        if ($remote !== '') return 'شاهراه: ' . mb_substr($remote, 0, 500);
        return 'شاهراه HTTP ' . $code;
    }

    public static function request(string $apiKey, string $method, string $path, ?array $body = null, array $query = []): array {
        $apiKey = self::clean_key($apiKey);
        $path = '/' . ltrim($path, '/');
        $url = self::BASE_URL . $path;
        if ($query) $url = add_query_arg($query, $url);

        $args = [
            'method' => strtoupper($method),
            'timeout' => 15,
            'redirection' => 0,
            'sslverify' => true,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-API-KEY' => $apiKey,
                'User-Agent' => 'BlueVPN-Shahrah/' . BLUEVPN_MANAGER_VERSION,
                'X-BlueVPN-Sentinel-Ignore' => '1',
            ],
        ];
        if ($body !== null) {
            $args['body'] = wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $safeRetry=strtoupper($method)==='GET';$res=null;
        for($attempt=1;$attempt<=($safeRetry?3:1);$attempt++){
            $res=wp_remote_request($url,$args);
            if(is_wp_error($res)){
                if($safeRetry&&$attempt<3){usleep(200000*$attempt);continue;}
                throw new RuntimeException('ارتباط با وب‌سرویس شاهراه برقرار نشد: '.$res->get_error_message());
            }
            $retryCode=(int)wp_remote_retrieve_response_code($res);
            if($safeRetry&&$retryCode>=500&&$attempt<3){usleep(200000*$attempt);continue;}
            break;
        }

        $code = (int)wp_remote_retrieve_response_code($res);
        $raw = (string)wp_remote_retrieve_body($res);
        $json = json_decode($raw, true);
        if (!is_array($json)) $json = ['ok' => false, 'status' => 'Error', 'message' => 'INVALID_JSON'];

        $ok = $code >= 200 && $code < 300 && (($json['ok'] ?? true) !== false);
        if (!$ok) throw new RuntimeException(self::error_message($code, $json));

        return [
            'ok' => true,
            'code' => $code,
            'status' => (string)($json['status'] ?? 'Success'),
            'items' => $json['items'] ?? [],
            'json' => $json,
        ];
    }

    public static function me(string $apiKey): array {
        return self::request($apiKey, 'GET', '/me');
    }

    public static function traffic(string $apiKey): array {
        return self::request($apiKey, 'GET', '/traffic');
    }

    public static function plans(string $apiKey): array {
        return self::request($apiKey, 'GET', '/plans');
    }

    public static function services(string $apiKey, array $query = []): array {
        $allowed = [];
        foreach (['limit','page','status'] as $key) {
            if (isset($query[$key]) && $query[$key] !== '') $allowed[$key] = $query[$key];
        }
        return self::request($apiKey, 'GET', '/services', null, $allowed);
    }

    public static function create_service(string $apiKey, string $planSlug, string $username): array {
        $planSlug = self::clean_slug($planSlug, 'planSlug');
        $username = self::clean_slug($username, 'username');
        return self::request($apiKey, 'POST', '/services/create', [
            'planSlug' => $planSlug,
            'username' => $username,
        ]);
    }

    public static function service(string $apiKey, string $serviceSlug): array {
        $serviceSlug = self::clean_slug($serviceSlug, 'شناسه سرویس');
        return self::request($apiKey, 'GET', '/services/' . rawurlencode($serviceSlug));
    }

    public static function renew_service(string $apiKey, string $serviceSlug, string $planSlug): array {
        $serviceSlug = self::clean_slug($serviceSlug, 'شناسه سرویس');
        $planSlug = self::clean_slug($planSlug, 'planSlug');
        return self::request($apiKey, 'POST', '/services/' . rawurlencode($serviceSlug) . '/renew', [
            'planSlug' => $planSlug,
        ]);
    }

    public static function disable_service(string $apiKey, string $serviceSlug): array {
        $serviceSlug = self::clean_slug($serviceSlug, 'شناسه سرویس');
        return self::request($apiKey, 'POST', '/services/' . rawurlencode($serviceSlug) . '/disable');
    }

    public static function enable_service(string $apiKey, string $serviceSlug): array {
        $serviceSlug = self::clean_slug($serviceSlug, 'شناسه سرویس');
        return self::request($apiKey, 'POST', '/services/' . rawurlencode($serviceSlug) . '/enable');
    }

    private static function find_first_key($node, array $keys): string {
        if (!is_array($node)) return '';
        foreach ($keys as $key) {
            if (isset($node[$key]) && is_scalar($node[$key])) {
                $value = trim((string)$node[$key]);
                if ($value !== '') return $value;
            }
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                $found = self::find_first_key($value, $keys);
                if ($found !== '') return $found;
            }
        }
        return '';
    }

    private static function find_service_for_username($node, string $username): array {
        if (!is_array($node)) return [];
        $candidateUser = '';
        foreach (['username','user','name'] as $key) {
            if (isset($node[$key]) && is_scalar($node[$key])) {
                $candidateUser = trim((string)$node[$key]);
                if ($candidateUser === $username) return $node;
            }
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                $found = self::find_service_for_username($value, $username);
                if ($found) return $found;
            }
        }
        return [];
    }

    private static function service_username_state($node,string $username): string {
        if(!is_array($node))return 'unknown';
        $sawIdentity=false;
        foreach(['username','user'] as $key){
            if(isset($node[$key])&&is_scalar($node[$key])){
                $candidate=trim((string)$node[$key]);
                if($candidate==='')continue;
                $sawIdentity=true;
                if($candidate===$username)return 'match';
            }
        }
        foreach($node as $value){
            if(!is_array($value))continue;
            $state=self::service_username_state($value,$username);
            if($state==='match')return 'match';
            if($state==='mismatch')$sawIdentity=true;
        }
        return $sawIdentity?'mismatch':'unknown';
    }

    public static function extract_service_slug(array $response, string $username = ''): string {
        $slug = self::find_first_key($response, ['slug','serviceSlug','service_slug']);
        if ($slug !== '') return $slug;
        if ($username !== '') {
            $candidate = self::find_service_for_username($response, $username);
            return self::find_first_key($candidate, ['slug','serviceSlug','service_slug']);
        }
        return '';
    }

    private static function collect_config_strings($node, array &$out): void {
        if (is_string($node)) {
            $value = trim($node);
            if ($value === '') return;
            if (preg_match('~^(?:vless|vmess|trojan|ss|hysteria2|hysteria|hy2|tuic)://~i', $value)) {
                $out[] = $value;
                return;
            }
            if (preg_match_all('~(?:vless|vmess|trojan|ss|hysteria2|hysteria|hy2|tuic)://[^\s"\'<>]+~i', $value, $m)) {
                foreach ($m[0] as $config) $out[] = trim((string)$config);
            }
            return;
        }
        if (!is_array($node)) return;
        foreach ($node as $value) self::collect_config_strings($value, $out);
    }

    public static function extract_configs(array $response): array {
        $out = [];
        self::collect_config_strings($response, $out);
        return array_values(array_unique(array_filter($out)));
    }

    private static function map_option(int $sourceId, int $customerId): string {
        return 'bluevpn_shahrah_' . $sourceId . '_' . $customerId;
    }

    private static function panel_map_option(int $panelId,int $customerId): string {
        return 'bluevpn_shahrah_panel_' . $panelId . '_' . $customerId;
    }

    public static function panel_mapping(int $panelId,int $customerId): array {
        $value=get_option(self::panel_map_option($panelId,$customerId),[]);
        return is_array($value)?$value:[];
    }

    private static function save_panel_mapping(int $panelId,int $customerId,array $mapping): void {
        update_option(self::panel_map_option($panelId,$customerId),$mapping,false);
    }

    public static function mapping(int $sourceId, int $customerId): array {
        $value = get_option(self::map_option($sourceId, $customerId), []);
        return is_array($value) ? $value : [];
    }

    private static function save_mapping(int $sourceId, int $customerId, array $mapping): void {
        update_option(self::map_option($sourceId, $customerId), $mapping, false);
    }

    public static function provision(int $sourceId, int $customerId, string $apiKey, string $planSlug, string $username): array {
        $existing = self::mapping($sourceId, $customerId);
        $serviceSlug = trim((string)($existing['service_slug'] ?? ''));
        $response = [];

        if ($serviceSlug !== '') {
            $owned=self::resolve_owned_service($apiKey,$username,$serviceSlug);
            $serviceSlug=trim((string)($owned['service_slug']??''));
            if($serviceSlug!=='')$response=self::renew_service($apiKey,$serviceSlug,$planSlug);
        }

        if ($serviceSlug === '') {
            $response = self::create_service_with_recovery($apiKey,$planSlug,$username);
            $serviceSlug = self::extract_service_slug((array)$response['json'], $username);
            if ($serviceSlug === '') {
                $listing = self::services($apiKey, ['limit'=>100,'page'=>1]);
                $candidate = self::find_service_for_username((array)$listing['json'], $username);
                $serviceSlug = self::extract_service_slug($candidate, $username);
            }
            if ($serviceSlug === '') {
                throw new RuntimeException('شاهراه سرویس را ساخت اما slug سرویس از پاسخ قابل تشخیص نبود.');
            }
        }

        self::save_mapping($sourceId, $customerId, [
            'service_slug' => $serviceSlug,
            'username' => $username,
            'plan_slug' => $planSlug,
            'updated_at' => BlueVPN_Utils::iso_now(),
        ]);

        return [
            'ok' => true,
            'service_slug' => $serviceSlug,
            'username' => $username,
            'configs' => self::extract_configs((array)$response['json']),
            'response' => (array)$response['json'],
        ];
    }

    private static function locate_service_by_username(string $apiKey,string $username,int $maxPages=5): array {
        $maxPages=max(1,min(10,$maxPages));
        for($page=1;$page<=$maxPages;$page++){
            $listing=self::services($apiKey,['limit'=>100,'page'=>$page]);
            $candidate=self::find_service_for_username((array)($listing['json']??[]),$username);
            if($candidate)return $candidate;
        }
        return [];
    }

    private static function transient_create_failure(Throwable $e): bool {
        $message=$e->getMessage();
        return str_contains($message,'خطای داخلی هنگام پردازش درخواست')
            || str_contains($message,'ارتباط با وب‌سرویس شاهراه برقرار نشد')
            || preg_match('/\\bHTTP\\s+5\\d\\d\\b/i',$message)===1;
    }

    /**
     * A Shahrah create is a charge-bearing POST. A transport failure or 5xx is
     * ambiguous because the upstream may have committed the service before its
     * response failed. Never blindly resend the POST: reconcile the deterministic
     * username with short GET probes and let a later repair run retry only if the
     * service is still absent.
     */
    private static function create_service_with_recovery(string $apiKey,string $planSlug,string $username): array {
        try{
            return self::create_service($apiKey,$planSlug,$username);
        }catch(Throwable $e){
            if(!self::transient_create_failure($e))throw $e;
            for($attempt=1;$attempt<=4;$attempt++){
                usleep(200000*$attempt);
                try{
                    $candidate=self::locate_service_by_username($apiKey,$username,1);
                    if($candidate){
                        return [
                            'ok'=>true,
                            'code'=>200,
                            'status'=>'RecoveredAfterAmbiguousCreate',
                            'items'=>[],
                            'json'=>$candidate,
                            'recovered_after_create_error'=>true,
                        ];
                    }
                }catch(Throwable $lookupError){
                    // Keep the original create failure. A failed reconciliation
                    // GET must never trigger a second chargeable POST.
                }
            }
            throw new RuntimeException(
                $e->getMessage().' وضعیت ساخت سرویس نامشخص است؛ برای جلوگیری از ساخت یا هزینه تکراری، POST دوباره ارسال نشد. چند ثانیه بعد اسکن را دوباره اجرا کنید.'
            );
        }
    }

    private static function resolve_owned_service(string $apiKey,string $username,string $existingSlug): array {
        $existingSlug=trim($existingSlug);
        if($existingSlug==='')return [];
        try{$response=self::service($apiKey,$existingSlug);}
        catch(Throwable $e){
            $msg=$e->getMessage();
            if(str_contains($msg,'پیدا نشد')||str_contains($msg,'404'))return [];
            throw $e;
        }
        $payload=(array)($response['json']??[]);
        $state=self::service_username_state($payload,$username);
        if($state==='match')return ['service_slug'=>$existingSlug,'response'=>$payload,'action'=>'existing'];
        $candidate=self::locate_service_by_username($apiKey,$username,5);
        $candidateSlug=self::extract_service_slug($candidate,$username);
        if($candidateSlug!=='')return ['service_slug'=>$candidateSlug,'response'=>$candidate,'action'=>'attached'];
        if($state==='unknown')throw new RuntimeException('مالکیت سرویس شاهراه قابل تأیید نیست؛ برای جلوگیری از تمدید کاربر اشتباه عملیات متوقف شد.');
        return [];
    }

    private static function repair_without_renew(string $apiKey,string $planSlug,string $username,string $existingSlug=''): array {
        $planSlug=self::clean_slug($planSlug,'planSlug');
        $username=self::clean_slug($username,'username');
        $serviceSlug=trim($existingSlug);
        $response=[];

        if($serviceSlug!==''){
            $owned=self::resolve_owned_service($apiKey,$username,$serviceSlug);
            if($owned){
                return [
                    'ok'=>true,
                    'action'=>(string)$owned['action'],
                    'service_slug'=>(string)$owned['service_slug'],
                    'username'=>$username,
                    'response'=>(array)$owned['response'],
                ];
            }
            $serviceSlug='';
        }

        $candidate=self::locate_service_by_username($apiKey,$username,5);
        if($candidate){
            $serviceSlug=self::extract_service_slug($candidate,$username);
            if($serviceSlug!==''){
                return [
                    'ok'=>true,
                    'action'=>'attached',
                    'service_slug'=>$serviceSlug,
                    'username'=>$username,
                    'response'=>$candidate,
                ];
            }
        }

        $response=self::create_service_with_recovery($apiKey,$planSlug,$username);
        $serviceSlug=self::extract_service_slug((array)($response['json']??[]),$username);
        if($serviceSlug===''){
            $candidate=self::locate_service_by_username($apiKey,$username,5);
            $serviceSlug=self::extract_service_slug($candidate,$username);
        }
        if($serviceSlug==='')throw new RuntimeException('شاهراه سرویس گمشده را ساخت اما slug سرویس از پاسخ قابل تشخیص نبود.');

        return [
            'ok'=>true,
            'action'=>'created',
            'service_slug'=>$serviceSlug,
            'username'=>$username,
            'response'=>(array)($response['json']??[]),
        ];
    }

    public static function repair_panel_customer(int $panelId,int $customerId,string $planSlug,string $username): array {
        $panel=self::panel($panelId);
        if(!$panel||!(int)($panel['active']??0))throw new RuntimeException('اتصال فعال شاهراه پیدا نشد.');
        if(!self::plan_exists($panelId,$planSlug,true))throw new RuntimeException('پلن Shahrah در آخرین Sync این اتصال وجود ندارد.');
        $apiKey=self::panel_api_key($panel);
        if($apiKey==='')throw new RuntimeException('API KEY شاهراه تنظیم نشده است.');
        $mapping=self::panel_mapping($panelId,$customerId);
        $result=self::repair_without_renew($apiKey,$planSlug,$username,trim((string)($mapping['service_slug']??'')));
        self::save_panel_mapping($panelId,$customerId,[
            'service_slug'=>(string)$result['service_slug'],
            'username'=>$username,
            'plan_slug'=>$planSlug,
            'updated_at'=>BlueVPN_Utils::iso_now(),
            'repair_action'=>(string)$result['action'],
        ]);
        return $result;
    }

    public static function repair_source_customer(int $sourceId,int $customerId,string $apiKey,string $planSlug,string $username): array {
        $mapping=self::mapping($sourceId,$customerId);
        $result=self::repair_without_renew($apiKey,$planSlug,$username,trim((string)($mapping['service_slug']??'')));
        self::save_mapping($sourceId,$customerId,[
            'service_slug'=>(string)$result['service_slug'],
            'username'=>$username,
            'plan_slug'=>$planSlug,
            'updated_at'=>BlueVPN_Utils::iso_now(),
            'repair_action'=>(string)$result['action'],
        ]);
        return $result;
    }

    public static function provision_panel(int $panelId,int $customerId,string $planSlug,string $username): array {
        $panel=self::panel($panelId);
        if(!$panel||!(int)($panel['active']??0))throw new RuntimeException('اتصال فعال شاهراه پیدا نشد.');
        $apiKey=self::panel_api_key($panel);
        if($apiKey==='')throw new RuntimeException('API KEY شاهراه تنظیم نشده است.');
        $existing=self::panel_mapping($panelId,$customerId);
        $serviceSlug=trim((string)($existing['service_slug']??''));
        $response=[];

        if($serviceSlug!==''){
            $owned=self::resolve_owned_service($apiKey,$username,$serviceSlug);
            $serviceSlug=trim((string)($owned['service_slug']??''));
            if($serviceSlug!=='')$response=self::renew_service($apiKey,$serviceSlug,$planSlug);
        }

        if($serviceSlug===''){
            $response=self::create_service_with_recovery($apiKey,$planSlug,$username);
            $serviceSlug=self::extract_service_slug((array)$response['json'],$username);
            if($serviceSlug===''){
                $listing=self::services($apiKey,['limit'=>100,'page'=>1]);
                $candidate=self::find_service_for_username((array)$listing['json'],$username);
                $serviceSlug=self::extract_service_slug($candidate,$username);
            }
            if($serviceSlug==='')throw new RuntimeException('شاهراه سرویس را ساخت اما slug سرویس از پاسخ قابل تشخیص نبود.');
        }

        self::save_panel_mapping($panelId,$customerId,[
            'service_slug'=>$serviceSlug,
            'username'=>$username,
            'plan_slug'=>$planSlug,
            'updated_at'=>BlueVPN_Utils::iso_now(),
        ]);
        return ['ok'=>true,'service_slug'=>$serviceSlug,'username'=>$username,'response'=>(array)$response['json']];
    }

    public static function configs_for_panel_customer(int $panelId,int $customerId): array {
        $panel=self::panel($panelId);
        if(!$panel||!(int)($panel['active']??0))return ['ok'=>false,'lines'=>[],'message'=>'اتصال شاهراه غیرفعال یا حذف شده است.'];
        $apiKey=self::panel_api_key($panel);
        if($apiKey==='')return ['ok'=>false,'lines'=>[],'message'=>'API KEY شاهراه تنظیم نشده است.'];
        $mapping=self::panel_mapping($panelId,$customerId);
        $slug=trim((string)($mapping['service_slug']??''));
        if($slug==='')return ['ok'=>false,'lines'=>[],'message'=>'سرویس شاهراه برای این کاربر هنوز ساخته نشده است.'];
        $response=self::service($apiKey,$slug);
        $lines=self::extract_configs((array)$response['json']);
        return [
            'ok'=>!empty($lines),
            'lines'=>$lines,
            'message'=>$lines?count($lines).' کانفیگ از شاهراه دریافت شد.':'سرویس شاهراه پاسخ داد اما کانفیگ قابل استفاده‌ای در پاسخ نبود.',
            'service_slug'=>$slug,
        ];
    }

    public static function configs_for_customer(int $sourceId, int $customerId, string $apiKey): array {
        $mapping = self::mapping($sourceId, $customerId);
        $slug = trim((string)($mapping['service_slug'] ?? ''));
        if ($slug === '') return ['ok'=>false,'lines'=>[],'message'=>'سرویس شاهراه برای این کاربر هنوز provision نشده است.'];
        $response = self::service($apiKey, $slug);
        $lines = self::extract_configs((array)$response['json']);
        return [
            'ok' => !empty($lines),
            'lines' => $lines,
            'message' => $lines ? count($lines) . ' کانفیگ از شاهراه دریافت شد.' : 'سرویس شاهراه پاسخ داد اما کانفیگ قابل استفاده‌ای در پاسخ نبود.',
            'service_slug' => $slug,
        ];
    }
}
