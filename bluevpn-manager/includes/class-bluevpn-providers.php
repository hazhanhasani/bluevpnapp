<?php
if (!defined('ABSPATH')) exit;

final class BlueVPN_Providers {
    private const SYNC_TTL_SECONDS = 300;
    private const SNAPSHOT_FRESH_SECONDS = 300;
    private const SNAPSHOT_STALE_SECONDS = 21600;

    public static function init(): void {
        add_action('template_redirect',[self::class,'serve_subscription'],0);
        add_action('bluevpn_refresh_subscription_snapshot',[self::class,'refresh_subscription_snapshot'],10,1);
        add_action('bluevpn_sync_customer_async',[self::class,'async_sync_customer'],10,1);
    }

    private static function panel(string $provider,int $id): ?array {
        global $wpdb;
        $map=['pasarguard'=>'pasarguard_panels','marzban'=>'marzban_panels','guardcore'=>'guardcore_panels'];
        if(!isset($map[$provider])||$id<=0)return null;
        $t=BlueVPN_DB::table($map[$provider]);
        $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d",$id),ARRAY_A);
        return is_array($r)?$r:null;
    }
    private static function req(string $method,string $url,array $headers=[],?array $json=null,bool $ssl=true,array $form=[],int $timeout=25): array {
        $args=['method'=>$method,'timeout'=>max(3,min(25,$timeout)),'redirection'=>2,'sslverify'=>$ssl,'headers'=>array_merge(['Accept'=>'application/json','User-Agent'=>'BlueVPN-WordPress/'.BLUEVPN_MANAGER_VERSION],$headers)];
        if($json!==null){$args['headers']['Content-Type']='application/json';$args['body']=wp_json_encode($json);}
        elseif($form){$args['body']=$form;}
        $res=wp_remote_request($url,$args);
        if(is_wp_error($res))throw new RuntimeException($res->get_error_message());
        $code=(int)wp_remote_retrieve_response_code($res);$body=(string)wp_remote_retrieve_body($res);
        $decoded=json_decode($body,true);
        return ['code'=>$code,'body'=>$body,'json'=>is_array($decoded)?$decoded:[],'headers'=>wp_remote_retrieve_headers($res)];
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
    public static function test(string $provider,int $id): array {
        try{
            $p=self::panel($provider,$id);if(!$p)throw new RuntimeException('پنل پیدا نشد.');
            $base=(string)$p['base_url'];$ssl=(bool)$p['verify_tls'];
            if($provider==='pasarguard'){
                $r=self::req('GET',self::join_url($base,'/api/users?limit=1&offset=0'),self::pg_headers($p),null,$ssl);
                $ok=$r['code']===200;$msg=$ok?'اتصال و دسترسی کاربران موفق بود.':'HTTP '.$r['code'].' '.mb_substr($r['body'],0,220);
                if($ok){$groups=self::pg_active_group_ids($p,[]);$msg.=' '.count($groups).' گروه فعال برای تخصیص خودکار پیدا شد.';}
            }elseif($provider==='marzban'){
                $h=self::mz_headers($p);$r=self::req('GET',self::join_url($base,'/api/admin'),$h,null,$ssl);
                $ok=$r['code']===200;$msg=$ok?'ورود مدیر Marzban موفق بود.':'HTTP '.$r['code'].' '.mb_substr($r['body'],0,220);
                if($ok){[$px,$ib]=self::mz_access($p);$count=0;foreach($ib as $tags)$count+=is_array($tags)?count($tags):0;$msg.=' '.$count.' Inbound فعال برای تخصیص خودکار پیدا شد.';}
            }else{
                if(($p['auth_mode']??'manual')==='manual'){$ok=true;$msg='GuardCore در حالت دستی فعال است.';}
                else{
                    $h=self::gc_headers($p);$r=self::req('GET',self::join_url($base,'/api/admins/current'),$h,null,$ssl);$ok=$r['code']<400;$msg=$ok?'ورود مدیر GuardCore موفق بود.':'HTTP '.$r['code'].' '.mb_substr($r['body'],0,220);
                    if($ok){
                        $catalog=self::guardcore_catalog($id,true);
                        if(!empty($catalog['ok'])){
                            $msg.=' API '.($catalog['version']??'').' • '.count((array)($catalog['services']??[])).' Service • '.count((array)($catalog['nodes']??[])).' Node دریافت شد.';
                        }else{
                            $ok=false;$msg.=' همگام‌سازی Catalog ناموفق: '.($catalog['message']??'');
                        }
                    }
                }
            }
            self::store_test($provider,$id,$ok,$msg);return ['ok'=>$ok,'message'=>$msg];
        }catch(Throwable $e){self::store_test($provider,$id,false,$e->getMessage());return ['ok'=>false,'message'=>$e->getMessage()];}
    }
    private static function store_test(string $provider,int $id,bool $ok,string $msg): void {
        global $wpdb;$map=['pasarguard'=>'pasarguard_panels','marzban'=>'marzban_panels','guardcore'=>'guardcore_panels'];if(!isset($map[$provider]))return;
        $wpdb->update(BlueVPN_DB::table($map[$provider]),['last_test_ok'=>$ok?1:0,'last_test_message'=>mb_substr($msg,0,1800),'last_test_at'=>BlueVPN_Utils::now_mysql()],['id'=>$id]);
    }
    private static function pg_user(array $p,string $username,int $timeout=25): ?array {
        $r=self::req('GET',self::join_url((string)$p['base_url'],'/api/user/by-username/'.rawurlencode($username)),self::pg_headers($p,$timeout),null,(bool)$p['verify_tls'],[],$timeout);
        if($r['code']===404)return null;if($r['code']>=400)throw new RuntimeException('خواندن کاربر PasarGuard ناموفق: HTTP '.$r['code']);return $r['json'];
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
            foreach(['/api/groups','/api/groups/simple'] as $path){
                $r=self::req('GET',self::join_url($base,$path),$headers,null,$ssl,[],$timeout);
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
        foreach(['/api/groups','/api/groups/simple'] as $path){
            try{$r=self::req('GET',self::join_url($base,$path),$headers,null,$ssl,[],$timeout);}catch(Throwable $e){$last=$e->getMessage();continue;}
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
    private static function target_expiry(array $c,array $plan): ?string {
        $days=(int)($plan['duration_days']??0);if($days<=0)return null;$base=time();if(!empty($c['subscription_expire'])){$t=strtotime((string)$c['subscription_expire'].' UTC');if($t&&$t>$base)$base=$t;}return gmdate('Y-m-d H:i:s',$base+$days*DAY_IN_SECONDS);
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
    public static function repair_customer_missing_providers(int $customerId): array {
        global $wpdb;
        $ct=BlueVPN_DB::table('customers');$pt=BlueVPN_DB::table('plans');
        $c=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$ct} WHERE id=%d LIMIT 1",$customerId),ARRAY_A);
        if(!$c)return ['ok'=>false,'eligible'=>false,'message'=>'کاربر پیدا نشد.','repaired'=>0,'created'=>0,'attached'=>0,'existing'=>0,'errors'=>['کاربر پیدا نشد.']];
        if(!(int)$c['active']||(string)$c['subscription_status']!=='active'||empty($c['plan_id']))return ['ok'=>true,'eligible'=>false,'message'=>'کاربر اشتراک فعال قابل ترمیم ندارد.','repaired'=>0,'created'=>0,'attached'=>0,'existing'=>0,'errors'=>[]];
        if(!empty($c['subscription_expire'])){$expTs=strtotime((string)$c['subscription_expire'].' UTC');if($expTs&&$expTs<=time())return ['ok'=>true,'eligible'=>false,'message'=>'اشتراک کاربر منقضی شده و برای Provider ساخته نشد.','repaired'=>0,'created'=>0,'attached'=>0,'existing'=>0,'errors'=>[]];}
        $plan=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$pt} WHERE id=%d AND deleted=0 LIMIT 1",(int)$c['plan_id']),ARRAY_A);
        if(!$plan)return ['ok'=>false,'eligible'=>false,'message'=>'پلن فعلی کاربر پیدا نشد.','repaired'=>0,'created'=>0,'attached'=>0,'existing'=>0,'errors'=>['پلن پیدا نشد.']];
        $pgId=(int)($plan['panel_id']??0);$mzId=(int)($plan['marzban_panel_id']??0);$gcId=(int)($plan['guardcore_panel_id']??0);
        if($pgId<=0)$pgId=class_exists('BlueVPN_AI_Ops')?BlueVPN_AI_Ops::recommend_panel_id('pasarguard'):(int)$wpdb->get_var("SELECT id FROM ".BlueVPN_DB::table('pasarguard_panels')." WHERE active=1 ORDER BY id ASC LIMIT 1");
        if($mzId<=0)$mzId=class_exists('BlueVPN_AI_Ops')?BlueVPN_AI_Ops::recommend_panel_id('marzban'):(int)$wpdb->get_var("SELECT id FROM ".BlueVPN_DB::table('marzban_panels')." WHERE active=1 ORDER BY id ASC LIMIT 1");
        if($gcId<=0)$gcId=(int)$wpdb->get_var("SELECT id FROM ".BlueVPN_DB::table('guardcore_panels')." WHERE active=1 AND auth_mode='manual' AND global_subscription_url IS NOT NULL AND TRIM(global_subscription_url)<>'' ORDER BY id ASC LIMIT 1");
        $expectsPg=$pgId>0;$expectsMz=$mzId>0;$expectsGc=$gcId>0;
        if(!$expectsPg&&!$expectsMz&&!$expectsGc)return ['ok'=>true,'eligible'=>false,'message'=>'هیچ Provider فعال یا Global Subscription قابل ترمیم وجود ندارد.','repaired'=>0,'created'=>0,'attached'=>0,'existing'=>0,'errors'=>[]];

        $expire=!empty($c['subscription_expire'])?(string)$c['subscription_expire']:null;
        $total=max(0,(int)($c['data_limit_bytes']??0));
        if($total===0&&max(0,(int)($plan['data_limit_gb']??0))>0)$total=max(0,(int)$plan['data_limit_gb'])*1024*1024*1024;
        $providerCount=count(array_filter([$expectsPg,$expectsMz,$expectsGc]));
        $quota=($providerCount>1&&($plan['multi_provider_quota_mode']??'split')==='split')?intdiv($total,max(1,$providerCount)):$total;
        $deviceLimit=max(1,(int)($c['device_limit']??$plan['device_limit']??1));
        $update=[];$created=0;$attached=0;$existing=0;$errors=[];$details=[];

        if($expectsPg){
            try{
                $p=self::panel('pasarguard',$pgId);if(!$p||!(int)$p['active'])throw new RuntimeException('پنل PasarGuard فعال پیدا نشد.');
                $fallbackGroups=BlueVPN_Utils::json_decode_array((string)$plan['group_ids_json'],[]);$groupIds=self::pg_active_group_ids($p,$fallbackGroups,10);
                $u=self::username($c,'pg');$remote=self::pg_user($p,$u,10);$wasMapped=((int)($c['panel_id']??0)===(int)$p['id']&&trim((string)($c['pg_username']??''))!==''&&trim((string)($c['pasarguard_subscription_url']??''))!=='');
                if(!$remote){
                    $payload=['username'=>$u,'status'=>'active','expire'=>$expire?gmdate('Y-m-d\TH:i:s\Z',strtotime($expire.' UTC')):0,'data_limit'=>$quota,'data_limit_reset_strategy'=>'no_reset','group_ids'=>$groupIds,'hwid_limit'=>$deviceLimit<=1?1:2,'note'=>'BlueVPN repair; customer '.$customerId];
                    $proxySettings=self::pg_proxy_settings($p);if($proxySettings)$payload['proxy_settings']=$proxySettings;
                    $r=self::req('POST',self::join_url((string)$p['base_url'],'/api/user'),self::pg_headers($p,10),$payload,(bool)$p['verify_tls'],[],12);
                    if($r['code']>=400)throw new RuntimeException('ساخت کاربر ناموفق: HTTP '.$r['code'].' '.mb_substr($r['body'],0,420));
                    $remote=$r['json']?:self::pg_user($p,$u,10)?:[];$created++;$details['pasarguard']='created';
                }else{
                    $sync=self::req('PUT',self::join_url((string)$p['base_url'],'/api/user/by-username/'.rawurlencode($u)),self::pg_headers($p,10),['group_ids'=>$groupIds],(bool)$p['verify_tls'],[],12);
                    if(in_array($sync['code'],[404,405],true))$sync=self::req('PUT',self::join_url((string)$p['base_url'],'/api/user/'.rawurlencode($u)),self::pg_headers($p,10),['group_ids'=>$groupIds],(bool)$p['verify_tls'],[],12);
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
                    $payload=['username'=>$u,'status'=>'active','expire'=>$expire?strtotime($expire.' UTC'):0,'data_limit'=>$quota,'data_limit_reset_strategy'=>'no_reset','proxies'=>$proxies,'inbounds'=>$inbounds,'note'=>'BlueVPN repair; customer '.$customerId];
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
                $p=self::panel('guardcore',$gcId);$global=$p?trim((string)($p['global_subscription_url']??'')):'';
                if(!$p||!(int)$p['active']||($p['auth_mode']??'manual')!=='manual'||$global==='')throw new RuntimeException('Global Subscription فعال پیدا نشد.');
                $wasMapped=((int)($c['guardcore_panel_id']??0)===(int)$p['id']&&trim((string)($c['guardcore_subscription_url']??''))!=='');
                $update['guardcore_panel_id']=(int)$p['id'];$update['guardcore_username']=self::username($c,'gc');$update['guardcore_subscription_url']=esc_url_raw($global);$update['guardcore_status']='active';$update['guardcore_expire']=$expire;$update['guardcore_last_error']='';
                if($wasMapped){$existing++;$details['guardcore']='global_existing';}else{$attached++;$details['guardcore']='global_attached';}
            }catch(Throwable $e){$errors[]='GuardCore: '.$e->getMessage();$details['guardcore']='error';}
        }

        $repaired=$created+$attached;
        if($repaired>0||$update){
            if(empty($c['subscription_token']))$update['subscription_token']=BlueVPN_Utils::random_token(30);
            $token=(string)($update['subscription_token']??$c['subscription_token']??'');if($token!=='')$update['subscription_url']=home_url('/sub/'.$token);
            $update['last_sync_at']=BlueVPN_Utils::now_mysql();$update['last_sync_error']=implode(' | ',$errors);
            $wpdb->update($ct,$update,['id'=>$customerId]);
            if($repaired>0)self::request_background_snapshot($customerId);
        }
        $ok=count($errors)===0;
        $message=$repaired>0?('ترمیم شد: '.$created.' ساخته شد، '.$attached.' اتصال محلی بازیابی شد.'):(($existing>0&&$ok)?'اشتراک‌های Provider موجود و سالم هستند.':($errors?implode(' | ',$errors):'نیازی به ترمیم نبود.'));
        return ['ok'=>$ok,'eligible'=>true,'message'=>$message,'repaired'=>$repaired,'created'=>$created,'attached'=>$attached,'existing'=>$existing,'errors'=>$errors,'details'=>$details];
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

    public static function provision_customer(int $customerId,int $planId): array {
        global $wpdb;$ct=BlueVPN_DB::table('customers');$pt=BlueVPN_DB::table('plans');$c=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$ct} WHERE id=%d",$customerId),ARRAY_A);$plan=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$pt} WHERE id=%d AND deleted=0",$planId),ARRAY_A);
        if(!$c||!$plan)return ['ok'=>false,'message'=>'کاربر یا پلن پیدا نشد.'];

        // Backward-safe provider routing: older plans created before provider
        // routing existed have zero provider ids. In that case an active
        // PasarGuard and Marzban are selected automatically instead of silently
        // producing a WordPress-only entitlement with no real VPN account.
        $pgId=(int)($plan['panel_id']??0);$mzId=(int)($plan['marzban_panel_id']??0);$gcId=(int)($plan['guardcore_panel_id']??0);
        if($pgId<=0)$pgId=class_exists('BlueVPN_AI_Ops')?BlueVPN_AI_Ops::recommend_panel_id('pasarguard'):(int)$wpdb->get_var("SELECT id FROM ".BlueVPN_DB::table('pasarguard_panels')." WHERE active=1 ORDER BY id ASC LIMIT 1");
        if($mzId<=0)$mzId=class_exists('BlueVPN_AI_Ops')?BlueVPN_AI_Ops::recommend_panel_id('marzban'):(int)$wpdb->get_var("SELECT id FROM ".BlueVPN_DB::table('marzban_panels')." WHERE active=1 ORDER BY id ASC LIMIT 1");
        // A configured shared/global subscription is itself a valid paid source.
        // Auto-attach only manual GuardCore rows here; API GuardCore still needs
        // explicit plan service_ids and must not be guessed.
        if($gcId<=0)$gcId=(int)$wpdb->get_var("SELECT id FROM ".BlueVPN_DB::table('guardcore_panels')." WHERE active=1 AND auth_mode='manual' AND global_subscription_url IS NOT NULL AND TRIM(global_subscription_url)<>'' ORDER BY id ASC LIMIT 1");

        $expire=self::target_expiry($c,$plan);$total=max(0,(int)$plan['data_limit_gb'])*1024*1024*1024;$providers=array_values(array_filter(['pg'=>$pgId>0,'mz'=>$mzId>0,'gc'=>$gcId>0]));$count=count($providers);$quota=($count>1&&($plan['multi_provider_quota_mode']??'split')==='split')?intdiv($total,max(1,$count)):$total;
        $errors=[];$success=0;$update=['plan_id'=>$planId,'subscription_expire'=>$expire,'data_limit_bytes'=>$total,'device_limit'=>max(1,(int)$plan['device_limit'])];
        if($pgId>0){
            try{
                $p=self::panel('pasarguard',$pgId);if(!$p||!(int)$p['active'])throw new RuntimeException('پنل PasarGuard فعال پیدا نشد.');
                $u=self::username($c,'pg');$old=self::pg_user($p,$u);$fallbackGroups=BlueVPN_Utils::json_decode_array((string)$plan['group_ids_json'],[]);$groupIds=self::pg_active_group_ids($p,$fallbackGroups);
                $payload=['status'=>'active','expire'=>$expire?gmdate('Y-m-d\TH:i:s\Z',strtotime($expire.' UTC')):0,'data_limit'=>$quota,'data_limit_reset_strategy'=>'no_reset','group_ids'=>$groupIds,'hwid_limit'=>(int)$plan['device_limit']<=1?1:2,'note'=>'BlueVPN WordPress; customer '.$customerId];
                if(!$old){$payload['username']=$u;$proxySettings=self::pg_proxy_settings($p);if($proxySettings)$payload['proxy_settings']=$proxySettings;$r=self::req('POST',self::join_url((string)$p['base_url'],'/api/user'),self::pg_headers($p),$payload,(bool)$p['verify_tls']);}
                else{$r=self::req('PUT',self::join_url((string)$p['base_url'],'/api/user/by-username/'.rawurlencode($u)),self::pg_headers($p),$payload,(bool)$p['verify_tls']);if(in_array($r['code'],[404,405],true))$r=self::req('PUT',self::join_url((string)$p['base_url'],'/api/user/'.rawurlencode($u)),self::pg_headers($p),$payload,(bool)$p['verify_tls']);}
                if($r['code']>=400)throw new RuntimeException('فعال‌سازی PasarGuard ناموفق: HTTP '.$r['code'].' '.mb_substr($r['body'],0,420));$remote=$r['json']?:self::pg_user($p,$u)?:[];$update['panel_id']=$p['id'];$update['pg_username']=$u;$update['pasarguard_subscription_url']=self::remote_sub_url($remote,(string)$p['base_url']);$success++;
            }catch(Throwable $e){$errors[]='PasarGuard: '.$e->getMessage();}
        }
        if($mzId>0){
            try{$p=self::panel('marzban',$mzId);if(!$p||!(int)$p['active'])throw new RuntimeException('پنل Marzban فعال پیدا نشد.');$u=self::username($c,'mz');$old=self::mz_user($p,$u);[$proxies,$inbounds]=self::mz_access($p,25,BlueVPN_Utils::json_decode_array((string)($plan['marzban_inbounds_json']??''),[]));$payload=['status'=>'active','expire'=>$expire?strtotime($expire.' UTC'):0,'data_limit'=>$quota,'data_limit_reset_strategy'=>'no_reset','proxies'=>$proxies,'inbounds'=>$inbounds,'note'=>'BlueVPN WordPress; customer '.$customerId];$path=$old?'/api/user/'.rawurlencode($u):'/api/user';if(!$old)$payload['username']=$u;$r=self::req($old?'PUT':'POST',self::join_url((string)$p['base_url'],$path),self::mz_headers($p),$payload,(bool)$p['verify_tls']);if($r['code']>=400)throw new RuntimeException('فعال‌سازی Marzban ناموفق: HTTP '.$r['code'].' '.mb_substr($r['body'],0,420));$remote=self::mz_user($p,$u)?:$r['json'];$update['marzban_panel_id']=$p['id'];$update['marzban_username']=$u;$update['marzban_subscription_url']=self::remote_sub_url($remote,(string)$p['base_url']);$update['marzban_status']='active';$success++;}catch(Throwable $e){$errors[]='Marzban: '.$e->getMessage();}
        }
        if($gcId>0){
            try{$p=self::panel('guardcore',$gcId);if(!$p||!(int)$p['active'])throw new RuntimeException('پنل GuardCore فعال پیدا نشد.');$u=self::username($c,'gc');$global=trim((string)($p['global_subscription_url']??''));if(($p['auth_mode']??'manual')==='manual'){$update['guardcore_panel_id']=$p['id'];$update['guardcore_username']=$u;$update['guardcore_subscription_url']=$global!==''?esc_url_raw($global):(string)$c['guardcore_subscription_url'];$update['guardcore_status']=!empty($update['guardcore_subscription_url'])?'active':'manual_pending';$update['guardcore_expire']=$expire;$update['guardcore_data_limit_bytes']=$global!==''?0:$quota;$update['guardcore_last_error']='';$success++;}else{$old=self::gc_user($p,$u);$serviceIds=BlueVPN_Utils::json_decode_array((string)($plan['guardcore_service_ids_json']??''),[]);$remote=self::gc_provision($p,$u,$expire,$quota,$serviceIds,'BlueVPN WordPress; customer '.$customerId,$old);$update['guardcore_panel_id']=$p['id'];$update['guardcore_username']=$u;$update['guardcore_subscription_id']=is_numeric($remote['id']??null)?(int)$remote['id']:null;$update['guardcore_subscription_url']=(string)($remote['subscription_url']??'');$update['guardcore_status']=(string)($remote['status']??'active');$update['guardcore_expire']=$remote['expire']??$expire;$update['guardcore_data_limit_bytes']=(int)($remote['data_limit']??$quota);$update['guardcore_used_traffic_bytes']=(int)($remote['used_traffic']??0);$update['guardcore_last_error']='';$success++;}}catch(Throwable $e){$errors[]='GuardCore: '.$e->getMessage();$update['guardcore_last_error']=mb_substr($e->getMessage(),0,1800);}
        }
        if(empty($c['subscription_token']))$update['subscription_token']=BlueVPN_Utils::random_token(30);else$update['subscription_token']=$c['subscription_token'];$update['subscription_url']=home_url('/sub/'.$update['subscription_token']);$update['subscription_status']=$success>0?'active':'inactive';$update['last_sync_at']=BlueVPN_Utils::now_mysql();
        if($success===0&&!$errors)$errors[]='هیچ Provider فعال یا Global Subscription قابل استفاده‌ای پیدا نشد.';
        $update['last_sync_error']=implode(' | ',$errors);
        $saved=$wpdb->update($ct,$update,['id'=>$customerId]);if($saved===false)return ['ok'=>false,'message'=>'ذخیره entitlement کاربر در MySQL ناموفق بود.','success_count'=>$success];
        if($success>0)self::request_background_snapshot($customerId);
        return ['ok'=>$success>0&&count($errors)===0,'partial'=>$success>0&&count($errors)>0,'message'=>$errors?implode(' | ',$errors):'فعال‌سازی Providerها انجام شد.','success_count'=>$success,'resolved_providers'=>['pasarguard'=>$pgId,'marzban'=>$mzId,'guardcore'=>$gcId]];
    }
    public static function request_background_sync(int $customerId): bool {
        if($customerId<=0)return false;
        if(wp_next_scheduled('bluevpn_sync_customer_async',[$customerId]))return false;
        $ok=wp_schedule_single_event(time()+2,'bluevpn_sync_customer_async',[$customerId]);
        if($ok!==false&&function_exists('spawn_cron'))spawn_cron(time());
        return $ok!==false;
    }
    public static function async_sync_customer(int $customerId): void {
        self::sync_customer($customerId,false);
    }

    public static function sync_customer(int $customerId,bool $force=false): array {
        global $wpdb;
        $ct=BlueVPN_DB::table('customers');
        $c=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$ct} WHERE id=%d",$customerId),ARRAY_A);
        if(!$c)return ['ok'=>false,'message'=>'کاربر پیدا نشد.'];
        $last=!empty($c['last_sync_at'])?(strtotime((string)$c['last_sync_at'].' UTC')?:0):0;
        if(!$force&&$last>0&&(time()-$last)<self::SYNC_TTL_SECONDS){
            return ['ok'=>true,'cached'=>true,'message'=>'وضعیت ذخیره‌شده WordPress معتبر است.'];
        }

        $u=[];$errors=[];$active=false;$used=0;$exp=[];$responses=0;$configured=0;
        if(!empty($c['panel_id'])&&!empty($c['pg_username'])){
            $configured++;
            try{$p=self::panel('pasarguard',(int)$c['panel_id']);if($p){$r=self::pg_user($p,(string)$c['pg_username']);$responses++;if($r){$active=$active||in_array(strtolower((string)($r['status']??'active')),['active','enabled'],true);$u['pasarguard_subscription_url']=self::remote_sub_url($r,(string)$p['base_url'])?:$c['pasarguard_subscription_url'];$used+=max(0,(int)($r['used_traffic']??$r['used_traffic_bytes']??0));$exp[]=self::remote_expiry($r['expire']??null);}}}catch(Throwable $e){$errors[]='PasarGuard: '.$e->getMessage();}
        }
        if(!empty($c['marzban_panel_id'])&&!empty($c['marzban_username'])){
            $configured++;
            try{$p=self::panel('marzban',(int)$c['marzban_panel_id']);if($p){$r=self::mz_user($p,(string)$c['marzban_username']);$responses++;if($r){$active=$active||in_array(strtolower((string)($r['status']??'active')),['active','enabled'],true);$u['marzban_subscription_url']=self::remote_sub_url($r,(string)$p['base_url'])?:$c['marzban_subscription_url'];$u['marzban_status']=(string)($r['status']??'active');$u['marzban_used_traffic_bytes']=max(0,(int)($r['used_traffic']??0));$used+=max(0,(int)($r['used_traffic']??0));$exp[]=self::remote_expiry($r['expire']??null);}}}catch(Throwable $e){$errors[]='Marzban: '.$e->getMessage();$u['marzban_last_error']=mb_substr($e->getMessage(),0,1800);}
        }
        if(!empty($c['guardcore_panel_id'])&&!empty($c['guardcore_username'])){
            $configured++;
            try{$p=self::panel('guardcore',(int)$c['guardcore_panel_id']);if($p&&($p['auth_mode']??'manual')!=='manual'){$r=self::gc_user($p,(string)$c['guardcore_username']);$responses++;if($r){$active=$active||($r['status']==='active');$u['guardcore_subscription_id']=is_numeric($r['id']??null)?(int)$r['id']:$c['guardcore_subscription_id'];$u['guardcore_subscription_url']=(string)($r['subscription_url']?:$c['guardcore_subscription_url']);$u['guardcore_status']=(string)$r['status'];$u['guardcore_expire']=$r['expire'];$u['guardcore_data_limit_bytes']=(int)$r['data_limit'];$u['guardcore_used_traffic_bytes']=(int)$r['used_traffic'];$u['guardcore_last_error']='';$used+=max(0,(int)$r['used_traffic']);$exp[]=$r['expire'];}}elseif(!empty($c['guardcore_subscription_url'])){$responses++;$active=true;}}catch(Throwable $e){$errors[]='GuardCore: '.$e->getMessage();$u['guardcore_last_error']=mb_substr($e->getMessage(),0,1800);}
        } elseif(!empty($c['guardcore_subscription_url'])){$configured++;$responses++;$active=true;}

        $valid=array_values(array_filter($exp));
        if($valid)$u['subscription_expire']=max($valid);
        if($responses>0)$u['used_traffic_bytes']=$used;
        // Fail open on transport/provider errors. A timeout must never turn a
        // paid user into Free. We only write inactive when every configured
        // provider answered and none reported an active subscription.
        if($active){
            $u['subscription_status']='active';
        } elseif($configured>0&&$responses>0&&$responses===$configured&&count($errors)===0){
            $u['subscription_status']='inactive';
        }
        $u['last_sync_at']=BlueVPN_Utils::now_mysql();
        $u['last_sync_error']=implode(' | ',$errors);
        $wpdb->update($ct,$u,['id'=>$customerId]);
        return [
            'ok'=>count($errors)===0,
            'cached'=>false,
            'preserved_status'=>!isset($u['subscription_status']),
            'message'=>$errors?implode(' | ',$errors):'همگام‌سازی انجام شد.'
        ];
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
    private static function snapshot_store(int $customerId,array $lines,array $errors=[],array $sourceStats=[]): void {
        if(!$lines)return;
        update_option(self::snapshot_option($customerId),[
            'lines'=>array_values($lines),
            'updated_at'=>time(),
            'errors'=>array_values($errors),
            'sources'=>$sourceStats,
        ],false);
    }
    private static function customer_sources(array $c): array {
        $out=[];
        foreach([
            'pasarguard'=>'pasarguard_subscription_url',
            'marzban'=>'marzban_subscription_url',
            'guardcore'=>'guardcore_subscription_url',
        ] as $provider=>$field){
            $url=trim((string)($c[$field]??''));
            if($url==='')continue;
            $key=$provider;
            $suffix=2;
            while(isset($out[$key])){$key=$provider.'_'.$suffix;$suffix++;}
            $out[$key]=$url;
        }
        return $out;
    }
    public static function refresh_subscription_snapshot(int $customerId): array {
        global $wpdb;$t=BlueVPN_DB::table('customers');$c=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d AND active=1 LIMIT 1",$customerId),ARRAY_A);if(!$c)return ['ok'=>false,'message'=>'customer not found'];
        $sources=self::customer_sources($c);$lines=[];$seen=[];$errors=[];$successSources=0;$sourceStats=[];
        foreach($sources as $provider=>$url){
            $r=wp_remote_get($url,['timeout'=>8,'redirection'=>2,'sslverify'=>true,'headers'=>['User-Agent'=>'BlueVPN-WordPress/'.BLUEVPN_MANAGER_VERSION,'Accept'=>'text/plain,*/*']]);
            if(is_wp_error($r)){
                $errors[]=$provider.': '.$r->get_error_message();
                $sourceStats[$provider]=['ok'=>false,'count'=>0,'url_hash'=>hash('sha256',$url),'error'=>$r->get_error_message()];
                continue;
            }
            $code=(int)wp_remote_retrieve_response_code($r);
            if($code>=400){
                $errors[]=$provider.': HTTP '.$code;
                $sourceStats[$provider]=['ok'=>false,'count'=>0,'url_hash'=>hash('sha256',$url),'error'=>'HTTP '.$code];
                continue;
            }
            $successSources++;
            $providerLines=self::subscription_lines((string)wp_remote_retrieve_body($r));
            $sourceStats[$provider]=[
                'ok'=>true,
                'count'=>count($providerLines),
                'url_hash'=>hash('sha256',$url),
                'content_hash'=>hash('sha256',implode("\n",$providerLines)),
                'updated_at'=>time(),
            ];
            foreach($providerLines as $line){
                $key=sha1($line);
                if(isset($seen[$key]))continue;
                $seen[$key]=1;
                $lines[]=$line;
            }
        }
        $old=self::snapshot_load($customerId);
        // Never replace a complete last-good pool with a partial response caused
        // by a temporarily unavailable panel. First bootstrap may use partial.
        $complete=$successSources===count($sources)&&count($errors)===0;
        if($lines&&($complete||empty($old['lines'])))self::snapshot_store($customerId,$lines,$errors,$sourceStats);
        $effective=$complete||empty($old['lines'])?$lines:(array)$old['lines'];
        $effectiveSources=$complete||empty($old['sources'])?$sourceStats:(array)($old['sources']??[]);
        return ['ok'=>!empty($effective),'fresh'=>$complete,'lines'=>$effective,'sources'=>$effectiveSources,'errors'=>$errors];
    }
    public static function subscription_snapshot_stats(int $customerId): array {
        $snapshot=self::snapshot_load($customerId);
        $sources=is_array($snapshot['sources']??null)?$snapshot['sources']:[];
        $guardcore=is_array($sources['guardcore']??null)?$sources['guardcore']:[];
        return [
            'updated_at'=>(int)($snapshot['updated_at']??0),
            'total_count'=>is_array($snapshot['lines']??null)?count($snapshot['lines']):0,
            'guardcore_count'=>(int)($guardcore['count']??0),
            'guardcore_ok'=>(bool)($guardcore['ok']??false),
            'guardcore_content_hash'=>(string)($guardcore['content_hash']??''),
        ];
    }

    public static function request_background_snapshot(int $customerId): bool {
        if($customerId<=0)return false;
        if(wp_next_scheduled('bluevpn_refresh_subscription_snapshot',[$customerId]))return false;
        $ok=wp_schedule_single_event(time()+2,'bluevpn_refresh_subscription_snapshot',[$customerId]);
        if($ok!==false&&function_exists('spawn_cron'))spawn_cron(time());
        return $ok!==false;
    }
    public static function serve_subscription(): void {
        $path=(string)(parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH)??'');if(!preg_match('~^/sub/([A-Za-z0-9_-]{10,100})/?$~',$path,$m))return;
        global $wpdb;$t=BlueVPN_DB::table('customers');$c=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE subscription_token=%s AND active=1 LIMIT 1",$m[1]),ARRAY_A);if(!$c){status_header(404);header('Content-Type: text/plain; charset=utf-8');echo 'subscription not found';exit;}
        $snapshot=self::snapshot_load((int)$c['id']);$age=!empty($snapshot['updated_at'])?time()-(int)$snapshot['updated_at']:PHP_INT_MAX;$lines=is_array($snapshot['lines']??null)?$snapshot['lines']:[];
        if(!$lines){$result=self::refresh_subscription_snapshot((int)$c['id']);$lines=(array)($result['lines']??[]);}elseif($age>self::SNAPSHOT_FRESH_SECONDS){self::request_background_snapshot((int)$c['id']);}
        if(!$lines){status_header(502);header('Content-Type: text/plain; charset=utf-8');echo 'No usable configs';exit;}
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: private, max-age=60, stale-while-revalidate=300');
        header('profile-title: base64:'.base64_encode('BlueVPN'));
        // BlueVPN Android owns refresh policy. Do not instruct upstream v2rayNG
        // to refresh the managed subscription every hour.
        header('profile-update-interval: 24');
        $expiry=!empty($c['subscription_expire'])?strtotime((string)$c['subscription_expire'].' UTC'):0;
        header('subscription-userinfo: upload=0; download='.(int)$c['used_traffic_bytes'].'; total='.(int)$c['data_limit_bytes'].'; expire='.(int)$expiry);
        header('X-BlueVPN-Config-Count: '.count($lines));
        header('X-BlueVPN-Snapshot-Age: '.($age===PHP_INT_MAX?0:max(0,$age)));
        echo base64_encode(implode("\n",$lines)."\n");exit;
    }

}
