<?php
/**
 * Isolated routing regression: actual archive/helper and installed WP_REST_Request.
 * Storage, publication records and transients are synthetic in-memory boundaries.
 * No WordPress bootstrap, database, HTTP, browser, customer data or real certificate.
 * Usage: php this-file.php <installed-wp-root> <existing-absolute-task-output-dir>
 */
namespace PeptideCore\Api {
    final class Rest_Controller { public static function client_ip(): string { return '192.0.2.44'; } }
}
namespace PeptideCore {
    final class Post_Types { public const REPORT = 'pc_report'; public const BATCH = 'pc_batch'; }
    final class Meta {
        public const REPORT_FILE='_file', REPORT_STATUS='_status', REPORT_IS_DEMO='_demo', REPORT_ISSUED_ON='_issued', REPORT_SOURCE_SHA256='_source_sha';
        public const REPORT_SOURCE_PRODUCT='_source_product', REPORT_SPECIFICATION='_specification', REPORT_REFERENCE_ID='_reference', REPORT_SOURCE_LOT='_lot', REPORT_ISSUER_NAME='_issuer';
        public const REPORT_BATCH_ID='_batch', REPORT_PRODUCT_ID='_product', BATCH_PRODUCT_ID='_batch_product';
        public static function bool($v): bool { return in_array($v, [true, 1, '1'], true); }
    }
    final class Private_Files {
        public const MAX_BYTES=20971520;
        public static string $root;
        public static function dir(): string { return self::$root; }
        public static function path(string $name): ?string {
            $GLOBALS['routing']['path_calls']++;
            return preg_match('/^[a-f0-9]{32}\.(pdf|png)$/D', $name) ? self::$root.'/'.$name : null;
        }
    }
    final class Reports { public static function product_id(int $id): int { return (int)\get_post_meta($id, Meta::REPORT_PRODUCT_ID, true); } }
    function fopen($path,$mode) { $GLOBALS['routing']['file_opens']++; return \fopen($path,$mode); }
    function header($line,$replace=true): void {
        [$name,$value]=array_pad(explode(':',$line,2),2,'');
        $GLOBALS['routing']['headers'][strtolower($name)]=trim($value);
    }
    function header_remove($name=null): void {
        if($name===null) $GLOBALS['routing']['headers']=[];
        else unset($GLOBALS['routing']['headers'][strtolower($name)]);
    }
    function http_response_code($status=null) {
        $old=$GLOBALS['routing']['status'];
        if($status!==null)$GLOBALS['routing']['status']=$status;
        return $old;
    }
}
namespace {
    if(($argv[1]??'')!=='--child') {
        $wp=$argv[1]??'';$out=$argv[2]??'';
        if(!is_file($wp.'/wp-includes/rest-api/class-wp-rest-request.php')||!is_dir($out)||realpath($out)!==$out)throw new RuntimeException('Pass actual installed WordPress and an existing absolute task directory.');
        $cases=['valid-range-121','valid-range-boundary','full-budget','large-range-budget','multi-range-budget','bad-range-budget','unsatisfiable-range-budget','if-range-budget','empty-if-range-budget','head-budget','range-ceiling','full-valid','head-valid','range-valid','empty-if-range-fallback','multiple-range-fallback','bad-range-416','if-range-fallback','conditional-valid','thumbnail-valid','thumbnail-range','thumbnail-conditional','thumbnail-stale','query'];
        foreach(['revoked','withdrawn','superseded','password','demo','admin-no-opt-in','private-post','wrong-post-type'] as $state)foreach(['conditional','range','thumbnail-conditional','thumbnail-range'] as $action)$cases[]=$state.'--'.$action;
        $run='routing-'.bin2hex(random_bytes(5));$checks=[];$records=[];
        foreach($cases as $case) {
            $prefix=$out.'/'.$run.'-'.preg_replace('/[^a-z0-9-]/','',$case);$receipt=$prefix.'.json';
            $extensionName=DIRECTORY_SEPARATOR==='\\'?'php_fileinfo.dll':'fileinfo.'.PHP_SHLIB_SUFFIX;
            $extensionPaths=[dirname(PHP_BINARY).'/ext/'.$extensionName,rtrim((string)ini_get('extension_dir'),'/\\').'/'.$extensionName];
            $extension=null;foreach($extensionPaths as $candidate)if(is_file($candidate)){$extension=$candidate;break;}
            $cmd=[PHP_BINARY];
            if($extension!==null)$cmd=array_merge($cmd,['-n','-d','extension='.$extension]);
            elseif(php_ini_loaded_file())$cmd=array_merge($cmd,['-c',php_ini_loaded_file()]);
            $cmd=array_merge($cmd,['-d','display_errors=stderr','-d','log_errors=0']);
            $cmd=array_merge($cmd,[__FILE__,'--child',$wp,$out,$case,$receipt]);
            $proc=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__);
            if(!is_resource($proc))throw new RuntimeException('Fixture subprocess failed');
            fclose($pipes[0]);$body=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($proc);
            if($exit!==0||!is_file($receipt))throw new RuntimeException('Fixture case failed: '.$case.'; '.substr($stderr,0,300));
            $r=json_decode(file_get_contents($receipt),true,64,JSON_THROW_ON_ERROR);$records[$case]=$r;
            $pass=static function($v,$s)use(&$checks,$case){$checks[]=['name'=>$case.': '.$s,'pass'=>(bool)$v];if(!$v)throw new RuntimeException($case.': '.$s);};
            if(str_contains($case,'--')) {
                $pass($r['error_status']===404,'publication denial precedes conditional/range delivery');
                $pass($r['file_opens']===0&&$r['path_calls']===0&&$r['headers']===[]&&strlen($body)===0,'no file open, representation headers or 304/206 on denied record');
                continue;
            }
            if(str_ends_with($case,'-budget')||$case==='range-ceiling') {
                $pass($r['error_status']===429,'appropriate request/full-file budget rejects');
                $pass($r['headers']===[]&&strlen($body)===0,'rate-limited request does not emit a file');
                continue;
            }
            if($case==='thumbnail-stale') { $pass($r['error_status']===404&&$r['headers']===[]&&strlen($body)===0,'stale derivative fails closed');continue; }
            if($case==='query') {
                $q=$r['query'];$pass($q['total']===4&&$q['total_pages']===2&&array_column($q['items'],'id')===[46,42],'real archive sort/count/page after eligibility filtering');
                $pass($r['thumbnail_meta_reads']===[46,42],'only selected page thumbnails inspected');
                $json=json_encode($q);$pass(!str_contains($json,'confidential')&&!str_contains($json,'stored')&&!str_contains($json,$r['source_sha256'])&&!str_contains($json,$out),'no private provenance/file names/path/hash in DTO');
                $pass(array_column($q['products'],'count')===[3,1],'product facet counts retain whole eligible set');
                continue;
            }
            $status=in_array($case,['conditional-valid','thumbnail-conditional'],true)?304:($case==='bad-range-416'?416:(in_array($case,['valid-range-121','valid-range-boundary','range-valid','thumbnail-range'],true)?206:200));
            $pass($r['status']===$status&&$r['error_status']===null,'real helper response status');
            $expectedLength=in_array($case,['head-valid','conditional-valid','thumbnail-conditional','bad-range-416'],true)?0:($case==='valid-range-boundary'?1048576:(in_array($case,['valid-range-121','range-valid','thumbnail-range'],true)?5:($case==='thumbnail-valid'?$r['thumbnail_bytes']:$r['source_bytes'])));
            $pass(strlen($body)===$expectedLength,'original-byte body length or empty HEAD/conditional/error');
            if($status===206) {
                $expectedHash=$case==='thumbnail-range'?$r['thumbnail_prefix_sha256']:($case==='valid-range-boundary'?$r['source_1m_sha256']:$r['source_prefix_sha256']);
                $pass(hash('sha256',$body)===$expectedHash,'range uses exact original synthetic representation bytes');
            }
            if($case==='valid-range-121'||$case==='valid-range-boundary')$pass($r['file_bucket_count']===120&&$r['request_bucket_count']===121,'bounded range passes full-budget hit121 without consuming full budget');
            if($case==='conditional-valid')$pass(!isset($r['headers']['content-length'])&&!isset($r['headers']['content-range']),'304 omits representation length/range headers');
            $pass($r['original_sha_after']===$r['source_sha256'],'no original-byte mutation');
        }
        $result=['kind'=>'Isolated controller routing with real installed WP_REST_Request and real file-response helper; synthetic records/files/transients only','passed'=>count($checks),'checks'=>$checks,'cases'=>array_keys($records),'archive_sha256'=>hash_file('sha256',dirname(__DIR__).'/includes/class-report-archive.php'),'response_sha256'=>hash_file('sha256',dirname(__DIR__).'/includes/class-report-file-response.php'),'wordpress_request_sha256'=>hash_file('sha256',$wp.'/wp-includes/rest-api/class-wp-rest-request.php'),'limitations'=>['No WordPress bootstrap, SQL, real reports, authentication middleware, actual HTTP server or browser/device execution.','Budget transients and publication metadata are in-memory stubs; native get_header/get_headers null/repeated-header behavior is real.']];
        file_put_contents($out.'/'.$run.'-summary.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));echo json_encode(['passed'=>$result['passed'],'cases'=>count($cases),'receipt'=>$out.'/'.$run.'-summary.json']),"\n";exit;
    }
    $wp=$argv[2];$out=$argv[3];$case=$argv[4];$receipt=$argv[5];
    if(!is_dir($out)||realpath($out)!==$out||dirname($receipt)!==$out||file_exists($receipt))throw new RuntimeException('Fixture path mismatch');
    define('ABSPATH',$wp.'/');define('MINUTE_IN_SECONDS',60);
    require $wp.'/wp-includes/class-wp-error.php';require $wp.'/wp-includes/class-wp-post.php';require $wp.'/wp-includes/rest-api/class-wp-rest-request.php';
    $GLOBALS['routing']=['case'=>$case,'status'=>null,'error_status'=>null,'headers'=>[],'file_opens'=>0,'path_calls'=>0,'thumbnail_meta_reads'=>[]];$GLOBALS['transients']=[];$GLOBALS['metadata']=[];$GLOBALS['posts']=[];
    function apply_filters($name,$value,...$args){return $value;}
    function do_action($name,...$args): void {}
    function is_wp_error($value): bool{return $value instanceof WP_Error;}
    function __($s,$domain=''){return $s;}
    function nocache_headers(): void {}
    function wp_salt($context=''): string{return 'synthetic-routing-salt';}
    function get_transient($key){return $GLOBALS['transients'][$key]??false;}
    function set_transient($key,$value,$ttl): bool{$GLOBALS['transients'][$key]=$value;return true;}
    function get_post($id){return $GLOBALS['posts'][(int)$id]??null;}
    function get_post_meta($id,$key,$single=false){if($key===\PeptideCore\Report_Archive::THUMB_META)$GLOBALS['routing']['thumbnail_meta_reads'][]=(int)$id;return $GLOBALS['metadata'][(int)$id][$key]??'';}
    function update_meta_cache($kind,$ids){return [];}
    function get_posts($args){$types=(array)$args['post_type'];return array_values(array_filter($GLOBALS['posts'],static fn($p)=>in_array($p->post_type,$types,true)));}
    function get_the_title($id){return 'Synthetic report '.$id;}
    function get_permalink($id){return 'https://coa.example.invalid/product/'.$id;}
    function rest_url($path){return 'https://coa.example.invalid/wp-json/'.$path;}
    function add_query_arg($key,$value,$url){return $url.'?'.rawurlencode($key).'='.rawurlencode($value);}
    function wp_strip_all_tags($v){return strip_tags($v);}
    function current_user_can(...$args){return str_starts_with($GLOBALS['routing']['case'],'admin-no-opt-in');}
    $dir=$out.'/routing-fixture-'.bin2hex(random_bytes(6));if(!mkdir($dir)||realpath(dirname($dir))!==$out)throw new RuntimeException('Fixture directory boundary');
    \PeptideCore\Private_Files::$root=$dir;$pdf=str_repeat('a',32).'.pdf';$png=str_repeat('b',32).'.png';
    $bytes="%PDF-1.4\n% Synthetic routing fixture, not a certificate.\n".str_repeat('A',3145728)."\n%%EOF\n";$thumb=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aXa0AAAAASUVORK5CYII=');
    file_put_contents($dir.'/'.$pdf,$bytes);file_put_contents($dir.'/'.$png,$thumb);$sourceSha=hash('sha256',$bytes);
    $GLOBALS['routing']+=['source_bytes'=>strlen($bytes),'source_sha256'=>$sourceSha,'source_prefix_sha256'=>hash('sha256',substr($bytes,0,5)),'source_1m_sha256'=>hash('sha256',substr($bytes,0,1048576)),'thumbnail_bytes'=>strlen($thumb),'thumbnail_prefix_sha256'=>hash('sha256',substr($thumb,0,5))];
    $file=['stored'=>$pdf,'mime'=>'application/pdf','size'=>strlen($bytes),'original'=>'synthetic-routing.pdf'];$thumbnail=['stored'=>$png,'mime'=>'image/png','size'=>strlen($thumb),'original'=>'synthetic-thumbnail.png','source_sha256'=>$sourceSha];
    require dirname(__DIR__).'/includes/class-report-file-response.php';require dirname(__DIR__).'/includes/class-report-archive.php';
    $record=static function($id,$date,$product=2001)use($file,$thumbnail,$sourceSha){$GLOBALS['posts'][$id]=new WP_Post((object)['ID'=>$id,'post_type'=>'pc_report','post_status'=>'draft','post_password'=>'']);$GLOBALS['metadata'][$id]=['_pc_report_library_public'=>'1','_status'=>'draft','_demo'=>'0','_file'=>$file,'_source_sha'=>$sourceSha,'_pc_report_library_thumbnail'=>$thumbnail,'_issued'=>$date,'_product'=>$product,'_specification'=>'synthetic unit','_source_product'=>'Synthetic product','_source_client'=>'confidential synthetic provenance'];};
    $record(42,'2026-10-07');$GLOBALS['posts'][2001]=new WP_Post((object)['ID'=>2001,'post_type'=>'product','post_status'=>'publish','post_password'=>'']);$GLOBALS['posts'][2002]=new WP_Post((object)['ID'=>2002,'post_type'=>'product','post_status'=>'publish','post_password'=>'']);
    $bucket=static fn($name)=>'pc_report_library_'.$name.'_'.substr(hash_hmac('sha256','192.0.2.44',wp_salt('nonce')),0,32);
    $seed=static function($name,$count)use($bucket){$GLOBALS['transients'][$bucket($name)]=['count'=>$count,'reset'=>time()+600];};
    $request=new WP_REST_Request(str_starts_with($case,'head-')?'HEAD':'GET','/peptide/v1/report-library/42/file');$request->set_url_params(['id'=>42]);
    $rangeCases=['valid-range-121','valid-range-boundary','large-range-budget','multi-range-budget','bad-range-budget','unsatisfiable-range-budget','if-range-budget','empty-if-range-budget','head-budget','range-ceiling','range-valid','empty-if-range-fallback','multiple-range-fallback','bad-range-416','if-range-fallback','thumbnail-range'];
    if(in_array($case,$rangeCases,true)||str_ends_with($case,'--range')||str_ends_with($case,'--thumbnail-range'))$request->set_header('Range','bytes=0-4');
    if($case==='valid-range-boundary')$request->set_header('Range','bytes=0-1048575');
    if($case==='large-range-budget')$request->set_header('Range','bytes=0-1048576');
    if(in_array($case,['multi-range-budget','multiple-range-fallback'],true))$request->set_header('Range','bytes=0-4,8-12');
    if(in_array($case,['bad-range-budget','bad-range-416'],true))$request->set_header('Range','bytes=bad');
    if($case==='unsatisfiable-range-budget')$request->set_header('Range','bytes=999999999-');
    if($case==='if-range-budget'||$case==='if-range-fallback')$request->set_header('If-Range','"different"');
    if($case==='empty-if-range-budget'||$case==='empty-if-range-fallback')$request->set_header('If-Range','');
    if($case==='head-budget')$request->set_method('HEAD');
    if($case==='conditional-valid'||$case==='thumbnail-conditional'||str_ends_with($case,'--conditional')||str_ends_with($case,'--thumbnail-conditional'))$request->set_header('If-None-Match','*');
    if(str_ends_with($case,'-budget')||$case==='valid-range-121'||$case==='valid-range-boundary')$seed('file',120);
    if($case==='valid-range-121'||$case==='valid-range-boundary')$seed('file_request',120);
    if($case==='range-ceiling')$seed('file_request',960);
    if(str_contains($case,'--')) {
        [$state,$action]=explode('--',$case,2);
        if($state==='revoked'||$state==='admin-no-opt-in')$GLOBALS['metadata'][42]['_pc_report_library_public']='0';
        if($state==='withdrawn'||$state==='superseded')$GLOBALS['metadata'][42]['_status']=$state;
        if($state==='password')$GLOBALS['posts'][42]->post_password='synthetic-protected';
        if($state==='demo')$GLOBALS['metadata'][42]['_demo']='1';
        if($state==='private-post')$GLOBALS['posts'][42]->post_status='private';
        if($state==='wrong-post-type')$GLOBALS['posts'][42]->post_type='product';
    }
    register_shutdown_function(static function()use($receipt,$dir,$pdf,$png,$bucket):void {
        $r=$GLOBALS['routing'];$r['file_bucket_count']=$GLOBALS['transients'][$bucket('file')]['count']??0;$r['request_bucket_count']=$GLOBALS['transients'][$bucket('file_request')]['count']??0;$r['original_sha_after']=hash_file('sha256',$dir.'/'.$pdf);
        file_put_contents($receipt,json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        // Only the two exact synthetic files created by this invocation are removed.
        if(realpath(dirname($dir))===realpath(dirname($receipt))){unlink($dir.'/'.$pdf);unlink($dir.'/'.$png);rmdir($dir);}
    });
    if($case==='thumbnail-stale')$GLOBALS['metadata'][42]['_pc_report_library_thumbnail']['source_sha256']=str_repeat('c',64);
    if($case==='query') {
        $record(43,'2026-09-02',2002);$record(44,'');$record(45,'2026-11-01');$GLOBALS['metadata'][45]['_status']='withdrawn';$record(46,'2026-10-10');$record(47,'2026-11-02');$GLOBALS['metadata'][47]['_demo']='1';
        $GLOBALS['routing']['query']=\PeptideCore\Report_Archive::query(['per_page'=>2]);exit;
    }
    $isThumb=str_starts_with($case,'thumbnail-')||str_contains($case,'--thumbnail-');
    $result=$isThumb?\PeptideCore\Report_Archive::rest_thumbnail($request):\PeptideCore\Report_Archive::rest_file($request);
    if($result instanceof WP_Error)$GLOBALS['routing']['error_status']=$result->get_error_data()['status']??null;
}
