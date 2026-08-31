<?php
if (!defined('ABSPATH')) exit;

/**
 * 3x-ui provider adapter using the first-class Clients API.
 *
 * Preferred authentication is a Bearer API Token. Username/password is kept
 * only as a compatibility fallback and the session cookie lives in memory for
 * the current PHP request; it is never persisted as plaintext.
 */
final class BlueVPN_ThreeXUI {
    private static array $sessionHeaders=[];

    private static function table(): string { return BlueVPN_DB::table('threexui_panels'); }

    public static function panel(int $id): ?array {
        if($id<=0)return null;global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE id=%d LIMIT 1',$id),ARRAY_A);
        return is_array($row)?$row:null;
    }

    private static function join(string $base,string $path): string { return rtrim($base,'/').'/'.ltrim($path,'/'); }

    private static function login(array $panel): array {
        $id=(int)($panel['id']??0);if(isset(self::$sessionHeaders[$id]))return self::$sessionHeaders[$id];
        $token=BlueVPN_Utils::decrypt_secret((string)($panel['api_token_enc']??''));
        if($token!=='')return self::$sessionHeaders[$id]=['Authorization'=>'Bearer '.$token];
        $username=BlueVPN_Utils::decrypt_secret((string)($panel['username_enc']??''));
        $password=BlueVPN_Utils::decrypt_secret((string)($panel['password_enc']??''));
        if($username===''||$password==='')throw new RuntimeException('برای 3x-ui API Token یا Username/Password لازم است.');
        $url=self::join((string)$panel['base_url'],'/login');
        $res=wp_remote_request($url,[
            'method'=>'POST','timeout'=>15,'redirection'=>2,'sslverify'=>!empty($panel['verify_tls']),
            'headers'=>['Accept'=>'application/json','User-Agent'=>'BlueVPN-3xUI/'.BLUEVPN_MANAGER_VERSION,'X-BlueVPN-Sentinel-Ignore'=>'1'],
            'body'=>['username'=>$username,'password'=>$password],
        ]);
        if(is_wp_error($res))throw new RuntimeException($res->get_error_message());
        $code=(int)wp_remote_retrieve_response_code($res);if($code>=400)throw new RuntimeException('ورود 3x-ui ناموفق: HTTP '.$code);
        $cookie='';
        foreach(wp_remote_retrieve_cookies($res) as $c){
            $name=(string)($c->name??'');$value=(string)($c->value??'');
            if($name==='3x-ui'&&$value!==''){$cookie='3x-ui='.$value;break;}
        }
        if($cookie===''){
            $set=(string)wp_remote_retrieve_header($res,'set-cookie');
            if(preg_match('/(?:^|[;,]\s*)3x-ui=([^;]+)/i',$set,$m))$cookie='3x-ui='.$m[1];
        }
        if($cookie==='')throw new RuntimeException('3x-ui نشست ورود معتبر برنگرداند؛ API Token توصیه می‌شود.');
        return self::$sessionHeaders[$id]=['Cookie'=>$cookie];
    }

    private static function request(array $panel,string $method,string $path,?array $json=null,int $timeout=20): array {
        $method=strtoupper($method);$headers=array_merge([
            'Accept'=>'application/json','User-Agent'=>'BlueVPN-3xUI/'.BLUEVPN_MANAGER_VERSION,
        ],self::login($panel));
        if($json!==null)$headers['Content-Type']='application/json';
        $attempts=$method==='GET'?2:1;$last='';
        for($i=0;$i<$attempts;$i++){
            $args=['method'=>$method,'timeout'=>max(4,min(25,$timeout)),'redirection'=>2,'sslverify'=>!empty($panel['verify_tls']),'headers'=>$headers];
            if($json!==null)$args['body']=wp_json_encode($json);
            if($i+1<$attempts){$args['headers']['X-BlueVPN-Sentinel-Transient']='1';$args['timeout']=min(8,(int)$args['timeout']);}
            $res=wp_remote_request(self::join((string)$panel['base_url'],$path),$args);
            if(is_wp_error($res)){$last=$res->get_error_message();if($i+1<$attempts){usleep(250000);continue;}throw new RuntimeException($last);}
            $code=(int)wp_remote_retrieve_response_code($res);$body=(string)wp_remote_retrieve_body($res);
            if($i+1<$attempts&&in_array($code,[408,425,429,500,502,503,504],true)){usleep(350000);continue;}
            $decoded=json_decode($body,true);return ['code'=>$code,'body'=>$body,'json'=>is_array($decoded)?$decoded:[]];
        }
        throw new RuntimeException($last!==''?$last:'3x-ui پاسخ معتبری برنگرداند.');
    }

