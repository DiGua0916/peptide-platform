<?php
/**
 * 总后台 → 物流填单（店主 2026-11-12）：所有店铺付款成功的订单自动进表，两个子表（发货登记 / 报关填单）按店主原来的表格格式，可下载 Excel。
 *
 * @package PeptideHq
 */

namespace PeptideHq\Admin;

use PeptideHq\Logistics;
use PeptideHq\Sites;
use PeptideHq\Tencent_Docs;
use PeptideHq\Track17;
use PeptideHq\Xlsx;

defined( 'ABSPATH' ) || exit;

final class Logistics_Page {

	public const SLUG = 'pchq-logistics';

	public static function hooks(): void {
		add_action(
			'admin_menu',
			static function (): void {
				add_submenu_page( 'pchq', '物流填单', '物流填单', Menu::CAP, self::SLUG, array( self::class, 'render' ) );
			},
			25
		);
		add_action( 'admin_post_pchq_logistics_xlsx', array( self::class, 'download' ) );
		add_action( 'admin_post_pchq_logistics_save', array( self::class, 'save' ) );
		add_action( 'admin_post_pchq_logistics_track', array( self::class, 'track' ) );
		add_action( 'admin_post_pchq_tdocs_save', array( self::class, 'tdocs_save' ) );
		add_action( 'admin_post_pchq_tdocs_auth', array( self::class, 'tdocs_auth' ) );
		add_action( 'admin_post_pchq_tdocs_callback', array( self::class, 'tdocs_callback' ) );
		add_action( 'admin_post_pchq_tdocs_sync', array( self::class, 'tdocs_sync' ) );
	}

