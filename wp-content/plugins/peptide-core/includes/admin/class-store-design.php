<?php
/**
 * 后台“店铺装修”菜单（EDIT-BLOCKS-02）：主题装修、菜单导航、自定义页面、首页 SEO、域名管理、活动专题页。
 * 目前只有“主题装修”有内容（模板库、店铺默认模板、域名指定模板、预览）；其余页面是占位，后续逐个实现。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Settings;
use PeptideCore\Shipping_Tracking;
use PeptideCore\Support_Contacts;
use PeptideCore\Templates;

defined( 'ABSPATH' ) || exit;

final class Store_Design {

	public const SLUG = 'pc-store-design';

	/** 表单动作 => 需要的能力。 */
	private const ACTIONS = array(
		'pc_design_edit'          => Templates::CAP_EDIT,
		'pc_design_create'        => Templates::CAP_EDIT,
		'pc_design_duplicate'     => Templates::CAP_EDIT,
		'pc_design_info'          => Templates::CAP_EDIT,
		'pc_design_default'       => Templates::CAP_MANAGE,
		'pc_design_trash'         => Templates::CAP_MANAGE,
		'pc_design_domain_add'    => Templates::CAP_MANAGE,
		'pc_design_domain_remove' => Templates::CAP_MANAGE,
	);

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ), 55 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		foreach ( array_keys( self::ACTIONS ) as $action ) {
			add_action( 'admin_post_' . $action, array( self::class, 'handle' ) );
		}
	}

	/**
	 * 子页面：slug => 名称。第一个是“主题装修”。
	 *
	 * @return array<string,string>
	 */
	public static function pages(): array {
		return array(
			self::SLUG               => __( 'Theme design', 'peptide-core' ),
			self::SLUG . '-menus'    => __( 'Menus & navigation', 'peptide-core' ),
			self::SLUG . '-pages'    => __( 'Custom pages', 'peptide-core' ),
			self::SLUG . '-seo'      => __( 'Homepage SEO', 'peptide-core' ),
			self::SLUG . '-domains'  => __( 'Domains', 'peptide-core' ),
			self::SLUG . '-campaign' => __( 'Campaign pages', 'peptide-core' ),
		);
	}

	public static function add_menu(): void {
		add_menu_page(
			__( 'Store design', 'peptide-core' ),
			__( 'Store design', 'peptide-core' ),
			Templates::CAP_EDIT,
			self::SLUG,
			array( self::class, 'render_theme_design' ),
			'dashicons-art',
			56
		);
		foreach ( self::pages() as $slug => $title ) {
			add_submenu_page(
				self::SLUG,
				$title,
				$title,
				Templates::CAP_EDIT,
				$slug,
				self::SLUG === $slug ? array( self::class, 'render_theme_design' ) : array( self::class, 'render_placeholder' )
			);
		}
	}

	public static function enqueue( string $hook ): void {
		if ( ! str_contains( $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'peptide-core-store-design', plugins_url( 'assets/store-design.css', PEPTIDE_CORE_FILE ), array(), PEPTIDE_CORE_VERSION );
	}

	private static function page_url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	private static function action_url( string $action, array $args = array() ): string {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => $action ), $args ), admin_url( 'admin-post.php' ) ), $action );
	}

	// ---------- 表单处理 ----------

	public static function handle(): void {
		$action = sanitize_key( (string) current_action() );
		$action = substr( $action, strlen( 'admin_post_' ) );
		if ( ! isset( self::ACTIONS[ $action ] ) || ! current_user_can( self::ACTIONS[ $action ] ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'peptide-core' ), 403 );
		}
		check_admin_referer( $action );

		// phpcs:disable WordPress.Security.NonceVerification -- 上面已用 check_admin_referer 校验。
		$id     = isset( $_REQUEST['template'] ) ? absint( $_REQUEST['template'] ) : 0;
		$view   = isset( $_REQUEST['view'] ) && 'domains' === $_REQUEST['view'] ? 'domains' : '';
		$text   = static fn( string $key ): string => isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		$notice = 'saved';
		// phpcs:enable

		switch ( $action ) {
			case 'pc_design_edit':
				$doc_id = Templates::document_id( $id, 'home' );
				if ( $doc_id ) {
					wp_safe_redirect( admin_url( 'post.php?post=' . $doc_id . '&action=edit' ) );
					exit;
				}
				$notice = 'missing';
				break;
			case 'pc_design_create':
				$notice = Templates::create( $text( 'name' ), $text( 'note' ) ) ? 'created' : 'error';
				break;
			case 'pc_design_duplicate':
				$notice = Templates::duplicate( $id ) ? 'duplicated' : 'missing';
				break;
			case 'pc_design_info':
				$notice = Templates::update_info( $id, $text( 'name' ), $text( 'note' ) ) ? 'saved' : 'missing';
				break;
			case 'pc_design_default':
				$notice = Templates::set_default( $id ) ? 'default' : 'missing';
				break;
			case 'pc_design_trash':
				$result = Templates::trash( $id );
				$notice = is_wp_error( $result ) ? $result->get_error_code() : 'trashed';
				break;
			case 'pc_design_domain_add':
				$result = Templates::assign_domain( $text( 'domain' ), $id );
				$notice = is_wp_error( $result ) ? $result->get_error_code() : 'domain_added';
				$view   = 'domains';
				break;
			case 'pc_design_domain_remove':
				Templates::remove_domain( $text( 'domain' ) ?: ( isset( $_GET['domain'] ) ? sanitize_text_field( wp_unslash( $_GET['domain'] ) ) : '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$notice = 'domain_removed';
				$view   = 'domains';
				break;
		}

		wp_safe_redirect( self::page_url( array_filter( array( 'pc_notice' => $notice, 'view' => $view ) ) ) );
		exit;
	}

	/**
	 * @return array{0:string,1:string}|null [类型, 文案]
	 */
	private static function notice(): ?array {
		$code = isset( $_GET['pc_notice'] ) ? sanitize_key( wp_unslash( $_GET['pc_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$map  = array(
			'saved'               => array( 'success', __( 'Template details saved.', 'peptide-core' ) ),
			'created'             => array( 'success', __( 'Template created.', 'peptide-core' ) ),
			'duplicated'          => array( 'success', __( 'Template copied. The copy is not used by the store until you set it as default or assign it to a domain.', 'peptide-core' ) ),
			'default'             => array( 'success', __( 'Store default template changed.', 'peptide-core' ) ),
			'trashed'             => array( 'success', __( 'Template moved to the trash.', 'peptide-core' ) ),
			'domain_added'        => array( 'success', __( 'Domain template saved.', 'peptide-core' ) ),
			'domain_removed'      => array( 'success', __( 'Domain assignment removed. That domain now uses the store default template.', 'peptide-core' ) ),
			'missing'             => array( 'error', __( 'Template not found.', 'peptide-core' ) ),
			'error'               => array( 'error', __( 'Something went wrong. Please try again.', 'peptide-core' ) ),
			'pc_template_missing' => array( 'error', __( 'Template not found.', 'peptide-core' ) ),
			'pc_template_default' => array( 'error', __( 'The store default template cannot be deleted. Set another template as default first.', 'peptide-core' ) ),
			'pc_template_domain'  => array( 'error', __( 'This template is assigned to a domain. Remove the domain assignment first.', 'peptide-core' ) ),
			'pc_domain_invalid'   => array( 'error', __( 'Enter a valid domain name, for example shop.example.com.', 'peptide-core' ) ),
		);
		return $map[ $code ] ?? null;
	}

	// ---------- 页面 ----------

	public static function render_theme_design(): void {
		if ( ! current_user_can( Templates::CAP_EDIT ) ) {
			return;
		}
		$default   = Templates::default_id();
		$templates = Templates::all();
		$domains   = Templates::domain_map();
		$manage    = current_user_can( Templates::CAP_MANAGE );
		$view      = isset( $_GET['view'] ) && 'domains' === $_GET['view'] ? 'domains' : 'default'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$notice    = self::notice();
		?>
		<div class="wrap pc-sd">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Theme design', 'peptide-core' ); ?></h1>
			<nav class="pc-sd-tabs" aria-label="<?php esc_attr_e( 'Template sources', 'peptide-core' ); ?>">
				<span class="pc-sd-tabs__item is-active"><?php esc_html_e( 'My templates', 'peptide-core' ); ?></span>
			</nav>
			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice[0] ); ?> is-dismissible"><p><?php echo esc_html( $notice[1] ); ?></p></div>
			<?php endif; ?>

			<section class="pc-sd-card pc-sd-current">
				<div class="pc-sd-current__side">
					<h2><?php esc_html_e( 'Template in use', 'peptide-core' ); ?></h2>
					<div class="pc-sd-switch" role="tablist">
						<a role="tab" class="pc-sd-switch__btn<?php echo 'default' === $view ? ' is-active' : ''; ?>" aria-selected="<?php echo 'default' === $view ? 'true' : 'false'; ?>" href="<?php echo esc_url( self::page_url() ); ?>"><span class="dashicons dashicons-store" aria-hidden="true"></span><?php esc_html_e( 'Store default template', 'peptide-core' ); ?></a>
						<a role="tab" class="pc-sd-switch__btn<?php echo 'domains' === $view ? ' is-active' : ''; ?>" aria-selected="<?php echo 'domains' === $view ? 'true' : 'false'; ?>" href="<?php echo esc_url( self::page_url( array( 'view' => 'domains' ) ) ); ?>"><span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span><?php esc_html_e( 'Domain templates', 'peptide-core' ); ?></a>
					</div>
					<ol class="pc-sd-help">
						<li><?php esc_html_e( 'This is the design customers see. Different domains can use different templates.', 'peptide-core' ); ?></li>
						<li><?php esc_html_e( 'A domain without its own template uses the store default template.', 'peptide-core' ); ?></li>
					</ol>
				</div>

				<div class="pc-sd-current__main">
					<?php
					if ( 'domains' === $view ) {
						self::render_domains( $domains, $templates, $manage );
					} else {
						self::render_current( $default );
					}
					?>
				</div>
			</section>

			<section class="pc-sd-library">
				<div class="pc-sd-library__head">
					<h2><?php esc_html_e( 'My template library', 'peptide-core' ); ?></h2>
					<details class="pc-sd-new">
						<summary class="button"><?php esc_html_e( 'New template', 'peptide-core' ); ?></summary>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pc-sd-form">
							<input type="hidden" name="action" value="pc_design_create">
							<?php wp_nonce_field( 'pc_design_create' ); ?>
							<label><?php esc_html_e( 'Template name', 'peptide-core' ); ?> <input type="text" name="name" maxlength="80" required></label>
							<label><?php esc_html_e( 'Note', 'peptide-core' ); ?> <input type="text" name="note" maxlength="200"></label>
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Create', 'peptide-core' ); ?></button>
						</form>
					</details>
				</div>
				<?php self::render_library( $templates, $default, $domains, $manage ); ?>
			</section>

			<?php self::render_apps(); ?>
		</div>
		<?php
	}

	private static function render_current( int $default ): void {
		$theme    = (string) get_post_meta( $default, Templates::THEME_META, true );
		$modified = Templates::modified( $default );
		?>
		<div class="pc-sd-current__head">
			<div>
				<h3 class="pc-sd-current__name">
					<?php echo esc_html( get_the_title( $default ) ); ?>
					<?php self::theme_badge( $theme ); ?>
				</h3>
				<p class="pc-sd-meta">
					<span class="dashicons dashicons-store" aria-hidden="true"></span><?php esc_html_e( 'Store default template', 'peptide-core' ); ?>
					<?php if ( $modified ) : ?>
						<span class="pc-sd-meta__sep">|</span>
						<?php
						/* translators: %s: date and time */
						echo esc_html( sprintf( __( 'Modified: %s', 'peptide-core' ), mysql2date( 'Y-m-d H:i:s', $modified ) ) );
						?>
					<?php endif; ?>
				</p>
			</div>
			<div class="pc-sd-actions">
				<a class="button button-primary pc-sd-primary" href="<?php echo esc_url( self::action_url( 'pc_design_edit', array( 'template' => $default ) ) ); ?>"><?php esc_html_e( 'Design', 'peptide-core' ); ?></a>
				<a class="button" href="<?php echo esc_url( Templates::preview_url( $default ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Preview', 'peptide-core' ); ?></a>
			</div>
		</div>
		<p class="pc-sd-note"><?php esc_html_e( 'The module editor is being built. Until a template has published modules, the storefront keeps showing the theme\'s built-in pages.', 'peptide-core' ); ?></p>
		<?php self::render_stage( $default ); ?>
		<?php
	}

	/**
	 * 电脑和手机两个缩略预览（同源 iframe，缩放显示，不可点击）。
	 */
	private static function render_stage( int $template_id ): void {
		$url = Templates::preview_url( $template_id, true );
		?>
		<div class="pc-sd-stage" aria-hidden="true">
			<div class="pc-sd-stage__desktop"><iframe src="<?php echo esc_url( $url ); ?>" loading="lazy" tabindex="-1" title=""></iframe></div>
			<div class="pc-sd-stage__phone"><iframe src="<?php echo esc_url( $url ); ?>" loading="lazy" tabindex="-1" title=""></iframe></div>
		</div>
		<?php
	}

	/**
	 * @param array<string,int> $domains
	 * @param \WP_Post[]        $templates
	 */
	private static function render_domains( array $domains, array $templates, bool $manage ): void {
		?>
		<h3 class="pc-sd-current__name"><?php esc_html_e( 'Domain templates', 'peptide-core' ); ?></h3>
		<p class="pc-sd-note"><?php esc_html_e( 'Use this when several domains point to this store and each should show a different design. The domain must already reach this site (DNS and server settings); adding it here only chooses the template.', 'peptide-core' ); ?></p>
		<table class="widefat striped pc-sd-table">
			<thead><tr><th><?php esc_html_e( 'Domain', 'peptide-core' ); ?></th><th><?php esc_html_e( 'Template', 'peptide-core' ); ?></th><th><?php esc_html_e( 'Actions', 'peptide-core' ); ?></th></tr></thead>
			<tbody>
			<?php if ( ! $domains ) : ?>
				<tr><td colspan="3"><?php esc_html_e( 'No domain has its own template. All domains use the store default template.', 'peptide-core' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $domains as $host => $template_id ) : ?>
				<tr>
					<td><code><?php echo esc_html( $host ); ?></code></td>
					<td><?php echo esc_html( get_the_title( $template_id ) ); ?></td>
					<td>
						<?php if ( $manage ) : ?>
							<a class="button-link-delete" href="<?php echo esc_url( self::action_url( 'pc_design_domain_remove', array( 'domain' => $host ) ) ); ?>" onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Remove this domain assignment? The domain will use the store default template.', 'peptide-core' ) ) ); ?>);"><?php esc_html_e( 'Remove', 'peptide-core' ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $manage ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pc-sd-form pc-sd-form--inline">
				<input type="hidden" name="action" value="pc_design_domain_add">
				<?php wp_nonce_field( 'pc_design_domain_add' ); ?>
				<label><?php esc_html_e( 'Domain', 'peptide-core' ); ?> <input type="text" name="domain" placeholder="shop.example.com" required></label>
				<label><?php esc_html_e( 'Template', 'peptide-core' ); ?>
					<select name="template">
						<?php foreach ( $templates as $t ) : ?>
							<option value="<?php echo esc_attr( (string) $t->ID ); ?>"><?php echo esc_html( $t->post_title ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Assign', 'peptide-core' ); ?></button>
			</form>
		<?php else : ?>
			<p class="description"><?php esc_html_e( 'Only administrators can change domain templates.', 'peptide-core' ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * @param \WP_Post[]        $templates
	 * @param array<string,int> $domains
	 */
	private static function render_library( array $templates, int $default, array $domains, bool $manage ): void {
		?>
		<table class="widefat pc-sd-table pc-sd-table--library">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Template name', 'peptide-core' ); ?></th>
					<th><?php esc_html_e( 'Theme', 'peptide-core' ); ?></th>
					<th><?php esc_html_e( 'Note', 'peptide-core' ); ?></th>
					<th><?php esc_html_e( 'Created by', 'peptide-core' ); ?></th>
					<th><?php esc_html_e( 'Modified', 'peptide-core' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'peptide-core' ); ?></th>
					<th><?php esc_html_e( 'Preview', 'peptide-core' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $templates as $t ) : ?>
				<?php
				$id       = (int) $t->ID;
				$hosts    = array_keys( $domains, $id, true );
				$modified = Templates::modified( $id );
				$author   = get_userdata( (int) $t->post_author );
				?>
				<tr>
					<td>
						<strong><?php echo esc_html( $t->post_title ); ?></strong>
						<?php if ( $default === $id ) : ?>
							<span class="pc-sd-tag pc-sd-tag--blue"><?php esc_html_e( 'In use', 'peptide-core' ); ?></span>
						<?php endif; ?>
						<?php foreach ( $hosts as $host ) : ?>
							<span class="pc-sd-tag"><?php echo esc_html( $host ); ?></span>
						<?php endforeach; ?>
					</td>
					<td><?php self::theme_badge( (string) get_post_meta( $id, Templates::THEME_META, true ) ); ?></td>
					<td><?php echo esc_html( (string) get_post_meta( $id, Templates::NOTE_META, true ) ?: '-' ); ?></td>
					<td><?php echo esc_html( $author ? $author->display_name : '-' ); ?></td>
					<td><?php echo esc_html( $modified ? mysql2date( 'Y-m-d H:i:s', $modified ) : '-' ); ?></td>
					<td>
						<div class="pc-sd-split">
							<a class="button" href="<?php echo esc_url( self::action_url( 'pc_design_edit', array( 'template' => $id ) ) ); ?>"><?php esc_html_e( 'Design', 'peptide-core' ); ?></a>
							<details class="pc-sd-menu">
								<summary class="button" aria-label="<?php esc_attr_e( 'More actions', 'peptide-core' ); ?>"><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></summary>
								<div class="pc-sd-menu__list">
									<?php if ( $manage && $default !== $id ) : ?>
										<a href="<?php echo esc_url( self::action_url( 'pc_design_default', array( 'template' => $id ) ) ); ?>" onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Use this template as the store default? Customers will see it on all domains without their own template.', 'peptide-core' ) ) ); ?>);"><?php esc_html_e( 'Set as store default', 'peptide-core' ); ?></a>
									<?php endif; ?>
									<a href="<?php echo esc_url( self::action_url( 'pc_design_duplicate', array( 'template' => $id ) ) ); ?>"><?php esc_html_e( 'Duplicate', 'peptide-core' ); ?></a>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pc-sd-form">
										<input type="hidden" name="action" value="pc_design_info">
										<input type="hidden" name="template" value="<?php echo esc_attr( (string) $id ); ?>">
										<?php wp_nonce_field( 'pc_design_info' ); ?>
										<label><?php esc_html_e( 'Template name', 'peptide-core' ); ?> <input type="text" name="name" maxlength="80" value="<?php echo esc_attr( $t->post_title ); ?>" required></label>
										<label><?php esc_html_e( 'Note', 'peptide-core' ); ?> <input type="text" name="note" maxlength="200" value="<?php echo esc_attr( (string) get_post_meta( $id, Templates::NOTE_META, true ) ); ?>"></label>
										<button type="submit" class="button"><?php esc_html_e( 'Save details', 'peptide-core' ); ?></button>
									</form>
									<?php if ( $manage && $default !== $id && ! $hosts ) : ?>
										<a class="pc-sd-menu__danger" href="<?php echo esc_url( self::action_url( 'pc_design_trash', array( 'template' => $id ) ) ); ?>" onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Move this template to the trash? Its designs are removed with it.', 'peptide-core' ) ) ); ?>);"><?php esc_html_e( 'Delete', 'peptide-core' ); ?></a>
									<?php endif; ?>
								</div>
							</details>
						</div>
					</td>
					<td><a class="pc-sd-eye" href="<?php echo esc_url( Templates::preview_url( $id ) ); ?>" target="_blank" rel="noopener" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: template name */ __( 'Preview %s', 'peptide-core' ), $t->post_title ) ); ?>"><span class="dashicons dashicons-visibility" aria-hidden="true"></span></a></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function theme_badge( string $stylesheet ): void {
		$theme   = wp_get_theme( '' !== $stylesheet ? $stylesheet : get_stylesheet() );
		$name    = $theme->exists() ? $theme->get( 'Name' ) : $stylesheet;
		$current = '' === $stylesheet || get_stylesheet() === $stylesheet;
		printf(
			'<span class="pc-sd-tag %1$s" title="%2$s">%3$s</span>',
			$current ? 'pc-sd-tag--teal' : 'pc-sd-tag--warn',
			esc_attr( $current ? __( 'Built for the active theme', 'peptide-core' ) : __( 'Built for a different theme', 'peptide-core' ) ),
			esc_html( (string) $name )
		);
	}

	/**
	 * “推荐应用”：已有的相关功能及其状态。
	 */
	private static function render_apps(): void {
		$support = Support_Contacts::public_view();
		$apps    = array(
			array( 'dashicons-format-chat', __( 'Customer support button', 'peptide-core' ), $support['enabled'], admin_url( 'admin.php?page=' . Settings_Page::SLUG ) ),
			array( 'dashicons-location', __( 'Shipment tracking', 'peptide-core' ), Shipping_Tracking::native_enabled(), admin_url( 'admin.php?page=' . Settings_Page::SLUG ) ),
			array( 'dashicons-yes-alt', __( 'Checkout confirmation', 'peptide-core' ), null !== Settings::checkout_ack_label(), admin_url( 'admin.php?page=' . Settings_Page::SLUG ) ),
			array( 'dashicons-media-document', __( 'Batch reports', 'peptide-core' ), true, admin_url( 'edit.php?post_type=pc_batch' ) ),
		);
		?>
		<section class="pc-sd-apps">
			<h2><?php esc_html_e( 'Recommended apps', 'peptide-core' ); ?></h2>
			<div class="pc-sd-apps__grid">
				<?php foreach ( $apps as [ $icon, $label, $on, $url ] ) : ?>
					<a class="pc-sd-app" href="<?php echo esc_url( $url ); ?>">
						<span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
						<span class="pc-sd-app__name"><?php echo esc_html( $label ); ?></span>
						<?php if ( $on ) : ?>
							<span class="dashicons dashicons-yes pc-sd-app__on" aria-label="<?php esc_attr_e( 'Enabled', 'peptide-core' ); ?>"></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * 其余子页面的占位。
	 */
	public static function render_placeholder(): void {
		if ( ! current_user_can( Templates::CAP_EDIT ) ) {
			return;
		}
		$slug = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$desc = array(
			self::SLUG . '-menus'    => __( 'Edit the header and footer menus: links, order and sub-menus.', 'peptide-core' ),
			self::SLUG . '-pages'    => __( 'Build extra pages (About, Shipping, FAQ…) from the same modules as the homepage.', 'peptide-core' ),
			self::SLUG . '-seo'      => __( 'Homepage title, description and social sharing image for search engines.', 'peptide-core' ),
			self::SLUG . '-domains'  => __( 'Bind your own domain and check HTTPS once the site is on a server.', 'peptide-core' ),
			self::SLUG . '-campaign' => __( 'Landing pages for promotions, built from modules, with their own address.', 'peptide-core' ),
		);
		?>
		<div class="wrap pc-sd">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<section class="pc-sd-card pc-sd-empty">
				<span class="dashicons dashicons-hammer" aria-hidden="true"></span>
				<h2><?php esc_html_e( 'Coming soon', 'peptide-core' ); ?></h2>
				<p><?php echo esc_html( $desc[ $slug ] ?? '' ); ?></p>
			</section>
		</div>
		<?php
	}
}
