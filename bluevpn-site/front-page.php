<?php get_header(); $cfg = bluevpn_site_mobile_config(); ?>
<section class="bv-home-hero">
  <div class="bv-hero-glow bv-hero-glow-a" aria-hidden="true"></div>
  <div class="bv-hero-glow bv-hero-glow-b" aria-hidden="true"></div>
  <div class="bv-shell bv-home-hero-grid">
    <div class="bv-home-copy">
      <div class="bv-eyebrow"><span class="bv-status-dot"></span> BLUEVPN • اتصال ساده و مدیریت‌شده</div>
      <h1>یک لمس تا<br><span>اتصال بهتر.</span></h1>
      <p class="bv-hero-lead">لوکیشن را انتخاب کن؛ BlueVPN مسیرهای داخلی را پشت‌صحنه بررسی می‌کند و تجربه‌ای ساده، سریع و بدون شلوغی فنی به تو می‌دهد.</p>
      <div class="bv-actions bv-actions-hero">
        <a class="bv-btn bv-btn-primary" href="<?php echo esc_url(home_url('/download/')); ?>">
          <span>دانلود BlueVPN</span><span class="bv-btn-arrow">↙</span>
        </a>
        <a class="bv-btn bv-btn-soft" href="<?php echo esc_url(home_url('/plans/')); ?>">مشاهده پلن‌ها</a>
      </div>
      <div class="bv-hero-points" aria-label="مزایای BlueVPN">
        <span><i>✓</i> انتخاب خودکار مسیر</span>
        <span><i>✓</i> لوکیشن‌های ساده و شفاف</span>
        <span><i>✓</i> حساب یکپارچه</span>
      </div>
    </div>

    <div class="bv-connect-stage" aria-label="نمایش رابط اتصال BlueVPN">
      <div class="bv-stage-orbit bv-stage-orbit-one" aria-hidden="true"></div>
      <div class="bv-stage-orbit bv-stage-orbit-two" aria-hidden="true"></div>
      <div class="bv-connect-panel">
        <div class="bv-connect-panel-top">
          <div>
            <small>BlueVPN</small>
            <strong>آماده اتصال</strong>
          </div>
          <span class="bv-version-pill"><?php echo esc_html((string)($cfg['latest_version'] ?? 'Android')); ?></span>
        </div>
        <div class="bv-power-wrap">
          <div class="bv-power-ring"><span class="bv-power-symbol">⌁</span></div>
          <div class="bv-power-text"><strong>اتصال</strong><small>بهترین مسیر به‌صورت خودکار</small></div>
        </div>
        <div class="bv-location-card">
          <div class="bv-location-icon">◎</div>
          <div><small>لوکیشن</small><strong>انتخاب خودکار</strong></div>
          <span class="bv-live-pill"><i></i> آماده</span>
        </div>
      </div>
      <div class="bv-float-card bv-float-speed"><span>⚡</span><div><small>مسیرها</small><strong>پشت‌صحنه</strong></div></div>
      <div class="bv-float-card bv-float-account"><span>◉</span><div><small>حساب</small><strong>یکپارچه</strong></div></div>
    </div>
  </div>
</section>

<section class="bv-logo-strip" aria-label="ویژگی‌های اصلی">
  <div class="bv-shell bv-logo-strip-inner">
    <span>اتصال خودکار</span><i></i><span>مدیریت لوکیشن</span><i></i><span>پرداخت BluePay</span><i></i><span>بروزرسانی خودکار</span>
  </div>
</section>

<section class="bv-section bv-feature-section" id="features">
  <div class="bv-shell">
    <div class="bv-section-head bv-section-head-centered">
      <div class="bv-kicker">چرا BlueVPN؟</div>
      <h2>همه‌چیز برای استفاده روزمره ساده شده</h2>
      <p>جزئیات فنی در جای خودش باقی می‌ماند؛ چیزی که کاربر می‌بیند فقط تصمیم‌های ساده و قابل فهم است.</p>
    </div>
    <div class="bv-feature-grid">
      <article class="bv-feature-card bv-feature-card-large">
        <div class="bv-feature-icon">◎</div>
        <div class="bv-feature-content"><span>01</span><h3>لوکیشن، نه مسیرهای پیچیده</h3><p>کاربر کشور یا اتصال خودکار را انتخاب می‌کند و مسیرهای داخلی همان لوکیشن پشت‌صحنه مدیریت می‌شوند.</p></div>
        <div class="bv-mini-locations" aria-hidden="true"><b>🇬🇧</b><b>🇩🇪</b><b>🇷🇴</b><b>🇺🇸</b><em>+</em></div>
      </article>
      <article class="bv-feature-card">
        <div class="bv-feature-icon">↻</div>
        <div class="bv-feature-content"><span>02</span><h3>بروزرسانی بدون دردسر</h3><p>نسخه اپ، کنترل‌پنل و پوسته سایت با چرخه بروزرسانی کنترل‌شده مدیریت می‌شوند.</p></div>
      </article>
      <article class="bv-feature-card">
        <div class="bv-feature-icon">◇</div>
        <div class="bv-feature-content"><span>03</span><h3>حساب و پرداخت یکجا</h3><p>ورود با موبایل یا ایمیل، مشاهده اشتراک و انتقال به BluePay از یک مسیر یکپارچه انجام می‌شود.</p></div>
      </article>
    </div>
  </div>
