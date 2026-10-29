<?php
/**
 * 总后台 → 告警：未处理的告警（可以标记已处理）和最近已恢复的。新告警也会发邮件到总后台的管理员邮箱。
 *
 * @package PeptideHq
 */

namespace PeptideHq\Admin;

use PeptideHq\Alerts;
use PeptideHq\Sites;

defined( 'ABSPATH' ) || exit;

final class Alerts_Page {

	public const SLUG = 'pchq-alerts';

	public static function render(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			return;
		}
		$sites = Sites::all();
		$types = Alerts::types();
		$open  = Alerts::list( true );
		$done  = Alerts::list( false, 50 );
		$name  = static fn( $id ) => isset( $sites[ (int) $id ] ) ? $sites[ (int) $id ]['name'] : '#' . (int) $id;
		?>
		<div class="wrap pchq">
			<h1><?php esc_html_e( 'Alerts', 'peptide-hq' ); ?></h1>
			<?php Menu::notices(); ?>
			<p class="pchq-muted">
				<?php
				/* translators: %s: email address */
				echo esc_html( sprintf( __( 'Stores are checked every 10 minutes. New alerts are also emailed to %s. An alert closes by itself when the problem is gone.', 'peptide-hq' ), (string) get_option( 'admin_email' ) ) );
				?>
			</p>

			<h2 class="pchq-h2"><?php esc_html_e( 'Open', 'peptide-hq' ); ?></h2>
			<?php if ( ! $open ) : ?>
				<p class="pchq-card pchq-ok"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> <?php esc_html_e( 'No open alerts. All stores look fine.', 'peptide-hq' ); ?></p>
			<?php else : ?>
				<div class="pchq-scroll">
					<table class="widefat striped pchq-table">
						<thead><tr><th scope="col"><?php esc_html_e( 'Store', 'peptide-hq' ); ?></th><th scope="col"><?php esc_html_e( 'Problem', 'peptide-hq' ); ?></th><th scope="col"><?php esc_html_e( 'Details', 'peptide-hq' ); ?></th><th scope="col"><?php esc_html_e( 'Since', 'peptide-hq' ); ?></th><th scope="col"><?php esc_html_e( 'Actions', 'peptide-hq' ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( $open as $a ) : ?>
								<tr>
									<td data-label="<?php esc_attr_e( 'Store', 'peptide-hq' ); ?>"><strong><?php echo esc_html( $name( $a['site_id'] ) ); ?></strong></td>
									<td data-label="<?php esc_attr_e( 'Problem', 'peptide-hq' ); ?>"><span class="pchq-badge is-bad"><?php echo esc_html( $types[ $a['type'] ] ?? (string) $a['type'] ); ?></span></td>
									<td data-label="<?php esc_attr_e( 'Details', 'peptide-hq' ); ?>"><?php echo esc_html( (string) $a['message'] ); ?></td>
									<td data-label="<?php esc_attr_e( 'Since', 'peptide-hq' ); ?>"><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $a['created'] ) ); ?></td>
									<td data-label="<?php esc_attr_e( 'Actions', 'peptide-hq' ); ?>"><a href="<?php echo esc_url( Menu::action_url( 'pchq_alert_close', array( 'alert' => (int) $a['id'] ) ) ); ?>"><?php esc_html_e( 'Mark as handled', 'peptide-hq' ); ?></a></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<?php if ( $done ) : ?>
				<h2 class="pchq-h2"><?php esc_html_e( 'Recently closed', 'peptide-hq' ); ?></h2>
				<div class="pchq-scroll">
					<table class="widefat striped pchq-table">
						<thead><tr><th scope="col"><?php esc_html_e( 'Store', 'peptide-hq' ); ?></th><th scope="col"><?php esc_html_e( 'Problem', 'peptide-hq' ); ?></th><th scope="col"><?php esc_html_e( 'Details', 'peptide-hq' ); ?></th><th scope="col"><?php esc_html_e( 'From', 'peptide-hq' ); ?></th><th scope="col"><?php esc_html_e( 'Closed', 'peptide-hq' ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( $done as $a ) : ?>
								<tr>
									<td data-label="<?php esc_attr_e( 'Store', 'peptide-hq' ); ?>"><?php echo esc_html( $name( $a['site_id'] ) ); ?></td>
									<td data-label="<?php esc_attr_e( 'Problem', 'peptide-hq' ); ?>"><?php echo esc_html( $types[ $a['type'] ] ?? (string) $a['type'] ); ?></td>
									<td data-label="<?php esc_attr_e( 'Details', 'peptide-hq' ); ?>" class="pchq-muted"><?php echo esc_html( (string) $a['message'] ); ?></td>
									<td data-label="<?php esc_attr_e( 'From', 'peptide-hq' ); ?>"><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $a['created'] ) ); ?></td>
									<td data-label="<?php esc_attr_e( 'Closed', 'peptide-hq' ); ?>"><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $a['resolved'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