    private static function obj(array $r) {
        $j=(array)($r['json']??[]);
        if(array_key_exists('success',$j)&&!BlueVPN_Utils::boolish($j['success'])){
            $msg=trim((string)($j['msg']??$j['message']??'3x-ui request failed'));
            throw new RuntimeException($msg!==''?$msg:'3x-ui request failed');
        }
        return $j['obj']??$j['data']??$j['result']??$j;
    }

    public static function inbounds(int $panelId): array {
        $p=self::panel($panelId);if(!$p)throw new RuntimeException('پنل 3x-ui پیدا نشد.');
        $r=self::request($p,'GET','/panel/api/inbounds/list',null,15);if($r['code']>=400)throw new RuntimeException('دریافت Inboundهای 3x-ui ناموفق: HTTP '.$r['code']);
        $raw=self::obj($r);if(!is_array($raw))return [];$out=[];
        foreach($raw as $row){
            if(!is_array($row))continue;$id=(int)($row['id']??0);if($id<=0)continue;
            $enabled=!array_key_exists('enable',$row)||BlueVPN_Utils::boolish($row['enable']);if(!$enabled)continue;
            $out[]=['id'=>$id,'remark'=>(string)($row['remark']??$row['tag']??('Inbound #'.$id)),'protocol'=>(string)($row['protocol']??''),'port'=>(int)($row['port']??0),'enable'=>true];
        }
        return $out;
    }

    public static function client(int $panelId,string $email): ?array {
        $email=trim($email);if($email==='')return null;$p=self::panel($panelId);if(!$p)return null;
        try{$r=self::request($p,'GET','/panel/api/clients/get/'.rawurlencode($email),null,12);}catch(Throwable $e){if(str_contains(strtolower($e->getMessage()),'not found'))return null;throw $e;}
        if(in_array($r['code'],[400,404],true))return null;if($r['code']>=400)throw new RuntimeException('خواندن Client 3x-ui ناموفق: HTTP '.$r['code']);
        $obj=self::obj($r);if(!is_array($obj)||!$obj)return null;
        if(isset($obj['client'])&&is_array($obj['client'])){$client=$obj['client'];$client['inboundIds']=$obj['inboundIds']??$client['inboundIds']??[];return $client;}
        return $obj;
    }

    private static function selected_inbounds(int $panelId,array $selected): array {
        $live=self::inbounds($panelId);$ids=array_values(array_unique(array_filter(array_map('intval',$selected),static fn($v)=>$v>0)));
        $available=array_map(static fn($r)=>(int)$r['id'],$live);
        if(!$ids)return $available;
        $chosen=array_values(array_intersect($ids,$available));if(!$chosen)throw new RuntimeException('Inboundهای انتخاب‌شده 3x-ui دیگر فعال/موجود نیستند.');
        return $chosen;
    }

    private static function reconcile_inbounds(array $panel,string $email,array $current,array $chosen): void {
        $current=array_values(array_unique(array_filter(array_map('intval',$current),static fn($v)=>$v>0)));
        $chosen=array_values(array_unique(array_filter(array_map('intval',$chosen),static fn($v)=>$v>0)));
        sort($current);sort($chosen);
        $attach=array_values(array_diff($chosen,$current));
        $detach=array_values(array_diff($current,$chosen));

        // Attach first. If a new inbound rejects the client (for example a
        // WireGuard allowedIPs collision), keep the old memberships untouched.
        if($attach){
            $r=self::request($panel,'POST','/panel/api/clients/'.rawurlencode($email).'/attach',['inboundIds'=>$attach],15);
            if($r['code']>=400)throw new RuntimeException('Attach 3x-ui ناموفق: HTTP '.$r['code'].' '.mb_substr($r['body'],0,220));
            self::obj($r);
        }
        if($detach){
            $r=self::request($panel,'POST','/panel/api/clients/'.rawurlencode($email).'/detach',['inboundIds'=>$detach],15);
            if($r['code']>=400)throw new RuntimeException('Detach 3x-ui ناموفق: HTTP '.$r['code'].' '.mb_substr($r['body'],0,220));
            self::obj($r);
        }
    }

