<?php
/**
 * M9 投手后台的两个按店开关（2026-11-14，“优化，不改功能”）：
 * - pc_buyer_extras：导出订单、订单列表的退款状态和详情页退款提示、店铺信息里的“显示客服按钮”；
 * - pc_buyer_no_palette：投手不加载 WordPress 的命令面板。
 * 两个都默认关；关着时投手看到的和线上一样。
 *
 * 运行：php -n wp-content/plugins/peptide-core/tests/m9-buyer-switches-pure.php
 * 纯逻辑，不加载 WordPress；下面只放这几个函数用得到的替身。
 */

namespace PeptideCore {
	final class Staff {
		public static function disabled( int $user_id ): bool { return false; }
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	function check( string $label, bool $ok ): void {
		global $checks;
		if ( ! $ok ) {
			fwrite( STDERR, "FAIL: $label\n" );
			exit( 1 );
		}
		$checks = ( $checks ?? 0 ) + 1;
	}

	final class WP_User {
		public int $ID;
		public array $roles;
		public array $allcaps;
		public function __construct( int $id, array $roles, array $allcaps = array() ) { $this->ID = $id; $this->roles = $roles; $this->allcaps = $allcaps; }
	}
	final class WP_Admin_Bar {
		public array $nodes = array();
		public function remove_node( $id ) { unset( $this->nodes[ $id ] ); }
		public function get_node( $id ) { return $this->nodes[ $id ] ?? null; }
		public function add_node( $args ) { $this->nodes[ $args['id'] ] = $args; }
	}

