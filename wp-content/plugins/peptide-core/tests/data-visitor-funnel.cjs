/* Exercise the actual renderers and their event handlers, without dependencies. */
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
let passed = 0;
const check = (name, value) => { assert.ok(value, name); ++passed; };
const flush = () => new Promise(resolve => setImmediate(resolve));
const keys = ['visitors','viewed','carted','checkout','address','shipping','payment','paid'];
function layers(values = [10,8,6,4,3,3,2,1]) {
  return keys.map((key,i) => ({key,count:values[i],visitor_rate:values[0] ? values[i]/values[0]*100 : null,step_rate:i && values[i-1] ? values[i]/values[i-1]*100 : null}));
}
const detail = () => ({unit:'visitors',current:layers(),previous:layers(),change:{}});
const row = (key='desktop') => ({key,sales:20,conversion:20,funnel:[],visitor_funnel:{unit:'visitors',layers:layers()}});
class Element {
  constructor() { this.innerHTML=''; this.events={}; this.attrs={}; this.nodes={}; this.nodeType=1; this.value='device'; }
  querySelector(selector) { return this.nodes[selector] || (this.nodes[selector]=new Element()); }
  addEventListener(name,fn) { this.events[name]=fn; }
  setAttribute(key,value) { this.attrs[key]=value; }
  getAttribute(key) { return this.attrs[key]; }
  hasAttribute(key) { return Object.hasOwn(this.attrs,key); }
  focus() { this.focused=true; }
}
function harness(name) {
  let renderer;
  const calls=[];
  const strings=new Proxy({ofVisitors:'Of visitors: %s',groupRate:'%s conversion',viewDetails:'View details',hideDetails:'Hide details',paidVisitors:'Paying visitors',detailUnavailable:'DETAIL UNAVAILABLE'}, {get:(o,k)=>o[k] || String(k)});
  const ctx={esc:value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])),number:v=>String(v??0),money:v=>String(v??0),change:v=>v==null?'-':String(v),strings:{loading:'Loading',loadError:'Failed',vs:'VS'},currency:{code:'USD'},api:(url,params)=>new Promise((resolve,reject)=>calls.push({url,params,resolve,reject})),register:(key,fn)=>{renderer=fn;}};
  const window={PcData:ctx,localStorage:{getItem:()=>null,setItem:()=>{}}};
  window['pcData'+name[0].toUpperCase()+name.slice(1)]={strings};
  const exports=name==='operations'?'funnel:funnel,view:view,expanded:expanded':name==='conversion'?'card:card,overall:overall,view:view,expanded:expanded':'steps:function(values,detailed){ctx=window.PcData;return steps(values,detailed);}';
  let source=fs.readFileSync(path.join(__dirname,'../assets/data-'+name+'.js'),'utf8');
  source=source.replace("\tPcData.register( '", '\twindow.__test022={'+exports+'};\n\tPcData.register( \'');
  vm.runInNewContext(source,{window,document:{addEventListener(){}},console,Date});
  return {renderer,ctx,calls,api:window.__test022};
}
const count = (html,cls) => (html.match(new RegExp('class="'+cls+'"','g')) || []).length;
const range={preset:'today',dates:{from:'2026-10-30',to:'2026-10-30'}};
(async()=>{
  const rt=harness('realtime');
  let html=rt.api.steps(layers(),true);
  check('realtime actual detailed renderer has eight rows',count(html,'pc-rt-step')===8);
  check('realtime includes three middle labels and visitor-paid label',html.includes('address')&&html.includes('shipping')&&html.includes('payment')&&html.includes('Paying visitors'));
  html=rt.api.steps(layers().filter(x=>!['address','shipping','payment'].includes(x.key)),false);
  check('missing detailed realtime response is explicitly legacy five',count(html,'pc-rt-step')===5&&html.includes('DETAIL UNAVAILABLE'));
  const ops=harness('operations'), block=detail();
  html=ops.api.funnel('Visitors',block,ops.ctx,'visitor');
  check('operations default projection five',count(html,'pc-ops-step')===5&&html.includes('aria-expanded="false"'));
  check('collapsed paid step uses checkout denominator',html.includes('1 (25%)'));
  ops.api.expanded.visitor=true;
  html=ops.api.funnel('Visitors',block,ops.ctx,'visitor');
  check('operations detailed projection eight',count(html,'pc-ops-step')===8&&html.includes('aria-expanded="true"'));
  check('expanded paid step uses payment denominator',html.includes('1 (50%)'));
  check('returning projection remains independent',count(ops.api.funnel('Returning',block,ops.ctx,'returning'),'pc-ops-step')===5);
  ops.api.expanded.visitor=false;
  const body=new Element(); ops.renderer(body,range,ops.ctx);
  ops.calls[0].resolve({tracking:true,current:{},previous:{},change:{},visitor_funnel:block,returning_visitor_funnel:detail()}); await flush();
  const box=body.querySelector('.pc-ops'), button=new Element(); button.attrs['data-funnel-toggle']='visitor'; button.parentNode=box;
  check('operations real click handler attached',typeof box.events.click==='function');
  box.events.click({target:button});
  const slot=box.querySelector('[data-funnel-slot="visitor"]');
  check('operations actual click expands eight',count(slot.innerHTML,'pc-ops-step')===8);
  check('operations keyboard focus restored to replacement button',slot.querySelector('[data-funnel-toggle]').focused===true);
  const body2=new Element(); ops.renderer(body2,range,ops.ctx);
  ops.calls[3].resolve({tracking:true,current:{},previous:{},change:{},visitor_funnel:detail(),returning_visitor_funnel:detail()}); await flush();
  check('operations repaint preserves expansion',body2.innerHTML.includes('aria-expanded="true"')&&count(body2.innerHTML,'pc-ops-step')===13);
  box.events.click({target:button});
  check('operations actual second click restores five',count(slot.innerHTML,'pc-ops-step')===5);
  const conv=harness('conversion');
  html=conv.api.card('device',row(),conv.ctx);
  check('conversion card defaults five',count(html,'pc-conv-step')===5&&html.includes('aria-controls="pc-conv-detail-device%3Adesktop"'));
  const token='device%3Adesktop'; conv.api.expanded[token]=true;
  html=conv.api.card('device',row(),conv.ctx);
  check('conversion card expands to eight',count(html,'pc-conv-step')===8);
  check('conversion expanded adjacent rate is recomputed',html.includes('↓</span> 50%'));
  check('conversion other group is independent',count(conv.api.card('device',row('mobile'),conv.ctx),'pc-conv-step')===5);
  html=conv.api.overall({visitor_funnel:detail()},conv.ctx);
  check('conversion overall contains conversion plus eight behavior metrics',count(html,'pc-conv-tile')===9);
  check('overall paid label is visitors',html.includes('Paying visitors'));
  html=conv.api.card('browser',row('<img src=x onerror=bad>'),conv.ctx);
  check('group identity escaped and controls encoded',!html.includes('<img src=x')&&html.includes('&lt;img')&&html.includes('%3Cimg'));
  html=conv.api.card('device',{key:'desktop',funnel:layers().filter(x=>!['address','shipping','payment'].includes(x.key))},conv.ctx);
  check('legacy conversion fallback has five and no pretend toggle',count(html,'pc-conv-step')===5&&html.includes('DETAIL UNAVAILABLE')&&!html.includes('data-conv-toggle'));
  const cb=new Element(); conv.api.expanded[token]=false; conv.renderer(cb,range,conv.ctx);
  const result=cb.querySelector('.pc-conv-result');
  conv.calls[0].resolve({tracking:true,group:'device',visitor_funnel:detail(),rows:[row(),row('mobile')],checkout_steps:{labels:{checkout_view:'Observed visit'},sources:{},current:{checkout_view:1},previous:{},change:{},channels:[]}}); await flush();
  check('conversion retains visit-based observed table',result.innerHTML.includes('Observed visit')&&result.innerHTML.includes('pc-conv-checkout-table'));
  const toggle=new Element(); toggle.attrs['data-conv-toggle']=token; toggle.parentNode=result; result.events.click({target:toggle});
  const cs=result.querySelector('[data-conv-slot="'+token+'"]');
  check('conversion actual delegated click expands eight',count(cs.innerHTML,'pc-conv-step')===8&&cs.querySelector('[data-conv-toggle]').focused);
  result.events.click({target:toggle}); check('conversion actual second click restores five',count(cs.innerHTML,'pc-conv-step')===5);
  cb.querySelector('.pc-conv-group').value='browser'; cb.querySelector('.pc-conv-group').events.change();
  check('group change sends correct request',conv.calls[1].params.group==='browser');
  conv.calls[1].reject(new Error('failure')); await flush(); check('API failure has visible error',result.innerHTML.includes('Failed'));
  const old=new Element(); conv.renderer(old,range,conv.ctx); const older=conv.calls[2];
  conv.renderer(old,{...range,preset:'yesterday'},conv.ctx); const newer=conv.calls[3];
  newer.resolve({tracking:true,group:'browser',overall:{},rows:[]}); await flush(); const latest=old.querySelector('.pc-conv-result').innerHTML;
  older.resolve({tracking:true,group:'browser',visitor_funnel:detail(),rows:[row('STALE')]}); await flush();
  check('stale response does not overwrite newer range',old.querySelector('.pc-conv-result').innerHTML===latest&&!latest.includes('STALE'));
  const zeros={unit:'visitors',layers:layers([0,0,0,0,0,0,0,0])};
  html=conv.api.card('device',{...row(),visitor_funnel:zeros},conv.ctx);
  check('empty funnel has no NaN or Infinity',!html.includes('NaN')&&!html.includes('Infinity'));
  console.log(JSON.stringify({passed,failed:0}));
})().catch(error=>{console.error(error);process.exitCode=1;});
