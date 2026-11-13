<?php
/**
 * CR 素材归因线 3 总后台纯逻辑测试：日报整天覆盖写入（含失败回滚）、指标计算（0 花费、0 访客）、样本不足、
 * 分组对比、检查点触发、词表改动和版本号加一、导出内容。
 * 数据库用内存里的假 $wpdb，不需要 WordPress、数据库或网络。运行：php -n cr-hq-compare-pure.php
 */
namespace PeptideHq {
	define( 'ABSPATH', __DIR__ . '/' );
	final class Orders {
		public static function valid_day( string $day ): bool { return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) && checkdate( (int) substr( $day, 5, 2 ), (int) substr( $day, 8, 2 ), (int) substr( $day, 0, 4 ) ); }
	}
	final class Db {
		public static function table( string $name ): string { return 'wp_pchq_' . $name; }
	}
}
namespace {
	define( 'ARRAY_A', 'ARRAY_A' );
	final class WP_Error {
		public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
		public function get_error_message(): string { return $this->message; }
	}
	function is_wp_error( $v ): bool { return $v instanceof WP_Error; }
	$GLOBALS['options'] = array();
	function get_option( string $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
	function update_option( string $k, $v, $autoload = null ): bool { $GLOBALS['options'][ $k ] = $v; return true; }

	/** 内存里的假数据库：一张表一个数组，支持事务快照、replace / delete / 本测试用到的两种查询。 */
	final class Fake_Wpdb {
		public array $tables = array();
		public array $log = array();
		private ?array $snapshot = null;
		public int $fail_replace_at = 0; // 第几次 replace 失败（0 = 不失败）
		public int $replaces = 0;
		private static function keys( string $t ): array { return str_contains( $t, 'vocab' ) ? array( 'attr', 'value' ) : array( 'site_id', 'day', 'code' ); }
		private static function id( string $t, array $row ): string { return implode( '|', array_map( static fn( $k ) => (string) $row[ $k ], self::keys( $t ) ) ); }
		public function query( string $sql ) {
			$this->log[] = $sql;
			if ( 'START TRANSACTION' === $sql ) { $this->snapshot = $this->tables; }
			if ( 'ROLLBACK' === $sql && null !== $this->snapshot ) { $this->tables = $this->snapshot; $this->snapshot = null; }
			if ( 'COMMIT' === $sql ) { $this->snapshot = null; }
			return 0;
		}
		public function replace( string $t, array $row ) {
			++$this->replaces;
			$this->log[] = 'REPLACE ' . $t;
			if ( $this->fail_replace_at === $this->replaces ) { return false; }
			$this->tables[ $t ][ self::id( $t, $row ) ] = $row;
			return 1;
		}
		public function delete( string $t, array $where ) {
			$this->log[] = 'DELETE ' . $t;
			$n = 0;
			foreach ( $this->tables[ $t ] ?? array() as $id => $row ) {
				if ( array_intersect_assoc( array_map( 'strval', $where ), array_map( 'strval', $row ) ) === array_map( 'strval', $where ) ) { unset( $this->tables[ $t ][ $id ] ); ++$n; }
			}
			return $n;
		}
		public function prepare( string $sql, ...$args ): string {
			$args = isset( $args[0] ) && is_array( $args[0] ) ? $args[0] : $args;
			return (string) preg_replace_callback( '/%[ds]/', static function ( $m ) use ( &$args ) { $v = array_shift( $args ); return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'"; }, $sql );
		}
		public function get_col( string $sql ) {
			if ( preg_match( "/FROM (\S+) WHERE site_id = (\d+) AND day = '([0-9-]+)'/", $sql, $m ) ) {
				return array_values( array_map( static fn( $r ) => $r['code'], array_filter( $this->tables[ $m[1] ] ?? array(), static fn( $r ) => (int) $r['site_id'] === (int) $m[2] && $r['day'] === $m[3] ) ) );
			}
			return null;
		}
		public function rows( string $t ): array { return array_values( $this->tables[ $t ] ?? array() ); }
	}

	require dirname( __DIR__ ) . '/includes/class-creatives.php';
	use PeptideHq\Creatives as H;

	$checks = 0;
	function check( string $name, bool $ok ): void { ++$GLOBALS['checks']; if ( ! $ok ) { throw new RuntimeException( 'FAIL ' . $name ); } }

	$fx    = json_decode( (string) file_get_contents( dirname( __DIR__, 4 ) . '/tests/fixtures/creatives/report-sample.json' ), true );
	$in    = $fx['input'];
	$clean = H::clean_report( array( 'day' => $in['day'], 'tz' => $in['tz'], 'rows' => $in['rows'], 'untagged' => array( 'visitors' => $in['untagged'] ) ) );

	// ---------- 一行变成表里的一行 ----------
	$rec = H::daily_record( 7, '2026-11-11', $clean['rows'][1], 1700000000 );
	check( 'record keeps code, site and day', 'C0012' === $rec['code'] && 7 === $rec['site_id'] && '2026-11-11' === $rec['day'] );
	check( 'attrs stored as JSON', array( 'angle' => 'purity', 'fmt' => 'vid', 'hook' => 'lab', 'lang' => 'en' ) === json_decode( $rec['attrs'], true ) && array( 'cta' => 'shopnow' ) === json_decode( $rec['custom'], true ) );
	check( 'intent split into four columns', 3 === $rec['intent_high'] && 9 === $rec['intent_mid'] && 23 === $rec['intent_low'] && 5 === $rec['intent_invalid'] && ! isset( $rec['intent'] ) );
	check( 'counts copied', 35 === $rec['valid_visitors'] && 15900 === $rec['sales_cents'] && 2350 === $rec['spend_cents'] && 52 === $rec['link_clicks'] && 1700000000 === $rec['reported_at'] );
	$unreg = H::daily_record( 7, '2026-11-11', $clean['rows'][2], 1 );
	check( 'empty attrs stored as an object, not a list', '{}' === $unreg['attrs'] && '{}' === $unreg['custom'] );
	check( 'record columns match the table', array_keys( $rec ) === array_merge( array( 'site_id', 'day', 'code', 'attrs', 'custom', 'thumb', 'intent_high', 'intent_mid', 'intent_low', 'intent_invalid' ), H::COUNTS, array( 'reported_at' ) ) );
	check( 'stale codes = old minus new', array( 'C0003', 'UNREG' ) === H::stale_codes( array( 'C0003', 'C0012', 'UNREG' ), array( 'C0012', 'C0099' ) ) && array() === H::stale_codes( array(), array( 'C0001' ) ) );

	// ---------- 整天覆盖写入 ----------
	$GLOBALS['wpdb'] = $db = new Fake_Wpdb();
	$t = H::daily_table();
	check( 'first report stores every row', 3 === H::store_rows( 7, $clean ) && 3 === count( $db->rows( $t ) ) );
	check( 'wrapped in a transaction', 'START TRANSACTION' === $db->log[0] && 'COMMIT' === end( $db->log ) && ! in_array( 'ROLLBACK', $db->log, true ) );
	H::store_rows( 7, array( 'day' => '2026-11-10', 'rows' => $clean['rows'] ) );
	H::store_rows( 8, $clean );
	check( 'other day and other store kept apart', 9 === count( $db->rows( $t ) ) );
	$redo                                 = $clean;
	$redo['rows']                         = array( $clean['rows'][1] );
	$redo['rows'][0]['valid_visitors']    = 99;
	$db->log                              = array();
	check( 'resend returns the new row count', 1 === H::store_rows( 7, $redo ) );
	$day7 = array_values( array_filter( $db->rows( $t ), static fn( $r ) => 7 === $r['site_id'] && '2026-11-11' === $r['day'] ) );
	check( 'resend replaces the whole day', 1 === count( $day7 ) && 'C0012' === $day7[0]['code'] && 99 === $day7[0]['valid_visitors'] );
	check( 'resend leaves other days and stores', 7 === count( $db->rows( $t ) ) );
	$first_delete = array_search( 'DELETE ' . $t, $db->log, true );
	$last_replace = max( array_keys( $db->log, 'REPLACE ' . $t, true ) );
	check( 'new rows written before old rows deleted', false !== $first_delete && $last_replace < $first_delete );
	check( 'empty resend clears the day', 0 === H::store_rows( 7, array( 'day' => '2026-11-11', 'rows' => array() ) ) && 6 === count( $db->rows( $t ) ) );

	$before                = $db->tables;
	$db->fail_replace_at   = $db->replaces + 2; // 这次的第二行写失败
	$db->log               = array();
	$err                   = H::store_rows( 8, array( 'day' => '2026-11-11', 'rows' => array( $redo['rows'][0], $clean['rows'][0] ) ) );
	check( 'write failure returns an error', is_wp_error( $err ) && 500 === $err->data['status'] );
	check( 'write failure rolls back and deletes nothing', $before === $db->tables && in_array( 'ROLLBACK', $db->log, true ) && ! in_array( 'DELETE ' . $t, $db->log, true ) );

	// ---------- 指标 ----------
	$m = H::metrics( array( 'spend_cents' => 0, 'visitors' => 0, 'valid_visitors' => 0, 'intent_high' => 0, 'sales_cents' => 0 ) );
	check( 'zero visitors: rates are empty, not zero', null === $m['mid_high_share'] && null === $m['cart_rate'] && null === $m['checkout_rate'] && null === $m['cost_valid_cents'] && null === $m['cost_high_cents'] );
	check( 'zero spend: no ROAS', null === $m['roas'] && true === $m['thin'] );
	$m = H::metrics( array( 'spend_cents' => 0, 'valid_visitors' => 40, 'intent_high' => 4, 'intent_mid' => 6, 'add_to_cart' => 10, 'checkout' => 2, 'sales_cents' => 5000 ) );
	check( 'zero spend with visitors: cost is $0, ROAS empty', 0.0 === (float) $m['cost_valid_cents'] && 0.0 === (float) $m['cost_high_cents'] && null === $m['roas'] );
	check( 'rates use valid visitors as denominator', 0.25 === $m['mid_high_share'] && 0.25 === $m['cart_rate'] && 0.05 === $m['checkout_rate'] );
	$m = H::metrics( array( 'spend_cents' => 2350, 'visitors' => 40, 'valid_visitors' => 35, 'intent_high' => 3, 'intent_mid' => 9, 'add_to_cart' => 4, 'checkout' => 2, 'cashier' => 2, 'orders' => 1, 'sales_cents' => 15900 ) );
	check( 'cost per valid visitor', abs( $m['cost_valid_cents'] - 2350 / 35 ) < 1e-9 );
	check( 'cost per high-intent visitor', abs( $m['cost_high_cents'] - 2350 / 3 ) < 1e-9 );
	check( 'ROAS = sales / spend', abs( $m['roas'] - 15900 / 2350 ) < 1e-9 );
	check( 'counts kept beside ratios', 2 === $m['cashier'] && 1 === $m['orders'] && 40 === $m['visitors'] );
	check( 'zero high intent: no cost per high-intent visitor', null === H::metrics( array( 'spend_cents' => 100, 'valid_visitors' => 50 ) )['cost_high_cents'] );

	// ---------- 样本不足 ----------
	check( 'under 30 valid visitors is thin', H::thin( 0 ) && H::thin( 29 ) && ! H::thin( 30 ) && ! H::thin( 500 ) && 30 === H::MIN_SAMPLE );
	check( 'metrics carry the thin flag', true === H::metrics( array( 'valid_visitors' => 29 ) )['thin'] && false === H::metrics( array( 'valid_visitors' => 30 ) )['thin'] );

	// ---------- 合并和分组 ----------
	$row = static function ( int $site, string $day, string $code, array $attrs, array $n = array(), string $thumb = '' ): array {
		return H::decode_row( array_merge( array( 'site_id' => $site, 'day' => $day, 'code' => $code, 'attrs' => json_encode( $attrs ?: new stdClass() ), 'custom' => '{}', 'thumb' => $thumb ), $n ) );
	};
	$rows = array(
		$row( 1, '2026-11-01', 'C0001', array( 'hook' => 'lab', 'fmt' => 'vid' ), array( 'valid_visitors' => 20, 'visitors' => 25, 'spend_cents' => 1000, 'intent_high' => 2 ), 'https://a.test/1.jpg' ),
		$row( 1, '2026-11-02', 'C0001', array( 'hook' => 'lab', 'fmt' => 'img' ), array( 'valid_visitors' => 20, 'visitors' => 22, 'spend_cents' => 1000, 'sales_cents' => 9000, 'orders' => 1 ) ),
		$row( 2, '2026-11-01', 'C0001', array( 'hook' => 'price' ), array( 'valid_visitors' => 10, 'spend_cents' => 500 ) ),
		$row( 2, '2026-11-01', 'C0002', array( 'hook' => 'lab' ), array( 'valid_visitors' => 5 ) ),
		$row( 2, '2026-11-02', 'UNREG', array(), array( 'valid_visitors' => 40 ) ),
		$row( 3, '2026-11-02', 'C0007', array( 'fmt' => 'vid' ), array( 'valid_visitors' => 1 ) ),
	);
	$cr = H::fold( $rows );
	check( 'same code in two stores stays two creatives', 5 === count( $cr ) && isset( $cr['1:C0001'], $cr['2:C0001'] ) );
	check( 'days of one creative add up', 40 === $cr['1:C0001']['sum']['valid_visitors'] && 2000 === $cr['1:C0001']['sum']['spend_cents'] && '2026-11-01' === $cr['1:C0001']['first_day'] && '2026-11-02' === $cr['1:C0001']['last_day'] );
	check( 'latest attrs win, thumb kept when later day has none', 'img' === $cr['1:C0001']['attrs']['fmt'] && 'https://a.test/1.jpg' === $cr['1:C0001']['thumb'] );

	$cmp  = H::compare( $cr, 'attr', 'hook' );
	$keys = array_column( $cmp['groups'], 'key' );
	$by   = array_combine( $keys, $cmp['groups'] );
	check( 'attr groups across stores', array( 'attr:lab', '#unreg', 'attr:price', 'attr:' ) === $keys );
	check( 'hook-lab sums both stores', 45 === $by['attr:lab']['metrics']['valid_visitors'] && array( 1, 2 ) === $by['attr:lab']['sites'] && 2 === count( $by['attr:lab']['creatives'] ) );
	check( 'thin groups sorted last', false === $by['attr:lab']['metrics']['thin'] && true === $by['attr:price']['metrics']['thin'] && true === $by['attr:']['metrics']['thin'] );
	check( 'missing attr has its own group', array( '3:C0007' ) === $by['attr:']['creatives'] );
	check( 'total equals sum of groups', 96 === $cmp['total']['valid_visitors'] && 96 === array_sum( array_map( static fn( $g ) => $g['metrics']['valid_visitors'], $cmp['groups'] ) ) && 2500 === $cmp['total']['spend_cents'] );
	check( 'total ROAS from summed numbers', abs( $cmp['total']['roas'] - 9000 / 2500 ) < 1e-9 );
	check( 'creative mode: one row per store creative', 5 === count( H::compare( $cr, 'creative' )['groups'] ) );
	$sites = array_column( H::compare( $cr, 'site' )['groups'], 'metrics', 'key' );
	check( 'site mode sums per store', 40 === $sites['site:1']['valid_visitors'] && 55 === $sites['site:2']['valid_visitors'] && 1 === $sites['site:3']['valid_visitors'] );
	$buyers = array_column( H::compare( $cr, 'buyer', 'hook', array( 1 => 'Ann', 2 => 'Ann', 3 => '' ) )['groups'], 'metrics', 'key' );
	check( 'buyer mode merges stores of one buyer; no name grouped apart', 95 === $buyers['buyer:Ann']['valid_visitors'] && 1 === $buyers['buyer:']['valid_visitors'] && 2 === count( $buyers ) );
	check( 'new store appears without configuration', isset( H::compare( H::fold( array_merge( $rows, array( $row( 99, '2026-11-02', 'C0001', array( 'hook' => 'q' ), array( 'valid_visitors' => 31 ) ) ) ) ), 'site' )['groups'][0] ) && in_array( 'site:99', array_column( H::compare( H::fold( array_merge( $rows, array( $row( 99, '2026-11-02', 'C0001', array( 'hook' => 'q' ), array( 'valid_visitors' => 31 ) ) ) ) ), 'site' )['groups'], 'key' ), true ) );
	check( 'attr filter keeps matching creatives only', array( '3:C0007' ) === array_keys( H::filter_attr( $cr, 'fmt', 'vid' ) ) && array( '1:C0001' ) === array_keys( H::filter_attr( $cr, 'fmt', 'img' ) ) && $cr === H::filter_attr( $cr, '', '' ) );
	check( 'empty data compares to zero', array() === H::compare( array(), 'attr' )['groups'] && null === H::compare( array(), 'attr' )['total']['roas'] );

	// ---------- 检查点 ----------
	check( 'below first step: no checkpoint', null === H::checkpoint_due( 999, 0 ) && null === H::checkpoint_due( 0, 0 ) );
	check( 'crossing 1000 triggers', 1000 === H::checkpoint_due( 1000, 0 ) && 1000 === H::checkpoint_due( 1999, 0 ) );
	check( 'same multiple does not trigger twice', null === H::checkpoint_due( 1999, 1000 ) && null === H::checkpoint_due( 1000, 1000 ) );
	check( 'next multiple triggers', 2000 === H::checkpoint_due( 2000, 1000 ) );
	check( 'jumping several multiples makes one checkpoint at the highest', 5000 === H::checkpoint_due( 5400, 1000 ) );
	check( 'total going down after a resend does not trigger', null === H::checkpoint_due( 1500, 2000 ) );
	check( 'bad step never triggers', null === H::checkpoint_due( 5000, 0, 0 ) && 1000 === H::CHECKPOINT_STEP );

	// ---------- 词表 ----------
	$cur = array();
	foreach ( H::DEFAULT_VOCAB as $i => $item ) {
		$cur[ $item[0] . ':' . $item[1] ] = array( 'attr' => $item[0], 'value' => $item[1], 'label' => $item[2], 'required' => in_array( $item[0], H::REQUIRED, true ), 'active' => true, 'position' => $i );
	}
	$same = array();
	foreach ( $cur as $k => $item ) { $same[ $k ] = array( 'label' => $item['label'], 'active' => $item['active'], 'position' => (string) $item['position'] ); }
	check( 'unchanged form: nothing to save', array( 'rows' => array(), 'errors' => array() ) === H::vocab_changes( $cur, $same ) );
	$edit                          = $same;
	$edit['hook:lab']['label']     = '检测报告图';
	$edit['hook:q']['active']      = false;
	$edit['fmt:car']['position']   = '0';
	$edit['nope:x']                = array( 'label' => 'x', 'active' => true );
	unset( $edit['lang:es'] );
	$plan = H::vocab_changes( $cur, $edit, array( 'attr' => 'Hook', 'value' => 'review', 'label' => '用户评价' ) );
	$got  = array_column( $plan['rows'], null, 'value' );
	check( 'rename, disable, reorder and add in one save', array() === $plan['errors'] && 4 === count( $plan['rows'] ) );
	check( 'renamed label', '检测报告图' === $got['lab']['label'] );
	check( 'disabled, not deleted', false === $got['q']['active'] && 'hook' === $got['q']['attr'] );
	check( 'reordered', 0 === $got['car']['position'] );
	check( 'new value lowercased, active, required follows attr, placed last', 'hook' === $got['review']['attr'] && true === $got['review']['active'] && true === $got['review']['required'] && 14 === $got['review']['position'] );
	check( 'rows missing from the form are left alone; unknown keys ignored', ! isset( $got['es'] ) && ! isset( $got['x'] ) );
	check( 'new optional attribute allowed', false === H::vocab_changes( $cur, array(), array( 'attr' => 'cta', 'value' => 'shopnow', 'label' => '立即购买' ) )['rows'][0]['required'] );
	check( 'duplicate value refused', array() !== H::vocab_changes( $cur, array(), array( 'attr' => 'hook', 'value' => 'lab', 'label' => 'x' ) )['errors'] );
	check( 'value with a dash refused', array() !== H::vocab_changes( $cur, array(), array( 'attr' => 'hook', 'value' => 'lab-2', 'label' => 'x' ) )['errors'] );
	check( 'new value needs a label', array() !== H::vocab_changes( $cur, array(), array( 'attr' => 'hook', 'value' => 'new', 'label' => '' ) )['errors'] );
	check( 'empty label refused', array() !== H::vocab_changes( $cur, array( 'hook:lab' => array( 'label' => ' ', 'active' => true ) ) )['errors'] );
	$off = array();
	foreach ( array( 'fmt:vid', 'fmt:img', 'fmt:car', 'fmt:ugc' ) as $k ) { $off[ $k ] = array( 'label' => $cur[ $k ]['label'], 'active' => false ); }
	check( 'cannot disable every value of a required attribute', array() === H::vocab_changes( $cur, $off )['rows'] && array() !== H::vocab_changes( $cur, $off )['errors'] );
	$lang_off = array( 'lang:en' => array( 'label' => '英文', 'active' => false ), 'lang:es' => array( 'label' => '西班牙文', 'active' => false ) );
	check( 'optional attribute may be fully disabled', 2 === count( H::vocab_changes( $cur, $lang_off )['rows'] ) );

	$GLOBALS['wpdb']                    = $db = new Fake_Wpdb();
	$GLOBALS['options'][ H::VOCAB_VER ] = 1;
	check( 'save with changes bumps version by one', 2 === H::vocab_save( $plan['rows'] ) && 2 === get_option( H::VOCAB_VER ) && 4 === count( $db->rows( H::vocab_table() ) ) );
	check( 'second save bumps again', 3 === H::vocab_save( array( $got['lab'] ) ) );
	check( 'nothing to save keeps version', 3 === H::vocab_save( array() ) && 3 === get_option( H::VOCAB_VER ) );
	$db->fail_replace_at = $db->replaces + 1; // 下一次 replace 失败
	check( 'failed save keeps version', is_wp_error( H::vocab_save( array( $got['lab'] ) ) ) && 3 === get_option( H::VOCAB_VER ) && in_array( 'ROLLBACK', $db->log, true ) );
	check( 'saved row keeps active flag as 0/1', 0 === $db->tables[ H::vocab_table() ]['hook|q']['active'] );

	// ---------- 导出 ----------
	$rows[] = $row( 2, '2026-11-02', 'C0009', array( 'hook' => 'lab' ), array( 'valid_visitors' => 1 ) );
	$rows[ count( $rows ) - 1 ]['custom'] = array( 'note' => '=cmd' );
	$p = H::export_payload( $rows, array( 1 => 'Store A', 2 => '=Evil' ), array( 1 => 'Ann' ), array( 'kind' => 'checkpoint', 'mark' => 1000, 'from' => '2026-11-01', 'to' => '2026-11-02', 'generated_at' => 'x' ) );
	check( 'export keeps every daily row', 7 === count( $p['rows'] ) && 'checkpoint' === $p['kind'] && 1000 === $p['checkpoint'] );
	check( 'export row has store, buyer and one column per attribute', 'Store A' === $p['rows'][0]['site'] && 'Ann' === $p['rows'][0]['buyer'] && 'lab' === $p['rows'][0]['attr_hook'] && array_key_exists( 'attr_fmt', $p['rows'][0] ) && '店铺 #3' === $p['rows'][5]['site'] );
	check( 'export summary per attribute', isset( $p['by_attr']['hook'], $p['by_attr']['fmt'] ) && 'lab' === $p['by_attr']['hook'][0]['value'] && 46 === $p['by_attr']['hook'][0]['valid_visitors'] );
	check( 'export total', 97 === $p['valid_visitors'] && 30 === $p['min_sample'] );
	$csv   = H::export_csv( $p );
	$lines = explode( "\n", trim( $csv ) );
	check( 'CSV starts with BOM and header', str_starts_with( $csv, "\xEF\xBB\xBF" ) && str_starts_with( substr( $lines[0], 3 ), 'day,site_id,site,buyer,code,attr_fmt,attr_hook,custom,thumb,visitors' ) && 8 === count( $lines ) );
	check( 'CSV neutralises formulas', str_contains( $csv, "'=Evil" ) && ! str_contains( $csv, ',=Evil' ) );
	check( 'empty export is just the BOM', "\xEF\xBB\xBF" === H::export_csv( H::export_payload( array(), array(), array(), array() ) ) );
	check( 'export JSON round-trips', is_array( json_decode( (string) json_encode( $p ), true ) ) );

	echo "PASS {$checks} CR HQ compare checks; no WordPress, database, or network.\n";
}
