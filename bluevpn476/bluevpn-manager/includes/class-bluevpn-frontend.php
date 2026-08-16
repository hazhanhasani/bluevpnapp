<?php
if (!defined('ABSPATH')) exit;

final class BlueVPN_Frontend {
    public static function init(): void {
        add_action('template_redirect', [self::class, 'maybe_render_login']);
        add_shortcode('bluevpn_otp_login', [self::class, 'shortcode']);
    }

    public static function shortcode(): string {
        ob_start();
        self::render_markup(false);
        return (string)ob_get_clean();
    }

    public static function maybe_render_login(): void {
        if (!get_query_var('bluevpn_login')) return;
        status_header(200);
        nocache_headers();
        header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="'.esc_attr(get_bloginfo('charset')).'"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>ورود به BlueVPN</title>';
        echo '<link rel="stylesheet" href="'.esc_url(BLUEVPN_MANAGER_URL.'assets/otp-login.css?ver='.rawurlencode(BLUEVPN_MANAGER_VERSION)).'">';
        echo '</head><body class="bluevpn-otp-page">';
        self::render_markup(true);
        echo '</body></html>';
        exit;
    }

    private static function config(): array {
        $otp = class_exists('BlueVPN_SMS_OTP') ? BlueVPN_SMS_OTP::public_config() : ['ready'=>false,'otp_length'=>6,'resend_seconds'=>60];
        return [
            'requestUrl' => rest_url('bluevpn/v1/auth/otp/request'),
            'verifyUrl' => rest_url('bluevpn/v1/auth/otp/verify'),
            'otpLength' => 6,
            'resendSeconds' => (int)($otp['resend_seconds'] ?? 60),
            'smsReady' => !empty($otp['ready']),
            'appName' => 'BlueVPN',
        ];
    }

    private static function render_markup(bool $standalone): void {
        $cfg = self::config();
        $id = 'bluevpnOtp' . wp_rand(1000, 99999);
        echo '<div class="bvotp-root" id="'.esc_attr($id).'">';
        echo '<div class="bvotp-bg" aria-hidden="true"><i></i><i></i><i></i><span></span></div>';
        echo '<main class="bvotp-main"><article class="bvotp-card">';
        echo '<div class="bvotp-logo"><span>B</span></div><div class="bvotp-brand">BlueVPN</div>';
        echo '<section class="bvotp-step" data-step="phone"><h1>ورود به حساب کاربری</h1><p>شماره موبایل خود را وارد کنید تا کد ورود ۶ رقمی برایتان ارسال شود.</p><form class="bvotp-phone-form"><label>شماره موبایل</label><div class="bvotp-phone-wrap"><span>+98</span><input type="tel" inputmode="numeric" autocomplete="tel-national" placeholder="9121234567" maxlength="10" aria-label="شماره موبایل"></div><div class="bvotp-error" role="alert"></div><button type="submit" class="bvotp-primary"><span>ارسال کد ورود</span><b class="bvotp-spinner"></b></button></form></section>';
        echo '<section class="bvotp-step is-hidden" data-step="code"><button type="button" class="bvotp-back" data-action="back">→ تغییر شماره</button><h1>کد تأیید را وارد کنید</h1><p>کد ۶ رقمی ارسال‌شده به <strong class="bvotp-phone-label"></strong> را وارد کنید.</p><form class="bvotp-code-form"><div class="bvotp-inputs" dir="ltr" aria-label="کد تأیید شش رقمی">';
        for ($i=0;$i<6;$i++) echo '<input class="bvotp-digit" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="1" '.($i===0?'autocomplete="one-time-code" ':'').'aria-label="رقم '.($i+1).'">';
        echo '</div><div class="bvotp-error" role="alert"></div><button type="submit" class="bvotp-primary"><span>تأیید و ورود</span><b class="bvotp-spinner"></b></button></form><div class="bvotp-resend"><span>کد را دریافت نکردید؟</span> <button type="button" data-action="resend" disabled>ارسال مجدد</button><small>امکان ارسال مجدد تا <b class="bvotp-timer">01:00</b></small></div></section>';
        echo '<section class="bvotp-step is-hidden bvotp-success" data-step="success"><div class="bvotp-check">✓</div><h1>ورود موفق!</h1><p>احراز هویت شش‌رقمی BlueVPN با موفقیت انجام شد.</p><button type="button" class="bvotp-primary" data-action="done">ادامه</button></section>';
        if (empty($cfg['smsReady'])) echo '<div class="bvotp-admin-note">سامانه SMS هنوز در پنل BlueVPN فعال نشده است.</div>';
        echo '</article></main></div>';
        echo '<script>window.BlueVPNOTPInstances=window.BlueVPNOTPInstances||{};window.BlueVPNOTPInstances['.wp_json_encode($id).']='.wp_json_encode($cfg,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).';</script>';
        if ($standalone) echo '<script src="'.esc_url(BLUEVPN_MANAGER_URL.'assets/otp-login.js?ver='.rawurlencode(BLUEVPN_MANAGER_VERSION)).'"></script>';
        else {
            wp_enqueue_style('bluevpn-otp-login', BLUEVPN_MANAGER_URL.'assets/otp-login.css', [], BLUEVPN_MANAGER_VERSION);
            wp_enqueue_script('bluevpn-otp-login', BLUEVPN_MANAGER_URL.'assets/otp-login.js', [], BLUEVPN_MANAGER_VERSION, true);
        }
    }
}
