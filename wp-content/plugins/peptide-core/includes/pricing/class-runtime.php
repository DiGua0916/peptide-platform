<?php
/**
 * 价格引擎接到站点上的那一层（M4 / NF-02）。class-plugin.php 只注册这一个类。
 *
 * - 关（默认）：除了总后台接口和命令行，什么都不挂。
 * - 影子 / 开：请求末尾核对（Shadow::compare）。
 * - 开：旧类在原来的钩子里改为向这里要数——满减（Promotions::discounts）、赠品 / 捆绑的行价
 *   （Product_Offers::sync_cart 整理完购物车之后）、运费折扣（Shipping\Discounts::apply）。
 *   钩子、回调函数名、费用 ID、订单上记的内容都不变，所以加购推荐报价、结账、订单这些下游不受影响。
 *   引擎出错或算不了时返回 null，旧类照旧自己算，并且这一次请求之内不再接管。
 *   优惠码“能不能用”仍由原来挂在 woocommerce_coupon_is_valid 上的四个检查执行（WooCommerce 是优惠码的主人），
 *   引擎在核对时复算一遍。
 *
 * 总后台接口（签名校验用 Hq_Connector::verify）：
 *   GET  peptide/v1/hq/pricing-engine          → { mode, locked, engine, summary }
 *   POST peptide/v1/hq/pricing-engine { mode } → 改档位，返回同上
 *
 * @package PeptideCore
 */

namespace PeptideCore\Pricing;

use PeptideCore\Hq_Connector;
use PeptideCore\Product_Offers;
use PeptideCore\Shipping\Discounts;

defined( 'ABSPATH' ) || exit;

final class Runtime {

	/** 这一次请求里引擎出过错：之后都走旧逻辑。 */
	private static bool $failed = false;

	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_filter( 'pc_hq_capabilities', array( self::class, 'capabilities' ) );
		Popups::register_hooks(); // 营销弹窗静默开关和价格无关，借这里注册，入口文件不用再加一行。
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'peptide price-engine', Cli::class );
		}
		if ( Mode::OFF !== Mode::get() ) {
			add_action( 'shutdown', array( Shadow::class, 'compare' ), 5 );
		}
	}

	/** 向总后台声明：这家店的代码支持价格引擎开关（F1 的 pc_hq_capabilities 钩子）。 */
	public static function capabilities( $list ) {
		$list   = is_array( $list ) ? $list : array();
		$list[] = 'pricing_engine';
		$list[] = 'marketing_quiet';
		return array_values( array_unique( $list ) );
	}

	/** 现在是否由引擎接管。 */
	public static function on(): bool {
		return ! self::$failed && Mode::ON === Mode::get();
	}

	private static function fail( string $where, \Throwable $e ): void {
		self::$failed = true;
		Shadow::error( Mode::get(), $where, $e );
	}

	/** 满减用到的最小输入：商品行、挂着的优惠码和策略、进行中的活动、顾客。 */
	private static function base_input( \WC_Cart $cart, array $facts ): array {
		$promotions = Cart_Reader::promotions();
		$customer   = Cart_Reader::customer( $cart );
		$customer['visitor_id'] = Cart_Reader::visitor_id( array_map( static fn( $c ) => (array) ( $c['customer_rule'] ?? array() ), $promotions ) );
		return array(
			'decimals'   => wc_get_price_decimals(),
			'lines'      => Cart_Reader::lines( $cart, $facts ),
			'coupons'    => Cart_Reader::coupon_strategies( $cart ),
			'promotions' => $promotions,
			'customer'   => $customer,
		);
	}

	/**
	 * 开启档：生效的满减和各自的金额（Promotions::discounts 的引擎版）。
	 *
	 * @return array{applied:array[],evals:array[]}|null 出错时为 null。
	 */
	public static function promotions( \WC_Cart $cart, string $skip_code = '' ): ?array {
		try {
			$subtotals = array();
			foreach ( $cart->get_cart() as $key => $item ) {
				$subtotals[ $key ] = (float) ( $item['line_subtotal'] ?? 0 );
			}
			return Promotion_Math::discounts( self::base_input( $cart, array() ), $subtotals, wc_format_coupon_code( $skip_code ) );
		} catch ( \Throwable $e ) {
			self::fail( 'promotions', $e );
			return null;
		}
	}

	/** 开启档：旧逻辑整理完购物车之后，赠品 / 捆绑行的单价改用引擎算的。结构上看法不同或出错时不动。 */
	public static function offer_prices( \WC_Cart $cart ): void {
		try {
			$facts                 = Product_Offers::pricing_facts( $cart );
			$input                 = self::base_input( $cart, $facts );
			$input['offers']       = $facts['offers'];
			$input['offer_groups'] = $facts['groups'];
			$resolved              = Offer_Math::resolve( $input );
			if ( $resolved['actions'] ) {
				throw new \RuntimeException( 'offer_structure: ' . wp_json_encode( $resolved['actions'] ) );
			}
			foreach ( $input['lines'] as $key => $line ) {
				if ( $line['has_meta'] && isset( $cart->cart_contents[ $key ]['data'] ) ) {
					$cart->cart_contents[ $key ]['data']->set_price( $resolved['unit'][ $key ] );
				}
			}
		} catch ( \Throwable $e ) {
			self::fail( 'offers', $e );
		}
	}

	/**
	 * 开启档：当前顾客现在可以用的运费折扣。
	 *
	 * @return array<int,array{0:array,1:float}>|null 出错时为 null。
	 */
	public static function shipping_applicable(): ?array {
		try {
			$discounts              = array_values( Discounts::all() );
			$cart                   = function_exists( 'WC' ) && WC()->cart instanceof \WC_Cart ? WC()->cart : null;
			$customer               = Cart_Reader::customer( $cart );
			$customer['visitor_id'] = Cart_Reader::visitor_id( $discounts );
			return Shipping_Math::applicable( $discounts, $customer, time(), (int) wp_date( 'N' ) );
		} catch ( \Throwable $e ) {
			self::fail( 'shipping', $e );
			return null;
		}
	}

	// ---------- 总后台接口 ----------

	public static function routes(): void {
		$auth = array( Hq_Connector::class, 'verify' );
		register_rest_route(
			Hq_Connector::NS,
			'/hq/pricing-engine',
			array(
				array( 'methods' => 'GET', 'callback' => array( self::class, 'status_route' ), 'permission_callback' => $auth ),
				array( 'methods' => 'POST', 'callback' => array( self::class, 'mode_route' ), 'permission_callback' => $auth ),
			)
		);
	}

	public static function status(): array {
		return array( 'mode' => Mode::get(), 'locked' => Mode::locked(), 'engine' => Engine::VERSION, 'summary' => Shadow::summary( 7, 20 ) );
	}

	public static function status_route() {
		return self::status();
	}

	public static function mode_route( \WP_REST_Request $request ) {
		$p    = (array) $request->get_json_params();
		$done = Mode::set( is_string( $p['mode'] ?? null ) ? $p['mode'] : '' );
		if ( is_wp_error( $done ) ) {
			$done->add_data( array( 'status' => 400 ) );
			return $done;
		}
		return self::status();
	}

	/** 测试用。 */
	public static function reset(): void {
		self::$failed = false;
	}
}
