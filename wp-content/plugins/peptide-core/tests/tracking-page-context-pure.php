<?php
/** Standalone real cleaner, without WordPress, database, network or mocked normalization. */
define( 'ABSPATH', __DIR__ . '/' );
require_once dirname( __DIR__ ) . '/includes/tracking/class-page-context.php';
use PeptideCore\Tracking\Page_Context;
$pass=0;$check=static function(string$name,bool$ok)use(&$pass):void{if(!$ok){fwrite(STDERR,'FAIL '.$name."\n");exit(1);}++$pass;echo 'PASS ',$name,"\n";};
$wire=array('version'=>1,'kind'=>'collection','term_id'=>123,'geo'=>array('status'=>'known','country_code'=>'de','private'=>'discard'),'private'=>'discard');
$r=Page_Context::clean($wire);$expected=array('version'=>1,'kind'=>'collection','term_id'=>123,'geo'=>array('status'=>'known','country_code'=>'DE'));
$check('atomic canonical order/country uppercase/extra removal',$r===array('ok'=>true,'value'=>$expected));$check('input remains unchanged',$wire['geo']['country_code']==='de'&&isset($wire['private']));
foreach(Page_Context::KINDS as$kind){$v=array('version'=>1,'kind'=>$kind);if($kind==='collection'){$v['term_id']=123;}$check('kind '.$kind,Page_Context::clean($v)===array('ok'=>true,'value'=>$v));}
foreach(array(null,false,true,1,'',array())as$i=>$bad){$check('metadata container '.$i,Page_Context::clean($bad)===array('ok'=>false,'code'=>'bad_page_context'));}
foreach(array(null,'1',1.0,true,false,array())as$i=>$bad){$v=$wire;$v['version']=$bad;$check('strict version type '.$i,Page_Context::clean($v)===array('ok'=>false,'code'=>'bad_page_context'));}
foreach(array(-1,0,2)as$version){$v=$wire;$v['version']=$version;$check('unsupported integer version '.$version,Page_Context::clean($v)===array('ok'=>false,'code'=>'unsupported_version'));}
foreach(array(null,true,1,'Collection','other','')as$i=>$kind){$v=$wire;$v['kind']=$kind;$check('invalid kind '.$i,!Page_Context::clean($v)['ok']);}
foreach(array(null,false,true,1.0,'123',0,-1,array())as$i=>$term){$v=$wire;$v['term_id']=$term;$check('strict collection term '.$i,!Page_Context::clean($v)['ok']);}
$v=$wire;unset($v['term_id']);$check('collection requires term',!Page_Context::clean($v)['ok']);
foreach(array(null,0,1)as$i=>$term){$v=array('version'=>1,'kind'=>'shop','term_id'=>$term);$check('other kind forbids even null term '.$i,!Page_Context::clean($v)['ok']);}
foreach(array(null,true,false,1,'',array(),array('status'=>null),array('status'=>'unobserved'),array('status'=>'KNOWN'))as$i=>$geo){$v=$wire;$v['geo']=$geo;$check('invalid geo container/status '.$i,!Page_Context::clean($v)['ok']);}
foreach(array('unknown','unavailable')as$status){$v=array('version'=>1,'kind'=>'content','geo'=>array('status'=>$status,'extra'=>'discard'));$check('explicit state '.$status,Page_Context::clean($v)['value']===array('version'=>1,'kind'=>'content','geo'=>array('status'=>$status)));$v['geo']['country_code']=null;$check('nonknown forbids null code '.$status,!Page_Context::clean($v)['ok']);}
foreach(array(null,false,true,12,'','D','DEU',"DE\n","DE\r\n",' DE','DE ','中D','ＤＥ',array())as$i=>$code){$v=$wire;$v['geo']['country_code']=$code;$check('absolute ASCII code boundaries '.$i,!Page_Context::clean($v)['ok']);}
$v=$wire;unset($v['geo']['country_code']);$check('known requires code',!Page_Context::clean($v)['ok']);
foreach(array('de'=>'DE','us'=>'US','aQ'=>'AQ','ZZ'=>'ZZ')as$code=>$want){$v=$wire;$v['geo']['country_code']=$code;$check('syntax-only canonical code '.$code,Page_Context::clean($v)['value']['geo']['country_code']===$want);}
$v=array('version'=>1,'kind'=>'content');$check('geo absence remains absent',!array_key_exists('geo',Page_Context::clean($v)['value']));
$check('saved syntax does not consult deleted term/country catalog',Page_Context::saved($expected)===$expected);
$check('malformed saved context not exposed',Page_Context::saved(array('version'=>1,'kind'=>'content','geo'=>null))===null);
$check('receipt has only safe stable fields',Page_Context::receipt('','rejected','core_rejected')===array('event_id'=>'','status'=>'rejected','code'=>'core_rejected','retry'=>false));
foreach(array("synthetic-id\n",str_repeat('x',65),'<unsafe>')as$i=>$id){$check('extension receipt never echoes unsafe identifier '.$i,Page_Context::receipt($id,'rejected','core_rejected')['event_id']==='');}
echo 'TOTAL ',$pass," PASS, 0 FAIL\n";
