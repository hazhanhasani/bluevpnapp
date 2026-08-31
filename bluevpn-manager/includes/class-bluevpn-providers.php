<?php
if (!defined('ABSPATH')) exit;

final class BlueVPN_Providers {
    private const SYNC_TTL_SECONDS = 300;
    private const SNAPSHOT_FRESH_SECONDS = 300;
    private const SNAPSHOT_STALE_SECONDS = 21600;
    private const EXPIRY_DRIFT_TOLERANCE_SECONDS = 21600;
    private const EXPIRY_REPAIR_OPTION = 'bluevpn_expiry_inflation_repair_4174';
    private const EXPIRY_NON_GRANT_REPAIR_OPTION = 'bluevpn_expiry_non_grant_repair_4175';
    private const PAID_REPAIR_CURSOR_OPTION = 'bluevpn_paid_subscription_repair_cursor';
    private const PAID_REPAIR_LAST_OPTION = 'bluevpn_paid_subscription_repair_last';
    private const RETRYABLE_HTTP = [408,425,429,500,502,503,504];

    public static function init(): void {
        add_action('template_redirect',[self::class,'serve_subscription'],0);
        add_action('bluevpn_refresh_subscription_snapshot',[self::class,'refresh_subscription_snapshot'],10,1);
        add_action('bluevpn_sync_customer_async',[self::class,'async_sync_customer'],10,1);
    }

    private static function panel(string $provider,int $id): ?array {
        global $wpdb;
        $map=['pasarguard'=>'pasarguard_panels','marzban'=>'marzban_panels','guardcore'=>'guardcore_panels','hiddify'=>'hiddify_panels','threexui'=>'threexui_panels'];
        if(!isset($map[$provider])||$id<=0)return null;
        $t=BlueVPN_DB::table($map[$provider]);
        $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d",$id),ARRAY_A);
        return is_array($r)?$r:null;
    }
    public static function plan_provider_routes(array $plan): array {
        $raw=BlueVPN_Utils::json_decode_array((string)($plan['provider_routes_json']??''),[]);
        $out=['pasarguard'=>[],'marzban'=>[],'shahrah'=>[],'guardcore'=>[],'hiddify'=>[],'threexui'=>[]];
        foreach(array_keys($out) as $provider){
            $rows=$raw[$provider]??[];
            if(!is_array($rows))$rows=[];
            foreach($rows as $row){
                if(!is_array($row))continue;
                $panelId=max(0,(int)($row['panel_id']??0));if($panelId<=0)continue;
                $route=['panel_id'=>$panelId];
                if($provider==='shahrah'){
                    $slug=trim((string)($row['plan_slug']??''));if($slug==='')continue;
                    $route['plan_slug']=$slug;
                }elseif($provider==='guardcore'){
                    $route['service_ids']=array_values(array_unique(array_filter(array_map('intval',(array)($row['service_ids']??[])),static fn($x)=>$x>0)));
                }elseif($provider==='pasarguard'){
                    $route['group_ids']=array_values(array_unique(array_filter(array_map('intval',(array)($row['group_ids']??[])),static fn($x)=>$x>0)));
                }elseif($provider==='marzban'){
                    $route['inbounds']=is_array($row['inbounds']??null)?$row['inbounds']:[];
                }elseif($provider==='threexui'){
                    $route['inbound_ids']=array_values(array_unique(array_filter(array_map('intval',(array)($row['inbound_ids']??[])),static fn($x)=>$x>0)));
                }
                $out[$provider][]=$route;
            }
        }

        // Backward compatibility: old single-provider columns become one route
        // only when the new route list for that provider is empty.
        if(!$out['pasarguard']&&(int)($plan['panel_id']??0)>0){
            $out['pasarguard'][]=['panel_id'=>(int)$plan['panel_id'],'group_ids'=>BlueVPN_Utils::json_decode_array((string)($plan['group_ids_json']??''),[])];
        }
        if(!$out['marzban']&&(int)($plan['marzban_panel_id']??0)>0){
            $out['marzban'][]=['panel_id'=>(int)$plan['marzban_panel_id'],'inbounds'=>BlueVPN_Utils::json_decode_array((string)($plan['marzban_inbounds_json']??''),[])];
        }
        if(!$out['shahrah']&&(int)($plan['shahrah_panel_id']??0)>0&&trim((string)($plan['shahrah_plan_slug']??''))!==''){
            $out['shahrah'][]=['panel_id'=>(int)$plan['shahrah_panel_id'],'plan_slug'=>trim((string)$plan['shahrah_plan_slug'])];
        }
        if(!$out['guardcore']&&(int)($plan['guardcore_panel_id']??0)>0){
            $out['guardcore'][]=['panel_id'=>(int)$plan['guardcore_panel_id'],'service_ids'=>BlueVPN_Utils::json_decode_array((string)($plan['guardcore_service_ids_json']??''),[])];
        }

        foreach($out as $provider=>$rows){
            $seen=[];$clean=[];
            foreach($rows as $row){
                $key=$provider.':'.(int)$row['panel_id'].($provider==='shahrah'?':'.(string)($row['plan_slug']??''):'');
                if(isset($seen[$key]))continue;$seen[$key]=true;$clean[]=$row;
            }
            $out[$provider]=$clean;
        }
        return $out;
    }

    private static function provider_route_key(string $provider,array $route): string {
        $key=$provider.':'.max(0,(int)($route['panel_id']??0));
        if($provider==='shahrah')$key.=':'.trim((string)($route['plan_slug']??''));
        return mb_substr($key,0,190);
    }

    private static function provider_link_upsert(int $customerId,int $planId,string $provider,array $route,array $data=[]): void {
        global $wpdb;
        $t=BlueVPN_DB::table('customer_provider_links');
        $panelId=max(0,(int)($route['panel_id']??0));if($customerId<=0||$panelId<=0)return;
        $routeKey=self::provider_route_key($provider,$route);
        $existing=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE customer_id=%d AND provider_type=%s AND panel_id=%d AND route_key=%s",$customerId,$provider,$panelId,$routeKey));
        $row=[
            'customer_id'=>$customerId,'plan_id'=>$planId,'provider_type'=>$provider,'panel_id'=>$panelId,'route_key'=>$routeKey,
            'username'=>(string)($data['username']??''),'remote_id'=>(string)($data['remote_id']??''),
            'subscription_url'=>(string)($data['subscription_url']??''),'status'=>(string)($data['status']??'active'),
            'used_traffic_bytes'=>max(0,(int)($data['used_traffic_bytes']??0)),
            'remote_expire'=>$data['remote_expire']??null,
            'metadata_json'=>BlueVPN_Utils::json_encode((array)($data['metadata']??$route)),
            'last_error'=>(string)($data['last_error']??''),
            'last_sync_at'=>BlueVPN_Utils::now_mysql(),'updated_at'=>BlueVPN_Utils::now_mysql(),
        ];
        if($existing>0)$wpdb->update($t,$row,['id'=>$existing]);
        else{$row['created_at']=BlueVPN_Utils::now_mysql();$wpdb->insert($t,$row);}
    }

    public static function customer_provider_links(int $customerId): array {
        if($customerId<=0)return [];
        global $wpdb;$t=BlueVPN_DB::table('customer_provider_links');
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE customer_id=%d ORDER BY provider_type,panel_id,id",$customerId),ARRAY_A)?:[];
    }

    private static function prune_customer_provider_links(int $customerId,array $desiredRouteKeys): void {
        global $wpdb;$t=BlueVPN_DB::table('customer_provider_links');
        $desired=array_fill_keys(array_values(array_unique(array_filter(array_map('strval',$desiredRouteKeys)))),true);
        foreach(self::customer_provider_links($customerId) as $row){
            if(isset($desired[(string)$row['route_key']]))continue;
            $wpdb->delete($t,['id'=>(int)$row['id']]);
        }
    }

    private static function req(string $method,string $url,array $headers=[],?array $json=null,bool $ssl=true,array $form=[],int $timeout=25): array {
        $method=strtoupper($method);$baseTimeout=max(3,min(25,$timeout));
        $baseArgs=['method'=>$method,'timeout'=>$baseTimeout,'redirection'=>2,'sslverify'=>$ssl,'headers'=>array_merge(['Accept'=>'application/json','User-Agent'=>'BlueVPN-WordPress/'.BLUEVPN_MANAGER_VERSION],$headers)];
        if($json!==null){$baseArgs['headers']['Content-Type']='application/json';$baseArgs['body']=wp_json_encode($json);}
        elseif($form){$baseArgs['body']=$form;}
        // Retry only idempotent GET requests. Earlier transient attempts are marked
        // so Sentinel observes only the final exhausted failure, not every probe.
        $attempts=$method==='GET'?2:1;$lastError='';
        for($attempt=0;$attempt<$attempts;$attempt++){
            $args=$baseArgs;
            if($attempt+1<$attempts){
                $args['headers']['X-BlueVPN-Sentinel-Transient']='1';
                $args['timeout']=min(8,$baseTimeout);
            }
            $res=wp_remote_request($url,$args);
            if(is_wp_error($res)){
                $lastError=$res->get_error_message();
                if($attempt+1<$attempts){usleep(250000);continue;}
                throw new RuntimeException($lastError);
            }
            $code=(int)wp_remote_retrieve_response_code($res);$body=(string)wp_remote_retrieve_body($res);
            if($attempt+1<$attempts&&in_array($code,self::RETRYABLE_HTTP,true)){usleep(350000);continue;}
            $decoded=json_decode($body,true);
            return ['code'=>$code,'body'=>$body,'json'=>is_array($decoded)?$decoded:[],'headers'=>wp_remote_retrieve_headers($res)];
        }
        throw new RuntimeException($lastError!==''?$lastError:'درخواست Provider ناموفق بود.');
    }
    private static function join_url(string $base,string $path): string { return rtrim($base,'/').'/'.ltrim($path,'/'); }
    private static function absolute_url(string $base,string $value): string {
        $value=trim($value);if($value==='')return '';
        if(preg_match('~^https?://~i',$value))return $value;
        return self::join_url($base,$value);
    }
    private static function pg_headers(array $p,int $timeout=25): array {
        $mode=(string)($p['auth_mode']??'api_key');
        if($mode==='api_key'){
            $key=BlueVPN_Utils::decrypt_secret((string)($p['api_key_enc']??''));
            if($key==='')throw new RuntimeException('کلید API پاسارگارد تنظیم نشده است.');
            return ['X-Api-Key'=>$key];
        }
        $u=BlueVPN_Utils::decrypt_secret((string)($p['username_enc']??''));
        $pw=BlueVPN_Utils::decrypt_secret((string)($p['password_enc']??''));
        if($u===''||$pw==='')throw new RuntimeException('نام کاربری یا رمز پاسارگارد تنظیم نشده است.');
        $r=self::req('POST',self::join_url((string)$p['base_url'],'/api/admin/token'),[],null,(bool)$p['verify_tls'],['grant_type'=>'password','username'=>$u,'password'=>$pw],$timeout);
        if($r['code']>=400)throw new RuntimeException('ورود پاسارگارد ناموفق: HTTP '.$r['code']);
        $tok=(string)($r['json']['access_token']??$r['json']['token']??'');
        if($tok==='')throw new RuntimeException('توکن پاسارگارد دریافت نشد.');
        return ['Authorization'=>'Bearer '.$tok];
    }
    private static function mz_headers(array $p,int $timeout=25): array {
        $u=BlueVPN_Utils::decrypt_secret((string)($p['username_enc']??''));
        $pw=BlueVPN_Utils::decrypt_secret((string)($p['password_enc']??''));
        if($u===''||$pw==='')throw new RuntimeException('نام کاربری یا رمز Marzban تنظیم نشده است.');
        $r=self::req('POST',self::join_url((string)$p['base_url'],'/api/admin/token'),[],null,(bool)$p['verify_tls'],['grant_type'=>'password','username'=>$u,'password'=>$pw],$timeout);
        if($r['code']>=400)throw new RuntimeException('ورود Marzban ناموفق: HTTP '.$r['code']);
        $tok=(string)($r['json']['access_token']??'');
        if($tok==='')throw new RuntimeException('توکن Marzban دریافت نشد.');
        return ['Authorization'=>'Bearer '.$tok];
    }
    private static function gc_token_headers(array $p,string $totpCode=''): array {
        $u=BlueVPN_Utils::decrypt_secret((string)($p['username_enc']??''));
        $pw=BlueVPN_Utils::decrypt_secret((string)($p['password_enc']??''));
        if($u===''||$pw==='')throw new RuntimeException('نام کاربری یا رمز GuardCore تنظیم نشده است.');

        $query=$totpCode!==''?'?totp_code='.rawurlencode($totpCode):'';
        $r=self::req(
            'POST',
            self::join_url((string)$p['base_url'],'/api/admins/token'.$query),
            [],
            null,
            (bool)$p['verify_tls'],
            ['grant_type'=>'password','username'=>$u,'password'=>$pw]
        );
        if($r['code']>=400){
            $suffix=$r['code']===401&&$totpCode===''?' — اگر TOTP فعال است کد یک‌بارمصرف لازم است.':'';
            throw new RuntimeException('ورود GuardCore ناموفق: HTTP '.$r['code'].' '.mb_substr($r['body'],0,220).$suffix);
        }
        $tok=(string)($r['json']['access_token']??'');
        if($tok==='')throw new RuntimeException('توکن GuardCore دریافت نشد.');
        return ['Authorization'=>'Bearer '.$tok];
    }

    private static function gc_headers(array $p): array {
        $mode=(string)($p['auth_mode']??'manual');
        if($mode==='manual')return [];
        if($mode==='api_key'){
            $key=BlueVPN_Utils::decrypt_secret((string)($p['api_key_enc']??''));
            if($key==='')throw new RuntimeException('کلید API GuardCore تنظیم نشده است.');
            return ['X-API-Key'=>$key];
        }
        return self::gc_token_headers($p);
    }

    public static function guardcore_bootstrap_api_key(int $panelId,string $totpCode=''): array {
        $p=self::panel('guardcore',$panelId);
        if(!$p)return ['ok'=>false,'message'=>'پنل GuardCore پیدا نشد.'];
        if(($p['auth_mode']??'manual')==='manual')return ['ok'=>false,'message'=>'پنل GuardCore روی Manual است.'];
        try{
            $headers=self::gc_token_headers($p,trim($totpCode));
            $r=self::req('GET',self::join_url((string)$p['base_url'],'/api/admins/current'),$headers,null,(bool)$p['verify_tls']);
            if($r['code']>=400)throw new RuntimeException('خواندن Admin فعلی ناموفق: HTTP '.$r['code']);
            $key=trim((string)($r['json']['api_key']??''));
            if($key==='')throw new RuntimeException('GuardCore API Key را در پاسخ Admin برنگرداند.');
            global $wpdb;
            $wpdb->update(
                BlueVPN_DB::table('guardcore_panels'),
                [
                    'api_key_enc'=>BlueVPN_Utils::encrypt_secret($key),
                    'auth_mode'=>'api_key',
                    'last_sync_at'=>BlueVPN_Utils::now_mysql(),
                ],
                ['id'=>$panelId]
            );
            return ['ok'=>true,'message'=>'API Key از GuardCore دریافت شد و پنل برای عملیات خودکار روی API Key قرار گرفت.'];
        }catch(Throwable $e){
            return ['ok'=>false,'message'=>$e->getMessage()];
        }
    }

    private static function gc_request(array $p,string $method,string $path,?array $json=null,array $query=[]): array {
        if($query){
            $path.=(str_contains($path,'?')?'&':'?').http_build_query($query,'','&',PHP_QUERY_RFC3986);
        }
        return self::req(
            $method,
            self::join_url((string)$p['base_url'],$path),
            self::gc_headers($p),
            $json,
            (bool)$p['verify_tls']
        );
    }

    public static function guardcore_catalog(int $panelId,bool $force=false): array {
        $p=self::panel('guardcore',$panelId);
        if(!$p)return ['ok'=>false,'message'=>'پنل GuardCore پیدا نشد.'];
        if(($p['auth_mode']??'manual')==='manual'){
            return ['ok'=>true,'manual'=>true,'services'=>[],'nodes'=>[],'stats'=>[],'capabilities'=>[],'version'=>'manual'];
        }

        $cachedAt=!empty($p['last_sync_at'])?(strtotime((string)$p['last_sync_at'].' UTC')?:0):0;
        if(!$force&&$cachedAt>0&&(time()-$cachedAt)<300){
            return [
                'ok'=>true,
                'cached'=>true,
                'version'=>(string)($p['api_version']??''),
                'services'=>BlueVPN_Utils::json_decode_array((string)($p['services_json']??''),[]),
                'nodes'=>BlueVPN_Utils::json_decode_array((string)($p['nodes_json']??''),[]),
                'stats'=>BlueVPN_Utils::json_decode_array((string)($p['stats_json']??''),[]),
                'capabilities'=>BlueVPN_Utils::json_decode_array((string)($p['capabilities_json']??''),[]),
            ];
        }

        try{
            $base=(string)$p['base_url'];
            $version='';
            $capabilities=[
                'subscriptions'=>true,
                'subscription_stats'=>true,
                'subscription_status_stats'=>true,
                'subscription_usage'=>true,
                'subscription_actions'=>['enable','disable','revoke','reset'],
                'services'=>true,
                'nodes'=>true,
                'nodes_stats'=>true,
                'admins'=>true,
                'totp'=>true,
                'auto_renewals'=>true,
            ];

            // OpenAPI is public in GuardCore 0.13.0. Failure here does not block API use.
            try{
                $open=self::req('GET',self::join_url($base,'/openapi.json'),[],null,(bool)$p['verify_tls'],[],8);
                if($open['code']<400&&is_array($open['json'])){
                    $version=(string)($open['json']['info']['version']??'');
                    $paths=array_keys((array)($open['json']['paths']??[]));
                    $capabilities['openapi_paths']=$paths;
                }
            }catch(Throwable $ignore){}

            $services=self::gc_request($p,'GET','/api/services');
            $nodes=self::gc_request($p,'GET','/api/nodes');
            $nodeStats=self::gc_request($p,'GET','/api/nodes/stats');
            $subStats=self::gc_request($p,'GET','/api/subscriptions/stats');
            $statusStats=self::gc_request($p,'GET','/api/stats/subscriptions/status');
            $admin=self::gc_request($p,'GET','/api/admins/current');
            $agents=self::gc_request($p,'GET','/api/stats/agents');
            $start=gmdate('Y-m-d',time()-7*DAY_IN_SECONDS);
            $end=gmdate('Y-m-d');
            $usage=self::gc_request($p,'GET','/api/stats/usage',null,['start_date'=>$start,'end_date'=>$end]);
            $mostUsage=self::gc_request($p,'GET','/api/stats/subscriptions/most_usage',null,['start_date'=>$start,'end_date'=>$end]);

            foreach([
                'services'=>$services,'nodes'=>$nodes,'node_stats'=>$nodeStats,
                'subscription_stats'=>$subStats,'status_stats'=>$statusStats,'admin'=>$admin,
                'agents'=>$agents,'usage'=>$usage,'most_usage'=>$mostUsage,
            ] as $name=>$response){
                if($response['code']>=400){
                    throw new RuntimeException('GuardCore '.$name.' ناموفق: HTTP '.$response['code'].' '.mb_substr($response['body'],0,180));
                }
            }

            if($version==='')$version='0.13-compatible';
            $stats=[
                'nodes'=>$nodeStats['json'],
                'subscriptions'=>$subStats['json'],
                'status'=>$statusStats['json'],
                'usage_7d'=>$usage['json'],
                'most_usage_7d'=>$mostUsage['json'],
                'agents'=>$agents['json'],
                'admin'=>[
                    'username'=>(string)($admin['json']['username']??''),
                    'role'=>(string)($admin['json']['role']??''),
                    'current_count'=>(int)($admin['json']['current_count']??0),
                    'left_count'=>(int)($admin['json']['left_count']??0),
                    'current_usage'=>(int)($admin['json']['current_usage']??0),
                    'left_usage'=>(int)($admin['json']['left_usage']??0),
                    'totp_status'=>(bool)($admin['json']['totp_status']??false),
                ],
            ];

            global $wpdb;
            $wpdb->update(
                BlueVPN_DB::table('guardcore_panels'),
                [
                    'api_version'=>$version,
                    'services_json'=>BlueVPN_Utils::json_encode((array)$services['json']),
                    'nodes_json'=>BlueVPN_Utils::json_encode((array)$nodes['json']),
                    'stats_json'=>BlueVPN_Utils::json_encode($stats),
                    'capabilities_json'=>BlueVPN_Utils::json_encode($capabilities),
                    'last_sync_at'=>BlueVPN_Utils::now_mysql(),
                ],
                ['id'=>$panelId]
            );

            return [
                'ok'=>true,'cached'=>false,'version'=>$version,
                'services'=>(array)$services['json'],
                'nodes'=>(array)$nodes['json'],
                'stats'=>$stats,
                'capabilities'=>$capabilities,
            ];
        }catch(Throwable $e){
            return ['ok'=>false,'message'=>$e->getMessage()];
        }
    }

    public static function guardcore_subscription_detail(int $panelId,string $username): array {
        $p=self::panel('guardcore',$panelId);
        if(!$p)return ['ok'=>false,'message'=>'پنل GuardCore پیدا نشد.'];
        try{
            $user=self::gc_user($p,$username);
            if(!$user)return ['ok'=>false,'message'=>'Subscription در GuardCore پیدا نشد.'];
            $usage=self::guardcore_subscription_usage($panelId,$username);
            return ['ok'=>true,'subscription'=>$user,'usages'=>$usage['ok']?($usage['data']['usages']??[]):[]];
        }catch(Throwable $e){
            return ['ok'=>false,'message'=>$e->getMessage()];
        }
    }

    public static function guardcore_node_action(int $panelId,int $nodeId,bool $enable): array {
        $p=self::panel('guardcore',$panelId);
        if(!$p||$nodeId<=0)return ['ok'=>false,'message'=>'Node یا پنل GuardCore نامعتبر است.'];
        try{
            $r=self::gc_request($p,'POST','/api/nodes/'.$nodeId.'/'.($enable?'enable':'disable'));
            if($r['code']>=400)throw new RuntimeException('تغییر وضعیت Node ناموفق: HTTP '.$r['code'].' '.mb_substr($r['body'],0,240));
            self::guardcore_catalog($panelId,true);
            return ['ok'=>true,'message'=>'وضعیت Node GuardCore بروزرسانی شد.'];
        }catch(Throwable $e){
            return ['ok'=>false,'message'=>$e->getMessage()];
        }
    }

