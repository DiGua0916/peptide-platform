<?php
/** Actual native validated-state getter, normalizer and template with memory platform boundaries. */
namespace {
	$mode062=$argv[1]??'';
	if($mode062===''){$total062=0;$ini062=php_ini_loaded_file();foreach(array('native','spy','absent') as $case062){$cmd062=array(PHP_BINARY);if($ini062){$cmd062[]='-c';$cmd062[]=$ini062;}$cmd062[]=__FILE__;$cmd062[]=$case062;$proc062=proc_open($cmd062,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes062);if(!is_resource($proc062)){throw new \RuntimeException('Cannot start memory boundary');}fclose($pipes062[0]);$out062=stream_get_contents($pipes062[1]);$err062=stream_get_contents($pipes062[2]);fclose($pipes062[1]);fclose($pipes062[2]);$exit062=proc_close($proc062);if($exit062||$err062||!preg_match('/RESULT (\d+) PASS/',$out062,$m062)){throw new \RuntimeException($case062.': '.$out062.$err062);}$total062+=(int)$m062[1];}echo json_encode(array('suite'=>'new-native-search','passed'=>$total062,'failed'=>0)),"\n";exit;}
	define('ABSPATH',__DIR__.'/');define('PEPTIDE_CORE_DIR',dirname(__DIR__,3).'/plugins/peptide-core');define('PEPTIDE_CORE_FILE',PEPTIDE_CORE_DIR.'/peptide-core.php');$cap062=$mode062!=='spy';$legacyReads062=0;$routeReads062=0;$stateReads062=0;$legacy062='';$kind062='content';$spyState062=null;
}
namespace PeptideCore\Tracking {
	if($GLOBALS['mode062']==='spy'){final class Page_Context {public static function clean(){}public static function saved(){}public static function replay(){}public static function search_protocol(){return $GLOBALS['cap062']?1:0;}}}
	else {require PEPTIDE_CORE_DIR.'/includes/tracking/class-page-context.php';}
	require PEPTIDE_CORE_DIR.'/includes/tracking/class-search-context.php';
}
namespace PeptideCore {
	if($GLOBALS['mode062']==='spy'){final class Public_Site_Search {public static function is_results_request(){++$GLOBALS['routeReads062'];return true;}public static function page_state(){++$GLOBALS['stateReads062'];return $GLOBALS['spyState062'];}}}
	elseif($GLOBALS['mode062']!=='absent'){require PEPTIDE_CORE_DIR.'/includes/class-public-site-search.php';}
}
namespace {
	class WP_Error {public function __construct(public $code,public $message='',public $data=array()){}public function get_error_message(){return $this->message;}}
	function is_wp_error($v){return $v instanceof WP_Error;}function __($v,$d=''){return $v;}function translate($v,$d=''){return $v;}function add_action(...$args){}
	function is_admin(){return false;}function is_search(){return true;}function get_search_query($escaped=true){++$GLOBALS['legacyReads062'];if($escaped){throw new \RuntimeException('Raw native value required');}return $GLOBALS['legacy062'];}
	function is_wc_endpoint_url($name){return false;}function is_account_page(){return $GLOBALS['kind062']==='account';}function is_product(){return $GLOBALS['kind062']==='product';}function is_cart(){return $GLOBALS['kind062']==='cart';}function is_checkout(){return $GLOBALS['kind062']==='checkout';}function is_front_page(){return false;}function is_shop(){return false;}function is_product_taxonomy(){return false;}function is_singular(){return false;}function is_archive(){return false;}
	function get_queried_object_id(){return 101;}function wc_get_product($id){return new class {public function get_permalink(){return home_url('/product/item/');}public function get_id(){return 101;}public function get_name(){return 'Synthetic product';}};}function wc_get_cart_url(){return home_url('/cart/');}function wc_get_checkout_url(){return home_url('/checkout/');}
	function home_url($path=''){return 'https://store.example.invalid'.$path;}function wp_parse_url($url,$part=-1){return parse_url($url,$part);}function plugins_url($path,$file=''){return 'https://store.example.invalid/plugin/'.$path;}function wp_enqueue_style(...$args){}function wp_enqueue_script(...$args){}
	function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}function esc_attr($v){return esc_html($v);}function esc_url($v){return esc_html($v);}function esc_html_e($v,$d=''){echo esc_html($v);}function esc_attr_e($v,$d=''){echo esc_attr($v);}function wp_kses($v,$rules){return $v;}function get_header(){echo '<!-- header -->';}function get_footer(){echo '<!-- footer -->';}function add_query_arg($args,$url){return $url.'?'.http_build_query($args);}
	$_GET=array('pc_site_search'=>'1','pc_site_q'=>'RAW-MUST-NOT-BE-READ');$_SERVER=array();require dirname(__DIR__).'/inc/visitor-tracking.php';
	$passed062=0;$check062=static function($label,$yes)use(&$passed062){if(!$yes){throw new \RuntimeException($label);}++$passed062;};
	if($mode062==='spy'){
		$spyState062=array('q'=>'must-not-read');$check062('capability off omits native and legacy query reads',vc_visitor_search_observation()===null&&$legacyReads062===0&&$routeReads062===0&&$stateReads062===0);$cap062=true;
		foreach(array('account','cart','checkout','product') as $kind062){$observation062=vc_visitor_page_observation();$check062('private/product policy no query read '.$kind062,!isset($observation062['context']['search'])&&$legacyReads062===0&&$routeReads062===0&&$stateReads062===0);}
	}elseif($mode062==='absent'){
		$legacy062=" Legacy\u{3000}Peptide ";$value062=vc_visitor_search_observation();$check062('absent native class preserves old native query normalization',$value062===array('version'=>1,'status'=>'known','term'=>'legacy peptide')&&$legacyReads062===1);
	}else{
		$state062=new \ReflectionProperty(\PeptideCore\Public_Site_Search::class,'page_state');$state062->setValue(null,array('q'=>" Demo\u{3000}Peptide "));$value062=vc_visitor_search_observation();$check062('actual native validated q captured when ordinary query empty',$value062===array('version'=>1,'status'=>'known','term'=>'demo peptide')&&$legacyReads062===0);
		$observation062=vc_visitor_page_observation();$check062('native search PageContext shape remains canonical',$observation062['page']===array('page_type'=>'content','path'=>'/')&&$observation062['context']['kind']==='search'&&$observation062['context']['search']===$value062);
		foreach(array(null,new WP_Error('synthetic','Synthetic error'),array(),array('q'=>null),array('q'=>array()),array('q'=>42)) as $state){$state062->setValue(null,$state);$check062('malformed/unavailable native state omitted no empty/raw fallback',vc_visitor_search_observation()===null&&$legacyReads062===0);}
		foreach(array('email@example.invalid','https://private.example.invalid/','1234567',str_repeat('a',81),"private\nline") as $term){$state062->setValue(null,array('q'=>$term));$value=vc_visitor_search_observation();$check062('native PII/URL/long/numeric goes through exact original filter',$value===array('version'=>1,'status'=>'filtered')&&$legacyReads062===0);}
		$state062->setValue(null,array('q'=>"\u{3000}肽  DEMO\u{3000}"));$check062('Unicode normalization unchanged',vc_visitor_search_observation()===array('version'=>1,'status'=>'known','term'=>'肽 demo'));
		$state062->setValue(null,array('q'=>str_repeat('a',80)));$check062('80-character bound retained',vc_visitor_search_observation()['status']==='known');
		$state062->setValue(null,array('q'=>''));$check062('only explicitly validated empty string can be empty',vc_visitor_search_observation()===array('version'=>1,'status'=>'empty'));
		unset($_GET['pc_site_search']);$legacy062='Legacy Search';$check062('present native class but ordinary WP request still uses old query',vc_visitor_search_observation()===array('version'=>1,'status'=>'known','term'=>'legacy search')&&$legacyReads062===1);$_GET['pc_site_search']='1';
		$rows062=array();foreach(array(array('products',101),array('products',9007199254740991),array('products',0),array('products',-1),array('products','101'),array('products',101.0),array('products',9007199254740992),array('collections',201),array('topics',202),array('blogs',203),array('pages',204),array('promotions',205),array('coupons',206)) as [$type,$id]){$rows062[]=array('type'=>$type,'id'=>$id,'url'=>home_url('/result/'.$type.'/'),'title'=>'Synthetic '.$type,'subtitle'=>'Details','image'=>array('src'=>home_url('/image.png'),'alt'=>'Image'),'priceHtml'=>'<span class="price">10.00</span>');}
		$state062->setValue(null,array('q'=>'private@example.invalid','type'=>'products','scope'=>array('products','collections','topics'),'results'=>$rows062,'page'=>1,'nextPage'=>2,'hasMore'=>true));
		$render062=static function($template){ob_start();include $template;return ob_get_clean();};$new062=$render062(PEPTIDE_CORE_DIR.'/templates/site-search-results.php');$old062=$render062(__DIR__.'/fixtures/search-results-before-062.php');
		preg_match_all('/data-vc-observe-product="([^"]*)"/',$new062,$markers062);$check062('only exact safe native product ids get markers',$markers062[1]===array('101','9007199254740991')&&substr_count($new062,'data-vc-observe-variation="0"')===2);
		$without062=preg_replace('/ data-vc-observe-product="[0-9]+" data-vc-observe-variation="0"/','',$new062);$check062('native layout href class form labels body bytes unchanged',$without062===$old062);
		$check062('new attributes never retain raw query or names',!str_contains(implode(' ',$markers062[1]),'private')&&substr_count($new062,'class="pc-site-search__result"')===count($rows062));
	}
	echo 'RESULT ',$passed062," PASS\n";
}
