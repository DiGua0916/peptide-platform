'use strict';
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(path.join(__dirname,'visitor-transport.cjs'),'utf8').split('async function run()')[0];
const box={require,__dirname,console,Headers,URL,AbortController,DOMException,TextEncoder};vm.runInNewContext(source+';globalThis.h={fixture};',box);
let passed=0;const check=(label,yes)=>{assert.ok(yes,label);passed++;};
function target(){const section={id:'vc-sample',dataset:{module:'demo-catalog'}};const card={dataset:{vcHomeProduct:'42'}};return{disabled:false,getAttribute:()=>null,matches:()=>false,closest:selector=>selector==='[data-module]'?section:selector.includes('data-vc-home-product')?card:null};}
(async()=>{
 const f=box.h.fixture();try{
  await f.advance(300);const control=target();const event=trusted=>({type:'click',isTrusted:trusted,button:0,target:{closest:selector=>selector==='a,button,summary,[role="button"]'?control:null}});
  f.document.dispatchEvent(event(false));await f.advance(1000);check('programmatic clicks do not create preference events',!f.calls.flatMap(c=>c.body.events).some(e=>e.type==='module_click'));
  f.document.dispatchEvent(event(true));await f.advance(1000);const records=f.calls.flatMap(c=>c.body.events);const module=records.find(e=>e.type==='module_click'),product=records.find(e=>e.type==='product_click');
  check('trusted module click uses stable identifier',module?.data.module_id==='vc-sample'&&module.data.module_type==='demo-catalog');check('trusted product click counts product separately',product?.data.product_id===42);check('no control text or destination is sent',Object.keys(module.data).sort().join(',')==='module_id,module_type');
  const before=records.length;f.window.navigator={globalPrivacyControl:true};f.document.dispatchEvent(event(true));await f.advance(1000);check('GPC suppresses new preference clicks',f.calls.flatMap(c=>c.body.events).length===before);
 }finally{f.stop();}
 console.log(JSON.stringify({passed,failed:0}));
})().catch(e=>{console.error(e);process.exitCode=1;});
