<?php
/**
 * 价格引擎的按店开关（M4 / NF-02，开发文档 12.3）。每家店一个选项，三档：
 *
 * - off    关：只走旧逻辑。引擎的代码已部署但什么都不挂，站点和以前完全一样。（默认）
 * - shadow 影子：顾客看到的仍是旧逻辑的结果；每次购物车算完后引擎在请求末尾再算一遍，不一致就记日志。
 * - on     开：满减金额、赠品 / 捆绑的行价、运费折扣改由引擎给出（引擎算不了或出错时自动退回旧逻辑）；
 *          请求末尾继续核对并记日志。
 *
 * 出问题时把开关拨回 off 即可，不用回滚代码。紧急情况可以在 wp-config.php 里写
 * define( 'PC_PRICING_ENGINE_MODE', 'off' ); 它优先于后台和总后台的设置。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Pricing;

defined( 'ABSPATH' ) || exit;

final class Mode {

	public const OPTION = 'pc_pricing_engine_mode';
	public const OFF    = 'off';
	public const SHADOW = 'shadow';
	public const ON     = 'on';
	public const ALL    = array( self::OFF, self::SHADOW, self::ON );

	public static function get(): string {
		$mode = defined( 'PC_PRICING_ENGINE_MODE' ) ? (string) constant( 'PC_PRICING_ENGINE_MODE' ) : (string) get_option( self::OPTION, self::OFF );
		return in_array( $mode, self::ALL, true ) ? $mode : self::OFF;
	}

	/** wp-config.php 里的常量锁定了档位时，后台和总后台改不了。 */
	public static function locked(): bool {
		return defined( 'PC_PRICING_ENGINE_MODE' );
	}

	public static function is_on(): bool {
		return self::ON === self::get();
	}

	/** @return true|\WP_Error */
	public static function set( string $mode ) {
		if ( ! in_array( $mode, self::ALL, true ) ) {
			return new \WP_Error( 'pc_pricing_mode', '价格引擎只有三档：off（关）、shadow（影子）、on（开）。' );
		}
		if ( self::locked() ) {
			return new \WP_Error( 'pc_pricing_locked', 'wp-config.php 里的 PC_PRICING_ENGINE_MODE 锁定了档位，请先改那里。' );
		}
		// 自动加载：每个请求都要读一次，不额外查数据库。
		update_option( self::OPTION, $mode, true );
		return true;
	}
}
