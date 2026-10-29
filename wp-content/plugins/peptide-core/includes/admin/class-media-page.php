<?php
/**
 * 后台“后台设置 → 素材管理”（照 YY 的素材中心，用户 2026-10-27）。数据见 PeptideCore\Media_Library。
 *
 * - 顶层菜单“后台设置”放在店铺设置和应用中心之间，下面是素材管理和操作记录（Activity_Page）。
 * - 标签：所有素材 / 采集中 / 采集重试 / 采集失败。
 * - 所有素材：左边分组（全部、未分组、各分组，新建 / 改名 / 删除），右边搜索、上传素材、采集素材、
 *   批量更改分组 / 删除，列：素材、宽高、地址（复制）、文件大小、素材类型、分组、采集地址，每页 20 / 50 / 100 条。
 * - 采集标签：地址、分组、原因、添加时间；可以重试、移除、立即处理。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Media_Library;

defined( 'ABSPATH' ) || exit;

final class Media_Page {

	public const SLUG     = 'pc-media';
	public const POSITION = '58.5';

	private const ACTIONS = array( 'pc_media_upload', 'pc_media_collect', 'pc_media_bulk', 'pc_media_group_add', 'pc_media_group_rename', 'pc_media_group_delete', 'pc_media_queue', 'pc_media_export' );

	/** 一次最多上传几个文件。 */
	private const MAX_UPLOAD = 20;

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ), 61 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		foreach ( self::ACTIONS as $action ) {
			add_action( 'admin_post_' . $action, array( self::class, 'handle' ) );
		}
	}

	public static function add_menu(): void {
		add_menu_page( __( 'Admin settings', 'peptide-core' ), __( 'Admin settings', 'peptide-core' ), Media_Library::CAP, self::SLUG, array( self::class, 'render' ), 'dashicons-admin-tools', self::POSITION );
		add_submenu_page( self::SLUG, __( 'Media library', 'peptide-core' ), __( 'Media library', 'peptide-core' ), Media_Library::CAP, self::SLUG, array( self::class, 'render' ) );
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	public static function enqueue( string $hook ): void {
		if ( ! str_ends_with( $hook, self::SLUG ) ) {
			return;
		}
		$ver = static fn( string $f ): string => PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( PEPTIDE_CORE_DIR . '/assets/' . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_enqueue_style( 'peptide-core-media-admin', plugins_url( 'assets/media-admin.css', PEPTIDE_CORE_FILE ), array(), $ver( 'media-admin.css' ) );
		wp_enqueue_script( 'peptide-core-media-admin', plugins_url( 'assets/media-admin.js', PEPTIDE_CORE_FILE ), array(), $ver( 'media-admin.js' ), true );
		wp_localize_script(
			'peptide-core-media-admin',
			'pcMediaAdmin',
			array(
				'copied'  => __( 'Copied', 'peptide-core' ),
				'confirm' => __( 'Delete the selected media files? They are removed from the server and disappear wherever they are used (products, posts).', 'peptide-core' ),
			)
		);
	}

	// ---------- 提示 ----------

	private static function flash( string $type, string $text ): void {
		set_transient( 'pc_media_msg_' . get_current_user_id(), array( $type, $text ), 120 );
	}

	private static function notices(): void {
		$key = 'pc_media_msg_' . get_current_user_id();
		$msg = get_transient( $key );
		if ( is_array( $msg ) && 2 === count( $msg ) ) {
			delete_transient( $key );
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', 'error' === $msg[0] ? 'error' : 'success', esc_html( (string) $msg[1] ) );
		}
	}

	// ---------- 表单处理 ----------

	public static function handle(): void {
		$action = substr( (string) current_action(), strlen( 'admin_post_' ) );
		if ( ! in_array( $action, self::ACTIONS, true ) || ! current_user_can( Media_Library::CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'peptide-core' ), 403 );
		}
		check_admin_referer( $action );
		// phpcs:disable WordPress.Security.NonceVerification -- 上面已用 check_admin_referer 校验。
		$back  = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : '';
		$back  = '' !== $back && str_starts_with( $back, admin_url() ) ? $back : self::url();
		$group = isset( $_POST['group'] ) ? max( 0, (int) $_POST['group'] ) : 0;
		$group = $group && isset( Media_Library::groups()[ $group ] ) ? $group : 0;

		switch ( $action ) {
			case 'pc_media_export':
				nocache_headers();
				header( 'Content-Type: text/csv; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename="media-' . gmdate( 'Ymd' ) . '.csv"' );
				echo Media_Library::export_csv(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV 下载。
				exit;

			case 'pc_media_upload':
				self::upload( $group );
				break;

			case 'pc_media_collect':
				$urls  = isset( $_POST['urls'] ) ? preg_split( '/\s+/', (string) wp_unslash( $_POST['urls'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Media_Library::enqueue 清理。
				$added = Media_Library::enqueue( (array) $urls, $group );
				if ( is_wp_error( $added ) ) {
					self::flash( 'error', $added->get_error_message() );
					break;
				}
				// 先处理几个，剩下的由定时任务处理。
				$r = Media_Library::process( 3 );
				/* translators: 1: addresses added, 2: collected now, 3: failed */
				self::flash( 'success', sprintf( __( 'Added to the queue: %1$d. Collected now: %2$d, failed: %3$d. The rest are collected in the background.', 'peptide-core' ), $added, $r['done'], $r['failed'] ) );
				break;

			case 'pc_media_bulk':
				$ids = isset( $_POST['media'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['media'] ) ) : array();
				$op  = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
				if ( ! $ids ) {
					self::flash( 'error', __( 'Select at least one media file.', 'peptide-core' ) );
				} elseif ( 'delete' === $op ) {
					$n = 0;
					foreach ( $ids as $id ) {
						if ( 'attachment' === get_post_type( $id ) && current_user_can( 'delete_post', $id ) && wp_delete_attachment( $id, true ) ) {
							++$n;
						}
					}
					/* translators: %d: number of files */
					self::flash( 'success', sprintf( __( 'Media files deleted: %d.', 'peptide-core' ), $n ) );
				} elseif ( 'move' === $op ) {
					$target = isset( $_POST['target'] ) ? (int) $_POST['target'] : 0;
					$target = $target > 0 && isset( Media_Library::groups()[ $target ] ) ? $target : 0;
					/* translators: %d: number of files */
					self::flash( 'success', sprintf( __( 'Group changed for %d media files.', 'peptide-core' ), Media_Library::set_group( $ids, $target ) ) );
				} else {
					self::flash( 'error', __( 'Choose a bulk action.', 'peptide-core' ) );
				}
				break;

			case 'pc_media_group_add':
				$made = Media_Library::create_group( isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '' );
				if ( is_wp_error( $made ) ) {
					self::flash( 'error', $made->get_error_message() );
				} else {
					self::flash( 'success', __( 'Group created.', 'peptide-core' ) );
					$back = self::url( array( 'group' => $made ) );
				}
				break;

			case 'pc_media_group_rename':
				$done = Media_Library::rename_group( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0, isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '' );
				self::flash( is_wp_error( $done ) ? 'error' : 'success', is_wp_error( $done ) ? $done->get_error_message() : __( 'Group renamed.', 'peptide-core' ) );
				break;

			case 'pc_media_group_delete':
				Media_Library::delete_group( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 );
				self::flash( 'success', __( 'Group deleted. Its media files are now ungrouped.', 'peptide-core' ) );
				$back = self::url();
				break;

			case 'pc_media_queue':
				$qids = isset( $_POST['queue'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['queue'] ) ) : array();
				$op   = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
				if ( 'run' === $op ) {
					$r = Media_Library::process( 5 );
					/* translators: 1: collected, 2: failed */
					self::flash( 'success', sprintf( __( 'Collected: %1$d, failed: %2$d.', 'peptide-core' ), $r['done'], $r['failed'] ) );
				} elseif ( ! $qids ) {
					self::flash( 'error', __( 'Select at least one address.', 'peptide-core' ) );
				} elseif ( 'retry' === $op ) {
					/* translators: %d: number of addresses */
					self::flash( 'success', sprintf( __( 'Queued again: %d.', 'peptide-core' ), Media_Library::retry( $qids ) ) );
				} elseif ( 'remove' === $op ) {
					/* translators: %d: number of addresses */
					self::flash( 'success', sprintf( __( 'Removed from the queue: %d.', 'peptide-core' ), Media_Library::dequeue( $qids ) ) );
				}
				break;
		}
		// phpcs:enable
		wp_safe_redirect( $back );
		exit;
	}

	private static function upload( int $group ): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$files = $_FILES['files'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- 已校验 nonce；WordPress 的上传函数检查文件。
		$names = is_array( $files ) && is_array( $files['name'] ?? null ) ? $files['name'] : array();
		$done  = 0;
		$errs  = array();
		foreach ( array_slice( array_keys( $names ), 0, self::MAX_UPLOAD ) as $i ) {
			if ( UPLOAD_ERR_NO_FILE === (int) $files['error'][ $i ] ) {
				continue;
			}
			$_FILES['pc_media_one'] = array(
				'name'     => $files['name'][ $i ],
				'type'     => $files['type'][ $i ],
				'tmp_name' => $files['tmp_name'][ $i ],
				'error'    => $files['error'][ $i ],
				'size'     => $files['size'][ $i ],
			);
			$id = media_handle_upload( 'pc_media_one', 0 );
			if ( is_wp_error( $id ) ) {
				$errs[] = sanitize_file_name( (string) $files['name'][ $i ] ) . ': ' . $id->get_error_message();
				continue;
			}
			if ( $group ) {
				wp_set_object_terms( (int) $id, array( $group ), Media_Library::GROUP_TAX );
			}
			++$done;
		}
		unset( $_FILES['pc_media_one'] );
		/* translators: %d: number of files */
		$text = sprintf( __( 'Uploaded: %d.', 'peptide-core' ), $done );
		self::flash( $errs ? 'error' : 'success', trim( $text . ' ' . implode( ' ', array_slice( $errs, 0, 5 ) ) ) );
	}

	// ---------- 页面 ----------

	public static function render(): void {
		if ( ! current_user_can( Media_Library::CAP ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 只读的筛选参数。
		$tab      = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'all';
		$tab      = in_array( $tab, array( 'all', 'collecting', 'retrying', 'failed' ), true ) ? $tab : 'all';
		$queue    = Media_Library::queue();
		$counts   = array_count_values( array_map( array( Media_Library::class, 'queue_tab' ), $queue ) );
		$tabs     = array(
			'all'        => __( 'All media', 'peptide-core' ),
			'collecting' => __( 'Being collected', 'peptide-core' ),
			'retrying'   => __( 'Retrying', 'peptide-core' ),
			'failed'     => __( 'Collection failed', 'peptide-core' ),
		);
		// phpcs:enable
		?>
		<div class="wrap pc-media">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Media center', 'peptide-core' ); ?></h1>
			<form class="pc-media-inline" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pc_media_export">
				<?php wp_nonce_field( 'pc_media_export' ); ?>
				<button type="submit" class="page-title-action"><?php esc_html_e( 'Export media', 'peptide-core' ); ?></button>
			</form>
			<hr class="wp-header-end">
			<?php self::notices(); ?>
			<nav class="nav-tab-wrapper pc-media-tabs" aria-label="<?php esc_attr_e( 'Media views', 'peptide-core' ); ?>">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a class="nav-tab<?php echo $tab === $key ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::url( 'all' === $key ? array() : array( 'tab' => $key ) ) ); ?>" <?php echo $tab === $key ? 'aria-current="page"' : ''; ?>>
						<?php echo esc_html( $label ); ?>
						<?php if ( 'all' !== $key ) : ?>
							<span class="count">(<?php echo (int) ( $counts[ $key ] ?? 0 ); ?>)</span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</nav>
			<?php
			if ( 'all' === $tab ) {
				self::render_library();
			} else {
				self::render_queue( $tab, $queue );
			}
			?>
		</div>
		<?php
	}

	private static function render_library(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 只读的筛选参数。
		$groups   = Media_Library::groups();
		$group    = isset( $_GET['group'] ) ? (int) $_GET['group'] : 0;
		$group    = -1 === $group || isset( $groups[ $group ] ) ? $group : 0;
		$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$per_page = isset( $_GET['per_page'] ) && in_array( (int) $_GET['per_page'], array( 20, 50, 100 ), true ) ? (int) $_GET['per_page'] : 50;
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable
		[ $items, $total ] = Media_Library::query(
			array(
				's'        => $search,
				'group'    => $group,
				'paged'    => $paged,
				'per_page' => $per_page,
			)
		);
		$pages   = max( 1, (int) ceil( $total / $per_page ) );
		$here    = self::url( array_filter( array( 'group' => $group, 's' => $search, 'per_page' => 50 !== $per_page ? $per_page : 0, 'paged' => $paged > 1 ? $paged : 0 ) ) );
		$all     = (int) wp_count_posts( 'attachment' )->inherit;
		$grouped = array_sum( array_column( $groups, 'count' ) );
		?>
		<div class="pc-media-layout">
			<aside class="pc-media-groups" aria-label="<?php esc_attr_e( 'Media groups', 'peptide-core' ); ?>">
				<ul>
					<li><a class="<?php echo 0 === $group ? 'is-current' : ''; ?>" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'All media', 'peptide-core' ); ?> <span class="count"><?php echo (int) $all; ?></span></a></li>
					<li><a class="<?php echo -1 === $group ? 'is-current' : ''; ?>" href="<?php echo esc_url( self::url( array( 'group' => -1 ) ) ); ?>"><?php esc_html_e( 'Ungrouped', 'peptide-core' ); ?> <span class="count"><?php echo (int) max( 0, $all - $grouped ); ?></span></a></li>
					<?php foreach ( $groups as $g ) : ?>
						<li><a class="<?php echo $g['id'] === $group ? 'is-current' : ''; ?>" href="<?php echo esc_url( self::url( array( 'group' => $g['id'] ) ) ); ?>"><?php echo esc_html( $g['name'] ); ?> <span class="count"><?php echo (int) $g['count']; ?></span></a></li>
					<?php endforeach; ?>
				</ul>
				<details class="pc-media-newgroup">
					<summary class="button"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span><?php esc_html_e( 'New group', 'peptide-core' ); ?></summary>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="pc_media_group_add">
						<?php wp_nonce_field( 'pc_media_group_add' ); ?>
						<label class="screen-reader-text" for="pc-media-group-name"><?php esc_html_e( 'Group name', 'peptide-core' ); ?></label>
						<input type="text" id="pc-media-group-name" name="name" maxlength="60" placeholder="<?php esc_attr_e( 'Group name', 'peptide-core' ); ?>" required>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Create', 'peptide-core' ); ?></button>
					</form>
				</details>
				<?php if ( $group > 0 ) : ?>
					<details class="pc-media-editgroup">
						<summary><?php esc_html_e( 'Rename or delete this group', 'peptide-core' ); ?></summary>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="pc_media_group_rename">
							<input type="hidden" name="id" value="<?php echo (int) $group; ?>">
							<input type="hidden" name="back" value="<?php echo esc_url( $here ); ?>">
							<?php wp_nonce_field( 'pc_media_group_rename' ); ?>
							<label class="screen-reader-text" for="pc-media-group-rename"><?php esc_html_e( 'Group name', 'peptide-core' ); ?></label>
							<input type="text" id="pc-media-group-rename" name="name" maxlength="60" value="<?php echo esc_attr( $groups[ $group ]['name'] ); ?>" required>
							<button type="submit" class="button"><?php esc_html_e( 'Rename', 'peptide-core' ); ?></button>
						</form>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Delete this group? Its media files are kept and become ungrouped.', 'peptide-core' ) ) ); ?>);">
							<input type="hidden" name="action" value="pc_media_group_delete">
							<input type="hidden" name="id" value="<?php echo (int) $group; ?>">
							<?php wp_nonce_field( 'pc_media_group_delete' ); ?>
							<button type="submit" class="button-link button-link-delete"><?php esc_html_e( 'Delete group', 'peptide-core' ); ?></button>
						</form>
					</details>
				<?php endif; ?>
			</aside>

			<div class="pc-media-main">
				<div class="pc-media-tools">
					<form class="pc-media-search" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
						<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
						<?php if ( 0 !== $group ) : ?>
							<input type="hidden" name="group" value="<?php echo (int) $group; ?>">
						<?php endif; ?>
						<label class="screen-reader-text" for="pc-media-s"><?php esc_html_e( 'Search media', 'peptide-core' ); ?></label>
						<input type="search" id="pc-media-s" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Enter a media name', 'peptide-core' ); ?>">
						<button type="submit" class="button"><?php esc_html_e( 'Search', 'peptide-core' ); ?></button>
					</form>
					<details class="pc-media-drop">
						<summary class="button button-primary"><?php esc_html_e( 'Upload media', 'peptide-core' ); ?></summary>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
							<input type="hidden" name="action" value="pc_media_upload">
							<input type="hidden" name="back" value="<?php echo esc_url( $here ); ?>">
							<?php wp_nonce_field( 'pc_media_upload' ); ?>
							<p><label for="pc-media-files"><?php echo esc_html( sprintf( /* translators: %d: maximum number of files */ __( 'Files (up to %d at a time)', 'peptide-core' ), self::MAX_UPLOAD ) ); ?></label><br><input type="file" id="pc-media-files" name="files[]" multiple required></p>
							<?php self::group_select( 'group', $group > 0 ? $group : 0, 'pc-media-upload-group' ); ?>
							<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Upload', 'peptide-core' ); ?></button></p>
						</form>
					</details>
					<details class="pc-media-drop">
						<summary class="button"><?php esc_html_e( 'Collect from addresses', 'peptide-core' ); ?></summary>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="pc_media_collect">
							<input type="hidden" name="back" value="<?php echo esc_url( self::url( array( 'tab' => 'collecting' ) ) ); ?>">
							<?php wp_nonce_field( 'pc_media_collect' ); ?>
							<p><label for="pc-media-urls"><?php echo esc_html( sprintf( /* translators: %d: maximum number of addresses */ __( 'Image, video or PDF addresses, one per line (up to %d)', 'peptide-core' ), Media_Library::MAX_SUBMIT ) ); ?></label><br><textarea id="pc-media-urls" name="urls" rows="5" class="large-text" placeholder="https://example.com/image.png" required spellcheck="false"></textarea></p>
							<?php self::group_select( 'group', $group > 0 ? $group : 0, 'pc-media-collect-group' ); ?>
							<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Collect', 'peptide-core' ); ?></button></p>
						</form>
					</details>
				</div>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pc-media-list" data-confirm-delete>
					<input type="hidden" name="action" value="pc_media_bulk">
					<input type="hidden" name="back" value="<?php echo esc_url( $here ); ?>">
					<?php wp_nonce_field( 'pc_media_bulk' ); ?>
					<div class="pc-media-bulk">
						<label class="screen-reader-text" for="pc-media-op"><?php esc_html_e( 'Bulk actions', 'peptide-core' ); ?></label>
						<select id="pc-media-op" name="op">
							<option value=""><?php esc_html_e( 'Bulk actions', 'peptide-core' ); ?></option>
							<option value="move"><?php esc_html_e( 'Change group to…', 'peptide-core' ); ?></option>
							<option value="delete"><?php esc_html_e( 'Delete permanently', 'peptide-core' ); ?></option>
						</select>
						<?php self::group_select( 'target', 0, 'pc-media-target', true ); ?>
						<button type="submit" class="button"><?php esc_html_e( 'Apply bulk action', 'peptide-core' ); ?></button>
						<span class="pc-media-total">
							<?php
							/* translators: %d: number of media files */
							echo esc_html( sprintf( __( '%d items', 'peptide-core' ), $total ) );
							?>
						</span>
					</div>
					<div class="pc-media-scroll">
						<table class="widefat striped pc-media-table">
							<thead>
								<tr>
									<td class="manage-column column-cb check-column"><input type="checkbox" aria-label="<?php esc_attr_e( 'Select all', 'peptide-core' ); ?>"></td>
									<th scope="col"><?php esc_html_e( 'Media file', 'peptide-core' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Size (px)', 'peptide-core' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Address', 'peptide-core' ); ?></th>
									<th scope="col"><?php esc_html_e( 'File size', 'peptide-core' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Type', 'peptide-core' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Group', 'peptide-core' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Collected from', 'peptide-core' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php if ( ! $items ) : ?>
									<tr><td colspan="8"><?php esc_html_e( 'No media files here.', 'peptide-core' ); ?></td></tr>
								<?php endif; ?>
								<?php foreach ( $items as $it ) : ?>
									<?php self::render_row( $it, $groups ); ?>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</form>
				<?php self::pagination( $paged, $pages, $per_page, $group, $search ); ?>
			</div>
		</div>
		<?php
	}

	private static function render_row( array $it, array $groups ): void {
		?>
		<tr>
			<th scope="row" class="check-column"><input type="checkbox" name="media[]" value="<?php echo (int) $it['id']; ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: file name */ __( 'Select %s', 'peptide-core' ), $it['name'] ) ); ?>"></th>
			<td class="pc-media-file" data-label="<?php esc_attr_e( 'Media file', 'peptide-core' ); ?>">
				<span class="pc-media-thumb">
					<?php if ( '' !== $it['thumb'] ) : ?>
						<img src="<?php echo esc_url( $it['thumb'] ); ?>" alt="" loading="lazy">
					<?php else : ?>
						<span class="dashicons dashicons-media-default" aria-hidden="true"></span>
					<?php endif; ?>
				</span>
				<span>
					<?php if ( current_user_can( 'edit_post', $it['id'] ) ) : ?>
						<a href="<?php echo esc_url( get_edit_post_link( $it['id'] ) ); ?>"><?php echo esc_html( $it['name'] ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $it['name'] ); ?>
					<?php endif; ?>
				</span>
			</td>
			<td data-label="<?php esc_attr_e( 'Size (px)', 'peptide-core' ); ?>"><?php echo $it['width'] ? esc_html( $it['width'] . '*' . $it['height'] ) : '—'; ?></td>
			<td class="pc-media-url" data-label="<?php esc_attr_e( 'Address', 'peptide-core' ); ?>">
				<input type="text" readonly value="<?php echo esc_attr( $it['url'] ); ?>" aria-label="<?php esc_attr_e( 'Address', 'peptide-core' ); ?>">
				<button type="button" class="button button-small pc-media-copy" data-copy="<?php echo esc_attr( $it['url'] ); ?>"><?php esc_html_e( 'Copy', 'peptide-core' ); ?></button>
			</td>
			<td data-label="<?php esc_attr_e( 'File size', 'peptide-core' ); ?>"><?php echo $it['size'] ? esc_html( size_format( $it['size'], 2 ) ) : '—'; ?></td>
			<td data-label="<?php esc_attr_e( 'Type', 'peptide-core' ); ?>"><?php echo esc_html( $it['mime'] ); ?></td>
			<td data-label="<?php esc_attr_e( 'Group', 'peptide-core' ); ?>"><?php echo esc_html( $groups[ $it['group'] ]['name'] ?? '—' ); ?></td>
			<td class="pc-media-source" data-label="<?php esc_attr_e( 'Collected from', 'peptide-core' ); ?>"><?php echo '' !== $it['source'] ? '<a href="' . esc_url( $it['source'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $it['source'] ) . '</a>' : '—'; ?></td>
		</tr>
		<?php
	}

	private static function group_select( string $name, int $selected, string $id, bool $for_bulk = false ): void {
		$groups = Media_Library::groups();
		echo '<label' . ( $for_bulk ? ' class="screen-reader-text"' : '' ) . ' for="' . esc_attr( $id ) . '">' . esc_html__( 'Group', 'peptide-core' ) . '</label> ';
		echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
		echo '<option value="0">' . esc_html__( 'No group', 'peptide-core' ) . '</option>';
		foreach ( $groups as $g ) {
			echo '<option value="' . (int) $g['id'] . '"' . selected( $selected, $g['id'], false ) . '>' . esc_html( $g['name'] ) . '</option>';
		}
		echo '</select>';
	}

	private static function pagination( int $paged, int $pages, int $per_page, int $group, string $search ): void {
		$base = array_filter( array( 'group' => $group, 's' => $search, 'per_page' => 50 !== $per_page ? $per_page : 0 ) );
		?>
		<nav class="pc-media-pages" aria-label="<?php esc_attr_e( 'Pages', 'peptide-core' ); ?>">
			<?php if ( $paged > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( self::url( $base + array( 'paged' => $paged - 1 ) ) ); ?>">‹ <?php esc_html_e( 'Previous page', 'peptide-core' ); ?></a>
			<?php endif; ?>
			<span>
				<?php
				/* translators: 1: current page, 2: total pages */
				echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'peptide-core' ), $paged, $pages ) );
				?>
			</span>
			<?php if ( $paged < $pages ) : ?>
				<a class="button" href="<?php echo esc_url( self::url( $base + array( 'paged' => $paged + 1 ) ) ); ?>"><?php esc_html_e( 'Next page', 'peptide-core' ); ?> ›</a>
			<?php endif; ?>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<?php foreach ( array_diff_key( $base, array( 'per_page' => 0 ) ) as $k => $v ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( (string) $v ); ?>">
				<?php endforeach; ?>
				<label class="screen-reader-text" for="pc-media-per-page"><?php esc_html_e( 'Items per page', 'peptide-core' ); ?></label>
				<select id="pc-media-per-page" name="per_page" onchange="this.form.submit()">
					<?php foreach ( array( 20, 50, 100 ) as $n ) : ?>
						<option value="<?php echo (int) $n; ?>" <?php selected( $per_page, $n ); ?>>
							<?php
							/* translators: %d: items per page */
							echo esc_html( sprintf( __( '%d per page', 'peptide-core' ), $n ) );
							?>
						</option>
					<?php endforeach; ?>
				</select>
				<noscript><button type="submit" class="button"><?php esc_html_e( 'Apply', 'peptide-core' ); ?></button></noscript>
			</form>
		</nav>
		<?php
	}

	private static function render_queue( string $tab, array $queue ): void {
		$rows   = array_filter( $queue, static fn( array $it ): bool => Media_Library::queue_tab( $it ) === $tab );
		$groups = Media_Library::groups();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pc-media-queue">
			<input type="hidden" name="action" value="pc_media_queue">
			<input type="hidden" name="back" value="<?php echo esc_url( self::url( array( 'tab' => $tab ) ) ); ?>">
			<?php wp_nonce_field( 'pc_media_queue' ); ?>
			<p class="description">
				<?php
				if ( 'failed' === $tab ) {
					esc_html_e( 'These addresses could not be downloaded. Check the reason, then retry or remove them.', 'peptide-core' );
				} else {
					esc_html_e( 'Waiting addresses are downloaded in the background, a few at a time. “Collect now” downloads the next few immediately.', 'peptide-core' );
				}
				?>
			</p>
			<div class="pc-media-bulk">
				<?php if ( 'failed' === $tab ) : ?>
					<button type="submit" name="op" value="retry" class="button button-primary"><?php esc_html_e( 'Retry selected', 'peptide-core' ); ?></button>
				<?php else : ?>
					<button type="submit" name="op" value="run" class="button button-primary"><?php esc_html_e( 'Collect now', 'peptide-core' ); ?></button>
				<?php endif; ?>
				<button type="submit" name="op" value="remove" class="button"><?php esc_html_e( 'Remove selected', 'peptide-core' ); ?></button>
			</div>
			<div class="pc-media-scroll">
				<table class="widefat striped pc-media-table">
					<thead>
						<tr>
							<td class="manage-column column-cb check-column"><input type="checkbox" aria-label="<?php esc_attr_e( 'Select all', 'peptide-core' ); ?>"></td>
							<th scope="col"><?php esc_html_e( 'Collected from', 'peptide-core' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Group', 'peptide-core' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Attempts', 'peptide-core' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Reason', 'peptide-core' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Added', 'peptide-core' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( ! $rows ) : ?>
							<tr><td colspan="6"><?php esc_html_e( 'Nothing here.', 'peptide-core' ); ?></td></tr>
						<?php endif; ?>
						<?php foreach ( $rows as $it ) : ?>
							<tr>
								<th scope="row" class="check-column"><input type="checkbox" name="queue[]" value="<?php echo esc_attr( $it['id'] ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: address */ __( 'Select %s', 'peptide-core' ), $it['url'] ) ); ?>"></th>
								<td class="pc-media-source" data-label="<?php esc_attr_e( 'Collected from', 'peptide-core' ); ?>"><a href="<?php echo esc_url( $it['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $it['url'] ); ?></a></td>
								<td data-label="<?php esc_attr_e( 'Group', 'peptide-core' ); ?>"><?php echo esc_html( $groups[ $it['group'] ]['name'] ?? '—' ); ?></td>
								<td data-label="<?php esc_attr_e( 'Attempts', 'peptide-core' ); ?>"><?php echo (int) $it['attempts']; ?></td>
								<td data-label="<?php esc_attr_e( 'Reason', 'peptide-core' ); ?>"><?php echo '' !== $it['error'] ? esc_html( $it['error'] ) : '—'; ?></td>
								<td data-label="<?php esc_attr_e( 'Added', 'peptide-core' ); ?>"><?php echo $it['added'] ? esc_html( wp_date( 'Y-m-d H:i', $it['added'] ) ) : '—'; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</form>
		<?php
	}
}
