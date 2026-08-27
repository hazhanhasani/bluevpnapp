<?php
if (!defined('ABSPATH')) exit;

/**
 * First-party paid subscription sources which are not tied to a provider API.
 * URL tokens and inline configs are encrypted at rest and are never returned to apps.
 */
final class BlueVPN_Subscription_Sources {
    private const CACHE_TTL_SECONDS = 300;
    private const STALE_IF_ERROR_SECONDS = 1800;
    public const SHAHRah_DOCS_URL = 'https://shahrah.top/panel/user/vaas/web-service';

    public static function init(): void {
        add_action('admin_post_bluevpn_cc_save_subscription_source',[self::class,'save']);
        add_action('admin_post_bluevpn_cc_toggle_subscription_source',[self::class,'toggle']);
        add_action('admin_post_bluevpn_cc_delete_subscription_source',[self::class,'delete']);
        add_action('admin_post_bluevpn_cc_test_subscription_source',[self::class,'test']);
    }

    private static function table(): string { return BlueVPN_DB::table('subscription_sources'); }

    /**
     * Register Shahrah as a first-class paid subscription source without
     * guessing private API credentials/endpoints from the authenticated docs page.
     * It is intentionally inactive until the real subscription endpoint is saved.
     */
    private static function ensure_shahrah_source(): void {
        global $wpdb;
        $table=self::table();
        $exists=$wpdb->get_var("SELECT id FROM {$table} WHERE source_type='shahrah' ORDER BY id ASC LIMIT 1");
        if($exists)return;
        $now=BlueVPN_Utils::now_mysql();
        $wpdb->insert($table,[
            'name'=>'شاهراه',
            'source_type'=>'shahrah',
            'payload_enc'=>'',
            'active'=>0,
            'last_test_ok'=>0,
            'last_test_message'=>'وب‌سرویس شاهراه ثبت شد. API KEY و planSlug را وارد کنید، تست اتصال بگیرید و سپس Source را فعال کنید: '.self::SHAHRah_DOCS_URL,
            'last_test_at'=>null,
            'created_at'=>$now,
            'updated_at'=>$now,
        ]);
    }
    private static function guard(): void { if(!current_user_can('manage_options'))wp_die('دسترسی ندارید.'); }
    private static function redirect(string $message,bool $error=false): void {
        $key=$error?'cc_error':'cc_msg';wp_safe_redirect(add_query_arg([$key=>$message],admin_url('admin.php?page=bluevpn-subscription-sources')));exit;
    }

    public static function rows(bool $activeOnly=false): array {
        global $wpdb;$where=$activeOnly?' WHERE active=1':'';
        return $wpdb->get_results('SELECT * FROM '.self::table().$where.' ORDER BY active DESC,name ASC,id ASC',ARRAY_A)?:[];
    }

    public static function source(int $id): ?array {
        if($id<=0)return null;global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE id=%d LIMIT 1',$id),ARRAY_A);return is_array($row)?$row:null;
    }

    public static function plaintext(array $row): string {
        return BlueVPN_Utils::decrypt_secret((string)($row['payload_enc']??''));
    }

    /**
     * Shahrah source payload is encrypted JSON:
     * {"api_key":"...","plan_slug":"..."}
     * The secret is never returned to an app or rendered back into HTML.
     */
    public static function shahrah_payload(string $payload): array {
        $decoded=BlueVPN_Utils::json_decode_array(trim($payload),[]);
        if(!$decoded)return ['api_key'=>'','plan_slug'=>''];
        return [
            'api_key'=>trim((string)($decoded['api_key']??'')),
            'plan_slug'=>trim((string)($decoded['plan_slug']??$decoded['planSlug']??'')),
        ];
    }

    public static function shahrah_credentials_from_entry(array $entry): array {
        return self::shahrah_payload((string)($entry['payload']??''));
    }

    public static function source_ids_for_plan(array $plan): array {
        $ids=BlueVPN_Utils::json_decode_array((string)($plan['source_ids_json']??''),[]);$out=[];
        foreach($ids as $id){$n=(int)$id;if($n>0&&!in_array($n,$out,true))$out[]=$n;}
        return array_slice($out,0,200);
    }

