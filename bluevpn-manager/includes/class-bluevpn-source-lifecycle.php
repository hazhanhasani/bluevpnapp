<?php
if (!defined('ABSPATH')) exit;

final class BlueVPN_Source_Lifecycle {
    private const MIGRATION_OPTION='bluevpn_source_lifecycle_60303_reconciled';
    private const LOCK_KEY='bluevpn_source_lifecycle_60303_lock';

    public static function init(): void {
        add_action('init',[self::class,'maybe_repair_existing_state'],1);
        add_action('bluevpn_manager_cleanup',[self::class,'reconcile_free_pool'],5);
        add_action('admin_post_bluevpn_cc_sync_customer',[self::class,'before_customer_sync'],1);
        add_action('admin_post_bluevpn_cc_save_plan_routing',[self::class,'before_plan_save'],1);
        add_action('admin_post_bluevpn_cc_save_plan',[self::class,'before_plan_save'],1);
        add_action('admin_post_bluevpn_cc_toggle_subscription_source',[self::class,'before_paid_source_mutation'],1);
        add_action('admin_post_bluevpn_cc_delete_subscription_source',[self::class,'before_paid_source_mutation'],1);
        add_action('admin_post_bluevpn_cc_save_subscription_source',[self::class,'before_paid_source_mutation'],1);
        add_action('admin_post_bluevpn_shahrah_delete',[self::class,'before_shahrah_delete'],1);
        add_action('admin_post_bluevpn_shahrah_toggle',[self::class,'before_shahrah_toggle'],1);
        add_action('admin_post_bluevpn_cc_delete_provider',[self::class,'before_provider_mutation'],1);
        add_action('admin_post_bluevpn_cc_toggle_provider',[self::class,'before_provider_mutation'],1);
        add_action('admin_post_bluevpn_free_source_toggle',[self::class,'before_free_toggle'],1);
        add_action('admin_post_bluevpn_free_source_delete',[self::class,'free_delete'],1);
    }

    private static function admin(): bool { return current_user_can('manage_options'); }
    private static function snapshot_option(int $customerId): string { return 'bluevpn_sub_snapshot_'.$customerId; }

    private static function invalidate_customer(int $customerId): void {
        if($customerId<=0)return;delete_option(self::snapshot_option($customerId));wp_clear_scheduled_hook('bluevpn_refresh_subscription_snapshot',[$customerId]);
        global $wpdb;$wpdb->update(BlueVPN_DB::table('customers'),['last_sync_at'=>null,'last_sync_error'=>''],['id'=>$customerId]);
    }

    private static function invalidate_plan(int $planId): void {
        if($planId<=0)return;global $wpdb;$ct=BlueVPN_DB::table('customers');
        $ids=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT id FROM {$ct} WHERE plan_id=%d",$planId))?:[]);
        foreach($ids as $id){delete_option(self::snapshot_option($id));wp_clear_scheduled_hook('bluevpn_refresh_subscription_snapshot',[$id]);}
        $wpdb->query($wpdb->prepare("UPDATE {$ct} SET last_sync_at=NULL,last_sync_error='' WHERE plan_id=%d",$planId));
    }

    private static function invalidate_all(): void {
        global $wpdb;$like=$wpdb->esc_like('bluevpn_sub_snapshot_').'%';
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",$like));
        $wpdb->query("UPDATE ".BlueVPN_DB::table('customers')." SET last_sync_at=NULL,last_sync_error='' WHERE plan_id IS NOT NULL");
    }

    private static function route_key(string $provider,array $route): string {
        $key=$provider.':'.max(0,(int)($route['panel_id']??0));
        if($provider==='shahrah')$key.=':'.trim((string)($route['plan_slug']??''));
        return mb_substr($key,0,190);
    }

