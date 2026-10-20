<?php
/**
 * 装修文档的读取、保存与渲染（EDIT-BLOCKS-04）。
 *
 * 文档内容（post_content）用区块格式保存：<!-- wp:vialcove/<type> {...} /-->，所以 WordPress 的修订、
 * 区块编辑器都能直接使用同一份数据，不另外维护一份 JSON。
 * 装修编辑器（YY 式界面）通过 peptide/v1/layouts 接口读写模块列表；实时预览使用短期草稿（transient），
 * 不会每次改动都产生修订。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Modules;

use PeptideCore\Templates;

defined( 'ABSPATH' ) || exit;

final class Layout {

	public const PREVIEW_PARAM = 'pc_preview_token';
	public const PREVIEW_TTL   = 1800;

	/**
	 * 从区块内容中取出模块：[{block, attrs}]。非模块区块忽略。
	 *
	 * @return array<int,array{block:string,attrs:array<string,mixed>}>
	 */
	public static function modules_from_content( string $content ): array {
		$out = array();
		foreach ( parse_blocks( $content ) as $block ) {
			$name = (string) ( $block['blockName'] ?? '' );
			if ( '' !== $name && Registry::type_for_block( $name ) ) {
				$out[] = array(
					'block' => $name,
					'attrs' => is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array(),
				);
			}
		}
		return $out;
	}

	/**
	 * 清理模块列表（类型必须已注册；字段按定义清理；ID 不重复），返回 [{block, attrs}]。
	 *
	 * @param mixed $modules
	 * @return array<int,array{block:string,attrs:array<string,mixed>}>
	 */
	public static function clean_modules( $modules ): array {
		$out  = array();
		$seen = array();
		foreach ( is_array( $modules ) ? array_slice( $modules, 0, 80 ) : array() as $row ) {
			$block = is_array( $row ) ? (string) ( $row['block'] ?? '' ) : '';
			$type  = Registry::type_for_block( $block );
			if ( ! $type ) {
				continue;
			}
			$m = Renderer::normalize( $type, is_array( $row['attrs'] ?? null ) ? $row['attrs'] : array() );
			while ( isset( $seen[ $m['id'] ] ) ) {
				$m['id'] = Fields::id( '' );
			}
			$seen[ $m['id'] ] = true;
			$out[]            = array(
				'block' => $block,
				'attrs' => array(
					'moduleId' => $m['id'],
					'version'  => $m['version'],
					'enabled'  => $m['enabled'],
					'settings' => $m['settings'],
					'items'    => $m['items'],
				),
			);
		}
		return $out;
	}

	/**
	 * 模块列表 → 区块内容。
	 *
	 * @param array<int,array{block:string,attrs:array<string,mixed>}> $modules
	 */
	public static function content_from_modules( array $modules ): string {
		$blocks = array();
		foreach ( $modules as $m ) {
			$blocks[] = serialize_block(
				array(
					'blockName'    => $m['block'],
					'attrs'        => $m['attrs'],
					'innerBlocks'  => array(),
					'innerHTML'    => '',
					'innerContent' => array(),
				)
			);
		}
		return implode( "\n\n", $blocks );
	}

	/**
	 * 渲染模块列表。
	 *
	 * @param array<int,array{block:string,attrs:array<string,mixed>}> $modules
	 */
	public static function render_modules( array $modules, string $context, int $document_id = 0 ): string {
		$previous              = Renderer::$document_id;
		Renderer::$document_id = $document_id;
		$html                  = '';
		foreach ( $modules as $m ) {
			$type = Registry::type_for_block( $m['block'] );
			if ( $type ) {
				$html .= Renderer::render( $type, $m['attrs'], $context );
			}
		}
		Renderer::$document_id = $previous;
		return $html;
	}

	// ---------- 预览草稿 ----------

	/**
	 * 保存一份短期预览草稿，返回令牌。令牌只对当前用户有效。
	 *
	 * @param array<int,array{block:string,attrs:array<string,mixed>}> $modules
	 */
	public static function store_preview( int $document_id, array $modules ): string {
		$token = strtolower( wp_generate_password( 20, false ) );
		set_transient(
			'pc_layout_preview_' . $token,
			array(
				'user'     => get_current_user_id(),
				'document' => $document_id,
				'modules'  => $modules,
			),
			self::PREVIEW_TTL
		);
		return $token;
	}

	/**
	 * 本次请求的预览草稿（令牌有效、属于当前用户、文档属于正在预览的模板）。
	 *
	 * @return array{document:int,modules:array<int,array<string,mixed>>}|null
	 */
	public static function preview_draft( int $template_id, string $doc ): ?array {
		$token = isset( $_GET[ self::PREVIEW_PARAM ] ) ? sanitize_key( wp_unslash( $_GET[ self::PREVIEW_PARAM ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 预览模板本身已校验 nonce；令牌只在服务器保存且绑定用户。
		if ( '' === $token ) {
			return null;
		}
		$data = get_transient( 'pc_layout_preview_' . $token );
		if ( ! is_array( $data ) || (int) $data['user'] !== get_current_user_id() || (int) $data['document'] !== Templates::document_id( $template_id, $doc ) ) {
			return null;
		}
		return array(
			'document' => (int) $data['document'],
			'modules'  => (array) $data['modules'],
		);
	}

	// ---------- 前台预览页 ----------

	public static function register_hooks(): void {
		add_filter( 'template_include', array( self::class, 'preview_template' ), 99 );
	}

	/**
	 * 预览模板时，首页由插件输出：主题的页头 + 模板首页的模块 + 主题的页脚。
	 * 公开访客不受影响（还没有发布功能，公开首页仍是主题自带的页面）。
	 * 主题可以提供 peptide-layout.php 自己接管这个页面。
	 */
	public static function preview_template( string $template ): string {
		$template_id = Templates::previewing_id();
		if ( ! $template_id || ! ( is_front_page() || is_home() ) ) {
			return $template;
		}
		$theme = locate_template( 'peptide-layout.php' );
		return $theme ? $theme : PEPTIDE_CORE_DIR . '/templates/layout-page.php';
	}

	/**
	 * 预览页的内容：有预览草稿用草稿，否则用文档当前保存的内容。
	 */
	public static function render_preview( string $doc = 'home' ): string {
		$template_id = Templates::previewing_id();
		$doc_id      = Templates::document_id( $template_id, $doc );
		$draft       = self::preview_draft( $template_id, $doc );
		$modules     = $draft ? $draft['modules'] : self::modules_from_content( (string) get_post_field( 'post_content', $doc_id ) );
		if ( ! $modules ) {
			return '<div class="vc-m"><div class="vc-m__inner">' . Renderer::notice( __( 'This page has no modules yet. Add modules in the design editor.', 'peptide-core' ) ) . '</div></div>';
		}
		return self::render_modules( $modules, 'preview', $doc_id );
	}
}
