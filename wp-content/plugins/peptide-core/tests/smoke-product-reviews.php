<?php
/** Local regression for review CRUD, permissions, source provenance and public moderation. */
defined('ABSPATH') || exit;
use PeptideCore\Reviews;
use PeptideCore\Staff;
use PeptideCore\Modules\Commerce_Blocks;
if(!defined('WP_CLI') || !WP_CLI || !in_array(wp_get_environment_type(),array('local','development'),true)){throw new RuntimeException('Local CLI only');}
$pass=0;$fail=0;$ok=static function($label,$yes)use(&$pass,&$fail){$yes?++$pass:++$fail;echo($yes?'PASS ':'FAIL ').$label."\n";};
$admin=(int)(get_users(array('role'=>'administrator','number'=>1,'fields'=>'ID'))[0]??0);$before_user=get_current_user_id();$old_post=$_POST;$old_server=$_SERVER;$tag='pcreview-'.substr(wp_generate_uuid4(),0,10);$comments=array();$users=array();$orders=array();$product=0;
$no_mail=static fn()=>true;add_filter('pre_wp_mail',$no_mail);
$native_options=static fn($value)=>$value;
$registration=static fn()=>0;$verification=static fn()=>'no';add_filter('pre_option_comment_registration',$registration);add_filter('pre_option_woocommerce_review_rating_verification_required',$verification);
try{
 wp_set_current_user($admin);
 $p=new WC_Product_Simple();$p->set_name($tag);$p->set_status('publish');$p->set_regular_price('12');$p->set_reviews_allowed(true);$product=$p->save();
 $base=array('product'=>$product,'author'=>'Synthetic reviewer','email'=>$tag.'@example.invalid','rating'=>'4','content'=>"Synthetic review: it's clear.",'source'=>'store','status'=>'0');
 $id=Reviews::save_admin($base);if(!is_wp_error($id))$comments[]=$id;
 $ok('administrator can add a pending store review',is_int($id)&&$id>0&&'0'===get_comment($id)->comment_approved);
 $ok('manual review keeps exact quote text',!is_wp_error($id)&&get_comment($id)->comment_content===$base['content']);
 $ok('store source remains visible to staff and is never caller-verified',get_comment_meta($id,Reviews::SOURCE,true)==='store'&&!wc_review_is_from_verified_owner($id)&&str_contains(Reviews::source_html($id,true),'Store entry'));
 $ok('public source renderer reveals no internal origin or editor label',Reviews::source_html($id)==='');
 $ok('pending review is not counted in product rating',(float)wc_get_product($product)->get_average_rating()===0.0);
 $edit=$base+array('id'=>$id);$edit['status']='1';$saved=Reviews::save_admin($edit);
 $ok('approval updates actual Woo rating and count',$saved===$id&&'1'===get_comment($id)->comment_approved&&(float)wc_get_product($product)->get_average_rating()===4.0&&wc_get_product($product)->get_review_count()===1);
 $edit['content']='Synthetic edited review';$edit['rating']='2';$edit['source']='buyer';Reviews::save_admin($edit);
 $ok('editor updates text and star aggregate without falsifying source',get_comment($id)->comment_content===$edit['content']&&get_comment_meta($id,'rating',true)==2&&(float)wc_get_product($product)->get_average_rating()===2.0&&get_comment_meta($id,Reviews::SOURCE,true)==='store');
 $ok('review saves retain operator audit history',count((array)get_comment_meta($id,'_pc_review_changes',true))===3);
 $import=$base;$import['source']='imported';$import['content']='Synthetic historical import';$imported=Reviews::save_admin($import);if(!is_wp_error($imported))$comments[]=$imported;
 $ok('historical import has distinct truthful provenance',is_int($imported)&&str_contains(Reviews::source_html($imported,true),'Imported review'));
 $bad=$base;$bad['source']='buyer';$ok('new admin entry cannot impersonate buyer submission',is_wp_error(Reviews::save_admin($bad)));
 foreach(array('0','6','2.5','-1')as$rating){$bad=$base;$bad['rating']=$rating;$ok('invalid rating '.$rating.' rejected',is_wp_error(Reviews::save_admin($bad)));}
 $bad=$base;$bad['date']='2099-01-01T12:00';$ok('future review date rejected',is_wp_error(Reviews::save_admin($bad)));
 $bad=$base;$bad['product']=0;$ok('missing product rejected',is_wp_error(Reviews::save_admin($bad)));
 $bad=$base;$bad['video_id']=$product;$ok('non-media video reference rejected',is_wp_error(Reviews::save_admin($bad)));
 $bad=$base;$bad['image_ids']=(string)$product;$ok('non-image media reference rejected',is_wp_error(Reviews::save_admin($bad)));
 $fake=array('pc_review_photos'=>array('name'=>array('fake.jpg'),'tmp_name'=>array(__FILE__),'size'=>array(100),'error'=>array(0)));
 $ok('arbitrary local paths are never accepted as uploaded files',is_wp_error(Reviews::validate_uploads($fake)));
 $fake=array('pc_review_photos'=>array('name'=>array_fill(0,6,'a.jpg'),'error'=>array_fill(0,6,0)));
 $ok('more than five photos rejected before persistence',is_wp_error(Reviews::validate_uploads($fake)));
 foreach(array('products','marketing','data')as$module){$u=wp_insert_user(array('user_login'=>$tag.'-'.$module,'user_pass'=>wp_generate_password(32),'role'=>Staff::ROLE));if(is_wp_error($u))throw new RuntimeException('Fixture user failed');$users[]=$u;Staff::set_modules($u,array($module));wp_set_current_user($u);$r=Reviews::save_admin($base);if(!is_wp_error($r))$comments[]=$r;$ok($module.' staff review write permission is exact',('products'===$module)===!is_wp_error($r));}
 wp_set_current_user(0);$ok('guest cannot use review administration',is_wp_error(Reviews::save_admin($base)));
 $ok('explicit admin source context still checks staff permission',Reviews::source_html($id,true)==='');
 $customer=wp_insert_user(array('user_login'=>$tag.'-buyer','user_email'=>$tag.'-buyer@example.invalid','user_pass'=>wp_generate_password(32),'role'=>'customer'));if(is_wp_error($customer))throw new RuntimeException('Fixture customer failed');$users[]=$customer;wp_set_current_user($customer);
 $input=array('comment_post_ID'=>$product,'comment'=>'Synthetic buyer submission '.$tag,'rating'=>'5','pc_review_nonce'=>wp_create_nonce('pc_review_'.$product));
 $bad=$input;$bad['pc_review_nonce']='invalid';$ok('public submission requires product-scoped nonce',is_wp_error(Reviews::submit($bad)));
 $bad=$input;$bad['pc_review_website']='spam';$ok('public honeypot rejects bots',is_wp_error(Reviews::submit($bad)));
 $bad=$input;$bad['rating']='6';$ok('public invalid rating rejected',is_wp_error(Reviews::submit($bad)));
 $bad=$input;$bad['comment']='';$ok('public empty review rejected',is_wp_error(Reviews::submit($bad)));
 $_SERVER['REMOTE_ADDR']='127.0.0.231';$submitted=Reviews::submit($input);if(!is_wp_error($submitted))$comments[]=$submitted['id'];
 $ok('buyer text submission enters pending even with trusted account',!is_wp_error($submitted)&&'0'===get_comment($submitted['id'])->comment_approved&&get_comment_meta($submitted['id'],Reviews::SOURCE,true)==='buyer');
 if(is_wp_error($submitted)){echo 'DETAIL '.esc_html($submitted->get_error_message())."\n";}
 $ok('non-purchasing reviewer never gets verified badge',!is_wp_error($submitted)&&!wc_review_is_from_verified_owner($submitted['id']));
 wp_set_current_user($admin);
 if(!is_wp_error($submitted)){$buyer_id=$submitted['id'];$entry=get_comment($buyer_id);$change=array('id'=>$buyer_id,'product'=>$product,'author'=>$entry->comment_author,'email'=>$entry->comment_author_email,'rating'=>'3','content'=>$entry->comment_content,'date'=>$entry->comment_date,'status'=>'0');Reviews::save_admin($change);$ok('editing buyer star rating preserves source and marks store edit',get_comment_meta($buyer_id,Reviews::SOURCE,true)==='buyer'&&get_comment_meta($buyer_id,'_pc_review_store_edited',true)==='1');}
 $paid=wp_insert_user(array('user_login'=>$tag.'-paid','user_email'=>$tag.'-paid@example.invalid','user_pass'=>wp_generate_password(32),'role'=>'customer'));if(is_wp_error($paid))throw new RuntimeException('Paid fixture customer failed');$users[]=$paid;
 $order=wc_create_order(array('customer_id'=>$paid,'created_via'=>'pc-review-smoke'));$orders[]=$order->get_id();$order->set_billing_email($tag.'-paid@example.invalid');$order->add_product(wc_get_product($product),1);$order->set_payment_method('cod');$order->calculate_totals();$order->set_status('completed');$order->save();
 wp_set_current_user($paid);$_SERVER['REMOTE_ADDR']='127.0.0.232';$paid_input=array('comment_post_ID'=>$product,'comment'=>'Synthetic purchased buyer '.$tag,'rating'=>'4','pc_review_nonce'=>wp_create_nonce('pc_review_'.$product));$paid_review=Reviews::submit($paid_input);if(!is_wp_error($paid_review))$comments[]=$paid_review['id'];
 $ok('verified badge requires an authenticated matching purchaser',!is_wp_error($paid_review)&&wc_review_is_from_verified_owner($paid_review['id']));
 wp_set_current_user(0);$_SERVER['REMOTE_ADDR']='127.0.0.233';$guest_input=$paid_input;$guest_input['author']='Synthetic guest';$guest_input['email']=$tag.'-paid@example.invalid';$guest_input['comment']='Synthetic guest email impersonation '.$tag;$guest_input['pc_review_nonce']=wp_create_nonce('pc_review_'.$product);
 // This assertion tests identity verification, independently of native same-email flood throttling.
 $guest_flood=static fn($flood,$ip,$email)=>$email===$tag.'-paid@example.invalid'?false:$flood;add_filter('wp_is_comment_flood',$guest_flood,PHP_INT_MAX,3);
 try{$guest_review=Reviews::submit($guest_input);}finally{remove_filter('wp_is_comment_flood',$guest_flood,PHP_INT_MAX);}
 if(!is_wp_error($guest_review))$comments[]=$guest_review['id'];
 $ok('guest cannot gain a verified badge by typing a purchasers email',!is_wp_error($guest_review)&&!wc_review_is_from_verified_owner($guest_review['id']));
 if(is_wp_error($guest_review)){echo 'DETAIL guest submission '.$guest_review->get_error_code().' '.$guest_review->get_error_message()."\n";}
 wp_set_current_user($admin);$manual=$base;$manual['email']=$tag.'-paid@example.invalid';$manual['verified']='1';$manual['content']='Synthetic store entered purchased email '.$tag;$manual_id=Reviews::save_admin($manual);if(!is_wp_error($manual_id))$comments[]=$manual_id;
 $ok('manual verified input never creates a purchase badge',is_int($manual_id)&&!wc_review_is_from_verified_owner($manual_id));
 wp_set_current_user(0);remove_filter('pre_option_woocommerce_review_rating_verification_required',$verification);$required=static fn()=>'yes';add_filter('pre_option_woocommerce_review_rating_verification_required',$required);$input['pc_review_nonce']=wp_create_nonce('pc_review_'.$product);$ok('verified-purchase-only policy enforced server-side',is_wp_error(Reviews::submit($input)));remove_filter('pre_option_woocommerce_review_rating_verification_required',$required);add_filter('pre_option_woocommerce_review_rating_verification_required',$verification);
 remove_filter('pre_option_comment_registration',$registration);$registered=static fn()=>1;add_filter('pre_option_comment_registration',$registered);$ok('native sign-in requirement enforced for guests',is_wp_error(Reviews::submit($input)));remove_filter('pre_option_comment_registration',$registered);add_filter('pre_option_comment_registration',$registration);
 wp_set_current_user($admin);$p=wc_get_product($product);$p->set_reviews_allowed(false);$p->save();$input['pc_review_nonce']=wp_create_nonce('pc_review_'.$product);$ok('closed product rejects reviews',is_wp_error(Reviews::submit($input)));
 $ok('dedicated admin route maps to product module',Staff::module_for('admin.php','pc-product-reviews')==='products');
}finally{
 wp_set_current_user($admin);foreach(array_unique($comments)as$id){wp_delete_comment($id,true);}foreach($orders as$oid){$o=wc_get_order($oid);if($o)$o->delete(true);}if($product){$p=wc_get_product($product);if($p)$p->delete(true);}
 require_once ABSPATH.'wp-admin/includes/user.php';foreach($users as$u){foreach(array('127.0.0.231','127.0.0.232','127.0.0.233')as$ip){delete_transient('pc_review_rate_'.hash_hmac('sha256',$ip.'|'.$u,wp_salt()));}wp_delete_user($u);}delete_transient('pc_review_rate_'.hash_hmac('sha256','127.0.0.233|0',wp_salt()));
 remove_filter('pre_wp_mail',$no_mail);remove_filter('pre_option_comment_registration',$registration);remove_filter('pre_option_woocommerce_review_rating_verification_required',$verification);$_POST=$old_post;$_SERVER=$old_server;wp_set_current_user($before_user);
}
$ok('review fixture comments and users cleaned',!array_filter($comments,static fn($id)=>get_comment($id)!==null)&&!array_filter($users,static fn($id)=>get_userdata($id)!==false));
echo "Result: $pass passed, $fail failed\n";if($fail){throw new RuntimeException('Review smoke failed');}
