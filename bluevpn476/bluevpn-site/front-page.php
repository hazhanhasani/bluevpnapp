<?php if (class_exists('BlueVPN_Elementor_Integration') && BlueVPN_Elementor_Integration::render_page()) { return; } ?>
<?php get_header(); $cfg = bluevpn_site_mobile_config(); $latest=(string)($cfg['latest_version'] ?? 'Android'); ?>
<section class="bv-hero">
  <div class="bv-hero-gridfx" aria-hidden="true"></div>
  <div class="bv-hero-aurora bv-a1" aria-hidden="true"></div>
  <div class="bv-hero-aurora bv-a2" aria-hidden="true"></div>
  <div class="bv-shell bv-hero-layout">
    <div class="bv-hero-copy" data-bv-reveal>
      <div class="bv-eyebrow"><span class="bv-status-dot"></span> اتصال مدیریت‌شده برای استفاده روزمره</div>
      <h1>اینترنتت را<br><span>ساده‌تر وصل کن.</span></h1>
      <p>BlueVPN پیچیدگی مسیرها و کانفیگ‌ها را پشت یک تجربه ساده پنهان می‌کند؛ تو فقط لوکیشن را انتخاب می‌کنی و اپ ادامه مسیر را مدیریت می‌کند.</p>
      <div class="bv-hero-actions">
        <a class="bv-btn bv-btn-primary bv-btn-xl" href="<?php echo esc_url(home_url('/download/')); ?>">دانلود BlueVPN <span>↓</span></a>
        <a class="bv-btn bv-btn-ghost bv-btn-xl" href="<?php echo esc_url(home_url('/plans/')); ?>">مشاهده پلن‌ها</a>
      </div>
      <div class="bv-hero-proof">
        <div><strong>یک لمس</strong><span>برای شروع اتصال</span></div>
        <div><strong>لوکیشن‌محور</strong><span>مسیرها پشت‌صحنه</span></div>
        <div><strong>یکپارچه</strong><span>حساب، پرداخت و دانلود</span></div>
      </div>
    </div>

    <div class="bv-product-stage" data-bv-reveal aria-label="نمایش تجربه اتصال BlueVPN">
      <div class="bv-product-glow"></div>
      <div class="bv-app-shell">
        <div class="bv-app-topbar">
          <div class="bv-app-brand"><span class="bv-app-logo">B</span><div><b>BlueVPN</b><small>Android</small></div></div>
          <span class="bv-app-version">v<?php echo esc_html($latest); ?></span>
        </div>
        <div class="bv-app-status"><span><i></i> آماده اتصال</span><small>شبکه بررسی شد</small></div>
        <div class="bv-connect-orb-wrap">
          <div class="bv-connect-orb-pulse p1"></div><div class="bv-connect-orb-pulse p2"></div>
          <div class="bv-connect-orb"><span class="bv-power-glyph">⌁</span></div>
        </div>
        <div class="bv-app-connect-label"><b>اتصال</b><span>بهترین مسیر در پس‌زمینه انتخاب می‌شود</span></div>
        <div class="bv-location-row">
          <div class="bv-flag">🌐</div><div class="bv-location-text"><small>لوکیشن</small><b>انتخاب خودکار</b></div>
          <div class="bv-chevron">‹</div>
        </div>
        <div class="bv-app-mini-grid"><div><span>↙</span><small>دریافت</small><b>آماده</b></div><div><span>↗</span><small>ارسال</small><b>آماده</b></div><div><span>◉</span><small>وضعیت</small><b>پایدار</b></div></div>
      </div>
      <div class="bv-stage-card bv-stage-card-1"><span>⚡</span><div><small>اتصال</small><b>یک‌لمسی</b></div></div>
      <div class="bv-stage-card bv-stage-card-2"><span>◎</span><div><small>مسیرها</small><b>مخفی و مدیریت‌شده</b></div></div>
      <div class="bv-stage-card bv-stage-card-3"><span>✓</span><div><small>حساب</small><b>همگام با سایت</b></div></div>
    </div>
  </div>
</section>

<section class="bv-tech-strip">
  <div class="bv-shell bv-tech-strip-inner">
    <span>BlueVPN</span><i></i><span>اتصال سریع</span><i></i><span>حریم خصوصی</span><i></i><span>لوکیشن‌های جهانی</span><i></i><span>یک لمس تا اتصال</span>
  </div>
</section>

