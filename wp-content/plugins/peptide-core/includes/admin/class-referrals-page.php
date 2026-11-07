<?php
/**
 * 推广人后台（REF-01）：顾客 → 推广人。名单、专属链接、业绩；订单列表“归属人”列和筛选；
 * 订单编辑页、用户资料页手动改归属。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Referrals;

defined( 'ABSPATH' ) || exit;

final class Referrals_Page {

	public const SLUG = 'pc-referrals';
	public const CAP  = 'manage_woocommerce';

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 61 ); // 在 Admin_Menus（60）建好“顾客”菜单之后。
		add_action( 'admin_post_pc_referrals_save', array( self::class, 'save_owners' ) );
		add_action( 'admin_post_pc_referrals_assign', array( self::class, 'save_assign' ) );

		// 订单列表（HPOS 与旧版）。
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( self::class, 'order_columns' ), 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( self::class, 'order_cell_hpos' ), 20, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( self::class, 'order_columns' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( self::class, 'order_cell_legacy' ), 20, 2 );
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( self::class, 'order_filter' ), 20 );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( self::class, 'order_filter_args' ) );
		add_action( 'restrict_manage_posts', static function ( $type ) { if ( 'shop_order' === $type ) { self::order_filter(); } }, 20 );
		add_action( 'pre_get_posts', array( self::class, 'order_filter_legacy' ) );

		// 订单编辑页、用户资料页手动改归属。
		add_action( 'woocommerce_admin_order_data_after_order_details', array( self::class, 'order_field' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( self::class, 'order_field_save' ), 20, 1 );
		add_action( 'show_user_profile', array( self::class, 'user_field' ) );
		add_action( 'edit_user_profile', array( self::class, 'user_field' ) );
		add_action( 'personal_options_update', array( self::class, 'user_field_save' ) );
		add_action( 'edit_user_profile_update', array( self::class, 'user_field_save' ) );
	}

	public static function menu(): void {
		if ( ! current_user_can( self::CAP ) ) { return; }
		add_submenu_page( Admin_Menus::CUSTOMERS, '推广人', '推广人', self::CAP, self::SLUG, array( self::class, 'render' ) );
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	private static function guard( string $action ): void {
		if ( ! current_user_can( self::CAP ) ) { wp_die( '你没有权限。', '无权访问', array( 'response' => 403 ) ); }
		check_admin_referer( $action );
	}

	private static function select( string $name, string $current, bool $blank = true ): string {
		$html = '<select name="' . esc_attr( $name ) . '">';
		if ( $blank ) { $html .= '<option value="">无归属</option>'; }
		$owners = Referrals::owners();
		if ( '' !== $current && ! isset( $owners[ $current ] ) ) { $owners[ $current ] = $current . '（已从名单删除）'; }
		foreach ( $owners as $code => $name_label ) {
			$html .= '<option value="' . esc_attr( $code ) . '"' . selected( $current, $code, false ) . '>' . esc_html( $name_label . '（' . $code . '）' ) . '</option>';
		}
		return $html . '</select>';
	}

	private static function label( string $code ): string {
		return '' === $code ? '—' : esc_html( Referrals::name( $code ) );
	}

	// ---------- 主页面 ----------

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) { return; }
		$days   = isset( $_GET['days'] ) ? (int) $_GET['days'] : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$days   = in_array( $days, array( 7, 30, 0 ), true ) ? $days : 30;
		$owners = Referrals::owners();
		$stats  = Referrals::stats( $days );
		$error  = get_transient( 'pc_ref_error_' . get_current_user_id() );
		if ( $error ) { delete_transient( 'pc_ref_error_' . get_current_user_id() ); }

		echo '<div class="wrap pc-referrals"><h1>推广人</h1>';
		echo '<p class="description">每个推广人一个专属链接。客户第一次从谁的链接进站，就永久算谁的：之后不带链接直接回来、注册、换设备登录、下单，都归这个人。后来点了别人的链接也不会改。任何页面都可以带上 <code>?ref=代号</code>，比如商品页、活动页。</p>';
		if ( $error ) { echo '<div class="notice notice-error"><p>' . esc_html( (string) $error ) . '</p></div>'; }
		if ( isset( $_GET['saved'] ) && '1' === $_GET['saved'] ) { echo '<div class="notice notice-success is-dismissible"><p>已保存。</p></div>'; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<h2>业绩</h2><p>';
		foreach ( array( 7 => '近 7 天', 30 => '近 30 天', 0 => '全部' ) as $d => $text ) {
			echo $d === $days ? '<strong>' . esc_html( $text ) . '</strong>' : '<a href="' . esc_url( self::url( array( 'days' => $d ) ) ) . '">' . esc_html( $text ) . '</a>';
			echo ' &nbsp; ';
		}
		echo '</p>';
		if ( ! $owners ) {
			echo '<p>还没有推广人。在下面“管理推广人”里添加。</p>';
		} else {
			echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>推广人</th><th>专属链接</th><th>带来访客</th><th>新归属客户</th><th>已付款订单</th><th>销售额（扣退款）</th><th></th></tr></thead><tbody>';
			foreach ( $owners as $code => $name ) {
				$s    = $stats[ $code ];
				$link = Referrals::link( $code );
				echo '<tr><td><strong>' . esc_html( $name ) . '</strong><br><code>' . esc_html( $code ) . '</code></td>';
				echo '<td><input type="text" readonly class="regular-text" value="' . esc_attr( $link ) . '" onclick="this.select()"> <button type="button" class="button" onclick="navigator.clipboard&&navigator.clipboard.writeText(this.previousElementSibling.value);this.textContent=\'已复制\'">复制</button></td>';
				echo '<td>' . (int) $s['visitors'] . '</td><td>' . (int) $s['customers'] . '</td><td>' . (int) $s['orders'] . '</td><td>' . wp_kses_post( wc_price( $s['sales'] ) ) . '</td>';
				echo '<td><a href="' . esc_url( admin_url( 'admin.php?page=wc-orders&pc_ref_owner=' . rawurlencode( $code ) ) ) . '">看订单</a></td></tr>';
			}
			echo '</tbody></table>';
			echo '<p class="description">带来访客：第一次带这个人的链接进站的浏览器数（已排除爬虫）。新归属客户：这段时间内归到这个人名下的注册账号。订单按下单时间统计，只算已付款状态。</p>';
		}

		// 管理推广人。
		$lines = array();
		foreach ( $owners as $code => $name ) { $lines[] = $code . '|' . $name; }
		echo '<h2>管理推广人</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="pc_referrals_save">';
		wp_nonce_field( 'pc_referrals_save' );
		echo '<p>每行一个：<code>代号|名字</code>。代号会出现在链接里，用小写英文或数字，比如 <code>a|张三</code>。删掉一行后，这个代号不再接收新客户，已有的归属保留。</p>';
		echo '<textarea name="owners" rows="5" class="large-text code" style="max-width:600px">' . esc_textarea( implode( "\n", $lines ) ) . '</textarea>';
		submit_button( '保存推广人' );
		echo '</form>';

		// 手动改客户归属。
		echo '<h2>修改客户归属</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="pc_referrals_assign">';
		wp_nonce_field( 'pc_referrals_assign' );
		echo '<p>输入客户邮箱或用户 ID，选新的归属人。只影响这个客户以后的订单；已下的订单可以在订单详情里单独改。</p>';
		echo '<input type="text" name="customer" class="regular-text" placeholder="客户邮箱或用户 ID" required> ' . self::select( 'owner', '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		submit_button( '保存归属', 'secondary', 'submit', false );
		echo '</form>';

		// 最近归属的客户。
		$filter = isset( $_GET['owner'] ) ? sanitize_key( wp_unslash( $_GET['owner'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$query  = array( 'number' => 50, 'meta_key' => Referrals::USER_AT, 'orderby' => 'meta_value_num', 'order' => 'DESC', 'meta_query' => array( array( 'key' => Referrals::USER_META, 'compare' => 'EXISTS' ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( '' !== $filter ) { $query['meta_query'] = array( array( 'key' => Referrals::USER_META, 'value' => $filter ) ); }
		$users = get_users( $query );
		echo '<h2>最近归属的客户</h2><p>筛选：<a href="' . esc_url( self::url() ) . '">全部</a>';
		foreach ( $owners as $code => $name ) { echo ' · <a href="' . esc_url( self::url( array( 'owner' => $code ) ) ) . '">' . esc_html( $name ) . '</a>'; }
		echo '</p>';
		if ( ! $users ) {
			echo '<p>还没有归属的客户。</p>';
		} else {
			echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>客户</th><th>邮箱</th><th>归属人</th><th>归属时间</th></tr></thead><tbody>';
			foreach ( $users as $u ) {
				$at = (int) get_user_meta( $u->ID, Referrals::USER_AT, true );
				echo '<tr><td><a href="' . esc_url( get_edit_user_link( $u->ID ) ) . '">' . esc_html( $u->display_name ) . '</a></td><td>' . esc_html( $u->user_email ) . '</td><td>' . self::label( (string) get_user_meta( $u->ID, Referrals::USER_META, true ) ) . '</td><td>' . ( $at ? esc_html( wp_date( 'Y-m-d H:i', $at ) ) : '—' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	public static function save_owners(): void {
		self::guard( 'pc_referrals_save' );
		$r = Referrals::save_owners( (string) wp_unslash( $_POST['owners'] ?? '' ) );
		self::done( $r );
	}

	public static function save_assign(): void {
		self::guard( 'pc_referrals_assign' );
		$key  = trim( (string) wp_unslash( $_POST['customer'] ?? '' ) );
		$user = ctype_digit( $key ) ? get_userdata( (int) $key ) : get_user_by( 'email', sanitize_email( $key ) );
		$code = sanitize_key( wp_unslash( $_POST['owner'] ?? '' ) );
		if ( ! $user ) { self::done( new \WP_Error( 'user', '找不到这个客户：' . $key ) ); }
		self::done( Referrals::set_user_owner( (int) $user->ID, $code ) ? true : new \WP_Error( 'owner', '归属人代号不在名单里。' ) );
	}

	private static function done( $result ): void {
		if ( is_wp_error( $result ) ) { set_transient( 'pc_ref_error_' . get_current_user_id(), $result->get_error_message(), 120 ); }
		wp_safe_redirect( self::url( array( 'saved' => is_wp_error( $result ) ? 0 : 1 ) ) );
		exit;
	}

	// ---------- 订单列表 ----------

	public static function order_columns( array $columns ): array {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'order_status' === $key ) { $out['pc_ref_owner'] = '归属人'; }
		}
		if ( ! isset( $out['pc_ref_owner'] ) ) { $out['pc_ref_owner'] = '归属人'; }
		return $out;
	}

	public static function order_cell_hpos( string $column, $order ): void {
		if ( 'pc_ref_owner' === $column && $order instanceof \WC_Order ) { echo self::label( (string) $order->get_meta( Referrals::ORDER_META ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public static function order_cell_legacy( string $column, int $post_id ): void {
		if ( 'pc_ref_owner' !== $column ) { return; }
		$order = wc_get_order( $post_id );
		echo $order ? self::label( (string) $order->get_meta( Referrals::ORDER_META ) ) : '—'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private static function filter_value(): string {
		return isset( $_GET['pc_ref_owner'] ) ? sanitize_key( wp_unslash( $_GET['pc_ref_owner'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	public static function order_filter(): void {
		if ( ! Referrals::owners() ) { return; }
		$current = self::filter_value();
		echo '<select name="pc_ref_owner"><option value="">全部归属人</option><option value="_none"' . selected( $current, '_none', false ) . '>无归属</option>';
		foreach ( Referrals::owners() as $code => $name ) { echo '<option value="' . esc_attr( $code ) . '"' . selected( $current, $code, false ) . '>' . esc_html( $name ) . '</option>'; }
		echo '</select>';
	}

	private static function meta_clause( string $value ): array {
		return '_none' === $value
			? array( 'key' => Referrals::ORDER_META, 'compare' => 'NOT EXISTS' )
			: array( 'key' => Referrals::ORDER_META, 'value' => $value );
	}

	public static function order_filter_args( array $args ): array {
		$value = self::filter_value();
		if ( '' === $value ) { return $args; }
		$args['meta_query']   = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array(); // phpcs:ignore WordPress.DB.SlowDBQuery
		$args['meta_query'][] = self::meta_clause( $value );
		return $args;
	}

	public static function order_filter_legacy( $query ): void {
		if ( ! is_admin() || ! $query instanceof \WP_Query || ! $query->is_main_query() || 'shop_order' !== $query->get( 'post_type' ) ) { return; }
		$value = self::filter_value();
		if ( '' === $value ) { return; }
		$meta   = (array) $query->get( 'meta_query' );
		$meta[] = self::meta_clause( $value );
		$query->set( 'meta_query', $meta );
	}

	// ---------- 订单编辑页 ----------

	public static function order_field( $order ): void {
		if ( ! $order instanceof \WC_Order || ! current_user_can( self::CAP ) ) { return; }
		echo '<p class="form-field form-field-wide"><label>归属人：</label>' . self::select( 'pc_ref_owner_set', (string) $order->get_meta( Referrals::ORDER_META ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		wp_nonce_field( 'pc_ref_owner_order', 'pc_ref_owner_nonce' );
	}

	public static function order_field_save( $order_id ): void {
		if ( ! current_user_can( self::CAP ) || ! isset( $_POST['pc_ref_owner_set'], $_POST['pc_ref_owner_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pc_ref_owner_nonce'] ) ), 'pc_ref_owner_order' ) ) { return; }
		$order = wc_get_order( (int) $order_id );
		$code  = sanitize_key( wp_unslash( $_POST['pc_ref_owner_set'] ) );
		if ( $order && $code !== (string) $order->get_meta( Referrals::ORDER_META ) ) { Referrals::set_order_owner( $order, $code ); }
	}

	// ---------- 用户资料页 ----------

	public static function user_field( $user ): void {
		if ( ! $user instanceof \WP_User || ! current_user_can( self::CAP ) ) { return; }
		echo '<h2>推广人归属</h2><table class="form-table"><tr><th><label>归属人</label></th><td>' . self::select( 'pc_ref_owner_user', (string) get_user_meta( $user->ID, Referrals::USER_META, true ) ) . '<p class="description">只影响这个客户以后的订单。</p></td></tr></table>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public static function user_field_save( $user_id ): void {
		// 资料页自带 update-user_{id} 校验；这里只要求有店铺管理权限。
		if ( ! current_user_can( self::CAP ) || ! isset( $_POST['pc_ref_owner_user'] ) ) { return; }
		$code = sanitize_key( wp_unslash( $_POST['pc_ref_owner_user'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $code !== (string) get_user_meta( (int) $user_id, Referrals::USER_META, true ) ) { Referrals::set_user_owner( (int) $user_id, $code ); }
	}
}
