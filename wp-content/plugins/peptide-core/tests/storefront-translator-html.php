<?php
/** Actual WordPress lexical HTML parser with the production translator, no WP bootstrap/DB/network.
 * Run: php tests/storefront-translator-html.php <absolute WordPress site directory>
 */
namespace PeptideCore {
    final class Storefront_Translation {
        public static bool $enabled = true;
        public static bool $active = true;
        public static string $language = 'es';
        public static bool $public = true;
        public static function enabled(): bool { return self::$enabled; }
        public static function active(): bool { return self::$active; }
        public static function code(): string { return self::$language; }
        public static function public_document_request(): bool { return self::$public; }
    }
}
namespace {
    $site = $argv[1] ?? '';
    if ( ! is_string( $site ) || ! is_file( rtrim( $site, '/\\' ) . '/wp-includes/html-api/class-wp-html-tag-processor.php' ) ) {
        fwrite( STDERR, "Pass an existing WordPress directory; this test never boots it.\n" ); exit( 2 );
    }
    define( 'ABSPATH', rtrim( $site, '/\\' ) . '/' );
    define( 'PEPTIDE_CORE_DIR', dirname( __DIR__ ) );
    // Core HTML's filter and diagnostic dependencies are process-local, inert fixtures.
    function apply_filters( $name, $value, ...$args ) { return $value; }
    function __( $text, $domain = '' ) { return $text; }
    function absint( $value ) { return abs( (int) $value ); }
    function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
    function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
    function esc_url( $value ) { return esc_attr( $value ); }
    function wp_unslash( $value ) { return stripslashes( $value ); }
    // Only the URL dependencies of switcher() are process-local fixtures. Real output
    // is inspected below with the actual native WordPress HTML lexical processor.
    function home_url( $path = '' ) { return 'https://shop.example' . $path; }
    function language_url_query_fixture( string $key, $value, string $url ): string {
        $parts = parse_url( $url ); $query = array(); parse_str( $parts['query'] ?? '', $query );
        if ( null === $value ) { unset( $query[$key] ); } else { $query[$key] = $value; }
        return ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? 'shop.example' ) . ( $parts['path'] ?? '/' ) . ( $query ? '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) : '' ) . ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
    }
    function add_query_arg( $key, $value, $url ) { return language_url_query_fixture( $key, $value, $url ); }
    function remove_query_arg( $key, $url ) { return language_url_query_fixture( $key, null, $url ); }
    function _doing_it_wrong( $function, $message, $version ) { throw new \RuntimeException( $function . ': ' . $message ); }
    function get_option( ...$args ) { throw new \RuntimeException( 'No WordPress option/database access is allowed in this offline test.' ); }
    require_once ABSPATH . 'wp-includes/utf8.php';
    require_once ABSPATH . 'wp-includes/kses.php';
    require_once ABSPATH . 'wp-includes/class-wp-token-map.php';
    foreach ( array( 'html5-named-character-references.php', 'class-wp-html-span.php', 'class-wp-html-text-replacement.php', 'class-wp-html-attribute-token.php', 'class-wp-html-token.php', 'class-wp-html-doctype-info.php', 'class-wp-html-decoder.php', 'class-wp-html-tag-processor.php' ) as $file ) {
        require_once ABSPATH . 'wp-includes/html-api/' . $file;
    }
    require_once ABSPATH . 'wp-includes/class-wp-http-response.php';
    require_once ABSPATH . 'wp-includes/rest-api/class-wp-rest-response.php';
    require_once ABSPATH . 'wp-includes/rest-api/class-wp-rest-request.php';
    require_once dirname( __DIR__ ) . '/includes/class-storefront-translator.php';
    $checks = array();
    function check( bool $condition, string $name ): void {
        $GLOBALS['checks'][] = array( 'test' => $name, 'pass' => $condition );
    }
    function translate( string $html, ?array $dictionary = null ): string {
        return \PeptideCore\Storefront_Translator::html( $html, $dictionary ?? array( 'Search reports' => 'Buscar informes', 'Offers' => 'Ofertas', 'A & B' => 'A & B traducido' ) );
    }
    $table = '<table id="p-11307"><tbody><tr><th>Search reports</th><td data-sku="EXAMPLE-20">Offers</td></tr></tbody></table>';
    check( translate( $table ) === '<table id="p-11307"><tbody><tr><th>Buscar informes</th><td data-sku="EXAMPLE-20">Ofertas</td></tr></tbody></table>', 'native processor translates table text, preserving tags/ids/SKU attributes' );
    check( translate( '<p> Search reports </p><p>Unknown copy</p>' ) === '<p> Buscar informes </p><p>Unknown copy</p>', 'whole-node match preserves boundary whitespace and unknown text' );
    foreach ( array(
        array( 'Spend $1,000.00, get', 'Compra por $1,000.00' ),
        array( '82 products. Find the specification that fits.', '82 productos. Encuentre la especificación adecuada.' ),
        array( 'Explore GLOW Blend (GLOW70) specifications', 'Explore las especificaciones de GLOW Blend (GLOW70)' ),
        array( 'View CJC-1295 no DAC', 'Ver CJC-1295 no DAC' ),
        array( 'Go to offer 3', 'Ir a la oferta 3' ),
        array( 'Price range: $40.00 through $220.00', 'Rango de precios: $40.00 a $220.00' ),
        array( 'Spend $41.10 more to unlock $25.00 off your order.', 'Compra $41.10 más para obtener $25.00 de descuento en tu pedido.' ),
        array( 'Explore GLOW Blend (GLOW70)', 'Explorar GLOW Blend (GLOW70)' ),
    ) as [$input,$expected] ) {
        $actual = \PeptideCore\Storefront_Translator::text( $input, array() );
        preg_match_all( '/\d+(?:[.,]\d+)*/', $input, $a ); preg_match_all( '/\d+(?:[.,]\d+)*/', $actual, $b );
        $a=$a[0]; $b=$b[0]; sort($a); sort($b);
        check( $actual === $expected && $a === $b, 'dynamic UI preserves full names and numeric values: ' . $input );
    }
    check( translate( '<p>A &amp; B</p>' ) === '<p>A &amp; B traducido</p>', 'entities decoded for exact matching and safely re-escaped once' );
    $input = '<input id="Search reports" name="Search reports" value="Search reports" placeholder="Search reports" aria-label="Search reports" data-label="Search reports">';
    $target = translate( $input );
    check( str_contains( $target, 'placeholder="Buscar informes"' ) && str_contains( $target, 'aria-label="Buscar informes"' ), 'only reviewed input presentation labels can translate' );
    check( str_contains( $target, 'id="Search reports"' ) && str_contains( $target, 'name="Search reports"' ) && str_contains( $target, 'value="Search reports"' ) && str_contains( $target, 'data-label="Search reports"' ), 'input typed/submitted value, id/name/data attributes remain byte-identical' );
    $safe = '<a href="https://example.test/Offers?x=Search%20reports#Offers" id="Offers" data-product-id="11307" title="Search reports" aria-label="Offers">Offers</a><img src="/Offers.png" alt="Offers">';
    $target = translate( $safe );
    check( str_contains( $target, 'href="https://example.test/Offers?x=Search%20reports#Offers"' ) && str_contains( $target, 'data-product-id="11307"' ) && str_contains( $target, 'src="/Offers.png"' ), 'links, file paths, product IDs and hashes are not translated' );
    check( str_contains( $target, 'title="Buscar informes"' ) && str_contains( $target, 'alt="Ofertas"' ), 'reviewed title and image alt are translated' );
    foreach ( array( 'script', 'style', 'textarea', 'select', 'option', 'code', 'pre', 'kbd', 'samp', 'address', 'svg', 'math', 'iframe', 'noscript' ) as $tag ) {
        $html = '<' . $tag . '>Search reports</' . $tag . '>';
        check( translate( $html ) === $html, 'protected raw/code/address element untouched: ' . $tag );
    }
    $json = '<script type="application/ld+json">{"name":"Search reports","price":"55.00","sku":"EXAMPLE-20"}</script>';
    check( translate( $json ) === $json, 'script JSON preserves names, price, SKU and encoding' );
    foreach ( array( 'product_title', 'vc-product-meta__name', 'sku', 'woocommerce-Price-amount', 'comment-text', 'review-content', 'woocommerce-review__author', 'woocommerce-Address', 'woocommerce-customer-details', 'woocommerce-MyAccount-content', 'wp-block-woocommerce-checkout', 'wp-block-woocommerce-cart', 'wc-block-checkout', 'wc-block-cart', 'wc-block-components-address-card' ) as $class ) {
        $html = '<div class="' . $class . '"><span title="Search reports">Search reports</span></div>';
        check( translate( $html ) === $html, 'protected class and descendants untouched: ' . $class );
    }
    foreach ( array( 'translate="no"', 'id="wpadminbar"', 'contenteditable="true"', 'contenteditable=""' ) as $attribute ) {
        $html = '<div ' . $attribute . '><span>Search reports</span><input value="Offers" placeholder="Search reports"></div>';
        check( translate( $html ) === $html, 'protected user/editor/admin subtree untouched: ' . $attribute );
    }
    $scientific = '<p>Retatrutide</p><p>2381089-83-2</p><p>C₂₂₁H₃₄₂N₄₆O₆₈</p><p>20mg*10vials</p><span class="woocommerce-Price-amount">$55.00</span>';
    check( translate( $scientific ) === $scientific, 'scientific name, CAS, formula, specification and authoritative amount stay unchanged' );
    check( translate( '<p>Offers</p>', array( 'Offers' => '<script>alert(1)</script>' ) ) === '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>', 'dictionary targets cannot inject HTML or script' );
    check( translate( '<!-- Offers --><p data-x="Offers">Offers</p>' ) === '<!-- Offers --><p data-x="Offers">Ofertas</p>', 'comments and unapproved attributes remain unchanged' );
    check( translate( '<div translate="no"><p>Offers</p></div><p>Offers</p>' ) === '<div translate="no"><p>Offers</p></div><p>Ofertas</p>', 'skip scope ends at corresponding closing tag' );
    $translator = \PeptideCore\Storefront_Translator::class;
    $state = \PeptideCore\Storefront_Translation::class;
    $previous_uri = $_SERVER['REQUEST_URI'] ?? null;
    $_SERVER['REQUEST_URI'] = '/cart/?coupon=KEEP&pc_lang_auto=1&pc_lang=en&pc_lang=en#totals';
    foreach ( array( 'en', 'es' ) as $selected_language ) {
        $state::$language = $selected_language; $state::$active = true; $state::$public = true;
        ob_start(); $translator::switcher(); $markup = ob_get_clean();
        check( ! str_contains( $markup, '<select' ) && ! str_contains( $markup, '<option' ), 'native details switcher replaces the clipped select: ' . $selected_language );
        check( str_contains( $markup, '<span>Language</span>' ) && str_contains( $markup, '>English</span>' ) && str_contains( $markup, '>Español</span>' ), 'fixed Language trigger and self-language labels are emitted in both document languages: ' . $selected_language );
        $processor = new \WP_HTML_Tag_Processor( $markup );
        $details = 0; $summaries = 0; $links = array(); $controls = null; $panel = null; $native = true; $no_translate = false;
        while ( $processor->next_tag() ) {
            $tag = $processor->get_tag();
            if ( 'NAV' === $tag ) { $no_translate = 'no' === $processor->get_attribute( 'translate' ); }
            if ( 'DETAILS' === $tag && null !== $processor->get_attribute( 'data-pc-language-menu' ) ) { ++$details; }
            if ( 'SUMMARY' === $tag && null !== $processor->get_attribute( 'data-pc-language-trigger' ) ) { ++$summaries; $controls = $processor->get_attribute( 'aria-controls' ); }
            if ( 'DIV' === $tag && 'pc-language-options' === $processor->get_attribute( 'class' ) ) { $panel = $processor->get_attribute( 'id' ); }
            if ( in_array( $tag, array( 'DETAILS', 'SUMMARY', 'DIV', 'A' ), true ) && null !== $processor->get_attribute( 'role' ) ) { $native = false; }
            if ( 'A' === $tag && null !== $processor->get_attribute( 'data-pc-language-option' ) ) {
                $language = $processor->get_attribute( 'data-pc-language-option' );
                $links[$language] = array( 'href' => $processor->get_attribute( 'href' ), 'current' => $processor->get_attribute( 'aria-current' ), 'lang' => $processor->get_attribute( 'lang' ), 'hreflang' => $processor->get_attribute( 'hreflang' ) );
            }
        }
        check( $details === 1 && $summaries === 1 && $native && $no_translate && $controls && $controls === $panel, 'actual native HTML parser finds one translated-off details/summary and matching controlled panel, no menu role: ' . $selected_language );
        check( array_keys( $links ) === array( 'en', 'es' ), 'exactly two functional language anchors exist without JavaScript: ' . $selected_language );
        foreach ( $links as $language => $props ) {
            $url = parse_url( $props['href'] ); $query = array(); parse_str( $url['query'] ?? '', $query );
            check( 'https' === ( $url['scheme'] ?? '' ) && 'shop.example' === ( $url['host'] ?? '' ) && '/cart/' === ( $url['path'] ?? '' ) && 'totals' === ( $url['fragment'] ?? '' ) && $query === array( 'coupon' => 'KEEP', 'pc_lang' => $language ), 'language link retains path/unrelated query/available fragment and replaces only explicit preference: ' . $selected_language . '/' . $language );
            check( $props['current'] === ( $language === $selected_language ? 'true' : null ) && $props['lang'] === $language && $props['hreflang'] === $language, 'only actual current language has aria-current and each label has language metadata: ' . $selected_language . '/' . $language );
        }
        check( translate( $markup, array( 'Language' => 'Idioma', 'English' => 'Inglés', 'Español' => 'Spanish' ) ) === $markup, 'translation never rewrites fixed trigger/self-language names or functional hrefs: ' . $selected_language );
    }
    foreach ( array( false, true ) as $enabled ) {
        $state::$enabled = $enabled; $state::$public = false;
        ob_start(); $translator::switcher(); $markup = ob_get_clean();
        check( '' === $markup, 'language switcher is absent on excluded management/payment/nonpublic requests, enabled=' . (int) $enabled );
    }
    $state::$enabled = false; $state::$public = true;
    ob_start(); $translator::switcher(); $markup = ob_get_clean();
    check( '' === $markup, 'disabled translation does not emit a language switcher' );
    $state::$enabled = true; $state::$public = true;
    if ( null === $previous_uri ) { unset( $_SERVER['REQUEST_URI'] ); } else { $_SERVER['REQUEST_URI'] = $previous_uri; }
    foreach ( array( array( 'en', true, true ), array( 'es', false, true ), array( 'es', true, false ) ) as [$language,$active,$public] ) {
        $state::$language = $language; $state::$active = $active; $state::$public = $public;
        ob_start(); $before = ob_get_level(); $translator::start_buffer(); $after = ob_get_level(); ob_end_clean();
        check( $before === $after, 'output buffer disabled for ' . $language . '/active=' . (int) $active . '/public=' . (int) $public );
    }
    $state::$language = 'en'; $state::$active = true; $state::$public = true;
    check( $translator::gettext( 'Existing translation', 'Offers', 'peptide-core' ) === 'Existing translation', 'EN active request does not apply ES gettext' );
    $state::$language = 'es';
    ob_start(); $translator::start_buffer(); echo '<p>Offers</p>'; ob_end_flush(); $rendered = ob_get_clean();
    check( '<p>Ofertas</p>' === $rendered, 'enabled active Spanish public document installs one working presentation buffer' );
    check( $translator::gettext( 'External domain', 'Offers', 'another-plugin' ) === 'External domain', 'unowned gettext domain remains untouched' );
    $data = array( 'id'=>11307,'name'=>'Retatrutide','html'=>'<p>Offers</p>','description'=>'<p>Search reports</p>','prices'=>array('price'=>'5500','currency_code'=>'USD'),'attributes'=>array(array('name'=>'Size','terms'=>array('20mg*10vials'))),'sku'=>'EXAMPLE-20','extension'=>array('coupon'=>'PRIVATE_SYNTHETIC') );
    foreach ( array( array( 'GET','/wc/store/v1/cart' ),array( 'POST','/wc/store/v1/products/11307' ),array( 'GET','/pc-panapay/v1/return' ) ) as [$method,$route] ) {
        $request = new \WP_REST_Request( $method, $route ); $response = new \WP_REST_Response( $data );
        $target = $translator::translate_response( $response, null, $request );
        check( $target->get_data() === $data, 'cart/mutation/payment DTO excluded: ' . $method . ' ' . $route );
    }
    $request = new \WP_REST_Request( 'GET', '/wc/store/v1/products/11307' );
    $response = $translator::translate_response( new \WP_REST_Response( $data ), null, $request );
    $target = $response->get_data();
    check( $target['html'] !== $data['html'] && $target['description'] !== $data['description'], 'eligible public product read translates only HTML descriptions' );
    unset( $target['html'], $target['description'] ); unset( $data['html'], $data['description'] );
    check( $target === $data, 'all identity, raw prices, specification options and extension fields remain structurally identical' );
    $receipt = array( 'mode'=>'offline actual WP_HTML_Tag_Processor and native REST data classes; no WordPress bootstrap', 'source_sha256'=>hash_file('sha256',dirname(__DIR__).'/includes/class-storefront-translator.php'), 'tests'=>$checks, 'passed'=>count(array_filter($checks,static fn($r)=>$r['pass'])), 'total'=>count($checks) );
    echo json_encode( $receipt, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES ) . "\n";
    exit( $receipt['passed'] === $receipt['total'] ? 0 : 1 );
}
