<!doctype html>
<html <?php language_attributes(); ?> dir="rtl">
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#07101d">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<header class="bv-header">
  <div class="bv-shell bv-nav">
    <div class="bv-brand"><?php echo bluevpn_site_brand(); ?></div>
    <button class="bv-menu-btn" type="button" aria-label="بازکردن منو" aria-expanded="false" data-bv-menu><span></span><span></span><span></span></button>
    <nav class="bv-menu" data-bv-menu-panel aria-label="منوی اصلی">
      <a href="<?php echo esc_url(home_url('/')); ?>">خانه</a>
      <a href="<?php echo esc_url(home_url('/#features')); ?>">امکانات</a>
      <a href="<?php echo esc_url(home_url('/plans/')); ?>">پلن‌ها</a>
      <a href="<?php echo esc_url(home_url('/download/')); ?>">دانلود</a>
      <a href="<?php echo esc_url(home_url('/support/')); ?>">پشتیبانی</a>
      <a class="bv-nav-account" href="<?php echo esc_url(home_url('/account/')); ?>"><span>حساب من</span><i>←</i></a>
    </nav>
  </div>
</header>
<main>
