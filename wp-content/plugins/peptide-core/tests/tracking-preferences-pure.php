<?php
namespace PeptideCore\Tracking { final class Tracker { public const IDLE_MS = 60000; } }
namespace {
 define( 'ABSPATH', __DIR__ ); require dirname( __DIR__ ) . '/includes/tracking/class-labels.php'; require dirname( __DIR__ ) . '/includes/tracking/class-preferences.php';
 $passed=0; $check=static function($label,$yes)use(&$passed){if(!$yes)throw new \RuntimeException($label);++$passed;};
 $row=static function($at,$type='heartbeat',$data=[],$tab='a',$pid=0,$path='/'){
  return ['id'=>$at,'visitor_id'=>1,'visit_id'=>1,'tab_id'=>$tab,'page_instance_id'=>'page','seq'=>$at,'type'=>$type,'source'=>'client','occurred_ms'=>$at,'page_type'=>'home','path'=>$path,'product_id'=>$pid,'data'=>json_encode($data+['visibility'=>'visible','activity'=>'active','last_activity_age_ms'=>0])];
 };
 $sum=static fn($rows)=>\PeptideCore\Tracking\Preferences::summarize($rows,1000,200000);
 $r=$sum([]);$check('empty observations do not invent dwell',$r['summary']['active_seconds']===0.0);
 $r=$sum([$row(1000,'page_view'),$row(16000),$row(31000,'page_exit')]);$check('presence creates two observed active intervals',$r['summary']['active_seconds']===30.0);$check('single real pageview only',$r['summary']['pageviews']===1);
 $r=$sum([$row(1000,'page_view'),$row(61000)]);$check('large gaps never become reading time',$r['summary']['active_seconds']===0.0);
 $r=$sum([$row(1000,'page_view',['visibility'=>'hidden']),$row(16000)]);$check('hidden tabs add no dwell',$r['summary']['active_seconds']===0.0);
 $r=$sum([$row(1000,'page_view',['activity'=>'idle']),$row(16000)]);$check('idle tabs add no dwell',$r['summary']['active_seconds']===0.0);
 $r=$sum([$row(1000,'page_view'),$row(1000,'page_view',[],'b'),$row(16000),$row(16000,'heartbeat',[],'b')]);$check('overlapping tabs union total',$r['summary']['active_seconds']===15.0);$check('overlapping same-page tabs union page dwell',$r['pages'][0]['active_seconds']===15.0);
 $r=$sum([$row(1000,'page_view'),$row(16000,'visibility_change',['visibility'=>'hidden']),$row(31000)]);$check('visibility boundary closes previous visible interval only',$r['summary']['active_seconds']===15.0);
 $r=$sum([$row(1000,'page_view'),$row(16000,'heartbeat',['activity'=>'idle','last_activity_age_ms'=>70000])]);$check('idle boundary is clipped using activity age',$r['summary']['active_seconds']===5.0);
 $r=$sum([$row(1000,'product_click',[], 'a',42),$row(1001,'product_click',[],'a',42),$row(1002,'product_view',[],'a',42)]);$check('clicks and views are separate facts',$r['products'][0]['clicks']===2&&$r['products'][0]['views']===1);$check('click counts never become dwell',$r['products'][0]['active_seconds']===0.0);
 $r=$sum([$row(1000,'module_click',['module_id'=>'vc-safe','module_type'=>'faq']),$row(1001,'module_click',['module_id'=>'<unsafe>'])]);$check('only structured module identifiers are ranked',count($r['modules'])===1&&$r['modules'][0]['module_id']==='vc-safe');
 $r=$sum([$row(1000,'page_view',[],'a',0,'/safe/?email=private')]);$check('query strings are excluded',$r['pages'][0]['path']==='/safe/');
 $private=$row(1000,'page_view',[],'a',0,'/account/private');$private['page_type']='account';$r=$sum([$private]);$check('private account route identifiers are redacted',$r['pages'][0]['path']==='[account]');
 $r=$sum([$row(999,'page_view'),$row(200000,'page_view')]);$check('period bounds are half open',$r['summary']['pageviews']===0);
 $r=$sum([$row(1000,'add_to_cart',[],'a',42)]);$check('unverified client add is not a successful add',$r['products'][0]['cart_additions']===0);
 $server=$row(1000,'add_to_cart',[],'a',42);$server['source']='server';$r=$sum([$server]);$check('server add fact is counted',$r['products'][0]['cart_additions']===1);
 echo json_encode(['passed'=>$passed,'failed'=>0]);
}
