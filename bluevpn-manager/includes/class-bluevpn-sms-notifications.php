<?php
if (!defined('ABSPATH')) exit;

final class BlueVPN_SMS_Notifications {
    public const HOOK_PROCESS = 'bluevpn_sms_process_queue';
    public const HOOK_WAKE = 'bluevpn_sms_wake_queue';
    private const RETRY_DELAYS = [60, 300, 900, 1800];
    private const CATALOG_VERSION = '2026-08-18-4.15.6-manual-exact-admin-activation-event';
    private static bool $shutdownRegistered = false;

    /**
     * Canonical message contracts migrated from the Railway backend.
     * Existing pattern_code/enabled values are preserved when seeding.
     */
    private static function catalog(): array {
        $v = static fn(string $name, string $kind, int $length): array => ['name'=>$name,'type'=>$kind,'length'=>$length];
        return [
            'auth_otp'=>['title'=>'کد ورود یا ثبت‌نام','category'=>'احراز هویت','body'=>"کد ورود شما به بلوپنل: %code%\nاین کد را در اختیار دیگران قرار ندهید.",'vars'=>[$v('code','متنی',6)],'default'=>1],
            'welcome'=>['title'=>'خوش‌آمدگویی','category'=>'حساب کاربری','body'=>'%name% عزیز، عضویت شما در بلوپنل با موفقیت انجام شد.','vars'=>[$v('name','متنی',30)]],
            'account_activated'=>['title'=>'فعال‌شدن حساب','category'=>'حساب کاربری','body'=>"حساب شما در بلوپنل فعال شد.\nاکنون می‌توانید وارد حساب خود شوید."],
            'subscription_activated'=>['title'=>'فعال‌شدن اشتراک','category'=>'اشتراک','body'=>"اشتراک %plan% شما در بلوپنل فعال شد.\nاعتبار تا: %expire_date%",'vars'=>[$v('plan','متنی',40),$v('expire_date','متنی',10)]],
            'admin_subscription_activated'=>['title'=>'فعال‌سازی دستی توسط مدیریت','category'=>'اشتراک','body'=>"اشتراک %plan% شما توسط مدیریت بلوپنل فعال شد.\nاعتبار تا: %expire_date%",'vars'=>[$v('plan','متنی',40),$v('expire_date','متنی',10)]],
            'subscription_renewed'=>['title'=>'تمدید اشتراک','category'=>'اشتراک','body'=>"اشتراک %plan% شما در بلوپنل تمدید شد.\nاعتبار جدید: %expire_date%",'vars'=>[$v('plan','متنی',40),$v('expire_date','متنی',10)]],
            'subscription_upgraded'=>['title'=>'ارتقای اشتراک','category'=>'اشتراک','body'=>"اشتراک شما در بلوپنل به پلن %plan% ارتقا یافت.\nاعتبار تا: %expire_date%",'vars'=>[$v('plan','متنی',40),$v('expire_date','متنی',10)]],
            'subscription_plan_changed'=>['title'=>'تغییر پلن اشتراک','category'=>'اشتراک','body'=>"پلن حساب شما در بلوپنل به %plan% تغییر کرد.\nاعتبار تا: %expire_date%",'vars'=>[$v('plan','متنی',40),$v('expire_date','متنی',10)]],
            'payment_success'=>['title'=>'پرداخت موفق','category'=>'پرداخت','body'=>"پرداخت %amount% تومان با موفقیت انجام شد.\nشماره فاکتور: %invoice_id%\nبلوپنل",'vars'=>[$v('amount','عددی',12),$v('invoice_id','متنی',40)]],
            'payment_failed'=>['title'=>'پرداخت ناموفق','category'=>'پرداخت','body'=>"پرداخت فاکتور %invoice_id% ناموفق بود.\nدر صورت کسر وجه با پشتیبانی بلوپنل تماس بگیرید.",'vars'=>[$v('invoice_id','متنی',40)]],
            'invoice_created'=>['title'=>'ایجاد فاکتور','category'=>'پرداخت','body'=>"فاکتور %invoice_id% به مبلغ %amount% تومان ایجاد شد.\nبلوپنل",'vars'=>[$v('invoice_id','متنی',40),$v('amount','عددی',12)]],
            'invoice_expired'=>['title'=>'لغو یا انقضای فاکتور','category'=>'پرداخت','body'=>"مهلت پرداخت فاکتور %invoice_id% به پایان رسید و فاکتور لغو شد.\nبلوپنل",'vars'=>[$v('invoice_id','متنی',40)]],
            'refund_success'=>['title'=>'بازگشت وجه','category'=>'پرداخت','body'=>"مبلغ %amount% تومان بابت فاکتور %invoice_id% بازگشت داده شد.\nبلوپنل",'vars'=>[$v('amount','عددی',12),$v('invoice_id','متنی',40)]],
            'subscription_reminder'=>['title'=>'یادآوری پایان اشتراک','category'=>'اشتراک','body'=>"تنها %days_left% روز از اعتبار اشتراک شما باقی مانده است.\nبلوپنل",'vars'=>[$v('days_left','عددی',2)]],
            'subscription_expired'=>['title'=>'پایان اشتراک','category'=>'اشتراک','body'=>"اشتراک شما در بلوپنل به پایان رسید.\nبرای فعال‌سازی مجدد، اشتراک خود را تمدید کنید."],
            'low_remaining_volume'=>['title'=>'هشدار کاهش حجم','category'=>'اشتراک','body'=>"حجم باقی‌مانده اشتراک شما کمتر از %remaining_volume% گیگابایت است.\nبلوپنل",'vars'=>[$v('remaining_volume','عددی',4)]],
            'volume_expired'=>['title'=>'پایان حجم اشتراک','category'=>'اشتراک','body'=>"حجم اشتراک شما به پایان رسید.\nبرای ادامه استفاده، اشتراک خود را تمدید کنید."],
            'new_device_login'=>['title'=>'ورود از دستگاه جدید','category'=>'امنیت','body'=>"ورود جدید به حساب بلوپنل شما ثبت شد.\nدستگاه: %device%\nزمان: %date%",'vars'=>[$v('device','متنی',30),$v('date','متنی',16)]],
            'suspicious_login'=>['title'=>'هشدار ورود مشکوک','category'=>'امنیت','body'=>"ورود مشکوکی به حساب بلوپنل شما ثبت شد.\nاگر شما نبودید، سریعاً با پشتیبانی تماس بگیرید."],
            'device_connected'=>['title'=>'اتصال دستگاه جدید','category'=>'امنیت','body'=>'دستگاه %device% به حساب بلوپنل شما متصل شد.','vars'=>[$v('device','متنی',30)]],
            'device_removed'=>['title'=>'حذف دستگاه','category'=>'امنیت','body'=>'دستگاه %device% از حساب بلوپنل شما حذف شد.','vars'=>[$v('device','متنی',30)]],
            'phone_changed'=>['title'=>'تغییر شماره تلفن','category'=>'امنیت','body'=>"شماره تلفن حساب بلوپنل شما با موفقیت تغییر کرد.\nاگر شما نبودید، با پشتیبانی تماس بگیرید."],
            'phone_change_otp'=>['title'=>'کد تأیید تغییر شماره','category'=>'امنیت','body'=>"کد تأیید تغییر شماره در بلوپنل: %code%\nاین کد را در اختیار دیگران قرار ندهید.",'vars'=>[$v('code','متنی',6)],'default'=>1],
            'account_temporarily_blocked'=>['title'=>'مسدودشدن موقت حساب','category'=>'امنیت','body'=>'حساب بلوپنل شما به‌دلیل تلاش‌های ناموفق موقتاً مسدود شد.'],
            'account_unblocked'=>['title'=>'رفع مسدودی حساب','category'=>'امنیت','body'=>"محدودیت حساب شما در بلوپنل برداشته شد.\nاکنون می‌توانید وارد حساب شوید."],
            'account_status_changed'=>['title'=>'تغییر وضعیت حساب توسط مدیر','category'=>'حساب کاربری','body'=>'وضعیت حساب شما در بلوپنل به «%status%» تغییر یافت.','vars'=>[$v('status','متنی',20)]],
            'wallet_charged'=>['title'=>'افزایش موجودی کیف پول','category'=>'کیف پول','body'=>"کیف پول شما در بلوپنل به مبلغ %amount% تومان شارژ شد.\nموجودی: %balance% تومان",'vars'=>[$v('amount','عددی',12),$v('balance','عددی',12)]],
            'wallet_deducted'=>['title'=>'کسر از کیف پول','category'=>'کیف پول','body'=>"مبلغ %amount% تومان از کیف پول بلوپنل شما کسر شد.\nموجودی: %balance% تومان",'vars'=>[$v('amount','عددی',12),$v('balance','عددی',12)]],
            'wallet_insufficient'=>['title'=>'موجودی ناکافی کیف پول','category'=>'کیف پول','body'=>"موجودی کیف پول بلوپنل برای انجام این عملیات کافی نیست.\nموجودی: %balance% تومان",'vars'=>[$v('balance','عددی',12)]],
            'ticket_created'=>['title'=>'ثبت درخواست پشتیبانی','category'=>'پشتیبانی','body'=>"درخواست پشتیبانی شما با شماره %ticket_id% ثبت شد.\nبلوپنل",'vars'=>[$v('ticket_id','متنی',30)]],
            'ticket_replied'=>['title'=>'پاسخ پشتیبانی','category'=>'پشتیبانی','body'=>"به درخواست پشتیبانی %ticket_id% پاسخ داده شد.\nبرای مشاهده پاسخ وارد بلوپنل شوید.",'vars'=>[$v('ticket_id','متنی',30)]],
            'ticket_closed'=>['title'=>'بسته‌شدن درخواست پشتیبانی','category'=>'پشتیبانی','body'=>"درخواست پشتیبانی %ticket_id% بسته شد.\nبلوپنل",'vars'=>[$v('ticket_id','متنی',30)]],
            'service_disruption'=>['title'=>'اطلاع‌رسانی اختلال','category'=>'اطلاع‌رسانی','body'=>"کاربر گرامی، بخشی از خدمات بلوپنل دچار اختلال موقت شده است.\nدر حال رفع مشکل هستیم.",'broadcast'=>1],
            'service_restored'=>['title'=>'رفع اختلال','category'=>'اطلاع‌رسانی','body'=>"اختلال خدمات بلوپنل برطرف شد.\nاز شکیبایی شما سپاسگزاریم.",'broadcast'=>1],
            'scheduled_maintenance'=>['title'=>'تعمیرات برنامه‌ریزی‌شده','category'=>'اطلاع‌رسانی','body'=>'بلوپنل در تاریخ %date% از ساعت %start_time% تا %end_time% در حال به‌روزرسانی خواهد بود.','vars'=>[$v('date','متنی',10),$v('start_time','متنی',5),$v('end_time','متنی',5)],'broadcast'=>1],
            'new_version'=>['title'=>'انتشار نسخه جدید','category'=>'اطلاع‌رسانی','body'=>"نسخه جدید بلوپنل منتشر شد.\nبرای دریافت آخرین نسخه: %download_link%",'vars'=>[$v('download_link','متنی',100)],'broadcast'=>1],
            'required_update'=>['title'=>'الزام به‌روزرسانی','category'=>'اطلاع‌رسانی','body'=>"برای ادامه استفاده از بلوپنل، برنامه را به آخرین نسخه به‌روزرسانی کنید.\n%download_link%",'vars'=>[$v('download_link','متنی',100)],'broadcast'=>1],
            'admin_announcement'=>['title'=>'پیام عمومی مدیریت','category'=>'اطلاع‌رسانی','body'=>"اطلاعیه بلوپنل:\n%message%",'vars'=>[$v('message','متنی',120)],'broadcast'=>1],
        ];
    }

