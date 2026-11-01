<?php
require_once __DIR__ . "/product-actions-test-autoload.php";
/** Actual reducers, Products and CSV. Existing051 assertions retain the old numerical oracle. */
require __DIR__ . '/data-product-user-ratios.php';
use PeptideCore\Data\Product_Actions as Actions056;
use PeptideCore\Data\Products as Products056;
use PeptideCore\Data\Report_Filter as Filter051;
$n056=0;$check056=static function($name,$ok)use(&$n056){if(!$ok){throw new RuntimeException($name);}++$n056;};
class ActionsDB056 extends Exposure_DB {
 public array $actions=array();public array $sizes=array();public bool $fail=false;
 public function get_results($sql,$mode){
  if(str_contains($sql,"e.type IN ('product_favorite','product_share','product_intent')")){
   $this->last_error=$this->fail?'synthetic action SQL failure':'';if($this->fail){return null;}
   preg_match('/e.id>([0-9]+)/',$sql,$m);$cursor=(int)($m[1]??0);$out=array_values(array_filter($this->actions,static fn($r)=>$r['id']>$cursor));usort($out,static fn($a,$b)=>$a['id']<=>$b['id']);$slice=array_slice($out,0,500);$this->sizes[]=count($slice);return $slice;
  }
  return parent::get_results($sql,$mode);
 }
}
$base056=$wpdb;$wpdb=new ActionsDB056();$wpdb->events=$base056->events;$wpdb->visits=$base056->visits;$wpdb->visitor_test=$base056->visitor_test;
$row056=static function($id,$type='product_favorite',$pid=121,$visitor=1,$variation=0,$at=110000){
 $data=array('version'=>1,'product_id'=>$pid,'variation_id'=>$variation);
 $data+= $type==='product_favorite'?array('action'=>'add'):($type==='product_share'?array('method'=>'copy_link','result'=>'completed'):array('kind'=>'quick_view_open','result'=>'completed'));
 return array('id'=>$id,'event_uid'=>'action-'.str_pad((string)$id,5,'0',STR_PAD_LEFT),'visitor_id'=>$visitor,'visit_id'=>$visitor,'matched_visitor_id'=>$visitor,'matched_visit_id'=>$visitor,'owner'=>$visitor,'is_test'=>0,'visit_test'=>0,'visitor_test'=>0,'type'=>$type,'source'=>'client','occurred_ms'=>$at,'path'=>'/actual/','product_id'=>$pid,'variation_id'=>$variation,'data'=>json_encode($data));
};
for($i=1;$i<=1001;++$i){$wpdb->actions[]=$row056($i);}
$r056=Actions056::period(100,200);$check056('500/500/1 exhausts 1001 original rows',$r056['products'][121]['favorite_count']===1001&&$wpdb->sizes===array(500,500,1));
$check056('one kind does not invent other zeroes',$r056['products'][121]['share_count']===null&&$r056['products'][121]['intent_users']===null&&$r056['product_coverage'][121]['share']['availability']==='not_observed');
$check056('availability never claims complete coverage',$r056['product_coverage'][121]['favorite']['availability']==='observed'&&$r056['coverage']['full_coverage']===false&&$r056['coverage']['coverage']==='partial');
$wpdb->actions=array($row056(1,'product_share',121,1,555),$row056(2,'product_share',121,1,556),$row056(3,'product_share',121,2,557),$row056(4,'product_intent',122,1));
$r056=Actions056::period(100,200);$check056('parent variation raw user union',$r056['products'][121]['share_count']===3&&$r056['products'][121]['share_users']===2);
$check056('independent product and kind nullable',$r056['products'][121]['intent_count']===null&&$r056['products'][122]['share_users']===null);
$invalid056=array();foreach(array('source'=>'server','is_test'=>1,'visit_test'=>1,'visitor_test'=>1,'owner'=>2,'matched_visit_id'=>null,'event_uid'=>'bad','product_id'=>999,'occurred_ms'=>200000)as$k=>$v){$x=$row056(10+count($invalid056));$x[$k]=$v;$invalid056[]=$x;}
$x=$row056(90);$d=json_decode($x['data'],true);$d['private']='discard';$x['data']=json_encode($d);$invalid056[]=$x;
$wpdb->actions=$invalid056;$r056=Actions056::period(100,200);$check056('source test owner orphan UID column cutoff and noncanonical excluded',$r056['products']===array()&&$r056['coverage']['availability']==='not_observed');
$wpdb->actions=array($row056(1,'product_share',121,1),$row056(2,'product_share',121,2));
$filter056=Filter051::params(array('entry_domain_state'=>'value','entry_domain'=>'a.test','country_state'=>'value','country'=>'US'));
$r056=Actions056::period(100,200,new Filter051($filter056,200000));$check056('real domain AND country owner cutoff excludes visitor2',$r056['products'][121]['share_count']===1&&$r056['products'][121]['share_users']===1);
$wpdb->actions=array($row056(1,'product_favorite',121,1),$row056(2,'product_favorite',122,1,0,60000),$row056(3,'product_share',121,1));
$table056=Products056::table($range051,$args051);
$rows056=array();foreach($table056['rows']as$row){$rows056[$row['product']['id']]=$row;}
$check056('action-only parent included and prior side remains null',isset($rows056[121],$rows056[122])&&$rows056[121]['current']['favorite_count']===1&&$rows056[121]['previous']['favorite_count']===null&&$rows056[121]['change']['favorite_count']===null&&$rows056[122]['current']['favorite_count']===null);
$check056('existing variations do not inherit parent action fields',$rows056[11]['variations'][0]['current']['favorite_count']===null);
foreach(array('asc','desc')as$direction){$sorted=Products056::table($range051,array_replace($args051,array('sort'=>'favorite_count','order'=>$direction)));$ids=array_column(array_column($sorted['rows'],'product'),'id');$check056('new count null last '.$direction,$ids[0]===121);}
$csv056=$csv051(array('favorite_count','share_count','share_users','intent_count','intent_users'),array_replace($args051,array('per_page'=>1)));$stream=fopen('php://temp','w+');fwrite($stream,$csv056);rewind($stream);$header=fgetcsv($stream);$csvrows=array();while(($r=fgetcsv($stream))!==false){$csvrows[]=$r;}
$check056('five generic count triples and complete CSV',count($header)===26&&count($csvrows)>1&&in_array('current_favorite_count',$header,true)&&in_array('change_intent_users_pct',$header,true));
$target=array_values(array_filter($csvrows,static fn($r)=>$r[1]==='121'))[0];$check056('CSV unknown period and comparison stay blank',$target[11]==='1'&&$target[12]===''&&$target[13]==='');
$wpdb->fail=true;$caught=false;try{Actions056::period(100,200);}catch(RuntimeException $e){$caught=true;}$check056('SQL errors are unavailable not empty zero',$caught);$wpdb->fail=false;
\PeptideCore\Tracking\Schema::$ready=false;$r056=Actions056::period(100,200);$check056('missing storage explicitly unavailable',$r056['coverage']['availability']==='unavailable'&&$r056['products']===array());\PeptideCore\Tracking\Schema::$ready=true;
echo 'PASS ',$n056," product action report assertions, 0 FAIL\n";
