<?php
if (!defined('ABSPATH')) exit;

/**
 * First-party paid subscription sources which are not tied to a provider API.
 * URL tokens and inline configs are encrypted at rest and are never returned to apps.
 */
final class BlueVPN_Subscription_Sources {
    public static function init(): void {
        add_action('admin_post_bluevpn_cc_save_subscription_source',[self::class,'save']);
        add_action('admin_post_bluevpn_cc_toggle_subscription_source',[self::class,'toggle']);
        add_action('admin_post_bluevpn_cc_delete_subscription_source',[self::class,'delete']);
        add_action('admin_post_bluevpn_cc_test_subscription_source',[self::class,'test']);
    }

    private static function table(): string { return BlueVPN_DB::table('subscription_sources'); }
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
        $out=[];foreach($rows as $row){$payload=self::plaintext($row);if(trim($payload)==='')continue;$out[]=['key'=>'manual:'.(int)$row['id'],'id'=>(int)$row['id'],'name'=>(string)$row['name'],'type'=>(string)$row['source_type'],'payload'=>$payload];}
        return $out;
    }

    public static function parse_lines(string $text): array {
        $text=trim($text);if($text==='')return [];
        $decoded=base64_decode(preg_replace('/\s+/','',$text),true);
        if($decoded!==false&&preg_match('~(?:vless|vmess|trojan|ss|hysteria2|tuic)://~i',$decoded))$text=$decoded;
        $lines=preg_split('/\R+/',trim($text))?:[];$out=[];$seen=[];
        foreach($lines as $line){$line=trim($line);if(!preg_match('~^(?:vless|vmess|trojan|ss|hysteria2|tuic)://~i',$line))continue;$key=sha1($line);if(isset($seen[$key]))continue;$seen[$key]=1;$out[]=$line;}
        return $out;
    }

    private static function validate_payload(string $type,string $payload): array {
        $payload=trim($payload);if($payload==='')return ['ok'=>false,'message'=>'مقدار Source خالی است.','count'=>0];
        if($type==='inline'){$lines=self::parse_lines($payload);return ['ok'=>!empty($lines),'message'=>$lines?count($lines).' کانفیگ معتبر پیدا شد.':'هیچ کانفیگ پشتیبانی‌شده‌ای پیدا نشد.','count'=>count($lines)];}
        if(!wp_http_validate_url($payload))return ['ok'=>false,'message'=>'URL معتبر نیست.','count'=>0];
        $r=wp_remote_get($payload,['timeout'=>10,'redirection'=>2,'sslverify'=>true,'headers'=>['User-Agent'=>'BlueVPN-Source-Test/'.BLUEVPN_MANAGER_VERSION,'Accept'=>'text/plain,*/*']]);
        if(is_wp_error($r))return ['ok'=>false,'message'=>$r->get_error_message(),'count'=>0];
        $code=(int)wp_remote_retrieve_response_code($r);if($code>=400)return ['ok'=>false,'message'=>'HTTP '.$code,'count'=>0];
        $lines=self::parse_lines((string)wp_remote_retrieve_body($r));return ['ok'=>!empty($lines),'message'=>$lines?count($lines).' کانفیگ معتبر دریافت شد.':'پاسخ Source کانفیگ قابل استفاده ندارد.','count'=>count($lines)];
    }

    public static function save(): void {
        self::guard();$id=max(0,(int)($_POST['source_id']??0));check_admin_referer('bluevpn_cc_save_subscription_source_'.$id);
        global $wpdb;$old=$id>0?self::source($id):null;$name=sanitize_text_field(wp_unslash($_POST['name']??''));$type=sanitize_key((string)($_POST['source_type']??'url'));if(!in_array($type,['url','inline'],true))$type='url';
        if($name==='')self::redirect('نام Source نمی‌تواند خالی باشد.',true);
        $raw=trim((string)wp_unslash($_POST['payload']??''));$payloadEnc=$raw!==''?BlueVPN_Utils::encrypt_secret($raw):(string)($old['payload_enc']??'');if($payloadEnc==='')self::redirect('URL یا کانفیگ Source را وارد کن.',true);
        $data=['name'=>$name,'source_type'=>$type,'payload_enc'=>$payloadEnc,'active'=>isset($_POST['active'])?1:0,'updated_at'=>BlueVPN_Utils::now_mysql()];
        if($id>0){$ok=$wpdb->update(self::table(),$data,['id'=>$id]);}
        else{$data['created_at']=BlueVPN_Utils::now_mysql();$ok=$wpdb->insert(self::table(),$data);$id=(int)$wpdb->insert_id;}
        self::redirect($ok===false?'ذخیره Source ناموفق بود.':'Source ذخیره شد.',$ok===false);
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
        $rows=self::rows(true);$selected=array_map('intval',$selected);
        echo '<div class="bvc-card" style="margin-top:10px"><strong>ساب‌ها و کانفیگ‌های دستی</strong><p class="description">این Sourceها فقط سمت سرور نگهداری می‌شوند و در حالت Gateway به اپ کاربر لو نمی‌روند.</p>';
        if(!$rows){echo '<p class="description">Source دستی فعالی وجود ندارد. از بخش «Sourceهای اشتراک» اضافه کن.</p></div>';return;}
        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px">';foreach($rows as $row){$id=(int)$row['id'];echo '<label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="source_ids_selected[]" value="'.$id.'" '.checked(in_array($id,$selected,true),true,false).'> '.esc_html((string)$row['name']).' <small>('.esc_html((string)$row['source_type']).')</small></label>';}
        echo '</div></div>';
    }

    public static function render_admin_tab(): void {
        $rows=self::rows(false);
        echo '<div class="bvc-page-tools"><div><h2 class="bvc-section-title">Sourceهای اشتراک پولی</h2><p class="bvc-section-subtitle">ساب URL یا کانفیگ دستی را رمزنگاری‌شده ذخیره کن و به هر پلن وصل کن.</p></div></div>';
        echo '<details class="bvc-card bvc-disclosure" '.(!$rows?'open':'').'><summary><span><strong>افزودن Source</strong><small>URL یا متن کانفیگ</small></span><span>⌄</span></summary><div class="bvc-disclosure-body"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_save_subscription_source_0');echo '<input type="hidden" name="action" value="bluevpn_cc_save_subscription_source"><input type="hidden" name="source_id" value="0"><div class="bvc-form-grid"><label>نام<input name="name" required></label><label>نوع<select name="source_type"><option value="url">Subscription URL</option><option value="inline">Inline configs</option></select></label></div><label style="display:block;margin-top:10px">URL / Configs<textarea name="payload" rows="7" style="width:100%" required></textarea></label><label><input type="checkbox" name="active" value="1" checked> فعال</label><div class="bvc-form-actions"><button class="button button-primary">ذخیره Source</button></div></form></div></details>';
        if(!$rows){echo '<div class="bvc-empty-state"><strong>هنوز Source دستی ثبت نشده است.</strong></div>';return;}
        echo '<div class="bvc-plan-list">';foreach($rows as $row){$id=(int)$row['id'];$toggle=wp_nonce_url(admin_url('admin-post.php?action=bluevpn_cc_toggle_subscription_source&id='.$id),'bluevpn_cc_toggle_subscription_source_'.$id);$test=wp_nonce_url(admin_url('admin-post.php?action=bluevpn_cc_test_subscription_source&id='.$id),'bluevpn_cc_test_subscription_source_'.$id);echo '<article class="bvc-plan-card '.((int)$row['active']?'is-active':'is-inactive').'"><header class="bvc-plan-head"><div><h3>'.esc_html((string)$row['name']).'</h3><p>'.esc_html(strtoupper((string)$row['source_type'])).' • Payload encrypted at rest</p></div><span class="bvc-status-pill '.((int)$row['active']?'is-active':'is-inactive').'">'.((int)$row['active']?'فعال':'غیرفعال').'</span></header><div class="bvc-plan-metrics"><div><span>آخرین تست</span><strong>'.(!empty($row['last_test_at'])?esc_html(BlueVPN_Utils::tehran_datetime_fa((string)$row['last_test_at'])):'—').'</strong></div><div><span>نتیجه</span><strong>'.((int)$row['last_test_ok']?'سالم':'نیاز به تست').'</strong></div></div><div class="bvc-actions"><a class="button" href="'.esc_url($test).'">تست</a><a class="button" href="'.esc_url($toggle).'">'.((int)$row['active']?'غیرفعال':'فعال').' کردن</a></div><details class="bvc-plan-routing"><summary>ویرایش</summary><div class="bvc-plan-routing-body"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('bluevpn_cc_save_subscription_source_'.$id);echo '<input type="hidden" name="action" value="bluevpn_cc_save_subscription_source"><input type="hidden" name="source_id" value="'.$id.'"><div class="bvc-form-grid"><label>نام<input name="name" value="'.esc_attr((string)$row['name']).'" required></label><label>نوع<select name="source_type"><option value="url" '.selected((string)$row['source_type'],'url',false).'>Subscription URL</option><option value="inline" '.selected((string)$row['source_type'],'inline',false).'>Inline configs</option></select></label></div><label style="display:block;margin-top:10px">Payload جدید (خالی = بدون تغییر)<textarea name="payload" rows="6" style="width:100%"></textarea></label><label><input type="checkbox" name="active" value="1" '.checked((int)$row['active'],1,false).'> فعال</label><button class="button button-primary">ذخیره</button></form><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin-top:10px">';wp_nonce_field('bluevpn_cc_delete_subscription_source_'.$id);echo '<input type="hidden" name="action" value="bluevpn_cc_delete_subscription_source"><input type="hidden" name="source_id" value="'.$id.'"><button class="button button-link-delete" onclick="return confirm(\'حذف شود؟\')">حذف</button></form></div></details></article>';}
        echo '</div>';
    }
}
