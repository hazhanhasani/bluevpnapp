<?php
if (!defined('ABSPATH')) exit;

$bv_download_title = isset($bv_download_title) && is_string($bv_download_title) && $bv_download_title !== ''
    ? $bv_download_title : 'آخرین نسخه، مستقیم و آماده نصب.';
$bv_download_description = isset($bv_download_description) && is_string($bv_download_description) && $bv_download_description !== ''
    ? $bv_download_description : 'نسخه مناسب BlueVPN را برای Android یا Windows از همین صفحه دریافت کن.';
$bv_download_shot = isset($bv_download_shot) && is_string($bv_download_shot)
    ? $bv_download_shot : bluevpn_site_app_screenshot_url();

$cfg = bluevpn_site_mobile_config();
$apk = trim((string)($cfg['apk_url'] ?? ''));
$androidVersion = trim((string)($cfg['latest_version'] ?? ''));
$windows = bluevpn_site_windows_downloads();
$windowsAvailable = !empty($windows['available']);
$windowsVersion = $windowsAvailable ? (string)$windows['version'] : '—';
$windowsChannel = $windowsAvailable ? (string)$windows['channel_label'] : 'هنوز انتشار عمومی ثبت نشده';
?>
<section class="bv-subhero bv-download-hero">
  <div class="bv-shell bv-subhero-grid">
    <div data-bv-reveal>
      <span class="bv-kicker bv-kicker-light">BLUEVPN FOR ANDROID + WINDOWS</span>
      <h1><?php echo esc_html($bv_download_title); ?></h1>
      <p><?php echo esc_html($bv_download_description); ?></p>
      <div class="bv-download-meta">
        <?php if ($androidVersion !== ''): ?><span><i></i> Android <?php echo esc_html($androidVersion); ?></span><?php else: ?><span>Android</span><?php endif; ?>
        <?php if ($windowsAvailable): ?><span>Windows <?php echo esc_html($windowsVersion); ?></span><span><?php echo esc_html($windowsChannel); ?></span><?php else: ?><span>Windows</span><?php endif; ?>
      </div>
      <div class="bv-subhero-actions bv-download-actions">
        <?php if ($apk !== '' && wp_http_validate_url($apk)): ?>
          <a class="bv-btn bv-btn-light bv-btn-xl" href="<?php echo esc_url($apk); ?>" rel="nofollow">دانلود Android APK <span>↓</span></a>
        <?php endif; ?>
        <?php if ($windowsAvailable): ?>
          <a class="bv-btn bv-btn-light bv-btn-xl" href="<?php echo esc_url($windows['x64_url']); ?>" rel="nofollow">دانلود Windows x64 <span>↓</span></a>
          <a class="bv-btn bv-btn-ghost bv-btn-xl" href="<?php echo esc_url($windows['arm64_url']); ?>" rel="nofollow">Windows ARM64 <span>↓</span></a>
        <?php endif; ?>
      </div>
      <?php if ($windowsAvailable): ?>
        <p class="bv-download-hint">Windows یک انتشار مستقل با وضعیت <b><?php echo esc_html($windowsChannel); ?></b> است. برای بیشتر دستگاه‌های Intel/AMD گزینه <b>x64</b> مناسب است؛ ARM64 مخصوص Windows on ARM است.</p>
      <?php else: ?>
        <p class="bv-download-hint">پلتفرم <b>Windows</b> در قالب تعریف شده است؛ به‌محض انتشار کامل x64 و ARM64 در Release رسمی، لینک‌ها خودکار همین‌جا ظاهر می‌شوند.</p>
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
        <div><span class="bv-kicker">ANDROID</span><h2>BlueVPN for Android</h2><p>نسخه موبایل BlueVPN با نصب مستقیم APK.</p></div>
        <div class="bv-download-facts">
          <div><span>سیستم‌عامل</span><b>Android</b></div><div><span>فرمت</span><b>APK</b></div><div><span>نسخه</span><b><?php echo esc_html($androidVersion !== '' ? $androidVersion : '—'); ?></b></div>
        </div>
        <?php if ($apk !== '' && wp_http_validate_url($apk)): ?><a class="bv-btn bv-btn-primary" href="<?php echo esc_url($apk); ?>" rel="nofollow">دانلود APK</a><?php else: ?><span class="bv-btn bv-btn-disabled">لینک Android هنوز منتشر نشده</span><?php endif; ?>
      </article>

      <article class="bv-platform-download-card bv-platform-download-card-windows" data-bv-reveal>
        <div><span class="bv-kicker">WINDOWS</span><h2>BlueVPN for Windows</h2><p>نسخه Windows به‌صورت مستقل از Android منتشر می‌شود و کانال انتشار خودش را دارد.</p></div>
        <div class="bv-download-facts">
          <div><span>نسخه</span><b><?php echo esc_html($windowsVersion); ?></b></div><div><span>وضعیت</span><b><?php echo esc_html($windowsChannel); ?></b></div><div><span>پردازنده</span><b>x64 / ARM64</b></div>
        </div>
        <?php if ($windowsAvailable): ?>
          <div class="bv-windows-download-buttons">
            <a class="bv-btn bv-btn-primary" href="<?php echo esc_url($windows['x64_url']); ?>" rel="nofollow">Windows x64</a>
            <a class="bv-btn bv-btn-secondary" href="<?php echo esc_url($windows['arm64_url']); ?>" rel="nofollow">Windows ARM64</a>
          </div>
          <?php if (!empty($windows['release_url'])): ?><a class="bv-release-link" href="<?php echo esc_url($windows['release_url']); ?>" rel="nofollow">جزئیات انتشار و SHA256 ↗</a><?php endif; ?>
        <?php else: ?>
          <span class="bv-btn bv-btn-disabled">در انتظار اولین Release کامل Windows</span>
        <?php endif; ?>
      </article>
    </div>

    <div class="bv-install-steps">
      <article data-bv-reveal><span>01</span><h3>دانلود</h3><p>نسخه مناسب دستگاهت را از همین صفحه دریافت کن.</p></article>
      <article data-bv-reveal><span>02</span><h3>اجرا</h3><p>Android را نصب کن؛ در Windows فایل ZIP را استخراج و BlueVPN.exe را اجرا کن.</p></article>
      <article data-bv-reveal><span>03</span><h3>ورود و اتصال</h3><p>با حساب BlueVPN وارد شو، لوکیشن را انتخاب کن و متصل شو.</p></article>
    </div>
  </div>
</section>
