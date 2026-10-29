<?php
defined( 'ABSPATH' ) || exit;

/** Format the plugin's public, same-variation price pair; never invent a compare price. */
function vc_sale_pair_html( array $pair, $product ): string {
    $unit = $pair['currency_minor_unit'] ?? null;
    $amount = $pair['price'] ?? null;
    $currency = $pair['currency_code'] ?? '';
    if ( ! is_int( $unit ) || $unit < 0 || $unit > 6 || ! is_string( $amount ) || ! ctype_digit( $amount ) || strlen( $amount ) > 14 || ! is_string( $currency ) || ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
        return '';
    }
    $format = static fn( string $value ): string => wc_price( (int) $value / ( 10 ** $unit ), array( 'currency' => $currency, 'decimals' => $unit ) );
    $regular = $pair['regular_price'] ?? '';
    $sale = true === ( $pair['on_sale'] ?? false ) && is_string( $regular ) && ctype_digit( $regular ) && strlen( $regular ) <= 14 && (int) $regular > (int) $amount;
    $html = '<span class="vc-price-set' . ( $sale ? ' vc-price-set--sale' : '' ) . '">';
    $html .= '<span class="vc-price-current"><span class="screen-reader-text">Current price: </span>' . $format( $amount ) . '</span>';
    if ( $sale ) {
        $demo = true === ( $pair['compare_is_demo'] ?? false );
        $html .= '<del class="vc-price-compare"><span class="screen-reader-text">' . ( $demo ? 'Demo comparison price: ' : 'Regular price: ' ) . '</span>' . $format( $regular ) . '</del>';
        $percent = $pair['percent_off'] ?? 0;
        if ( is_numeric( $percent ) && (int) $percent > 0 && (int) $percent <= 100 ) {
            $html .= '<span class="vc-sale-badge">' . esc_html( (int) $percent ) . '% off</span>';
        }
        if ( $demo ) {
            $html .= '<span class="vc-price-demo">Demo pricing</span>';
        }
    }
    $html .= '</span>';
    $priced_product = ! empty( $pair['variation_id'] ) ? wc_get_product( (int) $pair['variation_id'] ) : $product;
    return $html . ( $priced_product ? $priced_product->get_price_suffix() : '' );
}

/** Current-price-only fallback if the public pricing extension is unavailable. */
function vc_current_price_html( $product ): string {
    if ( $product->is_type( 'variable' ) ) {
        $prices = $product->get_variation_prices( true );
        return empty( $prices['price'] ) ? '' : wc_price( current( $prices['price'] ) ) . $product->get_price_suffix();
    }
    return '' === $product->get_price() ? '' : wc_price( wc_get_price_to_display( $product ) ) . $product->get_price_suffix();
}

function vc_public_price_html( $product ): string {
    $pair = vc_product_data( $product )['price_pair'] ?? null;
    $html = is_array( $pair ) ? vc_sale_pair_html( $pair, $product ) : '';
    return '' !== $html ? $html : vc_current_price_html( $product );
}

// Detail pricing remains tied to the selected variation. Before selection,
// show only the real current-price range, not an assumed sale comparison.
add_action( 'wp', function () {
    if ( ! function_exists( 'is_product' ) || ! is_product() ) { return; }
    // The price group carries the contextual badge and demo disclosure.
    remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_sale_flash', 10 );
    remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_price', 10 );
    add_action( 'woocommerce_single_product_summary', function () {
        global $product;
        if ( ! $product ) { return; }
        $html = vc_public_price_html( $product );
        if ( $product->is_type( 'variable' ) ) {
            $prices = $product->get_variation_prices( true );
            $html = '';
            if ( ! empty( $prices['price'] ) ) {
                $min = current( $prices['price'] );
                $max = end( $prices['price'] );
                $html = '<span class="vc-price-current">' . ( (float) $min === (float) $max ? wc_price( $min ) : wc_format_price_range( $min, $max ) ) . '</span>';
                $html .= '<span class="vc-price-hint">Choose a specification to see its price.</span>';
            }
        }
        echo '<p class="price vc-detail-price">' . wp_kses_post( $html ) . '</p>';
    }, 10 );
} );

add_filter( 'woocommerce_available_variation', function ( $data, $parent, $variation ) {
    if ( '' !== $variation->get_price() ) {
        $data['price_html'] = '<span class="price vc-variation-price">' . vc_public_price_html( $variation ) . '</span>';
    }
    return $data;
}, 20, 3 );

add_action( 'wp_enqueue_scripts', function () {
    wp_enqueue_style( 'vialcove-sale-pricing', get_template_directory_uri() . '/assets/sale-pricing.css', array( 'vialcove-product-preview' ), (string) filemtime( __DIR__ . '/../assets/sale-pricing.css' ) );
}, 25 );
