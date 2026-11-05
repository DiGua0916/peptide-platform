'use strict';
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {JSDOM}=require(path.join(process.env.HQ_NAV_MODEL_DEPS||'D:/Codex/Tasks/2026-11-03/hq-navigation/work/model-deps','node_modules/jsdom'));
const code=fs.readFileSync(path.join(__dirname,'../assets/tracking-admin.js'),'utf8'),flush=()=>new Promise(r=>setImmediate(r));
let passed=0;const check=(label,yes)=>{assert.ok(yes,label);passed++;};
(async()=>{
 const dom=new JSDOM('<div id="pc-trk-app"></div>',{url:'https://store.example.invalid/wp-admin/admin.php?page=pc-visitors',runScripts:'outside-only',pretendToBeVisual:true}),w=dom.window,calls=[];let interval;
 try{
  w.setInterval=fn=>{interval=fn;return 1;};w.clearInterval=()=>{};
  const strings=new Proxy({pageTypes:{},stages:{}},{get:(o,k)=>o[k]||String(k)});
  w.pcTrackingAdmin={view:'live',strings,liveUrl:'/live',archiveUrl:'/archive',timezone:'UTC'};
  w.wp={apiFetch:options=>new Promise((resolve,reject)=>calls.push({options,resolve,reject}))};w.eval(code);
  check('one initial request',calls.length===1);interval();interval();check('polls never overlap pending requests',calls.length===1);
  const visitor=(id,label,status)=>({visitor:{id,label,device:'desktop',browser:'Other',visits:1},status,tabs:[],visit:null,last_signal_ms:1000,cart:null});
  const data={now_ms:1000,visitors:[visitor(1,'Synthetic A','active'),visitor(2,'Synthetic B','idle')]};calls[0].resolve(data);await flush();
  const input=w.document.querySelector('[name=visitor_search]');input.value='Synthetic B';input.dispatchEvent(new w.Event('input'));check('search filters existing records',w.document.querySelector('#pc-trk-app').textContent.includes('Synthetic B')&&!w.document.querySelector('#pc-trk-app').textContent.includes('Synthetic A'));
  const select=w.document.querySelector('[name=visitor_status]');select.value='active';select.dispatchEvent(new w.Event('change'));check('status filter composes with search',!w.document.querySelector('#pc-trk-app').textContent.includes('Synthetic B'));
  const button=w.document.querySelector('.pc-trk-live-tools button');button.click();interval();check('pause suppresses refresh',calls.length===1&&button.getAttribute('aria-pressed')==='true');
  button.click();check('resume starts one request',calls.length===2);const prior=w.document.querySelector('#pc-trk-app').innerHTML;calls[1].reject(Error('offline'));await flush();check('network failure retains current list',w.document.querySelector('#pc-trk-app').innerHTML===prior);check('failure exposes retry state',w.document.querySelector('[role=status]').textContent.includes('重试'));
 }finally{w.dispatchEvent(new w.Event('pagehide'));w.close();}
 console.log(JSON.stringify({passed,failed:0}));
})().catch(e=>{console.error(e);process.exitCode=1;});
