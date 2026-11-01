<?php
/** Pure attribution boundaries; no WordPress bootstrap, file writes, DB or network. */
declare(strict_types=1);
$root057 = dirname(__DIR__,4);
if ( ($argv[1] ?? '') === '--case' ) {
	$case057 = $argv[2] ?? ''; define('ABSPATH',__DIR__.'/');
	$hooks057=array();$escape057=array('html'=>0,'url'=>0);
	function __( $s,$domain='' ){return $s;}
	function esc_html( $s ){++$GLOBALS['escape057']['html'];return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
	function esc_html__( $s,$domain='' ){return esc_html($s);}
	function esc_attr( $s ){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
	function esc_url( $s ){++$GLOBALS['escape057']['url'];return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
	function current_user_can( $cap ){return true;}
	function wp_unslash( $v ){return $v;}
	function sanitize_key( $v ){return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v));}
	function add_action( $name,$callback,...$args ){$GLOBALS['hooks057'][$name][]=$callback;}
	function get_option( $key,$default=false ){return $default;}
	function wp_upload_dir( ...$args ){return array('basedir'=>__DIR__,'error'=>false);}
	if($case057!=='no_provider'){
		if($case057!=='absent'&&$case057!=='custom_maxmind')define('PC_DATA_GEOIP_ATTRIBUTION',match($case057){'wrong_enum'=>'DB-IP','malicious_enum'=>'<script>bad</script>',default=>'db-ip'});
		if($case057==='custom_maxmind')define('PC_DATA_GEOIP_ATTRIBUTION','maxmind');
		if($case057!=='custom_maxmind')define('PC_DATA_GEOIP_DATABASE',match($case057){'empty_database'=>'','missing_file'=>__DIR__.'/missing-country-resource.mmdb','stream_database'=>'https://example.invalid/geo.mmdb',default=>__FILE__});
		require $root057.'/wp-content/plugins/peptide-core/includes/tracking/class-country-provider.php';
		$credit057=PeptideCore\Tracking\Country_Provider::attribution();
		ob_start();PeptideCore\Tracking\Country_Provider::render_attribution();$direct057=ob_get_clean();
		$legacy057=PeptideCore\Tracking\Country_Provider::lookup('127.0.0.1');
	}else{$credit057=null;$direct057='';$legacy057=null;}
	$_GET['page']='pc-data-products';
	require $root057.'/wp-content/plugins/peptide-core/includes/admin/class-data-pages.php';
	ob_start();PeptideCore\Admin\Data_Pages::render();$admin057=ob_get_clean();
	require $root057.'/wp-content/themes/peptide-site/inc/visitor-tracking.php';
	ob_start();foreach($hooks057['wp_footer']??array() as $callback057){$callback057();}$footer057=ob_get_clean();
	echo json_encode(array('credit'=>$credit057,'direct'=>$direct057,'admin'=>$admin057,'footer'=>$footer057,'escape'=>$escape057,'legacy'=>$legacy057,'path_leaked'=>str_contains($direct057,__FILE__)||str_contains($admin057,__FILE__)||str_contains($footer057,__FILE__)),JSON_THROW_ON_ERROR);exit;
}
$passed057=0;
$check057=static function(string $label,bool $ok)use(&$passed057):void{if(!$ok)throw new RuntimeException($label);++$passed057;};
foreach(array('absent','wrong_enum','empty_database','missing_file','active','malicious_enum','stream_database','custom_maxmind','no_provider') as $case057){
	$process057=proc_open(array(PHP_BINARY,'-n',__FILE__,'--case',$case057),array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes057);
	if(!is_resource($process057))throw new RuntimeException('Cannot start pure PHP boundary');
	fclose($pipes057[0]);$json057=stream_get_contents($pipes057[1]);fclose($pipes057[1]);$err057=stream_get_contents($pipes057[2]);fclose($pipes057[2]);$exit057=proc_close($process057);
	if($exit057!==0)throw new RuntimeException('Pure child failed: '.$case057.' '.$err057);
	$r057=json_decode($json057,true,512,JSON_THROW_ON_ERROR);$active057=$case057==='active';
	$check057($case057.' exact enum and local resource gate',$active057?$r057['credit']===array('label'=>'IP Geolocation by DB-IP','url'=>'https://db-ip.com'):$r057['credit']===null);
	$check057($case057.' direct and actual frontend credit agree',$active057?($r057['direct']===$r057['footer']&&str_contains($r057['footer'],'href="https://db-ip.com"')&&substr_count($r057['footer'],'IP Geolocation by DB-IP')===1):($r057['direct']===''&&$r057['footer']===''));
	$check057($case057.' actual Data render adds credit after wrapper',$active057?(str_contains($r057['admin'],'pc-data-wrap')&&str_ends_with($r057['admin'],$r057['direct'])):!str_contains($r057['admin'],'IP Geolocation by DB-IP'));
	$check057($case057.' no configured path or injected markup leaks',!$r057['path_leaked']&&!str_contains($r057['direct'],'<script>'));
	$check057($case057.' native loopback lookup unchanged',$case057==='no_provider'||$r057['legacy']===array('status'=>'unknown'));
	if($active057)$check057('native escaping called for fixed link and label',$r057['escape']['html']>0&&$r057['escape']['url']>0);
}
echo json_encode(array('suite'=>'country-resource-attribution-pure','passed'=>$passed057,'failed'=>0,'wordpress_bootstrap'=>false,'network'=>false),JSON_THROW_ON_ERROR)."\n";
