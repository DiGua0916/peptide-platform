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
console.log('checkout-assist 17/17 ok');
