<?php
if (!defined('ABSPATH')) exit;

final class BlueVPN_Support {
    private const SCHEMA = '1.1.0';

    public static function init(): void {
        self::maybe_upgrade();
        add_action('admin_menu', [self::class, 'admin_menu'], 40);
        add_action('admin_post_bluevpn_support_reply', [self::class, 'admin_reply']);
        add_action('admin_post_bluevpn_support_assign', [self::class, 'admin_assign']);
        add_action('admin_post_bluevpn_support_status', [self::class, 'admin_status']);
        add_action('admin_post_bluevpn_support_department_save', [self::class, 'admin_department_save']);
        add_action('admin_post_bluevpn_support_operator_save', [self::class, 'admin_operator_save']);
        add_action('admin_post_bluevpn_support_note', [self::class, 'admin_note']);
        add_action('admin_post_bluevpn_support_canned_save', [self::class, 'admin_canned_save']);
        add_action('admin_post_bluevpn_support_operator_presence', [self::class, 'admin_operator_presence']);
        add_action('admin_post_bluevpn_support_attachment', [self::class, 'admin_attachment']);
    }

    public static function activate(): void {
        self::install_schema();
        self::seed_defaults();
    }

    private static function table(string $name): string {
        return BlueVPN_DB::table('support_' . $name);
    }

    private static function install_schema(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $cc = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE " . self::table('departments') . " (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            name varchar(120) NOT NULL,
            slug varchar(80) NOT NULL,
            description varchar(255) NOT NULL DEFAULT '',
            active tinyint(1) NOT NULL DEFAULT 1,
            sort_order int NOT NULL DEFAULT 0,
            first_response_minutes int NOT NULL DEFAULT 30,
            resolution_minutes int NOT NULL DEFAULT 1440,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_support_department_slug (slug),
            KEY ix_support_department_active (active,sort_order)
        ) $cc;");

        dbDelta("CREATE TABLE " . self::table('operators') . " (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            wp_user_id bigint unsigned NOT NULL DEFAULT 0,
            display_name varchar(120) NOT NULL,
            department_ids varchar(255) NOT NULL DEFAULT '',
            online tinyint(1) NOT NULL DEFAULT 1,
            max_active int NOT NULL DEFAULT 20,
            last_seen_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY ix_support_operator_wp (wp_user_id),
            KEY ix_support_operator_online (online)
        ) $cc;");

        dbDelta("CREATE TABLE " . self::table('conversations') . " (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            public_id varchar(40) NOT NULL,
            customer_id bigint unsigned NOT NULL,
            department_id bigint unsigned NOT NULL,
            operator_id bigint unsigned NOT NULL DEFAULT 0,
            subject varchar(180) NOT NULL DEFAULT '',
            status varchar(24) NOT NULL DEFAULT 'waiting',
            priority varchar(16) NOT NULL DEFAULT 'normal',
            source varchar(16) NOT NULL DEFAULT 'android',
            unread_customer int NOT NULL DEFAULT 0,
            unread_operator int NOT NULL DEFAULT 0,
            last_message_at datetime NOT NULL,
            first_response_due_at datetime NULL,
            resolution_due_at datetime NULL,
            first_response_at datetime NULL,
            resolved_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            closed_at datetime NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_support_public_id (public_id),
            KEY ix_support_customer (customer_id,status,last_message_at),
            KEY ix_support_queue (department_id,status,priority,last_message_at),
            KEY ix_support_operator (operator_id,status,last_message_at),
            KEY ix_support_sla (status,first_response_due_at,resolution_due_at)
        ) $cc;");

        dbDelta("CREATE TABLE " . self::table('messages') . " (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            conversation_id bigint unsigned NOT NULL,
            sender_type varchar(16) NOT NULL,
            sender_id bigint unsigned NOT NULL DEFAULT 0,
            body text NOT NULL,
            message_type varchar(16) NOT NULL DEFAULT 'text',
            telegram_chat_id varchar(40) NOT NULL DEFAULT '',
            telegram_message_id varchar(40) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            seen_at datetime NULL,
            PRIMARY KEY (id),
            KEY ix_support_messages (conversation_id,id),
            KEY ix_support_unseen (conversation_id,sender_type,seen_at)
        ) $cc;");

        dbDelta("CREATE TABLE " . self::table('events') . " (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            conversation_id bigint unsigned NOT NULL,
            actor_type varchar(16) NOT NULL,
            actor_id bigint unsigned NOT NULL DEFAULT 0,
            event_type varchar(40) NOT NULL,
            payload_json longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY ix_support_events (conversation_id,id)
        ) $cc;");

        dbDelta("CREATE TABLE " . self::table('attachments') . " (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            conversation_id bigint unsigned NOT NULL,
            message_id bigint unsigned NOT NULL DEFAULT 0,
            uploader_type varchar(16) NOT NULL,
            uploader_id bigint unsigned NOT NULL DEFAULT 0,
            original_name varchar(190) NOT NULL,
            mime_type varchar(100) NOT NULL,
            file_size bigint unsigned NOT NULL DEFAULT 0,
            storage_path varchar(255) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY ix_support_attachment_conversation (conversation_id,id),
            KEY ix_support_attachment_message (message_id)
        ) $cc;");

        dbDelta("CREATE TABLE " . self::table('notes') . " (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            conversation_id bigint unsigned NOT NULL,
            operator_id bigint unsigned NOT NULL DEFAULT 0,
            body text NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY ix_support_notes (conversation_id,id)
        ) $cc;");

        dbDelta("CREATE TABLE " . self::table('canned_replies') . " (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            department_id bigint unsigned NOT NULL DEFAULT 0,
            title varchar(120) NOT NULL,
            body text NOT NULL,
            active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY ix_support_canned (department_id,active,title)
        ) $cc;");

        update_option('bluevpn_support_schema', self::SCHEMA, false);
    }

    private static function maybe_upgrade(): void {
        if ((string)get_option('bluevpn_support_schema', '') !== self::SCHEMA) {
            self::install_schema();
            self::seed_defaults();
        }
    }

