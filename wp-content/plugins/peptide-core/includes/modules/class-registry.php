<?php
/**
 * 装修模块库（EDIT-BLOCKS-03）：基础积木与店铺模块。
 * 捐款 / 小费仅提供真实统计展示（服务端数据提供方待接）；收款功能仍暂缓。
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
			foreach ( (array) apply_filters( 'peptide_core_layout_definitions', self::definitions() ) as $type => $def ) {
				$def['type']     = $type;
				$def['block']    = 'vialcove/' . str_replace( '_', '-', $type );
				$def['version']  = $def['version'] ?? 1;
				$def['category'] = $def['category'] ?? 'basic';
				$def['settings'] = array_merge( $def['settings'], self::general_fields() );
				// Global styles emit no public module wrapper, so these spacer controls have no target.
				if ( 'site_styles' === $type ) {
					foreach ( $def['settings'] as &$field ) {
						if ( in_array( $field['key'], array( 'paddingTopPx', 'paddingBottomPx' ), true ) ) { $field['uiHidden'] = true; }
					}
					unset( $field );
				}
				if ( 'basic' === $def['category'] && ! in_array( $type, array( 'site_header', 'site_footer', 'site_styles', 'context_content' ), true ) ) {
					$def['settings'] = self::control_metadata( $def['settings'] );
					if ( ! empty( $def['items']['fields'] ) ) { $def['items']['fields'] = self::control_metadata( $def['items']['fields'] ); }
					$def = self::reference_controls( $type, $def );
					$def = self::reference_content_controls( $type, $def );
				}
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

	/** Presentation metadata only: persisted units, defaults and bounds stay unchanged. */
	private static function control_metadata( array $fields ): array {
		foreach ( $fields as &$field ) {
			if ( 'number' === $field['type'] ) {
				if ( str_ends_with( $field['key'], 'Ms' ) ) {
					$field['ui'] = 'seconds';
					$field['unit'] = 's';
					$field['label'] = trim( preg_replace( '/\s*[(（](?:ms|毫秒)[)）]/u', '', $field['label'] ) );
				} elseif ( str_ends_with( $field['key'], 'Px' ) ) {
					$field['unit'] = 'px';
					if ( null !== ( $field['default'] ?? null ) ) { $field['ui'] = 'slider'; }
				} elseif ( in_array( $field['key'], array( 'overlayOpacity', 'startPercent', 'x', 'y' ), true ) ) {
					$field['ui'] = 'slider'; $field['unit'] = '%';
				} elseif ( 'columns' === $field['key'] ) {
					$field['ui'] = 'slider';
				} elseif ( 'durationS' === $field['key'] ) {
					$field['ui'] = 'slider'; $field['unit'] = 's';
				}
			}
			if ( ! empty( $field['fields'] ) ) { $field['fields'] = self::control_metadata( $field['fields'] ); }
		}
		unset( $field );
		return $fields;
	}

	/** Organize observed basic controls without rewriting saved module values. */
	private static function reference_controls( string $type, array $def ): array {
		$observed = array( 'carousel', 'image', 'image_row', 'image_grid', 'mosaic_3', 'mosaic_4', 'mosaic_5' );
		if ( ! in_array( $type, $observed, true ) ) { return $def; }
		$general = array( 'id' => 'general', 'label' => '显示与间距', 'fields' => array( 'showDesktop', 'showMobile', 'paddingTopPx', 'paddingBottomPx', 'backgroundColor' ) );
		foreach ( $def['settings'] as &$field ) {
			if ( 'intervalMs' === $field['key'] ) { $field['uiMax'] = 5000; }
			if ( 'titleSizePx' === $field['key'] && 'carousel' === $type ) { $field['label'] = '标题字号上限'; $field['help'] = '字体随可用宽度缩放，此值控制最大字号。'; }
			if ( 'columns' === $field['key'] && 'image_grid' === $type ) { $field['max'] = 8; $field['uiMax'] = array( 'desktop' => 8, 'mobile' => 4 ); $field['deviceUI'] = 'paired'; }
			if ( 'gapPx' === $field['key'] && in_array( $type, array( 'image_row', 'image_grid' ), true ) ) { $field['max'] = 200; }
			if ( 'overlayOpacity' === $field['key'] && 'image' === $type ) { $field['max'] = 100; }
			if ( 'mobileStyle' === $field['key'] && 'image_row' === $type ) { $field['options']['inline'] = '横向平铺'; $field['options']['stack'] = '竖向平铺'; }
			if ( 'description' === $field['key'] && 'carousel' !== $type ) { $field['uiHidden'] = true; }
		}
		unset( $field );
		if ( 'carousel' !== $type ) {
			$def['settings'][] = Fields::f( 'html', 'descriptionHtml', '简短描述', null, array( 'ui' => 'richtext', 'legacyKey' => 'description' ) );
		}
		if ( 'carousel' === $type ) {
			$def['panels'] = array(
				array( 'id' => 'layout', 'label' => '轮播设置', 'initialOpen' => true, 'fields' => array( 'autoplay', 'pagination', 'intervalMs', 'height', 'heightPx', 'fullWidth', 'titleSizePx' ) ),
				array( 'id' => 'advanced', 'label' => '更多轮播选项', 'fields' => array( 'arrows' ) ),
				$general,
			);
			$def['items']['panels'] = array(
				array( 'id' => 'slide', 'label' => '图片与文字', 'initialOpen' => true, 'fields' => array( 'media', 'position', 'eyebrow', 'title', 'button' ) ),
				array( 'id' => 'colors', 'label' => '蒙层与颜色', 'fields' => array( 'overlayColor', 'overlayOpacity', 'textColor', 'buttonTextColor', 'buttonBgColor' ) ),
				array( 'id' => 'advanced', 'label' => '更多内容与布局', 'fields' => array( 'name', 'layout', 'titleAccent', 'description', 'secondaryLink', 'facts', 'cardLayout', 'highlights', 'footnote', 'sceneNumber', 'captionTitle', 'captionNote' ) ),
			);
		} elseif ( 'image' === $type ) {
			$def['panels'] = array(
				array( 'id' => 'image', 'label' => '图片设置', 'initialOpen' => true, 'fields' => array( 'media', 'href', 'height', 'fullWidth' ) ),
				array( 'id' => 'content', 'label' => '标题与描述', 'initialOpen' => true, 'fields' => array( 'title', 'descriptionHtml', 'position' ) ),
				array( 'id' => 'button', 'label' => '按钮与特效', 'fields' => array( 'button', 'buttonEffect', 'effectDurationMs', 'effectIntervalMs', 'buttonTextColor', 'buttonBgColor' ) ),
				array( 'id' => 'style', 'label' => '蒙层与文字颜色', 'fields' => array( 'overlayColor', 'overlayOpacity', 'titleColor', 'textColor' ) ),
				$general,
			);
		} else {
			$def['panels'] = array(
				array( 'id' => 'content', 'label' => '标题与描述', 'initialOpen' => true, 'fields' => array( 'title', 'descriptionHtml' ) ),
				array( 'id' => 'layout', 'label' => '图片排列', 'initialOpen' => true, 'fields' => array( 'fullWidth', 'columns', 'mobileStyle', 'layout', 'gapPx', 'mobileGapPx' ) ),
				$general,
			);
			if ( 'image_row' === $type ) { $def['editorDefaults'] = array( 'gapPx' => 30, 'mobileStyle' => 'stack' ); }
			if ( in_array( $type, array( 'mosaic_3', 'mosaic_4', 'mosaic_5' ), true ) ) {
				$def['panels'][1]['fields'] = array( 'displayShape', 'layout', 'mainTitle' );
				array_splice( $def['panels'], 2, 0, array( array( 'id' => 'advanced', 'label' => '更多间距选项', 'fields' => array( 'gapPx', 'mobileGapPx' ) ) ) );
				$def['settings'][] = Fields::f( 'number', 'mobileGapPx', '手机图片间距', null, array( 'group' => 'layout', 'ui' => 'range', 'unit' => 'px', 'min' => 0, 'max' => 80, 'help' => '留空时沿用电脑间距。' ) );
				$def['editorDefaults'] = array( 'gapPx' => 30, 'mobileGapPx' => 15 );
			}
			if ( 'mosaic_4' === $type ) {
				$def['settings'][] = Fields::f( 'select', 'displayShape', '展示形态', 'mosaic', array( 'group' => 'layout', 'options' => array( 'mosaic' => '一主三辅拼贴', 'poster' => '水平联排拼贴' ) ) );
				$def['settings'][] = Fields::f( 'text', 'mainTitle', '主图标题', '', array( 'group' => 'layout', 'show_if' => array( 'displayShape' => 'poster' ) ) );
			}
		}
		foreach ( $def['settings'] as &$field ) {
			foreach ( $def['panels'] as $panel ) { if ( in_array( $field['key'], $panel['fields'], true ) ) { $field['panel'] = $panel['id']; break; } }
		}
		unset( $field );
		return $def;
	}

	/** Marquee keeps saved pixel heights while new inserts can use viewport height. */
	private static function reference_marquee_controls( array $def ): array {
		foreach ( $def['settings'] as &$field ) {
			if ( 'heightPx' === $field['key'] ) {
				$field['label'] = '高度（像素）'; $field['show_if'] = array( 'heightUnit' => 'px' );
				$field['help'] = '作为最小高度；文字较大或有多行时自动增高，避免裁切。';
			}
			if ( 'fontSizePx' === $field['key'] ) { $field['label'] = '文字大小'; $field['max'] = 100; }
			if ( 'durationS' === $field['key'] ) { $field['label'] = '每轮时长'; $field['min'] = 1; $field['help'] = '完成一轮滚动的时间，数值越小越快。'; }
			if ( 'pauseOnHover' === $field['key'] ) { $field['label'] = '鼠标悬停时暂停'; }
		}
		unset( $field );
		$def['settings'][] = Fields::f( 'select', 'heightUnit', '高度方式', 'px', array( 'group' => 'layout', 'options' => array( 'px' => '固定像素', 'vh' => '视口高度' ) ) );
		$def['settings'][] = Fields::f( 'number', 'heightVh', '高度（视口）', 12, array( 'group' => 'layout', 'ui' => 'slider', 'unit' => 'vh', 'min' => 5, 'max' => 30, 'show_if' => array( 'heightUnit' => 'vh' ), 'help' => '1vh 等于访问者窗口高度的 1%；作为最小高度，内容较高时自动增高。' ) );
		$def['editorDefaults'] = array( 'heightUnit' => 'vh', 'heightVh' => 12, 'bgColor' => '#ffffff', 'textColor' => '#000000' );
		$def['panels'] = array(
			array( 'id' => 'layout', 'label' => '滚动设置', 'initialOpen' => true, 'fields' => array( 'heightUnit', 'heightPx', 'heightVh', 'fontSizePx', 'direction', 'durationS', 'pauseOnHover' ) ),
			array( 'id' => 'style', 'label' => '颜色', 'fields' => array( 'bgColor', 'textColor' ) ),
			array( 'id' => 'general', 'label' => '显示与间距', 'fields' => array( 'showDesktop', 'showMobile', 'paddingTopPx', 'paddingBottomPx', 'backgroundColor' ) ),
		);
		foreach ( $def['items']['fields'] as &$field ) { if ( 'text' === $field['key'] ) { $field['uiHidden'] = true; } }
		unset( $field );
		$def['items']['fields'][] = Fields::f( 'html', 'textHtml', '文本内容', null, array( 'ui' => 'richtext', 'legacyKey' => 'text' ) );
		$def['items']['panels'] = array( array( 'id' => 'content', 'label' => '文本内容', 'initialOpen' => true, 'fields' => array( 'textHtml' ) ) );
		return $def;
	}

	/** Rich text retains its pixel layout while new inserts use responsive percentages. */
	private static function reference_rich_text_controls( array $def ): array {
		foreach ( $def['settings'] as &$field ) {
			if ( 'title' === $field['key'] ) { $field['label'] = '标题'; }
			if ( 'bodyHtml' === $field['key'] ) { $field['label'] = '描述'; $field['ui'] = 'richtext'; $field['richTextMedia'] = 'image'; $field['richMediaBlock'] = 'vialcove/rich-text'; }
			if ( 'textAlign' === $field['key'] ) { $field['label'] = '文字对齐'; }
			if ( 'contentWidthPx' === $field['key'] ) { $field['label'] = '内容宽度（像素）'; $field['show_if'] = array( 'contentWidthMode' => 'pixels' ); }
			if ( 'innerPaddingPx' === $field['key'] ) { $field['label'] = '统一内距'; $field['help'] = '四边留空时沿用此值；窄屏上的左右内距会自适应收窄，保留正文空间。'; }
			if ( 'radiusPx' === $field['key'] ) { $field['label'] = '圆角'; }
			if ( 'contentBgColor' === $field['key'] ) { $field['label'] = '内容背景'; }
			if ( 'textColor' === $field['key'] ) { $field['label'] = '文字颜色'; }
			if ( 'buttonTextColor' === $field['key'] ) { $field['label'] = '按钮文字颜色'; }
			if ( 'buttonBgColor' === $field['key'] ) { $field['label'] = '按钮背景颜色'; }
			if ( 'effectDurationMs' === $field['key'] ) { $field['uiMin'] = 1000; $field['uiMax'] = 5000; }
			if ( 'effectIntervalMs' === $field['key'] ) { $field['uiMin'] = 500; $field['uiMax'] = 5000; }
		}
		unset( $field );
		$def['settings'][] = self::select( 'contentWidthMode', '宽度方式', 'pixels', array( 'pixels' => '固定像素', 'percent' => '容器百分比' ) );
		$def['settings'][] = self::number( 'contentWidthPercent', '内容宽度', array( 'desktop' => 100, 'mobile' => 100 ), 50, 100, 'layout', array( 'device' => true, 'ui' => 'slider', 'unit' => '%', 'show_if' => array( 'contentWidthMode' => 'percent' ), 'help' => '按可用容器宽度设置，电脑和手机可分别调整；手机默认占满容器。' ) );
		$padding = array( 'contentPaddingTopPx' => '上', 'contentPaddingRightPx' => '右', 'contentPaddingBottomPx' => '下', 'contentPaddingLeftPx' => '左' );
		foreach ( $padding as $key => $label ) {
			$def['settings'][] = self::number( $key, $label, null, 0, 120, 'layout', array( 'device' => true, 'ui' => 'input', 'unit' => 'px', 'help' => '留空时沿用统一内距；手机未单独设置时沿用此边的电脑值。左右内距在窄列中自适应收窄，保存值不变。' ) );
		}
		$def['editorDefaults'] = array( 'textAlign' => 'center', 'contentWidthMode' => 'percent', 'contentWidthPercent' => array( 'desktop' => 100, 'mobile' => 100 ), 'textColor' => '#1d1f21', 'buttonTextColor' => '#ffffff', 'buttonBgColor' => '#1d1f21' );
		foreach ( $padding as $key => $label ) { $def['editorDefaults'][ $key ] = array( 'desktop' => 0, 'mobile' => 0 ); }
		$def['panels'] = array(
			array( 'id' => 'content', 'label' => '标题与描述', 'initialOpen' => true, 'fields' => array( 'title', 'bodyHtml' ) ),
			array( 'id' => 'layout', 'label' => '宽度与对齐', 'initialOpen' => true, 'fields' => array( 'textAlign', 'contentWidthMode', 'contentWidthPx', 'contentWidthPercent', 'radiusPx' ) ),
			array( 'id' => 'inner-padding', 'label' => '四边内距', 'ui' => 'boxmodel', 'initialOpen' => true, 'fields' => array_keys( $padding ) ),
			array( 'id' => 'style', 'label' => '文字与背景颜色', 'fields' => array( 'contentBgColor', 'textColor', 'buttonTextColor', 'buttonBgColor' ) ),
			array( 'id' => 'button', 'label' => '按钮与特效', 'fields' => array( 'button', 'buttonEffect', 'effectDurationMs', 'effectIntervalMs' ) ),
			array( 'id' => 'compatibility', 'label' => '统一内距', 'fields' => array( 'innerPaddingPx' ) ),
			array( 'id' => 'general', 'label' => '显示与间距', 'fields' => array( 'showDesktop', 'showMobile', 'paddingTopPx', 'paddingBottomPx', 'backgroundColor' ) ),
		);
		return $def;
	}

	/** New plus markers keep popup media separate from the saved marker icon. */
	private static function reference_hotspots_controls( array $def ): array {
		foreach ( $def['settings'] as &$field ) {
			if ( 'title' === $field['key'] ) { $field['label'] = '标题'; }
			if ( 'description' === $field['key'] ) { $field['uiHidden'] = true; }
			if ( 'media' === $field['key'] ) { $field['label'] = '背景图片'; $field['mobileImageLabel'] = '手机图片（留空沿用电脑图片）'; }
			if ( 'overlayColor' === $field['key'] ) { $field['label'] = '蒙层颜色'; }
			if ( 'iconWidthPx' === $field['key'] ) { $field['label'] = '标准点位图标宽度'; $field['show_if'] = array( 'displayStyle' => 'standard' ); }
		}
		unset( $field );
		$def['settings'][] = Fields::f( 'html', 'descriptionHtml', '描述', null, array( 'ui' => 'richtext', 'legacyKey' => 'description', 'richTextMedia' => 'image', 'richMediaBlock' => 'vialcove/hotspots' ) );
		$def['settings'][] = self::select( 'displayStyle', '点位样式', 'standard', array( 'standard' => '居中点位（自定义图标）', 'plus' => '加号点位（左上角定位）' ), 'layout', array( 'help' => '标准样式以坐标为点位中心；加号样式以左上角定位，极边位置自动留在图片内，保存坐标不变。手机加号内容以对话框打开。' ) );
		$def['editorDefaults'] = array( 'displayStyle' => 'plus', 'overlayColor' => '#0000001a' );
		$def['panels'] = array(
			array( 'id' => 'content', 'label' => '标题与描述', 'initialOpen' => true, 'fields' => array( 'title', 'descriptionHtml' ) ),
			array( 'id' => 'background', 'label' => '背景图片与蒙层', 'initialOpen' => true, 'fields' => array( 'media', 'overlayColor' ) ),
			array( 'id' => 'layout', 'label' => '点位样式', 'initialOpen' => true, 'fields' => array( 'displayStyle', 'iconWidthPx' ) ),
			array( 'id' => 'general', 'label' => '显示与间距', 'fields' => array( 'showDesktop', 'showMobile', 'paddingTopPx', 'paddingBottomPx', 'backgroundColor' ) ),
		);
		foreach ( $def['items']['fields'] as &$field ) {
			if ( in_array( $field['key'], array( 'x', 'y' ), true ) ) { $field['label'] = 'x' === $field['key'] ? '横向位置' : '纵向位置'; $field['help'] = '切换上方电脑/手机，分别调整横纵位置；手机留空时沿用电脑。'; }
			if ( 'title' === $field['key'] ) { $field['label'] = '标题'; }
			if ( 'text' === $field['key'] ) { $field['uiHidden'] = true; }
			if ( 'icon' === $field['key'] ) { $field['label'] = '标准样式点位图标'; $field['help'] = '用于标准样式的点位本身；浮层图片请在“浮层内容”中选择。切换样式不会删除已存图标。'; }
			if ( 'popup' === $field['key'] ) { $field['label'] = '标准样式浮层方向'; }
		}
		unset( $field );
		$def['items']['fields'][] = self::image( 'popupImage', '浮层图片', '图片显示在浮层顶部，保留自然比例；不会替换点位图标。' );
		$def['items']['fields'][] = self::number( 'imageWidthPx', '浮层图片宽度', 32, 20, 100, 'content', array( 'ui' => 'slider', 'unit' => 'px' ) );
		$def['items']['fields'][] = Fields::f( 'html', 'textHtml', '简短描述', null, array( 'ui' => 'richtext', 'legacyKey' => 'text', 'richTextMedia' => 'image', 'richMediaBlock' => 'vialcove/hotspots', 'richMediaItem' => true ) );
		$def['items']['fields'][] = self::select( 'popupSide', '加号样式浮层方向', 'left', array( 'left' => '左侧', 'right' => '右侧' ), 'content', array( 'help' => '电脑端优先在所选方向显示，靠近边缘时自动避让；手机使用居中对话框。' ) );
		$def['items']['panels'] = array(
			array( 'id' => 'position', 'label' => '电脑与手机位置', 'initialOpen' => true, 'fields' => array( 'x', 'y' ) ),
			array( 'id' => 'content', 'label' => '浮层内容', 'initialOpen' => true, 'fields' => array( 'popupImage', 'imageWidthPx', 'title', 'textHtml' ) ),
			array( 'id' => 'side', 'label' => '加号样式方向', 'fields' => array( 'popupSide' ) ),
			array( 'id' => 'standard', 'label' => '标准样式图标与方向', 'fields' => array( 'icon', 'popup' ) ),
		);
		$def['items']['default_items'] = array(
			array( 'x' => array( 'desktop' => 50, 'mobile' => 50 ), 'y' => array( 'desktop' => 50, 'mobile' => 50 ), 'popupSide' => 'left' ),
			array( 'x' => array( 'desktop' => 40, 'mobile' => 20 ), 'y' => array( 'desktop' => 60, 'mobile' => 20 ), 'popupSide' => 'right' ),
		);
		return $def;
	}

	/** Panel FAQ keeps the existing side and stacked layouts and their saved defaults. */
	private static function reference_faq_controls( array $def ): array {
		foreach ( $def['settings'] as &$field ) {
			if ( 'title' === $field['key'] ) { $field['label'] = '标题'; }
			if ( 'description' === $field['key'] ) { $field['uiHidden'] = true; }
			if ( 'layout' === $field['key'] ) { $field['options']['panel'] = '标题在上，整块问答底色'; }
			if ( 'answerBgColor' === $field['key'] ) { $field['label'] = '问答背景颜色'; $field['help'] = '整块问答布局应用于整个问答区；其它布局应用于回答。'; }
			if ( 'answerTextColor' === $field['key'] ) { $field['label'] = '问答文字颜色'; }
		}
		unset( $field );
		$def['settings'][] = Fields::f( 'html', 'descriptionHtml', '描述', null, array( 'ui' => 'richtext', 'legacyKey' => 'description', 'richTextMedia' => 'image', 'richMediaBlock' => 'vialcove/faq' ) );
		$def['editorDefaults'] = array( 'layout' => 'panel', 'openFirst' => false, 'answerBgColor' => '#f6f6f6', 'answerTextColor' => '#1d1f21' );
		$def['panels'] = array(
			array( 'id' => 'content', 'label' => '标题与描述', 'initialOpen' => true, 'fields' => array( 'eyebrow', 'title', 'titleAccent', 'descriptionHtml', 'link' ) ),
			array( 'id' => 'layout', 'label' => '问答布局', 'initialOpen' => true, 'fields' => array( 'layout', 'openFirst', 'answerBgColor', 'answerTextColor' ) ),
			array( 'id' => 'general', 'label' => '显示与间距', 'fields' => array( 'showDesktop', 'showMobile', 'paddingTopPx', 'paddingBottomPx', 'backgroundColor' ) ),
		);
		foreach ( $def['items']['fields'] as &$field ) {
			if ( 'question' === $field['key'] ) { $field['label'] = '问题'; $field['help'] = '整块问答布局中，问题和回答均空的条目不会公开显示；只有回答时显示“View answer”展开动作，只有问题时显示静态文字。'; }
			if ( 'answerHtml' === $field['key'] ) { $field['label'] = '回答'; $field['ui'] = 'richtext'; $field['richTextMedia'] = 'image'; $field['richMediaBlock'] = 'vialcove/faq'; $field['richMediaItem'] = true; }
		}
		unset( $field );
		return $def;
	}

	/** A comparison's saved layout remains available beside the new natural-frame view. */
	private static function reference_compare_controls( array $def ): array {
		foreach ( $def['settings'] as &$field ) {
			if ( 'description' === $field['key'] ) { $field['uiHidden'] = true; }
			if ( 'title' === $field['key'] ) { $field['label'] = '标题'; }
			if ( 'before' === $field['key'] ) { $field['label'] = '前图'; }
			if ( 'after' === $field['key'] ) { $field['label'] = '后图'; }
			if ( 'beforeLabel' === $field['key'] ) { $field['label'] = '前图文字'; }
			if ( 'afterLabel' === $field['key'] ) { $field['label'] = '后图文字'; }
			if ( 'startPercent' === $field['key'] ) { $field['label'] = '初始位置'; $field['help'] = '从图片左侧计算，0% 和 100% 都是有效位置。'; }
			if ( 'beforeTextColor' === $field['key'] ) { $field['label'] = '前图文字颜色'; }
			if ( 'afterTextColor' === $field['key'] ) { $field['label'] = '后图文字颜色'; }
		}
		unset( $field );
		$def['settings'][] = Fields::f( 'html', 'descriptionHtml', '描述', null, array( 'ui' => 'richtext', 'legacyKey' => 'description', 'richTextMedia' => 'image', 'richMediaBlock' => 'vialcove/compare' ) );
		$def['settings'][] = self::select( 'displayStyle', '画幅方式', 'standard', array( 'standard' => '后图比例（最宽1000px）', 'natural' => '前图比例（宽屏80%）' ), 'layout', array( 'help' => '基准图决定画幅高度，另一张图按比例裁切铺满。前图比例在电脑宽屏占可用容器80%，其它屏幕铺满容器。' ) );
		$def['editorDefaults'] = array( 'displayStyle' => 'natural', 'startPercent' => 39, 'beforeTextColor' => '#1a1a1a', 'afterTextColor' => '#1a1a1a' );
		$def['panels'] = array(
			array( 'id' => 'content', 'label' => '标题与描述', 'initialOpen' => true, 'fields' => array( 'title', 'descriptionHtml' ) ),
			array( 'id' => 'before', 'label' => '前图与文字', 'initialOpen' => true, 'fields' => array( 'before', 'beforeLabel', 'beforeTextColor' ) ),
			array( 'id' => 'after', 'label' => '后图与文字', 'initialOpen' => true, 'fields' => array( 'after', 'afterLabel', 'afterTextColor' ) ),
			array( 'id' => 'layout', 'label' => '画幅与分割位置', 'initialOpen' => true, 'fields' => array( 'displayStyle', 'startPercent' ) ),
			array( 'id' => 'general', 'label' => '显示与间距', 'fields' => array( 'showDesktop', 'showMobile', 'paddingTopPx', 'paddingBottomPx', 'backgroundColor' ) ),
		);
		return $def;
	}

	/** Product bindings keep the native Woo source of price, stock, variation and cart data. */
	private static function reference_showcase_controls( array $def ): array {
		foreach ( $def['settings'] as &$field ) {
			if ( 'autoplay' === $field['key'] ) { $field['label'] = '自动轮播'; }
			if ( 'intervalMs' === $field['key'] ) { $field['label'] = '轮播时间'; $field['uiMax'] = 5000; $field['help'] = '滑块常用1–5秒；已存的最长20秒仍有效，可用数值输入调整。'; }
			if ( 'hoverPreview' === $field['key'] ) { $field['label'] = '悬停显示热点商品卡'; $field['help'] = '鼠标悬停时显露商品卡；点击按钮后的详情或快速预览由下方交互设置决定。'; }
		}
		unset( $field );
		$def['settings'][] = self::select( 'interaction', '热点预览交互', 'detail', array( 'detail' => '跳转商品详情页', 'quick_view' => '弹出快速预览弹窗' ), 'content', array( 'help' => '快速预览沿用商品的真实规格、库存和购物车；不支持的商品类型仍进入详情页。' ) );
		$def['editorDefaults'] = array( 'autoplay' => false, 'interaction' => 'quick_view' );
		$def['items']['default_count'] = 2;
		$def['panels'] = array(
			array( 'id' => 'play', 'label' => '轮播与交互', 'initialOpen' => true, 'fields' => array( 'autoplay', 'intervalMs', 'interaction', 'hoverPreview' ) ),
			array( 'id' => 'general', 'label' => '显示与间距', 'fields' => array( 'showDesktop', 'showMobile', 'paddingTopPx', 'paddingBottomPx', 'backgroundColor' ) ),
		);
		foreach ( $def['items']['fields'] as &$field ) {
			if ( 'media' === $field['key'] ) { $field['label'] = '背景产品图片'; $field['mobileImageLabel'] = '手机背景图片（留空沿用电脑）'; }
			if ( 'overlayColor' === $field['key'] ) { $field['label'] = '蒙层颜色'; }
			if ( 'overlayOpacity' === $field['key'] ) { $field['label'] = '蒙层透明度'; $field['ui'] = 'slider'; }
			if ( 'spots' === $field['key'] ) {
				$field['label'] = '商品热点';
				$field['previewSelect'] = true;
				foreach ( $field['fields'] as &$spot ) {
					if ( 'product' === $spot['key'] ) { $spot['label'] = '选择商品'; }
					if ( 'open' === $spot['key'] ) { $spot['label'] = '默认展示产品'; $spot['help'] = '启用多个时，手机初始只展示本场景首个；电脑可同时展示。保存值不变。'; }
					if ( in_array( $spot['key'], array( 'x', 'y' ), true ) ) { $spot['label'] = 'x' === $spot['key'] ? '横向位置' : '纵向位置'; $spot['ui'] = 'slider'; $spot['unit'] = '%'; $spot['help'] = '切换上方电脑/手机分别调整；手机留空沿用电脑。极边位置仅显示时避边，不改保存坐标。'; }
					if ( 'buttonLabel' === $spot['key'] ) { $spot['label'] = '按钮文字'; $spot['help'] = '仅修改文字；实际动作由“热点预览交互”设置决定。'; }
				}
				unset( $spot );
				$field['fields'][] = self::select( 'popupPosition', '产品显示位置', 'top-center', array( 'right' => '居右', 'left' => '居左', 'top-left' => '上左', 'top-center' => '上中', 'top-right' => '上右', 'bottom-left' => '下左', 'bottom-center' => '下中', 'bottom-right' => '下右' ), 'layout', array( 'device' => true, 'default' => array( 'desktop' => 'top-center', 'mobile' => null ), 'help' => '切换电脑/手机分别选择；空间不足时自动避边。' ) );
				$field['editorDefaults'] = array( 'buttonLabel' => 'Quick View', 'popupPosition' => array( 'desktop' => 'bottom-center', 'mobile' => 'bottom-center' ) );
			}
		}
		unset( $field );
		$spots = array();
		foreach ( array( array( 50, 50, 50, 50 ), array( 70, 20, 50, 20 ), array( 20, 50, 70, 50 ) ) as $index => $position ) {
			$spots[] = array( 'product' => null, 'open' => 0 === $index, 'buttonLabel' => 'Quick View', 'x' => array( 'desktop' => $position[0], 'mobile' => $position[2] ), 'y' => array( 'desktop' => $position[1], 'mobile' => $position[3] ), 'popupPosition' => array( 'desktop' => 'bottom-center', 'mobile' => 'bottom-center' ) );
		}
		$def['items']['nestedDefaults'] = array( 'spots' => $spots );
		return $def;
	}

	/** Observed content blocks use the same saved fields with task-specific panels. */
	/** Manual information rows; old plain strings and icon defaults remain valid. */
	private static function reference_info_list_controls( array $def ): array {
		$def['settings'][] = Fields::f( 'select', 'displayStyle', '展示样式', 'standard', array( 'group' => 'layout', 'options' => array( 'standard' => '原信息条', 'bar' => '信息列表' ) ) );
		foreach ( $def['settings'] as &$field ) {
			if ( 'mobileSwipe' === $field['key'] ) { $field['label'] = '手机端滑动切换'; $field['help'] = '信息列表在手机上每次显示一项，关闭后纵向显示；原信息条保留原来的横向滑动。'; }
			if ( 'align' === $field['key'] ) { $field['label'] = '对齐方式'; }
		}
		unset( $field );
		$def['editorDefaults'] = array( 'displayStyle' => 'bar' );
		$def['panels'] = array(
			array( 'id' => 'layout', 'label' => '信息列表设置', 'initialOpen' => true, 'fields' => array( 'mobileSwipe', 'align' ) ),
			array( 'id' => 'legacy', 'label' => '其他展示方式', 'fields' => array( 'displayStyle' ) ),
			array( 'id' => 'general', 'label' => '显示与间距', 'fields' => array( 'showDesktop', 'showMobile', 'paddingTopPx', 'paddingBottomPx', 'backgroundColor' ) ),
		);
		foreach ( $def['items']['fields'] as &$field ) {
			if ( 'text' === $field['key'] ) { $field['uiHidden'] = true; }
			if ( 'image' === $field['key'] ) { $field['label'] = '图片'; $field['help'] = '图片按原比例显示，高度最多20px；不拉伸图片，也不替换为自动业务图标。'; }
			if ( 'icon' === $field['key'] ) { $field['label'] = '原内置图标'; }
		}
		unset( $field );
		$def['items']['fields'][] = Fields::f( 'html', 'textHtml', '简短描述', null, array( 'ui' => 'richtext', 'legacyKey' => 'text', 'richTextMedia' => 'image', 'richMediaBlock' => 'vialcove/info-list', 'richMediaItem' => true ) );
		$def['items']['editorDefaults'] = array( 'icon' => '' );
		$def['items']['panels'] = array(
			array( 'id' => 'content', 'label' => '图片与描述', 'initialOpen' => true, 'fields' => array( 'image', 'textHtml' ) ),
			array( 'id' => 'legacy', 'label' => '其他图标', 'fields' => array( 'icon' ) ),
		);
		return $def;
	}

	/** Preserve original HTML until a deliberate edit opts into the rich field. */
	private static function reference_text_block_controls( array $def ): array {
		foreach ( $def['settings'] as &$field ) {
			if ( 'bodyHtml' === $field['key'] ) { $field['label'] = '原始HTML（兼容已有内容）'; $field['help'] = '保留原有HTML格式；沿用原HTML时生效。新富文本启用后，可点“沿用原HTML”切回。'; }
			if ( 'collapsedHeightPx' === $field['key'] ) { $field['label'] = '收起高度'; $field['ui'] = 'range'; $field['uiMin'] = 50; $field['uiMax'] = 300; $field['help'] = '常用50–300px；保留原有0–1000px，0表示不折叠。'; }
			if ( 'expandLabel' === $field['key'] ) { $field['label'] = '展开文字'; }
			if ( 'collapseLabel' === $field['key'] ) { $field['label'] = '收起文字'; }
		}
		unset( $field );
		$def['settings'][] = Fields::f( 'html', 'bodyRichHtml', '内容', null, array( 'ui' => 'richtext', 'legacyHtml' => true, 'legacyHtmlKey' => 'bodyHtml', 'richTextMedia' => 'image', 'richMediaBlock' => 'vialcove/text-block', 'help' => '已有原始HTML先在源码中保留；明确应用修改后才使用新富文本规范。' ) );
		$def['editorDefaults'] = array( 'bodyRichHtml' => '', 'collapsedHeightPx' => 80, 'expandLabel' => 'See all Information', 'collapseLabel' => 'Hide less Information' );
		$def['panels'] = array(
			array( 'id' => 'content', 'label' => '标题与内容', 'initialOpen' => true, 'fields' => array( 'title', 'bodyRichHtml' ) ),
			array( 'id' => 'collapse', 'label' => '展开与收起', 'initialOpen' => true, 'fields' => array( 'collapsedHeightPx', 'expandLabel', 'collapseLabel' ) ),
			array( 'id' => 'colors', 'label' => '颜色设置', 'fields' => array( 'textColor', 'linkColor', 'buttonColor' ) ),
			array( 'id' => 'legacy', 'label' => '原始HTML', 'fields' => array( 'bodyHtml' ) ),
			array( 'id' => 'general', 'label' => '显示与间距', 'fields' => array( 'showDesktop', 'showMobile', 'paddingTopPx', 'paddingBottomPx', 'backgroundColor' ) ),
		);
		return $def;
	}

	/** New logo layouts retain both saved legacy display values and column ranges. */
	private static function reference_logo_list_controls( array $def ): array {
		foreach ( $def['settings'] as &$field ) {
			if ( 'description' === $field['key'] ) { $field['uiHidden'] = true; }
			if ( 'mode' === $field['key'] ) { $field['label'] = '展示方式'; $field['options'] += array( 'single' => '单排', 'adaptive' => '电脑平铺，手机分页', 'double' => '双排' ); }
			if ( 'columns' === $field['key'] ) { $field['label'] = '每排数量'; $field['deviceUI'] = 'paired'; $field['uiMin'] = array( 'desktop' => 4, 'mobile' => 1 ); $field['uiMax'] = array( 'desktop' => 8, 'mobile' => 2 ); $field['help'] = '常用电脑4–8个、手机1–2个；原有1–8的数值仍可保留。'; }
		}
		unset( $field );
		$def['settings'][] = Fields::f( 'html', 'descriptionHtml', '简短描述', null, array( 'ui' => 'richtext', 'legacyKey' => 'description', 'richTextMedia' => 'image', 'richMediaBlock' => 'vialcove/logo-list' ) );
		foreach ( $def['items']['fields'] as &$field ) {
			if ( 'image' === $field['key'] ) { $field['label'] = '商标图片'; $field['help'] = '推荐素材400×200；按原图比例显示，不裁切。'; }
			if ( 'href' === $field['key'] ) { $field['label'] = '图片链接'; $field['help'] = '可关联店铺页面或填写真实网址；留空时只展示图片。'; }
		}
		unset( $field );
		$def['editorDefaults'] = array( 'title' => 'Brands', 'mode' => 'adaptive' );
		$def['panels'] = array(
			array( 'id' => 'content', 'label' => '标题与描述', 'initialOpen' => true, 'fields' => array( 'title', 'descriptionHtml' ) ),
			array( 'id' => 'layout', 'label' => '展示设置', 'initialOpen' => true, 'fields' => array( 'mode', 'columns' ) ),
			array( 'id' => 'general', 'label' => '显示与间距', 'fields' => array( 'showDesktop', 'showMobile', 'paddingTopPx', 'paddingBottomPx', 'backgroundColor' ) ),
		);
		return $def;
	}

	/** Explicit store-menu rendering uses the same native menu ID and sanitizer. */
	private static function reference_nav_bar_controls( array $def ): array {
		$def['settings'][] = Fields::f( 'select', 'menuMode', '导航展示方式', 'wordpress', array( 'group' => 'layout', 'options' => array( 'wordpress' => '原WordPress导航', 'store' => '店铺导航' ) ) );
		$def['settings'][] = Fields::f( 'select', 'borderEdges', '边框显示', 'both', array( 'group' => 'style', 'show_if' => array( 'menuMode' => 'store' ), 'options' => array( 'top' => '上边框', 'bottom' => '下边框', 'both' => '上下边框' ) ) );
		$padding = array( 'navPaddingTopPx' => '上', 'navPaddingRightPx' => '右', 'navPaddingBottomPx' => '下', 'navPaddingLeftPx' => '左' );
		foreach ( $padding as $key => $label ) {
			$vertical = in_array( $key, array( 'navPaddingTopPx', 'navPaddingBottomPx' ), true );
			$def['settings'][] = Fields::f( 'number', $key, $label, null, array( 'group' => 'layout', 'min' => 0, 'max' => 80, 'unit' => 'px', 'show_if' => array( 'menuMode' => 'store' ), 'help' => $vertical ? '0–80；空值沿用原内距。' : '0–80；空值为0。' ) );
		}
		foreach ( $def['settings'] as &$field ) {
			if ( 'menu' === $field['key'] ) { $field['label'] = '选择菜单'; $field['ui'] = 'menu-picker'; $field['help'] = '选择“店铺装修 → 菜单导航”中的真实菜单；这里只关联菜单，不改变菜单的页头或页尾位置。'; }
			if ( 'fontSizePx' === $field['key'] ) { $field['label'] = '字体大小'; $field['uiMin'] = 10; $field['uiMax'] = 30; $field['help'] = '常用10–30px；原有较大字号仍可在数值框保留至40px。'; }
			if ( 'bold' === $field['key'] ) { $field['label'] = '文字加粗'; }
			if ( 'fullWidth' === $field['key'] ) { $field['label'] = '铺满可用宽度'; }
			if ( 'align' === $field['key'] ) { $field['label'] = '对齐方式'; $field['help'] = '只影响电脑端；手机菜单保持一行，可横向滚动。'; }
			if ( in_array( $field['key'], array( 'innerPaddingPx', 'border' ), true ) ) { $field['show_if'] = array( 'menuMode' => 'wordpress' ); }
		}
		unset( $field );
		$def['editorDefaults'] = array( 'menuMode' => 'store', 'align' => 'left', 'borderEdges' => 'both', 'navPaddingTopPx' => 12, 'navPaddingRightPx' => 0, 'navPaddingBottomPx' => 12, 'navPaddingLeftPx' => 0 );
		$def['panels'] = array(
			array( 'id' => 'menu', 'label' => '菜单导航设置', 'initialOpen' => true, 'fields' => array( 'menu', 'fullWidth', 'bold', 'fontSizePx', 'align', 'borderEdges' ) ),
			array( 'id' => 'padding', 'label' => '内容留白', 'initialOpen' => true, 'ui' => 'boxmodel', 'plainNumbers' => true, 'fields' => array_keys( $padding ) ),
			array( 'id' => 'colors', 'label' => '颜色设置', 'initialOpen' => true, 'fields' => array( 'textColor', 'bgColor', 'borderColor' ) ),
			array( 'id' => 'legacy', 'label' => '其他展示方式', 'fields' => array( 'menuMode', 'innerPaddingPx', 'border' ) ),
			array( 'id' => 'general', 'label' => '显示与间距', 'fields' => array( 'showDesktop', 'showMobile', 'paddingTopPx', 'paddingBottomPx', 'backgroundColor' ) ),
		);
		return $def;
	}

	private static function reference_content_controls( string $type, array $def ): array {
		if ( 'info_list' === $type ) { return self::reference_info_list_controls( $def ); }
		if ( 'nav_bar' === $type ) { return self::reference_nav_bar_controls( $def ); }
		if ( 'logo_list' === $type ) { return self::reference_logo_list_controls( $def ); }
		if ( 'text_block' === $type ) { return self::reference_text_block_controls( $def ); }
		if ( 'marquee' === $type ) { return self::reference_marquee_controls( $def ); }
		if ( 'spacer' === $type ) { return self::reference_spacer_controls( $def ); }
		if ( 'rich_text' === $type ) { return self::reference_rich_text_controls( $def ); }
		if ( 'compare' === $type ) { return self::reference_compare_controls( $def ); }
		if ( 'hotspots' === $type ) { return self::reference_hotspots_controls( $def ); }
		if ( 'faq' === $type ) { return self::reference_faq_controls( $def ); }
		if ( 'product_showcase' === $type ) { return self::reference_showcase_controls( $def ); }
		if ( ! in_array( $type, array( 'media_text', 'card_list', 'video', 'video_text', 'slogan', 'counter', 'gallery', 'timeline', 'contact', 'promo_grid' ), true ) ) { return $def; }
		foreach ( $def['settings'] as &$field ) {
			if ( 'bodyHtml' === $field['key'] ) { $field['ui'] = 'richtext'; }
			if ( 'effectDurationMs' === $field['key'] ) { $field['uiMin'] = 1000; }
			if ( 'effectIntervalMs' === $field['key'] ) { $field['uiMin'] = 500; $field['uiMax'] = 5000; }
			if ( in_array( $type, array( 'card_list', 'video', 'video_text', 'slogan', 'counter', 'gallery', 'timeline', 'contact' ), true ) && 'description' === $field['key'] ) { $field['uiHidden'] = true; }
			if ( 'gallery' === $type && 'columns' === $field['key'] ) { $field['show_if'] = array( 'display' => 'grid' ); $field['deviceUI'] = 'paired'; }
			if ( 'counter' === $type ) {
				if ( 'numberSizePx' === $field['key'] ) { $field['label'] = '数字字号'; $field['min'] = 14; $field['uiMin'] = 14; $field['uiMax'] = 52; }
				if ( 'columns' === $field['key'] ) { $field['uiMin'] = 1; $field['uiMax'] = array( 'desktop' => 5, 'mobile' => 2 ); $field['deviceUI'] = 'paired'; }
			}
			if ( 'slogan' === $type ) {
				if ( 'iconWidthPx' === $field['key'] ) { $field['label'] = '图标宽度'; $field['uiMin'] = 20; $field['uiMax'] = 100; }
				if ( 'titleSizePx' === $field['key'] ) { $field['label'] = '条目标题字号'; $field['uiMin'] = 14; $field['uiMax'] = 30; }
				if ( 'mobileCarousel' === $field['key'] ) { $field['label'] = '手机轮播'; $field['help'] = '开启时一屏显示一项，可滑动或点击圆点；关闭时逐项排列。'; }
			}
			if ( 'video_text' === $type ) {
				if ( 'title' === $field['key'] ) { $field['type'] = 'textarea'; $field['help'] = '回车换行，不使用 HTML 标记。'; }
				if ( 'titleSizePx' === $field['key'] ) { $field['uiMin'] = 40; $field['uiMax'] = 100; }
				if ( 'overlayOpacity' === $field['key'] ) { $field['max'] = 100; }
				if ( 'heightPx' === $field['key'] ) { $field['label'] = '自定义高度'; $field['deviceUI'] = 'paired'; $field['help'] = '高度预设选择“自定义高度”时使用此值。'; }
			}
			if ( 'card_list' === $type && 'columns' === $field['key'] ) { $field['uiMin'] = array( 'desktop' => 3, 'mobile' => 1 ); $field['uiMax'] = array( 'desktop' => 5, 'mobile' => 2 ); $field['deviceUI'] = 'paired'; }
		}
		unset( $field );
		if ( 'promo_grid' === $type ) {
			$def['settings'][] = Fields::f( 'color', 'dotColor', '点', '', array( 'help' => '设置轮播分页圆点的颜色。' ) );
			$def['settings'][] = Fields::f( 'select', 'layoutPreset', '分栏比例', 'legacy', array( 'group' => 'layout', 'options' => array( 'legacy' => '主图优先（电脑 2:1，手机上下排列）', 'balanced' => '等宽分栏（电脑和手机并排）' ) ) );
			foreach ( $def['settings'] as &$field ) {
				if ( 'leftTitle' === $field['key'] ) { $field['label'] = '左边标题'; }
				if ( 'rightTitle' === $field['key'] ) { $field['label'] = '右边标题'; }
				if ( 'autoplay' === $field['key'] ) { $field['label'] = '自动轮播'; }
				if ( 'intervalMs' === $field['key'] ) { $field['label'] = '轮播时间'; $field['uiMin'] = 1000; $field['uiMax'] = 5000; $field['help'] = '常用 1–5 秒；已有较长间隔仍可在数值框中保留或调整。'; }
				if ( 'dots' === $field['key'] ) { $field['label'] = '显示分页圆点'; }
				if ( in_array( $field['key'], array( 'category1', 'category2' ), true ) ) { $field['label'] = 'category1' === $field['key'] ? '促销专辑 1' : '促销专辑 2'; $field['ui'] = 'collection-confirm'; }
			}
			unset( $field );
			$def['editorDefaults'] = array( 'leftTitle' => 'Sell hot', 'rightTitle' => 'Most-loved', 'layoutPreset' => 'balanced', 'dotColor' => '#171717' );
			$def['panels'] = array(
				array( 'id' => 'left', 'label' => '左边设置', 'initialOpen' => true, 'fields' => array( 'leftTitle', 'dotColor', 'autoplay', 'intervalMs' ) ),
				array( 'id' => 'right', 'label' => '右边设置', 'initialOpen' => true, 'fields' => array( 'rightTitle', 'category1', 'category2' ) ),
				array( 'id' => 'general', 'label' => '分栏与通用设置', 'fields' => array( 'layoutPreset', 'dots', 'showDesktop', 'showMobile', 'paddingTopPx', 'paddingBottomPx', 'backgroundColor' ) ),
			);
			$def['items']['label'] = '左边轮播图设置';
			foreach ( $def['items']['fields'] as &$field ) {
				if ( 'image' === $field['key'] ) { $field['label'] = '图片'; $field['help'] = '建议宽度 1000px 以上，高度随分栏空间自适应；图片保留比例并裁切填充。'; }
				if ( 'href' === $field['key'] ) { $field['label'] = '跳转链接'; }
			}
			unset( $field );
		} elseif ( 'contact' === $type ) {
			$def['settings'][] = Fields::f( 'html', 'descriptionHtml', '简短描述', null, array( 'ui' => 'richtext', 'legacyKey' => 'description', 'richTextMedia' => 'image', 'richMediaBlock' => 'vialcove/contact' ) );
			$def['settings'][] = Fields::f( 'color', 'themeColor', '主题颜色', null, array( 'group' => 'style', 'help' => '设置标题、正文、链接与内置图标的基础颜色；未设置时保留店铺文字颜色与原图标颜色。正文单独设置的文字颜色仍有效。' ) );
			$def['settings'][] = Fields::f( 'select', 'backgroundFallback', '缺图时的背景', 'inherit', array( 'group' => 'layout', 'options' => array( 'inherit' => '沿用另一端图片', 'color' => '使用背景颜色' ), 'help' => '“使用背景颜色”让电脑与手机背景保持独立，未设置图片的一端显示背景颜色并叠加蒙层；需要共用图片时选择“沿用另一端图片”。' ) );
			$def['editorDefaults'] = array( 'backgroundFallback' => 'color', 'bgColor' => '#171717', 'themeColor' => '#ffffff' );
			foreach ( $def['settings'] as &$field ) {
				if ( 'overlayOpacity' === $field['key'] ) { $field['max'] = 100; }
				if ( 'iconSizePx' === $field['key'] ) { $field['uiMin'] = 24; $field['uiMax'] = 56; }
				if ( 'accentColor' === $field['key'] ) { $field['label'] = '原图标颜色'; $field['help'] = '未设置主题颜色时，只影响内置图标；保留已有颜色设置。'; }
				if ( 'media' === $field['key'] ) { $field['mobileImageLabel'] = '手机图片'; $field['help'] = '分别选择电脑与手机图片；未设置图片时按上面的背景方式显示。建议电脑2400×656px、手机1242×1138px。'; }
			}
			unset( $field );
			$def['panels'] = array(
				array( 'id' => 'content', 'label' => '标题与说明', 'initialOpen' => true, 'fields' => array( 'title', 'descriptionHtml' ) ),
				array( 'id' => 'background', 'label' => '背景与宽度', 'initialOpen' => true, 'fields' => array( 'fullWidth', 'backgroundFallback', 'media', 'overlayOpacity', 'bgColor' ) ),
				array( 'id' => 'style', 'label' => '图标与主题颜色', 'initialOpen' => true, 'fields' => array( 'iconSizePx', 'themeColor' ) ),
				array( 'id' => 'legacy-color', 'label' => '其他图标颜色', 'fields' => array( 'accentColor' ) ),
				array( 'id' => 'general', 'label' => '显示与间距', 'fields' => array( 'showDesktop', 'showMobile', 'paddingTopPx', 'paddingBottomPx', 'backgroundColor' ) ),
			);
			foreach ( $def['items']['fields'] as &$field ) {
				if ( 'text' === $field['key'] ) { $field['uiHidden'] = true; }
				if ( 'icon' === $field['key'] ) { $field['label'] = '其他图标'; $field['help'] = '联系图标选择“沿用原图标”、且未使用自定义图片时显示。'; }
				if ( 'image' === $field['key'] ) { $field['label'] = '自定义图标图片'; $field['help'] = '建议48×48px；有效图片优先于内置图标显示。'; }
				if ( 'href' === $field['key'] ) { $field['help'] = '链接应用于此联系方式；正文含独立链接时，图标和标题使用此链接。图标与标题都未设置时，显示独立的联系链接，正文链接保持可用。'; }
			}
			unset( $field );
			array_unshift( $def['items']['fields'], Fields::f( 'select', 'symbol', '联系图标', 'none', array( 'options' => array( 'none' => '沿用原图标', 'hidden' => '不显示内置图标' ) + Contact_Symbols::options(), 'help' => '自定义图片优先；选择图标不会改动已填写的标题、正文或链接。' ) ) );
			$def['items']['fields'][] = Fields::f( 'html', 'textHtml', '内容', null, array( 'ui' => 'richtext', 'legacyKey' => 'text', 'richTextMedia' => 'image', 'richMediaBlock' => 'vialcove/contact', 'richMediaItem' => true ) );
			$def['items']['panels'] = array(
				array( 'id' => 'visual', 'label' => '图标与图片', 'initialOpen' => true, 'fields' => array( 'symbol', 'image' ) ),
				array( 'id' => 'content', 'label' => '内容与链接', 'initialOpen' => true, 'fields' => array( 'title', 'textHtml', 'href' ) ),
				array( 'id' => 'legacy-icon', 'label' => '其他图标', 'fields' => array( 'icon' ) ),
			);
		} elseif ( 'timeline' === $type ) {
			$def['settings'][] = Fields::f( 'html', 'descriptionHtml', '简短描述', null, array( 'ui' => 'richtext', 'legacyKey' => 'description' ) );
			$def['settings'][] = Fields::f( 'number', 'itemWidthPx', '电脑时间线宽度', 200, array( 'group' => 'layout', 'min' => 150, 'max' => 350, 'ui' => 'slider', 'unit' => 'px', 'show_if' => array( 'style' => 'horizontal' ), 'help' => '推荐 150、200、250、300 或 350px；手机自动改为纵向排列。' ) );
			$padding = array( 'contentPaddingTopPx' => '上', 'contentPaddingRightPx' => '右', 'contentPaddingBottomPx' => '下', 'contentPaddingLeftPx' => '左' );
			foreach ( $padding as $key => $label ) {
				$def['settings'][] = Fields::f( 'number', $key, $label, 0, array( 'group' => 'layout', 'device' => true, 'min' => 0, 'max' => 240, 'ui' => 'input', 'unit' => 'px' ) );
			}
			foreach ( self::button( false ) as $field ) {
				if ( 'button' === $field['key'] ) { continue; }
				if ( str_ends_with( $field['key'], 'Ms' ) ) { $field['label'] = 'effectDurationMs' === $field['key'] ? '按钮动画时间' : '按钮动画间隔'; $field['ui'] = 'seconds'; $field['unit'] = 's'; $field['uiMin'] = 'effectDurationMs' === $field['key'] ? 1000 : 500; $field['uiMax'] = 5000; }
				$def['settings'][] = $field;
			}
			$def['editorDefaults'] = array( 'autoScroll' => true, 'collapsible' => true );
			$def['panels'] = array(
				array( 'id' => 'content', 'label' => '标题与说明', 'initialOpen' => true, 'fields' => array( 'title', 'descriptionHtml' ) ),
				array( 'id' => 'layout', 'label' => '时间轴样式', 'initialOpen' => true, 'fields' => array( 'style', 'itemWidthPx', 'autoScroll', 'collapsible', 'expandLabel', 'collapseLabel' ) ),
				array( 'id' => 'inner-padding', 'label' => '内容留白', 'ui' => 'boxmodel', 'initialOpen' => true, 'fields' => array_keys( $padding ) ),
				array( 'id' => 'style', 'label' => '文字与背景颜色', 'fields' => array( 'titleColor', 'textColor', 'backgroundColor' ) ),
				array( 'id' => 'button', 'label' => '展开按钮动效', 'fields' => array( 'buttonEffect', 'effectDurationMs', 'effectIntervalMs' ) ),
				array( 'id' => 'general', 'label' => '显示与间距', 'fields' => array( 'showDesktop', 'showMobile', 'paddingTopPx', 'paddingBottomPx' ) ),
			);
			foreach ( $def['settings'] as &$field ) {
				if ( 'autoScroll' === $field['key'] ) { $field['show_if'] = array( 'style' => 'horizontal' ); $field['help'] = '仅电脑端自动滚动；悬停、聚焦或手动操作时暂停，也可使用页面上的暂停按钮。'; }
				if ( in_array( $field['key'], array( 'collapsible', 'expandLabel', 'collapseLabel', 'buttonEffect', 'effectDurationMs', 'effectIntervalMs' ), true ) ) { $field['show_if'] = array( 'style' => 'vertical' ); }
			}
			unset( $field );
			foreach ( $def['items']['fields'] as &$field ) {
				if ( 'text' === $field['key'] ) { $field['uiHidden'] = true; }
				if ( 'date' === $field['key'] ) { $field['help'] = '样式一使用此日期；有效的 YYYY-MM-DD 日期会同时显示年份。其他日期文字原样保留，条目按列表顺序显示。'; }
				if ( 'title' === $field['key'] ) { $field['label'] = '副标题'; }
			}
			unset( $field );
			$def['items']['fields'][] = Fields::f( 'html', 'textHtml', '简短描述', null, array( 'ui' => 'richtext', 'legacyKey' => 'text' ) );
			$def['items']['fields'][] = Fields::f( 'text', 'dateTitle', '日期标题', null, array( 'ui' => 'inherited-text', 'legacyTextKey' => 'date', 'help' => '未单独设置时沿用原日期；清空后不显示。' ) );
			$def['items']['fields'][] = Fields::f( 'text', 'dateText', '日期文字', null, array( 'ui' => 'inherited-text', 'legacyTextKey' => 'date', 'help' => '未单独设置时沿用原日期；清空后不显示。' ) );
			$def['items']['panels'] = array(
				array( 'id' => 'content', 'label' => '内容与颜色', 'initialOpen' => true, 'fields' => array( 'title', 'textHtml', 'color' ) ),
				array( 'id' => 'style-one', 'label' => '样式一日期', 'fields' => array( 'date' ) ),
				array( 'id' => 'style-two', 'label' => '样式二图片与日期', 'fields' => array( 'image', 'imagePosition', 'dateTitle', 'dateText' ) ),
			);
		} elseif ( 'gallery' === $type ) {
			$def['settings'][] = Fields::f( 'html', 'descriptionHtml', '简短描述', null, array( 'ui' => 'richtext', 'legacyKey' => 'description' ) );
			$def['settings'][] = Fields::f( 'select', 'display', '展示方式', 'grid', array( 'options' => array( 'grid' => '多列图片', 'rail' => '横向画廊' ), 'help' => '横向画廊以相同图片高度排列，保留每张图片的比例；多列图片沿用原列数。' ) );
			$def['editorDefaults'] = array( 'display' => 'rail' );
			$def['panels'] = array(
				array( 'id' => 'content', 'label' => '标题与描述', 'initialOpen' => true, 'fields' => array( 'title', 'descriptionHtml' ) ),
				array( 'id' => 'layout', 'label' => '画廊排列', 'initialOpen' => true, 'fields' => array( 'display', 'fullWidth', 'columns' ) ),
				array( 'id' => 'button', 'label' => '按钮与特效', 'fields' => array( 'button', 'buttonEffect', 'effectDurationMs', 'effectIntervalMs' ) ),
				array( 'id' => 'general', 'label' => '显示与间距', 'fields' => array( 'showDesktop', 'showMobile', 'paddingTopPx', 'paddingBottomPx', 'backgroundColor' ) ),
			);
			foreach ( $def['items']['fields'] as &$field ) {
				if ( 'caption' === $field['key'] ) { $field['uiHidden'] = true; }
				if ( 'href' === $field['key'] ) { $field['help'] = '链接用于图片和说明；说明内已有链接时，只为图片应用此链接，保留说明中的独立链接。'; }
			}
			unset( $field );
			$def['items']['fields'][] = Fields::f( 'html', 'captionHtml', '简短描述', null, array( 'ui' => 'richtext', 'legacyKey' => 'caption' ) );
			$def['items']['panels'] = array(
				array( 'id' => 'image', 'label' => '图片与链接', 'initialOpen' => true, 'fields' => array( 'image', 'href' ) ),
				array( 'id' => 'caption', 'label' => '简短描述', 'initialOpen' => true, 'fields' => array( 'captionHtml' ) ),
			);
		} elseif ( 'counter' === $type ) {
			$def['settings'][] = Fields::f( 'html', 'descriptionHtml', '简短描述', null, array( 'ui' => 'richtext', 'legacyKey' => 'description' ) );
			$def['panels'] = array(
				array( 'id' => 'content', 'label' => '标题与描述', 'initialOpen' => true, 'fields' => array( 'title', 'descriptionHtml' ) ),
				array( 'id' => 'layout', 'label' => '数字与列数', 'initialOpen' => true, 'fields' => array( 'numberSizePx', 'columns' ) ),
				array( 'id' => 'general', 'label' => '显示与间距', 'fields' => array( 'showDesktop', 'showMobile', 'paddingTopPx', 'paddingBottomPx', 'backgroundColor' ) ),
			);
			foreach ( $def['items']['fields'] as &$field ) {
				if ( 'image' === $field['key'] ) { $field['label'] = '自定义图标图片'; $field['help'] = '建议48×48px。有效图片优先显示；清空图片后使用所选图标。'; }
				if ( 'icon' === $field['key'] ) { $field['label'] = '其他图标'; $field['help'] = '内置图标选择“沿用原图标”且未使用自定义图片时显示。'; }
				if ( 'number' === $field['key'] ) { $field['label'] = '数字'; $field['min'] = -999999999; $field['step'] = 0.000001; $field['precision'] = 6; $field['help'] = '支持负数及最多6位小数；显示时保留单位的原始大小写。'; }
			}
			unset( $field );
			array_unshift( $def['items']['fields'], Fields::f( 'select', 'symbol', '内置图标', 'none', array( 'options' => array( 'none' => '沿用原图标', 'hidden' => '仅不显示内置图标' ) + Slogan_Symbols::options(), 'help' => '自定义图片优先；“仅不显示内置图标”同时关闭此处和其他图标，不隐藏图片。' ) ) );
			$def['items']['panels'] = array(
				array( 'id' => 'visual', 'label' => '图标与图片', 'initialOpen' => true, 'fields' => array( 'symbol', 'image' ) ),
				array( 'id' => 'number', 'label' => '数字与文字', 'initialOpen' => true, 'fields' => array( 'number', 'unit', 'label' ) ),
				array( 'id' => 'other-icon', 'label' => '其他图标', 'fields' => array( 'icon' ) ),
			);
		} elseif ( 'slogan' === $type ) {
			$def['settings'][] = Fields::f( 'html', 'descriptionHtml', '简短描述', null, array( 'ui' => 'richtext', 'legacyKey' => 'description' ) );
			$def['panels'] = array(
				array( 'id' => 'content', 'label' => '标题与描述', 'initialOpen' => true, 'fields' => array( 'title', 'descriptionHtml' ) ),
				array( 'id' => 'layout', 'label' => '图标与手机排列', 'initialOpen' => true, 'fields' => array( 'iconWidthPx', 'titleSizePx', 'mobileCarousel' ) ),
				array( 'id' => 'general', 'label' => '显示与间距', 'fields' => array( 'showDesktop', 'showMobile', 'paddingTopPx', 'paddingBottomPx', 'backgroundColor' ) ),
			);
			foreach ( $def['items']['fields'] as &$field ) {
				if ( 'text' === $field['key'] ) { $field['uiHidden'] = true; }
				if ( 'icon' === $field['key'] ) { $field['label'] = '自定义图标图片'; $field['help'] = '图片优先显示；清空图片后显示所选内置图标。建议使用正方形图片。'; }
			}
			unset( $field );
			array_unshift( $def['items']['fields'], Fields::f( 'select', 'symbol', '内置图标', 'none', array( 'options' => array( 'none' => '无图标' ) + Slogan_Symbols::options() ) ) );
			$def['items']['fields'][] = Fields::f( 'html', 'textHtml', '内容', null, array( 'ui' => 'richtext', 'legacyKey' => 'text' ) );
			$def['items']['panels'] = array(
				array( 'id' => 'icon', 'label' => '图标', 'initialOpen' => true, 'fields' => array( 'symbol', 'icon' ) ),
				array( 'id' => 'content', 'label' => '标题与内容', 'initialOpen' => true, 'fields' => array( 'title', 'textHtml' ) ),
			);
		} elseif ( 'card_list' === $type ) {
			$def['settings'][] = Fields::f( 'html', 'descriptionHtml', '简短描述', null, array( 'ui' => 'richtext', 'legacyKey' => 'description' ) );
			$def['editorDefaults'] = array( 'gapPx' => 30, 'textAlign' => 'center' );
			$def['panels'] = array(
				array( 'id' => 'content', 'label' => '标题与描述', 'initialOpen' => true, 'fields' => array( 'title', 'descriptionHtml' ) ),
				array( 'id' => 'layout', 'label' => '宽度与排列', 'initialOpen' => true, 'fields' => array( 'fullWidth', 'display', 'textAlign', 'columns', 'gapPx' ) ),
				array( 'id' => 'general', 'label' => '显示与间距', 'fields' => array( 'showDesktop', 'showMobile', 'paddingTopPx', 'paddingBottomPx', 'backgroundColor' ) ),
			);
		} elseif ( 'video' === $type ) {
			$def['settings'][] = Fields::f( 'html', 'descriptionHtml', '简短描述', null, array( 'ui' => 'richtext', 'legacyKey' => 'description' ) );
			// Only newly inserted videos follow the observed quiet playback defaults.
			// Existing stored values and the original field defaults remain authoritative.
			$def['editorDefaults'] = array( 'autoplay' => false, 'muted' => false );
			$def['panels'] = array(
				array( 'id' => 'content', 'label' => '标题与介绍', 'initialOpen' => true, 'fields' => array( 'title', 'descriptionHtml' ) ),
				array( 'id' => 'media', 'label' => '视频来源与封面', 'initialOpen' => true, 'fields' => array( 'source', 'video', 'youtubeUrl', 'poster' ) ),
				array( 'id' => 'layout', 'label' => '布局与比例', 'initialOpen' => true, 'fields' => array( 'layout', 'fullWidth', 'ratio' ) ),
				array( 'id' => 'play', 'label' => '播放设置', 'fields' => array( 'autoplay', 'loop', 'muted', 'controls' ) ),
				array( 'id' => 'general', 'label' => '显示与间距', 'fields' => array( 'showDesktop', 'showMobile', 'paddingTopPx', 'paddingBottomPx', 'backgroundColor' ) ),
			);
		} elseif ( 'video_text' === $type ) {
			$desktop_heights = array( '450px' => '450px', '550px' => '550px', '650px' => '650px', '750px' => '750px', 'screen' => '全屏', 'custom' => '自定义高度' );
			$mobile_heights = array( '250px' => '250px', '300px' => '300px', '400px' => '400px', '500px' => '500px', 'screen' => '全屏', 'custom' => '自定义高度' );
			$def['settings'][] = Fields::f( 'html', 'descriptionHtml', '描述', null, array( 'ui' => 'richtext', 'legacyKey' => 'description' ) );
			$def['settings'][] = Fields::f( 'select', 'heightPreset', '高度预设', 'custom', array(
				'device' => true,
				'deviceUI' => 'paired',
				'options' => array( '450px' => '450px', '550px' => '550px', '650px' => '650px', '750px' => '750px', '250px' => '250px', '300px' => '300px', '400px' => '400px', '500px' => '500px', 'screen' => '全屏', 'custom' => '自定义高度' ),
				'uiOptionsByDevice' => array( 'desktop' => $desktop_heights, 'mobile' => $mobile_heights ),
			) );
			$def['editorDefaults'] = array( 'heightPreset' => array( 'desktop' => '550px', 'mobile' => '300px' ), 'heightPx' => array( 'desktop' => 550, 'mobile' => 300 ), 'autoplay' => false, 'loop' => false, 'controls' => false, 'muted' => false );
			$def['panels'] = array(
				array( 'id' => 'content', 'label' => '标题、描述与按钮', 'initialOpen' => true, 'fields' => array( 'title', 'descriptionHtml', 'titleSizePx', 'button' ) ),
				array( 'id' => 'media', 'label' => '视频来源与封面', 'initialOpen' => true, 'fields' => array( 'source', 'video', 'youtubeUrl', 'poster' ) ),
				array( 'id' => 'layout', 'label' => '高度、位置与蒙层', 'initialOpen' => true, 'fields' => array( 'heightPreset', 'position', 'overlayOpacity', 'textColor' ) ),
				array( 'id' => 'play', 'label' => '播放设置', 'fields' => array( 'autoplay', 'loop', 'controls', 'muted' ) ),
				array( 'id' => 'custom', 'label' => '自定义高度', 'fields' => array( 'heightPx' ) ),
				array( 'id' => 'general', 'label' => '显示与间距', 'fields' => array( 'showDesktop', 'showMobile', 'paddingTopPx', 'paddingBottomPx', 'backgroundColor' ) ),
			);
		} else { $def['panels'] = array(
			array( 'id' => 'media', 'label' => '图片设置', 'initialOpen' => true, 'fields' => array( 'media', 'imagePosition', 'textAlign', 'fullWidth' ) ),
			array( 'id' => 'content', 'label' => '标题与内容', 'initialOpen' => true, 'fields' => array( 'title', 'bodyHtml' ) ),
			array( 'id' => 'style', 'label' => '文字与背景颜色', 'fields' => array( 'titleColor', 'textColor', 'contentBgColor' ) ),
			array( 'id' => 'button', 'label' => '按钮与特效', 'fields' => array( 'button', 'buttonEffect', 'effectDurationMs', 'effectIntervalMs' ) ),
			array( 'id' => 'advanced', 'label' => '更多内容与动效', 'fields' => array( 'caption', 'eyebrow', 'label', 'animation' ) ),
			array( 'id' => 'general', 'label' => '显示与间距', 'fields' => array( 'showDesktop', 'showMobile', 'paddingTopPx', 'paddingBottomPx', 'backgroundColor' ) ),
		); }
		foreach ( $def['settings'] as &$field ) {
			foreach ( $def['panels'] as $panel ) { if ( in_array( $field['key'], $panel['fields'], true ) ) { $field['panel'] = $panel['id']; break; } }
		}
		unset( $field );
		return $def;
	}

	/** Spacer has no outer padding: expose its actual paired height without changing saved units. */
	private static function reference_spacer_controls( array $def ): array {
		foreach ( $def['settings'] as &$field ) {
			if ( 'heightPx' === $field['key'] ) { $field['label'] = '空白高度'; $field['deviceUI'] = 'paired'; $field['uiMin'] = 0; $field['uiMax'] = 100; $field['help'] = '0 表示不留空白；手机可以沿用电脑高度。'; }
			if ( 'color' === $field['key'] ) { $field['label'] = '颜色'; }
			if ( in_array( $field['key'], array( 'paddingTopPx', 'paddingBottomPx' ), true ) ) { $field['uiHidden'] = true; }
		}
		unset( $field );
		$def['editorDefaults'] = array( 'heightPx' => array( 'desktop' => 30, 'mobile' => 20 ) );
		$def['panels'] = array(
			array( 'id' => 'layout', 'label' => '空白高度与颜色', 'initialOpen' => true, 'fields' => array( 'heightPx', 'color' ) ),
			array( 'id' => 'general', 'label' => '显示', 'fields' => array( 'showDesktop', 'showMobile', 'backgroundColor' ) ),
		);
		return $def;
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
						'none'       => __( 'None', 'peptide-core' ),
						'bounce'     => __( '跳动', 'peptide-core' ),
						'rubberband' => __( '橡皮筋', 'peptide-core' ),
						'shake'      => __( '左右抖动', 'peptide-core' ),
						'swing'      => __( '摇摆', 'peptide-core' ),
						'wobble'     => __( '摇晃', 'peptide-core' ),
						'pulse'      => __( '呼吸', 'peptide-core' ),
						'shine'      => __( '流光', 'peptide-core' ),
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
		return Fields::f( 'url', $key, '' !== $label ? $label : __( 'Link', 'peptide-core' ), '', array( 'ui' => 'site-link' ) );
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
						self::select(
							'cardLayout',
							__( 'Floating cards', 'peptide-core' ),
							'overlay',
							array(
								'overlay' => __( 'On the image (drag to position in the preview)', 'peptide-core' ),
								'below'   => __( 'Below the image', 'peptide-core' ),
								'hidden'  => __( 'Hidden', 'peptide-core' ),
							),
							'content',
							array( 'device' => true, 'default' => array( 'desktop' => 'overlay', 'mobile' => 'hidden' ) )
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
									// 卡片左上角在图片上的位置（%）；空 = 自动排列。可以在装修预览里直接拖动。
									Fields::f( 'number', 'x', __( 'Horizontal position on the image (%, empty = automatic)', 'peptide-core' ), null, array( 'device' => true, 'min' => 0, 'max' => 100 ) ),
									Fields::f( 'number', 'y', __( 'Vertical position on the image (%, empty = automatic)', 'peptide-core' ), null, array( 'device' => true, 'min' => 0, 'max' => 100 ) ),
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
						Fields::f( 'text', 'caption', __( 'Image caption', 'peptide-core' ), '', array( 'group' => 'media' ) ),
						Fields::f( 'text', 'eyebrow', __( 'Small heading', 'peptide-core' ), '' ),
						Fields::f( 'text', 'title', __( 'Title', 'peptide-core' ), '' ),
						Fields::f( 'text', 'label', __( 'Label', 'peptide-core' ), '' ),
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
						self::toggle( 'animation', __( 'Fade in on scroll', 'peptide-core' ), true ),
						self::color( 'titleColor', __( 'Title color', 'peptide-core' ) ),
						self::color( 'textColor', __( 'Text color', 'peptide-core' ) ),
						self::color( 'contentBgColor', __( 'Text background', 'peptide-core' ) ),
					),
					self::button( false )
				),
			),

			// 标题栏：一个区域的小标题、两行标题、介绍和链接（例如品牌故事上方的 “THE VIALCOVE STORY”）。
			'section_heading'  => array(
				'title'       => __( 'Section heading', 'peptide-core' ),
				'icon'        => 'heading',
				'description' => __( 'A heading for the blocks below it: small heading, two-line title, introduction and a link.', 'peptide-core' ),
				'settings'    => array(
					Fields::f( 'text', 'eyebrow', __( 'Small heading', 'peptide-core' ), '' ),
					Fields::f( 'text', 'title', __( 'Title', 'peptide-core' ), '' ),
					Fields::f( 'text', 'titleAccent', __( 'Title (second line, emphasized)', 'peptide-core' ), '' ),
					Fields::f( 'textarea', 'description', __( 'Introduction', 'peptide-core' ), '' ),
					Fields::f( 'link', 'link', __( 'Text link (under the title)', 'peptide-core' ) ),
					self::select( 'align', __( 'Alignment', 'peptide-core' ), 'center', $align ),
					self::color( 'titleColor', __( 'Title color', 'peptide-core' ) ),
					self::color( 'textColor', __( 'Text color', 'peptide-core' ) ),
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
								'masonry'  => __( '瀑布流', 'peptide-core' ),
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
					Fields::f( 'code', 'html', __( 'HTML', 'peptide-core' ), '', array( 'ui' => 'expanded-code', 'help' => __( '请仅粘贴可信 HTML。完整预览按当前账号权限过滤；编辑器安全预览仍会过滤代码。应用修改不会发布网站。', 'peptide-core' ) ) ),
				),
				'panels' => array(
					array( 'id' => 'content', 'label' => '内容与代码', 'initialOpen' => true, 'fields' => array( 'title', 'html' ) ),
					array( 'id' => 'layout', 'label' => '宽度', 'initialOpen' => true, 'fields' => array( 'fullWidth' ) ),
					array( 'id' => 'general', 'label' => '显示与间距', 'fields' => array( 'showDesktop', 'showMobile', 'paddingTopPx', 'paddingBottomPx', 'backgroundColor' ) ),
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

			// External donation/tip aggregates; no payment collection or manual counters.
			'donation_tip' => array(
				'title' => __( 'Donation / tip statistics', 'peptide-core' ),
				'icon' => 'money-alt',
				'description' => __( 'Displays verified aggregate data supplied by a server integration. This block does not collect payments.', 'peptide-core' ),
				'settings' => array(
					self::full_width(),
					Fields::f( 'text', 'title', __( 'Title', 'peptide-core' ), '' ),
					Fields::f( 'textarea', 'description', __( 'Short description', 'peptide-core' ), '', array( 'uiHidden' => true ) ),
					Fields::f( 'html', 'descriptionHtml', '简短描述', null, array( 'ui' => 'richtext', 'legacyKey' => 'description', 'richTextMedia' => 'image', 'richMediaBlock' => 'vialcove/donation-tip' ) ),
					self::media( 'media', '电脑 / 手机图片', '2:1仅为素材建议；按原图比例显示。' ),
					Fields::f( 'number', 'statsDays', '统计天数', 360, array( 'group' => 'content', 'min' => 30, 'max' => 360, 'ui' => 'range', 'help' => '读取本站最近相应天数的真实聚合；需先连接捐款 / 小费统计服务。' ) ),
					Fields::f( 'number', 'minDonors', '公开展示最低人数', 6, array( 'min' => 0, 'max' => 1000000, 'group' => 'content', 'help' => '真实人数达到此值才公开。0可展示已核实的零数据；未连接数据时始终隐藏。' ) ),
					Fields::f( 'text', 'amountLabel', '总额标签', 'Total Donation' ),
					Fields::f( 'text', 'donorsLabel', '人数标签', 'Total Donors' ),
					Fields::f( 'textarea', 'donorCaption', '捐款用户说明', '' ),
					Fields::f( 'text', 'buttonLabel', '按钮标题', 'More About' ),
					self::link_url( 'buttonHref', '按钮链接' ),
				),
				'panels' => array(
					array( 'id' => 'content', 'label' => '内容设置', 'initialOpen' => true, 'fields' => array( 'fullWidth', 'title', 'descriptionHtml', 'media', 'buttonLabel', 'buttonHref' ) ),
					array( 'id' => 'statistics', 'label' => '真实统计展示', 'initialOpen' => true, 'fields' => array( 'statsDays', 'minDonors', 'amountLabel', 'donorsLabel', 'donorCaption' ) ),
					array( 'id' => 'general', 'label' => '显示与间距', 'fields' => array( 'showDesktop', 'showMobile', 'paddingTopPx', 'paddingBottomPx', 'backgroundColor' ) ),
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
					self::toggle( 'reportPreview', __( 'Show the hover card set on each product', 'peptide-core' ), true ),
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
