<?php
/** Source-backed groups of the existing eight-step visit cohort, not a new financial model. */
namespace PeptideCore\Data;

use PeptideCore\Tracking\Schema;

defined( 'ABSPATH' ) || exit;

final class Dimension_Conversion {
	public static function summary( array $range, string $group ): array {
		if ( ! isset( Dimension_Reports::GROUPS[$group] ) ) { throw new \InvalidArgumentException( 'Invalid conversion dimension.' ); }
		$from = (int) $range['from']; $to = (int) $range['to']; $dimension = Dimension_Reports::GROUPS[$group];
		$current = Conversion::overall( Operations::period( $from, $to ) );
		$previous = Conversion::overall( Operations::period( (int) $range['compare_from'], (int) $range['compare_to'] ) );
		$mapping = Dimension_Reports::mapping( Dimension_Reports::cohort( $from, $to ), $dimension );
		$reader = new Visitor_Funnel();
		$detail = $reader->period( $from * 1000, $to * 1000, 'start_ms', '', null, null, $mapping['map'] );
		$before = $reader->period( (int) $range['compare_from'] * 1000, (int) $range['compare_to'] * 1000 );
		$paid = Conversion::paid_groups( $from, $to, $group );
		$rows = self::rows( $detail['groups'], $paid, $mapping['typed'], $dimension );
		$change = array();
		foreach ( Conversion::OVERALL as $key ) {
			$c = $current[$key]; $p = $previous[$key];
			$change[$key] = null === $c || null === $p ? null : ( 'conversion' === $key ? round( $c - $p, 2 ) : Range::change( (float) $c, (float) $p ) );
		}
		return array( 'range' => $range, 'tracking' => Schema::installed(), 'group' => $group, 'groups' => Conversion::GROUPS, 'overall' => array( 'current' => $current, 'previous' => $previous, 'change' => $change ), 'rows' => $rows, 'visitor_funnel' => Visitor_Funnel::compare( $detail['overall'], $before['overall'] ), 'checkout_steps' => Checkout_Steps::summary( $range ), 'dimension_coverage' => $mapping['coverage'], 'dimension_scope' => 'visit_start_cohort_saved_facts_before_period_end', 'overall_scope' => 'all_visitors', 'checkout_steps_scope' => 'all_visits' );
	}

	/** Pure merge; payment UV comes only from Visitor_Funnel, money only from Conversion. */
	public static function rows( array $groups, array $paid, array $typed, string $dimension ): array {
		$out = array();
		foreach ( array_keys( $groups + $paid ) as $key ) {
			$funnel = $groups[$key] ?? array( 'unit' => 'visitors', 'layers' => Visitor_Funnel::layers( Visitor_Funnel::zero() ) );
			$counts = array_column( $funnel['layers'], 'count', 'key' ); $financial = $paid[$key] ?? array( 'paid' => 0, 'sales' => 0.0 );
			$value = $typed[$key] ?? $financial['dimension']; $visitors = $counts['visitors'];
			$out[] = array( 'key' => $key, 'label' => Dimension_Reports::label( $value, $dimension ), 'dimension' => $value, 'conversion' => $visitors ? round( $financial['paid'] / $visitors * 100, 2 ) : null, 'sales' => round( $financial['sales'], 2 ), 'visitors' => $visitors, 'paid' => $financial['paid'], 'funnel' => Operations::layers( array( 'visitors' => $visitors, 'viewed' => $counts['viewed'], 'carted' => $counts['carted'], 'checkout' => $counts['checkout'], 'paid' => $financial['paid'] ) ), 'visitor_funnel' => $funnel );
		}
		usort( $out, static function ( array $a, array $b ): int { $cmp = $b['visitors'] <=> $a['visitors']; $cmp = $cmp ?: $b['paid'] <=> $a['paid']; return $cmp ?: strcmp( $a['key'], $b['key'] ); } );
		return $out;
	}
}
