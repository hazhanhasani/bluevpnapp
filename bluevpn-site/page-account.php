<?php if (class_exists('BlueVPN_Elementor_Integration') && BlueVPN_Elementor_Integration::page_ready()) { get_header(); while (have_posts()) { the_post(); the_content(); } get_footer(); return; } ?>
<?php get_header(); ?>
<section class="bv-account-page">
  <div class="bv-account-orb a1"></div><div class="bv-account-orb a2"></div>
  <div class="bv-shell bv-account-layout" data-bv-account-layout>
    <aside class="bv-account-intro" data-bv-reveal>
      <span class="bv-kicker bv-kicker-light">حساب BlueVPN</span>
      <h1>اشتراک و حساب، ساده و یکپارچه.</h1>
      <p>با موبایل یا ایمیل وارد شو و وضعیت اشتراک و پلن‌ها را از همین صفحه مدیریت کن.</p>
      <div class="bv-account-benefits">
        <div><i>✓</i><span><b>ورود سریع</b><small>با شماره موبایل یا ایمیل</small></span></div>
        <div><i>✓</i><span><b>اشتراک یکپارچه</b><small>همان حسابی که در اپ استفاده می‌کنی</small></span></div>
      </div>
    </aside>
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

      <div class="bv-hidden bv-account-dashboard" data-bv-dashboard>
        <div class="bv-dashboard-head">
          <div><span class="bv-kicker">حساب کاربری</span><h2 data-account-identity>BlueVPN</h2></div>
          <div class="bv-dashboard-actions"><button class="bv-link-btn" data-refresh-account>بروزرسانی</button><button class="bv-btn bv-btn-ghost bv-btn-small" data-logout>خروج</button></div>
        </div>

        <section class="bv-current-subscription" data-account-current>
          <div class="bv-current-subscription-head"><span class="bv-status-dot"></span><div><small>اشتراک فعلی</small><h3 data-current-plan-title>بدون اشتراک</h3></div><span class="bv-current-badge" data-current-badge>—</span></div>
          <div class="bv-current-subscription-grid">
            <div><small>وضعیت</small><strong data-account-status>—</strong></div>
            <div><small>اعتبار</small><strong data-account-expire>—</strong></div>
            <div><small>دستگاه مجاز</small><strong data-account-devices>—</strong></div>
          </div>
          <div class="bv-current-identity" data-account-detail></div>
        </section>

        <section class="bv-dashboard-panel bv-dashboard-plans">
          <div class="bv-card-title"><div><small>انتخاب اشتراک</small><h3>پلن‌ها</h3></div></div>
          <div class="bv-plans" data-account-plans></div>
        </section>
      </div>
    </div>
  </div>
</section>
<?php get_footer(); ?>
