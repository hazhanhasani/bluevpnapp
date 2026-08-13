<?php get_header(); $cfg=bluevpn_site_mobile_config(); $apk=(string)($cfg['apk_url']??''); ?>
<section class="bv-page-hero"><div class="bv-shell"><div class="bv-kicker">ANDROID APP</div><h1>دانلود BlueVPN</h1><p>آخرین نسخه منتشرشده اپلیکیشن را مستقیماً دریافت کنید.</p></div></section>
<section class="bv-section"><div class="bv-shell"><div class="bv-download-card">
<div class="bv-app-icon">B</div><div class="bv-download-info"><h2>BlueVPN for Android</h2><p>نسخه <strong><?php echo esc_html((string)($cfg['latest_version']??'—')); ?></strong><?php if(!empty($cfg['release_build_number'])): ?> • Build #<?php echo (int)$cfg['release_build_number']; ?><?php endif; ?></p><small>منبع نسخه: <?php echo esc_html((string)($cfg['update_source']??'WordPress')); ?></small></div>
<?php if ($apk && wp_http_validate_url($apk)): ?><a class="bv-btn" href="<?php echo esc_url($apk); ?>" rel="nofollow">دانلود APK</a><?php else: ?><span class="bv-btn bv-btn-disabled">لینک دانلود هنوز منتشر نشده</span><?php endif; ?>
</div></div></section>
<?php get_footer(); ?>
