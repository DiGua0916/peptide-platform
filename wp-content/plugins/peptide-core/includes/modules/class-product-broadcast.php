<?php
/** A layout reference to the store's published product broadcast, never a second copy of its text. */
namespace PeptideCore\Modules;

use PeptideCore\Broadcast\Store;

defined( 'ABSPATH' ) || exit;

final class Product_Broadcast {

	public const TYPE = 'product_broadcast';
	public const CONFIG_ID = 'default';
	private static bool $registered = false;

	public static function register_hooks(): void {
		if ( self::$registered ) { return; }
		self::$registered = true;
		add_filter( 'peptide_core_layout_definitions', array( self::class, 'definitions' ) );
		add_filter( 'peptide_core_module_html', array( self::class, 'render' ), 10, 4 );
		add_filter( 'peptide_core_module_wrapper', array( self::class, 'wrapper' ), 10, 3 );
	}

	public static function definitions( array $definitions ): array {
		$definitions[ self::TYPE ] = array(
			'title' => __( '商品播报', 'peptide-core' ),
			'description' => __( '引用应用中心已应用的店铺播报。文案仅在应用中心维护，商品变量由当前商品提供。', 'peptide-core' ),
			'category' => 'operation', 'icon' => 'visibility', 'version' => 1, 'singleRender' => true,
			'editorDefaults' => array( 'paddingTopPx' => array( 'desktop' => 0, 'mobile' => 0 ), 'paddingBottomPx' => array( 'desktop' => 0, 'mobile' => 0 ) ),
			'settings' => array(
				Fields::f( 'select', 'configId', __( '播报配置', 'peptide-core' ), self::CONFIG_ID, array(
					'group' => 'general',
					'options' => array( self::CONFIG_ID => __( '当前店铺共用配置', 'peptide-core' ) ),
					'help' => __( '在应用中心 → 商品播报编辑和应用文案；本积木只保存引用。关闭积木不影响其他引用。', 'peptide-core' ),
				) ),
			),
		);
		return $definitions;
	}

	/** Public-only configuration, including when called from a layout draft preview. */
	public static function config(): array {
		return Store::effective();
	}

	public static function config_url(): string {
		return rest_url( 'peptide/v1/product-broadcast' );
	}

	/**
	 * Safe inert SSR contract. The frontend owns mounting, context, playback and removal.
	 * Hidden remains set until the frontend has successfully configured its controller.
	 */
	public static function markup(): string {
		$config = self::config();
		if ( empty( $config['enabled'] ) || empty( $config['items'] ) || ! is_array( $config['items'] ) ) { return ''; }
		$json = wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) { return ''; }
		return '<div class="pc-product-broadcast" data-pc-product-broadcast data-config-id="' . esc_attr( self::CONFIG_ID ) . '" data-revision="' . (int) ( $config['revision'] ?? 0 ) . '" data-config-url="' . esc_url( self::config_url() ) . '" hidden>'
			. '<script type="application/json" class="pc-product-broadcast__config">' . $json . '</script></div>';
	}

	public static function render( $html, array $module, string $context, string $device = 'all' ) {
		if ( self::TYPE !== ( $module['type'] ?? '' ) ) { return $html; }
		if ( 'editor' === $context ) {
			return '<div class="vc-m__notice"><strong>' . esc_html__( '商品播报', 'peptide-core' ) . '</strong><p>'
				. esc_html__( '引用店铺已应用配置。文案、姓名和播放时间在应用中心维护；未应用草稿不会显示给客户。', 'peptide-core' )
				. '</p><a href="' . esc_url( admin_url( 'admin.php?page=pc-apps&app=product-broadcast' ) ) . '" target="_blank" rel="noopener">'
				. esc_html__( '打开商品播报设置', 'peptide-core' ) . '</a></div>';
		}
		return self::markup();
	}

	/** Keep the outer layout spacing inert too, including manually supplied padding. */
	public static function wrapper( string $html, array $module, bool $editor ): string {
		if ( $editor || self::TYPE !== ( $module['type'] ?? '' ) || '' === $html ) { return $html; }
		$processor = new \WP_HTML_Tag_Processor( $html );
		if ( ! $processor->next_tag( 'SECTION' ) ) { return ''; }
		$processor->set_attribute( 'hidden', true );
		$processor->set_attribute( 'data-pc-product-broadcast-shell', '' );
		return $processor->get_updated_html();
	}
}
