'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const source=fs.readFileSync(path.join(__dirname,'../assets/product-dialog-experience.js'),'utf8');
function fixture(){
  const timers=new Map(),queries=[],observers=[];let serial=0,active=true,moves=0;
  function target(){return {events:new Map(),addEventListener(type,fn,options){const list=this.events.get(type)||[];list.push(fn);this.events.set(type,list);options?.signal?.addEventListener('abort',()=>this.events.set(type,(this.events.get(type)||[]).filter(item=>item!==fn)),{once:true});},fire(type,extra={}){for(const fn of [...(this.events.get(type)||[])])fn(extra);}};}
  const document=Object.assign(target(),{hidden:false}),window=target(),media=Object.assign(target(),{children:[],contains:value=>value===media}),dialog={open:true};
  const reduced=Object.assign(target(),{matches:false,removeEventListener(type,fn){this.events.set(type,(this.events.get(type)||[]).filter(item=>item!==fn));}});
  const track=Object.assign(target(),{scrollLeft:0,getBoundingClientRect:()=>({left:0}),scrollTo({left,behavior}){this.scrollLeft=left;this.behavior=behavior;moves++;}});
  const slides=[0,390].map(offset=>({hidden:false,disabled:false,getBoundingClientRect:()=>({left:offset-track.scrollLeft})}));
  class IntersectionObserver {constructor(callback,options){this.callback=callback;this.options=options;observers.push(this);}observe(){}disconnect(){this.disconnected=true;}emit(visible){this.callback([{isIntersecting:visible,intersectionRatio:visible?1:0}]);}}
  const context=vm.createContext({window,document,IntersectionObserver,matchMedia:()=>reduced,setTimeout(fn,delay){const id=++serial;timers.set(id,{fn,delay});return id;},clearTimeout(id){timers.delete(id);}});
  vm.runInContext(source.slice(source.indexOf('  function createGalleryPlayback('),source.indexOf('  function reportPresentationKey(')),context);
  const controller=new AbortController();require('node:events').setMaxListeners(0,controller.signal);
  const api=context.createGalleryPlayback({track,slides,dialog,media,signal:controller.signal,isActive:()=>active});
  return {api,document,window,media,dialog,reduced,track,slides,timers,observers,controller,setActive(value){active=value;api.refresh();},get moves(){return moves;},tick(){const [id,row]=[...timers][0]||[];if(row){timers.delete(id);row.fn();}}};
}
test('autoplay waits for visible eligible slides, loops once per 6.5 seconds, adds no controls and coalesces refreshes',()=>{
  const f=fixture();assert.equal(f.timers.size,0);f.observers[0].emit(true);assert.equal(f.timers.size,1);assert.equal([...f.timers.values()][0].delay,6500);
  for(let i=0;i<100;i++)f.api.refresh();assert.equal(f.timers.size,1);f.tick();assert.equal(f.track.scrollLeft,390);assert.equal(f.timers.size,1);f.tick();assert.equal(f.track.scrollLeft,0);assert.equal(f.moves,2);assert.equal(f.media.children.length,0);
  f.slides[1].hidden=true;f.api.refresh();assert.equal(f.timers.size,0);f.slides[1].hidden=false;f.api.refresh();assert.equal(f.timers.size,1);f.slides[0].disabled=true;f.api.refresh();assert.equal(f.timers.size,0);
  f.api.destroy();
});
test('touch drag, hover, focus and manual wheel/keyboard suspend or restart the full delay',()=>{
  const f=fixture();f.observers[0].emit(true);f.track.fire('pointerdown');assert.equal(f.timers.size,0);f.tick();assert.equal(f.moves,0);f.window.fire('pointerup');assert.equal(f.timers.size,1);
  f.media.fire('pointerenter',{pointerType:'touch'});assert.equal(f.timers.size,1);f.media.fire('pointerenter',{pointerType:'mouse'});assert.equal(f.timers.size,0);f.media.fire('pointerleave');assert.equal(f.timers.size,1);
  f.media.fire('focusin');assert.equal(f.timers.size,0);f.media.fire('focusout',{relatedTarget:null});assert.equal(f.timers.size,1);
  for(const type of ['wheel','keydown']){const id=[...f.timers.keys()][0];f.track.fire(type);assert.equal(f.timers.size,1);assert.notEqual([...f.timers.keys()][0],id);}
  f.track.fire('pointerdown');f.window.fire('pointercancel');assert.equal(f.timers.size,1);f.api.destroy();
});
test('offscreen, hidden, nested modal, reduced motion and BFCache have zero autoplay timers; abort cleans listeners',()=>{
  const f=fixture();f.observers[0].emit(true);
  f.observers[0].emit(false);assert.equal(f.timers.size,0);f.observers[0].emit(true);assert.equal(f.timers.size,1);
  f.document.hidden=true;f.document.fire('visibilitychange');assert.equal(f.timers.size,0);f.document.hidden=false;f.document.fire('visibilitychange');assert.equal(f.timers.size,1);
  f.setActive(false);assert.equal(f.timers.size,0);f.setActive(true);assert.equal(f.timers.size,1);
  f.reduced.matches=true;f.reduced.fire('change');assert.equal(f.timers.size,0);f.reduced.matches=false;f.reduced.fire('change');assert.equal(f.timers.size,1);
  f.window.fire('pagehide');assert.equal(f.timers.size,0);f.window.fire('pageshow');assert.equal(f.timers.size,1);
  f.controller.abort();assert.equal(f.timers.size,0);assert.equal(f.observers[0].disconnected,true);f.api.refresh();f.document.fire('close');assert.equal(f.timers.size,0);assert.equal(f.reduced.events.get('change').length,0);
});
