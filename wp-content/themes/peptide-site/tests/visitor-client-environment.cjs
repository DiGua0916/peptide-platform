/* Actual collector/adapter; reuse the accepted034 browser/clock/HTTP boundary fixture. */
'use strict';
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const {createRequire}=require('node:module');
const fixturePath=path.join(__dirname,'visitor-metadata-push.cjs');
const fixtureSource=fs.readFileSync(fixturePath,'utf8');
const marker='(async () => { await attributionCases();';
assert.equal(fixtureSource.split(marker).length,2);
const sandbox={require:createRequire(fixturePath),__dirname,console,process,URL,Headers,DOMException,AbortController,TextEncoder,Uint8Array,Date};
vm.runInNewContext(fixtureSource.slice(0,fixtureSource.indexOf(marker))+'\nglobalThis.env043={fixture,pv,drain,json};',sandbox,{filename:fixturePath});
const {fixture,pv,drain,json}=sandbox.env043;
let passed=0;
const check=(name,yes)=>{assert.ok(yes,name);++passed;};
function browser(options={}) {
 const f=fixture({config:{client_environment_protocol:1,...options.config},...options.fixture});
 // osFacts counts userAgentData / platform, which only the environment collector reads. navigator.userAgent is also
 // read once per observer start by the crawler / headless-browser filter (2026-11-08), which is not an environment read.
 const reads={os:0,osFacts:0,screen:0,forbidden:0};
 const nav={};
 for(const [key,value]of Object.entries({userAgentData:{platform:'Windows'},platform:'Win32',userAgent:'Mozilla Windows PRIVATE_FULL_UA',...options.navigator}))Object.defineProperty(nav,key,{configurable:true,get(){++reads.os;if(key!=='userAgent')++reads.osFacts;if(options.osThrows===key)throw Error('API unavailable');return value;}});
 nav.getHighEntropyValues=()=>{++reads.forbidden;throw Error('No high entropy API');};
 Object.defineProperty(nav,'maxTouchPoints',{get(){++reads.forbidden;throw Error('No hardware inference');}});
 f.window.navigator=options.noNavigator?undefined:nav;
 const screen={};for(const [key,value]of Object.entries({width:1920,height:1080,...options.screen}))Object.defineProperty(screen,key,{configurable:true,get(){++reads.screen;if(options.screenThrows===key)throw Error('Screen API unavailable');return value;}});
 Object.defineProperty(screen,'pixelDepth',{get(){++reads.forbidden;throw Error('No extra screen facts');}});
 f.window.screen=options.noScreen?undefined:screen;
 return {...f,reads,nav,screen};
}
const env=f=>pv(f.calls[0]).data.page_context.environment;
(async()=>{
 for(const [navigator,family]of [
  [{userAgentData:{platform:'Android'},platform:'Linux arm64',userAgent:'Android Linux PRIVATE_FULL_UA'},'Android'],
  [{userAgentData:{platform:'iOS'},platform:'MacIntel',userAgent:'iPhone like Mac OS X'},'iOS'],
  [{userAgentData:{platform:'Chrome OS'},platform:'Linux x86_64',userAgent:'X11 CrOS x86_64'},'ChromeOS'],
  [{userAgentData:{platform:'Windows'},platform:'Win32',userAgent:'Windows NT'},'Windows'],
  [{userAgentData:{platform:'macOS'},platform:'MacIntel',userAgent:'Macintosh Mac OS X'},'macOS'],
  [{userAgentData:{platform:'Linux'},platform:'Linux x86_64',userAgent:'Linux'},'Linux']
 ]){const f=browser({navigator});await f.advance();check('fixed OS enum and Android/iOS/ChromeOS priority',env(f).os.status==='known'&&env(f).os.family===family);check('only normalized OS/screen facts enter real PV',JSON.stringify(env(f))===JSON.stringify({version:1,os:{status:'known',family},screen:{status:'known',width:1920,height:1080}})&&!JSON.stringify(f.calls).includes('PRIVATE_FULL_UA')&&f.reads.forbidden===0);f.stop();}
 for(const navigator of [{userAgentData:{platform:'Android'},platform:'iPhone',userAgent:''},{userAgentData:{platform:'Windows'},platform:'MacIntel',userAgent:''},{userAgentData:{platform:'Linux'},platform:'Linux',userAgent:'Tizen'},{userAgentData:{platform:'FreeBSD'},platform:'',userAgent:''}]){const f=browser({navigator});await f.advance();check('ambiguous and unsupported reports remain unknown',env(f).os.status==='unknown'&&!Object.hasOwn(env(f).os,'family'));f.stop();}
 for(const screen of [{width:1,height:1},{width:16384,height:16384}]){const f=browser({screen});await f.advance();check('native screen CSS integer bounds accepted',env(f).screen.status==='known'&&env(f).screen.width===screen.width);f.stop();}
 for(const width of [0,-1,16385,1.5,'1920',Infinity,null]){const f=browser({screen:{width,height:1080}});await f.advance();check('invalid screen values are unknown, never coerced',env(f).screen.status==='unknown'&&!Object.hasOwn(env(f).screen,'width'));f.stop();}
 {const f=browser({noNavigator:true,noScreen:true});await f.advance();check('missing APIs are explicit unknown facts',env(f).os.status==='unknown'&&env(f).screen.status==='unknown');f.stop();}
 {const f=browser({osThrows:'userAgentData'});await f.advance();check('throwing OS API is unavailable independently of screen',env(f).os.status==='unavailable'&&env(f).screen.status==='known');f.stop();}
 {const f=browser({screenThrows:'height'});await f.advance();check('throwing screen API is unavailable independently of OS',env(f).screen.status==='unavailable'&&env(f).os.family==='Windows');f.stop();}
 for(const version of [undefined,0,2,'1',true]){const f=browser({config:{client_environment_protocol:version},osThrows:'userAgentData',screenThrows:'width'});await f.advance();check('old/invalid receiver capability never reads or sends client environment',!Object.hasOwn(pv(f.calls[0]).data.page_context,'environment')&&f.reads.osFacts===0&&f.reads.screen===0);f.stop();}
 {const f=browser({config:{metadata_protocols:{attribution:1}}});await f.advance();check('environment requires independent page-context receiver capability',!Object.hasOwn(pv(f.calls[0]).data,'page_context')&&f.reads.osFacts===0);f.stop();}
 for(const config of [{enabled:false},{endpoint:'https://other.test/track'}]){const f=browser({config});await f.advance();check('privacy/endpoint startup gates remain inert',f.calls.length===0&&f.reads.osFacts===0&&f.reads.screen===0);f.stop();}
 {const f=browser();f.scenarios.push({network:true});await f.advance();const first=json(pv(f.calls[0])),firstReads={...f.reads},identity=f.calls[0].body.tab_id;Object.defineProperty(f.nav,'platform',{get(){++f.reads.os;return 'MacIntel';}});Object.defineProperty(f.screen,'width',{get(){++f.reads.screen;return 1080;}});Object.defineProperty(f.screen,'height',{get(){++f.reads.screen;return 1920;}});f.rotate();await f.advance(1100);const copies=f.calls.flatMap(c=>c.body.events).filter(e=>e.event_id===first.event_id);check('retry and tab rotation retain full frozen environment',copies.length>=2&&copies.every(e=>JSON.stringify(e)===JSON.stringify(first))&&f.calls[1].body.tab_id===identity);check('send/retry never re-reads browser environment',f.reads.os===firstReads.os&&f.reads.screen===firstReads.screen);f.hide();await drain();f.show();await f.advance();const newest=f.calls.flatMap(c=>c.body.events).filter(e=>e.type==='page_view').at(-1);check('BFCache new PV reads fresh screen while old UID stays immutable',newest.event_id!==first.event_id&&newest.data.page_context.environment.screen.width===1080&&newest.data.page_context.environment.screen.height===1920&&copies.every(e=>e.data.page_context.environment.screen.width===1920));f.stop();}
 {const f=browser();await f.advance(90);f.action();f.scenarios.push({status:413},{network:true});await f.advance(400);const first=json(pv(f.calls[0]));const reads={...f.reads};f.rotate();await f.advance(1100);const copies=f.calls.flatMap(c=>c.body.events).filter(e=>e.event_id===first.event_id);check('413 split plus network retry preserves normalized environment and UID',f.calls[0].body.events.length===2&&copies.length>=3&&copies.every(e=>JSON.stringify(e)===JSON.stringify(first))&&f.reads.os===reads.os&&f.reads.screen===reads.screen);f.stop();}
 {const f=browser();await f.advance();f.document.dispatchEvent({type:'vc:visitor-action',detail:{type:'client_environment',data:{os:'Injected'}}});f.document.dispatchEvent({type:'vc:visitor-action',detail:{type:'cart_open',data:{source:'native',environment:{version:1,os:{status:'known',family:'Android'}}}}});await f.advance(400);check('public actions cannot emit environment facts',f.calls.flatMap(c=>c.body.events).filter(e=>e.type!=='page_view').every(e=>!Object.hasOwn(e.data,'environment')&&!Object.hasOwn(e.data,'page_context')));f.stop();}
 {const f=browser();f.scenarios.push({status:403});await f.advance();const reads={...f.reads};f.hide();f.show();await f.advance(1000);check('denial stops collection and cannot restart via BFCache',f.stats().stopped&&f.reads.os===reads.os&&f.reads.screen===reads.screen);f.stop();}
 {const f=fixture({adapter:false}),packets=[];const input={version:1,kind:'home',environment:{version:1,os:{status:'known',family:'Windows',fullUA:'PRIVATE'},screen:{status:'known',width:1920,height:1080,pixelRatio:3},query:'PRIVATE'}};let calls=0;const observer=f.window.VialCoveVisitorObserver.start({context:{page_type:'home',path:'/'},pageViewMetadata:()=>{++calls;return {page_context:input};},sendBatch:async events=>{packets.push(events);return {ackEventIds:events.map(e=>e.event_id)};}});input.environment.os.family='Android';input.environment.screen.width=1;await f.advance();const fact=packets[0][0].data.page_context.environment;check('actual collector deeply copies/freezes normalized environment',calls===1&&fact.os.family==='Windows'&&fact.screen.width===1920&&Object.isFrozen(fact)&&Object.isFrozen(fact.os)&&Object.isFrozen(fact.screen)&&!JSON.stringify(packets).includes('PRIVATE'));observer.stop();}
 for(const bad of [{version:2,os:{status:'unknown'}},{version:1,os:{status:'unknown',family:'Windows'}},{version:1,screen:{status:'known',width:'1920',height:1080}},{version:1}]){const f=fixture({adapter:false}),packets=[];const observer=f.window.VialCoveVisitorObserver.start({context:{page_type:'home',path:'/'},pageViewMetadata:()=>({page_context:{version:1,kind:'home',environment:bad}}),sendBatch:async events=>{packets.push(events);return {ackEventIds:events.map(e=>e.event_id)};}});await f.advance();check('bad environment is atomically omitted without breaking the genuine PV',packets[0][0].type==='page_view'&&!Object.hasOwn(packets[0][0].data,'page_context')&&observer.getStats().extensionUnsupported===1);observer.stop();}
 console.log(JSON.stringify({suite:'client-environment',passed,failed:0,browser:false,network:false}));
})().catch(error=>{console.error(error);process.exitCode=1;});
