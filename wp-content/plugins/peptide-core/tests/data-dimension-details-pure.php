<?php
/** Actual dimension-details API/reducers/CSV with in-memory native boundaries only. */
namespace PeptideCore\Tracking {
    final class Schema { public static bool $installed=true; public static int $checks=0; public static function installed(): bool { ++self::$checks; return self::$installed; } public static function table(string $name): string { return 'track_'.$name; } }
    final class Server_Events { public const ORDER_VISIT_META='visit'; public const ORDER_VISITOR_META='visitor'; }
}
namespace PeptideCore {
    final class Overview {
        public static function is_test_order($order): bool { return $order->test; }
        public static function window($preset,$now=null): array { $d=(new \DateTimeImmutable('@'.($now??time())))->setTimezone(\wp_timezone())->setTime(0,0); return array($d->getTimestamp(),$d->modify('+1 day')->getTimestamp()); }
    }
}
namespace PeptideCore\Api {
    final class Errors { public static function invalid(array $fields) { return new \WP_Error('pc_invalid_param','Invalid test params',array('status'=>400,'fields'=>$fields)); } }
}
namespace PeptideCore\Data {
    final class Operations { public static array $orders=array(); public static function paid_orders(int $from,int $to): array { return array_values(array_filter(self::$orders,static fn($o)=>$o->paid&&$o->created >= $from&&$o->created < $to)); } }
}
namespace {
    define('ABSPATH',__DIR__); define('ARRAY_A','ARRAY_A');
    final class WP_Error {
        public function __construct(public string $code, public string $message='', public array $data=array()) {}
        public function get_error_code(): string { return $this->code; } public function get_error_data(): array { return $this->data; }
    }
    class WP_REST_Response {
        protected $data; private int $status; private array $headers;
        public function __construct($data=null,int $status=200,array $headers=array()){ $this->data=$data;$this->status=$status;$this->headers=$headers; }
        public function get_data(){return $this->data;} public function get_status(): int{return $this->status;} public function set_status(int $v): void{$this->status=$v;} public function get_headers(): array{return $this->headers;} public function header($k,$v): void{$this->headers[$k]=$v;}
    }
    final class WP_REST_Request {
        public int $reads=0; public function __construct(private array $params=array(),private string $method='GET',private string $route='/peptide/v1/data/dimension-details'){}
        public function get_params(): array {++$this->reads;return $this->params;} public function get_param($key){++$this->reads;return $this->params[$key]??null;} public function has_param($key): bool{++$this->reads;return array_key_exists($key,$this->params);}
        public function get_route(): string{return $this->route;} public function get_method(): string{return $this->method;}
    }
    final class WP_REST_Server { public const READABLE='GET'; }
    final class WC_Order {
        public bool $test=false,$paid=true; public int $created=15;
        public function __construct(public int $id,public $visit,public $visitor,public float $total=20,public float $refund=5,public int $customer=0,public string $email='buyer@example.invalid'){}
        public function get_id(){return $this->id;}public function get_meta($key){return $this->$key;}public function get_total(){return $this->total;}public function get_total_refunded(){return $this->refund;}public function get_customer_id(){return $this->customer;}public function get_billing_email(){return $this->email;}
    }
    $user082=1;$cap082=true;$tz082='UTC';$routes082=array();
    function get_current_user_id(){return $GLOBALS['user082'];}function current_user_can($cap){return $cap==='manage_woocommerce'&&$GLOBALS['cap082'];}
    function rest_authorization_required_code(){return get_current_user_id()>0?403:401;} function is_wp_error($v): bool{return $v instanceof WP_Error;}function rows082($value): array{return is_array($value)?$value:iterator_to_array($value);}
    function __($v,$domain=''){return $v;}function wp_timezone(){return new DateTimeZone($GLOBALS['tz082']);}function get_woocommerce_currency(){return 'USD';}
    function register_rest_route($ns,$route,$args){$GLOBALS['routes082'][]=array($ns,$route,$args);}
    function WC(){return (object)array('countries'=>new class{public function get_countries(){return array('US'=>'United States','DE'=>'Germany','CI'=>'Côte d’Ivoire','BV'=>'Bouvet Island');}});}
    function home_url(){return 'https://example.invalid';}function wp_parse_url($v,$part=-1){return parse_url($v,$part);}function sanitize_text_field($v){return strip_tags($v);}
    if(!function_exists('mb_strlen')){function mb_strlen($v,$encoding=null){return count(preg_split('//u',$v,-1,PREG_SPLIT_NO_EMPTY));}}
    if(!function_exists('mb_substr')){function mb_substr($v,$offset,$len=null,$encoding=null){return implode('',array_slice(preg_split('//u',$v,-1,PREG_SPLIT_NO_EMPTY),$offset,$len));}}
    if(!function_exists('mb_stripos')){function mb_stripos($v,$q,$offset=0,$encoding=null){return stripos($v,$q,$offset);}}
    final class Memory082 {
        public string $last_error='';public array $visits=array(),$visitors=array(),$events=array(),$queries=array();public string $fail='';public int $writes=0;
        public function prepare(string $sql,...$args): string {
            if(count($args)===1&&is_array($args[0]))$args=$args[0];$i=0;
            return preg_replace_callback('/%[dsf]/',static function($m)use(&$i,$args){$v=$args[$i++];return $m[0]==='%s'?"'".addslashes((string)$v)."'":(string)(int)$v;},$sql);
        }
        public function query($sql){++$this->writes;throw new RuntimeException('Unexpected mutating query');}
        public function get_results(string $sql,$mode): array|null {
            $this->queries[]=$sql;if($this->fail==='throw')throw new RuntimeException('Synthetic read failure');if($this->fail==='sql'||$this->fail==='null'){$this->last_error='synthetic';return $this->fail==='null'?null:array();}$this->last_error='';
            $event=str_contains($sql,'FROM track_events');$rows=array_values($event?$this->events:$this->visits);
            if(str_contains($sql,'JOIN track_visitors')){
                $joined=array();foreach($rows as $row){$owner=$this->visitors[$row['visitor_id']]??null;if(!$owner||$row['is_test']||$owner['is_test'])continue;$joined[]=$row+array('browser'=>$owner['browser']);}$rows=$joined;
            }
            if(preg_match_all('/s\.id\s*=\s*([0-9]+)\s+AND\s+s\.visitor_id\s*=\s*([0-9]+)/',$sql,$pairs,PREG_SET_ORDER)&&count($pairs)){
                $allowed=array();foreach($pairs as $p)$allowed[(int)$p[1].'|'.(int)$p[2]]=true;
                $rows=array_values(array_filter($rows,static fn($r)=>isset($allowed[$r['id'].'|'.$r['visitor_id']])));
            }
            if(preg_match('/(?:visit_id|(?<![a-z_])id)\s+IN\s*\(([0-9, ]+)\)/',$sql,$m)){$ids=array_map('intval',explode(',',$m[1]));$key=$event?'visit_id':'id';$rows=array_values(array_filter($rows,static fn($r)=>in_array((int)$r[$key],$ids,true)));}
            if(preg_match('/(?:s\.)?start_ms\s*>=\s*(-?[0-9]+)\s+AND\s+(?:s\.)?start_ms\s*<\s*(-?[0-9]+)/',$sql,$m))$rows=array_values(array_filter($rows,static fn($r)=>$r['start_ms']>=(int)$m[1]&&$r['start_ms']<(int)$m[2]));
            if(preg_match('/(?:s\.)?id\s*>\s*([0-9]+)/',$sql,$m))$rows=array_values(array_filter($rows,static fn($r)=>$r['id']>(int)$m[1]));
            if($event){
                if(preg_match("/type\s*=\s*'([^']+)'/",$sql,$m))$rows=array_values(array_filter($rows,static fn($r)=>$r['type']===$m[1]));
                if(preg_match('/occurred_ms\s*<\s*([0-9]+)\s+AND\s+\(occurred_ms\s*<\s*([0-9]+)\s+OR\s+\(occurred_ms\s*=\s*([0-9]+)\s+AND\s+id\s*<\s*([0-9]+)\)\)/',$sql,$m))$rows=array_values(array_filter($rows,static fn($r)=>$r['occurred_ms']<(int)$m[1]&&($r['occurred_ms']<(int)$m[2]||($r['occurred_ms']==(int)$m[3]&&$r['id']<(int)$m[4]))));
            }
            usort($rows,static fn($a,$b)=>$event?($b['occurred_ms']<=>$a['occurred_ms']?:$b['id']<=>$a['id']):$a['id']<=>$b['id']);
            return preg_match('/LIMIT\s+500/',$sql)?array_slice($rows,0,500):$rows;
        }
    }
    foreach(array('tracking/class-attribution.php','tracking/class-page-context.php','data/class-params.php','data/class-range.php','data/class-report-dimensions.php','data/class-report-filter.php','data/class-visit-metrics.php','data/class-dimension-reports.php','data/class-csv-response.php','data/class-dimension-details.php')as$file)require dirname(__DIR__).'/includes/'.$file;
    use PeptideCore\Data\Dimension_Details as Details082;use PeptideCore\Data\Operations as Ops082;use PeptideCore\Data\Range as Range082;use PeptideCore\Data\Csv_Response as Csv082;use PeptideCore\Tracking\Schema as Schema082;
    $passed082=0;$check082=static function(string $label,bool $ok)use(&$passed082):void{if(!$ok)throw new RuntimeException('FAIL '.$label);++$passed082;};
    $status082=static fn($r)=>$r instanceof WP_Error?(int)($r->get_error_data()['status']??0):$r->get_status();
    $metrics082=array('uv','customers','viewed_uv','carted_uv','paid_uv','orders','conversion','sales','aov','uv_value');
    $args082=static function(array $v=array()){ $out=Details082::params($v);if(is_wp_error($out))throw new RuntimeException('Unexpected invalid test args');return $out; };
    $range082=array('preset'=>null,'from'=>10,'to'=>20,'compare_from'=>0,'compare_to'=>10,'days'=>1,'bucket'=>'hour','dates'=>array('from'=>'1970-01-01','to'=>'1970-01-01','compare_from'=>'1969-12-31','compare_to'=>'1969-12-31'));
    $reset082=static function():void{ $GLOBALS['wpdb']=new Memory082();Ops082::$orders=array();Schema082::$installed=true;Schema082::$checks=0;$GLOBALS['user082']=1;$GLOBALS['cap082']=true;$GLOBALS['tz082']='UTC'; };
    $visit082=static function(int $id,int $visitor,$source='A',$medium='one',string $device='mobile',string $browser='Chrome',int $start=11000,int $stage=2,int $user=0,string $host='entry.test'):void{
        global $wpdb;$wpdb->visitors[$visitor]=array('id'=>$visitor,'is_test'=>0,'browser'=>$browser);
        $wpdb->visits[$id]=array('id'=>$id,'visitor_id'=>$visitor,'user_id'=>$user,'stage'=>$stage,'device'=>$device,'start_ms'=>$start,'is_test'=>0,'attribution_version'=>1,'attribution_event_uid'=>'pv-'.$id,'attribution_ms'=>$start+1,'attribution_host'=>$host,'attribution_path'=>'/P'.$id,'attribution_query'=>'','utm_source'=>$source,'utm_medium'=>$medium,'referrer_host'=>null);
    };
    $geo082=static function(int $id,int $visit,int $visitor,int $at,$geo=null):void{
        global $wpdb;$context=array('version'=>1,'kind'=>'content');if($geo!==null)$context['geo']=$geo;
        $wpdb->events[]=array('id'=>$id,'visit_id'=>$visit,'visitor_id'=>$visitor,'occurred_ms'=>$at,'source'=>'client','type'=>'page_view','data'=>json_encode(array('page_context'=>$context)));
    };
    $seed082=static function()use($reset082,$visit082,$geo082):void{
        $reset082();global $wpdb;
        $visit082(1,7,'A','one','mobile','Chrome',11000,2,11);$visit082(2,7,'A','two','tablet','Chrome',12000,1);
        $visit082(3,8,'B','one','desktop','Firefox',13000,0);$visit082(4,9,null,null,'','',14000,2);$visit082(5,10,'','','Mobile','nonsense',15000,0);
        $visit082(6,11,'unobserved','one','desktop','Other',15000,2);$visit082(7,12,'late','one','mobile','Edge',13000,1);$wpdb->visits[7]['attribution_ms']=20000;
        $visit082(8,13,'ignored','one','invalid','Opera',13000,0);$wpdb->visits[8]['attribution_version']=0;
        $visit082(9,14,'Test visit');$wpdb->visits[9]['is_test']=1;$visit082(10,15,'Test visitor');$wpdb->visitors[15]['is_test']=1;$visit082(11,16,'Deleted visitor');unset($wpdb->visitors[16]);
        $visit082(100,20,'Order only','one','tablet','Safari',1000,0);$visit082(101,21,'Previous','one','desktop','Samsung',1000,1);$visit082(102,22,'A','one','mobile','Chrome',2000,0);$visit082(103,23,'ZeroPrev','one','desktop','Safari',3000,0);
        $visit082(104,24,'ZeroPrev','one','mobile','Chrome',14000,1);$visit082(105,25,'=1+1','one','desktop','Firefox',14000,0);
        $geo082(1,1,7,11001,array('status'=>'known','country_code'=>'DE'));$geo082(2,1,7,13000,array('status'=>'unknown'));$geo082(3,1,7,19000);
        $geo082(4,2,7,11000,array('status'=>'known','country_code'=>'US'));$geo082(5,2,7,11000,array('status'=>'unavailable'));$geo082(6,2,888,19000,array('status'=>'known','country_code'=>'DE'));
        $geo082(7,3,8,19999,array('status'=>'known','country_code'=>'US'));$geo082(8,3,8,20000,array('status'=>'known','country_code'=>'DE'));
        $geo082(9,7,12,20000,array('status'=>'known','country_code'=>'US'));$geo082(10,100,20,5000,array('status'=>'known','country_code'=>'US'));$geo082(11,100,20,15000,array('status'=>'known','country_code'=>'DE'));$geo082(12,105,25,15000,array('status'=>'known','country_code'=>'CI'));
        Ops082::$orders=array(new WC_Order(1,1,7,40,10),new WC_Order(2,2,7,20,5),new WC_Order(3,100,20,50,0),new WC_Order(4,1,999),new WC_Order(5,9999,30),new WC_Order(6,'1x',7),new WC_Order(7,9,14),new WC_Order(8,10,15),new WC_Order(9,11,16),new WC_Order(10,1,7),new WC_Order(11,104,24,10,0),new WC_Order(12,1,7));
        Ops082::$orders[9]->test=true;Ops082::$orders[11]->paid=false;
        $previous=new WC_Order(201,100,20,10,0);$previous->created=5;Ops082::$orders[]=$previous;
    };
    $by082=static function(array $table):array{$out=array();foreach($table['rows']as$r)$out[$r['dimension']['state'].'|'.($r['dimension']['value']??'')]= $r;return $out;};

