<?php
/**
 * Restricted text colors for explicitly opted-in module rich text.
 *
 * @package PeptideCore
 */

namespace PeptideCore\Modules;

defined( 'ABSPATH' ) || exit;

final class Rich_Html {
	/** ECMAScript trim/\s whitespace, kept identical to the client grammar. */
	private const SPACE = '[\x09-\x0d\x20\x{00a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}\x{feff}]';

	/** Registry definitions are trusted; saved attributes never grant this policy. */
	public static function supports_color( array $field ): bool {
		return 'html' === ( $field['type'] ?? null ) && 'richtext' === ( $field['ui'] ?? null );
	}

	/** Strict browser RGB compatibility is normalized to the sole saved hex form. */
	public static function hex( $value ): ?string {
		if ( ! is_string( $value ) ) { return null; }
		$value = preg_replace( '/^' . self::SPACE . '+|' . self::SPACE . '+$/u', '', $value );
		if ( ! is_string( $value ) ) { return null; }
		if ( preg_match( '/^#[0-9a-f]{3}$/iD', $value ) ) {
			return '#' . strtolower( $value[1] . $value[1] . $value[2] . $value[2] . $value[3] . $value[3] );
		}
		if ( preg_match( '/^#[0-9a-f]{6}$/iD', $value ) ) { return strtolower( $value ); }
		$space = self::SPACE . '*';
		if ( preg_match( '/^rgb\(' . $space . '(\d{1,3})' . $space . ',' . $space . '(\d{1,3})' . $space . ',' . $space . '(\d{1,3})' . $space . '\)$/iuD', $value, $match ) ) {
			$channels = array_map( 'intval', array_slice( $match, 1 ) );
			if ( max( $channels ) <= 255 ) { return sprintf( '#%02x%02x%02x', ...$channels ); }
		}
		return null;
	}

	/** One declaration, no other CSS grammar or properties. */
	public static function style_color( $value ): ?string {
		$space = self::SPACE . '*';
		return is_string( $value ) && preg_match( '/^' . $space . 'color' . $space . ':' . $space . '([^;]+)' . $space . ';?' . $space . '$/iuD', $value, $match ) ? self::hex( $match[1] ) : null;
	}

