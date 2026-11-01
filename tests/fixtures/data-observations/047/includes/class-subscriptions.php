<?php
/** Consented store subscriptions. A submitted email is never active before confirmation. */
namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Subscriptions {

	public const SCHEMA = '2';
	public const NONCE = 'pc_subscribe';
	private const OPTION = 'pc_subscriptions_schema';

	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'ensure_schema' ), 6 );
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'privacy_exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'privacy_erasers' ) );
		add_action( 'admin_init', array( self::class, 'privacy_policy' ) );
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_action( 'admin_post_nopriv_pc_subscribe', array( self::class, 'submit_form' ) );
		add_action( 'admin_post_pc_subscribe', array( self::class, 'submit_form' ) );
		add_action( 'template_redirect', array( self::class, 'token_page' ), 1 );
	}

	public static function table(): string { global $wpdb; return $wpdb->prefix . 'pc_subscriptions'; }

	public static function ensure_schema(): void {
		if ( self::SCHEMA === get_option( self::OPTION ) ) { return; }
		global $wpdb;
		$table = self::table();
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
		if ( $exists ) {
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM `' . $table . '` LIKE %s', 'other_contact' ) ) ) { $wpdb->query( "ALTER TABLE `$table` ADD COLUMN other_contact varchar(191) NOT NULL DEFAULT ''" ); }
		} else {
			// Preserve the original fresh-install table creation; QA upgrade approval must require an existing table.
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$collate = $wpdb->get_charset_collate();
			dbDelta( "CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(191) NOT NULL,
			other_contact varchar(191) NOT NULL DEFAULT '',
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
		}
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table && 'other_contact' === $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM `' . $table . '` LIKE %s', 'other_contact' ) ) ) { update_option( self::OPTION, self::SCHEMA, false ); }
	}

	public static function routes(): void {
		register_rest_route( 'peptide/v1', '/subscriptions', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => array( self::class, 'rest_submit' ) ) );
	}

	/** Bind source and the exact displayed consent to this form, without storing visitor identifiers. */
	public static function form_context( string $consent, string $source = '', bool $other_contact = false ): string {
		$source = $source ?: (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );
		$payload = base64_encode( wp_json_encode( array( 'source' => substr( esc_url_raw( $source ), 0, 500 ), 'consent' => sanitize_text_field( $consent ), 'issued' => time() ) ) );
		if ( $other_contact ) { $data = json_decode( base64_decode( $payload ), true ); $data['otherContact'] = true; $payload = base64_encode( wp_json_encode( $data ) ); }
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

	/** O17 reference contact opt-in; the legacy form and email confirmation protocol remain unchanged. */
	public static function render_reference_form( array $settings, bool $interactive = false ): string {
		if ( 'reference' !== ( $settings['subscribeMode'] ?? 'legacy' ) ) { return ''; }
		$preview = \PeptideCore\Modules\Renderer::$in_editor || Templates::previewing_id() > 0;
		if ( ! $preview && Store_Config::get( 'force_login' ) && ! is_user_logged_in() ) { return ''; }
		$interactive = $interactive && ! $preview;
		$consent = trim( sanitize_text_field( $settings['consentText'] ?? __( 'I agree to receive store emails. I can unsubscribe at any time.', 'peptide-core' ) ) );
		if ( '' === $consent ) { $consent = __( 'I agree to receive store emails. I can unsubscribe at any time.', 'peptide-core' ); }
		$contact = self::reference_contact_policy( $settings );
		if ( $contact['enabled'] ) { $consent .= ' ' . __( 'If I provide another contact detail, I agree to store it with this subscription. It is not verified and is not used to send messages here.', 'peptide-core' ); }
		$label = ( $settings['buttonLabel'] ?? '' ) ?: __( 'Subscribe', 'peptide-core' );
		$id = wp_unique_id( 'pc-subscribe-reference-' ); $tokens = ''; $effect_attrs = '';
		$effect = in_array( $settings['buttonEffect'] ?? '', array( 'none', 'bounce', 'rubberband', 'shake', 'swing', 'wobble' ), true ) ? $settings['buttonEffect'] : 'none';
		if ( $interactive ) {
			wp_enqueue_script( 'pc-subscriptions', plugins_url( 'assets/subscriptions.js', PEPTIDE_CORE_FILE ), array(), (string) filemtime( PEPTIDE_CORE_DIR . '/assets/subscriptions.js' ), true );
			$open = '<form class="pc-subscribe-form pc-subscribe-reference__form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-pc-subscribe data-pc-subscribe-reference data-rest-nonce="' . esc_attr( wp_create_nonce( 'wp_rest' ) ) . '" data-endpoint="' . esc_url( rest_url( 'peptide/v1/subscriptions' ) ) . '" data-error="' . esc_attr__( 'The request could not be completed. Please try again.', 'peptide-core' ) . '" data-timeout="' . esc_attr__( 'The request timed out. Its result is unknown. Reload the page before trying again.', 'peptide-core' ) . '" data-supplement="' . esc_attr( sanitize_textarea_field( $settings['subscribeThanks'] ?? '' ) ) . '">';
			$tokens = '<input type="hidden" name="action" value="pc_subscribe"><input type="hidden" name="nonce" value="' . esc_attr( wp_create_nonce( self::NONCE ) ) . '"><input type="hidden" name="context" value="' . esc_attr( self::form_context( $consent, '', $contact['enabled'] ) ) . '">';
			$effect_attrs = ' data-pc-campaign-effect="' . esc_attr( $effect ) . '" data-effect-duration="' . max( 1000, min( 5000, (int) ( $settings['effectDurationMs'] ?? 1000 ) ) ) . '" data-effect-interval="' . max( 500, min( 5000, (int) ( $settings['effectIntervalMs'] ?? 1500 ) ) ) . '"';
		} else {
			$open = '<div class="pc-subscribe-form pc-subscribe-reference__form" role="group" aria-label="' . esc_attr__( 'Email subscription preview', 'peptide-core' ) . '" data-pc-subscribe-preview>';
		}
		$disabled = $interactive ? '' : ' disabled';
		$contact_input = $contact['enabled'] ? '<label class="pc-subscribe-email pc-subscribe-other" for="' . esc_attr( $id . '-other' ) . '"><span class="pc-subscribe-reference__label">' . esc_html__( 'Other contact detail', 'peptide-core' ) . '</span><input id="' . esc_attr( $id . '-other' ) . '" type="text" name="other_contact" maxlength="191" autocomplete="off" placeholder="' . esc_attr( $contact['placeholder'] ) . '"' . ( $contact['required'] ? ' required' : '' ) . $disabled . '></label>' : '';
		$row = '<div class="pc-subscribe-reference__row' . ( $contact['enabled'] ? ' pc-subscribe-reference__row--contact' : '' ) . '"><label class="pc-subscribe-email" for="' . esc_attr( $id ) . '"><span class="pc-subscribe-reference__label">' . esc_html__( 'Email address', 'peptide-core' ) . '</span><input id="' . esc_attr( $id ) . '" type="email" name="email" maxlength="191" autocomplete="email" placeholder="' . esc_attr__( 'Email', 'peptide-core' ) . '" required' . $disabled . '></label>' . $contact_input . '<button class="pc-subscribe-reference__button pc-campaign__button pc-campaign__button--' . esc_attr( $effect ) . '" type="' . ( $interactive ? 'submit' : 'button' ) . '"' . $disabled . $effect_attrs . '>' . esc_html( $label ) . '</button></div>';
		$consent_html = '<label class="pc-subscribe-consent"><input type="checkbox" name="consent" value="1" required' . $disabled . '> <span>' . esc_html( $consent ) . '</span></label>';
		$tail = $interactive ? '<label class="pc-subscribe-trap" aria-hidden="true">' . esc_html__( 'Leave this field empty', 'peptide-core' ) . '<input name="website" tabindex="-1" autocomplete="off"></label><p class="pc-subscribe-feedback" role="status" aria-live="polite"></p></form>' : '<p class="pc-subscribe-reference__preview-note">' . esc_html__( 'Subscription preview. No email is sent from the design editor.', 'peptide-core' ) . '</p></div>';
		return $open . $tokens . $row . $consent_html . $tail;
	}

	public static function contact_storage_ready(): bool { return self::SCHEMA === get_option( self::OPTION ); }

	public static function reference_contact_policy( array $settings ): array {
		$global = Store_Config::other_contact_preferences();
		$enabled = 'reference' === ( $settings['subscribeMode'] ?? 'legacy' ) && ! empty( $settings['collectOtherContact'] ) && in_array( $global['mode'], array( '1', '2' ), true );
		return array( 'enabled' => $enabled, 'required' => $enabled && '1' === $global['mode'], 'placeholder' => $global['placeholder'] );
	}

	/** Unsigned/disabled inputs are never read, validated, echoed or persisted as contact data. */
	private static function requested_other_contact( array $input, array $context ) {
		$global = Store_Config::other_contact_preferences();
		if ( true !== ( $context['otherContact'] ?? false ) || ! in_array( $global['mode'], array( '1', '2' ), true ) ) { return ''; }
		$value = $input['other_contact'] ?? '';
		if ( ! is_string( $value ) || strlen( $value ) > 1000 || ! preg_match( '//u', $value ) || preg_match( '/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $value ) ) { return self::error( 'pc_subscription_contact', __( 'Enter a single line contact detail of up to 191 characters.', 'peptide-core' ) ); }
		$value = trim( sanitize_text_field( $value ) );
		preg_match_all( '/./us', $value, $characters );
		if ( count( $characters[0] ) > 191 || ( '1' === $global['mode'] && '' === $value ) ) { return self::error( 'pc_subscription_contact', __( 'Enter a single line contact detail of up to 191 characters.', 'peptide-core' ) ); }
		return $value;
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
		$other_contact = self::requested_other_contact( $input, $context );
		if ( is_wp_error( $other_contact ) ) { return $other_contact; }
		$limited = self::rate_limit( $email ); if ( is_wp_error( $limited ) ) { return $limited; }
		self::ensure_schema(); global $wpdb; $table = self::table();
		if ( true === ( $context['otherContact'] ?? false ) && in_array( Store_Config::other_contact_preferences()['mode'], array( '1', '2' ), true ) && ! self::contact_storage_ready() ) { return self::error( 'pc_subscription_storage', __( 'The contact detail could not be saved. Please try again later.', 'peptide-core' ), 503 ); }
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE email=%s", $email ), ARRAY_A );
		if ( $row && 'active' === $row['status'] ) { return self::success(); }
		$token = bin2hex( random_bytes( 32 ) ); $unsubscribe = bin2hex( random_bytes( 32 ) ); $now = gmdate( 'Y-m-d H:i:s' );
		$data = array( 'status' => 'pending', 'source' => substr( esc_url_raw( $context['source'] ), 0, 500 ), 'consent_text' => sanitize_text_field( $context['consent'] ), 'consent_at' => $now, 'updated_at' => $now, 'confirmed_at' => null, 'unsubscribed_at' => null, 'token_hash' => self::token_hash( $token ), 'token_expires' => time() + 2 * DAY_IN_SECONDS, 'unsubscribe_hash' => self::token_hash( $unsubscribe ) );
		if ( self::contact_storage_ready() ) { $data['other_contact'] = $other_contact; }
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

	public static function privacy_exporters( $exporters ): array {
		$exporters = is_array( $exporters ) ? $exporters : array();
		$exporters['peptide-core-subscriptions'] = array( 'exporter_friendly_name' => __( 'Store subscriptions', 'peptide-core' ), 'callback' => array( self::class, 'privacy_export' ) );
		return $exporters;
	}

	public static function privacy_erasers( $erasers ): array {
		$erasers = is_array( $erasers ) ? $erasers : array();
		$erasers['peptide-core-subscriptions'] = array( 'eraser_friendly_name' => __( 'Store subscriptions', 'peptide-core' ), 'callback' => array( self::class, 'privacy_erase' ) );
		return $erasers;
	}

	private static function privacy_email( string $email ): string {
		$email = strtolower( trim( $email ) );
		return strlen( $email ) <= 191 && is_email( $email ) && sanitize_email( $email ) === $email ? $email : '';
	}

	public static function privacy_export( string $email, int $page = 1 ): array {
		$email = self::privacy_email( $email );
		if ( '' === $email || 1 !== $page ) { return array( 'data' => array(), 'done' => true ); }
		global $wpdb; $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE email=%s', $email ), ARRAY_A );
		$data = array();
		if ( $row ) {
			$fields = array( 'email' => 'Email address', 'other_contact' => 'Other contact detail (not verified)', 'status' => 'Email subscription status', 'source' => 'Source', 'consent_text' => 'Consent evidence', 'consent_at' => 'Consent at (UTC)', 'created_at' => 'Created at (UTC)', 'confirmed_at' => 'Email confirmed at (UTC)', 'unsubscribed_at' => 'Unsubscribed at (UTC)' );
			foreach ( $fields as $key => $name ) { $data[] = array( 'name' => __( $name, 'peptide-core' ), 'value' => (string) ( $row[ $key ] ?? '' ) ); }
			$data = array( array( 'group_id' => 'peptide-core-subscriptions', 'group_label' => __( 'Store subscriptions', 'peptide-core' ), 'item_id' => 'subscription-' . (int) $row['id'], 'data' => $data ) );
		}
		return array( 'data' => $data, 'done' => true );
	}

	public static function privacy_erase( string $email, int $page = 1 ): array {
		$email = self::privacy_email( $email );
		if ( '' === $email || 1 !== $page ) { return array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true ); }
		global $wpdb; $removed = $wpdb->delete( self::table(), array( 'email' => $email ), array( '%s' ) );
		return array( 'items_removed' => (bool) $removed, 'items_retained' => false === $removed, 'messages' => false === $removed ? array( __( 'Subscription data could not be erased. Please retry the privacy request.', 'peptide-core' ) ) : array(), 'done' => true );
	}

	public static function privacy_policy(): void {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) { wp_add_privacy_policy_content( __( 'Store subscriptions', 'peptide-core' ), '<p>' . esc_html__( 'We store your email address, subscription status, source and consent evidence. If an enabled subscription form asks for another contact detail, we store that text with your subscription. Email confirmation does not verify that contact detail, and this field does not send messages. Subscription records can be exported or erased through the store privacy request process.', 'peptide-core' ) . '</p>' ); }
	}

	/** Protect spreadsheet imports against executable formula cells. */
	public static function csv_cell( $value ): string { $value = (string) $value; return preg_match( '/^[\s]*[=+@-]/u', $value ) ? "'" . $value : $value; }
}