    $reset082();$defaults=$args082();
    $check082('normalized default args exact fields',count($defaults)===10&&!array_diff(array('dimension','sort','order','sort_period','page','per_page','q','split_medium','columns','filter'),array_keys($defaults))&&$defaults['dimension']==='utm'&&$defaults['sort']==='uv'&&$defaults['order']==='desc'&&$defaults['sort_period']==='current'&&$defaults['page']===1&&$defaults['per_page']===20&&$defaults['columns']===$metrics082&&$defaults['filter']===null);
    foreach(array(
        array('dimension'=>null),array('dimension'=>false),array('dimension'=>array()),array('dimension'=>'COUNTRY'),array('dimension'=>''),
        array('sort'=>null),array('sort'=>'not_metric'),array('order'=>true),array('order'=>'ASC'),array('sort_period'=>'last'),array('sort_period'=>array()),
        array('page'=>0),array('page'=>1.5),array('page'=>true),array('page'=>'1x'),array('page'=>'1000000000'),array('per_page'=>101),array('per_page'=>0),
        array('q'=>null),array('q'=>true),array('q'=>1),array('q'=>array()),array('q'=>str_repeat('界',101)),
        array('split_medium'=>true),array('split_medium'=>2),array('dimension'=>'country','split_medium'=>1),
        array('columns'=>null),array('columns'=>array()),array('columns'=>''),array('columns'=>'uv,uv'),array('columns'=>'uv,bad'),
        array('country_state'=>null),array('country_state'=>'value','country'=>'ZZ'),array('entry_domain_state'=>'value','entry_domain'=>'https://entry.test')
    )as$bad)$check082('strict invalid params '.json_encode($bad),is_wp_error(Details082::params($bad)));
    $ordered=$args082(array('columns'=>'sales,conversion,uv','page'=>'2','per_page'=>'1','split_medium'=>'1','q'=>' A '));$check082('ordered columns/numeric strings/trim preserved',$ordered['columns']===array('sales','conversion','uv')&&$ordered['page']===2&&$ordered['per_page']===1&&$ordered['q']==='A');
    $explicit=$args082(array('country_state'=>'all'));$check082('explicit typed All canonical',$explicit['filter']===array('entry_domain_state'=>'all','country_state'=>'all'));

