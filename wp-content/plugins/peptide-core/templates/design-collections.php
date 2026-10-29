<?php defined( 'ABSPATH' ) || exit; get_header(); ?>
<main id="main-content" class="container standard-page">
	<h1><?php esc_html_e( 'Collections', 'peptide-core' ); ?></h1>
	<?php echo do_shortcode( '[product_categories]' ); ?>
</main>
<?php get_footer(); ?>
