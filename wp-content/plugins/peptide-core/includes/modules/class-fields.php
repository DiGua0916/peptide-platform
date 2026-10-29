<?php
/**
 * 装修模块的字段定义与清理（EDIT-BLOCKS-03）。
 *
 * 每个字段是一个数组：key、type、label、default，以及可选的 group、device、options、min、max、step、unit、help、fields（子条目）。
 * device=true 的字段分别保存电脑和手机两个值：{desktop, mobile}；手机为 null 表示沿用电脑的值。
 * 编辑器侧栏（assets/module-editor.js）和服务端清理都使用同一份定义。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Modules;

defined( 'ABSPATH' ) || exit;

final class Fields {

	/** 富文本允许的标签。 */
	private const HTML_TAGS = array(
		'p'      => array(),
		'br'     => array(),
		'strong' => array(),
		'b'      => array(),
		'em'     => array(),
		'i'      => array(),
		'u'      => array(),
		'ul'     => array(),
		'ol'     => array(),
		'li'     => array(),
		'h3'     => array(),
		'h4'     => array(),
		'span'   => array( 'class' => true ),
		'a'      => array(
			'href'   => true,
			'target' => true,
			'rel'    => true,
		),
	);

	public const ICONS = array( 'check', 'truck', 'shield', 'chat', 'mail', 'phone', 'location', 'star', 'clock', 'flask', 'box', 'globe', 'lock', 'document' );

	public const POSITIONS9 = array( 'top-left', 'top-center', 'top-right', 'middle-left', 'middle-center', 'middle-right', 'bottom-left', 'bottom-center', 'bottom-right' );

	/**
	 * 字段构造器。
	 *
	 * @param array<string,mixed> $extra
	 * @return array<string,mixed>
	 */
	public static function f( string $type, string $key, string $label, $default = null, array $extra = array() ): array {
		return array_merge(
			array(
				'key'     => $key,
				'type'    => $type,
				'label'   => $label,
				'default' => $default,
				'group'   => 'content',
			),
			$extra
		);
	}

	/**
	 * 九宫格位置选项。
	 *
	 * @return array<string,string>
	 */
	public static function positions9(): array {
		return array(
			'top-left'      => __( 'Top left', 'peptide-core' ),
			'top-center'    => __( 'Top center', 'peptide-core' ),
			'top-right'     => __( 'Top right', 'peptide-core' ),
			'middle-left'   => __( 'Middle left', 'peptide-core' ),
			'middle-center' => __( 'Center', 'peptide-core' ),
			'middle-right'  => __( 'Middle right', 'peptide-core' ),
			'bottom-left'   => __( 'Bottom left', 'peptide-core' ),
			'bottom-center' => __( 'Bottom center', 'peptide-core' ),
			'bottom-right'  => __( 'Bottom right', 'peptide-core' ),
		);
	}

	/**
	 * 空的媒体引用（尚未选择图片）。
	 *
	 * @return array<string,mixed>
	 */
	public static function empty_image(): array {
		return array(
			'attachmentId' => null,
			'assetKey'     => null,
			'alt'          => '',
		);
	}

	/**
	 * 字段默认值（device 字段返回 {desktop, mobile}）。
	 *
	 * @param array<string,mixed> $field
	 * @return mixed
	 */
	public static function default_value( array $field ) {
		$default = $field['default'] ?? null;
		if ( 'media' === $field['type'] && null === $default ) {
			$default = array(
				'desktop' => self::empty_image() + array( 'fit' => 'cover', 'focalPoint' => array( 'xPercent' => 50, 'yPercent' => 50 ) ),
				'mobile'  => null,
			);
		} elseif ( 'image' === $field['type'] && null === $default ) {
			$default = self::empty_image();
		} elseif ( 'video' === $field['type'] && null === $default ) {
			$default = array( 'attachmentId' => null );
		} elseif ( 'link' === $field['type'] && null === $default ) {
			$default = array( 'label' => '', 'href' => '', 'target' => '_self' );
		} elseif ( 'repeater' === $field['type'] && null === $default ) {
			$default = array();
		}
		if ( ! empty( $field['device'] ) && ! ( is_array( $default ) && array_key_exists( 'desktop', $default ) ) ) {
			$default = array(
				'desktop' => $default,
				'mobile'  => null,
			);
		}
		return $default;
	}

	/**
	 * 按字段定义清理一个值。无效时返回默认值。
	 *
	 * @param array<string,mixed> $field
	 * @param mixed               $value
	 * @return mixed
	 */
	public static function sanitize( array $field, $value ) {
		if ( ! empty( $field['device'] ) ) {
			$default = self::default_value( $field );
			$value   = is_array( $value ) ? $value : array();
			$single  = array_merge( $field, array( 'device' => false, 'default' => $default['desktop'] ) );
			$mobile  = array_merge( $field, array( 'device' => false, 'default' => $default['mobile'] ) );
			return array(
				'desktop' => self::sanitize( $single, $value['desktop'] ?? $default['desktop'] ),
				// 手机值为 null 表示沿用电脑的值（用户点了“和电脑一致”）；没有这个键时用默认值。
				// 0、false 和空字符串是有效值，不当作 null。
				'mobile'  => ! array_key_exists( 'mobile', $value ) ? $default['mobile'] : ( null === $value['mobile'] ? null : self::sanitize( $mobile, $value['mobile'] ) ),
			);
		}

		$default = self::default_value( $field );
		switch ( $field['type'] ) {
			case 'text':
				return is_scalar( $value ) ? self::cut( sanitize_text_field( (string) $value ), 300 ) : $default;
			case 'textarea':
				return is_scalar( $value ) ? self::cut( sanitize_textarea_field( (string) $value ), 2000 ) : $default;
			case 'html':
				return is_scalar( $value ) ? trim( wp_kses( (string) $value, self::HTML_TAGS ) ) : $default;
			case 'code':
				// 自定义 HTML 原样保存；渲染时只有具备 unfiltered_html 能力的人最后编辑的文档才会原样输出。
				return is_scalar( $value ) ? (string) $value : $default;
			case 'url':
				return is_scalar( $value ) ? self::url( (string) $value ) : $default;
			case 'toggle':
				return is_bool( $value ) ? $value : ( null === $value ? $default : (bool) $value );
			case 'select':
			case 'icon':
				$options = 'icon' === $field['type'] ? array_merge( array( '' ), self::ICONS ) : array_keys( $field['options'] ?? array() );
				return is_scalar( $value ) && in_array( (string) $value, array_map( 'strval', $options ), true ) ? (string) $value : $default;
			case 'number':
				if ( null === $value || '' === $value ) {
					return $default;
				}
				if ( ! is_numeric( $value ) ) {
					return $default;
				}
				$n = (float) $value;
				if ( isset( $field['min'] ) ) {
					$n = max( (float) $field['min'], $n );
				}
				if ( isset( $field['max'] ) ) {
					$n = min( (float) $field['max'], $n );
				}
				return ( ( $field['step'] ?? 1 ) >= 1 ) ? (int) round( $n ) : round( $n, 2 );
			case 'color':
				return is_string( $value ) && 1 === preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value ) ? strtolower( $value ) : ( '' === $value ? '' : $default );
			case 'image':
				return self::image( $value, $default );
			case 'media':
				$value = is_array( $value ) ? $value : array();
				$out   = array( 'desktop' => self::media_one( $value['desktop'] ?? null, $default['desktop'] ) );
				// 手机图未选择时为 null，前台沿用电脑图。
				$out['mobile'] = isset( $value['mobile'] ) && is_array( $value['mobile'] ) && ( ! empty( $value['mobile']['attachmentId'] ) || ! empty( $value['mobile']['assetKey'] ) )
					? self::media_one( $value['mobile'], $out['desktop'] )
					: null;
				return $out;
			case 'video':
				$id = is_array( $value ) ? absint( $value['attachmentId'] ?? 0 ) : 0;
				return array( 'attachmentId' => $id && 'attachment' === get_post_type( $id ) ? $id : null );
			case 'link':
				$value  = is_array( $value ) ? $value : array();
				$target = (string) ( $value['target'] ?? '_self' );
				return array(
					'label'  => self::cut( sanitize_text_field( (string) ( $value['label'] ?? '' ) ), 80 ),
					'href'   => self::url( (string) ( $value['href'] ?? '' ) ),
					'target' => in_array( $target, array( '_self', '_blank' ), true ) ? $target : '_self',
				);
			case 'menu':
				$id = absint( is_scalar( $value ) ? $value : 0 );
				return $id && is_nav_menu( $id ) ? $id : null;
			case 'category':
				$id = absint( is_scalar( $value ) ? $value : 0 );
				return $id && term_exists( $id, 'product_cat' ) ? $id : null;
			case 'product':
				$id = absint( is_scalar( $value ) ? $value : 0 );
				return $id && 'product' === get_post_type( $id ) ? $id : null;
			case 'record':
				// Preserve unavailable IDs so a stopped/deleted binding never silently turns into automatic mode.
				return Records::sanitize( $value );
			case 'repeater':
				$out = array();
				foreach ( is_array( $value ) ? $value : array() as $row ) {
					if ( count( $out ) >= (int) ( $field['max'] ?? 20 ) ) {
						break;
					}
					$out[] = self::item( $field['fields'] ?? array(), is_array( $row ) ? $row : array() );
				}
				return $out;
		}
		return $default;
	}

	/**
	 * 清理一个重复条目：{id, enabled, settings}。
	 *
	 * @param array<int,array<string,mixed>> $fields
	 * @param array<string,mixed>            $row
	 * @return array<string,mixed>
	 */
	public static function item( array $fields, array $row ): array {
		$settings = is_array( $row['settings'] ?? null ) ? $row['settings'] : array();
		$clean    = array();
		foreach ( $fields as $field ) {
			$clean[ $field['key'] ] = self::sanitize( $field, $settings[ $field['key'] ] ?? self::default_value( $field ) );
		}
		return array(
			'id'       => self::id( $row['id'] ?? '' ),
			'enabled'  => ! array_key_exists( 'enabled', $row ) || (bool) $row['enabled'],
			'settings' => $clean,
		);
	}

	/**
	 * 模块和条目的稳定 ID：字母、数字、短横线，最长 40 位；缺少时生成新的。
	 */
	public static function id( $value ): string {
		$value = is_string( $value ) ? strtolower( $value ) : '';
		return 1 === preg_match( '/^[a-z0-9][a-z0-9-]{3,39}$/', $value ) ? $value : 'm' . strtolower( wp_generate_password( 10, false ) );
	}

	/**
	 * @param mixed               $value
	 * @param array<string,mixed> $default
	 * @return array<string,mixed>
	 */
	private static function image( $value, array $default ): array {
		$value = is_array( $value ) ? $value : array();
		$id    = absint( $value['attachmentId'] ?? 0 );
		$key   = is_string( $value['assetKey'] ?? null ) ? sanitize_key( $value['assetKey'] ) : '';
		return array(
			'attachmentId' => $id && wp_attachment_is_image( $id ) ? $id : null,
			'assetKey'     => '' !== $key && Assets::exists( $key ) ? $key : null,
			'alt'          => self::cut( sanitize_text_field( (string) ( $value['alt'] ?? $default['alt'] ?? '' ) ), 200 ),
		);
	}

	/**
	 * @param mixed               $value
	 * @param array<string,mixed> $default
	 * @return array<string,mixed>
	 */
	private static function media_one( $value, array $default ): array {
		$value = is_array( $value ) ? $value : array();
		$fit   = (string) ( $value['fit'] ?? $default['fit'] ?? 'cover' );
		$fp    = is_array( $value['focalPoint'] ?? null ) ? $value['focalPoint'] : array();
		$pct   = static fn( $v ): int => (int) max( 0, min( 100, is_numeric( $v ) ? round( (float) $v ) : 50 ) );
		return self::image( $value, $default ) + array(
			'fit'        => in_array( $fit, array( 'cover', 'contain' ), true ) ? $fit : 'cover',
			'focalPoint' => array(
				'xPercent' => $pct( $fp['xPercent'] ?? 50 ),
				'yPercent' => $pct( $fp['yPercent'] ?? 50 ),
			),
		);
	}

	/**
	 * 链接：允许 http(s)、mailto、tel、站内相对路径和 # 锚点。
	 */
	public static function url( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( str_starts_with( $value, '#' ) ) {
			return '#' . sanitize_title( substr( $value, 1 ) );
		}
		if ( str_starts_with( $value, '/' ) && ! str_starts_with( $value, '//' ) ) {
			// 站内链接保存为相对路径，换域名后仍然有效。
			return (string) esc_url_raw( $value );
		}
		return (string) esc_url_raw( $value, array( 'http', 'https', 'mailto', 'tel' ) );
	}

	private static function cut( string $value, int $max ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
	}
}
