<?php
/** Read-only equivalent price per vial. A sales quantity is still one native Woo product/box. */
namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Unit_Pricing {
	public const META = '_pc_vials_per_sales_unit';
	private const FIELD = 'pc_vials_per_sales_unit';
	private const MAX_COUNT = 10000;
	private const SAFE_MINOR = 9007199254740991;

	public static function register_hooks(): void {
		add_action( 'woocommerce_product_options_general_product_data', array( self::class, 'render_product_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( self::class, 'save_product_field' ) );
		add_action( 'woocommerce_variation_options_pricing', array( self::class, 'render_variation_field' ), 10, 3 );
		add_action( 'woocommerce_admin_process_variation_object', array( self::class, 'save_variation_field' ), 10, 2 );
		add_filter( 'woocommerce_available_variation', array( self::class, 'variation_data' ), 10, 3 );
	}

	/** Null means no reliable selected-SKU price/count; it never means an assumed 10-vial box. */
	public static function for_product( $product, string $display_context = 'shop' ): ?array {
		try { return self::read_product( $product, $display_context ); }
		catch ( \Throwable $error ) { return null; } // An informational enhancement cannot block the native cart/checkout.
	}

	private static function read_product( $product, string $display_context ): ?array {
		if ( ! in_array( $display_context, array( 'shop', 'cart' ), true ) ) { return null; }
		if ( ! $product instanceof \WC_Product || ! $product->is_type( array( 'simple', 'variation' ) )
			|| 'publish' !== $product->get_status() || '' !== $product->get_post_password()
			|| ! $product->is_purchasable() || ! $product->is_in_stock() ) { return null; }
		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( ! $parent instanceof \WC_Product || ! $parent->is_type( 'variable' ) || ! self::public_parent( $parent )
				|| ! $product->variation_is_active() || ! $product->variation_is_visible() ) { return null; }
			$attributes = $product->get_variation_attributes(); $required = $parent->get_variation_attributes();
			if ( ! $required ) { return null; }
			foreach ( $required as $name => $options ) {
				$value = $attributes[ wc_variation_attribute_name( $name ) ] ?? null;
				if ( ! is_string( $value ) || '' === trim( $value ) ) { return null; }
			}
		} else {
			$parent = $product;
			if ( ! self::public_parent( $parent ) ) { return null; }
		}
		$count = self::count_for_product( $product, $parent );
		if ( null === $count ) { return null; }
		$decimals = wc_get_price_decimals();
		$raw = $product->get_price();
		if ( ! is_int( $decimals ) || $decimals < 0 || $decimals > 8 || ! is_numeric( $raw ) || ! is_finite( (float) $raw ) || (float) $raw < 0 ) { return null; }
		$display = wc_get_price_to_display( $product, array( 'price' => (float) $raw, 'qty' => 1, 'display_context' => $display_context ) );
		$factor = 10 ** $decimals;
		if ( ! is_numeric( $display ) || ! is_finite( (float) $display ) || (float) $display < 0 || (float) $display * $factor > self::SAFE_MINOR ) { return null; }
		$minor = (int) round( (float) $display * $factor );
		$each = intdiv( $minor, $count['count'] );
		if ( ( $minor % $count['count'] ) * 2 >= $count['count'] ) { ++$each; }
		return array(
			'version' => 1, 'productId' => $parent->get_id(), 'variationId' => $product->is_type( 'variation' ) ? $product->get_id() : 0,
			'purchasableId' => $product->get_id(), 'vialsPerSalesUnit' => $count['count'], 'countSource' => $count['source'],
			'salesUnitPrice' => self::money( $minor, $factor ), 'perVialPrice' => self::money( $each, $factor ),
			'approximate' => 0 !== $minor % $count['count'], 'currency' => get_woocommerce_currency(), 'currencyMinorUnit' => $decimals,
			'taxDisplay' => get_option( 'woocommerce_tax_display_' . $display_context, 'excl' ), 'basis' => 'cart' === $display_context ? 'cart_display_price' : 'catalog_display_price',
			'includesCoupons' => false, 'isPurchasableAsSingleVial' => 1 === $count['count'],
		);
	}

	private static function public_parent( \WC_Product $product ): bool {
		return 'publish' === $product->get_status() && '' === $product->get_post_password() && $product->is_publicly_viewable() && $product->is_visible();
	}

	private static function money( int $minor, int $factor ): array {
		return array( 'minor' => (string) $minor, 'text' => html_entity_decode( wp_strip_all_tags( wc_price( $minor / $factor ) ), ENT_QUOTES, 'UTF-8' ) );
	}

	/** Explicit SKU value wins; 0 deliberately hides. Parent fallback never overrides an ambiguous SKU pack. */
	private static function count_for_product( \WC_Product $product, \WC_Product $parent ): ?array {
		$own = $product->get_meta( self::META, true, 'edit' );
		if ( '' !== $own ) {
			$count = self::normalize_count( $own );
			return null !== $count && $count > 0 ? array( 'count' => $count, 'source' => 'explicit_sku' ) : null;
		}
		$values = array();
		if ( $product->is_type( 'variation' ) ) {
			foreach ( $product->get_variation_attributes() as $key => $value ) {
				if ( in_array( $key, array( 'attribute_pack', 'attribute_pa_pack' ), true ) && '' !== trim( (string) $value ) ) { $values[] = (string) $value; }
			}
		} else {
			foreach ( array( 'pack', 'pa_pack' ) as $key ) { $value = $product->get_attribute( $key ); if ( '' !== trim( $value ) ) { $values[] = $value; } }
			foreach ( (array) $product->get_meta( Meta::PRODUCT_SPECS ) as $spec ) {
				if ( is_array( $spec ) && 'pack' === ( $spec['key'] ?? '' ) && is_string( $spec['value'] ?? null ) ) { $values[] = $spec['value']; }
			}
		}
		if ( $values ) {
			$counts = array_map( array( self::class, 'parse_pack' ), $values );
			if ( in_array( null, $counts, true ) || count( array_unique( $counts ) ) !== 1 ) { return null; }
			return array( 'count' => $counts[0], 'source' => 'stored_pack' );
		}
		if ( $product->is_type( 'variation' ) ) {
			$count = self::normalize_count( $parent->get_meta( self::META, true, 'edit' ) );
			if ( null !== $count && $count > 0 ) { return array( 'count' => $count, 'source' => 'explicit_parent' ); }
		}
		return null;
	}

	/** Only complete, unambiguous stored Pack strings. Never scan descriptions, titles or dose numbers. */
	public static function parse_pack( string $value ): ?int {
		if ( strlen( $value ) > 160 ) { return null; }
		$pattern = '~\A\s*(?:[0-9]+(?:\.[0-9]+)?\s*(?:mg|mcg|ug|μg|g|ml|iu|units?)(?:\s*/\s*(?:vial|bottle))?\s*[*x×·]\s*)?([1-9][0-9]{0,4})\s*(?:vials?|bottles?|瓶)(?:\s*/\s*(?:box|pack|盒))?\s*\z~iu';
		if ( 1 !== preg_match( $pattern, $value, $match ) ) { return null; }
		$count = self::normalize_count( $match[1] );
		return null !== $count && $count > 0 ? $count : null;
	}

	/** Empty is inheritance; 0 is explicit hide; no coercion of malformed counts. */
	public static function normalize_count( $value ): ?int {
		if ( is_int( $value ) ) { return $value >= 0 && $value <= self::MAX_COUNT ? $value : null; }
		return is_string( $value ) && 1 === preg_match( '/\A(?:0|[1-9][0-9]{0,4})\z/', $value ) && (int) $value <= self::MAX_COUNT ? (int) $value : null;
	}

	public static function render_product_field(): void {
		global $product_object;
		if ( ! $product_object instanceof \WC_Product ) { return; }
		woocommerce_wp_text_input( array( 'id' => self::FIELD, 'label' => '每销售单位瓶数', 'type' => 'number', 'value' => $product_object->get_meta( self::META, true, 'edit' ),
			'description' => '仅用于计算每瓶参考价，不拆盒、不改售价。留空读取明确Pack规格；0隐藏。可变商品此项供未配置包装的规格沿用。', 'desc_tip' => true,
			'custom_attributes' => array( 'min' => '0', 'max' => (string) self::MAX_COUNT, 'step' => '1' ) ) );
	}

	public static function render_variation_field( int $loop, array $data, $variation ): void {
		$product = wc_get_product( $variation->ID ); if ( ! $product instanceof \WC_Product ) { return; }
		woocommerce_wp_text_input( array( 'id' => self::FIELD . '_' . $loop, 'name' => self::FIELD . '_variation[' . $loop . ']', 'label' => '每销售单位瓶数', 'type' => 'number',
			'value' => $product->get_meta( self::META, true, 'edit' ), 'wrapper_class' => 'form-row form-row-full', 'description' => '留空读取此规格Pack包装，再沿用父商品瓶数；0隐藏。实际仍按原规格整盒销售。',
			'custom_attributes' => array( 'min' => '0', 'max' => (string) self::MAX_COUNT, 'step' => '1' ) ) );
	}

	public static function save_product_field( \WC_Product $product ): void {
		// Native Woo save owns nonce, edit_product permission and the eventual product save.
		if ( array_key_exists( self::FIELD, $_POST ) ) { self::save_count( $product, $_POST[ self::FIELD ] ); } // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	public static function save_variation_field( \WC_Product $product, int $loop ): void {
		$key = self::FIELD . '_variation';
		if ( isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) && array_key_exists( $loop, $_POST[ $key ] ) ) { self::save_count( $product, $_POST[ $key ][ $loop ] ); } // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	private static function save_count( \WC_Product $product, $value ): void {
		$value = is_string( $value ) ? trim( wp_unslash( $value ) ) : $value;
		if ( '' === $value ) { $product->delete_meta_data( self::META ); return; }
		$count = self::normalize_count( $value );
		if ( null === $count ) {
			if ( class_exists( '\WC_Admin_Meta_Boxes' ) ) { \WC_Admin_Meta_Boxes::add_error( '每销售单位瓶数须为0至10000的整数；原值已保留。' ); }
			return;
		}
		$product->update_meta_data( self::META, (string) $count );
	}

	public static function variation_data( array $data, \WC_Product $parent, \WC_Product $variation ): array {
		$data['pc_unit_pricing'] = self::for_product( $variation );
		return $data;
	}
}