    $seed082();$table=Details082::table($range082,$defaults);$by=$by082($table);$a=$by['value|A'];$old=$by['value|Previous'];$only=$by['value|Order only'];$unknown=$by['unobserved|'];
    $check082('JSON contract and all10maps',$table['version']===1&&$table['dimension']==='utm'&&$table['range']===$range082&&$table['currency']==='USD'&&$table['params']===$defaults&&array_keys($a['current'])===$metrics082&&array_keys($a['previous'])===$metrics082&&array_keys($a['change'])===$metrics082);
    $check082('typed JSON tuple key stable and decodable',json_decode($a['key'],true)===array($a['dimension'],null)&&$a['medium']===null);
    $check082('visitor rededup and native buyer/refund math',$a['current']===array('uv'=>1,'customers'=>2,'viewed_uv'=>1,'carted_uv'=>1,'paid_uv'=>1,'orders'=>2,'conversion'=>200.0,'sales'=>45.0,'aov'=>22.5,'uv_value'=>45.0));
    $check082('previous zero conversion change is finite percentage points',$a['previous']['conversion']===0.0&&$a['change']['conversion']===200.0&&$a['change']['orders']===null&&$by['value|ZeroPrev']['change']['conversion']===100.0);
    $check082('previous-only genuine blank current retained',$old['current']['uv']===0&&$old['current']['orders']===0&&$old['current']['conversion']===null&&$old['previous']['uv']===1);
    $check082('original order outside cohort not latest visit fallback',$only['current']['uv']===0&&$only['current']['orders']===1&&$only['current']['sales']===50.0&&$only['current']['conversion']===null&&$only['current']['uv_value']===null);
    $check082('bad owner only removes dimension attribution and retains saved visitor UV',$unknown['current']['orders']===6&&$unknown['current']['paid_uv']===6&&$unknown['current']['sales']===90.0);
    $check082('typed missing empty literal and unknown distinct',isset($by['missing|'],$by['empty|'],$by['value|unobserved'],$by['unobserved|'])&&count(array_unique(array_map(static fn($r)=>$r['key'],array($by['missing|'],$by['empty|'],$by['value|unobserved'],$by['unobserved|']))))===4);
    $check082('test visit/visitor/native test and unpaid excluded',!isset($by['value|Test visit'],$by['value|Test visitor'],$by['value|Deleted visitor'])&&array_sum(array_column(array_column($table['rows'],'current'),'orders'))===10);
    $check082('no internal identities leak',!preg_match('/"(?:visitor_id|visit_id|order_id|email|user_agent|ip)"/',json_encode($table)));
    $coverage=$table['coverage'];$stateKeys=array('value','missing','empty','unknown','unavailable','unobserved');
    $check082('coverage visits and orders count candidates not UV',$coverage['current']['visits']['base_count']===10&&$coverage['current']['visits']['matched_count']===10&&$coverage['current']['visits']['value']===6&&$coverage['current']['orders']['base_count']===10&&$coverage['current']['orders']['unobserved']===6);
    foreach(array('current','previous')as$period)foreach(array('visits','orders')as$unit){$c=$coverage[$period][$unit];$check082('state counts sum matched '.$period.$unit,array_sum(array_intersect_key($c,array_flip($stateKeys)))===$c['matched_count']&&$c['unit']===$unit);}
    $split=Details082::table($range082,$args082(array('split_medium'=>1)));$splitA=array_values(array_filter($split['rows'],static fn($r)=>$r['dimension']['state']==='value'&&$r['dimension']['value']==='A'));
    $check082('medium split rededups within groups only',count($splitA)===2&&$splitA[0]['current']['uv']===1&&$splitA[1]['current']['uv']===1&&$splitA[0]['medium']!==$splitA[1]['medium']);
    $check082('split labels contain only dimension while medium stays separate',$splitA[0]['label']==='A'&&$splitA[1]['label']==='A'&&array_values(array_unique(array_column(array_column($splitA,'medium'),'value')))===array('one','two'));
    $countries=$by082(Details082::table($range082,$args082(array('dimension'=>'country'))));
    $check082('country latest valid/tie/foreign/no-geo/exclusive cutoff',isset($countries['unknown|'],$countries['unavailable|'],$countries['unobserved|'],$countries['value|US'])&&$countries['unknown|']['current']['orders']===1&&$countries['unavailable|']['current']['orders']===1&&$countries['value|US']['current']['uv']===1&&$countries['value|US']['current']['orders']===0);
    $check082('country period cutoff differs for original old order',$countries['value|US']['previous']['orders']===1&&$countries['value|DE']['current']['orders']===1&&$countries['value|DE']['current']['uv']===0);
    $check082('country uses shared country label',$countries['value|CI']['label']==='Côte d’Ivoire');
    $devices=$by082(Details082::table($range082,$args082(array('dimension'=>'device'))));$browsers=$by082(Details082::table($range082,$args082(array('dimension'=>'browser'))));
    $check082('original visit device and original visitor browser',$devices['value|tablet']['current']['orders']===2&&$devices['value|mobile']['current']['orders']===2&&$browsers['value|Chrome']['current']['orders']===3&&$browsers['value|Safari']['current']['orders']===1);
    $check082('invalid device/browser unknown and bad original owner unobserved',$devices['unknown|']['current']['uv']===3&&$devices['unobserved|']['current']['orders']===6&&$browsers['unknown|']['current']['uv']===2&&$browsers['unobserved|']['current']['orders']===6);
    $domains=$by082(Details082::table($range082,$args082(array('dimension'=>'domain'))));$channels=$by082(Details082::table($range082,$args082(array('dimension'=>'channel'))));
    $check082('domain/channel actual saved identities',$domains['value|entry.test']['current']['orders']===4&&$channels['value|tagged_other']['current']['orders']===4&&$channels['unobserved|']['current']['orders']===6);
    $filterArgs=$args082(array('dimension'=>'country','entry_domain_state'=>'value','entry_domain'=>'ENTRY.TEST.','country_state'=>'unknown'));
    $filtered=Details082::table($range082,$filterArgs);$check082('typed AND filter scopes all metrics and original orders',$filtered['total']===1&&$filtered['rows'][0]['dimension']['state']==='unknown'&&$filtered['rows'][0]['current']['uv']===1&&$filtered['rows'][0]['current']['orders']===1&&$filtered['rows'][0]['current']['sales']===30.0);
    $check082('filter coverage base before filter and counters after',$filtered['coverage']['current']['visits']['base_count']===10&&$filtered['coverage']['current']['visits']['matched_count']===1&&$filtered['coverage']['current']['orders']['matched_count']===1&&$filtered['coverage']['current']['orders']['unknown']===1);
    $searched=Details082::table($range082,$args082(array('q'=>'=1+1')));$check082('search scopes groups only not coverage',$searched['total']===1&&$searched['rows'][0]['dimension']['value']==='=1+1'&&$searched['coverage']===$coverage);
    foreach(array('asc','desc')as$direction){$rows=rows082(Details082::all_rows($range082,$args082(array('sort'=>'conversion','order'=>$direction))));$seenNull=false;$ok=true;foreach($rows as$r){if($r['current']['conversion']===null)$seenNull=true;elseif($seenNull)$ok=false;}$check082('null below numeric both directions '.$direction,$ok);}
    $changed=rows082(Details082::all_rows($range082,$args082(array('sort'=>'conversion','sort_period'=>'change'))));$check082('change period sort uses PP metric',$changed[0]['dimension']['value']==='A'&&$changed[0]['change']['conversion']===200.0);
    $prev=rows082(Details082::all_rows($range082,$args082(array('sort'=>'conversion','sort_period'=>'previous'))));$check082('previous sort independent from current null',$prev[0]['dimension']['value']==='Order only'&&$prev[0]['previous']['conversion']===100.0);
    $all=rows082(Details082::all_rows($range082,$defaults));$page=Details082::table($range082,$args082(array('page'=>2,'per_page'=>2)));$check082('pagination uses same full sorted selection',$page['total']===count($all)&&$page['rows']===array_slice($all,2,2));

