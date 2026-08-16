<?php
if (!defined('ABSPATH')) exit;

/**
 * BlueAI operational control plane.
 *
 * AI detects and recommends; deterministic provider/payment engines perform
 * actual repairs. Every repair is idempotently logged.
 */
final class BlueVPN_AI_Ops {
    private const CRON = 'bluevpn_ai_ops_tick';
    private const LOCK = 'bluevpn_ai_ops_lock';
    private const MAX_REPAIR_PER_RUN = 20;

    public static function init(): void {
        add_action(self::CRON, [self::class, 'tick']);
        add_action('admin_post_bluevpn_ai_ops_run', [self::class, 'admin_run']);
        if (!wp_next_scheduled(self::CRON)) {
            wp_schedule_event(time() + 90, 'hourly', self::CRON);
        }
    }

    public static function tick(): void {
        if (get_transient(self::LOCK)) return;
        set_transient(self::LOCK, '1', 5 * MINUTE_IN_SECONDS);
        try {
            $settings = BlueVPN_DB::settings();
            if (!empty($settings['blueai_enabled']) && (!isset($settings['blueai_anomaly_detection']) || !empty($settings['blueai_anomaly_detection']))) {
                self::detect_route_anomalies();
                self::detect_payment_provisioning_anomalies();
                self::detect_sms_anomalies();
                self::detect_stale_live_sessions();
            }
            if (!empty($settings['blueai_enabled']) && !empty($settings['blueai_auto_heal'])) {
                self::reconcile_customers(self::MAX_REPAIR_PER_RUN);
            }
            self::resolve_stale_incidents();
        } catch (Throwable $e) {
            self::upsert_incident(
                'ops-runtime:' . substr(hash('sha256', $e->getMessage()), 0, 24),
                'ops_runtime',
                'error',
                'global',
                'blueai',
                'خطای Runtime در BlueAI Operations',
                ['error' => mb_substr($e->getMessage(), 0, 500)],
                'لاگ سرور و سلامت دیتابیس/Provider بررسی شود.'
            );
        } finally {
            delete_transient(self::LOCK);
        }
    }

    public static function admin_run(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('bluevpn_ai_ops_run');
        self::tick();
        wp_safe_redirect(add_query_arg([
            'page' => 'bluevpn-control-center',
            'tab' => 'blueai',
            'bluevpn_notice' => rawurlencode('BlueAI Operations اجرا شد.'),
        ], admin_url('admin.php')));
        exit;
    }

