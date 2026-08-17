<?php if (!defined('ABSPATH')) exit; ?>
<main class="bv-home-v2">
<section class="bv2-hero">
  <div class="bv2-grid" aria-hidden="true"></div><div class="bv2-glow g1" aria-hidden="true"></div><div class="bv2-glow g2" aria-hidden="true"></div>
  <div class="bv-shell bv2-hero-layout">
    <div class="bv2-hero-copy" data-bv-reveal>
      <span class="bv2-pill"><i></i> BlueVPN برای Android</span>
      <h1>یک لمس تا<br><em>اتصال بهتر.</em></h1>
      <p>BlueVPN مسیرها، اشتراک و اتصال را پشت یک رابط ساده جمع می‌کند. لوکیشن را انتخاب کن؛ بقیه کارها را اپ مدیریت می‌کند.</p>
      <div class="bv2-actions">
        <a class="bv-btn bv-btn-primary bv-btn-xl" href="<?php echo esc_url(home_url('/download/')); ?>">دانلود BlueVPN <span>↓</span></a>
        <a class="bv2-link" href="<?php echo esc_url(home_url('/plans/')); ?>">مشاهده پلن‌ها <span>←</span></a>
      </div>
      <div class="bv2-trust-row"><span>✓ اتصال یک‌لمسی</span><span>✓ لوکیشن‌محور</span><span>✓ حساب و اشتراک یکپارچه</span></div>
    </div>
    <div class="bv2-hero-product" data-bv-reveal>
      <div class="bv2-phone-halo"></div>
      <?php if ($shots['home'] !== ''): ?>
      <figure class="bv2-phone"><img src="<?php echo esc_url($shots['home']); ?>" alt="تصویر واقعی اپلیکیشن BlueVPN" loading="eager" decoding="async"></figure>
      <?php else: ?>
      <div class="bv2-app-placeholder"><img src="<?php echo esc_url(BLUEVPN_SITE_URL.'/assets/images/bluevpn-icon.png'); ?>" alt="BlueVPN"><strong>BlueVPN</strong><span>تصویر واقعی اپ را از سفارشی‌سازی انتخاب کن</span></div>
      <?php endif; ?>
      <div class="bv2-float-card fc1"><i class="ok"></i><span>وضعیت</span><b>آماده اتصال</b></div>
      <div class="bv2-float-card fc2"><span class="flag">🌐</span><div><small>انتخاب مسیر</small><b>خودکار یا دستی</b></div></div>
      <div class="bv2-float-card fc3"><span>نسخه</span><b><?php echo esc_html($latest); ?></b></div>
    </div>
  </div>
</section>

<section class="bv2-compat">
  <div class="bv-shell">
    <p>ساخته‌شده برای اینترنت روزمره</p>
    <div class="bv2-compat-row"><span>Android</span><i></i><span>Wi‑Fi</span><i></i><span>همراه اول</span><i></i><span>ایرانسل</span><i></i><span>رایتل</span><i></i><span>IPv4 / IPv6</span></div>
  </div>
</section>

<section class="bv2-section bv2-showcase" id="features">
  <div class="bv-shell">
    <div class="bv2-heading" data-bv-reveal><span class="bv2-kicker">خود اپلیکیشن، محور تجربه</span><h2>چیزی که می‌بینی،<br>همان چیزی است که استفاده می‌کنی.</h2><p>در سایت هم به‌جای موکاپ‌های خیالی، تصاویر واقعی BlueVPN نمایش داده می‌شوند.</p></div>
    <div class="bv2-showcase-grid">
      <article class="bv2-showcase-copy" data-bv-reveal>
        <span class="bv2-number">01</span><h3>اتصال ساده، بدون منوی شلوغ.</h3><p>صفحه اصلی برای یک کار ساخته شده: انتخاب و اتصال. جزئیات فنی و مسیرهای داخلی در پس‌زمینه می‌مانند.</p>
        <ul><li><i>✓</i> وضعیت اتصال واضح</li><li><i>✓</i> نمایش لوکیشن فعال</li><li><i>✓</i> اطلاعات اشتراک در دسترس</li></ul>
      </article>
      <div class="bv2-shot-stack" data-bv-reveal>
        <?php if ($shots['home'] !== ''): ?><figure class="primary"><img src="<?php echo esc_url($shots['home']); ?>" alt="صفحه اصلی واقعی BlueVPN" loading="lazy"></figure><?php endif; ?>
        <?php if ($shots['locations'] !== ''): ?><figure class="secondary"><img src="<?php echo esc_url($shots['locations']); ?>" alt="صفحه لوکیشن‌های واقعی BlueVPN" loading="lazy"></figure><?php endif; ?>
        <div class="bv2-shot-badge"><span>BlueVPN</span><b>Real app UI</b></div>
      </div>
    </div>
  </div>
