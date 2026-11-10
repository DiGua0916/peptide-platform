<?php
/** Real offer/admin and existing first-order rules; in-memory WordPress/Woo fixture, no database. */
namespace {
define( 'ABSPATH', __DIR__ . '/' );
define( 'DB_NAME', 'fixture_offer_database' );
final class WP_Error { public function __construct( public string $code, public string $message ) {} public function get_error_message() { return $this->message; } }
final class Offer_Die extends \RuntimeException {}
final class Offer_Redirect extends \RuntimeException {}
class WC_Coupon {
    public array $data = array( 'id'=>0, 'code'=>'', 'discount_type'=>'percent', 'amount'=>'5', 'usage_limit_per_user'=>1, 'individual_use'=>false, 'free_shipping'=>false, 'usage_limit'=>0, 'usage_count'=>0, 'date_expires'=>null, 'product_ids'=>array(), 'product_categories'=>array(), 'excluded_product_ids'=>array(), 'excluded_product_categories'=>array(), 'exclude_sale_items'=>false );
    private array $new_meta = array();
    public function __construct( $id = 0 ) {
        if ( is_string( $id ) && ! ctype_digit( $id ) ) { $id = wc_get_coupon_id_by_code( $id ); }
        if ( isset( $GLOBALS['coupons'][ $id ] ) ) { $this->data = array_replace( $this->data, $GLOBALS['coupons'][ $id ] ); }
    }
    public function __call( $name, $args ) {
        if ( str_starts_with( $name, 'get_' ) ) { return $this->data[ substr( $name,4 ) ] ?? null; }
        if ( str_starts_with( $name, 'set_' ) ) { $this->data[ substr( $name,4 ) ] = $args[0]; return; }
        throw new \RuntimeException( $name );
    }
    public function update_meta_data( $key, $value ) { $this->new_meta[$key]=$value; }
    public function save() {
        if ( $GLOBALS['save_failure'] ) { throw new \RuntimeException( 'Synthetic creation failure' ); }
        $this->data['id']=99;$GLOBALS['coupons'][99]=$this->data;$GLOBALS['meta'][99]=$this->new_meta;$GLOBALS['post_status'][99]='publish';$GLOBALS['writes'][]='coupon:99';return 99;
    }
}
class WC_Product { public function get_id() { return 1; } public function get_parent_id() { return 0; } public function is_on_sale() { return false; } }
class WC_Cart { public array $codes=array(); public function get_applied_coupons() { return $this->codes; } public function get_cart() { return array(array('data'=>new WC_Product(),'quantity'=>1,'line_subtotal'=>100)); } }
class WC_Discounts { public function __construct( private $cart ) {} public function get_object() { return $this->cart; } }
$GLOBALS['coupons']=array(1=>array('id'=>1,'code'=>'welcome5'),2=>array('id'=>2,'code'=>'legacy','individual_use'=>true));
$GLOBALS['meta']=array(1=>array('_pc_first_order'=>'yes','_pc_strategy'=>'stack'),2=>array());
$GLOBALS['post_status']=array(1=>'publish',2=>'publish');$GLOBALS['post_types']=array();
$GLOBALS['options']=array();$GLOBALS['writes']=array();$GLOBALS['hooks']=array();$GLOBALS['permissions']=true;$GLOBALS['nonce']=true;$GLOBALS['save_failure']=false;$GLOBALS['coupons_enabled']=true;
$GLOBALS['email']='';$GLOBALS['user_id']=0;$GLOBALS['prior_email']='';$GLOBALS['prior_id']=0;$GLOBALS['promotions']=array();$GLOBALS['promo_configs']=array();
$wpdb=new class {
    public string $prefix='fixture_';public bool $busy=false;public array $queries=array();
    public function prepare($sql,$key){return $sql.'|'.$key;}
    public function get_var($sql){$this->queries[]=$sql;return str_contains($sql,'RELEASE_LOCK')?'1':($this->busy?'0':'1');}
};
function get_option( $key, $default=null ) { return $GLOBALS['options'][$key]??$default; }
function update_option( $key,$value,$autoload=null ) { $GLOBALS['options'][$key]=$value;$GLOBALS['writes'][]='option:'.$key;return true; }
function get_post_meta( $id,$key,$single=false ) { return $GLOBALS['meta'][$id][$key]??''; }
function get_post_type( $id ) { return $GLOBALS['post_types'][$id]??(isset($GLOBALS['coupons'][$id])?'shop_coupon':null); }
function get_post_status( $id ) { return $GLOBALS['post_status'][$id]??null; }
function current_user_can( $cap ) { return $GLOBALS['permissions']; }
function wc_get_coupon_id_by_code( $code ) { foreach($GLOBALS['coupons'] as $id=>$row) { if(wc_format_coupon_code($row['code'])===wc_format_coupon_code($code))return $id; } return 0; }
function wc_coupons_enabled() { return $GLOBALS['coupons_enabled']; }
function wc_format_coupon_code( $code ) { return strtolower(trim($code)); }
function wc_strtolower( $text ) { return strtolower($text); }
function wc_get_product_cat_ids( $id ) { return array(); }
function get_current_user_id() { return $GLOBALS['user_id']; }
function wp_get_current_user() { return (object)array('user_email'=>$GLOBALS['email']); }
function WC() { return (object)array('customer'=>new class { public function get_billing_email() { return $GLOBALS['email']; } }); }
function wc_get_is_paid_statuses() { return array('processing','completed'); }
function wc_get_orders( $args ) { return (!empty($args['customer_id'])&&$args['customer_id']===$GLOBALS['prior_id'])||(!empty($args['billing_email'])&&$args['billing_email']===$GLOBALS['prior_email'])?array(123):array(); }
function __( $text,...$args ) { return $text; }
function _n( $singular,$plural,$count,...$args ) { return $count===1?$singular:$plural; }
function wc_get_cart_url() { return 'https://example.invalid/cart/'; }
function wp_json_encode( $value,$flags=0 ) { return json_encode($value,$flags); }
function wp_unique_id( $prefix ) { static $n=0;return $prefix.++$n; }
function esc_attr( $text ) { return htmlspecialchars((string)$text,ENT_QUOTES,'UTF-8'); }
function esc_html( $text ) { return htmlspecialchars((string)$text,ENT_QUOTES,'UTF-8'); }
function esc_url( $text ) { return esc_attr($text); }
function add_filter( ...$args ) { $GLOBALS['hooks'][]=$args; }
function add_action( ...$args ) { $GLOBALS['hooks'][]=$args; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_die( $text,$title='',$args=array() ) { throw new Offer_Die($text,(int)($args['response']??500)); }
function check_admin_referer( $action ) { if(!$GLOBALS['nonce'])throw new Offer_Die('nonce',403); }
function wp_safe_redirect( $url ) { throw new Offer_Redirect($url); }
function admin_url( $path ) { return 'https://example.invalid/wp-admin/'.$path; }
function get_posts( $args ) { return array_map(static fn($id)=>(object)array('ID'=>$id),array_keys($GLOBALS['coupons'])); }
function wp_nonce_field( $action ) { echo '<input name="_wpnonce" value="fixture">'; }
function checked( $a,$b,$echo=true ) { return $a===$b?' checked':''; }
function selected( $a,$b,$echo=true ) { return $a===$b?' selected':''; }
function submit_button( $label,...$args ) { echo '<button>'.esc_html($label).'</button>'; }
}
namespace PeptideCore {
final class Customer_Segments { public const COUPON_META='_pc_fixture_segment';public static function matches($rule,$email){return true;}public static function current_email(){return $GLOBALS['email'];} }
final class Promotions { public static function discounts($cart,$skip='') { return array('applied'=>$GLOBALS['promotions']); } public static function config($id) { return $GLOBALS['promo_configs'][$id]??array(); } }
final class Storefront_Translation { public static bool $es=false;public static function code() { return self::$es?'es':'en'; } public static function active() { return self::$es; } }
final class Templates { public static int $id=0;public static function previewing_id() { return self::$id; } }
}
namespace PeptideCore\Modules { final class Renderer { public static bool $in_editor=false; } }
namespace PeptideCore\Admin { final class Apps_Page { public static function url($args=array()) { return 'https://example.invalid/wp-admin/admin.php?'.http_build_query($args); } public static function require_allowed($app) { /* 2026-11-08 起保存前先检查总后台是否停用了这个应用；这里一律放行。 */ } } }
namespace {
require dirname(__DIR__).'/includes/class-coupon-rules.php';
if(!function_exists('apply_filters')){function apply_filters($tag,$value,...$args){return $value;}}
require dirname(__DIR__).'/includes/pricing/class-popups.php'; // 营销弹窗静默开关（M4）：这里始终不静默。
require dirname(__DIR__).'/includes/class-first-order-offer.php';
require dirname(__DIR__).'/includes/admin/class-marketing.php'; // HQ-07：保存前检查 Marketing::can_manage()（投手只读）。
require dirname(__DIR__).'/includes/admin/class-first-order-offer-page.php';
use PeptideCore\First_Order_Offer as Offer;
use PeptideCore\Coupon_Rules;
use PeptideCore\Admin\First_Order_Offer_Page as Admin;
$checks=array();$check=static function($name,$pass)use(&$checks){$checks[]=array('name'=>$name,'pass'=>(bool)$pass);};
$throws=static function($callback,$code=null){try{$callback();return false;}catch(\Throwable $e){return null===$code||$e->getCode()===$code;}};
$check('disabled defaults and own option only',Offer::settings()===array('enabled'=>false,'coupon_id'=>0)&&!$GLOBALS['writes']);
foreach(array(null,'bad',42,array(),array('enabled'=>'1','coupon_id'=>-1),array('enabled'=>1,'coupon_id'=>'1e2'),array('enabled'=>true,'coupon_id'=>array(1)))as$i=>$raw){$s=Offer::normalize($raw);$check('malformed bounded config '.$i,is_bool($s['enabled'])&&is_int($s['coupon_id'])&&$s['coupon_id']>=0);}
$check('strict true and digit id preserved',Offer::normalize(array('enabled'=>true,'coupon_id'=>'1','foreign'=>'x'))===array('enabled'=>true,'coupon_id'=>1));
$coupon=new WC_Coupon(1);$check('valid five percent first-order coupon accepted',''===Offer::coupon_issue($coupon));
foreach(array('discount_type'=>'fixed_cart','amount'=>'5.1','usage_limit_per_user'=>2,'individual_use'=>true,'free_shipping'=>true)as$key=>$value){$bad=new WC_Coupon(1);$bad->data[$key]=$value;$check('reject incompatible '.$key,''!==Offer::coupon_issue($bad));}
$GLOBALS['meta'][1]['_pc_first_order']='no';$check('requires native first-order rule','first'===Offer::coupon_issue($coupon));$GLOBALS['meta'][1]['_pc_first_order']='yes';
foreach(array('replace','no_stack')as$strategy){$GLOBALS['meta'][1]['_pc_strategy']=$strategy;$check('reject strategy '.$strategy,'stack'===Offer::coupon_issue($coupon));}$GLOBALS['meta'][1]['_pc_strategy']='stack';
$GLOBALS['post_status'][1]='draft';$check('inactive can bind disabled but cannot display','inactive'===Offer::coupon_issue($coupon)&&''===Offer::coupon_issue($coupon,false));$GLOBALS['post_status'][1]='publish';
$GLOBALS['options'][Offer::OPTION]=array('enabled'=>true,'coupon_id'=>1);$check('enabled compatible native coupon resolved',Offer::coupon() instanceof WC_Coupon);
$GLOBALS['coupons_enabled']=false;$check('Woo disabled means no public offer',null===Offer::coupon());$GLOBALS['coupons_enabled']=true;
$definitions=Offer::definitions(array());$check('native module operation category and single render',$definitions[Offer::MODULE]['category']==='operation'&&$definitions[Offer::MODULE]['singleRender']===true);
$html=Offer::render();$check('escaped static code no customer data',str_contains($html,'WELCOME5')&&str_contains($html,'data-offer-config')&&!str_contains($html,'billing_email'));
$check('compact offer has exactly one claim button and retains compatibility marker',substr_count($html,'<button ')===1&&str_contains($html,'data-offer-claim data-offer-apply>Claim</button>')&&!str_contains($html,'data-offer-copy'));
$check('compact offer keeps readable protected code and one short title',str_contains($html,'<code data-offer-code translate="no">WELCOME5</code>')&&str_contains($html,'>5% off your first order</h2>')&&!str_contains($html,'pc-first-offer__body')&&!str_contains($html,'pc-first-offer__pending'));
preg_match('/data-offer-config="([^"]+)"/',$html,$config_match);$public_config=json_decode(html_entity_decode($config_match[1]??'',ENT_QUOTES,'UTF-8'),true);
$check('actual claim config keeps real code and short popup eligibility copy',$public_config['code']==='welcome5'&&$public_config['closeMs']===15000&&$public_config['preview']===false&&$public_config['strings']['apply']==='Claim'&&$public_config['strings']['pending']==='First-order eligibility is checked at checkout.');
$check('public coupon render never saves',!$GLOBALS['writes']);
$check('public render never acquires a database creation lock',!$wpdb->queries);
$check('unrelated module output retained','old'===Offer::render_module('old',array('type'=>'richtext'),'public','all'));
$preview=Offer::render(true);$check('preview disabled write',str_contains($preview,'data-offer-apply disabled')&&str_contains($preview,'&quot;preview&quot;:true'));
PeptideCore\Storefront_Translation::$es=true;$spanish_html=Offer::render();$check('Spanish offer has one Obtener claim action and translated compact title',substr_count($spanish_html,'<button ')===1&&str_contains($spanish_html,'data-offer-claim data-offer-apply>Obtener</button>')&&str_contains($spanish_html,'>5% en su primer pedido</h2>')&&!str_contains($spanish_html,'data-offer-copy'));PeptideCore\Storefront_Translation::$es=false;
$en=Offer::strings();PeptideCore\Storefront_Translation::$es=true;$es=Offer::strings();
$check('all nonempty English customer UI keys have distinct Spanish copy',!array_diff(array_keys($en),array_keys($es))&&!array_filter($en,static fn($value,$key)=>is_string($value)&&''!==$value&&(!is_string($es[$key]??null)||''===$es[$key]||$value===$es[$key]),ARRAY_FILTER_USE_BOTH));
$check('unused body copy stays empty in both languages',$en['body']===''&&$es['body']==='');
$check('Spanish dynamic/native failures have owned translations',isset($es['errors']['This code is for a first order only.'],$es['errors']['The request timed out. Please check your bag before trying again.'])&&''!==$es['failed']);
PeptideCore\Storefront_Translation::$es=false;
$cart=new WC_Cart();$discounts=new WC_Discounts($cart);$check('valid matching stack retained',true===Offer::validate_stack(true,$coupon,$discounts));
$cart->codes=array('legacy');$check('existing individual-use coupon not removed and bound offer rejected',$throws(static fn()=>Offer::validate_stack(true,$coupon,$discounts))&&$cart->codes===array('legacy'));
$check('foreign coupon validator remains inert',true===Offer::validate_stack(true,new WC_Coupon(2),$discounts));
$GLOBALS['options'][Offer::OPTION]['enabled']=false;$check('disabled guard is inert',true===Offer::validate_stack(true,$coupon,$discounts));$GLOBALS['options'][Offer::OPTION]['enabled']=true;$cart->codes=array();
$GLOBALS['promotions']=array(array('id'=>8));$GLOBALS['promo_configs'][8]=array('with_coupons'=>false);$check('existing incompatible promotion rejects without writes',$throws(static fn()=>Offer::validate_stack(true,$coupon,$discounts))&&!$GLOBALS['writes']);
$GLOBALS['promo_configs'][8]['with_coupons']=true;$check('compatible promotion and coupon can coexist',true===Offer::validate_stack(true,$coupon,$discounts));$GLOBALS['promotions']=array();
$GLOBALS['prior_email']='buyer@example.invalid';$GLOBALS['email']='';$check('anonymous first-order eligibility remains pending',true===Coupon_Rules::validate(true,$coupon,$discounts));
$GLOBALS['email']=' BUYER@EXAMPLE.INVALID ';$check('existing production rule rejects prior checkout email',$throws(static fn()=>Coupon_Rules::validate(true,$coupon,$discounts)));$GLOBALS['email']='';
$GLOBALS['prior_id']=7;$GLOBALS['user_id']=7;$check('existing production rule rejects prior account',$throws(static fn()=>Coupon_Rules::validate(true,$coupon,$discounts)));$GLOBALS['user_id']=0;
$GLOBALS['permissions']=false;$check('creation denies unauthorized operator',is_wp_error(Offer::create_welcome_coupon()));$check('admin page denies unauthorized operator',$throws(static fn()=>Admin::render(),403));$GLOBALS['permissions']=true;
$_SERVER['REQUEST_METHOD']='GET';$check('save route rejects GET',$throws(static fn()=>Admin::handle(),405));$_SERVER['REQUEST_METHOD']='POST';$GLOBALS['nonce']=false;$check('save route checks nonce',$throws(static fn()=>Admin::handle(),403));$GLOBALS['nonce']=true;
$_POST=array('mode'=>'save','coupon_id'=>'1e2','enabled'=>'1');$check('save rejects malformed id no write',$throws(static fn()=>Admin::handle(),400)&&!$GLOBALS['writes']);
$GLOBALS['coupons'][1]['amount']='10';$_POST=array('mode'=>'save','coupon_id'=>'1','enabled'=>'1');$check('save rejects incompatible five percent no write',$throws(static fn()=>Admin::handle(),400)&&!$GLOBALS['writes']);$GLOBALS['coupons'][1]['amount']='5';
$GLOBALS['coupons_enabled']=false;$check('cannot misleadingly enable while native Woo coupons are off',$throws(static fn()=>Admin::handle(),400)&&!$GLOBALS['writes']);$GLOBALS['coupons_enabled']=true;
$_POST=array('mode'=>'save','coupon_id'=>'1','enabled'=>'1','foreign'=>'x');$check('valid protected save redirects',$throws(static fn()=>Admin::handle()));$check('save writes only normalized independent option',$GLOBALS['writes']===array('option:'.Offer::OPTION)&&$GLOBALS['options'][Offer::OPTION]===array('enabled'=>true,'coupon_id'=>1));$GLOBALS['writes']=array();
$check('existing compatible WELCOME5 reused no coupon write',Offer::create_welcome_coupon()===1&&!$GLOBALS['writes']);
$GLOBALS['coupons'][1]['amount']='10';$check('existing incompatible same code never overwritten',is_wp_error(Offer::create_welcome_coupon())&&!$GLOBALS['writes']);$GLOBALS['coupons'][1]['amount']='5';
unset($GLOBALS['coupons'][1]);$id=Offer::create_welcome_coupon();$new=new WC_Coupon($id);$check('explicit creation persists controlled exact rule',$id===99&&''===Offer::coupon_issue($new)&&$GLOBALS['writes']===array('coupon:99'));
$check('create helper itself never enables or writes settings',$GLOBALS['options'][Offer::OPTION]===array('enabled'=>true,'coupon_id'=>1));
$GLOBALS['writes']=array();$check('repeated explicit creation reuses code',Offer::create_welcome_coupon()===99&&!$GLOBALS['writes']);
unset($GLOBALS['coupons'][99]);$wpdb->busy=true;$check('concurrent create fails closed without coupon write',is_wp_error(Offer::create_welcome_coupon())&&!$GLOBALS['writes']);$wpdb->busy=false;
$GLOBALS['save_failure']=true;$check('creation failure returns recoverable error',is_wp_error(Offer::create_welcome_coupon())&&!$GLOBALS['writes']);$GLOBALS['save_failure']=false;
$check('advisory creation lock released after exception',str_contains(end($wpdb->queries),'RELEASE_LOCK'));
$check('advisory lock separates database and site without revealing database name',str_contains(end($wpdb->queries),'pc_offer_'.sha1(DB_NAME.'|fixture_|welcome5'))&&!str_contains(end($wpdb->queries),DB_NAME));
$GLOBALS['options'][Offer::OPTION]=array('enabled'=>false,'coupon_id'=>0);$check('disabled public block absent',''===Offer::render());
Offer::register_hooks();$check('native coupon guard priority and args registered',(bool)array_filter($GLOBALS['hooks'],static fn($row)=>$row[0]==='woocommerce_coupon_is_valid'&&$row[1]===array(Offer::class,'validate_stack')&&$row[2]===30&&$row[3]===3));
echo json_encode(array('passed'=>count(array_filter($checks,static fn($row)=>$row['pass'])),'total'=>count($checks),'checks'=>$checks),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
exit(count(array_filter($checks,static fn($row)=>!$row['pass']))?1:0);
}
