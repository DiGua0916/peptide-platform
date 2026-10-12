<?php
/**
 * 报告下载权限。
 *
 * 规则（方案 v1 E 节 + v2 E' 节）：
 * - public：所有人；registered：已登录；approved：用户元数据 pc_buyer_approved 为真；admin：仅员工。
 * - 员工（有 edit_pc_reports 能力）可以下载任何报告。
 * - “订单所有者可下载本单批次报告”在 BE-04 通过 peptide_core_report_access 过滤器加入。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Access {

	public const APPROVED_META = 'pc_buyer_approved';

	public static function is_staff( int $user_id ): bool {
		return $user_id > 0 && user_can( $user_id, 'edit_pc_reports' );
	}

	public static function is_approved( int $user_id ): bool {
		return $user_id > 0 && Meta::bool( get_user_meta( $user_id, self::APPROVED_META, true ) );
	}

	/**
	 * @return array{allowed:bool,access_reason:?string,locked_reason:?string}
	 *   access_reason：允许时的依据（public / registered / approved / staff / order_owner）。
	 *   locked_reason：不允许时给前端的原因（login_required / approval_required），仅员工可见的报告为 null。
	 */
	public static function for_report( int $report_id, int $user_id ): array {
		$access = (string) get_post_meta( $report_id, Meta::REPORT_ACCESS, true );

		if ( self::is_staff( $user_id ) ) {
			$result = self::allow( 'staff' );
		} elseif ( 'public' === $access ) {
			$result = self::allow( 'public' );
		} elseif ( 'registered' === $access ) {
			$result = $user_id > 0 ? self::allow( 'registered' ) : self::deny( 'login_required' );
		} elseif ( 'approved' === $access ) {
			if ( $user_id <= 0 ) {
				$result = self::deny( 'login_required' );
			} else {
				$result = self::is_approved( $user_id ) ? self::allow( 'approved' ) : self::deny( 'approval_required' );
			}
		} else {
			$result = self::deny( null );
		}

		/**
		 * 允许其他模块（如 BE-04 的订单所有者规则）放宽权限。
		 *
		 * @param array $result    当前判断结果。
		 * @param int   $report_id 报告 ID。
		 * @param int   $user_id   用户 ID（访客为 0）。
		 */
		$filtered = apply_filters( 'peptide_core_report_access', $result, $report_id, $user_id );
		return is_array( $filtered ) && isset( $filtered['allowed'] ) ? $filtered + $result : $result;
	}

	/**
	 * @return array{allowed:bool,access_reason:?string,locked_reason:?string}
	 */
	private static function allow( string $reason ): array {
		return array(
			'allowed'       => true,
			'access_reason' => $reason,
			'locked_reason' => null,
		);
	}

	/**
	 * @return array{allowed:bool,access_reason:?string,locked_reason:?string}
	 */
	private static function deny( ?string $reason ): array {
		return array(
			'allowed'       => false,
			'access_reason' => null,
			'locked_reason' => $reason,
		);
	}
}
