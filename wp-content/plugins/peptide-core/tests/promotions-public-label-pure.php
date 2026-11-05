<?php
/** Actual promotion fee/public DTO label paths, isolated in memory. No WordPress/DB writes. */
declare( strict_types=1 );
namespace PeptideCore {
	final class Customer_Segments {
		public static function matches( $rule, $email ): bool { return true; }
		public static function current_email(): string { return ''; }
	}
}
namespace {
	if ( 'cli' !== PHP_SAPI ) { exit( 2 ); }
	define( 'ABSPATH', __DIR__ );
	$GLOBALS['promo_label_posts'] = array(); $GLOBALS['promo_label_writes'] = 0;
	function get_post_type( $id ) { return $GLOBALS['promo_label_posts'][ $id ]['type'] ?? false; }
	function get_post_status( $id ) { return $GLOBALS['promo_label_posts'][ $id ]['status'] ?? false; }
	function get_post_field( $key, $id ) { return $GLOBALS['promo_label_posts'][ $id ][ $key ] ?? ''; }
	function get_post_meta( $id, $key, $single = false ) {
		if ( \PeptideCore\Promotions::META !== $key ) { throw new \RuntimeException( 'Unexpected metadata read' ); }
		return $GLOBALS['promo_label_posts'][ $id ]['config'] ?? null;
	}
	function get_the_title( $id ) { return $GLOBALS['promo_label_posts'][ $id ]['title'] ?? ''; }
	function get_posts( $args ) {
		return array_keys( array_filter( $GLOBALS['promo_label_posts'], static fn( $p ) => $p['type'] === $args['post_type'] && 'publish' === $p['status'] ) );
	}
	function get_option( $key, $default = false ) { return $default; }
	function __( $text, $domain = '' ) { return $text; }
	function _n( $single, $plural, $number, $domain = '' ) { return 1 === $number ? $single : $plural; }
	function wc_get_price_decimals() { return 2; }
	function wc_price( $number ) { return '$' . number_format( $number, 2, '.', ',' ); }
	function wc_format_localized_decimal( $number ) { return (string) $number; }
	function wc_format_coupon_code( $code ) { return strtolower( trim( $code ) ); }
	function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
	function wp_date( $format, $time ) { return gmdate( $format ?: 'c', $time ); }
	function wp_insert_post( ...$args ) { ++$GLOBALS['promo_label_writes']; throw new \RuntimeException( 'Forbidden post write' ); }
	function wp_update_post( ...$args ) { ++$GLOBALS['promo_label_writes']; throw new \RuntimeException( 'Forbidden post write' ); }
	function update_option( ...$args ) { ++$GLOBALS['promo_label_writes']; throw new \RuntimeException( 'Forbidden option write' ); }
	function update_post_meta( ...$args ) { ++$GLOBALS['promo_label_writes']; throw new \RuntimeException( 'Forbidden metadata write' ); }
	final class WC_Product {
		public function get_id() { return 61; }
		public function get_parent_id() { return 0; }
		public function is_on_sale() { return false; }
	}
	final class Label_Fees {
		public array $rows = array();
		public function add_fee( $args ) { $this->rows[] = $args; }
	}
	final class WC_Cart {
		public Label_Fees $fees;
		public function __construct( public float $subtotal ) { $this->fees = new Label_Fees(); }
		public function get_cart() { return array( 'line-a' => array( 'data' => new WC_Product(), 'quantity' => 1, 'line_subtotal' => $this->subtotal ) ); }
		public function get_applied_coupons() { return array(); }
		public function is_empty() { return false; }
		public function fees_api() { return $this->fees; }
	}
	require dirname( __DIR__ ) . '/includes/autoload.php';
	use PeptideCore\Promotions as P;
	$checks = 0;
	$check = static function ( string $label, bool $result ) use ( &$checks ): void {
		if ( ! $result ) { throw new \RuntimeException( 'FAIL: ' . $label ); }
		++$checks;
	};
	$config = array_replace( P::defaults(), array( 'type' => 'spend_amount', 'public' => true, 'tiers' => array( array( 'min' => 300.0, 'value' => 25.0 ), array( 'min' => 500.0, 'value' => 55.0 ) ) ) );
	$GLOBALS['promo_label_posts'][41] = array( 'title' => '黑色星期五', 'type' => P::CPT, 'status' => 'publish', 'post_password' => '', 'config' => $config );
	$language_state = new \ReflectionProperty( \PeptideCore\Storefront_Translation::class, 'state' );
	$original_language = $language_state->getValue();
	$original_posts = $GLOBALS['promo_label_posts'];
	foreach ( array( 'en' => 'Black Friday', 'es' => 'Viernes Negro' ) as $language => $expected ) {
		$language_state->setValue( null, array_replace( $original_language, array( 'language' => $language ) ) );
		foreach ( array( '黑色星期五', '黑五', ' 黑五 ' ) as $native ) {
			$GLOBALS['promo_label_posts'][41]['title'] = $native;
			$cart = new WC_Cart( 400.0 );
			P::apply_to_cart( $cart );
			$fee = $cart->fees->rows[0] ?? array();
			$check( 'native cart fee uses buyer title in ' . $language, $expected === ( $fee['name'] ?? null ) );
			$check( 'fee identity, amount and tax unchanged', array( 'id' => 'pc-promo-41', 'name' => $expected, 'amount' => -25.0, 'taxable' => false ) === $fee );
			$progress = P::progress( $cart );
			$check( 'current and next tier share buyer title', $expected === $progress['applied']['title'] && $expected === $progress['next']['title'] );
			$check( 'next tier financials unchanged', 100.0 === $progress['next']['remaining'] && 25.0 === $progress['discount'] );
			$check( 'public detail uses buyer title', $expected === P::get_public( 41 )['title'] );
			$check( 'public list uses buyer title', $expected === P::public_list()[0]['title'] );
			$check( 'product promotion uses buyer title', $expected === P::for_product( new WC_Product() )[0]['title'] );
			$check( 'merchant view keeps original title', $native === P::view( 41 )['title'] );
			$check( 'stored title and rules unchanged', $native === $GLOBALS['promo_label_posts'][41]['title'] && $config === $GLOBALS['promo_label_posts'][41]['config'] );
		}
		foreach ( array( 'My Black Friday Special', '自定义黑五活动', 'Viernes Negro', 'Black Friday' ) as $custom ) {
			$GLOBALS['promo_label_posts'][41]['title'] = $custom;
			$cart = new WC_Cart( 400.0 ); P::apply_to_cart( $cart );
			$check( 'custom titles are not globally translated', $custom === $cart->fees->rows[0]['name'] && $custom === P::get_public( 41 )['title'] );
		}
		$low = new WC_Cart( 250.0 ); P::apply_to_cart( $low );
		$check( 'title repair cannot apply an unmet discount', array() === $low->fees->rows && null === P::progress( $low )['applied'] );
	}
	$language_state->setValue( null, $original_language );
	$GLOBALS['promo_label_posts'] = $original_posts;
	$check( 'no configuration, promotion or order writes', 0 === $GLOBALS['promo_label_writes'] );
	echo "RESULT {$checks} PASS / 0 FAIL (isolated actual fee/public paths; not live checkout)\n";
}
