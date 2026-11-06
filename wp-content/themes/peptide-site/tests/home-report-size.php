<?php
/** Render actual homepage report template: only authoritative integer DTO size. */
define('ABSPATH',__DIR__);
function esc_attr($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}function esc_html($v){return esc_attr($v);}function esc_url($v){return esc_attr($v);}function __($v,...$args){return $v;}
function vc_demo_home_items($m,$context){return $m['items'];}function vc_demo_home_item_attrs($item){return '';}
function vc_module_media_ref($ref){return [];}function vc_module_button(...$args){return '';}
function vc_demo_home_report($id){return ['title'=>'Public report','view_url'=>'/wp-json/peptide/v1/report-library/'.$id.'/file?download=0','file'=>['size_bytes'=>$id===1?3713351:'3713351" onclick="bad']];}
$s=array_fill_keys(['eyebrow','title','titleAccent','cardNote','description','cardEyebrow','previewCaption'],'');$s['button']=null;
$args=['module'=>['settings'=>$s,'items'=>[['settings'=>['reportId'=>1]],['settings'=>['reportId'=>2]],['settings'=>['reportId'=>0,'label'=>'Manual URL','value'=>'','reportLink'=>['href'=>'/manual.pdf']]]]]];
ob_start();require dirname(__DIR__).'/template-parts/modules/demo-report-feature.php';$html=ob_get_clean();
if(substr_count($html,'data-vc-report-size-bytes=')!==1)throw new RuntimeException('Only authoritative integer size may be emitted');
if(!str_contains($html,'data-vc-report-size-bytes="3713351"'))throw new RuntimeException('Exact DTO size lost');
if(str_contains($html,'onclick='))throw new RuntimeException('Malformed metadata escaped size boundary');
if(substr_count($html,'data-vc-demo-report')!==3)throw new RuntimeException('Unknown-size/manual report links lost');
echo "4 actual homepage report-size provenance checks passed.\n";
