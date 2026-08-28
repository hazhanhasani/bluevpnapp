<?php if (!defined('ABSPATH')) exit; ?>
<?php
$bundled_home_image = BLUEVPN_SITE_URL . '/assets/images/app-home-connection-clean.webp';
$bundled_locations_image = BLUEVPN_SITE_URL . '/assets/images/app-locations-clean.webp';
$hero_art = BLUEVPN_SITE_URL . '/assets/images/visuals/bluevpn-shield-globe.webp';
$hero_image = !empty($shots['home_explicit']) ? $shots['home_explicit'] : $bundled_home_image;
$locations_image = !empty($shots['locations_explicit']) ? $shots['locations_explicit'] : $bundled_locations_image;
$account_image = $shots['account'] ?? '';
$support_image = $shots['support'] !== '' ? $shots['support'] : BLUEVPN_SITE_URL . '/assets/images/app-support-real.jpg';
?>
<main class="bv-home-v5">
<section class="bv5-hero">
  <div class="bv-shell bv5-hero-grid">
    <div class="bv5-copy" data-bv-reveal>
      <span class="bv5-eyebrow"><i></i> اتصال ساده، انتخاب با خودت</span>
      <h1>اینترنتت را ساده‌تر وصل کن.<br><em>BlueVPN آماده است.</em></h1>
      <p>کشور دلخواهت را انتخاب کن یا انتخاب را به BlueVPN بسپار؛ بعد با یک لمس وصل شو. اشتراک و پشتیبانی هم همیشه از داخل حساب خودت در دسترس است.</p>
      <div class="bv5-actions">
        <a class="bv-btn bv-btn-primary bv-btn-xl" href="#app-showcase">دیدن محیط اپ <span>↓</span></a>
        <a class="bv5-text-link" href="<?php echo esc_url(home_url('/plans/')); ?>">دیدن پلن‌ها <span>←</span></a>
      </div>
      <div class="bv5-proof"><span><b>✓</b> اتصال یک‌لمسی</span><span><b>✓</b> لوکیشن هوشمند</span><span><b>✓</b> Free + Premium</span></div>
    </div>
    <div class="bv5-hero-product" data-bv-reveal data-bv-parallax>
      <div class="bv5-ring r1"></div><div class="bv5-ring r2"></div>
      <div class="bv5-hero-art"><img src="<?php echo esc_url($hero_art); ?>" alt="سپر محافظ شبکه جهانی BlueVPN" fetchpriority="high" decoding="async" width="900" height="900"></div>
      <figure class="bv5-hero-phone"><img src="<?php echo esc_url($hero_image); ?>" alt="صفحه اتصال واقعی BlueVPN" loading="eager" decoding="async"></figure>
      <span class="bv5-chip c1"><i></i> متصل</span>
      <span class="bv5-chip c2"><?php echo bluevpn_site_icon('radar'); ?> انتخاب هوشمند</span>
    </div>
  </div>
</section>

<section class="bv5-network-strip" aria-label="سازگاری شبکه و اپراتورها">
 <div class="bv-shell">
  <div class="bv5-strip-track">
   <b>سازگار با اینترنت روزمره تو</b>
   <span class="bv5-operator-chip"><img src="<?php echo esc_url(BLUEVPN_SITE_URL . '/assets/images/operator-mci.jpg'); ?>" alt="لوگوی همراه اول" loading="lazy"><span>همراه اول</span></span>
   <span class="bv5-operator-chip"><img src="<?php echo esc_url(BLUEVPN_SITE_URL . '/assets/images/operator-irancell.png'); ?>" alt="لوگوی ایرانسل" loading="lazy"><span>ایرانسل</span></span>
   <span class="bv5-operator-chip"><img src="<?php echo esc_url(BLUEVPN_SITE_URL . '/assets/images/operator-rightel.png'); ?>" alt="لوگوی رایتل" loading="lazy"><span>رایتل</span></span>
   <span class="bv5-tech-chip"><?php echo bluevpn_site_icon('android'); ?>Android</span>
   <span class="bv5-tech-chip"><?php echo bluevpn_site_icon('radar'); ?>Wi‑Fi</span>
   <span class="bv5-tech-chip">IPv4 / IPv6</span>
  </div>
 </div>