    private static function seed_defaults(): void {
        global $wpdb;
        $t = self::table('departments');
        $count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t}");
        $now = BlueVPN_Utils::now_mysql();
        $rows = [
            ['فنی','technical','اتصال، سرورها، سرعت و خطاهای VPN',10,20,240],
            ['اشتراک و حساب','account','اشتراک، حجم، زمان و حساب کاربری',20,20,360],
            ['مالی و پرداخت','billing','پرداخت، فاکتور و بلوپال',30,10,180],
            ['فروش','sales','خرید، تمدید و ارتقای سرویس',40,10,180],
            ['نمایندگان','resellers','همکاری و امور نمایندگی',50,30,720],
        ];
        if ($count === 0) {
            foreach ($rows as $r) {
                $wpdb->insert($t, [
                    'name'=>$r[0], 'slug'=>$r[1], 'description'=>$r[2],
                    'active'=>1, 'sort_order'=>$r[3],
                    'first_response_minutes'=>$r[4], 'resolution_minutes'=>$r[5],
                    'created_at'=>$now, 'updated_at'=>$now,
                ]);
            }
        }
        $canned=self::table('canned_replies');
        if ((int)$wpdb->get_var("SELECT COUNT(*) FROM {$canned}")===0) {
            foreach ([
                ['بررسی اتصال','پیام شما دریافت شد. لطفاً چند لحظه فرصت بدهید تا وضعیت اتصال و سرور بررسی شود.'],
                ['بررسی پرداخت','پرداخت شما در حال بررسی است. لطفاً تا اعلام نتیجه از پرداخت مجدد خودداری کنید.'],
                ['بررسی اشتراک','وضعیت اشتراک شما در حال همگام‌سازی است و نتیجه در همین گفتگو اعلام می‌شود.'],
            ] as $row) {
                $wpdb->insert($canned,[
                    'department_id'=>0,'title'=>$row[0],'body'=>$row[1],
                    'active'=>1,'created_at'=>$now,'updated_at'=>$now,
                ]);
            }
        }
    }

    private static function auth_customer(WP_REST_Request $r): array {
        return BlueVPN_Auth::current_customer($r);
    }

    private static function rate_limit(int $customerId, string $action, int $limit=8, int $window=60): void {
        $ip = sanitize_text_field((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        $key = 'bluevpn_support_rl_' . hash('sha256', $customerId.'|'.$action.'|'.$ip);
        $state = get_transient($key);
        $count = is_array($state) ? (int)($state['count'] ?? 0) : 0;
        if ($count >= $limit) {
            throw new BlueVPN_Auth_Exception(429, 'SUPPORT_RATE_LIMIT', 'لطفاً کمی بعد دوباره تلاش کنید');
        }
        set_transient($key, ['count'=>$count+1], $window);
    }

    private static function clean_message($raw): string {
        $text = trim(wp_strip_all_tags((string)$raw));
        $text = preg_replace('/\s{4,}/u', '   ', $text);
        if ($text === '') throw new BlueVPN_Auth_Exception(422,'SUPPORT_EMPTY_MESSAGE','پیام خالی است');
        if (mb_strlen($text) > 4000) throw new BlueVPN_Auth_Exception(422,'SUPPORT_MESSAGE_TOO_LONG','پیام بیش از حد طولانی است');
        return $text;
    }

    private static function conversation_for_customer(int $id, int $customerId): ?array {
        global $wpdb;
        $t=self::table('conversations');
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d AND customer_id=%d LIMIT 1",$id,$customerId),ARRAY_A);
        return is_array($row)?$row:null;
    }

    private static function sla_state(array $c): array {
        $now=time();
        $firstDue=!empty($c['first_response_due_at'])?strtotime((string)$c['first_response_due_at'].' UTC'):0;
        $resolutionDue=!empty($c['resolution_due_at'])?strtotime((string)$c['resolution_due_at'].' UTC'):0;
        $firstDone=!empty($c['first_response_at']);
        $resolved=in_array((string)($c['status']??''),['resolved','closed'],true);
        $firstOverdue=!$firstDone && $firstDue>0 && $firstDue<$now;
        $resolutionOverdue=!$resolved && $resolutionDue>0 && $resolutionDue<$now;
        return [
            'first_response_due_at'=>(string)($c['first_response_due_at']??''),
            'resolution_due_at'=>(string)($c['resolution_due_at']??''),
            'first_response_overdue'=>$firstOverdue,
            'resolution_overdue'=>$resolutionOverdue,
            'state'=>$resolutionOverdue?'overdue':($firstOverdue?'response_overdue':'on_time'),
        ];
    }

    private static function attachment_rows_for_messages(array $messageIds): array {
        global $wpdb;
        $ids=array_values(array_filter(array_map('intval',$messageIds)));
        if(!$ids)return [];
        $placeholders=implode(',',array_fill(0,count($ids),'%d'));
        $query=$wpdb->prepare(
            "SELECT id,message_id,original_name,mime_type,file_size FROM ".self::table('attachments')." WHERE message_id IN ({$placeholders}) ORDER BY id ASC",
            ...$ids
        );
        $rows=$wpdb->get_results($query,ARRAY_A);
        $out=[];
        foreach((array)$rows as $r){
            $out[(int)$r['message_id']][]=[
                'id'=>(int)$r['id'],
                'name'=>(string)$r['original_name'],
                'mime'=>(string)$r['mime_type'],
                'size'=>(int)$r['file_size'],
            ];
        }
        return $out;
    }

    private static function safe_attachment_name(string $name): string {
        $name=sanitize_file_name($name);
        return $name!==''?$name:'attachment.bin';
    }

    private static function save_base64_attachment(
        int $cid,
        int $messageId,
        string $uploaderType,
        int $uploaderId,
        string $name,
        string $mime,
        string $encoded
    ): int {
        global $wpdb;
        $allowed=[
            'image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp',
            'application/pdf'=>'pdf','text/plain'=>'txt',
            'application/zip'=>'zip',
        ];
        $mime=strtolower(trim($mime));
        if(strlen($encoded)>5_600_000)throw new BlueVPN_Auth_Exception(413,'SUPPORT_ATTACHMENT_TOO_LARGE','حجم فایل زیاد است');
        $raw=base64_decode($encoded,true);
        if($raw===false || strlen($raw)===0)throw new BlueVPN_Auth_Exception(422,'SUPPORT_ATTACHMENT_INVALID','فایل معتبر نیست');
        if(strlen($raw)>4*1024*1024)throw new BlueVPN_Auth_Exception(413,'SUPPORT_ATTACHMENT_TOO_LARGE','حداکثر حجم فایل ۴ مگابایت است');
        if(class_exists('finfo')){
            $finfo=new finfo(FILEINFO_MIME_TYPE);
            $detected=strtolower((string)$finfo->buffer($raw));
            if($detected!=='')$mime=$detected;
        }
        if(!isset($allowed[$mime]))throw new BlueVPN_Auth_Exception(422,'SUPPORT_ATTACHMENT_TYPE','نوع فایل مجاز نیست');

        $uploads=wp_upload_dir();
        if(!empty($uploads['error']))throw new RuntimeException('upload_dir unavailable');
        $dir=trailingslashit((string)$uploads['basedir']).'bluevpn-support/'.substr(hash('sha256',(string)$cid),0,12);
        if(!wp_mkdir_p($dir))throw new RuntimeException('support upload directory unavailable');
        $original=self::safe_attachment_name($name);
        $file=wp_unique_filename($dir,pathinfo($original,PATHINFO_FILENAME).'.'.$allowed[$mime]);
        $path=trailingslashit($dir).$file;
        if(file_put_contents($path,$raw,LOCK_EX)===false)throw new RuntimeException('support attachment write failed');
        @chmod($path,0640);
        $wpdb->insert(self::table('attachments'),[
            'conversation_id'=>$cid,'message_id'=>$messageId,'uploader_type'=>$uploaderType,'uploader_id'=>$uploaderId,
            'original_name'=>$original,'mime_type'=>$mime,'file_size'=>strlen($raw),
            'storage_path'=>$path,'created_at'=>BlueVPN_Utils::now_mysql(),
        ]);
        return (int)$wpdb->insert_id;
    }

    private static function blueai_suggestion(int $cid): string {
        global $wpdb;
        $last=(string)$wpdb->get_var($wpdb->prepare(
            "SELECT body FROM ".self::table('messages')." WHERE conversation_id=%d AND sender_type='customer' ORDER BY id DESC LIMIT 1",
            $cid
        ));
        $x=mb_strtolower($last);
        if(str_contains($x,'پرداخت')||str_contains($x,'پول')||str_contains($x,'فاکتور')){
            return 'پرداخت شما دریافت شد و در حال بررسی تطبیق تراکنش با سفارش است. لطفاً تا اعلام نتیجه پرداخت مجدد انجام ندهید.';
        }
        if(str_contains($x,'وصل')||str_contains($x,'اتصال')||str_contains($x,'سرور')){
            return 'پیام شما دریافت شد. وضعیت اتصال، سرور و آخرین خطای فنی بررسی می‌شود و نتیجه در همین گفتگو اعلام خواهد شد.';
        }
        if(str_contains($x,'اشتراک')||str_contains($x,'حجم')||str_contains($x,'تمدید')){
            return 'وضعیت اشتراک و همگام‌سازی سرویس شما بررسی می‌شود. اگر تغییری لازم باشد از همین بخش اعمال خواهد شد.';
        }
        return 'پیام شما دریافت شد و به بخش مربوطه ارجاع داده شده است. نتیجه بررسی در همین گفتگو اعلام می‌شود.';
    }

    private static function serialize_conversation(array $c): array {
        global $wpdb;
        $dept=$wpdb->get_row($wpdb->prepare("SELECT id,name,slug FROM ".self::table('departments')." WHERE id=%d",(int)$c['department_id']),ARRAY_A);
        $op=null;
        if ((int)$c['operator_id']>0) $op=$wpdb->get_row($wpdb->prepare("SELECT id,display_name,online FROM ".self::table('operators')." WHERE id=%d",(int)$c['operator_id']),ARRAY_A);
        return [
            'id'=>(int)$c['id'],
            'public_id'=>(string)$c['public_id'],
            'subject'=>(string)$c['subject'],
            'status'=>(string)$c['status'],
            'priority'=>(string)$c['priority'],
            'department'=>$dept?:null,
            'operator'=>$op?:null,
            'unread'=>(int)$c['unread_customer'],
            'last_message_at'=>(string)$c['last_message_at'],
            'created_at'=>(string)$c['created_at'],
            'sla'=>self::sla_state($c),
        ];
    }

    public static function api_departments(WP_REST_Request $r): WP_REST_Response {
        try {
            self::auth_customer($r);
            global $wpdb;
            $rows=$wpdb->get_results("SELECT id,name,slug,description FROM ".self::table('departments')." WHERE active=1 ORDER BY sort_order ASC,id ASC",ARRAY_A);
            return new WP_REST_Response(['departments'=>array_map(static function($x){
                return ['id'=>(int)$x['id'],'name'=>(string)$x['name'],'slug'=>(string)$x['slug'],'description'=>(string)$x['description']];
            },(array)$rows)]);
        } catch (BlueVPN_Auth_Exception $e) { return self::auth_fail($e); }
    }

    public static function api_conversations(WP_REST_Request $r): WP_REST_Response {
        try {
            $customer=self::auth_customer($r);
            global $wpdb;
            $rows=$wpdb->get_results($wpdb->prepare(
                "SELECT * FROM ".self::table('conversations')." WHERE customer_id=%d ORDER BY last_message_at DESC LIMIT 30",
                (int)$customer['id']
            ),ARRAY_A);
            return new WP_REST_Response(['conversations'=>array_map([self::class,'serialize_conversation'],(array)$rows)]);
        } catch (BlueVPN_Auth_Exception $e) { return self::auth_fail($e); }
    }

    private static function auto_assign_operator(int $departmentId): int {
        global $wpdb;
        $operators=self::table('operators');
        $conversations=self::table('conversations');
        $rows=$wpdb->get_results(
            "SELECT o.id,o.department_ids,o.max_active,
                    SUM(CASE WHEN c.status IN ('waiting','open','pending_customer') THEN 1 ELSE 0 END) active_count
             FROM {$operators} o
             LEFT JOIN {$conversations} c ON c.operator_id=o.id
             WHERE o.online=1
             GROUP BY o.id,o.department_ids,o.max_active
             ORDER BY active_count ASC,o.id ASC",
            ARRAY_A
        );
        foreach((array)$rows as $row){
            $ids=array_values(array_filter(array_map('intval',explode(',',(string)$row['department_ids']))));
            if($ids && !in_array($departmentId,$ids,true))continue;
            if((int)$row['active_count'] >= max(1,(int)$row['max_active']))continue;
            return (int)$row['id'];
        }
        return 0;
    }

    public static function api_create(WP_REST_Request $r): WP_REST_Response {
        try {
            $customer=self::auth_customer($r);
            self::rate_limit((int)$customer['id'],'create',4,120);
            $body=$r->get_json_params(); if(!is_array($body))$body=[];
            $dept=(int)($body['department_id']??0);
            $message=self::clean_message($body['message']??'');
            $subject=sanitize_text_field((string)($body['subject']??''));
            if ($subject==='') $subject=mb_substr($message,0,80);

            global $wpdb;
            $d=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::table('departments')." WHERE id=%d AND active=1",$dept),ARRAY_A);
            if(!$d) throw new BlueVPN_Auth_Exception(422,'SUPPORT_INVALID_DEPARTMENT','بخش پشتیبانی معتبر نیست');

            $now=BlueVPN_Utils::now_mysql();
            $operatorId=self::auto_assign_operator($dept);
            $firstDue=gmdate('Y-m-d H:i:s',time()+max(5,(int)($d['first_response_minutes']??30))*60);
            $resolutionDue=gmdate('Y-m-d H:i:s',time()+max(30,(int)($d['resolution_minutes']??1440))*60);
            $public='chat_'.substr(hash('sha256',wp_generate_uuid4().'|'.$customer['id'].'|'.microtime(true)),0,20);
            $wpdb->insert(self::table('conversations'),[
                'public_id'=>$public,'customer_id'=>(int)$customer['id'],'department_id'=>$dept,
                'operator_id'=>$operatorId,'subject'=>$subject,'status'=>$operatorId>0?'open':'waiting','priority'=>'normal','source'=>'android',
                'unread_customer'=>0,'unread_operator'=>1,'last_message_at'=>$now,
                'first_response_due_at'=>$firstDue,'resolution_due_at'=>$resolutionDue,
                'created_at'=>$now,'updated_at'=>$now,
            ]);
            $cid=(int)$wpdb->insert_id;
            self::insert_message($cid,'customer',(int)$customer['id'],$message);
            self::event($cid,'customer',(int)$customer['id'],'conversation_created',['department_id'=>$dept]);
            self::telegram_notify_new($cid,$customer,$d,$message);
            return new WP_REST_Response(['conversation'=>self::serialize_conversation(self::conversation_row($cid))],201);
        } catch (BlueVPN_Auth_Exception $e) { return self::auth_fail($e); }
        catch(Throwable $e){ return self::server_fail($e,'support_create'); }
    }

    public static function api_messages(WP_REST_Request $r): WP_REST_Response {
        try {
            $customer=self::auth_customer($r);
            $cid=(int)$r['id'];
            $conv=self::conversation_for_customer($cid,(int)$customer['id']);
            if(!$conv) throw new BlueVPN_Auth_Exception(404,'SUPPORT_NOT_FOUND','گفتگو پیدا نشد');
            $after=max(0,(int)$r->get_param('after_id'));
            global $wpdb;
            $rows=$wpdb->get_results($wpdb->prepare(
                "SELECT id,sender_type,body,message_type,created_at,seen_at FROM ".self::table('messages')." WHERE conversation_id=%d AND id>%d ORDER BY id ASC LIMIT 200",
                $cid,$after
            ),ARRAY_A);
            $wpdb->query($wpdb->prepare(
                "UPDATE ".self::table('messages')." SET seen_at=%s WHERE conversation_id=%d AND sender_type='operator' AND seen_at IS NULL",
                BlueVPN_Utils::now_mysql(),$cid
            ));
            $wpdb->update(self::table('conversations'),['unread_customer'=>0],['id'=>$cid]);
            $attachmentMap=self::attachment_rows_for_messages(array_column((array)$rows,'id'));
            return new WP_REST_Response([
                'conversation'=>self::serialize_conversation($conv),
                'messages'=>array_map(static function($m) use ($attachmentMap){
                    $mid=(int)$m['id'];
                    return ['id'=>$mid,'sender'=>(string)$m['sender_type'],'body'=>(string)$m['body'],'type'=>(string)$m['message_type'],'created_at'=>(string)$m['created_at'],'seen'=>(bool)$m['seen_at'],'attachments'=>$attachmentMap[$mid]??[]];
                },(array)$rows),
            ]);
        } catch (BlueVPN_Auth_Exception $e) { return self::auth_fail($e); }
    }

    public static function api_send(WP_REST_Request $r): WP_REST_Response {
        try {
            $customer=self::auth_customer($r);
            self::rate_limit((int)$customer['id'],'message',12,60);
            $cid=(int)$r['id'];
            $conv=self::conversation_for_customer($cid,(int)$customer['id']);
            if(!$conv) throw new BlueVPN_Auth_Exception(404,'SUPPORT_NOT_FOUND','گفتگو پیدا نشد');
            if($conv['status']==='closed') throw new BlueVPN_Auth_Exception(409,'SUPPORT_CLOSED','این گفتگو بسته شده است');
            $body=$r->get_json_params(); if(!is_array($body))$body=[];
            $message=self::clean_message($body['message']??'');
            $mid=self::insert_message($cid,'customer',(int)$customer['id'],$message);
            global $wpdb;
            $wpdb->query($wpdb->prepare(
                "UPDATE ".self::table('conversations')." SET unread_operator=unread_operator+1,status=IF(status='waiting','waiting','open'),last_message_at=%s,updated_at=%s WHERE id=%d",
                BlueVPN_Utils::now_mysql(),BlueVPN_Utils::now_mysql(),$cid
            ));
            self::telegram_notify_reply($cid,$customer,$message);
            return new WP_REST_Response(['ok'=>true,'message_id'=>$mid]);
        } catch (BlueVPN_Auth_Exception $e) { return self::auth_fail($e); }
        catch(Throwable $e){ return self::server_fail($e,'support_send'); }
    }

    public static function api_unread(WP_REST_Request $r): WP_REST_Response {
        try {
            $customer=self::auth_customer($r);
            global $wpdb;
            $row=$wpdb->get_row($wpdb->prepare(
                "SELECT c.id,c.unread_customer,m.id message_id,m.body,m.created_at
                 FROM ".self::table('conversations')." c
                 LEFT JOIN ".self::table('messages')." m ON m.id=(
                    SELECT id FROM ".self::table('messages')." WHERE conversation_id=c.id AND sender_type='operator' ORDER BY id DESC LIMIT 1
                 )
                 WHERE c.customer_id=%d AND c.unread_customer>0
                 ORDER BY c.last_message_at DESC LIMIT 1",
                (int)$customer['id']
            ),ARRAY_A);
            $count=(int)$wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(unread_customer),0) FROM ".self::table('conversations')." WHERE customer_id=%d",
                (int)$customer['id']
            ));
            return new WP_REST_Response([
                'unread'=>$count,
                'latest'=>$row?[
                    'conversation_id'=>(int)$row['id'],
                    'message_id'=>(int)($row['message_id']??0),
                    'body'=>(string)($row['body']??''),
                    'created_at'=>(string)($row['created_at']??''),
                ]:null,
            ]);
        } catch (BlueVPN_Auth_Exception $e) { return self::auth_fail($e); }
    }

    public static function api_attachment(WP_REST_Request $r): WP_REST_Response {
        try {
            $customer=self::auth_customer($r);
            self::rate_limit((int)$customer['id'],'attachment',5,120);
            $cid=(int)$r['id'];
            $conv=self::conversation_for_customer($cid,(int)$customer['id']);
            if(!$conv)throw new BlueVPN_Auth_Exception(404,'SUPPORT_NOT_FOUND','گفتگو پیدا نشد');
            if($conv['status']==='closed')throw new BlueVPN_Auth_Exception(409,'SUPPORT_CLOSED','این گفتگو بسته شده است');
            $body=$r->get_json_params();if(!is_array($body))$body=[];
            $name=self::safe_attachment_name((string)($body['name']??'attachment'));
            $mime=sanitize_mime_type((string)($body['mime']??''));
            $encoded=(string)($body['data_base64']??'');
            $mid=self::insert_message($cid,'customer',(int)$customer['id'],'📎 '.$name,'attachment');
            try {
                $aid=self::save_base64_attachment($cid,$mid,'customer',(int)$customer['id'],$name,$mime,$encoded);
            } catch(Throwable $e) {
                global $wpdb;
                $wpdb->delete(self::table('messages'),['id'=>$mid,'conversation_id'=>$cid]);
                throw $e;
            }
            global $wpdb;
            $wpdb->query($wpdb->prepare(
                "UPDATE ".self::table('conversations')." SET unread_operator=unread_operator+1,last_message_at=%s,updated_at=%s WHERE id=%d",
                BlueVPN_Utils::now_mysql(),BlueVPN_Utils::now_mysql(),$cid
            ));
            self::event($cid,'customer',(int)$customer['id'],'attachment_added',['attachment_id'=>$aid,'mime'=>$mime]);
            self::telegram_notify_reply($cid,$customer,'📎 '.$name);
            return new WP_REST_Response(['ok'=>true,'message_id'=>$mid,'attachment_id'=>$aid],201);
        } catch (BlueVPN_Auth_Exception $e) { return self::auth_fail($e); }
        catch(Throwable $e){ return self::server_fail($e,'support_attachment'); }
    }

    public static function api_close(WP_REST_Request $r): WP_REST_Response {
        try {
            $customer=self::auth_customer($r);
            $cid=(int)$r['id'];
            $conv=self::conversation_for_customer($cid,(int)$customer['id']);
            if(!$conv) throw new BlueVPN_Auth_Exception(404,'SUPPORT_NOT_FOUND','گفتگو پیدا نشد');
            self::set_status($cid,'closed','customer',(int)$customer['id']);
            return new WP_REST_Response(['ok'=>true]);
        } catch (BlueVPN_Auth_Exception $e) { return self::auth_fail($e); }
    }

    private static function auth_fail(BlueVPN_Auth_Exception $e): WP_REST_Response {
        return new WP_REST_Response(['detail'=>['code'=>$e->error_code,'message'=>$e->getMessage()]],$e->http_status);
    }
    private static function server_fail(Throwable $e,string $scope): WP_REST_Response {
        $trace=substr(hash('sha256',$scope.'|'.microtime(true).'|'.wp_rand()),0,12);
        error_log("BlueVPN Support {$scope} [{$trace}]: ".$e->getMessage());
        return new WP_REST_Response(['detail'=>['code'=>'SUPPORT_INTERNAL','message'=>'خطای داخلی پشتیبانی','trace_id'=>$trace]],500);
    }
    private static function conversation_row(int $id): array {
        global $wpdb;
        return (array)$wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::table('conversations')." WHERE id=%d",$id),ARRAY_A);
    }
    private static function insert_message(int $cid,string $type,int $sender,string $body,string $messageType='text'): int {
        global $wpdb;
        $wpdb->insert(self::table('messages'),[
            'conversation_id'=>$cid,'sender_type'=>$type,'sender_id'=>$sender,'body'=>$body,
            'message_type'=>$messageType,'created_at'=>BlueVPN_Utils::now_mysql(),
        ]);
        return (int)$wpdb->insert_id;
    }
    private static function event(int $cid,string $actorType,int $actorId,string $type,array $payload=[]): void {
        global $wpdb;
        $wpdb->insert(self::table('events'),[
            'conversation_id'=>$cid,'actor_type'=>$actorType,'actor_id'=>$actorId,'event_type'=>$type,
            'payload_json'=>$payload?wp_json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,
            'created_at'=>BlueVPN_Utils::now_mysql(),
        ]);
    }
    private static function set_status(int $cid,string $status,string $actorType,int $actorId): void {
        $allowed=['waiting','open','pending_customer','resolved','closed'];
        if(!in_array($status,$allowed,true))$status='open';
        global $wpdb;
        $data=['status'=>$status,'updated_at'=>BlueVPN_Utils::now_mysql()];
        if($status==='resolved')$data['resolved_at']=BlueVPN_Utils::now_mysql();
        if($status==='closed')$data['closed_at']=BlueVPN_Utils::now_mysql();
        $wpdb->update(self::table('conversations'),$data,['id'=>$cid]);
        self::event($cid,$actorType,$actorId,'status_changed',['status'=>$status]);
    }

    private static function touch_current_operator(): void {
        if(!is_user_logged_in())return;
        global $wpdb;
        $uid=get_current_user_id();
        if($uid<=0)return;
        $wpdb->update(
            self::table('operators'),
            ['online'=>1,'last_seen_at'=>BlueVPN_Utils::now_mysql(),'updated_at'=>BlueVPN_Utils::now_mysql()],
            ['wp_user_id'=>$uid]
        );
    }

    public static function admin_menu(): void {
        add_submenu_page('bluevpn-manager','پشتیبانی آنلاین','پشتیبانی آنلاین','manage_options','bluevpn-support',[self::class,'admin_page']);
    }

    public static function admin_page(): void {
        if(!current_user_can('manage_options'))return;
        self::touch_current_operator();
        $supportPageTitle='پشتیبانی آنلاین BlueVPN';
        BlueVPN_Unified_UI::shell_open(
            'پشتیبانی آنلاین',
            'گفتگوهای کاربران • اپراتورها • SLA • BlueAI'
        );

        global $wpdb;
        $cid=max(0,(int)($_GET['conversation']??0));
        $convs=$wpdb->get_results(
            "SELECT c.*,d.name department_name,o.display_name operator_name
             FROM ".self::table('conversations')." c
             LEFT JOIN ".self::table('departments')." d ON d.id=c.department_id
             LEFT JOIN ".self::table('operators')." o ON o.id=c.operator_id
             ORDER BY FIELD(c.status,'waiting','open','pending_customer','resolved','closed'),
                      c.last_message_at DESC
             LIMIT 100",
            ARRAY_A
        );
        $depts=$wpdb->get_results(
            "SELECT * FROM ".self::table('departments')." ORDER BY sort_order,id",
            ARRAY_A
        );
        $ops=$wpdb->get_results(
            "SELECT * FROM ".self::table('operators')." ORDER BY display_name",
            ARRAY_A
        );

        $stats=[
            'all'=>count((array)$convs),
            'waiting'=>0,
            'open'=>0,
            'overdue'=>0,
        ];
        foreach((array)$convs as $row){
            $status=(string)($row['status']??'');
            if($status==='waiting')$stats['waiting']++;
            if(in_array($status,['open','pending_customer'],true))$stats['open']++;
            $sla=self::sla_state($row);
            if(($sla['state']??'')!=='on_time')$stats['overdue']++;
        }

        echo '<style>
        .bvs-app{
            --bvs-bg:#08111d;
            --bvs-panel:#0d1726;
            --bvs-panel-2:#111e30;
            --bvs-panel-3:#16243a;
            --bvs-border:#22324a;
            --bvs-text:#edf5ff;
            --bvs-muted:#8ea0ba;
            --bvs-accent:#24d6c3;
            --bvs-blue:#4b83ff;
            --bvs-danger:#ff6677;
            --bvs-warning:#ffb34d;
            color:var(--bvs-text);
            direction:rtl;
        }
        .bvs-topstats{
            display:grid;
            grid-template-columns:repeat(4,minmax(0,1fr));
            gap:12px;
            margin:0 0 14px;
        }
        .bvs-stat{
            background:linear-gradient(180deg,var(--bvs-panel-2),var(--bvs-panel));
            border:1px solid var(--bvs-border);
            border-radius:16px;
            padding:14px 16px;
            min-height:74px;
        }
        .bvs-stat-label{color:var(--bvs-muted);font-size:12px;margin-bottom:4px}
        .bvs-stat-value{font-size:24px;font-weight:800;line-height:1}
        .bvs-layout{
            display:grid;
            grid-template-columns:minmax(250px,320px) minmax(0,1fr) minmax(250px,310px);
            gap:14px;
            align-items:stretch;
            min-height:660px;
        }
        .bvs-panel{
            background:linear-gradient(180deg,var(--bvs-panel-2),var(--bvs-panel));
            border:1px solid var(--bvs-border);
            border-radius:18px;
            overflow:hidden;
            box-shadow:0 14px 34px rgba(0,0,0,.18);
        }
        .bvs-panel-head{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:10px;
            padding:14px 16px;
            border-bottom:1px solid var(--bvs-border);
        }
        .bvs-panel-head h2,.bvs-panel-head h3{margin:0;color:var(--bvs-text);font-size:15px}
        .bvs-pill{
            display:inline-flex;
            align-items:center;
            gap:6px;
            border:1px solid var(--bvs-border);
            background:#0a1422;
            color:var(--bvs-muted);
            border-radius:999px;
            padding:5px 9px;
            font-size:10px;
            white-space:nowrap;
        }
        .bvs-list{max-height:680px;overflow:auto}
        .bvs-conv{
            display:block;
            padding:13px 14px;
            text-decoration:none!important;
            color:var(--bvs-text)!important;
            border-bottom:1px solid rgba(34,50,74,.7);
            transition:.15s ease;
        }
        .bvs-conv:hover,.bvs-conv.is-active{background:rgba(75,131,255,.10)}
        .bvs-conv-top{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:8px;
            margin-bottom:5px;
        }
        .bvs-conv-title{
            font-size:13px;
            font-weight:700;
            overflow:hidden;
            text-overflow:ellipsis;
            white-space:nowrap;
            min-width:0;
        }
        .bvs-conv-time{font-size:9px;color:var(--bvs-muted);white-space:nowrap}
        .bvs-conv-meta{
            display:flex;
            flex-wrap:wrap;
            gap:5px;
            align-items:center;
            color:var(--bvs-muted);
            font-size:10px;
        }
        .bvs-dot{width:7px;height:7px;border-radius:99px;display:inline-block;background:#73839b}
        .bvs-dot.waiting{background:var(--bvs-warning)}
        .bvs-dot.open,.bvs-dot.pending_customer{background:var(--bvs-accent)}
        .bvs-dot.closed,.bvs-dot.resolved{background:#62708a}
        .bvs-sla-bad{color:var(--bvs-warning);font-weight:700}
        .bvs-chat{
            display:flex;
            flex-direction:column;
            min-height:660px;
        }
        .bvs-chat-head{
            padding:14px 16px;
            border-bottom:1px solid var(--bvs-border);
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
        }
        .bvs-chat-title{min-width:0}
        .bvs-chat-title strong{display:block;font-size:15px;color:var(--bvs-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .bvs-chat-title small{color:var(--bvs-muted)}
        .bvs-chat-body{
            flex:1;
            min-height:370px;
            max-height:500px;
            overflow:auto;
            padding:16px;
            background:
                radial-gradient(circle at 20% 10%,rgba(75,131,255,.06),transparent 34%),
                linear-gradient(180deg,#091421,#0a1421);
        }
        .bvs-msg-row{display:flex;margin:8px 0}
        .bvs-msg-row.customer{justify-content:flex-start}
        .bvs-msg-row.operator{justify-content:flex-end}
        .bvs-msg-row.system{justify-content:center}
        .bvs-msg{
            max-width:min(78%,620px);
            border-radius:16px;
            padding:10px 12px;
            border:1px solid var(--bvs-border);
            line-height:1.8;
            font-size:12px;
            box-shadow:0 6px 18px rgba(0,0,0,.10);
        }
        .bvs-msg.customer{background:#173056;border-color:#28528f}
        .bvs-msg.operator{background:#102d2b;border-color:#1e5a55}
        .bvs-msg.system{background:#121d2c;color:var(--bvs-muted)}
        .bvs-msg-meta{display:flex;justify-content:space-between;gap:14px;margin-top:5px;color:#8da0bb;font-size:9px}
        .bvs-ai{
            margin:12px 14px 0;
            padding:11px 12px;
            border-radius:14px;
            border:1px solid rgba(75,131,255,.35);
            background:rgba(75,131,255,.08);
        }
        .bvs-ai strong{color:#86a9ff}
        .bvs-compose{padding:12px 14px 14px}
        .bvs-compose textarea,
        .bvs-app input[type=text],
        .bvs-app input[type=number],
        .bvs-app select,
        .bvs-app textarea{
            width:100%;
            box-sizing:border-box;
            background:#081321!important;
            color:var(--bvs-text)!important;
            border:1px solid var(--bvs-border)!important;
            border-radius:12px!important;
            min-height:40px;
            box-shadow:none!important;
        }
        .bvs-compose textarea{min-height:92px;resize:vertical;padding:10px 12px}
        .bvs-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
        .bvs-row > *{min-width:0}
        .bvs-btn{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            border:0;
            border-radius:11px;
            min-height:40px;
            padding:0 14px;
            cursor:pointer;
            font-weight:700;
            text-decoration:none!important;
        }
        .bvs-btn-primary{background:linear-gradient(135deg,#4b83ff,#2d66e8);color:#fff}
        .bvs-btn-soft{background:#132137;color:var(--bvs-text);border:1px solid var(--bvs-border)}
        .bvs-btn-danger{background:rgba(255,102,119,.12);color:#ff8390;border:1px solid rgba(255,102,119,.3)}
        .bvs-side-scroll{max-height:680px;overflow:auto;padding:12px}
        .bvs-section{
            background:#0a1422;
            border:1px solid var(--bvs-border);
            border-radius:14px;
            padding:12px;
            margin-bottom:10px;
        }
        .bvs-section h3{margin:0 0 10px;color:var(--bvs-text);font-size:13px}
        .bvs-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
        .bvs-form-grid .wide{grid-column:1/-1}
        .bvs-mini-list{display:grid;gap:7px}
        .bvs-mini-item{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:8px;
            border:1px solid var(--bvs-border);
            background:#0d1928;
            border-radius:11px;
            padding:9px 10px;
        }
        .bvs-mini-item strong{font-size:11px}
        .bvs-mini-item small{color:var(--bvs-muted);font-size:9px}
        .bvs-note{
            background:#2a2512;
            border:1px solid #5e5122;
            border-radius:11px;
            padding:9px 10px;
            margin-top:7px;
            color:#f1df9c;
            font-size:11px;
        }
        .bvs-empty{
            min-height:620px;
            display:flex;
            align-items:center;
            justify-content:center;
            text-align:center;
            padding:24px;
            color:var(--bvs-muted);
        }
        .bvs-empty-icon{
            width:64px;height:64px;border-radius:20px;
            margin:0 auto 12px;
            display:flex;align-items:center;justify-content:center;
            background:rgba(36,214,195,.09);
            border:1px solid rgba(36,214,195,.22);
            font-size:28px;
        }
        .bvs-app input[type=file]{color:var(--bvs-muted);max-width:100%}
        @media(max-width:1100px){
            .bvs-layout{grid-template-columns:minmax(230px,300px) minmax(0,1fr)}
            .bvs-right{grid-column:1/-1}
            .bvs-side-scroll{max-height:none}
        }
        @media(max-width:760px){
            .bvs-topstats{grid-template-columns:repeat(2,minmax(0,1fr))}
            .bvs-layout{display:block}
            .bvs-panel{margin-bottom:12px}
            .bvs-list{max-height:280px}
            .bvs-chat{min-height:560px}
            .bvs-chat-body{max-height:420px}
            .bvs-msg{max-width:90%}
            .bvs-form-grid{grid-template-columns:1fr}
            .bvs-form-grid .wide{grid-column:auto}
            .bvs-row{align-items:stretch}
            .bvs-row > *{width:100%}
            .bvs-btn{width:100%}
        }
        </style>';

        echo '<div class="bvs-app">';
        echo '<div class="bvs-topstats">';
        foreach([
            ['همه گفتگوها',$stats['all']],
            ['در انتظار',$stats['waiting']],
            ['فعال',$stats['open']],
            ['SLA عقب‌افتاده',$stats['overdue']],
        ] as $stat){
            echo '<div class="bvs-stat"><div class="bvs-stat-label">'.esc_html((string)$stat[0]).'</div><div class="bvs-stat-value">'.(int)$stat[1].'</div></div>';
        }
        echo '</div>';

        echo '<div class="bvs-layout">';

        echo '<section class="bvs-panel">';
        echo '<div class="bvs-panel-head"><h2>گفتگوها</h2><span class="bvs-pill">'.(int)$stats['all'].' گفتگو</span></div>';
        echo '<div class="bvs-list">';
        if(!$convs){
            echo '<div class="bvs-empty"><div><div class="bvs-empty-icon">💬</div><strong>هنوز گفتگویی نیست</strong><br><small>پیام‌های کاربران اینجا نمایش داده می‌شوند.</small></div></div>';
        } else {
            foreach((array)$convs as $c){
                $sla=self::sla_state($c);
                $active=$cid===(int)$c['id'];
                $status=(string)$c['status'];
                $title=trim((string)$c['subject'])?:'بدون عنوان';
                echo '<a class="bvs-conv'.($active?' is-active':'').'" href="'.esc_url(admin_url('admin.php?page=bluevpn-support&conversation='.(int)$c['id'])).'">';
                echo '<div class="bvs-conv-top"><span class="bvs-conv-title">'.esc_html($title).'</span><span class="bvs-conv-time">'.esc_html(BlueVPN_Utils::tehran_datetime_fa($c['last_message_at'])).'</span></div>';
                echo '<div class="bvs-conv-meta"><span class="bvs-dot '.esc_attr($status).'"></span><span>'.esc_html((string)$c['department_name']).'</span><span>•</span><span>'.esc_html($status).'</span>';
                if(($sla['state']??'on_time')!=='on_time')echo '<span class="bvs-sla-bad">• SLA</span>';
                echo '</div></a>';
            }
        }
        echo '</div></section>';

        echo '<section class="bvs-panel bvs-chat">';
        if($cid>0){
            $c=$wpdb->get_row(
                $wpdb->prepare("SELECT * FROM ".self::table('conversations')." WHERE id=%d",$cid),
                ARRAY_A
            );
        } else {
            $c=null;
        }

        if($c){
            $msgs=$wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM ".self::table('messages')." WHERE conversation_id=%d ORDER BY id ASC",
                    $cid
                ),
                ARRAY_A
            );
            $notes=$wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM ".self::table('notes')." WHERE conversation_id=%d ORDER BY id DESC LIMIT 30",
                    $cid
                ),
                ARRAY_A
            );
            $canned=$wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM ".self::table('canned_replies')."
                     WHERE active=1 AND (department_id=0 OR department_id=%d)
                     ORDER BY title",
                    (int)$c['department_id']
                ),
                ARRAY_A
            );
            $suggestion=self::blueai_suggestion($cid);
            $sla=self::sla_state($c);

            echo '<div class="bvs-chat-head">';
            echo '<div class="bvs-chat-title"><strong>'.esc_html((string)$c['subject']).'</strong><small>#'.(int)$cid.' • '.esc_html((string)$c['status']).'</small></div>';
            echo '<span class="bvs-pill">'.esc_html((string)($sla['state']??'on_time')).'</span>';
            echo '</div>';

            echo '<div class="bvs-chat-body" id="bvs-chat-body">';
            if(!$msgs){
                echo '<div class="bvs-empty"><div><div class="bvs-empty-icon">✦</div><strong>گفتگو آماده است</strong><br><small>اولین پیام در اینجا ظاهر می‌شود.</small></div></div>';
            } else {
                foreach((array)$msgs as $m){
                    $sender=(string)$m['sender_type'];
                    if(!in_array($sender,['customer','operator','system'],true))$sender='system';
                    $label=$sender==='customer'?'کاربر':($sender==='operator'?'پشتیبانی':'سیستم');
                    echo '<div class="bvs-msg-row '.esc_attr($sender).'"><div class="bvs-msg '.esc_attr($sender).'">';
                    echo '<div>'.nl2br(esc_html((string)$m['body'])).'</div>';
                    echo '<div class="bvs-msg-meta"><span>'.esc_html($label).'</span><span>'.esc_html(BlueVPN_Utils::tehran_datetime_fa($m['created_at'])).'</span></div>';
                    echo '</div></div>';
                }
            }
            echo '</div>';

            echo '<div class="bvs-ai"><strong>پیشنهاد BlueAI</strong><div style="margin-top:5px">'.esc_html($suggestion).'</div></div>';

            echo '<div class="bvs-compose">';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('bluevpn_support_reply_'.$cid);
            echo '<input type="hidden" name="action" value="bluevpn_support_reply"><input type="hidden" name="conversation_id" value="'.$cid.'">';
            echo '<div class="bvs-row" style="margin-bottom:8px">';
            echo '<select id="bvs-canned-'.$cid.'" style="flex:1" onchange="var t=document.getElementById(\'bvs-reply-'.$cid.'\');if(this.value)t.value=this.value"><option value="">پاسخ آماده...</option>';
            foreach((array)$canned as $cr){
                echo '<option value="'.esc_attr((string)$cr['body']).'">'.esc_html((string)$cr['title']).'</option>';
            }
            echo '</select>';
            echo '<button type="button" class="bvs-btn bvs-btn-soft" onclick="document.getElementById(\'bvs-reply-'.$cid.'\').value='.esc_attr(wp_json_encode($suggestion)).'">استفاده از BlueAI</button>';
            echo '</div>';
            echo '<textarea id="bvs-reply-'.$cid.'" name="message" required placeholder="پاسخ خود را بنویسید..."></textarea>';
            echo '<div class="bvs-row" style="margin-top:8px"><button class="bvs-btn bvs-btn-primary">ارسال پاسخ</button></div>';
            echo '</form>';
            echo '</div>';
        } else {
            echo '<div class="bvs-empty"><div><div class="bvs-empty-icon">💬</div><strong>یک گفتگو را انتخاب کنید</strong><br><small>پیام‌های کاربر و پاسخ اپراتور اینجا نمایش داده می‌شوند.</small></div></div>';
        }
        echo '</section>';

        echo '<aside class="bvs-panel bvs-right">';
        echo '<div class="bvs-panel-head"><h3>'.($c?'مدیریت گفتگو':'مدیریت پشتیبانی').'</h3><span class="bvs-pill">BlueVPN</span></div>';
        echo '<div class="bvs-side-scroll">';

        if($c){
            echo '<div class="bvs-section"><h3>ارجاع و وضعیت</h3>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            echo '<input type="hidden" name="action" value="bluevpn_support_assign"><input type="hidden" name="conversation_id" value="'.$cid.'">';
            wp_nonce_field('bluevpn_support_assign_'.$cid);
            echo '<div class="bvs-form-grid">';
            echo '<select name="operator_id"><option value="0">بدون اپراتور</option>';
            foreach((array)$ops as $o){
                echo '<option value="'.(int)$o['id'].'" '.selected((int)$c['operator_id'],(int)$o['id'],false).'>'.esc_html((string)$o['display_name']).'</option>';
            }
            echo '</select>';
            echo '<select name="department_id">';
            foreach((array)$depts as $d){
                echo '<option value="'.(int)$d['id'].'" '.selected((int)$c['department_id'],(int)$d['id'],false).'>'.esc_html((string)$d['name']).'</option>';
            }
            echo '</select>';
            echo '<button class="bvs-btn bvs-btn-soft wide">تخصیص / انتقال</button></div></form>';

            echo '<form style="margin-top:8px" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            echo '<input type="hidden" name="action" value="bluevpn_support_status"><input type="hidden" name="conversation_id" value="'.$cid.'">';
            wp_nonce_field('bluevpn_support_status_'.$cid);
            echo '<div class="bvs-row"><select name="status" style="flex:1">';
            foreach([
                'open'=>'در حال پاسخ',
                'pending_customer'=>'منتظر کاربر',
                'resolved'=>'حل‌شده',
                'closed'=>'بسته',
            ] as $value=>$label){
                echo '<option value="'.esc_attr($value).'" '.selected((string)$c['status'],$value,false).'>'.esc_html($label).'</option>';
            }
            echo '</select><button class="bvs-btn bvs-btn-soft">ثبت وضعیت</button></div></form>';
            echo '</div>';

            echo '<div class="bvs-section"><h3>SLA</h3>';
            echo '<div class="bvs-mini-list">';
            echo '<div class="bvs-mini-item"><div><strong>پاسخ اولیه</strong><br><small>'.esc_html(BlueVPN_Utils::tehran_datetime_fa($sla['first_response_due_at'])).'</small></div><span class="bvs-pill">'.(!empty($sla['first_response_overdue'])?'عقب‌افتاده':'عادی').'</span></div>';
            echo '<div class="bvs-mini-item"><div><strong>حل گفتگو</strong><br><small>'.esc_html(BlueVPN_Utils::tehran_datetime_fa($sla['resolution_due_at'])).'</small></div><span class="bvs-pill">'.(!empty($sla['resolution_overdue'])?'عقب‌افتاده':'عادی').'</span></div>';
            echo '</div></div>';

            echo '<div class="bvs-section"><h3>فایل</h3>';
            echo '<form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'">';
            echo '<input type="hidden" name="action" value="bluevpn_support_attachment"><input type="hidden" name="conversation_id" value="'.$cid.'">';
            wp_nonce_field('bluevpn_support_attachment_'.$cid);
            echo '<input type="file" name="attachment" accept="image/jpeg,image/png,image/webp,application/pdf,text/plain,application/zip" required>';
            echo '<button style="margin-top:8px" class="bvs-btn bvs-btn-soft">ارسال فایل</button></form></div>';

            echo '<div class="bvs-section"><h3>یادداشت داخلی</h3>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            echo '<input type="hidden" name="action" value="bluevpn_support_note"><input type="hidden" name="conversation_id" value="'.$cid.'">';
            wp_nonce_field('bluevpn_support_note_'.$cid);
            echo '<textarea name="note" rows="3" required placeholder="فقط اپراتورها می‌بینند"></textarea>';
            echo '<button style="margin-top:8px" class="bvs-btn bvs-btn-soft">ثبت یادداشت</button></form>';
            foreach((array)$notes as $n){
                echo '<div class="bvs-note">'.nl2br(esc_html((string)$n['body'])).'<br><small>'.esc_html(BlueVPN_Utils::tehran_datetime_fa($n['created_at'])).'</small></div>';
            }
            echo '</div>';
        } else {
            echo '<div class="bvs-section"><h3>افزودن بخش</h3>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            echo '<input type="hidden" name="action" value="bluevpn_support_department_save">';
            wp_nonce_field('bluevpn_support_department_save');
            echo '<div class="bvs-form-grid">';
            echo '<input class="wide" name="name" required placeholder="نام بخش">';
            echo '<input class="wide" name="description" placeholder="توضیح کوتاه">';
            echo '<input name="first_response_minutes" type="number" min="5" value="30" title="SLA پاسخ">';
            echo '<input name="resolution_minutes" type="number" min="30" value="1440" title="SLA حل">';
            echo '<button class="bvs-btn bvs-btn-primary wide">افزودن بخش</button>';
            echo '</div></form></div>';

            echo '<div class="bvs-section"><h3>افزودن اپراتور</h3>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            echo '<input type="hidden" name="action" value="bluevpn_support_operator_save">';
            wp_nonce_field('bluevpn_support_operator_save');
            echo '<div class="bvs-form-grid"><input name="display_name" required placeholder="نام اپراتور"><input name="wp_user_id" type="number" min="0" placeholder="WP User ID">';
            echo '<div class="wide" style="display:flex;gap:7px;flex-wrap:wrap">';
            foreach((array)$depts as $d){
                echo '<label class="bvs-pill"><input type="checkbox" name="department_ids[]" value="'.(int)$d['id'].'"> '.esc_html((string)$d['name']).'</label>';
            }
            echo '</div><button class="bvs-btn bvs-btn-primary wide">افزودن اپراتور</button></div></form></div>';

            echo '<div class="bvs-section"><h3>پاسخ آماده</h3>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            echo '<input type="hidden" name="action" value="bluevpn_support_canned_save">';
            wp_nonce_field('bluevpn_support_canned_save');
            echo '<input name="title" required placeholder="عنوان پاسخ">';
            echo '<textarea style="margin-top:8px" name="body" rows="3" required placeholder="متن پاسخ آماده"></textarea>';
            echo '<button style="margin-top:8px" class="bvs-btn bvs-btn-soft">ذخیره پاسخ</button></form></div>';

            echo '<div class="bvs-section"><h3>بخش‌ها</h3><div class="bvs-mini-list">';
            foreach((array)$depts as $d){
                echo '<div class="bvs-mini-item"><div><strong>'.esc_html((string)$d['name']).'</strong><br><small>SLA '.(int)$d['first_response_minutes'].' / '.(int)$d['resolution_minutes'].' دقیقه</small></div><span class="bvs-pill">فعال</span></div>';
            }
            echo '</div></div>';

            echo '<div class="bvs-section"><h3>اپراتورها</h3><div class="bvs-mini-list">';
            foreach((array)$ops as $o){
                $online=!empty($o['online']);
                echo '<div class="bvs-mini-item"><div><strong>'.esc_html((string)$o['display_name']).'</strong><br><small>'.($online?'آنلاین':'آفلاین').'</small></div>';
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
                echo '<input type="hidden" name="action" value="bluevpn_support_operator_presence"><input type="hidden" name="operator_id" value="'.(int)$o['id'].'"><input type="hidden" name="online" value="'.($online?'0':'1').'">';
                wp_nonce_field('bluevpn_support_operator_presence_'.(int)$o['id']);
                echo '<button class="bvs-btn '.($online?'bvs-btn-danger':'bvs-btn-soft').'">'.($online?'آفلاین':'آنلاین').'</button></form></div>';
            }
            echo '</div></div>';
        }

        echo '</div></aside>';
        echo '</div></div>';

        echo '<script>
        (function(){
            var body=document.getElementById("bvs-chat-body");
            if(body){body.scrollTop=body.scrollHeight;}
        })();
        </script>';

        BlueVPN_Unified_UI::shell_close();
    }

    public static function admin_reply(): void {
        if(!current_user_can('manage_options'))wp_die('Forbidden',403);
        $cid=(int)($_POST['conversation_id']??0);
        check_admin_referer('bluevpn_support_reply_'.$cid);
        try{$msg=self::clean_message(wp_unslash($_POST['message']??''));}
        catch(Throwable $e){wp_die(esc_html($e->getMessage()));}
        self::operator_reply($cid,$msg,get_current_user_id(),'wordpress');
        wp_safe_redirect(admin_url('admin.php?page=bluevpn-support&conversation='.$cid));exit;
    }

    public static function admin_assign(): void {
        if(!current_user_can('manage_options'))wp_die('Forbidden',403);
        $cid=(int)($_POST['conversation_id']??0);
        check_admin_referer('bluevpn_support_assign_'.$cid);
        global $wpdb;
        $wpdb->update(self::table('conversations'),[
            'operator_id'=>max(0,(int)($_POST['operator_id']??0)),
            'department_id'=>max(1,(int)($_POST['department_id']??1)),
            'updated_at'=>BlueVPN_Utils::now_mysql(),
        ],['id'=>$cid]);
        wp_safe_redirect(admin_url('admin.php?page=bluevpn-support&conversation='.$cid));exit;
    }
    public static function admin_status(): void {
        if(!current_user_can('manage_options'))wp_die('Forbidden',403);
        $cid=(int)($_POST['conversation_id']??0);
        check_admin_referer('bluevpn_support_status_'.$cid);
        $status=sanitize_key((string)($_POST['status']??'open'));
        self::set_status($cid,$status,'operator',get_current_user_id());
        wp_safe_redirect(admin_url('admin.php?page=bluevpn-support&conversation='.$cid));exit;
    }
    public static function admin_department_save(): void {
        if(!current_user_can('manage_options'))wp_die('Forbidden',403);
        check_admin_referer('bluevpn_support_department_save');
        $name=sanitize_text_field(wp_unslash($_POST['name']??''));
        if($name!==''){
            global $wpdb;$now=BlueVPN_Utils::now_mysql();
            $slug=sanitize_title($name).'-'.substr(hash('sha256',$name.microtime(true)),0,6);
            $wpdb->insert(self::table('departments'),['name'=>$name,'slug'=>$slug,'description'=>sanitize_text_field(wp_unslash($_POST['description']??'')),'active'=>1,'sort_order'=>100,'first_response_minutes'=>max(5,(int)($_POST['first_response_minutes']??30)),'resolution_minutes'=>max(30,(int)($_POST['resolution_minutes']??1440)),'created_at'=>$now,'updated_at'=>$now]);
        }
        wp_safe_redirect(admin_url('admin.php?page=bluevpn-support'));exit;
    }
    public static function admin_operator_save(): void {
        if(!current_user_can('manage_options'))wp_die('Forbidden',403);
        check_admin_referer('bluevpn_support_operator_save');
        $name=sanitize_text_field(wp_unslash($_POST['display_name']??''));
        if($name!==''){
            global $wpdb;$now=BlueVPN_Utils::now_mysql();
            $departmentIds=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['department_ids']??[])))));
            $wpdb->insert(self::table('operators'),['wp_user_id'=>max(0,(int)($_POST['wp_user_id']??0)),'display_name'=>$name,'department_ids'=>implode(',',$departmentIds),'online'=>1,'max_active'=>20,'created_at'=>$now,'updated_at'=>$now]);
        }
        wp_safe_redirect(admin_url('admin.php?page=bluevpn-support'));exit;
    }

    public static function admin_note(): void {
        if(!current_user_can('manage_options'))wp_die('Forbidden',403);
        $cid=(int)($_POST['conversation_id']??0);
        check_admin_referer('bluevpn_support_note_'.$cid);
        $body=trim(wp_strip_all_tags((string)wp_unslash($_POST['note']??'')));
        if($body!==''){
            global $wpdb;
            $wpdb->insert(self::table('notes'),[
                'conversation_id'=>$cid,'operator_id'=>get_current_user_id(),
                'body'=>mb_substr($body,0,4000),'created_at'=>BlueVPN_Utils::now_mysql(),
            ]);
            self::event($cid,'operator',get_current_user_id(),'internal_note_added');
        }
        wp_safe_redirect(admin_url('admin.php?page=bluevpn-support&conversation='.$cid));exit;
    }

    public static function admin_canned_save(): void {
        if(!current_user_can('manage_options'))wp_die('Forbidden',403);
        check_admin_referer('bluevpn_support_canned_save');
        $title=sanitize_text_field(wp_unslash($_POST['title']??''));
        $body=trim(wp_strip_all_tags((string)wp_unslash($_POST['body']??'')));
        if($title!==''&&$body!==''){
            global $wpdb;$now=BlueVPN_Utils::now_mysql();
            $wpdb->insert(self::table('canned_replies'),[
                'department_id'=>max(0,(int)($_POST['department_id']??0)),
                'title'=>mb_substr($title,0,120),'body'=>mb_substr($body,0,4000),
                'active'=>1,'created_at'=>$now,'updated_at'=>$now,
            ]);
        }
        wp_safe_redirect(admin_url('admin.php?page=bluevpn-support'));exit;
    }

    public static function admin_operator_presence(): void {
        if(!current_user_can('manage_options'))wp_die('Forbidden',403);
        $id=(int)($_POST['operator_id']??0);
        check_admin_referer('bluevpn_support_operator_presence_'.$id);
        global $wpdb;
        $online=!empty($_POST['online'])?1:0;
        $wpdb->update(self::table('operators'),[
            'online'=>$online,
            'last_seen_at'=>$online?BlueVPN_Utils::now_mysql():null,
            'updated_at'=>BlueVPN_Utils::now_mysql(),
        ],['id'=>$id]);
        wp_safe_redirect(admin_url('admin.php?page=bluevpn-support'));exit;
    }

    public static function admin_attachment(): void {
        if(!current_user_can('manage_options'))wp_die('Forbidden',403);
        $cid=(int)($_POST['conversation_id']??0);
        check_admin_referer('bluevpn_support_attachment_'.$cid);
        $file=$_FILES['attachment']??null;
        if(!is_array($file)||!empty($file['error'])||empty($file['tmp_name'])) {
            wp_safe_redirect(admin_url('admin.php?page=bluevpn-support&conversation='.$cid));exit;
        }
        $raw=@file_get_contents((string)$file['tmp_name']);
        if($raw===false)wp_die('فایل خوانده نشد');
        $name=self::safe_attachment_name((string)($file['name']??'attachment'));
        $mime=sanitize_mime_type((string)($file['type']??'application/octet-stream'));
        try {
            $mid=self::insert_message($cid,'operator',get_current_user_id(),'📎 '.$name,'attachment');
            try {
                $aid=self::save_base64_attachment($cid,$mid,'operator',get_current_user_id(),$name,$mime,base64_encode($raw));
            } catch(Throwable $e) {
                global $wpdb;
                $wpdb->delete(self::table('messages'),['id'=>$mid,'conversation_id'=>$cid]);
                throw $e;
            }
            global $wpdb;$c=self::conversation_row($cid);$now=BlueVPN_Utils::now_mysql();
            $firstResponse=empty($c['first_response_at'])?$now:(string)$c['first_response_at'];
            $wpdb->query($wpdb->prepare(
                "UPDATE ".self::table('conversations')." SET unread_customer=unread_customer+1,unread_operator=0,status='pending_customer',first_response_at=%s,last_message_at=%s,updated_at=%s WHERE id=%d",
                $firstResponse,$now,$now,$cid
            ));
            self::event($cid,'operator',get_current_user_id(),'attachment_added',['attachment_id'=>$aid]);
        } catch(Throwable $e) {
            wp_die(esc_html($e->getMessage()));
        }
        wp_safe_redirect(admin_url('admin.php?page=bluevpn-support&conversation='.$cid));exit;
    }

    public static function operator_reply(int $cid,string $message,int $operatorId=0,string $source='wordpress'): bool {
        $c=self::conversation_row($cid); if(!$c)return false;
        self::insert_message($cid,'operator',$operatorId,self::clean_message($message));
        global $wpdb;
        $now=BlueVPN_Utils::now_mysql();
        $firstResponse=empty($c['first_response_at'])?$now:(string)$c['first_response_at'];
        $wpdb->query($wpdb->prepare(
            "UPDATE ".self::table('conversations')." SET unread_customer=unread_customer+1,unread_operator=0,status='pending_customer',first_response_at=%s,last_message_at=%s,updated_at=%s WHERE id=%d",
            $firstResponse,$now,$now,$cid
        ));
        self::event($cid,'operator',$operatorId,'operator_reply',['source'=>$source]);
        return true;
    }

    private static function customer_label(array $customer): string {
        foreach(['phone','email','username','id'] as $k){
            $v=trim((string)($customer[$k]??''));if($v!=='')return $v;
        }
        return 'customer';
    }

    private static function telegram_notify_new(int $cid,array $customer,array $dept,string $message): void {
        if(!class_exists('BlueVPN_Telegram_Bot'))return;
        BlueVPN_Telegram_Bot::support_notify(
            "💬 <b>گفتگوی جدید پشتیبانی</b>\n".
            "شناسه: <code>#{$cid}</code>\n".
            "کاربر: <code>".esc_html(self::customer_label($customer))."</code>\n".
            "بخش: <b>".esc_html((string)$dept['name'])."</b>\n\n".
            esc_html(mb_substr($message,0,1800)).
            "\n\nپاسخ: <code>/support_reply {$cid} متن پاسخ</code>"
        );
    }
    private static function telegram_notify_reply(int $cid,array $customer,string $message): void {
        if(!class_exists('BlueVPN_Telegram_Bot'))return;
        BlueVPN_Telegram_Bot::support_notify(
            "💬 <b>پیام جدید کاربر</b> <code>#{$cid}</code>\n".
            "کاربر: <code>".esc_html(self::customer_label($customer))."</code>\n\n".
            esc_html(mb_substr($message,0,1800)).
            "\n\n<code>/support_reply {$cid} متن پاسخ</code>"
        );
    }

    public static function telegram_reply_command(string $text,int $telegramUserId): bool {
        if(!preg_match('/^\/support_reply\s+(\d+)\s+(.+)$/us',trim($text),$m))return false;
        $cid=(int)$m[1];
        try{$message=self::clean_message($m[2]);}
        catch(Throwable $e){return true;}
        return self::operator_reply($cid,$message,$telegramUserId,'telegram');
    }
}
