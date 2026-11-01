/* Actual email renderer; VM/DOM boundary double only, no browser or requests. */
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
let passed = 0;
function check(name, yes) { assert.ok(yes, name); ++passed; }
const source = fs.readFileSync(path.join(__dirname, '../assets/data-emails.js'), 'utf8');
const seam = "\tPcData.register( 'emails', render );";
assert.equal(source.split(seam).length, 2);
function harness(href = 'https://store.example.invalid/wp-admin/admin.php?page=pc-data-emails') {
  const calls = [];
  const callbacks = [];
  let renderer;
  const strings = new Proxy({observation_enabled:'Collection enabled',observation_not_installed:'Storage not adopted',observation_unavailable:'Unavailable'}, {get:(o,k)=>o[k] ?? String(k)});
  const window = {
    URL, location:{href}, history:{replaceState(a,b,url) { window.location.href = url; }},
    PcData:{register(name,fn) { assert.equal(name,'emails'); renderer=fn; }}, pcDataEmails:{strings}
  };
  vm.runInNewContext(source.replace(seam, '\twindow.__email037 = {view:view,observationTiles:observationTiles};\n' + seam), {window});
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const ctx = {esc,number:v=>String(Number(v || 0)),change:v=>v == null ? '-' : String(v)+'%',strings:{vs:'VS',loading:'Loading',loadError:'Request failed'},api(url,params) { calls.push({url,params:{...params}}); return {then(ok,fail) { callbacks.push({ok,fail}); }}; }};
  const nodes = {'.pc-mail-family':{value:'',listeners:{},addEventListener(k,fn){this.listeners[k]=fn;}},'.pc-mail-sender':{value:'',listeners:{},addEventListener(k,fn){this.listeners[k]=fn;}}};
  const body = {innerHTML:'',querySelector(key){return nodes[key];}};
  return {window,api:window.__email037,ctx,calls,callbacks,body,nodes,render:renderer};
}
function data(observations) {
  return {current:{sent:9,success_rate:88.89},previous:{sent:3,success_rate:100},change:{sent:200,success_rate:-11.11},rows:[{name:'Legacy Woo type',sent:9,success_rate:88.89}],log_url:'/wp-admin/admin.php?page=pc-emails',observations};
}
const h=harness();
let html=h.api.view(data({status:'not_installed',current:null,previous:null,rows:[]}),h.ctx);
check('six metric cards retain separate legacy and observation headings',(html.match(/class="pc-emails-tile"/g)||[]).length===6 && html.includes('<h2>legacyTitle</h2>') && html.includes('<h2>observationTitle</h2>'));
check('unadopted storage displays four unavailable metrics',(html.match(/pc-emails-tile__value">-</g)||[]).length===4 && html.includes('Storage not adopted'));
check('missing collection has its own message',html.includes('noObservedMessages') && html.includes('observationHelp') && html.includes('legacyHelp'));
const report={status:'enabled',current:{opened:0,open_rate:0,clicked:1,click_rate:33.33},previous:{opened:null,open_rate:null,clicked:0,click_rate:0},rows:[{family:'woo',source_key:'type',name:'Unsafe <script>name</script>',sender:'<img src=x onerror=bad>',attempts:10,accepted:7,failed:2,uncertain:1,open_eligible:4,opened:0,open_rate:0,click_eligible:3,clicked:1,click_rate:33.33}],senders:[{sender_key:'a'.repeat(64),sender:'sender@example.invalid'}]};
html=h.api.view(data(report),h.ctx);
check('observed zero remains numeric zero and rate',html.includes('pc-emails-tile__value">0</div>') && html.includes('pc-emails-tile__value">0%</div>'));
check('rates and current/previous coverage stay distinct',html.includes('33.33%') && html.includes('>VS -</div>'));
check('source names and senders are escaped',html.includes('&lt;script&gt;name&lt;/script&gt;') && html.includes('&lt;img src=x onerror=bad&gt;') && !html.includes('<script>') && !html.includes('<img'));
check('legacy Woo numbers remain visible independently',html.includes('Legacy Woo type') && html.includes('88.89%') && html.includes('>9</td>'));
check('new table retains denominator columns',html.includes('>open_eligible</th>') && html.includes('>click_eligible</th>') && html.includes('>uncertain</th>'));
const none=h.api.view(data({status:'enabled',current:{opened:null,clicked:null},rows:[]}),h.ctx);
check('an empty observed cohort never renders a numeric open total',none.includes('noObservedMessages') && (none.match(/pc-emails-tile__value">-</g)||[]).length===4);
const range={dates:{from:'2026-10-30',to:'2026-10-31',compare_from:'2026-10-28',compare_to:'2026-10-29'}};
h.render(h.body,range,h.ctx);
check('actual renderer sends the selected cohort and mail filters',h.calls.length===1 && h.calls[0].url==='/peptide/v1/data/emails' && h.calls[0].params.from==='2026-10-30' && h.calls[0].params.compare_to==='2026-10-29' && h.calls[0].params.mail_family==='' && h.calls[0].params.mail_sender==='');
h.callbacks[0].ok(data(report));
h.nodes['.pc-mail-family'].value='campaign';h.nodes['.pc-mail-sender'].value='a'.repeat(64);h.nodes['.pc-mail-family'].listeners.change();
check('source/sender changes request server-side filters',h.calls[1].params.mail_family==='campaign' && h.calls[1].params.mail_sender==='a'.repeat(64));
check('source/sender filters persist in the URL',new URL(h.window.location.href).searchParams.get('mail_family')==='campaign' && new URL(h.window.location.href).searchParams.get('mail_sender')==='a'.repeat(64));
h.callbacks[1].ok(data(report));const accepted=h.body.innerHTML;h.callbacks[0].fail();
check('stale request failure cannot erase the accepted result',h.body.innerHTML===accepted);
h.render(h.body,range,h.ctx);h.render(h.body,{preset:'today'},h.ctx);h.callbacks[2].ok(data({status:'not_installed',current:null,rows:[]}));
check('stale success cannot replace the latest loading state',h.body.innerHTML.includes('Loading'));
h.callbacks[3].fail();check('latest request failure is visible',h.body.innerHTML.includes('Request failed'));
const selected=harness('https://store.example.invalid/wp-admin/admin.php?page=pc-data-emails&mail_family=woo&mail_sender='+'b'.repeat(64));
html=selected.api.view(data({status:'enabled',current:null,rows:[],senders:[]}),selected.ctx);
check('unlisted selected sender remains explicitly selected',html.includes('value="'+'b'.repeat(64)+'" selected') && html.includes('unobservedSender'));
selected.render(selected.body,{preset:'7d'},selected.ctx);
check('reload restores source/sender in the real request',selected.calls[0].params.range==='7d' && selected.calls[0].params.mail_family==='woo' && selected.calls[0].params.mail_sender==='b'.repeat(64));
check('nonfinite observation values remain unavailable',h.api.observationTiles({current:{opened:Infinity,open_rate:NaN},previous:null},h.ctx).includes('pc-emails-tile__value">-</div>'));
console.log(JSON.stringify({passed,failed:0,browser:false,networkCalls:0}));
