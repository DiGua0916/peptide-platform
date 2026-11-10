<?php
/**
 * 像素与 CAPI 验收（FE-09，开发文档 6.3）：对一家店跑一遍完整下单，核对发送记录（pc_px_log）里六类事件
 * 都由服务端发送成功，并且和浏览器发出的事件是同一个 event_id（事件管理器才会显示“浏览器 + 服务器”并去重）。
 *
 * 六类事件：PageView、ViewContent、AddToCart、InitiateCheckout、AddPaymentInfo、Purchase。
 *
 * 做法：
 * 1. start() 生成一个验收码（默认 30 分钟有效），给出带验收码的店铺链接；
 * 2. 验收人用这个链接打开店铺（浏览器里记一个会话 cookie），照常浏览 → 看商品 → 加购 → 结账 → 选支付 → 付款 → 回到下单成功页；
 *    验收期间这个浏览器每发出一个 Facebook 像素事件，就把事件名和 event_id 报到 POST /pixel-check，
 *    记进同一张发送记录表（platform = browser，pixel = 验收码）—— 每条一行，并发也不会互相覆盖；
 * 3. check() 把服务端的记录（platform = facebook）和浏览器的记录按事件逐个对：服务端成功、浏览器发过、event_id 对得上。
 *
 * 普通访客不受影响：没有验收码 cookie 的浏览器拿不到上报地址，不会多发任何请求；验收码过期后上报被拒绝。
 * 命令：wp eval-file wp-content/plugins/peptide-core/tests/m7-pixel-acceptance-smoke.php start|check|stop
 * 逐店操作清单：docs/guides/m7-pixel-acceptance-checklist.md
 *
 * @package PeptideCore
 */

namespace PeptideCore\Pixels;

use PeptideCore\Pixels;

defined( 'ABSPATH' ) || exit;

final class Acceptance {

	public const OPTION      = 'peptide_core_px_acceptance';
	public const PARAM       = 'pc_px_check';
	public const COOKIE      = 'pc_px_check';
	public const MAX_BROWSER = 300;