</section>

<section class="bv-section bv-how-section">
  <div class="bv-shell bv-how-shell">
    <div class="bv-how-copy">
      <div class="bv-kicker">ساده مثل باید</div>
      <h2>سه قدم تا شروع</h2>
      <p>بدون تنظیمات پیچیده، بدون نمایش جزئیات اضافه و بدون اینکه کاربر درگیر ساختار داخلی اتصال شود.</p>
      <a class="bv-text-link" href="<?php echo esc_url(home_url('/download/')); ?>">همین حالا دانلود کن <span>←</span></a>
    </div>
    <div class="bv-step-list">
      <article><span>01</span><div><h3>دانلود و ورود</h3><p>BlueVPN را نصب کن و با موبایل یا ایمیل وارد حساب شو.</p></div></article>
      <article><span>02</span><div><h3>لوکیشن را انتخاب کن</h3><p>یک کشور یا حالت خودکار را انتخاب کن؛ مسیرهای فنی نمایش داده نمی‌شوند.</p></div></article>
      <article><span>03</span><div><h3>اتصال را بزن</h3><p>اپ بهترین مسیر قابل استفاده را در همان تجربه ساده اجرا می‌کند.</p></div></article>
    </div>
  </div>
</section>

<section class="bv-section bv-network-section">
  <div class="bv-shell">
    <div class="bv-network-card">
      <div class="bv-network-copy">
        <div class="bv-kicker">شبکه BlueVPN</div>
        <h2>لوکیشن‌ها برای کاربر، مسیرها برای موتور.</h2>
        <p>فهرست لوکیشن‌های فعال از کنترل‌پنل مدیریت می‌شود و ساختار داخلی مسیرها در رابط کاربری پنهان می‌ماند.</p>
        <a class="bv-btn bv-btn-soft" href="<?php echo esc_url(home_url('/account/')); ?>">ورود به حساب</a>
      </div>
      <div class="bv-network-visual" aria-hidden="true">
        <div class="bv-world-ring ring-a"></div><div class="bv-world-ring ring-b"></div><div class="bv-world-ring ring-c"></div>
        <span class="bv-node n1"></span><span class="bv-node n2"></span><span class="bv-node n3"></span><span class="bv-node n4"></span><span class="bv-node n5"></span>
        <strong>BlueVPN</strong>
      </div>
    </div>
  </div>
</section>

<section class="bv-section bv-plan-teaser-section">
  <div class="bv-shell">
    <div class="bv-plan-teaser">
      <div>
        <div class="bv-kicker">Premium</div>
        <h2>پلنی که با نیازت هماهنگ باشد</h2>
        <p>قیمت‌ها، مدت، حجم و محدودیت دستگاه از BlueVPN Manager دریافت می‌شوند؛ برای دیدن گزینه‌های فعال وارد حساب شو.</p>
      </div>
      <div class="bv-plan-teaser-actions">
        <a class="bv-btn bv-btn-primary" href="<?php echo esc_url(home_url('/plans/')); ?>">مشاهده پلن‌های فعال</a>
        <small>پرداخت از مسیر BluePay</small>
      </div>
    </div>
  </div>
</section>

<section class="bv-section bv-final-cta-section">
  <div class="bv-shell">
    <div class="bv-final-cta">
      <div class="bv-final-cta-mark">B</div>
      <div><div class="bv-kicker">BlueVPN for Android</div><h2>نصب کن. لوکیشن را انتخاب کن. وصل شو.</h2><p>آخرین نسخه منتشرشده را از صفحه دانلود دریافت کن.</p></div>
      <a class="bv-btn bv-btn-light" href="<?php echo esc_url(home_url('/download/')); ?>">دانلود نسخه <?php echo esc_html((string)($cfg['latest_version'] ?? 'جدید')); ?></a>
    </div>
  </div>
</section>
<?php get_footer(); ?>
