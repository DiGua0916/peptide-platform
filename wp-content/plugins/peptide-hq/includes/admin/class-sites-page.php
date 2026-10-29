<?php
/**
 * 总后台 → 店铺：每个店铺的状态、分配的服务器、域名、搜索引擎收录和访客追踪开关、销售账号数、最后检查时间；
 * 一键进店铺后台、马上刷新；“更多”里有平台工具（店铺后台不显示的页面）、系统信息、访客数据保留天数、改名、移除。
 * 店铺是自动接入的（开新店铺时在 wp-config.php 加两行，见 Register），这里显示要加的两行。
 *
 * @package PeptideHq
 */

namespace PeptideHq\Admin;

use PeptideHq\Platform;
use PeptideHq\Servers;
use PeptideHq\Sites;

defined( 'ABSPATH' ) || exit;

final class Sites_Page {

	public const SLUG = 'pchq-sites';

	public static function render(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			return;
		}
		$sites   = Sites::all();
		$servers = Servers::all();
		?>
		<div class="wrap pchq">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Stores', 'peptide-hq' ); ?></h1>
			<?php if ( $sites ) : ?>
				<a class="page-title-action" href="<?php echo esc_url( Menu::action_url( 'pchq_site_refresh' ) ); ?>"><?php esc_html_e( 'Refresh all stores now', 'peptide-hq' ); ?></a>
			<?php endif; ?>
			<hr class="wp-header-end">
			<?php Menu::notices(); ?>

			<details class="pchq-card pchq-add" <?php echo $sites ? '' : 'open'; ?>>
				<summary><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span><?php esc_html_e( 'Opening a new store', 'peptide-hq' ); ?></summary>
				<p><?php esc_html_e( 'Every store you open joins the master admin by itself. When you set up a new store, add these two lines to its wp-config.php (above “That’s all, stop editing!”). The store appears here as soon as its admin is opened once (at the latest within a day).', 'peptide-hq' ); ?></p>
				<p>
					<label for="pchq-lines" class="screen-reader-text"><?php esc_html_e( 'Lines for wp-config.php', 'peptide-hq' ); ?></label>
					<textarea id="pchq-lines" class="large-text code" rows="3" readonly onclick="this.select()"><?php echo esc_textarea( \PeptideHq\Register::config_lines() ); ?></textarea>
				</p>
				<p class="pchq-muted"><?php esc_html_e( 'The secret only lets a store join; each store makes its own key for everything else. Keep the lines private.', 'peptide-hq' ); ?></p>
				<p>
					<a class="pchq-delete" href="<?php echo esc_url( Menu::action_url( 'pchq_secret_regenerate' ) ); ?>" onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Create a new registration secret? Stores already connected keep working. Stores opened later need the new lines.', 'peptide-hq' ) ) ); ?>);"><?php esc_html_e( 'Create a new secret (if the old one leaked)', 'peptide-hq' ); ?></a>
				</p>
			</details>

			<?php if ( $sites ) : ?>
				<div class="pchq-scroll">
					<table class="widefat striped pchq-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Store', 'peptide-hq' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Status', 'peptide-hq' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Server', 'peptide-hq' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Domains', 'peptide-hq' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Search engines', 'peptide-hq' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Visitor tracking', 'peptide-hq' ); ?></th>
								<th scope="col" class="num"><?php esc_html_e( 'Salesperson accounts', 'peptide-hq' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Last check', 'peptide-hq' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Actions', 'peptide-hq' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $sites as $s ) : ?>
								<?php self::render_row( $s, $servers ); ?>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/** 开关（点一下下发到店铺）。 */
	private static function render_switch( array $s, string $key, bool $on, string $ask_on, string $ask_off ): void {
		printf(
			'<a class="pchq-switch%1$s" role="switch" aria-checked="%2$s" aria-label="%3$s" href="%4$s" onclick="return confirm(%5$s);"></a> <span class="pchq-muted">%6$s</span>',
			$on ? ' is-on' : '',
			$on ? 'true' : 'false',
			esc_attr( $s['name'] ),
			esc_url( Menu::action_url( 'pchq_site_config', array( 'site' => $s['id'], 'key' => $key, 'on' => $on ? 0 : 1 ) ) ),
			esc_attr( (string) wp_json_encode( $on ? $ask_off : $ask_on ) ),
			esc_html( $on ? __( 'On', 'peptide-hq' ) : __( 'Off', 'peptide-hq' ) )
		);
	}

