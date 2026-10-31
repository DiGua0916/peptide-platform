<?php
/** O24: a new shoppable media gallery. Old hotspots and product showcase stay independent. */
namespace PeptideCore\Modules;

use PeptideCore\Templates;
use PeptideCore\YouTube_Metadata;

defined( 'ABSPATH' ) || exit;

final class Shop_By_Gram {
	private static function f( string $type, string $key, string $label, $default = null, array $extra = array() ): array {
		return Fields::f( $type, $key, $label, $default, $extra );
	}

	public static function definition(): array {
		$rich = array( 'ui' => 'richtext', 'richTextMedia' => 'image', 'richMediaBlock' => 'vialcove/shop-by-gram' );
		$row_rich = $rich + array( 'richMediaItem' => true, 'panel' => 'content' );
		$fields = array(
			self::f( 'select', 'mediaType', '媒体类型', 'image', array( 'ui' => 'item-title-option', 'options' => array( 'image' => '热点图片', 'video' => '视频' ), 'panel' => 'media' ) ),
			self::f( 'text', 'title', '媒体说明', '', array( 'panel' => 'media', 'help' => '用于图片说明、视频标题和打开按钮的无障碍名称。' ) ),
			self::f( 'image', 'image', '图片', null, array( 'panel' => 'media', 'show_if' => array( 'mediaType' => 'image' ), 'help' => '建议图片宽度1000px，高度自适应。不会自动填入参考站图片。' ) ),
			self::f( 'url', 'youtubeUrl', 'YouTube 视频链接', '', array( 'panel' => 'media', 'show_if' => array( 'mediaType' => 'video' ), 'help' => '填写完整 HTTPS YouTube 链接；选择本地视频时优先使用本地文件。' ) ),
			self::f( 'video', 'localVideo', '本地视频', null, array( 'panel' => 'media', 'show_if' => array( 'mediaType' => 'video' ) ) ),
			self::f( 'toggle', 'autoplay', '自动播放', true, array( 'panel' => 'media', 'show_if' => array( 'mediaType' => 'video' ), 'help' => '自动播放始终静音；离屏、页面隐藏及减少动态效果时暂停。' ) ),
			self::f( 'toggle', 'loop', '循环播放', true, array( 'panel' => 'media', 'show_if' => array( 'mediaType' => 'video' ) ) ),
			self::f( 'category', 'collection', '选择专辑', null, array( 'ui' => 'collection', 'panel' => 'content', 'help' => '关联一个真实商品分类，按目录顺序展示前两个当前可公开商品。' ) ),
			self::f( 'html', 'descriptionHtml', '热点描述', null, $row_rich ),
			self::f( 'html', 'tagsHtml', '热点标签', null, $row_rich ),
		);
		foreach ( array( 1 => array( 30, 40 ), 2 => array( 70, 60 ) ) as $n => $position ) {
			$fields[] = self::f( 'number', 'hotspot' . $n . 'X', '热点 ' . $n . ' 水平位置 (%)', $position[0], array( 'ui' => 'range', 'min' => 0, 'max' => 100, 'unit' => '%', 'panel' => 'hotspots' ) );
			$fields[] = self::f( 'number', 'hotspot' . $n . 'Y', '热点 ' . $n . ' 垂直位置 (%)', $position[1], array( 'ui' => 'range', 'min' => 0, 'max' => 100, 'unit' => '%', 'panel' => 'hotspots' ) );
		}
		return array(
			'title' => '热点购物', 'category' => 'operation', 'icon' => 'format-gallery',
			'settings' => array(
				self::f( 'text', 'title', '标题', 'Shop by image', array( 'group' => 'general', 'panel' => 'content' ) ),
				self::f( 'html', 'descriptionHtml', '简短描述', null, $rich + array( 'group' => 'general', 'panel' => 'content' ) ),
				self::f( 'number', 'desktopColumns', '桌面端每行显示', 6, array( 'ui' => 'range', 'min' => 3, 'max' => 6, 'panel' => 'layout' ) ),
				self::f( 'number', 'gap', '图片间距', 12, array( 'ui' => 'range', 'min' => 0, 'max' => 30, 'unit' => 'px', 'panel' => 'layout', 'help' => '手机固定显示两张卡片，可水平滑动。' ) ),
				self::f( 'color', 'iconBackground', '图标背景色', '#1a1a1a', array( 'panel' => 'style' ) ),
				self::f( 'color', 'iconColor', '图标颜色', '#ffffff', array( 'panel' => 'style' ) ),
			),
			'panels' => array(
				array( 'id' => 'content', 'label' => '标题与简述', 'initialOpen' => true, 'fields' => array( 'title', 'descriptionHtml' ) ),
				array( 'id' => 'layout', 'label' => '布局设置', 'initialOpen' => true, 'fields' => array( 'desktopColumns', 'gap' ) ),
				array( 'id' => 'style', 'label' => '图标颜色', 'fields' => array( 'iconBackground', 'iconColor' ) ),
			),
			'items' => array( 'label' => '热点商品', 'default_count' => 0, 'min' => 0, 'max' => 24, 'title_field' => 'mediaType', 'fields' => $fields,
				'panels' => array( array( 'id' => 'media', 'label' => '媒体', 'initialOpen' => true ), array( 'id' => 'content', 'label' => '专辑与内容', 'initialOpen' => true ), array( 'id' => 'hotspots', 'label' => '两个热点位置', 'initialOpen' => true ) ) ),
		);
	}

