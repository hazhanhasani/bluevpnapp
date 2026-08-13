<?php get_header(); ?>
<section class="bv-page-hero"><div class="bv-shell"><h1><?php single_post_title(); ?></h1></div></section>
<section class="bv-section"><div class="bv-shell bv-content"><?php if(have_posts()): while(have_posts()): the_post(); the_content(); endwhile; endif; ?></div></section>
<?php get_footer(); ?>
