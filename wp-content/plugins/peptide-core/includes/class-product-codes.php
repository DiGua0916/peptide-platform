<?php
/**
 * 1 号站（2026-11-17）：简称搜索。
 *
 * 店主的报价表（includes/data/product-codes.json，从“产品报价_售价与原价.xlsx”生成）给每个商品规格一个简称：
 * TR30 = Tirzepatide 30mg，RT10 = Retatrutide 10mg，CGL5 = Cagrilintide 5mg……
 * - 首页商品区的搜索（主题 demo-catalog）把这些简称加进每个商品的搜索文字，输入 TR30 或 tr 就能找到；
 * - 站内搜索（/?s=、商品搜索）输入完整简称（TR30）或字母部分（TR）时，改成按对应的商品名搜索。
 * 商品名按“只留小写字母和数字”比较，所以大小写、空格、括号、连字符不影响；改名（Cagriliintide → Cagrilintide、
 * bac.water → Bacteriostatic Water）前后的名字都在表里。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Product_Codes {

	/** @var array<string,array<int,array{0:string,1:string}>>|null */
	private static ?array $map = null;

	public static function register_hooks(): void {
		add_action( 'pre_get_posts', array( self::class, 'search' ) );
	}

	public static function key( string $name ): string {
		if ( class_exists( '\Normalizer' ) ) {
			$name = (string) \Normalizer::normalize( $name, \Normalizer::FORM_KC );
		}
		return (string) preg_replace( '/[^a-z0-9]/', '', strtolower( html_entity_decode( $name, ENT_QUOTES, 'UTF-8' ) ) );
	}

	/** @return array<string,array<int,array{0:string,1:string}>> */
	public static function map(): array {
		if ( null === self::$map ) {
			$raw       = is_readable( __DIR__ . '/data/product-codes.json' ) ? json_decode( (string) file_get_contents( __DIR__ . '/data/product-codes.json' ), true ) : null;
			self::$map = is_array( $raw['products'] ?? null ) ? $raw['products'] : array();
		}
		return self::$map;
	}

	/** Abbreviations for a product name, e.g. Tirzepatide → [TR10, TR15, …]. */
	public static function for_name( string $name ): array {
		return array_values( array_unique( array_map( static fn( $row ) => (string) $row[0], self::map()[ self::key( $name ) ] ?? array() ) ) );
	}

	/**
	 * Extra catalog search words for a product: its codes, plus the other spelling of a renamed product
	 * (Cagriliintide ↔ Cagrilintide, bac.water ↔ Bacteriostatic Water), so either spelling finds it.
	 */
	public static function search_words( string $name ): string {
		$key = self::key( $name ); $rows = self::map()[ $key ] ?? array();
		if ( ! $rows ) {
			return '';
		}
		$codes = array_map( static fn( $row ) => (string) $row[0], $rows ); $words = $codes;
		foreach ( self::map() as $other => $other_rows ) {
			if ( $other !== $key && array_intersect( $codes, array_map( static fn( $row ) => (string) $row[0], $other_rows ) ) ) { $words[] = $other; }
		}
		return implode( ' ', array_unique( $words ) );
	}

	/**
	 * The product name a search term stands for: a full code (TR30) or a code's letter part (TR).
	 * Returns null when the term is not a code, so ordinary searches are untouched.
	 */
	public static function name_for_term( string $term ): ?string {
		$term = strtoupper( trim( $term ) );
		if ( '' === $term || strlen( $term ) > 12 || str_contains( $term, ' ' ) ) {
			return null;
		}
		$exact = null; $family = null;
		foreach ( self::map() as $name => $rows ) {
			foreach ( $rows as $row ) {
				$code = strtoupper( (string) $row[0] );
				if ( $code === $term ) { $exact = $exact ?? $name; }
				if ( null === $family && preg_match( '/^([A-Z]+)/', $code, $m ) && $m[1] === $term && strlen( $term ) >= 2 ) { $family = $name; }
			}
		}
		$key = $exact ?? $family;
		return null === $key ? null : self::product_name_for_key( $key );
	}

	private static function product_name_for_key( string $key ): ?string {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return null;
		}
		foreach ( wc_get_products( array( 'status' => 'publish', 'limit' => -1, 'return' => 'objects', 'type' => array( 'simple', 'variable' ) ) ) as $product ) {
			if ( self::key( $product->get_name() ) === $key ) {
				return $product->get_name();
			}
		}
		return null;
	}

	/** Site search: a code becomes the product name it stands for. */
	public static function search( $query ): void {
		if ( is_admin() || ! $query instanceof \WP_Query || ! $query->is_main_query() || ! $query->is_search() ) {
			return;
		}
		$name = self::name_for_term( (string) $query->get( 's' ) );
		if ( null !== $name ) {
			$query->set( 's', $name );
		}
	}
}
