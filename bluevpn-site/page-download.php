<?php if (class_exists('BlueVPN_Elementor_Integration') && BlueVPN_Elementor_Integration::render_page()) { return; } ?>
<?php
get_header();
$bv_download_title = 'آخرین نسخه، مستقیم و آماده نصب.';
$bv_download_description = 'نسخه مناسب BlueVPN را برای Android یا Windows از همین صفحه دریافت کن؛ هر پلتفرم با نسخه و وضعیت انتشار مستقل خودش.';
$bv_download_shot = bluevpn_site_app_screenshot_url();
require BLUEVPN_SITE_DIR . '/inc/download-view.php';
get_footer();