    public static function guardcore_subscription_action(int $panelId,string $username,string $action): array {
        $p=self::panel('guardcore',$panelId);
        if(!$p)return ['ok'=>false,'message'=>'پنل GuardCore پیدا نشد.'];
        $username=trim($username);
        $action=sanitize_key($action);
        $paths=[
            'enable'=>'/api/subscriptions/enable',
            'disable'=>'/api/subscriptions/disable',
            'revoke'=>'/api/subscriptions/revoke',
            'reset'=>'/api/subscriptions/reset',
        ];
        if($username===''||!isset($paths[$action]))return ['ok'=>false,'message'=>'عملیات GuardCore نامعتبر است.'];
        try{
            $r=self::gc_request($p,'POST',$paths[$action],['usernames'=>[$username]]);
            if($r['code']>=400)throw new RuntimeException('GuardCore '.$action.' ناموفق: HTTP '.$r['code'].' '.mb_substr($r['body'],0,260));
            return ['ok'=>true,'message'=>'عملیات '.$action.' برای '.$username.' انجام شد.','data'=>$r['json']];
        }catch(Throwable $e){
            return ['ok'=>false,'message'=>$e->getMessage()];
        }
    }

    public static function guardcore_subscription_usage(int $panelId,string $username): array {
        $p=self::panel('guardcore',$panelId);
        if(!$p)return ['ok'=>false,'message'=>'پنل GuardCore پیدا نشد.'];
        try{
            $r=self::gc_request($p,'GET','/api/subscriptions/'.rawurlencode($username).'/usages');
            if($r['code']>=400)throw new RuntimeException('خواندن Usage GuardCore ناموفق: HTTP '.$r['code']);
            return ['ok'=>true,'data'=>$r['json']];
        }catch(Throwable $e){
            return ['ok'=>false,'message'=>$e->getMessage()];
        }
    }

    public static function guardcore_reached(int $panelId,int $size=20): array {
        $p=self::panel('guardcore',$panelId);
        if(!$p)return [];
        try{
            $r=self::gc_request($p,'GET','/api/stats/subscriptions/reacheds',null,['page'=>1,'size'=>max(1,min(100,$size))]);
            return $r['code']<400&&is_array($r['json'])?$r['json']:[];
        }catch(Throwable $e){return [];}
    }

    private static function gc_usage_bytes(array $p,$value): int {
        $amount=is_numeric($value)?max(0,(float)$value):0;
        if(($p['usage_unit']??'bytes')==='gb')$amount*=1024*1024*1024;
        return max(0,(int)$amount);
    }
    private static function gc_usage_encode(array $p,int $bytes): int {
        $bytes=max(0,$bytes);if(($p['usage_unit']??'bytes')==='gb')return $bytes===0?0:max(1,(int)ceil($bytes/(1024*1024*1024)));return $bytes;
    }
    private static function gc_expire(array $p,array $data): ?string {
        foreach(['expire','expires_at','expiration','expiry'] as $k)if(!empty($data[$k])){ $v=self::remote_expiry($data[$k]); if($v)return $v; }
        $limit=(int)($data['limit_expire']??0);if($limit<=0)return null;
        if(($p['expire_mode']??'days')==='timestamp')return gmdate('Y-m-d H:i:s',$limit);
        $base=time();foreach(['created_at','updated_at'] as $k)if(!empty($data[$k])){$x=strtotime((string)$data[$k]);if($x){$base=$x;break;}}
        $seconds=(($p['expire_mode']??'days')==='seconds')?$limit:$limit*DAY_IN_SECONDS;return gmdate('Y-m-d H:i:s',$base+$seconds);
    }
    private static function gc_expire_encode(array $p,?string $target): int {
        if(!$target)return 0;$ts=strtotime($target.' UTC');if(!$ts)return 0;if(($p['expire_mode']??'days')==='timestamp')return $ts;$remain=max(1,$ts-time());return (($p['expire_mode']??'days')==='seconds')?$remain:max(1,(int)ceil($remain/DAY_IN_SECONDS));
    }
    private static function gc_normalize(array $p,array $data): array {
        $enabled=!array_key_exists('enabled',$data)||BlueVPN_Utils::boolish($data['enabled']);
        $activated=!array_key_exists('activated',$data)||BlueVPN_Utils::boolish($data['activated']);
        $expired=BlueVPN_Utils::boolish($data['expired']??false);
        $limited=BlueVPN_Utils::boolish($data['limited']??false);
        $isActive=array_key_exists('is_active',$data)?BlueVPN_Utils::boolish($data['is_active']):($enabled&&$activated&&!$expired&&!$limited);
        $status=$isActive?'active':(!$enabled?'disabled':(!$activated?'pending':($expired?'expired':($limited?'limited':'inactive'))));
        $link=(string)($data['link']??$data['subscription_url']??'');
        return [
            'id'=>$data['id']??null,
            'username'=>(string)($data['username']??''),
            'owner_username'=>(string)($data['owner_username']??''),
            'subscription_url'=>self::absolute_url((string)$p['base_url'],$link),
            'status'=>$status,
            'enabled'=>$enabled,
            'activated'=>$activated,
            'is_online'=>BlueVPN_Utils::boolish($data['is_online']??false),
            'online_at'=>(string)($data['online_at']??''),
            'last_request_at'=>(string)($data['last_request_at']??''),
            'last_client_agent'=>(string)($data['last_client_agent']??''),
            'expire'=>self::gc_expire($p,$data),
            'data_limit'=>self::gc_usage_bytes($p,$data['limit_usage']??0),
            'used_traffic'=>self::gc_usage_bytes($p,$data['current_usage']??$data['total_usage']??0),
            'total_usage'=>self::gc_usage_bytes($p,$data['total_usage']??0),
            'reset_usage'=>self::gc_usage_bytes($p,$data['reset_usage']??0),
            'service_ids'=>array_values(array_map('intval',(array)($data['service_ids']??[]))),
            'auto_renewals'=>(array)($data['auto_renewals']??[]),
            'raw'=>$data,
        ];
    }
    private static function gc_user(array $p,string $username): ?array {
        $r=self::req('GET',self::join_url((string)$p['base_url'],'/api/subscriptions/'.rawurlencode($username)),self::gc_headers($p),null,(bool)$p['verify_tls']);
        if($r['code']===404)return null;if($r['code']>=400)throw new RuntimeException('خواندن اشتراک GuardCore ناموفق: HTTP '.$r['code'].' '.mb_substr($r['body'],0,300));if(!$r['json'])throw new RuntimeException('پاسخ GuardCore معتبر نیست.');return self::gc_normalize($p,$r['json']);
    }
    private static function gc_route_service_ids(int $panelId,array $selected=[]): array {
        $selected=array_values(array_unique(array_filter(array_map('intval',$selected),static fn($x)=>$x>0)));
        if($selected)return $selected;
        $catalog=self::guardcore_catalog($panelId,false);
        if(empty($catalog['ok']))throw new RuntimeException('دریافت سرویس‌های گاردکور برای مسیر چندگانه ناموفق بود.');
        $ids=[];
        foreach((array)($catalog['services']??[]) as $row){
            if(!is_array($row))continue;
            if(isset($row['status'])&&in_array(strtolower((string)$row['status']),['disabled','inactive','deleted'],true))continue;
            if(array_key_exists('active',$row)&&!BlueVPN_Utils::boolish($row['active']))continue;
            $id=(int)($row['id']??0);if($id>0)$ids[$id]=$id;
        }
        if(!$ids)throw new RuntimeException('هیچ سرویس فعال گاردکور برای این پنل پیدا نشد.');
        return array_values($ids);
    }

    private static function gc_find_customer_subscription(array $p,array $customer,string $preferredUsername): ?array {
        $preferredUsername=trim($preferredUsername);
        if($preferredUsername!==''){
            $direct=self::gc_user($p,$preferredUsername);
            if($direct)return $direct;
        }

        // Lost local mappings can still be recovered safely from GuardCore 0.13
        // using its subscription search. Matching is deliberately strict: we
        // only attach exact customer identity candidates or a BlueVPN note that
        // contains this WordPress customer id. Never attach on fuzzy similarity.
        $candidates=[];
        foreach([
            $preferredUsername,
            (string)($customer['email']??''),
            (string)($customer['phone']??''),
            (string)($customer['username']??''),
        ] as $candidate){
            $candidate=trim($candidate);
            if($candidate!=='')$candidates[$candidate]=true;
        }
        $customerId=(int)($customer['id']??0);
        if($customerId>0){
            $seed=(string)(($customer['email']??'')?:($customer['phone']??'')?:$customerId);
            $generated=substr('bv_'.$customerId.'_'.substr(sha1('gc:'.$seed),0,9),0,32);
            if($generated!=='')$candidates[$generated]=true;
        }
        $searchTerms=array_keys($candidates);
        if($customerId>0)$searchTerms[]='customer '.$customerId;
        foreach(array_values(array_unique($searchTerms)) as $search){
            $path='/api/subscriptions?search='.rawurlencode($search).'&page=1&size=50';
            $r=self::gc_request($p,'GET',$path);
            if($r['code']>=400)continue;
            $rows=is_array($r['json'])?$r['json']:[];
            foreach($rows as $row){
                if(!is_array($row))continue;
                $username=trim((string)($row['username']??''));
                $note=trim((string)($row['note']??''));
                $exact=$username!==''&&isset($candidates[$username]);
                $owned=$customerId>0&&$note!==''&&(
                    str_contains($note,'customer '.$customerId)||
                    str_contains($note,'customer #'.$customerId)||
                    str_contains($note,'customer_id='.$customerId)
                );
                if($exact||$owned)return self::gc_normalize($p,$row);
            }
        }
        return null;
    }

    private static function gc_provision(array $p,string $username,?string $expire,int $quota,array $serviceIds,string $note,?array $remote): array {
        $serviceIds=array_values(array_unique(array_filter(array_map('intval',$serviceIds),static fn($x)=>$x>0)));if(!$serviceIds)throw new RuntimeException('حداقل یک Service ID برای GuardCore لازم است.');
        $payload=['limit_usage'=>self::gc_usage_encode($p,$quota),'limit_expire'=>self::gc_expire_encode($p,$expire),'service_ids'=>$serviceIds,'note'=>mb_substr($note,0,500)];
        if($remote===null){$body=[array_merge(['username'=>$username],$payload)];$r=self::req('POST',self::join_url((string)$p['base_url'],'/api/subscriptions'),self::gc_headers($p),$body,(bool)$p['verify_tls']);}
        else{$r=self::req('PUT',self::join_url((string)$p['base_url'],'/api/subscriptions/'.rawurlencode($username)),self::gc_headers($p),$payload,(bool)$p['verify_tls']);}
        if($r['code']>=400)throw new RuntimeException('فعال‌سازی GuardCore ناموفق: HTTP '.$r['code'].' '.mb_substr($r['body'],0,420));
        $fresh=self::gc_user($p,$username);
        if(!$fresh)throw new RuntimeException('اشتراک GuardCore ساخته شد ولی قابل خواندن نیست.');
        if(($fresh['status']??'')!=='active'){
            $en=self::gc_request($p,'POST','/api/subscriptions/enable',['usernames'=>[$username]]);
            if($en['code']>=400)throw new RuntimeException('فعال‌سازی نهایی GuardCore ناموفق: HTTP '.$en['code']);
            $fresh=self::gc_user($p,$username)?:$fresh;
        }
        return $fresh;
    }
    private static function pg_probe_capabilities(array $p,int $timeout=12): array {
        $base=(string)$p['base_url'];$headers=self::pg_headers($p,$timeout);$ssl=(bool)$p['verify_tls'];
        $caps=[
            'target_release'=>'5.3.0',
            'users_read'=>false,'users_id_api'=>false,'groups_read_simple'=>false,
            'groups_read_full'=>false,'hwid_limit'=>true,'rbac'=>false,'api_key_auth'=>($p['auth_mode']??'api_key')==='api_key',
        ];
        $health=self::req('GET',self::join_url($base,'/health'),[],null,$ssl,[],$timeout);
        $caps['health']=$health['code']<400;

        $users=self::req('GET',self::join_url($base,'/api/users?limit=1&offset=0'),$headers,null,$ssl,[],$timeout);
        if($users['code']===401)throw new RuntimeException('PasarGuard احراز هویت را رد کرد (401). API Key/JWT را بررسی کن.');
        if($users['code']===403)throw new RuntimeException('PasarGuard مجوز users.read را برای این API Key/Admin ندارد (403).');
        if($users['code']>=400)throw new RuntimeException('PasarGuard users.read ناموفق: HTTP '.$users['code']);
        $caps['users_read']=true;

        $rows=self::provider_list_rows($users['json'],['users']);
        $sampleId=0;
        foreach($rows as $row){if(is_array($row)&&isset($row['id'])&&is_numeric($row['id'])){$sampleId=(int)$row['id'];break;}}
        if($sampleId>0){
            $byId=self::req('GET',self::join_url($base,'/api/user/by-id/'.$sampleId),$headers,null,$ssl,[],$timeout);
            if($byId['code']<400)$caps['users_id_api']=true;
            elseif(!in_array($byId['code'],[403,404,405],true))throw new RuntimeException('PasarGuard by-id probe ناموفق: HTTP '.$byId['code']);
        }

        $simpleUrl=self::join_url($base,'/api/groups/simple?limit=1');
        if(class_exists('BlueVPN_Error_Monitor'))BlueVPN_Error_Monitor::expect_http_status_once($simpleUrl,[403,404,405]);
        $simple=self::req('GET',$simpleUrl,$headers,null,$ssl,[],$timeout);
        if($simple['code']<400)$caps['groups_read_simple']=true;
        elseif($simple['code']===403)$caps['rbac']=true;
        elseif(!in_array($simple['code'],[404,405],true))throw new RuntimeException('PasarGuard groups.read_simple probe ناموفق: HTTP '.$simple['code']);

        // Full groups is optional in v5 RBAC. Never fail a healthy operator key only
        // because it intentionally lacks groups.read.
        $fullUrl=self::join_url($base,'/api/groups?limit=1');
        if(class_exists('BlueVPN_Error_Monitor'))BlueVPN_Error_Monitor::expect_http_status_once($fullUrl,[403,404,405]);
        $full=self::req('GET',$fullUrl,$headers,null,$ssl,[],$timeout);
        if($full['code']<400)$caps['groups_read_full']=true;
        elseif($full['code']===403)$caps['rbac']=true;

        $contract=$caps['users_id_api']?'v5-id-rbac':'legacy-username';
        if($caps['users_id_api']&&!$caps['groups_read_simple']){
            throw new RuntimeException('PasarGuard v5 شناسایی شد ولی API Key مجوز groups.read_simple ندارد.');
        }
        return ['ok'=>true,'contract'=>$contract,'capabilities'=>$caps,'sample_user_id'=>$sampleId];
    }

    public static function test(string $provider,int $id): array {
        if($provider==='hiddify')return class_exists('BlueVPN_Hiddify')?BlueVPN_Hiddify::test($id):['ok'=>false,'message'=>'Adapter Hiddify بارگذاری نشده است.'];
        if($provider==='threexui')return class_exists('BlueVPN_ThreeXUI')?BlueVPN_ThreeXUI::test($id):['ok'=>false,'message'=>'Adapter 3x-ui بارگذاری نشده است.'];
        try{
            $p=self::panel($provider,$id);if(!$p)throw new RuntimeException('پنل پیدا نشد.');
            $base=(string)$p['base_url'];$ssl=(bool)$p['verify_tls'];
            if($provider==='pasarguard'){
                $probe=self::pg_probe_capabilities($p,12);$ok=!empty($probe['ok']);
                $groups=self::pg_active_group_ids($p,[],12);
                $contract=(string)($probe['contract']??'legacy-username');
                $msg='PasarGuard '.($contract==='v5-id-rbac'?'v5 ID/RBAC':'Legacy').' آماده است؛ '.count($groups).' گروه قابل استفاده.';
                global $wpdb;
                $wpdb->update(BlueVPN_DB::table('pasarguard_panels'),[
                    'api_version'=>$contract==='v5-id-rbac'?'5.x':'legacy',
                    'api_contract'=>$contract,
                    'capabilities_json'=>BlueVPN_Utils::json_encode((array)($probe['capabilities']??[])),
                    'last_sync_at'=>BlueVPN_Utils::now_mysql(),'updated_at'=>BlueVPN_Utils::now_mysql(),
                ],['id'=>$id]);
            }elseif($provider==='marzban'){
                $h=self::mz_headers($p);$r=self::req('GET',self::join_url($base,'/api/admin'),$h,null,$ssl);
                $ok=$r['code']===200;$msg=$ok?'ورود مدیر Marzban موفق بود.':'HTTP '.$r['code'].' '.mb_substr($r['body'],0,220);
                if($ok){[$px,$ib]=self::mz_access($p);$count=0;foreach($ib as $tags)$count+=is_array($tags)?count($tags):0;$msg.=' '.$count.' Inbound فعال برای تخصیص خودکار پیدا شد.';}
            }else{
                if(($p['auth_mode']??'manual')==='manual'){$ok=true;$msg='GuardCore در حالت دستی فعال است.';}
                else{
                    $h=self::gc_headers($p);$r=self::req('GET',self::join_url($base,'/api/admins/current'),$h,null,$ssl);$ok=$r['code']<400;$msg=$ok?'ورود مدیر GuardCore موفق بود.':'HTTP '.$r['code'].' '.mb_substr($r['body'],0,220);
                    if($ok){$catalog=self::guardcore_catalog($id,true);if(!empty($catalog['ok'])){$msg.=' API '.($catalog['version']??'').' • '.count((array)($catalog['services']??[])).' Service • '.count((array)($catalog['nodes']??[])).' Node دریافت شد.';}else{$ok=false;$msg.=' همگام‌سازی Catalog ناموفق: '.($catalog['message']??'');}}
                }
            }
            self::store_test($provider,$id,$ok,$msg);return ['ok'=>$ok,'message'=>$msg];
        }catch(Throwable $e){self::store_test($provider,$id,false,$e->getMessage());return ['ok'=>false,'message'=>$e->getMessage()];}
    }
    private static function store_test(string $provider,int $id,bool $ok,string $msg): void {
        global $wpdb;$map=['pasarguard'=>'pasarguard_panels','marzban'=>'marzban_panels','guardcore'=>'guardcore_panels','hiddify'=>'hiddify_panels','threexui'=>'threexui_panels'];if(!isset($map[$provider]))return;
        $wpdb->update(BlueVPN_DB::table($map[$provider]),['last_test_ok'=>$ok?1:0,'last_test_message'=>mb_substr($msg,0,1800),'last_test_at'=>BlueVPN_Utils::now_mysql()],['id'=>$id]);
    }
    private static function pg_user(array $p,string $username,int $timeout=25,int $remoteId=0): ?array {
        $base=(string)$p['base_url'];$headers=self::pg_headers($p,$timeout);$ssl=(bool)$p['verify_tls'];
        if($remoteId>0){
            $url=self::join_url($base,'/api/user/by-id/'.$remoteId);
            if(class_exists('BlueVPN_Error_Monitor'))BlueVPN_Error_Monitor::expect_http_status_once($url,[403,404,405]);
            $r=self::req('GET',$url,$headers,null,$ssl,[],$timeout);
            if($r['code']<400)return $r['json'];
            if(!in_array($r['code'],[403,404,405],true))throw new RuntimeException('خواندن کاربر PasarGuard با ID ناموفق: HTTP '.$r['code']);
        }
        $url=self::join_url($base,'/api/user/by-username/'.rawurlencode($username));
        if(class_exists('BlueVPN_Error_Monitor'))BlueVPN_Error_Monitor::expect_http_status_once($url,[404,405]);
        $r=self::req('GET',$url,$headers,null,$ssl,[],$timeout);
        if($r['code']===404)return null;
        if($r['code']===405){
            $legacy=self::join_url($base,'/api/user/'.rawurlencode($username));
            $r=self::req('GET',$legacy,$headers,null,$ssl,[],$timeout);
        }
        if($r['code']===404)return null;
        if($r['code']>=400)throw new RuntimeException('خواندن کاربر PasarGuard ناموفق: HTTP '.$r['code']);
        return $r['json'];
    }

    private static function pg_update_user(array $p,string $username,array $payload,int $remoteId=0,int $timeout=25): array {
        $base=(string)$p['base_url'];$headers=self::pg_headers($p,$timeout);$ssl=(bool)$p['verify_tls'];
        if($remoteId>0){
            $r=self::req('PUT',self::join_url($base,'/api/user/by-id/'.$remoteId),$headers,$payload,$ssl,[],$timeout);
            if($r['code']<400)return $r;
            if(!in_array($r['code'],[403,404,405],true))return $r;
        }
        $r=self::req('PUT',self::join_url($base,'/api/user/by-username/'.rawurlencode($username)),$headers,$payload,$ssl,[],$timeout);
        if(in_array($r['code'],[404,405],true))$r=self::req('PUT',self::join_url($base,'/api/user/'.rawurlencode($username)),$headers,$payload,$ssl,[],$timeout);
        return $r;
    }

