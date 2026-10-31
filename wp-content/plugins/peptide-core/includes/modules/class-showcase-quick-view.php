<?php
/** Module-scoped native Woo purchase forms. No new endpoint or variation matcher. */
namespace PeptideCore\Modules;

defined( 'ABSPATH' ) || exit;

final class Showcase_Quick_View {
	private static array $rendered = array();
	private static bool $assets = false;

	/** Never put nonpublic or password-protected purchase data into templates. */
	public static function supports( $product ): bool {
		return $product instanceof \WC_Product && 'publish' === $product->get_status() && $product->is_publicly_viewable()
			&& '' === $product->get_post_password() && $product->is_type( array( 'simple', 'variable' ) )
			&& function_exists( 'woocommerce_template_single_add_to_cart' );
	}

	private static function enqueue( $product ): void {
		// The actual theme opts in here; its original product-page enqueue remains independent.
		do_action( 'peptide_core_showcase_quick_view_assets', $product );
		if ( self::$assets ) { return; }
		self::$assets = true;
		$deps = array( 'jquery', 'wc-add-to-cart-variation', Renderer::SCRIPT );
		if ( wp_script_is( 'vialcove-product-options', 'registered' ) ) { $deps[] = 'vialcove-product-options'; }
		wp_enqueue_script( 'peptide-showcase-quick-view', plugins_url( 'assets/showcase-quick-view.js', PEPTIDE_CORE_FILE ), $deps, PEPTIDE_CORE_VERSION . '.' . filemtime( PEPTIDE_CORE_DIR . '/assets/showcase-quick-view.js' ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
	}

	/** A paged catalog may receive its first supported product in a later batch. */
	public static function catalog_assets(): void { self::enqueue( null ); }

	/** Shared native dispatcher; caller checks the existing product eligibility before rendering. */
	private static function native_form( $product ): string {
		$id = $product->get_id();
		$had_product = array_key_exists( 'product', $GLOBALS ); $old_product = $GLOBALS['product'] ?? null;
		$had_post = array_key_exists( 'post', $GLOBALS ); $old_post = $GLOBALS['post'] ?? null;
		$level = ob_get_level();
		try {
			$GLOBALS['product'] = $product;
			$GLOBALS['post'] = get_post( $id );
			ob_start(); woocommerce_template_single_add_to_cart(); $form = (string) ob_get_clean();
		} finally {
			while ( ob_get_level() > $level ) { ob_end_clean(); }
			if ( $had_product ) { $GLOBALS['product'] = $old_product; } else { unset( $GLOBALS['product'] ); }
			if ( $had_post ) { $GLOBALS['post'] = $old_post; } else { unset( $GLOBALS['post'] ); }
		}
		return $form;
	}

	private static function config(): array {
		return array(
			'cartApi' => rest_url( 'wc/store/v1/cart' ), 'addApi' => rest_url( 'wc/store/v1/cart/add-item' ),
			'cartUrl' => wc_get_cart_url(), 'checkoutUrl' => wc_get_checkout_url(),
			'wpNonce' => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
		);
	}

	/** One inline host owns its own cloned form; the Quick View pool is not shared or moved. */
	public static function inline_purchase( $product, string $instance ): string {
		if ( ! self::supports( $product ) ) { return ''; }
		self::enqueue( $product );
		$form = self::native_form( $product );
		$config = self::config();
		$flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
		$out = '<div class="pc-featured__purchase" data-vc-inline-purchase="' . esc_attr( $instance ) . '" data-vc-product-id="' . (int) $product->get_id() . '" data-vc-product-name="' . esc_attr( $product->get_name() ) . '" data-vc-variable="' . ( $product->is_type( 'variable' ) ? '1' : '0' ) . '" data-vc-inline-config="' . esc_attr( wp_json_encode( $config, $flags ) ) . '">';
		$out .= '<template data-vc-inline-form><div class="pc-featured__form">' . $form;
		if ( str_contains( $form, '<form' ) ) { $out .= '<button type="button" class="button pc-featured__buy" data-vc-quick-buy disabled>' . esc_html__( 'Buy it now', 'peptide-core' ) . '</button>'; }
		$out .= '</div></template><div class="pc-featured__feedback" data-vc-inline-feedback>';
		$out .= '<p role="status" aria-live="polite" data-vc-inline-status></p><a data-vc-inline-cart hidden href="' . esc_url( $config['cartUrl'] ) . '">' . esc_html__( 'View cart', 'peptide-core' ) . '</a>';
		$out .= '<button type="button" data-vc-inline-check hidden>' . esc_html__( 'Retry cart check', 'peptide-core' ) . '</button><button type="button" data-vc-inline-acknowledge hidden>' . esc_html__( "I've checked my cart", 'peptide-core' ) . '</button></div>';
		$out .= '<a class="pc-featured__details" href="' . esc_url( $product->get_permalink() ) . '">' . esc_html__( 'View full product details', 'peptide-core' ) . '</a></div>';
		return $out;
	}

	/** Fixed, public specifications only; never match or silently replace an incomplete variation. */
	public static function fixed_variation_product( $candidate ): ?\WC_Product_Variation {
		if ( ! function_exists( 'wc_get_product' ) ) { return null; }
		if ( is_int( $candidate ) || ( is_string( $candidate ) && preg_match( '/^[1-9][0-9]*$/D', $candidate ) ) ) { $candidate = wc_get_product( (int) $candidate ); }
		if ( ! $candidate instanceof \WC_Product_Variation || 'product_variation' !== get_post_type( $candidate->get_id() ) || 'publish' !== $candidate->get_status() || '' !== $candidate->get_post_password() || '' !== (string) get_post_field( 'post_password', $candidate->get_id(), 'raw' ) ) { return null; }
		$parent = wc_get_product( $candidate->get_parent_id() );
		if ( ! Products::public_catalog_product( $parent ) || ! $parent->is_type( 'variable' ) || ! $candidate->variation_is_active() || ! $candidate->variation_is_visible() || ! $candidate->is_purchasable() || ! $candidate->is_in_stock() ) { return null; }
		$attributes = $candidate->get_variation_attributes(); $required = $parent->get_variation_attributes();
		if ( ! $required ) { return null; }
		foreach ( $required as $name => $options ) {
			$value = $attributes[ wc_variation_attribute_name( $name ) ] ?? null;
			if ( ! is_string( $value ) || '' === trim( $value ) ) { return null; }
		}
		foreach ( $attributes as $value ) { if ( ! is_string( $value ) || '' === trim( $value ) ) { return null; } }
		// Product getters are read-only. StoreAPI QuantityLimits would initialize a session during lookup.
		$minimum = (float) $candidate->get_min_purchase_quantity(); $maximum = (float) $candidate->get_max_purchase_quantity();
		$step = method_exists( $candidate, 'get_purchase_quantity_step' ) ? (float) $candidate->get_purchase_quantity_step() : 1.0;
		if ( ! is_finite( $minimum ) || ! is_finite( $maximum ) || ! is_finite( $step ) || $minimum > 1 || ( $maximum >= 0 && $maximum < 1 ) || $step <= 0 ) { return null; }
		$steps = 1 / $step;
		if ( ! is_finite( $steps ) || abs( $steps - round( $steps ) ) > 0.0000001 ) { return null; }
		return $candidate;
	}

	/** O01 fixed choice reuses the existing inline transport, confirmation and Blocks-checkout flow. */
	public static function inline_variation_purchase( $candidate, string $instance, string $label ): string {
		if ( Renderer::$in_editor || \PeptideCore\Templates::previewing_id() || ( \PeptideCore\Store_Config::get( 'force_login' ) && ! is_user_logged_in() ) ) { return ''; }
		$variation = self::fixed_variation_product( $candidate );
		if ( ! $variation || ! function_exists( 'woocommerce_template_single_add_to_cart' ) ) { return ''; }
		self::enqueue( wc_get_product( $variation->get_parent_id() ) );
		$config = self::config(); $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
		// A concrete variation needs no client-side matcher. StoreAPI accepts its ID and checks it again.
		$out = '<div class="pc-featured__purchase pc-pricing__purchase" data-vc-inline-purchase="' . esc_attr( $instance ) . '" data-vc-product-id="' . (int) $variation->get_id() . '" data-vc-product-name="' . esc_attr( $variation->get_name() ) . '" data-vc-variable="0" data-vc-fixed-variation="' . (int) $variation->get_id() . '" data-vc-inline-config="' . esc_attr( wp_json_encode( $config, $flags ) ) . '">';
		$out .= '<template data-vc-inline-form><div class="pc-featured__form"><form class="cart"><input type="hidden" name="quantity" value="1"><button type="button" class="button single_add_to_cart_button pc-pricing__primary" data-vc-quick-buy disabled>' . esc_html( $label ) . '</button></form></div></template>';
		$out .= '<div class="pc-featured__feedback" data-vc-inline-feedback><p role="status" aria-live="polite" data-vc-inline-status></p><a data-vc-inline-cart hidden href="' . esc_url( $config['cartUrl'] ) . '">' . esc_html__( 'View cart', 'peptide-core' ) . '</a><button type="button" data-vc-inline-check hidden>' . esc_html__( 'Retry cart check', 'peptide-core' ) . '</button><button type="button" data-vc-inline-acknowledge hidden>' . esc_html__( "I've checked my cart", 'peptide-core' ) . '</button></div>';
		$out .= '<a class="pc-featured__details pc-pricing__details" href="' . esc_url( $variation->get_permalink() ) . '">' . esc_html__( 'View product details', 'peptide-core' ) . '</a></div>';
		return $out;
	}

	/** Templates are inert; JavaScript activates at most one form and namespaces its DOM IDs. */
	public static function template( $product ): string {
		if ( ! self::supports( $product ) ) { return ''; }
		self::enqueue( $product );
		$id = $product->get_id();
		if ( isset( self::$rendered[ $id ] ) ) { return ''; }
		$form = self::native_form( $product );
		$config = self::config();
		$flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
		$out = '<template data-vc-quick-product="' . (int) $id . '" data-vc-quick-config="' . esc_attr( wp_json_encode( $config, $flags ) ) . '">';
		$out .= '<div class="vc-showcase-quick-product product" data-vc-product-id="' . (int) $id . '" data-vc-product-name="' . esc_attr( $product->get_name() ) . '" data-vc-variable="' . ( $product->is_type( 'variable' ) ? '1' : '0' ) . '">';
		if ( $product->get_image_id() ) { $out .= '<div class="vc-showcase-quick__media">' . $product->get_image( 'woocommerce_single' ) . '</div>'; }
		$out .= '<div class="summary"><h2 class="vc-showcase-quick__title">' . esc_html( $product->get_name() ) . '</h2>';
		$price = ! $product->is_type( 'variable' ) && function_exists( 'vc_public_price_html' ) ? vc_public_price_html( $product ) : $product->get_price_html();
		$out .= '<p class="price">' . wp_kses_post( $price ) . '</p>' . $form;
		if ( str_contains( $form, '<form' ) ) {
			$out .= '<button type="button" class="button vc-showcase-quick__buy" data-vc-quick-buy disabled>' . esc_html__( 'Buy it now', 'peptide-core' ) . '</button>';
			$out .= '<p class="vc-showcase-quick__note">' . esc_html__( 'Buy it now adds this selection to your existing cart, then opens checkout.', 'peptide-core' ) . '</p>';
		} else {
			$out .= '<p>' . esc_html__( 'Purchasing options are currently unavailable. Visit the product page for details.', 'peptide-core' ) . '</p>';
		}
		$out .= '<a class="vc-showcase-quick__details" href="' . esc_url( $product->get_permalink() ) . '">' . esc_html__( 'View full product details', 'peptide-core' ) . '</a></div></div></template>';
		self::$rendered[ $id ] = true;
		return $out;
	}
}
