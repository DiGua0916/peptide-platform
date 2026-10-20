<?php
/**
 * 模块的默认外观（EDIT-BLOCKS-03）。主题提供 template-parts/modules/<type>.php 时不使用这里的输出。
 *
 * 目标是“能看清内容和布局、可以正常使用”，不追求最终视觉；正式样式由 Codex 在主题里实现。
 * 所有输出都经过转义；缺图片时编辑器里显示占位框，公开页面不输出空图片。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Modules;

defined( 'ABSPATH' ) || exit;

final class Fallback {

	private static string $context = 'public';

	/**
	 * @param array<string,mixed> $module
	 */
	public static function render( array $module, string $context ): string {
		self::$context = $context;
		$method        = 'render_' . $module['type'];
		if ( ! method_exists( self::class, $method ) ) {
			return 'editor' === $context ? Renderer::notice( __( 'This module has no default design yet.', 'peptide-core' ) ) : '';
		}
		return (string) self::$method( $module['settings'], self::enabled_items( $module['items'] ), $module );
	}

	/**
	 * @param array<int,array<string,mixed>> $items
	 * @return array<int,array<string,mixed>>
	 */
	private static function enabled_items( array $items ): array {
		$out = array();
		foreach ( $items as $item ) {
			if ( $item['enabled'] || 'editor' === self::$context ) {
				$out[] = $item['settings'] + array( '_id' => $item['id'], '_enabled' => $item['enabled'] );
			}
		}
		return $out;
	}

	// ---------- 工具 ----------

	private static function editor(): bool {
		return 'editor' === self::$context;
	}

	/** 员工能看到的上下文（编辑器、预览）：显示“选择图片”等占位提示；公开页面不显示。 */
	private static function staff(): bool {
		return 'public' !== self::$context;
	}

	/**
	 * @param array<string,string|int|null> $vars
	 */
	private static function style( array $vars ): string {
		$out = array();
		foreach ( $vars as $name => $value ) {
			if ( null !== $value && '' !== $value ) {
				$out[] = $name . ':' . $value;
			}
		}
		return $out ? ' style="' . esc_attr( implode( ';', $out ) ) . '"' : '';
	}

	/** 设备值：手机为 null 时沿用电脑值。 */
	private static function dv( $value, string $device ) {
		if ( ! is_array( $value ) || ! array_key_exists( 'desktop', $value ) ) {
			return $value;
		}
		return 'mobile' === $device && null !== $value['mobile'] ? $value['mobile'] : $value['desktop'];
	}

	private static function inner_class( array $s ): string {
		return ! empty( $s['fullWidth'] ) ? 'vc-m__inner vc-m__inner--full' : 'vc-m__inner';
	}

	private static function heading( array $s, string $tag = 'h2' ): string {
		$title = (string) ( $s['title'] ?? '' );
		$desc  = (string) ( $s['description'] ?? '' );
		if ( '' === $title && '' === $desc ) {
			return '';
		}
		$out = '<header class="vc-m__head">';
		if ( '' !== $title ) {
			$out .= sprintf( '<%1$s class="vc-m__title">%2$s</%1$s>', $tag, esc_html( $title ) );
		}
		if ( '' !== $desc ) {
			$out .= '<p class="vc-m__desc">' . nl2br( esc_html( $desc ) ) . '</p>';
		}
		return $out . '</header>';
	}

	private static function placeholder( string $label = '' ): string {
		return self::staff() ? '<div class="vc-ph" role="img" aria-label="' . esc_attr__( 'No image selected', 'peptide-core' ) . '"><span>' . esc_html( '' !== $label ? $label : __( 'Choose an image', 'peptide-core' ) ) . '</span></div>' : '';
	}

	/**
	 * 电脑 / 手机两张图：<picture>，手机图未选择时沿用电脑图。
	 *
	 * @param array<string,mixed> $media
	 */
	private static function picture( array $media, string $class = 'vc-img', string $sizes = '100vw' ): string {
		$desk = Assets::resolve( $media['desktop'] ?? null );
		$mob  = isset( $media['mobile'] ) ? Assets::resolve( $media['mobile'] ) : null;
		if ( ! $desk && ! $mob ) {
			return self::placeholder();
		}
		$main  = $desk ? $desk : $mob;
		$fp    = $media['desktop']['focalPoint'] ?? array( 'xPercent' => 50, 'yPercent' => 50 );
		$style = self::style(
			array(
				'object-fit'      => (string) ( $media['desktop']['fit'] ?? 'cover' ),
				'object-position' => (int) $fp['xPercent'] . '% ' . (int) $fp['yPercent'] . '%',
			)
		);
		$out = '<picture class="' . esc_attr( $class ) . '">';
		if ( $mob && $desk ) {
			$out .= sprintf( '<source media="(max-width: 767px)" srcset="%s">', esc_attr( '' !== $mob['srcset'] ? $mob['srcset'] : $mob['src'] ) );
		}
		$out .= sprintf(
			'<img src="%1$s"%2$s alt="%3$s"%4$s%5$s loading="lazy" decoding="async"%6$s>',
			esc_url( $main['src'] ),
			'' !== $main['srcset'] ? ' srcset="' . esc_attr( $main['srcset'] ) . '" sizes="' . esc_attr( $sizes ) . '"' : '',
			esc_attr( $main['alt'] ),
			$main['width'] ? ' width="' . (int) $main['width'] . '"' : '',
			$main['height'] ? ' height="' . (int) $main['height'] . '"' : '',
			$style
		);
		return $out . '</picture>';
	}

	/**
	 * @param array<string,mixed>|null $image
	 */
	private static function img( ?array $image, string $class = 'vc-img', string $label = '' ): string {
		$r = Assets::resolve( $image );
		if ( ! $r ) {
			return self::placeholder( $label );
		}
		return sprintf(
			'<img class="%1$s" src="%2$s"%3$s alt="%4$s"%5$s%6$s loading="lazy" decoding="async">',
			esc_attr( $class ),
			esc_url( $r['src'] ),
			'' !== $r['srcset'] ? ' srcset="' . esc_attr( $r['srcset'] ) . '" sizes="(max-width: 767px) 100vw, 50vw"' : '',
			esc_attr( $r['alt'] ),
			$r['width'] ? ' width="' . (int) $r['width'] . '"' : '',
			$r['height'] ? ' height="' . (int) $r['height'] . '"' : ''
		);
	}

	/**
	 * @param array<string,mixed> $link
	 * @param array<string,mixed> $s
	 */
	private static function button( array $link, array $s = array(), string $class = 'vc-btn' ): string {
		if ( '' === (string) ( $link['label'] ?? '' ) ) {
			return '';
		}
		$effect = (string) ( $s['buttonEffect'] ?? 'none' );
		if ( 'none' !== $effect ) {
			$class .= ' vc-btn--' . $effect;
		}
		$style = self::style(
			array(
				'--vc-btn-fg'       => (string) ( $s['buttonTextColor'] ?? '' ),
				'--vc-btn-bg'       => (string) ( $s['buttonBgColor'] ?? '' ),
				'--vc-btn-duration' => isset( $s['effectDurationMs'] ) && 'none' !== $effect ? (int) $s['effectDurationMs'] . 'ms' : null,
				'--vc-btn-interval' => isset( $s['effectIntervalMs'] ) && 'none' !== $effect ? (int) $s['effectIntervalMs'] . 'ms' : null,
			)
		);
		$href   = (string) ( $link['href'] ?? '' );
		$target = '_blank' === ( $link['target'] ?? '' ) ? ' target="_blank" rel="noopener"' : '';
		if ( '' === $href ) {
			return sprintf( '<span class="%1$s"%2$s>%3$s</span>', esc_attr( $class ), $style, esc_html( (string) $link['label'] ) );
		}
		return sprintf( '<a class="%1$s" href="%2$s"%3$s%4$s>%5$s</a>', esc_attr( $class ), esc_url( $href ), $target, $style, esc_html( (string) $link['label'] ) );
	}

	private static function maybe_link( string $html, string $href, string $class = 'vc-link' ): string {
		return '' !== $href ? sprintf( '<a class="%1$s" href="%2$s">%3$s</a>', esc_attr( $class ), esc_url( $href ), $html ) : $html;
	}

	private static function html( string $value ): string {
		return wp_kses_post( wpautop( $value ) );
	}

	private static function pos_class( $position ): string {
		return 'vc-pos-d-' . sanitize_html_class( (string) self::dv( $position, 'desktop' ) ) . ' vc-pos-m-' . sanitize_html_class( (string) self::dv( $position, 'mobile' ) );
	}

	private static function cols_style( array $s, string $gap_key = 'gapPx' ): string {
		return self::style(
			array(
				'--vc-cols'   => isset( $s['columns'] ) ? (int) self::dv( $s['columns'], 'desktop' ) : null,
				'--vc-cols-m' => isset( $s['columns'] ) ? (int) self::dv( $s['columns'], 'mobile' ) : null,
				'--vc-gap'    => isset( $s[ $gap_key ] ) ? (int) $s[ $gap_key ] . 'px' : null,
			)
		);
	}

	private static function item_class( array $item, string $class ): string {
		return $class . ( empty( $item['_enabled'] ) ? ' is-hidden' : '' );
	}

	/** 简单的内置线条图标（访客页面不加载 dashicons）。 */
	private static function icon( string $name, int $size = 24 ): string {
		$paths = array(
			'check'    => 'M5 12l5 5L20 7',
			'truck'    => 'M3 6h11v9H3zM14 9h4l3 3v3h-7zM7 18a2 2 0 1 0 0-.01M17 18a2 2 0 1 0 0-.01',
			'shield'   => 'M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z',
			'chat'     => 'M4 5h16v10H8l-4 4z',
			'mail'     => 'M3 6h18v12H3zM3 7l9 6 9-6',
			'phone'    => 'M6 3h4l2 5-2.5 1.5a11 11 0 0 0 5 5L16 12l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 4 5a2 2 0 0 1 2-2z',
			'location' => 'M12 21s-7-6.5-7-12a7 7 0 0 1 14 0c0 5.5-7 12-7 12zM12 11a2 2 0 1 0 0-.01',
			'star'     => 'M12 3l2.8 5.7 6.2.9-4.5 4.4 1 6.2L12 17.3 6.5 20.2l1-6.2L3 9.6l6.2-.9z',
			'clock'    => 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM12 7v5l3 3',
			'flask'    => 'M9 3h6M10 3v6l-6 10a1.5 1.5 0 0 0 1.3 2h13.4a1.5 1.5 0 0 0 1.3-2L14 9V3',
			'box'      => 'M3 7l9-4 9 4v10l-9 4-9-4zM3 7l9 4 9-4M12 11v10',
			'globe'    => 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM3 12h18M12 3c3 3 3 15 0 18M12 3c-3 3-3 15 0 18',
			'lock'     => 'M6 11h12v10H6zM8 11V7a4 4 0 0 1 8 0v4',
			'document' => 'M6 3h8l4 4v14H6zM14 3v4h4M9 12h6M9 16h6',
		);
		if ( ! isset( $paths[ $name ] ) ) {
			return '';
		}
		return sprintf(
			'<svg class="vc-icon" width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="%2$s"/></svg>',
			$size,
			esc_attr( $paths[ $name ] )
		);
	}

	private static function icon_or_image( array $item, int $size ): string {
		if ( ! empty( $item['image'] ) && Assets::resolve( $item['image'] ) ) {
			return self::img( $item['image'], 'vc-m__icon-img' );
		}
		return self::icon( (string) ( $item['icon'] ?? '' ), $size );
	}

	// ---------- 各模块 ----------

	private static function render_carousel( array $s, array $items ): string {
		$height = 'fixed' === $s['height'] ? self::style(
			array(
				'--vc-h'   => (int) self::dv( $s['heightPx'], 'desktop' ) . 'px',
				'--vc-h-m' => (int) self::dv( $s['heightPx'], 'mobile' ) . 'px',
			)
		) : '';
		$out = sprintf(
			'<div class="%1$s"><div class="vc-carousel vc-carousel--%2$s" data-autoplay="%3$s" data-interval="%4$d" data-dots="%5$s" data-arrows="%6$s"%7$s>',
			esc_attr( self::inner_class( $s ) ),
			esc_attr( $s['height'] ),
			$s['autoplay'] ? '1' : '0',
			(int) $s['intervalMs'],
			$s['pagination'] ? '1' : '0',
			$s['arrows'] ? '1' : '0',
			$height
		);
		foreach ( $items as $i => $it ) {
			$overlay = self::style(
				array(
					'--vc-overlay'   => '' !== $it['overlayColor'] ? $it['overlayColor'] : null,
					'--vc-overlay-o' => (string) ( (int) $it['overlayOpacity'] / 100 ),
					'--vc-fg'        => $it['textColor'],
					'--vc-title'     => (int) $s['titleSizePx'] . 'px',
				)
			);
			$out .= sprintf( '<div class="%1$s"%2$s>', esc_attr( self::item_class( $it, 'vc-slide' . ( 0 === $i ? ' is-active' : '' ) ) ), $overlay );
			$out .= self::picture( $it['media'], 'vc-slide__media' );
			$out .= '<div class="vc-slide__overlay"></div><div class="vc-slide__text ' . esc_attr( self::pos_class( $it['position'] ) ) . '">';
			if ( '' !== $it['eyebrow'] ) {
				$out .= '<p class="vc-eyebrow">' . esc_html( $it['eyebrow'] ) . '</p>';
			}
			if ( '' !== $it['title'] ) {
				$out .= '<h2 class="vc-slide__title">' . esc_html( $it['title'] ) . '</h2>';
			}
			if ( '' !== $it['description'] ) {
				$out .= '<p class="vc-slide__desc">' . nl2br( esc_html( $it['description'] ) ) . '</p>';
			}
			$out .= self::button( $it['button'], $it );
			$out .= '</div></div>';
		}
		if ( $s['pagination'] && count( $items ) > 1 ) {
			$out .= '<div class="vc-carousel__dots" aria-hidden="true">' . str_repeat( '<span></span>', count( $items ) ) . '</div>';
		}
		return $out . '</div></div>';
	}

	private static function render_image( array $s ): string {
		$vars = self::style(
			array(
				'--vc-overlay'   => '' !== $s['overlayColor'] ? $s['overlayColor'] : null,
				'--vc-overlay-o' => (string) ( (int) $s['overlayOpacity'] / 100 ),
				'--vc-fg'        => $s['textColor'],
				'--vc-title-c'   => $s['titleColor'],
				'--vc-h'         => 'auto' !== $s['height'] ? (int) $s['height'] . 'px' : null,
			)
		);
		$text = '';
		if ( '' !== $s['title'] || '' !== $s['description'] || '' !== $s['button']['label'] ) {
			$text = '<div class="vc-banner__text ' . esc_attr( self::pos_class( $s['position'] ) ) . '">' . self::heading( $s ) . self::button( $s['button'], $s ) . '</div>';
		}
		$media = self::maybe_link( self::picture( $s['media'], 'vc-banner__media' ), $s['href'], 'vc-banner__link' );
		return sprintf( '<div class="%1$s"><div class="vc-banner"%2$s>%3$s<div class="vc-banner__overlay"></div>%4$s</div></div>', esc_attr( self::inner_class( $s ) ), $vars, $media, $text );
	}

	private static function render_image_row( array $s, array $items ): string {
		$out = '<div class="' . esc_attr( self::inner_class( $s ) ) . '">' . self::heading( $s );
		$out .= '<div class="vc-row vc-row--' . esc_attr( $s['mobileStyle'] ) . '"' . self::style( array( '--vc-gap' => (int) $s['gapPx'] . 'px' ) ) . '>';
		foreach ( $items as $it ) {
			$out .= '<div class="' . esc_attr( self::item_class( $it, 'vc-row__item' ) ) . '">' . self::maybe_link( self::picture( $it['media'] ), $it['href'] ) . '</div>';
		}
		return $out . '</div></div>';
	}

	private static function render_image_grid( array $s, array $items ): string {
		$out = '<div class="' . esc_attr( self::inner_class( $s ) ) . '">' . self::heading( $s ) . '<div class="vc-grid"' . self::cols_style( $s ) . '>';
		foreach ( $items as $it ) {
			$out .= '<div class="' . esc_attr( self::item_class( $it, 'vc-grid__item' ) ) . '">' . self::maybe_link( self::picture( $it['media'], 'vc-img', '(max-width: 767px) 50vw, 25vw' ), $it['href'] ) . '</div>';
		}
		return $out . '</div></div>';
	}

	private static function mosaic( array $s, array $items, int $count ): string {
		$out = '<div class="vc-m__inner">' . self::heading( $s );
		$out .= sprintf( '<div class="vc-mosaic vc-mosaic--%1$d vc-mosaic--%2$s"%3$s>', $count, esc_attr( $s['layout'] ), self::style( array( '--vc-gap' => (int) $s['gapPx'] . 'px' ) ) );
		foreach ( $items as $i => $it ) {
			$tile = self::picture( $it['media'], 'vc-mosaic__media' );
			if ( '' !== $it['buttonLabel'] ) {
				$tile .= '<span class="vc-btn vc-mosaic__btn">' . esc_html( $it['buttonLabel'] ) . '</span>';
			}
			$out .= '<div class="' . esc_attr( self::item_class( $it, 'vc-mosaic__tile vc-mosaic__tile--' . ( $i + 1 ) ) ) . '">' . self::maybe_link( $tile, $it['href'], 'vc-mosaic__link' ) . '</div>';
		}
		return $out . '</div></div>';
	}

	private static function render_mosaic_3( array $s, array $items ): string {
		return self::mosaic( $s, $items, 3 );
	}

	private static function render_mosaic_4( array $s, array $items ): string {
		return self::mosaic( $s, $items, 4 );
	}

	private static function render_mosaic_5( array $s, array $items ): string {
		return self::mosaic( $s, $items, 5 );
	}

	/**
	 * 一行图文（图文、图文行共用）。
	 */
	private static function media_text_row( array $media, $position, string $text_html, string $class = 'vc-mt' ): string {
		return sprintf(
			'<div class="%1$s vc-mt--d-%2$s vc-mt--m-%3$s"><div class="vc-mt__media">%4$s</div><div class="vc-mt__text">%5$s</div></div>',
			esc_attr( $class ),
			esc_attr( (string) self::dv( $position, 'desktop' ) ),
			esc_attr( (string) self::dv( $position, 'mobile' ) ),
			self::picture( $media, 'vc-img', '(max-width: 767px) 100vw, 50vw' ),
			$text_html
		);
	}

	private static function render_media_text( array $s ): string {
		$text = '';
		if ( '' !== $s['title'] ) {
			$text .= '<h2 class="vc-m__title">' . esc_html( $s['title'] ) . '</h2>';
		}
		if ( '' !== $s['bodyHtml'] ) {
			$text .= '<div class="vc-richtext">' . self::html( $s['bodyHtml'] ) . '</div>';
		}
		$text .= self::button( $s['button'], $s );
		$vars  = self::style(
			array(
				'--vc-title-c' => $s['titleColor'],
				'--vc-fg'      => $s['textColor'],
				'--vc-text-bg' => $s['contentBgColor'],
				'text-align'   => $s['textAlign'],
			)
		);
		return '<div class="' . esc_attr( self::inner_class( $s ) ) . '"' . $vars . '>' . self::media_text_row( $s['media'], $s['imagePosition'], $text ) . '</div>';
	}

	private static function render_media_text_list( array $s, array $items ): string {
		$width = array(
			'content' => 'vc-m__inner',
			'wide'    => 'vc-m__inner vc-m__inner--wide',
			'full'    => 'vc-m__inner vc-m__inner--full',
		)[ $s['container'] ] ?? 'vc-m__inner';
		$head = '';
		if ( '' !== $s['eyebrow'] || '' !== $s['title'] || '' !== $s['descriptionHtml'] ) {
			$head = '<header class="vc-m__head">';
			if ( '' !== $s['eyebrow'] ) {
				$head .= '<p class="vc-eyebrow">' . esc_html( $s['eyebrow'] ) . '</p>';
			}
			if ( '' !== $s['title'] ) {
				$head .= '<h2 class="vc-m__title">' . esc_html( $s['title'] ) . ( '' !== $s['titleAccent'] ? '<br><em>' . esc_html( $s['titleAccent'] ) . '</em>' : '' ) . '</h2>';
			}
			if ( '' !== $s['descriptionHtml'] ) {
				$head .= '<div class="vc-m__desc">' . self::html( $s['descriptionHtml'] ) . '</div>';
			}
			$head .= '</header>';
		}
		$rows = '';
		foreach ( $items as $it ) {
			$text = '';
			if ( '' !== $it['eyebrow'] ) {
				$text .= '<p class="vc-eyebrow">' . esc_html( $it['eyebrow'] ) . '</p>';
			}
			if ( '' !== $it['title'] ) {
				$text .= '<h3 class="vc-mt__title">' . esc_html( $it['title'] ) . '</h3>';
			}
			if ( '' !== $it['label'] ) {
				$text .= '<p class="vc-mt__label">' . esc_html( $it['label'] ) . '</p>';
			}
			if ( '' !== $it['bodyHtml'] ) {
				$text .= '<div class="vc-richtext">' . self::html( $it['bodyHtml'] ) . '</div>';
			}
			$text .= self::button( $it['button'] );
			$rows .= self::media_text_row( $it['media'], $it['imagePosition'], $text, self::item_class( $it, 'vc-mt' ) );
		}
		$vars = self::style(
			array(
				'--vc-gap'   => (int) self::dv( $s['itemGapPx'], 'desktop' ) . 'px',
				'--vc-gap-m' => (int) self::dv( $s['itemGapPx'], 'mobile' ) . 'px',
			)
		);
		return '<div class="' . esc_attr( $width ) . '">' . $head . '<div class="vc-mt-list' . ( $s['animation'] ? ' vc-reveal' : '' ) . '"' . $vars . '>' . $rows . '</div></div>';
	}

	private static function render_card_list( array $s, array $items ): string {
		$out = '<div class="' . esc_attr( self::inner_class( $s ) ) . '"' . self::style( array( 'text-align' => $s['textAlign'] ) ) . '>' . self::heading( $s );
		$out .= '<div class="vc-grid vc-grid--' . esc_attr( $s['display'] ) . '"' . self::cols_style( $s ) . '>';
		foreach ( $items as $it ) {
			$card = self::picture( $it['media'], 'vc-card__media', '(max-width: 767px) 100vw, 33vw' ) . '<div class="vc-card__body">';
			if ( '' !== $it['title'] ) {
				$card .= '<h3 class="vc-card__title">' . esc_html( $it['title'] ) . '</h3>';
			}
			if ( '' !== $it['text'] ) {
				$card .= '<p class="vc-card__text">' . nl2br( esc_html( $it['text'] ) ) . '</p>';
			}
			$card .= '</div>';
			$out  .= '<div class="' . esc_attr( self::item_class( $it, 'vc-card' ) ) . '">' . self::maybe_link( $card, $it['href'], 'vc-card__link' ) . '</div>';
		}
		return $out . '</div></div>';
	}

	/**
	 * 视频本体：上传的视频或 YouTube（youtube-nocookie 嵌入）。
	 */
	private static function video_player( array $s, string $class = 'vc-video' ): string {
		$poster = Assets::resolve( $s['poster'] );
		if ( 'youtube' === $s['source'] ) {
			$id = self::youtube_id( (string) $s['youtubeUrl'] );
			if ( ! $id ) {
				return self::placeholder( __( 'Add a YouTube link', 'peptide-core' ) );
			}
			$params = array_filter(
				array(
					'autoplay' => $s['autoplay'] ? '1' : null,
					'mute'     => ( $s['muted'] || $s['autoplay'] ) ? '1' : null,
					'loop'     => $s['loop'] ? '1' : null,
					'playlist' => $s['loop'] ? $id : null,
					'controls' => $s['controls'] ? null : '0',
					'rel'      => '0',
				)
			);
			return sprintf(
				'<div class="%1$s"><iframe src="%2$s" title="%3$s" loading="lazy" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe></div>',
				esc_attr( $class ),
				esc_url( add_query_arg( $params, 'https://www.youtube-nocookie.com/embed/' . rawurlencode( $id ) ) ),
				esc_attr( (string) ( $s['title'] ?? '' ) ?: __( 'Video', 'peptide-core' ) )
			);
		}
		$url = ! empty( $s['video']['attachmentId'] ) ? wp_get_attachment_url( (int) $s['video']['attachmentId'] ) : '';
		if ( ! $url ) {
			return self::placeholder( __( 'Choose a video', 'peptide-core' ) );
		}
		// 浏览器只允许静音视频自动播放，所以开启自动播放时总是静音。
		return sprintf(
			'<div class="%1$s"><video src="%2$s"%3$s%4$s%5$s%6$s%7$s playsinline preload="metadata"></video></div>',
			esc_attr( $class ),
			esc_url( $url ),
			$poster ? ' poster="' . esc_url( $poster['src'] ) . '"' : '',
			$s['autoplay'] ? ' autoplay' : '',
			( $s['muted'] || $s['autoplay'] ) ? ' muted' : '',
			$s['loop'] ? ' loop' : '',
			$s['controls'] ? ' controls' : ''
		);
	}

	public static function youtube_id( string $url ): ?string {
		if ( 1 === preg_match( '~(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $url, $m ) ) {
			return $m[1];
		}
		return null;
	}

	private static function render_video( array $s ): string {
		$ratio = str_replace( ':', ' / ', $s['ratio'] );
		return sprintf(
			'<div class="%1$s"><div class="vc-video-block vc-video-block--%2$s"%3$s>%4$s%5$s</div></div>',
			esc_attr( self::inner_class( $s ) ),
			esc_attr( $s['layout'] ),
			self::style( array( '--vc-ratio' => $ratio ) ),
			self::heading( $s ),
			self::video_player( $s )
		);
	}

	private static function render_video_text( array $s ): string {
		$vars = self::style(
			array(
				'--vc-h'         => (int) self::dv( $s['heightPx'], 'desktop' ) . 'px',
				'--vc-h-m'       => (int) self::dv( $s['heightPx'], 'mobile' ) . 'px',
				'--vc-overlay-o' => (string) ( (int) $s['overlayOpacity'] / 100 ),
				'--vc-fg'        => $s['textColor'],
				'--vc-title'     => (int) $s['titleSizePx'] . 'px',
			)
		);
		$text = '<div class="vc-banner__text ' . esc_attr( self::pos_class( $s['position'] ) ) . '">' . self::heading( $s ) . self::button( $s['button'] ) . '</div>';
		return '<div class="vc-banner vc-banner--video"' . $vars . '>' . self::video_player( $s, 'vc-banner__media vc-video--cover' ) . '<div class="vc-banner__overlay"></div>' . $text . '</div>';
	}

	private static function render_slogan( array $s, array $items ): string {
		$out = '<div class="vc-m__inner">' . self::heading( $s );
		$out .= '<div class="vc-slogan' . ( $s['mobileCarousel'] ? ' vc-swipe-m' : '' ) . '"' . self::style(
			array(
				'--vc-icon' => (int) $s['iconWidthPx'] . 'px',
				'--vc-t'    => (int) $s['titleSizePx'] . 'px',
			)
		) . '>';
		foreach ( $items as $it ) {
			$out .= '<div class="' . esc_attr( self::item_class( $it, 'vc-slogan__item' ) ) . '">' . self::img( $it['icon'], 'vc-slogan__icon', __( 'Icon', 'peptide-core' ) );
			$out .= '' !== $it['title'] ? '<h3 class="vc-slogan__title">' . esc_html( $it['title'] ) . '</h3>' : '';
			$out .= '' !== $it['text'] ? '<p class="vc-slogan__text">' . esc_html( $it['text'] ) . '</p>' : '';
			$out .= '</div>';
		}
		return $out . '</div></div>';
	}

	private static function render_counter( array $s, array $items ): string {
		$out = '<div class="vc-m__inner">' . self::heading( $s ) . '<div class="vc-grid vc-counter"' . self::cols_style( $s ) . self::style( array( '--vc-num' => (int) $s['numberSizePx'] . 'px' ) ) . '>';
		foreach ( $items as $it ) {
			$out .= '<div class="' . esc_attr( self::item_class( $it, 'vc-counter__item' ) ) . '">' . self::icon_or_image( $it, 32 );
			$out .= sprintf( '<p class="vc-counter__num"><span data-count="%1$d">%2$s</span>%3$s</p>', (int) $it['number'], esc_html( number_format_i18n( (int) $it['number'] ) ), '' !== $it['unit'] ? '<small>' . esc_html( $it['unit'] ) . '</small>' : '' );
			$out .= '' !== $it['label'] ? '<p class="vc-counter__label">' . esc_html( $it['label'] ) . '</p>' : '';
			$out .= '</div>';
		}
		return $out . '</div></div>';
	}

	private static function render_gallery( array $s, array $items ): string {
		$out = '<div class="' . esc_attr( self::inner_class( $s ) ) . '">' . self::heading( $s ) . '<div class="vc-gallery"' . self::cols_style( $s ) . '>';
		foreach ( $items as $it ) {
			$fig  = self::img( $it['image'], 'vc-gallery__img' );
			$fig .= '' !== $it['caption'] ? '<figcaption>' . esc_html( $it['caption'] ) . '</figcaption>' : '';
			$out .= '<figure class="' . esc_attr( self::item_class( $it, 'vc-gallery__item' ) ) . '">' . self::maybe_link( $fig, $it['href'] ) . '</figure>';
		}
		$out .= '</div>';
		$btn  = self::button( $s['button'], $s );
		return $out . ( '' !== $btn ? '<p class="vc-m__actions">' . $btn . '</p>' : '' ) . '</div>';
	}

	private static function render_custom_html( array $s, array $items, array $module ): string {
		$html = (string) $s['html'];
		if ( '' === trim( $html ) ) {
			return self::staff() ? Renderer::notice( __( 'Paste your HTML in the sidebar.', 'peptide-core' ) ) : '';
		}
		if ( self::editor() ) {
			// 编辑器里不执行脚本，只显示安全版本。
			$html = wp_kses_post( $html );
		} elseif ( ! self::trusted_author() ) {
			$html = wp_kses_post( $html );
		}
		$title = '' !== $s['title'] ? '<h2 class="vc-m__title">' . esc_html( $s['title'] ) . '</h2>' : '';
		return '<div class="' . esc_attr( self::inner_class( $s ) ) . '">' . $title . '<div class="vc-custom">' . $html . '</div></div>';
	}

	/**
	 * 当前文档最后的编辑者是否可以使用未过滤的 HTML（管理员）。
	 */
	private static function trusted_author(): bool {
		$doc  = Renderer::$document_id;
		$user = $doc ? (int) get_post_meta( $doc, '_edit_last', true ) : 0;
		return $user && user_can( $user, 'unfiltered_html' );
	}

	private static function render_timeline( array $s, array $items ): string {
		$out = '<div class="vc-m__inner"' . self::style(
			array(
				'--vc-title-c' => $s['titleColor'],
				'--vc-fg'      => $s['textColor'],
			)
		) . '>' . self::heading( $s );
		$out .= '<ol class="vc-timeline vc-timeline--' . esc_attr( $s['style'] ) . '">';
		foreach ( $items as $it ) {
			$out .= '<li class="' . esc_attr( self::item_class( $it, 'vc-timeline__item vc-timeline__item--' . $it['imagePosition'] ) ) . '"' . self::style( array( '--vc-accent' => $it['color'] ) ) . '>';
			if ( 'vertical' === $s['style'] ) {
				$out .= self::img( $it['image'], 'vc-timeline__img' );
			}
			$out .= '<div class="vc-timeline__body"><p class="vc-timeline__date">' . esc_html( $it['date'] ) . '</p>';
			$out .= '' !== $it['title'] ? '<h3 class="vc-timeline__title">' . esc_html( $it['title'] ) . '</h3>' : '';
			$out .= '' !== $it['text'] ? '<p>' . nl2br( esc_html( $it['text'] ) ) . '</p>' : '';
			$out .= '</div></li>';
		}
		$out .= '</ol>';
		if ( 'vertical' === $s['style'] && $s['collapsible'] && count( $items ) > 3 ) {
			$out .= sprintf( '<button type="button" class="vc-btn vc-btn--ghost vc-timeline__toggle" data-expand="%1$s" data-collapse="%2$s">%1$s</button>', esc_attr( $s['expandLabel'] ), esc_attr( $s['collapseLabel'] ) );
		}
		return $out . '</div>';
	}

	private static function render_contact( array $s, array $items ): string {
		$vars = self::style(
			array(
				'--vc-overlay-o' => (string) ( (int) $s['overlayOpacity'] / 100 ),
				'--vc-icon'      => (int) $s['iconSizePx'] . 'px',
				'--vc-bg'        => $s['bgColor'],
				'--vc-accent'    => $s['accentColor'],
			)
		);
		$bg  = Assets::resolve( $s['media']['desktop'] ) ? self::picture( $s['media'], 'vc-contact__bg' ) . '<div class="vc-banner__overlay"></div>' : '';
		$out = '<div class="' . esc_attr( self::inner_class( $s ) ) . '"><div class="vc-contact"' . $vars . '>' . $bg . '<div class="vc-contact__body">' . self::heading( $s ) . '<ul class="vc-contact__list">';
		foreach ( $items as $it ) {
			$row  = '<span class="vc-contact__icon">' . self::icon_or_image( $it, (int) $s['iconSizePx'] ) . '</span><span><strong>' . esc_html( $it['title'] ) . '</strong><br>' . esc_html( $it['text'] ) . '</span>';
			$out .= '<li class="' . esc_attr( self::item_class( $it, 'vc-contact__item' ) ) . '">' . self::maybe_link( $row, $it['href'] ) . '</li>';
		}
		return $out . '</ul></div></div></div>';
	}

	private static function render_promo_grid( array $s, array $items ): string {
		$left = '<div class="vc-promo__left">' . ( '' !== $s['leftTitle'] ? '<h3 class="vc-promo__title">' . esc_html( $s['leftTitle'] ) . '</h3>' : '' );
		$left .= sprintf( '<div class="vc-carousel vc-carousel--auto" data-autoplay="%1$s" data-interval="%2$d" data-dots="%3$s">', $s['autoplay'] ? '1' : '0', (int) $s['intervalMs'], $s['dots'] ? '1' : '0' );
		foreach ( $items as $i => $it ) {
			$left .= '<div class="' . esc_attr( self::item_class( $it, 'vc-slide' . ( 0 === $i ? ' is-active' : '' ) ) ) . '">' . self::maybe_link( self::img( $it['image'], 'vc-slide__media' ), $it['href'] ) . '</div>';
		}
		$left .= '</div></div>';
		$right = '<div class="vc-promo__right">' . ( '' !== $s['rightTitle'] ? '<h3 class="vc-promo__title">' . esc_html( $s['rightTitle'] ) . '</h3>' : '' );
		foreach ( array( $s['category1'], $s['category2'] ) as $term_id ) {
			$term = $term_id ? get_term( (int) $term_id, 'product_cat' ) : null;
			if ( $term instanceof \WP_Term ) {
				$thumb  = (int) get_term_meta( $term->term_id, 'thumbnail_id', true );
				$img    = $thumb ? self::img( array( 'attachmentId' => $thumb, 'alt' => $term->name ), 'vc-promo__img' ) : '';
				$right .= sprintf( '<a class="vc-promo__cat" href="%1$s">%2$s<span>%3$s</span></a>', esc_url( (string) get_term_link( $term ) ), $img, esc_html( $term->name ) );
			} elseif ( self::staff() ) {
				$right .= self::placeholder( __( 'Choose a category', 'peptide-core' ) );
			}
		}
		$right .= '</div>';
		return '<div class="vc-m__inner"><div class="vc-promo">' . $left . $right . '</div></div>';
	}

	private static function render_marquee( array $s, array $items ): string {
		$texts = array_filter( array_map( static fn( $it ) => (string) $it['text'], $items ), 'strlen' );
		if ( ! $texts ) {
			return self::staff() ? Renderer::notice( __( 'Add some text.', 'peptide-core' ) ) : '';
		}
		$track = '';
		foreach ( $texts as $t ) {
			$track .= '<span class="vc-marquee__item">' . esc_html( $t ) . '</span>';
		}
		$vars = self::style(
			array(
				'--vc-h'        => (int) $s['heightPx'] . 'px',
				'--vc-fs'       => (int) $s['fontSizePx'] . 'px',
				'--vc-duration' => (int) $s['durationS'] . 's',
				'--vc-bg'       => $s['bgColor'],
				'--vc-fg'       => $s['textColor'],
			)
		);
		// 内容重复一次用于无缝滚动；第二份对读屏软件隐藏。
		return sprintf(
			'<div class="vc-marquee vc-marquee--%1$s%2$s"%3$s><div class="vc-marquee__track"><div class="vc-marquee__group">%4$s</div><div class="vc-marquee__group" aria-hidden="true">%4$s</div></div></div>',
			esc_attr( $s['direction'] ),
			$s['pauseOnHover'] ? ' vc-marquee--pause' : '',
			$vars,
			$track
		);
	}

	private static function render_spacer( array $s ): string {
		return '<div class="vc-spacer"' . self::style(
			array(
				'--vc-h'   => (int) self::dv( $s['heightPx'], 'desktop' ) . 'px',
				'--vc-h-m' => (int) self::dv( $s['heightPx'], 'mobile' ) . 'px',
				'--vc-bg'  => $s['color'],
			)
		) . ( self::staff() ? ' data-label="' . esc_attr__( 'Spacer', 'peptide-core' ) . '"' : '' ) . '></div>';
	}

	private static function render_rich_text( array $s ): string {
		$vars = self::style(
			array(
				'--vc-w'       => (int) $s['contentWidthPx'] . 'px',
				'--vc-pad'     => (int) self::dv( $s['innerPaddingPx'], 'desktop' ) . 'px',
				'--vc-pad-m'   => (int) self::dv( $s['innerPaddingPx'], 'mobile' ) . 'px',
				'--vc-radius'  => (int) $s['radiusPx'] . 'px',
				'--vc-text-bg' => $s['contentBgColor'],
				'--vc-fg'      => $s['textColor'],
				'text-align'   => $s['textAlign'],
			)
		);
		$body = '' !== $s['title'] ? '<h2 class="vc-m__title">' . esc_html( $s['title'] ) . '</h2>' : '';
		$body .= '' !== $s['bodyHtml'] ? '<div class="vc-richtext">' . self::html( $s['bodyHtml'] ) . '</div>' : ( self::staff() ? Renderer::notice( __( 'Write your text in the sidebar.', 'peptide-core' ) ) : '' );
		$btn  = self::button( $s['button'], $s );
		return '<div class="vc-m__inner"><div class="vc-rich"' . $vars . '>' . $body . ( '' !== $btn ? '<p class="vc-m__actions">' . $btn . '</p>' : '' ) . '</div></div>';
	}

	private static function render_compare( array $s ): string {
		$before = self::img( $s['before'], 'vc-compare__img', __( 'Before image', 'peptide-core' ) );
		$after  = self::img( $s['after'], 'vc-compare__img', __( 'After image', 'peptide-core' ) );
		$label  = static fn( string $text, string $color, string $side ): string => '' !== $text ? '<span class="vc-compare__label vc-compare__label--' . $side . '"' . ( '' !== $color ? ' style="color:' . esc_attr( $color ) . '"' : '' ) . '>' . esc_html( $text ) . '</span>' : '';
		return sprintf(
			'<div class="vc-m__inner">%1$s<div class="vc-compare" style="--vc-pos:%2$d%%"><div class="vc-compare__after">%3$s%4$s</div><div class="vc-compare__before">%5$s%6$s</div><input class="vc-compare__range" type="range" min="0" max="100" value="%2$d" aria-label="%7$s"></div></div>',
			self::heading( $s ),
			(int) $s['startPercent'],
			$after,
			$label( $s['afterLabel'], $s['afterTextColor'], 'after' ),
			$before,
			$label( $s['beforeLabel'], $s['beforeTextColor'], 'before' ),
			esc_attr__( 'Drag to compare', 'peptide-core' )
		);
	}

	private static function render_hotspots( array $s, array $items ): string {
		$out = '<div class="vc-m__inner">' . self::heading( $s ) . '<div class="vc-hotspots"' . self::style(
			array(
				'--vc-overlay' => '' !== $s['overlayColor'] ? $s['overlayColor'] : null,
				'--vc-icon'    => (int) $s['iconWidthPx'] . 'px',
			)
		) . '>' . self::picture( $s['media'], 'vc-hotspots__media' );
		foreach ( $items as $i => $it ) {
			$vars = self::style(
				array(
					'--x'   => (int) self::dv( $it['x'], 'desktop' ) . '%',
					'--y'   => (int) self::dv( $it['y'], 'desktop' ) . '%',
					'--x-m' => (int) self::dv( $it['x'], 'mobile' ) . '%',
					'--y-m' => (int) self::dv( $it['y'], 'mobile' ) . '%',
				)
			);
			$icon = Assets::resolve( $it['icon'] ) ? self::img( $it['icon'], 'vc-hotspot__icon' ) : '<span class="vc-hotspot__dot"></span>';
			$out .= sprintf(
				'<details class="%1$s"%2$s><summary aria-label="%3$s">%4$s</summary><div class="vc-hotspot__pop vc-hotspot__pop--%5$s"><strong>%6$s</strong><p>%7$s</p></div></details>',
				esc_attr( self::item_class( $it, 'vc-hotspot' ) ),
				$vars,
				esc_attr( '' !== $it['title'] ? $it['title'] : sprintf( /* translators: %d: hotspot number */ __( 'Hotspot %d', 'peptide-core' ), $i + 1 ) ),
				$icon,
				esc_attr( $it['popup'] ),
				esc_html( $it['title'] ),
				nl2br( esc_html( $it['text'] ) )
			);
		}
		return $out . '</div></div>';
	}

	private static function render_faq( array $s, array $items ): string {
		$out = '<div class="vc-m__inner">' . self::heading( $s ) . '<div class="vc-faq"' . self::style(
			array(
				'--vc-answer-bg' => $s['answerBgColor'],
				'--vc-answer-fg' => $s['answerTextColor'],
			)
		) . '>';
		foreach ( $items as $i => $it ) {
			$out .= sprintf(
				'<details class="%1$s"%2$s><summary>%3$s</summary><div class="vc-faq__answer">%4$s</div></details>',
				esc_attr( self::item_class( $it, 'vc-faq__item' ) ),
				( 0 === $i && $s['openFirst'] ) ? ' open' : '',
				esc_html( '' !== $it['question'] ? $it['question'] : __( 'Question', 'peptide-core' ) ),
				self::html( $it['answerHtml'] )
			);
		}
		return $out . '</div></div>';
	}

	private static function render_product_showcase( array $s, array $items ): string {
		$out = sprintf( '<div class="vc-m__inner"><div class="vc-carousel vc-carousel--auto vc-showcase" data-autoplay="%1$s" data-interval="%2$d" data-dots="1">', $s['autoplay'] ? '1' : '0', (int) $s['intervalMs'] );
		foreach ( $items as $i => $it ) {
			$out .= '<div class="' . esc_attr( self::item_class( $it, 'vc-slide vc-showcase__scene' . ( 0 === $i ? ' is-active' : '' ) ) ) . '"' . self::style(
				array(
					'--vc-overlay'   => '' !== $it['overlayColor'] ? $it['overlayColor'] : null,
					'--vc-overlay-o' => (string) ( (int) $it['overlayOpacity'] / 100 ),
				)
			) . '>' . self::picture( $it['media'], 'vc-slide__media' ) . '<div class="vc-slide__overlay"></div>';
			foreach ( $it['spots'] as $spot ) {
				$product = $spot['settings']['product'] ? wc_get_product( (int) $spot['settings']['product'] ) : null;
				if ( ! $product || ( 'publish' !== $product->get_status() && ! self::staff() ) ) {
					continue;
				}
				$x    = $spot['settings']['x'];
				$y    = $spot['settings']['y'];
				$vars = self::style(
					array(
						'--x'   => (int) self::dv( $x, 'desktop' ) . '%',
						'--y'   => (int) self::dv( $y, 'desktop' ) . '%',
						'--x-m' => (int) self::dv( $x, 'mobile' ) . '%',
						'--y-m' => (int) self::dv( $y, 'mobile' ) . '%',
					)
				);
				// 价格来自 WooCommerce，不在装修数据里保存。
				$out .= sprintf(
					'<details class="vc-hotspot"%1$s%2$s><summary aria-label="%3$s"><span class="vc-hotspot__dot"></span></summary><div class="vc-hotspot__pop"><strong>%3$s</strong><p class="vc-price">%4$s</p><a class="vc-btn" href="%5$s">%6$s</a></div></details>',
					$vars,
					$spot['settings']['open'] ? ' open' : '',
					esc_html( $product->get_name() ),
					wp_kses_post( $product->get_price_html() ),
					esc_url( $product->get_permalink() ),
					esc_html( $spot['settings']['buttonLabel'] )
				);
			}
			$out .= '</div>';
		}
		return $out . '</div></div>';
	}

	private static function render_hero_h1( array $s ): string {
		$text = '<div class="vc-mt__text">';
		$text .= '' !== $s['title'] ? '<h1 class="vc-hero-h1__title">' . esc_html( $s['title'] ) . '</h1>' : ( self::staff() ? Renderer::notice( __( 'Add the headline.', 'peptide-core' ) ) : '' );
		$text .= '' !== $s['description'] ? '<p class="vc-m__desc">' . nl2br( esc_html( $s['description'] ) ) . '</p>' : '';
		$text .= self::button( $s['button'], $s ) . '</div>';
		$vars  = self::style(
			array(
				'--vc-title-c' => $s['titleColor'],
				'--vc-fg'      => $s['textColor'],
				'--vc-text-bg' => $s['contentBgColor'],
				'text-align'   => $s['textAlign'],
			)
		);
		if ( ! $s['showMedia'] ) {
			return '<div class="' . esc_attr( self::inner_class( $s ) ) . '"' . $vars . '><div class="vc-hero-h1">' . $text . '</div></div>';
		}
		return sprintf(
			'<div class="%1$s"%2$s><div class="vc-hero-h1 vc-mt vc-mt--d-%3$s vc-mt--m-%4$s"><div class="vc-mt__media">%5$s</div>%6$s</div></div>',
			esc_attr( self::inner_class( $s ) ),
			$vars,
			esc_attr( (string) self::dv( $s['mediaPosition'], 'desktop' ) ),
			esc_attr( (string) self::dv( $s['mediaPosition'], 'mobile' ) ),
			self::picture( $s['media'], 'vc-img', '(max-width: 767px) 100vw, 50vw' ),
			$text
		);
	}

	private static function render_info_list( array $s, array $items ): string {
		$out = '<div class="vc-m__inner"><ul class="vc-info vc-info--' . esc_attr( $s['align'] ) . ( $s['mobileSwipe'] ? ' vc-swipe-m' : '' ) . '">';
		foreach ( $items as $it ) {
			$out .= '<li class="' . esc_attr( self::item_class( $it, 'vc-info__item' ) ) . '">' . self::icon_or_image( $it, 22 ) . '<span>' . esc_html( $it['text'] ) . '</span></li>';
		}
		return $out . '</ul></div>';
	}

	private static function render_nav_bar( array $s ): string {
		if ( ! $s['menu'] ) {
			return self::staff() ? Renderer::notice( __( 'Choose a menu in the sidebar.', 'peptide-core' ) ) : '';
		}
		$menu = wp_nav_menu(
			array(
				'menu'        => (int) $s['menu'],
				'container'   => false,
				'menu_class'  => 'vc-nav__list',
				'depth'       => 1,
				'echo'        => false,
				'fallback_cb' => false,
			)
		);
		$vars = self::style(
			array(
				'--vc-fg'      => $s['textColor'],
				'--vc-bg'      => $s['bgColor'],
				'--vc-border'  => $s['border'] ? ( '' !== $s['borderColor'] ? $s['borderColor'] : 'currentColor' ) : null,
				'--vc-fs'      => (int) $s['fontSizePx'] . 'px',
				'--vc-pad'     => (int) $s['innerPaddingPx'] . 'px',
				'font-weight'  => $s['bold'] ? '600' : null,
			)
		);
		return '<div class="' . esc_attr( self::inner_class( $s ) ) . '"><nav class="vc-nav vc-nav--' . esc_attr( $s['align'] ) . ( $s['border'] ? ' vc-nav--border' : '' ) . '"' . $vars . ' aria-label="' . esc_attr__( 'Menu', 'peptide-core' ) . '">' . (string) $menu . '</nav></div>';
	}

	private static function render_logo_list( array $s, array $items ): string {
		$out = '<div class="vc-m__inner">' . self::heading( $s ) . '<div class="vc-logos vc-logos--' . esc_attr( $s['mode'] ) . '"' . self::cols_style( $s ) . '>';
		foreach ( $items as $it ) {
			$out .= '<div class="' . esc_attr( self::item_class( $it, 'vc-logos__item' ) ) . '">' . self::maybe_link( self::img( $it['image'], 'vc-logos__img', __( 'Logo', 'peptide-core' ) ), $it['href'] ) . '</div>';
		}
		return $out . '</div></div>';
	}

	private static function render_text_block( array $s ): string {
		$vars = self::style(
			array(
				'--vc-fg'        => $s['textColor'],
				'--vc-link'      => $s['linkColor'],
				'--vc-btn-c'     => $s['buttonColor'],
				'--vc-collapsed' => $s['collapsedHeightPx'] ? (int) $s['collapsedHeightPx'] . 'px' : null,
			)
		);
		$body = '' !== $s['title'] ? '<h2 class="vc-m__title">' . esc_html( $s['title'] ) . '</h2>' : '';
		$body .= '<div class="vc-textblock__body">' . self::html( $s['bodyHtml'] ) . '</div>';
		if ( $s['collapsedHeightPx'] ) {
			$body .= sprintf( '<button type="button" class="vc-textblock__toggle" data-expand="%1$s" data-collapse="%2$s" aria-expanded="false">%1$s</button>', esc_attr( $s['expandLabel'] ), esc_attr( $s['collapseLabel'] ) );
		}
		return '<div class="vc-m__inner"><div class="vc-textblock' . ( $s['collapsedHeightPx'] ? ' is-collapsible' : '' ) . '"' . $vars . '>' . $body . '</div></div>';
	}
}
