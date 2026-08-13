<?php get_header(); ?>
<section class="bv-account-page">
  <div class="bv-account-orb a1"></div><div class="bv-account-orb a2"></div>
  <div class="bv-shell bv-account-layout">
    <aside class="bv-account-intro" data-bv-reveal><span class="bv-kicker bv-kicker-light">MY BLUEVPN</span><h1>حسابت، اشتراکت، همه در یکجا.</h1><p>با موبایل یا ایمیل وارد شو؛ وضعیت اشتراک، پلن‌ها و خرید از همین صفحه مدیریت می‌شود.</p><div class="bv-account-benefits"><div><i>✓</i><span><b>OTP موبایل</b><small>ورود سریع؛ نشست وب از دستگاه‌های VPN جداست</small></span></div><div><i>✓</i><span><b>ایمیل و رمز</b><small>ورود یا ثبت‌نام مستقیم</small></span></div><div><i>✓</i><span><b>خرید یکپارچه</b><small>مدیریت پلن و پرداخت از همان حساب</small></span></div></div></aside>
    <div id="bv-account-app" class="bv-account-app" data-bv-account data-bv-reveal>
      <div class="bv-auth-shell" data-bv-auth>
        <div class="bv-auth-header"><span class="bv-app-logo">B</span><div><h2>ورود به BlueVPN</h2><p>روش ورود را انتخاب کن.</p></div></div>
        <div class="bv-auth-tabs"><button class="is-active" data-auth-tab="otp">شماره موبایل</button><button data-auth-tab="email">ایمیل و رمز</button></div>
        <div class="bv-auth-panel is-active" data-auth-panel="otp">
          <div data-otp-step="phone"><label>شماره موبایل</label><div class="bv-field"><span>+98</span><input type="tel" inputmode="tel" placeholder="912 000 0000" data-otp-phone></div><button class="bv-btn bv-btn-primary bv-full" data-otp-request>ارسال کد تأیید</button></div>
          <div class="bv-hidden" data-otp-step="code"><label>کد تأیید</label><input class="bv-code-input" type="text" inputmode="numeric" maxlength="6" placeholder="••••••" data-otp-code><button class="bv-btn bv-btn-primary bv-full" data-otp-verify>تأیید و ورود</button><button class="bv-link-btn" data-otp-back>تغییر شماره</button></div>
        </div>
        <div class="bv-auth-panel" data-auth-panel="email"><label>ایمیل</label><input type="email" placeholder="name@example.com" data-email><label>رمز عبور</label><input type="password" placeholder="••••••••" data-password><div class="bv-auth-actions"><button class="bv-btn bv-btn-primary" data-email-login>ورود</button><button class="bv-btn bv-btn-ghost" data-email-register>ثبت‌نام</button></div></div>
        <div class="bv-form-message" data-auth-message></div>
      </div>
      <div class="bv-hidden" data-bv-dashboard><div class="bv-dashboard-head"><div><span class="bv-kicker">ACCOUNT</span><h2 data-account-identity>BlueVPN</h2></div><button class="bv-btn bv-btn-ghost bv-btn-small" data-logout>خروج</button></div><div class="bv-account-stats"><article><small>وضعیت</small><strong data-account-status>—</strong></article><article><small>پلن</small><strong data-account-plan>—</strong></article><article><small>اعتبار</small><strong data-account-expire>—</strong></article></div><div class="bv-dashboard-grid"><section class="bv-dashboard-panel"><div class="bv-card-title"><h3>پلن‌ها</h3><button class="bv-link-btn" data-refresh-account>بروزرسانی</button></div><div class="bv-plans" data-account-plans></div></section><aside class="bv-account-card"><h3>اشتراک فعلی</h3><div data-account-detail></div></aside></div></div>
    </div>
  </div>
</section>
<?php get_footer(); ?>
