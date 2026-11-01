'use strict';
// Actual collector+adapter, reusable native fake platform, no network/WP/database.
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const original=fs.readFileSync(path.join(__dirname,'visitor-product-actions.cjs'),'utf8'),marker='async function run()';
assert.equal(original.split(marker).length,2);
let source=original.slice(0,original.indexOf(marker));
source=source.replace('  const context = vm.createContext(window);','  options.prepare?.(window, document, {calls,responses});\n  const context = vm.createContext(window);');
const scope={require,__dirname,console,URL,Headers,AbortController,DOMException,TextEncoder,Uint8Array};vm.runInNewContext(source+';this.fixture=fixture;this.drain=drain;',scope);
const fixture=(config={},options={})=>scope.fixture(config,{page:{page_type:'home',path:'/featured/?private=OMIT#OMIT'},...options});
const response=(events,overrides={})=>({protocol:1,ack:events.map(e=>e.event_id),rejected:[],visitor_id:123,visit_id:456,...overrides});
const nativeResponse=data=>({status:200,ok:true,headers:new Headers(),json:async()=>data});
const take=(calls,url,init)=>{const call={url,...init,body:JSON.parse(init.body)};calls.push(call);return call.body.events;};
function observe(f){const values=[];const unsubscribe=f.window.VialCoveVisitorTransport.subscribePageViews(value=>values.push(value));return{values,unsubscribe};}

test('network failure publishes nothing; actual ACK publishes immutable minimal current PV once with late subscription',async()=>{
 const f=fixture();f.responses.push({network:true});const a=observe(f);assert.equal(a.values.length,0);await f.advance(300);assert.equal(a.values.length,0);
 await f.advance(1100);assert.equal(a.values.length,1);const ref=a.values[0],pv=f.calls[0].body.events.find(e=>e.type==='page_view');
 assert.equal(Object.isFrozen(ref),true);assert.deepEqual(Object.keys(ref).sort(),['event_id','page_instance_id','path','page_type','occurred_at'].sort());
 assert.equal(ref.event_id,pv.event_id);assert.equal(ref.page_instance_id,pv.page_instance_id);assert.equal(ref.occurred_at,pv.occurred_at);assert.equal(ref.path,'/featured/');assert.equal(ref.page_type,'home');assert.equal(JSON.stringify(ref).includes('OMIT'),false);
 const late=observe(f);assert.equal(late.values.length,1);assert.equal(late.values[0],ref);await f.advance(500);assert.equal(a.values.length,1);assert.equal(late.values.length,1);a.unsubscribe();late.unsubscribe();f.stop();
});

test('unsubscribe and throwing listeners are isolated; listener bound is20 and freed slots accept late subscription',async()=>{
 const f=fixture(),seen=[];const gone=observe(f);gone.unsubscribe();f.window.VialCoveVisitorTransport.subscribePageViews(()=>{throw Error('optional');});
 const unsubs=[];for(let i=0;i<20;i++)unsubs.push(f.window.VialCoveVisitorTransport.subscribePageViews(()=>seen.push(i)));
 assert.equal(typeof f.window.VialCoveVisitorTransport.subscribePageViews(null),'function');await f.advance(300);assert.equal(gone.values.length,0);assert.deepEqual(seen,Array.from({length:19},(_,i)=>i));
 unsubs[0]();const replacement=observe(f);assert.equal(replacement.values.length,1);assert.equal(seen.length,19);f.stop();
});

test('metadata retries gate source ACK and retain original identity cleanup',async()=>{
 let requests=0;const config={metadata_protocols:{attribution:1,page_context:1},page_observation:{version:1,page:{page_type:'home',path:'/featured/'},context:{version:1,kind:'home'}}};
 const f=fixture(config,{prepare(window,doc,{calls}){window.fetch=async(url,init)=>{const events=take(calls,url,init),retry=++requests===1,extensions={};
  for(const key of ['attribution','page_context'])extensions[key]={version:1,results:events.filter(e=>Object.hasOwn(e.data,key)).map(e=>({event_id:e.event_id,status:retry?'retry':'applied',code:retry?'server_error':key==='attribution'?'captured':'saved_context',retry}))};
  return nativeResponse(response(events,{extensions}));};}}),a=observe(f);
 await f.advance(300);assert.equal(a.values.length,0);assert.ok(f.stats().metadataPending>0);await f.advance(1100);assert.equal(a.values.length,1);assert.equal(f.stats().metadataPending,0);f.stop();
});

test('permanent rejection, unknown ACK and wrong protocol never become accepted source references',async()=>{
 for(const mode of ['rejected','unknown','protocol']){
  const f=fixture({}, {prepare(window,doc,{calls}){window.fetch=async(url,init)=>{const events=take(calls,url,init);return nativeResponse(response(events,mode==='rejected'?{ack:[],rejected:events.map(e=>({event_id:e.event_id,code:'bad_event',retry:false}))}:mode==='unknown'?{ack:['unrecognized-00000001']}:{protocol:2}));};}}),a=observe(f);
  await f.advance(300);assert.equal(a.values.length,0,mode);assert.equal(observe(f).values.length,0,mode+' late');f.stop();
 }
});

