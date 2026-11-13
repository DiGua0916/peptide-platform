<?php
/** Standalone production cleaner/canonical/ingest with an in-memory SQL spy; no WordPress or DB bootstrap. */
namespace PeptideCore\Tracking {
	final class Schema { public static function table( string $name ): string { return 'fixture_' . $name; } }
	final class Tracker {
		public static int $allocations = 0;
		public static int $visit_calls = 0;
		public static bool $fail_visit = false;
		public static function cookie_user_id(): int { return 0; }
		public static function is_staff( int $id ): bool { return false; }
		// 2026-11-08 起 Ingest::handle() 先问 Tracker::is_automated()（爬虫不追踪）；桩没跟上，这个用例从那时起一直报错。
		public static function is_automated( string $ua = '' ): bool { return false; }
		public static function current_vid(): string { return str_repeat( 'a', 32 ); }
		public static function issue_vid(): string { return self::current_vid(); }
		public static function visitor( string $vid, int $now ): array { global $wpdb; ++self::$allocations; if ( ! isset( $wpdb->visitors[10] ) ) { $wpdb->visitors[10] = array( 'id' => 10, 'user_id' => 0 ); } return $wpdb->visitors[10]; }
		public static function visit( int $v, int $now, string $path ): int { global $wpdb; ++self::$visit_calls; if ( self::$fail_visit ) { return 0; } $wpdb->visits[20] = $wpdb->visits[20] ?? array( 'id' => 20, 'visitor_id' => $v, 'events' => 0, 'pageviews' => 0, 'stage' => 0 ); return 20; }
		public static function link_user( int $v, int $user, int $visit ): void { throw new \RuntimeException( 'Anonymous fixture must not link a user' ); }
		public static function raise_stage( int $visit, int $stage ): void { global $wpdb; $wpdb->visits[$visit]['stage'] = max( $stage, $wpdb->visits[$visit]['stage'] ); }
	}
}
namespace {
	require_once __DIR__ . '/product-actions-test-autoload.php';
	define( 'ABSPATH', __DIR__ . '/' ); define( 'DAY_IN_SECONDS', 86400 ); define( 'ARRAY_A', 'ARRAY_A' );
	function sanitize_text_field( $v ) { return strip_tags( $v ); }
	function wp_parse_url( $v, $part = -1 ) { return parse_url( $v, $part ); }
	function wp_unslash( $v ) { return stripslashes( $v ); }
	function wp_hash( $v ) { return hash( 'sha256', $v ); }
	function wp_json_encode( $v ) { return json_encode( $v ); }
	function get_transient( $key ) { return false; }
	function set_transient( $key, $value, $expiry ) { return true; }
	function __( $s, $domain = '' ) { return $s; }
	class WP_Error { private array $args; public function __construct( ...$args ) { $this->args = $args; } public function get_error_code() { return $this->args[0]; } public function get_error_data() { return $this->args[2]; } }

