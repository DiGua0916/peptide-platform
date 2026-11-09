<?php
/**
 * 弃单数据（2026-11-09，按店主 Shopname 后台「弃单」页重做）。
 *
 * 一条弃单 = 一张没付款的订单（结账草稿 / 待付款 / 支付失败 / 未付款被取消），不含店主的测试单。
 * 召回 = 给顾客发邮件（系统自动的付款帮助信、弃单提醒，或店主手写的信），次数和时间从店铺邮件记录
 * （pc_mail_outbox）按订单号或顾客邮箱汇总。召回状态：
 *   pending   待召回：还没发过信
 *   recalling 召回中：发过信，3 天内
 *   failed    召回失败：发过信，3 天内没回来下单（第 4 天起）
 *   success   召回成功：发过信之后付了款（这张单付了，或同一邮箱随后有付款单）
 *   void      已作废：店主手动作废，或系统判定的测试单
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Recovery_Items {

	public const STATUSES   = array( 'pending', 'recalling', 'failed', 'success', 'void' );
	public const VOID_META  = '_pc_recall_void';
	public const RECALL_DAYS = 3; // 店主规则：发信后 3 天内没回来下单，第 4 天起算召回失败。
	private const UNPAID    = array( 'wc-checkout-draft', 'wc-pending', 'wc-failed', 'wc-cancelled', 'wc-on-hold' );

	public static function status_labels(): array {
		return array( 'pending' => '待召回', 'recalling' => '召回中', 'failed' => '召回失败', 'success' => '召回成功', 'void' => '已作废' );
	}

	public static function order_status_label( string $status ): string {
		$map = array( 'checkout-draft' => '未完成', 'pending' => '未支付', 'failed' => '支付失败', 'cancelled' => '已取消', 'on-hold' => '待确认', 'processing' => '已付款', 'completed' => '已完成', 'refunded' => '已退款' );
		return $map[ $status ] ?? $status;
	}

	/** 召回邮件：订单号或收件人匹配、发送成功、且是发给顾客的信（系统付款帮助 / 弃单提醒 / 手写）。 */
	public static function recall_mails( \WC_Order $order ): array {
		if ( ! Mail_Outbox::installed() ) {
			return array();
		}
		global $wpdb;
		$t     = Mail_Outbox::table();
		$email = strtolower( trim( (string) $order->get_billing_email() ) );
		$where = $wpdb->prepare( 'order_id = %d', $order->get_id() );
		if ( '' !== $email ) {
			$where .= $wpdb->prepare( ' OR recipient LIKE %s', '%' . $wpdb->esc_like( $email ) . '%' );
		}
		$rows = $wpdb->get_results( "SELECT id, kind, subject, status, error, created_ms FROM {$t} WHERE ({$where}) ORDER BY created_ms DESC LIMIT 50", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return array_values( array_filter( (array) $rows, static fn( $r ) => self::is_recall_kind( (string) $r['kind'] ) ) );
	}

	private static function is_recall_kind( string $kind ): bool {
		return str_starts_with( $kind, 'manual' ) || str_contains( $kind, 'customer_failed_order' ) || str_contains( $kind, 'cart_recovery' ) || str_contains( $kind, 'customer_invoice' );
	}

	public static function mail_kind_label( string $kind ): string {
		if ( str_starts_with( $kind, 'manual' ) ) {
			return '手动发送';
		}
		if ( str_contains( $kind, 'customer_failed_order' ) ) {
			return '付款帮助信（自动）';
		}
		if ( preg_match( '/cart_recovery_(\d)/', $kind, $m ) ) {
			return '弃单提醒（第 ' . $m[1] . ' 封）';
		}
		if ( str_contains( $kind, 'customer_invoice' ) ) {
			return '账单 / 付款链接';
		}
		return Mail_Outbox::kind_label( $kind );
	}

	/** 同一邮箱在某个时间之后有没有付款的订单。 */
	private static function paid_after( string $email, int $after_ts, int $exclude ): ?\WC_Order {
		if ( '' === $email ) {
			return null;
		}
		foreach ( wc_get_orders( array( 'billing_email' => $email, 'status' => array( 'processing', 'completed' ), 'limit' => 5, 'orderby' => 'date', 'order' => 'DESC' ) ) as $o ) {
			if ( $o->get_id() !== $exclude && $o->get_date_paid() && $o->get_date_paid()->getTimestamp() >= $after_ts ) {
				return $o;
			}
		}
		return null;
	}

	/** 把一张订单整理成列表行。 */
	public static function item( \WC_Order $order ): array {
		$mails   = self::recall_mails( $order );
		$sent    = array_values( array_filter( $mails, static fn( $m ) => 'sent' === $m['status'] ) );
		$last_ms = $sent ? (int) $sent[0]['created_ms'] : 0;
		$paid    = $order->get_date_paid() ? $order->get_date_paid()->getTimestamp() : 0;
		$email   = strtolower( trim( (string) $order->get_billing_email() ) );
		$status  = 'pending';
		$later   = null;
		if ( '1' === (string) $order->get_meta( self::VOID_META ) || Test_Orders::is_test_order( $order ) ) {
			$status = 'void';
		} elseif ( $paid ) {
			$status = $sent ? 'success' : 'void';
		} elseif ( $sent ) {
			$later  = self::paid_after( $email, (int) floor( $last_ms / 1000 ) - 3600, $order->get_id() );
			$status = $later ? 'success' : ( time() - (int) floor( $last_ms / 1000 ) > self::RECALL_DAYS * DAY_IN_SECONDS ? 'failed' : 'recalling' );
		}
		return array(
			'id'           => $order->get_id(),
			'order'        => $order,
			'number'       => $order->get_order_number(),
			'created_ms'   => $order->get_date_created() ? $order->get_date_created()->getTimestamp() * 1000 : 0,
			'name'         => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'email'        => (string) $order->get_billing_email(),
			'phone'        => (string) ( $order->get_billing_phone() ?: $order->get_shipping_phone() ),
			'country'      => (string) ( $order->get_shipping_country() ?: $order->get_billing_country() ),
			'items'        => count( $order->get_items( 'line_item' ) ),
			'ship_method'  => (string) $order->get_shipping_method(),
			'total'        => (float) $order->get_total(),
			'currency'     => $order->get_currency(),
			'pay_method'   => (string) $order->get_payment_method_title(),
			'paid_ms'      => $paid * 1000,
			'tid'          => (string) $order->get_transaction_id(),
			'order_status' => $order->get_status(),
			'status'       => $status,
			'emails'       => count( $sent ),
			'last_sent_ms' => $last_ms,
			'mails'        => $mails,
			'paid_later'   => $later,
		);
	}

	/**
	 * 弃单列表。$f: status / from_ms / to_ms / q。最多看最近 400 张未付款订单 + 发过召回信后付款的订单。
	 *
	 * @return array{items:array,total:int,counts:array}
	 */
	public static function list( array $f, int $page = 1, int $per = 50 ): array {
		$args = array( 'status' => self::UNPAID, 'limit' => 400, 'orderby' => 'date', 'order' => 'DESC' );
		if ( ! empty( $f['from_ms'] ) || ! empty( $f['to_ms'] ) ) {
			$from = ! empty( $f['from_ms'] ) ? (int) floor( $f['from_ms'] / 1000 ) : 0;
			$to   = ! empty( $f['to_ms'] ) ? (int) floor( $f['to_ms'] / 1000 ) : time() + DAY_IN_SECONDS;
			$args['date_created'] = $from . '...' . $to;
		}
		$orders = wc_get_orders( $args );
		// 发过召回信后付了款的订单（召回成功）也要在列表里。
		foreach ( wc_get_orders( array( 'status' => array( 'processing', 'completed' ), 'limit' => 100, 'orderby' => 'date', 'order' => 'DESC' ) ) as $o ) {
			if ( self::recall_mails( $o ) ) {
				$orders[] = $o;
			}
		}
		$items = self::session_items( $f );
		$seen  = array();
		foreach ( $orders as $o ) {
			if ( isset( $seen[ $o->get_id() ] ) ) {
				continue;
			}
			$seen[ $o->get_id() ] = true;
			if ( '' === trim( (string) $o->get_billing_email() ) && '' === trim( (string) $o->get_billing_first_name() ) ) {
				continue; // 还没填任何信息的空草稿不算弃单。
			}
			if ( ! empty( $f['q'] ) ) {
				$hit = false;
				foreach ( preg_split( '/\s+/', trim( (string) $f['q'] ) ) as $term ) {
					$term = ltrim( strtolower( $term ), '#' );
					if ( '' !== $term && ( str_contains( strtolower( $o->get_order_number() ), $term ) || str_contains( strtolower( (string) $o->get_billing_email() ), $term ) || str_contains( strtolower( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ), $term ) ) ) {
						$hit = true;
					}
				}
				if ( ! $hit ) {
					continue;
				}
			}
			$items[] = self::item( $o );
		}
		usort( $items, static fn( $a, $b ) => $b['created_ms'] <=> $a['created_ms'] );
		$items  = self::group_by_customer( $items );
		$counts = array_fill_keys( self::STATUSES, 0 );
		foreach ( $items as $i ) {
			++$counts[ $i['status'] ];
		}
		if ( ! empty( $f['status'] ) && in_array( $f['status'], self::STATUSES, true ) ) {
			$items = array_values( array_filter( $items, static fn( $i ) => $i['status'] === $f['status'] ) );
		}
		$total = count( $items );
		return array( 'items' => array_slice( $items, ( $page - 1 ) * $per, $per ), 'total' => $total, 'counts' => $counts );
	}

	/**
	 * 同一顾客反复下单只算一条弃单（店主要求）：按金额最高的那张展示，其余挂在它下面（attempts / siblings）。
	 * 已付款的单子不并进去。
	 */
	private static function group_by_customer( array $items ): array {
		$groups = array();
		$order  = array();
		foreach ( $items as $i ) {
			$key = strtolower( trim( (string) $i['email'] ) );
			if ( '' === $key || ! empty( $i['paid_ms'] ) ) {
				$key = 'solo:' . $i['id'];
			}
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array();
				$order[]        = $key;
			}
			$groups[ $key ][] = $i;
		}
		$rank = array( 'success' => 0, 'recalling' => 1, 'pending' => 2, 'failed' => 3, 'void' => 4 );
		$out  = array();
		foreach ( $order as $key ) {
			$g = $groups[ $key ];
			// 代表这位顾客的那张单：金额最高的（店主要求），金额相同取最新。
			usort( $g, static fn( $a, $b ) => ( $b['total'] <=> $a['total'] ) ?: ( $b['created_ms'] <=> $a['created_ms'] ) );
			$rep             = array_shift( $g );
			$rep['attempts'] = 1 + count( $g );
			$rep['siblings'] = $g;
			$rep['total_max'] = $rep['total'];
			foreach ( $g as $i ) {
				if ( $rank[ $i['status'] ] < $rank[ $rep['status'] ] ) {
					$rep['status'] = $i['status'];
				}
				$rep['emails']       = max( $rep['emails'], $i['emails'] );
				$rep['last_sent_ms'] = max( $rep['last_sent_ms'], $i['last_sent_ms'] );
				$rep['created_ms']   = max( $rep['created_ms'], $i['created_ms'] );
			}
			$out[] = $rep;
		}
		usort( $out, static fn( $a, $b ) => $b['created_ms'] <=> $a['created_ms'] );
		return $out;
	}

	/** 同一顾客的其他未付款订单（详情页用）。 */
	public static function siblings( \WC_Order $order ): array {
		$email = strtolower( trim( (string) $order->get_billing_email() ) );
		if ( '' === $email ) {
			return array();
		}
		$out = array();
		foreach ( wc_get_orders( array( 'billing_email' => $email, 'status' => self::UNPAID, 'limit' => 20, 'orderby' => 'date', 'order' => 'DESC' ) ) as $o ) {
			if ( $o->get_id() !== $order->get_id() ) {
				$out[] = $o;
			}
		}
		return $out;
	}

	/**
	 * 还没点“下单”的结账（店主 Shopname 后台也算弃单）：顾客在结账页填了邮箱和地址就离开了，WooCommerce 里没有订单，
	 * 只有购物会话。Recovery::scan() 把这些会话记成弃单记录（pc_cart_recovery），这里整理成和订单一样的列表行。
	 */
	public static function session_items( array $f ): array {
		if ( ! Recovery::installed() ) {
			Recovery::install();
		}
		if ( false === get_transient( 'pc_recovery_scanned' ) ) {
			set_transient( 'pc_recovery_scanned', 1, 5 * MINUTE_IN_SECONDS );
			try {
				Recovery::scan();
			} catch ( \Throwable $e ) { /* 扫描失败不影响看列表 */ }
		}
		$filter = array( 'from_ms' => $f['from_ms'] ?? 0, 'to_ms' => $f['to_ms'] ?? 0 );
		$out    = array();
		foreach ( Recovery::records( $filter, 1, 300 )['items'] as $r ) {
			if ( (int) $r['order_id'] || (int) $r['draft_id'] || '' === (string) $r['email'] ) {
				continue; // 已经变成订单的，按订单那条算。
			}
			if ( 'void' === $r['status'] && 'test checkout' === (string) $r['note'] ) {
				continue;
			}
			if ( ! empty( $f['q'] ) ) {
				$hay = strtolower( $r['email'] . ' ' . $r['name'] );
				$hit = false;
				foreach ( preg_split( '/\s+/', strtolower( trim( (string) $f['q'] ) ) ) as $term ) {
					if ( '' !== $term && str_contains( $hay, ltrim( $term, '#' ) ) ) {
						$hit = true;
					}
				}
				if ( ! $hit ) {
					continue;
				}
			}
			$out[] = self::record_item( $r );
		}
		return $out;
	}

	/** 弃单记录（没下单的结账）→ 列表行。 */
	public static function record_item( array $r ): array {
		$mails = self::mails_for_email( (string) $r['email'] );
		$sent  = array_values( array_filter( $mails, static fn( $m ) => 'sent' === $m['status'] ) );
		$last  = max( (int) $r['last_sent_ms'], $sent ? (int) $sent[0]['created_ms'] : 0 );
		$map   = array( 'pending' => 'pending', 'recalling' => 'recalling', 'failed' => 'failed', 'success' => 'success', 'void' => 'void' );
		$status = $map[ $r['status'] ] ?? 'pending';
		if ( in_array( $status, array( 'pending', 'recalling', 'failed' ), true ) ) {
			if ( $sent ) {
				$status = time() - (int) floor( $last / 1000 ) > self::RECALL_DAYS * DAY_IN_SECONDS ? 'failed' : 'recalling';
			} else {
				$status = 'pending';
			}
		}
		$cust  = self::session_customer( (string) $r['session_key'] );
		$items = (array) json_decode( (string) $r['cart'], true );
		return array(
			'id'           => 'r' . (int) $r['id'],
			'rec'          => $r,
			'order'        => null,
			'number'       => '未下单',
			'created_ms'   => (int) $r['created_ms'],
			'name'         => (string) $r['name'],
			'email'        => (string) $r['email'],
			'phone'        => (string) ( $cust['phone'] ?? $cust['shipping_phone'] ?? '' ),
			'country'      => (string) $r['country'],
			'items'        => (int) array_sum( array_column( $items, 'quantity' ) ),
			'ship_method'  => (string) $r['ship_method'],
			'total'        => '' !== (string) $r['total'] ? (float) $r['total'] : (float) $r['subtotal'],
			'currency'     => (string) ( $r['currency'] ?: get_woocommerce_currency() ),
			'pay_method'   => (string) $r['pay_method'],
			'paid_ms'      => (int) $r['paid_ms'],
			'tid'          => '',
			'order_status' => 'checkout-draft',
			'status'       => $status,
			'emails'       => max( (int) $r['emails'], count( $sent ) ),
			'last_sent_ms' => $last,
			'mails'        => $mails,
			'paid_later'   => null,
		);
	}

	/** 购物会话的完整数据：顾客资料、购物车合计、选的配送 / 支付。 */
	public static function session_data( string $key ): array {
		global $wpdb;
		$v = $wpdb->get_var( $wpdb->prepare( "SELECT session_value FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key = %s", $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$d = is_string( $v ) ? maybe_unserialize( $v ) : null;
		$d = is_array( $d ) ? $d : array();
		$c = maybe_unserialize( $d['customer'] ?? '' );
		$t = maybe_unserialize( $d['cart_totals'] ?? '' );
		$c = is_array( $c ) ? $c : array();
		$billing  = array();
		$shipping = array();
		foreach ( array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone', 'email' ) as $f ) {
			$billing[ $f ]  = (string) ( $c[ $f ] ?? '' );
			$shipping[ $f ] = (string) ( $c[ 'shipping_' . $f ] ?? '' );
		}
		if ( '' === trim( implode( '', array_diff_key( $shipping, array( 'email' => 1, 'phone' => 1 ) ) ) ) ) {
			$shipping = $billing; // 没单独填收货地址就是同一个。
		}
		return array( 'customer' => $c, 'billing' => $billing, 'shipping' => $shipping, 'totals' => is_array( $t ) ? $t : array() );
	}

	/** 没下单的结账对应的访客（实时访客里 cart_key = 购物会话键）。 */
	public static function visitor_by_session( string $key ): ?array {
		if ( '' === $key || ! class_exists( Tracking\Schema::class ) || ! Tracking\Schema::ready() ) {
			return null;
		}
		global $wpdb;
		$v = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Tracking\Schema::table( 'visitors' ) . ' WHERE cart_key = %s ORDER BY last_ms DESC LIMIT 1', $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( ! $v ) {
			return null;
		}
		$visit = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Tracking\Schema::table( 'visits' ) . ' WHERE visitor_id = %d ORDER BY start_ms DESC LIMIT 1', (int) $v['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		return self::visitor_view( $v, $visit );
	}

	private static function visitor_view( array $v, ?array $visit ): array {
		$devices = array( 'mobile' => '手机', 'desktop' => '电脑', 'tablet' => '平板' );
		$query   = array();
		if ( $visit && ! empty( $visit['attribution_query'] ) ) {
			parse_str( (string) $visit['attribution_query'], $query );
		}
		return array(
			'id'        => (int) $v['id'],
			'device'    => $devices[ $v['device'] ] ?? (string) $v['device'],
			'browser'   => (string) $v['browser'],
			'visits'    => (int) $v['visits'],
			'first_ms'  => (int) $v['first_ms'],
			'last_ms'   => (int) $v['last_ms'],
			'landing'   => $visit ? (string) $visit['landing_path'] : '',
			'utm'       => $visit ? trim( (string) $visit['utm_source'] . ' / ' . (string) $visit['utm_medium'], ' /' ) : '',
			'campaign'  => (string) ( $query['utm_campaign'] ?? '' ),
			'content'   => (string) ( $query['utm_content'] ?? '' ),
			'referrer'  => $visit ? (string) $visit['referrer_host'] : '',
			'host'      => $visit ? (string) $visit['attribution_host'] : '',
			'pageviews' => $visit ? (int) $visit['pageviews'] : 0,
			'url'       => admin_url( 'admin.php?page=pc-visitors&visitor=' . (int) $v['id'] ),
		);
	}

	private static function session_customer( string $key ): array {
		global $wpdb;
		$v = $wpdb->get_var( $wpdb->prepare( "SELECT session_value FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key = %s", $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$d = is_string( $v ) ? maybe_unserialize( $v ) : null;
		$c = is_array( $d ) ? maybe_unserialize( $d['customer'] ?? '' ) : null;
		return is_array( $c ) ? $c : array();
	}

	/** 按邮箱找发给顾客的召回邮件。 */
	public static function mails_for_email( string $email ): array {
		$email = strtolower( trim( $email ) );
		if ( '' === $email || ! Mail_Outbox::installed() ) {
			return array();
		}
		global $wpdb;
		$t    = Mail_Outbox::table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, kind, subject, status, error, created_ms FROM {$t} WHERE recipient LIKE %s ORDER BY created_ms DESC LIMIT 50", '%' . $wpdb->esc_like( $email ) . '%' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return array_values( array_filter( (array) $rows, static fn( $r ) => self::is_recall_kind( (string) $r['kind'] ) ) );
	}

	/** 顾客姓名规范显示：首字母大写，去掉顾客把全名重复填进姓和名造成的重复（“carla figueroa carla figueroa” → “Carla Figueroa”）。 */
	public static function display_name( string $name ): string {
		$words = preg_split( '/\s+/u', trim( $name ) ) ?: array();
		$words = array_values( array_filter( $words, static fn( $w ) => '' !== $w ) );
		$n     = count( $words );
		if ( $n >= 2 && 0 === $n % 2 && array_slice( $words, 0, $n / 2 ) === array_slice( $words, $n / 2 ) ) {
			$words = array_slice( $words, 0, $n / 2 );
		}
		return implode( ' ', array_map( static fn( $w ) => mb_convert_case( mb_strtolower( $w ), MB_CASE_TITLE, 'UTF-8' ), $words ) );
	}

	/** 手动作废 / 取消作废。 */
	public static function set_void( int $order_id, bool $void ): bool {
		$o = wc_get_order( $order_id );
		if ( ! $o instanceof \WC_Order ) {
			return false;
		}
		$o->update_meta_data( self::VOID_META, $void ? '1' : '0' );
		$o->add_order_note( $void ? '弃单已手动作废，不再召回。' : '弃单恢复召回。' );
		$o->save();
		return true;
	}

	/* ---------- 详情页需要的数据 ---------- */

	/** 商品行。 */
	public static function lines( \WC_Order $order ): array {
		$out = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			$qty     = max( 1, (int) $item->get_quantity() );
			$attrs   = array();
			if ( $product && $product->is_type( 'variation' ) ) {
				foreach ( $product->get_variation_attributes() as $k => $v ) {
					$attrs[] = wc_attribute_label( str_replace( 'attribute_', '', $k ), $product ) . ': ' . $v;
				}
			}
			foreach ( $item->get_formatted_meta_data( '_', true ) as $meta ) {
				$attrs[] = wp_strip_all_tags( $meta->display_key ) . ': ' . wp_strip_all_tags( $meta->display_value );
			}
			$out[] = array(
				'name'  => $item->get_name(),
				'sku'   => $product ? (string) $product->get_sku() : '',
				'attrs' => array_values( array_unique( $attrs ) ),
				'image' => $product ? (string) wp_get_attachment_image_url( (int) $product->get_image_id(), 'thumbnail' ) : '',
				'url'   => $product ? get_edit_post_link( $product->get_parent_id() ?: $product->get_id(), '' ) : '',
				'qty'   => $qty,
				'unit'  => (float) $item->get_subtotal() / $qty,
				'total' => (float) $item->get_total(),
			);
		}
		return $out;
	}

	/** 费用拆分。 */
	public static function totals( \WC_Order $order ): array {
		$rows   = array();
		$rows[] = array( '商品价格', (float) $order->get_subtotal() );
		$rows[] = array( '运费' . ( $order->get_shipping_method() ? '（' . $order->get_shipping_method() . '）' : '' ), (float) $order->get_shipping_total() );
		$codes  = $order->get_coupon_codes();
		$rows[] = array( '优惠券优惠' . ( $codes ? '（' . implode( ', ', $codes ) . '）' : '' ), -1 * (float) $order->get_discount_total() );
		foreach ( $order->get_fees() as $fee ) {
			$rows[] = array( $fee->get_name(), (float) $fee->get_total() );
		}
		$rows[] = array( '税费', (float) $order->get_total_tax() );
		return $rows;
	}

	/** 支付尝试（Panapay 中枢会话）。 */
	public static function payment_attempts( \WC_Order $order ): array {
		$book = $order->get_meta( '_pc_panapay_sessions' );
		$book = is_string( $book ) ? json_decode( $book, true ) : $book;
		if ( ! is_array( $book ) || empty( $book['attempts'] ) ) {
			return array();
		}
		$labels = array( 'created' => '支付页已打开', 'creating' => '正在创建', 'unknown' => '创建结果待核对', 'failed' => '创建失败', 'paid' => '已付款' );
		$out    = array();
		foreach ( $book['attempts'] as $id => $a ) {
			$out[] = array(
				'time'   => (int) ( $a['created_at'] ?? 0 ),
				'state'  => $labels[ $a['state'] ?? '' ] ?? (string) ( $a['state'] ?? '-' ),
				'amount' => (string) ( $a['amount'] ?? '' ) . ' ' . (string) ( $a['currency'] ?? '' ),
				'active' => ( $book['active'] ?? '' ) === $id,
			);
		}
		usort( $out, static fn( $a, $b ) => $b['time'] <=> $a['time'] );
		return $out;
	}

	/** 顾客汇总：付款订单数、消费总额、首次付款时间。 */
	public static function customer_summary( string $email ): array {
		$out = array( 'orders' => 0, 'spent' => 0.0, 'first_paid' => 0 );
		if ( '' === trim( $email ) ) {
			return $out;
		}
		foreach ( wc_get_orders( array( 'billing_email' => $email, 'status' => array( 'processing', 'completed' ), 'limit' => 50 ) ) as $o ) {
			++$out['orders'];
			$out['spent'] += (float) $o->get_total();
			$ts = $o->get_date_paid() ? $o->get_date_paid()->getTimestamp() : 0;
			if ( $ts && ( ! $out['first_paid'] || $ts < $out['first_paid'] ) ) {
				$out['first_paid'] = $ts;
			}
		}
		return $out;
	}

	/** WooCommerce 订单归因 + 我们自己的访问记录。 */
	public static function attribution( \WC_Order $order ): array {
		$g   = static fn( string $k ) => (string) $order->get_meta( '_wc_order_attribution_' . $k );
		$src = $g( 'source_type' );
		$map = array( 'utm' => '广告 / 推广链接', 'organic' => '搜索引擎', 'referral' => '外部网站', 'typein' => '直接输入网址', 'admin' => '后台创建', 'mobile_app' => 'App' );
		return array(
			'source_type' => $map[ $src ] ?? ( '' !== $src ? $src : '' ),
			'utm_source'  => $g( 'utm_source' ),
			'utm_medium'  => $g( 'utm_medium' ),
			'utm_campaign' => $g( 'utm_campaign' ),
			'utm_content' => $g( 'utm_content' ),
			'referrer'    => $g( 'referrer' ),
			'landing'     => $g( 'session_entry' ),
			'session_start' => $g( 'session_start_time' ),
			'pages'       => $g( 'session_pages' ),
			'sessions'    => $g( 'session_count' ),
			'device'      => $g( 'device_type' ),
			'user_agent'  => $g( 'user_agent' ),
		);
	}

	/** 访客（实时访客模块）：设备、浏览器、访问次数、最近访问、轨迹链接。 */
	public static function visitor( \WC_Order $order ): ?array {
		$vid = (int) $order->get_meta( '_pc_track_visitor' );
		if ( ! $vid || ! class_exists( Tracking\Schema::class ) || ! Tracking\Schema::ready() ) {
			return null;
		}
		global $wpdb;
		$v = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Tracking\Schema::table( 'visitors' ) . ' WHERE id = %d', $vid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		if ( ! $v ) {
			return null;
		}
		$visit_id = (int) $order->get_meta( '_pc_track_visit' );
		$visit    = $visit_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Tracking\Schema::table( 'visits' ) . ' WHERE id = %d', $visit_id ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		return self::visitor_view( $v, $visit );
	}

	/** 卖家备注（私密订单备注，去掉系统自动写的）。 */
	public static function notes( \WC_Order $order ): array {
		$out = array();
		foreach ( wc_get_order_notes( array( 'order_id' => $order->get_id(), 'type' => 'internal', 'limit' => 30 ) ) as $n ) {
			$out[] = array( 'time' => $n->date_created ? $n->date_created->getTimestamp() : 0, 'by' => (string) $n->added_by, 'text' => wp_strip_all_tags( (string) $n->content ) );
		}
		return $out;
	}
}
