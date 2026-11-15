<?php
/**
 * M4 发布前的“线上已有功能保持原样”比对：同一份数据，分别在线上的代码和 M4 的代码上跑这个脚本，比两份输出。
 *
 * 这个脚本**只用线上已经有的类**（满减、优惠码规则、赠品 / 捆绑、运费折扣、手动订单、首单优惠、离开挽回、
 * 营销后台页面），不用 M4 新加的任何东西，所以同一个文件可以在线上的代码上跑：
 *
 *   M4_WALKTHROUGH=sandbox M4_COMPARE_OUT=/tmp/online.json wp eval-file <这个文件>     # 站点装的是线上的代码
 *   M4_WALKTHROUGH=sandbox M4_COMPARE_OUT=/tmp/m4.json     wp eval-file <这个文件>     # 站点装的是 M4 的代码
 *   diff /tmp/online.json /tmp/m4.json                                                  # 应当完全一样
 *
 * 两次运行前把数据库恢复到同一份快照（脚本会临时建商品、活动、优惠码，结束时删掉；ID 由数据库分配，
 * 同一份快照、同样的顺序，两边的 ID 一样）。输出里没有随机数、时间戳、nonce。
 *
 * 比的内容（顾客和店员能看到的）：
 * - 24 个购物车：每行单价和小计、每张优惠码减了多少、被拒绝的提示、满减费用、运费的每个选项和选中的那个、
 *   应付总额；满减进度条的数据。
 * - 前台：首单券条的 HTML、离开挽回 / 转盘发给浏览器的配置、活动页和优惠码页的 HTML、商品页显示的满减。
 * - 店铺后台（管理员）：满减、优惠码、运费折扣三个列表页和编辑页，赠品 / 捆绑、首单优惠、离开挽回的设置页的 HTML。
 *
 * 只能在沙盒 / 本地站点跑（域名里带 sandbox / localhost / local / test / staging，并且 M4_WALKTHROUGH=sandbox）。
 * 不建订单、不发邮件、不碰支付。
 *
 * @package PeptideCore
 */

use PeptideCore\Coupon_Rules;
use PeptideCore\Exit_Intent;
use PeptideCore\First_Order_Offer;
use PeptideCore\Manual_Orders;
use PeptideCore\Product_Offers;
use PeptideCore\Promotions;
use PeptideCore\Shipping\Discounts;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'M4_Compare_Stop' ) ) {
	/** 后台页面里的 wp_die / 跳转被截下来。 */
	final class M4_Compare_Stop extends RuntimeException {}
}

