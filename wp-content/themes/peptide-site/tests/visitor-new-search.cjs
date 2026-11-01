'use strict';
/* Actual visibility source; reuse the existing portable DOM/IO/MO/clock boundary without edits. */
const test=require('node:test'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const context={require,__dirname,console,URL,Headers,AbortController,DOMException,TextEncoder,Uint8Array};
vm.runInNewContext(fs.readFileSync(path.join(__dirname,'visitor-products.cjs'),'utf8').split('(async()=>{')[0]+';this.boundary={fixture,Element};',context);
const {fixture,Element}=context.boundary,observation={page:{page_type:'content',path:'/'},context:{version:1,kind:'search',search:{version:1,status:'known',term:'demo peptide'}}};
function anchor(f,id='101',attrs={}){return f.document.documentElement.append(new Element('a',{class:'pc-site-search__result','data-vc-observe-product':id,'data-vc-observe-variation':'0',...attrs}));}
test('native search product anchors reuse the original received-visibility reducer',async()=>{
 let passed=0;const check=(label,yes)=>{assert.ok(yes,label);passed++;};
 let f=fixture({observation}),a=anchor(f);Object.defineProperty(a,'href',{get(){throw Error('URL must not be scraped');}});Object.defineProperty(a,'textContent',{get(){throw Error('Title must not be scraped');}});f.start();
 check('existing observer discovers exact native marked anchor',f.latestIO().targets.has(a));f.io(a,.399999);await f.advance(1000);check('below40 percent does not qualify',f.direct.length===0);f.io(a,.4);await f.advance(499);check('499ms does not qualify new anchor',f.direct.length===0);await f.advance(1);check('500ms emits canonical parent search-context exposure',f.direct.length===1&&JSON.stringify(f.direct[0])==='{"version":1,"product_id":101,"variation_id":0,"visible_fraction":0.4,"visible_ms":500,"context":{"kind":"search"}}');
 check('search term stays separate from impression context',!JSON.stringify(f.direct).includes('demo peptide'));const duplicate=anchor(f);f.mutation({type:'childList',addedNodes:[duplicate]});f.io(duplicate,1);await f.advance(1000);check('duplicate result placement shares original dedupe',f.direct.length===1);f.stop();
 for(const mode of ['hidden','offscreen','invalid-id','no-marker','other-anchor']){
  f=fixture({observation});a=anchor(f);if(mode==='hidden')a.style.display='none';if(mode==='offscreen')a.rect={left:0,top:900,right:100,bottom:1000};if(mode==='invalid-id')a.setAttribute('data-vc-observe-product','9007199254740993');if(mode==='no-marker')a.removeAttribute('data-vc-observe-product');if(mode==='other-anchor')a.setAttribute('class','other-result');f.start();f.io(a,1);await f.advance(1000);check('unmeasured or invalid native result '+mode,f.direct.length===0);f.stop();
 }
 f=fixture({observation});f.start();a=anchor(f,'202');f.mutation({type:'childList',addedNodes:[a]});f.io(a,.8);await f.advance(499);f.api().suspend();await f.advance(1000);check('dynamic anchor suspend cancels incomplete interval',f.direct.length===0);f.api().resume();f.io(a,.8);await f.advance(500);check('resume reuses original visibility interval rules',f.direct.length===1&&f.direct[0].product_id===202);f.stop();f.start();f.io(a,.8);await f.advance(500);check('new observer/page instance can observe same product again',f.direct.length===2);f.stop();
 console.log(JSON.stringify({suite:'new-search-products',passed,failed:0}));
});
