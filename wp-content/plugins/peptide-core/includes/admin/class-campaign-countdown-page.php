<?php
/** Campaign countdown application settings; timing remains native promotion data. */
namespace PeptideCore\Admin;

use PeptideCore\Campaign_Countdown;
use PeptideCore\Activity\Store;

defined( 'ABSPATH' ) || exit;

final class Campaign_Countdown_Page {
	public const APP = 'campaign-countdown';
	public const ACTION = 'pc_campaign_countdown_save';
	public static function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}
	public static function enqueue(): void {
		if ( 'pc-apps' !== ( $_GET['page'] ?? '' ) || self::APP !== ( $_GET['app'] ?? '' ) || ! Store::can_manage() ) { return; }
		wp_enqueue_style( 'pc-campaign-countdown', plugins_url( 'assets/campaign-countdown.css', PEPTIDE_CORE_FILE ), array(), (string) filemtime( PEPTIDE_CORE_DIR . '/assets/campaign-countdown.css' ) );
	}
	public static function url(): string { return Apps_Page::url( array( 'app' => self::APP ) ); }
	public static function save(): void {
		if ( ! Store::can_manage() ) { wp_die( '没有修改应用的权限。', '', array( 'response' => 403 ) ); }
		check_admin_referer( self::ACTION );
		$id = $_POST['promotionId'] ?? '0';
		if ( ! is_string( $id ) || ! preg_match( '/\A(?:0|[1-9][0-9]{0,9})\z/', $id ) || (int) $id > 2147483647 ) { self::redirect( 'invalid' ); }
		$settings = array( 'version' => 1, 'promotionId' => (int) $id );
		foreach ( array( 'enabled', 'checkout', 'product' ) as $key ) {
			$value = $_POST[ $key ] ?? '0';
			if ( ! is_string( $value ) || ! in_array( $value, array( '0', '1' ), true ) ) { self::redirect( 'invalid' ); }
			$settings[ $key ] = '1' === $value;
		}
		if ( $settings['enabled'] ) {
			$choices = array_column( Campaign_Countdown::candidates( (int) $id ), null, 'id' );
			if ( ! isset( $choices[ (int) $id ] ) || ! in_array( $choices[ (int) $id ]['status'], array( 'active', 'upcoming' ), true ) ) { self::redirect( 'selection' ); }
		}
		update_option( Campaign_Countdown::OPTION, Campaign_Countdown::normalize( $settings ), false );
		self::redirect( Campaign_Countdown::settings() === Campaign_Countdown::normalize( $settings ) ? 'saved' : 'failed' );
	}
	private static function redirect( string $notice ): void { wp_safe_redirect( add_query_arg( 'countdown_notice', $notice, self::url() ) ); exit; }
	public static function render(): void {
		if ( ! Store::can_manage() ) { echo '<div class="notice notice-error"><p>没有访问应用设置的权限。</p></div>'; return; }
		$c = Campaign_Countdown::settings(); $rows = Campaign_Countdown::candidates( $c['promotionId'] );
		$notice = $_GET['countdown_notice'] ?? '';
		$messages = array( 'saved' => '设置已保存。', 'invalid' => '设置无效，请重新选择。', 'selection' => '请选择公开、已启用或即将开始的有效活动。', 'failed' => '保存未完成，请重试。' );
		if ( is_string( $notice ) && isset( $messages[ $notice ] ) ) { echo '<div class="notice ' . ( 'saved' === $notice ? 'notice-success' : 'notice-error' ) . '"><p>' . esc_html( $messages[ $notice ] ) . '</p></div>'; }
		echo '<div class="wrap pc-countdown-settings"><p><a href="' . esc_url( Apps_Page::url() ) . '">返回应用中心</a></p><h1>活动倒计时</h1><p>结账页和商品弹窗共用同一个活动与真实截止时间。刷新页面不会重置倒计时。</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<section class="pc-ss-panel"><h2>关联活动</h2><p><label><input type="checkbox" name="enabled" value="1"' . checked( $c['enabled'], true, false ) . '> 启用活动倒计时</label></p><p><label for="pc-countdown-promotion">优惠活动</label></p><select id="pc-countdown-promotion" name="promotionId"><option value="0">请选择已有活动</option>';
		$labels = array( 'active' => '进行中', 'upcoming' => '未开始', 'ended' => '已结束' );
		foreach ( $rows as $row ) { echo '<option value="' . esc_attr( (string) $row['id'] ) . '"' . selected( $c['promotionId'], $row['id'], false ) . '>' . esc_html( $row['title'] . ' · #' . $row['id'] . ' · ' . ( $labels[ $row['status'] ] ?? '不可用' ) . ( $row['hasDeadline'] ? '' : ' · 未设置截止时间' ) ) . '</option>'; }
		echo '</select>';
		$selected = array_column( $rows, null, 'id' )[ $c['promotionId'] ] ?? null;
		if ( $selected ) {
			echo '<p>' . esc_html( $selected['ends'] ? '截止时间：' . wp_date( 'Y-m-d H:i:s T', $selected['ends'] ) : '此活动未设置截止时间，前台不显示倒计时。请在活动中设置真实时间。' ) . '</p>';
			if ( current_user_can( Marketing::CAP ) ) { echo '<p><a href="' . esc_url( Promotions_Page::url( array( 'edit' => $selected['id'] ) ) ) . '">编辑此活动与时间</a></p>'; }
			if ( $selected['tiers'] ) { echo '<ul>'; foreach ( $selected['tiers'] as $tier ) { echo '<li>' . esc_html( $tier['text'] ) . '</li>'; } echo '</ul>'; }
		} else { echo '<p>仅可关联公开、已发布且无密码保护的优惠活动。倒计时不创建优惠或改变结算规则。</p>'; }
		echo '</section><section class="pc-ss-panel"><h2>展示位置</h2><p><label><input type="checkbox" name="checkout" value="1"' . checked( $c['checkout'], true, false ) . '> 结账页：优惠卡下方的红色胶囊横条</label></p><p><label><input type="checkbox" name="product" value="1"' . checked( $c['product'], true, false ) . '> 商品弹窗：播报下方、主图上方的紧凑分格倒计时</label></p><p>未开始时显示距离开始的时间；结束、停用或无截止时间时自动隐藏。此设置不修改商品、购物车和付款。</p></section>';
		submit_button( '保存设置' ); echo '</form></div>';
	}
}
