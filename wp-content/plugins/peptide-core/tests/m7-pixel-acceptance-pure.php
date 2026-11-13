<?php
/**
 * FE-09：像素验收的判断逻辑（Pixels\Acceptance）。纯逻辑，不连 Facebook、不连数据库：php -n m7-pixel-acceptance-pure.php
 *
 * 真正的验收（跑一遍完整下单）用 m7-pixel-acceptance-smoke.php，在装好 WooCommerce 的店里执行。
 */
namespace PeptideCore {
	/** 只带验收用到的部分。 */
	final class Pixels {
		public static array $settings = array();
		public static function platforms(): array {
			return array( 'facebook' => array( 'label' => 'Facebook', 'events' => array( 'page_view' => 'PageView', 'view_item' => 'ViewContent', 'search' => 'Search', 'add_to_cart' => 'AddToCart', 'begin_checkout' => 'InitiateCheckout', 'purchase' => 'Purchase', 'add_payment_info' => 'AddPaymentInfo', 'sign_up' => 'CompleteRegistration' ) ) );
		}
		public static function settings(): array { return self::$settings; }
		public static function store_domain(): string { return 'store.test'; }
		public static function pixel_domain( array $px ): string { return '' !== (string) ( $px['domain'] ?? '' ) ? (string) $px['domain'] : self::store_domain(); }
	}
}

namespace PeptideCore\Pixels {
	/** 发送记录表放在内存里。 */
	final class Server {
		public static array $rows = array();
		public static function installed(): bool { return true; }
		public static function table(): string { return 'test_pc_px_log'; }
		public static function log( string $platform, string $pixel, string $event, string $event_id, bool $ok, int $code, string $message ): void {
			self::$rows[] = array( 'platform' => $platform, 'pixel' => $pixel, 'event' => $event, 'event_id' => $event_id, 'ok' => $ok ? 1 : 0, 'code' => $code, 'message' => $message, 'sent_ms' => (int) round( microtime( true ) * 1000 ) );
		}
	}
}

namespace {
	require __DIR__ . '/m6-stubs.php';

	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'COOKIEPATH', '/' );
	function wp_generate_password( $length = 12, $special = true ) { return substr( 'AbCdEfGh1234XyZ9', 0, (int) $length ); }
	function home_url( $path = '' ) { return 'https://store.test' . $path; }
	function add_query_arg( $key, $value, $url ) { return $url . '?' . $key . '=' . $value; }
	function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
	function wp_unslash( $value ) { return $value; }

	/** 假 $wpdb：只认验收用的两条查询。 */
	final class Px_DB {
		public function prepare( string $sql, ...$args ): string {
			if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; } // 和 WordPress 一样：参数可以整个放在一个数组里。
			foreach ( $args as $arg ) { $sql = preg_replace( '/%[ds]/', is_int( $arg ) ? (string) $arg : "'" . $arg . "'", $sql, 1 ); }
			return $sql;
		}
		public function get_var( string $sql ) {
			if ( preg_match( "/platform = 'browser' AND pixel = '([a-z0-9]+)'/", $sql, $m ) ) {
				return count( array_filter( \PeptideCore\Pixels\Server::$rows, static fn( array $r ): bool => 'browser' === $r['platform'] && $m[1] === $r['pixel'] ) );
			}
			throw new RuntimeException( 'Unexpected get_var: ' . $sql );
		}
		public function get_results( string $sql, $mode = null ) {
			if ( ! preg_match( '/sent_ms >= (\d+)/', $sql, $m ) ) {
				throw new RuntimeException( 'Unexpected get_results: ' . $sql );
			}
			$rows = array_values( array_filter( \PeptideCore\Pixels\Server::$rows, static fn( array $r ): bool => $r['sent_ms'] >= (int) $m[1] ) );
			if ( preg_match( "/platform = 'browser' AND pixel = '([a-z0-9]+)'/", $sql, $b ) ) { // 验收浏览器发出的事件。
				return array_values( array_filter( $rows, static fn( array $r ): bool => 'browser' === $r['platform'] && $b[1] === $r['pixel'] ) );
			}
			if ( preg_match( "/platform = 'facebook' AND event_id IN \(([^)]*)\)/", $sql, $in ) ) { // 服务端对这些 event_id 的发送记录。
				$ids = array_map( static fn( string $id ): string => trim( $id, " '" ), explode( ',', $in[1] ) );
				return array_values( array_filter( $rows, static fn( array $r ): bool => 'facebook' === $r['platform'] && in_array( $r['event_id'], $ids, true ) ) );
			}
			if ( preg_match( "/platform = 'facebook' ORDER BY id DESC LIMIT (\d+)/", $sql, $limit ) ) { // 最近的服务端记录（任何访客的）。
				return array_slice( array_reverse( array_values( array_filter( $rows, static fn( array $r ): bool => 'facebook' === $r['platform'] ) ) ), 0, (int) $limit[1] );
			}
			throw new RuntimeException( 'Unexpected get_results: ' . $sql );
		}
	}

	require dirname( __DIR__ ) . '/includes/pixels/class-acceptance.php';
}

