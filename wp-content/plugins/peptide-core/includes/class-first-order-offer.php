<?php
/** First-order offer presentation; WooCommerce remains the only coupon/cart authority. */
namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class First_Order_Offer {

	public const OPTION = 'pc_first_order_offer';
	public const MODULE = 'first_order_offer';
	public const APP = 'first-order-offer';

	public static function register_hooks(): void {
		add_filter( 'peptide_core_layout_definitions', array( self::class, 'definitions' ) );
		add_filter( 'peptide_core_module_html', array( self::class, 'render_module' ), 20, 4 );
		add_filter( 'woocommerce_coupon_is_valid', array( self::class, 'validate_stack' ), 30, 3 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'assets' ), 150 );
		Admin\First_Order_Offer_Page::register_hooks();
	}

	public static function defaults(): array { return array( 'enabled' => false, 'coupon_id' => 0 ); }

	/** Normalization never creates, alters, applies or consumes a coupon. */
	public static function normalize( $raw ): array {
		$raw = is_array( $raw ) ? $raw : array();
		$id = $raw['coupon_id'] ?? 0;
		$id = is_int( $id ) && $id >= 0 ? $id : ( is_string( $id ) && 1 === preg_match( '/\A[0-9]{1,10}\z/D', $id ) ? (int) $id : 0 );
		return array( 'enabled' => true === ( $raw['enabled'] ?? false ), 'coupon_id' => $id );
	}

	public static function settings(): array { return self::normalize( get_option( self::OPTION, self::defaults() ) ); }

	public static function coupon_issue( $coupon, bool $active = true ): string {
		if ( ! $coupon instanceof \WC_Coupon || ! $coupon->get_id() || 'shop_coupon' !== get_post_type( $coupon->get_id() ) ) { return 'missing'; }
		if ( 'percent' !== $coupon->get_discount_type() || ! is_numeric( $coupon->get_amount() ) || 5.0 !== (float) $coupon->get_amount() ) { return 'percent'; }
		if ( 'yes' !== get_post_meta( $coupon->get_id(), Coupon_Rules::FIRST_META, true ) || 1 !== $coupon->get_usage_limit_per_user() ) { return 'first'; }
		if ( $coupon->get_individual_use() || 'stack' !== Coupon_Rules::strategy( $coupon ) ) { return 'stack'; }
		if ( $coupon->get_free_shipping() ) { return 'shipping'; }
		if ( $active && 'active' !== Coupon_Rules::status( $coupon ) ) { return 'inactive'; }
		return '';
	}

	public static function coupon() {
		$s = self::settings();
		if ( ! $s['enabled'] || ! $s['coupon_id'] || ! class_exists( '\WC_Coupon' ) || ! function_exists( 'wc_coupons_enabled' ) || ! wc_coupons_enabled() ) { return null; }
		$c = new \WC_Coupon( $s['coupon_id'] );
		return '' === self::coupon_issue( $c ) ? $c : null;
	}

	/** Only an explicit protected admin action calls this. Existing codes are never overwritten. */
	public static function create_welcome_coupon() {
		global $wpdb;
		if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_shop_coupons' ) ) { return new \WP_Error( 'pc_offer_permission', '没有创建优惠券的权限。' ); }
		if ( ! class_exists( '\WC_Coupon' ) || ! function_exists( 'wc_get_coupon_id_by_code' ) ) { return new \WP_Error( 'pc_offer_woo', 'WooCommerce 优惠券服务不可用。' ); }
		// Woo's coupon title has no unique database index. Serialize ONLY this explicit
		// admin creation, per database/site/code; public reads do not lock or write.
		$lock = 'pc_offer_' . sha1( ( defined( 'DB_NAME' ) ? (string) DB_NAME : '' ) . '|' . (string) ( $wpdb->prefix ?? '' ) . '|welcome5' );
		if ( ! is_object( $wpdb ) || ! is_callable( array( $wpdb, 'prepare' ) ) || ! is_callable( array( $wpdb, 'get_var' ) ) || '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', $lock ) ) ) {
			return new \WP_Error( 'pc_offer_busy', '优惠券创建正在处理或数据库暂不可用，请稍后重试；未新建优惠券。' );
		}
		try {
			// Re-query inside the lock so a concurrent operator reuses the committed code.
			$id = (int) wc_get_coupon_id_by_code( 'WELCOME5' );
			if ( $id ) {
				$c = new \WC_Coupon( $id );
				return '' === self::coupon_issue( $c, false ) ? $id : new \WP_Error( 'pc_offer_exists', 'WELCOME5 已存在但规则不同。未覆盖；请选择其他符合规则的优惠券。' );
			}
			$c = new \WC_Coupon();
			$c->set_code( 'WELCOME5' ); $c->set_discount_type( 'percent' ); $c->set_amount( '5' );
			$c->set_individual_use( false ); $c->set_usage_limit_per_user( 1 ); $c->set_exclude_sale_items( false );
			$c->set_free_shipping( false ); $c->set_description( '5% off eligible items on a first order. Eligibility is checked at checkout.' );
			$c->update_meta_data( Coupon_Rules::FIRST_META, 'yes' );
			$c->update_meta_data( Coupon_Rules::STRATEGY_META, 'stack' );
			$c->update_meta_data( Coupon_Rules::LABEL_META, 'WELCOME5' );
			$id = (int) $c->save();
			return $id > 0 ? $id : new \WP_Error( 'pc_offer_create', '优惠券创建失败，请检查优惠券列表后再操作。' );
		} catch ( \Throwable $error ) { return new \WP_Error( 'pc_offer_create', '优惠券创建失败，请检查优惠券列表后再操作。' ); }
		finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) ); }
	}

	/** Preserve an otherwise eligible promotion rather than silently replacing it with this code. */
	public static function validate_stack( $valid, $coupon, $discounts = null ) {
		if ( ! $valid || ! $coupon instanceof \WC_Coupon ) { return $valid; }
		$s = self::settings();
		if ( ! $s['enabled'] || $s['coupon_id'] !== $coupon->get_id() ) { return $valid; }
		if ( '' !== self::coupon_issue( $coupon ) ) { throw new \Exception( self::strings()['unavailable'] ); }
		$cart = $discounts instanceof \WC_Discounts ? $discounts->get_object() : null;
		if ( ! $cart instanceof \WC_Cart ) { return $valid; }
		foreach ( $cart->get_applied_coupons() as $code ) {
			if ( wc_format_coupon_code( $code ) !== wc_format_coupon_code( $coupon->get_code() ) && ( new \WC_Coupon( $code ) )->get_individual_use() ) {
				throw new \Exception( self::strings()['conflict'] );
			}
		}
		foreach ( Promotions::discounts( $cart, $coupon->get_code() )['applied'] as $promotion ) {
			if ( empty( Promotions::config( (int) $promotion['id'] )['with_coupons'] ) ) {
				throw new \Exception( self::strings()['conflict'] );
			}
		}
		return $valid;
	}

	public static function definitions( array $definitions ): array {
		$definitions[ self::MODULE ] = array(
			'title' => '首单 5% 优惠券', 'category' => 'operation', 'icon' => 'tickets-alt', 'singleRender' => true,
			'description' => '引用应用中心的首单 5% 优惠配置；复制优惠码或申请原生购物车优惠。',
			'settings' => array(), 'items' => null,
		);
		return $definitions;
	}

	public static function render_module( $html, array $module, string $context, string $device ) {
		if ( self::MODULE !== ( $module['type'] ?? '' ) ) { return $html; }
		$preview = 'public' !== $context || Modules\Renderer::$in_editor || Templates::previewing_id() > 0;
		return self::render( $preview );
	}

	public static function assets(): void {
		if ( is_admin() || ! self::coupon() ) { return; }
		foreach ( array( 'css', 'js' ) as $ext ) {
			$file = 'assets/first-order-offer.' . $ext;
			$url = plugins_url( $file, PEPTIDE_CORE_FILE ); $version = (string) filemtime( PEPTIDE_CORE_DIR . '/' . $file );
			if ( 'css' === $ext ) { wp_enqueue_style( 'pc-first-order-offer', $url, array(), $version ); }
			else { wp_enqueue_script( 'pc-first-order-offer', $url, array(), $version, true ); }
		}
	}

	public static function strings(): array {
		$es = class_exists( Storefront_Translation::class ) && 'es' === Storefront_Translation::code() && Storefront_Translation::active();
		return $es ? array(
			'title'=>'5% en su primer pedido', 'body'=>'',
			'copy'=>'Copiar código', 'apply'=>'Obtener', 'copied'=>'Código copiado', 'copyFailed'=>'No se pudo copiar. Seleccione y copie el código de la oferta.',
			'empty'=>'Código copiado. Añada un producto para usarlo.', 'pending'=>'La elegibilidad se verifica al finalizar la compra.',
			'working'=>'Aplicando…', 'success'=>'Su código está aplicado.', 'already'=>'Este código ya está aplicado.', 'uncertain'=>'No pudimos confirmar la solicitud. Revise su carrito antes de intentarlo de nuevo.',
			'failed'=>'No se pudo aplicar el código. Revise sus condiciones y su carrito antes de intentarlo de nuevo.',
			'errors'=>array(
				'This code is for a first order only.'=>'Este código solo es válido para un primer pedido.',
				'The request timed out. Please check your bag before trying again.'=>'La solicitud agotó el tiempo de espera. Revise su carrito antes de intentarlo de nuevo.',
				'Your bag is updating. Please try again when it is ready.'=>'Su carrito se está actualizando. Vuelva a intentarlo cuando esté listo.',
				'Your bag could not be verified. Please reload it.'=>'No se pudo verificar su carrito. Vuelva a cargarlo.',
				'The cart update could not be confirmed. Review your bag before trying again.'=>'No se pudo confirmar la actualización. Revise su carrito antes de intentarlo de nuevo.',
			),
			'unavailable'=>'Esta oferta no está disponible ahora.', 'conflict'=>'No se puede añadir este código sin sustituir una promoción actual. Su descuento existente se conserva.',
			'notReady'=>'Su carrito aún no está listo. Puede copiar el código y aplicarlo al finalizar la compra.', 'discount'=>'Descuento del cupón antes de impuestos',
			'close'=>'Cerrar resultado', 'viewCart'=>'Ver carrito', 'timer'=>'Este mensaje se cierra automáticamente en 15 segundos.', 'orderTotal'=>'Total actual del pedido',
		) : array(
			'title'=>'5% off your first order', 'body'=>'',
			'copy'=>'Copy code', 'apply'=>'Claim', 'copied'=>'Code copied', 'copyFailed'=>'Could not copy. Select and copy the code shown in this offer.',
			'empty'=>'Code copied. Add an item to use it.', 'pending'=>'First-order eligibility is checked at checkout.',
			'working'=>'Applying…', 'success'=>'Your code is applied.', 'already'=>'This code is already applied.', 'uncertain'=>'We could not confirm the request. Review your bag before trying again.',
			'failed'=>'The code could not be applied. Check its conditions and review your bag before trying again.',
			'unavailable'=>'This offer is not available right now.', 'conflict'=>'This code cannot be added without replacing an existing promotion. Your current discount is preserved.',
			'notReady'=>'Your bag is not ready yet. You can copy the code and apply it at checkout.', 'discount'=>'Coupon discount before tax',
			'close'=>'Close result', 'viewCart'=>'View bag', 'timer'=>'This message closes automatically in 15 seconds.', 'orderTotal'=>'Current order total',
		);
	}

	/** Public static offer only: no customer/email/order information is embedded in HTML. */
	public static function render( bool $preview = false ): string {
		$c = self::coupon();
		if ( ! $c ) { return $preview ? '<p class="pc-first-offer-empty">请在应用中心绑定并启用符合规则的首单 5% 优惠券。</p>' : ''; }
		$s = self::strings();
		$config = array( 'code' => $c->get_code(), 'strings' => $s, 'cartUrl' => wc_get_cart_url(), 'closeMs' => 15000, 'preview' => $preview );
		$json = wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		if ( ! is_string( $json ) ) { return ''; }
		$id = wp_unique_id( 'pc-first-offer-' );
		return '<section class="pc-first-offer" data-pc-first-order-offer data-offer-config="' . esc_attr( $json ) . '" aria-labelledby="' . esc_attr( $id ) . '">' .
			'<div class="pc-first-offer__seal" aria-hidden="true"><span>5<small>%</small></span><svg viewBox="0 0 48 24" fill="none"><path d="M12 7v1m24-1v1M16 14q8 9 16 0" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/></svg></div>' .
			'<div class="pc-first-offer__copy"><h2 id="' . esc_attr( $id ) . '">' . esc_html( $s['title'] ) . '</h2></div>' .
			'<div class="pc-first-offer__actions"><code data-offer-code translate="no">' . esc_html( Coupon_Rules::display_code( $c ) ) . '</code><button type="button" class="pc-first-offer__apply" data-offer-claim data-offer-apply' . ( $preview ? ' disabled' : '' ) . '>' . esc_html( $s['apply'] ) . '</button></div>' .
			'<p class="pc-first-offer__status" data-offer-status role="status" aria-live="polite"></p></section>';
	}
}
