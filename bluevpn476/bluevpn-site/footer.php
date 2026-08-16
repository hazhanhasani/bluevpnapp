  </main>
<?php $bluevpn_elementor_footer = class_exists('BlueVPN_Elementor_Integration') && BlueVPN_Elementor_Integration::render_location('footer'); if (!$bluevpn_elementor_footer): ?>
  <footer class="bv-footer">
    <div class="bv-shell">
      <div class="bv-footer-cta">
        <div>
          <span class="bv-kicker bv-kicker-light">BLUEVPN FOR ANDROID</span>
          <h2>ساده وارد شو، لوکیشن را انتخاب کن و وصل شو.</h2>
        </div>
        <a class="bv-btn bv-btn-light" href="<?php echo esc_url(home_url('/download/')); ?>">دانلود BlueVPN <span>↓</span></a>
      </div>
      <div class="bv-footer-main">
        <div class="bv-footer-about">
          <div class="bv-footer-brand">BlueVPN</div>
          <p>تجربه‌ای ساده برای اتصال، حساب کاربری و مدیریت اشتراک؛ بدون نمایش پیچیدگی‌های فنی به کاربر.</p>
          <div class="bv-footer-badges"><span>Android</span><span>اتصال سریع</span><span>پشتیبانی BlueVPN</span></div>
        </div>
        <div class="bv-footer-col"><h4>محصول</h4><a href="<?php echo esc_url(home_url('/')); ?>">خانه</a><a href="<?php echo esc_url(home_url('/#features')); ?>">امکانات</a><a href="<?php echo esc_url(home_url('/plans/')); ?>">پلن‌ها</a><a href="<?php echo esc_url(home_url('/download/')); ?>">دانلود</a></div>
        <div class="bv-footer-col"><h4>حساب</h4><a href="<?php echo esc_url(home_url('/account/')); ?>">ورود / ثبت‌نام</a><a href="<?php echo esc_url(home_url('/account/')); ?>">مدیریت اشتراک</a><a href="<?php echo esc_url(bluevpn_site_support_url()); ?>">پشتیبانی</a></div>
        <div class="bv-footer-col"><h4>BlueVPN</h4><a href="<?php echo esc_url(home_url('/support/')); ?>">راهنما و پشتیبانی</a><a href="<?php echo esc_url(home_url('/account/')); ?>">حساب کاربری</a><a href="<?php echo esc_url(home_url('/download/')); ?>">آخرین نسخه</a></div>
      </div>
      <div class="bv-footer-bottom"><span>© <?php echo esc_html(date_i18n('Y')); ?> BlueVPN</span><span>BlueVPN • اتصال ساده، تجربه یکپارچه</span></div>
    </div>
  </footer>
<?php endif; ?>
</div>
<div class="bv-toast" data-bv-toast></div>
<?php wp_footer(); ?>
</body></html>
