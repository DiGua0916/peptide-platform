<?php
/** Read-only countdown bound to one native promotion; never owns discount rules. */
namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Campaign_Countdown {
	public const OPTION = 'pc_campaign_countdown_v1';
	public const POSITIONS = array( 'checkout', 'product' );

	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_if_enabled' ) );
	}
	public static function defaults(): array {
		return array( 'version' => 1, 'enabled' => false, 'promotionId' => 0, 'checkout' => true, 'product' => true );
	}
	/** Strict shared storage contract. A read of damaged storage fails closed. */
	public static function normalize( $input ): ?array {
		if ( ! is_array( $input ) || ( $input && array_is_list( $input ) ) || array_diff( array_keys( $input ), array_keys( self::defaults() ) ) ) { return null; }
		$out = array_replace( self::defaults(), $input );
		if ( 1 !== $out['version'] || ! is_int( $out['promotionId'] ) || $out['promotionId'] < 0 || $out['promotionId'] > 2147483647 ) { return null; }
		foreach ( array( 'enabled', 'checkout', 'product' ) as $key ) { if ( ! is_bool( $out[ $key ] ) ) { return null; } }
		return $out;
	}
	public static function settings(): array {
		return self::normalize( get_option( self::OPTION, array() ) ) ?? self::defaults();
	}
	private static function epoch( $value ): ?int {
		return is_int( $value ) && $value > 0 && $value <= 253402300799 ? $value : null;
	}
	/** No private/draft/password-protected promotion metadata can enter a public DTO. */
	private static function source( int $id, int $now ): ?array {
		if ( ! $id || Promotions::CPT !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) || '' !== (string) get_post_field( 'post_password', $id ) ) { return null; }
		try {
			$config = Promotions::config( $id );
			if ( true !== ( $config['public'] ?? false ) || ! is_array( $config['tiers'] ?? null ) || ! $config['tiers'] || count( $config['tiers'] ) > Promotions::MAX_TIERS ) { return null; }
			foreach ( $config['tiers'] as $tier ) {
				if ( ! is_array( $tier ) || ! is_numeric( $tier['min'] ?? null ) || ! is_numeric( $tier['value'] ?? null ) || ! is_finite( (float) $tier['min'] ) || ! is_finite( (float) $tier['value'] ) || (float) $tier['min'] <= 0 || (float) $tier['value'] <= 0 ) { return null; }
			}
			$view = Promotions::get_public( $id );
			if ( ! $view || empty( $view['tiers'] ) ) { return null; }
			$announcement = Campaign_Announcement::for_header( array( 'announcement' => true, 'announcementSource' => 'promotion', 'announcementPromotion' => $id ), false );
			$starts = self::epoch( $config['start'] ?? null );
			$ends = self::epoch( $config['end'] ?? null );
			if ( ( ! empty( $config['start'] ) && null === $starts ) || ( ! empty( $config['end'] ) && null === $ends ) || ( $starts && $ends && $ends <= $starts ) ) { return null; }
			return array(
				'id' => $id,
				'title' => html_entity_decode( wp_strip_all_tags( (string) ( $announcement['visible'] ? $announcement['title'] : $view['title'] ) ), ENT_QUOTES, 'UTF-8' ),
				'status' => Promotions::status( $id, $now ),
				'starts' => $starts, 'ends' => $ends,
				'tiers' => array_map( static fn( $tier ) => array( 'min' => $tier['min'], 'value' => $tier['value'], 'text' => html_entity_decode( wp_strip_all_tags( (string) $tier['text'] ), ENT_QUOTES, 'UTF-8' ) ), $view['tiers'] ),
			);
		} catch ( \Throwable $unavailable ) { return null; }
	}
	/** Public selection candidates only; never guesses a Black Friday ID or changes a record. */
	public static function candidates( int $selected = 0 ): array {
		$ids = get_posts( array( 'post_type' => Promotions::CPT, 'post_status' => 'publish', 'posts_per_page' => 300, 'fields' => 'ids', 'orderby' => array( 'date' => 'DESC', 'ID' => 'DESC' ), 'no_found_rows' => true ) );
		if ( $selected && ! in_array( $selected, array_map( 'intval', $ids ), true ) ) { $ids[] = $selected; }
		$rows = array(); $now = time();
		foreach ( $ids as $id ) {
			$source = self::source( (int) $id, $now );
			if ( $source ) { $source['hasDeadline'] = null !== $source['ends']; $rows[] = $source; }
		}
		return $rows;
	}
	/** Customer label only: keep stored and admin candidate titles exactly as supplied. */
	private static function customer_title( string $title ): string {
		if ( ! in_array( trim( $title ), array( '黑色星期五', '黑五' ), true ) ) { return $title; }
		$language = class_exists( Storefront_Translation::class ) ? Storefront_Translation::code() : 'en';
		return 'es' === $language ? 'Viernes Negro' : 'Black Friday';
	}
	public static function for_position( string $position, ?array $settings = null, ?int $now = null ): array {
		$now = $now ?? time();
		$out = array( 'version' => 1, 'position' => $position, 'enabled' => false, 'visible' => false, 'state' => 'disabled', 'promotionId' => null, 'title' => '', 'tiers' => array(), 'starts' => null, 'ends' => null, 'target' => null, 'serverNow' => $now );
		$c = null === $settings ? self::settings() : self::normalize( $settings );
		if ( ! $c || ! in_array( $position, self::POSITIONS, true ) || ! $c['enabled'] || ! $c[ $position ] ) { return $out; }
		$out['enabled'] = true;
		$out['state'] = $c['promotionId'] ? 'unavailable' : 'unconfigured';
		$source = self::source( $c['promotionId'], $now );
		if ( ! $source ) { return $out; }
		$out['promotionId'] = $source['id'];
		foreach ( array( 'title', 'tiers', 'starts', 'ends' ) as $key ) { $out[ $key ] = $source[ $key ]; }
		$out['title'] = self::customer_title( $out['title'] );
		if ( ! in_array( $source['status'], array( 'active', 'upcoming', 'ended' ), true ) ) { $out['state'] = 'unavailable'; return $out; }
		$out['state'] = $source['ends'] ? $source['status'] : 'untimed';
		$out['target'] = 'upcoming' === $out['state'] ? $source['starts'] : ( 'active' === $out['state'] ? $source['ends'] : null );
		$out['visible'] = null !== $out['target'] && $out['target'] > $now && in_array( $out['state'], array( 'active', 'upcoming' ), true );
		return $out;
	}
	public static function register_routes(): void {
		register_rest_route( 'peptide/v1', '/campaign-countdown', array( 'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => array( self::class, 'response' ) ) );
	}
	/** Anonymous read: no cart/session, payment, visitor tracking or configuration writes. */
	public static function response(): \WP_REST_Response {
		$now = time(); $settings = self::settings();
		$response = new \WP_REST_Response( array( 'version' => 1, 'serverNow' => $now, 'checkout' => self::for_position( 'checkout', $settings, $now ), 'product' => self::for_position( 'product', $settings, $now ) ) );
		$response->header( 'Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Vary', 'Cookie' );
		return $response;
	}
	public static function enqueue_assets(): void {
		foreach ( array( 'css', 'js' ) as $ext ) {
			$file = 'assets/campaign-countdown.' . $ext;
			$version = (string) filemtime( PEPTIDE_CORE_DIR . '/' . $file );
			if ( 'css' === $ext ) { wp_enqueue_style( 'pc-campaign-countdown', plugins_url( $file, PEPTIDE_CORE_FILE ), array(), $version ); }
			else { wp_enqueue_script( 'pc-campaign-countdown', plugins_url( $file, PEPTIDE_CORE_FILE ), array(), $version, true ); }
		}
	}
	/** Load before wp_head, including pages that can open a product dialog later. */
	public static function enqueue_if_enabled(): void {
		$settings = self::settings();
		if ( $settings['enabled'] && ( $settings['checkout'] || $settings['product'] ) ) { self::enqueue_assets(); }
	}
	public static function render( string $position, ?array $dto = null ): string {
		if ( ! in_array( $position, self::POSITIONS, true ) ) { return ''; }
		$dto = $dto ?? self::for_position( $position );
		// Keep a hidden mount point so a later read can notice a newly enabled activity.
		self::enqueue_assets();
		return '<div class="pc-campaign-countdown pc-campaign-countdown--' . esc_attr( $position ) . '" data-pc-campaign-countdown="' . esc_attr( $position ) . '" data-countdown-config="' . esc_attr( wp_json_encode( $dto, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) ) . '" data-countdown-endpoint="' . esc_url( rest_url( 'peptide/v1/campaign-countdown' ) ) . '" hidden></div>';
	}
}
