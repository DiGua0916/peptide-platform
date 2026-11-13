<?php
/**
 * 素材看板页面（线 4）：按素材 / 素材属性看有效进站、意向分层、加购、成本；漏参提醒；检查点导出；每日上报状态。
 *
 * - 页面 pc-creatives-board（能力 Staff_Buyer::CAP_VIEW，投手和店铺管理员都能看）。筛选用 GET 参数：
 *   dim（attr / creative / ad / adset / platform）、mode（first / last）、from、to（店铺时区日期）、ver（站点版本号）。
 * - 写操作都走 admin-post.php?action=pc_creative_export（Module::ACTION_EXPORT，投手已放行），用 op 区分：
 *   download（下载一份导出）、manual（按日期手动导出）、settings（漏参阈值、站点版本号）。
 * - 站点版本号先手工维护：选项 pc_creatives_site_versions（[{ver, since_ms, user}]），在本页“设置”里改；
 *   改版本号 = 从现在开始记为新版本。按版本筛选就是按版本的起止时间筛选。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Creatives;

use PeptideCore\Staff_Buyer;

defined( 'ABSPATH' ) || exit;

final class Board_Page {

	public const OPTION_SETTINGS = 'pc_creatives_board';
	public const OPTION_VERSIONS = 'pc_creatives_site_versions';
	/** “今天要做的事”里标记过已处理的行（按店铺记，只当天有效）：array{day:string,keys:array<string,int>} */
	public const OPTION_HANDLED  = 'pc_creatives_board_handled';

	/** 今天已处理的行 key => 时间戳（换天自动清空）。 */
	public static function handled_today(): array {
		$h = get_option( self::OPTION_HANDLED, array() );
		return is_array( $h ) && ( $h['day'] ?? '' ) === wp_date( 'Y-m-d' ) && is_array( $h['keys'] ?? null ) ? $h['keys'] : array();
	}

	/** 默认看最近几天。 */
	public const DEFAULT_DAYS = 7;

	public static function register_hooks(): void {
		add_action( 'admin_post_' . Module::ACTION_EXPORT, array( self::class, 'handle' ) );
		Export::register_hooks();
		Daily_Report::register_hooks();
	}

	// ---------- 设置和版本 ----------

	/** @return array{untagged_pct:float,min_clicks:int,scale_roas:float,stop_roas:float,stop_spend:float} */
	public static function settings(): array {
		$s = get_option( self::OPTION_SETTINGS, array() );
		$s = is_array( $s ) ? $s : array();
		return array(
			'untagged_pct' => isset( $s['untagged_pct'] ) ? max( 0.0, min( 100.0, (float) $s['untagged_pct'] ) ) : (float) Board::UNTAGGED_PCT,
			'min_clicks'   => isset( $s['min_clicks'] ) ? max( 1, min( 100000, (int) $s['min_clicks'] ) ) : Board::MIN_CLICKS,
			// 建议标签的规则（2026-11-13）。
			'scale_roas'   => isset( $s['scale_roas'] ) ? max( 0.0, min( 100.0, (float) $s['scale_roas'] ) ) : (float) Board::RULES['scale_roas'],
			'stop_roas'    => isset( $s['stop_roas'] ) ? max( 0.0, min( 100.0, (float) $s['stop_roas'] ) ) : (float) Board::RULES['stop_roas'],
			'stop_spend'   => isset( $s['stop_spend'] ) ? max( 0.0, min( 100000.0, (float) $s['stop_spend'] ) ) : Board::RULES['stop_spend_cents'] / 100,
		);
	}

	/** 设置 → Board::verdict() 的规则。 */
	public static function rules( array $settings ): array {
		return array( 'scale_roas' => $settings['scale_roas'], 'stop_roas' => $settings['stop_roas'], 'stop_spend_cents' => (int) round( $settings['stop_spend'] * 100 ) );
	}

	/** @return list<array{ver:string,since_ms:int,user:int}> */
	public static function versions(): array {
		$v = get_option( self::OPTION_VERSIONS, array() );
		return array_values( array_filter( is_array( $v ) ? $v : array(), static fn( $x ) => is_array( $x ) && self::valid_version( (string) ( $x['ver'] ?? '' ) ) ) );
	}

	public static function current_version(): string {
		$list = self::versions();
		usort( $list, static fn( $a, $b ) => (int) $a['since_ms'] <=> (int) $b['since_ms'] );
		return $list ? (string) end( $list )['ver'] : '';
	}

	/** 版本号：字母数字和 . _ -，1–32 位。 */
	public static function valid_version( string $ver ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$/', $ver );
	}

	// ---------- 写操作 ----------

	public static function handle(): void {
		if ( ! current_user_can( Staff_Buyer::CAP_VIEW ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'peptide-core' ), 403 );
		}
		$op = isset( $_REQUEST['op'] ) ? sanitize_key( wp_unslash( $_REQUEST['op'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'download' === $op ) {
			check_admin_referer( 'pc_creative_export_dl' );
			Export::stream( isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '', isset( $_GET['fmt'] ) ? sanitize_key( wp_unslash( $_GET['fmt'] ) ) : 'json' );
			return;
		}
		if ( 'handled' === $op || 'unhandle' === $op ) {
			check_admin_referer( 'pc_creative_board_handled' );
			$key  = isset( $_POST['key'] ) ? substr( (string) preg_replace( '/[^A-Za-z0-9:_|.-]/', '', (string) wp_unslash( $_POST['key'] ) ), 0, 80 ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$keys = self::handled_today();
			if ( '' !== $key ) {
				if ( 'handled' === $op ) {
					$keys[ $key ] = time();
				} else {
					unset( $keys[ $key ] );
				}
				update_option( self::OPTION_HANDLED, array( 'day' => wp_date( 'Y-m-d' ), 'keys' => $keys ), false );
			}
			self::back( 'success', 'handled' === $op ? '已标记为处理过，今天不再提醒。' : '已撤销，重新放回待办。' );
		}
		if ( 'manual' === $op ) {
			check_admin_referer( 'pc_creative_export_manual' );
			$from = isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '';
			$to   = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';
			if ( ! Board::valid_day( $from ) || ! Board::valid_day( $to ) || $from > $to ) {
				self::back( 'error', '日期不对，请重新选择。' );
			}
			$entry = Export::generate( 'manual', '手动导出 ' . $from . ' ~ ' . $to, array(), array( $from, $to ) );
			self::back( $entry ? 'success' : 'error', $entry ? '已生成导出，在下面的列表里下载。' : '导出没生成成功（上传目录不可写？）。' );
		}
		if ( 'settings' === $op ) {
			check_admin_referer( 'pc_creative_board_settings' );
			$pct    = isset( $_POST['untagged_pct'] ) ? (float) wp_unslash( $_POST['untagged_pct'] ) : Board::UNTAGGED_PCT;
			$clicks = isset( $_POST['min_clicks'] ) ? (int) wp_unslash( $_POST['min_clicks'] ) : Board::MIN_CLICKS;
			$num = static fn( string $k, float $d ): float => isset( $_POST[ $k ] ) && is_numeric( wp_unslash( $_POST[ $k ] ) ) ? (float) wp_unslash( $_POST[ $k ] ) : $d; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$old = self::settings();
			$scale = max( 0.0, min( 100.0, $num( 'scale_roas', $old['scale_roas'] ) ) );
			$stop  = max( 0.0, min( $scale, $num( 'stop_roas', $old['stop_roas'] ) ) );
			update_option( self::OPTION_SETTINGS, array( 'untagged_pct' => max( 0.0, min( 100.0, $pct ) ), 'min_clicks' => max( 1, min( 100000, $clicks ) ), 'scale_roas' => $scale, 'stop_roas' => $stop, 'stop_spend' => max( 0.0, min( 100000.0, $num( 'stop_spend', $old['stop_spend'] ) ) ) ), false );
			$ver = isset( $_POST['version'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['version'] ) ) ) : '';
			$msg = '设置已保存。';
			if ( '' !== $ver && $ver !== self::current_version() ) {
				if ( ! self::valid_version( $ver ) || in_array( $ver, array_column( self::versions(), 'ver' ), true ) ) {
					self::back( 'error', '版本号只能用字母、数字和 . _ -，并且不能和以前的版本号重复。阈值已保存。' );
				}
				$list   = self::versions();
				$list[] = array( 'ver' => $ver, 'since_ms' => (int) floor( microtime( true ) * 1000 ), 'user' => get_current_user_id() );
				update_option( self::OPTION_VERSIONS, $list, false );
				$msg = '设置已保存，从现在起记为版本 ' . $ver . '。';
			}
			self::back( 'success', $msg );
		}
		self::back( 'error', '不认识的操作。' );
	}

	private static function back( string $type, string $text ): void {
		Staff_Buyer::flash( $type, $text );
		$ref = wp_get_referer();
		wp_safe_redirect( $ref && str_contains( $ref, 'page=' . Module::PAGE_BOARD ) ? $ref : admin_url( 'admin.php?page=' . Module::PAGE_BOARD ) );
		exit;
	}

	// ---------- 页面 ----------

	/**
	 * 解析筛选参数。
	 *
	 * @return array{dim:string,mode:string,from:string,to:string,ver:string}
	 */
	public static function filters( array $get, string $tz, int $now ): array {
		$dim  = is_string( $get['dim'] ?? null ) && isset( Board::DIMS[ $get['dim'] ] ) ? $get['dim'] : 'creative';
		$mode = is_string( $get['mode'] ?? null ) && isset( Board::MODES[ $get['mode'] ] ) ? $get['mode'] : 'first';
		$to   = Board::day( $now * 1000, $tz );
		$from = Board::day( ( $now - ( self::DEFAULT_DAYS - 1 ) * 86400 ) * 1000, $tz );
		if ( is_string( $get['from'] ?? null ) && is_string( $get['to'] ?? null ) && Board::valid_day( $get['from'] ) && Board::valid_day( $get['to'] ) && $get['from'] <= $get['to'] ) {
			$from = $get['from'];
			$to   = $get['to'];
		}
		$ver  = is_string( $get['ver'] ?? null ) && self::valid_version( $get['ver'] ) ? $get['ver'] : '';
		$sort = is_string( $get['sort'] ?? null ) && isset( Board::SORTS[ $get['sort'] ] ) ? $get['sort'] : 'roas';
		return array( 'dim' => $dim, 'mode' => $mode, 'from' => $from, 'to' => $to, 'ver' => $ver, 'sort' => $sort );
	}

	public static function render(): void {
		if ( ! current_user_can( Staff_Buyer::CAP_VIEW ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'peptide-core' ), 403 );
		}
		$tz       = Board::timezone();
		$f        = self::filters( wp_unslash( $_GET ), $tz, time() ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$settings = self::settings();
		$versions = self::versions();
		$ms       = Board::range_ms( $f['from'], $f['to'], $tz );
		$ver_note = '';
		if ( '' !== $f['ver'] ) {
			$r = Export::version_range( $versions, $f['ver'], (int) floor( microtime( true ) * 1000 ) );
			if ( $r ) {
				// 版本时间段和所选日期取交集。
				$ms       = array( max( $ms[0], $r[0] ), min( $ms[1], $r[1] ) );
				$ver_note = '版本 ' . $f['ver'] . ' 从 ' . wp_date( 'Y-m-d H:i', intdiv( $r[0], 1000 ) ) . ' 开始';
			}
		}
		$latest = Export::latest_checkpoint();
		$unseen = $latest && (string) get_user_meta( get_current_user_id(), Export::META_SEEN, true ) < $latest['id'];
		Export::mark_seen();

		$agg = array( 'rows' => array(), 'total' => Board::metrics( array( 'visitors' => 0 ) ) + array( 'label' => '合计', 'meta' => array(), 'visitor_ids' => array() ), 'untagged' => 0, 'paid' => 0, 'ad_day_visitors' => array() );
		$data = array( 'spend' => array(), 'ads' => array() );
		$err  = '';
		try {
			if ( $ms[0] < $ms[1] ) {
				$data = Board::load( $ms, $f['from'], $f['to'] );
				$agg  = Board::aggregate( $data, array( 'mode' => $f['mode'], 'dim' => $f['dim'], 'from_ms' => $ms[0], 'to_ms' => $ms[1], 'tz' => $tz ) );
			}
		} catch ( \Throwable $e ) {
			$err = '数据暂时读不出来，请稍后刷新。';
		}

		echo '<div class="wrap pc-creatives pc-cr-board">';
		echo '<h1>素材看板</h1>';
		Module::tabs( Module::PAGE_BOARD );
		self::styles();
		Staff_Buyer::render_flash();
		if ( $unseen ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html( '素材数据到检查点了（' . $latest['label'] . '），已生成分析导出，' ) . '<a href="#pc-cr-exports">在下面下载</a>。</p></div>';
		}
		if ( '' !== $err ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $err ) . '</p></div>';
		}
		self::render_alerts( $agg, $data, $settings );
		self::render_filters( $f, $versions, $ver_note );
		self::render_cards( $agg, $f, $settings, $data );
		self::render_exports();
		self::render_settings( $settings, $versions );
		self::render_report_status();
		echo '</div>';
	}

	private static function styles(): void {
		echo '<style>
.pc-cr-board .pc-cr-filters{display:flex;flex-wrap:wrap;gap:8px 16px;align-items:flex-end;margin:12px 0}
.pc-cr-board .pc-cr-filters label{display:flex;flex-direction:column;font-size:12px;color:#50575e;gap:2px}
.pc-cr-board .pc-cr-scroll{overflow-x:auto;max-width:100%;container-type:inline-size}
.pc-cr-board table.pc-cr-table th{white-space:normal!important;line-height:1.3;vertical-align:bottom}
/* 自适应（2026-11-13）：放不下整张表时，每行变成一张卡片，所有指标带名称显示，花费 / 订单 / 销售额 / ROAS 排在前面。 */
@container (max-width: 1240px){
.pc-cr-board table.pc-cr-table,.pc-cr-board table.pc-cr-table tbody{display:block;border:0;background:none;width:100%;box-sizing:border-box}
.pc-cr-board table.pc-cr-table tr{box-sizing:border-box;width:auto}
.pc-cr-board table.pc-cr-table{overflow-x:clip}
.pc-cr-board table.pc-cr-table thead{display:none}
.pc-cr-board table.pc-cr-table tr{display:grid;grid-template-columns:repeat(auto-fill,minmax(118px,1fr));gap:2px 10px;background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:10px 12px;margin:0 0 10px}
.pc-cr-board table.pc-cr-table tr.pc-cr-thin{background:#fafafa}
.pc-cr-board table.pc-cr-table tr.pc-cr-total{border:2px solid #2271b1}
.pc-cr-board table.pc-cr-table tr.pc-cr-group{display:block;border:0;background:none;padding:6px 0 0;margin:8px 0 4px}
.pc-cr-board table.pc-cr-table td,.pc-cr-board table.pc-cr-table th{display:block;text-align:left!important;padding:4px 0!important;border:0!important;white-space:normal}
.pc-cr-board table.pc-cr-table td:first-child{grid-column:1/-1;min-width:0;padding-bottom:8px!important;margin-bottom:4px;border-bottom:1px solid #f0f0f1!important}
.pc-cr-board table.pc-cr-table td[data-label]::before{content:attr(data-label);display:block;font-size:11px;line-height:15px;color:#646970;font-variant-numeric:normal}
.pc-cr-board table.pc-cr-table td[data-label]{font-size:14px;font-weight:600}
.pc-cr-board table.pc-cr-table td.pc-cr-key{order:-1;font-size:16px}
.pc-cr-board table.pc-cr-table td:first-child{order:-2}
}
.pc-cr-board table.pc-cr-table td,.pc-cr-board table.pc-cr-table th{white-space:nowrap;text-align:right;font-variant-numeric:tabular-nums}
.pc-cr-board table.pc-cr-table td:first-child,.pc-cr-board table.pc-cr-table th:first-child{text-align:left;white-space:normal;min-width:180px}
.pc-cr-board tr.pc-cr-thin td{color:#a7aaad}
.pc-cr-board tr.pc-cr-thin td:first-child{color:#646970}
.pc-cr-board .pc-cr-tag{display:inline-block;font-size:11px;line-height:16px;padding:0 6px;border-radius:8px;background:#f0f0f1;color:#646970;margin-left:6px}
.pc-cr-board .pc-cr-attrs-inline{display:inline-block;font-size:12px;color:#50575e;margin-left:6px}
.pc-cr-board tr.pc-cr-group th{background:#f6f7f7;text-align:left}
.pc-cr-board tr.pc-cr-total td{font-weight:600;border-top:2px solid #c3c4c7}
.pc-cr-board .pc-cr-thumb{width:32px;height:32px;object-fit:cover;vertical-align:middle;margin-right:6px;border-radius:3px}
.pc-cr-board details summary{cursor:pointer}
.pc-cr-board details ul{margin:4px 0 0 0;columns:3;font-size:12px}
.pc-cr-board .pc-cr-alert{border-left:4px solid #dba617;background:#fff;padding:8px 12px;margin:12px 0}
.pc-cr-board .pc-cr-section{margin-top:28px}
.pc-cr-board .pc-cr-muted{color:#646970}
.pc-cr-board .pc-cr-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin:14px 0 6px}
.pc-cr-board .pc-cr-kpi{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:10px 14px}
.pc-cr-board .pc-cr-kpi span{display:block;font-size:12px;color:#646970}
.pc-cr-board .pc-cr-kpi b{display:block;font-size:22px;line-height:1.3;font-weight:600;font-variant-numeric:tabular-nums}
.pc-cr-board .pc-cr-toolbar{display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center;justify-content:space-between;margin:12px 0 8px}
.pc-cr-board .pc-cr-sorts{display:flex;gap:4px 14px;flex-wrap:wrap}
.pc-cr-board .pc-cr-counts{display:flex;gap:6px;flex-wrap:wrap}
.pc-cr-board .pc-cr-badge{display:inline-block;white-space:nowrap;font-size:12px;line-height:20px;padding:0 10px;border-radius:10px;background:#f0f0f1;color:#50575e}
.pc-cr-board .pc-cr-badge.is-scale{background:#edfaef;color:#007017}
.pc-cr-board .pc-cr-badge.is-watch{background:#fcf9e8;color:#8a6d00}
.pc-cr-board .pc-cr-badge.is-stop{background:#fcf0f1;color:#b32d2e}
.pc-cr-board .pc-cr-badge.is-nospend{background:#f0f6fc;color:#2271b1}
.pc-cr-board .pc-cr-group-title{margin:18px 0 6px;font-size:14px}
.pc-cr-board details.pc-cr-row{background:#fff;border:1px solid #dcdcde;border-radius:8px;margin:0 0 10px}
.pc-cr-board details.pc-cr-row.is-stop{border-left:4px solid #d63638}
.pc-cr-board details.pc-cr-row.is-scale{border-left:4px solid #00a32a}
.pc-cr-board details.pc-cr-row.is-thin{background:#fafafa}
.pc-cr-board details.pc-cr-row>summary{list-style:none;padding:12px 14px;display:grid;gap:8px}
.pc-cr-board details.pc-cr-row>summary::-webkit-details-marker{display:none}
.pc-cr-board .pc-cr-row-head{display:flex;align-items:center;gap:10px}
.pc-cr-board .pc-cr-row-head .pc-cr-thumb{width:40px;height:40px;margin:0}
.pc-cr-board .pc-cr-row-name{flex:1;min-width:0}
.pc-cr-board .pc-cr-row-name b{font-family:monospace;font-size:14px;margin-right:8px}
.pc-cr-board .pc-cr-row-name span{font-size:12px;color:#50575e}
.pc-cr-board .pc-cr-row-keys{display:flex;flex-wrap:wrap;gap:4px 22px;font-size:13px;color:#50575e}
.pc-cr-board .pc-cr-row-keys b{font-size:16px;color:#1d2327;font-variant-numeric:tabular-nums}
.pc-cr-board .pc-cr-row-keys b.is-good{color:#007017}
.pc-cr-board .pc-cr-row-keys b.is-bad{color:#b32d2e}
.pc-cr-board .pc-cr-funnel{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px}
.pc-cr-board .pc-cr-step span{display:block;font-size:12px;color:#50575e;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pc-cr-board .pc-cr-step b{color:#1d2327}
.pc-cr-board .pc-cr-step em{font-style:normal;color:#8c8f94}
.pc-cr-board .pc-cr-step i{display:block;height:8px;border-radius:4px;background:#72aee6;margin-top:3px;min-width:2px}
.pc-cr-board .pc-cr-step.is-drop span,.pc-cr-board .pc-cr-step.is-drop em{color:#b32d2e}
.pc-cr-board .pc-cr-step.is-drop i{background:#e65054}
.pc-cr-board .pc-cr-row-body{border-top:1px solid #f0f0f1;padding:10px 14px 12px}
.pc-cr-board .pc-cr-all{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:8px 12px}
.pc-cr-board .pc-cr-all span{display:block;font-size:11px;color:#646970}
.pc-cr-board .pc-cr-all b{font-variant-numeric:tabular-nums}
.pc-cr-board ul.pc-cr-visitors{columns:4;font-size:12px;margin:4px 0 0}
.pc-cr-board ul.pc-cr-visitors li{break-inside:avoid;margin:0 0 4px}
.pc-cr-board ul.pc-cr-visitors li.is-buyer a{font-weight:600}
.pc-cr-board ul.pc-cr-visitors li b{color:#007017;font-weight:600;font-variant-numeric:tabular-nums}
/* 卡片展开提示（2026-11-13） */
.pc-cr-board .pc-cr-toggle{font-size:12px;color:#2271b1;white-space:nowrap;margin-left:4px}
.pc-cr-board .pc-cr-toggle b{font-weight:400}
.pc-cr-board .pc-cr-toggle .is-shut::after{content:" \25BE"}
.pc-cr-board .pc-cr-toggle .is-open{display:none}
.pc-cr-board .pc-cr-toggle .is-open::after{content:" \25B4"}
.pc-cr-board details.pc-cr-row[open] .pc-cr-toggle .is-shut{display:none}
.pc-cr-board details.pc-cr-row[open] .pc-cr-toggle .is-open{display:inline;color:#646970}
.pc-cr-board details.pc-cr-row>summary:hover{background:#f6f7f7}
.pc-cr-board details.pc-cr-row[open]>summary{border-bottom:1px solid #f0f0f1}
.pc-cr-board .pc-cr-expand{font-size:13px}
/* 决策清单按钮（2026-11-13） */
.pc-cr-board .pc-cr-todo-actions{margin-top:8px!important;display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.pc-cr-board .pc-cr-inline-form{display:inline}
.pc-cr-board details.pc-cr-done{margin:6px 0 0;color:#50575e}
.pc-cr-board details.pc-cr-done summary{cursor:pointer}
.pc-cr-board details.pc-cr-done li{margin:4px 0}
/* 决策清单、象限图、热力格（2026-11-13） */
.pc-cr-board .pc-cr-h2{font-size:16px;margin:26px 0 6px}
.pc-cr-board .pc-cr-h3{font-size:14px;margin:16px 0 4px}
.pc-cr-board .pc-cr-today .pc-cr-h2{margin-top:18px}
.pc-cr-board .pc-cr-todo{display:flex;gap:12px;align-items:flex-start;background:#fff;border:1px solid #dcdcde;border-left:4px solid #c3c4c7;border-radius:0 8px 8px 0;padding:12px 14px;margin:0 0 8px}
.pc-cr-board .pc-cr-todo.is-stop{border-left-color:#d63638}
.pc-cr-board .pc-cr-todo.is-scale{border-left-color:#00a32a}
.pc-cr-board .pc-cr-todo>div{flex:1;min-width:0}
.pc-cr-board .pc-cr-todo p{margin:0}
.pc-cr-board .pc-cr-todo .pc-cr-thumb{width:46px;height:46px;margin:0;flex:none}
.pc-cr-board .pc-cr-thumb.is-blank{display:inline-block;background:#f0f0f1;border-radius:3px}
.pc-cr-board .pc-cr-todo-title{font-size:15px;font-weight:600}
.pc-cr-board .pc-cr-todo-why{color:#50575e;margin:2px 0 4px!important}
.pc-cr-board .pc-cr-todo-nums{color:#646970;font-size:12px;font-variant-numeric:tabular-nums}
.pc-cr-board ul.pc-cr-waits{margin:4px 0 0;color:#50575e}
.pc-cr-board ul.pc-cr-waits li{margin:0 0 2px}
.pc-cr-board .pc-cr-quadrant svg{width:100%;max-width:780px;height:auto;background:#fff;border:1px solid #dcdcde;border-radius:8px}
.pc-cr-board .pc-cr-grid-wrap{overflow-x:auto;max-width:100%}
.pc-cr-board table.pc-cr-grid{border-collapse:separate;border-spacing:4px;min-width:460px}
.pc-cr-board table.pc-cr-grid th{font-size:12px;color:#50575e;font-weight:400;padding:2px 6px;text-align:center}
.pc-cr-board table.pc-cr-grid th[scope=row]{text-align:left;white-space:nowrap}
.pc-cr-board table.pc-cr-grid td{min-width:84px;text-align:center;border-radius:5px;padding:8px 6px;background:#f6f7f7;line-height:1.3}
.pc-cr-board table.pc-cr-grid td b{display:block;font-size:15px;font-variant-numeric:tabular-nums}
.pc-cr-board table.pc-cr-grid td span{font-size:11px;opacity:.85}
.pc-cr-board table.pc-cr-grid td.is-none{color:#8c8f94;font-size:12px}
.pc-cr-board table.pc-cr-grid td.is-good{background:#5dcaa5;color:#04342c}
.pc-cr-board table.pc-cr-grid td.is-mid{background:#fac775;color:#412402}
.pc-cr-board table.pc-cr-grid td.is-bad{background:#f09595;color:#501313}
.pc-cr-board table.pc-cr-grid td.is-nospend{background:#e6f1fb;color:#0c447c}
.pc-cr-board table.pc-cr-grid td.is-thin{opacity:.6}
@media (max-width:600px){.pc-cr-board .pc-cr-funnel{grid-template-columns:repeat(3,minmax(0,1fr))}.pc-cr-board ul.pc-cr-visitors{columns:2}.pc-cr-board .pc-cr-kpi b{font-size:18px}}
</style>';
	}

	private static function render_alerts( array $agg, array $data, array $settings ): void {
		$untagged = Board::untagged_alert( (int) $agg['untagged'], (int) $agg['paid'], $settings['untagged_pct'] );
		$missing  = Board::missing_params( (array) ( $data['spend'] ?? array() ), (array) $agg['ad_day_visitors'], $settings['min_clicks'], (array) ( $data['ads'] ?? array() ) );
		if ( ! $untagged && ! $missing ) {
			echo '<p class="pc-cr-muted">漏参检查：没有发现问题（没带参数的 FB/IG 访客占比不超过 ' . esc_html( self::num( $settings['untagged_pct'], 1 ) ) . '%，也没有点击 ≥ ' . esc_html( (string) $settings['min_clicks'] ) . ' 却没有访客的广告）。</p>';
			return;
		}
		echo '<div class="pc-cr-alert" role="status"><strong>漏参提醒</strong>';
		if ( $untagged ) {
			echo '<p>' . esc_html( sprintf( '没带广告参数的 FB/IG 访客有 %d 人，占付费访客的 %s%%（超过 %s%%）。请检查广告的“网址参数”是否填了固定模板。', $untagged['untagged'], self::num( $untagged['share'] * 100, 1 ), self::num( $settings['untagged_pct'], 1 ) ) ) . '</p>';
		}
		if ( $missing ) {
			echo '<p>疑似漏填参数（当天链接点击 ≥ ' . esc_html( (string) $settings['min_clicks'] ) . '，但没有一个访客带着这个广告进站）：</p><ul>';
			foreach ( array_slice( $missing, 0, 30 ) as $m ) {
				echo '<li>' . esc_html( $m['day'] . '　' . $m['ad_name'] . '（广告 ' . $m['ad_id'] . '，点击 ' . $m['link_clicks'] . '）' ) . '</li>';
			}
			echo '</ul>';
			if ( count( $missing ) > 30 ) {
				echo '<p class="pc-cr-muted">还有 ' . esc_html( (string) ( count( $missing ) - 30 ) ) . ' 条没列出。</p>';
			}
		}
		echo '</div>';
	}

	private static function render_filters( array $f, array $versions, string $ver_note ): void {
		echo '<form method="get" class="pc-cr-filters"><input type="hidden" name="page" value="' . esc_attr( Module::PAGE_BOARD ) . '">';
		echo '<label>维度<select name="dim">';
		foreach ( Board::DIMS as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $f['dim'], $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label><label>口径<select name="mode">';
		foreach ( Board::MODES as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $f['mode'], $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label>';
		echo '<label>开始<input type="date" name="from" value="' . esc_attr( $f['from'] ) . '"></label>';
		echo '<label>结束<input type="date" name="to" value="' . esc_attr( $f['to'] ) . '"></label>';
		echo '<label>站点版本<select name="ver"><option value="">全部</option>';
		foreach ( array_reverse( $versions ) as $v ) {
			echo '<option value="' . esc_attr( $v['ver'] ) . '"' . selected( $f['ver'], $v['ver'], false ) . '>' . esc_html( $v['ver'] ) . '</option>';
		}
		echo '</select></label><button class="button">查看</button></form>';
		if ( '' !== $ver_note ) {
			echo '<p class="pc-cr-muted">' . esc_html( $ver_note . '；只看这个版本期间、且在所选日期内的数据。' ) . '</p>';
		}
	}

	/** 表格列：键 => [标题, 格式]。 */
	private const COLUMNS = array(
		'spend_cents'    => array( '花费', 'money' ),
		'link_clicks'    => array( '链接点击', 'int' ),
		'visitors'       => array( '进站访客', 'int' ),
		'arrival_rate'   => array( '到达率', 'pct' ),
		'valid_rate'     => array( '有效进站率', 'pct' ),
		'mid_high_share' => array( '中高意向占比', 'pct' ),
		'cart_rate'      => array( '加购率', 'pct' ),
		'checkout_rate'  => array( '进结账率', 'pct' ),
		'cashier'        => array( '进收银页', 'int' ),
		'orders'         => array( '订单', 'int' ),
		'sales_cents'    => array( '销售额', 'money' ),
		'cost_per_valid' => array( '每有效访客成本', 'money' ),
		'cost_per_high'  => array( '每高意向访客成本', 'money' ),
		'roas'           => array( 'ROAS', 'x' ),
	);

	private static function render_table( array $agg, array $f ): void {
		$total = $agg['total'];
		echo '<p>' . esc_html( sprintf( '%s ~ %s（%s，%s）：付费访客 %d，其中有效 %d（高 %d / 中 %d / 低 %d / 无效 %d）；没带参数的 FB/IG 访客 %d。', $f['from'], $f['to'], Board::MODES[ $f['mode'] ], Board::DIMS[ $f['dim'] ], $total['visitors'], $total['valid'], $total['intent']['high'], $total['intent']['mid'], $total['intent']['low'], $total['intent']['invalid'], $agg['untagged'] ) ) . '</p>';
		if ( 'attr' === $f['dim'] ) {
			echo '<p class="pc-cr-muted">按属性时，每个访客在钩子、形式、卖点、语言下各算一次，每组加起来等于合计。属性以素材库登记为准。</p>';
		} elseif ( 'ad' === $f['dim'] ) {
			echo '<p class="pc-cr-muted">每站日访客 100–200、广告组 20–30 个，单条广告的样本通常很小，只作参考，结论看“按素材属性”。</p>';
		} elseif ( 'platform' === $f['dim'] ) {
			echo '<p class="pc-cr-muted">花费按广告导入，分不到平台 / 版位，所以花费和成本列显示“—”。</p>';
		}
		echo '<div class="pc-cr-scroll"><table class="widefat striped pc-cr-table"><thead><tr><th>' . esc_html( Board::DIMS[ $f['dim'] ] ) . '</th>';
		foreach ( self::COLUMNS as $col ) {
			echo '<th>' . esc_html( $col[0] ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( ! $agg['rows'] ) {
			echo '<tr><td colspan="' . esc_attr( (string) ( count( self::COLUMNS ) + 1 ) ) . '">这段时间没有付费访客，也没有导入花费。</td></tr>';
		}
		$group = null;
		foreach ( $agg['rows'] as $row ) {
			if ( 'attr' === $f['dim'] ) {
				$g = (string) strtok( $row['key'], ':' );
				if ( $g !== $group ) {
					$group = $g;
					echo '<tr class="pc-cr-group"><th colspan="' . esc_attr( (string) ( count( self::COLUMNS ) + 1 ) ) . '">' . esc_html( Board::ATTR_LABELS[ $g ] ?? $g ) . '</th></tr>';
				}
			}
			self::render_row( $row, false );
		}
		self::render_row( $total, true );
		echo '</tbody></table></div>';
		echo '<p class="pc-cr-muted">有效访客少于 ' . esc_html( (string) Board::MIN_VALID ) . ' 的行置灰并标“样本不足”。比率的分母为 0 时显示“—”。加购率、进结账率按有效访客算；到达率 = 进站访客 ÷ 链接点击。点行名展开访客，可打开访客档案。</p>';
	}

	private static function render_row( array $row, bool $is_total ): void {
		$cls = $is_total ? 'pc-cr-total' : ( $row['insufficient'] ? 'pc-cr-thin' : '' );
		echo '<tr class="' . esc_attr( $cls ) . '"><td>';
		$thumb = (string) ( $row['meta']['thumb'] ?? '' );
		if ( '' !== $thumb ) {
			echo '<img class="pc-cr-thumb" src="' . esc_url( $thumb ) . '" alt="">';
		}
		$label = esc_html( (string) $row['label'] );
		$attrs = (array) ( $row['meta']['attrs'] ?? array() );
		if ( $attrs && ! $is_total ) {
			// 按单个素材：编号后面带上这个素材的属性（钩子 · 形式 · 卖点 · 语言），不用回素材库查。
			static $names = null;
			$names = $names ?? Creatives::label_map( Schema::vocab() );
			$parts = array();
			foreach ( array_merge( Contract::REQUIRED_ATTRS, array( 'lang' ) ) as $k ) {
				if ( '' !== (string) ( $attrs[ $k ] ?? '' ) ) {
					$parts[] = $names[ $k ][ $attrs[ $k ] ] ?? $attrs[ $k ];
				}
			}
			$label .= $parts ? '<span class="pc-cr-attrs-inline">' . esc_html( implode( ' · ', $parts ) ) . '</span>' : '';
		}
		if ( $row['insufficient'] && ! $is_total ) {
			$label .= '<span class="pc-cr-tag">样本不足</span>';
		}
		$ids = (array) ( $row['visitor_ids'] ?? array() );
		if ( $ids && ! $is_total ) {
			echo '<details><summary>' . $label . '</summary><ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 上面已转义。
			foreach ( $ids as $id ) {
				echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=pc-visitors&visitor=' . (int) $id ) ) . '">访客 #' . esc_html( (string) (int) $id ) . '</a></li>';
			}
			echo '</ul>';
			if ( count( $ids ) >= Board::DRILL_LIMIT ) {
				echo '<p class="pc-cr-muted">只列出前 ' . esc_html( (string) Board::DRILL_LIMIT ) . ' 位。</p>';
			}
			echo '</details>';
		} else {
			echo $label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 上面已转义。
		}
		echo '</td>';
		foreach ( self::COLUMNS as $key => $col ) {
			$key_metric = in_array( $key, array( 'spend_cents', 'orders', 'sales_cents', 'roas' ), true );
			echo '<td data-label="' . esc_attr( $col[0] ) . '"' . ( $key_metric ? ' class="pc-cr-key"' : '' ) . '>' . esc_html( self::format( $row[ $key ] ?? null, $col[1] ) ) . '</td>';
		}
		echo '</tr>';
	}

	private static function render_exports(): void {
		echo '<div class="pc-cr-section" id="pc-cr-exports"><h2>检查点导出</h2>';
		echo '<p class="pc-cr-muted">累计付费访客到 ' . esc_html( implode( ' / ', Export::ALL ) ) . '，以及每个站点版本满 ' . esc_html( implode( '、', Export::PER_VERSION ) ) . ' 时自动生成（每小时检查一次），内容是给“人群与素材分析师”的输入：人群分布、意向分层、按素材、按属性、版本日志，全量 / 本版本 / 上一版本三套数据，口径为首次来源。</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pc-cr-filters">';
		echo '<input type="hidden" name="action" value="' . esc_attr( Module::ACTION_EXPORT ) . '"><input type="hidden" name="op" value="manual">';
		wp_nonce_field( 'pc_creative_export_manual' );
		$tz = Board::timezone();
		echo '<label>开始<input type="date" name="from" required value="' . esc_attr( Board::day( ( time() - 29 * 86400 ) * 1000, $tz ) ) . '"></label>';
		echo '<label>结束<input type="date" name="to" required value="' . esc_attr( Board::day( time() * 1000, $tz ) ) . '"></label>';
		echo '<button class="button">按日期导出</button></form>';
		$list = Export::index();
		if ( ! $list ) {
			echo '<p>还没有导出。</p></div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>生成时间</th><th>类型</th><th>说明</th><th>站点版本</th><th>数据</th><th>下载</th></tr></thead><tbody>';
		foreach ( $list as $e ) {
			$link = static fn( string $fmt ): string => wp_nonce_url( admin_url( 'admin-post.php?action=' . Module::ACTION_EXPORT . '&op=download&id=' . rawurlencode( $e['id'] ) . '&fmt=' . $fmt ), 'pc_creative_export_dl' );
			$sets = array_map( static fn( $n ) => array( 'all' => '全量', 'current_version' => '本版本', 'previous_version' => '上一版本', 'range' => '所选日期' )[ $n ] ?? $n, (array) ( $e['datasets'] ?? array() ) );
			echo '<tr><td>' . esc_html( wp_date( 'Y-m-d H:i', (int) $e['created'] ) ) . '</td><td>' . esc_html( 'checkpoint' === $e['kind'] ? '检查点' : '手动' ) . '</td><td>' . esc_html( (string) $e['label'] ) . '</td><td>' . esc_html( '' !== (string) ( $e['version'] ?? '' ) ? (string) $e['version'] : '—' ) . '</td><td>' . esc_html( implode( '、', $sets ) ) . '</td>';
			echo '<td><a href="' . esc_url( $link( 'json' ) ) . '">JSON</a> · <a href="' . esc_url( $link( 'csv' ) ) . '">CSV</a></td></tr>';
		}
		echo '</tbody></table></div>';
	}

	// ---------- 新样式（2026-11-13）：总数 + 每个素材一张卡片（关键数字、漏斗、建议），点开看全部指标和访客 ----------

	/** 卡片里访客名单用：每位访客的下单金额和客户姓名（订单上的账单姓名，没有就用账号显示名）。 */
	private static array $people = array( 'amounts' => array(), 'names' => array() );

	private static function load_people( array $data ): void {
		$visits  = (array) ( $data['visits'] ?? array() );
		$amounts = Board::visitor_amounts( $visits, (array) ( $data['orders'] ?? array() ) );
		$names   = array();
		foreach ( $visits as $v ) {
			$vid = (int) ( $v['visitor_id'] ?? 0 );
			$oid = (int) ( $v['order_id'] ?? 0 );
			if ( $vid <= 0 || $oid <= 0 || isset( $names[ $vid ] ) || ! function_exists( 'wc_get_order' ) ) {
				continue;
			}
			$order = wc_get_order( $oid );
			if ( $order instanceof \WC_Order ) {
				$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
				if ( '' !== $name ) {
					$names[ $vid ] = $name;
				}
			}
		}
		// 登录过但没下单的访客：用账号显示名。
		$rest = array_diff( array_unique( array_map( static fn( $v ) => (int) ( $v['visitor_id'] ?? 0 ), $visits ) ), array_keys( $names ), array( 0 ) );
		if ( $rest && class_exists( \PeptideCore\Tracking\Schema::class ) ) {
			global $wpdb;
			$in = implode( ',', array_map( 'intval', $rest ) );
			foreach ( (array) $wpdb->get_results( 'SELECT id, user_id FROM ' . \PeptideCore\Tracking\Schema::table( 'visitors' ) . " WHERE user_id > 0 AND id IN ({$in})", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
				$user = get_userdata( (int) $r['user_id'] );
				if ( $user && '' !== trim( (string) $user->display_name ) ) {
					$names[ (int) $r['id'] ] = (string) $user->display_name;
				}
			}
		}
		self::$people = array( 'amounts' => $amounts, 'names' => $names );
	}

	private static function render_cards( array $agg, array $f, array $settings, array $data = array() ): void {
		self::load_people( $data );
		$total = $agg['total'];
		$rules = self::rules( $settings );
		$money = static fn( $c ): string => self::format( $c, 'money' );
		echo '<div class="pc-cr-kpis">';
		foreach ( array(
			array( '花费', $money( $total['spend_cents'] ) ),
			array( '销售额', $money( $total['sales_cents'] ) ),
			array( 'ROAS', self::format( $total['roas'], 'x' ) ),
			array( '订单', (string) (int) $total['orders'] ),
			array( '付费访客', (int) $total['visitors'] . '（有效 ' . (int) $total['valid'] . '）' ),
		) as $k ) {
			echo '<div class="pc-cr-kpi"><span>' . esc_html( $k[0] ) . '</span><b>' . esc_html( $k[1] ) . '</b></div>';
		}
		echo '</div>';
		echo '<p class="pc-cr-muted">' . esc_html( sprintf( '%s ~ %s · %s · %s · 意向：高 %d / 中 %d / 低 %d / 无效 %d · 没带参数的 FB/IG 访客 %d', $f['from'], $f['to'], Board::MODES[ $f['mode'] ], Board::DIMS[ $f['dim'] ], $total['intent']['high'], $total['intent']['mid'], $total['intent']['low'], $total['intent']['invalid'], $agg['untagged'] ) ) . '</p>';

		$rows   = array_values( $agg['rows'] );
		$matrix = array();
		if ( 'attr' === $f['dim'] ) {
			list( $rows, $matrix ) = Board::split_matrix( $rows );
		}
		$counts = array_fill_keys( array_keys( Board::VERDICTS ), 0 );
		foreach ( $rows as $i => $row ) {
			$rows[ $i ]['verdict'] = Board::verdict( $row, $rules );
			++$counts[ $rows[ $i ]['verdict'] ];
		}
		// 排序：按属性时在每组里排，其他维度整体排。
		if ( 'attr' === $f['dim'] ) {
			$groups = array();
			foreach ( $rows as $row ) {
				$groups[ (string) strtok( $row['key'], ':' ) ][] = $row;
			}
			$rows = array();
			foreach ( $groups as $g ) {
				$rows = array_merge( $rows, Board::sort_rows( $g, $f['sort'] ) );
			}
		} else {
			$rows = Board::sort_rows( $rows, $f['sort'] );
		}

		self::render_decisions( $rows, $f, (array) ( $data['ads'] ?? array() ) );
		self::render_quadrant( $rows, $rules, $f );
		if ( 'attr' === $f['dim'] ) {
			self::render_matrix( $matrix, $rules );
		}
		echo '<h2 class="pc-cr-h2">' . esc_html( Board::DIMS[ $f['dim'] ] ) . '（' . count( $rows ) . '）</h2>';
		echo '<div class="pc-cr-toolbar"><span class="pc-cr-sorts">';
		foreach ( Board::SORTS as $k => $label ) {
			$url = add_query_arg( array( 'page' => Module::PAGE_BOARD, 'dim' => $f['dim'], 'mode' => $f['mode'], 'from' => $f['from'], 'to' => $f['to'], 'ver' => $f['ver'], 'sort' => $k ), admin_url( 'admin.php' ) );
			echo $k === $f['sort'] ? '<b>' . esc_html( $label ) . '</b>' : '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '<button type="button" class="button-link pc-cr-expand" data-pc-cr-expand>展开全部详细数据</button>';
		echo '</span><span class="pc-cr-counts">';
		foreach ( Board::VERDICTS as $k => $label ) {
			if ( $counts[ $k ] ) {
				echo '<span class="pc-cr-badge is-' . esc_attr( $k ) . '">' . esc_html( $label . ' ' . $counts[ $k ] ) . '</span>';
			}
		}
		echo '</span></div>';

		if ( ! $rows ) {
			echo '<p>这段时间没有付费访客，也没有导入花费。</p>';
		}
		$group = null;
		foreach ( $rows as $row ) {
			if ( 'attr' === $f['dim'] ) {
				$g = (string) strtok( $row['key'], ':' );
				if ( $g !== $group ) {
					$group = $g;
					echo '<h3 class="pc-cr-group-title">' . esc_html( Board::ATTR_LABELS[ $g ] ?? $g ) . '</h3>';
				}
			}
			self::render_card( $row, $rules );
		}
		self::cards_script();
		echo '<p class="pc-cr-muted">建议：ROAS ≥ ' . esc_html( self::num( $rules['scale_roas'], 1 ) ) . ' 加投，&lt; ' . esc_html( self::num( $rules['stop_roas'], 1 ) ) . ' 建议停，花费 ≥ ' . esc_html( $money( $rules['stop_spend_cents'] ) ) . ' 还没订单也建议停；有效访客不到 ' . esc_html( (string) Board::MIN_VALID ) . ' 显示样本不足。规则在下面“设置”里改。漏斗按这一行的进站访客算比例，红色是丢人最多的一步。点卡片看全部指标和访客名单。</p>';
	}

	/** “展开全部详细数据”按钮。 */
	private static function cards_script(): void {
		?>
<script>
( function () {
	document.addEventListener( 'click', function ( e ) {
		var c = e.target.closest( '[data-pc-cr-copy-ids]' );
		if ( c ) {
			var text = c.getAttribute( 'data-pc-cr-copy-ids' ), label = c.textContent;
			var done = function () { c.textContent = '已复制，到 Meta 广告管理里粘贴搜索'; setTimeout( function () { c.textContent = label; }, 2500 ); };
			if ( navigator.clipboard && window.isSecureContext ) { navigator.clipboard.writeText( text ).then( done ); }
			else { var t = document.createElement( 'textarea' ); t.value = text; document.body.appendChild( t ); t.select(); try { document.execCommand( 'copy' ); done(); } catch ( err ) {} t.remove(); }
			return;
		}
		var b = e.target.closest( '[data-pc-cr-expand]' );
		if ( ! b ) { return; }
		var shut = '1' !== b.getAttribute( 'data-open' );
		document.querySelectorAll( 'details.pc-cr-row' ).forEach( function ( d ) { d.open = shut; } );
		b.setAttribute( 'data-open', shut ? '1' : '0' );
		b.textContent = shut ? '收起全部' : '展开全部详细数据';
	} );
} )();
</script>
		<?php
	}

	/** 今天要做的事（2026-11-13）：只在能直接操作的维度显示（素材、广告、广告组）。 */
	private static function handled_form( string $op, string $key, string $label ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pc-cr-inline-form"><input type="hidden" name="action" value="' . esc_attr( Module::ACTION_EXPORT ) . '"><input type="hidden" name="op" value="' . esc_attr( $op ) . '"><input type="hidden" name="key" value="' . esc_attr( $key ) . '">'
			. wp_nonce_field( 'pc_creative_board_handled', '_wpnonce', true, false ) . '<button class="button button-small">' . esc_html( $label ) . '</button></form>';
	}

	private static function render_decisions( array $rows, array $f, array $ads = array() ): void {
		if ( ! in_array( $f['dim'], array( 'creative', 'ad', 'adset' ), true ) ) {
			return;
		}
		$handled = self::handled_today();
		$act_all = array_values( array_filter( $rows, static fn( $r ) => in_array( $r['verdict'], array( 'stop', 'scale' ), true ) ) );
		$done    = array_values( array_filter( $act_all, static fn( $r ) => isset( $handled[ (string) $r['key'] ] ) ) );
		$act     = array_values( array_filter( $act_all, static fn( $r ) => ! isset( $handled[ (string) $r['key'] ] ) ) );
		$watch = array_values( array_filter( $rows, static fn( $r ) => 'watch' === $r['verdict'] ) );
		$later = array_values( array_filter( $rows, static fn( $r ) => in_array( $r['verdict'], array( 'thin', 'nospend' ), true ) ) );
		// 先停后加；同类按花费从多到少。
		usort( $act, static fn( $a, $b ) => array( 'stop' === $b['verdict'] ? 1 : 0, (int) $b['spend_cents'] ) <=> array( 'stop' === $a['verdict'] ? 1 : 0, (int) $a['spend_cents'] ) );

		echo '<div class="pc-cr-today"><h2 class="pc-cr-h2">今天要做的事' . ( $act ? '（' . count( $act ) . '）' : '' ) . '</h2>';
		if ( ! $act ) {
			echo '<p class="pc-cr-muted">' . esc_html( $done ? '今天要做的都处理完了。' : '没有要马上处理的，继续跑。' ) . '</p>';
		}
		foreach ( $act as $row ) {
			$scale = 'scale' === $row['verdict'];
			$zero  = 0 === (int) $row['orders'];
			$name  = (string) $row['label'];
			$title = $scale ? '给 ' . $name . ' 加预算' : ( $zero ? '停掉 ' . $name : '停掉或换掉 ' . $name );
			$why   = $scale
				? 'ROAS ' . self::format( $row['roas'], 'x' ) . '，有效访客 ' . (int) $row['valid'] . ' 人，样本够了。'
				: ( $zero ? '花了 ' . self::format( $row['spend_cents'], 'money' ) . '，一单没有。' : 'ROAS ' . self::format( $row['roas'], 'x' ) . '，赚不回广告费。' );
			echo '<div class="pc-cr-todo is-' . esc_attr( $row['verdict'] ) . '">';
			echo ( '' !== (string) ( $row['meta']['thumb'] ?? '' ) ? '<img class="pc-cr-thumb" src="' . esc_url( (string) $row['meta']['thumb'] ) . '" alt="">' : '<span class="pc-cr-thumb is-blank" aria-hidden="true"></span>' );
			echo '<div><p class="pc-cr-todo-title">' . esc_html( $title ) . '</p>';
			echo '<p class="pc-cr-todo-why">' . esc_html( $why . Board::reason( $row ) ) . '</p>';
			echo '<p class="pc-cr-todo-nums">' . esc_html( '花费 ' . self::format( $row['spend_cents'], 'money' ) . ' · 订单 ' . (int) $row['orders'] . ' · 销售额 ' . self::format( $row['sales_cents'], 'money' ) . ' · 进站 ' . (int) $row['visitors'] ) . '</p>';
			$ids = Board::ad_ids_for( $f['dim'], (string) $row['key'], $ads );
			echo '<p class="pc-cr-todo-actions">';
			if ( $ids ) {
				echo '<button type="button" class="button button-small" data-pc-cr-copy-ids="' . esc_attr( implode( ',', $ids ) ) . '">' . esc_html( '复制广告编号（' . count( $ids ) . ' 条）' ) . '</button> ';
			}
			echo self::handled_form( 'handled', (string) $row['key'], '标记已处理' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- handled_form() 里已转义。
			echo '</p>';
			echo '</div><span class="pc-cr-badge is-' . esc_attr( $row['verdict'] ) . '">' . esc_html( Board::VERDICTS[ $row['verdict'] ] ) . '</span></div>';
		}
		if ( $done ) {
			echo '<details class="pc-cr-done"><summary>' . esc_html( '今天已处理（' . count( $done ) . '）' ) . '</summary><ul>';
			foreach ( $done as $row ) {
				echo '<li><b>' . esc_html( (string) $row['label'] ) . '</b> ' . esc_html( Board::VERDICTS[ $row['verdict'] ] ) . ' · ' . esc_html( wp_date( 'H:i', (int) $handled[ (string) $row['key'] ] ) ) . ' ' . self::handled_form( 'unhandle', (string) $row['key'], '撤销' ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- handled_form() 里已转义。
			}
			echo '</ul></details>';
		}
		if ( $watch || $later ) {
			echo '<h3 class="pc-cr-h3">再等等（' . ( count( $watch ) + count( $later ) ) . '）</h3><ul class="pc-cr-waits">';
			foreach ( $watch as $row ) {
				echo '<li><b>' . esc_html( (string) $row['label'] ) . '</b> ROAS ' . esc_html( self::format( $row['roas'], 'x' ) ) . ' 在观察区间，有效访客 ' . esc_html( (string) (int) $row['valid'] ) . ' 人</li>';
			}
			foreach ( $later as $row ) {
				$note = 'nospend' === $row['verdict'] ? '还没导入花费，算不出 ROAS' : '有效访客 ' . (int) $row['valid'] . ' 人，还差 ' . max( 0, Board::MIN_VALID - (int) $row['valid'] ) . ' 人才能下结论';
				echo '<li><b>' . esc_html( (string) $row['label'] ) . '</b> ' . esc_html( $note ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	/** 花费与回报象限图（2026-11-13）：横轴花费、纵轴 ROAS、圆大小 = 订单数。 */
	private static function render_quadrant( array $rows, array $rules, array $f ): void {
		$pts = array_values( array_filter( $rows, static fn( $r ) => null !== $r['spend_cents'] && (int) $r['spend_cents'] > 0 && null !== $r['roas'] ) );
		if ( count( $pts ) < 2 ) {
			return;
		}
		$max_spend = 0;
		$max_roas  = (float) $rules['scale_roas'] * 1.3;
		foreach ( $pts as $r ) {
			$max_spend = max( $max_spend, (int) $r['spend_cents'] );
			$max_roas  = max( $max_roas, (float) $r['roas'] * 1.15 );
		}
		$max_spend = max( 1, $max_spend ) * 1.12;
		$x0 = 58.0;
		$x1 = 612.0;
		$y0 = 26.0;
		$y1 = 268.0;
		$px = static fn( $cents ): float => $x0 + ( $cents / $max_spend ) * ( $x1 - $x0 );
		$py = static fn( $roas ): float => $y1 - ( min( $roas, $max_roas ) / $max_roas ) * ( $y1 - $y0 );
		$fill = array( 'scale' => '#1D9E75', 'watch' => '#BA7517', 'stop' => '#E24B4A', 'thin' => '#888780', 'nospend' => '#888780' );
		$y_scale = $py( (float) $rules['scale_roas'] );
		$y_stop  = $py( (float) $rules['stop_roas'] );

		echo '<div class="pc-cr-quadrant"><h2 class="pc-cr-h2">花费与回报</h2>';
		echo '<p class="pc-cr-muted">圆越大订单越多；空心 = 还没有订单。横轴花费，纵轴 ROAS，虚线是“设置”里的两条阈值线。</p>';
		echo '<svg viewBox="0 0 680 300" role="img" aria-label="花费与 ROAS 象限图">';
		echo '<rect x="' . $x0 . '" y="' . $y0 . '" width="' . ( $x1 - $x0 ) . '" height="' . max( 0, $y_scale - $y0 ) . '" fill="#e1f5ee"/>';
		echo '<rect x="' . $x0 . '" y="' . $y_stop . '" width="' . ( $x1 - $x0 ) . '" height="' . max( 0, $y1 - $y_stop ) . '" fill="#fcebeb"/>';
		echo '<line x1="' . $x0 . '" y1="' . $y_scale . '" x2="' . $x1 . '" y2="' . $y_scale . '" stroke="#1d9e75" stroke-dasharray="4 4"/>';
		echo '<line x1="' . $x0 . '" y1="' . $y_stop . '" x2="' . $x1 . '" y2="' . $y_stop . '" stroke="#e24b4a" stroke-dasharray="4 4"/>';
		echo '<line x1="' . $x0 . '" y1="' . $y0 . '" x2="' . $x0 . '" y2="' . $y1 . '" stroke="#c3c4c7"/><line x1="' . $x0 . '" y1="' . $y1 . '" x2="' . $x1 . '" y2="' . $y1 . '" stroke="#c3c4c7"/>';
		echo '<text x="' . ( $x1 + 6 ) . '" y="' . ( $y_scale - 6 ) . '" font-size="11" fill="#0f6e56">加投区</text>';
		echo '<text x="' . ( $x1 + 6 ) . '" y="' . ( $y_stop + 14 ) . '" font-size="11" fill="#a32d2d">止损区</text>';
		echo '<text x="' . ( $x0 - 6 ) . '" y="' . ( $y_scale + 4 ) . '" font-size="11" text-anchor="end" fill="#0f6e56">' . esc_html( self::num( (float) $rules['scale_roas'], 1 ) ) . '</text>';
		echo '<text x="' . ( $x0 - 6 ) . '" y="' . ( $y_stop + 4 ) . '" font-size="11" text-anchor="end" fill="#a32d2d">' . esc_html( self::num( (float) $rules['stop_roas'], 1 ) ) . '</text>';
		echo '<text x="' . ( $x0 - 6 ) . '" y="' . ( $y1 + 4 ) . '" font-size="11" text-anchor="end" fill="#8c8f94">0</text>';
		echo '<text x="' . ( $x0 - 40 ) . '" y="' . ( ( $y0 + $y1 ) / 2 ) . '" font-size="11" fill="#646970" transform="rotate(-90 ' . ( $x0 - 40 ) . ' ' . ( ( $y0 + $y1 ) / 2 ) . ')" text-anchor="middle">ROAS</text>';
		echo '<text x="' . $x0 . '" y="' . ( $y1 + 20 ) . '" font-size="11" fill="#8c8f94">$0</text>';
		echo '<text x="' . $x1 . '" y="' . ( $y1 + 20 ) . '" font-size="11" fill="#8c8f94" text-anchor="end">' . esc_html( self::format( (int) round( $max_spend ), 'money' ) ) . '　花费 →</text>';
		foreach ( $pts as $r ) {
			$cx     = $px( (int) $r['spend_cents'] );
			$cy     = $py( (float) $r['roas'] );
			$orders = (int) $r['orders'];
			$rad    = min( 26.0, 7.0 + sqrt( max( 0, $orders ) ) * 4.0 );
			$color  = $fill[ $r['verdict'] ] ?? '#888780';
			$name   = (string) ( $r['meta']['code'] ?? $r['label'] );
			echo '<g><title>' . esc_html( $name . '：花费 ' . self::format( $r['spend_cents'], 'money' ) . ' · ROAS ' . self::format( $r['roas'], 'x' ) . ' · ' . $orders . ' 单' ) . '</title>';
			if ( $orders > 0 ) {
				echo '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $rad . '" fill="' . $color . '" fill-opacity="0.85"/>';
			} else {
				echo '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $rad . '" fill="none" stroke="' . $color . '" stroke-width="2"/>';
			}
			echo '<text x="' . $cx . '" y="' . ( $cy - $rad - 6 ) . '" font-size="11" text-anchor="middle" fill="#50575e">' . esc_html( mb_substr( $name, 0, 10 ) ) . '</text></g>';
		}
		echo '</svg></div>';
	}

	/** 钩子 × 卖点热力格（2026-11-13，只在“按素材属性”显示）：哪种组合好卖、哪些还没试过。 */
	private static function render_matrix( array $matrix, array $rules ): void {
		$vocab  = Schema::vocab();
		$labels = Creatives::label_map( $vocab );
		$hooks  = (array) ( $labels['hook'] ?? array() );
		$angles = (array) ( $labels['angle'] ?? array() );
		if ( ! $hooks || ! $angles ) {
			return;
		}
		$cells = array();
		foreach ( $matrix as $row ) {
			$cells[ substr( (string) $row['key'], strlen( Board::MATRIX_PREFIX ) ) ] = $row;
		}
		$tested = count( $cells );
		echo '<div class="pc-cr-matrix"><h2 class="pc-cr-h2">哪种组合好卖</h2>';
		echo '<p class="pc-cr-muted">' . esc_html( '钩子 × 卖点，' . ( count( $hooks ) * count( $angles ) ) . ' 种组合里试过 ' . $tested . ' 种。格子里是 ROAS 和进站访客。' ) . '</p>';
		echo '<div class="pc-cr-grid-wrap"><table class="pc-cr-grid"><thead><tr><th></th>';
		foreach ( $angles as $label ) {
			echo '<th>' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $hooks as $hv => $hl ) {
			echo '<tr><th scope="row">' . esc_html( $hl ) . '</th>';
			foreach ( $angles as $av => $al ) {
				$cell = $cells[ $hv . '|' . $av ] ?? null;
				if ( ! $cell ) {
					echo '<td class="is-none"><span>未测</span></td>';
					continue;
				}
				$roas = $cell['roas'];
				$cls  = null === $roas ? 'is-nospend' : ( $roas >= (float) $rules['scale_roas'] ? 'is-good' : ( $roas < (float) $rules['stop_roas'] ? 'is-bad' : 'is-mid' ) );
				echo '<td class="' . esc_attr( $cls ) . ( ! empty( $cell['insufficient'] ) ? ' is-thin' : '' ) . '"><b>' . esc_html( null === $roas ? '待花费' : self::format( $roas, 'x' ) ) . '</b><span>' . esc_html( '访客 ' . (int) $cell['visitors'] . ( ! empty( $cell['insufficient'] ) ? ' · 样本不足' : '' ) ) . '</span></td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';
		echo '<p class="pc-cr-muted">灰格 = 这个组合还没有素材，可以作为下一批拍摄的方向。</p></div>';
	}

	private static function render_card( array $row, array $rules ): void {
		$v      = (string) $row['verdict'];
		$attrs  = (array) ( $row['meta']['attrs'] ?? array() );
		$thumb  = (string) ( $row['meta']['thumb'] ?? '' );
		$attr_t = '';
		if ( $attrs ) {
			static $names = null;
			$names = $names ?? Creatives::label_map( Schema::vocab() );
			$parts = array();
			foreach ( array_merge( Contract::REQUIRED_ATTRS, array( 'lang' ) ) as $k ) {
				if ( '' !== (string) ( $attrs[ $k ] ?? '' ) ) {
					$parts[] = $names[ $k ][ $attrs[ $k ] ] ?? $attrs[ $k ];
				}
			}
			$attr_t = implode( ' · ', $parts );
		}
		$roas_cls = 'scale' === $v ? 'is-good' : ( 'stop' === $v ? 'is-bad' : '' );
		echo '<details class="pc-cr-row is-' . esc_attr( $v ) . '"><summary>';
		echo '<div class="pc-cr-row-head">' . ( '' !== $thumb ? '<img class="pc-cr-thumb" src="' . esc_url( $thumb ) . '" alt="">' : '' );
		echo '<div class="pc-cr-row-name"><b>' . esc_html( (string) $row['label'] ) . '</b>' . ( '' !== $attr_t ? '<span>' . esc_html( $attr_t ) . '</span>' : '' ) . '</div>';
		echo '<span class="pc-cr-badge is-' . esc_attr( $v ) . '">' . esc_html( Board::VERDICTS[ $v ] ?? $v ) . '</span>';
		echo '<span class="pc-cr-toggle"><b class="is-shut">详细数据</b><b class="is-open">收起</b></span></div>';
		echo '<div class="pc-cr-row-keys">';
		foreach ( array( array( '花费', self::format( $row['spend_cents'], 'money' ), '' ), array( '订单', (string) (int) $row['orders'], '' ), array( '销售额', self::format( $row['sales_cents'], 'money' ), '' ), array( 'ROAS', self::format( $row['roas'], 'x' ), $roas_cls ) ) as $k ) {
			echo '<span>' . esc_html( $k[0] ) . ' <b class="' . esc_attr( $k[2] ) . '">' . esc_html( $k[1] ) . '</b></span>';
		}
		echo '</div>';
		// 漏斗：进站 → 中高意向 → 加购 → 结账 → 成交，宽度按进站访客算。
		$steps = array( array( '进站', (int) $row['visitors'] ), array( '中高意向', (int) $row['intent']['high'] + (int) $row['intent']['mid'] ), array( '加购', (int) $row['carted'] ), array( '结账', (int) $row['checkout'] ), array( '成交', (int) $row['orders'] ) );
		$base   = max( 1, (int) $row['visitors'] );
		$worst  = -1;
		$drop   = 0.0;
		for ( $i = 1; $i < count( $steps ); $i++ ) {
			// 按“这一步丢掉的人占进站访客的比例”找最大的一步（小数字的相对比例会误导，比如 1 → 0）。
			$loss = ( $steps[ $i - 1 ][1] - $steps[ $i ][1] ) / $base;
			if ( $loss > $drop ) {
				$drop  = $loss;
				$worst = $i;
			}
		}
		echo '<div class="pc-cr-funnel">';
		foreach ( $steps as $i => $s ) {
			$pct = (int) round( 100 * $s[1] / $base );
			echo '<div class="pc-cr-step' . ( $i === $worst ? ' is-drop' : '' ) . '" title="' . esc_attr( $s[0] . ' ' . $s[1] . '（' . $pct . '%）' ) . '"><span>' . esc_html( $s[0] ) . ' <b>' . esc_html( (string) $s[1] ) . '</b>' . ( $i > 0 ? ' <em>' . esc_html( $pct . '%' ) . '</em>' : '' ) . '</span><i style="width:' . esc_attr( (string) max( 0, min( 100, $pct ) ) ) . '%"></i></div>';
		}
		echo '</div></summary><div class="pc-cr-row-body"><div class="pc-cr-all">';
		foreach ( self::COLUMNS as $key => $col ) {
			echo '<div><span>' . esc_html( $col[0] ) . '</span><b>' . esc_html( self::format( $row[ $key ] ?? null, $col[1] ) ) . '</b></div>';
		}
		echo '</div>';
		$ids = (array) ( $row['visitor_ids'] ?? array() );
		if ( $ids ) {
			// 2026-11-13：按下单金额从高到低；有姓名的显示客户姓名。
			$people = Board::rank_visitors( $ids, self::$people['amounts'], self::$people['names'] );
			$buyers = count( array_filter( $people, static fn( $p ) => $p['cents'] > 0 ) );
			$all    = count( array_unique( array_map( 'intval', $ids ) ) );
			echo '<p class="pc-cr-muted">' . esc_html( '访客 ' . $all . ' 位，其中 ' . $buyers . ' 位下单，按下单金额从高到低（点进去看机型、IP、浏览路径）：' ) . '</p><ul class="pc-cr-visitors">';
			foreach ( $people as $p ) {
				$label = '' !== $p['name'] ? $p['name'] : '访客 #' . $p['id'];
				echo '<li' . ( $p['cents'] > 0 ? ' class="is-buyer"' : '' ) . '><a href="' . esc_url( admin_url( 'admin.php?page=pc-visitors&visitor=' . (int) $p['id'] ) ) . '">' . esc_html( $label ) . '</a>' . ( $p['cents'] > 0 ? ' <b>' . esc_html( self::format( $p['cents'], 'money' ) ) . '</b>' : '' ) . '</li>';
			}
			echo '</ul>';
			if ( $all > Board::DRILL_LIMIT ) {
				echo '<p class="pc-cr-muted">只列出前 ' . esc_html( (string) Board::DRILL_LIMIT ) . ' 位。</p>';
			}
		}
		echo '</div></details>';
	}

	private static function render_settings( array $settings, array $versions ): void {
		echo '<div class="pc-cr-section"><h2>设置</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( Module::ACTION_EXPORT ) . '"><input type="hidden" name="op" value="settings">';
		wp_nonce_field( 'pc_creative_board_settings' );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="pc-cr-pct">没带参数的访客占比提醒线（%）</label></th><td><input id="pc-cr-pct" type="number" name="untagged_pct" min="0" max="100" step="0.1" value="' . esc_attr( self::num( $settings['untagged_pct'], 1 ) ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="pc-cr-clicks">疑似漏参：当天链接点击至少</label></th><td><input id="pc-cr-clicks" type="number" name="min_clicks" min="1" max="100000" value="' . esc_attr( (string) $settings['min_clicks'] ) . '"></td></tr>';
		echo '<tr><th scope="row">建议标签规则</th><td><label>ROAS ≥ <input type="number" name="scale_roas" min="0" max="100" step="0.1" value="' . esc_attr( self::num( $settings['scale_roas'], 1 ) ) . '" style="width:70px"> 显示“加投”</label>；<label>ROAS &lt; <input type="number" name="stop_roas" min="0" max="100" step="0.1" value="' . esc_attr( self::num( $settings['stop_roas'], 1 ) ) . '" style="width:70px"> 显示“建议停”</label>；<label>花费 ≥ $<input type="number" name="stop_spend" min="0" max="100000" step="1" value="' . esc_attr( self::num( $settings['stop_spend'], 0 ) ) . '" style="width:80px"> 还没有订单也显示“建议停”</label><p class="description">中间的显示“观察”；有效访客不到 ' . esc_html( (string) Board::MIN_VALID ) . ' 时显示“样本不足”。只是提示，不会自动改广告。</p></td></tr>';
		echo '<tr><th scope="row"><label for="pc-cr-ver">当前站点版本号</label></th><td><input id="pc-cr-ver" type="text" name="version" maxlength="32" placeholder="例如 v1" value="' . esc_attr( self::current_version() ) . '"><p class="description">改成新的版本号 = 从保存这一刻起算新版本（改了页面、价格、落地页等之后填）。用于按版本筛选和“每个版本满 500、1000 访客”的导出。</p></td></tr>';
		echo '</tbody></table><button class="button button-primary">保存设置</button></form>';
		if ( $versions ) {
			echo '<h3>版本日志</h3><table class="widefat striped" style="max-width:640px"><thead><tr><th>版本号</th><th>开始时间</th><th>设置人</th></tr></thead><tbody>';
			foreach ( array_reverse( $versions ) as $v ) {
				$user = get_userdata( (int) ( $v['user'] ?? 0 ) );
				echo '<tr><td>' . esc_html( $v['ver'] ) . '</td><td>' . esc_html( wp_date( 'Y-m-d H:i', intdiv( (int) $v['since_ms'], 1000 ) ) ) . '</td><td>' . esc_html( $user ? $user->display_name : '—' ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	private static function render_report_status(): void {
		echo '<div class="pc-cr-section"><h2>每日上报总后台</h2>';
		if ( ! \PeptideCore\Order_Report::enabled() ) {
			echo '<p class="pc-cr-muted">这家店还没接入总后台，素材日报暂不上报（接上后会补报队列里的日期）。</p>';
		} else {
			echo '<p class="pc-cr-muted">每天把店铺时区前一天的按素材汇总报给总后台（口径：当天最近来源），失败会自动重试。</p>';
		}
		$queue = Daily_Report::queue();
		if ( $queue ) {
			echo '<p>' . esc_html( '待上报 ' . count( $queue ) . ' 天：' . implode( '、', array_slice( array_keys( $queue ), 0, 10 ) ) . ( count( $queue ) > 10 ? '……' : '' ) ) . '</p>';
		}
		$log = (array) get_option( Daily_Report::OPTION_LOG, array() );
		if ( $log ) {
			$names = array( 'done' => '已上报', 'retry' => '稍后重试', 'drop' => '放弃', 'dropped' => '放弃' );
			echo '<table class="widefat striped" style="max-width:760px"><thead><tr><th>日期</th><th>时间</th><th>结果</th><th>说明</th></tr></thead><tbody>';
			foreach ( array_slice( $log, 0, 10 ) as $l ) {
				echo '<tr><td>' . esc_html( (string) $l['day'] ) . '</td><td>' . esc_html( wp_date( 'm-d H:i', (int) $l['at'] ) ) . '</td><td>' . esc_html( $names[ $l['outcome'] ] ?? (string) $l['outcome'] ) . '</td><td>' . esc_html( 501 === ( $l['code'] ?? null ) ? '总后台还没开通素材日报（501），会继续重试' : (string) $l['error'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	// ---------- 格式 ----------

	/** 单元格文字。null = “—”。money 是美分。 */
	public static function format( $value, string $kind ): string {
		if ( null === $value ) {
			return '—';
		}
		switch ( $kind ) {
			case 'money':
				return '$' . number_format( (float) $value / 100, 2 );
			case 'pct':
				return self::num( (float) $value * 100, 1 ) . '%';
			case 'x':
				return self::num( (float) $value, 2 );
		}
		return number_format( (int) $value );
	}

	private static function num( float $v, int $dec ): string {
		return number_format( $v, $dec, '.', '' );
	}
}
