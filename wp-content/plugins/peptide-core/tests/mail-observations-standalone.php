<?php
/** Focused, in-memory tests: native WP/Woo/PHPMailer code, NO bootstrap/DB/network/mail. */
declare(strict_types=1);
if ( PHP_SAPI !== 'cli' ) { exit( 2 ); }
$native = $argv[1] ?? dirname(__DIR__,4) . '/tests/fixtures/data-observations/native-wp-mail';
if ( ! is_file( $native . '/wp-includes/pluggable.php' ) || ! is_file( $native . '/wp-content/plugins/woocommerce/includes/emails/class-wc-email.php' ) ) { fwrite( STDERR, "Pass the installed WordPress public directory; it is read only.\n" ); exit( 2 ); }
define( 'ABSPATH', rtrim( $native, '/\\' ) . '/' );
define( 'WPINC', 'wp-includes' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'ARRAY_A', 'ARRAY_A' );

// Stub platform boundaries only. Actual production helpers and native mail/MIME/parser run below.
$hooks037 = array(); $options037 = array( 'woocommerce_email_from_address' => 'sender@example.invalid', 'woocommerce_email_from_name' => 'Synthetic sender', 'peptide_core_email_log_db' => '1' );
function add_filter( $name, $fn, $priority = 10, $accepted = 1 ) { global $hooks037; $hooks037[$name][$priority][] = array($fn,$accepted); return true; }
function add_action( $name, $fn, $priority = 10, $accepted = 1 ) { return add_filter($name,$fn,$priority,$accepted); }
function remove_filter( $name, $fn, $priority = 10 ) { global $hooks037; foreach($hooks037[$name][$priority] ?? array() as $i=>$row) { if($row[0] === $fn) { unset($hooks037[$name][$priority][$i]); } } return true; }
function apply_filters( $name, $value, ...$args ) { global $hooks037; $groups=$hooks037[$name] ?? array(); ksort($groups); foreach($groups as $rows){foreach($rows as [$fn,$n]){$value=$fn(...array_slice(array_merge(array($value),$args),0,$n));}} return $value; }
function do_action( $name, ...$args ) { global $hooks037; $groups=$hooks037[$name] ?? array(); ksort($groups); foreach($groups as $rows){foreach($rows as [$fn,$n]){$fn(...array_slice($args,0,$n));}} }
function do_action_ref_array( $name, $args ) { do_action($name,...$args); }
function get_option( $key, $default = false ) { global $options037; return $options037[$key] ?? $default; }
function wp_salt( $scheme = 'auth' ) { return 'synthetic-test-only-' . $scheme; }
function __( $text, $domain = '' ) { return $text; }
function determine_locale() { return 'en_US'; }
function wp_parse_url( $url, $component = -1 ) { return parse_url($url,$component); }
function home_url( $path = '' ) { return 'https://store.example.invalid' . $path; }
function network_home_url() { return home_url('/'); }
function add_query_arg( $key, $value, $url ) { return $url . (str_contains($url,'?') ? '&' : '?') . rawurlencode($key) . '=' . rawurlencode($value); }
function is_email( $email ) { return filter_var($email,FILTER_VALIDATE_EMAIL) ? $email : false; }
function sanitize_email( $email ) { return filter_var($email,FILTER_SANITIZE_EMAIL); }
function get_bloginfo( $key, $context = '' ) { return 'charset' === $key ? 'UTF-8' : 'Synthetic store'; }
function get_transient( $key ) { return false; }
function wp_specialchars_decode( $value, $flags = ENT_NOQUOTES ) { return html_entity_decode((string)$value,$flags,'UTF-8'); }
function wp_strip_all_tags( $value ) { return strip_tags($value); }
function esc_html( $value ) { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
function esc_url( $value ) { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
function wp_kses_uri_attributes() { return array('href','src'); }
function _doing_it_wrong( ...$args ) { throw new RuntimeException('Unexpected native parser warning.'); }
function wc_doing_it_wrong( ...$args ) {}
function wc_string_to_bool( $value ) { return in_array(strtolower($value),array('yes','true','1'),true); }
function wp_rand( $min, $max ) { return $max; }
$catalog037 = array('https://store.example.invalid/product/test/' => 101,'https://store.example.invalid/shop/' => 102);
function url_to_postid( $url ) { global $catalog037; return $catalog037[$url] ?? 0; }
function get_post_type( $id ) { return 101 === $id ? 'product' : 'page'; }
function get_post_status( $id ) { return 'publish'; }
function get_permalink( $id ) { return home_url(101 === $id ? '/product/test/' : '/shop/'); }
function wc_get_page_id( $kind ) { return 'shop' === $kind ? 102 : -1; }
class WP_Error { public function __construct(public $code,public $message='',public $data=array()){} }
class WC_Settings_API {}
class WC_Order {}
class WP_User {}

final class Prepared037 { public function __construct(public string $sql, public array $values){} }
final class Memory037 {
	public string $prefix='test_'; public string $last_error=''; public int $insert_id=0; public array $messages=array(); public array $links=array(); public array $recipients=array(); public array $logs=array(); public array $prepared=array(); public int $writes=0; public bool $fail_begin=false; public bool $fail_update=false; private ?array $tx=null;
	public function prepare($sql,...$values){$p=new Prepared037($sql,$values);$this->prepared[]=$p;return $p;}
	public function insert($table,$row){++$this->writes;if(str_ends_with($table,'pc_mail_messages')){if($this->fail_begin){throw new RuntimeException('Synthetic observation insert failure.');}$this->messages[$row['id']]=$row+array('status'=>'pending','mime'=>'','coverage'=>'not_observed','sender'=>'','sender_key'=>'','recipient_count'=>0,'open_eligible'=>0,'click_eligible'=>0,'opened_ms'=>0,'clicked_ms'=>0,'woo_log_id'=>0);}
		elseif(str_ends_with($table,'pc_mail_links')){$this->links[$row['id']]=$row+array('clicked_ms'=>0);}
		elseif(str_ends_with($table,'pc_mail_recipients')){$this->recipients[$row['message_id'].'|'.$row['email_hash']]=$row;}
		elseif(str_ends_with($table,'pc_email_log')){$this->logs[++$this->insert_id]=$row;}
		else{throw new RuntimeException('Unexpected table.');}return 1;}
	public function update($table,$data,$where){++$this->writes;if($this->fail_update){throw new RuntimeException('Synthetic observation update failure.');}$this->messages[$where['id']]=array_merge($this->messages[$where['id']],$data);return 1;}
	public function get_row($p,$mode){[$hash,$now]=$p->values;if(str_contains($p->sql,'INNER JOIN')){foreach($this->links as $link){$m=$this->messages[$link['message_id']] ?? null;if($link['token_hash']===$hash && $m && $m['expires_ms']>$now && $m['status']==='accepted' && (!str_contains($p->sql,'m.click_eligible=1') || $m['click_eligible'])){return $link+array('click_eligible'=>$m['click_eligible']);}}}
		else{foreach($this->messages as $m){if($m['token_hash']===$hash && $m['expires_ms']>$now && $m['status']==='accepted' && $m['open_eligible']){return array('id'=>$m['id']);}}}return null;}
	public function query($p){++$this->writes;if(is_string($p)){if('START TRANSACTION'===$p){$this->tx=array($this->messages,$this->links,$this->recipients);}elseif('ROLLBACK'===$p && $this->tx){[$this->messages,$this->links,$this->recipients]=$this->tx;$this->tx=null;}elseif('COMMIT'===$p){$this->tx=null;}return 1;}
		if(str_starts_with($p->sql,'DELETE')){$kind=str_contains($p->sql,'pc_mail_links')?'links':(str_contains($p->sql,'pc_mail_recipients')?'recipients':'messages');foreach($this->$kind as $key=>$row){$id='messages'===$kind?$row['id']:$row['message_id'];if(in_array($id,$p->values,true)){unset($this->{$kind}[$key]);}}return 1;}
		[$now,$id]=$p->values;$key=str_contains($p->sql,'opened_ms')?'opened_ms':'clicked_ms';$kind=str_contains($p->sql,'pc_mail_links')?'links':'messages';if(isset($this->{$kind}[$id]) && !$this->{$kind}[$id][$key]){$this->{$kind}[$id][$key]=$now;}return 1;}
	public function get_col($p){$ids=array();if(str_contains($p->sql,'email_hash')){foreach($this->recipients as $r){if($r['email_hash']===$p->values[0]){$ids[]=$r['message_id'];}}}else{foreach($this->messages as $m){if($m['expires_ms']<=$p->values[0]){$ids[]=$m['id'];}}}return array_slice($ids,0,200);}
	public function get_results($p,$mode){if(is_string($p)){return array();}if(str_contains($p->sql,'r.email_hash')){$rows=array();foreach($this->recipients as $r){if($r['email_hash']===$p->values[0]){$m=$this->messages[$r['message_id']];$rows[]=array_intersect_key($m,array_flip(array('id','family','source_key','attempted_ms','status','coverage','opened_ms','clicked_ms')));}}return array_slice($rows,$p->values[2],$p->values[1]);}return array();}
}
$wpdb=new Memory037();

require ABSPATH.'wp-includes/PHPMailer/Exception.php';
require ABSPATH.'wp-includes/PHPMailer/PHPMailer.php';
require ABSPATH.'wp-includes/PHPMailer/SMTP.php';
require ABSPATH.'wp-includes/class-wp-phpmailer.php';
require ABSPATH.'wp-includes/pluggable.php';
require ABSPATH.'wp-includes/class-wp-token-map.php';
require ABSPATH.'wp-includes/utf8.php';
foreach(array('class-wp-html-attribute-token.php','class-wp-html-span.php','class-wp-html-text-replacement.php','html5-named-character-references.php','class-wp-html-decoder.php','class-wp-html-tag-processor.php') as $file){require ABSPATH.'wp-includes/html-api/'.$file;}
require ABSPATH.'wp-content/plugins/woocommerce/includes/emails/class-wc-email.php';
require dirname(__DIR__).'/includes/autoload.php';

use PeptideCore\Emails\Message_Observations as O;
use PeptideCore\Emails\Observation_Store as S;
use PeptideCore\Emails\Observation_Links as L;

/** Native preSend builds real MIME; the only transport is this in-memory postSend. */
final class Capture037 extends WP_PHPMailer {
	public array $captures=array();public bool $outcome=true;public ?Throwable $throw_next=null;
	public function postSend(){if($this->throw_next){$e=$this->throw_next;$this->throw_next=null;throw $e;}$this->captures[]=array('mime'=>$this->getSentMIMEMessage(),'body'=>$this->Body,'alt'=>$this->AltBody,'from'=>$this->From);return $this->outcome;}
	protected function mailSend($header,$body){throw new RuntimeException('Forbidden mail transport.');}
	protected function sendmailSend($header,$body){throw new RuntimeException('Forbidden sendmail transport.');}
	protected function smtpSend($header,$body){throw new RuntimeException('Forbidden SMTP transport.');}
}
final class Email037 extends WC_Email {
	public string $fixture_body='';
	public function __construct(){ $this->id='synthetic_notice';$this->email_type='html';$this->recipient='first@example.invalid';add_action('phpmailer_init',array($this,'handle_multipart')); }
	public function style_inline($content){return $content;}
	public function get_content_html(){return $this->fixture_body;}
	public function get_content_plain(){return 'Synthetic plain alternative with https://store.example.invalid/product/test/';}
	public function get_subject(){return 'Synthetic subject';}
}
$phpmailer=new Capture037(true);
O::register_hooks();
add_action('woocommerce_email_sent',array(PeptideCore\Emails\Log::class,'record'),10,3);
$passed=0;
function check037(string $label,bool $yes):void{global $passed;if(!$yes){fwrite(STDERR,'FAIL '.$label."\n");exit(1);}++$passed;}
function last037():array{global $wpdb;return array_values($wpdb->messages)[count($wpdb->messages)-1];}
function send037(Email037 $email):bool{return $email->send($email->recipient,$email->get_subject(),$email->get_content(),'Content-Type: '.$email->get_content_type().'; charset=UTF-8',array());}
function tokens037(array $capture):array{preg_match('/pc_mail_open=([a-f0-9]{64})/',$capture['body'],$o);preg_match('/pc_mail_click=([a-f0-9]{64})/',$capture['body'],$c);return array($o[1] ?? '',$c[1] ?? '');}

$email=new Email037();
$email->fixture_body='<!doctype html><html><head><style>p {color: black}</style></head><body><p data-preserve="yes">Synthetic <a href="https://store.example.invalid/product/test/#details">product</a></p><a href="https://store.example.invalid/product/test/?add-to-cart=101">action</a><a href="https://store.example.invalid/?pc_campaign_unsubscribe=7&amp;token=synthetic">unsubscribe</a><a href="https://external.example.invalid/">external</a></body></html>';
check037('disabled option preserves the native callback',O::woo_callback('wp_mail',$email)==='wp_mail');
send037($email);
check037('disabled source creates no new attempt facts',count($wpdb->messages)===0);
$options037[O::ENABLED]=true;$options037[S::SCHEMA]='1';
$parsed=L::html($email->fixture_body,static fn($target)=>home_url('/?synthetic-link=1'),home_url('/?synthetic-pixel=1'));
check037('actual native HTML parser supports the normal fixture',$parsed!==null && $parsed['links']===1);
send037($email);$html=last037();$capture=$phpmailer->captures[array_key_last($phpmailer->captures)];[$open,$click]=tokens037($capture);
check037('normal native Woo HTML creates an accepted eligible attempt',$html['status']==='accepted' && $html['open_eligible']===1 && $html['click_eligible']===1);
check037('actual native sender and recipient are captured',$html['sender']==='sender@example.invalid' && $html['recipient_count']===1);
check037('actual native final MIME contains the image and safe catalogue link',strlen($open)===64 && strlen($click)===64 && str_contains($capture['mime'],'pc_mail_open=') && str_contains($capture['mime'],'pc_mail_click='));
check037('native MIME has no internal observation marker',!str_contains($capture['mime'],'X-Pc-Mail-Observation'));
check037('HTML parser preserves unrelated markup and excluded links',str_contains($capture['body'],'<p data-preserve="yes">') && str_contains($capture['body'],'?add-to-cart=101') && str_contains($capture['body'],'pc_campaign_unsubscribe=7') && str_contains($capture['body'],'https://external.example.invalid/'));
check037('observation credentials are stored only as hashes',$html['token_hash']===hash('sha256',$open) && !in_array($open,$html,true) && !array_key_exists('subject',$html) && !array_key_exists('body',$html));
check037('real Woo Log insert is bound to the exact attempt',$html['woo_log_id']>0 && isset($wpdb->logs[$html['woo_log_id']]));
check037('source identity and cohort cannot be rewritten',S::update($html['id'],array('source_key'=>'rewritten','attempted_ms'=>0))===false && $wpdb->messages[$html['id']]['source_key']===$html['source_key']);
S::observe('open',$open);$opened=$wpdb->messages[$html['id']]['opened_ms'];S::observe('open',$open);
check037('duplicate image requests preserve first observation',$opened>0 && $wpdb->messages[$html['id']]['opened_ms']===$opened);
check037('frozen link resolves only its server-stored target',S::observe('click',$click)==='https://store.example.invalid/product/test/#details');
$clicked=$wpdb->messages[$html['id']]['clicked_ms'];S::observe('click',$click);check037('duplicate link requests preserve first observation',$clicked>0 && $wpdb->messages[$html['id']]['clicked_ms']===$clicked);
$before=$wpdb->writes;check037('tampered token is rejected',S::observe('click',str_repeat('0',64))===null && S::observe('open','malformed')===null);check037('invalid credentials never write',$before===$wpdb->writes);
$wpdb->messages[$html['id']]['expires_ms']=S::now()-1;$before=$wpdb->writes;check037('expired token neither observes nor redirects',S::observe('open',$open)===null && S::observe('click',$click)===null && $before===$wpdb->writes);$wpdb->messages[$html['id']]['expires_ms']=S::now()+86400000;
check037('request metadata is not required or stored for observations',!isset($html['ip'],$html['user_agent']));
$late=static function($mailer){$mailer->From='LaterSender@example.invalid';$mailer->clearAllRecipients();$mailer->addAddress('later-recipient@example.invalid');};add_action('phpmailer_init',$late,PHP_INT_MAX);send037($email);$later=last037();remove_filter('phpmailer_init',$late,PHP_INT_MAX);check037('later native hook sender and recipient changes are confirmed precisely',$later['sender']==='LaterSender@example.invalid' && $later['open_eligible']===1 && count(S::export('later-recipient@example.invalid')['data'])===1 && count(array_filter(S::export('first@example.invalid')['data'],static fn($item)=>$item['item_id']==='mail-'.$later['id']))===0);
$change_body=static function($mailer){$mailer->Body='<html><body>Later replacement without observations.</body></html>';};add_action('phpmailer_init',$change_body,PHP_INT_MAX);send037($email);$changed=last037();remove_filter('phpmailer_init',$change_body,PHP_INT_MAX);check037('later native content changes cannot claim eligible observations',$changed['status']==='accepted' && $changed['coverage']==='native_changed' && !$changed['open_eligible'] && !$changed['click_eligible']);
$append_footer=static function($mailer){$mailer->Body.='<p>Late synthetic footer.</p>';};add_action('phpmailer_init',$append_footer,PHP_INT_MAX);send037($email);$footer=last037();$footer_capture=$phpmailer->captures[array_key_last($phpmailer->captures)];remove_filter('phpmailer_init',$append_footer,PHP_INT_MAX);[, $footer_click]=tokens037($footer_capture);
check037('native late footer preserves rewritten link but excludes measurement',$footer['status']==='accepted' && $footer['coverage']==='native_changed' && !$footer['click_eligible'] && strlen($footer_click)===64 && str_contains($footer_capture['mime'],'Late synthetic footer.'));
$before_footer_click=$wpdb->writes;$footer_target=S::observe('click',$footer_click,true);$footer_link=array_values(array_filter($wpdb->links,static fn($row)=>$row['message_id']===$footer['id']))[0];
check037('ineligible accepted message still resolves its frozen safe destination',$footer_target==='https://store.example.invalid/product/test/#details');
check037('ineligible retained links never record a click',$wpdb->writes===$before_footer_click && $wpdb->messages[$footer['id']]['clicked_ms']===0 && $footer_link['clicked_ms']===0);
check037('HEAD-style navigation also resolves without counting',S::observe('click',$footer_click,false)===$footer_target && $wpdb->writes===$before_footer_click);
$footer_totals=S::totals(array(array('attempts'=>1,'accepted'=>1,'click_eligible'=>$footer['click_eligible'],'clicked'=>0)));
check037('late footer message does not enter the click denominator',$footer_totals['click_eligible']===0 && $footer_totals['clicked']===null && $footer_totals['click_rate']===null);

foreach(array('https://store.example.invalid/my-account/','https://store.example.invalid/product/test/?token=private','https://store.example.invalid/product/test/?','https://external.example.invalid/product/test/','//external.example.invalid/','javascript:alert(1)','https://user:pass@store.example.invalid/product/test/') as $url){check037('restricted catalogue destination rejects unsupported URL',L::target($url)===null);}
check037('relative canonical native product preserves fragment',L::target('/product/test/#details')==='https://store.example.invalid/product/test/#details');
foreach(array('<plaintext>not an image','<template><p>hidden</p></template>','<html><body>safe</body><!-- </body> -->') as $unsupported){check037('unsupported HTML cannot claim an image was inserted',L::html($unsupported,static fn()=>home_url('/?link=1'),home_url('/?pixel=1'))===null);}
$email->email_type='multipart';$options037[O::ENABLED]=false;send037($email);$baseline_alt=$phpmailer->captures[array_key_last($phpmailer->captures)]['alt'];$options037[O::ENABLED]=true;send037($email);$multipart=last037();$capture=$phpmailer->captures[array_key_last($phpmailer->captures)];
check037('actual native multipart final MIME contains both alternatives',str_contains($capture['mime'],'multipart/alternative') && str_contains($capture['mime'],'text/plain') && str_contains($capture['mime'],'text/html'));
check037('multipart plain alternative remains untracked and unchanged',$capture['alt']===$baseline_alt && !str_contains($capture['alt'],'pc_mail_') && $multipart['open_eligible']===1);
check037('native Woo cleanup still clears AltBody',$phpmailer->AltBody==='');
$email->email_type='plain';send037($email);$plain=last037();$capture=$phpmailer->captures[array_key_last($phpmailer->captures)];
check037('plain native MIME is unmodified and explicitly ineligible',$plain['coverage']==='plain_no_observations' && !$plain['open_eligible'] && !str_contains($capture['mime'],'pc_mail_open='));
$email->email_type='html';$short=static fn()=>true;add_filter('pre_wp_mail',$short);send037($email);$pre=last037();remove_filter('pre_wp_mail',$short);
check037('short circuit cannot claim actual sender or MIME coverage',$pre['status']==='accepted' && $pre['sender']==='' && $pre['coverage']==='not_observed' && !$pre['open_eligible']);
$phpmailer->outcome=false;send037($email);$failed=last037();$phpmailer->outcome=true;check037('known transport failure remains failed',$failed['status']==='failed');
$exception=new RuntimeException('Synthetic exact exception.');$phpmailer->throw_next=$exception;$caught=null;try{send037($email);}catch(Throwable $e){$caught=$e;}check037('original transport exception propagates unchanged',$caught===$exception && last037()['status']==='uncertain');
$before=count($wpdb->messages);$wpdb->fail_begin=true;$calls=0;$result=O::send(static function(...$args)use(&$calls){++$calls;return 'unchanged';},array('a','b'),array('family'=>'campaign','key'=>'4','attempt'=>'8:1'));$wpdb->fail_begin=false;
check037('observation begin failure invokes original exactly once unchanged',$calls===1 && $result==='unchanged' && count($wpdb->messages)===$before);
$wpdb->fail_update=true;$calls=0;$result=O::send(static function()use(&$calls){++$calls;return true;},array(),array('family'=>'campaign','key'=>'4','attempt'=>'8:2'));$wpdb->fail_update=false;
check037('observation finalization failure never changes accepted business result',$calls===1 && $result===true);
$callback_args=array('one','two',array('three'));$seen_args=null;$sentinel=(object)array('same'=>true);$calls=0;$result=O::send(static function(...$actual)use(&$calls,&$seen_args,$sentinel){++$calls;$seen_args=$actual;return $sentinel;},$callback_args,array('family'=>'campaign','key'=>'4','attempt'=>'8:3'));check037('original callable receives exact arguments once and its exact result survives',$calls===1 && $seen_args===$callback_args && $result===$sentinel);

$email->fixture_body='<p><a href="https://store.example.invalid/product/test/';send037($email);$bad=last037();check037('incomplete HTML is not partially transformed',$bad['coverage']==='unsupported_html' && !$bad['open_eligible']);
$email->fixture_body='<html><body><p>Normal <a href="https://store.example.invalid/product/test/">product</a></p></body></html>';
$nested=false;$nested_filter=static function($atts)use(&$nested,$email){if(!$nested){$nested=true;send037($email);}return $atts;};add_filter('wp_mail',$nested_filter,5);$before=count($wpdb->messages);send037($email);remove_filter('wp_mail',$nested_filter,5);$pair=array_slice(array_values($wpdb->messages),$before);
check037('reentrant same-object native sends keep distinct attempt identities',count($pair)===2 && $pair[0]['id']!==$pair[1]['id'] && $pair[0]['woo_log_id']!==$pair[1]['woo_log_id'] && $pair[0]['woo_log_id']>0 && $pair[1]['woo_log_id']>0);
$native_pair=array_slice($phpmailer->captures,-2);$sender_match=true;foreach($pair as $message){$match=false;foreach($native_pair as $sent){[$credential]=tokens037($sent);if(hash('sha256',$credential)===$message['token_hash']){$match=$message['sender']===$sent['from'];}}$sender_match=$sender_match && $match;}
check037('nested frames capture their own actual MIME senders',$sender_match);
$args=array(array('first@example.invalid','second@example.invalid'),'Synthetic campaign',$email->fixture_body,array('Content-Type: text/html; charset=UTF-8','From: campaign@example.invalid','Cc: third@example.invalid'));
O::send('wp_mail',$args,array('family'=>'campaign','key'=>'4','attempt'=>'8:1'));$campaign=last037();$capture=$phpmailer->captures[array_key_last($phpmailer->captures)];[$campaign_open,$campaign_click]=tokens037($capture);
check037('campaign HTML uses actual sender and one attempt for several recipients',$campaign['family']==='campaign' && $campaign['source_attempt']==='8:1' && $campaign['sender']==='campaign@example.invalid' && $campaign['recipient_count']===3);
O::send('wp_mail',$args,array('family'=>'campaign','key'=>'4','attempt'=>'8:2'));check037('retry is a new immutable attempt',last037()['id']!==$campaign['id'] && last037()['source_attempt']==='8:2');
$export=S::export('second@example.invalid');$encoded=json_encode($export);check037('privacy export is precisely linked and excludes credentials',count($export['data'])===2 && !str_contains($encoded,'token_hash') && !str_contains($encoded,$campaign_open) && !str_contains($encoded,'recipient'));
$erased=S::erase('second@example.invalid');check037('privacy erasure revokes a shared message and all its links',$erased['items_removed'] && $erased['done'] && !isset($wpdb->messages[$campaign['id']]) && S::observe('open',$campaign_open)===null && S::observe('click',$campaign_click)===null);
check037('unrelated Woo message remains after precise campaign erasure',isset($wpdb->messages[$html['id']]));
$wpdb->messages[$failed['id']]['expires_ms']=S::now()-1;S::prune();check037('bounded retention removes only expired facts',!isset($wpdb->messages[$failed['id']]) && isset($wpdb->messages[$html['id']]));

$totals=S::totals(array(array('attempts'=>10,'accepted'=>6,'open_eligible'=>4,'opened'=>2,'click_eligible'=>3,'clicked'=>1)));
check037('engagement rates use eligible accepted attempts, never legacy totals',$totals['open_rate']===50.0 && $totals['click_rate']===33.33);
$none=S::totals(array());check037('no eligible collection is null, not observed zero',$none['opened']===null && $none['clicked']===null && $none['open_rate']===null);
$zero=S::totals(array(array('open_eligible'=>1,'click_eligible'=>1)));check037('eligible messages without requests are real zero',$zero['opened']===0 && $zero['clicked']===0 && $zero['open_rate']===0.0);
S::period(1700000000,1700003600,array('family'=>'campaign','sender'=>str_repeat('a',64)));$query=$wpdb->prepared[array_key_last($wpdb->prepared)];check037('report prepares send-time half-open cohort and separate source/sender filters',$query->values===array(1700000000000,1700003600000,'campaign',str_repeat('a',64)) && str_contains($query->sql,'attempted_ms>=%d AND attempted_ms<%d') && !str_contains($query->sql,'recipient'));
$options037[S::SCHEMA]='';$report=O::report(array());check037('missing storage reports unavailable metrics without installing',$report['status']==='not_installed' && $report['current']===null && $report['rows']===array());$options037[S::SCHEMA]='1';
$wpdb->last_error='Synthetic query error';$report=O::report(array('from'=>1,'to'=>2,'compare_from'=>0,'compare_to'=>1));check037('storage read failure is unavailable rather than a fabricated zero',$report['status']==='unavailable' && $report['current']===null);$wpdb->last_error='';
check037('filter rejects non-scalar/unknown source or malformed sender',O::params(array('mail_family'=>array('woo'))) instanceof WP_Error && O::params(array('mail_sender'=>'not-an-opaque-key')) instanceof WP_Error);
$labels=PeptideCore\Emails\Observation_Labels::strings();$map=require dirname(__DIR__).'/languages/customer-mail-observations-zh_CN.php';$existing=require dirname(__DIR__).'/languages/customer-campaigns-zh_CN.php';$map=array_merge($existing,$map);check037('all new Data labels have Chinese mappings',count(array_filter($labels,static fn($text)=>!array_key_exists($text,$map)))===0);
echo json_encode(array('passed'=>$passed,'failed'=>0,'native_mime'=>true,'database'=>'in-memory boundary double','network_calls'=>0,'external_mail'=>0)),"\n";