<section class="bv-section bv-intro" id="features">
  <div class="bv-shell">
    <div class="bv-section-heading" data-bv-reveal>
      <div><span class="bv-kicker">تجربه BlueVPN</span><h2>ساده در ظاهر.<br>قدرتمند در پشت‌صحنه.</h2></div>
      <p>طراحی BlueVPN از نمایش تنظیمات و اصطلاحات فنی دوری می‌کند. چیزی که می‌بینی فقط انتخاب‌های لازم است؛ مدیریت مسیر، حساب و بروزرسانی پشت‌صحنه انجام می‌شود.</p>
    </div>
    <div class="bv-bento">
      <article class="bv-bento-card bv-bento-main" data-bv-reveal>
        <div class="bv-card-meta"><span>01</span><b>لوکیشن‌محور</b></div>
        <h3>کشور را انتخاب کن، نه کانفیگ را.</h3><p>Routeهای داخلی هر لوکیشن در رابط کاربری پنهان هستند و BlueVPN آن‌ها را در زمان اتصال مدیریت می‌کند.</p>
        <div class="bv-locations-stack" aria-hidden="true"><div>🇩🇪 <span>آلمان</span></div><div>🇬🇧 <span>انگلیس</span></div><div>🇷🇴 <span>رومانی</span></div><div>🌐 <span>خودکار</span></div></div>
      </article>
      <article class="bv-bento-card bv-bento-dark" data-bv-reveal>
        <div class="bv-card-icon">↻</div><div class="bv-card-meta"><span>02</span><b>همگام‌سازی</b></div><h3>حساب و اشتراک در یک مسیر.</h3><p>ورود، وضعیت اشتراک و بروزرسانی‌ها بدون مراحل اضافه در یک تجربه یکپارچه قرار دارند.</p>
        <div class="bv-sync-visual"><i></i><span></span><i></i></div>
      </article>
      <article class="bv-bento-card bv-bento-blue" data-bv-reveal>
        <div class="bv-card-icon">◇</div><div class="bv-card-meta"><span>03</span><b>پرداخت</b></div><h3>خرید و پرداخت بدون خروج از جریان کار.</h3><p>از انتخاب پلن تا پرداخت، تجربه کاربر یکپارچه باقی می‌ماند.</p>
        <a class="bv-inline-link" href="<?php echo esc_url(home_url('/plans/')); ?>">دیدن پلن‌ها <span>←</span></a>
      </article>
      <article class="bv-bento-card bv-bento-soft" data-bv-reveal>
        <div class="bv-card-icon">↓</div><div class="bv-card-meta"><span>04</span><b>بروزرسانی</b></div><h3>نسخه جدید همیشه از مسیر کنترل‌شده.</h3><p>نسخه‌های جدید بدون شلوغی و با یک مسیر ساده در اختیار کاربر قرار می‌گیرند.</p>
        <div class="bv-update-pill"><i></i><span>Latest</span><b><?php echo esc_html($latest); ?></b></div>
      </article>
    </div>
  </div>
</section>

<section class="bv-section bv-network" id="network">
  <div class="bv-shell bv-network-layout">
    <div class="bv-network-copy" data-bv-reveal>
      <span class="bv-kicker bv-kicker-light">شبکه BlueVPN</span>
      <h2>لوکیشن‌ها برای تو،<br>مسیرها برای موتور.</h2>
      <p>فقط کشور موردنظرت را انتخاب کن؛ BlueVPN بهترین مسیر اتصال را در پس‌زمینه مدیریت می‌کند.</p>
      <ul class="bv-check-list"><li><i>✓</i> انتخاب خودکار یا دستی لوکیشن</li><li><i>✓</i> رابط ساده بدون جزئیات فنی</li><li><i>✓</i> جابه‌جایی هوشمند برای اتصال پایدارتر</li></ul>
      <a class="bv-btn bv-btn-white-outline" href="<?php echo esc_url(home_url('/download/')); ?>">دریافت اپلیکیشن</a>
    </div>
    <div class="bv-network-visual-pro" data-bv-reveal aria-hidden="true">
      <svg viewBox="0 0 760 500" role="img" aria-label="شبکه لوکیشن‌های BlueVPN">
        <defs><linearGradient id="lineg" x1="0" x2="1"><stop offset="0" stop-color="#5f8dff" stop-opacity=".15"/><stop offset=".5" stop-color="#5f8dff" stop-opacity=".85"/><stop offset="1" stop-color="#57e8ca" stop-opacity=".25"/></linearGradient></defs>
        <path class="mapline" d="M104 294 C210 145 304 178 379 256 S565 358 656 217"/><path class="mapline dim" d="M129 169 C248 315 375 336 626 139"/><path class="mapline dim" d="M130 370 C314 279 438 130 643 340"/>
        <g class="mapnode n-de"><circle cx="205" cy="184" r="9"/><circle cx="205" cy="184" r="23" class="halo"/></g>
        <g class="mapnode n-ro"><circle cx="370" cy="262" r="9"/><circle cx="370" cy="262" r="23" class="halo"/></g>
        <g class="mapnode n-gb"><circle cx="127" cy="287" r="9"/><circle cx="127" cy="287" r="23" class="halo"/></g>
        <g class="mapnode n-us"><circle cx="618" cy="225" r="9"/><circle cx="618" cy="225" r="23" class="halo"/></g>
        <g class="mapnode n-auto"><circle cx="492" cy="156" r="11"/><circle cx="492" cy="156" r="31" class="halo strong"/></g>
      </svg>
      <div class="bv-map-label l1">🇩🇪 <b>آلمان</b></div><div class="bv-map-label l2">🇷🇴 <b>رومانی</b></div><div class="bv-map-label l3">🇬🇧 <b>انگلیس</b></div><div class="bv-map-label l4">🇺🇸 <b>آمریکا</b></div><div class="bv-map-center"><span>◎</span><b>BlueVPN</b><small>Auto select</small></div>
    </div>
  </div>
