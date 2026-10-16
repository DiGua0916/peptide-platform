<?php
/**
 * 插件能力。批次、报告和批次分配授予管理员与 WooCommerce 的 shop_manager；插件设置只授予管理员。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Capabilities {

	public const ROLES = array( 'administrator', 'shop_manager' );

	/** 只授予管理员的能力。 */
	public const ADMIN_ONLY = array( 'pc_manage_settings' );

	/**
	 * register_post_type 的 capability_type 对应的基础能力（配合 map_meta_cap），以及员工共用的能力。
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
		// 发货时给订单行指定批次（BE-04）。
		$caps[] = 'pc_assign_batches';
		return $caps;
	}

	/** 记录插件授出过哪些“角色:能力”，升级时只补授新增的。 */
	public const GRANTED_OPTION = 'peptide_core_granted_caps';

	/**
	 * @param bool $only_new true 时只授予以前没授过的能力（升级用），不恢复管理员手动移除的能力。
	 */
	public static function grant( bool $only_new = false ): void {
		$granted = $only_new ? (array) get_option( self::GRANTED_OPTION, array() ) : array();
		$plan    = array();
		foreach ( self::ROLES as $role_name ) {
			foreach ( self::all() as $cap ) {
				$plan[] = array( $role_name, $cap );
			}
		}
		foreach ( self::ADMIN_ONLY as $cap ) {
			$plan[] = array( 'administrator', $cap );
		}

		$record = (array) get_option( self::GRANTED_OPTION, array() );
		foreach ( $plan as [ $role_name, $cap ] ) {
			$key  = $role_name . ':' . $cap;
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			if ( ! $only_new || ! in_array( $key, $granted, true ) ) {
				$role->add_cap( $cap );
			}
			$record[] = $key;
		}
		update_option( self::GRANTED_OPTION, array_values( array_unique( $record ) ), false );
	}

	public static function revoke(): void {
		foreach ( self::ROLES as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( array_merge( self::all(), self::ADMIN_ONLY ) as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}
}
