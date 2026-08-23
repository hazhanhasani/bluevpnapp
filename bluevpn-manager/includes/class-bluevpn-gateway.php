<?php
if (!defined('ABSPATH')) exit;

/**
 * BlueVPN first-party gateway control plane.
 *
 * Phase 6 adds one-click enrollment and production credential hardening on top of
 * Gateway Autopilot, zero-downtime handoff, durable metering and safe rollout.
 */
final class BlueVPN_Gateway {
    private const AUTH_WINDOW_SECONDS = 300;
    private const HEALTHY_WINDOW_SECONDS = 150;
    private const DEGRADED_WINDOW_SECONDS = 300;
    private const MAX_USAGE_EVENTS = 500;
    private const MAX_EVENT_BYTES = 1099511627776; // 1 TiB sanity cap per event.
    private const MAX_NODE_SESSIONS = 10000;
    private const RECONCILE_HOOK = 'bluevpn_gateway_reconcile_tick';
    private const CIRCUIT_OPTION = 'bluevpn_gateway_phase3_circuit_state';
    private const CIRCUIT_ENABLED_OPTION = 'bluevpn_gateway_phase3_circuit_enabled';
    private const CIRCUIT_FAILURE_THRESHOLD = 3;
    private const CIRCUIT_RECOVERY_THRESHOLD = 2;
    private const CIRCUIT_OPEN_SECONDS = 180;
    private const ROLLOUT_OPTION = 'bluevpn_gateway_safe_rollout_state';
    private const ROLLOUT_ENABLED_OPTION = 'bluevpn_gateway_safe_rollout_enabled';
    private const ROLLOUT_GENERATION_OPTION = 'bluevpn_gateway_safe_rollout_generation';
    private const ROLLOUT_AGENT_MIN_VERSION = '5.1.8';
    private const ROLLOUT_STAGES = [10,25,50,100];
    private const ROLLOUT_ACK_TIMEOUT_SECONDS = 150;
    private const ROLLOUT_HEALTH_HOLD_SECONDS = 45;
    private const ROLLOUT_RETRY_COOLDOWN_SECONDS = 900;
    private const AUTOPILOT_OPTION = 'bluevpn_gateway_autopilot_state';
    private const AUTOPILOT_ENABLED_OPTION = 'bluevpn_gateway_autopilot_enabled';
    private const AUTOPILOT_FAILURE_THRESHOLD = 2;
    private const AUTOPILOT_RECOVERY_THRESHOLD = 3;
    private const AUTOPILOT_HIGH_CPU_PCT = 95.0;
    private const AUTOPILOT_HIGH_MEMORY_PCT = 95.0;
    private const MIGRATION_PREPARE_TIMEOUT_SECONDS = 240;
    private const MIGRATION_OVERLAP_SECONDS = 60;
    private const ENROLLMENT_TOKEN_TTL_SECONDS = 1800;
    private const ENROLLMENT_HEARTBEAT_GRACE_SECONDS = 900;
    private const SECRET_ROTATION_SECONDS = 2592000; // 30 days.
    private const PREVIOUS_SECRET_GRACE_SECONDS = 86400; // 24h handoff window.
    private const ENROLLMENT_AGENT_MIN_VERSION = '5.2.4';

    public static function init(): void {
        add_action('rest_api_init',[self::class,'register_routes']);
        add_action('admin_post_bluevpn_cc_save_gateway_node',[self::class,'save_node']);
        add_action('admin_post_bluevpn_cc_toggle_gateway_node',[self::class,'toggle_node']);
        add_action('admin_post_bluevpn_cc_delete_gateway_node',[self::class,'delete_node']);
        add_action('admin_post_bluevpn_cc_rotate_gateway_secret',[self::class,'rotate_secret']);
        add_action('admin_post_bluevpn_cc_regenerate_gateway_enrollment',[self::class,'regenerate_enrollment']);
        add_action('admin_post_bluevpn_cc_reconcile_gateways',[self::class,'reconcile_gateways']);
        add_action('init',[self::class,'ensure_schedule'],20);
        add_action(self::RECONCILE_HOOK,[self::class,'scheduled_reconcile']);
    }

    private static function nodes_table(): string { return BlueVPN_DB::table('gateway_nodes'); }
    private static function sessions_table(): string { return BlueVPN_DB::table('gateway_sessions'); }
    private static function usage_table(): string { return BlueVPN_DB::table('gateway_usage_events'); }
    private static function rollout_table(): string { return BlueVPN_DB::table('gateway_config_generations'); }
    private static function migrations_table(): string { return BlueVPN_DB::table('gateway_session_migrations'); }
    private static function customers_table(): string { return BlueVPN_DB::table('customers'); }
    private static function plans_table(): string { return BlueVPN_DB::table('plans'); }

    public static function register_routes(): void {
        register_rest_route('bluevpn-gateway/v1','/config',['methods'=>'GET','callback'=>[self::class,'rest_config'],'permission_callback'=>'__return_true']);
        register_rest_route('bluevpn-gateway/v1','/usage',['methods'=>'POST','callback'=>[self::class,'rest_usage'],'permission_callback'=>'__return_true']);
        register_rest_route('bluevpn-gateway/v1','/heartbeat',['methods'=>'POST','callback'=>[self::class,'rest_heartbeat'],'permission_callback'=>'__return_true']);
        register_rest_route('bluevpn-gateway/v1','/enroll',['methods'=>'POST','callback'=>[self::class,'rest_enroll'],'permission_callback'=>'__return_true']);
    }

    private static function ok(array $data,int $status=200): WP_REST_Response { return new WP_REST_Response($data,$status); }
    private static function guard_admin(): void { if(!current_user_can('manage_options'))wp_die('دسترسی ندارید.'); }
    private static function redirect(string $message,bool $error=false): void { $key=$error?'cc_error':'cc_msg';wp_safe_redirect(add_query_arg([$key=>$message],admin_url('admin.php?page=bluevpn-gateway')));exit; }

