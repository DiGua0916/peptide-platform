<?php
/** Actual saved reducers, Products and CSV with explicit native boundaries; no WP/database/network. */
namespace PeptideCore\Tracking {
	final class Schema { public static bool $ready = true; public static function installed(): bool { return self::$ready; } public static function table( string $name ): string { return 'qa_' . $name; } }
	final class Server_Events { public const ORDER_VISITOR_META = 'visitor'; public const ORDER_VISIT_META = 'visit'; }
}
namespace PeptideCore { final class Overview { public static function is_test_order( $order ): bool { return $order->test; } } }
namespace {
	require_once __DIR__ . '/product-actions-test-autoload.php';
	define( 'ABSPATH', __DIR__ . '/' ); define( 'ARRAY_A', 'ARRAY_A' ); define( 'HOUR_IN_SECONDS', 3600 ); define( 'DAY_IN_SECONDS', 86400 );
	function __( $s, $d = '' ) { return $s; } function _x( $s, $c, $d = '' ) { return $s; } function home_url() { return 'https://example.test/'; } function wp_parse_url( ...$args ) { return parse_url( ...$args ); }
	function get_woocommerce_currency() { return 'USD'; } function get_edit_post_link( $id, $mode ) { return '/edit/' . $id; } function get_permalink( $id ) { return '/product/' . $id; }
	function is_wp_error( $v ) { return $v instanceof WP_Error; } class WP_Error { public function __construct( public $code, public $message, public $data ) {} }
	class WP_REST_Response { public function __construct(...$args) {} public function header(...$args) {} }
	if ( ! function_exists( 'mb_strlen' ) ) { function mb_strlen( $s, $encoding = null ) { return preg_match_all( '/./us', $s ); } }
	if ( ! function_exists( 'mb_stripos' ) ) { function mb_stripos( $haystack, $needle, $offset = 0, $encoding = null ) { return stripos( $haystack, $needle, $offset ); } }
	function WC() { return (object) array( 'countries' => new class { public function get_countries() { return array( 'US' => 'United States', 'CA' => 'Canada' ); } } ); }
	function wc_get_is_paid_statuses() { return array( 'processing', 'completed' ); }
	function wc_get_orders( array $args ) { $out = array(); foreach ( $GLOBALS['orders'] as $order ) { if ( isset( $args['post__in'] ) && ! in_array( $order->id, $args['post__in'], true ) ) { continue; } if ( isset( $args['date_created'] ) ) { $times = array_map( 'intval', explode( '...', $args['date_created'] ) ); if ( $order->created < $times[0] || $order->created > $times[1] ) { continue; } } if ( isset( $args['status'] ) && ! in_array( $order->status, array_map( static fn( $s ) => preg_replace( '/^wc-/', '', $s ), $args['status'] ), true ) ) { continue; } $out[] = $order; } return $out; }
	function wc_get_product( $id ) { return $GLOBALS['products'][(int) $id] ?? false; }
	class WC_Product { public function __construct( public int $id, public string $name = '=Formula' ) {} public function get_name() { return $this->name; } public function get_sku() { return 'SKU'; } public function get_image_id() { return 0; } public function get_date_created() { return null; } }
	class WC_Product_Variation extends WC_Product {}
	function wc_get_formatted_variation( $product, ...$args ) { return $product->get_name(); }
	class WC_Order_Item_Product { public function __construct( public int $id, public int $parent, public int $variation = 111, public int $qty = 3 ) {} public function get_id() { return $this->id; } public function get_product_id() { return $this->parent; } public function get_variation_id() { return $this->variation; } public function get_quantity() { return $this->qty; } public function get_subtotal() { return 30; } public function get_total() { return 25; } }
	class WC_Order { public bool $test = false; public string $status = 'processing'; public string $method = 'local'; public int $created = 120; public function __construct( public int $id, public int $visit, public int $visitor, public array $items ) {} public function get_id() { return $this->id; } public function get_type() { return 'shop_order'; } public function get_meta( $key, ...$args ) { return $this->$key ?? ''; } public function get_payment_method() { return $this->method; } public function is_paid() { return $this->status === 'processing'; } public function get_date_paid() { return $this->is_paid() ? new DateTimeImmutable( '@150' ) : null; } public function get_items() { return $this->items; } public function get_qty_refunded_for_item( $id ) { return 1; } public function get_total_refunded_for_item( $id ) { return 5; } public function get_customer_id() { return 0; } public function get_billing_email() { return 'synthetic@example.test'; } }
	class Exposure_DB {
		public string $last_error = ''; public array $events = array(); public array $visits = array(); public array $visitor_test = array(); public int $exposure_pages = 0; public int $largest_batch = 0;
		public function prepare( string $sql, ...$args ): string { if ( count( $args ) === 1 && is_array( $args[0] ) ) { $args = $args[0]; } foreach ( $args as $arg ) { $sql = preg_replace_callback( '/%[ds]/', static fn( $m ) => $m[0] === '%d' ? (string) (int) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'", $sql, 1 ); } return $sql; }
		private function ids( $sql ) { preg_match( '/(?:visit_id|id) IN \(([^)]*)\)/', $sql, $m ); return isset( $m[1] ) ? array_map( 'intval', explode( ',', $m[1] ) ) : array(); }
		private function timed( $sql ) { preg_match( '/(?:e\.)?occurred_ms\s*>=\s*(\d+)/', $sql, $a ); preg_match( '/(?:e\.)?occurred_ms\s*<\s*(\d+)/', $sql, $b ); return array_filter( $this->events, static fn( $e ) => $e['occurred_ms'] >= (int) ( $a[1] ?? 0 ) && $e['occurred_ms'] < (int) ( $b[1] ?? PHP_INT_MAX ) ); }
		public function get_results( $sql, $mode ) {
			if ( str_contains( $sql, "e.type IN ('product_favorite','product_share','product_intent')" ) ) { return array(); }
			if ( str_contains( $sql, 'FROM qa_visits WHERE id IN' ) ) { $ids = $this->ids( $sql ); return array_values( array_filter( $this->visits, static fn( $v ) => in_array( $v['id'], $ids, true ) ) ); }
			if ( str_contains( $sql, "type = 'page_view'" ) && str_contains( $sql, 'ORDER BY occurred_ms DESC' ) ) {
				$ids = $this->ids( $sql ); preg_match( '/occurred_ms < (\d+) AND \(occurred_ms < (\d+) OR \(occurred_ms = \d+ AND id < (\d+)\)\)/', $sql, $m );
				$rows = array_values( array_filter( $this->events, static fn( $e ) => $e['type'] === 'page_view' && in_array( $e['visit_id'], $ids, true ) && $e['occurred_ms'] < (int) $m[1] && ( $e['occurred_ms'] < (int) $m[2] || $e['occurred_ms'] === (int) $m[2] && $e['id'] < (int) $m[3] ) ) ); usort( $rows, static fn( $a, $b ) => ( $b['occurred_ms'] <=> $a['occurred_ms'] ) ?: ( $b['id'] <=> $a['id'] ) ); return array_slice( $rows, 0, 500 );
			}
			if ( str_contains( $sql, 'SELECT e.*' ) ) {
				$exposure = str_contains( $sql, "e.type='product_impression'" ); if ( $exposure ) { ++$this->exposure_pages; }
				preg_match( '/e.occurred_ms>(\d+) OR \(e.occurred_ms=(\d+) AND e.id>(\d+)\)/', $sql, $cursor ); $ids = $this->ids( $sql ); $rows = array();
				foreach ( $this->timed( $sql ) as $event ) {
					$visit = $this->visits[$event['visit_id']] ?? null; if ( ! $visit || $visit['visitor_id'] !== $event['visitor_id'] || $event['is_test'] || $visit['is_test'] || ( $this->visitor_test[$event['visitor_id']] ?? true ) ) { continue; }
					if ( $event['occurred_ms'] < (int) $cursor[1] || $event['occurred_ms'] === (int) $cursor[1] && $event['id'] <= (int) $cursor[3] ) { continue; }
					if ( $exposure ? $event['type'] !== 'product_impression' || $event['source'] !== 'client' : ! in_array( $event['visit_id'], $ids, true ) || ! ( $event['type'] === 'product_view' && $event['source'] === 'client' || in_array( $event['type'], array( 'add_to_cart', 'payment_success' ), true ) && $event['source'] === 'server' ) ) { continue; }
					$rows[] = $event + array( 'matched_visit_id' => $event['visit_id'], 'matched_visitor_id' => $event['visitor_id'] );
				} usort( $rows, static fn( $a, $b ) => ( $a['occurred_ms'] <=> $b['occurred_ms'] ) ?: ( $a['id'] <=> $b['id'] ) ); $rows = array_slice( $rows, 0, 500 ); $this->largest_batch = max( $this->largest_batch, count( $rows ) ); return $rows;
			}
			if ( str_contains( $sql, 'SELECT id,visit_id,visitor_id,type,path' ) ) { preg_match( '/id > (\d+)/', $sql, $m ); return array_slice( array_values( array_filter( $this->timed( $sql ), static fn( $e ) => $e['id'] > (int) $m[1] && in_array( $e['type'], array( 'product_view', 'add_to_cart' ), true ) ) ), 0, 500 ); }
			if ( str_contains( $sql, 'COUNT(*) AS n' ) ) {
				$cart = str_contains( $sql, 'GROUP BY product_id, variation_id' ); $groups = array();
				foreach ( $this->timed( $sql ) as $event ) { if ( $event['product_id'] <= 0 || ! in_array( $event['type'], $cart ? array( 'add_to_cart' ) : array( 'product_view', 'add_to_cart' ), true ) ) { continue; } $key = $event['product_id'] . ':' . ( $cart ? $event['variation_id'] : $event['type'] ); if ( ! isset( $groups[$key] ) ) { $groups[$key] = array( 'product_id' => $event['product_id'], 'variation_id' => $event['variation_id'], 'type' => $event['type'], 'n' => 0, 'set' => array(), 'units' => 0 ); } ++$groups[$key]['n']; $groups[$key]['set'][$event['visitor_id']] = true; $groups[$key]['units'] += json_decode( $event['data'], true )['quantity'] ?? 0; }
				return array_values( array_map( static function ( $g ) { $g['users'] = count( $g['set'] ); return $g; }, $groups ) );
			}
			throw new RuntimeException( 'Unexpected query boundary: ' . $sql );
		}
	}
	function wp_timezone(){return new DateTimeZone('Asia/Shanghai');} function wp_date($format,$timestamp,$zone=null){return (new DateTimeImmutable('@'.$timestamp))->setTimezone($zone??wp_timezone())->format($format);}
	$root = dirname( __DIR__ ); foreach ( array( 'api/class-errors.php', 'tracking/class-attribution.php', 'tracking/class-page-context.php', 'tracking/class-impressions.php', 'data/class-report-dimensions.php', 'data/class-report-filter.php', 'data/class-range.php', 'data/class-params.php', 'data/class-operations.php', 'data/class-page-conversion.php', 'data/class-product-exposure.php', 'data/class-products.php', 'data/class-csv-response.php', 'data/class-report-exports.php' ) as $file ) { require $root . '/includes/' . $file; }
	use PeptideCore\Data\Products as Products051;
	use PeptideCore\Data\Report_Exports as Exports051;
	use PeptideCore\Data\Report_Filter as Filter051;
	class Buyer051 extends WC_Order {
		public int $customer051=0; public string $email051='Buyer@Example.test';
		public function get_customer_id(){return $this->customer051;}
		public function get_billing_email(){return $this->email051;}
	}
	$wpdb=new Exposure_DB();$products=array(11=>new WC_Product(11),111=>new WC_Product_Variation(111,'Variant'),12=>new WC_Product(12,'Second'));
	foreach(array(1=>1,2=>2,3=>3,4=>4) as $vid=>$owner){$wpdb->visits[$vid]=array('id'=>$vid,'visitor_id'=>$owner,'start_ms'=>51000,'is_test'=>0,'attribution_version'=>1,'attribution_ms'=>52000,'attribution_host'=>$vid===2?'b.test':'a.test','utm_source'=>null,'utm_medium'=>null);$wpdb->visitor_test[$owner]=false;}
	$event051=static function($id,$type,$pid,$visit,$visitor,$at,$variation=0,$data=array())use($wpdb){$wpdb->events[]=array('id'=>$id,'event_uid'=>'ratio-'.$id,'type'=>$type,'source'=>$type==='add_to_cart'?'server':'client','product_id'=>$pid,'variation_id'=>$variation,'visit_id'=>$visit,'visitor_id'=>$visitor,'occurred_ms'=>$at,'path'=>'/synthetic/','page_type'=>'product','order_id'=>0,'data'=>json_encode($data),'is_test'=>0);};
	$event051(1,'product_view',11,1,1,110000);$event051(2,'product_view',11,1,1,111000);$event051(3,'product_view',11,1,1,112000);
	$event051(4,'add_to_cart',11,1,1,113000,111,array('quantity'=>2));$event051(5,'add_to_cart',11,1,1,114000,111,array('quantity'=>1));$event051(6,'add_to_cart',11,2,2,115000,111,array('quantity'=>1));
	$event051(7,'product_view',12,3,3,110000);$event051(8,'add_to_cart',12,3,3,115000,0,array('quantity'=>1));$event051(9,'add_to_cart',13,2,2,115000,0,array('quantity'=>1));
	$event051(10,'product_view',11,1,1,60000);$event051(11,'product_view',11,4,4,60000);$event051(12,'add_to_cart',11,1,1,61000,111,array('quantity'=>1));$event051(13,'product_view',14,4,4,62000);
	foreach(array(1=>'US',2=>'CA',3=>'US',4=>'US') as $vid=>$code){$event051(20+$vid,'page_view',0,$vid,$vid,53000,0,array('page_context'=>array('version'=>1,'kind'=>'home','geo'=>array('status'=>'known','country_code'=>$code))));}
	$event051(30,'page_view',0,1,1,200000,0,array('page_context'=>array('version'=>1,'kind'=>'home','geo'=>array('status'=>'known','country_code'=>'CA'))));
	$event051(31,'page_view',0,4,4,100000,0,array('page_context'=>array('version'=>1,'kind'=>'home','geo'=>array('status'=>'unknown'))));
	$orders=array();
	foreach(array(1,2,3,4) as $id){$order=new Buyer051($id,1,1,array(new WC_Order_Item_Product(100+$id,11,111,3)));if($id===2){$order->email051='buyer@example.test';}if($id===3){$order->customer051=7;}if($id===4){$order->email051='other@example.test';}$orders[]=$order;}
	$previous051=new Buyer051(5,1,1,array(new WC_Order_Item_Product(105,11,111,2)));$previous051->created=60;$orders[]=$previous051;
	$range051=array('from'=>100,'to'=>200,'compare_from'=>50,'compare_to'=>100,'dates'=>array('from'=>'1970-01-01','to'=>'1970-01-01'));
	$args051=array('sort'=>'views','order'=>'desc','page'=>1,'per_page'=>20,'q'=>'');
	$csv051=static function($columns,$args=null)use($range051,$args051){$method=new ReflectionMethod(Exports051::class,'products');$stream=fopen('php://temp','w+');foreach($method->invoke(null,$range051,$args??$args051,$columns) as $row){fputcsv($stream,$row,',','"','');}rewind($stream);return stream_get_contents($stream);};
	$oracle051=dirname(__DIR__,4).'/tests/fixtures/data-observations/051';
	if(in_array('--capture',$argv,true)){
		if(count(Products051::METRICS)!==26){throw new RuntimeException('Capture must precede feature edit');}
		file_put_contents($oracle051.'/old26-table.json',json_encode(Products051::table($range051,$args051),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n");
		file_put_contents($oracle051.'/old26.csv',$csv051(Products051::METRICS));
		echo "Captured actual pre-edit 26-metric table and explicit CSV oracle\n";return;
	}
	$checks051=0;$check051=static function($label,$ok)use(&$checks051){if(!$ok){throw new RuntimeException($label);}++$checks051;};
	$table051=Products051::table($range051,$args051);$old051=json_decode(file_get_contents($oracle051.'/old26-table.json'),true);$oldkeys051=$old051['metrics'];
	$project051=static function($value)use(&$project051){if(!is_array($value)){return $value;}$out=array();foreach($value as $key=>$item){if(in_array($key,array('cart_user_rate','paid_user_rate','columns','report_timezone','created_at','created_at_label','favorite_count','share_count','share_users','intent_count','intent_users','repeat_view_users','returning_view_users','action_coverage','action_measurement'),true)){continue;}$out[$key]=$key==='metrics'?array_values(array_diff($item,array('cart_user_rate','paid_user_rate','favorite_count','share_count','share_users','intent_count','intent_users','repeat_view_users','returning_view_users','action_coverage','action_measurement'))):$project051($item);}return $out;};
	$check051('old26 entire table projection exactly matches pre-edit048',json_encode($project051($table051))===json_encode($old051));
	$check051('old explicit26 CSV bytes unchanged',$csv051($oldkeys051)===file_get_contents($oracle051.'/old26.csv'));
	$check051('append only28 unique metrics and unchanged defaultten',count(Products051::METRICS)===35 && count(array_unique(Products051::METRICS))===35 && array_slice(Products051::METRICS,0,26)===$oldkeys051 && array_slice(Products051::METRICS,26)===array('cart_user_rate','paid_user_rate','favorite_count','share_count','share_users','intent_count','intent_users','repeat_view_users','returning_view_users') && Products051::DEFAULTS===$old051['defaults']);
	$byid051=array();foreach($table051['rows'] as $row){$byid051[$row['product']['id']]=$row;}$row051=$byid051[11];
	$check051('cart numerator is distinct observed visitors not action count',$row051['current']['view_users']===1 && $row051['current']['cart_users']===2 && $row051['current']['carts']===3 && $row051['current']['cart_user_rate']===200.0);
	$check051('paid numerator retains account and normalized guest identity',$row051['current']['paid_users']===3 && $row051['current']['paid_user_rate']===300.0);
	$check051('new ratios uncapped and changes are percentage points',$row051['previous']['cart_user_rate']===50.0 && $row051['previous']['paid_user_rate']===50.0 && $row051['change']['cart_user_rate']===150.0 && $row051['change']['paid_user_rate']===250.0);
	$check051('variation new ratios are null',$row051['variations'][0]['current']['cart_user_rate']===null && $row051['variations'][0]['current']['paid_user_rate']===null);
	$check051('zero denominator null even when numerator positive',$byid051[13]['current']['cart_users']===1 && $byid051[13]['current']['cart_user_rate']===null && $byid051[13]['current']['paid_user_rate']===null);
	$metrics051=new ReflectionMethod(Products051::class,'metrics');$empty051=$metrics051->invoke(null,array(),false);$check051('missing denominator is null',$empty051['cart_user_rate']===null && $empty051['paid_user_rate']===null);
	$negative051=$metrics051->invoke(null,array('view_users'=>1,'cart_users'=>-1,'buyers'=>array('one'=>true)),false);$check051('defined negative robustness input remains defined',$negative051['cart_user_rate']===-100.0);
	// A synthetic SQL-boundary negative checks ordering robustness, not a possible visitor count.
	class NegativeRatios051 extends Exposure_DB { public function get_results($sql,$mode){$rows=parent::get_results($sql,$mode);if(str_contains($sql,'COUNT(*) AS n')){foreach($rows as &$r){if((int)$r['product_id']===12 && ($r['type']??'')==='add_to_cart'){$r['users']=-2;}}unset($r);}return $rows;} }
	$normal051=$wpdb;$negativeDB051=new NegativeRatios051();$negativeDB051->events=$wpdb->events;$negativeDB051->visits=$wpdb->visits;$negativeDB051->visitor_test=$wpdb->visitor_test;$wpdb=$negativeDB051;
	foreach(array('asc','desc') as $direction051){$negativeTable051=Products051::table($range051,array_replace($args051,array('sort'=>'cart_user_rate','order'=>$direction051)));$negativeIDs051=array_column(array_column($negativeTable051['rows'],'product'),'id');$check051('new ratio null sorts after defined negative '.$direction051,array_search(12,$negativeIDs051,true)<array_search(13,$negativeIDs051,true));}
	$wpdb=$normal051;
	foreach(array('cart_user_rate','paid_user_rate') as $key){$check051('new metric metadata includes rate '.$key,in_array($key,Products051::RATES,true));$sorted051=Products051::table($range051,array_replace($args051,array('sort'=>$key,'order'=>'asc')));$first051=array_values(array_filter($sorted051['rows'],static fn($r)=>$r['current'][$key]!==null));$null051=array_values(array_filter($sorted051['rows'],static fn($r)=>$r['current'][$key]===null));$check051('new ratio sorts null after defined ascending '.$key,array_merge($first051,$null051)===$sorted051['rows']);}
	$filter051=Filter051::params(array('entry_domain_state'=>'value','entry_domain'=>'A.TEST.','country_state'=>'value','country'=>'us'));
	$filtered051=Products051::table($range051,$args051+array('filter'=>$filter051));$filtered11=array_values(array_filter($filtered051['rows'],static fn($r)=>$r['product']['id']===11))[0];
	$check051('new ratios reuse canonical AND filter original visits and exact cutoff',$filtered051['filter']===array('entry_domain_state'=>'value','entry_domain'=>'a.test','country_state'=>'value','country'=>'US') && $filtered11['current']['cart_user_rate']===100.0 && $filtered11['current']['paid_user_rate']===300.0 && $filtered11['previous']['cart_user_rate']===50.0);
	$allcsv051=$csv051(Products051::METRICS,array_replace($args051,array('per_page'=>1)));$stream051=fopen('php://temp','w+');fwrite($stream051,$allcsv051);rewind($stream051);$header051=fgetcsv($stream051);$rows051=array();while(($r=fgetcsv($stream051))!==false){$rows051[]=$r;}
	$check051('all28 CSV typed pct and pp units',count($header051)===116 && in_array('current_cart_user_rate_pct',$header051,true) && in_array('change_cart_user_rate_pp',$header051,true) && in_array('current_paid_user_rate_pct',$header051,true) && in_array('change_paid_user_rate_pp',$header051,true));
	$check051('all28 CSV complete beyond page and escaped identity',count($rows051)>count(Products051::table($range051,array_replace($args051,array('per_page'=>1)))['rows']) && str_contains($allcsv051,"'=Formula"));
	$params051=Products051::params(array('sort'=>'paid_user_rate'));$check051('new sort accepted without changing filters',$params051['sort']==='paid_user_rate');
	echo 'PASS ',$checks051," product user ratio assertions, 0 FAIL\n";
}
