<?php
/**
 * 受控资产：主题自带的图片用“资产键”引用（例如迁移现有首页图片时），由主题通过过滤器登记。
 * 浏览器只拿到解析后的地址，不会看到本机路径。
 *
 * 主题登记示例：
 *     add_filter( 'peptide_core_layout_assets', function ( $assets ) {
 *         $assets['story-israel'] = array( 'url' => get_theme_file_uri( 'assets/images/israel.webp' ), 'width' => 1200, 'height' => 800 );
 *         return $assets;
 *     } );
 *
 * @package PeptideCore
 */

namespace PeptideCore\Modules;

defined( 'ABSPATH' ) || exit;

final class Assets {

	/**
	 * @return array<string,array{url:string,width:int,height:int}>
	 */
	public static function all(): array {
		$out = array();
		foreach ( (array) apply_filters( 'peptide_core_layout_assets', array() ) as $key => $asset ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || ! is_array( $asset ) || empty( $asset['url'] ) ) {
				continue;
			}
			$out[ $key ] = array(
				'url'    => esc_url_raw( (string) $asset['url'] ),
				'width'  => (int) ( $asset['width'] ?? 0 ),
				'height' => (int) ( $asset['height'] ?? 0 ),
			);
		}
		return $out;
	}

	public static function exists( string $key ): bool {
		return isset( self::all()[ $key ] );
	}

	/**
	 * 解析图片引用（附件优先，其次资产键）。没有可用图片时返回 null。
	 *
	 * @param array<string,mixed>|null $ref
	 * @return array{src:string,width:int,height:int,srcset:string,alt:string}|null
	 */
	public static function resolve( ?array $ref, string $size = 'full' ): ?array {
		if ( ! $ref ) {
			return null;
		}
		$alt = (string) ( $ref['alt'] ?? '' );
		$id  = (int) ( $ref['attachmentId'] ?? 0 );
		if ( $id ) {
			$src = wp_get_attachment_image_src( $id, $size );
			if ( $src ) {
				return array(
					'src'    => (string) $src[0],
					'width'  => (int) $src[1],
					'height' => (int) $src[2],
					'srcset' => (string) wp_get_attachment_image_srcset( $id, $size ),
					'alt'    => '' !== $alt ? $alt : (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
				);
			}
		}
		$key = (string) ( $ref['assetKey'] ?? '' );
		if ( '' !== $key ) {
			$asset = self::all()[ $key ] ?? null;
			if ( $asset ) {
				return array(
					'src'    => $asset['url'],
					'width'  => $asset['width'],
					'height' => $asset['height'],
					'srcset' => '',
					'alt'    => $alt,
				);
			}
		}
		return null;
	}
}
