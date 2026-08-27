<?php
if (!defined('ABSPATH')) exit;

final class BlueVPN_Free_Sources {
    private const DEFAULT_SOURCE_KEY='telegram-persianvpnhub';
    private const DEFAULT_SOURCE_URL='https://t.me/s/persianvpnhub';
    private const ALLOWED_SCHEMES=['vless','vmess','trojan','ss','ssr','tuic','hysteria2','hy2','wireguard'];
    private const RETRYABLE_HTTP=[408,425,429,500,502,503,504];
    private const CRON_FAILURE_COOLDOWN_SECONDS=900;
    private const ALERT_COOLDOWN_SECONDS=1800;
    private const TRANSPORT_ALERT_THRESHOLD=3;
    private const TRANSPORT_FAILURE_WINDOW_SECONDS=21600;

    public static function init(): void {
        add_action('bluevpn_manager_cleanup',[self::class,'cron_refresh'],20);
        add_action('admin_post_bluevpn_free_source_refresh',[self::class,'admin_refresh']);
        add_action('admin_post_bluevpn_free_source_toggle',[self::class,'admin_toggle']);
        add_action('admin_post_bluevpn_free_source_save',[self::class,'admin_save']);
    }

    public static function seed(): void {
        global $wpdb;$t=BlueVPN_DB::table('free_config_sources');
        $exists=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE source_key=%s",self::DEFAULT_SOURCE_KEY));
        if(!$exists)$wpdb->insert($t,[
            'source_key'=>self::DEFAULT_SOURCE_KEY,'source_type'=>'telegram_public','title'=>'VPNhub | کانفیگ رایگان',
            'url'=>self::DEFAULT_SOURCE_URL,'enabled'=>1,'priority'=>10,'fetch_interval_seconds'=>300,'max_items'=>400,
            'created_at'=>BlueVPN_Utils::now_mysql(),'updated_at'=>BlueVPN_Utils::now_mysql(),
        ]);
    }

    private static function cooldown_key(int $id): string { return 'bluevpn_free_source_cooldown_'.$id; }
    private static function alert_key(int $id): string { return 'bluevpn_free_source_alert_'.$id; }
    private static function failure_count_key(int $id): string { return 'bluevpn_free_source_transport_failures_'.$id; }
    private static function transport_code(int $id): string { return 'FREE_SOURCE_TRANSPORT_FAILED_'.$id; }

    public static function cron_refresh(): void {
        self::seed(); global $wpdb;$t=BlueVPN_DB::table('free_config_sources');
        $rows=$wpdb->get_results("SELECT * FROM {$t} WHERE enabled=1 ORDER BY priority,id",ARRAY_A)?:[];
        foreach($rows as $row){
            $id=(int)$row['id'];
            if($id<=0||get_transient(self::cooldown_key($id)))continue;
            $last=!empty($row['last_fetch_at'])?(strtotime((string)$row['last_fetch_at'].' UTC')?:0):0;
            if($last>0&&time()-$last<max(60,(int)$row['fetch_interval_seconds']))continue;
            self::refresh_source($id);
        }
        self::prune();
    }

    private static function source_request_args(int $timeout): array {
        return [
            'timeout'=>$timeout,
            'redirection'=>2,
            'sslverify'=>true,
            'headers'=>[
                'User-Agent'=>'BlueVPN-Collector/'.BLUEVPN_MANAGER_VERSION,
                'Accept'=>'text/html',
                // The free-source collector owns retry/dedup semantics. Suppress
                // the generic HTTP observer so one Telegram outage is not reported
                // once as HTTP_REQUEST_FAILED and again as FREE_SOURCE_FAILED.
                'X-BlueVPN-Sentinel-Ignore'=>'1',
            ],
        ];
    }

