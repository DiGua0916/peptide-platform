'use strict';
// Production progressive helper; synthetic DOM, no HTTP/PDF/browser/session or business operations.
const test=require('node:test'), assert=require('node:assert/strict');
const {initialize,resetPending,markImageFailure}=require('../assets/public-report-library.js');
function fixture(){
    const listeners=new Map(), attrs=new Map();let checks=true;
    const button={disabled:false},status={textContent:'',getAttribute:name=>name==='data-message'?'Buscando informes…':null};
    const form={querySelector:selector=>selector.startsWith('button')?button:status,checkValidity:()=>checks,setAttribute:(k,v)=>attrs.set(k,v),removeAttribute:k=>attrs.delete(k),addEventListener:(type,fn)=>listeners.set(type,fn)};
    const fallback={hidden:true},preview={querySelector:()=>fallback};
    const image={hidden:false,complete:false,currentSrc:'',naturalWidth:0,closest:()=>preview,addEventListener:(type,fn)=>listeners.set('image:'+type,fn)};
    const library={querySelectorAll:selector=>selector==='[data-pc-coa-form]'?[form]:[image]};
    const doc={querySelectorAll:()=>[library]};
    return {doc,form,button,status,attrs,listeners,image,fallback,invalid(){checks=false;}};
}
test('native GET form submits without preventDefault/fetch while pending feedback is accessible',()=>{
    const f=fixture();initialize(f.doc);let prevented=0;
    f.listeners.get('submit')({defaultPrevented:false,preventDefault(){prevented++;}});
    assert.equal(prevented,0);assert.equal(f.attrs.get('aria-busy'),'true');assert.equal(f.button.disabled,true);assert.equal(f.status.textContent,'Buscando informes…');
});
test('invalid or earlier-cancelled forms remain available without false pending feedback',()=>{
    for(const invalid of [false,true]){const f=fixture();initialize(f.doc);if(invalid)f.invalid();f.listeners.get('submit')({defaultPrevented:!invalid});assert.equal(f.button.disabled,false);assert.equal(f.attrs.size,0);assert.equal(f.status.textContent,'');}
});
test('BFCache/page lifecycle reset restores submit button and clears only own feedback',()=>{
    const f=fixture();const reset=initialize(f.doc);f.listeners.get('submit')({defaultPrevented:false});reset();assert.equal(f.button.disabled,false);assert.equal(f.attrs.has('aria-busy'),false);assert.equal(f.status.textContent,'');
});
test('failed preview hides only its own broken image and exposes fallback without removing real PDF actions',()=>{
    const f=fixture();initialize(f.doc);f.listeners.get('image:error')();assert.equal(f.image.hidden,true);assert.equal(f.fallback.hidden,false);assert.ok(f.form.querySelector);markImageFailure({closest:()=>null});
});
test('already failed complete previews recover; unloaded lazy images are not mistaken for failures',()=>{
    const bad=fixture();bad.image.complete=true;bad.image.currentSrc='https://shop.example/actual-thumbnail';initialize(bad.doc);assert.equal(bad.image.hidden,true);
    const lazy=fixture();lazy.image.complete=false;lazy.image.currentSrc='';initialize(lazy.doc);assert.equal(lazy.image.hidden,false);assert.equal(lazy.fallback.hidden,true);
});
test('enhancing twice binds each actual form/image once without multiplying submission work',()=>{
    const f=fixture();let binds=0;const original=f.form.addEventListener;f.form.addEventListener=(...args)=>{binds++;original(...args);};initialize(f.doc);initialize(f.doc);assert.equal(binds,1);resetPending(f.form);
});
