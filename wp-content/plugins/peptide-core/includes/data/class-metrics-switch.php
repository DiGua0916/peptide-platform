<?php
/**
 * M6 的按店开关（开发文档 12.3）：关 → 影子 → 开。管两件事：报表数字用不用 Data\Metrics，以及漏斗推进规则（FE-05）拦不拦。
 *
 * - off（默认）：新代码不参与。pick() 直接返回旧值，连新算法都不调用，站点和以前完全一样。
 * - shadow：新旧同时算，页面仍然显示旧值；两边不一致就记进对账日志（选项 peptide_core_m6_shadow_log）。
 * - on：页面显示新值；仍然继续对账，方便观察。新算法抛异常时退回旧值并记日志，不让页面出错。
 *
 * 切换（每家店各自一个选项，不随代码发布改变）：
 *   wp option update peptide_core_m6_mode shadow     # 或 on / off
 * 看对账结果：
 *   wp eval 'print_r( PeptideCore\Data\Metrics_Switch::log() );'
 *
 * @package PeptideCore
 */

namespace PeptideCore\Data;

defined( 'ABSPATH' ) || exit;

final class Metrics_Switch {

	public const OPTION      = 'peptide_core_m6_mode';
	public const LOG_OPTION  = 'peptide_core_m6_shadow_log';
	public const MODES       = array( 'off', 'shadow', 'on' );
	public const MAX_ENTRIES = 100;

	/** 本次请求里的对账结果，请求结束时一次写入。 */
	private static array $pending = array();
	private static bool $hooked   = false;

	public static function register_hooks(): void {
		// F1 的功能声明钩子：店铺向总后台说明 M6 现在是哪一档。钩子还没上线时这一行什么都不做。
		add_filter( 'pc_hq_capabilities', array( self::class, 'capabilities' ) );
	}

	public static function capabilities( $capabilities ) {
		$capabilities               = is_array( $capabilities ) ? $capabilities : array();
		$capabilities['m6_metrics'] = array( 'version' => Metrics::VERSION, 'mode' => self::mode() );
		return $capabilities;
	}

