<?php
/**
 * 订单详情页（店主 2026-11-11：按原来用的 SaaS 后台订单详情排版复刻）。
 *
 * admin.php?page=pc-order&id=订单ID。左边：订单头（状态 / 支付 / 发货）、商品、金额明细、收货 / 账单地址、支付信息、物流、任务（邮件）；
 * 右边：购买人、订单基础信息、订单备注（卖家备注可改）、订单标签、订单归因、设备信息、IP 信息。
 * 退款、发货、改地址仍用 WooCommerce 原生订单页（按钮直接跳过去）；作废订单 = 改为已取消。
 * 没有的数据显示“-”，不编造。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Mail_Outbox;
use PeptideCore\Shipping_Tracking;

defined( 'ABSPATH' ) || exit;

final class Order_Detail_Page {

	public const SLUG = 'pc-order';
	public const CAP  = 'manage_woocommerce';
	public const SELLER_NOTE = '_pc_seller_note';
	public const TAGS        = '_pc_order_tags';

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 90 );
		add_action( 'admin_post_pc_order_detail', array( self::class, 'handle' ) );
		// 店主 2026-11-11：所有入口打开订单都用新详情页——WooCommerce 原生订单编辑页（action=edit）一律转到这里；
		// 退款、发货、改地址等需要原生页的按钮带 pc_native=1，不转。
		add_action( 'load-woocommerce_page_wc-orders', array( self::class, 'maybe_redirect' ) );
		add_action( 'load-post.php', array( self::class, 'maybe_redirect' ) );
		// 隐藏页面没有菜单标题：浏览器标签和面包屑显示“订单详情”。
		add_action( 'load-admin_page_' . self::SLUG, static function (): void { $GLOBALS['title'] = '订单详情'; } );
	}

	public static function menu(): void {
		add_submenu_page( '', '订单详情', '订单详情', self::CAP, self::SLUG, array( self::class, 'render' ) );
	}

	public static function maybe_redirect(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) || ! empty( $_GET['pc_native'] ) || ! current_user_can( self::CAP ) ) {
			return;
		}
		$id = 0;
		if ( isset( $_GET['page'] ) && 'wc-orders' === $_GET['page'] && 'edit' === ( $_GET['action'] ?? '' ) ) {
			$id = absint( $_GET['id'] ?? 0 );
		} elseif ( isset( $_GET['post'] ) && 'edit' === ( $_GET['action'] ?? '' ) ) {
			$id = absint( $_GET['post'] );
		}
		// phpcs:enable
		if ( ! $id ) {
			return;
		}
		$o = wc_get_order( $id );
		if ( $o instanceof \WC_Order && 'shop_order' === $o->get_type() ) {
			wp_safe_redirect( self::url( $id ) );
			exit;
		}
	}

	/** 原生订单编辑页（不被转走），退款 / 发货 / 改地址用。 */
	public static function native_url( \WC_Order $o, string $anchor = '' ): string {
		return add_query_arg( 'pc_native', '1', $o->get_edit_order_url() ) . $anchor;
	}

	public static function url( int $id ): string {
		return admin_url( 'admin.php?page=' . self::SLUG . '&id=' . $id );
	}

	/** 作废订单 / 卖家备注 / 订单标签。 */
	public static function handle(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( '没有权限。', '', array( 'response' => 403 ) );
		}
		$id = absint( $_POST['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- 下一行校验。
		check_admin_referer( 'pc_order_detail_' . $id );
		$order = wc_get_order( $id );
		if ( ! $order instanceof \WC_Order ) {
			wp_die( '订单不存在。' );
		}
		$do  = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) );
		$msg = '';
		if ( 'void' === $do ) {
			if ( ! $order->has_status( array( 'cancelled', 'refunded' ) ) ) {
				$order->update_status( 'cancelled', 'Order voided by the store in the order detail page.' );
				$msg = 'voided';
			}
		} elseif ( 'note' === $do ) {
			$order->update_meta_data( self::SELLER_NOTE, mb_substr( sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ), 0, 1000 ) );
			$order->save_meta_data();
			$msg = 'saved';
		} elseif ( 'tag_add' === $do ) {
			$tag  = mb_substr( trim( sanitize_text_field( wp_unslash( $_POST['tag'] ?? '' ) ) ), 0, 30 );
			$tags = self::tags( $order );
			if ( '' !== $tag && ! in_array( $tag, $tags, true ) && count( $tags ) < 20 ) {
				$tags[] = $tag;
				$order->update_meta_data( self::TAGS, $tags );
				$order->save_meta_data();
			}
			$msg = 'saved';
		} elseif ( 'tag_del' === $do ) {
			$tag  = sanitize_text_field( wp_unslash( $_POST['tag'] ?? '' ) );
			$order->update_meta_data( self::TAGS, array_values( array_diff( self::tags( $order ), array( $tag ) ) ) );
			$order->save_meta_data();
			$msg = 'saved';
		}
		wp_safe_redirect( add_query_arg( 'done', $msg, self::url( $id ) ) );
		exit;
	}

	private static function tags( \WC_Order $o ): array {
		$t = $o->get_meta( self::TAGS );
		return is_array( $t ) ? array_values( array_filter( array_map( 'strval', $t ) ) ) : array();
	}

	private static function t( $ts ): string {
		if ( $ts instanceof \WC_DateTime ) {
			$ts = $ts->getTimestamp();
		}
		return $ts ? wp_date( 'Y-m-d H:i:s', (int) $ts ) : '-';
	}

	private static function money( $amount, \WC_Order $o ): string {
		return number_format( (float) $amount, 2 ) . ' ' . $o->get_currency();
	}

	private static function v( $s ): string {
		$s = trim( (string) $s );
		return '' === $s ? '-' : esc_html( $s );
	}

	private static function flag( string $cc ): string {
		$cc = strtoupper( $cc );
		if ( ! preg_match( '/^[A-Z]{2}$/', $cc ) ) {
			return '';
		}
		return mb_chr( 0x1F1E6 + ord( $cc[0] ) - 65 ) . mb_chr( 0x1F1E6 + ord( $cc[1] ) - 65 );
	}

	/** 相邻订单（上一单 = 更早的，下一单 = 更新的）。 */
	private static function neighbor( int $id, bool $newer ): int {
		global $wpdb;
		if ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$t   = $wpdb->prefix . 'wc_orders';
			$sql = $newer ? "SELECT id FROM {$t} WHERE type='shop_order' AND id > %d ORDER BY id ASC LIMIT 1" : "SELECT id FROM {$t} WHERE type='shop_order' AND id < %d ORDER BY id DESC LIMIT 1";
		} else {
			$t   = $wpdb->posts;
			$sql = $newer ? "SELECT ID FROM {$t} WHERE post_type='shop_order' AND ID > %d ORDER BY ID ASC LIMIT 1" : "SELECT ID FROM {$t} WHERE post_type='shop_order' AND ID < %d ORDER BY ID DESC LIMIT 1";
		}
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	private static function status_badge( \WC_Order $o ): array {
		$map = array(
			'pending'    => array( '待付款', 'gray' ),
			'processing' => array( '进行中', 'blue' ),
			'on-hold'    => array( '待确认', 'orange' ),
			'completed'  => array( '已完成', 'green' ),
			'cancelled'  => array( '已取消', 'gray' ),
			'refunded'   => array( '已退款', 'gray' ),
			'failed'     => array( '支付失败', 'red' ),
		);
		return $map[ $o->get_status() ] ?? array( wc_get_order_status_name( $o->get_status() ), 'gray' );
	}

	/** 访客记录（下单时记下的访客 / 访问）。 */
	private static function visitor( \WC_Order $o ): array {
		global $wpdb;
		$vid = (int) $o->get_meta( '_pc_track_visitor' );
		$out = array( 'row' => null, 'os' => '', 'screen' => '', 'country' => '', 'visits' => array() );
		if ( ! $vid || ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'pc_track_visitors' ) ) ) {
			return $out;
		}
		$out['row'] = $wpdb->get_row( $wpdb->prepare( "SELECT id, vid, device, browser, first_ms, last_ms FROM {$wpdb->prefix}pc_track_visitors WHERE id = %d", $vid ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$data = (string) $wpdb->get_var( $wpdb->prepare( "SELECT data FROM {$wpdb->prefix}pc_track_events WHERE visitor_id = %d AND type = 'page_view' ORDER BY id DESC LIMIT 1", $vid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$j    = json_decode( $data, true );
		$env  = $j['page_context']['environment'] ?? array();
		$out['os']      = (string) ( $env['os']['family'] ?? '' );
		$out['screen']  = isset( $env['screen']['width'], $env['screen']['height'] ) ? (int) $env['screen']['width'] . 'X' . (int) $env['screen']['height'] : '';
		$out['country'] = (string) ( $j['page_context']['geo']['country_code'] ?? '' );
		$out['visits']  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT start_ms, utm_source, utm_medium, attribution_host, attribution_path, referrer_host FROM {$wpdb->prefix}pc_track_visits WHERE visitor_id = %d AND start_ms <= %d ORDER BY start_ms DESC LIMIT 6", $vid, ( $o->get_date_created() ? $o->get_date_created()->getTimestamp() * 1000 + 60000 : PHP_INT_MAX ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $out;
	}

	/** 同邮箱的付款订单统计。 */
	private static function buyer_stats( \WC_Order $o ): array {
		$email = (string) $o->get_billing_email();
		if ( '' === $email ) {
			return array( 'count' => 0, 'sum' => 0.0, 'first' => null );
		}
		$ids   = wc_get_orders( array( 'billing_email' => $email, 'status' => wc_get_is_paid_statuses(), 'limit' => 200, 'return' => 'ids', 'type' => 'shop_order' ) );
		$sum   = 0.0;
		$first = null;
		foreach ( $ids as $i ) {
			$x = wc_get_order( $i );
			if ( ! $x ) {
				continue;
			}
			$sum += (float) $x->get_total() - (float) $x->get_total_refunded();
			$p    = $x->get_date_paid();
			if ( $p && ( null === $first || $p->getTimestamp() < $first ) ) {
				$first = $p->getTimestamp();
			}
		}
		return array( 'count' => count( $ids ), 'sum' => $sum, 'first' => $first );
	}

	/** @var array{0:array,1:array}|null 英文国家 / 州名（后台是中文，地址一律显示英文，店主 2026-11-11）。 */
	private static ?array $en = null;

	private static function en_lists(): array {
		if ( null === self::$en ) {
			$keep = static fn( $t, $text ) => $text;
			add_filter( 'gettext_woocommerce', $keep, 999, 2 );
			$dir      = WC()->plugin_path() . '/i18n/';
			self::$en = array( (array) include $dir . 'countries.php', (array) include $dir . 'states.php' );
			remove_filter( 'gettext_woocommerce', $keep, 999 );
		}
		return self::$en;
	}

	public static function en_country( string $cc ): string {
		return '' === $cc ? '' : html_entity_decode( (string) ( self::en_lists()[0][ $cc ] ?? $cc ), ENT_QUOTES );
	}

	public static function en_state( string $cc, string $st ): string {
		return '' === $st ? '' : html_entity_decode( (string) ( self::en_lists()[1][ $cc ][ $st ] ?? $st ), ENT_QUOTES );
	}

	/** 美国属地：WooCommerce 当作国家，地址里按美国写（州 = 属地，国家 = United States），和快递面单一致。 */
	private const US_TERRITORIES = array( 'PR', 'GU', 'VI', 'AS', 'MP', 'UM' );

	/**
	 * 地址表格的行（店主 2026-11-11：项目 / 信息 两列，国家州名一律英文）。
	 *
	 * @return array<string,string>
	 */
	private static function address( \WC_Order $o, string $type ): array {
		$g     = static fn( string $k ): string => trim( (string) $o->{"get_{$type}_{$k}"}() );
		$cc    = strtoupper( $g( 'country' ) );
		$st    = $g( 'state' );
		$state = '' !== $st ? self::en_state( $cc, $st ) . ( strtoupper( $st ) !== strtoupper( self::en_state( $cc, $st ) ) ? '（' . strtoupper( $st ) . '）' : '' ) : '';
		$ctry  = self::en_country( $cc );
		if ( in_array( $cc, self::US_TERRITORIES, true ) ) {
			$state = '' !== $state ? $state : $ctry . '（' . $cc . '）';
			$ctry  = 'United States';
		}
		$phone = 'billing' === $type ? $o->get_billing_phone() : ( $o->get_shipping_phone() ?: $o->get_billing_phone() );
		$rows  = array(
			'姓名'             => trim( $g( 'first_name' ) . ' ' . $g( 'last_name' ) ),
			'名（First Name）' => $g( 'first_name' ),
			'姓（Last Name）'  => $g( 'last_name' ),
			'公司'             => $g( 'company' ),
			'街道地址'         => $g( 'address_1' ),
			'小区／社区'       => $g( 'address_2' ),
			'城市'             => $g( 'city' ),
			'州／地区'         => $state,
			'邮编'             => $g( 'postcode' ),
			'国家'             => $ctry,
			'电话'             => (string) $phone,
		);
		if ( '' === $rows['公司'] ) {
			unset( $rows['公司'] );
		}
		return $rows;
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$id = absint( $_GET['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$o  = $id ? wc_get_order( $id ) : null;
		if ( ! $o instanceof \WC_Order || 'shop_order' !== $o->get_type() ) {
			echo '<div class="wrap"><h1>订单详情</h1><p>订单不存在。</p></div>';
			return;
		}
		$edit   = add_query_arg( 'pc_native', '1', $o->get_edit_order_url() );
		$nonce  = wp_create_nonce( 'pc_order_detail_' . $id );
		$form   = static function ( string $do, string $inner, string $cls = '' ) use ( $id, $nonce ): string {
			return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="' . esc_attr( $cls ) . '"><input type="hidden" name="action" value="pc_order_detail"><input type="hidden" name="id" value="' . (int) $id . '"><input type="hidden" name="_wpnonce" value="' . esc_attr( $nonce ) . '"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">' . $inner . '</form>';
		};
		$prev   = self::neighbor( $id, false );
		$next   = self::neighbor( $id, true );
		$status = self::status_badge( $o );
		$ship   = class_exists( Shipping_Tracking::class ) ? Shipping_Tracking::customer_view( $o ) : null;
		$shipped = ! empty( $ship['shipments'] );
		$back   = admin_url( 'admin.php?page=wc-orders' );
		$copy_ic = '<span class="dashicons dashicons-admin-page"></span>';

		echo '<div class="pc-od">';
		echo '<div class="pc-od-top"><a class="pc-od-back" href="' . esc_url( $back ) . '"><span class="dashicons dashicons-arrow-left-alt"></span> 返回待处理订单列表</a></div>';

		// 标题行。
		echo '<div class="pc-od-head"><div class="pc-od-head__l"><h1>订单详情</h1><a class="pc-od-link" href="' . esc_url( admin_url( 'admin.php?page=pc-manual-order&from_order=' . $id ) ) . '">' . $copy_ic . ' 订单复制</a></div>';
		echo '<div class="pc-od-head__r"><a class="pc-od-btn" href="' . esc_url( $edit . '#woocommerce-order-items' ) . '">退款</a>';
		if ( ! $o->has_status( array( 'cancelled', 'refunded' ) ) ) {
			echo $form( 'void', '<button type="submit" class="pc-od-btn" onclick="return confirm(\'确定作废这个订单？订单会改为“已取消”。\')">作废订单</button>', 'pc-od-inline' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '<a class="pc-od-arrow' . ( $prev ? '' : ' is-off' ) . '" href="' . esc_url( $prev ? self::url( $prev ) : '#' ) . '" title="上一单" aria-label="上一单"><span class="dashicons dashicons-arrow-left-alt2"></span></a>';
		echo '<a class="pc-od-arrow' . ( $next ? '' : ' is-off' ) . '" href="' . esc_url( $next ? self::url( $next ) : '#' ) . '" title="下一单" aria-label="下一单"><span class="dashicons dashicons-arrow-right-alt2"></span></a></div></div>';
		if ( ! empty( $_GET['done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . ( 'voided' === $_GET['done'] ? '订单已作废（已取消）。' : '已保存。' ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		echo '<div class="pc-od-grid"><div class="pc-od-main">';

		// 订单头 + 商品 + 金额。
		echo '<section class="pc-od-card pc-od-card--flush"><div class="pc-od-ohead"><div class="pc-od-ohead__row"><strong class="pc-od-num">' . esc_html( $o->get_order_number() ) . '</strong>';
		echo '<span class="pc-od-badge is-' . esc_attr( $status[1] ) . '"><span class="dashicons dashicons-clipboard"></span>' . esc_html( $status[0] ) . '</span>';
		echo '<span class="pc-od-sep"></span><span class="pc-od-badge ' . ( $o->is_paid() ? 'is-teal' : 'is-gray' ) . '"><span class="dashicons dashicons-money-alt"></span>' . ( $o->is_paid() ? '已支付' : '未支付' ) . '</span>';
		echo '<span class="pc-od-badge ' . ( $shipped ? 'is-green' : 'is-orange' ) . '"><span class="dashicons dashicons-car"></span>' . ( $shipped ? '已发货' : '未发货' ) . '</span>';
		echo '<span class="pc-od-ohead__links"><a class="pc-od-link" href="' . esc_url( $o->get_checkout_order_received_url() ) . '" target="_blank" rel="noopener"><span class="dashicons dashicons-yes-alt"></span>结账成功页</a><a class="pc-od-link" href="' . esc_url( $o->get_view_order_url() ) . '" target="_blank" rel="noopener"><span class="dashicons dashicons-location"></span>订单追踪页</a></span></div>';
		echo '<div class="pc-od-times"><span>创建时间：' . esc_html( self::t( $o->get_date_created() ) ) . '</span><span>支付时间：' . esc_html( self::t( $o->get_date_paid() ) ) . '</span></div></div>';

		echo '<table class="pc-od-items"><thead><tr><th>商品名称</th><th>单价 * 数量</th><th>总价</th></tr></thead><tbody>';
		foreach ( $o->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$p     = $item->get_product();
			$qty   = max( 1, (int) $item->get_quantity() );
			$img   = $p ? $p->get_image( array( 64, 64 ) ) : '';
			$spec  = $p && $p->is_type( 'variation' ) ? wc_get_formatted_variation( $p, true, false, false ) : '';
			$parent = $p && $p->is_type( 'variation' ) ? wc_get_product( $p->get_parent_id() ) : $p;
			echo '<tr><td><div class="pc-od-prod"><span class="pc-od-thumb">' . wp_kses_post( $img ) . '</span><div><strong>' . esc_html( $parent ? $parent->get_name() : $item->get_name() ) . '</strong>';
			echo '<small>SPU: ' . esc_html( (string) ( $item->get_product_id() ?: '-' ) ) . '</small><small>SKU: ' . self::v( $p ? $p->get_sku() : '' ) . '</small>';
			if ( '' !== $spec ) {
				echo '<small>Specifications: ' . esc_html( $spec ) . '</small>';
			}
			echo '</div></div></td><td>' . esc_html( self::money( (float) $item->get_subtotal() / $qty, $o ) . ' * ' . $qty ) . '</td><td>' . esc_html( self::money( $item->get_subtotal(), $o ) ) . '</td></tr>';
		}
		echo '</tbody></table>';

		// 金额明细。
		$rows = array( array( '商品价格', self::money( $o->get_subtotal(), $o ) ) );
		$rows[] = array( '<a href="' . esc_url( $edit . '#woocommerce-order-items' ) . '">查看明细</a> 运费', self::money( $o->get_shipping_total(), $o ) );
		foreach ( $o->get_items( 'coupon' ) as $c ) {
			$rows[] = array( '（' . esc_html( strtoupper( $c->get_code() ) ) . '）优惠码', '-' . self::money( $c->get_discount(), $o ) );
		}
		foreach ( $o->get_fees() as $fee ) {
			$amt    = (float) $fee->get_total();
			$rows[] = array( $amt < 0 ? '（' . esc_html( $fee->get_name() ) . '）活动优惠' : esc_html( $fee->get_name() ), ( $amt < 0 ? '-' : '' ) . self::money( abs( $amt ), $o ) );
		}
		$rows[] = array( '税费', self::money( $o->get_total_tax(), $o ) );
		if ( (float) $o->get_total_refunded() > 0 ) {
			$rows[] = array( '已退款', '-' . self::money( $o->get_total_refunded(), $o ) );
		}
		echo '<div class="pc-od-totals">';
		foreach ( $rows as $r ) {
			echo '<div><span>' . $r[0] . '</span><b>' . esc_html( $r[1] ) . '</b></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 标签已转义。
		}
		echo '<div class="is-total"><span>订单总价</span><b>' . esc_html( self::money( $o->get_total(), $o ) ) . '</b></div></div></section>';

		// 地址。
		echo '<div class="pc-od-two">';
		foreach ( array( 'shipping' => '收货地址', 'billing' => '账单地址' ) as $type => $label ) {
			$a    = self::address( $o, $type );
			$copy = implode( "\n", array_filter( array( $a['姓名'], $a['公司'] ?? '', $a['街道地址'], $a['小区／社区'], trim( $a['城市'] . ', ' . $a['州／地区'] . ' ' . $a['邮编'], ', ' ), $a['国家'], $a['电话'] ) ) );
			echo '<section class="pc-od-card"><div class="pc-od-ct"><h2>' . esc_html( $label ) . '</h2><span><button type="button" class="pc-od-link" data-pc-copy="' . esc_attr( $copy ) . '">' . $copy_ic . '复制</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			if ( 'shipping' === $type ) {
				echo '<a class="pc-od-link" href="' . esc_url( $edit . '#order_data' ) . '"><span class="dashicons dashicons-edit"></span>编辑</a>';
			}
			echo '</span></div><table class="pc-od-addr"><thead><tr><th>项目</th><th>信息</th></tr></thead><tbody>';
			foreach ( $a as $k => $val ) {
				if ( '电话' === $k && '' !== $val ) {
					$digits = preg_replace( '/\D/', '', $val );
					echo '<tr><td>' . esc_html( $k ) . '</td><td>' . esc_html( $val ) . ( $digits ? ' <a class="pc-od-wa" href="' . esc_url( 'https://wa.me/' . $digits ) . '" target="_blank" rel="noopener" title="WhatsApp">WhatsApp</a>' : '' ) . '</td></tr>';
				} else {
					echo '<tr><td>' . esc_html( $k ) . '</td><td>' . self::v( $val ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
			}
			echo '</tbody></table></section>';
		}
		echo '</div>';

		// 支付信息。
		$book     = $o->get_meta( '_pc_panapay_sessions' );
		$attempts = is_array( $book['attempts'] ?? null ) ? $book['attempts'] : array();
		echo '<section class="pc-od-card"><div class="pc-od-ct"><h2>支付信息</h2>' . ( $attempts ? '<button type="button" class="pc-od-btn" data-pc-toggle="pc-od-attempts">支付记录</button>' : '' ) . '</div>';
		echo '<table class="pc-od-table"><thead><tr><th>流水号</th><th>商家账号</th><th>收款方式</th><th>备注</th><th>时间</th></tr></thead><tbody>';
		if ( $o->get_transaction_id() || $o->is_paid() ) {
			echo '<tr><td>' . self::v( $o->get_transaction_id() ) . '</td><td>' . self::v( strtoupper( (string) $o->get_payment_method_title() ) ) . '</td><td>' . self::v( $o->get_payment_method_title() ) . '</td><td>' . ( $o->is_paid() ? 'Payment succeeded' : esc_html( wc_get_order_status_name( $o->get_status() ) ) ) . '</td><td>' . esc_html( self::t( $o->get_date_paid() ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			echo '<tr><td colspan="5" class="pc-od-empty">暂无支付记录</td></tr>';
		}
		echo '</tbody></table>';
		if ( $attempts ) {
			$state = array( 'creating' => '创建中', 'created' => '已打开收银台', 'confirmed' => '已确认', 'paid' => '支付成功', 'failed' => '支付失败', 'unknown' => '结果待核对' );
			echo '<table class="pc-od-table" id="pc-od-attempts" hidden><thead><tr><th>第几次</th><th>支付单号</th><th>状态</th><th>时间</th></tr></thead><tbody>';
			$n = 0;
			foreach ( $attempts as $at ) {
				++$n;
				echo '<tr><td>' . (int) $n . '</td><td>' . self::v( $at['pp_ref'] ?? ( $at['reference'] ?? '' ) ) . '</td><td>' . esc_html( $state[ $at['state'] ?? '' ] ?? (string) ( $at['state'] ?? '-' ) ) . '</td><td>' . esc_html( self::t( (int) ( $at['created_at'] ?? 0 ) ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</tbody></table>';
		}
		echo '</section>';

		// 物流信息。
		echo '<section class="pc-od-card"><div class="pc-od-ct"><h2>物流信息</h2><a class="pc-od-link" href="' . esc_url( $edit . '#woocommerce-order-fulfillments' ) . '"><span class="dashicons dashicons-car"></span>发货</a></div>';
		if ( $shipped ) {
			echo '<table class="pc-od-table"><thead><tr><th>承运商</th><th>物流单号</th><th>发货时间</th><th>追踪</th></tr></thead><tbody>';
			foreach ( $ship['shipments'] as $sh ) {
				echo '<tr><td>' . self::v( $sh['provider_name'] ?? '' ) . '</td><td>' . self::v( $sh['tracking_number'] ?? '' ) . '</td><td>' . esc_html( ! empty( $sh['shipped_at'] ) ? self::t( strtotime( (string) $sh['shipped_at'] ) ) : '-' ) . '</td><td>' . ( ! empty( $sh['tracking_url'] ) ? '<a href="' . esc_url( $sh['tracking_url'] ) . '" target="_blank" rel="noopener">查看</a>' : '-' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</tbody></table>';
		} else {
			echo '<div class="pc-od-none"><span class="dashicons dashicons-archive"></span><p>暂无物流信息</p></div>';
		}
		echo '</section>';

		// 任务列表（这单发过的邮件）。
		global $wpdb;
		$mails = class_exists( Mail_Outbox::class ) && Mail_Outbox::installed() ? (array) $wpdb->get_results( $wpdb->prepare( 'SELECT kind, subject, status, error, created_ms FROM ' . Mail_Outbox::table() . ' WHERE order_id = %d ORDER BY id DESC LIMIT 50', $id ), ARRAY_A ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		echo '<section class="pc-od-card"><div class="pc-od-ct"><h2>任务列表</h2><a class="pc-od-btn" href="' . esc_url( Mail_Page::compose_url( array( 'order_id' => $id, 'to' => $o->get_billing_email() ) ) ) . '">手动发送邮件</a></div>';
		echo '<table class="pc-od-table"><thead><tr><th>任务名称</th><th>标题</th><th>类型</th><th>状态</th><th>结果</th><th>时间</th></tr></thead><tbody>';
		if ( ! $mails ) {
			echo '<tr><td colspan="6" class="pc-od-empty">暂无任务</td></tr>';
		}
		foreach ( $mails as $m ) {
			$ok = 'sent' === $m['status'];
			echo '<tr><td>' . esc_html( Mail_Outbox::kind_label( (string) $m['kind'] ) ) . '</td><td>' . esc_html( (string) $m['subject'] ) . '</td><td>邮件</td><td><span class="pc-od-badge ' . ( $ok ? 'is-teal' : 'is-red' ) . '">' . ( $ok ? '成功' : '失败' ) . '</span></td><td>' . esc_html( $ok ? 'success' : wp_html_excerpt( (string) $m['error'], 60, '…' ) ) . '</td><td>' . esc_html( self::t( (int) floor( (int) $m['created_ms'] / 1000 ) ) ) . '</td></tr>';
		}
		echo '</tbody></table></section>';

		echo '</div><aside class="pc-od-side">';

		// 购买人信息。
		$v     = self::visitor( $o );
		$stats = self::buyer_stats( $o );
		$cc    = $o->get_billing_country();
		$email = (string) $o->get_billing_email();
		echo '<section class="pc-od-card"><h2>购买人信息</h2><p class="pc-od-buyer"><span class="pc-od-flag">' . esc_html( self::flag( $cc ) ) . '</span>' . self::v( trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ) ) . '</p><dl class="pc-od-kv">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<dt>邮箱：</dt><dd class="pc-od-mail"><a href="' . esc_url( Mail_Page::url( array( 'q' => $email ) ) ) . '">' . self::v( $email ) . '</a> <button type="button" class="pc-od-icon" data-pc-copy="' . esc_attr( $email ) . '" title="复制" aria-label="复制邮箱">' . $copy_ic . '</button></dd>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<dt>其他联系方式：</dt><dd>' . self::v( $o->get_meta( '_pc_paid_contact' ) ) . '</dd></dl><hr class="pc-od-hr"><dl class="pc-od-kv">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<dt>首次支付时间：</dt><dd>' . esc_html( self::t( $stats['first'] ) ) . '</dd>';
		echo '<dt>最后访问时间：</dt><dd>' . esc_html( $v['row'] ? self::t( (int) floor( (int) $v['row']['last_ms'] / 1000 ) ) : '-' ) . '</dd>';
		echo '<dt>总计付款订单：</dt><dd>' . (int) $stats['count'] . '</dd>';
		echo '<dt>总计消费金额：</dt><dd>' . esc_html( self::money( $stats['sum'], $o ) ) . '</dd>';
		echo '<dt>顾客备注：</dt><dd>' . self::v( $o->get_user() ? get_user_meta( $o->get_user_id(), 'description', true ) : '' ) . '</dd></dl></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		// 订单基础信息。
		$via  = array( 'checkout' => '购物车结账', 'store-api' => '购物车结账', 'admin' => '后台创建', 'pc-manual' => '手动订单', 'rest-api' => '接口创建' );
		$host = '';
		foreach ( $v['visits'] as $vis ) {
			if ( ! empty( $vis['attribution_host'] ) ) {
				$host = (string) $vis['attribution_host'];
				break;
			}
		}
		echo '<section class="pc-od-card"><h2>订单基础信息</h2><dl class="pc-od-kv">';
		echo '<dt>支付方式：</dt><dd>' . self::v( $o->get_payment_method_title() ) . '</dd>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<dt>配送方式：</dt><dd>' . self::v( $o->get_shipping_method() ) . ' <a class="pc-od-link" href="' . esc_url( $edit . '#woocommerce-order-items' ) . '"><span class="dashicons dashicons-edit"></span>编辑</a></dd>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<dt>下单类型：</dt><dd>' . esc_html( $via[ $o->get_created_via() ] ?? ( $o->get_created_via() ?: '-' ) ) . '</dd>';
		echo '<dt>结账流程：</dt><dd>单页结账</dd>';
		echo '<dt>域名来源：</dt><dd>' . self::v( $host ?: (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) . '</dd>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<dt>客服信息：</dt><dd>-</dd></dl></section>';

		// 订单备注。
		echo '<section class="pc-od-card"><h2>订单备注</h2><dl class="pc-od-kv"><dt>买家备注：</dt><dd>' . self::v( $o->get_customer_note() ) . '</dd><dt>卖家备注：</dt><dd>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $form( 'note', '<textarea name="note" rows="2" placeholder="只有店铺能看到">' . esc_textarea( (string) $o->get_meta( self::SELLER_NOTE ) ) . '</textarea><button type="submit" class="button button-small">保存</button>', 'pc-od-note' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</dd></dl></section>';

		// 订单标签。
		echo '<section class="pc-od-card"><h2>订单标签</h2><div class="pc-od-tags">';
		foreach ( self::tags( $o ) as $tag ) {
			echo $form( 'tag_del', '<input type="hidden" name="tag" value="' . esc_attr( $tag ) . '"><span class="pc-od-tag">' . esc_html( $tag ) . '<button type="submit" aria-label="删除标签">&times;</button></span>', 'pc-od-inline' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div>' . $form( 'tag_add', '<input type="text" name="tag" maxlength="30" placeholder="输入标签后回车可创建标签">', 'pc-od-tagform' ) . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		// 订单归因。
		$utm = array_filter( array( $o->get_meta( '_wc_order_attribution_utm_source' ), $o->get_meta( '_wc_order_attribution_utm_medium' ), $o->get_meta( '_wc_order_attribution_utm_id' ) ?: $o->get_meta( '_wc_order_attribution_utm_campaign' ), $o->get_meta( '_wc_order_attribution_utm_content' ), $o->get_meta( '_wc_order_attribution_utm_term' ) ) );
		echo '<section class="pc-od-card"><h2>订单归因</h2><ul class="pc-od-timeline"><li><span>' . self::v( $utm ? implode( '/', $utm ) : ( $o->get_meta( '_wc_order_attribution_source_type' ) ?: '' ) ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<dl class="pc-od-kv"><dt>落地页：</dt><dd class="pc-od-break">' . self::v( $o->get_meta( '_wc_order_attribution_session_entry' ) ) . '</dd></dl></li></ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<h3>归因模型</h3><ul class="pc-od-timeline">';
		if ( ! $v['visits'] ) {
			echo '<li><span>-</span></li>';
		}
		foreach ( array_reverse( $v['visits'] ) as $vis ) {
			$src = array_filter( array( $vis['utm_source'] ?? '', $vis['utm_medium'] ?? '' ) );
			echo '<li><small>' . esc_html( self::t( (int) floor( (int) $vis['start_ms'] / 1000 ) ) ) . '</small><span>' . esc_html( $src ? implode( '/', $src ) : ( ( $vis['referrer_host'] ?? '' ) ?: 'direct' ) ) . ( ! empty( $vis['attribution_path'] ) ? ' · ' . esc_html( (string) $vis['attribution_path'] ) : '' ) . '</span></li>';
		}
		echo '</ul></section>';

		// 设备信息。
		$dev = array( 'mobile' => '移动', 'desktop' => '电脑', 'tablet' => '平板' );
		echo '<section class="pc-od-card"><h2>设备信息</h2><dl class="pc-od-kv">';
		echo '<dt>访客ID：</dt><dd class="pc-od-break">' . ( $v['row'] ? esc_html( (string) $v['row']['vid'] ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=' . Tracking_Page::SLUG . '&visitor=' . (int) $v['row']['id'] ) ) . '">查看轨迹</a>' : '-' ) . '</dd>';
		echo '<dt>设备来源：</dt><dd>' . esc_html( $v['row'] ? ( $dev[ $v['row']['device'] ] ?? (string) $v['row']['device'] ) : ( $o->get_meta( '_wc_order_attribution_device_type' ) ?: '-' ) ) . '</dd>';
		echo '<dt>操作系统：</dt><dd>' . self::v( $v['os'] ) . '</dd>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<dt>时区：</dt><dd>-</dd>';
		echo '<dt>浏览器：</dt><dd>' . self::v( $v['row']['browser'] ?? '' ) . '</dd>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<dt>浏览器语言：</dt><dd>-</dd>';
		echo '<dt>屏幕尺寸：</dt><dd>' . self::v( $v['screen'] ) . '</dd>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<dt>访问IP：</dt><dd>' . self::v( $o->get_customer_ip_address() ) . '</dd></dl></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		// IP 信息。
		$geo = array( 'country' => '', 'state' => '' );
		if ( $o->get_customer_ip_address() && class_exists( '\WC_Geolocation' ) ) {
			$geo = array_merge( $geo, (array) \WC_Geolocation::geolocate_ip( $o->get_customer_ip_address(), true, false ) );
		}
		$gcc = $geo['country'] ?: $v['country'];
		echo '<section class="pc-od-card"><h2>IP信息</h2><dl class="pc-od-kv">';
		echo '<dt>IP国家：</dt><dd>' . self::v( self::en_country( $gcc ) ) . '</dd>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$gst = $gcc && $geo['state'] ? self::en_state( $gcc, $geo['state'] ) : '';
		echo '<dt>IP州/省份：</dt><dd>' . self::v( $gst ) . '</dd><dt>IP城市：</dt><dd>-</dd><dt>经纬度：</dt><dd>-</dd><dt>ISP：</dt><dd>-</dd><dt>用户类型：</dt><dd>-</dd></dl></section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		echo '</aside></div></div>';
		self::styles();
	}

	private static function styles(): void {
		?>
<style>
.pc-od{margin:0 20px 40px 0;color:#303133;font-size:13px}
.pc-od-top{margin:14px 0 10px}.pc-od-back{color:#303133;text-decoration:none;font-size:14px}.pc-od-back .dashicons{vertical-align:-4px}
.pc-od-head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin:6px 0 16px;flex-wrap:wrap}
.pc-od-head__l{display:flex;align-items:center;gap:20px}.pc-od-head h1{margin:0;font-size:18px;font-weight:500}
.pc-od-head__r{display:flex;align-items:center;gap:10px}.pc-od-inline{display:inline}
.pc-od-btn{display:inline-flex;align-items:center;justify-content:center;min-height:34px;padding:0 18px;border:0;border-radius:3px;background:#ed8a3a;color:#fff!important;text-decoration:none;font-size:14px;cursor:pointer}
.pc-od-btn:hover{background:#e47a26}
.pc-od-arrow{color:#909399;text-decoration:none;padding:4px}.pc-od-arrow .dashicons{font-size:24px;width:24px;height:24px}.pc-od-arrow.is-off{opacity:.3;pointer-events:none}
.pc-od-link{display:inline-flex;align-items:center;gap:3px;border:0;background:none;padding:0;color:#ed8a3a;text-decoration:none;font-size:13px;cursor:pointer}
.pc-od-link .dashicons{font-size:16px;width:16px;height:16px}
.pc-od-grid{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:24px;align-items:start}
.pc-od-main,.pc-od-side{display:grid;grid-template-columns:minmax(0,1fr);gap:20px;min-width:0}
.pc-od-main>*,.pc-od-side>*{min-width:0}
.pc-od-card--flush{overflow-x:auto}
.pc-od-addr{width:100%;border-collapse:collapse;font-size:13px}
.pc-od-addr th{text-align:left;font-weight:600;color:#303133;padding:0 12px 8px 0;border-bottom:1px solid #dcdfe6}
.pc-od-addr td{padding:11px 12px 11px 0;border-bottom:1px solid #f0f2f5;vertical-align:top;word-break:break-word}
.pc-od-addr td:first-child,.pc-od-addr th:first-child{width:1%;white-space:nowrap;padding-right:28px}.pc-od-addr td:first-child{color:#606266}
.pc-od-addr tr:last-child td{border-bottom:0}
.pc-od-card{background:#fff;border-radius:4px;padding:20px 24px;box-shadow:0 1px 2px rgba(0,0,0,.03)}
.pc-od-card--flush{padding:0}
.pc-od-card h2{margin:0 0 16px;font-size:15px;font-weight:500}.pc-od-card h3{margin:18px 0 10px;font-size:14px;font-weight:500}
.pc-od-ct{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}.pc-od-ct h2{margin:0}.pc-od-ct>span{display:flex;gap:14px}
.pc-od-ohead{padding:22px 32px 18px}.pc-od-ohead__row{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.pc-od-num{font-size:18px;margin-right:6px}.pc-od-sep{width:1px;height:22px;background:#dcdfe6;margin:0 2px}
.pc-od-ohead__links{margin-left:auto;display:flex;gap:30px}
.pc-od-times{display:flex;flex-wrap:wrap;gap:6px 60px;margin-top:14px;color:#606266}
.pc-od-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:2px;color:#fff;font-size:13px;line-height:20px}
.pc-od-badge .dashicons{font-size:15px;width:15px;height:15px}
.pc-od-badge.is-blue{background:#3a8ee6}.pc-od-badge.is-teal{background:#17a2b8}.pc-od-badge.is-orange{background:#f0a020}.pc-od-badge.is-green{background:#3fb06b}.pc-od-badge.is-red{background:#e5484d}.pc-od-badge.is-gray{background:#a0a4ab}
.pc-od-items{width:100%;border-collapse:collapse}
.pc-od-items th{background:#e6ebf2;color:#606266;font-weight:400;text-align:left;padding:16px 32px}
.pc-od-items td{padding:14px 32px;border-bottom:1px solid #ebeef5;vertical-align:middle}
.pc-od-items th:nth-child(2),.pc-od-items th:nth-child(3),.pc-od-items td:nth-child(2),.pc-od-items td:nth-child(3){width:160px;white-space:nowrap}
.pc-od-prod{display:flex;gap:20px;align-items:center}.pc-od-thumb img{width:64px;height:64px;object-fit:contain;display:block}
.pc-od-prod strong{display:block;font-size:14px;margin-bottom:4px}.pc-od-prod small{display:block;color:#606266;font-size:13px;line-height:22px}
.pc-od-totals{padding:22px 32px;display:grid;justify-content:end;gap:8px}
.pc-od-totals>div{display:grid;grid-template-columns:auto 140px;gap:40px;text-align:right;align-items:baseline}
.pc-od-totals b{font-weight:400}.pc-od-totals .is-total b,.pc-od-totals .is-total span{font-weight:600;font-size:14px}
.pc-od-two{display:grid;grid-template-columns:1fr 1fr;gap:20px}
.pc-od-dl,.pc-od-kv{display:grid;grid-template-columns:auto 1fr;gap:9px 14px;margin:0}.pc-od-dl dt,.pc-od-kv dt{color:#606266}.pc-od-dl dd,.pc-od-kv dd{margin:0;word-break:break-word}
.pc-od-dl{grid-template-columns:60px 1fr}
.pc-od-wa{color:#25d366;text-decoration:none;font-size:12px;margin-left:4px}
.pc-od-table{width:100%;border-collapse:collapse;margin-top:4px}
.pc-od-table th{background:#e6ebf2;color:#606266;font-weight:400;text-align:left;padding:10px 12px}
.pc-od-table td{padding:12px;border-bottom:1px solid #ebeef5;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pc-od-empty{text-align:center;color:#909399}
.pc-od-none{text-align:center;color:#909399;padding:20px 0}.pc-od-none .dashicons{font-size:56px;width:56px;height:56px;color:#c0c4cc}.pc-od-none p{margin:8px 0 0}
.pc-od-buyer{display:flex;align-items:center;gap:10px;font-size:14px;margin:0 0 14px}.pc-od-flag{font-size:22px;line-height:1}
.pc-od-hr{border:0;border-top:1px dashed #dcdfe6;margin:16px 0}
.pc-od-icon{border:0;background:none;padding:0;margin-left:4px;color:#ed8a3a;cursor:pointer;vertical-align:-3px;white-space:nowrap}.pc-od-icon .dashicons{font-size:15px;width:15px;height:15px}
.pc-od-note textarea{width:100%;font-size:13px}.pc-od-note .button{margin-top:6px}
.pc-od-tags{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px}
.pc-od-tag{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:3px;background:#fdf1e7;color:#c36a1e;font-size:12px}
.pc-od-tag button{border:0;background:none;color:#c36a1e;cursor:pointer;padding:0;font-size:14px;line-height:1}
.pc-od-tagform input{width:100%}
.pc-od-timeline{list-style:none;margin:0;padding:0 0 0 14px;border-left:1px dashed #dcdfe6;display:grid;gap:14px}
.pc-od-timeline li{position:relative;word-break:break-all}.pc-od-timeline li::before{content:"";position:absolute;left:-19px;top:5px;width:8px;height:8px;border-radius:50%;background:#ed8a3a}
.pc-od-timeline small{display:block;color:#909399;margin-bottom:2px}.pc-od-timeline .pc-od-kv{margin-top:8px}
.pc-od-break{word-break:break-all}.pc-od-mail{display:flex;align-items:flex-start;gap:2px;word-break:break-all}
@media (max-width:1100px){.pc-od-grid{grid-template-columns:1fr}.pc-od-two{grid-template-columns:1fr}}
</style>
<script>
document.addEventListener('click',function(e){
	var c=e.target.closest('[data-pc-copy]');
	if(c){ e.preventDefault(); var t=c.getAttribute('data-pc-copy'); var done=function(){ var o=c.innerHTML; c.innerHTML='已复制'; setTimeout(function(){c.innerHTML=o;},1200); }; if(navigator.clipboard){navigator.clipboard.writeText(t).then(done,function(){});} return; }
	var g=e.target.closest('[data-pc-toggle]');
	if(g){ var el=document.getElementById(g.getAttribute('data-pc-toggle')); if(el){ el.hidden=!el.hidden; } }
});
</script>
		<?php
	}
}
