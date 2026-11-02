<?php
/** No WordPress bootstrap, database or network. Native link/cookie helpers are optional. */
declare(strict_types=1);

$mode = $argv[1] ?? 'managed';
$native = $argv[2] ?? '';
define( 'ABSPATH', __DIR__ . '/unused/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'PEPTIDE_CORE_VERSION', 'test' );
if ( 'legacy' !== $mode ) {
	define( 'PEPTIDE_CORE_MANAGEMENT_URL', 'https://store-a.platform.example' );
	define( 'PEPTIDE_CORE_MANAGEMENT_UUID', '01234567-89ab-cdef-0123-456789abcdef' );
}
define( 'COOKIE_DOMAIN', 'bad-cookie' === $mode ? '.platform.example' : '' );
if('bad-home'===$mode){define('WP_HOME','https://shop-a.example');}
if('bad-path'===$mode){define('SITECOOKIEPATH','/old-site/');}
$GLOBALS['options'] = array(
	'home' => 'https://shop-a.example', 'siteurl' => 'wrong-siteurl' === $mode ? 'https://shop-a.example' : 'https://store-a.platform.example',
	'peptide_core_hq' => array( 'uuid' => 'wrong-uuid' === $mode ? 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa' : '01234567-89ab-cdef-0123-456789abcdef' ),
	'peptide_core_domains' => array( 'items' => array(
		'shop-a.example' => array( 'id' => 1, 'conn' => 'connected', 'https' => 'active', 'redirect' => '' ),
		'shop-b.example' => array( 'id' => 2, 'conn' => 'connected', 'https' => 'active', 'redirect' => '' ),
		'insecure.example' => array( 'id' => 3, 'conn' => 'connected', 'https' => 'failed', 'redirect' => '' ),
	) ),
);
$GLOBALS['filters'] = array();
$GLOBALS['actor'] = null;
$GLOBALS['transients'] = array();
$_SERVER = array( 'HTTP_HOST' => 'store-a.platform.example', 'REQUEST_URI' => '/wp-admin/', 'REQUEST_METHOD' => 'GET', 'HTTPS' => 'on' );
$_GET = $_POST = array();
function add_filter($h,$cb,$p=10,$n=1){$GLOBALS['filters'][$h][$p][]=$cb;return true;}
function add_action($h,$cb,$p=10,$n=1){return add_filter($h,$cb,$p,$n);}
function add_shortcode($name,$callback){}
function apply_filters($h,$v,...$args){$rows=$GLOBALS['filters'][$h]??array();ksort($rows);foreach($rows as$cbs){foreach($cbs as$cb){$v=$cb($v,...$args);}}return$v;}
function get_option($k,$d=false){return apply_filters('option_'.$k,$GLOBALS['options'][$k]??$d);}
function get_site_option($k,$d=false){return get_option($k,$d);}
function update_option($k,$v,...$a){$GLOBALS['options'][$k]=$v;return true;}
function wp_parse_url($v,$c=-1){return parse_url($v,$c);}
function rest_get_url_prefix(){return $GLOBALS['rest_prefix']??'wp-json';}
function untrailingslashit($v){return rtrim($v,'/\\');}
function trailingslashit($v){return untrailingslashit($v).'/';}
function is_multisite(){return false;}
function get_current_blog_id(){return 1;}
function is_ssl(){return 'on'===($_SERVER['HTTPS']??'');}
function force_ssl_admin($v=null){static$s=false;if(null!==$v){$s=$v;}return$s;}
function is_admin(){return str_starts_with($_SERVER['REQUEST_URI'],'/wp-admin/');}
function wp_doing_ajax(){return str_contains($_SERVER['REQUEST_URI'],'admin-ajax.php');}
function wp_doing_cron(){return false;}
function wp_get_current_user(){return $GLOBALS['actor']??new WP_User(0,array());}
function current_user_can($cap){return user_can(wp_get_current_user(),$cap);}
function wc_get_page_permalink($page){return home_url('/my-account/');}
function wc_get_account_endpoint_url($endpoint){return home_url('/my-account/'.$endpoint.'/?_wpnonce=synthetic');}
function user_can($u,$cap){return in_array($cap,$u->caps,true);}
function get_userdata($id){return $GLOBALS['users'][$id]??false;}
function get_users($args){$rows=array_filter($GLOBALS['users']??array(),static function($u)use($args){return in_array($args['role'],$u->roles,true)&&(!isset($args['meta_key'])||get_user_meta($u->ID,$args['meta_key'])===($args['meta_value']??''));});return array_slice(array_map(fn($u)=>$u->ID,array_values($rows)),0,$args['number']??999);}
function get_user_meta($id,$key,$single=true){return $GLOBALS['meta'][$id][$key]??'';}
function update_user_meta($id,$key,$v){$GLOBALS['meta'][$id][$key]=$v;return true;}
function wp_salt($scheme){return 'synthetic-test-salt';}
function add_query_arg($key,$value=null,$url=null){if(is_array($key)){$args=$key;$base=(string)$value;}else{$args=array($key=>$value);$base=(string)$url;}return$base.(str_contains($base,'?')?'&':'?').http_build_query($args);}
function wp_generate_password($n=12,...$a){static$i=0;return substr(hash('sha256','synthetic-test-'.++$i),0,$n);}
function sanitize_key($s){return preg_replace('/[^a-z0-9_-]/','',strtolower($s));}
function wp_next_scheduled($h){return true;}
function wp_schedule_event(...$a){return true;}
function nocache_headers(){}
function esc_html($s){return htmlspecialchars($s);}
function __($s,$d=''){return$s;}
function sanitize_text_field($s){return trim($s);}
function wp_unslash($s){return$s;}
function wp_basename($s){return basename($s);}
function wp_fuzzy_number_match($expected,$actual,$precision=1){return abs($expected-$actual)<=$precision;}
function esc_url($s){return$s;}
function get_metadata($type,$id,$key,$single=false){return'_wp_attached_file'===$key?'2026/11/synthetic.jpg':null;}
function wp_get_upload_dir(){return apply_filters('upload_dir',array('baseurl'=>'https://store-a.platform.example/wp-content/uploads','url'=>'https://store-a.platform.example/wp-content/uploads/2026/11','basedir'=>'/synthetic/uploads','path'=>'/synthetic/uploads/2026/11','error'=>false));}
function wp_die($m,$t='',$a=array()){throw new RuntimeException('denied:'.($a['response']??0));}
function wp_redirect($url,$status=302,$by=''){throw new RuntimeException('redirect:'.$status.':'.$url);}
function wp_safe_redirect($url,$status=302,$by=''){return wp_redirect($url,$status,$by);}
function set_transient($k,$v,$ttl){$GLOBALS['transients'][$k]=$v;return true;}
function get_transient($k){return$GLOBALS['transients'][$k]??false;}
function delete_transient($k){unset($GLOBALS['transients'][$k]);}
class WP_User {public int $ID;public array $roles;public array $caps;public string $user_login='synthetic';function __construct($id,$caps,$roles=array()){$this->ID=$id;$this->caps=$caps;$this->roles=$roles;}}
class WP_Error {public string $code;public array $data;function __construct($code,$message='',$data=array()){$this->code=$code;$this->data=$data;}}
class WP_REST_Request {private array $data;function __construct($data=array()){$this->data=$data;}function get_json_params(){return$this->data;}function get_route(){return$this->data['route']??'/';}}
class WP_Post {public int $ID=99;public string $post_type='attachment';public string $filter='raw';static function get_instance($id){return 99===$id?new self():null;}function filter($f){return$this;}}
class WP_Customize_Manager {public string $url='https://shop-b.example/product/synthetic/';function get_preview_url(){return$this->url;}function set_preview_url($url){$this->url=$url;}function is_preview(){return true;}}
require dirname(__DIR__).'/includes/autoload.php';
require dirname(__DIR__,2).'/peptide-panapay/includes/class-order-payload.php';
if ( '' !== $native ) {
	if ( ! is_file($native.'/wp-includes/link-template.php') ) { throw new RuntimeException('Native WordPress source required'); }
	define('WP_PLUGIN_URL','https://store-a.platform.example/wp-content/plugins');
	define('OBJECT','OBJECT');define('ARRAY_A','ARRAY_A');define('ARRAY_N','ARRAY_N');
	require $native.'/wp-includes/link-template.php';
	require $native.'/wp-includes/default-constants.php';
	require $native.'/wp-includes/post.php';
	require $native.'/wp-includes/media.php';
	wp_cookie_constants();
} else {
	function home_url($p='',$s=null){return apply_filters('home_url',rtrim(get_option('home'),'/').'/'.ltrim($p,'/'));}
	function site_url($p='',$s=null){return apply_filters('site_url',rtrim(get_option('siteurl'),'/').('/'===substr($p,0,1)?$p:($p?'/'.$p:'')),$p,$s,null);}
}
use PeptideCore\Management_Host as Host;
use PeptideCore\Domains;
use PeptideCore\Staff_Entry;
use PeptideCore\Hq_Connector;
$count=0;
function check($name,$ok){global$count;if(!$ok){throw new RuntimeException('FAIL '.$name);}++$count;}
function outcome($cb){try{$cb();return'allowed';}catch(RuntimeException$e){return$e->getMessage();}}
Host::register_hooks();
Domains::register_hooks();
if('legacy'===$mode){check('legacy has no host enforcement',!isset($GLOBALS['filters']['init'][-1000]));check('legacy retains both domain filters',isset($GLOBALS['filters']['option_siteurl'][99]));check('legacy payment URL unchanged','http://127.0.0.1:18080/?wc-api=pc_panapay_return'===\VialCove\Panapay\Order_Payload::customer_url('http://127.0.0.1:18080/?wc-api=pc_panapay_return'));echo"PASS legacy $count\n";exit;}
if(in_array($mode,array('bad-cookie','bad-home','bad-path','wrong-uuid','wrong-siteurl'),true)){check('bad binding fails closed',str_starts_with(outcome(fn()=>Host::guard_request()),'denied:503'));echo"PASS $mode $count\n";exit;}
check('valid explicit binding',''===Host::configuration_error());
foreach(array('http://a.example','https://a.example:444','https://u:p@a.example','https://a.example/path','https://a.example/?x=1','https://a.example/#x','https://127.0.0.1','https://a.example\\evil')as$url){check('reject malformed management origin '.$url,''===Host::normalize_url($url));}
check('normalize uppercase host','https://a.example'===Host::normalize_url('https://A.EXAMPLE/'));
check('unknown host not admitted',!in_array('other.platform.example',Host::allowed_hosts(),true));
$_SERVER['HTTP_HOST']='store-b.platform.example';check('wrong store host is rejected','denied:421'===outcome(fn()=>Host::guard_request()));
$_SERVER['HTTP_HOST']='shop-a.example';$_SERVER['REQUEST_URI']='/';
foreach(array('/wp-admin/','/WP-ADMIN/','/%77p-admin/','//wp-admin//','/wp-login.php','/xmlrpc.php','/wp-json/peptide/v1/hq/status','/WP-JSON/Peptide/v1/HQ/status','/wp-json/PEPTIDE-OPS/v1/download','/wp-json/pc-panapay/v1/store/event')as$uri){check('public personnel/machine route '.$uri,Host::personnel_path($uri,array()));}
check('query machine route blocked',Host::personnel_path('/',array('rest_route'=>'/peptide/v1/hq/staff')));
check('malformed query route blocked',Host::personnel_path('/',array('rest_route'=>array('bad'))));
check('public commerce ajax remains',!Host::personnel_path('/wp-admin/admin-ajax.php',array()));
check('public subscription remains',!Host::personnel_path('/wp-admin/admin-post.php',array(),array('action'=>'pc_subscribe')));
check('admin export action denied',Host::personnel_path('/wp-admin/admin-post.php',array(),array('action'=>'export')));
check('staff entry query rejected',Host::personnel_path('/',array('pc_staff_entry'=>'')));
check('customer storefront permitted','allowed'===outcome(fn()=>Host::guard_request()));
$staff=new WP_User(10,array('read'),array('pc_staff'));$admin=new WP_User(11,array('manage_options'),array('administrator'));$customer=new WP_User(12,array('read'),array('customer'));$manager=new WP_User(13,array('manage_woocommerce'),array('shop_manager'));
$other=new WP_User(14,array('read'),array('pc_staff'));$GLOBALS['users']=array(10=>$staff,11=>$admin,12=>$customer,13=>$manager,14=>$other);
foreach(array($staff,$admin,$manager)as$user){check('public cannot authenticate personnel',Host::authenticate($user)instanceof WP_Error);$GLOBALS['actor']=$user;check('public cannot replay personnel session','denied:403'===outcome(fn()=>Host::guard_request()));check('public personnel REST rejected',Host::rest_authentication(null)instanceof WP_Error);}
check('native REST authentication true cannot bypass host boundary',Host::rest_authentication(true)instanceof WP_Error);
$GLOBALS['actor']=new WP_User(0,array());check('anonymous REST batch machine route rejected',apply_filters('rest_pre_dispatch',null,null,new WP_REST_Request(array('route'=>'/peptide/v1/hq/status')))instanceof WP_Error);
check('ops batch subroute rejected',apply_filters('rest_pre_dispatch',null,null,new WP_REST_Request(array('route'=>'/peptide-ops/v1/download')))instanceof WP_Error);
check('mixed case batch subroute rejected',apply_filters('rest_pre_dispatch',null,null,new WP_REST_Request(array('route'=>'/Peptide-OPS/v1/download')))instanceof WP_Error);
$GLOBALS['rest_prefix']='api';check('custom REST prefix cannot bypass route guard',Host::personnel_path('/api/peptide/v1/hq/status',array()));unset($GLOBALS['rest_prefix']);
$GLOBALS['actor']=$customer;check('customer auth unchanged',Host::authenticate($customer)===$customer);check('customer REST unchanged',null===Host::rest_authentication(null));
check('public native login links go to customer account','https://shop-a.example/my-account/'===apply_filters('login_url','https://store-a.platform.example/wp-login.php'));
check('public customer logout keeps its cookie origin','https://shop-a.example/my-account/customer-logout/?_wpnonce=synthetic'===apply_filters('logout_url','https://store-a.platform.example/wp-login.php?action=logout&_wpnonce=synthetic'));
check('Woo customer password reset stays public','https://shop-a.example/my-account/lost-password/'===apply_filters('lostpassword_url','https://shop-a.example/my-account/lost-password/'));
check('public assets public','https://shop-a.example/wp-content/a.css'===Host::asset_link('https://store-a.platform.example/wp-content/a.css'));
$media=Host::upload_dir(array('baseurl'=>'https://store-a.platform.example/wp-content/uploads','url'=>'https://store-a.platform.example/wp-content/uploads/2026/11','basedir'=>'/private/disk/uploads','path'=>'/private/disk/uploads/2026/11'));
check('public uploaded media origin',$media['baseurl']==='https://shop-a.example/wp-content/uploads'&&$media['url']==='https://shop-a.example/wp-content/uploads/2026/11');check('upload disk paths unchanged',$media['basedir']==='/private/disk/uploads'&&$media['path']==='/private/disk/uploads/2026/11');
check('public srcset origin',Host::image_srcset(array(150=>array('url'=>'https://store-a.platform.example/wp-content/uploads/a-150.jpg')))[150]['url']==='https://shop-a.example/wp-content/uploads/a-150.jpg');
check('public REST same-origin','https://shop-a.example/wp-json/wc/store/v1/cart'===Host::request_link('https://store-a.platform.example/wp-json/wc/store/v1/cart'));
check('personnel URL stays management','https://store-a.platform.example/wp-login.php'===Host::site_url('https://shop-a.example/wp-login.php'));
check('HQ machine URL fixed','https://store-a.platform.example/'===Hq_Connector::connection_url());
check('public primary switch succeeds',true===Domains::set_primary('shop-b.example'));
check('public home changes','https://shop-b.example'===$GLOBALS['options']['home']);check('siteurl fixed',$GLOBALS['options']['siteurl']==='https://store-a.platform.example');
check('public switch cannot choose insecure host',Domains::set_primary('insecure.example')instanceof WP_Error);
check('HQ URL unchanged after public switch','https://store-a.platform.example/'===Hq_Connector::connection_url());
check('payment alias uses registered primary with unchanged native callback query','https://shop-b.example/?wc-api=pc_panapay_return&reference=synthetic&token=synthetic'===\VialCove\Panapay\Order_Payload::customer_url('https://shop-a.example/?wc-api=pc_panapay_return&reference=synthetic&token=synthetic'));
check('provider site never exposes management origin','https://shop-b.example/'===\VialCove\Panapay\Order_Payload::customer_url('https://store-a.platform.example/'));
$_SERVER['HTTP_HOST']='store-a.platform.example';$_SERVER['REQUEST_URI']='/wp-admin/';$GLOBALS['actor']=$staff;
check('management staff auth retained',Host::authenticate($staff)===$staff);check('management REST same-origin','https://store-a.platform.example/wp-json/peptide/v1/site'===Host::request_link('https://shop-b.example/wp-json/peptide/v1/site'));
check('staff password reset remains native management','https://store-a.platform.example/wp-login.php?action=lostpassword'===apply_filters('lostpassword_url','https://shop-b.example/my-account/lost-password/'));
check('backend public links remain public','https://shop-b.example/'===home_url('/'));
check('preview reuses management session','https://store-a.platform.example/?preview=true'===Host::management_link('https://shop-b.example/?preview=true'));
check('redirect allowlist adds only management',array('shop-b.example','store-a.platform.example')===Host::redirect_hosts(array('shop-b.example')));
check('no wildcards accepted',!in_array('.platform.example',Host::redirect_hosts(array()),true));
apply_filters('woocommerce_email_header',null);check('admin-triggered customer mail images public','https://shop-b.example/wp-content/uploads/a.jpg'===Host::asset_link('https://store-a.platform.example/wp-content/uploads/a.jpg'));apply_filters('woocommerce_email_footer',null);check('mail rendering restores backend asset origin','https://store-a.platform.example/wp-content/a.css'===Host::asset_link('https://shop-b.example/wp-content/a.css'));
$entryA=Staff_Entry::url(10);$entryB=Staff_Entry::url(14);check('staff entries use management origin',str_starts_with($entryA,'https://store-a.platform.example/wp-login.php?pc_staff_entry='));check('staff entries are account-specific',$entryA!==$entryB);
parse_str((string)parse_url($entryA,PHP_URL_QUERY),$entryQuery);$_GET=$entryQuery;$GLOBALS['pagenow']='wp-login.php';
check('assigned staff entry accepts only owner',Staff_Entry::authenticate($staff)===$staff&&Staff_Entry::authenticate($other)instanceof WP_Error);
check('earlier auth errors stay errors',Staff_Entry::authenticate(new WP_Error('prior'))instanceof WP_Error);
$_GET=array();check('staff generic login still denied',Staff_Entry::authenticate($staff)instanceof WP_Error);
$ticket=Hq_Connector::login_route(new WP_REST_Request(array('to'=>'plugins')));check('HQ ticket management origin',str_starts_with($ticket['url'],'https://store-a.platform.example/?pc_hq_login='));$saved=array_values($GLOBALS['transients'])[0];check('HQ ticket bound to UUID and origin',$saved['uuid']===PEPTIDE_CORE_MANAGEMENT_UUID&&$saved['management_url']===PEPTIDE_CORE_MANAGEMENT_URL);
if('cli'!==$mode){$_SERVER['REQUEST_URI']='/product/synthetic/';check('management storefront canonical public','redirect:302:https://shop-b.example/product/synthetic/'===outcome(fn()=>Host::guard_request()));$_GET=array('pc_template_preview'=>'3');check('staff preview allowed','allowed'===outcome(fn()=>Host::guard_request()));$GLOBALS['actor']=$customer;check('preview requires staff','denied:403'===outcome(fn()=>Host::guard_request()));}
if('cli'!==$mode){
	$_GET=array('pc_exit_preview'=>'1');$GLOBALS['actor']=$manager;check('existing exit preview stays management','allowed'===outcome(fn()=>Host::guard_request()));
	$_SERVER['REQUEST_METHOD']='POST';foreach(array('pc_exit_event','pc_exit_claim','pc_exit_spin')as$action){$_GET=array('wc-ajax'=>$action);check('staff exit preview AJAX '.$action,'allowed'===outcome(fn()=>Host::guard_request()));}
	$_GET=array('wc-ajax'=>'checkout');check('preview does not open real checkout on management','denied:403'===outcome(fn()=>Host::guard_request()));
	$_GET=array('wc-ajax'=>'pc_exit_claim');$GLOBALS['actor']=$customer;check('customer cannot call preview AJAX on management','denied:403'===outcome(fn()=>Host::guard_request()));
	$_SERVER['REQUEST_METHOD']='GET';$_GET=array('customize_changeset_uuid'=>'synthetic');check('customer cannot preview Customizer','denied:403'===outcome(fn()=>Host::guard_request()));
	$GLOBALS['actor']=new WP_User(15,array('manage_options','customize'),array('administrator'));$GLOBALS['wp_customize']=new WP_Customize_Manager();
	check('Customizer preview staff stays management','allowed'===outcome(fn()=>Host::guard_request()));Host::customizer_preview($GLOBALS['wp_customize']);
	check('Customizer initial preview management',$GLOBALS['wp_customize']->url==='https://store-a.platform.example/product/synthetic/');
	check('Customizer allowed origins only management',array('https://store-a.platform.example/')===apply_filters('customize_allowed_urls',array('https://shop-b.example/','https://evil.example/')));
	check('Customizer navigation management','https://store-a.platform.example/product/next/'===home_url('/product/next/'));
	$_GET=array();$_POST=array('wp_customize'=>'on','customize_preview_nonce'=>'synthetic','customize_changeset_uuid'=>'synthetic');$_SERVER['REQUEST_METHOD']='POST';
	check('Customizer selective refresh accepts native POST context','allowed'===outcome(fn()=>Host::guard_request()));
	unset($GLOBALS['wp_customize']);check('Customizer POST requires native manager','denied:403'===outcome(fn()=>Host::guard_request()));
	$GLOBALS['wp_customize']=new WP_Customize_Manager();$GLOBALS['actor']=$customer;check('Customizer POST requires customize capability','denied:403'===outcome(fn()=>Host::guard_request()));
	$GLOBALS['actor']=new WP_User(15,array('manage_options','customize'),array('administrator'));$_POST=array();$_SERVER['REQUEST_METHOD']='GET';
	unset($GLOBALS['wp_customize']);check('ordinary admin customer links stay public','https://shop-b.example/product/next/'===home_url('/product/next/'));$_GET=array();
}
if('cli'===$mode){define('WP_CLI',true);check('CLI customer assets public','https://shop-b.example/wp-content/a.css'===Host::asset_link('https://store-a.platform.example/wp-content/a.css'));}
if(''!==$native){check('native cookie domain stays host-only',''===COOKIE_DOMAIN);check('native cookie hash stable',md5('https://store-a.platform.example')===COOKIEHASH);check('native admin URL fixed','https://store-a.platform.example/wp-admin/'===admin_url());check('native customer link uses home','https://shop-b.example/my-account/'===home_url('/my-account/'));
	$_SERVER['HTTP_HOST']='shop-b.example';$_SERVER['REQUEST_URI']='/';$GLOBALS['actor']=$customer;$GLOBALS['pagenow']='index.php';
	check('native attachment URL stays public','https://shop-b.example/wp-content/uploads/2026/11/synthetic.jpg'===wp_get_attachment_url(99));
	$meta=array('file'=>'2026/11/synthetic.jpg','width'=>600,'height'=>600,'sizes'=>array('thumbnail'=>array('file'=>'synthetic-150.jpg','width'=>150,'height'=>150,'mime-type'=>'image/jpeg')));
	$srcset=wp_calculate_image_srcset(array(150,150),'https://shop-b.example/wp-content/uploads/2026/11/synthetic-150.jpg',$meta,99);
	check('native srcset contains public candidates',is_string($srcset)&&str_contains($srcset,'https://shop-b.example/')&&!str_contains($srcset,'store-a.platform.example'));
}
echo'PASS '.$mode.' '.(''!==$native?'native-links ':'policy ').$count."\n";
