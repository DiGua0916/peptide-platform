<?php
/**
 * 批次与报告的能力。管理员和 WooCommerce 的 shop_manager 获得全部能力。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Capabilities {

	public const ROLES = array( 'administrator', 'shop_manager' );

	/**
	 * register_post_type 的 capability_type 对应的基础能力（配合 map_meta_cap）。
	 *
	 * @return string[]
	 */
	public static function all(): array {
		$caps = array();
		foreach ( array( 'pc_batches', 'pc_reports' ) as $plural ) {
			$caps[] = "edit_{$plural}";
			$caps[] = "edit_others_{$plural}";
			$caps[] = "edit_published_{$plural}";
			$caps[] = "edit_private_{$plural}";
			$caps[] = "publish_{$plural}";
			$caps[] = "read_private_{$plural}";
			$caps[] = "delete_{$plural}";
			$caps[] = "delete_others_{$plural}";
			$caps[] = "delete_published_{$plural}";
			$caps[] = "delete_private_{$plural}";
		}
		return $caps;
	}

	public static function grant(): void {
		foreach ( self::ROLES as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( self::all() as $cap ) {
				$role->add_cap( $cap );
			}
		}
	}

	public static function revoke(): void {
		foreach ( self::ROLES as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( self::all() as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}
}
