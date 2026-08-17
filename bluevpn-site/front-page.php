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
<?php require BLUEVPN_SITE_DIR . '/inc/home-v2.php'; ?>
<?php get_footer(); ?>