</section>

<section class="bv5-section bv5-value" id="app-showcase">
 <div class="bv-shell">
  <header class="bv5-heading" data-bv-reveal><span>BlueVPN را قبل از شروع بشناس</span><h2>هر چیزی که برای اتصال لازم داری،<br>همان‌جا جلوی چشمت است.</h2><p>بعد از اتصال می‌توانی سرعت، مدت اتصال، لوکیشن فعال و وضعیت اشتراکت را همان لحظه ببینی.</p></header>
  <div class="bv5-feature-row">
   <article data-bv-reveal><strong>01</strong><div class="ico"><?php echo bluevpn_site_icon('bolt'); ?></div><h3>اتصال سریع</h3><p>اپ را باز کن، دکمه اتصال را بزن و آنلاین شو.</p></article>
   <article data-bv-reveal><strong>02</strong><div class="ico"><?php echo bluevpn_site_icon('radar'); ?></div><h3>انتخاب هوشمند</h3><p>اگر نخواهی دستی انتخاب کنی، BlueVPN بهترین گزینه در دسترس را برایت پیدا می‌کند.</p></article>
   <article data-bv-reveal><strong>03</strong><div class="ico"><?php echo bluevpn_site_icon('account'); ?></div><h3>حساب یکپارچه</h3><p>اشتراکت را ببین، تمدید کن و درخواست پشتیبانی را از حساب خودت پیگیری کن.</p></article>
   <article data-bv-reveal><strong>04</strong><div class="ico"><?php echo bluevpn_site_icon('refresh'); ?></div><h3>بروزرسانی کنترل‌شده</h3><p>وقتی نسخه تازه‌ای منتشر شود، از مسیر رسمی BlueVPN به نسخه جدید دسترسی داری.</p></article>
  </div>
 </div>
</section>

<section class="bv5-section bv5-screen-section white">
 <div class="bv-shell bv5-screen-grid">
   <div class="bv5-screen-copy" data-bv-reveal><span>صفحه اصلی و اتصال</span><h2>اتصال را بزن؛<br>وضعیتش را همان‌جا ببین.</h2><p>بعد از اتصال، سرعت دانلود و آپلود، مدت اتصال، لوکیشن فعال و باقی‌مانده اشتراکت یک‌جا نمایش داده می‌شود.</p><ul><li><i>✓</i> وضعیت اتصال واضح</li><li><i>✓</i> سرعت دانلود و آپلود</li><li><i>✓</i> سرور و لوکیشن فعال</li><li><i>✓</i> وضعیت اشتراک</li></ul></div>
   <div class="bv5-device bv5-product-stage" data-bv-reveal><div class="glow"></div><figure class="bv5-shot-frame"><img src="<?php echo esc_url($hero_image); ?>" alt="رابط واقعی صفحه اتصال BlueVPN" loading="lazy"></figure></div>
 </div>
</section>

<section class="bv5-section bv5-screen-section locations" id="network">
 <div class="bv-shell bv5-locations-layout">
   <div class="bv5-locations-device" data-bv-reveal>
     <div class="glow"></div>
     <figure class="bv5-shot-frame"><img src="<?php echo esc_url($locations_image); ?>" alt="صفحه واقعی لوکیشن‌های BlueVPN" loading="lazy"></figure>
   </div>
   <div class="bv5-locations-copy" data-bv-reveal>
     <span>لوکیشن‌ها</span>
     <h2>لوکیشن دلخواهت را انتخاب کن؛<br>یا بگذار BlueVPN انتخاب کند.</h2>
     <p>کشورها و پینگ هر مسیر را ببین و با یک لمس انتخاب کن. برای اتصال سریع‌تر هم می‌توانی «انتخاب هوشمند» را روشن بگذاری.</p>
     <div class="bv5-pills"><span><i>DE</i> آلمان</span><span><i>TR</i> ترکیه</span><span><i>NL</i> هلند</span><span><i>US</i> آمریکا</span><span><i>CA</i> کانادا</span><span><?php echo bluevpn_site_icon('radar'); ?> خودکار</span></div>
   </div>
 </div>
</section>

