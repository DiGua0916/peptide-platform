<?php
/**
 * 后台“店铺装修 → 域名管理”（照 YY 后台，用户 2026-10-27）。数据、检查和前台行为见 PeptideCore\Domains。
 *
 * - 列表：ID、域名、跳转域名、是否切换为主域名、HTTPS 状态、CDN 节点、连接状态、创建时间；
 *   按 HTTPS 状态、连接状态、创建日期、域名筛选；每行可以立即检查、设置跳转、删除，也可以批量检查 / 删除。
 * - 添加域名四步：输入域名 → 选择 CDN 节点 → 解析域名（要添加的解析记录、当前解析、立即检查）→ 成功。
 * - 查看要 Templates::CAP_EDIT；改动要 manage_options，因为切换主域名会改网站地址。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Domains;
use PeptideCore\Templates;

defined( 'ABSPATH' ) || exit;

final class Domains_Page {

	public const SLUG = 'pc-store-design-domains';

	/** 改动要管理员：切换主域名会改网站地址。 */
	public const CAP = 'manage_options';

	private const ACTIONS = array( 'pc_domain_add', 'pc_domain_check', 'pc_domain_delete', 'pc_domain_bulk', 'pc_domain_redirect', 'pc_domain_primary', 'pc_domain_server' );

	/** 一次最多检查几个域名（每个要请求两次，太多会超时）。 */
	private const CHECK_LIMIT = 20;

	public static function register_hooks(): void {
		foreach ( self::ACTIONS as $action ) {
			add_action( 'admin_post_' . $action, array( self::class, 'handle' ) );
		}
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	private static function action_url( string $action, array $args = array() ): string {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => $action ), $args ), admin_url( 'admin-post.php' ) ), $action );
	}

	public static function enqueue( string $hook ): void {
		if ( ! str_ends_with( $hook, self::SLUG ) ) {
			return;
		}
		$file = PEPTIDE_CORE_DIR . '/assets/domains-admin.css';
		wp_enqueue_style( 'peptide-core-domains-admin', plugins_url( 'assets/domains-admin.css', PEPTIDE_CORE_FILE ), array(), PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( $file ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	// ---------- 名称 ----------

	public static function cdn_label( string $cdn ): string {
		$labels = array(
			'cloudflare' => 'Cloudflare',
			'other'      => __( 'Other CDN', 'peptide-core' ),
			'none'       => __( 'No CDN', 'peptide-core' ),
		);
		return $labels[ $cdn ] ?? $cdn;
	}

	private static function conn_label( string $state ): string {
		$labels = array(
			'checking'  => __( 'Checking', 'peptide-core' ),
			'connected' => __( 'Connected', 'peptide-core' ),
			'failed'    => __( 'Not connected', 'peptide-core' ),
		);
		return $labels[ $state ] ?? $state;
	}

	private static function https_label( string $state ): string {
		$labels = array(
			'checking' => __( 'Checking', 'peptide-core' ),
			'active'   => __( 'In effect', 'peptide-core' ),
			'failed'   => __( 'Not in effect', 'peptide-core' ),
		);
		return $labels[ $state ] ?? $state;
	}

	private static function badge( string $text, string $state, string $title ): string {
		$tone = array(
			'connected' => 'is-ok',
			'active'    => 'is-ok',
			'checking'  => 'is-wait',
			'failed'    => 'is-bad',
		);
		return '<span class="pc-dm-badge ' . esc_attr( $tone[ $state ] ?? '' ) . '" title="' . esc_attr( $title ) . '">' . esc_html( $text ) . '</span>';
	}

	// ---------- 提示 ----------

	private static function flash( string $type, string $text ): void {
		set_transient( 'pc_domain_msg_' . get_current_user_id(), array( $type, $text ), 120 );
	}

	private static function notices(): void {
		$key = 'pc_domain_msg_' . get_current_user_id();
		$msg = get_transient( $key );
		if ( ! is_array( $msg ) || 2 !== count( $msg ) ) {
			return;
		}
		delete_transient( $key );
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', 'error' === $msg[0] ? 'error' : 'success', esc_html( (string) $msg[1] ) );
	}

	/** @param string[] $hosts */
	private static function ids_of( array $hosts ): string {
		$items = Domains::items();
		$ids   = array();
		foreach ( $hosts as $host ) {
			if ( isset( $items[ $host ] ) ) {
				$ids[] = $items[ $host ]['id'];
			}
		}
		return implode( ',', $ids );
	}

	// ---------- 表单处理 ----------

	public static function handle(): void {
		$action = substr( (string) current_action(), strlen( 'admin_post_' ) );
		if ( ! in_array( $action, self::ACTIONS, true ) || ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'peptide-core' ), 403 );
		}
		check_admin_referer( $action );

		// phpcs:disable WordPress.Security.NonceVerification -- 上面已用 check_admin_referer 校验。
		$clean = static fn( $value ): string => (string) Templates::normalize_host( sanitize_text_field( (string) $value ) );
		$hosts = isset( $_REQUEST['domains'] ) ? array_map( $clean, (array) wp_unslash( $_REQUEST['domains'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $clean 清理。
		if ( isset( $_REQUEST['domain'] ) ) {
			$hosts[] = $clean( wp_unslash( $_REQUEST['domain'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}
		$hosts = array_values( array_unique( array_filter( $hosts ) ) );
		$ids   = isset( $_POST['ids'] ) ? sanitize_text_field( wp_unslash( $_POST['ids'] ) ) : '';
		$back  = array();

		switch ( $action ) {
			case 'pc_domain_add':
				$added = Domains::add( $hosts, isset( $_POST['cdn'] ) ? sanitize_key( wp_unslash( $_POST['cdn'] ) ) : '' );
				if ( is_wp_error( $added ) ) {
					self::flash( 'error', $added->get_error_message() );
					$back = array( 'add' => 1 );
					break;
				}
				$back = array(
					'add'  => 1,
					'step' => 3,
					'ids'  => self::ids_of( $added ),
				);
				break;

			case 'pc_domain_check':
				self::check( $hosts );
				if ( '' !== $ids ) {
					$back = array(
						'add'  => 1,
						'step' => 3,
						'ids'  => $ids,
					);
				}
				break;

			case 'pc_domain_delete':
				self::delete( $hosts );
				break;

			case 'pc_domain_bulk':
				$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
				if ( ! $hosts ) {
					self::flash( 'error', __( 'Select at least one domain.', 'peptide-core' ) );
				} elseif ( 'delete' === $op ) {
					self::delete( $hosts );
				} elseif ( 'check' === $op ) {
					self::check( $hosts );
				} else {
					self::flash( 'error', __( 'Choose a bulk action.', 'peptide-core' ) );
				}
				break;

			case 'pc_domain_redirect':
				$target = isset( $_POST['target'] ) ? $clean( wp_unslash( $_POST['target'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$result = Domains::set_redirect( $hosts[0] ?? '', $target );
				if ( is_wp_error( $result ) ) {
					self::flash( 'error', $result->get_error_message() );
					$back = array( 'redirect' => $hosts[0] ?? '' );
				} else {
					self::flash( 'success', '' === $target ? __( 'Redirect removed.', 'peptide-core' ) : __( 'Redirect saved.', 'peptide-core' ) );
				}
				break;

			case 'pc_domain_primary':
				$result = Domains::set_primary( $hosts[0] ?? '' );
				if ( is_wp_error( $result ) ) {
					self::flash( 'error', $result->get_error_message() );
				} else {
					/* translators: %s: domain */
					self::flash( 'success', sprintf( __( '%s is now the primary domain. Links in the store and in emails use it.', 'peptide-core' ), $hosts[0] ?? '' ) );
				}
				break;

			case 'pc_domain_server':
				$result = Domains::save_server_ip( isset( $_POST['server_ip'] ) ? sanitize_text_field( wp_unslash( $_POST['server_ip'] ) ) : '' );
				self::flash( is_wp_error( $result ) ? 'error' : 'success', is_wp_error( $result ) ? $result->get_error_message() : __( 'Server IP saved.', 'peptide-core' ) );
				break;
		}
		// phpcs:enable

		wp_safe_redirect( self::url( $back ) );
		exit;
	}

	/** @param string[] $hosts */
	private static function check( array $hosts ): void {
		$done = 0;
		foreach ( array_slice( $hosts, 0, self::CHECK_LIMIT ) as $host ) {
			if ( Domains::check( $host ) ) {
				++$done;
			}
		}
		/* translators: %d: number of domains */
		self::flash( 'success', sprintf( __( 'Domains checked: %d. The results are shown below.', 'peptide-core' ), $done ) );
	}

	/** @param string[] $hosts */
	private static function delete( array $hosts ): void {
		$primary = in_array( Domains::primary_host(), $hosts, true );
		$removed = Domains::remove( $hosts );
		/* translators: %d: number of domains */
		$text = sprintf( __( 'Domains deleted: %d.', 'peptide-core' ), $removed );
		if ( $primary ) {
			$text .= ' ' . __( 'The primary domain cannot be deleted.', 'peptide-core' );
		}
		self::flash( 'success', $text );
	}

	// ---------- 页面 ----------

	public static function render(): void {
		if ( ! current_user_can( Templates::CAP_EDIT ) ) {
			return;
		}
		Domains::ensure_primary();
		$manage = current_user_can( self::CAP );
		echo '<div class="wrap pc-dm">';
		if ( $manage && isset( $_GET['add'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::render_wizard();
		} else {
			self::render_list( $manage );
		}
		echo '</div>';
	}

	/** 列表筛选条件（GET）。 */
	private static function filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 只读筛选。
		$get  = static fn( string $key ): string => isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
		$date = static fn( string $key ): string => 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $get( $key ) ) ? $get( $key ) : '';
		// phpcs:enable
		return array(
			'https' => in_array( $get( 'https' ), Domains::SSL_STATES, true ) ? $get( 'https' ) : '',
			'conn'  => in_array( $get( 'conn' ), Domains::CONN_STATES, true ) ? $get( 'conn' ) : '',
			'from'  => $date( 'from' ),
			'to'    => $date( 'to' ),
			's'     => strtolower( substr( $get( 's' ), 0, 253 ) ),
		);
	}

	private static function matches( array $item, array $f ): bool {
		$day = $item['created'] ? wp_date( 'Y-m-d', $item['created'] ) : '';
		return ( '' === $f['https'] || $item['https'] === $f['https'] )
			&& ( '' === $f['conn'] || $item['conn'] === $f['conn'] )
			&& ( '' === $f['from'] || $day >= $f['from'] )
			&& ( '' === $f['to'] || $day <= $f['to'] )
			&& ( '' === $f['s'] || str_contains( $item['host'], $f['s'] ) );
	}

	private static function render_list( bool $manage ): void {
		$items   = Domains::items();
		$primary = Domains::primary_host();
		$f       = self::filters();
		$rows    = array_filter( $items, static fn( array $item ): bool => self::matches( $item, $f ) );
		// 主域名在最前，其余按 ID 从新到旧。
		uasort( $rows, static fn( array $a, array $b ): int => array( $b['host'] === $primary, $b['id'] ) <=> array( $a['host'] === $primary, $a['id'] ) );
		$redirect = isset( $_GET['redirect'] ) ? (string) Templates::normalize_host( sanitize_text_field( wp_unslash( $_GET['redirect'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$cols     = $manage ? 10 : 9;
		?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Domains', 'peptide-core' ); ?></h1>
		<?php if ( $manage ) : ?>
			<a class="page-title-action" href="<?php echo esc_url( self::url( array( 'add' => 1 ) ) ); ?>"><?php esc_html_e( 'Add domain', 'peptide-core' ); ?></a>
		<?php endif; ?>
		<span class="pc-dm-limit">
			<?php
			/* translators: 1: bound domains, 2: maximum */
			echo esc_html( sprintf( __( '%1$d of up to %2$d domains bound', 'peptide-core' ), count( $items ), Domains::MAX ) );
			?>
		</span>
		<hr class="wp-header-end">
		<?php self::notices(); ?>

		<div class="pc-dm-tips">
			<ul>
				<li><?php esc_html_e( 'We suggest a domain redirect: send “www” to “@” or “@” to “www” (for example www.example.com → example.com), so all visits are counted under one domain.', 'peptide-core' ); ?></li>
				<li><?php esc_html_e( 'New domains are checked every hour for 7 days, then once a day. When HTTPS works, http visits are sent to https automatically.', 'peptide-core' ); ?></li>
			</ul>
		</div>

		<?php if ( Domains::is_local_site() ) : ?>
			<div class="notice notice-info inline">
				<p>
					<?php
					/* translators: %s: local site domain */
					echo esc_html( sprintf( __( 'The site runs on this computer (%s). Domains on the internet cannot reach it yet, so they show “Not connected” until the site is on a server.', 'peptide-core' ), $primary ) );
					?>
				</p>
			</div>
		<?php endif; ?>

		<?php if ( $manage ) : ?>
			<form class="pc-dm-server" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pc_domain_server">
				<?php wp_nonce_field( 'pc_domain_server' ); ?>
				<label for="pc-dm-ip"><?php esc_html_e( 'Server IP', 'peptide-core' ); ?></label>
				<input type="text" id="pc-dm-ip" name="server_ip" value="<?php echo esc_attr( '' !== Domains::server_ip() ? Domains::server_ip() : Domains::suggested_ip() ); ?>" placeholder="203.0.113.10" autocomplete="off" spellcheck="false">
				<button type="submit" class="button"><?php esc_html_e( 'Save', 'peptide-core' ); ?></button>
				<span class="description"><?php esc_html_e( 'The DNS records point domains to this address. Fill it in once the site is on a server.', 'peptide-core' ); ?></span>
			</form>
			<?php
			if ( '' !== $redirect ) {
				self::render_redirect_panel( $redirect, $items, $primary );
			}
			?>
		<?php endif; ?>

		<form class="pc-dm-filters" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
			<select name="https" aria-label="<?php esc_attr_e( 'HTTPS status', 'peptide-core' ); ?>">
				<option value=""><?php esc_html_e( 'All HTTPS statuses', 'peptide-core' ); ?></option>
				<?php foreach ( Domains::SSL_STATES as $state ) : ?>
					<option value="<?php echo esc_attr( $state ); ?>" <?php selected( $f['https'], $state ); ?>><?php echo esc_html( self::https_label( $state ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="conn" aria-label="<?php esc_attr_e( 'Connection status', 'peptide-core' ); ?>">
				<option value=""><?php esc_html_e( 'All connection statuses', 'peptide-core' ); ?></option>
				<?php foreach ( Domains::CONN_STATES as $state ) : ?>
					<option value="<?php echo esc_attr( $state ); ?>" <?php selected( $f['conn'], $state ); ?>><?php echo esc_html( self::conn_label( $state ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<span class="pc-dm-dates">
				<input type="date" name="from" value="<?php echo esc_attr( $f['from'] ); ?>" aria-label="<?php esc_attr_e( 'Created from', 'peptide-core' ); ?>">
				<span aria-hidden="true">~</span>
				<input type="date" name="to" value="<?php echo esc_attr( $f['to'] ); ?>" aria-label="<?php esc_attr_e( 'Created until', 'peptide-core' ); ?>">
			</span>
			<input type="search" name="s" value="<?php echo esc_attr( $f['s'] ); ?>" maxlength="253" placeholder="<?php esc_attr_e( 'Enter a domain', 'peptide-core' ); ?>" aria-label="<?php esc_attr_e( 'Search domains', 'peptide-core' ); ?>">
			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'peptide-core' ); ?></button>
			<?php if ( array_filter( $f ) ) : ?>
				<a href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Clear filters', 'peptide-core' ); ?></a>
			<?php endif; ?>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return 'delete' !== this.op.value || confirm(<?php echo esc_attr( wp_json_encode( __( 'Delete the selected domains? Visitors on them will no longer stay on that domain.', 'peptide-core' ) ) ); ?>);">
			<input type="hidden" name="action" value="pc_domain_bulk">
			<?php wp_nonce_field( 'pc_domain_bulk' ); ?>
			<?php if ( $manage ) : ?>
				<div class="tablenav top">
					<div class="alignleft actions bulkactions">
						<label class="screen-reader-text" for="pc-dm-op"><?php esc_html_e( 'Bulk actions', 'peptide-core' ); ?></label>
						<select name="op" id="pc-dm-op">
							<option value=""><?php esc_html_e( 'Bulk actions', 'peptide-core' ); ?></option>
							<option value="check"><?php esc_html_e( 'Check now', 'peptide-core' ); ?></option>
							<option value="delete"><?php esc_html_e( 'Delete', 'peptide-core' ); ?></option>
						</select>
						<button type="submit" class="button"><?php esc_html_e( 'Apply bulk action', 'peptide-core' ); ?></button>
					</div>
				</div>
			<?php endif; ?>
			<div class="pc-dm-scroll">
			<table class="widefat striped pc-dm-table">
				<thead>
					<tr>
						<?php if ( $manage ) : ?>
							<td class="manage-column column-cb check-column"><input type="checkbox" aria-label="<?php esc_attr_e( 'Select all', 'peptide-core' ); ?>"></td>
						<?php endif; ?>
						<th scope="col"><?php esc_html_e( 'Actions', 'peptide-core' ); ?></th>
						<th scope="col"><?php esc_html_e( 'ID', 'peptide-core' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Domain', 'peptide-core' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Redirects to', 'peptide-core' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Primary domain', 'peptide-core' ); ?></th>
						<th scope="col"><span title="<?php esc_attr_e( 'Checked every hour for 7 days. When HTTPS works, http visits are sent to https.', 'peptide-core' ); ?>"><?php esc_html_e( 'HTTPS status', 'peptide-core' ); ?> <span class="dashicons dashicons-editor-help" aria-hidden="true"></span></span></th>
						<th scope="col"><?php esc_html_e( 'CDN', 'peptide-core' ); ?></th>
						<th scope="col"><span title="<?php esc_attr_e( 'Checked every hour for 7 days, then daily: whether the domain really opens this store.', 'peptide-core' ); ?>"><?php esc_html_e( 'Connection status', 'peptide-core' ); ?> <span class="dashicons dashicons-editor-help" aria-hidden="true"></span></span></th>
						<th scope="col"><?php esc_html_e( 'Created', 'peptide-core' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $rows ) : ?>
						<tr><td colspan="<?php echo (int) $cols; ?>"><?php esc_html_e( 'No domains match.', 'peptide-core' ); ?></td></tr>
					<?php endif; ?>
					<?php
					foreach ( $rows as $item ) {
						self::render_row( $item, $primary, $manage );
					}
					?>
				</tbody>
			</table>
			</div>
		</form>
		<?php
	}

	private static function render_row( array $item, string $primary, bool $manage ): void {
		$host       = $item['host'];
		$is_primary = $host === $primary;
		/* translators: %s: date and time */
		$checked = $item['checked'] ? sprintf( __( 'Last checked: %s', 'peptide-core' ), wp_date( 'Y-m-d H:i', $item['checked'] ) ) : __( 'Not checked yet', 'peptide-core' );
		$scheme  = 'active' === $item['https'] ? 'https' : 'http';
		?>
		<tr>
			<?php if ( $manage ) : ?>
				<th scope="row" class="check-column">
					<input type="checkbox" name="domains[]" value="<?php echo esc_attr( $host ); ?>" <?php disabled( $is_primary ); ?> aria-label="<?php echo esc_attr( sprintf( /* translators: %s: domain */ __( 'Select %s', 'peptide-core' ), $host ) ); ?>">
				</th>
			<?php endif; ?>
			<td class="pc-dm-actions" data-label="<?php esc_attr_e( 'Actions', 'peptide-core' ); ?>">
				<?php if ( $manage ) : ?>
					<a href="<?php echo esc_url( self::action_url( 'pc_domain_check', array( 'domain' => $host ) ) ); ?>" title="<?php esc_attr_e( 'Check now', 'peptide-core' ); ?>"><span class="dashicons dashicons-update" aria-hidden="true"></span><span class="screen-reader-text"><?php echo esc_html( sprintf( /* translators: %s: domain */ __( 'Check %s now', 'peptide-core' ), $host ) ); ?></span></a>
					<?php if ( ! $is_primary ) : ?>
						<a href="<?php echo esc_url( self::url( array( 'redirect' => $host ) ) . '#pc-dm-redirect' ); ?>" title="<?php esc_attr_e( 'Domain redirect', 'peptide-core' ); ?>"><span class="dashicons dashicons-randomize" aria-hidden="true"></span><span class="screen-reader-text"><?php echo esc_html( sprintf( /* translators: %s: domain */ __( 'Set the redirect for %s', 'peptide-core' ), $host ) ); ?></span></a>
						<a class="pc-dm-delete" href="<?php echo esc_url( self::action_url( 'pc_domain_delete', array( 'domain' => $host ) ) ); ?>" title="<?php esc_attr_e( 'Delete', 'peptide-core' ); ?>" onclick="return confirm(<?php echo esc_attr( wp_json_encode( sprintf( /* translators: %s: domain */ __( 'Delete %s? Visitors on it will no longer stay on that domain.', 'peptide-core' ), $host ) ) ); ?>);"><span class="dashicons dashicons-trash" aria-hidden="true"></span><span class="screen-reader-text"><?php echo esc_html( sprintf( /* translators: %s: domain */ __( 'Delete %s', 'peptide-core' ), $host ) ); ?></span></a>
					<?php endif; ?>
				<?php else : ?>
					—
				<?php endif; ?>
			</td>
			<td data-label="<?php esc_attr_e( 'ID', 'peptide-core' ); ?>"><?php echo (int) $item['id']; ?></td>
			<td class="pc-dm-host" data-label="<?php esc_attr_e( 'Domain', 'peptide-core' ); ?>">
				<a href="<?php echo esc_url( $scheme . '://' . $host . '/' ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $host ); ?></a>
				<?php if ( $is_primary ) : ?>
					<span class="pc-dm-tag"><?php esc_html_e( 'Primary domain', 'peptide-core' ); ?></span>
				<?php endif; ?>
			</td>
			<td data-label="<?php esc_attr_e( 'Redirects to', 'peptide-core' ); ?>"><?php echo '' !== $item['redirect'] ? esc_html( $item['redirect'] ) : '—'; ?></td>
			<td data-label="<?php esc_attr_e( 'Primary domain', 'peptide-core' ); ?>"><?php self::render_switch( $item, $is_primary, $manage ); ?></td>
			<td data-label="<?php esc_attr_e( 'HTTPS status', 'peptide-core' ); ?>"><?php echo self::badge( self::https_label( $item['https'] ), $item['https'], trim( $checked . ' ' . $item['https_error'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() 已转义。 ?></td>
			<td data-label="<?php esc_attr_e( 'CDN', 'peptide-core' ); ?>"><?php echo esc_html( self::cdn_label( $item['cdn'] ) ); ?></td>
			<td class="pc-dm-conn" data-label="<?php esc_attr_e( 'Connection status', 'peptide-core' ); ?>">
				<?php echo self::badge( self::conn_label( $item['conn'] ), $item['conn'], trim( $checked . ' ' . $item['error'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php if ( '' !== $item['error'] ) : ?>
					<span class="pc-dm-error"><?php echo esc_html( $item['error'] ); ?></span>
				<?php endif; ?>
			</td>
			<td class="pc-dm-created" data-label="<?php esc_attr_e( 'Created', 'peptide-core' ); ?>"><?php echo $item['created'] ? esc_html( wp_date( 'Y-m-d H:i:s', $item['created'] ) ) : '—'; ?></td>
		</tr>
		<?php
	}

	/** “是否切换为主域名”开关：只有已连接、没有跳转的域名能打开。 */
	private static function render_switch( array $item, bool $is_primary, bool $manage ): void {
		$host = $item['host'];
		if ( $is_primary ) {
			echo '<span class="pc-dm-switch is-on" role="img" aria-label="' . esc_attr__( 'Primary domain', 'peptide-core' ) . '"></span>';
			return;
		}
		$why = '';
		if ( 'connected' !== $item['conn'] ) {
			$why = __( 'Only a connected domain can become the primary domain.', 'peptide-core' );
		} elseif ( '' !== $item['redirect'] ) {
			$why = __( 'This domain redirects to another domain.', 'peptide-core' );
		}
		if ( ! $manage || '' !== $why ) {
			echo '<span class="pc-dm-switch is-disabled" role="img" title="' . esc_attr( $why ) . '" aria-label="' . esc_attr( trim( __( 'Not the primary domain.', 'peptide-core' ) . ' ' . $why ) ) . '"></span>';
			return;
		}
		/* translators: %s: domain */
		$confirm = sprintf( __( 'Make %s the primary domain? The site address changes to this domain, so links in the store, in emails and in the admin use it. Other bound domains keep working.', 'peptide-core' ), $host );
		printf(
			'<a class="pc-dm-switch" href="%1$s" onclick="return confirm(%2$s);" aria-label="%3$s"></a>',
			esc_url( self::action_url( 'pc_domain_primary', array( 'domain' => $host ) ) ),
			esc_attr( wp_json_encode( $confirm ) ),
			/* translators: %s: domain */
			esc_attr( sprintf( __( 'Make %s the primary domain', 'peptide-core' ), $host ) )
		);
	}

	private static function render_redirect_panel( string $host, array $items, string $primary ): void {
		if ( ! isset( $items[ $host ] ) ) {
			return;
		}
		$current = $items[ $host ]['redirect'];
		$bare    = str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : '';
		$pick    = '' !== $current ? $current : ( isset( $items[ $bare ] ) && '' === $items[ $bare ]['redirect'] ? $bare : '' );
		?>
		<section class="pc-dm-card pc-dm-redirect" id="pc-dm-redirect">
			<h2><?php esc_html_e( 'Domain redirect', 'peptide-core' ); ?></h2>
			<?php if ( $host === $primary ) : ?>
				<p><?php esc_html_e( 'The primary domain cannot redirect. Switch the primary domain first.', 'peptide-core' ); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="pc_domain_redirect">
					<input type="hidden" name="domain" value="<?php echo esc_attr( $host ); ?>">
					<?php wp_nonce_field( 'pc_domain_redirect' ); ?>
					<p>
						<label for="pc-dm-target">
							<?php
							/* translators: %s: domain */
							echo esc_html( sprintf( __( 'Visits to %s go to', 'peptide-core' ), $host ) );
							?>
						</label>
						<select id="pc-dm-target" name="target">
							<option value=""><?php esc_html_e( 'No redirect (stay on this domain)', 'peptide-core' ); ?></option>
							<?php foreach ( $items as $other ) : ?>
								<?php
								if ( $other['host'] === $host || '' !== $other['redirect'] ) {
									continue;
								}
								?>
								<option value="<?php echo esc_attr( $other['host'] ); ?>" <?php selected( $pick, $other['host'] ); ?>><?php echo esc_html( $other['host'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</p>
					<p class="description"><?php esc_html_e( 'The page address stays the same, only the domain changes (301 redirect). Domains that redirect themselves are not offered, so redirects never chain.', 'peptide-core' ); ?></p>
					<p>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'peptide-core' ); ?></button>
						<a class="button" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Cancel', 'peptide-core' ); ?></a>
					</p>
				</form>
			<?php endif; ?>
		</section>
		<?php
	}

	// ---------- 添加域名（四步） ----------

	private static function render_wizard(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 第 1–2 步只读；第 2 步提交时有 nonce。
		$step  = isset( $_GET['step'] ) ? min( 4, max( 1, absint( $_GET['step'] ) ) ) : 1;
		$one   = isset( $_GET['domain'] ) ? sanitize_text_field( wp_unslash( $_GET['domain'] ) ) : '';
		$batch = isset( $_GET['batch'] ) ? sanitize_textarea_field( wp_unslash( $_GET['batch'] ) ) : '';
		$only  = ! empty( $_GET['only'] );
		$ids   = isset( $_GET['ids'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['ids'] ) ) ) ) ) : array();
		// phpcs:enable
		$error = '';
		$hosts = array();
		if ( 2 === $step ) {
			$hosts = Domains::expand( (array) preg_split( '/[\s,，]+/u', $one . "\n" . $batch ), $only );
			if ( is_wp_error( $hosts ) ) {
				$error = $hosts->get_error_message();
				$step  = 1;
			}
		}
		$picked = array();
		if ( $step >= 3 ) {
			foreach ( Domains::items() as $item ) {
				if ( in_array( $item['id'], $ids, true ) ) {
					$picked[ $item['host'] ] = $item;
				}
			}
			$step = $picked ? $step : 1;
		}
		$steps = array(
			1 => __( 'Enter domain', 'peptide-core' ),
			2 => __( 'Choose CDN', 'peptide-core' ),
			3 => __( 'Add DNS records', 'peptide-core' ),
			4 => __( 'Done', 'peptide-core' ),
		);
		?>
		<p class="pc-dm-back"><a href="<?php echo esc_url( self::url() ); ?>"><span class="dashicons dashicons-arrow-left-alt" aria-hidden="true"></span><?php esc_html_e( 'Back to the domain list', 'peptide-core' ); ?></a></p>
		<h1 class="screen-reader-text"><?php esc_html_e( 'Add domain', 'peptide-core' ); ?></h1>
		<ol class="pc-dm-steps">
			<?php foreach ( $steps as $n => $label ) : ?>
				<li class="<?php echo esc_attr( $n < $step ? 'is-done' : ( $n === $step ? 'is-current' : '' ) ); ?>" <?php echo $n === $step ? 'aria-current="step"' : ''; ?>>
					<span class="pc-dm-steps__n"><?php echo (int) $n; ?></span><?php echo esc_html( $label ); ?>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php
		self::notices();
		if ( 1 === $step ) {
			self::render_step_domain( $one, $batch, $only, $error );
		} elseif ( 2 === $step ) {
			self::render_step_cdn( $hosts, $one, $batch, $only );
		} elseif ( 3 === $step ) {
			self::render_step_dns( $picked, implode( ',', $ids ) );
		} else {
			self::render_step_done( $picked );
		}
	}

	private static function render_step_domain( string $one, string $batch, bool $only, string $error ): void {
		?>
		<section class="pc-dm-card pc-dm-step">
			<h2><?php esc_html_e( 'Bind a domain', 'peptide-core' ); ?></h2>
			<p><?php esc_html_e( 'Bind a domain you already own to the store, so customers who know the domain can find the store and shop.', 'peptide-core' ); ?></p>
			<p><?php esc_html_e( 'By default the domain and its “www” subdomain are both bound: entering example.com binds example.com and www.example.com.', 'peptide-core' ); ?></p>
			<?php if ( '' !== $error ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<input type="hidden" name="add" value="1">
				<input type="hidden" name="step" value="2">
				<p>
					<label class="screen-reader-text" for="pc-dm-domain"><?php esc_html_e( 'Domain', 'peptide-core' ); ?></label>
					<input type="text" id="pc-dm-domain" name="domain" class="large-text" value="<?php echo esc_attr( $one ); ?>" placeholder="<?php esc_attr_e( 'Enter a domain', 'peptide-core' ); ?>" autocomplete="off" spellcheck="false">
				</p>
				<p>
					<label><input type="checkbox" name="only" value="1" <?php checked( $only ); ?>> <?php esc_html_e( 'Only bind the domain I entered', 'peptide-core' ); ?></label><br>
					<span class="description"><?php esc_html_e( 'Checked: only this domain is bound. Unchecked: the domain and its www subdomain are bound (check with care).', 'peptide-core' ); ?></span>
				</p>
				<details class="pc-dm-batch" <?php echo '' !== $batch ? 'open' : ''; ?>>
					<summary><?php esc_html_e( 'Add several domains', 'peptide-core' ); ?></summary>
					<p>
						<label for="pc-dm-batch"><?php esc_html_e( 'One domain per line', 'peptide-core' ); ?></label>
						<textarea id="pc-dm-batch" name="batch" rows="6" class="large-text" spellcheck="false"><?php echo esc_textarea( $batch ); ?></textarea>
					</p>
				</details>
				<p class="pc-dm-step__actions"><button type="submit" class="button button-primary"><?php esc_html_e( 'Next', 'peptide-core' ); ?></button></p>
			</form>
		</section>
		<?php
	}

	/** @param string[] $hosts */
	private static function render_step_cdn( array $hosts, string $one, string $batch, bool $only ): void {
		$bound = Domains::hosts();
		$new   = array_values( array_diff( $hosts, $bound ) );
		$back  = self::url( array_filter( array( 'add' => 1, 'domain' => $one, 'batch' => $batch, 'only' => $only ? 1 : 0 ) ) );
		$cdns  = array(
			'cloudflare' => __( 'The domain’s DNS is managed in Cloudflare with the proxy on (orange cloud). Cloudflare provides the HTTPS certificate.', 'peptide-core' ),
			'other'      => __( 'Another CDN speeds up the store. Add the domain in the CDN with the server IP as the origin, then point the domain to the address the CDN gives you.', 'peptide-core' ),
			'none'       => __( 'Point the domain straight to the server at your domain registrar. The server needs its own HTTPS certificate.', 'peptide-core' ),
		);
		?>
		<section class="pc-dm-card pc-dm-step">
			<h2><?php esc_html_e( 'Choose a CDN', 'peptide-core' ); ?></h2>
			<p><?php esc_html_e( 'Domains to bind:', 'peptide-core' ); ?></p>
			<ul class="pc-dm-hostlist">
				<?php foreach ( $hosts as $host ) : ?>
					<li><?php echo esc_html( $host ); ?>
						<?php if ( in_array( $host, $bound, true ) ) : ?>
							<span class="pc-dm-badge"><?php esc_html_e( 'Already bound, skipped', 'peptide-core' ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( ! $new ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'These domains are already bound.', 'peptide-core' ); ?></p></div>
				<p class="pc-dm-step__actions"><a class="button" href="<?php echo esc_url( $back ); ?>"><?php esc_html_e( 'Previous', 'peptide-core' ); ?></a></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="pc_domain_add">
					<?php wp_nonce_field( 'pc_domain_add' ); ?>
					<?php foreach ( $new as $host ) : ?>
						<input type="hidden" name="domains[]" value="<?php echo esc_attr( $host ); ?>">
					<?php endforeach; ?>
					<fieldset class="pc-dm-cdns">
						<legend class="screen-reader-text"><?php esc_html_e( 'CDN', 'peptide-core' ); ?></legend>
						<?php foreach ( $cdns as $cdn => $desc ) : ?>
							<label class="pc-dm-cdn">
								<input type="radio" name="cdn" value="<?php echo esc_attr( $cdn ); ?>" <?php checked( 'cloudflare', $cdn ); ?>>
								<span><strong><?php echo esc_html( self::cdn_label( $cdn ) ); ?></strong><span><?php echo esc_html( $desc ); ?></span></span>
							</label>
						<?php endforeach; ?>
					</fieldset>
					<p class="pc-dm-step__actions">
						<a class="button" href="<?php echo esc_url( $back ); ?>"><?php esc_html_e( 'Previous', 'peptide-core' ); ?></a>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Next', 'peptide-core' ); ?></button>
					</p>
				</form>
			<?php endif; ?>
		</section>
		<?php
	}

	/** @param array<string,array> $picked */
	private static function render_step_dns( array $picked, string $ids ): void {
		$cdn = (string) reset( $picked )['cdn'];
		$ip  = Domains::server_ip();
		?>
		<section class="pc-dm-card pc-dm-step">
			<h2><?php esc_html_e( 'Add DNS records', 'peptide-core' ); ?></h2>
			<p><?php esc_html_e( 'Add these records where the domain’s DNS is managed. They usually take effect within minutes, sometimes up to 48 hours.', 'peptide-core' ); ?></p>
			<ul class="pc-dm-howto">
				<?php if ( 'cloudflare' === $cdn ) : ?>
					<li><?php esc_html_e( 'In Cloudflare → DNS, add the records below and keep the proxy on (orange cloud).', 'peptide-core' ); ?></li>
					<li><?php esc_html_e( 'In Cloudflare → SSL/TLS, choose “Full” when the server has a certificate, otherwise “Flexible”.', 'peptide-core' ); ?></li>
				<?php elseif ( 'other' === $cdn ) : ?>
					<li><?php esc_html_e( 'In the CDN, add each domain and set the origin to the server IP.', 'peptide-core' ); ?></li>
					<li><?php esc_html_e( 'Then add a CNAME record pointing to the address the CDN gives you. Many registrars do not allow a CNAME on “@”; follow the CDN’s instructions for the root domain.', 'peptide-core' ); ?></li>
				<?php else : ?>
					<li><?php esc_html_e( 'At your domain registrar, add the records below.', 'peptide-core' ); ?></li>
					<li><?php esc_html_e( 'Install an HTTPS certificate on the server (for example Let’s Encrypt).', 'peptide-core' ); ?></li>
				<?php endif; ?>
			</ul>
			<p class="pc-dm-ip">
				<?php esc_html_e( 'Server IP', 'peptide-core' ); ?>:
				<?php if ( '' !== $ip ) : ?>
					<code><?php echo esc_html( $ip ); ?></code>
				<?php else : ?>
					<em><?php esc_html_e( 'Not filled in yet. Fill it in on the domain list once the site is on a server.', 'peptide-core' ); ?></em>
				<?php endif; ?>
			</p>
			<div class="pc-dm-scroll">
				<table class="widefat striped pc-dm-records">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Domain', 'peptide-core' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Record type', 'peptide-core' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Host record', 'peptide-core' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Record value', 'peptide-core' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Current DNS', 'peptide-core' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Connection status', 'peptide-core' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $picked as $host => $item ) : ?>
							<?php
							$record = Domains::record( $host, $item['cdn'] );
							$dns    = Domains::lookup( $host );
							$found  = array_merge( array_map( static fn( $a ) => 'A ' . $a, $dns['a'] ), array_map( static fn( $c ) => 'CNAME ' . $c, $dns['cname'] ) );
							?>
							<tr>
								<td data-label="<?php esc_attr_e( 'Domain', 'peptide-core' ); ?>"><?php echo esc_html( $host ); ?></td>
								<td data-label="<?php esc_attr_e( 'Record type', 'peptide-core' ); ?>"><code><?php echo esc_html( $record['type'] ); ?></code></td>
								<td data-label="<?php esc_attr_e( 'Host record', 'peptide-core' ); ?>"><code><?php echo esc_html( $record['name'] ); ?></code></td>
								<td data-label="<?php esc_attr_e( 'Record value', 'peptide-core' ); ?>">
									<?php if ( '' !== $record['value'] ) : ?>
										<code><?php echo esc_html( $record['value'] ); ?></code>
									<?php elseif ( 'other' === $item['cdn'] ) : ?>
										<em><?php esc_html_e( 'Address from your CDN', 'peptide-core' ); ?></em>
									<?php else : ?>
										<em><?php esc_html_e( 'Server IP', 'peptide-core' ); ?></em>
									<?php endif; ?>
								</td>
								<td data-label="<?php esc_attr_e( 'Current DNS', 'peptide-core' ); ?>"><?php echo $found ? esc_html( implode( ', ', $found ) ) : esc_html__( 'No records found', 'peptide-core' ); ?></td>
								<td data-label="<?php esc_attr_e( 'Connection status', 'peptide-core' ); ?>">
									<?php echo self::badge( self::conn_label( $item['conn'] ), $item['conn'], $item['error'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<?php if ( '' !== $item['error'] ) : ?>
										<span class="pc-dm-error"><?php echo esc_html( $item['error'] ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<form class="pc-dm-step__actions" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pc_domain_check">
				<input type="hidden" name="ids" value="<?php echo esc_attr( $ids ); ?>">
				<?php wp_nonce_field( 'pc_domain_check' ); ?>
				<?php foreach ( array_keys( $picked ) as $host ) : ?>
					<input type="hidden" name="domains[]" value="<?php echo esc_attr( $host ); ?>">
				<?php endforeach; ?>
				<button type="submit" class="button"><?php esc_html_e( 'Check now', 'peptide-core' ); ?></button>
				<a class="button button-primary" href="<?php echo esc_url( self::url( array( 'add' => 1, 'step' => 4, 'ids' => $ids ) ) ); ?>"><?php esc_html_e( 'Next', 'peptide-core' ); ?></a>
			</form>
		</section>
		<?php
	}

	/** @param array<string,array> $picked */
	private static function render_step_done( array $picked ): void {
		?>
		<section class="pc-dm-card pc-dm-step pc-dm-done">
			<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
			<h2><?php esc_html_e( 'Domain added', 'peptide-core' ); ?></h2>
			<ul class="pc-dm-hostlist">
				<?php foreach ( array_keys( $picked ) as $host ) : ?>
					<li><?php echo esc_html( $host ); ?></li>
				<?php endforeach; ?>
			</ul>
			<p><?php esc_html_e( 'The domain is checked every hour for the next 7 days. Once it is connected and HTTPS works, the list shows it and http visits go to https automatically.', 'peptide-core' ); ?></p>
			<p class="pc-dm-step__actions">
				<a class="button button-primary" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Back to the domain list', 'peptide-core' ); ?></a>
				<a class="button" href="<?php echo esc_url( self::url( array( 'add' => 1 ) ) ); ?>"><?php esc_html_e( 'Add another domain', 'peptide-core' ); ?></a>
			</p>
		</section>
		<?php
	}
}