test('unrecognized ordered metadata receipt does not prematurely acknowledge a PV',async()=>{
 const config={metadata_protocols:{attribution:1}};
 const f=fixture(config,{prepare(window,doc,{calls}){window.fetch=async(url,init)=>{const events=take(calls,url,init);return nativeResponse(response(events,{extensions:{attribution:{version:1,results:events.map(e=>({event_id:e.event_id,status:'applied',code:'unrecognized',retry:false}))}}}));};}}),a=observe(f);
 await f.advance(300);assert.equal(a.values.length,0);assert.ok(f.stats().queued>0);f.stop();
});

test('disabled protocol/GPC/stop/denial clear references and return inert subscriptions',async()=>{
 for(const [config,options]of [[{enabled:false},{}],[{protocol:'1'},{}],[{}, {navigator:{globalPrivacyControl:true}}]]){
  const f=fixture(config,options),a=observe(f);await f.advance(300);assert.equal(a.values.length,0);a.unsubscribe();f.stop();
 }
 const f=fixture(),a=observe(f);await f.advance(300);assert.equal(a.values.length,1);f.window.navigator.globalPrivacyControl=true;assert.equal(observe(f).values.length,0);f.window.navigator.globalPrivacyControl=false;
 const b=observe(f);assert.equal(b.values.length,0);f.window.dispatchEvent({type:'pagehide',persisted:true});await scope.drain();f.window.dispatchEvent({type:'pageshow',persisted:true});await f.advance(300);assert.equal(a.values.length,1);assert.equal(b.values.length,1);
 f.window.VialCoveVisitorConfig.enabled=false;assert.equal(observe(f).values.length,0);f.window.VialCoveVisitorConfig.enabled=true;assert.equal(observe(f).values.length,0);f.stop();assert.equal(observe(f).values.length,0);
 const denied=fixture();denied.responses.push({status:403,data:{code:'pc_track_declined'}});const c=observe(denied);await denied.advance(300);assert.equal(c.values.length,0);assert.equal(observe(denied).values.length,0);assert.equal(denied.stats().stopped,true);denied.stop();
});

test('hidden ACK is withheld until foreground; same UID is never delivered twice',async()=>{
 const f=fixture(),a=observe(f);f.document.hidden=true;f.document.visibilityState='hidden';await f.advance(300);assert.equal(a.values.length,0);const late=observe(f);assert.equal(late.values.length,0);
 f.document.hidden=false;f.document.visibilityState='visible';f.document.dispatchEvent({type:'visibilitychange'});assert.equal(a.values.length,1);assert.equal(late.values.length,1);f.document.dispatchEvent({type:'visibilitychange'});assert.equal(a.values.length,1);f.stop();
});

test('pagehide clears current cache and stale old-page ACK cannot fill BFCache current reference',async()=>{
 const held=[];const f=fixture({}, {prepare(window,doc,{calls}){window.fetch=(url,init)=>{const events=take(calls,url,init);return new Promise(resolve=>held.push({events,resolve}));};}}),a=observe(f);
 await f.advance(300);const old=held[0].events.find(e=>e.type==='page_view');assert.ok(old);
 f.window.dispatchEvent({type:'pagehide',persisted:true});await scope.drain();assert.equal(observe(f).values.length,0);f.window.dispatchEvent({type:'pageshow',persisted:true});
 held[0].resolve(nativeResponse(response(held[0].events)));await scope.drain();assert.equal(a.values.length,0,'old ACK is not current');
 for(const item of held.slice(1))item.resolve(nativeResponse(response(item.events)));await scope.drain();await f.advance(300);
 const current=held.find(item=>item.events.some(e=>e.type==='page_view'&&e.event_id!==old.event_id));assert.ok(current);current.resolve(nativeResponse(response(current.events)));await scope.drain();
 assert.equal(a.values.length,1);assert.notEqual(a.values[0].event_id,old.event_id);assert.notEqual(a.values[0].page_instance_id,old.page_instance_id);const late=observe(f);assert.equal(late.values.length,1);f.stop();
});

test('a listener changing privacy prevents later listeners from receiving that reference',async()=>{
 const f=fixture();let first=0,second=0;
 f.window.VialCoveVisitorTransport.subscribePageViews(()=>{first++;f.window.navigator.globalPrivacyControl=true;});
 f.window.VialCoveVisitorTransport.subscribePageViews(()=>second++);await f.advance(300);
 assert.equal(first,1);assert.equal(second,0);f.window.navigator.globalPrivacyControl=false;assert.equal(observe(f).values.length,0);f.stop();
});
