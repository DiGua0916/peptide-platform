<?php
/**
 * 装修模块库（EDIT-BLOCKS-03）。第一批：基础积木 30 个（参考 Codex 的 YY 审计 yy-basic-01…31，不做“捐款/小费”）
 * + VialCove 首页的“图文行”（品牌故事三行左右交替）。
 *
 * 每个模块：type（数据里的类型，下划线）、block（区块名 vialcove/<短横线>）、title、category、icon、version、settings 字段、items 条目定义。
 * 字段格式见 Fields；所有模块自动加上“通用”分组（电脑 / 手机显示、上下留白）。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Modules;

defined( 'ABSPATH' ) || exit;

final class Registry {

	public const CATEGORIES = array( 'basic', 'product', 'operation' );

	/** @var array<string,array<string,mixed>>|null */
	private static ?array $cache = null;

	/**
	 * @return array<string,string> 分类 => 名称（与 YY 模块库分组一致）
	 */
	public static function categories(): array {
		return array(
			'basic'     => __( 'Basic blocks', 'peptide-core' ),
			'product'   => __( 'Product blocks', 'peptide-core' ),
			'operation' => __( 'Marketing blocks', 'peptide-core' ),
		);
	}

	/**
	 * @return array<string,string> 分组 => 名称（侧栏面板）
	 */
	public static function groups(): array {
		return array(
			'content' => __( 'Content', 'peptide-core' ),
			'media'   => __( 'Media', 'peptide-core' ),
			'layout'  => __( 'Layout', 'peptide-core' ),
			'play'    => __( 'Playback', 'peptide-core' ),
			'button'  => __( 'Button', 'peptide-core' ),
			'style'   => __( 'Colors', 'peptide-core' ),
			'general' => __( 'Display & spacing', 'peptide-core' ),
		);
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$defs = array();
			foreach ( self::definitions() as $type => $def ) {
				$def['type']     = $type;
				$def['block']    = 'vialcove/' . str_replace( '_', '-', $type );
				$def['version']  = $def['version'] ?? 1;
				$def['category'] = $def['category'] ?? 'basic';
				$def['settings'] = array_merge( $def['settings'], self::general_fields() );
				$defs[ $type ]   = $def;
			}
			self::$cache = (array) apply_filters( 'peptide_core_layout_modules', $defs );
		}
		return self::$cache;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function get( string $type ): ?array {
		return self::all()[ $type ] ?? null;
	}

	public static function type_for_block( string $block ): ?string {
		foreach ( self::all() as $type => $def ) {
			if ( $def['block'] === $block ) {
				return $type;
			}
		}
		return null;
	}

	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * 所有模块共有的“显示与留白”设置。null 表示使用全局默认留白。
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function general_fields(): array {
		$g = array( 'group' => 'general' );
		return array(
			Fields::f( 'toggle', 'showDesktop', __( 'Show on desktop', 'peptide-core' ), true, $g ),
			Fields::f( 'toggle', 'showMobile', __( 'Show on mobile', 'peptide-core' ), true, $g ),
			Fields::f( 'number', 'paddingTopPx', __( 'Space above (px, empty = default)', 'peptide-core' ), null, $g + array( 'device' => true, 'min' => 0, 'max' => 240 ) ),
			Fields::f( 'number', 'paddingBottomPx', __( 'Space below (px, empty = default)', 'peptide-core' ), null, $g + array( 'device' => true, 'min' => 0, 'max' => 240 ) ),
			Fields::f( 'color', 'backgroundColor', __( 'Module background', 'peptide-core' ), '', $g ),
		);
	}

	/**
	 * 常用字段组合。
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function heading( bool $with_desc = true ): array {
		$out = array( Fields::f( 'text', 'title', __( 'Title', 'peptide-core' ), '' ) );
		if ( $with_desc ) {
			$out[] = Fields::f( 'textarea', 'description', __( 'Short description', 'peptide-core' ), '' );
		}
		return $out;
	}

	private static function full_width( bool $default = false ): array {
		return Fields::f( 'toggle', 'fullWidth', __( 'Full width', 'peptide-core' ), $default, array( 'group' => 'layout' ) );
	}

	/**
	 * 按钮：文字、链接、打开方式、特效、动画时间、颜色。
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function button( bool $colors = true ): array {
		$b   = array( 'group' => 'button' );
		$out = array(
			Fields::f( 'link', 'button', __( 'Button', 'peptide-core' ), null, $b ),
			Fields::f(
				'select',
				'buttonEffect',
				__( 'Button effect', 'peptide-core' ),
				'none',
				$b + array(
					'options' => array(
						'none'  => __( 'None', 'peptide-core' ),
						'pulse' => __( 'Pulse', 'peptide-core' ),
						'shine' => __( 'Shine', 'peptide-core' ),
					),
				)
			),
			Fields::f( 'number', 'effectDurationMs', __( 'Effect duration (ms)', 'peptide-core' ), 1000, $b + array( 'min' => 0, 'max' => 5000, 'step' => 100 ) ),
			Fields::f( 'number', 'effectIntervalMs', __( 'Effect interval (ms)', 'peptide-core' ), 1500, $b + array( 'min' => 0, 'max' => 10000, 'step' => 100 ) ),
		);
		if ( $colors ) {
			$out[] = Fields::f( 'color', 'buttonTextColor', __( 'Button text color', 'peptide-core' ), '', $b );
			$out[] = Fields::f( 'color', 'buttonBgColor', __( 'Button background', 'peptide-core' ), '', $b );
		}
		return $out;
	}

	private static function columns( int $desktop, int $mobile, int $max = 6 ): array {
		return Fields::f(
			'number',
			'columns',
			__( 'Items per row', 'peptide-core' ),
			array(
				'desktop' => $desktop,
				'mobile'  => $mobile,
			),
			array( 'group' => 'layout', 'device' => true, 'min' => 1, 'max' => $max )
		);
	}

	private static function color( string $key, string $label, string $default = '' ): array {
		return Fields::f( 'color', $key, $label, $default, array( 'group' => 'style' ) );
	}

	private static function select( string $key, string $label, string $default, array $options, string $group = 'layout', array $extra = array() ): array {
		return Fields::f( 'select', $key, $label, $default, array_merge( array( 'group' => $group, 'options' => $options ), $extra ) );
	}

	private static function number( string $key, string $label, $default, int $min, int $max, string $group = 'layout', array $extra = array() ): array {
		return Fields::f( 'number', $key, $label, $default, array_merge( array( 'group' => $group, 'min' => $min, 'max' => $max ), $extra ) );
	}

	private static function toggle( string $key, string $label, bool $default, string $group = 'layout' ): array {
		return Fields::f( 'toggle', $key, $label, $default, array( 'group' => $group ) );
	}

	private static function media( string $key = 'media', string $label = '', string $hint = '' ): array {
		return Fields::f( 'media', $key, '' !== $label ? $label : __( 'Image', 'peptide-core' ), null, array( 'group' => 'media', 'help' => $hint ) );
	}

	private static function image( string $key, string $label, string $hint = '', string $group = 'content' ): array {
		return Fields::f( 'image', $key, $label, null, array( 'group' => $group, 'help' => $hint ) );
	}

	private static function link_url( string $key = 'href', string $label = '' ): array {
		return Fields::f( 'url', $key, '' !== $label ? $label : __( 'Link', 'peptide-core' ), '' );
	}

	/**
	 * 条目定义。
	 *
	 * @param array<int,array<string,mixed>> $fields
	 * @return array<string,mixed>
	 */
	private static function items( string $label, array $fields, int $default_count, int $min = 0, int $max = 20, string $title_field = 'title', array $default_items = array() ): array {
		return array(
			'label'         => $label,
			'fields'        => $fields,
			'default_count' => $default_count,
			'min'           => $min,
			'max'           => $max,
			'title_field'   => $title_field,
			// 新插入模块时各条目与默认值不同的字段（例如图文行默认左、右、左）。
			'default_items' => $default_items,
		);
	}

	private static function align_options(): array {
		return array(
			'left'   => __( 'Left', 'peptide-core' ),
			'center' => __( 'Center', 'peptide-core' ),
			'right'  => __( 'Right', 'peptide-core' ),
		);
	}

	private static function video_fields(): array {
		return array(
			self::select(
				'source',
				__( 'Video source', 'peptide-core' ),
				'file',
				array(
					'file'    => __( 'Uploaded video', 'peptide-core' ),
					'youtube' => __( 'YouTube link', 'peptide-core' ),
				),
				'media'
			),
			Fields::f( 'video', 'video', __( 'Video file', 'peptide-core' ), null, array( 'group' => 'media', 'show_if' => array( 'source' => 'file' ) ) ),
			Fields::f( 'url', 'youtubeUrl', __( 'YouTube URL', 'peptide-core' ), '', array( 'group' => 'media', 'show_if' => array( 'source' => 'youtube' ) ) ),
			self::image( 'poster', __( 'Cover image', 'peptide-core' ), __( 'Recommended width 1000px or more.', 'peptide-core' ), 'media' ),
			self::toggle( 'autoplay', __( 'Autoplay (muted)', 'peptide-core' ), true, 'play' ),
			self::toggle( 'loop', __( 'Loop', 'peptide-core' ), false, 'play' ),
			self::toggle( 'muted', __( 'Muted', 'peptide-core' ), true, 'play' ),
			self::toggle( 'controls', __( 'Show controls', 'peptide-core' ), true, 'play' ),
		);
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private static function definitions(): array {
		$pos9  = Fields::positions9();
		$align = self::align_options();

		return array(
			// yy-basic-01 轮播图
			'carousel'         => array(
				'title'       => __( 'Carousel', 'peptide-core' ),
				'icon'        => 'images-alt2',
				'description' => __( 'Full-width slides with separate desktop and mobile images.', 'peptide-core' ),
				'settings'    => array(
					self::toggle( 'autoplay', __( 'Autoplay', 'peptide-core' ), true, 'play' ),
					self::number( 'intervalMs', __( 'Time per slide (ms)', 'peptide-core' ), 3000, 1000, 20000, 'play', array( 'step' => 500 ) ),
					self::toggle( 'pagination', __( 'Show dots', 'peptide-core' ), true, 'play' ),
					self::toggle( 'arrows', __( 'Show arrows', 'peptide-core' ), false, 'play' ),
					self::select(
						'height',
						__( 'Image height', 'peptide-core' ),
						'auto',
						array(
							'auto'   => __( 'Image height (auto)', 'peptide-core' ),
							'screen' => __( 'Full screen', 'peptide-core' ),
							'fixed'  => __( 'Fixed height', 'peptide-core' ),
						)
					),
					self::number( 'heightPx', __( 'Fixed height (px)', 'peptide-core' ), array( 'desktop' => 640, 'mobile' => 520 ), 200, 1200, 'layout', array( 'device' => true, 'show_if' => array( 'height' => 'fixed' ) ) ),
					self::full_width( true ),
					self::number( 'titleSizePx', __( 'Title size (px)', 'peptide-core' ), 60, 16, 120, 'style' ),
				),
				'items'       => self::items(
					__( 'Slides', 'peptide-core' ),
					array(
						Fields::f( 'text', 'name', __( 'Slide name (read by screen readers)', 'peptide-core' ), '' ),
						self::select(
							'layout',
							__( 'Slide layout', 'peptide-core' ),
							'overlay',
							array(
								'overlay' => __( 'Text over the image', 'peptide-core' ),
								'split'   => __( 'Text beside the image', 'peptide-core' ),
							),
							'content'
						),
						self::media( 'media', __( 'Slide image', 'peptide-core' ), __( 'Desktop: 1920px wide. Mobile: 1242px wide. Height follows the image.', 'peptide-core' ) ),
						Fields::f( 'text', 'eyebrow', __( 'Subtitle', 'peptide-core' ), '' ),
						Fields::f( 'text', 'title', __( 'Title', 'peptide-core' ), '' ),
						Fields::f( 'text', 'titleAccent', __( 'Title (second line, emphasized)', 'peptide-core' ), '' ),
						Fields::f( 'textarea', 'description', __( 'Text', 'peptide-core' ), '' ),
						Fields::f( 'link', 'button', __( 'Button', 'peptide-core' ) ),
						Fields::f( 'link', 'secondaryLink', __( 'Text link (next to the button)', 'peptide-core' ) ),
						Fields::f(
							'repeater',
							'facts',
							__( 'Short facts under the text', 'peptide-core' ),
							null,
							array(
								'max'    => 4,
								'fields' => array(
									Fields::f( 'text', 'label', __( 'Bold text', 'peptide-core' ), '' ),
									Fields::f( 'text', 'text', __( 'Text', 'peptide-core' ), '' ),
								),
							)
						),
						Fields::f(
							'repeater',
							'highlights',
							__( 'Floating cards on the image', 'peptide-core' ),
							null,
							array(
								'max'    => 3,
								'fields' => array(
									Fields::f( 'icon', 'icon', __( 'Icon', 'peptide-core' ), '' ),
									Fields::f( 'text', 'label', __( 'Small label', 'peptide-core' ), '' ),
									Fields::f( 'text', 'title', __( 'Title', 'peptide-core' ), '' ),
									Fields::f( 'text', 'action', __( 'Action text', 'peptide-core' ), '' ),
									Fields::f( 'url', 'href', __( 'Link', 'peptide-core' ), '' ),
								),
							)
						),
						Fields::f( 'text', 'footnote', __( 'Footnote under the text', 'peptide-core' ), '' ),
						Fields::f( 'text', 'sceneNumber', __( 'Image number label', 'peptide-core' ), '' ),
						Fields::f( 'text', 'captionTitle', __( 'Image caption (bold)', 'peptide-core' ), '' ),
						Fields::f( 'text', 'captionNote', __( 'Image caption (note)', 'peptide-core' ), '' ),
						self::select( 'position', __( 'Text position', 'peptide-core' ), 'middle-left', $pos9, 'layout', array( 'device' => true ) ),
						self::color( 'overlayColor', __( 'Overlay color', 'peptide-core' ), '#000000' ),
						self::number( 'overlayOpacity', __( 'Overlay opacity (%)', 'peptide-core' ), 0, 0, 90, 'style' ),
						self::color( 'textColor', __( 'Text color', 'peptide-core' ) ),
						self::color( 'buttonTextColor', __( 'Button text color', 'peptide-core' ) ),
						self::color( 'buttonBgColor', __( 'Button background', 'peptide-core' ) ),
					),
					2,
					1,
					12
				),
			),

			// yy-basic-02 单图
			'image'            => array(
				'title'    => __( 'Single image', 'peptide-core' ),
				'icon'     => 'format-image',
				'settings' => array_merge(
					array(
						self::media( 'media', '', __( 'Desktop: 1920 × 800 / 600 / 420. Mobile: 1242 × 2000.', 'peptide-core' ) ),
						self::link_url( 'href', __( 'Image link', 'peptide-core' ) ),
						self::select(
							'height',
							__( 'Image height', 'peptide-core' ),
							'auto',
							array(
								'auto' => __( 'Image height (auto)', 'peptide-core' ),
								'420'  => '420px',
								'600'  => '600px',
								'800'  => '800px',
							)
						),
						self::full_width( true ),
						self::color( 'overlayColor', __( 'Overlay color', 'peptide-core' ), '#000000' ),
						self::number( 'overlayOpacity', __( 'Overlay opacity (%)', 'peptide-core' ), 0, 0, 90, 'style' ),
					),
					self::heading(),
					array(
						self::select( 'position', __( 'Text position', 'peptide-core' ), 'middle-center', $pos9, 'layout', array( 'device' => true ) ),
						self::color( 'titleColor', __( 'Title color', 'peptide-core' ) ),
						self::color( 'textColor', __( 'Text color', 'peptide-core' ) ),
					),
					self::button()
				),
			),

			// yy-basic-03 单排图片
			'image_row'        => array(
				'title'    => __( 'Image row', 'peptide-core' ),
				'icon'     => 'images-alt',
				'settings' => array_merge(
					self::heading(),
					array(
						self::full_width(),
						self::number( 'gapPx', __( 'Gap (px)', 'peptide-core' ), 16, 0, 120 ),
						self::select(
							'mobileStyle',
							__( 'Mobile style', 'peptide-core' ),
							'scroll',
							array(
								'scroll' => __( 'Swipe sideways', 'peptide-core' ),
								'stack'  => __( 'Stack vertically', 'peptide-core' ),
							)
						),
					)
				),
				'items'    => self::items(
					__( 'Images', 'peptide-core' ),
					array(
						self::media(),
						self::link_url(),
					),
					3,
					1,
					12,
					''
				),
			),

			// yy-basic-04 多列图片
			'image_grid'       => array(
				'title'    => __( 'Image grid', 'peptide-core' ),
				'icon'     => 'grid-view',
				'settings' => array_merge(
					self::heading(),
					array(
						self::full_width(),
						self::number( 'gapPx', __( 'Gap (px)', 'peptide-core' ), 16, 0, 120 ),
						self::columns( 4, 2 ),
					)
				),
				'items'    => self::items(
					__( 'Images', 'peptide-core' ),
					array(
						self::media(),
						self::link_url(),
					),
					4,
					1,
					24,
					''
				),
			),

			// yy-basic-05 / 06 / 07 多图 3 / 4 / 5
			'mosaic_3'         => self::mosaic( 3, __( 'Mosaic (3 images)', 'peptide-core' ), array( 'left' => __( 'Large image on the left', 'peptide-core' ), 'right' => __( 'Large image on the right', 'peptide-core' ) ) ),
			'mosaic_4'         => self::mosaic( 4, __( 'Mosaic (4 images)', 'peptide-core' ), array( 'left' => __( 'Tall image on the left', 'peptide-core' ), 'right' => __( 'Tall image on the right', 'peptide-core' ) ) ),
			'mosaic_5'         => self::mosaic(
				5,
				__( 'Mosaic (5 images)', 'peptide-core' ),
				array(
					'left'   => __( 'Tall image on the left', 'peptide-core' ),
					'center' => __( 'Tall image in the middle', 'peptide-core' ),
					'right'  => __( 'Tall image on the right', 'peptide-core' ),
					'top'    => __( 'Wide image on top', 'peptide-core' ),
				)
			),

			// yy-basic-08 图文
			'media_text'       => array(
				'title'    => __( 'Image & text', 'peptide-core' ),
				'icon'     => 'align-pull-left',
				'settings' => array_merge(
					array(
						self::media( 'media', '', __( 'Ratio 4:3, 3:2, 2:1 or 1:1, at least 1000px wide.', 'peptide-core' ) ),
						Fields::f( 'text', 'title', __( 'Title', 'peptide-core' ), '' ),
						Fields::f( 'html', 'bodyHtml', __( 'Text', 'peptide-core' ), '' ),
						self::select(
							'imagePosition',
							__( 'Image position', 'peptide-core' ),
							'left',
							array(
								'left'   => __( 'Left', 'peptide-core' ),
								'right'  => __( 'Right', 'peptide-core' ),
								'top'    => __( 'Above the text', 'peptide-core' ),
								'bottom' => __( 'Below the text', 'peptide-core' ),
							),
							'layout',
							array( 'device' => true, 'default' => array( 'desktop' => 'left', 'mobile' => 'top' ) )
						),
						self::select( 'textAlign', __( 'Text alignment', 'peptide-core' ), 'left', $align ),
						self::full_width(),
						self::color( 'titleColor', __( 'Title color', 'peptide-core' ) ),
						self::color( 'textColor', __( 'Text color', 'peptide-core' ) ),
						self::color( 'contentBgColor', __( 'Text background', 'peptide-core' ) ),
					),
					self::button( false )
				),
			),

			// VialCove 首页“品牌故事”：多行图文，每行单独设置左右位置（Codex 交接的 media_text_list）。
			'media_text_list'  => array(
				'title'       => __( 'Image & text rows', 'peptide-core' ),
				'icon'        => 'align-left',
				'description' => __( 'Several image-and-text rows; each row chooses its own image side (e.g. left, right, left).', 'peptide-core' ),
				'settings'    => array(
					Fields::f( 'text', 'eyebrow', __( 'Small heading', 'peptide-core' ), '' ),
					Fields::f( 'text', 'title', __( 'Title', 'peptide-core' ), '' ),
					Fields::f( 'text', 'titleAccent', __( 'Title (second line, emphasized)', 'peptide-core' ), '' ),
					Fields::f( 'html', 'descriptionHtml', __( 'Introduction', 'peptide-core' ), '' ),
					self::select(
						'container',
						__( 'Width', 'peptide-core' ),
						'content',
						array(
							'content' => __( 'Content width', 'peptide-core' ),
							'wide'    => __( 'Wide', 'peptide-core' ),
							'full'    => __( 'Full width', 'peptide-core' ),
						)
					),
					self::number( 'itemGapPx', __( 'Gap between rows (px)', 'peptide-core' ), array( 'desktop' => 48, 'mobile' => 32 ), 0, 240, 'layout', array( 'device' => true ) ),
					self::toggle( 'animation', __( 'Fade in on scroll', 'peptide-core' ), true, 'layout' ),
					Fields::f( 'textarea', 'footnote', __( 'Note under the rows (e.g. image disclosure)', 'peptide-core' ), '' ),
				),
				'items'       => self::items(
					__( 'Rows', 'peptide-core' ),
					array(
						Fields::f( 'text', 'eyebrow', __( 'Small heading', 'peptide-core' ), '' ),
						Fields::f( 'text', 'title', __( 'Title', 'peptide-core' ), '' ),
						Fields::f( 'text', 'label', __( 'Label', 'peptide-core' ), '' ),
						Fields::f( 'html', 'bodyHtml', __( 'Text', 'peptide-core' ), '' ),
						self::media(),
						Fields::f( 'text', 'caption', __( 'Image caption', 'peptide-core' ), '', array( 'group' => 'media' ) ),
						self::select(
							'imagePosition',
							__( 'Image position', 'peptide-core' ),
							'left',
							array(
								'left'   => __( 'Left', 'peptide-core' ),
								'right'  => __( 'Right', 'peptide-core' ),
								'top'    => __( 'Above the text', 'peptide-core' ),
								'bottom' => __( 'Below the text', 'peptide-core' ),
							),
							'layout',
							array( 'device' => true, 'default' => array( 'desktop' => 'left', 'mobile' => 'top' ) )
						),
						Fields::f( 'link', 'button', __( 'Button', 'peptide-core' ) ),
					),
					3,
					1,
					12,
					'title',
					array(
						array( 'imagePosition' => array( 'desktop' => 'left', 'mobile' => 'top' ) ),
						array( 'imagePosition' => array( 'desktop' => 'right', 'mobile' => 'top' ) ),
						array( 'imagePosition' => array( 'desktop' => 'left', 'mobile' => 'top' ) ),
					)
				),
			),

			// yy-basic-09 图文列表
			'card_list'        => array(
				'title'    => __( 'Card list', 'peptide-core' ),
				'icon'     => 'screenoptions',
				'settings' => array_merge(
					self::heading(),
					array(
						self::full_width(),
						self::select(
							'display',
							__( 'Display', 'peptide-core' ),
							'grid',
							array(
								'grid'     => __( 'Grid', 'peptide-core' ),
								'carousel' => __( 'Carousel', 'peptide-core' ),
							)
						),
						self::select( 'textAlign', __( 'Text alignment', 'peptide-core' ), 'left', $align ),
						self::columns( 3, 1 ),
						self::number( 'gapPx', __( 'Card gap (px)', 'peptide-core' ), 24, 10, 120 ),
					)
				),
				'items'    => self::items(
					__( 'Cards', 'peptide-core' ),
					array(
						self::media(),
						Fields::f( 'text', 'title', __( 'Title', 'peptide-core' ), '' ),
						Fields::f( 'textarea', 'text', __( 'Text', 'peptide-core' ), '' ),
						self::link_url(),
					),
					3,
					1,
					24
				),
			),

			// yy-basic-10 视频
			'video'            => array(
				'title'    => __( 'Video', 'peptide-core' ),
				'icon'     => 'video-alt3',
				'settings' => array_merge(
					self::heading(),
					array(
						self::select(
							'layout',
							__( 'Video position', 'peptide-core' ),
							'below',
							array(
								'below' => __( 'Below the text', 'peptide-core' ),
								'above' => __( 'Above the text', 'peptide-core' ),
								'left'  => __( 'Left of the text', 'peptide-core' ),
								'right' => __( 'Right of the text', 'peptide-core' ),
							)
						),
						self::full_width(),
						self::select(
							'ratio',
							__( 'Video ratio', 'peptide-core' ),
							'16:9',
							array(
								'16:9' => '16:9',
								'4:3'  => '4:3',
								'1:1'  => '1:1',
								'9:16' => '9:16',
								'21:9' => '21:9',
							)
						),
					),
					self::video_fields()
				),
			),

			// yy-basic-11 视频图文
			'video_text'       => array(
				'title'    => __( 'Video banner', 'peptide-core' ),
				'icon'     => 'format-video',
				'settings' => array_merge(
					self::heading(),
					array(
						self::number( 'titleSizePx', __( 'Title size (px)', 'peptide-core' ), 40, 16, 120, 'style' ),
						self::select( 'position', __( 'Text position', 'peptide-core' ), 'middle-center', $pos9, 'layout', array( 'device' => true ) ),
						self::number( 'heightPx', __( 'Height (px)', 'peptide-core' ), array( 'desktop' => 600, 'mobile' => 420 ), 200, 1200, 'layout', array( 'device' => true ) ),
						self::number( 'overlayOpacity', __( 'Overlay opacity (%)', 'peptide-core' ), 20, 0, 90, 'style' ),
						self::color( 'textColor', __( 'Text color', 'peptide-core' ), '#ffffff' ),
						Fields::f( 'link', 'button', __( 'Button', 'peptide-core' ), null, array( 'group' => 'button' ) ),
					),
					self::video_fields()
				),
			),

			// yy-basic-12 slogan
			'slogan'           => array(
				'title'    => __( 'Highlights', 'peptide-core' ),
				'icon'     => 'megaphone',
				'settings' => array_merge(
					self::heading(),
					array(
						self::number( 'iconWidthPx', __( 'Icon width (px)', 'peptide-core' ), 30, 16, 120 ),
						self::number( 'titleSizePx', __( 'Item title size (px)', 'peptide-core' ), 16, 12, 48, 'style' ),
						self::toggle( 'mobileCarousel', __( 'Swipe on mobile', 'peptide-core' ), true ),
					)
				),
				'items'    => self::items(
					__( 'Highlights', 'peptide-core' ),
					array(
						self::image( 'icon', __( 'Icon image', 'peptide-core' ) ),
						Fields::f( 'text', 'title', __( 'Title', 'peptide-core' ), '' ),
						Fields::f( 'textarea', 'text', __( 'Text', 'peptide-core' ), '' ),
					),
					3,
					1,
					12
				),
			),

			// yy-basic-13 数字增长
			'counter'          => array(
				'title'    => __( 'Counting numbers', 'peptide-core' ),
				'icon'     => 'chart-bar',
				'settings' => array_merge(
					self::heading(),
					array(
						self::number( 'numberSizePx', __( 'Number size (px)', 'peptide-core' ), 42, 16, 120, 'style' ),
						self::columns( 3, 1 ),
					)
				),
				'items'    => self::items(
					__( 'Numbers', 'peptide-core' ),
					array(
						Fields::f( 'icon', 'icon', __( 'Icon', 'peptide-core' ), '' ),
						self::image( 'image', __( 'Or an image (48 × 48)', 'peptide-core' ) ),
						Fields::f( 'number', 'number', __( 'Number', 'peptide-core' ), 0, array( 'min' => 0, 'max' => 999999999 ) ),
						Fields::f( 'text', 'unit', __( 'Unit', 'peptide-core' ), '' ),
						Fields::f( 'text', 'label', __( 'Text', 'peptide-core' ), '' ),
					),
					3,
					1,
					12,
					'label'
				),
			),

			// yy-basic-14 画廊
			'gallery'          => array(
				'title'    => __( 'Gallery', 'peptide-core' ),
				'icon'     => 'format-gallery',
				'settings' => array_merge(
					self::heading(),
					array(
						self::full_width(),
						self::columns( 3, 2 ),
					),
					self::button()
				),
				'items'    => self::items(
					__( 'Pictures', 'peptide-core' ),
					array(
						self::image( 'image', __( 'Image', 'peptide-core' ), __( 'At least 1000px wide; height follows the image.', 'peptide-core' ) ),
						Fields::f( 'text', 'caption', __( 'Short description', 'peptide-core' ), '' ),
						self::link_url(),
					),
					6,
					1,
					36,
					'caption'
				),
			),

			// yy-basic-15 自定义
			'custom_html'      => array(
				'title'    => __( 'Custom HTML', 'peptide-core' ),
				'icon'     => 'editor-code',
				'settings' => array(
					Fields::f( 'text', 'title', __( 'Title', 'peptide-core' ), '' ),
					self::full_width(),
					Fields::f( 'code', 'html', __( 'HTML', 'peptide-core' ), '', array( 'help' => __( 'Scripts only run when the last editor of this design is an administrator. Do not paste code you do not trust.', 'peptide-core' ) ) ),
				),
			),

			// yy-basic-16 时间轴
			'timeline'         => array(
				'title'    => __( 'Timeline', 'peptide-core' ),
				'icon'     => 'clock',
				'settings' => array_merge(
					self::heading(),
					array(
						self::select(
							'style',
							__( 'Timeline style', 'peptide-core' ),
							'horizontal',
							array(
								'horizontal' => __( 'Horizontal line', 'peptide-core' ),
								'vertical'   => __( 'Vertical with images', 'peptide-core' ),
							)
						),
						self::toggle( 'autoScroll', __( 'Auto scroll (horizontal style, desktop)', 'peptide-core' ), false ),
						self::toggle( 'collapsible', __( 'Collapse long timelines (vertical style)', 'peptide-core' ), false ),
						Fields::f( 'text', 'expandLabel', __( 'Expand button text', 'peptide-core' ), 'Show more', array( 'group' => 'layout' ) ),
						Fields::f( 'text', 'collapseLabel', __( 'Collapse button text', 'peptide-core' ), 'Show less', array( 'group' => 'layout' ) ),
						self::color( 'titleColor', __( 'Title color', 'peptide-core' ) ),
						self::color( 'textColor', __( 'Text color', 'peptide-core' ) ),
					)
				),
				'items'    => self::items(
					__( 'Events', 'peptide-core' ),
					array(
						Fields::f( 'text', 'date', __( 'Date', 'peptide-core' ), '' ),
						Fields::f( 'text', 'title', __( 'Title', 'peptide-core' ), '' ),
						Fields::f( 'textarea', 'text', __( 'Short description', 'peptide-core' ), '' ),
						self::image( 'image', __( 'Image (vertical style)', 'peptide-core' ) ),
						self::select(
							'imagePosition',
							__( 'Image position', 'peptide-core' ),
							'left',
							array(
								'left'  => __( 'Left', 'peptide-core' ),
								'right' => __( 'Right', 'peptide-core' ),
							),
							'content'
						),
						Fields::f( 'color', 'color', __( 'Accent color', 'peptide-core' ), '' ),
					),
					3,
					1,
					30
				),
			),

			// yy-basic-17 联系我们（只展示联系方式，没有提交表单）
			'contact'          => array(
				'title'    => __( 'Contact details', 'peptide-core' ),
				'icon'     => 'email-alt',
				'settings' => array_merge(
					self::heading(),
					array(
						self::full_width(),
						self::media( 'media', __( 'Background image', 'peptide-core' ), __( 'Desktop 2400 × 656, mobile 1242 × 1138. Without an image the background color is used.', 'peptide-core' ) ),
						self::number( 'overlayOpacity', __( 'Overlay opacity (%)', 'peptide-core' ), 50, 0, 90, 'style' ),
						self::number( 'iconSizePx', __( 'Icon size (px)', 'peptide-core' ), 24, 12, 64, 'style' ),
						self::color( 'bgColor', __( 'Background color', 'peptide-core' ) ),
						self::color( 'accentColor', __( 'Accent color', 'peptide-core' ) ),
					)
				),
				'items'    => self::items(
					__( 'Contact methods', 'peptide-core' ),
					array(
						Fields::f( 'icon', 'icon', __( 'Icon', 'peptide-core' ), 'mail' ),
						self::image( 'image', __( 'Or an icon image (48 × 48)', 'peptide-core' ) ),
						Fields::f( 'text', 'title', __( 'Title', 'peptide-core' ), '' ),
						Fields::f( 'text', 'text', __( 'Content', 'peptide-core' ), '' ),
						self::link_url(),
					),
					3,
					1,
					12
				),
			),

			// yy-basic-18 推广格子
			'promo_grid'       => array(
				'title'    => __( 'Promo grid', 'peptide-core' ),
				'icon'     => 'tag',
				'settings' => array(
					Fields::f( 'text', 'leftTitle', __( 'Left title', 'peptide-core' ), '' ),
					self::toggle( 'autoplay', __( 'Autoplay', 'peptide-core' ), true, 'play' ),
					self::number( 'intervalMs', __( 'Time per slide (ms)', 'peptide-core' ), 3000, 1000, 20000, 'play', array( 'step' => 500 ) ),
					self::toggle( 'dots', __( 'Show dots', 'peptide-core' ), true, 'play' ),
					Fields::f( 'text', 'rightTitle', __( 'Right title', 'peptide-core' ), '' ),
					Fields::f( 'category', 'category1', __( 'Promoted category 1', 'peptide-core' ), null ),
					Fields::f( 'category', 'category2', __( 'Promoted category 2', 'peptide-core' ), null ),
				),
				'items'    => self::items(
					__( 'Left slides', 'peptide-core' ),
					array(
						self::image( 'image', __( 'Image', 'peptide-core' ), __( 'At least 1000px wide.', 'peptide-core' ) ),
						self::link_url(),
					),
					2,
					1,
					10,
					''
				),
			),

			// yy-basic-19 滚动文本
			'marquee'          => array(
				'title'    => __( 'Scrolling text', 'peptide-core' ),
				'icon'     => 'editor-insertmore',
				'settings' => array(
					self::number( 'heightPx', __( 'Height (px)', 'peptide-core' ), 64, 24, 300 ),
					self::number( 'fontSizePx', __( 'Text size (px)', 'peptide-core' ), 20, 10, 80, 'style' ),
					self::select(
						'direction',
						__( 'Direction', 'peptide-core' ),
						'left',
						array(
							'left'  => __( 'Right to left', 'peptide-core' ),
							'right' => __( 'Left to right', 'peptide-core' ),
						),
						'play'
					),
					self::number( 'durationS', __( 'Seconds per loop', 'peptide-core' ), 40, 5, 200, 'play' ),
					self::toggle( 'pauseOnHover', __( 'Pause on hover', 'peptide-core' ), true, 'play' ),
					self::color( 'bgColor', __( 'Background color', 'peptide-core' ) ),
					self::color( 'textColor', __( 'Text color', 'peptide-core' ) ),
				),
				'items'    => self::items(
					__( 'Texts', 'peptide-core' ),
					array( Fields::f( 'text', 'text', __( 'Text', 'peptide-core' ), '' ) ),
					3,
					1,
					20,
					'text'
				),
			),

			// yy-basic-20 空白积木块
			'spacer'           => array(
				'title'    => __( 'Spacer', 'peptide-core' ),
				'icon'     => 'image-flip-vertical',
				'settings' => array(
					self::number( 'heightPx', __( 'Height (px)', 'peptide-core' ), array( 'desktop' => 40, 'mobile' => 24 ), 0, 400, 'layout', array( 'device' => true ) ),
					self::color( 'color', __( 'Color', 'peptide-core' ) ),
				),
			),

			// yy-basic-21 富文本
			'rich_text'        => array(
				'title'    => __( 'Rich text', 'peptide-core' ),
				'icon'     => 'editor-paragraph',
				'settings' => array_merge(
					array(
						Fields::f( 'text', 'title', __( 'Title', 'peptide-core' ), '' ),
						Fields::f( 'html', 'bodyHtml', __( 'Text', 'peptide-core' ), '' ),
						self::select( 'textAlign', __( 'Text alignment', 'peptide-core' ), 'left', $align ),
						self::number( 'contentWidthPx', __( 'Content width (px)', 'peptide-core' ), 800, 320, 1600 ),
						self::number( 'innerPaddingPx', __( 'Inner padding (px)', 'peptide-core' ), array( 'desktop' => 32, 'mobile' => 20 ), 0, 120, 'layout', array( 'device' => true ) ),
						self::number( 'radiusPx', __( 'Corner radius (px)', 'peptide-core' ), 0, 0, 48, 'style' ),
						self::color( 'contentBgColor', __( 'Content background', 'peptide-core' ) ),
						self::color( 'textColor', __( 'Text color', 'peptide-core' ) ),
					),
					self::button()
				),
			),

			// yy-basic-22 对比图
			'compare'          => array(
				'title'    => __( 'Before / after', 'peptide-core' ),
				'icon'     => 'image-flip-horizontal',
				'settings' => array_merge(
					self::heading(),
					array(
						self::image( 'before', __( 'Before image', 'peptide-core' ), __( 'Recommended 1000 × 500.', 'peptide-core' ), 'media' ),
						self::image( 'after', __( 'After image', 'peptide-core' ), __( 'Recommended 1000 × 500, same size as the before image.', 'peptide-core' ), 'media' ),
						Fields::f( 'text', 'beforeLabel', __( 'Before label', 'peptide-core' ), 'Before', array( 'group' => 'media' ) ),
						Fields::f( 'text', 'afterLabel', __( 'After label', 'peptide-core' ), 'After', array( 'group' => 'media' ) ),
						self::number( 'startPercent', __( 'Divider start position (%)', 'peptide-core' ), 50, 0, 100 ),
						self::color( 'beforeTextColor', __( 'Before label color', 'peptide-core' ) ),
						self::color( 'afterTextColor', __( 'After label color', 'peptide-core' ) ),
					)
				),
			),

			// yy-basic-23 图片热点
			'hotspots'         => array(
				'title'    => __( 'Image hotspots', 'peptide-core' ),
				'icon'     => 'location',
				'settings' => array_merge(
					self::heading(),
					array(
						self::media( 'media', '', __( 'Desktop 1920 × 600, mobile 1242 × 900.', 'peptide-core' ) ),
						self::color( 'overlayColor', __( 'Overlay color', 'peptide-core' ) ),
						self::number( 'iconWidthPx', __( 'Hotspot icon width (px)', 'peptide-core' ), 32, 16, 96, 'style' ),
					)
				),
				'items'    => self::items(
					__( 'Hotspots', 'peptide-core' ),
					array(
						self::number( 'x', __( 'Horizontal position (%)', 'peptide-core' ), array( 'desktop' => 50, 'mobile' => 50 ), 0, 100, 'content', array( 'device' => true ) ),
						self::number( 'y', __( 'Vertical position (%)', 'peptide-core' ), array( 'desktop' => 50, 'mobile' => 50 ), 0, 100, 'content', array( 'device' => true ) ),
						self::image( 'icon', __( 'Hotspot icon (200 × 200)', 'peptide-core' ) ),
						Fields::f( 'text', 'title', __( 'Title', 'peptide-core' ), '' ),
						Fields::f( 'textarea', 'text', __( 'Short description', 'peptide-core' ), '' ),
						self::select(
							'popup',
							__( 'Popup position', 'peptide-core' ),
							'top',
							array(
								'top'    => __( 'Above', 'peptide-core' ),
								'right'  => __( 'Right', 'peptide-core' ),
								'bottom' => __( 'Below', 'peptide-core' ),
								'left'   => __( 'Left', 'peptide-core' ),
							),
							'content'
						),
					),
					2,
					1,
					12
				),
			),

			// yy-basic-24 FAQ
			'faq'              => array(
				'title'    => __( 'FAQ', 'peptide-core' ),
				'icon'     => 'editor-help',
				'settings' => array_merge(
					array( Fields::f( 'text', 'eyebrow', __( 'Small heading', 'peptide-core' ), '' ) ),
					self::heading(),
					array(
						Fields::f( 'text', 'titleAccent', __( 'Title (second line, emphasized)', 'peptide-core' ), '' ),
						Fields::f( 'link', 'link', __( 'Text link (under the title)', 'peptide-core' ) ),
						self::select(
							'layout',
							__( 'Layout', 'peptide-core' ),
							'side',
							array(
								'side'    => __( 'Title on the left, questions on the right', 'peptide-core' ),
								'stacked' => __( 'Title above the questions', 'peptide-core' ),
							)
						),
						self::toggle( 'openFirst', __( 'Open the first question', 'peptide-core' ), true ),
						self::color( 'answerBgColor', __( 'Answer background', 'peptide-core' ) ),
						self::color( 'answerTextColor', __( 'Answer text color', 'peptide-core' ) ),
					)
				),
				'items'    => self::items(
					__( 'Questions', 'peptide-core' ),
					array(
						Fields::f( 'text', 'question', __( 'Question', 'peptide-core' ), '' ),
						Fields::f( 'html', 'answerHtml', __( 'Answer', 'peptide-core' ), '' ),
					),
					4,
					1,
					40,
					'question'
				),
			),

			// yy-basic-25 产品展示（场景图 + 商品热点）
			'product_showcase' => array(
				'title'    => __( 'Shop the look', 'peptide-core' ),
				'icon'     => 'cart',
				'settings' => array(
					self::toggle( 'autoplay', __( 'Autoplay', 'peptide-core' ), true, 'play' ),
					self::number( 'intervalMs', __( 'Time per slide (ms)', 'peptide-core' ), 3000, 1000, 20000, 'play', array( 'step' => 500 ) ),
					self::toggle( 'hoverPreview', __( 'Preview product on hover', 'peptide-core' ), true, 'play' ),
				),
				'items'    => self::items(
					__( 'Scenes', 'peptide-core' ),
					array(
						self::media( 'media', __( 'Background image', 'peptide-core' ), __( 'At least 1000px wide.', 'peptide-core' ) ),
						Fields::f( 'color', 'overlayColor', __( 'Overlay color', 'peptide-core' ), '#000000' ),
						Fields::f( 'number', 'overlayOpacity', __( 'Overlay opacity (%)', 'peptide-core' ), 10, array( 'min' => 0, 'max' => 90 ) ),
						Fields::f(
							'repeater',
							'spots',
							__( 'Product hotspots', 'peptide-core' ),
							null,
							array(
								'max'    => 3,
								'fields' => array(
									Fields::f( 'product', 'product', __( 'Product', 'peptide-core' ), null ),
									Fields::f( 'toggle', 'open', __( 'Show by default', 'peptide-core' ), false ),
									Fields::f( 'number', 'x', __( 'Horizontal position (%)', 'peptide-core' ), array( 'desktop' => 50, 'mobile' => 50 ), array( 'device' => true, 'min' => 0, 'max' => 100 ) ),
									Fields::f( 'number', 'y', __( 'Vertical position (%)', 'peptide-core' ), array( 'desktop' => 50, 'mobile' => 50 ), array( 'device' => true, 'min' => 0, 'max' => 100 ) ),
									Fields::f( 'text', 'buttonLabel', __( 'Button text', 'peptide-core' ), 'View product' ),
								),
							)
						),
					),
					1,
					1,
					8,
					''
				),
			),

			// yy-basic-26 首页 H1 媒体
			'hero_h1'          => array(
				'title'       => __( 'Homepage headline (H1)', 'peptide-core' ),
				'icon'        => 'heading',
				'description' => __( 'The page\'s main heading for search engines. Use only one, before other blocks.', 'peptide-core' ),
				'settings'    => array_merge(
					array(
						Fields::f( 'text', 'title', __( 'Headline (H1)', 'peptide-core' ), '' ),
						Fields::f( 'textarea', 'description', __( 'Short description', 'peptide-core' ), '' ),
						self::select( 'textAlign', __( 'Text alignment', 'peptide-core' ), 'left', $align ),
						self::full_width(),
						self::toggle( 'showMedia', __( 'Show image', 'peptide-core' ), true, 'media' ),
						self::media( 'media', '', __( 'At least 1000px wide; height follows the image.', 'peptide-core' ) ),
						self::select(
							'mediaPosition',
							__( 'Image position', 'peptide-core' ),
							'right',
							array(
								'left'   => __( 'Left', 'peptide-core' ),
								'right'  => __( 'Right', 'peptide-core' ),
								'top'    => __( 'Above the text', 'peptide-core' ),
								'bottom' => __( 'Below the text', 'peptide-core' ),
							),
							'media',
							array( 'device' => true, 'default' => array( 'desktop' => 'right', 'mobile' => 'top' ) )
						),
						self::color( 'titleColor', __( 'Title color', 'peptide-core' ) ),
						self::color( 'textColor', __( 'Text color', 'peptide-core' ) ),
						self::color( 'contentBgColor', __( 'Text background', 'peptide-core' ) ),
					),
					self::button()
				),
			),

			// yy-basic-27 信息列表
			'info_list'        => array(
				'title'    => __( 'Info strip', 'peptide-core' ),
				'icon'     => 'list-view',
				'settings' => array(
					self::toggle( 'mobileSwipe', __( 'Swipe on mobile', 'peptide-core' ), true ),
					self::select( 'align', __( 'Alignment', 'peptide-core' ), 'center', $align ),
				),
				'items'    => self::items(
					__( 'Items', 'peptide-core' ),
					array(
						Fields::f( 'icon', 'icon', __( 'Icon', 'peptide-core' ), 'check' ),
						self::image( 'image', __( 'Or an image (40px or wider)', 'peptide-core' ) ),
						Fields::f( 'text', 'text', __( 'Short description', 'peptide-core' ), '' ),
					),
					4,
					1,
					12,
					'text'
				),
			),

			// yy-basic-28 通用导航栏
			'nav_bar'          => array(
				'title'    => __( 'Navigation bar', 'peptide-core' ),
				'icon'     => 'menu',
				'settings' => array(
					Fields::f( 'menu', 'menu', __( 'Menu', 'peptide-core' ), null, array( 'help' => __( 'Menus are managed under Store design → Menus & navigation (for now: Appearance → Menus).', 'peptide-core' ) ) ),
					self::full_width(),
					self::select( 'align', __( 'Alignment (desktop)', 'peptide-core' ), 'center', $align ),
					self::number( 'innerPaddingPx', __( 'Inner padding (px)', 'peptide-core' ), 12, 0, 80 ),
					self::toggle( 'bold', __( 'Bold text', 'peptide-core' ), false, 'style' ),
					self::number( 'fontSizePx', __( 'Text size (px)', 'peptide-core' ), 16, 10, 40, 'style' ),
					self::toggle( 'border', __( 'Show border', 'peptide-core' ), false, 'style' ),
					self::color( 'textColor', __( 'Text color', 'peptide-core' ) ),
					self::color( 'bgColor', __( 'Background color', 'peptide-core' ) ),
					self::color( 'borderColor', __( 'Border color', 'peptide-core' ) ),
				),
			),

			// yy-basic-29 商标列表
			'logo_list'        => array(
				'title'    => __( 'Logo list', 'peptide-core' ),
				'icon'     => 'awards',
				'settings' => array_merge(
					self::heading(),
					array(
						self::select(
							'mode',
							__( 'Display', 'peptide-core' ),
							'grid',
							array(
								'grid'   => __( 'Grid', 'peptide-core' ),
								'scroll' => __( 'Scrolling row', 'peptide-core' ),
							)
						),
						self::columns( 6, 2, 8 ),
					)
				),
				'items'    => self::items(
					__( 'Logos', 'peptide-core' ),
					array(
						self::image( 'image', __( 'Logo (400 × 200)', 'peptide-core' ) ),
						self::link_url(),
					),
					6,
					1,
					40,
					''
				),
			),

			// VialCove 首页：商品区（读 WooCommerce 商品；不保存价格副本）。参考 YY 商品块“专辑商品”。
			'product_collection' => array(
				'title'       => __( 'Product collection', 'peptide-core' ),
				'category'    => 'product',
				'icon'        => 'products',
				'description' => __( 'Products from your catalog. Prices, stock and images always come from WooCommerce.', 'peptide-core' ),
				'settings'    => array(
					Fields::f( 'text', 'eyebrow', __( 'Small heading', 'peptide-core' ), '' ),
					Fields::f( 'text', 'title', __( 'Title', 'peptide-core' ), '' ),
					Fields::f( 'textarea', 'description', __( 'Short description', 'peptide-core' ), '' ),
					Fields::f( 'link', 'link', __( 'Text link (e.g. "View all")', 'peptide-core' ) ),
					self::select(
						'source',
						__( 'Products to show', 'peptide-core' ),
						'catalog',
						array(
							'catalog'  => __( 'Whole catalog', 'peptide-core' ),
							'category' => __( 'One category', 'peptide-core' ),
							'manual'   => __( 'Choose products', 'peptide-core' ),
						),
						'content'
					),
					Fields::f( 'category', 'category', __( 'Category', 'peptide-core' ), null, array( 'show_if' => array( 'source' => 'category' ) ) ),
					Fields::f(
						'repeater',
						'products',
						__( 'Products', 'peptide-core' ),
						null,
						array(
							'max'     => 24,
							'show_if' => array( 'source' => 'manual' ),
							'fields'  => array( Fields::f( 'product', 'product', __( 'Product', 'peptide-core' ), null ) ),
						)
					),
					self::select(
						'sort',
						__( 'Sort order', 'peptide-core' ),
						'catalog',
						array(
							'catalog'    => __( 'Catalog order (as in the price list)', 'peptide-core' ),
							'newest'     => __( 'Newest first', 'peptide-core' ),
							'title'      => __( 'Name A–Z', 'peptide-core' ),
							'price_asc'  => __( 'Price: low to high', 'peptide-core' ),
							'price_desc' => __( 'Price: high to low', 'peptide-core' ),
						),
						'content'
					),
					self::number( 'limit', __( 'Number of products', 'peptide-core' ), 4, 1, 24, 'content' ),
					self::columns( 4, 2 ),
					self::toggle( 'reportPreview', __( 'Show report preview on hover', 'peptide-core' ), true ),
					Fields::f( 'text', 'emptyText', __( 'Text when there are no products', 'peptide-core' ), 'The collection is being prepared. Please check back soon.', array( 'group' => 'layout' ) ),
				),
			),

			// VialCove 首页：批次报告介绍（只是展示；批次查询和报告权限仍由批次报告功能提供）。
			'report_feature'   => array(
				'title'       => __( 'Batch report highlight', 'peptide-core' ),
				'icon'        => 'media-document',
				'description' => __( 'Introduces batch lookup with a sample report card. It does not show real report data.', 'peptide-core' ),
				'settings'    => array(
					Fields::f( 'text', 'eyebrow', __( 'Small heading', 'peptide-core' ), '' ),
					Fields::f( 'text', 'title', __( 'Title', 'peptide-core' ), '' ),
					Fields::f( 'text', 'titleAccent', __( 'Title (second line, emphasized)', 'peptide-core' ), '' ),
					Fields::f( 'textarea', 'description', __( 'Short description', 'peptide-core' ), '' ),
					Fields::f( 'link', 'button', __( 'Button', 'peptide-core' ) ),
					Fields::f( 'text', 'cardEyebrow', __( 'Card: small heading', 'peptide-core' ), '', array( 'group' => 'media' ) ),
					Fields::f( 'text', 'cardTitle', __( 'Card: title', 'peptide-core' ), '', array( 'group' => 'media' ) ),
					Fields::f( 'text', 'cardNote', __( 'Card: bottom line', 'peptide-core' ), '', array( 'group' => 'media' ) ),
					self::color( 'bgColor', __( 'Background color', 'peptide-core' ) ),
					self::color( 'textColor', __( 'Text color', 'peptide-core' ) ),
				),
				'items'       => self::items(
					__( 'Card rows', 'peptide-core' ),
					array(
						Fields::f( 'text', 'label', __( 'Label', 'peptide-core' ), '' ),
						Fields::f( 'text', 'value', __( 'Value', 'peptide-core' ), '' ),
					),
					3,
					0,
					6,
					'label'
				),
			),

			// VialCove 首页：结尾引导。
			'cta'              => array(
				'title'    => __( 'Call to action', 'peptide-core' ),
				'icon'     => 'megaphone',
				'settings' => array(
					Fields::f( 'text', 'eyebrow', __( 'Small heading', 'peptide-core' ), '' ),
					Fields::f( 'text', 'title', __( 'Title', 'peptide-core' ), '' ),
					Fields::f( 'textarea', 'description', __( 'Short description', 'peptide-core' ), '' ),
					Fields::f( 'link', 'button', __( 'Button', 'peptide-core' ) ),
					self::select( 'align', __( 'Alignment', 'peptide-core' ), 'center', $align ),
					self::color( 'bgColor', __( 'Background color', 'peptide-core' ) ),
					self::color( 'textColor', __( 'Text color', 'peptide-core' ) ),
				),
			),

			// yy-basic-31 文本区块
			'text_block'       => array(
				'title'    => __( 'Text with "read more"', 'peptide-core' ),
				'icon'     => 'text',
				'settings' => array(
					Fields::f( 'text', 'title', __( 'Title', 'peptide-core' ), '' ),
					Fields::f( 'html', 'bodyHtml', __( 'Content', 'peptide-core' ), '' ),
					Fields::f( 'text', 'expandLabel', __( 'Expand button text', 'peptide-core' ), 'Read more', array( 'group' => 'layout' ) ),
					Fields::f( 'text', 'collapseLabel', __( 'Collapse button text', 'peptide-core' ), 'Show less', array( 'group' => 'layout' ) ),
					self::number( 'collapsedHeightPx', __( 'Height when collapsed (px, 0 = never collapse)', 'peptide-core' ), 160, 0, 1000 ),
					self::color( 'textColor', __( 'Text color', 'peptide-core' ) ),
					self::color( 'linkColor', __( 'Link color', 'peptide-core' ) ),
					self::color( 'buttonColor', __( 'Button color', 'peptide-core' ) ),
				),
			),
		);
	}

	/**
	 * 多图 3 / 4 / 5：固定张数，每张有图片、链接和按钮文字。
	 *
	 * @param array<string,string> $layouts
	 * @return array<string,mixed>
	 */
	private static function mosaic( int $count, string $title, array $layouts ): array {
		return array(
			'title'    => $title,
			'icon'     => 'layout',
			'settings' => array_merge(
				self::heading(),
				array(
					self::select( 'layout', __( 'Layout', 'peptide-core' ), (string) array_key_first( $layouts ), $layouts ),
					self::number( 'gapPx', __( 'Gap (px)', 'peptide-core' ), 16, 0, 80 ),
				)
			),
			'items'    => self::items(
				__( 'Images', 'peptide-core' ),
				array(
					self::media(),
					self::link_url(),
					Fields::f( 'text', 'buttonLabel', __( 'Button text', 'peptide-core' ), '' ),
				),
				$count,
				$count,
				$count,
				'buttonLabel'
			),
		);
	}
}
