<?php
/** Read-only header announcement binding to an existing public promotion. */
namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Campaign_Announcement {

	/**
	 * One promotion identity supplies the title, tiers and dates. No copied discount rules,
	 * automatic activity selection, option writes, activation or calendar assumptions.
	 * Missing source is the legacy manual mode; an unavailable binding stays unavailable.
	 */
	public static function for_header( array $settings, bool $is_home ): array {
		$source = $settings['announcementSource'] ?? 'manual';
		$out = array( 'version' => 1, 'visible' => false, 'source' => 'promotion' === $source ? 'promotion' : 'manual', 'promotionId' => null, 'title' => '', 'text' => '', 'html' => '', 'status' => null, 'tiers' => array(), 'starts' => null, 'ends' => null, 'url' => '' );
		if ( true !== ( $settings['announcement'] ?? false ) || ( ! empty( $settings['announcementHomeOnly'] ) && ! $is_home ) || ! in_array( $source, array( 'manual', 'promotion' ), true ) ) { return $out; }
		if ( 'manual' === $source ) {
			$html = $settings['announcementText'] ?? '';
			$out['html'] = is_string( $html ) ? wp_kses_post( $html ) : '';
			$out['visible'] = '' !== trim( $out['html'] );
			return $out;
		}
		$id = Modules\Records::sanitize( $settings['announcementPromotion'] ?? null );
		if ( ! $id || Promotions::CPT !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) || '' !== (string) get_post_field( 'post_password', $id ) ) { return $out; }
		try { $promotion = Promotions::get_public( $id ); } catch ( \Throwable $unavailable ) { return $out; }
		if ( ! $promotion || ! in_array( $promotion['status'] ?? '', array( 'active', 'upcoming' ), true ) || empty( $promotion['tiers'] ) ) { return $out; }
		$out['promotionId'] = $id;
		$out['title'] = self::plain( $promotion['title'] ?? '' );
		$out['text'] = self::plain( $promotion['text'] ?? '' );
		$out['status'] = $promotion['status'];
		$out['starts'] = $promotion['starts'] ?? null;
		$out['ends'] = $promotion['ends'] ?? null;
		$out['url'] = esc_url_raw( (string) ( $promotion['url'] ?? '' ) );
		foreach ( $promotion['tiers'] as $tier ) {
			$out['tiers'][] = array( 'min' => $tier['min'], 'value' => $tier['value'], 'text' => self::plain( $tier['text'] ?? '' ) );
		}
		$parts = array_filter( array_column( $out['tiers'], 'text' ), static fn( $value ) => '' !== $value );
		if ( 'upcoming' === $out['status'] ) {
			$starts = self::plain( $promotion['starts_text'] ?? '' );
			array_unshift( $parts, '' !== $starts ? sprintf( __( 'Starts %s', 'peptide-core' ), $starts ) : __( 'Coming soon', 'peptide-core' ) );
		}
		$out['html'] = ( '' !== $out['title'] ? '<strong>' . esc_html( $out['title'] ) . '</strong>' : '' ) . ( $parts ? '<span> ' . esc_html( implode( ' · ', $parts ) ) . '</span>' : '' );
		$out['visible'] = '' !== $out['html'];
		return $out;
	}

	private static function plain( $value ): string {
		return is_string( $value ) ? html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES, 'UTF-8' ) : '';
	}
}
