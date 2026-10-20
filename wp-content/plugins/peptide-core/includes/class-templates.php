<?php
/**
 * 店铺装修模板（EDIT-BLOCKS-02）。
 *
 * 一个模板（pc_template）是一整套装修：首页、页头、页脚、全局样式各是一份装修文档（pc_layout，post_parent 指向模板）。
 * 店铺有一个默认模板；还可以给某个域名指定别的模板（需要该域名也解析到本站）。
 * 有权限的人可以通过带 nonce 的地址预览任意模板，访客只会看到默认或域名指定的模板。
 *
 * 模块编辑、草稿与发布在后续步骤实现；本类只负责模板本身的增删改、默认模板、域名指定和预览。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Templates {

	public const TEMPLATE = 'pc_template';
	public const LAYOUT   = 'pc_layout';

	public const ACTIVE_OPTION = 'peptide_core_active_template';
	public const DOMAIN_OPTION = 'peptide_core_template_domains';

	public const NOTE_META  = '_pc_note';
	public const THEME_META = '_pc_theme';
	public const DOC_META   = '_pc_document';

	/** 编辑、复制、新建模板的能力（管理员和店长）。 */
	public const CAP_EDIT = 'edit_pc_layouts';
	/** 设为默认、域名指定、删除、发布的能力（只有管理员）。 */
	public const CAP_MANAGE = 'pc_publish_layouts';

	/** 只有删除整个模板时，才允许把它的文档移到回收站。 */
	private static bool $trashing_template = false;

	public const PREVIEW_PARAM = 'pc_template_preview';
	public const NONCE_PARAM   = 'pc_preview_nonce';
	public const FRAME_PARAM   = 'pc_frame';

	/**
	 * 每个模板包含的装修文档。
	 *
	 * @return array<string,string> 文档键 => 名称
	 */
	public static function documents(): array {
		return array(
			'home'   => __( 'Homepage', 'peptide-core' ),
			'header' => __( 'Header', 'peptide-core' ),
			'footer' => __( 'Footer', 'peptide-core' ),
			'global' => __( 'Global styles', 'peptide-core' ),
		);
	}

	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'register' ) );
		add_action( 'send_headers', array( self::class, 'preview_headers' ) );
		add_filter( 'show_admin_bar', array( self::class, 'hide_admin_bar_in_frame' ) );
		add_action( 'wp_footer', array( self::class, 'render_preview_bar' ) );
		add_filter( 'map_meta_cap', array( self::class, 'protect_documents' ), 10, 4 );
	}

	/**
	 * 装修文档不能单独删除（编辑器里的“移到回收站”），只能随模板一起删除，避免模板缺少文档。
	 *
	 * @param string[] $caps
	 * @param mixed[]  $args
	 * @return string[]
	 */
	public static function protect_documents( $caps, $cap, $user_id, $args ) {
		if ( 'delete_post' === $cap && ! self::$trashing_template && ! empty( $args[0] ) && self::LAYOUT === get_post_type( (int) $args[0] ) ) {
			return array( 'do_not_allow' );
		}
		return $caps;
	}

	public static function register(): void {
		$common = array(
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => false,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'map_meta_cap'        => true,
			'capability_type'     => array( 'pc_layout', 'pc_layouts' ),
		);

		register_post_type(
			self::TEMPLATE,
			$common + array(
				'labels'       => array(
					'name'          => __( 'Store templates', 'peptide-core' ),
					'singular_name' => __( 'Store template', 'peptide-core' ),
				),
				'supports'     => array( 'title', 'author' ),
				'show_in_rest' => false,
			)
		);

		register_post_type(
			self::LAYOUT,
			$common + array(
				'labels'       => array(
					'name'          => __( 'Design documents', 'peptide-core' ),
					'singular_name' => __( 'Design document', 'peptide-core' ),
					'edit_item'     => __( 'Edit design', 'peptide-core' ),
				),
				// 用区块编辑器编辑模块；修订用于历史版本。
				'supports'     => array( 'title', 'editor', 'revisions', 'author', 'custom-fields' ),
				'show_in_rest' => true,
				'hierarchical' => false,
			)
		);
	}

	// ---------- 读取 ----------

	/**
	 * 所有模板，按修改时间倒序。
	 *
	 * @return \WP_Post[]
	 */
	public static function all(): array {
		return get_posts(
			array(
				'post_type'      => self::TEMPLATE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);
	}

	public static function exists( int $template_id ): bool {
		return $template_id > 0 && self::TEMPLATE === get_post_type( $template_id ) && 'publish' === get_post_status( $template_id );
	}

	/**
	 * 店铺默认模板。还没有任何模板时：$create 为 true 则新建一个（后台进入装修页时），否则返回 0（前台请求不写数据库）。
	 */
	public static function default_id( bool $create = true ): int {
		$id = (int) get_option( self::ACTIVE_OPTION, 0 );
		if ( self::exists( $id ) ) {
			return $id;
		}
		$all = self::all();
		if ( ! $all && ! $create ) {
			return 0;
		}
		$id = $all ? (int) end( $all )->ID : self::create( 'VialCove', '' );
		update_option( self::ACTIVE_OPTION, $id, true );
		return $id;
	}

	/**
	 * 某个模板的装修文档 ID，缺少时自动补建（草稿内容为空）。
	 */
	public static function document_id( int $template_id, string $doc ): int {
		if ( ! self::exists( $template_id ) || ! isset( self::documents()[ $doc ] ) ) {
			return 0;
		}
		$found = get_posts(
			array(
				'post_type'      => self::LAYOUT,
				'post_status'    => 'any',
				'post_parent'    => $template_id,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => self::DOC_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $doc, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'no_found_rows'  => true,
			)
		);
		if ( $found ) {
			return (int) $found[0];
		}
		$id = wp_insert_post(
			array(
				'post_type'    => self::LAYOUT,
				'post_status'  => 'publish', // 文档本身不公开；“已发布到网站”由单独的发布快照决定。
				'post_parent'  => $template_id,
				'post_title'   => self::documents()[ $doc ],
				'post_content' => '',
				'post_author'  => (int) get_post_field( 'post_author', $template_id ),
				'meta_input'   => array( self::DOC_META => $doc ),
			),
			true
		);
		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/**
	 * 模板最近一次修改时间（模板本身和它的文档取最晚的），站点时区，未知时返回 null。
	 */
	public static function modified( int $template_id ): ?string {
		$latest = (string) get_post_field( 'post_modified', $template_id );
		foreach ( get_children( array( 'post_parent' => $template_id, 'post_type' => self::LAYOUT, 'post_status' => 'any' ) ) as $doc ) {
			if ( $doc->post_modified > $latest ) {
				$latest = $doc->post_modified;
			}
		}
		return '' !== $latest ? $latest : null;
	}

	// ---------- 修改 ----------

	/**
	 * 新建模板，同时建好四份空的装修文档。
	 */
	public static function create( string $name, string $note, int $author = 0 ): int {
		$name = self::clean_name( $name );
		$id   = wp_insert_post(
			array(
				'post_type'   => self::TEMPLATE,
				'post_status' => 'publish',
				'post_title'  => '' !== $name ? $name : __( 'Untitled template', 'peptide-core' ),
				'post_author' => $author ? $author : get_current_user_id(),
				'meta_input'  => array(
					self::NOTE_META  => self::clean_note( $note ),
					self::THEME_META => get_stylesheet(),
				),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return 0;
		}
		foreach ( array_keys( self::documents() ) as $doc ) {
			self::document_id( (int) $id, $doc );
		}
		return (int) $id;
	}

	public static function update_info( int $template_id, string $name, string $note ): bool {
		if ( ! self::exists( $template_id ) ) {
			return false;
		}
		$name = self::clean_name( $name );
		if ( '' !== $name ) {
			wp_update_post( array( 'ID' => $template_id, 'post_title' => $name ) );
		}
		update_post_meta( $template_id, self::NOTE_META, self::clean_note( $note ) );
		return true;
	}

	/**
	 * 复制模板：复制每份文档的草稿内容和全部插件元数据（包括已发布快照），新模板名后加“副本”。
	 */
	public static function duplicate( int $template_id ): int {
		if ( ! self::exists( $template_id ) ) {
			return 0;
		}
		/* translators: %s: original template name */
		$name = sprintf( __( '%s (copy)', 'peptide-core' ), get_the_title( $template_id ) );
		/* translators: %s: original template name */
		$note = sprintf( __( 'Copied from: %s', 'peptide-core' ), get_the_title( $template_id ) );
		$copy = self::create( $name, $note );
		if ( ! $copy ) {
			return 0;
		}
		update_post_meta( $copy, self::THEME_META, (string) get_post_meta( $template_id, self::THEME_META, true ) );
		foreach ( array_keys( self::documents() ) as $doc ) {
			$from = self::document_id( $template_id, $doc );
			$to   = self::document_id( $copy, $doc );
			if ( ! $from || ! $to ) {
				continue;
			}
			wp_update_post(
				array(
					'ID'           => $to,
					'post_content' => wp_slash( (string) get_post_field( 'post_content', $from ) ),
				)
			);
			foreach ( get_post_meta( $from ) as $key => $values ) {
				if ( str_starts_with( (string) $key, '_pc_' ) && self::DOC_META !== $key ) {
					delete_post_meta( $to, $key );
					foreach ( $values as $value ) {
						add_post_meta( $to, $key, wp_slash( maybe_unserialize( $value ) ) );
					}
				}
			}
		}
		return $copy;
	}

	public static function set_default( int $template_id ): bool {
		if ( ! self::exists( $template_id ) ) {
			return false;
		}
		update_option( self::ACTIVE_OPTION, $template_id, true );
		return true;
	}

	/**
	 * 删除模板（移到回收站，连同它的文档）。默认模板和被域名指定的模板不能删除。
	 *
	 * @return true|\WP_Error
	 */
	public static function trash( int $template_id ) {
		if ( ! self::exists( $template_id ) ) {
			return new \WP_Error( 'pc_template_missing', __( 'Template not found.', 'peptide-core' ) );
		}
		if ( self::default_id( false ) === $template_id ) {
			return new \WP_Error( 'pc_template_default', __( 'The store default template cannot be deleted. Set another template as default first.', 'peptide-core' ) );
		}
		if ( in_array( $template_id, self::domain_map(), true ) ) {
			return new \WP_Error( 'pc_template_domain', __( 'This template is assigned to a domain. Remove the domain assignment first.', 'peptide-core' ) );
		}
		self::$trashing_template = true;
		foreach ( get_children( array( 'post_parent' => $template_id, 'post_type' => self::LAYOUT, 'post_status' => 'any', 'fields' => 'ids' ) ) as $doc_id ) {
			wp_trash_post( (int) $doc_id );
		}
		wp_trash_post( $template_id );
		self::$trashing_template = false;
		return true;
	}

	// ---------- 域名指定模板 ----------

	/**
	 * 规范化域名：去掉协议、路径、端口，转小写。无效时返回 null。
	 */
	public static function normalize_host( string $value ): ?string {
		$value = strtolower( trim( $value ) );
		if ( '' === $value ) {
			return null;
		}
		if ( ! str_contains( $value, '://' ) ) {
			$value = 'http://' . $value;
		}
		$host = wp_parse_url( $value, PHP_URL_HOST );
		if ( ! is_string( $host ) || strlen( $host ) > 253 ) {
			return null;
		}
		$host = rtrim( $host, '.' );
		if ( 'localhost' === $host ) {
			return $host;
		}
		return 1 === preg_match( '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,62}$/', $host ) ? $host : null;
	}

	/**
	 * @return array<string,int> 域名 => 模板 ID（只包含仍然存在的模板）
	 */
	public static function domain_map(): array {
		$map = get_option( self::DOMAIN_OPTION, array() );
		$out = array();
		foreach ( is_array( $map ) ? $map : array() as $host => $template_id ) {
			if ( self::exists( (int) $template_id ) ) {
				$out[ (string) $host ] = (int) $template_id;
			}
		}
		return $out;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function assign_domain( string $domain, int $template_id ) {
		$host = self::normalize_host( $domain );
		if ( null === $host ) {
			return new \WP_Error( 'pc_domain_invalid', __( 'Enter a valid domain name, for example shop.example.com.', 'peptide-core' ) );
		}
		if ( ! self::exists( $template_id ) ) {
			return new \WP_Error( 'pc_template_missing', __( 'Template not found.', 'peptide-core' ) );
		}
		$map          = self::domain_map();
		$map[ $host ] = $template_id;
		ksort( $map );
		update_option( self::DOMAIN_OPTION, $map, true );
		return true;
	}

	public static function remove_domain( string $domain ): void {
		$host = self::normalize_host( $domain );
		$map  = self::domain_map();
		if ( null !== $host && isset( $map[ $host ] ) ) {
			unset( $map[ $host ] );
			update_option( self::DOMAIN_OPTION, $map, true );
		}
	}

	/**
	 * 某个域名应使用的模板：域名指定（www. 前缀视为同一域名）> 店铺默认。
	 */
	public static function for_host( string $host ): int {
		$host = self::normalize_host( $host );
		$map  = self::domain_map();
		if ( null !== $host ) {
			foreach ( array( $host, str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : 'www.' . $host ) as $candidate ) {
				if ( isset( $map[ $candidate ] ) ) {
					return $map[ $candidate ];
				}
			}
		}
		return self::default_id( false );
	}

	// ---------- 当前请求与预览 ----------

	/**
	 * 本次请求正在预览的模板（有编辑权限且 nonce 有效），否则 0。
	 */
	public static function previewing_id(): int {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 下面用 wp_verify_nonce 校验。
		if ( empty( $_GET[ self::PREVIEW_PARAM ] ) || empty( $_GET[ self::NONCE_PARAM ] ) ) {
			return 0;
		}
		$id    = absint( $_GET[ self::PREVIEW_PARAM ] );
		$nonce = sanitize_text_field( wp_unslash( $_GET[ self::NONCE_PARAM ] ) );
		// phpcs:enable
		if ( ! self::exists( $id ) || ! current_user_can( self::CAP_EDIT ) || ! wp_verify_nonce( $nonce, 'pc_template_preview_' . $id ) ) {
			return 0;
		}
		return $id;
	}

	/**
	 * 本次前台请求使用的模板：预览 > 域名指定 > 店铺默认。
	 */
	public static function current_id(): int {
		$preview = self::previewing_id();
		if ( $preview ) {
			return $preview;
		}
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		return self::for_host( $host );
	}

	/**
	 * 预览地址。$frame 为 true 时用于后台缩略预览（不显示管理工具栏和预览提示条）。
	 */
	public static function preview_url( int $template_id, bool $frame = false ): string {
		$args = array(
			self::PREVIEW_PARAM => $template_id,
			self::NONCE_PARAM   => wp_create_nonce( 'pc_template_preview_' . $template_id ),
		);
		if ( $frame ) {
			$args[ self::FRAME_PARAM ] = 1;
		}
		return add_query_arg( $args, home_url( '/' ) );
	}

	private static function in_frame(): bool {
		return self::previewing_id() && ! empty( $_GET[ self::FRAME_PARAM ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/** 预览页不能被缓存，避免草稿通过缓存泄露。 */
	public static function preview_headers(): void {
		if ( self::previewing_id() ) {
			nocache_headers();
			header( 'X-Robots-Tag: noindex, nofollow' );
		}
	}

	public static function hide_admin_bar_in_frame( $show ) {
		return self::in_frame() ? false : $show;
	}

	/**
	 * 预览时在页面底部显示提示条，说明正在预览哪个模板、只有自己能看到。
	 */
	public static function render_preview_bar(): void {
		$id = self::previewing_id();
		if ( ! $id || self::in_frame() ) {
			return;
		}
		printf(
			'<div class="pc-preview-bar" role="status" style="position:fixed;left:0;right:0;bottom:0;z-index:99999;background:#1d2327;color:#fff;font:14px/1.4 system-ui,sans-serif;padding:10px 16px;text-align:center">%s</div>',
			esc_html(
				sprintf(
					/* translators: %s: template name */
					__( 'Previewing template "%s". Only signed-in staff can see this preview.', 'peptide-core' ),
					get_the_title( $id )
				)
			)
		);
	}

	private static function clean_name( string $name ): string {
		$name = sanitize_text_field( $name );
		return function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 80 ) : substr( $name, 0, 80 );
	}

	private static function clean_note( string $note ): string {
		$note = sanitize_text_field( $note );
		return function_exists( 'mb_substr' ) ? mb_substr( $note, 0, 200 ) : substr( $note, 0, 200 );
	}
}