namespace {
	use PeptideCore\Pixels;
	use PeptideCore\Pixels\Acceptance;
	use PeptideCore\Pixels\Server;

	$wpdb = new Px_DB();
	$row  = static fn( string $platform, string $event, string $id, int $ok = 1, string $pixel = '111', int $code = 200, string $message = '' ): array => array( 'platform' => $platform, 'pixel' => $pixel, 'event' => $event, 'event_id' => $id, 'ok' => $ok, 'code' => $code, 'message' => $message );
	$ids  = array( 'PageView' => 'pag_1', 'ViewContent' => 'vie_1', 'AddToCart' => 'add_1', 'InitiateCheckout' => 'beg_1', 'AddPaymentInfo' => 'add_2', 'Purchase' => 'order_88' );
	$full = static function ( array $skip_server = array(), array $skip_browser = array() ) use ( $row, $ids ): array {
		$rows = array();
		foreach ( $ids as $event => $id ) {
			if ( ! in_array( $event, $skip_server, true ) ) { $rows[] = $row( 'facebook', $event, $id ); }
			if ( ! in_array( $event, $skip_browser, true ) ) { $rows[] = $row( 'browser', $event, $id, 1, 'code1' ); }
		}
		return $rows;
	};
	$status = static fn( array $result ): array => array_map( static fn( array $e ): string => $e['status'], $result['events'] );

	// ---------- 六类事件 ----------
	m6_check( 'the six events to verify, in funnel order', array( 'PageView', 'ViewContent', 'AddToCart', 'InitiateCheckout', 'AddPaymentInfo', 'Purchase' ) === array_keys( Acceptance::EVENTS ) );
	$ok = Acceptance::evaluate( $full(), 'code1' );
	m6_check( 'all six sent by server and browser with the same ids: pass', $ok['pass'] && array( 'ok' ) === array_values( array_unique( $status( $ok ) ) ) );
	m6_check( 'every event reports its counts', 1 === $ok['events']['Purchase']['server_ok'] && 1 === $ok['events']['Purchase']['browser'] && 1 === $ok['events']['Purchase']['matched'] && '' === $ok['events']['Purchase']['hint'] );

	$r = Acceptance::evaluate( $full( array( 'PageView' ) ), 'code1' );
	m6_check( 'server PageView missing fails with the setting to turn on', ! $r['pass'] && 'server_missing' === $r['events']['PageView']['status'] && str_contains( $r['events']['PageView']['hint'], '服务端也回传 PageView' ) && 'ok' === $r['events']['Purchase']['status'] );

	$rows   = $full( array( 'AddToCart' ) );
	$rows[] = $row( 'facebook', 'AddToCart', 'add_1', 0, '111', 400, 'Invalid OAuth access token' );
	$r      = Acceptance::evaluate( $rows, 'code1' );
	m6_check( 'a rejected server event is a failure with the platform error', ! $r['pass'] && 'server_failed' === $r['events']['AddToCart']['status'] && array( 'HTTP 400 Invalid OAuth access token' ) === $r['events']['AddToCart']['errors'] && str_contains( $r['events']['AddToCart']['hint'], '令牌' ) );

	$r = Acceptance::evaluate( $full( array(), array( 'Purchase' ) ), 'code1' );
	// 11-11 起浏览器的购买事件由付款成功弹窗页发（?pc_paid=），提示跟着改了。
	m6_check( 'browser Purchase missing: wait for the paid popup; a test order sends none', ! $r['pass'] && 'browser_missing' === $r['events']['Purchase']['status'] && str_contains( $r['events']['Purchase']['hint'], '付款成功的弹窗' ) && str_contains( $r['events']['Purchase']['hint'], '测试单' ) );

	$rows   = $full( array(), array( 'AddPaymentInfo' ) );
	$rows[] = $row( 'browser', 'AddPaymentInfo', 'pay_local1', 1, 'code1' ); // 浏览器退回了只发浏览器事件的做法。
	$r      = Acceptance::evaluate( $rows, 'code1' );
	m6_check( 'different event ids on the two sides are a mismatch', ! $r['pass'] && 'id_mismatch' === $r['events']['AddPaymentInfo']['status'] && 0 === $r['events']['AddPaymentInfo']['matched'] );

