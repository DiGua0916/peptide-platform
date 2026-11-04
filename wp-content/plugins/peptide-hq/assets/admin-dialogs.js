/** Explicit asynchronous admin dialogs. Never patch browser confirm or replay clicks. */
(function () {
  'use strict';
  if (window.VialCoveDialogs) return;
  let queue = Promise.resolve();
  function ask(kind, message, initial) {
    const run = () => new Promise(resolve => {
      const prior = document.activeElement;
      const selection = window.getSelection && window.getSelection();
      const range = selection && selection.rangeCount ? selection.getRangeAt(0).cloneRange() : null;
      const d = document.createElement('dialog'); d.className = 'vc-admin-dialog';
      d.setAttribute('aria-labelledby','vc-admin-dialog-title'); d.setAttribute('aria-describedby','vc-admin-dialog-message');
      const title = document.createElement('h2'); title.id='vc-admin-dialog-title'; title.textContent=kind==='alert'?'提示':kind==='prompt'?'请输入':'请确认操作';
      const text = document.createElement('p'); text.id='vc-admin-dialog-message'; text.textContent=String(message || '是否继续此操作？');
      const input = document.createElement('input'); input.type='text'; input.value=initial || ''; input.setAttribute('aria-label',String(message || '输入内容'));
      const actions=document.createElement('div'); actions.className='vc-admin-dialog__actions';
      const cancel=document.createElement('button'); cancel.type='button'; cancel.textContent='取消';
      const ok=document.createElement('button'); ok.type='button'; ok.className='vc-admin-dialog__primary'; ok.textContent=kind==='alert'?'知道了':'确认';
      d.append(title,text); if(kind==='prompt')d.append(input); if(kind!=='alert')actions.append(cancel); actions.append(ok); d.append(actions);
      let settled=false;
      function finish(approved) {
        if(settled)return; settled=true;
        const result=kind==='prompt'?(approved?input.value:null):approved;
        d.removeEventListener('keydown',keys,true); if(d.open)d.close(); d.remove();
        if(prior && prior.isConnected)prior.focus({preventScroll:true});
        if(range && range.commonAncestorContainer.isConnected && selection){selection.removeAllRanges();selection.addRange(range);}
        resolve(result);
      }
      function keys(e){if(e.key==='Escape'){e.preventDefault();e.stopImmediatePropagation();finish(false);} else if(e.key==='Enter' && e.target===input){e.preventDefault();finish(true);}}
      d.addEventListener('keydown',keys,true); d.addEventListener('cancel',e=>{e.preventDefault();e.stopPropagation();finish(false);});
      d.addEventListener('close',()=>finish(false)); cancel.addEventListener('click',()=>finish(false)); ok.addEventListener('click',()=>finish(true));
      document.body.append(d);
      try { d.showModal(); (kind==='prompt'?input:kind==='alert'?ok:cancel).focus(); }
      catch (_) { finish(false); }
    });
    const result=queue.then(run,run); queue=result.then(()=>{},()=>{}); return result;
  }
  let currentEvent=null;
  ['click','submit'].forEach(type=>document.addEventListener(type,event=>{currentEvent=event;setTimeout(()=>{if(currentEvent===event)currentEvent=null;},0);},true));
  const approvals=new WeakMap(), waiting=new WeakSet();
  async function confirmSubmit(form, submitter, message, onApprove) {
    if(waiting.has(form))return;
    waiting.add(form);
    const snapshot=new FormData(form), action=form.action, method=form.method;
    const stamp=data=>JSON.stringify(Array.from(data.entries()).map(([k,v])=>[k,typeof v==='string'?v:[v.name,v.size,v.lastModified]]));
    const before=stamp(snapshot);
    try {
      if(!await ask('confirm',message))return;
      if(!form.isConnected || form.action!==action || form.method!==method || before!==stamp(new FormData(form)) || submitter && (!submitter.isConnected || submitter.form!==form))return;
      if(onApprove)onApprove();
      approvals.set(form,submitter || null);
      try { form.requestSubmit(submitter || undefined); } finally { approvals.delete(form); }
    } finally {waiting.delete(form);}
  }
  function approved(form,submitter){return approvals.has(form) && approvals.get(form)===(submitter || null);}
  function messageFor(el){if(el.hasAttribute('data-pc-confirm-json')){try{return JSON.parse(el.getAttribute('data-pc-confirm-json'));}catch(_){return '请确认是否继续此操作？';}} return el.getAttribute('data-pc-confirm');}
  // Core legacy inline guards contain only a quoted message. Parse literals, never eval handlers.
  function readLiteral(value) {
    const quote=value[0];if(!["'",'"'].includes(quote) || value.at(-1)!==quote)throw new Error('literal');
    let out='';
    for(let i=1;i<value.length-1;i++){
      let ch=value[i];if(ch!=='\\'){out+=ch;continue;}
      ch=value[++i];
      if(ch==='u'||ch==='x'){const size=ch==='u'?4:2,hex=value.slice(i+1,i+1+size);if(!new RegExp('^[0-9a-fA-F]{'+size+'}$').test(hex))throw new Error('escape');out+=String.fromCharCode(parseInt(hex,16));i+=size;}
      else out+=({n:'\n',r:'\r',t:'\t',b:'\b',f:'\f',v:'\v','0':'\0'})[ch] ?? ch;
    }
    return out;
  }
  function prepareInline(element,attribute,event) {
    const source=element.getAttribute(attribute);if(!source)return false;
    const literal="('(?:\\\\.|[^'\\\\])*'|\"(?:\\\\.|[^\"\\\\])*\")";
    if(/^\s*return\s+(?:window\.)?showNotice\.warn\(\s*\)\s*;?\s*$/.test(source)){element.setAttribute('data-pc-confirm','确定永久删除所选内容？此操作无法撤销。');element.removeAttribute(attribute);return false;}
    const match=source.match(new RegExp('^\\s*return\\s+(?:window\\.)?confirm\\s*\\(\\s*'+literal+'\\s*\\)\\s*;?\\s*$'));
    if(match){
      try{element.setAttribute('data-pc-confirm',readLiteral(match[1]));element.removeAttribute(attribute);return false;}catch(_){}
    }
    // WordPress's Get Shortlink button displays a value; its prompt result is unused.
    if(attribute==='onclick' && /^\s*prompt\(\s*['"]URL:['"]\s*,\s*jQuery\(['"]#shortlink['"]\)\.val\(\)\s*\)\s*;?\s*$/.test(source)){
      event.preventDefault();event.stopImmediatePropagation();ask('prompt','短链接',document.getElementById('shortlink')?.value || '');return true;
    }
    if(/(?:^|[^\w])(?:window\.)?(?:confirm|prompt|alert)\s*\(/.test(source)){
      event.preventDefault();event.stopImmediatePropagation();ask('alert','此操作的提示尚未适配，已安全停止。请刷新页面后重试。');return true;
    }
    return false;
  }
  document.addEventListener('submit',event=>{
    const form=event.target;
    if (prepareInline(form,'onsubmit',event)) return;
    if(!form.matches('form[data-pc-confirm],form[data-pc-confirm-json]') || approved(form,event.submitter))return;
    const field=form.getAttribute('data-pc-confirm-field');
    if(field && (!form.elements.namedItem(field) || form.elements.namedItem(field).value!==form.getAttribute('data-pc-confirm-value')))return;
    const required=form.getAttribute('data-pc-confirm-selected');
    if(required && !form.querySelector(required)){event.preventDefault();return;}
    event.preventDefault(); event.stopImmediatePropagation(); confirmSubmit(form,event.submitter,messageFor(form));
  },true);
  async function confirmLink(link,message,onApprove){const href=link.href;if(await ask('confirm',message) && link.isConnected && link.href===href){if(onApprove)onApprove();window.location.assign(href);}}
  document.addEventListener('click',event=>{
    const inline=event.target.closest && event.target.closest('a[onclick],button[onclick],input[onclick]');
    if(inline && prepareInline(inline,'onclick',event))return;
    const link=event.target.closest && event.target.closest('a[data-pc-confirm],a[data-pc-confirm-json]');
    if(!link)return;
    event.preventDefault();event.stopImmediatePropagation();confirmLink(link,messageFor(link));
  },true);
  // Legacy vendor handlers that only confirm a browser default (link or form).
  // Cancel synchronously; approval performs that default without replaying click handlers.
  function confirmDefault(event,message,onApprove) {
    if(!event || typeof event.preventDefault!=='function')return false;
    if(event.type==='submit' && event.target.matches('form')) {
      if(approved(event.target,event.submitter))return true;
      event.preventDefault();event.stopImmediatePropagation();confirmSubmit(event.target,event.submitter,message,onApprove);return false;
    }
    event.preventDefault();event.stopImmediatePropagation();
    const target=event.target && event.target.closest && event.target.closest('a[href],button,input[type="submit"],input[type="image"]');
    if(!target)return false;
    if(target.matches('a[href]'))confirmLink(target,message,onApprove);
    else if(target.form)confirmSubmit(target.form,target,message,onApprove);
    return false;
  }
  window.VialCoveDialogs={confirm:message=>ask('confirm',message),alert:message=>ask('alert',message),prompt:(message,initial)=>ask('prompt',message,initial),confirmSubmit,approved,confirmLink,confirmDefault,currentEvent:()=>currentEvent};
}());