	private static function back( array $args ): void {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => 'tdocs' ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function tdocs_save(): void {
		self::guard( 'pchq_tdocs_save' );
		Tencent_Docs::save( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- save() 逐项清理。
		self::back( array( 'tmsg' => rawurlencode( '已保存。' ) ) );
	}

	public static function tdocs_auth(): void {
		self::guard( 'pchq_tdocs_auth' );
		if ( ! Tencent_Docs::has_app() ) {
			self::back( array( 'terr' => rawurlencode( '先填 Client ID 和 Client Secret 并保存。' ) ) );
		}
		wp_redirect( Tencent_Docs::authorize_url() ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- 跳到腾讯文档授权页。
		exit;
	}

	public static function tdocs_callback(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( '没有权限。', '', array( 'response' => 403 ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- state 就是 nonce。
		$state = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );
		$code  = sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) );
		$err   = sanitize_text_field( wp_unslash( $_GET['error_description'] ?? ( $_GET['error'] ?? '' ) ) );
		// phpcs:enable
		if ( ! wp_verify_nonce( $state, 'pchq_tdocs_state' ) ) {
			self::back( array( 'terr' => rawurlencode( '授权已过期，请再点一次“授权腾讯文档”。' ) ) );
		}
		if ( '' === $code ) {
			self::back( array( 'terr' => rawurlencode( '腾讯文档没有完成授权' . ( '' !== $err ? '：' . $err : '。' ) ) ) );
		}
		$r = Tencent_Docs::exchange( $code );
		if ( is_wp_error( $r ) ) {
			self::back( array( 'terr' => rawurlencode( $r->get_error_message() ) ) );
		}
		self::back( array( 'tmsg' => rawurlencode( '腾讯文档授权成功。' ) ) );
	}

	public static function tdocs_sync(): void {
		self::guard( 'pchq_tdocs_sync' );
		$msg = Tencent_Docs::sync_all();
		$s   = Tencent_Docs::settings();
		self::back( '' !== (string) $s['last_error'] ? array( 'terr' => rawurlencode( (string) $s['last_error'] ) ) : array( 'tmsg' => rawurlencode( '' !== $msg ? $msg : '没有需要同步的内容。' ) ) );
	}

	private static function render_tdocs(): void {
		$s    = Tencent_Docs::settings();
		$auth = Tencent_Docs::authorized();
		$post = esc_url( admin_url( 'admin-post.php' ) );
		echo '<div class="pchq-logi-card">';
		echo '<p class="pchq-logi-intro">把上面两个表自动写进你的腾讯文档：新的付款订单接在最后一行下面，序号接着往下编；表里已经有的单号（你手填的）不会动；我们写的行在有物流单号、妥投后会自动更新。新订单约 2 分钟内写入，每小时再检查一次。</p>';
		echo '<ol class="pchq-logi-steps"><li>打开 <a href="https://docs.qq.com/open/developers/" target="_blank" rel="noopener">腾讯文档开放平台</a>，注册开发者，创建一个应用，等审核通过。</li><li>在应用设置里把下面的“回调地址”加进白名单（域名 vialcoveresearch.com）。</li><li>把应用的 Client ID 和 Client Secret 填到下面，保存。</li><li>点“授权腾讯文档”，用能编辑这两个表的腾讯文档账号登录并同意。</li><li>分别点开两个工作表，复制浏览器地址（带 ?tab=）填到下面，保存，再点“立即同步”。</li></ol>';
		$cb = Tencent_Docs::redirect_uri();
		echo '<p><strong>回调地址</strong>　<code id="pchq-tdocs-cb">' . esc_html( $cb ) . '</code> <button type="button" class="button button-small" data-copy="' . esc_attr( $cb ) . '" onclick="var b=this;navigator.clipboard.writeText(b.dataset.copy).then(function(){b.textContent=\'已复制\'}).catch(function(){var r=document.createRange();r.selectNodeContents(document.getElementById(\'pchq-tdocs-cb\'));getSelection().removeAllRanges();getSelection().addRange(r);});">复制</button></p>';
		echo '<form method="post" action="' . $post . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		wp_nonce_field( 'pchq_tdocs_save' );
		echo '<input type="hidden" name="action" value="pchq_tdocs_save"><table class="form-table">';
		echo '<tr><th><label for="pchq-td-id">Client ID</label></th><td><input id="pchq-td-id" type="text" name="tdocs_client_id" class="regular-text" autocomplete="off" value="' . esc_attr( $s['client_id'] ) . '"></td></tr>';
		echo '<tr><th><label for="pchq-td-sec">Client Secret</label></th><td><input id="pchq-td-sec" type="password" name="tdocs_secret" class="regular-text" autocomplete="new-password" placeholder="' . esc_attr( '' !== $s['secret_box'] ? '已保存，不改就留空' : '' ) . '"><p class="description">加密保存，不会发到店铺。</p></td></tr>';
		foreach ( Tencent_Docs::KINDS as $k => $label ) {
			echo '<tr><th><label for="pchq-td-' . esc_attr( $k ) . '">' . esc_html( $label ) . '工作表链接</label></th><td><input id="pchq-td-' . esc_attr( $k ) . '" type="url" name="tdocs_' . esc_attr( $k ) . '_url" class="large-text" placeholder="https://docs.qq.com/sheet/DXXXX?tab=BB08J2" value="' . esc_attr( (string) $s[ $k . '_url' ] ) . '"></td></tr>';
		}
		echo '<tr><th>状态</th><td>';
		if ( $auth ) {
			echo '<strong style="color:#15803d">已授权</strong>';
		} elseif ( Tencent_Docs::has_app() ) {
			echo '<span style="color:#9a4b0b">已填应用，还没授权</span>';
		} else {
			echo '<span style="color:#9a4b0b">还没填应用</span>';
		}
		if ( (int) $s['last_sync'] > 0 ) {
			echo '<br>上次同步：' . esc_html( wp_date( 'Y-m-d H:i', (int) $s['last_sync'], new \DateTimeZone( Logistics::TZ ) ) ) . '　' . esc_html( (string) $s['last_result'] );
		}
		if ( '' !== (string) $s['last_error'] ) {
			echo '<br><span style="color:#b91c1c">' . esc_html( (string) $s['last_error'] ) . '</span>';
		}
		echo '</td></tr></table><p class="pchq-logi-bar"><button type="submit" class="button button-primary">保存</button>';
		if ( '' !== $s['client_id'] ) {
			echo '<button type="submit" class="button" name="tdocs_clear" value="1">清除设置</button>';
		}
		echo '</p></form>';
		echo '<div class="pchq-logi-bar">';
		foreach ( array( 'pchq_tdocs_auth' => $auth ? '重新授权腾讯文档' : '授权腾讯文档', 'pchq_tdocs_sync' => '立即同步' ) as $act => $label ) {
			if ( 'pchq_tdocs_sync' === $act && ! Tencent_Docs::ready() ) {
				continue;
			}
			echo '<form method="post" action="' . $post . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			wp_nonce_field( $act );
			echo '<input type="hidden" name="action" value="' . esc_attr( $act ) . '"><button type="submit" class="button' . ( 'pchq_tdocs_auth' === $act && ! $auth ? ' button-primary' : '' ) . '"' . disabled( Tencent_Docs::has_app(), false, false ) . '>' . esc_html( $label ) . '</button></form>';
		}
		echo '</div><p class="description">腾讯文档的接口不能设置单元格底色：想让新行的序号、日期、客户单号也是绿色，在表格里把 A–C 整列先设成绿色即可。</p></div>';
	}

	/** 筛选条件：付款日期（店铺时区）和店铺。默认最近 30 天。 */
	private static function filters( array $src ): array {
		$tz    = new \DateTimeZone( Logistics::TZ );
		$today = new \DateTimeImmutable( 'today', $tz );
		$from  = (string) ( $src['from'] ?? '' );
		$to    = (string) ( $src['to'] ?? '' );
		$from  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ? $from : $today->modify( '-29 days' )->format( 'Y-m-d' );
		$to    = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ? $to : $today->format( 'Y-m-d' );
		if ( $to < $from ) {
			list( $from, $to ) = array( $to, $from );
		}
		return array(
			'from'  => $from,
			'to'    => $to,
			'site'  => max( 0, (int) ( $src['site'] ?? 0 ) ),
			'tab'   => in_array( $src['tab'] ?? '', array( 'customs', 'tdocs' ), true ) ? (string) $src['tab'] : 'ship',
			'start' => ( new \DateTimeImmutable( $from . ' 00:00:00', $tz ) )->getTimestamp(),
			'end'   => ( new \DateTimeImmutable( $to . ' 00:00:00', $tz ) )->modify( '+1 day' )->getTimestamp(),
		);
	}

	private static function guard( string $nonce ): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( '没有权限。', '', array( 'response' => 403 ) );
		}
		check_admin_referer( $nonce );
	}