    private static function plan_state(int $planId): array {
        if($planId<=0)return ['declared'=>false,'desired'=>[],'providers'=>[]];
        global $wpdb;$pt=BlueVPN_DB::table('plans');$plan=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$pt} WHERE id=%d AND deleted=0 LIMIT 1",$planId),ARRAY_A);
        if(!$plan)return ['declared'=>false,'desired'=>[],'providers'=>[]];
        $declared=trim((string)($plan['provider_routes_json']??''))!==''||trim((string)($plan['source_ids_json']??''))!=='';
        $desired=[];$providers=[];
        foreach(BlueVPN_Providers::plan_provider_routes($plan) as $provider=>$routes)foreach((array)$routes as $route){$desired[self::route_key((string)$provider,(array)$route)]=true;$providers[(string)$provider]=true;}
        return ['declared'=>$declared,'desired'=>$desired,'providers'=>$providers];
    }

    public static function reconcile_plan(int $planId): void {
        if($planId<=0||!class_exists('BlueVPN_Providers'))return;
        global $wpdb;$ct=BlueVPN_DB::table('customers');$lt=BlueVPN_DB::table('customer_provider_links');$state=self::plan_state($planId);
        $links=$wpdb->get_results($wpdb->prepare("SELECT l.id,l.route_key FROM {$lt} l INNER JOIN {$ct} c ON c.id=l.customer_id WHERE c.plan_id=%d",$planId),ARRAY_A)?:[];
        foreach($links as $link)if(empty($state['desired'][(string)$link['route_key']]))$wpdb->delete($lt,['id'=>(int)$link['id']],['%d']);
        if(!empty($state['declared'])){
            if(empty($state['providers']['pasarguard']))$wpdb->query($wpdb->prepare("UPDATE {$ct} SET panel_id=NULL,pg_user_id=NULL,pg_username='',pasarguard_subscription_url='' WHERE plan_id=%d",$planId));
            if(empty($state['providers']['marzban']))$wpdb->query($wpdb->prepare("UPDATE {$ct} SET marzban_panel_id=NULL,marzban_user_id=NULL,marzban_username='',marzban_subscription_url='',marzban_status='inactive',marzban_last_error='' WHERE plan_id=%d",$planId));
            if(empty($state['providers']['guardcore']))$wpdb->query($wpdb->prepare("UPDATE {$ct} SET guardcore_panel_id=NULL,guardcore_subscription_id=NULL,guardcore_username='',guardcore_subscription_url='',guardcore_status='inactive',guardcore_last_error='' WHERE plan_id=%d",$planId));
        }
        self::invalidate_plan($planId);
    }

    private static function source_plan_ids(int $sourceId): array {
        if($sourceId<=0)return [];global $wpdb;$pt=BlueVPN_DB::table('plans');$out=[];
        foreach($wpdb->get_results("SELECT id,source_ids_json FROM {$pt} WHERE deleted=0",ARRAY_A)?:[] as $plan){
            $ids=BlueVPN_Utils::json_decode_array((string)($plan['source_ids_json']??''),[]);
            if(in_array($sourceId,array_map('intval',$ids),true))$out[]=(int)$plan['id'];
        }
        return array_values(array_unique($out));
    }

    private static function provider_plan_ids(string $provider,int $panelId): array {
        if($panelId<=0)return [];global $wpdb;$pt=BlueVPN_DB::table('plans');$out=[];
        foreach($wpdb->get_results("SELECT * FROM {$pt} WHERE deleted=0",ARRAY_A)?:[] as $plan){
            foreach(BlueVPN_Providers::plan_provider_routes($plan) as $p=>$routes)if($p===$provider)foreach((array)$routes as $route)if((int)($route['panel_id']??0)===$panelId){$out[]=(int)$plan['id'];break 2;}
        }
        return array_values(array_unique($out));
    }

    public static function before_customer_sync(): void {
        if(!self::admin())return;$id=(int)($_GET['customer_id']??0);check_admin_referer('bluevpn_cc_sync_customer_'.$id);
        global $wpdb;$planId=(int)$wpdb->get_var($wpdb->prepare("SELECT plan_id FROM ".BlueVPN_DB::table('customers')." WHERE id=%d",$id));
        if($planId>0)self::reconcile_plan($planId);
        try{BlueVPN_Providers::repair_customer_missing_providers($id);}catch(Throwable $e){}
        self::invalidate_customer($id);
    }

    public static function before_plan_save(): void {
        if(!self::admin())return;$id=(int)($_POST['plan_id']??0);$action=current_action();
        check_admin_referer($action==='admin_post_bluevpn_cc_save_plan'?'bluevpn_cc_save_plan_'.$id:'bluevpn_cc_save_plan_routing_'.$id);
        register_shutdown_function([self::class,'reconcile_plan'],$id);
    }

    public static function before_paid_source_mutation(): void {
        if(!self::admin())return;$action=current_action();$id=$action==='admin_post_bluevpn_cc_save_subscription_source'?(int)($_POST['source_id']??0):($action==='admin_post_bluevpn_cc_delete_subscription_source'?(int)($_POST['source_id']??0):(int)($_GET['id']??0));
        $nonce=$action==='admin_post_bluevpn_cc_save_subscription_source'?'bluevpn_cc_save_subscription_source_'.$id:($action==='admin_post_bluevpn_cc_delete_subscription_source'?'bluevpn_cc_delete_subscription_source_'.$id:'bluevpn_cc_toggle_subscription_source_'.$id);
        check_admin_referer($nonce);$plans=self::source_plan_ids($id);
        if($id>0&&class_exists('BlueVPN_Subscription_Sources')){$row=BlueVPN_Subscription_Sources::source($id);if($row&&(string)($row['source_type']??'')==='url'){$url=BlueVPN_Subscription_Sources::plaintext($row);delete_transient('bluevpn_subsrc_'.substr(hash('sha256',trim($url)),0,40));}}
        foreach($plans as $planId)self::invalidate_plan($planId);
        register_shutdown_function(static function()use($plans){foreach($plans as $planId)self::reconcile_plan($planId);});
    }

    public static function before_free_toggle(): void {
        if(!self::admin())return;$id=(int)($_POST['source_id']??0);check_admin_referer('bluevpn_free_source_toggle_'.$id);
        global $wpdb;$st=BlueVPN_DB::table('free_config_sources');$enabled=(int)$wpdb->get_var($wpdb->prepare("SELECT enabled FROM {$st} WHERE id=%d",$id));
        if($enabled)$wpdb->update(BlueVPN_DB::table('free_configs'),['active'=>0],['source_id'=>$id]);
        self::reconcile_free_pool();
    }

    public static function free_delete(): void {
        if(!self::admin())wp_die('دسترسی ندارید.');$id=(int)($_POST['source_id']??0);check_admin_referer('bluevpn_free_source_delete_'.$id);
        global $wpdb;$wpdb->update(BlueVPN_DB::table('free_configs'),['active'=>0],['source_id'=>$id]);$wpdb->delete(BlueVPN_DB::table('free_config_sources'),['id'=>$id],['%d']);
        update_option('bluevpn_free_sources_initialized','1',false);self::reconcile_free_pool();wp_safe_redirect(admin_url('admin.php?page=bluevpn-free-access'));exit;
    }

    public static function before_shahrah_toggle(): void {
        if(!self::admin())return;$id=(int)($_GET['id']??0);check_admin_referer('bluevpn_shahrah_toggle_'.$id);delete_transient('bluevpn_shahrah_circuit_state');
        foreach(self::provider_plan_ids('shahrah',$id) as $planId)self::invalidate_plan($planId);
    }

    public static function before_shahrah_delete(): void {
        if(!self::admin())return;$id=(int)($_GET['id']??0);check_admin_referer('bluevpn_shahrah_delete_'.$id);global $wpdb;$pt=BlueVPN_DB::table('plans');$plans=self::provider_plan_ids('shahrah',$id);
        foreach($wpdb->get_results("SELECT id,provider_routes_json FROM {$pt} WHERE deleted=0",ARRAY_A)?:[] as $plan){
            $routes=BlueVPN_Utils::json_decode_array((string)($plan['provider_routes_json']??''),[]);if(!isset($routes['shahrah'])||!is_array($routes['shahrah']))continue;
            $next=array_values(array_filter($routes['shahrah'],static fn($route)=>!is_array($route)||(int)($route['panel_id']??0)!==$id));if($next!==$routes['shahrah']){$routes['shahrah']=$next;$wpdb->update($pt,['provider_routes_json'=>BlueVPN_Utils::json_encode($routes)],['id'=>(int)$plan['id']]);}
        }
        $lt=BlueVPN_DB::table('customer_provider_links');$ids=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT DISTINCT customer_id FROM {$lt} WHERE provider_type='shahrah' AND panel_id=%d",$id))?:[]);
        $wpdb->query($wpdb->prepare("DELETE FROM {$lt} WHERE provider_type='shahrah' AND panel_id=%d",$id));$prefix=$wpdb->esc_like('bluevpn_shahrah_panel_'.$id.'_').'%';$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",$prefix));delete_transient('bluevpn_shahrah_circuit_state');
        foreach($ids as $customerId)self::invalidate_customer($customerId);register_shutdown_function(static function()use($plans){foreach($plans as $planId)self::reconcile_plan($planId);});
    }

    public static function before_provider_mutation(): void {
        if(!self::admin())return;$provider=sanitize_key((string)($_GET['provider']??''));$id=(int)($_GET['id']??0);$action=current_action();
        check_admin_referer(($action==='admin_post_bluevpn_cc_delete_provider'?'bluevpn_cc_delete_provider_':'bluevpn_cc_toggle_provider_').$provider.'_'.$id);
        $plans=self::provider_plan_ids($provider,$id);foreach($plans as $planId)self::invalidate_plan($planId);
        register_shutdown_function(static function()use($plans){foreach($plans as $planId)self::reconcile_plan($planId);});
    }

    public static function reconcile_free_pool(): void {
        global $wpdb;$ct=BlueVPN_DB::table('free_configs');$st=BlueVPN_DB::table('free_config_sources');
        $wpdb->query("UPDATE {$ct} c LEFT JOIN {$st} s ON s.id=c.source_id SET c.active=0 WHERE s.id IS NULL OR s.enabled<>1");
    }

    private static function prune_missing_panel_routes(): void {
        global $wpdb;$map=['pasarguard'=>'pasarguard_panels','marzban'=>'marzban_panels','shahrah'=>'shahrah_panels','guardcore'=>'guardcore_panels','hiddify'=>'hiddify_panels','threexui'=>'threexui_panels'];$valid=[];
        foreach($map as $provider=>$table)$valid[$provider]=array_fill_keys(array_map('intval',$wpdb->get_col("SELECT id FROM ".BlueVPN_DB::table($table))?:[]),true);
        $pt=BlueVPN_DB::table('plans');foreach($wpdb->get_results("SELECT id,provider_routes_json FROM {$pt} WHERE deleted=0",ARRAY_A)?:[] as $plan){
            $routes=BlueVPN_Utils::json_decode_array((string)($plan['provider_routes_json']??''),[]);$changed=false;
            foreach($map as $provider=>$unused){if(!isset($routes[$provider])||!is_array($routes[$provider]))continue;$next=array_values(array_filter($routes[$provider],static fn($route)=>is_array($route)&&isset($valid[$provider][(int)($route['panel_id']??0)])));if($next!==$routes[$provider]){$routes[$provider]=$next;$changed=true;}}
            if($changed)$wpdb->update($pt,['provider_routes_json'=>BlueVPN_Utils::json_encode($routes)],['id'=>(int)$plan['id']]);
        }
        $lt=BlueVPN_DB::table('customer_provider_links');foreach($wpdb->get_results("SELECT id,provider_type,panel_id FROM {$lt}",ARRAY_A)?:[] as $link){$p=(string)$link['provider_type'];if(isset($valid[$p])&&!isset($valid[$p][(int)$link['panel_id']]))$wpdb->delete($lt,['id'=>(int)$link['id']],['%d']);}
        $prefix=$wpdb->esc_like('bluevpn_shahrah_panel_').'%';foreach($wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",$prefix))?:[] as $name)if(preg_match('/^bluevpn_shahrah_panel_(\d+)_\d+$/',(string)$name,$m)&&!isset($valid['shahrah'][(int)$m[1]]))delete_option((string)$name);
    }

    public static function maybe_repair_existing_state(): void {
        if(get_option(self::MIGRATION_OPTION,'0')==='1'||get_transient(self::LOCK_KEY))return;set_transient(self::LOCK_KEY,'1',5*MINUTE_IN_SECONDS);
        try{update_option('bluevpn_free_sources_initialized','1',false);self::reconcile_free_pool();self::prune_missing_panel_routes();global $wpdb;foreach(array_map('intval',$wpdb->get_col("SELECT id FROM ".BlueVPN_DB::table('plans')." WHERE deleted=0")?:[]) as $planId)self::reconcile_plan($planId);self::invalidate_all();delete_transient('bluevpn_shahrah_circuit_state');update_option(self::MIGRATION_OPTION,'1',false);}
        catch(Throwable $e){if(class_exists('BlueVPN_Error_Monitor'))BlueVPN_Error_Monitor::legacy_error_log('BlueVPN source lifecycle 6.3.3 repair: '.$e->getMessage());}
        finally{delete_transient(self::LOCK_KEY);}
    }
}
