<?php
/**
 * 素材人群的 AI 深度分析（2026-11-13，店主：这部分用我接入的 AI）。
 *
 * - 素材看板每张卡片上的“AI 深度分析”按钮 → POST peptide/v1/creatives/audience-ai。
 * - 按看板当前的日期、归因方式、版本重新算一遍这条素材的人群画像，连同全部素材平均、规则结论一起
 *   交给总后台 AI（用途 creative）。只发汇总数字，不发任何访客的 IP、姓名、邮箱、电话。
 * - 每条素材保存最近一次结果（同样条件 30 分钟内再点直接用上次的，“重新分析”强制重来）。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Creatives;

use PeptideCore\Staff_Buyer;
use PeptideCore\Hq_Ai;

defined( 'ABSPATH' ) || exit;

final class Audience_Ai {

	public const OPTION = 'pc_cr_audience_ai';
	private const KEEP  = 200;

	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route(
			'peptide/v1',
			Module::REST_BASE . '/audience-ai',
			array(
				'methods'             => 'POST',
				'permission_callback' => static fn() => current_user_can( Staff_Buyer::CAP_VIEW ),
				'callback'            => array( self::class, 'rest' ),
			)
		);
	}

	/** @return array<string,array{text:string,at:int,persona:string,model:string,sig:string,range:string}> */
	public static function saved(): array {
		$v = get_option( self::OPTION, array() );
		return is_array( $v ) ? $v : array();
	}

	public static function rest( \WP_REST_Request $request ) {
		$code = strtoupper( (string) $request['code'] );
		if ( ! Contract::valid_code( $code ) ) {
			return new \WP_Error( 'pc_cr_ai_code', '素材编号不对。', array( 'status' => 400 ) );
		}
		if ( ! Hq_Ai::available() ) {
			return new \WP_Error( 'pc_cr_ai_hq', '这家店还没有接入总后台，用不了 AI 分析。', array( 'status' => 503 ) );
		}
		$tz  = Board::timezone();
		$f   = Board_Page::filters( array( 'from' => (string) $request['from'], 'to' => (string) $request['to'], 'mode' => (string) $request['mode'], 'ver' => (string) $request['ver'], 'dim' => 'creative' ), $tz, time() );
		$ms  = Board_Page::range( $f, $tz );
		$sig = md5( implode( '|', array( $code, $f['from'], $f['to'], $f['mode'], $f['ver'] ) ) );
		$all = self::saved();
		$old = $all[ $code ] ?? null;
		if ( empty( $request['refresh'] ) && is_array( $old ) && $old['sig'] === $sig && time() - (int) $old['at'] < 30 * MINUTE_IN_SECONDS ) {
			return rest_ensure_response( self::view( $old ) + array( 'cached' => true ) );
		}
		if ( $ms[0] >= $ms[1] ) {
			return new \WP_Error( 'pc_cr_ai_range', '日期范围不对。', array( 'status' => 400 ) );
		}
		$data = Board::load( $ms, $f['from'], $f['to'] );
		$agg  = Board::aggregate( $data, array( 'mode' => $f['mode'], 'dim' => 'creative', 'from_ms' => $ms[0], 'to_ms' => $ms[1], 'tz' => $tz ) );
		$p    = $agg['profiles'][ $code ] ?? null;
		$avg  = $agg['profiles'][ Audience::ALL ] ?? null;
		if ( ! $p || ! $avg ) {
			return new \WP_Error( 'pc_cr_ai_empty', '这段时间这条素材没有付费访客。', array( 'status' => 404 ) );
		}
		$row = null;
		foreach ( $agg['rows'] as $r ) {
			if ( $r['key'] === $code ) {
				$row = $r;
			}
		}
		$facts = self::facts( $code, (array) $row, $p, $avg, $f, (array) ( $data['creatives'][ $code ]['attrs'] ?? array() ), (array) ( $data['labels'] ?? array() ) );
		$r     = Hq_Ai::ask_creative( $facts );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$item          = $r + array( 'at' => time(), 'sig' => $sig, 'range' => $f['from'] . ' ~ ' . $f['to'] . ' · ' . Board::MODES[ $f['mode'] ] . ( '' !== $f['ver'] ? ' · 版本 ' . $f['ver'] : '' ) );
		$all[ $code ]  = $item;
		uasort( $all, static fn( $a, $b ) => (int) $b['at'] <=> (int) $a['at'] );
		update_option( self::OPTION, array_slice( $all, 0, self::KEEP, true ), false );
		return rest_ensure_response( self::view( $item ) + array( 'cached' => false ) );
	}

	/** 给 AI 的事实（中文键，汇总数字）。纯函数。 */
	public static function facts( string $code, array $row, array $p, array $avg, array $f, array $attrs, array $labels ): array {
		$pct  = static fn( $x ) => null === $x ? null : round( (float) $x * 100, 1 );
		$usd  = static fn( $c ) => null === $c ? null : round( (int) $c / 100, 2 );
		$seg  = static function ( array $r ) use ( $pct, $usd ): array {
			return array( '取值' => $r['label'], '人数' => (int) $r['visitors'], '占比%' => $pct( $r['share'] ), '中高意向%' => $pct( $r['mid_high_rate'] ), '加购率%' => $pct( $r['cart_rate'] ), '加购后进结账%' => $pct( $r['checkout_of_cart'] ), '进结账后付款%' => $pct( $r['paid_of_checkout'] ), '下单人数' => (int) $r['paid'], '下单率%' => $pct( $r['order_rate'] ), '订单' => (int) $r['orders'], '销售额$' => $usd( $r['sales_cents'] ), '客单价$' => $usd( $r['aov_cents'] ), '平均停留秒' => $r['dwell_s'], '人均页数' => null === $r['pv_avg'] ? null : round( (float) $r['pv_avg'], 1 ), '滚到75%以上%' => $pct( $r['scroll75_rate'] ) );
		};
		$total = static function ( array $t ) use ( $seg ): array {
			$out = $seg( $t + array( 'label' => '合计', 'share' => 1.0 ) );
			unset( $out['取值'], $out['占比%'] );
			return $out + array( '无效访客%' => null === $t['invalid_rate'] ? null : round( $t['invalid_rate'] * 100, 1 ), '机房或VPN%' => null === $t['bad_ip_rate'] ? null : round( $t['bad_ip_rate'] * 100, 1 ) );
		};
		$dims = static function ( array $prof, int $top ) use ( $seg ): array {
			$out = array();
			foreach ( Audience::DIMS as $dim => $name ) {
				$out[ $name ] = array_map( $seg, array_slice( (array) ( $prof['dims'][ $dim ] ?? array() ), 0, $top ) );
			}
			return $out;
		};
		$attr_text = array();
		foreach ( $attrs as $k => $v ) {
			if ( '' !== (string) $v ) {
				$attr_text[ Board::ATTR_LABELS[ $k ] ?? $k ] = $labels[ $k ][ $v ] ?? $v;
			}
		}
		return array(
			'素材'         => array( '编号' => $code, '属性' => $attr_text, '时间段' => $f['from'] . ' ~ ' . $f['to'], '归因' => Board::MODES[ $f['mode'] ] ?? $f['mode'], '版本' => $f['ver'] ),
			'投放结果'     => array( '花费$' => $usd( $row['spend_cents'] ?? null ), '订单' => (int) ( $row['orders'] ?? 0 ), '销售额$' => $usd( $row['sales_cents'] ?? null ), 'ROAS' => $row['roas'] ?? null, '链接点击' => $row['link_clicks'] ?? null ),
			'这条素材合计' => $total( $p['total'] ),
			'这条素材人群画像' => $dims( $p, Audience::TOP ),
			'全部素材合计（平均参照）' => $total( $avg['total'] ),
			'全部素材人群画像（平均参照）' => $dims( $avg, 5 ),
			'规则结论'     => Audience::analyse( $p, $avg ),
			'说明'         => '人数含无效访客；加购、结账只算有效访客；机型档次（高端 / 中端 / 入门）由机型推断，只是消费能力线索；Safari 打开的 iPhone 认不出具体型号，记为 iPhone。',
		);
	}

	/** 保存的结果 → 页面用。 */
	public static function view( array $item ): array {
		return array(
			'html'    => self::html( (string) $item['text'] ),
			'at'      => wp_date( 'm-d H:i', (int) $item['at'] ),
			'persona' => (string) $item['persona'],
			'model'   => (string) $item['model'],
			'range'   => (string) ( $item['range'] ?? '' ),
		);
	}

	/** AI 的文字 → 简单 HTML（先转义；**粗体**、# 标题、- 列表、换行）。 */
	public static function html( string $text ): string {
		$out = array();
		foreach ( preg_split( '/\R/u', trim( $text ) ) as $line ) {
			$l = esc_html( trim( $line ) );
			$l = (string) preg_replace( '/\*\*(.+?)\*\*/u', '<b>$1</b>', $l );
			if ( '' === $l ) {
				continue;
			}
			if ( preg_match( '/^#{1,4}\s*(.+)$/u', $l, $m ) ) {
				$out[] = '<h4>' . $m[1] . '</h4>';
			} elseif ( preg_match( '/^\d+[.、]\s*(.+)$/u', $l, $m ) && mb_strlen( $m[1] ) <= 30 ) {
				$out[] = '<h4>' . $l . '</h4>';
			} elseif ( preg_match( '/^[-*•]\s*(.+)$/u', $l, $m ) ) {
				$out[] = '<p class="is-li">' . $m[1] . '</p>';
			} else {
				$out[] = '<p>' . $l . '</p>';
			}
		}
		return implode( '', $out );
	}
}
