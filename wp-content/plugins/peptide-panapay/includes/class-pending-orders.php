<?php
/** Find an existing unpaid order without resuming it as the next checkout draft. */
namespace VialCove\Panapay;

defined( 'ABSPATH' ) || exit;

final class Pending_Orders {

	private const SESSION_KEY = 'pc_panapay_pending_orders';
	private const MAX_REMEMBERED = 8;
	private const MAX_DISPLAYED = 5;
	private const MAX_AGE = 604800;
	private static bool $rendered = false;

	public static function register_hooks(): void {
		add_action( 'pc_panapay_checkout_detached', array( self::class, 'remember' ) );
		add_action( 'pc_panapay_session_created', array( self::class, 'remember' ) );
		add_action( 'woocommerce_checkout_order_processed', array( self::class, 'remember_processed' ), 20, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'remember' ), 20 );
		add_action( 'template_redirect', array( self::class, 'protect_page' ), 1 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
		// 2026-11-09 店主要求：购物车 / 结账页不再展示“Your unpaid orders”，只保留“我的账户”里的列表。
		add_action( 'woocommerce_account_dashboard', array( self::class, 'render' ), 5 );
	}

	/** No public lookup by order number or email: these hooks already hold the native order. */
	public static function remember_processed( $order_id, $posted_data = array(), $order = null ): void {
		self::remember( $order instanceof \WC_Order ? $order : wc_get_order( $order_id ) );
	}

	private static function session() {
		return function_exists( 'WC' ) && WC() ? WC()->session : null;
	}

	private static function eligible( $order ): bool {
		return $order instanceof \WC_Order && $order->get_id() > 0
			&& null !== Gateway::account_from_id( $order->get_payment_method() )
			&& $order->has_status( array( 'pending', 'failed' ) ) && $order->needs_payment() && ! $order->is_paid();
	}

	private static function owned( \WC_Order $order, int $user_id ): bool {
		return $user_id > 0 ? $order->get_customer_id() === $user_id : 0 === $order->get_customer_id();
	}

	/** Store only a bounded capability in the existing server-side Woo session. */
	public static function remember( $order ): void {
		try { self::remember_owned( $order ); }
		catch ( \Throwable $ignored ) { /* Discovery must never prevent checkout or payment. */ }
	}

	private static function remember_owned( $order ): void {
		$session = self::session();
		if ( ! $session || ! self::eligible( $order ) || ! self::owned( $order, get_current_user_id() ) ) { return; }
		$key = $order->get_order_key();
		if ( ! is_string( $key ) || '' === $key || strlen( $key ) > 100 ) { return; }
		$entries = $session->get( self::SESSION_KEY, array() );
		$entries = is_array( $entries ) ? $entries : array();
		$entries = array_values( array_filter( $entries, static fn( $entry ) => is_array( $entry ) && ( $entry['id'] ?? 0 ) !== $order->get_id() ) );
		array_unshift( $entries, array( 'id' => $order->get_id(), 'key' => $key, 'at' => time() ) );
		$session->set( self::SESSION_KEY, array_slice( $entries, 0, self::MAX_REMEMBERED ) );
	}

	/** Existing sessions created before this UI can still discover their own native order. */
	private static function remember_native_pointers(): void {
		$session = self::session();
		if ( ! $session ) { return; }
		foreach ( array( 'order_awaiting_payment', 'store_api_draft_order' ) as $pointer ) {
			$id = self::pointer_id( $session->get( $pointer ) );
			if ( $id > 0 ) {
				self::remember( wc_get_order( $id ) );
			}
		}
	}

	private static function pointer_id( $value ): int {
		if ( is_int( $value ) ) { return $value > 0 ? $value : 0; }
		return is_string( $value ) && preg_match( '/\A[1-9][0-9]{0,18}\z/D', $value )
			&& (string) (int) $value === $value ? (int) $value : 0;
	}

	/** Read native orders; never call Session_Service::start or a payment endpoint. */
	public static function visible_orders(): array {
		try { return self::discover(); }
		catch ( \Throwable $ignored ) { return array(); }
	}

	private static function discover(): array {
		$user_id = get_current_user_id();
		$orders = array();
		if ( $user_id > 0 ) {
			$candidates = wc_get_orders( array( 'customer_id' => $user_id, 'status' => array( 'pending', 'failed' ), 'limit' => 20, 'orderby' => 'date', 'order' => 'DESC' ) );
			foreach ( $candidates as $order ) {
				if ( self::eligible( $order ) && self::owned( $order, $user_id ) ) { $orders[ $order->get_id() ] = $order; }
				if ( count( $orders ) >= self::MAX_DISPLAYED ) { break; }
			}
			return array_values( $orders );
		}
		self::remember_native_pointers();
		$session = self::session();
		$entries = $session ? $session->get( self::SESSION_KEY, array() ) : array();
		if ( ! is_array( $entries ) ) { return array(); }
		foreach ( array_slice( $entries, 0, self::MAX_REMEMBERED ) as $entry ) {
			if ( ! is_array( $entry ) || ! is_int( $entry['id'] ?? null ) || $entry['id'] <= 0
				|| ! is_string( $entry['key'] ?? null ) || '' === $entry['key'] || strlen( $entry['key'] ) > 100
				|| ! is_int( $entry['at'] ?? null ) || $entry['at'] < time() - self::MAX_AGE || $entry['at'] > time() + 60 ) { continue; }
			$order = wc_get_order( $entry['id'] );
			if ( ! self::eligible( $order ) || ! self::owned( $order, 0 ) || ! hash_equals( $order->get_order_key(), $entry['key'] ) ) { continue; }
			$orders[ $order->get_id() ] = $order;
			if ( count( $orders ) >= self::MAX_DISPLAYED ) { break; }
		}
		return array_values( $orders );
	}

