<?php get_header(); ?>
<section class="bv-page-hero bv-account-hero"><div class="bv-shell"><div class="bv-kicker">MY BLUEVPN</div><h1>حساب کاربری</h1><p>ورود، اشتراک و پرداخت را از یکجا مدیریت کنید.</p></div></section>
<section class="bv-section bv-account-section"><div class="bv-shell"><div id="bv-account-app" data-bv-account>
<div class="bv-auth-shell" data-bv-auth>
 <div class="bv-auth-tabs"><button class="is-active" data-auth-tab="otp">ورود با موبایل</button><button data-auth-tab="email">ایمیل و رمز</button></div>
 <div class="bv-auth-panel is-active" data-auth-panel="otp">
  <div data-otp-step="phone"><label>شماره موبایل</label><input type="tel" inputmode="tel" placeholder="0912xxxxxxx" data-otp-phone><button class="bv-btn bv-full" data-otp-request>ارسال کد</button></div>
  <div class="bv-hidden" data-otp-step="code"><label>کد تأیید</label><input type="text" inputmode="numeric" maxlength="6" placeholder="••••••" data-otp-code><button class="bv-btn bv-full" data-otp-verify>تأیید و ورود</button><button class="bv-link-btn" data-otp-back>تغییر شماره</button></div>
 </div>
 <div class="bv-auth-panel" data-auth-panel="email">
  <label>ایمیل</label><input type="email" placeholder="name@example.com" data-email>
  <label>رمز عبور</label><input type="password" placeholder="••••••••" data-password>
  <div class="bv-auth-actions"><button class="bv-btn" data-email-login>ورود</button><button class="bv-btn bv-btn-ghost" data-email-register>ثبت‌نام</button></div>
 </div>
 <div class="bv-form-message" data-auth-message></div>
</div>
<div class="bv-hidden" data-bv-dashboard>
 <div class="bv-dashboard-head"><div><div class="bv-kicker">ACCOUNT</div><h2 data-account-identity>BlueVPN</h2></div><button class="bv-btn bv-btn-ghost bv-btn-small" data-logout>خروج</button></div>
 <div class="bv-account-stats"><article><small>وضعیت</small><strong data-account-status>—</strong></article><article><small>پلن</small><strong data-account-plan>—</strong></article><article><small>اعتبار</small><strong data-account-expire>—</strong></article></div>
 <div class="bv-dashboard-grid"><section><div class="bv-card-title"><h3>پلن‌ها</h3><button class="bv-link-btn" data-refresh-account>بروزرسانی</button></div><div class="bv-plans" data-account-plans></div></section><aside class="bv-account-card"><h3>اشتراک فعلی</h3><div data-account-detail></div></aside></div>
</div>
</div></div></section>
<?php get_footer(); ?>
