  </main>
<?php $bluevpn_elementor_footer = class_exists('BlueVPN_Elementor_Integration') && BlueVPN_Elementor_Integration::render_location('footer'); if (!$bluevpn_elementor_footer): ?>
  <footer class="bv-footer">
    <div class="bv-shell">
      <div class="bv-footer-cta bv-footer-cta-static">
        <div>
          <span class="bv-kicker bv-kicker-light">BLUEVPN</span>
          <h2>اتصال، حساب و پشتیبانی؛ همه در یک تجربه ساده.</h2>
        </div>
      </div>
      <div class="bv-footer-main">
        <div class="bv-footer-about">
          <div class="bv-footer-brand">BlueVPN</div>
          <p>اتصال، مدیریت اشتراک و پشتیبانی را ساده و یک‌جا در BlueVPN تجربه کن.</p>
          <div class="bv-footer-badges"><span>Android</span><span>اتصال سریع</span><span>پشتیبانی BlueVPN</span></div>
        </div>
        <div class="bv-footer-col"><h4>محصول</h4><a href="<?php echo esc_url(home_url('/')); ?>">خانه</a><a href="<?php echo esc_url(home_url('/#features')); ?>">امکانات</a><a href="<?php echo esc_url(home_url('/plans/')); ?>">پلن‌ها</a></div>
        <div class="bv-footer-col"><h4>حساب</h4><a href="<?php echo esc_url(home_url('/account/')); ?>">ورود / ثبت‌نام</a><a href="<?php echo esc_url(home_url('/account/')); ?>">مدیریت اشتراک</a><a href="<?php echo esc_url(bluevpn_site_support_url()); ?>">پشتیبانی</a></div>
        <div class="bv-footer-col"><h4>BlueVPN</h4><a href="<?php echo esc_url(home_url('/support/')); ?>">راهنما و پشتیبانی</a><a href="<?php echo esc_url(home_url('/account/')); ?>">حساب کاربری</a></div>
      </div>
      <div class="bv-footer-bottom"><span>© <?php echo esc_html(date_i18n('Y')); ?> BlueVPN</span><span>BlueVPN • اتصال ساده، تجربه یکپارچه</span></div>
    </div>
  </footer>
<?php endif; ?>
</div>
<div class="bv-network-status" data-bv-network-status role="status" aria-live="polite" aria-hidden="true"><span></span><b>اتصال اینترنت قطع است</b></div>
<div class="bv-toast" data-bv-toast role="status" aria-live="polite"></div>
<?php wp_footer(); ?>
</body></html>
