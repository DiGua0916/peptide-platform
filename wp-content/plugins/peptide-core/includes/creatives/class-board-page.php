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

	/** 默认看最近几天。 */
	public const DEFAULT_DAYS = 7;

	public static function register_hooks(): void {
		add_action( 'admin_post_' . Module::ACTION_EXPORT, array( self::class, 'handle' ) );
		Export::register_hooks();
		Daily_Report::register_hooks();
	}

	// ---------- 设置和版本 ----------

	/** @return array{untagged_pct:float,min_clicks:int} */
	public static function settings(): array {
		$s = get_option( self::OPTION_SETTINGS, array() );
		$s = is_array( $s ) ? $s : array();
		return array(
			'untagged_pct' => isset( $s['untagged_pct'] ) ? max( 0.0, min( 100.0, (float) $s['untagged_pct'] ) ) : (float) Board::UNTAGGED_PCT,
			'min_clicks'   => isset( $s['min_clicks'] ) ? max( 1, min( 100000, (int) $s['min_clicks'] ) ) : Board::MIN_CLICKS,
		);
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
			update_option( self::OPTION_SETTINGS, array( 'untagged_pct' => max( 0.0, min( 100.0, $pct ) ), 'min_clicks' => max( 1, min( 100000, $clicks ) ) ), false );
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
		$ver = is_string( $get['ver'] ?? null ) && self::valid_version( $get['ver'] ) ? $get['ver'] : '';
		return array( 'dim' => $dim, 'mode' => $mode, 'from' => $from, 'to' => $to, 'ver' => $ver );
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
		self::render_table( $agg, $f );
		self::render_exports();
		self::render_settings( $settings, $versions );
		self::render_report_status();
		echo '</div>';
	}

	private static function styles(): void {
		echo '<style>
.pc-cr-board .pc-cr-filters{display:flex;flex-wrap:wrap;gap:8px 16px;align-items:flex-end;margin:12px 0}
.pc-cr-board .pc-cr-filters label{display:flex;flex-direction:column;font-size:12px;color:#50575e;gap:2px}
.pc-cr-board .pc-cr-scroll{overflow-x:auto;max-width:100%}
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
			echo '<td>' . esc_html( self::format( $row[ $key ] ?? null, $col[1] ) ) . '</td>';
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

	private static function render_settings( array $settings, array $versions ): void {
		echo '<div class="pc-cr-section"><h2>设置</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( Module::ACTION_EXPORT ) . '"><input type="hidden" name="op" value="settings">';
		wp_nonce_field( 'pc_creative_board_settings' );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="pc-cr-pct">没带参数的访客占比提醒线（%）</label></th><td><input id="pc-cr-pct" type="number" name="untagged_pct" min="0" max="100" step="0.1" value="' . esc_attr( self::num( $settings['untagged_pct'], 1 ) ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="pc-cr-clicks">疑似漏参：当天链接点击至少</label></th><td><input id="pc-cr-clicks" type="number" name="min_clicks" min="1" max="100000" value="' . esc_attr( (string) $settings['min_clicks'] ) . '"></td></tr>';
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
