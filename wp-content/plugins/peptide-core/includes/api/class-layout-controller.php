<?php
/**
 * 装修编辑器接口（EDIT-BLOCKS-04），只给后台编辑器使用，需要该文档的编辑权限：
 *
 *   GET  /peptide/v1/layouts/{id}          读取文档：模块列表、修改时间、所属模板
 *   POST /peptide/v1/layouts/{id}          保存草稿：{modules, modified}；modified 与服务器不同时返回 409（别人已经改过）
 *   POST /peptide/v1/layouts/{id}/preview  生成实时预览：{modules} → {url}（30 分钟有效，不产生修订）
 *   POST /peptide/v1/layouts/{id}/publish    发布到网站：{modified}；草稿在你打开后被改过时返回 409（需要管理员）
 *   POST /peptide/v1/layouts/{id}/unpublish  取消发布，网站恢复主题原来的页面（需要管理员）
 *   POST /peptide/v1/layouts/{id}/discard    放弃未发布的修改，草稿恢复成已发布版本
 *
 * 保存的内容先按字段定义清理，再写成区块格式；每次保存产生一条 WordPress 修订。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Api;

use PeptideCore\Modules\Layout;
use PeptideCore\Templates;

defined( 'ABSPATH' ) || exit;

final class Layout_Controller {

	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	public static function register_routes(): void {
		$id = array(
			'id' => array(
				'type'     => 'integer',
				'required' => true,
			),
		);
		register_rest_route(
			Rest_Controller::ROUTE_NAMESPACE,
			'/layouts/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get' ),
					'permission_callback' => array( self::class, 'can_edit' ),
					'args'                => $id,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'save' ),
					'permission_callback' => array( self::class, 'can_edit' ),
					'args'                => $id,
				),
			)
		);
		foreach ( array(
			'publish'   => array( 'publish', 'can_publish' ),
			'unpublish' => array( 'unpublish', 'can_publish' ),
			'discard'   => array( 'discard', 'can_edit' ),
		) as $path => [ $callback, $permission ] ) {
			register_rest_route(
				Rest_Controller::ROUTE_NAMESPACE,
				'/layouts/(?P<id>\d+)/' . $path,
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, $callback ),
					'permission_callback' => array( self::class, $permission ),
					'args'                => $id,
				)
			);
		}
		register_rest_route(
			Rest_Controller::ROUTE_NAMESPACE,
			'/layouts/(?P<id>\d+)/preview',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'preview' ),
				'permission_callback' => array( self::class, 'can_edit' ),
				'args'                => $id,
			)
		);
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function can_edit( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( Templates::LAYOUT !== get_post_type( $id ) || 'trash' === get_post_status( $id ) ) {
			return new \WP_Error( 'pc_layout_not_found', __( 'Design document not found.', 'peptide-core' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new \WP_Error( 'pc_layout_forbidden', __( 'Sorry, you are not allowed to edit this design.', 'peptide-core' ), array( 'status' => rest_authorization_required_code() ) );
		}
		return true;
	}

	/**
	 * 发布 / 取消发布需要管理员（pc_publish_layouts）。
	 *
	 * @return true|\WP_Error
	 */
	public static function can_publish( \WP_REST_Request $request ) {
		$can = self::can_edit( $request );
		if ( true !== $can ) {
			return $can;
		}
		return current_user_can( Templates::CAP_MANAGE ) ? true : new \WP_Error( 'pc_layout_publish_forbidden', __( 'Only administrators can publish designs to the storefront.', 'peptide-core' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function document( int $id ): array {
		require_once ABSPATH . 'wp-admin/includes/post.php';
		$template = (int) wp_get_post_parent_id( $id );
		$lock     = wp_check_post_lock( $id );
		$locker   = $lock ? get_userdata( (int) $lock ) : null;
		return array(
			'id'          => $id,
			'document'    => (string) get_post_meta( $id, Templates::DOC_META, true ),
			'template'    => array(
				'id'   => $template,
				'name' => get_the_title( $template ),
			),
			'modules'     => Layout::modules_from_content( (string) get_post_field( 'post_content', $id ) ),
			'modified'    => (string) get_post_field( 'post_modified_gmt', $id ),
			'lockedBy'    => $locker ? $locker->display_name : null,
			'status'      => Layout::status( $id ),
			'previewBase' => Templates::preview_url( $template, true ),
		);
	}

	public static function get( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response( self::document( (int) $request['id'] ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function save( \WP_REST_Request $request ) {
		require_once ABSPATH . 'wp-admin/includes/post.php';
		$id       = (int) $request['id'];
		$expected = (string) $request->get_param( 'modified' );
		$current  = (string) get_post_field( 'post_modified_gmt', $id );
		if ( '' !== $expected && $expected !== $current ) {
			$editor = get_userdata( (int) get_post_meta( $id, '_edit_last', true ) );
			return new \WP_Error(
				'pc_layout_conflict',
				sprintf(
					/* translators: %s: user name */
					__( 'This design was changed by %s after you opened it. Reload to see their changes before saving.', 'peptide-core' ),
					$editor ? $editor->display_name : __( 'someone else', 'peptide-core' )
				),
				array( 'status' => 409 )
			);
		}
		$modules = Layout::clean_modules( $request->get_param( 'modules' ) );
		$result  = wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => wp_slash( Layout::content_from_modules( $modules ) ),
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return new \WP_Error( 'pc_layout_save_failed', $result->get_error_message(), array( 'status' => 500 ) );
		}
		update_post_meta( $id, '_edit_last', get_current_user_id() );
		if ( function_exists( 'wp_set_post_lock' ) ) {
			wp_set_post_lock( $id );
		}
		clean_post_cache( $id );
		return rest_ensure_response( self::document( $id ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function publish( \WP_REST_Request $request ) {
		$id       = (int) $request['id'];
		$expected = (string) $request->get_param( 'modified' );
		if ( '' !== $expected && $expected !== (string) get_post_field( 'post_modified_gmt', $id ) ) {
			return new \WP_Error( 'pc_layout_conflict', __( 'This design was changed after you opened it. Reload, check the changes, then publish.', 'peptide-core' ), array( 'status' => 409 ) );
		}
		Layout::publish( $id );
		return rest_ensure_response( self::document( $id ) );
	}

	public static function unpublish( \WP_REST_Request $request ): \WP_REST_Response {
		Layout::unpublish( (int) $request['id'] );
		return rest_ensure_response( self::document( (int) $request['id'] ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function discard( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! Layout::discard_draft( $id ) ) {
			return new \WP_Error( 'pc_layout_not_published', __( 'There is no published version to go back to.', 'peptide-core' ), array( 'status' => 400 ) );
		}
		clean_post_cache( $id );
		return rest_ensure_response( self::document( $id ) );
	}

	public static function preview( \WP_REST_Request $request ): \WP_REST_Response {
		$id      = (int) $request['id'];
		$modules = Layout::clean_modules( $request->get_param( 'modules' ) );
		$token   = Layout::store_preview( $id, $modules );
		return rest_ensure_response(
			array(
				'url' => add_query_arg( Layout::PREVIEW_PARAM, $token, Templates::preview_url( (int) wp_get_post_parent_id( $id ), true ) ),
			)
		);
	}
}