	public static function download(): void {
		self::guard( 'pchq_logistics_xlsx' );
		$f    = self::filters( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- filters() 逐项校验。
		$rows = Logistics::rows( $f['start'], $f['end'], $f['site'] );
		$file = Xlsx::build(
			array(
				array( 'name' => '发货登记', 'rows' => Logistics::sheet_ship( $rows ), 'widths' => array( 8, 11, 26, 24, 18, 20, 9 ), 'head' => Xlsx::S_HEAD_BLUE, 'green' => 3, 'left' => array() ),
				array( 'name' => '报关填单', 'rows' => Logistics::sheet_customs( $rows ), 'widths' => array( 6, 9, 22, 8, 24, 10, 10, 20, 7, 14, 32, 16, 28, 10, 6, 16, 10, 9, 10, 13, 10 ), 'head' => Xlsx::S_HEAD, 'green' => 3, 'left' => array( 10, 12 ) ),
			)
		);
		if ( is_wp_error( $file ) ) {
			wp_die( esc_html( $file->get_error_message() ) );
		}
		$name = '物流填单-' . str_replace( '-', '', $f['from'] ) . '-' . str_replace( '-', '', $f['to'] ) . '.xlsx';
		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( "Content-Disposition: attachment; filename=\"logistics.xlsx\"; filename*=UTF-8''" . rawurlencode( $name ) );
		header( 'Content-Length: ' . strlen( $file ) );
		echo $file; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 二进制文件。
		exit;
	}

	public static function save(): void {
		self::guard( 'pchq_logistics_save' );
		Logistics::save_settings( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- save_settings 逐项清理。
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'tab' => 'customs', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function track(): void {
		self::guard( 'pchq_logistics_track' );
		$n = Logistics::refresh_tracking( 40 );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'tracked' => $n ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function cell( $v, string $class = '' ): string {
		return '<td' . ( '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . '>' . nl2br( esc_html( (string) $v ) ) . '</td>';
	}

	public static function render(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			return;
		}
		Logistics::maybe_install();
		$f     = self::filters( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput -- 只读筛选。
		$rows  = Logistics::rows( $f['start'], $f['end'], $f['site'] );
		$sites = Sites::all();
		$base  = array( 'page' => self::SLUG, 'from' => $f['from'], 'to' => $f['to'], 'site' => $f['site'] );

		echo '<div class="wrap pchq-logi"><h1>物流填单</h1>';
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>已保存，下载的 Excel 立即使用新内容。</p></div>';
		}
		if ( isset( $_GET['tracked'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>已查询 ' . (int) $_GET['tracked'] . ' 个物流单号的状态。</p></div>';
		}
		if ( isset( $_GET['tmsg'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sanitize_text_field( rawurldecode( wp_unslash( $_GET['tmsg'] ) ) ) ) . '</p></div>';
		}
		if ( isset( $_GET['terr'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sanitize_text_field( rawurldecode( wp_unslash( $_GET['terr'] ) ) ) ) . '</p></div>';
		}
		// phpcs:enable
		echo '<p class="pchq-logi-intro">所有店铺付款成功的订单会自动出现在这里（付款后约 1 分钟；物流单号每小时同步一次）。退款或取消的订单自动去掉。备注栏的“妥投”由 17TRACK 每 3 小时更新。</p>';

		// 筛选。
		echo '<form method="get" class="pchq-logi-bar"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="' . esc_attr( $f['tab'] ) . '">';
		echo '<label>付款日期 <input type="date" name="from" value="' . esc_attr( $f['from'] ) . '"></label><span>至</span><label><input type="date" name="to" value="' . esc_attr( $f['to'] ) . '"></label>';
		echo '<label>店铺 <select name="site"><option value="0">全部店铺</option>';
		foreach ( $sites as $id => $s ) {
			echo '<option value="' . (int) $id . '"' . selected( $f['site'], (int) $id, false ) . '>' . esc_html( (string) ( $s['name'] ?? $id ) ) . '</option>';
		}
		echo '</select></label><button type="submit" class="button">查看</button>';
		$dl = wp_nonce_url( add_query_arg( array_merge( $base, array( 'action' => 'pchq_logistics_xlsx' ) ), admin_url( 'admin-post.php' ) ), 'pchq_logistics_xlsx' );
		echo '<a class="button button-primary" href="' . esc_url( $dl ) . '">下载 Excel（两个表）</a>';
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pchq-logi-bar pchq-logi-sub">';
		wp_nonce_field( 'pchq_logistics_track' );
		echo '<input type="hidden" name="action" value="pchq_logistics_track"><span>共 <strong>' . count( $rows ) . '</strong> 单</span>';
		echo Track17::configured() ? '<button type="submit" class="button">立即更新物流状态</button>' : '<span class="pchq-logi-muted">17TRACK 还没设置，备注栏暂不显示妥投。</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</form>';

		// 子表切换。
		echo '<nav class="nav-tab-wrapper">';
		foreach ( array( 'ship' => '发货登记', 'customs' => '报关填单', 'tdocs' => '同步到腾讯文档' ) as $k => $label ) {
			echo '<a class="nav-tab' . ( $f['tab'] === $k ? ' nav-tab-active' : '' ) . '" href="' . esc_url( add_query_arg( array_merge( $base, array( 'tab' => $k ) ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		if ( 'tdocs' === $f['tab'] ) {
			self::render_tdocs();
			self::styles();
			echo '</div>';
			return;
		}

		$sheet = 'customs' === $f['tab'] ? Logistics::sheet_customs( $rows ) : Logistics::sheet_ship( $rows );
		$head  = array_shift( $sheet );
		echo '<div class="pchq-logi-wrap"><table class="pchq-logi-table pchq-logi-' . esc_attr( $f['tab'] ) . '"><thead><tr>';
		foreach ( $head as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( ! $sheet ) {
			echo '<tr><td colspan="' . count( $head ) . '" class="pchq-logi-empty">这个时间段还没有付款成功的订单。</td></tr>';
		}
		foreach ( $sheet as $i => $line ) {
			$site = $sites[ (int) $rows[ $i ]['site_id'] ]['name'] ?? '';
			echo '<tr>';
			foreach ( $line as $ci => $v ) {
				if ( 2 === $ci ) {
					echo '<td class="g">' . esc_html( (string) $v ) . ( '' !== $site ? '<small>' . esc_html( (string) $site ) . '</small>' : '' ) . '</td>';
					continue;
				}
				echo self::cell( $v, $ci < 3 ? 'g' : ( 'customs' === $f['tab'] && in_array( $ci, array( 10, 12 ), true ) ? 'l' : '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';

		if ( 'customs' === $f['tab'] ) {
			$s = Logistics::settings();
			echo '<details class="pchq-logi-set"><summary>报关填单的固定内容</summary><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'pchq_logistics_save' );
			echo '<input type="hidden" name="action" value="pchq_logistics_save"><table class="form-table">';
			foreach ( array( 'method' => '运输方式', 'name_en' => '海关报关品名1', 'goods' => '配货信息1', 'value' => '申报价值1', 'hs' => '海关编码' ) as $k => $label ) {
				echo '<tr><th><label for="pchq-logi-' . esc_attr( $k ) . '">' . esc_html( $label ) . '</label></th><td><input id="pchq-logi-' . esc_attr( $k ) . '" type="text" class="regular-text" name="' . esc_attr( $k ) . '" value="' . esc_attr( $s[ $k ] ) . '"></td></tr>';
			}
			echo '</table><p><button type="submit" class="button button-primary">保存</button></p></form></details>';
		}

		self::styles();
		echo '</div>';
	}

	private static function styles(): void {
		echo '<style>
.pchq-logi-card{margin-top:12px;padding:4px 20px 16px;background:#fff;border:1px solid #c3c4c7;max-width:960px}
.pchq-logi-steps{line-height:1.9;max-width:80ch}
.pchq-logi-bar form{display:inline}
.pchq-logi-intro{max-width:80ch;line-height:1.7;color:#3c434a}
.pchq-logi-bar{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:12px 0}
.pchq-logi-bar label{display:inline-flex;align-items:center;gap:6px}
.pchq-logi-sub{margin-top:0}.pchq-logi-muted{color:#8a6d3b}
.pchq-logi-wrap{overflow-x:auto;margin-top:12px;background:#fff;border:1px solid #c3c4c7}
.pchq-logi-table{border-collapse:collapse;min-width:100%;font-size:13px}
.pchq-logi-table th,.pchq-logi-table td{border:1px solid #1d2327;padding:6px 10px;text-align:center;vertical-align:middle;white-space:nowrap}
.pchq-logi-ship th{background:#8edcf8;font-size:14px;font-weight:700}
.pchq-logi-customs th{background:#fff;font-weight:700}
.pchq-logi-table td.g{background:#92d050}
.pchq-logi-table td.l{text-align:left}
.pchq-logi-table td small{display:block;color:#1d4d12;font-size:11px;opacity:.75}
.pchq-logi-empty{padding:28px!important;color:#646970}
.pchq-logi-set{margin-top:18px}.pchq-logi-set summary{cursor:pointer;font-weight:600}
</style>';
	}
}
