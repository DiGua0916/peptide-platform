<?php
/** Independent real-SKU gifts and component bundles; WooCommerce owns every cart/order line. */
namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Product_Offers {
	public const CPT = 'pc_product_offer';
	public const META = '_pc_product_offer';
	public const CART = '_pc_offer';
	public const CAP = 'edit_shop_coupons';
	private static bool $busy = false;
	private static ?array $adding = null;

	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'register' ) );
		add_shortcode( 'pc_product_offer', array( self::class, 'shortcode' ) );
		add_action( 'woocommerce_single_product_summary', array( self::class, 'product_forms' ), 36 );
		add_action( 'admin_post_pc_offer_add', array( self::class, 'add_action' ) );
		add_action( 'admin_post_nopriv_pc_offer_add', array( self::class, 'add_action' ) );
		add_filter( 'woocommerce_add_cart_item_data', array( self::class, 'cart_data' ), 1000, 4 );
		add_action( 'woocommerce_before_calculate_totals', array( self::class, 'sync_cart' ), 100 );
		add_action( 'woocommerce_after_cart_item_quantity_update', array( self::class, 'quantity_changed' ), 10, 4 );
		add_action( 'woocommerce_remove_cart_item', array( self::class, 'remove_group' ), 10, 2 );
		add_action( 'woocommerce_cart_item_restored', array( self::class, 'restore_group' ), 10, 2 );
		add_filter( 'woocommerce_coupon_is_valid', array( self::class, 'coupon_valid' ), 30, 3 );
		add_filter( 'woocommerce_coupon_is_valid_for_product', array( self::class, 'coupon_product_valid' ), 30, 4 );
		add_action( 'woocommerce_check_cart_items', array( self::class, 'check_cart' ) );
		add_action( 'woocommerce_store_api_cart_errors', array( self::class, 'store_api_errors' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( self::class, 'order_item' ), 10, 4 );
		add_filter( 'woocommerce_get_item_data', array( self::class, 'item_data' ), 10, 2 );
	}
	public static function register(): void {
		register_post_type( self::CPT, array( 'label' => __( 'Product offers', 'peptide-core' ), 'public' => false, 'show_ui' => false, 'show_in_rest' => false, 'supports' => array( 'title' ), 'capability_type' => 'shop_coupon', 'map_meta_cap' => true ) );
	}
	private static function error( string $code, string $message ): \WP_Error { return new \WP_Error( $code, $message ); }
	public static function policy(): string { return __( 'Gifts and bundles do not combine with coupons or other promotions. Only one gift offer applies per cart. Adding a bundle replaces any automatic gift.', 'peptide-core' ); }
	public static function defaults(): array { return array( 'kind' => 'gift', 'enabled' => false, 'start' => 0, 'end' => 0, 'basis' => 'spend', 'threshold' => 100, 'qualifiers' => array(), 'gift_product' => 0, 'gift_qty' => 1, 'price_type' => 'fixed', 'price_value' => 0, 'slots' => array() ); }
	private static function product( int $id ): ?\WC_Product {
		$p = wc_get_product( $id );
		if ( ! $p || ! in_array( $p->get_type(), array( 'simple', 'variation' ), true ) || 'publish' !== $p->get_status() || ! $p->is_purchasable() ) { return null; }
		if ( $p->get_parent_id() && 'publish' !== get_post_status( $p->get_parent_id() ) ) { return null; }
		return $p;
	}
	public static function get( int $id ): ?array {
		if ( self::CPT !== get_post_type( $id ) || 'trash' === get_post_status( $id ) ) { return null; }
		return array_merge( self::defaults(), (array) get_post_meta( $id, self::META, true ), array( 'id' => $id, 'title' => get_the_title( $id ) ) );
	}
	public static function active( array $offer ): bool { return $offer['enabled'] && 'publish' === get_post_status( $offer['id'] ) && ( ! $offer['start'] || time() >= $offer['start'] ) && ( ! $offer['end'] || time() < $offer['end'] ); }
	public static function all( bool $active = false ): array {
		$out = array();
		foreach ( get_posts( array( 'post_type' => self::CPT, 'post_status' => array( 'publish', 'draft' ), 'numberposts' => 200, 'orderby' => 'ID', 'order' => 'DESC' ) ) as $post ) { $row = self::get( $post->ID ); if ( $row && ( ! $active || self::active( $row ) ) ) { $out[ $post->ID ] = $row; } }
		return $out;
	}
	public static function store( int $id, array $raw ) {
		if ( ! current_user_can( self::CAP ) ) { return self::error( 'pc_offer_forbidden', __( 'You cannot manage product offers.', 'peptide-core' ) ); }
		if ( $id && ! self::get( $id ) ) { return self::error( 'pc_offer_missing', __( 'Product offer not found.', 'peptide-core' ) ); }
		$c = self::defaults(); $title = mb_substr( sanitize_text_field( $raw['title'] ?? '' ), 0, 160 );
		$c['kind'] = in_array( $raw['kind'] ?? '', array( 'gift', 'bundle' ), true ) ? $raw['kind'] : 'gift';
		$c['enabled'] = ! empty( $raw['enabled'] ); $c['start'] = max( 0, (int) ( $raw['start'] ?? 0 ) ); $c['end'] = max( 0, (int) ( $raw['end'] ?? 0 ) );
		if ( ! $title || ( $c['end'] && $c['start'] && $c['end'] <= $c['start'] ) ) { return self::error( 'pc_offer_dates', __( 'Enter a name and valid start/end times.', 'peptide-core' ) ); }
		if ( 'gift' === $c['kind'] ) {
			$c['basis'] = 'quantity' === ( $raw['basis'] ?? '' ) ? 'quantity' : 'spend'; $c['threshold'] = (float) ( $raw['threshold'] ?? 0 ); $c['gift_qty'] = absint( $raw['gift_qty'] ?? 1 ); $c['gift_product'] = absint( $raw['gift_product'] ?? 0 );
			$c['qualifiers'] = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $raw['qualifiers'] ?? array() ) ) ) ) );
			if ( ! is_finite( $c['threshold'] ) || $c['threshold'] <= 0 || $c['threshold'] > 10000000 || ( 'quantity' === $c['basis'] && floor( $c['threshold'] ) !== $c['threshold'] ) || $c['gift_qty'] < 1 || $c['gift_qty'] > 10 || ! self::product( $c['gift_product'] ) ) { return self::error( 'pc_offer_gift', __( 'Choose a published, purchasable gift SKU, quantity 1–10 and a positive threshold.', 'peptide-core' ) ); }
			if ( self::product( $c['gift_product'] )->is_sold_individually() && $c['gift_qty'] > 1 ) { return self::error( 'pc_offer_gift', __( 'A sold-individually gift can only have quantity one.', 'peptide-core' ) ); }
			foreach ( $c['qualifiers'] as $pid ) { $p = wc_get_product( $pid ); if ( ! $p || 'publish' !== $p->get_status() ) { return self::error( 'pc_offer_products', __( 'Choose only published products or variations.', 'peptide-core' ) ); } }
		} else {
			$c['price_type'] = 'percent' === ( $raw['price_type'] ?? '' ) ? 'percent' : 'fixed'; $c['price_value'] = (float) ( $raw['price_value'] ?? 0 );
			if ( ! is_finite( $c['price_value'] ) || $c['price_value'] <= 0 || ( 'percent' === $c['price_type'] && $c['price_value'] >= 100 ) || $c['price_value'] > 10000000 ) { return self::error( 'pc_offer_price', __( 'Enter a positive bundle price or a discount percentage below 100.', 'peptide-core' ) ); }
			foreach ( array_slice( (array) ( $raw['slots'] ?? array() ), 0, 12 ) as $i => $slot ) {
				$choices = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $slot['choices'] ?? array() ) ) ) ) ); $qty = absint( $slot['qty'] ?? 1 );
				if ( ! $choices && empty( $slot['label'] ) ) { continue; }
				if ( ! $choices || count( $choices ) > 30 || $qty < 1 || $qty > 10 ) { return self::error( 'pc_offer_slots', __( 'Each bundle slot needs 1–30 real SKU choices and quantity 1–10.', 'peptide-core' ) ); }
				foreach ( $choices as $pid ) { if ( ! self::product( $pid ) ) { return self::error( 'pc_offer_products', __( 'Choose only published products or variations.', 'peptide-core' ) ); } }
				$c['slots'][] = array( 'id' => 'slot-' . count( $c['slots'] ), 'label' => mb_substr( sanitize_text_field( $slot['label'] ?? '' ), 0, 100 ), 'qty' => $qty, 'choices' => $choices );
			}
			if ( count( $c['slots'] ) < 2 ) { return self::error( 'pc_offer_slots', __( 'A bundle needs at least two complete slots.', 'peptide-core' ) ); }
		}
		$post = array( 'post_type' => self::CPT, 'post_status' => $c['enabled'] ? 'publish' : 'draft', 'post_title' => $title ); if ( $id ) { $post['ID'] = $id; }
		$saved = wp_insert_post( $post, true ); if ( is_wp_error( $saved ) ) { return $saved; } update_post_meta( $saved, self::META, $c ); return (int) $saved;
	}
	private static function version( array $offer ): string { unset( $offer['title'] ); return hash( 'sha256', wp_json_encode( $offer ) ); }
	private static function signature( array $meta, int $product ): string { unset( $meta['signature'] ); return hash_hmac( 'sha256', wp_json_encode( $meta ) . '|' . $product, wp_salt( 'auth' ) ); }
	private static function valid_meta( array $item ): bool {
		$m = $item[ self::CART ] ?? null; if ( ! is_array( $m ) || ! isset( $m['offer'], $m['kind'], $m['group'], $m['slot'], $m['version'], $m['signature'] ) ) { return false; }
		$pid = (int) ( $item['variation_id'] ?: $item['product_id'] ); return is_string( $m['signature'] ) && hash_equals( self::signature( $m, $pid ), $m['signature'] );
	}
	public static function cart_data( array $data, int $product, int $variation = 0, $qty = 1 ): array {
		unset( $data[ self::CART ] );
		if ( self::$adding && (int) self::$adding['product'] === ( $variation ?: $product ) ) { $data[ self::CART ] = self::$adding['meta']; }
		return $data;
	}
	private static function meta( array $offer, int $product, string $group, string $slot ): array {
		$m = array( 'offer' => $offer['id'], 'kind' => $offer['kind'], 'group' => $group, 'slot' => $slot, 'version' => self::version( $offer ) ); $m['signature'] = self::signature( $m, $product ); return $m;
	}
	private static function add_line( \WC_Cart $cart, array $offer, int $pid, int $qty, string $group, string $slot ) {
		$p = self::product( $pid ); if ( ! $p ) { return false; }
		self::$adding = array( 'product' => $pid, 'meta' => self::meta( $offer, $pid, $group, $slot ) );
		try { return $cart->add_to_cart( $p->get_parent_id() ?: $pid, $qty, $p->get_parent_id() ? $pid : 0, $p->is_type( 'variation' ) ? $p->get_variation_attributes() : array() ); }
		finally { self::$adding = null; }
	}
	private static function conflict( \WC_Product $product ): bool { return (bool) Promotions::owner( $product, Promotions::active_ids() ); }
	/** Stock is aggregated by its native managed-by product, including ordinary lines already in the cart. */
	private static function stock_ok( array $selections, \WC_Cart $cart, string $ignore_group = '' ): bool {
		$need = array(); $products = array(); $quantities = array();
		foreach ( $selections as $selection ) {
			$p = self::product( $selection['product'] ); if ( ! $p || ! $p->is_in_stock() || $p->is_on_backorder( $selection['qty'] ) || ( $p->is_sold_individually() && $selection['qty'] > 1 ) ) { return false; }
			$products[ $p->get_id() ] = $p; $quantities[ $p->get_id() ] = ( $quantities[ $p->get_id() ] ?? 0 ) + $selection['qty'];
			if ( $p->managing_stock() ) { $id = $p->get_stock_managed_by_id(); $need[ $id ] = ( $need[ $id ] ?? 0 ) + $selection['qty']; }
		}
		foreach ( $cart->get_cart() as $item ) {
			if ( $ignore_group && ( $item[ self::CART ]['group'] ?? '' ) === $ignore_group ) { continue; }
			$p = $item['data']; if ( isset( $quantities[ $p->get_id() ] ) ) { $quantities[ $p->get_id() ] += (int) $item['quantity']; } if ( $p->managing_stock() && isset( $need[ $p->get_stock_managed_by_id() ] ) ) { $need[ $p->get_stock_managed_by_id() ] += (int) $item['quantity']; }
		}
		foreach ( $quantities as $id => $qty ) { if ( $products[ $id ]->is_sold_individually() && $qty > 1 ) { return false; } }
		foreach ( $need as $id => $qty ) { $p = wc_get_product( $id ); if ( ! $p || ! $p->has_enough_stock( $qty ) || $p->is_on_backorder( $qty ) || ( $p->is_sold_individually() && $qty > 1 ) ) { return false; } }
		return true;
	}
	private static function selections( array $offer, array $choices, int $units ) {
		if ( $units < 1 || $units > 10 ) { return self::error( 'pc_offer_qty', __( 'Choose 1–10 complete bundles.', 'peptide-core' ) ); }
		$out = array();
		foreach ( $offer['slots'] as $slot ) {
			$pid = absint( $choices[ $slot['id'] ] ?? ( 1 === count( $slot['choices'] ) ? $slot['choices'][0] : 0 ) );
			if ( ! in_array( $pid, $slot['choices'], true ) || ! self::product( $pid ) ) { return self::error( 'pc_offer_choice', __( 'Select an available SKU for every bundle slot.', 'peptide-core' ) ); }
			$p = self::product( $pid ); if ( self::conflict( $p ) ) { return self::error( 'pc_offer_conflict', __( 'A selected item belongs to another active promotion. This bundle cannot be combined with it.', 'peptide-core' ) ); }
			$out[ $slot['id'] ] = array( 'product' => $pid, 'qty' => $slot['qty'] * $units, 'base_qty' => $slot['qty'], 'price' => (float) $p->get_price() );
		}
		$total = array_sum( array_map( static fn( $s ) => $s['base_qty'] * $s['price'], $out ) );
		if ( ! is_finite( $total ) || $total <= 0 || $total > 1000000000 || ( 'fixed' === $offer['price_type'] && $offer['price_value'] > $total ) ) { return self::error( 'pc_offer_price_changed', __( 'The bundle price needs review after a product price change. Please use the normal product pages.', 'peptide-core' ) ); }
		return $out;
	}
	public static function add_bundle( int $id, array $choices, int $units = 1, ?\WC_Cart $cart = null ) {
		$cart = $cart ?: WC()->cart; $offer = self::get( $id );
		if ( ! $cart || ! $offer || 'bundle' !== $offer['kind'] || ! self::active( $offer ) ) { return self::error( 'pc_offer_inactive', __( 'This product offer is not available.', 'peptide-core' ) ); }
		if ( $cart->get_applied_coupons() ) { return self::error( 'pc_offer_coupon', __( 'Remove coupons before adding a bundle. These offers cannot be combined.', 'peptide-core' ) ); }
		$selections = self::selections( $offer, $choices, $units ); if ( is_wp_error( $selections ) ) { return $selections; }
		$group = wp_generate_uuid4(); $added = array(); self::$busy = true;
		try {
			// Replacing a gift frees its real SKU stock before native add_to_cart checks the new components.
			foreach ( $cart->get_cart() as $key => $item ) { if ( self::valid_meta( $item ) && 'gift' === $item[ self::CART ]['kind'] ) { $cart->remove_cart_item( $key ); } }
			if ( ! self::stock_ok( $selections, $cart ) ) { return self::error( 'pc_offer_stock', __( 'There is not enough stock for this complete offer.', 'peptide-core' ) ); }
			foreach ( $selections as $slot => $s ) { $key = self::add_line( $cart, $offer, $s['product'], $s['qty'], $group, $slot ); if ( ! $key ) { foreach ( $cart->get_cart() as $old => $line ) { if ( ( $line[ self::CART ]['group'] ?? '' ) === $group ) { $cart->remove_cart_item( $old ); } } return self::error( 'pc_offer_add', __( 'The complete bundle could not be added. No partial bundle was kept.', 'peptide-core' ) ); } $added[] = $key; }
		} catch ( \Throwable $e ) {
			foreach ( $cart->get_cart() as $old => $line ) { if ( ( $line[ self::CART ]['group'] ?? '' ) === $group ) { $cart->remove_cart_item( $old ); } }
			return self::error( 'pc_offer_add', __( 'The complete bundle could not be added. No partial bundle was kept.', 'peptide-core' ) );
		} finally { self::$busy = false; $cart->calculate_totals(); }
		return $group;
	}
	private static function groups( \WC_Cart $cart ): array {
		$out = array(); foreach ( $cart->get_cart() as $key => $item ) { if ( isset( $item[ self::CART ] ) && self::valid_meta( $item ) ) { $out[ $item[ self::CART ]['group'] ][ $key ] = $item; } } return $out;
	}
	/** An error is surfaced at checkout; invalid or changed bundles receive normal native prices. */
	private static function group_state( array $items, \WC_Cart $cart ) {
		$first = reset( $items ); $meta = $first[ self::CART ]; $offer = self::get( (int) $meta['offer'] );
		if ( ! $offer || ! self::active( $offer ) || $meta['version'] !== self::version( $offer ) || $cart->get_applied_coupons() ) { return self::error( 'pc_offer_changed', __( 'An offer has changed or expired. Remove its items and add the complete offer again.', 'peptide-core' ) ); }
		if ( 'gift' === $offer['kind'] ) {
			$pid = (int) ( $first['variation_id'] ?: $first['product_id'] );
			return 1 === count( $items ) && $pid === (int) $offer['gift_product'] && (float) $first['quantity'] === (float) $offer['gift_qty'] && self::gift_qualifies( $offer, $cart ) ? $offer : self::error( 'pc_offer_changed', __( 'An offer has changed or expired. Remove its items and add the complete offer again.', 'peptide-core' ) );
		}
		$choices = array(); $units = null;
		foreach ( $items as $item ) {
			$m = $item[ self::CART ]; $slot = array_values( array_filter( $offer['slots'], static fn( $s ) => $s['id'] === $m['slot'] ) )[0] ?? null;
			if ( ! $slot || isset( $choices[ $slot['id'] ] ) || $m['version'] !== $meta['version'] || (int) $m['offer'] !== (int) $offer['id'] ) { return self::error( 'pc_offer_partial', __( 'Keep every bundle slot and quantity together. Remove and re-add the complete bundle.', 'peptide-core' ) ); }
			$n = (float) $item['quantity'] / (float) $slot['qty']; if ( $n < 1 || floor( $n ) !== $n || ( null !== $units && $units !== $n ) ) { return self::error( 'pc_offer_partial', __( 'Keep every bundle slot and quantity together. Remove and re-add the complete bundle.', 'peptide-core' ) ); } $units = $n; $choices[ $slot['id'] ] = (int) ( $item['variation_id'] ?: $item['product_id'] );
		}
		if ( count( $choices ) !== count( $offer['slots'] ) ) { return self::error( 'pc_offer_partial', __( 'Keep every bundle slot and quantity together. Remove and re-add the complete bundle.', 'peptide-core' ) ); }
		$selection = self::selections( $offer, $choices, (int) $units ); if ( is_wp_error( $selection ) ) { return $selection; }
		if ( ! self::stock_ok( $selection, $cart, $meta['group'] ) ) { return self::error( 'pc_offer_stock', __( 'There is not enough stock for this complete offer.', 'peptide-core' ) ); }
		return $offer;
	}
	private static function gift_qualifies( array $offer, \WC_Cart $cart ): bool {
		if ( $cart->get_applied_coupons() ) { return false; } $measure = 0.0;
		foreach ( $cart->get_cart() as $item ) {
			if ( isset( $item[ self::CART ] ) ) { if ( 'bundle' === ( $item[ self::CART ]['kind'] ?? '' ) ) { return false; } continue; }
			$p = self::product( (int) ( $item['variation_id'] ?: $item['product_id'] ) ); if ( ! $p ) { continue; }
			if ( $offer['qualifiers'] && ! array_intersect( $offer['qualifiers'], array( $p->get_id(), $p->get_parent_id() ) ) ) { continue; }
			if ( self::conflict( $p ) ) { return false; }
			$measure += 'quantity' === $offer['basis'] ? (int) $item['quantity'] : (float) $p->get_price() * (int) $item['quantity'];
		}
		$p = self::product( (int) $offer['gift_product'] );
		return $measure + 0.000001 >= $offer['threshold'] && $p && ! self::conflict( $p ) && self::stock_ok( array( array( 'product' => $offer['gift_product'], 'qty' => $offer['gift_qty'] ) ), $cart, 'gift-' . $offer['id'] );
	}
	public static function sync_cart( \WC_Cart $cart ): void {
		if ( self::$busy ) { return; }
		self::$busy = true;
		try {
			// Always start from fresh native product prices, never an already discounted cart object.
			foreach ( $cart->get_cart() as $key => $item ) { if ( isset( $item[ self::CART ] ) ) { $p = wc_get_product( (int) ( $item['variation_id'] ?: $item['product_id'] ) ); if ( $p ) { $item['data']->set_price( $p->get_price() ); } if ( ! self::valid_meta( $item ) ) { unset( $cart->cart_contents[ $key ][ self::CART ] ); } } }
			$gift = null; foreach ( self::all( true ) as $offer ) { if ( 'gift' === $offer['kind'] && self::gift_qualifies( $offer, $cart ) ) { $gift = $offer; break; } }
			$gift_key = null;
			foreach ( $cart->get_cart() as $key => $item ) {
				if ( 'gift' !== ( $item[ self::CART ]['kind'] ?? '' ) ) { continue; }
				if ( ! $gift || (int) $item[ self::CART ]['offer'] !== (int) $gift['id'] || $item[ self::CART ]['version'] !== self::version( $gift ) || $gift_key ) { $cart->remove_cart_item( $key ); }
				else { $gift_key = $key; $cart->set_quantity( $key, $gift['gift_qty'], false ); $item['data']->set_price( 0 ); }
			}
			if ( $gift && ! $gift_key ) { $gift_key = self::add_line( $cart, $gift, $gift['gift_product'], $gift['gift_qty'], 'gift-' . $gift['id'], 'gift' ); if ( $gift_key ) { $cart->cart_contents[ $gift_key ]['data']->set_price( 0 ); } }
			foreach ( self::groups( $cart ) as $items ) {
				$offer = self::group_state( $items, $cart ); if ( is_wp_error( $offer ) || 'bundle' !== $offer['kind'] ) { continue; }
				$total = 0.0; foreach ( $items as $item ) { $total += (float) $item['data']->get_price() * $item['quantity']; }
				$first = reset( $items ); $slot = array_values( array_filter( $offer['slots'], static fn( $s ) => $s['id'] === $first[ self::CART ]['slot'] ) )[0]; $units = $first['quantity'] / $slot['qty'];
				$target = 'fixed' === $offer['price_type'] ? $offer['price_value'] * $units : $total * ( 1 - $offer['price_value'] / 100 );
				$scale = 10 ** wc_get_price_decimals(); $target_minor = (int) round( $target * $scale ); $allocated = array(); $fractions = array(); $used = 0;
				foreach ( $items as $key => $item ) { $share = $target_minor * ( (float) $item['data']->get_price() * $item['quantity'] / $total ); $allocated[ $key ] = (int) floor( $share ); $fractions[ $key ] = $share - $allocated[ $key ]; $used += $allocated[ $key ]; }
				arsort( $fractions, SORT_NUMERIC ); $remainder = $target_minor - $used;
				foreach ( array_keys( $fractions ) as $key ) { if ( $remainder <= 0 ) { break; } ++$allocated[ $key ]; --$remainder; }
				foreach ( $items as $key => $item ) { $cart->cart_contents[ $key ]['data']->set_price( $allocated[ $key ] / $scale / $item['quantity'] ); }
			}
		} finally { self::$busy = false; }
	}
	public static function validate_cart( \WC_Cart $cart ): array {
		$errors = array(); foreach ( $cart->get_cart() as $item ) { if ( isset( $item[ self::CART ] ) && ! self::valid_meta( $item ) ) { $errors[] = __( 'Invalid offer data. Remove and re-add the affected product.', 'peptide-core' ); } }
		foreach ( self::groups( $cart ) as $items ) { $state = self::group_state( $items, $cart ); if ( is_wp_error( $state ) ) { $errors[] = $state->get_error_message(); } }
		return array_values( array_unique( $errors ) );
	}
	public static function check_cart(): void { if ( WC()->cart ) { foreach ( self::validate_cart( WC()->cart ) as $message ) { if ( ! wc_has_notice( $message, 'error' ) ) { wc_add_notice( $message, 'error' ); } } } }
	public static function store_api_errors( \WP_Error $errors, \WC_Cart $cart ): void { foreach ( self::validate_cart( $cart ) as $i => $message ) { $errors->add( 'pc_offer_' . $i, $message ); } }
	public static function quantity_changed( string $key, $quantity, $old, \WC_Cart $cart ): void {
		if ( self::$busy || empty( $cart->cart_contents[ $key ][ self::CART ] ) ) { return; }
		$item = $cart->cart_contents[ $key ]; if ( ! self::valid_meta( $item ) ) { return; } $m = $item[ self::CART ]; $offer = self::get( $m['offer'] ); if ( ! $offer ) { return; }
		self::$busy = true;
		try {
			if ( 'gift' === $m['kind'] ) { $cart->set_quantity( $key, $offer['gift_qty'], false ); return; }
			$slot = array_values( array_filter( $offer['slots'], static fn( $s ) => $s['id'] === $m['slot'] ) )[0] ?? null; $units = $slot ? (float) $quantity / (float) $slot['qty'] : 0.0;
			$items = self::groups( $cart )[ $m['group'] ] ?? array(); $choices = array(); foreach ( $items as $line ) { $choices[ $line[ self::CART ]['slot'] ] = (int) ( $line['variation_id'] ?: $line['product_id'] ); }
			$selection = floor( $units ) === $units ? self::selections( $offer, $choices, (int) $units ) : self::error( 'qty', '' );
			if ( is_wp_error( $selection ) || ! self::stock_ok( $selection, $cart, $m['group'] ) ) { $cart->set_quantity( $key, $old, false ); wc_add_notice( __( 'Bundle quantities must stay together and within available stock.', 'peptide-core' ), 'error' ); return; }
			foreach ( $items as $other_key => $line ) { $s = $selection[ $line[ self::CART ]['slot'] ]; $cart->set_quantity( $other_key, $s['qty'], false ); }
		} finally { self::$busy = false; }
	}
	public static function remove_group( string $key, \WC_Cart $cart ): void {
		if ( self::$busy || empty( $cart->cart_contents[ $key ][ self::CART ] ) ) { return; } $item = $cart->cart_contents[ $key ]; if ( ! self::valid_meta( $item ) || 'bundle' !== $item[ self::CART ]['kind'] ) { return; }
		self::$busy = true; try { foreach ( $cart->get_cart() as $other => $line ) { if ( $other !== $key && ( $line[ self::CART ]['group'] ?? '' ) === $item[ self::CART ]['group'] ) { $cart->remove_cart_item( $other ); } } } finally { self::$busy = false; }
	}
	public static function restore_group( string $key, \WC_Cart $cart ): void {
		if ( self::$busy || empty( $cart->cart_contents[ $key ][ self::CART ] ) ) { return; } $item = $cart->cart_contents[ $key ]; if ( ! self::valid_meta( $item ) || 'bundle' !== $item[ self::CART ]['kind'] ) { return; }
		self::$busy = true; try { foreach ( $cart->removed_cart_contents as $other => $line ) { if ( ( $line[ self::CART ]['group'] ?? '' ) === $item[ self::CART ]['group'] ) { $cart->restore_cart_item( $other ); } } } finally { self::$busy = false; }
	}
	public static function coupon_valid( bool $valid, $coupon, $discounts ): bool {
		if ( $valid && WC()->cart ) { foreach ( WC()->cart->get_cart() as $item ) { if ( self::valid_meta( $item ) && 'bundle' === $item[ self::CART ]['kind'] ) { throw new \Exception( __( 'Coupons cannot be combined with a bundle. Remove the bundle first.', 'peptide-core' ) ); } } } return $valid;
	}
	public static function coupon_product_valid( bool $valid, $product, $coupon, $values ): bool { return isset( $values[ self::CART ] ) ? false : $valid; }
	public static function order_item( \WC_Order_Item_Product $item, string $key, array $values, \WC_Order $order ): void {
		if ( ! self::valid_meta( $values ) ) { return; } $m = $values[ self::CART ]; foreach ( array( 'offer', 'kind', 'group', 'slot' ) as $field ) { $item->add_meta_data( '_pc_offer_' . $field, $m[ $field ], true ); }
		$offer = self::get( $m['offer'] ); $item->add_meta_data( __( 'Product offer', 'peptide-core' ), $offer ? $offer['title'] : (string) $m['offer'], true );
	}
	public static function item_data( array $data, array $item ): array { if ( self::valid_meta( $item ) ) { $offer = self::get( $item[ self::CART ]['offer'] ); $data[] = array( 'key' => __( 'Product offer', 'peptide-core' ), 'value' => esc_html( $offer ? $offer['title'] : __( 'Unavailable offer', 'peptide-core' ) ) ); } return $data; }
	public static function shortcode( $attrs ): string { $a = shortcode_atts( array( 'id' => 0 ), $attrs, 'pc_product_offer' ); return self::render_form( absint( $a['id'] ) ); }
	public static function render_form( int $id ): string {
		$offer = self::get( $id ); if ( ! $offer || ! self::active( $offer ) ) { return ''; }
		wp_enqueue_style( 'pc-product-offers', plugins_url( 'assets/product-offers.css', PEPTIDE_CORE_FILE ), array(), (string) filemtime( PEPTIDE_CORE_DIR . '/assets/product-offers.css' ) );
		$out = '<section class="pc-product-offer"><h3>' . esc_html( $offer['title'] ) . '</h3><p>' . esc_html( self::policy() ) . '</p>';
		if ( 'gift' === $offer['kind'] ) { $p = self::product( $offer['gift_product'] ); if ( ! $p || ! $p->is_in_stock() || ! $p->has_enough_stock( $offer['gift_qty'] ) || $p->is_on_backorder( $offer['gift_qty'] ) || self::conflict( $p ) ) { return ''; } return $out . '<p>' . esc_html( sprintf( 'quantity' === $offer['basis'] ? __( 'Buy at least %1$s eligible items and receive %2$s × %3$s automatically, while stock is available.', 'peptide-core' ) : __( 'Spend at least %1$s on eligible items and receive %2$s × %3$s automatically, while stock is available.', 'peptide-core' ), 'quantity' === $offer['basis'] ? $offer['threshold'] : wp_strip_all_tags( wc_price( $offer['threshold'] ) ), $offer['gift_qty'], $p->get_name() ) ) . '</p></section>'; }
		$out .= '<p>' . ( 'fixed' === $offer['price_type'] ? wp_kses_post( wc_price( $offer['price_value'] ) ) : esc_html( sprintf( __( '%s%% off the selected products', 'peptide-core' ), $offer['price_value'] ) ) ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="pc_offer_add"><input type="hidden" name="offer" value="' . $id . '">' . wp_nonce_field( 'pc_offer_add_' . $id, '_wpnonce', false, false );
		foreach ( $offer['slots'] as $slot ) { $options = ''; foreach ( $slot['choices'] as $pid ) { $p = self::product( $pid ); if ( $p && $p->is_in_stock() && ! self::conflict( $p ) ) { $options .= '<option value="' . $pid . '">' . esc_html( $p->get_name() ) . '</option>'; } } if ( ! $options ) { return ''; } $out .= '<label>' . esc_html( $slot['label'] ?: __( 'Choose product', 'peptide-core' ) ) . ' × ' . $slot['qty'] . '<select name="choices[' . esc_attr( $slot['id'] ) . ']" required>' . $options . '</select></label>'; }
		return $out . '<label>' . esc_html__( 'Complete bundle quantity', 'peptide-core' ) . '<input type="number" name="units" value="1" min="1" max="10" required></label><button type="submit">' . esc_html__( 'Add complete bundle', 'peptide-core' ) . '</button></form></section>';
	}
	public static function product_forms(): void {
		global $product; if ( ! $product instanceof \WC_Product ) { return; }
		foreach ( self::all( true ) as $offer ) { $ids = 'gift' === $offer['kind'] ? $offer['qualifiers'] : array_merge( ...array_column( $offer['slots'], 'choices' ) ); $match = ! $ids || in_array( $product->get_id(), $ids, true ); foreach ( $ids as $pid ) { $match = $match || (int) wp_get_post_parent_id( $pid ) === $product->get_id(); } if ( $match ) { echo self::render_form( $offer['id'] ); } }
	}
	public static function add_action(): void {
		$id = absint( $_POST['offer'] ?? 0 );
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'pc_offer_add_' . $id ) ) { wp_die( esc_html__( 'This offer form has expired. Reload the page and try again.', 'peptide-core' ), '', array( 'response' => 403 ) ); }
		if ( ! WC()->cart ) { wc_load_cart(); } $result = self::add_bundle( $id, array_map( 'absint', (array) ( $_POST['choices'] ?? array() ) ), absint( $_POST['units'] ?? 1 ) );
		if ( is_wp_error( $result ) ) { wp_die( '<p>' . esc_html( $result->get_error_message() ) . '</p><p><a href="' . esc_url( wc_get_cart_url() ) . '">' . esc_html__( 'Return to cart', 'peptide-core' ) . '</a></p>', '', array( 'response' => 400 ) ); }
		wp_safe_redirect( wc_get_cart_url() ); exit;
	}
}
