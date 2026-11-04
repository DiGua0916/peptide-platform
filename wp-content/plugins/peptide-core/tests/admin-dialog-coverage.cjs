'use strict';
/** Guard owned admin code against reintroducing browser-native dialogs. */
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const plugins=path.resolve(__dirname,'../..');let files=0,confirmations=0;
function scan(dir){for(const entry of fs.readdirSync(dir,{withFileTypes:true})){
 if(['tests','cli'].includes(entry.name))continue;
 const p=path.join(dir,entry.name);if(entry.isDirectory()){scan(p);continue;}
 if(!/\.(js|php)$/.test(p)||['storefront-language.js','tracking-checkout.js'].includes(entry.name))continue;
 const source=fs.readFileSync(p,'utf8');files++;
 assert.ok(!/(?:window|env)\.(?:alert|confirm|prompt)\s*\(/.test(source),p);
 assert.ok(!/(?:onclick|onsubmit)="[^"\n]*(?:confirm|alert|prompt)\(/.test(source),p);
 confirmations+=(source.match(/VialCoveDialogs|data-pc-confirm/g)||[]).length;
}}
for(const name of ['peptide-core','peptide-hq','peptide-panapay','peptide-ops']){const p=path.join(plugins,name);if(fs.existsSync(p))scan(p);}
assert.ok(confirmations>30);
const own=fs.readFileSync(path.join(plugins,'peptide-core/assets/admin-dialogs.js'),'utf8');
assert.equal(own,fs.readFileSync(path.join(plugins,'peptide-hq/assets/admin-dialogs.js'),'utf8'));
assert.ok(!/window\.(?:alert|confirm|prompt)\s*=/.test(own));
assert.ok(!/\.click\(\)/.test(own),'No replay of arbitrary action handlers');
console.log(JSON.stringify({passed:true,ownedFiles:files,dialogReferences:confirmations,excluded:'Public storefront and command-line; browser beforeunload remains protected. Third-party source is a separate audit.'}));
