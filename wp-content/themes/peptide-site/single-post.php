<?php
/** Native single-entry content, also consumed by the design editor's original-content block. */
defined( 'ABSPATH' ) || exit;
get_header();
?>
<main id="main-content" class="container standard-page">
<?php while ( have_posts() ) : the_post(); ?>
	<h1><?php echo esc_html( get_the_title() ); ?></h1>
	<div class="entry-content">
		<p><time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time></p>
		<?php if ( '' !== trim( (string) get_the_author() ) ) : ?>
			<p><?php echo esc_html( get_the_author() ); ?></p>
		<?php endif; ?>
		<?php if ( ! post_password_required() && has_post_thumbnail() ) : ?>
			<?php the_post_thumbnail( 'large' ); ?>
		<?php endif; ?>
		<?php the_content(); ?>
		<?php wp_link_pages(); ?>
	</div>
<?php endwhile; ?>
</main>
<?php get_footer(); ?>
