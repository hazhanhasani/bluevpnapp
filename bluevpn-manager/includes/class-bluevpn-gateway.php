<?php
if (!defined('ABSPATH')) exit;

/**
 * BlueVPN Gateway metering control plane.
 *
 * Apps receive only first-party gateway VLESS credentials. Upstream provider
 * subscriptions remain server-side. Gateway agents pull signed configs, run Xray,
 * query per-user byte counters, and report deltas back to WordPress/MySQL.
 */
final class BlueVPN_Gateway {
    private const AUTH_WINDOW_SECONDS = 300;
    private const MAX_USAGE_EVENTS = 500;
    private const MAX_EVENT_BYTES = 1099511627776; // 1 TiB sanity cap per delta/event.

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

    public static function active_nodes(): array {
        global $wpdb;return $wpdb->get_results('SELECT * FROM '.self::nodes_table().' WHERE active=1 ORDER BY id ASC',ARRAY_A)?:[];
    }

    public static function node(int $id): ?array {
        if($id<=0)return null;global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::nodes_table().' WHERE id=%d LIMIT 1',$id),ARRAY_A);return is_array($row)?$row:null;
    }

    public static function plan_traffic_mode(int $planId): string {
        if($planId<=0)return 'provider_reported';global $wpdb;$mode=(string)$wpdb->get_var($wpdb->prepare('SELECT traffic_mode FROM '.self::plans_table().' WHERE id=%d LIMIT 1',$planId));return $mode==='gateway_metered'?'gateway_metered':'provider_reported';
    }

    public static function is_gateway_metered_customer(array $customer): bool { return self::plan_traffic_mode((int)($customer['plan_id']??0))==='gateway_metered'; }

    public static function has_active_gateway(): bool { global $wpdb;return (int)$wpdb->get_var('SELECT COUNT(*) FROM '.self::nodes_table().' WHERE active=1')>0; }

    public static function ensure_customer_sessions(int $customerId): array {
        if($customerId<=0)return [];global $wpdb;$ct=self::customers_table();$customer=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$ct} WHERE id=%d LIMIT 1",$customerId),ARRAY_A);if(!$customer||!self::is_gateway_metered_customer($customer))return [];
        $nodes=self::active_nodes();$st=self::sessions_table();$now=BlueVPN_Utils::now_mysql();$out=[];
        foreach($nodes as $node){$nodeId=(int)$node['id'];$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$st} WHERE node_id=%d AND customer_id=%d LIMIT 1",$nodeId,$customerId),ARRAY_A);
            if(!$row){$uuid=self::uuid_v4();$email='bv-'.$customerId.'-n'.$nodeId.'@gateway.bluevpn';$ok=$wpdb->insert($st,['node_id'=>$nodeId,'customer_id'=>$customerId,'client_uuid'=>$uuid,'client_email'=>$email,'status'=>'active','created_at'=>$now,'updated_at'=>$now]);if($ok!==false)$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$st} WHERE id=%d",(int)$wpdb->insert_id),ARRAY_A);}
            elseif((string)$row['status']!=='active'){$wpdb->update($st,['status'=>'active','updated_at'=>$now],['id'=>(int)$row['id']]);$row['status']='active';}
            if(is_array($row))$out[]=$row;
        }
        return $out;
    }

    public static function gateway_subscription_lines(array $customer): array {
        if(!self::is_gateway_metered_customer($customer))return [];
        if(!self::entitlement_allows($customer))return [];
        $sessions=self::ensure_customer_sessions((int)$customer['id']);if(!$sessions)return [];
        $nodes=[];foreach(self::active_nodes() as $n)$nodes[(int)$n['id']]=$n;$lines=[];
        foreach($sessions as $session){if((string)$session['status']!=='active')continue;$node=$nodes[(int)$session['node_id']]??null;if(!$node)continue;$host=trim((string)$node['public_host']);$port=max(1,min(65535,(int)$node['public_port']));$sni=trim((string)$node['server_name']);if($host===''||$sni==='')continue;
            $query=http_build_query(['encryption'=>'none','security'=>'tls','sni'=>$sni,'type'=>'tcp'],'','&',PHP_QUERY_RFC3986);$name=rawurlencode('BlueVPN Gateway • '.((string)$node['name']?:$host));$lines[]='vless://'.rawurlencode((string)$session['client_uuid']).'@'.$host.':'.$port.'?'.$query.'#'.$name;
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
        $node=self::node($nodeId);if(!$node||(int)$node['active']!==1)throw new RuntimeException('GATEWAY_NODE_DISABLED');$secret=self::node_secret($node);if($secret==='')throw new RuntimeException('GATEWAY_SECRET_MISSING');
        $body=(string)$r->get_body();$route=(string)$r->get_route();$message=$timestamp."\n".strtoupper((string)$r->get_method())."\n".$route."\n".hash('sha256',$body);$expected=hash_hmac('sha256',$message,$secret);if(!hash_equals($expected,$signature))throw new RuntimeException('GATEWAY_AUTH_INVALID');
        return $node;
    }

    private static function auth_fail(Throwable $e): WP_REST_Response {
        $code=$e->getMessage();$status=$code==='GATEWAY_NODE_DISABLED'?403:401;return self::ok(['ok'=>false,'detail'=>['code'=>$code,'message'=>'احراز هویت Gateway معتبر نیست.']],$status);
    }

    public static function rest_config(WP_REST_Request $r): WP_REST_Response {
        try{$node=self::auth_node($r);}catch(Throwable $e){return self::auth_fail($e);}global $wpdb;$nodeId=(int)$node['id'];$st=self::sessions_table();$ct=self::customers_table();$pt=self::plans_table();
        $rows=$wpdb->get_results($wpdb->prepare("SELECT s.*,c.plan_id,c.subscription_status,c.subscription_expire,c.data_limit_bytes,c.used_traffic_bytes,c.active,p.traffic_mode FROM {$st} s JOIN {$ct} c ON c.id=s.customer_id JOIN {$pt} p ON p.id=c.plan_id AND p.deleted=0 WHERE s.node_id=%d AND s.status='active' AND c.active=1 AND p.traffic_mode='gateway_metered' ORDER BY s.customer_id ASC LIMIT 1000",$nodeId),ARRAY_A)?:[];
        $sessions=[];$fingerprint=[];$now=BlueVPN_Utils::now_mysql();foreach($rows as $row){if(!self::entitlement_allows($row))continue;$pool=class_exists('BlueVPN_Providers')?BlueVPN_Providers::gateway_upstream_pool((int)$row['customer_id']):[];if(!$pool)continue;$item=['session_id'=>(int)$row['id'],'customer_id'=>(int)$row['customer_id'],'email'=>(string)$row['client_email'],'uuid'=>(string)$row['client_uuid'],'expires_at'=>(string)($row['subscription_expire']??''),'quota_bytes'=>(int)$row['data_limit_bytes'],'upstreams'=>array_values($pool)];$sessions[]=$item;$fingerprint[]=['id'=>$item['session_id'],'uuid'=>$item['uuid'],'expires'=>$item['expires_at'],'quota'=>$item['quota_bytes'],'pool'=>hash('sha256',implode("\n",$item['upstreams']))];}
        $hash=hash('sha256',wp_json_encode($fingerprint,JSON_UNESCAPED_SLASHES));$wpdb->update(self::nodes_table(),['last_seen_at'=>$now,'last_config_hash'=>$hash,'last_error'=>'','updated_at'=>$now],['id'=>$nodeId]);
        return self::ok(['ok'=>true,'schema'=>1,'mode'=>'gateway_metered','node'=>['id'=>$nodeId,'name'=>(string)$node['name'],'public_host'=>(string)$node['public_host'],'public_port'=>(int)$node['public_port'],'server_name'=>(string)$node['server_name'],'transport'=>(string)$node['transport']],'config_hash'=>$hash,'generated_at'=>BlueVPN_Utils::iso_now(),'sessions'=>$sessions]);
    }

    public static function rest_usage(WP_REST_Request $r): WP_REST_Response {
        try{$node=self::auth_node($r);}catch(Throwable $e){return self::auth_fail($e);}global $wpdb;$payload=$r->get_json_params();if(!is_array($payload))$payload=[];$events=$payload['events']??[];if(!is_array($events))$events=[];$events=array_slice($events,0,self::MAX_USAGE_EVENTS);$nodeId=(int)$node['id'];$st=self::sessions_table();$ut=self::usage_table();$ct=self::customers_table();$accepted=0;$duplicates=0;$rejected=0;$limited=[];$reload=false;
        $wpdb->query('START TRANSACTION');try{foreach($events as $event){if(!is_array($event)){$rejected++;continue;}$eventId=substr(sanitize_text_field((string)($event['event_id']??'')),0,120);$sessionId=max(0,(int)($event['session_id']??0));$seq=max(0,(int)($event['seq']??0));$up=min(self::MAX_EVENT_BYTES,max(0,(int)($event['uplink_bytes']??0)));$down=min(self::MAX_EVENT_BYTES,max(0,(int)($event['downlink_bytes']??0)));if($eventId===''||$sessionId<=0||($up+$down)<=0){$rejected++;continue;}
                $session=$wpdb->get_row($wpdb->prepare("SELECT s.*,c.data_limit_bytes,c.used_traffic_bytes,c.subscription_status FROM {$st} s JOIN {$ct} c ON c.id=s.customer_id WHERE s.id=%d AND s.node_id=%d LIMIT 1",$sessionId,$nodeId),ARRAY_A);if(!$session){$rejected++;continue;}
                $exists=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$ut} WHERE event_id=%s LIMIT 1",$eventId));if($exists>0){$duplicates++;continue;}
                $reportedRaw=(string)($event['reported_at']??'');$reported=$reportedRaw!==''?BlueVPN_Utils::mysql_from_iso($reportedRaw):null;if(!$reported)$reported=BlueVPN_Utils::now_mysql();$ok=$wpdb->insert($ut,['event_id'=>$eventId,'node_id'=>$nodeId,'session_id'=>$sessionId,'customer_id'=>(int)$session['customer_id'],'seq'=>$seq,'uplink_bytes'=>$up,'downlink_bytes'=>$down,'reported_at'=>$reported,'created_at'=>BlueVPN_Utils::now_mysql()]);if($ok===false){$dup=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$ut} WHERE event_id=%s LIMIT 1",$eventId));if($dup>0){$duplicates++;continue;}throw new RuntimeException('usage insert failed');}
                $delta=$up+$down;$wpdb->query($wpdb->prepare("UPDATE {$st} SET used_uplink_bytes=used_uplink_bytes+%d,used_downlink_bytes=used_downlink_bytes+%d,last_usage_at=%s,updated_at=%s WHERE id=%d",$up,$down,BlueVPN_Utils::now_mysql(),BlueVPN_Utils::now_mysql(),$sessionId));$wpdb->query($wpdb->prepare("UPDATE {$ct} SET used_traffic_bytes=used_traffic_bytes+%d,last_sync_at=%s WHERE id=%d",$delta,BlueVPN_Utils::now_mysql(),(int)$session['customer_id']));$newUsed=(int)$session['used_traffic_bytes']+$delta;$limit=(int)$session['data_limit_bytes'];if($limit>0&&$newUsed>=$limit){$wpdb->update($ct,['subscription_status'=>'limited','last_sync_error'=>'Gateway quota exhausted'],['id'=>(int)$session['customer_id']]);$wpdb->update($st,['status'=>'limited','updated_at'=>BlueVPN_Utils::now_mysql()],['customer_id'=>(int)$session['customer_id']]);$limited[]=(int)$session['customer_id'];$reload=true;}$accepted++;}
            $wpdb->query('COMMIT');
        }catch(Throwable $e){$wpdb->query('ROLLBACK');return self::ok(['ok'=>false,'detail'=>['code'=>'GATEWAY_USAGE_STORE_FAILED','message'=>$e->getMessage()]],500);}
        $now=BlueVPN_Utils::now_mysql();$wpdb->update(self::nodes_table(),['last_seen_at'=>$now,'last_error'=>'','updated_at'=>$now],['id'=>$nodeId]);return self::ok(['ok'=>true,'accepted'=>$accepted,'duplicates'=>$duplicates,'rejected'=>$rejected,'limited_customer_ids'=>array_values(array_unique($limited)),'reload_required'=>$reload,'server_time'=>time()]);
    }

    public static function rest_heartbeat(WP_REST_Request $r): WP_REST_Response {
        try{$node=self::auth_node($r);}catch(Throwable $e){return self::auth_fail($e);}global $wpdb;$body=$r->get_json_params();if(!is_array($body))$body=[];$data=['last_seen_at'=>BlueVPN_Utils::now_mysql(),'last_agent_version'=>substr(sanitize_text_field((string)($body['agent_version']??'')),0,64),'last_xray_version'=>substr(sanitize_text_field((string)($body['xray_version']??'')),0,64),'last_config_hash'=>substr(preg_replace('/[^a-f0-9]/i','',(string)($body['config_hash']??'')),0,64),'last_error'=>mb_substr(sanitize_textarea_field((string)($body['error']??'')),0,1800),'updated_at'=>BlueVPN_Utils::now_mysql()];$wpdb->update(self::nodes_table(),$data,['id'=>(int)$node['id']]);return self::ok(['ok'=>true,'server_time'=>time()]);
    }

    public static function save_node(): void {
        self::guard_admin();$id=max(0,(int)($_POST['node_id']??0));check_admin_referer('bluevpn_cc_save_gateway_node_'.$id);global $wpdb;$old=$id>0?self::node($id):null;$name=sanitize_text_field(wp_unslash($_POST['name']??''));$host=sanitize_text_field(wp_unslash($_POST['public_host']??''));$port=max(1,min(65535,(int)($_POST['public_port']??443)));$sni=sanitize_text_field(wp_unslash($_POST['server_name']??''));if($name===''||$host===''||$sni==='')self::redirect('نام، Host و TLS Server Name اجباری است.',true);$secret=$old?self::node_secret($old):'';if($secret==='')$secret=BlueVPN_Utils::random_token(36);$data=['name'=>$name,'public_host'=>$host,'public_port'=>$port,'server_name'=>$sni,'transport'=>'tcp','secret_enc'=>BlueVPN_Utils::encrypt_secret($secret),'secret_hash'=>hash('sha256',$secret),'active'=>isset($_POST['active'])?1:0,'updated_at'=>BlueVPN_Utils::now_mysql()];if($id>0)$ok=$wpdb->update(self::nodes_table(),$data,['id'=>$id]);else{$data['created_at']=BlueVPN_Utils::now_mysql();$ok=$wpdb->insert(self::nodes_table(),$data);$id=(int)$wpdb->insert_id;set_transient('bluevpn_gateway_secret_'.get_current_user_id(),['node_id'=>$id,'secret'=>$secret],300);}self::redirect($ok===false?'ذخیره Gateway ناموفق بود.':'Gateway ذخیره شد.',$ok===false);
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
        global $wpdb;$nodes=$wpdb->get_results('SELECT * FROM '.self::nodes_table().' ORDER BY active DESC,id ASC',ARRAY_A)?:[];$ct=self::customers_table();$pt=self::plans_table();$metered=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$ct} c JOIN {$pt} p ON p.id=c.plan_id WHERE c.active=1 AND p.traffic_mode='gateway_metered'");$used=(int)$wpdb->get_var("SELECT COALESCE(SUM(c.used_traffic_bytes),0) FROM {$ct} c JOIN {$pt} p ON p.id=c.plan_id WHERE p.traffic_mode='gateway_metered'");$secret=get_transient('bluevpn_gateway_secret_'.get_current_user_id());if($secret!==false)delete_transient('bluevpn_gateway_secret_'.get_current_user_id());
        echo '<div class="bvc-page-tools"><div><h2 class="bvc-section-title">BlueVPN Gateway Metering</h2><p class="bvc-section-subtitle">ترافیک پلن‌های Gateway از دیتاپلن خود BlueVPN عبور می‌کند؛ Provider دیگر مرجع حجم نیست.</p></div></div>';
        echo '<div class="bvc-grid"><div class="bvc-card bvc-kpi"><span>Gateway فعال</span><strong>'.count(array_filter($nodes,static fn($n)=>(int)$n['active']===1)).'</strong></div><div class="bvc-card bvc-kpi"><span>کاربر Metered</span><strong>'.number_format($metered).'</strong></div><div class="bvc-card bvc-kpi"><span>مصرف ثبت‌شده</span><strong>'.esc_html(self::fmt_bytes($used)).'</strong></div></div>';
        if(is_array($secret)&&!empty($secret['secret'])){echo '<div class="notice notice-warning"><p><strong>Secret فقط همین یک بار نمایش داده می‌شود.</strong></p><div class="bvc-code">NODE_ID='.(int)$secret['node_id'].'<br>NODE_SECRET='.esc_html((string)$secret['secret']).'</div></div>';}
        echo '<details class="bvc-card bvc-disclosure" '.(!$nodes?'open':'').'><summary><span><strong>افزودن Gateway</strong><small>دامنه‌ای که TLS معتبر روی سرور Gateway دارد</small></span><span>⌄</span></summary><div class="bvc-disclosure-body"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_save_gateway_node_0');echo '<input type="hidden" name="action" value="bluevpn_cc_save_gateway_node"><input type="hidden" name="node_id" value="0"><div class="bvc-form-grid"><label>نام<input name="name" required placeholder="Gateway Paris"></label><label>Public Host<input name="public_host" required placeholder="gw1.example.com"></label><label>Port<input type="number" name="public_port" value="443" min="1" max="65535"></label><label>TLS Server Name<input name="server_name" required placeholder="gw1.example.com"></label></div><label><input type="checkbox" name="active" value="1" checked> فعال</label><div class="bvc-form-actions"><button class="button button-primary">ساخت Gateway</button></div></form></div></details>';
        if(!$nodes){echo '<div class="bvc-empty-state"><strong>Gateway ثبت نشده است.</strong><span>بعد از ساخت Node، Secret را داخل agent.json سرور قرار بده.</span></div>';return;}
        echo '<div class="bvc-plan-list">';foreach($nodes as $node){$id=(int)$node['id'];$toggle=wp_nonce_url(admin_url('admin-post.php?action=bluevpn_cc_toggle_gateway_node&id='.$id),'bluevpn_cc_toggle_gateway_node_'.$id);$last=!empty($node['last_seen_at'])?strtotime((string)$node['last_seen_at'].' UTC'):0;$online=$last>0&&(time()-$last)<180;echo '<article class="bvc-plan-card '.((int)$node['active']?'is-active':'is-inactive').'"><header class="bvc-plan-head"><div><h3>'.esc_html((string)$node['name']).'</h3><p>'.esc_html((string)$node['public_host']).':'.(int)$node['public_port'].' • TLS '.esc_html((string)$node['server_name']).'</p></div><span class="bvc-status-pill '.($online?'is-active':'is-inactive').'">'.($online?'آنلاین':'آفلاین').'</span></header><div class="bvc-plan-metrics"><div><span>آخرین Heartbeat</span><strong>'.($last?esc_html(BlueVPN_Utils::tehran_datetime_fa((string)$node['last_seen_at'])):'—').'</strong></div><div><span>Agent</span><strong>'.esc_html((string)($node['last_agent_version']?:'—')).'</strong></div><div><span>Xray</span><strong>'.esc_html((string)($node['last_xray_version']?:'—')).'</strong></div></div><div class="bvc-actions"><a class="button" href="'.esc_url($toggle).'">'.((int)$node['active']?'غیرفعال':'فعال').' کردن</a></div><details class="bvc-plan-routing"><summary>تنظیمات Node</summary><div class="bvc-plan-routing-body"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_save_gateway_node_'.$id);echo '<input type="hidden" name="action" value="bluevpn_cc_save_gateway_node"><input type="hidden" name="node_id" value="'.$id.'"><div class="bvc-form-grid"><label>نام<input name="name" value="'.esc_attr((string)$node['name']).'" required></label><label>Public Host<input name="public_host" value="'.esc_attr((string)$node['public_host']).'" required></label><label>Port<input type="number" name="public_port" value="'.(int)$node['public_port'].'"></label><label>TLS Server Name<input name="server_name" value="'.esc_attr((string)$node['server_name']).'" required></label></div><label><input type="checkbox" name="active" value="1" '.checked((int)$node['active'],1,false).'> فعال</label><button class="button button-primary">ذخیره</button></form><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block;margin-top:10px">';wp_nonce_field('bluevpn_cc_rotate_gateway_secret_'.$id);echo '<input type="hidden" name="action" value="bluevpn_cc_rotate_gateway_secret"><input type="hidden" name="node_id" value="'.$id.'"><button class="button">چرخش Secret</button></form><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block;margin:10px">';wp_nonce_field('bluevpn_cc_delete_gateway_node_'.$id);echo '<input type="hidden" name="action" value="bluevpn_cc_delete_gateway_node"><input type="hidden" name="node_id" value="'.$id.'"><button class="button button-link-delete" onclick="return confirm(\'Gateway حذف شود؟\')">حذف</button></form></div></details></article>';}
        echo '</div><div class="bvc-card"><h3>Agent Endpoint</h3><div class="bvc-code">'.esc_html(rest_url('bluevpn-gateway/v1/config')).'</div><p class="description">Agent استاندارد همراه پروژه در پوشه <code>bluevpn-gateway/</code> قرار دارد. Secret را فقط روی خود سرور Gateway نگهداری کن.</p></div>';
    }

    private static function fmt_bytes(int $bytes): string { $n=max(0,(float)$bytes);foreach(['B','KB','MB','GB','TB'] as $u){if($n<1024||$u==='TB')return number_format($n,$n<10&&$u!=='B'?2:0).' '.$u;$n/=1024;}return '0 B'; }
}
