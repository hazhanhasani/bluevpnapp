<?php if (class_exists('BlueVPN_Elementor_Integration') && BlueVPN_Elementor_Integration::render_page()) { return; } ?>
<?php
get_header();
$cfg = bluevpn_site_mobile_config();
$apk = (string)($cfg['apk_url'] ?? '');
$latest = (string)($cfg['latest_version'] ?? BLUEVPN_SITE_VERSION);
$windows = bluevpn_site_windows_downloads(BLUEVPN_SITE_VERSION);
?>
<section class="bv-subhero bv-download-hero">
  <div class="bv-shell bv-subhero-grid">
    <div data-bv-reveal>
      <span class="bv-kicker bv-kicker-light">BLUEVPN FOR ANDROID + WINDOWS</span>
      <h1>BlueVPN را مستقیم از سایت دریافت کن.</h1>
      <p>نسخه Android و نسخه‌های رسمی Windows برای x64 و ARM64 از همین صفحه، مستقیم و یک‌جا در دسترس هستند.</p>
      <div class="bv-download-meta">
        <span><i></i> نسخه <?php echo esc_html(BLUEVPN_SITE_VERSION); ?></span>
        <span>Android</span><span>Windows x64</span><span>Windows ARM64</span>
      </div>
      <div class="bv-subhero-actions bv-download-actions">
        <?php if ($apk && wp_http_validate_url($apk)): ?>
          <a class="bv-btn bv-btn-light bv-btn-xl" href="<?php echo esc_url($apk); ?>" rel="nofollow">دانلود Android APK <span>↓</span></a>
        <?php endif; ?>
        <a class="bv-btn bv-btn-light bv-btn-xl" href="<?php echo esc_url($windows['x64_url']); ?>" rel="nofollow">دانلود Windows x64 <span>↓</span></a>
        <a class="bv-btn bv-btn-ghost bv-btn-xl" href="<?php echo esc_url($windows['arm64_url']); ?>" rel="nofollow">Windows ARM64 <span>↓</span></a>
      </div>
      <p class="bv-download-hint">برای بیشتر لپ‌تاپ‌ها و کامپیوترهای Intel/AMD گزینه <b>x64</b> مناسب است. ARM64 مخصوص دستگاه‌های Windows on ARM است.</p>
    </div>
    <?php $shot = bluevpn_site_app_screenshot_url(); if ($shot !== ''): ?>
      <figure class="bv-real-app-shot bv-real-app-shot-download" data-bv-reveal><img src="<?php echo esc_url($shot); ?>" alt="اسکرین‌شات واقعی BlueVPN" loading="lazy" decoding="async"></figure>
    <?php endif; ?>
  </div>
</section>

<section class="bv-section bv-download-info-section">
  <div class="bv-shell">
    <div class="bv-platform-download-grid">
      <article class="bv-platform-download-card" data-bv-reveal>
        <div><span class="bv-kicker">ANDROID</span><h2>BlueVPN for Android</h2><p>نسخه موبایل BlueVPN با نصب مستقیم APK.</p></div>
        <div class="bv-download-facts">
          <div><span>سیستم‌عامل</span><b>Android</b></div><div><span>فرمت</span><b>APK</b></div><div><span>نسخه</span><b><?php echo esc_html($latest); ?></b></div>
        </div>
        <?php if ($apk && wp_http_validate_url($apk)): ?><a class="bv-btn bv-btn-primary" href="<?php echo esc_url($apk); ?>" rel="nofollow">دانلود APK</a><?php else: ?><span class="bv-btn bv-btn-disabled">لینک Android هنوز منتشر نشده</span><?php endif; ?>
      </article>

      <article class="bv-platform-download-card bv-platform-download-card-windows" data-bv-reveal>
        <div><span class="bv-kicker">WINDOWS</span><h2>BlueVPN for Windows</h2><p>نسخه مستقل و آماده اجرا؛ بدون نیاز به نصب پیش‌نیازهای اضافی.</p></div>
        <div class="bv-download-facts">
          <div><span>نسخه</span><b><?php echo esc_html($windows['version']); ?></b></div><div><span>پردازنده</span><b>x64 / ARM64</b></div><div><span>فرمت</span><b>ZIP</b></div>
        </div>
        <div class="bv-windows-download-buttons">
          <a class="bv-btn bv-btn-primary" href="<?php echo esc_url($windows['x64_url']); ?>" rel="nofollow">Windows x64</a>
          <a class="bv-btn bv-btn-secondary" href="<?php echo esc_url($windows['arm64_url']); ?>" rel="nofollow">Windows ARM64</a>
        </div>
        <a class="bv-release-link" href="<?php echo esc_url($windows['release_url']); ?>" rel="nofollow">مشاهده جزئیات انتشار و SHA256 ↗</a>
      </article>
    </div>

    <div class="bv-install-steps">
      <article data-bv-reveal><span>01</span><h3>دانلود</h3><p>نسخه مناسب دستگاهت را از همین صفحه دریافت کن.</p></article>
      <article data-bv-reveal><span>02</span><h3>اجرا</h3><p>Android را نصب کن؛ در Windows فایل ZIP را استخراج و BlueVPN.exe را اجرا کن.</p></article>
      <article data-bv-reveal><span>03</span><h3>ورود و اتصال</h3><p>با حساب BlueVPN وارد شو، لوکیشن را انتخاب کن و متصل شو.</p></article>
    </div>
  </div>
</section>
<?php get_footer(); ?>