	final class Impression_SQL_Spy {
		public string $posts = 'fixture_posts';
		public string $last_error = '';
		public array $events = array();
		public array $visitors = array( 10 => array( 'id' => 10, 'user_id' => 0 ) );
		public array $visits = array( 20 => array( 'id' => 20, 'visitor_id' => 10, 'events' => 0, 'pageviews' => 0, 'stage' => 0 ) );
		public array $tabs = array();
		public array $native = array( 123 => array( 'ID' => 123, 'post_type' => 'product', 'post_status' => 'publish', 'post_parent' => 0 ), 124 => array( 'ID' => 124, 'post_type' => 'product_variation', 'post_status' => 'publish', 'post_parent' => 123 ) );
		public array $counts = array( 'native' => 0, 'winners' => 0, 'inserts' => 0 );
		public bool $fail_native = false;
		public bool $fail_winners = false;
		public bool $fail_insert = false;
		public bool $lose_insert = false;
		public bool $fail_identity_reread = false;
		public bool $fail_visit_read = false;
		public int $identity_reads = 0;
		public $before_native = null;
		public $before_insert = null;
		private array $bindings = array();
		public function prepare( string $sql, ...$args ): string { if ( count( $args ) === 1 && is_array( $args[0] ) ) { $args = $args[0]; } $id = count( $this->bindings ); $this->bindings[$id] = $args; return '/*spy:' . $id . '*/' . $sql; }
		private function args( string $sql ): array { preg_match( '/\A\/\*spy:(\d+)\*\//', $sql, $m ); return isset( $m[1] ) ? $this->bindings[(int) $m[1]] : array(); }
		public function get_row( string $sql, $format ): ?array {
			$this->last_error = ''; $a = $this->args( $sql );
			if ( str_contains( $sql, 'fixture_visitors' ) ) { ++$this->identity_reads; if ( $this->identity_reads > 1 && $this->fail_identity_reread ) { $this->last_error = 'synthetic identity reread failure'; return null; } return $this->visitors[10] ?? null; }
			if ( str_contains( $sql, 'fixture_events' ) ) { return $this->events[$a[1]] ?? null; }
			throw new RuntimeException( 'Unexpected read shape in pure spy' );
		}
		public function get_results( string $sql, $format ): ?array {
			$this->last_error = ''; $a = $this->args( $sql );
			if ( str_contains( $sql, 'fixture_posts' ) ) {
				++$this->counts['native']; if ( $this->before_native ) { ( $this->before_native )( $this ); }
				if ( $this->fail_native ) { $this->last_error = 'synthetic native failure'; return null; }
				return array_values( $this->native );
			}
			if ( str_contains( $sql, 'fixture_events' ) ) {
				++$this->counts['winners']; if ( $this->fail_winners ) { $this->last_error = 'synthetic saved read failure'; return null; }
				$visitor = array_shift( $a ); $out = array();
				foreach ( $a as $uid ) { if ( isset( $this->events[$uid] ) && $this->events[$uid]['visitor_id'] === $visitor ) { $r = $this->events[$uid]; $r['matched_visitor_id'] = isset( $this->visitors[$visitor] ) ? $visitor : null; $r['matched_visit_id'] = isset( $this->visits[$r['visit_id']] ) && $this->visits[$r['visit_id']]['visitor_id'] === $visitor ? $r['visit_id'] : null; $out[] = $r; } }
				return $out;
			}
			throw new RuntimeException( 'Unexpected batch read shape in pure spy' );
		}
		public function get_var( string $sql ) {
			$this->last_error = ''; $a = $this->args( $sql );
			if ( str_contains( $sql, 'SELECT s.id FROM fixture_visits' ) ) { if ( $this->fail_visit_read ) { $this->last_error = 'synthetic visit read failure'; return null; } return isset( $this->visits[$a[0]], $this->visitors[$a[1]] ) && $this->visits[$a[0]]['visitor_id'] === $a[1] ? $a[0] : null; }
			throw new RuntimeException( 'Unexpected scalar read in pure spy' );
		}
		public function query( string $sql ) {
			$this->last_error = ''; $a = $this->args( $sql );
			if ( str_contains( $sql, 'INSERT IGNORE INTO fixture_events' ) ) {
				++$this->counts['inserts']; if ( $this->before_insert ) { ( $this->before_insert )( $this, $a ); }
				if ( $this->fail_insert ) { $this->last_error = 'synthetic insert failure'; return false; }
				if ( $this->lose_insert || isset( $this->events[$a[0]] ) ) { return 0; }
				preg_match( '/\((event_uid,.*?)\) VALUES \((.*?)\)/', $sql, $m );
				$columns = array_map( 'trim', explode( ',', $m[1] ) ); $tokens = array_map( 'trim', explode( ',', $m[2] ) ); $row = array(); $i = 0;
				foreach ( $columns as $j => $column ) { $token = $tokens[$j]; $row[$column] = str_starts_with( $token, '%' ) ? $a[$i++] : ( is_numeric( $token ) ? (int) $token : trim( $token, "'" ) ); }
				$this->events[$a[0]] = $row; return 1;
			}
			if ( str_contains( $sql, 'INSERT IGNORE INTO fixture_tabs' ) ) { $this->tabs[$a[2]] = $this->tabs[$a[2]] ?? array( 'visitor_id' => $a[0], 'visit_id' => $a[1], 'tab_id' => $a[2], 'last_signal_ms' => $a[3] ); return 1; }
			if ( str_contains( $sql, 'UPDATE fixture_tabs' ) ) { $this->tabs[$a[3]]['visit_id'] = $a[0]; $this->tabs[$a[3]]['last_signal_ms'] = max( $this->tabs[$a[3]]['last_signal_ms'], $a[1] ); return 1; }
			if ( str_contains( $sql, 'UPDATE fixture_visits' ) ) { $id = $a[count( $a ) - 1]; $this->visits[$id]['events'] += $a[0]; if ( count( $a ) >= 4 ) { $this->visits[$id]['pageviews'] += $a[1]; } return 1; }
			if ( str_contains( $sql, 'UPDATE fixture_visitors' ) ) { return 1; }
			throw new RuntimeException( 'Unexpected mutation shape in pure spy' );
		}
	}

