<?php
/** Optional bounded native-search observation; rejected raw text is never retained. */
namespace PeptideCore\Tracking;
defined( 'ABSPATH' ) || exit;
final class Search_Context {
	/** Source normalization is deliberately narrower than the storefront's search input. */
	public static function source( $raw ): array {
		$filtered = array( 'version' => 1, 'status' => 'filtered' );
		if ( ! is_string( $raw ) || 1 !== preg_match( '//u', $raw ) || preg_match( '/[\x00-\x1f\x7f-\x9f@:\/\\\\?&%#<>=]/u', $raw ) || preg_match_all( '/\p{N}/u', $raw ) >= 7 ) { return $filtered; }
		$term = mb_strtolower( trim( preg_replace( '/[\p{Z}\s]+/u', ' ', $raw ) ), 'UTF-8' );
		if ( '' === $term ) { return array( 'version' => 1, 'status' => 'empty' ); }
		if ( preg_match( '/(?:^|[^\p{L}\p{M}\p{N}])[\p{L}\p{N}-]+(?:\.[\p{L}\p{N}-]+)*\.[\p{L}]{2,}(?:$|[^\p{L}\p{M}\p{N}])/u', $term ) ) { return $filtered; }
		if ( mb_strlen( $term, 'UTF-8' ) > 80 || 1 !== preg_match( "/\A[\p{L}\p{M}\p{N} '\-+.]+\z/uD", $term ) ) { return $filtered; }
		return array( 'version' => 1, 'status' => 'known', 'term' => $term );
	}
	/** Receiver and saved facts use canonical syntax, not current query/catalog state. */
	public static function clean( $input ): array {
		$bad = array( 'ok' => false );
		if ( ! is_array( $input ) || ! is_int( $input['version'] ?? null ) || 1 !== $input['version'] || ! is_string( $input['status'] ?? null ) || ! in_array( $input['status'], array( 'known', 'empty', 'filtered' ), true ) ) { return $bad; }
		$out = array( 'version' => 1, 'status' => $input['status'] );
		if ( 'known' === $input['status'] ) {
			if ( ! is_string( $input['term'] ?? null ) ) { return $bad; }
			$normalized = self::source( $input['term'] );
			if ( 'known' !== $normalized['status'] || $normalized['term'] !== $input['term'] ) { return $bad; }
			$out['term'] = $input['term'];
		} elseif ( array_key_exists( 'term', $input ) ) { return $bad; }
		return array( 'ok' => true, 'value' => $out );
	}
	public static function saved( $input ): ?array { $clean = self::clean( $input ); return $clean['ok'] ? $clean['value'] : null; }
}
