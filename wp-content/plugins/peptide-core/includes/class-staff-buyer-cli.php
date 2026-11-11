<?php
/**
 * 命令行：按账号切换投手版后台（M9）。规则见 Staff_Buyer。
 *
 *     wp peptide buyer list
 *     wp peptide buyer on <账号> [--must-change-password]
 *     wp peptide buyer off <账号>
 *     wp peptide buyer default [on|off]
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Staff_Buyer_Cli {

	/**
	 * 列出销售账号和各自用的后台版本。
	 *
	 * @subcommand list
	 */
	public function list_( $args, $assoc ): void {
		$rows = array();
		foreach ( get_users( array( 'role__in' => array( Staff::ROLE, Staff_Buyer::ROLE ), 'orderby' => 'ID' ) ) as $u ) {
			$rows[] = array(
				'ID'                   => $u->ID,
				'login'                => $u->user_login,
				'mode'                 => Staff_Buyer::is_buyer( $u ) ? 'buyer' : 'modules',
				'modules'              => implode( ',', array_intersect( array_keys( Staff::modules() ), (array) get_user_meta( $u->ID, Staff::META_MODULES, true ) ) ),
				'disabled'             => Staff::disabled( $u->ID ) ? 'yes' : 'no',
				'must_change_password' => Staff_Buyer::must_change( $u->ID ) ? 'yes' : 'no',
			);
		}
		if ( ! $rows ) {
			\WP_CLI::log( '这家店没有销售账号。' );
			return;
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'ID', 'login', 'mode', 'modules', 'disabled', 'must_change_password' ) );
		\WP_CLI::log( '新建账号默认投手版：' . ( Staff_Buyer::default_on() ? '是' : '否' ) );
	}

	/**
	 * 把一个销售账号切到投手版。
	 *
	 * ## OPTIONS
	 *
	 * <login>
	 * : 账号名。
	 *
	 * [--must-change-password]
	 * : 下次登录先改密码。
	 */
	public function on( $args, $assoc ): void {
		$this->switch_mode( (string) ( $args[0] ?? '' ), true, ! empty( $assoc['must-change-password'] ) );
	}

	/**
	 * 把一个账号改回按模块勾选的权限。
	 *
	 * ## OPTIONS
	 *
	 * <login>
	 * : 账号名。
	 */
	public function off( $args, $assoc ): void {
		$this->switch_mode( (string) ( $args[0] ?? '' ), false, false );
	}

	/**
	 * 新建的销售账号是否直接用投手版：不带参数时只显示当前设置。
	 *
	 * ## OPTIONS
	 *
	 * [<state>]
	 * : on 或 off。
	 *
	 * @subcommand default
	 */
	public function default_( $args, $assoc ): void {
		$state = (string) ( $args[0] ?? '' );
		if ( 'on' === $state ) {
			update_option( Staff_Buyer::OPTION_DEFAULT, '1', false );
		} elseif ( 'off' === $state ) {
			delete_option( Staff_Buyer::OPTION_DEFAULT );
		} elseif ( '' !== $state ) {
			\WP_CLI::error( '只能是 on 或 off。' );
		}
		\WP_CLI::log( '新建账号默认投手版：' . ( Staff_Buyer::default_on() ? '是' : '否' ) );
	}

	private function switch_mode( string $login, bool $on, bool $must_change ): void {
		$user = get_user_by( 'login', $login );
		if ( ! $user instanceof \WP_User ) {
			\WP_CLI::error( '找不到账号：' . $login );
		}
		$result = Staff_Buyer::set( (int) $user->ID, $on, $must_change );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::success( $login . ( $on ? ' 已切到投手版。' : ' 已改回按模块勾选的权限。' ) );
	}
}
