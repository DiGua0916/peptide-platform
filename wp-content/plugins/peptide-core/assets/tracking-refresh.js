/* Bounded detail polling; never reload a document or interrupt a reader. */
(function () {
 'use strict';
 const cfg=window.pcTrackingAdmin, root=document.getElementById('pc-trk-app');
 if(!root || cfg?.view!=='visitor' || !cfg.visitor || !window.wp?.apiFetch)return;
 const tools=document.createElement('div');tools.className='pc-trk-live-tools';
 tools.innerHTML='<button type="button" class="button" data-refresh-pause aria-pressed="false">暂停更新</button><button type="button" class="button" data-refresh-now>立即更新</button><span role="status" aria-live="polite">正在连接…</span><button type="button" class="button" data-refresh-new hidden>有新动作，查看更新</button>';
 root.before(tools);
 const status=tools.querySelector('[role=status]'), pause=tools.querySelector('[data-refresh-pause]'), notice=tools.querySelector('[data-refresh-new]');
 let pending=false,paused=false,stopped=false,controller=null,epoch=0,timer=null;
 const dispatch=(name,detail)=>document.dispatchEvent(new CustomEvent(name,{detail}));
 async function tick(){
  if(pending||paused||stopped||document.hidden)return;
  const box=document.getElementById('pc-trk-timeline');if(!box?.dataset.visitId || box.dataset.loading==='true')return;
  pending=true;const version=epoch,visit=box.dataset.visitId;controller=new AbortController();
  const timeout=setTimeout(()=>controller.abort(),12000);
  try{
   const data=await wp.apiFetch({path:'/peptide/v1/track/admin/visitors/'+cfg.visitor,signal:controller.signal});
   if(version!==epoch||stopped||paused)return;
   document.dispatchEvent(new CustomEvent('pc:visitor-snapshot',{detail:data}));
   const head=await wp.apiFetch({path:'/peptide/v1/track/admin/visits/'+visit+'?limit=1&order=desc&heartbeats='+(box.dataset.heartbeats||'0'),signal:controller.signal});
   if(version!==epoch||stopped||paused||visit!==box.dataset.visitId)return;
   const latest=String(head.events?.[0]?.id||0);
   if(latest!==box.dataset.latestId){
    const region=box.querySelector('.pc-lifecycle-scroll');
    if((region?.scrollTop||0)<8 && box.dataset.loadedOlder!=='true' && !box.querySelector('details[open]') && !box.contains(document.activeElement))dispatch('pc:timeline-refresh');
    else notice.hidden=false;
   }
   status.textContent='自动更新中 · '+new Date().toLocaleTimeString('zh-CN',{hour12:false})+'（本机时间）';
  }catch(error){if(!stopped&&!paused&&version===epoch)status.textContent='连接暂时中断，记录已保留，稍后自动重试。';}
  finally{clearTimeout(timeout);pending=false;}
 }
 pause.addEventListener('click',()=>{paused=!paused;epoch++;controller?.abort();pause.setAttribute('aria-pressed',String(paused));pause.textContent=paused?'继续更新':'暂停更新';status.textContent=paused?'已暂停更新':'正在恢复连接…';if(!paused)tick();});
 tools.querySelector('[data-refresh-now]').addEventListener('click',()=>{if(!paused)tick();});
 notice.addEventListener('click',()=>{notice.hidden=true;dispatch('pc:timeline-refresh',{force:true});});
 document.addEventListener('pc:timeline-pending',()=>{notice.hidden=false;});
 document.addEventListener('pc:visit-selected',()=>{epoch++;controller?.abort();notice.hidden=true;});
 document.addEventListener('visibilitychange',()=>{if(!document.hidden)tick();});
 window.addEventListener('online',tick);
 window.addEventListener('pagehide',()=>{stopped=true;epoch++;controller?.abort();clearInterval(timer);});
 window.addEventListener('pageshow',event=>{if(event.persisted){stopped=false;timer=setInterval(tick,5000);tick();}});
 timer=setInterval(tick,5000);tick();
}());
