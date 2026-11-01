<?php
/** Runs the approved native mail/MIME 037 suite once, then new plain-source contracts. */
declare(strict_types=1);
require __DIR__.'/mail-observations-standalone.php';
use PeptideCore\Emails\Message_Observations as Mail047;
use PeptideCore\Emails\Observation_Store as Store047;
$before047=$passed;
$options037[Mail047::ENABLED]=true;$options037[Store047::SCHEMA]='1';
$plain047="Synthetic credential-bearing confirmation\nhttps://store.example.invalid/?pc_subscription=confirm&subscriber=77&token=".str_repeat('b',64)."\nhttps://store.example.invalid/?pc_subscription=unsubscribe&subscriber=77&token=".str_repeat('c',64);
foreach(array('subscription'=>'confirmation','loyalty'=>'points_notice') as $family047=>$key047){
	$args047=array('customer@example.invalid','Synthetic plain subject',$plain047,array('Content-Type: text/plain; charset=UTF-8','From: source@example.invalid'));
	$options037[Mail047::ENABLED]=false;wp_mail(...$args047);$base047=$phpmailer->captures[array_key_last($phpmailer->captures)];$options037[Mail047::ENABLED]=true;
	$linkcount047=count($wpdb->links);$result047=Mail047::send('wp_mail',$args047,array('family'=>$family047,'key'=>$key047,'attempt'=>'77:1'));$row047=last037();$capture047=$phpmailer->captures[array_key_last($phpmailer->captures)];
	check037('new source accepted by actual native transport '.$family047,$result047===true && $row047['family']===$family047 && $row047['source_key']===$key047 && $row047['status']==='accepted');
	check037('plain source body hash remains native exact '.$family047,hash('sha256',$capture047['body'])===hash('sha256',$base047['body']) && $capture047['body']===$plain047 && $capture047['alt']===$base047['alt']);
	check037('native MIME remains plain without marker/pixel/rewrite '.$family047,str_contains($capture047['mime'],'Content-Type: text/plain;') && !str_contains($capture047['mime'],'X-Pc-Mail-Observation') && !str_contains($capture047['mime'],'pc_mail_') && count($wpdb->links)===$linkcount047);
	check037('plain source coverage and sender facts honest '.$family047,$row047['coverage']==='plain_no_observations' && $row047['sender']==='source@example.invalid' && $row047['recipient_count']===1 && !$row047['open_eligible'] && !$row047['click_eligible']);
	check037('source metadata stores no body subject or plaintext credential '.$family047,!isset($row047['body'],$row047['subject'],$row047['email']) && !str_contains(json_encode($row047),str_repeat('b',64)) && !str_contains(json_encode($row047),str_repeat('c',64)));
	$phpmailer->outcome=false;Mail047::send('wp_mail',$args047,array('family'=>$family047,'key'=>$key047,'attempt'=>'77:2'));$phpmailer->outcome=true;check037('plain native failure recorded '.$family047,last037()['status']==='failed' && !last037()['open_eligible']);
	$exact047=new RuntimeException('Synthetic original exception');$phpmailer->throw_next=$exact047;$caught047=null;try{Mail047::send('wp_mail',$args047,array('family'=>$family047,'key'=>$key047,'attempt'=>'77:3'));}catch(Throwable $e){$caught047=$e;}check037('new family preserves original exception '.$family047,$caught047===$exact047 && last037()['status']==='uncertain');
	$beforecount047=count($wpdb->messages);$calls047=0;$wpdb->fail_begin=true;$result047=Mail047::send(static function(...$a)use(&$calls047,$args047){++$calls047;return $a===$args047?'same-result':false;},$args047,array('family'=>$family047,'key'=>$key047,'attempt'=>'77:4'));$wpdb->fail_begin=false;check037('store failure falls through exact once '.$family047,$calls047===1 && $result047==='same-result' && count($wpdb->messages)===$beforecount047);
}
$calls047=0;$count047=count($wpdb->messages);Mail047::send(static function()use(&$calls047){++$calls047;return true;},array(),array('family'=>'unknown','key'=>'x'));check037('unknown family rejected for observation without suppressing original',$calls047===1 && count($wpdb->messages)===$count047);
foreach(array('subscription','loyalty') as $family047){$params047=Mail047::params(array('mail_family'=>$family047,'mail_sender'=>str_repeat('a',64)));check037('fixed new family and sender params accepted '.$family047,$params047===array('family'=>$family047,'sender'=>str_repeat('a',64)));}
check037('other family params remain invalid',Mail047::params(array('mail_family'=>'toString')) instanceof WP_Error);

