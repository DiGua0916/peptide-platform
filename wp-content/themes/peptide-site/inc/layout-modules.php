<?php
/**
 * Theme-side layout rendering. The plugin owns storage, validation and publishing.
 * This adapter does not switch the homepage or write a layout document.
 */
defined( 'ABSPATH' ) || exit;

/** Stable keys used by the import tool; paths remain relative to this theme. */
function vc_layout_asset_files(): array {
    return array(
        'home-packaging'            => array( 'assets/brand/packaging-corrected-20261017-1536.webp', 'assets/brand/packaging-corrected-20261017-768.webp' ),
        'carousel-israel-research'  => array( 'assets/images/carousel-israel-research-1440.webp', 'assets/images/carousel-israel-research-768.webp' ),
        'carousel-china-production' => array( 'assets/images/carousel-china-production-1440.webp', 'assets/images/carousel-china-production-768.webp' ),
        'carousel-us-warehouse'     => array( 'assets/images/carousel-us-warehouse-1440.webp', 'assets/images/carousel-us-warehouse-768.webp' ),
        'story-israel-research'     => array( 'assets/images/israel-research-concept-1200.webp', 'assets/images/israel-research-concept-600.webp' ),
        'story-china-manufacturing' => array( 'assets/images/china-manufacturing-concept-1200.webp', 'assets/images/china-manufacturing-concept-600.webp' ),
        'story-us-fulfillment'      => array( 'assets/images/us-fulfillment-concept-1200.webp', 'assets/images/us-fulfillment-concept-600.webp' ),
    );
}

add_filter( 'peptide_core_layout_assets', function ( $assets ) {
    foreach ( vc_layout_asset_files() as $key => [ $file, $small ] ) {
        $path = get_template_directory() . '/' . $file;
        if ( ! is_file( $path ) ) {
            continue;
        }
        $size = wp_getimagesize( $path );
        if ( ! $size ) {
            continue;
        }
        $small_path = get_template_directory() . '/' . $small;
        $small_size = is_file( $small_path ) ? wp_getimagesize( $small_path ) : false;
        $srcset = $small_size ? get_template_directory_uri() . '/' . $small . ' ' . $small_size[0] . 'w, '
            . get_template_directory_uri() . '/' . $file . ' ' . $size[0] . 'w' : '';
        $assets[ $key ] = array(
            'url'    => get_template_directory_uri() . '/' . $file,
            'width'  => $size[0],
            'height' => $size[1],
            'srcset' => $srcset,
        );
    }
    return $assets;
} );

/** Resolve media using the plugin's public API, preserving decorative empty alt. */
function vc_module_media_ref( ?array $ref ): ?array {
    if ( ! $ref || ! class_exists( '\PeptideCore\Modules\Assets' ) ) {
        return null;
    }
    $image = \PeptideCore\Modules\Assets::resolve( $ref );
    if ( ! $image ) {
        return null;
    }
    if ( array_key_exists( 'alt', $ref ) ) {
        $image['alt'] = (string) $ref['alt'];
    }
    $image['fit'] = 'contain' === ( $ref['fit'] ?? '' ) ? 'contain' : 'cover';
    $point       = $ref['focalPoint'] ?? array();
    $image['position'] = max( 0, min( 100, (float) ( $point['xPercent'] ?? 50 ) ) ) . '% '
        . max( 0, min( 100, (float) ( $point['yPercent'] ?? 50 ) ) ) . '%';
    return $image;
}

/**
 * Responsive picture with a shared 767px breakpoint, fit and focal point.
 * Missing mobile media inherits desktop; mobile-only media also works.
 */
