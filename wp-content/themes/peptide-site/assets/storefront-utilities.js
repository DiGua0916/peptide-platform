/* Reserve the native mobile navigation's space; do not create a second menu. */
(() => {
 'use strict';
 const link=document.querySelector('.vc-help-float'),navigation=document.querySelector('.pc-mobile-fixed');
 if(!link||!navigation)return;
 let frame=0;
 const update=()=>{frame=0;const box=navigation.getBoundingClientRect();const visible=getComputedStyle(navigation).display!=='none'&&box.width>0&&box.height>0;link.style.setProperty('--vc-help-nav-offset',visible?Math.ceil(box.height)+'px':'0px');};
 const schedule=()=>{if(!frame)frame=requestAnimationFrame(update);};
 const observer=typeof ResizeObserver==='function'?new ResizeObserver(schedule):null;observer?.observe(navigation);
 window.addEventListener('resize',schedule,{passive:true});window.addEventListener('pageshow',schedule);update();
 window.addEventListener('pagehide',event=>{if(event.persisted)return;observer?.disconnect();cancelAnimationFrame(frame);window.removeEventListener('resize',schedule);window.removeEventListener('pageshow',schedule);},{once:true});
})();