    private static function detect_route_anomalies(): void {
        global $wpdb;
        $table = BlueVPN_DB::table('ai_route_aggregates');
        $rows = $wpdb->get_results(
            "SELECT config_key,location_key,location_title,operator,network_type,plan_tier,
                    sample_count,recent_score,score,recent_success_rate,consecutive_failures,
                    average_ping_ms,updated_at
             FROM {$table}
             WHERE sample_count>=5
               AND updated_at>=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)
               AND (recent_score<42 OR consecutive_failures>=3 OR recent_success_rate<0.45)
             ORDER BY recent_score ASC, consecutive_failures DESC
             LIMIT 80",
            ARRAY_A
        );
        foreach ((array)$rows as $r) {
            $scope = implode('|', [
                (string)$r['config_key'], (string)$r['plan_tier'],
                (string)$r['operator'], (string)$r['network_type'],
            ]);
            $severity = ((int)$r['recent_score'] < 25 || (int)$r['consecutive_failures'] >= 5) ? 'error' : 'warning';
            self::upsert_incident(
                'route:' . substr(hash('sha256', $scope), 0, 40),
                'route_degradation',
                $severity,
                'route',
                $scope,
                'افت کیفیت Route: ' . (string)$r['location_title'],
                [
                    'recent_score' => (int)$r['recent_score'],
                    'score' => (int)$r['score'],
                    'samples' => (int)$r['sample_count'],
                    'success_rate' => (float)$r['recent_success_rate'],
                    'consecutive_failures' => (int)$r['consecutive_failures'],
                    'average_ping_ms' => (float)$r['average_ping_ms'],
                    'operator' => (string)$r['operator'],
                    'network_type' => (string)$r['network_type'],
                    'plan_tier' => (string)$r['plan_tier'],
                ],
                'Route موقتاً کم‌امتیاز/قرنطینه شود و Candidate سالم بعدی در اولویت قرار گیرد.'
            );
        }
    }

    private static function detect_sms_anomalies(): void {
        global $wpdb;
        $t = BlueVPN_DB::table('sms_deliveries');
        $rows = $wpdb->get_results(
            "SELECT status,COUNT(*) AS c
             FROM {$t}
             WHERE created_at>=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 6 HOUR)
             GROUP BY status",
            ARRAY_A
        );
        $total=0;$failed=0;
        foreach((array)$rows as $r){
            $count=(int)$r['c'];$total+=$count;
            if(in_array(strtolower((string)$r['status']),['failed','error','rejected'],true))$failed+=$count;
        }
        if($total>=10 && ($failed/$total)>=0.25){
            self::upsert_incident(
                'sms-provider-degradation',
                'sms_delivery_degradation',
                ($failed/$total)>=0.5?'error':'warning',
                'provider','sms',
                'افت نرخ تحویل SMS/OTP',
                ['samples'=>$total,'failed'=>$failed,'failure_rate'=>$failed/max(1,$total)],
                'سلامت Provider پیامک، Pattern و API بررسی شود؛ OTP code یا متن حساس در Incident ذخیره نمی‌شود.'
            );
        }
    }

    private static function detect_stale_live_sessions(): void {
        global $wpdb;
        $t=BlueVPN_DB::table('ai_live_connections');
        $count=(int)$wpdb->get_var(
            "SELECT COUNT(*) FROM {$t}
             WHERE connected=1 AND last_seen_at<DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)"
        );
        if($count>0){
            self::upsert_incident(
                'android-background-session-stale',
                'background_session_loss',
                $count>=5?'error':'warning',
                'runtime','android',
                'Heartbeat اتصال‌های Android در پس‌زمینه متوقف شده',
                ['stale_sessions'=>$count],
                'Foreground service، battery optimization، process kill و network handover بررسی شود.'
            );
        }
    }

    private static function detect_payment_provisioning_anomalies(): void {
        global $wpdb;
        $orders = BlueVPN_DB::table('orders');
        $rows = $wpdb->get_results(
            "SELECT id,customer_id,plan_id,status,paid_at,activated_at,activation_error
             FROM {$orders}
             WHERE status IN ('paid_needs_sync','partial_needs_sync')
               AND paid_at IS NOT NULL
               AND paid_at>=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)
             ORDER BY paid_at ASC
             LIMIT 100",
            ARRAY_A
        );
        foreach ((array)$rows as $r) {
            self::upsert_incident(
                'provision-order:' . substr(hash('sha256', (string)$r['id']), 0, 40),
                'payment_provisioning_gap',
                'error',
                'order',
                (string)$r['id'],
                'پرداخت موفق ولی Provisioning کامل نشده',
                [
                    'order_id' => (string)$r['id'],
                    'customer_id' => (int)$r['customer_id'],
                    'plan_id' => (int)$r['plan_id'],
                    'status' => (string)$r['status'],
                    'activation_error' => mb_substr((string)$r['activation_error'], 0, 500),
                ],
                'Reconciliation امن Provider اجرا شود؛ پرداخت دوباره اعمال نشود.'
            );
        }
    }

    private static function reconcile_customers(int $limit): void {
        if (!class_exists('BlueVPN_Providers')) return;
        $ids = BlueVPN_Providers::repair_candidate_ids_after(0, max(1, min(50, $limit)));
        foreach ((array)$ids as $id) {
            $customerId = (int)$id;
            if ($customerId <= 0) continue;
            $runKey = 'repair:' . $customerId . ':' . gmdate('Y-m-d-H');
            if (self::run_exists($runKey)) continue;
            try {
                $result = BlueVPN_Providers::repair_customer_missing_providers($customerId);
                $outcome = !empty($result['ok']) ? (!empty($result['repaired']) ? 'repaired' : 'healthy') : 'failed';
                self::log_reconciliation(
                    $runKey,
                    $customerId,
                    '',
                    'provider_entitlement',
                    !empty($result['repaired']) ? 'provider_repair' : 'verify',
                    $outcome,
                    (string)($result['message'] ?? '')
                );
                if ($outcome === 'repaired' || $outcome === 'healthy') {
                    self::resolve_scope('customer', (string)$customerId);
                } elseif ($outcome === 'failed') {
                    self::upsert_incident(
                        'provision-customer:' . $customerId,
                        'provider_reconciliation_failed',
                        'error',
                        'customer',
                        (string)$customerId,
                        'ترمیم Provider کاربر ناموفق بود',
                        ['result' => $result],
                        'Credential پنل، Group/Inbound انتخاب‌شده و دسترسی API بررسی شود.'
                    );
                }
            } catch (Throwable $e) {
                self::log_reconciliation($runKey, $customerId, '', 'provider_entitlement', 'provider_repair', 'failed', $e->getMessage());
            }
        }

        // Paid-but-unsynced orders are never charged or activated twice. We only
        // ask the provider reconciliation engine to verify/repair the already-paid entitlement.
        global $wpdb;
        $orders = BlueVPN_DB::table('orders');
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id,customer_id FROM {$orders}
                 WHERE status IN ('paid_needs_sync','partial_needs_sync')
                 ORDER BY paid_at ASC LIMIT %d",
                max(1, min(50, $limit))
            ),
            ARRAY_A
        );
        foreach ((array)$rows as $r) {
            $customerId = (int)$r['customer_id'];
            $orderId = (string)$r['id'];
            $runKey = 'order-repair:' . substr(hash('sha256', $orderId), 0, 48) . ':' . gmdate('Y-m-d-H');
            if (self::run_exists($runKey) || $customerId <= 0) continue;
            try {
                $result = BlueVPN_Providers::repair_customer_missing_providers($customerId);
                $outcome = !empty($result['ok']) ? (!empty($result['repaired']) ? 'repaired' : 'verified') : 'failed';
                self::log_reconciliation($runKey, $customerId, $orderId, 'payment_provisioning_gap', 'provider_repair', $outcome, (string)($result['message'] ?? ''));
                if ($outcome !== 'failed') self::resolve_scope('order', $orderId);
            } catch (Throwable $e) {
                self::log_reconciliation($runKey, $customerId, $orderId, 'payment_provisioning_gap', 'provider_repair', 'failed', $e->getMessage());
            }
        }
    }

    private static function run_exists(string $runKey): bool {
        global $wpdb;
        $t = BlueVPN_DB::table('ai_reconciliation_runs');
        return (bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE run_key=%s LIMIT 1", $runKey));
    }

    private static function log_reconciliation(string $runKey, int $customerId, string $orderId, string $issueType, string $action, string $outcome, string $detail): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO " . BlueVPN_DB::table('ai_reconciliation_runs') . "
             (run_key,customer_id,order_id,issue_type,action_taken,outcome,detail,created_at)
             VALUES (%s,%d,%s,%s,%s,%s,%s,%s)",
            $runKey, $customerId, $orderId, $issueType, $action, $outcome,
            mb_substr($detail, 0, 2000), BlueVPN_Utils::now_mysql()
        ));
    }

    private static function upsert_incident(string $key, string $type, string $severity, string $scopeType, string $scopeKey, string $title, array $evidence, string $action): void {
        global $wpdb;
        $t = BlueVPN_DB::table('ai_incidents');
        $now = BlueVPN_Utils::now_mysql();
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$t}
             (incident_key,incident_type,severity,status,scope_type,scope_key,title,evidence_json,recommended_action,occurrence_count,first_seen_at,last_seen_at)
             VALUES (%s,%s,%s,'open',%s,%s,%s,%s,%s,1,%s,%s)
             ON DUPLICATE KEY UPDATE
                severity=VALUES(severity), status='open', title=VALUES(title),
                evidence_json=VALUES(evidence_json), recommended_action=VALUES(recommended_action),
                occurrence_count=LEAST(100000,occurrence_count+1), last_seen_at=VALUES(last_seen_at), resolved_at=NULL",
            mb_substr($key,0,96), mb_substr($type,0,48), mb_substr($severity,0,16),
            mb_substr($scopeType,0,32), mb_substr($scopeKey,0,120), mb_substr($title,0,180),
            BlueVPN_Utils::json_encode(self::sanitize_evidence($evidence)),
            mb_substr($action,0,2000), $now, $now
        ));
    }

    private static function sanitize_evidence(array $data): array {
        $blocked = ['token','authorization','password','secret','otp','license','subscription_url','payment_data'];
        $walk = static function ($value, $key = '') use (&$walk, $blocked) {
            $lower = strtolower((string)$key);
            foreach ($blocked as $needle) if (str_contains($lower, $needle)) return '<redacted>';
            if (is_array($value)) {
                $out = [];
                foreach ($value as $k => $v) $out[$k] = $walk($v, (string)$k);
                return $out;
            }
            if (is_string($value)) return mb_substr($value, 0, 500);
            if (is_scalar($value) || $value === null) return $value;
            return '<unsupported>';
        };
        return $walk($data);
    }

    public static function observe_connection_outcome(array $event): void {
        if (empty($event['success'])) return;
        global $wpdb;
        $t = BlueVPN_DB::table('ai_incidents');
        $scopeKey = implode('|', [
            (string)($event['config_key'] ?? ''),
            (string)($event['plan_tier'] ?? 'unknown'),
            (string)($event['operator'] ?? 'unknown'),
            (string)($event['network_type'] ?? 'unknown'),
        ]);
        if ($scopeKey === '|||') return;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$t}
             SET status='resolved', resolved_at=%s
             WHERE status='open'
               AND incident_type='route_degradation'
               AND scope_type='route'
               AND scope_key=%s",
            BlueVPN_Utils::now_mysql(),
            $scopeKey
        ));
    }

    private static function resolve_scope(string $scopeType, string $scopeKey): void {
        global $wpdb;
        $t = BlueVPN_DB::table('ai_incidents');
        $wpdb->update($t, [
            'status' => 'resolved',
            'resolved_at' => BlueVPN_Utils::now_mysql(),
        ], [
            'scope_type' => $scopeType,
            'scope_key' => $scopeKey,
            'status' => 'open',
        ]);
    }

    private static function resolve_stale_incidents(): void {
        global $wpdb;
        $t = BlueVPN_DB::table('ai_incidents');
        $wpdb->query(
            "UPDATE {$t}
             SET status='resolved', resolved_at=UTC_TIMESTAMP()
             WHERE status='open'
               AND incident_type='route_degradation'
               AND last_seen_at<DATE_SUB(UTC_TIMESTAMP(), INTERVAL 6 HOUR)"
        );
    }

    public static function recommend_panel_id(string $type): int {
        global $wpdb;
        $map = [
            'pasarguard' => ['table'=>'pasarguard_panels','customer_col'=>'panel_id'],
            'marzban' => ['table'=>'marzban_panels','customer_col'=>'marzban_panel_id'],
            'guardcore' => ['table'=>'guardcore_panels','customer_col'=>'guardcore_panel_id'],
        ];
        if (!isset($map[$type])) return 0;
        $panelTable=BlueVPN_DB::table($map[$type]['table']);
        $customerTable=BlueVPN_DB::table('customers');
        $col=$map[$type]['customer_col'];
        $rows=$wpdb->get_results(
            "SELECT p.id,
                    COUNT(c.id) AS assigned,
                    SUM(CASE WHEN c.last_sync_error IS NOT NULL AND TRIM(c.last_sync_error)<>'' THEN 1 ELSE 0 END) AS errors
             FROM {$panelTable} p
             LEFT JOIN {$customerTable} c ON c.{$col}=p.id AND c.active=1
             WHERE p.active=1
             GROUP BY p.id
             ORDER BY errors ASC, assigned ASC, p.id ASC
             LIMIT 20",
            ARRAY_A
        );
        if(!$rows)return 0;
        $best=null;$bestScore=-PHP_INT_MAX;
        foreach($rows as $r){
            $assigned=(int)$r['assigned'];$errors=(int)$r['errors'];
            $score=1000-($assigned*3)-($errors*30);
            if($score>$bestScore){$bestScore=$score;$best=(int)$r['id'];}
        }
        return (int)$best;
    }

    public static function render_admin(): void {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $inc = BlueVPN_DB::table('ai_incidents');
        $runs = BlueVPN_DB::table('ai_reconciliation_runs');
        $incidents = $wpdb->get_results(
            "SELECT * FROM {$inc} WHERE status='open' ORDER BY FIELD(severity,'critical','error','warning','info'),last_seen_at DESC LIMIT 50",
            ARRAY_A
        );
        $recentRuns = $wpdb->get_results(
            "SELECT * FROM {$runs} ORDER BY created_at DESC LIMIT 30",
            ARRAY_A
        );

        echo '<div class="bvc-card"><div class="bvai-card-head"><div><h2>BlueAI Operations Center</h2><p>تشخیص ناهنجاری، Reconciliation پرداخت/Provider و پیشنهاد اقدام؛ تعمیر واقعی فقط از Engineهای قطعی و idempotent انجام می‌شود.</p></div>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('bluevpn_ai_ops_run');
        echo '<input type="hidden" name="action" value="bluevpn_ai_ops_run">';
        submit_button('اجرای پایش و ترمیم امن','secondary','submit',false);
        echo '</form></div>';

        echo '<h3>Incidentهای فعال</h3><table class="widefat striped bvc-table"><tr><th>شدت</th><th>نوع</th><th>Scope</th><th>عنوان</th><th>تکرار</th><th>پیشنهاد</th><th>آخرین مشاهده</th></tr>';
        if (!$incidents) echo '<tr><td colspan="7">Incident فعالی وجود ندارد.</td></tr>';
        foreach ((array)$incidents as $r) {
            echo '<tr><td>'.esc_html((string)$r['severity']).'</td><td>'.esc_html((string)$r['incident_type']).'</td><td>'.esc_html((string)$r['scope_type'].':'.(string)$r['scope_key']).'</td><td>'.esc_html((string)$r['title']).'</td><td>'.(int)$r['occurrence_count'].'</td><td>'.esc_html((string)$r['recommended_action']).'</td><td>'.esc_html((string)$r['last_seen_at']).'</td></tr>';
        }
        echo '</table>';

        echo '<h3 style="margin-top:24px">Reconciliation اخیر</h3><table class="widefat striped bvc-table"><tr><th>کاربر</th><th>سفارش</th><th>مسئله</th><th>اقدام</th><th>نتیجه</th><th>زمان</th></tr>';
        if (!$recentRuns) echo '<tr><td colspan="6">هنوز Reconciliation اجرا نشده است.</td></tr>';
        foreach ((array)$recentRuns as $r) {
            echo '<tr><td>'.(int)$r['customer_id'].'</td><td>'.esc_html((string)$r['order_id']).'</td><td>'.esc_html((string)$r['issue_type']).'</td><td>'.esc_html((string)$r['action_taken']).'</td><td>'.esc_html((string)$r['outcome']).'</td><td>'.esc_html((string)$r['created_at']).'</td></tr>';
        }
        echo '</table></div>';
    }
}
