<?php
/** Request/router contract; isolated native boundaries, no bootstrap or actual database. */
declare( strict_types=1 );
namespace PeptideCore { final class Templates { public static function previewing_id(){return $GLOBALS['entry']['preview_id']??0;} } }
namespace PeptideCore\Modules {
 final class Products { public static function public_catalog_product($product){return $product instanceof \WC_Product && !empty($product->public);} }
 final class Showcase_Quick_View { public static function supports($product){return $product instanceof \WC_Product && !empty($product->supported);} }
}
namespace {
 if(PHP_SAPI!=='cli')exit(2);define('ABSPATH',__DIR__);$checks=0;$hooks=[];$GLOBALS['writes']=0;
 class WC_Product { public function __construct(public bool $public=true,public bool $supported=true){} }
 function reset_entry(array $changes=[]):void{$GLOBALS['entry']=array_replace(['admin'=>false,'ajax'=>false,'cron'=>false,'front'=>false,'shop'=>false,'product'=>false,'category'=>false,'search'=>false,'cart'=>false,'checkout'=>false,'feed'=>false,'embed'=>false,'trackback'=>false,'preview'=>false,'preview_id'=>0,'id'=>37,'public'=>true,'supported'=>true,'enabled'=>true,'mobileDialog'=>true,'mobile'=>false,'visibility'=>['desktop'=>true,'mobile'=>true],'query'=>'','post_type'=>'product'], $changes);$_GET=[];$_SERVER=['REQUEST_METHOD'=>'GET','REQUEST_URI'=>'/shop/'];}
 function check_entry($name,$okay){global $checks;if(!$okay)throw new \RuntimeException('FAIL: '.$name);$checks++;}
 function is_admin(){return $GLOBALS['entry']['admin'];}function wp_doing_ajax(){return $GLOBALS['entry']['ajax'];}function wp_doing_cron(){return $GLOBALS['entry']['cron'];}
 function is_front_page(){return $GLOBALS['entry']['front'];}function is_shop(){return $GLOBALS['entry']['shop'];}function is_product(){return $GLOBALS['entry']['product'];}function is_product_category(){return $GLOBALS['entry']['category'];}function is_search(){return $GLOBALS['entry']['search'];}
 function is_cart(){return $GLOBALS['entry']['cart'];}function is_checkout(){return $GLOBALS['entry']['checkout'];}function is_feed(){return $GLOBALS['entry']['feed'];}function is_embed(){return $GLOBALS['entry']['embed'];}function is_trackback(){return $GLOBALS['entry']['trackback'];}function is_preview(){return $GLOBALS['entry']['preview'];}
 function wp_is_mobile(){return $GLOBALS['entry']['mobile'];}function get_query_var($key){return $GLOBALS['entry'][$key]??'';}function get_queried_object_id(){return $GLOBALS['entry']['id'];}function get_search_query($escaped=true){return $GLOBALS['entry']['query'];}
 function vc_live_product_settings($id=0){return array_intersect_key($GLOBALS['entry'],array_flip(['enabled','mobileDialog','visibility','devices']));}function wc_get_product($id){return $id===37?new WC_Product($GLOBALS['entry']['public'],$GLOBALS['entry']['supported']):null;}
 function wp_parse_url($url,$part){return parse_url($url,$part);}function home_url($path){return 'https://store.test'.$path;}function add_query_arg($args,$url){return $url.'?'.http_build_query($args);}function wp_unslash($value){return stripslashes($value);}if(!function_exists('mb_substr')){function mb_substr($s,$a,$b){return substr($s,$a,$b);}}
 function add_action($hook,$callback,$priority=10){$GLOBALS['hooks'][$hook][$priority]=$callback;}function get_template_directory(){return dirname(__DIR__);}function update_option(...$args){$GLOBALS['writes']++;throw new \RuntimeException('Forbidden write');}
 require dirname(__DIR__).'/inc/storefront-entry.php';
 reset_entry(['shop'=>true]);$url=vc_storefront_entry_destination();check_entry('shop is temporary home catalogue destination',str_starts_with($url,'https://store.test/')&&str_ends_with($url,'#vc-products'));
 reset_entry(['product'=>true]);$url=vc_storefront_entry_destination();check_entry('published supported PDP id preserved',str_contains($url,'vc_product=37'));
 $_GET=['pc_lang'=>'es','pc_lang_auto'=>'1','attribute_pa_pack'=>'20-mg','utm_source'=>'Meta Campaign'];$url=vc_storefront_entry_destination();parse_str(parse_url($url,PHP_URL_QUERY),$q);check_entry('language and auto marker preserved',$q['pc_lang']==='es'&&$q['pc_lang_auto']==='1');check_entry('native variation label query preserved',$q['attribute_pa_pack']==='20-mg');check_entry('campaign attribution kept',$q['utm_source']==='Meta Campaign');
 reset_entry(['category'=>true,'id'=>12,'query'=>'TR 20']);$url=vc_storefront_entry_destination();parse_str(parse_url($url,PHP_URL_QUERY),$q);check_entry('category and product search semantic ids retained',$q['vc_catalog_category']==='12'&&$q['vc_catalog_q']==='TR 20'&&!isset($q['s']));
 reset_entry(['search'=>true,'query'=>'Amino 1MQ']);check_entry('product-only search restored',str_contains(vc_storefront_entry_destination(),'vc_catalog_q=Amino+1MQ'));
 reset_entry(['search'=>true,'query'=>'About','post_type'=>'page']);check_entry('general site search untouched',vc_storefront_entry_destination()==='');
 foreach(['POST','PUT','PATCH','DELETE','OPTIONS']as$method){reset_entry(['shop'=>true]);$_SERVER['REQUEST_METHOD']=$method;check_entry('non-read route untouched',vc_storefront_entry_destination()==='');}
 reset_entry(['shop'=>true]);$_SERVER['REQUEST_METHOD']='HEAD';check_entry('normal HEAD remains safe redirect candidate',vc_storefront_entry_destination()!=='');
 foreach(['admin','ajax','cron','cart','checkout','feed','embed','trackback','preview','front']as$key){reset_entry(['shop'=>true,$key=>true]);check_entry('excluded context '.$key,vc_storefront_entry_destination()==='');}
 reset_entry(['shop'=>true,'preview_id'=>10]);check_entry('builder preview untouched',vc_storefront_entry_destination()==='');
 foreach(['/wp-admin/admin.php','/wp-login.php','/wp-json/anything','/checkout/','/checkout/order-pay/1/','/order-received/1/','/cart/','/product/foo/feed/','/%252fcheckout/','/product/\\checkout/']as$uri){reset_entry(['shop'=>true]);$_SERVER['REQUEST_URI']=$uri;check_entry('path exclusion '.$uri,vc_storefront_entry_destination()==='');}
 foreach(['rest_route','wc-ajax','wc-api','add-to-cart','remove_item','undo_item','quantity','action','key','order_key','_wpnonce','_ajax_nonce','csrfToken','signature','pc_payment_return','pc_panapay_return','payment-return','preview','pc_template_preview','customize_theme','redirect_to']as$key){reset_entry(['product'=>true]);$_GET=[$key=>'test'];check_entry('signed/action query exclusion '.$key,vc_storefront_entry_destination()==='');}
 foreach([['public'=>false],['supported'=>false],['enabled'=>false],['mobileDialog'=>false],['visibility'=>['desktop'=>false,'mobile'=>true]],['id'=>99]]as$change){reset_entry(array_replace(['product'=>true],$change));check_entry('disabled/private/unavailable product stays native',vc_storefront_entry_destination()==='');}
 reset_entry(['product'=>true,'mobile'=>true,'visibility'=>['desktop'=>true,'mobile'=>false]]);check_entry('disabled current mobile dialog stays native',vc_storefront_entry_destination()==='');
 $devices=['desktop'=>['enabled'=>false,'mobileDialog'=>false],'mobile'=>['enabled'=>true,'mobileDialog'=>true]];
 reset_entry(['product'=>true,'devices'=>$devices]);check_entry('aggregate enabled does not redirect disabled desktop',vc_storefront_entry_destination()==='');
 check_entry('mobile-only settings still load cross-device presentation assets',vc_storefront_entry_enabled(37,true));
 reset_entry(['product'=>true,'mobile'=>true,'devices'=>$devices]);check_entry('mobile-only native dialog can redirect on mobile',vc_storefront_entry_destination()!=='');
 $devices=['desktop'=>['enabled'=>true,'mobileDialog'=>true],'mobile'=>['enabled'=>false,'mobileDialog'=>true]];
 reset_entry(['product'=>true,'mobile'=>true,'devices'=>$devices]);check_entry('device visibility wins over aggregate loader flags',vc_storefront_entry_destination()==='');
 reset_entry(['product'=>true,'devices'=>$devices]);check_entry('desktop device remains available',vc_storefront_entry_destination()!=='');
 reset_entry(['product'=>true,'devices'=>[]]);check_entry('incomplete device contract cannot borrow aggregate permissions',vc_storefront_entry_destination()==='');
 check_entry('incomplete device contract does not load experience assets',!vc_storefront_entry_enabled(37,true));
 reset_entry(['shop'=>true]);$_GET=['pc_lang'=>['es'],'pc_lang_auto'=>'invalid'];$url=vc_storefront_entry_destination();check_entry('invalid language is not coerced',!str_contains($url,'pc_lang'));
 check_entry('source release is stable public digest',preg_match('/\A[a-f0-9]{64}\z/',vc_storefront_entry_release())===1&&vc_storefront_entry_release()===vc_storefront_entry_release());
 check_entry('no actual business writes',0===$GLOBALS['writes']);
 echo json_encode(['passed'=>$checks,'actualDbWrites'=>0,'bootstrap'=>false],JSON_PRETTY_PRINT).PHP_EOL;
}
