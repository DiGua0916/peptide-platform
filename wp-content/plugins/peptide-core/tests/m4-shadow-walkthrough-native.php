<?php
/**
 * M4 价格引擎 · 沙盒走查（真实的 WordPress + WooCommerce，影子档和开档）。
 *
 * 做什么：在这个站上临时建一批测试商品、满减活动、优惠码、赠品 / 捆绑、运费折扣，用真实的 WooCommerce 购物车
 * 把各种组合逐个走一遍；每个组合算完后，引擎用同一份购物车再算一遍，和 WooCommerce 给出的数字逐项核对
 * （和影子档在请求末尾做的是同一件事）。然后把档位临时拨到“开”再走一遍，确认顾客看到的数字和关着时逐分相同。
 * 结束时删掉临时数据，把改过的选项和档位恢复原样。
 *
 * 运行（只能在沙盒 / 本地站点；域名里要带 sandbox、localhost、local、test 或 staging，否则拒绝运行）：
 *
 *   M4_WALKTHROUGH=sandbox wp eval-file wp-content/plugins/peptide-core/tests/m4-shadow-walkthrough-native.php
 *
 * 通过的标准：最后一行是 “RESULT … 全部一致”，退出码 0。有 FAIL 时每条下面有新旧两边的数字，原样发给 F2。
 * “跳过”不算失败，但会列出原因（比如这个站价格含税）；全部都是跳过时也算没跑通，退出码 2。
 *
 * 它不建订单、不发邮件、不碰支付和顾客资料。“下过单的人不能用首单券”这一项用站里已有的一张已付款订单的邮箱来验证
 * （只读，不显示邮箱）；站里没有已付款订单时这一项不跑。
 *
 * @package PeptideCore
 */

use PeptideCore\Coupon_Rules;
use PeptideCore\First_Order_Offer;
use PeptideCore\Manual_Orders;
use PeptideCore\Pricing\Mode;
use PeptideCore\Pricing\Runtime;
use PeptideCore\Pricing\Shadow;
use PeptideCore\Product_Offers;
use PeptideCore\Promotions;
use PeptideCore\Shipping\Discounts;

defined( 'ABSPATH' ) || exit;

