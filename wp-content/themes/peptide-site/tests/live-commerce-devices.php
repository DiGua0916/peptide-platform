<?php
/** Production published settings getters with isolated native layout stubs. */
namespace PeptideCore {
    class Templates { public static function current_id() { return 123; } }
}
namespace PeptideCore\Modules {
    class Layout {
        public static function published_modules($document,$template) { $GLOBALS['published_reads'][]=[$document,$template];return $GLOBALS['published'][$document]??[]; }
        public static function preview_draft(...$args) { throw new \RuntimeException('The public product/cart getters must never read temporary drafts.'); }
    }
    class Renderer {
        public static function normalize($type,$attrs) {
            $defaults='product_experience'===$type ? ['mobileDialog'=>true,'reportGalleryEnabled'=>true,'stickyPurchaseEnabled'=>true,'broadcastEnabled'=>true,'whatsappEnabled'=>false,'whatsappNumber'=>'','whatsappMessage'=>'','whatsappLabel'=>'A little help'] : ['cart_experience_enabled'=>true,'cart_progress_enabled'=>true,'cart_recommendations_enabled'=>true,'cart_recent_enabled'=>true,'cart_title'=>'Shopping cart','cart_continue_label'=>'Continue exploring'];
            return ['settings'=>array_replace($defaults,$attrs['settings']??[]),'mobile'=>$attrs['mobile']??['settings'=>[],'items'=>null],'enabled'=>$attrs['enabled']??true,'visibility'=>$attrs['visibility']??['desktop'=>true,'mobile'=>true]];
        }
    }
}
namespace {
define('ABSPATH',__DIR__);
function add_filter(...$args){} function add_action(...$args){} function sanitize_text_field($value){return trim(preg_replace('/[\r\n\t]+/',' ',strip_tags($value)));}
require dirname(__DIR__).'/inc/live-commerce-settings.php';
$passed=0;function check($condition,$message){global $passed;if(!$condition)throw new \RuntimeException($message);++$passed;}
function row($type,$settings=[],$mobile=[],$extra=[]){return ['block'=>'vialcove/'.str_replace('_','-',$type),'attrs'=>array_replace(['settings'=>$settings,'mobile'=>['settings'=>$mobile,'items'=>null]],$extra)];}
function reset_fixture(){$GLOBALS['published']=[];$GLOBALS['published_reads']=[];$_GET=[];}
reset_fixture();
$p=vc_live_product_settings();$c=vc_live_cart_settings();
check(false===$p['enabled']&&false===$c['enabled'],'missing published settings never auto-enable a layout');
foreach(['desktop','mobile']as$d){check(false===$p['devices'][$d]['enabled']&&false===$c['devices'][$d]['enabled'],'both device DTOs exist for disabled legacy fallback');}
$GLOBALS['published']['product']=[row('product_experience')];$GLOBALS['published']['cart']=[row('context_content')];
$p=vc_live_product_settings();$c=vc_live_cart_settings();
check(true===$p['enabled']&&true===$c['enabled'],'both published layouts enabled');
check($p['devices']['desktop']===$p['devices']['mobile']&&$c['devices']['desktop']===$c['devices']['mobile'],'no override inherits every desktop field');
foreach(['mobileDialog','reportGalleryEnabled','stickyPurchaseEnabled','broadcastEnabled','whatsappEnabled','whatsappNumber','whatsappMessage','whatsappLabel','enabled']as$key){check(array_key_exists($key,$p['devices']['mobile']),'complete product device contract');}
foreach(['cart_experience_enabled','cart_progress_enabled','cart_recommendations_enabled','cart_recent_enabled','cart_title','cart_continue_label','enabled']as$key){check(array_key_exists($key,$c['devices']['mobile']),'complete cart device contract');}
$GLOBALS['published']['product']=[row('product_experience',[],['reportGalleryEnabled'=>false,'stickyPurchaseEnabled'=>false,'broadcastEnabled'=>false])];
$p=vc_live_product_settings();foreach(['reportGalleryEnabled','stickyPurchaseEnabled','broadcastEnabled']as$key){check($p[$key]===true&&$p['devices']['desktop'][$key]===true&&$p['devices']['mobile'][$key]===false,'mobile feature disable does not alter desktop compatibility value');}
$GLOBALS['published']['product']=[row('product_experience',['mobileDialog'=>false,'broadcastEnabled'=>false],['mobileDialog'=>true,'broadcastEnabled'=>true])];$p=vc_live_product_settings();
check($p['enabled']&&$p['mobileDialog']&&!$p['devices']['desktop']['enabled']&&$p['devices']['mobile']['enabled'],'desktop off mobile on still loads product controller');
check(!$p['broadcastEnabled']&&$p['devices']['mobile']['broadcastEnabled'],'mobile can enable feature disabled on desktop');
$GLOBALS['published']['product']=[row('product_experience',[],['mobileDialog'=>false])];$p=vc_live_product_settings();
check($p['enabled']&&$p['devices']['desktop']['enabled']&&!$p['devices']['mobile']['enabled'],'desktop on mobile off remains isolated');
$GLOBALS['published']['product']=[row('product_experience',[],['mobileDialog'=>true],['enabled'=>false])];$p=vc_live_product_settings();
check(!$p['enabled']&&!$p['mobileDialog']&&!$p['devices']['desktop']['enabled']&&!$p['devices']['mobile']['enabled'],'native disabled row overrides both device settings');
$GLOBALS['published']['product']=[row('product_experience',[],[],['visibility'=>['desktop'=>false,'mobile'=>true]])];$p=vc_live_product_settings();
check(!$p['devices']['desktop']['enabled']&&$p['devices']['mobile']['enabled']&&$p['visibility']===['desktop'=>false,'mobile'=>true],'native visibility gates each device and remains in top DTO');
$GLOBALS['published']['product']=[row('product_experience',['mobileDialog'=>false],['mobileDialog'=>true],['visibility'=>['desktop'=>true,'mobile'=>false]])];$p=vc_live_product_settings();
check(!$p['enabled']&&!$p['mobileDialog'],'invisible mobile-only setting cannot load product experience');
$GLOBALS['published']['product']=[row('product_experience',['whatsappEnabled'=>true,'whatsappNumber'=>'+1 (555) 123-4567','whatsappLabel'=>'Desktop support','whatsappMessage'=>'Desktop {product}'],['whatsappNumber'=>'+44 20 1234 5678','whatsappLabel'=>'<b>Mobile support</b>','whatsappMessage'=>'Mobile {sku}'])];$p=vc_live_product_settings();
check('15551234567'===$p['whatsappNumber']&&'442012345678'===$p['devices']['mobile']['whatsappNumber'],'each device sanitizes its own contact number');
check('Desktop support'===$p['whatsappLabel']&&'Mobile support'===$p['devices']['mobile']['whatsappLabel']&&'Mobile {sku}'===$p['devices']['mobile']['whatsappMessage'],'each device resolves all contact text independently');
check($p['devices']['desktop']['whatsappEnabled']&&$p['devices']['mobile']['whatsappEnabled'],'valid separate contacts enabled');
$GLOBALS['published']['product'][0]['attrs']['mobile']['settings']['whatsappNumber']='';$p=vc_live_product_settings();
check($p['devices']['desktop']['whatsappEnabled']&&!$p['devices']['mobile']['whatsappEnabled']&&''===$p['devices']['mobile']['whatsappNumber'],'explicit blank mobile number does not fall back to desktop number');
$GLOBALS['published']['product'][0]['attrs']['mobile']['settings']['whatsappNumber']='abc1234';$p=vc_live_product_settings();
check($p['whatsappEnabled']&&!$p['devices']['mobile']['whatsappEnabled'],'bad mobile number only suppresses the mobile contact');
$GLOBALS['published']['product']=[row('product_experience',['whatsappEnabled'=>false,'whatsappNumber'=>''],['whatsappEnabled'=>true,'whatsappNumber'=>'+1 555 999 8888','whatsappLabel'=>''])];$p=vc_live_product_settings();
check(!$p['whatsappEnabled']&&$p['devices']['mobile']['whatsappEnabled']&&''===$p['devices']['mobile']['whatsappLabel'],'mobile may enable contact and intentionally clear label independently');
$GLOBALS['published']['cart']=[row('context_content',[],['cart_progress_enabled'=>false,'cart_recommendations_enabled'=>false,'cart_recent_enabled'=>false,'cart_title'=>'<b>Mobile cart</b>','cart_continue_label'=>'Keep browsing'])];$c=vc_live_cart_settings();
foreach(['cart_progress_enabled','cart_recommendations_enabled','cart_recent_enabled']as$key){check($c[$key]&&$c['devices']['desktop'][$key]&&!$c['devices']['mobile'][$key],'each mobile cart feature honors explicit false');}
check('Shopping cart'===$c['cart_title']&&'Mobile cart'===$c['devices']['mobile']['cart_title']&&'Keep browsing'===$c['devices']['mobile']['cart_continue_label'],'cart title and continue text use selected device');
$GLOBALS['published']['cart']=[row('context_content',['cart_experience_enabled'=>false,'cart_progress_enabled'=>false],['cart_experience_enabled'=>true,'cart_progress_enabled'=>true])];$c=vc_live_cart_settings();
check($c['enabled']&&$c['cart_experience_enabled']&&!$c['devices']['desktop']['enabled']&&$c['devices']['mobile']['enabled'],'desktop off mobile on still loads cart controller');
check(!$c['cart_progress_enabled']&&$c['devices']['mobile']['cart_progress_enabled'],'mobile cart feature can enable desktop-disabled counterpart');
$GLOBALS['published']['cart']=[row('context_content',[],['cart_experience_enabled'=>false])];$c=vc_live_cart_settings();
check($c['enabled']&&$c['devices']['desktop']['enabled']&&!$c['devices']['mobile']['enabled'],'desktop cart survives independent mobile disable');
$GLOBALS['published']['cart']=[row('context_content',[],[],['enabled'=>false])];$c=vc_live_cart_settings();
check(!$c['enabled']&&!$c['cart_experience_enabled']&&!$c['devices']['desktop']['enabled']&&!$c['devices']['mobile']['enabled'],'native cart row disable wins');
$GLOBALS['published']['cart']=[row('context_content',['cart_experience_enabled'=>false],['cart_experience_enabled'=>true],['visibility'=>['desktop'=>true,'mobile'=>false]])];$c=vc_live_cart_settings();
check(!$c['enabled']&&!$c['cart_experience_enabled'],'hidden mobile-only cart remains disabled');
reset_fixture();$GLOBALS['published']['product']=[row('product_experience',['whatsappLabel'=>'Published label'])];$GLOBALS['published']['cart']=[row('context_content',['cart_title'=>'Published cart'])];
$_GET=['pc_template_preview'=>'123','pc_preview_token'=>'private-draft-token','pc_design_document'=>'product'];$GLOBALS['unpublished']=['whatsappLabel'=>'PRIVATE DRAFT','cart_title'=>'PRIVATE DRAFT'];
$p=vc_live_product_settings();$c=vc_live_cart_settings();
check('Published label'===$p['devices']['mobile']['whatsappLabel']&&'Published cart'===$c['devices']['mobile']['cart_title'],'preview query cannot leak unpublished product/cart configuration');
check([['product',123],['cart',123]]===$GLOBALS['published_reads'],'only published native layout snapshots were requested');
$GLOBALS['published']['product_7']=[row('product_experience',[],[],['enabled'=>false])];$p=vc_live_product_settings(7);
check('product_7'===$p['document']&&!$p['enabled'],'product-specific disabled published row does not fall through to generic product');
$p=vc_live_product_settings(8);check('product'===$p['document']&&$p['enabled'],'missing product-specific row inherits generic published product');
check(!isset($p['devices']['mobile']['devices'])&&!isset($c['devices']['mobile']['devices']),'device DTOs do not recurse');
echo "$passed published commerce device checks passed\n";
}
