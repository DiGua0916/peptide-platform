<?php
/** Consented store subscriptions. A submitted email is never active before confirmation. */
namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Subscriptions {

	public const SCHEMA = '1';
	public const NONCE = 'pc_subscribe';
	private const OPTION = 'pc_subscriptions_schema';

	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'ensure_schema' ), 6 );
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_action( 'admin_post_nopriv_pc_subscribe', array( self::class, 'submit_form' ) );
		add_action( 'admin_post_pc_subscribe', array( self::class, 'submit_form' ) );
		add_action( 'template_redirect', array( self::class, 'token_page' ), 1 );
	}

	public static function table(): string { global $wpdb; return $wpdb->prefix . 'pc_subscriptions'; }

	public static function ensure_schema(): void {
		if ( self::SCHEMA === get_option( self::OPTION ) ) { return; }
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table(); $collate = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(191) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			source varchar(500) NOT NULL DEFAULT '',
			consent_text text NOT NULL,
			consent_at datetime NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			confirmed_at datetime DEFAULT NULL,
			unsubscribed_at datetime DEFAULT NULL,
			token_hash varchar(64) NOT NULL DEFAULT '',
			token_expires bigint(20) unsigned NOT NULL DEFAULT 0,
			unsubscribe_hash varchar(64) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY email (email),
			KEY status (status)
		) $collate;" );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ) { update_option( self::OPTION, self::SCHEMA, false ); }
	}

	public static function routes(): void {
		register_rest_route( 'peptide/v1', '/subscriptions', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => array( self::class, 'rest_submit' ) ) );
	}

	/** Bind source and the exact displayed consent to this form, without storing visitor identifiers. */
	public static function form_context( string $consent, string $source = '' ): string {
		$source = $source ?: (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );
		$payload = base64_encode( wp_json_encode( array( 'source' => substr( esc_url_raw( $source ), 0, 500 ), 'consent' => sanitize_text_field( $consent ), 'issued' => time() ) ) );
		return $payload . '.' . hash_hmac( 'sha256', $payload, wp_salt( 'nonce' ) );
	}

	private static function decode_context( string $context ): ?array {
		if ( strlen( $context ) > 7000 ) { return null; }
		$parts = explode( '.', $context );
		if ( 2 !== count( $parts ) || ! hash_equals( hash_hmac( 'sha256', $parts[0], wp_salt( 'nonce' ) ), $parts[1] ) ) { return null; }
		$data = json_decode( (string) base64_decode( $parts[0], true ), true );
		if ( ! is_array( $data ) || empty( $data['consent'] ) || empty( $data['issued'] ) || (int) $data['issued'] > time() + 60 || (int) $data['issued'] < time() - DAY_IN_SECONDS ) { return null; }
		return $data;
	}

	public static function render_form( array $settings = array() ): string {
		$consent = trim( sanitize_text_field( $settings['consentText'] ?? __( 'I agree to receive store emails. I can unsubscribe at any time.', 'peptide-core' ) ) );
		if ( '' === $consent ) { $consent = __( 'I agree to receive store emails. I can unsubscribe at any time.', 'peptide-core' ); }
		wp_enqueue_script( 'pc-subscriptions', plugins_url( 'assets/subscriptions.js', PEPTIDE_CORE_FILE ), array(), (string) filemtime( PEPTIDE_CORE_DIR . '/assets/subscriptions.js' ), true );
		wp_enqueue_style( 'pc-commerce-blocks', plugins_url( 'assets/commerce-blocks.css', PEPTIDE_CORE_FILE ), array(), (string) filemtime( PEPTIDE_CORE_DIR . '/assets/commerce-blocks.css' ) );
		$id = wp_unique_id( 'pc-subscribe-' );
		return '<form class="pc-subscribe-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-pc-subscribe data-rest-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '" data-endpoint="' . esc_url( rest_url( 'peptide/v1/subscriptions' ) ) . '" data-error="' . esc_attr__( 'The request could not be completed. Please try again.', 'peptide-core' ) . '"><input type="hidden" name="action" value="pc_subscribe"><input type="hidden" name="nonce" value="' . esc_attr( wp_create_nonce( self::NONCE ) ) . '"><input type="hidden" name="context" value="' . esc_attr( self::form_context( $consent ) ) . '"><label class="pc-subscribe-email" for="' . esc_attr( $id ) . '">' . esc_html__( 'Email address', 'peptide-core' ) . '<input id="' . esc_attr( $id ) . '" type="email" name="email" maxlength="191" autocomplete="email" required></label><button type="submit">' . esc_html( ($settings['buttonLabel'] ?? '') ?: __( 'Subscribe', 'peptide-core' ) ) . '</button><label class="pc-subscribe-consent"><input type="checkbox" name="consent" value="1" required> <span>' . esc_html( $consent ) . '</span></label><label class="pc-subscribe-trap" aria-hidden="true">' . esc_html__( 'Leave this field empty', 'peptide-core' ) . '<input name="website" tabindex="-1" autocomplete="off"></label><p class="pc-subscribe-feedback" role="status" aria-live="polite"></p></form>';
	}

	private static function error( string $code, string $message, int $status = 422 ): \WP_Error { return new \WP_Error( $code, $message, array( 'status' => $status ) ); }
	private static function token_hash( string $token ): string { return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) ); }
	private static function success(): array { return array( 'message' => __( 'If this address needs confirmation, check its inbox and confirm the subscription. It is not subscribed until confirmed.', 'peptide-core' ) ); }

	/** Fixed short limits prevent repeated confirmation sends; forwarded IP headers are deliberately ignored. */
	private static function rate_limit( string $email ) {
		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? 'local' );
		$email_key = 'pc_sub_e_' . substr( self::token_hash( $email ), 0, 32 );
		$ip_key = 'pc_sub_i_' . substr( self::token_hash( $ip ), 0, 32 );
		if ( get_transient( $email_key ) || (int) get_transient( $ip_key ) >= 10 ) { return self::error( 'pc_subscription_rate', __( 'Please wait before requesting another confirmation email.', 'peptide-core' ), 429 ); }
		set_transient( $email_key, 1, MINUTE_IN_SECONDS );
		set_transient( $ip_key, (int) get_transient( $ip_key ) + 1, HOUR_IN_SECONDS );
		return true;
	}

	/** Public request service. Tests can intercept wp_mail; no other delivery provider is contacted. */
	public static function request( array $input ) {
		foreach ( array( 'nonce', 'consent', 'context', 'email', 'website' ) as $key ) { if ( isset( $input[ $key ] ) && ! is_scalar( $input[ $key ] ) ) { return self::error( 'pc_subscription_invalid', __( 'The request could not be completed. Please try again.', 'peptide-core' ) ); } }
		if ( ! wp_verify_nonce( sanitize_text_field( $input['nonce'] ?? '' ), self::NONCE ) ) { return self::error( 'pc_subscription_nonce', __( 'This form has expired. Reload the page and try again.', 'peptide-core' ), 403 ); }
		if ( ! empty( $input['website'] ) ) { return self::error( 'pc_subscription_invalid', __( 'The request could not be completed. Please try again.', 'peptide-core' ) ); }
		if ( '1' !== (string) ( $input['consent'] ?? '' ) ) { return self::error( 'pc_subscription_consent', __( 'Please agree to the email consent before subscribing.', 'peptide-core' ) ); }
		$context = self::decode_context( (string) ( $input['context'] ?? '' ) );
		if ( ! $context ) { return self::error( 'pc_subscription_context', __( 'This form has expired. Reload the page and try again.', 'peptide-core' ), 403 ); }
		$email = strtolower( trim( (string) ( $input['email'] ?? '' ) ) );
		if ( strlen( $email ) > 191 || ! is_email( $email ) || sanitize_email( $email ) !== $email ) { return self::error( 'pc_subscription_email', __( 'Enter a valid email address.', 'peptide-core' ) ); }
		$limited = self::rate_limit( $email ); if ( is_wp_error( $limited ) ) { return $limited; }
		self::ensure_schema(); global $wpdb; $table = self::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE email=%s", $email ), ARRAY_A );
		if ( $row && 'active' === $row['status'] ) { return self::success(); }
		$token = bin2hex( random_bytes( 32 ) ); $unsubscribe = bin2hex( random_bytes( 32 ) ); $now = gmdate( 'Y-m-d H:i:s' );
		$data = array( 'status' => 'pending', 'source' => substr( esc_url_raw( $context['source'] ), 0, 500 ), 'consent_text' => sanitize_text_field( $context['consent'] ), 'consent_at' => $now, 'updated_at' => $now, 'confirmed_at' => null, 'unsubscribed_at' => null, 'token_hash' => self::token_hash( $token ), 'token_expires' => time() + 2 * DAY_IN_SECONDS, 'unsubscribe_hash' => self::token_hash( $unsubscribe ) );
		if ( $row ) { $ok = $wpdb->update( $table, $data, array( 'id' => $row['id'], 'status' => $row['status'] ) ); $id = (int) $row['id']; }
		else { $ok = $wpdb->insert( $table, array_merge( array( 'email' => $email, 'created_at' => $now ), $data ) ); $id = (int) $wpdb->insert_id; }
		if ( false === $ok || ! $id ) { return self::error( 'pc_subscription_storage', __( 'The subscription could not be saved. Please try again later.', 'peptide-core' ), 503 ); }
		// If a concurrent request confirmed this record, do not send a now invalid token.
		if ( 0 === $ok ) { return self::success(); }
		$confirm_url = self::token_url( 'confirm', $id, $token ); $unsubscribe_url = self::token_url( 'unsubscribe', $id, $unsubscribe );
		$subject = sprintf( __( 'Confirm your subscription to %s', 'peptide-core' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		$body = __( 'Please confirm that you asked to receive store emails:', 'peptide-core' ) . "\n\n" . $confirm_url . "\n\n" . __( 'This confirmation link expires in 48 hours. If you did not request it, ignore this email or cancel below.', 'peptide-core' ) . "\n\n" . __( 'Unsubscribe or cancel this request:', 'peptide-core' ) . "\n" . $unsubscribe_url;
		if ( ! wp_mail( $email, $subject, $body, array( 'Content-Type: text/plain; charset=UTF-8' ) ) ) { return self::error( 'pc_subscription_mail', __( 'The confirmation email could not be handed to the mail service. Your address remains unconfirmed. Please try again later.', 'peptide-core' ), 503 ); }
		return self::success();
	}

	public static function token_url( string $action, int $id, string $token ): string { return add_query_arg( array( 'pc_subscription' => $action, 'subscriber' => $id, 'token' => $token ), home_url( '/' ) ); }

	private static function token_record( string $action, int $id, string $token ): ?array {
		if ( ! in_array( $action, array( 'confirm', 'unsubscribe' ), true ) || ! $id || ! preg_match( '/^[a-f0-9]{64}$/D', $token ) ) { return null; }
		global $wpdb; $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id=%d', $id ), ARRAY_A );
		$column = 'confirm' === $action ? 'token_hash' : 'unsubscribe_hash';
		if ( ! $row || empty( $row[ $column ] ) || ! hash_equals( $row[ $column ], self::token_hash( $token ) ) ) { return null; }
		if ( 'confirm' === $action && ( 'pending' !== $row['status'] || (int) $row['token_expires'] < time() ) ) { return null; }
		return $row;
	}

	/** Call only after an explicit confirmation POST; GET requests and mail scanners never activate. */
	public static function consume_token( string $action, int $id, string $token ) {
		$row = self::token_record( $action, $id, $token );
		if ( ! $row ) { return self::error( 'pc_subscription_token', __( 'This link is invalid, expired or has already been used.', 'peptide-core' ), 400 ); }
		global $wpdb; $now = gmdate( 'Y-m-d H:i:s' );
		$data = array( 'status' => 'confirm' === $action ? 'active' : 'unsubscribed', 'updated_at' => $now, 'token_hash' => '', 'token_expires' => 0 );
		$data[ 'confirm' === $action ? 'confirmed_at' : 'unsubscribed_at' ] = $now;
		if ( 'unsubscribe' === $action ) { $data['unsubscribe_hash'] = ''; }
		$where = array( 'id' => $id, 'confirm' === $action ? 'token_hash' : 'unsubscribe_hash' => self::token_hash( $token ) );
		if ( 1 !== $wpdb->update( self::table(), $data, $where ) ) { return self::error( 'pc_subscription_token', __( 'This link is invalid, expired or has already been used.', 'peptide-core' ), 400 ); }
		return array( 'message' => 'confirm' === $action ? __( 'Your email subscription is confirmed.', 'peptide-core' ) : __( 'You have been unsubscribed. No further subscription emails will be sent.', 'peptide-core' ) );
	}

	public static function rest_submit( \WP_REST_Request $request ) {
		$result = self::request( $request->get_params() );
		return is_wp_error( $result ) ? $result : new \WP_REST_Response( $result, 200 );
	}
	public static function submit_form(): void {
		$result = self::request( wp_unslash( $_POST ) );
		$message = is_wp_error( $result ) ? $result->get_error_message() : $result['message'];
		wp_die( '<p>' . esc_html( $message ) . '</p><p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Return to store', 'peptide-core' ) . '</a></p>', esc_html__( 'Email subscription', 'peptide-core' ), array( 'response' => is_wp_error( $result ) ? (int) ( $result->get_error_data()['status'] ?? 422 ) : 200 ) );
	}
	public static function token_page(): void {
		if ( ! isset( $_GET['pc_subscription'] ) ) { return; }
		nocache_headers(); header( 'Referrer-Policy: no-referrer' ); header( 'X-Robots-Tag: noindex, nofollow' );
		$action = sanitize_key( wp_unslash( $_GET['pc_subscription'] ) ); $id = absint( $_GET['subscriber'] ?? 0 ); $token = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
		$nonce_action = 'pc_subscription_' . $action . '_' . $id;
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$result = wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), $nonce_action ) ? self::consume_token( $action, $id, $token ) : self::error( 'pc_subscription_nonce', __( 'This form has expired. Reload the page and try again.', 'peptide-core' ), 403 );
			$body = '<p>' . esc_html( is_wp_error( $result ) ? $result->get_error_message() : $result['message'] ) . '</p>';
			$status = is_wp_error( $result ) ? (int) ( $result->get_error_data()['status'] ?? 400 ) : 200;
		} elseif ( self::token_record( $action, $id, $token ) ) {
			$label = 'confirm' === $action ? __( 'Confirm email subscription', 'peptide-core' ) : __( 'Unsubscribe', 'peptide-core' );
			$body = '<form method="post" action="' . esc_url( self::token_url( $action, $id, $token ) ) . '">' . wp_nonce_field( $nonce_action, '_wpnonce', false, false ) . '<p>' . esc_html( $label ) . '</p><button type="submit">' . esc_html( $label ) . '</button></form>'; $status = 200;
		} else { $body = '<p>' . esc_html__( 'This link is invalid, expired or has already been used.', 'peptide-core' ) . '</p>'; $status = 400; }
		$body .= '<p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Return to store', 'peptide-core' ) . '</a></p>';
		wp_die( $body, esc_html__( 'Email subscription', 'peptide-core' ), array( 'response' => $status ) );
	}

	/** Protect spreadsheet imports against executable formula cells. */
	public static function csv_cell( $value ): string { $value = (string) $value; return preg_match( '/^[\s]*[=+@-]/u', $value ) ? "'" . $value : $value; }
}
