/* Actual collector/adapter; reuse only browser/clock/transport boundaries. */
'use strict';
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const {createRequire}=require('node:module');
const fixturePath=path.join(__dirname,'visitor-metadata-push.cjs'),source=fs.readFileSync(fixturePath,'utf8');
const marker='(async () => { await attributionCases();';
assert.equal(source.split(marker).length,2);
const box={require:createRequire(fixturePath),__dirname,console,process,URL,Headers,DOMException,AbortController,TextEncoder,Uint8Array,Date};
vm.runInNewContext(source.slice(0,source.indexOf(marker))+'\nglobalThis.native046={fixture,pv,drain,json};',box,{filename:fixturePath});
const {fixture,pv,drain,json}=box.native046;
let passed=0; const check=(name,yes)=>{assert.ok(yes,name);++passed;};
const known={version:1,status:'known',term:'demo peptide'};
const observation=search=>({version:1,page:{page_type:'content',path:'/'},context:{version:1,kind:'search',search}});
const setup=(search=known,config={})=>fixture({observation:observation(search),config:{search_terms_protocol:1,...config}});
(async()=>{
 for(const search of [known,{version:1,status:'empty'},{version:1,status:'filtered'},{version:1,status:'known',term:'研究 肽'}, {version:1,status:'known',term:"o'hara + test-2.0"}]){
  const f=setup(search);await f.advance();check('canonical native search snapshot survives actual transport',JSON.stringify(pv(f.calls[0]).data.page_context.search)===JSON.stringify(search));f.stop();
 }
 for(const bad of [{version:'1',status:'empty'},{version:2,status:'empty'},{version:1,status:'known',term:'UPPER'},{version:1,status:'known',term:'private@example.test'},{version:1,status:'known',term:'1234567'},{version:1,status:'known',term:'www.example.test'},{version:1,status:'filtered',term:null},{version:1,status:'known',term:'肽'.repeat(81)}]){
  const f=setup(bad);await f.advance();check('noncanonical search atomically omits extension and preserves PV',pv(f.calls[0]).type==='page_view'&&!Object.hasOwn(pv(f.calls[0]).data,'page_context')&&f.stats().extensionUnsupported===1);f.stop();
 }
 for(const capability of [undefined,true,'1',2,null]){
  let reads=0;const raw=observation(known);Object.defineProperty(raw.context,'search',{get(){++reads;throw Error('PRIVATE_RAW');}});
  const f=fixture({observation:raw,config:{search_terms_protocol:capability}});await f.advance();check('old/malformed capability does not read or retain search payload',reads===0&&pv(f.calls[0]).data.page_context.kind==='search'&&!Object.hasOwn(pv(f.calls[0]).data.page_context,'search'));f.stop();
 }
 {const f=setup(known,{metadata_protocols:{attribution:1}});await f.advance();check('search requires independent page-context capability',!Object.hasOwn(pv(f.calls[0]).data,'page_context'));f.stop();}
 {const f=setup({...known,raw:'PRIVATE_RAW'});f.scenarios.push({network:true});await f.advance();const first=json(pv(f.calls[0])),tab=f.calls[0].body.tab_id;f.config.page_observation.context.search.term='changed';f.rotate();await f.advance(1100);check('network retry/rotation retain same UID/tab/search and prune raw fields',JSON.stringify(pv(f.calls[1]))===JSON.stringify(first)&&f.calls[1].body.tab_id===tab&&!JSON.stringify(f.calls).includes('PRIVATE_RAW'));f.hide();await drain();f.show();await f.advance();const newest=f.calls.flatMap(c=>c.body.events).filter(e=>e.type==='page_view').at(-1);check('restored document emits new UID with its frozen server search',newest.event_id!==first.event_id&&newest.data.page_context.search.term==='demo peptide');f.stop();}
 {const f=setup();await f.advance(90);f.action();f.scenarios.push({status:413},{network:true});await f.advance(400);const first=json(pv(f.calls[0]));f.rotate();await f.advance(1100);const copies=f.calls.flatMap(c=>c.body.events).filter(e=>e.event_id===first.event_id);check('actual413 split+retry freezes full search metadata',f.calls[0].body.events.length===2&&copies.length>=3&&copies.every(e=>JSON.stringify(e)===JSON.stringify(first)));f.stop();}
 {const f=fixture({adapter:false}),packets=[];const input={version:1,kind:'search',search:{...known,raw:'PRIVATE_RAW'}};const observer=f.window.VialCoveVisitorObserver.start({context:{page_type:'content',path:'/'},pageViewMetadata:()=>({page_context:input}),sendBatch:async events=>{packets.push(events);return {ackEventIds:events.map(e=>e.event_id)};}});input.search.term='next term';await f.advance();const first=packets[0][0];check('collector deeply copies/freezes canonical search before caller mutation',first.data.page_context.search.term==='demo peptide'&&Object.isFrozen(first.data.page_context.search)&&!JSON.stringify(packets).includes('PRIVATE_RAW'));f.hide();await drain();f.show();await f.advance();const next=packets.flat().filter(e=>e.type==='page_view').at(-1);check('new genuine PV can capture a fresh canonical source term',next.event_id!==first.event_id&&next.data.page_context.search.term==='next term');observer.stop();}
 for(const config of [{enabled:false},{endpoint:'https://foreign.test/events'}]){const f=setup(known,config);await f.advance();check('startup privacy/endpoint gates stay inert',f.calls.length===0);f.stop();}
 {const f=setup();f.scenarios.push({status:403});await f.advance();f.hide();f.show();await f.advance();check('server privacy denial stops all later search collection',f.stats().stopped&&f.calls.length===1);f.stop();}
 {const f=setup();await f.advance();f.document.dispatchEvent({type:'vc:visitor-action',detail:{type:'search',data:{term:'PRIVATE_FORM'}}});f.document.dispatchEvent({type:'vc:visitor-action',detail:{type:'cart_open',data:{source:'normal',search:{...known,term:'PRIVATE_FORM'}}}});await f.advance(400);check('public actions cannot inject search terms or page context',!JSON.stringify(f.calls).includes('PRIVATE_FORM')&&f.calls.flatMap(c=>c.body.events).filter(e=>e.type!=='page_view').every(e=>!Object.hasOwn(e.data,'search')&&!Object.hasOwn(e.data,'page_context')));f.stop();}
 console.log(JSON.stringify({suite:'native-search-browser-boundaries',passed,failed:0,nativeBrowser:false}));
})().catch(error=>{console.error(error);process.exitCode=1;});
