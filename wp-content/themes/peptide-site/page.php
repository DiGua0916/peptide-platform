<?php defined( 'ABSPATH' ) || exit; get_header(); ?>
<main id="main-content" class="container standard-page"><?php while ( have_posts() ) : the_post(); ?><span class="eyebrow">VIALCOVE</span><h1><?php the_title(); ?></h1><div class="entry-content"><?php the_content(); ?></div><?php endwhile; ?></main>
<?php get_footer(); ?>
