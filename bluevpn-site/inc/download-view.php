<?php
if (!defined('ABSPATH')) exit;

$bv_download_title = isset($bv_download_title) && is_string($bv_download_title) && $bv_download_title !== ''
    ? $bv_download_title : 'آخرین نسخه، مستقیم و آماده نصب.';
$bv_download_description = isset($bv_download_description) && is_string($bv_download_description) && $bv_download_description !== ''
    ? $bv_download_description : 'BlueVPN را برای موبایل یا کامپیوتر از همین صفحه دریافت کن.';
$bv_download_shot = isset($bv_download_shot) && is_string($bv_download_shot)
    ? $bv_download_shot : bluevpn_site_app_screenshot_url();

$cfg = bluevpn_site_mobile_config();
$apk = trim((string)($cfg['apk_url'] ?? ''));
$androidVersion = trim((string)($cfg['latest_version'] ?? ''));
$windows = bluevpn_site_windows_downloads();
$windowsAvailable = !empty($windows['available']);
$windowsVersion = $windowsAvailable ? trim((string)($windows['version'] ?? '')) : '';
?>
<section class="bv-subhero bv-download-hero">
  <div class="bv-shell bv-subhero-grid">
    <div data-bv-reveal>
      <span class="bv-kicker bv-kicker-light">BLUEVPN FOR ANDROID + WINDOWS</span>
      <h1><?php echo esc_html($bv_download_title); ?></h1>
      <p><?php echo esc_html($bv_download_description); ?></p>
      <div class="bv-download-meta">
        <?php if ($androidVersion !== ''): ?><span><i></i> Android <?php echo esc_html($androidVersion); ?></span><?php else: ?><span>Android</span><?php endif; ?>
        <?php if ($windowsAvailable && $windowsVersion !== ''): ?><span>Windows <?php echo esc_html($windowsVersion); ?></span><?php else: ?><span>Windows</span><?php endif; ?>
      </div>
      <div class="bv-subhero-actions bv-download-actions">
        <?php if ($apk !== '' && wp_http_validate_url($apk)): ?>
          <a class="bv-btn bv-btn-light bv-btn-xl" href="<?php echo esc_url($apk); ?>" rel="nofollow">دانلود Android <span>↓</span></a>
        <?php endif; ?>
        <?php if ($windowsAvailable): ?>
          <a class="bv-btn bv-btn-light bv-btn-xl" href="<?php echo esc_url($windows['x64_url']); ?>" rel="nofollow">نصب BlueVPN برای Windows <span>↓</span></a>
        <?php endif; ?>
      </div>
      <?php if (!$windowsAvailable): ?>
        <p class="bv-download-hint">نسخه Windows به‌زودی از همین صفحه در دسترس قرار می‌گیرد.</p>
      <?php endif; ?>
    </div>
    <?php if ($bv_download_shot !== ''): ?>
      <figure class="bv-real-app-shot bv-real-app-shot-download" data-bv-reveal><img src="<?php echo esc_url($bv_download_shot); ?>" alt="اسکرین‌شات واقعی BlueVPN" loading="lazy" decoding="async"></figure>
    <?php endif; ?>
  </div>
</section>

<section class="bv-section bv-download-info-section">
  <div class="bv-shell">
    <div class="bv-platform-download-grid">
      <article class="bv-platform-download-card" data-bv-reveal>
        <div><span class="bv-kicker">ANDROID</span><h2>BlueVPN for Android</h2><p>نسخه موبایل BlueVPN؛ آماده نصب روی گوشی و تبلت Android.</p></div>
        <div class="bv-download-facts">
          <div><span>سیستم‌عامل</span><b>Android</b></div><div><span>فرمت</span><b>APK</b></div><div><span>نسخه</span><b><?php echo esc_html($androidVersion !== '' ? $androidVersion : '—'); ?></b></div>
        </div>
        <?php if ($apk !== '' && wp_http_validate_url($apk)): ?><a class="bv-btn bv-btn-primary" href="<?php echo esc_url($apk); ?>" rel="nofollow">دانلود Android</a><?php else: ?><span class="bv-btn bv-btn-disabled">لینک دانلود به‌زودی فعال می‌شود</span><?php endif; ?>
      </article>

      <article class="bv-platform-download-card bv-platform-download-card-windows" data-bv-reveal>
        <div><span class="bv-kicker">WINDOWS</span><h2>BlueVPN for Windows</h2><p>BlueVPN برای کامپیوتر و لپ‌تاپ‌های ویندوزی.</p></div>
        <?php if ($windowsAvailable): ?>
          <div class="bv-download-facts">
            <div><span>سیستم‌عامل</span><b>Windows</b></div><div><span>نسخه</span><b><?php echo esc_html($windowsVersion !== '' ? $windowsVersion : '—'); ?></b></div><div><span>فرمت</span><b><?php echo (($windows['artifact_kind'] ?? '') === 'installer') ? 'Setup' : 'Portable'; ?></b></div>
          </div>
          <div class="bv-windows-download-buttons">
            <a class="bv-btn bv-btn-primary" href="<?php echo esc_url($windows['x64_url']); ?>" rel="nofollow">نصب برای Windows</a>
            <a class="bv-btn bv-btn-secondary" href="<?php echo esc_url($windows['arm64_url']); ?>" rel="nofollow">نصب Windows ARM</a>
          </div>
          <p class="bv-download-simple-note">برای بیشتر کامپیوترها نسخه Windows x64 مناسب است؛ فایل Setup برنامه را روی سیستم نصب و بروزرسانی‌های بعدی را دریافت می‌کند.</p>
        <?php else: ?>
          <div class="bv-download-coming-soon"><b>نسخه Windows به‌زودی منتشر می‌شود.</b><span>پس از انتشار، لینک دانلود به‌صورت خودکار همین‌جا قرار می‌گیرد.</span></div>
        <?php endif; ?>
      </article>
    </div>

    <div class="bv-install-steps">
      <article data-bv-reveal><span>01</span><h3>دانلود</h3><p>نسخه مناسب دستگاهت را دریافت کن.</p></article>
      <article data-bv-reveal><span>02</span><h3>نصب</h3><p>فایل را باز کن و مراحل نصب را ادامه بده.</p></article>
      <article data-bv-reveal><span>03</span><h3>اتصال</h3><p>وارد BlueVPN شو، لوکیشن را انتخاب کن و متصل شو.</p></article>
    </div>
  </div>
</section>
