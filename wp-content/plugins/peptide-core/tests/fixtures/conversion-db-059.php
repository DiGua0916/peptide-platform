<?php
/**
 * 转化报表纯逻辑用例共用的假 $wpdb（原来写在 data-classified-conversion-pure.php 里，原样搬出来，m6-reconcile-conversion-pure.php 也用它）。
 * 先 require fixtures/visit-classification-boundaries.php，再 require 这个文件。
 */
class ConversionDB059 extends ClassificationDB059 {
 public array $event_scans=array();public array $cohort_scans=array();
 public function cohort($sql){preg_match('/(?:s\.)?start_ms\s*>=\s*([0-9]+).*?(?:s\.)?start_ms\s*<\s*([0-9]+)/',$sql,$m);$from=(int)($m[1]??0);$to=(int)($m[2]??PHP_INT_MAX);return array_filter($this->visits,static fn($s)=>$s['start_ms']>=$from&&$s['start_ms']<$to);}
 public function get_row($sql,$format){if(str_contains($sql,'COUNT(DISTINCT')){$rows=$this->cohort($sql);$r=array('mobile'=>0,'avg_ms'=>0);foreach(array('visitors'=>0,'viewed'=>1,'carted'=>2,'checkout'=>3)as$key=>$stage){$set=array();foreach($rows as$s){if($s['stage']>=$stage){$set[$s['visitor_id']]=true;}}$r[$key]=count($set);$r['r_'.$key]=0;}return$r;}return parent::get_row($sql,$format);}
 public function get_results($sql,$format){
  if($this->fail){return parent::get_results($sql,$format);}$a=$this->args($sql);
  if(str_contains($sql,'SELECT s.*,v.browser')){$this->queries[]=$sql;$rows=array_values(array_filter($this->cohort($sql),static fn($s)=>$s['id']>$a[2]));usort($rows,static fn($a,$b)=>$a['id']<=>$b['id']);$rows=array_slice($rows,0,500);$this->cohort_scans[]=count($rows);return array_map(fn($s)=>$s+array('matched_visitor_id'=>$this->visitors[$s['visitor_id']]['id']??null,'owner_browser'=>$this->visitors[$s['visitor_id']]['browser']??'','visitor_test'=>$this->visitors[$s['visitor_id']]['is_test']??1),$rows);}
  if(str_contains($sql,'SELECT e.*,s.order_id AS linked_order')){$this->queries[]=$sql;$ids=$this->ids($sql);$rows=array_values(array_filter($this->events,static fn($r)=>in_array($r['visit_id'],$ids,true)&&$r['id']>$a[0]&&$r['type']!=='visit_classification'));usort($rows,static fn($a,$b)=>$a['id']<=>$b['id']);$rows=array_slice($rows,0,500);$this->event_scans[]=count($rows);return array_map(fn($r)=>$this->decorate($r),$rows);}
  if(str_contains($sql,'FROM qa_visits WHERE id IN')){$rows=array_values(array_intersect_key($this->visits,array_fill_keys($this->ids($sql),true)));return str_contains($sql,'device AS g')?array_map(static fn($s)=>array('id'=>$s['id'],'g'=>$s['device']),$rows):$rows;}
  if(str_contains($sql,"type = 'page_view'")&&str_contains($sql,'ORDER BY occurred_ms DESC')){$ids=$this->ids($sql);$rows=array_values(array_filter($this->events,static fn($r)=>$r['type']==='page_view'&&in_array($r['visit_id'],$ids,true)&&$r['occurred_ms']<$a[0]&&($r['occurred_ms']<$a[1]||$r['occurred_ms']===$a[1]&&$r['id']<$a[3])));usort($rows,static fn($a,$b)=>($b['occurred_ms']<=>$a['occurred_ms'])?:($b['id']<=>$a['id']));return array_slice($rows,0,500);}
  if(str_contains($sql,'SELECT id,visitor_id FROM qa_visits WHERE')){return array_values($this->cohort($sql));}
  if(str_contains($sql,'SELECT id, device AS g FROM qa_visits')){return array_map(static fn($s)=>array('id'=>$s['id'],'g'=>$s['device']),array_values(array_intersect_key($this->visits,array_fill_keys($this->ids($sql),true))));}
  if(str_contains($sql,'SELECT id, browser AS g FROM qa_visitors')){return array_map(static fn($v)=>array('id'=>$v['id'],'g'=>$v['browser']),array_values(array_intersect_key($this->visitors,array_fill_keys($this->ids($sql),true))));}
  if(str_contains($sql,'GROUP BY s.device')||str_contains($sql,'GROUP BY COALESCE')){$groups=array();foreach($this->cohort($sql)as$s){$g=str_contains($sql,'browser')?($this->visitors[$s['visitor_id']]['browser']??''):$s['device'];foreach(array('visitors'=>0,'viewed'=>1,'carted'=>2,'checkout'=>3)as$key=>$stage){if($s['stage']>=$stage){$groups[$g][$key][$s['visitor_id']]=true;}}}$rows=array();foreach($groups as$g=>$sets){$r=array('g'=>$g);foreach(array('visitors','viewed','carted','checkout')as$key){$r[$key]=count($sets[$key]??array());}$rows[]=$r;}return$rows;}
  return parent::get_results($sql,$format);
 }
}
