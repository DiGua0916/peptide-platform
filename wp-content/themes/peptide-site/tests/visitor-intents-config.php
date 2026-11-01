<?php
/** Portable actual theme enqueue/capability boundaries, no bootstrap or services. */
namespace {
	$mode060=$argv[1]??'';
	if($mode060===''){
		$total060=0;$ini060=php_ini_loaded_file();
		foreach(array('enabled','no_helper','no_clean','no_saved','wrong_version','wrong_type','admin','preview','customize','staff','options','frame','preview_arg','preview_token','templates','disabled','wrong_protocol','no_endpoint') as $case060){
			$command060=array(PHP_BINARY);if($ini060){$command060[]= '-c';$command060[]=$ini060;}$command060[]=__FILE__;$command060[]=$case060;
			$process060=proc_open($command060,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes060);if(!is_resource($process060)){throw new \RuntimeException('Cannot start config memory boundary');}fclose($pipes060[0]);$out060=stream_get_contents($pipes060[1]);$err060=stream_get_contents($pipes060[2]);fclose($pipes060[1]);fclose($pipes060[2]);$exit060=proc_close($process060);if($exit060||$err060||!preg_match('/RESULT (\d+) PASS/',$out060,$match060)){throw new \RuntimeException($case060.': '.$out060.$err060);}$total060+=(int)$match060[1];
		}echo json_encode(array('suite'=>'native-intent-config','passed'=>$total060,'failed'=>0,'modes'=>18)),"\n";exit;
	}
	define('ABSPATH',__DIR__.'/');
}
namespace PeptideCore\Tracking {
	if($GLOBALS['mode060']==='no_clean'){final class Product_Actions {public static function protocol(){return 1;}public static function saved(){}}}
	elseif($GLOBALS['mode060']==='no_saved'){final class Product_Actions {public static function protocol(){return 1;}public static function clean(){}}}
	elseif(in_array($GLOBALS['mode060'],array('wrong_version','wrong_type'),true)){final class Product_Actions {public static function protocol(){return $GLOBALS['mode060']==='wrong_type'?'1':2;}public static function clean(){}public static function saved(){}}}
	elseif($GLOBALS['mode060']!=='no_helper'){require dirname(__DIR__,3).'/plugins/peptide-core/includes/tracking/class-product-actions.php';}
}
namespace PeptideCore {final class Templates {public static function previewing_id(){return $GLOBALS['mode060']==='templates'?7:0;}}}
namespace {
	$hooks060=array();$scripts060=array();$inline060=array();$pageReads060=0;
	function add_action($name,$callback,$priority=10){$GLOBALS['hooks060'][$name]=$callback;}
	function apply_filters($name,$default){return array('enabled'=>$GLOBALS['mode060']!=='disabled','protocol'=>$GLOBALS['mode060']==='wrong_protocol'?'1':1,'endpoint'=>$GLOBALS['mode060']==='no_endpoint'?'':'https://store.example.invalid/events');}
	function is_admin(){return $GLOBALS['mode060']==='admin';}function is_preview(){return $GLOBALS['mode060']==='preview';}function is_customize_preview(){return $GLOBALS['mode060']==='customize';}function current_user_can($cap){return ($GLOBALS['mode060']==='staff'&&$cap==='manage_woocommerce')||($GLOBALS['mode060']==='options'&&$cap==='manage_options');}
	function is_wc_endpoint_url($name){return false;}function is_account_page(){return false;}function is_product(){return false;}function is_cart(){return false;}function is_checkout(){return false;}function is_front_page(){++$GLOBALS['pageReads060'];return false;}function is_shop(){return false;}function is_product_taxonomy(){return false;}function is_singular(){return false;}function is_search(){return false;}function is_archive(){return false;}
	function get_template_directory(){return dirname(__DIR__);}function get_template_directory_uri(){return 'https://store.example.invalid/theme';}function wp_script_is(...$args){return false;}function wp_enqueue_script($handle,$src,$deps,$version,$footer){$GLOBALS['scripts060'][$handle]=compact('src','deps','version','footer');}function wp_add_inline_script($handle,$code,$position){$GLOBALS['inline060'][$handle]=$code;}function esc_url_raw($v){return $v;}function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
	$_GET=array();if($mode060==='frame'){$_GET['pc_frame']='1';}if($mode060==='preview_arg'){$_GET['preview']='1';}if($mode060==='preview_token'){$_GET['pc_preview_token']='synthetic';}
	require dirname(__DIR__).'/inc/visitor-tracking.php';($hooks060['wp_enqueue_scripts'])();
	$excluded060=in_array($mode060,array('admin','preview','customize','staff','options','frame','preview_arg','preview_token','templates','disabled','wrong_protocol','no_endpoint'),true);
	$capable060=$mode060==='enabled';$expected060=!$excluded060&&$capable060;$checks060=0;
	$check060=static function($name,$yes)use(&$checks060){if(!$yes){throw new \RuntimeException($name);}++$checks060;};
	$check060('legacy observer inclusion respects all exclusions',isset($scripts060['vialcove-visitor-observer'])===!$excluded060);
	$check060('helper enqueued only for actual exact action capability',isset($scripts060['vialcove-visitor-intents'])===$expected060);
	$wire060=$inline060['vialcove-visitor-adapter']??'';$config060=$wire060?json_decode(substr($wire060,strlen('window.VialCoveVisitorConfig = '),-1),true):array();
	$check060('transport capability remains exact integer one',$expected060?($config060['product_actions_protocol']??null)===1:!isset($config060['product_actions_protocol']));
	$deps060=$scripts060['vialcove-visitor-observer']['deps']??array();$check060('helper dependency precedes observer startup',in_array('vialcove-visitor-intents',$deps060,true)===$expected060);
	$check060('excluded requests never read page or output inline identity',$excluded060?($pageReads060===0&&$inline060===array()):($pageReads060===1&&($config060['protocol']??null)===1));
	if($expected060){$asset060=$scripts060['vialcove-visitor-intents'];$check060('new asset uses actual helper/version path',$asset060['src']==='https://store.example.invalid/theme/assets/visitor-intents.js'&&$asset060['version']===(string)filemtime(dirname(__DIR__).'/assets/visitor-intents.js')&&$asset060['deps']===array());$check060('existing video dependency retained',in_array('vialcove-visitor-video',$deps060,true));}
	echo 'RESULT ',$checks060," PASS\n";
}
