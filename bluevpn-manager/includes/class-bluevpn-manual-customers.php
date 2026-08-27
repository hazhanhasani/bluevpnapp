<?php
if (!defined('ABSPATH')) exit;

final class BlueVPN_Manual_Customers {
    private const PAGE = 'bluevpn-manual-customers';

    public static function init(): void {
        foreach ([
            'bluevpn_manual_customer_save' => 'save',
            'bluevpn_manual_customer_renew' => 'renew',
            'bluevpn_manual_customer_toggle' => 'toggle',
            'bluevpn_manual_customer_delete' => 'delete',
            'bluevpn_manual_customer_send_sms' => 'send_sms',
            'bluevpn_manual_customer_send_activation_sms' => 'send_activation_sms',
            'bluevpn_manual_customer_import_csv' => 'import_csv',
        ] as $action => $method) {
            add_action('admin_post_' . $action, [self::class, $method]);
        }
    }

    private static function guard(): void {
        if (!current_user_can('manage_options')) wp_die('دسترسی ندارید.');
    }

    private static function table(): string {
        return BlueVPN_DB::table('manual_customers');
    }

    private static function url(array $args = []): string {
        return add_query_arg(array_merge(['page' => self::PAGE], $args), admin_url('admin.php'));
    }

    private static function redirect(string $message, bool $error = false, array $args = []): void {
        $args[$error ? 'cc_error' : 'cc_msg'] = $message;
        wp_safe_redirect(self::url($args));
        exit;
    }

    private static function safe_name(string $value): string {
        $value = trim(sanitize_text_field(wp_unslash($value)));
        return mb_substr($value, 0, 180);
    }

    private static function safe_phone(string $value): string {
        $phone = BlueVPN_Utils::sanitize_phone(sanitize_text_field(wp_unslash($value)));
        if (!preg_match('/^\+98\d{10}$/', $phone)) {
            throw new RuntimeException('شماره موبایل معتبر ایران وارد کنید.');
        }
        return $phone;
    }

    private static function date_input(?string $utcMysql): string {
        return $utcMysql ? BlueVPN_Utils::tehran_date_fa($utcMysql) : '';
    }

    private static function parse_date(string $value, bool $endOfDay): ?string {
        return BlueVPN_Utils::mysql_from_tehran_date(
            sanitize_text_field(wp_unslash($value)),
            $endOfDay
        );
    }

    private static function status_of(array $row): array {
        if (empty($row['active'])) return ['inactive', 'غیرفعال', 'bvc-warn'];
        $ts = !empty($row['expire_at']) ? (strtotime((string)$row['expire_at'] . ' UTC') ?: 0) : 0;
        if ($ts <= 0) return ['no_expiry', 'بدون انقضا', 'bvc-ok'];
        $seconds = $ts - time();
        if ($seconds <= 0) return ['expired', 'منقضی', 'bvc-bad'];
        $days = (int)ceil($seconds / DAY_IN_SECONDS);
        if ($days <= 7) return ['expiring', $days . ' روز مانده', 'bvc-warn'];
        return ['active', 'فعال', 'bvc-ok'];
    }

    private static function sms_params(
        array $row,
        string $event,
        int $daysLeft = 0
    ): array {
        $plan = mb_substr(
            trim((string)($row['service_name'] ?? '')) ?: 'اشتراک',
            0,
            40
        );
        $expireDate = BlueVPN_SMS_Notifications::jalali_date(
            (string)($row['expire_at'] ?? '')
        );

        return match ($event) {
            'admin_subscription_activated',
            'subscription_renewed',
            'subscription_plan_changed' => [
                'plan' => $plan,
                'expire_date' => $expireDate,
            ],
            'subscription_reminder' => [
                'days_left' => max(0, min(99, $daysLeft)),
            ],
            'subscription_expired' => [],
            default => [],
        };
    }

    private static function current_plans(bool $activeOnly = false): array {
        global $wpdb;
        $table = BlueVPN_DB::table('plans');
        $where = $activeOnly ? 'WHERE active=1 AND deleted=0' : 'WHERE deleted=0';
        return $wpdb->get_results(
            "SELECT id,title,duration_days,price_toman,active,deleted
             FROM {$table} {$where}
             ORDER BY active DESC,sort_order,price_toman,id",
            ARRAY_A
        ) ?: [];
    }

    private static function plan_by_id(int $planId, bool $mustBeActive = false): ?array {
        if ($planId <= 0) return null;
        global $wpdb;
        $table = BlueVPN_DB::table('plans');
        $sql = "SELECT id,title,duration_days,price_toman,active,deleted
                FROM {$table} WHERE id=%d AND deleted=0";
        if ($mustBeActive) $sql .= " AND active=1";
        $row = $wpdb->get_row($wpdb->prepare($sql, $planId), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private static function resolve_existing_plan(array $row): ?array {
        $planId = (int)($row['catalog_plan_id'] ?? 0);
        if ($planId > 0) {
            $plan = self::plan_by_id($planId, false);
            if ($plan) return $plan;
        }

        $title = trim((string)($row['service_name'] ?? ''));
        if ($title === '') return null;
        global $wpdb;
        $table = BlueVPN_DB::table('plans');
        $plan = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id,title,duration_days,price_toman,active,deleted
                 FROM {$table}
                 WHERE deleted=0 AND title=%s
                 ORDER BY active DESC,id ASC LIMIT 1",
                $title
            ),
            ARRAY_A
        );
        return is_array($plan) ? $plan : null;
    }


