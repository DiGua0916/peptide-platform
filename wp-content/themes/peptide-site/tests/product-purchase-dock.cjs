'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const source=fs.readFileSync(path.join(__dirname,'../assets/product-purchase-dock.js'),'utf8');
function fixture(variable=true){
  let mutations=0,submits=0,nextFrame=0;const frames=new Map(),observers=[];
  class Target{constructor(){this.listeners=new Map();}addEventListener(type,fn){const list=this.listeners.get(type)||[];list.push(fn);this.listeners.set(type,list);}removeEventListener(type,fn){this.listeners.set(type,(this.listeners.get(type)||[]).filter(item=>item!==fn));}fire(type){for(const fn of this.listeners.get(type)||[])fn();}}
  class Element extends Target{
    constructor(tag){super();this.tagName=tag;this.children=[];this.parentNode=null;this.attrs={};this.classes=new Set();this._text='';this._hidden=false;this.disabled=false;this.inert=false;this.height=136;this.values=new Map();this.classList={add:(...keys)=>keys.forEach(key=>this.classes.add(key)),remove:(...keys)=>keys.forEach(key=>this.classes.delete(key)),contains:key=>this.classes.has(key)};this.style={getPropertyValue:key=>this.values.get(key)?.value||'',getPropertyPriority:key=>this.values.get(key)?.priority||'',setProperty:(key,value,priority='')=>this.values.set(key,{value,priority}),removeProperty:key=>this.values.delete(key)};}
    set className(value){this.classes=new Set(value.split(' ').filter(Boolean));}get className(){return [...this.classes].join(' ');}
    get textContent(){return this._text;}set textContent(value){mutations++;this._text=value;}
    get hidden(){return this._hidden;}set hidden(value){mutations++;this._hidden=value;}
    get parentElement(){return this.parentNode;}get form(){return this.closest('form');}
    matches(selector){if(selector==='form')return this.tagName==='form';if(selector==='input.qty[name="quantity"]')return this.tagName==='input'&&this.classes.has('qty')&&this.name==='quantity';return selector.startsWith('.')&&this.classes.has(selector.slice(1));}
    closest(selector){for(let node=this;node;node=node.parentNode)if(node.matches(selector))return node;return null;}
    contains(node){return node===this||this.children.some(child=>child.contains(node));}
    append(...nodes){for(const node of nodes){node.remove();node.parentNode=this;this.children.push(node);}}
    before(node){if(!this.parentNode)return;node.remove();const parent=this.parentNode;parent.children.splice(parent.children.indexOf(this),0,node);node.parentNode=parent;}
    replaceWith(node){if(!this.parentNode)return;const parent=this.parentNode,index=parent.children.indexOf(this);node.remove();parent.children.splice(index,1,node);node.parentNode=parent;this.parentNode=null;}
    remove(){if(this.parentNode){this.parentNode.children.splice(this.parentNode.children.indexOf(this),1);this.parentNode=null;}}
    querySelector(selector){for(const child of this.children){if(child.matches(selector))return child;const found=child.querySelector(selector);if(found)return found;}return null;}
    getBoundingClientRect(){return {height:this.height};}
  }
  const document=Object.assign(new Target(),{hidden:false,createElement:tag=>new Element(tag),createComment:()=>new Element('#comment')});
  const window=new Target();
  vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../assets/product-dialog-meta.js'),'utf8'),{window});
  class ResizeObserver{constructor(callback){this.callback=callback;this.disconnected=false;observers.push(this);}observe(node){this.node=node;}disconnect(){this.disconnected=true;}}
  vm.runInNewContext(source,{window,document,ResizeObserver,requestAnimationFrame(fn){const id=++nextFrame;frames.set(id,fn);return id;},cancelAnimationFrame(id){frames.delete(id);}});
  const root=new Element('article'),summary=new Element('div'),form=new Element('form'),variation=new Element('div'),native=new Element('div'),qty=new Element('div'),input=new Element('input'),add=new Element('button'),buy=new Element('button'),hidden=new Element('input'),dialog=new Element('dialog');dialog.open=true;
  summary.className='summary';variation.className='single_variation_wrap';native.className='woocommerce-variation-add-to-cart woocommerce-variation-add-to-cart-enabled';qty.className='quantity';input.className='qty';input.name='quantity';input.value='3';input.min='2';input.max='12';input.step='1';add.className='single_add_to_cart_button';add.name='add-to-cart';add.value='7';buy.type='button';hidden.name='variation_id';hidden.value='71';
  qty.append(input);root.append(summary);summary.append(form,buy);if(variable){form.append(variation);variation.append(native);native.append(qty,add,hidden);}else form.append(qty,add,hidden);
  form.requestSubmit=()=>{submits++;};let addClicks=0,buyClicks=0;add.addEventListener('click',()=>addClicks++);buy.addEventListener('click',()=>buyClicks++);
  const priceFields={'.vc-price-current .woocommerce-Price-amount':{textContent:'$55.00'},'.vc-price-compare .woocommerce-Price-amount':{textContent:'$78.00'},'.vc-sale-badge':{textContent:'29% off'}};
  const priceNode={textContent:'Choose a specification',querySelector:selector=>priceFields[selector]||null};
  const controller=new AbortController();const options={root,dialog,form,mainAdd:add,mainBuy:buy,selectedLabel:()=> '20mg × 10 vials',priceNode,enabled:true,signal:controller.signal};
  return {window,document,root,summary,form,variation,native,qty,input,add,buy,hidden,dialog,priceFields,priceNode,controller,options,frames,observers,create:window.createVcProductPurchaseDock,get mutations(){return mutations;},get submits(){return submits;},get clicks(){return [addClicks,buyClicks];},flush(){for(const [id,fn]of [...frames]){frames.delete(id);fn();}}};
}
test('disabled, missing real form/Add and duplicate mount leave purchasing untouched',()=>{
  const f=fixture();assert.equal(f.create({...f.options,enabled:false}),null);assert.equal(f.create({...f.options,mainAdd:null}),null);assert.equal(f.create({...f.options,form:null}),null);assert.equal(f.qty.parentNode,f.native);assert.equal(f.buy.parentNode,f.summary);
  const dock=f.create(f.options);assert.ok(dock);assert.equal(f.create(f.options),null);dock.destroy();f.controller.abort();assert.equal(f.create(f.options),null);
});
test('variable product reuses native wrapper and preserves Woo single-variation ancestry, form ownership and handlers',()=>{
  const f=fixture(),before=[...f.native.children],dock=f.create(f.options);assert.equal(dock.element,f.native);assert.equal(dock.add,f.add);assert.equal(dock.buy,f.buy);
  assert.equal(f.input.closest('.single_variation_wrap'),f.variation);assert.equal(f.form.querySelector('input.qty[name="quantity"]'),f.input);assert.equal(f.add.form,f.form);assert.equal(f.buy.form,f.form);assert.equal(f.hidden.parentNode,f.native);assert.equal(f.hidden.value,'71');
  assert.equal(f.input.value,'3');assert.equal(f.input.min,'2');assert.equal(f.input.max,'12');assert.equal(f.input.step,'1');assert.equal(f.add.name,'add-to-cart');assert.equal(f.add.value,'7');
  f.add.fire('click');f.buy.fire('click');assert.deepEqual(f.clicks,[1,1]);assert.equal(f.submits,0);dock.destroy();assert.deepEqual(f.native.children,before);assert.equal(f.buy.parentNode,f.summary);assert.equal(f.native.classList.contains('woocommerce-variation-add-to-cart-enabled'),true);
});
test('simple product uses one in-form dock and destroy restores nodes, original visibility and attributes',()=>{
  const f=fixture(false),before=[...f.form.children];f.qty.hidden=true;f.buy.disabled=true;const dock=f.create(f.options);assert.notEqual(dock.element,f.form);assert.equal(dock.element.parentNode,f.form);assert.equal(f.add.form,f.form);assert.equal(f.qty.hidden,true);assert.equal(f.buy.disabled,true);assert.equal(f.hidden.parentNode,f.form);
  dock.destroy();dock.destroy();assert.deepEqual(f.form.children,before);assert.equal(f.buy.parentNode,f.summary);assert.equal(f.qty.hidden,true);assert.equal(f.buy.disabled,true);assert.equal(f.root.classList.contains('vc-product-purchase-docked'),false);assert.equal(f.dialog.classList.contains('vc-product-purchase-docked'),false);
});
test('price/spec are actual server text; unchanged updates write no text/hidden state and expired sales clear',()=>{
  const f=fixture(),dock=f.create(f.options),current=f.native.querySelector('.vc-purchase-dock__current'),compare=f.native.querySelector('.vc-purchase-dock__compare'),discount=f.native.querySelector('.vc-purchase-dock__discount');
  assert.equal(current.textContent,'$55.00 / box');assert.equal(compare.textContent,'$78.00');assert.equal(discount.textContent,'29% off');assert.equal(f.native.querySelector('.vc-purchase-dock__spec').textContent,'20 mg per vial · 10 vials per box');
  const written=f.mutations;dock.update();dock.update();assert.equal(f.mutations,written);
  delete f.priceFields['.vc-price-compare .woocommerce-Price-amount'];delete f.priceFields['.vc-sale-badge'];f.priceFields['.vc-price-current .woocommerce-Price-amount'].textContent='€61,50';dock.update();assert.equal(current.textContent,'€61,50 / box');assert.equal(compare.hidden,true);assert.equal(discount.hidden,true);assert.equal(discount.textContent,'');
  delete f.priceFields['.vc-price-current .woocommerce-Price-amount'];dock.destroy();
  const cleared=f.create({...f.options,selectedLabel:()=>''});assert.equal(cleared.element.querySelector('.vc-purchase-dock__current').hidden,true);assert.equal(cleared.element.querySelector('.vc-purchase-dock__spec').textContent,'Choose a specification');
});
test('height reservation is frame-coalesced and teardown restores prior CSS/cleans observers and callbacks',()=>{
  const f=fixture();f.dialog.style.setProperty('--vc-purchase-dock-height','17px','important');const dock=f.create(f.options);assert.equal(f.frames.size,1);f.observers[0].callback();f.window.fire('resize');assert.equal(f.frames.size,1);f.flush();assert.equal(f.root.style.getPropertyValue('--vc-purchase-dock-height'),'136px');
  dock.element.height=160;f.observers[0].callback();f.flush();assert.equal(f.dialog.style.getPropertyValue('--vc-purchase-dock-height'),'160px');
  f.document.hidden=true;f.document.fire('visibilitychange');f.window.fire('resize');assert.equal(f.frames.size,0);f.document.hidden=false;f.document.fire('visibilitychange');assert.equal(f.frames.size,1);
  f.controller.abort();assert.equal(f.frames.size,0);assert.equal(f.observers[0].disconnected,true);assert.equal(f.dialog.style.getPropertyValue('--vc-purchase-dock-height'),'17px');assert.equal(f.dialog.style.getPropertyPriority('--vc-purchase-dock-height'),'important');assert.equal(f.root.style.getPropertyValue('--vc-purchase-dock-height'),'');f.window.fire('resize');f.observers[0].callback();assert.equal(f.frames.size,0);
});
