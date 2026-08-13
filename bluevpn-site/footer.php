</main>
<footer class="bv-footer">
  <div class="bv-shell bv-footer-main">
    <div class="bv-footer-about">
      <div class="bv-footer-brand">BlueVPN</div>
      <p>اتصال ساده، مدیریت‌شده و یکپارچه؛ از اپلیکیشن تا حساب کاربری و پرداخت.</p>
      <a class="bv-footer-download" href="<?php echo esc_url(home_url('/download/')); ?>">دانلود اپلیکیشن <span>←</span></a>
    </div>
    <div class="bv-footer-col"><h4>BlueVPN</h4><a href="<?php echo esc_url(home_url('/')); ?>">خانه</a><a href="<?php echo esc_url(home_url('/plans/')); ?>">پلن‌ها</a><a href="<?php echo esc_url(home_url('/download/')); ?>">دانلود</a></div>
    <div class="bv-footer-col"><h4>حساب</h4><a href="<?php echo esc_url(home_url('/account/')); ?>">ورود / ثبت‌نام</a><a href="<?php echo esc_url(home_url('/account/')); ?>">مدیریت اشتراک</a><a href="<?php echo esc_url(bluevpn_site_support_url()); ?>">پشتیبانی</a></div>
  </div>
  <div class="bv-shell bv-footer-bottom"><span>© <?php echo esc_html(date_i18n('Y')); ?> BlueVPN</span><span>طراحی اختصاصی BlueVPN</span></div>
</footer>
<div class="bv-toast" data-bv-toast></div>
<?php wp_footer(); ?>
</body></html>
