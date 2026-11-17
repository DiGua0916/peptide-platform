<?php
/**
 * 1 号站 P2-1（2026-11-17）：规格参数名统一显示成 “Specifications”。
 *
 * 商品里同一个参数有 Specifications / Specification / Specificatio / size 几种写法。参数的内部标识
 * （attribute_specificatio 等）被规格、购物车和链接引用，不能改；这里只改显示出来的名字。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Catalog_Labels {

	private const SPEC = array( 'specification', 'specifications', 'specificatio', 'size' );

	public static function register_hooks(): void {
		add_filter( 'woocommerce_attribute_label', array( self::class, 'label' ), 20, 1 );
	}

	public static function label( $label ) {
		return is_string( $label ) && in_array( strtolower( trim( $label ) ), self::SPEC, true ) ? 'Specifications' : $label;
	}
}