	/** An authenticated native order URL, not the provider URL, is the only payable link. */
	public static function continuation( \WC_Order $order ): ?string {
		try {
			$book = $order->get_meta( '_pc_panapay_sessions', true );
			if ( '' === $book ) { return Order_Payload::customer_url( $order->get_checkout_payment_url() ); }
			if ( ! is_array( $book ) || ! is_array( $book['attempts'] ?? null ) || ! is_string( $book['active'] ?? null ) ) { return null; }
			$attempt = $book['attempts'][ $book['active'] ] ?? null;
			if ( ! is_array( $attempt ) ) { return null; }
			if ( 'failed' === ( $attempt['state'] ?? null ) ) { return Order_Payload::customer_url( $order->get_checkout_payment_url() ); }
			if ( 'created' !== ( $attempt['state'] ?? null ) ) { return null; }
			Session_Service::active_session( $order );
			return Order_Payload::customer_url( $order->get_checkout_payment_url( true ) );
		} catch ( \Throwable $ignored ) { return null; }
	}

	private static function target_page(): bool {
		return ! is_admin() && ( is_cart() || is_checkout() || is_account_page() )
			&& ! is_wc_endpoint_url( 'order-pay' ) && ! is_wc_endpoint_url( 'order-received' );
	}

	public static function protect_page(): void {
		if ( ! self::target_page() ) { return; }
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'Referrer-Policy: no-referrer', true );
			header( 'X-Robots-Tag: noindex, nofollow', true );
		}
	}

	public static function enqueue(): void {
		if ( ! self::target_page() ) { return; }
		$path = dirname( __DIR__ ) . '/assets/pending-orders.css';
		wp_enqueue_style( 'pc-panapay-pending-orders', plugins_url( 'assets/pending-orders.css', PEPTIDE_PANAPAY_FILE ), array(), (string) filemtime( $path ) );
	}

	/** Runs after the theme's native Cart-block fallback wrapper. */
	public static function prepend( string $html ): string {
		return self::markup() . $html;
	}

	public static function classic(): void {
		$id = get_queried_object_id();
		if ( has_block( 'woocommerce/cart', $id ) || has_block( 'woocommerce/checkout', $id ) ) { return; }
		self::render();
	}

	public static function render(): void {
		echo self::markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in markup.
	}

	public static function markup(): string {
		try { return self::build_markup(); }
		catch ( \Throwable $ignored ) { return ''; }
	}

	private static function build_markup(): string {
		if ( self::$rendered || ! self::target_page() ) { return ''; }
		$orders = self::visible_orders();
		if ( ! $orders ) { return ''; }
		self::$rendered = true;
		$html = '<section class="pc-panapay-pending" aria-labelledby="pc-panapay-pending-title"><h2 id="pc-panapay-pending-title" class="pc-panapay-pending__title">'
			. esc_html__( 'Your unpaid orders', 'peptide-panapay' ) . '</h2><p class="pc-panapay-pending__intro">'
			. esc_html__( 'Return to an existing order, or continue below to place a new order.', 'peptide-panapay' ) . '</p><ul class="pc-panapay-pending__list">';
		foreach ( $orders as $order ) {
			$url = self::continuation( $order );
			$html .= '<li><div class="pc-panapay-pending__summary"><strong>' . esc_html( sprintf( __( 'Order #%s', 'peptide-panapay' ), $order->get_order_number() ) ) . '</strong><span>'
				. wp_kses_post( $order->get_formatted_order_total() ) . '</span><span class="pc-panapay-pending__status">'
				. esc_html__( 'Awaiting payment', 'peptide-panapay' ) . '</span></div><div class="pc-panapay-pending__actions">';
			if ( get_current_user_id() > 0 ) {
				$html .= '<a href="' . esc_url( Order_Payload::customer_url( $order->get_view_order_url() ) ) . '">' . esc_html__( 'View order', 'peptide-panapay' ) . '</a>';
			}
			if ( null !== $url ) {
				$html .= '<a class="button" href="' . esc_url( $url ) . '" rel="noreferrer">' . esc_html__( 'Continue payment', 'peptide-panapay' ) . '</a>';
			} else {
				$html .= '<span class="pc-panapay-pending__detail">' . esc_html__( 'This payment needs confirmation. You can still place a new order.', 'peptide-panapay' ) . '</span>';
			}
			$html .= '</div></li>';
		}
		$new_checkout = class_exists( Checkout_Lifecycle::class ) ? Checkout_Lifecycle::new_checkout_url() : wc_get_checkout_url();
		$html .= '</ul><a class="pc-panapay-pending__new" href="' . esc_url( $new_checkout ) . '">' . esc_html__( 'Start a new order', 'peptide-panapay' ) . '</a></section>';
		return $html;
	}
}