	/** Full catalog eligibility is checked before a product consumes either of the two slots. */
	private static function products( int $category ): array {
		if ( $category < 1 || ! function_exists( 'wc_get_products' ) ) { return array(); }
		$term = get_term( $category, 'product_cat' );
		if ( ! $term instanceof \WP_Term || 'product_cat' !== $term->taxonomy ) { return array(); }
		$out = array(); $seen = array(); $page = 1;
		do {
			$result = wc_get_products( array( 'status' => 'publish', 'visibility' => 'visible', 'has_password' => false, 'category' => array( $term->slug ), 'return' => 'objects', 'limit' => 24, 'page' => $page, 'paginate' => true, 'orderby' => array( 'menu_order' => 'ASC', 'title' => 'ASC', 'ID' => 'ASC' ), 'order' => 'ASC' ) );
			if ( ! is_object( $result ) || ! is_array( $result->products ?? null ) ) { return array(); }
			foreach ( $result->products as $product ) {
				if ( ! Products::public_catalog_product( $product ) || isset( $seen[ $product->get_id() ] ) ) { continue; }
				$seen[ $product->get_id() ] = true;
				// A missing price is never replaced by a fabricated $0 demo price. Real free prices remain real.
				if ( '' === $product->get_price() || '' === trim( $product->get_name() ) || '' === $product->get_permalink() ) { continue; }
				$out[] = $product;
				if ( 2 === count( $out ) ) { return $out; }
			}
			$page++;
		} while ( $result->products && $page <= (int) ( $result->max_num_pages ?? 0 ) );
		return $out;
	}