    private static function links(int $panelId,string $email,string $subId=''): array {
        $p=self::panel($panelId);if(!$p)return [];
        $r=self::request($p,'GET','/panel/api/clients/links/'.rawurlencode($email),null,12);
        if($r['code']<400){$obj=self::obj($r);if(is_array($obj))return $obj;}
        if($subId!==''){
            $r=self::request($p,'GET','/panel/api/clients/subLinks/'.rawurlencode($subId),null,12);
            if($r['code']<400){$obj=self::obj($r);if(is_array($obj))return $obj;}
        }
        return [];
    }

    private static function subscription_url(array $panel,array $client,string $email): string {
        foreach(['subscription_url','subUrl','sub_link','subLink'] as $key){$u=trim((string)($client[$key]??''));if(preg_match('~^https?://~i',$u))return esc_url_raw($u);}
        $subId=trim((string)($client['subId']??$client['sub_id']??''));
        try{
            $links=self::links((int)$panel['id'],$email,$subId);
            foreach($links as $v){
                if(is_string($v)&&preg_match('~^https?://~i',$v))return esc_url_raw($v);
                if(is_array($v))foreach(['url','link','subscription'] as $k){$u=trim((string)($v[$k]??''));if(preg_match('~^https?://~i',$u))return esc_url_raw($u);}
            }
        }catch(Throwable $ignore){}
        $base=trim((string)($panel['subscription_base_url']??''));if($base!==''&&$subId!=='')return esc_url_raw(rtrim($base,'/').'/'.rawurlencode($subId));
        return '';
    }

    private static function normalize(array $panel,array $client): array {
        $email=(string)($client['email']??'');$up=max(0,(int)($client['up']??$client['upload']??0));$down=max(0,(int)($client['down']??$client['download']??0));
        $expiry=(int)($client['expiryTime']??0);$expire=$expiry>0?gmdate('Y-m-d H:i:s',(int)floor($expiry/1000)):null;
        return [
            'email'=>$email,'remote_id'=>(string)($client['id']??$client['uuid']??$client['subId']??$email),
            'sub_id'=>(string)($client['subId']??''),'status'=>(!array_key_exists('enable',$client)||BlueVPN_Utils::boolish($client['enable']))?'active':'disabled',
            'used_traffic_bytes'=>$up+$down,'data_limit_bytes'=>max(0,(int)($client['totalGB']??0)),'expire'=>$expire,
            'inbound_ids'=>array_values(array_unique(array_map('intval',(array)($client['inboundIds']??[])))),
            'subscription_url'=>self::subscription_url($panel,$client,$email),'raw'=>$client,
        ];
    }

    public static function provision(int $panelId,string $email,int $quotaBytes,?string $expire,int $deviceLimit,array $inboundIds=[],string $note=''): array {
        $p=self::panel($panelId);if(!$p||empty($p['active']))throw new RuntimeException('پنل 3x-ui فعال پیدا نشد.');
        $email=trim($email);if($email==='')throw new RuntimeException('شناسه Client 3x-ui خالی است.');
        $chosen=self::selected_inbounds($panelId,$inboundIds);if(!$chosen)throw new RuntimeException('هیچ Inbound فعال 3x-ui پیدا نشد.');
        $old=self::client($panelId,$email);
        $client=is_array($old)?$old:[];
        $client['email']=$email;$client['totalGB']=max(0,$quotaBytes);$client['expiryTime']=$expire?(int)(strtotime($expire.' UTC')*1000):0;
        $client['limitIp']=max(0,min(20,$deviceLimit));$client['enable']=true;$client['comment']=mb_substr($note,0,500);
        // Update replaces the client row, so preserve server-generated protocol secrets.
        // Inbound membership is a separate first-class relationship in 3x-ui:
        // update the client row first, then reconcile memberships explicitly.
        if($old){
            $currentInboundIds=array_values(array_map('intval',(array)($old['inboundIds']??[])));
            unset($client['inboundIds']);
            $r=self::request($p,'POST','/panel/api/clients/update/'.rawurlencode($email),$client,18);
            if($r['code']>=400)throw new RuntimeException('Provision 3x-ui ناموفق: HTTP '.$r['code'].' '.mb_substr($r['body'],0,300));
            self::obj($r);
            self::reconcile_inbounds($p,$email,$currentInboundIds,$chosen);
        }else{
            $r=self::request($p,'POST','/panel/api/clients/add',['client'=>$client,'inboundIds'=>$chosen],18);
            if($r['code']>=400)throw new RuntimeException('Provision 3x-ui ناموفق: HTTP '.$r['code'].' '.mb_substr($r['body'],0,300));
            self::obj($r);
        }
        $remote=self::client($panelId,$email);if(!$remote){$client['inboundIds']=$chosen;$remote=$client;}
        return self::normalize($p,$remote);
    }

