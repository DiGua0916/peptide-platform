<?php
/** Actual054 products/export plus the private051 numeric fixture/suite; no native bootstrap/DB. */
require __DIR__.'/data-product-user-ratios.php';
use PeptideCore\Data\Products as P054;
use PeptideCore\Data\Report_Exports as E054;
$checks054=0;
$check054=static function($label,$ok)use(&$checks054){if(!$ok){throw new RuntimeException($label);}++$checks054;};
$original054=json_decode(file_get_contents(dirname(__DIR__,4).'/tests/fixtures/data-observations/054/numeric28-table.json'),true);
$old28keys054=$original054['metrics'];
$strip054=static function($value)use(&$strip054,$old28keys054){if(!is_array($value)){return $value;}$out=array();foreach($value as $key=>$item){if(in_array($key,array('columns','report_timezone','created_at','created_at_label','favorite_count','share_count','share_users','intent_count','intent_users','repeat_view_users','returning_view_users','action_coverage','action_measurement'),true)){continue;}$out[$key]=$key==='metrics'?$old28keys054:$strip054($item);}return $out;};
$check054('numeric28 entire table projection unchanged',json_encode($strip054(P054::table($range051,$args051)))===json_encode($original054));
$check054('explicit numeric28 CSV exact pre-edit052 bytes',$csv051($old28keys054)===file_get_contents(dirname(__DIR__,4).'/tests/fixtures/data-observations/054/numeric28.csv'));
$check054('numeric28 and defaultten remain unchanged',count(P054::METRICS)===35 && array_slice(P054::METRICS,0,28)===$old28keys054 && P054::DEFAULTS===$original054['defaults']);
$check054('created is separate optional ordered metadata',P054::columns()===array_merge(P054::METRICS,array('created_at')) && !in_array('created_at',P054::METRICS,true) && !in_array('created_at',P054::DEFAULTS,true));
class Dated054 extends WC_Product { public int $dateReads=0;public function __construct($id,public ?DateTimeInterface $date054){parent::__construct($id);}public function get_date_created(){++$this->dateReads;return $this->date054;} }
class DatedVariant054 extends WC_Product_Variation {public int $dateReads=0;public function __construct($id,public ?DateTimeInterface $date054){parent::__construct($id,'Variant');}public function get_date_created(){++$this->dateReads;return $this->date054;} }
$products[11]=new Dated054(11,new DateTimeImmutable('2026-05-04T01:02:03+02:00'));
$products[111]=new DatedVariant054(111,new DateTimeImmutable('2025-01-01T00:00:00Z'));
$table054=P054::table($range051,$args051);$ids054=array();foreach($table054['rows'] as $row){$ids054[$row['product']['id']]=$row;}
$check054('table explicitly reports store timezone',$table054['report_timezone']==='Asia/Shanghai');
$check054('parent native timestamp serialized UTC',$ids054[11]['product']['created_at']==='2026-05-03T23:02:03Z');
$check054('parent native timestamp displayed in store timezone',$ids054[11]['product']['created_at_label']==='2026-05-04 07:02:03');
$check054('variation uses own native creation metadata',$ids054[11]['variations'][0]['variation']['created_at']==='2025-01-01T00:00:00Z' && $ids054[11]['variations'][0]['variation']['created_at_label']==='2025-01-01 08:00:00');
$check054('undated product stays null',$ids054[12]['product']['created_at']===null && $ids054[12]['product']['created_at_label']===null);
$check054('deleted product is null not epoch0',$ids054[14]['product']['created_at']===null && $ids054[14]['product']['created_at_label']===null);
$check054('existing native product objects supply metadata once',$products[11]->dateReads===1 && $products[111]->dateReads===1);
$method054=new ReflectionMethod(E054::class,'products');
$mixed054=iterator_to_array($method054->invoke(null,$range051,array_replace($args051,array('per_page'=>1)),array('sales','created_at','cart_user_rate')));
$check054('mixed selected order has one UTC column not a triple',array_slice($mixed054[0],11)===array('current_sales','previous_sales','change_sales_pct','created_at_utc','current_cart_user_rate_pct','previous_cart_user_rate_pct','change_cart_user_rate_pp'));
$check054('mixed CSV complete iterator beyond visible page',count($mixed054)>count(P054::table($range051,array_replace($args051,array('per_page'=>1)))['rows'])+1);
$parent054=null;$variation054=null;$missing054=null;foreach(array_slice($mixed054,1) as $row){if($row[0]==='product'&&$row[1]==='11'){$parent054=$row;}if($row[0]==='variation'&&$row[2]==='111'){$variation054=$row;}if($row[0]==='product'&&$row[1]==='14'){$missing054=$row;}$check054('CSV row/header alignment',count($row)===count($mixed054[0]));}
$check054('CSV parent UTC native value',$parent054[14]==='2026-05-03T23:02:03Z');
$check054('CSV variation UTC own value',$variation054[14]==='2025-01-01T00:00:00Z');
$check054('CSV missing metadata empty',$missing054[14]==='');
$dateonly054=iterator_to_array($method054->invoke(null,$range051,$args051,array('created_at')));$check054('date-only CSV one metadata value',count($dateonly054[0])===12 && $dateonly054[0][11]==='created_at_utc');
class WP_REST_Request {public function __construct(private array $params054){}public function get_params(){return $this->params054;}}
$bad054=E054::response(new WP_REST_Request(array('format'=>'csv','columns'=>'created_at,unknown')),'products',$range051,$args051);$check054('unknown metadata column rejects400',$bad054 instanceof WP_Error && $bad054->data['status']===400 && isset($bad054->data['fields']['columns']));
$valid054=E054::response(new WP_REST_Request(array('format'=>'csv','columns'=>'created_at,sales,created_at')),'products',$range051,$args051);$check054('valid metadata union reuses canonical CSV response',$valid054 instanceof PeptideCore\Data\Csv_Response);
$check054('creation header deliberately nonsortable rejectsdate sort',P054::params(array('sort'=>'created_at')) instanceof WP_Error);
echo 'PASS ',$checks054," product creation metadata assertions, 0 FAIL\n";
