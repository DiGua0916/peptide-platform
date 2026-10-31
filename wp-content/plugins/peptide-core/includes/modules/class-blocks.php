<?php
/**
 * 把模块注册成区块（vialcove/<type>），并为装修编辑器准备侧栏所需的数据（EDIT-BLOCKS-03）。
 *
 * 区块属性：moduleId（稳定 ID）、version、enabled（隐藏开关）、settings（字段值）、items（重复条目）。
 * 区块是动态的（save 为 null），内容由服务端按字段定义清理后渲染。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Modules;

use PeptideCore\Templates;

defined( 'ABSPATH' ) || exit;

final class Blocks {

	public const EDITOR_SCRIPT   = 'peptide-core-module-editor';
	public const CONTROLS_SCRIPT = 'peptide-core-module-controls';
	public const ICONS_SCRIPT    = 'peptide-core-design-icons';

	public static function register_hooks(): void {
		Links::register_hooks();
		add_filter( 'rest_pre_insert_' . Templates::LAYOUT, array( self::class, 'preflight_rest_media' ), 10, 2 );
		add_action( 'rest_after_insert_' . Templates::LAYOUT, array( self::class, 'approve_rest_media' ), 10, 3 );
		add_action( 'init', array( self::class, 'register' ), 20 );
		add_filter( 'block_categories_all', array( self::class, 'categories' ), 10, 2 );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue_editor' ) );
		add_action( 'enqueue_block_assets', array( self::class, 'enqueue_canvas' ) );
		add_filter( 'rest_pre_dispatch', array( self::class, 'mark_editor_preview' ), 10, 3 );
	}

	public static function register(): void {
		$url = plugins_url( 'assets/', PEPTIDE_CORE_FILE );
		$ver = static fn( string $file ): string => PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( PEPTIDE_CORE_DIR . '/assets/' . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_register_style( Renderer::STYLE, $url . 'modules.css', array(), $ver( 'modules.css' ) );
		wp_register_script( Renderer::SCRIPT, $url . 'modules.js', array(), $ver( 'modules.js' ), array( 'in_footer' => true, 'strategy' => 'defer' ) );

		foreach ( Registry::all() as $def ) {
			register_block_type(
				$def['block'],
				array(
					'api_version'     => 3,
					'title'           => $def['title'],
					'category'        => 'vialcove-' . $def['category'],
					'icon'            => $def['icon'] ?? 'screenoptions',
					'description'     => $def['description'] ?? '',
					'attributes'      => array(
						'moduleId' => array(
							'type'    => 'string',
							'default' => '',
						),
						'version'  => array(
							'type'    => 'number',
							'default' => (int) $def['version'],
						),
						'enabled'  => array(
							'type'    => 'boolean',
							'default' => true,
						),
						'settings' => array(
							'type'    => 'object',
							'default' => new \stdClass(),
						),
						'items'    => array(
							'type'    => 'array',
							'default' => array(),
						),
						// 手机版单独设置：{settings: {...}, items: [...] | null}
						'mobile'   => array(
							'type'    => 'object',
							'default' => new \stdClass(),
						),
					),
					'supports'        => array(
						'html'            => false,
						'reusable'        => false,
						'customClassName' => false,
						'className'       => false,
						'multiple'        => $def['multiple'] ?? ( 'hero_h1' !== $def['type'] ),
					),
					'render_callback' => array( Renderer::class, 'render_block' ),
				)
			);
		}
	}

	/**
	 * 装修文档里，在区块库最前面加上三个模块分类。
	 *
	 * @param array<int,array<string,mixed>> $categories
	 * @param \WP_Block_Editor_Context|mixed $context
	 * @return array<int,array<string,mixed>>
	 */
	public static function categories( $categories, $context ) {
		if ( ! isset( $context->post ) || ! $context->post instanceof \WP_Post || Templates::LAYOUT !== $context->post->post_type ) {
			return $categories;
		}
		$add = array();
		foreach ( Registry::categories() as $slug => $title ) {
			$add[] = array(
				'slug'  => 'vialcove-' . $slug,
				'title' => $title,
				'icon'  => null,
			);
		}
		return array_merge( $add, (array) $categories );
	}

	/**
	 * 编辑器的区块预览请求（ServerSideRender）里，模块使用 editor 上下文：显示占位、隐藏标记，不执行脚本。
	 *
	 * @param mixed            $result
	 * @param \WP_REST_Server  $server
	 * @param \WP_REST_Request $request
	 * @return mixed
	 */
	public static function mark_editor_preview( $result, $server, $request ) {
		if ( $request instanceof \WP_REST_Request && str_starts_with( $request->get_route(), '/wp/v2/block-renderer/vialcove/' ) ) {
			Renderer::$in_editor = true;
			Renderer::$document_id = 0;
			$post_id             = absint( $request->get_param( 'post_id' ) );
			if ( $post_id && Templates::LAYOUT === get_post_type( $post_id ) && current_user_can( 'edit_post', $post_id ) ) {
				Renderer::$document_id = $post_id;
			}
		}
		return $result;
	}

	/** Native REST must validate C before WordPress persists or sanitizes its content. */
	public static function preflight_rest_media( $prepared, $request ) {
		if ( is_wp_error( $prepared ) || ! isset( $prepared->post_content ) ) { return $prepared; }
		$rows = Layout::modules_from_content( (string) $prepared->post_content );
		if ( ! Rich_Media::has_images( $rows ) ) { return $prepared; }
		$id = (int) $request['id']; $context = Rich_Media::editing( $id );
		if ( ! $context ) { return Rich_Media::error( __( 'Save the design document before inserting rich text images.', 'peptide-core' ) ); }
		$checked = Rich_Media::preflight( $rows, $context );
		if ( is_wp_error( $checked ) ) { return $checked; }
		$blocks = parse_blocks( (string) $prepared->post_content );
		foreach ( $blocks as &$block ) {
			if ( ! Registry::type_for_block( (string) ( $block['blockName'] ?? '' ) ) ) { continue; }
			$clean = Layout::clean_modules( array( array( 'block' => $block['blockName'], 'attrs' => $block['attrs'] ?? array() ) ), $checked );
			if ( $clean ) { $block['attrs'] = $clean[0]['attrs']; }
		}
		unset( $block );
		$prepared->post_content = serialize_blocks( $blocks );
		return $prepared;
	}
	public static function approve_rest_media( $post, $request, $creating ): void {
		if ( ! $request->has_param( 'content' ) ) { return; }
		$context = Rich_Media::editing( (int) $post->ID );
		if ( ! $context ) { return; }
		$checked = Rich_Media::preflight( Layout::modules_from_content( (string) get_post_field( 'post_content', $post->ID ) ), $context );
		if ( ! is_wp_error( $checked ) ) { $checked->commit(); }
	}

	private static function is_layout_screen(): bool {
		if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		return $screen && Templates::LAYOUT === $screen->post_type;
	}

	/** 编辑器画布（iframe）里加载模块默认样式，保证预览和前台一致。 */
	public static function enqueue_canvas(): void {
		if ( self::is_layout_screen() ) {
			wp_enqueue_style( Renderer::STYLE );
		}
	}

	public static function enqueue_editor(): void {
		if ( ! self::is_layout_screen() ) {
			return;
		}
		self::enqueue_controls();
		wp_enqueue_script(
			self::EDITOR_SCRIPT,
			plugins_url( 'assets/module-editor.js', PEPTIDE_CORE_FILE ),
			array( self::CONTROLS_SCRIPT, 'wp-blocks', 'wp-block-editor', 'wp-server-side-render' ),
			PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( PEPTIDE_CORE_DIR . '/assets/module-editor.js' ), // phpcs:ignore WordPress.PHP.NoSilencedErrors
			array( 'in_footer' => true )
		);
	}

	/**
	 * 共用的字段控件（区块编辑器和装修编辑器都用），附带模块定义 window.pcModules。
	 */
	public static function enqueue_controls(): void {
		$url = plugins_url( 'assets/', PEPTIDE_CORE_FILE );
		$ver = static fn( string $file ): string => PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( PEPTIDE_CORE_DIR . '/assets/' . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_enqueue_style( self::EDITOR_SCRIPT, $url . 'module-editor.css', array( 'wp-components' ), $ver( 'module-editor.css' ) );
		wp_enqueue_script( self::ICONS_SCRIPT, $url . 'design-icons.js', array( 'wp-element' ), $ver( 'design-icons.js' ), array( 'in_footer' => true ) );
		wp_enqueue_script(
			self::CONTROLS_SCRIPT,
			$url . 'module-controls.js',
			array( self::ICONS_SCRIPT, 'wp-element', 'wp-components', 'wp-data', 'wp-core-data', 'wp-media-utils', 'wp-block-editor', 'wp-i18n', 'wp-api-fetch' ),
			$ver( 'module-controls.js' ),
			array( 'in_footer' => true )
		);
		wp_add_inline_script( self::CONTROLS_SCRIPT, 'window.pcModules = ' . wp_json_encode( self::editor_data(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
		wp_enqueue_media();
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function editor_data(): array {
		$modules = array();
		foreach ( Registry::all() as $type => $def ) {
			$items = null;
			if ( ! empty( $def['items'] ) ) {
				$items = $def['items'];
				$items['defaults'] = self::item_defaults( $def['items']['fields'] );
			}
			$modules[ $def['block'] ] = array(
				'multiple' => $def['multiple'] ?? ( 'hero_h1' !== $type ),
				'singleRender' => ! empty( $def['singleRender'] ),
				'type'     => $type,
				'title'    => $def['title'],
				'settings' => $def['settings'],
				'defaults' => self::item_defaults( $def['settings'] ),
				'panels'   => $def['panels'] ?? array(),
				'editorDefaults' => $def['editorDefaults'] ?? array(),
				'items'    => $items,
			);
		}

		$menus = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			$menus[] = array(
				'value' => (string) $menu->term_id,
				'label' => $menu->name,
			);
		}
		$cats = array();
		foreach ( get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) ) as $term ) {
			if ( $term instanceof \WP_Term ) {
				$cats[] = array(
					'value' => (string) $term->term_id,
					'label' => wp_specialchars_decode( $term->name, ENT_QUOTES ),
					'count' => (int) ( $term->count ?? 0 ),
				);
			}
		}
		$products = array();
		foreach ( wc_get_products( array( 'limit' => 20, 'status' => 'publish', 'orderby' => 'title', 'order' => 'ASC', 'type' => array( 'simple', 'variable' ) ) ) as $product ) {
			if ( ! $product->is_visible() || post_password_required( $product->get_id() ) ) { continue; }
			$products[] = array(
				'value' => (string) $product->get_id(),
				'label' => $product->get_name(),
			);
		}

		return array(
			'modules'    => $modules,
			'groups'     => Registry::groups(),
			'icons'      => Fields::ICONS,
			'menus'      => $menus,
			'menuPicker' => array(
				'path' => function_exists( 'rest_get_route_for_taxonomy_items' ) ? rest_get_route_for_taxonomy_items( 'nav_menu' ) : '/wp/v2/menus',
				'canRefresh' => current_user_can( \PeptideCore\Menus::CAP ),
				'canManage' => current_user_can( \PeptideCore\Menus::CAP ),
				'manageUrl' => current_user_can( \PeptideCore\Menus::CAP ) ? \PeptideCore\Admin\Menu_Page::url() : '',
			),
			'categories' => $cats,
			'products'   => $products,
			'recordLookup' => array( 'url' => admin_url( 'admin-ajax.php' ), 'security' => wp_create_nonce( 'pc_layout_records' ) ),
			'youtubeMetadata' => \PeptideCore\YouTube_Metadata::editor_config(),
			'linkLookup' => Links::config(),
			'strings'    => array(
				'desktop'      => __( 'Desktop', 'peptide-core' ),
				'mobile'       => __( 'Mobile', 'peptide-core' ),
				'editing'      => __( 'Editing values for', 'peptide-core' ),
				'sameAsDesk'   => __( 'Same as desktop', 'peptide-core' ),
				'add'          => __( 'Add', 'peptide-core' ),
				'duplicate'    => __( 'Duplicate', 'peptide-core' ),
				'remove'       => __( 'Delete', 'peptide-core' ),
				'moveUp'       => __( 'Move up', 'peptide-core' ),
				'moveDown'     => __( 'Move down', 'peptide-core' ),
				'hide'         => __( 'Hide', 'peptide-core' ),
				'show'         => __( 'Show', 'peptide-core' ),
				'hidden'       => __( 'hidden', 'peptide-core' ),
				'item'         => __( 'Item %d', 'peptide-core' ),
				'confirmDel'   => __( 'Delete this item?', 'peptide-core' ),
				'chooseImage'  => __( 'Choose image', 'peptide-core' ),
				'chooseVideo'  => __( 'Choose video', 'peptide-core' ),
				'replace'      => __( 'Replace', 'peptide-core' ),
				'clear'        => __( 'Remove', 'peptide-core' ),
				'alt'          => __( 'Alt text (describe the image)', 'peptide-core' ),
				'desktopImage' => __( 'Desktop image', 'peptide-core' ),
				'mobileImage'  => __( 'Mobile image (empty = use desktop image)', 'peptide-core' ),
				'fit'          => __( 'Fit', 'peptide-core' ),
				'fitCover'     => __( 'Fill (crop)', 'peptide-core' ),
				'fitContain'   => __( 'Show whole image', 'peptide-core' ),
				'focal'        => __( 'Focus point', 'peptide-core' ),
				'label'        => __( 'Text', 'peptide-core' ),
				'href'         => __( 'Link', 'peptide-core' ),
				'newTab'       => __( 'Open in a new tab', 'peptide-core' ),
				'none'         => __( '— None —', 'peptide-core' ),
				'defaultValue' => __( 'Default', 'peptide-core' ),
				'clearColor'   => __( 'Use default color', 'peptide-core' ),
				'minItems'     => __( 'At least %d item(s) are required.', 'peptide-core' ),
				'maxItems'     => __( 'Up to %d items.', 'peptide-core' ),
				'hiddenModule' => __( 'Hide this module', 'peptide-core' ),
				'htmlHelp'     => __( 'Allowed: paragraphs, bold, italic, links, lists.', 'peptide-core' ),
				'searchProduct' => __( 'Search products', 'peptide-core' ),
				'mobileOnly'    => __( 'Changed for mobile only.', 'peptide-core' ),
			),
		);
	}

	/**
	 * 字段默认值（编辑器新建模块 / 条目时使用）。
	 *
	 * @param array<int,array<string,mixed>> $fields
	 * @return array<string,mixed>
	 */
	public static function item_defaults( array $fields ): array {
		$out = array();
		foreach ( $fields as $field ) {
			$out[ $field['key'] ] = Fields::default_value( $field );
		}
		return $out;
	}
}
