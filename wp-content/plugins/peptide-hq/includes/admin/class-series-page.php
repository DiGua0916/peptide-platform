<?php
/**
 * 总后台 → 系列管理（HQ-05、HQ-06、FE-07）。
 *
 * - 系列：新建、改名、选母版店；每个系列一套商品和价格、一套模板、一套活动、一套运费。
 * - 店铺：归到哪个系列、投手是谁；首次同步前生成差异报告，平台所有者确认后才开启这家店的系列同步；
 *   可以关闭同步、立即同步、恢复到同步前的快照。
 * - 主商品库：从母版店导入；直接改价格，保存后版本号加一并推送给同系列的店，别的系列不受影响。
 * - 运费：$15 / $45 两档（门槛和金额可改），保存后随系列下发，新店自动带上。
 * - 模板：切到这个系列后去“模板库”装修和下发。
 * - 只给总后台管理员；写操作校验 nonce。
 *
 * @package PeptideHq
 */

namespace PeptideHq\Admin;

use PeptideHq\Design_Push;
use PeptideHq\Series;
use PeptideHq\Series_Campaigns;
use PeptideHq\Series_Catalog;
use PeptideHq\Series_Push;
use PeptideHq\Sites;

defined( 'ABSPATH' ) || exit;

final class Series_Page {

	public const SLUG = 'pchq-series';

	private const ACTIONS = array(
		'pchq_series_create',
		'pchq_series_update',
		'pchq_series_site',
		'pchq_series_import',
		'pchq_series_price',
		'pchq_series_product',
		'pchq_series_shipping',
		'pchq_series_diff',
		'pchq_series_confirm',
		'pchq_series_sync_off',
		'pchq_series_push',
		'pchq_series_restore',
		'pchq_series_design',
	);

