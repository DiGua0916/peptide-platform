<?php
/** Isolated actual helper/schema checks. No WordPress bootstrap or database writes. */
namespace {
	define( 'ABSPATH', __DIR__ );
	$GLOBALS['fixtures'] = array(); $GLOBALS['reads'] = 0;
	function __( $text, $domain = '' ) { return $text; }
	function get_bloginfo( $key ) { return 'Synthetic store'; }
	function wp_strip_all_tags( $text ) { return strip_tags( $text ); }
	function wp_kses_post( $text ) { return strip_tags( $text, '<strong><em><span><a><p><br>' ); }
	function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
	function esc_url_raw( $text ) { return str_starts_with( $text, 'https://' ) ? $text : ''; }
	function get_post_type( $id ) { return $GLOBALS['fixtures'][ $id ]['post_type'] ?? false; }
	function get_post_status( $id ) { return $GLOBALS['fixtures'][ $id ]['post_status'] ?? false; }
	function get_post_field( $key, $id ) { return $GLOBALS['fixtures'][ $id ][ $key ] ?? ''; }
	function sanitize_text_field( $text ) { return trim( strip_tags( $text ) ); }
	function sanitize_email( $text ) { return filter_var( $text, FILTER_SANITIZE_EMAIL ); }
	function is_email( $text ) { return filter_var( $text, FILTER_VALIDATE_EMAIL ); }
	function update_option( ...$args ) { throw new \RuntimeException( 'Unexpected option write' ); }
	function update_post_meta( ...$args ) { throw new \RuntimeException( 'Unexpected meta write' ); }
	function wp_update_post( ...$args ) { throw new \RuntimeException( 'Unexpected post write' ); }
}
namespace PeptideCore {
	final class Promotions {
		const CPT = 'pc_promotion';
		public static function get_public( int $id ): ?array {
			++$GLOBALS['reads']; $f = $GLOBALS['fixtures'][ $id ] ?? array();
			if ( ! empty( $f['throw'] ) ) { throw new \RuntimeException( 'Synthetic read failure' ); }
			return ! empty( $f['public'] ) ? $f['dto'] : null;
		}
	}
}
namespace {
	require dirname( __DIR__ ) . '/includes/modules/class-fields.php';
	require dirname( __DIR__ ) . '/includes/modules/class-records.php';
	require dirname( __DIR__ ) . '/includes/modules/class-site-design.php';
	require dirname( __DIR__ ) . '/includes/class-campaign-announcement.php';
	require dirname( __DIR__ ) . '/includes/class-support-contacts.php';
	use PeptideCore\Campaign_Announcement;
	use PeptideCore\Modules\Fields;
	use PeptideCore\Modules\Site_Design;
	$pass = 0;
	$ok = static function( string $label, bool $condition ) use ( &$pass ) { if ( ! $condition ) { throw new \RuntimeException( 'FAIL ' . $label ); } ++$pass; echo 'PASS ' . $label . "\n"; };
	$dto = array( 'id' => 41, 'title' => 'Black Friday', 'text' => 'Synthetic fixture', 'status' => 'active', 'tiers' => array( array( 'min' => 300.0, 'value' => 25.0, 'text' => 'Spend $300, get $25 off' ) ), 'starts' => null, 'ends' => null, 'starts_text' => '', 'url' => 'https://fixture.invalid/offers/?promotion=41', 'private_sentinel' => 'not public' );
	$base = array( 'post_type' => 'pc_promotion', 'post_status' => 'publish', 'post_password' => '', 'public' => true, 'dto' => $dto );
	$GLOBALS['fixtures'][41] = $base;
	$settings = array( 'announcement' => true, 'announcementHomeOnly' => true, 'announcementSource' => 'promotion', 'announcementPromotion' => 41, 'announcementText' => 'Old manual content' );
	$view = Campaign_Announcement::for_header( $settings, true );
	$ok( 'explicit valid public activity is visible', $view['visible'] && 41 === $view['promotionId'] );
	$ok( 'one existing activity owns title, tier values and dates', 'Black Friday' === $view['title'] && $dto['tiers'] === $view['tiers'] && null === $view['starts'] && null === $view['ends'] );
	$ok( 'no internal DTO keys are copied', ! isset( $view['private_sentinel'] ) );
	$ok( 'real tier text is in escaped server markup', str_contains( $view['html'], 'Spend $300, get $25 off' ) && ! str_contains( $view['html'], 'Old manual content' ) );
	$ok( 'only selected promotion is read once', 1 === $GLOBALS['reads'] );
	$ok( 'header disabled hides bound promotion', ! Campaign_Announcement::for_header( array_replace( $settings, array( 'announcement' => false ) ), true )['visible'] );
	$ok( 'home-only does not show on checkout or other page', ! Campaign_Announcement::for_header( $settings, false )['visible'] );
	$ok( 'non-home allowed only through existing switch', Campaign_Announcement::for_header( array_replace( $settings, array( 'announcementHomeOnly' => false ) ), false )['visible'] );
	$ok( 'disabled/home-only early exits do not query promotion', 2 === $GLOBALS['reads'] );
	foreach ( array( null, 0, -1, true, '41.0', array( 'id' => 41 ), '41x', '99999999999999999999999999999999999999' ) as $invalid ) {
		$ok( 'invalid or unavailable binding stays hidden ' . json_encode( $invalid ), ! Campaign_Announcement::for_header( array_replace( $settings, array( 'announcementPromotion' => $invalid ) ), true )['visible'] );
	}
	$ok( 'string ID from schema resolves same record', Campaign_Announcement::for_header( array_replace( $settings, array( 'announcementPromotion' => '41' ) ), true )['visible'] );
	foreach ( array( array( 'post_status' => 'draft' ), array( 'post_status' => 'private' ), array( 'post_status' => 'trash' ), array( 'post_type' => 'page' ), array( 'post_password' => 'synthetic-password' ), array( 'public' => false ), array( 'throw' => true ) ) as $invalid ) {
		$GLOBALS['fixtures'][41] = array_replace( $base, $invalid ); $hidden = Campaign_Announcement::for_header( $settings, true );
		$ok( 'private/offline/read-failure binding does not expose title ' . json_encode( array_keys( $invalid ) ), ! $hidden['visible'] && '' === $hidden['title'] && null === $hidden['promotionId'] && '' === $hidden['html'] );
	}
	foreach ( array( 'ended', 'off', 'draft', '' ) as $state ) {
		$GLOBALS['fixtures'][41] = array_replace( $base, array( 'dto' => array_replace( $dto, array( 'status' => $state ) ) ) );
		$ok( 'unavailable status does not fallback to old manual content ' . $state, ! Campaign_Announcement::for_header( $settings, true )['visible'] );
	}
	$GLOBALS['fixtures'][41] = array_replace( $base, array( 'dto' => array_replace( $dto, array( 'status' => 'upcoming', 'starts' => '2030-11-01T00:00:00+00:00', 'starts_text' => 'Nov 1, 2030' ) ) ) );
	$view = Campaign_Announcement::for_header( $settings, true );
	$ok( 'upcoming is marked rather than falsely active', 'upcoming' === $view['status'] && str_contains( $view['html'], 'Starts Nov 1, 2030' ) && '2030-11-01T00:00:00+00:00' === $view['starts'] );
	$GLOBALS['fixtures'][41] = array_replace( $base, array( 'dto' => array_replace( $dto, array( 'title' => '<b>Black &amp; Friday</b>', 'tiers' => array( array( 'min' => 300, 'value' => 25, 'text' => '&lt;script&gt;unsafe&lt;/script&gt;' ) ) ) ) ) );
	$view = Campaign_Announcement::for_header( $settings, true );
	$ok( 'activity markup is escaped, including encoded tags', str_contains( $view['html'], 'Black &amp; Friday' ) && ! str_contains( $view['html'], '<script>' ) && str_contains( $view['html'], '&lt;script&gt;' ) );
	$GLOBALS['fixtures'][41] = array_replace( $base, array( 'dto' => array_replace( $dto, array( 'tiers' => array() ) ) ) );
	$ok( 'empty tier source does not generate a false offer', ! Campaign_Announcement::for_header( $settings, true )['visible'] );
	$legacy = array( 'announcement' => true, 'announcementText' => '<strong>Existing manual</strong>' );
	$ok( 'missing mode preserves original manual HTML', '<strong>Existing manual</strong>' === Campaign_Announcement::for_header( $legacy, true )['html'] );
	$ok( 'legacy manual mode needs no promotion', null === Campaign_Announcement::for_header( $legacy, true )['promotionId'] );
	$ok( 'empty manual avoids blank announcement bar', ! Campaign_Announcement::for_header( array_replace( $legacy, array( 'announcementText' => '' ) ), true )['visible'] );
	$ok( 'unknown source hides rather than inventing a binding', ! Campaign_Announcement::for_header( array_replace( $settings, array( 'announcementSource' => 'auto' ) ), true )['visible'] );
	$defs = Site_Design::definitions( array() ); $fields = array_column( $defs['site_header']['settings'], null, 'key' );
	$ok( 'header has same known site_header identity and single render', $defs['site_header']['singleRender'] && ! $defs['site_header']['multiple'] );
	$ok( 'old headers default manual source', 'manual' === Fields::default_value( $fields['announcementSource'] ) );
	$ok( 'activity selection reuses record resource picker', 'record' === $fields['announcementPromotion']['type'] && 'promotion' === $fields['announcementPromotion']['resource'] );
	$ok( 'source schema permits promotion only explicitly', 'promotion' === Fields::sanitize( $fields['announcementSource'], 'promotion' ) && 'manual' === Fields::sanitize( $fields['announcementSource'], 'auto' ) );
	$ok( 'record schema keeps valid stale identity without auto-select', 41 === Fields::sanitize( $fields['announcementPromotion'], '41' ) && 999 === Fields::sanitize( $fields['announcementPromotion'], '999' ) && null === Fields::sanitize( $fields['announcementPromotion'], array( 41 ) ) );
	$ok( 'new binding field respects existing visible conditions', array( 'announcement' => true, 'announcementSource' => 'promotion' ) === $fields['announcementPromotion']['show_if'] );
	$ok( 'non-announcement header fields and footer remain defined', isset( $fields['logo'], $fields['menuId'], $fields['secondaryMenuId'], $defs['site_footer'], $defs['site_styles'] ) );
	$contacts = array_replace( \PeptideCore\Support_Contacts::defaults(), array( 'enabled' => true, 'whatsapp' => '+1 555 000 1234', 'message' => 'Hi & order?' ) );
	$support = \PeptideCore\Support_Contacts::public_view( $contacts );
	$ok( 'existing per-store WhatsApp channel is reusable', $support['enabled'] && 1 === count( $support['channels'] ) && 'whatsapp' === $support['channels'][0]['type'] );
	$ok( 'existing greeting is URL encoded without external redirect', 'https://wa.me/15550001234?text=Hi%20%26%20order%3F' === $support['channels'][0]['url'] );
	$ok( 'disabled support exposes no stale channel', array() === \PeptideCore\Support_Contacts::public_view( array_replace( $contacts, array( 'enabled' => false ) ) )['channels'] );
	$ok( 'unbound store does not borrow another store channel', ! \PeptideCore\Support_Contacts::public_view( \PeptideCore\Support_Contacts::defaults() )['enabled'] );
	$ok( 'invalid WhatsApp stays noninteractive', ! \PeptideCore\Support_Contacts::public_view( array_replace( $contacts, array( 'whatsapp' => 'not configured' ) ) )['enabled'] );
	$ok( 'support placement remains the current valid per-store setting', 'left' === \PeptideCore\Support_Contacts::public_view( array_replace( $contacts, array( 'position' => 'left' ) ) )['position'] );
	$ok( 'unsupported placement falls back to existing right position', 'right' === \PeptideCore\Support_Contacts::public_view( array_replace( $contacts, array( 'position' => 'floating-outside' ) ) )['position'] );
	echo "RESULT {$pass} PASS / 0 FAIL (isolated helper/schema; not live WordPress)\n";
}
