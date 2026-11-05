'use strict';
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {JSDOM}=require(path.join(process.env.HQ_NAV_MODEL_DEPS||'D:/Codex/Tasks/2026-11-03/hq-navigation/work/model-deps','node_modules/jsdom'));
const source=fs.readFileSync(path.join(__dirname,'../assets/tracking-insights.js'),'utf8');
const flush=()=>new Promise(r=>setImmediate(r));let passed=0;const check=(label,yes)=>{assert.ok(yes,label);passed++;};
function facts(){return {schema_version:1,summary:{active_seconds:30,pageviews:2,visits:1,observed_events:5},period:{from:'2026-11-04',to:'2026-11-04',timezone:'UTC'},coverage:{partial:false,tracking_enabled:true},pages:[{path:'<img src=x onerror=alert(1)>',views:2,active_seconds:30}],modules:[],products:[]};}
(async()=>{
 const d=new JSDOM('<div id="pc-trk-insights"></div>',{url:'https://store.example.invalid/wp-admin/admin.php?page=pc-visitors&visitor=1',runScripts:'outside-only'}),w=d.window,calls=[];
 try {
  w.pcTrackingAdmin={visitor:1,today:'2026-11-04'};w.wp={apiFetch:options=>new Promise((resolve,reject)=>calls.push({options,resolve,reject}))};w.eval(source);
  check('single initial request',calls.length===1);check('customer scope is explicit',calls[0].options.path.includes('visitor_id=1'));
  check('export unavailable while request pending',w.document.querySelector('[data-insight-export]').disabled);
  w.document.querySelector('[data-insight-period=week]').click();check('prior request is aborted',calls[0].options.signal.aborted);check('week is sent to server',calls[1].options.path.includes('period=week'));
  calls[0].resolve(facts());await flush();check('stale result cannot render',w.document.querySelector('.pc-insights-results').textContent==='');
  calls[1].resolve(facts());await flush();check('accepted response renders duration',w.document.querySelector('.pc-interest-summary').textContent.includes('30 秒'));check('data is text not executable HTML',!w.document.querySelector('.pc-insights-results img'));
  check('empty products have no misleading scroll hint',!w.document.querySelector('.pc-interest-scroll-hint'));
  check('customer AI has its own visible section',w.document.querySelector('.pc-interest-ai').textContent.includes('客户分析 AI'));
  check('export becomes available for complete data',!w.document.querySelector('[data-insight-export]').disabled);
  w.document.querySelector('[data-insight-period=month]').click();const partial=facts();partial.coverage.partial=true;calls[2].resolve(partial);await flush();check('partial data cannot be exported as complete',w.document.querySelector('[data-insight-export]').disabled);check('partial coverage is disclosed',w.document.querySelector('[role=status]').textContent.includes('部分数据'));
  w.document.querySelector('[data-insight-retry]').click();const unready=facts();unready.coverage.schema_ready=false;unready.summary={active_seconds:null,pageviews:null,visits:null,observed_events:null};calls[3].resolve(unready);await flush();check('uninitialised tracking is not fabricated zeros',w.document.querySelector('.pc-interest-summary').textContent.includes('未采集')&&w.document.querySelector('.pc-interest-summary').textContent.includes('—'));check('uninitialised tracking cannot export',w.document.querySelector('[data-insight-export]').disabled);
  w.document.querySelector('[data-insight-retry]').click();calls[4].reject(new Error('offline'));await flush();check('failure has retry guidance',w.document.querySelector('[role=status]').textContent.includes('刷新数据'));check('failure clears busy',!w.document.querySelector('#pc-trk-insights').hasAttribute('aria-busy'));
 } finally {w.close();}
 console.log(JSON.stringify({passed,failed:0}));
})().catch(e=>{console.error(e);process.exitCode=1;});