( static function (): void {
	$say  = static function ( string $line ): void {
		echo $line . "\n";
	};
	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	if ( 'sandbox' !== getenv( 'M4_WALKTHROUGH' ) || ! preg_match( '/(^|[.\-])(sandbox|localhost|local|test|staging)([.\-]|$)/i', $host ) ) {
		$say( '拒绝运行：这个脚本会临时建商品、优惠码并改选项，只能在沙盒 / 本地站点跑。' );
		$say( '需要：环境变量 M4_WALKTHROUGH=sandbox，并且站点域名里带 sandbox / localhost / local / test / staging（当前域名：' . $host . '）。' );
		exit( 3 );
	}
	if ( ! function_exists( 'WC' ) || ! class_exists( Shadow::class ) ) {
		$say( '这个站没有启用 WooCommerce 或 peptide-core 里还没有价格引擎。' );
		exit( 3 );
	}
	if ( null === WC()->cart ) {
		wc_load_cart();
	}
	$cart = WC()->cart;

	// ---------- 记下要恢复的东西 ----------
	$saved   = array(
		'mode'     => get_option( Mode::OPTION, null ),
		'first'    => get_option( First_Order_Offer::OPTION, null ),
		'shipdisc' => get_option( Discounts::OPTION, null ),
		'manual'   => get_option( Manual_Orders::OPTION, null ),
		'email'    => WC()->customer ? WC()->customer->get_billing_email() : '',
	);
	$created = array( 'posts' => array(), 'terms' => array() );
	$restore = static function () use ( &$saved, &$created, $cart ): void {
		$cart->empty_cart();
		foreach ( array( 'mode' => Mode::OPTION, 'first' => First_Order_Offer::OPTION, 'shipdisc' => Discounts::OPTION, 'manual' => Manual_Orders::OPTION ) as $key => $option ) {
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
		if ( class_exists( '\WC_Cache_Helper' ) ) {
			\WC_Cache_Helper::get_transient_version( 'shipping', true );
		}
	};

	$failed  = 0;
	$matched = 0;
	$skipped = array();
	try {
		// ---------- 临时数据 ----------
		$tag     = 'm4wt' . wp_rand( 1000, 9999 );
		$term    = wp_insert_term( 'M4 walkthrough ' . $tag, 'product_cat', array( 'slug' => $tag ) );
		$cat     = is_wp_error( $term ) ? 0 : (int) $term['term_id'];
		$created['terms'][] = $cat;
		$product = static function ( string $name, string $price, string $regular = '', array $cats = array() ) use ( $tag, &$created ): int {
			$p = new \WC_Product_Simple();
			$p->set_name( 'M4WT ' . $name . ' ' . $tag );
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
			$id = (int) wp_insert_post( array( 'post_type' => Promotions::CPT, 'post_status' => 'publish', 'post_title' => 'M4WT ' . $title . ' ' . $tag ) );
			update_post_meta( $id, Promotions::META, array_merge( Promotions::defaults(), array( 'type' => $type, 'basis' => in_array( $type, array( 'spend_amount', 'spend_percent' ), true ) ? 'spend' : 'quantity', 'reward' => in_array( $type, array( 'spend_percent', 'qty_percent', 'nth_percent' ), true ) ? 'percent' : 'amount', 'tiers' => $rows ), $extra ) );
			$created['posts'][] = $id;
			return $id;
		};
		$p1 = $promo( 'spend', 'spend_amount', array( array( 100, 10 ), array( 200, 25 ) ), array( 'scope' => 'products', 'products' => array( $f, $g ) ) );
		$promo( 'qty percent', 'qty_percent', array( array( 2, 10 ) ), array( 'scope' => 'categories', 'categories' => array( $cat ) ) );
		$promo( 'nth, no coupons', 'nth_percent', array( array( 2, 30 ) ), array( 'scope' => 'products', 'products' => array( $c ), 'with_coupons' => false ) );

		$coupon = static function ( string $code, string $type, string $amount, array $meta = array(), array $props = array() ) use ( $tag, &$created ): string {
			$k = new \WC_Coupon();
			$k->set_code( $tag . $code );
			$k->set_discount_type( $type );
			$k->set_amount( $amount );
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
			$id = (int) wp_insert_post( array( 'post_type' => Product_Offers::CPT, 'post_status' => 'publish', 'post_title' => 'M4WT ' . $title . ' ' . $tag ) );
			update_post_meta( $id, Product_Offers::META, array_merge( Product_Offers::defaults(), array( 'enabled' => true ), $config ) );
			$created['posts'][] = $id;
			return $id;
		};
		$offer( 'gift', array( 'kind' => 'gift', 'basis' => 'spend', 'threshold' => 100.0, 'qualifiers' => array( $d ), 'gift_product' => $e, 'gift_qty' => 1 ) );
		$bundle = $offer( 'bundle', array( 'kind' => 'bundle', 'price_type' => 'fixed', 'price_value' => 40.0, 'slots' => array( array( 'id' => 'slot-0', 'label' => 'H', 'qty' => 1, 'choices' => array( $h ) ), array( 'id' => 'slot-1', 'label' => 'I', 'qty' => 2, 'choices' => array( $i ) ) ) ) );

		$all   = is_array( $saved['shipdisc'] ) ? $saved['shipdisc'] : array();
		$all[] = array_merge( Discounts::defaults(), array( 'id' => 900000 + wp_rand( 1, 9999 ), 'name' => 'M4WT half shipping ' . $tag, 'kind' => 'percent', 'value' => 50.0, 'created' => time() ) );
		update_option( Discounts::OPTION, $all, false );
		\WC_Cache_Helper::get_transient_version( 'shipping', true );

		// “下过单的邮箱”：用站里已有的已付款订单（只读）。不新建订单——建一张已完成的订单会触发发信、积分、上报这些后续动作。
		$old_email = '';
		foreach ( wc_get_orders( array( 'status' => wc_get_is_paid_statuses(), 'limit' => 5 ) ) as $paid ) {
			if ( $paid instanceof \WC_Order && is_email( $paid->get_billing_email() ) ) {
				$old_email = strtolower( $paid->get_billing_email() );
				break;
			}
		}

		// 运费要有地址才会算：用美国地址（和线上顾客一样）。
		WC()->customer->set_shipping_country( 'US' );
		WC()->customer->set_shipping_state( 'CA' );
		WC()->customer->set_shipping_postcode( '94016' );
		WC()->customer->set_shipping_city( 'San Francisco' );
		WC()->customer->set_billing_country( 'US' );
		WC()->customer->set_calculated_shipping( true );

		// ---------- 组合 ----------
		$plain  = static fn( array $lines, array $codes = array(), string $email = 'm4wt-new@example.invalid' ) => array( 'lines' => $lines, 'codes' => $codes, 'email' => $email );
		$combos = array(
			'单品，没有优惠'                                     => $plain( array( array( $a, 1 ) ) ),
			'多品，没有优惠'                                     => $plain( array( array( $h, 2 ), array( $i, 1 ) ) ),
			'满额减元：刚好到门槛（$100.00）'                      => $plain( array( array( $f, 2 ) ) ),
			'满额减元：差一分（$99.99）'                           => $plain( array( array( $f, 1 ), array( $g, 1 ) ) ),
			'满额减元：第二档（$200.00）'                          => $plain( array( array( $f, 4 ) ) ),
			'两个活动各管各的：专辑满件打折 + 第 2 件 7 折'           => $plain( array( array( $a, 2 ), array( $b, 1 ), array( $c, 2 ) ) ),
			'优惠码 10% 叠加满减'                                 => $plain( array( array( $f, 2 ) ), array( $save10 ) ),
			'“不叠加”的优惠码遇到生效的满减（应被拒绝）'             => $plain( array( array( $f, 2 ) ), array( $solo ) ),
			'“不叠加”的优惠码，满减没到门槛'                        => $plain( array( array( $f, 1 ) ), array( $solo ) ),
			'“替换满减”的优惠码'                                  => $plain( array( array( $f, 2 ) ), array( $swap ) ),
			'活动设了不能和优惠码同时使用 + 优惠码'                  => $plain( array( array( $c, 2 ) ), array( $save10 ) ),
			'两张优惠码：按比例 + 固定金额，三种商品'                 => $plain( array( array( $h, 1 ), array( $i, 1 ), array( $e, 3 ) ), array( $five, $save10 ) ),
			'每件减 $3、最多 2 件、不含促销商品'                    => $plain( array( array( $d, 1 ), array( $h, 3 ) ), array( $each3 ) ),
			'优惠码的满金额条件：够'                               => $plain( array( array( $h, 3 ) ), array( $min60 ) ),
			'优惠码的满金额条件：不够（应被拒绝）'                    => $plain( array( array( $h, 2 ) ), array( $min60 ) ),
			'首单券：新顾客'                                      => $plain( array( array( $h, 2 ) ), array( $welcome ) ),
			'首单券 + 满减'                                       => $plain( array( array( $f, 2 ) ), array( $welcome ) ),
			'首单券：下过单的邮箱（应被拒绝）'                       => '' !== $old_email ? $plain( array( array( $h, 2 ) ), array( $welcome ), $old_email ) : null,
			'赠品：促销商品满 $100 自动送一件'                      => $plain( array( array( $d, 2 ) ) ),
			'赠品遇到优惠码：不送'                                 => $plain( array( array( $d, 2 ) ), array( $save10 ) ),
			'捆绑一口价 + 一件普通商品'                            => array( 'lines' => array( array( $c, 1 ) ), 'codes' => array(), 'email' => 'm4wt-new@example.invalid', 'bundle' => array( $bundle, array( 'slot-0' => $h ), 1 ) ),
			'离开挽回 / 转盘发的券（记在会话里）+ 专辑满件打折'        => array( 'lines' => array( array( $a, 2 ) ), 'codes' => array( $five ), 'email' => 'm4wt-new@example.invalid', 'exit' => $five ),
			'手动订单：改价 + 自定义运费 + 自定义优惠，不叠加店铺活动'   => array( 'manual' => array( 'items' => array( array( 'id' => $f, 'qty' => 2, 'price' => 45, 'regular' => 50 ) ), 'shipping' => array( 'label' => 'Courier', 'amount' => 8 ), 'discounts' => array( array( 'label' => 'VIP', 'amount' => 5 ) ), 'days' => 1 ), 'email' => 'm4wt-new@example.invalid' ),
			'手动订单：开店铺满减（选一个活动）+ 开首单优惠'          => array( 'manual' => array( 'items' => array( array( 'id' => $f, 'qty' => 2, 'price' => 50, 'regular' => 50 ) ), 'promo_ids' => array( $p1 ), 'first_order' => true, 'days' => 1 ), 'email' => 'm4wt-new@example.invalid' ),
		);
		$combos = array_filter( $combos );
		if ( '' === $old_email ) {
			$say( '（站里没有已付款订单，“下过单的邮箱不能用首单券”这一项不跑。）' );
		}

		/** 按组合把购物车搭出来并算一遍。 */
		$build = static function ( array $combo ) use ( $cart ): void {
			$cart->empty_cart();
			$cart->remove_coupons();
			wc_clear_notices();
			WC()->session->set( 'pc_exit_intent_code', isset( $combo['exit'] ) ? $combo['exit'] : null );
			WC()->session->set( 'chosen_shipping_methods', null );
			WC()->customer->set_billing_email( $combo['email'] );
			if ( isset( $combo['manual'] ) ) {
				$link = Manual_Orders::create( $combo['manual'] );
				foreach ( $link['items'] as $item ) {
					$cart->add_to_cart( $item['id'], $item['qty'], 0, array(), array( 'pc_manual' => $link['token'], 'pc_m_price' => $item['price'], 'pc_m_regular' => $item['regular'] ) );
				}
				$first = ! empty( $link['first_order'] ) ? Manual_Orders::first_order_coupon() : null;
				if ( $first ) {
					$cart->applied_coupons = array( wc_format_coupon_code( $first->get_code() ) ); // 链接打开时自动带上首单券。
				}
			} else {
				foreach ( $combo['lines'] as [ $id, $qty ] ) {
					$cart->add_to_cart( $id, $qty );
				}
				if ( isset( $combo['bundle'] ) ) {
					$added = Product_Offers::add_bundle( $combo['bundle'][0], $combo['bundle'][1], $combo['bundle'][2], $cart );
					if ( is_wp_error( $added ) ) {
						echo '      （捆绑没加进去：' . $added->get_error_message() . "）\n";
					}
				}
				foreach ( $combo['codes'] as $code ) {
					$cart->apply_coupon( $code ); // 真实的添加流程；被拒绝的券不会留在购物车上。
				}
			}
			wc_clear_notices();
			$cart->calculate_totals();
		};

		$totals = array();
		foreach ( array( Mode::SHADOW, Mode::ON ) as $mode ) {
			update_option( Mode::OPTION, $mode, true );
			$say( '' );
			$say( '== 档位：' . $mode . ' ==' );
			foreach ( $combos as $name => $combo ) {
				Runtime::reset();
				$build( $combo );
				$check = Shadow::check( $cart );
				$seen  = $check['legacy'];
				// 购物车行的编号每次搭出来都不一样（捆绑组、手动订单链接带随机串），比单价时不看编号。
				$seen['units'] = array_values( $seen['units'] );
				sort( $seen['units'] );
				$line  = sprintf( '%s  总额 %s  优惠码 %s  满减 %s  运费 %s', $name, number_format( $seen['total'] / 100, 2 ), number_format( $seen['discount'] / 100, 2 ), number_format( array_sum( $seen['fees'] ) / 100, 2 ), number_format( $seen['shipping'] / 100, 2 ) );
				$bad   = array();
				if ( 'skipped' === $check['kind'] ) {
					$skipped[ implode( ',', $check['reason'] ) ] = true;
					$say( '跳过  ' . $line . '  原因：' . implode( ',', $check['reason'] ) );
					continue;
				}
				if ( 'matched' !== $check['kind'] ) {
					$bad[] = '引擎和线上不一致：' . wp_json_encode( $check['diff'], JSON_UNESCAPED_UNICODE );
				}
				if ( Mode::ON === $mode ) {
					if ( ! Runtime::on() ) {
						$bad[] = '开档下引擎没有接管（退回了旧逻辑），看 wp peptide price-engine log';
					}
					if ( isset( $totals[ $name ] ) && $totals[ $name ] != $seen ) { // phpcs:ignore Universal.Operators.StrictComparisons
						$bad[] = '开档和影子档顾客看到的数字不同：影子 ' . wp_json_encode( $totals[ $name ] ) . ' / 开 ' . wp_json_encode( $seen );
					}
				} else {
					$totals[ $name ] = $seen;
				}
				if ( $bad ) {
					++$failed;
					$say( 'FAIL  ' . $line );
					foreach ( $bad as $text ) {
						$say( '      ' . $text );
					}
				} else {
					++$matched;
					$say( 'ok    ' . $line );
				}
			}
		}

		// 请求末尾的入口也走一次：真的往统计表里记一笔。
		update_option( Mode::OPTION, Mode::SHADOW, true );
		$build( $combos['优惠码 10% 叠加满减'] );
		WC()->session->set( Shadow::SESSION, null );
		Shadow::reset();
		Shadow::compare();
		$today = Shadow::summary( 1, 5 );
		$say( '' );
		$say( '请求末尾的核对记录（今天，影子档）：' . wp_json_encode( $today['totals']['shadow'] ?? array(), JSON_UNESCAPED_UNICODE ) );
		if ( empty( $today['totals']['shadow']['compared'] ) ) {
			++$failed;
			$say( 'FAIL  请求末尾的核对没有写进统计表' );
		}
	} catch ( \Throwable $error ) {
		++$failed;
		$say( 'FAIL  走查中途出错：' . get_class( $error ) . '：' . $error->getMessage() . ' @ ' . basename( $error->getFile() ) . ':' . $error->getLine() );
	} finally {
		$restore();
	}

	$say( '' );
	if ( $skipped ) {
		$say( '跳过的原因：' . implode( '；', array_keys( $skipped ) ) . '（引擎不支持这种设置，见交接文档第二部分第 3 点）' );
	}
	$say( sprintf( 'RESULT 一致 %d，不一致 %d，跳过原因 %d 种：%s', $matched, $failed, count( $skipped ), $failed ? '有不一致，不要往下切档' : ( $matched ? '全部一致' : '没有一个组合被核对，不算跑通' ) ) );
	$say( '临时数据已删除，档位和选项已恢复为运行前的值（档位：' . Mode::get() . '）。' );
	exit( $failed ? 1 : ( $matched ? 0 : 2 ) );
} )();