	/** 要验收的六类事件：Facebook 事件名 => 站内统一事件名。 */
	public const EVENTS = array(
		'PageView'         => 'page_view',
		'ViewContent'      => 'view_item',
		'AddToCart'        => 'add_to_cart',
		'InitiateCheckout' => 'begin_checkout',
		'AddPaymentInfo'   => 'add_payment_info',
		'Purchase'         => 'purchase',
	);

	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'remember' ) );
	}

	// ---------- 验收码 ----------

	/**
	 * 开始一次验收。
	 *
	 * @return array{code:string,url:string,until:int}
	 */
	public static function start( int $minutes = 30 ): array {
		$code  = strtolower( wp_generate_password( 12, false ) );
		$until = time() + max( 5, min( 240, $minutes ) ) * MINUTE_IN_SECONDS;
		update_option( self::OPTION, array( 'code' => $code, 'started_ms' => (int) round( microtime( true ) * 1000 ), 'until' => $until ), false );
		return array( 'code' => $code, 'url' => add_query_arg( self::PARAM, $code, home_url( '/' ) ), 'until' => $until );
	}

	public static function stop(): void {
		delete_option( self::OPTION );
	}

	/**
	 * 当前的验收：[ code, started_ms, until ]；没有时返回 null。$live 为 true 时过期的也当作没有。
	 */
	public static function state( bool $live = true ): ?array {
		$state = get_option( self::OPTION, null );
		if ( ! is_array( $state ) || ! is_string( $state['code'] ?? null ) || '' === $state['code'] ) {
			return null;
		}
		if ( $live && (int) ( $state['until'] ?? 0 ) < time() ) {
			return null;
		}
		return array( 'code' => $state['code'], 'started_ms' => (int) ( $state['started_ms'] ?? 0 ), 'until' => (int) ( $state['until'] ?? 0 ) );
	}

	/** 用带验收码的链接打开店铺时，记一个会话 cookie（关掉浏览器就没了）。没有这个参数的请求什么都不做。 */
	public static function remember(): void {
		if ( empty( $_GET[ self::PARAM ] ) || is_admin() || headers_sent() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$code  = sanitize_key( wp_unslash( $_GET[ self::PARAM ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$state = self::state();
		if ( ! $state || ! hash_equals( $state['code'], $code ) ) {
			return;
		}
		setcookie( self::COOKIE, $code, array( 'expires' => 0, 'path' => COOKIEPATH ? COOKIEPATH : '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
		$_COOKIE[ self::COOKIE ] = $code;
	}

	/** 这个浏览器带的验收码（和当前有效的验收码一致时）；否则是空字符串。没有 cookie 时不读选项。 */
	public static function browser_code(): string {
		if ( empty( $_COOKIE[ self::COOKIE ] ) ) {
			return '';
		}
		$code  = sanitize_key( wp_unslash( $_COOKIE[ self::COOKIE ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$state = self::state();
		return $state && hash_equals( $state['code'], $code ) ? $code : '';
	}

	// ---------- 浏览器上报 ----------

	/** 记一条浏览器事件。验收码不对或已过期、事件不在 Facebook 的事件表里、event_id 不合规、超过上限时不记。 */
	public static function record( string $code, string $name, string $id ): bool {
		global $wpdb;
		$state = self::state();
		$map   = Pixels::platforms()['facebook']['events'];
		if ( ! $state || ! hash_equals( $state['code'], $code ) || ! isset( $map[ $name ] ) || 1 !== preg_match( '/^[A-Za-z0-9_\-]{3,64}$/', $id ) ) {
			return false;
		}
		if ( Server::installed() ) {
			$table = Server::table();
			$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE platform = 'browser' AND pixel = %s", $code ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			if ( $count >= self::MAX_BROWSER ) {
				return false;
			}
		}
		Server::log( 'browser', $code, (string) $map[ $name ], $id, true, 0, '' );
		return true;
	}

	// ---------- 核对 ----------

	/**
	 * 纯判断：按事件核对服务端和浏览器的记录。
	 *
	 * @param array<int,array<string,mixed>> $rows pc_px_log 的行（platform, pixel, event, event_id, ok, code, message）。
	 * @param string                         $code 验收码（浏览器行的 pixel 列）。
	 * @return array{pass:bool,events:array<string,array<string,mixed>>}
	 */
	public static function evaluate( array $rows, string $code ): array {
		$out = array( 'pass' => true, 'events' => array() );
		foreach ( array_keys( self::EVENTS ) as $event ) {
			$server  = array();
			$failed  = array();
			$browser = array();
			foreach ( $rows as $row ) {
				if ( $event !== (string) ( $row['event'] ?? '' ) ) {
					continue;
				}
				$id = (string) ( $row['event_id'] ?? '' );
				if ( 'facebook' === ( $row['platform'] ?? '' ) ) {
					if ( (int) ( $row['ok'] ?? 0 ) ) {
						$server[ $id ] = true;
					} else {
						$failed[ $id ] = 'HTTP ' . (int) ( $row['code'] ?? 0 ) . ' ' . (string) ( $row['message'] ?? '' );
					}
				} elseif ( 'browser' === ( $row['platform'] ?? '' ) && $code === (string) ( $row['pixel'] ?? '' ) ) {
					$browser[ $id ] = true;
				}
			}
			$matched = array_intersect_key( $server, $browser );
			if ( ! $server ) {
				$status = $failed ? 'server_failed' : 'server_missing';
			} elseif ( ! $browser ) {
				$status = 'browser_missing';
			} elseif ( ! $matched ) {
				$status = 'id_mismatch';
			} else {
				$status = 'ok';
			}
			if ( 'ok' === $status && 'Purchase' === $event && ! array_filter( array_keys( $matched ), static fn( $id ): bool => 1 === preg_match( '/^order_\d+$/', (string) $id ) ) ) {
				$status = 'id_mismatch'; // 购买事件的 event_id 必须是 order_<订单号>，补发和晚付款才能去重。
			}
			$out['events'][ $event ] = array(
				'status'        => $status,
				'server_ok'     => count( $server ),
				'server_failed' => count( $failed ),
				'browser'       => count( $browser ),
				'matched'       => count( $matched ),
				'errors'        => array_slice( array_values( array_unique( $failed ) ), 0, 3 ),
				'hint'          => self::hint( $event, $status ),
			);
			if ( 'ok' !== $status ) {
				$out['pass'] = false;
			}
		}
		return $out;
	}

	/** 没通过时该查什么。 */
	public static function hint( string $event, string $status ): string {
		if ( 'ok' === $status ) {
			return '';
		}
		if ( 'server_failed' === $status ) {
			return '服务端发了但 Facebook 没收：看 errors 里的原因，多半是像素 ID 或 CAPI 令牌填错、令牌过期，或令牌不属于这个像素。';
		}
		if ( 'id_mismatch' === $status ) {
			return 'Purchase' === $event
				? '两边都发了，但 event_id 对不上（购买事件应该是 order_<订单号>）：事件管理器会算成两次购买，先不要投放，把这次的订单号发给开发。'
				: '两边都发了，但 event_id 对不上：事件管理器会重复计数，把这次验收的时间发给开发。';
		}
		$server = array(
			'PageView'         => '服务端没发 PageView：广告像素 → 统计设置里勾上“服务端也回传 PageView”，保存后重新打开店铺。',
			'ViewContent'      => '服务端没发 ViewContent：验收时要真的打开一个商品（商品页或首页弹窗）。',
			'AddToCart'        => '服务端没发 AddToCart：验收时要点“加入购物车”并看到购物车里有商品。',
			'InitiateCheckout' => '服务端没发 InitiateCheckout：验收时要进入结账页。',
			'AddPaymentInfo'   => '服务端没发 AddPaymentInfo：验收时要在结账页选一下支付方式或点一次下单；购物车是空的时不会发。',
			'Purchase'         => '服务端没发 Purchase：订单要真的付款成功（状态变成处理中 / 已完成 / 保留）；后台手工建的订单、员工账号下的单不回传。',
		);
		$browser = array(
			'Purchase' => '浏览器没发 Purchase：付款后要回到店铺的“下单成功”页（不要在收银台直接关掉页面）。',
		);
		if ( 'browser_missing' === $status ) {
			return $browser[ $event ] ?? '浏览器没发 ' . $event . '：确认是用带验收码的链接打开的店铺，没有开广告拦截，也没有用员工账号登录。';
		}
		return ( $server[ $event ] ?? '服务端没发 ' . $event . '。' ) . ' 六类事件都没有服务端记录时，先确认 Facebook 像素填了 CAPI 令牌、关联域名就是本店域名，后台任务（Action Scheduler）在跑。';
	}

	/**
	 * 核对这次验收。
	 *
	 * @return array<string,mixed> pass、events（每类事件的结果）、config（配置检查）；没有开始过验收时 pass = false、error = not_started。
	 */
	public static function check(): array {
		global $wpdb;
		$state = self::state( false );
		if ( ! $state ) {
			return array( 'pass' => false, 'error' => 'not_started', 'events' => array(), 'config' => self::config() );
		}
		$rows = array();
		if ( Server::installed() ) {
			$table = Server::table();
			$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT platform, pixel, event, event_id, ok, code, message FROM {$table} WHERE sent_ms >= %d ORDER BY id ASC LIMIT 5000", $state['started_ms'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		}
		$result             = self::evaluate( $rows, $state['code'] );
		$result['code']     = $state['code'];
		$result['started']  = wp_date( 'Y-m-d H:i:s', (int) floor( $state['started_ms'] / 1000 ) );
		$result['expired']  = $state['until'] < time();
		$result['config']   = self::config();
		$result['pass']     = $result['pass'] && $result['config']['ok'];
		return $result;
	}

	/**
	 * 配置检查：有没有带令牌的 Facebook 像素、关联域名是不是本店域名、服务端 PageView 开没开、测试事件代码清没清。
	 *
	 * @return array{ok:bool,pixels:int,with_token:int,domain:string,wrong_domain:string[],server_pv:bool,test_code:bool,problems:string[]}
	 */
	public static function config(): array {
		$s        = Pixels::settings();
		$domain   = Pixels::store_domain();
		$pixels   = (array) $s['platforms']['facebook']['pixels'];
		$tokens   = 0;
		$wrong    = array();
		$problems = array();
		foreach ( $pixels as $px ) {
			$tokens += '' !== (string) ( $px['token'] ?? '' ) ? 1 : 0;
			if ( Pixels::pixel_domain( $px ) !== $domain ) {
				$wrong[] = (string) ( $px['id'] ?? '' );
			}
		}
		if ( ! $pixels ) {
			$problems[] = '还没有填 Facebook 像素。';
		} elseif ( $tokens < count( $pixels ) ) {
			$problems[] = '有 ' . ( count( $pixels ) - $tokens ) . ' 个 Facebook 像素没有填 CAPI 令牌：这些像素只有浏览器事件，没有服务端事件。';
		}
		if ( $wrong ) {
			$problems[] = '这些像素的关联域名不是本店域名（' . $domain . '），服务端不会给它们回传：' . implode( '、', $wrong );
		}
		if ( ! $s['server_pv'] ) {
			$problems[] = '“服务端也回传 PageView”没有打开：PageView 只有浏览器一路。';
		}
		return array(
			'ok'           => ! $problems,
			'pixels'       => count( $pixels ),
			'with_token'   => $tokens,
			'domain'       => $domain,
			'wrong_domain' => $wrong,
			'server_pv'    => (bool) $s['server_pv'],
			// 不算问题，只是提醒：验收完要清掉，否则正式事件一直进“测试事件”。
			'test_code'    => '' !== (string) $s['test_code'],
			'problems'     => $problems,
		);
	}

	/** 核对结果 → 给人看的文字（命令行输出用）。 */
	public static function report( array $result ): string {
		$labels = array( 'ok' => '通过', 'server_missing' => '服务端没发', 'server_failed' => '服务端发送失败', 'browser_missing' => '浏览器没发', 'id_mismatch' => 'event_id 不一致' );
		$lines  = array();
		if ( 'not_started' === ( $result['error'] ?? '' ) ) {
			$lines[] = '还没有开始验收：先运行 start，再用给出的链接打开店铺走一遍下单。';
		}
		foreach ( (array) ( $result['events'] ?? array() ) as $event => $row ) {
			$lines[] = sprintf( '%-17s %s  服务端成功 %d / 失败 %d，浏览器 %d，event_id 对上 %d', $event, $labels[ $row['status'] ] ?? $row['status'], $row['server_ok'], $row['server_failed'], $row['browser'], $row['matched'] );
			if ( '' !== $row['hint'] ) {
				$lines[] = '    → ' . $row['hint'];
			}
			foreach ( (array) $row['errors'] as $error ) {
				$lines[] = '    ! ' . $error;
			}
		}
		foreach ( (array) ( $result['config']['problems'] ?? array() ) as $problem ) {
			$lines[] = '配置：' . $problem;
		}
		if ( ! empty( $result['config']['test_code'] ) ) {
			$lines[] = '提醒：统计设置里还填着“测试事件代码”，验收通过后记得清空，否则正式事件只会出现在“测试事件”里。';
		}
		if ( ! empty( $result['expired'] ) ) {
			$lines[] = '提醒：这次验收码已经过期，浏览器之后发的事件不会再记录；要重新验收请再运行 start。';
		}
		$lines[] = ! empty( $result['pass'] ) ? '结果：通过。六类事件都是“浏览器 + 服务器”，event_id 一致。' : '结果：没有通过。';
		return implode( "\n", $lines );
	}
}
