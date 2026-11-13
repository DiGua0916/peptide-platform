<?php
require_once __DIR__ . "/product-actions-test-autoload.php";
// 排行里的商品行后来带上了缩略图（Realtime::product_media()，c466262）：加载真正的类，下面的假商品补上它要读的三个方法（都没有图）。
spl_autoload_register( static function ( string $class ): void { if ( 'PeptideCore\\Data\\Realtime' === $class ) { require_once dirname( __DIR__ ) . '/includes/data/class-realtime.php'; } } );
/** Actual old nineteen oracle + search twentieth + complete favorite twenty-first pipeline. */
use PeptideCore\Data\Rankings as Ranks;
require __DIR__.'/data-native-search-pure.php';
function wc_get_product($id){return new WC_Product((int)$id);}function get_edit_post_link($id,$mode){return '/edit/'.$id;}function _prime_post_caches(...$args){}class WC_Product{public function __construct(public int $id){}public function get_name(){return $this->id===100?'=Formula':'Product '.$this->id;}public function get_image_id(){return 0;}public function get_parent_id(){return 0;}public function get_sku(){return '';}}
class FavoriteRankDB056{
 public string $last_error='';public string $posts='test_posts';public array $actions=array();public function __construct(public object $legacy){}
 public function prepare(...$args){return $this->legacy->prepare(...$args);}public function get_row(...$args){return $this->legacy->get_row(...$args);}public function get_col($sql){return array_column($this->actions,'product_id');}
 public function get_results($sql,$mode){if(!str_contains($sql,"e.type IN ('product_favorite','product_share','product_intent')")){return $this->legacy->get_results($sql,$mode);}preg_match('/e.id>([0-9]+).*e.occurred_ms>=([0-9]+) AND e.occurred_ms<([0-9]+)/',$sql,$m);return array_slice(array_values(array_filter($this->actions,static fn($r)=>$r['id']>(int)$m[1]&&$r['occurred_ms']>=(int)$m[2]&&$r['occurred_ms']<(int)$m[3])),0,500);}
}
$before056=Ranks::summary($range);$wpdb=new FavoriteRankDB056($wpdb);
$add056=static function($id,$pid,$at=110000)use($wpdb){$d=array('version'=>1,'product_id'=>$pid,'variation_id'=>0,'action'=>'add');$wpdb->actions[]=array('id'=>$id,'event_uid'=>'favorite-'.$id,'visitor_id'=>1,'visit_id'=>1,'matched_visitor_id'=>1,'matched_visit_id'=>1,'owner'=>1,'is_test'=>0,'visit_test'=>0,'visitor_test'=>0,'type'=>'product_favorite','source'=>'client','occurred_ms'=>$at,'path'=>'/shop/','product_id'=>$pid,'variation_id'=>0,'data'=>json_encode($d));};
for($i=100;$i<=160;++$i){$add056($i,$i);}$add056(999,999,51000);$add056(1000,100,51000);
$n=0;$check=static function($label,$ok)use(&$n){if(!$ok){throw new RuntimeException($label);}++$n;};
$args=array('table'=>'products_favorited','q'=>'','page'=>1,'per_page'=>100,'format'=>'json');$full=iterator_to_array(Ranks::all_rows($range,$args));$list=Ranks::listing($range,$args);$summary=Ranks::summary($range);
$oldkeys=array_slice(Ranks::TABLES,0,20);$check('old20 exact numerical labels order coverage remain unchanged',json_encode(array_intersect_key($before056['tables'],array_flip($oldkeys)))===json_encode(array_intersect_key($summary['tables'],array_flip($oldkeys))));
$check('favorite21 complete beyond50 and summary projection',count(Ranks::TABLES)===21&&Ranks::TABLES[20]==='products_favorited'&&count($full)===62&&$list['rows']===$full&&$summary['tables']['products_favorited']['rows']===array_slice($full,0,50));
$previous=array_values(array_filter($full,static fn($r)=>$r['key']==='999'))[0];$check('previous only current remains null no false minus100',$previous['current']===null&&$previous['previous']===1&&$previous['change']===null);
$check('favorite own coverage only partial',$list['coverage']['current']['received_operations']===61&&$list['coverage']['current']['full_coverage']===false&&!isset($list['coverage']['current']['base_count']));
$args['q']=$full[50]['label'];$found=Ranks::listing($range,$args);$check('native label search beyond summary50',in_array($full[50]['key'],array_column($found['rows'],'key'),true));
$args['q']='';$args['page']=2;$args['per_page']=20;$page=Ranks::listing($range,$args);$check('favorite full pagination shares ordering',$page['rows']===array_slice($full,20,20));
$csv=iterator_to_array($csv_method->invoke(null,$range,$args));$check('actual13col CSV complete not page50',count($csv)===63&&count($csv[0])===13&&array_column(array_slice($csv,1),1)===array_column($full,'key'));
$check('CSV native product label formula neutralized',in_array("'=Formula",array_column($csv,2),true));
$wpdb->actions=array();$empty=Ranks::listing($range,$args);$check('empty favorite table explicitly not_observed',$empty['rows']===array()&&$empty['coverage']['current']['availability']==='not_observed');
echo 'PASS ',$n," favorite full ranking assertions, 0 FAIL\n";
