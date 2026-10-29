<?php
defined('ABSPATH') || exit;
if(!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){throw new RuntimeException('Local CLI only.');}
use PeptideCore\Modules\Records;
use PeptideCore\Modules\Fields;
use PeptideCore\Modules\Commerce_Search;
$old=get_current_user_id();$get=$_GET;$request=$_REQUEST;$ids=array();$users=array();$pass=0;$fail=0;
$ok=static function($label,$yes)use(&$pass,&$fail){$yes?++$pass:++$fail;echo($yes?'PASS ':'FAIL ').$label."\n";};
$admin=(int)(get_users(array('role'=>'administrator','number'=>1,'fields'=>'ID'))[0]??0);$tag='pc-records-'.substr(wp_generate_uuid4(),0,10);$mail=static fn()=>true;add_filter('pre_wp_mail',$mail);
$snapshot=static function(){ $rows=array();foreach(array(54,55)as$id){$o=wc_get_order($id);$rows[$id]=$o?$o->get_data():null;}foreach(get_posts(array('post_type'=>'pc_layout','post_status'=>'any','numberposts'=>-1))as$p){$meta=array_filter(get_post_meta($p->ID),static fn($k)=>str_starts_with((string)$k,'_pc_'),ARRAY_FILTER_USE_KEY);ksort($meta);$rows['layout-'.$p->ID]=array($p->post_content,$meta);}foreach(array('timezone_string','gmt_offset','peptide_core_settings','peptide_core_tracking','peptide_core_media_queue','sticky_posts','peptide_core_active_template','peptide_core_template_domains')as$key){$rows[$key]=get_option($key,null);}ksort($rows);return hash('sha256',wp_json_encode($rows));};$before=$snapshot();
try{
 wp_set_current_user($admin);
 $field=Fields::f('record','binding','Record',null,array('resource'=>'topic'));
 $ok('record field preserves existing numeric and missing positive bindings',Fields::sanitize($field,'999999999')===999999999&&Fields::sanitize($field,123)===123);
 $invalid=array(null,'',0,'0',-5,'-5','1.5','1e3','bad',true,array(1));$ok('invalid record IDs are rejected without arbitrary casting',!array_filter($invalid,static fn($v)=>null!==Fields::sanitize($field,$v)));
 for($i=1;$i<=22;$i++){$id=wp_insert_post(array('post_type'=>'pc_topic','post_status'=>'publish','post_title'=>$tag.' '.sprintf('%02d',$i),'post_author'=>$admin));$ids[]=$id;}
 $first=Records::search('topic',$tag,1);$second=Records::search('topic',$tag,2);
 $ok('record lookup uses twenty-item pages and exposes the remaining page',count($first['options'])===20&&$first['more']&&count($second['options'])===2&&!$second['more']);
 $ok('record pages preserve stable order without duplicates',array_map('intval',array_column(array_merge($first['options'],$second['options']),'value'))===$ids);
 $selected=Records::search('topic',$tag,1,$ids[21]);$ok('selected record outside the current page is hydrated and retained',in_array((string)$ids[21],array_column($selected['options'],'value'),true));
 wp_update_post(array('ID'=>$ids[21],'post_status'=>'draft'));$selected=Records::search('topic',$tag,1,$ids[21]);$ok('inactive draft binding keeps its ID and state label',in_array((string)$ids[21],array_column($selected['options'],'value'),true)&&str_contains($selected['options'][0]['label'],'草稿'));
 wp_delete_post($ids[21],true);$selected=Records::search('topic',$tag,1,$ids[21]);$ok('deleted binding is visibly unavailable and never silently cleared',$selected['options'][0]['value']===(string)$ids[21]&&!empty($selected['options'][0]['unavailable']));
 $wrong=Records::search('post',$tag,1,$ids[0]);$ok('wrong resource binding never exposes another record title',!empty($wrong['options'][0]['unavailable'])&&!str_contains($wrong['options'][0]['label'],$tag));
 $ok('unsupported record resources return no data',Records::search('users',$tag)===array('options'=>array(),'more'=>false));
 wp_set_current_user(0);$ok('anonymous record lookup returns no editor records',Records::search('topic',$tag)===array('options'=>array(),'more'=>false));
 foreach(array('design','marketing','data')as$module){$u=wp_insert_user(array('user_login'=>$tag.'-'.$module,'user_pass'=>wp_generate_password(30),'role'=>PeptideCore\Staff::ROLE));if(is_wp_error($u)){throw new RuntimeException('Fixture employee failed.');}$users[]=$u;PeptideCore\Staff::set_modules($u,array($module));wp_set_current_user($u);$result=Records::search('topic',$tag);$ok($module.' employee record access matches design permission',('design'===$module)===!empty($result['options']));}
 wp_set_current_user($admin);
 $private_product=new WC_Product_Simple();$private_product->set_name('AAA SYNTHETIC PRIVATE '.$tag);$private_product->set_status('private');$private_product->set_regular_price('1');$private_id=$private_product->save();$ids[]=$private_id;
 wp_set_current_user($users[0]);$lookup=Records::search('product',$tag,1,$private_id);
 $ok('design-only product lookup masks private product titles',!empty($lookup['options'][0]['unavailable'])&&!str_contains(wp_json_encode($lookup),'AAA SYNTHETIC PRIVATE'));
 $editor_data=PeptideCore\Modules\Blocks::editor_data();$ok('legacy inline editor product data excludes private product titles',!str_contains(wp_json_encode($editor_data['products']),'AAA SYNTHETIC PRIVATE'));
 wp_set_current_user($admin);
 $discount=array_replace(PeptideCore\Shipping\Discounts::defaults(),array('id'=>987654321,'name'=>'SYNTHETIC '.$tag,'enabled'=>false,'emails'=>array('hidden@example.invalid')));
 $private_discount=array_replace($discount,array('id'=>987654322,'name'=>'SYNTHETIC PRIVATE '.$tag,'customers'=>'emails'));
 $mock=static fn()=>array($discount,$private_discount);add_filter('pre_option_'.PeptideCore\Shipping\Discounts::OPTION,$mock);
 try{
  $shipping=Records::search('shipping_discount',$tag);
  $ok('shipping lookup reveals metadata and disabled status without recipient lists',count($shipping['options'])===2&&str_contains(implode(' ',array_column($shipping['options'],'label')),'已停用')&&!str_contains(wp_json_encode($shipping),'hidden@example.invalid'));
  wp_set_current_user($users[0]);$shipping=Records::search('shipping_discount',$tag);
  $ok('design-only employee cannot search private shipping activity titles',count($shipping['options'])===1&&!str_contains(wp_json_encode($shipping),'SYNTHETIC PRIVATE'));
  $shipping=Records::search('shipping_discount',$tag,1,987654322);
  $ok('private shipping binding remains unavailable without revealing its title',!empty($shipping['options'][0]['unavailable'])&&!str_contains(wp_json_encode($shipping),'SYNTHETIC PRIVATE'));
 }finally{wp_set_current_user($admin);remove_filter('pre_option_'.PeptideCore\Shipping\Discounts::OPTION,$mock);}
 $_GET=array('post_type'=>'product','min_price'=>'','max_price'=>'   ');$_REQUEST=$_GET;Commerce_Search::clean_prices();$ok('blank product price bounds are absent before Woo queries',!isset($_GET['min_price'],$_GET['max_price'])&&!isset($_REQUEST['min_price'],$_REQUEST['max_price']));
 $_GET=array('post_type'=>'product','min_price'=>'0','max_price'=>'0');$_REQUEST=$_GET;Commerce_Search::clean_prices();$ok('explicit zero bounds remain valid price filters','0'===$_GET['min_price']&&'0'===$_GET['max_price']);
 $_GET=array('post_type'=>'post','max_price'=>'');$_REQUEST=$_GET;Commerce_Search::clean_prices();$ok('unrelated content searches are unchanged',array_key_exists('max_price',$_GET));
 $ok('original orders layouts and selected settings stay unchanged',$snapshot()===$before);
}finally{wp_set_current_user($admin);foreach($ids as$id){if(get_post($id)){wp_delete_post($id,true);}}require_once ABSPATH.'wp-admin/includes/user.php';foreach($users as$id){wp_delete_user($id);}$_GET=$get;$_REQUEST=$request;wp_set_current_user($old);remove_filter('pre_wp_mail',$mail);}
$ok('all lookup fixtures are removed and protected fingerprints match',!array_filter($ids,'get_post')&&!array_filter($users,'get_userdata')&&$snapshot()===$before);
echo "Result: $pass passed, $fail failed\n";if($fail){throw new RuntimeException('Record selector regressions failed');}
