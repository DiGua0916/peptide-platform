'use strict';
const fs=require('fs'),path=require('path'),crypto=require('crypto'),acorn=require(process.env.ADMIN_DIALOG_ACORN || 'acorn');
const input=process.argv[2],target=path.resolve(process.argv[3] || path.join(__dirname,'../assets/wordpress-dialogs'));if(!input)throw Error('Usage: node build-wordpress-dialogs.cjs FLAT_CORE_JS_DIRECTORY [OUTPUT_DIRECTORY]');fs.mkdirSync(target,{recursive:true});
const manifest={version:'7.1.4',files:{}};
const eventPrefix='\nvar vcEvent = arguments[0] && typeof arguments[0].preventDefault === "function" ? arguments[0] : window.VialCoveDialogs.currentEvent(); if(vcEvent){vcEvent.preventDefault();vcEvent.stopPropagation();}\n';
for(const name of fs.readdirSync(input)){
 const original=fs.readFileSync(path.join(input,name),'utf8');let s=original;
 // Preserve the Customizer requestClose() jQuery Deferred contract for its .done/.fail callers.
 if(name==='customize-controls.js'){
  const ast=acorn.parse(s,{ecmaVersion:'latest'});let fn;
  function find(n){if(!n||!n.type)return;if(n.type==='FunctionDeclaration'&&n.id.name==='requestClose')fn=n;for(const v of Object.values(n)){if(Array.isArray(v))v.forEach(find);else if(v&&v.type)find(v);}}find(ast);
  const first=fn.body.body[0],last=fn.body.body.at(-1);if(last.type!=='ReturnStatement')throw Error('requestClose contract changed');
  s=s.slice(0,last.start)+'}).call(this).catch(function(){clearedToClose.reject();});\n'+s.slice(last.start);
  s=s.slice(0,first.end)+'\n(async function(){\n'+s.slice(first.end);
 }
 const propagation=new Set(name==='image-edit.js'?['notsaved']:name==='edit-comments.js'?['discardCommentChanges']:[]);
 for(let pass=0;pass<12;pass++){
  const ast=acorn.parse(s,{ecmaVersion:'latest'}),edits=[],funcs=new Map();
  function walk(n,parents){if(!n||!n.type)return;
   if(n.type==='CallExpression'){
    const c=n.callee;const kind=c.type==='Identifier'&&['confirm','alert','prompt'].includes(c.name)?c.name:c.type==='MemberExpression'&&c.object.name==='window'&&['confirm','alert','prompt'].includes(c.property.name)?c.property.name:null;
    const propagated=c.type==='MemberExpression'&&propagation.has(c.property.name)&&['this','t','imageEdit','commentReply'].includes(c.object.name||c.object.type.toLowerCase().replace('expression',''));
    if(kind || propagated&&parents.at(-1).type!=='AwaitExpression'){
     const fn=parents.findLast(x=>['FunctionDeclaration','FunctionExpression','ArrowFunctionExpression'].includes(x.type)),index=parents.indexOf(fn),owner=parents[index-1];
     const label=fn?.id?.name||owner?.key?.name;
     const useDefault=kind==='confirm'&&(name==='common.js'||name==='nav-menu.js'||name==='theme.js'&&c.type==='Identifier');
     if(useDefault){
      edits.push([c.start,c.end,'window.VialCoveDialogs.confirmDefault']);
      const open=s.indexOf('(',c.end);edits.push([open+1,open+1,'window.VialCoveDialogs.currentEvent(), ']);
      if(name==='nav-menu.js')edits.push([n.end-1,n.end-1,', function(){window.onbeforeunload=null;}']);
     }else{
      if(kind)edits.push([c.start,c.end,'window.VialCoveDialogs.'+kind]);
      if(kind!=='alert'){
       if(parents.at(-1).type!=='AwaitExpression')edits.push([n.start,n.start,'await ']);
       if(!fn)throw Error('No function '+name);if(!fn.async)funcs.set(fn.start,{fn,label});
      }
     }
    }
   }
   for(const v of Object.values(n)){if(Array.isArray(v))v.forEach(x=>walk(x,[...parents,n]));else if(v&&v.type)walk(v,[...parents,n]);}
  }
  walk(ast,[]);
  for(const {fn,label}of funcs.values()){
   edits.push([fn.start,fn.start,'async ']);
   const bulk=name==='updates.js'&&s.slice(fn.start,fn.end).includes('var bulkAction');
   if(!bulk)edits.push([fn.body.start+1,fn.body.start+1,eventPrefix]);
   else {const match=s.slice(fn.start,fn.end).match(/if \( ! (?:window\.)?confirm\( confirmMessage \) \)/);if(!match)throw Error('Bulk guard changed');edits.push([fn.start+match.index,fn.start+match.index,'event.preventDefault();\n']);}
   if(['image-edit.js','edit-comments.js'].includes(name)&&label)propagation.add(label);
  }
  if(!edits.length)break;
  edits.sort((a,b)=>b[0]-a[0]||b[1]-a[1]);for(const [a,b,v]of edits)s=s.slice(0,a)+v+s.slice(b);acorn.parse(s,{ecmaVersion:'latest'});
  if(pass===11)throw Error('Propagation did not converge '+name);
 }
 const result='/* WordPress 7.1.4 dialog compatibility. Original WordPress code: GPL-2.0-or-later. */\n'+s;
 fs.writeFileSync(path.join(target,name),result);const hash=x=>crypto.createHash('sha256').update(x).digest('hex');
 const directory=['colorpicker.js','media-views.js','quicktags.js','media-editor.js'].includes(name)?'wp-includes/js/':'wp-admin/js/';manifest.files[directory+name]={upstream:hash(original),adapted:hash(result)};
 console.log(name,propagation.size);
}
fs.writeFileSync(path.join(target,'manifest.json'),JSON.stringify(manifest,null,2)+'\n');
