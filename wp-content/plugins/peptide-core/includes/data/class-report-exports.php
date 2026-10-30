<?php
/** Product/page CSV transport and typed cells; report selection and mathematics stay shared. */
namespace PeptideCore\Data;

use PeptideCore\Api\Errors;

defined( 'ABSPATH' ) || exit;

final class Report_Exports {

	/** One unchanged report snapshot feeds either the legacy JSON or the typed export. */
	public static function conversion_response( \WP_REST_Request $request, array $range, string $group ) {
		$params = $request->get_params();
		$format = $params['format'] ?? 'json';
		if ( array_key_exists( 'format', $params ) && ( ! is_string( $params['format'] ) || ! in_array( $format, array( 'json', 'csv' ), true ) ) ) { return Errors::invalid( array( 'format' => 'invalid_format' ) ); }
		if ( 'csv' === $format ) {
			$errors = array();
			foreach ( array( '_envelope', '_jsonp', '_fields' ) as $key ) { if ( array_key_exists( $key, $params ) ) { $errors[$key] = 'invalid_format'; } }
			if ( $errors ) { return Errors::invalid( $errors ); }
		}
		$summary = Conversion::summary( $range, $group );
		if ( 'json' === $format ) {
			$response = new \WP_REST_Response( $summary );
			$response->header( 'Cache-Control', 'no-store' );
			return $response;
		}
		return self::conversion_export( $summary, get_woocommerce_currency() );
	}

	/** Validate eagerly: no headers/BOM may escape before a missing detailed contract fails. */
	private static function conversion_export( array $summary, string $currency ) {
		if ( ! self::conversion_shape( $summary ) ) {
			return new \WP_Error( 'pc_export_unavailable', __( 'Conversion export is unavailable. Refresh the report and try again.', 'peptide-core' ), array( 'status' => 500 ) );
		}
		return new Csv_Response( self::conversion_rows( $summary, $currency ), 'data-conversion-' . $summary['group'] . '-' . $summary['range']['dates']['from'] . '-' . $summary['range']['dates']['to'] . '.csv' );
	}

	private static function conversion_shape( array $s ): bool {
		$number = static fn( $v ): bool => null === $v || ( ( is_int( $v ) || is_float( $v ) ) && is_finite( (float) $v ) );
		$layers = static function ( $rows ) use ( $number ): bool {
			if ( ! is_array( $rows ) || array_column( $rows, 'key' ) !== Visitor_Funnel::KEYS ) { return false; }
			foreach ( $rows as $row ) {
				if ( ! is_int( $row['count'] ?? null ) || $row['count'] < 0 ) { return false; }
				foreach ( array( 'visitor_rate', 'step_rate' ) as $key ) { if ( ! array_key_exists( $key, $row ) || ! $number( $row[$key] ) ) { return false; } }
			}
			return true;
		};
		if ( ! in_array( $s['group'] ?? null, Conversion::GROUPS, true ) || ! is_array( $s['rows'] ?? null ) || ! is_array( $s['range'] ?? null ) || ! is_array( $s['range']['dates'] ?? null ) ) { return false; }
		foreach ( array( 'from', 'to', 'compare_from', 'compare_to' ) as $key ) { if ( ! is_int( $s['range'][$key] ?? null ) || ! is_string( $s['range']['dates'][$key] ?? null ) ) { return false; } }
		$f = $s['visitor_funnel'] ?? array();
		if ( ! is_array( $f ) || ( $f['unit'] ?? null ) !== 'visitors' || ! $layers( $f['current'] ?? null ) || ! $layers( $f['previous'] ?? null ) || ! is_array( $f['change'] ?? null ) ) { return false; }
		foreach ( Visitor_Funnel::KEYS as $key ) { if ( ! array_key_exists( $key, $f['change'] ?? array() ) || ! $number( $f['change'][$key] ) ) { return false; } }
		foreach ( $s['rows'] as $row ) {
			if ( ! is_array( $row ) || ! is_string( $row['key'] ?? null ) || ! array_key_exists( 'sales', $row ) || null === $row['sales'] || ! $number( $row['sales'] ) || ( $row['visitor_funnel']['unit'] ?? null ) !== 'visitors' || ! $layers( $row['visitor_funnel']['layers'] ?? null ) ) { return false; }
		}
		$c = $s['checkout_steps'] ?? array();
		if ( ! is_bool( $c['local_simulation'] ?? null ) || ! is_array( $c['labels'] ?? null ) || array_keys( $c['labels'] ) !== array_keys( Checkout_Steps::EVENTS ) || ( $c['sources'] ?? null ) !== Checkout_Steps::EVENTS || ! is_array( $c['channels'] ?? null ) ) { return false; }
		$sets = array( $c );
		foreach ( $c['channels'] as $channel ) { if ( ! is_string( $channel['method'] ?? null ) ) { return false; } $sets[] = $channel; }
		foreach ( $sets as $index => $set ) {
			foreach ( array( 'current', 'previous', 'change' ) as $period ) {
				if ( ! is_array( $set[$period] ?? null ) ) { return false; }
				foreach ( 0 === $index ? array_keys( Checkout_Steps::EVENTS ) : self::conversion_channel_units() as $key => $value ) {
					$metric = 0 === $index ? $value : $key;
					if ( ! array_key_exists( $metric, $set[$period] ?? array() ) || ! $number( $set[$period][$metric] ) || ( 'change' !== $period && ( 0 === $index || 'success_rate' !== $metric ) && ( ! is_int( $set[$period][$metric] ) || $set[$period][$metric] < 0 ) ) ) { return false; }
				}
			}
		}
		return true;
	}

