<?php
/** Product/page CSV transport and typed cells; report selection and mathematics stay shared. */
namespace PeptideCore\Data;

use PeptideCore\Api\Errors;

defined( 'ABSPATH' ) || exit;

final class Report_Exports {

	/** Complete ranking rows share the same narrow transport and typed cells as the two existing exports. */
	public static function ranking_response( \WP_REST_Request $request, array $range, array $args ) {
		if ( 'json' === $args['format'] ) {
			$response = new \WP_REST_Response( Rankings::listing( $range, $args ) );
			$response->header( 'Cache-Control', 'no-store' );
			return $response;
		}
		$errors = array();
		foreach ( array( '_envelope', '_jsonp', '_fields' ) as $key ) { if ( array_key_exists( $key, $request->get_params() ) ) { $errors[$key] = 'invalid_format'; } }
		if ( $errors ) { return Errors::invalid( $errors ); }
		return new Csv_Response( self::rankings( $range, $args ), 'data-rankings-' . $args['table'] . '-' . $range['dates']['from'] . '-' . $range['dates']['to'] . '.csv' );
	}

	private static function rankings( array $range, array $args ): iterable {
		yield array( 'table', 'key', 'label', 'current', 'previous', 'change', 'value_unit', 'change_unit', 'currency', 'current_from_epoch_s', 'current_to_epoch_s', 'previous_from_epoch_s', 'previous_to_epoch_s' );
		$units = Rankings::units( $args['table'] );
		$metadata = array( Csv_Response::text( $units['value_unit'] ), Csv_Response::text( $units['change_unit'] ), Csv_Response::text( $units['currency'] ), (string) $range['from'], (string) $range['to'], (string) $range['compare_from'], (string) $range['compare_to'] );
		foreach ( Rankings::all_rows( $range, $args ) as $row ) {
			yield array_merge( array( Csv_Response::text( $args['table'] ), Csv_Response::text( $row['key'] ), Csv_Response::text( $row['label'] ), self::number( $row['current'], 'count' !== $units['value_unit'] ), self::number( $row['previous'], 'count' !== $units['value_unit'] ), self::number( $row['change'], true ) ), $metadata );
		}
	}

	public static function response( \WP_REST_Request $request, string $report, array $range, array $args ) {
		$params = $request->get_params();
		$format = $params['format'] ?? 'json';
		if ( array_key_exists( 'format', $params ) && ( ! is_string( $params['format'] ) || ! in_array( $format, array( 'json', 'csv' ), true ) ) ) { return Errors::invalid( array( 'format' => 'invalid_format' ) ); }
		if ( 'json' === $format ) {
			$res = new \WP_REST_Response( 'products' === $report ? Products::table( $range, $args ) : Page_Visits::table( $range, $args ) );
			$res->header( 'Cache-Control', 'no-store' );
			return $res;
		}
		$errors = array();
		foreach ( array( '_envelope', '_jsonp', '_fields' ) as $key ) { if ( array_key_exists( $key, $params ) ) { $errors[ $key ] = 'invalid_format'; } }
		$columns = Products::DEFAULTS;
		if ( 'products' === $report && array_key_exists( 'columns', $params ) ) {
			if ( ! is_string( $params['columns'] ) ) { $errors['columns'] = 'invalid_format'; }
			else {
				$columns = '' === $params['columns'] ? array() : array_values( array_unique( explode( ',', $params['columns'] ) ) );
				if ( array_diff( $columns, Products::METRICS ) ) { $errors['columns'] = 'invalid_format'; }
			}
		}
		if ( $errors ) { return Errors::invalid( $errors ); }
		$rows = 'products' === $report ? self::products( $range, $args, $columns ) : self::pages( $range, $args );
		return new Csv_Response( $rows, 'data-' . $report . '-' . $range['dates']['from'] . '-' . $range['dates']['to'] . '.csv' );
	}

	/** Null stays blank; decimal values are finite, locale independent, with two places. */
	private static function number( $value, bool $decimal = false ): string {
		if ( null === $value || ! is_numeric( $value ) || ! is_finite( (float) $value ) ) { return ''; }
		return $decimal ? number_format( (float) $value, 2, '.', '' ) : (string) (int) $value;
	}

	private static function metrics( array $row, array $columns ): array {
		$cells = array();
		foreach ( $columns as $key ) {
			$decimal = in_array( $key, array( 'sales', 'net_sales', 'cart_rate', 'conversion' ), true );
			$cells[] = self::number( $row['current'][ $key ], $decimal );
			$cells[] = self::number( $row['previous'][ $key ], $decimal );
			$cells[] = self::number( $row['change'][ $key ], true );
		}
		return $cells;
	}

	private static function products( array $range, array $args, array $columns ): iterable {
		$head = array( 'row_type', 'product_id', 'variation_id', 'product_name', 'variation_name', 'sku', 'currency', 'current_from_epoch_s', 'current_to_epoch_s', 'previous_from_epoch_s', 'previous_to_epoch_s' );
		foreach ( $columns as $key ) {
			$rate = in_array( $key, Products::RATES, true );
			$head[] = 'current_' . $key . ( $rate ? '_pct' : '' );
			$head[] = 'previous_' . $key . ( $rate ? '_pct' : '' );
			$head[] = 'change_' . $key . ( $rate ? '_pp' : '_pct' );
		}
		yield $head;
		$metadata = array( Csv_Response::text( get_woocommerce_currency() ), (string) $range['from'], (string) $range['to'], (string) $range['compare_from'], (string) $range['compare_to'] );
		foreach ( Products::all_rows( $range, $args ) as $row ) {
			$p = $row['product'];
			yield array_merge( array( 'product', (string) $p['id'], '', Csv_Response::text( $p['name'] ), '', Csv_Response::text( $p['sku'] ) ), $metadata, self::metrics( $row, $columns ) );
			foreach ( $row['variations'] as $variation ) {
				$v = $variation['variation'];
				yield array_merge( array( 'variation', (string) $p['id'], (string) $v['id'], Csv_Response::text( $p['name'] ), Csv_Response::text( $v['name'] ), Csv_Response::text( $v['sku'] ) ), $metadata, self::metrics( $variation, $columns ) );
			}
		}
	}

	private static function pages( array $range, array $args ): iterable {
		yield array( 'path', 'url', 'views', 'visitors', 'bounce_rate_pct', 'current_from_epoch_s', 'current_to_epoch_s' );
		foreach ( Page_Visits::all_rows( $range, $args ) as $row ) {
			yield array( Csv_Response::text( $row['path'] ), Csv_Response::text( $row['url'] ), self::number( $row['views'] ), self::number( $row['visitors'] ), self::number( $row['bounce_rate'], true ), (string) $range['from'], (string) $range['to'] );
		}
	}
}
