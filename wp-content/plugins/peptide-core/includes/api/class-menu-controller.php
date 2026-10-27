<?php
/**
 * 菜单编辑器接口（MENU-NAV-01），只给后台“菜单导航”编辑页使用，需要 edit_theme_options：
 *
 *   GET  /peptide/v1/menus/{id}   读取菜单：名称、固定菜单键、位置、修改标记、两级菜单项
 *   POST /peptide/v1/menus/{id}   保存：{name, location, items, modified}；modified 与服务器不同时返回 409
 *
 * 新建、删除、导出、导入在列表页用普通表单完成（Admin\Menu_Page）。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Api;

use PeptideCore\Menus;

defined( 'ABSPATH' ) || exit;

final class Menu_Controller {

	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	public static function register_routes(): void {
		$args = array(
			'id' => array(
				'type'     => 'integer',
				'required' => true,
			),
		);
		register_rest_route(
			Rest_Controller::ROUTE_NAMESPACE,
			'/menus/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get' ),
					'permission_callback' => array( self::class, 'can_edit' ),
					'args'                => $args,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'save' ),
					'permission_callback' => array( self::class, 'can_edit' ),
					'args'                => $args,
				),
			)
		);
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function can_edit( \WP_REST_Request $request ) {
		if ( ! current_user_can( Menus::CAP ) ) {
			return new \WP_Error( 'pc_menu_forbidden', __( 'Sorry, you are not allowed to edit menus.', 'peptide-core' ), array( 'status' => rest_authorization_required_code() ) );
		}
		if ( ! Menus::exists( (int) $request['id'] ) ) {
			return new \WP_Error( 'pc_menu_missing', __( 'Menu not found.', 'peptide-core' ), array( 'status' => 404 ) );
		}
		return true;
	}

	public static function get( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response( Menus::get( (int) $request['id'] ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function save( \WP_REST_Request $request ) {
		$body     = $request->get_json_params();
		$body     = is_array( $body ) ? $body : array();
		$modified = isset( $body['modified'] ) && is_scalar( $body['modified'] ) ? (string) $body['modified'] : null;
		$result   = Menus::save( (int) $request['id'], $body, $modified );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}
}