	require_once dirname( __DIR__ ) . '/includes/tracking/class-impressions.php';
	require_once dirname( __DIR__ ) . '/includes/tracking/class-attribution.php';
	require_once dirname( __DIR__ ) . '/includes/tracking/class-page-context.php';
	require_once dirname( __DIR__ ) . '/includes/tracking/class-ingest.php';
	use PeptideCore\Tracking\Impressions;
	use PeptideCore\Tracking\Ingest;
	use PeptideCore\Tracking\Tracker;

 $n=0;$check=static function($name,$ok)use(&$n){if(!$ok){throw new RuntimeException($name);}++$n;};
 $source=\PeptideCore\Tracking\Product_Actions::class;
 $clean=array('version'=>1,'product_id'=>123,'variation_id'=>0,'action'=>'add');
 $wire=$clean+array('private'=>'discard','recipient'=>'discard@example.test');
 $check('atomic whitelist and no contacts',$source::clean('product_favorite',$wire)===array('ok'=>true,'value'=>$clean));
 foreach(array(null,false,true,'1',1.0)as$v){$d=$wire;$d['version']=$v;$check('strict version type',!$source::clean('product_favorite',$d)['ok']);}
 foreach(array(0,-1,'123',1.0,9007199254740992)as$v){$d=$wire;$d['product_id']=$v;$check('strict safe parent',!$source::clean('product_favorite',$d)['ok']);}
 $d=$wire;$d['version']=2;$check('unsupported version explicit',$source::clean('product_favorite',$d)['code']==='unsupported_product_action_version');
 foreach(array('native','copy_link','other')as$method){$check('share accepted method',$source::clean('product_share',array('version'=>1,'product_id'=>123,'method'=>$method,'result'=>'completed'))['ok']);}
 foreach(array('quick_view_open','buy_now')as$kind){$check('explicit intent only',$source::clean('product_intent',array('version'=>1,'product_id'=>123,'kind'=>$kind,'result'=>'completed'))['ok']);}
 $check('cancelled share rejected',!$source::clean('product_share',array('version'=>1,'product_id'=>123,'method'=>'native','result'=>'cancelled'))['ok']);
 $reset=static function(){global$wpdb;$wpdb=new Impression_SQL_Spy();Tracker::$allocations=0;Tracker::$visit_calls=0;Tracker::$fail_visit=false;};
 $event=static function($uid,$type='product_favorite',$data=null)use($wire){return array('event_id'=>$uid,'page_instance_id'=>'page-0001','seq'=>1,'type'=>$type,'occurred_at'=>1000,'page'=>array('page_type'=>'shop','path'=>'/Actual%20Path/'),'data'=>$data??$wire);};
 $run=static fn($events)=>Ingest::handle(array('events'=>$events,'tab_id'=>'tab-0001'),2000);
 $reset();$r=$run(array($event('action-0001')));$check('new actual receiver ACK',$r['ack']===array('action-0001'));
 $check('canonical original path/no extras',json_decode($wpdb->events['action-0001']['data'],true)===$clean&&$wpdb->events['action-0001']['path']==='/Actual%20Path/');
 $check('no funnel promotion',$wpdb->visits[20]['events']===1&&$wpdb->visits[20]['pageviews']===0&&$wpdb->visits[20]['stage']===0);
 $snapshot=$wpdb->tabs;$wpdb->native=array();Tracker::$visit_calls=0;$r=$run(array($event('action-0001')));$check('duplicate catalog-independent no new visit/signal',$r['ack']===array('action-0001')&&Tracker::$visit_calls===0&&$wpdb->tabs===$snapshot&&$wpdb->visits[20]['events']===1);
 $saved=$wpdb->events['action-0001'];$saved['matched_visitor_id']=10;$saved['matched_visit_id']=20;
 foreach(array('type'=>'product_share','tab_id'=>'tab-other','page_instance_id'=>'page-other','seq'=>2,'path'=>'/Changed/','page_type'=>'product','product_id'=>124,'variation_id'=>124)as$k=>$v){$bad=$saved;$bad[$k]=$v;$incoming=$event('action-0001');$incoming['tab_id']='tab-0001';$incoming['page']=$incoming['page'];$incoming['data']=$clean;$check('frozen identity '.$k,!$source::equal(10,$incoming,$bad));}
 unset($wpdb->visits[20]);$r=$run(array($event('action-0001')));$check('orphan original rejected without new session',$r['rejected'][0]['code']==='original_visit_missing'&&Tracker::$visit_calls===0);
 $reset();$a=$event('action-0001');$b=$a;$b['data']['variation_id']=124;$r=$run(array($a,$a,$b));$check('same batch frozen winner',$r['ack']===array('action-0001','action-0001')&&$r['rejected'][0]['code']==='duplicate_conflict'&&$wpdb->visits[20]['events']===1);
 $reset();$share=$event('action-0001','product_share',array('version'=>1,'product_id'=>123,'method'=>'native','result'=>'completed'));$r=$run(array($a,$share));$check('same UID cross-type conflict',$r['rejected'][0]['code']==='duplicate_conflict');
 $reset();$bad=$wire;$bad['product_id']=999;unset($wpdb->visitors[10]);$r=$run(array($event('action-9999','product_favorite',$bad)));$check('bad catalog allocates nothing',$r['rejected'][0]['code']==='bad_product_action'&&Tracker::$allocations===0&&Tracker::$visit_calls===0);
 $reset();$bad=$wire;$bad['variation_id']=124;$r=$run(array($event('action-0002','product_favorite',$bad)));$check('owned published variant accepted',$r['ack']===array('action-0002'));
 $reset();$wpdb->native[124]['post_parent']=999;$r=$run(array($event('action-0002','product_favorite',$bad)));$check('wrong variant parent rejected',$r['rejected'][0]['code']==='bad_product_action');
 $reset();$wpdb->before_insert=static function($db,$args)use($saved){$db->events['action-0001']=$saved;};$r=$run(array($event('action-0001'),$event('action-0001')));$check('lost race reread canonical winner',$r['ack']===array('action-0001','action-0001')&&$wpdb->counts['winners']===2&&$wpdb->visits[20]['events']===0);
 $reset();$wpdb->lose_insert=true;$r=$run(array($event('action-0001')));$check('lost race missing winner retries',$r['ack']===array()&&$r['rejected'][0]['retry']);
 $reset();$bad=$event('action-path');$bad['page']['path']='/bad?token=x';$r=$run(array($bad));$check('strict raw path rejects rather than rewrites',$r['rejected'][0]['code']==='bad_product_action'&&Tracker::$visit_calls===0);
 echo 'PASS ',$n," strict product action receiver assertions, 0 FAIL\n";
}
