<?php
/**
 * 前台的 5 个按店开关，放在店铺后台的“插件”页（Apps_Page，admin.php?page=pc-apps）里，每个一张卡片（F5，2026-11-16）。
 *
 * 平台所有者 2026-11-16：信任条“这个可以自定义打开”。原来这几个开关只能用命令行改选项，现在店主在后台自己开关：
 * - 商品弹窗信任条 pc_trust_badges、落地页提速 pc_front_speed、看过 / 已加购 pc_product_activity（连同下限
 *   pc_product_activity_min）、商品分享地址 pc_entry_address（这四个由主题读）、老客静默 pc_buyer_recognition（插件读）。
 * - 选项名和取值不变：值 on 才算开；关掉时删除选项（回到“从没设置过”，和命令行 wp option delete 一样）。
 *   页面只管读写这几个选项，每个功能本身的代码一行没动；进页面不会改任何开关的现值。
 * - 保存后调一次 Page_Cache::flush()：首页缓存和片段缓存的键都带这个代数，改完前台马上生效，不用再手动清缓存。
 * - 谁能用：和其他应用一样，manage_woocommerce（店主 / 管理员）；投手打不开插件页，保存请求也不在投手的名单里（403）。
 *   总后台停用某个应用时先通过 App_Switch 把它关掉，这几个也一样（set()）。
 * - 投手后台的 pc_buyer_no_palette、pc_buyer_extras 是员工权限类，不放在这里，仍用命令行。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Page_Cache;

defined( 'ABSPATH' ) || exit;

final class Storefront_Switches {

	public const ACTION     = 'pc_app_switch_save';
	public const CAP        = 'manage_woocommerce';
	public const MIN_OPTION = 'pc_product_activity_min';
	public const MIN_KEY    = 'product-activity';

	/**
	 * 5 个开关：应用键 => 选项名、名字、说明、图标。顺序就是插件页上的顺序（排在已有应用后面）。
	 *
	 * @return array<string,array{option:string,name:string,text:string,icon:string}>
	 */
	public static function definitions(): array {
		return array(
			'trust-badges'     => array(
				'option' => 'pc_trust_badges',
				'name'   => '商品弹窗信任条',
				'text'   => '商品弹窗里显示“Secure checkout · Encrypted connection”和店里已有的付款图标。',
				'icon'   => 'dashicons-shield',
			),
			'front-speed'      => array(
				'option' => 'pc_front_speed',
				'name'   => '落地页提速',
				'text'   => '广告落地的首页、商品页、专辑页首屏少等样式文件，商品内容随页面一起送到，打开更快。',
				'icon'   => 'dashicons-performance',
			),
			'product-activity' => array(
				'option' => 'pc_product_activity',
				'name'   => '看过 / 已加购',
				'text'   => '商品弹窗里显示最近有多少人看过、加入购物车；人数低于下限时不显示。',
				'icon'   => 'dashicons-groups',
			),
			'entry-address'    => array(
				'option' => 'pc_entry_address',
				'name'   => '商品分享地址',
				'text'   => '商品弹窗打开时，地址栏显示这件商品自己的链接，方便顾客复制分享；打开链接照旧是首页 + 商品弹窗。',
				'icon'   => 'dashicons-admin-links',
			),
			'returning-buyer'  => array(
				'option' => 'pc_buyer_recognition',
				'name'   => '老客静默',
				'text'   => '认出付过款的顾客（这台设备付过款，或登录的账户名下有已付款订单），不再给他弹营销弹窗。',
				'icon'   => 'dashicons-businessperson',
			),
		);
	}

	public static function handles( string $key ): bool {
		return isset( self::definitions()[ $key ] );
	}

	/** 选项现在的值算不算开（不读数据库，测试里直接调用）：只有 on 算开，和每个功能自己的判断一样。 */
	public static function is_on( $value ): bool {
		return 'on' === (string) $value;
	}

	/** 这个开关现在开着没有（直接读选项，不经过各功能自己的过滤器）。 */
	public static function on( string $key ): bool {
		$def = self::definitions()[ $key ] ?? null;
		return $def ? self::is_on( get_option( $def['option'], 'off' ) ) : false;
	}

	/**
	 * 给 Apps_Page::catalog() 用：和已有应用同样的四个字段。
	 *
	 * @return array<string,array{name:string,text:string,icon:string,on:bool}>
	 */
	public static function catalog(): array {
		$out = array();
		foreach ( self::definitions() as $key => $def ) {
			$out[ $key ] = array(
				'name' => $def['name'],
				'text' => $def['text'],
				'icon' => $def['icon'],
				'on'   => self::on( $key ),
			);
		}
		return $out;
	}

	/**
	 * 看过 / 已加购的人数下限（不读数据库，测试里直接调用）：1–1000 的整数；不是的话返回 null（不保存）。
	 * 和主题 vc_product_activity_min() 认的范围一样。
	 */
	public static function normalize_min( $raw ): ?int {
		$raw = is_string( $raw ) ? trim( $raw ) : $raw;
		if ( is_string( $raw ) && 1 === preg_match( '/\A[0-9]{1,6}\z/D', $raw ) ) {
			$raw = (int) $raw;
		}
		return is_int( $raw ) && $raw >= 1 && $raw <= 1000 ? $raw : null;
	}

	/**
	 * 打开或关掉一个开关，然后清一次首页缓存。总后台停用应用时 App_Switch 也走这里。
	 * 开 = 写 on；关 = 删除选项。状态没变时什么都不写。
	 *
	 * @return true|\WP_Error
	 */
	public static function set( string $key, bool $on ) {
		$def = self::definitions()[ $key ] ?? null;
		if ( ! $def ) {
			return new \WP_Error( 'pc_app_unknown', '店铺里没有这个应用。' );
		}
		if ( self::on( $key ) === $on ) {
			return true;
		}
		if ( $on ) {
			update_option( $def['option'], 'on' );
		} else {
			delete_option( $def['option'] );
		}
		self::flush();
		return true;
	}

	private static function flush(): void {
		if ( class_exists( Page_Cache::class ) ) {
			Page_Cache::flush();
		}
	}

	public static function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'save' ) );
	}

	private static function access(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( '没有修改插件设置的权限。', '', array( 'response' => 403 ) );
		}
	}

	/** admin-post.php?action=pc_app_switch_save：一个开关的设置页提交到这里。 */
	public static function save(): void {
		self::access();
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( '只接受 POST。', '', array( 'response' => 405 ) );
		}
		$key = isset( $_POST['app'] ) && is_string( $_POST['app'] ) ? sanitize_key( wp_unslash( $_POST['app'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- 下一行核对。
		if ( ! self::handles( $key ) ) {
			wp_die( '店铺里没有这个应用。', '', array( 'response' => 400 ) );
		}
		check_admin_referer( self::ACTION . '_' . $key );
		Apps_Page::require_allowed( $key );
		$on  = ! empty( $_POST['enabled'] );
		$min = null;
		if ( self::MIN_KEY === $key && isset( $_POST['min'] ) ) {
			$min = self::normalize_min( wp_unslash( $_POST['min'] ) );
			if ( null === $min ) {
				// 下限填错时这次什么都不存，免得开关存了、下限没存。
				wp_safe_redirect( Apps_Page::url( array( 'app' => $key, 'saved' => 'min' ) ) );
				exit;
			}
		}
		$changed = self::on( $key ) !== $on;
		self::set( $key, $on );
		if ( null !== $min ) {
			$current = get_option( self::MIN_OPTION, null );
			if ( null === $current ? 5 !== $min : (string) $current !== (string) $min ) {
				update_option( self::MIN_OPTION, (string) $min );
				self::flush();
				$changed = true;
			}
		}
		if ( $changed && class_exists( \PeptideCore\Activity_Log::class ) ) {
			\PeptideCore\Activity_Log::log( 'settings', $on ? 'app_on' : 'app_off', array( 'type' => 'app', 'id' => 0, 'label' => $key ), array( 'via' => 'store' ) );
		}
		wp_safe_redirect( Apps_Page::url( array( 'app' => $key, 'saved' => '1' ) ) );
		exit;
	}

	/** 一个开关的设置页：一个“开启”勾选框和一句说明（看过 / 已加购另有人数下限）。 */
	public static function render( string $key ): void {
		self::access();
		Apps_Page::require_allowed( $key );
		$def = self::definitions()[ $key ] ?? null;
		if ( ! $def ) {
			return;
		}
		$saved = isset( $_GET['saved'] ) && is_string( $_GET['saved'] ) ? sanitize_key( wp_unslash( $_GET['saved'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只决定显示哪句提示。
		echo '<div class="wrap pc-mk pc-app-switch"><a href="' . esc_url( Apps_Page::url() ) . '">返回插件</a><h1>' . esc_html( $def['name'] ) . '</h1><p>' . esc_html( $def['text'] ) . '</p>';
		if ( '1' === $saved ) {
			echo '<div class="notice notice-success"><p>已保存，前台马上生效。</p></div>';
		} elseif ( 'min' === $saved ) {
			echo '<div class="notice notice-error"><p>没有保存：人数下限要填 1 到 1000 的整数。</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION . '_' . $key );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="app" value="' . esc_attr( $key ) . '">';
		echo '<p><label><input type="checkbox" name="enabled" value="1"' . checked( self::on( $key ), true, false ) . '> 开启</label></p>';
		if ( self::MIN_KEY === $key ) {
			$min = self::normalize_min( get_option( self::MIN_OPTION, 5 ) ) ?? 5;
			echo '<p><label>人数下限 <input type="number" name="min" min="1" max="1000" step="1" value="' . esc_attr( (string) $min ) . '" class="small-text"></label> <span class="description">看过或加购的人数低于这个数时不显示。</span></p>';
		}
		echo '<p class="description">只改这家店。保存后前台马上生效（会清一次首页缓存）。</p>';
		submit_button( '保存' );
		echo '</form></div>';
	}
}