    Details082::register_routes();$route=$routes082[0];$check082('actual manager route callback registered',$route[0]==='peptide/v1'&&$route[1]==='/data/dimension-details'&&$route[2]['methods']==='GET'&&$route[2]['permission_callback']===array(Details082::class,'permission')&&$route[2]['callback']===array(Details082::class,'response'));
    foreach(array(array(0,false,401),array(2,false,403))as[$user082,$cap082,$expected]){$request=new WP_REST_Request();Schema082::$checks=0;$wpdb->queries=array();$response=Details082::response($request);$check082('permission before params and storage '.$expected,$status082($response)===$expected&&$request->reads===0&&Schema082::$checks===0&&!$wpdb->queries);}
    $user082=1;$cap082=true;
    $httpParams=array('from'=>'1970-01-01','to'=>'1970-01-01','compare_from'=>'1970-01-02','compare_to'=>'1970-01-02');
    $json=Details082::response(new WP_REST_Request($httpParams));$check082('actual JSON response no-store',$json instanceof WP_REST_Response&&$json->get_status()===200&&$json->get_headers()['Cache-Control']==='no-store');
    $tz082='America/Los_Angeles';$dst=Details082::response(new WP_REST_Request(array('from'=>'2026-11-01','to'=>'2026-11-01')));$check082('actual Range DST day is25 hours',$dst->get_data()['range']['to']-$dst->get_data()['range']['from']===90000);$tz082='UTC';
    foreach(array('xml',array(),null,true)as$format)$check082('strict response format',is_wp_error(Details082::response(new WP_REST_Request($httpParams+array('format'=>$format)))));
    foreach(array('_envelope'=>1,'_jsonp'=>'callback','_fields'=>'rows')as$key=>$value)$check082('CSV unsafe envelope '.$key,$status082(Details082::response(new WP_REST_Request($httpParams+array('format'=>'csv',$key=>$value))))===400);
    Schema082::$installed=false;$wpdb->queries=array();$check082('missing schema503 not empty success',$status082(Details082::response(new WP_REST_Request($httpParams)))===503&&!$wpdb->queries);Schema082::$installed=true;
    foreach(array('sql','null','throw')as$mode){$wpdb->fail=$mode;$check082('native reader failure503 '.$mode,$status082(Details082::response(new WP_REST_Request($httpParams)))===503);}$wpdb->fail='';$wpdb->last_error='';

