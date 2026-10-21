<?php
/**
 * 后台“店铺装修 → 菜单导航”（MENU-NAV-01），参照 Shopname：
 *
 * 列表：导出、导入、新建菜单；每行是一份菜单（页头 / 页尾固定菜单标“不可删除”），显示位置、一级目录、创建人。
 * 编辑：admin.php?page=pc-store-design-menus&menu=<ID>，由 assets/menu-editor.js 渲染：
 *       菜单名称、显示位置；菜单目录结构（导航名称、链接页面、批量添加、添加二级目录、设置、删除、上下移动）。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Menus;

defined( 'ABSPATH' ) || exit;

final class Menu_Page {

	public const SLUG = Store_Design::SLUG . '-menus';

	private const ACTIONS = array( 'pc_menu_create', 'pc_menu_delete', 'pc_menu_export', 'pc_menu_import' );

	public static function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		foreach ( self::ACTIONS as $action ) {
			add_action( 'admin_post_' . $action, array( self::class, 'handle' ) );
		}
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	private static function action_url( string $action, array $args = array() ): string {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => $action ), $args ), admin_url( 'admin-post.php' ) ), $action );
	}

	private static function editing(): int {
		$id = isset( $_GET['menu'] ) ? absint( $_GET['menu'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return Menus::exists( $id ) ? $id : 0;
	}

	public static function enqueue( string $hook ): void {
		if ( ! str_ends_with( $hook, self::SLUG ) || ! current_user_can( Menus::CAP ) ) {
			return;
		}
		$id = self::editing();
		if ( ! $id ) {
			return;
		}
		wp_enqueue_media();
		$url = plugins_url( 'assets/', PEPTIDE_CORE_FILE );
		$ver = static fn( string $file ): string => PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( PEPTIDE_CORE_DIR . '/assets/' . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_enqueue_style( 'peptide-core-menu-editor', $url . 'menu-editor.css', array( 'wp-components' ), $ver( 'menu-editor.css' ) );
		wp_enqueue_script( 'peptide-core-menu-editor', $url . 'menu-editor.js', array( 'wp-api-fetch', 'wp-element', 'wp-components' ), $ver( 'menu-editor.js' ), array( 'in_footer' => true ) );

		$types = array();
		foreach ( Menus::link_types() as $value => $label ) {
			$types[] = array( 'value' => $value, 'label' => $label );
		}
		$icons = array();
		foreach ( Menus::get( $id )['items'] as $item ) {
			foreach ( array_merge( array( $item ), $item['children'] ) as $node ) {
				if ( $node['style']['icon'] ) {
					$src = wp_get_attachment_image_src( $node['style']['icon'], 'thumbnail' );
					if ( $src ) {
						$icons[ $node['style']['icon'] ] = $src[0];
					}
				}
			}
		}
		wp_add_inline_script(
			'peptide-core-menu-editor',
			'window.pcMenuEditor = ' . wp_json_encode(
				array(
					'menu'     => Menus::get( $id ),
					'restPath' => '/peptide/v1/menus/' . $id,
					'backUrl'  => self::url(),
					'types'    => $types,
					'options'  => Menus::link_options(),
					'icons'    => (object) $icons,
					'strings'  => array(
						'back'           => __( 'Back to menu list', 'peptide-core' ),
						'title'          => __( 'Edit menu', 'peptide-core' ),
						'name'           => __( 'Menu name', 'peptide-core' ),
						'location'       => __( 'Shown in', 'peptide-core' ),
						'locNone'        => __( 'Not used', 'peptide-core' ),
						'locHeader'      => __( 'Header (top navigation)', 'peptide-core' ),
						'locFooter'      => __( 'Footer', 'peptide-core' ),
						'locHelp'        => __( 'A menu used in the header or footer replaces the one used there before.', 'peptide-core' ),
						'fixedNote'      => __( 'This is a fixed menu and cannot be deleted.', 'peptide-core' ),
						'structure'      => __( 'Menu structure', 'peptide-core' ),
						'structureHelp'  => __( 'Item names are shown on the storefront. Up to two levels.', 'peptide-core' ),
						'colName'        => __( 'Navigation name', 'peptide-core' ),
						'colLink'        => __( 'Linked page', 'peptide-core' ),
						'colActions'     => __( 'Actions', 'peptide-core' ),
						'batchAdd'       => __( 'Batch add', 'peptide-core' ),
						'addChild'       => __( 'Add sub-item', 'peptide-core' ),
						'settings'       => __( 'Settings', 'peptide-core' ),
						'remove'         => __( 'Delete', 'peptide-core' ),
						'moveUp'         => __( 'Move up', 'peptide-core' ),
						'moveDown'       => __( 'Move down', 'peptide-core' ),
						'addItem'        => __( 'Add menu item', 'peptide-core' ),
						'batchAddTop'    => __( 'Batch add top-level items', 'peptide-core' ),
						'empty'          => __( 'No items yet. Add the first one below.', 'peptide-core' ),
						'choose'         => __( 'Choose…', 'peptide-core' ),
						'search'         => __( 'Type to search', 'peptide-core' ),
						'urlPlaceholder' => '/shop/  ·  https://…',
						'broken'         => __( 'The linked page no longer exists or is not published. Visitors will not see this item.', 'peptide-core' ),
						'newItem'        => __( 'New item', 'peptide-core' ),
						'save'           => __( 'Save', 'peptide-core' ),
						'saving'         => __( 'Saving…', 'peptide-core' ),
						'saved'          => __( 'Menu saved.', 'peptide-core' ),
						'unsaved'        => __( 'You have unsaved changes.', 'peptide-core' ),
						'confirmDelete'  => __( 'Delete this item and its sub-items?', 'peptide-core' ),
						'cancel'         => __( 'Cancel', 'peptide-core' ),
						'ok'             => __( 'OK', 'peptide-core' ),
						'batchTitle'     => __( 'Batch add sub-items', 'peptide-core' ),
						'batchTitleTop'  => __( 'Batch add top-level items', 'peptide-core' ),
						'batchType'      => __( 'Add type', 'peptide-core' ),
						'batchHelp'      => __( 'Adds one item per selected entry, named after it and linking to it. Example: choose several product categories to add them all as sub-items.', 'peptide-core' ),
						'selectAll'      => __( 'Select all', 'peptide-core' ),
						'selected'       => __( '%d selected', 'peptide-core' ),
						'settingsTitle'  => __( 'Navigation settings', 'peptide-core' ),
						'target'         => __( 'Open in', 'peptide-core' ),
						'keep'           => __( 'Keep default', 'peptide-core' ),
						'sameWindow'     => __( 'Same window', 'peptide-core' ),
						'newWindow'      => __( 'New window', 'peptide-core' ),
						'indexing'       => __( 'Search engines', 'peptide-core' ),
						'follow'         => __( 'Follow this link', 'peptide-core' ),
						'nofollow'       => __( 'Do not follow (nofollow)', 'peptide-core' ),
						'attrTitle'      => __( 'Link title (shown on hover)', 'peptide-core' ),
						'description'    => __( 'Description (shown under the name in the header drop-down)', 'peptide-core' ),
						'color'          => __( 'Text color', 'peptide-core' ),
						'clearColor'     => __( 'Clear', 'peptide-core' ),
						'icon'           => __( 'Icon', 'peptide-core' ),
						'iconHelp'       => __( 'Navigation icon, recommended 80 × 80 px.', 'peptide-core' ),
						'chooseImage'    => __( 'Choose image', 'peptide-core' ),
						'removeImage'    => __( 'Remove', 'peptide-core' ),
						'fontStyle'      => __( 'Font style', 'peptide-core' ),
						'normal'         => __( 'Normal', 'peptide-core' ),
						'italic'         => __( 'Italic', 'peptide-core' ),
						'fontWeight'     => __( 'Font weight', 'peptide-core' ),
						'bold'           => __( 'Bold', 'peptide-core' ),
						'decoration'     => __( 'Text decoration', 'peptide-core' ),
						'none'           => __( 'None', 'peptide-core' ),
						'underline'      => __( 'Underline', 'peptide-core' ),
						'overline'       => __( 'Overline', 'peptide-core' ),
						'lineThrough'    => __( 'Strikethrough', 'peptide-core' ),
						'conflict'       => __( 'This menu was changed somewhere else after you opened it. Reload the page to see the latest version.', 'peptide-core' ),
					),
				)
			) . ';',
			'before'
		);
	}

	// ---------- 表单处理 ----------

	public static function handle(): void {
		$action = substr( sanitize_key( (string) current_action() ), strlen( 'admin_post_' ) );
		if ( ! in_array( $action, self::ACTIONS, true ) || ! current_user_can( Menus::CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'peptide-core' ), 403 );
		}
		check_admin_referer( $action );
		// phpcs:disable WordPress.Security.NonceVerification -- 上面已用 check_admin_referer 校验。
		$id = isset( $_REQUEST['menu'] ) ? absint( $_REQUEST['menu'] ) : 0;
		switch ( $action ) {
			case 'pc_menu_create':
				$result = Menus::create( isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '' );
				if ( is_wp_error( $result ) ) {
					self::back( 'error', $result->get_error_message() );
				}
				wp_safe_redirect( self::url( array( 'menu' => $result ) ) );
				exit;
			case 'pc_menu_delete':
				$result = Menus::delete( $id );
				is_wp_error( $result ) ? self::back( 'error', $result->get_error_message() ) : self::back( 'success', __( 'Menu deleted.', 'peptide-core' ) );
				break;
			case 'pc_menu_export':
				nocache_headers();
				header( 'Content-Type: application/json; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename="menus-' . gmdate( 'Ymd-His' ) . '.json"' );
				echo wp_json_encode( Menus::export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				exit;
			case 'pc_menu_import':
				$file = $_FILES['file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				if ( ! is_array( $file ) || UPLOAD_ERR_OK !== ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( (string) $file['tmp_name'] ) || (int) $file['size'] > 1024 * 1024 ) {
					self::back( 'error', __( 'Choose a menu export file (.json, up to 1 MB).', 'peptide-core' ) );
				}
				$data   = json_decode( (string) file_get_contents( (string) $file['tmp_name'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$result = Menus::import( $data );
				if ( is_wp_error( $result ) ) {
					self::back( 'error', $result->get_error_message() );
				}
				/* translators: %d: number of menus */
				$message = sprintf( _n( 'Imported %d menu as a new menu.', 'Imported %d menus as new menus.', count( $result['created'] ), 'peptide-core' ), count( $result['created'] ) );
				if ( $result['missing'] ) {
					/* translators: %s: item names */
					$message .= ' ' . sprintf( __( 'These items link to pages that do not exist on this site and were imported without a link: %s', 'peptide-core' ), implode( ', ', array_slice( $result['missing'], 0, 20 ) ) );
				}
				self::back( $result['missing'] ? 'warning' : 'success', $message );
				break;
		}
		// phpcs:enable
		self::back( 'error', __( 'Sorry, you are not allowed to do that.', 'peptide-core' ) );
	}

	/** 回到列表并显示一条提示（提示内容放在短期缓存里，地址里只带一个随机键）。 */
	private static function back( string $type, string $message ): void {
		$key = wp_generate_password( 8, false );
		set_transient( 'pc_menu_notice_' . get_current_user_id() . '_' . $key, array( $type, $message ), 120 );
		wp_safe_redirect( self::url( array( 'pc_notice' => $key ) ) );
		exit;
	}

	private static function notice(): ?array {
		$key = isset( $_GET['pc_notice'] ) ? sanitize_key( wp_unslash( $_GET['pc_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $key ) {
			return null;
		}
		$name   = 'pc_menu_notice_' . get_current_user_id() . '_' . $key;
		$notice = get_transient( $name );
		delete_transient( $name );
		return is_array( $notice ) ? $notice : null;
	}

	// ---------- 页面 ----------

	public static function render(): void {
		if ( ! current_user_can( Menus::CAP ) ) {
			return;
		}
		Menus::ensure_fixed();
		$id = self::editing();
		if ( $id ) {
			echo '<div class="wrap pc-sd pc-me-wrap"><div id="pc-menu-editor" class="pc-me"><p>' . esc_html__( 'Loading…', 'peptide-core' ) . '</p></div></div>';
			return;
		}
		$notice    = self::notice();
		$locations = array(
			'header' => __( 'Header', 'peptide-core' ),
			'footer' => __( 'Footer', 'peptide-core' ),
		);
		?>
		<div class="wrap pc-sd">
			<div class="pc-sd-library__head">
				<h1 class="wp-heading-inline"><?php esc_html_e( 'Menus & navigation', 'peptide-core' ); ?></h1>
				<a class="button" href="<?php echo esc_url( self::action_url( 'pc_menu_export' ) ); ?>"><span class="dashicons dashicons-upload" aria-hidden="true"></span> <?php esc_html_e( 'Export', 'peptide-core' ); ?></a>
				<details class="pc-sd-new">
					<summary class="button"><span class="dashicons dashicons-download" aria-hidden="true"></span> <?php esc_html_e( 'Import', 'peptide-core' ); ?></summary>
					<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pc-sd-form">
						<input type="hidden" name="action" value="pc_menu_import">
						<?php wp_nonce_field( 'pc_menu_import' ); ?>
						<label><?php esc_html_e( 'Menu export file (.json)', 'peptide-core' ); ?> <input type="file" name="file" accept=".json,application/json" required></label>
						<p class="description"><?php esc_html_e( 'Every menu in the file is added as a new menu. Existing menus are not changed.', 'peptide-core' ); ?></p>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Import', 'peptide-core' ); ?></button>
					</form>
				</details>
				<p class="pc-sd-note"><?php esc_html_e( 'Set up menus here, then choose which one the storefront header and footer use.', 'peptide-core' ); ?></p>
				<details class="pc-sd-new">
					<summary class="button button-primary">+ <?php esc_html_e( 'New menu', 'peptide-core' ); ?></summary>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pc-sd-form">
						<input type="hidden" name="action" value="pc_menu_create">
						<?php wp_nonce_field( 'pc_menu_create' ); ?>
						<label><?php esc_html_e( 'Menu name', 'peptide-core' ); ?> <input type="text" name="name" maxlength="40" required></label>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Create', 'peptide-core' ); ?></button>
					</form>
				</details>
			</div>
			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice[0] ); ?> is-dismissible"><p><?php echo esc_html( $notice[1] ); ?></p></div>
			<?php endif; ?>
			<table class="widefat striped pc-sd-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Actions', 'peptide-core' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Menu name', 'peptide-core' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Shown in', 'peptide-core' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Top-level items', 'peptide-core' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Created by', 'peptide-core' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( Menus::all() as $row ) : ?>
						<tr>
							<td class="pc-sd-actions">
								<a class="button button-small" href="<?php echo esc_url( self::url( array( 'menu' => $row['id'] ) ) ); ?>"><?php esc_html_e( 'Edit', 'peptide-core' ); ?></a>
								<?php if ( ! $row['fixed'] ) : ?>
									<a class="button button-small button-link-delete" href="<?php echo esc_url( self::action_url( 'pc_menu_delete', array( 'menu' => $row['id'] ) ) ); ?>" onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Delete this menu?', 'peptide-core' ) ) ); ?>);"><?php esc_html_e( 'Delete', 'peptide-core' ); ?></a>
								<?php endif; ?>
							</td>
							<td>
								<strong><a href="<?php echo esc_url( self::url( array( 'menu' => $row['id'] ) ) ); ?>"><?php echo esc_html( $row['name'] ); ?></a></strong>
								<?php if ( $row['fixed'] ) : ?>
									<span class="pc-sd-tag pc-sd-tag--muted"><?php esc_html_e( 'Cannot be deleted', 'peptide-core' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $row['location'] ) : ?>
									<span class="pc-sd-tag pc-sd-tag--green"><?php echo esc_html( $locations[ $row['location'] ] ); ?></span>
								<?php else : ?>
									<span class="pc-sd-tag pc-sd-tag--muted"><?php esc_html_e( 'Not used', 'peptide-core' ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo $row['top'] ? esc_html( implode( ', ', $row['top'] ) ) : '—'; ?></td>
							<td><?php echo '' !== $row['author'] ? esc_html( $row['author'] ) : '—'; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
