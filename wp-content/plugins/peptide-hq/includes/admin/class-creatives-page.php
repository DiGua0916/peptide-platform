<?php
/**
 * 总后台 → 素材对比、素材词表（CR 素材归因线 3）。
 *
 * - 素材对比：各店每天上报的“按素材”汇总（pchq_creative_daily），默认按素材属性对比所有店，
 *   可切换到按单个素材、按店铺、按投手（pchq_sites.buyer_name）。筛选：日期范围、店铺（多选）、属性取值。
 *   有效访客少于 Creatives::MIN_SAMPLE 的行置灰、标“样本不足”。店铺不写死：哪家店上报了就出现哪家。
 * - 检查点导出：合计有效访客每跨过 1000 的整数倍自动生成（见 Creatives::maybe_checkpoint()），在本页下载；
 *   也可以按当前筛选手动导出 CSV / JSON。
 * - 素材词表：新增取值、改中文名、停用 / 启用、调顺序；不能删除。每次有改动版本号加一，店铺按版本拉取覆盖。
 * - 只给总后台管理员；写操作和下载校验 nonce。
 *
 * 没放进 Menu::navigation_policy()：这两个页面和“跨店报表”一样自带样式、筛选用多值参数（sites[]），走普通整页加载。
 *
 * @package PeptideHq
 */

namespace PeptideHq\Admin;

use PeptideHq\Creatives;
use PeptideHq\Orders;
use PeptideHq\Series;
use PeptideHq\Sites;

defined( 'ABSPATH' ) || exit;

final class Creatives_Page {

	public const SLUG       = 'pchq-creatives';
	public const VOCAB_SLUG = 'pchq-creatives-vocab';

	public const MODES = array(
		'attr'     => '按素材属性',
		'creative' => '按单个素材',
		'site'     => '按店铺',
		'buyer'    => '按投手',
	);

	/** 属性的中文名（词表里只有取值的中文名）。 */
	public const ATTR_LABELS = array(
		'hook'  => '钩子',
		'fmt'   => '形式',
		'angle' => '角度',
		'lang'  => '语言',
	);

	public static function hooks(): void {
		add_action(
			'admin_menu',
			static function (): void {
				add_submenu_page( 'pchq', '素材对比', '素材对比', Menu::CAP, self::SLUG, array( self::class, 'render' ) );
				add_submenu_page( 'pchq', '素材词表', '素材词表', Menu::CAP, self::VOCAB_SLUG, array( self::class, 'render_vocab' ) );
			},
			23
		);
		add_action( 'admin_post_pchq_creatives_vocab_save', array( self::class, 'handle_vocab' ) );
		add_action( 'admin_post_pchq_creatives_export', array( self::class, 'handle_export' ) );
		add_action( 'admin_post_pchq_creatives_download', array( self::class, 'handle_download' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ), 1001 );
	}

