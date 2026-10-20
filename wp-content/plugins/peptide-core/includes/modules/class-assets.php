<?php
/**
 * 受控资产：主题自带的图片用“资产键”引用（例如迁移现有首页图片时），由主题通过过滤器登记。
 * 浏览器只拿到解析后的地址，不会看到本机路径。
 *
 * 主题登记示例：
 *     add_filter( 'peptide_core_layout_assets', function ( $assets ) {
 *         $assets['story-israel'] = array(
 *             'url'    => get_theme_file_uri( 'assets/images/israel-1200.webp' ),
 *             'width'  => 1200,
 *             'height' => 800,
 *             'srcset' => get_theme_file_uri( 'assets/images/israel-600.webp' ) . ' 600w, ' . get_theme_file_uri( 'assets/images/israel-1200.webp' ) . ' 1200w', // 可选
 *         );
 *         return $assets;
 *     } );
 *
 * @package PeptideCore
 */

namespace PeptideCore\Modules;

defined( 'ABSPATH' ) || exit;

final class Assets {

	/**
	 * 现有首页图片的默认登记（SYNC-HOME-01）：主题还没登记时，先按主题里现有的文件引用，让首页能直接导入编辑。
	 * 只登记主题里确实存在的文件；主题用同名的键登记时（默认优先级 10）会覆盖这里的值。
	 * 键 => [大图相对路径, 小图相对路径]
	 */
	private const HOME_FILES = array(
		'home-packaging'            => array( 'assets/brand/packaging-corrected-20261017-1536.webp', 'assets/brand/packaging-corrected-20261017-768.webp' ),
		'carousel-israel-research'  => array( 'assets/images/carousel-israel-research-1440.webp', 'assets/images/carousel-israel-research-768.webp' ),
		'carousel-china-production' => array( 'assets/images/carousel-china-production-1440.webp', 'assets/images/carousel-china-production-768.webp' ),
		'carousel-us-warehouse'     => array( 'assets/images/carousel-us-warehouse-1440.webp', 'assets/images/carousel-us-warehouse-768.webp' ),
		'story-israel-research'     => array( 'assets/images/israel-research-concept-1200.webp', 'assets/images/israel-research-concept-600.webp' ),
		'story-china-manufacturing' => array( 'assets/images/china-manufacturing-concept-1200.webp', 'assets/images/china-manufacturing-concept-600.webp' ),
		'story-us-fulfillment'      => array( 'assets/images/us-fulfillment-concept-1200.webp', 'assets/images/us-fulfillment-concept-600.webp' ),
	);

	public static function register_hooks(): void {
		add_filter( 'peptide_core_layout_assets', array( self::class, 'home_defaults' ), 5 );
	}

	/**
	 * @param array<string,mixed> $assets
	 * @return array<string,mixed>
	 */
	public static function home_defaults( $assets ): array {
		$assets = is_array( $assets ) ? $assets : array();
		$dir    = get_template_directory();
		$uri    = get_template_directory_uri();
		foreach ( self::HOME_FILES as $key => [ $large, $small ] ) {
			if ( isset( $assets[ $key ] ) || ! is_readable( $dir . '/' . $large ) ) {
				continue;
			}
			$size_l = function_exists( 'wp_getimagesize' ) ? wp_getimagesize( $dir . '/' . $large ) : false;
			$size_s = is_readable( $dir . '/' . $small ) && function_exists( 'wp_getimagesize' ) ? wp_getimagesize( $dir . '/' . $small ) : false;
			$srcset = $size_s && $size_l ? $uri . '/' . $small . ' ' . (int) $size_s[0] . 'w, ' . $uri . '/' . $large . ' ' . (int) $size_l[0] . 'w' : '';
			$assets[ $key ] = array(
				'url'    => $uri . '/' . $large,
				'width'  => $size_l ? (int) $size_l[0] : 0,
				'height' => $size_l ? (int) $size_l[1] : 0,
				'srcset' => $srcset,
			);
		}
		return $assets;
	}

	/**
	 * @return array<string,array{url:string,width:int,height:int,srcset:string}>
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
				'srcset' => isset( $asset['srcset'] ) ? (string) $asset['srcset'] : '',
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
					'srcset' => $asset['srcset'],
					'alt'    => $alt,
				);
			}
		}
		return null;
	}
}
