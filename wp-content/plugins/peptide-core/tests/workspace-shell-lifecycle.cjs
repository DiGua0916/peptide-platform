/* Synthetic DOM boundary tests of actual router, not browser performance evidence. */
const vm = require('node:vm'), fs = require('node:fs'), assert = require('node:assert/strict');
const source = fs.readFileSync(require.resolve('../assets/workspace-shell.js'),'utf8');
class Node {
  constructor() { this.events={}; this.attrs={}; this.classes=new Set(); this.classList={add:(...v)=>v.forEach(x=>this.classes.add(x)),remove:(...v)=>v.forEach(x=>this.classes.delete(x))}; this.children=[]; this.hidden=false; this.isConnected=true; }
  addEventListener(n,f){ (this.events[n] ||= []).push(f); }
  fire(n,e={}){ for(const f of this.events[n] || []) f(e); }
  setAttribute(n,v){this.attrs[n]=v;} removeAttribute(n){delete this.attrs[n];}
  append(n){this.children.push(n);} remove(){this.removed=true; this.isConnected=false;}
  focus(){} querySelector(){return null;} querySelectorAll(){return [];}
}
const status=new Node(),host=new Node();host.querySelector=()=>status;
const document=new Node();document.getElementById=id=>id==='pc-workspace-shell'?host:null;
const frames=[];document.createElement=()=>{const n=new Node();frames.push(n);return n;};
const location={href:'https://store.example/wp-admin/admin.php?page=pc-overview',origin:'https://store.example',assign:url=>{location.assigned=url;}};
const history={entries:[],pushState(s,t,u){this.entries.push(u);location.href=u;},replaceState(s,t,u){location.href=u;}};
const timers=new Map();let tid=0;const window=new Node();window.parent=window;window.location=location;window.confirm=()=>true;
const ctx={window,document,location,history,URL,Set,Map,JSON,console,setTimeout:f=>{timers.set(++tid,f);return tid;},clearTimeout:n=>timers.delete(n)};
vm.runInNewContext(source,ctx);
function load(frame, title='Page') {
  const doc=new Node();doc.title=title;doc.getElementById=id=>id==='wpbody-content'?{}:null;
  frame.contentDocument=doc;frame.contentWindow={location:new URL(frame.src)};frame.fire('load');return doc;
}
let checks=0;function check(value){assert.ok(value);checks++;}
const initial=frames[0];check(!!initial);load(initial,'Dashboard');check(!status.hidden===false);check(!initial.removed);
function click(url){const a={href:new URL(url,location.href).href,target:'',hasAttribute:()=>false,closest:()=>null};const e={target:{closest:()=>a},button:0,preventDefault(){this.defaultPrevented=true;}};document.fire('click',e);return e;}
click('admin.php?page=pc-data');const data=frames.at(-1);check(initial.inert===true);check(!initial.removed);load(data,'Data');check(initial.removed);check(document.title==='Data');check(history.entries.length===1);check(location.href.endsWith('page=pc-data'));check(!data.classes.has('pc-shell-preparing'));
click('edit.php?post_type=product');const stale=frames.at(-1);click('admin.php?page=pc-overview');const fresh=frames.at(-1);check(stale.removed);load(stale,'Stale');check(document.title==='Data');load(fresh,'Dashboard');check(document.title==='Dashboard');check(data.removed);
const form={method:'post',isConnected:true,elements:[{name:'label',type:'text',value:'old'}]};fresh.contentDocument.querySelectorAll=sel=>sel==='form'?[form]:[];
// A newly rendered page captures its real form baseline on load.
click('admin.php?page=pc-shipping');const settings=frames.at(-1);const doc=load(settings,'Settings');doc.querySelector=sel=>sel.includes('data-unsaved')?{}:null;
window.confirm=()=>false;const oldCount=frames.length;click('admin.php?page=pc-data');check(frames.length===oldCount);check(!settings.removed);
location.href='https://store.example/wp-admin/admin.php?page=pc-overview';window.fire('popstate');check(!settings.removed);check(location.href.includes('page=pc-shipping'));
window.confirm=()=>true;doc.querySelector=()=>null;click('admin.php?page=pc-data');const timeout=frames.at(-1);const expire=Array.from(timers.values()).at(-1);expire();check(timeout.removed);check(!settings.removed);check(settings.inert===false);check(status.textContent.includes('重试'));
const e=click('admin-post.php?action=delete&_wpnonce=x');check(!e.defaultPrevented);
console.log(JSON.stringify({checks,passed:true,syntheticDOM:true}));
