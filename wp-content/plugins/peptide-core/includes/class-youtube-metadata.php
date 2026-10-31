<?php
/**
 * O04 public-video metadata. No credentials or provider HTML enter module attributes.
 *
 * @package PeptideCore
 */

namespace PeptideCore;

use PeptideCore\Api\Rest_Controller;
use PeptideCore\Modules\Layout;
use PeptideCore\Modules\Registry;

defined( 'ABSPATH' ) || exit;

final class YouTube_Metadata {
	public const OPTION = 'peptide_core_youtube_metadata';
	public const CRON = 'peptide_core_youtube_metadata_refresh';
	private const REGISTRY = 'peptide_core_youtube_metadata_registry';
	private const INDEX = 'peptide_core_youtube_metadata_index';
	private const BUDGET = 'peptide_core_youtube_metadata_budget';
	private const LOCK = 'peptide_core_youtube_metadata_lock';
	private const FRESH = 21600;
	private const HARD = 2419200;
	private const MAX_IDS = 12;
	private const MAX_CACHE = 2048;
	private const MAX_DOCS = 256;
	private const MAX_INDEX_IDS = 2048;

	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_action( 'peptide_core_layout_published', array( self::class, 'index_document' ) );
		add_action( 'before_delete_post', array( self::class, 'remove_document' ) );
		add_action( 'deleted_post', array( self::class, 'remove_document' ) );
		add_action( 'trashed_post', array( self::class, 'remove_document' ) );
		add_action( 'untrashed_post', array( self::class, 'index_document' ) );
		add_action( self::CRON, array( self::class, 'refresh_published' ) );
	}

	public static function register_routes(): void {
		register_rest_route( Rest_Controller::ROUTE_NAMESPACE, '/layouts/(?P<id>\d+)/youtube-metadata', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( self::class, 'request' ),
			'permission_callback' => array( self::class, 'can_edit' ),
			'args' => array(
				'id' => array( 'type' => 'integer', 'required' => true ),
				'videoIds' => array( 'type' => 'array', 'required' => true, 'maxItems' => self::MAX_IDS, 'items' => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{11}$' ) ),
				'force' => array( 'type' => 'boolean', 'default' => false ),
			),
		) );
	}

	public static function can_edit( \WP_REST_Request $request ) {
		if ( ! is_user_logged_in() || ! wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) || ! current_user_can( Templates::CAP_EDIT ) ) {
			return new \WP_Error( 'pc_youtube_forbidden', __( 'You are not allowed to read video information.', 'peptide-core' ), array( 'status' => 403 ) );
		}
		$id = (int) $request['id'];
		if ( Templates::LAYOUT !== get_post_type( $id ) || 'trash' === get_post_status( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			return new \WP_Error( 'pc_youtube_document_forbidden', __( 'You cannot edit this design document.', 'peptide-core' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public static function request( \WP_REST_Request $request ) {
		$allowed = self::can_edit( $request );
		if ( is_wp_error( $allowed ) ) { return $allowed; }
		$ids = $request->get_param( 'videoIds' );
		if ( ! is_array( $ids ) || count( $ids ) > self::MAX_IDS || array_filter( $ids, static fn( $id ) => ! is_string( $id ) || ! self::valid_id( $id ) ) ) {
			return new \WP_Error( 'pc_youtube_invalid_ids', __( 'Use valid YouTube video links.', 'peptide-core' ), array( 'status' => 400 ) );
		}
		return rest_ensure_response( self::resolve( array_values( array_unique( $ids ) ), true === $request->get_param( 'force' ), get_current_user_id() ) );
	}

	public static function config(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return array(
			'provider' => in_array( $saved['provider'] ?? '', array( 'public_oembed', 'data_api', 'manual' ), true ) ? $saved['provider'] : 'public_oembed',
			'daily_request_limit' => max( 1, min( 10000, (int) ( $saved['daily_request_limit'] ?? 100 ) ) ),
		);
	}

	/** Non-secret settings only; unknown fields, including key/token, are dropped. */
	public static function save_config( array $input ): bool {
		if ( ! current_user_can( 'manage_options' ) ) { return false; }
		$provider = in_array( $input['provider'] ?? '', array( 'public_oembed', 'data_api', 'manual' ), true ) ? $input['provider'] : 'public_oembed';
		update_option( self::OPTION, array( 'provider' => $provider, 'daily_request_limit' => max( 1, min( 10000, (int) ( $input['daily_request_limit'] ?? 100 ) ) ) ), false );
		self::schedule();
		return true;
	}

	/** Only authorized editor bootstrap may receive this DTO. */
	public static function editor_config(): array {
		if ( ! is_user_logged_in() || ! current_user_can( Templates::CAP_EDIT ) ) { return array(); }
		$config = self::config();
		return array(
			'url' => rest_url( Rest_Controller::ROUTE_NAMESPACE . '/layouts/' ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'provider' => $config['provider'],
			'configured' => 'data_api' !== $config['provider'] || '' !== self::api_key(),
		);
	}

	/** Admin status exposes existence, never a value or key fingerprint. */
	public static function admin_status(): array {
		if ( ! current_user_can( 'manage_options' ) ) { return array(); }
		$error = get_transient( 'pc_ytm_index_error' );
		return array( 'configured' => '' !== self::api_key(), 'scheduled' => (bool) wp_next_scheduled( self::CRON ), 'recentIndexError' => is_array( $error ) ? ( $error['reason'] ?? '' ) : '' );
	}

	private static function api_key(): string {
		if ( defined( 'PEPTIDE_YOUTUBE_API_KEY' ) ) {
			$value = constant( 'PEPTIDE_YOUTUBE_API_KEY' );
		} else {
			$value = getenv( 'PEPTIDE_YOUTUBE_API_KEY' );
		}
		return is_string( $value ) && strlen( $value ) <= 256 && ! preg_match( '/[\x00-\x20\x7f]/', $value ) ? $value : '';
	}

	private static function valid_id( $id ): bool {
		return is_string( $id ) && 1 === preg_match( '/^[A-Za-z0-9_-]{11}$/D', $id );
	}

	/** The existing reference parser moves here; the old legacy parser stays untouched. */
	public static function video_id( string $url ): ?string {
		if ( '' === $url || strlen( $url ) > 2048 || preg_match( '/[\x00-\x20\x7f]/', $url ) ) { return null; }
		$p = wp_parse_url( $url );
		if ( ! is_array( $p ) || 'https' !== strtolower( $p['scheme'] ?? '' ) || isset( $p['user'] ) || isset( $p['pass'] ) || ( isset( $p['port'] ) && 443 !== (int) $p['port'] ) ) { return null; }
		$host = strtolower( $p['host'] ?? '' ); $path = $p['path'] ?? ''; $id = null;
		if ( 'youtu.be' === $host ) { if ( preg_match( '~^/([A-Za-z0-9_-]{11})/?$~D', $path, $m ) ) { $id = $m[1]; } }
		elseif ( in_array( $host, array( 'youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com' ), true ) ) {
			if ( '/watch' === $path && ! str_contains( $host, 'nocookie' ) ) {
				$v = array(); foreach ( explode( '&', $p['query'] ?? '' ) as $part ) { $pair = explode( '=', $part, 2 ); $key = rawurldecode( $pair[0] ); if ( str_contains( $key, '[' ) || str_contains( $key, ']' ) ) { return null; } if ( 'v' === $key ) { $v[] = rawurldecode( $pair[1] ?? '' ); } }
				if ( 1 === count( $v ) && self::valid_id( $v[0] ) ) { $id = $v[0]; }
			} elseif ( preg_match( '~^/(embed|shorts|live)/([A-Za-z0-9_-]{11})/?$~D', $path, $m ) && ( ! str_contains( $host, 'nocookie' ) || 'embed' === $m[1] ) ) { $id = $m[2]; }
		}
		return $id;
	}

	private static function cache_key( string $provider, string $id ): string {
		return 'pc_ytm_' . hash( 'sha256', 'v1:' . $provider . ':' . $id );
	}

	private static function blank( string $id, string $status, int $retry = 0 ): array {
		return array( 'videoId' => $id, 'title' => '', 'authorName' => '', 'thumbnailUrl' => '', 'status' => $status, 'fetchedAt' => 0, 'stale' => false, 'retryAfter' => max( 0, $retry ) );
	}

	/** Cache-only public projection. It never reads a key or performs an HTTP request. */
	public static function cached( string $id ): array {
		if ( ! self::valid_id( $id ) ) { return self::blank( '', 'invalid_url' ); }
		$provider = self::config()['provider'];
		if ( 'manual' === $provider ) { return self::blank( $id, 'manual' ); }
		$entry = get_transient( self::cache_key( $provider, $id ) );
		if ( ! is_array( $entry ) || ! is_array( $entry['data'] ?? null ) ) { return self::blank( $id, 'missing' ); }
		$data = $entry['data'];
		if ( ! empty( $data['fetchedAt'] ) && (int) $data['fetchedAt'] + self::HARD <= time() ) { return self::blank( $id, 'expired' ); }
		$data['stale'] = ! empty( $data['fetchedAt'] ) && ( ! in_array( $data['status'] ?? '', array( 'success', 'partial' ), true ) || (int) $data['fetchedAt'] + self::FRESH <= time() );
		return array_intersect_key( $data, self::blank( $id, 'missing' ) );
	}

	private static function fresh( string $provider, string $id ): bool {
		$entry = get_transient( self::cache_key( $provider, $id ) );
		return is_array( $entry ) && (int) ( $entry['retryAt'] ?? 0 ) > time();
	}

	private static function needs_refresh( string $provider, string $id, bool $force ): bool {
		if ( ! $force ) { return ! self::fresh( $provider, $id ); }
		$entry = get_transient( self::cache_key( $provider, $id ) );
		return ! is_array( $entry ) || (int) ( $entry['attemptedAt'] ?? 0 ) + 5 <= time();
	}

	/** Admin tests require an explicitly supplied public URL. */
	public static function test( string $url ) {
		if ( ! current_user_can( 'manage_options' ) ) { return new \WP_Error( 'pc_youtube_forbidden', 'forbidden' ); }
		$id = self::video_id( $url );
		return $id ? self::resolve( array( $id ), true, get_current_user_id() ) : new \WP_Error( 'pc_youtube_invalid_url', 'invalid_url' );
	}

	private static function lock(): ?string {
		$token = wp_generate_uuid4();
		$old = get_option( self::LOCK, null );
		if ( null !== $old && ( ! is_array( $old ) || (int) ( $old['until'] ?? 0 ) < time() ) ) { self::cas( self::LOCK, $old, null ); }
		return add_option( self::LOCK, array( 'token' => $token, 'until' => time() + 30 ), '', false ) ? $token : null;
	}

	private static function unlock( string $token ): void {
		$old = get_option( self::LOCK, array() );
		if ( is_array( $old ) && ( $old['token'] ?? '' ) === $token ) { self::cas( self::LOCK, $old, null ); }
	}

	/**
	 * Bounded compare-and-swap for our two fixed service options only.
	 * Exact old serialized bytes prevent an expired owner deleting a newer lock.
	 */
	private static function cas( string $name, $before, ?array $after ): bool {
		if ( ! in_array( $name, array( self::INDEX, self::LOCK ), true ) ) { return false; }
		if ( null === $before ) { return null !== $after && add_option( $name, $after, '', false ); }
		global $wpdb;
		$sql = null === $after
			? $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND BINARY option_value = %s", $name, maybe_serialize( $before ) )
			: $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = %s", maybe_serialize( $after ), $name, maybe_serialize( $before ) );
		$changed = $wpdb->query( $sql );
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return 1 === $changed;
	}

	/** Called under the single short service lock; no per-IP/customer tracking. */
	private static function reserve( int $user, int $calls ): bool {
		$now = time(); $day = gmdate( 'Y-m-d', $now ); $state = get_option( self::BUDGET, array() );
		$state = is_array( $state ) && ( $state['day'] ?? '' ) === $day ? $state : array( 'day' => $day, 'calls' => 0, 'users' => array() );
		$users = is_array( $state['users'] ?? null ) ? $state['users'] : array();
		foreach ( $users as $id => $row ) { if ( ! is_array( $row ) || (int) ( $row['at'] ?? 0 ) + 60 <= $now ) { unset( $users[ $id ] ); } }
		$row = $users[ $user ] ?? array( 'at' => $now, 'count' => 0 );
		if ( ( $user && (int) $row['count'] >= 20 ) || (int) $state['calls'] + $calls > self::config()['daily_request_limit'] ) { return false; }
		if ( $user && ! isset( $users[ $user ] ) && count( $users ) >= 128 ) { return false; }
		if ( $user ) { $row['count']++; $users[ $user ] = $row; }
		update_option( self::BUDGET, array( 'day' => $day, 'calls' => (int) $state['calls'] + $calls, 'users' => $users ), false );
		return true;
	}

	private static function available_calls(): int {
		$state = get_option( self::BUDGET, array() );
		$spent = is_array( $state ) && ( $state['day'] ?? '' ) === gmdate( 'Y-m-d', time() ) ? (int) ( $state['calls'] ?? 0 ) : 0;
		return max( 0, self::config()['daily_request_limit'] - $spent );
	}

	private static function next_day(): int {
		return 86400 - ( (int) gmdate( 'H', time() ) * 3600 + (int) gmdate( 'i', time() ) * 60 + (int) gmdate( 's', time() ) );
	}

	/** Private entry point: callers have already authenticated, or are the bounded cron hook. */
	private static function resolve( array $ids, bool $force, int $user ): array {
		$config = self::config(); $provider = $config['provider']; $rows = array(); $pending = array(); $need = array();
		foreach ( array_slice( $ids, 0, self::MAX_IDS ) as $id ) {
			if ( ! self::valid_id( $id ) ) { continue; }
			if ( 'manual' === $provider ) { $rows[ $id ] = self::blank( $id, 'manual' ); continue; }
			if ( 'data_api' === $provider && '' === self::api_key() ) { $rows[ $id ] = self::blank( $id, 'no_key' ); continue; }
			$rows[ $id ] = self::cached( $id );
			$entry = get_transient( self::cache_key( $provider, $id ) );
			$cooldown = is_array( $entry ) ? max( 0, (int) ( $entry['attemptedAt'] ?? 0 ) + 5 - time() ) : 0;
			if ( $force && $cooldown ) { $rows[ $id ]['retryAfter'] = $cooldown; }
			elseif ( $force || ! self::fresh( $provider, $id ) ) { $need[] = $id; }
		}
		if ( ! $need ) { return array( 'rows' => array_values( $rows ), 'pendingIds' => array(), 'provider' => $provider ); }
		$token = self::lock();
		if ( ! $token ) {
			foreach ( $need as $id ) { $rows[ $id ]['status'] = 'busy'; $rows[ $id ]['retryAfter'] = 2; }
			return array( 'rows' => array_values( $rows ), 'pendingIds' => array(), 'provider' => $provider );
		}
		try {
			// Recheck after acquiring the global lock to merge concurrent editor/cron requests.
			$need = array_values( array_filter( $need, static fn( $id ) => self::needs_refresh( $provider, $id, $force ) ) );
			foreach ( array_keys( $rows ) as $id ) { $rows[ $id ] = self::cached( $id ); }
			$batch = 'public_oembed' === $provider ? array_slice( $need, 0, min( 2, self::available_calls() ) ) : $need;
			$pending = array_values( array_diff( $need, $batch ) );
			if ( $need && ( ! $batch || ! self::reserve( $user, 'data_api' === $provider ? 1 : count( $batch ) ) ) ) {
				foreach ( $need as $id ) { $rows[ $id ]['status'] = 'local_limit'; $rows[ $id ]['retryAfter'] = self::available_calls() ? 60 : self::next_day(); }
				$pending = array();
			} elseif ( $batch ) {
				$fetched = 'data_api' === $provider ? self::fetch_api( $batch ) : self::fetch_oembed( $batch );
				foreach ( $fetched as $id => $data ) { self::store( $provider, $id, $data ); }
				foreach ( $need as $id ) { $rows[ $id ] = self::cached( $id ); }
				foreach ( $pending as $id ) { $rows[ $id ]['status'] = 'queued'; }
			}
		} finally { self::unlock( $token ); }
		return array( 'rows' => array_values( $rows ), 'pendingIds' => $pending, 'provider' => $provider );
	}

	private static function http( string $url, array $headers = array() ) {
		$response = wp_safe_remote_get( $url, array( 'timeout' => 3, 'redirection' => 0, 'limit_response_size' => 131072, 'sslverify' => true, 'headers' => array_merge( array( 'Accept' => 'application/json' ), $headers ) ) );
		if ( is_wp_error( $response ) ) { return new \WP_Error( 'network', 'network' ); }
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) || strlen( $body ) >= 131072 ) { return new \WP_Error( 'invalid_response', 'invalid_response' ); }
		$json = json_decode( $body, true, 16 );
		if ( ! is_array( $json ) && 200 !== $code ) { $json = array(); }
		if ( ! is_array( $json ) ) { return new \WP_Error( 'invalid_response', 'invalid_response' ); }
		return array( 'code' => $code, 'json' => $json );
	}

	private static function text( $value ): string {
		if ( ! is_string( $value ) ) { return ''; }
		$value = sanitize_text_field( $value );
		return preg_match( '/^.{0,300}/us', $value, $match ) ? $match[0] : '';
	}

	private static function thumbnail( $value, string $id ): string {
		if ( ! is_string( $value ) || strlen( $value ) > 2048 || preg_match( '/[\x00-\x20\x7f]/', $value ) ) { return ''; }
		$p = wp_parse_url( $value );
		if ( ! is_array( $p ) || 'https' !== ( $p['scheme'] ?? '' ) || 'i.ytimg.com' !== strtolower( $p['host'] ?? '' ) || isset( $p['user'] ) || isset( $p['pass'] ) || isset( $p['port'] ) || isset( $p['fragment'] ) ) { return ''; }
		return preg_match( '~^/(?:vi|vi_webp)/' . preg_quote( $id, '~' ) . '/[A-Za-z0-9_-]{1,64}\.(?:jpg|jpeg|webp|png)$~D', $p['path'] ?? '' ) ? $value : '';
	}

	private static function data( string $id, $title, $author, $thumbnail ): array {
		$data = self::blank( $id, 'success' );
		$data['title'] = self::text( $title ); $data['authorName'] = self::text( $author ); $data['thumbnailUrl'] = self::thumbnail( $thumbnail, $id ); $data['fetchedAt'] = time();
		if ( '' === $data['title'] && '' === $data['thumbnailUrl'] && '' === $data['authorName'] ) { return self::blank( $id, 'invalid_response', 60 ); }
		if ( '' === $data['title'] || '' === $data['thumbnailUrl'] || '' === $data['authorName'] ) { $data['status'] = 'partial'; }
		return $data;
	}

	private static function fetch_oembed( array $ids ): array {
		$out = array();
		foreach ( $ids as $id ) {
			$url = add_query_arg( array( 'url' => 'https://www.youtube.com/watch?v=' . $id, 'format' => 'json' ), 'https://www.youtube.com/oembed' );
			$result = self::http( $url );
			if ( is_wp_error( $result ) ) { $out[ $id ] = self::blank( $id, $result->get_error_code(), 60 ); continue; }
			$code = $result['code']; $json = $result['json'];
			if ( 200 !== $code ) { $out[ $id ] = self::blank( $id, in_array( $code, array( 401, 404 ), true ) ? 'unavailable' : ( 403 === $code ? 'denied' : ( 429 === $code ? 'quota' : 'upstream' ) ), 60 ); continue; }
			if ( 'video' !== ( $json['type'] ?? '' ) || '1.0' !== (string) ( $json['version'] ?? '' ) ) { $out[ $id ] = self::blank( $id, 'invalid_response', 60 ); continue; }
			$out[ $id ] = self::data( $id, $json['title'] ?? '', $json['author_name'] ?? '', $json['thumbnail_url'] ?? '' );
		}
		return $out;
	}

	private static function fetch_api( array $ids ): array {
		$out = array();
		$url = add_query_arg( array( 'part' => 'snippet', 'id' => implode( ',', $ids ), 'fields' => 'items(id,snippet(title,channelTitle,thumbnails))' ), 'https://www.googleapis.com/youtube/v3/videos' );
		$result = self::http( $url, array( 'X-Goog-Api-Key' => self::api_key() ) );
		$status = 'unavailable';
		if ( is_wp_error( $result ) ) { $status = $result->get_error_code(); }
		elseif ( 200 !== $result['code'] ) {
			$reasons = array_column( is_array( $result['json']['error']['errors'] ?? null ) ? $result['json']['error']['errors'] : array(), 'reason' );
			$status = array_intersect( $reasons, array( 'quotaExceeded', 'dailyLimitExceeded' ) ) || 429 === $result['code'] ? 'quota' : ( array_intersect( $reasons, array( 'keyInvalid', 'ipRefererBlocked', 'accessNotConfigured' ) ) ? 'invalid_key' : 'denied' );
		} elseif ( ! is_array( $result['json']['items'] ?? null ) ) { $status = 'invalid_response'; }
		else {
			foreach ( array_slice( $result['json']['items'], 0, self::MAX_IDS ) as $item ) {
				$id = $item['id'] ?? '';
				if ( ! self::valid_id( $id ) || ! in_array( $id, $ids, true ) || ! is_array( $item['snippet'] ?? null ) ) { continue; }
				$s = $item['snippet']; $thumbnail = '';
				foreach ( array( 'maxres', 'standard', 'high', 'medium', 'default' ) as $size ) { $thumbnail = self::thumbnail( $s['thumbnails'][ $size ]['url'] ?? '', $id ); if ( $thumbnail ) { break; } }
				$out[ $id ] = self::data( $id, $s['title'] ?? '', $s['channelTitle'] ?? '', $thumbnail );
			}
		}
		foreach ( $ids as $id ) { if ( ! isset( $out[ $id ] ) ) { $out[ $id ] = self::blank( $id, $status, 60 ); } }
		return $out;
	}

	private static function store( string $provider, string $id, array $data ): void {
		$key = self::cache_key( $provider, $id ); $now = time(); $previous = get_transient( $key ); $ok = in_array( $data['status'], array( 'success', 'partial' ), true );
		$failures = $ok ? 0 : min( 10, (int) ( $previous['failures'] ?? 0 ) + 1 );
		$retry = $ok ? self::FRESH : min( self::FRESH, 60 * ( 2 ** ( $failures - 1 ) ) );
		if ( in_array( $data['status'], array( 'quota', 'invalid_key', 'denied' ), true ) ) { $retry = self::FRESH; }
		if ( ! $ok && 'unavailable' !== $data['status'] && is_array( $previous['data'] ?? null ) && (int) ( $previous['data']['fetchedAt'] ?? 0 ) > $now - self::HARD ) {
			$last = $previous['data']; $last['status'] = $data['status']; $last['retryAfter'] = $retry; $last['stale'] = true; $data = $last;
		}
		if ( ! $ok ) { $data['retryAfter'] = $retry; }
		$remaining = empty( $data['fetchedAt'] ) ? 86400 : max( 1, (int) $data['fetchedAt'] + self::HARD - $now );
		set_transient( $key, array( 'data' => $data, 'retryAt' => $now + $retry, 'attemptedAt' => $now, 'failures' => $failures ), $remaining );
		$registry = get_option( self::REGISTRY, array() ); $registry = is_array( $registry ) ? $registry : array();
		$registry[ $key ] = array( 'provider' => $provider, 'id' => $id, 'at' => $now );
		while ( count( $registry ) > self::MAX_CACHE ) { $first = array_key_first( $registry ); if ( self::registered_key( $first, $registry[ $first ] ) ) { delete_transient( $first ); } unset( $registry[ $first ] ); }
		update_option( self::REGISTRY, $registry, false );
	}

	private static function registered_key( $key, $row ): bool {
		return is_string( $key ) && is_array( $row ) && in_array( $row['provider'] ?? '', array( 'public_oembed', 'data_api' ), true ) && self::valid_id( $row['id'] ?? null ) && $key === self::cache_key( $row['provider'], $row['id'] );
	}

	public static function clear_cache(): bool {
		if ( ! current_user_can( 'manage_options' ) ) { return false; }
		$token = self::lock(); if ( ! $token ) { return false; }
		try {
			$registry = get_option( self::REGISTRY, array() );
			foreach ( is_array( $registry ) ? array_slice( $registry, 0, self::MAX_CACHE, true ) : array() as $key => $row ) { if ( self::registered_key( $key, $row ) ) { delete_transient( $key ); } }
			delete_option( self::REGISTRY );
			// Never reset budget/rate counters via cache clearing.
		} finally { self::unlock( $token ); }
		self::schedule();
		return true;
	}

	/**
	 * Explicit administrator-only historical synchronization: at most 20 existing layouts.
	 * No network request, new layout, revision or indefinite queue is created.
	 */
	public static function sync_batch( int $cursor = 0, int $limit = 20 ) {
		if ( ! current_user_can( 'manage_options' ) ) { return new \WP_Error( 'pc_youtube_forbidden', 'forbidden' ); }
		$cursor = max( 0, $cursor ); $limit = max( 1, min( 20, $limit ) ); $marker = wp_generate_uuid4();
		$where = static function ( $sql, $query ) use ( $cursor, $marker ) {
			if ( ! is_object( $query ) || ! method_exists( $query, 'get' ) || $marker !== $query->get( 'pc_youtube_sync' ) ) { return $sql; }
			global $wpdb;
			return $sql . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $cursor );
		};
		add_filter( 'posts_where', $where, 10, 2 );
		try {
			$documents = get_posts( array(
				'post_type' => Templates::LAYOUT, 'post_status' => 'any', 'fields' => 'ids',
				'posts_per_page' => $limit, 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true,
				'meta_query' => array( array( 'key' => Layout::PUBLISHED_META, 'compare' => 'EXISTS' ) ),
				'suppress_filters' => false, 'pc_youtube_sync' => $marker,
			) );
		} catch ( \Throwable $error ) {
			$documents = null; // Never expose query/provider details in an operator notice.
		} finally { remove_filter( 'posts_where', $where, 10 ); }
		if ( ! is_array( $documents ) ) { return array( 'status' => 'failed', 'cursor' => $cursor, 'processed' => 0, 'matched' => 0 ); }
		$processed = 0; $matched = 0; $status = count( $documents ) < $limit ? 'complete' : 'more';
		foreach ( array_slice( $documents, 0, $limit ) as $document ) {
			$id = (int) $document;
			if ( $id <= $cursor ) { $status = 'failed'; break; }
			// Recheck publication after the query: a concurrent unpublish/delete must not be re-added.
			if ( Templates::LAYOUT === get_post_type( $id ) && 'trash' !== get_post_status( $id ) && Layout::is_published( $id ) ) {
				if ( ! self::index_document( $id ) ) {
					$error = get_transient( 'pc_ytm_index_error' );
					$status = is_array( $error ) && 'capacity' === ( $error['reason'] ?? '' ) ? 'capacity' : 'failed';
					break;
				}
				$index = get_option( self::INDEX, array() ); if ( ! empty( $index[ $id ] ) ) { $matched++; }
			} else {
				if ( ! self::index_document( $id ) ) { $status = 'failed'; break; }
			}
			$cursor = $id; $processed++;
		}
		return array( 'status' => $status, 'cursor' => $cursor, 'processed' => $processed, 'matched' => $matched );
	}

	public static function index_document( int $document_id ): bool {
		return self::write_index( $document_id );
	}

	private static function snapshot( int $document_id ): array {
		// Another PHP request can update SQL while this request still holds local
		// object-cache rows. Refresh only this document, never the global cache.
		wp_cache_delete( $document_id, 'post_meta' );
		wp_cache_delete( $document_id, 'posts' );
		$type = get_post_type( $document_id ); $status = get_post_status( $document_id );
		$published = Templates::LAYOUT === $type && 'trash' !== $status && Layout::is_published( $document_id );
		return array( 'type' => $type, 'status' => $status, 'published' => $published, 'content' => $published ? (string) get_post_meta( $document_id, Layout::PUBLISHED_META, true ) : '' );
	}

	private static function snapshot_ids( array $snapshot ): array {
		if ( ! $snapshot['published'] ) { return array(); }
		$ids = array();
		foreach ( array_slice( Layout::modules_from_content( $snapshot['content'] ), 0, 80 ) as $module ) {
			if ( 'youtube_list' !== Registry::type_for_block( (string) ( $module['block'] ?? '' ) ) ) { continue; }
			$a = $module['attrs'] ?? array(); if ( ! is_array( $a ) || ( array_key_exists( 'enabled', $a ) && ! (bool) $a['enabled'] ) ) { continue; }
			$settings = is_array( $a['settings'] ?? null ) ? $a['settings'] : array();
			// youtubeMode is a global/general field: Renderer intentionally ignores
			// mobile.settings attempts to override it. Honor real device visibility.
			if ( 'reference' !== ( $settings['youtubeMode'] ?? '' ) ) { continue; }
			$desktop = ! array_key_exists( 'showDesktop', $settings ) || (bool) $settings['showDesktop'];
			$phone = ! array_key_exists( 'showMobile', $settings ) || (bool) $settings['showMobile'];
			$mobile = is_array( $a['mobile'] ?? null ) ? $a['mobile'] : array();
			$variants = array();
			if ( $desktop ) { $variants[] = $a['items'] ?? array(); }
			if ( $phone ) { $variants[] = is_array( $mobile['items'] ?? null ) ? $mobile['items'] : ( $a['items'] ?? array() ); }
			foreach ( $variants as $items ) {
				if ( ! is_array( $items ) ) { continue; }
				foreach ( array_slice( $items, 0, self::MAX_IDS ) as $item ) {
					if ( ! is_array( $item ) || ( array_key_exists( 'enabled', $item ) && ! (bool) $item['enabled'] ) ) { continue; }
					$id = self::video_id( is_string( $item['settings']['url'] ?? null ) ? trim( $item['settings']['url'] ) : '' ); if ( $id ) { $ids[ $id ] = true; }
				}
			}
		}
		return array_keys( $ids );
	}

	public static function remove_document( int $document_id ): bool {
		// Hooks describe a past transition, not ownership of the current state.
		// before_delete still has a live row; deleted_post performs final removal.
		// A delayed trash/delete callback must preserve a newer restored publication.
		return self::write_index( $document_id );
	}

	private static function write_index( int $document_id ): bool {
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			wp_cache_delete( self::INDEX, 'options' );
			$before = get_option( self::INDEX, null );
			// Read AFTER the index baseline, and repeat on every conflict: an older
			// publish/unpublish callback must never replay its original video IDs.
			$snapshot = self::snapshot( $document_id );
			$ids = self::snapshot_ids( $snapshot );
			$index = is_array( $before ) ? array_slice( $before, 0, self::MAX_DOCS, true ) : array();
			if ( ! $ids ) { unset( $index[ $document_id ] ); }
			elseif ( isset( $index[ $document_id ] ) || count( $index ) < self::MAX_DOCS ) { $index[ $document_id ] = $ids; }
			else { self::index_error( $document_id, 'capacity' ); return false; }
			$unique = array();
			foreach ( $index as $references ) {
				foreach ( is_array( $references ) ? array_slice( $references, 0, 1920 ) : array() as $id ) {
					if ( self::valid_id( $id ) ) { $unique[ $id ] = true; }
					if ( count( $unique ) > self::MAX_INDEX_IDS ) { self::index_error( $document_id, 'capacity' ); return false; }
				}
			}
			if ( $before === $index || self::cas( self::INDEX, $before, $index ) ) {
				// A publication can change before its hook reaches this index. Recheck
				// even after success, so the final return refers to the latest snapshot.
				if ( $snapshot !== self::snapshot( $document_id ) ) { continue; }
				$error = get_transient( 'pc_ytm_index_error' );
				if ( is_array( $error ) && (int) ( $error['document'] ?? 0 ) === $document_id ) { delete_transient( 'pc_ytm_index_error' ); }
				self::schedule(); return true;
			}
		}
		self::index_error( $document_id, 'conflict' );
		return false;
	}

	private static function index_error( int $document_id, string $reason ): void {
		// Diagnostic only, not an unbounded retry queue and not customer information.
		set_transient( 'pc_ytm_index_error', array( 'document' => $document_id, 'reason' => $reason ), 86400 );
	}

	private static function indexed_ids(): array {
		wp_cache_delete( self::INDEX, 'options' );
		$index = get_option( self::INDEX, array() ); $ids = array();
		foreach ( is_array( $index ) ? array_slice( $index, 0, self::MAX_DOCS, true ) : array() as $document => $rows ) {
			if ( Templates::LAYOUT !== get_post_type( (int) $document ) || 'trash' === get_post_status( (int) $document ) || ! Layout::is_published( (int) $document ) ) { continue; }
			foreach ( is_array( $rows ) ? array_slice( $rows, 0, 1920 ) : array() as $id ) { if ( self::valid_id( $id ) ) { $ids[ $id ] = true; } if ( count( $ids ) >= self::MAX_INDEX_IDS ) { break 2; } }
		}
		return array_keys( $ids );
	}

	private static function schedule( int $delay = 60 ): void {
		if ( 'manual' === self::config()['provider'] || ! self::indexed_ids() ) { wp_unschedule_hook( self::CRON ); return; }
		$target = time() + max( 60, $delay ); $next = wp_next_scheduled( self::CRON );
		if ( $next && $next > $target ) { wp_unschedule_hook( self::CRON ); $next = false; }
		if ( ! $next ) { wp_schedule_single_event( $target, self::CRON ); }
	}

	public static function refresh_published(): void {
		$ids = self::indexed_ids(); if ( ! $ids || 'manual' === self::config()['provider'] ) { return; }
		$provider = self::config()['provider'];
		$due = array_values( array_filter( $ids, static fn( $id ) => ! self::fresh( $provider, $id ) ) );
		if ( 'data_api' === $provider && '' === self::api_key() ) { self::schedule( self::FRESH ); return; }
		$result = $due ? self::resolve( array_slice( $due, 0, self::MAX_IDS ), false, 0 ) : array( 'rows' => array() );
		$limited = array_filter( $result['rows'], static fn( $row ) => 'local_limit' === ( $row['status'] ?? '' ) );
		if ( $limited ) { self::schedule( self::next_day() ); return; }
		$delay = self::FRESH;
		foreach ( $ids as $id ) {
			$entry = get_transient( self::cache_key( $provider, $id ) );
			$delay = min( $delay, is_array( $entry ) ? max( 60, (int) ( $entry['retryAt'] ?? 0 ) - time() ) : 60 );
		}
		self::schedule( $delay );
	}
}