    private static function queue_customer_event(
        array $row,
        string $event,
        string $dedupe,
        int $daysLeft = 0,
        bool $force = false
    ): ?string {
        if (empty($row['sms_enabled']) || empty($row['phone'])) return null;
        return BlueVPN_SMS_Notifications::queue(
            $event,
            (string)$row['phone'],
            self::sms_params($row, $event, $daysLeft),
            null,
            null,
            'manual-customer:' . (int)$row['id'] . ':' . $dedupe,
            $force,
            true,
            (int)$row['id']
        );
    }

    private static function queue_and_dispatch_customer_event(
        array $row,
        string $event,
        string $dedupe,
        int $daysLeft = 0
    ): array {
        $deliveryId = self::queue_customer_event(
            $row,
            $event,
            $dedupe,
            $daysLeft,
            false
        );
        if (!$deliveryId) {
            return [
                'queued'=>false,
                'sent'=>false,
                'status'=>'not_queued',
                'message'=>'پیام فعلی در SMS Manager غیرفعال است یا کد پترن ندارد.',
            ];
        }

        $result = BlueVPN_SMS_Notifications::dispatch_now($deliveryId);
        return [
            'queued'=>true,
            'sent'=>!empty($result['sent']),
            'status'=>(string)($result['status'] ?? 'queued'),
            'message'=>(string)($result['message'] ?? ''),
            'delivery_id'=>$deliveryId,
        ];
    }

