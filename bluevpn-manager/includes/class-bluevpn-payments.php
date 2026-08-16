<?php
if (!defined('ABSPATH')) exit;

final class BlueVPN_Payments {
    private const PENDING = ['', 'pending', 'created', 'creating', 'creating_invoice', 'processing', 'waiting', 'unpaid'];
    private const FAILED = ['failed', 'canceled', 'cancelled', 'expired', 'rejected', 'refunded', 'amount_mismatch'];
    private const PROVIDER = 'blupal';

    private static function settings(): array {
        global $wpdb;
        $row = $wpdb->get_row('SELECT * FROM ' . BlueVPN_DB::table('payment_settings') . ' WHERE id=1 LIMIT 1', ARRAY_A);
        return $row ?: [];
    }

    private static function secret(array $s, string $key): string {
        return BlueVPN_Utils::decrypt_secret((string)($s[$key] ?? ''));
    }

    private static function normalize_status($value): string {
        $s = strtolower(trim(str_replace(['-', ' '], '_', (string)$value)));
        if (in_array($s, ['paid', 'success', 'successful', 'confirmed', 'completed', 'successfully_paid', 'payment_success'], true)) return 'paid';
        if (in_array($s, ['cancelled', 'canceled'], true)) return 'canceled';
        return $s !== '' ? $s : 'pending';
    }

    private static function normalize_invoice($payload): array {
        if (!is_array($payload)) return [];
        $merged = $payload;
        foreach (['data','invoice','payment','result'] as $key) if (is_array($payload[$key] ?? null)) $merged = array_merge($merged, $payload[$key]);
        if (isset($merged['invoice_id'])) $merged['payment_id'] = trim((string)$merged['invoice_id']);
        elseif (isset($merged['payment_id'])) $merged['payment_id'] = trim((string)$merged['payment_id']);
        if (!empty($merged['payment_link'])) $merged['payment_url'] = trim((string)$merged['payment_link']);
        elseif (!empty($merged['payment_url'])) $merged['payment_url'] = trim((string)$merged['payment_url']);
        if (isset($merged['status'])) $merged['status'] = trim((string)$merged['status']);
        return $merged;
    }

    private static function provider_message($payload): string {
        $p = self::normalize_invoice($payload);
        foreach (['message', 'error', 'detail', 'description'] as $key) {
            $value = $p[$key] ?? '';
            if (is_array($value)) $value = $value['message'] ?? $value['detail'] ?? '';
            $value = trim((string)$value); if ($value !== '') return mb_substr($value, 0, 500);
        }
        return '';
    }