	private static function conversion_channel_units(): array {
		return array( 'initial' => 'visits', 'user' => 'visits', 'automatic' => 'visits', 'attempts' => 'events', 'attempt_visits' => 'visits', 'attempt_orders' => 'orders', 'succeeded' => 'orders', 'failed' => 'orders', 'success_rate' => 'percent' );
	}

	/** Pure presentation projection: no report queries, identity data, backfills or invented comparisons. */
	private static function conversion_rows( array $s, string $currency ): iterable {
		yield array( 'section', 'group', 'key', 'metric', 'unit', 'current', 'previous', 'change', 'change_unit', 'currency', 'source', 'comparison', 'current_from_epoch_s', 'current_to_epoch_s', 'previous_from_epoch_s', 'previous_to_epoch_s', 'local_simulation' );
		$bounds = array_map( static fn( $key ) => (string) $s['range'][$key], array( 'from', 'to', 'compare_from', 'compare_to' ) );
		$local = $s['checkout_steps']['local_simulation'] ? '1' : '0';
		$row = static function ( string $section, string $group, string $key, string $metric, string $unit, $current, $previous = null, $change = null, string $change_unit = '', string $source = '', string $comparison = 'current_only' ) use ( $currency, $bounds, $local ): array {
			return array_merge( array( Csv_Response::text( $section ), Csv_Response::text( $group ), Csv_Response::text( $key ), Csv_Response::text( $metric ), Csv_Response::text( $unit ), self::number( $current, in_array( $unit, array( 'percent', 'currency' ), true ) ), self::number( $previous, in_array( $unit, array( 'percent', 'currency' ), true ) ), self::number( $change, true ), Csv_Response::text( $change_unit ), Csv_Response::text( 'currency' === $unit ? $currency : '' ), Csv_Response::text( $source ), Csv_Response::text( $comparison ) ), $bounds, array( $local ) );
		};
		$f = $s['visitor_funnel']; $current = array_column( $f['current'], null, 'key' ); $previous = array_column( $f['previous'], null, 'key' );
		foreach ( array( 'conversion', 'paid', 'visitors', 'viewed', 'carted', 'checkout', 'address', 'shipping', 'payment' ) as $key ) {
			$c = 'conversion' === $key ? $current['paid']['visitor_rate'] : $current[$key]['count'];
			$p = 'conversion' === $key ? $previous['paid']['visitor_rate'] : $previous[$key]['count'];
			$d = 'conversion' === $key ? ( null === $c || null === $p ? null : round( $c - $p, 2 ) ) : $f['change'][$key];
			yield $row( 'visitor_overall', '', 'overall', $key, 'conversion' === $key ? 'percent' : 'visitors', $c, $p, $d, 'conversion' === $key ? 'percentage_points' : 'percent', '', 'periods' );
		}
		$c = $s['checkout_steps'];
		foreach ( $c['labels'] as $key => $unused ) { yield $row( 'observed_checkout', '', $key, 'count', 'visits', $c['current'][$key], $c['previous'][$key], $c['change'][$key], 'percent', $c['sources'][$key], 'periods' ); }
		foreach ( $c['channels'] as $channel ) {
			foreach ( self::conversion_channel_units() as $key => $unit ) { yield $row( 'payment_channels', 'payment_method', $channel['method'], $key, $unit, $channel['current'][$key], $channel['previous'][$key], $channel['change'][$key], 'success_rate' === $key ? 'percentage_points' : 'percent', '', 'periods' ); }
		}
		foreach ( $s['rows'] as $group ) {
			$by_key = array_column( $group['visitor_funnel']['layers'], null, 'key' ); $name = $s['group']; $key = $group['key'];
			yield $row( 'visitor_groups', $name, $key, 'conversion', 'percent', $by_key['paid']['visitor_rate'] );
			yield $row( 'visitor_groups', $name, $key, 'sales', 'currency', $group['sales'] );
			foreach ( Visitor_Funnel::KEYS as $step ) {
				yield $row( 'visitor_groups', $name, $key, $step . '_count', 'visitors', $by_key[$step]['count'] );
				yield $row( 'visitor_groups', $name, $key, $step . '_visitor_rate', 'percent', $by_key[$step]['visitor_rate'] );
			}
			foreach ( array( 'compact' => array( 'visitors', 'viewed', 'carted', 'checkout', 'paid' ), 'detailed' => Visitor_Funnel::KEYS ) as $mode => $steps ) {
				for ( $i = 1; $i < count( $steps ); ++$i ) {
					$a = $steps[$i - 1]; $b = $steps[$i]; $rate = $by_key[$a]['count'] ? floor( $by_key[$b]['count'] / $by_key[$a]['count'] * 10000 + 0.5 ) / 100 : null;
					yield $row( 'visitor_group_' . $mode . '_transitions', $name, $key, $a . '_to_' . $b . '_step_rate', 'percent', $rate );
				}
			}
		}
	}

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
