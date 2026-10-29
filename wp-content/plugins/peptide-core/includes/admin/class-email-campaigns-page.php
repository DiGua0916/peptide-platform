<?php
/** Campaign drafts and deliberate sending controls. */
namespace PeptideCore\Admin;

use PeptideCore\Email_Campaigns as Campaigns;

defined( 'ABSPATH' ) || exit;

final class Email_Campaigns_Page {
	public const SLUG = 'pc-promotions-campaigns';
	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 62 );
		add_action( 'admin_post_pc_campaign_save', array( self::class, 'save' ) );
		add_action( 'admin_post_pc_campaign_control', array( self::class, 'control' ) );
		add_action( 'admin_post_pc_campaign_preview', array( self::class, 'preview' ) );
		add_action( 'admin_post_pc_campaign_enabled', array( self::class, 'enabled' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
	}
	public static function menu(): void { add_submenu_page( 'pc-promotions', __( 'Email campaigns', 'peptide-core' ), __( 'Email campaigns', 'peptide-core' ), Campaigns::CAP, self::SLUG, array( self::class, 'render' ) ); }
	public static function assets(): void {
		if ( self::SLUG === sanitize_key( wp_unslash( $_GET['page'] ?? '' ) ) ) { Marketing::enqueue_assets(); wp_enqueue_script( 'pc-email-campaigns-admin', plugins_url( 'assets/email-campaigns-admin.js', PEPTIDE_CORE_FILE ), array(), (string) filemtime( PEPTIDE_CORE_DIR . '/assets/email-campaigns-admin.js' ), true ); }
	}
	private static function authorize( string $nonce = '' ): void {
		if ( ! current_user_can( Campaigns::CAP ) ) { wp_die( esc_html__( 'You cannot manage email campaigns.', 'peptide-core' ), '', array( 'response' => 403 ) ); }
		if ( $nonce ) { check_admin_referer( $nonce ); }
		if ( $nonce && 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( esc_html__( 'Use the campaign form.', 'peptide-core' ), '', array( 'response' => 405 ) ); }
	}
	private static function back( $result, int $id = 0 ): void {
		if ( is_wp_error( $result ) ) { wp_die( '<p>' . esc_html( $result->get_error_message() ) . '</p><p><a href="' . esc_url( Marketing::url( self::SLUG, $id ? array( 'edit' => $id ) : array() ) ) . '">' . esc_html__( 'Return to campaigns', 'peptide-core' ) . '</a></p>', '', array( 'response' => 400 ) ); }
		wp_safe_redirect( Marketing::url( self::SLUG, $id ? array( 'edit' => $id, 'saved' => 1 ) : array( 'saved' => 1 ) ) ); exit;
	}
	public static function save(): void { self::authorize( 'pc_campaign_save' ); $id = absint( $_POST['id'] ?? 0 ); $result = Campaigns::save( $id, wp_unslash( $_POST ) ); self::back( $result, is_wp_error( $result ) ? $id : $result ); }
	public static function enabled(): void {
		self::authorize( 'pc_campaign_enabled' );
		update_option( Campaigns::ENABLED, ! empty( $_POST['enabled'] ), false );
		self::back( true );
	}
	public static function control(): void {
		$id = absint( $_POST['id'] ?? 0 ); self::authorize( 'pc_campaign_control_' . $id ); $op = sanitize_key( $_POST['op'] ?? '' );
		if ( in_array( $op, array( 'schedule', 'resume', 'retry' ), true ) && empty( $_POST['confirm_sending'] ) ) { self::back( new \WP_Error( 'confirm', __( 'Confirm that you want to send this campaign to consenting subscribers.', 'peptide-core' ) ), $id ); }
		if ( 'schedule' === $op ) {
			$raw = sanitize_text_field( wp_unslash( $_POST['when'] ?? '' ) );
			$date = $raw ? date_create_immutable( $raw, wp_timezone() ) : null;
			if ( $raw && ! $date ) { self::back( new \WP_Error( 'time', __( 'Enter a valid sending date and time.', 'peptide-core' ) ), $id ); }
			$result = Campaigns::schedule( $id, $date ? $date->getTimestamp() : time() );
		} else { $result = Campaigns::control( $id, $op ); }
		self::back( $result, $id );
	}
	public static function preview(): void {
		self::authorize( 'pc_campaign_save' );
		$subject = sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) ); $body = wp_kses_post( wp_unslash( $_POST['body'] ?? '' ) );
		$html = Campaigns::message( array( 'subject' => $subject, 'body' => $body ) );
		wp_die( '<p>' . esc_html__( 'Preview only. No email has been sent.', 'peptide-core' ) . '</p><iframe title="' . esc_attr__( 'Campaign preview', 'peptide-core' ) . '" sandbox="" style="width:100%;height:65vh;border:1px solid #ccc" srcdoc="' . esc_attr( $html ) . '"></iframe>', esc_html__( 'Campaign preview', 'peptide-core' ), array( 'response' => 200, 'back_link' => true ) );
	}
	public static function labels(): array { return array( 'draft' => __( 'Draft', 'peptide-core' ), 'scheduled' => __( 'Scheduled', 'peptide-core' ), 'running' => __( 'Sending in batches', 'peptide-core' ), 'paused' => __( 'Paused', 'peptide-core' ), 'cancelled' => __( 'Cancelled', 'peptide-core' ), 'completed' => __( 'Finished processing', 'peptide-core' ) ); }
	public static function render(): void {
		self::authorize(); global $wpdb;
		echo '<div class="wrap pc-mk"><h1>' . esc_html__( 'Email campaigns', 'peptide-core' ) . '</h1><p>' . esc_html__( 'Send only to confirmed subscriptions with recorded consent. Sent means the WordPress mail transport accepted the message, not inbox delivery. Pause or cancel stops queued work after the current message.', 'peptide-core' ) . '</p>';
		$ready = Campaigns::ready(); if ( is_wp_error( $ready ) ) { echo '<div class="notice notice-warning"><p>' . esc_html( $ready->get_error_message() ) . '</p></div>'; }
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="pc_campaign_enabled">' . wp_nonce_field( 'pc_campaign_enabled', '_wpnonce', false, false ) . '<label><input type="checkbox" name="enabled" value="1" ' . checked( (bool) get_option( Campaigns::ENABLED ), true, false ) . '> ' . esc_html__( 'Allow campaign sending through the configured WordPress mail transport', 'peptide-core' ) . '</label> <button class="button">' . esc_html__( 'Save sending preference', 'peptide-core' ) . '</button></form>';
		$id = absint( $_GET['edit'] ?? 0 ); $row = $id ? Campaigns::get( $id ) : null;
		if ( isset( $_GET['new'] ) || $row ) {
			$row = $row ?: array( 'id' => 0, 'title' => '', 'subject' => '', 'body' => '', 'audience_source' => '', 'status' => 'draft', 'scheduled_at' => 0, 'last_error' => '' );
			if ( 'draft' === $row['status'] ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="pc_campaign_save"><input type="hidden" name="id" value="' . (int) $row['id'] . '">' . wp_nonce_field( 'pc_campaign_save', '_wpnonce', false, false ) . '<p><label>' . esc_html__( 'Campaign name', 'peptide-core' ) . '<br><input class="large-text" name="title" maxlength="200" required value="' . esc_attr( $row['title'] ) . '"></label></p><p><label>' . esc_html__( 'Email subject', 'peptide-core' ) . '<br><input class="large-text" name="subject" maxlength="200" required value="' . esc_attr( $row['subject'] ) . '"></label></p><p><label>' . esc_html__( 'Subscription source (exact path, empty means all confirmed subscribers)', 'peptide-core' ) . '<br><input class="large-text" name="audience_source" maxlength="500" value="' . esc_attr( $row['audience_source'] ) . '"></label></p><p><label for="pc_campaign_body">' . esc_html__( 'Message', 'peptide-core' ) . '</label></p>';
				wp_editor( $row['body'], 'pc_campaign_body', array( 'textarea_name' => 'body', 'textarea_rows' => 14, 'media_buttons' => current_user_can( 'upload_files' ) ) );
				echo '<p>' . esc_html__( 'The store address, consent explanation and unsubscribe link are appended automatically.', 'peptide-core' ) . '</p><button class="button button-primary">' . esc_html__( 'Save draft', 'peptide-core' ) . '</button> <button class="button" name="action" value="pc_campaign_preview" formtarget="_blank">' . esc_html__( 'Preview without sending', 'peptide-core' ) . '</button></form>';
			} else { echo '<h2>' . esc_html( $row['title'] ) . '</h2><p>' . esc_html( $row['subject'] ) . '</p>'; }
			if ( $row['id'] ) {
				$stats = Campaigns::stats( (int) $row['id'] );
				echo '<h2>' . esc_html( self::labels()[ $row['status'] ] ?? $row['status'] ) . '</h2><p>' . esc_html( sprintf( __( 'Pending: %1$d · Accepted: %2$d · Failed: %3$d · Skipped: %4$d · Needs review: %5$d', 'peptide-core' ), $stats['pending'], $stats['sent'], $stats['failed'], $stats['skipped'], $stats['uncertain'] + $stats['processing'] ) ) . '</p>';
				if ( $row['last_error'] ) { echo '<p>' . esc_html( $row['last_error'] ) . '</p>'; }
				$delivery_labels = array( 'pending' => __( 'Pending', 'peptide-core' ), 'processing' => __( 'Processing', 'peptide-core' ), 'sent' => __( 'Accepted by mail transport', 'peptide-core' ), 'failed' => __( 'Failed', 'peptide-core' ), 'skipped' => __( 'Skipped', 'peptide-core' ), 'uncertain' => __( 'Needs manual review', 'peptide-core' ) );
				echo '<h3>' . esc_html__( 'Recent recipient results (up to 100)', 'peptide-core' ) . '</h3><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Email address', 'peptide-core' ) . '</th><th>' . esc_html__( 'Status', 'peptide-core' ) . '</th><th>' . esc_html__( 'Attempts', 'peptide-core' ) . '</th><th>' . esc_html__( 'Result details', 'peptide-core' ) . '</th></tr></thead><tbody>';
				$deliveries = $wpdb->get_results( $wpdb->prepare( 'SELECT d.status,d.attempts,d.last_error,s.email FROM ' . Campaigns::table( 'deliveries' ) . ' d LEFT JOIN ' . \PeptideCore\Subscriptions::table() . ' s ON s.id=d.subscriber_id WHERE d.campaign_id=%d ORDER BY d.id DESC LIMIT 100', $row['id'] ), ARRAY_A );
				foreach ( $deliveries as $delivery ) { echo '<tr><td>' . esc_html( $delivery['email'] ?: __( 'Deleted subscription', 'peptide-core' ) ) . '</td><td>' . esc_html( $delivery_labels[ $delivery['status'] ] ?? $delivery['status'] ) . '</td><td>' . (int) $delivery['attempts'] . '</td><td>' . esc_html( $delivery['last_error'] ) . '</td></tr>'; } echo '</tbody></table>';
				$ops = array(); if ( 'draft' === $row['status'] ) { $ops['schedule'] = __( 'Schedule campaign', 'peptide-core' ); }
				if ( in_array( $row['status'], array( 'scheduled', 'running' ), true ) ) { $ops['pause'] = __( 'Pause', 'peptide-core' ); }
				if ( 'paused' === $row['status'] ) { $ops['resume'] = __( 'Resume', 'peptide-core' ); }
				if ( in_array( $row['status'], array( 'paused', 'completed' ), true ) && $stats['failed'] ) { $ops['retry'] = __( 'Retry failed messages (up to 3 attempts)', 'peptide-core' ); }
				if ( in_array( $row['status'], array( 'scheduled', 'running', 'paused' ), true ) ) { $ops['cancel'] = __( 'Cancel campaign', 'peptide-core' ); }
				if ( $ops ) {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-pc-campaign-control><input type="hidden" name="action" value="pc_campaign_control"><input type="hidden" name="id" value="' . (int) $row['id'] . '">' . wp_nonce_field( 'pc_campaign_control_' . $row['id'], '_wpnonce', false, false );
					if ( isset( $ops['schedule'] ) ) { echo '<p><label>' . esc_html__( 'Send at (store time, empty means now)', 'peptide-core' ) . ' <input type="datetime-local" name="when"></label></p>'; }
					echo '<p><label><input type="checkbox" name="confirm_sending" value="1"> ' . esc_html__( 'I confirm sending to the confirmed, consenting subscriber audience.', 'peptide-core' ) . '</label></p>';
					foreach ( $ops as $op => $label ) { echo '<button class="button" name="op" value="' . esc_attr( $op ) . '">' . esc_html( $label ) . '</button> '; } echo '</form>';
				}
			}
		}
		echo '<p><a class="button" href="' . esc_url( Marketing::url( self::SLUG, array( 'new' => 1 ) ) ) . '">' . esc_html__( 'New campaign', 'peptide-core' ) . '</a></p><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Campaign name', 'peptide-core' ) . '</th><th>' . esc_html__( 'Status', 'peptide-core' ) . '</th><th>' . esc_html__( 'Scheduled time', 'peptide-core' ) . '</th></tr></thead><tbody>';
		foreach ( $wpdb->get_results( 'SELECT id,title,status,scheduled_at FROM ' . Campaigns::table() . ' ORDER BY id DESC LIMIT 100', ARRAY_A ) as $campaign ) { echo '<tr><td><a href="' . esc_url( Marketing::url( self::SLUG, array( 'edit' => $campaign['id'] ) ) ) . '">' . esc_html( $campaign['title'] ) . '</a></td><td>' . esc_html( self::labels()[ $campaign['status'] ] ?? $campaign['status'] ) . '</td><td>' . esc_html( $campaign['scheduled_at'] ? wp_date( 'Y-m-d H:i', (int) $campaign['scheduled_at'] ) : '—' ) . '</td></tr>'; }
		echo '</tbody></table></div>';
	}
}