    private static function request(string $method, string $url, string $apiKey, ?array $body = null, string $idempotency = ''): array {
        $args = [
            'method' => strtoupper($method), 'timeout' => 12, 'redirection' => 0,
            'headers' => ['Accept'=>'application/json','Content-Type'=>'application/json','X-API-Key'=>$apiKey,'User-Agent'=>'BlueVPN-WordPress/'.BLUEVPN_MANAGER_VERSION],
        ];
        if ($idempotency !== '') $args['headers']['Idempotency-Key'] = $idempotency;
        if ($body !== null) $args['body'] = wp_json_encode($body, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $res = wp_remote_request($url, $args);
        if (is_wp_error($res)) throw new RuntimeException('ارتباط با BluPal برقرار نشد: ' . $res->get_error_message());
        $code = (int)wp_remote_retrieve_response_code($res); $raw = (string)wp_remote_retrieve_body($res); $json = json_decode($raw, true); $json = is_array($json) ? $json : ['raw'=>mb_substr($raw,0,1500)];
        if ($code >= 400 || (isset($json['success']) && $json['success'] === false)) {
            $msg = self::provider_message($json);
            if (in_array($code,[401,403],true)) throw new RuntimeException('API Key بلوپال نامعتبر است.');
            if ($code===429) throw new RuntimeException('محدودیت تعداد درخواست بلوپال فعال شده است.');
            throw new RuntimeException($msg !== '' ? 'BluPal: '.$msg : 'BluPal HTTP '.$code);
        }
        return self::normalize_invoice($json);
    }

    public static function webhook_url(): string {
        return home_url('/api/v1/webhooks/blupal');
    }

    private static function order_row(string $id, int $customerId): ?array {
        global $wpdb; $t=BlueVPN_DB::table('orders');
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%s AND customer_id=%d LIMIT 1",$id,$customerId),ARRAY_A); return $row?:null;
    }

    private static function parse_remote_amount(array $invoice): ?int {
        // BluPal documents `amount` in Rial. Local plans remain Toman.
        if (isset($invoice['amount']) && is_numeric($invoice['amount'])) return (int)round(((float)$invoice['amount']) / 10);
        return null;
    }

    private static function log_payment_event(array $order,array $invoice,string $event,bool $verified): void {
        global $wpdb;
        $wpdb->insert(BlueVPN_DB::table('payment_events'),[
            'order_id'=>(string)($order['id']??''),
            'provider'=>self::PROVIDER,
            'provider_invoice_id'=>mb_substr((string)($invoice['payment_id']??''),0,180),
            'transaction_id'=>mb_substr((string)($invoice['transaction_id']??''),0,180),
            'event_type'=>mb_substr($event,0,80),
            'status'=>mb_substr(self::normalize_status($invoice['status']??''),0,40),
            'amount_rial'=>(int)($invoice['amount']??0),
            'final_amount_rial'=>(int)($invoice['final_amount']??0),
            'verified'=>$verified?1:0,
            'payload_json'=>BlueVPN_Utils::json_encode($invoice),
            'created_at'=>BlueVPN_Utils::now_mysql(),
        ]);
    }

    private static function update_from_invoice(array $order, array $invoice): array {
        global $wpdb;
        $t = BlueVPN_DB::table('orders');
        $status = self::normalize_status($invoice['status'] ?? 'pending');

        // Keep BlueVPN activation metadata separate from the latest gateway payload.
        // Polling and Webhook can race; overwriting gateway_json used to erase the
        // idempotency marker and could provision the same paid order twice.
        $meta = BlueVPN_Utils::json_decode_array((string)($order['gateway_json'] ?? ''), []);
        $meta['blupal_last_invoice'] = $invoice;
        $meta['blupal_last_checked_at'] = BlueVPN_Utils::iso_now();
        $update = ['gateway_json' => BlueVPN_Utils::json_encode($meta)];

        $remoteAmount = self::parse_remote_amount($invoice);
        if ($remoteAmount !== null && $remoteAmount !== (int)$order['amount_toman']) {
            $status = 'amount_mismatch';
            $update['activation_error'] = 'مبلغ برگشتی بلوپال با مبلغ سفارش برابر نیست.';
        }

        $localStatus = (string)($order['status'] ?? '');
        if ($status === 'paid') {
            // Once provisioning started, a later BluPal GET/Webhook must not push
            // the order back to plain "paid" and accidentally trigger it again.
            if (in_array($localStatus, ['activated', 'partial_needs_sync', 'paid_needs_sync'], true)) {
                $update['status'] = $localStatus;
            } else {
                $update['status'] = 'paid';
                $update['activation_error'] = '';
            }
            $update['paid_at'] = !empty($order['paid_at']) ? $order['paid_at'] : BlueVPN_Utils::now_mysql();
        } elseif (in_array($status, self::FAILED, true)) {
            // A terminal local activation state wins over a stale/duplicated
            // gateway event; payment amount/signature were already verified.
            if (in_array($localStatus, ['activated', 'partial_needs_sync', 'paid_needs_sync'], true)) {
                $update['status'] = $localStatus;
            } else {
                $update['status'] = $status;
                $update['activation_error'] = !empty($order['activation_error']) ? $order['activation_error'] : 'پرداخت توسط درگاه نهایی نشد.';
            }
        } else {
            if (!in_array($localStatus, ['activated', 'partial_needs_sync', 'paid_needs_sync'], true)) $update['status'] = $status;
            else $update['status'] = $localStatus;
        }

        if (!empty($invoice['payment_id'])) $update['payment_id'] = mb_substr((string)$invoice['payment_id'], 0, 180);
        if (!empty($invoice['payment_url']) && wp_http_validate_url((string)$invoice['payment_url'])) $update['payment_url'] = esc_url_raw((string)$invoice['payment_url']);
        $update['payment_provider'] = self::PROVIDER;
        if (isset($invoice['mode'])) $update['payment_mode'] = mb_substr((string)$invoice['mode'],0,20);
        if (isset($invoice['amount'])) $update['amount_rial'] = (int)$invoice['amount'];
        if (isset($invoice['final_amount'])) $update['final_amount_rial'] = (int)$invoice['final_amount'];
        if (isset($invoice['transaction_id'])) $update['transaction_id'] = mb_substr((string)$invoice['transaction_id'],0,180);
        if (isset($invoice['payer_name'])) $update['payer_name'] = mb_substr((string)$invoice['payer_name'],0,180);
        if (isset($invoice['payer_card'])) $update['payer_card'] = mb_substr((string)$invoice['payer_card'],0,80);
        if (isset($invoice['payer_bank_name'])) $update['payer_bank_name'] = mb_substr((string)$invoice['payer_bank_name'],0,180);
        $wpdb->update($t, $update, ['id' => $order['id']]);
        $merged = array_merge($order, $update);
        if (class_exists('BlueVPN_SMS_Notifications') && in_array((string)($merged['status'] ?? ''), self::FAILED, true) && !in_array($localStatus, self::FAILED, true)) {
            try {
                $customer = BlueVPN_Auth::get_customer((int)$order['customer_id']);
                if (!empty($customer['phone'])) {
                    $terminal=(string)$merged['status'];$invoice=mb_substr((string)$order['order_code'],0,40);
                    if($terminal==='refunded') BlueVPN_SMS_Notifications::queue('refund_success',(string)$customer['phone'],['amount'=>(int)$order['amount_toman'],'invoice_id'=>$invoice],(int)$customer['id'],(string)$order['id'],'refund:'.$order['id']);
                    elseif(in_array($terminal,['expired','canceled','cancelled'],true)) BlueVPN_SMS_Notifications::queue('invoice_expired',(string)$customer['phone'],['invoice_id'=>$invoice],(int)$customer['id'],(string)$order['id'],'invoice-expired:'.$order['id']);
                    else BlueVPN_SMS_Notifications::queue('payment_failed',(string)$customer['phone'],['invoice_id'=>$invoice],(int)$customer['id'],(string)$order['id'],'payment-failed-recovery:'.$order['id'].':'.$terminal);
                }
            } catch (Throwable $e) { /* notification must never break payment state */ }
        }
        return $merged;
    }

    private static function activate_if_paid(array $order): array {
        global $wpdb;
        if ((string)$order['status'] !== 'paid' || !empty($order['activated_at'])) return $order;

        // MySQL advisory lock makes Webhook + client polling idempotent even when
        // both requests arrive before either one has written the activation marker.
        $lockName = 'bluevpn_activate_' . substr(hash('sha256', (string)$order['id']), 0, 32);
        $locked = (int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $lockName));
        if ($locked !== 1) return $order;
        try {
            $fresh = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.BlueVPN_DB::table('orders').' WHERE id=%s LIMIT 1', (string)$order['id']), ARRAY_A);
            if ($fresh) $order = $fresh;
            if ((string)$order['status'] !== 'paid' || !empty($order['activated_at'])) return $order;

