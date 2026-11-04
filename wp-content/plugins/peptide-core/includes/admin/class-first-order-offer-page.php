<?php
/** Explicit merchant-only binding/creation; rendering and anonymous GETs never write settings. */
namespace PeptideCore\Admin;

use PeptideCore\First_Order_Offer;
use PeptideCore\Coupon_Rules;

defined( 'ABSPATH' ) || exit;

final class First_Order_Offer_Page {
	public const ACTION = 'pc_first_order_offer_save';
	public static function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
	}
	private static function access(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_shop_coupons' ) ) { wp_die( '没有首单优惠配置权限。', '', array( 'response'=>403 ) ); }
	}
	public static function assets(): void {
		if ( 'pc-apps' !== ( $_GET['page'] ?? '' ) || First_Order_Offer::APP !== ( $_GET['app'] ?? '' ) ) { return; }
		self::access();
		wp_enqueue_style( 'pc-first-order-offer', plugins_url( 'assets/first-order-offer.css', PEPTIDE_CORE_FILE ), array(), (string) filemtime( PEPTIDE_CORE_DIR . '/assets/first-order-offer.css' ) );
	}
	public static function handle(): void {
		self::access();
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( '只接受 POST。', '', array( 'response'=>405 ) ); }
		check_admin_referer( self::ACTION );
		$mode = is_string( $_POST['mode'] ?? null ) ? $_POST['mode'] : '';
		if ( 'create' === $mode ) {
			$id = First_Order_Offer::create_welcome_coupon();
			if ( is_wp_error( $id ) ) { wp_die( esc_html( $id->get_error_message() ), '', array( 'response'=>400 ) ); }
			$current = First_Order_Offer::settings();
			$settings = array( 'enabled'=>$current['coupon_id'] === $id && $current['enabled'], 'coupon_id'=>$id );
		} elseif ( 'save' === $mode ) {
			$id = filter_var( $_POST['coupon_id'] ?? null, FILTER_VALIDATE_INT, array( 'options'=>array( 'min_range'=>0 ) ) );
			if ( false === $id ) { wp_die( '请选择有效优惠券。', '', array( 'response'=>400 ) ); }
			$settings = array( 'enabled'=>isset( $_POST['enabled'] ) && '1' === $_POST['enabled'], 'coupon_id'=>$id );
			if ( $settings['enabled'] && ( ! function_exists( 'wc_coupons_enabled' ) || ! wc_coupons_enabled() ) ) { wp_die( 'WooCommerce 尚未开启优惠券，请先在店铺设置中开启后再应用。未保存。', '', array( 'response'=>400 ) ); }
			if ( $id || $settings['enabled'] ) {
				$issue = First_Order_Offer::coupon_issue( class_exists( '\WC_Coupon' ) ? new \WC_Coupon( $id ) : null, $settings['enabled'] );
				if ( '' !== $issue ) { wp_die( '优惠券不符合首单 5% 及叠加规则，或已失效。未保存，请检查优惠券设置。', '', array( 'response'=>400 ) ); }
			}
		} else { wp_die( '未知操作。', '', array( 'response'=>400 ) ); return; }
		update_option( First_Order_Offer::OPTION, First_Order_Offer::normalize( $settings ), false );
		wp_safe_redirect( Apps_Page::url( array( 'app'=>First_Order_Offer::APP, 'saved'=>'1' ) ) );
		exit;
	}
	public static function render(): void {
		self::access(); $s = First_Order_Offer::settings();
		echo '<div class="wrap pc-mk pc-first-offer-admin"><a href="' . esc_url( Apps_Page::url() ) . '">返回应用中心</a><h1>首单 5% 优惠</h1>';
		echo '<p>绑定一张原生 Woo 优惠券，供首页首单优惠积木使用。优惠券使用次数、首单校验和实际减免仍由 Woo 管理。</p>';
		if ( '1' === ( $_GET['saved'] ?? '' ) ) { echo '<div class="notice notice-success"><p>配置已保存。创建券后仍需自行开启。</p></div>'; }
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="mode" value="save">';
		echo '<div class="pc-first-offer-admin__panel"><h2>优惠与展示</h2><p><label><input type="checkbox" name="enabled" value="1"' . checked( $s['enabled'], true, false ) . '> 开启首单优惠块</label></p>';
		echo '<p><label for="pc-first-offer-coupon">绑定现有优惠券</label></p><select id="pc-first-offer-coupon" name="coupon_id"><option value="0">请选择</option>';
		if ( class_exists( '\WC_Coupon' ) ) {
			$posts = get_posts( array( 'post_type'=>'shop_coupon', 'post_status'=>array('publish','draft'), 'posts_per_page'=>300, 'orderby'=>'date', 'order'=>'DESC' ) );
			if ( $s['coupon_id'] && ! in_array( $s['coupon_id'], array_map( static fn( $post ) => (int) $post->ID, $posts ), true ) ) { $posts[] = (object) array( 'ID'=>$s['coupon_id'] ); }
			foreach ( $posts as $post ) {
				$c = new \WC_Coupon( $post->ID );
				if ( '' !== First_Order_Offer::coupon_issue( $c, false ) ) { continue; }
				$label = Coupon_Rules::display_code( $c ) . ( 'active' === Coupon_Rules::status( $c ) ? '' : '（当前不可用）' );
				echo '<option value="' . esc_attr( (string) $c->get_id() ) . '"' . selected( $s['coupon_id'], $c->get_id(), false ) . '>' . esc_html( $label ) . '</option>';
			}
		}
		echo '</select><p class="description">只显示：5% 百分比、全局首单、每人 1 次、允许叠加、非独享、不附加免运费的优惠券。商品范围、门槛和有效期沿用所选券。</p>';
		echo '<p>满减活动也须允许与优惠券共用；若当前活动不兼容，申请会被拒绝，已有优惠不会被替换。匿名顾客未填邮箱时不能最终确认首单资格，结账仍需复核。</p>';
		submit_button( '保存配置' ); echo '</div></form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pc-first-offer-admin__panel"><h2>还没有合适的券？</h2><p>明确点击后创建 WELCOME5，绑定但不自动开启。默认 5%、首单、每人一次、允许叠加；没有到期时间和总次数上限，可在原生优惠券页继续配置。同名券绝不覆盖。</p>';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="mode" value="create">';
		submit_button( '创建并绑定 WELCOME5', 'secondary' ); echo '</form></div>';
	}
}
