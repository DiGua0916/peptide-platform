<?php
/**
 * 访客的系统、版本、机型（2026-11-13，用户要求追踪到每个人的机型）。纯函数，不读数据库。
 *
 * 能拿到多少，取决于浏览器给多少：
 * - 安卓：标识里一般带型号（SM-S918B、Pixel 8）；新版 Chrome 把它藏成 “K”，这时用客户端提示 Sec-CH-UA-Model
 *   （Tracker 在前台页面上发 Accept-CH 请求它）。三星等常见型号翻成市场名。
 * - iPhone：Safari 不告诉网页具体型号，只有 “iPhone + iOS 版本”；FB / IG 内置浏览器带了型号代码（FBDV/iPhone15,3、
 *   Instagram 的 (iPhone14,5; …)），翻成 iPhone 14 Pro Max 这类名字。
 * - 电脑：只能分 Windows / macOS / Linux；Windows 10 和 11 的标识一样，有 Sec-CH-UA-Platform-Version 时才分得出。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Tracking;

defined( 'ABSPATH' ) || exit;

final class User_Agent {

	/** 苹果型号代码 → 名称（iPhone 8 起）。 */
	private const APPLE = array(
		'iPhone10,1' => 'iPhone 8', 'iPhone10,4' => 'iPhone 8', 'iPhone10,2' => 'iPhone 8 Plus', 'iPhone10,5' => 'iPhone 8 Plus', 'iPhone10,3' => 'iPhone X', 'iPhone10,6' => 'iPhone X',
		'iPhone11,2' => 'iPhone XS', 'iPhone11,4' => 'iPhone XS Max', 'iPhone11,6' => 'iPhone XS Max', 'iPhone11,8' => 'iPhone XR',
		'iPhone12,1' => 'iPhone 11', 'iPhone12,3' => 'iPhone 11 Pro', 'iPhone12,5' => 'iPhone 11 Pro Max', 'iPhone12,8' => 'iPhone SE (2nd gen)',
		'iPhone13,1' => 'iPhone 12 mini', 'iPhone13,2' => 'iPhone 12', 'iPhone13,3' => 'iPhone 12 Pro', 'iPhone13,4' => 'iPhone 12 Pro Max',
		'iPhone14,4' => 'iPhone 13 mini', 'iPhone14,5' => 'iPhone 13', 'iPhone14,2' => 'iPhone 13 Pro', 'iPhone14,3' => 'iPhone 13 Pro Max', 'iPhone14,6' => 'iPhone SE (3rd gen)',
		'iPhone14,7' => 'iPhone 14', 'iPhone14,8' => 'iPhone 14 Plus', 'iPhone15,2' => 'iPhone 14 Pro', 'iPhone15,3' => 'iPhone 14 Pro Max',
		'iPhone15,4' => 'iPhone 15', 'iPhone15,5' => 'iPhone 15 Plus', 'iPhone16,1' => 'iPhone 15 Pro', 'iPhone16,2' => 'iPhone 15 Pro Max',
		'iPhone17,3' => 'iPhone 16', 'iPhone17,4' => 'iPhone 16 Plus', 'iPhone17,1' => 'iPhone 16 Pro', 'iPhone17,2' => 'iPhone 16 Pro Max', 'iPhone17,5' => 'iPhone 16e',
		'iPhone18,3' => 'iPhone 17', 'iPhone18,1' => 'iPhone 17 Pro', 'iPhone18,2' => 'iPhone 17 Pro Max', 'iPhone18,4' => 'iPhone Air',
	);

	/** 常见安卓型号前缀 → 市场名（按前缀匹配，地区后缀如 B / U / N 不影响）。 */
	private const ANDROID = array(
		'SM-S938' => 'Galaxy S25 Ultra', 'SM-S936' => 'Galaxy S25+', 'SM-S931' => 'Galaxy S25',
		'SM-S928' => 'Galaxy S24 Ultra', 'SM-S926' => 'Galaxy S24+', 'SM-S921' => 'Galaxy S24', 'SM-S721' => 'Galaxy S24 FE',
		'SM-S918' => 'Galaxy S23 Ultra', 'SM-S916' => 'Galaxy S23+', 'SM-S911' => 'Galaxy S23', 'SM-S711' => 'Galaxy S23 FE',
		'SM-S908' => 'Galaxy S22 Ultra', 'SM-S906' => 'Galaxy S22+', 'SM-S901' => 'Galaxy S22',
		'SM-G998' => 'Galaxy S21 Ultra', 'SM-G996' => 'Galaxy S21+', 'SM-G991' => 'Galaxy S21', 'SM-G990' => 'Galaxy S21 FE',
		'SM-F956' => 'Galaxy Z Fold6', 'SM-F741' => 'Galaxy Z Flip6', 'SM-F946' => 'Galaxy Z Fold5', 'SM-F731' => 'Galaxy Z Flip5',
		'SM-A566' => 'Galaxy A56', 'SM-A556' => 'Galaxy A55', 'SM-A546' => 'Galaxy A54', 'SM-A536' => 'Galaxy A53', 'SM-A356' => 'Galaxy A35', 'SM-A256' => 'Galaxy A25', 'SM-A156' => 'Galaxy A15', 'SM-A155' => 'Galaxy A15', 'SM-A146' => 'Galaxy A14', 'SM-A145' => 'Galaxy A14', 'SM-A057' => 'Galaxy A05s',
		'SM-S134' => 'Galaxy A03s', 'SM-S146' => 'Galaxy A14 5G', 'SM-S156' => 'Galaxy A15 5G',
	);

	/**
	 * @param string $ua         User-Agent
	 * @param string $ch_model   Sec-CH-UA-Model（可空，带引号也行）
	 * @param string $ch_version Sec-CH-UA-Platform-Version（可空）
	 * @return array{os:string,os_version:string,model:string}
	 */
	public static function parse( string $ua, string $ch_model = '', string $ch_version = '' ): array {
		$ch_model   = trim( $ch_model, " \t\"" );
		$ch_version = trim( $ch_version, " \t\"" );
		$out        = array( 'os' => '', 'os_version' => '', 'model' => '' );
		if ( '' === trim( $ua ) ) {
			return $out;
		}
		// 苹果移动设备。
		if ( preg_match( '/\((iPhone|iPad|iPod)[^)]*?OS (\d+(?:_\d+){0,2})/', $ua, $m ) ) {
			$out['os']         = 'iPad' === $m[1] ? 'iPadOS' : 'iOS';
			$out['os_version'] = str_replace( '_', '.', $m[2] );
			$code              = '';
			if ( preg_match( '/FBDV\/((?:iPhone|iPad)\d+,\d+)/', $ua, $c ) || preg_match( '/Instagram [\d.]+ \(((?:iPhone|iPad)\d+,\d+);/', $ua, $c ) ) {
				$code = $c[1];
			}
			$out['model'] = '' !== $code ? ( self::APPLE[ $code ] ?? $code ) : $m[1];
			return $out;
		}
		// 安卓。
		if ( preg_match( '/Android (\d+(?:\.\d+){0,2})/', $ua, $m ) ) {
			$out['os']         = 'Android';
			$out['os_version'] = $m[1];
			$model             = '';
			if ( preg_match( '/Android [\d.]+; (?:[a-z]{2}[-_][a-z]{2}; )?([^;)]+?)(?: Build\/[^;)]*)?[;)]/i', $ua, $c ) ) {
				$model = trim( $c[1] );
			}
			if ( preg_match( '/FBDV\/([^;\]]+)/', $ua, $c ) ) {
				$model = trim( $c[1] );
			}
			if ( '' === $model || 'K' === $model || 'wv' === $model ) {
				$model = $ch_model;
			}
			$out['model'] = self::android_name( $model );
			return $out;
		}
		if ( preg_match( '/Windows NT (\d+\.\d+)/', $ua, $m ) ) {
			$out['os']         = 'Windows';
			$major             = (int) $ch_version;
			$out['os_version'] = '10.0' === $m[1] ? ( $major >= 13 ? '11' : ( '' !== $ch_version ? '10' : '10/11' ) ) : $m[1];
			$out['model']      = 'PC';
			return $out;
		}
		if ( preg_match( '/Mac OS X (\d+[_.]\d+)/', $ua, $m ) ) {
			$out['os']         = 'macOS';
			$out['os_version'] = '' !== $ch_version ? $ch_version : ''; // 标识里固定写 10_15_7，没有参考价值。
			$out['model']      = 'Mac';
			return $out;
		}
		if ( str_contains( $ua, 'CrOS' ) ) {
			return array( 'os' => 'ChromeOS', 'os_version' => '', 'model' => 'Chromebook' );
		}
		if ( str_contains( $ua, 'Linux' ) ) {
			return array( 'os' => 'Linux', 'os_version' => '', 'model' => 'PC' );
		}
		return $out;
	}

	/** 安卓型号代码 → 市场名（认不出就原样，最多 60 字）。 */
	public static function android_name( string $code ): string {
		$code = substr( trim( (string) preg_replace( '/[^\w .+\-()]/u', '', $code ) ), 0, 60 );
		foreach ( self::ANDROID as $prefix => $name ) {
			if ( str_starts_with( strtoupper( $code ), $prefix ) ) {
				return $name . '（' . $code . '）';
			}
		}
		return $code;
	}
}
