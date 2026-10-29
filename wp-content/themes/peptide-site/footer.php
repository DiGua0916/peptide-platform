<?php defined( 'ABSPATH' ) || exit;
if ( class_exists( '\PeptideCore\Design_Pages' ) && \PeptideCore\Design_Pages::rendering_native() ) { return; }
?>
<?php if ( ! function_exists( 'is_checkout' ) || ( ! is_checkout() && ! is_cart() ) ) : ?><div class="container"><?php vc_use_statement(); ?></div><?php endif; ?>
<?php if ( class_exists( '\PeptideCore\Modules\Site_Design' ) && \PeptideCore\Modules\Site_Design::has_part( 'footer' ) ) :
	echo \PeptideCore\Modules\Site_Design::render_part( 'footer' );
else : ?>
<footer class="site-footer"><div class="container"><div class="footer-top"><a class="brand brand--footer" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="VialCovePeptides home"><?php vc_brand_logo(); ?></a><p>Science in focus.<br>Clarity in every detail.</p><?php get_template_part( 'template-parts/footer-navigation' ); ?></div><div class="footer-bottom"><span>© <?php echo esc_html( wp_date( 'Y' ) ); ?> VialCovePeptides.</span><span>Brand concept preview · Israel / China / United States</span><a href="<?php echo esc_url( home_url( '/#questions' ) ); ?>">Help & information <?php echo vc_icon(); ?></a></div></div></footer>
<?php endif; ?>
<?php wp_footer(); ?></body></html>