/** The real reducer receives grouped SQL boundary facts from captured in-memory attempts. */
final class Reports047 {
	public function __construct(public Memory037 $inner){}
	public function __get($key){return $this->inner->$key;}
	public function __call($method,$args){return $this->inner->$method(...$args);}
	public function get_results($p,$mode){
		if(is_string($p)){if(str_contains($p,'sender_key')){$senders=array();foreach($this->inner->messages as $m){if($m['sender_key']!==''){$senders[$m['sender_key']]=array('sender_key'=>$m['sender_key'],'sender'=>$m['sender']);}}return array_values($senders);}return array();}
		if(str_contains($p->sql,'pc_email_log')){return array(array('email_id'=>'synthetic_notice','sent'=>4,'delivered'=>3));}
		if(!str_contains($p->sql,'GROUP BY family')){return $this->inner->get_results($p,$mode);}
		[$from,$to]=$p->values;$i=2;$family=str_contains($p->sql,'AND family=%s')?$p->values[$i++]:'';$sender=str_contains($p->sql,'AND sender_key=%s')?$p->values[$i++]:'';$groups=array();
		foreach($this->inner->messages as $m){if($m['attempted_ms']<$from||$m['attempted_ms']>=$to||($family!==''&&$m['family']!==$family)||($sender!==''&&$m['sender_key']!==$sender)){continue;}$id=serialize(array($m['family'],$m['source_key'],$m['sender_key'],$m['mime'],$m['coverage']));if(!isset($groups[$id])){$groups[$id]=array_intersect_key($m,array_flip(array('family','source_key','sender','sender_key','mime','coverage')))+array_fill_keys(array('attempts','accepted','failed','uncertain','pending','open_eligible','click_eligible','opened','clicked'),0);}$g=&$groups[$id];++$g['attempts'];if(isset($g[$m['status']])){++$g[$m['status']];}foreach(array('open','click') as $kind){$eligible=$m['status']==='accepted' && $m[$kind.'_eligible'];$g[$kind.'_eligible']+=(int)$eligible;$g[$kind==='open'?'opened':'clicked']+=(int)($eligible&&$m[$kind==='open'?'opened_ms':'clicked_ms']>0);}unset($g);}
		return array_values($groups);
	}
}
$wpdb=new Reports047($wpdb);
function WC(){return new class {public function mailer(){return new class{public function get_emails(){return array('synthetic'=>new class{public $id='synthetic_notice';public function get_title(){return 'Synthetic Woo notice';}});}};}};}
function admin_url($path){return '/wp-admin/'.$path;}
$range047=array('from'=>time()-60,'to'=>time()+60,'compare_from'=>1,'compare_to'=>2);
$snapshot047=dirname(__DIR__,4).'/tests/fixtures/data-observations/047/includes/';
foreach(array('emails/class-message-observations.php'=>'Message_Observations','data/class-emails.php'=>'Emails') as $file047=>$class047){$source047=file_get_contents($snapshot047.$file047);$source047=str_replace('final class '.$class047.' {','final class '.$class047.'047Oracle {',$source047);eval(substr($source047,5));}
foreach(array('subscription'=>'Subscription confirmation','loyalty'=>'Points activity notice') as $family047=>$name047){
	$report047=PeptideCore\Data\Emails::summary($range047,array('family'=>$family047));$obs047=$report047['observations'];
	check037('new family report filter has real attempts and null engagement '.$family047,$obs047['current']['attempts']===3 && $obs047['current']['accepted']===1 && $obs047['current']['failed']===1 && $obs047['current']['uncertain']===1 && $obs047['current']['open_eligible']===0 && $obs047['current']['click_eligible']===0 && $obs047['current']['opened']===null && $obs047['current']['clicked']===null && $obs047['current']['open_rate']===null && $obs047['current']['click_rate']===null);
	check037('new source exact fixed display name '.$family047,count($obs047['rows'])===1 && $obs047['rows'][0]['name']===$name047 && $obs047['rows'][0]['family']===$family047);
	$sender047=Store047::sender_key('source@example.invalid');$filtered047=Mail047::report($range047,array('family'=>$family047,'sender'=>$sender047));check037('combined family and sender filter '.$family047,$filtered047['current']===$obs047['current'] && $filtered047['rows'][0]['sender_key']===$sender047);
}
$all047=Mail047::report($range047);check037('report fixed four families and honest uncovered metadata',$all047['families']===array('woo','campaign','subscription','loyalty') && $all047['uncovered_families']===array('other_wp_mail','custom_woo_callback') && $all047['historical_backfill']===false);
$before047legacy=PeptideCore\Data\Emails047Oracle::summary($range047);$after047legacy=PeptideCore\Data\Emails::summary($range047);$keys047=array_flip(array('current','previous','change','rows','logging','log_url'));check037('legacy Woo JSON projection byte identical',json_encode(array_intersect_key($before047legacy,$keys047))===json_encode(array_intersect_key($after047legacy,$keys047)));
foreach(array('woo','campaign') as $family047){$old047=PeptideCore\Emails\Message_Observations047Oracle::report($range047,array('family'=>$family047));$new047=Mail047::report($range047,array('family'=>$family047));unset($old047['families'],$old047['uncovered_families'],$new047['families'],$new047['uncovered_families']);check037('legacy report oracle unchanged '.$family047,json_encode($old047)===json_encode($new047));}
$allnames047=PeptideCore\Emails\Observation_Labels::strings();$map047=require dirname(__DIR__).'/languages/customer-mail-observations-zh_CN.php';check037('fixed source names translated',isset($map047['Subscription confirmation'],$map047['Points activity notice']));
echo json_encode(array('new_passed'=>$passed-$before047,'total_passed'=>$passed,'failed'=>0,'native_mime'=>true,'database'=>'memory','network_calls'=>0,'payload_output'=>false)),"\n";
