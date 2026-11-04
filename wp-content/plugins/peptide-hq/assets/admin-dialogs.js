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
  const approvals=new WeakMap(), waiting=new WeakSet();
  async function confirmSubmit(form, submitter, message) {
    if(waiting.has(form))return;
    waiting.add(form);
    const snapshot=new FormData(form), action=form.action, method=form.method;
    const stamp=data=>JSON.stringify(Array.from(data.entries()).map(([k,v])=>[k,typeof v==='string'?v:[v.name,v.size,v.lastModified]]));
    const before=stamp(snapshot);
    try {
      if(!await ask('confirm',message))return;
      if(!form.isConnected || form.action!==action || form.method!==method || before!==stamp(new FormData(form)) || submitter && (!submitter.isConnected || submitter.form!==form))return;
      approvals.set(form,submitter || null);
      try { form.requestSubmit(submitter || undefined); } finally { approvals.delete(form); }
    } finally {waiting.delete(form);}
  }
  function approved(form,submitter){return approvals.has(form) && approvals.get(form)===(submitter || null);}
  function messageFor(el){if(el.hasAttribute('data-pc-confirm-json')){try{return JSON.parse(el.getAttribute('data-pc-confirm-json'));}catch(_){return '请确认是否继续此操作？';}} return el.getAttribute('data-pc-confirm');}
  document.addEventListener('submit',event=>{
    const form=event.target;
    if(!form.matches('form[data-pc-confirm],form[data-pc-confirm-json]') || approved(form,event.submitter))return;
    const field=form.getAttribute('data-pc-confirm-field');
    if(field && (!form.elements.namedItem(field) || form.elements.namedItem(field).value!==form.getAttribute('data-pc-confirm-value')))return;
    const required=form.getAttribute('data-pc-confirm-selected');
    if(required && !form.querySelector(required)){event.preventDefault();return;}
    event.preventDefault(); event.stopImmediatePropagation(); confirmSubmit(form,event.submitter,messageFor(form));
  },true);
  async function confirmLink(link,message){const href=link.href;if(await ask('confirm',message) && link.isConnected && link.href===href)window.location.assign(href);}
  document.addEventListener('click',event=>{
    const link=event.target.closest && event.target.closest('a[data-pc-confirm],a[data-pc-confirm-json]');
    if(!link)return;
    event.preventDefault();event.stopImmediatePropagation();confirmLink(link,messageFor(link));
  },true);
  window.VialCoveDialogs={confirm:message=>ask('confirm',message),alert:message=>ask('alert',message),prompt:(message,initial)=>ask('prompt',message,initial),confirmSubmit,approved,confirmLink};
}());
