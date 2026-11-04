'use strict';
const fs=require('fs'),vm=require('vm'),assert=require('assert/strict');
const acorn=require(process.env.ADMIN_DIALOG_ACORN||'D:/Codex/Tasks/2026-11-05/admin-dialog-audit/work/parser/node_modules/acorn');
const source=fs.readFileSync(require.resolve('../assets/wordpress-dialogs/customize-controls.js'),'utf8'),ast=acorn.parse(source,{ecmaVersion:'latest'});let fn;
function walk(n){if(!n||!n.type)return;if(n.type==='FunctionDeclaration'&&n.id.name==='requestClose')fn=n;for(const v of Object.values(n)){if(Array.isArray(v))v.forEach(walk);else if(v&&v.type)walk(v);}}walk(ast);assert.ok(fn&&!fn.async);
let checks=0;
(async()=>{
for(const approve of [false,true]){
 let requests=0,cleaned=0,finished;
 const chain={off(){return this;},css(){return this;}};
 const $=()=>chain;$.Deferred=()=>{const state={value:'pending',resolve(){this.value='resolved';return this;},reject(){this.value='rejected';return this;},promise(){return this;},done(){return this;},fail(){return this;}};finished=state;return state;};
 const api={l10n:{saveAlert:'Unsaved'},each(callback){const setting={_dirty:true};callback(setting);if(!setting._dirty)cleaned++;},state(){return{get:()=>''};},settings:{theme:{stylesheet:'fixture'},changeset:{uuid:'synthetic'},nonce:{dismiss_autosave_or_lock:'synthetic'}}};
 const wp={ajax:{send(){requests++;return{always(callback){callback();}};}}};
 const context={$,api,wp,window:{VialCoveDialogs:{confirm:async()=>approve}},document:{},isCleanState:()=>false,closeBtn:chain};
 const requestClose=vm.runInNewContext('('+source.slice(fn.start,fn.end)+')',context);const result=requestClose();
 assert.equal(typeof result.done,'function');assert.equal(typeof result.fail,'function');checks+=2;
 await new Promise(resolve=>setImmediate(resolve));
 assert.equal(finished.value,approve?'resolved':'rejected');assert.equal(requests,approve?1:0);assert.equal(cleaned,approve?1:0);checks+=3;
}
console.log(JSON.stringify({passed:true,checks,deferredContract:true,synthetic:true}));
})().catch(e=>{console.error(e);process.exitCode=1;});