<section class="bv5-section bv5-trust">
 <div class="bv-shell">
  <header class="bv5-heading light" data-bv-reveal><span>چیزی که هر روز به کارت می‌آید</span><h2>برای وصل‌شدن، لازم نیست متخصص باشی.</h2><p>روش اتصال را انتخاب کن و استفاده را شروع کن. هر وقت هم به راهنمایی یا امکانات بیشتری نیاز داشتی، مسیرش داخل BlueVPN مشخص است.</p></header>
  <div class="bv5-metrics">
   <article data-bv-reveal><b>اندروید</b><span>فقط برای Android</span><p>BlueVPN برای Android ساخته شده و بدون برنامه جانبی تجربه اصلی را در اختیارت می‌گذارد.</p></article>
   <article data-bv-reveal><b>رایگان و پریمیوم</b><span>رایگان شروع کن</span><p>اول رایگان امتحان کن؛ اگر لوکیشن‌ها و امکانات بیشتری خواستی Premium را فعال کن.</p></article>
   <article data-bv-reveal><b>هوشمند و دستی</b><span>دستی یا هوشمند</span><p>خودت کشور را انتخاب کن یا اجازه بده BlueVPN گزینه مناسب را پیدا کند.</p></article>
   <article data-bv-reveal><b>همیشه در دسترس</b><span>حساب و پشتیبانی</span><p>وضعیت اشتراک و پیام‌های پشتیبانی را از حساب خودت دنبال کن.</p></article>
  </div>
 </div>
</section>

<section class="bv5-section bv5-plans" id="plans">
 <div class="bv-shell">
  <header class="bv5-heading" data-bv-reveal><span>رایگان یا Premium</span><h2>اول امتحان کن؛<br>بعد اگر خواستی ارتقا بده.</h2><p>برای شروع لازم نیست هزینه کنی. حالت رایگان را امتحان کن و اگر به انتخاب‌های بیشتر نیاز داشتی، پلن Premium مناسب خودت را بگیر.</p></header>
  <div class="bv5-plan-cards">
   <article class="free" data-bv-reveal><div class="top"><span>رایگان</span><i>برای شروع</i></div><h3>اتصال رایگان</h3><p>برای شروع سریع و آشناشدن با BlueVPN، بدون خرید اشتراک.</p><ul><li><b>✓</b> اتصال از داخل اپ</li><li><b>✓</b> بدون واردکردن کانفیگ</li><li><b>✓</b> تجربه ساده و یک‌لمسی</li><li class="muted"><b>—</b> انتخاب‌های محدودتر نسبت به Premium</li></ul><a class="bv5-outline-btn" href="<?php echo esc_url(home_url('/plans/')); ?>">مقایسه با Premium</a></article>
   <article class="premium" data-bv-reveal><div class="shine"></div><div class="top"><span>Premium</span><i>امکانات بیشتر</i></div><h3>اشتراک Premium</h3><p>وقتی لوکیشن‌ها و امکانات بیشتری می‌خواهی، Premium انتخاب کامل‌تری است.</p><ul><li><b>✓</b> لوکیشن‌های بیشتر و انتخاب هوشمند</li><li><b>✓</b> مدیریت اشتراک در حساب</li><li><b>✓</b> خرید از پلن‌های فعال</li><li><b>✓</b> نمایش وضعیت و باقی‌مانده داخل اپ</li></ul><a class="bv-btn bv-btn-primary" href="<?php echo esc_url(home_url('/plans/')); ?>">دیدن پلن‌های فعال</a></article>
  </div>
 </div>
</section>

<section class="bv5-section bv5-journey">
 <div class="bv-shell">
  <header class="bv5-heading compact" data-bv-reveal><span>شروع کار</span><h2>فقط سه قدم تا اتصال.</h2></header>
  <div class="bv5-steps"><article data-bv-reveal><b>01</b><div><?php echo bluevpn_site_icon('login'); ?></div><h3>ورود</h3><p>وارد BlueVPN شو و وضعیت حسابت را ببین.</p></article><article data-bv-reveal><b>02</b><div><?php echo bluevpn_site_icon('location'); ?></div><h3>انتخاب</h3><p>انتخاب هوشمند را بزن یا کشور دلخواهت را انتخاب کن.</p></article><article data-bv-reveal><b>03</b><div><?php echo bluevpn_site_icon('bolt'); ?></div><h3>اتصال</h3><p>دکمه اتصال را بزن و استفاده را شروع کن.</p></article></div>
 </div>