( static function (): void {
	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	if ( 'sandbox' !== getenv( 'M4_WALKTHROUGH' ) || ! preg_match( '/(^|[.\-])(sandbox|localhost|local|test|staging)([.\-]|$)/i', $host ) ) {
		echo "拒绝运行：只能在沙盒 / 本地站点跑（需要 M4_WALKTHROUGH=sandbox，域名里带 sandbox / localhost / local / test / staging；当前 {$host}）。\n";
		exit( 3 );
	}
	$out_file = (string) getenv( 'M4_COMPARE_OUT' );
	if ( '' === $out_file ) {
		echo "需要环境变量 M4_COMPARE_OUT=<输出文件>。\n";
		exit( 3 );
	}
	add_filter( 'pre_wp_mail', '__return_false' );
	if ( null === WC()->cart ) {
		wc_load_cart();
	}
	$cart = WC()->cart;

	$tag   = 'm4rc';
	$admin = (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID' ) )[0];
	$saved = array(
		'first'    => get_option( First_Order_Offer::OPTION, null ),
		'shipdisc' => get_option( Discounts::OPTION, null ),
		'manual'   => get_option( Manual_Orders::OPTION, null ),
		'exit'     => get_option( Exit_Intent::OPTION, null ),
		'email'    => WC()->customer ? WC()->customer->get_billing_email() : '',
	);
	$created = array( 'posts' => array(), 'terms' => array() );
	$result  = array( 'carts' => array(), 'front' => array(), 'admin' => array() );

	/** 去掉每次运行都会变的东西：nonce、时间戳、随机元素 ID、资源版本号、站点地址。 */
	$clean = static function ( string $html ) use ( $tag ): string {
		$html = (string) preg_replace( '/(_wpnonce|nonce|_wp_http_referer|security)(["\'=:\s\\\\]+)[0-9a-f]{10}/i', '$1$2N', $html );
		$html = (string) preg_replace( '/([?&](?:ver|v|_)=)[^"\'&\s<]+/', '$1X', $html );
		$html = (string) preg_replace( '/\b(quantity|pc-[a-z-]+|uid|wp-[a-z-]+)_[0-9a-f]{8,}\b/', '$1_U', $html );
		$html = (string) preg_replace( '/[0-9a-f]{32}/', 'H', $html );
		// 脚本运行那一刻的时间（新建表单的默认开始时间、临时活动的创建时间），两次运行不会一样。
		$html = (string) preg_replace( '/\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?/', 'TIME', $html );
		// 新建商品时系统自动生成的随机 SKU。
		$html = (string) preg_replace( '/SKU: \d{12,}/', 'SKU: RANDOM', $html );
		$html = str_replace( home_url(), 'SITE', $html );
		return trim( (string) preg_replace( '/\s+/', ' ', $html ) );
	};
	$capture = static function ( callable $fn ) use ( $clean ): string {
		ob_start();
		try {
			$fn();
		} catch ( M4_Compare_Stop $stop ) {
			echo '[[STOP ' . $stop->getCode() . ' ' . $stop->getMessage() . ']]';
		}
		return $clean( (string) ob_get_clean() );
	};

	try {
		wp_set_current_user( $admin );

		// ---------- 临时数据（和 m4-shadow-walkthrough-native.php 同一套组合） ----------
		$term = wp_insert_term( 'M4 release compare ' . $tag, 'product_cat', array( 'slug' => $tag . '-cat' ) );
		$cat  = is_wp_error( $term ) ? 0 : (int) $term['term_id'];
		$created['terms'][] = $cat;
		$product = static function ( string $name, string $price, string $regular = '', array $cats = array() ) use ( $tag, &$created ): int {
			$p = new \WC_Product_Simple();
			$p->set_name( 'M4RC ' . $name . ' ' . $tag );
			$p->set_status( 'publish' );
			$p->set_catalog_visibility( 'hidden' );
			$p->set_regular_price( '' !== $regular ? $regular : $price );
			if ( '' !== $regular ) {
				$p->set_sale_price( $price );
			}
			$p->set_manage_stock( false );
			$p->set_stock_status( 'instock' );
			$p->set_category_ids( $cats );
			$id                 = (int) $p->save();
			$created['posts'][] = $id;
			return $id;
		};
		$a = $product( 'A', '29.98', '', array( $cat ) );
		$b = $product( 'B', '37.98', '', array( $cat ) );
		$c = $product( 'C', '44.98' );
		$d = $product( 'D sale', '59.98', '119.98' );
		$e = $product( 'E gift', '9.99' );
		$f = $product( 'F', '50.00' );
		$g = $product( 'G', '49.99' );
		$h = $product( 'H bundle', '20.00' );
		$i = $product( 'I bundle', '15.00' );

		$promo = static function ( string $title, string $type, array $tiers, array $extra ) use ( $tag, &$created ): int {
			$rows = array();
			foreach ( $tiers as [ $min, $value ] ) {
				$rows[] = array( 'min' => (float) $min, 'value' => (float) $value );
			}
			$id = (int) wp_insert_post( array( 'post_type' => Promotions::CPT, 'post_status' => 'publish', 'post_title' => 'M4RC ' . $title . ' ' . $tag ) );
			update_post_meta( $id, Promotions::META, array_merge( Promotions::defaults(), array( 'type' => $type, 'basis' => in_array( $type, array( 'spend_amount', 'spend_percent' ), true ) ? 'spend' : 'quantity', 'reward' => in_array( $type, array( 'spend_percent', 'qty_percent', 'nth_percent' ), true ) ? 'percent' : 'amount', 'tiers' => $rows, 'public' => true ), $extra ) );
			$created['posts'][] = $id;
			return $id;
		};
		$p1 = $promo( 'spend', 'spend_amount', array( array( 100, 10 ), array( 200, 25 ) ), array( 'scope' => 'products', 'products' => array( $f, $g ) ) );
		$p2 = $promo( 'qty percent', 'qty_percent', array( array( 2, 10 ) ), array( 'scope' => 'categories', 'categories' => array( $cat ) ) );
		$p3 = $promo( 'nth, no coupons', 'nth_percent', array( array( 2, 30 ) ), array( 'scope' => 'products', 'products' => array( $c ), 'with_coupons' => false ) );

		$coupon = static function ( string $code, string $type, string $amount, array $meta = array(), array $props = array() ) use ( $tag, &$created ): string {
			$k = new \WC_Coupon();
			$k->set_code( $tag . $code );
			$k->set_discount_type( $type );
			$k->set_amount( $amount );
			$k->set_description( 'M4RC ' . $code );
			foreach ( $props as $setter => $value ) {
				$k->{'set_' . $setter}( $value );
			}
			foreach ( $meta as $key => $value ) {
				$k->update_meta_data( $key, $value );
			}
			$created['posts'][] = (int) $k->save();
			return $k->get_code();
		};
		$save10  = $coupon( 'save10', 'percent', '10', array( Coupon_Rules::STRATEGY_META => 'stack' ) );
		$solo    = $coupon( 'solo', 'percent', '20', array( Coupon_Rules::STRATEGY_META => 'no_stack' ) );
		$swap    = $coupon( 'swap', 'percent', '15', array( Coupon_Rules::STRATEGY_META => 'replace' ) );
		$five    = $coupon( 'five', 'fixed_cart', '5' );
		$each3   = $coupon( 'each3', 'fixed_product', '3', array(), array( 'limit_usage_to_x_items' => 2, 'exclude_sale_items' => true ) );
		$min60   = $coupon( 'min60', 'percent', '12', array( Coupon_Rules::MIN_SPEND_META => '60' ) );
		$welcome = $coupon( 'welcome', 'percent', '5', array( Coupon_Rules::FIRST_META => 'yes', Coupon_Rules::STRATEGY_META => 'stack', Coupon_Rules::LABEL_META => strtoupper( $tag . 'welcome' ) ), array( 'usage_limit_per_user' => 1 ) );
		update_option( First_Order_Offer::OPTION, array( 'enabled' => true, 'coupon_id' => (int) wc_get_coupon_id_by_code( $welcome ) ), false );

		$offer = static function ( string $title, array $config ) use ( $tag, &$created ): int {
			$id = (int) wp_insert_post( array( 'post_type' => Product_Offers::CPT, 'post_status' => 'publish', 'post_title' => 'M4RC ' . $title . ' ' . $tag ) );
			update_post_meta( $id, Product_Offers::META, array_merge( Product_Offers::defaults(), array( 'enabled' => true ), $config ) );
			$created['posts'][] = $id;
			return $id;
		};
		$offer( 'gift', array( 'kind' => 'gift', 'basis' => 'spend', 'threshold' => 100.0, 'qualifiers' => array( $d ), 'gift_product' => $e, 'gift_qty' => 1 ) );
		$bundle = $offer( 'bundle', array( 'kind' => 'bundle', 'price_type' => 'fixed', 'price_value' => 40.0, 'slots' => array( array( 'id' => 'slot-0', 'label' => 'H', 'qty' => 1, 'choices' => array( $h ) ), array( 'id' => 'slot-1', 'label' => 'I', 'qty' => 2, 'choices' => array( $i ) ) ) ) );

		$all   = is_array( $saved['shipdisc'] ) ? $saved['shipdisc'] : array();
		$all[] = array_merge( Discounts::defaults(), array( 'id' => 990001, 'name' => 'M4RC half shipping', 'kind' => 'percent', 'value' => 50.0, 'created' => 1700000000 ) );
		update_option( Discounts::OPTION, $all, false );
		update_option( Exit_Intent::OPTION, array_merge( Exit_Intent::defaults(), array( 'enabled' => true, 'type' => 'wheel', 'title' => 'M4RC spin', 'pages' => Exit_Intent::PAGES, 'prizes' => array( array( 'label' => '10% OFF', 'coupon' => $save10, 'weight' => 3 ), array( 'label' => 'Try again', 'coupon' => '', 'weight' => 7 ) ) ) ), false );
		\WC_Cache_Helper::get_transient_version( 'shipping', true );

		WC()->customer->set_shipping_country( 'US' );
		WC()->customer->set_shipping_state( 'CA' );
		WC()->customer->set_shipping_postcode( '94016' );
		WC()->customer->set_shipping_city( 'San Francisco' );
		WC()->customer->set_billing_country( 'US' );
		WC()->customer->set_calculated_shipping( true );

		$old_email = '';
		foreach ( wc_get_orders( array( 'status' => wc_get_is_paid_statuses(), 'limit' => 5, 'orderby' => 'ID', 'order' => 'ASC' ) ) as $paid ) {
			if ( $paid instanceof \WC_Order && is_email( $paid->get_billing_email() ) ) {
				$old_email = strtolower( $paid->get_billing_email() );
				break;
			}
		}

		// ---------- 24 个购物车（顾客身份） ----------
		wp_set_current_user( 0 );
		$plain  = static fn( array $lines, array $codes = array(), string $email = 'm4rc-new@example.invalid' ) => array( 'lines' => $lines, 'codes' => $codes, 'email' => $email );
		$combos = array(
			'single'                 => $plain( array( array( $a, 1 ) ) ),
			'multi'                  => $plain( array( array( $h, 2 ), array( $i, 1 ) ) ),
			'spend at threshold'     => $plain( array( array( $f, 2 ) ) ),
			'spend one cent short'   => $plain( array( array( $f, 1 ), array( $g, 1 ) ) ),
			'spend second tier'      => $plain( array( array( $f, 4 ) ) ),
			'two promotions'         => $plain( array( array( $a, 2 ), array( $b, 1 ), array( $c, 2 ) ) ),
			'stack coupon + promo'   => $plain( array( array( $f, 2 ) ), array( $save10 ) ),
			'no-stack vs promo'      => $plain( array( array( $f, 2 ) ), array( $solo ) ),
			'no-stack below promo'   => $plain( array( array( $f, 1 ) ), array( $solo ) ),
			'replace coupon'         => $plain( array( array( $f, 2 ) ), array( $swap ) ),
			'promo without coupons'  => $plain( array( array( $c, 2 ) ), array( $save10 ) ),
			'two coupons'            => $plain( array( array( $h, 1 ), array( $i, 1 ), array( $e, 3 ) ), array( $five, $save10 ) ),
			'per item, no sale'      => $plain( array( array( $d, 1 ), array( $h, 3 ) ), array( $each3 ) ),
			'min spend ok'           => $plain( array( array( $h, 3 ) ), array( $min60 ) ),
			'min spend short'        => $plain( array( array( $h, 2 ) ), array( $min60 ) ),
			'first order new'        => $plain( array( array( $h, 2 ) ), array( $welcome ) ),
			'first order + promo'    => $plain( array( array( $f, 2 ) ), array( $welcome ) ),
			'first order old email'  => '' !== $old_email ? $plain( array( array( $h, 2 ) ), array( $welcome ), $old_email ) : null,
			'gift'                   => $plain( array( array( $d, 2 ) ) ),
			'gift with coupon'       => $plain( array( array( $d, 2 ) ), array( $save10 ) ),
			'bundle + item'          => array( 'lines' => array( array( $c, 1 ) ), 'codes' => array(), 'email' => 'm4rc-new@example.invalid', 'bundle' => array( $bundle, array( 'slot-0' => $h ), 1 ) ),
			'exit popup coupon'      => array( 'lines' => array( array( $a, 2 ) ), 'codes' => array( $five ), 'email' => 'm4rc-new@example.invalid', 'exit' => $five ),
			'manual custom'          => array( 'manual' => array( 'items' => array( array( 'id' => $f, 'qty' => 2, 'price' => 45, 'regular' => 50 ) ), 'shipping' => array( 'label' => 'Courier', 'amount' => 8 ), 'discounts' => array( array( 'label' => 'VIP', 'amount' => 5 ) ), 'days' => 1 ), 'email' => 'm4rc-new@example.invalid' ),
			'manual promo + first'   => array( 'manual' => array( 'items' => array( array( 'id' => $f, 'qty' => 2, 'price' => 50, 'regular' => 50 ) ), 'promo_ids' => array( $p1 ), 'first_order' => true, 'days' => 1 ), 'email' => 'm4rc-new@example.invalid' ),
		);
		$names = array();
		foreach ( array( $a, $b, $c, $d, $e, $f, $g, $h, $i ) as $id ) {
			$names[ $id ] = str_replace( array( 'M4RC ', ' ' . $tag ), '', get_the_title( $id ) );
		}
		$money = static fn( $v ): string => number_format( (float) $v, 2, '.', '' );
		foreach ( array_filter( $combos ) as $label => $combo ) {
			$cart->empty_cart();
			$cart->remove_coupons();
			wc_clear_notices();
			WC()->session->set( 'pc_exit_intent_code', $combo['exit'] ?? null );
			WC()->session->set( 'chosen_shipping_methods', null );
			WC()->customer->set_billing_email( $combo['email'] );
			if ( isset( $combo['manual'] ) ) {
				$link = Manual_Orders::create( $combo['manual'] );
				foreach ( $link['items'] as $item ) {
					$cart->add_to_cart( $item['id'], $item['qty'], 0, array(), array( 'pc_manual' => $link['token'], 'pc_m_price' => $item['price'], 'pc_m_regular' => $item['regular'] ) );
				}
				$first = ! empty( $link['first_order'] ) ? Manual_Orders::first_order_coupon() : null;
				if ( $first ) {
					$cart->applied_coupons = array( wc_format_coupon_code( $first->get_code() ) );
				}
			} else {
				foreach ( $combo['lines'] as [ $id, $qty ] ) {
					$cart->add_to_cart( $id, $qty );
				}
				if ( isset( $combo['bundle'] ) ) {
					Product_Offers::add_bundle( $combo['bundle'][0], $combo['bundle'][1], $combo['bundle'][2], $cart );
				}
				foreach ( $combo['codes'] as $code ) {
					$cart->apply_coupon( $code );
				}
			}
			$notices = array();
			foreach ( wc_get_notices() as $type => $list ) {
				foreach ( $list as $n ) {
					$notices[] = $type . ': ' . wp_strip_all_tags( is_array( $n ) ? (string) ( $n['notice'] ?? '' ) : (string) $n );
				}
			}
			wc_clear_notices();
			$cart->calculate_totals();

			$lines = array();
			foreach ( $cart->get_cart() as $item ) {
				$lines[] = sprintf( '%s x%d unit %s subtotal %s total %s', $names[ (int) $item['product_id'] ] ?? (string) $item['product_id'], (int) $item['quantity'], $money( $item['data']->get_price() ), $money( $item['line_subtotal'] ), $money( $item['line_total'] ) );
			}
			sort( $lines );
			$fees = array();
			foreach ( $cart->get_fees() as $id => $fee ) {
				$fees[ (string) preg_replace( '/^pc-manual-.*/', 'pc-manual', (string) $id ) ] = $money( $fee->amount );
			}
			ksort( $fees );
			$rates = array();
			foreach ( WC()->shipping()->get_packages() as $pkg ) {
				foreach ( (array) $pkg['rates'] as $rid => $rate ) {
					$rates[ (string) preg_replace( '/^pc_manual:.*/', 'pc_manual', (string) $rid ) ] = $rate->get_label() . ' ' . $money( $rate->get_cost() );
				}
			}
			ksort( $rates );
			$coupons = array();
			foreach ( $cart->get_coupon_discount_totals() as $code => $amount ) {
				$coupons[ (string) $code ] = $money( $amount );
			}
			ksort( $coupons );
			$progress = Promotions::progress( $cart );
			$result['carts'][ $label ] = array(
				'lines'    => $lines,
				'coupons'  => $coupons,
				'notices'  => $notices,
				'fees'     => $fees,
				'rates'    => $rates,
				'chosen'   => (string) preg_replace( '/^pc_manual:.*/', 'pc_manual', (string) implode( ',', (array) WC()->session->get( 'chosen_shipping_methods' ) ) ),
				'totals'   => array(
					'subtotal' => $money( $cart->get_subtotal() ),
					'discount' => $money( $cart->get_discount_total() ),
					'fees'     => $money( $cart->get_fee_total() ),
					'shipping' => $money( $cart->get_shipping_total() ),
					'tax'      => $money( $cart->get_total_tax() ),
					'total'    => $money( $cart->get_total( 'edit' ) ),
				),
				'progress' => json_decode( (string) wp_json_encode( $progress ), true ),
			);
		}

		// ---------- 前台（访客身份） ----------
		$cart->empty_cart();
		$cart->remove_coupons();
		wc_clear_notices();
		$result['front']['first order bar']      = $clean( First_Order_Offer::render() );
		$result['front']['exit popup config']    = json_decode( $clean( (string) wp_json_encode( Exit_Intent::public_config( Exit_Intent::settings() ) ) ), true );
		$result['front']['offers page']          = $clean( do_shortcode( '[pc_promotions]' ) );
		$result['front']['coupons page']         = $clean( do_shortcode( '[pc_coupons]' ) );
		$result['front']['product F promotions'] = array_column( Promotions::for_product( wc_get_product( $f ) ), 'id' );
		$result['front']['product A promotions'] = array_column( Promotions::for_product( wc_get_product( $a ) ), 'id' );

		// ---------- 店铺后台（管理员身份） ----------
		wp_set_current_user( $admin );
		add_filter(
			'wp_die_handler',
			static fn() => static function ( $message, $title = '', $args = array() ): void {
				$code = is_int( $title ) ? $title : (int) ( is_array( $args ) ? ( $args['response'] ?? 500 ) : 500 );
				throw new M4_Compare_Stop( wp_strip_all_tags( is_wp_error( $message ) ? $message->get_error_message() : (string) $message ), $code );
			},
			PHP_INT_MAX
		);
		set_current_screen( 'dashboard' );
		$pages = array(
			'promotions list'     => array( '\PeptideCore\Admin\Promotions_Page', array() ),
			'promotion edit'      => array( '\PeptideCore\Admin\Promotions_Page', array( 'edit' => (string) $p1 ) ),
			'promotion new'       => array( '\PeptideCore\Admin\Promotions_Page', array( 'new' => '1' ) ),
			'coupons list'        => array( '\PeptideCore\Admin\Coupons_Page', array() ),
			'coupon edit'         => array( '\PeptideCore\Admin\Coupons_Page', array( 'edit' => (string) wc_get_coupon_id_by_code( $save10 ) ) ),
			'shipping disc list'  => array( '\PeptideCore\Admin\Shipping_Discounts_Page', array() ),
			'shipping disc edit'  => array( '\PeptideCore\Admin\Shipping_Discounts_Page', array( 'edit' => '990001' ) ),
			'gifts and bundles'   => array( '\PeptideCore\Admin\Product_Offers_Page', array() ),
			'first order setting' => array( '\PeptideCore\Admin\Apps_Page', array( 'app' => 'first-order-offer' ) ),
			'exit popup setting'  => array( '\PeptideCore\Admin\Apps_Page', array( 'app' => 'exit-intent' ) ),
		);
		foreach ( $pages as $label => [ $class, $get ] ) {
			if ( ! class_exists( $class ) ) {
				$result['admin'][ $label ] = '(没有这个页面)';
				continue;
			}
			$_GET     = array_merge( array( 'page' => defined( $class . '::SLUG' ) ? constant( $class . '::SLUG' ) : '' ), $get );
			$_REQUEST = $_GET;
			$result['admin'][ $label ] = $capture( array( $class, 'render' ) );
		}
		$_GET     = array();
		$_REQUEST = array();
	} catch ( \Throwable $error ) {
		$result['error'] = get_class( $error ) . ': ' . $error->getMessage() . ' @ ' . basename( $error->getFile() ) . ':' . $error->getLine();
	} finally {
		$cart->empty_cart();
		$cart->remove_coupons();
		wc_clear_notices();
		foreach ( array( 'first' => First_Order_Offer::OPTION, 'shipdisc' => Discounts::OPTION, 'manual' => Manual_Orders::OPTION, 'exit' => Exit_Intent::OPTION ) as $key => $option ) {
			if ( null === $saved[ $key ] ) {
				delete_option( $option );
			} else {
				update_option( $option, $saved[ $key ] );
			}
		}
		foreach ( $created['posts'] as $id ) {
			wp_delete_post( $id, true );
		}
		foreach ( $created['terms'] as $id ) {
			wp_delete_term( $id, 'product_cat' );
		}
		if ( WC()->customer ) {
			WC()->customer->set_billing_email( (string) $saved['email'] );
		}
		\WC_Cache_Helper::get_transient_version( 'shipping', true );
	}

	file_put_contents( $out_file, (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n" );
	printf( "写好了：%s（购物车 %d 个，前台 %d 项，后台页面 %d 个%s）\n", $out_file, count( $result['carts'] ), count( $result['front'] ), count( $result['admin'] ), isset( $result['error'] ) ? '；中途出错：' . $result['error'] : '' );
	exit( isset( $result['error'] ) ? 1 : 0 );
} )();
