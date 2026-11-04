<?php
/** ZIP completion from the current customer's own saved address, with an optional explicitly consented provider. */
namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Postal_Assist {
	public const OPTION = 'pc_postal_assist';
	public const APP = 'postal-assist';
	public const ROUTE = '/peptide/v1/postal-assist';
	private const FIELDS = array( 'country', 'state', 'city', 'address_1', 'address_2', 'postcode' );
	private const US_STATES = 'AL AK AZ AR CA CO CT DE DC FL GA HI ID IL IN IA KS KY LA ME MD MA MI MN MS MO MT NE NV NH NJ NM NY NC ND OH OK OR PA RI SC SD TN TX UT VT VA WA WV WI WY AA AE AP';

	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_filter( 'rest_post_dispatch', array( self::class, 'private_headers' ), 90, 3 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'assets' ), 150 );
		Admin\Postal_Assist_Page::register_hooks();
	}
	public static function defaults(): array { return array( 'enabled'=>false, 'google_enabled'=>false, 'merchant_consent'=>false ); }
	public static function normalize( $raw ): array {
		$raw = is_array( $raw ) ? $raw : array();
		$out = array(); foreach ( self::defaults() as $key=>$value ) { $out[$key] = true === ( $raw[$key] ?? false ); }
		return $out;
	}
	public static function settings(): array { return self::normalize( get_option( self::OPTION, self::defaults() ) ); }
	private static function key(): string { return defined( 'PC_GOOGLE_ADDRESS_VALIDATION_KEY' ) && is_string( PC_GOOGLE_ADDRESS_VALIDATION_KEY ) && 1 === preg_match( '/\A[A-Za-z0-9_-]{20,200}\z/D', PC_GOOGLE_ADDRESS_VALIDATION_KEY ) ? PC_GOOGLE_ADDRESS_VALIDATION_KEY : ''; }
	public static function google_ready( ?array $settings = null ): bool { $s = $settings ?? self::settings(); return ! empty( $s['enabled'] ) && ! empty( $s['google_enabled'] ) && ! empty( $s['merchant_consent'] ) && '' !== self::key(); }
	public static function text( string $value ): string { return trim( preg_replace( '/\s+/u', ' ', $value ) ?? '' ); }
	public static function fold( string $value ): string { return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( self::text( $value ), 'UTF-8' ) : strtoupper( self::text( $value ) ); }
	/** No identity, postcode guess, caller-provided user ID or city-only lookup. */
	public static function address( $raw ): ?array {
		if ( ! is_array( $raw ) || array_diff( array_keys( $raw ), self::FIELDS ) || array_diff( self::FIELDS, array_keys( $raw ) ) ) { return null; }
		$out=array();
		foreach ( self::FIELDS as $field ) {
			if ( ! is_string( $raw[$field] ) || strlen( $raw[$field] ) > 200 || preg_match( '/[\x00-\x1F<>]/u', $raw[$field] ) ) { return null; }
			$out[$field]=self::text( $raw[$field] );
		}
		$out['country']=strtoupper($out['country']);$out['state']=strtoupper($out['state']);
		if ( ! in_array( $out['country'], array('US','PR'), true ) || '' === $out['city'] || strlen($out['address_1'])<5 || ! preg_match('/\d/u',$out['address_1']) || ! preg_match('/\p{L}/u',$out['address_1']) ) { return null; }
		if ( 'US' === $out['country'] && ! in_array($out['state'],explode(' ',self::US_STATES),true) ) { return null; }
		// PR has no state list in Woo; country=PR is authoritative, never rewritten as US.
		if ( 'PR' === $out['country'] && ! in_array( $out['state'], array('', 'PR', 'PUERTO RICO'), true ) ) { return null; }
		if ( 'PR' === $out['country'] ) { $out['state']=''; }
		return $out;
	}
	public static function same_address( array $a, array $b ): bool {
		foreach ( array_diff(self::FIELDS,array('postcode')) as $key ) { if ( self::fold($a[$key]) !== self::fold($b[$key]) ) { return false; } }
		return true;
	}
	public static function valid_zip( $postcode, string $country ): bool {
		if ( ! is_string($postcode) || 1 !== preg_match('/\A[0-9]{5}(?:-[0-9]{4})?\z/D',$postcode) ) { return false; }
		return 'US' === $country || ( 'PR' === $country && in_array(substr($postcode,0,3),array('006','007','009'),true) );
	}
	/** A conflicting pair of saved ZIPs remains ambiguous; never choose the first row. */
	public static function saved_match( array $input, array $saved ): array {
		$zips=array();
		foreach($saved as $raw){$a=self::address($raw);if($a&&self::same_address($input,$a)&&self::valid_zip($a['postcode'],$input['country'])){$zips[$a['postcode']]=true;}}
		return count($zips)===1 ? array('state'=>'matched','postcode'=>(string)array_key_first($zips),'source'=>'saved') : array('state'=>count($zips)>1?'ambiguous':'manual');
	}
	private static function own_saved(): array {
		$id=get_current_user_id(); if(!$id||!class_exists('\\WC_Customer'))return array();
		$customer=new \WC_Customer($id);$out=array();
		foreach(array('shipping','billing')as$group){$a=array();foreach(self::FIELDS as$field){$getter='get_'.$group.'_'.$field;$a[$field]=(string)$customer->$getter('edit');}$out[]=$a;}
		return $out;
	}
	public static function routes(): void { register_rest_route( 'peptide/v1', '/postal-assist', array('methods'=>'POST','permission_callback'=>array(self::class,'permission'),'callback'=>array(self::class,'lookup')) ); }
	public static function permission( \WP_REST_Request $request ) {
		if(!wp_verify_nonce((string)$request->get_header('X-WP-Nonce'),'wp_rest'))return self::error('nonce',403);
		$origin=(string)$request->get_header('Origin');if(''===$origin)$origin=(string)$request->get_header('Referer');
		$a=wp_parse_url($origin);$b=wp_parse_url(home_url('/'));
		if(!is_array($a)||!is_array($b)||strtolower($a['scheme']??'')!==strtolower($b['scheme']??'')||strtolower($a['host']??'')!==strtolower($b['host']??'')||(int)($a['port']??(('https'===($a['scheme']??''))?443:80))!==(int)($b['port']??(('https'===($b['scheme']??''))?443:80)))return self::error('origin',403);
		if(Store_Config::get('force_login')&&!is_user_logged_in())return self::error('login',401);
		return true;
	}
	public static function lookup( \WP_REST_Request $request ) {
		if($request->get_query_params()||!$request->is_json_content_type()||strlen($request->get_body())>4096)return self::error('input',400);
		$body=$request->get_json_params();
		if(!is_array($body)||array_diff(array_keys($body),array('address','allow_external'))||!is_bool($body['allow_external']??null)||!($a=self::address($body['address']??null)))return self::error('input',400);
		$s=self::settings();if(!$s['enabled'])return rest_ensure_response(array('state'=>'disabled'));
		if(''!==$a['postcode'])return rest_ensure_response(array('state'=>'manual'));
		try{$matched=self::saved_match($a,self::own_saved());}catch(\Throwable $error){$matched=array('state'=>'manual');}
		if('manual'!==$matched['state'])return rest_ensure_response($matched);
		if(!$body['allow_external']||!self::google_ready($s))return rest_ensure_response(array('state'=>'manual'));
		// Security-only bounded native rate record; never stores address/email/raw IP.
		if(!class_exists('\\WC_Rate_Limiter'))return self::error('unavailable',503);
		$identity=get_current_user_id().'|'.(string)($_SERVER['REMOTE_ADDR']??'');$bucket='pc-postal-'.hash_hmac('sha256',$identity,wp_salt('nonce'));
		if(\WC_Rate_Limiter::retried_too_soon($bucket))return self::error('rate',429);
		if(!\WC_Rate_Limiter::set_rate_limit($bucket,5))return self::error('unavailable',503);
		return rest_ensure_response(self::google_lookup($a));
	}
	/** Fail closed on corrections/ambiguity. Only a missing ZIP may be inferred. */
	public static function google_result( array $input, $raw ): array {
		$r=is_array($raw)?($raw['result']??null):null;$v=is_array($r)?($r['verdict']??null):null;$a=is_array($r)?($r['address']??null):null;$usps=is_array($r)?($r['uspsData']??null):null;
		if(!is_array($v)||!is_array($a)||!is_array($usps)||true!==($v['addressComplete']??false)||!in_array($v['validationGranularity']??'',array('PREMISE','SUB_PREMISE'),true)||!empty($v['hasUnconfirmedComponents'])||!empty($v['hasReplacedComponents'])||!empty($v['hasSpellCorrectedComponents'])||'Y'!==($usps['dpvConfirmation']??''))return array('state'=>'manual');
		foreach(array('missingComponentTypes','unconfirmedComponentTypes','unresolvedTokens')as$key){if(!empty($a[$key]))return array('state'=>'manual');}
		$components=$a['addressComponents']??null;if(!is_array($components)||!$components)return array('state'=>'manual');
		foreach($components as$c){if(!is_array($c)||'CONFIRMED'!==($c['confirmationLevel']??'')||!empty($c['unexpected'])||!empty($c['replaced'])||(!empty($c['inferred'])&&!in_array($c['componentType']??'',array('postal_code','postal_code_suffix'),true)))return array('state'=>'manual');}
		$p=$a['postalAddress']??null;if(!is_array($p))return array('state'=>'manual');
		$country=(string)($p['regionCode']??'');if($country!==$input['country'])return array('state'=>'manual');
		$lines=$p['addressLines']??null;if(!is_array($lines)||array_filter($lines,static fn($line)=>!is_string($line)))return array('state'=>'manual');
		$expected=array_values(array_filter(array($input['address_1'],$input['address_2']),static fn($line)=>''!==$line));
		if(count($lines)!==count($expected))return array('state'=>'manual');
		foreach($lines as$i=>$line){if(self::fold($line)!==self::fold($expected[$i]))return array('state'=>'manual');}
		if(self::fold((string)($p['locality']??''))!==self::fold($input['city']))return array('state'=>'manual');
		$state=self::fold((string)($p['administrativeArea']??''));if('US'===$country&&$state!==$input['state'])return array('state'=>'manual');if('PR'===$country&&!in_array($state,array('','PR','PUERTO RICO'),true))return array('state'=>'manual');
		$zip=(string)($p['postalCode']??'');$std=$usps['standardizedAddress']??array();
		if(!self::valid_zip($zip,$country)||!is_array($std)||substr($zip,0,5)!==($std['zipCode']??'')||('PR'===$country&&'PR'!==($std['state']??''))||('US'===$country&&$input['state']!==($std['state']??'')))return array('state'=>'manual');
		if(strlen($zip)===10&&substr($zip,6)!==($std['zipCodeExtension']??''))return array('state'=>'manual');
		return array('state'=>'matched','postcode'=>$zip,'source'=>'google');
	}
	private static function google_lookup( array $a ): array {
		$address=array('regionCode'=>$a['country'],'administrativeArea'=>'PR'===$a['country']?'PR':$a['state'],'locality'=>$a['city'],'addressLines'=>array_values(array_filter(array($a['address_1'],$a['address_2']),static fn($line)=>''!==$line)));
		if(strlen(implode('',array_merge(array($address['regionCode'],$address['administrativeArea'],$address['locality']),$address['addressLines'])))>280)return array('state'=>'manual');
		try{$response=wp_safe_remote_post('https://addressvalidation.googleapis.com/v1:validateAddress',array('timeout'=>4,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>65536,'headers'=>array('Content-Type'=>'application/json','Accept'=>'application/json','X-Goog-Api-Key'=>self::key()),'body'=>wp_json_encode(array('address'=>$address,'enableUspsCass'=>true))));
			if(is_wp_error($response)||200!==wp_remote_retrieve_response_code($response))return array('state'=>'manual');
			return self::google_result($a,json_decode(wp_remote_retrieve_body($response),true,32));
		}catch(\Throwable $error){return array('state'=>'manual');}
	}
	private static function error(string $reason,int $status){return new \WP_Error('pc_postal_'.$reason,self::strings()['unavailable'],array('status'=>$status));}
	public static function private_headers($response,$server,\WP_REST_Request $request){
		if(self::ROUTE!==rtrim($request->get_route(),'/')||!$response instanceof \WP_REST_Response)return $response;
		$tokens=array();foreach($response->get_headers()as$name=>$value){if('vary'===strtolower($name))foreach(explode(',',implode(',',(array)$value))as$token){$token=trim($token);if(''!==$token)$tokens[strtolower($token)]=$token;}}
		$headers=$response->get_headers();foreach(array_keys($headers)as$name){if(in_array(strtolower($name),array('vary','cache-control','pragma','expires','x-content-type-options'),true))unset($headers[$name]);}$response->set_headers($headers);
		$tokens['cookie']='Cookie';$tokens['origin']='Origin';$response->header('Cache-Control','private, no-store, max-age=0');$response->header('Pragma','no-cache');$response->header('Expires','0');$response->header('Vary',isset($tokens['*'])?'*':implode(', ',array_values($tokens)));$response->header('X-Content-Type-Options','nosniff');return $response;
	}
	/** Every new customer-facing label, including server failures, is owned in both languages. */
	public static function strings(): array {
		$es=class_exists(Storefront_Translation::class)&&Storefront_Translation::active()&&'es'===Storefront_Translation::code();
		return $es?array('notice'=>'Solo se completa un código postal vacío cuando la dirección completa coincide de forma inequívoca. Revíselo antes de pagar.','consent'=>'Permitir que Google compruebe esta dirección para encontrar el código postal. Se enviarán país, estado, ciudad y dirección; no su nombre, correo ni teléfono. Es opcional.','filled'=>'Código postal completado. Compruebe la dirección antes de pagar.','manual'=>'Introduzca o revise el código postal manualmente.','unavailable'=>'La búsqueda del código postal no está disponible. Introduzca el código manualmente.') : array('notice'=>'An empty ZIP can be filled only from an unambiguous full-address match. Please review it before paying.','consent'=>'Allow Google to check this address for its ZIP code. Country, state, city and street address will be sent; not your name, email or phone. This is optional.','filled'=>'ZIP code filled. Please review your address before paying.','manual'=>'Enter or review the ZIP code manually.','unavailable'=>'Postal lookup is unavailable; enter the ZIP code manually.');
	}
	public static function assets(): void {
		$s=self::settings();if(!$s['enabled']||is_admin()||!is_checkout()||is_wc_endpoint_url()||is_preview()||is_customize_preview()||Templates::previewing_id())return;
		foreach(array('wp-data','wc-blocks-data-store')as$dependency){if(!wp_script_is($dependency,'registered'))return;}
		$strings=self::strings();
		$handle='pc-postal-assist';wp_enqueue_script($handle,plugins_url('assets/postal-assist.js',PEPTIDE_CORE_FILE),array('wp-data','wc-blocks-data-store'),(string)filemtime(PEPTIDE_CORE_DIR.'/assets/postal-assist.js'),true);
		wp_localize_script($handle,'PCPostalAssist',array('enabled'=>true,'endpoint'=>rest_url('peptide/v1/postal-assist'),'nonce'=>wp_create_nonce('wp_rest'),'externalEnabled'=>self::google_ready($s),'strings'=>$strings));
	}
}