</section>

<section class="bv5-section bv5-account" id="account-support">
 <div class="bv-shell">
  <header class="bv5-heading" data-bv-reveal><span>حساب و پشتیبانی</span><h2>اشتراکت را مدیریت کن؛<br>هر وقت لازم بود پیام بده.</h2></header>
  <div class="bv5-account-grid">
   <article class="account-card" data-bv-reveal>
    <div class="bv5-account-copy"><span>حساب کاربری</span><h3>اشتراکت را ببین و مدیریت کن.</h3><p>وارد حسابت شو، وضعیت سرویس و باقی‌مانده را ببین و در صورت نیاز پلنت را تمدید یا تغییر بده.</p><a href="<?php echo esc_url(home_url('/account/')); ?>">ورود به حساب ←</a></div>
    <?php if ($account_image !== ''): ?><figure><img src="<?php echo esc_url($account_image); ?>" alt="صفحه حساب BlueVPN" loading="lazy"></figure><?php else: ?><div class="bv5-account-ui" aria-label="نمای حساب BlueVPN"><div class="head"><span>B</span><div><b>حساب BlueVPN</b><small>وضعیت سرویس</small></div><i>فعال</i></div><div class="cards"><span><small>پلن</small><b>Premium</b></span><span><small>وضعیت</small><b>فعال</b></span></div><div class="line"><i></i><span>مدیریت اشتراک و دستگاه‌ها</span></div></div><?php endif; ?>
   </article>
   <article class="support-card" data-bv-reveal>
    <div class="bv5-account-copy"><span>پشتیبانی داخل محصول</span><h3>سؤالت را بفرست؛ پاسخ را همان‌جا دنبال کن.</h3><p>موضوع مشکلت را انتخاب کن، پیام بده و پاسخ پشتیبانی را بدون گم‌کردن گفتگو پیگیری کن.</p><a href="<?php echo esc_url(home_url('/support/')); ?>">رفتن به پشتیبانی ←</a></div>
    <figure class="bv5-support-shot"><img src="<?php echo esc_url($support_image); ?>" alt="صفحه واقعی پشتیبانی BlueVPN" loading="lazy"></figure>
   </article>
  </div>
 </div>
</section>

<section class="bv5-section bv5-faq">
 <div class="bv-shell"><header class="bv5-heading compact" data-bv-reveal><span>پرسش‌های پرتکرار</span><h2>قبل از شروع چیزی می‌خواهی بدانی؟</h2></header><div class="bv5-faq-list">
  <details data-bv-reveal><summary>برای اتصال باید کانفیگ وارد کنم؟</summary><p>خیر. BlueVPN برای اتصال آماده طراحی شده است؛ فقط لوکیشن را انتخاب کن یا انتخاب را به حالت هوشمند بسپار و وصل شو.</p></details>
  <details data-bv-reveal><summary>لوکیشن را می‌توانم خودم انتخاب کنم؟</summary><p>بله. در کنار انتخاب خودکار، صفحه لوکیشن‌ها برای انتخاب کشور و مشاهده وضعیت مسیر در دسترس است.</p></details>
  <details data-bv-reveal><summary>فرق Free و Premium چیست؟</summary><p>Free برای شروع ساده است؛ Premium انتخاب‌های بیشتری در بخش لوکیشن و مدیریت اشتراک در اختیار کاربر می‌گذارد. جزئیات پلن‌های فعال در صفحه پلن‌ها نمایش داده می‌شود.</p></details>
  <details data-bv-reveal><summary>نسخه جدید را از کجا دریافت کنم؟</summary><p>نسخه رسمی همیشه از بخش «دریافت اپ» در منوی اصلی در دسترس است.</p></details>
  <details data-bv-reveal><summary>برای مشکل اتصال از کجا پیگیری کنم؟</summary><p>از صفحه پشتیبانی می‌توانی موضوع درخواست را انتخاب و پیگیری را ادامه بدهی.</p></details>
 </div></div>
</section>

</main>
