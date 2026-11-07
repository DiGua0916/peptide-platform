<?php
/**
 * 推广人后台（REF-01）：左侧菜单“推广人”（紧跟“顾客”）。名单、专属链接、业绩；订单列表“归属人”列和筛选；
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
		add_action( 'admin_menu', array( self::class, 'place_menu' ), 10001 ); // Admin_Menus::order_menu（10000）排好之后，挪到“顾客”后面。
		add_action( 'admin_post_pc_referrals_save', array( self::class, 'save_owners' ) );
		add_action( 'admin_post_pc_referrals_assign', array( self::class, 'save_assign' ) );
		add_action( 'admin_post_pc_referrals_add', array( self::class, 'save_add' ) );
		add_action( 'admin_post_pc_referrals_rename', array( self::class, 'save_rename' ) );
		add_action( 'admin_post_pc_referrals_delete', array( self::class, 'save_delete' ) );
		add_action( 'admin_enqueue_scripts', static function () {
			if ( self::SLUG === ( $_GET['page'] ?? '' ) ) { wp_enqueue_style( 'pc-referrals', plugins_url( 'assets/referrals.css', PEPTIDE_CORE_FILE ), array(), (string) filemtime( PEPTIDE_CORE_DIR . '/assets/referrals.css' ) ); } // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} );

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
		// 单独一项、紧跟“顾客”（位置 4）：“顾客”点开的是 WooCommerce 自带页面，那里不展开子菜单，放在子菜单里找不到。
		add_menu_page( '推广人', '推广人', self::CAP, self::SLUG, array( self::class, 'render' ), 'dashicons-share', '4.5' );
	}

	/** 把“推广人”放在“顾客”正后面，然后像 order_menu 一样重新编号。 */
	public static function place_menu(): void {
		global $menu;
		if ( empty( $menu ) || ! is_array( $menu ) ) { return; }
		$items = array_values( $menu );
		$slugs = array_column( $items, 2 );
		$from  = array_search( self::SLUG, $slugs, true );
		if ( false === $from ) { return; }
		$item = $items[ $from ];
		array_splice( $items, $from, 1 );
		$to = array_search( Admin_Menus::CUSTOMERS, array_column( $items, 2 ), true );
		array_splice( $items, false === $to ? count( $items ) : $to + 1, 0, array( $item ) );
		$menu = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		foreach ( $items as $i => $entry ) { $menu[ ( $i + 1 ) * 5 ] = $entry; } // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
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
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 只读筛选参数。
		$days   = isset( $_GET['days'] ) ? (int) $_GET['days'] : 30;
		$days   = in_array( $days, array( 7, 30, 0 ), true ) ? $days : 30;
		$filter = isset( $_GET['owner'] ) ? sanitize_key( wp_unslash( $_GET['owner'] ) ) : '';
		$saved  = isset( $_GET['saved'] ) && '1' === $_GET['saved'];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$owners = Referrals::owners();
		$stats  = Referrals::stats( $days );
		$error  = get_transient( 'pc_ref_error_' . get_current_user_id() );
		if ( $error ) { delete_transient( 'pc_ref_error_' . get_current_user_id() ); }
		$total  = array( 'visitors' => 0, 'customers' => 0, 'orders' => 0, 'sales' => 0.0 );
		foreach ( $stats as $s ) { foreach ( $total as $k => $v ) { $total[ $k ] += $s[ $k ]; } }
		$ranges = array( 7 => '近 7 天', 30 => '近 30 天', 0 => '全部' );

		echo '<div class="wrap pc-ref">';
		echo '<header class="pc-ref-head"><div><h1>推广人</h1><p>每人一个专属链接，客户第一次从谁的链接进站，就永久归谁。</p></div>';
		echo '<nav class="pc-ref-range" aria-label="统计时间">';
		foreach ( $ranges as $d => $text ) {
			$args = array( 'days' => $d ) + ( '' !== $filter ? array( 'owner' => $filter ) : array() );
			echo '<a href="' . esc_url( self::url( $args ) ) . '"' . ( $d === $days ? ' aria-current="page"' : '' ) . '>' . esc_html( $text ) . '</a>';
		}
		echo '</nav></header>';

		if ( $error ) { echo '<div class="pc-ref-alert is-error" role="alert">' . esc_html( (string) $error ) . '</div>'; }
		elseif ( $saved ) { echo '<div class="pc-ref-alert" role="status">已保存。</div>'; }

		// 汇总。
		echo '<section class="pc-ref-kpis" aria-label="' . esc_attr( $ranges[ $days ] . '汇总' ) . '">';
		foreach ( array(
			array( '带来访客', number_format_i18n( $total['visitors'] ), '第一次从专属链接进站' ),
			array( '新归属客户', number_format_i18n( $total['customers'] ), '注册账号归到推广人' ),
			array( '已付款订单', number_format_i18n( $total['orders'] ), '按下单时间' ),
			array( '销售额', wp_strip_all_tags( wc_price( $total['sales'] ) ), '已扣退款' ),
		) as $kpi ) {
			echo '<div class="pc-ref-kpi"><span>' . esc_html( $kpi[0] ) . '</span><strong>' . esc_html( $kpi[1] ) . '</strong><small>' . esc_html( $kpi[2] ) . '</small></div>';
		}
		echo '</section>';

		echo '<div class="pc-ref-grid">';

		// 推广人列表。
		echo '<section class="pc-ref-card pc-ref-people"><div class="pc-ref-card-head"><h2>推广人 <span class="pc-ref-count">' . count( $owners ) . '</span></h2></div>';
		if ( $owners ) {
			echo '<ul class="pc-ref-list">';
			foreach ( $owners as $code => $name ) {
				$s    = $stats[ $code ];
				$link = Referrals::link( $code );
				$id   = 'pc-ref-' . $code;
				echo '<li class="pc-ref-row">';
				echo '<div class="pc-ref-who"><span class="pc-ref-avatar" aria-hidden="true">' . esc_html( mb_strtoupper( mb_substr( $name, 0, 1 ) ) ) . '</span><div><strong>' . esc_html( $name ) . '</strong><code>' . esc_html( $code ) . '</code></div></div>';
				echo '<div class="pc-ref-link"><label class="screen-reader-text" for="' . esc_attr( $id ) . '">' . esc_html( $name ) . ' 的专属链接</label><input id="' . esc_attr( $id ) . '" type="text" readonly value="' . esc_attr( $link ) . '"><button type="button" class="pc-ref-copy" data-copy="' . esc_attr( $link ) . '">复制链接</button></div>';
				echo '<dl class="pc-ref-stats">';
				echo '<div><dt>访客</dt><dd>' . esc_html( number_format_i18n( $s['visitors'] ) ) . '</dd></div>';
				echo '<div><dt>客户</dt><dd>' . esc_html( number_format_i18n( $s['customers'] ) ) . '</dd></div>';
				echo '<div><dt>订单</dt><dd>' . esc_html( number_format_i18n( $s['orders'] ) ) . '</dd></div>';
				echo '<div><dt>销售额</dt><dd>' . esc_html( wp_strip_all_tags( wc_price( $s['sales'] ) ) ) . '</dd></div>';
				echo '</dl>';
				echo '<div class="pc-ref-actions">';
				echo '<a href="' . esc_url( admin_url( 'admin.php?page=wc-orders&pc_ref_owner=' . rawurlencode( $code ) ) ) . '">订单</a>';
				echo '<a href="' . esc_url( self::url( array( 'owner' => $code, 'days' => $days ) ) . '#pc-ref-customers' ) . '">客户</a>';
				echo '<details class="pc-ref-more"><summary>编辑</summary><div class="pc-ref-pop">';
				self::form_open( 'pc_referrals_rename' );
				echo '<input type="hidden" name="code" value="' . esc_attr( $code ) . '"><label>名字<input type="text" name="name" value="' . esc_attr( $name ) . '" maxlength="40" required></label><button type="submit" class="pc-ref-btn">保存名字</button></form>';
				self::form_open( 'pc_referrals_delete', '删除后 ' . $name . ' 的链接不再接收新客户，已有的客户和订单仍然算 ' . $name . ' 的。确定删除？' );
				echo '<input type="hidden" name="code" value="' . esc_attr( $code ) . '"><button type="submit" class="pc-ref-btn is-danger">从名单删除</button></form>';
				echo '</div></details></div>';
				echo '</li>';
			}
			echo '</ul>';
		} else {
			echo '<div class="pc-ref-empty"><strong>还没有推广人</strong><p>在右边添加第一位，保存后就会生成专属链接。</p></div>';
		}
		echo '</section>';

		// 右栏：添加、改归属、规则。
		echo '<aside class="pc-ref-side">';
		echo '<section class="pc-ref-card"><h2>添加推广人</h2>';
		self::form_open( 'pc_referrals_add' );
		echo '<label>名字<input type="text" name="name" maxlength="40" placeholder="例如 张三" required></label>';
		echo '<label>代号<input type="text" name="code" maxlength="20" pattern="[a-zA-Z0-9][a-zA-Z0-9_\-]{0,19}" placeholder="例如 a 或 zhang" required autocapitalize="off" spellcheck="false"><small>会出现在链接里：' . esc_html( home_url( '/' ) ) . '?ref=<b>代号</b>。用英文或数字，保存后不要再改。</small></label>';
		echo '<button type="submit" class="pc-ref-btn is-primary">添加</button></form></section>';

		echo '<section class="pc-ref-card"><h2>修改客户归属</h2>';
		self::form_open( 'pc_referrals_assign' );
		echo '<label>客户邮箱或用户 ID<input type="text" name="customer" required></label><label>归属给' . self::select( 'owner', '' ) . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<button type="submit" class="pc-ref-btn">保存归属</button><small>只影响这个客户以后的订单；已有订单在订单详情里单独改。</small></form></section>';

		echo '<section class="pc-ref-card pc-ref-rules"><h2>归属规则</h2><ol>';
		echo '<li><strong>谁先带来就算谁的。</strong>后来点了别人的链接，也不会改。</li>';
		echo '<li><strong>以后再来也认得。</strong>不带链接回来、注册、换手机登录，都算原推广人。</li>';
		echo '<li><strong>任何页面都能带。</strong>商品页、活动页后面加上 <code>?ref=代号</code>。</li>';
		echo '<li><strong>链接不影响速度和广告。</strong>页面照常走缓存，代号不会传给 Facebook。</li>';
		echo '</ol></section>';
		echo '</aside></div>';

		// 最近归属的客户。
		$query = array( 'number' => 50, 'meta_key' => Referrals::USER_AT, 'orderby' => 'meta_value_num', 'order' => 'DESC', 'meta_query' => array( array( 'key' => Referrals::USER_META, 'compare' => 'EXISTS' ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( '' !== $filter ) { $query['meta_query'] = array( array( 'key' => Referrals::USER_META, 'value' => $filter ) ); }
		$users = get_users( $query );
		echo '<section class="pc-ref-card" id="pc-ref-customers"><div class="pc-ref-card-head"><h2>最近归属的客户</h2><nav class="pc-ref-chips" aria-label="按推广人筛选">';
		echo '<a href="' . esc_url( self::url( array( 'days' => $days ) ) . '#pc-ref-customers' ) . '"' . ( '' === $filter ? ' aria-current="page"' : '' ) . '>全部</a>';
		foreach ( $owners as $code => $name ) {
			echo '<a href="' . esc_url( self::url( array( 'owner' => $code, 'days' => $days ) ) . '#pc-ref-customers' ) . '"' . ( $code === $filter ? ' aria-current="page"' : '' ) . '>' . esc_html( $name ) . '</a>';
		}
		echo '</nav></div>';
		if ( ! $users ) {
			echo '<div class="pc-ref-empty is-small"><p>' . ( '' !== $filter ? '这个推广人名下还没有注册客户。' : '还没有归属的注册客户。客户通过专属链接进站后注册或下单，就会出现在这里。' ) . '</p></div>';
		} else {
			echo '<div class="pc-ref-table"><table><thead><tr><th>客户</th><th>邮箱</th><th>归属人</th><th>归属时间</th></tr></thead><tbody>';
			foreach ( $users as $u ) {
				$at = (int) get_user_meta( $u->ID, Referrals::USER_AT, true );
				echo '<tr><td><a href="' . esc_url( get_edit_user_link( $u->ID ) ) . '">' . esc_html( $u->display_name ) . '</a></td><td>' . esc_html( $u->user_email ) . '</td><td><span class="pc-ref-tag">' . self::label( (string) get_user_meta( $u->ID, Referrals::USER_META, true ) ) . '</span></td><td>' . ( $at ? esc_html( wp_date( 'Y-m-d H:i', $at ) ) : '—' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</tbody></table></div>';
		}
		echo '</section>';

		echo '<script>document.addEventListener("click",function(e){var b=e.target.closest(".pc-ref-copy");if(!b)return;var t=b.getAttribute("data-copy"),i=b.previousElementSibling;function ok(){b.textContent="已复制";b.classList.add("is-done");setTimeout(function(){b.textContent="复制链接";b.classList.remove("is-done")},1600)}if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(t).then(ok,function(){i.select();document.execCommand("copy");ok()})}else{i.select();document.execCommand("copy");ok()}});document.addEventListener("submit",function(e){var m=e.target.getAttribute("data-confirm");if(m&&!window.confirm(m))e.preventDefault()});</script>';
		echo '</div>';
	}

	private static function form_open( string $action, string $confirm = '' ): void {
		echo '<form class="pc-ref-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( '' !== $confirm ? ' data-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '><input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( $action );
	}

	public static function save_owners(): void {
		self::guard( 'pc_referrals_save' );
		self::done( Referrals::save_owners( (string) wp_unslash( $_POST['owners'] ?? '' ) ) );
	}

	public static function save_add(): void {
		self::guard( 'pc_referrals_add' );
		self::done( Referrals::add_owner( (string) wp_unslash( $_POST['code'] ?? '' ), (string) wp_unslash( $_POST['name'] ?? '' ) ) );
	}

	public static function save_rename(): void {
		self::guard( 'pc_referrals_rename' );
		self::done( Referrals::rename_owner( sanitize_key( wp_unslash( $_POST['code'] ?? '' ) ), (string) wp_unslash( $_POST['name'] ?? '' ) ) );
	}

	public static function save_delete(): void {
		self::guard( 'pc_referrals_delete' );
		self::done( Referrals::remove_owner( sanitize_key( wp_unslash( $_POST['code'] ?? '' ) ) ) );
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
