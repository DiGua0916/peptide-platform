<?php
/** Published, template-scoped site parts and visual settings. @package PeptideCore */
namespace PeptideCore\Modules;

use PeptideCore\Templates;
use PeptideCore\Design_Pages;

defined( 'ABSPATH' ) || exit;

final class Site_Design {
	public static function register_hooks(): void {
		add_filter( 'peptide_core_layout_definitions', array( self::class, 'definitions' ) );
		add_filter( 'peptide_core_module_html', array( self::class, 'render' ), 10, 4 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'assets' ), 30 );
		add_action( 'wp_head', array( self::class, 'styles' ), 99 );
		add_action( 'peptide_core_product_card_extras', array( self::class, 'product_extras' ) );
		add_action( 'woocommerce_after_shop_loop_item', static function() { global $product; if ( $product ) { self::product_extras( $product ); } }, 20 );
		add_filter( 'body_class', static function( $classes ) { if ( self::settings() ) { $classes[] = 'pc-design-styled'; } return $classes; } );
	}

	public static function definitions( array $defs ): array {
		$f = static fn( $type, $key, $label, $default = null, $extra = array() ) => Fields::f( $type, $key, __( $label, 'peptide-core' ), $default, $extra );
		$group = array( 'group' => 'style' );
		$fonts = array( 'inherit' => __( 'Keep current font', 'peptide-core' ), 'Manrope' => 'Manrope', 'Geist' => 'Geist', 'Poppins' => 'Poppins' );
		$weights = array( 'inherit' => __( 'Keep current weight', 'peptide-core' ), '400' => __( 'Regular', 'peptide-core' ), '600' => __( 'Semibold', 'peptide-core' ), '700' => __( 'Bold', 'peptide-core' ) );
		$defs['site_header'] = array(
			'title' => __( 'Site header', 'peptide-core' ), 'category' => 'basic', 'icon' => 'align-wide', 'singleRender' => true,
			'settings' => array(
				$f( 'text', 'brandName', 'Brand name', get_bloginfo( 'name' ) ),
				$f( 'media', 'logo', 'Desktop and mobile logo' ),
				$f( 'number', 'logoWidth', 'Logo width', 180, array( 'device' => true, 'min' => 48, 'max' => 400 ) ),
				$f( 'menu', 'menuId', 'Header navigation', null ),
				$f( 'menu', 'secondaryMenuId', 'Secondary header navigation', null ),
				$f( 'toggle', 'showSearch', 'Show search', true ),
				$f( 'text', 'searchPlaceholder', 'Search placeholder', 'Search products' ),
				$f( 'toggle', 'showAccount', 'Show account', true ),
				$f( 'toggle', 'showCart', 'Show cart', true ),
				$f( 'toggle', 'sticky', 'Keep header visible while scrolling', false ),
				$f( 'toggle', 'announcement', 'Show announcement', false ),
				$f( 'toggle', 'announcementHomeOnly', 'Show announcement only on homepage', false ),
				$f( 'html', 'announcementText', 'Announcement text', '' ),
				$f( 'color', 'headerBackground', 'Header background', '#ffffff', $group ),
				$f( 'color', 'headerText', 'Header text', '#101516', $group ),
				$f( 'color', 'announcementBackground', 'Announcement background', '#101516', $group ),
				$f( 'color', 'announcementColor', 'Announcement text color', '#ffffff', $group ),
			), 'items' => null,
		);
		$links = array( $f( 'text', 'label', 'Label', '' ), $f( 'url', 'url', 'URL', '' ), $f( 'icon', 'icon', 'Icon', '' ) );
		$defs['site_footer'] = array(
			'title' => __( 'Site footer', 'peptide-core' ), 'category' => 'basic', 'icon' => 'align-full-width', 'singleRender' => true,
			'settings' => array(
				$f( 'media', 'logo', 'Desktop and mobile logo' ),
				$f( 'text', 'brandName', 'Brand name', get_bloginfo( 'name' ) ),
				$f( 'html', 'description', 'Footer description', '' ),
				$f( 'menu', 'menuId', 'Footer navigation', null ),
				$f( 'text', 'copyright', 'Copyright text', '© {year} {store_name}' ),
				$f( 'toggle', 'showPolicies', 'Show privacy and terms links', true ),
				$f( 'toggle', 'showSubscribe', 'Show subscription form', false ),
				$f( 'text', 'subscribeTitle', 'Subscription heading', 'Updates from our store' ),
				$f( 'toggle', 'mobileNav', 'Show mobile fixed navigation', false ),
				$f( 'select', 'mobileNavSize', 'Fixed navigation devices', 'phone', array( 'options' => array( 'phone' => __( 'Phones', 'peptide-core' ), 'tablet' => __( 'Phones and tablets', 'peptide-core' ) ) ) ),
				$f( 'number', 'mobileNavHeight', 'Fixed navigation height', 0, array( 'min' => 0, 'max' => 160, 'help' => __( 'Use 0 to keep the automatic height.', 'peptide-core' ) ) ),
				$f( 'number', 'mobileNavIconSize', 'Fixed navigation icon size', 20, array( 'min' => 12, 'max' => 48 ) ),
				$f( 'number', 'mobileNavFontSize', 'Fixed navigation text size', 14, array( 'min' => 10, 'max' => 24 ) ),
				$f( 'repeater', 'mobileLinks', 'Fixed navigation links', array(), array( 'max' => 6, 'fields' => $links ) ),
				$f( 'color', 'footerBackground', 'Footer background', '#101516', $group ),
				$f( 'color', 'footerText', 'Footer text', '#ffffff', $group ),
			),
			'items' => array( 'label' => __( 'Social link', 'peptide-core' ), 'fields' => $links, 'default_count' => 0, 'min' => 0, 'max' => 12 ),
		);
		$styles = array(
			$f( 'select', 'fontFamily', 'Site font', 'inherit', array( 'options' => $fonts ) ),
			$f( 'select', 'bodyFontFamily', 'Body font', 'inherit', array( 'options' => $fonts ) ),
			$f( 'select', 'headingFontFamily', 'Heading font', 'inherit', array( 'options' => $fonts ) ),
			$f( 'select', 'bodyWeight', 'Body font weight', 'inherit', array( 'options' => $weights ) ),
			$f( 'select', 'headingWeight', 'Heading font weight', 'inherit', array( 'options' => $weights ) ),
			$f( 'number', 'headingLetterSpacing', 'Heading letter spacing', null, array( 'device' => true, 'min' => -2, 'max' => 12, 'step' => 0.1, 'unit' => 'px' ) ),
			$f( 'number', 'menuLetterSpacing', 'Menu letter spacing', null, array( 'device' => true, 'min' => -2, 'max' => 12, 'step' => 0.1, 'unit' => 'px' ) ),
			$f( 'number', 'bodySize', 'Body text size', 16, array( 'device' => true, 'min' => 12, 'max' => 24 ) ),
			$f( 'number', 'headingSize', 'Main heading size', 48, array( 'device' => true, 'min' => 24, 'max' => 96 ) ),
			$f( 'number', 'productTitleSize', 'Product title size', 20, array( 'device' => true, 'min' => 12, 'max' => 36 ) ),
			$f( 'number', 'detailTitleSize', 'Product detail title size', 0, array( 'device' => true, 'min' => 0, 'max' => 96, 'help' => __( 'Use 0 to keep the theme size.', 'peptide-core' ) ) ),
			$f( 'number', 'contentWidth', 'Content width', 1280, array( 'min' => 720, 'max' => 1920 ) ),
			$f( 'number', 'pageGutter', 'Page side spacing', 24, array( 'device' => true, 'min' => 0, 'max' => 160 ) ),
			$f( 'number', 'moduleGap', 'Default block spacing', 32, array( 'device' => true, 'min' => 0, 'max' => 160 ) ),
			$f( 'number', 'buttonRadius', 'Button corner radius', 8, array( 'min' => 0, 'max' => 48 ) ),
			$f( 'toggle', 'transitionEnabled', 'Enable page transitions', false ),
			$f( 'image', 'transitionImage', 'Page transition image', null, array( 'show_if' => array( 'transitionEnabled' => true ) ) ),
			$f( 'select', 'transitionDuration', 'Page transition duration', '500', array( 'options' => array( '300' => '300 ms', '500' => '500 ms', '700' => '700 ms' ), 'show_if' => array( 'transitionEnabled' => true ) ) ),
		);
		foreach ( array(
			'background' => array( 'Page background', '#ffffff' ), 'text' => array( 'Body text color', '#101516' ),
			'heading' => array( 'Heading color', '#101516' ), 'muted' => array( 'Secondary text color', '#687172' ),
			'price' => array( 'Current price color', '#101516' ), 'oldPrice' => array( 'Original price color', '#687172' ),
			'buttonBackground' => array( 'Primary button background', '#101516' ), 'buttonText' => array( 'Primary button text', '#ffffff' ),
			'secondaryBackground' => array( 'Secondary button background', '#f3f5f4' ), 'secondaryText' => array( 'Secondary button text', '#101516' ),
		) as $key => $row ) { $styles[] = $f( 'color', $key, $row[0], $row[1], $group ); }
		$styles = array_merge( $styles, array(
			$f( 'toggle', 'quickView', 'Show product quick view', false ),
			$f( 'toggle', 'secondImage', 'Show second image on hover', false ),
			$f( 'toggle', 'showRating', 'Show real product ratings', false ),
			$f( 'toggle', 'showSwatches', 'Show product color swatches', false ),
			$f( 'number', 'lowStockThreshold', 'Low stock threshold', 0, array( 'min' => 0, 'max' => 10000 ) ),
			$f( 'text', 'lowStockText', 'Low stock text', 'Only {stock} left in stock' ),
			$f( 'select', 'productImageFit', 'Product image fit', 'contain', array( 'options' => array( 'contain' => __( 'Fit inside', 'peptide-core' ), 'cover' => __( 'Fill frame', 'peptide-core' ) ) ) ),
			$f( 'select', 'reviewLayout', 'Review display layout', 'inherit', array( 'options' => array( 'inherit' => __( 'Keep block layout', 'peptide-core' ), 'grid' => __( 'Grid', 'peptide-core' ), 'carousel' => __( 'Carousel', 'peptide-core' ) ) ) ),
			$f( 'toggle', 'reviewAutoplay', 'Autoplay review carousel', false ),
			$f( 'number', 'reviewInterval', 'Review carousel interval (ms)', 5000, array( 'min' => 2000, 'max' => 20000 ) ),
			$f( 'select', 'reviewOrder', 'Review sort order', 'newest', array( 'options' => array( 'newest' => __( 'Newest first', 'peptide-core' ), 'oldest' => __( 'Oldest first', 'peptide-core' ), 'rating_desc' => __( 'Highest rating first', 'peptide-core' ), 'rating_asc' => __( 'Lowest rating first', 'peptide-core' ) ) ) ),
			$f( 'number', 'reviewMinimumRating', 'Minimum review rating', 0, array( 'min' => 0, 'max' => 5 ) ),
			$f( 'toggle', 'reviewShowRating', 'Show review star ratings', true ),
			$f( 'number', 'reviewLimit', 'Maximum displayed reviews', 0, array( 'min' => 0, 'max' => 100, 'help' => __( 'Use 0 to keep each block limit.', 'peptide-core' ) ) ),
			$f( 'select', 'reviewVideoRatio', 'Review video aspect ratio', 'original', array( 'options' => array( 'original' => __( 'Original ratio', 'peptide-core' ), '16/9' => '16:9', '4/3' => '4:3', '1/1' => '1:1', '9/16' => '9:16' ) ) ),
		) );
		$defs['site_styles'] = array( 'title' => __( 'Global site settings', 'peptide-core' ), 'category' => 'basic', 'icon' => 'admin-appearance', 'singleRender' => true, 'settings' => $styles, 'items' => null );
		$defs['context_content'] = array(
			'title' => __( 'Original page content', 'peptide-core' ), 'category' => 'basic', 'icon' => 'admin-page', 'singleRender' => true,
			'settings' => array(), 'items' => null,
		);
		foreach ( array( 'site_header', 'site_footer', 'site_styles', 'context_content' ) as $type ) { $defs[ $type ]['multiple'] = false; }
		return $defs;
	}

	private static function modules( string $doc ): array {
		$template = Templates::current_id();
		$id = Templates::document_id( $template, $doc, false );
		if ( ! $id ) { return array(); }
		if ( Templates::previewing_id() ) {
			$draft = Layout::preview_draft( $template, $doc );
			return $draft ? $draft['modules'] : Layout::modules_from_content( (string) get_post_field( 'post_content', $id ) );
		}
		return Layout::published_modules( $doc, $template );
	}

	public static function settings(): array {
		foreach ( self::modules( 'global' ) as $row ) {
			if ( 'vialcove/site-styles' === $row['block'] && false !== ( $row['attrs']['enabled'] ?? true ) ) {
				return Renderer::normalize( 'site_styles', $row['attrs'] )['settings'];
			}
		}
		return array();
	}

	/** Stable, sanitized review display settings for product reviews and commerce blocks. */
	public static function review_settings(): array {
		$s = self::settings();
		return array(
			'layout' => $s['reviewLayout'] ?? 'inherit', 'autoplay' => (bool) ( $s['reviewAutoplay'] ?? false ),
			'interval' => (int) ( $s['reviewInterval'] ?? 5000 ), 'order' => $s['reviewOrder'] ?? 'newest',
			'minimum_rating' => (int) ( $s['reviewMinimumRating'] ?? 0 ), 'show_rating' => (bool) ( $s['reviewShowRating'] ?? true ),
			'limit' => (int) ( $s['reviewLimit'] ?? 0 ), 'video_ratio' => $s['reviewVideoRatio'] ?? 'original',
		);
	}

	private static function font( string $font, string $fallback = 'inherit' ): string {
		return in_array( $font, array( 'Manrope', 'Geist', 'Poppins' ), true ) ? $font : $fallback;
	}

	public static function has_part( string $doc ): bool {
		return in_array( $doc, array( 'header', 'footer' ), true ) && (bool) self::modules( $doc );
	}

	public static function render_part( string $doc ): string {
		return self::has_part( $doc ) ? Layout::render_current( $doc ) : '';
	}

	public static function assets(): void {
		$url = plugins_url( 'assets/', PEPTIDE_CORE_FILE );
		wp_enqueue_style( 'peptide-site-design', $url . 'site-design.css', array(), PEPTIDE_CORE_VERSION . '.' . filemtime( PEPTIDE_CORE_DIR . '/assets/site-design.css' ) );
		$settings = self::settings();
		if ( array_filter( array_intersect_key( $settings, array_flip( array( 'fontFamily', 'bodyFontFamily', 'headingFontFamily' ) ) ), static fn( $font ) => 'inherit' !== $font ) ) {
			wp_enqueue_style( 'peptide-site-fonts', $url . 'site-fonts.css', array(), PEPTIDE_CORE_VERSION );
		}
		if ( self::settings() || self::has_part( 'header' ) || self::has_part( 'footer' ) || Templates::previewing_id() ) {
			wp_enqueue_script( 'peptide-site-design', $url . 'site-design.js', array(), PEPTIDE_CORE_VERSION . '.' . filemtime( PEPTIDE_CORE_DIR . '/assets/site-design.js' ), array( 'in_footer' => true ) );
			wp_enqueue_script( 'peptide-site-design-details', $url . 'site-design-details.js', array(), PEPTIDE_CORE_VERSION . '.' . filemtime( PEPTIDE_CORE_DIR . '/assets/site-design-details.js' ), array( 'in_footer' => true ) );
			$image = absint( $settings['transitionImage']['attachmentId'] ?? 0 );
			wp_add_inline_script( 'peptide-site-design-details', 'window.pcDesignDetails=' . wp_json_encode( array(
				'transition' => ! empty( $settings['transitionEnabled'] ) && ! Templates::previewing_id(),
				'duration' => (int) ( $settings['transitionDuration'] ?? 500 ),
				'image' => $image && wp_attachment_is_image( $image ) ? wp_get_attachment_image_url( $image, 'thumbnail' ) : '',
				'background' => $settings['background'] ?? '#ffffff',
				'exclude' => function_exists( 'wc_get_page_permalink' ) ? array_values( array_filter( array_map( static fn( $page ) => wp_parse_url( wc_get_page_permalink( $page ), PHP_URL_PATH ), array( 'cart', 'checkout', 'myaccount' ) ) ) ) : array(),
			) ) . ';', 'before' );
		}
	}

	public static function styles(): void {
		$s = self::settings();
		if ( ! $s ) { return; }
		$css = 'body.pc-design-styled{--pc-width:' . (int) $s['contentWidth'] . 'px;--pc-radius:' . (int) $s['buttonRadius'] . 'px;--pc-image-fit:' . ( 'cover' === $s['productImageFit'] ? 'cover' : 'contain' ) . ';';
		foreach ( array( 'background', 'text', 'heading', 'muted', 'price', 'oldPrice', 'buttonBackground', 'buttonText', 'secondaryBackground', 'secondaryText' ) as $key ) {
			if ( preg_match( '/^#[a-f0-9]{3,8}$/i', $s[ $key ] ) ) { $css .= '--pc-' . $key . ':' . $s[ $key ] . ';'; }
		}
		$body_font = self::font( $s['bodyFontFamily'], self::font( $s['fontFamily'] ) );
		$heading_font = self::font( $s['headingFontFamily'], $body_font );
		if ( 'inherit' !== $body_font ) { $css .= 'font-family:"' . esc_attr( $body_font ) . '",sans-serif;'; }
		if ( 'inherit' !== $s['bodyWeight'] ) { $css .= 'font-weight:' . (int) $s['bodyWeight'] . ';'; }
		$css .= '}';
		if ( 'inherit' !== $heading_font || 'inherit' !== $s['headingWeight'] ) {
			$css .= '.pc-design-styled :is(h1,h2,h3,h4,h5,h6){';
			if ( 'inherit' !== $heading_font ) { $css .= 'font-family:"' . esc_attr( $heading_font ) . '",sans-serif;'; }
			if ( 'inherit' !== $s['headingWeight'] ) { $css .= 'font-weight:' . (int) $s['headingWeight'] . ';'; }
			$css .= '}';
		}
		foreach ( array( 'desktop' => '', 'mobile' => '@media(max-width:767px){' ) as $device => $open ) {
			$css .= $open . 'body.pc-design-styled{';
			foreach ( array( 'bodySize', 'headingSize', 'productTitleSize', 'moduleGap', 'pageGutter' ) as $key ) {
				$value = $s[ $key ][ $device ] ?? $s[ $key ]['desktop'];
				$css .= '--pc-' . $key . ':' . (int) $value . 'px;';
			}
			$css .= '}';
			foreach ( array( 'headingLetterSpacing' => '.pc-design-styled :is(h1,h2,h3,h4,h5,h6)', 'menuLetterSpacing' => '.pc-design-styled :is(.primary-nav,.pc-secondary-nav,.footer-nav,.pc-mobile-fixed)' ) as $key => $selector ) {
				$value = $s[ $key ][ $device ] ?? $s[ $key ]['desktop'];
				if ( is_numeric( $value ) ) { $css .= $selector . '{letter-spacing:' . (float) $value . 'px}'; }
			}
			$detail = (int) ( $s['detailTitleSize'][ $device ] ?? $s['detailTitleSize']['desktop'] );
			if ( $detail > 0 ) { $css .= '.pc-design-styled.single-product :is(.product_title,.product-summary h1){font-size:' . $detail . 'px}'; }
			$css .= $open ? '}' : '';
		}
		if ( 'original' !== $s['reviewVideoRatio'] ) { $css .= '.pc-design-styled [data-pc-commerce*="reviews"] video{width:100%;aspect-ratio:' . $s['reviewVideoRatio'] . ';object-fit:contain}'; }
		echo '<style id="pc-published-site-styles">' . $css . '</style>'; // All values are allowlisted/sanitized.
	}

	private static function logo( array $media, string $name ): string {
		$desktop = absint( $media['desktop']['attachmentId'] ?? 0 );
		$mobile = absint( $media['mobile']['attachmentId'] ?? 0 );
		if ( $desktop || $mobile ) {
			$id = $desktop ?: $mobile;
			$html = '<picture>';
			if ( $mobile ) { $html .= '<source media="(max-width:767px)" srcset="' . esc_url( wp_get_attachment_image_url( $mobile, 'full' ) ) . '">'; }
			$html .= wp_get_attachment_image( $id, 'full', false, array( 'class' => 'pc-design-logo', 'alt' => $media['desktop']['alt'] ?? $name ) );
			return $html . '</picture>';
		}
		if ( function_exists( 'vc_brand_logo' ) ) { ob_start(); vc_brand_logo(); return (string) ob_get_clean(); }
		return esc_html( $name );
	}

	private static function icon( string $name ): string {
		if ( '' === $name ) { return ''; }
		$paths = array(
			'check' => 'm5 12 4 4L19 6', 'mail' => 'M3 5h18v14H3z M3 5l9 8 9-8',
			'phone' => 'M5 3h4l2 5-3 2c2 3 3 4 6 6l2-3 5 2v4c-1 4-5 3-10-1S2 8 5 3',
			'location' => 'M12 22S4 15 4 9a8 8 0 0 1 16 0c0 6-8 13-8 13z M9 9a3 3 0 1 0 6 0 3 3 0 0 0-6 0',
			'star' => 'm12 2 3 6 7 1-5 5 1 7-6-3-6 3 1-7-5-5 7-1z',
			'clock' => 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18 M12 7v5l4 2',
			'box' => 'm3 7 9-5 9 5v10l-9 5-9-5z M3 7l9 5 9-5 M12 12v10',
			'chat' => 'M3 3h18v14H8l-5 4z',
			'globe' => 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18 M3 12h18 M12 3c5 5 5 13 0 18-5-5-5-13 0-18',
			'shield' => 'm12 2 9 4v6c0 6-9 10-9 10S3 18 3 12V6z',
			'lock' => 'M5 10h14v12H5z M8 10V6a4 4 0 0 1 8 0v4',
			'truck' => 'M2 5h13v12H2z M15 9h4l3 4v4h-7 M5 17a2 2 0 1 0 4 0 M16 17a2 2 0 1 0 4 0',
			'flask' => 'M8 2h8 M10 2v7l-6 11c-1 2 17 2 16 0L14 9V2 M7 15h10',
		);
		return '<svg class="pc-design-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="' . esc_attr( $paths[ $name ] ?? 'M5 2h10l4 4v16H5z M8 11h8 M8 15h8' ) . '"/></svg>';
	}

	private static function menu( int $id, string $location ): string {
		$args = array();
		if ( $id && class_exists( '\PeptideCore\Menus' ) ) {
			$menu = \PeptideCore\Menus::public_menu( $id );
			if ( null !== $menu ) { $args['items'] = $menu; }
		}
		if ( locate_template( 'template-parts/' . ( 'header' === $location ? 'navigation' : 'footer-navigation' ) . '.php' ) ) {
			ob_start(); get_template_part( 'template-parts/' . ( 'header' === $location ? 'navigation' : 'footer-navigation' ), null, $args ); return (string) ob_get_clean();
		}
		return $id ? (string) wp_nav_menu( array( 'menu' => $id, 'echo' => false, 'fallback_cb' => false ) ) : '';
	}

	private static function secondary_menu( int $id ): string {
		if ( ! $id ) { return ''; }
		$items = class_exists( '\\PeptideCore\\Menus' ) ? \PeptideCore\Menus::public_menu( $id ) : null;
		if ( null === $items ) { return ''; }
		$render = static function ( array $nodes ) use ( &$render ): string {
			$out = '<ul>';
			foreach ( $nodes as $node ) {
				$url = esc_url( $node['url'] ?? '' ); $title = esc_html( $node['title'] ?? '' );
				if ( '' === $url && empty( $node['children'] ) ) { continue; }
				$rel = array_intersect( preg_split( '/\s+/', (string) ( $node['rel'] ?? '' ) ), array( 'nofollow', 'noopener', 'noreferrer', 'sponsored', 'ugc' ) );
				if ( '_blank' === ( $node['target'] ?? '' ) ) { $rel = array_unique( array_merge( $rel, array( 'noopener', 'noreferrer' ) ) ); }
				$out .= '<li>' . ( $url ? '<a href="' . $url . '"' . ( ! empty( $node['current'] ) ? ' aria-current="page"' : '' ) . ( '_blank' === ( $node['target'] ?? '' ) ? ' target="_blank"' : '' ) . ( $rel ? ' rel="' . esc_attr( implode( ' ', $rel ) ) . '"' : '' ) . '>' . $title . '</a>' : '<span>' . $title . '</span>' );
				if ( ! empty( $node['children'] ) ) { $out .= $render( $node['children'] ); }
				$out .= '</li>';
			}
			return $out . '</ul>';
		};
		return $items ? '<nav class="pc-secondary-nav container" aria-label="' . esc_attr__( 'Secondary navigation', 'peptide-core' ) . '">' . $render( $items ) . '</nav>' : '';
	}

	public static function render( $html, array $m, string $context, string $device ) {
		$s = $m['settings'];
		if ( 'context_content' === $m['type'] ) {
			$native = Design_Pages::native_content();
			return $native || 'public' === $context ? $native : Renderer::notice( __( 'The original page content appears here in the page preview.', 'peptide-core' ) );
		}
		if ( 'site_styles' === $m['type'] ) { return 'public' === $context ? '' : '<div class="vc-m__notice">' . esc_html__( 'Global settings apply to the entire preview. Save as draft or publish when ready.', 'peptide-core' ) . '</div>'; }
		if ( 'site_header' === $m['type'] ) {
			$width = $s['logoWidth']['desktop'] ?? 180;
			$mobile_width = $s['logoWidth']['mobile'] ?? $width;
			$out = '';
			if ( $s['announcement'] && ( ! $s['announcementHomeOnly'] || is_front_page() ) ) {
				$out .= '<div class="pc-design-announcement" style="background:' . esc_attr( $s['announcementBackground'] ) . ';color:' . esc_attr( $s['announcementColor'] ) . '">' . wp_kses_post( $s['announcementText'] ) . '</div>';
			}
			$out .= '<header class="site-header pc-design-header' . ( $s['sticky'] ? ' is-sticky' : '' ) . '" style="--pc-logo-width:' . (int) $width . 'px;--pc-logo-mobile:' . (int) $mobile_width . 'px;background:' . esc_attr( $s['headerBackground'] ) . ';color:' . esc_attr( $s['headerText'] ) . '"><div class="container header-inner">';
			$out .= '<a class="brand" href="' . esc_url( home_url( '/' ) ) . '" aria-label="' . esc_attr( $s['brandName'] ) . '">' . self::logo( $s['logo'], $s['brandName'] ) . '</a>';
			$out .= self::menu( (int) $s['menuId'], 'header' );
			$out .= '<div class="header-actions">';
			if ( $s['showSearch'] ) {
				$out .= '<details class="pc-header-search"><summary>' . esc_html__( 'Search', 'peptide-core' ) . '</summary><form role="search" method="get" action="' . esc_url( home_url( '/' ) ) . '"><label class="screen-reader-text" for="pc-header-search">' . esc_html__( 'Search products', 'peptide-core' ) . '</label><input id="pc-header-search" type="search" name="s" placeholder="' . esc_attr( $s['searchPlaceholder'] ) . '" required><input type="hidden" name="post_type" value="product"><button type="submit">' . esc_html__( 'Search', 'peptide-core' ) . '</button></form></details>';
			}
			if ( $s['showAccount'] ) { $out .= '<a class="account-link" href="' . esc_url( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url() ) . '">' . esc_html__( 'Account', 'peptide-core' ) . '</a>'; }
			if ( $s['showCart'] ) {
				$out .= '<a class="cart-link" href="' . esc_url( function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' ) ) . '">' . esc_html__( 'Cart', 'peptide-core' ) . ' <span class="vc-cart-count">' . ( function_exists( 'WC' ) && WC()->cart ? (int) WC()->cart->get_cart_contents_count() : 0 ) . '</span></a>';
			}
			$out .= '<button class="menu-toggle" type="button" aria-label="' . esc_attr__( 'Open menu', 'peptide-core' ) . '" aria-expanded="false" aria-controls="primary-nav"><span></span><span></span></button>';
			return $out . '</div></div>' . self::secondary_menu( (int) $s['secondaryMenuId'] ) . '</header>';
		}
		if ( 'site_footer' === $m['type'] ) {
			$out = '<footer class="site-footer pc-design-footer" style="background:' . esc_attr( $s['footerBackground'] ) . ';color:' . esc_attr( $s['footerText'] ) . '"><div class="container"><div class="footer-top"><a class="brand brand--footer" href="' . esc_url( home_url( '/' ) ) . '">' . self::logo( $s['logo'], $s['brandName'] ) . '</a><div>' . wp_kses_post( $s['description'] ) . '</div>' . self::menu( (int) $s['menuId'], 'footer' ) . '</div>';
			if ( $s['showSubscribe'] && Registry::get( 'subscribe' ) ) {
				$out .= Renderer::render( 'subscribe', array( 'settings' => array( 'title' => $s['subscribeTitle'] ) ), $context );
			}
			$out .= '<div class="footer-bottom"><span>' . esc_html( strtr( $s['copyright'], array( '{year}' => wp_date( 'Y' ), '{store_name}' => $s['brandName'] ) ) ) . '</span>';
			foreach ( $m['items'] as $item ) {
				if ( $item['enabled'] && ! empty( $item['settings']['url'] ) ) { $out .= '<a rel="noopener noreferrer" href="' . esc_url( $item['settings']['url'] ) . '">' . self::icon( $item['settings']['icon'] ) . esc_html( $item['settings']['label'] ) . '</a>'; }
			}
			if ( $s['showPolicies'] ) {
				$privacy = get_privacy_policy_url();
				$terms = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'terms' ) : '';
				if ( $privacy ) { $out .= '<a href="' . esc_url( $privacy ) . '">' . esc_html__( 'Privacy policy', 'peptide-core' ) . '</a>'; }
				if ( $terms ) { $out .= '<a href="' . esc_url( $terms ) . '">' . esc_html__( 'Terms', 'peptide-core' ) . '</a>'; }
			}
			$out .= '</div></div></footer>';
			if ( $s['mobileNav'] && $s['mobileLinks'] ) {
				$out .= '<nav class="pc-mobile-fixed' . ( 'tablet' === $s['mobileNavSize'] ? ' includes-tablet' : '' ) . '" style="--pc-mobile-nav-height:' . (int) $s['mobileNavHeight'] . 'px;--pc-mobile-nav-icon:' . (int) $s['mobileNavIconSize'] . 'px;--pc-mobile-nav-font:' . (int) $s['mobileNavFontSize'] . 'px" aria-label="' . esc_attr__( 'Mobile navigation', 'peptide-core' ) . '">';
				foreach ( $s['mobileLinks'] as $link ) {
					if ( $link['enabled'] && $link['settings']['url'] ) { $out .= '<a href="' . esc_url( $link['settings']['url'] ) . '">' . self::icon( $link['settings']['icon'] ) . '<span>' . esc_html( $link['settings']['label'] ) . '</span></a>'; }
				}
				$out .= '</nav>';
			}
			return $out;
		}
		return $html;
	}

	public static function product_extras( $product ): void {
		$s = self::settings();
		if ( ! $s || ! $product instanceof \WC_Product || 'publish' !== $product->get_status() || ! $product->is_visible() ) { return; }
		$out = '';
		if ( $s['secondImage'] ) {
			$images = $product->get_gallery_image_ids();
			if ( $images ) { $out .= wp_get_attachment_image( $images[0], 'woocommerce_thumbnail', false, array( 'class' => 'pc-secondary-image', 'loading' => 'lazy' ) ); }
		}
		if ( $s['showRating'] && $product->get_rating_count() ) { $out .= wc_get_rating_html( $product->get_average_rating(), $product->get_rating_count() ); }
		if ( (int) $s['lowStockThreshold'] > 0 && $product->managing_stock() && $product->is_in_stock()
			&& null !== $product->get_stock_quantity() && $product->get_stock_quantity() <= (int) $s['lowStockThreshold'] ) {
			$out .= '<span class="pc-stock-hint">' . esc_html( str_replace( '{stock}', (string) $product->get_stock_quantity(), $s['lowStockText'] ) ) . '</span>';
		}
		if ( $s['showSwatches'] ) {
			$colors = wc_get_product_terms( $product->get_id(), 'pa_color' );
			if ( ! is_wp_error( $colors ) && $colors ) {
				$out .= '<span class="pc-product-swatches">';
				foreach ( $colors as $term ) {
					$color = sanitize_hex_color( (string) get_term_meta( $term->term_id, 'color', true ) );
					if ( ! $color ) {
						$named = array( 'black' => '#000000', 'white' => '#ffffff', 'red' => '#dc2626', 'green' => '#16824a', 'blue' => '#2563eb', 'gray' => '#6b7280', 'gold' => '#b79432' );
						$color = $named[ strtolower( $term->name ) ] ?? '';
					}
					$out .= '<a href="' . esc_url( add_query_arg( 'attribute_pa_color', $term->slug, $product->get_permalink() ) ) . '" aria-label="' . esc_attr( $term->name ) . '" title="' . esc_attr( $term->name ) . '"' . ( $color ? ' style="background:' . esc_attr( $color ) . '"' : '' ) . '>' . ( $color ? '' : esc_html( $term->name ) ) . '</a>';
				}
				$out .= '</span>';
			}
		}
		if ( $s['quickView'] ) {
			$out .= '<button type="button" class="pc-quick-view" data-pc-quick-url="' . esc_url( rest_url( 'wc/store/v1/products/' . $product->get_id() ) ) . '" data-pc-product-url="' . esc_url( $product->get_permalink() ) . '" data-pc-close="' . esc_attr__( 'Close', 'peptide-core' ) . '" data-pc-details="' . esc_attr__( 'View product', 'peptide-core' ) . '" data-pc-error="' . esc_attr__( 'The product could not be loaded. Open the product page to continue.', 'peptide-core' ) . '">' . esc_html__( 'Quick view', 'peptide-core' ) . '</button>';
		}
		if ( $out ) { echo '<div class="pc-product-extras">' . $out . '</div>'; }
	}
}
