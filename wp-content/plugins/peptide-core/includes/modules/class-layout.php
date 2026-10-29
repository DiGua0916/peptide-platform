<?php
/**
 * 装修文档的读取、保存与渲染（EDIT-BLOCKS-04）。
 *
 * 文档内容（post_content）用区块格式保存：<!-- wp:vialcove/<type> {...} /-->，所以 WordPress 的修订、
 * 区块编辑器都能直接使用同一份数据，不另外维护一份 JSON。
 * 装修编辑器（YY 式界面）通过 peptide/v1/layouts 接口读写模块列表；实时预览使用短期草稿（transient），
 * 不会每次改动都产生修订。
 *
 * 发布（EDIT-BLOCKS-05）：把草稿复制成“已发布快照”（meta），访客只看快照；之后修改草稿不影响网站，直到再次发布。
 * 当前模板（预览 > 域名指定 > 店铺默认）的首页有已发布快照时，首页由插件输出；没有时仍是主题原来的首页。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Modules;

use PeptideCore\Templates;

defined( 'ABSPATH' ) || exit;

final class Layout {

	public const PREVIEW_PARAM = 'pc_preview_token';
	public const PREVIEW_TTL   = 1800;

	public const PUBLISHED_META     = '_pc_published_content';
	public const PUBLISHED_REVISION = '_pc_published_revision';
	public const PUBLISHED_AT       = '_pc_published_at';
	public const PUBLISHED_BY       = '_pc_published_by';

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
			$attrs_out = array(
				'moduleId' => $m['id'],
				'version'  => $m['version'],
				'enabled'  => $m['enabled'],
				'settings' => $m['settings'],
				'items'    => $m['items'],
			);
			// 手机版单独设置：没有时不保存，保持内容简洁。
			if ( Renderer::has_mobile( $m ) ) {
				$attrs_out['mobile'] = $m['mobile'];
			}
			$out[] = array(
				'block' => $block,
				'attrs' => $attrs_out,
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

	// ---------- 发布 ----------

	public static function is_published( int $document_id ): bool {
		return metadata_exists( 'post', $document_id, self::PUBLISHED_META );
	}

	/**
	 * 把当前草稿发布到网站。
	 */
	public static function publish( int $document_id ): void {
		$content   = (string) get_post_field( 'post_content', $document_id );
		$revisions = wp_get_post_revisions( $document_id, array( 'numberposts' => 1, 'fields' => 'ids' ) );
		update_post_meta( $document_id, self::PUBLISHED_META, wp_slash( $content ) );
		update_post_meta( $document_id, self::PUBLISHED_REVISION, $revisions ? (int) reset( $revisions ) : 0 );
		update_post_meta( $document_id, self::PUBLISHED_AT, current_time( 'mysql', true ) );
		update_post_meta( $document_id, self::PUBLISHED_BY, get_current_user_id() );
		/**
		 * 装修发布后触发（可用于清理页面缓存）。
		 *
		 * @param int $document_id 装修文档 ID
		 */
		do_action( 'peptide_core_layout_published', $document_id );
	}

	/**
	 * 取消发布：网站恢复主题原来的页面。草稿保留。
	 */
	public static function unpublish( int $document_id ): void {
		foreach ( array( self::PUBLISHED_META, self::PUBLISHED_REVISION, self::PUBLISHED_AT, self::PUBLISHED_BY ) as $key ) {
			delete_post_meta( $document_id, $key );
		}
		do_action( 'peptide_core_layout_published', $document_id );
	}

	/**
	 * 放弃未发布的修改：草稿恢复成已发布的版本（会留下一条修订，可以再找回）。
	 */
	public static function discard_draft( int $document_id ): bool {
		if ( ! self::is_published( $document_id ) ) {
			return false;
		}
		$result = wp_update_post(
			array(
				'ID'           => $document_id,
				'post_content' => wp_slash( (string) get_post_meta( $document_id, self::PUBLISHED_META, true ) ),
			),
			true
		);
		return ! is_wp_error( $result );
	}

	/**
	 * @return array{published:bool,at:?string,by:?string,changed:bool}
	 */
	public static function status( int $document_id ): array {
		$published = self::is_published( $document_id );
		$user      = $published ? get_userdata( (int) get_post_meta( $document_id, self::PUBLISHED_BY, true ) ) : null;
		$at        = $published ? (string) get_post_meta( $document_id, self::PUBLISHED_AT, true ) : '';
		return array(
			'published' => $published,
			'at'        => '' !== $at ? get_date_from_gmt( $at, 'Y-m-d H:i' ) : null,
			'by'        => $user ? $user->display_name : null,
			'changed'   => ! $published || (string) get_post_meta( $document_id, self::PUBLISHED_META, true ) !== (string) get_post_field( 'post_content', $document_id ),
		);
	}

	/**
	 * 当前模板某份文档已发布的模块；没有发布时为空。
	 *
	 * @return array<int,array{block:string,attrs:array<string,mixed>}>
	 */
	public static function published_modules( string $doc = 'home', ?int $template_id = null ): array {
		$template_id = $template_id ?? Templates::current_id();
		$doc_id      = $template_id ? Templates::document_id( $template_id, $doc, false ) : 0;
		if ( ! $doc_id || ! self::is_published( $doc_id ) ) {
			return array();
		}
		return self::modules_from_content( (string) get_post_meta( $doc_id, self::PUBLISHED_META, true ) );
	}

	/**
	 * 本次请求是否由插件输出这个页面：员工预览模板时，或当前模板已发布且有模块时。
	 */
	public static function takes_over( string $doc = 'home' ): bool {
		if ( Templates::previewing_id() ) {
			$id = Templates::document_id( Templates::previewing_id(), $doc, false );
			$draft = self::preview_draft( Templates::previewing_id(), $doc );
			return $draft ? (bool) $draft['modules'] : ( $id > 0 && '' !== trim( (string) get_post_field( 'post_content', $id ) ) );
		}
		return (bool) self::published_modules( $doc );
	}

	/**
	 * 本次请求应该显示的内容：预览时是草稿（或实时预览令牌），否则是已发布版本。
	 */
	public static function render_current( string $doc = 'home' ): string {
		if ( Templates::previewing_id() ) {
			return self::render_preview( $doc );
		}
		$template_id = Templates::current_id();
		return self::render_modules( self::published_modules( $doc, $template_id ), 'public', Templates::document_id( $template_id, $doc, false ) );
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
		if ( ! is_array( $data ) || (int) $data['user'] !== get_current_user_id() || (int) $data['document'] !== Templates::document_id( $template_id, $doc, false ) ) {
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
	 * 首页由插件输出（主题的页头 + 模块 + 主题的页脚）的两种情况：员工预览模板，或当前模板的首页已经发布。
	 * 其余情况（包括从未发布）仍使用主题原来的首页模板，可以随时回退。
	 * 主题可以提供 peptide-layout.php 自己接管这个页面。
	 */
	public static function preview_template( string $template ): string {
		if ( class_exists( '\PeptideCore\Design_Pages' ) ) {
			return \PeptideCore\Design_Pages::template_page( $template );
		}
		if ( ! ( is_front_page() || is_home() ) || ! self::takes_over( 'home' ) ) {
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
		$doc_id      = Templates::document_id( $template_id, $doc, false );
		$draft       = self::preview_draft( $template_id, $doc );
		$modules     = $draft ? $draft['modules'] : self::modules_from_content( (string) get_post_field( 'post_content', $doc_id ) );
		if ( ! $modules ) {
			return '<div class="vc-m"><div class="vc-m__inner">' . Renderer::notice( __( 'This page has no modules yet. Add modules in the design editor.', 'peptide-core' ) ) . '</div></div>';
		}
		return self::render_modules( $modules, 'preview', $doc_id );
	}
}
