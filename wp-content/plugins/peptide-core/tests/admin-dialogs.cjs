'use strict';
const {JSDOM}=require('D:/Codex/Tasks/2026-11-03/hq-navigation/work/model-deps/node_modules/jsdom');
const fs=require('node:fs'),assert=require('node:assert/strict');
const source=fs.readFileSync(require.resolve('../assets/admin-dialogs.js'),'utf8');
const dom=new JSDOM('<button id="before">Open</button><dialog id="parent" open><form method="post" action="/save" data-pc-confirm="删除？"><input name="nonce" value="synthetic"><button name="operation" value="delete">Delete</button></form></dialog>',{url:'https://admin.invalid/',runScripts:'outside-only'});
const w=dom.window,d=w.document;
w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};w.HTMLDialogElement.prototype.close=function(){this.open=false;this.dispatchEvent(new w.Event('close'));};
const nativeConfirm=w.confirm;w.eval(source);const api=w.VialCoveDialogs;
const flush=()=>new Promise(r=>setImmediate(r));
let count=0;const check=(v)=>{assert.ok(v);count++;};
(async()=>{
 check(w.confirm===nativeConfirm);
 d.getElementById('before').focus();let answer=api.confirm('停止店铺？');await flush();
 let box=d.querySelector('.vc-admin-dialog');check(box.open);check(box.textContent.includes('停止店铺'));check(d.activeElement.textContent==='取消');
 box.querySelector('button').click();check(await answer===false);check(d.getElementById('parent').open);check(d.activeElement.id==='before');
 answer=api.confirm('确认？');await flush();d.querySelector('.vc-admin-dialog__primary').click();check(await answer===true);
 answer=api.prompt('地址','https://');await flush();box=d.querySelector('.vc-admin-dialog');box.querySelector('input').value='https://example.invalid/';box.querySelector('.vc-admin-dialog__primary').click();check(await answer==='https://example.invalid/');
 answer=api.prompt('地址','');await flush();d.querySelector('.vc-admin-dialog').dispatchEvent(new w.KeyboardEvent('keydown',{key:'Escape',bubbles:true,cancelable:true}));check(await answer===null);check(d.getElementById('parent').open);
 const form=d.querySelector('form'),submitter=form.querySelector('button');let requests=0;
 form.addEventListener('submit',e=>{e.preventDefault();requests++;check(e.submitter===submitter);});
 // Cancel must never reach business handlers, whether mouse or keyboard initiated.
 form.requestSubmit(submitter);await flush();d.querySelector('.vc-admin-dialog button').click();await flush();check(requests===0);
 form.requestSubmit(submitter);await flush();d.querySelector('.vc-admin-dialog__primary').click();await flush();check(requests===1);
 // A changed action or changed form fields invalidates earlier approval.
 form.requestSubmit(submitter);await flush();form.elements.nonce.value='changed';d.querySelector('.vc-admin-dialog__primary').click();await flush();check(requests===1);
 form.requestSubmit(submitter);await flush();form.action='/different';d.querySelector('.vc-admin-dialog__primary').click();await flush();check(requests===1);
 // Duplicate submits while awaiting confirmation do not queue duplicate actions.
 form.requestSubmit(submitter);form.requestSubmit(submitter);await flush();check(d.querySelectorAll('.vc-admin-dialog').length===1);d.querySelector('.vc-admin-dialog__primary').click();await flush();check(requests===2);check(!d.querySelector('.vc-admin-dialog'));
 // Legacy vendor default guards cancel synchronously and submit once after approval.
 form.removeAttribute('data-pc-confirm');
 submitter.addEventListener('click',event=>api.confirmDefault(event,'Vendor confirmation'));
 submitter.click();await flush();check(requests===2);d.querySelector('.vc-admin-dialog button').click();await flush();check(requests===2);
 submitter.click();await flush();d.querySelector('.vc-admin-dialog__primary').click();await flush();check(requests===3);
 // Core inline literal guard is decoded as text, without evaluating JavaScript.
 const legacy=d.createElement('a');legacy.href='/delete';legacy.textContent='Delete';
 legacy.setAttribute('onclick',"return confirm('删除\\'数据\\'\\n继续？');");d.body.append(legacy);
 legacy.click();await flush();box=d.querySelector('.vc-admin-dialog');
 check(box.querySelector('h2').textContent==='请确认操作');check(box.querySelector('p').textContent==="删除'数据'\n继续？");
 box.querySelector('button').click();await flush();check(!legacy.hasAttribute('onclick'));
 const shortlink=d.createElement('input');shortlink.id='shortlink';shortlink.value='https://admin.invalid/?p=123';d.body.append(shortlink);
 const getLink=d.createElement('button');getLink.setAttribute('onclick',"prompt('URL:', jQuery('#shortlink').val());");d.body.append(getLink);
 getLink.click();await flush();box=d.querySelector('.vc-admin-dialog');check(box.querySelector('input').value===shortlink.value);box.querySelector('button').click();await flush();
 // No HTML is interpreted in a supplied message.
 answer=api.alert('<img src=x onerror=alert(1)>');await flush();check(!d.querySelector('.vc-admin-dialog img'));d.querySelector('.vc-admin-dialog__primary').click();await answer;
 console.log(JSON.stringify({checks:count,passed:true,syntheticDOM:true}));dom.window.close();
})().catch(e=>{console.error(e);process.exitCode=1;});
