<?php
if (!defined('ABSPATH')) exit;

/**
 * Hiddify Manager provider adapter.
 *
 * Uses the v2 Admin API with the Hiddify-API-Key header. BlueVPN keeps the
 * WordPress entitlement as the canonical expiry/quota authority and treats
 * Hiddify as a delivery provider.
 */
final class BlueVPN_Hiddify {
    private const API_PREFIX = '/api/v2/admin';

    private static function table(): string { return BlueVPN_DB::table('hiddify_panels'); }

    public static function panel(int $id): ?array {
        if($id<=0)return null;global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE id=%d LIMIT 1',$id),ARRAY_A);
        return is_array($row)?$row:null;
    }

    private static function join(string $base,string $path): string {
        return rtrim($base,'/').'/'.ltrim($path,'/');
    }

    private static function api_key(array $panel): string {
        $key=BlueVPN_Utils::decrypt_secret((string)($panel['api_key_enc']??''));
        if($key==='')throw new RuntimeException('Hiddify API Key تنظیم نشده است.');
        return $key;
    }

    private static function request(array $panel,string $method,string $path,?array $json=null,int $timeout=20): array {
        $method=strtoupper($method);$url=self::join((string)$panel['base_url'],$path);
        $headers=[
            'Accept'=>'application/json',
            'User-Agent'=>'BlueVPN-Hiddify/'.BLUEVPN_MANAGER_VERSION,
            'Hiddify-API-Key'=>self::api_key($panel),
        ];
        if($json!==null)$headers['Content-Type']='application/json';
        $attempts=$method==='GET'?2:1;$last='';
        for($i=0;$i<$attempts;$i++){
            $args=[
                'method'=>$method,'timeout'=>max(4,min(25,$timeout)),'redirection'=>2,
                'sslverify'=>!empty($panel['verify_tls']),'headers'=>$headers,
            ];
            if($json!==null)$args['body']=wp_json_encode($json);
            if($i+1<$attempts){
                $args['headers']['X-BlueVPN-Sentinel-Transient']='1';
                $args['timeout']=min(8,(int)$args['timeout']);
            }
            $res=wp_remote_request($url,$args);
            if(is_wp_error($res)){
                $last=$res->get_error_message();
                if($i+1<$attempts){usleep(250000);continue;}
                throw new RuntimeException($last);
            }
            $code=(int)wp_remote_retrieve_response_code($res);$body=(string)wp_remote_retrieve_body($res);
            if($i+1<$attempts&&in_array($code,[408,425,429,500,502,503,504],true)){usleep(350000);continue;}
            $decoded=json_decode($body,true);
            return ['code'=>$code,'body'=>$body,'json'=>is_array($decoded)?$decoded:[]];
        }
        throw new RuntimeException($last!==''?$last:'Hiddify پاسخ معتبری برنگرداند.');
    }

    private static function list_rows(array $payload): array {
        foreach(['data','items','users','result'] as $key){
            if(isset($payload[$key])&&is_array($payload[$key]))$payload=$payload[$key];
        }
        if(!$payload)return [];
        if(function_exists('array_is_list')&&array_is_list($payload))return array_values(array_filter($payload,'is_array'));
        $numeric=true;foreach(array_keys($payload) as $k)if(!is_int($k)&&!ctype_digit((string)$k)){$numeric=false;break;}
        return $numeric?array_values(array_filter($payload,'is_array')):[];
    }

    public static function users(int $panelId): array {
        $panel=self::panel($panelId);if(!$panel)throw new RuntimeException('پنل Hiddify پیدا نشد.');
        $r=self::request($panel,'GET',self::API_PREFIX.'/user/',null,15);
        if($r['code']>=400)throw new RuntimeException('دریافت کاربران Hiddify ناموفق: HTTP '.$r['code']);
        return self::list_rows($r['json']);
    }

    public static function user(int $panelId,string $uuid): ?array {
        $uuid=trim($uuid);if($uuid==='')return null;$panel=self::panel($panelId);if(!$panel)return null;
        $r=self::request($panel,'GET',self::API_PREFIX.'/user/'.rawurlencode($uuid).'/',null,12);
        if($r['code']<400&&$r['json'])return self::normalize($panel,$r['json']);
        if(!in_array($r['code'],[400,404,405],true))throw new RuntimeException('خواندن کاربر Hiddify ناموفق: HTTP '.$r['code']);
        foreach(self::users($panelId) as $row){
            if((string)($row['uuid']??$row['id']??'')===$uuid)return self::normalize($panel,$row);
        }
        return null;
    }