	$rows = array_merge( $full( array( 'Purchase' ), array( 'Purchase' ) ), array( $row( 'facebook', 'Purchase', 'pur_abc' ), $row( 'browser', 'Purchase', 'pur_abc', 1, 'code1' ) ) );
	$r    = Acceptance::evaluate( $rows, 'code1' );
	m6_check( 'Purchase must use order_<id> as its event id', ! $r['pass'] && 'id_mismatch' === $r['events']['Purchase']['status'] );

	m6_check( 'browser rows from another acceptance code are ignored', 'browser_missing' === Acceptance::evaluate( $full(), 'other' )['events']['PageView']['status'] );
	m6_check( 'no rows at all: nothing passes', ! Acceptance::evaluate( array(), 'code1' )['pass'] && array( 'server_missing' ) === array_values( array_unique( $status( Acceptance::evaluate( array(), 'code1' ) ) ) ) );
	m6_check( 'passing events need no hint', '' === Acceptance::hint( 'Purchase', 'ok' ) && '' !== Acceptance::hint( 'ViewContent', 'server_missing' ) && '' !== Acceptance::hint( 'ViewContent', 'browser_missing' ) );

	// ---------- 一家店两个像素：每个都要通 ----------
	$two = array( '111', '222' );
	$both = array_merge( $full(), array_map( static fn( string $event ) => $row( 'facebook', $event, $ids[ $event ], 1, '222' ), array_keys( $ids ) ) );
	m6_check( 'two pixels that both accept the same ids pass', Acceptance::evaluate( $both, 'code1', $two )['pass'] );
	$r = Acceptance::evaluate( $full(), 'code1', $two );
	m6_check( 'a configured pixel with no record at all fails every event', ! $r['pass'] && array( 'server_missing' ) === array_values( array_unique( $status( $r ) ) ) && 0 === $r['events']['Purchase']['server_ok'] );
	$rows   = array_merge( $full(), array_map( static fn( string $event ) => $row( 'facebook', $event, $ids[ $event ], 1, '222' ), array( 'PageView', 'ViewContent', 'AddToCart', 'InitiateCheckout', 'AddPaymentInfo' ) ) );
	$rows[] = $row( 'facebook', 'Purchase', 'order_88', 0, '222', 400, 'Invalid OAuth access token' );
	$r      = Acceptance::evaluate( $rows, 'code1', $two );
	m6_check( 'one pixel accepting does not hide another pixel rejecting the same event', ! $r['pass'] && 'server_failed' === $r['events']['Purchase']['status'] && array( 'HTTP 400 Invalid OAuth access token' ) === $r['events']['Purchase']['errors'] && 'ok' === $r['events']['PageView']['status'] );
	$rows[] = $row( 'facebook', 'Purchase', 'order_88', 1, '222' );
	m6_check( 'a failure that a retry on the same pixel fixed is not a failure', Acceptance::evaluate( $rows, 'code1', $two )['pass'] );

	// ---------- 店里还有别的访客 ----------
	$busy   = $full();
	$busy[] = $row( 'facebook', 'PageView', 'pag_other1' );
	$busy[] = $row( 'facebook', 'Purchase', 'order_901' );
	$busy[] = $row( 'facebook', 'AddToCart', 'add_other', 0, '111', 500, 'temporary' );
	$r      = Acceptance::evaluate( $busy, 'code1' );
	m6_check( "other visitors' events and their failures do not change the tester's result", $r['pass'] && 1 === $r['events']['AddToCart']['server_failed'] && 1 === $r['events']['PageView']['matched'] );

	// ---------- 验收码 ----------
	m6_check( 'no acceptance running by default', null === Acceptance::state() && '' === Acceptance::browser_code() );
	$started = Acceptance::start( 30 );
	m6_check( 'start returns a lowercase code and a store link carrying it', 'abcdefgh1234' === $started['code'] && 'https://store.test/?pc_px_check=abcdefgh1234' === $started['url'] && $started['until'] > time() );
	m6_check( 'state is live after start', 'abcdefgh1234' === Acceptance::state()['code'] );
	$_COOKIE[ Acceptance::COOKIE ] = 'abcdefgh1234';
	m6_check( 'a browser carrying the code gets it back', 'abcdefgh1234' === Acceptance::browser_code() );
	$_COOKIE[ Acceptance::COOKIE ] = 'someoneelse1';
	m6_check( 'a browser with another code gets nothing', '' === Acceptance::browser_code() );

