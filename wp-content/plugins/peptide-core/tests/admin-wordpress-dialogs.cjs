'use strict';
const fs=require('fs'),vm=require('vm'),path=require('path'),assert=require('assert/strict');
const acorn=require(process.env.ADMIN_DIALOG_ACORN||'D:/Codex/Tasks/2026-11-05/admin-dialog-audit/work/parser/node_modules/acorn');
const folder=path.resolve(__dirname,'../assets/wordpress-dialogs');let checks=0,methods=0;
(async()=>{
for(const name of fs.readdirSync(folder).filter(x=>x.endsWith('.js'))){
 const source=fs.readFileSync(path.join(folder,name),'utf8'),ast=acorn.parse(source,{ecmaVersion:'latest'}),targets=new Map();
 function walk(n,parents){if(!n||!n.type)return;
  if(n.type==='CallExpression'){
   const c=n.callee;assert.ok(!(c.type==='Identifier'&&['alert','confirm','prompt'].includes(c.name)),name+' bare native');assert.ok(!(c.type==='MemberExpression'&&c.object.name==='window'&&['alert','confirm','prompt'].includes(c.property.name)),name+' window native');
   if(c.type==='MemberExpression'&&c.object.type==='MemberExpression'&&c.object.property.name==='VialCoveDialogs'&&['confirm','prompt'].includes(c.property.name)){
    const fn=parents.findLast(x=>['FunctionExpression','FunctionDeclaration'].includes(x.type));targets.set(fn.start,fn);
   }
  }
  for(const v of Object.values(n)){if(Array.isArray(v))v.forEach(x=>walk(x,[...parents,n]));else if(v&&v.type)walk(v,[...parents,n]);}
 }
 walk(ast,[]);checks++;
 for(const fn of targets.values()){
  let network=0,decisions=0,lastSelector='';
  const chain=new Proxy(function(){return chain;},{get(t,k){if(k==='length')return 1;if(k==='val')return()=>lastSelector.includes('imgedit-history')?'[{"type":"crop"}]':lastSelector.includes('imgedit-undone')?'0':name==='updates.js'?'delete-selected':'dirty';if(k==='css')return()=> 'block';if(k==='is')return()=>false;if(['text','attr','data'].includes(k))return()=> 'Synthetic';return()=>chain;},apply(){return chain;}});
  const $=selector=>{lastSelector=String(selector);return chain;};$.ajax=$.post=()=>{network++;return chain;};$.extend=Object.assign;
  const strings=new Proxy({},{get:()=> 'Synthetic confirmation'});
  const model=new Proxy({},{get:(t,k)=>()=>chain});
  const service={confirm:async()=>{decisions++;return false;},prompt:async()=>{decisions++;return null;},alert:()=>{},currentEvent:()=>null};
  const api={l10n:strings,settings:{l10n:strings,theme:{_filesystemCredentialsNeeded:false}},section:()=>model};
  const wp={i18n:{__:x=>x},themes:{data:{settings:strings}},updates:{maybeRequestFilesystemCredentials(){network++;}},ajax:{send(){network++;return chain;}}};
  const state={value:'pending',reject(){this.value='rejected';}};
  const context={window:{VialCoveDialogs:service},$,jQuery:$,wp,api,themes:wp.themes,document:{},__:(x)=>x,_n:x=>x,sprintf:x=>x,l10n:strings,quicktagsL10n:strings,QTags:{TagButton:{prototype:{callback(){}}}},mediaTrash:false,pagenow:'plugins',$bulkActionForm:chain,isCleanState:()=>false,clearedToClose:state,dismissLock:false,dismissAutoSave:false,JSON,console};
  const callback=vm.runInNewContext('('+source.slice(fn.start,fn.end)+')',context);
  const event={preventDefault(){},stopPropagation(){},target:{}};
  const receiver={controller:{state:()=>({get:()=>chain})},model,getFocusableElements(){},act:'edit',originalContent:'old',isOpen:()=>false,intval:v=>Number(v)};
  await callback.call(receiver,event);assert.equal(network,0,name+' cancellation performed mutation');assert.ok(decisions>0,name+' cancellation fixture did not reach decision');checks+=2;methods++;
 }
}
console.log(JSON.stringify({passed:true,methods,checks,cancelOnly:true,synthetic:true}));
})().catch(error=>{console.error(error);process.exitCode=1;});



