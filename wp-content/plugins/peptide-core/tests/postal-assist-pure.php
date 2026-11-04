<?php
/** Production ZIP resolver and strict provider parser under synthetic offline dependencies. */
namespace {
define('ABSPATH',__DIR__.'/');define('PC_GOOGLE_ADDRESS_VALIDATION_KEY','synthetic-not-a-real-provider-key');
class WP_Error{public function __construct(public string $code,public string $message,public array $data=array()){} }
class WP_REST_Request{
 public function __construct(public array $json=array(),public array $headers=array(),public array $query=array()){}
 public function get_header($name){return $this->headers[$name]??'';} public function get_query_params(){return $this->query;} public function is_json_content_type(){return true;}public function get_body(){return json_encode($this->json);}public function get_json_params(){return $this->json;}public function get_route(){return '/peptide/v1/postal-assist';}
}
class WP_REST_Response{public array $headers=array();public function __construct(public $data){} public function get_headers(){return $this->headers;}public function set_headers($h){$this->headers=$h;}public function header($k,$v){$this->headers[$k]=$v;}}
class WC_Customer{public function __construct(public int $id){$GLOBALS['customer_reads'][]=$id;}public function __call($method,$args){return $GLOBALS['saved'][substr($method,4)]??'';}}
class WC_Rate_Limiter{public static bool $limited=false;public static function retried_too_soon($key){return self::$limited;}public static function set_rate_limit($key,$seconds){$GLOBALS['rate_keys'][]=$key;return true;}}
$GLOBALS['options']=array();$GLOBALS['user_id']=7;$GLOBALS['customer_reads']=array();$GLOBALS['saved']=array();$GLOBALS['nonce']=true;$GLOBALS['provider_calls']=array();$GLOBALS['rate_keys']=array();$GLOBALS['provider_result']=array();
function get_option($key,$default=null){return $GLOBALS['options'][$key]??$default;}function get_current_user_id(){return $GLOBALS['user_id'];}function is_user_logged_in(){return get_current_user_id()>0;}function wp_verify_nonce($n,$action){return $GLOBALS['nonce']&&$n==='fixture';}function wp_parse_url($url){return parse_url($url);}function home_url($path){return 'https://example.invalid'.$path;}function wp_salt($context){return 'offline-fixture-salt';}
function rest_ensure_response($data){return new WP_REST_Response($data);}function is_wp_error($x){return $x instanceof WP_Error;}function wp_json_encode($v){return json_encode($v);}
function wp_safe_remote_post($url,$args){$GLOBALS['provider_calls'][]=array('url'=>$url,'args'=>$args);return array('response'=>array('code'=>200),'body'=>json_encode($GLOBALS['provider_result']));}function wp_remote_retrieve_response_code($r){return $r['response']['code'];}function wp_remote_retrieve_body($r){return $r['body'];}
}
namespace PeptideCore { class Store_Config{public static function get($key){return false;}} class Storefront_Translation{public static bool $es=false;public static function active(){return self::$es;}public static function code(){return self::$es?'es':'en';}} }
namespace {
require dirname(__DIR__).'/includes/class-postal-assist.php';use PeptideCore\Postal_Assist as Postal;
$checks=array();$check=static function($name,$pass)use(&$checks){$checks[]=array('name'=>$name,'pass'=>(bool)$pass);};
$a=array('country'=>'US','state'=>'CA','city'=>'Example City','address_1'=>'123 Example Rd','address_2'=>'Unit 2','postcode'=>'');
$request=static fn($address,$allow=false)=>new WP_REST_Request(array('address'=>$address,'allow_external'=>$allow),array('X-WP-Nonce'=>'fixture','Origin'=>'https://example.invalid'));
$check('default disabled and no provider',Postal::settings()===array('enabled'=>false,'google_enabled'=>false,'merchant_consent'=>false)&&!Postal::google_ready());
$check('strict booleans independent option',Postal::normalize(array('enabled'=>'1','foreign'=>true))===Postal::defaults());
$en=Postal::strings();PeptideCore\Storefront_Translation::$es=true;$es=Postal::strings();
$check('all new ZIP UI and failure copy translated with exact key parity',array_keys($en)===array_keys($es)&&!array_filter($en,static fn($value,$key)=>!is_string($es[$key]??null)||''===$es[$key]||$value===$es[$key],ARRAY_FILTER_USE_BOTH));
$GLOBALS['nonce']=false;$check('actual Spanish permission error is localized',Postal::permission($request($a))->message===$es['unavailable']);$GLOBALS['nonce']=true;PeptideCore\Storefront_Translation::$es=false;
$check('complete US address accepted',Postal::address($a)===$a);
foreach(array('country'=>'CA','state'=>'PR','city'=>'','address_1'=>'Example Rd','address_2'=>'<script>')as$key=>$value){$check('reject incomplete/foreign '.$key,null===Postal::address(array_replace($a,array($key=>$value))));}
$check('caller user ID/address extras rejected',null===Postal::address($a+array('user_id'=>99)));
$pr=array_replace($a,array('country'=>'PR','state'=>'','city'=>'San Juan'));$check('PR native state optional',Postal::address($pr)===$pr);$check('PR state alias canonical without changing client',Postal::address(array_replace($pr,array('state'=>'PR')))['state']==='');
$one=array_replace($a,array('postcode'=>'90210'));$two=array_replace($a,array('postcode'=>'90211'));
$check('one matching own saved ZIP returned',Postal::saved_match($a,array($one))===array('state'=>'matched','postcode'=>'90210','source'=>'saved'));
$check('conflicting saved ZIPs ambiguous',Postal::saved_match($a,array($one,$two))===array('state'=>'ambiguous'));
$check('unit number difference not guessed',Postal::saved_match($a,array(array_replace($one,array('address_2'=>'Unit 3'))))===array('state'=>'manual'));
$check('ZIP grammar and PR prefixes strict',Postal::valid_zip('00901-1234','PR')&&!Postal::valid_zip('00801','PR')&&!Postal::valid_zip('90210','PR')&&!Postal::valid_zip('123','US'));
$check('permission verifies nonce',true===Postal::permission($request($a)));$GLOBALS['nonce']=false;$check('invalid nonce rejected',Postal::permission($request($a)) instanceof WP_Error);$GLOBALS['nonce']=true;
$cross=$request($a);$cross->headers['Origin']='https://evil.invalid';$check('foreign origin rejected',Postal::permission($cross) instanceof WP_Error);
$check('disabled resolver no saved or network read',Postal::lookup($request($a))->data===array('state'=>'disabled')&&!$GLOBALS['customer_reads']&&!$GLOBALS['provider_calls']);
$GLOBALS['options'][Postal::OPTION]=array('enabled'=>true,'google_enabled'=>false,'merchant_consent'=>false);
foreach($one as$key=>$value){$GLOBALS['saved']['shipping_'.$key]=$value;$GLOBALS['saved']['billing_'.$key]=$value;}
$check('only authenticated own customer ID read',Postal::lookup($request($a))->data['postcode']==='90210'&&$GLOBALS['customer_reads']===array(7));
$GLOBALS['user_id']=0;$check('anonymous no access to saved account record',Postal::lookup($request($a))->data===array('state'=>'manual')&&$GLOBALS['customer_reads']===array(7));
$check('merchant consent/provider off means zero outbound',Postal::lookup($request($a,true))->data===array('state'=>'manual')&&!$GLOBALS['provider_calls']);
$GLOBALS['options'][Postal::OPTION]=array('enabled'=>true,'google_enabled'=>true,'merchant_consent'=>true);$check('server key + merchant setting make provider ready',Postal::google_ready());
$check('customer consent still mandatory',Postal::lookup($request($a))->data===array('state'=>'manual')&&!$GLOBALS['provider_calls']);
$valid=array('result'=>array('verdict'=>array('addressComplete'=>true,'validationGranularity'=>'SUB_PREMISE','hasInferredComponents'=>true),'address'=>array('postalAddress'=>array('regionCode'=>'US','administrativeArea'=>'CA','locality'=>'Example City','addressLines'=>array('123 Example Rd','Unit 2'),'postalCode'=>'90210'),'addressComponents'=>array(array('componentType'=>'street_number','confirmationLevel'=>'CONFIRMED'),array('componentType'=>'postal_code','confirmationLevel'=>'CONFIRMED','inferred'=>true))),'uspsData'=>array('dpvConfirmation'=>'Y','standardizedAddress'=>array('zipCode'=>'90210','state'=>'CA'))));
$check('strict USPS DPV Y confirmed missing ZIP accepted',Postal::google_result($a,$valid)===array('state'=>'matched','postcode'=>'90210','source'=>'google'));
foreach(array('N','D','S','')as$dpv){$bad=$valid;$bad['result']['uspsData']['dpvConfirmation']=$dpv;$check('reject non-deliverable or missing unit DPV '.$dpv,Postal::google_result($a,$bad)===array('state'=>'manual'));}
foreach(array('hasUnconfirmedComponents','hasReplacedComponents','hasSpellCorrectedComponents')as$key){$bad=$valid;$bad['result']['verdict'][$key]=true;$check('reject correction flag '.$key,Postal::google_result($a,$bad)===array('state'=>'manual'));}
$bad=$valid;$bad['result']['address']['addressComponents'][0]['inferred']=true;$check('street/house inference rejected',Postal::google_result($a,$bad)===array('state'=>'manual'));
$bad=$valid;$bad['result']['address']['postalAddress']['addressLines'][0]='999 Changed Rd';$check('changed street never accepted',Postal::google_result($a,$bad)===array('state'=>'manual'));
$bad=$valid;$bad['result']['address']['postalAddress']['locality']='Other City';$check('changed city never accepted',Postal::google_result($a,$bad)===array('state'=>'manual'));
$bad=$valid;$bad['result']['address']['postalAddress']['regionCode']='PR';$check('provider cannot change country',Postal::google_result($a,$bad)===array('state'=>'manual'));
$GLOBALS['provider_result']=$valid;$response=Postal::lookup($request($a,true));$sent=$GLOBALS['provider_calls'][0];$payload=json_decode($sent['args']['body'],true);
$check('explicit consent uses fixed HTTPS endpoint no key query',str_ends_with($sent['url'],'/v1:validateAddress')&&!str_contains($sent['url'],'?')&&true===$sent['args']['sslverify']&&0===$sent['args']['redirection']);
$check('outbound contains only minimal address and CASS',array_keys($payload)===array('address','enableUspsCass')&&true===$payload['enableUspsCass']&&!str_contains($sent['args']['body'],'first_name')&&!str_contains($sent['args']['body'],'email'));
$check('response contains no echoed address secret or native customer',array_keys($response->data)===array('state','postcode','source')&&!str_contains(json_encode($response->data),'Example'));
$check('security record has hashed identity only',(bool)preg_match('/\Apc-postal-[a-f0-9]{64}\z/',$GLOBALS['rate_keys'][0]));
WC_Rate_Limiter::$limited=true;$n=count($GLOBALS['provider_calls']);$check('rate limited requests never invoke provider',Postal::lookup($request($a,true)) instanceof WP_Error&&count($GLOBALS['provider_calls'])===$n);WC_Rate_Limiter::$limited=false;
$filled=$request(array_replace($a,array('postcode'=>'33333')),true);$check('server refuses overwrite of populated ZIP',Postal::lookup($filled)->data===array('state'=>'manual')&&count($GLOBALS['provider_calls'])===$n);
$r=new WP_REST_Response(array());$r->headers=array('Vary'=>'Accept-Encoding','cache-control'=>'public');Postal::private_headers($r,null,$request($a));$check('private no-store and merged Vary on all responses',$r->headers['Cache-Control']==='private, no-store, max-age=0'&&str_contains($r->headers['Vary'],'Accept-Encoding')&&str_contains($r->headers['Vary'],'Cookie')&&!isset($r->headers['cache-control']));
echo json_encode(array('passed'=>count(array_filter($checks,static fn($r)=>$r['pass'])),'total'=>count($checks),'checks'=>$checks),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
exit(count(array_filter($checks,static fn($r)=>!$r['pass']))?1:0);
}
