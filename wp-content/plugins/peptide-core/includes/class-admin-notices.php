<?php
/**
 * 保存后台表单时的一次性提示（保存后会重定向，所以先存起来，下一页显示）。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Admin_Notices {

	private const TRANSIENT_PREFIX = 'pc_admin_notices_';

	public static function register_hooks(): void {
		add_action( 'admin_notices', array( self::class, 'render' ) );
	}

	public static function add( string $message, string $type = 'error' ): void {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}
		$key     = self::TRANSIENT_PREFIX . $user_id;
		$notices = get_transient( $key );
		$notices = is_array( $notices ) ? $notices : array();

		$notices[] = array(
			'message' => $message,
			'type'    => in_array( $type, array( 'error', 'warning', 'success', 'info' ), true ) ? $type : 'error',
		);
		set_transient( $key, $notices, 5 * MINUTE_IN_SECONDS );
	}

	public static function render(): void {
		$key     = self::TRANSIENT_PREFIX . get_current_user_id();
		$notices = get_transient( $key );
		if ( ! is_array( $notices ) || ! $notices ) {
			return;
		}
		delete_transient( $key );
		foreach ( $notices as $notice ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $notice['type'] ),
				esc_html( $notice['message'] )
			);
		}
	}
}