    public static function init(): void {
        add_filter('cron_schedules', [self::class, 'cron_schedules']);
        if ((string)get_option('bluevpn_sms_catalog_version','') !== self::CATALOG_VERSION) self::seed_templates();
        add_action(self::HOOK_PROCESS, [self::class, 'cron_process']);
        add_action(self::HOOK_WAKE, [self::class, 'cron_process']);
        self::schedule();
    }

    /** Events that are currently emitted automatically by a real BlueVPN runtime path. */
    public static function runtime_supported_events(): array {
        return [
            'auth_otp','welcome','account_activated','subscription_activated','admin_subscription_activated',
            'subscription_renewed','subscription_upgraded','subscription_plan_changed','payment_success','payment_failed',
            'invoice_created','invoice_expired','refund_success','subscription_reminder','subscription_expired',
            'low_remaining_volume','volume_expired','new_device_login','phone_changed','phone_change_otp',
            'account_temporarily_blocked','account_unblocked','account_status_changed','service_disruption','service_restored',
            'scheduled_maintenance','new_version','required_update','admin_announcement','device_removed'
        ];
    }

    public static function runtime_supports(string $eventKey): bool {
        return in_array($eventKey, self::runtime_supported_events(), true);
    }

    public static function cron_schedules(array $schedules): array {
        if (!isset($schedules['bluevpn_one_minute'])) $schedules['bluevpn_one_minute']=['interval'=>60,'display'=>'BlueVPN every minute'];
        return $schedules;
    }

    public static function schedule(): void {
        if (!wp_next_scheduled(self::HOOK_PROCESS)) wp_schedule_event(time()+30,'bluevpn_one_minute',self::HOOK_PROCESS);
    }

    public static function unschedule(): void {
        foreach ([self::HOOK_PROCESS,self::HOOK_WAKE] as $hook) {
            $ts=wp_next_scheduled($hook);
            while($ts){ wp_unschedule_event($ts,$hook); $ts=wp_next_scheduled($hook); }
        }
    }

    public static function settings(): array {
        global $wpdb;
        return $wpdb->get_row('SELECT * FROM '.BlueVPN_DB::table('sms_settings').' WHERE id=1 LIMIT 1', ARRAY_A) ?: [];
    }