	public static function hooks(): void {
		add_action(
			'admin_menu',
			static function (): void {
				add_submenu_page( 'pchq', '系列管理', '系列管理', Menu::CAP, self::SLUG, array( self::class, 'render' ) );
			},
			20
		);
		foreach ( self::ACTIONS as $action ) {
			add_action( 'admin_post_' . $action, array( self::class, 'handle' ) );
		}
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ), 1001 );
	}

	public static function assets(): void {
		if ( self::SLUG !== ( $_GET['page'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$dir = dirname( PEPTIDE_HQ_FILE ) . '/assets/';
		wp_enqueue_style( 'pchq-plugins', plugins_url( 'assets/hq-plugins.css', PEPTIDE_HQ_FILE ), array( 'peptide-hq-workspace' ), PEPTIDE_HQ_VERSION . '.' . (int) @filemtime( $dir . 'hq-plugins.css' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_enqueue_script( 'pchq-plugins', plugins_url( 'assets/hq-plugins.js', PEPTIDE_HQ_FILE ), array(), PEPTIDE_HQ_VERSION . '.' . (int) @filemtime( $dir . 'hq-plugins.js' ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- 表单上的 data-confirm 确认框由它处理。
		wp_add_inline_style(
			'pchq-plugins',
			'.pchq-s-tabs{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 16px}.pchq-s-form{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin:0 0 12px}.pchq-s-form label{display:flex;flex-direction:column;gap:4px;font-size:12px;color:#555}.pchq-s-form input[type=text],.pchq-s-form select{min-height:34px}'
			. '.pchq-s-actions{display:flex;flex-wrap:wrap;gap:6px}.pchq-s-actions form{margin:0}.pchq-s-num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}.pchq-s-muted{color:#777}.pchq-s-bad{color:#b32d2e}'
			. '.pchq-s-price{width:90px}.pchq-s-vars{width:100%;border-collapse:collapse}.pchq-s-vars td{padding:3px 8px 3px 0;border:0}.pchq-s-removed td{opacity:.55}.pchq-s-report td{vertical-align:top}'
		);
	}

	private static function flash( string $type, string $message ): void {
		set_transient( 'pchq_series_flash_' . get_current_user_id(), array( $type, $message ), 120 );
	}

	private static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	private static function site_name( array $site ): string {
		return '' !== (string) $site['name'] ? (string) $site['name'] : (string) wp_parse_url( (string) $site['url'], PHP_URL_HOST );
	}

	/** 金额输入（"12.5"、"$1,234.56"）转美分；留空是 0；不是有效金额时返回 -1。 */
	public static function cents( string $input ): int {
		$s = str_replace( array( ',', '$', ' ' ), '', trim( $input ) );
		if ( '' === $s ) {
			return 0;
		}
		if ( ! preg_match( '/^\d{1,9}(\.\d{1,2})?$/D', $s ) ) {
			return -1;
		}
		$parts = explode( '.', $s );
		return (int) $parts[0] * 100 + (int) str_pad( $parts[1] ?? '0', 2, '0' );
	}

	private static function dollars( int $cents ): string {
		return number_format( $cents / 100, 2, '.', '' );
	}

	private static function post_text( string $key ): string {
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle() 已校验。
	}

	// ---------- 表单处理 ----------

	public static function handle(): void {
		$action = substr( (string) current_action(), strlen( 'admin_post_' ) );
		if ( ! in_array( $action, self::ACTIONS, true ) || ! current_user_can( Menu::CAP ) ) {
			wp_die( '只有总后台管理员可以管理系列。', '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- 上面已用 check_admin_referer 校验。
		$series_id = absint( $_POST['series'] ?? 0 );
		$site_id   = absint( $_POST['site'] ?? 0 );
		$series    = Series::get( $series_id );
		$back      = self::url( $series ? array( 'series' => $series_id ) : array() );
		$sites     = Sites::all();
		$name      = isset( $sites[ $site_id ] ) ? self::site_name( $sites[ $site_id ] ) : '店铺';

		switch ( $action ) {
			case 'pchq_series_create':
				$id = Series::create( self::post_text( 'name' ) );
				if ( is_wp_error( $id ) ) {
					self::flash( 'error', $id->get_error_message() );
				} else {
					$back = self::url( array( 'series' => $id ) );
					self::flash( 'success', '系列已建好。下一步：把店铺归进来，选母版店，然后从母版店导入商品。' );
				}
				break;

			case 'pchq_series_update':
				if ( ! $series ) {
					self::flash( 'error', '系列不存在。' );
					break;
				}
				$fields = array( 'name' => self::post_text( 'name' ) );
				if ( isset( $_POST['master'] ) ) {
					$fields['master_site_id'] = absint( $_POST['master'] );
				}
				Series::update( $series_id, $fields );
				Series_Push::queue_series( $series_id );
				self::flash( 'success', '系列已保存。' );
				break;

			case 'pchq_series_site':
				if ( ! isset( $sites[ $site_id ] ) ) {
					self::flash( 'error', '店铺不存在。' );
					break;
				}
				Series::set_buyer( $site_id, self::post_text( 'buyer' ) );
				$to = absint( $_POST['to_series'] ?? 0 );
				if ( $to && $to !== Series::site( $site_id )['series_id'] ) {
					$moved = Series::assign( $site_id, $to );
					self::flash( is_wp_error( $moved ) ? 'error' : 'success', is_wp_error( $moved ) ? $moved->get_error_message() : $name . ' 已归到新的系列。这家店的系列同步已关闭，需要重新生成差异报告并确认后才会同步商品。' );
				} else {
					self::flash( 'success', $name . ' 已保存。' );
				}
				break;

			case 'pchq_series_import':
				$master = absint( $_POST['master'] ?? 0 );
				$parts  = array_values( array_intersect( array( 'products', 'shipping', 'campaigns' ), array_map( 'sanitize_key', (array) ( $_POST['parts'] ?? array() ) ) ) );
				if ( ! $series || ! $parts ) {
					self::flash( 'error', '请选择母版店和至少一项要导入的内容。' );
					break;
				}
				$res = Series_Catalog::import_from_store( $series_id, $master, $parts );
				if ( is_wp_error( $res ) ) {
					self::flash( 'error', $res->get_error_message() );
					break;
				}
				$said = array();
				if ( is_array( $res['products'] ) ) {
					$p      = $res['products'];
					$said[] = $p['changed'] ? sprintf( '商品：新增 %1$d，更新 %2$d，移出 %3$d，没变 %4$d（第 %5$d 版）', $p['added'], $p['updated'], $p['removed'], $p['same'], $p['ver'] ) : '商品没有变化';
				}
				if ( null !== $res['shipping'] ) {
					$said[] = '运费已导入（第 ' . (int) $res['shipping'] . ' 版）';
				}
				if ( null !== $res['campaigns'] ) {
					$said[] = $res['campaigns'] > 0 ? '活动已导入（第 ' . (int) $res['campaigns'] . ' 版）' : '母版店没有可导入的活动（店铺程序里还没有导出活动的模块，或者没有在跑的活动）';
				}
				self::flash( 'success', '已从母版店导入。' . implode( '；', $said ) . '。已经开启系列同步的店铺会在几分钟内更新；还没开启的店铺请先生成差异报告。' );
				break;

			case 'pchq_series_price':
				$sku    = isset( $_POST['sku'] ) && is_string( $_POST['sku'] ) ? trim( wp_unslash( $_POST['sku'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 只用来查找已有的商品键。
				$keys   = array_map( static fn( $k ): string => is_string( $k ) ? wp_unslash( $k ) : '', array_values( (array) ( $_POST['key'] ?? array() ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$price  = array_values( (array) ( $_POST['price'] ?? array() ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$comp   = array_values( (array) ( $_POST['compare'] ?? array() ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$prices = array();
				$bad    = false;
				foreach ( $keys as $i => $key ) {
					$p = self::cents( is_string( $price[ $i ] ?? null ) ? sanitize_text_field( wp_unslash( $price[ $i ] ) ) : '' );
					$c = self::cents( is_string( $comp[ $i ] ?? null ) ? sanitize_text_field( wp_unslash( $comp[ $i ] ) ) : '' );
					if ( $p < 0 || $c < 0 ) {
						$bad = true;
						break;
					}
					if ( 0 === $p ) {
						continue; // 成交价留空或填 0 的款式不改（例如停用的款式本来就没有价格）。
					}
					$prices[ $key ] = array( 'price_cents' => $p, 'compare_cents' => $c );
				}
				if ( $bad || ! $prices ) {
					self::flash( 'error', '价格没有保存：请填有效的金额（例如 49.99）。成交价留空或填 0 的款式不会改，划线价可以留空。' );
					break;
				}
				$before = $series ? $series['catalog_ver'] : 0;
				$ver    = Series_Catalog::set_prices( $series_id, $sku, $prices );
				if ( is_wp_error( $ver ) ) {
					self::flash( 'error', $ver->get_error_message() );
				} else {
					self::flash( 'success', $ver > $before ? '价格已保存（商品第 ' . $ver . ' 版），正在推送给这个系列里已开启同步的店铺；别的系列不受影响。' : '价格没有变化。' );
				}
				break;

			case 'pchq_series_product':
				$sku = isset( $_POST['sku'] ) && is_string( $_POST['sku'] ) ? trim( wp_unslash( $_POST['sku'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$ver = Series_Catalog::set_status( $series_id, $sku, '1' === ( $_POST['active'] ?? '' ) );
				self::flash( is_wp_error( $ver ) ? 'error' : 'success', is_wp_error( $ver ) ? $ver->get_error_message() : ( '1' === ( $_POST['active'] ?? '' ) ? '商品已放回系列，同步后各店重新上架。' : '商品已移出系列，同步后各店下架（不删除）。' ) );
				break;

			case 'pchq_series_shipping':
				$threshold = self::cents( self::post_text( 'threshold' ) );
				$below     = self::cents( self::post_text( 'below' ) );
				$above     = self::cents( self::post_text( 'above' ) );
				$dollars   = (int) round( $threshold / 100 );
				if ( ! $series || $dollars < 1 || $below < 0 || $above < 0 ) {
					self::flash( 'error', '运费没有保存：门槛必须是不小于 1 美元的整数金额，两档运费必须是不小于 0 的金额。' );
					break;
				}
				$ver = Series::save_shipping( $series_id, Series::default_shipping( $dollars, $below, $above ) );
				if ( is_wp_error( $ver ) ) {
					self::flash( 'error', $ver->get_error_message() );
				} else {
					Series_Push::queue_series( $series_id );
					self::flash( 'success', '运费已保存（第 ' . $ver . ' 版），已开启系列同步的店铺会在几分钟内更新，新开的店自动带上。' );
				}
				break;

			case 'pchq_series_diff':
				$report = Series_Push::diff( $site_id );
				if ( is_wp_error( $report ) ) {
					self::flash( 'error', $name . '：差异报告没有生成，' . $report->get_error_message() );
				} else {
					$back = self::url( array( 'series' => $report['series_id'], 'report' => $site_id ) );
					self::flash( 'success', $name . ' 的差异报告已生成。这一步只做比较，店铺里没有任何改动。' );
				}
				break;

			case 'pchq_series_confirm':
				$done = Series_Catalog::confirm( $series_id, $site_id, get_current_user_id() );
				self::flash( is_wp_error( $done ) ? 'error' : 'success', is_wp_error( $done ) ? $done->get_error_message() : $name . ' 已确认并开启系列同步，几分钟内开始同步。同步前会自动存一份快照，可以恢复。' );
				break;

			case 'pchq_series_sync_off':
				Series_Catalog::unconfirm( $series_id, $site_id );
				self::flash( 'success', $name . ' 的系列同步已关闭。已经同步过去的内容不变；之后系列的改动不会再发给这家店。' );
				break;

			case 'pchq_series_push':
				if ( $site_id ) {
					// 同步可能要几分钟（首次要下载图片），浏览器请求里只排队，由后台任务执行。
					Series_Push::queue_now( $site_id );
					self::flash( 'success', $name . ' 已排队同步，一两分钟内开始；结果显示在这一行的“已同步到”里。' );
				} elseif ( $series ) {
					$n = Series_Push::queue_series( $series_id );
					self::flash( 'success', '已给 ' . $n . ' 家店铺排队同步，几分钟内完成。' );
				}
				break;

			case 'pchq_series_restore':
				$res = Series_Push::restore( $site_id );
				if ( is_wp_error( $res ) ) {
					self::flash( 'error', $name . '：没有恢复，' . $res->get_error_message() );
				} else {
					$c = (array) ( $res['counts'] ?? array() );
					Series_Catalog::unconfirm( $series_id, $site_id );
					self::flash( 'success', sprintf( '%1$s 已恢复到同步前的快照：恢复 %2$d 个商品，同步后新建的 %3$d 个改为草稿。这家店的系列同步已关闭，要重新同步请再生成差异报告并确认。', $name, (int) ( $c['restored'] ?? 0 ), (int) ( $c['unpublished'] ?? 0 ) ) );
				}
				break;

			case 'pchq_series_design':
				if ( $series ) {
					Design_Push::use_series( $series_id );
					$back = admin_url( 'admin.php?page=pchq-design' );
				}
				break;
		}
		// phpcs:enable
		wp_safe_redirect( $back );
		exit;
	}

	// ---------- 页面 ----------

	private static function form_open( string $action, array $hidden, string $confirm = '', string $class = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . ( '' !== $confirm ? ' data-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>';
		wp_nonce_field( $action );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		foreach ( $hidden as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( (string) $v ) . '">';
		}
	}

	private static function button_form( string $action, array $hidden, string $label, string $confirm = '', bool $primary = false ): void {
		self::form_open( $action, $hidden, $confirm );
		echo '<button type="submit" class="button button-small' . ( $primary ? ' button-primary' : '' ) . '">' . esc_html( $label ) . '</button></form>';
	}

	public static function render(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			return;
		}
		$flash = get_transient( 'pchq_series_flash_' . get_current_user_id() );
		delete_transient( 'pchq_series_flash_' . get_current_user_id() );
		echo '<div class="wrap pchq pcp">';
		echo '<header class="pcp-head"><div><h1>系列管理</h1><p>一个系列 = 一套商品和价格 + 一套模板 + 一套活动 + 一套运费。同一个系列的店铺内容完全一致，在这里改一处，同系列的店自动同步；不同系列互不影响。</p></div></header>';
		if ( is_array( $flash ) ) {
			echo '<div class="pcp-flash ' . ( 'error' === $flash[0] ? 'is-bad' : 'is-ok' ) . '" role="status">' . esc_html( (string) $flash[1] ) . '</div>';
		}
		if ( ! Series::ready() ) {
			echo '<div class="pcp-flash is-bad">系列的数据表还没准备好，请刷新页面；一直这样时检查数据库权限。</div></div>';
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 只读。
		$all       = Series::all();
		$series_id = absint( $_GET['series'] ?? 0 );
		$series_id = isset( $all[ $series_id ] ) ? $series_id : Series::default_id();
		$report    = absint( $_GET['report'] ?? 0 );
		// phpcs:enable
		$series = $all[ $series_id ] ?? null;

		echo '<div class="pchq-s-tabs">';
		foreach ( $all as $s ) {
			$count = count( Series::sites_of( $s['id'] ) );
			echo '<a class="button' . ( $s['id'] === $series_id ? ' button-primary' : '' ) . '" href="' . esc_url( self::url( array( 'series' => $s['id'] ) ) ) . '">' . esc_html( $s['name'] . '（' . $count . ' 家店）' ) . '</a>';
		}
		echo '<details class="pcp-new"><summary class="button">新建系列</summary>';
		self::form_open( 'pchq_series_create', array(), '', 'pchq-s-form' );
		echo '<label>系列名称<input type="text" name="name" maxlength="80" required placeholder="例如：系列 2"></label><button type="submit" class="button button-primary">新建</button></form></details></div>';

		if ( ! $series ) {
			echo '<section class="pcp-card pcp-empty"><strong>还没有系列</strong></section></div>';
			return;
		}
		if ( $report ) {
			self::render_report( $series, $report );
			echo '</div>';
			return;
		}
		$stores = Series::sites_of( $series_id );
		self::render_overview( $series, $stores );
		self::render_stores( $series, $stores, $all );
		self::render_catalog( $series, $stores );
		self::render_shipping( $series );
		self::render_campaigns( $series );
		echo '</div>';
	}

	private static function render_overview( array $series, array $stores ): void {
		echo '<section class="pcp-card"><div class="pcp-card__head"><h2>' . esc_html( $series['name'] ) . '</h2><span class="pcp-hint">代号 ' . esc_html( $series['slug'] ) . ' · 商品第 ' . (int) $series['catalog_ver'] . ' 版 · 模板第 ' . (int) $series['template_ver'] . ' 版 · 活动第 ' . (int) $series['campaign_ver'] . ' 版 · 运费第 ' . (int) $series['shipping_ver'] . ' 版</span></div>';
		self::form_open( 'pchq_series_update', array( 'series' => $series['id'] ), '', 'pchq-s-form' );
		echo '<label>系列名称<input type="text" name="name" maxlength="80" required value="' . esc_attr( $series['name'] ) . '"></label>';
		echo '<label>母版店（商品内容在这家店编辑，再导入到系列）<select name="master"><option value="0">还没选</option>';
		foreach ( $stores as $id => $s ) {
			echo '<option value="' . (int) $id . '"' . selected( $series['master_site_id'], (int) $id, false ) . '>' . esc_html( self::site_name( $s ) ) . '</option>';
		}
		echo '</select></label><button type="submit" class="button button-primary">保存</button></form>';
		echo '<div class="pchq-s-actions">';
		self::button_form( 'pchq_series_design', array( 'series' => $series['id'] ), '去模板库（装修和下发这个系列的模板）' );
		self::button_form( 'pchq_series_push', array( 'series' => $series['id'] ), '给这个系列的店铺全部同步一次' );
		echo '</div></section>';
	}

	private static function render_stores( array $series, array $stores, array $all ): void {
		echo '<h2 class="pcp-section">店铺 <span>' . count( $stores ) . '</span></h2>';
		if ( ! $stores ) {
			echo '<section class="pcp-card pcp-empty"><strong>这个系列还没有店铺</strong><p>到别的系列里把店铺改到这个系列，或者开新店时选这个系列。</p></section>';
			return;
		}
		$lagging = Series_Push::lagging();
		$labels  = array( 'header' => '系列信息', 'catalog' => '商品', 'campaigns' => '活动', 'shipping' => '运费' );
		echo '<section class="pcp-card pcp-access"><div class="pcp-scroll"><table class="pcp-matrix"><thead><tr><th scope="col">店铺</th><th scope="col">投手 / 所属系列</th><th scope="col">系列同步</th><th scope="col">已同步到</th><th scope="col">差异报告</th><th scope="col">操作</th></tr></thead><tbody>';
		foreach ( $stores as $id => $s ) {
			$id     = (int) $id;
			$master = $id === $series['master_site_id'];
			$state  = Series_Push::site_state( $id );
			$diff   = Series_Catalog::diff( $series['id'], $id );
			$conf   = Series_Catalog::confirmation( $series['id'], $id );
			$need   = $lagging[ $id ] ?? array();
			echo '<tr><td><strong>' . esc_html( self::site_name( $s ) ) . '</strong>' . ( $master ? '<small>母版店</small>' : '' ) . '</td><td>';
			self::form_open( 'pchq_series_site', array( 'series' => $series['id'], 'site' => $id ), '', 'pchq-s-form' );
			echo '<label>投手<input type="text" name="buyer" maxlength="60" value="' . esc_attr( (string) $s['buyer_name'] ) . '" size="10"></label><label>系列<select name="to_series">';
			foreach ( $all as $other ) {
				echo '<option value="' . (int) $other['id'] . '"' . selected( $series['id'], $other['id'], false ) . '>' . esc_html( $other['name'] ) . '</option>';
			}
			echo '</select></label><button type="submit" class="button button-small">保存</button></form></td>';

			echo '<td>';
			if ( $master ) {
				echo '<span class="pcp-state is-ok"><i></i>母版店</span><small>只把总后台改的价格同步回来</small>';
			} elseif ( ! empty( $s['series_sync'] ) && $conf ) {
				echo '<span class="pcp-state is-ok"><i></i>已开启</span><small>' . esc_html( wp_date( 'm-d H:i', (int) $conf['at'] ) ) . ' 确认</small>';
			} elseif ( ! empty( $s['series_sync'] ) ) {
				echo '<span class="pcp-state is-wait"><i></i>等确认差异</span><small>运费和活动已同步，商品要确认后才同步</small>';
			} else {
				echo '<span class="pcp-state is-off"><i></i>未开启</span>';
			}
			echo '</td><td>';
			if ( ! $state['at'] ) {
				echo '<span class="pchq-s-muted">还没同步过</span>';
			} else {
				echo esc_html( sprintf( '商品 %1$d · 模板 %2$d · 活动 %3$d · 运费 %4$d', $state['synced']['catalog_ver'], $state['synced']['template_ver'], $state['synced']['campaign_ver'], $state['synced']['shipping_ver'] ) );
				echo '<small>' . esc_html( wp_date( 'm-d H:i', $state['at'] ) . ( $state['ok'] ? '' : ' 失败' ) ) . '</small>';
				if ( '' !== $state['message'] ) {
					echo '<small class="' . ( $state['ok'] ? 'pchq-s-muted' : 'pchq-s-bad' ) . '">' . esc_html( $state['message'] ) . '</small>';
				}
			}
			if ( $need ) {
				echo '<small class="pchq-s-bad">落后：' . esc_html( implode( '、', array_map( static fn( string $k ): string => $labels[ $k ] ?? $k, $need ) ) ) . '</small>';
			}
			echo '</td><td>';
			if ( $diff ) {
				$sum = $diff['summary'];
				echo '<a href="' . esc_url( self::url( array( 'series' => $series['id'], 'report' => $id ) ) ) . '">' . esc_html( sprintf( '一样 %1$d · 有差异 %2$d · 新建 %3$d · 下架 %4$d', $sum['same'], $sum['changed'], $sum['create'], $sum['unpublish'] ) ) . '</a>';
				echo '<small>' . esc_html( '针对商品第 ' . (int) $diff['ver'] . ' 版 · ' . wp_date( 'm-d H:i', (int) $diff['at'] ) ) . ( (int) $diff['ver'] !== (int) $series['catalog_ver'] ? ' <span class="pchq-s-bad">已过期</span>' : '' ) . '</small>';
			} else {
				echo '<span class="pchq-s-muted">还没生成</span>';
			}
			echo '</td><td><div class="pchq-s-actions">';
			$ids = array( 'series' => $series['id'], 'site' => $id );
			if ( $series['catalog_ver'] > 0 ) {
				self::button_form( 'pchq_series_diff', $ids, $diff ? '重新生成报告' : '生成差异报告' );
			}
			if ( ! $master && $diff && (int) $diff['ver'] === (int) $series['catalog_ver'] && ( ! $conf || empty( $s['series_sync'] ) ) ) {
				self::button_form( 'pchq_series_confirm', $ids, '确认并开启同步', '确定开启？这家店的商品和价格会变成和系列主商品库一致：有差异的商品会被改掉，系列里没有的商品会下架（不删除）。同步前自动存快照，可以恢复。', true );
			}
			if ( ! $master && ( $conf || ! empty( $s['series_sync'] ) ) ) {
				self::button_form( 'pchq_series_sync_off', $ids, '关闭同步', '确定关闭这家店的系列同步？已经同步过去的内容不变，之后系列的改动不会再发给它。' );
			}
			self::button_form( 'pchq_series_push', $ids, '立即同步' );
			if ( ! $master && $state['synced']['catalog_ver'] > 0 ) {
				self::button_form( 'pchq_series_restore', $ids, '恢复到同步前', '确定把这家店的商品、价格和运费恢复到最近一次同步之前的样子？恢复后这家店的系列同步会关闭。' );
			}
			echo '</div></td></tr>';
		}
		echo '</tbody></table></div><p class="pcp-hint">现有店铺默认不接受系列同步。第一次同步前先“生成差异报告”，看过确认后才会改店铺里的商品；新开的店默认接受同步。</p></section>';
	}

	private static function render_catalog( array $series, array $stores ): void {
		$products = Series_Catalog::products( $series['id'], true );
		$active   = count( array_filter( $products, static fn( array $p ): bool => 'active' === $p['status'] ) );
		echo '<h2 class="pcp-section">主商品库 <span>' . (int) $active . '</span></h2>';
		echo '<section class="pcp-card"><div class="pcp-card__head"><h2>从母版店导入</h2><span class="pcp-hint">商品的描述、图片、规格在母版店里改，改完在这里导入一次；价格可以直接在下面改</span></div>';
		if ( ! $stores ) {
			echo '<p class="pcp-hint">这个系列还没有店铺，先把母版店归进来。</p></section>';
		} else {
			self::form_open( 'pchq_series_import', array( 'series' => $series['id'] ), '确定从母版店导入？系列主商品库会整体换成母版店现在的内容（包括价格）；已开启系列同步的店铺随后自动更新。', 'pchq-s-form' );
			echo '<label>母版店<select name="master" required>';
			foreach ( $stores as $id => $s ) {
				echo '<option value="' . (int) $id . '"' . selected( $series['master_site_id'] ?: array_key_first( $stores ), (int) $id, false ) . '>' . esc_html( self::site_name( $s ) ) . '</option>';
			}
			echo '</select></label>';
			foreach ( array( 'products' => array( '商品和价格', true ), 'shipping' => array( '运费方案', false ), 'campaigns' => array( '营销活动', false ) ) as $key => $part ) {
				echo '<label class="pcp-check" style="flex-direction:row;align-items:center"><input type="checkbox" name="parts[]" value="' . esc_attr( $key ) . '"' . checked( $part[1], true, false ) . '> ' . esc_html( $part[0] ) . '</label>';
			}
			echo '<button type="submit" class="button button-primary">导入</button></form></section>';
		}
		if ( ! $products ) {
			echo '<section class="pcp-card pcp-empty"><strong>主商品库还是空的</strong><p>选好母版店后点“导入”。导入不会改任何店铺，直到你确认差异报告。</p></section>';
			return;
		}
		echo '<section class="pcp-card pcp-access"><div class="pcp-scroll"><table class="pcp-matrix"><thead><tr><th scope="col">商品</th><th scope="col">款式和价格（美元）</th><th scope="col">版本</th><th scope="col">操作</th></tr></thead><tbody>';
		foreach ( $products as $p ) {
			$payload = $p['payload'];
			$removed = 'active' !== $p['status'];
			$form_id = 'pchq-s-price-' . (int) $p['id'];
			echo '<tr' . ( $removed ? ' class="pchq-s-removed"' : '' ) . '><td><strong>' . esc_html( $p['name'] ) . '</strong><small>' . esc_html( $p['sku'] ) . ( $removed ? ' · 已移出系列' : '' ) . '</small></td><td>';
			$rows = array();
			if ( 'variable' === ( $payload['type'] ?? '' ) ) {
				foreach ( (array) ( $payload['variations'] ?? array() ) as $v ) {
					$rows[] = array( Series_Catalog::variation_key( (array) $v ), implode( ' / ', array_map( 'strval', (array) ( $v['attributes'] ?? array() ) ) ), (int) ( $v['price_cents'] ?? 0 ), (int) ( $v['compare_cents'] ?? 0 ), ! array_key_exists( 'enabled', (array) $v ) || ! empty( $v['enabled'] ) );
				}
			} else {
				$rows[] = array( '', '单一规格', (int) ( $payload['price_cents'] ?? 0 ), (int) ( $payload['compare_cents'] ?? 0 ), true );
			}
			echo '<table class="pchq-s-vars">';
			foreach ( $rows as $r ) {
				echo '<tr><td>' . esc_html( $r[1] ) . ( $r[4] ? '' : '（停用）' ) . '</td>';
				if ( $removed ) {
					echo '<td class="pchq-s-num">' . esc_html( self::dollars( $r[2] ) ) . '</td><td class="pchq-s-num pchq-s-muted">' . ( $r[3] ? esc_html( self::dollars( $r[3] ) ) : '—' ) . '</td>';
				} else {
					echo '<td><input type="hidden" form="' . esc_attr( $form_id ) . '" name="key[]" value="' . esc_attr( $r[0] ) . '"><input type="text" inputmode="decimal" class="pchq-s-price" form="' . esc_attr( $form_id ) . '" name="price[]" value="' . esc_attr( self::dollars( $r[2] ) ) . '" aria-label="成交价"></td>';
					echo '<td><input type="text" inputmode="decimal" class="pchq-s-price" form="' . esc_attr( $form_id ) . '" name="compare[]" value="' . esc_attr( $r[3] ? self::dollars( $r[3] ) : '' ) . '" placeholder="划线价" aria-label="划线价"></td>';
				}
				echo '</tr>';
			}
			echo '</table></td><td>第 ' . (int) $p['ver'] . ' 版</td><td><div class="pchq-s-actions">';
			if ( ! $removed ) {
				echo '<form method="post" id="' . esc_attr( $form_id ) . '" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-confirm="确定保存价格？这个系列里已开启同步的店铺会跟着改，别的系列不受影响。">';
				wp_nonce_field( 'pchq_series_price' );
				echo '<input type="hidden" name="action" value="pchq_series_price"><input type="hidden" name="series" value="' . (int) $series['id'] . '"><input type="hidden" name="sku" value="' . esc_attr( $p['sku'] ) . '"><button type="submit" class="button button-small button-primary">保存价格</button></form>';
				self::button_form( 'pchq_series_product', array( 'series' => $series['id'], 'sku' => $p['sku'], 'active' => '0' ), '移出系列', '确定把这个商品移出系列？同步后各店会下架它（不删除）。' );
			} else {
				self::button_form( 'pchq_series_product', array( 'series' => $series['id'], 'sku' => $p['sku'], 'active' => '1' ), '放回系列' );
			}
			echo '</div></td></tr>';
		}
		echo '</tbody></table></div></section>';
	}

	private static function render_shipping( array $series ): void {
		$plans     = (array) ( $series['shipping']['plans'] ?? array() );
		$rates     = (array) ( $plans[0]['rates'] ?? array() );
		$two_tier  = 1 === count( $plans ) && 2 === count( $rates ) && null !== ( $rates[0]['max'] ?? null ) && ! isset( $rates[1]['max'] );
		$threshold = $two_tier ? (float) $rates[0]['max'] : 1000.0;
		$below     = $two_tier ? (int) $rates[0]['fee_cents'] : 4500;
		$above     = $two_tier ? (int) $rates[1]['fee_cents'] : 1500;
		echo '<h2 class="pcp-section">运费</h2><section class="pcp-card"><div class="pcp-card__head"><h2>两档运费</h2><span class="pcp-hint">' . ( $series['shipping_ver'] > 0 ? '第 ' . (int) $series['shipping_ver'] . ' 版，已随系列下发' : '还没保存：保存后才会下发，新开的店自动带上' ) . '</span></div>';
		if ( $series['shipping_ver'] > 0 && ! $two_tier ) {
			echo '<p class="pcp-hint">现在用的是从母版店导入的运费方案（' . count( $plans ) . ' 个方案）。在下面保存两档运费会替换它。</p>';
		}
		self::form_open( 'pchq_series_shipping', array( 'series' => $series['id'] ), '确定保存运费？已开启系列同步的店铺会跟着改。', 'pchq-s-form' );
		echo '<label>订单金额不满（美元）<input type="text" inputmode="decimal" name="threshold" value="' . esc_attr( number_format( $threshold, 0, '.', '' ) ) . '" size="8" required></label>';
		echo '<label>不满时的运费<input type="text" inputmode="decimal" name="below" value="' . esc_attr( self::dollars( $below ) ) . '" size="8" required></label>';
		echo '<label>满额后的运费<input type="text" inputmode="decimal" name="above" value="' . esc_attr( self::dollars( $above ) ) . '" size="8" required></label>';
		echo '<button type="submit" class="button button-primary">保存运费</button></form>';
		echo '<p class="pcp-hint">只配送美国。按商品单独设置的运费方案留在各店，不受影响。</p></section>';
	}

	private static function render_campaigns( array $series ): void {
		$items = Series_Campaigns::items( $series['id'] );
		echo '<h2 class="pcp-section">营销活动 <span>' . count( $items ) . '</span></h2><section class="pcp-card">';
		if ( ! $items ) {
			echo '<p class="pcp-hint">这个系列还没有活动。活动在母版店的“营销活动”里配置，然后在上面“从母版店导入”时勾选“营销活动”。</p></section>';
			return;
		}
		$kinds = array( 'promotion' => '满减活动', 'coupon' => '优惠码', 'ship_discount' => '运费折扣', 'first_order' => '首单优惠', 'exit_intent' => '离开挽回', 'wheel' => '转盘', 'upsell' => '加购推荐', 'progress_bar' => '进度条' );
		echo '<div class="pcp-scroll"><table class="pcp-matrix"><thead><tr><th scope="col">类型</th><th scope="col">代号</th><th scope="col">状态</th></tr></thead><tbody>';
		foreach ( $items as $it ) {
			echo '<tr><td>' . esc_html( $kinds[ $it['kind'] ] ?? $it['kind'] ) . '</td><td>' . esc_html( $it['key'] ) . '</td><td><span class="pcp-state ' . ( $it['active'] ? 'is-ok' : 'is-off' ) . '"><i></i>' . ( $it['active'] ? '在跑' : '停用' ) . '</span></td></tr>';
		}
		echo '</tbody></table></div><p class="pcp-hint">活动第 ' . (int) $series['campaign_ver'] . ' 版。内容由营销模块下发到已开启系列同步的店铺。</p></section>';
	}

	private static function render_report( array $series, int $site_id ): void {
		$site   = Sites::get( $site_id );
		$report = Series_Catalog::diff( $series['id'], $site_id );
		echo '<p><a href="' . esc_url( self::url( array( 'series' => $series['id'] ) ) ) . '">← 返回' . esc_html( $series['name'] ) . '</a></p>';
		if ( ! $site || ! $report ) {
			echo '<section class="pcp-card pcp-empty"><strong>还没有这家店的差异报告</strong></section>';
			return;
		}
		$sum     = $report['summary'];
		$stale   = (int) $report['ver'] !== (int) $series['catalog_ver'];
		$conf    = Series_Catalog::confirmation( $series['id'], $site_id );
		$master  = $site_id === $series['master_site_id'];
		$fields  = array( 'name' => '名称', 'status' => '上架状态', 'type' => '商品类型', 'description' => '描述', 'short_description' => '简短描述', 'categories' => '专辑', 'menu_order' => '排序', 'attributes' => '规格', 'default_attributes' => '默认规格', 'images' => '图片', 'meta' => '附加信息', 'price' => '成交价', 'compare_price' => '划线价', 'stock' => '库存状态', 'enabled' => '款式启用', 'variation' => '款式', 'variation_attributes' => '款式规格', 'image' => '款式图片' );
		echo '<section class="pcp-card"><div class="pcp-card__head"><h2>' . esc_html( self::site_name( $site ) ) . ' 和系列主商品库的差异</h2><span class="pcp-hint">针对商品第 ' . (int) $report['ver'] . ' 版 · ' . esc_html( wp_date( 'Y-m-d H:i', (int) $report['at'] ) ) . '</span></div>';
		echo '<dl class="pcp-stats"><div><dt>一样</dt><dd>' . (int) $sum['same'] . '</dd></div><div><dt>有差异，同步后改成系列的</dt><dd>' . (int) $sum['changed'] . '</dd></div><div><dt>本店没有，同步后新建</dt><dd>' . (int) $sum['create'] . '</dd></div><div><dt>系列里没有，同步后下架</dt><dd>' . (int) $sum['unpublish'] . '</dd></div></dl>';
		if ( ! empty( $report['prices_only'] ) ) {
			echo '<p class="pcp-hint">这是母版店：只比较价格，同步时也只改价格。</p>';
		}
		if ( is_array( $report['shipping'] ?? null ) ) {
			echo '<p class="pcp-hint">运费：' . ( $report['shipping']['changed'] ? '和系列的不一样，同步后改成系列的运费。' : '和系列的一样。' ) . '</p>';
		}
		if ( $stale ) {
			echo '<div class="pcp-flash is-bad">这份报告是针对旧版本商品的，主商品库之后又改过。请重新生成后再确认。</div>';
		}
		echo '<div class="pchq-s-actions">';
		self::button_form( 'pchq_series_diff', array( 'series' => $series['id'], 'site' => $site_id ), '重新生成报告' );
		if ( ! $master && ! $stale && ! $conf ) {
			self::button_form( 'pchq_series_confirm', array( 'series' => $series['id'], 'site' => $site_id ), '我看过了，确认并开启同步', '确定开启？这家店的商品和价格会变成和系列主商品库一致。同步前自动存快照，可以恢复。', true );
		}
		echo '</div>';
		if ( $conf ) {
			echo '<p class="pcp-hint">已在 ' . esc_html( wp_date( 'Y-m-d H:i', (int) $conf['at'] ) ) . ' 确认（当时是商品第 ' . (int) $conf['ver'] . ' 版），这家店已开启系列同步。</p>';
		}
		echo '</section>';

		if ( $report['changed'] ) {
			echo '<h2 class="pcp-section">有差异的商品 <span>' . count( $report['changed'] ) . '</span></h2><section class="pcp-card pcp-access"><div class="pcp-scroll"><table class="pcp-matrix pchq-s-report"><thead><tr><th scope="col">商品</th><th scope="col">哪里不一样</th><th scope="col">本店现在</th><th scope="col">系列（同步后）</th></tr></thead><tbody>';
			foreach ( $report['changed'] as $c ) {
				$list = array_values( array_filter( (array) ( $c['fields'] ?? array() ), 'is_array' ) );
				foreach ( $list as $i => $f ) {
					echo '<tr>';
					if ( 0 === $i ) {
						echo '<td rowspan="' . count( $list ) . '"><strong>' . esc_html( (string) ( $c['name'] ?? '' ) ) . '</strong><small>' . esc_html( (string) ( $c['sku'] ?? '' ) ) . '</small></td>';
					}
					$what = (string) ( $f['field'] ?? '' );
					echo '<td>' . esc_html( ( $fields[ $what ] ?? $what ) . ( isset( $f['variation'] ) ? '（' . $f['variation'] . '）' : '' ) ) . '</td><td>' . esc_html( (string) ( $f['store'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $f['series'] ?? '' ) ) . '</td></tr>';
				}
			}
			echo '</tbody></table></div></section>';
		}
		foreach ( array( 'create' => '本店没有、同步后会新建的商品', 'unpublish' => '系列里没有、同步后会下架的商品（不删除）' ) as $key => $title ) {
			if ( ! $report[ $key ] ) {
				continue;
			}
			echo '<h2 class="pcp-section">' . esc_html( $title ) . ' <span>' . count( $report[ $key ] ) . '</span></h2><section class="pcp-card pcp-access"><div class="pcp-scroll"><table class="pcp-matrix"><thead><tr><th scope="col">商品</th><th scope="col">商品键</th></tr></thead><tbody>';
			foreach ( $report[ $key ] as $p ) {
				echo '<tr><td>' . esc_html( (string) ( $p['name'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $p['sku'] ?? '' ) ) . '</td></tr>';
			}
			echo '</tbody></table></div></section>';
		}
	}
}
