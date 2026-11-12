<?php
/**
 * 营销弹窗“静默”开关（M4；已购回访 FE-10 由 F5 调用，接口见 docs/CONTRACT.md 的 M4 一节）。
 *
 * 过滤器 pc_marketing_popups_suppressed 返回真时，这一次请求里：
 * - 首单券条（First_Order_Offer）不输出；
 * - 离开挽回弹窗和抽奖转盘（Exit_Intent）不加载；
 * - 活动倒计时（Campaign_Countdown）的接口返回“不显示”，页面上的倒计时被隐藏。
 * 满减、优惠码、赠品、运费折扣等价格优惠不受影响，照常生效。
 *
 * 过滤器的默认值：浏览器带着 pc_marketing_quiet=1 这个 cookie 时为真（前台脚本
 * window.pcMarketingQuiet.suppress() 会写它），否则为假。带任何这类 cookie 的请求不走首页整页缓存，
 * 所以缓存里的页面永远是“不静默”的版本。
 *
 * 前台另有 assets/marketing-quiet.js（内联在页头）：让页面里已经输出的条和倒计时立刻隐藏，并给前台脚本一个统一的
 * 判断入口（window.pcMarketingQuiet），见该文件开头的说明。它只在这一次请求需要静默时输出；没有人要静默的页面
 * （也就是整页缓存里的页面、以及没开已购回访的店的全部页面）不带它，和合入 M4 之前逐字节相同。
 * 需要在不静默的页面上也能调用 window.pcMarketingQuiet.suppress() 的模块（只有浏览器才知道状态的情况），
 * 用过滤器 pc_marketing_quiet_script 返回 true，让每个前台页面都带上这段脚本。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Pricing;

defined( 'ABSPATH' ) || exit;

final class Popups {

	public const FILTER = 'pc_marketing_popups_suppressed';
	public const SCRIPT = 'pc_marketing_quiet_script';
	public const COOKIE = 'pc_marketing_quiet';
	public const ROUTE  = '/peptide/v1/campaign-countdown';

	public static function register_hooks(): void {
		add_action( 'wp_head', array( self::class, 'print_script' ), 1 );
		add_filter( 'rest_post_dispatch', array( self::class, 'quiet_countdown' ), 20, 3 );
	}

	/** 这一次请求是否静默。 */
	public static function suppressed(): bool {
		$cookie = isset( $_COOKIE[ self::COOKIE ] ) && '1' === $_COOKIE[ self::COOKIE ]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- 只和固定值比较。
		return (bool) apply_filters( self::FILTER, $cookie );
	}

	/** 页头内联一小段脚本（不多一次请求，赶在各弹窗脚本之前执行）。只在静默时、或有模块声明需要时输出。 */
	public static function print_script(): void {
		if ( is_admin() ) {
			return;
		}
		$quiet = self::suppressed();
		if ( ! $quiet && ! apply_filters( self::SCRIPT, false ) ) {
			return;
		}
		$file = PEPTIDE_CORE_DIR . '/assets/marketing-quiet.js';
		$js   = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- 读插件自带的文件。
		if ( '' === $js ) {
			return;
		}
		// 输出时去掉说明文字和缩进（文件里只有块注释），页头只多 1KB 左右。
		$js = implode( "\n", array_filter( array_map( 'trim', explode( "\n", (string) preg_replace( '#/\*.*?\*/#s', '', $js ) ) ) ) );
		echo '<script id="pc-marketing-quiet">' . ( $quiet ? 'window.pcMarketingQuietServer=true;' : '' ) . $js . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 插件自带的静态脚本。
	}

	/** 活动倒计时的接口：静默时两处位置都返回“不显示”。 */
	public static function quiet_countdown( $response, $server = null, $request = null ) {
		if ( $response instanceof \WP_REST_Response && $request instanceof \WP_REST_Request && self::ROUTE === $request->get_route() && self::suppressed() ) {
			$data = $response->get_data();
			if ( is_array( $data ) ) {
				foreach ( array( 'checkout', 'product' ) as $position ) {
					if ( isset( $data[ $position ] ) && is_array( $data[ $position ] ) ) {
						$data[ $position ]['visible'] = false;
					}
				}
				$response->set_data( $data );
			}
		}
		return $response;
	}
}