    public static function inspect(int $panelId,string $email): ?array {
        $p=self::panel($panelId);if(!$p)return null;$client=self::client($panelId,$email);return $client?self::normalize($p,$client):null;
    }

    public static function enforce_expiry(int $panelId,string $email,string $canonical): array {
        try{
            $p=self::panel($panelId);if(!$p)return ['ok'=>false,'message'=>'پنل 3x-ui پیدا نشد'];
            $old=self::client($panelId,$email);if(!$old)return ['ok'=>false,'message'=>'Client 3x-ui پیدا نشد'];
            $old['expiryTime']=(int)(strtotime($canonical.' UTC')*1000);
            $r=self::request($p,'POST','/panel/api/clients/update/'.rawurlencode($email),$old,15);self::obj($r);
            return ['ok'=>$r['code']<400,'message'=>'HTTP '.$r['code']];
        }catch(Throwable $e){return ['ok'=>false,'message'=>$e->getMessage()];}
    }

    public static function test(int $panelId): array {
        global $wpdb;$p=self::panel($panelId);if(!$p)return ['ok'=>false,'message'=>'پنل 3x-ui پیدا نشد.'];
        try{
            $inbounds=self::inbounds($panelId);
            $r=self::request($p,'GET','/panel/api/clients/list/paged?page=1&pageSize=1',null,15);
            if($r['code']>=400)$r=self::request($p,'GET','/panel/api/clients/list',null,15);
            if($r['code']>=400)throw new RuntimeException('Clients API: HTTP '.$r['code']);
            $msg='اتصال 3x-ui موفق است؛ '.count($inbounds).' Inbound فعال شناسایی شد.';
            $wpdb->update(self::table(),[
                'api_version'=>'3.x','inbounds_json'=>BlueVPN_Utils::json_encode($inbounds),
                'capabilities_json'=>BlueVPN_Utils::json_encode(['clients_api'=>true,'multi_inbound'=>true,'quota'=>true,'expiry'=>true,'ip_limit'=>true,'subscription_links'=>true,'bearer_token'=>true,'cookie_fallback'=>true]),
                'stats_json'=>BlueVPN_Utils::json_encode(['active_inbounds'=>count($inbounds)]),'last_sync_at'=>BlueVPN_Utils::now_mysql(),
                'last_test_ok'=>1,'last_test_message'=>$msg,'last_test_at'=>BlueVPN_Utils::now_mysql(),'updated_at'=>BlueVPN_Utils::now_mysql(),
            ],['id'=>$panelId]);
            return ['ok'=>true,'message'=>$msg];
        }catch(Throwable $e){
            $msg=mb_substr($e->getMessage(),0,1800);$wpdb->update(self::table(),['last_test_ok'=>0,'last_test_message'=>$msg,'last_test_at'=>BlueVPN_Utils::now_mysql(),'updated_at'=>BlueVPN_Utils::now_mysql()],['id'=>$panelId]);
            return ['ok'=>false,'message'=>$msg];
        }
    }

    public static function catalog(int $panelId,bool $force=false): array {
        $p=self::panel($panelId);if(!$p)return ['ok'=>false,'message'=>'پنل 3x-ui پیدا نشد.'];
        $cachedAt=!empty($p['last_sync_at'])?(strtotime((string)$p['last_sync_at'].' UTC')?:0):0;
        if(!$force&&$cachedAt>0&&time()-$cachedAt<300)return ['ok'=>(int)($p['last_test_ok']??0)===1,'cached'=>true,'version'=>(string)($p['api_version']??'3.x'),'inbounds'=>BlueVPN_Utils::json_decode_array((string)($p['inbounds_json']??''),[]),'stats'=>BlueVPN_Utils::json_decode_array((string)($p['stats_json']??''),[])];
        $r=self::test($panelId);$p=self::panel($panelId)?:$p;
        return ['ok'=>!empty($r['ok']),'cached'=>false,'message'=>(string)$r['message'],'version'=>(string)($p['api_version']??'3.x'),'inbounds'=>BlueVPN_Utils::json_decode_array((string)($p['inbounds_json']??''),[]),'stats'=>BlueVPN_Utils::json_decode_array((string)($p['stats_json']??''),[])];
    }
}
