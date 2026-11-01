<?php
/** Complete typed Operations dimensions; existing visitor/native financial reducers remain authoritative. */
namespace PeptideCore\Data;

use PeptideCore\Api\Errors;
use PeptideCore\Overview;
use PeptideCore\Tracking\Schema;
use PeptideCore\Tracking\Server_Events;

defined( 'ABSPATH' ) || exit;

final class Dimension_Details {
    public const DIMENSIONS = array( 'utm', 'country', 'browser', 'domain', 'device', 'channel' );
    public const METRICS = array( 'uv', 'customers', 'viewed_uv', 'carted_uv', 'paid_uv', 'orders', 'conversion', 'sales', 'aov', 'uv_value' );
    private const COUNTERS = array( 'base_count', 'matched_count', 'value', 'missing', 'empty', 'unknown', 'unavailable', 'unobserved' );

    public static function permission() {
        return get_current_user_id() > 0 && current_user_can( 'manage_woocommerce' ) ? true : new \WP_Error( 'pc_forbidden', __( 'Dimension details require an authenticated store manager.', 'peptide-core' ), array( 'status' => rest_authorization_required_code() ) );
    }
    public static function register_routes(): void {
        register_rest_route( 'peptide/v1', '/data/dimension-details', array( 'methods' => \WP_REST_Server::READABLE, 'permission_callback' => array( self::class, 'permission' ), 'callback' => array( self::class, 'response' ) ) );
    }
    private static function word( array $raw, string $key, string $default, array $allowed ): ?string {
        if ( ! array_key_exists( $key, $raw ) ) { return $default; }
        return is_string( $raw[$key] ) && in_array( trim( $raw[$key] ), $allowed, true ) ? trim( $raw[$key] ) : null;
    }
    private static function whole( array $raw, string $key, int $default, int $min, int $max ): ?int {
        if ( ! array_key_exists( $key, $raw ) ) { return $default; }
        $value = $raw[$key];
        if ( ! is_int( $value ) && ! ( is_string( $value ) && preg_match( '/\A[0-9]{1,9}\z/D', trim( $value ) ) ) ) { return null; }
        $value = (int) $value;
        return $value >= $min && $value <= $max ? $value : null;
    }
    public static function params( array $raw ) {
        $out = array(); $errors = array();
        foreach ( array(
            'dimension' => array( 'utm', self::DIMENSIONS ),
            'sort' => array( 'uv', self::METRICS ),
            'order' => array( 'desc', array( 'asc', 'desc' ) ),
            'sort_period' => array( 'current', array( 'current', 'previous', 'change' ) ),
        ) as $key => $rule ) { $out[$key] = self::word( $raw, $key, $rule[0], $rule[1] ); if ( null === $out[$key] ) { $errors[$key] = 'invalid_format'; } }
        foreach ( array( 'page' => array( 1, 1, 999999999 ), 'per_page' => array( 20, 1, 100 ), 'split_medium' => array( 0, 0, 1 ) ) as $key => $rule ) {
            $out[$key] = self::whole( $raw, $key, $rule[0], $rule[1], $rule[2] ); if ( null === $out[$key] ) { $errors[$key] = 'invalid_format'; }
        }
        if ( 'utm' !== $out['dimension'] && 1 === $out['split_medium'] ) { $errors['split_medium'] = 'incompatible_dimension'; }
        $out['q'] = '';
        if ( array_key_exists( 'q', $raw ) ) {
            if ( ! is_string( $raw['q'] ) || 1 !== preg_match( '//u', $raw['q'] ) ) { $errors['q'] = 'invalid_format'; }
            else { $out['q'] = trim( $raw['q'] ); if ( preg_match_all( '/./us', $out['q'] ) > 100 ) { $errors['q'] = 'invalid_format'; } }
        }
        $out['columns'] = self::METRICS;
        if ( array_key_exists( 'columns', $raw ) ) {
            if ( ! is_string( $raw['columns'] ) ) { $errors['columns'] = 'invalid_format'; }
            else {
                $columns = array_map( 'trim', explode( ',', $raw['columns'] ) );
                if ( ! $columns || count( $columns ) > 10 || count( array_unique( $columns ) ) !== count( $columns ) || array_diff( $columns, self::METRICS ) ) { $errors['columns'] = 'invalid_format'; }
                else { $out['columns'] = $columns; }
            }
        }
        foreach ( array( 'visitor_id', 'visit_id', 'order_id', 'customer_id', 'user_id', 'email', 'ip', 'user_agent' ) as $key ) { if ( array_key_exists( $key, $raw ) ) { $errors[$key] = 'unsupported_parameter'; } }
        $out['filter'] = Report_Filter::params( $raw ); if ( is_wp_error( $out['filter'] ) ) { return $out['filter']; }
        return $errors ? Errors::invalid( $errors ) : $out;
    }
    public static function response( \WP_REST_Request $request ) {
        $permission = self::permission(); if ( true !== $permission ) { return $permission; }
        $raw = $request->get_params(); $range = Range::from_params( $raw ); if ( is_wp_error( $range ) ) { return $range; }
        $args = self::params( $raw ); if ( is_wp_error( $args ) ) { return $args; }
        $format = $raw['format'] ?? 'json';
        if ( array_key_exists( 'format', $raw ) && ( ! is_string( $raw['format'] ) || ! in_array( $format, array( 'json', 'csv' ), true ) ) ) { return Errors::invalid( array( 'format' => 'invalid_format' ) ); }
        if ( 'csv' === $format ) { foreach ( array( '_envelope', '_jsonp', '_fields' ) as $key ) { if ( array_key_exists( $key, $raw ) ) { return Errors::invalid( array( $key => 'invalid_format' ) ); } } }
        try {
            if ( 'json' === $format ) { $response = new \WP_REST_Response( self::table( $range, $args ) ); $response->header( 'Cache-Control', 'no-store' ); return $response; }
            // Finish all native reads before Csv_Response can emit headers or any bytes.
            $selection = self::selection( $range, $args );
            return new Csv_Response( self::csv( $selection, $range, $args ), 'data-dimensions-' . $args['dimension'] . '-' . $range['dates']['from'] . '-' . $range['dates']['to'] . '.csv' );
        } catch ( \Throwable $error ) {
            return new \WP_Error( 'pc_report_unavailable', __( 'Report dimensions are unavailable. Try again.', 'peptide-core' ), array( 'status' => 503 ) );
        }
    }
    public static function table( array $range, array $args ): array {
        $selection = self::selection( $range, $args ); $rows = $selection['rows']; $total = count( $rows );
        return array( 'version' => 1, 'dimension' => $args['dimension'], 'range' => $range, 'tracking' => true, 'currency' => get_woocommerce_currency(), 'params' => $args, 'total' => $total, 'rows' => array_slice( $rows, ( $args['page'] - 1 ) * $args['per_page'], $args['per_page'] ), 'coverage' => $selection['coverage'] );
    }
    public static function all_rows( array $range, array $args ): iterable { return self::selection( $range, $args )['rows']; }
    private static function coverage( string $unit ): array { return array( 'unit' => $unit ) + array_fill_keys( self::COUNTERS, 0 ); }
    private static function typed( string $state, ?string $value = null ): array { return array( 'state' => $state, 'value' => $value ); }
    private static function identity( array $fact, ?array $original, array $args ): array {
        $dimension = $args['dimension'];
        if ( in_array( $dimension, array( 'device', 'browser' ), true ) ) {
            if ( ! $original || (int) $fact['visitor_id'] !== (int) $original['visitor_id'] ) { $typed = self::typed( 'unobserved' ); }
            else {
                $value = $original[ 'device' === $dimension ? 'device' : 'browser' ] ?? null;
                $allowed = 'device' === $dimension ? array( 'desktop', 'mobile', 'tablet' ) : array( 'Edge', 'Opera', 'Samsung', 'Chrome', 'Firefox', 'Safari', 'Other' );
                $typed = is_string( $value ) && in_array( $value, $allowed, true ) ? self::typed( 'value', $value ) : self::typed( 'unknown' );
            }
        } else { $field = array( 'utm' => 'utm_source', 'domain' => 'entry_domain', 'country' => 'country', 'channel' => 'channel' )[$dimension]; $typed = $fact[$field]; }
        $medium = 'utm' === $dimension && 1 === $args['split_medium'] ? $fact['utm_medium'] : null;
        $key = json_encode( array( $typed, $medium ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
        return array( 'key' => $key, 'dimension' => $typed, 'medium' => $medium );
    }
    private static function label( array $identity, string $dimension ): string {
        $typed = $identity['dimension'];
        if ( 'device' === $dimension ) {
            $devices = array( 'desktop' => __( 'Desktop', 'peptide-core' ), 'mobile' => __( 'Mobile', 'peptide-core' ), 'tablet' => __( 'Tablet', 'peptide-core' ) );
            $label = 'value' === $typed['state'] ? $devices[$typed['value']] : ( 'unknown' === $typed['state'] ? __( 'Unknown', 'peptide-core' ) : __( 'Not observed', 'peptide-core' ) );
        } elseif ( 'browser' === $dimension ) {
            $label = 'value' === $typed['state'] ? ( 'Other' === $typed['value'] ? __( 'Other', 'peptide-core' ) : $typed['value'] ) : ( 'unknown' === $typed['state'] ? __( 'Unknown', 'peptide-core' ) : __( 'Not observed', 'peptide-core' ) );
        } else { $label = Dimension_Reports::label( $typed, array( 'utm' => 'utm_source', 'domain' => 'entry_domain', 'country' => 'country', 'channel' => 'channel' )[$dimension] ); }
        return $label;
    }
    private static function matched( array &$coverage, array $fact, ?Report_Filter $filter, array $identity ): bool {
        ++$coverage['base_count'];
        if ( $filter && ! $filter->matches( $fact ) ) { return false; }
        ++$coverage['matched_count']; $state = $identity['dimension']['state'];
        if ( ! in_array( $state, array( 'value', 'missing', 'empty', 'unknown', 'unavailable', 'unobserved' ), true ) ) { throw new \RuntimeException( 'Invalid dimension state.' ); }
        ++$coverage[$state]; return true;
    }
    private static function add_group( array &$groups, array $identity ): string {
        $key = $identity['key']; if ( ! isset( $groups[$key] ) ) { $groups[$key] = array( 'identity' => $identity, 'metrics' => Visit_Metrics::blank() ); } return $key;
    }
    private static function checked_rows( $rows ): array {
        Report_Dimensions::check_db(); if ( ! is_array( $rows ) ) { throw new \RuntimeException( 'Report dimension rows unavailable.' ); } return $rows;
    }
    private static function original_orders( array $orders, int $cutoff ): array {
        global $wpdb; $pairs = array(); $out = array(); $s = Schema::table( 'visits' ); $v = Schema::table( 'visitors' );
        foreach ( $orders as $order ) { if ( $order['visit'] && $order['visitor'] ) { $pairs[$order['visit'] . ':' . $order['visitor']] = array( $order['visit'], $order['visitor'] ); } }
        foreach ( array_chunk( array_values( $pairs ), 500 ) as $chunk ) {
            $where = array(); $values = array(); foreach ( $chunk as $pair ) { $where[] = '(s.id=%d AND s.visitor_id=%d)'; array_push( $values, ...$pair ); }
            $sql = "SELECT s.id,s.visitor_id,s.user_id,s.stage,s.device,v.browser FROM {$s} s INNER JOIN {$v} v ON v.id=s.visitor_id WHERE s.is_test=0 AND v.is_test=0 AND (" . implode( ' OR ', $where ) . ') ORDER BY s.id';
            $rows = self::checked_rows( $wpdb->get_results( $wpdb->prepare( $sql, ...$values ), ARRAY_A ) );
            $facts = Report_Dimensions::visit_facts( array_column( $rows, 'id' ), $cutoff );
            foreach ( $rows as $row ) {
                $visit = Report_Dimensions::positive_id( $row['id'] ?? null ); $visitor = Report_Dimensions::positive_id( $row['visitor_id'] ?? null );
                if ( ! $visit || ! $visitor ) { throw new \RuntimeException( 'Invalid original visit.' ); }
                $out[$visit . ':' . $visitor] = array( 'row' => $row, 'fact' => Report_Dimensions::for_visit( $facts, $visit, $visitor ) );
            }
        }
        return $out;
    }
    private static function period( int $from, int $to, array $args ): array {
        global $wpdb; $cutoff = $to * 1000; $filter = Report_Filter::active( $args, $cutoff ); $groups = array(); $coverage = array( 'visits' => self::coverage( 'visits' ), 'orders' => self::coverage( 'orders' ) );
        $s = Schema::table( 'visits' ); $v = Schema::table( 'visitors' ); $cursor = 0;
        do {
            $sql = "SELECT s.id,s.visitor_id,s.user_id,s.stage,s.device,v.browser FROM {$s} s INNER JOIN {$v} v ON v.id=s.visitor_id WHERE s.start_ms >= %d AND s.start_ms < %d AND s.id > %d AND s.is_test=0 AND v.is_test=0 ORDER BY s.id LIMIT 500";
            $rows = self::checked_rows( $wpdb->get_results( $wpdb->prepare( $sql, $from * 1000, $cutoff, $cursor ), ARRAY_A ) );
            $facts = Report_Dimensions::visit_facts( array_column( $rows, 'id' ), $cutoff );
            foreach ( $rows as $row ) {
                $visit = Report_Dimensions::positive_id( $row['id'] ?? null ); $visitor = Report_Dimensions::positive_id( $row['visitor_id'] ?? null );
                if ( ! $visit || $visit <= $cursor || ! $visitor ) { throw new \RuntimeException( 'Invalid report visit.' ); }
                $cursor = $visit; $fact = Report_Dimensions::for_visit( $facts, $visit, $visitor ); $identity = self::identity( $fact, $row, $args );
                if ( self::matched( $coverage['visits'], $fact, $filter, $identity ) ) { $key = self::add_group( $groups, $identity ); Visit_Metrics::visit( $groups[$key]['metrics'], $row ); }
            }
        } while ( count( $rows ) === 500 );
        $orders = array();
        foreach ( Operations::paid_orders( $from, $to ) as $order ) {
            if ( Overview::is_test_order( $order ) ) { continue; }
            $native = Visit_Metrics::order_fact( $order );
            $native['visit'] = Report_Dimensions::positive_id( $order->get_meta( Server_Events::ORDER_VISIT_META ) ) ?? 0;
            $native['visitor'] = Report_Dimensions::positive_id( $order->get_meta( Server_Events::ORDER_VISITOR_META ) ) ?? 0; $orders[] = $native;
        }
        $originals = self::original_orders( $orders, $cutoff );
        foreach ( $orders as $order ) {
            $original = $originals[$order['visit'] . ':' . $order['visitor']] ?? null;
            $fact = $original ? $original['fact'] : Report_Dimensions::unknown(); $identity = self::identity( $fact, $original['row'] ?? null, $args );
            if ( self::matched( $coverage['orders'], $fact, $filter, $identity ) ) { $key = self::add_group( $groups, $identity ); Visit_Metrics::order( $groups[$key]['metrics'], $order ); }
        }
        return array( 'groups' => $groups, 'coverage' => $coverage );
    }
    private static function metrics( array $group ): array {
        $out = Visit_Metrics::finish( $group ); foreach ( $out as &$value ) { if ( null !== $value && ! is_finite( (float) $value ) ) { $value = null; } } unset( $value ); return $out;
    }
    private static function selection( array $range, array $args ): array {
        if ( ! Schema::installed() ) { throw new \RuntimeException( 'Tracking storage unavailable.' ); }
        $current = self::period( (int) $range['from'], (int) $range['to'], $args ); $previous = self::period( (int) $range['compare_from'], (int) $range['compare_to'], $args ); $rows = array();
        foreach ( array_keys( $current['groups'] + $previous['groups'] ) as $key ) {
            $identity = ( $current['groups'][$key] ?? $previous['groups'][$key] )['identity']; $label = self::label( $identity, $args['dimension'] );
            if ( '' !== $args['q'] ) {
                $text = $label . ' ' . (string) $identity['dimension']['value'] . ' ' . (string) ( $identity['medium']['value'] ?? '' );
                if ( 1 !== preg_match( '/' . preg_quote( $args['q'], '/' ) . '/iu', $text ) ) { continue; }
            }
            $cur = self::metrics( $current['groups'][$key]['metrics'] ?? Visit_Metrics::blank() ); $prev = self::metrics( $previous['groups'][$key]['metrics'] ?? Visit_Metrics::blank() ); $change = array();
            foreach ( self::METRICS as $metric ) {
                $value = null === $cur[$metric] || null === $prev[$metric] ? null : ( 'conversion' === $metric ? round( $cur[$metric] - $prev[$metric], 2 ) : Range::change( (float) $cur[$metric], (float) $prev[$metric] ) );
                $change[$metric] = null !== $value && ! is_finite( (float) $value ) ? null : $value;
            }
            $rows[] = $identity + array( 'label' => $label, 'current' => $cur, 'previous' => $prev, 'change' => $change );
        }
        usort( $rows, static function( array $a, array $b ) use ( $args ): int {
            $av = $a[$args['sort_period']][$args['sort']]; $bv = $b[$args['sort_period']][$args['sort']];
            if ( null === $av || null === $bv ) { $cmp = $av === $bv ? 0 : ( null === $av ? 1 : -1 ); }
            else { $cmp = $av <=> $bv; if ( 'desc' === $args['order'] ) { $cmp = -$cmp; } }
            return $cmp ?: strcmp( $a['key'], $b['key'] );
        } );
        return array( 'rows' => $rows, 'coverage' => array( 'current' => $current['coverage'], 'previous' => $previous['coverage'] ) );
    }
    private static function cell( $value ): string { return null === $value || ! is_finite( (float) $value ) ? '' : (string) $value; }
    private static function csv( array $selection, array $range, array $args ): array {
        $header = array( 'version', 'group', 'dimension_state', 'dimension_value', 'dimension_label', 'medium_state', 'medium_value', 'currency', 'current_from_epoch_s', 'current_to_epoch_s', 'previous_from_epoch_s', 'previous_to_epoch_s' );
        foreach ( $args['columns'] as $metric ) {
            $header[] = 'current_' . $metric . ( 'conversion' === $metric ? '_pct' : '' ); $header[] = 'previous_' . $metric . ( 'conversion' === $metric ? '_pct' : '' ); $header[] = 'change_' . $metric . ( 'conversion' === $metric ? '_pp' : '_pct' );
        }
        foreach ( array( 'current', 'previous' ) as $period ) { foreach ( array( 'visits', 'orders' ) as $unit ) { foreach ( self::COUNTERS as $counter ) { $header[] = 'coverage_' . $period . '_' . $unit . '_' . $counter; } } }
        $out = array( $header );
        foreach ( $selection['rows'] as $row ) {
            $cells = array( '1', $args['dimension'], $row['dimension']['state'], Csv_Response::text( $row['dimension']['value'] ?? '' ), Csv_Response::text( $row['label'] ), $row['medium']['state'] ?? '', Csv_Response::text( $row['medium']['value'] ?? '' ), Csv_Response::text( get_woocommerce_currency() ), (string) $range['from'], (string) $range['to'], (string) $range['compare_from'], (string) $range['compare_to'] );
            foreach ( $args['columns'] as $metric ) { foreach ( array( 'current', 'previous', 'change' ) as $period ) { $cells[] = self::cell( $row[$period][$metric] ); } }
            foreach ( array( 'current', 'previous' ) as $period ) { foreach ( array( 'visits', 'orders' ) as $unit ) { foreach ( self::COUNTERS as $counter ) { $cells[] = (string) $selection['coverage'][$period][$unit][$counter]; } } }
            $out[] = $cells;
        }
        return $out;
    }
}
