<?php
/** Passive source adapters and public observations. Disabled until explicitly adopted. */
namespace PeptideCore\Emails;

defined( 'ABSPATH' ) || exit;

final class Message_Observations {
	public const ENABLED = 'pc_mail_observations_enabled';
	public const PRUNE = 'pc_mail_observation_prune';
	private const HEADER = 'X-Pc-Mail-Observation';
	private static array $stack = array();
	private static array $pending_logs = array();

	public static function register_hooks(): void {
		add_filter( 'woocommerce_mail_callback', array( self::class, 'woo_callback' ), PHP_INT_MAX, 2 );
		add_action( 'phpmailer_init', array( self::class, 'mailer' ), PHP_INT_MAX );
		add_action( 'wp_mail_succeeded', array( self::class, 'native_result' ), PHP_INT_MIN );
		add_action( 'wp_mail_failed', array( self::class, 'native_failure' ), PHP_INT_MIN );
		// Finish before Woo's wp_loaded cart/form actions as well as visitor-cookie creation.
		add_action( 'wp_loaded', array( self::class, 'public_request' ), -100 );
		add_action( 'init', array( self::class, 'schedule' ) );
		add_action( self::PRUNE, static function (): void { self::safely( static fn() => Observation_Store::prune() ); } );
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'erasers' ) );
		add_action( 'admin_init', array( self::class, 'policy' ) );
	}
	private static function safely( callable $callback ) {
		try { return $callback(); } catch ( \Throwable $error ) { return null; }
	}
	public static function enabled(): bool {
		return true === self::safely( static fn() => (bool) get_option( self::ENABLED, false ) && Observation_Store::installed() );
	}
	public static function schedule(): void {
		self::safely( static function (): void {
			if ( Observation_Store::installed() && ! wp_next_scheduled( self::PRUNE ) ) { wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::PRUNE ); }
		} );
	}

	/** Only the native wp_mail callback is adapted; arbitrary third-party transports are uncovered. */
	public static function woo_callback( $callback, $email ) {
		if ( 'wp_mail' !== $callback || ! $email instanceof \WC_Email || ! self::enabled() || true !== self::safely( static fn() => ! apply_filters( 'woocommerce_is_email_preview', false ) ) ) { return $callback; }
		return static function ( ...$args ) use ( $callback, $email ) {
			return self::send( $callback, $args, array( 'family' => 'woo', 'key' => (string) $email->id, 'object' => $email ) );
		};
	}

	/** Invoke the original callable exactly once and preserve its return value/exception. */
	public static function send( callable $callback, array $args, array $source ) {
		if ( ! self::enabled() || ! in_array( $source['family'] ?? '', array( 'woo', 'campaign', 'subscription', 'loyalty' ), true ) ) { return $callback( ...$args ); }
		$attempt = self::safely( static fn() => Observation_Store::begin( $source ) );
		if ( ! $attempt ) { return $callback( ...$args ); }
		$frame = (object) array( 'id' => $attempt['id'], 'token' => $attempt['token'], 'marker' => $attempt['id'], 'armed' => true, 'seen' => false, 'confirmed' => false, 'prepared' => false );
		// A one-use in-memory marker identifies this exact wp_mail invocation, including nested sends.
		$mark = static function ( $atts ) use ( $frame ) {
			if ( ! $frame->armed || ! is_array( $atts ) ) { return $atts; }
			$frame->armed = false;
			$headers = $atts['headers'] ?? array();
			$line = self::HEADER . ': ' . $frame->marker;
			$atts['headers'] = is_array( $headers ) ? array_merge( $headers, array( $line ) ) : rtrim( (string) $headers ) . "\r\n" . $line;
			return $atts;
		};
		self::$stack[] = $frame;
		add_filter( 'wp_mail', $mark, PHP_INT_MIN );
		try {
			try { $result = $callback( ...$args ); }
			catch ( \Throwable $error ) {
				self::safely( static fn() => Observation_Store::update( $frame->id, array( 'status' => 'uncertain' ) ) );
				throw $error;
			}
			// This matches Woo's normalization without changing the callback's actual return value.
			$accepted = is_bool( $result ) ? $result : ( is_scalar( $result ) && function_exists( 'wc_string_to_bool' ) ? wc_string_to_bool( (string) $result ) : false );
			self::safely( static function () use ( $frame, $accepted ): void {
				$final = array( 'status' => $accepted ? 'accepted' : 'failed' );
				if ( $frame->prepared && ! $frame->confirmed ) { $final += array( 'coverage' => 'native_unconfirmed', 'open_eligible' => 0, 'click_eligible' => 0 ); }
				Observation_Store::update( $frame->id, $final );
			} );
			self::safely( static function () use ( $source, $frame ): void { if ( 'woo' === $source['family'] && isset( $source['object'] ) ) { self::$pending_logs[ spl_object_id( $source['object'] ) ][] = $frame->id; } } );
			return $result;
		} finally {
			remove_filter( 'wp_mail', $mark, PHP_INT_MIN );
			array_pop( self::$stack );
		}
	}

	/** Precise association is supplied by the real Log insert, never recipient/subject matching. */
	public static function bind_log( $email, int $log_id ): void {
		self::safely( static function () use ( $email, $log_id ): void {
			if ( ! is_object( $email ) ) { return; }
			$key = spl_object_id( $email );
			if ( empty( self::$pending_logs[ $key ] ) ) { return; }
			$id = array_pop( self::$pending_logs[ $key ] );
			if ( ! self::$pending_logs[ $key ] ) { unset( self::$pending_logs[ $key ] ); }
			if ( $log_id > 0 ) { Observation_Store::update( $id, array( 'woo_log_id' => $log_id ) ); }
		} );
	}

	/** Observe actual native sender/MIME/recipients; leave AltBody and all transport settings alone. */
	public static function mailer( $mailer ): void {
		self::safely( static function () use ( $mailer ): void {
			$marker = '';
			foreach ( $mailer->getCustomHeaders() as $header ) { if ( 0 === strcasecmp( $header[0], self::HEADER ) ) { $marker = $header[1]; } }
			if ( '' === $marker ) { return; }
			$mailer->clearCustomHeader( self::HEADER );
			$frame = null;
			foreach ( array_reverse( self::$stack ) as $candidate ) { if ( hash_equals( $candidate->marker, $marker ) ) { $frame = $candidate; break; } }
			if ( ! $frame || $frame->seen ) { return; }
			$frame->seen = true;
			$frame->mailer = $mailer;
			$sender = (string) $mailer->From;
			$mime = strtolower( (string) $mailer->ContentType );
			$addresses = array_keys( $mailer->getAllRecipientAddresses() );
			$data = array( 'mime' => substr( $mime, 0, 32 ), 'sender' => is_email( $sender ) ? $sender : '', 'sender_key' => is_email( $sender ) ? Observation_Store::sender_key( $sender ) : '', 'recipient_count' => count( $addresses ), 'coverage' => 'unsupported_mime' );
			if ( ! Observation_Store::recipients( $frame->id, $addresses ) || ! Observation_Store::update( $frame->id, $data ) ) { return; }
			if ( 'text/plain' === $mime ) { Observation_Store::update( $frame->id, array( 'coverage' => 'plain_no_observations' ) ); return; }
			if ( ! in_array( $mime, array( 'text/html', 'multipart/alternative' ), true ) || ! is_email( $sender ) || ! $addresses ) { return; }
			// Multipart needs the native plain alternative to be present before adding HTML observations.
			if ( 'multipart/alternative' === $mime && '' === (string) $mailer->AltBody ) { return; }
			$pixel = add_query_arg( 'pc_mail_open', $frame->token, home_url( '/' ) );
			$transformed = Observation_Links::html( (string) $mailer->Body, static fn( string $target ) => Observation_Store::link( $frame->id, $target ), $pixel );
			if ( null === $transformed ) { Observation_Store::update( $frame->id, array( 'coverage' => 'unsupported_html' ) ); return; }
			if ( ! Observation_Store::update( $frame->id, array( 'coverage' => 'html_prepared' ) ) ) { return; }
			$mailer->Body = $transformed['body'];
			$frame->prepared = true;
			$frame->body_hash = hash( 'sha256', (string) $mailer->Body );
			$frame->alt_hash = hash( 'sha256', (string) $mailer->AltBody );
			$frame->has_links = $transformed['links'] > 0;
		} );
	}

	/** Native completion carries our exact invocation marker even though it was removed from MIME. */
	public static function native_result( $data ): void {
		self::safely( static function () use ( $data ): void {
			if ( ! is_array( $data ) ) { return; }
			$marker = '';
			foreach ( (array) ( $data['headers'] ?? array() ) as $key => $value ) { if ( is_string( $key ) && 0 === strcasecmp( $key, self::HEADER ) ) { $marker = (string) $value; } }
			if ( '' === $marker ) { return; }
			$frame = null;
			foreach ( array_reverse( self::$stack ) as $candidate ) { if ( hash_equals( $candidate->marker, $marker ) ) { $frame = $candidate; break; } }
			if ( ! $frame || ! $frame->seen || $frame->confirmed ) { return; }
			$mailer = $frame->mailer;
			$sender = (string) $mailer->From;
			$addresses = array_keys( $mailer->getAllRecipientAddresses() );
			if ( ! Observation_Store::replace_recipients( $frame->id, $addresses ) ) { return; }
			$final = array( 'sender' => is_email( $sender ) ? $sender : '', 'sender_key' => is_email( $sender ) ? Observation_Store::sender_key( $sender ) : '', 'recipient_count' => count( $addresses ) );
			if ( $frame->prepared ) {
				$intact = hash_equals( $frame->body_hash, hash( 'sha256', (string) $mailer->Body ) ) && hash_equals( $frame->alt_hash, hash( 'sha256', (string) $mailer->AltBody ) ) && is_email( $sender ) && $addresses;
				$final += array( 'coverage' => $intact ? 'html_observed' : 'native_changed', 'open_eligible' => $intact ? 1 : 0, 'click_eligible' => $intact && $frame->has_links ? 1 : 0 );
			}
			if ( Observation_Store::update( $frame->id, $final ) ) { $frame->confirmed = true; }
		} );
	}
	public static function native_failure( $error ): void {
		self::safely( static function () use ( $error ): void { if ( is_object( $error ) && method_exists( $error, 'get_error_data' ) ) { self::native_result( $error->get_error_data() ); } } );
	}

	/** Public routes finish before theme/visitor rendering and never create a visit. */
	public static function public_request(): void {
		$open = array_key_exists( 'pc_mail_open', $_GET ); $click = array_key_exists( 'pc_mail_click', $_GET );
		if ( ! $open && ! $click ) { return; }
		$kind = $open ? 'open' : 'click'; $value = $_GET[ $open ? 'pc_mail_open' : 'pc_mail_click' ];
		$token = is_string( $value ) ? $value : '';
		$gpc = $_SERVER['HTTP_SEC_GPC'] ?? ''; $optout = $_COOKIE[ \PeptideCore\Pixels::OPTOUT ] ?? '';
		$declined = ( is_string( $gpc ) && '1' === trim( $gpc ) ) || ( is_string( $optout ) && '1' === $optout );
		$count = 'GET' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && ! $declined && self::enabled();
		$target = self::safely( static fn() => Observation_Store::observe( $kind, $token, $count ) );
		nocache_headers();
		header( 'Cache-Control: no-store, private, max-age=0' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		if ( $open ) {
			header( 'Content-Type: image/gif' );
			echo base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' );
		} elseif ( is_string( $target ) && '' !== $target ) {
			wp_safe_redirect( $target, 302, 'Store email link' );
		} else { status_header( 404 ); }
		exit;
	}

	public static function params( array $params ) {
		$family = $params['mail_family'] ?? ''; $sender = $params['mail_sender'] ?? '';
		if ( ! is_string( $family ) || ! in_array( $family, array( '', 'woo', 'campaign', 'subscription', 'loyalty' ), true ) || ! is_string( $sender ) || ( '' !== $sender && ! preg_match( '/\A[a-f0-9]{64}\z/D', $sender ) ) ) { return new \WP_Error( 'pc_invalid_param', __( 'Invalid email observation filter.', 'peptide-core' ), array( 'status' => 400 ) ); }
		return array( 'family' => $family, 'sender' => $sender );
	}
	public static function report( array $range, array $args = array() ): array {
		$result = self::safely( static function () use ( $range, $args ): array {
			if ( ! Observation_Store::installed() ) { return array( 'status' => 'not_installed', 'current' => null, 'previous' => null, 'rows' => array() ); }
			$current = Observation_Store::period( (int) $range['from'], (int) $range['to'], $args );
			$previous = Observation_Store::period( (int) $range['compare_from'], (int) $range['compare_to'], $args );
			return array( 'status' => self::enabled() ? 'enabled' : 'disabled', 'current' => $current['totals'], 'previous' => $previous['totals'], 'rows' => $current['rows'], 'senders' => Observation_Store::senders() );
		} );
		return array_merge( array( 'unit' => 'send_attempt', 'cohort' => 'attempted_ms', 'families' => array( 'woo', 'campaign', 'subscription', 'loyalty' ), 'uncovered_families' => array( 'other_wp_mail', 'custom_woo_callback' ), 'retention_days' => Observation_Store::DAYS, 'historical_backfill' => false, 'filters' => $args ), $result ?? array( 'status' => 'unavailable', 'current' => null, 'previous' => null, 'rows' => array() ) );
	}
	public static function exporters( $exporters ): array {
		$exporters = is_array( $exporters ) ? $exporters : array();
		$exporters['pc-mail-observations'] = array( 'exporter_friendly_name' => __( 'Email observations', 'peptide-core' ), 'callback' => array( Observation_Store::class, 'export' ) );
		return $exporters;
	}
	public static function erasers( $erasers ): array {
		$erasers = is_array( $erasers ) ? $erasers : array();
		$erasers['pc-mail-observations'] = array( 'eraser_friendly_name' => __( 'Email observations', 'peptide-core' ), 'callback' => array( Observation_Store::class, 'erase' ) );
		return $erasers;
	}
	public static function policy(): void {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) { wp_add_privacy_policy_content( __( 'Store email observations', 'peptide-core' ), '<p>When enabled, supported store emails contain a unique image and restricted catalogue links. Loading an image or opening a link records the first request for that message, not proof of reading or inbox delivery. Proxies and security scanners can make these requests. Message attempts, the sender and hashed recipient associations are scheduled for deletion after 180 days; delayed scheduled jobs can delay deletion. Observation credentials expire after 180 days regardless of cleanup. No message body, subject, raw IP or browser identifier is stored by this feature. Supported messages sent to several recipients share one observation record; privacy erasure removes the whole shared record and revokes its links.</p>' ); }
	}
}
