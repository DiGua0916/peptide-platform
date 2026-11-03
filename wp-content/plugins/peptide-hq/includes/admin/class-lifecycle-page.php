<?php
/** Recover retained store identities; root-worker receipts are authoritative. */
namespace PeptideHq\Admin;
use PeptideHq\Site_Lifecycle;
defined( 'ABSPATH' ) || exit;

final class Lifecycle_Page {
	public const SLUG = 'pchq-site-recycle';
	public static function hooks(): void {
		add_action( 'admin_menu', static function (): void { add_submenu_page( 'pchq', '店铺回收站', '店铺回收站', Menu::CAP, self::SLUG, array( self::class, 'render' ) ); }, 25 );
		add_action( 'admin_post_pchq_site_restore', array( self::class, 'restore' ) );
		add_action( 'admin_post_pchq_site_retry', array( self::class, 'retry' ) );
	}
	public static function url(): string { return admin_url( 'admin.php?page=' . self::SLUG ); }
	public static function restore(): void {
		if ( ! current_user_can( Menu::CAP ) ) { wp_die( '无权恢复店铺。', '', array( 'response' => 403 ) ); }
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( '请从回收站提交恢复表单。', '', array( 'response' => 405 ) ); }
		check_admin_referer( 'pchq_site_restore' );
		$site = isset( $_POST['site'] ) && is_scalar( $_POST['site'] ) ? absint( $_POST['site'] ) : 0;
		$result = Site_Lifecycle::request( $site, 'restore' );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '恢复未提交', array( 'response' => 409, 'back_link' => true ) ); }
		wp_safe_redirect( add_query_arg( 'restore_requested', '1', self::url() ) ); exit;
	}
	public static function retry(): void {
		if ( ! current_user_can( Menu::CAP ) ) { wp_die( '无权重试店铺操作。', '', array( 'response' => 403 ) ); }
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( '请从回收站提交重试表单。', '', array( 'response' => 405 ) ); }
		check_admin_referer( 'pchq_site_retry' );
		$site = isset( $_POST['site'] ) && is_scalar( $_POST['site'] ) ? absint( $_POST['site'] ) : 0;
		$action = Site_Lifecycle::retry_action( $site );
		$result = '' !== $action ? Site_Lifecycle::request( $site, $action ) : new \WP_Error( 'pchq_retry_unavailable', '当前操作不可重试。恢复期限已过时，请联系管理员核对清理状态。' );
		if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ), '重试未提交', array( 'response' => 409, 'back_link' => true ) ); }
		wp_safe_redirect( add_query_arg( 'retry_requested', '1', self::url() ) ); exit;
	}
	public static function render(): void {
		if ( ! current_user_can( Menu::CAP ) ) { return; }
		Site_Lifecycle::sync(); $rows = Site_Lifecycle::all();
		$labels = array( 'offlining' => '正在下线', 'retained' => '可恢复', 'restoring' => '正在恢复', 'purging' => '正在清理', 'purged' => '已清理', 'failed' => '需要处理' );
		?>
		<div class="wrap pchq"><h1>店铺回收站</h1><p>店铺下线后停止顾客和后台访问，运行数据保留 72 小时。期限内可恢复原店；到期后自动清理运行数据。已有加密备份和支付核对记录按原保留规则保存。</p>
		<?php if ( isset( $_GET['restore_requested'] ) && '1' === $_GET['restore_requested'] ) : ?><div class="notice notice-success"><p>恢复请求已提交。完成身份及服务核验后，店铺才会重新开放。</p></div><?php endif; ?>
		<?php if ( isset( $_GET['retry_requested'] ) && '1' === $_GET['retry_requested'] ) : ?><div class="notice notice-success"><p>重试请求已提交，将继续原操作；恢复截止时间保持不变。总后台在两台服务器确认完成前保持该店停用。</p></div><?php endif; ?>
		<div class="pchq-card"><table class="widefat striped"><thead><tr><th scope="col">店铺</th><th scope="col">状态</th><th scope="col">恢复截止时间</th><th scope="col">操作</th></tr></thead><tbody>
		<?php if ( ! $rows ) : ?><tr><td colspan="4">暂无下线店铺。</td></tr><?php endif; ?>
		<?php foreach ( $rows as $r ) : $can_restore = 'retained' === $r['state'] && (int) $r['purge_at'] > time(); $retry = Site_Lifecycle::retry_action( (int) $r['site_id'] ); ?>
		<tr><td><?php echo esc_html( '' !== ( $r['name'] ?? '' ) ? $r['name'] : '店铺 #' . $r['site_id'] ); ?><br><span class="description"><?php echo esc_html( $r['public_url'] ); ?></span></td>
		<td><?php echo esc_html( $labels[ $r['state'] ] ?? '等待处理' ); ?><?php if ( ! empty( $r['message'] ) ) : ?><br><span class="description"><?php echo esc_html( $r['message'] ); ?></span><?php endif; ?></td>
		<td><?php echo (int) $r['purge_at'] > 0 ? esc_html( wp_date( 'Y-m-d H:i:s T', (int) $r['purge_at'] ) ) : '下线完成后开始计时'; ?></td>
		<td><?php if ( $can_restore ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="pchq_site_restore"><input type="hidden" name="site" value="<?php echo (int) $r['site_id']; ?>"><?php wp_nonce_field( 'pchq_site_restore' ); ?><button type="submit" class="button">恢复原店铺</button></form><?php elseif ( '' !== $retry ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="pchq_site_retry"><input type="hidden" name="site" value="<?php echo (int) $r['site_id']; ?>"><?php wp_nonce_field( 'pchq_site_retry' ); ?><button type="submit" class="button"><?php echo esc_html( 'restore' === $retry ? '重试恢复' : '重试下线 / 清理' ); ?></button></form><p class="description">继续原操作，不会延长恢复期限。</p><?php elseif ( 'failed' === $r['state'] ) : ?>恢复期限已过或当前不可恢复，请联系管理员核对。<?php else : ?>—<?php endif; ?></td></tr>
		<?php endforeach; ?></tbody></table></div></div>
		<?php
	}
}
