'use strict';
const test=require('node:test'), assert=require('node:assert/strict'), vm=require('node:vm'),fs=require('node:fs'),path=require('node:path');
const source=fs.readFileSync(path.resolve(__dirname,'../assets/product-image-warmup.js'),'utf8');
class Events { constructor(){this.events=new Map();} addEventListener(k,fn){if(!this.events.has(k))this.events.set(k,new Set());this.events.get(k).add(fn);}removeEventListener(k,fn){this.events.get(k)?.delete(fn);}fire(k){for(const fn of [...this.events.get(k)||[]])fn();} }
function fixture(rows, connection={}, idle=true){
 const window=new Events(),document=new Events(),dialog=new Events(),images=[],timers=new Map(),idles=new Map();let serial=0;
 dialog.open=true;document.hidden=false;const form={getAttribute(name){assert.equal(name,'data-product_variations');return JSON.stringify(rows);}};
 Object.assign(window,{document,location:{origin:'https://shop.test',href:'https://shop.test/'},navigator:{connection},setTimeout(fn,delay){const id=++serial;timers.set(id,{fn,delay});return id;},clearTimeout(id){timers.delete(id);},Image:class{constructor(){this.order=[];images.push(this);}set fetchPriority(v){this.order.push(['fetchPriority',v]);}set sizes(v){this.order.push(['sizes',v]);}set srcset(v){this.order.push(['srcset',v]);}set src(v){this.order.push(['src',v]);}removeAttribute(name){this.order.push(['remove',name]);}}});
 if(idle){window.requestIdleCallback=(fn,options)=>{assert.equal(options.timeout,1200);const id=++serial;idles.set(id,fn);return id;};window.cancelIdleCallback=id=>idles.delete(id);}
 vm.runInNewContext(source,{window,URL});
 return {window,document,dialog,form,images,timers,api:window.VialCoveProductImageWarmup,start(){return this.api.start(form,dialog);},idle(){const tasks=[...idles.values()];idles.clear();tasks.forEach(fn=>fn());},fallback(){for(const [id,task]of [...timers])if(task.delay===400){timers.delete(id);task.fn();}}};
}
const row=n=>({price:999,stock_status:'irrelevant',vc_home_image:{src:`/image-${n}.webp`,srcset:`/image-${n}.webp 500w, /image-${n}-large.webp 1000w`}});
test('native images defer, load at low priority in exact responsive source order, concurrency2 and cap12',()=>{
 const f=fixture(Array.from({length:20},(_,n)=>row(n)));f.start();assert.equal(f.images.length,0);f.idle();assert.equal(f.images.length,2);
 assert.deepEqual(f.images[0].order.map(x=>x[0]),['fetchPriority','sizes','srcset','src']);assert.equal(f.images[0].order[0][1],'low');assert.equal(f.images[0].order[1][1],'(max-width: 767px) calc(100vw - 64px), 500px');
 for(let n=0;n<12;n++){assert.ok(f.images[n]);f.images[n].onload();assert.ok(f.images.length<=Math.min(12,n+3));}assert.equal(f.images.length,12);
});
test('own null overrides fallback; missing foreign and mixed foreign srcset never request',()=>{
 const f=fixture([{vc_home_image:null,image:{src:'/fallback.webp'}},{image:{src:'/legacy.webp'}},{},{vc_home_image:{src:'https://evil.test/x'}},{vc_home_image:{src:'/ok',srcset:'/ok 500w, https://evil.test/x 1000w'}},{vc_home_image:{src:'data:image/png;base64,ABC'}}]);f.start();f.idle();assert.equal(f.images.length,1);assert.equal(f.images[0].order.at(-1)[1],'https://shop.test/legacy.webp');
});
test('repeat same product and completed responsive keys deduplicate without touching metadata or current form',()=>{
 const rows=[row(1),row(1),row(2)],before=JSON.stringify(rows),f=fixture(rows);const stop=f.start();assert.equal(f.start(),stop);f.idle();assert.equal(f.images.length,2);f.images.forEach(img=>img.onload());stop();f.start();f.idle();assert.equal(f.images.length,2);assert.equal(JSON.stringify(rows),before);
});
test('close, hidden and pagehide retire idle and active queues, and late callbacks cannot start more',()=>{
 for(const event of ['close','pagehide','visibilitychange']){const f=fixture([row(1),row(2),row(3)]);f.start();f.idle();const late=f.images[0].onload;if(event==='close')f.dialog.open=false;if(event==='visibilitychange')f.document.hidden=true;(event==='close'?f.dialog:event==='pagehide'?f.window:f.document).fire(event);late();assert.equal(f.images.length,2);assert.equal(f.timers.size,0);assert.equal(f.images[0].onload,null);}
 const f=fixture([row(1)]);f.start();f.dialog.fire('close');f.idle();assert.equal(f.images.length,0);
});
test('SaveData and slow connections skip; fallback defers; closed and hidden skip',()=>{
 for(const connection of [{saveData:true},{effectiveType:'2g'},{effectiveType:'slow-2g'}]){const f=fixture([row(1)],connection);f.start();f.idle();assert.equal(f.images.length,0);}
 const f=fixture([row(1)],{},false);f.start();assert.equal(f.images.length,0);f.fallback();assert.equal(f.images.length,1);
 for(const flag of ['closed','hidden']){const g=fixture([row(1)]);if(flag==='closed')g.dialog.open=false;else g.document.hidden=true;g.start();g.idle();assert.equal(g.images.length,0);}
});
test('stalled image deadline retires its resource before another queued image starts',()=>{
 const f=fixture([row(1),row(2),row(3)]);f.start();f.idle();const deadline=[...f.timers.values()][0];deadline.fn();
 assert.deepEqual(f.images[0].order.slice(-2),[['remove','srcset'],['remove','src']]);assert.equal(f.images.length,3);assert.equal(f.images[0].onload,null);
});
