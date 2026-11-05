<?php
namespace PeptideCore {
 class Post_Types {const BATCH='pc_batch';}
 class Meta {const BATCH_PRODUCT_ID='_product';const BATCH_VISIBILITY='_visibility';}
}
namespace {
 define('ABSPATH',__DIR__);$blog=1;$queries=0;$hooks=[];
 $batches=[(object)['ID'=>12,'parent'=>1,'status'=>'publish','visibility'=>'public'],(object)['ID'=>11,'parent'=>1,'status'=>'publish','visibility'=>'public'],(object)['ID'=>10,'parent'=>2,'status'=>'draft','visibility'=>'public'],(object)['ID'=>9,'parent'=>2,'status'=>'publish','visibility'=>'private']];
 function absint($v){return abs((int)$v);}function get_current_blog_id(){return $GLOBALS['blog'];}
 function add_action($name,$callback,...$args){$GLOBALS['hooks'][$name][]=$callback;}
 function get_post_meta($id,...$args){foreach($GLOBALS['batches'] as $b)if($b->ID===$id)return $b->parent;return 0;}
 function get_posts($args){++$GLOBALS['queries'];if($args['post_status']!=='publish'||$args['orderby']!=='date'||$args['order']!=='DESC'||$args['meta_query'][0]['type']!=='NUMERIC'||$args['meta_query'][1]['value']!=='public')throw new \RuntimeException('public predicate/order changed');$ids=(array)$args['meta_query'][0]['value'];$rows=array_values(array_filter($GLOBALS['batches'],fn($b)=>in_array($b->parent,$ids,true)&&$b->status==='publish'&&$b->visibility==='public'));return ($args['fields']??'')==='ids'?array_column($rows,'ID'):$rows;}
 require dirname(__DIR__).'/includes/class-catalog.php';
 function check($v,$m){if(!$v)throw new \RuntimeException($m);}
 \PeptideCore\Catalog::prime_public_batch_ids([1,2]);check($queries===1,'one query for multiple parents');
 check(\PeptideCore\Catalog::public_batch_ids(1)===[12,11],'native descending order preserved');
 check(\PeptideCore\Catalog::public_batch_ids(2)===[]&&$queries===1,'draft/private excluded, negative cached');
 \PeptideCore\Catalog::prime_public_batch_ids([1,2]);check($queries===1,'repeat coalesced');
 $blog=2;\PeptideCore\Catalog::public_batch_ids(1);check($queries===2,'blog scoped');$blog=1;
 $batches[0]->visibility='private';foreach($hooks['updated_post_meta'] as $h)$h();check(\PeptideCore\Catalog::public_batch_ids(1)===[11],'unpublication/visibility edit immediately retires primed public result');
 \PeptideCore\Catalog::prime_public_batch_ids([2]);$batches[2]->status='publish';foreach($hooks['transition_post_status'] as $h)$h();check(\PeptideCore\Catalog::public_batch_ids(2)===[10],'publish invalidates negative result');
 \PeptideCore\Catalog::prime_public_batch_ids([1]);$batches=[];foreach($hooks['deleted_post'] as $h)$h();check(\PeptideCore\Catalog::public_batch_ids(1)===[],'delete invalidates positive result');
 echo "8 native public-batch prime checks passed.\n";
}
