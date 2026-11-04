'use strict';
const fs=require('fs'),path=require('path'),crypto=require('crypto'),acorn=require(process.env.ADMIN_DIALOG_ACORN||'acorn');
const input=process.argv[2],output=path.resolve(process.argv[3]||path.join(__dirname,'../assets/woocommerce-dialogs'));
if(!input)throw Error('Usage: node build-woocommerce-client-dialogs.cjs FLAT_CLIENT_INPUT [OUTPUT]');
const hash=x=>crypto.createHash('sha256').update(x).digest('hex');
const manifestPath=path.join(output,'manifest.json'),manifest=JSON.parse(fs.readFileSync(manifestPath,'utf8'));
manifest.extraFiles={};manifest.preloadChunks=['assets/client/admin/chunks/analytics-settings.js'];
for(const [name,relative,target]of [['navigation.js','assets/client/admin/navigation/index.js','client-navigation.js'],['analytics-settings.js','assets/client/admin/chunks/analytics-settings.js','client-analytics-settings.js']]){
 const original=fs.readFileSync(path.join(input,name),'utf8'),ast=acorn.parse(original,{ecmaVersion:'latest'});let call,parents;
 function walk(n,p){if(!n||!n.type)return;if(n.type==='CallExpression'&&n.callee.type==='MemberExpression'&&n.callee.object.name==='window'&&n.callee.property.name==='confirm'){if(call)throw Error('Unexpected additional confirm');call=n;parents=p;}for(const v of Object.values(n)){if(Array.isArray(v))v.forEach(x=>walk(x,[...p,n]));else if(v&&v.type)walk(v,[...p,n]);}}walk(ast,[]);if(!call)throw Error('Expected native guard missing');
 let adapted;
 if(name==='navigation.js'){
  const funcs=parents.filter(x=>['FunctionExpression','ArrowFunctionExpression'].includes(x.type)),effect=funcs.at(-2);
  const expected='()=>{if(t){const e=i.push;return i.push=(...t)=>{const o=i.location,a=w(t[0]);"function"!=typeof r||r(a,o)?!1!==window.confirm(n)&&e(...t):e(...t)},()=>{i.push=e}}}';
  if(original.slice(effect.start,effect.end)!==expected)throw Error('Review changed history effect before adapting');
  const replacement='()=>{if(t){const e=i.push;let vcActive=true,vcIntent=0;return i.push=(...t)=>{const o=i.location,a=w(t[0]),vcId=++vcIntent;if("function"!=typeof r||r(a,o)){window.VialCoveDialogs.confirm(n).then(ok=>{if(ok&&vcActive&&vcId===vcIntent)e(...t)})}else e(...t)},()=>{vcActive=false;i.push=e}}}';
  adapted=original.slice(0,effect.start)+replacement+original.slice(effect.end);
 }else{
  const fn=parents.findLast(x=>x.type==='ArrowFunctionExpression');
  if(fn.async||fn.params.length||fn.body.type!=='BlockStatement')throw Error('Review changed reset callback');
  const edits=[[call.callee.start,call.callee.end,'window.VialCoveDialogs.confirm'],[call.start,call.start,'await '],[fn.start,fn.start,'async '],[fn.body.start+1,fn.body.start+1,'const vcEvent=window.VialCoveDialogs.currentEvent();if(vcEvent)vcEvent.preventDefault();']];
  edits.sort((a,b)=>b[0]-a[0]||b[1]-a[1]);adapted=original;for(const[a,b,value]of edits)adapted=adapted.slice(0,a)+value+adapted.slice(b);
 }
 adapted='/* WooCommerce 11.2.1 dialog compatibility; original code GPL-3.0-or-later. */\n'+adapted.replace(/\/\/# sourceMappingURL=.*$/mg,'');acorn.parse(adapted,{ecmaVersion:'latest'});
 fs.writeFileSync(path.join(output,target),adapted);
 manifest.extraFiles[relative]={upstream:hash(original),adapted:hash(adapted),target};
 if(name==='analytics-settings.js'){manifest.extraFiles[relative].webpackGlobal='webpackChunk_wcAdmin_webpackJsonp';manifest.extraFiles[relative].chunkIds=[5941];}
}
fs.writeFileSync(manifestPath,JSON.stringify(manifest,null,2)+'\n');console.log('Adapted the history guard and preloaded analytics settings chunk.');
