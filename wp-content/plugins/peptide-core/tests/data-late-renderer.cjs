/* Actual shell/export/Sources footer order, including an already-parsed document. */
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
let passed=0;const check=(label,ok)=>{assert.ok(ok,label);++passed;};const flush=()=>new Promise(resolve=>setImmediate(resolve));
let boundary=fs.readFileSync(path.join(__dirname,'data-exports.cjs'),'utf8').split('(async()=>{')[0];
boundary=boundary.replace('function harness(report) {',"function harness(report,mode='late') {")
  .replace("readyState:'loading'","readyState:mode==='early'?'loading':'complete'")
  .replace("search:''},history", "search:mode==='range'?'?range=yesterday':''},history")
  .replace("(report==='products'?'Products':'Pages')","(report==='products'?'Products':report==='sources'?'Sources':'Pages')")
  .replace("const prefix=report==='products'?'prod':'pages'","const prefix=report==='products'?'prod':report==='sources'?'sources':'pages'")
  .replace('document.events.DOMContentLoaded();','if(document.events.DOMContentLoaded)document.events.DOMContentLoaded();');
const scope={require,__dirname,console,URL,URLSearchParams,setImmediate};vm.runInNewContext(boundary+';globalThis.boundary={harness};',scope);
const harness=scope.boundary.harness;
const dates={from:'2026-10-30',to:'2026-10-30',compare_from:'2026-10-29',compare_to:'2026-10-29'};
const data=d=>({range:{dates:d},sort:'uv',order:'desc',page:1,per_page:20,split_medium:0,q:'',total:1,rows:[{utm_source:{state:'value',value:'SOURCE'},uv:1,customers:1,viewed_uv:1,carted_uv:1,paid_uv:0,orders:0,conversion:0,sales:0,aov:0,uv_value:0}]});
(async()=>{
  for(const mode of ['early','late']){
    const h=harness('sources',mode);check(mode+' shell and real footer register exactly one Sources request',h.calls.length===1&&h.last().path==='/peptide/v1/data/sources');
    h.last().resolve(data(dates));await flush();check(mode+' actual Sources table replaces shell placeholder',!!h.el('wrap')&&h.el('wrap').innerHTML.includes('SOURCE')&&!h.root.innerHTML.includes('building')&&!h.el('export').disabled);
    const before=h.calls.length;h.window.PcData.register('other',()=>{throw Error('Wrong page rendered');});check(mode+' another page registration never repaints current body',h.calls.length===before&&h.el('wrap').innerHTML.includes('SOURCE'));
    let duplicated=0;h.window.PcData.register('sources',()=>{++duplicated;});check(mode+' already registered renderer does not mount twice',duplicated===0&&h.calls.length===before&&h.el('wrap').innerHTML.includes('SOURCE'));
  }
  const h=harness('sources','range');check('late registration waits while accepted URL range is unresolved',h.calls.length===1&&h.last().path==='/peptide/v1/data/range');
  const selected={...dates,from:'2026-10-29',to:'2026-10-29'};h.last().resolve({preset:'yesterday',dates:selected});await flush();check('accepted initial URL range starts one Sources request with accepted dates',h.calls.length===2&&h.last().path==='/peptide/v1/data/sources'&&h.last().params.from===selected.from);
  h.last().resolve(data(selected));await flush();check('late renderer consumes accepted range and renders real table',h.el('wrap').innerHTML.includes('SOURCE')&&!h.el('export').disabled);
  console.log(JSON.stringify({passed,failed:0}));
})().catch(error=>{console.error(error);process.exitCode=1;});
