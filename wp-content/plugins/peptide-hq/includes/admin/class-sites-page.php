<?php
/**
 * 总后台 → 店铺：每个店铺的状态、分配的服务器、域名、搜索引擎收录和访客追踪开关、最后检查时间；
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
					<a class="pchq-delete" href="<?php echo esc_url( Menu::action_url( 'pchq_secret_regenerate' ) ); ?>" data-pc-confirm-json="<?php echo esc_attr( wp_json_encode( __( 'Create a new registration secret? Stores already connected keep working. Stores opened later need the new lines.', 'peptide-hq' ) ) ); ?>"><?php esc_html_e( 'Create a new secret (if the old one leaked)', 'peptide-hq' ); ?></a>
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
				<?php foreach ( $sites as $s ) : ?>
					<?php self::render_tools_dialog( $s ); ?>
					<?php self::render_server_dialog( $s, $servers ); ?>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/** 开关（点一下下发到店铺）。 */
	private static function render_switch( array $s, string $key, bool $on, string $ask_on, string $ask_off ): void {
		printf(
			'<a class="pchq-switch%1$s" role="switch" aria-checked="%2$s" aria-label="%3$s" href="%4$s" data-pc-confirm-json="%5$s"></a> <span class="pchq-muted">%6$s</span>',
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
		$assigned = current( array_filter( $servers, static fn( $server ) => (int) $server['id'] === (int) $s['server_id'] ) );
		$server_name = $assigned ? (string) $assigned['name'] : ( $s['server_id'] ? '当前服务器不可用' : '未分配服务器' );
		$server_ip = $assigned ? (string) $assigned['ip'] : ( $s['server_id'] ? $has : '点击选择服务器' );
		$domains = (array) ( $info['domains'] ?? array() );
		$primary = current( array_filter( $domains, static fn( $d ) => ! empty( $d['primary'] ) ) );
		$https   = array( 'active' => __( 'HTTPS in effect', 'peptide-hq' ), 'checking' => __( 'HTTPS being checked', 'peptide-hq' ), 'failed' => __( 'HTTPS not in effect', 'peptide-hq' ) );
		?>
		<tr>
			<td data-label="<?php esc_attr_e( 'Store', 'peptide-hq' ); ?>">
				<strong><?php echo esc_html( $s['name'] ); ?></strong><br>
				<a class="pchq-muted" href="<?php echo esc_url( \PeptideHq\Site_Urls::public_url( $s ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( \PeptideHq\Site_Urls::public_url( $s ) ); ?></a>
				<div class="pchq-site-admin" style="margin-top:6px;display:flex;flex-wrap:wrap;align-items:center;gap:8px"><span class="pchq-muted">后台：<?php echo esc_html( (string) wp_parse_url( \PeptideHq\Site_Urls::management( $s ), PHP_URL_HOST ) ); ?></span> <a class="button button-small" target="_blank" rel="noopener" href="<?php echo esc_url( Menu::action_url( 'pchq_login', array( 'site' => (int) $s['id'] ) ) ); ?>">进入后台</a></div>
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
				<form class="pchq-site-tools-trigger" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
					<button type="submit" name="site_server" value="<?php echo (int) $s['id']; ?>" class="button pchq-server-picker" data-pchq-site-tools-open="pchq-site-server-<?php echo (int) $s['id']; ?>" aria-haspopup="dialog" aria-controls="pchq-site-server-<?php echo (int) $s['id']; ?>" aria-expanded="false" aria-label="<?php echo esc_attr( $s['name'] . '：选择服务器，当前为' . $server_name ); ?>" title="<?php echo esc_attr( $server_name . ' · ' . $server_ip ); ?>">
						<span class="pchq-server-picker__text"><strong><?php echo esc_html( $server_name ); ?></strong><span><?php echo esc_html( $server_ip ); ?></span></span><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
					</button>
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
			<td data-label="<?php esc_attr_e( 'Last check', 'peptide-hq' ); ?>"><?php echo esc_html( Menu::ago( $s['last_check'] ) ); ?></td>
			<td data-label="<?php esc_attr_e( 'Actions', 'peptide-hq' ); ?>" class="pchq-actions">
				<a class="button button-small button-primary" target="_blank" rel="noopener" href="<?php echo esc_url( Menu::action_url( 'pchq_login', array( 'site' => $s['id'] ) ) ); ?>"><?php esc_html_e( 'Open store admin', 'peptide-hq' ); ?></a>
				<a class="button button-small" href="<?php echo esc_url( Menu::action_url( 'pchq_site_refresh', array( 'site' => $s['id'] ) ) ); ?>"><?php esc_html_e( 'Refresh', 'peptide-hq' ); ?></a>
				<form class="pchq-site-tools-trigger" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
					<button type="submit" name="site_tools" value="<?php echo (int) $s['id']; ?>" class="button button-small" data-pchq-site-tools-open="pchq-site-tools-<?php echo (int) $s['id']; ?>" aria-haspopup="dialog" aria-controls="pchq-site-tools-<?php echo (int) $s['id']; ?>" aria-expanded="false"><?php esc_html_e( 'More', 'peptide-hq' ); ?></button>
				</form>
			</td>
		</tr>
		<?php
	}

	/** An explicit server choice preserves the existing POST contract without native option popups. */
	private static function render_server_dialog( array $s, array $servers ): void {
		$requested = isset( $_GET['site_server'] ) && is_string( $_GET['site_server'] ) ? $_GET['site_server'] : '';
		$open = ctype_digit( $requested ) && (int) $requested === (int) $s['id'];
		$back = admin_url( 'admin.php?page=' . self::SLUG );
		$assigned = current( array_filter( $servers, static fn( $server ) => (int) $server['id'] === (int) $s['server_id'] ) );
		?>
		<dialog class="pchq-site-dialog pchq-server-dialog" id="pchq-site-server-<?php echo (int) $s['id']; ?>" aria-labelledby="pchq-site-server-title-<?php echo (int) $s['id']; ?>" aria-describedby="pchq-site-server-description-<?php echo (int) $s['id']; ?>" tabindex="-1"<?php echo $open ? ' open' : ''; ?>>
			<header class="pchq-site-dialog__header">
				<div><h2 id="pchq-site-server-title-<?php echo (int) $s['id']; ?>">选择服务器</h2><p class="pchq-muted" id="pchq-site-server-description-<?php echo (int) $s['id']; ?>"><?php echo esc_html( $s['name'] ); ?> · 选择后点击保存，才会更新分配。</p></div>
				<a class="pchq-site-dialog__close" href="<?php echo esc_url( $back ); ?>" data-pchq-site-tools-close aria-label="关闭服务器选择"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></a>
			</header>
			<div class="pchq-site-dialog__body">
				<form id="pchq-site-server-form-<?php echo (int) $s['id']; ?>" class="pchq-site-server-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-pchq-reset-on-open>
					<input type="hidden" name="action" value="pchq_site_server">
					<input type="hidden" name="site" value="<?php echo (int) $s['id']; ?>">
					<?php wp_nonce_field( 'pchq_site_server' ); ?>
					<?php if ( $s['server_id'] && ! $assigned ) : ?><p class="pchq-error">原服务器不在可用列表中，请重新选择后保存。</p><?php endif; ?>
					<fieldset class="pchq-server-options">
						<legend class="screen-reader-text"><?php echo esc_html( $s['name'] . '的服务器' ); ?></legend>
						<label class="pchq-server-option" for="pchq-server-<?php echo (int) $s['id']; ?>-0">
							<input type="radio" id="pchq-server-<?php echo (int) $s['id']; ?>-0" name="server" value="0" <?php checked( (int) $s['server_id'], 0 ); ?> required>
							<span class="pchq-server-option__text"><strong>未分配服务器</strong><span>不指定服务器</span></span>
							<?php if ( ! $s['server_id'] ) : ?><span class="pchq-server-option__current">当前</span><?php endif; ?>
						</label>
						<?php foreach ( $servers as $sv ) : ?>
							<label class="pchq-server-option" for="pchq-server-<?php echo (int) $s['id']; ?>-<?php echo (int) $sv['id']; ?>">
								<input type="radio" id="pchq-server-<?php echo (int) $s['id']; ?>-<?php echo (int) $sv['id']; ?>" name="server" value="<?php echo (int) $sv['id']; ?>" <?php checked( (int) $s['server_id'], (int) $sv['id'] ); ?> required>
								<span class="pchq-server-option__text"><strong><?php echo esc_html( $sv['name'] ); ?></strong><span><?php echo esc_html( $sv['ip'] ); ?></span></span>
								<?php if ( (int) $s['server_id'] === (int) $sv['id'] ) : ?><span class="pchq-server-option__current">当前</span><?php endif; ?>
							</label>
						<?php endforeach; ?>
					</fieldset>
				</form>
			</div>
			<footer class="pchq-site-dialog__footer"><a class="button" href="<?php echo esc_url( $back ); ?>" data-pchq-site-tools-close>取消</a><button type="submit" class="button button-primary" form="pchq-site-server-form-<?php echo (int) $s['id']; ?>">保存分配</button></footer>
		</dialog>
		<?php
	}

	/** Render outside the scrolling table: the native dialog owns its own viewport. */
	private static function render_tools_dialog( array $s ): void {
		$info = (array) $s['info'];
		$conf = (array) ( $info['config'] ?? array() );
		$v = (array) ( $info['versions'] ?? array() );
		$requested = isset( $_GET['site_tools'] ) && is_string( $_GET['site_tools'] ) ? $_GET['site_tools'] : '';
		$open = ctype_digit( $requested ) && (int) $requested === (int) $s['id'];
		$back = admin_url( 'admin.php?page=' . self::SLUG );
		?>
		<dialog class="pchq-site-dialog" id="pchq-site-tools-<?php echo (int) $s['id']; ?>" aria-labelledby="pchq-site-tools-title-<?php echo (int) $s['id']; ?>" aria-describedby="pchq-site-tools-description-<?php echo (int) $s['id']; ?>" tabindex="-1"<?php echo $open ? ' open' : ''; ?>>
			<header class="pchq-site-dialog__header">
				<div><h2 id="pchq-site-tools-title-<?php echo (int) $s['id']; ?>"><?php echo esc_html( $s['name'] ); ?> · 更多操作</h2><p class="pchq-muted" id="pchq-site-tools-description-<?php echo (int) $s['id']; ?>"><?php echo esc_html( \PeptideHq\Site_Urls::public_url( $s ) ); ?></p></div>
				<a class="pchq-site-dialog__close" href="<?php echo esc_url( $back ); ?>" data-pchq-site-tools-close aria-label="关闭店铺工具"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></a>
			</header>
			<div class="pchq-site-dialog__body">
					<p class="pchq-site-dialog__section-title"><strong><?php esc_html_e( 'Platform tools', 'peptide-hq' ); ?></strong> <span class="pchq-muted"><?php esc_html_e( 'Not shown in the store admin; opens the store page logged in as administrator.', 'peptide-hq' ); ?></span></p>
					<ul class="pchq-tools">
						<?php foreach ( Platform::tools() as $key => [ $label, $desc ] ) : ?>
							<li><a target="_blank" rel="noopener" title="<?php echo esc_attr( $desc ); ?>" href="<?php echo esc_url( Menu::action_url( 'pchq_login', array( 'site' => $s['id'], 'to' => $key ) ) ); ?>"><?php echo esc_html( $label ); ?></a></li>
						<?php endforeach; ?>
					</ul>
					<p class="pchq-site-dialog__section-title"><strong><?php esc_html_e( 'System', 'peptide-hq' ); ?></strong></p>
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
						<label for="pchq-rename-<?php echo (int) $s['id']; ?>">店铺名称 <span class="pchq-muted">（和店铺后台的名称保持一致，两边改哪边都行）</span></label>
						<input type="text" id="pchq-rename-<?php echo (int) $s['id']; ?>" name="name" value="<?php echo esc_attr( $s['name'] ); ?>" maxlength="190" required>
						<button type="submit" class="button button-small"><?php esc_html_e( 'Save name', 'peptide-hq' ); ?></button>
					</form>
				<form class="pchq-site-dialog__retire" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-pc-confirm="停止此店铺访问并移入回收站？数据保留72小时，期间可恢复，期满永久清理。">
					<input type="hidden" name="action" value="pchq_site_retire">
					<input type="hidden" name="site" value="<?php echo (int) $s['id']; ?>">
					<?php wp_nonce_field( 'pchq_site_retire' ); ?>
					<div><h3>删除店铺</h3><p class="pchq-muted">停止此店铺访问并移入回收站。数据保留72小时，期间可恢复，期满永久清理。</p></div>
					<button type="submit" class="button pchq-delete">删除店铺</button>
				</form>
			</div>
			<footer class="pchq-site-dialog__footer"><a class="button" href="<?php echo esc_url( $back ); ?>" data-pchq-site-tools-close>关闭</a></footer>
		</dialog>
		<?php
	}
}
