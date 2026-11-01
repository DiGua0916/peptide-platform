/* Actual shell/Operations/full-table/CSV using accepted DOM/API boundaries. */
'use strict';
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const {createRequire}=require('node:module');
const file=path.join(__dirname,'data-full-rankings.cjs'),source=fs.readFileSync(file,'utf8').replace(/\r\n/g,'\n');
const marker='\n(async()=>{\n  const h=harness();';assert.equal(source.split(marker).length,2);
const box={require:createRequire(file),__dirname,console,process,URL,URLSearchParams,setImmediate};
vm.runInNewContext(source.slice(0,source.indexOf(marker))+'\nglobalThis.ui046={harness,initial,listing,ranking,raw,flush,dates};',box,{filename:file});
const {harness,initial,listing,ranking,raw,flush,dates}=box.ui046;
let passed=0;const check=(name,yes)=>{assert.ok(yes,name);++passed;};
const coverage={current:{unit:'search_page_views',base_count:10,known:4,empty:1,filtered:2,unobserved:3},previous:{unit:'search_page_views',base_count:0,known:0,empty:0,filtered:0,unobserved:0}};
(async()=>{
 const h=harness(),strings=h.window.pcDataOperations.strings;strings.t_search_terms='搜索词';strings.rankSearchCoverage='搜索页% n;known%k;empty%e;filtered%f;old%u'.replace('% n','%n');strings.rankSearchHelp='按已接收搜索结果页浏览事件计次；刷新和分页另计，重试不重复。';
 const req=await initial(h,{late:true});const data=ranking();data.tables.search_terms={rows:[{key:'safe',label:'<safe>',edit_url:'',current:4,previous:0,change:null}],total_rows:1,coverage};req.resolve(data);await flush();
 const buttons=h.root.querySelectorAll('[data-rank-open]');check('all20 native openers include final search table',buttons.length===20&&buttons.at(-1).getAttribute('data-rank-open')==='search_terms');
 const summaryHtml=h.root.querySelector('.pc-ops-rankings').innerHTML;check('summary count labels escaped and honest source coverage present',summaryHtml.includes('&lt;safe&gt;')&&summaryHtml.includes('known4')&&summaryHtml.includes('filtered2')&&summaryHtml.includes('刷新和分页另计'));
 h.open('search_terms');check('search full table receives accepted explicit current/compare dates',h.last().params.table==='search_terms'&&h.last().params.from===dates.from&&h.last().params.compare_from===dates.compare_from&&h.el('export').disabled);
 h.last().resolve(listing('search_terms',{total:1,rows:[{key:'demo peptide',label:'demo peptide',edit_url:'',current:4,previous:0,change:null}],coverage}));await flush();
 check('full table renders counts and source coverage with export enabled',h.el('title').textContent==='搜索词'&&h.el('result').innerHTML.includes('demo peptide')&&h.el('result').innerHTML.includes('known4')&&!h.el('export').disabled);
 h.el('export').fire('click');const csv=h.last();check('search CSV uses same table/current/compare and complete filter contract',csv.params.table==='search_terms'&&csv.params.format==='csv'&&csv.params.from===dates.from&&csv.params.compare_from===dates.compare_from);
 h.el('search').value='peptide';h.el('search').fire('input');csv.resolve(raw());await flush();check('editing search immediately blocks stale CSV download',h.el('export').disabled&&h.downloads.length===0);h.tick(400);const current=h.last();check('full search query sent with accepted date range',current.params.q==='peptide'&&current.params.table==='search_terms'&&current.params.from===dates.from);current.resolve(listing('search_terms',{q:'peptide',total:1,rows:[],coverage}));await flush();
 h.el('export').fire('click');h.last().resolve(raw());await flush();check('only accepted current search CSV downloads',h.downloads.length===1);
 h.open('search_terms');const stale=h.last();h.range('yesterday');const range=h.last();stale.resolve(listing('search_terms',{rows:[{key:'STALE',label:'STALE',current:999,previous:0,change:null}],coverage}));await flush();check('range pending prevents stale search JSON and export',h.el('export').disabled&&!h.el('result').innerHTML.includes('STALE'));range.reject(Error('range'));await flush();h.tick(0);check('failed range recovers retained accepted dates once',h.last().params.table==='search_terms'&&h.last().params.from===dates.from);
 console.log(JSON.stringify({suite:'native-search-ui',passed,failed:0}));
})().catch(error=>{console.error(error);process.exitCode=1;});