	public static function mode(): string {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'apply_filters' ) ) {
			return 'off'; // 没有 WordPress 的环境（现有的纯逻辑测试）：一律当作关着。
		}
		$mode = get_option( self::OPTION, 'off' );
		$mode = is_string( $mode ) && in_array( $mode, self::MODES, true ) ? $mode : 'off';
		$mode = apply_filters( 'peptide_core_m6_mode', $mode );
		return is_string( $mode ) && in_array( $mode, self::MODES, true ) ? $mode : 'off';
	}

	public static function off(): bool {
		return 'off' === self::mode();
	}

	public static function shadow(): bool {
		return 'shadow' === self::mode();
	}

	public static function on(): bool {
		return 'on' === self::mode();
	}

	public static function set( string $mode ): bool {
		if ( ! in_array( $mode, self::MODES, true ) ) {
			return false;
		}
		update_option( self::OPTION, $mode, true );
		return true;
	}

	/**
	 * 取一个指标：关 → 旧值；影子 → 旧值（顺便对账）；开 → 新值。
	 *
	 * @param string   $metric    对账日志里的名字，例如 overview.totals。
	 * @param mixed    $legacy    旧算法的结果（数字，或者数字组成的数组）。
	 * @param callable $modern    新算法，只在影子和开的时候才调用。
	 * @param array    $context   写进日志的上下文（时间段等），只放标量。
	 * @param float    $tolerance 数字相差不超过这个值算一致（金额用美分时填 0）。
	 * @return mixed
	 */
	public static function pick( string $metric, $legacy, callable $modern, array $context = array(), float $tolerance = 0.0 ) {
		$mode = self::mode();
		if ( 'off' === $mode ) {
			return $legacy;
		}
		try {
			$value = $modern();
		} catch ( \Throwable $e ) {
			self::record( $metric, array( array( 'path' => '', 'legacy' => null, 'metrics' => null, 'error' => substr( $e->getMessage(), 0, 200 ) ) ), $context );
			return $legacy;
		}
		self::record( $metric, self::diff( $legacy, $value, $tolerance ), $context );
		return 'on' === $mode ? $value : $legacy;
	}

	// ---------- 纯比较 ----------

	/** 两个值算不算一致：数字按容差比，其余要完全相等；null 只和 null 一致。 */
	public static function same( $a, $b, float $tolerance = 0.0 ): bool {
		if ( null === $a || null === $b ) {
			return $a === $b;
		}
		if ( is_bool( $a ) || is_bool( $b ) ) {
			return $a === $b;
		}
		if ( is_numeric( $a ) && is_numeric( $b ) ) {
			return abs( (float) $a - (float) $b ) <= $tolerance + 1e-9;
		}
		return $a === $b;
	}

	/**
	 * 逐项比较，返回不一致的地方：[ path, legacy, metrics ]。数组按 key 递归；只在一边出现的 key 也算不一致。
	 *
	 * @return array<int,array{path:string,legacy:mixed,metrics:mixed}>
	 */
	public static function diff( $legacy, $modern, float $tolerance = 0.0, string $path = '' ): array {
		if ( is_array( $legacy ) && is_array( $modern ) ) {
			$out = array();
			foreach ( array_unique( array_merge( array_keys( $legacy ), array_keys( $modern ) ) ) as $key ) {
				$sub = '' === $path ? (string) $key : $path . '.' . $key;
				if ( ! array_key_exists( $key, $legacy ) || ! array_key_exists( $key, $modern ) ) {
					$out[] = array( 'path' => $sub, 'legacy' => self::plain( $legacy[ $key ] ?? null ), 'metrics' => self::plain( $modern[ $key ] ?? null ) );
					continue;
				}
				foreach ( self::diff( $legacy[ $key ], $modern[ $key ], $tolerance, $sub ) as $found ) {
					$out[] = $found;
				}
			}
			return $out;
		}
		if ( self::same( $legacy, $modern, $tolerance ) ) {
			return array();
		}
		return array( array( 'path' => $path, 'legacy' => self::plain( $legacy ), 'metrics' => self::plain( $modern ) ) );
	}

	/** 日志里只放标量：数组和对象压成一小段文字。 */
	private static function plain( $value ) {
		if ( null === $value || is_scalar( $value ) ) {
			return $value;
		}
		return substr( (string) json_encode( $value ), 0, 200 );
	}

	// ---------- 对账日志 ----------

	private static function record( string $metric, array $diffs, array $context ): void {
		self::$pending[] = array( 'metric' => $metric, 'diffs' => array_slice( $diffs, 0, 20 ), 'context' => $context, 'at' => time() );
		if ( $diffs ) {
			// F1 的巡检或别的模块可以挂这个钩子把不一致报出去。
			do_action( 'peptide_core_m6_shadow_diff', $metric, $diffs, $context );
		}
		if ( ! self::$hooked ) {
			self::$hooked = true;
			add_action( 'shutdown', array( self::class, 'flush' ) );
		}
	}

	/** 把本次请求的对账结果并进选项：每个指标比了几次、几次不一致，以及最近 100 种不一致（同一处不一致只留最新的数值）。 */
	public static function flush(): void {
		if ( ! self::$pending ) {
			return;
		}
		$log = self::log();
		foreach ( self::$pending as $item ) {
			$metric                     = (string) $item['metric'];
			$log['compared'][ $metric ] = (int) ( $log['compared'][ $metric ] ?? 0 ) + 1;
			if ( ! $item['diffs'] ) {
				continue;
			}
			$log['differed'][ $metric ] = (int) ( $log['differed'][ $metric ] ?? 0 ) + 1;
			$key                        = md5( $metric . '|' . json_encode( $item['context'] ) . '|' . implode( ',', array_column( $item['diffs'], 'path' ) ) );
			$log['entries'][ $key ]     = array(
				'metric'   => $metric,
				'context'  => $item['context'],
				'diffs'    => $item['diffs'],
				'count'    => (int) ( $log['entries'][ $key ]['count'] ?? 0 ) + 1,
				'first_at' => (int) ( $log['entries'][ $key ]['first_at'] ?? $item['at'] ),
				'last_at'  => (int) $item['at'],
			);
		}
		if ( count( $log['entries'] ) > self::MAX_ENTRIES ) {
			uasort( $log['entries'], static fn( $a, $b ) => (int) $b['last_at'] <=> (int) $a['last_at'] );
			$log['entries'] = array_slice( $log['entries'], 0, self::MAX_ENTRIES, true );
		}
		self::$pending = array();
		update_option( self::LOG_OPTION, $log, false );
	}

	/**
	 * 对账日志。
	 *
	 * @return array{since:int,compared:array<string,int>,differed:array<string,int>,entries:array<string,array>}
	 */
	public static function log(): array {
		$log = get_option( self::LOG_OPTION, array() );
		$log = is_array( $log ) ? $log : array();
		return array(
			'since'    => (int) ( $log['since'] ?? time() ),
			'compared' => is_array( $log['compared'] ?? null ) ? $log['compared'] : array(),
			'differed' => is_array( $log['differed'] ?? null ) ? $log['differed'] : array(),
			'entries'  => is_array( $log['entries'] ?? null ) ? $log['entries'] : array(),
		);
	}

	/** 清空对账日志（换档或修完差异之后重新观察）。 */
	public static function reset_log(): void {
		delete_option( self::LOG_OPTION );
	}

	/** 测试用：丢掉还没写入的对账结果。 */
	public static function discard_pending(): array {
		$pending       = self::$pending;
		self::$pending = array();
		return $pending;
	}
}
