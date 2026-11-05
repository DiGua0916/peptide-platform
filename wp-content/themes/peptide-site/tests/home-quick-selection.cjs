'use strict';
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const source=fs.readFileSync(path.join(__dirname,'../assets/home-quick-view.js'),'utf8');
function fixture(){
  const handlers=new Map(),native=new Map(),updates=[];
  const document={addEventListener(type,fn){native.set(type,fn);},querySelector(){return null;}};
  const $=()=>({on(events,selector,fn){for(const type of events.split(' '))handlers.set(type.split('.')[0],fn);}});
  const window={jQuery:$,addEventListener(){},vcProductSpecification:{format:value=>value}};
  vm.runInNewContext(source,{window,document,Map,WeakMap,URL,location:{href:'https://shop.test/',origin:'https://shop.test'}});
  const select={name:'attribute_pack',value:'20mg*10vials',selectedOptions:[{textContent:'20mg × 10 vials'}]};
  const price={innerHTML:'',set textContent(value){this.innerHTML=value;},setAttribute(){}},sku={hidden:false},link={hidden:false},spec={textContent:'',hidden:false};
  const summary={querySelector(selector){return selector===':scope > .price'?price:selector==='.vc-home-quick__sku'?sku:selector==='.vc-showcase-quick__details'?link:selector==='.vc-home-quick__specification'?spec:null;},querySelectorAll(){return [select];}};
  const root={querySelector(selector){return selector==='.summary'?summary:selector==='.summary > .price'?price:null;},vcExperience:{updateMedia(value){updates.push(value);},update(){}}};
  const form={closest(){return root;},querySelectorAll(){return [select];}};
  return {handlers,native,root,form,select,price,sku,link,updates,found(variation){handlers.get('found_variation').call(form,{},variation);},pending(){handlers.get('woocommerce_variation_select_change').call(form);}};
}
const variation=(id,dose,sale=false)=>({variation_id:id,attributes:{attribute_pack:dose+'mg*10vials'},sku:'TR'+dose,price_html:'<span class="vc-price-current">$'+dose+'</span>'+(sale?'<span class="vc-price-compare">$28</span><span class="vc-sale-badge">29% off</span>':''),vc_home_image:{src:'/tr'+dose+'.webp'},vc_home_promotions:[]});
test('TR20 sale → TR15 without sale clears all accepted state before lookup and rejects late TR20',()=>{
  const f=fixture(),tr20=variation(20,20,true),tr15=variation(15,15);
  f.found(tr20);assert.match(f.price.innerHTML,/29% off/);assert.equal(f.root.vcHomeSku,'TR20');
  f.select.value='15mg*10vials';f.select.selectedOptions[0].textContent='15mg × 10 vials';f.pending();
  assert.equal(f.root.vcHomeSelectionPending,true);assert.equal(f.root.vcHomePrice,'');assert.equal(f.root.vcHomeImage,null);assert.equal(f.root.vcHomeSku,'');assert.deepEqual(Array.from(f.root.vcHomePromotions),[]);
  f.price.innerHTML=tr20.price_html;f.found(tr20);assert.doesNotMatch(f.price.innerHTML,/29%|compare/,'late direct native mirror is removed');assert.equal(f.root.vcHomeSelectionPending,true);
  f.found(tr15);assert.equal(f.root.vcHomeSelectionPending,false);assert.equal(f.root.vcHomeSku,'TR15');assert.equal(f.root.vcHomeImage.src,'/tr15.webp');assert.doesNotMatch(f.price.innerHTML,/29%|compare/);assert.match(f.price.innerHTML,/\/ box/);
  f.price.innerHTML=tr20.price_html;f.found(tr20);assert.equal(f.root.vcHomeSku,'TR15');assert.doesNotMatch(f.price.innerHTML,/29%|compare/,'out-of-order old response cannot replace accepted TR15');assert.match(f.price.innerHTML,/\$15/);
});
test('response requires every exact selected attribute, and null/reset never restores parent/default sale',()=>{
  const f=fixture();for(const candidate of [{...variation(20,20,true),attributes:{}},{...variation(20,20,true),attributes:{attribute_pack:'20mg*10vials',attribute_extra:'other'}},{...variation(20,20,true),variation_id:0}])f.found(candidate);
  assert.equal(f.root.vcHomeSku,undefined);f.found(variation(20,20,true));f.handlers.get('reset_data').call(f.form);assert.equal(f.root.vcHomeSku,'');assert.equal(f.root.vcHomeImage,null);assert.doesNotMatch(f.price.innerHTML,/29%|compare/);
  f.select.value='';f.found(variation(20,20,true));assert.equal(f.root.vcHomeSelectionPending,true);assert.equal(f.sku.hidden,true);assert.equal(f.link.hidden,true);
});
test('each product form keeps isolated accepted state and transport remains shared read-only loader',()=>{
  const a=fixture(),b=fixture();a.found(variation(20,20,true));b.select.value='15mg*10vials';b.found(variation(15,15));
  a.pending();assert.equal(b.root.vcHomeSku,'TR15');assert.equal(b.root.vcHomeSelectionPending,false);assert.doesNotMatch(b.price.innerHTML,/29%/);
  assert.doesNotMatch(source,/method\s*:\s*['"]POST['"]/);
});
