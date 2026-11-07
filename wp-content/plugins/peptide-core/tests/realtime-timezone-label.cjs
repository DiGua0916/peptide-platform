const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(path.join(__dirname,'../assets/data-realtime-dimensions.js'),'utf8');
const fn=source.match(/function periodLabel\( data \) \{[^\n]+\}/)[0];
const label=vm.runInNewContext('('+fn+')',{Intl,Date,text:x=>x});
assert.equal(label({mode:'live',anchor_ms:Date.parse('2026-11-07T16:00:00Z'),timezone:'Asia/Shanghai'}),'2026-11-08 00:00:00 · Asia/Shanghai');
assert.equal(label({mode:'live',anchor_ms:Date.parse('2026-11-07T13:21:12.438Z'),timezone:'Asia/Shanghai'}),'2026-11-07 21:21:12 · Asia/Shanghai');
assert.equal(label({mode:'history',range:{dates:{from:'2026-11-07',to:'2026-11-07'},timezone:'Asia/Shanghai'}}),'history: 2026-11-07–2026-11-07 · Asia/Shanghai');
console.log('PASS: UTC instant, Beijing midnight, history calendar labels');