</section>

<section class="bv2-section bv2-benefits">
  <div class="bv-shell">
    <div class="bv2-heading compact" data-bv-reveal><span class="bv2-kicker">چرا BlueVPN؟</span><h2>کمتر تنظیم کن.<br>بیشتر استفاده کن.</h2></div>
    <div class="bv2-bento">
      <article class="wide" data-bv-reveal><span class="icon">◎</span><small>مسیر هوشمند</small><h3>لوکیشن را انتخاب کن؛ Route مناسب پشت‌صحنه مدیریت می‌شود.</h3><div class="route-lines"><i></i><i></i><i></i><b></b></div></article>
      <article data-bv-reveal><span class="icon">⚡</span><small>شروع سریع</small><h3>رابط کوتاه و مستقیم برای رسیدن سریع‌تر به اتصال.</h3></article>
      <article data-bv-reveal><span class="icon">♢</span><small>یکپارچه</small><h3>حساب، پلن، پرداخت و دانلود در یک مسیر.</h3></article>
      <article class="dark" data-bv-reveal><span class="icon">↻</span><small>بروزرسانی</small><h3>نسخه جدید از مسیر کنترل‌شده در اختیار کاربر قرار می‌گیرد.</h3><div class="bv2-version"><i></i><span>Latest</span><b><?php echo esc_html($latest); ?></b></div></article>
      <article class="accent" data-bv-reveal><span class="icon">◈</span><small>حریم خصوصی</small><h3>پیچیدگی فنی از رابط کاربر جدا می‌ماند.</h3></article>
    </div>
  </div>
</section>

<section class="bv2-section bv2-network" id="network">
  <div class="bv-shell bv2-network-grid">
    <div data-bv-reveal><span class="bv2-kicker light">شبکه BlueVPN</span><h2>کشور را ببین،<br>مسیر را نه.</h2><p>نمایش ساده لوکیشن‌ها به‌جای نمایش انبوه کانفیگ‌ها؛ انتخاب برای کاربر، مدیریت مسیر برای موتور.</p><div class="bv2-location-chips"><span>🇩🇪 آلمان</span><span>🇬🇧 انگلیس</span><span>🇷🇴 رومانی</span><span>🇺🇸 آمریکا</span><span>🌐 خودکار</span></div><a class="bv-btn bv-btn-light" href="<?php echo esc_url(home_url('/download/')); ?>">دریافت اپلیکیشن</a></div>
    <div class="bv2-map" data-bv-reveal aria-hidden="true"><div class="planet"></div><span class="node n1"></span><span class="node n2"></span><span class="node n3"></span><span class="node n4"></span><span class="node n5"></span><i class="orbit o1"></i><i class="orbit o2"></i><i class="orbit o3"></i><div class="map-core"><b>B</b><span>BlueVPN</span></div></div>
  </div>
</section>

<section class="bv2-section bv2-account-showcase">
  <div class="bv-shell bv2-account-grid">
    <div class="bv2-account-visual" data-bv-reveal>
      <?php if ($shots['account'] !== ''): ?><figure><img src="<?php echo esc_url($shots['account']); ?>" alt="صفحه حساب واقعی BlueVPN" loading="lazy"></figure><?php elseif ($shots['home'] !== ''): ?><figure class="muted"><img src="<?php echo esc_url($shots['home']); ?>" alt="رابط واقعی BlueVPN" loading="lazy"></figure><?php endif; ?>
      <div class="account-card"><small>اشتراک</small><strong>همه‌چیز در یک حساب</strong><span>ورود • پلن • پرداخت • پشتیبانی</span></div>
    </div>
    <div class="bv2-showcase-copy" data-bv-reveal><span class="bv2-number">02</span><h3>حساب و اشتراک از اپ جدا نیست.</h3><p>کاربر برای دیدن وضعیت اشتراک، خرید یا مدیریت حساب مجبور نیست بین چند سیستم پراکنده جابه‌جا شود.</p><a class="bv2-link" href="<?php echo esc_url(home_url('/account/')); ?>">ورود به حساب <span>←</span></a></div>
  </div>
