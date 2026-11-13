<?php
/**
 * Facebook 像素自动健康检查（2026-11-12）。
 *
 * 不额外发请求：服务端每次把真实事件（浏览、加购、下单……）回传给 Facebook，都会在发送记录（pc_px_log）里记下成功 / 失败和原因。
 * 这里读最近 24 小时这个像素的记录，自动判断像素是不是还通：
 *
 * - bad：最近一次发送失败，而且之后没有成功过（连续失败 ≥ 2 次，或者是令牌 / 像素 ID 这类一看就不会自己好的错误）；
 * - warn：失败比例偏高（≥ 10%，至少 20 条），或者有广告访客进站、却 24 小时一条事件都没发出去；
 * - idle：24 小时没有事件，也没有广告访客（还没开始投，或者店里没人来）；
 * - ok：最近 24 小时有事件、基本都成功。
 *
 * evaluate() 是纯函数（测试直接调用），facts() 读数据库。手动“发送测试事件”保留，第一次填像素时用它确认。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Pixels;

defined( 'ABSPATH' ) || exit;

final class Health {

	public const WINDOW_HOURS = 24;

	/** 失败比例超过这个值算 warn（样本至少 MIN_SAMPLE 条）。 */
	public const WARN_RATE  = 0.10;
	public const MIN_SAMPLE = 20;

	/**
	 * 读最近 24 小时的事实。
	 *
	 * @return array{pixel:string,total:int,ok:int,last_ms:int,last_ok:bool,last_ok_ms:int,fail_streak:int,last_error:string,last_code:int,paid_visits:int}
	 */
	public static function facts( string $pixel, ?int $now_ms = null, int $visits_since_ms = 0 ): array {
		global $wpdb;
		$now_ms = $now_ms ?? (int) round( microtime( true ) * 1000 );
		$since  = $now_ms - self::WINDOW_HOURS * 3600 * 1000;
		$out    = array( 'pixel' => $pixel, 'total' => 0, 'ok' => 0, 'last_ms' => 0, 'last_ok' => true, 'last_ok_ms' => 0, 'fail_streak' => 0, 'last_error' => '', 'last_code' => 0, 'paid_visits' => 0 );
		if ( '' === $pixel ) {
			return $out;
		}
		if ( Server::installed() ) {
			$t   = Server::table();
			$sum = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, COALESCE(SUM(ok),0) AS good, COALESCE(MAX(sent_ms),0) AS last_ms, COALESCE(MAX(CASE WHEN ok = 1 THEN sent_ms END),0) AS last_ok_ms FROM {$t} WHERE platform = 'facebook' AND pixel = %s AND sent_ms >= %d", $pixel, $since ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			$out['total']      = (int) ( $sum['n'] ?? 0 );
			$out['ok']         = (int) ( $sum['good'] ?? 0 );
			$out['last_ms']    = (int) ( $sum['last_ms'] ?? 0 );
			$out['last_ok_ms'] = (int) ( $sum['last_ok_ms'] ?? 0 );
			// 最近 10 条：连续失败了几次、最近一次的错误。
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT ok, code, message FROM {$t} WHERE platform = 'facebook' AND pixel = %s AND sent_ms >= %d ORDER BY sent_ms DESC, id DESC LIMIT 10", $pixel, $since ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			foreach ( $rows as $i => $r ) {
				if ( 0 === $i ) {
					$out['last_ok'] = '1' === (string) $r['ok'];
				}
				if ( '1' === (string) $r['ok'] ) {
					break;
				}
				++$out['fail_streak'];
				if ( '' === $out['last_error'] ) {
					$out['last_error'] = (string) $r['message'];
					$out['last_code']  = (int) $r['code'];
				}
			}
		}
		if ( class_exists( \PeptideCore\Tracking\Schema::class ) && \PeptideCore\Tracking\Schema::installed() ) {
			$suppress = $wpdb->suppress_errors( true );
			// 只算这个像素填好之后进来的广告访客：刚换像素时，之前的访客本来就不会有事件。
			$out['paid_visits'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . \PeptideCore\Tracking\Schema::table( 'visits' ) . " WHERE source_class = 'paid' AND start_ms >= %d", max( $since, $visits_since_ms ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			$wpdb->suppress_errors( $suppress );
		}
		return $out;
	}

	/**
	 * Facebook 返回的错误 → 投手看得懂的原因和怎么办。$message 是发送记录里存的返回内容（JSON 文本，最多 300 字）。
	 *
	 * @return array{kind:string,text:string}  kind：token / pixel / network / rate / other
	 */
	public static function explain( string $message, int $http ): array {
		$fb  = 0;
		$sub = 0;
		if ( preg_match( '/"code"\s*:\s*(\d+)/', $message, $m ) ) {
			$fb = (int) $m[1];
		}
		if ( preg_match( '/"error_subcode"\s*:\s*(\d+)/', $message, $m ) ) {
			$sub = (int) $m[1];
		}
		if ( 0 === $http ) {
			return array( 'kind' => 'network', 'text' => '服务器连不上 Facebook（网络或防火墙问题），会自动重试；一直这样请联系技术。' );
		}
		if ( 190 === $fb || 102 === $fb || in_array( $sub, array( 458, 459, 460, 463, 464, 467 ), true ) || false !== stripos( $message, 'access token' ) ) {
			return array( 'kind' => 'token', 'text' => '转化 API 访问令牌失效了（过期、被撤销或复制不完整）。到 Meta 事件管理工具重新生成令牌，粘到下面保存。' );
		}
		if ( 10 === $fb || 200 === $fb || in_array( $fb, array( 803, 2200 ), true ) || false !== stripos( $message, 'does not exist' ) || false !== stripos( $message, 'permission' ) ) {
			return array( 'kind' => 'pixel', 'text' => '像素 ID 不对，或者这个令牌没有这个像素的权限。核对像素 ID，并用同一个像素设置页里生成的令牌。' );
		}
		if ( in_array( $fb, array( 4, 17, 32, 613 ), true ) || 429 === $http ) {
			return array( 'kind' => 'rate', 'text' => 'Facebook 暂时限流，通常会自己恢复。' );
		}
		$short = trim( (string) preg_replace( '/\s+/', ' ', $message ) );
		return array( 'kind' => 'other', 'text' => 'Facebook 拒收了事件（HTTP ' . $http . '）' . ( '' !== $short ? '：' . mb_substr( $short, 0, 120 ) : '' ) . '。' );
	}

	/**
	 * 判断（纯函数）。
	 *
	 * @param array<string,mixed> $f facts() 的结果
	 * @return array{level:string,title:string,detail:string}  level：none / ok / idle / warn / bad
	 */
	public static function evaluate( array $f ): array {
		if ( '' === (string) ( $f['pixel'] ?? '' ) ) {
			return array( 'level' => 'none', 'title' => '还没有填像素', 'detail' => '' );
		}
		$total  = (int) ( $f['total'] ?? 0 );
		$good   = (int) ( $f['ok'] ?? 0 );
		$streak = (int) ( $f['fail_streak'] ?? 0 );
		$paid   = (int) ( $f['paid_visits'] ?? 0 );
		if ( $total > 0 && empty( $f['last_ok'] ) ) {
			$why = self::explain( (string) ( $f['last_error'] ?? '' ), (int) ( $f['last_code'] ?? 0 ) );
			// 令牌 / 像素 ID 错了不会自己好，失败一次就报；其他错误连续两次才报，偶尔一次算 warn。
			if ( $streak >= 2 || in_array( $why['kind'], array( 'token', 'pixel' ), true ) ) {
				$times = $streak >= 10 ? '最近 10 次以上都没发成功' : ( $streak >= 2 ? '最近 ' . $streak . ' 次都没发成功' : '最近一次没发成功' );
				return array( 'level' => 'bad', 'title' => '像素回传失败：' . $times, 'detail' => $why['text'] );
			}
			return array( 'level' => 'warn', 'title' => '最近一次回传失败', 'detail' => $why['text'] );
		}
		if ( $total >= self::MIN_SAMPLE && ( $total - $good ) / $total >= self::WARN_RATE ) {
			$rate = (int) round( 100 * $good / $total );
			return array( 'level' => 'warn', 'title' => '最近 24 小时成功率偏低（' . $rate . '%）', 'detail' => '有一部分事件被 Facebook 拒收，广告优化会受影响。最近还在成功，先观察；持续偏低请联系技术。' );
		}
		if ( 0 === $total ) {
			if ( $paid > 0 ) {
				return array( 'level' => 'warn', 'title' => '有 ' . $paid . ' 个广告访客进站，但 24 小时没有发出任何事件', 'detail' => '事件可能卡在后台队列里没发出去。点“发送测试事件”看看通不通；通的话请联系技术检查后台任务。' );
			}
			return array( 'level' => 'idle', 'title' => '最近 24 小时没有事件', 'detail' => '还没有访客，或还没开始投放。开始投放后这里会自动显示回传情况。' );
		}
		$rate = (int) floor( 100 * $good / $total );
		return array( 'level' => 'ok', 'title' => '回传正常：最近 24 小时 ' . $total . ' 条，成功 ' . $rate . '%', 'detail' => '' );
	}

	/** 记录“这个像素是什么时候第一次看到的”（像素 ID 变了就重新记）。 */
	public const SEEN_OPTION = 'peptide_core_px_health_seen';

	/** 当前第一个 Facebook 像素的状态。 */
	public static function current(): array {
		$pixels = array_values( (array) ( \PeptideCore\Pixels::settings()['platforms']['facebook']['pixels'] ?? array() ) );
		$pixel  = is_array( $pixels[0] ?? null ) ? (string) ( $pixels[0]['id'] ?? '' ) : '';
		$now    = (int) round( microtime( true ) * 1000 );
		$seen   = get_option( self::SEEN_OPTION, array() );
		if ( '' !== $pixel && ( ! is_array( $seen ) || ( $seen['pixel'] ?? '' ) !== $pixel ) ) {
			$seen = array( 'pixel' => $pixel, 'since' => $now );
			update_option( self::SEEN_OPTION, $seen, false );
		}
		$since = is_array( $seen ) && ( $seen['pixel'] ?? '' ) === $pixel ? (int) ( $seen['since'] ?? 0 ) : $now;
		return self::evaluate( self::facts( $pixel, $now, $since ) ) + array( 'pixel' => $pixel );
	}

	/** 一行提示（首页、广告设置页顶部用）。ok / idle / none 时返回空字符串。 */
	public static function alert( array $h, string $link = '' ): string {
		if ( ! in_array( $h['level'] ?? '', array( 'warn', 'bad' ), true ) ) {
			return '';
		}
		$class = 'bad' === $h['level'] ? 'is-bad' : 'is-warn';
		return '<div class="pc-px-health-alert ' . esc_attr( $class ) . '" role="alert"><strong>' . esc_html( (string) $h['title'] ) . '</strong> <span>' . esc_html( (string) $h['detail'] ) . '</span>'
			. ( '' !== $link ? ' <a class="button" href="' . esc_url( $link ) . '">去处理</a>' : '' ) . '</div>';
	}
}
