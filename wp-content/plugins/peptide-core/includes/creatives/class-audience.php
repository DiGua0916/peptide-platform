<?php
/**
 * 单条素材的人群画像和规则分析（2026-11-13，店主：分析整条素材带来的一群人，不是单个客户）。
 *
 * 纯函数，不碰数据库：
 * - keys()：一位访客（取归因的那次访问）落在每个维度的哪个取值；
 * - add() / finish()：按素材累计 → 每个维度的人数、占比、意向、加购、结账、下单、客单价、浏览行为；
 * - analyse()：按固定规则写结论（来的是什么人、质量、谁在买、卡在哪、建议），每条结论带依据数字。
 *   规则结论也作为输入交给总后台 AI（素材人群分析人设）做深度分析。
 *
 * 口径：
 * - 访客数含无效访客；加购 / 结账 / 付款人数只算有效访客（和素材看板一致）；订单不论意向都算。
 * - “平均”= 同一时间段全部付费访客（所有素材合在一起）。
 * - 样本不到 MIN_CONCLUDE 人只给数据不下结论；某个人群不到 MIN_SEGMENT 人不参与“谁在买 / 卡在哪 / 建议”。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Creatives;

defined( 'ABSPATH' ) || exit;

final class Audience {

	/** 维度 => 中文名（顺序 = 页面上的顺序；前 FIRST 个直接显示，其余收在“更多维度”里）。 */
	public const DIMS = array(
		'os'         => '系统',
		'model'      => '机型',
		'browser'    => '浏览器',
		'region'     => '省 / 州',
		'tier'       => '机型档次',
		'os_version' => '系统版本',
		'device'     => '设备类型',
		'screen'     => '屏幕尺寸',
		'country'    => '国家',
		'city'       => '城市',
		'isp'        => 'ISP',
		'ip_type'    => 'IP 类型',
		'language'   => '语言',
		'timezone'   => '时区',
		'placement'  => '版位',
	);
	public const FIRST = 4;

	/** 参与“同类型 / 不同项”比较的维度（城市、ISP、时区、屏幕太散，只看分布不下结论）。 */
	public const COMPARE = array( 'os', 'tier', 'browser', 'device', 'country', 'region', 'ip_type', 'language', 'placement', 'model' );

	/** “谁在买”按这几个维度拆人群。 */
	public const SEGMENTS = array( 'os', 'tier', 'browser' );

	public const MIN_CONCLUDE = 100;
	public const MIN_SEGMENT  = 30;
	public const COMMON_SHARE = 0.5;  // 占比过半算“共同特征”
	public const DIFF_PP      = 10;   // 和平均差 10 个百分点以上算“不同项”
	public const TOP          = 8;    // 每个维度最多列几项（其余合并为“其他”）

	public const ALL = '*';

	private const BAD_IP = array( 'hosting', 'vpn', 'datacenter', 'proxy', 'tor' );

	private const VALUE_LABELS = array(
		'ip_type'  => array( 'residential' => '家庭宽带', 'mobile' => '手机网络', 'hosting' => '机房', 'vpn' => 'VPN', 'datacenter' => '机房' ),
		'device'   => array( 'mobile' => '手机', 'tablet' => '平板', 'desktop' => '电脑' ),
		'country'  => array( 'US' => '美国', 'CA' => '加拿大', 'GB' => '英国', 'AU' => '澳大利亚', 'NZ' => '新西兰', 'MX' => '墨西哥', 'DE' => '德国', 'FR' => '法国', 'IE' => '爱尔兰', 'PR' => '波多黎各', 'CN' => '中国', 'HK' => '中国香港', 'TW' => '中国台湾', 'PH' => '菲律宾', 'IN' => '印度', 'BR' => '巴西' ),
		'language' => array( 'en' => '英语', 'es' => '西班牙语', 'zh' => '中文', 'fr' => '法语', 'de' => '德语', 'pt' => '葡萄牙语', 'ja' => '日语', 'ko' => '韩语', 'vi' => '越南语', 'tl' => '他加禄语' ),
	);

	// ---------- 取值 ----------

	/** 一次访问在每个维度的取值（没有 = unknown）。 @return array<string,string> */
	public static function keys( array $v ): array {
		$os    = (string) ( $v['os'] ?? '' );
		$model = self::model_name( (string) ( $v['device_model'] ?? '' ) );
		$ver   = (string) ( $v['os_version'] ?? '' );
		$major = '' !== $ver ? (string) strtok( $ver, '.' ) : '';
		$lang  = strtolower( (string) strtok( (string) ( $v['language'] ?? '' ), '-_' ) );
		$cc    = strtoupper( (string) ( $v['geo_country'] ?? '' ) );
		$out   = array(
			'os'         => $os,
			'model'      => $model,
			'browser'    => self::browser( (string) ( $v['ua'] ?? '' ), (string) ( $v['in_app'] ?? '' ) ),
			'region'     => (string) ( $v['geo_region'] ?? '' ),
			'tier'       => self::tier( $os, $model, (string) ( $v['device'] ?? '' ) ),
			'os_version' => '' !== $os && '' !== $major ? $os . ' ' . $major : '',
			'device'     => (string) ( $v['device'] ?? '' ),
			'screen'     => self::screen( (string) ( $v['screen'] ?? '' ) ),
			'country'    => 1 === preg_match( '/\A[A-Z]{2}\z/', $cc ) ? $cc : '',
			'city'       => (string) ( $v['geo_city'] ?? '' ),
			'isp'        => mb_substr( (string) ( $v['isp'] ?? '' ), 0, 60 ),
			'ip_type'    => (string) ( $v['ip_type'] ?? '' ),
			'language'   => 1 === preg_match( '/\A[a-z]{2,3}\z/', $lang ) ? $lang : '',
			'timezone'   => (string) ( $v['timezone'] ?? '' ),
			'placement'  => (string) ( $v['placement'] ?? '' ),
		);
		return array_map( static fn( $x ) => '' !== trim( $x ) ? trim( $x ) : 'unknown', $out );
	}

	/** “Galaxy S23（SM-S911U）”→“Galaxy S23”：同一机型不同地区代码合在一起。 */
	public static function model_name( string $model ): string {
		$model = trim( $model );
		$cut   = mb_strpos( $model, '（' );
		return false !== $cut ? trim( mb_substr( $model, 0, $cut ) ) : $model;
	}

	public static function browser( string $ua, string $in_app ): string {
		if ( 'fb' === $in_app ) {
			return 'FB 内置';
		}
		if ( 'ig' === $in_app ) {
			return 'IG 内置';
		}
		if ( '' === trim( $ua ) ) {
			return '';
		}
		foreach ( array( '/SamsungBrowser/' => '三星浏览器', '/EdgA?\/|EdgiOS/' => 'Edge', '/Firefox|FxiOS/' => 'Firefox', '/OPR\/|Opera/' => 'Opera', '/CriOS|Chrome\//' => 'Chrome', '/Safari\//' => 'Safari' ) as $re => $name ) {
			if ( preg_match( $re, $ua ) ) {
				return $name;
			}
		}
		return '其他';
	}

	/** 机型档次：只是推断消费能力的线索，不是事实。 */
	public static function tier( string $os, string $model, string $device ): string {
		if ( 'desktop' === $device || in_array( $os, array( 'Windows', 'macOS', 'Linux', 'ChromeOS' ), true ) ) {
			return '电脑';
		}
		if ( preg_match( '/\biPhone (?:\d+ )?(?:Pro|Air)\b|\biPad Pro\b|Galaxy S\d+ Ultra|Galaxy Z (?:Fold|Flip)|Pixel \d+ Pro/i', $model ) || preg_match( '/\biPhone Air\b/', $model ) ) {
			return '高端';
		}
		if ( preg_match( '/\biPhone (\d+)/', $model, $m ) ) {
			return (int) $m[1] >= 13 ? '中端' : '入门';
		}
		if ( preg_match( '/\biPhone (?:X|XR|XS|SE|8)\b/', $model ) ) {
			return '入门';
		}
		if ( preg_match( '/Galaxy S\d+|Pixel \d|Galaxy A[5-9]\d|\biPad\b/i', $model ) ) {
			return '中端';
		}
		if ( preg_match( '/Galaxy A[0-4]\d|Galaxy A0/i', $model ) ) {
			return '入门';
		}
		return '';
	}

	public static function screen( string $s ): string {
		return preg_match( '/\A(\d{3,4})\s*[x×*]\s*(\d{3,4})\z/u', trim( $s ), $m ) ? $m[1] . '×' . $m[2] : '';
	}

	public static function label( string $dim, string $value ): string {
		if ( 'unknown' === $value ) {
			return '未知';
		}
		return self::VALUE_LABELS[ $dim ][ $value ] ?? $value;
	}

	// ---------- 累计 ----------

	private static function blank(): array {
		return array( 'visitors' => 0, 'valid' => 0, 'mid_high' => 0, 'invalid' => 0, 'carted' => 0, 'checkout' => 0, 'paid' => 0, 'orders' => 0, 'sales_cents' => 0, 'dwell_ms' => 0, 'dwell_n' => 0, 'pv' => 0, 'scroll75' => 0, 'bad_ip' => 0 );
	}

	/**
	 * 把一位访客记进某条素材（和“全部”）。
	 *
	 * @param array  $acc      累计（引用）
	 * @param string $code     素材编号
	 * @param array  $keys     keys() 的结果
	 * @param string $tier     意向分层
	 * @param array  $facts    {carted,checkout,orders:array<int,int>}
	 * @param array  $behavior {dwell_ms:?int, pageviews:int, scroll_depth:int}
	 */
	public static function add( array &$acc, string $code, array $keys, string $tier, array $facts, array $behavior ): void {
		$one = self::blank();
		$one['visitors'] = 1;
		$valid           = Intent_Tiers::INVALID !== $tier;
		$one['valid']    = $valid ? 1 : 0;
		$one['invalid']  = $valid ? 0 : 1;
		$one['mid_high'] = in_array( $tier, array( 'high', 'mid' ), true ) ? 1 : 0;
		$one['carted']   = $valid && ! empty( $facts['carted'] ) ? 1 : 0;
		$one['checkout'] = $valid && ! empty( $facts['checkout'] ) ? 1 : 0;
		$orders          = (array) ( $facts['orders'] ?? array() );
		$one['paid']     = $orders ? 1 : 0;
		$one['orders']   = count( $orders );
		$one['sales_cents'] = (int) array_sum( $orders );
		if ( null !== ( $behavior['dwell_ms'] ?? null ) ) {
			$one['dwell_ms'] = max( 0, (int) $behavior['dwell_ms'] );
			$one['dwell_n']  = 1;
		}
		$one['pv']       = max( 0, (int) ( $behavior['pageviews'] ?? 0 ) );
		$one['scroll75'] = (int) ( $behavior['scroll_depth'] ?? 0 ) >= 75 ? 1 : 0;
		$one['bad_ip']   = in_array( (string) ( $keys['ip_type'] ?? '' ), self::BAD_IP, true ) ? 1 : 0;
		foreach ( array_unique( array( $code, self::ALL ) ) as $c ) {
			$acc[ $c ]['total'] = self::sum( $acc[ $c ]['total'] ?? self::blank(), $one );
			foreach ( $keys as $dim => $value ) {
				$acc[ $c ]['dims'][ $dim ][ $value ] = self::sum( $acc[ $c ]['dims'][ $dim ][ $value ] ?? self::blank(), $one );
			}
		}
	}

	private static function sum( array $a, array $b ): array {
		foreach ( $b as $k => $v ) {
			$a[ $k ] = (int) ( $a[ $k ] ?? 0 ) + (int) $v;
		}
		return $a;
	}

	/** 计数 → 比率。 */
	public static function rates( array $c ): array {
		$n = max( 0, (int) $c['visitors'] );
		$v = max( 0, (int) $c['valid'] );
		$r = static fn( $a, $b ) => $b > 0 ? $a / $b : null;
		return $c + array(
			'mid_high_rate' => $r( $c['mid_high'], $n ),
			'cart_rate'     => $r( $c['carted'], $v ),
			'checkout_of_cart' => $r( $c['checkout'], $c['carted'] ),
			'paid_of_checkout' => $r( $c['paid'], $c['checkout'] ),
			'order_rate'    => $r( $c['paid'], $n ),
			'aov_cents'     => $c['orders'] > 0 ? (int) round( $c['sales_cents'] / $c['orders'] ) : null,
			'dwell_s'       => $c['dwell_n'] > 0 ? (int) round( $c['dwell_ms'] / $c['dwell_n'] / 1000 ) : null,
			'pv_avg'        => $r( $c['pv'], $n ),
			'scroll75_rate' => $r( $c['scroll75'], $n ),
			'bad_ip_rate'   => $r( $c['bad_ip'], $n ),
			'invalid_rate'  => $r( $c['invalid'], $n ),
		);
	}

	/**
	 * 累计 → 每条素材的画像。
	 *
	 * @return array<string,array{total:array,dims:array<string,list<array>>}> 素材编号（和 '*' 全部）=> 画像
	 */
	public static function finish( array $acc ): array {
		$out = array();
		foreach ( $acc as $code => $p ) {
			$total = self::rates( $p['total'] );
			$dims  = array();
			foreach ( array_keys( self::DIMS ) as $dim ) {
				$list = array();
				foreach ( (array) ( $p['dims'][ $dim ] ?? array() ) as $value => $c ) {
					$list[] = array( 'value' => (string) $value, 'label' => self::label( $dim, (string) $value ), 'share' => $total['visitors'] > 0 ? $c['visitors'] / $total['visitors'] : 0.0 ) + self::rates( $c );
				}
				usort( $list, static fn( $a, $b ) => array( 'unknown' === $a['value'] ? 1 : 0, $b['visitors'], $a['value'] ) <=> array( 'unknown' === $b['value'] ? 1 : 0, $a['visitors'], $b['value'] ) );
				$dims[ $dim ] = $list;
			}
			$out[ (string) $code ] = array( 'total' => $total, 'dims' => $dims );
		}
		return $out;
	}

	/** 某维度某取值在“全部”里的占比。 */
	public static function avg_share( array $all, string $dim, string $value ): float {
		foreach ( (array) ( $all['dims'][ $dim ] ?? array() ) as $r ) {
			if ( $r['value'] === $value ) {
				return (float) $r['share'];
			}
		}
		return 0.0;
	}

	// ---------- 规则分析 ----------

	private static function pct( ?float $x ): string {
		return null === $x ? '—' : (string) round( $x * 100 ) . '%';
	}

	private static function money( ?int $cents ): string {
		return null === $cents ? '—' : '$' . number_format( $cents / 100, 0 );
	}

	private static function seg_name( string $dim, array $r ): string {
		return match ( $dim ) {
			'os'      => $r['label'] . ' 用户',
			'tier'    => $r['label'] . '机型',
			'browser' => $r['label'] . '浏览器',
			default   => $r['label'],
		};
	}

	/** 和平均比：高于 / 低于 / 差不多（相对差 20% 以内算差不多）。 */
	private static function vs( ?float $mine, ?float $avg ): string {
		if ( null === $mine || null === $avg || $avg <= 0 ) {
			return '';
		}
		return $mine >= $avg * 1.2 ? '高于平均' : ( $mine <= $avg * 0.8 ? '低于平均' : '和平均差不多' );
	}

	/**
	 * 规则分析（纯函数）。
	 *
	 * @param array $p   finish() 里这条素材的画像
	 * @param array $all finish() 里 '*' 的画像
	 * @return array{confidence:string,confidence_text:string,headline:string,common:list<string>,diff:list<array{text:string,dir:string}>,who:list<array>,quality:list<array>,buyers:list<array>,buyer_notes:list<array>,stuck:list<array>,advice:list<array>}
	 */
	public static function analyse( array $p, array $all ): array {
		$t   = $p['total'];
		$avg = $all['total'];
		$n   = (int) $t['visitors'];
		$out = array( 'confidence' => 'low', 'confidence_text' => '', 'headline' => '', 'common' => array(), 'diff' => array(), 'who' => array(), 'quality' => array(), 'buyers' => array(), 'buyer_notes' => array(), 'stuck' => array(), 'advice' => array() );
		if ( $n < self::MIN_CONCLUDE ) {
			$out['confidence_text'] = '付费访客 ' . $n . ' 人，不到 ' . self::MIN_CONCLUDE . ' 人，只看数据，先不下结论。';
			return $out;
		}
		$out['confidence']      = $n >= 300 ? 'high' : 'mid';
		$out['confidence_text'] = '付费访客 ' . $n . ' 人，结论可信度' . ( 'high' === $out['confidence'] ? '较高' : '中等' ) . '（' . ( $n >= 300 ? '样本 ≥ 300' : '样本 100–299' ) . '）。';

		// 同类型：占比过半的特征。
		$traits = array();
		foreach ( array( 'country', 'os', 'tier', 'browser', 'device', 'language', 'ip_type' ) as $dim ) {
			$top = $p['dims'][ $dim ][0] ?? null;
			if ( $top && 'unknown' !== $top['value'] && $top['share'] >= self::COMMON_SHARE ) {
				$out['common'][] = self::DIMS[ $dim ] . '：' . $top['label'] . '（' . self::pct( $top['share'] ) . '）';
				$traits[ $dim ]  = $top;
			}
		}
		// 不同项：和平均差 DIFF_PP 个百分点以上，且这一项至少 MIN_SEGMENT 人或平均里很常见。
		$diffs = array();
		foreach ( self::COMPARE as $dim ) {
			foreach ( (array) ( $p['dims'][ $dim ] ?? array() ) as $r ) {
				if ( 'unknown' === $r['value'] ) {
					continue;
				}
				$a  = self::avg_share( $all, $dim, $r['value'] );
				$pp = ( $r['share'] - $a ) * 100;
				if ( abs( $pp ) >= self::DIFF_PP && ( $r['visitors'] >= self::MIN_SEGMENT || $a * $n >= self::MIN_SEGMENT ) ) {
					$diffs[] = array( 'abs' => abs( $pp ), 'text' => self::DIMS[ $dim ] . ' ' . $r['label'] . ' ' . self::pct( $r['share'] ) . '（平均 ' . self::pct( $a ) . '，' . ( $pp > 0 ? '+' : '' ) . round( $pp ) . ' 个百分点）', 'dir' => $pp > 0 ? 'up' : 'down' );
				}
			}
		}
		usort( $diffs, static fn( $a, $b ) => $b['abs'] <=> $a['abs'] );
		$out['diff'] = array_map( static fn( $d ) => array( 'text' => $d['text'], 'dir' => $d['dir'] ), array_slice( $diffs, 0, 6 ) );

		// 1. 来的是什么人。
		if ( $out['common'] ) {
			$out['who'][] = array( 'text' => '主要是：' . implode( '；', $out['common'] ) . '。', 'ev' => '占比过半的特征' );
		} else {
			$out['who'][] = array( 'text' => '人群比较分散，没有哪一类占到一半以上。', 'ev' => '各维度最大的一项都不到 50%' );
		}
		$high = self::find( $p, 'tier', '高端' );
		if ( $high && $high['visitors'] >= self::MIN_SEGMENT ) {
			$ah   = self::avg_share( $all, 'tier', '高端' );
			$word = $high['share'] >= $ah + 0.1 ? '偏高' : ( $high['share'] <= $ah - 0.1 ? '偏低' : '和平均差不多' );
			$out['who'][] = array( 'text' => '高端机（iPhone Pro / Air、Galaxy S Ultra / Fold 等）占 ' . self::pct( $high['share'] ) . '，消费能力推断' . $word . '。', 'ev' => '平均 ' . self::pct( $ah ) . '；机型只是推断线索' );
		}
		$fake = (float) ( $t['bad_ip_rate'] ?? 0 );
		$out['who'][] = array( 'text' => '机房 / VPN 流量 ' . self::pct( $fake ) . '，无效访客 ' . self::pct( $t['invalid_rate'] ) . '，' . ( $fake + (float) $t['invalid_rate'] <= 0.1 ? '真人比例高。' : ( $fake + (float) $t['invalid_rate'] >= 0.25 ? '假流量偏多，注意排查版位或受众。' : '真人比例一般。' ) ), 'ev' => '平均：机房 / VPN ' . self::pct( $avg['bad_ip_rate'] ) . '，无效 ' . self::pct( $avg['invalid_rate'] ) );

		// 2. 质量。
		$q = self::vs( $t['mid_high_rate'], $avg['mid_high_rate'] );
		$out['quality'][] = array( 'text' => '中高意向 ' . self::pct( $t['mid_high_rate'] ) . ( '' !== $q ? '，' . $q : '' ) . '。', 'ev' => '平均 ' . self::pct( $avg['mid_high_rate'] ), 'level' => $q );
		$bits = array();
		if ( null !== $t['dwell_s'] ) {
			$bits[] = '平均停留 ' . self::duration( (int) $t['dwell_s'] ) . '（平均 ' . ( null !== $avg['dwell_s'] ? self::duration( (int) $avg['dwell_s'] ) : '—' ) . '）';
		}
		$bits[] = '滚到 75% 以上 ' . self::pct( $t['scroll75_rate'] ) . '（平均 ' . self::pct( $avg['scroll75_rate'] ) . '）';
		$bits[] = '人均看 ' . ( null !== $t['pv_avg'] ? number_format( (float) $t['pv_avg'], 1 ) : '—' ) . ' 页（平均 ' . ( null !== $avg['pv_avg'] ? number_format( (float) $avg['pv_avg'], 1 ) : '—' ) . '）';
		$out['quality'][] = array( 'text' => implode( '；', $bits ) . '。', 'ev' => '浏览行为' );
		if ( '高于平均' === $q ) {
			$out['quality'][] = array( 'text' => '素材承诺的内容和落地页对得上，进来的人多数是冲着产品来的。', 'ev' => '意向高于平均' );
		} elseif ( '低于平均' === $q ) {
			$out['quality'][] = array( 'text' => '进来的人兴趣不大，可能素材的钩子太泛，或和落地页内容对不上。', 'ev' => '意向低于平均' );
		}

		// 3. 谁在买，谁不买。
		$segs = array();
		foreach ( self::SEGMENTS as $dim ) {
			foreach ( (array) ( $p['dims'][ $dim ] ?? array() ) as $r ) {
				if ( 'unknown' !== $r['value'] && $r['visitors'] >= self::MIN_SEGMENT ) {
					$segs[] = array( 'dim' => $dim, 'name' => self::seg_name( $dim, $r ) ) + $r;
				}
			}
		}
		$rate = (float) ( $t['order_rate'] ?? 0 );
		foreach ( $segs as $s ) {
			$out['buyers'][] = array( 'name' => $s['name'], 'visitors' => $s['visitors'], 'cart_rate' => $s['cart_rate'], 'order_rate' => $s['order_rate'], 'aov_cents' => $s['aov_cents'], 'mark' => null === $s['order_rate'] || $rate <= 0 ? '' : ( $s['order_rate'] >= $rate * 1.3 ? 'good' : ( $s['order_rate'] <= $rate * 0.6 ? 'bad' : '' ) ) );
		}
		$best = null;
		foreach ( $segs as $s ) {
			if ( $s['orders'] >= 2 && $rate > 0 && (float) $s['order_rate'] >= $rate * 1.3 && ( ! $best || $s['order_rate'] > $best['order_rate'] ) ) {
				$best = $s;
			}
		}
		if ( $best && $t['sales_cents'] > 0 ) {
			$out['buyer_notes'][] = array( 'text' => $best['name'] . '下单率 ' . self::pct( $best['order_rate'] ) . '（这条素材整体 ' . self::pct( $rate ) . '），贡献了 ' . self::pct( $best['orders'] / max( 1, $t['orders'] ) ) . ' 的订单、' . self::pct( $best['sales_cents'] / max( 1, $t['sales_cents'] ) ) . ' 的销售额。', 'ev' => $best['visitors'] . ' 人' );
		} elseif ( 0 === (int) $t['orders'] ) {
			$out['buyer_notes'][] = array( 'text' => '还没有订单，先看下面卡在哪一步。', 'ev' => '订单 0' );
		}

		// 4. 卡在哪：下单率明显低于整体的人群，找掉人最多的那一步。
		$weak = array();
		$seen = array();
		foreach ( $segs as $s ) {
			// 不同维度拆出来的可能是同一批人（例如“安卓”和“入门机型”完全重合），数字一样的只说一次。
			$same = $s['visitors'] . '|' . $s['carted'] . '|' . $s['checkout'] . '|' . $s['paid'];
			if ( isset( $seen[ $same ] ) ) {
				continue;
			}
			if ( $rate > 0 && null !== $s['order_rate'] && (float) $s['order_rate'] <= $rate * 0.6 ) {
				$seen[ $same ] = true;
				$step = self::stuck_step( $s, $t );
				if ( $step ) {
					$weak[]         = $s + array( 'step' => $step['step'] );
					$out['stuck'][] = array( 'seg' => $s['name'], 'step' => $step['step'], 'text' => $s['name'] . '：' . $step['text'], 'ev' => $s['visitors'] . ' 人，下单率 ' . self::pct( $s['order_rate'] ) . '（整体 ' . self::pct( $rate ) . '）' );
				}
			}
		}
		if ( ! $out['stuck'] && $n >= self::MIN_CONCLUDE ) {
			$step = self::stuck_step( $t, $avg );
			if ( $step ) {
				$out['stuck'][] = array( 'seg' => '整体', 'step' => $step['step'], 'text' => '整体：' . $step['text'], 'ev' => '和全部素材平均比' );
			}
		}

		// 5. 建议（只是建议）。
		if ( $best ) {
			$out['advice'][] = array( 'kind' => 'scale', 'text' => '加投 ' . $best['name'] . '：' . self::targeting( $best ) . '，把预算往这里挪。', 'ev' => $best['name'] . '下单率 ' . self::pct( $best['order_rate'] ) );
		}
		foreach ( $weak as $w ) {
			if ( 'pay' === $w['step'] ) {
				$out['advice'][] = array( 'kind' => 'check', 'text' => '先查再投：用 ' . $w['name'] . '的设备完整走一遍结账和付款，修好之前先别加这部分预算。', 'ev' => $w['name'] . '进结账后很少付款' );
			} elseif ( 'cart' === $w['step'] ) {
				$out['advice'][] = array( 'kind' => 'cut', 'text' => '减投 ' . $w['name'] . '：' . self::targeting( $w, true ) . '。', 'ev' => $w['name'] . '进来后很少加购' );
			}
		}
		$ah = (float) ( $avg['mid_high_rate'] ?? 0 );
		if ( $t['orders'] >= 3 && $ah > 0 && (float) $t['mid_high_rate'] >= $ah * 1.2 ) {
			$out['advice'][] = array( 'kind' => 'clone', 'text' => '做同款：这条素材吸引来的人质量高，用同样的钩子和卖点再做 2–3 个变体测试。', 'ev' => '中高意向 ' . self::pct( $t['mid_high_rate'] ) . '，平均 ' . self::pct( $ah ) );
		}
		if ( ! $out['advice'] ) {
			$out['advice'][] = array( 'kind' => 'watch', 'text' => '暂时没有特别需要调整的人群，继续观察。', 'ev' => '各人群差别不大' );
		}

		// 一句话。
		$who = array();
		foreach ( array( 'country', 'os', 'tier', 'browser' ) as $dim ) {
			if ( isset( $traits[ $dim ] ) ) {
				$who[] = 'tier' === $dim ? $traits[ $dim ]['label'] . '机' : ( 'browser' === $dim ? $traits[ $dim ]['label'] . '浏览器' : $traits[ $dim ]['label'] );
			}
		}
		$h  = '这条素材吸引的' . ( $who ? '主要是' . implode( '、', $who ) . '用户' : '人群比较分散' );
		$h .= '高于平均' === $q ? '，进站后看得认真' : ( '低于平均' === $q ? '，进站后兴趣不大' : '' );
		if ( $best ) {
			$h .= '，' . $best['name'] . '买得最多';
		}
		if ( $weak ) {
			$h .= '；' . implode( '、', array_slice( array_unique( array_column( $weak, 'name' ) ), 0, 2 ) ) . '转化明显差';
		}
		$out['headline'] = $h . '。';
		return $out;
	}

	private static function find( array $p, string $dim, string $value ): ?array {
		foreach ( (array) ( $p['dims'][ $dim ] ?? array() ) as $r ) {
			if ( $r['value'] === $value ) {
				return $r;
			}
		}
		return null;
	}

	private static function duration( int $s ): string {
		return $s >= 60 ? intdiv( $s, 60 ) . ' 分 ' . ( $s % 60 ) . ' 秒' : $s . ' 秒';
	}

	/** 掉人最多的一步（和参照比，相对差距最大的那步）。 @return array{step:string,text:string}|null */
	private static function stuck_step( array $s, array $ref ): ?array {
		$cand = array();
		foreach ( array( 'cart' => 'cart_rate', 'checkout' => 'checkout_of_cart', 'pay' => 'paid_of_checkout' ) as $step => $k ) {
			$mine = $s[ $k ] ?? null;
			$base = $ref[ $k ] ?? null;
			if ( null !== $mine && null !== $base && $base > 0 && $mine < $base ) {
				$cand[ $step ] = 1 - $mine / $base;
			}
		}
		if ( ! $cand ) {
			return null;
		}
		arsort( $cand );
		$step = (string) array_key_first( $cand );
		$p    = static fn( $x ) => self::pct( $x );
		switch ( $step ) {
			case 'cart':
				$shallow = null !== ( $s['scroll75_rate'] ?? null ) && null !== ( $ref['scroll75_rate'] ?? null ) && $s['scroll75_rate'] < $ref['scroll75_rate'] * 0.8;
				return array( 'step' => 'cart', 'text' => '进来后很少加购（加购率 ' . $p( $s['cart_rate'] ) . '，参照 ' . $p( $ref['cart_rate'] ) . '）' . ( $shallow ? '，而且没怎么往下看（滚到 75% 以上 ' . $p( $s['scroll75_rate'] ) . '），多半是随手点进来的。' : '，看了但没下手，可能价格或产品不对胃口。' ) );
			case 'checkout':
				return array( 'step' => 'checkout', 'text' => '加购了但没去结账（加购后进结账 ' . $p( $s['checkout_of_cart'] ) . '，参照 ' . $p( $ref['checkout_of_cart'] ) . '），可能是运费、总价或优惠让人犹豫。' );
		}
		return array( 'step' => 'pay', 'text' => '进了结账没付款（进结账后付款 ' . $p( $s['paid_of_checkout'] ) . '，参照 ' . $p( $ref['paid_of_checkout'] ) . '），可能是这个人群的付款方式或结账页有问题。' );
	}

	/** 在 Meta 广告后台怎么定向这个人群。 */
	private static function targeting( array $s, bool $exclude = false ): string {
		$v = (string) $s['value'];
		switch ( $s['dim'] ) {
			case 'os':
				return $exclude ? '在广告组的“设备”里把 ' . $s['label'] . ' 拆成单独的广告组控制预算，或者先暂停' : '复制一个广告组，“设备”只选 ' . $s['label'];
			case 'browser':
				if ( 'FB 内置' === $s['label'] || 'IG 内置' === $s['label'] ) {
					$pl = 'FB 内置' === $s['label'] ? 'Facebook' : 'Instagram';
					return $exclude ? '把 ' . $pl . ' 版位拆出来单独控制预算，或者先关掉' : '复制一个广告组，版位只选 ' . $pl;
				}
				return $exclude ? '这部分人是在外部浏览器打开的，Meta 不能按浏览器定向，先观察' : '这部分人是在外部浏览器打开的，Meta 不能按浏览器定向，先观察';
			case 'tier':
				return $exclude ? '在广告组的“设备”里限定较新的系统版本，排除老机型（Meta 不能直接按机型定向，这是近似做法）' : '复制一个广告组，“设备”选 iOS 并限定较新的系统版本（Meta 不能直接按机型定向，这是近似做法）';
		}
		return ( $exclude ? '减少 ' : '加大 ' ) . $v;
	}
}