            $meta = BlueVPN_Utils::json_decode_array((string)($order['gateway_json'] ?? ''), []);
            $paymentId = (string)($order['payment_id'] ?? '');
            $attempted = (string)($meta['_bluevpn_activation_payment_id'] ?? '');
            if ($attempted !== '' && hash_equals($attempted, $paymentId)) {
                $remembered = (string)($meta['_bluevpn_activation_status'] ?? 'paid_needs_sync');
                if (!in_array($remembered, ['activated', 'partial_needs_sync', 'paid_needs_sync'], true)) $remembered = 'paid_needs_sync';
                $update = ['status' => $remembered];
                if ($remembered === 'activated' && empty($order['activated_at'])) $update['activated_at'] = BlueVPN_Utils::now_mysql();
                $wpdb->update(BlueVPN_DB::table('orders'), $update, ['id' => $order['id']]);
                return array_merge($order, $update);
            }

            $meta['_bluevpn_activation_payment_id'] = $paymentId;
            $meta['_bluevpn_activation_attempted_at'] = BlueVPN_Utils::iso_now();
            $wpdb->update(BlueVPN_DB::table('orders'), ['gateway_json' => BlueVPN_Utils::json_encode($meta)], ['id' => $order['id']]);

            $beforeCustomer = null; $beforePlan = null;
            try {
                $beforeCustomer = BlueVPN_Auth::get_customer((int)$order['customer_id']);
                if (!empty($beforeCustomer['plan_id'])) $beforePlan = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.BlueVPN_DB::table('plans').' WHERE id=%d LIMIT 1',(int)$beforeCustomer['plan_id']),ARRAY_A);
            } catch (Throwable $e) { $beforeCustomer = null; }
            $attemptNo=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*)+1 FROM '.BlueVPN_DB::table('provisioning_attempts').' WHERE order_id=%s',(string)$order['id']));
            $wpdb->insert(BlueVPN_DB::table('provisioning_attempts'),[
                'order_id'=>(string)$order['id'],'customer_id'=>(int)$order['customer_id'],'plan_id'=>(int)$order['plan_id'],
                'trigger_source'=>'payment','attempt_no'=>$attemptNo,'status'=>'started','started_at'=>BlueVPN_Utils::now_mysql(),'created_at'=>BlueVPN_Utils::now_mysql(),
            ]);
            $attemptId=(int)$wpdb->insert_id;
            try{
                $result = BlueVPN_Providers::provision_customer((int)$order['customer_id'], (int)$order['plan_id']);
            }catch(Throwable $provisionError){
                $wpdb->update(BlueVPN_DB::table('provisioning_attempts'),['status'=>'failed','error_message'=>mb_substr($provisionError->getMessage(),0,2000),'finished_at'=>BlueVPN_Utils::now_mysql()],['id'=>$attemptId]);
                throw $provisionError;
            }
            $status = !empty($result['ok']) ? 'activated' : (!empty($result['partial']) ? 'partial_needs_sync' : 'paid_needs_sync');
            $wpdb->update(BlueVPN_DB::table('provisioning_attempts'),[
                'status'=>$status==='activated'?'success':($status==='partial_needs_sync'?'partial':'failed'),
                'result_json'=>BlueVPN_Utils::json_encode($result),'error_message'=>!empty($result['ok'])?'':mb_substr((string)($result['message']??''),0,2000),'finished_at'=>BlueVPN_Utils::now_mysql(),
            ],['id'=>$attemptId]);
            $meta['_bluevpn_activation_status'] = $status;
            $meta['_bluevpn_activation_finished_at'] = BlueVPN_Utils::iso_now();
            $update = [
                'status' => $status,
                'gateway_json' => BlueVPN_Utils::json_encode($meta),
                'activation_error' => !empty($result['ok']) ? '' : mb_substr((string)($result['message'] ?? 'فعال‌سازی کامل نشد.'), 0, 2000),
            ];
            if ($status === 'activated') $update['activated_at'] = BlueVPN_Utils::now_mysql();

            // Build outbox jobs before the DB commit. The order state and any SMS
            // rows are then committed together on the same MySQL connection.
            $smsJobs=[];
            if ($status === 'activated' && class_exists('BlueVPN_SMS_Notifications')) {
                try {
                    $customer = BlueVPN_Auth::get_customer((int)$order['customer_id']);
                    $plan = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.BlueVPN_DB::table('plans').' WHERE id=%d LIMIT 1',(int)$order['plan_id']),ARRAY_A);
                    if ($plan && !empty($customer['phone'])) {
                        $event = 'subscription_activated';
                        $samePlan = $beforeCustomer && (int)($beforeCustomer['plan_id'] ?? 0) === (int)$order['plan_id'];
                        $beforeExpiry = $beforeCustomer && !empty($beforeCustomer['subscription_expire']) ? (strtotime((string)$beforeCustomer['subscription_expire'].' UTC') ?: 0) : 0;
                        if ($samePlan && $beforeExpiry > time()) $event = 'subscription_renewed';
                        elseif ($beforeCustomer && !empty($beforeCustomer['plan_id']) && !$samePlan) {
                            $upgraded = $beforePlan && (
                                (int)($plan['price_toman'] ?? 0) > (int)($beforePlan['price_toman'] ?? 0) ||
                                (int)($plan['duration_days'] ?? 0) > (int)($beforePlan['duration_days'] ?? 0) ||
                                (int)($plan['data_limit_gb'] ?? 0) > (int)($beforePlan['data_limit_gb'] ?? 0)
                            );
                            $event = $upgraded ? 'subscription_upgraded' : 'subscription_plan_changed';
                        }
                        $smsJobs[] = [$event,(string)$customer['phone'],[
                            'plan'=>(string)$plan['title'],
                            'expire_date'=>BlueVPN_SMS_Notifications::jalali_date($customer['subscription_expire'] ?? null),
                        ],(int)$customer['id'],(string)$order['id'],'subscription:'.$order['id'].':'.$event];
                        if ((int)($order['amount_toman'] ?? 0) > 0) $smsJobs[] = ['payment_success',(string)$customer['phone'],[
                            'amount'=>(int)$order['amount_toman'],'invoice_id'=>mb_substr((string)$order['order_code'],0,40)
                        ],(int)$customer['id'],(string)$order['id'],'payment-success:'.$order['id']];
                    }
                } catch (Throwable $e) { error_log('BlueVPN SMS activation preparation: '.$e->getMessage()); }
            }

            $wakeSms=false;
            $wpdb->query('START TRANSACTION');
            try {
                $saved=$wpdb->update(BlueVPN_DB::table('orders'), $update, ['id' => $order['id']]);
                if($saved===false) throw new RuntimeException('ذخیره وضعیت فعال‌سازی سفارش انجام نشد.');
                foreach($smsJobs as $job){
                    try{
                        if(BlueVPN_SMS_Notifications::queue($job[0],$job[1],$job[2],$job[3],$job[4],$job[5],false,false)!==null)$wakeSms=true;
                    }catch(Throwable $smsError){error_log('BlueVPN transactional activation SMS: '.$smsError->getMessage());}
                }
                $wpdb->query('COMMIT');
            } catch(Throwable $e) {
                $wpdb->query('ROLLBACK');
                throw $e;
            }
            if($wakeSms&&class_exists('BlueVPN_SMS_Notifications'))BlueVPN_SMS_Notifications::wake_queue();
            return array_merge($order, $update);
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
        }
    }

    public static function order_payload(array $order): array {
        $customer=BlueVPN_Auth::get_customer((int)$order['customer_id']);
        $expires=$order['expires_at']?:gmdate('Y-m-d H:i:s',(strtotime($order['created_at'].' UTC')?:time())+30*MINUTE_IN_SECONDS);
        $expired=in_array((string)$order['status'],['expired','expired_local','abandoned','canceled','cancelled'],true)||(in_array((string)$order['status'],self::PENDING,true)&&(strtotime($expires.' UTC')?:0)<=time());
        $checkout=!empty($order['checkout_closed_at'])?'closed':(!empty($order['checkout_opened_at'])?'open':'created');
        return ['id'=>$order['id'],'order_code'=>$order['order_code'],'payment_id'=>$order['payment_id'],'status'=>$order['status'],'payment_url'=>$order['payment_url'],'payment_provider'=>(string)($order['payment_provider']??self::PROVIDER),'payment_mode'=>(string)($order['payment_mode']??''),'amount_toman'=>(int)$order['amount_toman'],'amount_rial'=>(int)($order['amount_rial']??0),'final_amount_rial'=>(int)($order['final_amount_rial']??0),'transaction_id'=>(string)($order['transaction_id']??''),'payer_name'=>(string)($order['payer_name']??''),'payer_card'=>(string)($order['payer_card']??''),'payer_bank_name'=>(string)($order['payer_bank_name']??''),'activation_error'=>(string)$order['activation_error'],'created_at'=>BlueVPN_Utils::iso_from_mysql($order['created_at']??null),'created_at_fa'=>BlueVPN_Utils::tehran_datetime_fa($order['created_at']??null),'expires_at'=>BlueVPN_Utils::iso_from_mysql($expires),'expires_at_fa'=>BlueVPN_Utils::tehran_datetime_fa($expires),'checkout_state'=>$checkout,'checkout_opened_at'=>BlueVPN_Utils::iso_from_mysql($order['checkout_opened_at']??null),'checkout_last_seen_at'=>BlueVPN_Utils::iso_from_mysql($order['checkout_last_seen_at']??null),'checkout_closed_at'=>BlueVPN_Utils::iso_from_mysql($order['checkout_closed_at']??null),'abandon_grace_seconds'=>300,'expired'=>$expired,'paid_at'=>BlueVPN_Utils::iso_from_mysql($order['paid_at']??null),'activated_at'=>BlueVPN_Utils::iso_from_mysql($order['activated_at']??null),'calendar'=>'jalali','timezone'=>'Asia/Tehran','account'=>BlueVPN_Auth::account_payload($customer)];
    }

    public static function create(array $customer, array $body): array {
        global $wpdb;
        $planId=(int)($body['plan_id']??0);
        $pt=BlueVPN_DB::table('plans');
        $plan=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$pt} WHERE id=%d AND active=1 AND deleted=0 LIMIT 1",$planId),ARRAY_A);
        if(!$plan)throw new BlueVPN_Auth_Exception(404,'PLAN_NOT_FOUND','پلن پیدا نشد.');

        $pay=self::settings();
        $key=self::secret($pay,'api_key_enc');
        $base=untrailingslashit((string)($pay['base_url']??'https://blupal.net/api'));
        if(empty($pay['active'])||$key===''||!wp_http_validate_url($base))throw new BlueVPN_Auth_Exception(503,'PAYMENT_NOT_CONFIGURED','درگاه بلوپال در پنل کامل یا فعال نیست.');

        $amountToman=(int)$plan['price_toman'];
        $amountRial=$amountToman*10;
        if($amountRial<100000)throw new BlueVPN_Auth_Exception(422,'INVALID_AMOUNT','حداقل مبلغ بلوپال ۱۰۰٬۰۰۰ ریال است.');

        $ot=BlueVPN_DB::table('orders');
        $fresh=gmdate('Y-m-d H:i:s',time()-120);
        $existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$ot} WHERE customer_id=%d AND plan_id=%d AND status IN ('pending','created','creating_invoice','processing','waiting','unpaid') AND created_at>=%s ORDER BY created_at DESC LIMIT 1",(int)$customer['id'],$planId,$fresh),ARRAY_A);
        if($existing&&!empty($existing['payment_url']))return ['success'=>true,'reused'=>true,'order'=>self::order_payload($existing),'check_after_success_url'=>'/api/v1/orders/'.$existing['id'].'/check-after-success','poll_interval_seconds'=>5,'poll_timeout_seconds'=>45];

        $id=BlueVPN_Utils::random_uuid4();
        $code='BV-'.gmdate('Ymd-His').'-'.strtoupper(substr(bin2hex(random_bytes(4)),0,8));
        $now=BlueVPN_Utils::now_mysql();
        // BluPal live invoices may have no expires_at; sandbox currently expires around 30 minutes.
        $expires=gmdate('Y-m-d H:i:s',time()+30*MINUTE_IN_SECONDS);
        $wpdb->insert($ot,[
            'id'=>$id,'order_code'=>$code,'customer_id'=>(int)$customer['id'],'plan_id'=>$planId,
            'amount_toman'=>$amountToman,'amount_rial'=>$amountRial,'payment_provider'=>self::PROVIDER,
            'payment_id'=>'','payment_url'=>'','status'=>'creating_invoice','gateway_json'=>'{}','activation_error'=>'','expires_at'=>$expires,'created_at'=>$now,
        ]);

        $payload=['amount'=>$amountRial];
        $card=preg_replace('/\D+/','',(string)($pay['card_number']??''));
        if($card!=='')$payload['card_number']=$card;
        try{
            $invoice=self::request('POST',$base.'/v1/invoices/create',$key,$payload);
        }catch(Throwable $e){
            $wpdb->update($ot,['status'=>'invoice_failed','activation_error'=>mb_substr($e->getMessage(),0,2000)],['id'=>$id]);
            throw new BlueVPN_Auth_Exception(502,'INVOICE_CREATE_FAILED',$e->getMessage());
        }
        $paymentId=trim((string)($invoice['payment_id']??''));
        $url=trim((string)($invoice['payment_url']??''));
        $status=self::normalize_status($invoice['status']??'pending');
        $remoteAmount=self::parse_remote_amount($invoice);
        if($remoteAmount!==null&&$remoteAmount!==$amountToman){
            $wpdb->update($ot,['status'=>'amount_mismatch','activation_error'=>'مبلغ فاکتور بلوپال با سفارش برابر نیست.','gateway_json'=>BlueVPN_Utils::json_encode($invoice)],['id'=>$id]);
            throw new BlueVPN_Auth_Exception(502,'BLUPAL_AMOUNT_MISMATCH','مبلغ فاکتور بلوپال با مبلغ پلن برابر نیست.');
        }
        if($paymentId===''||!wp_http_validate_url($url)){
            $wpdb->update($ot,['status'=>'invoice_failed','activation_error'=>'بلوپال شناسه یا لینک پرداخت معتبر برنگرداند.','gateway_json'=>BlueVPN_Utils::json_encode($invoice)],['id'=>$id]);
            throw new BlueVPN_Auth_Exception(502,'BLUPAL_INVALID_RESPONSE','بلوپال شناسه یا لینک پرداخت معتبر برنگرداند.');
        }
        $update=[
            'payment_id'=>mb_substr($paymentId,0,180),'payment_url'=>esc_url_raw($url),'status'=>$status,
            'payment_mode'=>mb_substr((string)($invoice['mode']??''),0,20),'amount_rial'=>(int)($invoice['amount']??$amountRial),
            'final_amount_rial'=>(int)($invoice['final_amount']??0),'gateway_json'=>BlueVPN_Utils::json_encode(['blupal_last_invoice'=>$invoice]),'activation_error'=>'',
        ];
        if(!empty($invoice['expires_at']))$update['expires_at']=BlueVPN_Utils::mysql_from_iso((string)$invoice['expires_at']);
        $wpdb->update($ot,$update,['id'=>$id]);
        $order=self::order_row($id,(int)$customer['id']);
        self::log_payment_event($order,$invoice,'invoice.created',true);
        if(class_exists('BlueVPN_SMS_Notifications')&&!empty($customer['phone'])){
            try{BlueVPN_SMS_Notifications::queue('invoice_created',(string)$customer['phone'],['invoice_id'=>mb_substr($code,0,40),'amount'=>$amountToman],(int)$customer['id'],$id,'invoice-created:'.$id);}catch(Throwable $ignore){}
        }
        if($status==='paid')$order=self::activate_if_paid($order);
        return ['success'=>true,'reused'=>false,'order'=>self::order_payload($order),'check_after_success_url'=>'/api/v1/orders/'.$id.'/check-after-success','poll_interval_seconds'=>5,'poll_timeout_seconds'=>45];
    }

    private static function refresh_remote(array $order): array {
        if(empty($order['payment_id']))return $order;$pay=self::settings();$key=self::secret($pay,'api_key_enc');$base=untrailingslashit((string)($pay['base_url']??''));if($key===''||$base==='')return $order;try{$invoice=self::request('GET',$base.'/v1/invoices/'.rawurlencode((string)$order['payment_id']),$key);$order=self::update_from_invoice($order,$invoice);}catch(Throwable $e){global $wpdb;$wpdb->update(BlueVPN_DB::table('orders'),['activation_error'=>mb_substr($e->getMessage(),0,2000)],['id'=>$order['id']]);$order['activation_error']=$e->getMessage();}return self::activate_if_paid($order);
    }

    public static function get(array $customer,string $id,bool $refresh=true): array {
        $order=self::order_row($id,(int)$customer['id']);if(!$order)throw new BlueVPN_Auth_Exception(404,'ORDER_NOT_FOUND','فاکتور پیدا نشد.');if($refresh&&in_array((string)$order['status'],array_merge(self::PENDING,['paid']),true))$order=self::refresh_remote($order);return ['success'=>true,'order'=>self::order_payload($order)];
    }

    public static function checkout(array $customer,string $id,string $kind): array {
        global $wpdb;$order=self::order_row($id,(int)$customer['id']);if(!$order)throw new BlueVPN_Auth_Exception(404,'ORDER_NOT_FOUND','فاکتور پیدا نشد.');$now=BlueVPN_Utils::now_mysql();$field=$kind==='open'?'checkout_opened_at':($kind==='heartbeat'?'checkout_last_seen_at':'checkout_closed_at');$wpdb->update(BlueVPN_DB::table('orders'),[$field=>$now],['id'=>$id]);$order[$field]=$now;return ['success'=>true,'close_grace_seconds'=>300,'order'=>self::order_payload($order)];
    }

    private static function canonical_json(array $payload): string {
        $sort=function(&$value)use(&$sort){if(!is_array($value))return;if(array_keys($value)!==range(0,count($value)-1))ksort($value);foreach($value as &$v)$sort($v);};$copy=$payload;$sort($copy);return wp_json_encode($copy,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }

    public static function webhook(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $payload=$request->get_json_params();
        $payload=is_array($payload)?$payload:[];
        $invoice=self::normalize_invoice($payload);
        $event=(string)($payload['event']??'');
        $paymentId=trim((string)($invoice['payment_id']??''));
        if($event!=='payment.completed'||$paymentId==='')return new WP_REST_Response(['received'=>false,'error'=>'invalid_event'],400);

        $ot=BlueVPN_DB::table('orders');
        $order=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$ot} WHERE payment_id=%s LIMIT 1",$paymentId),ARRAY_A);
        if(!$order){
            return new WP_REST_Response(['received'=>true,'order_found'=>false],200);
        }

        // BluPal's public documentation does not specify a webhook signature.
        // Never activate from the webhook body alone: verify the invoice again
        // server-to-server with our X-API-Key and trust only that response.
        $pay=self::settings();
        $key=self::secret($pay,'api_key_enc');
        $base=untrailingslashit((string)($pay['base_url']??'https://blupal.net/api'));
        try{
            $verified=self::request('GET',$base.'/v1/invoices/'.rawurlencode($paymentId),$key);
        }catch(Throwable $e){
            self::log_payment_event($order,$invoice,'payment.completed.webhook',false);
            return new WP_REST_Response(['received'=>false,'error'=>'verification_failed'],503);
        }
        if(self::normalize_status($verified['status']??'')!=='paid'){
            self::log_payment_event($order,$verified,'payment.completed.unconfirmed',false);
            return new WP_REST_Response(['received'=>true,'verified'=>false],200);
        }
        $remoteAmount=self::parse_remote_amount($verified);
        if($remoteAmount===null||$remoteAmount!==(int)$order['amount_toman']){
            $verified['status']='amount_mismatch';
        }
        $transaction=trim((string)($verified['transaction_id']??''));
        $delivery='blupal:'.$paymentId.':'.($transaction!==''?$transaction:'paid');
        $wt=BlueVPN_DB::table('webhook_deliveries');
        $duplicate=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wt} WHERE delivery_id=%s LIMIT 1",$delivery));
        if($duplicate)return new WP_REST_Response(['received'=>true,'duplicate'=>true,'status'=>$order['status']],200);
        $wpdb->insert($wt,['delivery_id'=>mb_substr($delivery,0,180),'payment_id'=>mb_substr($paymentId,0,180),'event'=>'payment.completed','created_at'=>BlueVPN_Utils::now_mysql()]);
        self::log_payment_event($order,$verified,'payment.completed',true);
        $order=self::update_from_invoice($order,$verified);
        $order=self::activate_if_paid($order);
        return new WP_REST_Response(['received'=>true,'verified'=>true,'order_found'=>true,'status'=>$order['status']],200);
    }

}
