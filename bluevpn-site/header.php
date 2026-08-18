<!doctype html>
<html <?php language_attributes(); ?> dir="rtl">
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#06101f">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<div class="bv-site-frame">
<?php $bluevpn_elementor_header = class_exists('BlueVPN_Elementor_Integration') && BlueVPN_Elementor_Integration::render_location('header'); if (!$bluevpn_elementor_header): ?>
  <div class="bv-announcement">
    <div class="bv-shell bv-announcement-inner">
      <span><i></i> BlueVPN برای Android و Windows</span>
      <span class="bv-announcement-note">اتصال ساده، انتخاب هوشمند و پشتیبانی یک‌جا</span>
    </div>
  </div>
  <header class="bv-header" data-bv-header>
    <div class="bv-shell bv-nav">
      <div class="bv-brand"><?php echo bluevpn_site_brand(); ?></div>
      <button class="bv-menu-btn" type="button" aria-label="بازکردن منو" aria-expanded="false" data-bv-menu><span></span><span></span><span></span></button>
      <nav class="bv-menu" data-bv-menu-panel aria-label="منوی اصلی">
        <a href="<?php echo esc_url(home_url('/')); ?>">خانه</a>
        <a href="<?php echo esc_url(home_url('/#features')); ?>">امکانات</a>
        <a href="<?php echo esc_url(home_url('/#network')); ?>">شبکه</a>
        <a href="<?php echo esc_url(home_url('/plans/')); ?>">پلن‌ها</a>
        <a href="<?php echo esc_url(home_url('/support/')); ?>">پشتیبانی</a>
        <a class="bv-menu-download" href="<?php echo esc_url(home_url('/download/')); ?>">دریافت اپ</a>
      </nav>
      <div class="bv-nav-actions">
        <a class="bv-nav-account" href="<?php echo esc_url(home_url('/account/')); ?>"><span>حساب من</span><i>←</i></a>
      </div>
    </div>
  </header>
<?php endif; ?>
  <main>