    foreach(array(
        array(array('country_state'=>'all'),10,10),
        array(array('country_state'=>'unobserved'),6,7),
        array(array('country_state'=>'unknown'),1,1),
        array(array('country_state'=>'unavailable'),1,1),
        array(array('country_state'=>'value','country'=>'us'),1,0),
        array(array('country_state'=>'value','country'=>'DE'),0,1),
    )as[$filter,$visits,$orders]){
        $seed082();$r=Details082::table($range082,$args082(array('dimension'=>'country')+$filter));
        $check082('actual country filter state '.json_encode($filter),$r['coverage']['current']['visits']['matched_count']===$visits&&$r['coverage']['current']['orders']['matched_count']===$orders);
    }
    foreach(array(array('unobserved',null,2,6),array('value','ENTRY.TEST.',8,4))as[$state,$domain,$visits,$orders]){
        $seed082();$filter=array('entry_domain_state'=>$state);if($domain!==null)$filter['entry_domain']=$domain;$r=Details082::table($range082,$args082(array('dimension'=>'domain')+$filter));
        $check082('actual domain filter state '.$state,$r['coverage']['current']['visits']['matched_count']===$visits&&$r['coverage']['current']['orders']['matched_count']===$orders);
    }
    $seed082();Ops082::$orders[]=new WC_Order(900,1,7,INF,0);
    $finiteReport=Details082::table($range082,$defaults);$finiteBy=$by082($finiteReport);
    $check082('nonfinite native amounts never escape JSON numeric maps',$finiteBy['value|A']['current']['sales']===null&&$finiteBy['value|A']['current']['aov']===null&&$finiteBy['value|A']['current']['uv_value']===null&&json_encode($finiteReport)!==false);
    $reset082();
    for($i=1;$i<=501;$i++){$visit082($i,$i+1000,$i===1?'=1+1':'Source-'.str_pad((string)$i,4,'0',STR_PAD_LEFT));$visit082(10000+$i,2000+$i,'Order-'.str_pad((string)$i,4,'0',STR_PAD_LEFT),'one','tablet','Safari',1000);Ops082::$orders[]=new WC_Order($i,10000+$i,2000+$i,$i===1?0:20,$i===1?5:0);}
    $many=Details082::table($range082,$args082(array('per_page'=>100)));$queries=$wpdb->queries;
    $check082('501cohort +501original orders keep all1002 groups',$many['total']===1002&&count($many['rows'])===100&&count(rows082(Details082::all_rows($range082,$defaults)))===1002);
    $cohorts=array_values(array_filter($queries,static fn($q)=>str_contains($q,'JOIN track_visitors')&&str_contains($q,'start_ms')));
    $lookups=array_values(array_filter($queries,static fn($q)=>str_contains($q,'JOIN track_visitors')&&!str_contains($q,'start_ms')));
    $check082('bounded keyset and ownerpair chunks noNplus1',count($cohorts)===4&&count($lookups)===2&&max(array_map(static fn($q)=>preg_match_all('/s\.id\s*=/',$q),$lookups))<=500);
    $csvResponse=Details082::response(new WP_REST_Request($httpParams+array('format'=>'csv','columns'=>'sales,conversion,uv','per_page'=>1)));
    $rowsProperty=new ReflectionProperty(Csv082::class,'rows');$csv=rows082($rowsProperty->getValue($csvResponse));$header=$csv[0];
    $check082('actual complete CSV ignores pagination and stable width',count($csv)===1003&&count($header)===53&&count(array_unique(array_map('count',$csv)))===1);
    $check082('requested column order and percent/point units',array_slice($header,12,9)===array('current_sales','previous_sales','change_sales_pct','current_conversion_pct','previous_conversion_pct','change_conversion_pp','current_uv','previous_uv','change_uv_pct'));
    $check082('CSV MIME and filename native response',$csvResponse instanceof Csv082&&$csvResponse->get_headers()['Content-Type']==='text/csv; charset=UTF-8'&&str_contains($csvResponse->get_headers()['Content-Disposition'],'data-dimensions-utm-1970-01-01-1970-01-01.csv'));
    $formulaRows=array_values(array_filter(array_slice($csv,1),static fn($r)=>$r[3]==="'=1+1"));$check082('formula dimension escaped as text',count($formulaRows)===1&&$formulaRows[0][4]==="'=1+1");
    $negative=array_values(array_filter(array_slice($csv,1),static fn($r)=>is_numeric($r[12])&&(float)$r[12]===-5.0));$check082('genuine negative sales stays numeric no text prefix',count($negative)===1&&str_starts_with((string)$negative[0][12],'-'));
    $check082('CSV exact version/group and numeric coverage width',$csv[1][0]==='1'&&$csv[1][1]==='utm'&&array_slice($header,-8)===array('coverage_previous_orders_base_count','coverage_previous_orders_matched_count','coverage_previous_orders_value','coverage_previous_orders_missing','coverage_previous_orders_empty','coverage_previous_orders_unknown','coverage_previous_orders_unavailable','coverage_previous_orders_unobserved'));
    ob_start();$served=Csv082::serve(false,$csvResponse,new WP_REST_Request(array(),'GET'),null);$bytes=ob_get_clean();$check082('native raw transport includes UTF8BOM and actual rows',$served===true&&str_starts_with($bytes,"\xEF\xBB\xBF")&&substr_count($bytes,"\n")===1003);
    ob_start();$head=Csv082::serve(false,$csvResponse,new WP_REST_Request(array(),'HEAD'),null);$headBytes=ob_get_clean();$check082('HEAD body empty',$head===true&&$headBytes==='');
    ob_start();$wrongRoute=Csv082::serve(false,$csvResponse,new WP_REST_Request(array(),'GET','/unapproved'),null);$wrongBytes=ob_get_clean();$check082('raw route authority preserved',$wrongRoute===false&&$wrongBytes==='');
    ob_start();$already=Csv082::serve(true,$csvResponse,new WP_REST_Request(),null);$alreadyBytes=ob_get_clean();$check082('earlier final authority preserved',$already===true&&$alreadyBytes==='');
    $csvResponse->set_status(403);ob_start();$denied=Csv082::serve(false,$csvResponse,new WP_REST_Request(),null);$deniedBytes=ob_get_clean();$check082('final denial cannot leak CSV',$denied===false&&$deniedBytes==='');
    $check082('all test paths remain read-only',$wpdb->writes===0);
    echo 'PASS ',$passed082," dimension-details assertions, 0 FAIL\n";
}