    /**
     * Fetch a public Telegram preview with bounded retries.
     *
     * Return shape:
     *   ok=true  => response contains the successful WP HTTP response.
     *   ok=false => message/code are ready for the source status and Sentinel.
     */
    private static function fetch_public_preview(string $url): array {
        $timeouts=[6,10,15];
        $lastMessage='درخواست منبع عمومی ناموفق بود.';
        $lastCode=0;

        foreach($timeouts as $index=>$timeout){
            $res=wp_remote_get($url,self::source_request_args($timeout));
            if(is_wp_error($res)){
                $lastMessage=(string)$res->get_error_message();
                if($index<count($timeouts)-1){
                    usleep(($index===0?250:750)*1000);
                    continue;
                }
                return ['ok'=>false,'transport'=>true,'message'=>$lastMessage,'http_code'=>0];
            }

            $code=(int)wp_remote_retrieve_response_code($res);
            $body=(string)wp_remote_retrieve_body($res);
            $lastCode=$code;

            if($code>=200&&$code<300&&$body!==''){
                return ['ok'=>true,'response'=>$res,'http_code'=>$code];
            }

            $retryable=in_array($code,self::RETRYABLE_HTTP,true)||$code===0||($code>=300&&$code<400)||($code>=200&&$code<300&&$body==='');
            $lastMessage=($body===''&&$code>=200&&$code<300)?('HTTP '.$code.' empty response'):('HTTP '.$code);
            if($retryable&&$index<count($timeouts)-1){
                usleep(($index===0?250:750)*1000);
                continue;
            }
            return ['ok'=>false,'transport'=>$retryable,'message'=>$lastMessage,'http_code'=>$code];
        }

        return ['ok'=>false,'transport'=>true,'message'=>$lastMessage,'http_code'=>$lastCode];
    }

    private static function mark_transport_failure(array $src,string $message): void {
        global $wpdb;
        $id=(int)($src['id']??0);
        if($id<=0)return;
        $st=BlueVPN_DB::table('free_config_sources');
        $now=BlueVPN_Utils::now_mysql();

        // Keep last_error empty for transport outages: Sentinel's operational table
        // scanner uses last_error<>'' for content/runtime failures. This prevents a
        // second FREE_SOURCE_FAILED alert for the same Telegram transport incident.
        $wpdb->update($st,[
            'last_fetch_at'=>$now,
            'last_status'=>'failed_transport',
            'last_error'=>'',
            'updated_at'=>$now,
        ],['id'=>$id]);

        set_transient(self::cooldown_key($id),'1',self::CRON_FAILURE_COOLDOWN_SECONDS);

        // A single Telegram 5xx is usually transient. Count consecutive transport
        // failures and alert only after the outage persists across multiple refresh
        // windows; cached free configs remain available in the meantime.
        $failureKey=self::failure_count_key($id);
        $failures=max(0,(int)get_transient($failureKey))+1;
        set_transient($failureKey,$failures,self::TRANSPORT_FAILURE_WINDOW_SECONDS);

        $alertKey=self::alert_key($id);
        if($failures>=self::TRANSPORT_ALERT_THRESHOLD&&!get_transient($alertKey)&&class_exists('BlueVPN_Error_Monitor')){
            set_transient($alertKey,'1',self::ALERT_COOLDOWN_SECONDS);
            BlueVPN_Error_Monitor::report(
                'runtime',
                'free_sources',
                'warning',
                self::transport_code($id),
                $message!==''?$message:'دسترسی به منبع عمومی تلگرام موقتاً برقرار نشد.',
                [
                    'id'=>$id,
                    'title'=>(string)($src['title']??''),
                    'host'=>(string)wp_parse_url((string)($src['url']??''),PHP_URL_HOST),
                    'last_status'=>'failed_transport',
                    'consecutive_transport_failures'=>$failures,
                    'alert_threshold'=>self::TRANSPORT_ALERT_THRESHOLD,
                    'retry_after_seconds'=>self::CRON_FAILURE_COOLDOWN_SECONDS,
                    'cached_pool_preserved'=>true,
                ]
            );
        }
    }

