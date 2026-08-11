<?php
if (!defined('ABSPATH')) exit;

final class BlueVPN_Payments {
    private const PENDING = ['', 'pending', 'created', 'creating', 'creating_invoice', 'processing', 'waiting', 'unpaid'];
    private const FAILED = ['failed', 'canceled', 'cancelled', 'expired', 'rejected', 'refunded', 'amount_mismatch'];

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
        foreach (['data', 'invoice', 'payment', 'result'] as $key) if (is_array($payload[$key] ?? null)) $merged = array_merge($merged, $payload[$key]);
        foreach (['payment_id', 'invoice_id', 'id', 'uuid', 'token'] as $key) if (!empty($merged[$key])) { $merged['payment_id'] = trim((string)$merged[$key]); break; }
        foreach (['payment_url', 'checkout_url', 'redirect_url', 'pay_url', 'url', 'payment_link'] as $key) if (!empty($merged[$key])) { $merged['payment_url'] = trim((string)$merged[$key]); break; }
        foreach (['status', 'payment_status', 'invoice_status', 'state'] as $key) if (isset($merged[$key]) && trim((string)$merged[$key]) !== '') { $merged['status'] = trim((string)$merged[$key]); break; }
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
            'method' => strtoupper($method), 'timeout' => 25, 'redirection' => 0,
            'headers' => ['Accept'=>'application/json','Content-Type'=>'application/json','X-API-Key'=>$apiKey,'User-Agent'=>'BlueVPN-WordPress/4.0.19'],
        ];
        if ($idempotency !== '') $args['headers']['Idempotency-Key'] = $idempotency;
        if ($body !== null) $args['body'] = wp_json_encode($body, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $res = wp_remote_request($url, $args);
        if (is_wp_error($res)) throw new RuntimeException('ارتباط با BluePay برقرار نشد: ' . $res->get_error_message());
        $code = (int)wp_remote_retrieve_response_code($res); $raw = (string)wp_remote_retrieve_body($res); $json = json_decode($raw, true); $json = is_array($json) ? $json : ['raw'=>mb_substr($raw,0,1500)];
        if ($code >= 400 || (isset($json['success']) && $json['success'] === false)) {
            $msg = self::provider_message($json);
            if (in_array($code,[401,403],true)) throw new RuntimeException('API Key فروشگاه BluePay نامعتبر است.');
            if ($code===429) throw new RuntimeException('محدودیت تعداد درخواست BluePay فعال شده است.');
            throw new RuntimeException($msg !== '' ? 'BluePay: '.$msg : 'BluePay HTTP '.$code);
        }
        return self::normalize_invoice($json);
    }

    private static function safe_callback(): string {
        $url = home_url('/webhooks/bluepay');
        $p = wp_parse_url($url);
        if (($p['scheme'] ?? '') !== 'https' || empty($p['host']) || in_array(strtolower((string)$p['host']), ['localhost','127.0.0.1','::1'], true)) return '';
        return $url;
    }

    private static function order_row(string $id, int $customerId): ?array {
        global $wpdb; $t=BlueVPN_DB::table('orders');
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%s AND customer_id=%d LIMIT 1",$id,$customerId),ARRAY_A); return $row?:null;
    }

    private static function parse_remote_amount(array $invoice): ?int {
        // Prefer explicit toman fields; only interpret rial if currency says so.
        foreach (['amount_toman','amountTomans','amount_tomans'] as $k) if (isset($invoice[$k])&&is_numeric($invoice[$k])) return (int)$invoice[$k];
        $currency=strtolower((string)($invoice['currency']??$invoice['unit']??''));
        if(isset($invoice['amount'])&&is_numeric($invoice['amount'])) return in_array($currency,['irr','rial','ریال'],true)?(int)round(((float)$invoice['amount'])/10):(int)$invoice['amount'];
        return null;
    }

    private static function update_from_invoice(array $order, array $invoice): array {
        global $wpdb;
        $t = BlueVPN_DB::table('orders');
        $status = self::normalize_status($invoice['status'] ?? 'pending');

        // Keep BlueVPN activation metadata separate from the latest gateway payload.
        // Polling and Webhook can race; overwriting gateway_json used to erase the
        // idempotency marker and could provision the same paid order twice.
        $meta = BlueVPN_Utils::json_decode_array((string)($order['gateway_json'] ?? ''), []);
        $meta['bluepay_last_invoice'] = $invoice;
        $meta['bluepay_last_checked_at'] = BlueVPN_Utils::iso_now();
        $update = ['gateway_json' => BlueVPN_Utils::json_encode($meta)];

        $remoteAmount = self::parse_remote_amount($invoice);
        if ($remoteAmount !== null && $remoteAmount !== (int)$order['amount_toman']) {
            $status = 'amount_mismatch';
            $update['activation_error'] = 'مبلغ برگشتی BluePay با مبلغ سفارش برابر نیست.';
        }

        $localStatus = (string)($order['status'] ?? '');
        if ($status === 'paid') {
            // Once provisioning started, a later BluePay GET/Webhook must not push
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
        $wpdb->update($t, $update, ['id' => $order['id']]);
        return array_merge($order, $update);
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

            $result = BlueVPN_Providers::provision_customer((int)$order['customer_id'], (int)$order['plan_id']);
            $status = !empty($result['ok']) ? 'activated' : (!empty($result['partial']) ? 'partial_needs_sync' : 'paid_needs_sync');
            $meta['_bluevpn_activation_status'] = $status;
            $meta['_bluevpn_activation_finished_at'] = BlueVPN_Utils::iso_now();
            $update = [
                'status' => $status,
                'gateway_json' => BlueVPN_Utils::json_encode($meta),
                'activation_error' => !empty($result['ok']) ? '' : mb_substr((string)($result['message'] ?? 'فعال‌سازی کامل نشد.'), 0, 2000),
            ];
            if ($status === 'activated') $update['activated_at'] = BlueVPN_Utils::now_mysql();
            $wpdb->update(BlueVPN_DB::table('orders'), $update, ['id' => $order['id']]);
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
        return ['id'=>$order['id'],'order_code'=>$order['order_code'],'payment_id'=>$order['payment_id'],'status'=>$order['status'],'payment_url'=>$order['payment_url'],'amount_toman'=>(int)$order['amount_toman'],'activation_error'=>(string)$order['activation_error'],'created_at'=>BlueVPN_Utils::iso_from_mysql($order['created_at']??null),'created_at_fa'=>BlueVPN_Utils::tehran_datetime_fa($order['created_at']??null),'expires_at'=>BlueVPN_Utils::iso_from_mysql($expires),'expires_at_fa'=>BlueVPN_Utils::tehran_datetime_fa($expires),'checkout_state'=>$checkout,'checkout_opened_at'=>BlueVPN_Utils::iso_from_mysql($order['checkout_opened_at']??null),'checkout_last_seen_at'=>BlueVPN_Utils::iso_from_mysql($order['checkout_last_seen_at']??null),'checkout_closed_at'=>BlueVPN_Utils::iso_from_mysql($order['checkout_closed_at']??null),'abandon_grace_seconds'=>300,'expired'=>$expired,'paid_at'=>BlueVPN_Utils::iso_from_mysql($order['paid_at']??null),'activated_at'=>BlueVPN_Utils::iso_from_mysql($order['activated_at']??null),'calendar'=>'jalali','timezone'=>'Asia/Tehran','account'=>BlueVPN_Auth::account_payload($customer)];
    }

    public static function create(array $customer, array $body): array {
        global $wpdb;
        $planId=(int)($body['plan_id']??0);$pt=BlueVPN_DB::table('plans');$plan=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$pt} WHERE id=%d AND active=1 AND deleted=0 LIMIT 1",$planId),ARRAY_A);if(!$plan)throw new BlueVPN_Auth_Exception(404,'PLAN_NOT_FOUND','پلن پیدا نشد.');
        $pay=self::settings();$key=self::secret($pay,'api_key_enc');$base=untrailingslashit((string)($pay['base_url']??''));if(empty($pay['active'])||$key===''||!wp_http_validate_url($base))throw new BlueVPN_Auth_Exception(503,'PAYMENT_NOT_CONFIGURED','درگاه BluePay در پنل WordPress کامل یا فعال نیست.');
        $amount=(int)$plan['price_toman'];if($amount<1000||$amount>500000000)throw new BlueVPN_Auth_Exception(422,'INVALID_AMOUNT','مبلغ پلن خارج از محدوده مجاز است.');
        // Reuse an existing fresh pending order for the same plan to avoid duplicate invoices on double taps.
        $ot=BlueVPN_DB::table('orders');$fresh=gmdate('Y-m-d H:i:s',time()-120);$existing=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$ot} WHERE customer_id=%d AND plan_id=%d AND status IN ('pending','created','creating_invoice','processing','waiting','unpaid') AND created_at>=%s ORDER BY created_at DESC LIMIT 1",(int)$customer['id'],$planId,$fresh),ARRAY_A);if($existing&&!empty($existing['payment_url']))return ['success'=>true,'reused'=>true,'order'=>self::order_payload($existing),'check_after_success_url'=>'/api/v1/orders/'.$existing['id'].'/check-after-success','poll_interval_seconds'=>5,'poll_timeout_seconds'=>30];
        $id=BlueVPN_Utils::random_uuid4();$code='BV-'.gmdate('Ymd-His').'-'.strtoupper(substr(bin2hex(random_bytes(4)),0,8));$ttl=max(5,min(30,(int)($pay['ttl_minutes']??30)));$now=BlueVPN_Utils::now_mysql();$expires=gmdate('Y-m-d H:i:s',time()+$ttl*MINUTE_IN_SECONDS);
        $wpdb->insert($ot,['id'=>$id,'order_code'=>$code,'customer_id'=>(int)$customer['id'],'plan_id'=>$planId,'amount_toman'=>$amount,'payment_id'=>'','payment_url'=>'','status'=>'creating_invoice','gateway_json'=>'{}','activation_error'=>'','expires_at'=>$expires,'created_at'=>$now]);
        $identity=(string)($customer['phone']?:$customer['email']?:$customer['id']);$payload=['amount_toman'=>$amount,'order_id'=>$code,'description'=>mb_substr('خرید '.$plan['title'].' برای '.$identity,0,500),'fee_mode'=>in_array(($pay['fee_mode']??'default'),['default','merchant','customer','split'],true)?$pay['fee_mode']:'default','ttl_minutes'=>$ttl];$cb=self::safe_callback();if($cb!=='')$payload['callback_url']=$cb;
        try{$invoice=self::request('POST',$base.'/api/v1/invoices',$key,$payload,substr($code.'-create',0,180));}catch(Throwable $e){$wpdb->update($ot,['status'=>'invoice_failed','activation_error'=>mb_substr($e->getMessage(),0,2000)],['id'=>$id]);throw new BlueVPN_Auth_Exception(502,'INVOICE_CREATE_FAILED',$e->getMessage());}
        $paymentId=trim((string)($invoice['payment_id']??''));$url=trim((string)($invoice['payment_url']??''));$status=self::normalize_status($invoice['status']??'pending');$remoteAmount=self::parse_remote_amount($invoice);
        if($remoteAmount!==null&&$remoteAmount!==$amount){$wpdb->update($ot,['status'=>'amount_mismatch','activation_error'=>'مبلغ فاکتور BluePay با سفارش برابر نیست.','gateway_json'=>BlueVPN_Utils::json_encode($invoice)],['id'=>$id]);throw new BlueVPN_Auth_Exception(502,'BLUEPAY_AMOUNT_MISMATCH','مبلغ فاکتور BluePay با مبلغ پلن برابر نیست.');}
        if($paymentId===''||!wp_http_validate_url($url)){ $wpdb->update($ot,['status'=>'invoice_failed','activation_error'=>'BluePay شناسه یا لینک پرداخت معتبر برنگرداند.','gateway_json'=>BlueVPN_Utils::json_encode($invoice)],['id'=>$id]); throw new BlueVPN_Auth_Exception(502,'BLUEPAY_INVALID_RESPONSE','BluePay شناسه یا لینک پرداخت معتبر برنگرداند.'); }
        $wpdb->update($ot,['payment_id'=>mb_substr($paymentId,0,180),'payment_url'=>esc_url_raw($url),'status'=>$status,'gateway_json'=>BlueVPN_Utils::json_encode($invoice),'activation_error'=>''],['id'=>$id]);$order=self::order_row($id,(int)$customer['id']);if($status==='paid')$order=self::activate_if_paid($order);return ['success'=>true,'reused'=>false,'order'=>self::order_payload($order),'check_after_success_url'=>'/api/v1/orders/'.$id.'/check-after-success','poll_interval_seconds'=>5,'poll_timeout_seconds'=>30];
    }

    private static function refresh_remote(array $order): array {
        if(empty($order['payment_id']))return $order;$pay=self::settings();$key=self::secret($pay,'api_key_enc');$base=untrailingslashit((string)($pay['base_url']??''));if($key===''||$base==='')return $order;try{$invoice=self::request('GET',$base.'/api/v1/invoices/'.rawurlencode((string)$order['payment_id']),$key);$order=self::update_from_invoice($order,$invoice);}catch(Throwable $e){global $wpdb;$wpdb->update(BlueVPN_DB::table('orders'),['activation_error'=>mb_substr($e->getMessage(),0,2000)],['id'=>$order['id']]);$order['activation_error']=$e->getMessage();}return self::activate_if_paid($order);
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
        global $wpdb;$pay=self::settings();$secret=self::secret($pay,'callback_secret_enc');$raw=(string)$request->get_body();$signature='';foreach(['x-gateway-signature','x-bluepay-signature','x-signature'] as $h){$signature=trim((string)$request->get_header($h));if($signature!=='')break;}if(str_contains($signature,'=')){$parts=explode('=',$signature,2);if(in_array(strtolower($parts[0]),['sha256','hmac'],true))$signature=trim($parts[1]);}$payload=json_decode($raw,true);$payload=is_array($payload)?$payload:[];$expectedRaw=$secret!==''?hash_hmac('sha256',$raw,$secret):'';$expectedCanonical=$secret!==''?hash_hmac('sha256',self::canonical_json($payload),$secret):'';$valid=$secret!==''&&($signature!==''&&(hash_equals($expectedRaw,$signature)||hash_equals($expectedCanonical,$signature)));if(!$valid)return new WP_REST_Response(['success'=>false,'detail'=>['code'=>'INVALID_SIGNATURE','message'=>'امضای Webhook نامعتبر است']],401);
        $invoice=self::normalize_invoice($payload);$paymentId=trim((string)($invoice['payment_id']??''));$orderCode=trim((string)($invoice['order_id']??$invoice['merchant_order_id']??''));$ot=BlueVPN_DB::table('orders');$order=null;if($paymentId!=='')$order=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$ot} WHERE payment_id=%s LIMIT 1",$paymentId),ARRAY_A);if(!$order&&$orderCode!=='')$order=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$ot} WHERE order_code=%s LIMIT 1",$orderCode),ARRAY_A);
        $delivery='';foreach(['x-gateway-delivery','x-bluepay-delivery'] as $h){$delivery=trim((string)$request->get_header($h));if($delivery!=='')break;}if($delivery==='')$delivery='payment:'.$paymentId.':'.self::normalize_status($invoice['status']??'');$wt=BlueVPN_DB::table('webhook_deliveries');$duplicate=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wt} WHERE delivery_id=%s LIMIT 1",$delivery));if(!$duplicate)$wpdb->insert($wt,['delivery_id'=>mb_substr($delivery,0,180),'payment_id'=>mb_substr($paymentId,0,180),'event'=>mb_substr((string)($invoice['event']??$request->get_header('x-gateway-event')??''),0,80),'created_at'=>BlueVPN_Utils::now_mysql()]);
        if(!$order)return new WP_REST_Response(['success'=>true,'order_found'=>false,'duplicate'=>(bool)$duplicate],200);
        $order=self::update_from_invoice($order,$invoice);$order=self::activate_if_paid($order);return new WP_REST_Response(['success'=>true,'order_found'=>true,'duplicate'=>(bool)$duplicate,'status'=>$order['status']],200);
    }
}
