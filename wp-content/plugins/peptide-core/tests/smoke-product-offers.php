<?php
/** Local synthetic SKU/cart/order regression. Memory-only cart session, no gateway or real mail. */
defined( 'ABSPATH' ) || exit;
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() ) { throw new RuntimeException( 'Local WP-CLI only.' ); }
use PeptideCore\Product_Offers as Offers;
use PeptideCore\Promotions;
use PeptideCore\Staff;

if ( ! has_action( 'woocommerce_before_calculate_totals', array( Offers::class, 'sync_cart' ) ) ) { Offers::register_hooks(); }
Offers::register();
$pass = 0; $fail = 0; $ok = static function( string $label, bool $yes ) use ( &$pass, &$fail ): void { $yes ? ++$pass : ++$fail; echo ( $yes ? 'PASS ' : 'FAIL ' ) . $label . "\n"; };
$run = 'pc-offer-synthetic-' . substr( wp_generate_uuid4(), 0, 8 ); $posts = array(); $orders = array(); $users = array(); $warnings = array();
$original = array( WC()->cart, WC()->session, WC()->customer, get_current_user_id() ); $original_cookie = $_COOKIE; $sessions = array();
$no_mail = static fn() => true; add_filter( 'pre_wp_mail', $no_mail, 999 );
$error_handler = static function( $no, $text, $file, $line ) use ( &$warnings ) { if ( str_contains( $file, 'class-product-offers' ) ) { $warnings[] = basename( $file ) . ':' . $line . ' ' . $text; } return false; }; set_error_handler( $error_handler );
$admin = get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'number' => 1 ) ); wp_set_current_user( (int) $admin[0] );
WC()->session = new class extends WC_Session {
	public function __construct() { $this->_customer_id = 'pc-offers-synthetic-memory'; }
	public function has_session() { return true; }
	public function set_customer_session_cookie( $set ) {}
	public function save_data() {}
	public function destroy_session() { $this->_data = array(); }
};
WC()->customer = new WC_Customer( 0, true ); WC()->customer->set_billing_country( 'US' );
if ( $original[0] ) { foreach ( array( 'woocommerce_add_to_cart', 'woocommerce_applied_coupon', 'woocommerce_removed_coupon', 'woocommerce_cart_item_removed', 'woocommerce_cart_item_restored' ) as $hook ) { remove_action( $hook, array( $original[0], 'calculate_totals' ), 20 ); } }
WC()->cart = new WC_Cart(); $cart = WC()->cart;
$reset = static function() use ( $cart ): void { $cart->cart_contents = array(); $cart->removed_cart_contents = array(); $cart->set_applied_coupons( array() ); WC()->session->set( 'cart', array() ); $cart->calculate_totals(); wc_clear_notices(); };
$product = static function( string $suffix, int $price, int $stock = 40 ) use ( $run, &$posts ): int { $p = new WC_Product_Simple(); $p->set_name( $run . '-' . $suffix ); $p->set_status( 'publish' ); $p->set_regular_price( (string) $price ); $p->set_manage_stock( true ); $p->set_stock_quantity( $stock ); $p->set_tax_status( 'none' ); $id = $p->save(); $posts[] = $id; return $id; };
$save = static function( array $raw ) use ( &$posts, $run ): int { $result = Offers::store( 0, array_merge( array( 'title' => $run, 'enabled' => false ), $raw ) ); if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); } $posts[] = $result; return $result; };
$change = static function( int $id, array $patch ): void { $offer = Offers::get( $id ); $result = Offers::store( $id, array_merge( $offer, $patch ) ); if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); } };
$owned = static fn( string $kind ) => array_filter( $cart->get_cart(), static fn( $item ) => $kind === ( $item[ Offers::CART ]['kind'] ?? '' ) );
$http = static function( int $user, string $url, array $args = array() ) use ( &$sessions ) {
	if ( ! isset( $sessions[ $user ] ) ) { $sessions[ $user ] = WP_Session_Tokens::get_instance( $user )->create( time() + 3600 ); }
	$token = $sessions[ $user ]; $args['cookies'] = array( new WP_Http_Cookie( array( 'name' => AUTH_COOKIE, 'value' => wp_generate_auth_cookie( $user, time() + 3600, 'auth', $token ) ) ), new WP_Http_Cookie( array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( $user, time() + 3600, 'logged_in', $token ) ) ) ); $args['timeout'] = 15; $args['redirection'] = 0; return wp_remote_request( $url, $args );
};
try {
	$a = $product( 'paid-a', 30 ); $b = $product( 'paid-b', 20 ); $gift_sku = $product( 'gift', 7, 3 );
	$gift = $save( array( 'kind' => 'gift', 'basis' => 'spend', 'threshold' => 50, 'qualifiers' => array( $a, $b ), 'gift_product' => $gift_sku ) );
	$ok( 'new gift is an inactive draft and cannot alter carts', 'draft' === get_post_status( $gift ) && ! Offers::active( Offers::get( $gift ) ) && '' === Offers::render_form( $gift ) );
	$change( $gift, array( 'enabled' => true ) ); $cart->add_to_cart( $a, 1 ); $cart->calculate_totals(); $ok( 'below-threshold cart receives no gift', ! $owned( 'gift' ) );
	$cart->add_to_cart( $b, 1 ); $cart->calculate_totals(); $gifts = $owned( 'gift' ); $line = reset( $gifts );
	$ok( 'eligible cart receives a separate real Woo SKU with zero price', 1 === count( $gifts ) && $gift_sku === (int) $line['product_id'] && 1 === (int) $line['quantity'] && 0.0 === (float) $line['data']->get_price() );
	$cart->calculate_totals(); $cart->calculate_totals(); $ok( 'repeated total calculation never duplicates gifts', 1 === count( $owned( 'gift' ) ) );
	$change( $gift, array( 'end' => time() - 1 ) ); $cart->calculate_totals(); $ok( 'real gift expiry removes its SKU and hides the public offer', ! $owned( 'gift' ) && '' === Offers::render_form( $gift ) ); $change( $gift, array( 'end' => 0 ) ); $cart->calculate_totals();
	$gkey = array_key_first( $owned( 'gift' ) ); $cart->set_quantity( $gkey, 8 ); $ok( 'buyer cannot increase free gift quantity', 1 === (int) $cart->cart_contents[ $gkey ]['quantity'] );
	foreach ( $cart->get_cart() as $key => $item ) { if ( $b === (int) $item['product_id'] && ! isset( $item[ Offers::CART ] ) ) { $cart->remove_cart_item( $key ); break; } } $cart->calculate_totals(); $ok( 'losing threshold removes the gift immediately', ! $owned( 'gift' ) );
	$p = wc_get_product( $gift_sku ); $p->set_stock_quantity( 0 ); $p->save(); $cart->add_to_cart( $b, 1 ); $cart->calculate_totals(); $ok( 'gift stock exhaustion prevents a free stock promise', ! $owned( 'gift' ) ); $p->set_stock_quantity( 3 ); $p->save();
	$cart->calculate_totals(); $coupon = new WC_Coupon(); $coupon->set_code( $run ); $coupon->set_discount_type( 'percent' ); $coupon->set_amount( 5 ); $posts[] = $coupon->save();
	$cart->apply_coupon( $coupon->get_code() ); $cart->calculate_totals(); $ok( 'choosing a valid coupon replaces the automatic gift', $cart->has_discount( $coupon->get_code() ) && ! $owned( 'gift' ) );
	$reset(); $change( $gift, array( 'enabled' => false ) );
	$slots = array( array( 'label' => 'First SKU', 'qty' => 1, 'choices' => array( $a ) ), array( 'label' => 'Second SKU', 'qty' => 1, 'choices' => array( $b ) ) );
	$bundle = $save( array( 'kind' => 'bundle', 'enabled' => true, 'price_type' => 'fixed', 'price_value' => 40, 'slots' => $slots ) );
	$ok( 'public fixed bundle exposes all required real SKU slots', str_contains( Offers::render_form( $bundle ), 'choices[slot-0]' ) && str_contains( Offers::render_form( $bundle ), 'choices[slot-1]' ) && str_contains( Offers::render_form( $bundle ), 'pc_offer_add' ) );
	$bad = Offers::add_bundle( $bundle, array( 'slot-0' => $gift_sku, 'slot-1' => $b ) ); $ok( 'forged slot SKU is rejected without partial additions', is_wp_error( $bad ) && 0 === count( $cart->get_cart() ) );
	$group = Offers::add_bundle( $bundle, array() ); $ok( 'fixed bundle adds complete native cart lines and server-side price', ! is_wp_error( $group ) && 2 === count( $owned( 'bundle' ) ) && abs( 40 - (float) $cart->get_subtotal() ) < 0.001 && ! Offers::validate_cart( $cart ) );
	$cart->calculate_totals(); $ok( 'repeated totals do not compound the bundle discount', abs( 40 - (float) $cart->get_subtotal() ) < 0.001 );
	$keys = array_keys( $owned( 'bundle' ) ); $cart->set_quantity( $keys[0], 2 ); $cart->calculate_totals(); $ok( 'quantity changes update every slot as complete bundles', 2 === (int) $cart->cart_contents[ $keys[0] ]['quantity'] && 2 === (int) $cart->cart_contents[ $keys[1] ]['quantity'] && abs( 80 - (float) $cart->get_subtotal() ) < 0.001 && ! Offers::validate_cart( $cart ) );
	$cart->apply_coupon( $coupon->get_code() ); $ok( 'coupon application cannot stack with a bundle', ! $cart->has_discount( $coupon->get_code() ) ); wc_clear_notices();
	$cart->remove_cart_item( $keys[0] ); $ok( 'removing one bundle component removes the entire group', ! $owned( 'bundle' ) );
	$cart->restore_cart_item( $keys[0] ); $cart->calculate_totals(); $ok( 'native undo restores the complete group', 2 === count( $owned( 'bundle' ) ) && ! Offers::validate_cart( $cart ) );
	$cart->cart_contents[ $keys[0] ]['quantity'] = 3; $errors = new WP_Error(); Offers::store_api_errors( $errors, $cart ); $ok( 'direct quantity tampering fails native and Store API checkout validation', (bool) Offers::validate_cart( $cart ) && $errors->has_errors() ); $cart->cart_contents[ $keys[0] ]['quantity'] = 2;
	$cart->cart_contents[ $keys[0] ][ Offers::CART ]['offer'] = $gift; $ok( 'changed ownership metadata fails signature validation', (bool) Offers::validate_cart( $cart ) ); $cart->calculate_totals(); $ok( 'invalid signature never keeps a discounted price', 30.0 === (float) $cart->cart_contents[ $keys[0] ]['data']->get_price() );
	$reset(); $forged = $cart->add_to_cart( $a, 1, 0, array(), array( Offers::CART => array( 'kind' => 'gift', 'offer' => $gift ) ) ); $cart->calculate_totals(); $ok( 'external add-to-cart data cannot inject offer or free-price ownership', ! isset( $cart->cart_contents[ $forged ][ Offers::CART ] ) && 30.0 === (float) $cart->cart_contents[ $forged ]['data']->get_price() );
	$reset(); $cart->apply_coupon( $coupon->get_code() ); $ok( 'pre-existing coupon blocks bundle insertion', is_wp_error( Offers::add_bundle( $bundle, array() ) ) && ! $owned( 'bundle' ) ); $reset();
	$group = Offers::add_bundle( $bundle, array() ); $change( $bundle, array( 'enabled' => false ) ); $cart->calculate_totals(); $ok( 'disabled bundle restores native prices and blocks checkout', (bool) Offers::validate_cart( $cart ) && abs( 50 - (float) $cart->get_subtotal() ) < 0.001 ); $reset();
	$change( $bundle, array( 'enabled' => true ) ); Offers::add_bundle( $bundle, array() ); $change( $bundle, array( 'end' => time() - 1 ) ); $cart->calculate_totals(); $ok( 'expired bundle blocks checkout instead of retaining a stale price', (bool) Offers::validate_cart( $cart ) && abs( 50 - (float) $cart->get_subtotal() ) < 0.001 && '' === Offers::render_form( $bundle ) ); $reset(); $change( $bundle, array( 'end' => 0 ) );
	$change( $bundle, array( 'enabled' => true ) ); $p = wc_get_product( $a ); $p->set_stock_quantity( 0 ); $p->save(); $ok( 'bundle with an unavailable component is rejected atomically', is_wp_error( Offers::add_bundle( $bundle, array() ) ) && ! $cart->get_cart() ); $p->set_stock_quantity( 40 ); $p->save();
	$parent = new WC_Product_Variable(); $parent->set_name( $run . '-variable' ); $parent->set_status( 'publish' ); $attribute = new WC_Product_Attribute(); $attribute->set_name( 'Size' ); $attribute->set_options( array( 'Small', 'Large' ) ); $attribute->set_variation( true ); $parent->set_attributes( array( $attribute ) ); $posts[] = $parent->save();
	$variations = array(); foreach ( array( 'Small' => 12, 'Large' => 18 ) as $name => $price ) { $v = new WC_Product_Variation(); $v->set_parent_id( $parent->get_id() ); $v->set_status( 'publish' ); $v->set_attributes( array( 'size' => $name ) ); $v->set_regular_price( (string) $price ); $v->set_manage_stock( true ); $v->set_stock_quantity( 20 ); $v->set_tax_status( 'none' ); $variations[] = $v->save(); $posts[] = $v->get_id(); } WC_Product_Variable::sync( $parent->get_id() );
	$variable = $save( array( 'kind' => 'bundle', 'enabled' => true, 'price_type' => 'percent', 'price_value' => 10, 'slots' => array( array( 'label' => 'Choose real size', 'qty' => 1, 'choices' => $variations ), array( 'label' => 'Fixed companion', 'qty' => 2, 'choices' => array( $b ) ) ) ) );
	$ok( 'variation choice is required when a slot allows several SKUs', is_wp_error( Offers::add_bundle( $variable, array() ) ) );
	$var_group = Offers::add_bundle( $variable, array( 'slot-0' => $variations[1] ) ); $ok( 'variation bundle preserves real variation attributes and percentage price', ! is_wp_error( $var_group ) && abs( 52.2 - (float) $cart->get_subtotal() ) < 0.001 && ! Offers::validate_cart( $cart ) && in_array( $variations[1], array_column( $cart->get_cart(), 'variation_id' ), true ) );
	$items = $owned( 'bundle' ); foreach ( $items as $key => $line ) { if ( 2 === $line['quantity'] ) { $cart->set_quantity( $key, 3 ); $ok( 'non-integral bundle units are rolled back', 2 === (int) $cart->cart_contents[ $key ]['quantity'] ); break; } } wc_clear_notices();
	$order = wc_create_order( array( 'status' => 'pending', 'created_via' => $run ) ); if ( is_wp_error( $order ) || in_array( $order->get_id(), array( 54, 55 ), true ) ) { throw new RuntimeException( 'Order fixture failed.' ); } $orders[] = $order->get_id();
	foreach ( $cart->get_cart() as $key => $values ) { $item = new WC_Order_Item_Product(); $item->set_product( $values['data'] ); $item->set_quantity( $values['quantity'] ); $item->set_subtotal( $values['line_subtotal'] ); $item->set_total( $values['line_total'] ); do_action( 'woocommerce_checkout_create_order_line_item', $item, $key, $values, $order ); $order->add_item( $item ); } $order->calculate_totals( false ); $order->save();
	$order_items = $order->get_items(); $first = reset( $order_items ); $ok( 'native pending order keeps component identities and offer provenance', 2 === count( $order_items ) && (int) $first->get_meta( '_pc_offer_offer' ) === $variable && $first->get_meta( '_pc_offer_group' ) === $var_group && 'pending' === $order->get_status() );
	$reset();
	$cent = $product( 'cent-weight', 1, 100 );
	$small_bundle = $save( array( 'kind' => 'bundle', 'enabled' => true, 'price_type' => 'fixed', 'price_value' => 0.02, 'slots' => array_fill( 0, 4, array( 'label' => 'One real SKU', 'qty' => 1, 'choices' => array( $cent ) ) ) ) );
	$small_group = Offers::add_bundle( $small_bundle, array() );
	$line_values = array_map( static fn( $i ) => (float) $i['data']->get_price() * $i['quantity'], $owned( 'bundle' ) );
	$ok( 'four equal slots split two cents without a negative line or total drift', ! is_wp_error( $small_group ) && min( $line_values ) >= 0 && abs( array_sum( $line_values ) - 0.02 ) < 0.000001 && abs( (float) $cart->get_subtotal() - 0.02 ) < 0.000001 );
	$first_key = array_key_first( $owned( 'bundle' ) ); $cart->set_quantity( $first_key, 3 ); $cart->calculate_totals(); $ok( 'minor-unit allocation remains exact for multiple complete bundles', abs( (float) $cart->get_subtotal() - 0.06 ) < 0.000001 && ! Offers::validate_cart( $cart ) );
	foreach ( array( 0 => 1, 3 => 0.002 ) as $decimals => $value ) {
		$reset(); $precision = static fn() => $decimals; add_filter( 'wc_get_price_decimals', $precision, 100 );
		try { $currency_bundle = $save( array( 'kind' => 'bundle', 'enabled' => true, 'price_type' => 'fixed', 'price_value' => $value, 'slots' => array_fill( 0, 4, array( 'label' => 'Currency slot', 'qty' => 2, 'choices' => array( $cent ) ) ) ) ); $r = Offers::add_bundle( $currency_bundle, array() ); $values = array_map( static fn( $i ) => (float) $i['data']->get_price() * $i['quantity'], $owned( 'bundle' ) ); $ok( 'minor-unit split supports currency precision ' . $decimals, ! is_wp_error( $r ) && min( $values ) >= 0 && abs( array_sum( $values ) - $value ) < 0.0000001 && abs( (float) $cart->get_subtotal() - $value ) < 0.0000001 ); }
		finally { remove_filter( 'wc_get_price_decimals', $precision, 100 ); }
	}
	$reset(); $solo = $product( 'sold-individually', 10 ); $p = wc_get_product( $solo ); $p->set_manage_stock( false ); $p->set_sold_individually( true ); $p->save();
	$solo_bundle = $save( array( 'kind' => 'bundle', 'enabled' => true, 'price_type' => 'fixed', 'price_value' => 15, 'slots' => array( array( 'qty' => 1, 'choices' => array( $solo ) ), array( 'qty' => 1, 'choices' => array( $solo ) ) ) ) );
	$ok( 'unmanaged sold-individually SKU cannot occupy two different slots', is_wp_error( Offers::add_bundle( $solo_bundle, array() ) ) && ! $cart->get_cart() );
	$solo_once = $save( array( 'kind' => 'bundle', 'enabled' => true, 'price_type' => 'fixed', 'price_value' => 25, 'slots' => array( array( 'qty' => 1, 'choices' => array( $solo ) ), array( 'qty' => 1, 'choices' => array( $b ) ) ) ) );
	$cart->add_to_cart( $solo, 1 ); $ok( 'ordinary cart ownership counts against a sold-individually bundle SKU', is_wp_error( Offers::add_bundle( $solo_once, array() ) ) && 1 === count( $cart->get_cart() ) ); $reset();
	Offers::add_bundle( $solo_once, array() ); $ok( 'another bundle group cannot bypass sold-individually ownership', is_wp_error( Offers::add_bundle( $solo_once, array() ) ) && 2 === count( $owned( 'bundle' ) ) ); $reset();
	$solo_gift = $save( array( 'kind' => 'gift', 'enabled' => true, 'basis' => 'quantity', 'threshold' => 1, 'qualifiers' => array( $a ), 'gift_product' => $solo ) ); $cart->add_to_cart( $solo, 1 ); $cart->add_to_cart( $a, 1 ); $cart->calculate_totals(); $ok( 'ordinary ownership also prevents a duplicate sold-individually gift', ! $owned( 'gift' ) ); $change( $solo_gift, array( 'enabled' => false ) ); $reset();
	$p = wc_get_product( $gift_sku ); $p->set_stock_quantity( 1 ); $p->save(); $change( $gift, array( 'enabled' => true ) );
	$replace_bundle = $save( array( 'kind' => 'bundle', 'enabled' => true, 'price_type' => 'fixed', 'price_value' => 25, 'slots' => array( array( 'qty' => 1, 'choices' => array( $gift_sku ) ), array( 'qty' => 1, 'choices' => array( $b ) ) ) ) );
	$cart->add_to_cart( $a, 2 ); $cart->calculate_totals(); $ok( 'replacement fixture has its final stock unit reserved in the gift line', 1 === count( $owned( 'gift' ) ) );
	$r = Offers::add_bundle( $replace_bundle, array() ); $ok( 'bundle replacement releases the old gift SKU before native stock checks', ! is_wp_error( $r ) && ! $owned( 'gift' ) && 2 === count( $owned( 'bundle' ) ) ); $reset();
	$impossible_bundle = $save( array( 'kind' => 'bundle', 'enabled' => true, 'price_type' => 'fixed', 'price_value' => 25, 'slots' => array( array( 'qty' => 2, 'choices' => array( $gift_sku ) ), array( 'qty' => 1, 'choices' => array( $b ) ) ) ) );
	$cart->add_to_cart( $a, 2 ); $cart->calculate_totals(); $r = Offers::add_bundle( $impossible_bundle, array() ); $ok( 'failed replacement restores the still-eligible gift without partial bundle lines', is_wp_error( $r ) && 1 === count( $owned( 'gift' ) ) && ! $owned( 'bundle' ) );
	$gkey = array_key_first( $owned( 'gift' ) ); $cart->cart_contents[ $gkey ]['quantity'] = 2; $ok( 'gift quantity tampering is also rejected at checkout', (bool) Offers::validate_cart( $cart ) ); $cart->calculate_totals(); $ok( 'gift sync repairs the requested quantity to its real entitlement', 1 === (int) $cart->cart_contents[ $gkey ]['quantity'] );
	$change( $gift, array( 'enabled' => false ) );
	$reset(); $promo = wp_insert_post( array( 'post_type' => Promotions::CPT, 'post_status' => 'publish', 'post_title' => $run . '-other-promo' ) ); $posts[] = $promo; update_post_meta( $promo, Promotions::META, Promotions::clean( array( 'type' => 'spend_amount', 'tiers' => array( array( 'min' => 10, 'value' => 1 ) ), 'scope' => 'products', 'products' => array( $a ) ) )[0] );
	$promotion_result = Offers::add_bundle( $bundle, array() );
	$ok( 'existing seven-type promotion ownership prevents double discounting ' . wp_json_encode( array( 'status' => Promotions::status( $promo ), 'active' => in_array( $promo, Promotions::active_ids(), true ), 'owner' => Promotions::owner( wc_get_product( $a ), Promotions::active_ids() ), 'result' => is_wp_error( $promotion_result ) ? $promotion_result->get_error_code() : 'added' ) ), is_wp_error( $promotion_result ) );
	$change( $gift, array( 'enabled' => true ) ); $cart->add_to_cart( $a, 2 ); $cart->calculate_totals(); $ok( 'gift does not stack with an existing promotion', ! $owned( 'gift' ) );
	$uid = wp_insert_user( array( 'user_login' => $run, 'user_pass' => wp_generate_password( 32 ), 'role' => Staff::ROLE ) ); if ( is_wp_error( $uid ) ) { throw new RuntimeException( 'Staff fixture failed.' ); } $users[] = $uid; Staff::set_modules( $uid, array( 'data' ) ); wp_set_current_user( $uid ); Staff::flush_cache();
	$ok( 'data staff cannot create or edit offers', is_wp_error( Offers::store( 0, array() ) ) && is_wp_error( Offers::store( $bundle, array( 'title' => 'Denied' ) ) ) );
	Staff::set_modules( $uid, array( 'marketing' ) ); Staff::flush_cache(); $ok( 'marketing owns the offer capability and submenu scope', current_user_can( Offers::CAP ) && 'marketing' === Staff::module_for( 'admin.php', 'pc-promotions-product-offers' ) );
	$page = admin_url( 'admin.php?page=pc-promotions-product-offers&new=1' ); $response = $http( $uid, $page ); $ok( 'actual marketing editor opens with structured SKU slots and default disabled controls', 200 === wp_remote_retrieve_response_code( $response ) && str_contains( wp_remote_retrieve_body( $response ), 'pc_product_offer_save' ) && str_contains( wp_remote_retrieve_body( $response ), 'data-pc-offer-slot-template' ) );
	$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $uid, time() + 3600, 'logged_in', $sessions[ $uid ] );
	$search_url = add_query_arg( array( 'action' => 'pc_offer_products', 'security' => wp_create_nonce( 'search-products' ), 'term' => $run ), admin_url( 'admin-ajax.php' ) ); $search = $http( $uid, $search_url ); $found = json_decode( wp_remote_retrieve_body( $search ), true );
	$ok( 'actual marketing-only product lookup supports published SKUs without product edit permission', 200 === wp_remote_retrieve_response_code( $search ) && is_array( $found ) && isset( $found[ $a ] ) && ! current_user_can( 'edit_products' ) );
	$save_nonce = wp_create_nonce( 'pc_product_offer_save' ); $saved = $http( $uid, admin_url( 'admin-post.php' ), array( 'method' => 'POST', 'body' => array( 'action' => 'pc_product_offer_save', '_wpnonce' => $save_nonce, 'title' => $run . '-http-draft', 'kind' => 'gift', 'basis' => 'quantity', 'threshold' => 1, 'qualifiers' => array( $a ), 'gift_product' => $gift_sku, 'gift_qty' => 1 ) ) ); parse_str( (string) wp_parse_url( wp_remote_retrieve_header( $saved, 'location' ), PHP_URL_QUERY ), $saved_query ); $http_offer = absint( $saved_query['edit'] ?? 0 ); if ( $http_offer ) { $posts[] = $http_offer; }
	$ok( 'actual admin save creates a disabled draft without affecting real products', 302 === wp_remote_retrieve_response_code( $saved ) && $http_offer && ! Offers::get( $http_offer )['enabled'] );
	$invalid_add = $http( $uid, admin_url( 'admin-post.php' ), array( 'method' => 'POST', 'body' => array( 'action' => 'pc_offer_add', 'offer' => $variable, '_wpnonce' => 'invalid', 'choices' => array( 'slot-0' => $variations[1] ) ) ) ); $ok( 'actual public bundle endpoint rejects an invalid nonce', 403 === wp_remote_retrieve_response_code( $invalid_add ) );
	$valid_add = $http( $uid, admin_url( 'admin-post.php' ), array( 'method' => 'POST', 'body' => array( 'action' => 'pc_offer_add', 'offer' => $variable, '_wpnonce' => wp_create_nonce( 'pc_offer_add_' . $variable ), 'choices' => array( 'slot-0' => $variations[1] ), 'units' => 1, 'price' => 0.01 ) ) );
	$ok( 'actual nonce-protected bundle request ignores client price and redirects to native cart', 302 === wp_remote_retrieve_response_code( $valid_add ) && str_contains( (string) wp_remote_retrieve_header( $valid_add, 'location' ), (string) wp_parse_url( wc_get_cart_url(), PHP_URL_PATH ) ) );
	$stored_session = ( new WC_Session_Handler() )->get_session( (string) $uid, array() ); $stored_cart = maybe_unserialize( $stored_session['cart'] ?? array() ); $stored_totals = maybe_unserialize( $stored_session['cart_totals'] ?? array() );
	$ok( 'actual HTTP cart persists both signed Woo components at server-derived total', is_array( $stored_cart ) && 2 === count( $stored_cart ) && abs( (float) ( $stored_totals['subtotal'] ?? 0 ) - 52.2 ) < 0.000001 && 2 === count( array_filter( $stored_cart, static fn( $i ) => isset( $i[ Offers::CART ]['signature'] ) ) ) );
	foreach ( array( 'data', 'settings' ) as $module ) { Staff::set_modules( $uid, array( $module ) ); Staff::flush_cache(); $read = $http( $uid, $page ); $write = $http( $uid, admin_url( 'admin-post.php' ), array( 'method' => 'POST', 'body' => array( 'action' => 'pc_product_offer_save', '_wpnonce' => $save_nonce, 'title' => 'Denied' ) ) ); $ok( 'actual ' . $module . ' employee cannot read or write offer management', 403 === wp_remote_retrieve_response_code( $read ) && 403 === wp_remote_retrieve_response_code( $write ) ); }
	$ok( 'new offer code emits no PHP warnings', ! $warnings ); if ( $warnings ) { echo implode( "\n", $warnings ) . "\n"; }
} finally {
	$cart->cart_contents = array(); $cart->removed_cart_contents = array(); $cart->set_applied_coupons( array() );
	foreach ( array_reverse( $posts ) as $id ) { if ( 'product' === get_post_type( $id ) || 'product_variation' === get_post_type( $id ) ) { $p = wc_get_product( $id ); if ( $p ) { $p->delete( true ); } } else { wp_delete_post( $id, true ); } }
	foreach ( $orders as $id ) { $order = wc_get_order( $id ); if ( $order ) { $order->delete( true ); } }
	foreach ( $sessions as $id => $token ) { WP_Session_Tokens::get_instance( $id )->destroy( $token ); }
	$session_handler = new WC_Session_Handler(); foreach ( $users as $id ) { $session_handler->delete_session( (string) $id ); }
	if ( class_exists( \PeptideCore\Activity_Log::class ) ) { global $wpdb; $wpdb->delete( \PeptideCore\Activity_Log::table(), array( 'user_login' => $run ) ); }
	require_once ABSPATH . 'wp-admin/includes/user.php'; foreach ( $users as $id ) { wp_delete_user( $id ); }
	[ WC()->cart, WC()->session, WC()->customer, $original_user ] = $original; $_COOKIE = $original_cookie; wp_set_current_user( $original_user ); Staff::flush_cache(); remove_filter( 'pre_wp_mail', $no_mail, 999 ); restore_error_handler();
}
echo "Product offers: $pass passed, $fail failed.\n";
if ( $fail ) { WP_CLI::halt( 1 ); }
