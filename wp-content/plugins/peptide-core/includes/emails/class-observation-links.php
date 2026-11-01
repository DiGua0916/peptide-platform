<?php
/** Restricted catalogue links; no request ever supplies a redirect destination. */
namespace PeptideCore\Emails;

defined( 'ABSPATH' ) || exit;

final class Observation_Links {
	/** Return the original canonical product/shop URL including a safe fragment, or null. */
	public static function target( string $url ): ?string {
		if ( '' === $url || strlen( $url ) > 2048 || preg_match( '/[\x00-\x20\\\\]/', $url ) ) { return null; }
		$home = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $home ) || empty( $home['host'] ) ) { return null; }
		$origin = $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		if ( '/' === $url[0] && ! str_starts_with( $url, '//' ) ) { $url = $origin . $url; }
		$p = wp_parse_url( $url );
		if ( ! is_array( $p ) || isset( $p['user'] ) || isset( $p['pass'] ) || array_key_exists( 'query', $p ) || ! in_array( $p['scheme'] ?? '', array( 'http', 'https' ), true ) || strtolower( $p['host'] ?? '' ) !== strtolower( $home['host'] ) || $p['scheme'] !== $home['scheme'] || (int) ( $p['port'] ?? 0 ) !== (int) ( $home['port'] ?? 0 ) ) { return null; }
		$fragment = $p['fragment'] ?? '';
		if ( strlen( $fragment ) > 200 || preg_match( '/[^A-Za-z0-9._~!$&()*+,;=:@\/?%\-]/', $fragment ) ) { return null; }
		$base = $origin . ( $p['path'] ?? '/' );
		$id = url_to_postid( $base );
		$shop = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0;
		if ( ! $id || 'publish' !== get_post_status( $id ) || ( 'product' !== get_post_type( $id ) && $id !== $shop ) ) { return null; }
		// Native canonical identity excludes account, action, download and arbitrary page routes.
		if ( $base !== get_permalink( $id ) ) { return null; }
		return $base . ( array_key_exists( 'fragment', $p ) ? '#' . $fragment : '' );
	}

	/** Transform only href attributes; other HTML bytes are retained by the WP tag processor. */
	public static function html( string $body, callable $link, string $pixel ): ?array {
		if ( ! class_exists( '\WP_HTML_Tag_Processor' ) || strlen( $body ) > 200000 || '' === trim( $body ) ) { return null; }
		// Raw-text, alternate namespaces and form contexts are deliberately unsupported.
		$p = new \WP_HTML_Tag_Processor( $body );
		$count = 0;
		$body_closers = 0;
		while ( $p->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
			$tag = $p->get_tag();
			if ( $p->is_tag_closer() ) { if ( 'BODY' === $tag ) { ++$body_closers; } continue; }
			if ( in_array( $tag, array( 'BASE', 'SCRIPT', 'TEXTAREA', 'FORM', 'IFRAME', 'PLAINTEXT', 'XMP', 'NOEMBED', 'NOFRAMES', 'NOSCRIPT', 'TEMPLATE', 'SVG', 'MATH', 'SELECT' ), true ) ) { return null; }
			if ( 'A' !== $tag ) { continue; }
			$href = $p->get_attribute( 'href' );
			if ( ! is_string( $href ) ) { continue; }
			$target = self::target( $href );
			if ( null === $target ) { continue; }
			$replacement = $link( $target );
			if ( ! is_string( $replacement ) || '' === $replacement ) { return null; }
			if ( ! $p->set_attribute( 'href', $replacement ) ) { return null; }
			++$count;
		}
		if ( $p->paused_at_incomplete_token() ) { return null; }
		$html = $p->get_updated_html();
		$tag = '<img src="' . esc_url( $pixel ) . '" width="1" height="1" alt="" style="border:0;width:1px;height:1px" />';
		$literal_closers = preg_match_all( '~</body\s*>~i', $html, $matches, PREG_OFFSET_CAPTURE );
		// A closing-body string inside a comment/style is not an insertion point.
		if ( $literal_closers !== $body_closers || $body_closers > 1 ) { return null; }
		$html = 0 === $body_closers ? $html . $tag : substr_replace( $html, $tag, $matches[0][0][1], 0 );
		return array( 'body' => $html, 'links' => $count );
	}
}
