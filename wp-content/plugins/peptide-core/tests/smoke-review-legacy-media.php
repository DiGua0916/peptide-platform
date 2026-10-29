<?php
/** Existing reviews with more photos must never lose media when their text is edited. */
defined('ABSPATH') || exit;
if(!defined('WP_CLI')||!WP_CLI||!in_array(wp_get_environment_type(),array('local','development'),true)){throw new RuntimeException('Local CLI only');}
use PeptideCore\Reviews;
use PeptideCore\Modules\Commerce_Blocks;
$before=get_current_user_id();$admin=(int)(get_users(array('role'=>'administrator','number'=>1,'fields'=>'ID'))[0]??0);$media=array();$files=array();$comments=array();$product=0;$fail=0;$no_mail=static fn()=>true;add_filter('pre_wp_mail',$no_mail);
$ok=static function($label,$yes)use(&$fail){if(!$yes)++$fail;echo($yes?'PASS ':'FAIL ').$label."\n";};
try{
 wp_set_current_user($admin);$p=new WC_Product_Simple();$p->set_name('SYNTHETIC legacy review media '.wp_generate_uuid4());$p->set_status('publish');$p->set_regular_price('1');$product=$p->save();
 for($i=0;$i<6;++$i){$file='D:/Codex/Tasks/2026-10-28/new-chat/work/synthetic-legacy-'.wp_generate_uuid4().'.png';file_put_contents($file,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWuQAAAAASUVORK5CYII='));$files[]=$file;$attachment=wp_insert_attachment(array('post_title'=>'SYNTHETIC legacy media','post_status'=>'inherit','post_mime_type'=>'image/png'));update_attached_file($attachment,$file);$media[]=$attachment;}
 $id=wp_insert_comment(array('comment_post_ID'=>$product,'comment_author'=>'Synthetic imported reviewer','comment_content'=>'Synthetic old review','comment_type'=>'review','comment_approved'=>'0'));$comments[]=$id;
 update_comment_meta($id,Commerce_Blocks::REVIEW_IMAGES,$media);update_comment_meta($id,'rating',3);update_comment_meta($id,Reviews::SOURCE,'imported');
 $input=array('id'=>$id,'product'=>$product,'author'=>'Synthetic imported reviewer','content'=>'Synthetic edited old review','rating'=>'4','status'=>'0','image_ids'=>implode(',',$media));$result=Reviews::save_admin($input);
 if(is_wp_error($result)){echo 'DETAIL '.$result->get_error_code().' '.$result->get_error_message()."\n";}
 $ok('editing a legacy review preserves all six existing images',$result===$id&&array_map('intval',(array)get_comment_meta($id,Commerce_Blocks::REVIEW_IMAGES,true))===$media);
 $ok('legacy text and rating can change without media truncation',get_comment($id)->comment_content===$input['content']&&(int)get_comment_meta($id,'rating',true)===4);
 unset($input['id']);$input['source']='store';$rejected=Reviews::save_admin($input);if(!is_wp_error($rejected))$comments[]=$rejected;
 $ok('new review cannot silently truncate an oversized image selection',is_wp_error($rejected));
}finally{wp_set_current_user($admin);foreach($comments as$id){wp_delete_comment($id,true);}foreach($media as$id){wp_delete_attachment($id,true);}foreach($files as$file){if(is_file($file)){unlink($file);}}if($product&&wc_get_product($product)){wc_get_product($product)->delete(true);}wp_set_current_user($before);remove_filter('pre_wp_mail',$no_mail);}
echo 'Result: '.(3-$fail).' passed, '.$fail." failed\n";
if($fail){throw new RuntimeException('Legacy review media preservation failed');}
