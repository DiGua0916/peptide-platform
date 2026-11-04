'use strict';
const test = require('node:test'), assert = require('node:assert/strict');
const {display,create} = require('../assets/product-unit-pricing.js');
const attrs = value => ({attribute_pack:value});
function dto(variationId=71, changes={}) {
  return {version:1,productId:7,variationId,purchasableId:variationId || 7,vialsPerSalesUnit:10,countSource:'stored_pack',
    salesUnitPrice:{minor:'5500',text:'$55.00'},perVialPrice:{minor:'550',text:'$5.50'},approximate:false,currency:'USD',currencyMinorUnit:2,
    taxDisplay:'excl',basis:'catalog_display_price',includesCoupons:false,isPurchasableAsSingleVial:false,...changes};
}
test('initial first display SKU is used, independent of minimum price or dosage ordering',()=>{
  const model=create({productId:7,variable:true,initial:dto(),initialAttributes:attrs('20mg*10vials')});
  assert.equal(model.resolve({variationId:71,attributes:attrs('20mg*10vials')}).text,'$5.50 / vial');
  assert.equal(model.resolve({variationId:72,attributes:attrs('5mg*10vials')}),null);
  assert.equal(model.resolve({variationId:71,attributes:attrs('5mg*10vials')}),null,'old id cannot leak a preceding specification price');
});
test('native exact SKU cache and explicit null replace a prior server value',()=>{
  const model=create({productId:7,variable:true,initial:dto(),initialAttributes:attrs('20mg*10vials')});
  const variation={variation_id:72,attributes:attrs('10mg*10vials'),pc_unit_pricing:dto(72,{perVialPrice:{minor:'400',text:'$4.00'}})};
  model.remember(variation);
  assert.equal(model.resolve({variationId:72,attributes:variation.attributes}).text,'$4.00 / vial');
  model.remember({...variation,pc_unit_pricing:null});
  assert.equal(model.resolve({variationId:72,attributes:variation.attributes}),null);
  model.remember({variation_id:71,attributes:attrs('20mg*10vials')});
  assert.equal(model.resolve({variationId:71,attributes:attrs('20mg*10vials')}),null,'old protocol does not restore initial DTO');
});
test('cleared, incomplete and pending selections hide the previous price',()=>{
  const model=create({productId:7,variable:true,initial:dto(),initialAttributes:attrs('20mg*10vials')});
  for(const selected of [{variationId:0,attributes:attrs('')},{variationId:71,attributes:attrs('')},{variationId:71,attributes:{}},{variationId:71,attributes:attrs('20mg*10vials'),pending:true}]) assert.equal(model.resolve(selected),null);
});
test('wrong product, variation and purchasable identities cannot cross into the selected SKU',()=>{
  const selected={productId:7,variationId:71,variable:true};
  for(const bad of [{productId:8},{variationId:72},{purchasableId:72},{productId:'7'},{variationId:'71'}]) assert.equal(display(dto(71,bad),selected),null);
  assert.equal(display(dto(),{productId:7}),null,'a variable DTO is not a simple product');
});
test('unknown or unreliable pack metadata is hidden; titles and dosage never infer ten vials',()=>{
  const selected={productId:7,variationId:71,variable:true};
  for(const bad of [{vialsPerSalesUnit:0},{vialsPerSalesUnit:null},{vialsPerSalesUnit:'10'},{vialsPerSalesUnit:1.5},{vialsPerSalesUnit:10001},{countSource:'title'},{countSource:'guessed'},{countSource:null},{isPurchasableAsSingleVial:true}]) assert.equal(display(dto(71,bad),selected),null);
  assert.equal(create({productId:7,variable:true,initial:null,initialAttributes:attrs('20mg*10vials')}).resolve({variationId:71,attributes:attrs('20mg*10vials')}),null);
});
test('render server formatted money and approximation without recalculating tax/coupons or creating a single-vial purchase',()=>{
  const value=display(dto(0,{vialsPerSalesUnit:3,countSource:'explicit_sku',approximate:true,perVialPrice:{minor:'1833',text:'$18.33'}}),{productId:7});
  assert.equal(value.text,'Approx. $18.33 / vial');
  assert.equal(value.description,'Equivalent price per vial; sold as a 3-vial pack.');
  assert.deepEqual(Object.keys(value),['text','description']);
  assert.equal(display(dto(0,{includesCoupons:true}),{productId:7}),null);
  assert.equal(display(dto(0,{basis:'cart_display_price'}),{productId:7}),null);
  assert.equal(display(dto(0,{perVialPrice:{minor:'NaN',text:'$5.50'}}),{productId:7}),null);
});
test('multiple attributes require the complete same native SKU snapshot',()=>{
  const model=create({productId:7,variable:true});
  model.remember({variation_id:71,attributes:{...attrs('20mg*10vials'),attribute_form:'powder'},pc_unit_pricing:dto()});
  assert.equal(model.resolve({variationId:71,attributes:attrs('20mg*10vials')}),null);
  assert.equal(model.resolve({variationId:71,attributes:{...attrs('20mg*10vials'),attribute_form:''}}),null);
  assert.equal(model.resolve({variationId:71,attributes:{attribute_form:'powder',...attrs('20mg*10vials')}}).text,'$5.50 / vial');
});
