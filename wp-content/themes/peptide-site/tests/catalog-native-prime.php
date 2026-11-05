<?php
/** Native cache hydration boundary test; no business DTO/pricing replacement. */
define('ABSPATH',__DIR__);$options=[];$raw=[];$post_prime=[];$queries=0;$ext=false;$blog=1;
function absint($v){return abs((int)$v);}function get_current_blog_id(){return $GLOBALS['blog'];}function wp_using_ext_object_cache(){return $GLOBALS['ext'];}
function wp_prime_option_caches($names){$GLOBALS['options'][]=$names;}
function get_posts($args){return [11,12];}function _prime_post_caches($ids,$terms,$meta){$GLOBALS['post_prime'][]=$ids;}
function get_post_meta($id,$key,$single){return [1=>100,2=>100,11=>101,12=>0][$id]??0;}
class WC_Data {static function prime_raw_meta_data_cache($rows,$group){if($group!=='products')throw new RuntimeException('wrong group');$GLOBALS['raw']=$rows;}}
$wpdb=new class {public $postmeta='wp_postmeta';function prepare($sql,...$ids){return $sql;}function get_results($sql){++$GLOBALS['queries'];return [(object)['post_id'=>1,'meta_id'=>9,'meta_key'=>'exact_custom_price','meta_value'=>'5500'],(object)['post_id'=>11,'meta_id'=>10,'meta_key'=>'sku_report','meta_value'=>'report-11']];}};
require dirname(__DIR__).'/inc/navigation.php';
function check($v,$m){if(!$v)throw new RuntimeException($m);}
vc_prime_catalog_product_data([1,2,1,0]);
check(count($options)===1&&count($options[0])===20,'all native options bulk primed once');
check($post_prime[0]===[1,2,11,12],'parent and variation raw WP caches primed');
check($post_prime[1]===[100,101],'shared parent/exact-SKU attachments primed once, absent image excluded');
check($raw[1][0]->meta_id===9&&$raw[1][0]->meta_value==='5500'&&$raw[11][0]->meta_value==='report-11','unmodified native metadata retains exact values/IDs');
check($raw[2]===[]&&$raw[12]===[],'absent metadata represented by empty native entries');
vc_prime_catalog_product_data([1,2]);check($queries===1,'same-request repeated catalogue does not repeat SQL');
$blog=2;vc_prime_catalog_product_data([1]);check($queries===2,'blog switch primes independently');
$ext=true;vc_prime_catalog_product_data([3]);check($queries===2&&count($options)===2,'no custom persistent Woo cache populated');
echo "8 catalogue native-cache boundary checks passed.\n";
