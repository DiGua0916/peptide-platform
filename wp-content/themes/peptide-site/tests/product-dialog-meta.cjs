'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const source=fs.readFileSync(path.join(__dirname,'../assets/product-dialog-meta.js'),'utf8');
function fixture(variable=true){
  let writes=0;
  class Element{
    constructor(tag='div',classes=''){this.tagName=tag;this.children=[];this.parentNode=null;this._classes=classes;this._text='';this._hidden=false;this.handlers=[];this.classList={contains:key=>this._classes.split(' ').includes(key),add:key=>{if(!this.classList.contains(key))this._classes+=(this._classes?' ':'')+key;},remove:key=>{this._classes=this._classes.split(' ').filter(item=>item!==key).join(' ');}};}
    get className(){return this._classes;}set className(value){writes++;this._classes=value;}
    get textContent(){return this._text;}set textContent(value){writes++;this._text=value;}
    get hidden(){return this._hidden;}set hidden(value){writes++;this._hidden=value;}
    get parentElement(){return this.parentNode;}
    contains(node){return this===node||this.children.some(child=>child.contains(node));}
    append(...nodes){for(const node of nodes){node.remove();this.children.push(node);node.parentNode=this;}}
    prepend(...nodes){for(const node of [...nodes].reverse()){node.remove();this.children.unshift(node);node.parentNode=this;}}
    before(node){const parent=this.parentNode;if(!parent)return;node.remove();parent.children.splice(parent.children.indexOf(this),0,node);node.parentNode=parent;}
    replaceWith(node){const parent=this.parentNode;if(!parent)return;const index=parent.children.indexOf(this);node.remove();parent.children.splice(index,1,node);node.parentNode=parent;this.parentNode=null;}
    remove(){if(this.parentNode){this.parentNode.children.splice(this.parentNode.children.indexOf(this),1);this.parentNode=null;}}
    matches(selector){if(selector==='[name="variation_id"]')return this.name==='variation_id';return selector.startsWith('.')&&this.classList.contains(selector.slice(1));}
    querySelector(selector){if(selector===':scope > .stock')return this.children.find(node=>node.classList.contains('stock'))||null;if(selector==='.single_variation .stock')return this.querySelector('.single_variation')?.querySelector('.stock')||null;for(const child of this.children){if(child.matches(selector))return child;const match=child.querySelector(selector);if(match)return match;}return null;}
    addEventListener(type,fn){this.handlers.push({type,fn});}click(){for(const item of this.handlers)if(item.type==='click')item.fn();}
  }
  const root=new Element('article'),summary=new Element('div','summary'),form=new Element('form','cart'),variations=new Element('table','variations'),single=new Element('div','single_variation'),stock=new Element('p','stock in-stock'),reset=new Element('a','reset_variations'),sku=new Element('p','vc-home-quick__sku'),details=new Element('a','vc-showcase-quick__details'),id=new Element('input');
  id.name='variation_id';id.value='71';stock.textContent='In stock';sku.textContent='SKU TR20';details.textContent='Explore product details';let resets=0,explores=0;reset.addEventListener('click',()=>resets++);details.addEventListener('click',()=>explores++);
  root.append(summary);summary.append(form,sku,details);if(variable){form.append(variations,single,id);variations.append(reset);single.append(stock);}else{summary.prepend(stock);form.append(reset);}
  const window={};vm.runInNewContext(source,{window,document:{createElement:tag=>new Element(tag),createComment:()=>new Element('#comment')}});
  const controller=new AbortController();let label=variable?'20mg × 10 vials':'';
  const options={root,summary,form,signal:controller.signal,selectedLabel:()=>label};
  return {root,summary,form,variations,single,stock,reset,sku,details,id,controller,options,create:window.createVcProductMetaStrip,Element,setLabel(value){label=value;},get writes(){return writes;},get clicks(){return [resets,explores];}};
}
test('moves real Clear/SKU/Explore before variations while retaining native form/summary selectors and handlers',()=>{
  const f=fixture(),beforeForm=[...f.form.children],beforeSummary=[...f.summary.children],meta=f.create(f.options);
  assert.equal(f.form.children[0],meta.element);assert.equal(f.form.querySelector('.reset_variations'),f.reset);assert.equal(f.summary.querySelector('.vc-home-quick__sku'),f.sku);assert.equal(f.summary.querySelector('.vc-showcase-quick__details'),f.details);assert.equal(f.single.querySelector('.stock'),f.stock);
  f.reset.click();f.details.click();assert.deepEqual(f.clicks,[1,1]);meta.destroy();assert.deepEqual(f.form.children,beforeForm);assert.deepEqual(f.summary.children,beforeSummary);assert.equal(f.reset.parentNode,f.variations);assert.equal(f.root.classList.contains('vc-product-meta-ready'),false);
});
test('copies actual stock and classes, hides pending/stale variation stock, updates when Woo replaces it',()=>{
  const f=fixture(),meta=f.create(f.options),copy=meta.element.querySelector('.vc-product-meta__stock');assert.equal(copy.textContent,'In stock');assert.equal(copy.classList.contains('in-stock'),true);assert.equal(f.stock.parentNode,f.single);
  f.id.value='';meta.update();assert.equal(copy.hidden,true);f.id.value='72';meta.update();assert.equal(copy.hidden,true,'old template is not the new variation');
  const fresh=new f.Element('p','stock out-of-stock');fresh.textContent='Out of stock';f.stock.remove();f.single.append(fresh);meta.update();assert.equal(copy.textContent,'Out of stock');assert.equal(copy.classList.contains('out-of-stock'),true);assert.equal(copy.classList.contains('in-stock'),false);
  fresh.remove();meta.update();assert.equal(copy.hidden,true);assert.equal(copy.textContent,'');
});
test('unchanged update makes no DOM writes; home SKU updater still reaches the moved real line',()=>{
  const f=fixture(),meta=f.create(f.options),count=f.writes;meta.update();meta.update();assert.equal(f.writes,count);
  f.summary.querySelector('.vc-home-quick__sku').textContent='SKU TR10';f.setLabel('10mg × 10 vials');meta.update();assert.equal(f.sku.textContent,'SKU TR10');assert.equal(meta.element.querySelector('.vc-product-meta__spec').textContent,'10mg × 10 vials');
});
test('simple products copy only real direct stock; missing metadata stays empty and abort restores native nodes',()=>{
  const f=fixture(false);f.reset.hidden=true;f.details.hidden=true;const meta=f.create(f.options),copy=meta.element.querySelector('.vc-product-meta__stock');assert.equal(copy.textContent,'In stock');assert.equal(meta.element.querySelector('.vc-product-meta__spec').hidden,true);
  f.stock.remove();meta.update();assert.equal(copy.hidden,true);f.controller.abort();assert.equal(f.reset.parentNode,f.form);assert.equal(f.reset.hidden,true);assert.equal(f.details.parentNode,f.summary);assert.equal(f.details.hidden,true);meta.destroy();
});
test('invalid/aborted/duplicate mount performs no duplicate metadata or native movement',()=>{
  const f=fixture();assert.equal(f.create({...f.options,form:null}),null);const meta=f.create(f.options);assert.equal(f.create(f.options),null);meta.destroy();f.controller.abort();assert.equal(f.create(f.options),null);
});
