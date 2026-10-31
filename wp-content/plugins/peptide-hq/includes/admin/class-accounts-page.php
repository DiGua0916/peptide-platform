<?php
/**
 * 总后台 → 销售账号：每个店铺的销售账号（店铺上的 pc_staff 账号）。
 * 一个销售一个店铺，所以店铺后台没有“用户”菜单，账号都在这里建：账号、邮箱、姓名、密码（管理员设置）、
 * 能用的菜单（勾选）、停用。数据实时从店铺读写，总后台不保存销售的密码。
 *
 * @package PeptideHq
 */

namespace PeptideHq\Admin;

use PeptideHq\Client;
use PeptideHq\Sites;

defined( 'ABSPATH' ) || exit;

final class Accounts_Page {

	public const SLUG = 'pchq-accounts';

	/** 密码最少几位（和店铺一样）。 */
	public const MIN_PASSWORD = 8;

	public static function render(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			return;
		}
		$sites = Sites::all();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 只读参数。
		$site = isset( $_GET['site'] ) ? absint( $_GET['site'] ) : (int) array_key_first( $sites );
		$edit = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$add  = isset( $_GET['add'] );
		// phpcs:enable
		?>
		<div class="wrap pchq">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Salesperson accounts', 'peptide-hq' ); ?></h1>
			<?php if ( isset( $sites[ $site ] ) && ! $add && ! $edit ) : ?>
				<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&site=' . $site . '&add=1' ) ); ?>"><?php esc_html_e( 'Add salesperson account', 'peptide-hq' ); ?></a>
			<?php endif; ?>
			<hr class="wp-header-end">
			<?php Menu::notices(); ?>
			<p class="pchq-muted"><?php esc_html_e( 'Each store is run by one salesperson. Their account lives on the store; create and change it here. The master admin does not keep their password.', 'peptide-hq' ); ?></p>