    private static function mark_source_failure(int $id,string $message): void {
        global $wpdb;
        $now=BlueVPN_Utils::now_mysql();
        $wpdb->update(BlueVPN_DB::table('free_config_sources'),[
            'last_fetch_at'=>$now,
            'last_status'=>'failed',
            'last_error'=>mb_substr($message,0,1000),
            'updated_at'=>$now,
        ],['id'=>$id]);
    }

    private static function mark_source_success(int $id,string $now): void {
        global $wpdb;
        $wpdb->update(BlueVPN_DB::table('free_config_sources'),[
            'last_fetch_at'=>$now,
            'last_status'=>'ok',
            'last_error'=>'',
            'updated_at'=>$now,
        ],['id'=>$id]);
        delete_transient(self::cooldown_key($id));
        delete_transient(self::alert_key($id));
        delete_transient(self::failure_count_key($id));
        if(class_exists('BlueVPN_Error_Monitor')){
            BlueVPN_Error_Monitor::resolve_matching('runtime','free_sources',self::transport_code($id));
        }
    }

    public static function refresh_source(int $id): array {
        global $wpdb;$st=BlueVPN_DB::table('free_config_sources');$ct=BlueVPN_DB::table('free_configs');
        $src=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$st} WHERE id=%d",$id),ARRAY_A);
        if(!$src)return ['ok'=>false,'message'=>'منبع پیدا نشد.'];
        $url=(string)$src['url'];
        if(!wp_http_validate_url($url)||!str_starts_with($url,'https://t.me/'))return ['ok'=>false,'message'=>'URL منبع عمومی تلگرام معتبر نیست.'];

        $fetch=self::fetch_public_preview($url);
        if(empty($fetch['ok'])){
            $msg=(string)($fetch['message']??'درخواست منبع عمومی ناموفق بود.');
            if(!empty($fetch['transport']))self::mark_transport_failure($src,$msg);
            else self::mark_source_failure($id,$msg);
            return ['ok'=>false,'message'=>$msg,'transport'=>!empty($fetch['transport'])];
        }