	/** Same public attachment rules as existing video products; never expose private review uploads. */
	private static function public_file( int $id, string $kind ): bool {
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || 'attachment' !== $post->post_type || ! in_array( $post->post_status, array( 'inherit', 'publish' ), true ) || '' !== $post->post_password || ! str_starts_with( (string) get_post_mime_type( $id ), $kind . '/' ) || metadata_exists( 'post', $id, '_pc_review_owner' ) || metadata_exists( 'post', $id, '_pc_review_file' ) ) { return false; }
		if ( $post->post_parent ) { $parent = get_post( (int) $post->post_parent ); if ( ! $parent || '' !== $parent->post_password || ! is_post_publicly_viewable( $parent ) ) { return false; } }
		return true;
	}

	private static function image( ?array $ref, string $label = '' ): string {
		$id = (int) ( $ref['attachmentId'] ?? 0 );
		if ( $id && ! self::public_file( $id, 'image' ) ) { return ''; }
		$image = Assets::resolve( $ref, 'large' );
		if ( ! $image || '' === $image['src'] ) { return ''; }
		return '<img src="' . esc_url( $image['src'] ) . '"' . ( $image['srcset'] ? ' srcset="' . esc_attr( $image['srcset'] ) . '" sizes="(max-width: 767px) 50vw, 34vw"' : '' ) . ' alt="' . esc_attr( $image['alt'] ?: $label ) . '" loading="lazy" decoding="async"' . ( $image['width'] && $image['height'] ? ' width="' . (int) $image['width'] . '" height="' . (int) $image['height'] . '"' : '' ) . '>';
	}

	private static function media( array $row, string $label ): string {
		if ( 'video' !== ( $row['mediaType'] ?? 'image' ) ) { return self::image( $row['image'] ?? null, $label ); }
		$id = (int) ( $row['localVideo']['attachmentId'] ?? 0 );
		if ( $id ) {
			if ( ! self::public_file( $id, 'video' ) || ! wp_get_attachment_url( $id ) ) { return ''; }
			// Reuse the existing local player adapter; JS owns visibility and autoplay here.
			return Fallback::local_file_video( $id, true, ! empty( $row['loop'] ), 'pc-gram__video' );
		}
		$video = YouTube_Metadata::video_id( (string) ( $row['youtubeUrl'] ?? '' ) );
		if ( ! $video ) { return ''; }
		$url = 'https://www.youtube-nocookie.com/embed/' . $video . '?enablejsapi=1&playsinline=1&rel=0&mute=1&autoplay=1' . ( ! empty( $row['loop'] ) ? '&loop=1&playlist=' . $video : '' );
		return '<div class="pc-gram__youtube"><iframe data-pc-gram-src="' . esc_url( $url ) . '" title="' . esc_attr( $label ) . '" loading="lazy" tabindex="-1" allow="autoplay; encrypted-media; fullscreen; picture-in-picture" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe><button class="pc-gram__play" type="button" data-pc-gram-play>Play video</button><a class="pc-gram__watch" href="https://www.youtube.com/watch?v=' . esc_attr( $video ) . '" target="_blank" rel="noopener noreferrer">Watch on YouTube</a></div>';
	}

	private static function product_html( $product, int $index ): string {
		$image_id = (int) $product->get_image_id();
		$image = $image_id && self::public_file( $image_id, 'image' ) ? wp_get_attachment_image( $image_id, 'woocommerce_thumbnail', false, array( 'alt' => '', 'loading' => 'lazy' ) ) : '';
		$price = function_exists( 'vc_public_price_html' ) ? vc_public_price_html( $product ) : $product->get_price_html();
		return '<li class="pc-gram__product" data-pc-gram-product="' . $index . '"><a href="' . esc_url( $product->get_permalink() ) . '"><span class="pc-gram__product-image">' . $image . '</span><span class="pc-gram__product-name">' . esc_html( $product->get_name() ) . '</span><span class="pc-gram__price">' . wp_kses_post( $price ) . '</span></a></li>';
	}

	public static function render( array $module, string $context ): string {
		$preview = in_array( $context, array( 'editor', 'preview' ), true );
		if ( ! $preview && ( 'public' !== $context || ( \PeptideCore\Store_Config::get( 'force_login' ) && ! is_user_logged_in() ) ) ) { return ''; }
		$s = $module['settings'] ?? array(); $cards = ''; $fallback = ''; $cache = array(); $invalid = 0;
		$group_id = wp_unique_id( 'pc-gram-' );
		foreach ( array_slice( $module['items'] ?? array(), 0, 24 ) as $item ) {
			if ( empty( $item['enabled'] ) ) { continue; }
			$row = $item['settings'] ?? array(); $label = trim( (string) ( $row['title'] ?? '' ) );
			if ( '' === $label ) { $label = 'Shop this media'; }
			$media = self::media( $row, $label );
			if ( '' === $media ) { $invalid++; continue; }
			$category = (int) ( $row['collection'] ?? 0 );
			if ( ! array_key_exists( $category, $cache ) ) { $cache[ $category ] = self::products( $category ); }
			$products = ''; $spots = '';
			foreach ( $cache[ $category ] as $i => $product ) {
				$products .= self::product_html( $product, $i ); $n = $i + 1;
				$x = max( 0, min( 100, (int) ( $row['hotspot' . $n . 'X'] ?? ( 1 === $n ? 30 : 70 ) ) ) );
				$y = max( 0, min( 100, (int) ( $row['hotspot' . $n . 'Y'] ?? ( 1 === $n ? 40 : 60 ) ) ) );
				$spots .= '<button type="button" class="pc-gram__spot" data-pc-gram-spot="' . $i . '" style="--pc-gram-x:' . $x . '%;--pc-gram-y:' . $y . '%" aria-label="' . esc_attr( 'Show ' . $product->get_name() ) . '" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg></button>';
			}
			$description = (string) ( $row['descriptionHtml'] ?? '' ); $tags = (string) ( $row['tagsHtml'] ?? '' );
			$details = '<div class="pc-gram__details">' . ( $products ? '<ul class="pc-gram__products" aria-label="Products in this media">' . $products . '</ul>' : '<p class="pc-gram__empty">No available products in this collection.</p>' ) . ( $description ? '<div class="pc-gram__description">' . $description . '</div>' : '' ) . ( $tags ? '<div class="pc-gram__tags">' . $tags . '</div>' : '' ) . '</div>';
			$body = '<div class="pc-gram__modal-media">' . $media . $spots . '</div>' . $details;
			$cards .= '<article class="pc-gram__card" data-module-item="' . esc_attr( (string) ( $item['id'] ?? '' ) ) . '" data-autoplay="' . ( ! empty( $row['autoplay'] ) ? '1' : '0' ) . '"><div class="pc-gram__tile" inert aria-hidden="true">' . $media . '</div><button type="button" class="pc-gram__open" data-pc-gram-open aria-label="' . esc_attr( $label ) . '" aria-haspopup="dialog" aria-controls="' . esc_attr( $group_id . '-dialog' ) . '"><span class="pc-gram__open-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 8h10l1 12H6zM9 8V6a3 3 0 0 1 6 0v2"/></svg></span></button><template data-pc-gram-content>' . $body . '</template></article>';
			$fallback .= '<article class="pc-gram__static"><div class="pc-gram__modal-media">' . $media . '</div>' . $details . '</article>';
		}
		if ( '' === $cards && ! $preview ) { return ''; }
		$base = plugins_url( 'assets/', PEPTIDE_CORE_FILE );
		wp_enqueue_style( 'pc-shop-by-gram', $base . 'shop-by-gram.css', array(), (string) filemtime( __DIR__ . '/../../assets/shop-by-gram.css' ) );
		wp_enqueue_script( 'pc-shop-by-gram', $base . 'shop-by-gram.js', array(), (string) filemtime( __DIR__ . '/../../assets/shop-by-gram.js' ), true );
		$style = '--pc-gram-cols:' . max( 3, min( 6, (int) ( $s['desktopColumns'] ?? 6 ) ) ) . ';--pc-gram-gap:' . max( 0, min( 30, (int) ( $s['gap'] ?? 12 ) ) ) . 'px;--pc-gram-icon-bg:' . ( sanitize_hex_color( $s['iconBackground'] ?? '' ) ?: '#1a1a1a' ) . ';--pc-gram-icon-color:' . ( sanitize_hex_color( $s['iconColor'] ?? '' ) ?: '#ffffff' );
		$head = ! empty( $s['title'] ) ? '<h2>' . esc_html( $s['title'] ) . '</h2>' : '';
		$head .= ! empty( $s['descriptionHtml'] ) ? '<div class="pc-gram__intro">' . $s['descriptionHtml'] . '</div>' : '';
		$notice = $preview && ( $invalid || ! $cards ) ? '<p class="pc-gram__empty">添加图片或完整 YouTube 链接 / 可公开的本地视频，并关联真实商品专辑。未配置媒体的条目不展示。</p>' : '';
		return '<section class="vc-m__inner pc-gram" data-pc-gram style="' . esc_attr( $style ) . '">' . ( $head ? '<header class="pc-gram__head">' . $head . '</header>' : '' ) . $notice . '<div class="pc-gram__gallery" tabindex="0" role="region" aria-label="Shop by image">' . $cards . '</div><noscript>' . $fallback . '</noscript><div id="' . esc_attr( $group_id . '-dialog' ) . '" class="pc-gram__overlay" data-pc-gram-overlay hidden><div class="pc-gram__dialog" role="dialog" aria-modal="true" aria-label="Shop this media" tabindex="-1"><button type="button" class="pc-gram__close" data-pc-gram-close aria-label="Close gallery"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button><div class="pc-gram__body" data-pc-gram-body></div></div></div></section>';
	}
}
