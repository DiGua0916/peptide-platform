<?php
/**
 * Local-only customer segmentation / points regression. Synthetic customers and orders only.
 * wp eval-file wp-content/plugins/peptide-core/tests/smoke-customer-loyalty.php
 * No gateway calls, refund payments, stock restoration or outgoing emails. Finally deletes only this run's fixtures.
 */
defined( 'ABSPATH' ) || exit;

use PeptideCore\Customer_Segments as Segments;
use PeptideCore\Loyalty;
use PeptideCore\Promotions;
use PeptideCore\Coupon_Rules;
use PeptideCore\Admin\Coupons_Page;
use PeptideCore\Admin\Customers_Page;
use PeptideCore\Admin\Marketing;
use PeptideCore\Admin\Promotions_Page;
use PeptideCore\Admin\Shipping_Discounts_Page;
use PeptideCore\Shipping\Discounts;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) { throw new RuntimeException( 'Local WP-CLI only.' ); }

$pass = 0; $fail = 0;
$ok = static function ( string $label, bool $yes ) use ( &$pass, &$fail ): void { $yes ? ++$pass : ++$fail; echo ( $yes ? 'PASS ' : 'FAIL ' ) . $label . "\n"; };
$token = 'pcl-' . wp_generate_uuid4();
$original_user = get_current_user_id(); $original_get = $_GET; $original_cookie = $_COOKIE;
$users = array(); $orders = array(); $posts = array();
$snapshots = array();
foreach ( array( Segments::OPTION, Loyalty::OPTION ) as $option ) {
	wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' ); wp_cache_delete( $option, 'options' );
	$sentinel = new stdClass(); $value = get_option( $option, $sentinel );
	$snapshots[ $option ] = array( $value !== $sentinel, $value );
}
$no_mail = static fn() => true;
add_filter( 'pre_wp_mail', $no_mail );
$no_async_notice = static fn( $pre, $hook ) => 'pc_loyalty_points_notice' === $hook ? 0 : $pre;
add_filter( 'pre_as_enqueue_async_action', $no_async_notice, 10, 2 );
$original_earn_hook = has_action( 'woocommerce_order_status_processing', array( Loyalty::class, 'earn' ) );
$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID' ) );
if ( ! $admin ) { throw new RuntimeException( 'Administrator required.' ); }
$admin = (int) $admin[0];
$new_order = static function ( int $user, int $product, float $amount = 100 ) use ( &$orders, $token ): WC_Order {
	$o = wc_create_order( array( 'customer_id' => $user, 'status' => 'pending', 'created_via' => $token ) );
	if ( is_wp_error( $o ) || in_array( $o->get_id(), array( 54, 55 ), true ) ) { throw new RuntimeException( 'Fixture creation failed.' ); }
	$orders[] = $o->get_id();
	$o->set_billing_email( '' );
	$o->add_product( wc_get_product( $product ), 1, array( 'subtotal' => $amount, 'total' => $amount ) );
	$o->set_currency( get_woocommerce_currency() );
	$o->calculate_totals( false ); $o->save(); return $o;
};
$paid = static function ( WC_Order $o ): void { $o->set_date_paid( time() ); $o->set_status( 'processing' ); $o->save(); Loyalty::earn( $o->get_id() ); };
$refund = static function ( WC_Order $o, float $amount, bool $allocated = true ) use ( &$orders, $token ) {
	$items = $o->get_items(); $item = reset( $items );
	$r = wc_create_refund( array( 'order_id' => $o->get_id(), 'amount' => $amount, 'reason' => $token, 'refund_payment' => false, 'restock_items' => false, 'line_items' => $allocated ? array( $item->get_id() => array( 'qty' => 0, 'refund_total' => $amount, 'refund_tax' => array() ) ) : array() ) );
	if ( is_wp_error( $r ) ) { throw new RuntimeException( 'Synthetic refund failed.' ); }
	$orders[] = $r->get_id(); return $r;
};
$caught = static function ( callable $work ): bool { try { $work(); return false; } catch ( Exception $e ) { return true; } };