			<?php if ( ! $sites ) : ?>
				<p><?php esc_html_e( 'No store has joined yet.', 'peptide-hq' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Sites_Page::SLUG ) ); ?>"><?php esc_html_e( 'How stores join', 'peptide-hq' ); ?></a></p>
			</div>
				<?php
				return;
			endif;
			?>

			<nav class="pchq-ranges pchq-sitepick" aria-label="<?php esc_attr_e( 'Store', 'peptide-hq' ); ?>">
				<?php foreach ( $sites as $s ) : ?>
					<a class="<?php echo $s['id'] === $site ? 'is-current' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&site=' . $s['id'] ) ); ?>" <?php echo $s['id'] === $site ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $s['name'] ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php
			if ( ! isset( $sites[ $site ] ) ) {
				echo '</div>';
				return;
			}
			$list = Client::staff_list( $site );
			if ( is_wp_error( $list ) ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html( $list->get_error_message() ) . '</p></div></div>';
				return;
			}
			$accounts = (array) ( $list['accounts'] ?? array() );
			$current  = $edit ? current( array_filter( $accounts, static fn( $a ) => (int) $a['id'] === $edit ) ) : false;
			if ( $add || $current ) {
				self::render_form( $site, $current ? (array) $current : null );
			} else {
				self::render_list( $site, $accounts );
			}
			?>
		</div>
		<?php
	}

	private static function labels( array $keys ): string {
		$all = Menu::modules();
		return implode( ', ', array_map( static fn( $k ) => $all[ $k ] ?? $k, $keys ) );
	}

	private static function render_list( int $site, array $accounts ): void {
		if ( ! $accounts ) {
			echo '<div class="pchq-card pchq-empty"><p>' . esc_html__( 'This store has no salesperson account yet.', 'peptide-hq' ) . '</p><p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&site=' . $site . '&add=1' ) ) . '">' . esc_html__( 'Add salesperson account', 'peptide-hq' ) . '</a></p></div>';
			return;
		}
		?>
		<div class="pchq-scroll">
			<table class="widefat striped pchq-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Account', 'peptide-hq' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Name', 'peptide-hq' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Email', 'peptide-hq' ); ?></th>
						<th scope="col"><?php echo esc_html( str_starts_with( determine_locale(), 'zh' ) ? '账号专属登录入口' : __( 'Account login entry', 'peptide-hq' ) ); ?></th>
						<th scope="col"><?php esc_html_e( 'Menus they can use', 'peptide-hq' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'peptide-hq' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Last login', 'peptide-hq' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Actions', 'peptide-hq' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $accounts as $a ) : ?>
						<?php $off = ! empty( $a['disabled'] ); ?>
						<tr>
							<td data-label="<?php esc_attr_e( 'Account', 'peptide-hq' ); ?>"><strong><?php echo esc_html( (string) $a['login'] ); ?></strong></td>
							<td data-label="<?php esc_attr_e( 'Name', 'peptide-hq' ); ?>"><?php echo esc_html( (string) $a['name'] ); ?></td>
							<td data-label="<?php esc_attr_e( 'Email', 'peptide-hq' ); ?>"><?php echo esc_html( (string) $a['email'] ); ?></td>
							<td data-label="<?php echo esc_attr( str_starts_with( determine_locale(), 'zh' ) ? '登录入口' : __( 'Login entry', 'peptide-hq' ) ); ?>">
								<?php if ( ! $off && ! empty( $a['staff_entry_url'] ) && is_string( $a['staff_entry_url'] ) ) : ?>
									<label class="screen-reader-text" for="pchq-entry-<?php echo (int) $a['id']; ?>"><?php echo esc_html( str_starts_with( determine_locale(), 'zh' ) ? '账号专属登录入口' : __( 'Account login entry', 'peptide-hq' ) ); ?></label>
									<input type="url" readonly class="regular-text code" id="pchq-entry-<?php echo (int) $a['id']; ?>" value="<?php echo esc_url( $a['staff_entry_url'] ); ?>" spellcheck="false">
									<p class="description"><?php echo esc_html( str_starts_with( determine_locale(), 'zh' ) ? '复制此入口给该销售；仍需输入账号和密码。' : __( 'Copy this entry for this salesperson. Their account and password are still required.', 'peptide-hq' ) ); ?></p>
								<?php else : ?>
									<span class="pchq-muted"><?php echo esc_html( str_starts_with( determine_locale(), 'zh' ) ? '暂不可用' : __( 'Unavailable', 'peptide-hq' ) ); ?></span>
								<?php endif; ?>
							</td>
							<td data-label="<?php esc_attr_e( 'Menus they can use', 'peptide-hq' ); ?>" class="pchq-perms"><?php echo esc_html( $a['modules'] ? self::labels( (array) $a['modules'] ) : __( 'None', 'peptide-hq' ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Status', 'peptide-hq' ); ?>">
								<a class="pchq-switch<?php echo $off ? '' : ' is-on'; ?>" role="switch" aria-checked="<?php echo $off ? 'false' : 'true'; ?>" href="<?php echo esc_url( Menu::action_url( 'pchq_account_toggle', array( 'site' => $site, 'user' => (int) $a['id'] ) ) ); ?>" aria-label="<?php echo esc_attr( (string) $a['login'] ); ?>" onclick="return confirm(<?php echo esc_attr( wp_json_encode( $off ? __( 'Enable this account?', 'peptide-hq' ) : __( 'Disable this account? The salesperson is logged out at once and cannot log in until enabled again.', 'peptide-hq' ) ) ); ?>);"></a>
								<span class="pchq-muted"><?php echo esc_html( $off ? __( 'Disabled', 'peptide-hq' ) : __( 'Enabled', 'peptide-hq' ) ); ?></span>
							</td>
							<td data-label="<?php esc_attr_e( 'Last login', 'peptide-hq' ); ?>"><?php echo esc_html( Menu::ago( (int) $a['last_login'] ) ); ?></td>
							<td data-label="<?php esc_attr_e( 'Actions', 'peptide-hq' ); ?>">
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&site=' . $site . '&edit=' . (int) $a['id'] ) ); ?>"><?php esc_html_e( 'Edit', 'peptide-hq' ); ?></a>
								|
								<a class="pchq-delete" href="<?php echo esc_url( Menu::action_url( 'pchq_account_delete', array( 'site' => $site, 'user' => (int) $a['id'] ) ) ); ?>" onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Delete this salesperson account from the store? Its posts and products are kept and moved to the store administrator.', 'peptide-hq' ) ) ); ?>);"><?php esc_html_e( 'Delete', 'peptide-hq' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function render_form( int $site, ?array $a ): void {
		$back = get_transient( 'pchq_form_' . get_current_user_id() );
		delete_transient( 'pchq_form_' . get_current_user_id() );
		$back = is_array( $back ) ? $back : array();
		$v    = array(
			'login'    => $back['login'] ?? ( $a['login'] ?? '' ),
			'email'    => $back['email'] ?? ( $a['email'] ?? '' ),
			'name'     => $back['name'] ?? ( $a['name'] ?? '' ),
			'modules'  => $back['modules'] ?? ( $a ? (array) $a['modules'] : array_keys( Menu::modules() ) ),
			'disabled' => $back['disabled'] ?? ( ! empty( $a['disabled'] ) ),
		);
		$id   = $a ? (int) $a['id'] : 0;
		?>
		<form class="pchq-card pchq-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off">
			<h2><?php echo esc_html( $id ? __( 'Edit salesperson account', 'peptide-hq' ) : __( 'Add salesperson account', 'peptide-hq' ) ); ?></h2>
			<input type="hidden" name="action" value="pchq_account_save">
			<input type="hidden" name="site" value="<?php echo (int) $site; ?>">
			<input type="hidden" name="user" value="<?php echo (int) $id; ?>">
			<?php wp_nonce_field( 'pchq_account_save' ); ?>
			<p>
				<label for="pchq-login"><strong><?php esc_html_e( 'Account name', 'peptide-hq' ); ?></strong></label><br>
				<input type="text" id="pchq-login" name="login" class="regular-text" value="<?php echo esc_attr( (string) $v['login'] ); ?>" <?php echo $id ? 'readonly' : 'required'; ?> maxlength="60" spellcheck="false" autocomplete="off">
			</p>
			<p>
				<label for="pchq-email"><strong><?php esc_html_e( 'Email', 'peptide-hq' ); ?></strong></label><br>
				<input type="email" id="pchq-email" name="email" class="regular-text" value="<?php echo esc_attr( (string) $v['email'] ); ?>" required>
			</p>
			<p>
				<label for="pchq-aname"><strong><?php esc_html_e( 'Name', 'peptide-hq' ); ?></strong></label><br>
				<input type="text" id="pchq-aname" name="name" class="regular-text" value="<?php echo esc_attr( (string) $v['name'] ); ?>" maxlength="60">
			</p>
			<p>
				<label for="pchq-pass"><strong><?php esc_html_e( 'Password', 'peptide-hq' ); ?></strong></label><br>
				<input type="password" id="pchq-pass" name="password" class="regular-text" minlength="<?php echo (int) self::MIN_PASSWORD; ?>" autocomplete="new-password" <?php echo $id ? '' : 'required'; ?>>
				<br><span class="pchq-muted">
					<?php
					echo esc_html(
						$id
							? __( 'Leave empty to keep the current password. A new password logs the salesperson out everywhere.', 'peptide-hq' )
							/* translators: %d: minimum length */
							: sprintf( __( 'At least %d characters. Tell the salesperson the account name and password yourself.', 'peptide-hq' ), self::MIN_PASSWORD )
					);
					?>
				</span>
			</p>
			<fieldset class="pchq-modules">
				<legend><strong><?php esc_html_e( 'Menus the salesperson can use in the store admin', 'peptide-hq' ); ?></strong></legend>
				<?php foreach ( Menu::modules() as $key => $label ) : ?>
					<label><input type="checkbox" name="modules[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, (array) $v['modules'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
				<?php endforeach; ?>
				<p class="pchq-muted"><?php esc_html_e( 'Users, plugins and WordPress settings always stay with the administrator.', 'peptide-hq' ); ?></p>
			</fieldset>
			<p><label><input type="checkbox" name="disabled" value="1" <?php checked( (bool) $v['disabled'] ); ?>> <?php esc_html_e( 'Disable this account (cannot log in)', 'peptide-hq' ); ?></label></p>
			<p class="pchq-buttons">
				<button type="submit" class="button button-primary"><?php echo esc_html( $id ? __( 'Save', 'peptide-hq' ) : __( 'Create account on the store', 'peptide-hq' ) ); ?></button>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&site=' . $site ) ); ?>"><?php esc_html_e( 'Cancel', 'peptide-hq' ); ?></a>
			</p>
		</form>
		<?php
	}
}
