<?php
/**
 * M6“按广告拆分”：落地页的广告系列 / 广告组 / 广告参数（utm_campaign / utm_term / utm_content）进归因、入库、给报表。
 * 真正的 Tracking\Attribution 和 Tracking\Ad_Params，只有数据库是假的。纯逻辑：php -n m6-ad-params-pure.php
 *
 * 要证明的事：
 * 1. 合规的广告参数跟着来源、媒介一起通过归因的清洗；不合规的只丢那一项，来源和媒介不受影响。
 * 2. 归因写成功的那一刻把广告参数存进自己的表；一次访问第一次记下的算数；表没装好时什么都不做，归因照常。
 * 3. 报表拿到的是带状态的值：有值 / 没有这个参数 / 没有观察到。
 * 4. 删访问（隐私抹除、超期清理）时这张表的行跟着删。
 */
require __DIR__ . '/m6-stubs.php';
require dirname( __DIR__ ) . '/includes/tracking/class-ad-params.php';
require dirname( __DIR__ ) . '/includes/tracking/class-attribution.php';

use PeptideCore\Tracking\Ad_Params;
use PeptideCore\Tracking\Attribution;

/** 假 $wpdb：访问表只认归因那一条 UPDATE，广告参数表放在内存里。 */
final class Ads_DB {
	public string $prefix     = 'test_';
	public string $last_error = '';
	public array $ads         = array(); // visit_id => 行
	public array $captured    = array(); // 已经写过归因的访问
	public array $visits      = array(); // visit_id => visitor_id（delete_for_visitors 用）
	public bool $fail_ads     = false;
	public function prepare( string $sql, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		foreach ( $args as $arg ) { $sql = preg_replace_callback( '/%[ds]/', static fn( $m ) => '%d' === $m[0] ? (string) (int) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'", $sql, 1 ); }
		return $sql;
	}
	public function query( string $sql ) {
		if ( str_contains( $sql, 'UPDATE test_visits SET attribution_version = 1' ) ) { // 归因：每次访问只成功一次。
			preg_match( '/WHERE id = (\d+)/', $sql, $m );
			if ( isset( $this->captured[ (int) $m[1] ] ) ) { return 0; }
			$this->captured[ (int) $m[1] ] = true;
			return 1;
		}
		if ( str_contains( $sql, 'INSERT IGNORE INTO test_pc_track_ads' ) ) {
			if ( $this->fail_ads ) { throw new RuntimeException( 'synthetic ads table failure' ); }
			preg_match( "/VALUES \((\d+), (NULL|'(?:[^']|'')*'), (NULL|'(?:[^']|'')*'), (NULL|'(?:[^']|'')*'), (\d+)\)/", $sql, $m );
			$id = (int) $m[1];
			if ( isset( $this->ads[ $id ] ) ) { return 0; }
			$cell             = static fn( string $raw ) => 'NULL' === $raw ? null : str_replace( "''", "'", substr( $raw, 1, -1 ) );
			$this->ads[ $id ] = array( 'visit_id' => $id, 'utm_campaign' => $cell( $m[2] ), 'utm_term' => $cell( $m[3] ), 'utm_content' => $cell( $m[4] ), 'captured_ms' => (int) $m[5] );
			return 1;
		}
		if ( str_contains( $sql, 'DELETE FROM test_pc_track_ads WHERE visit_id IN (SELECT id FROM test_visits WHERE visitor_id IN (' ) ) {
			preg_match( '/visitor_id IN \(([\d,]+)\)\)/', $sql, $m );
			$visitors = array_map( 'intval', explode( ',', $m[1] ) );
			foreach ( $this->visits as $visit => $visitor ) { if ( in_array( $visitor, $visitors, true ) ) { unset( $this->ads[ $visit ] ); } }
			return 1;
		}
		if ( str_contains( $sql, 'DELETE FROM test_pc_track_ads WHERE visit_id IN (' ) ) {
			preg_match( '/visit_id IN \(([\d,]+)\)/', $sql, $m );
			foreach ( array_map( 'intval', explode( ',', $m[1] ) ) as $visit ) { unset( $this->ads[ $visit ] ); }
			return 1;
		}
		throw new RuntimeException( 'Unexpected query: ' . $sql );
	}
	public function get_results( string $sql, $mode = null ) {
		if ( str_contains( $sql, 'FROM test_pc_track_ads WHERE visit_id IN (' ) ) {
			preg_match( '/visit_id IN \(([\d,]+)\)/', $sql, $m );
			return array_values( array_intersect_key( $this->ads, array_flip( array_map( 'intval', explode( ',', $m[1] ) ) ) ) );
		}
		throw new RuntimeException( 'Unexpected get_results: ' . $sql );
	}
	public function get_row( string $sql, $mode = null ) { return null; }
}
$wpdb = new Ads_DB();

// ---------- 1. 清洗 ----------
m6_check( '1: ordinary names are valid', Ad_Params::valid( 'BPC-157 | US | 25-44' ) && Ad_Params::valid( '春季 促销 #2' ) && Ad_Params::valid( str_repeat( '肽', 120 ) ) );
foreach ( array( 'empty' => '', 'too long' => str_repeat( 'a', 121 ), 'control character' => "a\nb", 'invalid UTF-8' => "a\xff", 'unreplaced Facebook macro' => '{{campaign.name}}', 'macro inside text' => 'x {{ad.name}} y', 'not a string' => 12, 'array' => array( 'a' ), 'null' => null ) as $label => $bad ) {
	m6_check( '1: rejected — ' . $label, ! Ad_Params::valid( $bad ) );
}
m6_check( '1: pick keeps only the three keys, in a fixed order', array( 'utm_campaign' => 'C', 'utm_term' => 'S', 'utm_content' => 'A' ) === Ad_Params::pick( array( 'utm_content' => 'A', 'other' => 'x', 'utm_term' => 'S', 'utm_campaign' => 'C', 'utm_source' => 'facebook' ) ) );

$wire  = array( 'version' => 1, 'host' => 'store.test', 'referrer_host' => 'm.facebook.com', 'landing_query' => array( 'utm_source' => 'facebook', 'utm_medium' => 'paid', 'utm_campaign' => 'Spring sale', 'utm_term' => 'US women 25-44', 'utm_content' => 'Video A' ) );
$clean = Attribution::clean( $wire );
m6_check( '1: valid ad parameters pass the attribution cleaner next to source and medium', $clean['ok'] && array( 'utm_source' => 'facebook', 'utm_medium' => 'paid', 'utm_campaign' => 'Spring sale', 'utm_term' => 'US women 25-44', 'utm_content' => 'Video A' ) === $clean['value']['landing_query'] );
m6_check( '1: the stored attribution query string still holds only source and medium', 'utm_source=facebook&utm_medium=paid' === Attribution::query( $clean['value'] ) );

$dirty                                  = $wire;
$dirty['landing_query']['utm_campaign'] = '{{campaign.name}}';      // Facebook 没替换掉的占位符。
$dirty['landing_query']['utm_term']     = str_repeat( 'x', 500 );   // 太长。
$partial                                = Attribution::clean( $dirty );
m6_check( '1: a bad ad parameter is dropped alone; source, medium and the good one survive', $partial['ok'] && array( 'utm_source' => 'facebook', 'utm_medium' => 'paid', 'utm_content' => 'Video A' ) === $partial['value']['landing_query'] );

$strict                                = $wire;
$strict['landing_query']['utm_source'] = "bad\nsource";
m6_check( '1: a bad source still rejects the whole attribution, as before', array( 'ok' => false, 'code' => 'bad_attribution' ) === Attribution::clean( $strict ) );
$plain = $wire;
unset( $plain['landing_query']['utm_campaign'], $plain['landing_query']['utm_term'], $plain['landing_query']['utm_content'] );
m6_check( '1: links without ad parameters clean exactly as before', array( 'utm_source' => 'facebook', 'utm_medium' => 'paid' ) === Attribution::clean( $plain )['value']['landing_query'] );

// ---------- 2. 入库 ----------
$event = static fn( int $visit, int $visitor = 7 ): array => array( 'event_uid' => 'pv-' . $visit, 'visitor_id' => $visitor, 'visit_id' => $visit, 'path' => '/', 'received_ms' => 150000 + $visit );

// 表还没装好：归因照常成功，广告参数不存，也不报错。
$receipt = Attribution::capture( 7, $event( 11 ), $clean['value'] );
m6_check( '2: before the table exists attribution still succeeds and nothing is stored', 'applied' === $receipt['status'] && 'captured' === $receipt['code'] && array() === $wpdb->ads && array() === Ad_Params::for_visits( array( 11 ) ) );

$GLOBALS['m6_options'][ Ad_Params::OPTION ] = Ad_Params::DB_VERSION; // 表装好了。
$receipt = Attribution::capture( 7, $event( 12 ), $clean['value'] );
m6_check( '2: a captured visit gets its ad parameters stored', 'captured' === $receipt['code'] && array( 'utm_campaign' => 'Spring sale', 'utm_term' => 'US women 25-44', 'utm_content' => 'Video A' ) === Ad_Params::for_visits( array( 12 ) )[12] && 150012 === $wpdb->ads[12]['captured_ms'] );

$other                                  = $wire;
$other['landing_query']['utm_campaign'] = 'Another campaign';
$receipt                                = Attribution::capture( 7, $event( 12 ), Attribution::clean( $other )['value'] );
m6_check( '2: the first capture of a visit wins', 'applied' !== $receipt['status'] || 'captured' !== $receipt['code'] );
m6_check( '2: its stored ad parameters are unchanged', 'Spring sale' === Ad_Params::for_visits( array( 12 ) )[12]['utm_campaign'] );
m6_check( '2: storing twice directly is ignored as well', false === Ad_Params::store( 12, array( 'utm_campaign' => 'Third' ), 1 ) && 'Spring sale' === $wpdb->ads[12]['utm_campaign'] );

Attribution::capture( 7, $event( 13 ), $partial['value'] );
m6_check( '2: missing parameters are stored as NULL, not as empty text', array( 'utm_campaign' => null, 'utm_term' => null, 'utm_content' => 'Video A' ) === Ad_Params::for_visits( array( 13 ) )[13] );
Attribution::capture( 7, $event( 14 ), Attribution::clean( $plain )['value'] );
m6_check( '2: a visit without any ad parameter gets no row', ! isset( $wpdb->ads[14] ) && isset( $wpdb->captured[14] ) );

$quote                                  = $wire;
$quote['landing_query']['utm_campaign'] = "O'Brien's 50% off";
Attribution::capture( 7, $event( 15 ), Attribution::clean( $quote )['value'] );
m6_check( '2: quotes and percent signs survive', "O'Brien's 50% off" === Ad_Params::for_visits( array( 15 ) )[15]['utm_campaign'] );

$wpdb->fail_ads = true;
$receipt        = Attribution::capture( 7, $event( 16 ), $clean['value'] );
$wpdb->fail_ads = false;
m6_check( '2: a failure of the ad table never fails the attribution', 'applied' === $receipt['status'] && 'captured' === $receipt['code'] && ! isset( $wpdb->ads[16] ) );

// ---------- 3. 报表用的值 ----------
m6_check( '3: a stored value', array( 'state' => 'value', 'value' => 'Spring sale' ) === Ad_Params::typed( $wpdb->ads[12], true )['utm_campaign'] );
m6_check( '3: captured visit without this parameter is "missing"', array( 'state' => 'missing', 'value' => null ) === Ad_Params::typed( $wpdb->ads[13], true )['utm_campaign'] && array( 'state' => 'missing', 'value' => null ) === Ad_Params::typed( null, true )['utm_content'] );
m6_check( '3: a visit whose source was never captured is "unobserved"', array( 'state' => 'unobserved', 'value' => null ) === Ad_Params::typed( null, false )['utm_term'] );
m6_check( '3: lookups ignore junk ids and unknown visits', array( 12, 13 ) === array_keys( Ad_Params::for_visits( array( 0, -1, 'x', 12, 13, 99 ) ) ) );

// ---------- 4. 跟着访问一起删 ----------
$wpdb->visits = array( 12 => 7, 13 => 7, 15 => 8 );
Ad_Params::delete_for_visits( array( 13 ) );
m6_check( '4: pruning a visit removes its row', ! isset( $wpdb->ads[13] ) && isset( $wpdb->ads[12], $wpdb->ads[15] ) );
Ad_Params::delete_for_visitors( array( 7 ) );
m6_check( '4: erasing a visitor removes the rows of all their visits', ! isset( $wpdb->ads[12] ) && isset( $wpdb->ads[15] ) );
Ad_Params::delete_for_visits( array() );
Ad_Params::delete_for_visitors( array( 0 ) );
m6_check( '4: empty requests run no query', isset( $wpdb->ads[15] ) );

echo 'PASS ', $GLOBALS['m6_passed'], " ad parameter (campaign / ad set / ad) assertions\n";