	$GLOBALS['f5_options']  = array();
	$GLOBALS['f5_filters']  = array();
	$GLOBALS['f5_removed']  = array();
	$GLOBALS['f5_current']  = 7;
	$GLOBALS['f5_users']    = array( 7 => new WP_User( 7, array( 'pc_staff', 'pc_media_buyer' ), array( 'read' => true ) ), 1 => new WP_User( 1, array( 'administrator' ), array( 'manage_options' => true ) ) );
	function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['f5_options'] ) ? $GLOBALS['f5_options'][ $name ] : $default; }
	function apply_filters( $hook, $value ) { return array_key_exists( $hook, $GLOBALS['f5_filters'] ) ? $GLOBALS['f5_filters'][ $hook ] : $value; }
	function remove_action( $hook, $callback ) { $GLOBALS['f5_removed'][] = $hook . ' ' . $callback; return true; }
	function get_current_user_id(): int { return (int) $GLOBALS['f5_current']; }
	function get_userdata( $id ) { return $GLOBALS['f5_users'][ (int) $id ] ?? false; }
	function get_user_meta( $id, $key = '', $single = false ) { return ''; }
	function current_user_can( $cap ) { $u = get_userdata( get_current_user_id() ); return $u && ! empty( $u->allcaps[ $cap ] ); }
	function is_admin(): bool { return true; }
	function wp_doing_ajax(): bool { return false; }
	function wp_unslash( $v ) { return $v; }
	function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ); }
	function sanitize_text_field( $v ) { return trim( (string) $v ); }
	function admin_url( $path = '' ) { return 'https://store.test/wp-admin/' . $path; }
	function add_query_arg( $args, $url = '' ) { return $url . ( false === strpos( (string) $url, '?' ) ? '?' : '&' ) . http_build_query( (array) $args ); }
	function esc_url( $v ) { return (string) $v; }
	function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }
	function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }
	function wp_nonce_field() { echo '<input type="hidden" name="_wpnonce" value="N">'; }
	function wp_date( $format ) { return '2026-11-14'; }

	$inc = dirname( __DIR__ ) . '/includes';
	require $inc . '/class-capabilities.php';
	require $inc . '/class-staff-buyer.php';
	require $inc . '/admin/class-buyer-order-export.php';

	use PeptideCore\Staff_Buyer;
	use PeptideCore\Admin\Buyer_Order_Export;

	$set = static function ( array $options, array $filters = array() ): void { $GLOBALS['f5_options'] = $options; $GLOBALS['f5_filters'] = $filters; };
	$render = static function ( callable $fn ): string { ob_start(); $fn(); return (string) ob_get_clean(); };

	// ---------- 两个开关的默认值和写法 ----------
	$set( array() );
	check( 'extras: off by default (option missing)', false === Staff_Buyer::extras() );
	check( 'no-palette: off by default (option missing)', false === Staff_Buyer::hide_palette() );
	$set( array( 'pc_buyer_extras' => 'off', 'pc_buyer_no_palette' => '1' ) );
	check( 'only the value "on" turns them on', false === Staff_Buyer::extras() && false === Staff_Buyer::hide_palette() );
	$set( array( 'pc_buyer_extras' => 'on', 'pc_buyer_no_palette' => 'on' ) );
	check( 'value "on" turns each on', true === Staff_Buyer::extras() && true === Staff_Buyer::hide_palette() );
	$set( array( 'pc_buyer_extras' => 'on' ), array( 'pc_buyer_extras_enabled' => false ) );
	check( 'a filter can override the option', false === Staff_Buyer::extras() );
	check( 'option names are the documented ones', 'pc_buyer_extras' === Staff_Buyer::OPTION_EXTRAS && 'pc_buyer_no_palette' === Staff_Buyer::OPTION_NO_PALETTE );
	$caps = Staff_Buyer::capabilities( array() );
	check( 'the store tells the master admin it supports both switches', '1' === ( $caps['m9.buyer_extras'] ?? '' ) && '1' === ( $caps['m9.buyer_no_palette'] ?? '' ) );

	// ---------- 命令面板：开关关着和线上一样加载 ----------
	$fresh_bar = static function (): WP_Admin_Bar {
		$bar = new WP_Admin_Bar();
		foreach ( array( 'new-content', 'comments', 'command-palette', 'my-account' ) as $id ) { $bar->nodes[ $id ] = array( 'id' => $id ); }
		return $bar;
	};
	$set( array() );
	$GLOBALS['f5_removed'] = array();
	Staff_Buyer::no_command_palette();
	check( 'switch off: the palette scripts are still loaded for buyers (as online)', array() === $GLOBALS['f5_removed'] );
	$bar = $fresh_bar(); Staff_Buyer::admin_bar( $bar );
	check( 'switch off: the toolbar keeps its palette button (as online); other nodes are removed as before', isset( $bar->nodes['command-palette'] ) && ! isset( $bar->nodes['new-content'] ) && ! isset( $bar->nodes['comments'] ) );
	$set( array( 'pc_buyer_no_palette' => 'on' ) );
	Staff_Buyer::no_command_palette();
	check( 'switch on: the palette scripts are not loaded for buyers', array( 'admin_enqueue_scripts wp_enqueue_command_palette_assets' ) === $GLOBALS['f5_removed'] );
	$bar = $fresh_bar(); Staff_Buyer::admin_bar( $bar );
	check( 'switch on: the toolbar button is removed too', ! isset( $bar->nodes['command-palette'] ) );
	$GLOBALS['f5_current'] = 1; $GLOBALS['f5_removed'] = array();
	Staff_Buyer::no_command_palette();
	$bar = $fresh_bar(); Staff_Buyer::admin_bar( $bar );
	check( 'administrators always keep the palette', array() === $GLOBALS['f5_removed'] && isset( $bar->nodes['command-palette'] ) && isset( $bar->nodes['new-content'] ) );
	$GLOBALS['f5_current'] = 7;

	// ---------- 导出订单：开关关着时订单列表上没有表单 ----------
	$_GET = array( 'page' => 'wc-orders' );
	$set( array() );
	check( 'switch off: no export form on the buyer order list (as online)', '' === $render( array( Buyer_Order_Export::class, 'form' ) ) );
	$set( array( 'pc_buyer_extras' => 'on' ) );
	$form = $render( array( Buyer_Order_Export::class, 'form' ) );
	check( 'switch on: the export form is there', false !== strpos( $form, 'name="action" value="pc_buyer_orders_export"' ) && false !== strpos( $form, '下载表格' ) );
	$GLOBALS['f5_current'] = 1;
	check( 'administrators never get the buyer export form', '' === $render( array( Buyer_Order_Export::class, 'form' ) ) );
	$GLOBALS['f5_current'] = 7;
	$src = (string) file_get_contents( $inc . '/admin/class-buyer-order-export.php' );
	check( 'switch off: the download itself refuses too (not just the hidden form)', false !== strpos( $src, "if ( ! Staff_Buyer::can_use() || ! Staff_Buyer::extras() ) {\n\t\t\tStaff_Buyer::deny();" ) );

	// ---------- 订单列表的新列、详情页退款提示、店铺信息的勾选框：都跟着同一个开关 ----------
	$menus = (string) file_get_contents( $inc . '/admin/class-admin-menus.php' );
	check( 'order list columns follow the switch', false !== strpos( $menus, 'return self::buyer_columns( $columns, \\PeptideCore\\Staff_Buyer::extras() );' ) );
	check( 'refund notice on the detail page follows the switch', false !== strpos( $menus, "if ( ! \$detail || ! \\PeptideCore\\Staff_Buyer::extras() || ! function_exists( 'wc_get_order' ) ) {" ) );
	$store = (string) file_get_contents( $inc . '/admin/class-store-settings.php' );
	check( 'store info: the support-button checkbox is only drawn with the switch on', 1 === preg_match( "/if \\( Staff_Buyer::extras\\(\\) \\) \\{\\s*echo '<input type=\"hidden\" name=\"float_present\"/", $store ) );
	check( 'store info: saving never touches support.enabled with the switch off, even from a crafted form', false !== strpos( $store, "if ( null !== \$clean['float'] && Staff_Buyer::extras() ) {" ) );

	echo "PASS {$checks} media-buyer switch checks\n";
}