    private static function expiry_from(array $row): ?string {
        foreach(['expire','expiry','expire_date','end_date','expiration_date'] as $key){
            if(empty($row[$key]))continue;$v=$row[$key];
            if(is_numeric($v)){ $n=(int)$v;if($n>2000000000000)$n=(int)floor($n/1000);if($n>1000000000)return gmdate('Y-m-d H:i:s',$n); }
            $ts=strtotime((string)$v.' UTC');if($ts)return gmdate('Y-m-d H:i:s',$ts);
        }
        $start=$row['start_date']??null;$days=(int)($row['package_days']??0);
        if($start&&$days>0){$ts=strtotime((string)$start.' UTC');if($ts)return gmdate('Y-m-d H:i:s',$ts+$days*DAY_IN_SECONDS);}
        return null;
    }

    private static function subscription_url(array $panel,array $row,string $uuid): string {
        foreach(['subscription_url','sub_url','sub_link','user_url','link'] as $key){
            $url=trim((string)($row[$key]??''));if(preg_match('~^https?://~i',$url))return esc_url_raw($url);
        }
        $base=trim((string)($panel['subscription_base_url']??''));if($base==='')$base=(string)$panel['base_url'];
        return esc_url_raw(rtrim($base,'/').'/'.rawurlencode($uuid).'/all-configs/');
    }

    private static function normalize(array $panel,array $row): array {
        foreach(['data','item','user','result'] as $key)if(isset($row[$key])&&is_array($row[$key]))$row=$row[$key];
        $uuid=trim((string)($row['uuid']??$row['id']??''));
        $active=true;
        foreach(['is_active','enable','enabled','active'] as $key)if(array_key_exists($key,$row)){$active=BlueVPN_Utils::boolish($row[$key]);break;}
        $usedGb=max(0,(float)($row['current_usage_GB']??$row['current_usage_gb']??$row['usage_GB']??0));
        $limitGb=max(0,(float)($row['usage_limit_GB']??$row['usage_limit_gb']??0));
        return [
            'uuid'=>$uuid,'name'=>(string)($row['name']??''),'status'=>$active?'active':'disabled',
            'used_traffic_bytes'=>(int)round($usedGb*1024*1024*1024),
            'data_limit_bytes'=>(int)round($limitGb*1024*1024*1024),
            'expire'=>self::expiry_from($row),
            'subscription_url'=>self::subscription_url($panel,$row,$uuid),
            'raw'=>$row,
        ];
    }

    public static function provision(int $panelId,string $uuid,string $name,int $quotaBytes,?string $expire,int $deviceLimit=1,string $note=''): array {
        $panel=self::panel($panelId);if(!$panel||empty($panel['active']))throw new RuntimeException('پنل Hiddify فعال پیدا نشد.');
        $uuid=trim($uuid);if($uuid==='')throw new RuntimeException('UUID Hiddify خالی است.');
        $days=0;if($expire){$ts=strtotime($expire.' UTC');if($ts)$days=max(1,(int)ceil(($ts-time())/DAY_IN_SECONDS));}
        $payload=[
            'uuid'=>$uuid,'name'=>mb_substr($name!==''?$name:$uuid,0,120),
            'usage_limit_GB'=>round(max(0,$quotaBytes)/(1024*1024*1024),3),
            'package_days'=>$days,'mode'=>'no_reset','comment'=>mb_substr($note,0,500),
        ];
        $existing=self::user($panelId,$uuid);
        $path=self::API_PREFIX.'/user/'.rawurlencode($uuid).'/';
        $r=$existing
            ? self::request($panel,'PATCH',$path,$payload,18)
            : self::request($panel,'POST',self::API_PREFIX.'/user/',$payload,18);
        if($r['code']>=400)throw new RuntimeException('Provision Hiddify ناموفق: HTTP '.$r['code'].' '.mb_substr($r['body'],0,300));
        $remote=self::user($panelId,$uuid);
        if(!$remote)$remote=self::normalize($panel,$r['json']?:$payload);
        if(class_exists('BlueVPN_Error_Monitor')){
            BlueVPN_Error_Monitor::report(
                'provider','hiddify','notice','HIDDIFY_APPLY_USERS_EVENTUAL_CONSISTENCY',
                'کاربر Hiddify از API ذخیره شد. در برخی نسخه‌های جدید، پروتکل‌های Sing-box ممکن است تا Apply Users/Reload بعدی با تأخیر همگام شوند.',
                ['panel_id'=>$panelId,'user_uuid'=>$uuid]
            );
        }
        return $remote;
    }