try {
	Loyalty::install(); wp_set_current_user( $admin );
	foreach ( array( 'a', 'b', 'c' ) as $suffix ) {
		$id = wp_insert_user( array( 'user_login' => $token . '-' . $suffix, 'user_email' => $token . '-' . $suffix . '@example.invalid', 'user_pass' => wp_generate_password( 32 ), 'display_name' => 'Synthetic loyalty ' . $suffix, 'role' => 'customer' ) );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Synthetic customer creation failed.' ); } $users[] = (int) $id;
	}
	[ $a, $b, $c ] = $users;
	$ok( 'catalog validates and persists stable tags/levels', ! is_wp_error( Segments::save_catalog( array( 'tags' => array( 'vip' => '测试VIP', 'research' => '测试研究顾客' ), 'levels' => array( 'gold' => '测试金级', 'silver' => '测试银级' ) ) ) ) );
	$ok( 'unknown segment assignment refused', is_wp_error( Segments::set_profile( $a, array( 'missing' ), 'gold' ) ) );
	$ok( 'administrator cannot be assigned as a customer', is_wp_error( Segments::set_profile( $admin, array( 'vip' ), 'gold' ) ) );
	Segments::set_profile( $a, array( 'vip', 'research' ), 'gold' ); Segments::set_profile( $b, array( 'research' ), 'silver' );
	$tag = Segments::clean_rule( array( 'customers' => 'tags', 'segment_tags' => array( 'vip' ) ) )[0];
	$level = Segments::clean_rule( array( 'customers' => 'levels', 'segment_levels' => array( 'gold' ) ) )[0];
	$ok( 'tag and level rules match the stored account, not an entered email', Segments::matches( $tag, '', $a ) && Segments::matches( $level, '', $a ) && ! Segments::matches( $tag, get_userdata( $a )->user_email, 0 ) && ! Segments::matches( $level, '', $b ) );
	$ok( 'empty targeting selection is rejected', (bool) Segments::clean_rule( array( 'customers' => 'tags' ) )[1] );
	unset( $_COOKIE[ PeptideCore\Tracking\Tracker::COOKIE ] ); $_GET['visitor_id'] = 999;
	$ok( 'numeric visitor query cannot impersonate the opaque tracking identity', ! Segments::matches( array( 'customers' => 'visitor', 'visitor_ids' => array( 999 ) ), '', 0 ) );
	$p = new WC_Product_Simple(); $p->set_name( $token ); $p->set_regular_price( '100' ); $p->set_status( 'private' ); $p->set_virtual( true ); $posts[] = $p->save(); $product = $p->get_id();
	$promo = wp_insert_post( array( 'post_type' => Promotions::CPT, 'post_status' => 'publish', 'post_title' => $token ) ); $posts[] = $promo;
	$config = Promotions::clean( array( 'type' => 'spend_amount', 'tiers' => array( array( 'min' => 50, 'value' => 5 ) ), 'scope' => 'products', 'products' => array( $product ), 'customer_rule' => $tag ) )[0];
	update_post_meta( $promo, Promotions::META, $config );
	wp_set_current_user( $a ); $ok( 'eligible customer owns a targeted promotion', $promo === Promotions::owner( $p, array( $promo ) ) );
	wp_set_current_user( $b ); $ok( 'ineligible targeted promotion cannot mask another product owner', 0 === Promotions::owner( $p, array( $promo ) ) );
	[ $ship, $ship_errors ] = Discounts::normalize( array_merge( array( 'name' => $token, 'value' => 1 ), $level ) );
	$ok( 'shipping targeting persists and applies to the correct membership', ! $ship_errors && Discounts::applies_to( $ship, '', $a ) && ! Discounts::applies_to( $ship, '', $b ) );
	[ $shipping_form ] = Shipping_Discounts_Page::from_form( array( 'name' => $token, 'mode' => 'fixed', 'kind' => 'amount', 'value' => 1, 'time_mode' => 'long', 'customers' => 'tags', 'segment_tags' => array( 'vip' ) ) );
	$ok( 'shipping form preserves the targeting selection', array( 'vip' ) === $shipping_form['segment_tags'] );
	[ , $promo_form ] = Promotions_Page::from_form( array( 'name' => $token, 'time_mode' => 'long', 'customers' => 'levels', 'segment_levels' => array( 'gold' ) ) );
	$ok( 'promotion form preserves the targeting selection', array( 'gold' ) === $promo_form['customer_rule']['segment_levels'] );
	wp_set_current_user( $admin );
	[ $coupon_id, $errors ] = Coupons_Page::store( 0, array( 'name' => $token, 'code' => 's' . substr( md5( $token ), 0, 12 ), 'time_mode' => 'long', 'start' => Marketing::local_input( time() - 60 ), 'dtype' => 'fixed_cart', 'amount' => 5, 'customers' => 'tags', 'segment_tags' => array( 'vip' ), 'scope' => 'all', 'public' => 'yes' ) );
	if ( $coupon_id ) { $posts[] = $coupon_id; }
	$ok( 'coupon form saves segments and does not advertise a restricted coupon publicly', ! $errors && 'tags' === get_post_meta( $coupon_id, Segments::COUPON_META, true )['customers'] && 'no' === get_post_meta( $coupon_id, PeptideCore\Coupons::PUBLIC_META, true ) );
	$cart = new WC_Cart(); $discounts = new WC_Discounts( $cart ); $coupon = new WC_Coupon( $coupon_id );
	wp_set_current_user( $a ); $ok( 'coupon validation allows the assigned tag', true === Coupon_Rules::validate( true, $coupon, $discounts ) );
	wp_set_current_user( $b ); $ok( 'coupon validation rejects a different customer segment', $caught( static fn() => Coupon_Rules::validate( true, $coupon, $discounts ) ) );
	wp_set_current_user( $admin );
	Loyalty::save_settings( array( 'earn_per_unit' => 1, 'redeem_per_unit' => 100, 'minimum' => 100, 'expiry_days' => 30 ) );
	$ok( 'points earning and monetary redemption are opt-in', ! Loyalty::settings()['enabled'] && ! Loyalty::settings()['redeem_enabled'] );
	$old = $new_order( $a, $product ); $old->set_date_created( time() - DAY_IN_SECONDS ); $old->save();
	Loyalty::save_settings( array( 'enabled' => 1, 'redeem_enabled' => 1, 'earn_per_unit' => 1, 'redeem_per_unit' => 100, 'minimum' => 100, 'expiry_days' => 30 ) );
	$paid( $old ); $ok( 'orders created before enabling are never backfilled', 0 === Loyalty::account( $a )['balance'] );
	$o = $new_order( $a, $product ); $paid( $o );
	$ok( 'paid merchandise earns points exactly once despite multiple payment hooks', 100 === Loyalty::account( $a )['balance'] );
	Loyalty::earn( $o->get_id() ); $ok( 'payment replay is idempotent', 100 === Loyalty::account( $a )['balance'] );
	$r = $refund( $o, 25 ); Loyalty::refunded( $o->get_id(), $r->get_id() );
	$ok( 'partial refund reverses only its proportion, without replay duplication', 75 === Loyalty::account( $a )['balance'] );
	$refund( wc_get_order( $o->get_id() ), 75 ); $ok( 'full refund reverses the remaining earned points', 0 === Loyalty::account( $a )['balance'] && 0 === Loyalty::account( $a )['debt'] );
	$o2 = $new_order( $a, $product ); $paid( $o2 ); wp_set_current_user( $a );
	$key = wp_generate_uuid4(); $voucher = Loyalty::redeem( $a, 100, $key ); if ( is_wp_error( $voucher ) ) { throw new RuntimeException( 'Synthetic redemption failed: ' . $voucher->get_error_code() ); } $posts[] = $voucher->get_id();
	$again = Loyalty::redeem( $a, 500, $key );
	$ok( 'redemption reserves once and retry uses original cost/value', ! is_wp_error( $again ) && $again->get_id() === $voucher->get_id() && 0 === Loyalty::account( $a )['balance'] && 1.0 === (float) $again->get_amount() );
	$ok( 'insufficient balance is rejected without a negative balance', is_wp_error( Loyalty::redeem( $a, 100, wp_generate_uuid4() ) ) && 0 === Loyalty::account( $a )['balance'] );
	wp_set_current_user( $b ); $ok( 'another account cannot spend or use this customer points coupon', is_wp_error( Loyalty::redeem( $a, 100, wp_generate_uuid4() ) ) && $caught( static fn() => Loyalty::validate_coupon( true, $voucher ) ) );
	wp_set_current_user( $a ); $refund( $o2, 100 );
	$ok( 'spent earning refund becomes recoverable debt, never a negative available balance', 0 === Loyalty::account( $a )['balance'] && 100 === Loyalty::account( $a )['debt'] );
	Loyalty::release_coupon( $voucher->get_id(), $a ); Loyalty::release_coupon( $voucher->get_id(), $a );
	$ok( 'unused-coupon return pays the debt once', 0 === Loyalty::account( $a )['balance'] && 0 === Loyalty::account( $a )['debt'] );
	$ok( 'a returned coupon can no longer be used', $caught( static fn() => Loyalty::validate_coupon( true, new WC_Coupon( $voucher->get_id() ) ) ) );
	$ok( 'customer cannot make an administrative points adjustment', is_wp_error( Loyalty::adjust( $a, 200, 'forbidden', wp_generate_uuid4() ) ) );
	wp_set_current_user( $admin ); $adjust = wp_generate_uuid4(); Loyalty::adjust( $a, 2000, 'Synthetic starting balance', $adjust ); Loyalty::adjust( $a, 2000, 'Repeated synthetic request', $adjust );
	$ok( 'manual adjustment request cannot be replayed', 2000 === Loyalty::account( $a )['balance'] );
	$ok( 'manual subtraction cannot overdraw the account', is_wp_error( Loyalty::adjust( $a, -2001, 'Synthetic excessive subtraction', wp_generate_uuid4() ) ) && 2000 === Loyalty::account( $a )['balance'] );
	// Failure after the balance write must roll the whole account transaction back.
	global $wpdb; $ledger_table = Loyalty::table( 'ledger' );
	$bad_insert = static function ( $sql ) use ( $ledger_table ) { return str_contains( $sql, 'INSERT INTO `' . $ledger_table . '`' ) ? 'INSERT INTO pc_intentionally_missing_loyalty_test_table VALUES (1)' : $sql; };
	$previous_suppression = $wpdb->suppress_errors( true ); add_filter( 'query', $bad_insert );
	$failed = Loyalty::adjust( $a, 7, 'Synthetic rollback check', wp_generate_uuid4() ); remove_filter( 'query', $bad_insert ); $wpdb->suppress_errors( $previous_suppression );
	$ok( 'ledger insertion failure rolls back the preceding balance update', is_wp_error( $failed ) && 2000 === Loyalty::account( $a )['balance'] );
	wp_set_current_user( $a );
	$save_failure = static function ( $object ) use ( $a ) { if ( $object instanceof WC_Coupon && (int) $object->get_meta( '_pc_loyalty_user' ) === $a ) { throw new RuntimeException( 'Synthetic coupon failure' ); } };
	add_action( 'woocommerce_before_data_object_save', $save_failure );
	$failed_coupon = Loyalty::redeem( $a, 100, wp_generate_uuid4() ); remove_action( 'woocommerce_before_data_object_save', $save_failure );
	$ok( 'coupon business failure is compensated with an auditable points return', is_wp_error( $failed_coupon ) && 2000 === Loyalty::account( $a )['balance'] );
	$v2 = Loyalty::redeem( $a, 1000, wp_generate_uuid4() ); if ( is_wp_error( $v2 ) ) { throw new RuntimeException( 'Second synthetic redemption failed.' ); } $posts[] = $v2->get_id();
	$o3 = $new_order( $a, $product ); $applied = $o3->apply_coupon( $v2 ); $o3->save(); Loyalty::link_coupons( $o3 ); $paid( $o3 );
	$ok( 'personal coupon binds to one order and deducts its real WooCommerce amount', ! is_wp_error( $applied ) && 90.0 === (float) $o3->get_total() && 1090 === Loyalty::account( $a )['balance'] );
	$ok( 'bound coupon cannot be cancelled directly or assigned to another order', is_wp_error( Loyalty::release_coupon( $v2->get_id(), $a ) ) && $caught( static fn() => Loyalty::validate_coupon( true, new WC_Coupon( $v2->get_id() ) ) ) );
	$r3 = $refund( $o3, 45, false ); Loyalty::refunded( $o3->get_id(), $r3->get_id() );
	$ok( 'amount-only partial order refund reverses earnings and returns proportional redeemed points once', 1545 === Loyalty::account( $a )['balance'] );
	$refund( wc_get_order( $o3->get_id() ), 45 );
	$ok( 'final refund restores all redeemed points and removes all earned points', 2000 === Loyalty::account( $a )['balance'] && 0 === Loyalty::account( $a )['debt'] );
	$v3 = Loyalty::redeem( $a, 100, wp_generate_uuid4() ); if ( is_wp_error( $v3 ) ) { throw new RuntimeException( 'Cancellation fixture redemption failed.' ); } $posts[] = $v3->get_id();
	$o4 = $new_order( $a, $product ); $o4->apply_coupon( $v3 ); $o4->save(); Loyalty::link_coupons( $o4 ); $o4->update_status( 'cancelled' ); Loyalty::cancelled( $o4->get_id() );
	$ok( 'unpaid cancelled order returns reserved points once without earning points', 2000 === Loyalty::account( $a )['balance'] );
	$v4 = Loyalty::redeem( $a, 100, wp_generate_uuid4() ); if ( is_wp_error( $v4 ) ) { throw new RuntimeException( 'Expiry fixture redemption failed.' ); } $posts[] = $v4->get_id(); $v4->set_date_expires( time() - 60 ); $v4->save(); Loyalty::expire_coupon( $v4->get_id() ); Loyalty::expire_coupon( $v4->get_id() );
	$ok( 'expired unused voucher returns points only once', 2000 === Loyalty::account( $a )['balance'] );
	// Saving the returned flag fails after the financial return; the ledger must still deny this coupon.
	$v5 = Loyalty::redeem( $a, 100, wp_generate_uuid4() ); if ( is_wp_error( $v5 ) ) { throw new RuntimeException( 'Release failure fixture failed.' ); } $posts[] = $v5->get_id();
	$release_failure = static function ( $object ) use ( $v5 ) { if ( $object instanceof WC_Coupon && $object->get_id() === $v5->get_id() ) { throw new RuntimeException( 'Synthetic release flag failure' ); } };
	add_action( 'woocommerce_before_data_object_save', $release_failure );
	Loyalty::release_coupon( $v5->get_id(), $a ); remove_action( 'woocommerce_before_data_object_save', $release_failure );
	$ok( 'returned ledger denies a coupon even when its released flag failed to save', 2000 === Loyalty::account( $a )['balance'] && 'yes' !== get_post_meta( $v5->get_id(), '_pc_loyalty_released', true ) && $caught( static fn() => Loyalty::validate_coupon( true, new WC_Coupon( $v5->get_id() ) ) ) );
	$blocked_order = $new_order( $a, $product ); $ci = new WC_Order_Item_Coupon(); $ci->set_code( $v5->get_code() ); $ci->set_discount( 1 ); $blocked_order->add_item( $ci ); $blocked_order->save();
	$ok( 'checkout binding also rejects a returned coupon with stale metadata', $caught( static fn() => Loyalty::link_coupons( $blocked_order ) ) );
	Loyalty::release_coupon( $v5->get_id(), $a );
	$ok( 'release retry repairs the flag without returning points twice', 'yes' === get_post_meta( $v5->get_id(), '_pc_loyalty_released', true ) && 2000 === Loyalty::account( $a )['balance'] );
	// Re-delivery of a payment callback after cancellation, with deliberately stale Woo order cache.
	$delayed = $new_order( $a, $product );
	remove_action( 'woocommerce_order_status_processing', array( Loyalty::class, 'earn' ), 20 );
	$delayed->set_date_paid( time() ); $delayed->set_status( 'processing' ); $delayed->save(); $stale_paid = clone $delayed;
	$delayed->update_status( 'cancelled' ); add_action( 'woocommerce_order_status_processing', array( Loyalty::class, 'earn' ), 20 );
	wc_get_container()->get( Automattic\WooCommerce\Caches\OrderCache::class )->set( $stale_paid, $delayed->get_id() );
	Loyalty::earn( $delayed->get_id() );
	$ok( 'delayed payment callback re-reads cancelled state under the lifecycle lock', 2000 === Loyalty::account( $a )['balance'] );
	foreach ( array( true, false ) as $allocated ) {
		$late = $new_order( $b, $product );
		remove_action( 'woocommerce_order_status_processing', array( Loyalty::class, 'earn' ), 20 );
		$late->set_date_paid( time() ); $late->set_status( 'processing' ); $late->save();
		$refund( $late, 25, $allocated );
		add_action( 'woocommerce_order_status_processing', array( Loyalty::class, 'earn' ), 20 );
		$kind = $allocated ? 'itemized' : 'amount-only';
		$ok( $kind . ' partial refund can arrive before the first earning callback', 0 === Loyalty::account( $b )['balance'] && wc_get_order( $late->get_id() )->is_paid() );
		Loyalty::earn( $late->get_id() ); Loyalty::earn( $late->get_id() );
		$entries = array_filter( Loyalty::ledger( $b, 100 ), static fn( $r ) => (int) $r['order_id'] === $late->get_id() );
		$earned = array_sum( array_map( static fn( $r ) => 'earned' === $r['kind'] ? (int) $r['delta'] : 0, $entries ) );
		$reversed = array_sum( array_map( static fn( $r ) => 'earn_reversal' === $r['kind'] ? (int) $r['delta'] : 0, $entries ) );
		$ok( $kind . ' late/repeated earning keeps gross snapshot but reconciles the earlier refund once', 75 === Loyalty::account( $b )['balance'] && 100 === $earned && -25 === $reversed );
		$refund( wc_get_order( $late->get_id() ), 25, $allocated );
		$ok( $kind . ' later partial refund applies only the incremental reversal', 50 === Loyalty::account( $b )['balance'] );
		$refund( wc_get_order( $late->get_id() ), 50, $allocated );
		$ok( $kind . ' eventual full refund leaves no extra earned points or debt', 0 === Loyalty::account( $b )['balance'] && 0 === Loyalty::account( $b )['debt'] );
	}
	// Coupon identity survives rule edits/deletion without creating a second free coupon or a second refund.
	$old_request = (string) $v2->get_meta( '_pc_loyalty_request' ); wp_delete_post( $v2->get_id(), true );
	$ok( 'replaying a processed redemption cannot recreate a deleted used voucher or refund twice', is_wp_error( Loyalty::redeem( $a, 1000, $old_request ) ) && 2000 === Loyalty::account( $a )['balance'] );
	wp_set_current_user( $admin ); Loyalty::save_settings( array_merge( Loyalty::settings(), array( 'redeem_per_unit' => 1000, 'minimum' => 5 ) ) ); wp_set_current_user( $a );
	$ok( 'fractional minor-unit redemption is rejected rather than rounded up', 10 === Loyalty::redemption_step() && is_wp_error( Loyalty::redeem( $a, 5, wp_generate_uuid4() ) ) && 2000 === Loyalty::account( $a )['balance'] );
	$tiny = Loyalty::redeem( $a, 10, wp_generate_uuid4() ); if ( is_wp_error( $tiny ) ) { throw new RuntimeException( 'Exact minor-unit fixture failed.' ); } $posts[] = $tiny->get_id();
	$ok( 'exact minor-unit redemption charges the exact configured points cost', 0.01 === (float) $tiny->get_amount() && 1990 === Loyalty::account( $a )['balance'] ); Loyalty::release_coupon( $tiny->get_id(), $a );
	wp_set_current_user( $admin ); Loyalty::save_settings( array_merge( Loyalty::settings(), array( 'redeem_per_unit' => 100, 'minimum' => 100 ) ) );
	wp_set_current_user( $admin ); $_GET = array( 'page' => Customers_Page::SLUG, 'customer_id' => $a ); ob_start(); Customers_Page::render(); $html = ob_get_clean();
	$ok( 'customer operations renders editable segments and auditable points history', str_contains( $html, 'pc_customer_save' ) && str_contains( $html, 'pc_customer_points' ) && str_contains( $html, '积分流水' ) );
	wp_set_current_user( $a ); $_GET = array(); ob_start(); Loyalty::account_page(); $html = ob_get_clean();
	$ok( 'My account exposes the owner points history and an actual redemption form', str_contains( $html, 'pc_points_redeem' ) && str_contains( $html, 'pc_points_release' ) === false && str_contains( $html, '2000' ) );
	$ok( 'account ledger events retain non-negative balance/debt and auditable actors', ! array_filter( Loyalty::ledger( $a, 100 ), static fn( $r ) => (int) $r['balance'] < 0 || (int) $r['debt'] < 0 || '' === $r['event_key'] ) );
	wp_set_current_user( $admin );
	Loyalty::save_settings( array_merge( Loyalty::settings(), array( 'notify_enabled' => true ) ) );
	$notice = Loyalty::adjust( $c, 5, 'Synthetic notification check', wp_generate_uuid4() );
	$mail_count = 0;
	$count_mail = static function ( $pre ) use ( &$mail_count ) { ++$mail_count; return true; };
	add_filter( 'pre_wp_mail', $count_mail, 20 );
	Loyalty::send_notice( $notice['id'] ); Loyalty::send_notice( $notice['id'] );
	remove_filter( 'pre_wp_mail', $count_mail, 20 );
	$ok( 'points email worker claims each event once; no outgoing email is sent in this test', 1 === $mail_count && 'sent' === Loyalty::ledger( $c, 1 )[0]['notice_status'] );
} finally {
	wp_set_current_user( $admin );
	if ( isset( $bad_insert ) ) { remove_filter( 'query', $bad_insert ); }
	if ( isset( $save_failure ) ) { remove_action( 'woocommerce_before_data_object_save', $save_failure ); }
	if ( isset( $release_failure ) ) { remove_action( 'woocommerce_before_data_object_save', $release_failure ); }
	if ( false !== $original_earn_hook && false === has_action( 'woocommerce_order_status_processing', array( Loyalty::class, 'earn' ) ) ) { add_action( 'woocommerce_order_status_processing', array( Loyalty::class, 'earn' ), (int) $original_earn_hook ); }
	foreach ( array_reverse( array_unique( $orders ) ) as $id ) { if ( ! in_array( (int) $id, array( 54, 55 ), true ) ) { $o = wc_get_order( $id ); if ( $o ) { $o->delete( true ); } } }
	foreach ( $users as $id ) {
		foreach ( get_posts( array( 'post_type' => 'shop_coupon', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_pc_loyalty_user', 'meta_value' => $id ) ) as $cid ) { wp_clear_scheduled_hook( 'pc_loyalty_expire_coupon', array( (int) $cid ) ); $posts[] = $cid; }
		global $wpdb; $wpdb->delete( Loyalty::table( 'ledger' ), array( 'user_id' => $id ) ); $wpdb->delete( Loyalty::table( 'accounts' ), array( 'user_id' => $id ) );
		require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $id );
	}
	foreach ( array_unique( $posts ) as $id ) { if ( 'product' === get_post_type( $id ) ) { $p = wc_get_product( $id ); if ( $p ) { $p->delete( true ); } } else { wp_delete_post( $id, true ); } }
	foreach ( $snapshots as $option => [ $existed, $value ] ) {
		wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' ); wp_cache_delete( $option, 'options' );
		$existed ? update_option( $option, $value, false ) : delete_option( $option );
	}
	remove_filter( 'pre_wp_mail', $no_mail ); remove_filter( 'pre_as_enqueue_async_action', $no_async_notice, 10 ); wp_set_current_user( $original_user ); $_GET = $original_get; $_COOKIE = $original_cookie;
}
$remaining = 0;
foreach ( $users as $id ) {
	if ( get_userdata( $id ) ) { ++$remaining; }
	foreach ( array( 'accounts', 'ledger' ) as $kind ) { $t = Loyalty::table( $kind ); $remaining += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE user_id=%d", $id ) ); }
}
foreach ( array_unique( $orders ) as $id ) { if ( wc_get_order( $id ) ) { ++$remaining; } }
foreach ( array_unique( $posts ) as $id ) { if ( get_post( $id ) ) { ++$remaining; } }
$restored = true;
foreach ( $snapshots as $option => [ $existed, $value ] ) { wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' ); wp_cache_delete( $option, 'options' ); $restored = $restored && ( $existed ? get_option( $option ) == $value : false === get_option( $option, false ) ); }
$ok( 'cleanup removed only this runs customers/orders/coupons/product/ledger rows and restored both options', 0 === $remaining && $restored );
echo "RESULT {$pass} passed, {$fail} failed\n";
if ( $fail ) { throw new RuntimeException( 'Customer/loyalty regression failed.' ); }