    public static function render(): void {
        self::guard();
        global $wpdb;

        $table = self::table();
        $q = sanitize_text_field(wp_unslash($_GET['q'] ?? ''));
        $filter = sanitize_key((string)($_GET['status'] ?? 'all'));
        $editId = max(0, (int)($_GET['manual_customer_id'] ?? 0));
        $edit = $editId > 0
            ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $editId), ARRAY_A)
            : null;

        $plans = self::current_plans(false);
        $selectedPlanId = $edit ? (int)($edit['catalog_plan_id'] ?? 0) : 0;
        if ($edit && $selectedPlanId <= 0) {
            $legacyPlan = self::resolve_existing_plan($edit);
            if ($legacyPlan) $selectedPlanId = (int)$legacyPlan['id'];
        }

        $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $active = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE active=1");
        $expired = (int)$wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE active=1 AND expire_at IS NOT NULL AND expire_at<=%s", BlueVPN_Utils::now_mysql())
        );
        $todayEnd = gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS);
        $today = (int)$wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE active=1 AND expire_at>%s AND expire_at<=%s",
                BlueVPN_Utils::now_mysql(),
                $todayEnd
            )
        );
        $week = gmdate('Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS);
        $expiring = (int)$wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE active=1 AND expire_at>%s AND expire_at<=%s",
                BlueVPN_Utils::now_mysql(),
                $week
            )
        );

        echo '<div class="bvc-grid">';
        foreach ([
            ['کل مشتریان دستی', $total],
            ['فعال', $active],
            ['تا ۲۴ ساعت آینده', $today],
            ['تا ۷ روز آینده', $expiring],
            ['منقضی', $expired],
        ] as [$label, $value]) {
            echo '<div class="bvc-card bvc-kpi"><span>'.esc_html($label).'</span><strong>'.number_format($value).'</strong></div>';
        }
        echo '</div>';

        echo '<div class="bvc-card"><h2>'.($edit ? 'ویرایش مشتری دستی' : 'ثبت مشتری دستی').'</h2>';
        echo '<div class="bvc-note"><strong>دقیقاً مثل کاربر اپ:</strong> برای مشتری جدید، بعد از انتخاب پلن و ثبت فرم همان پیام فعلی «فعال‌سازی دستی توسط مدیریت» (<code>admin_subscription_activated</code>) همان لحظه ارسال می‌شود. تمدید، یادآوری و پایان اشتراک هم از همان پیام‌ها و پترن‌های کاربران اپ استفاده می‌کنند. هیچ entitlement یا سرویس VPN جدیدی برای این مشتری ساخته نمی‌شود.</div>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('bluevpn_manual_customer_save');
        echo '<input type="hidden" name="action" value="bluevpn_manual_customer_save">';
        echo '<input type="hidden" name="customer_id" value="'.(int)($edit['id'] ?? 0).'">';
        echo '<div class="bvc-form-grid">';
        self::field('full_name', 'نام مشتری', (string)($edit['full_name'] ?? ''));
        self::field('phone', 'شماره موبایل', BlueVPN_Utils::local_phone((string)($edit['phone'] ?? '')), true);
        echo '<label>پلن فعلی BlueVPN<select name="catalog_plan_id" required>';
        echo '<option value="">انتخاب پلن…</option>';
        foreach ($plans as $plan) {
            $planId = (int)$plan['id'];
            $days = (int)$plan['duration_days'];
            $suffix = $days > 0 ? ' — '.$days.' روز' : ' — بدون محدودیت زمانی';
            if (empty($plan['active'])) $suffix .= ' — غیرفعال';
            echo '<option value="'.$planId.'" '.selected($selectedPlanId,$planId,false).'>'.esc_html((string)$plan['title'].$suffix).'</option>';
        }
        echo '</select><small style="display:block;margin-top:4px">تمدید بعدی دقیقاً با مدت همین پلن انجام می‌شود.</small></label>';
        self::field('app_name', 'اپ / بستر سرویس', (string)($edit['app_name'] ?? ''));
        self::field('start_date', 'تاریخ شروع شمسی', self::date_input($edit['start_at'] ?? null), false, 'مثال: 1405/05/27');
        self::field('expire_date', 'تاریخ انقضا شمسی', self::date_input($edit['expire_at'] ?? null), true, 'مثال: 1405/06/27');
        echo '<label style="grid-column:1/-1">یادداشت<textarea name="notes" rows="3">'.esc_textarea((string)($edit['notes'] ?? '')).'</textarea></label>';
        echo '<label><input type="checkbox" name="active" value="1" '.checked(!isset($edit['active']) || (int)$edit['active'] === 1, true, false).'> مشتری فعال باشد</label>';
        echo '<label><input type="checkbox" name="sms_enabled" value="1" '.checked(!isset($edit['sms_enabled']) || (int)$edit['sms_enabled'] === 1, true, false).'> یادآوری SMS فعال باشد</label>';
        echo '<div class="bvc-note">ارسال‌ها خودکار است: انتخاب پلن + ثبت مشتری جدید → همان «فعال‌سازی دستی توسط مدیریت» و ارسال فوری؛ تمدید → «تمدید اشتراک» و ارسال فوری؛ روزهای یادآوری → «یادآوری پایان اشتراک»؛ پایان اعتبار → «پایان اشتراک».</div>';
        echo '</div><p>';
        submit_button($edit ? 'ذخیره تغییرات' : 'ثبت مشتری', 'primary', 'submit', false);
        if ($edit) echo ' <a class="button" href="'.esc_url(self::url()).'">انصراف</a>';
        echo '</p></form></div>';

        echo '<div class="bvc-card"><h2>ورود گروهی CSV</h2>';
        echo '<div class="bvc-note">ستون‌های قابل قبول: <code>phone</code>، <code>name</code>، <code>plan_id</code> یا <code>plan</code> (عنوان دقیق یکی از پلن‌های فعلی)، <code>app</code>، <code>start_date</code>، <code>expire_date</code>، <code>note</code>، <code>sms_enabled</code>. تاریخ‌ها می‌توانند شمسی مثل 1405/06/27 باشند.</div>';
        echo '<form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('bluevpn_manual_customer_import_csv');
        echo '<input type="hidden" name="action" value="bluevpn_manual_customer_import_csv">';
        echo '<input type="file" name="csv_file" accept=".csv,text/csv" required> ';
        echo '<span class="bvc-note">برای ردیف جدید با SMS فعال، پیام فعال‌سازی فعلی به‌صورت خودکار صف می‌شود.</span> ';
        submit_button('ورود CSV', 'secondary', 'submit', false);
        echo '</form></div>';

        $where = ['1=1'];
        $params = [];
        if ($q !== '') {
            $like = '%' . $wpdb->esc_like($q) . '%';
            $where[] = '(full_name LIKE %s OR phone LIKE %s OR service_name LIKE %s OR app_name LIKE %s)';
            array_push($params, $like, $like, $like, $like);
        }
        $now = BlueVPN_Utils::now_mysql();
        if ($filter === 'active') {
            $where[] = 'active=1 AND (expire_at IS NULL OR expire_at>%s)';
            $params[] = $now;
        } elseif ($filter === 'today') {
            $where[] = 'active=1 AND expire_at>%s AND expire_at<=%s';
            $params[] = $now;
            $params[] = $todayEnd;
        } elseif ($filter === 'expiring') {
            $where[] = 'active=1 AND expire_at>%s AND expire_at<=%s';
            $params[] = $now;
            $params[] = $week;
        } elseif ($filter === 'expired') {
            $where[] = 'active=1 AND expire_at IS NOT NULL AND expire_at<=%s';
            $params[] = $now;
        } elseif ($filter === 'inactive') {
            $where[] = 'active=0';
        }

        $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $where) . " ORDER BY active DESC,expire_at ASC,id DESC LIMIT 500";
        if ($params) $sql = $wpdb->prepare($sql, ...$params);
        $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];

        echo '<form method="get" class="bvc-card"><input type="hidden" name="page" value="'.esc_attr(self::PAGE).'">';
        echo '<input name="q" value="'.esc_attr($q).'" placeholder="نام، موبایل، سرویس یا اپ"> ';
        echo '<select name="status">';
        foreach ([
            'all'=>'همه',
            'active'=>'فعال',
            'today'=>'تا ۲۴ ساعت آینده',
            'expiring'=>'تا ۷ روز آینده',
            'expired'=>'منقضی',
            'inactive'=>'غیرفعال',
        ] as $key=>$label) {
            echo '<option value="'.esc_attr($key).'" '.selected($filter,$key,false).'>'.esc_html($label).'</option>';
        }
        echo '</select> <button class="button">فیلتر</button></form>';

        echo '<div class="bvc-card"><h2>مشتریان دستی</h2>';
        if (!$rows) {
            echo '<p class="bvc-note">موردی با این فیلتر پیدا نشد.</p></div>';
            return;
        }
        echo '<table class="widefat striped bvc-table"><tr><th>مشتری</th><th>پلن فعلی</th><th>اعتبار</th><th>SMS</th><th>عملیات</th></tr>';
        foreach ($rows as $row) {
            [$statusKey,$statusLabel,$statusClass] = self::status_of($row);
            $id = (int)$row['id'];
            $historyCount = (int)$wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM ".BlueVPN_DB::table('sms_deliveries')."
                     WHERE manual_customer_id=%d
                        OR (manual_customer_id IS NULL AND phone=%s AND dedupe_key LIKE %s)",
                    $id,
                    (string)$row['phone'],
                    'manual-customer:' . $id . ':%'
                )
            );

            echo '<tr>';
            echo '<td><strong>'.esc_html((string)$row['full_name']).'</strong><br>'.esc_html(BlueVPN_Utils::local_phone((string)$row['phone'])).'<br><small>#'.$id.'</small></td>';
            echo '<td><strong>'.esc_html((string)$row['service_name']).'</strong><br><small>'.esc_html((string)$row['app_name']).'</small></td>';
            echo '<td><span class="'.esc_attr($statusClass).'">'.esc_html($statusLabel).'</span><br><small>شروع: '.esc_html(self::date_input($row['start_at'])).'<br>انقضا: '.esc_html(self::date_input($row['expire_at'])).'</small></td>';
            echo '<td>'.(!empty($row['sms_enabled'])?'✅ فعال':'⛔ خاموش').'<br><small>'.number_format($historyCount).' پیام ثبت‌شده</small></td>';
            echo '<td><div class="bvc-actions">';
            echo '<a class="button" href="'.esc_url(self::url(['manual_customer_id'=>$id])).'">ویرایش</a>';
            echo '<a class="button" href="'.esc_url(self::url(['sms_customer_id'=>$id])).'">تاریخچه SMS</a>';

            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('bluevpn_manual_customer_renew_'.$id);
            echo '<input type="hidden" name="action" value="bluevpn_manual_customer_renew"><input type="hidden" name="customer_id" value="'.$id.'">';
            $rowPlan = self::resolve_existing_plan($row);
            $renewLabel = $rowPlan
                ? ('تمدید '.$rowPlan['duration_days'].' روزه')
                : 'انتخاب پلن برای تمدید';
            echo '<button class="button button-primary">'.esc_html($renewLabel).'</button></form>';

            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('bluevpn_manual_customer_send_activation_sms_'.$id);
            echo '<input type="hidden" name="action" value="bluevpn_manual_customer_send_activation_sms"><input type="hidden" name="customer_id" value="'.$id.'">';
            echo '<button class="button">ارسال فعال‌سازی</button></form>';

            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('bluevpn_manual_customer_send_sms_'.$id);
            echo '<input type="hidden" name="action" value="bluevpn_manual_customer_send_sms"><input type="hidden" name="customer_id" value="'.$id.'">';
            echo '<button class="button">SMS یادآوری</button></form>';

            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('bluevpn_manual_customer_toggle_'.$id);
            echo '<input type="hidden" name="action" value="bluevpn_manual_customer_toggle"><input type="hidden" name="customer_id" value="'.$id.'">';
            echo '<button class="button">'.(!empty($row['active'])?'غیرفعال':'فعال').'</button></form>';

            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" onsubmit="return confirm(&quot;این مشتری دستی حذف شود؟ این کار حساب یا سرویس VPN دیگری را لمس نمی‌کند.&quot;)">';
            wp_nonce_field('bluevpn_manual_customer_delete_'.$id);
            echo '<input type="hidden" name="action" value="bluevpn_manual_customer_delete"><input type="hidden" name="customer_id" value="'.$id.'">';
            echo '<button class="button button-link-delete">حذف</button></form>';
            echo '</div></td></tr>';
        }
        echo '</table></div>';

        self::render_recent_sms(max(0, (int)($_GET['sms_customer_id'] ?? 0)));
    }

    private static function field(
        string $name,
        string $label,
        string $value = '',
        bool $required = false,
        string $hint = ''
    ): void {
        echo '<label>'.esc_html($label).'<input type="text" name="'.esc_attr($name).'" value="'.esc_attr($value).'" '.($required?'required':'').'>';
        if ($hint !== '') echo '<small style="display:block;margin-top:4px">'.esc_html($hint).'</small>';
        echo '</label>';
    }

    private static function render_recent_sms(int $manualCustomerId = 0): void {
        global $wpdb;
        $deliveries = BlueVPN_DB::table('sms_deliveries');

        if ($manualCustomerId > 0) {
            $manual = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id,full_name,phone,service_name FROM ".self::table()." WHERE id=%d",
                    $manualCustomerId
                ),
                ARRAY_A
            );
            $like = 'manual-customer:' . $manualCustomerId . ':%';
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id,event_key,phone,status,last_error,sent_at,created_at,dedupe_key,manual_customer_id
                     FROM {$deliveries}
                     WHERE manual_customer_id=%d
                        OR (manual_customer_id IS NULL AND dedupe_key LIKE %s)
                     ORDER BY created_at DESC LIMIT 150",
                    $manualCustomerId,
                    $like
                ),
                ARRAY_A
            ) ?: [];
            echo '<div class="bvc-card"><h2>تاریخچه SMS مشتری #'.(int)$manualCustomerId.'</h2>';
            if ($manual) {
                echo '<p><strong>'.esc_html((string)$manual['full_name']).'</strong> — '.esc_html(BlueVPN_Utils::local_phone((string)$manual['phone'])).' — '.esc_html((string)$manual['service_name']).'</p>';
            }
            echo '<p><a class="button" href="'.esc_url(self::url()).'">نمایش تاریخچه همه</a></p>';
        } else {
            $rows = $wpdb->get_results(
                "SELECT id,event_key,phone,status,last_error,sent_at,created_at,dedupe_key,manual_customer_id
                 FROM {$deliveries}
                 WHERE manual_customer_id IS NOT NULL
                    OR dedupe_key LIKE 'manual-customer:%'
                 ORDER BY created_at DESC LIMIT 80",
                ARRAY_A
            ) ?: [];
            echo '<div class="bvc-card"><h2>آخرین پیامک‌های مشتریان دستی</h2>';
        }

        if (!$rows) {
            echo '<p>هنوز پیامکی در این بخش ثبت نشده است.</p></div>';
            return;
        }

        echo '<table class="widefat striped bvc-table"><tr><th>رویداد</th><th>موبایل</th><th>وضعیت</th><th>زمان</th><th>خطا</th></tr>';
        foreach ($rows as $row) {
            echo '<tr><td>'.esc_html((string)$row['event_key']).'</td><td>'.esc_html(BlueVPN_Utils::local_phone((string)$row['phone'])).'</td><td>'.esc_html((string)$row['status']).'</td><td>'.esc_html(BlueVPN_Utils::tehran_datetime_fa($row['sent_at'] ?: $row['created_at'])).'</td><td><small>'.esc_html((string)$row['last_error']).'</small></td></tr>';
        }
        echo '</table></div>';
    }

    public static function save(): void {
        self::guard();
        check_admin_referer('bluevpn_manual_customer_save');
        global $wpdb;

        try {
            $id = max(0, (int)($_POST['customer_id'] ?? 0));
            $phone = self::safe_phone((string)($_POST['phone'] ?? ''));
            $name = self::safe_name((string)($_POST['full_name'] ?? ''));
            $planId = max(0, (int)($_POST['catalog_plan_id'] ?? 0));
            $plan = self::plan_by_id($planId, false);
            if (!$plan) throw new RuntimeException('یکی از پلن‌های فعلی BlueVPN را انتخاب کنید.');
            $service = self::safe_name((string)$plan['title']);

            $startAt = self::parse_date((string)($_POST['start_date'] ?? ''), false);
            $expireAt = self::parse_date((string)($_POST['expire_date'] ?? ''), true);
            if (!$expireAt) throw new RuntimeException('تاریخ انقضا شمسی معتبر وارد کنید.');
            if (!$startAt) $startAt = BlueVPN_Utils::now_mysql();

            $data = [
                'full_name' => $name,
                'phone' => $phone,
                'service_name' => $service,
                'catalog_plan_id' => $planId,
                'app_name' => self::safe_name((string)($_POST['app_name'] ?? '')),
                'start_at' => $startAt,
                'expire_at' => $expireAt,
                'notes' => mb_substr(sanitize_textarea_field(wp_unslash($_POST['notes'] ?? '')), 0, 4000),
                'active' => isset($_POST['active']) ? 1 : 0,
                'sms_enabled' => isset($_POST['sms_enabled']) ? 1 : 0,
                'updated_at' => BlueVPN_Utils::now_mysql(),
            ];

            $table = self::table();
            $before = $id > 0
                ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id), ARRAY_A)
                : null;
            if ($id > 0) {
                $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE id=%d", $id));
                if (!$exists) throw new RuntimeException('مشتری دستی پیدا نشد.');
                if ($wpdb->update($table, $data, ['id'=>$id]) === false) {
                    throw new RuntimeException('ذخیره مشتری ناموفق بود.');
                }
            } else {
                $data['created_at'] = BlueVPN_Utils::now_mysql();
                if ($wpdb->insert($table, $data) === false) {
                    throw new RuntimeException('ثبت مشتری ناموفق بود.');
                }
                $id = (int)$wpdb->insert_id;
            }

            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id), ARRAY_A);
            $smsResult = null;
            $smsEventTitle = '';
            if ($row && !empty($row['sms_enabled'])) {
                $seed = gmdate('YmdHi', strtotime((string)$row['expire_at'] . ' UTC'));
                if (!$before) {
                    $smsEventTitle = 'فعال‌سازی دستی توسط مدیریت';
                    $smsResult = self::queue_and_dispatch_customer_event(
                        $row,
                        'admin_subscription_activated',
                        'activated:' . $planId . ':' . $seed,
                        0
                    );
                } elseif ((int)($before['catalog_plan_id'] ?? 0) !== $planId) {
                    $smsEventTitle = 'تغییر پلن اشتراک';
                    $smsResult = self::queue_and_dispatch_customer_event(
                        $row,
                        'subscription_plan_changed',
                        'plan-changed:' . $planId . ':' . $seed,
                        0
                    );
                } elseif ((string)($before['expire_at'] ?? '') !== (string)$row['expire_at']) {
                    $smsEventTitle = 'تمدید اشتراک';
                    $smsResult = self::queue_and_dispatch_customer_event(
                        $row,
                        'subscription_renewed',
                        'expiry-edited:' . $planId . ':' . $seed,
                        0
                    );
                }
            }

            $message = 'مشتری دستی ذخیره شد.';
            $error = false;
            if (is_array($smsResult)) {
                if (!empty($smsResult['sent'])) {
                    $message .= ' پیام «' . $smsEventTitle . '» همان لحظه ارسال شد.';
                } elseif (!empty($smsResult['queued'])) {
                    $message .= ' پیام «' . $smsEventTitle . '» ارسال فوری نشد و برای Retry در صف باقی ماند: ' .
                        (string)($smsResult['message'] ?? '');
                } else {
                    $message .= ' پیام «' . $smsEventTitle . '» ارسال نشد: ' .
                        (string)($smsResult['message'] ?? '');
                    $error = true;
                }
            } elseif ($row && empty($row['sms_enabled'])) {
                $message .= ' SMS این مشتری خاموش است.';
            }

            self::redirect($message, $error);
        } catch (Throwable $e) {
            self::redirect($e->getMessage(), true);
        }
    }

    public static function renew(): void {
        self::guard();
        global $wpdb;
        $id = max(0, (int)($_POST['customer_id'] ?? 0));
        check_admin_referer('bluevpn_manual_customer_renew_' . $id);

        try {
            $table = self::table();
            $row = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id),
                ARRAY_A
            );
            if (!$row) throw new RuntimeException('مشتری دستی پیدا نشد.');

            $plan = self::resolve_existing_plan($row);
            if (!$plan) {
                throw new RuntimeException('برای این مشتری ابتدا یکی از پلن‌های فعلی BlueVPN را انتخاب و ذخیره کنید.');
            }
            if (empty($plan['active'])) {
                throw new RuntimeException('پلن انتخاب‌شده در حال حاضر غیرفعال است. ابتدا پلن مشتری را تغییر دهید.');
            }

            $days = (int)($plan['duration_days'] ?? 0);
            if ($days <= 0) {
                throw new RuntimeException('این پلن مدت روزانه مشخص ندارد و تمدید خودکار زمانی برای آن قابل محاسبه نیست.');
            }

            $currentTs = !empty($row['expire_at'])
                ? (strtotime((string)$row['expire_at'] . ' UTC') ?: 0)
                : 0;
            $base = max(time(), $currentTs);
            $newExpiry = gmdate('Y-m-d H:i:s', $base + $days * DAY_IN_SECONDS);

            $ok = $wpdb->update(
                $table,
                [
                    'catalog_plan_id' => (int)$plan['id'],
                    'service_name' => (string)$plan['title'],
                    'expire_at' => $newExpiry,
                    'active' => 1,
                    'last_renewed_at' => BlueVPN_Utils::now_mysql(),
                    'updated_at' => BlueVPN_Utils::now_mysql(),
                ],
                ['id' => $id]
            );
            if ($ok === false) throw new RuntimeException('تمدید مشتری ناموفق بود.');

            $row['catalog_plan_id'] = (int)$plan['id'];
            $row['service_name'] = (string)$plan['title'];
            $row['expire_at'] = $newExpiry;
            $row['active'] = 1;

            $smsResult = self::queue_and_dispatch_customer_event(
                $row,
                'subscription_renewed',
                'renewed:' . (int)$plan['id'] . ':' . gmdate('YmdHi', strtotime($newExpiry . ' UTC')),
                0
            );

            $message = 'اشتراک با پلن «' . (string)$plan['title'] . '» برای ' .
                $days . ' روز تمدید شد.';
            $error = false;
            if (!empty($smsResult['sent'])) {
                $message .= ' پیام «تمدید اشتراک» همان لحظه ارسال شد.';
            } elseif (!empty($smsResult['queued'])) {
                $message .= ' پیام «تمدید اشتراک» برای Retry در صف ماند: ' .
                    (string)($smsResult['message'] ?? '');
            } else {
                $message .= ' پیام «تمدید اشتراک» ارسال نشد: ' .
                    (string)($smsResult['message'] ?? '');
                $error = true;
            }
            self::redirect($message, $error);
        } catch (Throwable $e) {
            self::redirect($e->getMessage(), true);
        }
    }

    public static function toggle(): void {
        self::guard();
        global $wpdb;
        $id = max(0, (int)($_POST['customer_id'] ?? 0));
        check_admin_referer('bluevpn_manual_customer_toggle_' . $id);
        $table = self::table();
        $active = (int)$wpdb->get_var($wpdb->prepare("SELECT active FROM {$table} WHERE id=%d", $id));
        $wpdb->update($table, ['active'=>$active ? 0 : 1, 'updated_at'=>BlueVPN_Utils::now_mysql()], ['id'=>$id]);
        self::redirect($active ? 'مشتری غیرفعال شد.' : 'مشتری فعال شد.');
    }

    public static function delete(): void {
        self::guard();
        global $wpdb;
        $id = max(0, (int)($_POST['customer_id'] ?? 0));
        check_admin_referer('bluevpn_manual_customer_delete_' . $id);
        $wpdb->delete(self::table(), ['id'=>$id], ['%d']);
        self::redirect('مشتری دستی حذف شد. هیچ حساب یا سرویس VPN تغییر نکرد.');
    }

    public static function send_activation_sms(): void {
        self::guard();
        global $wpdb;
        $id = max(0, (int)($_POST['customer_id'] ?? 0));
        check_admin_referer('bluevpn_manual_customer_send_activation_sms_' . $id);

        try {
            $row = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM ".self::table()." WHERE id=%d", $id),
                ARRAY_A
            );
            if (!$row) throw new RuntimeException('مشتری دستی پیدا نشد.');
            if (empty($row['sms_enabled'])) throw new RuntimeException('SMS این مشتری غیرفعال است.');

            $plan = self::resolve_existing_plan($row);
            if (!$plan) throw new RuntimeException('پلن فعلی این مشتری پیدا نشد.');

            $seed = gmdate(
                'YmdHi',
                strtotime((string)$row['expire_at'] . ' UTC') ?: time()
            );
            $result = self::queue_and_dispatch_customer_event(
                $row,
                'admin_subscription_activated',
                'manual-resend-activated:' . (int)$plan['id'] . ':' . $seed . ':' . gmdate('YmdHis'),
                0
            );

            if (!empty($result['sent'])) {
                self::redirect('پیام «فعال‌سازی دستی توسط مدیریت» همان لحظه ارسال شد.');
            }
            if (!empty($result['queued'])) {
                self::redirect(
                    'ارسال فوری انجام نشد؛ پیام برای Retry در صف باقی ماند: ' .
                    (string)($result['message'] ?? '')
                );
            }
            throw new RuntimeException(
                'پیام فعال‌سازی ارسال نشد: ' . (string)($result['message'] ?? '')
            );
        } catch (Throwable $e) {
            self::redirect($e->getMessage(), true);
        }
    }

    public static function send_sms(): void {
        self::guard();
        global $wpdb;
        $id = max(0, (int)($_POST['customer_id'] ?? 0));
        check_admin_referer('bluevpn_manual_customer_send_sms_' . $id);

        try {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::table()." WHERE id=%d", $id), ARRAY_A);
            if (!$row) throw new RuntimeException('مشتری دستی پیدا نشد.');
            if (empty($row['sms_enabled'])) throw new RuntimeException('SMS این مشتری غیرفعال است.');

            $expiryTs = !empty($row['expire_at']) ? (strtotime((string)$row['expire_at'] . ' UTC') ?: 0) : 0;
            $daysLeft = $expiryTs > time() ? (int)ceil(($expiryTs - time()) / DAY_IN_SECONDS) : 0;
            $event = $daysLeft > 0 ? 'subscription_reminder' : 'subscription_expired';
            $idQueued = self::queue_customer_event(
                $row,
                $event,
                'manual-send:' . gmdate('YmdHis') . ':' . substr(bin2hex(random_bytes(4)), 0, 8),
                $daysLeft,
                true
            );
            if (!$idQueued) throw new RuntimeException('پیامک در صف قرار نگرفت؛ تنظیمات SMS و پترن را بررسی کنید.');
            self::redirect('پیامک مشتری در صف ارسال قرار گرفت.');
        } catch (Throwable $e) {
            self::redirect($e->getMessage(), true);
        }
    }

    private static function normalize_csv_header(string $value): string {
        $value = strtolower(trim(strtr($value, '۰۱۲۳۴۵۶۷۸۹', '0123456789')));
        $map = [
            'شماره'=>'phone','شماره موبایل'=>'phone','موبایل'=>'phone','mobile'=>'phone',
            'نام'=>'name','نام مشتری'=>'name','full_name'=>'name',
            'پلن'=>'plan','نام پلن'=>'plan','plan_title'=>'plan','service'=>'plan','service_name'=>'plan',
            'شناسه پلن'=>'plan_id','planid'=>'plan_id',
            'اپ'=>'app','برنامه'=>'app','app_name'=>'app',
            'شروع'=>'start_date','تاریخ شروع'=>'start_date','start'=>'start_date',
            'انقضا'=>'expire_date','تاریخ انقضا'=>'expire_date','expire'=>'expire_date','expiry'=>'expire_date',
            'یادداشت'=>'note','توضیحات'=>'note','notes'=>'note',
            'پیامک'=>'sms_enabled','sms'=>'sms_enabled',
        ];
        return $map[$value] ?? sanitize_key($value);
    }

    public static function import_csv(): void {
        self::guard();
        check_admin_referer('bluevpn_manual_customer_import_csv');
        global $wpdb;

        try {
            if (empty($_FILES['csv_file']['tmp_name']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
                throw new RuntimeException('فایل CSV دریافت نشد.');
            }
            if ((int)($_FILES['csv_file']['size'] ?? 0) > 2 * 1024 * 1024) {
                throw new RuntimeException('حجم CSV نباید بیشتر از ۲ مگابایت باشد.');
            }

            $fh = fopen($_FILES['csv_file']['tmp_name'], 'rb');
            if (!$fh) throw new RuntimeException('بازکردن CSV ناموفق بود.');

            $header = fgetcsv($fh);
            if (!$header) {
                fclose($fh);
                throw new RuntimeException('CSV خالی است.');
            }
            $header = array_map([self::class, 'normalize_csv_header'], $header);
            if (
                !in_array('phone', $header, true) ||
                !in_array('expire_date', $header, true) ||
                (!in_array('plan_id', $header, true) && !in_array('plan', $header, true))
            ) {
                fclose($fh);
                throw new RuntimeException('CSV باید phone و expire_date و یکی از plan_id یا plan را داشته باشد.');
            }

            $created = 0;
            $skipped = 0;
            $errors = 0;
            $line = 1;
            $table = self::table();

            while (($rowValues = fgetcsv($fh)) !== false && $line < 5001) {
                $line++;
                if (!array_filter($rowValues, static fn($v) => trim((string)$v) !== '')) continue;
                $rowValues = array_pad($rowValues, count($header), '');
                $row = array_combine($header, array_slice($rowValues, 0, count($header))) ?: [];

                try {
                    $phone = self::safe_phone((string)($row['phone'] ?? ''));
                    $expireAt = BlueVPN_Utils::mysql_from_tehran_date((string)($row['expire_date'] ?? ''), true);
                    if (!$expireAt) throw new RuntimeException('تاریخ انقضا نامعتبر');
                    $startAt = BlueVPN_Utils::mysql_from_tehran_date((string)($row['start_date'] ?? ''), false)
                        ?: BlueVPN_Utils::now_mysql();
                    $plan = null;
                    $csvPlanId = max(0, (int)($row['plan_id'] ?? 0));
                    if ($csvPlanId > 0) {
                        $plan = self::plan_by_id($csvPlanId, false);
                    }
                    if (!$plan) {
                        $planTitle = self::safe_name((string)($row['plan'] ?? ''));
                        if ($planTitle !== '') {
                            $planTable = BlueVPN_DB::table('plans');
                            $plan = $wpdb->get_row(
                                $wpdb->prepare(
                                    "SELECT id,title,duration_days,price_toman,active,deleted
                                     FROM {$planTable}
                                     WHERE deleted=0 AND title=%s
                                     ORDER BY active DESC,id ASC LIMIT 1",
                                    $planTitle
                                ),
                                ARRAY_A
                            );
                        }
                    }
                    if (!$plan) throw new RuntimeException('پلن فعلی BlueVPN پیدا نشد');
                    $service = self::safe_name((string)$plan['title']);

                    // Avoid accidental duplicate import of the exact same
                    // phone/plan/expiry tuple.
                    $duplicate = $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT id FROM {$table} WHERE phone=%s AND catalog_plan_id=%d AND expire_at=%s LIMIT 1",
                            $phone,
                            (int)$plan['id'],
                            $expireAt
                        )
                    );
                    if ($duplicate) {
                        $skipped++;
                        continue;
                    }

                    $smsRaw = strtolower(trim((string)($row['sms_enabled'] ?? '1')));
                    $smsEnabled = !in_array($smsRaw, ['0','false','no','off','خیر'], true) ? 1 : 0;

                    $ok = $wpdb->insert($table, [
                        'full_name'=>self::safe_name((string)($row['name'] ?? '')),
                        'phone'=>$phone,
                        'service_name'=>$service,
                        'catalog_plan_id'=>(int)$plan['id'],
                        'app_name'=>self::safe_name((string)($row['app'] ?? '')),
                        'start_at'=>$startAt,
                        'expire_at'=>$expireAt,
                        'notes'=>mb_substr(sanitize_textarea_field((string)($row['note'] ?? '')),0,4000),
                        'active'=>1,
                        'sms_enabled'=>$smsEnabled,
                        'created_at'=>BlueVPN_Utils::now_mysql(),
                        'updated_at'=>BlueVPN_Utils::now_mysql(),
                    ]);
                    if ($ok === false) throw new RuntimeException('DB insert failed');
                    $created++;

                    if ($smsEnabled) {
                        $id = (int)$wpdb->insert_id;
                        $saved = $wpdb->get_row(
                            $wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $id),
                            ARRAY_A
                        );
                        if ($saved) {
                            self::queue_customer_event(
                                $saved,
                                'admin_subscription_activated',
                                'import-activated:' . gmdate('YmdHi', strtotime($expireAt . ' UTC')),
                                0,
                                false
                            );
                        }
                    }
                } catch (Throwable $rowError) {
                    $errors++;
                }
            }
            fclose($fh);

            self::redirect(
                'ورود CSV تمام شد: ' . number_format($created) . ' ثبت، ' .
                number_format($skipped) . ' تکراری، ' . number_format($errors) . ' خطا.'
            );
        } catch (Throwable $e) {
            self::redirect($e->getMessage(), true);
        }
    }

    public static function scan_notifications(): array {
        global $wpdb;
        $settings = BlueVPN_SMS_Notifications::settings();
        if (empty($settings['notification_active'])) return ['scanned'=>0,'queued'=>0];

        $days = BlueVPN_Utils::json_decode_array(
            (string)($settings['reminder_days_json'] ?? '[3,2,1]'),
            [3,2,1]
        );
        $days = array_values(array_unique(array_filter(
            array_map('intval', $days),
            static fn($x) => $x >= 1 && $x <= 30
        )));
        if (!$days) $days = [3,2,1];

        $rows = $wpdb->get_results(
            "SELECT * FROM ".self::table()."
             WHERE active=1 AND sms_enabled=1 AND phone<>'' AND expire_at IS NOT NULL
             ORDER BY expire_at ASC LIMIT 2000",
            ARRAY_A
        ) ?: [];

        $queued = 0;
        $now = time();
        foreach ($rows as $row) {
            $expiryTs = strtotime((string)$row['expire_at'] . ' UTC') ?: 0;
            if ($expiryTs <= 0) continue;

            $seconds = $expiryTs - $now;
            $daysLeft = $seconds > 0 ? (int)ceil($seconds / DAY_IN_SECONDS) : 0;
            $seed = gmdate('YmdHi', $expiryTs);

            if (in_array($daysLeft, $days, true)) {
                $queued += self::queue_customer_event(
                    $row,
                    'subscription_reminder',
                    'expiry:' . $seed . ':day:' . $daysLeft,
                    $daysLeft
                ) ? 1 : 0;
            }
            if ($seconds <= 0) {
                $queued += self::queue_customer_event(
                    $row,
                    'subscription_expired',
                    'expired:' . $seed,
                    0
                ) ? 1 : 0;
            }
        }

        return ['scanned'=>count($rows),'queued'=>$queued];
    }
}
