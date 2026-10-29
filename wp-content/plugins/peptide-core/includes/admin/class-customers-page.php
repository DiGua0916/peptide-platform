<?php
/** Customer operations, segment definitions and an auditable points ledger. */
namespace PeptideCore\Admin;

use PeptideCore\Customer_Segments;
use PeptideCore\Loyalty;

defined( 'ABSPATH' ) || exit;

final class Customers_Page {
	public const SLUG = 'pc-customers';
	public const CAP = 'manage_woocommerce';

	public static function register_hooks(): void {
		add_action( 'admin_post_pc_customer_save', array( self::class, 'save' ) );
		add_action( 'admin_post_pc_customer_catalog', array( self::class, 'catalog_save' ) );
		add_action( 'admin_post_pc_customer_points', array( self::class, 'points_save' ) );
		add_action( 'admin_post_pc_customer_loyalty', array( self::class, 'settings_save' ) );
		add_action( 'admin_post_pc_customer_bulk', array( self::class, 'bulk_save' ) );
		add_action( 'admin_enqueue_scripts', static function () {
			if ( self::SLUG === ( $_GET['page'] ?? '' ) ) { wp_enqueue_style( 'pc-customers', plugins_url( 'assets/customers.css', PEPTIDE_CORE_FILE ), array(), PEPTIDE_CORE_VERSION ); }
		} );
	}

