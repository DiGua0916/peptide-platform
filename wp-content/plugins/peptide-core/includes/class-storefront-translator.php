<?php
/** Reviewed bilingual presentation catalogue. English business records are never overwritten. */
namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Storefront_Translator {

	private static ?array $catalogue = null;
	private const VOID_TAGS = array( 'AREA', 'BASE', 'BR', 'COL', 'EMBED', 'HR', 'IMG', 'INPUT', 'LINK', 'META', 'PARAM', 'SOURCE', 'TRACK', 'WBR' );
	private const ATOMIC_TAGS = array( 'SCRIPT', 'STYLE', 'TITLE', 'TEXTAREA', 'IFRAME', 'NOEMBED', 'NOFRAMES', 'XMP' );

	public static function register_hooks(): void {
		add_action( 'template_redirect', array( self::class, 'start_buffer' ), 90 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ), 140 );
		add_action( 'wp_footer', array( self::class, 'switcher' ), 12 );
		add_filter( 'gettext', array( self::class, 'gettext' ), 30, 3 );
		add_filter( 'rest_post_dispatch', array( self::class, 'translate_response' ), 80, 3 );
		add_action( 'wp_head', array( self::class, 'alternate_links' ), 4 );
	}

	/** Versioned, reviewed public source strings only. No telemetry or capture of unknown strings. */
	public static function dictionary( bool $ui_only = false ): array {
		static $ui = null;
		if ( null === $ui ) { $ui = self::read_catalogue( 'storefront-es-ui.json' ); }
		if ( $ui_only ) { return $ui; }
		if ( null === self::$catalogue ) { self::$catalogue = $ui + self::read_catalogue( 'storefront-es-content.json' ); }
		return self::$catalogue;
	}

	private static function read_catalogue( string $name ): array {
		$file = PEPTIDE_CORE_DIR . '/languages/' . $name;
		if ( ! is_readable( $file ) || filesize( $file ) > 3000000 ) { return array(); }
		$data = json_decode( (string) file_get_contents( $file ), true );
		$out = array();
		foreach ( is_array( $data['strings'] ?? null ) ? $data['strings'] : array() as $source => $target ) {
			if ( is_string( $source ) && is_string( $target ) && '' !== trim( $source ) && '' !== trim( $target ) ) {
				$out[ $source ] = $target;
				$normalized = preg_replace( '/\s+/u', ' ', trim( $source ) );
				if ( is_string( $normalized ) && '' !== $normalized && ! isset( $out[ $normalized ] ) ) { $out[ $normalized ] = trim( $target ); }
			}
		}
		return $out;
	}

	/** Whole-node matches only. No substring, scientific-name, number, price or user-value rewriting. */
	public static function text( string $source, ?array $dictionary = null ): string {
		$dictionary = $dictionary ?? self::dictionary();
		if ( isset( $dictionary[ $source ] ) ) { return $dictionary[ $source ]; }
		if ( strlen( $source ) > 30000 || ! preg_match( '/\A(\s*)(.*?)(\s*)\z/us', $source, $parts ) ) { return $source; }
		$key = preg_replace( '/\s+/u', ' ', $parts[2] );
		if ( ! is_string( $key ) ) { return $source; }
		if ( isset( $dictionary[ $key ] ) ) { return $parts[1] . $dictionary[ $key ] . $parts[3]; }
		$target = self::dynamic_text( $key );
		return null === $target ? $source : $parts[1] . $target . $parts[3];
	}

	/** Bounded UI templates copy source numbers and names verbatim, without calculating business data. */
	private static function dynamic_text( string $source ): ?string {
		if ( preg_match( '/\A(\d+) (?:box|boxes)\z/u', $source, $m ) ) { return $m[1] . ( '1' === $m[1] ? ' caja' : ' cajas' ); }
		if ( preg_match( '/\A(\d+) (products?|items?|reviews?|reports?|vials?)\z/iu', $source, $m ) ) {
			$base = rtrim( strtolower( $m[2] ), 's' );
			$words = array( 'product' => 'producto', 'item' => 'artículo', 'review' => 'reseña', 'report' => 'informe', 'vial' => 'vial' );
			return $m[1] . ' ' . $words[ $base ] . ( '1' === $m[1] ? '' : ( 'vial' === $base ? 'es' : 's' ) );
		}
		$patterns = array(
			'/\A(\d+(?:[.,]\d+)?)%\s+off\z/iu' => static fn( $m ) => $m[1] . '% DTO.',
			'/\A(\$[0-9,.]+) off\z/u' => static fn( $m ) => $m[1] . ' DTO.',
			'/\A(\$[0-9,.]+)\. (\$[0-9,.]+) off(?:\. (Reached)\.)?\z/u' => static fn( $m ) => $m[1] . '. ' . $m[2] . ' de descuento' . ( isset( $m[3] ) ? '. Alcanzado.' : '' ),
			'/\A(Spend|Add) (.+) more to unlock (.+) off your order\.\z/u' => static fn( $m ) => ( 'Spend' === $m[1] ? 'Compra ' : 'Agrega ' ) . $m[2] . ' más para obtener ' . $m[3] . ' de descuento en tu pedido.',
			'/\ASpend (.+?)(?:, get)?\z/u' => static fn( $m ) => 'Compra por ' . $m[1],
			'/\A(\d+) products\. Find the specification that fits\.\z/u' => static fn( $m ) => $m[1] . ' productos. Encuentre la especificación adecuada.',
			'/\AExplore (.+) specifications\z/u' => static fn( $m ) => 'Explore las especificaciones de ' . $m[1],
			'/\AExplore (.+)\z/u' => static fn( $m ) => 'Explorar ' . $m[1],
			'/\AView (.+)\z/u' => static fn( $m ) => 'Ver ' . $m[1],
			'/\ARemove (.+)\z/u' => static fn( $m ) => 'Eliminar ' . $m[1],
			'/\AA little more about (.+)\.\z/u' => static fn( $m ) => 'Un poco más sobre ' . $m[1] . '.',
			'/\A(.+) quantity\z/u' => static fn( $m ) => 'Cantidad de ' . $m[1],
			'/\AGo to offer (\d+)\z/u' => static fn( $m ) => 'Ir a la oferta ' . $m[1],
			'/\APrice range: (.+) through (.+)\z/u' => static fn( $m ) => 'Rango de precios: ' . $m[1] . ' a ' . $m[2],
			'/\APage (\d+) of (\d+)\z/u' => static fn( $m ) => 'Página ' . $m[1] . ' de ' . $m[2],
			'/\APage (\d+)\z/u' => static fn( $m ) => 'Página ' . $m[1],
			'/\AShowing (\d+)([–-])(\d+) of (\d+) results\z/u' => static fn( $m ) => 'Mostrando ' . $m[1] . $m[2] . $m[3] . ' de ' . $m[4] . ' resultados',
			'/\A(\d+) published batches\z/u' => static fn( $m ) => $m[1] . ' lotes publicados',
			'/\AFirst page of (.+)\z/u' => static fn( $m ) => 'Primera página de ' . $m[1],
			'/\ASelected: (.+)\z/u' => static fn( $m ) => 'Seleccionado: ' . $m[1],
			'/\A(Decrease|Increase) quantity of (.+)\z/u' => static fn( $m ) => ( 'Decrease' === $m[1] ? 'Disminuir' : 'Aumentar' ) . ' la cantidad de ' . $m[2],
			'/\A(.+?)(\s*\/\s*)(vial|box)\z/iu' => static fn( $m ) => $m[1] . $m[2] . ( 'box' === strtolower( $m[3] ) ? 'caja' : 'vial' ),
		);
		foreach ( $patterns as $pattern => $render ) { if ( preg_match( $pattern, $source, $m ) ) { return $render( $m ); } }
		return null;
	}

	/** Use WordPress' lexical HTML API, preserving markup, script JSON, URLs, IDs and form values. */
	public static function html( string $html, ?array $dictionary = null ): string {
		if ( ! class_exists( '\WP_HTML_Tag_Processor' ) || strlen( $html ) > 6000000 ) { return $html; }
		$dictionary = $dictionary ?? self::dictionary();
		$p = new \WP_HTML_Tag_Processor( $html );
		$stack = array();
		while ( $p->next_token() ) {
			$type = $p->get_token_type();
			$skip = ! empty( $stack ) && $stack[ count( $stack ) - 1 ]['skip'];
			if ( '#text' === $type ) {
				if ( ! $skip ) {
					$source = $p->get_modifiable_text(); $target = self::text( $source, $dictionary );
					if ( $source !== $target ) { $p->set_modifiable_text( $target ); }
				}
				continue;
			}
			if ( '#tag' !== $type ) { continue; }
			$tag = $p->get_tag();
			if ( $p->is_tag_closer() ) {
				for ( $i = count( $stack ) - 1; $i >= 0; --$i ) {
					if ( $stack[ $i ]['tag'] === $tag ) { $stack = array_slice( $stack, 0, $i ); break; }
				}
				continue;
			}
			$id = $p->get_attribute( 'id' ); $classes = (string) ( $p->get_attribute( 'class' ) ?? '' );
			$skip = $skip || 'no' === $p->get_attribute( 'translate' ) || null !== $p->get_attribute( 'contenteditable' ) || 'wpadminbar' === $id
				|| in_array( $tag, array( 'PRE', 'CODE', 'KBD', 'SAMP', 'ADDRESS', 'SVG', 'MATH', 'SCRIPT', 'STYLE', 'TEXTAREA', 'SELECT', 'OPTION', 'IFRAME', 'NOSCRIPT' ), true )
				|| 1 === preg_match( '/(?:^|\s)(?:product_title|vc-product-meta__name|woocommerce-Price-amount|sku|comment-text|review-content|woocommerce-review__author|woocommerce-Address|woocommerce-customer-details|woocommerce-MyAccount-content|pc-language-switcher|wp-block-woocommerce-checkout|wp-block-woocommerce-cart|wc-block-checkout|wc-block-cart)(?:\s|$)|(?:^|\s)wc-block-components-/', $classes );
			if ( 'HTML' === $tag ) { $p->set_attribute( 'lang', 'es' ); }
			if ( ! $skip ) {
				foreach ( array( 'aria-label', 'placeholder', 'title', 'alt' ) as $attribute ) {
					$value = $p->get_attribute( $attribute );
					if ( is_string( $value ) ) { $target = self::text( $value, $dictionary ); if ( $target !== $value ) { $p->set_attribute( $attribute, $target ); } }
				}
				if ( 'TITLE' === $tag ) {
					$source = $p->get_modifiable_text(); $target = self::text( $source, $dictionary );
					if ( $source !== $target ) { $p->set_modifiable_text( $target ); }
				}
			}
			if ( ! in_array( $tag, self::VOID_TAGS, true ) && ! in_array( $tag, self::ATOMIC_TAGS, true ) ) { $stack[] = array( 'tag' => $tag, 'skip' => $skip ); }
		}
		return $p->get_updated_html();
	}

	public static function start_buffer(): void {
		if ( Storefront_Translation::enabled() && Storefront_Translation::public_document_request() && 'es' === Storefront_Translation::code() && Storefront_Translation::active() ) {
			ob_start( static fn( $html ) => self::html( (string) $html ) );
		}
	}

	public static function gettext( string $translated, string $original, string $domain ): string {
		return in_array( $domain, array( 'peptide-core', 'peptide-site' ), true ) && 'es' === Storefront_Translation::code() && Storefront_Translation::active() ? self::text( $original ) : $translated;
	}

	/** Only translate HTML fields on existing public read APIs; never mutate cart/checkout/payment DTOs. */
	public static function translate_response( $response, $server, \WP_REST_Request $request ) {
		if ( ! Storefront_Translation::active() || 'es' !== Storefront_Translation::code() || 'GET' !== $request->get_method() || ! $response instanceof \WP_REST_Response ) { return $response; }
		$route = $request->get_route();
		if ( ! preg_match( '#\A/(?:vialcove/v1/home-product/\d+|wc/store/v1/products(?:/\d+)?)\z#D', $route ) ) { return $response; }
		$data = $response->get_data();
		if ( ! is_array( $data ) ) { return $response; }
		$translate = static function ( array $row ): array {
			foreach ( array( 'html', 'description', 'short_description' ) as $field ) { if ( isset( $row[ $field ] ) && is_string( $row[ $field ] ) ) { $row[ $field ] = self::html( $row[ $field ] ); } }
			return $row;
		};
		$data = array_is_list( $data ) ? array_map( static fn( $row ) => is_array( $row ) ? $translate( $row ) : $row, $data ) : $translate( $data );
		$response->set_data( $data );
		return $response;
	}

	public static function enqueue(): void {
		if ( ! Storefront_Translation::enabled() || ! Storefront_Translation::public_document_request() ) { return; }
		$base = PEPTIDE_CORE_DIR . '/assets/';
		wp_enqueue_style( 'pc-storefront-language', plugins_url( 'assets/storefront-language.css', PEPTIDE_CORE_FILE ), array(), (string) filemtime( $base . 'storefront-language.css' ) );
		wp_enqueue_script( 'pc-storefront-language', plugins_url( 'assets/storefront-language.js', PEPTIDE_CORE_FILE ), array(), (string) filemtime( $base . 'storefront-language.js' ), true );
		$config = Storefront_Language::get();
		wp_add_inline_script( 'pc-storefront-language', 'window.PCStorefrontI18n=' . wp_json_encode( array(
			'enabled' => true, 'language' => Storefront_Translation::code(), 'locale' => Storefront_Translation::locale(),
			'dictionary' => self::dictionary( true ), 'resolverUrl' => rest_url( 'peptide/v1/storefront-language' ),
			'preferenceCookie' => Storefront_Language::PREFERENCE_COOKIE, 'defaultLanguage' => $config['default_language'],
			'autoEnabled' => $config['enabled'] && Storefront_Translation::packages_ready(),
		) ), 'before' );
	}

	public static function language_url( string $language ): string {
		$path = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		if ( ! str_starts_with( $path, '/' ) || str_starts_with( $path, '//' ) || preg_match( '/[\x00-\x20\x7f\\\\]/', $path ) ) { $path = '/'; }
		return add_query_arg( 'pc_lang', 'es' === $language ? 'es' : 'en', remove_query_arg( 'pc_lang_auto', home_url( $path ) ) );
	}

	public static function switcher(): void {
		if ( ! Storefront_Translation::enabled() || ! Storefront_Translation::public_document_request() ) { return; }
		$code = Storefront_Translation::code();
		echo '<nav class="pc-language-switcher" data-pc-language-switcher translate="no" aria-label="Language"><details data-pc-language-menu>';
		echo '<summary data-pc-language-trigger aria-controls="pc-language-options"><span>Language</span><svg class="pc-language-chevron" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" focusable="false"><path d="m4 6 4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></summary>';
		echo '<div class="pc-language-options" id="pc-language-options">';
		foreach ( array( 'en' => 'English', 'es' => 'Español' ) as $language => $label ) {
			echo '<a href="' . esc_url( self::language_url( $language ) ) . '" data-pc-language-option="' . esc_attr( $language ) . '" lang="' . esc_attr( $language ) . '" hreflang="' . esc_attr( $language ) . '"' . ( $language === $code ? ' aria-current="true"' : '' ) . '><span>' . esc_html( $label ) . '</span>';
			echo '<svg class="pc-language-check" viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" focusable="false"><path d="m3 8 3 3 7-7" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></a>';
		}
		echo '</div></details></nav>';
		if ( defined( 'PC_DATA_GEOIP_ATTRIBUTION' ) && 'db-ip' === PC_DATA_GEOIP_ATTRIBUTION && 'geoip' === ( Storefront_Translation::selected()['source'] ?? '' ) ) {
			echo '<small class="pc-language-attribution" translate="no"><a href="https://db-ip.com" rel="noopener noreferrer">IP Geolocation by DB-IP</a></small>';
		}
	}

	public static function alternate_links(): void {
		if ( ! Storefront_Translation::enabled() || ! Storefront_Translation::public_document_request() || ( function_exists( 'is_checkout' ) && ( is_checkout() || is_account_page() || is_cart() ) ) ) { return; }
		foreach ( array( 'en', 'es' ) as $language ) { echo '<link rel="alternate" hreflang="' . esc_attr( $language ) . '" href="' . esc_url( self::language_url( $language ) ) . '">'; }
	}
}