	/**
	 * Keep the original KSES path byte-for-byte when no valid color is present.
	 * Any unavailable/unsupported parser takes that same safe path for all input.
	 *
	 * @param array<string,array<string,mixed>> $baseline Existing field allowlist.
	 */
	public static function sanitize( string $html, array $baseline ): string {
		$original = static fn(): string => trim( wp_kses( $html, $baseline ) );
		if ( false === stripos( $html, 'color' ) || ! self::available() || ! self::has_color( $html ) ) { return $original(); }

		$prepared = self::prepare( $html, $baseline );
		if ( null === $prepared ) { return $original(); }
		$processor = \WP_HTML_Processor::create_fragment( $prepared );
		if ( ! $processor ) { return $original(); }
		$required = array( 'next_token', 'get_tag', 'is_tag_closer', 'get_attribute', 'get_modifiable_text', 'get_token_type', 'get_breadcrumbs', 'get_last_error', 'paused_at_incomplete_token' );
		foreach ( $required as $method ) { if ( ! is_callable( array( $processor, $method ) ) ) { return $original(); } }

		$blocked = array( 'SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'SVG', 'MATH', 'FORM', 'INPUT', 'BUTTON', 'TEXTAREA', 'SELECT', 'TEMPLATE' );
		$out = '';
		$stack = array();
		$count = 0;
		while ( $processor->next_token() ) {
			if ( ++$count > 50000 ) { return $original(); }
			if ( array_intersect( $blocked, $processor->get_breadcrumbs() ) ) { continue; }
			$type = $processor->get_token_type();
			if ( '#text' === $type ) { $out .= self::escape( $processor->get_modifiable_text() ); continue; }
			if ( '#tag' !== $type ) { continue; }
			$tag = strtolower( (string) $processor->get_tag() );
			$mapped = 'div' === $tag ? 'p' : ( 'font' === $tag ? 'span' : $tag );
			if ( ! array_key_exists( $mapped, $baseline ) ) { continue; }
			if ( $processor->is_tag_closer() ) {
				if ( $stack && $stack[ count( $stack ) - 1 ]['tag'] === $tag ) {
					$opened = array_pop( $stack );
					if ( '' !== $opened['mapped'] ) { $out .= '</' . $opened['mapped'] . '>'; }
				}
				continue;
			}
			if ( 'br' === $mapped ) { $out .= '<br>'; continue; }
			$attrs = self::attributes( $processor, $tag, $mapped );
			$stack[] = array( 'tag' => $tag, 'mapped' => $mapped );
			if ( '' !== $mapped ) { $out .= '<' . $mapped . $attrs . '>'; }
		}
		if ( null !== $processor->get_last_error() || $processor->paused_at_incomplete_token() || $stack ) { return $original(); }
		$allowed = $baseline;
		$allowed['span']['style'] = true;
		$out = trim( wp_kses( $out, $allowed ) );
		$text = html_entity_decode( strip_tags( $out ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return '' === preg_replace( '/[\p{Z}\s\x{FEFF}]+/u', '', $text ) ? '' : $out;
	}

	/** Strict image path: failure is observable before any caller writes or issues a token. */
	public static function with_images( string $html, array $baseline, Rich_Media $media ) {
		if ( ! preg_match( '/<\s*img\b/i', $html ) ) { return self::sanitize( $html, $baseline ); }
		$fail = static fn() => Rich_Media::error( __( 'Rich text image markup could not be parsed. Correct the source before applying.', 'peptide-core' ) );
		if ( ! self::available() ) { return $fail(); }
		$prepared = self::prepare( $html, $baseline, $media );
		if ( is_wp_error( $prepared ) ) { return $prepared; }
		if ( null === $prepared ) { return $fail(); }
		$processor = \WP_HTML_Processor::create_fragment( $prepared );
		if ( ! $processor ) { return $fail(); }
		foreach ( array( 'next_token', 'get_tag', 'is_tag_closer', 'get_attribute', 'get_modifiable_text', 'get_token_type', 'get_breadcrumbs', 'get_last_error', 'paused_at_incomplete_token' ) as $method ) { if ( ! is_callable( array( $processor, $method ) ) ) { return $fail(); } }
		$out = ''; $stack = array(); $count = 0;
		while ( $processor->next_token() ) {
			if ( ++$count > 50000 ) { return $fail(); }
			$type = $processor->get_token_type();
			if ( '#text' === $type ) { $out .= self::escape( $processor->get_modifiable_text() ); continue; }
			if ( '#tag' !== $type ) { continue; }
			$tag = strtolower( (string) $processor->get_tag() );
			if ( 'img' === $tag && ! $processor->is_tag_closer() ) { $image = $media->image( $processor ); if ( is_wp_error( $image ) ) { return $image; } $out .= $image; continue; }
			$mapped = 'div' === $tag ? 'p' : ( 'font' === $tag ? 'span' : $tag );
			if ( ! array_key_exists( $mapped, $baseline ) ) { continue; }
			if ( $processor->is_tag_closer() ) { if ( $stack && end( $stack )['tag'] === $tag ) { $opened = array_pop( $stack ); if ( '' !== $opened['mapped'] ) { $out .= '</' . $opened['mapped'] . '>'; } } continue; }
			if ( 'br' === $mapped ) { $out .= '<br>'; continue; }
			$attrs = self::attributes( $processor, $tag, $mapped ); $stack[] = array( 'tag' => $tag, 'mapped' => $mapped );
			if ( '' !== $mapped ) { $out .= '<' . $mapped . $attrs . '>'; }
		}
		if ( null !== $processor->get_last_error() || $processor->paused_at_incomplete_token() || $stack ) { return $fail(); }
		$allowed = $baseline; $allowed['span']['style'] = true;
		$allowed['img'] = array_fill_keys( array( 'class', 'data-pc-attachment', 'data-pc-asset', 'src', 'width', 'height', 'alt' ), true );
		return trim( wp_kses( $out, $allowed ) );
	}

	private static function available(): bool {
		return class_exists( '\WP_HTML_Tag_Processor' ) && class_exists( '\WP_HTML_Processor' ) && is_callable( array( '\WP_HTML_Processor', 'create_fragment' ) );
	}

	/**
	 * Public tokenizer pass: remove unsupported containers before structural parsing.
	 * Only fixed baseline tags and canonical attributes are rebuilt, never raw tags.
	 */
	private static function prepare( string $html, array $baseline, ?Rich_Media $media = null ) {
		$scanner = new \WP_HTML_Tag_Processor( $html );
		foreach ( array( 'next_token', 'get_token_type', 'get_tag', 'get_attribute', 'get_modifiable_text', 'is_tag_closer', 'paused_at_incomplete_token' ) as $method ) {
			if ( ! is_callable( array( $scanner, $method ) ) ) { return null; }
		}
		$containers = array( 'OBJECT', 'SVG', 'MATH', 'FORM', 'BUTTON', 'SELECT', 'TEMPLATE' );
		$atomic = array( 'SCRIPT', 'STYLE', 'IFRAME', 'EMBED', 'INPUT', 'TEXTAREA' );
		$blocked = array();
		$anchors = array();
		$out = '';
		$count = 0;
		while ( $scanner->next_token() ) {
			if ( ++$count > 50000 ) { return null; }
			$type = $scanner->get_token_type();
			if ( '#tag' === $type ) {
				$upper = $scanner->get_tag();
				$closing = $scanner->is_tag_closer();
				if ( $blocked ) {
					if ( $closing && end( $blocked ) === $upper ) { array_pop( $blocked ); }
					elseif ( ! $closing && in_array( $upper, $containers, true ) ) { $blocked[] = $upper; }
					continue;
				}
				if ( $media && ( in_array( $upper, $containers, true ) || in_array( $upper, $atomic, true ) ) ) { return Rich_Media::error( __( 'Unsupported structure around rich text images. Correct the source before applying.', 'peptide-core' ) ); }
				if ( in_array( $upper, $containers, true ) ) { if ( ! $closing ) { $blocked[] = $upper; } continue; }
				if ( in_array( $upper, $atomic, true ) ) { continue; }
				if ( 'IMG' === $upper && $media && ! $closing ) { $image = $media->image( $scanner ); if ( is_wp_error( $image ) ) { return $image; } $out .= $image; continue; }
				$tag = strtolower( (string) $upper );
				$mapped = 'div' === $tag ? 'p' : ( 'font' === $tag ? 'span' : $tag );
				if ( ! array_key_exists( $mapped, $baseline ) ) { continue; }
				if ( $closing ) {
					if ( 'a' === $tag && $anchors ) { $mapped = array_pop( $anchors ); }
					if ( '' !== $mapped && 'br' !== $mapped ) { $out .= '</' . $mapped . '>'; }
				} elseif ( 'br' === $mapped ) {
					$out .= '<br>';
				} else {
					$attrs = self::attributes( $scanner, $tag, $mapped );
					if ( 'a' === $tag ) { $anchors[] = $mapped; }
					if ( '' !== $mapped ) { $out .= '<' . $mapped . $attrs . '>'; }
				}
			} elseif ( '#text' === $type && ! $blocked ) {
				$out .= self::escape( $scanner->get_modifiable_text() );
			}
		}
		return $scanner->paused_at_incomplete_token() || $blocked ? null : $out;
	}

	/** Read only public decoded attributes; emit an independently fixed allowlist. */
	private static function attributes( $processor, string $tag, string &$mapped ): string {
		$attrs = '';
		if ( 'span' === $mapped ) {
			$class = 'span' === $tag ? $processor->get_attribute( 'class' ) : null;
			if ( is_string( $class ) || true === $class ) { $attrs .= ' class="' . self::escape( true === $class ? '' : $class ) . '"'; }
			$color = self::style_color( $processor->get_attribute( 'style' ) );
			if ( null === $color && 'font' === $tag ) { $color = self::hex( $processor->get_attribute( 'color' ) ); }
			if ( null !== $color ) { $attrs .= ' style="color:' . $color . '"'; }
		} elseif ( 'a' === $mapped ) {
			$href = $processor->get_attribute( 'href' );
			if ( is_string( $href ) && ! self::allowed_href( $href ) ) { $mapped = ''; return ''; }
			if ( is_string( $href ) ) { $attrs .= ' href="' . self::escape( trim( $href ) ) . '"'; }
			$target = $processor->get_attribute( 'target' );
			if ( is_string( $target ) ) { $attrs .= ' target="' . self::escape( $target ) . '"'; }
			$rel = $processor->get_attribute( 'rel' );
			$tokens = is_string( $rel ) ? preg_split( '/\s+/', $rel, -1, PREG_SPLIT_NO_EMPTY ) : array();
			$tokens = array_values( array_filter( $tokens, static fn( string $token ): bool => 'opener' !== strtolower( $token ) ) );
			foreach ( array( 'noopener', 'noreferrer' ) as $required_rel ) {
				if ( ! in_array( $required_rel, array_map( 'strtolower', $tokens ), true ) ) { $tokens[] = $required_rel; }
			}
			$attrs .= ' rel="' . self::escape( implode( ' ', $tokens ) ) . '"';
		}
		return $attrs;
	}

	private static function has_color( string $html ): bool {
		$scanner = new \WP_HTML_Tag_Processor( $html );
		if ( ! is_callable( array( $scanner, 'next_tag' ) ) || ! is_callable( array( $scanner, 'get_attribute' ) ) ) { return false; }
		while ( $scanner->next_tag() ) {
			$tag = $scanner->get_tag();
			if ( ( 'SPAN' === $tag || 'FONT' === $tag ) && ( null !== self::style_color( $scanner->get_attribute( 'style' ) ) || ( 'FONT' === $tag && null !== self::hex( $scanner->get_attribute( 'color' ) ) ) ) ) { return true; }
		}
		return false;
	}

	private static function allowed_href( string $href ): bool {
		$href = trim( $href );
		if ( preg_match( '/[\x00-\x1f\x7f]/', $href ) ) { return false; }
		if ( preg_match( '/^([a-z][a-z0-9+.-]*):/i', $href, $scheme ) ) {
			return ! in_array( strtolower( $scheme[1] ), array( 'javascript', 'vbscript', 'data', 'file', 'blob' ), true ) && in_array( strtolower( $scheme[1] ), wp_allowed_protocols(), true );
		}
		return true;
	}

	/** Escape decoded processor values, including literal entity-looking strings. */
	private static function escape( string $value ): string {
		return strtr( $value, array( '&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&#39;' ) );
	}
}
