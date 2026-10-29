<?php
/**
 * 装修编辑器（EDIT-BLOCKS-04）：参照 YY（Shopname）的装修界面。
 *
 * 顶部：返回、页面选择、电脑 / 手机 / 全屏预览、撤销 / 重做、查看店铺、保存、发布。
 * 左侧：整页实时预览（主题的页头页脚 + 模块），点击预览里的模块即可选中。
 * 右侧：积木装修 / 全局配置；积木列表（头部固定在上、底部固定在下，可拖动排序、复制、隐藏、删除、添加），
 *       旁边是选中积木的设置面板或积木库。
 *
 * 入口：店铺装修 → 主题装修 → “装修”。地址：admin.php?page=pc-design-editor&document=<装修文档 ID>。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Api\Layout_Controller;
use PeptideCore\Modules\Blocks;
use PeptideCore\Modules\Registry;
use PeptideCore\Templates;

defined( 'ABSPATH' ) || exit;

final class Design_Editor {

	public const SLUG = 'pc-design-editor';

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ), 60 );
		add_action( 'admin_head', array( self::class, 'hide_menu_item' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_filter( 'admin_body_class', array( self::class, 'body_class' ) );
	}

	public static function url( int $document_id ): string {
		return admin_url( 'admin.php?page=' . self::SLUG . '&document=' . $document_id );
	}

	/**
	 * 注册在“店铺装修”下（这样才有访问权限），菜单里用样式隐藏这一项；从“主题装修”的“装修”按钮进入。
	 * 不能用 remove_submenu_page()：去掉菜单项后 WordPress 会拒绝访问这个页面。
	 */
	public static function add_page(): void {
		add_submenu_page( Store_Design::SLUG, __( 'Design editor', 'peptide-core' ), __( 'Design editor', 'peptide-core' ), Templates::CAP_EDIT, self::SLUG, array( self::class, 'render' ) );
	}

	public static function hide_menu_item(): void {
		echo '<style>#adminmenu a[href$="page=' . esc_attr( self::SLUG ) . '"]{display:none}</style>';
	}

	private static function is_page(): bool {
		return isset( $_GET['page'] ) && self::SLUG === $_GET['page']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	private static function document_id(): int {
		$id = isset( $_GET['document'] ) ? absint( $_GET['document'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return Templates::LAYOUT === get_post_type( $id ) && current_user_can( 'edit_post', $id ) ? $id : 0;
	}

	public static function body_class( string $classes ): string {
		return self::is_page() ? $classes . ' pc-design-editor-page' : $classes;
	}

	public static function enqueue(): void {
		if ( ! self::is_page() ) {
			return;
		}
		$doc_id = self::document_id();
		if ( ! $doc_id ) {
			return;
		}
		$document_key = (string) get_post_meta( $doc_id, Templates::DOC_META, true );
		\PeptideCore\Design_Pages::seed( $doc_id, $document_key );
		require_once ABSPATH . 'wp-admin/includes/post.php';
		wp_set_post_lock( $doc_id );

		Blocks::enqueue_controls();
		$url = plugins_url( 'assets/', PEPTIDE_CORE_FILE );
		$ver = static fn( string $file ): string => PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( PEPTIDE_CORE_DIR . '/assets/' . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_enqueue_style( 'peptide-core-design-editor', $url . 'design-editor.css', array( 'wp-components', Blocks::EDITOR_SCRIPT ), $ver( 'design-editor.css' ) );
		wp_enqueue_script( 'peptide-core-design-editor', $url . 'design-editor.js', array( Blocks::CONTROLS_SCRIPT, 'wp-api-fetch', 'wp-element', 'wp-components' ), $ver( 'design-editor.js' ), array( 'in_footer' => true ) );

		$template = (int) wp_get_post_parent_id( $doc_id );
		$pages = array();
		$document_links = array();
		$document_labels = Templates::documents();
		if ( ! isset( $document_labels[ $document_key ] ) ) {
			$document_labels[ $document_key ] = \PeptideCore\Design_Pages::label( $document_key );
		}
		foreach ( $document_labels as $key => $label ) {
			$document_links[ $key ] = html_entity_decode( \PeptideCore\Design_Pages::open_url( $template, $key ), ENT_QUOTES, 'UTF-8' );
			$pages[] = array( 'value' => $key, 'label' => $label, 'enabled' => true, 'url' => $document_links[ $key ] );
		}
		$library  = array();
		foreach ( Registry::categories() as $cat => $label ) {
			$cards = array();
			foreach ( Registry::all() as $def ) {
				if ( in_array( $document_key, array( 'header', 'footer', 'global' ), true ) && 'context_content' === $def['type'] ) { continue; }
				$special = array( 'site_header' => 'header', 'site_footer' => 'footer', 'site_styles' => 'global' );
				if ( isset( $special[ $def['type'] ] ) && $special[ $def['type'] ] !== $document_key ) { continue; }
				if ( 'global' === $document_key && 'site_styles' !== $def['type'] ) { continue; }
				if ( $def['category'] === $cat ) {
					$cards[] = array(
						'block' => $def['block'],
						'title' => $def['title'],
						'icon'  => $def['icon'] ?? 'screenoptions',
					);
				}
			}
			if ( $cards ) {
				$library[] = array(
					'label' => $label,
					'cards' => $cards,
				);
			}
		}

		wp_add_inline_script(
			'peptide-core-design-editor',
			'window.pcDesignEditor = ' . wp_json_encode(
				array(
					'document'  => Layout_Controller::document( $doc_id ),
					'restPath'  => '/peptide/v1/layouts/' . $doc_id,
					'backUrl'   => admin_url( 'admin.php?page=' . Store_Design::SLUG ),
					'storeUrl'  => \PeptideCore\Design_Pages::sample_url( $document_key ),
					'documentLinks' => $document_links,
					'canPublish' => current_user_can( Templates::CAP_MANAGE ),
					'library'   => $library,
					'pages'     => $pages,
					'strings'   => array(
						'back'          => __( 'Back to theme design', 'peptide-core' ),
						'page'          => __( 'Page', 'peptide-core' ),
						'desktop'       => __( 'Desktop preview', 'peptide-core' ),
						'mobile'        => __( 'Mobile preview', 'peptide-core' ),
						'full'          => __( 'Full-screen preview', 'peptide-core' ),
						'undo'          => __( 'Undo', 'peptide-core' ),
						'redo'          => __( 'Redo', 'peptide-core' ),
						'viewStore'     => __( 'View store', 'peptide-core' ),
						'save'          => __( 'Save draft', 'peptide-core' ),
						'saving'        => __( 'Saving…', 'peptide-core' ),
						'saved'         => __( 'Draft saved', 'peptide-core' ),
						'publish'       => __( 'Publish', 'peptide-core' ),
						'publishMenu'      => __( 'More publishing options', 'peptide-core' ),
						'publishConfirm'   => __( 'Publish this design to the storefront? Visitors will see it right away. Unsaved changes are saved first.', 'peptide-core' ),
						'published'        => __( 'Published. Visitors now see this design.', 'peptide-core' ),
						'publishDenied'    => __( 'Only administrators can publish designs to the storefront.', 'peptide-core' ),
						'working'          => __( 'Working…', 'peptide-core' ),
						'statusDraft'      => __( 'Not published: visitors see the theme\'s original page', 'peptide-core' ),
						/* translators: %s: date and time */
						'statusLive'       => __( 'Published %s', 'peptide-core' ),
						'statusChanged'    => __( 'Published · has unpublished changes', 'peptide-core' ),
						'discard'          => __( 'Discard unpublished changes (back to the published version)', 'peptide-core' ),
						'discardConfirm'   => __( 'Replace your draft with the published version? The current draft stays in the revision history.', 'peptide-core' ),
						'discarded'        => __( 'The draft is back to the published version.', 'peptide-core' ),
						'unpublish'        => __( 'Unpublish (show the theme\'s original page again)', 'peptide-core' ),
						'unpublishConfirm' => __( 'Unpublish this design? Visitors will see the theme\'s original page again. Your draft is kept.', 'peptide-core' ),
						'unpublished'      => __( 'Unpublished. Visitors see the theme\'s original page.', 'peptide-core' ),
						'tabModules'    => __( 'Block design', 'peptide-core' ),
						'tabGlobal'     => __( 'Global settings', 'peptide-core' ),
						'header'        => __( 'Header', 'peptide-core' ),
						'footer'        => __( 'Footer', 'peptide-core' ),
						'addModule'     => __( 'Add block', 'peptide-core' ),
						'singleton'     => __( 'This block can only appear once on a page.', 'peptide-core' ),
						'library'       => __( 'Block library', 'peptide-core' ),
						'libraryHint'   => __( 'Click a card to add it below the selected block.', 'peptide-core' ),
						'duplicate'     => __( 'Duplicate', 'peptide-core' ),
						'hide'          => __( 'Hide', 'peptide-core' ),
						'show'          => __( 'Show', 'peptide-core' ),
						'remove'        => __( 'Delete', 'peptide-core' ),
						'more'          => __( 'More', 'peptide-core' ),
						'confirmDelete' => __( 'Delete this block? You can undo this until you leave the editor.', 'peptide-core' ),
						'dragHint'      => __( 'Drag to reorder', 'peptide-core' ),
						'emptyList'     => __( 'No blocks yet. Click "Add block" to start.', 'peptide-core' ),
						'headerNote'    => __( 'The header (announcement bar, logo, menu, search, account and cart) is shown by the theme for now. Editing it here comes next, together with the footer.', 'peptide-core' ),
						'footerNote'    => __( 'The footer is shown by the theme for now. Editing it here comes next.', 'peptide-core' ),
						'globalNote'    => __( 'Global settings (colors, fonts, content width, spacing, button corners) come next. They will apply to every block.', 'peptide-core' ),
						'unsaved'       => __( 'You have unsaved changes.', 'peptide-core' ),
						'conflict'      => __( 'Reload', 'peptide-core' ),
						'lockedBy'      => __( '%s is also editing this design. Saving may overwrite their changes.', 'peptide-core' ),
						'loadingPreview' => __( 'Updating preview…', 'peptide-core' ),
						'selectHint'    => __( 'Select a block on the left of this panel, or click it in the preview.', 'peptide-core' ),
						'previewNote'   => __( 'Live preview of what you are editing. Only you can see it.', 'peptide-core' ),
						'editing'       => __( 'Editing values for', 'peptide-core' ),
						'mobileEditing'  => __( 'Mobile version: changes here only affect phones. Anything you do not change stays the same as on desktop.', 'peptide-core' ),
						'sharedDeviceSettings' => __( '内容和绑定共用；带设备选项的布局分别生效。', 'peptide-core' ),
						/* translators: %d: number of changed settings */
						'mobileChanged'  => __( '%d setting(s) changed for mobile.', 'peptide-core' ),
						'mobileResetAll' => __( 'Make mobile the same as desktop again', 'peptide-core' ),
						/* translators: %d: item number */
						'itemN'         => __( 'Item %d', 'peptide-core' ),
					),
				)
			) . ';',
			'before'
		);
	}

	public static function render(): void {
		$doc_id = self::document_id();
		if ( ! $doc_id ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Design editor', 'peptide-core' ) . '</h1><p>' . esc_html__( 'Design document not found.', 'peptide-core' ) . '</p></div>';
			return;
		}
		echo '<div id="pc-design-editor" class="pc-de" aria-busy="true"><p class="pc-de__loading">' . esc_html__( 'Loading the design editor…', 'peptide-core' ) . '</p></div>';
	}
}
