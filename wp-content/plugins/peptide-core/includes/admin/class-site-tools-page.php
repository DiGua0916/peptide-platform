<?php
/** Administrator site tools; WordPress handles navigation, forms and nonce validation. */
namespace PeptideCore\Admin;
use PeptideCore\Site_Tools;
defined( 'ABSPATH' ) || exit;

final class Site_Tools_Page {
	public const SLUG = 'pc-site-tools';
	public const CAP = 'manage_options';
	public static function register_hooks(): void {
		add_action( 'admin_post_pc_site_tools_save', array( self::class, 'save' ) );
		add_action( 'admin_post_pc_site_tools_delete', array( self::class, 'delete' ) );
		add_action( 'admin_enqueue_scripts', static function () {
			if ( self::SLUG !== ( $_GET['page'] ?? '' ) ) { return; }
			wp_enqueue_style( 'pc-site-tools', plugins_url( 'assets/site-tools.css', PEPTIDE_CORE_FILE ), array(), PEPTIDE_CORE_VERSION );
			wp_enqueue_script( 'pc-site-tools', plugins_url( 'assets/site-tools.js', PEPTIDE_CORE_FILE ), array(), PEPTIDE_CORE_VERSION, true );
		} );
	}
	public static function url( array $args = array() ): string { return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) ); }
	public static function guard( string $action = '' ): void {
		if ( ! Site_Tools::can_manage() ) { wp_die( '仅管理员可管理站点代码、虚拟文件和常用话术。', '无权访问', array( 'response' => 403 ) ); }
		if ( $action ) { if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( '请使用保存表单。', '', array( 'response' => 405 ) ); } check_admin_referer( $action ); }
	}
	private static function start_form( string $operation, string $option, int $id = 0, bool $delete = false ): void {
		$action = $delete ? 'pc_site_tools_delete' : 'pc_site_tools_save';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( $action ) . '"><input type="hidden" name="operation" value="' . esc_attr( $operation ) . '"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="revision" value="' . esc_attr( Site_Tools::revision( $option ) ) . '">'; wp_nonce_field( $action );
	}
	private static function finish( $r, string $tab, array $input = array() ): void {
		if ( is_wp_error( $r ) ) { set_transient( 'pc_site_tools_error_' . get_current_user_id(), $r->get_error_message(), 120 ); if ( $input ) { unset( $input['_wpnonce'], $input['_wp_http_referer'], $input['revision'], $input['action'] ); set_transient( 'pc_site_tools_form_' . get_current_user_id(), array( 'tab' => $tab, 'data' => $input ), 300 ); } }
		wp_safe_redirect( self::url( array( 'tab' => $tab, 'saved' => is_wp_error( $r ) ? 0 : 1, 'edit' => absint( $input['id'] ?? 0 ) ) ) ); exit;
	}
	public static function save(): void {
		self::guard( 'pc_site_tools_save' ); $in = wp_unslash( $_POST ); $op = sanitize_key( $in['operation'] ?? '' ); $revision = (string) ( $in['revision'] ?? '' );
		switch ( $op ) {
			case 'settings': $r = Site_Tools::save_settings( $in, $revision ); $tab = 'snippets'; break;
			case 'snippet': $r = Site_Tools::save_snippet( $in, $revision ); $tab = 'snippets'; break;
			case 'file': $r = Site_Tools::save_file( $in, $revision ); $tab = 'files'; break;
			case 'phrase': $r = Site_Tools::save_phrase( $in, $revision ); $tab = 'phrases'; break;
			default: $r = new \WP_Error( 'pc_tools_action', '无效的保存操作。' ); $tab = 'snippets';
		}
		self::finish( $r, $tab, is_wp_error( $r ) ? $in : array() );
	}
	public static function delete(): void {
		self::guard( 'pc_site_tools_delete' ); $op = sanitize_key( wp_unslash( $_POST['operation'] ?? '' ) ); $id = absint( $_POST['id'] ?? 0 ); $revision = (string) wp_unslash( $_POST['revision'] ?? '' );
		switch ( $op ) {
			case 'snippet': $r = Site_Tools::delete_snippet( $id, $revision ); $tab = 'snippets'; break;
			case 'file': $r = Site_Tools::delete_file( $id, $revision ); $tab = 'files'; break;
			case 'phrase': $r = Site_Tools::delete_phrase( $id, $revision ); $tab = 'phrases'; break;
			default: $r = new \WP_Error( 'pc_tools_action', '无效的删除操作。' ); $tab = 'snippets';
		}
		self::finish( $r, $tab );
	}
	private static function select( string $name, array $items, string $selected ): void {
		echo '<select name="' . esc_attr( $name ) . '">'; foreach ( $items as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '"' . selected( $key, $selected, false ) . '>' . esc_html( $label ) . '</option>'; } echo '</select>';
	}
	private static function remove_button( string $kind, string $option, int $id ): void { self::start_form( $kind, $option, $id, true ); echo '<button class="button-link-delete">删除</button></form>'; }
	public static function render(): void {
		self::guard(); $tab = sanitize_key( $_GET['tab'] ?? 'snippets' ); if ( ! in_array( $tab, array( 'snippets', 'files', 'phrases' ), true ) ) { $tab = 'snippets'; }
		$error = get_transient( 'pc_site_tools_error_' . get_current_user_id() ); delete_transient( 'pc_site_tools_error_' . get_current_user_id() );
		$form = get_transient( 'pc_site_tools_form_' . get_current_user_id() ); delete_transient( 'pc_site_tools_form_' . get_current_user_id() ); $kept = is_array( $form ) && $form['tab'] === $tab ? $form['data'] : array();
		echo '<div class="wrap pc-site-tools"><h1>站点工具</h1><nav class="nav-tab-wrapper" aria-label="站点工具">'; foreach ( array( 'snippets' => 'JS与CSS', 'files' => '虚拟文本文件', 'phrases' => '常用话术' ) as $key => $label ) { echo '<a class="nav-tab ' . ( $tab === $key ? 'nav-tab-active' : '' ) . '" href="' . esc_url( self::url( array( 'tab' => $key ) ) ) . '"' . ( $tab === $key ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>'; } echo '</nav>';
		if ( $error ) { echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>'; }
		elseif ( ! empty( $_GET['saved'] ) ) { echo '<div class="notice notice-success"><p>已保存。</p></div>'; }
		if ( 'files' === $tab ) { self::files_form( $kept ); } elseif ( 'phrases' === $tab ) { self::phrases_form( $kept ); } else { self::snippets_form( $kept ); }
		echo '<p class="pc-tools-copy-status" role="status" aria-live="polite"></p></div>';
	}
	private static function snippets_form( array $kept ): void {
		$s = Site_Tools::settings(); $edit = absint( $_GET['edit'] ?? 0 ); $r = array_merge( array( 'id' => 0, 'name' => '', 'type' => 'js', 'location' => 'head', 'scope' => 'all', 'targets' => array(), 'code' => '', 'enabled' => false ), $s['snippets'][ $edit ] ?? array(), $kept );
		echo '<section class="pc-tools-card"><h2>前台代码总开关：' . ( $s['enabled'] ? '已开启' : '已关闭' ) . '</h2><p>每个片段另有独立开关。总开关默认关闭，关闭时保留源码；仅在选定前台页面输出，不注入登录、REST或后台页面。</p>'; self::start_form( 'settings', Site_Tools::OPTION ); echo '<label><input type="checkbox" name="enabled" value="1"' . checked( (bool) $s['enabled'], true, false ) . '> 允许输出已开启的JS与CSS片段</label>'; submit_button( '保存总开关', 'primary', 'submit', false ); echo '</form></section><div class="pc-tools-grid"><section class="pc-tools-card"><h2>代码片段</h2>';
		if ( ! $s['snippets'] ) { echo '<p>还没有代码片段。填写右侧表单添加，确认作用范围后再开启。</p>'; }
		foreach ( $s['snippets'] as $item ) { echo '<article class="pc-tools-item"><h3><a href="' . esc_url( self::url( array( 'tab' => 'snippets', 'edit' => $item['id'] ) ) ) . '">' . esc_html( $item['name'] ) . '</a></h3><p>' . esc_html( strtoupper( $item['type'] ) . ' · ' . ( 'head' === $item['location'] ? '页头' : '页尾' ) . ' · ' . ( $item['enabled'] ? ( $s['enabled'] ? '输出中' : '片段开启，总开关关闭' ) : '未开启' ) ) . '</p>'; self::remove_button( 'snippet', Site_Tools::OPTION, $item['id'] ); echo '</article>'; }
		echo '</section><section class="pc-tools-card"><h2>' . ( $edit ? '编辑代码片段' : '添加代码片段' ) . '</h2>'; self::start_form( 'snippet', Site_Tools::OPTION, (int) $r['id'] );
		echo '<p><label>名称<input class="widefat" name="name" maxlength="100" value="' . esc_attr( $r['name'] ) . '" required></label></p><p><label>类型 '; self::select( 'type', array( 'js' => 'JavaScript', 'css' => 'CSS' ), $r['type'] ); echo '</label> <label>输出位置 '; self::select( 'location', array( 'head' => '页头', 'footer' => '页尾' ), $r['location'] ); echo '</label></p><p><label>页面范围 '; self::select( 'scope', array( 'all' => '全部前台页面', 'home' => '仅首页', 'shop' => '商品目录与分类', 'product' => '商品详情', 'pages' => '普通页面', 'posts' => '博客文章', 'selected' => '指定页面' ), $r['scope'] ); echo '</label></p><div class="pc-tools-targets" data-selected-pages="1"><label>指定页面<select name="targets[]" class="widefat" multiple size="6">';
		foreach ( get_posts( array( 'post_type' => array( 'page', 'post', 'product', 'pc_topic' ), 'post_status' => array( 'publish', 'draft', 'private' ), 'posts_per_page' => 200, 'orderby' => 'title', 'order' => 'ASC' ) ) as $target ) { echo '<option value="' . (int) $target->ID . '"' . selected( in_array( $target->ID, (array) $r['targets'], true ), true, false ) . '>' . esc_html( get_the_title( $target ) ) . '</option>'; } echo '</select></label><p class="description">选择“指定页面”时生效；按住Ctrl或Command选择多个页面。</p></div>';
		echo '<p><label>源码<textarea class="widefat code" name="code" rows="14" required spellcheck="false">' . esc_textarea( $r['code'] ) . '</textarea></label><span class="description">只填写JS或CSS本体，不要加script/style标签。代码由有权限的管理员维护。</span></p><p><label><input type="checkbox" name="enabled" value="1"' . checked( (bool) $r['enabled'], true, false ) . '> 开启这个片段</label></p>'; submit_button( '保存代码片段' ); echo '<a href="' . esc_url( self::url() ) . '">添加新片段</a></form></section></div>';
	}
	private static function files_form( array $kept ): void {
		$items = Site_Tools::files(); $edit = absint( $_GET['edit'] ?? 0 ); $r = array_merge( array( 'id' => 0, 'path' => '', 'content' => '', 'enabled' => false ), $items[ $edit ] ?? array(), $kept );
		echo '<p>通过站点路由发布文本，不创建磁盘文件，不执行PHP。允许 /site-files/ 文件名，以及 /ads.txt、/app-ads.txt、/.well-known/security.txt。支持txt、css、js、json、xml、csv、webmanifest，不覆盖现有页面或站点关键路径。</p><div class="pc-tools-grid"><section class="pc-tools-card"><h2>虚拟文本文件</h2>';
		if ( ! $items ) { echo '<p>还没有虚拟文本文件。先填写路径和内容，确认后勾选发布。</p>'; }
		foreach ( $items as $item ) { echo '<article class="pc-tools-item"><h3><a href="' . esc_url( self::url( array( 'tab' => 'files', 'edit' => $item['id'] ) ) ) . '">' . esc_html( $item['path'] ) . '</a></h3><p>' . ( $item['enabled'] ? '<a target="_blank" rel="noopener" href="' . esc_url( home_url( $item['path'] ) ) . '">打开已发布文本</a>' : '未发布' ) . '</p>'; self::remove_button( 'file', Site_Tools::FILES, $item['id'] ); echo '</article>'; }
		echo '</section><section class="pc-tools-card"><h2>' . ( $edit ? '编辑虚拟文件' : '添加虚拟文件' ) . '</h2>'; self::start_form( 'file', Site_Tools::FILES, (int) $r['id'] ); echo '<p><label>虚拟路径<input class="widefat code" name="path" value="' . esc_attr( $r['path'] ) . '" maxlength="150" placeholder="/site-files/store-info.txt" required></label></p><p><label>文本内容<textarea class="widefat code" name="content" rows="16" spellcheck="false">' . esc_textarea( $r['content'] ) . '</textarea></label></p><p><label><input type="checkbox" name="enabled" value="1"' . checked( (bool) $r['enabled'], true, false ) . '> 发布这个文本文件</label></p>'; submit_button( '保存虚拟文件' ); echo '<a href="' . esc_url( self::url( array( 'tab' => 'files' ) ) ) . '">添加新文件</a></form></section></div>';
	}
	private static function phrases_form( array $kept ): void {
		$items = Site_Tools::phrases(); $edit = absint( $_GET['edit'] ?? 0 ); $q = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) ); $r = array_merge( array( 'id' => 0, 'title' => '', 'category' => '', 'text' => '' ), $items[ $edit ] ?? array(), $kept );
		echo '<p>保存常用回复，点击复制后自行发送；本工具不会向顾客自动发消息。</p><form method="get"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="phrases"><label class="screen-reader-text" for="pc-tools-search">搜索话术</label><input id="pc-tools-search" name="q" value="' . esc_attr( $q ) . '" placeholder="搜索名称、分类或正文"> <button class="button">搜索</button></form><div class="pc-tools-grid"><section class="pc-tools-card"><h2>常用话术</h2>';
		if ( ! $items ) { echo '<p>还没有常用话术。先添加一条实际使用的回复。</p>'; }
		foreach ( $items as $item ) { if ( $q && false === stripos( $item['title'] . ' ' . $item['category'] . ' ' . $item['text'], $q ) ) { continue; } echo '<article class="pc-tools-item"><h3><a href="' . esc_url( self::url( array( 'tab' => 'phrases', 'edit' => $item['id'] ) ) ) . '">' . esc_html( $item['title'] ) . '</a></h3><p>' . esc_html( $item['category'] ) . '</p><textarea readonly class="widefat" rows="4" id="pc-phrase-' . (int) $item['id'] . '" aria-label="' . esc_attr( $item['title'] ) . '">' . esc_textarea( $item['text'] ) . '</textarea><button type="button" class="button" data-copy-phrase="pc-phrase-' . (int) $item['id'] . '">复制话术</button>'; self::remove_button( 'phrase', Site_Tools::PHRASES, $item['id'] ); echo '</article>'; }
		echo '</section><section class="pc-tools-card"><h2>' . ( $edit ? '编辑话术' : '添加话术' ) . '</h2>'; self::start_form( 'phrase', Site_Tools::PHRASES, (int) $r['id'] ); echo '<p><label>名称<input class="widefat" name="title" value="' . esc_attr( $r['title'] ) . '" maxlength="100" required></label></p><p><label>分类<input class="widefat" name="category" value="' . esc_attr( $r['category'] ) . '" maxlength="50"></label></p><p><label>正文<textarea class="widefat" name="text" rows="12" required>' . esc_textarea( $r['text'] ) . '</textarea></label></p>'; submit_button( '保存话术' ); echo '<a href="' . esc_url( self::url( array( 'tab' => 'phrases' ) ) ) . '">添加新话术</a></form></section></div>';
	}
}
