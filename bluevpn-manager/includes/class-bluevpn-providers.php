<?php
if (!defined('ABSPATH')) exit;

final class BlueVPN_Providers {
    public static function init(): void {
        add_action('template_redirect',[self::class,'serve_subscription'],0);
    }

    private static function panel(string $provider,int $id): ?array {
        global $wpdb;
        $map=['pasarguard'=>'pasarguard_panels','marzban'=>'marzban_panels','guardcore'=>'guardcore_panels'];
        if(!isset($map[$provider])||$id<=0)return null;
        $t=BlueVPN_DB::table($map[$provider]);
        $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d",$id),ARRAY_A);
        return is_array($r)?$r:null;
    }
    private static function req(string $method,string $url,array $headers=[],?array $json=null,bool $ssl=true,array $form=[]): array {
        $args=['method'=>$method,'timeout'=>25,'redirection'=>2,'sslverify'=>$ssl,'headers'=>array_merge(['Accept'=>'application/json','User-Agent'=>'BlueVPN-WordPress/'.BLUEVPN_MANAGER_VERSION],$headers)];
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
    private static function pg_headers(array $p): array {
        $mode=(string)($p['auth_mode']??'api_key');
        if($mode==='api_key'){
            $key=BlueVPN_Utils::decrypt_secret((string)($p['api_key_enc']??''));
            if($key==='')throw new RuntimeException('کلید API پاسارگارد تنظیم نشده است.');
            return ['X-Api-Key'=>$key];
        }
        $u=BlueVPN_Utils::decrypt_secret((string)($p['username_enc']??''));
        $pw=BlueVPN_Utils::decrypt_secret((string)($p['password_enc']??''));
        if($u===''||$pw==='')throw new RuntimeException('نام کاربری یا رمز پاسارگارد تنظیم نشده است.');
        $r=self::req('POST',self::join_url((string)$p['base_url'],'/api/admin/token'),[],null,(bool)$p['verify_tls'],['grant_type'=>'password','username'=>$u,'password'=>$pw]);
        if($r['code']>=400)throw new RuntimeException('ورود پاسارگارد ناموفق: HTTP '.$r['code']);
        $tok=(string)($r['json']['access_token']??$r['json']['token']??'');
        if($tok==='')throw new RuntimeException('توکن پاسارگارد دریافت نشد.');
        return ['Authorization'=>'Bearer '.$tok];
    }
    private static function mz_headers(array $p): array {
        $u=BlueVPN_Utils::decrypt_secret((string)($p['username_enc']??''));
        $pw=BlueVPN_Utils::decrypt_secret((string)($p['password_enc']??''));
        if($u===''||$pw==='')throw new RuntimeException('نام کاربری یا رمز Marzban تنظیم نشده است.');
        $r=self::req('POST',self::join_url((string)$p['base_url'],'/api/admin/token'),[],null,(bool)$p['verify_tls'],['grant_type'=>'password','username'=>$u,'password'=>$pw]);
        if($r['code']>=400)throw new RuntimeException('ورود Marzban ناموفق: HTTP '.$r['code']);
        $tok=(string)($r['json']['access_token']??'');
        if($tok==='')throw new RuntimeException('توکن Marzban دریافت نشد.');
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
        $u=BlueVPN_Utils::decrypt_secret((string)($p['username_enc']??''));
        $pw=BlueVPN_Utils::decrypt_secret((string)($p['password_enc']??''));
        if($u===''||$pw==='')throw new RuntimeException('نام کاربری یا رمز GuardCore تنظیم نشده است.');
        $r=self::req('POST',self::join_url((string)$p['base_url'],'/api/admins/token'),[],null,(bool)$p['verify_tls'],['grant_type'=>'password','username'=>$u,'password'=>$pw]);
        if($r['code']>=400)throw new RuntimeException('ورود GuardCore ناموفق: HTTP '.$r['code'].' '.mb_substr($r['body'],0,220));
        $tok=(string)($r['json']['access_token']??'');
        if($tok==='')throw new RuntimeException('توکن GuardCore دریافت نشد.');
        return ['Authorization'=>'Bearer '.$tok];
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
        $enabled=!array_key_exists('enabled',$data)||BlueVPN_Utils::boolish($data['enabled']);$activated=!array_key_exists('activated',$data)||BlueVPN_Utils::boolish($data['activated']);$expired=BlueVPN_Utils::boolish($data['expired']??false);$limited=BlueVPN_Utils::boolish($data['limited']??false);$status=($enabled&&$activated&&!$expired&&!$limited)?'active':(!$enabled?'disabled':(!$activated?'pending':($expired?'expired':($limited?'limited':'inactive'))));$link=(string)($data['link']??$data['subscription_url']??'');
        return ['id'=>$data['id']??null,'username'=>(string)($data['username']??''),'subscription_url'=>self::absolute_url((string)$p['base_url'],$link),'status'=>$status,'expire'=>self::gc_expire($p,$data),'data_limit'=>self::gc_usage_bytes($p,$data['limit_usage']??0),'used_traffic'=>self::gc_usage_bytes($p,$data['current_usage']??$data['total_usage']??0),'raw'=>$data];
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
        if($r['code']>=400)throw new RuntimeException('فعال‌سازی GuardCore ناموفق: HTTP '.$r['code'].' '.mb_substr($r['body'],0,420));$fresh=self::gc_user($p,$username);if(!$fresh)throw new RuntimeException('اشتراک GuardCore ساخته شد ولی قابل خواندن نیست.');
        if(($fresh['status']??'')==='disabled'){$en=self::req('POST',self::join_url((string)$p['base_url'],'/api/subscriptions/enable'),self::gc_headers($p),['usernames'=>[$username]],(bool)$p['verify_tls']);if($en['code']<400)$fresh=self::gc_user($p,$username)?:$fresh;}return $fresh;
    }
    public static function test(string $provider,int $id): array {
        try{
            $p=self::panel($provider,$id);if(!$p)throw new RuntimeException('پنل پیدا نشد.');
            $base=(string)$p['base_url'];$ssl=(bool)$p['verify_tls'];
            if($provider==='pasarguard'){
                $r=self::req('GET',self::join_url($base,'/api/users?limit=1&offset=0'),self::pg_headers($p),null,$ssl);
                $ok=$r['code']===200;$msg=$ok?'اتصال و دسترسی کاربران موفق بود.':'HTTP '.$r['code'].' '.mb_substr($r['body'],0,220);
            }elseif($provider==='marzban'){
                $h=self::mz_headers($p);$r=self::req('GET',self::join_url($base,'/api/admin'),$h,null,$ssl);
                $ok=$r['code']===200;$msg=$ok?'ورود مدیر Marzban موفق بود.':'HTTP '.$r['code'].' '.mb_substr($r['body'],0,220);
                if($ok){$in=self::req('GET',self::join_url($base,'/api/inbounds'),$h,null,$ssl);if($in['code']===200)$msg.=' ورودی‌ها نیز قابل خواندن هستند.';}
            }else{
                if(($p['auth_mode']??'manual')==='manual'){$ok=true;$msg='GuardCore در حالت دستی فعال است.';}
                else{
                    $h=self::gc_headers($p);$r=self::req('GET',self::join_url($base,'/api/admins/current'),$h,null,$ssl);$ok=$r['code']<400;$msg=$ok?'ورود مدیر GuardCore موفق بود.':'HTTP '.$r['code'].' '.mb_substr($r['body'],0,220);
                    if($ok){$sv=self::req('GET',self::join_url($base,'/api/services'),$h,null,$ssl);if($sv['code']<400&&is_array($sv['json'])){global $wpdb;$wpdb->update(BlueVPN_DB::table('guardcore_panels'),['services_json'=>BlueVPN_Utils::json_encode($sv['json'])],['id'=>$id]);$msg.=' '.count($sv['json']).' سرویس دریافت شد.';}else{$ok=false;$msg.=' دریافت Serviceها ناموفق بود: HTTP '.$sv['code'];}}
                }
            }
            self::store_test($provider,$id,$ok,$msg);return ['ok'=>$ok,'message'=>$msg];
        }catch(Throwable $e){self::store_test($provider,$id,false,$e->getMessage());return ['ok'=>false,'message'=>$e->getMessage()];}
    }
    private static function store_test(string $provider,int $id,bool $ok,string $msg): void {
        global $wpdb;$map=['pasarguard'=>'pasarguard_panels','marzban'=>'marzban_panels','guardcore'=>'guardcore_panels'];if(!isset($map[$provider]))return;
        $wpdb->update(BlueVPN_DB::table($map[$provider]),['last_test_ok'=>$ok?1:0,'last_test_message'=>mb_substr($msg,0,1800),'last_test_at'=>BlueVPN_Utils::now_mysql()],['id'=>$id]);
    }
    private static function pg_user(array $p,string $username): ?array {
        $r=self::req('GET',self::join_url((string)$p['base_url'],'/api/user/by-username/'.rawurlencode($username)),self::pg_headers($p),null,(bool)$p['verify_tls']);
        if($r['code']===404)return null;if($r['code']>=400)throw new RuntimeException('خواندن کاربر PasarGuard ناموفق: HTTP '.$r['code']);return $r['json'];
    }
    private static function mz_user(array $p,string $username): ?array {
        $r=self::req('GET',self::join_url((string)$p['base_url'],'/api/user/'.rawurlencode($username)),self::mz_headers($p),null,(bool)$p['verify_tls']);
        if($r['code']===404)return null;if($r['code']>=400)throw new RuntimeException('خواندن کاربر Marzban ناموفق: HTTP '.$r['code']);return $r['json'];
    }
    private static function mz_access(array $p): array {
        $proxies=BlueVPN_Utils::json_decode_array((string)($p['proxies_json']??''),[]);$inbounds=BlueVPN_Utils::json_decode_array((string)($p['inbounds_json']??''),[]);
        if($proxies&&$inbounds)return [$proxies,$inbounds];
        $r=self::req('GET',self::join_url((string)$p['base_url'],'/api/inbounds'),self::mz_headers($p),null,(bool)$p['verify_tls']);
        if($r['code']>=400)throw new RuntimeException('دریافت Inboundهای Marzban ناموفق: HTTP '.$r['code']);
        $raw=$r['json'];$proxies=[];$inbounds=[];
        foreach(['vless','vmess','trojan','shadowsocks'] as $proto){$items=$raw[$proto]??[];if(!is_array($items)||!$items)continue;$tags=[];foreach($items as $item){$tag=is_string($item)?$item:(string)($item['tag']??$item['remark']??$item['name']??'');if($tag!==''&&!in_array($tag,$tags,true))$tags[]=$tag;}if($tags){$proxies[$proto]=new stdClass();$inbounds[$proto]=$tags;}}
        if(!$proxies)throw new RuntimeException('هیچ Inbound قابل استفاده در Marzban پیدا نشد.');
        return [$proxies,$inbounds];
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
    public static function provision_customer(int $customerId,int $planId): array {
        global $wpdb;$ct=BlueVPN_DB::table('customers');$pt=BlueVPN_DB::table('plans');$c=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$ct} WHERE id=%d",$customerId),ARRAY_A);$plan=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$pt} WHERE id=%d AND deleted=0",$planId),ARRAY_A);
        if(!$c||!$plan)return ['ok'=>false,'message'=>'کاربر یا پلن پیدا نشد.'];
        $expire=self::target_expiry($c,$plan);$total=max(0,(int)$plan['data_limit_gb'])*1024*1024*1024;$providers=array_values(array_filter(['pg'=>!empty($plan['panel_id']),'mz'=>!empty($plan['marzban_panel_id']),'gc'=>!empty($plan['guardcore_panel_id'])]));$count=count($providers);$quota=($count>1&&($plan['multi_provider_quota_mode']??'split')==='split')?intdiv($total,max(1,$count)):$total;
        $errors=[];$success=0;$update=['plan_id'=>$planId,'subscription_expire'=>$expire,'data_limit_bytes'=>$total,'device_limit'=>max(1,(int)$plan['device_limit'])];
        if(!empty($plan['panel_id'])){
            try{$p=self::panel('pasarguard',(int)$plan['panel_id']);if(!$p||!(int)$p['active'])throw new RuntimeException('پنل PasarGuard پلن فعال نیست.');$u=self::username($c,'pg');$old=self::pg_user($p,$u);$payload=['status'=>'active','expire'=>$expire?gmdate('Y-m-d\TH:i:s\Z',strtotime($expire.' UTC')):0,'data_limit'=>$quota,'data_limit_reset_strategy'=>'no_reset','group_ids'=>BlueVPN_Utils::json_decode_array((string)$plan['group_ids_json'],[]),'hwid_limit'=>(int)$plan['device_limit']<=1?1:2,'note'=>'BlueVPN WordPress; customer '.$customerId];if(!$old){$payload['username']=$u;$payload['proxy_settings']=BlueVPN_Utils::json_decode_array((string)$p['proxy_settings_json'],['vless'=>[]]);$r=self::req('POST',self::join_url((string)$p['base_url'],'/api/user'),self::pg_headers($p),$payload,(bool)$p['verify_tls']);}else{$r=self::req('PUT',self::join_url((string)$p['base_url'],'/api/user/by-username/'.rawurlencode($u)),self::pg_headers($p),$payload,(bool)$p['verify_tls']);}if($r['code']>=400)throw new RuntimeException('فعال‌سازی PasarGuard ناموفق: HTTP '.$r['code'].' '.mb_substr($r['body'],0,250));$remote=$r['json']?:self::pg_user($p,$u)?:[];$update['panel_id']=$p['id'];$update['pg_username']=$u;$update['pasarguard_subscription_url']=self::remote_sub_url($remote,(string)$p['base_url']);$success++;}catch(Throwable $e){$errors[]='PasarGuard: '.$e->getMessage();}
        }
        if(!empty($plan['marzban_panel_id'])){
            try{$p=self::panel('marzban',(int)$plan['marzban_panel_id']);if(!$p||!(int)$p['active'])throw new RuntimeException('پنل Marzban پلن فعال نیست.');$u=self::username($c,'mz');$old=self::mz_user($p,$u);[$proxies,$inbounds]=self::mz_access($p);$payload=['status'=>'active','expire'=>$expire?strtotime($expire.' UTC'):0,'data_limit'=>$quota,'data_limit_reset_strategy'=>'no_reset','proxies'=>$proxies,'inbounds'=>$inbounds,'note'=>'BlueVPN WordPress; customer '.$customerId];$path=$old?'/api/user/'.rawurlencode($u):'/api/user';if(!$old)$payload['username']=$u;$r=self::req($old?'PUT':'POST',self::join_url((string)$p['base_url'],$path),self::mz_headers($p),$payload,(bool)$p['verify_tls']);if($r['code']>=400)throw new RuntimeException('فعال‌سازی Marzban ناموفق: HTTP '.$r['code'].' '.mb_substr($r['body'],0,250));$remote=self::mz_user($p,$u)?:$r['json'];$update['marzban_panel_id']=$p['id'];$update['marzban_username']=$u;$update['marzban_subscription_url']=self::remote_sub_url($remote,(string)$p['base_url']);$update['marzban_status']='active';$success++;}catch(Throwable $e){$errors[]='Marzban: '.$e->getMessage();}
        }
        if(!empty($plan['guardcore_panel_id'])){
            try{$p=self::panel('guardcore',(int)$plan['guardcore_panel_id']);if(!$p||!(int)$p['active'])throw new RuntimeException('پنل GuardCore پلن فعال نیست.');$u=self::username($c,'gc');$global=trim((string)($p['global_subscription_url']??''));if(($p['auth_mode']??'manual')==='manual'){$update['guardcore_panel_id']=$p['id'];$update['guardcore_username']=$u;$update['guardcore_subscription_url']=$global!==''?esc_url_raw($global):(string)$c['guardcore_subscription_url'];$update['guardcore_status']=!empty($update['guardcore_subscription_url'])?'active':'manual_pending';$update['guardcore_expire']=$expire;$update['guardcore_data_limit_bytes']=$global!==''?0:$quota;$update['guardcore_last_error']='';$success++;}else{$old=self::gc_user($p,$u);$serviceIds=BlueVPN_Utils::json_decode_array((string)($plan['guardcore_service_ids_json']??''),[]);$remote=self::gc_provision($p,$u,$expire,$quota,$serviceIds,'BlueVPN WordPress; customer '.$customerId,$old);$update['guardcore_panel_id']=$p['id'];$update['guardcore_username']=$u;$update['guardcore_subscription_id']=is_numeric($remote['id']??null)?(int)$remote['id']:null;$update['guardcore_subscription_url']=(string)($remote['subscription_url']??'');$update['guardcore_status']=(string)($remote['status']??'active');$update['guardcore_expire']=$remote['expire']??$expire;$update['guardcore_data_limit_bytes']=(int)($remote['data_limit']??$quota);$update['guardcore_used_traffic_bytes']=(int)($remote['used_traffic']??0);$update['guardcore_last_error']='';$success++;}}catch(Throwable $e){$errors[]='GuardCore: '.$e->getMessage();$update['guardcore_last_error']=mb_substr($e->getMessage(),0,1800);}
        }
        if(empty($c['subscription_token']))$update['subscription_token']=BlueVPN_Utils::random_token(30);else$update['subscription_token']=$c['subscription_token'];$update['subscription_url']=home_url('/sub/'.$update['subscription_token']);$update['subscription_status']=$success>0?'active':'inactive';$update['last_sync_at']=BlueVPN_Utils::now_mysql();$update['last_sync_error']=implode(' | ',$errors);
        $wpdb->update($ct,$update,['id'=>$customerId]);
        return ['ok'=>$success>0&&count($errors)===0,'partial'=>$success>0&&count($errors)>0,'message'=>$errors?implode(' | ',$errors):'فعال‌سازی Providerها انجام شد.','success_count'=>$success];
    }
    public static function sync_customer(int $customerId): array {
        global $wpdb;
        $ct=BlueVPN_DB::table('customers');
        $c=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$ct} WHERE id=%d",$customerId),ARRAY_A);
        if(!$c)return ['ok'=>false,'message'=>'کاربر پیدا نشد.'];
        $u=[];$errors=[];$active=false;$used=0;$exp=[];
        if(!empty($c['panel_id'])&&!empty($c['pg_username']))try{$p=self::panel('pasarguard',(int)$c['panel_id']);if($p){$r=self::pg_user($p,(string)$c['pg_username']);if($r){$active=$active||in_array(strtolower((string)($r['status']??'active')),['active','enabled'],true);$u['pasarguard_subscription_url']=self::remote_sub_url($r,(string)$p['base_url'])?:$c['pasarguard_subscription_url'];$used+=max(0,(int)($r['used_traffic']??$r['used_traffic_bytes']??0));$exp[]=self::remote_expiry($r['expire']??null);}}}catch(Throwable $e){$errors[]='PasarGuard: '.$e->getMessage();}
        if(!empty($c['marzban_panel_id'])&&!empty($c['marzban_username']))try{$p=self::panel('marzban',(int)$c['marzban_panel_id']);if($p){$r=self::mz_user($p,(string)$c['marzban_username']);if($r){$active=$active||in_array(strtolower((string)($r['status']??'active')),['active','enabled'],true);$u['marzban_subscription_url']=self::remote_sub_url($r,(string)$p['base_url'])?:$c['marzban_subscription_url'];$u['marzban_status']=(string)($r['status']??'active');$u['marzban_used_traffic_bytes']=max(0,(int)($r['used_traffic']??0));$used+=max(0,(int)($r['used_traffic']??0));$exp[]=self::remote_expiry($r['expire']??null);}}}catch(Throwable $e){$errors[]='Marzban: '.$e->getMessage();$u['marzban_last_error']=mb_substr($e->getMessage(),0,1800);}
        if(!empty($c['guardcore_panel_id'])&&!empty($c['guardcore_username']))try{$p=self::panel('guardcore',(int)$c['guardcore_panel_id']);if($p&&($p['auth_mode']??'manual')!=='manual'){$r=self::gc_user($p,(string)$c['guardcore_username']);if($r){$active=$active||($r['status']==='active');$u['guardcore_subscription_id']=is_numeric($r['id']??null)?(int)$r['id']:$c['guardcore_subscription_id'];$u['guardcore_subscription_url']=(string)($r['subscription_url']?:$c['guardcore_subscription_url']);$u['guardcore_status']=(string)$r['status'];$u['guardcore_expire']=$r['expire'];$u['guardcore_data_limit_bytes']=(int)$r['data_limit'];$u['guardcore_used_traffic_bytes']=(int)$r['used_traffic'];$u['guardcore_last_error']='';$used+=max(0,(int)$r['used_traffic']);$exp[]=$r['expire'];}}elseif(!empty($c['guardcore_subscription_url']))$active=true;}catch(Throwable $e){$errors[]='GuardCore: '.$e->getMessage();$u['guardcore_last_error']=mb_substr($e->getMessage(),0,1800);}
        elseif(!empty($c['guardcore_subscription_url']))$active=true;
        $valid=array_values(array_filter($exp));if($valid)$u['subscription_expire']=max($valid);$u['used_traffic_bytes']=$used;$u['subscription_status']=$active?'active':'inactive';$u['last_sync_at']=BlueVPN_Utils::now_mysql();$u['last_sync_error']=implode(' | ',$errors);$wpdb->update($ct,$u,['id'=>$customerId]);return ['ok'=>!$errors,'message'=>$errors?implode(' | ',$errors):'همگام‌سازی انجام شد.'];
    }
    public static function attach_guardcore(int $customerId,string $url): array {
        global $wpdb;$url=esc_url_raw(trim($url));if($url==='')return ['ok'=>false,'message'=>'لینک اشتراک معتبر نیست.'];$t=BlueVPN_DB::table('customers');$ok=$wpdb->update($t,['guardcore_subscription_url'=>$url,'guardcore_status'=>'active','last_sync_at'=>BlueVPN_Utils::now_mysql()],['id'=>$customerId]);return ['ok'=>$ok!==false,'message'=>$ok===false?'ذخیره نشد.':'لینک GuardCore ثبت شد.'];
    }
    private static function subscription_lines(string $text): array {
        $text=trim($text);if($text==='')return [];$decoded=base64_decode(preg_replace('/\s+/','',$text),true);if($decoded!==false&&preg_match('~(?:vless|vmess|trojan|ss|hysteria2|tuic)://~i',$decoded))$text=$decoded;
        $lines=preg_split('/\R+/',trim($text))?:[];return array_values(array_filter(array_map('trim',$lines),fn($x)=>preg_match('~^(?:vless|vmess|trojan|ss|hysteria2|tuic)://~i',$x)));
    }
    public static function serve_subscription(): void {
        $path=(string)(parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH)??'');if(!preg_match('~^/sub/([A-Za-z0-9_-]{10,100})/?$~',$path,$m))return;
        global $wpdb;$t=BlueVPN_DB::table('customers');$c=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE subscription_token=%s AND active=1 LIMIT 1",$m[1]),ARRAY_A);if(!$c){status_header(404);header('Content-Type: text/plain; charset=utf-8');echo 'subscription not found';exit;}
        $sources=[];foreach(['pasarguard_subscription_url','marzban_subscription_url','guardcore_subscription_url'] as $k)if(!empty($c[$k]))$sources[]=(string)$c[$k];$lines=[];$seen=[];$errors=[];
        foreach($sources as $url){$r=wp_remote_get($url,['timeout'=>20,'redirection'=>3,'sslverify'=>true,'headers'=>['User-Agent'=>'BlueVPN-WordPress/'.BLUEVPN_MANAGER_VERSION]]);if(is_wp_error($r)){$errors[]=$r->get_error_message();continue;}if((int)wp_remote_retrieve_response_code($r)>=400){$errors[]='HTTP '.wp_remote_retrieve_response_code($r);continue;}foreach(self::subscription_lines((string)wp_remote_retrieve_body($r)) as $line){$key=sha1($line);if(isset($seen[$key]))continue;$seen[$key]=1;$lines[]=$line;}}
        if(!$lines){status_header(502);header('Content-Type: text/plain; charset=utf-8');echo 'No usable configs. '.implode(' | ',$errors);exit;}
        header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: no-store, no-cache, must-revalidate');header('profile-title: base64:'.base64_encode('BlueVPN'));header('profile-update-interval: 1');$expiry=!empty($c['subscription_expire'])?strtotime((string)$c['subscription_expire'].' UTC'):0;header('subscription-userinfo: upload=0; download='.(int)$c['used_traffic_bytes'].'; total='.(int)$c['data_limit_bytes'].'; expire='.(int)$expiry);header('X-BlueVPN-Config-Count: '.count($lines));echo base64_encode(implode("\n",$lines)."\n");exit;
    }
}
