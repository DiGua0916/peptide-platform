<?php
/**
 * 数据页接口的请求参数小工具：商品详情数据页和页面访问统计页共用（sort / order / page / per_page 这类“文字必须在允许的几个里”和“整数必须在范围内”的参数）。
 *
 * 规则：没传或为空就用默认值；传了但不合法返回 null，由调用方记成 invalid_format（接口返回 400）。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Data;

defined( 'ABSPATH' ) || exit;

final class Params {

	/** 取一个必须在 $allowed 里的文字参数：没传或为空用默认值；不在里面返回 null。 */
	public static function word( array $params, string $key, string $default, array $allowed ): ?string {
		$raw = $params[ $key ] ?? '';
		if ( ! is_scalar( $raw ) || is_bool( $raw ) ) {
			return null;
		}
		$text = trim( (string) $raw );
		if ( '' === $text ) {
			return $default;
		}
		return in_array( $text, $allowed, true ) ? $text : null;
	}

	/** 取一个整数参数：没传或为空用默认值；不是整数或超出 $min ~ $max 返回 null。 */
	public static function whole( array $params, string $key, int $default, int $min, int $max ): ?int {
		$raw = $params[ $key ] ?? '';
		if ( ! is_scalar( $raw ) || is_bool( $raw ) ) {
			return null;
		}
		$text = trim( (string) $raw );
		if ( '' === $text ) {
			return $default;
		}
		if ( 1 !== preg_match( '/^[+-]?\d{1,9}$/', $text ) ) {
			return null;
		}
		$n = (int) $text;
		return ( $n < $min || $n > $max ) ? null : $n;
	}
}
