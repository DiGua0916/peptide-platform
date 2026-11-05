'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const apply = require('../assets/checkout-layout-preview.js');
const base = 'https://manage.store.test/?pc_template_preview=123&pc_preview_nonce=fixtureNonce&pc_frame=1&pc_design_document=checkout';
function fixture() {
 const cfg = {document:{id:42,document:'checkout',template:{id:123},previewBase:base,modules:[{block:'vialcove/context-content',attrs:{settings:{checkout_subtotal_savings_enabled:false}}}]},restPath:'/peptide/v1/layouts/42',storeUrl:'https://store.test/',library:['original'],strings:{save:'Save'}};
 const bridge = {documentId:42,templateId:123,previewBase:base.replace('/?', '/checkout/?'),storeUrl:'https://store.test/checkout/'};
 return {cfg,bridge};
}
test('before-factory bridge changes only the authorized checkout URLs', () => {
 const {cfg,bridge}=fixture(), before=structuredClone(cfg);
 assert.equal(apply(cfg,bridge),true);
 assert.equal(cfg.document.previewBase,bridge.previewBase); assert.equal(cfg.storeUrl,bridge.storeUrl);
 before.document.previewBase=bridge.previewBase; before.storeUrl=bridge.storeUrl;
 assert.deepEqual(cfg,before); assert.equal(apply(cfg,bridge),true);
});
for (const [label,mutate] of [
 ['another document', x=>x.cfg.document.document='home'],
 ['wrong document ID', x=>x.cfg.document.id=43],
 ['wrong template', x=>x.cfg.document.template.id=124],
 ['wrong route', x=>x.cfg.restPath='/peptide/v1/layouts/43'],
 ['a save route', x=>x.cfg.restPath='/peptide/v1/layouts/42/save'],
 ['string ID', x=>x.bridge.documentId='42'],
 ['unsafe target', x=>x.bridge.storeUrl='javascript:alert(1)'],
 ['credential URL', x=>x.bridge.storeUrl='https://user:secret@store.test/checkout/'],
 ['wrong preview origin', x=>x.bridge.previewBase=x.bridge.previewBase.replace('manage.store.test','foreign.test')],
 ['wrong target path', x=>x.bridge.previewBase=x.bridge.previewBase.replace('/checkout/','/products/')],
 ['wrong preview document', x=>x.bridge.previewBase=x.bridge.previewBase.replace('document=checkout','document=home')],
 ['wrong preview template', x=>x.bridge.previewBase=x.bridge.previewBase.replace('preview=123','preview=124')],
 ['missing frame', x=>x.bridge.previewBase=x.bridge.previewBase.replace('&pc_frame=1','')],
 ['missing nonce', x=>x.bridge.previewBase=x.bridge.previewBase.replace('&pc_preview_nonce=fixtureNonce','')],
 ['different nonce', x=>x.bridge.previewBase=x.bridge.previewBase.replace('fixtureNonce','differentNonce')],
]) test(label+' leaves the native editor unchanged',()=>{
 const f=fixture(); mutate(f); const before=structuredClone(f.cfg);
 assert.equal(apply(f.cfg,f.bridge),false); assert.deepEqual(f.cfg,before);
});
test('bridge neither invokes transport nor adds prices, inventory or checkout data',()=>{
 const {cfg,bridge}=fixture(); let called=false; cfg.apiFetch=()=>{called=true};
 assert.equal(apply(cfg,bridge),true); assert.equal(called,false);
 assert.equal('prices' in cfg,false); assert.equal('cart' in cfg,false); assert.equal('customer' in cfg,false);
});