	public static function url( array $args = array() ): string { return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) ); }

	public static function guard( string $action = '' ): void {
		if ( ! Loyalty::can_manage() ) { wp_die( '你没有顾客管理权限。', '无权访问', array( 'response' => 403 ) ); }
		if ( $action ) { check_admin_referer( $action ); }
	}

	private static function begin_form( string $action ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( $action ) . '">'; wp_nonce_field( $action );
	}

	private static function result( $result, array $args = array() ): void {
		if ( is_wp_error( $result ) ) { set_transient( 'pc_customer_error_' . get_current_user_id(), $result->get_error_message(), 120 ); }
		wp_safe_redirect( self::url( $args + array( 'saved' => is_wp_error( $result ) ? 0 : 1 ) ) ); exit;
	}

	public static function save(): void {
		self::guard( 'pc_customer_save' );
		$id = absint( $_POST['customer_id'] ?? 0 );
		$r = Customer_Segments::set_profile( $id, (array) wp_unslash( $_POST['tags'] ?? array() ), sanitize_key( wp_unslash( $_POST['level'] ?? '' ) ) );
		self::result( $r, array( 'customer_id' => $id ) );
	}

	public static function catalog_save(): void {
		self::guard( 'pc_customer_catalog' );
		$out = array();
		foreach ( array( 'tags', 'levels' ) as $kind ) {
			$out[ $kind ] = array();
			foreach ( preg_split( '/\r?\n/', (string) wp_unslash( $_POST[ $kind ] ?? '' ) ) as $line ) {
				if ( '' === trim( $line ) ) { continue; }
				$parts = explode( '|', $line, 2 );
				$slug = trim( $parts[0] );
				if ( 2 !== count( $parts ) || isset( $out[ $kind ][ $slug ] ) || ! preg_match( '/^[a-z0-9_-]{1,40}$/', $slug ) ) { self::result( new \WP_Error( 'format', '每行请使用唯一的英文标识|中文名称，例如 vip|VIP顾客。' ), array( 'tab' => 'catalog' ) ); }
				$out[ $kind ][ $slug ] = trim( $parts[1] );
			}
		}
		self::result( Customer_Segments::save_catalog( $out ), array( 'tab' => 'catalog' ) );
	}

	public static function points_save(): void {
		self::guard( 'pc_customer_points' );
		$id = absint( $_POST['customer_id'] ?? 0 );
		$r = Loyalty::adjust( $id, (int) ( $_POST['delta'] ?? 0 ), sanitize_text_field( wp_unslash( $_POST['reason'] ?? '' ) ), sanitize_text_field( wp_unslash( $_POST['request'] ?? '' ) ) );
		self::result( $r, array( 'customer_id' => $id ) );
	}

	public static function settings_save(): void {
		self::guard( 'pc_customer_loyalty' );
		self::result( Loyalty::save_settings( wp_unslash( $_POST ) ), array( 'tab' => 'rules' ) );
	}

	public static function bulk_save(): void {
		self::guard( 'pc_customer_bulk' );
		$ids = array_values( array_unique( array_map( 'absint', (array) ( $_POST['ids'] ?? array() ) ) ) );
		$catalog = Customer_Segments::catalog();
		$tag = sanitize_key( wp_unslash( $_POST['tag'] ?? '' ) );
		$op = sanitize_key( wp_unslash( $_POST['operation'] ?? '' ) );
		$level = sanitize_key( wp_unslash( $_POST['level'] ?? '' ) );
		if ( ! $ids || count( $ids ) > 100 || array_filter( $ids, static fn( $id ) => ! Customer_Segments::is_customer( $id ) ) || ! in_array( $op, array( 'add', 'remove', 'level' ), true ) || ( 'level' !== $op && ! isset( $catalog['tags'][ $tag ] ) ) || ( 'level' === $op && '' !== $level && ! isset( $catalog['levels'][ $level ] ) ) ) { self::result( new \WP_Error( 'selection', '请选择有效顾客及标签或等级。' ) ); }
		foreach ( $ids as $id ) {
			$p = Customer_Segments::profile( $id );
			$tags = 'add' === $op ? array_merge( $p['tags'], array( $tag ) ) : ( 'remove' === $op ? array_diff( $p['tags'], array( $tag ) ) : $p['tags'] );
			Customer_Segments::set_profile( $id, array_values( $tags ), 'level' === $op ? $level : $p['level'] );
		}
		self::result( true );
	}

	private static function select( string $name, array $choices, string $value = '', string $empty = '未设置' ): void {
		echo '<select name="' . esc_attr( $name ) . '"><option value="">' . esc_html( $empty ) . '</option>';
		foreach ( $choices as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '"' . selected( $key, $value, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select>';
	}

	public static function render(): void {
		self::guard();
		$c = Customer_Segments::catalog();
		$tab = sanitize_key( $_GET['tab'] ?? 'customers' );
		echo '<div class="wrap pc-customers"><h1>顾客运营</h1><p><a href="' . esc_url( admin_url( 'admin.php?page=wc-admin&path=/customers' ) ) . '">打开 WooCommerce 原生顾客与购买统计</a></p><nav class="nav-tab-wrapper">';
		foreach ( array( 'customers' => '顾客与积分明细', 'catalog' => '标签和等级', 'rules' => '积分规则' ) as $key => $label ) { echo '<a class="nav-tab ' . ( $tab === $key ? 'nav-tab-active' : '' ) . '" href="' . esc_url( self::url( array( 'tab' => $key ) ) ) . '">' . esc_html( $label ) . '</a>'; }
		echo '</nav>';
		$error = get_transient( 'pc_customer_error_' . get_current_user_id() );
		if ( $error ) { delete_transient( 'pc_customer_error_' . get_current_user_id() ); echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>'; }
		if ( ! empty( $_GET['saved'] ) ) { echo '<div class="notice notice-success"><p>已保存。</p></div>'; }
		if ( 'catalog' === $tab ) { self::catalog_form( $c ); }
		elseif ( 'rules' === $tab ) { self::rules_form(); }
		elseif ( ! empty( $_GET['customer_id'] ) ) { self::customer_detail( absint( $_GET['customer_id'] ), $c ); }
		else { self::customer_list( $c ); }
		echo '</div>';
	}

	private static function catalog_form( array $c ): void {
		echo '<section class="pc-customer-card"><h2>管理标签和等级</h2><p>每行填写：英文标识|显示名称。标识用于营销规则，请保持稳定。删除定义后不会再匹配该标签或等级，也不会删除历史积分。</p>';
		self::begin_form( 'pc_customer_catalog' );
		foreach ( array( 'tags' => '顾客标签（顾客可有多个）', 'levels' => '会员等级（每位顾客一个，手动分配）' ) as $kind => $label ) {
			$lines = array(); foreach ( $c[ $kind ] as $slug => $name ) { $lines[] = $slug . '|' . $name; }
			echo '<p><label>' . esc_html( $label ) . '<textarea name="' . esc_attr( $kind ) . '" rows="8" class="large-text">' . esc_textarea( implode( "\n", $lines ) ) . '</textarea></label></p>';
		}
		submit_button( '保存标签和等级' ); echo '</form></section>';
	}

	private static function rules_form(): void {
		$s = Loyalty::settings(); self::begin_form( 'pc_customer_loyalty' );
		echo '<section class="pc-customer-card"><h2>积分赚取和兑换</h2><p>两个开关默认关闭。开启赚取只影响开启之后新建并付款的订单，不回算历史订单；按折后商品金额计算，不含运费和税。规则更改不重算已记账积分。</p><p><label><input type="checkbox" name="enabled" value="1"' . checked( (bool) $s['enabled'], true, false ) . '> 开启新订单付款赚取积分</label></p><p><label>每1个店铺货币单位赚取积分 <input type="number" name="earn_per_unit" min="0.0001" max="10000" step="0.0001" value="' . esc_attr( (string) $s['earn_per_unit'] ) . '" required></label></p><p><label><input type="checkbox" name="redeem_enabled" value="1"' . checked( (bool) $s['redeem_enabled'], true, false ) . '> 开启积分兑换实际优惠券（会影响订单金额）</label></p>';
		foreach ( array( 'redeem_per_unit' => '兑换1个店铺货币单位所需积分', 'minimum' => '每次最低兑换积分', 'expiry_days' => '兑换券有效天数（1–365）' ) as $key => $label ) { echo '<p><label>' . esc_html( $label ) . ' <input type="number" name="' . esc_attr( $key ) . '" min="1" max="' . ( 'expiry_days' === $key ? 365 : 1000000 ) . '" step="1" value="' . (int) $s[ $key ] . '" required></label></p>'; }
		echo '<p><label><input type="checkbox" name="notify_enabled" value="1"' . checked( (bool) $s['notify_enabled'], true, false ) . '> 开启积分变动邮件通知（异步发送至顾客账户邮箱）</label></p>';
		echo '<p>兑换时预扣积分并生成本人专用、仅一次有效的优惠券；只接受可精确兑换最小货币单位的积分数量，不向上舍入。未用券可取消、到期自动返还。订单取消/失败/全额退款返还兑换积分，部分商品退款按比例返还；未指定项目的纯金额退款，按原整单金额比例分摊。付款所得积分同样按退款比例撤销；已花掉的积分记为待抵扣额，后续赚取先抵扣，余额不出现负数。</p>';
		submit_button( '保存积分规则' ); echo '</section></form>';
	}

	private static function customer_list( array $c ): void {
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$page = max( 1, absint( $_GET['paged'] ?? 1 ) ); $tag = sanitize_key( $_GET['tag'] ?? '' ); $level = sanitize_key( $_GET['level'] ?? '' );
		$args = array( 'role' => 'customer', 'number' => 25, 'offset' => ( $page - 1 ) * 25, 'orderby' => 'registered', 'order' => 'DESC', 'count_total' => true );
		if ( $search ) { $args['search'] = '*' . $search . '*'; $args['search_columns'] = array( 'user_login', 'user_email', 'display_name' ); }
		if ( $tag ) { $args['meta_query'][] = array( 'key' => Customer_Segments::TAGS, 'value' => '"' . $tag . '"', 'compare' => 'LIKE' ); }
		if ( $level ) { $args['meta_query'][] = array( 'key' => Customer_Segments::LEVEL, 'value' => $level ); }
		$q = new \WP_User_Query( $args );
		echo '<form method="get" class="pc-customer-toolbar"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input name="s" value="' . esc_attr( $search ) . '" placeholder="搜索顾客名称或邮箱"> '; self::select( 'tag', $c['tags'], $tag, '全部标签' ); self::select( 'level', $c['levels'], $level, '全部等级' ); echo ' <button class="button">筛选</button></form>';
		self::begin_form( 'pc_customer_bulk' );
		echo '<div class="pc-customer-toolbar"><select name="operation"><option value="add">添加标签</option><option value="remove">移除标签</option><option value="level">设定等级</option></select> '; self::select( 'tag', $c['tags'], '', '选择标签' ); self::select( 'level', $c['levels'], '', '清空等级' ); echo ' <button class="button">对勾选顾客应用</button></div><div class="pc-customer-scroll"><table class="widefat striped"><thead><tr><th>选择</th><th>顾客</th><th>邮箱</th><th>标签 / 等级</th><th>可用积分</th><th>退款待抵扣</th></tr></thead><tbody>';
		foreach ( $q->get_results() as $u ) {
			if ( ! Customer_Segments::is_customer( (int) $u->ID ) ) { continue; }
			$p = Customer_Segments::profile( (int) $u->ID ); $a = Loyalty::account( (int) $u->ID ); $labels = array_map( static fn( $k ) => $c['tags'][ $k ], $p['tags'] );
			echo '<tr><td><input type="checkbox" name="ids[]" value="' . (int) $u->ID . '"></td><td><a href="' . esc_url( self::url( array( 'customer_id' => $u->ID ) ) ) . '">' . esc_html( $u->display_name ) . '</a></td><td>' . esc_html( $u->user_email ) . '</td><td>' . esc_html( implode( '、', $labels ) . ' / ' . ( $c['levels'][ $p['level'] ] ?? '未设置等级' ) ) . '</td><td>' . (int) $a['balance'] . '</td><td>' . (int) $a['debt'] . '</td></tr>';
		}
		echo '</tbody></table></div></form><p>共 ' . (int) $q->get_total() . ' 位顾客。';
		foreach ( array( $page - 1 => '上一页', $page + 1 => '下一页' ) as $num => $label ) { if ( $num > 0 && ( $num < $page || $num <= (int) ceil( $q->get_total() / 25 ) ) ) { echo ' <a href="' . esc_url( self::url( array( 'paged' => $num, 's' => $search, 'tag' => $tag, 'level' => $level ) ) ) . '">' . esc_html( $label ) . '</a>'; } } echo '</p>';
	}

	private static function customer_detail( int $id, array $c ): void {
		if ( ! Customer_Segments::is_customer( $id ) ) { echo '<p>顾客不存在或不是可管理的顾客账户。</p>'; return; }
		$u = get_userdata( $id ); $p = Customer_Segments::profile( $id ); $a = Loyalty::account( $id );
		echo '<p><a href="' . esc_url( self::url() ) . '">← 返回顾客列表</a></p><h2>' . esc_html( $u->display_name ) . '</h2><p>' . esc_html( $u->user_email ) . '</p><section class="pc-customer-card"><h3>标签与等级</h3>';
		self::begin_form( 'pc_customer_save' ); echo '<input type="hidden" name="customer_id" value="' . $id . '">';
		foreach ( $c['tags'] as $key => $label ) { echo '<label class="pc-customer-tag"><input type="checkbox" name="tags[]" value="' . esc_attr( $key ) . '"' . checked( in_array( $key, $p['tags'], true ), true, false ) . '> ' . esc_html( $label ) . '</label>'; }
		echo '<p>会员等级 '; self::select( 'level', $c['levels'], $p['level'] ); echo '</p>'; submit_button( '保存顾客分群' ); echo '</form></section><section class="pc-customer-card"><h3>积分账户</h3><p>可用积分：<strong>' . (int) $a['balance'] . '</strong>；退款待抵扣：<strong>' . (int) $a['debt'] . '</strong></p>';
		self::begin_form( 'pc_customer_points' ); echo '<input type="hidden" name="customer_id" value="' . $id . '"><input type="hidden" name="request" value="' . esc_attr( wp_generate_uuid4() ) . '"><p><label>调整积分（正数增加，负数扣减） <input type="number" name="delta" min="-1000000" max="1000000" step="1" required></label></p><p><label>调整原因 <input type="text" name="reason" maxlength="250" class="regular-text" required></label></p>'; submit_button( '记账调整积分' ); echo '</form></section>';
		$page = max( 1, absint( $_GET['ledger_page'] ?? 1 ) ); $rows = Loyalty::ledger( $id, 51, ( $page - 1 ) * 50 );
		echo '<h3>积分流水（UTC）</h3><div class="pc-customer-scroll"><table class="widefat striped"><thead><tr><th>流水号 / 时间</th><th>类型</th><th>变动</th><th>余额 / 待抵扣</th><th>订单</th><th>操作人</th><th>邮件状态</th><th>原因</th></tr></thead><tbody>';
		foreach ( array_slice( $rows, 0, 50 ) as $r ) { $actor = $r['actor_id'] ? get_userdata( (int) $r['actor_id'] ) : false; echo '<tr><td>#' . (int) $r['id'] . '<br>' . esc_html( $r['created_at'] ) . '</td><td>' . esc_html( self::kind_label( $r['kind'] ) ) . '</td><td>' . (int) $r['delta'] . '</td><td>' . (int) $r['balance'] . ' / ' . (int) $r['debt'] . '</td><td>' . ( $r['order_id'] ? '#' . (int) $r['order_id'] : '—' ) . '</td><td>' . esc_html( $actor ? $actor->display_name : '系统' ) . '</td><td>' . esc_html( array( 'none' => '未开启', 'queued' => '排队中', 'sending' => '发送中', 'sent' => '已发送', 'failed' => '失败', 'skipped' => '已跳过' )[ $r['notice_status'] ?? 'none' ] ?? '—' ) . '</td><td>' . esc_html( $r['note'] ) . '</td></tr>'; }
		echo '</tbody></table></div><p>';
		if ( $page > 1 ) { echo '<a href="' . esc_url( self::url( array( 'customer_id' => $id, 'ledger_page' => $page - 1 ) ) ) . '">上一页</a> '; }
		if ( count( $rows ) > 50 ) { echo '<a href="' . esc_url( self::url( array( 'customer_id' => $id, 'ledger_page' => $page + 1 ) ) ) . '">下一页</a>'; } echo '</p>';
	}

	public static function kind_label( string $kind ): string { return array( 'adjustment' => '人工调整', 'earned' => '订单赚取', 'earn_reversal' => '付款积分撤销', 'redeemed' => '兑换预扣', 'redeem_return' => '退款/取消返还', 'redeem_release' => '未用兑换券返还' )[ $kind ] ?? $kind; }
}
