'use strict';
const test=require('node:test'),assert=require('node:assert/strict');
const {mount}=require('../assets/campaign-strip-sticky.js');
class Events {
 constructor(){this.handlers=new Map();}
 addEventListener(type,callback){if(!this.handlers.has(type))this.handlers.set(type,new Set());this.handlers.get(type).add(callback);}
 removeEventListener(type,callback){this.handlers.get(type)?.delete(callback);}
 fire(type,event={}){for(const callback of this.handlers.get(type)||[])callback(event);}
}
function style(initial={}){const values=new Map(Object.entries(initial));return{writes:0,getPropertyValue:key=>values.get(key)||'',getPropertyPriority:()=>'',setProperty(key,value){this.writes++;values.set(key,value);},removeProperty(key){values.delete(key);}};}
function classes(){const values=new Set();return{contains:key=>values.has(key),add:key=>values.add(key),remove:key=>values.delete(key)};}
function fixture(){
 const frames=new Map(),observers=[];let serial=0;
 const page={style:style(),classList:classes()},wrapper={style:style(),classList:classes(),contains:()=>true,getBoundingClientRect:()=>({height:44})};
 const hiddenHeader={computed:{position:'sticky',display:'none',top:'0px'},getBoundingClientRect:()=>({width:0,height:0})};
 const header={computed:{position:'sticky',display:'block',top:'0px'},getBoundingClientRect:()=>({width:390,height:68})};
 const markers=[hiddenHeader,header].map(node=>({closest:()=>node}));
 const root={isConnected:true,hasAttribute:()=>true,closest:()=>wrapper};
 const doc={documentElement:page,querySelectorAll:()=>markers,querySelector:()=>markers[0],getElementById:()=>null};
 const window=Object.assign(new Events(),{document:doc,visualViewport:new Events(),getComputedStyle:node=>node.computed,
  requestAnimationFrame(callback){const id=++serial;frames.set(id,callback);return id;},cancelAnimationFrame:id=>frames.delete(id),
  ResizeObserver:class{constructor(callback){this.callback=callback;this.nodes=new Set();observers.push(this);}observe(node){this.nodes.add(node);}unobserve(node){this.nodes.delete(node);}disconnect(){this.nodes.clear();}}
 });
 const controller=mount(root,window);
 return{root,page,wrapper,header,hiddenHeader,frames,window,observers,controller,frame(){const callbacks=[...frames.values()];frames.clear();callbacks.forEach(callback=>callback());}};
}
test('the visible mobile sticky header wins over a hidden desktop header',()=>{
 const f=fixture();assert.equal(f.wrapper.style.getPropertyValue('--vc-campaign-sticky-top'),'68.00px');assert.equal(f.page.style.getPropertyValue('--vc-campaign-anchor-offset'),'124.00px');
 assert.ok(f.observers[0].nodes.has(f.header));assert.ok(!f.observers[0].nodes.has(f.hiddenHeader));assert.ok(!f.observers[0].nodes.has(f.page));f.controller.destroy();
});
test('duplicate resize/font notifications coalesce and unchanged boxes cause no CSS mutations',()=>{
 const f=fixture(),writes=f.wrapper.style.writes+f.page.style.writes;
 for(let i=0;i<10;i++)f.controller.refresh();assert.equal(f.frames.size,1);f.frame();assert.equal(f.wrapper.style.writes+f.page.style.writes,writes);
 f.header.computed.top='46px';f.controller.refresh();f.frame();assert.equal(f.wrapper.style.getPropertyValue('--vc-campaign-sticky-top'),'114.00px');f.controller.destroy();
});
test('BFCache has one owner and ordinary page exit removes pending frames, observers and CSS state',()=>{
 const f=fixture();f.window.fire('pagehide',{persisted:true});assert.equal(mount(f.root,f.window),f.controller);
 f.controller.refresh();f.window.fire('pagehide',{persisted:false});assert.equal(f.frames.size,0);assert.equal(f.root.vcCampaignSticky,undefined);assert.equal(f.wrapper.style.getPropertyValue('--vc-campaign-sticky-top'),'');assert.equal(f.page.style.getPropertyValue('--vc-campaign-anchor-offset'),'');assert.equal(f.observers[0].nodes.size,0);
});