    private static function pg_hwid_limit(int $deviceLimit): int {
        return max(1,$deviceLimit);
    }
    private static function mz_user(array $p,string $username,int $timeout=25): ?array {
        $r=self::req('GET',self::join_url((string)$p['base_url'],'/api/user/'.rawurlencode($username)),self::mz_headers($p,$timeout),null,(bool)$p['verify_tls'],[],$timeout);
        if($r['code']===404)return null;if($r['code']>=400)throw new RuntimeException('خواندن کاربر Marzban ناموفق: HTTP '.$r['code']);return $r['json'];
    }
    private static function provider_list_rows(array $payload,array $keys=[]): array {
        $isList=static function(array $v): bool { if(function_exists('array_is_list'))return array_is_list($v);if(!$v)return true;return array_keys($v)===range(0,count($v)-1); };
        if($isList($payload))return $payload;
        foreach($keys as $key){$candidate=$payload[$key]??null;if(is_array($candidate)&&$isList($candidate))return $candidate;}
        foreach(['data','items','results','groups','inbounds'] as $key){$candidate=$payload[$key]??null;if(is_array($candidate)&&$isList($candidate))return $candidate;}
        return [];
    }
    /**
     * Return a normalized, admin-safe access catalog for provider selection.
     * Secrets and raw provider payloads are deliberately not returned.
     */
    public static function access_catalog(string $provider,int $id,int $timeout=12): array {
        $provider=sanitize_key($provider);$p=self::panel($provider,$id);
        if(!$p||!(int)($p['active']??0))throw new RuntimeException('Provider فعال پیدا نشد.');
        if($provider==='pasarguard'){
            $headers=self::pg_headers($p,$timeout);$base=(string)$p['base_url'];$ssl=(bool)$p['verify_tls'];$last='';
            foreach(['/api/groups/simple','/api/groups'] as $path){
                $url=self::join_url($base,$path);
                if(class_exists('BlueVPN_Error_Monitor')) BlueVPN_Error_Monitor::expect_http_status_once($url,[403,404]);
                $r=self::req('GET',$url,$headers,null,$ssl,[],$timeout);
                if($r['code']===401){
                    if(class_exists('BlueVPN_Error_Monitor')) BlueVPN_Error_Monitor::report('provider','pasarguard','warning','PASARGUARD_AUTH_FAILED','احراز هویت PasarGuard رد شد؛ کلید API یا مجوز پنل را بررسی کنید.',['panel_id'=>$id,'path'=>$path]);
                    throw new RuntimeException('احراز هویت PasarGuard ناموفق است (HTTP 401). کلید API/مجوز پنل را بررسی کنید.');
                }
                if($r['code']>=400){$last='HTTP '.$r['code'];continue;}
                $rows=self::provider_list_rows($r['json'],['groups']);$items=[];
                foreach($rows as $row){
                    if(!is_array($row))continue;$gid=(int)($row['id']??0);if($gid<=0)continue;
                    if(array_key_exists('is_disabled',$row)&&BlueVPN_Utils::boolish($row['is_disabled']))continue;
                    if(isset($row['status'])&&in_array(strtolower((string)$row['status']),['disabled','inactive','deleted'],true))continue;
                    $name=trim((string)($row['name']??$row['title']??$row['remark']??('Group #'.$gid)));
                    $items[]=['value'=>(string)$gid,'label'=>$name!==''?$name:('Group #'.$gid),'meta'=>'ID '.$gid];
                }
                if($items)return ['provider'=>'pasarguard','panel_id'=>$id,'kind'=>'group','items'=>$items];
                $last='هیچ گروه فعالی دریافت نشد.';
            }
            throw new RuntimeException('دریافت گروه‌های PasarGuard ناموفق'.($last!==''?': '.$last:''));
        }
        if($provider==='marzban'){
            $r=self::req('GET',self::join_url((string)$p['base_url'],'/api/inbounds'),self::mz_headers($p,$timeout),null,(bool)$p['verify_tls'],[],$timeout);
            if($r['code']>=400)throw new RuntimeException('دریافت Inboundهای Marzban ناموفق: HTTP '.$r['code']);
            $raw=$r['json'];if(isset($raw['inbounds'])&&is_array($raw['inbounds']))$raw=$raw['inbounds'];$items=[];
            foreach(['vless','vmess','trojan','shadowsocks'] as $proto){
                $rows=$raw[$proto]??[];if(!is_array($rows))continue;
                foreach($rows as $row){
                    if(is_array($row)){
                        if((array_key_exists('is_disabled',$row)&&BlueVPN_Utils::boolish($row['is_disabled']))||(isset($row['status'])&&in_array(strtolower((string)$row['status']),['disabled','inactive'],true)))continue;
                        $tag=trim((string)($row['tag']??$row['remark']??$row['name']??''));
                    }else{$tag=is_string($row)?trim($row):'';}
                    if($tag==='')continue;$value=$proto.'|'.$tag;
                    $items[$value]=['value'=>$value,'label'=>$tag,'meta'=>strtoupper($proto)];
                }
            }
            if(!$items)throw new RuntimeException('هیچ Inbound فعال Marzban پیدا نشد.');
            return ['provider'=>'marzban','panel_id'=>$id,'kind'=>'inbound','items'=>array_values($items)];
        }
        if($provider==='threexui'){
            if(!class_exists('BlueVPN_ThreeXUI'))throw new RuntimeException('Adapter 3x-ui بارگذاری نشده است.');
            $items=[];foreach(BlueVPN_ThreeXUI::inbounds($id) as $row){$iid=(int)($row['id']??0);if($iid<=0)continue;$items[]=['value'=>(string)$iid,'label'=>(string)($row['remark']??('Inbound #'.$iid)),'meta'=>strtoupper((string)($row['protocol']??'')).((int)($row['port']??0)>0?' :'.(int)$row['port']:'')];}
            if(!$items)throw new RuntimeException('هیچ Inbound فعال 3x-ui پیدا نشد.');
            return ['provider'=>'threexui','panel_id'=>$id,'kind'=>'inbound_id','items'=>$items];
        }
        if($provider==='hiddify'){
            if(!class_exists('BlueVPN_Hiddify'))throw new RuntimeException('Adapter Hiddify بارگذاری نشده است.');
            $catalog=BlueVPN_Hiddify::catalog($id,false);
            return ['provider'=>'hiddify','panel_id'=>$id,'kind'=>'automatic','items'=>[],'stats'=>(array)($catalog['stats']??[])];
        }
        throw new RuntimeException('Catalog برای این Provider پشتیبانی نمی‌شود.');
    }

    private static function normalize_marzban_selection(array $selection): array {
        $out=[];
        foreach($selection as $proto=>$tags){
            $proto=strtolower(trim((string)$proto));if(!in_array($proto,['vless','vmess','trojan','shadowsocks'],true)||!is_array($tags))continue;
            foreach($tags as $tag){$tag=trim((string)$tag);if($tag!==''&&!in_array($tag,$out[$proto]??[],true))$out[$proto][]=$tag;}
        }
        return $out;
    }

