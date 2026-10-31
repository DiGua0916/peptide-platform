<?php
/**
 * Account-specific staff login entry. The public opaque identifier is not a login token:
 * WordPress still verifies the password and all other authentication filters.
 *
 * @package PeptideCore
 */
namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Staff_Entry {

	public const ARG  = 'pc_staff_entry';
	public const META = 'pc_staff_entry_id';

	public static function register_hooks(): void {
		add_action( 'login_form', array( self::class, 'hidden_field' ) );
		add_action( 'login_init', array( self::class, 'protect_entry' ) );
		// A successful password/other authenticator cannot bypass the entry binding. Earlier
		// errors (including CAPTCHA and 2FA challenges) remain errors, never converted to a user.
		add_filter( 'authenticate', array( self::class, 'authenticate' ), PHP_INT_MAX, 3 );
	}

	/** Called only by the existing authenticated HQ staff DTO. Never include a password. */
	public static function url( int $user_id ): string {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User || ! Staff::is_staff( $user ) || Staff::disabled( $user_id ) ) {
			return '';
		}
		$uuid = Hq_Connector::uuid();
		if ( ! self::valid_uuid( $uuid ) ) { return ''; }
		$id = self::identifier( $user_id, $uuid );
		if ( get_user_meta( $user_id, self::META, true ) !== $id ) {
			update_user_meta( $user_id, self::META, $id );
		}
		if ( get_user_meta( $user_id, self::META, true ) !== $id ) { return ''; }
		return add_query_arg( self::ARG, $id, site_url( 'wp-login.php', 'login' ) );
	}

	private static function valid_uuid( string $uuid ): bool {
		return 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $uuid );
	}

	/** Stable across a domain migration; a clone must have a different store UUID. */
	private static function identifier( int $user_id, string $uuid ): string {
		return hash_hmac( 'sha256', "pc-staff-entry-v1\n" . $uuid . "\n" . $user_id, wp_salt( 'auth' ) );
	}

	/**
	 * Preserve whether an entry was supplied, including malformed/empty values.
	 * GET/POST disagreement is invalid rather than choosing an attacker-controlled winner.
	 *
	 * @return array{present:bool,id:string}
	 */
	private static function request(): array {
		$get = array_key_exists( self::ARG, $_GET );
		$post = array_key_exists( self::ARG, $_POST );
		if ( ! $get && ! $post ) { return array( 'present' => false, 'id' => '' ); }
		$values = array();
		foreach ( array( $get ? $_GET[ self::ARG ] : null, $post ? $_POST[ self::ARG ] : null ) as $value ) {
			if ( null === $value ) { continue; }
			if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
				return array( 'present' => true, 'id' => '' );
			}
			$values[] = $value;
		}
		if ( ! $values || ( 2 === count( $values ) && ! hash_equals( $values[0], $values[1] ) ) ) {
			return array( 'present' => true, 'id' => '' );
		}
		return array( 'present' => true, 'id' => $values[0] );
	}

	private static function owner( string $id ): int {
		if ( '' === $id ) { return 0; }
		$uuid = Hq_Connector::settings()['uuid'];
		if ( ! self::valid_uuid( $uuid ) ) { return 0; }
		$users = array_values( array_unique( array_map( 'intval', get_users( array(
			'role' => Staff::ROLE, 'fields' => 'ID', 'number' => 2, 'count_total' => false,
			'meta_key' => self::META, 'meta_value' => $id,
		) ) ) ) );
		if ( 1 !== count( $users ) ) { return 0; }
		$user = get_userdata( $users[0] );
		return $user instanceof \WP_User && Staff::is_staff( $user ) && ! Staff::disabled( $user->ID )
			&& hash_equals( self::identifier( $user->ID, $uuid ), $id ) ? $user->ID : 0;
	}

	/** Retain the entry when WordPress posts its form to wp-login.php without the query. */
	public static function hidden_field(): void {
		$entry = self::request();
		if ( ! $entry['present'] ) { return; }
		printf( '<input type="hidden" name="%1$s" value="%2$s">', esc_attr( self::ARG ), esc_attr( '' !== $entry['id'] ? $entry['id'] : 'invalid' ) );
	}

	public static function protect_entry(): void {
		if ( ! self::request()['present'] ) { return; }
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Robots-Tag: noindex, nofollow' );
	}

	public static function authenticate( $user, $username = '', $password = '' ) {
		if ( ! $user instanceof \WP_User ) { return $user; }
		$entry = self::request();
		if ( ! $entry['present'] && ! Staff::is_staff( $user ) ) { return $user; }
		global $pagenow;
		if ( $entry['present'] && 'wp-login.php' === $pagenow && Staff::is_staff( $user )
			&& ! Staff::disabled( $user->ID ) && self::owner( $entry['id'] ) === $user->ID ) {
			return $user;
		}
		return new \WP_Error( 'pc_staff_entry_denied', __( 'Unable to sign in. Check your assigned login entry and credentials, or contact the administrator.', 'peptide-core' ) );
	}
}