</section>

<section class="bv2-section bv2-how">
  <div class="bv-shell"><div class="bv2-heading compact" data-bv-reveal><span class="bv2-kicker">شروع استفاده</span><h2>سه قدم؛ تمام.</h2></div><div class="bv2-steps"><article data-bv-reveal><b>01</b><span>↓</span><h3>دانلود</h3><p>آخرین نسخه BlueVPN را نصب کن.</p></article><article data-bv-reveal><b>02</b><span>◎</span><h3>انتخاب</h3><p>حالت خودکار یا لوکیشن دلخواه را انتخاب کن.</p></article><article data-bv-reveal><b>03</b><span>⚡</span><h3>اتصال</h3><p>دکمه اتصال را بزن و ادامه را به BlueVPN بسپار.</p></article></div></div>
</section>

<section class="bv2-section bv2-premium">
  <div class="bv-shell"><div class="bv2-premium-card" data-bv-reveal><div><span class="bv2-kicker light">BLUEVPN PREMIUM</span><h2>برای وقتی که انتخاب بیشتری می‌خواهی.</h2><p>پلن‌ها با مشخصات شفاف نمایش داده می‌شوند؛ مدت، حجم و دستگاه را ببین و همان‌جا ادامه بده.</p><div class="bv2-actions"><a class="bv-btn bv-btn-light" href="<?php echo esc_url(home_url('/plans/')); ?>">مشاهده پلن‌ها</a><a class="bv2-link light" href="<?php echo esc_url(home_url('/account/')); ?>">حساب من ←</a></div></div><div class="premium-orbit"><i></i><i></i><div><span>B</span><b>Premium</b><small>BlueVPN</small></div></div></div></div>
</section>

<section class="bv2-section bv2-faq"><div class="bv-shell bv2-faq-grid"><div data-bv-reveal><span class="bv2-kicker">سؤال‌های کوتاه</span><h2>قبل از شروع،<br>جواب‌های روشن.</h2><p>اگر پاسخ اینجا نبود، پشتیبانی BlueVPN داخل حساب در دسترس است.</p><a class="bv2-link" href="<?php echo esc_url(home_url('/support/')); ?>">رفتن به پشتیبانی ←</a></div><div class="bv-accordion" data-bv-accordion><article class="is-open" data-bv-reveal><button type="button"><span>برای اتصال باید کانفیگ وارد کنم؟</span><i>+</i></button><div><p>خیر. رابط BlueVPN برای انتخاب لوکیشن و اتصال ساخته شده و جزئیات مسیر پشت‌صحنه مدیریت می‌شود.</p></div></article><article data-bv-reveal><button type="button"><span>نسخه جدید را از کجا دریافت کنم؟</span><i>+</i></button><div><p>صفحه دانلود، نسخه منتشرشده BlueVPN را نمایش می‌دهد.</p></div></article><article data-bv-reveal><button type="button"><span>پلن و حساب را از کجا مدیریت کنم؟</span><i>+</i></button><div><p>از صفحه حساب کاربری می‌توانی وارد شوی و وضعیت اشتراک و پلن‌ها را ببینی.</p></div></article></div></div></section>

<section class="bv2-final"><div class="bv-shell"><div class="bv2-final-card" data-bv-reveal><div><span>BlueVPN for Android</span><h2>اینترنت را ساده‌تر وصل کن.</h2></div><a class="bv-btn bv-btn-primary bv-btn-xl" href="<?php echo esc_url(home_url('/download/')); ?>">دانلود BlueVPN <span>↓</span></a></div></div></section>
</main>
