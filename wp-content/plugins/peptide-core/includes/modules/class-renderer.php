<?php
/**
 * 模块渲染（EDIT-BLOCKS-03）。
 *
 * 外层由插件输出：<section class="vc-m vc-m--<type>"> 加上电脑 / 手机显示、上下留白、背景色和锚点 id。
 * 内层优先使用主题模板 template-parts/modules/<type 短横线>.php（Codex 负责），参数：
 *     $args['module']  已清理、补好默认值的模块 {id, type, version, enabled, visibility, settings, items}
 *     $args['context'] public | preview | editor
 * 主题没有模板时使用插件自带的默认外观（Fallback），保证编辑器里能看到效果。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Modules;

defined( 'ABSPATH' ) || exit;

final class Renderer {

	public const STYLE  = 'peptide-core-modules';
	public const SCRIPT = 'peptide-core-modules';

	/** 正在渲染的装修文档 ID（自定义 HTML 据此判断最后编辑者的权限）；0 表示未知。 */
	public static int $document_id = 0;

	/** 当前请求是否是编辑器的区块预览（/wp/v2/block-renderer）。 */
	public static bool $in_editor = false;

	/**
	 * 把区块属性清理成规范模块。未知类型返回 null。
	 *
	 * @param array<string,mixed> $attrs
	 * @return array<string,mixed>|null
	 */
	public static function normalize( string $type, array $attrs ): ?array {
		$def = Registry::get( $type );
		if ( ! $def ) {
			return null;
		}
		$raw      = is_array( $attrs['settings'] ?? null ) ? $attrs['settings'] : array();
		$settings = array();
		foreach ( $def['settings'] as $field ) {
			$settings[ $field['key'] ] = Fields::sanitize( $field, array_key_exists( $field['key'], $raw ) ? $raw[ $field['key'] ] : Fields::default_value( $field ) );
		}

		$items = array();
		if ( ! empty( $def['items'] ) ) {
			$rows = is_array( $attrs['items'] ?? null ) ? array_values( $attrs['items'] ) : array();
			$rows = array_slice( $rows, 0, (int) $def['items']['max'] );
			foreach ( $rows as $row ) {
				$items[] = Fields::item( $def['items']['fields'], is_array( $row ) ? $row : array() );
			}
		}

		return array(
			'id'         => Fields::id( $attrs['moduleId'] ?? '' ),
			'type'       => $type,
			'version'    => (int) $def['version'],
			'enabled'    => ! array_key_exists( 'enabled', $attrs ) || (bool) $attrs['enabled'],
			'visibility' => array(
				'desktop' => (bool) $settings['showDesktop'],
				'mobile'  => (bool) $settings['showMobile'],
			),
			'settings'   => $settings,
			'items'      => $items,
		);
	}

	/**
	 * 区块渲染回调。
	 *
	 * @param array<string,mixed> $attrs
	 */
	public static function render_block( array $attrs, string $content = '', $block = null ): string {
		$name = $block instanceof \WP_Block ? $block->name : '';
		$type = Registry::type_for_block( $name );
		if ( ! $type ) {
			return '';
		}
		return self::render( $type, $attrs, self::context() );
	}

	public static function context(): string {
		if ( self::$in_editor ) {
			return 'editor';
		}
		return \PeptideCore\Templates::previewing_id() ? 'preview' : 'public';
	}

	/**
	 * @param array<string,mixed> $attrs
	 */
	public static function render( string $type, array $attrs, string $context = 'public' ): string {
		$module = self::normalize( $type, $attrs );
		if ( ! $module ) {
			return 'editor' === $context ? self::notice( __( 'Unknown module type.', 'peptide-core' ) ) : '';
		}
		$editor = 'editor' === $context;
		if ( ! $module['enabled'] && ! $editor ) {
			return '';
		}
		if ( ! $module['visibility']['desktop'] && ! $module['visibility']['mobile'] && ! $editor ) {
			return '';
		}

		wp_enqueue_style( self::STYLE );
		if ( ! $editor ) {
			wp_enqueue_script( self::SCRIPT );
		}

		$inner = self::theme_template( $module, $context );
		if ( null === $inner ) {
			$inner = Fallback::render( $module, $context );
		}
		return self::wrap( $module, $inner, $editor );
	}

	/**
	 * 主题模板（template-parts/modules/<type 短横线>.php）。没有模板时返回 null。
	 *
	 * @param array<string,mixed> $module
	 */
	private static function theme_template( array $module, string $context ): ?string {
		$slug = 'template-parts/modules/' . str_replace( '_', '-', $module['type'] );
		if ( ! locate_template( $slug . '.php' ) ) {
			return null;
		}
		ob_start();
		get_template_part(
			$slug,
			null,
			array(
				'module'  => $module,
				'context' => $context,
			)
		);
		return (string) ob_get_clean();
	}

	/**
	 * @param array<string,mixed> $module
	 */
	private static function wrap( array $module, string $inner, bool $editor ): string {
		$s       = $module['settings'];
		$classes = array( 'vc-m', 'vc-m--' . str_replace( '_', '-', $module['type'] ) );
		if ( ! $module['visibility']['desktop'] ) {
			$classes[] = 'vc-hide-desktop';
		}
		if ( ! $module['visibility']['mobile'] ) {
			$classes[] = 'vc-hide-mobile';
		}
		if ( $editor ) {
			$classes[] = 'vc-m--editor';
		}
		$vars = array();
		foreach ( array( 'paddingTopPx' => 'pt', 'paddingBottomPx' => 'pb' ) as $key => $var ) {
			$v = $s[ $key ];
			if ( null !== $v['desktop'] ) {
				$vars[] = "--vc-m-{$var}:" . (int) $v['desktop'] . 'px';
			}
			$mobile = null !== $v['mobile'] ? $v['mobile'] : $v['desktop'];
			if ( null !== $mobile ) {
				$vars[] = "--vc-m-{$var}-m:" . (int) $mobile . 'px';
			}
		}
		if ( '' !== $s['backgroundColor'] ) {
			$vars[] = '--vc-m-bg:' . $s['backgroundColor'];
		}

		$badge = '';
		if ( $editor && ( ! $module['enabled'] || ! $module['visibility']['desktop'] || ! $module['visibility']['mobile'] ) ) {
			$label = ! $module['enabled'] ? __( 'Hidden', 'peptide-core' ) : ( ! $module['visibility']['desktop'] ? __( 'Hidden on desktop', 'peptide-core' ) : __( 'Hidden on mobile', 'peptide-core' ) );
			$badge = '<span class="vc-m__badge">' . esc_html( $label ) . '</span>';
		}

		return sprintf(
			'<section id="%1$s" class="%2$s" data-module="%3$s"%4$s>%5$s%6$s</section>',
			esc_attr( 'vc-' . $module['id'] ),
			esc_attr( implode( ' ', $classes ) ),
			esc_attr( $module['type'] ),
			$vars ? ' style="' . esc_attr( implode( ';', $vars ) ) . '"' : '',
			$badge,
			$inner
		);
	}

	public static function notice( string $text ): string {
		return '<div class="vc-m__notice">' . esc_html( $text ) . '</div>';
	}
}
