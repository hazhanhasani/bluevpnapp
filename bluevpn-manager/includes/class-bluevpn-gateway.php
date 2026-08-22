<?php
if (!defined('ABSPATH')) exit;

/**
 * BlueVPN Gateway metering control plane.
 *
 * Phase 2 adds health-aware replicas, capacity/drain controls, immediate revoke
 * hints, quota leases for local fail-closed enforcement, monotonic usage sequence
 * checks, and richer node health telemetry.
 */
final class BlueVPN_Gateway {
    private const AUTH_WINDOW_SECONDS = 300;
    private const MAX_USAGE_EVENTS = 500;
    private const MAX_EVENT_BYTES = 1099511627776; // 1 TiB sanity cap per delta/event.
    private const NODE_ONLINE_SECONDS = 180;
    private const GATEWAY_REPLICA_COUNT = 2;

    public static function init(): void {
        add_action('rest_api_init',[self::class,'register_routes']);
        add_action('admin_post_bluevpn_cc_save_gateway_node',[self::class,'save_node']);
        add_action('admin_post_bluevpn_cc_toggle_gateway_node',[self::class,'toggle_node']);
        add_action('admin_post_bluevpn_cc_delete_gateway_node',[self::class,'delete_node']);
        add_action('admin_post_bluevpn_cc_rotate_gateway_secret',[self::class,'rotate_secret']);
    }

    private static function nodes_table(): string { return BlueVPN_DB::table('gateway_nodes'); }
    private static function sessions_table(): string { return BlueVPN_DB::table('gateway_sessions'); }
    private static function usage_table(): string { return BlueVPN_DB::table('gateway_usage_events'); }
    private static function customers_table(): string { return BlueVPN_DB::table('customers'); }
    private static function plans_table(): string { return BlueVPN_DB::table('plans'); }

    public static function register_routes(): void {
        register_rest_route('bluevpn-gateway/v1','/config',['methods'=>'GET','callback'=>[self::class,'rest_config'],'permission_callback'=>'__return_true']);
        register_rest_route('bluevpn-gateway/v1','/usage',['methods'=>'POST','callback'=>[self::class,'rest_usage'],'permission_callback'=>'__return_true']);
        register_rest_route('bluevpn-gateway/v1','/heartbeat',['methods'=>'POST','callback'=>[self::class,'rest_heartbeat'],'permission_callback'=>'__return_true']);
    }

    private static function ok(array $data,int $status=200): WP_REST_Response { return new WP_REST_Response($data,$status); }
    private static function guard_admin(): void { if(!current_user_can('manage_options'))wp_die('دسترسی ندارید.'); }
    private static function redirect(string $message,bool $error=false): void { $key=$error?'cc_error':'cc_msg';wp_safe_redirect(add_query_arg([$key=>$message],admin_url('admin.php?page=bluevpn-gateway')));exit; }