    public static function active_entries_for_plan(int $planId): array {
        if($planId<=0)return [];global $wpdb;$pt=BlueVPN_DB::table('plans');$plan=$wpdb->get_row($wpdb->prepare("SELECT source_ids_json FROM {$pt} WHERE id=%d AND deleted=0 LIMIT 1",$planId),ARRAY_A);if(!$plan)return [];
        $ids=self::source_ids_for_plan($plan);if(!$ids)return [];
        $placeholders=implode(',',array_fill(0,count($ids),'%d'));
        $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::table()." WHERE active=1 AND id IN ({$placeholders}) ORDER BY name,id",...$ids),ARRAY_A)?:[];
        $out=[];foreach($rows as $row){$payload=self::plaintext($row);if(trim($payload)==='')continue;$sourceType=(string)$row['source_type'];$key=$sourceType==='shahrah'?'manual:shahrah:'.(int)$row['id']:'manual:'.(int)$row['id'];$runtimeType=$sourceType==='inline'?'inline':($sourceType==='shahrah'?'shahrah':'url');$out[]=['key'=>$key,'id'=>(int)$row['id'],'name'=>(string)$row['name'],'type'=>$runtimeType,'provider_type'=>$sourceType,'payload'=>$payload];}
        return $out;
    }

    private static function supported_uri_pattern(): string {
        return '~^(?:vless|vmess|trojan|ss|hysteria2|hysteria|hy2|tuic)://~i';
    }

    private static function decode_subscription_body(string $text): string {
        $text=trim(preg_replace('/^\xEF\xBB\xBF/','',$text)??$text);
        if($text==='')return '';
        if(preg_match('~(?:vless|vmess|trojan|ss|hysteria2|hysteria|hy2|tuic)://~i',$text))return $text;
        $compact=preg_replace('/\s+/','',$text)??'';
        if($compact===''||strlen($compact)>16*1024*1024)return $text;
        $normalized=strtr($compact,'-_','+/');
        $normalized.=str_repeat('=',(4-(strlen($normalized)%4))%4);
        $decoded=base64_decode($normalized,true);
        if($decoded!==false&&preg_match('~(?:vless|vmess|trojan|ss|hysteria2|hysteria|hy2|tuic)://~i',$decoded))return trim($decoded);
        return $text;
    }

    public static function parse_lines(string $text): array {
        $text=self::decode_subscription_body($text);if($text==='')return [];
        // Some subscription servers separate entries with spaces or JSON-style
        // escaped newlines. Normalize only boundaries before supported URI schemes;
        // query-string spaces inside an individual URI are left untouched.
        $text=str_replace(["\\r\\n","\\n","\\r"],"\n",$text);
        $text=preg_replace('~[\t ,]+(?=(?:vless|vmess|trojan|ss|hysteria2|hysteria|hy2|tuic)://)~i',"\n",$text)??$text;
        $lines=preg_split('/\R+/',trim($text))?:[];$out=[];$seen=[];
        foreach($lines as $line){
            $line=trim($line," \t\n\r\0\x0B\"'");
            if(!preg_match(self::supported_uri_pattern(),$line))continue;
            $key=hash('sha256',$line);if(isset($seen[$key]))continue;$seen[$key]=1;$out[]=$line;
        }
        return $out;
    }

    private static function is_public_ip(string $ip): bool {
        return filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)!==false;
    }

    private static function validate_subscription_url(string $url): array {
        $url=trim($url);
        if($url===''||strlen($url)>4096)return ['ok'=>false,'url'=>'','message'=>'URL خالی یا بیش از حد طولانی است.'];
        $parts=wp_parse_url($url);
        if(!is_array($parts))return ['ok'=>false,'url'=>'','message'=>'ساختار URL قابل تشخیص نیست.'];
        $scheme=strtolower((string)($parts['scheme']??''));$host=strtolower(rtrim((string)($parts['host']??''),'.'));
        if(!in_array($scheme,['http','https'],true))return ['ok'=>false,'url'=>'','message'=>'فقط URLهای http و https پشتیبانی می‌شوند.'];
        if($host===''||isset($parts['user'])||isset($parts['pass']))return ['ok'=>false,'url'=>'','message'=>'Host معتبر نیست یا URL شامل نام کاربری/رمز عبور است.'];
        $port=isset($parts['port'])?(int)$parts['port']:($scheme==='https'?443:80);
        if($port<1||$port>65535)return ['ok'=>false,'url'=>'','message'=>'Port باید بین 1 تا 65535 باشد.'];
        if($host==='localhost'||str_ends_with($host,'.localhost')||str_ends_with($host,'.local')||str_ends_with($host,'.internal'))return ['ok'=>false,'url'=>'','message'=>'آدرس‌های محلی برای Subscription Source مجاز نیستند.'];
        if(filter_var($host,FILTER_VALIDATE_IP)!==false){
            if(!self::is_public_ip($host))return ['ok'=>false,'url'=>'','message'=>'IP خصوصی/رزروشده برای Subscription Source مجاز نیست.'];
        }else{
            $ascii=function_exists('idn_to_ascii')?idn_to_ascii($host,IDNA_DEFAULT,INTL_IDNA_VARIANT_UTS46):$host;
            if(!is_string($ascii)||$ascii===''||strlen($ascii)>253||!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i',$ascii))return ['ok'=>false,'url'=>'','message'=>'نام دامنه URL معتبر نیست.'];
            // Keep custom ports (8000/8443/2053/...) while retaining the host-side
            // SSRF protection that wp_http_validate_url normally gives us. The
            // default-port probe validates the public hostname independently of the
            // subscription port which WordPress otherwise rejects as unsafe.
            $probe=$scheme.'://'.$ascii.'/';
            if(!wp_http_validate_url($probe))return ['ok'=>false,'url'=>'','message'=>'دامنه Subscription از نظر WordPress امن/عمومی تشخیص داده نشد.'];
            $resolved=@gethostbynamel($ascii)?:[];
            foreach($resolved as $ip)if(!self::is_public_ip((string)$ip))return ['ok'=>false,'url'=>'','message'=>'دامنه Subscription به IP خصوصی/رزروشده resolve می‌شود.'];
        }
        return ['ok'=>true,'url'=>$url,'message'=>''];
    }

    private static function endpoint_label(string $url): string {
        $p=wp_parse_url($url);if(!is_array($p))return 'subscription';
        $scheme=strtolower((string)($p['scheme']??'https'));$host=(string)($p['host']??'subscription');$port=(int)($p['port']??0);
        return $scheme.'://'.$host.($port>0?':'.$port:'');
    }

    private static function transport_error_label(WP_Error $error): string {
        $raw=strtolower((string)$error->get_error_message());
        if(str_contains($raw,'timed out')||str_contains($raw,'timeout'))return 'timeout';
        if(str_contains($raw,'ssl')||str_contains($raw,'certificate')||str_contains($raw,'tls'))return 'tls';
        if(str_contains($raw,'resolve')||str_contains($raw,'dns'))return 'dns';
        if(str_contains($raw,'refused'))return 'connection_refused';
        if(str_contains($raw,'reset'))return 'connection_reset';
        $code=sanitize_key((string)$error->get_error_code());return $code!==''?$code:'transport_error';
    }

    private static function cache_key(string $url): string {
        // Only a digest of the token-bearing URL is stored in the transient key.
        return 'bluevpn_subsrc_'.substr(hash('sha256',trim($url)),0,40);
    }

    private static function cache_success(string $url,array $lines): void {
        if(!$lines)return;
        set_transient(self::cache_key($url),[
            'fetched_at'=>time(),
            'lines'=>array_slice(array_values($lines),0,5000),
        ],self::STALE_IF_ERROR_SECONDS+120);
    }

    private static function cached_success(string $url,int $maxAge): ?array {
        $cached=get_transient(self::cache_key($url));
        if(!is_array($cached))return null;
        $at=(int)($cached['fetched_at']??0);$lines=(array)($cached['lines']??[]);
        if($at<=0||!$lines||time()-$at<0||time()-$at>$maxAge)return null;
        $valid=[];foreach($lines as $line){$line=trim((string)$line);if(preg_match(self::supported_uri_pattern(),$line))$valid[]=$line;}
        if(!$valid)return null;
        return ['lines'=>array_values(array_unique($valid)),'age'=>max(0,time()-$at)];
    }

    private static function stale_fallback(string $url,string $reason,int $status=0): ?array {
        $cached=self::cached_success($url,self::STALE_IF_ERROR_SECONDS);
        if(!$cached)return null;
        $age=(int)$cached['age'];$minutes=max(1,(int)ceil($age/60));
        return [
            'ok'=>true,
            'lines'=>(array)$cached['lines'],
            'message'=>'Source موقتاً در دسترس نیست؛ آخرین نسخه سالم '.$minutes.' دقیقه قبل استفاده شد ('.$reason.').',
            'status'=>$status,
            'endpoint'=>self::endpoint_label($url),
            'stale'=>true,
            'cache_age'=>$age,
        ];
    }

    public static function fetch_url_configs(string $url,int $maxRedirects=4): array {
        $origin=trim($url);$current=$origin;$visited=[];$maxRedirects=max(0,min(5,$maxRedirects));
        for($redirect=0;$redirect<=$maxRedirects;$redirect++){
            $valid=self::validate_subscription_url($current);
            if(empty($valid['ok']))return ['ok'=>false,'lines'=>[],'message'=>(string)$valid['message'],'status'=>0,'endpoint'=>self::endpoint_label($current)];
            $current=(string)$valid['url'];$key=hash('sha256',$current);if(isset($visited[$key]))return ['ok'=>false,'lines'=>[],'message'=>'Redirect loop در Subscription URL شناسایی شد.','status'=>0,'endpoint'=>self::endpoint_label($current)];$visited[$key]=true;
            if($redirect===0){$fresh=self::cached_success($origin,self::CACHE_TTL_SECONDS);if($fresh)return ['ok'=>true,'lines'=>(array)$fresh['lines'],'message'=>count((array)$fresh['lines']).' کانفیگ معتبر از cache تازه Source استفاده شد.','status'=>200,'endpoint'=>self::endpoint_label($current),'stale'=>false,'cache_age'=>(int)$fresh['age']];}
            $response=null;$lastError='';$timeouts=[7,12];
            foreach($timeouts as $attempt=>$timeout){
                $headers=['User-Agent'=>'BlueVPN-Subscription-Source/'.BLUEVPN_MANAGER_VERSION,'Accept'=>'text/plain,application/octet-stream,*/*;q=0.8','X-BlueVPN-Sentinel-Ignore'=>'1'];
                $parts=wp_parse_url($current);$scheme=strtolower((string)($parts['scheme']??'https'));$port=isset($parts['port'])?(int)$parts['port']:($scheme==='https'?443:80);
                $allowPort=static function(array $ports)use($port):array{$ports[]=$port;return array_values(array_unique(array_map('intval',$ports)));};
                add_filter('http_allowed_safe_ports',$allowPort,10,1);
                try{$r=wp_safe_remote_get($current,['timeout'=>$timeout,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>8*1024*1024,'headers'=>$headers]);}
                finally{remove_filter('http_allowed_safe_ports',$allowPort,10);}
                if(is_wp_error($r)){$lastError=self::transport_error_label($r);if($attempt+1<count($timeouts)){usleep(250000);continue;}$stale=self::stale_fallback($origin,$lastError,0);if($stale)return $stale;return ['ok'=>false,'lines'=>[],'message'=>'دریافت Subscription از '.self::endpoint_label($current).' ناموفق بود ('.$lastError.').','status'=>0,'endpoint'=>self::endpoint_label($current)];}
                $code=(int)wp_remote_retrieve_response_code($r);
                if(in_array($code,[408,425,429,500,502,503,504],true)&&$attempt+1<count($timeouts)){usleep(350000);continue;}
                $response=$r;break;
            }
            if(!is_array($response))return ['ok'=>false,'lines'=>[],'message'=>'Subscription پاسخ معتبری برنگرداند.','status'=>0,'endpoint'=>self::endpoint_label($current)];
            $code=(int)wp_remote_retrieve_response_code($response);
            if(in_array($code,[301,302,303,307,308],true)){
                if($redirect>=$maxRedirects)return ['ok'=>false,'lines'=>[],'message'=>'تعداد Redirectهای Subscription بیش از حد مجاز است.','status'=>$code,'endpoint'=>self::endpoint_label($current)];
                $location=trim((string)wp_remote_retrieve_header($response,'location'));
                if($location==='')return ['ok'=>false,'lines'=>[],'message'=>'Redirect بدون Location دریافت شد.','status'=>$code,'endpoint'=>self::endpoint_label($current)];
                if(str_starts_with($location,'/')){$p=wp_parse_url($current);$location=(string)($p['scheme']??'https').'://'.(string)($p['host']??'').(isset($p['port'])?':'.(int)$p['port']:'').$location;}
                elseif(!preg_match('~^https?://~i',$location)){$base=preg_replace('~/[^/]*$~','/',$current)??$current;$location=$base.ltrim($location,'/');}
                $current=$location;continue;
            }
            if($code<200||$code>=300){if(in_array($code,[408,425,429,500,502,503,504],true)){$stale=self::stale_fallback($origin,'HTTP '.$code,$code);if($stale)return $stale;}return ['ok'=>false,'lines'=>[],'message'=>'Subscription از '.self::endpoint_label($current).' با HTTP '.$code.' پاسخ داد.','status'=>$code,'endpoint'=>self::endpoint_label($current)];}
            $body=(string)wp_remote_retrieve_body($response);$lines=self::parse_lines($body);
            if(!$lines)return ['ok'=>false,'lines'=>[],'message'=>'پاسخ Subscription دریافت شد اما کانفیگ پشتیبانی‌شده‌ای داخل آن نبود.','status'=>$code,'endpoint'=>self::endpoint_label($current)];
            self::cache_success($origin,$lines);return ['ok'=>true,'lines'=>$lines,'message'=>count($lines).' کانفیگ معتبر از '.self::endpoint_label($current).' دریافت شد.','status'=>$code,'endpoint'=>self::endpoint_label($current),'stale'=>false,'cache_age'=>0];
        }
        return ['ok'=>false,'lines'=>[],'message'=>'Subscription URL قابل دریافت نیست.','status'=>0,'endpoint'=>self::endpoint_label($current)];
    }

    private static function validate_payload(string $type,string $payload): array {
        $payload=trim($payload);if($payload==='')return ['ok'=>false,'message'=>'مقدار Source خالی است.','count'=>0];
        if($type==='shahrah'){
            if(!class_exists('BlueVPN_Shahrah'))return ['ok'=>false,'message'=>'کلاس Provider شاهراه بارگذاری نشده است.','count'=>0];
            $cfg=self::shahrah_payload($payload);
            if($cfg['api_key']===''||$cfg['plan_slug']==='')return ['ok'=>false,'message'=>'API KEY و planSlug شاهراه هر دو الزامی هستند.','count'=>0];
            try{
                $me=BlueVPN_Shahrah::me($cfg['api_key']);
                return ['ok'=>true,'message'=>'اتصال شاهراه موفق است؛ API KEY معتبر است و planSlug برای provisioning ذخیره شد.','count'=>is_array($me['items']??null)?count($me['items']):1];
            }catch(Throwable $e){
                return ['ok'=>false,'message'=>$e->getMessage(),'count'=>0];
            }
        }
        if($type==='inline'){$lines=self::parse_lines($payload);return ['ok'=>!empty($lines),'message'=>$lines?count($lines).' کانفیگ معتبر پیدا شد.':'هیچ کانفیگ پشتیبانی‌شده‌ای پیدا نشد.','count'=>count($lines)];}
        $r=self::fetch_url_configs($payload);return ['ok'=>!empty($r['ok']),'message'=>(string)$r['message'],'count'=>count((array)($r['lines']??[]))];
    }

    public static function save(): void {
        self::guard();$id=max(0,(int)($_POST['source_id']??0));check_admin_referer('bluevpn_cc_save_subscription_source_'.$id);
        global $wpdb;$old=$id>0?self::source($id):null;$name=sanitize_text_field(wp_unslash($_POST['name']??''));$type=sanitize_key((string)($_POST['source_type']??'url'));if(!in_array($type,['url','inline','shahrah'],true))$type='url';if($type==='shahrah'&&(!$old||(string)($old['source_type']??'')!=='shahrah'))self::redirect('Shahrah از صفحه اختصاصی Provider مدیریت می‌شود.',true);
        if($name==='')self::redirect('نام Source نمی‌تواند خالی باشد.',true);
        if($type==='shahrah'){
            $previous=$old?self::shahrah_payload(self::plaintext($old)):['api_key'=>'','plan_slug'=>''];
            $apiKey=trim((string)wp_unslash($_POST['shahrah_api_key']??''));
            $planSlug=trim(sanitize_text_field(wp_unslash((string)($_POST['shahrah_plan_slug']??''))));
            if($apiKey==='')$apiKey=(string)$previous['api_key'];
            if($planSlug==='')$planSlug=(string)$previous['plan_slug'];
            if($apiKey===''||$planSlug==='')self::redirect('برای Source شاهراه، API KEY و planSlug را وارد کن.',true);
            if(strlen($planSlug)>180||!preg_match('/^[A-Za-z0-9._-]+$/',$planSlug))self::redirect('planSlug شاهراه معتبر نیست.',true);
            $raw=BlueVPN_Utils::json_encode(['api_key'=>$apiKey,'plan_slug'=>$planSlug]);
            $payloadEnc=BlueVPN_Utils::encrypt_secret($raw);
        }else{
            $raw=trim((string)wp_unslash($_POST['payload']??''));
            $payloadEnc=$raw!==''?BlueVPN_Utils::encrypt_secret($raw):(string)($old['payload_enc']??'');
            if($payloadEnc==='')self::redirect('URL یا کانفیگ Source را وارد کن.',true);
        }
        $data=['name'=>$name,'source_type'=>$type,'payload_enc'=>$payloadEnc,'active'=>isset($_POST['active'])?1:0,'updated_at'=>BlueVPN_Utils::now_mysql()];
        if($id>0){$ok=$wpdb->update(self::table(),$data,['id'=>$id]);}
        else{$data['created_at']=BlueVPN_Utils::now_mysql();$ok=$wpdb->insert(self::table(),$data);$id=(int)$wpdb->insert_id;}
        if($ok===false)self::redirect('ذخیره Source ناموفق بود.',true);
        // 5.2.1: validate immediately after save so a typo/bad subscription is
        // visible at the moment it is entered rather than much later during a
        // customer's refresh. Failure does not delete the encrypted source.
        $saved=self::source($id);$result=$saved?self::validate_payload((string)$saved['source_type'],self::plaintext($saved)):['ok'=>false,'message'=>'Source بعد از ذخیره قابل خواندن نبود.'];
        if($saved)$wpdb->update(self::table(),['last_test_ok'=>!empty($result['ok'])?1:0,'last_test_message'=>mb_substr((string)($result['message']??''),0,1800),'last_test_at'=>BlueVPN_Utils::now_mysql()],['id'=>$id]);
        self::redirect('Source ذخیره شد. تست خودکار: '.(string)($result['message']??''),empty($result['ok']));
    }

    public static function toggle(): void {
        self::guard();$id=max(0,(int)($_GET['id']??0));check_admin_referer('bluevpn_cc_toggle_subscription_source_'.$id);global $wpdb;$row=self::source($id);if(!$row)self::redirect('Source پیدا نشد.',true);$ok=$wpdb->update(self::table(),['active'=>(int)$row['active']?0:1,'updated_at'=>BlueVPN_Utils::now_mysql()],['id'=>$id]);self::redirect($ok===false?'تغییر وضعیت Source ناموفق بود.':'وضعیت Source تغییر کرد.',$ok===false);
    }

    public static function delete(): void {
        self::guard();$id=max(0,(int)($_POST['source_id']??0));check_admin_referer('bluevpn_cc_delete_subscription_source_'.$id);global $wpdb;$pt=BlueVPN_DB::table('plans');
        $plans=$wpdb->get_results("SELECT id,source_ids_json FROM {$pt} WHERE deleted=0",ARRAY_A)?:[];foreach($plans as $plan){$ids=self::source_ids_for_plan($plan);$next=array_values(array_filter($ids,static fn($x)=>$x!==$id));if($next!==$ids)$wpdb->update($pt,['source_ids_json'=>BlueVPN_Utils::json_encode($next)],['id'=>(int)$plan['id']]);}
        $ok=$wpdb->delete(self::table(),['id'=>$id],['%d']);self::redirect($ok===false?'حذف Source ناموفق بود.':'Source حذف شد و از پلن‌ها جدا شد.',$ok===false);
    }

    public static function test(): void {
        self::guard();$id=max(0,(int)($_GET['id']??0));check_admin_referer('bluevpn_cc_test_subscription_source_'.$id);global $wpdb;$row=self::source($id);if(!$row)self::redirect('Source پیدا نشد.',true);$result=self::validate_payload((string)$row['source_type'],self::plaintext($row));$wpdb->update(self::table(),['last_test_ok'=>$result['ok']?1:0,'last_test_message'=>mb_substr((string)$result['message'],0,1800),'last_test_at'=>BlueVPN_Utils::now_mysql()],['id'=>$id]);self::redirect((string)$result['message'],empty($result['ok']));
    }

    public static function render_plan_picker(array $selected=[]): void {
        $rows=array_values(array_filter(self::rows(true),static fn($row)=>(string)($row['source_type']??'')!=='shahrah'));$selected=array_map('intval',$selected);
        echo '<div class="bvc-card" style="margin-top:10px"><strong>ساب‌ها و کانفیگ‌های دستی</strong><p class="description">این Sourceها فقط سمت سرور نگهداری می‌شوند و در حالت Gateway به اپ کاربر لو نمی‌روند.</p>';
        if(!$rows){echo '<p class="description">Source دستی فعالی وجود ندارد. از بخش «Sourceهای اشتراک» اضافه کن.</p></div>';return;}
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px">';foreach($rows as $row){$id=(int)$row['id'];echo '<label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="source_ids_selected[]" value="'.$id.'" '.checked(in_array($id,$selected,true),true,false).'> '.esc_html((string)$row['name']).' <small>('.esc_html((string)$row['source_type']).')</small></label>';}
        echo '</div></div>';
    }

    public static function render_admin_tab(): void {
        $rows=array_values(array_filter(self::rows(false),static fn($row)=>(string)($row['source_type']??'')!=='shahrah'));
        echo '<div class="bvc-page-tools"><div><h2 class="bvc-section-title">Sourceهای اشتراک پولی</h2><p class="bvc-section-subtitle">ساب URL یا کانفیگ دستی را رمزنگاری‌شده ذخیره کن؛ پورت‌های سفارشی مثل 8000/8443 پشتیبانی می‌شوند؛ در قطعی موقت هم آخرین Source سالم حداکثر ۳۰ دقیقه به‌صورت fail-safe نگه داشته می‌شود.</p><p class="description"><strong>Shahrah</strong> دیگر Source دستی نیست و از صفحه اختصاصی خودش مدیریت می‌شود. <a href="'.esc_url(admin_url('admin.php?page=bluevpn-shahrah')).'">رفتن به Shahrah</a></p></div></div>';
        echo '<details class="bvc-card bvc-disclosure" '.(!$rows?'open':'').'><summary><span><strong>افزودن Source</strong><small>URL یا متن کانفیگ</small></span><span>⌄</span></summary><div class="bvc-disclosure-body"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_save_subscription_source_0');echo '<input type="hidden" name="action" value="bluevpn_cc_save_subscription_source"><input type="hidden" name="source_id" value="0"><div class="bvc-form-grid"><label>نام<input name="name" required></label><label>نوع<select name="source_type"><option value="url">Subscription URL (custom ports supported)</option><option value="inline">Inline configs</option></select></label></div><label style="display:block;margin-top:10px">URL / Configs<textarea name="payload" rows="5" style="width:100%"></textarea></label><label><input type="checkbox" name="active" value="1" checked> فعال</label><div class="bvc-form-actions"><button class="button button-primary">ذخیره Source</button></div></form></div></details>';
        if(!$rows){echo '<div class="bvc-empty-state"><strong>هنوز Source دستی ثبت نشده است.</strong></div>';return;}
        echo '<div class="bvc-plan-list">';foreach($rows as $row){$id=(int)$row['id'];$shahrahCfg=(string)$row['source_type']==='shahrah'?self::shahrah_payload(self::plaintext($row)):['api_key'=>'','plan_slug'=>''];$toggle=wp_nonce_url(admin_url('admin-post.php?action=bluevpn_cc_toggle_subscription_source&id='.$id),'bluevpn_cc_toggle_subscription_source_'.$id);$test=wp_nonce_url(admin_url('admin-post.php?action=bluevpn_cc_test_subscription_source&id='.$id),'bluevpn_cc_test_subscription_source_'.$id);echo '<article class="bvc-plan-card '.((int)$row['active']?'is-active':'is-inactive').'"><header class="bvc-plan-head"><div><h3>'.esc_html((string)$row['name']).'</h3><p>'.esc_html(strtoupper((string)$row['source_type'])).' • Payload encrypted at rest</p></div><span class="bvc-status-pill '.((int)$row['active']?'is-active':'is-inactive').'">'.((int)$row['active']?'فعال':'غیرفعال').'</span></header><div class="bvc-plan-metrics"><div><span>آخرین تست</span><strong>'.(!empty($row['last_test_at'])?esc_html(BlueVPN_Utils::tehran_datetime_fa((string)$row['last_test_at'])):'—').'</strong></div><div><span>نتیجه</span><strong>'.((int)$row['last_test_ok']?'سالم':'نیاز به تست').'</strong></div></div><div class="bvc-actions"><a class="button" href="'.esc_url($test).'">تست</a><a class="button" href="'.esc_url($toggle).'">'.((int)$row['active']?'غیرفعال':'فعال').' کردن</a></div><details class="bvc-plan-routing"><summary>ویرایش</summary><div class="bvc-plan-routing-body"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_save_subscription_source_'.$id);echo '<input type="hidden" name="action" value="bluevpn_cc_save_subscription_source"><input type="hidden" name="source_id" value="'.$id.'"><div class="bvc-form-grid"><label>نام<input name="name" value="'.esc_attr((string)$row['name']).'" required></label><label>نوع<select name="source_type"><option value="url" '.selected((string)$row['source_type'],'url',false).'>Subscription URL</option><option value="inline" '.selected((string)$row['source_type'],'inline',false).'>Inline configs</option></select></label></div><label style="display:block;margin-top:10px">Payload جدید (خالی = بدون تغییر)<textarea name="payload" rows="5" style="width:100%"></textarea></label><label><input type="checkbox" name="active" value="1" '.checked((int)$row['active'],1,false).'> فعال</label><button class="button button-primary">ذخیره</button></form><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin-top:10px">';wp_nonce_field('bluevpn_cc_delete_subscription_source_'.$id);echo '<input type="hidden" name="action" value="bluevpn_cc_delete_subscription_source"><input type="hidden" name="source_id" value="'.$id.'"><button class="button button-link-delete" onclick="return confirm(\'حذف شود؟\')">حذف</button></form></div></details></article>';}
        echo '</div>';
    }
}
