<?php
/** Front-end optimisation item 6: optional CDN URL rewrite (pure; WordPress URL helpers stubbed). */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
	function check( string $label, bool $ok ): void { global $checks; if ( ! $ok ) { fwrite( STDERR, "FAIL: $label\n" ); exit( 1 ); } $checks = ( $checks ?? 0 ) + 1; }
	require dirname( __DIR__ ) . '/includes/class-static-cdn.php';
	use PeptideCore\Static_Cdn as Cdn;
	$site = 'https://shop.example'; $cdn = 'https://d1.cloudfront.net';
	check( 'off by default', '' === Cdn::base() );
	check( 'theme css → cdn', 'https://d1.cloudfront.net/wp-content/themes/peptide-site/assets/site.css?ver=1' === Cdn::rewrite_url( 'https://shop.example/wp-content/themes/peptide-site/assets/site.css?ver=1', $site, $cdn ) );
	check( 'core js → cdn', 'https://d1.cloudfront.net/wp-includes/js/jquery/jquery.min.js?ver=3.7.1' === Cdn::rewrite_url( 'https://shop.example/wp-includes/js/jquery/jquery.min.js?ver=3.7.1', $site, $cdn ) );
	check( 'upload image → cdn', 'https://d1.cloudfront.net/wp-content/uploads/2026/11/a-600x600.png' === Cdn::rewrite_url( 'https://shop.example/wp-content/uploads/2026/11/a-600x600.png', $site, $cdn ) );
	check( 'root-relative → cdn', 'https://d1.cloudfront.net/wp-includes/js/wp-util.min.js' === Cdn::rewrite_url( '/wp-includes/js/wp-util.min.js', $site, $cdn ) );
	check( 'module worker stays same-origin', 'https://shop.example/wp-content/themes/peptide-site/assets/product-report-pdf-worker.mjs' === Cdn::rewrite_url( 'https://shop.example/wp-content/themes/peptide-site/assets/product-report-pdf-worker.mjs', $site, $cdn ) );
	check( 'third-party script untouched', 'https://connect.facebook.net/en_US/fbevents.js' === Cdn::rewrite_url( 'https://connect.facebook.net/en_US/fbevents.js', $site, $cdn ) );
	check( 'REST / pages untouched', 'https://shop.example/wp-json/wc/store/v1/cart' === Cdn::rewrite_url( 'https://shop.example/wp-json/wc/store/v1/cart', $site, $cdn ) && 'https://shop.example/checkout/' === Cdn::rewrite_url( 'https://shop.example/checkout/', $site, $cdn ) );
	check( 'other host with wp-content untouched', 'https://other.example/wp-content/x.css' === Cdn::rewrite_url( 'https://other.example/wp-content/x.css', $site, $cdn ) );
	check( 'cdn off → unchanged', 'https://shop.example/wp-content/a.css' === Cdn::rewrite_url( 'https://shop.example/wp-content/a.css', $site, '' ) );
	define( 'PEPTIDE_CORE_CDN_URL', 'http://insecure.example' );
	check( 'non-https CDN refused', '' === Cdn::base() );
	echo "PASS {$checks} static CDN checks\n";
}
