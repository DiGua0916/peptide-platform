<?php
/** Adapter boundaries only; native draft/nonces remain owned by peptide-core. */
namespace PeptideCore {
    class Templates {
        public const CAP_EDIT='edit_pc_layouts',LAYOUT='pc_layout',DOC_META='_pc_document',PREVIEW_PARAM='pc_template_preview',NONCE_PARAM='pc_preview_nonce',FRAME_PARAM='pc_frame';
        public static function exists($id) { return 123===$id; }
        public static function document_id($template,$document,$create=false) { return 123===$template && 'checkout'===$document ? 42 : 0; }
        public static function previewing_id() { return $GLOBALS['preview_template']; }
    }
    class Design_Pages { public const PREVIEW_DOC='pc_design_document'; public static function preview_url($template,$doc) { return ($GLOBALS['management']?'https://manage.store.test':'https://store.test').'/?pc_template_preview='.$template.'&pc_preview_nonce=fixtureNonce&pc_frame=1&pc_design_document='.$doc; } }
    class Management_Host { public static function enabled() { return $GLOBALS['management']; } public static function management_link($url) { return str_replace('https://store.test','https://manage.store.test',$url); } }
}
namespace PeptideCore\Modules {
    class Layout { public static function preview_draft($template,$doc) { ++$GLOBALS['draft_reads']; return $GLOBALS['accepted_draft']; } public static function modules_from_content($content) { return json_decode($content,true)?:[]; } }
    class Renderer { public static function normalize($type,$attrs) { return ['type'=>$type,'settings'=>$attrs['settings']??[],'enabled'=>$attrs['enabled']??true,'visibility'=>['desktop'=>true,'mobile'=>true],'mobile'=>$attrs['mobile']??[]]; } }
}
namespace {
define('ABSPATH',__DIR__);
$GLOBALS['hooks']=[];function add_filter(...$args) {$GLOBALS['hooks'][]=$args;} function add_action(...$args) {$GLOBALS['hooks'][]=$args;}
function current_user_can($cap,$id=0) { return $GLOBALS['cap'] && ($id===0 || !in_array($id,$GLOBALS['forbidden'],true)); }
function get_post_type($id) {return $GLOBALS['posts'][$id]['type']??'';}
function get_post_meta($id,$key,$single=true) {return $GLOBALS['posts'][$id]['doc']??'';}
function wp_get_post_parent_id($id) {return $GLOBALS['posts'][$id]['parent']??0;}
function get_post_field($key,$id) {return $GLOBALS['posts'][$id][$key]??'';}
function wp_parse_url($url) {return parse_url($url);} function home_url($path) {return 'https://store.test'.$path;}
function wc_get_checkout_url() {return $GLOBALS['checkout'];} function wp_verify_nonce($nonce,$action) {return 'fixtureNonce'===$nonce && 'pc_template_preview_123'===$action;}
function add_query_arg($args,$url) { $parts=parse_url($url);parse_str($parts['query']??'',$query);$query=array_merge($query,$args);return $parts['scheme'].'://'.$parts['host'].($parts['path']??'/').($query?'?'.http_build_query($query):'').(isset($parts['fragment'])?'#'.$parts['fragment']:'');}
function is_admin(){return $GLOBALS['admin'];} function wp_script_is($name,$state){return $GLOBALS['script_enqueued'] && $name==='peptide-core-design-editor' && $state==='enqueued';}
function wp_add_inline_script(...$args){$GLOBALS['inline'][]=$args;} function wp_json_encode($value,$flags=0){return json_encode($value,$flags);} function get_template_directory(){return dirname(__DIR__);}
function vc_live_commerce_published_module($docs,$type) {++$GLOBALS['published_reads'];return ['document'=>'checkout','source'=>'published'];}
class WP_REST_Request {private $method;private $route;public function __construct($method,$route){$this->method=$method;$this->route=$route;}public function get_method(){return $this->method;}public function get_route(){return $this->route;}}
class WP_REST_Response {private $data;private $status;public function __construct($data,$status=200){$this->data=$data;$this->status=$status;}public function get_status(){return $this->status;}public function get_data(){return $this->data;}public function set_data($data){$this->data=$data;}}
require dirname(__DIR__).'/inc/checkout-layout-preview.php';
$passed=0;function check($condition,$label){global $passed;if(!$condition)throw new \RuntimeException($label);++$passed;}
function fixture(){
 $_GET=[];$GLOBALS['cap']=true;$GLOBALS['forbidden']=[];$GLOBALS['management']=true;$GLOBALS['checkout']='https://store.test/checkout/';$GLOBALS['preview_template']=0;$GLOBALS['accepted_draft']=null;$GLOBALS['draft_reads']=0;$GLOBALS['published_reads']=0;$GLOBALS['inline']=[];$GLOBALS['admin']=true;$GLOBALS['script_enqueued']=true;
 $GLOBALS['posts']=[42=>['type'=>'pc_layout','doc'=>'checkout','parent'=>123,'post_content'=>json_encode([['block'=>'vialcove/context-content','attrs'=>['settings'=>['checkout_subtotal_savings_enabled'=>true]]]])],43=>['type'=>'pc_layout','doc'=>'home','parent'=>123]];
}
fixture();
check(123===vc_checkout_layout_document_template(42),'authorized checkout document');
check(0===vc_checkout_layout_document_template(43),'other document rejected');
$base=\PeptideCore\Design_Pages::preview_url(123,'checkout');$token='nativeFixtureToken';
$source=$base.'&pc_preview_token='.$token.'&keep=one#details';
$url=vc_checkout_layout_preview_url(123,$source);parse_str(parse_url($url,PHP_URL_QUERY),$q);
check('https://manage.store.test/checkout/'===explode('?',$url)[0],'native management host retained');
check($token===$q['pc_preview_token']&&'fixtureNonce'===$q['pc_preview_nonce']&&'one'===$q['keep']&&str_ends_with($url,'#details'),'native token nonce query fragment kept');
check($url===vc_checkout_layout_preview_url(123,$url),'URL rewrite idempotent');
foreach(['javascript:alert(1)',str_replace('manage.store.test','foreign.test',$source),str_replace('/?','/products/?',$source),str_replace('fixtureNonce','invalid',$source),str_replace('document=checkout','document=home',$source),str_replace('preview=123','preview=124',$source),str_replace('&pc_frame=1','',$source)]as$bad){check(''===vc_checkout_layout_preview_url(123,$bad),'untrusted or wrong preview URL rejected');}
$GLOBALS['checkout']='https://foreign.test/checkout/';check(''===vc_checkout_layout_preview_url(123,$base),'checkout target cannot move to external origin');
fixture();$GLOBALS['checkout']='https://store.test/?page_id=9';$url=vc_checkout_layout_preview_url(123,$base);parse_str(parse_url($url,PHP_URL_QUERY),$q);check('9'===$q['page_id']&&'checkout'===$q['pc_design_document'],'plain native checkout query retained');
fixture();$GLOBALS['management']=false;$url=vc_checkout_layout_preview_url(123,\PeptideCore\Design_Pages::preview_url(123,'checkout'));check(str_starts_with($url,'https://store.test/checkout/?'),'ordinary store host unchanged');
fixture();$_GET=['page'=>'pc-design-editor','document'=>'42'];vc_checkout_layout_preview_editor_assets();check(1===count($GLOBALS['inline'])&&'before'===$GLOBALS['inline'][0][2]&&'peptide-core-design-editor'===$GLOBALS['inline'][0][0],'bridge appended before same native handle');
foreach([['page'=>'pc-design-editor','document'=>'43'],['page'=>'pc-design-editor','document'=>['42']],['page'=>'other','document'=>'42'],['page'=>'pc-design-editor','document'=>'-42']]as$get){fixture();$_GET=$get;vc_checkout_layout_preview_editor_assets();check([]===$GLOBALS['inline'],'unrelated admin route unchanged');}
fixture();$_GET=['page'=>'pc-design-editor','document'=>'42'];$GLOBALS['forbidden']=[42];vc_checkout_layout_preview_editor_assets();check([]===$GLOBALS['inline'],'document permission required');
fixture();$_GET=['page'=>'pc-design-editor','document'=>'42'];$GLOBALS['cap']=false;vc_checkout_layout_preview_editor_assets();check([]===$GLOBALS['inline'],'layout capability required');
fixture();$request=new WP_REST_Request('POST','/peptide/v1/layouts/42/preview');$response=new WP_REST_Response(['url'=>$source,'other'=>['keep'=>'original']]);$same=vc_checkout_layout_preview_response($response,null,$request);check($same===$response&&$source!==$same->get_data()['url']&&['keep'=>'original']===$same->get_data()['other'],'only native preview result URL changes');
foreach([new WP_REST_Request('GET','/peptide/v1/layouts/42/preview'),new WP_REST_Request('POST','/peptide/v1/layouts/42'),new WP_REST_Request('POST','/peptide/v1/layouts/43/preview'),new WP_REST_Request('POST','/peptide/v1/layouts/42/save')]as$other){$response=new WP_REST_Response(['url'=>$source]);check($source===vc_checkout_layout_preview_response($response,null,$other)->get_data()['url'],'non-preview and non-checkout responses unchanged');}
$response=new WP_REST_Response(['url'=>$source],403);check($source===vc_checkout_layout_preview_response($response,null,$request)->get_data()['url'],'error responses unchanged');
fixture();check('published'===vc_checkout_preview_module()['source']&&0===$GLOBALS['draft_reads'],'public request only reads published module');
$_GET=['pc_design_document'=>'checkout'];$GLOBALS['preview_template']=0;check('published'===vc_checkout_preview_module()['source']&&0===$GLOBALS['draft_reads'],'invalid preview never reads draft');
$GLOBALS['preview_template']=123;$_GET['pc_design_document']='home';check('published'===vc_checkout_preview_module()['source']&&0===$GLOBALS['draft_reads'],'other preview document never reads checkout draft');
$_GET['pc_design_document']='checkout';$GLOBALS['forbidden']=[42];check('published'===vc_checkout_preview_module()['source']&&0===$GLOBALS['draft_reads'],'checkout edit permission required to read draft');
$GLOBALS['forbidden']=[];$module=vc_checkout_preview_module();check(true===$module['settings']['checkout_subtotal_savings_enabled'],'accepted preview without ephemeral token uses saved layout');
$GLOBALS['accepted_draft']=['document'=>42,'modules'=>[['block'=>'vialcove/context-content','attrs'=>['enabled'=>false,'settings'=>['checkout_subtotal_savings_enabled'=>false],'mobile'=>['settings'=>['checkout_subtotal_savings_enabled'=>true]]]]]];
$module=vc_checkout_preview_module();check(false===$module['enabled']&&false===$module['settings']['checkout_subtotal_savings_enabled']&&true===$module['mobile']['settings']['checkout_subtotal_savings_enabled'],'native accepted temporary draft respects enabled and device overrides');
$GLOBALS['accepted_draft']=['document'=>42,'modules'=>[]];check(null===vc_checkout_preview_module(),'empty authorized preview does not resurrect published module');
echo "$passed checkout preview adapter checks passed\n";
}