function vc_module_picture( array $media, string $class = '', string $sizes = '100vw', array $attrs = array() ): string {
    $desktop = vc_module_media_ref( $media['desktop'] ?? null );
    $mobile  = vc_module_media_ref( $media['mobile'] ?? null );
    $desktop = $desktop ?? $mobile;
    $mobile  = $mobile ?? $desktop;
    if ( ! $desktop ) {
        return '';
    }
    $style = '--vc-media-fit:' . $desktop['fit'] . ';--vc-media-position:' . $desktop['position']
        . ';--vc-media-fit-mobile:' . $mobile['fit'] . ';--vc-media-position-mobile:' . $mobile['position'];
    $html = '<picture class="vc-module-picture ' . esc_attr( $class ) . '" style="' . esc_attr( $style ) . '">';
    if ( $mobile['src'] !== $desktop['src'] ) {
        $html .= '<source media="(max-width: 767px)" srcset="' . esc_attr( $mobile['srcset'] ?: $mobile['src'] ) . '" sizes="' . esc_attr( $sizes ) . '" width="' . (int) $mobile['width'] . '" height="' . (int) $mobile['height'] . '">';
    }
    $html .= '<img src="' . esc_url( $desktop['src'] ) . '" width="' . (int) $desktop['width'] . '" height="' . (int) $desktop['height'] . '" alt="' . esc_attr( $desktop['alt'] ) . '" decoding="async"';
    if ( ! empty( $desktop['srcset'] ) ) {
        $html .= ' srcset="' . esc_attr( $desktop['srcset'] ) . '" sizes="' . esc_attr( $sizes ) . '"';
    }
    $html .= ' loading="' . ( 'eager' === ( $attrs['loading'] ?? '' ) ? 'eager' : 'lazy' ) . '"';
    if ( in_array( $attrs['fetchpriority'] ?? '', array( 'high', 'low', 'auto' ), true ) ) {
        $html .= ' fetchpriority="' . esc_attr( $attrs['fetchpriority'] ) . '"';
    }
    return $html . '></picture>';
}

function vc_module_button( $link, string $class = 'button' ): string {
    if ( ! is_array( $link ) || '' === trim( $link['label'] ?? '' ) || '' === trim( $link['href'] ?? '' ) ) {
        return '';
    }
    $href = esc_url( $link['href'] );
    if ( '' === $href ) {
        return '';
    }
    $blank = '_blank' === ( $link['target'] ?? '' );
    return '<a class="' . esc_attr( $class ) . '" href="' . $href . '"'
        . ( $blank ? ' target="_blank" rel="noopener noreferrer"' : '' )
        . '>' . esc_html( $link['label'] ) . '</a>';
}

/** Same styles in the theme preview and the Gutenberg canvas. */
function vc_enqueue_layout_module_assets(): void {
    if ( ! class_exists( '\PeptideCore\Modules\Registry' ) ) {
        return;
    }
    if ( is_admin() ) {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || ! class_exists( '\PeptideCore\Templates' ) || \PeptideCore\Templates::LAYOUT !== $screen->post_type ) {
            return;
        }
    }
    $root = get_template_directory_uri();
    if ( is_admin() ) {
        wp_enqueue_style( 'vialcove-module-base', $root . '/assets/site.css', array(), (string) filemtime( __DIR__ . '/../assets/site.css' ) );
        wp_enqueue_style( 'vialcove-module-brand', $root . '/assets/brand.css', array( 'vialcove-module-base' ), (string) filemtime( __DIR__ . '/../assets/brand.css' ) );
    }
    $dependency = is_admin() ? array( 'vialcove-module-brand' ) : array( 'vialcove-product-images' );
    if ( wp_style_is( 'peptide-core-modules', 'registered' ) ) {
        $dependency[] = 'peptide-core-modules';
    }
    foreach ( array( 'modules-shared', 'modules-media-text', 'modules-carousel', 'modules-content' ) as $asset ) {
        $file = __DIR__ . '/../assets/' . $asset . '.css';
        if ( is_file( $file ) ) {
            wp_enqueue_style( 'vialcove-' . $asset, $root . '/assets/' . $asset . '.css', $dependency, (string) filemtime( $file ) );
        }
    }
    if ( ! is_admin() ) {
        foreach ( array( 'modules-shared', 'modules-carousel' ) as $asset ) {
            $file = __DIR__ . '/../assets/' . $asset . '.js';
            if ( is_file( $file ) ) {
                wp_enqueue_script( 'vialcove-' . $asset, $root . '/assets/' . $asset . '.js', array(), (string) filemtime( $file ), true );
            }
        }
    }
}
add_action( 'wp_enqueue_scripts', 'vc_enqueue_layout_module_assets', 30 );
add_action( 'enqueue_block_assets', function () {
    if ( is_admin() ) {
        vc_enqueue_layout_module_assets();
    }
}, 30 );
