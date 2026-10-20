<?php
/**
 * 装修文档（pc_layout）的区块编辑器设置（EDIT-BLOCKS-02）：只允许插入 VialCove 模块（vialcove/*），
 * 并在编辑器顶部说明当前状态、给出返回“主题装修”的链接。模块本身在后续步骤注册。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Templates;

defined( 'ABSPATH' ) || exit;

final class Layout_Editor {

	public const BLOCK_PREFIX = 'vialcove/';

	public static function register_hooks(): void {
		add_filter( 'allowed_block_types_all', array( self::class, 'allowed_blocks' ), 10, 2 );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue' ) );
	}

	/**
	 * @param bool|string[]            $allowed
	 * @param \WP_Block_Editor_Context $context
	 * @return bool|string[]
	 */
	public static function allowed_blocks( $allowed, $context ) {
		if ( ! isset( $context->post ) || ! $context->post instanceof \WP_Post || Templates::LAYOUT !== $context->post->post_type ) {
			return $allowed;
		}
		$names = array();
		foreach ( array_keys( \WP_Block_Type_Registry::get_instance()->get_all_registered() ) as $name ) {
			if ( str_starts_with( $name, self::BLOCK_PREFIX ) ) {
				$names[] = $name;
			}
		}
		return $names;
	}

	public static function enqueue(): void {
		$screen = get_current_screen();
		if ( ! $screen || Templates::LAYOUT !== $screen->post_type ) {
			return;
		}
		$post_id  = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$template = $post_id ? (int) wp_get_post_parent_id( $post_id ) : 0;
		$message  = sprintf(
			/* translators: %s: template name */
			__( 'Template "%s". The module library is being built: the first module (brand story rows) comes next. Changes here are not shown on the storefront yet.', 'peptide-core' ),
			$template ? get_the_title( $template ) : '-'
		);
		$data = array(
			'message' => $message,
			'back'    => __( 'Back to theme design', 'peptide-core' ),
			'backUrl' => admin_url( 'admin.php?page=' . Store_Design::SLUG ),
		);
		wp_register_script( 'peptide-core-layout-editor', '', array( 'wp-data', 'wp-notices', 'wp-dom-ready' ), PEPTIDE_CORE_VERSION, true );
		wp_enqueue_script( 'peptide-core-layout-editor' );
		wp_add_inline_script(
			'peptide-core-layout-editor',
			'wp.domReady(function(){var d=' . wp_json_encode( $data ) . ';wp.data.dispatch("core/notices").createInfoNotice(d.message,{id:"pc-layout-status",isDismissible:false,actions:[{label:d.back,url:d.backUrl}]});});'
		);
	}
}
