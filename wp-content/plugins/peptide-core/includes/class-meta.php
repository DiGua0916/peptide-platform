<?php
/**
 * 元数据键与注册。键名以 _pc_ 开头（下划线使其不出现在“自定义栏目”面板中）。
 * 主题不要直接读取这些键，应通过插件提供的函数或接口取数。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Meta {

	// 商品（WooCommerce product）。
	public const PRODUCT_IS_DEMO = '_pc_is_demo';
	public const PRODUCT_SPECS   = '_pc_specs';
	public const PRODUCT_SUBTITLE = '_pc_product_subtitle';

	// 批次。
	public const BATCH_NO             = '_pc_batch_no';
	public const BATCH_PRODUCT_ID     = '_pc_product_id';
	public const BATCH_VARIATION_IDS  = '_pc_variation_ids';
	public const BATCH_VISIBILITY     = '_pc_visibility';
	public const BATCH_STATUS         = '_pc_status';
	public const BATCH_IS_DEMO        = '_pc_is_demo';
	public const BATCH_MANUFACTURED   = '_pc_manufactured_on';
	public const BATCH_RETEST_EXPIRY  = '_pc_retest_or_expiry_on';
	public const BATCH_ORIGIN_NOTE    = '_pc_origin_note';
	public const BATCH_REPORT_PENDING = '_pc_report_pending';

	// 报告。
	public const REPORT_BATCH_ID      = '_pc_batch_id';
	public const REPORT_TYPE          = '_pc_type';
	public const REPORT_VERSION       = '_pc_version';
	public const REPORT_STATUS        = '_pc_report_status';
	public const REPORT_ACCESS        = '_pc_access';
	public const REPORT_IS_DEMO       = '_pc_is_demo';
	public const REPORT_ISSUED_ON     = '_pc_issued_on';
	public const REPORT_ISSUER_NAME   = '_pc_issuer_name';
	public const REPORT_SUMMARY       = '_pc_summary';
	public const REPORT_FILE          = '_pc_file';
	public const REPORT_SUPERSEDED_BY = '_pc_superseded_by';

	// 演示数据命令写入的标记，用于一键清除。
	public const DEMO_SEED = '_pc_demo_seed';

	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'register' ) );
	}

	public static function register(): void {
		$batch_auth  = static fn() => current_user_can( 'edit_pc_batches' );
		$report_auth = static fn() => current_user_can( 'edit_pc_reports' );

		$batch = array(
			self::BATCH_NO             => array( 'string', '', array( self::class, 'sanitize_text' ) ),
			self::BATCH_PRODUCT_ID     => array( 'integer', 0, 'absint' ),
			self::BATCH_VARIATION_IDS  => array( 'array', array(), array( self::class, 'sanitize_id_list' ) ),
			self::BATCH_VISIBILITY     => array( 'string', 'public', array( self::class, 'sanitize_text' ) ),
			self::BATCH_STATUS         => array( 'string', 'active', array( self::class, 'sanitize_text' ) ),
			self::BATCH_IS_DEMO        => array( 'boolean', false, 'rest_sanitize_boolean' ),
			self::BATCH_MANUFACTURED   => array( 'string', '', array( self::class, 'sanitize_date' ) ),
			self::BATCH_RETEST_EXPIRY  => array( 'string', '', array( self::class, 'sanitize_date' ) ),
			self::BATCH_ORIGIN_NOTE    => array( 'string', '', array( self::class, 'sanitize_text' ) ),
			self::BATCH_REPORT_PENDING => array( 'boolean', false, 'rest_sanitize_boolean' ),
		);
		foreach ( $batch as $key => $def ) {
			self::register_one( Post_Types::BATCH, $key, $def, $batch_auth );
		}

		$report = array(
			self::REPORT_BATCH_ID      => array( 'integer', 0, 'absint' ),
			self::REPORT_TYPE          => array( 'string', 'other', array( self::class, 'sanitize_text' ) ),
			self::REPORT_VERSION       => array( 'integer', 0, 'absint' ),
			self::REPORT_STATUS        => array( 'string', 'draft', array( self::class, 'sanitize_text' ) ),
			self::REPORT_ACCESS        => array( 'string', 'public', array( self::class, 'sanitize_text' ) ),
			self::REPORT_IS_DEMO       => array( 'boolean', false, 'rest_sanitize_boolean' ),
			self::REPORT_ISSUED_ON     => array( 'string', '', array( self::class, 'sanitize_date' ) ),
			self::REPORT_ISSUER_NAME   => array( 'string', '', array( self::class, 'sanitize_text' ) ),
			self::REPORT_SUMMARY       => array( 'string', '', 'sanitize_textarea_field' ),
			self::REPORT_FILE          => array( 'object', array(), null ),
			self::REPORT_SUPERSEDED_BY => array( 'integer', 0, 'absint' ),
		);
		foreach ( $report as $key => $def ) {
			self::register_one( Post_Types::REPORT, $key, $def, $report_auth );
		}
	}

	/**
	 * @param array{0:string,1:mixed,2:callable|null} $def 类型、默认值、清理函数。
	 */
	private static function register_one( string $post_type, string $key, array $def, callable $auth ): void {
		[ $type, $default, $sanitize ] = $def;
		$args = array(
			'type'          => $type,
			'single'        => true,
			'default'       => $default,
			'show_in_rest'  => false,
			'auth_callback' => $auth,
		);
		if ( $sanitize ) {
			$args['sanitize_callback'] = $sanitize;
		}
		register_post_meta( $post_type, $key, $args );
	}

	public static function sanitize_text( $value ): string {
		return sanitize_text_field( (string) $value );
	}

	/**
	 * 只接受 YYYY-MM-DD 格式的有效日期，其他一律存为空字符串。
	 */
	public static function sanitize_date( $value ): string {
		$value = trim( (string) $value );
		if ( '' === $value || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return '';
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $value : '';
	}

	/**
	 * @return int[]
	 */
	public static function sanitize_id_list( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_unique( array_filter( array_map( 'absint', $value ) ) ) );
	}

	public static function bool( $value ): bool {
		return rest_sanitize_boolean( $value );
	}
}
