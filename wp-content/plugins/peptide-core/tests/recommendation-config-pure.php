<?php
/** Direct PHP tests of the actual activity configuration; no WordPress or database mutations. */
define( 'ABSPATH', __DIR__ . '/' );
final class WP_Error {
	public function __construct( public string $code, public string $message, public array $data ) {}
	public function get_error_data(): array { return $this->data; }
}
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
require dirname( __DIR__ ) . '/includes/activity/class-config.php';
use PeptideCore\Activity\Config;

$checks=array();
$check=static function(string $name,bool $pass) use (&$checks):void { $checks[]=array('name'=>$name,'pass'=>$pass); };
$bad=static function($result,string $field):bool { return is_wp_error($result)&&isset($result->get_error_data()['fields'][$field]); };
$defaults=Config::defaults(); $rec=Config::recommendation_defaults();
$check('recommendation_defaults_are_disabled_and_bounded',$rec===array('enabled'=>false,'maxGapRatio'=>0.2,'maxGapMinor'=>null,'maxGapQuantity'=>3,'maxAddBoxes'=>3,'maxVisible'=>1,'ranking'=>'most_viewed_then_recent'));
$check('top_level_and_nested_exact_keys',array_keys($defaults)===Config::KEYS&&array_keys($rec)===Config::RECOMMENDATION_KEYS);
$check('complete_defaults_normalize',Config::normalize($defaults)===$defaults);
$legacy=$defaults;unset($legacy['recommendation']);
$check('legacy_six_fields_normalize_with_disabled_recommendation',Config::normalize($legacy)===$defaults&&6===count($legacy));
$old_enabled=array_replace($legacy,array('enabled'=>true,'promotionId'=>123));$old_normalized=Config::normalize($old_enabled);
$check('legacy_enabled_listener_never_enables_recommendation',$old_normalized['enabled']===true&&$old_normalized['promotionId']===123&&$old_normalized['recommendation']['enabled']===false);
$check('legacy_partial_defaults_remain_supported',Config::normalize(array())===$defaults&&Config::normalize(array('title'=>'Draft'))['recommendation']===$rec);
$check('unrelated_listener_fields_preserved',Config::normalize(array_replace($legacy,array('title'=>'  Offer   progress ','smileEnabled'=>false)))['title']==='Offer progress');
foreach(Config::RECOMMENDATION_KEYS as $key) { $missing=$rec;unset($missing[$key]);$check('supplied_nested_requires_'.$key,$bad(Config::normalize(array('recommendation'=>$missing)),'recommendation.'.$key)); }
foreach(array(null,false,true,'x',0,array(),array('x')) as $i=>$wrong) $check('nonobject_recommendation_rejected_'.$i,$bad(Config::normalize(array('recommendation'=>$wrong)),'recommendation'));
$unknown=$rec;$unknown['price']=5;$check('unknown_nested_pricing_field_rejected',$bad(Config::normalize(array('recommendation'=>$unknown)),'recommendation.price'));
foreach(array(0,1,'true',null,array()) as $i=>$wrong) $check('nonboolean_enabled_rejected_'.$i,$bad(Config::normalize(array('recommendation'=>array_replace($rec,array('enabled'=>$wrong)))),'recommendation.enabled'));
foreach(array(0,0.2,1,0.123456789) as $i=>$ratio) { $result=Config::normalize(array('recommendation'=>array_replace($rec,array('maxGapRatio'=>$ratio))));$check('finite_ratio_boundary_accepted_'.$i,!is_wp_error($result)&&$result['recommendation']['maxGapRatio']===(float)$ratio); }
foreach(array(-0.01,1.01,'0.2',true,null,NAN,INF,-INF,array()) as $i=>$ratio) $check('invalid_ratio_rejected_'.$i,$bad(Config::normalize(array('recommendation'=>array_replace($rec,array('maxGapRatio'=>$ratio)))),'recommendation.maxGapRatio'));
foreach(array(null,'0','1','100','9007199254740991') as $i=>$minor) { $result=Config::normalize(array('recommendation'=>array_replace($rec,array('maxGapMinor'=>$minor))));$check('nullable_exact_minor_accepted_'.$i,!is_wp_error($result)&&$result['recommendation']['maxGapMinor']===$minor); }
foreach(array('','00','01','+1','-1','1.0','1e3',' 1 ','9007199254740992','9999999999999999','10000000000000000',100,100.0,false,array()) as $i=>$minor) $check('invalid_or_unsafe_minor_rejected_'.$i,$bad(Config::normalize(array('recommendation'=>array_replace($rec,array('maxGapMinor'=>$minor)))),'recommendation.maxGapMinor'));
foreach(array('maxGapQuantity'=>10000,'maxAddBoxes'=>100,'maxVisible'=>3) as $key=>$max) {
	foreach(array(1,$max) as $value) { $result=Config::normalize(array('recommendation'=>array_replace($rec,array($key=>$value))));$check('quantity_boundary_'.$key.'_'.$value,!is_wp_error($result)&&$result['recommendation'][$key]===$value); }
	foreach(array(0,$max+1,1.0,1.1,'1',true,null) as $i=>$value) $check('invalid_integer_'.$key.'_'.$i,$bad(Config::normalize(array('recommendation'=>array_replace($rec,array($key=>$value)))),'recommendation.'.$key));
}
foreach(array('recent','cheapest','most_viewed',null,1,array()) as $i=>$ranking) $check('fixed_ranking_enforced_'.$i,$bad(Config::normalize(array('recommendation'=>array_replace($rec,array('ranking'=>$ranking)))),'recommendation.ranking'));
$enabled=array_replace($rec,array('enabled'=>true,'maxGapMinor'=>'9007199254740991'));$result=Config::normalize(array_replace($defaults,array('recommendation'=>$enabled)));
$check('explicit_complete_enabled_config_accepted',!is_wp_error($result)&&$result['recommendation']===$enabled);
$off_bad=array_replace($rec,array('enabled'=>false,'maxAddBoxes'=>101));$check('disabled_recommendation_still_validates_fields',$bad(Config::normalize(array('recommendation'=>$off_bad)),'recommendation.maxAddBoxes'));
$passed=count(array_filter($checks,static fn($check)=>$check['pass']));
echo json_encode(array('kind'=>'pure actual Activity Config validation; no database','passed'=>$passed,'total'=>count($checks),'checks'=>$checks),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($passed===count($checks)?0:1);
