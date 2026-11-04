'use strict';
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
const acorn=require(process.env.ADMIN_DIALOG_ACORN || 'acorn');
const input=process.argv[2]; if(!input)throw Error('Usage: node build-woocommerce-dialogs.cjs WC_ADMIN_JS_DIRECTORY [OUTPUT_DIRECTORY]');
const output=path.resolve(process.argv[3] || path.join(__dirname,'../assets/woocommerce-dialogs'));fs.mkdirSync(output,{recursive:true});
const defaults=new Set(['quick-edit.js','settings.js','system-status.js','wc-customer-stock-notifications.js','woocommerce_admin.js']);
const manifest={version:'11.2.1',files:{}};let report=[];
for(const name of ["meta-boxes-order.js", "meta-boxes-product-variation.js", "meta-boxes-product.js", "quick-edit.js", "settings-views-html-settings-tax.js", "settings.js", "system-status.js", "wc-customer-stock-notifications.js", "wc-shipping-classes.js", "wc-shipping-providers.js", "wc-shipping-zone-methods.js", "wc-shipping-zones.js", "woocommerce_admin.js"]){
 const original=fs.readFileSync(path.join(input,name),'utf8');let s=original;
 for(let pass=0;pass<2;pass++){
  const ast=acorn.parse(s,{ecmaVersion:'latest'}),edits=[],functions=new Set();
  function walk(n,parents){if(!n||!n.type)return;
   if(n.type==='CallExpression'){
    const c=n.callee,kind=c.type==='MemberExpression'&&c.object.name==='window'&&c.property.name;
    const changes=c.type==='MemberExpression'&&c.object.name==='wc_meta_boxes_product_variations_ajax'&&c.property.name==='check_for_changes';
    if(['confirm','prompt','alert'].includes(kind) || changes&&parents.at(-1)?.type!=='AwaitExpression'){
     const fn=parents.findLast(x=>['FunctionDeclaration','FunctionExpression','ArrowFunctionExpression'].includes(x.type));
     if(kind==='confirm'&&defaults.has(name)){
      edits.push([c.start,c.end,'window.VialCoveDialogs.confirmDefault']);edits.push([n.start+(n.callee.end-n.callee.start)+s.slice(n.callee.end,n.end).indexOf('(')+1,n.start+(n.callee.end-n.callee.start)+s.slice(n.callee.end,n.end).indexOf('(')+1,'arguments[0], ']);
     }else{
      if(kind)edits.push([c.start,c.end,'window.VialCoveDialogs.'+kind]);
      if(kind!=='alert'){
       if(parents.at(-1)?.type!=='AwaitExpression')edits.push([n.start,n.start,'await ']);
       if(!fn)throw Error('No callback for '+name);if(!fn.async)functions.add(fn);
      }
     }
    }
   }
   for(const v of Object.values(n)){if(Array.isArray(v))v.forEach(x=>walk(x,[...parents,n]));else if(v&&v.type)walk(v,[...parents,n]);}
  }
  walk(ast,[]);
  for(const fn of functions){edits.push([fn.start,fn.start,'async ']);edits.push([fn.body.start+1,fn.body.start+1,'\n\t\t\t// Async handlers must cancel their default before awaiting a decision.\n\t\t\tif (arguments[0] && typeof arguments[0].preventDefault === "function") { arguments[0].preventDefault(); arguments[0].stopPropagation(); }\n']);}
  edits.sort((a,b)=>b[0]-a[0]||b[1]-a[1]);for(const [a,b,v]of edits)s=s.slice(0,a)+v+s.slice(b);acorn.parse(s,{ecmaVersion:'latest'});
 }
 // The approved-directories delete link has no JavaScript mutation: preserve its default guard.
 if(/window\.(?:confirm|prompt|alert)\s*\(/.test(s))throw Error('Native call remains '+name);
 fs.writeFileSync(path.join(output,name),'/* WooCommerce 11.2.1 compatibility: only explicit dialog calls/default guards adapted. GPL-3.0-or-later. */\n'+s);
 const hash=x=>crypto.createHash('sha256').update(x).digest('hex');manifest.files[name]={upstream:hash(original),adapted:hash(fs.readFileSync(path.join(output,name)))};
 report.push({name,asyncFunctions:(s.match(/async function/g)||[]).length,bytes:s.length});
}
fs.writeFileSync(path.join(output,'manifest.json'),JSON.stringify(manifest,null,2)+'\n');console.log(JSON.stringify(report));