    public static function seed_templates(): void {
        global $wpdb;
        $table = BlueVPN_DB::table('sms_templates');
        $now = BlueVPN_Utils::now_mysql();
        foreach (self::catalog() as $key => $spec) {
            $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE `key`=%s LIMIT 1", $key), ARRAY_A);
            $base = [
                'title'=>$spec['title'], 'category'=>$spec['category'], 'body'=>$spec['body'],
                'variables_json'=>BlueVPN_Utils::json_encode($spec['vars'] ?? []),
                'broadcast'=>!empty($spec['broadcast']) ? 1 : 0, 'updated_at'=>$now,
            ];
            if ($existing) {
                $wpdb->update($table, $base, ['key'=>$key]);
            } else {
                $base += ['key'=>$key,'pattern_code'=>'','enabled'=>!empty($spec['default'])?1:0];
                $wpdb->insert($table, $base);
            }
        }
        // 4.15.4: Manual CRM now reuses the canonical subscription templates
        // exactly, so remove the old duplicate manual-only template rows.
        $legacyManualKeys = [
            'manual_subscription_activated',
            'manual_subscription_renewed',
            'manual_subscription_reminder',
            'manual_subscription_expired',
        ];
        $placeholders = implode(',', array_fill(0, count($legacyManualKeys), '%s'));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE `key` IN ({$placeholders})",
            ...$legacyManualKeys
        ));

        // Keep legacy OTP settings and the auth template mirrored both ways.
        $s = self::settings();
        if ($s) {
            $auth = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE `key`=%s", 'auth_otp'), ARRAY_A);
            if ($auth) {
                if (trim((string)$auth['pattern_code']) === '' && trim((string)($s['pattern_code'] ?? '')) !== '') {
                    $wpdb->update($table, ['pattern_code'=>(string)$s['pattern_code'],'enabled'=>!empty($s['active'])?1:0], ['key'=>'auth_otp']);
                } elseif (trim((string)$auth['pattern_code']) !== '' && trim((string)($s['pattern_code'] ?? '')) === '') {
                    $wpdb->update(BlueVPN_DB::table('sms_settings'), ['pattern_code'=>(string)$auth['pattern_code'],'parameter_name'=>'code'], ['id'=>1]);
                }
            }
        }
        update_option('bluevpn_sms_catalog_version', self::CATALOG_VERSION, false);
    }

    private static function smart_normalize_text(string $text): string {
        $text = mb_strtolower(wp_strip_all_tags($text), 'UTF-8');
        $text = strtr($text, [
            'ي'=>'ی','ى'=>'ی','ك'=>'ک','ة'=>'ه','ۀ'=>'ه','ؤ'=>'و','إ'=>'ا','أ'=>'ا','ٱ'=>'ا',
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
        ]);
        $text = preg_replace('/%[A-Za-z0-9_\-]+%/u', ' ', $text) ?: $text;
        $text = preg_replace('/[^\p{L}\p{N}_]+/u', ' ', $text) ?: $text;
        return trim(preg_replace('/\s+/u', ' ', $text) ?: $text);
    }

    private static function smart_tokens(string $text): array {
        $text = self::smart_normalize_text($text);
        if ($text === '') return [];
        $stop = array_fill_keys([
            'bluevpn','بلوپنل','بلوویپیان','بلوویپی','شما','برای','این','آن','یک','و','یا','در','به','از','با','را','که','است','شد','شود','شده','کرد','کنید','می','اکنون','کاربر','گرامی'
        ], true);
        $out = [];
        foreach (preg_split('/\s+/u', $text) ?: [] as $token) {
            $token = trim($token);
            if ($token === '' || mb_strlen($token, 'UTF-8') < 2 || isset($stop[$token])) continue;
            $out[$token] = true;
        }
        return array_keys($out);
    }

    private static function smart_var_names(array $spec): array {
        $vars = [];
        foreach (($spec['vars'] ?? []) as $var) {
            $name = sanitize_key((string)($var['name'] ?? ''));
            if ($name !== '') $vars[$name] = true;
        }
        $out = array_keys($vars); sort($out, SORT_STRING); return $out;
    }

    private static function smart_provider_vars(array $pattern): array {
        $vars = [];
        foreach ((array)($pattern['variables'] ?? []) as $name) {
            $name = sanitize_key((string)$name);
            if ($name !== '') $vars[$name] = true;
        }
        $out = array_keys($vars); sort($out, SORT_STRING); return $out;
    }

    private static function smart_pattern_score(string $key, array $spec, array $pattern): array {
        $expected = self::smart_var_names($spec);
        $provided = self::smart_provider_vars($pattern);
        $score = 0;
        $reasons = [];

        if ($key === 'auth_otp' && count($expected) === 1 && count($provided) === 1) {
            // OTP transport can adapt its single parameter name to the Provider pattern.
            $score += 58; $reasons[] = 'OTP تک‌متغیره';
            if ($expected === $provided) { $score += 10; $reasons[] = 'متغیر دقیق'; }
        } elseif ($expected === $provided) {
            $score += $expected ? 58 : 12;
            $reasons[] = $expected ? 'متغیرها دقیق' : 'بدون متغیر';
        } else {
            // Never auto-wire a pattern whose attribute contract cannot be sent safely.
            return ['score'=>-1,'reason'=>'متغیر ناسازگار'];
        }

        $providerText = trim((string)($pattern['description'] ?? '').' '.(string)($pattern['text'] ?? ''));
        $providerNorm = self::smart_normalize_text($providerText);
        $titleNorm = self::smart_normalize_text((string)($spec['title'] ?? ''));
        $bodyNorm = self::smart_normalize_text((string)($spec['body'] ?? ''));
        $categoryNorm = self::smart_normalize_text((string)($spec['category'] ?? ''));

        if ($providerNorm !== '' && $bodyNorm !== '' && $providerNorm === $bodyNorm) {
            $score += 100; $reasons[] = 'متن دقیق';
        } elseif ($providerNorm !== '' && $bodyNorm !== '' && (str_contains($providerNorm, $bodyNorm) || str_contains($bodyNorm, $providerNorm))) {
            $score += 42; $reasons[] = 'متن بسیار نزدیک';
        }
        if ($providerNorm !== '' && $titleNorm !== '' && str_contains($providerNorm, $titleNorm)) {
            $score += 38; $reasons[] = 'عنوان داخل پترن';
        }

        $providerTokens = array_fill_keys(self::smart_tokens($providerText), true);
        $titleShared = 0;
        foreach (self::smart_tokens((string)($spec['title'] ?? '')) as $token) if (isset($providerTokens[$token])) $titleShared++;
        if ($titleShared) { $score += min(42, $titleShared * 14); $reasons[] = 'عنوان '.(int)$titleShared.' واژه مشترک'; }

        $bodyShared = 0;
        foreach (self::smart_tokens((string)($spec['body'] ?? '')) as $token) if (isset($providerTokens[$token])) $bodyShared++;
        if ($bodyShared) { $score += min(36, $bodyShared * 4); $reasons[] = 'متن '.(int)$bodyShared.' واژه مشترک'; }

        $categoryShared = 0;
        foreach (self::smart_tokens((string)($spec['category'] ?? '')) as $token) if (isset($providerTokens[$token])) $categoryShared++;
        if ($categoryShared) $score += min(8, $categoryShared * 4);

        // A few conflict terms are decisive when two templates share the same variables.
        $conflicts = [
            'payment_success'=>['ناموفق','لغو','بازگشت'], 'payment_failed'=>['موفق','بازگشت'],
            'subscription_activated'=>['تمدید','ارتقا','تغییر'], 'subscription_renewed'=>['فعال شد','ارتقا','تغییر'],
            'subscription_upgraded'=>['تمدید','ناموفق'], 'refund_success'=>['ناموفق','ایجاد'],
            'service_disruption'=>['برطرف'], 'service_restored'=>['اختلال موقت','در حال رفع'],
        ];
        foreach (($conflicts[$key] ?? []) as $term) {
            $term = self::smart_normalize_text($term);
            if ($term !== '' && str_contains($providerNorm, $term)) $score -= 36;
        }

        return ['score'=>$score,'reason'=>implode(' + ', $reasons) ?: 'تطبیق محتوا'];
    }

    /**
     * Safely maps IranPayamak patterns to BlueVPN message contracts.
     * Existing valid manual selections are preserved unless $overwrite=true.
     * Patterns are never auto-assigned when their variable contract is incompatible.
     */
    public static function smart_assign_patterns(array $patterns, bool $overwrite = false): array {
        global $wpdb;
        self::seed_templates();
        $table = BlueVPN_DB::table('sms_templates');
        $templates = $wpdb->get_results("SELECT * FROM {$table} ORDER BY category,title", ARRAY_A) ?: [];
        $catalog = self::catalog();
        $available = [];
        foreach ($patterns as $pattern) {
            if (!is_array($pattern)) continue;
            $code = trim((string)($pattern['code'] ?? ''));
            if ($code !== '') $available[$code] = $pattern;
        }

        $used = [];
        $skippedExisting = 0;
        if (!$overwrite) {
            foreach ($templates as $row) {
                $code = trim((string)($row['pattern_code'] ?? ''));
                if ($code !== '' && isset($available[$code])) { $used[$code] = true; $skippedExisting++; }
            }
        }

        $proposals = [];
        $ambiguous = [];
        $unmatched = [];
        foreach ($templates as $row) {
            $key = (string)($row['key'] ?? '');
            $spec = $catalog[$key] ?? null;
            if (!$spec) continue;
            $existing = trim((string)($row['pattern_code'] ?? ''));
            if (!$overwrite && $existing !== '' && isset($available[$existing])) continue;

            $candidates = [];
            foreach ($available as $code => $pattern) {
                if (isset($used[$code])) continue;
                $scored = self::smart_pattern_score($key, $spec, $pattern);
                if ((int)$scored['score'] < 0) continue;
                $candidates[] = ['code'=>$code,'score'=>(int)$scored['score'],'reason'=>(string)$scored['reason']];
            }
            usort($candidates, static fn(array $a,array $b): int => $b['score'] <=> $a['score']);
            if (!$candidates || (int)$candidates[0]['score'] < 72) { $unmatched[] = $key; continue; }
            $best = $candidates[0];
            $second = $candidates[1]['score'] ?? -999;
            if ((int)$best['score'] < 112 && ((int)$best['score'] - (int)$second) < 7) {
                $ambiguous[] = ['key'=>$key,'title'=>(string)$spec['title'],'best'=>(string)$best['code'],'score'=>(int)$best['score']];
                continue;
            }
            $proposals[] = ['key'=>$key,'title'=>(string)$spec['title']] + $best;
        }

        // Highest-confidence matches claim a Provider pattern first.
        usort($proposals, static fn(array $a,array $b): int => $b['score'] <=> $a['score']);
        $assigned = 0;
        $mappings = [];
        $assignedKeys = [];
        foreach ($proposals as $proposal) {
            $key = (string)$proposal['key']; $code = (string)$proposal['code'];
            if (isset($assignedKeys[$key]) || isset($used[$code])) continue;
            $ok = $wpdb->update($table, ['pattern_code'=>$code,'updated_at'=>BlueVPN_Utils::now_mysql()], ['key'=>$key]);
            if ($ok === false) continue;
            $assigned++; $assignedKeys[$key] = true; $used[$code] = true;
            $confidence = max(50, min(99, (int)round(55 + (((int)$proposal['score'] - 72) * 0.55))));
            $mappings[] = [
                'key'=>$key,'title'=>(string)$proposal['title'],'code'=>$code,
                'score'=>(int)$proposal['score'],'confidence'=>$confidence,'reason'=>(string)$proposal['reason'],
            ];
        }

        // Keep the canonical OTP settings mirrored with the smart auth mapping.
        foreach ($mappings as $map) {
            if ($map['key'] !== 'auth_otp') continue;
            $settingsTable = BlueVPN_DB::table('sms_settings');
            $current = $wpdb->get_row("SELECT id,active,parameter_name FROM {$settingsTable} WHERE id=1", ARRAY_A) ?: [];
            if ($current) {
                $wpdb->update($settingsTable, [
                    'pattern_code'=>$map['code'],
                    'parameter_name'=>BlueVPN_SMS_OTP::preferred_otp_parameter($map['code'], (string)($current['parameter_name'] ?? 'code')),
                    'updated_at'=>BlueVPN_Utils::now_mysql(),
                ], ['id'=>1]);
            }
        }

        $report = [
            'generated_at'=>BlueVPN_Utils::now_mysql(),'overwrite'=>$overwrite?1:0,'assigned'=>$assigned,
            'skipped_existing'=>$skippedExisting,'ambiguous'=>$ambiguous,'unmatched'=>$unmatched,'mappings'=>$mappings,
            'provider_count'=>count($available),
        ];
        update_option('bluevpn_sms_smart_map_report_v1', $report, false);
        return $report;
    }

    public static function smart_assignment_report(): array {
        $report = get_option('bluevpn_sms_smart_map_report_v1', []);
        return is_array($report) ? $report : [];
    }

    public static function templates(): array {
        global $wpdb;
        self::seed_templates();
        return $wpdb->get_results('SELECT * FROM '.BlueVPN_DB::table('sms_templates').' ORDER BY category,title', ARRAY_A) ?: [];
    }

    public static function spec(string $eventKey): ?array {
        $catalog = self::catalog();
        return $catalog[$eventKey] ?? null;
    }

    private static function normalized_phone(string $phone): string {
        $phone = BlueVPN_Utils::sanitize_phone($phone);
        if (!preg_match('/^\+989\d{9}$/', $phone)) throw new RuntimeException('شماره موبایل معتبر نیست.');
        return $phone;
    }

    private static function clean_params(array $spec, array $params): array {
        $out = [];
        foreach (($spec['vars'] ?? []) as $var) {
            $name = (string)$var['name'];
            if (!array_key_exists($name, $params)) throw new RuntimeException('پارامتر '.$name.' برای پیام «'.$spec['title'].'» ارسال نشده است.');
            $value = trim((string)$params[$name]);
            if (($var['type'] ?? '') === 'عددی') {
                $value = strtr($value, '۰۱۲۳۴۵۶۷۸۹', '0123456789');
                $value = preg_replace('/\D+/', '', $value) ?: '';
                if ($value === '') throw new RuntimeException('پارامتر '.$name.' باید عددی باشد.');
            }
            $out[$name] = mb_substr($value, 0, max(1, (int)($var['length'] ?? 160)));
        }
        return $out;
    }

    private static function line_number(array $s): string {
        $line = preg_replace('/\s+/', '', strtr((string)($s['from_number'] ?? ''), '۰۱۲۳۴۵۶۷۸۹', '0123456789')) ?: '';
        return preg_match('/^[+0-9A-Za-z_-]{3,32}$/', $line) ? $line : '';
    }

    public static function notification_ready(): bool {
        $s = self::settings();
        return !empty($s['notification_active'])
            && BlueVPN_Utils::decrypt_secret((string)($s['api_key_enc'] ?? '')) !== ''
            && self::line_number($s) !== '';
    }

    private static function provider_message(array $payload, string $fallback): string {
        if (isset($payload['meta']) && is_array($payload['meta']) && !empty($payload['meta']['message'])) return mb_substr(wp_strip_all_tags((string)$payload['meta']['message']),0,500);
        foreach (['message','error','messages','detail'] as $key) {
            $value = $payload[$key] ?? '';
            if (is_array($value)) $value = $value['message'] ?? $value['detail'] ?? wp_json_encode($value);
            $value = trim(wp_strip_all_tags((string)$value));
            if ($value !== '') return mb_substr($value,0,500);
        }
        return $fallback;
    }

    private static function record_provider_health(bool $ok, string $message): void {
        global $wpdb;
        $wpdb->update(BlueVPN_DB::table('sms_settings'), [
            'last_test_ok'=>$ok?1:0,
            'last_test_message'=>mb_substr(wp_strip_all_tags($message),0,1000),
            'last_test_at'=>BlueVPN_Utils::now_mysql(),
            'updated_at'=>BlueVPN_Utils::now_mysql(),
        ], ['id'=>1]);
    }

    public static function send_pattern(string $phone, string $patternCode, array $params): array {
        $s = self::settings();
        $apiKey = BlueVPN_Utils::decrypt_secret((string)($s['api_key_enc'] ?? ''));
        $patternCode = trim($patternCode);
        $line = self::line_number($s);
        if ($apiKey === '') throw new RuntimeException('API Key ایران‌پیامک ثبت نشده است.');
        if ($patternCode === '') throw new RuntimeException('کد پترن این پیام ثبت نشده است.');
        if ($line === '') throw new RuntimeException('شماره خط ارسال معتبر نیست.');
        $phone = self::normalized_phone($phone);
        $base = untrailingslashit((string)($s['base_url'] ?: 'https://api.iranpayamak.com/ws/v1'));
        if ($base === '' || stripos($base, 'edge.ippanel.com') !== false) $base = 'https://api.iranpayamak.com/ws/v1';
        $payload = [
            'code'=>$patternCode,
            // Critical: zero-variable patterns must be encoded as {} not [].
            'attributes'=>$params ? $params : (object)[],
            'recipient'=>BlueVPN_Utils::local_phone($phone),
            'number_format'=>'english',
            'line_number'=>$line,
        ];
        $res = wp_remote_post($base.'/sms/pattern', [
            'timeout'=>10,'redirection'=>2,'sslverify'=>!isset($s['verify_tls']) || (bool)$s['verify_tls'],
            'headers'=>['Api-Key'=>$apiKey,'Content-Type'=>'application/json','Accept'=>'application/json','User-Agent'=>'BlueVPN-WordPress-SMS/'.BLUEVPN_MANAGER_VERSION],
            'body'=>wp_json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        ]);
        if (is_wp_error($res)) {
            $msg='ارتباط با ایران‌پیامک برقرار نشد: '.$res->get_error_message();
            self::record_provider_health(false,$msg);
            throw new RuntimeException($msg);
        }
        $status = (int)wp_remote_retrieve_response_code($res);
        $raw = (string)wp_remote_retrieve_body($res);
        $decoded = json_decode($raw,true); $data = is_array($decoded) ? $decoded : [];
        if ($status < 200 || $status >= 300) {
            $fallback = in_array($status,[401,403],true) ? 'API Key ایران‌پیامک معتبر نیست یا مجوز ارسال ندارد.' : 'ایران‌پیامک ارسال پیام را رد کرد (HTTP '.$status.').';
            $message=self::provider_message($data,$fallback);
            self::record_provider_health(false,'HTTP '.$status.': '.$message);
            throw new RuntimeException($message);
        }
        if ($raw === '' || !is_array($decoded)) {
            self::record_provider_health(false,'پاسخ Provider معتبر نبود (HTTP '.$status.').');
            throw new RuntimeException('ایران‌پیامک پاسخ معتبر JSON برنگرداند.');
        }
        if ((isset($data['success']) && $data['success'] === false) || (isset($data['status']) && $data['status'] === false) || (isset($data['meta']['status']) && $data['meta']['status'] === false)) {
            $message=self::provider_message($data,'ایران‌پیامک ارسال پیام را رد کرد.');
            self::record_provider_health(false,'Provider rejected: '.$message);
            throw new RuntimeException($message);
        }
        self::record_provider_health(true,'Provider accepted SMS pattern request (HTTP '.$status.').');
        return $data;
    }

    /** Send one configured template synchronously (admin test / diagnostics). */
    public static function send_template_now(string $eventKey, string $phone, array $params = []): array {
        global $wpdb;
        $spec = self::spec($eventKey);
        if (!$spec) throw new RuntimeException('نوع پیام شناخته‌شده نیست.');
        $template = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.BlueVPN_DB::table('sms_templates').' WHERE `key`=%s LIMIT 1',$eventKey), ARRAY_A);
        if (!$template || trim((string)($template['pattern_code'] ?? '')) === '') throw new RuntimeException('کد پترن این پیام ثبت نشده است.');
        $clean = self::clean_params($spec,$params);
        return self::send_pattern($phone,(string)$template['pattern_code'],$clean);
    }

    private static function kick_queue(): void {
        // HOOK_PROCESS is recurring and therefore cannot be used as an immediate
        // wake signal: wp_next_scheduled() is almost always true. Use a dedicated
        // single-shot hook and ask WordPress to spawn the loopback cron worker now.
        if (!wp_next_scheduled(self::HOOK_WAKE)) {
            wp_schedule_single_event(time(), self::HOOK_WAKE);
        }
        if (function_exists('spawn_cron')) {
            try { spawn_cron(time()); } catch (Throwable $e) { /* shutdown flush remains the fallback */ }
        }
        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            // Do not rely only on WP-Cron. Flush a small batch at the end of the
            // current request; on PHP-FPM the HTTP response is released first.
            add_action('shutdown',[self::class,'shutdown_flush'],PHP_INT_MAX);
        }
    }

    public static function shutdown_flush(): void {
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        @ignore_user_abort(true);
        try { self::process(24); } catch (Throwable $e) { BlueVPN_Error_Monitor::legacy_error_log('BlueVPN SMS shutdown flush: '.$e->getMessage()); }
    }

    /** Wake the worker after a caller commits a DB transaction containing outbox rows. */
    public static function wake_queue(): void { self::kick_queue(); }

    public static function queue(string $eventKey, string $phone, array $params = [], ?int $customerId = null, ?string $orderId = null, string $dedupeSeed = '', bool $force = false, bool $kick = true, ?int $manualCustomerId = null): ?string {
        global $wpdb;
        $spec = self::spec($eventKey);
        if (!$spec) return null;
        $template = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.BlueVPN_DB::table('sms_templates').' WHERE `key`=%s LIMIT 1',$eventKey), ARRAY_A);
        $s = self::settings();
        if (!$template || !$s) return null;
        $global = in_array($eventKey,['auth_otp','phone_change_otp'],true) ? !empty($s['active']) : !empty($s['notification_active']);
        if (!$force && (!$global || empty($template['enabled']) || trim((string)$template['pattern_code']) === '')) return null;
        $phone = self::normalized_phone($phone);
        $clean = self::clean_params($spec,$params);
        $seed = $dedupeSeed !== '' ? $dedupeSeed : gmdate('YmdHi');
        $dedupe = hash('sha256', BlueVPN_Utils::json_encode([$eventKey,$phone,$clean,$seed]));
        $id = BlueVPN_Utils::random_uuid4();
        $ok = $wpdb->insert(BlueVPN_DB::table('sms_deliveries'), [
            'id'=>$id,'event_key'=>$eventKey,'customer_id'=>$customerId ?: null,'manual_customer_id'=>$manualCustomerId ?: null,'order_id'=>$orderId ?: null,
            'phone'=>$phone,'params_json'=>BlueVPN_Utils::json_encode($clean),'dedupe_key'=>$dedupe,'status'=>'pending','attempts'=>0,
            'max_attempts'=>max(1,min(5,(int)($s['retry_max_attempts'] ?? 3))),'provider_message_id'=>'','provider_delivery_status'=>'unknown','provider_delivery_at'=>null,'response_json'=>'','last_error'=>'',
            'next_attempt_at'=>BlueVPN_Utils::now_mysql(),'sending_started_at'=>null,'sent_at'=>null,'created_at'=>BlueVPN_Utils::now_mysql(),
        ]);
        if ($ok === false) {
            // Duplicate deliveries are intentionally ignored; other DB errors are logged.
            if (stripos((string)$wpdb->last_error,'duplicate') === false) BlueVPN_Error_Monitor::legacy_error_log('BlueVPN SMS queue DB error: '.$wpdb->last_error);
            return null;
        }
        if ($kick) self::kick_queue();
        return $id;
    }

    /**
     * Durable outbox + foreground delivery for user-visible events.
     * The row is committed first; dispatch_now updates the exact same row so
     * history, dedupe and Retry remain canonical even when the provider fails.
     */
    public static function queue_and_dispatch(
        string $eventKey,
        string $phone,
        array $params = [],
        ?int $customerId = null,
        ?string $orderId = null,
        string $dedupeSeed = '',
        bool $force = false,
        ?int $manualCustomerId = null
    ): array {
        $id=self::queue($eventKey,$phone,$params,$customerId,$orderId,$dedupeSeed,$force,true,$manualCustomerId);
        if(!$id)return ['ok'=>false,'queued'=>false,'sent'=>false,'status'=>'not_queued','message'=>'پیام در Outbox ثبت نشد.'];
        $result=self::dispatch_now($id);
        return [
            'ok'=>!empty($result['ok']),'queued'=>true,'sent'=>!empty($result['sent']),
            'status'=>(string)($result['status']??'queued'),'message'=>(string)($result['message']??''),
            'delivery_id'=>$id,
        ];
    }

    private static function provider_id(array $response): string {
        foreach (['message_id','messageId','id','uid'] as $k) if (isset($response[$k]) && is_scalar($response[$k])) return mb_substr((string)$response[$k],0,180);
        if (isset($response['data']) && is_array($response['data'])) foreach (['message_id','messageId','id','uid'] as $k) if (isset($response['data'][$k]) && is_scalar($response['data'][$k])) return mb_substr((string)$response['data'][$k],0,180);
        // IranPayamak's documented pattern response may expose `data` as a
        // scalar. Preserve it as the provider reference when present.
        if (array_key_exists('data',$response) && is_scalar($response['data'])) return mb_substr((string)$response['data'],0,180);
        return '';
    }

    /**
     * Attempt one exact queued delivery immediately.
     *
     * Used by foreground admin actions where the operator expects the same
     * subscription SMS to be attempted as soon as the plan is assigned.
     * Retry state remains durable in sms_deliveries if the provider fails.
     */
    public static function dispatch_now(string $deliveryId): array {
        global $wpdb;
        $deliveryId = trim($deliveryId);
        if ($deliveryId === '') {
            return ['ok'=>false,'status'=>'missing','sent'=>false,'message'=>'شناسه پیامک خالی است.'];
        }

        $table = BlueVPN_DB::table('sms_deliveries');
        $lockName = 'bluevpn_sms_' . substr(hash('sha256', $deliveryId), 0, 32);
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $lockName)) !== 1) {
            self::wake_queue();
            return ['ok'=>true,'status'=>'queued','sent'=>false,'message'=>'پیامک در صف ارسال قرار دارد.'];
        }

        try {
            $fresh = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$table} WHERE id=%s LIMIT 1", $deliveryId),
                ARRAY_A
            );
            if (!$fresh) {
                return ['ok'=>false,'status'=>'missing','sent'=>false,'message'=>'پیامک در صف پیدا نشد.'];
            }

            $status = (string)($fresh['status'] ?? '');
            if ($status === 'sent') {
                return ['ok'=>true,'status'=>'sent','sent'=>true,'message'=>'پیامک ارسال شده است.'];
            }
            if (!in_array($status, ['pending','retry'], true)) {
                return [
                    'ok'=>$status !== 'failed',
                    'status'=>$status,
                    'sent'=>false,
                    'message'=>(string)($fresh['last_error'] ?? 'پیامک در وضعیت قابل ارسال نیست.'),
                ];
            }

            $template = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT * FROM '.BlueVPN_DB::table('sms_templates').' WHERE `key`=%s LIMIT 1',
                    (string)$fresh['event_key']
                ),
                ARRAY_A
            );
            $spec = self::spec((string)$fresh['event_key']);
            if (!$template || !$spec || empty($template['enabled']) || trim((string)$template['pattern_code']) === '') {
                $message = 'پترن غیرفعال یا بدون کد پترن است.';
                $wpdb->update(
                    $table,
                    ['status'=>'skipped','last_error'=>$message,'next_attempt_at'=>null],
                    ['id'=>$deliveryId]
                );
                return ['ok'=>false,'status'=>'skipped','sent'=>false,'message'=>$message];
            }

            $attempts = (int)$fresh['attempts'] + 1;
            $wpdb->update(
                $table,
                [
                    'status'=>'sending',
                    'attempts'=>$attempts,
                    'sending_started_at'=>BlueVPN_Utils::now_mysql(),
                ],
                ['id'=>$deliveryId]
            );

            try {
                $params = BlueVPN_Utils::json_decode_array((string)$fresh['params_json'], []);
                $response = self::send_pattern(
                    (string)$fresh['phone'],
                    (string)$template['pattern_code'],
                    self::clean_params($spec, $params)
                );
                $persisted=$wpdb->update(
                    $table,
                    [
                        'status'=>'sent',
                        'sent_at'=>BlueVPN_Utils::now_mysql(),
                        'provider_message_id'=>self::provider_id($response),
                        'provider_delivery_status'=>'provider_accepted',
                        'provider_delivery_at'=>BlueVPN_Utils::now_mysql(),
                        'response_json'=>mb_substr(BlueVPN_Utils::json_encode($response),0,8000),
                        'last_error'=>'',
                        'next_attempt_at'=>null,
                        'sending_started_at'=>null,
                    ],
                    ['id'=>$deliveryId]
                );
                if($persisted===false){
                    $dbError=trim((string)$wpdb->last_error);
                    if(class_exists('BlueVPN_Error_Monitor')){
                        BlueVPN_Error_Monitor::report(
                            'runtime',
                            'sms',
                            'error',
                            'SMS_SENT_DB_PERSIST_FAILED',
                            $dbError!==''?$dbError:'پیامک توسط سرویس پذیرفته شد اما ثبت وضعیت ارسال در دیتابیس ناموفق بود.',
                            ['delivery_id'=>$deliveryId,'event_key'=>(string)$fresh['event_key'],'phone'=>(string)$fresh['phone'],'manual_customer_id'=>(int)($fresh['manual_customer_id']??0)]
                        );
                    }
                    return ['ok'=>false,'status'=>'sent_unpersisted','sent'=>true,'message'=>'پیامک ارسال شد اما ثبت نتیجه در دیتابیس ناموفق بود.','db_error'=>$dbError];
                }
                return ['ok'=>true,'status'=>'sent','sent'=>true,'message'=>'پیامک ارسال شد.'];
            } catch (Throwable $e) {
                $max = max(1, (int)$fresh['max_attempts']);
                if ($attempts >= $max) {
                    $retryStatus = 'failed';
                    $next = null;
                } else {
                    $retryStatus = 'retry';
                    $delay = self::RETRY_DELAYS[min($attempts - 1, count(self::RETRY_DELAYS) - 1)];
                    $next = gmdate('Y-m-d H:i:s', time() + $delay);
                }
                $message = mb_substr($e->getMessage(), 0, 2000);
                $wpdb->update(
                    $table,
                    [
                        'status'=>$retryStatus,
                        'last_error'=>$message,
                        'next_attempt_at'=>$next,
                        'sending_started_at'=>null,
                    ],
                    ['id'=>$deliveryId]
                );
                if ($retryStatus === 'retry') self::wake_queue();
                return [
                    'ok'=>false,
                    'status'=>$retryStatus,
                    'sent'=>false,
                    'message'=>$message,
                ];
            }
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
        }
    }

    public static function process(int $limit = 50): array {
        global $wpdb;
        $table = BlueVPN_DB::table('sms_deliveries');
        $now = BlueVPN_Utils::now_mysql();
        // Recover rows left in sending state if PHP/WP was terminated mid-request.
        $stale = gmdate('Y-m-d H:i:s', time()-10*MINUTE_IN_SECONDS);
        $wpdb->query($wpdb->prepare("UPDATE {$table} SET status='retry',next_attempt_at=%s,sending_started_at=NULL,last_error=CONCAT(IFNULL(last_error,''),' | recovered stale sending') WHERE status='sending' AND sending_started_at IS NOT NULL AND sending_started_at<%s",$now,$stale));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE status IN ('pending','retry') AND (next_attempt_at IS NULL OR next_attempt_at<=%s) ORDER BY created_at ASC LIMIT %d",$now,max(1,min(100,$limit))),ARRAY_A) ?: [];
        $sent=0;$failed=0;
        foreach ($rows as $row) {
            $lockName='bluevpn_sms_'.substr(hash('sha256',(string)$row['id']),0,32);
            if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)',$lockName))!==1) continue;
            try {
                $fresh=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%s LIMIT 1",$row['id']),ARRAY_A);
                if(!$fresh||!in_array((string)$fresh['status'],['pending','retry'],true))continue;
                $template=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.BlueVPN_DB::table('sms_templates').' WHERE `key`=%s LIMIT 1',$fresh['event_key']),ARRAY_A);
                $spec=self::spec((string)$fresh['event_key']);
                if(!$template||!$spec||empty($template['enabled'])||trim((string)$template['pattern_code'])===''){
                    $wpdb->update($table,['status'=>'skipped','last_error'=>'پترن غیرفعال یا بدون کد پترن است.','next_attempt_at'=>null],['id'=>$fresh['id']]);$failed++;continue;
                }
                $attempts=(int)$fresh['attempts']+1;
                $wpdb->update($table,['status'=>'sending','attempts'=>$attempts,'sending_started_at'=>BlueVPN_Utils::now_mysql()],['id'=>$fresh['id']]);
                try {
                    $params=BlueVPN_Utils::json_decode_array((string)$fresh['params_json'],[]);
                    $response=self::send_pattern((string)$fresh['phone'],(string)$template['pattern_code'],self::clean_params($spec,$params));
                    $persisted=$wpdb->update($table,['status'=>'sent','sent_at'=>BlueVPN_Utils::now_mysql(),'provider_message_id'=>self::provider_id($response),'provider_delivery_status'=>'provider_accepted','provider_delivery_at'=>BlueVPN_Utils::now_mysql(),'response_json'=>mb_substr(BlueVPN_Utils::json_encode($response),0,8000),'last_error'=>'','next_attempt_at'=>null,'sending_started_at'=>null],['id'=>$fresh['id']]);
                    if($persisted===false){
                        $dbError=trim((string)$wpdb->last_error);
                        if(class_exists('BlueVPN_Error_Monitor'))BlueVPN_Error_Monitor::report('runtime','sms','error','SMS_SENT_DB_PERSIST_FAILED',$dbError!==''?$dbError:'پیامک ارسال شد اما ثبت نتیجه در دیتابیس ناموفق بود.',['delivery_id'=>(string)$fresh['id'],'event_key'=>(string)$fresh['event_key'],'phone'=>(string)$fresh['phone'],'manual_customer_id'=>(int)($fresh['manual_customer_id']??0)]);
                        $failed++;
                    }else{$sent++;}
                } catch (Throwable $e) {
                    $max=max(1,(int)$fresh['max_attempts']);
                    if($attempts>=$max){$status='failed';$next=null;}else{$status='retry';$delay=self::RETRY_DELAYS[min($attempts-1,count(self::RETRY_DELAYS)-1)];$next=gmdate('Y-m-d H:i:s',time()+$delay);}
                    $wpdb->update($table,['status'=>$status,'last_error'=>mb_substr($e->getMessage(),0,2000),'next_attempt_at'=>$next,'sending_started_at'=>null],['id'=>$fresh['id']]);$failed++;
                }
            } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lockName)); }
        }
        return ['processed'=>count($rows),'sent'=>$sent,'failed'=>$failed];
    }

    public static function cron_process(): void {
        try {
            self::scan_subscription_notifications();
            if (class_exists('BlueVPN_Manual_Customers')) {
                BlueVPN_Manual_Customers::scan_notifications();
            }
            self::scan_order_notifications();
            self::process(60);
        } catch (Throwable $e) { BlueVPN_Error_Monitor::legacy_error_log('BlueVPN SMS cron: '.$e->getMessage()); }
    }

    /**
     * Reconcile durable order notifications. This is the safety net for the very
     * small window between committing a payment/order state and PHP finishing the
     * request. Dedupe keys make every recovery pass idempotent.
     */
    public static function scan_order_notifications(): array {
        global $wpdb;
        $s=self::settings();
        if(empty($s['notification_active'])) return ['scanned'=>0,'queued'=>0];
        $orders=BlueVPN_DB::table('orders');$customers=BlueVPN_DB::table('customers');$plans=BlueVPN_DB::table('plans');$deliveries=BlueVPN_DB::table('sms_deliveries');
        $rows=$wpdb->get_results("SELECT o.id,o.order_code,o.customer_id,o.plan_id,o.amount_toman,o.status,o.payment_id,o.payment_url,o.activated_at,c.phone,c.subscription_expire,p.title AS plan_title FROM {$orders} o JOIN {$customers} c ON c.id=o.customer_id LEFT JOIN {$plans} p ON p.id=o.plan_id WHERE c.phone IS NOT NULL AND c.phone<>'' ORDER BY o.created_at DESC LIMIT 700",ARRAY_A)?:[];
        $queued=0;
        foreach($rows as $row){
            $status=strtolower((string)$row['status']);$orderId=(string)$row['id'];$phone=(string)$row['phone'];$customerId=(int)$row['customer_id'];$invoice=mb_substr((string)$row['order_code'],0,40);
            // Any valid remote invoice must have an invoice-created notification.
            if(!empty($row['payment_id'])&&!empty($row['payment_url'])&&!in_array($status,['invoice_failed','amount_mismatch'],true)){
                $queued+=self::queue('invoice_created',$phone,['invoice_id'=>$invoice,'amount'=>(int)$row['amount_toman']],$customerId,$orderId,'invoice-created:'.$orderId)?1:0;
            }
            if($status==='activated'||!empty($row['activated_at'])){
                if((int)$row['amount_toman']>0)$queued+=self::queue('payment_success',$phone,['amount'=>(int)$row['amount_toman'],'invoice_id'=>$invoice],$customerId,$orderId,'payment-success:'.$orderId)?1:0;
                // Preserve the original renewed/upgraded/changed message if it was
                // already queued. Only synthesize generic activation if the request
                // died before *any* subscription event reached the outbox.
                $existing=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$deliveries} WHERE order_id=%s AND event_key IN ('subscription_activated','subscription_renewed','subscription_upgraded','subscription_plan_changed','admin_subscription_activated')",$orderId));
                if($existing===0&&!empty($row['plan_title'])){
                    $queued+=self::queue('subscription_activated',$phone,['plan'=>mb_substr((string)$row['plan_title'],0,40),'expire_date'=>self::jalali_date((string)($row['subscription_expire']??''))],$customerId,$orderId,'subscription:'.$orderId.':subscription_activated')?1:0;
                }
            }elseif($status==='refunded'){
                $queued+=self::queue('refund_success',$phone,['amount'=>(int)$row['amount_toman'],'invoice_id'=>$invoice],$customerId,$orderId,'refund:'.$orderId)?1:0;
            }elseif(in_array($status,['expired','canceled','cancelled'],true)){
                $queued+=self::queue('invoice_expired',$phone,['invoice_id'=>$invoice],$customerId,$orderId,'invoice-expired:'.$orderId)?1:0;
            }elseif(in_array($status,['failed','rejected','amount_mismatch','invoice_failed'],true)){
                $queued+=self::queue('payment_failed',$phone,['invoice_id'=>$invoice],$customerId,$orderId,'payment-failed-recovery:'.$orderId.':'.$status)?1:0;
            }
        }
        return ['scanned'=>count($rows),'queued'=>$queued];
    }

    public static function scan_subscription_notifications(): array {
        global $wpdb;
        $s=self::settings();
        if(empty($s['notification_active'])) return ['scanned'=>0,'queued'=>0];
        $days=BlueVPN_Utils::json_decode_array((string)($s['reminder_days_json'] ?? '[3,2,1]'),[3,2,1]);
        $days=array_values(array_unique(array_filter(array_map('intval',$days),fn($x)=>$x>=1&&$x<=30)));
        if(!$days)$days=[3,2,1];
        $threshold=max(1,min(9999,(int)($s['low_volume_threshold_gb'] ?? 5)));
        $customers=$wpdb->get_results("SELECT id,phone,plan_id,subscription_expire,data_limit_bytes,used_traffic_bytes FROM ".BlueVPN_DB::table('customers')." WHERE active=1 AND phone IS NOT NULL AND phone<>''",ARRAY_A)?:[];
        $queued=0;$now=time();
        foreach($customers as $c){
            $phone=(string)$c['phone'];$expiryTs=!empty($c['subscription_expire'])?(strtotime((string)$c['subscription_expire'].' UTC')?:0):0;
            if($expiryTs>0){
                $seconds=$expiryTs-$now;$daysLeft=$seconds>0?(int)ceil($seconds/DAY_IN_SECONDS):0;$seed=gmdate('YmdHi',$expiryTs);
                if(in_array($daysLeft,$days,true))$queued+=self::queue('subscription_reminder',$phone,['days_left'=>$daysLeft],(int)$c['id'],null,'expiry:'.$c['id'].':'.$seed.':day:'.$daysLeft)?1:0;
                if($seconds<=0)$queued+=self::queue('subscription_expired',$phone,[],(int)$c['id'],null,'expired:'.$c['id'].':'.$seed)?1:0;
            }
            $limit=max(0,(int)$c['data_limit_bytes']);$used=max(0,(int)$c['used_traffic_bytes']);
            if($limit>0){$remaining=max(0,$limit-$used);$gb=(int)floor($remaining/(1024**3));$cycle=$expiryTs?gmdate('YmdHi',$expiryTs):(string)($c['plan_id']??0);
                if($remaining<=0)$queued+=self::queue('volume_expired',$phone,[],(int)$c['id'],null,'volume-expired:'.$c['id'].':'.$cycle)?1:0;
                elseif($gb<=$threshold)$queued+=self::queue('low_remaining_volume',$phone,['remaining_volume'=>max(1,$gb)],(int)$c['id'],null,'low-volume:'.$c['id'].':'.$cycle.':'.$gb)?1:0;
            }
        }
        return ['scanned'=>count($customers),'queued'=>$queued];
    }

    public static function retry(string $deliveryId): bool {
        global $wpdb;
        return $wpdb->update(BlueVPN_DB::table('sms_deliveries'),['status'=>'retry','attempts'=>0,'last_error'=>'','provider_delivery_status'=>'unknown','provider_delivery_at'=>null,'next_attempt_at'=>BlueVPN_Utils::now_mysql(),'sending_started_at'=>null],['id'=>$deliveryId])!==false;
    }

    public static function broadcast(string $eventKey, array $params, bool $onlyActive=true): int {
        global $wpdb;$spec=self::spec($eventKey);if(!$spec||empty($spec['broadcast']))throw new RuntimeException('این پیام برای ارسال عمومی مجاز نیست.');
        $where="phone IS NOT NULL AND phone<>''".($onlyActive?' AND active=1':'');
        $rows=$wpdb->get_results('SELECT id,phone FROM '.BlueVPN_DB::table('customers').' WHERE '.$where,ARRAY_A)?:[];
        $seed='broadcast:'.gmdate('YmdHis').':'.substr(bin2hex(random_bytes(4)),0,8);$count=0;
        foreach($rows as $c)$count+=self::queue($eventKey,(string)$c['phone'],$params,(int)$c['id'],null,$seed.':'.$c['id'])?1:0;
        return $count;
    }

    public static function jalali_date(?string $utcMysql): string {
        $v=BlueVPN_Utils::tehran_datetime_fa($utcMysql,false);return $v!==''?substr($v,0,10):'نامحدود';
    }
}
