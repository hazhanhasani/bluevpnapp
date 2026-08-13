<?php get_header(); ?>
<?php if (have_posts()): while (have_posts()): the_post(); ?>
  <?php if (class_exists('BlueVPN_Elementor_Integration') && BlueVPN_Elementor_Integration::page_ready(get_the_ID())): ?>
    <?php the_content(); ?>
  <?php else: ?>
    <section class="bv-subhero bv-simple-hero"><div class="bv-shell"><span class="bv-kicker bv-kicker-light">BLUEVPN</span><h1><?php the_title(); ?></h1></div></section>
    <section class="bv-section bv-generic-section"><div class="bv-shell bv-content"><?php the_content(); ?></div></section>
  <?php endif; ?>
<?php endwhile; endif; ?>
<?php get_footer(); ?>