    private static function pg_active_group_ids(array $p,array $fallback=[],int $timeout=25): array {
        $fallback=array_values(array_unique(array_filter(array_map('intval',$fallback),static fn($id)=>$id>0)));
        $headers=self::pg_headers($p,$timeout);$base=(string)$p['base_url'];$ssl=(bool)$p['verify_tls'];$last='';
        foreach(['/api/groups/simple','/api/groups'] as $path){
            $url=self::join_url($base,$path);
            if(class_exists('BlueVPN_Error_Monitor')) BlueVPN_Error_Monitor::expect_http_status_once($url,[403,404]);
            try{$r=self::req('GET',$url,$headers,null,$ssl,[],$timeout);}catch(Throwable $e){$last=$e->getMessage();continue;}
            if($r['code']===401){
                if(class_exists('BlueVPN_Error_Monitor')) BlueVPN_Error_Monitor::report('provider','pasarguard','warning','PASARGUARD_AUTH_FAILED','احراز هویت PasarGuard رد شد؛ کلید API یا مجوز پنل را بررسی کنید.',['panel_id'=>(int)($p['id']??0),'path'=>$path]);
                throw new RuntimeException('احراز هویت PasarGuard ناموفق است (HTTP 401). کلید API/مجوز پنل را بررسی کنید.');
            }
            if($r['code']>=400){$last='HTTP '.$r['code'].' '.mb_substr($r['body'],0,180);continue;}
            $rows=self::provider_list_rows($r['json'],['groups']);$ids=[];
            foreach($rows as $row){
                if(!is_array($row))continue;$id=(int)($row['id']??0);if($id<=0)continue;
                // Full endpoint exposes is_disabled. The simple endpoint only returns id/name;
                // assigning a disabled group is harmless because PasarGuard excludes disabled groups
                // from access calculations, while enabled groups grant every linked inbound/host.
                if(array_key_exists('is_disabled',$row)&&BlueVPN_Utils::boolish($row['is_disabled']))continue;
                if(isset($row['status'])&&in_array(strtolower((string)$row['status']),['disabled','inactive','deleted'],true))continue;
                $ids[$id]=$id;
            }
            if($ids){
                if($fallback){$chosen=array_values(array_intersect($fallback,array_values($ids)));if($chosen)return $chosen;throw new RuntimeException('گروه‌های انتخاب‌شده PasarGuard دیگر فعال/موجود نیستند. دوباره لیست گروه‌ها را دریافت و انتخاب کن.');}
                return array_values($ids);
            }
            $last='هیچ گروه قابل استفاده‌ای از '.$path.' دریافت نشد.';
        }
        if($fallback)return $fallback;
        throw new RuntimeException('دریافت گروه‌های فعال PasarGuard ناموفق بود'.($last!==''?': '.$last:''));
    }
    private static function pg_proxy_settings(array $p): array {
        $raw=BlueVPN_Utils::json_decode_array((string)($p['proxy_settings_json']??''),[]);$out=[];
        foreach($raw as $proto=>$settings){
            $proto=trim((string)$proto);if($proto===''||!is_array($settings)||!$settings)continue;
            // PasarGuard v5 removed per-user vless.flow; flow belongs to the core inbound.
            if(strtolower($proto)==='vless')unset($settings['flow']);
            if(!$settings)continue;
            // PasarGuard proxy settings are dictionaries/objects. Legacy [] placeholders trigger
            // FastAPI/Pydantic 422 ("Input should be a valid dictionary or object").
            $out[$proto]=$settings;
        }
        return $out;
    }
    private static function mz_normalize_proxies(array $proxies,array $inbounds): array {
        $out=[];
        foreach($inbounds as $proto=>$tags){
            if(!is_array($tags)||!$tags)continue;$current=$proxies[$proto]??null;
            if(is_array($current)&&$current){$out[$proto]=$current;}else{$out[$proto]=new stdClass();}
        }
        return $out;
    }
    private static function mz_access(array $p,int $timeout=25,array $selected=[]): array {
        $selected=self::normalize_marzban_selection($selected);
        $cachedProxies=BlueVPN_Utils::json_decode_array((string)($p['proxies_json']??''),[]);$cachedInbounds=BlueVPN_Utils::json_decode_array((string)($p['inbounds_json']??''),[]);
        try{
            // Always prefer the live inbound catalog. This makes newly enabled Marzban inbounds
            // available to BlueVPN users automatically instead of pinning users to stale JSON.
            $r=self::req('GET',self::join_url((string)$p['base_url'],'/api/inbounds'),self::mz_headers($p,$timeout),null,(bool)$p['verify_tls'],[],$timeout);
            if($r['code']>=400)throw new RuntimeException('HTTP '.$r['code'].' '.mb_substr($r['body'],0,180));
            $raw=$r['json'];if(isset($raw['inbounds'])&&is_array($raw['inbounds']))$raw=$raw['inbounds'];
            $proxies=[];$inbounds=[];
            foreach(['vless','vmess','trojan','shadowsocks'] as $proto){
                $items=$raw[$proto]??[];if(!is_array($items)||!$items)continue;$tags=[];
                foreach($items as $item){
                    if(is_array($item)){
                        if((array_key_exists('is_disabled',$item)&&BlueVPN_Utils::boolish($item['is_disabled']))||isset($item['status'])&&in_array(strtolower((string)$item['status']),['disabled','inactive'],true))continue;
                        $tag=(string)($item['tag']??$item['remark']??$item['name']??'');
                    }else{$tag=is_string($item)?$item:'';}
                    if($tag!==''&&!in_array($tag,$tags,true))$tags[]=$tag;
                }
                if($selected){$allowed=$selected[$proto]??[];$tags=array_values(array_filter($tags,static fn($tag)=>in_array($tag,$allowed,true)));}
                if($tags){$inbounds[$proto]=$tags;$proxies[$proto]=new stdClass();}
            }
            if($inbounds)return [self::mz_normalize_proxies($proxies,$inbounds),$inbounds];
            throw new RuntimeException('هیچ Inbound فعال قابل استفاده‌ای پیدا نشد.');
        }catch(Throwable $e){
            if($cachedInbounds){
                if($selected){foreach($cachedInbounds as $proto=>$tags){$allowed=$selected[$proto]??[];$cachedInbounds[$proto]=array_values(array_filter(is_array($tags)?$tags:[],static fn($tag)=>in_array((string)$tag,$allowed,true)));if(!$cachedInbounds[$proto])unset($cachedInbounds[$proto]);}}
                $normalized=self::mz_normalize_proxies($cachedProxies,$cachedInbounds);if($normalized)return [$normalized,$cachedInbounds];
            }
            throw new RuntimeException('دریافت Inboundهای فعال Marzban ناموفق: '.$e->getMessage());
        }
    }
    private static function username(array $c,string $prefix): string {
        $field=$prefix==='pg'?'pg_username':($prefix==='mz'?'marzban_username':'guardcore_username');if(!empty($c[$field]))return (string)$c[$field];
        $seed=(string)($c['email']?:$c['phone']?:$c['id']);return substr('bv_'.$c['id'].'_'.substr(sha1($prefix.':'.$seed),0,9),0,32);
    }
    private static function external_username(array $c,string $prefix): string {
        $seed=(string)($c['email']?:$c['phone']?:$c['id']);
        return substr('bv_'.$c['id'].'_'.substr(sha1($prefix.':'.$seed),0,12),0,48);
    }
    private static function hiddify_uuid(array $c,int $panelId): string {
        $seed='bluevpn:hiddify:'.$panelId.':'.(string)($c['id']??0).':'.(string)($c['email']??$c['phone']??'');
        $h=md5($seed);return substr($h,0,8).'-'.substr($h,8,4).'-4'.substr($h,13,3).'-a'.substr($h,17,3).'-'.substr($h,20,12);
    }
    private static function target_expiry(array $c,array $plan,bool $extend): ?string {
        $current=self::remote_expiry($c['subscription_expire']??null);
        if(!$extend&&$current)return $current;
        $days=(int)($plan['duration_days']??0);if($days<=0)return $current;
        $base=time();if($extend&&$current){$t=strtotime($current.' UTC');if($t&&$t>$base)$base=$t;}
        return gmdate('Y-m-d H:i:s',$base+$days*DAY_IN_SECONDS);
    }
    /** Calculate one intentional purchase/manual-renewal entitlement extension. */
    public static function next_entitlement_expiry(int $customerId,int $planId): ?string {
        global $wpdb;$ct=BlueVPN_DB::table('customers');$pt=BlueVPN_DB::table('plans');
        $c=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$ct} WHERE id=%d LIMIT 1",$customerId),ARRAY_A);
        $plan=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$pt} WHERE id=%d AND deleted=0 LIMIT 1",$planId),ARRAY_A);
        if(!$c||!$plan)return null;
        return self::target_expiry($c,$plan,true);
    }
    private static function canonical_expiry(array $c,array $plan,?string $explicit=null): ?string {
        if($explicit!==null&&trim($explicit)!=='')return self::remote_expiry($explicit);
        $stored=self::target_expiry($c,$plan,false);
        // First-ever activation may legitimately have no stored entitlement yet.
        return $stored?:self::target_expiry($c,$plan,true);
    }
    private static function valid_provision_result(string $raw): bool {
        $raw=trim($raw);if($raw==='')return false;$r=BlueVPN_Utils::json_decode_array($raw,[]);if(!$r)return false;
        return !str_contains((string)($r['message']??''),'ذخیره entitlement کاربر در MySQL ناموفق بود');
    }
    /** Persist every canonical expiry mutation so future repair never has to guess. */
    public static function record_entitlement_ledger(
        int $customerId,
        ?int $planId,
        string $source,
        string $sourceRef,
        string $eventType,
        bool $intentionalGrant,
        int $durationDays,
        ?string $beforeExpire,
        ?string $targetExpire,
        array $metadata=[]
    ): bool {
        if($customerId<=0)return false;
        global $wpdb;$t=BlueVPN_DB::table('entitlement_ledger');
        $source=substr(sanitize_key($source),0,40);$eventType=substr(sanitize_key($eventType),0,40);
        $sourceRef=substr(sanitize_text_field($sourceRef),0,160);
        if($source===''||$sourceRef===''||$eventType==='')return false;
        $before=self::remote_expiry($beforeExpire);$target=self::remote_expiry($targetExpire);
        $existing=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE source=%s AND source_ref=%s AND event_type=%s LIMIT 1",$source,$sourceRef,$eventType));
        if($existing>0)return true;
        $ok=$wpdb->insert($t,[
            'customer_id'=>$customerId,'plan_id'=>$planId&&$planId>0?$planId:null,'source'=>$source,'source_ref'=>$sourceRef,'event_type'=>$eventType,
            'intentional_grant'=>$intentionalGrant?1:0,'duration_days'=>max(0,$durationDays),'before_expire'=>$before,'target_expire'=>$target,
            'metadata_json'=>BlueVPN_Utils::json_encode($metadata),'created_at'=>BlueVPN_Utils::now_mysql(),
        ]);
        if($ok!==false)return true;
        return (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE source=%s AND source_ref=%s AND event_type=%s LIMIT 1",$source,$sourceRef,$eventType))>0;
    }

    /**
     * 4.17.5 repair: old (<=4.17.3) provision_customer() extended the plan on
     * every invocation. Retry/reconcile triggers were never entitlement grants,
     * so each legacy successful non-grant attempt represents one provable extra
     * duration. 4.17.4 repaired only duplicate attempts for the same order and
     * therefore missed the first native_cutover/admin_retry inflation.
     */
    public static function repair_legacy_non_grant_expiry_inflation(): array {
        $done=get_option(self::EXPIRY_NON_GRANT_REPAIR_OPTION,[]);if(is_array($done)&&!empty($done['completed']))return $done;
        global $wpdb;$at=BlueVPN_DB::table('provisioning_attempts');$ot=BlueVPN_DB::table('orders');$pt=BlueVPN_DB::table('plans');$ct=BlueVPN_DB::table('customers');
        $already=[];$old=get_option(self::EXPIRY_REPAIR_OPTION,[]);
        if(is_array($old))foreach((array)($old['details']??[]) as $detail)foreach((array)($detail['evidence']??[]) as $ev){$aid=(int)($ev[1]??0);if($aid>0)$already[$aid]=true;}
        $rows=$wpdb->get_results("SELECT a.id,a.order_id,a.customer_id,a.plan_id,a.trigger_source,a.status,a.result_json,a.created_at,p.duration_days FROM {$at} a LEFT JOIN {$ot} o ON o.id=a.order_id LEFT JOIN {$pt} p ON p.id=COALESCE(a.plan_id,o.plan_id) WHERE a.customer_id IS NOT NULL AND a.trigger_source IN ('native_cutover_reconcile','admin_retry') ORDER BY a.customer_id ASC,a.id ASC",ARRAY_A)?:[];
        $inflation=[];$evidence=[];
        foreach($rows as $row){
            $aid=(int)$row['id'];if(isset($already[$aid]))continue;
            $raw=trim((string)($row['result_json']??''));if($raw===''||!self::valid_provision_result($raw))continue;
            $result=BlueVPN_Utils::json_decode_array($raw,[]);
            // New safe provision results are explicitly canonical and must never
            // be rolled back. Absence of this marker identifies legacy behavior.
            if((string)($result['expiry_source']??'')==='wordpress_mysql_entitlement')continue;
            $days=max(0,(int)($row['duration_days']??0));if($days<=0)continue;
            $cid=(int)$row['customer_id'];$inflation[$cid]=($inflation[$cid]??0)+$days;
            $evidence[$cid][]=[$aid,(string)($row['order_id']??''),(string)$row['trigger_source'],$days,(string)($result['canonical_expire']??'')];
        }
        $summary=['completed'=>true,'checked_customers'=>count($inflation),'repaired'=>0,'skipped'=>0,'details'=>[],'at'=>BlueVPN_Utils::iso_now()];
        foreach($inflation as $cid=>$days){
            $c=$wpdb->get_row($wpdb->prepare("SELECT id,plan_id,subscription_status,subscription_expire FROM {$ct} WHERE id=%d LIMIT 1",$cid),ARRAY_A);
            if(!$c||empty($c['subscription_expire'])||$days<=0){$summary['skipped']++;continue;}
            $current=strtotime((string)$c['subscription_expire'].' UTC');if(!$current){$summary['skipped']++;continue;}
            $candidate=$current-$days*DAY_IN_SECONDS;
            // Never auto-expire an account. If historical evidence would cross
            // "now", keep the account untouched and surface it for manual review.
            if((string)$c['subscription_status']!=='active'||$candidate<=time()){
                $summary['skipped']++;$summary['details'][]=['customer_id'=>$cid,'removed_days_candidate'=>$days,'before'=>(string)$c['subscription_expire'],'action'=>'manual_review','evidence'=>$evidence[$cid]??[]];continue;
            }
            $target=gmdate('Y-m-d H:i:s',$candidate);
            $ok=$wpdb->update($ct,['subscription_expire'=>$target,'last_sync_at'=>null],['id'=>$cid]);if($ok===false){$summary['skipped']++;continue;}
            self::record_entitlement_ledger($cid,(int)($c['plan_id']??0),'repair','legacy_non_grant_4175_customer_'.$cid,'legacy_non_grant_repair',false,$days,(string)$c['subscription_expire'],$target,['evidence'=>$evidence[$cid]??[]]);
            $summary['repaired']++;$summary['details'][]=['customer_id'=>$cid,'removed_days'=>$days,'before'=>(string)$c['subscription_expire'],'after'=>$target,'evidence'=>$evidence[$cid]??[]];
            self::request_background_sync($cid);
        }
        update_option(self::EXPIRY_NON_GRANT_REPAIR_OPTION,$summary,false);
        if(class_exists('BlueVPN_Error_Monitor')){
            if($summary['repaired']>0)BlueVPN_Error_Monitor::report('entitlement','expiry_repair','notice','SUBSCRIPTION_EXPIRY_LEGACY_REPAIR_4175',$summary['repaired'].' اشتراک که توسط Retry/Reconcile قدیمی بیش از حد تمدید شده بود به تاریخ صحیح برگردانده شد.',$summary);
            if($summary['skipped']>0)BlueVPN_Error_Monitor::report('entitlement','expiry_repair','warning','SUBSCRIPTION_EXPIRY_REPAIR_REVIEW_REQUIRED',$summary['skipped'].' اشتراک دارای شواهد تمدید اضافی است اما برای جلوگیری از کاهش اشتباه نیازمند بررسی دستی است.',$summary);
        }
        return $summary;
    }

    /**
     * One-time 4.17.4 repair for the historical retry inflation bug.
     * Every paid order may grant plan duration exactly once. Older retry/reconcile
     * calls invoked provision_customer() again and therefore added the same plan
     * duration repeatedly. We only subtract provable duplicate persisted attempts.
     */
    public static function repair_duplicate_provision_expiry_inflation(): array {
        $done=get_option(self::EXPIRY_REPAIR_OPTION,[]);if(is_array($done)&&!empty($done['completed']))return $done;
        global $wpdb;$at=BlueVPN_DB::table('provisioning_attempts');$ot=BlueVPN_DB::table('orders');$pt=BlueVPN_DB::table('plans');$ct=BlueVPN_DB::table('customers');
        $rows=$wpdb->get_results("SELECT a.id,a.order_id,a.customer_id,a.plan_id,a.trigger_source,a.result_json,a.created_at,p.duration_days FROM {$at} a LEFT JOIN {$ot} o ON o.id=a.order_id LEFT JOIN {$pt} p ON p.id=COALESCE(a.plan_id,o.plan_id) WHERE a.order_id IS NOT NULL AND a.customer_id IS NOT NULL ORDER BY a.order_id ASC,a.id ASC",ARRAY_A)?:[];
        $seen=[];$inflation=[];$evidence=[];
        foreach($rows as $row){
            $orderId=(string)$row['order_id'];if(!self::valid_provision_result((string)($row['result_json']??'')))continue;
            if(empty($seen[$orderId])){$seen[$orderId]=1;continue;}
            $days=max(0,(int)($row['duration_days']??0));if($days<=0)continue;
            $cid=(int)$row['customer_id'];$inflation[$cid]=($inflation[$cid]??0)+$days;
            $evidence[$cid][]=[$orderId,(int)$row['id'],(string)$row['trigger_source'],$days];
        }
        $summary=['completed'=>true,'checked_customers'=>count($inflation),'repaired'=>0,'skipped'=>0,'details'=>[],'at'=>BlueVPN_Utils::iso_now()];
        foreach($inflation as $cid=>$days){
            $c=$wpdb->get_row($wpdb->prepare("SELECT id,plan_id,subscription_status,subscription_expire FROM {$ct} WHERE id=%d LIMIT 1",$cid),ARRAY_A);
            if(!$c||empty($c['subscription_expire'])||$days<=0){$summary['skipped']++;continue;}
            $current=strtotime((string)$c['subscription_expire'].' UTC');if(!$current){$summary['skipped']++;continue;}
            $candidate=$current-$days*DAY_IN_SECONDS;
            // Automatic rollback is deliberately conservative: only a currently
            // active entitlement that remains active after removing duplicate days.
            if((string)$c['subscription_status']!=='active'||$candidate<=time()){$summary['skipped']++;$summary['details'][]=['customer_id'=>$cid,'days'=>$days,'action'=>'manual_review'];continue;}
            $target=gmdate('Y-m-d H:i:s',$candidate);
            $ok=$wpdb->update($ct,['subscription_expire'=>$target,'last_sync_at'=>null],['id'=>$cid]);
            if($ok===false){$summary['skipped']++;continue;}
            $summary['repaired']++;$summary['details'][]=['customer_id'=>$cid,'removed_days'=>$days,'before'=>(string)$c['subscription_expire'],'after'=>$target,'evidence'=>$evidence[$cid]??[]];
            self::request_background_sync($cid);
        }
        update_option(self::EXPIRY_REPAIR_OPTION,$summary,false);
        if($summary['repaired']>0&&class_exists('BlueVPN_Error_Monitor'))BlueVPN_Error_Monitor::report('entitlement','expiry_repair','notice','SUBSCRIPTION_EXPIRY_INFLATION_REPAIRED',$summary['repaired'].' اشتراک با تمدید تکراریِ قابل اثبات به تاریخ صحیح WordPress/MySQL برگردانده شد.',$summary);
        return $summary;
    }
    private static function remote_sub_url(array $remote,string $base=''): string {
        $vals=[];foreach(['subscription_url','sub_url','subscriptionUrl'] as $k)if(!empty($remote[$k]))$vals[]=$remote[$k];
        if(isset($remote['subscription'])){if(is_string($remote['subscription']))$vals[]=$remote['subscription'];elseif(is_array($remote['subscription']))foreach(['url','subscription_url','sub_url'] as $k)if(!empty($remote['subscription'][$k]))$vals[]=$remote['subscription'][$k];}
        foreach($vals as $v){$u=self::absolute_url($base,(string)$v);if($u!=='')return $u;}return '';
    }
    private static function remote_expiry($v): ?string {
        if($v===null||$v===''||$v===0||$v==='0')return null;if(is_numeric($v)){return gmdate('Y-m-d H:i:s',(int)$v);}try{$d=new DateTime((string)$v);$d->setTimezone(new DateTimeZone('UTC'));return $d->format('Y-m-d H:i:s');}catch(Throwable $e){return null;}
    }
    /**
     * Repair provider mappings without renewing or extending the customer's plan.
     *
     * This is deliberately separate from provision_customer(): a repair must use
     * the entitlement already stored in WordPress and must never add plan days,
     * reset usage, or turn an expired/free account into Premium.
     */
    private static function repair_customer_multi_routes(array $c,array $plan): array {
        global $wpdb;$customerId=(int)$c['id'];$planId=(int)$plan['id'];$ct=BlueVPN_DB::table('customers');
        $routes=self::plan_provider_routes($plan);
        $trafficMode=((string)($plan['traffic_mode']??'provider_reported'))==='gateway_metered'?'gateway_metered':'provider_reported';
        $manualEntries=class_exists('BlueVPN_Subscription_Sources')?BlueVPN_Subscription_Sources::active_entries_for_plan($planId):[];
        $shahrahEntries=array_values(array_filter($manualEntries,static fn($entry)=>(string)($entry['provider_type']??'')==='shahrah'));
        $staticEntries=array_values(array_filter($manualEntries,static fn($entry)=>(string)($entry['provider_type']??'')!=='shahrah'));

        $expire=!empty($c['subscription_expire'])?(string)$c['subscription_expire']:null;
        $total=max(0,(int)($c['data_limit_bytes']??0));if($total===0&&max(0,(int)($plan['data_limit_gb']??0))>0)$total=max(0,(int)($plan['data_limit_gb']??0))*1024*1024*1024;
        $quotaRoutes=count($routes['pasarguard'])+count($routes['marzban'])+count($routes['guardcore'])+count($routes['hiddify'])+count($routes['threexui']);
        $quota=($quotaRoutes>1&&($plan['multi_provider_quota_mode']??'split')==='split')?intdiv($total,max(1,$quotaRoutes)):$total;
        $providerQuota=$trafficMode==='gateway_metered'?0:$quota;$deviceLimit=max(1,(int)($c['device_limit']??$plan['device_limit']??1));

        $existingLinks=[];foreach(self::customer_provider_links($customerId) as $link)$existingLinks[(string)$link['route_key']]=$link;
        $created=0;$attached=0;$existing=0;$resynced=0;$errors=[];$details=[];$update=[];$desired=[];$legacyWritten=['pasarguard'=>false,'marzban'=>false,'guardcore'=>false];

        foreach($routes['shahrah'] as $route){
            $panelId=(int)$route['panel_id'];$slug=(string)$route['plan_slug'];$key=self::provider_route_key('shahrah',$route);$desired[]=$key;
            try{
                if(!class_exists('BlueVPN_Shahrah'))throw new RuntimeException('شاهراه بارگذاری نشده است.');
                $seed=(string)($c['email']?:$c['phone']?:$c['id']);$username=substr('bv_'.$c['id'].'_'.substr(sha1('shahrah-panel:'.$panelId.':'.$seed),0,9),0,32);
                $r=BlueVPN_Shahrah::repair_panel_customer($panelId,$customerId,$slug,$username);$action=(string)($r['action']??'existing');
                if($action==='created')$created++;elseif($action==='attached')$attached++;else$existing++;
                self::provider_link_upsert($customerId,$planId,'shahrah',$route,['username'=>$username,'remote_id'=>(string)($r['service_slug']??''),'status'=>'active','metadata'=>$route]);
                $details[$key]=$action;
            }catch(Throwable $e){$errors[]='شاهراه #'.$panelId.': '.$e->getMessage();$details[$key]='error';}
        }

        foreach($shahrahEntries as $entry){
            $sourceId=(int)($entry['id']??0);
            try{
                if(!class_exists('BlueVPN_Shahrah'))throw new RuntimeException('شاهراه بارگذاری نشده است.');
                $cfg=BlueVPN_Subscription_Sources::shahrah_credentials_from_entry($entry);if(($cfg['api_key']??'')===''||($cfg['plan_slug']??'')==='')throw new RuntimeException('کلید یا پلن منبع قدیمی شاهراه تنظیم نشده است.');
                $seed=(string)($c['email']?:$c['phone']?:$c['id']);$username=substr('bv_'.$c['id'].'_'.substr(sha1('shahrah:'.$seed),0,9),0,32);
                $r=BlueVPN_Shahrah::repair_source_customer($sourceId,$customerId,(string)$cfg['api_key'],(string)$cfg['plan_slug'],$username);
                $action=(string)($r['action']??'existing');if($action==='created')$created++;elseif($action==='attached')$attached++;else$existing++;$details['shahrah_source_'.$sourceId]=$action;
            }catch(Throwable $e){$errors[]='منبع شاهراه #'.$sourceId.': '.$e->getMessage();}
        }

        foreach($routes['pasarguard'] as $route){
            $panelId=(int)$route['panel_id'];$key=self::provider_route_key('pasarguard',$route);$desired[]=$key;
            try{
                $p=self::panel('pasarguard',$panelId);if(!$p||!(int)$p['active'])throw new RuntimeException('سرور فعال پیدا نشد.');
                $groups=self::pg_active_group_ids($p,(array)($route['group_ids']??[]),10);$u=self::username($c,'pg');
                $knownId=isset($existingLinks[$key])?max(0,(int)($existingLinks[$key]['remote_id']??0)):0;
                $remote=self::pg_user($p,$u,10,$knownId);
                if(!$remote){
                    $payload=['username'=>$u,'status'=>'active','expire'=>$expire?gmdate('Y-m-d\TH:i:s\Z',strtotime($expire.' UTC')):0,'data_limit'=>$providerQuota,'data_limit_reset_strategy'=>'no_reset','group_ids'=>$groups,'hwid_limit'=>self::pg_hwid_limit($deviceLimit),'note'=>'BlueVPN repair; customer '.$customerId];
                    $proxy=self::pg_proxy_settings($p);if($proxy)$payload['proxy_settings']=$proxy;
                    $r=self::req('POST',self::join_url((string)$p['base_url'],'/api/user'),self::pg_headers($p,10),$payload,(bool)$p['verify_tls'],[],12);
                    if($r['code']>=400)throw new RuntimeException('ساخت کاربر ناموفق: HTTP '.$r['code']);$remote=$r['json']?:self::pg_user($p,$u,10)?:[];$created++;$action='created';
                }else{
                    $r=self::pg_update_user($p,$u,['group_ids'=>$groups],max(0,(int)($remote['id']??0)),12);
                    if($r['code']>=400)throw new RuntimeException('همگام‌سازی دسترسی ناموفق: HTTP '.$r['code']);$remote=self::pg_user($p,$u,10,max(0,(int)($remote['id']??0)))?:$remote;
                    if(isset($existingLinks[$key])){$existing++;$action='existing_synced';}else{$attached++;$action='attached';}
                }
                $sub=self::remote_sub_url($remote,(string)$p['base_url']);self::provider_link_upsert($customerId,$planId,'pasarguard',$route,['username'=>$u,'remote_id'=>(string)($remote['id']??''),'subscription_url'=>$sub,'status'=>(string)($remote['status']??'active'),'metadata'=>$route]);
                if(!$legacyWritten['pasarguard']){$update['panel_id']=$panelId;$update['pg_username']=$u;$update['pasarguard_subscription_url']=$sub;$legacyWritten['pasarguard']=true;}$details[$key]=$action;
            }catch(Throwable $e){$errors[]='پاسارگاد #'.$panelId.': '.$e->getMessage();$details[$key]='error';}
        }

        foreach($routes['marzban'] as $route){
            $panelId=(int)$route['panel_id'];$key=self::provider_route_key('marzban',$route);$desired[]=$key;
            try{
                $p=self::panel('marzban',$panelId);if(!$p||!(int)$p['active'])throw new RuntimeException('سرور فعال پیدا نشد.');
                [$proxies,$inbounds]=self::mz_access($p,10,(array)($route['inbounds']??[]));$u=self::username($c,'mz');$remote=self::mz_user($p,$u,10);
                if(!$remote){
                    $payload=['username'=>$u,'status'=>'active','expire'=>$expire?strtotime($expire.' UTC'):0,'data_limit'=>$providerQuota,'data_limit_reset_strategy'=>'no_reset','proxies'=>$proxies,'inbounds'=>$inbounds,'note'=>'BlueVPN repair; customer '.$customerId];
                    $r=self::req('POST',self::join_url((string)$p['base_url'],'/api/user'),self::mz_headers($p,10),$payload,(bool)$p['verify_tls'],[],12);
                    if($r['code']>=400)throw new RuntimeException('ساخت کاربر ناموفق: HTTP '.$r['code']);$remote=self::mz_user($p,$u,10)?:$r['json'];$created++;$action='created';
                }else{
                    $r=self::req('PUT',self::join_url((string)$p['base_url'],'/api/user/'.rawurlencode($u)),self::mz_headers($p,10),['proxies'=>$proxies,'inbounds'=>$inbounds],(bool)$p['verify_tls'],[],12);
                    if($r['code']>=400)throw new RuntimeException('همگام‌سازی دسترسی ناموفق: HTTP '.$r['code']);$remote=self::mz_user($p,$u,10)?:$remote;
                    if(isset($existingLinks[$key])){$existing++;$action='existing_synced';}else{$attached++;$action='attached';}
                }
                $sub=self::remote_sub_url($remote,(string)$p['base_url']);self::provider_link_upsert($customerId,$planId,'marzban',$route,['username'=>$u,'remote_id'=>(string)($remote['id']??''),'subscription_url'=>$sub,'status'=>(string)($remote['status']??'active'),'metadata'=>$route]);
                if(!$legacyWritten['marzban']){$update['marzban_panel_id']=$panelId;$update['marzban_username']=$u;$update['marzban_subscription_url']=$sub;$update['marzban_status']=(string)($remote['status']??'active');$legacyWritten['marzban']=true;}$details[$key]=$action;
            }catch(Throwable $e){$errors[]='مرزبان #'.$panelId.': '.$e->getMessage();$details[$key]='error';}
        }

        foreach($routes['guardcore'] as $route){
            $panelId=(int)$route['panel_id'];$key=self::provider_route_key('guardcore',$route);$desired[]=$key;
            try{
                $p=self::panel('guardcore',$panelId);if(!$p||!(int)$p['active'])throw new RuntimeException('سرور فعال پیدا نشد.');$u=self::username($c,'gc');$global=trim((string)($p['global_subscription_url']??''));
                if(($p['auth_mode']??'manual')==='manual'){
                    if($global==='')throw new RuntimeException('نشانی اشتراک عمومی خالی است.');$remote=[];$sub=esc_url_raw($global);$status='active';
                    if(isset($existingLinks[$key])){$existing++;$action='existing';}else{$attached++;$action='attached';}
                }else{
                    $serviceIds=self::gc_route_service_ids($panelId,(array)($route['service_ids']??[]));$remote=self::gc_find_customer_subscription($p,$c,$u);
                    if(!$remote){$remote=self::gc_provision($p,$u,$expire,$providerQuota,$serviceIds,'BlueVPN repair; customer '.$customerId,null);$created++;$action='created';}
                    else{
                        $u=trim((string)($remote['username']??''))?:$u;$remoteServices=array_values(array_unique(array_map('intval',(array)($remote['service_ids']??[]))));sort($remoteServices);$expected=$serviceIds;sort($expected);
                        if($remoteServices!==$expected){$r=self::gc_request($p,'PUT','/api/subscriptions/'.rawurlencode($u),['service_ids'=>$serviceIds]);if($r['code']>=400)throw new RuntimeException('همگام‌سازی سرویس‌ها ناموفق: HTTP '.$r['code']);$remote=self::gc_user($p,$u)?:$remote;}
                        if(isset($existingLinks[$key])){$existing++;$action='existing_synced';}else{$attached++;$action='attached';}
                    }
                    $route['service_ids']=$serviceIds;$sub=(string)($remote['subscription_url']??'');$status=(string)($remote['status']??'active');
                }
                self::provider_link_upsert($customerId,$planId,'guardcore',$route,['username'=>$u,'remote_id'=>(string)($remote['id']??''),'subscription_url'=>$sub,'status'=>$status,'metadata'=>$route]);
                if(!$legacyWritten['guardcore']){$update['guardcore_panel_id']=$panelId;$update['guardcore_username']=$u;$update['guardcore_subscription_url']=$sub;$update['guardcore_status']=$status;$legacyWritten['guardcore']=true;}$details[$key]=$action;
            }catch(Throwable $e){$errors[]='گاردکور #'.$panelId.': '.$e->getMessage();$details[$key]='error';}
        }

        foreach($routes['hiddify'] as $route){
            $panelId=(int)$route['panel_id'];$key=self::provider_route_key('hiddify',$route);$desired[]=$key;
            try{
                if(!class_exists('BlueVPN_Hiddify'))throw new RuntimeException('Adapter Hiddify بارگذاری نشده است.');
                $uuid=self::hiddify_uuid($c,$panelId);$name=self::external_username($c,'hiddify');
                $before=BlueVPN_Hiddify::user($panelId,$uuid);
                $remote=BlueVPN_Hiddify::provision($panelId,$uuid,$name,$providerQuota,$expire,$deviceLimit,'BlueVPN repair; customer '.$customerId);
                if($before===null){$created++;$action='created';}
                elseif(isset($existingLinks[$key])){$existing++;$action='existing_synced';}
                else{$attached++;$action='attached_synced';}
                self::provider_link_upsert($customerId,$planId,'hiddify',$route,[
                    'username'=>$name,'remote_id'=>$uuid,'subscription_url'=>(string)($remote['subscription_url']??''),
                    'status'=>(string)($remote['status']??'active'),'used_traffic_bytes'=>(int)($remote['used_traffic_bytes']??0),
                    'remote_expire'=>$remote['expire']??$expire,'metadata'=>$route
                ]);
                $details[$key]=$action;
            }catch(Throwable $e){$errors[]='هیدیفای #'.$panelId.': '.$e->getMessage();$details[$key]='error';}
        }

        foreach($routes['threexui'] as $route){
            $panelId=(int)$route['panel_id'];$key=self::provider_route_key('threexui',$route);$desired[]=$key;
            try{
                if(!class_exists('BlueVPN_ThreeXUI'))throw new RuntimeException('Adapter 3x-ui بارگذاری نشده است.');
                $email=self::external_username($c,'3xui');$before=BlueVPN_ThreeXUI::client($panelId,$email);
                $remote=BlueVPN_ThreeXUI::provision($panelId,$email,$providerQuota,$expire,$deviceLimit,(array)($route['inbound_ids']??[]),'BlueVPN repair; customer '.$customerId);
                $route['inbound_ids']=(array)($remote['inbound_ids']??$route['inbound_ids']??[]);
                if($before===null){$created++;$action='created';}
                elseif(isset($existingLinks[$key])){$existing++;$action='existing_synced';}
                else{$attached++;$action='attached_synced';}
                self::provider_link_upsert($customerId,$planId,'threexui',$route,[
                    'username'=>$email,'remote_id'=>(string)($remote['remote_id']??$remote['sub_id']??$email),
                    'subscription_url'=>(string)($remote['subscription_url']??''),'status'=>(string)($remote['status']??'active'),
                    'used_traffic_bytes'=>(int)($remote['used_traffic_bytes']??0),'remote_expire'=>$remote['expire']??$expire,'metadata'=>$route
                ]);
                $details[$key]=$action;
            }catch(Throwable $e){$errors[]='3x-ui #'.$panelId.': '.$e->getMessage();$details[$key]='error';}
        }

        if($staticEntries){$resynced+=count($staticEntries);$details['static_sources']='snapshot_resync_queued';}
        if($trafficMode==='gateway_metered'){
            try{
                if(!class_exists('BlueVPN_Gateway')||!BlueVPN_Gateway::has_active_gateway())throw new RuntimeException('دروازه فعال و سالم وجود ندارد.');
                $sessions=BlueVPN_Gateway::ensure_customer_sessions($customerId);if(!$sessions)throw new RuntimeException('نشست قابل استفاده ساخته یا بازیابی نشد.');$resynced++;$details['gateway']='sessions_ensured';
            }catch(Throwable $e){$errors[]='دروازه: '.$e->getMessage();$details['gateway']='error';}
        }

        self::prune_customer_provider_links($customerId,$desired);
        $repaired=$created+$attached;
        if($repaired>0||$resynced>0||$update){
            if(empty($c['subscription_token']))$update['subscription_token']=BlueVPN_Utils::random_token(30);$token=(string)($update['subscription_token']??$c['subscription_token']??'');
            if($token!=='')$update['subscription_url']=home_url('/sub/'.$token);$update['last_sync_at']=BlueVPN_Utils::now_mysql();$update['last_sync_error']=implode(' | ',$errors);$wpdb->update($ct,$update,['id'=>$customerId]);
        }
        self::request_background_snapshot($customerId);
        $ok=!$errors;$message=$repaired||$resynced?'ترمیم چندمسیره انجام شد: '.$created.' ساخته شد، '.$attached.' اتصال بازیابی شد، '.$resynced.' مسیر همگام شد.':($existing&&$ok?'همه مسیرهای انتخاب‌شده سالم هستند.':($errors?implode(' | ',$errors):'نیازی به ترمیم نبود.'));
        return ['ok'=>$ok,'eligible'=>true,'multi_provider'=>true,'message'=>$message,'repaired'=>$repaired,'created'=>$created,'attached'=>$attached,'existing'=>$existing,'resynced'=>$resynced,'errors'=>$errors,'details'=>$details];
    }

    public static function repair_customer_missing_providers(int $customerId): array {
        global $wpdb;
        $ct=BlueVPN_DB::table('customers');$pt=BlueVPN_DB::table('plans');
        $empty=['repaired'=>0,'created'=>0,'attached'=>0,'existing'=>0,'resynced'=>0,'errors'=>[]];
        $c=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$ct} WHERE id=%d LIMIT 1",$customerId),ARRAY_A);
        if(!$c)return array_merge(['ok'=>false,'eligible'=>false,'message'=>'کاربر پیدا نشد.'],$empty,['errors'=>['کاربر پیدا نشد.']]);
        if(!(int)$c['active']||(string)$c['subscription_status']!=='active'||empty($c['plan_id']))return array_merge(['ok'=>true,'eligible'=>false,'message'=>'کاربر اشتراک فعال قابل ترمیم ندارد.'],$empty);
        if(!empty($c['subscription_expire'])){$expTs=strtotime((string)$c['subscription_expire'].' UTC');if($expTs&&$expTs<=time())return array_merge(['ok'=>true,'eligible'=>false,'message'=>'اشتراک کاربر منقضی شده و برای Provider ساخته نشد.'],$empty);}
        $plan=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$pt} WHERE id=%d AND deleted=0 LIMIT 1",(int)$c['plan_id']),ARRAY_A);
        if(!$plan)return array_merge(['ok'=>false,'eligible'=>false,'message'=>'پلن فعلی کاربر پیدا نشد.'],$empty,['errors'=>['پلن پیدا نشد.']]);

        if(trim((string)($plan['provider_routes_json']??''))!=='')return self::repair_customer_multi_routes($c,$plan);

        $trafficMode=(string)($plan['traffic_mode']??'provider_reported');
        $trafficMode=$trafficMode==='gateway_metered'?'gateway_metered':'provider_reported';
        $manualEntries=class_exists('BlueVPN_Subscription_Sources')?BlueVPN_Subscription_Sources::active_entries_for_plan((int)$c['plan_id']):[];
        $shahrahEntries=array_values(array_filter($manualEntries,static fn($entry)=>(string)($entry['provider_type']??'')==='shahrah'));
        $staticManualEntries=array_values(array_filter($manualEntries,static fn($entry)=>(string)($entry['provider_type']??'')!=='shahrah'));

        $pgId=(int)($plan['panel_id']??0);
        $mzId=(int)($plan['marzban_panel_id']??0);
        $shId=(int)($plan['shahrah_panel_id']??0);
        $shSlug=trim((string)($plan['shahrah_plan_slug']??''));
        $gcId=(int)($plan['guardcore_panel_id']??0);
        $hasExplicitManual=!empty($manualEntries)||$shId>0;

        if(!$hasExplicitManual){
            if($pgId<=0)$pgId=class_exists('BlueVPN_AI_Ops')?BlueVPN_AI_Ops::recommend_panel_id('pasarguard'):(int)$wpdb->get_var("SELECT id FROM ".BlueVPN_DB::table('pasarguard_panels')." WHERE active=1 ORDER BY id ASC LIMIT 1");
            if($mzId<=0)$mzId=class_exists('BlueVPN_AI_Ops')?BlueVPN_AI_Ops::recommend_panel_id('marzban'):(int)$wpdb->get_var("SELECT id FROM ".BlueVPN_DB::table('marzban_panels')." WHERE active=1 ORDER BY id ASC LIMIT 1");
            if($gcId<=0)$gcId=(int)$wpdb->get_var("SELECT id FROM ".BlueVPN_DB::table('guardcore_panels')." WHERE active=1 AND auth_mode='manual' AND global_subscription_url IS NOT NULL AND TRIM(global_subscription_url)<>'' ORDER BY id ASC LIMIT 1");
        }

        $expectsPg=$pgId>0;
        $expectsMz=$mzId>0;
        $expectsSh=$shId>0;
        $expectsLegacySh=!empty($shahrahEntries);
        $expectsGc=$gcId>0;
        $expectsStatic=!empty($staticManualEntries);
        $expectsGateway=$trafficMode==='gateway_metered';
        if(!$expectsPg&&!$expectsMz&&!$expectsSh&&!$expectsLegacySh&&!$expectsGc&&!$expectsStatic&&!$expectsGateway){
            return array_merge(['ok'=>true,'eligible'=>false,'message'=>'هیچ Provider، Source پولی یا Gateway قابل ترمیم وجود ندارد.'],$empty);
        }

        $expire=!empty($c['subscription_expire'])?(string)$c['subscription_expire']:null;
        $total=max(0,(int)($c['data_limit_bytes']??0));
        if($total===0&&max(0,(int)($plan['data_limit_gb']??0))>0)$total=max(0,(int)($plan['data_limit_gb']??0))*1024*1024*1024;
        $providerCount=count(array_filter([$expectsPg,$expectsMz,$expectsGc]));
        $quota=($providerCount>1&&($plan['multi_provider_quota_mode']??'split')==='split')?intdiv($total,max(1,$providerCount)):$total;
        $providerQuota=$trafficMode==='gateway_metered'?0:$quota;
        $deviceLimit=max(1,(int)($c['device_limit']??$plan['device_limit']??1));

        $update=[];$created=0;$attached=0;$existing=0;$resynced=0;$errors=[];$details=[];

        if($expectsSh){
            try{
                if(!class_exists('BlueVPN_Shahrah'))throw new RuntimeException('Provider شاهراه بارگذاری نشده است.');
                if($shSlug==='')throw new RuntimeException('پلن Shahrah برای این پلن BlueVPN انتخاب نشده است.');
                $seed=(string)($c['email']?:$c['phone']?:$c['id']);
                $username=substr('bv_'.$c['id'].'_'.substr(sha1('shahrah-panel:'.$seed),0,9),0,32);
                $r=BlueVPN_Shahrah::repair_panel_customer($shId,$customerId,$shSlug,$username);
                $action=(string)($r['action']??'existing');
                if($action==='created')$created++;
                elseif($action==='attached')$attached++;
                else$existing++;
                $details['shahrah']=$action;
            }catch(Throwable $e){$errors[]='Shahrah: '.$e->getMessage();$details['shahrah']='error';}
        }

        foreach($shahrahEntries as $entry){
            $sourceId=(int)($entry['id']??0);
            try{
                if(!class_exists('BlueVPN_Shahrah'))throw new RuntimeException('Provider شاهراه بارگذاری نشده است.');
                $cfg=BlueVPN_Subscription_Sources::shahrah_credentials_from_entry($entry);
                if(($cfg['api_key']??'')===''||($cfg['plan_slug']??'')==='')throw new RuntimeException('API KEY یا planSlug شاهراه برای Source قدیمی تنظیم نشده است.');
                $seed=(string)($c['email']?:$c['phone']?:$c['id']);
                $username=substr('bv_'.$c['id'].'_'.substr(sha1('shahrah:'.$seed),0,9),0,32);
                $r=BlueVPN_Shahrah::repair_source_customer($sourceId,$customerId,(string)$cfg['api_key'],(string)$cfg['plan_slug'],$username);
                $action=(string)($r['action']??'existing');
                if($action==='created')$created++;
                elseif($action==='attached')$attached++;
                else$existing++;
                $details['shahrah_source_'.$sourceId]=$action;
            }catch(Throwable $e){$errors[]='Shahrah Source #'.$sourceId.': '.$e->getMessage();$details['shahrah_source_'.$sourceId]='error';}
        }

        if($expectsPg){
            try{
                $p=self::panel('pasarguard',$pgId);if(!$p||!(int)$p['active'])throw new RuntimeException('پنل PasarGuard فعال پیدا نشد.');
                $fallbackGroups=BlueVPN_Utils::json_decode_array((string)$plan['group_ids_json'],[]);$groupIds=self::pg_active_group_ids($p,$fallbackGroups,10);
                $u=self::username($c,'pg');$remote=self::pg_user($p,$u,10,max(0,(int)($c['pg_user_id']??0)));$wasMapped=((int)($c['panel_id']??0)===(int)$p['id']&&trim((string)($c['pg_username']??''))!==''&&trim((string)($c['pasarguard_subscription_url']??''))!=='');
                if(!$remote){
                    $payload=['username'=>$u,'status'=>'active','expire'=>$expire?gmdate('Y-m-d\TH:i:s\Z',strtotime($expire.' UTC')):0,'data_limit'=>$providerQuota,'data_limit_reset_strategy'=>'no_reset','group_ids'=>$groupIds,'hwid_limit'=>self::pg_hwid_limit($deviceLimit),'note'=>'BlueVPN repair; customer '.$customerId];
                    $proxySettings=self::pg_proxy_settings($p);if($proxySettings)$payload['proxy_settings']=$proxySettings;
                    $r=self::req('POST',self::join_url((string)$p['base_url'],'/api/user'),self::pg_headers($p,10),$payload,(bool)$p['verify_tls'],[],12);
                    if($r['code']>=400)throw new RuntimeException('ساخت کاربر ناموفق: HTTP '.$r['code'].' '.mb_substr($r['body'],0,420));
                    $remote=$r['json']?:self::pg_user($p,$u,10)?:[];$created++;$details['pasarguard']='created';
                }else{
                    $sync=self::pg_update_user($p,$u,['group_ids'=>$groupIds],max(0,(int)($remote['id']??0)),12);
                    if($sync['code']>=400)throw new RuntimeException('همگام‌سازی گروه‌های PasarGuard ناموفق: HTTP '.$sync['code'].' '.mb_substr($sync['body'],0,420));
                    $remote=self::pg_user($p,$u,10)?:$remote;$existing++;$details['pasarguard']=$wasMapped?'groups_synced':'attached_groups_synced';if(!$wasMapped)$attached++;
                }
                $sub=self::remote_sub_url((array)$remote,(string)$p['base_url']);
                $update['panel_id']=(int)$p['id'];$update['pg_username']=$u;
                if(isset($remote['id'])&&is_numeric($remote['id']))$update['pg_user_id']=(int)$remote['id'];
                if($sub!=='')$update['pasarguard_subscription_url']=$sub;
            }catch(Throwable $e){$errors[]='PasarGuard: '.$e->getMessage();$details['pasarguard']='error';}
        }

        if($expectsMz){
            try{
                $p=self::panel('marzban',$mzId);if(!$p||!(int)$p['active'])throw new RuntimeException('پنل Marzban فعال پیدا نشد.');
                [$proxies,$inbounds]=self::mz_access($p,10,BlueVPN_Utils::json_decode_array((string)($plan['marzban_inbounds_json']??''),[]));
                $u=self::username($c,'mz');$remote=self::mz_user($p,$u,10);$wasMapped=((int)($c['marzban_panel_id']??0)===(int)$p['id']&&trim((string)($c['marzban_username']??''))!==''&&trim((string)($c['marzban_subscription_url']??''))!=='');
                if(!$remote){
                    $payload=['username'=>$u,'status'=>'active','expire'=>$expire?strtotime($expire.' UTC'):0,'data_limit'=>$providerQuota,'data_limit_reset_strategy'=>'no_reset','proxies'=>$proxies,'inbounds'=>$inbounds,'note'=>'BlueVPN repair; customer '.$customerId];
                    $r=self::req('POST',self::join_url((string)$p['base_url'],'/api/user'),self::mz_headers($p,10),$payload,(bool)$p['verify_tls'],[],12);
                    if($r['code']>=400)throw new RuntimeException('ساخت کاربر ناموفق: HTTP '.$r['code'].' '.mb_substr($r['body'],0,420));
                    $remote=self::mz_user($p,$u,10)?:$r['json'];$created++;$details['marzban']='created';
                }else{
                    $sync=self::req('PUT',self::join_url((string)$p['base_url'],'/api/user/'.rawurlencode($u)),self::mz_headers($p,10),['proxies'=>$proxies,'inbounds'=>$inbounds],(bool)$p['verify_tls'],[],12);
                    if($sync['code']>=400)throw new RuntimeException('همگام‌سازی Inboundهای Marzban ناموفق: HTTP '.$sync['code'].' '.mb_substr($sync['body'],0,420));
                    $remote=self::mz_user($p,$u,10)?:$remote;$existing++;$details['marzban']=$wasMapped?'inbounds_synced':'attached_inbounds_synced';if(!$wasMapped)$attached++;
                }
                $sub=self::remote_sub_url((array)$remote,(string)$p['base_url']);
                $update['marzban_panel_id']=(int)$p['id'];$update['marzban_username']=$u;$update['marzban_status']=(string)($remote['status']??'active');
                if(isset($remote['id'])&&is_numeric($remote['id']))$update['marzban_user_id']=(int)$remote['id'];
                if($sub!=='')$update['marzban_subscription_url']=$sub;
            }catch(Throwable $e){$errors[]='Marzban: '.$e->getMessage();$details['marzban']='error';$update['marzban_last_error']=mb_substr($e->getMessage(),0,1800);}
        }

        if($expectsGc){
            try{
                $p=self::panel('guardcore',$gcId);
                if(!$p||!(int)$p['active'])throw new RuntimeException('پنل GuardCore فعال پیدا نشد.');
                $u=self::username($c,'gc');
                $global=trim((string)($p['global_subscription_url']??''));
                $wasMapped=((int)($c['guardcore_panel_id']??0)===(int)$p['id'])&&(trim((string)($c['guardcore_subscription_url']??''))!==''||trim((string)($c['guardcore_username']??''))!=='');

                if(($p['auth_mode']??'manual')==='manual'){
                    if($global==='')throw new RuntimeException('Global Subscription فعال پیدا نشد.');
                    $update['guardcore_panel_id']=(int)$p['id'];$update['guardcore_username']=$u;$update['guardcore_subscription_url']=esc_url_raw($global);
                    $update['guardcore_status']='active';$update['guardcore_expire']=$expire;$update['guardcore_last_error']='';
                    if($wasMapped){$existing++;$details['guardcore']='global_existing';}else{$attached++;$details['guardcore']='global_attached';}
                }else{
                    $serviceIds=array_values(array_unique(array_filter(array_map('intval',BlueVPN_Utils::json_decode_array((string)($plan['guardcore_service_ids_json']??''),[])),static fn($x)=>$x>0)));
                    $remote=self::gc_find_customer_subscription($p,$c,$u);
                    if(!$remote){
                        if(!$serviceIds)throw new RuntimeException('برای ساخت اشتراک گمشده GuardCore، Serviceهای پلن مشخص نشده‌اند.');
                        $remote=self::gc_provision($p,$u,$expire,$providerQuota,$serviceIds,'BlueVPN repair; customer '.$customerId,null);
                        $created++;$details['guardcore']='created';
                    }else{
                        $existingUsername=trim((string)($remote['username']??''))?:$u;$u=$existingUsername;
                        // Repair access selection without touching quota, usage or expiry.
                        if($serviceIds){
                            $remoteServices=array_values(array_unique(array_map('intval',(array)($remote['service_ids']??[]))));sort($remoteServices);
                            $expectedServices=$serviceIds;sort($expectedServices);
                            if($remoteServices!==$expectedServices){
                                $sync=self::gc_request($p,'PUT','/api/subscriptions/'.rawurlencode($u),['service_ids'=>$serviceIds]);
                                if($sync['code']>=400)throw new RuntimeException('همگام‌سازی Serviceهای GuardCore ناموفق: HTTP '.$sync['code']);
                                $remote=self::gc_user($p,$u)?:$remote;
                            }
                        }
                        if($wasMapped){$existing++;$details['guardcore']='services_synced';}else{$attached++;$details['guardcore']='attached_services_synced';}
                    }
                    $update['guardcore_panel_id']=(int)$p['id'];$update['guardcore_username']=$u;
                    $update['guardcore_subscription_id']=is_numeric($remote['id']??null)?(int)$remote['id']:null;
                    $update['guardcore_subscription_url']=(string)($remote['subscription_url']??'');
                    $update['guardcore_status']=(string)($remote['status']??'active');$update['guardcore_expire']=$remote['expire']??$expire;
                    $update['guardcore_data_limit_bytes']=(int)($remote['data_limit']??$providerQuota);
                    $update['guardcore_used_traffic_bytes']=(int)($remote['used_traffic']??0);$update['guardcore_last_error']='';
                }
            }catch(Throwable $e){$errors[]='GuardCore: '.$e->getMessage();$details['guardcore']='error';$update['guardcore_last_error']=mb_substr($e->getMessage(),0,1800);}
        }

        if($expectsStatic){
            $resynced+=count($staticManualEntries);
            $details['static_sources']='snapshot_resync_queued';
        }

        if($expectsGateway){
            try{
                if(!class_exists('BlueVPN_Gateway'))throw new RuntimeException('Gateway Metering در این نصب در دسترس نیست.');
                if(!BlueVPN_Gateway::has_active_gateway())throw new RuntimeException('Gateway فعال و سالم برای جایگذاری session وجود ندارد.');
                $sessions=BlueVPN_Gateway::ensure_customer_sessions($customerId);
                if(!$sessions)throw new RuntimeException('Gateway session قابل استفاده برای کاربر ساخته یا بازیابی نشد.');
                $resynced++;$details['gateway']='sessions_ensured';
            }catch(Throwable $e){$errors[]='Gateway: '.$e->getMessage();$details['gateway']='error';}
        }

        $repaired=$created+$attached;
        $needsSnapshot=$repaired>0||$expectsStatic||$expectsSh||$expectsLegacySh||$expectsGc||$expectsPg||$expectsMz;
        if($repaired>0||$resynced>0||$update){
            if(empty($c['subscription_token']))$update['subscription_token']=BlueVPN_Utils::random_token(30);
            $token=(string)($update['subscription_token']??$c['subscription_token']??'');
            if($token!=='')$update['subscription_url']=home_url('/sub/'.$token);
            $update['last_sync_at']=BlueVPN_Utils::now_mysql();$update['last_sync_error']=implode(' | ',$errors);
            if($wpdb->update($ct,$update,['id'=>$customerId])===false)$errors[]='ذخیره نتیجه ترمیم در MySQL ناموفق بود.';
        }
        if($needsSnapshot)self::request_background_snapshot($customerId);

        $ok=count($errors)===0;
        if($repaired>0||$resynced>0)$message='ترمیم سراسری انجام شد: '.$created.' ساخته شد، '.$attached.' اتصال بازیابی شد، '.$resynced.' مسیر دوباره همگام شد.';
        elseif($existing>0&&$ok)$message='همه اشتراک‌ها و مسیرهای پولی موجود و سالم هستند.';
        else $message=$errors?implode(' | ',$errors):'نیازی به ترمیم نبود.';

        return ['ok'=>$ok,'eligible'=>true,'message'=>$message,'repaired'=>$repaired,'created'=>$created,'attached'=>$attached,'existing'=>$existing,'resynced'=>$resynced,'errors'=>$errors,'details'=>$details];
    }

    public static function reconcile_missing_paid_subscriptions_batch(int $limit=2): array {
        $limit=max(1,min(5,$limit));
        $cursor=max(0,(int)get_option(self::PAID_REPAIR_CURSOR_OPTION,0));
        $ids=self::repair_candidate_ids_after($cursor,$limit);

        if(!$ids&&$cursor>0){
            update_option(self::PAID_REPAIR_CURSOR_OPTION,0,false);
            $summary=['checked'=>0,'repaired'=>0,'created'=>0,'attached'=>0,'resynced'=>0,'errors'=>0,'wrapped'=>true,'cursor'=>0,'at'=>BlueVPN_Utils::iso_now()];
            update_option(self::PAID_REPAIR_LAST_OPTION,$summary,false);
            return $summary;
        }

        $summary=['checked'=>0,'repaired'=>0,'created'=>0,'attached'=>0,'resynced'=>0,'errors'=>0,'wrapped'=>false,'cursor'=>$cursor,'at'=>BlueVPN_Utils::iso_now()];
        $last=$cursor;
        foreach($ids as $id){
            $id=(int)$id;$last=max($last,$id);$summary['checked']++;
            try{
                $r=self::repair_customer_missing_providers($id);
                $summary['repaired']+=(int)($r['repaired']??0);
                $summary['created']+=(int)($r['created']??0);
                $summary['attached']+=(int)($r['attached']??0);
                $summary['resynced']+=(int)($r['resynced']??0);
                if(empty($r['ok']))$summary['errors']++;
            }catch(Throwable $e){
                $summary['errors']++;
            }
        }
        update_option(self::PAID_REPAIR_CURSOR_OPTION,$last,false);
        $summary['cursor']=$last;
        update_option(self::PAID_REPAIR_LAST_OPTION,$summary,false);
        return $summary;
    }

    public static function repairable_customer_count(): int {
        global $wpdb;$c=BlueVPN_DB::table('customers');$p=BlueVPN_DB::table('plans');
        return (int)$wpdb->get_var("SELECT COUNT(*) FROM {$c} c JOIN {$p} p ON p.id=c.plan_id AND p.deleted=0 WHERE c.active=1 AND c.subscription_status='active' AND (c.subscription_expire IS NULL OR c.subscription_expire>UTC_TIMESTAMP())");
    }

    public static function repair_candidate_ids_after(int $afterId,int $limit=1): array {
        global $wpdb;$c=BlueVPN_DB::table('customers');$p=BlueVPN_DB::table('plans');$limit=max(1,min(5,$limit));
        $sql=$wpdb->prepare("SELECT c.id FROM {$c} c JOIN {$p} p ON p.id=c.plan_id AND p.deleted=0 WHERE c.id>%d AND c.active=1 AND c.subscription_status='active' AND (c.subscription_expire IS NULL OR c.subscription_expire>UTC_TIMESTAMP()) ORDER BY c.id ASC LIMIT %d",max(0,$afterId),$limit);
        return array_map('intval',$wpdb->get_col($sql)?:[]);
    }

    public static function provision_customer(int $customerId,int $planId,?string $canonicalExpire=null,?int $overrideDataLimitBytes=null): array {
        global $wpdb;$ct=BlueVPN_DB::table('customers');$pt=BlueVPN_DB::table('plans');
        $c=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$ct} WHERE id=%d",$customerId),ARRAY_A);
        $plan=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$pt} WHERE id=%d AND deleted=0",$planId),ARRAY_A);
        if(!$c||!$plan)return ['ok'=>false,'message'=>'کاربر یا پلن پیدا نشد.'];

        $trafficMode=(string)($plan['traffic_mode']??'provider_reported');$trafficMode=$trafficMode==='gateway_metered'?'gateway_metered':'provider_reported';
        $manualEntries=class_exists('BlueVPN_Subscription_Sources')?BlueVPN_Subscription_Sources::active_entries_for_plan($planId):[];
        $shahrahEntries=array_values(array_filter($manualEntries,static fn($entry)=>(string)($entry['provider_type']??'')==='shahrah'));
        $staticManualEntries=array_values(array_filter($manualEntries,static fn($entry)=>(string)($entry['provider_type']??'')!=='shahrah'));
        $routes=self::plan_provider_routes($plan);
        $routeCount=array_sum(array_map('count',$routes));
        $hasExplicit=!empty($manualEntries)||$routeCount>0;

        if(!$hasExplicit){
            $pg=class_exists('BlueVPN_AI_Ops')?BlueVPN_AI_Ops::recommend_panel_id('pasarguard'):(int)$wpdb->get_var("SELECT id FROM ".BlueVPN_DB::table('pasarguard_panels')." WHERE active=1 ORDER BY id ASC LIMIT 1");
            $mz=class_exists('BlueVPN_AI_Ops')?BlueVPN_AI_Ops::recommend_panel_id('marzban'):(int)$wpdb->get_var("SELECT id FROM ".BlueVPN_DB::table('marzban_panels')." WHERE active=1 ORDER BY id ASC LIMIT 1");
            $gc=(int)$wpdb->get_var("SELECT id FROM ".BlueVPN_DB::table('guardcore_panels')." WHERE active=1 AND auth_mode='manual' AND global_subscription_url IS NOT NULL AND TRIM(global_subscription_url)<>'' ORDER BY id ASC LIMIT 1");
            if($pg>0)$routes['pasarguard'][]=['panel_id'=>$pg,'group_ids'=>[]];
            if($mz>0)$routes['marzban'][]=['panel_id'=>$mz,'inbounds'=>[]];
            if($gc>0)$routes['guardcore'][]=['panel_id'=>$gc,'service_ids'=>[]];
        }

        $expire=self::canonical_expiry($c,$plan,$canonicalExpire);
        $total=$overrideDataLimitBytes!==null
            ? max(0,$overrideDataLimitBytes)
            : max(0,(int)$plan['data_limit_gb'])*1024*1024*1024;
        $quotaRoutes=count($routes['pasarguard'])+count($routes['marzban'])+count($routes['guardcore'])+count($routes['hiddify'])+count($routes['threexui']);
        $quota=($quotaRoutes>1&&($plan['multi_provider_quota_mode']??'split')==='split')?intdiv($total,max(1,$quotaRoutes)):$total;
        $providerQuota=$trafficMode==='gateway_metered'?0:$quota;

        $errors=[];$success=count($staticManualEntries)>0?1:0;
        $update=['plan_id'=>$planId,'subscription_expire'=>$expire,'data_limit_bytes'=>$total,'device_limit'=>max(1,(int)$plan['device_limit'])];
        $desired=[];$resolved=['pasarguard'=>[],'marzban'=>[],'shahrah'=>[],'guardcore'=>[],'hiddify'=>[],'threexui'=>[]];
        $legacyWritten=['pasarguard'=>false,'marzban'=>false,'guardcore'=>false];
        $existingLinks=[];foreach(self::customer_provider_links($customerId) as $link)$existingLinks[(string)$link['route_key']]=$link;

        foreach($routes['shahrah'] as $route){
            $panelId=(int)$route['panel_id'];$slug=(string)$route['plan_slug'];$desired[]=self::provider_route_key('shahrah',$route);
            try{
                if(!class_exists('BlueVPN_Shahrah'))throw new RuntimeException('تأمین‌کننده شاهراه بارگذاری نشده است.');
                $seed=(string)($c['email']?:$c['phone']?:$c['id']);
                $username=substr('bv_'.$c['id'].'_'.substr(sha1('shahrah-panel:'.$panelId.':'.$seed),0,9),0,32);
                $result=BlueVPN_Shahrah::provision_panel($panelId,$customerId,$slug,$username);
                if(empty($result['ok']))throw new RuntimeException((string)($result['message']??'فعال‌سازی ناموفق'));
                self::provider_link_upsert($customerId,$planId,'shahrah',$route,[
                    'username'=>$username,'remote_id'=>(string)($result['service_slug']??''),'status'=>'active','metadata'=>$route,
                ]);
                $resolved['shahrah'][]=$panelId.'|'.$slug;$success++;
            }catch(Throwable $e){$errors[]='شاهراه #'.$panelId.': '.$e->getMessage();}
        }

        foreach($shahrahEntries as $entry){
            try{
                if(!class_exists('BlueVPN_Shahrah'))throw new RuntimeException('تأمین‌کننده شاهراه بارگذاری نشده است.');
                $cfg=BlueVPN_Subscription_Sources::shahrah_credentials_from_entry($entry);
                if(($cfg['api_key']??'')===''||($cfg['plan_slug']??'')==='')throw new RuntimeException('کلید یا پلن شاهراه برای منبع قدیمی تنظیم نشده است.');
                $seed=(string)($c['email']?:$c['phone']?:$c['id']);$username=substr('bv_'.$c['id'].'_'.substr(sha1('shahrah:'.$seed),0,9),0,32);
                $result=BlueVPN_Shahrah::provision((int)($entry['id']??0),$customerId,(string)$cfg['api_key'],(string)$cfg['plan_slug'],$username);
                if(empty($result['ok']))throw new RuntimeException((string)($result['message']??'فعال‌سازی ناموفق'));$success++;
            }catch(Throwable $e){$errors[]='شاهراه قدیمی: '.$e->getMessage();}
        }

        foreach($routes['pasarguard'] as $route){
            $panelId=(int)$route['panel_id'];$desired[]=self::provider_route_key('pasarguard',$route);
            try{
                $p=self::panel('pasarguard',$panelId);if(!$p||!(int)$p['active'])throw new RuntimeException('سرور فعال پیدا نشد.');
                $u=self::username($c,'pg');$routeKey=self::provider_route_key('pasarguard',$route);
                $knownId=isset($existingLinks[$routeKey])?max(0,(int)($existingLinks[$routeKey]['remote_id']??0)):max(0,(int)($c['pg_user_id']??0));
                $old=self::pg_user($p,$u,25,$knownId);
                $groupIds=self::pg_active_group_ids($p,(array)($route['group_ids']??[]));
                $payload=['status'=>'active','expire'=>$expire?gmdate('Y-m-d\TH:i:s\Z',strtotime($expire.' UTC')):0,'data_limit'=>$providerQuota,'data_limit_reset_strategy'=>'no_reset','group_ids'=>$groupIds,'hwid_limit'=>self::pg_hwid_limit((int)$plan['device_limit']),'note'=>'BlueVPN WordPress; customer '.$customerId];
                if(!$old){$payload['username']=$u;$proxySettings=self::pg_proxy_settings($p);if($proxySettings)$payload['proxy_settings']=$proxySettings;$r=self::req('POST',self::join_url((string)$p['base_url'],'/api/user'),self::pg_headers($p),$payload,(bool)$p['verify_tls']);}
                else{$r=self::pg_update_user($p,$u,$payload,max(0,(int)($old['id']??0)),25);}
                if($r['code']>=400)throw new RuntimeException('فعال‌سازی ناموفق: HTTP '.$r['code'].' '.mb_substr($r['body'],0,420));
                $remote=$r['json']?:self::pg_user($p,$u,25,max(0,(int)($old['id']??0)))?:[];$sub=self::remote_sub_url($remote,(string)$p['base_url']);
                self::provider_link_upsert($customerId,$planId,'pasarguard',$route,['username'=>$u,'remote_id'=>(string)($remote['id']??''),'subscription_url'=>$sub,'status'=>(string)($remote['status']??'active'),'used_traffic_bytes'=>(int)($remote['used_traffic']??$remote['used_traffic_bytes']??0),'remote_expire'=>self::remote_expiry($remote['expire']??null),'metadata'=>$route]);
                if(!$legacyWritten['pasarguard']){$update['panel_id']=$panelId;$update['pg_username']=$u;$update['pasarguard_subscription_url']=$sub;$legacyWritten['pasarguard']=true;}
                $resolved['pasarguard'][]=$panelId;$success++;
            }catch(Throwable $e){$errors[]='پاسارگاد #'.$panelId.': '.$e->getMessage();}
        }

        foreach($routes['marzban'] as $route){
            $panelId=(int)$route['panel_id'];$desired[]=self::provider_route_key('marzban',$route);
            try{
                $p=self::panel('marzban',$panelId);if(!$p||!(int)$p['active'])throw new RuntimeException('سرور فعال پیدا نشد.');
                $u=self::username($c,'mz');$old=self::mz_user($p,$u);[$proxies,$inbounds]=self::mz_access($p,25,(array)($route['inbounds']??[]));
                $payload=['status'=>'active','expire'=>$expire?strtotime($expire.' UTC'):0,'data_limit'=>$providerQuota,'data_limit_reset_strategy'=>'no_reset','proxies'=>$proxies,'inbounds'=>$inbounds,'note'=>'BlueVPN WordPress; customer '.$customerId];
                $path=$old?'/api/user/'.rawurlencode($u):'/api/user';if(!$old)$payload['username']=$u;
                $r=self::req($old?'PUT':'POST',self::join_url((string)$p['base_url'],$path),self::mz_headers($p),$payload,(bool)$p['verify_tls']);
                if($r['code']>=400)throw new RuntimeException('فعال‌سازی ناموفق: HTTP '.$r['code'].' '.mb_substr($r['body'],0,420));
                $remote=self::mz_user($p,$u)?:$r['json'];$sub=self::remote_sub_url($remote,(string)$p['base_url']);
                self::provider_link_upsert($customerId,$planId,'marzban',$route,['username'=>$u,'remote_id'=>(string)($remote['id']??''),'subscription_url'=>$sub,'status'=>(string)($remote['status']??'active'),'used_traffic_bytes'=>(int)($remote['used_traffic']??$remote['used_traffic_bytes']??0),'remote_expire'=>self::remote_expiry($remote['expire']??null),'metadata'=>$route]);
                if(!$legacyWritten['marzban']){$update['marzban_panel_id']=$panelId;$update['marzban_username']=$u;$update['marzban_subscription_url']=$sub;$update['marzban_status']='active';$legacyWritten['marzban']=true;}
                $resolved['marzban'][]=$panelId;$success++;
            }catch(Throwable $e){$errors[]='مرزبان #'.$panelId.': '.$e->getMessage();}
        }

        foreach($routes['guardcore'] as $route){
            $panelId=(int)$route['panel_id'];$desired[]=self::provider_route_key('guardcore',$route);
            try{
                $p=self::panel('guardcore',$panelId);if(!$p||!(int)$p['active'])throw new RuntimeException('سرور فعال پیدا نشد.');
                $u=self::username($c,'gc');$global=trim((string)($p['global_subscription_url']??''));
                if(($p['auth_mode']??'manual')==='manual'){
                    if($global==='')throw new RuntimeException('نشانی اشتراک عمومی این سرور خالی است.');
                    $sub=esc_url_raw($global);$remote=[];$status='active';
                }else{
                    $serviceIds=self::gc_route_service_ids($panelId,(array)($route['service_ids']??[]));
                    $old=self::gc_user($p,$u);$remote=self::gc_provision($p,$u,$expire,$providerQuota,$serviceIds,'BlueVPN WordPress; customer '.$customerId,$old);
                    $sub=(string)($remote['subscription_url']??'');$status=(string)($remote['status']??'active');$route['service_ids']=$serviceIds;
                }
                self::provider_link_upsert($customerId,$planId,'guardcore',$route,['username'=>$u,'remote_id'=>(string)($remote['id']??''),'subscription_url'=>$sub,'status'=>$status,'used_traffic_bytes'=>(int)($remote['used_traffic']??0),'remote_expire'=>$remote['expire']??$expire,'metadata'=>$route]);
                if(!$legacyWritten['guardcore']){$update['guardcore_panel_id']=$panelId;$update['guardcore_username']=$u;$update['guardcore_subscription_url']=$sub;$update['guardcore_status']=$status;$update['guardcore_expire']=$remote['expire']??$expire;$update['guardcore_data_limit_bytes']=($p['auth_mode']??'manual')==='manual'?0:$providerQuota;$update['guardcore_used_traffic_bytes']=(int)($remote['used_traffic']??0);$update['guardcore_last_error']='';$legacyWritten['guardcore']=true;}
                $resolved['guardcore'][]=$panelId;$success++;
            }catch(Throwable $e){$errors[]='گاردکور #'.$panelId.': '.$e->getMessage();}
        }

        foreach($routes['hiddify'] as $route){
            $panelId=(int)$route['panel_id'];$desired[]=self::provider_route_key('hiddify',$route);
            try{
                if(!class_exists('BlueVPN_Hiddify'))throw new RuntimeException('Adapter Hiddify بارگذاری نشده است.');
                $uuid=self::hiddify_uuid($c,$panelId);$name=self::external_username($c,'hiddify');
                $remote=BlueVPN_Hiddify::provision($panelId,$uuid,$name,$providerQuota,$expire,max(1,(int)$plan['device_limit']),'BlueVPN WordPress; customer '.$customerId);
                self::provider_link_upsert($customerId,$planId,'hiddify',$route,[
                    'username'=>$name,'remote_id'=>$uuid,'subscription_url'=>(string)($remote['subscription_url']??''),
                    'status'=>(string)($remote['status']??'active'),'used_traffic_bytes'=>(int)($remote['used_traffic_bytes']??0),
                    'remote_expire'=>$remote['expire']??$expire,'metadata'=>$route,
                ]);
                $resolved['hiddify'][]=$panelId;$success++;
            }catch(Throwable $e){$errors[]='هیدیفای #'.$panelId.': '.$e->getMessage();}
        }

        foreach($routes['threexui'] as $route){
            $panelId=(int)$route['panel_id'];$desired[]=self::provider_route_key('threexui',$route);
            try{
                if(!class_exists('BlueVPN_ThreeXUI'))throw new RuntimeException('Adapter 3x-ui بارگذاری نشده است.');
                $email=self::external_username($c,'3xui');
                $remote=BlueVPN_ThreeXUI::provision($panelId,$email,$providerQuota,$expire,max(1,(int)$plan['device_limit']),(array)($route['inbound_ids']??[]),'BlueVPN WordPress; customer '.$customerId);
                $route['inbound_ids']=(array)($remote['inbound_ids']??$route['inbound_ids']??[]);
                self::provider_link_upsert($customerId,$planId,'threexui',$route,[
                    'username'=>$email,'remote_id'=>(string)($remote['remote_id']??$remote['sub_id']??$email),
                    'subscription_url'=>(string)($remote['subscription_url']??''),'status'=>(string)($remote['status']??'active'),
                    'used_traffic_bytes'=>(int)($remote['used_traffic_bytes']??0),'remote_expire'=>$remote['expire']??$expire,'metadata'=>$route,
                ]);
                $resolved['threexui'][]=$panelId;$success++;
            }catch(Throwable $e){$errors[]='3x-ui #'.$panelId.': '.$e->getMessage();}
        }

        self::prune_customer_provider_links($customerId,$desired);

        if(empty($c['subscription_token']))$update['subscription_token']=BlueVPN_Utils::random_token(30);else$update['subscription_token']=$c['subscription_token'];
        $update['subscription_url']=home_url('/sub/'.$update['subscription_token']);$update['subscription_status']=$success>0?'active':'inactive';$update['last_sync_at']=BlueVPN_Utils::now_mysql();
        if($trafficMode==='gateway_metered'&&!class_exists('BlueVPN_Gateway'))$errors[]='اندازه‌گیری مرکزی مصرف در این نصب در دسترس نیست.';
        if($trafficMode==='gateway_metered'&&class_exists('BlueVPN_Gateway')&&!BlueVPN_Gateway::has_active_gateway())$errors[]='برای این پلن دروازه فعال ثبت نشده است.';
        if($success===0&&!$errors)$errors[]='هیچ تأمین‌کننده یا منبع اشتراک قابل استفاده‌ای پیدا نشد.';
        $update['last_sync_error']=implode(' | ',$errors);if($trafficMode==='gateway_metered'&&$errors)$update['subscription_status']='inactive';
        $saved=$wpdb->update($ct,$update,['id'=>$customerId]);if($saved===false)return ['ok'=>false,'message'=>'ذخیره اشتراک کاربر در پایگاه داده ناموفق بود.','success_count'=>$success];
        $gatewaySessions=[];if($success>0)self::request_background_snapshot($customerId);
        if($trafficMode==='gateway_metered'&&class_exists('BlueVPN_Gateway')&&!$errors)$gatewaySessions=BlueVPN_Gateway::ensure_customer_sessions($customerId);
        return ['ok'=>$success>0&&count($errors)===0,'partial'=>$success>0&&count($errors)>0,'message'=>$errors?implode(' | ',$errors):'همه مسیرهای تأمین‌کننده فعال شدند.','success_count'=>$success,'canonical_expire'=>$expire,'expiry_source'=>'wordpress_mysql_entitlement','data_limit_bytes'=>$total,'data_limit_source'=>$overrideDataLimitBytes!==null?'manual_override':'plan','traffic_mode'=>$trafficMode,'gateway_sessions'=>count($gatewaySessions),'resolved_providers'=>$resolved];
    }

    public static function request_background_sync(int $customerId): bool {
        if($customerId<=0)return false;
        if(wp_next_scheduled('bluevpn_sync_customer_async',[$customerId]))return false;
        $ok=wp_schedule_single_event(time()+2,'bluevpn_sync_customer_async',[$customerId]);
        if($ok!==false)BlueVPN_Utils::kick_wp_cron();
        return $ok!==false;
    }
    public static function async_sync_customer(int $customerId): void {
        self::sync_customer($customerId,false);
    }

    private static function enforce_provider_expiry(array $c,string $provider,string $canonical): array {
        try{
            if($provider==='pasarguard'&&!empty($c['panel_id'])&&!empty($c['pg_username'])){
                $p=self::panel('pasarguard',(int)$c['panel_id']);if(!$p)return ['ok'=>false,'message'=>'PasarGuard panel missing'];
                $payload=['expire'=>gmdate('Y-m-d\TH:i:s\Z',strtotime($canonical.' UTC'))];
                $r=self::pg_update_user($p,(string)$c['pg_username'],$payload,max(0,(int)($c['pg_user_id']??0)),12);
                return ['ok'=>$r['code']<400,'message'=>'HTTP '.$r['code']];
            }
            if($provider==='marzban'&&!empty($c['marzban_panel_id'])&&!empty($c['marzban_username'])){
                $p=self::panel('marzban',(int)$c['marzban_panel_id']);if(!$p)return ['ok'=>false,'message'=>'Marzban panel missing'];
                $r=self::req('PUT',self::join_url((string)$p['base_url'],'/api/user/'.rawurlencode((string)$c['marzban_username'])),self::mz_headers($p,10),['expire'=>strtotime($canonical.' UTC')],(bool)$p['verify_tls'],[],12);
                return ['ok'=>$r['code']<400,'message'=>'HTTP '.$r['code']];
            }
            if($provider==='guardcore'&&!empty($c['guardcore_panel_id'])&&!empty($c['guardcore_username'])){
                $p=self::panel('guardcore',(int)$c['guardcore_panel_id']);if(!$p)return ['ok'=>false,'message'=>'GuardCore panel missing'];
                if(($p['auth_mode']??'manual')==='manual')return ['ok'=>true,'message'=>'manual/global provider uses WordPress entitlement'];
                $r=self::gc_request($p,'PUT','/api/subscriptions/'.rawurlencode((string)$c['guardcore_username']),['limit_expire'=>self::gc_expire_encode($p,$canonical)]);
                return ['ok'=>$r['code']<400,'message'=>'HTTP '.$r['code']];
            }
            return ['ok'=>true,'message'=>'provider mapping not applicable'];
        }catch(Throwable $e){return ['ok'=>false,'message'=>$e->getMessage()];}
    }

    private static function enforce_provider_link_expiry(array $link,string $canonical): array {
        try{
            $provider=(string)($link['provider_type']??'');$panelId=(int)($link['panel_id']??0);$username=(string)($link['username']??'');
            if($panelId<=0||$username==='')return ['ok'=>true,'message'=>'mapping not applicable'];
            if($provider==='pasarguard'){
                $p=self::panel('pasarguard',$panelId);if(!$p)return ['ok'=>false,'message'=>'سرور پاسارگاد پیدا نشد'];
                $r=self::pg_update_user($p,$username,['expire'=>gmdate('Y-m-d\TH:i:s\Z',strtotime($canonical.' UTC'))],max(0,(int)($link['remote_id']??0)),12);
                return ['ok'=>$r['code']<400,'message'=>'HTTP '.$r['code']];
            }
            if($provider==='marzban'){
                $p=self::panel('marzban',$panelId);if(!$p)return ['ok'=>false,'message'=>'سرور مرزبان پیدا نشد'];
                $r=self::req('PUT',self::join_url((string)$p['base_url'],'/api/user/'.rawurlencode($username)),self::mz_headers($p,10),['expire'=>strtotime($canonical.' UTC')],(bool)$p['verify_tls'],[],12);
                return ['ok'=>$r['code']<400,'message'=>'HTTP '.$r['code']];
            }
            if($provider==='guardcore'){
                $p=self::panel('guardcore',$panelId);if(!$p)return ['ok'=>false,'message'=>'سرور گاردکور پیدا نشد'];
                if(($p['auth_mode']??'manual')==='manual')return ['ok'=>true,'message'=>'اشتراک عمومی از تاریخ مرکزی استفاده می‌کند'];
                $r=self::gc_request($p,'PUT','/api/subscriptions/'.rawurlencode($username),['limit_expire'=>self::gc_expire_encode($p,$canonical)]);
                return ['ok'=>$r['code']<400,'message'=>'HTTP '.$r['code']];
            }
            if($provider==='hiddify'){
                if(!class_exists('BlueVPN_Hiddify'))return ['ok'=>false,'message'=>'Adapter Hiddify بارگذاری نشده'];
                $uuid=trim((string)($link['remote_id']??''));if($uuid==='')return ['ok'=>false,'message'=>'UUID Hiddify در mapping موجود نیست'];
                return BlueVPN_Hiddify::enforce_expiry($panelId,$uuid,$canonical);
            }
            if($provider==='threexui'){
                if(!class_exists('BlueVPN_ThreeXUI'))return ['ok'=>false,'message'=>'Adapter 3x-ui بارگذاری نشده'];
                return BlueVPN_ThreeXUI::enforce_expiry($panelId,$username,$canonical);
            }
            return ['ok'=>true,'message'=>'این تأمین‌کننده تاریخ مستقل قابل تنظیم ندارد'];
        }catch(Throwable $e){return ['ok'=>false,'message'=>$e->getMessage()];}
    }

    private static function sync_customer_multi_links(array $c,bool $gateway): array {
        global $wpdb;$customerId=(int)$c['id'];$ct=BlueVPN_DB::table('customers');
        $links=self::customer_provider_links($customerId);
        $u=[];$errors=[];$active=false;$providerUsed=0;$responses=0;$configured=0;$providerExpiries=[];

        $manualEntries=class_exists('BlueVPN_Subscription_Sources')?BlueVPN_Subscription_Sources::active_entries_for_plan((int)$c['plan_id']):[];
        foreach($manualEntries as $entry){
            $configured++;
            if((string)($entry['provider_type']??'')==='shahrah'){
                if(class_exists('BlueVPN_Shahrah')){
                    $mapping=BlueVPN_Shahrah::mapping((int)($entry['id']??0),$customerId);
                    if(!empty($mapping['service_slug'])){$responses++;$active=true;}
                }
            }else{$responses++;$active=true;}
        }

        foreach($links as $link){
            $configured++;$provider=(string)$link['provider_type'];$panelId=(int)$link['panel_id'];$username=(string)$link['username'];
            $metadata=BlueVPN_Utils::json_decode_array((string)($link['metadata_json']??''),[]);if(!$metadata)$metadata=['panel_id'=>$panelId];
            try{
                if($provider==='shahrah'){
                    if(!class_exists('BlueVPN_Shahrah'))throw new RuntimeException('شاهراه بارگذاری نشده است.');
                    $r=BlueVPN_Shahrah::inspect_panel_customer($panelId,$customerId);$responses++;
                    if(empty($r['ok']))throw new RuntimeException((string)($r['message']??'وضعیت سرویس قابل دریافت نیست.'));
                    $status=(string)($r['status']??'unknown');$active=$active||!empty($r['active']);
                    self::provider_link_upsert($customerId,(int)$c['plan_id'],$provider,$metadata,['username'=>$username,'remote_id'=>(string)($r['service_slug']??$link['remote_id']),'subscription_url'=>(string)$link['subscription_url'],'status'=>$status,'metadata'=>$metadata]);
                    if(empty($r['active']))$errors[]='شاهراه #'.$panelId.': سرویس غیرفعال است.';
                    continue;
                }

                if($provider==='pasarguard'){
                    $p=self::panel('pasarguard',$panelId);if(!$p)throw new RuntimeException('سرور پیدا نشد.');
                    $r=self::pg_user($p,$username,25,max(0,(int)($link['remote_id']??0)));$responses++;if(!$r)throw new RuntimeException('کاربر روی سرور پیدا نشد.');
                    $status=strtolower((string)($r['status']??'active'));$active=$active||in_array($status,['active','enabled'],true);
                    $used=max(0,(int)($r['used_traffic']??$r['used_traffic_bytes']??0));$providerUsed+=$used;
                    $sub=self::remote_sub_url($r,(string)$p['base_url'])?:(string)$link['subscription_url'];$exp=self::remote_expiry($r['expire']??null);
                    if($exp)$providerExpiries[$link['route_key']]=$exp;
                    self::provider_link_upsert($customerId,(int)$c['plan_id'],$provider,$metadata,['username'=>$username,'remote_id'=>(string)($r['id']??$link['remote_id']),'subscription_url'=>$sub,'status'=>$status,'used_traffic_bytes'=>$used,'remote_expire'=>$exp,'metadata'=>$metadata]);
                    continue;
                }

                if($provider==='marzban'){
                    $p=self::panel('marzban',$panelId);if(!$p)throw new RuntimeException('سرور پیدا نشد.');
                    $r=self::mz_user($p,$username);$responses++;if(!$r)throw new RuntimeException('کاربر روی سرور پیدا نشد.');
                    $status=strtolower((string)($r['status']??'active'));$active=$active||in_array($status,['active','enabled'],true);
                    $used=max(0,(int)($r['used_traffic']??$r['used_traffic_bytes']??0));$providerUsed+=$used;
                    $sub=self::remote_sub_url($r,(string)$p['base_url'])?:(string)$link['subscription_url'];$exp=self::remote_expiry($r['expire']??null);
                    if($exp)$providerExpiries[$link['route_key']]=$exp;
                    self::provider_link_upsert($customerId,(int)$c['plan_id'],$provider,$metadata,['username'=>$username,'remote_id'=>(string)($r['id']??$link['remote_id']),'subscription_url'=>$sub,'status'=>$status,'used_traffic_bytes'=>$used,'remote_expire'=>$exp,'metadata'=>$metadata]);
                    continue;
                }

                if($provider==='hiddify'){
                    if(!class_exists('BlueVPN_Hiddify'))throw new RuntimeException('Adapter Hiddify بارگذاری نشده است.');
                    $uuid=trim((string)($link['remote_id']??''));if($uuid==='')throw new RuntimeException('UUID کاربر Hiddify در mapping موجود نیست.');
                    $r=BlueVPN_Hiddify::user($panelId,$uuid);$responses++;if(!$r)throw new RuntimeException('کاربر روی Hiddify پیدا نشد.');
                    $status=strtolower((string)($r['status']??'active'));$active=$active||in_array($status,['active','enabled'],true);
                    $used=max(0,(int)($r['used_traffic_bytes']??0));$providerUsed+=$used;
                    $sub=trim((string)($r['subscription_url']??''))?:(string)$link['subscription_url'];$exp=self::remote_expiry($r['expire']??null);
                    if($exp)$providerExpiries[$link['route_key']]=$exp;
                    self::provider_link_upsert($customerId,(int)$c['plan_id'],$provider,$metadata,[
                        'username'=>$username,'remote_id'=>$uuid,'subscription_url'=>$sub,'status'=>$status,
                        'used_traffic_bytes'=>$used,'remote_expire'=>$exp,'metadata'=>$metadata
                    ]);
                    continue;
                }

                if($provider==='threexui'){
                    if(!class_exists('BlueVPN_ThreeXUI'))throw new RuntimeException('Adapter 3x-ui بارگذاری نشده است.');
                    $r=BlueVPN_ThreeXUI::inspect($panelId,$username);$responses++;if(!$r)throw new RuntimeException('Client روی 3x-ui پیدا نشد.');
                    $status=strtolower((string)($r['status']??'active'));$active=$active||in_array($status,['active','enabled'],true);
                    $used=max(0,(int)($r['used_traffic_bytes']??0));$providerUsed+=$used;
                    $sub=trim((string)($r['subscription_url']??''))?:(string)$link['subscription_url'];$exp=self::remote_expiry($r['expire']??null);
                    if($exp)$providerExpiries[$link['route_key']]=$exp;
                    $metadata['inbound_ids']=(array)($r['inbound_ids']??$metadata['inbound_ids']??[]);
                    self::provider_link_upsert($customerId,(int)$c['plan_id'],$provider,$metadata,[
                        'username'=>$username,'remote_id'=>(string)($r['remote_id']??$link['remote_id']),
                        'subscription_url'=>$sub,'status'=>$status,'used_traffic_bytes'=>$used,'remote_expire'=>$exp,'metadata'=>$metadata
                    ]);
                    continue;
                }

                if($provider==='guardcore'){
                    $p=self::panel('guardcore',$panelId);if(!$p)throw new RuntimeException('سرور پیدا نشد.');
                    if(($p['auth_mode']??'manual')==='manual'){
                        $responses++;$active=$active||trim((string)$link['subscription_url'])!=='';
                        continue;
                    }
                    $r=self::gc_user($p,$username);$responses++;if(!$r)throw new RuntimeException('اشتراک روی سرور پیدا نشد.');
                    $status=strtolower((string)($r['status']??'active'));$active=$active||$status==='active';
                    $used=max(0,(int)($r['used_traffic']??0));$providerUsed+=$used;$sub=(string)($r['subscription_url']??$link['subscription_url']);$exp=self::remote_expiry($r['expire']??null);
                    if($exp)$providerExpiries[$link['route_key']]=$exp;
                    self::provider_link_upsert($customerId,(int)$c['plan_id'],$provider,$metadata,['username'=>$username,'remote_id'=>(string)($r['id']??$link['remote_id']),'subscription_url'=>$sub,'status'=>$status,'used_traffic_bytes'=>$used,'remote_expire'=>$exp,'metadata'=>$metadata]);
                }
            }catch(Throwable $e){$errors[]=$provider.' #'.$panelId.': '.$e->getMessage();}
        }

        $canonical=self::remote_expiry($c['subscription_expire']??null);
        if($canonical){
            $canonicalTs=strtotime($canonical.' UTC')?:0;
            foreach($links as $link){
                $key=(string)$link['route_key'];$remoteExpire=$providerExpiries[$key]??null;if(!$remoteExpire)continue;
                $remoteTs=strtotime($remoteExpire.' UTC')?:0;if(!$remoteTs||!$canonicalTs||abs($remoteTs-$canonicalTs)<=self::EXPIRY_DRIFT_TOLERANCE_SECONDS)continue;
                $repair=self::enforce_provider_link_expiry($link,$canonical);
                if(empty($repair['ok']))$errors[]=(string)$link['provider_type'].' #'.(int)$link['panel_id'].' تاریخ انقضا: '.(string)($repair['message']??'اصلاح ناموفق');
            }
        }

        if(!$gateway&&$responses>0)$u['used_traffic_bytes']=$providerUsed;
        if($gateway){
            $limit=max(0,(int)$c['data_limit_bytes']);$used=max(0,(int)$c['used_traffic_bytes']);$expired=$canonical&&(strtotime($canonical.' UTC')?:0)<=time();
            if($expired)$u['subscription_status']='expired';elseif($limit>0&&$used>=$limit)$u['subscription_status']='limited';elseif($active&&class_exists('BlueVPN_Gateway')&&BlueVPN_Gateway::has_active_gateway()){$u['subscription_status']='active';BlueVPN_Gateway::ensure_customer_sessions($customerId);}elseif($configured===0)$u['subscription_status']='inactive';
        }else{
            if($active)$u['subscription_status']='active';elseif($configured>0&&$responses>0&&$responses===$configured&&count($errors)===0)$u['subscription_status']='inactive';
        }
        $u['last_sync_at']=BlueVPN_Utils::now_mysql();$u['last_sync_error']=implode(' | ',$errors);$wpdb->update($ct,$u,['id'=>$customerId]);
        if($configured>0)self::request_background_snapshot($customerId);
        return ['ok'=>count($errors)===0,'cached'=>false,'multi_provider'=>true,'route_count'=>count($links),'traffic_mode'=>$gateway?'gateway_metered':'provider_reported','provider_reported_bytes'=>$providerUsed,'central_used_bytes'=>(int)($u['used_traffic_bytes']??$c['used_traffic_bytes']),'message'=>$errors?implode(' | ',$errors):'همگام‌سازی همه مسیرهای تأمین‌کننده انجام شد.'];
    }

    public static function sync_customer(int $customerId,bool $force=false): array {
        global $wpdb;
        $ct=BlueVPN_DB::table('customers');$pt=BlueVPN_DB::table('plans');
        $c=$wpdb->get_row($wpdb->prepare("SELECT c.*,p.traffic_mode,p.source_ids_json,p.shahrah_panel_id,p.shahrah_plan_slug FROM {$ct} c LEFT JOIN {$pt} p ON p.id=c.plan_id WHERE c.id=%d",$customerId),ARRAY_A);
        if(!$c)return ['ok'=>false,'message'=>'کاربر پیدا نشد.'];
        $gateway=((string)($c['traffic_mode']??'provider_reported'))==='gateway_metered';
        $last=!empty($c['last_sync_at'])?(strtotime((string)$c['last_sync_at'].' UTC')?:0):0;
        if(!$force&&$last>0&&(time()-$last)<self::SYNC_TTL_SECONDS)return ['ok'=>true,'cached'=>true,'message'=>'وضعیت ذخیره‌شده WordPress معتبر است.','traffic_mode'=>$gateway?'gateway_metered':'provider_reported'];

        if(self::customer_provider_links($customerId))return self::sync_customer_multi_links($c,$gateway);

        $u=[];$errors=[];$active=false;$providerUsed=0;$providerExpiries=[];$responses=0;$configured=0;
        $manualEntries=class_exists('BlueVPN_Subscription_Sources')?BlueVPN_Subscription_Sources::active_entries_for_plan((int)$c['plan_id']):[];
        foreach($manualEntries as $entry){
            $configured++;
            if((string)($entry['provider_type']??'')==='shahrah'){
                if(class_exists('BlueVPN_Shahrah')){
                    $mapping=BlueVPN_Shahrah::mapping((int)($entry['id']??0),$customerId);
                    if(!empty($mapping['service_slug'])){$responses++;$active=true;}
                }
            }else{$responses++;$active=true;}
        }
        if((int)($c['shahrah_panel_id']??0)>0&&trim((string)($c['shahrah_plan_slug']??''))!==''){
            $configured++;
            if(class_exists('BlueVPN_Shahrah')){
                $inspection=BlueVPN_Shahrah::inspect_panel_customer((int)$c['shahrah_panel_id'],$customerId);
                if(!empty($inspection['ok'])){
                    $responses++;
                    $active=$active||!empty($inspection['active']);
                    if(empty($inspection['active']))$errors[]='Shahrah: سرویس remote در وضعیت '.(string)($inspection['status']??'inactive').' است.';
                }else{
                    $errors[]='Shahrah: '.(string)($inspection['message']??'وضعیت سرویس قابل دریافت نیست.');
                }
            }else{
                $errors[]='Shahrah: Provider بارگذاری نشده است.';
            }
        }
        if(!empty($c['panel_id'])&&!empty($c['pg_username'])){
            $configured++;try{$p=self::panel('pasarguard',(int)$c['panel_id']);if($p){$r=self::pg_user($p,(string)$c['pg_username'],25,max(0,(int)($c['pg_user_id']??0)));$responses++;if($r){$active=$active||in_array(strtolower((string)($r['status']??'active')),['active','enabled'],true);$u['pasarguard_subscription_url']=self::remote_sub_url($r,(string)$p['base_url'])?:$c['pasarguard_subscription_url'];$providerUsed+=max(0,(int)($r['used_traffic']??$r['used_traffic_bytes']??0));$remoteExpire=self::remote_expiry($r['expire']??null);if($remoteExpire)$providerExpiries['pasarguard']=$remoteExpire;}}}catch(Throwable $e){$errors[]='PasarGuard: '.$e->getMessage();}
        }
        if(!empty($c['marzban_panel_id'])&&!empty($c['marzban_username'])){
            $configured++;try{$p=self::panel('marzban',(int)$c['marzban_panel_id']);if($p){$r=self::mz_user($p,(string)$c['marzban_username']);$responses++;if($r){$active=$active||in_array(strtolower((string)($r['status']??'active')),['active','enabled'],true);$u['marzban_subscription_url']=self::remote_sub_url($r,(string)$p['base_url'])?:$c['marzban_subscription_url'];$u['marzban_status']=(string)($r['status']??'active');$u['marzban_used_traffic_bytes']=max(0,(int)($r['used_traffic']??0));$providerUsed+=max(0,(int)($r['used_traffic']??0));$remoteExpire=self::remote_expiry($r['expire']??null);if($remoteExpire){$providerExpiries['marzban']=$remoteExpire;$u['marzban_expire']=$remoteExpire;}}}}catch(Throwable $e){$errors[]='Marzban: '.$e->getMessage();$u['marzban_last_error']=mb_substr($e->getMessage(),0,1800);}
        }
        if(!empty($c['guardcore_panel_id'])&&!empty($c['guardcore_username'])){
            $configured++;try{$p=self::panel('guardcore',(int)$c['guardcore_panel_id']);if($p&&($p['auth_mode']??'manual')!=='manual'){$r=self::gc_user($p,(string)$c['guardcore_username']);$responses++;if($r){$active=$active||($r['status']==='active');$u['guardcore_subscription_id']=is_numeric($r['id']??null)?(int)$r['id']:$c['guardcore_subscription_id'];$u['guardcore_subscription_url']=(string)($r['subscription_url']?:$c['guardcore_subscription_url']);$u['guardcore_status']=(string)$r['status'];$u['guardcore_expire']=$r['expire'];$u['guardcore_data_limit_bytes']=(int)$r['data_limit'];$u['guardcore_used_traffic_bytes']=(int)$r['used_traffic'];$u['guardcore_last_error']='';$providerUsed+=max(0,(int)$r['used_traffic']);$remoteExpire=self::remote_expiry($r['expire']??null);if($remoteExpire){$providerExpiries['guardcore']=$remoteExpire;$u['guardcore_expire']=$remoteExpire;}}}elseif(!empty($c['guardcore_subscription_url'])){$responses++;$active=true;}}catch(Throwable $e){$errors[]='GuardCore: '.$e->getMessage();$u['guardcore_last_error']=mb_substr($e->getMessage(),0,1800);}
        }elseif(!empty($c['guardcore_subscription_url'])){$configured++;$responses++;$active=true;}

        // WordPress/MySQL is the only entitlement-expiry authority. Provider expiry is diagnostic and repaired when possible.
        $canonical=self::remote_expiry($c['subscription_expire']??null);$drift=[];
        if($canonical){$canonicalTs=strtotime($canonical.' UTC')?:0;foreach($providerExpiries as $provider=>$remoteExpire){$remoteTs=strtotime($remoteExpire.' UTC')?:0;if(!$remoteTs||!$canonicalTs)continue;$delta=$remoteTs-$canonicalTs;if(abs($delta)<=self::EXPIRY_DRIFT_TOLERANCE_SECONDS)continue;$repair=self::enforce_provider_expiry($c,$provider,$canonical);$repaired=!empty($repair['ok']);$drift[$provider]=['remote'=>$remoteExpire,'canonical'=>$canonical,'delta_seconds'=>$delta,'repaired'=>$repaired,'message'=>(string)($repair['message']??'')];if($repaired){if($provider==='marzban')$u['marzban_expire']=$canonical;if($provider==='guardcore')$u['guardcore_expire']=$canonical;}else$errors[]=$provider.' expiry drift: '.(string)($repair['message']??'repair failed');}}
        if($drift&&class_exists('BlueVPN_Error_Monitor'))BlueVPN_Error_Monitor::report('entitlement','expiry_drift','warning','SUBSCRIPTION_EXPIRY_DRIFT','تاریخ انقضای Provider با Entitlement اصلی WordPress/MySQL اختلاف داشت؛ تاریخ Provider به مقدار Canonical برگردانده شد.',['customer_id'=>(int)$c['id'],'canonical_expire'=>$canonical,'providers'=>$drift]);elseif(class_exists('BlueVPN_Error_Monitor'))BlueVPN_Error_Monitor::resolve_matching('entitlement','expiry_drift','SUBSCRIPTION_EXPIRY_DRIFT');

        if(!$gateway&&$responses>0)$u['used_traffic_bytes']=$providerUsed;
        if($gateway){
            // Gateway byte events are the only quota authority. Never overwrite central usage with provider counters.
            $limit=max(0,(int)$c['data_limit_bytes']);$used=max(0,(int)$c['used_traffic_bytes']);$expired=$canonical&&(strtotime($canonical.' UTC')?:0)<=time();
            if($expired)$u['subscription_status']='expired';elseif($limit>0&&$used>=$limit)$u['subscription_status']='limited';elseif($active&&class_exists('BlueVPN_Gateway')&&BlueVPN_Gateway::has_active_gateway()){$u['subscription_status']='active';BlueVPN_Gateway::ensure_customer_sessions($customerId);}elseif($configured===0)$u['subscription_status']='inactive';
        }else{
            // Fail open on transient provider transport errors.
            if($active)$u['subscription_status']='active';elseif($configured>0&&$responses>0&&$responses===$configured&&count($errors)===0)$u['subscription_status']='inactive';
        }
        $u['last_sync_at']=BlueVPN_Utils::now_mysql();$u['last_sync_error']=implode(' | ',$errors);$wpdb->update($ct,$u,['id'=>$customerId]);
        if($configured>0)self::request_background_snapshot($customerId);
        return ['ok'=>count($errors)===0,'cached'=>false,'traffic_mode'=>$gateway?'gateway_metered':'provider_reported','provider_reported_bytes'=>$providerUsed,'central_used_bytes'=>(int)($u['used_traffic_bytes']??$c['used_traffic_bytes']),'preserved_status'=>!isset($u['subscription_status']),'message'=>$errors?implode(' | ',$errors):'همگام‌سازی انجام شد.'];
    }
    public static function attach_guardcore(int $customerId,string $url): array {
        global $wpdb;$url=esc_url_raw(trim($url));if($url==='')return ['ok'=>false,'message'=>'لینک اشتراک معتبر نیست.'];$t=BlueVPN_DB::table('customers');$ok=$wpdb->update($t,['guardcore_subscription_url'=>$url,'guardcore_status'=>'active','last_sync_at'=>BlueVPN_Utils::now_mysql()],['id'=>$customerId]);if($ok!==false)self::request_background_snapshot($customerId);return ['ok'=>$ok!==false,'message'=>$ok===false?'ذخیره نشد.':'لینک GuardCore ثبت شد.'];
    }
    private static function subscription_lines(string $text): array {
        $text=trim($text);if($text==='')return [];$decoded=base64_decode(preg_replace('/\s+/','',$text),true);if($decoded!==false&&preg_match('~(?:vless|vmess|trojan|ss|hysteria2|tuic)://~i',$decoded))$text=$decoded;
        $lines=preg_split('/\R+/',trim($text))?:[];return array_values(array_filter(array_map('trim',$lines),fn($x)=>preg_match('~^(?:vless|vmess|trojan|ss|hysteria2|tuic)://~i',$x)));
    }
    public static function reconcile_guardcore_expiries(int $limit=100): array {
        global $wpdb;
        $ct=BlueVPN_DB::table('customers');
        $now=BlueVPN_Utils::now_mysql();
        $rows=$wpdb->get_results(
            $wpdb->prepare(
                "SELECT id,guardcore_panel_id,guardcore_username
                 FROM {$ct}
                 WHERE active=1
                   AND subscription_status='active'
                   AND subscription_expire IS NOT NULL
                   AND subscription_expire<%s
                   AND guardcore_panel_id IS NOT NULL
                   AND guardcore_username<>''
                 ORDER BY subscription_expire ASC
                 LIMIT %d",
                $now,max(1,min(500,$limit))
            ),
            ARRAY_A
        )?:[];
        $disabled=0;$errors=[];
        foreach($rows as $row){
            $r=self::guardcore_subscription_action(
                (int)$row['guardcore_panel_id'],
                (string)$row['guardcore_username'],
                'disable'
            );
            if(!empty($r['ok'])){
                $wpdb->update($ct,[
                    'subscription_status'=>'inactive',
                    'guardcore_status'=>'disabled',
                    'last_sync_at'=>$now,
                ],['id'=>(int)$row['id']]);
                $disabled++;
            }else{
                $errors[]='#'.(int)$row['id'].': '.(string)($r['message']??'');
            }
        }
        return ['checked'=>count($rows),'disabled'=>$disabled,'errors'=>$errors];
    }

    private static function snapshot_option(int $customerId): string { return 'bluevpn_sub_snapshot_'.$customerId; }
    private static function snapshot_load(int $customerId): array {
        $raw=get_option(self::snapshot_option($customerId),[]);return is_array($raw)?$raw:[];
    }
    private static function snapshot_store(int $customerId,array $lines,array $errors=[],array $sourceStats=[],array $sourceLines=[]): void {
        if(!$lines)return;
        update_option(self::snapshot_option($customerId),[
            'lines'=>array_values($lines),
            'updated_at'=>time(),
            'errors'=>array_values($errors),
            'sources'=>$sourceStats,
            'source_lines'=>$sourceLines,
        ],false);
    }
    private static function expected_provider_route_keys(array $plan): array {
        $keys=[];
        foreach(self::plan_provider_routes($plan) as $provider=>$routes){
            foreach((array)$routes as $route){
                $panelId=(int)($route['panel_id']??0);
                if($panelId<=0)continue;
                $keys[self::provider_route_key((string)$provider,(array)$route)]=(string)$provider;
            }
        }
        return $keys;
    }

    private static function reconcile_snapshot_routes_if_needed(array $c): array {
        $customerId=(int)($c['id']??0);$planId=(int)($c['plan_id']??0);
        if($customerId<=0||$planId<=0)return ['needed'=>false,'repaired'=>false,'missing'=>[],'empty'=>[]];
        global $wpdb;
        $plan=$wpdb->get_row($wpdb->prepare(
            "SELECT * FROM ".BlueVPN_DB::table('plans')." WHERE id=%d AND deleted=0 LIMIT 1",
            $planId
        ),ARRAY_A);
        if(!$plan)return ['needed'=>false,'repaired'=>false,'missing'=>[],'empty'=>[]];

        $expected=self::expected_provider_route_keys($plan);
        if(!$expected)return ['needed'=>false,'repaired'=>false,'missing'=>[],'empty'=>[]];

        $linked=[];$empty=[];
        foreach(self::customer_provider_links($customerId) as $link){
            $key=trim((string)($link['route_key']??''));
            if($key==='')continue;
            $linked[$key]=true;
            $provider=(string)($link['provider_type']??'');
            // Shahrah obtains configs through its service API and does not need a
            // subscription URL in customer_provider_links.
            if($provider!=='shahrah'&&trim((string)($link['subscription_url']??''))==='')$empty[]=$key;
        }
        $missing=array_values(array_diff(array_keys($expected),array_keys($linked)));
        $empty=array_values(array_unique($empty));
        if(!$missing&&!$empty)return ['needed'=>false,'repaired'=>false,'missing'=>[],'empty'=>[]];

        // Safe repair never extends the WordPress entitlement and Shahrah repair
        // explicitly avoids renew; it only reattaches or creates truly missing routes.
        $repair=self::repair_customer_missing_providers($customerId);
        return [
            'needed'=>true,
            'repaired'=>!empty($repair['ok']),
            'missing'=>$missing,
            'empty'=>$empty,
            'message'=>(string)($repair['message']??''),
        ];
    }

    private static function customer_source_entries(array $c): array {
        $out=[];$customerId=(int)($c['id']??0);$usableLinkedProviders=[];$seenRouteKeys=[];
        foreach(self::customer_provider_links($customerId) as $link){
            $provider=(string)($link['provider_type']??'');$panelId=(int)($link['panel_id']??0);if($provider===''||$panelId<=0)continue;
            $routeKey=trim((string)($link['route_key']??''));if($routeKey==='')$routeKey=$provider.':'.$panelId;$seenRouteKeys[$routeKey]=true;
            if($provider==='shahrah'){
                $out[]=['key'=>$routeKey,'id'=>$panelId,'type'=>'shahrah_panel','provider_type'=>'shahrah','payload'=>'','route_key'=>$routeKey];
                $usableLinkedProviders[$provider]=true;
                continue;
            }
            $url=trim((string)($link['subscription_url']??''));
            if($url===''){
                $out[]=['key'=>$routeKey,'id'=>$panelId,'type'=>'provider_link_missing','provider_type'=>$provider,'payload'=>'','route_key'=>$routeKey];
                continue;
            }
            $usableLinkedProviders[$provider]=true;
            $out[]=['key'=>$routeKey,'id'=>$panelId,'type'=>'url','provider_type'=>$provider,'payload'=>$url,'route_key'=>$routeKey];
        }

        foreach([
            'pasarguard'=>'pasarguard_subscription_url',
            'marzban'=>'marzban_subscription_url',
            'guardcore'=>'guardcore_subscription_url',
        ] as $provider=>$field){
            if(isset($usableLinkedProviders[$provider]))continue;
            $url=trim((string)($c[$field]??''));if($url==='')continue;
            $out[]=['key'=>$provider.':legacy','type'=>'url','provider_type'=>$provider,'payload'=>$url,'route_key'=>$provider.':legacy'];
        }

        $planId=(int)($c['plan_id']??0);
        if($planId>0){
            global $wpdb;
            $plan=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".BlueVPN_DB::table('plans')." WHERE id=%d AND deleted=0",$planId),ARRAY_A);
            if($plan){
                foreach(self::plan_provider_routes($plan) as $provider=>$routes){
                    foreach((array)$routes as $route){
                        $panelId=(int)($route['panel_id']??0);if($panelId<=0)continue;
                        $key=self::provider_route_key((string)$provider,(array)$route);
                        if(isset($seenRouteKeys[$key]))continue;
                        $seenRouteKeys[$key]=true;
                        if($provider==='shahrah'){
                            $out[]=['key'=>$key,'id'=>$panelId,'type'=>'shahrah_panel','provider_type'=>'shahrah','payload'=>'','route_key'=>$key];
                        }else{
                            $out[]=['key'=>$key,'id'=>$panelId,'type'=>'provider_link_missing','provider_type'=>(string)$provider,'payload'=>'','route_key'=>$key,'expected_from_plan'=>true];
                        }
                    }
                }
            }
        }

        if(class_exists('BlueVPN_Subscription_Sources')){
            foreach(BlueVPN_Subscription_Sources::active_entries_for_plan($planId) as $entry){
                $key=(string)($entry['key']??'manual');$type=(string)($entry['type']??'url');$payload=trim((string)($entry['payload']??''));if($payload==='')continue;
                $out[]=['key'=>$key,'id'=>(int)($entry['id']??0),'type'=>$type,'provider_type'=>(string)($entry['provider_type']??''),'payload'=>$payload,'route_key'=>$key];
            }
        }
        return $out;
    }
    public static function refresh_subscription_snapshot(int $customerId): array {
        global $wpdb;$t=BlueVPN_DB::table('customers');$c=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d AND active=1 LIMIT 1",$customerId),ARRAY_A);if(!$c)return ['ok'=>false,'message'=>'customer not found'];
        $routeReconcile=self::reconcile_snapshot_routes_if_needed($c);
        // Re-read links after repair so every newly attached provider participates
        // in this same snapshot instead of waiting for another cron cycle.
        $sources=self::customer_source_entries($c);$lines=[];$seen=[];$errors=[];$successSources=0;$sourceStats=[];$freshSourceLines=[];
        foreach($sources as $source){
            $provider=(string)($source['key']??'source');$type=(string)($source['type']??'url');$payload=(string)($source['payload']??'');$providerLines=[];
            if($type==='shahrah_panel'){
                try{
                    if(!class_exists('BlueVPN_Shahrah'))throw new RuntimeException('Shahrah provider unavailable');
                    $result=BlueVPN_Shahrah::configs_for_panel_customer((int)($source['id']??0),$customerId);
                    $providerLines=array_values((array)($result['lines']??[]));
                    if($providerLines)$successSources++;
                    $sourceStats[$provider]=['ok'=>!empty($providerLines),'count'=>count($providerLines),'service_slug'=>(string)($result['service_slug']??''),'content_hash'=>$providerLines?hash('sha256',implode("\n",$providerLines)):'','updated_at'=>time()];
                    if(!$providerLines)$errors[]=$provider.': '.(string)($result['message']??'Shahrah service has no usable configs');
                }catch(Throwable $e){
                    $errors[]=$provider.': '.$e->getMessage();
                    $sourceStats[$provider]=['ok'=>false,'count'=>0,'error'=>$e->getMessage()];
                }
            }elseif($type==='shahrah'){
                try{
                    if(!class_exists('BlueVPN_Shahrah'))throw new RuntimeException('Shahrah provider unavailable');
                    $cfg=BlueVPN_Subscription_Sources::shahrah_payload($payload);
                    if(($cfg['api_key']??'')==='')throw new RuntimeException('Shahrah API KEY missing');
                    $result=BlueVPN_Shahrah::configs_for_customer((int)($source['id']??0),$customerId,(string)$cfg['api_key']);
                    $providerLines=array_values((array)($result['lines']??[]));
                    if($providerLines)$successSources++;
                    $sourceStats[$provider]=['ok'=>!empty($providerLines),'count'=>count($providerLines),'service_slug'=>(string)($result['service_slug']??''),'content_hash'=>$providerLines?hash('sha256',implode("\n",$providerLines)):'','updated_at'=>time()];
                    if(!$providerLines)$errors[]=$provider.': '.(string)($result['message']??'Shahrah service has no usable configs');
                }catch(Throwable $e){
                    $errors[]=$provider.': '.$e->getMessage();
                    $sourceStats[$provider]=['ok'=>false,'count'=>0,'error'=>$e->getMessage()];
                }
            }elseif($type==='provider_link_missing'){
                $message='مسیر تأمین‌کننده برای مشتری ساخته شده اما آدرس اشتراک قابل استفاده ثبت نشده است.';
                $errors[]=$provider.': '.$message;
                $sourceStats[$provider]=[
                    'ok'=>false,
                    'count'=>0,
                    'error'=>$message,
                    'provider_type'=>(string)($source['provider_type']??''),
                    'panel_id'=>(int)($source['id']??0),
                    'route_key'=>(string)($source['route_key']??$provider),
                    'missing_subscription_url'=>true,
                    'updated_at'=>time(),
                ];
            }elseif($type==='inline'){
                $providerLines=self::subscription_lines($payload);if($providerLines)$successSources++;
                $sourceStats[$provider]=['ok'=>!empty($providerLines),'count'=>count($providerLines),'payload_hash'=>hash('sha256',$payload),'content_hash'=>hash('sha256',implode("\n",$providerLines)),'updated_at'=>time()];
                if(!$providerLines)$errors[]=$provider.': inline source has no usable configs';
            }else{
                $url=$payload;
                if(class_exists('BlueVPN_Subscription_Sources')){
                    // All token-bearing provider subscription URLs use the same
                    // SSRF-safe, retrying, cache-aware fetcher as manual sources.
                    // Its internal requests are Sentinel-suppressed because a stale
                    // last-known-good snapshot is the expected degradation path.
                    $fetched=BlueVPN_Subscription_Sources::fetch_url_configs($url);
                    if(empty($fetched['ok'])){
                        $message=(string)($fetched['message']??'Subscription Source unavailable');
                        $errors[]=$provider.': '.$message;
                        $sourceStats[$provider]=['ok'=>false,'count'=>0,'url_hash'=>hash('sha256',$url),'error'=>$message,'endpoint'=>(string)($fetched['endpoint']??'')];
                        continue;
                    }
                    $providerLines=array_values((array)($fetched['lines']??[]));if($providerLines)$successSources++;
                    $sourceStats[$provider]=['ok'=>true,'count'=>count($providerLines),'url_hash'=>hash('sha256',$url),'content_hash'=>hash('sha256',implode("\n",$providerLines)),'updated_at'=>time(),'stale'=>!empty($fetched['stale']),'cache_age'=>max(0,(int)($fetched['cache_age']??0)),'endpoint'=>(string)($fetched['endpoint']??'')];
                    if(!$providerLines)$errors[]=$provider.': source has no usable configs';
                }else{
                    // Defensive fallback for partial plugin loads: never expose a
                    // token-bearing upstream URL as a raw Sentinel runtime incident.
                    $r=wp_remote_get($url,['timeout'=>8,'redirection'=>2,'sslverify'=>true,'headers'=>['User-Agent'=>'BlueVPN-WordPress/'.BLUEVPN_MANAGER_VERSION,'Accept'=>'text/plain,*/*','X-BlueVPN-Sentinel-Ignore'=>'1']]);
                    if(is_wp_error($r)){$errors[]=$provider.': '.$r->get_error_message();$sourceStats[$provider]=['ok'=>false,'count'=>0,'url_hash'=>hash('sha256',$url),'error'=>$r->get_error_message()];continue;}
                    $code=(int)wp_remote_retrieve_response_code($r);if($code>=400){$errors[]=$provider.': HTTP '.$code;$sourceStats[$provider]=['ok'=>false,'count'=>0,'url_hash'=>hash('sha256',$url),'error'=>'HTTP '.$code];continue;}
                    $providerLines=self::subscription_lines((string)wp_remote_retrieve_body($r));if($providerLines)$successSources++;$sourceStats[$provider]=['ok'=>!empty($providerLines),'count'=>count($providerLines),'url_hash'=>hash('sha256',$url),'content_hash'=>hash('sha256',implode("\n",$providerLines)),'updated_at'=>time()];if(!$providerLines)$errors[]=$provider.': source has no usable configs';
                }
            }
            if($providerLines)$freshSourceLines[$provider]=array_values($providerLines);
            foreach($providerLines as $line){$key=sha1($line);if(isset($seen[$key]))continue;$seen[$key]=1;$lines[]=$line;}
        }
        $old=self::snapshot_load($customerId);
        // Merge per-source last-known-good data. A failing provider cannot erase
        // its previous configs, while a newly-added healthy provider is delivered
        // immediately instead of being hidden behind an all-or-nothing snapshot.
        $complete=$successSources===count($sources)&&count($errors)===0;
        $oldSourceLines=is_array($old['source_lines']??null)?$old['source_lines']:[];
        $effectiveSourceLines=$complete?$freshSourceLines:array_merge($oldSourceLines,$freshSourceLines);
        $effective=[];$effectiveSeen=[];
        foreach($effectiveSourceLines as $providerLines)foreach((array)$providerLines as $line){$key=sha1((string)$line);if(isset($effectiveSeen[$key]))continue;$effectiveSeen[$key]=1;$effective[]=(string)$line;}
        // One-time migration from aggregate-only snapshots: preserve the old
        // aggregate during a partial refresh, then naturally retire it after the
        // first complete per-source refresh.
        if(!$complete&&empty($oldSourceLines))foreach((array)($old['lines']??[]) as $line){$key=sha1((string)$line);if(isset($effectiveSeen[$key]))continue;$effectiveSeen[$key]=1;$effective[]=(string)$line;}
        if(!$effective)$effective=$lines;
        $effectiveSources=array_replace((array)($old['sources']??[]),$sourceStats);
        if($effective)self::snapshot_store($customerId,$effective,$errors,$effectiveSources,$effectiveSourceLines);
        return [
            'ok'=>!empty($effective),
            'fresh'=>$complete,
            'lines'=>$effective,
            'sources'=>$effectiveSources,
            'errors'=>$errors,
            'route_reconcile'=>$routeReconcile,
        ];
    }
    public static function gateway_upstream_pool(int $customerId): array {
        if($customerId<=0)return [];$snapshot=self::snapshot_load($customerId);$age=!empty($snapshot['updated_at'])?time()-(int)$snapshot['updated_at']:PHP_INT_MAX;$lines=is_array($snapshot['lines']??null)?array_values($snapshot['lines']):[];
        if(!$lines){$result=self::refresh_subscription_snapshot($customerId);$lines=array_values((array)($result['lines']??[]));}
        elseif($age>self::SNAPSHOT_FRESH_SECONDS)self::request_background_snapshot($customerId);
        return $lines;
    }

    public static function subscription_route_audit(int $customerId): array {
        if($customerId<=0)return ['ok'=>false,'routes'=>[],'expected'=>0,'healthy'=>0,'missing'=>0];
        global $wpdb;
        $ct=BlueVPN_DB::table('customers');$pt=BlueVPN_DB::table('plans');
        $c=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$ct} WHERE id=%d LIMIT 1",$customerId),ARRAY_A);
        if(!$c)return ['ok'=>false,'routes'=>[],'expected'=>0,'healthy'=>0,'missing'=>0];
        $planId=(int)($c['plan_id']??0);
        $plan=$planId>0?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$pt} WHERE id=%d AND deleted=0 LIMIT 1",$planId),ARRAY_A):null;
        $snapshot=self::snapshot_load($customerId);
        $stats=is_array($snapshot['sources']??null)?$snapshot['sources']:[];
        $links=[];foreach(self::customer_provider_links($customerId) as $link){
            $key=trim((string)($link['route_key']??''));if($key!=='')$links[$key]=$link;
        }
        $routes=[];
        if($plan){
            foreach(self::plan_provider_routes($plan) as $provider=>$providerRoutes){
                foreach((array)$providerRoutes as $route){
                    $key=self::provider_route_key((string)$provider,(array)$route);
                    $link=$links[$key]??null;$stat=is_array($stats[$key]??null)?$stats[$key]:[];
                    $count=max(0,(int)($stat['count']??0));
                    $linked=is_array($link);
                    $usable=$provider==='shahrah'
                        ? $linked
                        : ($linked&&trim((string)($link['subscription_url']??''))!=='');
                    $healthy=$usable&&$count>0&&!empty($stat['ok']);
                    $routes[]=[
                        'key'=>$key,'provider'=>(string)$provider,'panel_id'=>(int)($route['panel_id']??0),
                        'linked'=>$linked,'usable'=>$usable,'healthy'=>$healthy,'config_count'=>$count,
                        'status'=>$healthy?'healthy':($usable?'empty_snapshot':'missing_link'),
                        'error'=>(string)($stat['error']??$link['last_error']??''),
                    ];
                }
            }
        }
        if(class_exists('BlueVPN_Subscription_Sources')){
            foreach(BlueVPN_Subscription_Sources::active_entries_for_plan($planId) as $entry){
                $key=(string)($entry['key']??('manual:'.(int)($entry['id']??0)));
                $stat=is_array($stats[$key]??null)?$stats[$key]:[];
                $count=max(0,(int)($stat['count']??0));$healthy=$count>0&&!empty($stat['ok']);
                $routes[]=[
                    'key'=>$key,'provider'=>(string)($entry['provider_type']??'source'),'panel_id'=>(int)($entry['id']??0),
                    'linked'=>true,'usable'=>true,'healthy'=>$healthy,'config_count'=>$count,
                    'status'=>$healthy?'healthy':'empty_snapshot','error'=>(string)($stat['error']??''),
                ];
            }
        }
        $healthy=count(array_filter($routes,static fn($r)=>!empty($r['healthy'])));
        $missing=count(array_filter($routes,static fn($r)=>empty($r['healthy'])));
        return [
            'ok'=>$missing===0&&count($routes)>0,
            'routes'=>$routes,
            'expected'=>count($routes),
            'healthy'=>$healthy,
            'missing'=>$missing,
            'snapshot_updated_at'=>(int)($snapshot['updated_at']??0),
            'total_configs'=>is_array($snapshot['lines']??null)?count($snapshot['lines']):0,
        ];
    }

    public static function subscription_snapshot_stats(int $customerId): array {
        $snapshot=self::snapshot_load($customerId);
        $sources=is_array($snapshot['sources']??null)?$snapshot['sources']:[];
        $routeCounts=[];$providerCounts=[];$missingRoutes=[];
        foreach($sources as $key=>$stat){
            if(!is_array($stat))continue;
            $count=max(0,(int)($stat['count']??0));
            $routeCounts[(string)$key]=$count;
            $provider=(string)($stat['provider_type']??'');
            if($provider===''){
                $prefix=strtolower((string)strtok((string)$key,':'));
                $provider=in_array($prefix,['pasarguard','marzban','guardcore','shahrah'],true)?$prefix:'source';
            }
            $providerCounts[$provider]=($providerCounts[$provider]??0)+$count;
            if(empty($stat['ok'])||!empty($stat['missing_subscription_url']))$missingRoutes[]=(string)$key;
        }
        $guardcoreCount=(int)($providerCounts['guardcore']??0);
        $guardcoreOk=$guardcoreCount>0;
        $guardcoreHashes=[];
        foreach($sources as $key=>$stat){
            if(!is_array($stat)||!str_starts_with((string)$key,'guardcore'))continue;
            $hash=trim((string)($stat['content_hash']??''));if($hash!=='')$guardcoreHashes[]=$hash;
            if(empty($stat['ok']))$guardcoreOk=false;
        }
        return [
            'updated_at'=>(int)($snapshot['updated_at']??0),
            'total_count'=>is_array($snapshot['lines']??null)?count($snapshot['lines']):0,
            'route_counts'=>$routeCounts,
            'provider_counts'=>$providerCounts,
            'missing_routes'=>array_values(array_unique($missingRoutes)),
            'source_count'=>count($sources),
            'guardcore_count'=>$guardcoreCount,
            'guardcore_ok'=>$guardcoreOk,
            'guardcore_content_hash'=>$guardcoreHashes?hash('sha256',implode('|',$guardcoreHashes)):'',
        ];
    }

    public static function request_background_snapshot(int $customerId): bool {
        if($customerId<=0)return false;
        if(wp_next_scheduled('bluevpn_refresh_subscription_snapshot',[$customerId]))return false;
        $ok=wp_schedule_single_event(time()+2,'bluevpn_refresh_subscription_snapshot',[$customerId]);
        if($ok!==false)BlueVPN_Utils::kick_wp_cron();
        return $ok!==false;
    }
    public static function serve_subscription(): void {
        $path=(string)(parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH)??'');if(!preg_match('~^/sub/([A-Za-z0-9_-]{10,100})/?$~',$path,$m))return;
        global $wpdb;$t=BlueVPN_DB::table('customers');$c=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE subscription_token=%s AND active=1 LIMIT 1",$m[1]),ARRAY_A);if(!$c){status_header(404);header('Content-Type: text/plain; charset=utf-8');echo 'subscription not found';exit;}
        $gateway=class_exists('BlueVPN_Gateway')&&BlueVPN_Gateway::is_gateway_metered_customer($c);
        if(!self::subscription_entitlement_allows($c)){status_header(403);header('Content-Type: text/plain; charset=utf-8');header('X-BlueVPN-Traffic-Mode: '.($gateway?'gateway_metered':'provider_reported'));echo 'subscription quota exhausted, inactive or expired';exit;}
        $age=0;$lines=[];
        if($gateway){$lines=BlueVPN_Gateway::gateway_subscription_lines($c);if(!$lines){status_header(503);header('Content-Type: text/plain; charset=utf-8');header('Retry-After: 20');header('X-BlueVPN-Traffic-Mode: gateway_metered');echo 'BlueVPN gateway unavailable';exit;}}
        else{$snapshot=self::snapshot_load((int)$c['id']);$age=!empty($snapshot['updated_at'])?time()-(int)$snapshot['updated_at']:PHP_INT_MAX;$lines=is_array($snapshot['lines']??null)?$snapshot['lines']:[];if(!$lines){$result=self::refresh_subscription_snapshot((int)$c['id']);$lines=(array)($result['lines']??[]);}elseif($age>self::SNAPSHOT_FRESH_SECONDS)self::request_background_snapshot((int)$c['id']);if(!$lines){status_header(502);header('Content-Type: text/plain; charset=utf-8');echo 'No usable configs';exit;}}
        header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: private, max-age=60, stale-while-revalidate=300');header('profile-title: base64:'.base64_encode('BlueVPN'));header('profile-update-interval: 24');
        $expiry=!empty($c['subscription_expire'])?strtotime((string)$c['subscription_expire'].' UTC'):0;header('subscription-userinfo: upload=0; download='.(int)$c['used_traffic_bytes'].'; total='.(int)$c['data_limit_bytes'].'; expire='.(int)$expiry);header('X-BlueVPN-Expiry-Source: wordpress_mysql_entitlement');header('X-BlueVPN-Traffic-Mode: '.($gateway?'gateway_metered':'provider_reported'));header('X-BlueVPN-Config-Count: '.count($lines));header('X-BlueVPN-Snapshot-Age: '.($gateway?0:($age===PHP_INT_MAX?0:max(0,$age))));
        echo base64_encode(implode("\n",$lines)."\n");exit;
    }

    private static function subscription_entitlement_allows(array $c): bool {
        if(!(int)($c['active']??0))return false;
        $status=strtolower(trim((string)($c['subscription_status']??'inactive')))?:'inactive';
        if(in_array($status,['disabled','expired','limited','blocked','deleted','revoked'],true))return false;
        $expiry=!empty($c['subscription_expire'])?(strtotime((string)$c['subscription_expire'].' UTC')?:0):0;
        if($expiry>0&&$expiry<=time()-120)return false;
        $limit=max(0,(int)($c['data_limit_bytes']??0));$used=max(0,(int)($c['used_traffic_bytes']??0));
        if($limit>0&&$used>=$limit)return false;
        $providerUncertain=trim((string)($c['last_sync_error']??''))!==''&&!empty($c['plan_id']);
        return $status==='active'||$providerUncertain;
    }


}
