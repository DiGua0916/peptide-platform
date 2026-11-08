<?php
/** Site-local draft/public snapshots with optimistic, atomic option updates. */
namespace PeptideCore\Activity;

defined( 'ABSPATH' ) || exit;

final class Store {

	public const OPTION = 'pc_activity_listener_v1';
	public const CONFIG_ID = 'default';
	public const VERSION = 1;
	private const MAX_RECORD_BYTES = 32 * 1024;

	public static function can_manage(): bool {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_woocommerce' ) ) { return false; }
		$id = get_current_user_id();
		return ! \PeptideCore\Staff::is_staff( $id ) || in_array( 'apps', \PeptideCore\Staff::granted( $id ), true );
	}

	/** Reading an unconfigured application does not write defaults to the database. */
	private static function initial(): array {
		$published = Config::defaults();
		$published['enabled'] = false;
		return array(
			'version' => self::VERSION, 'revision' => 0,
			'draft' => array( 'config' => Config::defaults(), 'updatedAt' => null ),
			'published' => array( 'config' => $published, 'revision' => 0, 'updatedAt' => null ),
		);
	}

	/** Fresh database read: never use an object-cache value as the CAS source. */
	private static function record() {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", self::OPTION ) );
		if ( $wpdb->last_error ) { return self::unavailable(); }
		if ( null === $raw ) { return array( 'raw' => null, 'state' => self::initial() ); }
		if ( ! is_string( $raw ) || strlen( $raw ) > self::MAX_RECORD_BYTES ) { return self::unavailable(); }
		$state = json_decode( $raw, true, 16 );
		if ( ! is_array( $state ) || self::VERSION !== ( $state['version'] ?? null ) || ! is_int( $state['revision'] ?? null ) || $state['revision'] < 1 || ! is_array( $state['draft'] ?? null ) || ! is_array( $state['published'] ?? null ) || ! is_int( $state['published']['revision'] ?? null ) || $state['published']['revision'] < 0 || $state['published']['revision'] > $state['revision'] ) { return self::unavailable(); }
		foreach ( array( 'draft', 'published' ) as $snapshot ) {
			$config = Config::normalize( $state[ $snapshot ]['config'] ?? null );
			if ( is_wp_error( $config ) || ! array_key_exists( 'updatedAt', $state[ $snapshot ] ) || ( null !== $state[ $snapshot ]['updatedAt'] && ( ! is_string( $state[ $snapshot ]['updatedAt'] ) || ! preg_match( '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $state[ $snapshot ]['updatedAt'] ) ) ) ) { return self::unavailable(); }
			$state[ $snapshot ]['config'] = $config;
		}
		return array( 'raw' => $raw, 'state' => $state );
	}

	/** Private management DTO; authorization is also enforced by this store. */
	public static function admin() {
		if ( ! self::can_manage() ) { return self::forbidden(); }
		$record = self::record();
		return is_wp_error( $record ) ? $record : self::admin_dto( $record['state'] );
	}

	private static function admin_dto( array $state ): array {
		return array(
			'configId' => self::CONFIG_ID, 'revision' => $state['revision'],
			'draft' => $state['draft'], 'published' => $state['published'],
			'defaults' => Config::defaults(),
			'publicUrl' => rest_url( 'peptide/v1/activity-listener' ),
			'choices' => Config::choices( $state['draft']['config']['promotionId'] ),
			'couponChoices' => Recommendation_Coupon::choices( $state['draft']['config']['recommendationCoupon']['couponId'] ),
		);
	}

	/** Public data only; no draft, editor identity or request-selected site identifier. */
	public static function public_config() {
		$record = self::record();
		if ( is_wp_error( $record ) ) { return $record; }
		$published = $record['state']['published'];
		$config = $published['config'];

		return array_merge( array( 'configId' => self::CONFIG_ID, 'revision' => $published['revision'], 'updatedAt' => $published['updatedAt'] ), $config );
	}

	/** SSR fails closed without making a failed API read look like successful publication. */
	public static function effective(): array {
		$config = self::public_config();
		if ( ! is_wp_error( $config ) ) { return $config; }
		return array_merge( array( 'configId' => self::CONFIG_ID, 'revision' => 0, 'updatedAt' => null ), self::initial()['published']['config'] );
	}

	/** 总后台“插件管理”的开关（2026-11-08）：以当前已应用的设置为准，只改 enabled 并立即应用；校验与普通保存相同。 */
	public static function set_enabled( bool $on ) {
		$record = self::record();
		if ( is_wp_error( $record ) ) { return $record; }
		$config = $record['state']['published']['config'];
		if ( ! is_array( $config ) ) { return self::unavailable(); }
		$config['enabled'] = $on;
		return self::save( $config, (int) $record['state']['revision'], true );
	}

	/** Save draft, or atomically save and apply. Invalid/stale requests write nothing. */
	public static function save( $input, int $expected_revision, bool $publish = false ) {
		if ( ! self::can_manage() ) { return self::forbidden(); }
		$config = Config::normalize( $input );
		if ( is_wp_error( $config ) ) { return $config; }
		if ( $publish && $config['enabled'] ) {
			$selection = Config::validate_selection( $config['promotionId'] );
			if ( is_wp_error( $selection ) ) { return $selection; }
			if ( $config['recommendationCoupon']['enabled'] && ! Recommendation_Coupon::coupon( $config ) ) { return Config::error( 'recommendationCoupon.couponId', 'unavailable_coupon', '请选择可用的已有优惠券；此版本支持叠加满减的非定向金额或折扣券，不支持免运、首单或客户分组券。' ); }
		}
		$record = self::record();
		if ( is_wp_error( $record ) ) { return $record; }
		$state = $record['state'];
		if ( $expected_revision !== $state['revision'] ) { return self::conflict(); }
		if ( $state['revision'] >= 2147483646 ) { return self::unavailable(); }
		$state['revision']++;
		$now = gmdate( 'Y-m-d\TH:i:s\Z' );
		$state['draft'] = array( 'config' => $config, 'updatedAt' => $now );
		// An unchanged public configuration preserves the customer's milestone baseline.
		if ( $publish && $config !== $state['published']['config'] ) {
			$state['published'] = array( 'config' => $config, 'revision' => $state['revision'], 'updatedAt' => $now );
		}
		$encoded = wp_json_encode( $state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $encoded ) || strlen( $encoded ) > self::MAX_RECORD_BYTES ) { return self::unavailable(); }
		global $wpdb;
		if ( null === $record['raw'] ) {
			$changed = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s,%s,'no')", self::OPTION, $encoded ) );
		} else {
			$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value=%s, autoload='no' WHERE option_name=%s AND BINARY option_value=BINARY %s", $encoded, self::OPTION, $record['raw'] ) );
		}
		if ( false === $changed || $wpdb->last_error ) { return self::unavailable(); }
		if ( 1 !== $changed ) { return self::conflict(); }
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		return self::admin_dto( $state );
	}

	private static function conflict(): \WP_Error {
		return new \WP_Error( 'pc_activity_listener_conflict', __( '配置已被其他窗口修改。请先导出当前草稿，再重新加载后保存。', 'peptide-core' ), array( 'status' => 409 ) );
	}
	private static function forbidden(): \WP_Error {
		return new \WP_Error( 'pc_activity_listener_forbidden', __( '此账号没有应用配置权限。', 'peptide-core' ), array( 'status' => rest_authorization_required_code() ) );
	}
	private static function unavailable(): \WP_Error {
		return new \WP_Error( 'pc_activity_listener_unavailable', __( '暂时无法读取或保存活动监听，请稍后重试。', 'peptide-core' ), array( 'status' => 503 ) );
	}
}
