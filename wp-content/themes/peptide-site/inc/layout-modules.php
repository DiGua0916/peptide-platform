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

/** An optional artwork reference for one card render, including products without a main image. */
function vc_module_card_image( array $ref, string $sizes = '100vw' ): string {
    $image = vc_module_media_ref( $ref );
    if ( ! $image ) { return ''; }
    return '<img class="attachment-woocommerce_single size-woocommerce_single" src="' . esc_url( $image['src'] ) . '"'
        . ( ! empty( $image['srcset'] ) ? ' srcset="' . esc_attr( $image['srcset'] ) . '"' : '' )
        . ' sizes="' . esc_attr( $sizes ) . '"'
        . ' width="' . (int) $image['width'] . '" height="' . (int) $image['height'] . '"'
        . ' alt="' . esc_attr( $image['alt'] ) . '" loading="lazy" decoding="async">';
}

/** Optional card artwork is stored in the same builder row, never on the Woo product. */
add_filter( 'peptide_core_layout_definitions', function ( array $definitions ): array {
    if ( ! isset( $definitions['product_collection']['settings'] ) ) { return $definitions; }
    foreach ( $definitions['product_collection']['settings'] as &$field ) {
        if ( 'products' === ( $field['key'] ?? '' ) ) {
            $field['fields'][] = \PeptideCore\Modules\Fields::f( 'image', 'cardImage', __( '普通商品备用展示图（不影响商品详情）', 'peptide-site' ), null,
                array( 'help' => __( '多规格商品的图片、价格和优惠自动跟随后台排序的第一个可见规格，不使用此图；规格无图时显示示意占位。普通商品可使用此图，留空使用商品主图。', 'peptide-site' ) ) );
            break;
        }
    }
    unset( $field );
    return $definitions;
}, 100 );

/** Optional storefront presentations use the builder's existing field contract. */
add_filter( 'peptide_core_layout_definitions', function ( array $definitions ): array {
    if ( isset( $definitions['hero_h1']['settings'] ) ) {
        $definitions['hero_h1']['settings'][] = \PeptideCore\Modules\Fields::f( 'select', 'presentation', __( '首页排版', 'peptide-site' ), 'standard', array( 'group' => 'layout', 'options' => array( 'standard' => '原图文排版', 'focused' => '简洁首页（手机居中）' ) ) );
        $definitions['hero_h1']['settings'][] = \PeptideCore\Modules\Fields::f( 'link', 'secondaryLink', __( '次要入口', 'peptide-site' ), null, array( 'help' => '例如报告资料入口；文字或链接留空时不显示。' ) );
        $definitions['hero_h1']['settings'][] = \PeptideCore\Modules\Fields::f( 'text', 'caption', __( '图片说明', 'peptide-site' ), '' );
    }
    if ( isset( $definitions['product_collection']['settings'] ) ) {
        $definitions['product_collection']['settings'][] = \PeptideCore\Modules\Fields::f( 'select', 'cardPresentation', __( '商品卡片排版', 'peptide-site' ), 'standard', array( 'group' => 'layout', 'options' => array( 'standard' => '完整资料', 'compact' => '简洁选购' ), 'help' => '简洁选购展示当前规格、价格和详情入口；报告状态在商品详情中查看。真实价格和规格顺序不变。' ) );
    }
    if ( isset( $definitions['info_list']['settings'] ) ) {
        $definitions['info_list']['settings'][] = \PeptideCore\Modules\Fields::f( 'select', 'visualPresentation', __( '图片呈现', 'peptide-site' ), 'standard', array( 'group' => 'layout', 'options' => array( 'standard' => '原图标尺寸', 'illustrated' => '服务插画' ), 'help' => '服务插画用于原信息条且关闭手机滑动时；在每项的图片字段上传插画。' ) );
        $definitions['info_list']['settings'][] = \PeptideCore\Modules\Fields::f( 'number', 'illustrationSizePx', __( '插画尺寸', 'peptide-site' ), array( 'desktop' => 72, 'mobile' => 64 ), array( 'group' => 'layout', 'device' => true, 'min' => 40, 'max' => 112, 'unit' => 'px', 'show_if' => array( 'visualPresentation' => 'illustrated' ), 'help' => '电脑和手机分别设置；窄列会适当缩小图片，为文字保留空间。' ) );
        foreach ( $definitions['info_list']['items']['fields'] as &$field ) {
            if ( 'image' === ( $field['key'] ?? '' ) ) {
                $field['help'] = '图片保留原比例。信息列表样式最高20px；原信息条默认40px，选服务插画后使用可调尺寸。';
            }
        }
        unset( $field );
    }
    return $definitions;
}, 110 );

/** Add illustration sizing without duplicating information-list content or interactions. */
add_filter( 'peptide_core_module_html', function ( $html, array $module, string $context, string $device ) {
    $settings = $module['settings'];
    if ( null !== $html || 'info_list' !== $module['type'] || 'illustrated' !== ( $settings['visualPresentation'] ?? 'standard' )
        || 'standard' !== ( $settings['displayStyle'] ?? 'standard' ) || ! empty( $settings['mobileSwipe'] ) ) {
        return $html;
    }
    $size = $settings['illustrationSizePx'] ?? array( 'desktop' => 72, 'mobile' => 64 );
    $desktop = max( 40, min( 112, (int) $size['desktop'] ) );
    $mobile = max( 40, min( 112, (int) ( $size['mobile'] ?? $desktop ) ) );
    $style = '--vc-service-visual-size:' . $desktop . 'px;--vc-service-visual-size-mobile:' . $mobile . 'px';
    $content = new WP_HTML_Tag_Processor( \PeptideCore\Modules\Fallback::render( $module, $context, $device ) );
    while ( $content->next_tag( array( 'tag_name' => 'IMG', 'class_name' => 'vc-m__icon-img' ) ) ) {
        // Keep WordPress srcset; choose an icon-sized derivative instead of a half-page image.
        $content->set_attribute( 'sizes', '(max-width: 767px) ' . $mobile . 'px, ' . $desktop . 'px' );
    }
    return '<div class="vc-service-illustrations" style="' . esc_attr( $style ) . '">'
        . $content->get_updated_html() . '</div>';
}, 20, 4 );

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
    foreach ( array( 'modules-shared', 'modules-media-text', 'modules-carousel', 'modules-content', 'modules-home', 'modules-editorial' ) as $asset ) {
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
