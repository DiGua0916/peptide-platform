'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const source=fs.readFileSync(path.resolve(__dirname,'../assets/product-template-prefetch.js'),'utf8');
class Events{constructor(){this.handlers=new Map();}addEventListener(k,f){if(!this.handlers.has(k))this.handlers.set(k,new Set());this.handlers.get(k).add(f);}removeEventListener(k,f){this.handlers.get(k)?.delete(f);}fire(k,e={}){for(const f of [...this.handlers.get(k)||[]])f(e);}}
function fixture({connection={},supported=true,idle=true}={}){
 const window=new Events(),document=new Events(),timers=new Map(),frames=new Map(),idles=new Map(),reads=[],templates=new Map(),inflight=new Map();let next=0,now=0;
 const link=id=>({dataset:{vcHomeProduct:String(id),vcQuickOpen:String(id)},href:'https://shop.test/product/'+id,isConnected:true,disabled:false,getAttribute(){return null;},getClientRects(){return [1];},closest(selector){return selector.includes('[inert]')?null:this;},contains(el){return el===this;}});
 const hero=link(7);document.hidden=false;document.querySelector=selector=>selector.startsWith('template')?templates.get(selector.match(/"(\d+)"/)?.[1])||null:hero;document.readyState='complete';
 Object.assign(window,{document,navigator:{connection},location:{origin:'https://shop.test',href:'https://shop.test/'},jQuery:{},vcHomeQuickView:{productApi:'https://shop.test/wp-json/vialcove/v1/home-product/'},HTMLDialogElement:supported?{prototype:{showModal(){}}}:null,fetch(){throw Error('module must use shared loader only');},AbortController:class{abort(){throw Error('subscriber must not abort shared transport');}},
 VialCoveHomeQuickView:{loadTemplate(id){if(inflight.has(id))return inflight.get(id);let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b;});inflight.set(id,promise);reads.push({id,promise,resolve(){const node={remove(){if(templates.get(id)===node)templates.delete(id);}};templates.set(id,node);inflight.delete(id);resolve();},reject(error){inflight.delete(id);reject(error);}});return promise;}},
 setTimeout(fn,delay){const id=++next;timers.set(id,{fn,delay});return id;},clearTimeout(id){timers.delete(id);},requestAnimationFrame(fn){const id=++next;frames.set(id,fn);return id;},cancelAnimationFrame(id){frames.delete(id);}});
 if(idle){window.requestIdleCallback=(fn,options)=>{assert.equal(options.timeout,1200);const id=++next;idles.set(id,fn);return id;};window.cancelIdleCallback=id=>idles.delete(id);}
 vm.runInNewContext(source,{window,document,URL,Date:{now:()=>now}});
 return {window,document,hero,link,reads,timers,frames,idles,templates,advance(ms){now+=ms;},click(el){const event={target:el,button:0,defaultPrevented:false};window.fire('click',event);document.fire('click',event);if(!templates.has(el.dataset.vcHomeProduct))return window.VialCoveHomeQuickView.loadTemplate(el.dataset.vcHomeProduct);},frame(){const work=[...frames.values()];frames.clear();work.forEach(fn=>fn());},idle(){const work=[...idles.values()];idles.clear();work.forEach(fn=>fn());},timer(delay){for(const [id,item]of [...timers])if(item.delay===delay){timers.delete(id);item.fn();}},intent(el){document.fire('pointerover',{target:el});}};
}
const settle=async()=>{for(let i=0;i<8;i++)await Promise.resolve();};
test('one hero template only after paint and idle; shared native loader receives ID only, no catalogue sweep',async()=>{
 const f=fixture();assert.equal(f.reads.length,0);f.frame();assert.equal(f.reads.length,0);f.frame();assert.equal(f.reads.length,0);f.idle();assert.deepEqual(f.reads.map(x=>x.id),['7']);f.reads[0].resolve();await settle();f.idle();assert.equal(f.reads.length,1);
});
test('debounced pointer/focus intent deduplicates, one in flight, and only two unique templates per page',async()=>{
 const f=fixture();f.frame();f.frame();f.idle();const second=f.link(8),third=f.link(9);f.intent(second);f.timer(180);assert.equal(f.reads.length,1);f.reads[0].resolve();await settle();assert.deepEqual(f.reads.map(x=>x.id),['7','8']);f.document.fire('focusin',{target:second});f.timer(180);f.intent(third);f.timer(180);f.reads[1].resolve();await settle();assert.equal(f.reads.length,2);
});
test('leaving before debounce and unsupported/disabled/foreign links never start reads',()=>{
 const f=fixture();const el=f.link(8);f.intent(el);f.document.fire('pointerout',{target:el,relatedTarget:null});f.timer(180);assert.equal(f.reads.length,0);
 const bad=f.link(9);bad.disabled=true;f.intent(bad);f.timer(180);bad.disabled=false;bad.href='https://evil.test/product/9';f.intent(bad);f.timer(180);assert.equal(f.reads.length,0);
 for(const opts of [{supported:false},{connection:{saveData:true}},{connection:{effectiveType:'2g'}},{connection:{effectiveType:'slow-2g'}}]){const g=fixture(opts);g.frame();g.frame();g.idle();g.intent(g.link(8));g.timer(180);assert.equal(g.reads.length,0);}
});
test('pagehide and hidden retire queued work; late shared completion cannot start another read or abort a native click',async()=>{
 for(const event of ['pagehide','visibilitychange']){const f=fixture();f.frame();f.frame();f.idle();f.intent(f.link(8));f.timer(180);if(event==='visibilitychange')f.document.hidden=true;(event==='pagehide'?f.window:f.document).fire(event);f.reads[0].resolve();await settle();assert.equal(f.reads.length,1);assert.equal(f.timers.size+f.frames.size+f.idles.size,0);}
 const f=fixture();f.window.fire('pagehide');f.frame();f.idle();assert.equal(f.reads.length,0);
});
test('failed speculative read is silent and bounded; fallback stays deferred',async()=>{
 const f=fixture({idle:false});f.frame();f.frame();assert.equal(f.reads.length,0);f.timer(1000);assert.equal(f.reads.length,1);f.reads[0].reject(new Error('speculative offline'));await settle();f.intent(f.hero);f.timer(180);assert.equal(f.reads.length,1);
 const enqueue=fs.readFileSync(path.resolve(__dirname,'../inc/home-quick-view.php'),'utf8');assert.match(enqueue,/wp_enqueue_script\( 'vc-product-template-prefetch',[\s\S]*?array\( 'vc-home-quick-view' \)/);
});
test('unused prefetched template expires; throttled expiry timer cannot give a later native click stale commerce HTML',async()=>{
 const f=fixture();f.frame();f.frame();f.idle();f.reads[0].resolve();await settle();assert.ok(f.templates.has('7'));
 f.advance(30001);f.click(f.hero);assert.equal(f.templates.has('7'),false,'window capture removes expired unclaimed node before native document capture');assert.equal(f.reads.length,2,'actual click now uses native fresh loader');
 const g=fixture();g.frame();g.frame();g.idle();g.reads[0].resolve();await settle();g.timer(30000);assert.equal(g.templates.has('7'),false,'ordinary expiry evicts unused HTML');
});
test('fresh click followed by presentation and already presented products never evict active native view',async()=>{
 for(const mode of ['click','presented']){const f=fixture();f.frame();f.frame();f.idle();f.reads[0].resolve();await settle();if(mode==='click')f.click(f.hero);f.document.fire('vc:product-presented',{detail:{productId:7}});f.advance(3600000);f.timer(30000);f.window.fire('pagehide');assert.ok(f.templates.has('7'));assert.equal(f.reads.length,1);}
});
test('hidden/exit evicts unused HTML and late unclaimed results, but retains inflight genuine shared click result',async()=>{
 const f=fixture();f.frame();f.frame();f.idle();f.reads[0].resolve();await settle();f.document.hidden=true;f.document.fire('visibilitychange');assert.equal(f.templates.size,0);
 const g=fixture();g.frame();g.frame();g.idle();g.window.fire('pagehide');g.reads[0].resolve();await settle();assert.equal(g.templates.size,0,'late unused result must not survive in BFCache');
 const h=fixture();h.frame();h.frame();h.idle();h.window.fire('pagehide');const click=h.click(h.hero);assert.equal(h.reads.length,1,'genuine click shares pending native read');h.reads[0].resolve();await click;await settle();assert.ok(h.templates.has('7'),'retired speculative owner must not evict genuinely pending late result');h.document.fire('vc:product-presented',{detail:{productId:7}});assert.equal(h.timers.size,0);assert.equal(h.window.handlers.get('click')?.size||0,0);assert.equal(h.document.handlers.get('vc:product-presented')?.size||0,0);
});
test('superseded real click cannot permanently claim a late unpresented speculative result',async()=>{
 const f=fixture();f.frame();f.frame();f.idle();const a=f.click(f.hero),bLink=f.link(8),b=f.click(bLink);assert.equal(f.reads.length,2);
 f.reads[1].resolve();await b;f.document.fire('vc:product-presented',{detail:{productId:8}});f.reads[0].resolve();await a;await settle();assert.ok(f.templates.has('7'));f.advance(30001);f.timer(30000);assert.equal(f.templates.has('7'),false,'A was never presented, so its unused HTML must expire');assert.ok(f.templates.has('8'),'actual presented B remains native owned');
});
