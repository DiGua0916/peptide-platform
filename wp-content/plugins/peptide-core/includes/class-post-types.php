<?php
/**
 * 批次与报告的自定义文章类型。两者都不公开（前台通过插件接口按权限读取），后台挂在“产品”菜单下。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Post_Types {

	public const BATCH  = 'pc_batch';
	public const REPORT = 'pc_report';

	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'register' ) );
	}

	public static function register(): void {
		$common = array(
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => 'edit.php?post_type=product',
			'show_in_rest'        => false,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'map_meta_cap'        => true,
		);

		register_post_type(
			self::BATCH,
			$common + array(
				'labels'          => array(
					'name'          => __( 'Batches', 'peptide-core' ),
					'singular_name' => __( 'Batch', 'peptide-core' ),
					'add_new_item'  => __( 'Add batch', 'peptide-core' ),
					'edit_item'     => __( 'Edit batch', 'peptide-core' ),
					'search_items'  => __( 'Search batches', 'peptide-core' ),
					'not_found'     => __( 'No batches found.', 'peptide-core' ),
				),
				// 标题由批次号自动同步，不在编辑页单独输入。
				'supports'        => false,
				'capability_type' => array( 'pc_batch', 'pc_batches' ),
			)
		);

		register_post_type(
			self::REPORT,
			$common + array(
				'labels'          => array(
					'name'          => __( 'Test reports', 'peptide-core' ),
					'singular_name' => __( 'Test report', 'peptide-core' ),
					'add_new_item'  => __( 'Add test report', 'peptide-core' ),
					'edit_item'     => __( 'Edit test report', 'peptide-core' ),
					'search_items'  => __( 'Search test reports', 'peptide-core' ),
					'not_found'     => __( 'No test reports found.', 'peptide-core' ),
				),
				'supports'        => array( 'title' ),
				'capability_type' => array( 'pc_report', 'pc_reports' ),
			)
		);
	}
}
