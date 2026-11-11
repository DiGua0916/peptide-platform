<?php
/**
 * 花费导入页面（线 2，pc-creatives-spend）：上传 Meta 广告管理工具导出的“按广告、按天”报表 CSV，
 * 按（ad_id, 日期）覆盖写入 pc_ad_daily_spend，再把每日全店花费推给总后台（source = csv）。
 * 解析和写库见 Spend_Import。写操作走 admin-post 动作 Module::ACTION_IMPORT（op：import / push）。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Creatives;

use PeptideCore\Staff_Buyer;

defined( 'ABSPATH' ) || exit;

final class Spend_Page {

	public static function register_hooks(): void {
		add_action( 'admin_post_' . Module::ACTION_IMPORT, array( self::class, 'handle' ) );
		add_action( Spend_Import::RETRY, array( Spend_Import::class, 'retry' ) );
	}

	private static function url(): string {
		return admin_url( 'admin.php?page=' . Module::PAGE_SPEND );
	}

	public static function handle(): void {
		if ( ! current_user_can( Staff_Buyer::CAP_VIEW ) ) {
			wp_die( esc_html( '没有权限。' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( Module::ACTION_IMPORT );
		Schema::maybe_install();
		$done = static function ( string $type, string $msg ): void {
			Staff_Buyer::flash( $type, $msg );
			wp_safe_redirect( self::url() );
			exit;
		};
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : 'import';
		if ( 'push' === $op ) {
			$r = Spend_Import::push();
			$done( 'failed' === $r['status'] ? 'error' : 'success', '' !== $r['message'] ? $r['message'] : '没有需要推送的日期。' );
		}

		$file = isset( $_FILES['csv'] ) && is_array( $_FILES['csv'] ) ? $_FILES['csv'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! $file || UPLOAD_ERR_NO_FILE === (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			$done( 'error', '请先选择 CSV 文件。' );
		}
		if ( UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			$done( 'error', '文件上传失败，请再试一次。' );
		}
		$name = sanitize_file_name( (string) $file['name'] );
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'csv', 'txt', 'tsv' ), true ) ) {
			$done( 'error', '请上传 CSV 文件。Meta 导出时“格式”选 .csv，不要选 .xlsx。' );
		}
		if ( (int) $file['size'] > Spend_Import::MAX_BYTES ) {
			$done( 'error', '文件超过 8MB。请缩短日期范围后分几次导出。' );
		}
		$parsed = Spend_Import::parse( (string) file_get_contents( (string) $file['tmp_name'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! $parsed['ok'] ) {
			$done( 'error', $parsed['error'] . ( $parsed['errors'] ? ' ' . implode( ' ', array_slice( $parsed['errors'], 0, 5 ) ) : '' ) );
		}
		$r    = Spend_Import::import( $parsed, $name );
		$days = $r['days'];
		$msg  = sprintf( '已导入 %d 行（%s 至 %s）', $r['saved'], $days[0], $days[ count( $days ) - 1 ] );
		$msg .= $r['ads_added'] ? sprintf( '，新发现 %d 条广告', $r['ads_added'] ) : '';
		$msg .= $parsed['skipped'] ? sprintf( '，跳过 %d 行', $parsed['skipped'] ) : '';
		$msg .= '。';
		$tail = array_merge( $parsed['warnings'], array_slice( $parsed['errors'], 0, 5 ) );
		if ( '' !== $r['push']['message'] ) {
			$tail[] = $r['push']['message'];
		}
		$done( 'failed' === $r['push']['status'] || $parsed['errors'] ? 'error' : 'success', $msg . ( $tail ? ' ' . implode( ' ', $tail ) : '' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( Staff_Buyer::CAP_VIEW ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'peptide-core' ), 403 );
		}
		Schema::maybe_install();
		echo '<div class="wrap pc-creatives pc-cr">';
		echo '<h1>花费导入</h1>';
		Module::tabs( Module::PAGE_SPEND );
		Staff_Buyer::render_flash();
		echo '<style>.pc-cr-box{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:4px 16px 12px;margin:16px 0;max-width:1100px}.pc-cr-box h2{font-size:15px;margin:12px 0 6px}.pc-cr-box ol{margin-left:20px}.pc-cr-warn{border-left:4px solid #dba617}.pc-cr-imports{max-width:1100px}</style>';

		$pending = Spend_Import::pending();
		if ( $pending ) {
			echo '<div class="pc-cr-box pc-cr-warn"><p>';
			if ( Creatives::hq_enabled() ) {
				echo esc_html( sprintf( '有 %d 天的全店花费合计还没推到总后台（%s 等）。系统每小时自动重试。', count( $pending ), $pending[0] ) ) . '</p>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( Module::ACTION_IMPORT ) . '"><input type="hidden" name="op" value="push">';
				wp_nonce_field( Module::ACTION_IMPORT );
				echo '<p><button class="button">重新推送</button></p></form>';
			} else {
				echo esc_html( '这家店还没有接入总后台。导入的花费照常保存和统计，接入后自动补推每日合计。' ) . '</p>';
			}
			echo '</div>';
		}

		echo '<div class="pc-cr-box"><h2>上传 Meta 报表</h2>';
		echo '<p>每天上传前一天的报表即可；补导或重复上传同一天，按广告和日期覆盖，不会重复计算。</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( Module::ACTION_IMPORT ) . '"><input type="hidden" name="op" value="import">';
		wp_nonce_field( Module::ACTION_IMPORT );
		echo '<p><input type="file" name="csv" accept=".csv,.txt,.tsv,text/csv" required> <button class="button button-primary">导入</button></p></form></div>';

		echo '<div class="pc-cr-box"><h2>在 Meta 里怎么导出这份报表</h2><ol>';
		echo '<li>打开广告管理工具，切到<strong>“广告”</strong>标签（不是广告系列或广告组），选好日期范围（一般选“昨天”）。</li>';
		echo '<li>点<strong>“细分”→“按时间”→“天”</strong>。</li>';
		echo '<li>“列”里确认有：<strong>广告名称、广告编号、已花费金额、展示次数、链接点击量</strong>（广告编号在“自定义列 → 设置”里勾选）。</li>';
		echo '<li>点<strong>“报告”→“导出表格数据”</strong>，格式选 <strong>.csv</strong>，下载后上传到这里。</li>';
		echo '</ol><p class="description">中文或英文界面导出的都可以。金额要是美元。</p></div>';

		echo '<h2>最近 10 次导入</h2>';
		$rows = Spend_Import::recent( 10 );
		if ( ! $rows ) {
			echo '<p>还没有导入过。</p>';
		} else {
			echo '<table class="widefat striped pc-cr-imports"><thead><tr><th>时间</th><th>文件</th><th>日期范围</th><th>行数</th><th>导入</th><th>操作人</th><th>提示</th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				$user = get_userdata( (int) $row['user_id'] );
				$errs = json_decode( (string) $row['errors'], true );
				$errs = is_array( $errs ) ? array_map( 'strval', $errs ) : array();
				echo '<tr><td>' . esc_html( wp_date( 'Y-m-d H:i', (int) $row['created_at'] ) ) . '</td>';
				echo '<td>' . esc_html( (string) $row['filename'] ) . '</td>';
				echo '<td>' . esc_html( (string) $row['day_from'] . ( $row['day_to'] !== $row['day_from'] ? ' 至 ' . (string) $row['day_to'] : '' ) ) . '</td>';
				echo '<td>' . (int) $row['rows_total'] . '</td><td>' . (int) $row['rows_saved'] . '</td>';
				echo '<td>' . esc_html( $user ? $user->display_name : '—' ) . '</td>';
				echo '<td>' . esc_html( $errs ? implode( ' ', array_slice( $errs, 0, 3 ) ) . ( count( $errs ) > 3 ? ' …' : '' ) : '' ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}
}
