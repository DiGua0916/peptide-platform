'use strict';
// Execute the production archive bridge with synthetic native close-event timing.
const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const source=fs.readFileSync(path.resolve(__dirname,'../assets/product-report-library-viewer.js'),'utf8');
function fixture({supported=true,deferOpen=false}={}){
 const closeEvents=[],owners=[];let document;
 class Events{constructor(){this.events=new Map();}addEventListener(type,fn){this.events.set(type,[...(this.events.get(type)||[]),fn]);}fire(type,event={}){event.type=type;if(!event.target)event.target=this;for(const fn of this.events.get(type)||[])fn(event);}}
 class El extends Events{
  constructor(tag='div',className=''){super();this.tagName=tag;this.className=className;this.parentNode=null;this.children=[];this.dataset={};this.attrs={};this.open=false;this._text='';this.scrollTop=0;}
  get isConnected(){return this===document.body||!!this.parentNode?.isConnected;}set textContent(v){this._text=v;}get textContent(){return this._text+this.children.map(x=>x.textContent).join('');}
  setAttribute(k,v){this.attrs[k]=v;}append(...items){for(const el of items){el.remove();el.parentNode=this;this.children.push(el);}}remove(){if(this.parentNode){this.parentNode.children=this.parentNode.children.filter(x=>x!==this);this.parentNode=null;}}
  closest(selector){if(selector==='.pc-coa-library__report')return this.card||null;return this.className==='pc-coa-library__view'?this:null;}
  querySelector(selector){return this.fields?.[selector]||null;}
  showModal(){this.open=true;}close(){if(this.open){this.open=false;closeEvents.push(()=>this.fire('close'));}}focus(options){document.focused={el:this,options};}
 }
 document=new Events();document.body=new El('body');document.createElement=tag=>new El(tag);
 const urls=['https://shop.test/wp-json/peptide/v1/report-library/7/file?download=0','https://shop.test/?rest_route=%2Fpeptide%2Fv1%2Freport-library%2F8%2Ffile&download=0'];
 const links=urls.map((href,index)=>{const card=new El('article','pc-coa-library__report'),link=new El('a','pc-coa-library__view'),title=new El('h3'),download=new El('a');title.textContent='Genuine public DTO title '+(index+1);link.href=href;link.card=card;download.href=href.replace('download=0','download=1');card.fields={'.pc-coa-library__details dd':title,'.pc-coa-library__download':download};card.append(link);document.body.append(card);return link;});
 document.querySelectorAll=()=>links;
 const window=new Events();if(supported)window.HTMLDialogElement=function(){};if(supported)window.HTMLDialogElement.prototype.showModal=()=>{};
 window.createProductReportViewer=({element})=>{const owner={element,disposed:0,open(payload){this.payload=payload;if(deferOpen)return new Promise(resolve=>{this.resolve=resolve;});},dispose(){this.disposed++;}};owners.push(owner);return owner;};
 vm.runInNewContext(source,{window,document,URL,location:{href:'https://shop.test/coa-library/?coa_q=report&coa_page=2#results',origin:'https://shop.test'}});
 return {window,document,links,urls,owners,api:window.VialCoveReportLibraryViewer,dialogs:()=>document.body.children.filter(x=>x.tagName==='dialog'),flush(){while(closeEvents.length)closeEvents.shift()();},click(link,extra={}){const event={target:link,button:0,defaultPrevented:false,preventDefault(){this.defaultPrevented=true;},...extra};document.fire('click',event);return event;}};
}
test('genuine pretty and plain-route View links open the same reader and preserve original PDF/download URLs',()=>{
 const f=fixture();for(let n=0;n<2;n++){const event=f.click(f.links[n]);assert.equal(event.defaultPrevented,true);assert.equal(f.owners[n].payload.pdf,f.urls[n]);assert.match(f.owners[n].payload.title,/Genuine public DTO title/);assert.equal(f.dialogs()[0].dataset.vcHistoryReportOrigin,'library');assert.equal(f.dialogs()[0].children.at(-1).href,f.urls[n].replace('download=0','download=1'));}f.flush();
});
test('queued close from previous report disposes only its own PDF and cannot steal focus from a newer report',()=>{
 const f=fixture();f.api.open(f.urls[0]);f.api.open(f.urls[1]);f.flush();assert.equal(f.owners[0].disposed,1);assert.equal(f.owners[1].disposed,0);assert.equal(f.dialogs()[0].open,true);assert.equal(f.document.focused,undefined);
 f.api.close();f.flush();assert.equal(f.owners[1].disposed,1);assert.equal(f.document.focused.el,f.links[1]);assert.equal(f.document.focused.options.preventScroll,true);
});
test('history cannot open an absent, external, protected, nonce-bearing or download resource',()=>{
 const f=fixture();for(const url of ['https://external.test/wp-json/peptide/v1/report-library/7/file','/wp-json/peptide/v1/reports/7/file','/wp-json/peptide/v1/report-library/77/file','/wp-json/peptide/v1/report-library/7/file?download=1','/wp-json/peptide/v1/report-library/7/file?_wpnonce=old'])assert.equal(f.api.open(url),false);assert.equal(f.owners.length,0);
});
test('unsupported browser and modifier clicks retain the native PDF action',()=>{
 const off=fixture({supported:false});assert.equal(off.click(off.links[0]).defaultPrevented,false);assert.equal(off.owners.length,0);
 for(const modifier of ['metaKey','ctrlKey','shiftKey','altKey']){const f=fixture();assert.equal(f.click(f.links[0],{[modifier]:true}).defaultPrevented,false);assert.equal(f.owners.length,0);}
});
test('same open resource does not reload; pagehide closes disposed layer so BFCache can reopen exactly',()=>{
 const f=fixture();f.api.open(f.urls[0]);assert.equal(f.api.open(f.urls[0]),true);assert.equal(f.owners.length,1);f.window.fire('pagehide');f.flush();assert.equal(f.dialogs()[0].open,false);assert.equal(f.owners[0].disposed,1);f.api.open(f.urls[0]);assert.equal(f.owners.length,2);assert.equal(f.owners[1].payload.pdf,f.urls[0]);
});
test('Forward scroll waits for genuine page geometry readiness and stale PDF completion cannot scroll a newer report',async()=>{
 const settle=async()=>{for(let n=0;n<6;n++)await Promise.resolve();};
 const f=fixture({deferOpen:true});f.api.open(f.urls[0],undefined,1440);assert.equal(f.dialogs()[0].scrollTop,0);f.owners[0].resolve();await settle();assert.equal(f.dialogs()[0].scrollTop,1440);
 f.api.open(f.urls[1],undefined,2000);f.api.open(f.urls[0]);f.owners[1].resolve();await settle();assert.equal(f.dialogs()[0].scrollTop,0);f.owners[2].resolve();await settle();assert.equal(f.dialogs()[0].scrollTop,0);
});
