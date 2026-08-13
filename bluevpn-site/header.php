<!doctype html>
<html <?php language_attributes(); ?> dir="rtl">
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<header class="bv-header">
  <div class="bv-shell bv-nav">
    <div class="bv-brand"><?php echo bluevpn_site_brand(); ?></div>
    <button class="bv-menu-btn" type="button" aria-label="منو" data-bv-menu>☰</button>
    <nav class="bv-menu" data-bv-menu-panel>
      <a href="<?php echo esc_url(home_url('/')); ?>">خانه</a>
      <a href="<?php echo esc_url(home_url('/plans/')); ?>">پلن‌ها</a>
      <a href="<?php echo esc_url(home_url('/download/')); ?>">دانلود</a>
      <a href="<?php echo esc_url(home_url('/support/')); ?>">پشتیبانی</a>
      <a class="bv-btn bv-btn-small" href="<?php echo esc_url(home_url('/account/')); ?>">حساب من</a>
    </nav>
  </div>
</header>
<main>
