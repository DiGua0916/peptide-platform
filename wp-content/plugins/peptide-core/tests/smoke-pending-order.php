<?php
// Site 1 P1-1 pending-order data. Run: wp eval-file tests/smoke-pending-order.php (creates and deletes a test user and two orders).
use PeptideCore\Pending_Order as P;
$ok=0;$n=0;$t=function($c,$m)use(&$ok,&$n){$n++; if($c){$ok++;} else echo "FAIL $m\n";};
$now=time();
$t(P::qualifies('pending',true,$now-60,$now),'pending fresh');
$t(P::qualifies('failed',true,$now-60,$now),'failed fresh');
$t(!P::qualifies('checkout-draft',true,$now-60,$now),'draft excluded');
$t(!P::qualifies('pending',true,$now-73*3600,$now),'old excluded');
$t(!P::qualifies('processing',false,$now-60,$now),'paid excluded');
$uid = wp_insert_user(['user_login'=>'laneb_po_'.wp_rand(),'user_pass'=>wp_generate_password(),'user_email'=>'laneb'.wp_rand().'@example.test']);
wp_set_current_user($uid);
$o = wc_create_order(['customer_id'=>$uid]); $o->add_product(wc_get_product(10),2); $o->calculate_totals(); $o->update_status('failed');
$r = P::for_visitor();
$t($r && $r['order_id']===$o->get_id() && $r['state']==='failed' && $r['items']===2 && str_contains($r['pay_url'],'order-pay') ,'failed order found '.json_encode($r));
$t(!isset($r['email']) && !isset($r['address']), 'no personal data');
sleep(1);
$p = wc_create_order(['customer_id'=>$uid]); $p->add_product(wc_get_product(10),1); $p->calculate_totals(); $p->update_status('processing');
$t(null===P::for_visitor(),'hidden after a later paid order');
wp_set_current_user(0);
$t(null===P::for_visitor(),'guest without session order sees nothing');
$o->delete(true); $p->delete(true); require_once ABSPATH.'wp-admin/includes/user.php'; wp_delete_user($uid);
echo "pending-order $ok/$n\n";