    private static function uuid_v4(): string {
        $b=random_bytes(16);$b[6]=chr((ord($b[6])&0x0f)|0x40);$b[8]=chr((ord($b[8])&0x3f)|0x80);$h=bin2hex($b);
        return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20,12);
    }

    private static function node_secret(array $node): string { return BlueVPN_Utils::decrypt_secret((string)($node['secret_enc']??'')); }

    public static function node(int $id): ?array {
        if($id<=0)return null;global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::nodes_table().' WHERE id=%d LIMIT 1',$id),ARRAY_A);return is_array($row)?$row:null;
    }

    public static function node_is_online(array $node): bool {
        if((int)($node['active']??0)!==1 || (int)($node['draining']??0)===1)return false;
        $raw=(string)($node['last_seen_at']??'');if($raw==='')return false;
        $ts=strtotime($raw.' UTC');return $ts!==false && (time()-$ts)<self::NODE_ONLINE_SECONDS;
    }

    private static function node_session_count(int $nodeId): int {
        if($nodeId<=0)return 0;global $wpdb;return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".self::sessions_table()." WHERE node_id=%d AND status='active'",$nodeId));
    }

    /** @return array<int,array<string,mixed>> */
    public static function active_nodes(): array {
        global $wpdb;return $wpdb->get_results('SELECT * FROM '.self::nodes_table().' WHERE active=1 ORDER BY priority ASC,id ASC',ARRAY_A)?:[];
    }

    /**
     * Health-aware candidate order. Cold nodes (never seen) are accepted only for
     * initial bootstrap; once at least one node is online, offline nodes are not
     * assigned new sessions.
     */
    public static function healthy_nodes(bool $allowBootstrap=true,int $customerId=0): array {
        $nodes=self::active_nodes();$online=[];$cold=[];
        foreach($nodes as $node){
            if((int)($node['draining']??0)===1)continue;
            $count=self::node_session_count((int)$node['id']);$node['_session_count']=$count;
            $max=max(0,(int)($node['max_sessions']??0));if($max>0 && $count>=$max){$existing=false;if($customerId>0){global $wpdb;$existing=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".self::sessions_table()." WHERE node_id=%d AND customer_id=%d AND status='active'",(int)$node['id'],$customerId))>0;}if(!$existing)continue;}
            if(self::node_is_online($node))$online[]=$node;elseif($allowBootstrap && empty($node['last_seen_at']))$cold[]=$node;
        }
        $sort=static function(array $a,array $b): int {
            $pa=(int)($a['priority']??100);$pb=(int)($b['priority']??100);if($pa!==$pb)return $pa<=>$pb;
            $sa=(int)($a['_session_count']??0);$sb=(int)($b['_session_count']??0);if($sa!==$sb)return $sa<=>$sb;
            $la=(float)($a['last_load1']??0);$lb=(float)($b['last_load1']??0);if($la!==$lb)return $la<=>$lb;
            return ((int)$a['id'])<=>((int)$b['id']);
        };
        usort($online,$sort);usort($cold,$sort);
        return $online ?: $cold;
    }

    public static function plan_traffic_mode(int $planId): string {
        if($planId<=0)return 'provider_reported';global $wpdb;$mode=(string)$wpdb->get_var($wpdb->prepare('SELECT traffic_mode FROM '.self::plans_table().' WHERE id=%d LIMIT 1',$planId));return $mode==='gateway_metered'?'gateway_metered':'provider_reported';
    }

    public static function is_gateway_metered_customer(array $customer): bool { return self::plan_traffic_mode((int)($customer['plan_id']??0))==='gateway_metered'; }
    public static function has_active_gateway(): bool { return count(self::healthy_nodes(true))>0; }

    public static function entitlement_allows(array $customer): bool {
        if(!(int)($customer['active']??0))return false;$status=strtolower(trim((string)($customer['subscription_status']??'')));if(in_array($status,['limited','expired','inactive','disabled','revoked'],true))return false;
        $exp=!empty($customer['subscription_expire'])?(strtotime((string)$customer['subscription_expire'].' UTC')?:0):0;if($exp>0&&$exp<=time())return false;
        $limit=max(0,(int)($customer['data_limit_bytes']??0));$used=max(0,(int)($customer['used_traffic_bytes']??0));if($limit>0&&$used>=$limit)return false;
        return true;
    }

    /** @return array<int,array<string,mixed>> */
    public static function ensure_customer_sessions(int $customerId): array {
        if($customerId<=0)return [];global $wpdb;$ct=self::customers_table();$st=self::sessions_table();
        $customer=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$ct} WHERE id=%d LIMIT 1",$customerId),ARRAY_A);if(!$customer||!self::is_gateway_metered_customer($customer))return [];
        if(!self::entitlement_allows($customer)){
            $wpdb->update($st,['status'=>'revoked','updated_at'=>BlueVPN_Utils::now_mysql()],['customer_id'=>$customerId]);return [];
        }
        $nodes=self::healthy_nodes(true,$customerId);if(!$nodes)return [];$desired=min(self::GATEWAY_REPLICA_COUNT,count($nodes));$nodes=array_slice($nodes,0,$desired);$selected=[];$out=[];$now=BlueVPN_Utils::now_mysql();
        foreach($nodes as $node){
            $nodeId=(int)$node['id'];$selected[]=$nodeId;$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$st} WHERE node_id=%d AND customer_id=%d LIMIT 1",$nodeId,$customerId),ARRAY_A);
            if(!$row){
                $uuid=self::uuid_v4();$email='bv-'.$customerId.'-n'.$nodeId.'@gateway.bluevpn';
                $ok=$wpdb->insert($st,['node_id'=>$nodeId,'customer_id'=>$customerId,'client_uuid'=>$uuid,'client_email'=>$email,'status'=>'active','created_at'=>$now,'updated_at'=>$now]);
                if($ok!==false)$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$st} WHERE id=%d",(int)$wpdb->insert_id),ARRAY_A);
            }elseif((string)$row['status']!=='active'){
                $wpdb->update($st,['status'=>'active','updated_at'=>$now],['id'=>(int)$row['id']]);$row['status']='active';
            }
            if(is_array($row))$out[]=$row;
        }
        // Any replica not selected by the current health/capacity scheduler becomes standby.
        if($selected){$marks=implode(',',array_fill(0,count($selected),'%d'));$args=array_merge([$now,$customerId],$selected);$sql=$wpdb->prepare("UPDATE {$st} SET status='standby',updated_at=%s WHERE customer_id=%d AND status='active' AND node_id NOT IN ({$marks})",...$args);$wpdb->query($sql);}else{$wpdb->update($st,['status'=>'standby','updated_at'=>$now],['customer_id'=>$customerId,'status'=>'active']);}
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private static function active_delivery_sessions(int $customerId): array {
        global $wpdb;$st=self::sessions_table();$nt=self::nodes_table();$rows=$wpdb->get_results($wpdb->prepare("SELECT s.*,n.active AS node_active,n.draining,n.last_seen_at,n.priority,n.max_sessions FROM {$st} s JOIN {$nt} n ON n.id=s.node_id WHERE s.customer_id=%d AND s.status='active' AND n.active=1 AND n.draining=0 ORDER BY n.priority ASC,n.id ASC",$customerId),ARRAY_A)?:[];
        return array_values(array_filter($rows,static fn($r)=>self::node_is_online($r)));
    }

    public static function gateway_subscription_lines(array $customer): array {
        if(!self::is_gateway_metered_customer($customer)||!self::entitlement_allows($customer))return [];
        self::ensure_customer_sessions((int)$customer['id']);$sessions=self::active_delivery_sessions((int)$customer['id']);if(!$sessions)return [];
        $nodes=[];foreach(self::active_nodes() as $n)$nodes[(int)$n['id']]=$n;$lines=[];
        foreach($sessions as $session){$node=$nodes[(int)$session['node_id']]??null;if(!$node||!self::node_is_online($node))continue;$host=trim((string)$node['public_host']);$port=max(1,min(65535,(int)$node['public_port']));$sni=trim((string)$node['server_name']);if($host===''||$sni==='')continue;
            $query=http_build_query(['encryption'=>'none','security'=>'tls','sni'=>$sni,'type'=>'tcp'],'','&',PHP_QUERY_RFC3986);$name=rawurlencode('BlueVPN Gateway • '.((string)$node['name']?:$host));$lines[]='vless://'.rawurlencode((string)$session['client_uuid']).'@'.$host.':'.$port.'?'.$query.'#'.$name;
        }
        return $lines;
    }

    private static function auth_node(WP_REST_Request $r): array {
        $nodeId=(int)$r->get_header('x-bluevpn-gateway-id');$timestamp=trim((string)$r->get_header('x-bluevpn-gateway-timestamp'));$signature=strtolower(trim((string)$r->get_header('x-bluevpn-gateway-signature')));
        if($nodeId<=0||!preg_match('/^\d{10}$/',$timestamp)||abs(time()-(int)$timestamp)>self::AUTH_WINDOW_SECONDS||!preg_match('/^[a-f0-9]{64}$/',$signature))throw new RuntimeException('GATEWAY_AUTH_INVALID');
        $node=self::node($nodeId);if(!$node||(int)$node['active']!==1)throw new RuntimeException('GATEWAY_NODE_DISABLED');$secret=self::node_secret($node);if($secret==='')throw new RuntimeException('GATEWAY_SECRET_MISSING');
        $body=(string)$r->get_body();$route=(string)$r->get_route();$message=$timestamp."\n".strtoupper((string)$r->get_method())."\n".$route."\n".hash('sha256',$body);$expected=hash_hmac('sha256',$message,$secret);if(!hash_equals($expected,$signature))throw new RuntimeException('GATEWAY_AUTH_INVALID');
        return $node;
    }

    private static function auth_fail(Throwable $e): WP_REST_Response {
        $code=$e->getMessage();$status=$code==='GATEWAY_NODE_DISABLED'?403:401;return self::ok(['ok'=>false,'detail'=>['code'=>$code,'message'=>'احراز هویت Gateway معتبر نیست.']],$status);
    }

    public static function rest_config(WP_REST_Request $r): WP_REST_Response {
        try{$node=self::auth_node($r);}catch(Throwable $e){return self::auth_fail($e);}global $wpdb;$nodeId=(int)$node['id'];$st=self::sessions_table();$ct=self::customers_table();$pt=self::plans_table();$ut=self::usage_table();$now=BlueVPN_Utils::now_mysql();
        if((int)($node['draining']??0)===1){$hash=hash('sha256','draining:'.$nodeId);$wpdb->update(self::nodes_table(),['last_seen_at'=>$now,'last_config_hash'=>$hash,'updated_at'=>$now],['id'=>$nodeId]);return self::ok(['ok'=>true,'schema'=>2,'mode'=>'gateway_metered','draining'=>true,'config_hash'=>$hash,'policy_hash'=>$hash,'generated_at'=>BlueVPN_Utils::iso_now(),'sessions'=>[]]);}
        $rows=$wpdb->get_results($wpdb->prepare("SELECT s.*,c.plan_id,c.subscription_status,c.subscription_expire,c.data_limit_bytes,c.used_traffic_bytes,c.active,p.traffic_mode FROM {$st} s JOIN {$ct} c ON c.id=s.customer_id JOIN {$pt} p ON p.id=c.plan_id AND p.deleted=0 WHERE s.node_id=%d AND s.status='active' AND c.active=1 AND p.traffic_mode='gateway_metered' ORDER BY s.customer_id ASC LIMIT 1000",$nodeId),ARRAY_A)?:[];
        $seqRows=$wpdb->get_results($wpdb->prepare("SELECT session_id,MAX(seq) AS last_seq FROM {$ut} WHERE node_id=%d GROUP BY session_id",$nodeId),ARRAY_A)?:[];$seqMap=[];foreach($seqRows as $x)$seqMap[(int)$x['session_id']]=(int)$x['last_seq'];
        $sessions=[];$fingerprint=[];$policyFingerprint=[];
        foreach($rows as $row){
            if(!self::entitlement_allows($row))continue;$pool=class_exists('BlueVPN_Providers')?BlueVPN_Providers::gateway_upstream_pool((int)$row['customer_id']):[];if(!$pool)continue;
            $limit=max(0,(int)$row['data_limit_bytes']);$used=max(0,(int)$row['used_traffic_bytes']);$remaining=$limit>0?max(0,$limit-$used):0;$replicas=max(1,count(self::active_delivery_sessions((int)$row['customer_id'])));$lease=$limit>0?(int)ceil($remaining/$replicas):0;
            $item=['session_id'=>(int)$row['id'],'customer_id'=>(int)$row['customer_id'],'email'=>(string)$row['client_email'],'uuid'=>(string)$row['client_uuid'],'expires_at'=>(string)($row['subscription_expire']??''),'quota_bytes'=>$limit,'used_bytes'=>$used,'remaining_bytes'=>$remaining,'lease_bytes'=>$lease,'last_seq'=>(int)($seqMap[(int)$row['id']]??0),'upstreams'=>array_values($pool)];
            $sessions[]=$item;$fingerprint[]=['id'=>$item['session_id'],'uuid'=>$item['uuid'],'expires'=>$item['expires_at'],'pool'=>hash('sha256',implode("\n",$item['upstreams']))];$policyFingerprint[]=['id'=>$item['session_id'],'lease'=>$lease,'last_seq'=>$item['last_seq'],'remaining'=>$remaining];
        }
        $hash=hash('sha256',wp_json_encode($fingerprint,JSON_UNESCAPED_SLASHES));$policyHash=hash('sha256',wp_json_encode($policyFingerprint,JSON_UNESCAPED_SLASHES));$wpdb->update(self::nodes_table(),['last_seen_at'=>$now,'last_config_hash'=>$hash,'last_error'=>'','updated_at'=>$now],['id'=>$nodeId]);
        return self::ok(['ok'=>true,'schema'=>2,'mode'=>'gateway_metered','node'=>['id'=>$nodeId,'name'=>(string)$node['name'],'public_host'=>(string)$node['public_host'],'public_port'=>(int)$node['public_port'],'server_name'=>(string)$node['server_name'],'transport'=>(string)$node['transport'],'priority'=>(int)($node['priority']??100),'max_sessions'=>(int)($node['max_sessions']??0)],'config_hash'=>$hash,'policy_hash'=>$policyHash,'generated_at'=>BlueVPN_Utils::iso_now(),'sessions'=>$sessions]);
    }

    public static function rest_usage(WP_REST_Request $r): WP_REST_Response {
        try{$node=self::auth_node($r);}catch(Throwable $e){return self::auth_fail($e);}global $wpdb;$payload=$r->get_json_params();if(!is_array($payload))$payload=[];$events=$payload['events']??[];if(!is_array($events))$events=[];$events=array_slice($events,0,self::MAX_USAGE_EVENTS);$nodeId=(int)$node['id'];$st=self::sessions_table();$ut=self::usage_table();$ct=self::customers_table();$accepted=0;$duplicates=0;$rejected=0;$limited=[];$reload=false;$now=BlueVPN_Utils::now_mysql();
        $wpdb->query('START TRANSACTION');
        try{
            foreach($events as $event){
                if(!is_array($event)){$rejected++;continue;}$eventId=substr(sanitize_text_field((string)($event['event_id']??'')),0,120);$sessionId=max(0,(int)($event['session_id']??0));$seq=max(0,(int)($event['seq']??0));$up=min(self::MAX_EVENT_BYTES,max(0,(int)($event['uplink_bytes']??0)));$down=min(self::MAX_EVENT_BYTES,max(0,(int)($event['downlink_bytes']??0)));
                if($eventId===''||$sessionId<=0||$seq<=0||($up+$down)<=0){$rejected++;continue;}
                $session=$wpdb->get_row($wpdb->prepare("SELECT s.*,c.data_limit_bytes,c.used_traffic_bytes,c.subscription_status FROM {$st} s JOIN {$ct} c ON c.id=s.customer_id WHERE s.id=%d AND s.node_id=%d LIMIT 1 FOR UPDATE",$sessionId,$nodeId),ARRAY_A);if(!$session){$rejected++;continue;}
                $exists=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$ut} WHERE event_id=%s LIMIT 1",$eventId));if($exists>0){$duplicates++;continue;}
                // A different event_id must not be able to replay the same reset-counter sequence.
                $seqExists=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$ut} WHERE session_id=%d AND seq=%d LIMIT 1",$sessionId,$seq));if($seqExists>0){$duplicates++;continue;}
                $reportedRaw=(string)($event['reported_at']??'');$reported=$reportedRaw!==''?BlueVPN_Utils::mysql_from_iso($reportedRaw):null;if(!$reported)$reported=$now;
                $ok=$wpdb->insert($ut,['event_id'=>$eventId,'node_id'=>$nodeId,'session_id'=>$sessionId,'customer_id'=>(int)$session['customer_id'],'seq'=>$seq,'uplink_bytes'=>$up,'downlink_bytes'=>$down,'reported_at'=>$reported,'created_at'=>$now]);if($ok===false)throw new RuntimeException('usage insert failed');
                $delta=$up+$down;$wpdb->query($wpdb->prepare("UPDATE {$st} SET used_uplink_bytes=used_uplink_bytes+%d,used_downlink_bytes=used_downlink_bytes+%d,last_usage_at=%s,updated_at=%s WHERE id=%d",$up,$down,$now,$now,$sessionId));$wpdb->query($wpdb->prepare("UPDATE {$ct} SET used_traffic_bytes=used_traffic_bytes+%d,last_sync_at=%s WHERE id=%d",$delta,$now,(int)$session['customer_id']));
                // Re-read authoritative total after each event so multiple events in one batch cannot bypass quota.
                $fresh=$wpdb->get_row($wpdb->prepare("SELECT used_traffic_bytes,data_limit_bytes,subscription_status FROM {$ct} WHERE id=%d LIMIT 1",(int)$session['customer_id']),ARRAY_A);$newUsed=(int)($fresh['used_traffic_bytes']??0);$limit=(int)($fresh['data_limit_bytes']??0);
                if($limit>0&&$newUsed>=$limit){$wpdb->update($ct,['subscription_status'=>'limited','last_sync_error'=>'Gateway quota exhausted'],['id'=>(int)$session['customer_id']]);$wpdb->update($st,['status'=>'limited','updated_at'=>$now],['customer_id'=>(int)$session['customer_id']]);$limited[]=(int)$session['customer_id'];$reload=true;}
                $accepted++;
            }
            $wpdb->query('COMMIT');
        }catch(Throwable $e){$wpdb->query('ROLLBACK');return self::ok(['ok'=>false,'detail'=>['code'=>'GATEWAY_USAGE_STORE_FAILED','message'=>$e->getMessage()]],500);}
        $revoked=[];if($limited){$ids=array_values(array_unique($limited));$marks=implode(',',array_fill(0,count($ids),'%d'));$args=array_merge([$nodeId],$ids);$sql=$wpdb->prepare("SELECT id FROM {$st} WHERE node_id=%d AND customer_id IN ({$marks})",...$args);$revoked=array_map('intval',$wpdb->get_col($sql)?:[]);}
        $wpdb->update(self::nodes_table(),['last_seen_at'=>$now,'last_error'=>'','updated_at'=>$now],['id'=>$nodeId]);return self::ok(['ok'=>true,'accepted'=>$accepted,'duplicates'=>$duplicates,'rejected'=>$rejected,'limited_customer_ids'=>array_values(array_unique($limited)),'revoked_session_ids'=>$revoked,'reload_required'=>$reload,'server_time'=>time()]);
    }

    public static function rest_heartbeat(WP_REST_Request $r): WP_REST_Response {
        try{$node=self::auth_node($r);}catch(Throwable $e){return self::auth_fail($e);}global $wpdb;$body=$r->get_json_params();if(!is_array($body))$body=[];
        $data=['last_seen_at'=>BlueVPN_Utils::now_mysql(),'last_agent_version'=>substr(sanitize_text_field((string)($body['agent_version']??'')),0,64),'last_xray_version'=>substr(sanitize_text_field((string)($body['xray_version']??'')),0,64),'last_singbox_version'=>substr(sanitize_text_field((string)($body['singbox_version']??'')),0,64),'last_config_hash'=>substr(preg_replace('/[^a-f0-9]/i','',(string)($body['config_hash']??'')),0,64),'last_active_sessions'=>max(0,(int)($body['last_active_sessions']??0)),'last_pending_events'=>max(0,(int)($body['pending_events']??0)),'last_load1'=>max(0,min(99999,(float)($body['load1']??0))),'last_error'=>mb_substr(sanitize_textarea_field((string)($body['error']??'')),0,1800),'updated_at'=>BlueVPN_Utils::now_mysql()];
        $wpdb->update(self::nodes_table(),$data,['id'=>(int)$node['id']]);return self::ok(['ok'=>true,'server_time'=>time(),'draining'=>(int)($node['draining']??0)===1]);
    }

    public static function save_node(): void {
        self::guard_admin();$id=max(0,(int)($_POST['node_id']??0));check_admin_referer('bluevpn_cc_save_gateway_node_'.$id);global $wpdb;$old=$id>0?self::node($id):null;$name=sanitize_text_field(wp_unslash($_POST['name']??''));$host=sanitize_text_field(wp_unslash($_POST['public_host']??''));$port=max(1,min(65535,(int)($_POST['public_port']??443)));$sni=sanitize_text_field(wp_unslash($_POST['server_name']??''));$priority=max(0,min(10000,(int)($_POST['priority']??100)));$maxSessions=max(0,min(100000,(int)($_POST['max_sessions']??0)));if($name===''||$host===''||$sni==='')self::redirect('نام، Host و TLS Server Name اجباری است.',true);$secret=$old?self::node_secret($old):'';if($secret==='')$secret=BlueVPN_Utils::random_token(36);
        $data=['name'=>$name,'public_host'=>$host,'public_port'=>$port,'server_name'=>$sni,'transport'=>'tcp','priority'=>$priority,'max_sessions'=>$maxSessions,'draining'=>isset($_POST['draining'])?1:0,'secret_enc'=>BlueVPN_Utils::encrypt_secret($secret),'secret_hash'=>hash('sha256',$secret),'active'=>isset($_POST['active'])?1:0,'updated_at'=>BlueVPN_Utils::now_mysql()];
        if($id>0)$ok=$wpdb->update(self::nodes_table(),$data,['id'=>$id]);else{$data['created_at']=BlueVPN_Utils::now_mysql();$ok=$wpdb->insert(self::nodes_table(),$data);$id=(int)$wpdb->insert_id;set_transient('bluevpn_gateway_secret_'.get_current_user_id(),['node_id'=>$id,'secret'=>$secret],300);}self::redirect($ok===false?'ذخیره Gateway ناموفق بود.':'Gateway ذخیره شد.',$ok===false);
    }

    public static function toggle_node(): void {
        self::guard_admin();$id=max(0,(int)($_GET['id']??0));check_admin_referer('bluevpn_cc_toggle_gateway_node_'.$id);global $wpdb;$node=self::node($id);if(!$node)self::redirect('Gateway پیدا نشد.',true);$ok=$wpdb->update(self::nodes_table(),['active'=>(int)$node['active']?0:1,'updated_at'=>BlueVPN_Utils::now_mysql()],['id'=>$id]);self::redirect($ok===false?'تغییر وضعیت Gateway ناموفق بود.':'وضعیت Gateway تغییر کرد.',$ok===false);
    }

    public static function rotate_secret(): void {
        self::guard_admin();$id=max(0,(int)($_POST['node_id']??0));check_admin_referer('bluevpn_cc_rotate_gateway_secret_'.$id);global $wpdb;$node=self::node($id);if(!$node)self::redirect('Gateway پیدا نشد.',true);$secret=BlueVPN_Utils::random_token(36);$ok=$wpdb->update(self::nodes_table(),['secret_enc'=>BlueVPN_Utils::encrypt_secret($secret),'secret_hash'=>hash('sha256',$secret),'updated_at'=>BlueVPN_Utils::now_mysql()],['id'=>$id]);if($ok!==false)set_transient('bluevpn_gateway_secret_'.get_current_user_id(),['node_id'=>$id,'secret'=>$secret],300);self::redirect($ok===false?'چرخش Secret ناموفق بود.':'Secret جدید ساخته شد؛ Agent را با Secret جدید بروزرسانی کن.',$ok===false);
    }

    public static function delete_node(): void {
        self::guard_admin();$id=max(0,(int)($_POST['node_id']??0));check_admin_referer('bluevpn_cc_delete_gateway_node_'.$id);global $wpdb;$wpdb->query('START TRANSACTION');try{$wpdb->delete(self::usage_table(),['node_id'=>$id],['%d']);$wpdb->delete(self::sessions_table(),['node_id'=>$id],['%d']);$ok=$wpdb->delete(self::nodes_table(),['id'=>$id],['%d']);if($ok===false)throw new RuntimeException('delete failed');$wpdb->query('COMMIT');}catch(Throwable $e){$wpdb->query('ROLLBACK');self::redirect('حذف Gateway ناموفق بود.',true);}self::redirect('Gateway و sessionهای آن حذف شدند.');
    }

    public static function render_admin_tab(): void {
        global $wpdb;$nodes=$wpdb->get_results('SELECT * FROM '.self::nodes_table().' ORDER BY active DESC,priority ASC,id ASC',ARRAY_A)?:[];$ct=self::customers_table();$pt=self::plans_table();$metered=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$ct} c JOIN {$pt} p ON p.id=c.plan_id WHERE c.active=1 AND p.traffic_mode='gateway_metered'");$used=(int)$wpdb->get_var("SELECT COALESCE(SUM(c.used_traffic_bytes),0) FROM {$ct} c JOIN {$pt} p ON p.id=c.plan_id WHERE p.traffic_mode='gateway_metered'");$secret=get_transient('bluevpn_gateway_secret_'.get_current_user_id());if($secret!==false)delete_transient('bluevpn_gateway_secret_'.get_current_user_id());
        echo '<div class="bvc-page-tools"><div><h2 class="bvc-section-title">BlueVPN Gateway Metering</h2><p class="bvc-section-subtitle">Phase 2: سهمیه مرکزی، failover سلامت‌محور، ظرفیت Node و revoke سریع.</p></div></div>';
        echo '<div class="bvc-grid"><div class="bvc-card bvc-kpi"><span>Gateway آنلاین</span><strong>'.count(array_filter($nodes,static fn($n)=>self::node_is_online($n))).'</strong></div><div class="bvc-card bvc-kpi"><span>کاربر Metered</span><strong>'.number_format($metered).'</strong></div><div class="bvc-card bvc-kpi"><span>مصرف ثبت‌شده</span><strong>'.esc_html(self::fmt_bytes($used)).'</strong></div></div>';
        if(is_array($secret)&&!empty($secret['secret'])){echo '<div class="notice notice-warning"><p><strong>Secret فقط همین یک بار نمایش داده می‌شود.</strong></p><div class="bvc-code">NODE_ID='.(int)$secret['node_id'].'<br>NODE_SECRET='.esc_html((string)$secret['secret']).'</div></div>';}
        echo '<details class="bvc-card bvc-disclosure" '.(!$nodes?'open':'').'><summary><span><strong>افزودن Gateway</strong><small>Node لینوکسی با TLS معتبر</small></span><span>⌄</span></summary><div class="bvc-disclosure-body"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_save_gateway_node_0');echo '<input type="hidden" name="action" value="bluevpn_cc_save_gateway_node"><input type="hidden" name="node_id" value="0"><div class="bvc-form-grid"><label>نام<input name="name" required placeholder="Gateway Paris"></label><label>Public Host<input name="public_host" required placeholder="gw1.example.com"></label><label>Port<input type="number" name="public_port" value="443" min="1" max="65535"></label><label>TLS Server Name<input name="server_name" required placeholder="gw1.example.com"></label><label>Priority<input type="number" name="priority" value="100" min="0" max="10000"><small>عدد کمتر = اولویت بیشتر</small></label><label>Max Sessions<input type="number" name="max_sessions" value="0" min="0"><small>۰ = بدون سقف</small></label></div><label><input type="checkbox" name="active" value="1" checked> فعال</label> <label><input type="checkbox" name="draining" value="1"> Drain؛ اتصال جدید نگیرد</label><div class="bvc-form-actions"><button class="button button-primary">ساخت Gateway</button></div></form></div></details>';
        if(!$nodes){echo '<div class="bvc-empty-state"><strong>Gateway ثبت نشده است.</strong><span>بعد از ساخت Node، Secret را داخل agent.json سرور قرار بده.</span></div>';return;}
        echo '<div class="bvc-plan-list">';
        foreach($nodes as $node){$id=(int)$node['id'];$toggle=wp_nonce_url(admin_url('admin-post.php?action=bluevpn_cc_toggle_gateway_node&id='.$id),'bluevpn_cc_toggle_gateway_node_'.$id);$last=!empty($node['last_seen_at'])?strtotime((string)$node['last_seen_at'].' UTC'):0;$online=self::node_is_online($node);$sessionCount=self::node_session_count($id);echo '<article class="bvc-plan-card '.((int)$node['active']?'is-active':'is-inactive').'"><header class="bvc-plan-head"><div><h3>'.esc_html((string)$node['name']).'</h3><p>'.esc_html((string)$node['public_host']).':'.(int)$node['public_port'].' • TLS '.esc_html((string)$node['server_name']).' • Priority '.(int)($node['priority']??100).'</p></div><span class="bvc-status-pill '.($online?'is-active':'is-inactive').'">'.($online?'آنلاین':((int)($node['draining']??0)?'Drain':'آفلاین')).'</span></header><div class="bvc-plan-metrics"><div><span>آخرین Heartbeat</span><strong>'.($last?esc_html(BlueVPN_Utils::tehran_datetime_fa((string)$node['last_seen_at'])):'—').'</strong></div><div><span>Session فعال</span><strong>'.number_format($sessionCount).' / '.((int)($node['max_sessions']??0)>0?number_format((int)$node['max_sessions']):'∞').'</strong></div><div><span>Pending usage</span><strong>'.number_format((int)($node['last_pending_events']??0)).'</strong></div><div><span>Load 1m</span><strong>'.esc_html(number_format((float)($node['last_load1']??0),2)).'</strong></div><div><span>Agent</span><strong>'.esc_html((string)($node['last_agent_version']?:'—')).'</strong></div><div><span>Xray</span><strong>'.esc_html((string)($node['last_xray_version']?:'—')).'</strong></div><div><span>sing-box</span><strong>'.esc_html((string)($node['last_singbox_version']?:'—')).'</strong></div></div><div class="bvc-actions"><a class="button" href="'.esc_url($toggle).'">'.((int)$node['active']?'غیرفعال':'فعال').' کردن</a></div><details class="bvc-plan-routing"><summary>تنظیمات Node</summary><div class="bvc-plan-routing-body"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_save_gateway_node_'.$id);echo '<input type="hidden" name="action" value="bluevpn_cc_save_gateway_node"><input type="hidden" name="node_id" value="'.$id.'"><div class="bvc-form-grid"><label>نام<input name="name" value="'.esc_attr((string)$node['name']).'" required></label><label>Public Host<input name="public_host" value="'.esc_attr((string)$node['public_host']).'" required></label><label>Port<input type="number" name="public_port" value="'.(int)$node['public_port'].'"></label><label>TLS Server Name<input name="server_name" value="'.esc_attr((string)$node['server_name']).'" required></label><label>Priority<input type="number" name="priority" value="'.(int)($node['priority']??100).'" min="0" max="10000"></label><label>Max Sessions<input type="number" name="max_sessions" value="'.(int)($node['max_sessions']??0).'" min="0"></label></div><label><input type="checkbox" name="active" value="1" '.checked((int)$node['active'],1,false).'> فعال</label> <label><input type="checkbox" name="draining" value="1" '.checked((int)($node['draining']??0),1,false).'> Drain</label><button class="button button-primary">ذخیره</button></form><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block;margin-top:10px">';wp_nonce_field('bluevpn_cc_rotate_gateway_secret_'.$id);echo '<input type="hidden" name="action" value="bluevpn_cc_rotate_gateway_secret"><input type="hidden" name="node_id" value="'.$id.'"><button class="button">چرخش Secret</button></form><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block;margin:10px">';wp_nonce_field('bluevpn_cc_delete_gateway_node_'.$id);echo '<input type="hidden" name="action" value="bluevpn_cc_delete_gateway_node"><input type="hidden" name="node_id" value="'.$id.'"><button class="button button-link-delete" onclick="return confirm(\'Gateway حذف شود؟\')">حذف</button></form></div></details></article>';}
        echo '</div><div class="bvc-card"><h3>Agent Endpoint</h3><div class="bvc-code">'.esc_html(rest_url('bluevpn-gateway/v1/config')).'</div><p class="description">Agent 5.1.5 در پوشه <code>bluevpn-gateway/</code> قرار دارد. Secret را فقط روی خود Gateway نگهداری کن.</p></div>';
    }

    private static function fmt_bytes(int $bytes): string { $n=max(0,(float)$bytes);foreach(['B','KB','MB','GB','TB'] as $u){if($n<1024||$u==='TB')return number_format($n,$n<10&&$u!=='B'?2:0).' '.$u;$n/=1024;}return '0 B'; }
}
