<?php get_header(); $cfg = bluevpn_site_mobile_config(); ?>
<section class="bv-hero">
 <div class="bv-shell bv-hero-grid">
  <div class="bv-hero-copy">
   <div class="bv-kicker">BLUEVPN • SECURE CONNECTION</div>
   <h1>اتصال مطمئن،<br><span>بدون پیچیدگی.</span></h1>
   <p>BlueVPN بهترین مسیر را پشت‌صحنه انتخاب می‌کند؛ شما فقط لوکیشن یا اتصال خودکار را انتخاب می‌کنید.</p>
   <div class="bv-actions">
    <a class="bv-btn" href="<?php echo esc_url(home_url('/download/')); ?>">دانلود BlueVPN</a>
    <a class="bv-btn bv-btn-ghost" href="<?php echo esc_url(home_url('/plans/')); ?>">مشاهده پلن‌ها</a>
   </div>
   <div class="bv-trust"><span>✓ اتصال Premium</span><span>✓ لوکیشن‌های مخفی</span><span>✓ مدیریت اشتراک</span></div>
  </div>
  <div class="bv-orbit-card" aria-hidden="true">
   <div class="bv-orbit"><i></i><i></i><i></i><strong>BlueVPN</strong></div>
   <div class="bv-live"><span></span> آماده اتصال</div>
  </div>
 </div>
</section>
<section class="bv-section">
 <div class="bv-shell">
  <div class="bv-section-head"><div><div class="bv-kicker">WHY BLUEVPN</div><h2>همه‌چیز ساده‌تر شده</h2></div><p>جزئیات فنی مسیرها پنهان می‌مانند و انتخاب مسیر مناسب در پس‌زمینه انجام می‌شود.</p></div>
  <div class="bv-features">
   <article><b>01</b><h3>انتخاب خودکار مسیر</h3><p>برای هر لوکیشن، مسیر مناسب بدون نمایش جزئیات فنی انتخاب می‌شود.</p></article>
   <article><b>02</b><h3>حساب یکپارچه</h3><p>ورود با موبایل یا ایمیل، مشاهده اعتبار و مدیریت پلن از سایت و اپ.</p></article>
   <article><b>03</b><h3>پرداخت و فعال‌سازی</h3><p>خرید پلن از BluePay و فعال‌شدن حساب پس از تأیید پرداخت.</p></article>
  </div>
 </div>
</section>
<section class="bv-section bv-dark-card-section">
 <div class="bv-shell bv-split-card">
  <div><div class="bv-kicker">PREMIUM</div><h2>برای استفاده روزمره ساخته شده</h2><p>پلن‌های BlueVPN بر اساس مدت، حجم و محدودیت دستگاه از کنترل‌پنل مدیریت می‌شوند.</p><a class="bv-text-link" href="<?php echo esc_url(home_url('/plans/')); ?>">ورود و مشاهده پلن‌های فعال ←</a></div>
  <div class="bv-stat-stack"><div><small>نسخه فعلی</small><strong><?php echo esc_html((string)($cfg['latest_version'] ?? 'BlueVPN')); ?></strong></div><div><small>بروزرسانی</small><strong><?php echo !empty($cfg['auto_update']) ? 'خودکار' : 'کنترل‌شده'; ?></strong></div></div>
 </div>
</section>
<section class="bv-section">
 <div class="bv-shell bv-cta"><div><div class="bv-kicker">START NOW</div><h2>BlueVPN را نصب کن و شروع کن</h2><p>حساب شما بین وب و اپ یکپارچه است.</p></div><a class="bv-btn" href="<?php echo esc_url(home_url('/download/')); ?>">دانلود اپلیکیشن</a></div>
</section>
<?php get_footer(); ?>
