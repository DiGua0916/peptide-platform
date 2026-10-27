<?php
/**
 * 交易条款（TERMS-01），对照 Shopname“系统设置 → 交易条款”：开启后，买家必须勾选所有条款。
 *
 * 设置：标题名称 + 若干方案。每个方案是一个复选框：条款描述（复选框文字）、显示页面（结账页 / 注册页 / 登录页）、
 * 是否默认勾选，以及若干条款（名称 + 内容）。条款名称显示成链接，点开弹窗看内容。
 *
 * - 结账页（区块结账）：每个方案注册为 WooCommerce 的必选附加字段（Additional Checkout Fields），服务端校验；
 *   默认勾选用 woocommerce_get_default_value_for_<字段>；条款链接和弹窗由 assets/terms.js 加在复选框下面。
 *   下单时在订单上保存当时的条款快照 _pc_terms（描述、条款名称、勾选时间，UTC），后台订单页显示。
 * - 注册页、登录页（“我的账户”的 WooCommerce 表单）：服务端输出复选框和弹窗，提交时校验。
 * - 选项 peptide_core_terms。条款内容只允许 wp_kses_post 允许的 HTML。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Terms {

	public const OPTION     = 'peptide_core_terms';
	public const FIELD      = 'peptide-core/terms-';
	public const META       = '_pc_terms';
	public const PAGES      = array( 'checkout', 'register', 'login' );
	public const TITLE_MAX  = 40;
	public const DESC_MAX   = 100;
	public const NAME_MAX   = 30;

	public static function register_hooks(): void {
		add_action( 'woocommerce_init', array( self::class, 'register_fields' ) );
		add_action( 'woocommerce_set_additional_field_value', array( self::class, 'save_snapshot' ), 10, 4 );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( self::class, 'render_admin' ) );
		add_action( 'woocommerce_register_form', array( self::class, 'render_register' ), 20 );
		add_filter( 'woocommerce_registration_errors', array( self::class, 'validate_register' ), 20 );
		add_action( 'woocommerce_login_form', array( self::class, 'render_login' ), 20 );
		add_filter( 'woocommerce_process_login_errors', array( self::class, 'validate_login' ), 20 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	// ---------- 数据 ----------

	public static function defaults(): array {
		return array( 'enabled' => false, 'title' => '', 'plans' => array() );
	}

	public static function all(): array {
		$o = get_option( self::OPTION, array() );
		return self::normalize( is_array( $o ) ? $o : array() );
	}

	private static function cut( string $s, int $max ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max ) : substr( $s, 0, $max );
	}

	/** 标准形式（同时用于清理后台提交的内容）。 */
	public static function normalize( array $in ): array {
		$plans = array();
		$ids   = array();
		$raw   = array_values( (array) ( $in['plans'] ?? array() ) );
		// 新方案（或重复的 ID）编号为已有最大 ID + 1。
		$next  = 1 + max( array( 0 ) + array_map( static fn( $p ) => absint( $p['id'] ?? 0 ), $raw ) );
		foreach ( $raw as $p ) {
			$terms = array();
			foreach ( array_values( (array) ( $p['terms'] ?? array() ) ) as $t ) {
				$name    = self::cut( trim( sanitize_text_field( (string) ( $t['name'] ?? '' ) ) ), self::NAME_MAX );
				$content = trim( wp_kses_post( (string) ( $t['content'] ?? '' ) ) );
				if ( '' === $name && '' === wp_strip_all_tags( $content ) ) {
					continue;
				}
				$terms[] = array( 'name' => $name, 'content' => $content );
			}
			$desc  = self::cut( trim( sanitize_text_field( (string) ( $p['description'] ?? '' ) ) ), self::DESC_MAX );
			if ( '' === $desc && ! $terms ) {
				continue;
			}
			$id = absint( $p['id'] ?? 0 );
			if ( ! $id || isset( $ids[ $id ] ) ) {
				$id = $next++;
			}
			$ids[ $id ] = true;
			$pages      = array_values( array_intersect( self::PAGES, array_map( 'strval', (array) ( $p['pages'] ?? array( 'checkout' ) ) ) ) );
			$plans[]    = array(
				'id'          => $id,
				'description' => $desc,
				'pages'       => $pages,
				'checked'     => ! empty( $p['checked'] ) && 'false' !== $p['checked'],
				'terms'       => $terms,
			);
		}
		return array(
			'enabled' => ! empty( $in['enabled'] ) && 'false' !== $in['enabled'],
			'title'   => self::cut( trim( sanitize_text_field( (string) ( $in['title'] ?? '' ) ) ), self::TITLE_MAX ),
			'plans'   => $plans,
		);
	}

	public static function save( array $in ): array {
		$s = self::normalize( $in );
		update_option( self::OPTION, $s );
		return $s;
	}

	public static function reset(): void {
		delete_option( self::OPTION );
	}

	/** 某个页面上生效的方案（未开启时为空）。 */
	public static function plans_for( string $page ): array {
		$s = self::all();
		if ( ! $s['enabled'] ) {
			return array();
		}
		return array_values( array_filter( $s['plans'], static fn( $p ) => in_array( $page, $p['pages'], true ) && '' !== $p['description'] ) );
	}

	// ---------- 结账页（区块结账） ----------

	public static function register_fields(): void {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		foreach ( self::plans_for( 'checkout' ) as $p ) {
			$key = self::FIELD . $p['id'];
			woocommerce_register_additional_checkout_field(
				array(
					'id'            => $key,
					'label'         => $p['description'],
					'location'      => 'order',
					'type'          => 'checkbox',
					'required'      => true,
					/* translators: %s: the checkbox text */
					'error_message' => sprintf( __( 'Please accept: %s', 'peptide-core' ), $p['description'] ),
				)
			);
			if ( $p['checked'] ) {
				add_filter( 'woocommerce_get_default_value_for_' . $key, static fn( $v ) => null === $v ? true : $v );
			}
		}
	}

	/**
	 * 勾选后在订单上保存条款快照（以后修改条款也不影响旧订单）。
	 *
	 * @param mixed $value
	 * @param mixed $wc_object
	 */
	public static function save_snapshot( string $key, $value, string $group, $wc_object ): void {
		if ( ! str_starts_with( $key, self::FIELD ) || ! $wc_object instanceof \WC_Order || ! rest_sanitize_boolean( $value ) ) {
			return;
		}
		$id = (int) substr( $key, strlen( self::FIELD ) );
		foreach ( self::plans_for( 'checkout' ) as $p ) {
			if ( $p['id'] === $id ) {
				$snap        = $wc_object->get_meta( self::META );
				$snap        = is_array( $snap ) ? $snap : array();
				$snap[ $id ] = array(
					'description' => $p['description'],
					'terms'       => array_column( $p['terms'], 'name' ),
					'accepted_at' => gmdate( 'c' ),
				);
				$wc_object->update_meta_data( self::META, $snap );
			}
		}
	}

	public static function render_admin( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$snap = $order->get_meta( self::META );
		if ( ! is_array( $snap ) || ! $snap ) {
			return;
		}
		echo '<p class="form-field form-field-wide pc-terms-accepted"><strong>' . esc_html__( 'Terms accepted at checkout', 'peptide-core' ) . '</strong>';
		foreach ( $snap as $s ) {
			$names = array_filter( (array) ( $s['terms'] ?? array() ) );
			echo '<br>' . esc_html( (string) ( $s['description'] ?? '' ) ) . ( $names ? ' <small>(' . esc_html( implode( ', ', $names ) ) . ')</small>' : '' );
			/* translators: %s: date and time */
			echo '<br><small>' . esc_html( sprintf( __( 'Accepted at %s (UTC)', 'peptide-core' ), (string) ( $s['accepted_at'] ?? '' ) ) ) . '</small>';
		}
		echo '</p>';
	}

	// ---------- 注册页、登录页 ----------

	/** 复选框 + 条款链接 + 弹窗（服务端输出，注册页 / 登录页和后台预览共用）。 */
	public static function checkboxes_html( string $page, bool $preview = false ): string {
		$plans = $preview ? self::all()['plans'] : self::plans_for( $page );
		if ( ! $plans ) {
			return '';
		}
		$title = self::all()['title'];
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- 只用于表单提交失败后保持勾选状态，校验在 validate()。
		$posted = isset( $_POST['pc_terms'] ) && is_array( $_POST['pc_terms'] ) ? array_map( 'absint', array_keys( wp_unslash( $_POST['pc_terms'] ) ) ) : null;
		$html   = '<div class="pc-terms" data-page="' . esc_attr( $page ) . '">' . ( '' !== $title ? '<p class="pc-terms__title"><strong>' . esc_html( $title ) . '</strong></p>' : '' );
		foreach ( $plans as $p ) {
			$checked = null === $posted ? $p['checked'] : in_array( $p['id'], $posted, true );
			$html   .= '<p class="pc-terms__row form-row"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox"><input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox" name="pc_terms[' . (int) $p['id'] . ']" value="1"' . checked( $checked, true, false ) . ' required> <span>' . esc_html( $p['description'] ) . '</span> <abbr class="required" title="' . esc_attr__( 'required', 'peptide-core' ) . '">*</abbr></label>';
			$html   .= self::links_html( $p ) . '</p>';
		}
		return $html . '</div>' . self::dialogs_html( $plans );
	}

	private static function links_html( array $p ): string {
		$links = array();
		foreach ( $p['terms'] as $i => $t ) {
			if ( '' !== $t['name'] ) {
				$links[] = '<button type="button" class="pc-terms__link" data-pc-terms="pc-terms-' . (int) $p['id'] . '-' . (int) $i . '">' . esc_html( $t['name'] ) . '</button>';
			}
		}
		return $links ? '<span class="pc-terms__links">' . implode( ' · ', $links ) . '</span>' : '';
	}

	private static function dialogs_html( array $plans ): string {
		$html = '';
		foreach ( $plans as $p ) {
			foreach ( $p['terms'] as $i => $t ) {
				$html .= '<dialog class="pc-terms__dialog" id="pc-terms-' . (int) $p['id'] . '-' . (int) $i . '" aria-label="' . esc_attr( $t['name'] ) . '"><div class="pc-terms__dialog-body"><h3>' . esc_html( $t['name'] ) . '</h3>' . wp_kses_post( wpautop( $t['content'] ) ) . '</div><form method="dialog"><button class="button pc-terms__close">' . esc_html__( 'Close', 'peptide-core' ) . '</button></form></dialog>';
			}
		}
		return $html;
	}

	public static function render_register(): void {
		echo self::checkboxes_html( 'register' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 已转义。
	}

	public static function render_login(): void {
		echo self::checkboxes_html( 'login' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 已转义。
	}

	/** 没勾选的方案。 */
	public static function missing( string $page, array $posted ): array {
		$ticked = array_map( 'absint', array_keys( array_filter( $posted ) ) );
		return array_values( array_filter( self::plans_for( $page ), static fn( $p ) => ! in_array( $p['id'], $ticked, true ) ) );
	}

	/** @param \WP_Error $errors */
	public static function validate_register( $errors ) {
		return self::add_errors( $errors, 'register' );
	}

	/** @param \WP_Error $errors */
	public static function validate_login( $errors ) {
		return self::add_errors( $errors, 'login' );
	}

	/** @param \WP_Error $errors */
	private static function add_errors( $errors, string $page ) {
		if ( ! $errors instanceof \WP_Error ) {
			return $errors;
		}
		$posted = isset( $_POST['pc_terms'] ) && is_array( $_POST['pc_terms'] ) ? wp_unslash( $_POST['pc_terms'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WooCommerce 已校验表单 nonce；这里只读取勾选的键。
		foreach ( self::missing( $page, (array) $posted ) as $p ) {
			/* translators: %s: the checkbox text */
			$errors->add( 'pc_terms_' . $p['id'], sprintf( __( 'Please accept: %s', 'peptide-core' ), $p['description'] ) );
		}
		return $errors;
	}

	// ---------- 前台资源 ----------

	public static function enqueue(): void {
		$checkout = function_exists( 'is_checkout' ) && is_checkout() && self::plans_for( 'checkout' );
		$account  = function_exists( 'is_account_page' ) && is_account_page() && ! is_user_logged_in() && ( self::plans_for( 'register' ) || self::plans_for( 'login' ) );
		if ( ! $checkout && ! $account ) {
			return;
		}
		$ver = static fn( string $f ): string => PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( PEPTIDE_CORE_DIR . '/assets/' . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_enqueue_style( 'peptide-core-terms', plugins_url( 'assets/terms.css', PEPTIDE_CORE_FILE ), array(), $ver( 'terms.css' ) );
		wp_enqueue_script( 'peptide-core-terms', plugins_url( 'assets/terms.js', PEPTIDE_CORE_FILE ), array(), $ver( 'terms.js' ), true );
		if ( $checkout ) {
			$data = array();
			foreach ( self::plans_for( 'checkout' ) as $p ) {
				$data[] = array(
					'field' => 'order-' . self::FIELD . $p['id'],
					'terms' => array_map( static fn( $t ) => array( 'name' => $t['name'], 'html' => wp_kses_post( wpautop( $t['content'] ) ) ), array_values( array_filter( $p['terms'], static fn( $t ) => '' !== $t['name'] ) ) ),
				);
			}
			wp_localize_script( 'peptide-core-terms', 'pcTerms', array( 'checkout' => $data, 'close' => __( 'Close', 'peptide-core' ) ) );
		}
	}
}
