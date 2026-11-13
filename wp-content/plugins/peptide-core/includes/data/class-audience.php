<?php
/**
 * 谁在看数据页（ST-05 / ST-06）：投手只看精简后的页面，管理员（含总后台一键登录）看全部。
 *
 * 投手 = 有角色 pc_media_buyer、且没有 manage_options 的用户。角色本身由 F5（M9 投手后台）创建和维护，
 * 这里只读；角色还不存在时所有人都按管理员处理，页面和以前一样。
 * 过滤器 peptide_core_is_media_buyer( bool $is, int $user_id ) 可以改写判断（例如预览投手视图）。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Data;

defined( 'ABSPATH' ) || exit;

final class Audience {

	public const ROLE = 'pc_media_buyer';

	/** $user_id 为 null 时看当前登录用户。 */
	public static function is_media_buyer( ?int $user_id = null ): bool {
		if ( ! function_exists( 'wp_get_current_user' ) ) {
			return false; // 没有 WordPress 的环境（纯逻辑测试）。
		}
		$user = null === $user_id ? wp_get_current_user() : get_userdata( $user_id );
		$is   = $user instanceof \WP_User && $user->exists() && in_array( self::ROLE, (array) $user->roles, true ) && ! user_can( $user, 'manage_options' );
		return (bool) apply_filters( 'peptide_core_is_media_buyer', $is, $user instanceof \WP_User ? (int) $user->ID : 0 );
	}
}
