/* Actual visitor -> timeline script, with complete pinned post030 script as the old-format oracle. */
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
let passed=0;const check=(name,ok)=>{assert.ok(ok,name);++passed;};
let boundary=fs.readFileSync(path.join(__dirname,'tracking-page-context.cjs'),'utf8').split('function event(')[0];
boundary=boundary.replace('../../../../tests/fixtures/data-observations/028/tracking-admin.js','../../../../tests/fixtures/data-observations/028/tracking-admin.js');
boundary=boundary.replace('const strings=new Proxy({types:',"const strings=new Proxy({observedProduct:'Observed product ID',observedVariation:'Observed variation ID',visibleFraction:'Visible fraction',visibleDuration:'Visible duration (ms)',pageEntity:'Page entity ID',templateId:'Template ID',moduleId:'Module ID',types:");
const context={require,__dirname,console,setImmediate};vm.runInNewContext(boundary+'\nthis.actualRender=render;this.actualSource=source;this.oldSource=original;',context);
const render=(events,old=false)=>context.actualRender(old?context.oldSource:context.actualSource,events);
const observed={version:1,product_id:123,variation_id:0,visible_fraction:0.4,visible_ms:500,context:{kind:'product',entity_id:999,template_id:7,module_id:'m-example'}};
const event=(data=observed,extra={})=>({at_ms:10000,tab:'tab-0001',type:'product_impression',source:'client',path:'/Case/',page_type:'product',product_id:123,variation_id:0,data,...extra});
(async()=>{
 let r=await render([event()]);check('new observed exposure name remains distinct from product_view',r.html.includes('product_impression'));
 check('restricted details include parent visibility duration context and optional identifiers',r.html.includes('Observed product ID=#123')&&r.html.includes('Visible fraction=40%')&&r.html.includes('Visible duration (ms)=500')&&r.html.includes('Page kind=product')&&r.html.includes('Page entity ID=999')&&r.html.includes('Template ID=7')&&r.html.includes('Module ID=m-example'));
 check('nested data is neither raw JSON nor object coercion',!r.html.includes('[object Object]')&&!r.html.includes('"version"')&&!r.html.includes('context='));
 check('actual startup uses visitor then timeline requests',r.calls.length===2&&r.calls[1].path.includes('/visits/1?limit=200&heartbeats=0'));
 for(const [key,value] of Object.entries({product_id:9007199254740992,variation_id:9007199254740992,visible_fraction:0.399999,visible_ms:499,version:2})){
  r=await render([event({...observed,[key]:value})]);check('unsafe or malformed observation is omitted '+key,!r.html.includes('Observed product ID='));
 }
 for(const [key,value] of Object.entries({entity_id:9007199254740992,template_id:9007199254740992,module_id:'<img onerror=bad>'})){
  r=await render([event({...observed,context:{...observed.context,[key]:value}})]);check('optional field validation fails atomically '+key,!r.html.includes('Observed product ID=')&&!r.html.includes('<img'));
 }
 for(const extra of[{source:'server'},{product_id:124},{variation_id:1},{product_id:9007199254740992}]){r=await render([event(observed,extra)]);check('source and column/data identity mismatch is omitted',!r.html.includes('Observed product ID='));}
 r=await render([event({...observed,variation_id:124},{variation_id:124})]);check('optional variation is readable once',r.html.includes('Observed variation ID=#124')&&!r.html.includes('variation=124'));
 const d={...observed,product_id:9007199254740991,context:{kind:'unknown'}};delete d.variation_id;r=await render([event(d,{product_id:9007199254740991})]);check('max safe ID and omitted variation remain exact',r.html.includes('#9007199254740991')&&!r.html.includes('Observed variation ID='));
 for(const e of[
  {type:'heartbeat',source:'client',data:{activity:'active',a:null}},
  {type:'product_view',source:'client',data:{product_id:123}},
  {type:'page_view',source:'client',data:{page_context:{version:1,kind:'shop',geo:{status:'unknown'}},cause:'<old>'}},
  {type:'page_view',source:'client',data:{attribution:{version:1,host:'example.test',landing_query:[],referrer_host:null}}}
 ]){const old=await render([event(e.data,e)],true),now=await render([event(e.data,e)]);check('complete old timeline HTML stays byte-exact '+e.type,old.html===now.html);check('old API/cursor paths stay exact '+e.type,old.calls.map(c=>c.path).join('|')===now.calls.map(c=>c.path).join('|'));}
 console.log('TOTAL '+passed+' PASS, 0 FAIL');
})().catch(error=>{console.error(error);process.exitCode=1;});