	public static function assets(): void {
		$page = $_GET['page'] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::SLUG !== $page && self::VOCAB_SLUG !== $page ) {
			return;
		}
		$dir = dirname( PEPTIDE_HQ_FILE ) . '/assets/';
		wp_enqueue_style( 'pchq-plugins', plugins_url( 'assets/hq-plugins.css', PEPTIDE_HQ_FILE ), array( 'peptide-hq-workspace' ), PEPTIDE_HQ_VERSION . '.' . (int) @filemtime( $dir . 'hq-plugins.css' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_add_inline_style( 'pchq-plugins', Orders_Page::css() . self::css() );
	}

	public static function css(): string {
		return '.pchq-cr-thin td{color:#8c8f94;background:#fafafa}.pchq-cr-thin td a{color:#8c8f94}'
			. '.pchq-cr-tag{display:inline-block;margin-left:6px;padding:0 6px;border-radius:9px;font-size:11px;line-height:18px;background:#f0f0f1;color:#646970;font-weight:400;white-space:nowrap}'
			. '.pchq-cr-head small{display:block;font-weight:400;font-size:11px;color:#777;white-space:normal;min-width:80px}'
			. '.pchq-cr-modes{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 12px}'
			. '.pchq-cr-pick{flex-basis:100%;font-size:13px}.pchq-cr-pick summary{cursor:pointer;min-height:34px;line-height:34px}'
			. '.pchq-cr-sites{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:6px 16px}.pchq-cr-sites label{flex-direction:row!important;align-items:center;gap:6px!important;font-size:13px!important;color:#1d2327!important}.pchq-cr-sites input{min-height:0!important;margin:0}'
			. '.pchq-cr-group summary{cursor:pointer}.pchq-cr-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px;margin:8px 0 4px;min-width:320px}'
			. '.pchq-cr-card{border:1px solid #e5e5e5;border-radius:6px;padding:6px;font-size:12px;background:#fff}.pchq-cr-card img{display:block;width:100%;max-height:150px;object-fit:contain;background:#f6f7f7;border-radius:4px;margin:0 0 4px}'
			. '.pchq-cr-noimg{display:flex;align-items:center;justify-content:center;height:80px;background:#f6f7f7;color:#999;border-radius:4px;margin:0 0 4px}'
			. '.pchq-cr-vocab td input[type=text]{width:100%;max-width:260px}.pchq-cr-vocab td input[type=number]{width:80px}.pchq-cr-off td{color:#8c8f94}';
	}

	private static function url( array $args = array(), string $slug = self::SLUG ): string {
		return add_query_arg( array_merge( array( 'page' => $slug ), $args ), admin_url( 'admin.php' ) );
	}

	private static function flash_key(): string {
		return 'pchq_creatives_flash_' . get_current_user_id();
	}

	private static function flash( string $type, string $text ): void {
		set_transient( self::flash_key(), array( $type, $text ), 120 );
	}

	private static function show_flash(): void {
		$flash = get_transient( self::flash_key() );
		delete_transient( self::flash_key() );
		if ( is_array( $flash ) && 2 === count( $flash ) ) {
			echo '<div class="pcp-flash ' . ( 'error' === $flash[0] ? 'is-bad' : 'is-ok' ) . '" role="status">' . esc_html( (string) $flash[1] ) . '</div>';
		}
	}

	public static function attr_label( string $attr ): string {
		return self::ATTR_LABELS[ $attr ] ?? $attr;
	}

	// ---------- 筛选 ----------

	/**
	 * 从请求里读筛选条件（纯整理，参数由调用方传入）。
	 *
	 * @return array{from:string,to:string,preset:string,sites:int[],mode:string,by:string,f_attr:string,f_value:string}
	 */
	public static function filters( array $q ): array {
		$str = static fn( string $k ): string => isset( $q[ $k ] ) && is_string( $q[ $k ] ) ? sanitize_text_field( wp_unslash( $q[ $k ] ) ) : '';
		[ $from, $to, $preset ] = Report_Page::range( sanitize_key( $str( 'range' ) ), $str( 'from' ), $str( 'to' ) );
		$sites = array();
		foreach ( isset( $q['sites'] ) && is_array( $q['sites'] ) ? $q['sites'] : array() as $id ) {
			if ( is_scalar( $id ) && absint( $id ) > 0 ) {
				$sites[ absint( $id ) ] = absint( $id );
			}
		}
		$mode = $str( 'mode' );
		$by   = sanitize_key( $str( 'by' ) );
		$only = $str( 'only' );
		[ $f_attr, $f_value ] = 1 === preg_match( '/^([a-z][a-z0-9]{0,15}):([a-z0-9]{1,24})$/D', $only, $m ) ? array( $m[1], $m[2] ) : array( '', '' );
		return array(
			'from'    => $from,
			'to'      => $to,
			'preset'  => $preset,
			'sites'   => array_values( $sites ),
			'mode'    => isset( self::MODES[ $mode ] ) ? $mode : 'attr',
			'by'      => 1 === preg_match( '/^[a-z][a-z0-9]{0,15}$/D', $by ) ? $by : 'hook',
			'f_attr'  => $f_attr,
			'f_value' => $f_value,
		);
	}

	private static function filter_args( array $f, array $extra = array() ): array {
		$args = array( 'from' => $f['from'], 'to' => $f['to'], 'mode' => $f['mode'], 'by' => $f['by'] );
		if ( $f['sites'] ) {
			$args['sites'] = $f['sites'];
		}
		if ( '' !== $f['f_attr'] ) {
			$args['only'] = $f['f_attr'] . ':' . $f['f_value'];
		}
		return array_merge( $args, $extra );
	}

	// ---------- 显示 ----------

	private static function pct( ?float $v ): string {
		return null === $v ? '<span class="pchq-f3-muted">—</span>' : esc_html( number_format( $v * 100, 1 ) . '%' );
	}

	private static function cents( ?float $v ): string {
		return null === $v ? '<span class="pchq-f3-muted">—</span>' : esc_html( Orders::money( (int) round( $v ) ) );
	}

	/** 表头：名称 + 口径。 */
	public static function columns(): array {
		return array(
			array( '花费', '投手导入的 Meta 花费，按素材汇总' ),
			array( '进站访客', '各店上报的带这个素材进站的访客' ),
			array( '有效访客', '进站访客去掉意向分层为“无效”的，由店铺计算；少于 ' . Creatives::MIN_SAMPLE . ' 为样本不足' ),
			array( '中高意向占比', '（高 + 中意向）÷ 有效访客' ),
			array( '加购率', '加购数 ÷ 有效访客' ),
			array( '进结账率', '进结账数 ÷ 有效访客' ),
			array( '进收银页', '店铺上报的进收银页数' ),
			array( '订单', '店铺上报的订单数' ),
			array( '销售额', '店铺上报的订单金额' ),
			array( '每有效访客成本', '花费 ÷ 有效访客' ),
			array( '每高意向访客成本', '花费 ÷ 高意向访客' ),
			array( 'ROAS', '销售额 ÷ 花费；没有花费时为 —' ),
		);
	}

	private static function cells( array $m ): string {
		return '<td class="pchq-f3-num">' . ( $m['spend_cents'] > 0 ? esc_html( Orders::money( (int) $m['spend_cents'] ) ) : '<span class="pchq-f3-muted">$0.00</span>' ) . '</td>'
			. '<td class="pchq-f3-num">' . (int) $m['visitors'] . '</td>'
			. '<td class="pchq-f3-num"><strong>' . (int) $m['valid_visitors'] . '</strong></td>'
			. '<td class="pchq-f3-num">' . self::pct( $m['mid_high_share'] ) . '</td>'
			. '<td class="pchq-f3-num">' . self::pct( $m['cart_rate'] ) . '</td>'
			. '<td class="pchq-f3-num">' . self::pct( $m['checkout_rate'] ) . '</td>'
			. '<td class="pchq-f3-num">' . (int) $m['cashier'] . '</td>'
			. '<td class="pchq-f3-num">' . (int) $m['orders'] . '</td>'
			. '<td class="pchq-f3-num">' . esc_html( Orders::money( (int) $m['sales_cents'] ) ) . '</td>'
			. '<td class="pchq-f3-num">' . self::cents( $m['cost_valid_cents'] ) . '</td>'
			. '<td class="pchq-f3-num">' . self::cents( $m['cost_high_cents'] ) . '</td>'
			. '<td class="pchq-f3-num">' . ( null === $m['roas'] ? '<span class="pchq-f3-muted">—</span>' : esc_html( number_format( (float) $m['roas'], 2 ) ) ) . '</td>';
	}

	/** 一个素材的小卡片：缩略图、编号、所属店铺、属性。 */
	private static function card( array $c, array $names, array $vocab ): string {
		$attrs = array();
		foreach ( (array) $c['attrs'] as $a => $v ) {
			$attrs[] = self::attr_label( (string) $a ) . '：' . (string) ( $vocab[ $a . ':' . $v ]['label'] ?? $v );
		}
		$img = '' !== $c['thumb']
			? '<a href="' . esc_url( $c['thumb'] ) . '" target="_blank" rel="noopener noreferrer"><img src="' . esc_url( $c['thumb'] ) . '" alt="' . esc_attr( $c['code'] ) . '" loading="lazy" referrerpolicy="no-referrer"></a>'
			: '<div class="pchq-cr-noimg">没有缩略图</div>';
		return '<div class="pchq-cr-card">' . $img . '<strong>' . esc_html( $c['code'] ) . '</strong> · ' . esc_html( $names[ $c['site_id'] ] ?? '店铺 #' . $c['site_id'] )
			. ( $attrs ? '<br><span class="pchq-f3-muted">' . esc_html( implode( '，', $attrs ) ) . '</span>' : '' )
			. '<br><span class="pchq-f3-muted">有效访客 ' . (int) $c['sum']['valid_visitors'] . ' · ' . esc_html( $c['first_day'] === $c['last_day'] ? $c['first_day'] : $c['first_day'] . ' 至 ' . $c['last_day'] ) . '</span></div>';
	}

	/** 分组名（纯文字）。 */
	public static function group_label( array $g, string $mode, string $by, array $creatives, array $names, array $buyers, array $vocab ): string {
		$key = (string) $g['key'];
		switch ( $mode ) {
			case 'creative':
				$c = $creatives[ $key ];
				return $c['code'] . ' · ' . ( $names[ $c['site_id'] ] ?? '店铺 #' . $c['site_id'] );
			case 'site':
				$id    = (int) substr( $key, 5 );
				$buyer = (string) ( $buyers[ $id ] ?? '' );
				return ( $names[ $id ] ?? '店铺 #' . $id ) . ( '' !== $buyer ? '（' . $buyer . '）' : '' );
			case 'buyer':
				$name = substr( $key, 6 );
				return '' !== $name ? $name : '未填投手';
			default:
				if ( '#unreg' === $key ) {
					return '未登记素材（' . Creatives::UNREGISTERED . '）';
				}
				$value = substr( $key, 5 );
				if ( '' === $value ) {
					return '没填' . self::attr_label( $by );
				}
				return (string) ( $vocab[ $by . ':' . $value ]['label'] ?? $value ) . '（' . $by . '-' . $value . '）';
		}
	}

	public static function render(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			return;
		}
		$f = self::filters( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 只读筛选。

		echo '<div class="wrap pchq pcp">';
		echo '<header class="pcp-head"><div><h1>素材对比</h1><p>各店每天上报的“按素材”数据，跨店对比哪种素材更好。默认按素材属性（例如钩子：检测报告 对比 价格）看所有店；新店接入总后台并开始上报后自动出现。</p></div><a class="button" href="' . esc_url( self::url( array(), self::VOCAB_SLUG ) ) . '">素材词表</a></header>';
		self::show_flash();
		if ( ! Creatives::ready() ) {
			echo '<div class="pcp-flash is-bad">素材数据表还没准备好，请刷新页面。</div></div>';
			return;
		}

		$vocab = Creatives::vocab_all();
		$span  = Creatives::data_span();
		$known = array_keys( Sites::all() );
		[ $site_names, ] = Creatives::site_labels( array_merge( $known, $span['site_ids'] ) );
		asort( $site_names );

		$rows      = Creatives::rows_between( $f['from'], $f['to'], $f['sites'] );
		$creatives = Creatives::filter_attr( Creatives::fold( $rows ), $f['f_attr'], $f['f_value'] );
		[ $names, $buyers ] = Creatives::site_labels( array_merge( array_keys( $site_names ), array_column( $creatives, 'site_id' ) ) );
		$attrs = array();
		foreach ( $vocab as $item ) {
			$attrs[ $item['attr'] ] = true;
		}
		foreach ( $creatives as $c ) {
			foreach ( array_keys( (array) $c['attrs'] ) as $a ) {
				$attrs[ $a ] = true;
			}
		}
		$attrs = array_keys( $attrs );
		$cmp   = Creatives::compare( $creatives, $f['mode'], $f['by'], $buyers );

		// 筛选
		echo '<section class="pcp-card"><div class="pcp-card__head"><h2>' . esc_html( $f['from'] === $f['to'] ? $f['from'] : $f['from'] . ' 至 ' . $f['to'] ) . '</h2><span class="pcp-hint">日期是店铺时区的日期（各店上报的那一天）</span></div>';
		echo '<nav class="pchq-cr-modes" aria-label="对比方式">';
		foreach ( self::MODES as $key => $label ) {
			echo '<a class="button' . ( $f['mode'] === $key ? ' button-primary' : '' ) . '" href="' . esc_url( self::url( self::filter_args( $f, array( 'mode' => $key ) ) ) ) . '"' . ( $f['mode'] === $key ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="pchq-f3-filters"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="mode" value="' . esc_attr( $f['mode'] ) . '">';
		foreach ( array( 'yesterday' => '昨天', '7d' => '近 7 天', '30d' => '近 30 天', 'month' => '本月' ) as $key => $label ) {
			echo '<a class="button' . ( $f['preset'] === $key ? ' button-primary' : '' ) . '" href="' . esc_url( self::url( array_diff_key( self::filter_args( $f, array( 'range' => $key ) ), array( 'from' => 1, 'to' => 1 ) ) ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '<label>从<input type="date" name="from" value="' . esc_attr( $f['from'] ) . '"></label><label>到<input type="date" name="to" value="' . esc_attr( $f['to'] ) . '"></label>';
		if ( 'attr' === $f['mode'] ) {
			echo '<label>对比哪个属性<select name="by">';
			foreach ( $attrs as $a ) {
				echo '<option value="' . esc_attr( $a ) . '"' . selected( $f['by'], $a, false ) . '>' . esc_html( self::attr_label( $a ) . '（' . $a . '）' ) . '</option>';
			}
			echo '</select></label>';
		} else {
			echo '<input type="hidden" name="by" value="' . esc_attr( $f['by'] ) . '">';
		}
		echo '<label>只看带这个属性的素材<select name="only"><option value="">全部素材</option>';
		$cur = '' !== $f['f_attr'] ? $f['f_attr'] . ':' . $f['f_value'] : '';
		foreach ( $vocab as $key => $item ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $cur, $key, false ) . '>' . esc_html( self::attr_label( $item['attr'] ) . '：' . $item['label'] . '（' . $item['attr'] . '-' . $item['value'] . '）' . ( $item['active'] ? '' : ' · 已停用' ) ) . '</option>';
		}
		echo '</select></label>';
		if ( $site_names ) {
			$picked = array_intersect_key( $site_names, array_flip( $f['sites'] ) );
			echo '<details class="pchq-cr-pick"' . ( $f['sites'] ? ' open' : '' ) . '><summary>店铺：' . esc_html( $picked ? '已选 ' . count( $picked ) . ' 家（' . implode( '、', array_slice( $picked, 0, 3 ) ) . ( count( $picked ) > 3 ? '…' : '' ) . '）' : '全部 ' . count( $site_names ) . ' 家' ) . '</summary>';
			echo '<p class="pcp-hint">不勾 = 全部店铺</p><div class="pchq-cr-sites">';
			foreach ( $site_names as $id => $name ) {
				echo '<label><input type="checkbox" name="sites[]" value="' . (int) $id . '"' . checked( in_array( (int) $id, $f['sites'], true ), true, false ) . '>' . esc_html( $name ) . '</label>';
			}
			echo '</div></details>';
		}
		echo '<button type="submit" class="button">查看</button></form>';

		// 对比表
		$heads = '';
		foreach ( self::columns() as [ $name, $note ] ) {
			$heads .= '<th scope="col" class="pchq-f3-num pchq-cr-head" title="' . esc_attr( $note ) . '">' . esc_html( $name ) . '<small>' . esc_html( $note ) . '</small></th>';
		}
		$first = 'attr' === $f['mode'] ? self::attr_label( $f['by'] ) . '（' . $f['by'] . '）' : array( 'creative' => '素材 · 店铺', 'site' => '店铺（投手）', 'buyer' => '投手' )[ $f['mode'] ];
		echo '<div class="pcp-scroll"><table class="pcp-matrix"><thead><tr><th scope="col">' . esc_html( $first ) . '</th><th scope="col" class="pchq-f3-num pchq-cr-head">素材 / 店铺<small>这一行包含的素材数和店铺数</small></th>' . $heads . '</tr></thead><tbody>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $heads 已转义。
		$total = $cmp['total'];
		echo '<tr class="pchq-f3-level1"><td>合计' . ( $total['thin'] ? '<span class="pchq-cr-tag">样本不足</span>' : '' ) . '</td><td class="pchq-f3-num">' . count( $creatives ) . ' / ' . count( array_unique( array_column( $creatives, 'site_id' ) ) ) . '</td>' . self::cells( $total ) . '</tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cells() 已转义。
		if ( ! $cmp['groups'] ) {
			echo '<tr><td colspan="14" class="pcp-hint">这段时间没有素材数据。店铺每天会上报前一天的数据；补登记或补导入花费后会重报受影响的日期。</td></tr>';
		}
		foreach ( $cmp['groups'] as $g ) {
			$m     = $g['metrics'];
			$label = self::group_label( $g, $f['mode'], $f['by'], $creatives, $names, $buyers, $vocab );
			$cards = '';
			foreach ( array_slice( $g['creatives'], 0, 60 ) as $cid ) {
				$cards .= self::card( $creatives[ $cid ], $names, $vocab );
			}
			if ( count( $g['creatives'] ) > 60 ) {
				$cards .= '<div class="pchq-cr-card pchq-f3-muted">还有 ' . ( count( $g['creatives'] ) - 60 ) . ' 个素材；可切到“按单个素材”查看。</div>';
			}
			$site_list = array();
			foreach ( $g['sites'] as $sid ) {
				$site_list[] = $names[ $sid ] ?? '店铺 #' . $sid;
			}
			echo '<tr class="' . ( $m['thin'] ? 'pchq-cr-thin' : '' ) . '"><td><details class="pchq-cr-group"><summary>' . esc_html( $label ) . ( $m['thin'] ? '<span class="pchq-cr-tag">样本不足</span>' : '' ) . '</summary>'
				. '<p class="pcp-hint">所属店铺：' . esc_html( implode( '、', $site_list ) ) . '</p><div class="pchq-cr-list">' . $cards . '</div></details></td>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- card() 已转义。
				. '<td class="pchq-f3-num">' . count( $g['creatives'] ) . ' / ' . count( $g['sites'] ) . '</td>' . self::cells( $m ) . '</tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</tbody></table></div>';
		echo '<p class="pcp-hint">点分组名可以看里面的素材缩略图和所属店铺。有效访客少于 ' . (int) Creatives::MIN_SAMPLE . ' 的行置灰、标“样本不足”，排在后面，结论只作参考。“未登记素材”是广告名里没有编号或编号没在素材库登记的访问；花费只算导入了的广告。</p></section>';

		self::render_exports( $f, $span );
		echo '</div>';
	}

	private static function render_exports( array $f, array $span ): void {
		$total = Creatives::total_valid_visitors();
		$last  = (int) get_option( Creatives::CHECKPOINT_OPTION, 0 );
		$next  = ( intdiv( $total, Creatives::CHECKPOINT_STEP ) + 1 ) * Creatives::CHECKPOINT_STEP;
		echo '<h2 class="pcp-section">导出</h2><section class="pcp-card"><div class="pcp-card__head"><h2>检查点导出</h2><span class="pcp-hint">全部店铺合计有效访客每跨过 ' . esc_html( number_format( Creatives::CHECKPOINT_STEP ) ) . ' 的整数倍，自动生成一份全量导出（CSV + JSON）</span></div>';
		echo '<p>现在合计有效访客 <strong>' . esc_html( number_format( $total ) ) . '</strong>，上一个检查点 ' . esc_html( $last ? number_format( $last ) : '还没有' ) . '，下一个在 ' . esc_html( number_format( max( $next, $last + Creatives::CHECKPOINT_STEP ) ) ) . '。' . ( $span['from'] ? '数据从 ' . esc_html( $span['from'] ) . ' 到 ' . esc_html( $span['to'] ) . '。' : '' ) . '</p>';
		$list = Creatives::exports();
		if ( $list ) {
			echo '<div class="pcp-scroll"><table class="pcp-matrix"><thead><tr><th>生成时间</th><th>类型</th><th>日期范围</th><th class="pchq-f3-num">行数</th><th class="pchq-f3-num">有效访客</th><th>下载</th></tr></thead><tbody>';
			foreach ( $list as $e ) {
				$dl = static fn( string $ext ): string => '<a class="button button-small" href="' . esc_url( Menu::action_url( 'pchq_creatives_download', array( 'id' => (string) $e['id'], 'ext' => $ext ) ) ) . '">' . strtoupper( $ext ) . '</a>';
				echo '<tr><td>' . esc_html( wp_date( 'Y-m-d H:i', (int) $e['created'] ) ) . '</td><td>' . esc_html( 'checkpoint' === $e['kind'] ? '检查点 ' . number_format( (int) $e['mark'] ) : '手动' ) . '</td><td>' . esc_html( $e['from'] ? $e['from'] . ' 至 ' . $e['to'] : '—' ) . '</td><td class="pchq-f3-num">' . (int) $e['rows'] . '</td><td class="pchq-f3-num">' . (int) $e['valid_visitors'] . '</td><td>' . $dl( 'csv' ) . ' ' . $dl( 'json' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $dl 已转义。
			}
			echo '</tbody></table></div>';
		} else {
			echo '<p class="pcp-hint">还没有生成过检查点导出。</p>';
		}
		echo '</section>';

		echo '<section class="pcp-card"><div class="pcp-card__head"><h2>手动导出</h2><span class="pcp-hint">按上面的日期范围和店铺导出每天每个素材一行，附按属性的汇总（JSON 里）</span></div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pchq-f3-form">';
		wp_nonce_field( 'pchq_creatives_export' );
		echo '<input type="hidden" name="action" value="pchq_creatives_export"><input type="hidden" name="from" value="' . esc_attr( $f['from'] ) . '"><input type="hidden" name="to" value="' . esc_attr( $f['to'] ) . '">';
		foreach ( $f['sites'] as $sid ) {
			echo '<input type="hidden" name="sites[]" value="' . (int) $sid . '">';
		}
		echo '<label>格式<select name="format"><option value="csv">CSV（Excel 打开）</option><option value="json">JSON（含汇总）</option></select></label>';
		echo '<label style="flex-direction:row;align-items:center"><input type="checkbox" name="keep" value="1"> 同时保存到上面的导出列表</label>';
		echo '<button type="submit" class="button button-primary">导出 ' . esc_html( $f['from'] . ' 至 ' . $f['to'] ) . '</button></form></section>';
	}

	// ---------- 导出和下载 ----------

	private static function send( string $filename, string $type, string $body ): void {
		nocache_headers();
		header( 'Content-Type: ' . $type . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 下载文件。
		exit;
	}

	public static function handle_export(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( '只有总后台管理员可以导出。', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'pchq_creatives_export' );
		$f      = self::filters( $_POST );
		$format = 'json' === ( $_POST['format'] ?? '' ) ? 'json' : 'csv';
		if ( ! empty( $_POST['keep'] ) ) {
			$entry = Creatives::write_export( 'manual', $f['from'], $f['to'], $f['sites'] );
			if ( is_wp_error( $entry ) ) {
				self::flash( 'error', $entry->get_error_message() );
			} else {
				self::flash( 'success', '已保存到导出列表（' . (int) $entry['rows'] . ' 行）。' );
			}
			wp_safe_redirect( self::url( self::filter_args( $f ) ) );
			exit;
		}
		$payload = Creatives::build_export( 'manual', $f['from'], $f['to'], $f['sites'] );
		$name    = 'creatives-' . $f['from'] . '-' . $f['to'] . '.' . $format;
		if ( 'json' === $format ) {
			self::send( $name, 'application/json', (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
		}
		self::send( $name, 'text/csv', Creatives::export_csv( $payload ) );
	}

	public static function handle_download(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( '只有总后台管理员可以下载导出。', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'pchq_creatives_download' );
		$id   = isset( $_GET['id'] ) && is_string( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';
		$ext  = 'json' === ( $_GET['ext'] ?? '' ) ? 'json' : 'csv';
		$file = 1 === preg_match( '/^\d{8}-\d{6}-[a-f0-9]{16}$/D', $id ) ? Creatives::export_file( $id, $ext ) : '';
		if ( '' === $file ) {
			wp_die( '这份导出已经不在了（只保留最近 ' . (int) Creatives::EXPORT_KEEP . ' 份）。', '', array( 'response' => 404 ) );
		}
		self::send( basename( $file ), 'json' === $ext ? 'application/json' : 'text/csv', (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	// ---------- 词表 ----------

	public static function handle_vocab(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( '只有总后台管理员可以修改词表。', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'pchq_creatives_vocab_save' );
		$posted = array();
		foreach ( isset( $_POST['items'] ) && is_array( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : array() as $key => $p ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 下面逐项清理。
			if ( ! is_string( $key ) || ! is_array( $p ) ) {
				continue;
			}
			$posted[ $key ] = array(
				'label'    => sanitize_text_field( (string) ( $p['label'] ?? '' ) ),
				'active'   => ! empty( $p['active'] ),
				'position' => (string) ( $p['position'] ?? '' ),
			);
		}
		$new = array();
		foreach ( array( 'attr', 'value', 'label', 'position' ) as $k ) {
			$new[ $k ] = isset( $_POST['new'][ $k ] ) && is_string( $_POST['new'][ $k ] ) ? sanitize_text_field( wp_unslash( $_POST['new'][ $k ] ) ) : '';
		}
		if ( '' === $new['position'] ) {
			unset( $new['position'] );
		}
		$plan = Creatives::vocab_changes( Creatives::vocab_all(), $posted, $new );
		if ( $plan['errors'] ) {
			self::flash( 'error', '没有保存：' . implode( ' ', $plan['errors'] ) );
		} elseif ( ! $plan['rows'] ) {
			self::flash( 'success', '没有改动，词表版本不变。' );
		} else {
			$ver = Creatives::vocab_save( $plan['rows'] );
			self::flash( is_wp_error( $ver ) ? 'error' : 'success', is_wp_error( $ver ) ? $ver->get_error_message() : '已保存 ' . count( $plan['rows'] ) . ' 条改动，词表版本升到 ' . (int) $ver . '。店铺下次拉取词表时整体更新。' );
		}
		wp_safe_redirect( self::url( array(), self::VOCAB_SLUG ) );
		exit;
	}

	public static function render_vocab(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			return;
		}
		echo '<div class="wrap pchq pcp">';
		echo '<header class="pcp-head"><div><h1>素材词表</h1><p>投手登记素材时可选的属性和取值，所有店铺共用。取值会写进广告名（例如 hook-lab），已有取值不能删除，不再用的请停用。每次保存有改动，版本号加一，店铺按版本拉取后整体覆盖本地词表。</p></div><a class="button" href="' . esc_url( self::url() ) . '">素材对比</a></header>';
		self::show_flash();
		if ( ! Creatives::ready() ) {
			echo '<div class="pcp-flash is-bad">素材数据表还没准备好，请刷新页面。</div></div>';
			return;
		}
		$vocab = Creatives::vocab_all();
		$ver   = (int) get_option( Creatives::VOCAB_VER, 0 );
		$by    = array();
		foreach ( $vocab as $key => $item ) {
			$by[ $item['attr'] ][ $key ] = $item;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'pchq_creatives_vocab_save' );
		echo '<input type="hidden" name="action" value="pchq_creatives_vocab_save">';
		echo '<section class="pcp-card"><div class="pcp-card__head"><h2>当前词表 · 版本 ' . (int) $ver . '</h2><span class="pcp-hint">顺序小的排前面；必填属性（' . esc_html( implode( '、', Creatives::REQUIRED ) ) . '）至少保留一个启用的取值</span></div>';
		echo '<div class="pcp-scroll"><table class="pcp-matrix pchq-cr-vocab"><thead><tr><th>属性</th><th>取值（广告名里）</th><th>中文名</th><th>顺序</th><th>启用</th></tr></thead><tbody>';
		foreach ( $by as $attr => $items ) {
			foreach ( $items as $key => $item ) {
				$name = 'items[' . $key . ']';
				echo '<tr class="' . ( $item['active'] ? '' : 'pchq-cr-off' ) . '"><td>' . esc_html( self::attr_label( $attr ) . '（' . $attr . '）' ) . ( $item['required'] ? '<span class="pchq-cr-tag">必填</span>' : '' ) . '</td>'
					. '<td><code>' . esc_html( $attr . '-' . $item['value'] ) . '</code></td>'
					. '<td><input type="text" name="' . esc_attr( $name . '[label]' ) . '" value="' . esc_attr( $item['label'] ) . '" maxlength="60" required aria-label="' . esc_attr( $attr . '-' . $item['value'] . ' 的中文名' ) . '"></td>'
					. '<td><input type="number" name="' . esc_attr( $name . '[position]' ) . '" value="' . (int) $item['position'] . '" min="0" max="9999" aria-label="顺序"></td>'
					. '<td><label><input type="checkbox" name="' . esc_attr( $name . '[active]' ) . '" value="1"' . checked( $item['active'], true, false ) . '> ' . ( $item['active'] ? '启用中' : '已停用' ) . '</label></td></tr>';
			}
		}
		echo '</tbody></table></div></section>';
		echo '<section class="pcp-card"><div class="pcp-card__head"><h2>新增取值</h2><span class="pcp-hint">可以加到已有属性下，也可以新建属性（新属性是选填的）；属性名和取值只能用小写字母和数字</span></div><div class="pchq-f3-form">';
		echo '<label>属性<input type="text" name="new[attr]" list="pchq-cr-attrs" maxlength="16" pattern="[a-z][a-z0-9]{0,15}" placeholder="hook"></label><datalist id="pchq-cr-attrs">';
		foreach ( array_keys( $by ) as $attr ) {
			echo '<option value="' . esc_attr( $attr ) . '">' . esc_html( self::attr_label( $attr ) ) . '</option>';
		}
		echo '</datalist><label>取值<input type="text" name="new[value]" maxlength="24" pattern="[a-z0-9]{1,24}" placeholder="review"></label><label>中文名<input type="text" name="new[label]" maxlength="60" placeholder="用户评价"></label><label>顺序（可空）<input type="number" name="new[position]" min="0" max="9999"></label></div></section>';
		echo '<p><button type="submit" class="button button-primary">保存词表</button></p></form></div>';
	}
}
