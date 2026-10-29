<?php
/** Customer tags and membership levels use the existing WordPress customer identity. */
namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Customer_Segments {

	public const OPTION = 'peptide_core_customer_segments';
	public const TAGS = '_pc_customer_tags';
	public const LEVEL = '_pc_customer_level';
	public const COUPON_META = '_pc_customer_rule';

	public static function register_hooks(): void {}

	public static function catalog(): array {
		$value = get_option( self::OPTION, array() );
		return array( 'tags' => (array) ( $value['tags'] ?? array() ), 'levels' => (array) ( $value['levels'] ?? array() ) );
	}

	/** Slugs are stable identifiers; removing one immediately stops its matching rules. */
	public static function save_catalog( array $raw ) {
		$out = array();
		foreach ( array( 'tags', 'levels' ) as $kind ) {
			$out[ $kind ] = array();
			foreach ( (array) ( $raw[ $kind ] ?? array() ) as $slug => $label ) {
				$key = sanitize_key( (string) $slug );
				$name = sanitize_text_field( (string) $label );
				if ( ! $key || strlen( $key ) > 40 || ! $name || strlen( $name ) > 180 || count( $out[ $kind ] ) >= 100 ) {
					return new \WP_Error( 'pc_segments_invalid', '标签和等级需有唯一的英文标识及名称，每类最多100项。' );
				}
				$out[ $kind ][ $key ] = $name;
			}
		}
		update_option( self::OPTION, $out, false );
		return $out;
	}

	public static function profile( int $user_id ): array {
		$catalog = self::catalog();
		$tags = array_values( array_intersect( (array) get_user_meta( $user_id, self::TAGS, true ), array_keys( $catalog['tags'] ) ) );
		$level = (string) get_user_meta( $user_id, self::LEVEL, true );
		return array( 'tags' => $tags, 'level' => isset( $catalog['levels'][ $level ] ) ? $level : '' );
	}

	public static function is_customer( int $user_id ): bool {
		$user = get_userdata( $user_id );
		return $user && in_array( 'customer', (array) $user->roles, true ) && ! user_can( $user, 'manage_woocommerce' ) && ! user_can( $user, 'manage_options' );
	}

	public static function set_profile( int $user_id, array $tags, string $level ) {
		if ( ! self::is_customer( $user_id ) ) {
			return new \WP_Error( 'pc_customer_invalid', '请选择现有的 WooCommerce 顾客账户。' );
		}
		$catalog = self::catalog();
		$tags = array_values( array_unique( array_map( 'sanitize_key', $tags ) ) );
		$level = sanitize_key( $level );
		if ( array_diff( $tags, array_keys( $catalog['tags'] ) ) || ( '' !== $level && ! isset( $catalog['levels'][ $level ] ) ) ) {
			return new \WP_Error( 'pc_segments_unknown', '标签或等级已不存在，请刷新后重试。' );
		}
		update_user_meta( $user_id, self::TAGS, $tags );
		update_user_meta( $user_id, self::LEVEL, $level );
		do_action( 'peptide_core_customer_segments_changed', $user_id, $tags, $level );
		return self::profile( $user_id );
	}

	public static function defaults(): array {
		return array( 'customers' => 'all', 'emails' => array(), 'segment_tags' => array(), 'segment_levels' => array(), 'visitor_ids' => array() );
	}

	/** The same rule shape is persisted by coupons, promotions and shipping discounts. */
	public static function clean_rule( array $raw ): array {
		$r = self::defaults();
		$errors = array();
		$mode = (string) ( $raw['customers'] ?? 'all' );
		$r['customers'] = in_array( $mode, array( 'all', 'emails', 'tags', 'levels', 'visitor' ), true ) ? $mode : 'all';
		$emails = $raw['emails'] ?? array();
		$emails = is_array( $emails ) ? $emails : preg_split( '/[\s,;]+/', (string) $emails );
		$r['emails'] = array_values( array_unique( array_filter( array_map( static fn( $v ) => strtolower( sanitize_email( (string) $v ) ), $emails ), 'is_email' ) ) );
		$catalog = self::catalog();
		foreach ( array( 'segment_tags' => 'tags', 'segment_levels' => 'levels' ) as $key => $kind ) {
			$r[ $key ] = array_values( array_intersect( array_unique( array_map( 'sanitize_key', (array) ( $raw[ $key ] ?? array() ) ) ), array_keys( $catalog[ $kind ] ) ) );
		}
		$ids = $raw['visitor_ids'] ?? array();
		$ids = is_array( $ids ) ? $ids : preg_split( '/[\s,;]+/', (string) $ids );
		$r['visitor_ids'] = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		$needed = array( 'emails' => 'emails', 'tags' => 'segment_tags', 'levels' => 'segment_levels', 'visitor' => 'visitor_ids' );
		if ( isset( $needed[ $r['customers'] ] ) && ! $r[ $needed[ $r['customers'] ] ] ) {
			$errors[] = '请选择至少一个适用顾客条件。';
		}
		return array( $r, $errors );
	}

	/** Never accept a numeric visitor ID from query/form data. Resolve the opaque tracking cookie server-side. */
	public static function current_visitor_id(): int {
		if ( ! class_exists( Tracking\Tracker::class ) || ! Tracking\Tracker::enabled() || Tracking\Tracker::declined() ) {
			return 0;
		}
		$vid = Tracking\Tracker::current_vid();
		if ( '' === $vid ) {
			return 0;
		}
		global $wpdb;
		$table = Tracking\Schema::table( 'visitors' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE vid = %s AND is_test = 0", $vid ) );
	}

	public static function matches( array $rule, string $email = '', ?int $user_id = null ): bool {
		$mode = $rule['customers'] ?? 'all';
		if ( 'all' === $mode ) {
			return true;
		}
		if ( 'emails' === $mode ) {
			return '' !== $email && in_array( strtolower( trim( $email ) ), (array) ( $rule['emails'] ?? array() ), true );
		}
		if ( 'visitor' === $mode ) {
			$id = self::current_visitor_id();
			return $id > 0 && in_array( $id, array_map( 'intval', (array) ( $rule['visitor_ids'] ?? array() ) ), true );
		}
		$user_id = $user_id ?? get_current_user_id();
		if ( ! $user_id ) {
			return false; // A checkout email must never impersonate an account's level or tags.
		}
		$p = self::profile( $user_id );
		return 'tags' === $mode ? (bool) array_intersect( $p['tags'], (array) ( $rule['segment_tags'] ?? array() ) ) : ( 'levels' === $mode && '' !== $p['level'] && in_array( $p['level'], (array) ( $rule['segment_levels'] ?? array() ), true ) );
	}

	public static function current_email(): string {
		$email = function_exists( 'WC' ) && WC()->customer ? (string) WC()->customer->get_billing_email() : '';
		return '' !== $email ? strtolower( $email ) : ( is_user_logged_in() ? strtolower( (string) wp_get_current_user()->user_email ) : '' );
	}
}
