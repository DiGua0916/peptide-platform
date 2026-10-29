<?php
/**
 * 后台“店铺设置 → 员工管理”（用户 2026-10-27：按菜单勾选权限，管理员直接设置密码）。权限规则见 PeptideCore\Staff。
 *
 * - 列表：账号、姓名、邮箱、权限、状态（启用 / 停用开关）、最后登录、创建时间、操作（编辑、删除）。管理员也列出来，标“全部权限”，不能在这里改。
 * - 添加 / 编辑：账号（建好后不能改）、邮箱、姓名、密码（新建必填，编辑时留空不改）、权限（勾选菜单）、状态。
 * - 删除员工：他写的博客、商品等内容转给当前管理员，不会一起删掉。
 * - 只有管理员（create_users）能管理员工。每次改动都写进操作记录。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Activity_Log;
use PeptideCore\Staff;

defined( 'ABSPATH' ) || exit;

final class Staff_Page {

	public const SLUG = 'pc-staff';
	public const CAP  = 'create_users';

	/** 密码最少几位。 */
	public const MIN_PASSWORD = 8;

	private const ACTIONS = array( 'pc_staff_save', 'pc_staff_delete', 'pc_staff_toggle' );

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ), 61 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		foreach ( self::ACTIONS as $action ) {
			add_action( 'admin_post_' . $action, array( self::class, 'handle' ) );
		}
	}

	public static function add_menu(): void {
		add_submenu_page( Admin_Menus::SETTINGS, __( 'Staff', 'peptide-core' ), __( 'Staff', 'peptide-core' ), self::CAP, self::SLUG, array( self::class, 'render' ) );
		// The visible entry is removed by Admin_Menus, so keep its direct admin.php route registered.
		// An empty string creates a hidden alias without PHP 8.3's null parent deprecation.
		add_submenu_page( '', __( 'Staff', 'peptide-core' ), __( 'Staff', 'peptide-core' ), self::CAP, self::SLUG, array( self::class, 'render' ) );
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	public static function enqueue( string $hook ): void {
		if ( str_ends_with( $hook, self::SLUG ) ) {
			wp_enqueue_style( 'peptide-core-media-admin', plugins_url( 'assets/media-admin.css', PEPTIDE_CORE_FILE ), array(), PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( PEPTIDE_CORE_DIR . '/assets/media-admin.css' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			wp_enqueue_style( 'peptide-core-domains-admin', plugins_url( 'assets/domains-admin.css', PEPTIDE_CORE_FILE ), array(), PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( PEPTIDE_CORE_DIR . '/assets/domains-admin.css' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	private static function flash( string $type, string $text ): void {
		set_transient( 'pc_staff_msg_' . get_current_user_id(), array( $type, $text ), 120 );
	}

	private static function notices(): void {
		$key = 'pc_staff_msg_' . get_current_user_id();
		$msg = get_transient( $key );
		if ( is_array( $msg ) && 2 === count( $msg ) ) {
			delete_transient( $key );
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', 'error' === $msg[0] ? 'error' : 'success', esc_html( (string) $msg[1] ) );
		}
	}

	private static function labels( array $modules ): string {
		$all = Staff::modules();
		return implode( ', ', array_map( static fn( string $k ): string => $all[ $k ]['label'] ?? $k, $modules ) );
	}

	// ---------- 保存 ----------

	/**
	 * 新建或修改员工。
	 *
	 * @param array{login?:string,email:string,name?:string,password?:string,modules?:string[],disabled?:bool} $data
	 * @return int|\WP_Error 员工的用户 ID
	 */
	public static function save( int $id, array $data ) {
		$email = sanitize_email( (string) ( $data['email'] ?? '' ) );
		$name  = mb_substr( sanitize_text_field( (string) ( $data['name'] ?? '' ) ), 0, 60 );
		$pass  = (string) ( $data['password'] ?? '' );
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'pc_staff_email', __( 'Enter a valid email address.', 'peptide-core' ) );
		}
		if ( ( ! $id || '' !== $pass ) && strlen( $pass ) < self::MIN_PASSWORD ) {
			/* translators: %d: minimum length */
			return new \WP_Error( 'pc_staff_password', sprintf( __( 'The password needs at least %d characters.', 'peptide-core' ), self::MIN_PASSWORD ) );
		}
		$user = array(
			'user_email'   => $email,
			'display_name' => '' !== $name ? $name : $email,
			'first_name'   => $name,
		);
		if ( $id ) {
			if ( ! Staff::is_staff( $id ) ) {
				return new \WP_Error( 'pc_staff_missing', __( 'This account is not a staff account.', 'peptide-core' ) );
			}
			$user['ID'] = $id;
			if ( '' !== $pass ) {
				$user['user_pass'] = $pass;
			}
			$result = wp_update_user( $user );
		} else {
			$login = sanitize_user( (string) ( $data['login'] ?? '' ), true );
			if ( '' === $login ) {
				return new \WP_Error( 'pc_staff_login', __( 'Enter an account name (letters, numbers, - _ . @).', 'peptide-core' ) );
			}
			$user['user_login'] = $login;
			$user['user_pass']  = $pass;
			$user['role']       = Staff::ROLE;
			$result             = wp_insert_user( $user );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$id      = (int) $result;
		$before  = array_values( array_intersect( array_keys( Staff::modules() ), (array) get_user_meta( $id, Staff::META_MODULES, true ) ) );
		$off     = ! empty( $data['disabled'] );
		$was_off = Staff::disabled( $id );
		$modules = Staff::set_modules( $id, (array) ( $data['modules'] ?? array() ) );
		Staff::set_disabled( $id, $off );
		$object = array( 'type' => 'user', 'id' => $id, 'label' => (string) get_userdata( $id )->user_login );
		if ( ! isset( $user['ID'] ) ) {
			Activity_Log::log( 'staff', 'create', $object, array( 'modules' => self::labels( $modules ) ) );
		} else {
			if ( $before !== $modules ) {
				Activity_Log::log( 'staff', 'permissions', $object, array( 'from' => self::labels( $before ), 'to' => self::labels( $modules ) ) );
			}
			if ( $off !== $was_off ) {
				Activity_Log::log( 'staff', $off ? 'disable' : 'enable', $object );
			}
			if ( '' !== $pass ) {
				Activity_Log::log( 'staff', 'password', $object );
			}
		}
		return $id;
	}

	public static function handle(): void {
		$action = substr( (string) current_action(), strlen( 'admin_post_' ) );
		if ( ! in_array( $action, self::ACTIONS, true ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'peptide-core' ), 403 );
		}
		check_admin_referer( $action );
		// phpcs:disable WordPress.Security.NonceVerification -- 上面已用 check_admin_referer 校验。
		$id = isset( $_REQUEST['user'] ) ? absint( $_REQUEST['user'] ) : 0;
		switch ( $action ) {
			case 'pc_staff_save':
				$data = array(
					'login'    => isset( $_POST['login'] ) ? sanitize_user( wp_unslash( $_POST['login'] ), true ) : '',
					'email'    => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
					'name'     => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
					'password' => isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 密码原样交给 WordPress 加密。
					'modules'  => isset( $_POST['modules'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['modules'] ) ) : array(),
					'disabled' => ! empty( $_POST['disabled'] ),
				);
				$result = self::save( $id, $data );
				if ( is_wp_error( $result ) ) {
					unset( $data['password'] );
					set_transient( 'pc_staff_form_' . get_current_user_id(), $data, 300 );
					self::flash( 'error', $result->get_error_message() );
					wp_safe_redirect( self::url( $id ? array( 'edit' => $id ) : array( 'add' => 1 ) ) );
					exit;
				}
				self::flash( 'success', $id ? __( 'Staff account saved.', 'peptide-core' ) : __( 'Staff account created. Tell the staff member the account name and password.', 'peptide-core' ) );
				break;

			case 'pc_staff_toggle':
				if ( Staff::is_staff( $id ) ) {
					$off = ! Staff::disabled( $id );
					Staff::set_disabled( $id, $off );
					Activity_Log::log( 'staff', $off ? 'disable' : 'enable', array( 'type' => 'user', 'id' => $id, 'label' => (string) get_userdata( $id )->user_login ) );
					self::flash( 'success', $off ? __( 'Staff account disabled. It is logged out and cannot log in.', 'peptide-core' ) : __( 'Staff account enabled.', 'peptide-core' ) );
				}
				break;

			case 'pc_staff_delete':
				if ( Staff::is_staff( $id ) && get_current_user_id() !== $id ) {
					require_once ABSPATH . 'wp-admin/includes/user.php';
					wp_delete_user( $id, get_current_user_id() );
					self::flash( 'success', __( 'Staff account deleted. Its posts and products now belong to you.', 'peptide-core' ) );
				}
				break;
		}
		// phpcs:enable
		wp_safe_redirect( self::url() );
		exit;
	}

	// ---------- 页面 ----------

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 只读参数。
		$edit = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$add  = isset( $_GET['add'] );
		// phpcs:enable
		echo '<div class="wrap pc-media pc-staff">';
		if ( $add || ( $edit && Staff::is_staff( $edit ) ) ) {
			self::render_form( $edit );
		} else {
			self::render_list();
		}
		echo '</div>';
	}

	private static function render_list(): void {
		$users = get_users(
			array(
				'role__in' => array( 'administrator', Staff::ROLE ),
				'orderby'  => 'registered',
				'order'    => 'ASC',
			)
		);
		?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Staff', 'peptide-core' ); ?></h1>
		<a class="page-title-action" href="<?php echo esc_url( self::url( array( 'add' => 1 ) ) ); ?>"><?php esc_html_e( 'Add staff', 'peptide-core' ); ?></a>
		<hr class="wp-header-end">
		<?php self::notices(); ?>
		<p class="description"><?php esc_html_e( 'Staff only see and open the menus ticked for them. Administrators always have every permission. All changes are written to the activity log.', 'peptide-core' ); ?></p>
		<div class="pc-media-scroll">
			<table class="widefat striped pc-media-table pc-staff-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Account', 'peptide-core' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Name', 'peptide-core' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Email', 'peptide-core' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Permissions', 'peptide-core' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'peptide-core' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Last login', 'peptide-core' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Created', 'peptide-core' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Actions', 'peptide-core' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $users as $u ) : ?>
						<?php
						$is_staff = Staff::is_staff( $u );
						$last     = (int) get_user_meta( $u->ID, Staff::META_LOGIN, true );
						$off      = $is_staff && Staff::disabled( $u->ID );
						$mods     = $is_staff ? array_values( array_intersect( array_keys( Staff::modules() ), (array) get_user_meta( $u->ID, Staff::META_MODULES, true ) ) ) : array();
						?>
						<tr>
							<td data-label="<?php esc_attr_e( 'Account', 'peptide-core' ); ?>">
								<strong><?php echo esc_html( $u->user_login ); ?></strong>
								<?php if ( ! $is_staff ) : ?>
									<span class="pc-dm-tag"><?php esc_html_e( 'Administrator', 'peptide-core' ); ?></span>
								<?php endif; ?>
							</td>
							<td data-label="<?php esc_attr_e( 'Name', 'peptide-core' ); ?>"><?php echo esc_html( $u->display_name ); ?></td>
							<td data-label="<?php esc_attr_e( 'Email', 'peptide-core' ); ?>" class="pc-media-source"><?php echo esc_html( $u->user_email ); ?></td>
							<td data-label="<?php esc_attr_e( 'Permissions', 'peptide-core' ); ?>" class="pc-staff-perms"><?php echo esc_html( $is_staff ? ( $mods ? self::labels( $mods ) : __( 'None', 'peptide-core' ) ) : __( 'All permissions', 'peptide-core' ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Status', 'peptide-core' ); ?>">
								<?php if ( $is_staff ) : ?>
									<a class="pc-dm-switch<?php echo $off ? '' : ' is-on'; ?>" role="switch" aria-checked="<?php echo $off ? 'false' : 'true'; ?>" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pc_staff_toggle&user=' . $u->ID ), 'pc_staff_toggle' ) ); ?>" aria-label="<?php echo esc_attr( sprintf( $off ? /* translators: %s: account */ __( 'Enable %s', 'peptide-core' ) : /* translators: %s: account */ __( 'Disable %s', 'peptide-core' ), $u->user_login ) ); ?>" onclick="return confirm(<?php echo esc_attr( wp_json_encode( $off ? __( 'Enable this staff account?', 'peptide-core' ) : __( 'Disable this staff account? It is logged out at once and cannot log in until enabled again.', 'peptide-core' ) ) ); ?>);"></a>
									<span class="pc-staff-state"><?php echo esc_html( $off ? __( 'Disabled', 'peptide-core' ) : __( 'Enabled', 'peptide-core' ) ); ?></span>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
							<td data-label="<?php esc_attr_e( 'Last login', 'peptide-core' ); ?>" class="pc-nowrap"><?php echo $last ? esc_html( wp_date( 'Y-m-d H:i', $last ) ) : '—'; ?></td>
							<td data-label="<?php esc_attr_e( 'Created', 'peptide-core' ); ?>" class="pc-nowrap"><?php echo esc_html( get_date_from_gmt( $u->user_registered, 'Y-m-d H:i' ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Actions', 'peptide-core' ); ?>" class="pc-nowrap">
								<?php if ( $is_staff ) : ?>
									<a href="<?php echo esc_url( self::url( array( 'edit' => $u->ID ) ) ); ?>"><?php esc_html_e( 'Edit', 'peptide-core' ); ?></a>
									|
									<a class="pc-dm-delete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pc_staff_delete&user=' . $u->ID ), 'pc_staff_delete' ) ); ?>" onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Delete this staff account? Its posts and products are kept and moved to you.', 'peptide-core' ) ) ); ?>);"><?php esc_html_e( 'Delete', 'peptide-core' ); ?></a>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function render_form( int $id ): void {
		$user = $id ? get_userdata( $id ) : null;
		$back = get_transient( 'pc_staff_form_' . get_current_user_id() );
		delete_transient( 'pc_staff_form_' . get_current_user_id() );
		$back = is_array( $back ) ? $back : array();
		$vals = array(
			'login'    => $back['login'] ?? ( $user ? $user->user_login : '' ),
			'email'    => $back['email'] ?? ( $user ? $user->user_email : '' ),
			'name'     => $back['name'] ?? ( $user ? (string) $user->first_name : '' ),
			'modules'  => $back['modules'] ?? ( $user ? (array) get_user_meta( $id, Staff::META_MODULES, true ) : array() ),
			'disabled' => $back['disabled'] ?? ( $user ? Staff::disabled( $id ) : false ),
		);
		?>
		<p class="pc-dm-back"><a href="<?php echo esc_url( self::url() ); ?>"><span class="dashicons dashicons-arrow-left-alt" aria-hidden="true"></span><?php esc_html_e( 'Back to staff', 'peptide-core' ); ?></a></p>
		<h1><?php echo esc_html( $id ? __( 'Edit staff', 'peptide-core' ) : __( 'Add staff', 'peptide-core' ) ); ?></h1>
		<?php self::notices(); ?>
		<form class="pc-dm-card pc-staff-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off">
			<input type="hidden" name="action" value="pc_staff_save">
			<input type="hidden" name="user" value="<?php echo (int) $id; ?>">
			<?php wp_nonce_field( 'pc_staff_save' ); ?>
			<p>
				<label for="pc-staff-login"><strong><?php esc_html_e( 'Account name', 'peptide-core' ); ?></strong></label><br>
				<input type="text" id="pc-staff-login" name="login" class="regular-text" value="<?php echo esc_attr( (string) $vals['login'] ); ?>" <?php echo $id ? 'readonly' : 'required'; ?> maxlength="60" spellcheck="false" autocomplete="off">
				<?php if ( $id ) : ?>
					<br><span class="description"><?php esc_html_e( 'The account name cannot be changed.', 'peptide-core' ); ?></span>
				<?php endif; ?>
			</p>
			<p>
				<label for="pc-staff-email"><strong><?php esc_html_e( 'Email', 'peptide-core' ); ?></strong></label><br>
				<input type="email" id="pc-staff-email" name="email" class="regular-text" value="<?php echo esc_attr( (string) $vals['email'] ); ?>" required>
			</p>
			<p>
				<label for="pc-staff-name"><strong><?php esc_html_e( 'Name', 'peptide-core' ); ?></strong></label><br>
				<input type="text" id="pc-staff-name" name="name" class="regular-text" value="<?php echo esc_attr( (string) $vals['name'] ); ?>" maxlength="60">
			</p>
			<p>
				<label for="pc-staff-pass"><strong><?php esc_html_e( 'Password', 'peptide-core' ); ?></strong></label><br>
				<input type="password" id="pc-staff-pass" name="password" class="regular-text" minlength="<?php echo (int) self::MIN_PASSWORD; ?>" autocomplete="new-password" <?php echo $id ? '' : 'required'; ?>>
				<br><span class="description">
					<?php
					echo esc_html(
						$id
							? __( 'Leave empty to keep the current password. A new password logs the staff member out everywhere.', 'peptide-core' )
							/* translators: %d: minimum length */
							: sprintf( __( 'At least %d characters. Tell the staff member the account name and password yourself.', 'peptide-core' ), self::MIN_PASSWORD )
					);
					?>
				</span>
			</p>
			<fieldset class="pc-staff-modules">
				<legend><strong><?php esc_html_e( 'Permissions (menus this staff member can use)', 'peptide-core' ); ?></strong></legend>
				<label class="pc-staff-all"><input type="checkbox" onclick="this.closest('fieldset').querySelectorAll('input[name=&quot;modules[]&quot;]').forEach(function(c){c.checked=this.checked;}.bind(this));"> <?php esc_html_e( 'Select all', 'peptide-core' ); ?></label>
				<?php foreach ( Staff::modules() as $key => $m ) : ?>
					<label><input type="checkbox" name="modules[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, (array) $vals['modules'], true ) ); ?>> <?php echo esc_html( $m['label'] ); ?></label>
				<?php endforeach; ?>
				<p class="description"><?php esc_html_e( 'Staff management, plugins, users and WordPress settings stay with administrators.', 'peptide-core' ); ?></p>
			</fieldset>
			<p>
				<label><input type="checkbox" name="disabled" value="1" <?php checked( (bool) $vals['disabled'] ); ?>> <?php esc_html_e( 'Disable this account (cannot log in)', 'peptide-core' ); ?></label>
			</p>
			<p class="pc-dm-step__actions">
				<button type="submit" class="button button-primary"><?php echo esc_html( $id ? __( 'Save', 'peptide-core' ) : __( 'Create staff account', 'peptide-core' ) ); ?></button>
				<a class="button" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Cancel', 'peptide-core' ); ?></a>
			</p>
		</form>
		<?php
	}
}
