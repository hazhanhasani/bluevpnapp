<?php if (class_exists('BlueVPN_Elementor_Integration') && BlueVPN_Elementor_Integration::render_page()) { return; } ?>
<?php
get_header();
$cfg = bluevpn_site_mobile_config();
$latest = (string)($cfg['latest_version'] ?? 'Android');
$apk = (string)($cfg['apk_url'] ?? '');
$shots = [
  'home' => '',
  'locations' => '',
  'home_explicit' => '',
  'locations_explicit' => '',
  'account' => bluevpn_site_app_screenshot_url('account'),
  'support' => bluevpn_site_app_screenshot_url('support'),
];
?>
<?php
$bluevpn_home_template = BLUEVPN_SITE_DIR . '/inc/home-v2.php';
if (is_readable($bluevpn_home_template)) {
    require $bluevpn_home_template;
} else {
    bluevpn_site_log(
        'BlueVPN home template is missing; rendering safe fallback.',
        'warning',
        'THEME_HOME_TEMPLATE_MISSING',
        ['file' => 'inc/home-v2.php']
    );
    ?>
    <main class="bv-home-fallback">
      <section class="bv-section">
        <div class="bv-shell">
          <h1>BlueVPN</h1>
          <p>سرویس در حال بروزرسانی است. بخش‌های حساب، دانلود و پشتیبانی همچنان در دسترس هستند.</p>
          <p>
            <a class="bv-btn bv-btn-primary" href="<?php echo esc_url(home_url('/download/')); ?>">دانلود BlueVPN</a>
            <a class="bv-btn" href="<?php echo esc_url(home_url('/account/')); ?>">حساب کاربری</a>
          </p>
        </div>
      </section>
    </main>
    <?php
}
?>
<?php get_footer(); ?>
