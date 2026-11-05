<?php
/** Actual Templates bulk/negative lookup API; isolated storage, no WP writes. */
define('ABSPATH', __DIR__);
$blog=1;$queries=0;$hooks=[];$records=[(object)['ID'=>31,'doc'=>'product'],(object)['ID'=>30,'doc'=>'product']];
function get_current_blog_id(){return $GLOBALS['blog'];}
function get_post_type($id){return 'pc_template';} function get_post_status($id){return 'publish';}
function __($v,...$args){return $v;}
function apply_filters($name,$value,...$args){return $name==='peptide_core_layout_document_label'?'Product':$value;}
function get_post_meta($id,...$args){foreach($GLOBALS['records'] as $r)if($r->ID===$id)return $r->doc;return '';}
function get_posts($args){++$GLOBALS['queries'];$docs=$args['meta_query'][0]['value']??[$args['meta_value']];$rows=array_values(array_filter($GLOBALS['records'],fn($r)=>in_array($r->doc,$docs,true)));return ($args['fields']??'')==='ids'?array_slice(array_column($rows,'ID'),0,1):$rows;}
function get_post_field(...$args){return 1;} function is_wp_error($id){return false;}
function wp_insert_post($args,...$unused){$GLOBALS['records'][]=(object)['ID'=>99,'doc'=>$args['meta_input']['_pc_document']];foreach($GLOBALS['hooks']['added_post_meta']??[] as $h)$h();return 99;}
function add_action($name,$callback,...$args){$GLOBALS['hooks'][$name][]=$callback;}function add_filter(...$args){}
require dirname(__DIR__).'/includes/class-templates.php';
use PeptideCore\Templates as T;
function check($v,$message){if(!$v)throw new RuntimeException($message);}
T::register_hooks();T::prime_document_ids(7,['product','product_1','product_2']);
check($queries===1,'one bulk lookup');
check(T::document_id(7,'product',false)===31,'newest duplicate wins');
check(T::document_id(7,'product_1',false)===0 && $queries===1,'negative lookup avoids query');
T::prime_document_ids(7,['product','product_1']);check($queries===1,'repeat prime coalesces');
check(T::document_id(7,'product_1',true)===99,'negative prime does not block creation');
check(T::document_id(7,'product_1',false)===99,'meta hook invalidates negative entry');
T::prime_document_ids(7,['product_2']);$before=$queries;$blog=2;T::document_id(7,'product_2',false);check($queries===$before+1,'blog boundaries isolated');
$blog=1;$before=$queries;T::document_id(8,'product_2',false);check($queries===$before+1,'template boundaries isolated');
foreach($hooks['deleted_post'] as $h)$h();$before=$queries;T::document_id(7,'product_2',false);check($queries===$before+1,'delete invalidates negatives');
echo "9 document prime checks passed; 3 documents use 1 query, native create remains live.\n";