</section>

<section class="bv-section bv-how">
  <div class="bv-shell">
    <div class="bv-how-head" data-bv-reveal><span class="bv-kicker">شروع سریع</span><h2>سه قدم. همین.</h2><p>کاربر نباید برای اتصال، آموزش فنی ببیند؛ تجربه باید کوتاه، روشن و قابل فهم باشد.</p></div>
    <div class="bv-how-grid">
      <article data-bv-reveal><span class="bv-step-no">01</span><div class="bv-step-icon">↓</div><h3>دانلود</h3><p>آخرین نسخه BlueVPN را نصب کن و وارد حساب شو.</p></article>
      <article data-bv-reveal><span class="bv-step-no">02</span><div class="bv-step-icon">◎</div><h3>انتخاب</h3><p>حالت خودکار یا یک لوکیشن مشخص را انتخاب کن.</p></article>
      <article data-bv-reveal><span class="bv-step-no">03</span><div class="bv-step-icon">⌁</div><h3>اتصال</h3><p>دکمه اتصال را بزن؛ جزئیات فنی پشت‌صحنه باقی می‌مانند.</p></article>
    </div>
  </div>
</section>

<section class="bv-section bv-premium">
  <div class="bv-shell">
    <div class="bv-premium-card" data-bv-reveal>
      <div class="bv-premium-copy"><span class="bv-kicker bv-kicker-light">BLUEVPN PREMIUM</span><h2>وقتی انتخاب بیشتر می‌خواهی.</h2><p>پلن‌ها با مدت، حجم و تعداد دستگاه مشخص نمایش داده می‌شوند تا انتخاب ساده باشد.</p><div class="bv-premium-actions"><a class="bv-btn bv-btn-light" href="<?php echo esc_url(home_url('/plans/')); ?>">مشاهده پلن‌ها</a><a class="bv-inline-link light" href="<?php echo esc_url(home_url('/account/')); ?>">ورود به حساب ←</a></div></div>
      <div class="bv-premium-visual"><div class="bv-pcard back"></div><div class="bv-pcard front"><small>BlueVPN</small><b>Premium</b><span>Global locations</span><i>•••• 2026</i></div></div>
    </div>
  </div>
</section>

<section class="bv-section bv-faq">
  <div class="bv-shell bv-faq-layout">
    <div class="bv-faq-copy" data-bv-reveal><span class="bv-kicker">سؤال‌های پرتکرار</span><h2>قبل از شروع، جواب‌های کوتاه.</h2><p>اگر پاسخ موردنظرت اینجا نبود، پشتیبانی BlueVPN در دسترس است.</p><a class="bv-inline-link" href="<?php echo esc_url(home_url('/support/')); ?>">رفتن به پشتیبانی ←</a></div>
    <div class="bv-accordion" data-bv-accordion>
      <article class="is-open" data-bv-reveal><button type="button"><span>آیا برای اتصال باید تنظیمات فنی انجام بدهم؟</span><i>+</i></button><div><p>خیر. فقط لوکیشن را انتخاب کن و BlueVPN جزئیات اتصال را خودش مدیریت می‌کند.</p></div></article>
      <article data-bv-reveal><button type="button"><span>برای خرید پلن باید از کجا شروع کنم؟</span><i>+</i></button><div><p>از صفحه پلن‌ها وارد حساب شو، پلن مناسب را انتخاب کن و خرید را ادامه بده.</p></div></article>
      <article data-bv-reveal><button type="button"><span>نسخه جدید اپ را از کجا بگیرم؟</span><i>+</i></button><div><p>صفحه دانلود همیشه آخرین نسخه آماده نصب BlueVPN را نمایش می‌دهد.</p></div></article>
    </div>
  </div>
</section>
<?php get_footer(); ?>
