<?php
/**
 * 统一错误格式（方案 v1 D1）：{ code, message, data: { status, fields? } }。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Api;

defined( 'ABSPATH' ) || exit;

final class Errors {

	/**
	 * @param array<string,string> $fields 字段 => 原因码（required / invalid_format / ...）。
	 */
	public static function invalid( array $fields ): \WP_Error {
		return new \WP_Error(
			'pc_invalid_param',
			__( 'Invalid request parameters.', 'peptide-core' ),
			array(
				'status' => 400,
				'fields' => $fields,
			)
		);
	}

	/**
	 * 不存在与不可见不作区分，避免暴露资源是否存在。
	 */
	public static function not_found(): \WP_Error {
		return new \WP_Error( 'pc_not_found', __( 'Not found.', 'peptide-core' ), array( 'status' => 404 ) );
	}
}
