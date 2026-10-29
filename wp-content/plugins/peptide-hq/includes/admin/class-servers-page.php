<?php
/**
 * 总后台 → 服务器：登记服务器（名称、IP、备注），在“店铺”页给每个店铺分配一台。
 * 分配后，店铺“域名管理”里的解析记录就指向这台服务器的 IP（销售自己去域名服务商解析）。
 *
 * @package PeptideHq
 */

namespace PeptideHq\Admin;

use PeptideHq\Servers;
use PeptideHq\Sites;

defined( 'ABSPATH' ) || exit;

final class Servers_Page {

	public const SLUG = 'pchq-servers';

	public static function render(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			return;
		}
		$servers = Servers::all();
		$sites   = Sites::all();
		$edit    = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = $edit ? Servers::get( $edit ) : null;
		?>
		<div class="wrap pchq">
			<h1><?php esc_html_e( 'Servers', 'peptide-hq' ); ?></h1>
			<?php Menu::notices(); ?>
			<p class="pchq-muted"><?php esc_html_e( 'Register your servers here, then assign one to each store on the Stores page. When a salesperson adds a domain in their store, the DNS records point to the IP of the assigned server; they set the records at their domain registrar themselves.', 'peptide-hq' ); ?></p>

			<?php if ( $servers ) : ?>
				<div class="pchq-scroll">
					<table class="widefat striped pchq-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Name', 'peptide-hq' ); ?></th>
								<th scope="col"><?php esc_html_e( 'IP address', 'peptide-hq' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Note', 'peptide-hq' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Stores on it', 'peptide-hq' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Actions', 'peptide-hq' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $servers as $v ) : ?>
								<?php $on = array_filter( $sites, static fn( $s ) => $s['server_id'] === $v['id'] ); ?>
								<tr>
									<td data-label="<?php esc_attr_e( 'Name', 'peptide-hq' ); ?>"><strong><?php echo esc_html( $v['name'] ); ?></strong></td>
									<td data-label="<?php esc_attr_e( 'IP address', 'peptide-hq' ); ?>"><code><?php echo esc_html( $v['ip'] ); ?></code></td>
									<td data-label="<?php esc_attr_e( 'Note', 'peptide-hq' ); ?>" class="pchq-muted"><?php echo esc_html( '' !== $v['note'] ? $v['note'] : '—' ); ?></td>
									<td data-label="<?php esc_attr_e( 'Stores on it', 'peptide-hq' ); ?>"><?php echo esc_html( $on ? implode( ', ', array_column( $on, 'name' ) ) : '—' ); ?></td>
									<td data-label="<?php esc_attr_e( 'Actions', 'peptide-hq' ); ?>">
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&edit=' . $v['id'] ) ); ?>"><?php esc_html_e( 'Edit', 'peptide-hq' ); ?></a>
										<?php if ( ! $on ) : ?>
											| <a class="pchq-delete" href="<?php echo esc_url( Menu::action_url( 'pchq_server_delete', array( 'server' => $v['id'] ) ) ); ?>" onclick="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Delete this server from the list?', 'peptide-hq' ) ) ); ?>);"><?php esc_html_e( 'Delete', 'peptide-hq' ); ?></a>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<form class="pchq-card pchq-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<h2><?php echo esc_html( $current ? __( 'Edit server', 'peptide-hq' ) : __( 'Add server', 'peptide-hq' ) ); ?></h2>
				<input type="hidden" name="action" value="pchq_server_save">
				<input type="hidden" name="server" value="<?php echo (int) ( $current['id'] ?? 0 ); ?>">
				<?php wp_nonce_field( 'pchq_server_save' ); ?>
				<p>
					<label for="pchq-sv-ip"><strong><?php esc_html_e( 'IP address', 'peptide-hq' ); ?></strong></label><br>
					<input type="text" id="pchq-sv-ip" name="ip" class="regular-text code" value="<?php echo esc_attr( (string) ( $current['ip'] ?? '' ) ); ?>" placeholder="203.0.113.10" required spellcheck="false" autocomplete="off">
				</p>
				<p>
					<label for="pchq-sv-name"><strong><?php esc_html_e( 'Name', 'peptide-hq' ); ?></strong></label><br>
					<input type="text" id="pchq-sv-name" name="name" class="regular-text" value="<?php echo esc_attr( (string) ( $current['name'] ?? '' ) ); ?>" maxlength="190" placeholder="<?php esc_attr_e( 'For example US-West 1', 'peptide-hq' ); ?>">
				</p>
				<p>
					<label for="pchq-sv-note"><strong><?php esc_html_e( 'Note', 'peptide-hq' ); ?></strong></label><br>
					<input type="text" id="pchq-sv-note" name="note" class="large-text" value="<?php echo esc_attr( (string) ( $current['note'] ?? '' ) ); ?>" maxlength="255" placeholder="<?php esc_attr_e( 'Provider, location, renewal date…', 'peptide-hq' ); ?>">
				</p>
				<?php if ( $current ) : ?>
					<p class="pchq-muted"><?php esc_html_e( 'Changing the IP sends the new address to every store on this server right away; their salespeople must update their DNS records.', 'peptide-hq' ); ?></p>
				<?php endif; ?>
				<p class="pchq-buttons">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save server', 'peptide-hq' ); ?></button>
					<?php if ( $current ) : ?>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ); ?>"><?php esc_html_e( 'Cancel', 'peptide-hq' ); ?></a>
					<?php endif; ?>
				</p>
			</form>
		</div>
		<?php
	}
}