	// ---------- 浏览器上报 ----------
	Server::$rows = array();
	m6_check( 'a valid browser event is recorded under the Facebook event name', Acceptance::record( 'abcdefgh1234', 'add_payment_info', 'add_9f3' ) && array( 'browser', 'abcdefgh1234', 'AddPaymentInfo', 'add_9f3' ) === array( Server::$rows[0]['platform'], Server::$rows[0]['pixel'], Server::$rows[0]['event'], Server::$rows[0]['event_id'] ) );
	m6_check( 'wrong code, unknown event and malformed ids are refused', ! Acceptance::record( 'wrongcode000', 'purchase', 'order_1' ) && ! Acceptance::record( 'abcdefgh1234', 'place_order', 'place_1' ) && ! Acceptance::record( 'abcdefgh1234', 'purchase', 'x' ) && ! Acceptance::record( 'abcdefgh1234', 'purchase', "order_1'; DROP" ) && 1 === count( Server::$rows ) );
	for ( $i = 0; $i < Acceptance::MAX_BROWSER + 5; $i++ ) { Acceptance::record( 'abcdefgh1234', 'page_view', 'pag_' . $i ); }
	m6_check( 'browser reports are capped per acceptance', Acceptance::MAX_BROWSER === count( Server::$rows ) );

	// ---------- 整体核对 ----------
	Pixels::$settings = array( 'server_pv' => false, 'test_code' => 'TEST123', 'platforms' => array( 'facebook' => array( 'pixels' => array( array( 'id' => '111', 'token' => 'tok', 'domain' => '' ), array( 'id' => '222', 'token' => '', 'domain' => 'other.test' ) ) ) ) );
	$config           = Acceptance::config();
	m6_check( 'config check finds the missing token, the foreign domain and server PageView off', ! $config['ok'] && 2 === $config['pixels'] && 1 === $config['with_token'] && array( '222' ) === $config['wrong_domain'] && ! $config['server_pv'] && $config['test_code'] && 3 === count( $config['problems'] ) );

	Server::$rows = array();
	foreach ( $ids as $event => $id ) {
		Server::log( 'facebook', '111', $event, $id, true, 200, '' );
		Acceptance::record( 'abcdefgh1234', Acceptance::EVENTS[ $event ], $id );
	}
	$check = Acceptance::check();
	m6_check( 'events all match but a config problem still fails the check', ! $check['pass'] && array( 'ok' ) === array_values( array_unique( $status( $check ) ) ) && ! $check['expired'] && 'abcdefgh1234' === $check['code'] );
	Pixels::$settings = array( 'server_pv' => true, 'test_code' => '', 'platforms' => array( 'facebook' => array( 'pixels' => array( array( 'id' => '111', 'token' => 'tok', 'domain' => '' ) ) ) ) );
	$check            = Acceptance::check();
	m6_check( 'full pass: six events on both sides, same ids, config clean', $check['pass'] && $check['config']['ok'] && str_contains( Acceptance::report( $check ), '结果：通过' ) );
	m6_check( 'report lists every event', 6 === substr_count( Acceptance::report( $check ), '服务端成功' ) );
	// 生产店验收：之后又来了 2500 条别的访客的服务端记录，超过“最近 2000 条”的范围，验收人的记录仍然找得到。
	for ( $i = 0; $i < 2500; $i++ ) { Server::log( 'facebook', '111', 'PageView', 'pag_busy_' . $i, true, 200, '' ); }
	$check = Acceptance::check();
	m6_check( "a busy store does not push the tester's records out of the check", $check['pass'] && 1 === $check['events']['ViewContent']['matched'] && array( '111' ) === $check['config']['expected'] );

	// 过期：浏览器之后的上报被拒绝，但已经记下的仍然能核对。
	$GLOBALS['m6_options'][ Acceptance::OPTION ]['until'] = time() - 1;
	m6_check( 'expired code: no live state, reports refused', null === Acceptance::state() && '' === Acceptance::browser_code() && ! Acceptance::record( 'abcdefgh1234', 'page_view', 'pag_late' ) );
	$late = Acceptance::check();
	m6_check( 'expired code: what was recorded can still be checked', $late['pass'] && $late['expired'] && str_contains( Acceptance::report( $late ), '已经过期' ) );
	Acceptance::stop();
	$none = Acceptance::check();
	m6_check( 'after stop there is nothing to check', ! $none['pass'] && 'not_started' === $none['error'] && str_contains( Acceptance::report( $none ), '还没有开始验收' ) );

	echo 'PASS ', $GLOBALS['m6_passed'], " pixel acceptance (FE-09) assertions\n";
}
