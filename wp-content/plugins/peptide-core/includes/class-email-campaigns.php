<?php
/** Consented, resumable email campaigns. "Sent" means accepted by wp_mail, never delivered. */
namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Email_Campaigns {
	public const CAP = 'edit_shop_coupons';
	public const HOOK = 'pc_email_campaign_batch';
	public const ENABLED = 'pc_email_campaigns_enabled';
	public const BATCH = 20;
	private const SCHEMA = 'pc_email_campaigns_schema';

	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'install' ), 7 );
		add_action( self::HOOK, array( self::class, 'run_batch' ) );
		add_action( 'template_redirect', array( self::class, 'unsubscribe_page' ), 1 );
	}
	public static function table( string $kind = 'campaigns' ): string { global $wpdb; return $wpdb->prefix . ( 'deliveries' === $kind ? 'pc_email_campaign_deliveries' : 'pc_email_campaigns' ); }
	public static function install(): void {
		if ( '2' === get_option( self::SCHEMA ) ) { return; }
		global $wpdb; require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = self::table(); $d = self::table( 'deliveries' ); $charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE $c (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
title varchar(200) NOT NULL,
subject varchar(200) NOT NULL,
body longtext NOT NULL,
audience_source varchar(500) NOT NULL DEFAULT '',
status varchar(20) NOT NULL DEFAULT 'draft',
scheduled_at bigint(20) unsigned NOT NULL DEFAULT 0,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
author_id bigint(20) unsigned NOT NULL,
lock_token varchar(64) NOT NULL DEFAULT '',
lock_until bigint(20) unsigned NOT NULL DEFAULT 0,
last_error text NOT NULL,
PRIMARY KEY  (id),
KEY status (status)
) ENGINE=InnoDB $charset;" );
		dbDelta( "CREATE TABLE $d (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
campaign_id bigint(20) unsigned NOT NULL,
subscriber_id bigint(20) unsigned NOT NULL,
status varchar(20) NOT NULL DEFAULT 'pending',
attempts int(10) unsigned NOT NULL DEFAULT 0,
claimed_at bigint(20) unsigned NOT NULL DEFAULT 0,
sent_at datetime DEFAULT NULL,
last_error text NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY recipient (campaign_id,subscriber_id),
KEY work (campaign_id,status,id)
) ENGINE=InnoDB $charset;" );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $c ) ) ) === $c && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $d ) ) ) === $d && $wpdb->get_var( "SHOW COLUMNS FROM $c LIKE 'audience_source'" ) ) { update_option( self::SCHEMA, '2', false ); }
	}
	private static function error( string $code, string $message ): \WP_Error { return new \WP_Error( $code, $message ); }
	public static function ready() {
		if ( ! get_option( self::ENABLED, false ) ) { return self::error( 'pc_campaign_disabled', __( 'Campaign sending is disabled. Enable it after checking the sender and store address.', 'peptide-core' ) ); }
		$from = (string) get_option( 'woocommerce_email_from_address', '' );
		if ( ! is_email( $from ) || preg_match( '/[\r\n]/', $from ) || ! trim( (string) get_option( 'woocommerce_email_from_name', '' ) ) ) { return self::error( 'pc_campaign_sender', __( 'Configure a valid sender email and sender name first.', 'peptide-core' ) ); }
		if ( Recovery::blocked_reason() ) { return self::error( 'pc_campaign_address', __( 'Configure the physical store address and city before sending campaigns.', 'peptide-core' ) ); }
		return true;
	}
	public static function get( int $id ): ?array { global $wpdb; return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id=%d', $id ), ARRAY_A ) ?: null; }
	public static function save( int $id, array $raw ) {
		if ( ! current_user_can( self::CAP ) ) { return self::error( 'pc_campaign_forbidden', __( 'You cannot manage email campaigns.', 'peptide-core' ) ); }
		$old = $id ? self::get( $id ) : null;
		if ( $id && ( ! $old || 'draft' !== $old['status'] ) ) { return self::error( 'pc_campaign_locked', __( 'Only a draft can be edited. Queued campaign content is kept as a sending snapshot.', 'peptide-core' ) ); }
		$title = mb_substr( sanitize_text_field( $raw['title'] ?? '' ), 0, 200 );
		$subject = mb_substr( preg_replace( '/[\r\n]+/', ' ', sanitize_text_field( $raw['subject'] ?? '' ) ), 0, 200 );
		$body = wp_kses_post( (string) ( $raw['body'] ?? '' ) );
		if ( ! $title || ! $subject || ! trim( wp_strip_all_tags( $body ) ) || strlen( $body ) > 200000 ) { return self::error( 'pc_campaign_content', __( 'Enter a campaign name, subject and message (up to 200 KB).', 'peptide-core' ) ); }
		global $wpdb; $now = gmdate( 'Y-m-d H:i:s' );
		$data = array( 'title' => $title, 'subject' => $subject, 'body' => $body, 'audience_source' => substr( sanitize_text_field( $raw['audience_source'] ?? '' ), 0, 500 ), 'updated_at' => $now );
		if ( $id ) { $result = $wpdb->update( self::table(), $data, array( 'id' => $id, 'status' => 'draft' ) ); }
		else { $result = $wpdb->insert( self::table(), $data + array( 'status' => 'draft', 'created_at' => $now, 'author_id' => get_current_user_id(), 'last_error' => '' ) ); $id = (int) $wpdb->insert_id; }
		return false === $result ? self::error( 'pc_campaign_storage', __( 'The campaign could not be saved.', 'peptide-core' ) ) : $id;
	}
	private static function schedule_event( int $id, int $when ): bool {
		if ( wp_next_scheduled( self::HOOK, array( $id ) ) ) { return true; }
		return true === wp_schedule_single_event( max( time() + 1, $when ), self::HOOK, array( $id ) );
	}
	public static function schedule( int $id, int $when = 0 ) {
		if ( ! current_user_can( self::CAP ) ) { return self::error( 'pc_campaign_forbidden', __( 'You cannot manage email campaigns.', 'peptide-core' ) ); }
		$ready = self::ready(); if ( is_wp_error( $ready ) ) { return $ready; }
		global $wpdb; $c = self::table(); $d = self::table( 'deliveries' ); $s = Subscriptions::table();
		$wpdb->query( 'START TRANSACTION' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $c WHERE id=%d FOR UPDATE", $id ), ARRAY_A );
		if ( ! $row || 'draft' !== $row['status'] ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'pc_campaign_state', __( 'Only a draft can be scheduled.', 'peptide-core' ) ); }
		$source = $row['audience_source'] ? $wpdb->prepare( ' AND source=%s', $row['audience_source'] ) : '';
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $d (campaign_id,subscriber_id,status,last_error) SELECT %d,id,'pending','' FROM $s WHERE status='active' AND confirmed_at IS NOT NULL AND consent_at IS NOT NULL AND consent_text<>'' $source", $id ) );
		if ( ! $inserted ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'pc_campaign_audience', __( 'There are no confirmed, consenting subscribers to send to.', 'peptide-core' ) ); }
		$when = max( time(), $when );
		if ( false === $wpdb->update( $c, array( 'status' => 'scheduled', 'scheduled_at' => $when, 'updated_at' => gmdate( 'Y-m-d H:i:s' ), 'last_error' => '' ), array( 'id' => $id ) ) ) { $wpdb->query( 'ROLLBACK' ); return self::error( 'pc_campaign_storage', __( 'The campaign could not be saved.', 'peptide-core' ) ); }
		$wpdb->query( 'COMMIT' );
		if ( ! self::schedule_event( $id, $when ) ) { $wpdb->update( $c, array( 'status' => 'paused', 'last_error' => __( 'The background task could not be scheduled. Resume to try again.', 'peptide-core' ) ), array( 'id' => $id ) ); }
		return self::get( $id );
	}
	public static function control( int $id, string $action ) {
		if ( ! current_user_can( self::CAP ) ) { return self::error( 'pc_campaign_forbidden', __( 'You cannot manage email campaigns.', 'peptide-core' ) ); }
		$row = self::get( $id ); if ( ! $row ) { return self::error( 'pc_campaign_missing', __( 'Campaign not found.', 'peptide-core' ) ); }
		global $wpdb; $c = self::table(); $d = self::table( 'deliveries' );
		if ( 'pause' === $action && in_array( $row['status'], array( 'scheduled', 'running' ), true ) ) {
			$wpdb->update( $c, array( 'status' => 'paused' ), array( 'id' => $id, 'status' => $row['status'] ) ); wp_clear_scheduled_hook( self::HOOK, array( $id ) );
		} elseif ( 'cancel' === $action && in_array( $row['status'], array( 'scheduled', 'running', 'paused' ), true ) ) {
			$wpdb->update( $c, array( 'status' => 'cancelled' ), array( 'id' => $id, 'status' => $row['status'] ) ); wp_clear_scheduled_hook( self::HOOK, array( $id ) );
		} elseif ( in_array( $action, array( 'resume', 'retry' ), true ) && ( 'paused' === $row['status'] || ( 'retry' === $action && 'completed' === $row['status'] ) ) ) {
			$ready = self::ready(); if ( is_wp_error( $ready ) ) { return $ready; }
			if ( 'retry' === $action ) { $wpdb->query( $wpdb->prepare( "UPDATE $d SET status='pending',last_error='' WHERE campaign_id=%d AND status='failed' AND attempts<3", $id ) ); }
			$when = 'resume' === $action ? max( time(), (int) $row['scheduled_at'] ) : time();
			$wpdb->update( $c, array( 'status' => 'scheduled', 'scheduled_at' => $when, 'last_error' => '' ), array( 'id' => $id, 'status' => $row['status'] ) );
			if ( ! self::schedule_event( $id, $when ) ) { $wpdb->update( $c, array( 'status' => 'paused' ), array( 'id' => $id ) ); }
		} else { return self::error( 'pc_campaign_state', __( 'This action is not available for the current campaign state.', 'peptide-core' ) ); }
		return self::get( $id );
	}
	public static function stats( int $id ): array {
		global $wpdb; $out = array_fill_keys( array( 'pending', 'processing', 'sent', 'failed', 'skipped', 'uncertain' ), 0 );
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT status,COUNT(*) AS n FROM ' . self::table( 'deliveries' ) . ' WHERE campaign_id=%d GROUP BY status', $id ), ARRAY_A ) as $row ) { $out[ $row['status'] ] = (int) $row['n']; }
		return $out;
	}
	private static function subscriber( int $id ): ?array { global $wpdb; return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Subscriptions::table() . ' WHERE id=%d', $id ), ARRAY_A ) ?: null; }
	private static function eligible( ?array $subscriber ): bool { return $subscriber && 'active' === $subscriber['status'] && $subscriber['confirmed_at'] && $subscriber['consent_at'] && trim( $subscriber['consent_text'] ) && is_email( $subscriber['email'] ) && $subscriber['unsubscribe_hash']; }
	private static function unsubscribe_token( array $subscriber ): string { return hash_hmac( 'sha256', $subscriber['id'] . '|' . $subscriber['email'] . '|' . $subscriber['unsubscribe_hash'], wp_salt( 'auth' ) ); }
	public static function unsubscribe_url( array $subscriber ): string { return add_query_arg( array( 'pc_campaign_unsubscribe' => $subscriber['id'], 'token' => self::unsubscribe_token( $subscriber ) ), home_url( '/' ) ); }
	public static function unsubscribe( int $id, string $token ) {
		$row = self::subscriber( $id );
		if ( ! $row || ! $row['unsubscribe_hash'] || ! hash_equals( self::unsubscribe_token( $row ), $token ) ) { return self::error( 'pc_campaign_token', __( 'This unsubscribe link is invalid or has already been used.', 'peptide-core' ) ); }
		global $wpdb;
		$changed = $wpdb->update( Subscriptions::table(), array( 'status' => 'unsubscribed', 'unsubscribed_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ), 'token_hash' => '', 'token_expires' => 0, 'unsubscribe_hash' => '' ), array( 'id' => $id, 'unsubscribe_hash' => $row['unsubscribe_hash'] ) );
		return 1 === $changed ? true : self::error( 'pc_campaign_token', __( 'This unsubscribe link is invalid or has already been used.', 'peptide-core' ) );
	}
	public static function message( array $campaign, ?array $subscriber = null ): string {
		$url = $subscriber ? self::unsubscribe_url( $subscriber ) : '#unsubscribe-preview';
		return '<!doctype html><html><body><h1>' . esc_html( $campaign['subject'] ) . '</h1>' . wp_kses_post( wpautop( $campaign['body'] ) ) . '<hr><p>' . esc_html__( 'You received this email because you confirmed your store email subscription.', 'peptide-core' ) . '</p><p>' . esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) . '<br>' . esc_html( Recovery::store_address() ) . '</p><p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Unsubscribe', 'peptide-core' ) . '</a></p></body></html>';
	}

	/** Per-campaign lease plus atomic delivery claims. Stale in-flight sends are never silently retried. */
	public static function run_batch( int $id ): void {
		global $wpdb; $c = self::table(); $d = self::table( 'deliveries' ); $now = time();
		$campaign = self::get( $id );
		if ( ! $campaign || ! in_array( $campaign['status'], array( 'scheduled', 'running' ), true ) || (int) $campaign['scheduled_at'] > $now ) { return; }
		$ready = self::ready();
		if ( is_wp_error( $ready ) ) { $wpdb->update( $c, array( 'status' => 'paused', 'last_error' => $ready->get_error_message() ), array( 'id' => $id, 'status' => $campaign['status'] ) ); return; }
		$lease = bin2hex( random_bytes( 16 ) );
		if ( 1 !== $wpdb->query( $wpdb->prepare( "UPDATE $c SET lock_token=%s,lock_until=%d,status='running' WHERE id=%d AND status IN ('scheduled','running') AND (lock_until<%d OR lock_token='')", $lease, $now + 600, $id, $now ) ) ) { self::schedule_event( $id, time() + MINUTE_IN_SECONDS ); return; }
		try {
			$wpdb->query( $wpdb->prepare( "UPDATE $d SET status='uncertain',last_error=%s WHERE campaign_id=%d AND status='processing'", __( 'An interrupted send needs manual review; it will not be automatically resent.', 'peptide-core' ), $id ) );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $d WHERE campaign_id=%d AND status='pending' ORDER BY id ASC LIMIT %d", $id, self::BATCH ), ARRAY_A );
			foreach ( $rows as $delivery ) {
				$current = self::get( $id );
				if ( ! $current || 'running' !== $current['status'] || $lease !== $current['lock_token'] ) { break; }
				if ( is_wp_error( self::ready() ) ) { $wpdb->update( $c, array( 'status' => 'paused' ), array( 'id' => $id, 'lock_token' => $lease ) ); break; }
				$subscriber = self::subscriber( (int) $delivery['subscriber_id'] );
				if ( ! self::eligible( $subscriber ) ) { $wpdb->update( $d, array( 'status' => 'skipped', 'last_error' => __( 'Subscription is no longer confirmed and consenting.', 'peptide-core' ) ), array( 'id' => $delivery['id'], 'status' => 'pending' ) ); continue; }
				if ( 1 !== $wpdb->query( $wpdb->prepare( "UPDATE $d SET status='processing',attempts=attempts+1,claimed_at=%d WHERE id=%d AND status='pending'", time(), $delivery['id'] ) ) ) { continue; }
				$headers = array( 'Content-Type: text/html; charset=UTF-8', 'From: ' . sanitize_text_field( get_option( 'woocommerce_email_from_name' ) ) . ' <' . sanitize_email( get_option( 'woocommerce_email_from_address' ) ) . '>', 'List-Unsubscribe: <' . self::unsubscribe_url( $subscriber ) . '>' );
				$uncertain = false;
				try { $sent = wp_mail( $subscriber['email'], $campaign['subject'], self::message( $campaign, $subscriber ), $headers ); }
				catch ( \Throwable $e ) { $sent = false; $uncertain = true; }
				$wpdb->update( $d, array( 'status' => $sent ? 'sent' : ( $uncertain ? 'uncertain' : 'failed' ), 'sent_at' => $sent ? gmdate( 'Y-m-d H:i:s' ) : null, 'last_error' => $sent ? '' : ( $uncertain ? __( 'An interrupted send needs manual review; it will not be automatically resent.', 'peptide-core' ) : __( 'The mail transport did not accept this message.', 'peptide-core' ) ) ), array( 'id' => $delivery['id'], 'status' => 'processing' ) );
				// Renew between messages so another worker cannot claim the campaign during a slow batch.
				$wpdb->update( $c, array( 'lock_until' => time() + 600 ), array( 'id' => $id, 'lock_token' => $lease ) );
			}
			$stats = self::stats( $id );
			if ( ! $stats['pending'] ) { $wpdb->update( $c, array( 'status' => 'completed', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $id, 'status' => 'running', 'lock_token' => $lease ) ); }
			elseif ( 'running' === ( self::get( $id )['status'] ?? '' ) && ! self::schedule_event( $id, time() + MINUTE_IN_SECONDS ) ) { $wpdb->update( $c, array( 'status' => 'paused', 'last_error' => __( 'The background task could not be scheduled. Resume to try again.', 'peptide-core' ) ), array( 'id' => $id, 'status' => 'running' ) ); }
		} finally { $wpdb->update( $c, array( 'lock_token' => '', 'lock_until' => 0 ), array( 'id' => $id, 'lock_token' => $lease ) ); }
	}
	public static function unsubscribe_page(): void {
		if ( ! isset( $_GET['pc_campaign_unsubscribe'] ) ) { return; }
		nocache_headers(); header( 'X-Robots-Tag: noindex, nofollow' ); header( 'Referrer-Policy: no-referrer' );
		$id = absint( $_GET['pc_campaign_unsubscribe'] ); $token = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) ); $nonce = 'pc_campaign_unsubscribe_' . $id;
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), $nonce ) ) { wp_die( esc_html__( 'This form has expired. Reload the page and try again.', 'peptide-core' ), '', array( 'response' => 403 ) ); }
			$result = self::unsubscribe( $id, $token ); $text = is_wp_error( $result ) ? $result->get_error_message() : __( 'You have been unsubscribed from store campaigns.', 'peptide-core' );
			wp_die( '<p>' . esc_html( $text ) . '</p>', esc_html__( 'Unsubscribe', 'peptide-core' ), array( 'response' => is_wp_error( $result ) ? 400 : 200 ) );
		}
		$row = self::subscriber( $id );
		if ( ! $row || ! $row['unsubscribe_hash'] || ! hash_equals( self::unsubscribe_token( $row ), $token ) ) { wp_die( esc_html__( 'This unsubscribe link is invalid or has already been used.', 'peptide-core' ), '', array( 'response' => 400 ) ); }
		wp_die( '<form method="post" action="' . esc_url( self::unsubscribe_url( $row ) ) . '">' . wp_nonce_field( $nonce, '_wpnonce', false, false ) . '<p>' . esc_html__( 'Unsubscribe from store campaigns?', 'peptide-core' ) . '</p><button type="submit">' . esc_html__( 'Unsubscribe', 'peptide-core' ) . '</button></form>', esc_html__( 'Unsubscribe', 'peptide-core' ), array( 'response' => 200 ) );
	}
}
