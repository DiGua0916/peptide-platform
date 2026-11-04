'use strict';
const fs=require('fs'),path=require('path'),vm=require('vm'),assert=require('assert/strict');
const acorn=require(process.env.ADMIN_DIALOG_ACORN||'D:/Codex/Tasks/2026-11-05/admin-dialog-audit/work/parser/node_modules/acorn');
const folder=path.resolve(__dirname,'../assets/woocommerce-dialogs');let checks=0;
const parse=source=>acorn.parse(source,{ecmaVersion:'latest'});
const nav=fs.readFileSync(path.join(folder,'client-navigation.js'),'utf8');let effect;
function walk(n,parents,callback){if(!n||!n.type)return;callback(n,parents);for(const v of Object.values(n)){if(Array.isArray(v))v.forEach(x=>walk(x,[...parents,n],callback));else if(v&&v.type)walk(v,[...parents,n],callback);}}
walk(parse(nav),[],(n,p)=>{if(n.type==='VariableDeclarator'&&n.id.name==='vcActive')effect=p.findLast(x=>x.type==='ArrowFunctionExpression');});assert.ok(effect);
const flush=()=>new Promise(r=>setImmediate(r));
function fixture(when=true,predicate){const pushed=[],decisions=[];const history={location:'/current',push(...values){pushed.push(values);}};const original=history.push;const service={confirm:()=>new Promise(resolve=>decisions.push(resolve))};const run=vm.runInNewContext('('+nav.slice(effect.start,effect.end)+')',{i:history,t:when,r:predicate,w:x=>x,n:'Synthetic unsaved',window:{VialCoveDialogs:service}});return{history,original,pushed,decisions,cleanup:run()};}
(async()=>{
 let f=fixture();assert.equal(f.history.push('/cancelled'),undefined);assert.equal(f.pushed.length,0);f.decisions.shift()(false);await flush();assert.equal(f.pushed.length,0);checks+=3;f.cleanup();assert.equal(f.history.push,f.original);checks++;
 f=fixture();f.history.push('/approved');f.decisions.shift()(true);await flush();assert.deepEqual(f.pushed,[['/approved']]);checks++;f.cleanup();
 f=fixture();f.history.push('/stale');f.history.push('/latest');f.decisions.shift()(true);await flush();assert.equal(f.pushed.length,0);f.decisions.shift()(true);await flush();assert.deepEqual(f.pushed,[['/latest']]);checks+=2;f.cleanup();
 f=fixture();f.history.push('/unmounted');f.cleanup();f.decisions.shift()(true);await flush();assert.equal(f.pushed.length,0);checks++;
 f=fixture(true,()=>false);f.history.push('/exempt');assert.equal(f.decisions.length,0);assert.equal(f.pushed.length,1);checks+=2;f.cleanup();
 f=fixture(false);assert.equal(f.history.push,f.original);checks++;
 assert.ok(nav.includes('window.addEventListener("beforeunload"'));checks++;
 const chunk=fs.readFileSync(path.join(folder,'client-analytics-settings.js'),'utf8');const registry={};vm.runInNewContext(chunk,registry);
 assert.equal(registry.webpackChunk_wcAdmin_webpackJsonp.length,1);assert.ok(registry.webpackChunk_wcAdmin_webpackJsonp[0][0].includes(5941));checks+=2;
 let reset;
 walk(parse(chunk),[],(n,p)=>{if(n.type==='CallExpression'&&n.callee.type==='MemberExpression'&&n.callee.object.type==='MemberExpression'&&n.callee.object.property.name==='VialCoveDialogs'&&n.callee.property.name==='confirm')reset=p.findLast(x=>x.type==='ArrowFunctionExpression');});assert.ok(reset&&reset.async);
 for(const answer of [false,true]){
  const mutations=[],events=[];let prevented=0;const handler=vm.runInNewContext('('+chunk.slice(reset.start,reset.end)+')',{window:{VialCoveDialogs:{confirm:async()=>answer,currentEvent:()=>({preventDefault(){prevented++;}})}},s:{__:x=>x},m:{$W:{test:{defaultValue:'default'}}},v:(...args)=>mutations.push(args),l:{recordEvent:name=>events.push(name)}});
  const work=handler();assert.equal(prevented,1);assert.equal(mutations.length,0);await work;assert.equal(mutations.length,answer?1:0);assert.equal(events.length,answer?1:0);checks+=4;
 }
 for(const source of [nav,chunk])walk(parse(source),[],n=>{assert.ok(!(n.type==='CallExpression'&&n.callee.type==='MemberExpression'&&n.callee.object.name==='window'&&['confirm','prompt','alert'].includes(n.callee.property.name)));});checks+=2;
 console.log(JSON.stringify({passed:true,checks,historyVoidContract:true,chunkPreloadsWithoutRuntime:true,synthetic:true}));
})().catch(e=>{console.error(e);process.exitCode=1;});
