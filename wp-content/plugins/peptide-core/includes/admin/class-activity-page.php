<?php
/**
 * 后台“后台设置 → 操作记录”（照 YY，用户 2026-10-27）。数据见 PeptideCore\Activity_Log。
 *
 * 按操作人、模块、操作日期筛选；列：操作（查看明细）、操作类型、操作人、操作时间、模块、事件、对象、Trace ID、IP、浏览器、操作设备。
 * 查看明细在列表上方显示（操作人、时间、模块、事件、对象和记下的明细）。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Admin;

use PeptideCore\Activity_Log;

defined( 'ABSPATH' ) || exit;

final class Activity_Page {

	public const SLUG     = 'pc-activity';
	private const PER_PAGE = 50;

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ), 62 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	public static function add_menu(): void {
		add_submenu_page( Media_Page::SLUG, __( 'Activity log', 'peptide-core' ), __( 'Activity log', 'peptide-core' ), Activity_Log::CAP, self::SLUG, array( self::class, 'render' ) );
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) );
	}

	public static function enqueue( string $hook ): void {
		if ( str_ends_with( $hook, self::SLUG ) ) {
			wp_enqueue_style( 'peptide-core-media-admin', plugins_url( 'assets/media-admin.css', PEPTIDE_CORE_FILE ), array(), PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( PEPTIDE_CORE_DIR . '/assets/media-admin.css' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	public static function render(): void {
		if ( ! current_user_can( Activity_Log::CAP ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 只读的筛选参数。
		$get     = static fn( string $k ): string => isset( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) : '';
		$modules = Activity_Log::modules();
		$f       = array(
			'user'   => mb_substr( $get( 'user' ), 0, 60 ),
			'module' => isset( $modules[ $get( 'module' ) ] ) ? $get( 'module' ) : '',
			'from'   => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $get( 'from' ) ) ? $get( 'from' ) : '',
			'to'     => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $get( 'to' ) ) ? $get( 'to' ) : '',
		);
		$paged   = max( 1, absint( $get( 'paged' ) ) );
		$view    = absint( $get( 'view' ) );
		// phpcs:enable
		[ $rows, $total ] = Activity_Log::query( $f, $paged, self::PER_PAGE );
		$pages            = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$base             = array_filter( $f );
		?>
		<div class="wrap pc-media pc-activity">
			<h1><?php esc_html_e( 'Activity log', 'peptide-core' ); ?></h1>
			<p class="description">
				<?php
				/* translators: %d: days kept */
				echo esc_html( sprintf( __( 'What the people who work in the admin did: logins, products, orders, blog, store design, settings and more. Customers are not recorded. Records are kept for %d days.', 'peptide-core' ), Activity_Log::KEEP_DAYS ) );
				?>
			</p>
			<?php
			if ( $view ) {
				self::render_detail( $view );
			}
			?>
			<form class="pc-media-tools pc-activity-filters" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<label class="screen-reader-text" for="pc-act-user"><?php esc_html_e( 'Operator', 'peptide-core' ); ?></label>
				<input type="search" id="pc-act-user" name="user" value="<?php echo esc_attr( $f['user'] ); ?>" placeholder="<?php esc_attr_e( 'Enter an operator', 'peptide-core' ); ?>" maxlength="60">
				<label class="screen-reader-text" for="pc-act-module"><?php esc_html_e( 'Module', 'peptide-core' ); ?></label>
				<select id="pc-act-module" name="module">
					<option value=""><?php esc_html_e( 'All modules', 'peptide-core' ); ?></option>
					<?php foreach ( $modules as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $f['module'], $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<span class="pc-activity-dates">
					<input type="date" name="from" value="<?php echo esc_attr( $f['from'] ); ?>" aria-label="<?php esc_attr_e( 'From', 'peptide-core' ); ?>">
					<span aria-hidden="true">~</span>
					<input type="date" name="to" value="<?php echo esc_attr( $f['to'] ); ?>" aria-label="<?php esc_attr_e( 'Until', 'peptide-core' ); ?>">
				</span>
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'peptide-core' ); ?></button>
				<?php if ( $base ) : ?>
					<a href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Clear filters', 'peptide-core' ); ?></a>
				<?php endif; ?>
				<span class="pc-media-total">
					<?php
					/* translators: %d: number of records */
					echo esc_html( sprintf( __( '%d records', 'peptide-core' ), $total ) );
					?>
				</span>
			</form>
			<div class="pc-media-scroll">
				<table class="widefat striped pc-media-table pc-activity-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Actions', 'peptide-core' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Operator type', 'peptide-core' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Operator', 'peptide-core' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Time', 'peptide-core' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Module', 'peptide-core' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Event', 'peptide-core' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Object', 'peptide-core' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Trace ID', 'peptide-core' ); ?></th>
							<th scope="col">IP</th>
							<th scope="col"><?php esc_html_e( 'Browser', 'peptide-core' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Device', 'peptide-core' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( ! $rows ) : ?>
							<tr><td colspan="11"><?php esc_html_e( 'No records yet.', 'peptide-core' ); ?></td></tr>
						<?php endif; ?>
						<?php foreach ( $rows as $r ) : ?>
							<tr>
								<td data-label="<?php esc_attr_e( 'Actions', 'peptide-core' ); ?>"><a href="<?php echo esc_url( self::url( $base + array( 'view' => (int) $r['id'], 'paged' => $paged > 1 ? $paged : null ) ) ); ?>" title="<?php esc_attr_e( 'View details', 'peptide-core' ); ?>"><span class="dashicons dashicons-visibility" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e( 'View details', 'peptide-core' ); ?></span></a></td>
								<td data-label="<?php esc_attr_e( 'Operator type', 'peptide-core' ); ?>"><?php echo esc_html( Activity_Log::role_label( (string) $r['user_role'] ) ); ?></td>
								<td data-label="<?php esc_attr_e( 'Operator', 'peptide-core' ); ?>"><?php echo esc_html( (string) $r['user_login'] ); ?></td>
								<td data-label="<?php esc_attr_e( 'Time', 'peptide-core' ); ?>" class="pc-nowrap"><?php echo esc_html( get_date_from_gmt( (string) $r['created_at'], 'Y-m-d H:i:s' ) ); ?></td>
								<td data-label="<?php esc_attr_e( 'Module', 'peptide-core' ); ?>"><?php echo esc_html( $modules[ $r['module'] ] ?? (string) $r['module'] ); ?></td>
								<td data-label="<?php esc_attr_e( 'Event', 'peptide-core' ); ?>"><?php echo esc_html( Activity_Log::event_label( (string) $r['event'], (string) $r['module'] ) ); ?></td>
								<td data-label="<?php esc_attr_e( 'Object', 'peptide-core' ); ?>" class="pc-media-source"><?php echo '' !== (string) $r['object_label'] ? esc_html( (string) $r['object_label'] ) : '—'; ?></td>
								<td data-label="<?php esc_attr_e( 'Trace ID', 'peptide-core' ); ?>" class="pc-activity-trace" title="<?php echo esc_attr( (string) $r['trace_id'] ); ?>"><?php echo esc_html( substr( (string) $r['trace_id'], 0, 8 ) ); ?></td>
								<td data-label="IP"><?php echo esc_html( (string) $r['ip'] ); ?></td>
								<td data-label="<?php esc_attr_e( 'Browser', 'peptide-core' ); ?>"><?php echo esc_html( (string) $r['browser'] ); ?></td>
								<td data-label="<?php esc_attr_e( 'Device', 'peptide-core' ); ?>"><?php echo esc_html( (string) $r['device'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
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
			</nav>
		</div>
		<?php
	}

	private static function render_detail( int $id ): void {
		$r = Activity_Log::get( $id );
		if ( ! $r ) {
			return;
		}
		$modules = Activity_Log::modules();
		$details = json_decode( (string) $r['details'], true );
		$keys    = array(
			'from'   => __( 'Before', 'peptide-core' ),
			'to'     => __( 'After', 'peptide-core' ),
			'amount' => __( 'Amount', 'peptide-core' ),
			'reason' => __( 'Reason', 'peptide-core' ),
			'author' => __( 'Review author', 'peptide-core' ),
			'modules' => __( 'Permissions', 'peptide-core' ),
			'action'  => __( 'Form', 'peptide-core' ),
		);
		?>
		<section class="pc-activity-detail" aria-labelledby="pc-act-detail-title">
			<h2 id="pc-act-detail-title"><?php esc_html_e( 'Activity details', 'peptide-core' ); ?></h2>
			<dl>
				<dt><?php esc_html_e( 'Operator', 'peptide-core' ); ?></dt><dd><?php echo esc_html( $r['user_login'] . ' (' . Activity_Log::role_label( (string) $r['user_role'] ) . ')' ); ?></dd>
				<dt><?php esc_html_e( 'Time', 'peptide-core' ); ?></dt><dd><?php echo esc_html( get_date_from_gmt( (string) $r['created_at'], 'Y-m-d H:i:s' ) ); ?></dd>
				<dt><?php esc_html_e( 'Module', 'peptide-core' ); ?></dt><dd><?php echo esc_html( $modules[ $r['module'] ] ?? (string) $r['module'] ); ?></dd>
				<dt><?php esc_html_e( 'Event', 'peptide-core' ); ?></dt><dd><?php echo esc_html( Activity_Log::event_label( (string) $r['event'], (string) $r['module'] ) ); ?></dd>
				<dt><?php esc_html_e( 'Object', 'peptide-core' ); ?></dt><dd><?php echo esc_html( trim( (string) $r['object_label'] . ( (int) $r['object_id'] ? ' (ID ' . (int) $r['object_id'] . ')' : '' ) ) ); ?></dd>
				<?php foreach ( is_array( $details ) ? $details : array() as $k => $v ) : ?>
					<dt><?php echo esc_html( $keys[ $k ] ?? (string) $k ); ?></dt><dd><?php echo esc_html( is_scalar( $v ) ? (string) $v : (string) wp_json_encode( $v, JSON_UNESCAPED_UNICODE ) ); ?></dd>
				<?php endforeach; ?>
				<dt><?php esc_html_e( 'Trace ID', 'peptide-core' ); ?></dt><dd><code><?php echo esc_html( (string) $r['trace_id'] ); ?></code></dd>
				<dt>IP</dt><dd><?php echo esc_html( (string) $r['ip'] ); ?></dd>
				<dt><?php esc_html_e( 'Browser', 'peptide-core' ); ?></dt><dd><?php echo esc_html( $r['browser'] . ' · ' . $r['device'] ); ?></dd>
			</dl>
			<p><a class="button" href="<?php echo esc_url( remove_query_arg( 'view' ) ); ?>"><?php esc_html_e( 'Close', 'peptide-core' ); ?></a></p>
		</section>
		<?php
	}
}
