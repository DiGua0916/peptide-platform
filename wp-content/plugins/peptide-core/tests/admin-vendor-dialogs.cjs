'use strict';
const fs=require('node:fs'),vm=require('node:vm'),path=require('node:path'),assert=require('node:assert/strict');
const acorn=require(process.env.ADMIN_DIALOG_ACORN || 'D:/Codex/Tasks/2026-11-05/admin-dialog-audit/work/parser/node_modules/acorn');
const folder=path.resolve(__dirname,'../assets/woocommerce-dialogs');let checks=0,methods=0;
(async()=>{
for(const name of Object.keys(JSON.parse(fs.readFileSync(path.join(folder,'manifest.json'),'utf8')).files)){
 const source=fs.readFileSync(path.join(folder,name),'utf8'),ast=acorn.parse(source,{ecmaVersion:'latest'}),targets=new Map();
 function walk(n,parents){if(!n||!n.type)return;
  if(n.type==='CallExpression'&&n.callee.type==='MemberExpression'&&n.callee.object.type==='MemberExpression'&&n.callee.object.property.name==='VialCoveDialogs'&&['confirm','prompt'].includes(n.callee.property.name)){
   const fn=parents.findLast(x=>['FunctionExpression','FunctionDeclaration'].includes(x.type));targets.set(fn.start,fn);
  }
  for(const v of Object.values(n)){if(Array.isArray(v))v.forEach(x=>walk(x,[...parents,n]));else if(v&&v.type)walk(v,[...parents,n]);}
 }
 walk(ast,[]);
 for(const fn of targets.values()){
  let network=0,prevented=false;
  const chain=new Proxy(function(){return chain;},{get(target,key){if(key==='length')return 1;if(['val','attr','text','data'].includes(key))return()=>'';if(key==='is')return selector=>selector===':visible:checked';if(key==='preventDefault')return()=>{prevented=true;};return()=>chain;},apply(){return chain;}});
  const $=()=>chain;$.post=$.ajax=()=>{network++;return chain;};$.extend=(...xs)=>Object.assign({},...xs);$.each=()=>{};
  const strings=new Proxy({post_id:123},{get:(t,k)=>t[k]||'Synthetic confirmation'});
  const service={confirm:async()=>false,prompt:async()=>null,alert:()=>{}};
  const methodsStub=new Proxy({check_for_changes:async()=>false},{get:(t,k)=>k==='model'?methodsStub:t[k]||(()=>chain)});
  const context={$,jQuery:$,window:{VialCoveDialogs:service,wcTracks:{recordEvent(){}}},document:{},woocommerce_admin_meta_boxes:strings,woocommerce_admin_meta_boxes_variations:strings,wc_meta_boxes_order_items:methodsStub,wc_meta_boxes_order:methodsStub,wc_meta_boxes_product_variations_ajax:methodsStub,console,_:{indexBy:()=>({}),extend:Object.assign},data:{strings:new Proxy({},{get:()=> 'Synthetic confirmation'})}};
  const callback=vm.runInNewContext('('+source.slice(fn.start,fn.end)+')',context);
  const event={preventDefault(){prevented=true;},stopPropagation(){},target:{},data:{view:methodsStub}};
  const pending=callback.call({},event);assert.equal(prevented,true,name+' default must stop synchronously');checks++;
  await pending;assert.equal(network,0,name+' cancelled operation issued network mutation');checks++;methods++;
 }
 assert.ok(!/window\.(confirm|alert|prompt)\s*\(/.test(source));checks++;
}
console.log(JSON.stringify({passed:true,methods,checks,cancelOnly:true,synthetic:true}));
})().catch(error=>{console.error(error);process.exitCode=1;});



