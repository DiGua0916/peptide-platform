// Site 1 P1-1/P1-6 checkout assist: pure helpers. Run: node checkout-assist.cjs
'use strict';
const a=require(require('path').join(__dirname,'..','assets','checkout-assist.js'));const assert=require('assert');
assert.equal(a.stateForZip('10001'),'NY');assert.equal(a.stateForZip('90210'),'CA');assert.equal(a.stateForZip('33101'),'FL');
assert.equal(a.stateForZip('75001'),'TX');assert.equal(a.stateForZip('20500'),'DC');assert.equal(a.stateForZip('02108'),'MA');
assert.equal(a.stateForZip('96813'),'HI');assert.equal(a.stateForZip('99501'),'AK');assert.equal(a.stateForZip('60601-1234'),'IL');
assert.equal(a.stateForZip('1234'),'');assert.equal(a.stateForZip('abcde'),'');
assert(a.phoneOk('(415) 555-0100'));assert(a.phoneOk('+1 415 555 0100'));assert(!a.phoneOk('555-0100'));
assert.equal(a.cleanDraft({at:Date.now()-8*864e5,email:'x'},Date.now()),null);
const d=a.cleanDraft({at:Date.now(),email:'a@b.co',shipping:{city:'Austin',evil:'x',postcode:5}},Date.now());
assert.deepEqual(d.shipping,{city:'Austin'});
assert.equal(a.payLabel({totals:{total_price:'15599',currency_minor_unit:2,currency_prefix:'$'}}),'Pay $155.99');
assert.equal(a.helpText([]),'Your details are saved. Please try again.');
assert.equal(a.helpText(['Cash App','Klarna']),'Your details are saved. Try again, or pay another way: Cash App or Klarna.');
// Site 1 2026-11-17: struck regular prices on the checkout summary.
{
  const cart = (o) => ({ totals: { currency_minor_unit: 2, currency_prefix: '$', total_shipping: o.pay }, extensions: { peptide_core: {
    shipping_savings: { status: 'applied', complete: true, original_shipping: '5500', payable_shipping: o.pay, shipping_discount: String(5500 - Number(o.pay)) },
    shipping_protection: { enabled: true, chosen: o.chosen !== false, price_minor: 99, reference_minor: 20000, label: 'Shipping protection' } } } });
  const r = a.refPrices(cart({ pay: '4500' }));
  assert.equal(r.shipping, '$55.00'); assert.equal(r.protection, '$200.00'); assert.equal(r.free, false);
  assert.equal(a.refPrices(cart({ pay: '0' })).free, true);
  assert.equal(a.refPrices(cart({ pay: '5500' })).shipping, '', 'no offer, no struck price');
  assert.equal(a.refPrices(cart({ pay: '4500', chosen: false })).protection, '', 'protection opted out shows no struck price');
  assert.equal(a.refPrices({}).shipping, '');
}
console.log('checkout-assist 17/17 ok');