    public static function enforce_expiry(int $panelId,string $uuid,string $canonical): array {
        try{
            $panel=self::panel($panelId);if(!$panel)return ['ok'=>false,'message'=>'پنل Hiddify پیدا نشد'];
            $ts=strtotime($canonical.' UTC');if(!$ts)return ['ok'=>false,'message'=>'تاریخ Canonical معتبر نیست'];
            $days=max(1,(int)ceil(($ts-time())/DAY_IN_SECONDS));
            $r=self::request($panel,'PATCH',self::API_PREFIX.'/user/'.rawurlencode($uuid).'/',[
                'package_days'=>$days,
            ],15);
            return ['ok'=>$r['code']<400,'message'=>'HTTP '.$r['code']];
        }catch(Throwable $e){return ['ok'=>false,'message'=>$e->getMessage()];}
    }

    public static function test(int $panelId): array {
        global $wpdb;$panel=self::panel($panelId);if(!$panel)return ['ok'=>false,'message'=>'پنل Hiddify پیدا نشد.'];
        try{
            $users=self::users($panelId);$msg='اتصال Hiddify API v2 موفق است؛ '.count($users).' کاربر دریافت شد.';
            $wpdb->update(self::table(),[
                'api_version'=>'v2','capabilities_json'=>BlueVPN_Utils::json_encode([
                    'users'=>true,'create'=>true,'update'=>true,'traffic_limit'=>true,'expiry'=>true,'subscription'=>true,
                    'singbox_apply_users_eventual_consistency'=>true,
                ]),'stats_json'=>BlueVPN_Utils::json_encode(['users'=>count($users)]),'last_sync_at'=>BlueVPN_Utils::now_mysql(),
                'last_test_ok'=>1,'last_test_message'=>$msg,'last_test_at'=>BlueVPN_Utils::now_mysql(),'updated_at'=>BlueVPN_Utils::now_mysql(),
            ],['id'=>$panelId]);
            return ['ok'=>true,'message'=>$msg];
        }catch(Throwable $e){
            $msg=mb_substr($e->getMessage(),0,1800);$wpdb->update(self::table(),['last_test_ok'=>0,'last_test_message'=>$msg,'last_test_at'=>BlueVPN_Utils::now_mysql(),'updated_at'=>BlueVPN_Utils::now_mysql()],['id'=>$panelId]);
            return ['ok'=>false,'message'=>$msg];
        }
    }

    public static function catalog(int $panelId,bool $force=false): array {
        $panel=self::panel($panelId);if(!$panel)return ['ok'=>false,'message'=>'پنل Hiddify پیدا نشد.'];
        $cachedAt=!empty($panel['last_sync_at'])?(strtotime((string)$panel['last_sync_at'].' UTC')?:0):0;
        if(!$force&&$cachedAt>0&&time()-$cachedAt<300)return [
            'ok'=>(int)($panel['last_test_ok']??0)===1,'cached'=>true,'version'=>(string)($panel['api_version']??'v2'),
            'stats'=>BlueVPN_Utils::json_decode_array((string)($panel['stats_json']??''),[]),
            'capabilities'=>BlueVPN_Utils::json_decode_array((string)($panel['capabilities_json']??''),[]),
        ];
        $r=self::test($panelId);$panel=self::panel($panelId)?:$panel;
        return ['ok'=>!empty($r['ok']),'cached'=>false,'message'=>(string)$r['message'],'version'=>(string)($panel['api_version']??'v2'),'stats'=>BlueVPN_Utils::json_decode_array((string)($panel['stats_json']??''),[]),'capabilities'=>BlueVPN_Utils::json_decode_array((string)($panel['capabilities_json']??''),[])];
    }
}