        $res=$fetch['response'];
        $html=(string)wp_remote_retrieve_body($res);
        $decoded=html_entity_decode($html,ENT_QUOTES|ENT_HTML5,'UTF-8');
        $scheme='(?:'.implode('|',array_map('preg_quote',self::ALLOWED_SCHEMES)).')';
        preg_match_all("~\\b(".$scheme.")://[^\\s<>\"']+~iu",$decoded,$matches);
        $uris=array_values(array_unique(array_map(static fn($v)=>rtrim(trim((string)$v),".,;)]}"),$matches[0]??[])));
        $uris=array_slice($uris,0,max(10,min(1000,(int)$src['max_items'])));
        $now=BlueVPN_Utils::now_mysql();$added=0;$seen=0;
        foreach($uris as $uri){
            $protocol=strtolower((string)parse_url($uri,PHP_URL_SCHEME));if(!in_array($protocol,self::ALLOWED_SCHEMES,true))continue;
            $hash=hash('sha256',$uri);$exists=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$ct} WHERE id=%s",$hash));
            $country='';$ping=0;
            // Public Telegram preview commonly puts location/ping near each URI.
            $pos=strpos($decoded,$uri);if($pos!==false){$ctx=mb_substr($decoded,max(0,$pos-700),700);if(preg_match('/(?:لوکیشن|Location)\\s*[:：]\\s*([^\\r\\n<]{2,80})/iu',$ctx,$m))$country=trim(strip_tags($m[1]));if(preg_match('/(?:پینگ|Ping)\\s*[:：]\\s*(\\d{1,5})\\s*ms/iu',$ctx,$m))$ping=(int)$m[1];}
            if($exists){$wpdb->update($ct,['last_seen_at'=>$now,'active'=>1,'country_hint'=>mb_substr($country,0,80),'source_ping_ms'=>$ping],['id'=>$hash]);$seen++;}
            else{$wpdb->insert($ct,['id'=>$hash,'source_id'=>$id,'protocol'=>$protocol,'config_uri'=>$uri,'country_hint'=>mb_substr($country,0,80),'source_ping_ms'=>$ping,'score'=>0,'reports_count'=>0,'successes'=>0,'failures'=>0,'active'=>1,'first_seen_at'=>$now,'last_seen_at'=>$now]);$added++;}
        }
        self::mark_source_success($id,$now);
        return ['ok'=>true,'added'=>$added,'seen'=>$seen,'total'=>count($uris)];
    }

    public static function has_enabled_sources(): bool {
        self::seed();
        global $wpdb;
        $t=BlueVPN_DB::table('free_config_sources');
        return (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE enabled=1") > 0;
    }

    public static function ensure_seeded_pool(): void {
        self::seed();
        if (!self::has_enabled_sources()) return;
        // Reuse the bounded cron policy. It fetches only sources that are due.
        self::cron_refresh();
    }

    public static function curated(int $limit=80): array {
        self::seed(); global $wpdb;$ct=BlueVPN_DB::table('free_configs');
        $limit=max(10,min(300,$limit));$fresh=gmdate('Y-m-d H:i:s',time()-2*DAY_IN_SECONDS);
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id,protocol,config_uri,country_hint,source_ping_ms,score,reports_count,successes,failures,avg_latency_ms,avg_jitter_ms,avg_loss_x100,last_seen_at FROM {$ct} WHERE active=1 AND last_seen_at>=%s ORDER BY CASE WHEN reports_count>=2 THEN 0 ELSE 1 END ASC, score DESC, successes DESC, source_ping_ms ASC, last_seen_at DESC LIMIT %d",
            $fresh,$limit
        ),ARRAY_A)?:[];
    }

    public static function subscription_text(int $limit=80): string {
        return implode("\n",array_values(array_filter(array_map(static fn($r)=>trim((string)($r['config_uri']??'')),self::curated($limit)))))."\n";
    }

    public static function report(array $body,string $deviceId,string $appVersion=''): array {
        global $wpdb;$ct=BlueVPN_DB::table('free_configs');$rt=BlueVPN_DB::table('free_config_reports');
        $configId=preg_replace('/[^a-f0-9]/','',strtolower((string)($body['config_id']??'')));if(strlen($configId)!==64)return ['ok'=>false,'message'=>'config_id نامعتبر است.'];
        $exists=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$ct} WHERE id=%s",$configId));if(!$exists)return ['ok'=>false,'message'=>'کانفیگ شناخته‌شده نیست.'];
        $lat=max(0,min(60001,(int)($body['latency_ms']??0)));$jit=max(0,min(60001,(int)($body['jitter_ms']??0)));$loss=max(0,min(10000,(int)($body['loss_x100']??0)));$bucket=sanitize_key((string)($body['bucket']??''));$success=in_array($bucket,['fast','stable','reserve'],true)?1:0;
        $network=preg_replace('/[^A-Za-z0-9_-]/','',(string)($body['network_id']??''));$deviceHash=hash('sha256','bluevpn-free:'.$deviceId);
        $recent=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$rt} WHERE config_id=%s AND device_hash=%s AND created_at>=%s",$configId,$deviceHash,gmdate('Y-m-d H:i:s',time()-10*MINUTE_IN_SECONDS)));
        if($recent>0)return ['ok'=>true,'rate_limited'=>true];
        $wpdb->insert($rt,['config_id'=>$configId,'network_hash'=>mb_substr($network,0,64),'device_hash'=>$deviceHash,'bucket'=>mb_substr($bucket,0,20),'latency_ms'=>$lat,'jitter_ms'=>$jit,'loss_x100'=>$loss,'success'=>$success,'app_version'=>mb_substr($appVersion,0,32),'created_at'=>BlueVPN_Utils::now_mysql()]);
        $agg=$wpdb->get_row($wpdb->prepare("SELECT COUNT(*) reports,SUM(success) successes,AVG(latency_ms) lat,AVG(jitter_ms) jit,AVG(loss_x100) loss FROM {$rt} WHERE config_id=%s AND created_at>=%s",$configId,gmdate('Y-m-d H:i:s',time()-7*DAY_IN_SECONDS)),ARRAY_A)?:[];
        $reports=(int)($agg['reports']??0);$successes=(int)($agg['successes']??0);$failures=max(0,$reports-$successes);$latency=(float)($agg['lat']??0);$jitter=(float)($agg['jit']??0);$lossAvg=(float)($agg['loss']??0);
        $rate=$reports>0?$successes/$reports:0;$score=max(0,min(100,($rate*70)+max(0,20-$latency/25)+max(0,10-$jitter/20)-($lossAvg/500)));
        $wpdb->update($ct,['score'=>$score,'reports_count'=>$reports,'successes'=>$successes,'failures'=>$failures,'avg_latency_ms'=>$latency,'avg_jitter_ms'=>$jitter,'avg_loss_x100'=>$lossAvg,'last_report_at'=>BlueVPN_Utils::now_mysql()],['id'=>$configId]);
        return ['ok'=>true,'score'=>round($score,2),'reports'=>$reports];
    }

    public static function prune(): void { global $wpdb;$ct=BlueVPN_DB::table('free_configs');$rt=BlueVPN_DB::table('free_config_reports');$wpdb->query($wpdb->prepare("UPDATE {$ct} SET active=0 WHERE last_seen_at<%s",gmdate('Y-m-d H:i:s',time()-3*DAY_IN_SECONDS)));$wpdb->query($wpdb->prepare("DELETE FROM {$rt} WHERE created_at<%s",gmdate('Y-m-d H:i:s',time()-30*DAY_IN_SECONDS))); }

    public static function admin_refresh(): void { if(!current_user_can('manage_options'))wp_die('دسترسی ندارید.');$id=(int)($_POST['source_id']??0);check_admin_referer('bluevpn_free_source_refresh_'.$id);$r=self::refresh_source($id);$args=['page'=>'bluevpn-free-access'];$args[$r['ok']?'cc_msg':'cc_error']=$r['ok']?'منبع بروزرسانی شد؛ '.(int)($r['total']??0).' کانفیگ دیده شد.':'خطا: '.(string)($r['message']??'');wp_safe_redirect(add_query_arg($args,admin_url('admin.php')));exit; }
    public static function admin_toggle(): void { if(!current_user_can('manage_options'))wp_die('دسترسی ندارید.');global $wpdb;$id=(int)($_POST['source_id']??0);check_admin_referer('bluevpn_free_source_toggle_'.$id);$t=BlueVPN_DB::table('free_config_sources');$v=(int)$wpdb->get_var($wpdb->prepare("SELECT enabled FROM {$t} WHERE id=%d",$id));$wpdb->update($t,['enabled'=>$v?0:1,'updated_at'=>BlueVPN_Utils::now_mysql()],['id'=>$id]);wp_safe_redirect(admin_url('admin.php?page=bluevpn-free-access'));exit; }
    public static function admin_save(): void { if(!current_user_can('manage_options'))wp_die('دسترسی ندارید.');check_admin_referer('bluevpn_free_source_save');global $wpdb;$url=esc_url_raw(wp_unslash($_POST['url']??''));if(!str_starts_with($url,'https://t.me/'))wp_die('فعلاً فقط Preview عمومی Telegram پشتیبانی می‌شود.');$key='telegram-'.substr(hash('sha256',$url),0,20);$wpdb->replace(BlueVPN_DB::table('free_config_sources'),['source_key'=>$key,'source_type'=>'telegram_public','title'=>sanitize_text_field(wp_unslash($_POST['title']??'Telegram Source')),'url'=>$url,'enabled'=>1,'priority'=>(int)($_POST['priority']??100),'fetch_interval_seconds'=>max(60,(int)($_POST['fetch_interval_seconds']??300)),'max_items'=>max(10,min(1000,(int)($_POST['max_items']??400))),'created_at'=>BlueVPN_Utils::now_mysql(),'updated_at'=>BlueVPN_Utils::now_mysql()]);wp_safe_redirect(admin_url('admin.php?page=bluevpn-free-access'));exit; }
}
