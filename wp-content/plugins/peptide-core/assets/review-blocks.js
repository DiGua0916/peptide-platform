(() => {
  'use strict';
  const init = () => {
  document.querySelectorAll('[data-pc-review-block]:not([data-pc-review-ready])').forEach(group => {
    group.dataset.pcReviewReady = '1';
    const list = group.querySelector('.pc-rb__list'), controls = group.querySelector('.pc-rb__controls');
    const previous = group.querySelector('[data-pc-review-prev]'), next = group.querySelector('[data-pc-review-next]'), pause = group.querySelector('[data-pc-review-pause]');
    if (!list || !controls || !previous || !next) return;
    const mobile = matchMedia('(max-width:767px)'), reduced = matchMedia('(prefers-reduced-motion:reduce)');
    const carousel = () => (mobile.matches ? group.dataset.mobileLayout : group.dataset.desktopLayout) === 'carousel';
    let userPaused = false, hover = false, focused = false, visible = true;
    const setPaused = value => { userPaused=value; if(pause){pause.setAttribute('aria-pressed',String(userPaused));pause.textContent=userPaused?pause.dataset.play:pause.dataset.pause;} };
    const sync = () => {
      const enabled = carousel() && list.scrollWidth > list.clientWidth + 2;
      controls.hidden = !enabled; list.tabIndex = enabled ? 0 : -1;
      previous.disabled = list.scrollLeft <= 2; next.disabled = list.scrollLeft >= list.scrollWidth - list.clientWidth - 2;
    };
    const move = (direction, wrap = false) => {
      if (!carousel()) return;
      const behavior = reduced.matches ? 'auto' : 'smooth', max = list.scrollWidth - list.clientWidth;
      const target = wrap && direction > 0 && list.scrollLeft >= max - 2 ? 0 : Math.max(0,Math.min(max,list.scrollLeft+direction*list.clientWidth));
      list.scrollTo({left:target,behavior});
    };
    list.setAttribute('aria-label','Customer reviews');
    list.setAttribute('role','region');
    list.addEventListener('scroll',sync,{passive:true});
    list.addEventListener('keydown',event=>{
      if (!carousel() || !['ArrowLeft','ArrowRight','Home','End'].includes(event.key)) return;
      if(event.target!==list&&event.target.closest('a,button,input,textarea,select,video'))return;
      event.preventDefault(); setPaused(true);
      if (event.key === 'Home' || event.key === 'End') list.scrollTo({left:event.key==='Home'?0:list.scrollWidth-list.clientWidth,behavior:reduced.matches?'auto':'smooth'});
      else move(event.key==='ArrowRight'?1:-1);
    });
    previous.addEventListener('click',()=>{setPaused(true);move(-1);});next.addEventListener('click',()=>{setPaused(true);move(1);});
    list.addEventListener('pointerdown',event=>{if(event.pointerType==='touch'||event.pointerType==='pen')setPaused(true);},{passive:true});
    list.addEventListener('wheel',()=>setPaused(true),{passive:true});
    group.addEventListener('play',event=>{if(event.target instanceof HTMLVideoElement)setPaused(true);},true);
    group.addEventListener('pointerenter',()=>{hover=true;});group.addEventListener('pointerleave',()=>{hover=false;});
    group.addEventListener('focusin',()=>{focused=true;});group.addEventListener('focusout',event=>{focused=group.contains(event.relatedTarget);});
    pause?.addEventListener('click',()=>setPaused(!userPaused));
    if (typeof ResizeObserver === 'function') {const observer=new ResizeObserver(()=>{if(!group.isConnected)observer.disconnect();else sync();});observer.observe(list);}
    if (typeof IntersectionObserver === 'function') {const observer=new IntersectionObserver(entries=>{if(!group.isConnected)observer.disconnect();else visible=entries[0].isIntersecting;});observer.observe(group);}
    mobile.addEventListener('change',()=>{list.scrollLeft=0;sync();});
    if (group.dataset.autoplay === '1') {
      const timer=setInterval(()=>{if(!group.isConnected){clearInterval(timer);return;}const playing=[...group.querySelectorAll('video')].some(video=>!video.paused&&!video.ended);if(carousel()&&!reduced.matches&&!document.hidden&&visible&&!hover&&!focused&&!userPaused&&!playing)move(1,true);},Math.max(2000,Math.min(20000,Number(group.dataset.interval)||5000)));
    }
    sync();
    const opener=group.querySelector('[data-pc-review-more]'),dialog=group.querySelector('.pc-rb__dialog');
    if(opener&&dialog){
      const results=dialog.querySelector('[data-pc-review-results]'),status=dialog.querySelector('[data-pc-review-status]'),nextPage=dialog.querySelector('[data-pc-review-next-page]'),retry=dialog.querySelector('[data-pc-review-retry]'),close=dialog.querySelector('[data-pc-review-close]');
      let page=1,sequence=0,pending=false,controller=null,returnFocus=null;
      const load=async()=>{
        if(pending||!dialog.open)return;
        pending=true;const current=++sequence;controller=new AbortController();
        dialog.setAttribute('aria-busy','true');status.textContent='Loading reviews…';retry.hidden=true;nextPage.disabled=true;
        if(document.activeElement===retry||document.activeElement===nextPage){status.tabIndex=-1;status.focus({preventScroll:true});}
        try{
          const endpoint=new URL(opener.dataset.endpoint,location.href);if(endpoint.origin!==location.origin)throw Error('invalid endpoint');
          const response=await fetch(endpoint.href,{method:'POST',credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:new URLSearchParams({action:'pc_review_block_more',token:opener.dataset.token,page:String(page)})});
          const payload=await response.json();
          if(current!==sequence||!dialog.open||!group.isConnected)return;
          if(!response.ok||!payload.success){const error=Error('review response failed');error.userMessage=payload.data?.message||'Unable to load reviews. Please try again.';error.contextExpired=payload.data?.code==='invalid_context';throw error;}
          const data=payload.data;if(!data||typeof data.html!=='string'||typeof data.hasMore!=='boolean'||!Number.isInteger(data.count)||data.count<0||data.count>12||data.page!==page)throw Error('Unable to load reviews. Please try again.');
          results.insertAdjacentHTML('beforeend',data.html);status.textContent=data.message||`${data.count} reviews loaded.`;nextPage.hidden=!data.hasMore;page++;
        }catch(error){
          if(current!==sequence||!dialog.open||error.name==='AbortError')return;
          status.textContent=error.userMessage||(error instanceof TypeError?'Unable to load reviews. Check your connection and try again.':'Unable to load reviews. Please try again.');retry.hidden=!!error.contextExpired;nextPage.hidden=true;
        }finally{if(current===sequence){pending=false;dialog.setAttribute('aria-busy','false');nextPage.disabled=false;}}
      };
      const cancel=()=>{sequence++;controller?.abort();pending=false;dialog.setAttribute('aria-busy','false');};
      opener.addEventListener('click',()=>{
        if(typeof dialog.showModal!=='function')return;
        cancel();page=1;results.replaceChildren();nextPage.hidden=true;retry.hidden=true;returnFocus=document.activeElement;dialog.showModal();close.focus({preventScroll:true});load();
      });
      close.addEventListener('click',()=>dialog.close());
      dialog.addEventListener('close',()=>{cancel();dialog.querySelectorAll('video').forEach(video=>video.pause());if(returnFocus?.isConnected)returnFocus.focus({preventScroll:true});});
      dialog.addEventListener('click',event=>{const bounds=dialog.getBoundingClientRect();if(event.target===dialog&&(event.clientX<bounds.left||event.clientX>bounds.right||event.clientY<bounds.top||event.clientY>bounds.bottom))dialog.close();});
      dialog.addEventListener('keydown',event=>{
        if(event.key!=='Tab')return;
        const focusable=[...dialog.querySelectorAll('button:not([disabled]),a[href],input,select,textarea,video[controls],[tabindex="0"]')].filter(element=>!element.hidden&&element.getClientRects().length);
        const first=focusable[0],last=focusable[focusable.length-1];if(event.shiftKey&&document.activeElement===first){event.preventDefault();last?.focus();}else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first?.focus();}
      });
      nextPage.addEventListener('click',load);retry.addEventListener('click',load);
    }
    group.querySelectorAll('[data-pc-review-effect]').forEach(button=>{
      if(!['bounce','rubberband','shake','swing','wobble','pulse','shine'].includes(button.dataset.pcReviewEffect))return;
      const duration=Math.max(1000,Math.min(5000,Number(button.dataset.duration)||1000)),interval=Math.max(500,Math.min(5000,Number(button.dataset.interval)||1500));button.style.setProperty('--pc-rb-effect-duration',duration+'ms');
      const stopInvisible=()=>{if(document.hidden||reduced.matches)button.classList.remove('is-animating');};document.addEventListener('visibilitychange',stopInvisible);reduced.addEventListener('change',stopInvisible);
      let timer;const run=()=>{if(!button.isConnected){clearTimeout(timer);document.removeEventListener('visibilitychange',stopInvisible);reduced.removeEventListener('change',stopInvisible);return;}if(reduced.matches||document.hidden||!visible){button.classList.remove('is-animating');timer=setTimeout(run,interval);return;}button.classList.add('is-animating');timer=setTimeout(()=>{button.classList.remove('is-animating');timer=setTimeout(run,interval);},duration);};timer=setTimeout(run,interval);
    });
  });
  document.querySelectorAll('[data-pc-review-gallery]:not([data-pc-review-gallery-ready])').forEach(gallery=>{
    gallery.dataset.pcReviewGalleryReady='1';
    const tabs=[...gallery.querySelectorAll('.pc-rb-gallery__thumb')],panels=[...gallery.querySelectorAll('.pc-rb-gallery__panel')],rail=gallery.querySelector('.pc-rb-gallery__thumbs');
    if(!tabs.length||tabs.length!==panels.length)return;
    const activate=(index,focus=false)=>{
      tabs.forEach((tab,current)=>{const active=current===index;tab.setAttribute('aria-selected',String(active));tab.tabIndex=active?0:-1;panels[current].hidden=!active;if(!active)panels[current].querySelectorAll('video').forEach(video=>video.pause());});
      const tab=tabs[index];if(focus)tab.focus({preventScroll:true});
      if(rail){const left=tab.getBoundingClientRect().left-rail.getBoundingClientRect().left+rail.scrollLeft,right=left+tab.offsetWidth;if(left<rail.scrollLeft)rail.scrollLeft=left;else if(right>rail.scrollLeft+rail.clientWidth)rail.scrollLeft=right-rail.clientWidth;}
    };
    tabs.forEach((tab,index)=>{
      tab.addEventListener('click',()=>activate(index));
      tab.addEventListener('keydown',event=>{if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;event.preventDefault();const next=event.key==='Home'?0:event.key==='End'?tabs.length-1:(index+(event.key==='ArrowRight'?1:-1)+tabs.length)%tabs.length;activate(next,true);});
    });
  });
  };
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
  new MutationObserver(records=>{if(records.some(record=>[...record.addedNodes].some(node=>node.nodeType===1)))init();}).observe(document.documentElement,{childList:true,subtree:true});
})();