	private static function render_row( array $s, array $servers ): void {
		$conf    = (array) ( $s['info']['config'] ?? array() );
		$want    = Servers::ip_for_site( $s['id'] );
		$has     = (string) ( $conf['server_ip'] ?? '' );
		$info    = $s['info'];
		$v       = (array) ( $info['versions'] ?? array() );
		$domains = (array) ( $info['domains'] ?? array() );
		$primary = current( array_filter( $domains, static fn( $d ) => ! empty( $d['primary'] ) ) );
		$https   = array( 'active' => __( 'HTTPS in effect', 'peptide-hq' ), 'checking' => __( 'HTTPS being checked', 'peptide-hq' ), 'failed' => __( 'HTTPS not in effect', 'peptide-hq' ) );
		?>
		<tr>
			<td data-label="<?php esc_attr_e( 'Store', 'peptide-hq' ); ?>">
				<strong><?php echo esc_html( $s['name'] ); ?></strong><br>
				<a class="pchq-muted" href="<?php echo esc_url( $s['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $s['url'] ); ?></a>
			</td>
			<td data-label="<?php esc_attr_e( 'Status', 'peptide-hq' ); ?>">
				<?php echo Menu::status_badge( $s ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php if ( '' !== $s['error'] ) : ?>
					<span class="pchq-error"><?php echo esc_html( $s['error'] ); ?></span>
				<?php endif; ?>
				<?php if ( ! empty( $info['coming_soon'] ) ) : ?>
					<span class="pchq-error"><?php esc_html_e( 'Coming soon mode: customers cannot see the store.', 'peptide-hq' ); ?></span>
				<?php endif; ?>
			</td>
			<td data-label="<?php esc_attr_e( 'Server', 'peptide-hq' ); ?>">
				<form class="pchq-inline" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="pchq_site_server">
					<input type="hidden" name="site" value="<?php echo (int) $s['id']; ?>">
					<?php wp_nonce_field( 'pchq_site_server' ); ?>
					<label class="screen-reader-text" for="pchq-srv-<?php echo (int) $s['id']; ?>"><?php esc_html_e( 'Server', 'peptide-hq' ); ?></label>
					<select id="pchq-srv-<?php echo (int) $s['id']; ?>" name="server" onchange="this.form.submit()">
						<option value="0"><?php esc_html_e( '— Not assigned —', 'peptide-hq' ); ?></option>
						<?php foreach ( $servers as $sv ) : ?>
							<option value="<?php echo (int) $sv['id']; ?>" <?php selected( $s['server_id'], $sv['id'] ); ?>><?php echo esc_html( $sv['name'] . ' · ' . $sv['ip'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<noscript><button type="submit" class="button button-small"><?php esc_html_e( 'Save', 'peptide-hq' ); ?></button></noscript>
				</form>
				<?php if ( ! $servers ) : ?>
					<a class="pchq-muted" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Servers_Page::SLUG ) ); ?>"><?php esc_html_e( 'Add a server first', 'peptide-hq' ); ?></a>
				<?php elseif ( $s['server_id'] && $want !== $has ) : ?>
					<span class="pchq-error"><?php esc_html_e( 'Not on the store yet; sent again at the next check.', 'peptide-hq' ); ?></span>
				<?php endif; ?>
			</td>
			<td data-label="<?php esc_attr_e( 'Domains', 'peptide-hq' ); ?>">
				<?php if ( $primary ) : ?>
					<?php echo esc_html( (string) $primary['host'] ); ?><br>
					<span class="pchq-muted"><?php echo esc_html( $https[ $primary['https'] ?? '' ] ?? '' ); ?></span>
				<?php endif; ?>
				<?php if ( count( $domains ) > 1 ) : ?>
					<br><span class="pchq-muted">
						<?php
						/* translators: %d: number of other domains */
						echo esc_html( sprintf( __( '+%d other domains', 'peptide-hq' ), count( $domains ) - 1 ) );
						?>
					</span>
				<?php endif; ?>
			</td>
			<td data-label="<?php esc_attr_e( 'Search engines', 'peptide-hq' ); ?>">
				<?php self::render_switch( $s, 'search', ! empty( $conf['blog_public'] ), __( 'Allow search engines to index this store?', 'peptide-hq' ), __( 'Hide this store from search engines?', 'peptide-hq' ) ); ?>
			</td>
			<td data-label="<?php esc_attr_e( 'Visitor tracking', 'peptide-hq' ); ?>">
				<?php self::render_switch( $s, 'tracking', ! empty( $conf['tracking']['enabled'] ), __( 'Start recording visitors on this store?', 'peptide-hq' ), __( 'Stop recording visitors on this store? Data already collected is kept.', 'peptide-hq' ) ); ?>
				<?php if ( ! empty( $conf['tracking']['enabled'] ) ) : ?>
					<span class="pchq-muted">
						<?php
						/* translators: %d: days */
						echo esc_html( sprintf( __( 'kept %d days', 'peptide-hq' ), (int) ( $conf['tracking']['retention_days'] ?? 0 ) ) );
						?>
					</span>
				<?php endif; ?>
			</td>
			<td class="num" data-label="<?php esc_attr_e( 'Salesperson accounts', 'peptide-hq' ); ?>"><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Accounts_Page::SLUG . '&site=' . $s['id'] ) ); ?>"><?php echo esc_html( number_format_i18n( (int) ( $info['staff'] ?? 0 ) ) ); ?></a></td>
			<td data-label="<?php esc_attr_e( 'Last check', 'peptide-hq' ); ?>"><?php echo esc_html( Menu::ago( $s['last_check'] ) ); ?></td>
			<td data-label="<?php esc_attr_e( 'Actions', 'peptide-hq' ); ?>" class="pchq-actions">
				<a class="button button-small button-primary" target="_blank" rel="noopener" href="<?php echo esc_url( Menu::action_url( 'pchq_login', array( 'site' => $s['id'] ) ) ); ?>"><?php esc_html_e( 'Open store admin', 'peptide-hq' ); ?></a>
				<a class="button button-small" href="<?php echo esc_url( Menu::action_url( 'pchq_site_refresh', array( 'site' => $s['id'] ) ) ); ?>"><?php esc_html_e( 'Refresh', 'peptide-hq' ); ?></a>
				<details class="pchq-more">
					<summary><?php esc_html_e( 'More', 'peptide-hq' ); ?></summary>
					<p class="pchq-more__title"><strong><?php esc_html_e( 'Platform tools', 'peptide-hq' ); ?></strong> <span class="pchq-muted"><?php esc_html_e( 'Not shown in the store admin; opens the store page logged in as administrator.', 'peptide-hq' ); ?></span></p>
					<ul class="pchq-tools">
						<?php foreach ( Platform::tools() as $key => [ $label, $desc ] ) : ?>
							<li><a target="_blank" rel="noopener" title="<?php echo esc_attr( $desc ); ?>" href="<?php echo esc_url( Menu::action_url( 'pchq_login', array( 'site' => $s['id'], 'to' => $key ) ) ); ?>"><?php echo esc_html( $label ); ?></a></li>
						<?php endforeach; ?>
					</ul>
					<p class="pchq-more__title"><strong><?php esc_html_e( 'System', 'peptide-hq' ); ?></strong></p>
					<p class="pchq-muted pchq-sysinfo">
						<?php echo esc_html( 'peptide-core ' . ( $v['plugin'] ?? '?' ) . ' · WooCommerce ' . ( $v['wc'] ?? '?' ) . ' · WordPress ' . ( $v['wp'] ?? '?' ) . ' · PHP ' . ( $v['php'] ?? '?' ) ); ?><br>
						<?php
						/* translators: %s: theme name and version */
						echo esc_html( sprintf( __( 'Theme: %s', 'peptide-hq' ), (string) ( $info['theme'] ?? '?' ) ) );
						?>
						<br>
						<?php
						/* translators: %d: number of products */
						echo esc_html( sprintf( __( 'Published products: %d', 'peptide-hq' ), (int) ( $info['products'] ?? 0 ) ) );
						?>
						<br>
						<?php
						$plugins = array_map( static fn( $p ) => $p['name'] . ' ' . $p['version'], (array) ( $info['plugins'] ?? array() ) );
						/* translators: %s: list of plugins */
						echo esc_html( sprintf( __( 'Active plugins: %s', 'peptide-hq' ), $plugins ? implode( ', ', $plugins ) : '—' ) );
						?>
					</p>
					<?php if ( ! empty( $conf['tracking']['enabled'] ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="pchq_site_config">
							<input type="hidden" name="site" value="<?php echo (int) $s['id']; ?>">
							<input type="hidden" name="key" value="retention">
							<?php wp_nonce_field( 'pchq_site_config' ); ?>
							<label for="pchq-ret-<?php echo (int) $s['id']; ?>"><?php esc_html_e( 'Keep visitor data for', 'peptide-hq' ); ?></label>
							<select id="pchq-ret-<?php echo (int) $s['id']; ?>" name="days">
								<?php foreach ( array( 30, 90, 180, 365 ) as $d ) : ?>
									<?php /* translators: %d: days */ ?>
									<option value="<?php echo (int) $d; ?>" <?php selected( (int) ( $conf['tracking']['retention_days'] ?? 0 ), $d ); ?>><?php echo esc_html( sprintf( __( '%d days', 'peptide-hq' ), $d ) ); ?></option>
								<?php endforeach; ?>
							</select>
							<button type="submit" class="button button-small"><?php esc_html_e( 'Save', 'peptide-hq' ); ?></button>
						</form>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="pchq_site_rename">
						<input type="hidden" name="site" value="<?php echo (int) $s['id']; ?>">
						<?php wp_nonce_field( 'pchq_site_rename' ); ?>
						<label for="pchq-rename-<?php echo (int) $s['id']; ?>"><?php esc_html_e( 'Name', 'peptide-hq' ); ?></label>
						<input type="text" id="pchq-rename-<?php echo (int) $s['id']; ?>" name="name" value="<?php echo esc_attr( $s['name'] ); ?>" maxlength="190" required>
						<button type="submit" class="button button-small"><?php esc_html_e( 'Save name', 'peptide-hq' ); ?></button>
					</form>
					<a class="pchq-delete" href="<?php echo esc_url( Menu::action_url( 'pchq_site_delete', array( 'site' => $s['id'] ) ) ); ?>" onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Remove this store from the master admin? Its numbers and alerts here are deleted; the store itself keeps running.', 'peptide-hq' ) ) ); ?>);"><?php esc_html_e( 'Remove from master admin', 'peptide-hq' ); ?></a>
				</details>
			</td>
		</tr>
		<?php
	}
}
