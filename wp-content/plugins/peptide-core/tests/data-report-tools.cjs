/* Run the unmodified product report in a small DOM/API/clock harness. No packages. */
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(path.join(__dirname, '../assets/data-products.js'), 'utf8');
let passed = 0;
const check = (label, value) => { assert.ok(value, label); ++passed; };
const flush = () => new Promise(resolve => setImmediate(resolve));
const decode = value => value.replace(/&(?:amp|lt|gt|quot|#39);/g, entity => ({'&amp;':'&','&lt;':'<','&gt;':'>','&quot;':'"','&#39;':"'"}[entity]));
function harness(saved = null, blocked = false) {
  const document = {activeElement:null};
  class Element {
    constructor(tag = 'div', attrs = {}) { this.tagName = tag; this.attrs = attrs; this.children = []; this.events = {}; this.nodeType = 1; this.value = ''; this.checked = Object.hasOwn(attrs, 'checked'); this.disabled = Object.hasOwn(attrs, 'disabled'); }
    set innerHTML(html) {
      this.html = html; this.children = [];
      const stack = [this];
      for (const match of html.matchAll(/<\/?([a-z][\w-]*)([^>]*)>/gi)) {
        if (match[0][1] === '/') { if (stack.length > 1) stack.pop(); continue; }
        const attrs = {};
        for (const attribute of match[2].matchAll(/([\w-]+)(?:="([^"]*)")?/g)) attrs[attribute[1]] = decode(attribute[2] || '');
        const node = new Element(match[1], attrs); node.parentNode = stack.at(-1); stack.at(-1).children.push(node);
        if (!['input','img','br','hr'].includes(match[1])) stack.push(node);
      }
    }
    get innerHTML() { return this.html || ''; }
    hasAttribute(key) { return Object.hasOwn(this.attrs, key); }
    getAttribute(key) { return this.attrs[key] ?? null; }
    setAttribute(key, value) { this.attrs[key] = String(value); }
    removeAttribute(key) { delete this.attrs[key]; }
    querySelectorAll(selector) {
      const match = selector.match(/^(?:\.([\w-]+)|([\w-]+))?(?:\[([\w-]+)(?:="([^"]*)")?\])?$/);
      assert.ok(match, 'supported DOM selector: ' + selector);
      const nodes = [];
      const visit = node => { for (const child of node.children) { if ((!match[1] || (child.attrs.class || '').split(' ').includes(match[1])) && (!match[2] || child.tagName === match[2]) && (!match[3] || child.hasAttribute(match[3]) && (match[4] === undefined || child.getAttribute(match[3]) === match[4]))) nodes.push(child); visit(child); } };
      visit(this); return nodes;
    }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
    addEventListener(name, fn) { this.events[name] = fn; }
    focus() { document.activeElement = this; }
    fire(name, extra = {}) { if (name === 'click' && this.disabled) return; const event = {target:this,preventDefault(){},...extra}; for (let node = this; node; node = node.parentNode) if (node.events[name]) node.events[name](event); }
  }
  const storage = {value:saved}, writes = [], calls = [], timers = new Map();
  let renderer, timerId = 0;
  const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const ctx = {esc:escape,number:String,money:String,change:v=>v==null?'-':String(v),strings:{loading:'Loading',loadError:'Failed',vs:'VS'},api:(url,params)=>new Promise((resolve,reject)=>calls.push({url,params,resolve,reject})),register:(key,fn)=>{assert.equal(key,'products');renderer=fn;}};
  const strings = {product:'Product',views:'Views',carted:'Carted',sales:'Sales',search:'Search',columns:'Columns',moveUp:'Move %s up',moveDown:'Move %s down',noData:'No data',page:'Page %1$d of %2$d',prev:'Previous',next:'Next',sku:'SKU %s',variations:'Variations'};
  const window = {PcData:ctx,pcDataProducts:{strings},localStorage:{getItem(){if(blocked)throw Error('blocked');return storage.value;},setItem(key,value){if(blocked)throw Error('blocked');assert.equal(key,'pcDataProductsColumns');storage.value=value;writes.push(value);}},clearTimeout:id=>timers.delete(id),setTimeout:(fn,ms)=>{timers.set(++timerId,{fn,ms});return timerId;}};
  vm.runInNewContext(source,{window,document,console});
  let body;
  const mount = (range = {preset:'today',dates:{}}) => {body = new Element();renderer(body,range,ctx);};
  mount();
  const el = suffix => body.querySelector('.pc-prod-' + suffix);
  const headers = () => el('wrap').querySelectorAll('button[data-sort]').map(node=>node.getAttribute('data-sort'));
  const boxes = () => el('columns').querySelectorAll('input[data-key]');
  const box = key => boxes().find(node=>node.getAttribute('data-key') === key);
  const move = (key,dir) => el('columns').querySelectorAll('button[data-move-key]').find(node=>node.getAttribute('data-move-key') === key && node.getAttribute('data-move-dir') === String(dir));
  const search = text => {el('search').value=text;el('search').fire('keydown',{key:'Enter'});};
  const tick = () => {const pending = [...timers.values()];timers.clear();pending.forEach(item=>item.fn());};
  return {calls,storage,writes,document,mount,el,headers,boxes,box,move,search,timers,tick,strings};
}
function report(name = 'CURRENT') {
  return {metrics:['views','carted','sales'],defaults:['views','sales'],sort:'views',order:'desc',page:1,per_page:20,total:1,rows:[{product:{id:1,name,edit_url:'/edit',sku:'SKU',image:'/image'},current:{views:4,sales:20},previous:{views:2,sales:10},change:{views:100,sales:100},variations:[{variation:{id:2,name:'Child'},current:{views:1},previous:{},change:{}}]}]};
}
(async()=>{
  const h = harness();
  check('initial pending chooser disabled and empty',h.el('columns-btn').disabled && h.el('columns').children.length===0);
  h.calls[0].resolve(report());await flush();
  check('default order follows validated defaults',h.headers().join() === 'views,sales');
  h.el('columns-btn').fire('click');
  check('selected entries precede unselected API entries',h.boxes().map(node=>node.getAttribute('data-key')).join() === 'views,sales,carted');
  check('native boundaries and metric-specific accessible names',h.move('views',-1).disabled && h.move('sales',1).disabled && h.move('sales',-1).getAttribute('aria-label')==='Move Sales up');
  check('buttons are outside checkbox labels',h.move('sales',-1).parentNode.tagName==='span' && h.move('sales',-1).parentNode.parentNode.tagName==='div');
  h.move('sales',-1).fire('click');
  check('move persists ordered keys and table order',h.storage.value==='["sales","views"]' && h.headers().join()==='sales,views');
  check('move focuses the moved checkbox, including new boundary',h.document.activeElement===h.box('sales') && h.move('sales',-1).disabled && h.box('sales').checked);
  h.box('carted').checked=true;h.box('carted').fire('change');
  check('new checkbox appends to selected order',h.headers().join()==='sales,views,carted');
  h.box('views').checked=false;h.box('views').fire('change');
  check('uncheck preserves remaining order',h.headers().join()==='sales,carted');
  const staleBox=h.box('sales'), staleMove=h.move('carted',-1);
  h.search('NEXT');
  check('new query immediately clears rows and closes/removes chooser children',h.el('wrap').innerHTML.includes('Loading') && !h.el('wrap').innerHTML.includes('CURRENT') && h.el('columns-btn').disabled && h.el('columns').hasAttribute('hidden') && h.el('columns').children.length===0 && h.el('columns-btn').getAttribute('aria-expanded')==='false');
  h.el('columns-btn').fire('click');h.el('columns').fire('change',{target:staleBox});h.el('columns').fire('click',{target:staleMove});
  check('pending chooser cannot revive old rows or preferences',h.el('wrap').innerHTML.includes('Loading') && h.storage.value==='["sales","carted"]');
  h.calls.at(-1).reject(Error('network'));await flush();
  h.el('columns').fire('change',{target:staleBox});h.el('columns').fire('click',{target:staleMove});h.el('columns-btn').fire('click');
  check('failure chooser remains empty/disabled and cannot replace error',h.el('wrap').innerHTML.includes('Failed') && !h.el('wrap').innerHTML.includes('CURRENT') && h.el('columns-btn').disabled && h.el('columns').children.length===0);
  h.search('RECOVERY');h.calls.at(-1).resolve(report('RECOVERED'));await flush();
  check('search recovery reenables latest report and preserves preference',!h.el('columns-btn').disabled && h.el('wrap').innerHTML.includes('RECOVERED') && h.headers().join()==='sales,carted');
  h.el('wrap').querySelector('button[data-sort="sales"]').fire('click');
  check('sort remains usable and requests chosen sort',h.calls.at(-1).params.sort==='sales' && h.el('columns-btn').disabled);
  const old=h.calls.at(-1);h.search('LATEST');h.calls.at(-1).resolve(report('LATEST'));await flush();old.resolve(report('STALE'));await flush();
  check('late older success never overwrites latest rows',h.el('wrap').innerHTML.includes('LATEST') && !h.el('wrap').innerHTML.includes('STALE'));
  h.search('OLDER');const oldError=h.calls.at(-1);h.search('NEWER');h.calls.at(-1).resolve(report('NEWER'));await flush();oldError.reject(Error('late'));await flush();
  check('late older failure never replaces current report',h.el('wrap').innerHTML.includes('NEWER') && !h.el('columns-btn').disabled);
  h.mount({preset:'yesterday',dates:{}});h.calls.at(-1).resolve(report('RANGE'));await flush();
  check('range remount preserves ordered preference and resets page',h.headers().join()==='sales,carted' && h.calls.at(-1).params.range==='yesterday' && h.calls.at(-1).params.page===1);
  const reload=harness(h.storage.value);reload.calls[0].resolve(report());await flush();
  check('reload reads the same stored ordered array',reload.headers().join()==='sales,carted');
  const dirty=harness('["obsolete","sales","sales",42,"views"]');dirty.calls[0].resolve(report());await flush();
  check('unknown and duplicate keys removed without sorting valid preference',dirty.headers().join()==='sales,views');
  const obsolete=harness('["gone"]');obsolete.calls[0].resolve(report());await flush();
  check('all obsolete nonempty preference falls back to defaults',obsolete.headers().join()==='views,sales');
  const empty=harness('[]');empty.calls[0].resolve(report());await flush();
  check('explicit empty array preserves identity column only',empty.headers().length===0 && empty.el('wrap').querySelectorAll('th').length===1 && empty.el('wrap').innerHTML.includes('CURRENT'));
  const denied=harness(null,true);denied.calls[0].resolve(report());await flush();denied.move('sales',-1).fire('click');
  check('blocked storage leaves reorder usable in memory',denied.headers().join()==='sales,views');
  const safe=harness();safe.strings.sales='<img src=x onerror="bad">';const unsafe=report('<script>bad</script>');unsafe.metrics.push('x" onclick="bad');unsafe.defaults.push('x" onclick="bad');safe.calls[0].resolve(unsafe);await flush();
  check('labels, keys, names and accessible names escaped',!safe.el('wrap').innerHTML.includes('<script>') && !safe.el('columns').innerHTML.includes('<img') && safe.el('columns').innerHTML.includes('&quot;bad') && safe.box('x" onclick="bad')!==undefined);
  const ime=harness();ime.calls[0].resolve(report());await flush();ime.el('search').fire('compositionstart');ime.el('search').value='中文';ime.el('search').fire('input');ime.el('search').fire('keydown',{key:'Enter'});
  check('IME does not query during composition',ime.calls.length===1 && ime.timers.size===0);
  ime.el('search').fire('compositionend');check('search debounce remains 400 milliseconds',[...ime.timers.values()][0].ms===400);ime.tick();check('completed composition searches exactly once',ime.calls.length===2 && ime.calls[1].params.q==='中文');
  const page=harness();const many=report();many.total=41;page.calls[0].resolve(many);await flush();page.el('pager').querySelector('button[data-dir="1"]').fire('click');const out=report();out.rows=[];out.total=1;out.page=2;page.calls.at(-1).resolve(out);await flush();
  check('out-of-bounds correction stays pending with no actionable stale chooser',page.calls.at(-1).params.page===1 && page.el('columns-btn').disabled && page.el('wrap').innerHTML.includes('Loading'));
  page.calls.at(-1).resolve(report());await flush();page.el('wrap').querySelector('button[data-toggle]').fire('click');
  check('variation expansion survives chooser redraw',!page.el('wrap').querySelector('tr[data-parent="1"]').hasAttribute('hidden'));page.move('sales',-1).fire('click');check('expanded variation remains open after reorder',!page.el('wrap').querySelector('tr[data-parent="1"]').hasAttribute('hidden'));
  const noData=harness();const zero=report();zero.total=0;zero.rows=[];noData.calls[0].resolve(zero);await flush();noData.move('sales',-1).fire('click');
  check('empty response retains truthful empty message and usable preferences',noData.el('wrap').innerHTML.includes('No data') && noData.storage.value==='["sales","views"]');
  console.log(JSON.stringify({passed,failed:0}));
})().catch(error=>{console.error(error);process.exitCode=1;});
