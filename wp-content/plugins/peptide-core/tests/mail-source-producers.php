<?php
/** Actual producer methods with memory boundaries; no WP bootstrap, persistence or mail. */
declare(strict_types=1);
namespace PeptideCore {
	final class Store_Config { public static function other_contact_preferences(): array { return array('mode'=>'0','placeholder'=>''); } }
	function time() { return 1700000000; }
	function gmdate( $format ) { return \gmdate( $format, time() ); }
	function random_bytes( $length ) { global $random047; return str_repeat( chr( ++$random047 ), $length ); }
}
namespace {
define( 'ABSPATH', __DIR__ . '/' ); define( 'ARRAY_A', 'ARRAY_A' ); define( 'DAY_IN_SECONDS', 86400 ); define( 'HOUR_IN_SECONDS', 3600 ); define( 'MINUTE_IN_SECONDS', 60 );
class WP_Error { public function __construct( public $code, public $message='', public $data=array() ) {} }
function __( $s, $domain='' ) { return $s; }
function get_option( $k, $default=false ) { global $options047; return $options047[$k] ?? $default; }
function wp_verify_nonce( $v, $key ) { return 'valid' === $v; }
function sanitize_text_field( $v ) { return strip_tags( (string) $v ); }
function wp_salt( $key='' ) { return 'synthetic-memory-boundary'; }
function wp_json_encode( $v ) { return json_encode($v); }
function wp_unslash( $v ) { return $v; }
function wp_parse_url( $v, $part=-1 ) { return parse_url($v,$part); }
function esc_url_raw( $v ) { return $v; }
function is_email( $v ) { return filter_var($v,FILTER_VALIDATE_EMAIL); }
function sanitize_email( $v ) { return filter_var($v,FILTER_SANITIZE_EMAIL); }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function get_transient( $k ) { global $transients047; return $transients047[$k] ?? false; }
function set_transient( $k, $v, $ttl ) { global $transients047; $transients047[$k]=$v; }
function home_url( $path='' ) { return 'https://store.example.invalid'.$path; }
function add_query_arg( $args, $url ) { return $url.'?'.http_build_query($args); }
function wp_specialchars_decode( $s, $flags ) { return html_entity_decode($s,$flags,'UTF-8'); }
function get_bloginfo( $k ) { return 'Synthetic store'; }
function wp_generate_uuid4() { global $uuid047; return sprintf('00000000-0000-4000-8000-%012d',++$uuid047); }
function wp_mail( ...$args ) { global $mail047,$outcome047; $mail047[]=$args; if($outcome047 instanceof Throwable){throw $outcome047;} return $outcome047; }
function add_filter( ...$args ) { return true; }
function remove_filter( ...$args ) { return true; }
function get_userdata( $id ) { global $user047; return $user047 ? (object)array('user_email'=>'customer@example.invalid') : false; }
function wc_get_account_endpoint_url( $endpoint ) { return home_url('/my-account/'.$endpoint.'/'); }
function wp_next_scheduled( ...$args ) { return false; }
function wp_schedule_single_event( ...$args ) { global $schedule047; $schedule047[]=$args; }
final class Memory047 {
	public string $prefix='memory_'; public int $insert_id=77; public string $last_error=''; public bool $lock=true,$claim=true,$store_error=false; public $subscriber=null;
	public array $messages=array(),$updates=array(),$ledger=array('id'=>9,'user_id'=>3,'kind'=>'adjustment','delta'=>10,'balance'=>20,'debt'=>0,'notice_attempts'=>1); public int $released=0;
	public function prepare($sql,...$values){return (object)array('sql'=>$sql,'values'=>$values);}
	public function get_var($p){if(str_contains($p->sql,'RELEASE_LOCK')){++$this->released;return '1';}return $this->lock?'1':'0';}
	public function get_row($p,$mode){return str_contains($p->sql,'pc_subscriptions')?$this->subscriber:$this->ledger;}
	public function insert($table,$row){if(str_ends_with($table,'pc_mail_messages')){if($this->store_error){throw new RuntimeException('Synthetic store error');}$this->messages[$row['id']]=$row;return 1;}return 1;}
	public function update($table,$data,$where){if(str_ends_with($table,'pc_mail_messages')){$this->messages[$where['id']]=array_merge($this->messages[$where['id']],$data);}else{$this->updates[]=array($data,$where);}return 1;}
	public function query($p){return $this->claim?1:0;}
}
require dirname(__DIR__).'/includes/autoload.php';
$snapshot=dirname(__DIR__,4).'/tests/fixtures/data-observations/047/includes/';
foreach(array('Subscriptions'=>'class-subscriptions.php','Loyalty'=>'class-loyalty.php') as $class=>$file){$src=file_get_contents($snapshot.$file);$src=str_replace('final class '.$class.' {','final class '.$class.'047Oracle {',$src);eval(substr($src,5));}
$passed047=0;
function check047($label,$yes){global $passed047;if(!$yes){fwrite(STDERR,'FAIL '.$label."\n");exit(1);}++$passed047;}
function reset047($enabled=true){global $wpdb,$options047,$transients047,$mail047,$outcome047,$random047,$uuid047,$user047,$schedule047;$wpdb=new Memory047();$options047=array('pc_subscriptions_schema'=>'2','pc_mail_observations_enabled'=>$enabled,'pc_mail_observation_schema'=>'1','peptide_core_loyalty'=>array('notify_enabled'=>true));$transients047=array();$mail047=array();$outcome047=true;$random047=0;$uuid047=0;$user047=true;$schedule047=array();}
function input047($class){return array('nonce'=>'valid','consent'=>'1','context'=>$class::form_context('Synthetic consent','/shop/'),'email'=>'customer@example.invalid','website'=>'');}
function normalized047($result){return $result instanceof WP_Error?array($result->code,$result->message,$result->data):$result;}
function subscription047($class,$mode,$enabled=true){global $wpdb,$mail047,$outcome047,$transients047;reset047($enabled);$input=input047($class);if($mode==='guard'){$input['consent']='0';}if($mode==='active'){$wpdb->subscriber=array('id'=>77,'status'=>'active');}if($mode==='false'){$outcome047=false;}if($mode==='throw'){$outcome047=new RuntimeException('Exact synthetic exception');}if($mode==='store_error'){$wpdb->store_error=true;}$caught=null;try{$result=$class::request($input);}catch(Throwable $e){$caught=$e;$result=null;}return array('result'=>normalized047($result),'mail'=>$mail047,'exception'=>$caught,'messages'=>$wpdb->messages);}
foreach(array('success','false','guard','active','throw','store_error') as $mode){
	$oracle=subscription047(PeptideCore\Subscriptions047Oracle::class,$mode);$actual=subscription047(PeptideCore\Subscriptions::class,$mode);
	check047('subscription native args and credential body hash unchanged '.$mode,$oracle['mail']===$actual['mail'] && array_map(static fn($a)=>hash('sha256',$a[2]),$oracle['mail'])===array_map(static fn($a)=>hash('sha256',$a[2]),$actual['mail']));
	check047('subscription result/throw unchanged '.$mode,$oracle['result']===$actual['result'] && (($oracle['exception']===null && $actual['exception']===null)||($oracle['exception'] instanceof RuntimeException && $actual['exception'] instanceof RuntimeException)));
	check047('subscription call count '.$mode,count($actual['mail'])===(in_array($mode,array('guard','active'),true)?0:1));
}
$a=subscription047(PeptideCore\Subscriptions::class,'success');$message=array_values($a['messages'])[0];check047('subscription source contains only native id and fresh opaque attempt',$message['family']==='subscription' && $message['source_key']==='confirmation' && preg_match('/\A77:[0-9a-f-]{36}\z/D',$message['source_attempt'])===1 && !isset($message['body'],$message['subject'],$message['email']));
$b=subscription047(PeptideCore\Subscriptions::class,'success',false);check047('disabled subscription retains exact original once',$b['mail']===$a['mail'] && count($b['messages'])===0);
reset047();$in=input047(PeptideCore\Subscriptions::class);PeptideCore\Subscriptions::request($in);$transients047=array();PeptideCore\Subscriptions::request($in);$attempts=array_column(array_values($wpdb->messages),'source_attempt');check047('repeated confirmation sends have distinct opaque attempts',count($attempts)===2 && count(array_unique($attempts))===2);
function loyalty047($class,$mode,$enabled=true){global $wpdb,$outcome047,$mail047,$user047,$options047,$schedule047;reset047($enabled);if($mode==='lock'){$wpdb->lock=false;}if($mode==='claim'){$wpdb->claim=false;}if($mode==='user'){$user047=false;}if($mode==='setting'){$options047['peptide_core_loyalty']['notify_enabled']=false;}if($mode==='false'||$mode==='last'){$outcome047=false;}if($mode==='throw'){$outcome047=new RuntimeException('Exact synthetic exception');}if($mode==='last'){$wpdb->ledger['notice_attempts']=5;}if($mode==='store_error'){$wpdb->store_error=true;}$class::send_notice(9);return array('mail'=>$mail047,'updates'=>$wpdb->updates,'schedule'=>$schedule047,'released'=>$wpdb->released,'messages'=>$wpdb->messages);}
foreach(array('success','false','throw','last','lock','claim','user','setting','store_error') as $mode){
	$oracle=loyalty047(PeptideCore\Loyalty047Oracle::class,$mode);$actual=loyalty047(PeptideCore\Loyalty::class,$mode);
	check047('loyalty original args and plain body hash unchanged '.$mode,$oracle['mail']===$actual['mail'] && array_map(static fn($a)=>hash('sha256',$a[2]),$oracle['mail'])===array_map(static fn($a)=>hash('sha256',$a[2]),$actual['mail']));
	check047('loyalty retry/status/claim/lock flow unchanged '.$mode,$oracle['updates']===$actual['updates'] && $oracle['schedule']===$actual['schedule'] && $oracle['released']===$actual['released']);
	check047('loyalty exact send count '.$mode,count($actual['mail'])===(in_array($mode,array('lock','claim','user','setting'),true)?0:1));
}
$a=loyalty047(PeptideCore\Loyalty::class,'success');$message=array_values($a['messages'])[0];check047('loyalty source is exact ledger id and current attempt',$message['family']==='loyalty' && $message['source_key']==='points_notice' && $message['source_attempt']==='9:1');
$b=loyalty047(PeptideCore\Loyalty::class,'success',false);check047('disabled loyalty invokes original once',$b['mail']===$a['mail'] && count($b['messages'])===0);
echo json_encode(array('passed'=>$passed047,'failed'=>0,'database'=>'memory','network_calls'=>0,'payload_output'=>false)),"\n";
}