    private static function uuid_v4(): string {
        $b=random_bytes(16);$b[6]=chr((ord($b[6])&0x0f)|0x40);$b[8]=chr((ord($b[8])&0x3f)|0x80);$h=bin2hex($b);
        return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20,12);
    }

    private static function node_secret(array $node): string { return BlueVPN_Utils::decrypt_secret((string)($node['secret_enc']??'')); }


    private static function enrollment_token_key(int $nodeId,string $token): string {
        return hash('sha256',$nodeId.'|'.$token);
    }

    private static function enrollment_asset_url(string $name): string {
        return trailingslashit(BLUEVPN_MANAGER_URL).'assets/gateway/'.rawurlencode($name);
    }

    private static function enrollment_command(int $nodeId,string $token): string {
        $installer=self::enrollment_asset_url('one-click-install.sh');
        $endpoint=rest_url('bluevpn-gateway/v1/enroll');
        return "curl -fsSL '".$installer."' | sudo bash -s -- '".$endpoint."' '".$nodeId."' '".$token."'";
    }

    private static function issue_enrollment_token(int $nodeId): array {
        global $wpdb;$node=self::node($nodeId);if(!$node)throw new RuntimeException('Gateway پیدا نشد.');
        $token=BlueVPN_Utils::random_token(36);$expires=time()+self::ENROLLMENT_TOKEN_TTL_SECONDS;
        $ok=$wpdb->update(self::nodes_table(),[
            'enrollment_token_hash'=>self::enrollment_token_key($nodeId,$token),
            'enrollment_expires_at'=>gmdate('Y-m-d H:i:s',$expires),
            'updated_at'=>BlueVPN_Utils::now_mysql(),
        ],['id'=>$nodeId]);
        if($ok===false)throw new RuntimeException('ساخت توکن Enrollment ناموفق بود.');
        return ['node_id'=>$nodeId,'token'=>$token,'expires_at'=>$expires];
    }

    private static function stash_enrollment_display(array $enroll): void {
        $nodeId=max(0,(int)($enroll['node_id']??0));$token=(string)($enroll['token']??'');if($nodeId<=0||$token==='')return;
        set_transient('bluevpn_gateway_enrollment_'.get_current_user_id(),[
            'node_id'=>$nodeId,
            'token_enc'=>BlueVPN_Utils::encrypt_secret($token),
            'expires_at'=>max(time()+60,(int)($enroll['expires_at']??0)),
        ],self::ENROLLMENT_TOKEN_TTL_SECONDS);
    }

    private static function rotate_node_secret_internal(array $node): array {
        global $wpdb;$id=(int)($node['id']??0);if($id<=0)throw new RuntimeException('Gateway نامعتبر است.');
        $old=self::node_secret($node);if($old==='')throw new RuntimeException('Secret فعلی Gateway خالی است.');
        $secret=BlueVPN_Utils::random_token(36);$generation=max(1,(int)($node['secret_generation']??1))+1;$now=BlueVPN_Utils::now_mysql();
        $ok=$wpdb->update(self::nodes_table(),[
            'previous_secret_enc'=>BlueVPN_Utils::encrypt_secret($old),
            'previous_secret_hash'=>hash('sha256',$old),
            'previous_secret_expires_at'=>gmdate('Y-m-d H:i:s',time()+self::PREVIOUS_SECRET_GRACE_SECONDS),
            'secret_enc'=>BlueVPN_Utils::encrypt_secret($secret),
            'secret_hash'=>hash('sha256',$secret),
            'secret_generation'=>$generation,
            'last_secret_rotated_at'=>$now,
            'updated_at'=>$now,
        ],['id'=>$id]);
        if($ok===false)throw new RuntimeException('چرخش Secret ناموفق بود.');
        return ['secret'=>$secret,'generation'=>$generation];
    }

    private static function rotate_stale_secrets(int $limit=10): int {
        global $wpdb;$limit=max(1,min(50,$limit));$cutoff=gmdate('Y-m-d H:i:s',time()-self::SECRET_ROTATION_SECONDS);$seen=gmdate('Y-m-d H:i:s',time()-self::HEALTHY_WINDOW_SECONDS);
        $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::nodes_table()." WHERE active=1 AND last_seen_at IS NOT NULL AND last_seen_at>=%s AND (last_secret_rotated_at IS NULL OR last_secret_rotated_at<%s) ORDER BY id ASC LIMIT {$limit}",$seen,$cutoff),ARRAY_A)?:[];
        $rotated=0;foreach($rows as $row){try{self::rotate_node_secret_internal($row);$rotated++;}catch(Throwable $ignored){}}
        return $rotated;
    }

    private static function enrollment_health_watchdog(int $limit=25): void {
        if(!class_exists('BlueVPN_Error_Monitor'))return;global $wpdb;$limit=max(1,min(100,$limit));$cutoff=gmdate('Y-m-d H:i:s',time()-self::ENROLLMENT_HEARTBEAT_GRACE_SECONDS);
        $rows=$wpdb->get_results($wpdb->prepare('SELECT id,name,public_host,enrolled_at,last_seen_at FROM '.self::nodes_table()." WHERE active=1 AND enrolled_at IS NOT NULL AND enrolled_at<%s AND (last_seen_at IS NULL OR last_seen_at<enrolled_at) ORDER BY id ASC LIMIT {$limit}",$cutoff),ARRAY_A)?:[];
        foreach($rows as $row){$id=(int)$row['id'];BlueVPN_Error_Monitor::report('gateway','enrollment','warning','GATEWAY_ENROLLMENT_NO_HEARTBEAT_'.$id,'Gateway ثبت شده ولی Heartbeat دریافت نشده است.',['node_id'=>$id,'name'=>(string)$row['name'],'public_host'=>(string)$row['public_host']]);}
    }

    public static function rest_enroll(WP_REST_Request $r): WP_REST_Response {
        $body=$r->get_json_params();if(!is_array($body))$body=[];$nodeId=max(0,(int)($body['node_id']??0));$token=trim((string)($body['enrollment_token']??''));$agentVersion=substr(sanitize_text_field((string)($body['agent_version']??'')),0,64);
        if($nodeId<=0||!preg_match('/^[A-Za-z0-9_-]{20,120}$/',$token))return self::ok(['ok'=>false,'detail'=>['code'=>'GATEWAY_ENROLLMENT_INVALID','message'=>'Enrollment معتبر نیست.']],401);
        $ip=(string)($_SERVER['REMOTE_ADDR']??'');$rateKey='bluevpn_gw_enroll_'.substr(hash_hmac('sha256',$nodeId.'|'.$ip,wp_salt('auth')),0,24);$attempts=(int)get_transient($rateKey);if($attempts>=10)return self::ok(['ok'=>false,'detail'=>['code'=>'GATEWAY_ENROLLMENT_RATE_LIMIT','message'=>'تلاش بیش از حد.']],429);set_transient($rateKey,$attempts+1,10*MINUTE_IN_SECONDS);
        $node=self::node($nodeId);if(!$node||(int)($node['active']??0)!==1)return self::ok(['ok'=>false,'detail'=>['code'=>'GATEWAY_ENROLLMENT_NODE_DISABLED','message'=>'Gateway فعال نیست.']],403);
        $expires=!empty($node['enrollment_expires_at'])?(strtotime((string)$node['enrollment_expires_at'].' UTC')?:0):0;$stored=(string)($node['enrollment_token_hash']??'');$expected=self::enrollment_token_key($nodeId,$token);
        if($stored===''||$expires<=time()||!hash_equals($stored,$expected))return self::ok(['ok'=>false,'detail'=>['code'=>'GATEWAY_ENROLLMENT_EXPIRED','message'=>'لینک نصب منقضی یا قبلاً استفاده شده است. از پنل یک لینک جدید بساز.']],401);
        if($agentVersion!==''&&version_compare($agentVersion,self::ENROLLMENT_AGENT_MIN_VERSION,'<'))return self::ok(['ok'=>false,'detail'=>['code'=>'GATEWAY_ENROLLMENT_AGENT_OLD','message'=>'Installer/Agent قدیمی است.']],409);
        $secret=self::node_secret($node);if($secret==='')return self::ok(['ok'=>false,'detail'=>['code'=>'GATEWAY_SECRET_MISSING','message'=>'Secret Gateway موجود نیست.']],500);
        global $wpdb;$now=BlueVPN_Utils::now_mysql();$wpdb->update(self::nodes_table(),['enrollment_token_hash'=>'','enrollment_expires_at'=>null,'enrolled_at'=>$now,'last_secret_rotated_at'=>($node['last_secret_rotated_at']??null)?:$now,'updated_at'=>$now],['id'=>$nodeId]);delete_transient($rateKey);
        $host=(string)$node['public_host'];$config=[
            'manager_url'=>home_url('/'),'node_id'=>$nodeId,'node_secret'=>$secret,'credential_generation'=>max(1,(int)($node['secret_generation']??1)),
            'xray_path'=>'/usr/local/bin/xray','singbox_path'=>'/usr/local/bin/sing-box','xray_config_path'=>'/etc/bluevpn-gateway/xray.json','singbox_config_path'=>'/etc/bluevpn-gateway/sing-box.json','state_path'=>'/var/lib/bluevpn-gateway/state.json','xray_log_path'=>'/var/log/bluevpn-gateway-xray.log','singbox_log_path'=>'/var/log/bluevpn-gateway-singbox.log',
            'cert_file'=>'/etc/letsencrypt/live/'.$host.'/fullchain.pem','key_file'=>'/etc/letsencrypt/live/'.$host.'/privkey.pem','listen_host'=>'0.0.0.0','listen_port'=>(int)$node['public_port'],'api_server'=>'127.0.0.1:10085','bridge_socks_base_port'=>18080,'bridge_test_url'=>'https://www.gstatic.com/generate_204','bridge_test_interval'=>'2m','bridge_test_tolerance_ms'=>80,'poll_seconds'=>15,'usage_seconds'=>5,'heartbeat_seconds'=>30,'http_timeout'=>20,'log_level'=>'INFO','xray_log_level'=>'warning','singbox_log_level'=>'warn'
        ];
        return self::ok(['ok'=>true,'node_id'=>$nodeId,'public_host'=>$host,'server_name'=>(string)$node['server_name'],'config'=>$config,'assets'=>['agent'=>self::enrollment_asset_url('agent.py'),'service'=>self::enrollment_asset_url('bluevpn-gateway.service')],'server_time'=>time()]);
    }

    public static function active_nodes(): array {
        global $wpdb;
        return $wpdb->get_results('SELECT * FROM '.self::nodes_table().' WHERE active=1 ORDER BY priority ASC,id ASC',ARRAY_A)?:[];
    }

    public static function node(int $id): ?array {
        if($id<=0)return null;global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::nodes_table().' WHERE id=%d LIMIT 1',$id),ARRAY_A);
        return is_array($row)?$row:null;
    }

    public static function plan_traffic_mode(int $planId): string {
        if($planId<=0)return 'provider_reported';global $wpdb;
        $mode=(string)$wpdb->get_var($wpdb->prepare('SELECT traffic_mode FROM '.self::plans_table().' WHERE id=%d LIMIT 1',$planId));
        return $mode==='gateway_metered'?'gateway_metered':'provider_reported';
    }

    public static function plan_gateway_replicas(int $planId): int {
        if($planId<=0)return 2;global $wpdb;
        $count=(int)$wpdb->get_var($wpdb->prepare('SELECT gateway_replica_count FROM '.self::plans_table().' WHERE id=%d LIMIT 1',$planId));
        return max(1,min(3,$count?:2));
    }

    public static function is_gateway_metered_customer(array $customer): bool { return self::plan_traffic_mode((int)($customer['plan_id']??0))==='gateway_metered'; }

    public static function has_active_gateway(): bool {
        return count(self::eligible_nodes())>0;
    }

    private static function node_last_seen_age(array $node): int {
        $ts=!empty($node['last_seen_at'])?(strtotime((string)$node['last_seen_at'].' UTC')?:0):0;
        return $ts>0?max(0,time()-$ts):PHP_INT_MAX;
    }

    public static function autopilot_enabled(): bool {
        $enabled=(string)get_option(self::AUTOPILOT_ENABLED_OPTION,'1')!=='0';
        return (bool)apply_filters('bluevpn_gateway_autopilot_enabled',$enabled);
    }

    private static function autopilot_state(): array {
        $state=get_option(self::AUTOPILOT_OPTION,[]);
        return is_array($state)?$state:[];
    }

    private static function autopilot_node_state(int $nodeId): array {
        $all=self::autopilot_state();$row=$all[(string)$nodeId]??[];if(!is_array($row))$row=[];
        return ['auto_draining'=>!empty($row['auto_draining']),'failures'=>max(0,(int)($row['failures']??0)),'recoveries'=>max(0,(int)($row['recoveries']??0)),'reason'=>(string)($row['reason']??''),'updated_at'=>max(0,(int)($row['updated_at']??0))];
    }

    private static function node_effectively_draining(array $node): bool {
        if((int)($node['draining']??0)===1)return true;
        return self::autopilot_enabled()&&self::autopilot_node_state((int)($node['id']??0))['auto_draining'];
    }

    private static function autopilot_capacity(array $node): int {
        $manual=max(1,min(self::MAX_NODE_SESSIONS,(int)($node['max_sessions']??5000)));
        if(!self::autopilot_enabled())return $manual;
        $cores=max(1,(int)($node['cpu_cores']??0));$mem=max(256,(int)($node['memory_total_mb']??0));
        if((int)($node['cpu_cores']??0)<=0||(int)($node['memory_total_mb']??0)<=0)return min($manual,2000);
        // Conservative capacity: both CPU and RAM must be able to support the session count.
        $auto=max(250,min(self::MAX_NODE_SESSIONS,$cores*1000,(int)floor($mem/2)));
        return $auto;
    }

    private static function autopilot_observe_node(array $node,string $health,string $error): void {
        if(!self::autopilot_enabled())return;$id=(int)($node['id']??0);if($id<=0)return;
        $all=self::autopilot_state();$row=self::autopilot_node_state($id);$cpu=(float)($node['cpu_load_pct']??0);$mem=(float)($node['memory_used_pct']??0);
        $bad=$health!=='healthy'||$error!==''||$cpu>=self::AUTOPILOT_HIGH_CPU_PCT||$mem>=self::AUTOPILOT_HIGH_MEMORY_PCT;
        if($bad){$row['failures']++;$row['recoveries']=0;$row['reason']=$health!=='healthy'?'health_'.$health:($error!==''?'runtime_error':($cpu>=self::AUTOPILOT_HIGH_CPU_PCT?'cpu_pressure':'memory_pressure'));if($row['failures']>=self::AUTOPILOT_FAILURE_THRESHOLD)$row['auto_draining']=true;}
        else{$row['failures']=0;$row['recoveries']++;if($row['auto_draining']&&$row['recoveries']>=self::AUTOPILOT_RECOVERY_THRESHOLD){$row['auto_draining']=false;$row['reason']='recovered';$row['recoveries']=0;}}
        $row['updated_at']=time();$all[(string)$id]=$row;update_option(self::AUTOPILOT_OPTION,$all,false);
    }

    public static function autopilot_summary(): array {
        $auto=0;foreach(self::active_nodes() as $n)if(self::autopilot_node_state((int)$n['id'])['auto_draining'])$auto++;
        return ['enabled'=>self::autopilot_enabled(),'auto_draining_nodes'=>$auto,'mode'=>self::autopilot_enabled()?'autopilot':'manual'];
    }

    private static function node_has_capacity(array $node): bool {
        $max=self::autopilot_capacity($node);
        return (int)($node['active_sessions']??0)<$max;
    }

    /** Phase 3 circuit breaker can be disabled instantly for rollback. */
    public static function circuit_enabled(): bool {
        $enabled=(string)get_option(self::CIRCUIT_ENABLED_OPTION,'1')!=='0';
        return (bool)apply_filters('bluevpn_gateway_phase3_circuit_enabled',$enabled);
    }

    private static function circuit_state(): array {
        $state=get_option(self::CIRCUIT_OPTION,[]);
        return is_array($state)?$state:[];
    }

    private static function circuit_node_state(int $nodeId): array {
        $all=self::circuit_state();$row=$all[(string)$nodeId]??[];if(!is_array($row))$row=[];
        return [
            'state'=>in_array((string)($row['state']??'closed'),['closed','open','half_open'],true)?(string)($row['state']??'closed'):'closed',
            'failures'=>max(0,(int)($row['failures']??0)),
            'recoveries'=>max(0,(int)($row['recoveries']??0)),
            'opened_at'=>max(0,(int)($row['opened_at']??0)),
            'updated_at'=>max(0,(int)($row['updated_at']??0)),
        ];
    }

    private static function circuit_allows_node(array $node): bool {
        if(!self::circuit_enabled())return true;$id=(int)($node['id']??0);if($id<=0)return false;
        return self::circuit_node_state($id)['state']==='closed';
    }

    private static function record_circuit_observation(int $nodeId,bool $healthy): void {
        if($nodeId<=0||!self::circuit_enabled())return;
        $all=self::circuit_state();$row=self::circuit_node_state($nodeId);$now=time();
        if($healthy){
            if($row['state']==='open'){
                if($row['opened_at']>0&&($now-$row['opened_at'])>=self::CIRCUIT_OPEN_SECONDS){$row['state']='half_open';$row['recoveries']=1;$row['failures']=0;}
            }elseif($row['state']==='half_open'){
                $row['recoveries']++;
                if($row['recoveries']>=self::CIRCUIT_RECOVERY_THRESHOLD){$row=['state'=>'closed','failures'=>0,'recoveries'=>0,'opened_at'=>0,'updated_at'=>$now];}
            }else{$row['failures']=0;$row['recoveries']=0;$row['opened_at']=0;}
        }else{
            $row['recoveries']=0;$row['failures']++;
            if($row['state']==='half_open'||$row['failures']>=self::CIRCUIT_FAILURE_THRESHOLD){$row['state']='open';$row['opened_at']=$now;$row['failures']=max($row['failures'],self::CIRCUIT_FAILURE_THRESHOLD);}
        }
        $row['updated_at']=$now;$all[(string)$nodeId]=$row;update_option(self::CIRCUIT_OPTION,$all,false);
    }

    private static function clear_circuit_node(int $nodeId): void {
        $all=self::circuit_state();$key=(string)$nodeId;if(!array_key_exists($key,$all))return;unset($all[$key]);update_option(self::CIRCUIT_OPTION,$all,false);
    }

    private static function active_replica_count(int $customerId): int {
        if($customerId<=0)return 1;global $wpdb;$eligible=[];foreach(self::eligible_nodes() as $node)$eligible[(int)$node['id']]=true;if(!$eligible)return 1;
        $rows=$wpdb->get_col($wpdb->prepare("SELECT node_id FROM ".self::sessions_table()." WHERE customer_id=%d AND status='active'",$customerId))?:[];$count=0;foreach($rows as $nodeId)if(isset($eligible[(int)$nodeId]))$count++;
        return max(1,$count);
    }

    /** Healthy nodes first; recent degraded nodes are only a fallback pool. */
    public static function eligible_nodes(): array {
        $healthy=[];$degraded=[];
        foreach(self::active_nodes() as $node){
            if(self::node_effectively_draining($node)||!self::node_has_capacity($node)||!self::circuit_allows_node($node))continue;
            $age=self::node_last_seen_age($node);$health=strtolower((string)($node['health_status']??'unknown'));
            if($age<=self::HEALTHY_WINDOW_SECONDS&&$health==='healthy'){$healthy[]=$node;continue;}
            if($age<=self::DEGRADED_WINDOW_SECONDS&&in_array($health,['healthy','degraded','unknown'],true))$degraded[]=$node;
        }
        return array_merge($healthy,$degraded);
    }

    private static function node_score(array $node,int $customerId): float {
        $priority=self::autopilot_enabled()?100:max(1,min(10000,(int)($node['priority']??100)));
        $max=self::autopilot_capacity($node);
        $active=max(0,(int)($node['active_sessions']??0));
        $util=min(1.5,$active/$max);
        $health=strtolower((string)($node['health_status']??'unknown'));
        $healthPenalty=$health==='healthy'?0.0:25000.0;
        $stable=(int)sprintf('%u',crc32($customerId.':'.(int)$node['id']))%1000;
        return ($priority*100000.0)+($util*10000.0)+$healthPenalty+$stable;
    }

    private static function select_nodes_for_customer(int $customerId,int $replicas): array {
        $nodes=self::eligible_nodes();global $wpdb;$existingIds=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT node_id FROM ".self::sessions_table()." WHERE customer_id=%d AND status='active'",$customerId))?:[]);$sticky=array_fill_keys($existingIds,true);
        usort($nodes,static function(array $a,array $b)use($customerId,$sticky){$sa=isset($sticky[(int)$a['id']])?0:1;$sb=isset($sticky[(int)$b['id']])?0:1;if($sa!==$sb)return $sa<=>$sb;return self::node_score($a,$customerId)<=>self::node_score($b,$customerId);});
        $replicas=max(1,min(3,$replicas));if(count($nodes)<=$replicas)return $nodes;
        $selected=[];$regions=[];
        foreach($nodes as $node){$region=strtolower(trim((string)($node['region']??'')));if($region!==''&&isset($regions[$region]))continue;$selected[]=$node;if($region!=='')$regions[$region]=true;if(count($selected)>=$replicas)return $selected;}
        foreach($nodes as $node){$id=(int)$node['id'];$exists=false;foreach($selected as $s){if((int)$s['id']===$id){$exists=true;break;}}if($exists)continue;$selected[]=$node;if(count($selected)>=$replicas)break;}
        return $selected;
    }

    private static function active_migration_for_source(int $sourceSessionId): ?array {
        if($sourceSessionId<=0)return null;global $wpdb;$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::migrations_table()." WHERE source_session_id=%d AND state IN ('preparing','ready') ORDER BY id DESC LIMIT 1",$sourceSessionId),ARRAY_A);return is_array($row)?$row:null;
    }

    private static function start_session_migration(array $source,array $target): void {
        $sourceId=(int)($source['id']??0);$targetId=(int)($target['id']??0);if($sourceId<=0||$targetId<=0||$sourceId===$targetId)return;if(self::active_migration_for_source($sourceId))return;
        global $wpdb;$now=BlueVPN_Utils::now_mysql();$deadline=gmdate('Y-m-d H:i:s',time()+self::MIGRATION_PREPARE_TIMEOUT_SECONDS);
        $wpdb->insert(self::migrations_table(),['customer_id'=>(int)$source['customer_id'],'source_session_id'=>$sourceId,'target_session_id'=>$targetId,'source_node_id'=>(int)$source['node_id'],'target_node_id'=>(int)$target['node_id'],'state'=>'preparing','started_at'=>$now,'target_ready_at'=>null,'completed_at'=>null,'deadline_at'=>$deadline,'last_error'=>'','created_at'=>$now,'updated_at'=>$now]);
    }

    private static function migration_target_ready(array $migration): bool {
        $node=self::node((int)($migration['target_node_id']??0));if(!$node||self::node_effectively_draining($node)||!self::circuit_allows_node($node))return false;
        if(self::node_last_seen_age($node)>self::HEALTHY_WINDOW_SECONDS||(string)($node['health_status']??'')!=='healthy')return false;
        $started=!empty($migration['started_at'])?(strtotime((string)$migration['started_at'].' UTC')?:0):0;$acked=!empty($node['last_config_ack_at'])?(strtotime((string)$node['last_config_ack_at'].' UTC')?:0):0;
        return $started>0&&$acked>=$started;
    }

    public static function migration_tick(int $limit=500): array {
        global $wpdb;$mt=self::migrations_table();$st=self::sessions_table();$limit=max(1,min(2000,$limit));$rows=$wpdb->get_results("SELECT * FROM {$mt} WHERE state IN ('preparing','ready') ORDER BY id ASC LIMIT {$limit}",ARRAY_A)?:[];$ready=0;$completed=0;$failed=0;$now=time();$nowMysql=BlueVPN_Utils::now_mysql();
        foreach($rows as $m){$id=(int)$m['id'];$deadline=!empty($m['deadline_at'])?(strtotime((string)$m['deadline_at'].' UTC')?:0):0;$isReady=self::migration_target_ready($m);
            if((string)$m['state']==='preparing'&&$isReady){$wpdb->update($mt,['state'=>'ready','target_ready_at'=>$nowMysql,'updated_at'=>$nowMysql],['id'=>$id]);$ready++;continue;}
            if((string)$m['state']==='ready'){if(!$isReady){$wpdb->update($mt,['state'=>'preparing','target_ready_at'=>null,'last_error'=>'target_lost_health_before_cutover','updated_at'=>$nowMysql],['id'=>$id]);continue;}$readyAt=!empty($m['target_ready_at'])?(strtotime((string)$m['target_ready_at'].' UTC')?:0):0;if($readyAt>0&&($now-$readyAt)>=self::MIGRATION_OVERLAP_SECONDS){$wpdb->update($st,['status'=>'retired','revoked_at'=>$nowMysql,'updated_at'=>$nowMysql],['id'=>(int)$m['source_session_id']]);$wpdb->update($mt,['state'=>'completed','completed_at'=>$nowMysql,'last_error'=>'','updated_at'=>$nowMysql],['id'=>$id]);$completed++;continue;}}
            if((string)$m['state']==='preparing'&&$deadline>0&&$now>$deadline){$wpdb->update($mt,['state'=>'failed','last_error'=>'target_not_ready_before_deadline','updated_at'=>$nowMysql],['id'=>$id]);$wpdb->update($st,['status'=>'retired','revoked_at'=>$nowMysql,'updated_at'=>$nowMysql],['id'=>(int)$m['target_session_id']]);$failed++;}
        }
        return ['checked'=>count($rows),'ready'=>$ready,'completed'=>$completed,'failed'=>$failed];
    }

    public static function migration_summary(): array {
        global $wpdb;$t=self::migrations_table();$active=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE state IN ('preparing','ready')");$failed=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE state='failed' AND updated_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY)");return ['active'=>$active,'failed_24h'=>$failed,'overlap_seconds'=>self::MIGRATION_OVERLAP_SECONDS];
    }

    public static function ensure_customer_sessions(int $customerId): array {
        if($customerId<=0)return [];global $wpdb;$ct=self::customers_table();$st=self::sessions_table();
        $customer=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$ct} WHERE id=%d LIMIT 1",$customerId),ARRAY_A);
        if(!$customer||!self::is_gateway_metered_customer($customer))return [];
        $selected=self::select_nodes_for_customer($customerId,self::plan_gateway_replicas((int)$customer['plan_id']));
        if(!$selected)return [];
        $existing=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$st} WHERE customer_id=%d ORDER BY id ASC",$customerId),ARRAY_A)?:[];$byNode=[];foreach($existing as $row)$byNode[(int)$row['node_id']]=$row;
        $now=BlueVPN_Utils::now_mysql();$out=[];$desired=[];
        foreach($selected as $idx=>$node){$nodeId=(int)$node['id'];$desired[$nodeId]=true;$role=$idx===0?'primary':'standby';$row=$byNode[$nodeId]??null;
            if(!$row){$uuid=self::uuid_v4();$email='bv-'.$customerId.'-n'.$nodeId.'@gateway.bluevpn';$ok=$wpdb->insert($st,['node_id'=>$nodeId,'customer_id'=>$customerId,'client_uuid'=>$uuid,'client_email'=>$email,'status'=>'active','role'=>$role,'assigned_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);if($ok!==false)$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$st} WHERE id=%d",(int)$wpdb->insert_id),ARRAY_A);}
            elseif((string)$row['status']!=='active'||(string)($row['role']??'')!==$role){$wpdb->update($st,['status'=>'active','role'=>$role,'revoked_at'=>null,'assigned_at'=>$now,'updated_at'=>$now],['id'=>(int)$row['id']]);$row['status']='active';$row['role']=$role;$row['revoked_at']=null;}
            if(is_array($row))$out[]=$row;
        }
        $targetPrimary=null;foreach($out as $candidate){if((string)($candidate['role']??'')==='primary'){$targetPrimary=$candidate;break;}}if(!$targetPrimary&&$out)$targetPrimary=$out[0];
        foreach($existing as $row){$nodeId=(int)$row['node_id'];if(isset($desired[$nodeId])||(string)$row['status']!=='active')continue;if(is_array($targetPrimary)){self::start_session_migration($row,$targetPrimary);if(self::active_migration_for_source((int)$row['id']))$out[]=$row;}}
        return $out;
    }

    public static function gateway_subscription_lines(array $customer): array {
        if(!self::is_gateway_metered_customer($customer)||!self::entitlement_allows($customer))return [];
        $sessions=self::ensure_customer_sessions((int)$customer['id']);if(!$sessions)return [];
        $nodes=[];foreach(self::active_nodes() as $n){$age=self::node_last_seen_age($n);$health=strtolower((string)($n['health_status']??'unknown'));if(!self::circuit_allows_node($n)||$age>self::DEGRADED_WINDOW_SECONDS||!in_array($health,['healthy','degraded','unknown'],true))continue;$nodes[(int)$n['id']]=$n;}$lines=[];
        foreach($sessions as $session){if((string)$session['status']!=='active')continue;$node=$nodes[(int)$session['node_id']]??null;if(!$node)continue;$host=trim((string)$node['public_host']);$port=max(1,min(65535,(int)$node['public_port']));$sni=trim((string)$node['server_name']);if($host===''||$sni==='')continue;
            $query=http_build_query(['encryption'=>'none','security'=>'tls','sni'=>$sni,'type'=>'tcp'],'','&',PHP_QUERY_RFC3986);$role=(string)($session['role']??'primary');$label=self::node_effectively_draining($node)?'Handoff':($role==='primary'?'Primary':'Standby');$region=trim((string)($node['region']??''));$name='BlueVPN '.$label.' • '.((string)$node['name']?:$host).($region!==''?' • '.$region:'');$lines[]='vless://'.rawurlencode((string)$session['client_uuid']).'@'.$host.':'.$port.'?'.$query.'#'.rawurlencode($name);
        }
        return $lines;
    }

    public static function entitlement_allows(array $customer): bool {
        if(!(int)($customer['active']??0))return false;$status=strtolower(trim((string)($customer['subscription_status']??'')));if(in_array($status,['limited','expired','inactive','disabled','revoked'],true))return false;
        $exp=!empty($customer['subscription_expire'])?(strtotime((string)$customer['subscription_expire'].' UTC')?:0):0;if($exp>0&&$exp<=time())return false;
        $limit=max(0,(int)($customer['data_limit_bytes']??0));$used=max(0,(int)($customer['used_traffic_bytes']??0));if($limit>0&&$used>=$limit)return false;
        return true;
    }

    private static function auth_node(WP_REST_Request $r): array {
        $nodeId=(int)$r->get_header('x-bluevpn-gateway-id');$timestamp=trim((string)$r->get_header('x-bluevpn-gateway-timestamp'));$signature=strtolower(trim((string)$r->get_header('x-bluevpn-gateway-signature')));
        if($nodeId<=0||!preg_match('/^\d{10}$/',$timestamp)||abs(time()-(int)$timestamp)>self::AUTH_WINDOW_SECONDS||!preg_match('/^[a-f0-9]{64}$/',$signature))throw new RuntimeException('GATEWAY_AUTH_INVALID');
        $node=self::node($nodeId);if(!$node||(int)$node['active']!==1)throw new RuntimeException('GATEWAY_NODE_DISABLED');
        $body=(string)$r->get_body();$route=(string)$r->get_route();$message=$timestamp."\n".strtoupper((string)$r->get_method())."\n".$route."\n".hash('sha256',$body);
        $current=self::node_secret($node);$matched='';$expected=$current!==''?hash_hmac('sha256',$message,$current):'';if($expected!==''&&hash_equals($expected,$signature))$matched='current';
        if($matched===''){$expires=!empty($node['previous_secret_expires_at'])?(strtotime((string)$node['previous_secret_expires_at'].' UTC')?:0):0;$previous=$expires>time()?BlueVPN_Utils::decrypt_secret((string)($node['previous_secret_enc']??'')):'';if($previous!==''&&hash_equals(hash_hmac('sha256',$message,$previous),$signature))$matched='previous';}
        if($matched==='')throw new RuntimeException('GATEWAY_AUTH_INVALID');$node['_auth_secret_slot']=$matched;return $node;
    }

    private static function auth_fail(Throwable $e): WP_REST_Response {
        $code=$e->getMessage();$status=$code==='GATEWAY_NODE_DISABLED'?403:401;
        return self::ok(['ok'=>false,'detail'=>['code'=>$code,'message'=>'احراز هویت Gateway معتبر نیست.']],$status);
    }

    /** Phase 4 / 5.2.4 safe staged rollout can be disabled instantly. */
    public static function rollout_enabled(): bool {
        $enabled=(string)get_option(self::ROLLOUT_ENABLED_OPTION,'1')!=='0';
        return (bool)apply_filters('bluevpn_gateway_safe_rollout_enabled',$enabled);
    }

    private static function rollout_default_state(): array {
        return [
            'status'=>'idle','stable_generation'=>0,'active_generation'=>0,'previous_generation'=>0,
            'stage_index'=>0,'stage_percent'=>0,'included_node_ids'=>[],'exposed_node_ids'=>[],
            'stable_fingerprint'=>'','candidate_fingerprint'=>'','started_at'=>0,'stage_started_at'=>0,
            'rollback_started_at'=>0,'rollback_acks'=>[],'blocked_fingerprint'=>'','blocked_until'=>0,
            'last_reason'=>'','updated_at'=>0,
        ];
    }

    private static function rollout_state(): array {
        $saved=get_option(self::ROLLOUT_OPTION,[]);
        return array_replace(self::rollout_default_state(),is_array($saved)?$saved:[]);
    }

    private static function save_rollout_state(array $state): void {
        $state=array_replace(self::rollout_default_state(),$state);$state['updated_at']=time();
        update_option(self::ROLLOUT_OPTION,$state,false);
    }

    private static function next_rollout_generation(): int {
        $next=max(1,(int)get_option(self::ROLLOUT_GENERATION_OPTION,0)+1);
        update_option(self::ROLLOUT_GENERATION_OPTION,$next,false);return $next;
    }

    /** Structural config only; quota/sequence policy is rehydrated live on every poll. */
    private static function structural_snapshot_for_node(array $node): array {
        global $wpdb;$nodeId=(int)($node['id']??0);if($nodeId<=0)return ['config_hash'=>hash('sha256','empty'),'sessions'=>[]];
        $st=self::sessions_table();$ct=self::customers_table();$pt=self::plans_table();$limit=self::autopilot_capacity($node);
        $sql=$wpdb->prepare("SELECT s.*,c.plan_id,c.subscription_status,c.subscription_expire,c.data_limit_bytes,c.used_traffic_bytes,c.active,p.traffic_mode FROM {$st} s JOIN {$ct} c ON c.id=s.customer_id JOIN {$pt} p ON p.id=c.plan_id AND p.deleted=0 WHERE s.node_id=%d AND s.status='active' AND c.active=1 AND p.traffic_mode='gateway_metered' ORDER BY s.role ASC,s.customer_id ASC LIMIT {$limit}",$nodeId);
        $rows=$wpdb->get_results($sql,ARRAY_A)?:[];$sessions=[];$fingerprint=[];
        foreach($rows as $row){
            if(!self::entitlement_allows($row))continue;
            $pool=class_exists('BlueVPN_Providers')?BlueVPN_Providers::gateway_upstream_pool((int)$row['customer_id']):[];if(!$pool)continue;
            $item=['session_id'=>(int)$row['id'],'customer_id'=>(int)$row['customer_id'],'email'=>(string)$row['client_email'],'uuid'=>(string)$row['client_uuid'],'role'=>(string)($row['role']??'primary'),'upstreams'=>array_values($pool)];
            $sessions[]=$item;$fingerprint[]=['id'=>$item['session_id'],'uuid'=>$item['uuid'],'role'=>$item['role'],'pool'=>hash('sha256',implode("\n",$item['upstreams']))];
        }
        return ['config_hash'=>hash('sha256',wp_json_encode($fingerprint,JSON_UNESCAPED_SLASHES)),'sessions'=>$sessions];
    }

    /** Reapply live session membership/authorization/quota while pinning known upstream pools to the selected generation. */
    private static function hydrate_structural_snapshot(int $nodeId,array $snapshot): array {
        global $wpdb;$sessions=[];$policyFingerprint=[];$st=self::sessions_table();$ct=self::customers_table();$pt=self::plans_table();
        $pinned=[];foreach((array)($snapshot['sessions']??[]) as $base){if(is_array($base)&&!empty($base['customer_id']))$pinned[(int)$base['customer_id']]=(array)($base['upstreams']??[]);}
        $rows=$wpdb->get_results($wpdb->prepare("SELECT s.*,c.plan_id,c.subscription_status,c.subscription_expire,c.data_limit_bytes,c.used_traffic_bytes,c.active,p.traffic_mode FROM {$st} s JOIN {$ct} c ON c.id=s.customer_id JOIN {$pt} p ON p.id=c.plan_id AND p.deleted=0 WHERE s.node_id=%d AND s.status='active' AND c.active=1 AND p.traffic_mode='gateway_metered' ORDER BY s.role ASC,s.customer_id ASC LIMIT %d",$nodeId,self::MAX_NODE_SESSIONS),ARRAY_A)?:[];
        foreach($rows as $row){
            if(!self::entitlement_allows($row))continue;$customerId=(int)$row['customer_id'];$pool=$pinned[$customerId]??[];if(!$pool)$pool=class_exists('BlueVPN_Providers')?BlueVPN_Providers::gateway_upstream_pool($customerId):[];if(!$pool)continue;
            $quota=max(0,(int)$row['data_limit_bytes']);$used=max(0,(int)$row['used_traffic_bytes']);$remaining=$quota>0?max(0,$quota-$used):0;$replicas=self::active_replica_count($customerId);$lease=$quota>0?(int)ceil($remaining/$replicas):0;$lastSeq=max(0,(int)($row['last_seq']??0));
            $item=['session_id'=>(int)$row['id'],'customer_id'=>$customerId,'email'=>(string)$row['client_email'],'uuid'=>(string)$row['client_uuid'],'role'=>(string)($row['role']??'primary'),'upstreams'=>array_values($pool),'expires_at'=>(string)($row['subscription_expire']??''),'quota_bytes'=>$quota,'used_bytes'=>$used,'remaining_bytes'=>$remaining,'lease_bytes'=>$lease,'last_seq'=>$lastSeq];$sessions[]=$item;
            $policyFingerprint[]=['id'=>(int)$row['id'],'lease'=>$lease,'last_seq'=>$lastSeq,'remaining'=>$remaining];
        }
        return ['sessions'=>$sessions,'policy_hash'=>hash('sha256',wp_json_encode($policyFingerprint,JSON_UNESCAPED_SLASHES))];
    }

    private static function rollout_nodes(): array {
        $out=[];foreach(self::active_nodes() as $node){if(self::node_effectively_draining($node)||!self::circuit_allows_node($node))continue;$out[]=$node;}return $out;
    }

    private static function build_rollout_snapshots(array $nodes): array {
        $out=[];foreach($nodes as $node){$id=(int)$node['id'];$out[$id]=self::structural_snapshot_for_node($node);}return $out;
    }

    private static function rollout_fingerprint(array $snapshots): string {
        ksort($snapshots,SORT_NUMERIC);$parts=[];foreach($snapshots as $nodeId=>$snapshot)$parts[]=(int)$nodeId.':'.(string)($snapshot['config_hash']??'');return hash('sha256',implode('|',$parts));
    }

    private static function store_rollout_generation(int $generation,array $snapshots,string $state='pending'): void {
        global $wpdb;$t=self::rollout_table();$now=BlueVPN_Utils::now_mysql();
        foreach($snapshots as $nodeId=>$snapshot){$wpdb->replace($t,['generation'=>$generation,'node_id'=>(int)$nodeId,'config_hash'=>(string)($snapshot['config_hash']??''),'snapshot_json'=>wp_json_encode($snapshot,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),'rollout_state'=>$state,'is_canary'=>0,'served_at'=>null,'acked_at'=>null,'failed_at'=>null,'last_error'=>'','created_at'=>$now,'updated_at'=>$now]);}
        // Retain only the newest six generations so rollback history stays bounded.
        $wpdb->query($wpdb->prepare("DELETE FROM {$t} WHERE generation < %d",max(0,$generation-5)));
    }

    private static function rollout_row(int $generation,int $nodeId): ?array {
        if($generation<=0||$nodeId<=0)return null;global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::rollout_table().' WHERE generation=%d AND node_id=%d LIMIT 1',$generation,$nodeId),ARRAY_A);return is_array($row)?$row:null;
    }

    private static function rollout_snapshot(int $generation,int $nodeId): ?array {
        $row=self::rollout_row($generation,$nodeId);if(!$row)return null;$decoded=json_decode((string)($row['snapshot_json']??''),true);return is_array($decoded)?$decoded:null;
    }

    private static function ordered_rollout_node_ids(array $nodes): array {
        usort($nodes,static function(array $a,array $b): int {
            $ha=(string)($a['health_status']??'unknown')==='healthy'?0:1;$hb=(string)($b['health_status']??'unknown')==='healthy'?0:1;if($ha!==$hb)return $ha<=>$hb;
            $aa=max(0,(int)($a['active_sessions']??0));$ab=max(0,(int)($b['active_sessions']??0));if($aa!==$ab)return $aa<=>$ab;
            $pa=max(1,(int)($a['priority']??100));$pb=max(1,(int)($b['priority']??100));if($pa!==$pb)return $pa<=>$pb;
            return ((int)$a['id'])<=>((int)$b['id']);
        });
        return array_values(array_map(static fn($n)=>(int)$n['id'],$nodes));
    }

    private static function rollout_included_ids(array $ordered,int $percent): array {
        $count=count($ordered);if($count===0)return [];$want=max(1,(int)ceil(($count*max(1,min(100,$percent)))/100));return array_slice($ordered,0,min($count,$want));
    }

    private static function rollout_agents_ready(array $nodes): array {
        $bad=[];foreach($nodes as $node){$v=trim((string)($node['last_agent_version']??''));if($v===''||version_compare($v,self::ROLLOUT_AGENT_MIN_VERSION,'<'))$bad[]=(int)$node['id'];}return $bad;
    }

    private static function mark_rollout_included(int $generation,array $included,array $canaries): void {
        global $wpdb;$t=self::rollout_table();$now=BlueVPN_Utils::now_mysql();$canaryMap=array_fill_keys(array_map('intval',$canaries),true);
        foreach(array_map('intval',$included) as $id)$wpdb->update($t,['is_canary'=>isset($canaryMap[$id])?1:0,'updated_at'=>$now],['generation'=>$generation,'node_id'=>$id]);
    }

    private static function begin_rollout_rollback(array $state,string $reason): void {
        $state['status']='rollback';$state['last_reason']=$reason;$state['rollback_started_at']=time();$state['rollback_acks']=[];self::save_rollout_state($state);
        if(class_exists('BlueVPN_Error_Monitor'))BlueVPN_Error_Monitor::report('gateway','safe_rollout','warning','GATEWAY_ROLLOUT_AUTO_ROLLBACK','Gateway rollout به نسخه پایدار قبلی برگشت.',['generation'=>(int)$state['active_generation'],'stable_generation'=>(int)$state['stable_generation'],'reason'=>$reason,'stage_percent'=>(int)$state['stage_percent']]);
    }

    private static function maybe_advance_rollout(array $state,array $nodes,array $snapshots,string $fingerprint): array {
        global $wpdb;$t=self::rollout_table();$active=(int)$state['active_generation'];$included=array_values(array_unique(array_map('intval',(array)$state['included_node_ids'])));$now=time();
        if($fingerprint!==(string)$state['candidate_fingerprint']){self::begin_rollout_rollback($state,'candidate_changed_during_rollout');return self::rollout_state();}
        $allAcked=(bool)$included;$latestAck=0;
        foreach($included as $nodeId){$row=self::rollout_row($active,$nodeId);if(!$row){self::begin_rollout_rollback($state,'candidate_snapshot_missing');return self::rollout_state();}
            if(!empty($row['failed_at'])){self::begin_rollout_rollback($state,'canary_or_stage_node_failed');return self::rollout_state();}
            $served=!empty($row['served_at'])?(strtotime((string)$row['served_at'].' UTC')?:0):0;$acked=!empty($row['acked_at'])?(strtotime((string)$row['acked_at'].' UTC')?:0):0;
            if($served>0&&$acked===0&&($now-$served)>self::ROLLOUT_ACK_TIMEOUT_SECONDS){self::begin_rollout_rollback($state,'config_ack_timeout');return self::rollout_state();}
            if($acked===0){$allAcked=false;}else{$latestAck=max($latestAck,$acked);}
        }
        if(!$allAcked||$latestAck<=0||($now-$latestAck)<self::ROLLOUT_HEALTH_HOLD_SECONDS)return $state;
        $idx=(int)$state['stage_index'];
        if($idx>=count(self::ROLLOUT_STAGES)-1){
            $wpdb->update($t,['rollout_state'=>'stable','updated_at'=>BlueVPN_Utils::now_mysql()],['generation'=>$active]);$prev=(int)$state['stable_generation'];if($prev>0)$wpdb->update($t,['rollout_state'=>'superseded','updated_at'=>BlueVPN_Utils::now_mysql()],['generation'=>$prev]);
            $state['status']='idle';$state['stable_generation']=$active;$state['previous_generation']=$prev;$state['active_generation']=0;$state['stage_index']=0;$state['stage_percent']=0;$state['included_node_ids']=[];$state['exposed_node_ids']=[];$state['stable_fingerprint']=$state['candidate_fingerprint'];$state['candidate_fingerprint']='';$state['last_reason']='rollout_completed';self::save_rollout_state($state);return $state;
        }
        $ordered=self::ordered_rollout_node_ids($nodes);$nextIdx=$idx+1;$nextPercent=(int)self::ROLLOUT_STAGES[$nextIdx];$nextIncluded=self::rollout_included_ids($ordered,$nextPercent);$exposed=array_values(array_unique(array_merge((array)$state['exposed_node_ids'],$nextIncluded)));
        $state['stage_index']=$nextIdx;$state['stage_percent']=$nextPercent;$state['included_node_ids']=$nextIncluded;$state['exposed_node_ids']=$exposed;$state['stage_started_at']=$now;self::mark_rollout_included($active,$nextIncluded,self::rollout_included_ids($ordered,(int)self::ROLLOUT_STAGES[0]));self::save_rollout_state($state);return $state;
    }

    public static function rollout_tick(): array {
        if(!self::rollout_enabled())return ['status'=>'disabled'];$nodes=self::rollout_nodes();$snapshots=self::build_rollout_snapshots($nodes);$fingerprint=self::rollout_fingerprint($snapshots);$state=self::rollout_state();$now=time();
        if((int)$state['stable_generation']<=0){$gen=self::next_rollout_generation();self::store_rollout_generation($gen,$snapshots,'stable');$state['stable_generation']=$gen;$state['stable_fingerprint']=$fingerprint;$state['last_reason']='baseline_seeded';self::save_rollout_state($state);return $state;}
        if((string)$state['status']==='rollback'){
            $exposed=array_values(array_unique(array_map('intval',(array)$state['exposed_node_ids'])));$acks=(array)$state['rollback_acks'];$complete=true;foreach($exposed as $id){if((int)($acks[(string)$id]??0)<(int)$state['rollback_started_at']){$complete=false;break;}}
            if($complete||($now-(int)$state['rollback_started_at'])>self::ROLLOUT_ACK_TIMEOUT_SECONDS){$state['blocked_fingerprint']=(string)$state['candidate_fingerprint'];$state['blocked_until']=$now+self::ROLLOUT_RETRY_COOLDOWN_SECONDS;$state['status']='idle';$state['active_generation']=0;$state['included_node_ids']=[];$state['exposed_node_ids']=[];$state['candidate_fingerprint']='';$state['last_reason']=$complete?'rollback_acked':'rollback_timeout_completed';self::save_rollout_state($state);}return $state;
        }
        if((string)$state['status']==='rolling')return self::maybe_advance_rollout($state,$nodes,$snapshots,$fingerprint);
        if($fingerprint===(string)$state['stable_fingerprint'])return $state;
        if((string)$state['blocked_fingerprint']===$fingerprint&&(int)$state['blocked_until']>$now){$state['last_reason']='candidate_in_retry_cooldown';self::save_rollout_state($state);return $state;}
        $notReady=self::rollout_agents_ready($nodes);if($notReady){$reason='agent_upgrade_required:'.implode(',',$notReady);$changed=(string)$state['last_reason']!==$reason;$state['last_reason']=$reason;self::save_rollout_state($state);if($changed&&class_exists('BlueVPN_Error_Monitor'))BlueVPN_Error_Monitor::report('gateway','safe_rollout','warning','GATEWAY_ROLLOUT_AGENT_UPGRADE_REQUIRED','Rollout امن تا ارتقای همه Gateway Agentها متوقف است.',['minimum_version'=>self::ROLLOUT_AGENT_MIN_VERSION,'node_ids'=>$notReady]);return $state;}
        $gen=self::next_rollout_generation();self::store_rollout_generation($gen,$snapshots,'candidate');$ordered=self::ordered_rollout_node_ids($nodes);$canaries=self::rollout_included_ids($ordered,(int)self::ROLLOUT_STAGES[0]);self::mark_rollout_included($gen,$canaries,$canaries);
        $state['status']='rolling';$state['active_generation']=$gen;$state['previous_generation']=(int)$state['stable_generation'];$state['stage_index']=0;$state['stage_percent']=(int)self::ROLLOUT_STAGES[0];$state['included_node_ids']=$canaries;$state['exposed_node_ids']=$canaries;$state['candidate_fingerprint']=$fingerprint;$state['started_at']=$now;$state['stage_started_at']=$now;$state['last_reason']='canary_started';self::save_rollout_state($state);return $state;
    }

    private static function rollout_selection_for_node(int $nodeId,array $current): array {
        $state=self::rollout_state();$generation=(int)$state['stable_generation'];$snapshot=$generation>0?self::rollout_snapshot($generation,$nodeId):null;$source='stable';$canary=false;
        if((string)$state['status']==='rolling'&&in_array($nodeId,array_map('intval',(array)$state['included_node_ids']),true)){$generation=(int)$state['active_generation'];$snapshot=self::rollout_snapshot($generation,$nodeId);$source='candidate';$row=self::rollout_row($generation,$nodeId);$canary=!empty($row['is_canary']);}
        elseif((string)$state['status']==='rollback'){$generation=(int)$state['stable_generation'];$snapshot=self::rollout_snapshot($generation,$nodeId);$source='rollback';}
        if(!is_array($snapshot)){$snapshot=$current;$generation=max(0,$generation);$source='live_fallback';}
        if($source==='candidate'&&$generation>0){global $wpdb;$wpdb->query($wpdb->prepare("UPDATE ".self::rollout_table()." SET served_at=COALESCE(served_at,%s),updated_at=%s WHERE generation=%d AND node_id=%d",BlueVPN_Utils::now_mysql(),BlueVPN_Utils::now_mysql(),$generation,$nodeId));}
        return ['generation'=>$generation,'snapshot'=>$snapshot,'source'=>$source,'canary'=>$canary,'state'=>$state];
    }

    private static function rollout_record_heartbeat(int $nodeId,int $generation,string $configHash,string $health,string $error): void {
        if($nodeId<=0||!self::rollout_enabled())return;$state=self::rollout_state();$now=BlueVPN_Utils::now_mysql();
        if((string)$state['status']==='rolling'&&in_array($nodeId,array_map('intval',(array)$state['included_node_ids']),true)){
            $active=(int)$state['active_generation'];$row=self::rollout_row($active,$nodeId);if(!$row)return;global $wpdb;
            if($error!==''||$health!=='healthy'){$wpdb->update(self::rollout_table(),['failed_at'=>$now,'last_error'=>mb_substr($error!==''?$error:'gateway health='.$health,0,1800),'updated_at'=>$now],['generation'=>$active,'node_id'=>$nodeId]);return;}
            if($generation===$active&&$configHash!==''&&hash_equals((string)$row['config_hash'],$configHash)){$wpdb->update(self::rollout_table(),['acked_at'=>$now,'failed_at'=>null,'last_error'=>'','updated_at'=>$now],['generation'=>$active,'node_id'=>$nodeId]);}
            elseif($generation===$active&&$configHash!==''){$wpdb->update(self::rollout_table(),['failed_at'=>$now,'last_error'=>'CONFIG_HASH_MISMATCH expected='.(string)$row['config_hash'].' applied='.$configHash,'updated_at'=>$now],['generation'=>$active,'node_id'=>$nodeId]);}
        }elseif((string)$state['status']==='rollback'&&in_array($nodeId,array_map('intval',(array)$state['exposed_node_ids']),true)){
            $stable=(int)$state['stable_generation'];$row=self::rollout_row($stable,$nodeId);if($row&&$generation===$stable&&$configHash!==''&&hash_equals((string)$row['config_hash'],$configHash)&&$health==='healthy'&&$error===''){$acks=(array)$state['rollback_acks'];$acks[(string)$nodeId]=time();$state['rollback_acks']=$acks;self::save_rollout_state($state);}
        }
    }

    public static function rollout_summary(): array {
        $s=self::rollout_state();return ['enabled'=>self::rollout_enabled(),'status'=>(string)$s['status'],'stable_generation'=>(int)$s['stable_generation'],'active_generation'=>(int)$s['active_generation'],'stage_percent'=>(int)$s['stage_percent'],'included_nodes'=>count((array)$s['included_node_ids']),'reason'=>(string)$s['last_reason']];
    }

    public static function rest_config(WP_REST_Request $r): WP_REST_Response {
        try{$node=self::auth_node($r);}catch(Throwable $e){return self::auth_fail($e);}global $wpdb;$nodeId=(int)$node['id'];
        $rollout=self::rollout_summary();
        // Circuit isolation is immediate. Drain is graceful: stop new placement but keep existing sessions alive until handoff ACK + overlap completes.
        if(!self::circuit_allows_node($node)){
            $state=self::circuit_node_state($nodeId);$hash=hash('sha256','disabled:'.$nodeId.':'.$state['state']);
            return self::ok(['ok'=>true,'schema'=>5,'mode'=>'gateway_metered','draining'=>self::node_effectively_draining($node),'accept_new'=>false,'circuit_state'=>$state['state'],'config_generation'=>0,'config_hash'=>$hash,'policy_hash'=>$hash,'rollout_status'=>'emergency_bypass','rollout_stage_percent'=>0,'rollout_canary'=>false,'generated_at'=>BlueVPN_Utils::iso_now(),'sessions'=>[]]);
        }
        $current=self::structural_snapshot_for_node($node);$selection=self::rollout_enabled()?self::rollout_selection_for_node($nodeId,$current):['generation'=>0,'snapshot'=>$current,'source'=>'disabled','canary'=>false,'state'=>self::rollout_state()];
        $snapshot=is_array($selection['snapshot']??null)?$selection['snapshot']:$current;$live=self::hydrate_structural_snapshot($nodeId,$snapshot);$hash=(string)($snapshot['config_hash']??$current['config_hash']);$policyHash=(string)$live['policy_hash'];$now=BlueVPN_Utils::now_mysql();
        // last_config_hash is ACKed runtime state and is only written by heartbeat, never by GET /config.
        $wpdb->update(self::nodes_table(),['last_seen_at'=>$now,'updated_at'=>$now],['id'=>$nodeId]);
        return self::ok(['ok'=>true,'schema'=>5,'mode'=>'gateway_metered','node'=>['id'=>$nodeId,'name'=>(string)$node['name'],'region'=>(string)($node['region']??''),'public_host'=>(string)$node['public_host'],'public_port'=>(int)$node['public_port'],'server_name'=>(string)$node['server_name'],'transport'=>(string)$node['transport'],'draining'=>self::node_effectively_draining($node),'accept_new'=>!self::node_effectively_draining($node),'effective_capacity'=>self::autopilot_capacity($node)],'config_generation'=>(int)($selection['generation']??0),'config_hash'=>$hash,'policy_hash'=>$policyHash,'rollout_status'=>(string)($selection['source']??'stable'),'rollout_stage_percent'=>(int)($selection['state']['stage_percent']??0),'rollout_canary'=>!empty($selection['canary']),'generated_at'=>BlueVPN_Utils::iso_now(),'sessions'=>$live['sessions']]);
    }

    public static function rest_usage(WP_REST_Request $r): WP_REST_Response {
        try{$node=self::auth_node($r);}catch(Throwable $e){return self::auth_fail($e);}global $wpdb;$payload=$r->get_json_params();if(!is_array($payload))$payload=[];$events=$payload['events']??[];if(!is_array($events))$events=[];$events=array_slice($events,0,self::MAX_USAGE_EVENTS);$nodeId=(int)$node['id'];$st=self::sessions_table();$ut=self::usage_table();$ct=self::customers_table();$accepted=0;$duplicates=0;$rejected=0;$limited=[];$limitedSessions=[];$reload=false;
        $wpdb->query('START TRANSACTION');
        try{
            foreach($events as $event){
                if(!is_array($event)){$rejected++;continue;}
                $eventId=substr(sanitize_text_field((string)($event['event_id']??'')),0,120);$sessionId=max(0,(int)($event['session_id']??0));$seq=max(0,(int)($event['seq']??0));$epoch=substr(preg_replace('/[^a-zA-Z0-9._:-]/','',(string)($event['agent_epoch']??'')),0,64);$up=min(self::MAX_EVENT_BYTES,max(0,(int)($event['uplink_bytes']??0)));$down=min(self::MAX_EVENT_BYTES,max(0,(int)($event['downlink_bytes']??0)));
                if($eventId===''||$sessionId<=0||$seq<=0||$epoch===''||($up+$down)<=0){$rejected++;continue;}
                $exists=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$ut} WHERE event_id=%s LIMIT 1",$eventId));if($exists>0){$duplicates++;continue;}
                $session=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$st} WHERE id=%d AND node_id=%d LIMIT 1 FOR UPDATE",$sessionId,$nodeId),ARRAY_A);if(!$session){$rejected++;continue;}
                if((string)($session['agent_epoch']??'')===$epoch&&$seq<=(int)($session['last_seq']??0)){$duplicates++;continue;}
                $customerId=(int)$session['customer_id'];$customer=$wpdb->get_row($wpdb->prepare("SELECT id,data_limit_bytes,used_traffic_bytes,subscription_status FROM {$ct} WHERE id=%d LIMIT 1 FOR UPDATE",$customerId),ARRAY_A);if(!$customer){$rejected++;continue;}
                $reportedRaw=(string)($event['reported_at']??'');$reported=$reportedRaw!==''?BlueVPN_Utils::mysql_from_iso($reportedRaw):null;if(!$reported)$reported=BlueVPN_Utils::now_mysql();
                $ok=$wpdb->insert($ut,['event_id'=>$eventId,'node_id'=>$nodeId,'session_id'=>$sessionId,'customer_id'=>$customerId,'seq'=>$seq,'agent_epoch'=>$epoch,'uplink_bytes'=>$up,'downlink_bytes'=>$down,'reported_at'=>$reported,'created_at'=>BlueVPN_Utils::now_mysql()]);
                if($ok===false){$dup=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$ut} WHERE event_id=%s LIMIT 1",$eventId));if($dup>0){$duplicates++;continue;}throw new RuntimeException('usage insert failed');}
                $now=BlueVPN_Utils::now_mysql();$delta=$up+$down;$wpdb->query($wpdb->prepare("UPDATE {$st} SET agent_epoch=%s,last_seq=%d,used_uplink_bytes=used_uplink_bytes+%d,used_downlink_bytes=used_downlink_bytes+%d,last_usage_at=%s,updated_at=%s WHERE id=%d",$epoch,$seq,$up,$down,$now,$now,$sessionId));
                $currentUsed=max(0,(int)$customer['used_traffic_bytes']);$newUsed=$currentUsed+$delta;$wpdb->query($wpdb->prepare("UPDATE {$ct} SET used_traffic_bytes=%d,last_sync_at=%s WHERE id=%d",$newUsed,$now,$customerId));
                $limit=max(0,(int)$customer['data_limit_bytes']);
                if($limit>0&&$newUsed>=$limit){$wpdb->update($ct,['subscription_status'=>'limited','last_sync_error'=>'Gateway quota exhausted'],['id'=>$customerId]);$wpdb->update($st,['status'=>'limited','revoked_at'=>$now,'updated_at'=>$now],['customer_id'=>$customerId]);$limited[]=$customerId;$limitedSessions[]=$sessionId;$reload=true;}
                $accepted++;
            }
            $wpdb->query('COMMIT');
        }catch(Throwable $e){$wpdb->query('ROLLBACK');return self::ok(['ok'=>false,'detail'=>['code'=>'GATEWAY_USAGE_STORE_FAILED','message'=>$e->getMessage()]],500);}
        $now=BlueVPN_Utils::now_mysql();$wpdb->update(self::nodes_table(),['last_seen_at'=>$now,'last_usage_flush_at'=>$now,'updated_at'=>$now],['id'=>$nodeId]);$revoked=[];
        if($limited){$ids=array_values(array_unique(array_map('intval',$limited)));$marks=implode(',',array_fill(0,count($ids),'%d'));$args=array_merge([$nodeId],$ids);$sql=$wpdb->prepare("SELECT id FROM {$st} WHERE node_id=%d AND customer_id IN ({$marks}) AND status IN ('limited','revoked')",...$args);$revoked=array_map('intval',$wpdb->get_col($sql)?:[]);}
        return self::ok(['ok'=>true,'accepted'=>$accepted,'duplicates'=>$duplicates,'rejected'=>$rejected,'limited_customer_ids'=>array_values(array_unique($limited)),'limited_session_ids'=>array_values(array_unique($limitedSessions)),'revoked_session_ids'=>$revoked,'reload_required'=>$reload,'server_time'=>time()]);
    }

    public static function rest_heartbeat(WP_REST_Request $r): WP_REST_Response {
        try{$node=self::auth_node($r);}catch(Throwable $e){return self::auth_fail($e);}global $wpdb;$body=$r->get_json_params();if(!is_array($body))$body=[];$error=mb_substr(sanitize_textarea_field((string)($body['error']??'')),0,1800);$xrayRunning=!empty($body['xray_running']);$health=!$xrayRunning?'down':($error!==''?'degraded':'healthy');$lastFlushRaw=(string)($body['last_usage_flush_at']??'');$lastFlush=$lastFlushRaw!==''?BlueVPN_Utils::mysql_from_iso($lastFlushRaw):null;
        $generation=max(0,(int)($body['config_generation']??0));$appliedHash=substr(preg_replace('/[^a-f0-9]/i','',(string)($body['config_hash']??'')),0,64);$policyHash=substr(preg_replace('/[^a-f0-9]/i','',(string)($body['policy_hash']??'')),0,64);$ackRaw=(string)($body['config_applied_at']??'');$ackAt=$ackRaw!==''?BlueVPN_Utils::mysql_from_iso($ackRaw):null;
        $data=['last_seen_at'=>BlueVPN_Utils::now_mysql(),'health_status'=>$health,'active_sessions'=>max(0,min(self::MAX_NODE_SESSIONS,(int)($body['active_sessions']??0))),'pending_usage_events'=>max(0,min(100000,(int)($body['pending_usage_events']??0))),'cpu_load_pct'=>max(0,min(1000,(float)($body['cpu_load_pct']??0))),'memory_used_pct'=>max(0,min(100,(float)($body['memory_used_pct']??0))),'cpu_cores'=>max(1,min(256,(int)($body['cpu_cores']??1))),'memory_total_mb'=>max(256,min(1048576,(int)($body['memory_total_mb']??256))),'agent_uptime_seconds'=>max(0,(int)($body['uptime_seconds']??0)),'agent_boot_id'=>substr(preg_replace('/[^a-zA-Z0-9._:-]/','',(string)($body['agent_boot_id']??'')),0,64),'last_agent_version'=>substr(sanitize_text_field((string)($body['agent_version']??'')),0,64),'last_xray_version'=>substr(sanitize_text_field((string)($body['xray_version']??'')),0,64),'last_config_hash'=>$appliedHash,'last_policy_hash'=>$policyHash,'last_config_generation'=>$generation,'last_error'=>$error,'updated_at'=>BlueVPN_Utils::now_mysql()];if($lastFlush)$data['last_usage_flush_at']=$lastFlush;if($ackAt)$data['last_config_ack_at']=$ackAt;$wpdb->update(self::nodes_table(),$data,['id'=>(int)$node['id']]);$fresh=array_replace($node,$data);self::record_circuit_observation((int)$node['id'],$health==='healthy');self::autopilot_observe_node($fresh,$health,$error);self::rollout_record_heartbeat((int)$node['id'],$generation,$appliedHash,$health,$error);
        if(class_exists('BlueVPN_Error_Monitor'))BlueVPN_Error_Monitor::resolve_matching('gateway','enrollment','GATEWAY_ENROLLMENT_NO_HEARTBEAT_'.(int)$node['id']);
        $circuit=self::circuit_node_state((int)$node['id']);$rollout=self::rollout_summary();$response=['ok'=>true,'server_time'=>time(),'health'=>$health,'draining'=>self::node_effectively_draining($fresh),'effective_capacity'=>self::autopilot_capacity($fresh),'circuit_state'=>$circuit['state'],'rollout'=>$rollout,'autopilot'=>self::autopilot_summary(),'migration'=>self::migration_summary()];
        if((string)($node['_auth_secret_slot']??'')==='previous'){$response['credential_update']=['node_secret'=>self::node_secret($node),'generation'=>max(1,(int)($node['secret_generation']??1))];}
        return self::ok($response);
    }


    public static function ensure_schedule(): void {
        if(!wp_next_scheduled(self::RECONCILE_HOOK))wp_schedule_event(time()+60,'bluevpn_one_minute',self::RECONCILE_HOOK);
    }

    public static function unschedule(): void {
        $ts=wp_next_scheduled(self::RECONCILE_HOOK);while($ts){wp_unschedule_event($ts,self::RECONCILE_HOOK);$ts=wp_next_scheduled(self::RECONCILE_HOOK);}
    }

    public static function scheduled_reconcile(): void {
        self::reconcile_metered_customers(120,true);
        self::migration_tick(500);
        self::rollout_tick();
        self::rotate_stale_secrets(10);
        self::enrollment_health_watchdog(25);
    }

    public static function reconcile_metered_customers(int $limit=500,bool $useCursor=false): array {
        global $wpdb;$ct=self::customers_table();$pt=self::plans_table();$limit=max(1,min(2000,$limit));$cursor=$useCursor?max(0,(int)get_option('bluevpn_gateway_reconcile_cursor',0)):0;
        $sql=$wpdb->prepare("SELECT c.id FROM {$ct} c JOIN {$pt} p ON p.id=c.plan_id AND p.deleted=0 WHERE c.active=1 AND p.traffic_mode='gateway_metered' AND c.id>%d ORDER BY c.id ASC LIMIT {$limit}",$cursor);
        $ids=$wpdb->get_col($sql)?:[];if($useCursor&&!$ids&&$cursor>0){update_option('bluevpn_gateway_reconcile_cursor',0,false);return ['checked'=>0,'placed'=>0,'empty'=>0,'wrapped'=>true];}
        $placed=0;$empty=0;$last=$cursor;foreach($ids as $id){$last=max($last,(int)$id);$rows=self::ensure_customer_sessions((int)$id);if($rows)$placed++;else$empty++;}
        if($useCursor)update_option('bluevpn_gateway_reconcile_cursor',$last,false);
        return ['checked'=>count($ids),'placed'=>$placed,'empty'=>$empty,'wrapped'=>false];
    }

    public static function save_node(): void {
        self::guard_admin();$id=max(0,(int)($_POST['node_id']??0));check_admin_referer('bluevpn_cc_save_gateway_node_'.$id);global $wpdb;$old=$id>0?self::node($id):null;$name=sanitize_text_field(wp_unslash($_POST['name']??''));$host=strtolower(trim(sanitize_text_field(wp_unslash($_POST['public_host']??''))));$host=preg_replace('#^https?://#','',$host);$host=preg_replace('#[/:].*$#','',$host);$port=max(1,min(65535,(int)($_POST['public_port']??443)));$sni=sanitize_text_field(wp_unslash($_POST['server_name']??''));$region=sanitize_text_field(wp_unslash($_POST['region']??''));$priority=max(1,min(10000,(int)($_POST['priority']??100)));$maxSessions=max(1,min(self::MAX_NODE_SESSIONS,(int)($_POST['max_sessions']??5000)));
        if($host===''||strlen($host)>253||!preg_match('/^[a-z0-9.-]+$/',$host))self::redirect('Public Host معتبر وارد کن.',true);if($sni==='')$sni=$host;if($name==='')$name='Gateway '.$host;$secret=$old?self::node_secret($old):'';if($secret==='')$secret=BlueVPN_Utils::random_token(36);$now=BlueVPN_Utils::now_mysql();$data=['name'=>$name,'public_host'=>$host,'public_port'=>$port,'server_name'=>$sni,'transport'=>'tcp','region'=>$region,'priority'=>$priority,'max_sessions'=>$maxSessions,'draining'=>isset($_POST['draining'])?1:0,'secret_enc'=>BlueVPN_Utils::encrypt_secret($secret),'secret_hash'=>hash('sha256',$secret),'active'=>isset($_POST['active'])?1:0,'updated_at'=>$now];
        if($id>0)$ok=$wpdb->update(self::nodes_table(),$data,['id'=>$id]);else{$data['secret_generation']=1;$data['last_secret_rotated_at']=$now;$data['created_at']=$now;$ok=$wpdb->insert(self::nodes_table(),$data);$id=(int)$wpdb->insert_id;if($ok!==false&&$id>0){try{$enroll=self::issue_enrollment_token($id);self::stash_enrollment_display($enroll);}catch(Throwable $ignored){}}}
        self::redirect($ok===false?'ذخیره Gateway ناموفق بود.':($old?'Gateway ذخیره شد.':'Gateway ساخته شد؛ دستور نصب یک‌مرحله‌ای آماده است.'),$ok===false);
    }

    public static function toggle_node(): void {
        self::guard_admin();$id=max(0,(int)($_GET['id']??0));check_admin_referer('bluevpn_cc_toggle_gateway_node_'.$id);global $wpdb;$node=self::node($id);if(!$node)self::redirect('Gateway پیدا نشد.',true);$ok=$wpdb->update(self::nodes_table(),['active'=>(int)$node['active']?0:1,'updated_at'=>BlueVPN_Utils::now_mysql()],['id'=>$id]);self::redirect($ok===false?'تغییر وضعیت Gateway ناموفق بود.':'وضعیت Gateway تغییر کرد.',$ok===false);
    }

    public static function rotate_secret(): void {
        self::guard_admin();$id=max(0,(int)($_POST['node_id']??0));check_admin_referer('bluevpn_cc_rotate_gateway_secret_'.$id);$node=self::node($id);if(!$node)self::redirect('Gateway پیدا نشد.',true);try{self::rotate_node_secret_internal($node);self::redirect('Secret چرخش کرد؛ Agent در Heartbeat بعدی Credential جدید را خودکار دریافت و ذخیره می‌کند.');}catch(Throwable $e){self::redirect('چرخش Secret ناموفق بود.',true);}
    }

    public static function regenerate_enrollment(): void {
        self::guard_admin();$id=max(0,(int)($_POST['node_id']??0));check_admin_referer('bluevpn_cc_regenerate_gateway_enrollment_'.$id);if(!self::node($id))self::redirect('Gateway پیدا نشد.',true);try{$enroll=self::issue_enrollment_token($id);self::stash_enrollment_display($enroll);self::redirect('لینک نصب یک‌بارمصرف جدید ساخته شد.');}catch(Throwable $e){self::redirect('ساخت لینک Enrollment ناموفق بود.',true);}
    }

    public static function reconcile_gateways(): void {
        self::guard_admin();check_admin_referer('bluevpn_cc_reconcile_gateways');$r=self::reconcile_metered_customers(2000,false);self::migration_tick(2000);self::rollout_tick();self::redirect('Reconcile انجام شد؛ '.number_format((int)$r['placed']).' کاربر جایگذاری شد و '.number_format((int)$r['empty']).' کاربر Gateway سالم نداشت.',false);
    }

    public static function delete_node(): void {
        self::guard_admin();$id=max(0,(int)($_POST['node_id']??0));check_admin_referer('bluevpn_cc_delete_gateway_node_'.$id);global $wpdb;$wpdb->query('START TRANSACTION');try{$wpdb->delete(self::usage_table(),['node_id'=>$id],['%d']);$wpdb->delete(self::sessions_table(),['node_id'=>$id],['%d']);$wpdb->delete(self::rollout_table(),['node_id'=>$id],['%d']);$wpdb->query($wpdb->prepare('DELETE FROM '.self::migrations_table().' WHERE source_node_id=%d OR target_node_id=%d',$id,$id));$ok=$wpdb->delete(self::nodes_table(),['id'=>$id],['%d']);if($ok===false)throw new RuntimeException('delete failed');$wpdb->query('COMMIT');self::clear_circuit_node($id);}catch(Throwable $e){$wpdb->query('ROLLBACK');self::redirect('حذف Gateway ناموفق بود.',true);}self::redirect('Gateway و sessionهای آن حذف شدند.');
    }

    public static function render_admin_tab(): void {
        global $wpdb;$nodes=$wpdb->get_results('SELECT * FROM '.self::nodes_table().' ORDER BY active DESC,priority ASC,id ASC',ARRAY_A)?:[];$ct=self::customers_table();$pt=self::plans_table();$metered=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$ct} c JOIN {$pt} p ON p.id=c.plan_id WHERE c.active=1 AND p.traffic_mode='gateway_metered'");$used=(int)$wpdb->get_var("SELECT COALESCE(SUM(c.used_traffic_bytes),0) FROM {$ct} c JOIN {$pt} p ON p.id=c.plan_id WHERE p.traffic_mode='gateway_metered'");$enrollment=get_transient('bluevpn_gateway_enrollment_'.get_current_user_id());if($enrollment!==false)delete_transient('bluevpn_gateway_enrollment_'.get_current_user_id());$enrollmentCommand='';if(is_array($enrollment)&&!empty($enrollment['token_enc'])){$displayToken=BlueVPN_Utils::decrypt_secret((string)$enrollment['token_enc']);if($displayToken!=='')$enrollmentCommand=self::enrollment_command((int)($enrollment['node_id']??0),$displayToken);}$healthy=count(array_filter($nodes,static fn($n)=>self::node_last_seen_age($n)<=self::HEALTHY_WINDOW_SECONDS&&(string)($n['health_status']??'')==='healthy'&&!self::node_effectively_draining($n)));$rollout=self::rollout_summary();$autopilot=self::autopilot_summary();$migration=self::migration_summary();
        echo '<div class="bvc-page-tools"><div><h2 class="bvc-section-title">BlueVPN Gateway HA Metering</h2><p class="bvc-section-subtitle">Phase 6 / 5.2.4: Gateway Autopilot + نصب یک‌مرحله‌ای؛ Node و Secret و Agent به‌صورت خودکار Enrollment می‌شوند.</p></div><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_reconcile_gateways');echo '<input type="hidden" name="action" value="bluevpn_cc_reconcile_gateways"><button class="button button-primary">Reconcile همه Gatewayها</button></form></div>';
        echo '<div class="bvc-grid"><div class="bvc-card bvc-kpi"><span>Gateway سالم</span><strong>'.number_format($healthy).'</strong><small>Autopilot: '.($autopilot['enabled']?'ON':'OFF').'</small></div><div class="bvc-card bvc-kpi"><span>کاربر Metered</span><strong>'.number_format($metered).'</strong></div><div class="bvc-card bvc-kpi"><span>Safe Rollout</span><strong>'.esc_html(strtoupper((string)$rollout['status'])).' '.((int)$rollout['stage_percent']?(int)$rollout['stage_percent'].'%':'').'</strong><small>Stable #'.(int)$rollout['stable_generation'].' • Active #'.(int)$rollout['active_generation'].'</small></div><div class="bvc-card bvc-kpi"><span>Migration فعال</span><strong>'.number_format((int)$migration['active']).'</strong><small>Overlap '.(int)$migration['overlap_seconds'].'s</small></div></div>';
        if($enrollmentCommand!==''){echo '<div class="notice notice-success"><p><strong>نصب یک‌مرحله‌ای Gateway آماده است.</strong> این دستور فقط یک‌بار و تا ۳۰ دقیقه معتبر است.</p><div class="bvc-code">'.esc_html($enrollmentCommand).'</div><p class="description">دستور را روی VPS با دسترسی root اجرا کن؛ Node ID، Secret و agent.json خودکار ساخته می‌شوند.</p></div>';}
        echo '<details class="bvc-card bvc-disclosure" '.(!$nodes?'open':'').'><summary><span><strong>افزودن Gateway با Autopilot</strong><small>فقط Public Host را وارد کن؛ Priority، Capacity و Drain خودکار هستند.</small></span><span>⌄</span></summary><div class="bvc-disclosure-body"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_save_gateway_node_0');echo '<input type="hidden" name="action" value="bluevpn_cc_save_gateway_node"><input type="hidden" name="node_id" value="0"><div class="bvc-form-grid"><label>Public Host<input name="public_host" required placeholder="gw1.example.com"></label></div><input type="hidden" name="public_port" value="443"><input type="hidden" name="priority" value="100"><input type="hidden" name="max_sessions" value="5000"><input type="hidden" name="active" value="1"><details class="bvc-plan-routing"><summary>Advanced Override (اختیاری)</summary><div class="bvc-form-grid"><label>نام<input name="name" placeholder="خودکار از Host"></label><label>Region<input name="region" placeholder="اختیاری"></label><label>TLS Server Name<input name="server_name" placeholder="خودکار از Host"></label></div></details><div class="bvc-form-actions"><button class="button button-primary">ساخت Gateway</button></div></form></div></details>';
        if(!$nodes){echo '<div class="bvc-empty-state"><strong>Gateway ثبت نشده است.</strong><span>بعد از ساخت Node، دستور نصب یک‌مرحله‌ای را روی VPS اجرا کن؛ تنظیم دستی Secret لازم نیست.</span></div>';return;}
        echo '<div class="bvc-plan-list">';
        foreach($nodes as $node){$id=(int)$node['id'];$toggle=wp_nonce_url(admin_url('admin-post.php?action=bluevpn_cc_toggle_gateway_node&id='.$id),'bluevpn_cc_toggle_gateway_node_'.$id);$last=!empty($node['last_seen_at'])?strtotime((string)$node['last_seen_at'].' UTC'):0;$age=self::node_last_seen_age($node);$health=(string)($node['health_status']??'unknown');$circuit=self::circuit_node_state($id);$online=$age<=self::DEGRADED_WINDOW_SECONDS&&$health!=='down';$max=self::autopilot_capacity($node);$active=max(0,(int)($node['active_sessions']??0));$util=min(100,round(($active/$max)*100));$autoState=self::autopilot_node_state($id);$statusLabel=$circuit['state']!=='closed'?'Circuit '.strtoupper($circuit['state']):(self::node_effectively_draining($node)?($autoState['auto_draining']?'Auto-Drain':'Drain'):($online?($health==='healthy'?'سالم':'Degraded'):'آفلاین'));
            echo '<article class="bvc-plan-card '.((int)$node['active']?'is-active':'is-inactive').'"><header class="bvc-plan-head"><div><h3>'.esc_html((string)$node['name']).'</h3><p>'.esc_html((string)$node['public_host']).':'.(int)$node['public_port'].' • '.esc_html((string)($node['region']?:'بدون Region')).' • Autopilot '.(self::autopilot_enabled()?'ON':'OFF').'</p></div><span class="bvc-status-pill '.($online&&$health==='healthy'?'is-active':'is-inactive').'">'.esc_html($statusLabel).'</span></header><div class="bvc-plan-metrics"><div><span>Sessions</span><strong>'.number_format($active).' / '.number_format($max).' ('.$util.'%)</strong></div><div><span>Load / RAM</span><strong>'.number_format((float)($node['cpu_load_pct']??0),1).'% / '.number_format((float)($node['memory_used_pct']??0),1).'%</strong></div><div><span>Pending Usage</span><strong>'.number_format((int)($node['pending_usage_events']??0)).'</strong></div><div><span>آخرین Heartbeat</span><strong>'.($last?esc_html(BlueVPN_Utils::tehran_datetime_fa((string)$node['last_seen_at'])):'—').'</strong></div><div><span>Agent</span><strong>'.esc_html((string)($node['last_agent_version']?:'—')).'</strong></div><div><span>Config ACK</span><strong>#'.number_format((int)($node['last_config_generation']??0)).' • '.(!empty($node['last_config_ack_at'])?esc_html(BlueVPN_Utils::tehran_datetime_fa((string)$node['last_config_ack_at'])):'—').'</strong></div><div><span>Xray</span><strong>'.esc_html((string)($node['last_xray_version']?:'—')).'</strong></div></div><div class="bvc-actions"><a class="button" href="'.esc_url($toggle).'">'.((int)$node['active']?'غیرفعال':'فعال').' کردن</a></div>';
            if(!empty($node['last_error']))echo '<div class="bvc-note bvc-bad">'.esc_html(mb_substr((string)$node['last_error'],0,500)).'</div>';
            echo '<details class="bvc-plan-routing"><summary>Advanced Override — معمولاً نیاز نیست</summary><div class="bvc-plan-routing-body"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_save_gateway_node_'.$id);echo '<input type="hidden" name="action" value="bluevpn_cc_save_gateway_node"><input type="hidden" name="node_id" value="'.$id.'"><div class="bvc-form-grid"><label>نام<input name="name" value="'.esc_attr((string)$node['name']).'" required></label><label>Region<input name="region" value="'.esc_attr((string)($node['region']??'')).'"></label><label>Public Host<input name="public_host" value="'.esc_attr((string)$node['public_host']).'" required></label><label>Port<input type="number" name="public_port" value="'.(int)$node['public_port'].'"></label><label>TLS Server Name<input name="server_name" value="'.esc_attr((string)$node['server_name']).'" required></label><label>Priority<input type="number" name="priority" value="'.(int)($node['priority']??100).'" min="1" max="10000"></label><label>Max Sessions<input type="number" name="max_sessions" value="'.(int)($node['max_sessions']??5000).'" min="1" max="10000"></label></div><label><input type="checkbox" name="active" value="1" '.checked((int)$node['active'],1,false).'> فعال</label> <label><input type="checkbox" name="draining" value="1" '.checked((int)($node['draining']??0),1,false).'> Drain</label><button class="button button-primary">ذخیره</button></form><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block;margin-top:10px">';wp_nonce_field('bluevpn_cc_regenerate_gateway_enrollment_'.$id);echo '<input type="hidden" name="action" value="bluevpn_cc_regenerate_gateway_enrollment"><input type="hidden" name="node_id" value="'.$id.'"><button class="button button-primary">ساخت دستور نصب جدید</button></form><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block;margin:10px 0 0 10px">';wp_nonce_field('bluevpn_cc_rotate_gateway_secret_'.$id);echo '<input type="hidden" name="action" value="bluevpn_cc_rotate_gateway_secret"><input type="hidden" name="node_id" value="'.$id.'"><button class="button">چرخش امن Secret</button></form><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block;margin:10px">';wp_nonce_field('bluevpn_cc_delete_gateway_node_'.$id);echo '<input type="hidden" name="action" value="bluevpn_cc_delete_gateway_node"><input type="hidden" name="node_id" value="'.$id.'"><button class="button button-link-delete" onclick="return confirm(\'Gateway حذف شود؟\')">حذف</button></form></div></details></article>';
        }
        echo '</div><div class="bvc-card"><h3>Agent Endpoint</h3><div class="bvc-code">'.esc_html(rest_url('bluevpn-gateway/v1/config')).'</div><p class="description">Autopilot ظرفیت، Primary/Standby، Drain/Recovery، Handoff و Credential Rotation را خودکار مدیریت می‌کند. برای نصب Node فقط دستور Enrollment را اجرا کن.</p></div>';
    }

    private static function fmt_bytes(int $bytes): string { $n=max(0,(float)$bytes);foreach(['B','KB','MB','GB','TB'] as $u){if($n<1024||$u==='TB')return number_format($n,$n<10&&$u!=='B'?2:0).' '.$u;$n/=1024;}return '0 B'; }
}
