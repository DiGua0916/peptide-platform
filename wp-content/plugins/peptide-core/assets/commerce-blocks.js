(() => {
  'use strict';
  // P02 presentation only. Native media owns playback, sound, progress and looping.
  const videoProducts = group => {
    if (group.dataset.pcVideoReady) return;
    const list = group.querySelector('.pc-video-products__list');
    if (!list) return;
    group.dataset.pcVideoReady = '1';
    const items = [...list.children], videos = items.map(item => item.querySelector('video'));
    const single = group.dataset.display === 'single', editor = group.dataset.editor === '1';
    const reduced = matchMedia('(prefers-reduced-motion:reduce)');
    const states = videos.map(() => ({ userPaused:false, automaticPause:false, automatic:false, pending:false, failed:false, attempt:0 }));
    const controls = group.querySelector('.pc-video-products__controls');
    const previous = group.querySelector('[data-pc-video-prev]'), next = group.querySelector('[data-pc-video-next]');
    let selected = 0, inView = true, suspended = false, scrollFrame = 0, scrollTarget = -1;
    const status = (index, message) => {
      const output = items[index].querySelector('.pc-video-products__status');
      if (output) { output.textContent = message; output.hidden = !message; }
    };
    const pause = index => {
      const video = videos[index];
      if (states[index].pending) { states[index].attempt++; states[index].pending = false; }
      if (video && !video.paused) { states[index].automaticPause = true; video.pause(); }
    };
    const visible = video => {
      const rect = video.getBoundingClientRect();
      return rect.width > 0 && rect.height > 0 && rect.bottom > 0 && rect.top < innerHeight && rect.right > 0 && rect.left < innerWidth;
    };
    const requestPlay = index => {
      const video = videos[index], state = states[index];
      if (!video || !video.paused || state.pending || state.failed || state.userPaused || !video.muted) return;
      state.pending = true; state.automatic = true; const attempt = ++state.attempt;
      const failed = () => { if (attempt !== state.attempt) return; state.pending = false; state.automatic = false; state.userPaused = true; status(index, 'Use the video controls to start playback.'); };
      try {
        const result = video.play();
        if (result && result.then) result.then(() => { if (attempt === state.attempt) state.pending = false; }).catch(failed);
        else state.pending = false;
      } catch (error) { failed(); }
    };
    const update = () => {
      videos.forEach((video, index) => {
        if (!video) return;
        const active = !single || index === selected;
        const present = !suspended && !document.hidden && inView && visible(video) && active;
        if (!present) { pause(index); return; }
        if (editor || reduced.matches) { if (states[index].automatic) { pause(index); states[index].automatic = false; } return; }
        if ((single ? group.dataset.autoCarousel : group.dataset.autoplay) === '1') requestPlay(index);
      });
    };
    const paint = () => {
      items.forEach((item,index) => { item.dataset.distance = String(Math.min(2,Math.abs(index-selected))); });
      if (previous) previous.disabled = items.length < 2;
      if (next) next.disabled = items.length < 2;
    };
    const select = (index, scroll = true) => {
      selected = (index + items.length) % items.length;
      paint();
      if (single && scroll) {
        scrollTarget = selected;
        const item = items[selected];
        list.scrollTo({left:item.offsetLeft - list.offsetLeft - (list.clientWidth-item.offsetWidth)/2,behavior:reduced.matches ? 'auto' : 'smooth'});
      }
      update();
    };
    videos.forEach((video,index) => {
      if (!video) return;
      const state = states[index];
      video.addEventListener('playing', () => { state.pending = false; state.userPaused = false; status(index,''); if (single && selected !== index) select(index); else update(); });
      video.addEventListener('pause', () => { state.attempt++; state.pending = false; if (state.automaticPause) state.automaticPause = false; else if (!video.ended) { state.userPaused = true; state.automatic = false; } });
      video.addEventListener('error', () => { state.failed = true; state.pending = false; status(index,'This video could not load. Try the video controls again.'); });
      video.addEventListener('loadeddata', () => { state.failed = false; update(); });
      video.addEventListener('ended', () => {
        if (single && index === selected && group.dataset.autoCarousel === '1' && !video.loop && !state.userPaused && !editor && !suspended && !document.hidden && !reduced.matches && inView) select(selected+1);
      });
    });
    previous?.addEventListener('click', () => select(selected-1));
    next?.addEventListener('click', () => select(selected+1));
    list.addEventListener('scroll', () => {
      if (!single || scrollFrame) return;
      scrollFrame = requestAnimationFrame(() => {
        scrollFrame = 0;
        const center = list.getBoundingClientRect().left + list.clientWidth/2;
        const index = items.reduce((best,item,index) => Math.abs(item.getBoundingClientRect().left+item.offsetWidth/2-center) < Math.abs(items[best].getBoundingClientRect().left+items[best].offsetWidth/2-center) ? index : best,0);
        if (scrollTarget >= 0) { if (index !== scrollTarget) return; scrollTarget = -1; }
        if (index !== selected) { selected = index; paint(); update(); }
      });
    },{passive:true});
    list.addEventListener('pointerdown', () => { scrollTarget = -1; },{passive:true});
    list.addEventListener('wheel', () => { scrollTarget = -1; },{passive:true});
    group.addEventListener('vc:select-item', event => {
      const item = event.target.closest && event.target.closest('[data-module-item]'), index = items.indexOf(item);
      if (index < 0 || item.parentElement !== list || !event.detail || typeof event.detail.item !== 'string' || event.detail.item !== item.dataset.moduleItem) return;
      event.preventDefault();
      states.forEach((state,index) => { state.userPaused = true; pause(index); });
      select(index);
      if (!single) item.scrollIntoView({block:'nearest',inline:'nearest',behavior:'auto'});
    });
    const observer = typeof IntersectionObserver === 'function' ? new IntersectionObserver(entries => {
      entries.forEach(entry => { if (entry.target === group) inView = entry.isIntersecting; });
      if (!group.isConnected) { observer.disconnect(); videos.forEach((video,index) => pause(index)); return; }
      update();
    },{threshold:0.01}) : null;
    observer?.observe(group);
    videos.forEach(video => { if (video) observer?.observe(video); });
    document.addEventListener('visibilitychange',update);
    reduced.addEventListener('change',update);
    window.addEventListener('resize',() => { scrollTarget = -1; update(); },{passive:true});
    window.addEventListener('pagehide',() => { suspended = true; update(); });
    window.addEventListener('pageshow',() => { suspended = false; update(); });
    if (controls) controls.hidden = items.length < 2;
    paint(); update();
  };
  // P07 keeps one operable sequence. Decorative copies never own links or focus.
  const productScroll = group => {
    const viewport = group.querySelector('.pc-product-scroll__viewport');
    const track = group.querySelector('.pc-product-scroll__track');
    const source = track?.querySelector('.pc-product-scroll__group');
    const button = group.querySelector('.pc-product-scroll__toggle');
    if (!viewport || !source || !button) return;
    group.dataset.pcScrollReady = '1';
    const reduced = matchMedia('(prefers-reduced-motion:reduce)'), abort = new AbortController();
    let manual = false, hovered = false, focused = false, scheduled = 0, viewportWidth = -1, sourceWidth = -1;
    const visible = () => group.getClientRects().length > 0 && viewport.clientWidth > 0;
    const update = () => {
      const reading = manual || hovered || focused || reduced.matches;
      group.classList.toggle('is-reading', reading);
      group.classList.toggle('is-paused', reading || document.hidden || !visible());
      button.setAttribute('aria-pressed', String(manual));
      button.textContent = manual ? group.dataset.resumeLabel : group.dataset.pauseLabel;
    };
    const clean = () => { abort.abort(); observer?.disconnect(); if (scheduled) cancelAnimationFrame(scheduled); };
    const measure = () => {
      scheduled = 0;
      if (!group.isConnected) { clean(); return; }
      if (!visible()) { viewportWidth = -1; update(); return; }
      const width = source.getBoundingClientRect().width;
      if (viewportWidth === viewport.clientWidth && sourceWidth === width) { update(); return; }
      viewportWidth = viewport.clientWidth; sourceWidth = width;
      track.querySelectorAll('[data-pc-scroll-copy]').forEach(copy => copy.remove());
      group.classList.remove('is-enhanced');
      button.hidden = true;
      const count = width > 0 ? Math.max(2, Math.ceil(viewport.clientWidth / width)) : 0;
      if (count && count <= 128 && source.children.length) {
        for (let n = 0; n < count; n++) {
          const copy = source.cloneNode(true);
          copy.dataset.pcScrollCopy = '1'; copy.inert = true;
          copy.setAttribute('inert', ''); copy.setAttribute('aria-hidden', 'true');
          [copy, ...copy.querySelectorAll('*')].forEach(node => {
            node.removeAttribute('id'); node.removeAttribute('data-module-item');
            if (node.matches('a,button,input,select,textarea,[tabindex],[contenteditable]')) { node.tabIndex = -1; node.removeAttribute('href'); node.removeAttribute('contenteditable'); }
          });
          track.appendChild(copy);
        }
        group.style.setProperty('--pc-scroll-distance', width + 'px');
        group.classList.add('is-enhanced'); button.hidden = reduced.matches;
      }
      update();
      const active = document.activeElement;
      if (focused && active?.isConnected && source.contains(active)) active.scrollIntoView({block:'nearest',inline:'nearest',behavior:'auto'});
    };
    const schedule = () => { if (!scheduled) scheduled = requestAnimationFrame(measure); };
    const observer = typeof ResizeObserver === 'function' ? new ResizeObserver(schedule) : null;
    const on = (node, name, callback) => node.addEventListener(name, callback, {signal:abort.signal});
    on(group, 'mouseenter', () => { hovered = true; update(); });
    on(group, 'mouseleave', () => { hovered = false; update(); });
    on(group, 'focusin', event => {
      focused = true; update();
      const item = event.target.closest('[data-pc-scroll-product]');
      if (item?.parentElement === source) item.scrollIntoView({block:'nearest',inline:'nearest',behavior:'auto'});
    });
    on(group, 'focusout', event => {
      if (group.contains(event.relatedTarget)) return;
      focused = false; if (!manual && !hovered) viewport.scrollLeft = 0; update();
    });
    on(button, 'click', () => { manual = !manual; if (!manual) viewport.scrollLeft = 0; update(); });
    on(document, 'visibilitychange', () => { if (!group.isConnected) clean(); else update(); });
    on(reduced, 'change', () => { viewportWidth = -1; measure(); });
    if (observer) { observer.observe(group); observer.observe(viewport); observer.observe(source); }
    else on(window, 'resize', schedule);
    source.querySelectorAll('img').forEach(image => { if (!image.complete) on(image, 'load', schedule); });
    if (document.fonts?.ready) document.fonts.ready.then(() => { if (group.isConnected) schedule(); });
    measure();
  };
  // P08 accepts only the visible row addressed by the existing Host contract.
  const collectionNavigation = group => {
    group.dataset.pcNavigationReady = '1';
    group.addEventListener('vc:select-item', event => {
      const item = event.target.closest?.('[data-module-item]');
      if (!item || !group.contains(item) || !event.detail || event.detail.item !== item.getAttribute('data-module-item') || !item.getClientRects().length || item.hidden || item.inert) return;
      event.preventDefault(); item.scrollIntoView({block:'nearest',inline:'nearest',behavior:'auto'});
    });
  };

  // P14 uses the existing signed P03 endpoint, with one unchanged logical card sequence.
  const aiProductWaterfall = group => {
    const list = group.querySelector('[data-pc-ai-waterfall-list]');
    const sentinel = group.querySelector('[data-pc-ai-waterfall-sentinel]');
    const status = group.querySelector('[data-pc-ai-waterfall-status]');
    const retry = group.querySelector('[data-pc-ai-waterfall-retry]');
    const more = group.querySelector('[data-pc-ai-waterfall-more]');
    if (!list || !sentinel || !status || !retry || !more || !window.fetch || !window.AbortController) return;
    const token = group.dataset.context, maxBatches = Number(group.dataset.batches);
    let endpoint;
    try { endpoint = new URL(group.dataset.endpoint,location.href); if (endpoint.origin !== location.origin || !/^https?:$/.test(endpoint.protocol)) return; } catch (_) { return; }
    if (!token || !Number.isInteger(maxBatches) || maxBatches < 1 || maxBatches > 5) return;
    group.dataset.pcAiWaterfallReady = '0';
    const media = matchMedia('(max-width:767px)'), lifetime = new AbortController(), observed = new WeakSet();
    let page = 1, hasMore = group.dataset.hasMore === '1', busy = false, failed = false, suspended = false;
    let request, epoch = 0, layoutFrame = 0, checkFrame = 0, lastWidth = -1, lastColumns = -1;
    const items = () => [...list.children].filter(node => node.matches('.pc-waterfall__item[data-pc-waterfall-product]'));
    const visible = () => !suspended && group.isConnected && group.getClientRects().length > 0 && list.clientWidth > 0 && getComputedStyle(group).visibility !== 'hidden';
    const on = (node,name,fn,options = {}) => node.addEventListener(name,fn,{...options,signal:lifetime.signal});
    const paint = () => {
      list.setAttribute('aria-busy',String(busy)); retry.hidden = !failed; retry.disabled = busy; more.hidden = !failed;
      status.textContent = busy ? 'Loading products…' : failed ? 'Products could not be loaded. Try again or open the collection.' : '';
    };
    const cleanup = () => {
      lifetime.abort(); request?.abort(); resize?.disconnect(); intersection?.disconnect();
      if (layoutFrame) cancelAnimationFrame(layoutFrame); if (checkFrame) cancelAnimationFrame(checkFrame);
    };
    const check = () => {
      checkFrame = 0;
      if (!group.isConnected) { cleanup(); return; }
      if (!visible() || document.hidden || !hasMore || busy || failed || page >= maxBatches) return;
      const box = sentinel.getBoundingClientRect(); if (box.top <= innerHeight + 80 && box.bottom >= 0) load();
    };
    const scheduleCheck = () => { if (!checkFrame) checkFrame = requestAnimationFrame(check); };
    const layout = () => {
      layoutFrame = 0;
      if (!group.isConnected) { cleanup(); return; }
      if (!visible()) return;
      const mobile = group.dataset.device === 'mobile' || (group.dataset.device === 'all' && media.matches);
      const rawColumns = parseInt(getComputedStyle(group).getPropertyValue(mobile ? '--pc-waterfall-cols-m' : '--pc-waterfall-cols'),10);
      const columns = Math.max(2,Math.min(mobile ? 3 : 5,rawColumns || (mobile ? 2 : 4)));
      const width = list.getBoundingClientRect().width, gapX = mobile ? 15 : 30, gapY = 30;
      const cardWidth = Math.max(1,(width - (columns - 1) * gapX) / columns), tops = Array(columns).fill(0);
      const active = document.activeElement, changedWidth = lastWidth !== width || lastColumns !== columns;
      let bottom = 0;
      group.dataset.pcAiWaterfallReady = '1';
      items().forEach((item,index) => {
        const column = index % columns;
        item.style.width = cardWidth + 'px'; item.style.left = column * (cardWidth + gapX) + 'px'; item.style.top = tops[column] + 'px';
        const visual = item.querySelector('.product-visual'), quick = item.querySelector(':scope > .pc-waterfall__quick');
        if (visual && quick) {
          item.dataset.pcAiQuickOverlay = '1';
          item.style.setProperty('--pc-ai-quick-top',Math.max(0,visual.getBoundingClientRect().bottom - item.getBoundingClientRect().top - (mobile ? 8 : 12)) + 'px');
        }
        const height = item.getBoundingClientRect().height;
        bottom = Math.max(bottom,tops[column] + height); tops[column] += height + gapY;
        if (resize && !observed.has(item)) { observed.add(item); resize.observe(item); }
      });
      list.style.height = bottom + 'px'; lastWidth = width; lastColumns = columns;
      if (changedWidth && active?.isConnected && list.contains(active)) active.scrollIntoView({block:'nearest',inline:'nearest',behavior:'auto'});
      paint(); scheduleCheck();
    };
    const scheduleLayout = () => { if (!layoutFrame) layoutFrame = requestAnimationFrame(layout); };
    const resize = typeof ResizeObserver === 'function' ? new ResizeObserver(scheduleLayout) : null;
    const intersection = typeof IntersectionObserver === 'function' ? new IntersectionObserver(scheduleCheck,{rootMargin:'80px 0px'}) : null;
    const load = async () => {
      if (!visible() || busy || !hasMore || page >= maxBatches) return;
      const generation = ++epoch, nextPage = page + 1, own = new AbortController(); request = own;
      const timer = setTimeout(() => own.abort(),20000); busy = true; failed = false; paint();
      try {
        const url = new URL(endpoint); url.searchParams.set('action','pc_product_waterfall_more'); url.searchParams.set('context',token); url.searchParams.set('page',String(nextPage));
        const response = await fetch(url,{method:'GET',credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'},signal:own.signal});
        const result = await response.json();
        if (generation !== epoch || !visible() || group.dataset.context !== token) return;
        if (!response.ok || result?.success !== true || result.data?.page !== nextPage || typeof result.data.html !== 'string' || typeof result.data.hasMore !== 'boolean') throw new Error('load_failed');
        const template = document.createElement('template'); template.innerHTML = result.data.html;
        const rows = [...template.content.children];
        if (rows.length > 40 || rows.some(node => !node.matches('.pc-waterfall__item[data-pc-waterfall-product]') || !/^[1-9]\d*$/.test(node.dataset.pcWaterfallProduct))) throw new Error('invalid_products');
        const known = new Set(items().map(node => node.dataset.pcWaterfallProduct));
        rows.forEach(node => { if (!known.has(node.dataset.pcWaterfallProduct)) { known.add(node.dataset.pcWaterfallProduct); list.append(node); } });
        page = nextPage; hasMore = result.data.hasMore && page < maxBatches; failed = false; layout();
      } catch (_) {
        if (generation === epoch && visible() && group.dataset.context === token) failed = true;
      } finally {
        clearTimeout(timer);
        if (request === own) { request = null; busy = false; paint(); scheduleLayout(); }
      }
    };
    on(retry,'click',() => { failed = false; load(); });
    on(list,'focusin',event => {
      const item = event.target.closest('.pc-waterfall__item[data-pc-waterfall-product]');
      if (item && item.parentElement === list && item === items().at(-1) && !failed) load();
    });
    on(window,'scroll',scheduleCheck,{passive:true}); on(window,'resize',scheduleLayout,{passive:true}); on(media,'change',scheduleLayout);
    on(list,'load',scheduleLayout,{capture:true}); on(document,'visibilitychange',scheduleCheck);
    on(window,'pagehide',() => { suspended = true; epoch++; request?.abort(); busy = false; intersection?.disconnect(); });
    on(window,'pageshow',() => { suspended = false; if (intersection) intersection.observe(sentinel); paint(); scheduleLayout(); });
    if (resize) { resize.observe(list); resize.observe(group); }
    if (intersection) intersection.observe(sentinel);
    if (document.fonts?.ready) document.fonts.ready.then(() => { if (group.isConnected) scheduleLayout(); });
    layout();
  };

  // P15 owns real hidden panels; opacity-only inactive products cannot retain keyboard focus.
  function behaviorRanking(root) {
    if (root.hasAttribute('data-pc-behavior-ranking-ready')) return;
    const nav = root.querySelector(':scope > .pc-behavior-ranking__tabs');
    const tabs = nav ? Array.from(nav.children).filter(node => node.matches('a[aria-controls]')) : [];
    const panels = tabs.map(tab => Array.from(root.children).find(node => node.id === tab.getAttribute('aria-controls')));
    if (tabs.length !== 3 || panels.some(panel => !panel)) return;
    root.setAttribute('data-pc-behavior-ranking-ready', '1'); nav.setAttribute('role', 'tablist');
    const activate = index => {
      if (index < 0 || index >= tabs.length) return;
      const active = document.activeElement;
      const focusWouldHide = panels.some((panel, i) => i !== index && panel.contains(active));
      tabs.forEach((tab, i) => { tab.setAttribute('role', 'tab'); tab.setAttribute('aria-selected', i === index ? 'true' : 'false'); tab.tabIndex = i === index ? 0 : -1; });
      panels.forEach((panel, i) => { panel.setAttribute('role', 'tabpanel'); panel.tabIndex = 0; panel.hidden = i !== index; panel.inert = i !== index; });
      if (focusWouldHide) tabs[index].focus();
    };
    tabs.forEach((tab, index) => {
      tab.addEventListener('click', event => { if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return; event.preventDefault(); activate(index); });
      tab.addEventListener('keydown', event => {
        let next = index;
        if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
        else if (event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length;
        else if (event.key === 'Home') next = 0;
        else if (event.key === 'End') next = tabs.length - 1;
        else if (event.key === ' ') { event.preventDefault(); activate(index); return; }
        else return;
        event.preventDefault(); activate(next); tabs[next].focus();
        tabs[next].scrollIntoView({ block: 'nearest', inline: 'nearest' });
      });
    });
    activate(0);
  }

  // P09 has one native rail: deterministic one-card positions, no clones or autoplay.
  const collectionProducts = group => {
    const viewport = group.querySelector('.pc-collection-products__viewport');
    const list = group.querySelector('.pc-collection-products__list');
    const controls = group.querySelector('[data-pc-collection-products-controls]');
    const dots = group.querySelector('[data-pc-collection-products-dots]');
    const previous = group.querySelector('[data-pc-collection-products-prev]');
    const next = group.querySelector('[data-pc-collection-products-next]');
    if (!viewport || !list || !controls || !previous || !next) return;
    group.dataset.pcCollectionProductsReady = '1';
    const cards = [...list.children], mobile = matchMedia('(max-width:767px)'), reduced = matchMedia('(prefers-reduced-motion:reduce)');
    const abort = new AbortController(); let positions = [], selected = 0, frame = 0, drag = null, suppressClickUntil = 0;
    const on = (node, name, fn, options = {}) => node.addEventListener(name, fn, {...options,signal:abort.signal});
    const visible = () => group.getClientRects().length > 0 && viewport.clientWidth > 0;
    const carousel = () => group.dataset.display === 'carousel';
    const cleanup = () => { abort.abort(); observer?.disconnect(); if (frame) cancelAnimationFrame(frame); };
    const sync = () => {
      if (!group.isConnected) { cleanup(); return; }
      if (!visible() || !carousel() || positions.length < 2) { controls.hidden = true; return; }
      controls.hidden = false;
      selected = positions.reduce((best, left, index) => Math.abs(left - viewport.scrollLeft) < Math.abs(positions[best] - viewport.scrollLeft) ? index : best, 0);
      if (dots) [...dots.children].forEach((dot, index) => dot.setAttribute('aria-current', String(index === selected)));
      previous.disabled = selected === 0; next.disabled = selected === positions.length - 1;
    };
    const move = index => {
      if (!visible() || !carousel() || !positions.length) return;
      const target = Math.max(0, Math.min(positions.length - 1, index));
      viewport.scrollTo({left:positions[target],behavior:reduced.matches ? 'auto' : 'smooth'});
    };
    const measure = () => {
      frame = 0;
      if (!group.isConnected) { cleanup(); return; }
      if (!visible()) { controls.hidden = true; return; }
      const value = getComputedStyle(group).getPropertyValue(mobile.matches ? '--pc-collection-product-cols-m' : '--pc-collection-product-cols');
      const cols = Math.max(1, Math.min(6, parseFloat(value) || (mobile.matches ? 2 : 4)));
      const max = Math.max(0, viewport.scrollWidth - viewport.clientWidth), count = Math.max(0, cards.length - cols);
      const computed = [];
      if (carousel() && cards.length) for (let n = 0; n <= count; n++) {
        const left = Math.max(0, Math.min(max, cards[n].offsetLeft - cards[0].offsetLeft));
        if (!computed.length || Math.abs(left - computed[computed.length - 1]) > 1) computed.push(left);
      }
      if (carousel() && computed.length && max - computed[computed.length - 1] > 1) computed.push(max);
      positions = computed;
      if (dots && dots.children.length !== positions.length) {
        dots.replaceChildren();
        positions.forEach((_, index) => {
          const dot = document.createElement('button'); dot.type = 'button'; dot.setAttribute('aria-label','Product position ' + (index + 1));
          dot.setAttribute('aria-controls',viewport.id); dot.addEventListener('click',()=>move(index),{signal:abort.signal}); dots.appendChild(dot);
        });
      }
      viewport.tabIndex = carousel() && max > 1 ? 0 : -1;
      viewport.setAttribute('aria-label','Collection products');
      const active = document.activeElement;
      if (carousel() && active?.isConnected && list.contains(active)) active.scrollIntoView({block:'nearest',inline:'nearest',behavior:'auto'});
      sync();
    };
    const schedule = () => { if (!frame) frame = requestAnimationFrame(measure); };
    const observer = typeof ResizeObserver === 'function' ? new ResizeObserver(schedule) : null;
    on(previous,'click',()=>move(selected - 1)); on(next,'click',()=>move(selected + 1));
    on(viewport,'scroll',sync,{passive:true});
    on(viewport,'keydown',event=>{
      if (!carousel() || !['ArrowLeft','ArrowRight','Home','End'].includes(event.key) || event.target.closest('input,select,textarea,[contenteditable]')) return;
      event.preventDefault(); move(event.key === 'Home' ? 0 : event.key === 'End' ? positions.length - 1 : selected + (event.key === 'ArrowRight' ? 1 : -1));
    });
    on(viewport,'focusin',event=>{ if (carousel() && list.contains(event.target)) { event.target.scrollIntoView({block:'nearest',inline:'nearest',behavior:'auto'}); sync(); } });
    on(viewport,'pointerdown',event=>{
      if (!carousel() || event.pointerType !== 'mouse' || event.button !== 0 || event.target.closest('button,input,select,textarea,[contenteditable]')) return;
      drag = {id:event.pointerId,x:event.clientX,left:viewport.scrollLeft,moved:false};
    });
    on(viewport,'dragstart',event=>{ if (drag) event.preventDefault(); });
    on(window,'pointermove',event=>{
      if (!drag || event.pointerId !== drag.id) return;
      const delta = event.clientX - drag.x;
      if (!drag.moved && Math.abs(delta) < 6) return;
      if (!drag.moved) { drag.moved = true; viewport.setPointerCapture(drag.id); group.classList.add('is-dragging'); }
      event.preventDefault(); viewport.scrollLeft = drag.left - delta;
    });
    const endDrag = event => { if (!drag || event.pointerId !== drag.id) return; if (drag.moved) suppressClickUntil = performance.now() + 400; drag = null; group.classList.remove('is-dragging'); };
    on(window,'pointerup',endDrag); on(window,'pointercancel',endDrag); on(viewport,'lostpointercapture',endDrag);
    on(viewport,'click',event=>{ if (performance.now() < suppressClickUntil) { event.preventDefault(); event.stopImmediatePropagation(); } },{capture:true});
    on(mobile,'change',schedule); if (observer) observer.observe(viewport); else on(window,'resize',schedule);
    if (document.fonts?.ready) document.fonts.ready.then(()=>{ if (group.isConnected) schedule(); });
    measure();
  };

  // P12 progressively opts into the existing tab controller; SSR keeps every group readable.
  const collectionTabs = group => {
    const nav = group.querySelector(':scope > .pc-collection-tabs__nav');
    const tabs = nav ? [...nav.children] : [], panels = [...group.querySelectorAll(':scope > .pc-collection-tabs__panel')];
    if (!tabs.length || tabs.length !== panels.length) return;
    group.dataset.pcCollectionTabsReady = '1'; group.dataset.pcCbTabs = '';
    nav.setAttribute('role','tablist');
    tabs.forEach((tab,index) => { tab.setAttribute('role','tab'); tab.setAttribute('aria-selected',String(index===0)); tab.tabIndex=index===0?0:-1; panels[index].setAttribute('role','tabpanel'); panels[index].hidden=index!==0; });
    group.classList.add('is-enhanced');
    nav.addEventListener('click',event => { if (event.target.closest('a[role="tab"]')) event.preventDefault(); });
    group.addEventListener('vc:select-item',event => {
      const item = event.target.closest?.('[data-module-item]'), panel = item?.closest('[role="tabpanel"]');
      if (!item || !item.isConnected || !group.getClientRects().length || !event.detail || event.detail.item!==item.dataset.moduleItem || !panels.includes(panel)) event.stopImmediatePropagation();
      // Valid parent/child events continue into the existing controller, which selects the panel.
    });
  };

  // P16 only: progressive product details and a real absolute deadline.
  const promotionTrio = group => {
    if (group.dataset.pcTrioReady) return;
    group.dataset.pcTrioReady = '1';
    const lifetime = new AbortController(), counterLife = new AbortController(), counters = [...group.querySelectorAll('[data-pc-trio-countdown]')];
    const on = (node,name,fn) => node.addEventListener(name,fn,{signal:lifetime.signal});
    let timer, suspended = false;
    const stop = () => { clearTimeout(timer); timer = undefined; };
    const cleanup = () => { stop(); counterLife.abort(); lifetime.abort(); };
    group.querySelectorAll('[data-pc-trio-open]').forEach(opener => {
      const card = opener.closest('.pc-promotion-trio__product');
      const panel = card?.querySelector('[data-pc-trio-panel]'), close = panel?.querySelector('[data-pc-trio-close]');
      if (!panel || !close || opener.getAttribute('aria-controls') !== panel.id) return;
      panel.hidden = true; close.hidden = false; opener.setAttribute('aria-expanded','false');
      const hide = restore => { panel.hidden = true; opener.setAttribute('aria-expanded','false'); if (restore && opener.isConnected) opener.focus({preventScroll:true}); };
      on(opener,'click',event => { if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return; event.preventDefault(); const open = panel.hidden; panel.hidden = !open; opener.setAttribute('aria-expanded',String(open)); if (open) close.focus({preventScroll:true}); });
      on(close,'click',() => hide(true));
      on(card,'keydown',event => { if (event.key === 'Escape' && !panel.hidden) { event.preventDefault(); hide(true); } });
    });
    group.classList.add('is-enhanced');
    const tick = () => {
      stop();
      if (!group.isConnected) { cleanup(); return; }
      if (suspended) return;
      let pending = false;
      counters.forEach(counter => {
        const end = Number(counter.dataset.end); if (!Number.isFinite(end)) return;
        const seconds = Math.max(0,Math.ceil((end - Date.now()) / 1000)); pending ||= seconds > 0;
        // Commit the terminal state even offscreen before releasing visibility listeners.
        if (document.hidden && seconds > 0) return;
        const values = {days:Math.floor(seconds / 86400),hours:Math.floor(seconds % 86400 / 3600),minutes:Math.floor(seconds % 3600 / 60),seconds:seconds % 60};
        Object.keys(values).forEach(part => { const output = counter.querySelector('[data-pc-trio-part="' + part + '"]'), text = String(values[part]).padStart(2,'0'); if (output && output.textContent !== text) output.textContent = text; });
        const ended = counter.parentElement.querySelector('[data-pc-trio-ended]'); if (ended) ended.hidden = seconds > 0;
      });
      if (pending) timer = setTimeout(tick,1000); else counterLife.abort();
    };
    if (counters.length) {
      window.addEventListener('pagehide',() => { suspended = true; stop(); },{signal:counterLife.signal});
      window.addEventListener('pageshow',() => { suspended = false; tick(); },{signal:counterLife.signal});
      document.addEventListener('visibilitychange',() => { if (!document.hidden && !suspended) tick(); },{signal:counterLife.signal});
      tick();
    }
  };

  // O01 selection is presentation only. Original Quick View owns every purchase request.
  const pricingCards = group => {
    if (group.dataset.pcPricingReady) return;
    const rail = group.querySelector('[data-pc-pricing-rail]'), navigation = group.querySelector('[data-pc-pricing-dots]');
    if (!rail || !navigation) return;
    const items = [...rail.children].filter(item => item.matches('[data-pc-pricing-item]'));
    const buttons = items.map(item => item.querySelector('[data-pc-pricing-select]'));
    const dots = [...navigation.querySelectorAll('[data-pc-pricing-dot]')];
    if (!items.length || buttons.some(button => !button) || dots.length !== items.length) return;
    group.dataset.pcPricingReady = '1';
    const phone = matchMedia('(max-width:767px)'), reduced = matchMedia('(prefers-reduced-motion:reduce)');
    let frame = 0;
    const select = index => {
      items.forEach((item,i) => { item.classList.toggle('is-selected',i===index); buttons[i].setAttribute('aria-pressed',String(i===index)); });
    };
    const scrollTo = index => {
      const item = items[index]; if (!item) return;
      const left = rail.scrollLeft + item.getBoundingClientRect().left - rail.getBoundingClientRect().left;
      rail.scrollTo({left,behavior:reduced.matches?'auto':'smooth'});
    };
    const paintNavigation = () => {
      frame = 0;
      const horizontal = !phone.matches || group.dataset.mobileStyle === 'scroll';
      navigation.hidden = !horizontal || rail.scrollWidth <= rail.clientWidth + 2;
      const left = rail.getBoundingClientRect().left;
      let nearest = 0, distance = Infinity;
      items.forEach((item,index) => { const gap=Math.abs(item.getBoundingClientRect().left-left); if(gap<distance){nearest=index;distance=gap;} });
      dots.forEach((dot,index) => { if(index===nearest) dot.setAttribute('aria-current','true'); else dot.removeAttribute('aria-current'); });
    };
    buttons.forEach((button,index) => {
      button.addEventListener('click',()=>select(index));
      button.addEventListener('keydown',event=>{
        const rtl = getComputedStyle(group).direction === 'rtl'; let next = index;
        if(event.key==='ArrowRight') next=(index+(rtl?-1:1)+items.length)%items.length;
        else if(event.key==='ArrowLeft') next=(index+(rtl?1:-1)+items.length)%items.length;
        else if(event.key==='Home') next=0;
        else if(event.key==='End') next=items.length-1;
        else return;
        event.preventDefault();select(next);
        if(!phone.matches||group.dataset.mobileStyle==='scroll'){buttons[next].focus({preventScroll:true});scrollTo(next);}
        else buttons[next].focus();
      });
    });
    dots.forEach((button,index)=>button.addEventListener('click',()=>scrollTo(index)));
    rail.addEventListener('scroll',()=>{if(!frame)frame=requestAnimationFrame(paintNavigation);},{passive:true});
    phone.addEventListener('change',paintNavigation);
    if(window.ResizeObserver){const observer=new ResizeObserver(paintNavigation);observer.observe(rail);}
    else window.addEventListener('resize',paintNavigation,{passive:true});
    group.addEventListener('vc:select-item',event=>{
      const item=event.target.closest?.('[data-pc-pricing-item]'),index=items.indexOf(item);
      if(index>=0&&event.detail?.item===item.dataset.moduleItem){select(index);if(!phone.matches||group.dataset.mobileStyle==='scroll')scrollTo(index);}
    });
    paintNavigation();
    if(group.dataset.loadAnimation==='1'&&!reduced.matches&&typeof group.animate==='function'){
      const reveal=()=>group.animate([{opacity:.82},{opacity:1}],{duration:220,easing:'cubic-bezier(.16,1,.3,1)'});
      if(window.IntersectionObserver){const observer=new IntersectionObserver(entries=>{if(!group.isConnected){observer.disconnect();return;}if(entries.some(entry=>entry.isIntersecting)){observer.disconnect();if(!reduced.matches)reveal();}});observer.observe(group);}
      else reveal();
    }
  };

  // O03 owns only its new marker; old product_search/min/max and existing controls are unchanged.
  const siteSearchHero = group => {
    let config;
    try { config = JSON.parse(group.dataset.pcSiteSearch); } catch { return; }
    const whitelist = ['products','collections','topics','blogs','pages','promotions','coupons'];
    if (!config || !Array.isArray(config.types) || !config.types.length || config.types.length > 7 || config.types.some(type => !whitelist.includes(type)) || new Set(config.types).size !== config.types.length) return;
    const form = group.querySelector('form'), input = form?.querySelector('[name=pc_site_q]'), typeInput = form?.querySelector('[name=pc_site_type]');
    const panel = group.querySelector('.pc-site-search__panel'), tabs = [...group.querySelectorAll('[data-pc-search-type]')], list = group.querySelector('.pc-site-search__results'), status = group.querySelector('.pc-site-search__status');
    if (!form || !input || !typeInput || !panel || !list || !status || tabs.length !== config.types.length) return;
    let endpoint;
    try { endpoint = new URL(config.url, location.href); if (endpoint.origin !== location.origin) return; } catch { return; }
    group.dataset.pcSiteSearchReady = '1';
    const lifetime = new AbortController();
    const on = (node,name,fn,options={}) => node.addEventListener(name,fn,{...options,signal:lifetime.signal});
    let type = config.types[0], generation = 0, timer, request, composing = false, active = true, silentFocus = false;
    const query = () => input.value.trim();
    const localUrl = value => { if(typeof value!=='string'||!value.trim())return '';try { const url = new URL(value, location.href); return ['http:','https:'].includes(url.protocol) && url.origin === location.origin && !url.username && !url.password ? url.href : ''; } catch { return ''; } };
    const focusInput = () => { silentFocus=true;input.focus();queueMicrotask(()=>{silentFocus=false;}); };
    const close = () => { panel.hidden = true; delete group.dataset.pcSearchOpen; input.setAttribute('aria-expanded','false'); };
    const show = () => { panel.hidden = false; group.dataset.pcSearchOpen = '1'; input.setAttribute('aria-expanded','true'); };
    const invalidate = () => { generation++; clearTimeout(timer); request?.abort(); };
    const textNode = (tag,className,text) => { const node = document.createElement(tag); node.className = className; node.textContent = text; return node; };
    const priceFragment = html => {
      const fragment = document.createDocumentFragment();
      if (typeof html !== 'string' || html.length > 4000) return fragment;
      const doc = new DOMParser().parseFromString(html,'text/html'); let count = 0;
      const walk = (node,parent) => {
        if (++count > 256) return;
        if (node.nodeType === Node.TEXT_NODE) { parent.append(document.createTextNode(node.textContent)); return; }
        if (node.nodeType !== Node.ELEMENT_NODE || !['SPAN','BDI','DEL','INS','SMALL'].includes(node.nodeName)) return;
        const element = document.createElement(node.nodeName.toLowerCase());
        const cls = node.getAttribute('class'); if (cls && /^[a-zA-Z0-9_ -]{1,200}$/.test(cls)) element.className = cls;
        if (['true','false'].includes(node.getAttribute('aria-hidden'))) element.setAttribute('aria-hidden',node.getAttribute('aria-hidden'));
        [...node.childNodes].forEach(child => walk(child,element)); parent.append(element);
      };
      [...doc.body.childNodes].forEach(node => walk(node,fragment)); return fragment;
    };
    const renderRows = rows => {
      list.replaceChildren();
      rows.forEach(row => {
        const item = document.createElement('li'), link = document.createElement('a'); link.className = 'pc-site-search__result'; link.href = localUrl(row.url);
        if (row.image && typeof row.image.src === 'string') {
          try { const url = new URL(row.image.src,location.href); if (['http:','https:'].includes(url.protocol) && !url.username && !url.password) { const image = document.createElement('img'); image.src = url.href; image.alt = typeof row.image.alt === 'string' ? row.image.alt : ''; image.width = 64; image.height = 64; image.loading = 'lazy'; link.append(image); } } catch {}
        }
        const copy = document.createElement('span'); copy.className = 'pc-site-search__result-copy';
        copy.append(textNode('span','pc-site-search__result-title',row.title));
        if (typeof row.subtitle === 'string' && row.subtitle) copy.append(textNode('span','pc-site-search__result-subtitle',row.subtitle));
        if (typeof row.priceHtml === 'string' && row.priceHtml) { const price = document.createElement('span'); price.className='pc-site-search__result-price'; price.append(priceFragment(row.priceHtml)); copy.append(price); }
        link.append(copy); item.append(link); list.append(item);
      });
    };
    const busy = () => {
      if (status.contains(document.activeElement) || list.contains(document.activeElement)) focusInput();
      list.replaceChildren(); status.replaceChildren();
      const skeleton = document.createElement('span'); skeleton.className='pc-site-search__skeleton'; skeleton.setAttribute('aria-label','Loading search results');
      skeleton.append(document.createElement('span'),document.createElement('span')); status.append(skeleton); show();
    };
    const failure = data => {
      list.replaceChildren(); status.textContent = 'Search could not be loaded. Try again or refresh this page.';
      const login = localUrl(data?.data?.login_url || '');
      if (login && data?.data?.status === 401) { status.textContent='Please sign in to search this store. '; const link=document.createElement('a');link.href=login;link.textContent='Sign in';status.append(link); }
      else { const retry=document.createElement('button');retry.type='button';retry.textContent='Retry';retry.addEventListener('click',()=>schedule(0),{once:true});status.append(retry); }
      show();
    };
    const load = async (own,phrase,picked) => {
      if (!active || !group.isConnected || own !== generation) return;
      request = new AbortController(); const controller=request; let timedOut=false;
      const deadline=setTimeout(()=>{timedOut=true;controller.abort();},10000); busy();
      const url=new URL(endpoint);url.searchParams.set('q',phrase);url.searchParams.set('type',picked);url.searchParams.set('page','1');url.searchParams.set('scope',config.types.join(','));
      try {
        const headers={Accept:'application/json'};if(typeof config.nonce==='string'&&config.nonce)headers['X-WP-Nonce']=config.nonce;
        const response=await fetch(url,{credentials:'same-origin',cache:'no-store',headers,signal:controller.signal});
        const data=await response.json();
        if (!active || !group.isConnected || own!==generation || phrase!==query() || picked!==type) return;
        if (!response.ok) { failure(data);return; }
        if (!data || data.type!==picked || data.page!==1 || !Array.isArray(data.results) || data.results.length>8 || typeof data.hasMore!=='boolean' || data.results.some(row=>!row||row.type!==picked||!Number.isSafeInteger(row.id)||row.id<1||typeof row.title!=='string'||Array.from(row.title).length>200||!localUrl(row.url))) throw new Error('invalid_result');
        status.replaceChildren();renderRows(data.results);
        if (!data.results.length) status.textContent=picked==='collections'?'No Collection':'No matching results.';
        show();
      } catch(error) {
        if (own===generation && active && group.isConnected && (error.name!=='AbortError'||timedOut)) failure(null);
      } finally {clearTimeout(deadline);}
    };
    const schedule = (delay=200) => {
      invalidate();const phrase=query(),own=generation;
      if (composing || !phrase) {status.replaceChildren();list.replaceChildren();close();return;}
      if (Array.from(phrase).length>100) {list.replaceChildren();status.textContent='Use no more than 100 characters.';show();return;}
      timer=setTimeout(()=>load(own,phrase,type),delay);
    };
    const pickType = index => {
      type=config.types[index];typeInput.value=type;
      tabs.forEach((tab,i)=>{tab.setAttribute('aria-selected',String(i===index));tab.tabIndex=i===index?0:-1;});
      tabs[index].scrollIntoView({block:'nearest',inline:'nearest',behavior:'auto'});schedule(0);
    };
    on(input,'compositionstart',()=>{composing=true;invalidate();});on(input,'compositionend',()=>{composing=false;schedule();});on(input,'input',()=>{if(!composing)schedule();});
    on(input,'focus',()=>{if(query()&&!silentFocus)schedule();});
    on(input,'keydown',event=>{if(event.key==='Escape'){event.preventDefault();invalidate();close();}else if(event.key==='ArrowDown'&&!panel.hidden){event.preventDefault();(list.querySelector('a')||tabs[config.types.indexOf(type)]).focus();}});
    tabs.forEach((tab,index)=>{on(tab,'click',()=>pickType(index));on(tab,'keydown',event=>{let next=index;if(event.key==='ArrowRight')next=(index+1)%tabs.length;else if(event.key==='ArrowLeft')next=(index-1+tabs.length)%tabs.length;else if(event.key==='Home')next=0;else if(event.key==='End')next=tabs.length-1;else return;event.preventDefault();pickType(next);tabs[next].focus();});});
    on(panel,'keydown',event=>{if(event.key==='Escape'){event.preventDefault();event.stopPropagation();invalidate();close();focusInput();}});
    on(document,'pointerdown',event=>{if(!group.isConnected){invalidate();lifetime.abort();return;}if(!group.contains(event.target)){invalidate();close();}});
    on(group,'focusout',event=>{if(!group.contains(event.relatedTarget)){invalidate();close();}});
    on(form,'submit',event=>{invalidate();typeInput.value=type;if(config.preview){event.preventDefault();status.textContent='Open the published storefront to submit this search.';show();}});
    if(config.preview)on(list,'click',event=>{if(event.target.closest('a')){event.preventDefault();status.textContent='Open the published storefront to follow search results.';show();}});
    on(window,'pagehide',()=>{active=false;invalidate();close();});on(window,'pageshow',()=>{active=true;});
  };

  // O04 manual embeds always exist in server HTML. The optional API only observes real player events.
  let youtubeAPIRequest;
  const youtubeAPI = () => {
    if (window.YT && typeof window.YT.Player === 'function') return Promise.resolve(window.YT);
    if (youtubeAPIRequest) return youtubeAPIRequest;
    youtubeAPIRequest = new Promise((resolve,reject) => {
      let settled=false,created=false,script=document.querySelector('script[src="https://www.youtube.com/iframe_api"]');
      const previous=window.onYouTubeIframeAPIReady;
      const finish=(error)=>{if(settled)return;settled=true;clearTimeout(deadline);clearInterval(poll);if(window.onYouTubeIframeAPIReady===ready)window.onYouTubeIframeAPIReady=previous;if(error){if(created)script.remove();youtubeAPIRequest=undefined;reject(error);}else resolve(window.YT);};
      const ready=(...args)=>{try{if(typeof previous==='function')previous.apply(window,args);}finally{if(window.YT&&typeof window.YT.Player==='function')finish();}};
      const poll=setInterval(()=>{if(window.YT&&typeof window.YT.Player==='function')finish();},100),deadline=setTimeout(()=>finish(new Error('api_timeout')),12000);
      window.onYouTubeIframeAPIReady=ready;
      if(!script){script=document.createElement('script');script.src='https://www.youtube.com/iframe_api';script.async=true;created=true;script.addEventListener('error',()=>finish(new Error('api_network')),{once:true});document.head.append(script);}
    });
    return youtubeAPIRequest;
  };
  const youtubeReference = group => {
    group.dataset.pcYoutubeReady='1';
    const lifecycle=new AbortController(),states=[];
    const alive=()=>group.isConnected&&!lifecycle.signal.aborted;
    group.querySelectorAll('[data-pc-youtube-card]').forEach(card=>{
      let frame=card.querySelector('iframe'),player,generation=0,deadline;
      const slot=card.querySelector('.pc-youtube__frame'),status=card.querySelector('.pc-youtube__status'),source=frame?.dataset.pcYoutubeSrc;
      if(!frame||!slot||!status||!source)return;
      let checked;try{checked=new URL(source);if(checked.origin!=='https://www.youtube-nocookie.com'||!/^\/embed\/[A-Za-z0-9_-]{11}$/.test(checked.pathname))return;}catch{return;}
      const focusBeforeClear=()=>{if(status.contains(document.activeElement))frame.focus({preventScroll:true});};
      const notice=(message,retry=true)=>{if(!alive())return;focusBeforeClear();status.textContent=message;slot.setAttribute('aria-busy','false');if(retry){const button=document.createElement('button');button.type='button';button.textContent='Reload player';button.addEventListener('click',()=>start(true),{once:true,signal:lifecycle.signal});status.append(button);}};
      const start=(replace=false)=>{
        if(!alive())return;const own=++generation,returnFocus=status.contains(document.activeElement);clearTimeout(deadline);focusBeforeClear();
        if(replace){const next=frame.cloneNode(false);try{player?.destroy();}catch{}player=undefined;slot.replaceChildren(next);frame=next;frame.src=source;if(returnFocus)frame.focus({preventScroll:true});}
        card.dataset.playerState='loading';status.textContent='Loading YouTube player…';slot.setAttribute('aria-busy','true');
        frame.addEventListener('error',()=>{if(own===generation){clearTimeout(deadline);card.dataset.playerState='network-error';notice('The player could not load. You can watch on YouTube.');}},{once:true,signal:lifecycle.signal});
        youtubeAPI().then(YT=>{
          if(!alive()||own!==generation)return;
          deadline=setTimeout(()=>{if(own===generation){card.dataset.playerState='timeout';notice('The player is taking longer than expected. You can watch on YouTube.');}},12000);
          try{player=new YT.Player(frame.id,{events:{
            onReady:()=>{if(!alive()||own!==generation)return;clearTimeout(deadline);focusBeforeClear();status.replaceChildren();slot.setAttribute('aria-busy','false');card.dataset.playerState='ready';},
            onStateChange:event=>{if(!alive()||own!==generation)return;if(event.data===1){card.dataset.playerState='playing';}else if(event.data===2){card.dataset.playerState='paused';}else if(event.data===0){card.dataset.playerState='ended';}},
            onError:event=>{if(!alive()||own!==generation)return;clearTimeout(deadline);card.dataset.playerState='error';card.dataset.playerError=String(event.data);notice(event.data===153?'The player could not identify this page. You can watch on YouTube.':'This video is unavailable in the player. You can watch on YouTube.');}
          }});}catch{notice('Player status could not be read. You can use the video controls or watch on YouTube.');}
        }).catch(()=>{if(alive()&&own===generation){card.dataset.playerState='status-unavailable';notice('Player status could not be read. You can use the video controls or watch on YouTube.');}});
      };
      states.push(()=>{generation++;clearTimeout(deadline);try{player?.destroy();}catch{}});start();
    });
    const rail=group.querySelector('.pc-youtube__list'),controls=group.querySelector('.pc-youtube__controls'),prev=controls?.querySelector('[data-pc-youtube-prev]'),next=controls?.querySelector('[data-pc-youtube-next]');
    if(rail&&controls&&prev&&next&&group.dataset.display==='single'){
      const sync=()=>{controls.hidden=rail.scrollWidth<=rail.clientWidth+2;prev.disabled=rail.scrollLeft<=1;next.disabled=rail.scrollLeft+rail.clientWidth>=rail.scrollWidth-2;};
      const scroll=direction=>{rail.scrollBy({left:direction*(rail.firstElementChild?.getBoundingClientRect().width+24||rail.clientWidth),behavior:matchMedia('(prefers-reduced-motion:reduce)').matches?'auto':'smooth'});};
      prev.addEventListener('click',()=>scroll(-1),{signal:lifecycle.signal});next.addEventListener('click',()=>scroll(1),{signal:lifecycle.signal});rail.addEventListener('scroll',sync,{passive:true,signal:lifecycle.signal});
      rail.addEventListener('keydown',event=>{if(event.target!==rail)return;if(['ArrowLeft','ArrowRight','Home','End'].includes(event.key)){event.preventDefault();if(event.key==='Home')rail.scrollTo({left:0,behavior:'auto'});else if(event.key==='End')rail.scrollTo({left:rail.scrollWidth,behavior:'auto'});else scroll(event.key==='ArrowLeft'?-1:1);}},{signal:lifecycle.signal});
      if(typeof ResizeObserver==='function'){const resize=new ResizeObserver(sync);resize.observe(rail);states.push(()=>resize.disconnect());}sync();
    }
    const removed=new MutationObserver(()=>{if(!group.isConnected){removed.disconnect();lifecycle.abort();states.forEach(stop=>stop());}});removed.observe(document.documentElement,{childList:true,subtree:true});
  };

  // O05 owns host coordination only. Native TikTok controls and real messages own playback state.
  const tiktokCards = new Set();
  let tiktokActive = null, tiktokScheduled = false;
  const tiktokReconcile = () => {
    tiktokScheduled = false;
    if (tiktokActive && !tiktokActive.visible()) { tiktokActive.stop(false); tiktokActive = null; }
    if (!tiktokActive) {
      const first = [...tiktokCards].find(card => card.autoEligible() && card.preferred());
      if (first) tiktokActive = first;
    }
    tiktokCards.forEach(card => {
      if (!card.visible()) card.stop(false);
      else card.load(card === tiktokActive && card.autoEligible() && !card.userPaused);
    });
  };
  const tiktokSchedule = () => { if (!tiktokScheduled) { tiktokScheduled = true; requestAnimationFrame(tiktokReconcile); } };
  const tiktokClaim = (card, manual) => {
    if (!card.visible()) { card.stop(false); return; }
    tiktokActive = card;
    tiktokCards.forEach(other => { if (other !== card) other.stop(other.visible()); });
    if (!manual) card.load(card.autoEligible() && !card.userPaused);
  };
  const tiktokReference = group => {
    group.dataset.pcTiktokReady = '1';
    const lifecycle = new AbortController(), reduced = matchMedia('(prefers-reduced-motion:reduce)'), narrow = matchMedia('(max-width:767px)');
    const rail = group.querySelector('.pc-tiktok__list'), elements = [...rail.querySelectorAll(':scope > [data-pc-tiktok-card]')];
    const dots = [...group.querySelectorAll('[data-pc-tiktok-dot]')], controls = group.querySelector('.pc-tiktok__controls');
    const prev = controls?.querySelector('[data-pc-tiktok-prev]'), next = controls?.querySelector('[data-pc-tiktok-next]');
    const states = [], disposals = []; let selected = 0, cleaned = false, navigation = null;
    const alive = () => group.isConnected && !lifecycle.signal.aborted;
    const inView = element => {
      if (!alive() || document.hidden || !element.getClientRects().length) return false;
      const r = element.getBoundingClientRect(), container = rail.getBoundingClientRect();
      const width = Math.min(r.right, container.right, innerWidth) - Math.max(r.left, container.left, 0);
      const height = Math.min(r.bottom, innerHeight) - Math.max(r.top, 0);
      return width >= Math.min(80, r.width * .5) && height >= Math.min(100, r.height * .2);
    };
    const behavior = () => reduced.matches ? 'auto' : 'smooth';
    const size = () => {
      if (!alive()) return;
      // Each active card may grow with real prices, long names or stacked products.
      if (narrow.matches && elements[selected]) rail.style.height = Math.ceil(elements[selected].getBoundingClientRect().height) + 'px';
      else rail.style.removeProperty('height');
    };
    let navigationFrame = 0;
    const clearNavigation = () => { if (navigationFrame) cancelAnimationFrame(navigationFrame); navigationFrame = 0; navigation = null; };
    const targetLeft = index => Math.max(0, Math.min(rail.scrollWidth - rail.clientWidth, elements[index].offsetLeft - elements[0].offsetLeft));
    const nearest = () => {
      const left = rail.getBoundingClientRect().left; let index = 0, distance = Infinity;
      elements.forEach((item, n) => { const d = Math.abs(item.getBoundingClientRect().left - left); if (d < distance) { distance = d; index = n; } });
      return index;
    };
    // A program target owns selection through intermediate smooth-scroll frames.
    // Native input and horizontal geometry changes explicitly return ownership to the gesture.
    const buttonState = (button, disabled) => {
      if (!button) return;
      if (disabled && document.activeElement === button) rail.focus({ preventScroll:true });
      button.disabled = disabled;
    };
    const sync = (natural = false) => {
      if (!alive()) return;
      const prior = selected;
      if (navigation) {
        selected = navigation.index;
        if (Math.abs(rail.scrollLeft - targetLeft(selected)) <= 1) clearNavigation();
      } else if (natural === true || gestureSelection || narrow.matches || !elements[selected] || !inView(elements[selected])) selected = nearest();
      if (prior !== selected && tiktokActive && states.includes(tiktokActive)) { tiktokActive.stop(tiktokActive.visible()); tiktokActive = null; }
      dots.forEach((dot, index) => { if (index === selected) dot.setAttribute('aria-current', 'true'); else dot.removeAttribute('aria-current'); });
      if (controls) controls.hidden = rail.scrollWidth <= rail.clientWidth + 2;
      buttonState(prev, rail.scrollLeft <= 1);
      buttonState(next, rail.scrollLeft + rail.clientWidth >= rail.scrollWidth - 2);
      size(); tiktokSchedule();
    };
    const go = index => {
      if (!elements.length) return;
      const prior = selected; selected = Math.max(0, Math.min(elements.length - 1, index));
      if (tiktokActive && states.includes(tiktokActive) && !tiktokActive.preferred()) { tiktokActive.stop(tiktokActive.visible()); tiktokActive = null; }
      clearNavigation(); gestureSelection = false; navigation = { index:selected, last:rail.scrollLeft, stalled:0 };
      rail.scrollTo({ left:targetLeft(selected), behavior:behavior() });
      sync();
      if (navigation) watchNavigation(navigation);
    };
    const interrupt = (measure = true) => {
      if (!navigation) return;
      clearNavigation();
      rail.scrollTo({ left:rail.scrollLeft, behavior:'instant' });
      if (measure && rail.getClientRects().length && !document.hidden) sync(true);
    };
    // A transient no-layout can cancel native smooth scrolling without an observer seeing it.
    // Only a pending navigation owns this progress monitor; four rendered no-progress samples
    // release a cancelled target to the measured position, never restart or navigate the player.
    const watchNavigation = pending => {
      navigationFrame = requestAnimationFrame(() => {
        if (navigation !== pending) return;
        navigationFrame = 0;
        if (!alive()) { clearNavigation(); return; }
        if (!rail.getClientRects().length || document.hidden) { needsMeasure = true; interrupt(false); return; }
        const left = rail.scrollLeft;
        if (Math.abs(left - targetLeft(pending.index)) <= 1) { sync(); return; }
        pending.stalled = Math.abs(left - pending.last) < .01 ? pending.stalled + 1 : 0;
        pending.last = left;
        if (pending.stalled >= 4) { clearNavigation(); gestureSelection = false; sync(true); return; }
        watchNavigation(pending);
      });
    };
    disposals.push(clearNavigation);
    const geometry = () => [rail.clientWidth, rail.scrollWidth, ...elements.map(item => item.offsetLeft - (elements[0]?.offsetLeft || 0))].join(':');
    let priorGeometry = geometry(), gestureSelection = false, needsMeasure = false;
    const resized = () => {
      if (!alive()) return;
      if (!rail.getClientRects().length || document.hidden) { needsMeasure = true; interrupt(false); return; }
      const nextGeometry = geometry();
      if (needsMeasure || nextGeometry !== priorGeometry) { needsMeasure = false; priorGeometry = nextGeometry; gestureSelection = false; interrupt(); sync(true); }
      else sync();
    };
    const gesture = event => {
      const region = event.target.closest?.('.pc-tiktok__products-region'), nested = region?.querySelector('.pc-tiktok__products');
      if (!narrow.matches && nested && nested.scrollWidth > nested.clientWidth + 1) return;
      interrupt(); gestureSelection = true; sync(true);
    };
    rail.addEventListener('wheel', gesture, { passive:true, signal:lifecycle.signal });
    rail.addEventListener('touchstart', gesture, { passive:true, signal:lifecycle.signal });
    rail.addEventListener('pointerdown', gesture, { passive:true, signal:lifecycle.signal });
    // Scroll is the no-timer completion path; scrollend may confirm only the current reached target.
    rail.addEventListener('scrollend', () => {
      if (!navigation) { sync(gestureSelection); gestureSelection = false; }
      else if (Math.abs(rail.scrollLeft - targetLeft(navigation.index)) <= 1) sync();
    }, { signal:lifecycle.signal });
    document.addEventListener('visibilitychange', () => { if (document.hidden) { needsMeasure = true; interrupt(false); } else resized(); }, { signal:lifecycle.signal });
    prev?.addEventListener('click', () => go(selected - 1), { signal:lifecycle.signal });
    next?.addEventListener('click', () => go(selected + 1), { signal:lifecycle.signal });
    dots.forEach(dot => dot.addEventListener('click', () => go(Number(dot.dataset.pcTiktokDot)), { signal:lifecycle.signal }));
    if (dots.length) dots[0].parentElement.hidden = false;
    rail.addEventListener('scroll', sync, { passive:true, signal:lifecycle.signal });
    rail.addEventListener('keydown', event => {
      if (event.target !== rail || !['ArrowLeft','ArrowRight','Home','End'].includes(event.key)) return;
      event.preventDefault(); go(event.key === 'Home' ? 0 : event.key === 'End' ? elements.length - 1 : selected + (event.key === 'ArrowLeft' ? -1 : 1));
    }, { signal:lifecycle.signal });
    elements.forEach((element, index) => {
      let frame = element.querySelector('iframe'), deadline, loaded = false, ready = false, auto = false, generation = 0, automaticPause = false;
      const slot = element.querySelector('.pc-tiktok__frame'), output = element.querySelector('.pc-tiktok__status'), watch = element.querySelector('.pc-tiktok__watch');
      if (!frame || !slot || !output || !watch) return;
      let source;
      try { source = new URL(frame.dataset.pcTiktokSrc); if (source.origin !== 'https://www.tiktok.com' || source.username || source.password || !/^\/player\/v1\/[1-9][0-9]{9,24}$/.test(source.pathname)) return; } catch { return; }
      const card = {
        userPaused:false, fallback:false, failed:false,
        visible:() => inView(slot), preferred:() => selected === index,
        autoEligible:() => alive() && group.dataset.context === 'public' && !reduced.matches && !card.fallback && !card.failed,
        load:wantsAuto => {
          if (!card.visible()) return;
          if (loaded) {
            if (wantsAuto && ready && !card.userPaused && element.dataset.playerState !== 'playing') play();
            return;
          }
          const url = new URL(source.origin + source.pathname);
          url.search = new URLSearchParams({ music_info:'1', description:'1', controls:'1', autoplay:wantsAuto ? '1' : '0', muted:'1', loop:group.dataset.context === 'public' && !reduced.matches && !card.fallback ? '1' : '0' }).toString();
          auto = !!wantsAuto; loaded = true; ready = false; const own = generation;
          output.textContent = card.fallback ? 'Player status is unavailable. Use the native controls or watch on TikTok.' : 'Loading TikTok player…';
          element.dataset.playerState = 'loading'; slot.setAttribute('aria-busy', 'true');
          frame.addEventListener('error', () => { if (own === generation && alive()) fail('The player could not load. You can watch on TikTok.'); }, { once:true, signal:lifecycle.signal });
          frame.src = url.href;
          if (!card.fallback) deadline = setTimeout(() => {
            if (!alive() || own !== generation || ready) return;
            card.fallback = true; pause();
            element.dataset.playerState = 'status-unavailable'; notice('Player status could not be read. Use the native controls or watch on TikTok.');
          }, 12000);
        },
        stop:() => {
          if (!loaded) return;
          pause();
        },
        retire:() => { if (loaded) pause(); replace(); },
        receive:event => {
          if (!loaded || !alive() || event.origin !== 'https://www.tiktok.com' || event.source !== frame.contentWindow) return;
          let data = event.data;
          if (typeof data === 'string') { if (data.length > 4096) return; try { data = JSON.parse(data); } catch { return; } }
          if (!data || typeof data !== 'object' || Array.isArray(data) || data['x-tiktok-player'] !== true || typeof data.type !== 'string') return;
          if (data.type === 'onPlayerReady') {
            clearTimeout(deadline); ready = true; slot.setAttribute('aria-busy', 'false');
            if (!['error','autoplay-blocked'].includes(element.dataset.playerState)) {
              focusBeforeClear(); output.replaceChildren();
              if (!['playing','paused','ended','buffering'].includes(element.dataset.playerState)) element.dataset.playerState = 'ready';
            }
            if (!card.visible() || tiktokActive !== card) card.stop();
            else if (card.autoEligible() && !card.userPaused) play();
            return;
          }
          if (data.type === 'onStateChange' && Number.isInteger(data.value) && [-1,0,1,2,3].includes(data.value)) {
            if (data.value === 1) {
              if (!card.visible()) { card.stop(false); return; }
              clearTimeout(deadline); ready = true; auto = true; automaticPause = false; card.failed = false; card.userPaused = false; tiktokClaim(card, true);
              slot.setAttribute('aria-busy', 'false'); focusBeforeClear(); output.replaceChildren(); element.dataset.playerState = 'playing';
            } else if (data.value === 2) { if (!automaticPause) card.userPaused = true; automaticPause = false; if (!['error','autoplay-blocked','status-unavailable'].includes(element.dataset.playerState)) element.dataset.playerState = 'paused'; }
            else if (data.value === 0) element.dataset.playerState = 'ended';
            else if (data.value === 3) element.dataset.playerState = 'buffering';
            return;
          }
          let code = null;
          if (data.type === 'onPlayerError' && data.value && typeof data.value === 'object' && !Array.isArray(data.value) && Number.isInteger(data.value.errorCode) && data.value.errorCode >= 0 && data.value.errorCode <= 9999) code = data.value.errorCode;
          if (data.type === 'onError' && Number.isInteger(data.value) && data.value >= 1 && data.value <= 4) code = data.value;
          if (code !== null) {
            clearTimeout(deadline); element.dataset.playerError = String(code);
            if (code === 3002) { card.fallback = true; pause(); element.dataset.playerState = 'autoplay-blocked'; notice('Automatic playback was blocked. Use the native play control or watch on TikTok.'); }
            else fail('This video is unavailable in the player. You can watch on TikTok.');
          }
        }
      };
      function focusBeforeClear() { if (output.contains(document.activeElement)) frame.focus({ preventScroll:true }); }
      function pause() { automaticPause = true; try { frame.contentWindow?.postMessage({ type:'pause', 'x-tiktok-player':true }, 'https://www.tiktok.com'); } catch {} }
      function play() { automaticPause = false; try { frame.contentWindow?.postMessage({ type:'play', 'x-tiktok-player':true }, 'https://www.tiktok.com'); } catch {} }
      function replace() {
        clearTimeout(deadline); const nextFrame = frame.cloneNode(false); nextFrame.removeAttribute('src');
        const focused = document.activeElement === frame || output.contains(document.activeElement);
        // Move focus before retiring an active browsing context or its status controls.
        // Retry explicitly returns to the new player after it has been created.
        if (focused && rail.isConnected) rail.focus({ preventScroll:true });
        frame.replaceWith(nextFrame); frame = nextFrame; generation++; loaded = false; ready = false; auto = false;
        output.replaceChildren(); slot.setAttribute('aria-busy', 'false'); element.dataset.playerState = 'idle';
      }
      function notice(message) {
        if (!alive()) return;
        focusBeforeClear(); output.textContent = message + ' '; slot.setAttribute('aria-busy', 'false');
        const link = document.createElement('a'); link.href = watch.href; link.textContent = 'Watch on TikTok'; link.target = '_blank'; link.rel = 'noopener noreferrer'; output.append(link);
        const retry = document.createElement('button'); retry.type = 'button'; retry.textContent = 'Reload player';
        retry.addEventListener('click', () => {
          const focused = output.contains(document.activeElement); pause(); replace(); card.failed = false; card.fallback = false; card.userPaused = false;
          if (focused) frame.focus({ preventScroll:true });
          if (card.visible()) { tiktokClaim(card, false); card.load(card === tiktokActive && card.autoEligible()); }
        }, { once:true, signal:lifecycle.signal }); output.append(retry);
      }
      function fail(message) { clearTimeout(deadline); card.failed = true; pause(); element.dataset.playerState = 'error'; notice(message); }
      states.push(card); tiktokCards.add(card);
      disposals.push(() => { card.retire(); tiktokCards.delete(card); if (tiktokActive === card) tiktokActive = null; });
      const productRail = element.querySelector('.pc-tiktok__products'), productControls = element.querySelector('.pc-tiktok__product-controls');
      if (productRail && productControls) {
        const previous = productControls.querySelector('[data-pc-tiktok-product-prev]'), following = productControls.querySelector('[data-pc-tiktok-product-next]');
        const productSync = () => { productControls.hidden = narrow.matches || productRail.scrollWidth <= productRail.clientWidth + 2; previous.disabled = productRail.scrollLeft <= 1; following.disabled = productRail.scrollLeft + productRail.clientWidth >= productRail.scrollWidth - 2; size(); };
        const productGo = direction => productRail.scrollBy({ left:direction * productRail.clientWidth, behavior:behavior() });
        previous.addEventListener('click', () => productGo(-1), { signal:lifecycle.signal }); following.addEventListener('click', () => productGo(1), { signal:lifecycle.signal });
        productRail.addEventListener('scroll', productSync, { passive:true, signal:lifecycle.signal });
        productRail.addEventListener('keydown', event => {
          if (event.target !== productRail || narrow.matches || !['ArrowLeft','ArrowRight','Home','End'].includes(event.key)) return;
          event.preventDefault();
          if (event.key === 'Home' || event.key === 'End') productRail.scrollTo({ left:event.key === 'Home' ? 0 : productRail.scrollWidth, behavior:behavior() });
          else productGo(event.key === 'ArrowLeft' ? -1 : 1);
        }, { signal:lifecycle.signal });
        if (typeof ResizeObserver === 'function') { const observer = new ResizeObserver(productSync); observer.observe(productRail); disposals.push(() => observer.disconnect()); }
        productSync();
      }
    });
    window.addEventListener('message', event => states.forEach(card => card.receive(event)), { signal:lifecycle.signal });
    window.addEventListener('scroll', tiktokSchedule, { passive:true, signal:lifecycle.signal });
    document.addEventListener('visibilitychange', tiktokSchedule, { signal:lifecycle.signal });
    // The provider has no loop setter. Only an explicit reduced-motion policy change,
    // Retry or module removal retires frames; ordinary resize/activation preserves them.
    const policyChange = () => { if (navigation) rail.scrollTo({ left:targetLeft(navigation.index), behavior:'instant' }); states.forEach(card => { card.retire(); card.userPaused = false; }); if (states.includes(tiktokActive)) tiktokActive = null; sync(); };
    reduced.addEventListener('change', policyChange); narrow.addEventListener('change', resized);
    disposals.push(() => { reduced.removeEventListener('change', policyChange); narrow.removeEventListener('change', resized); });
    if (typeof IntersectionObserver === 'function') { const observer = new IntersectionObserver(tiktokSchedule, { threshold:[0,.2,.5] }); elements.forEach(item => observer.observe(item)); disposals.push(() => observer.disconnect()); }
    if (typeof ResizeObserver === 'function') { const observer = new ResizeObserver(resized); observer.observe(rail); elements.forEach(item => observer.observe(item)); disposals.push(() => observer.disconnect()); }
    const removed = new MutationObserver(() => { if (!group.isConnected && !cleaned) { cleaned = true; removed.disconnect(); lifecycle.abort(); disposals.forEach(stop => stop()); tiktokSchedule(); } });
    removed.observe(document.documentElement, { childList:true, subtree:true }); sync();
  };

  const init = (root = document) => {
    root.querySelectorAll('[data-pc-site-search]:not([data-pc-site-search-ready])').forEach(siteSearchHero);
    root.querySelectorAll('[data-pc-youtube]:not([data-pc-youtube-ready])').forEach(youtubeReference);
    root.querySelectorAll('[data-pc-tiktok]:not([data-pc-tiktok-ready])').forEach(tiktokReference);
    root.querySelectorAll('[data-pc-pricing]:not([data-pc-pricing-ready])').forEach(pricingCards);
    root.querySelectorAll('[data-pc-promotion-trio]:not([data-pc-trio-ready])').forEach(promotionTrio);
    root.querySelectorAll('[data-pc-collection-tabs]:not([data-pc-collection-tabs-ready])').forEach(collectionTabs);

    root.querySelectorAll('[data-pc-collection-products]:not([data-pc-collection-products-ready])').forEach(collectionProducts);

    root.querySelectorAll('[data-pc-product-scroll]:not([data-pc-scroll-ready])').forEach(productScroll);
    root.querySelectorAll('[data-pc-collection-navigation]:not([data-pc-navigation-ready])').forEach(collectionNavigation);

    root.querySelectorAll('[data-pc-video-products]:not([data-pc-video-ready])').forEach(videoProducts);
    root.querySelectorAll('.pc-merch-card:not([data-pc-merch-ready])').forEach(item => {
      item.dataset.pcMerchReady='1';
      const visual=item.querySelector('.product-visual'),quick=item.querySelector(':scope > .pc-merch-card__quick');
      if(visual&&quick){const holder=document.createElement('div');holder.className='pc-merch-card__visual';visual.before(holder);holder.append(visual,quick);}
    });
    root.querySelectorAll('[data-pc-collection-list]:not([data-pc-collections-ready])').forEach(group => {
      const rail=group.querySelector('[data-pc-collection-rail]'),dots=group.querySelector('[data-pc-collection-dots]');if(!rail||!dots)return;
      const items=[...rail.children],media=matchMedia('(max-width:767px)');let count=-1,frame;
      group.dataset.pcCollectionsReady='1';
      const move=index=>{const item=items[Math.max(0,Math.min(items.length-1,index))];if(item)rail.scrollTo({left:item.offsetLeft-items[0].offsetLeft,behavior:matchMedia('(prefers-reduced-motion:reduce)').matches?'auto':'smooth'});};
      const sync=()=>{
        const per=Math.max(1,Math.min(8,parseInt(getComputedStyle(group).getPropertyValue('--pc-collections-cols-m'),10)||3));
        const next=media.matches?Math.max(1,items.length-per+1):0;
        if(next!==count){count=next;dots.replaceChildren();for(let i=0;i<count;i++){const b=document.createElement('button');b.type='button';b.setAttribute('aria-label','Show collections from position '+(i+1));b.addEventListener('click',()=>move(i));dots.append(b);}}
        dots.hidden=count<2;
        const step=items.length>1?Math.abs(items[1].offsetLeft-items[0].offsetLeft):1;
        const current=Math.max(0,Math.min(count-1,Math.round(Math.abs(rail.scrollLeft)/Math.max(1,step))));
        [...dots.children].forEach((b,i)=>b.setAttribute('aria-current',String(i===current)));
      };
      const schedule=()=>{cancelAnimationFrame(frame);frame=requestAnimationFrame(sync);};
      rail.addEventListener('scroll',schedule,{passive:true});window.addEventListener('resize',schedule,{passive:true});
      if(window.ResizeObserver)new ResizeObserver(schedule).observe(rail);
      group.addEventListener('vc:select-item',event=>{const item=event.target.closest('[data-module-item]');if(!item||item.parentElement!==rail||event.detail?.item!==item.dataset.moduleItem)return;if(media.matches)move(items.indexOf(item));event.preventDefault();});
      sync();
    });
    root.querySelectorAll('[data-pc-recent-history]:not([data-pc-recent-ready])').forEach(group => {
      const list=group.querySelector('[data-pc-recent-list]');
      if(!list||!window.fetch||!window.AbortController)return;
      let endpoint;try{endpoint=new URL(group.dataset.endpoint,location.href);if(endpoint.origin!==location.origin||!/^https?:$/.test(endpoint.protocol))return;}catch(_){return;}
      const total=Number(group.dataset.total);if(!Number.isInteger(total)||total<1||total>100)return;
      group.dataset.pcRecentReady='1';let controller,sequence=0,active=true;
      const clear=()=>{group.hidden=true;list.replaceChildren();};
      const load=async()=>{
        const own=++sequence;controller?.abort();controller=new AbortController();const request=controller;
        const timer=setTimeout(()=>request.abort(),20000);clear();
        try{
          const url=new URL(endpoint);url.searchParams.set('action','pc_recent_history');url.searchParams.set('total',String(total));
          const response=await fetch(url,{method:'GET',credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'},signal:request.signal});const result=await response.json();
          if(!active||own!==sequence||!group.isConnected)return;
          if(!response.ok||result?.success!==true||typeof result.data?.html!=='string'||!Number.isInteger(result.data.count)||result.data.count<0||result.data.count>total)throw new Error('unavailable');
          const fragment=document.createElement('template');fragment.innerHTML=result.data.html;const rows=[...fragment.content.children];
          if(rows.length!==result.data.count||rows.some(node=>!node.matches('.pc-merch-card[data-pc-merch-product]')||!/^[1-9]\d*$/.test(node.dataset.pcMerchProduct)))throw new Error('invalid_history');
          if(new Set(rows.map(node=>node.dataset.pcMerchProduct)).size!==rows.length)throw new Error('invalid_history');
          list.replaceChildren(...rows);group.hidden=rows.length===0;
        }catch(_){if(own===sequence)clear();}
        finally{clearTimeout(timer);}
      };
      window.addEventListener('pagehide',()=>{active=false;sequence++;controller?.abort();clear();});
      window.addEventListener('pageshow',event=>{if(event.persisted){active=true;load();}});
      load();
    });
    root.querySelectorAll('[data-pc-ai-waterfall]:not([data-pc-ai-waterfall-ready])').forEach(aiProductWaterfall);
    root.querySelectorAll('[data-pc-behavior-ranking]:not([data-pc-behavior-ranking-ready])').forEach(behaviorRanking);
    root.querySelectorAll('[data-pc-waterfall]:not([data-pc-waterfall-ready])').forEach(group => {
      const list = group.querySelector('[data-pc-waterfall-list]'), sentinel = group.querySelector('[data-pc-waterfall-sentinel]');
      const status = group.querySelector('[data-pc-waterfall-status]'), retry = group.querySelector('[data-pc-waterfall-retry]'), more = group.querySelector('[data-pc-waterfall-more]');
      if (!list || !sentinel || !status || !retry || !more || !window.fetch || !window.AbortController) return;
      const token = group.dataset.context;
      let endpoint;
      try { endpoint = new URL(group.dataset.endpoint, location.href); if (endpoint.origin !== location.origin || !/^https?:$/.test(endpoint.protocol)) return; } catch (_) { return; }
      const media = matchMedia('(max-width:767px)'), fixed = group.dataset.device !== 'all';
      const first = [...list.children].filter(node => node.hasAttribute('data-pc-waterfall-product'));
      const makeState = items => ({items, page:1, hasMore:group.dataset.hasMore === '1', failed:false, busy:false, request:0});
      const states = {desktop:makeState(first), mobile:makeState(first.map(node => node.cloneNode(true)))};
      let key = fixed ? (group.dataset.device === 'mobile' ? 'mobile' : 'desktop') : (media.matches ? 'mobile' : 'desktop');
      if (key === 'mobile') { states.mobile.items = first; states.desktop.items = first.map(node => node.cloneNode(true)); }
      let state = states[key], epoch = 0, controller, frame, observer, active = true;
      const visible = () => active && group.isConnected && group.getClientRects().length && getComputedStyle(group).visibility !== 'hidden';
      const current = (own, generation) => visible() && state === own && generation === epoch && group.dataset.context === token;
      const enhance = item => {
        const visual = item.querySelector('.product-visual'), quick = item.querySelector(':scope > .pc-waterfall__quick');
        if (visual && quick && !visual.parentElement.classList.contains('pc-waterfall__visual')) {
          const holder = document.createElement('div'); holder.className = 'pc-waterfall__visual'; visual.before(holder); holder.append(visual, quick);
        }
      };
      const paint = () => {
        more.hidden = state.hasMore; retry.hidden = !state.failed;
        list.setAttribute('aria-busy', String(state.busy));
        status.textContent = state.busy ? 'Loading products…' : state.failed ? 'Products could not be loaded. Try again or open the collection.' : '';
        if (state.failed) more.hidden = false;
      };
      const layout = () => {
        const focused = group.contains(document.activeElement) ? document.activeElement : null;
        const mobile = fixed ? key === 'mobile' : media.matches;
        const columns = Math.max(1, Math.min(6, parseInt(getComputedStyle(group).getPropertyValue(mobile ? '--pc-waterfall-cols-m' : '--pc-waterfall-cols'),10) || 2));
        const holders = Array.from({length:columns}, () => {const node=document.createElement('div');node.className='pc-waterfall__column';return node;});
        state.items.forEach((item,index) => { enhance(item); holders[index % columns].append(item); });
        list.replaceChildren(...holders);
        if (focused?.isConnected && document.activeElement !== focused) focused.focus({preventScroll:true});
        paint();
      };
      const check = () => {
        if (!visible() || document.hidden || !state.hasMore || state.busy || state.failed) return;
        const box = sentinel.getBoundingClientRect();
        if (box.top <= innerHeight + 80 && box.bottom >= 0) load();
      };
      const schedule = () => { cancelAnimationFrame(frame); frame = requestAnimationFrame(check); };
      const load = async () => {
        if (!visible() || !state.hasMore || state.busy) return;
        const own = state, generation = ++epoch, page = own.page + 1;
        controller = new AbortController(); const request = controller;
        const timer = setTimeout(() => request.abort(), 20000);
        own.busy = true; own.request = generation; own.failed = false; paint();
        try {
          const url = new URL(endpoint); url.searchParams.set('action','pc_product_waterfall_more'); url.searchParams.set('context',token); url.searchParams.set('page',String(page));
          const response = await fetch(url, {method:'GET',credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'},signal:request.signal});
          const result = await response.json();
          if (!current(own,generation)) return;
          if (!response.ok || result?.success !== true || result.data?.page !== page || typeof result.data.html !== 'string' || typeof result.data.hasMore !== 'boolean') throw new Error('load_failed');
          const fragment = document.createElement('template'); fragment.innerHTML = result.data.html;
          const rows = [...fragment.content.children];
          if (rows.length > 50 || rows.some(node => !node.matches('.pc-waterfall__item[data-pc-waterfall-product]') || !/^[1-9]\d*$/.test(node.dataset.pcWaterfallProduct))) throw new Error('invalid_products');
          const known = new Set(own.items.map(node => node.dataset.pcWaterfallProduct));
          rows.forEach(node => { if (!known.has(node.dataset.pcWaterfallProduct)) {known.add(node.dataset.pcWaterfallProduct);own.items.push(node);} });
          own.page = page; own.hasMore = result.data.hasMore; own.failed = false; layout();
        } catch (_) { if (current(own,generation)) own.failed = true; }
        finally { clearTimeout(timer); if (own.request === generation) own.busy = false; if (state === own && generation === epoch) {paint();schedule();} }
      };
      group.dataset.pcWaterfallReady = '1';
      retry.addEventListener('click', () => {state.failed=false;load();});
      const change = () => {
        if (fixed) {schedule();return;}
        const next = media.matches ? 'mobile' : 'desktop'; if (next === key) {layout();return;}
        epoch++; controller?.abort(); state.busy=false; key=next;state=states[key];layout();schedule();
      };
      if (media.addEventListener) media.addEventListener('change',change);
      window.addEventListener('scroll',schedule,{passive:true}); window.addEventListener('resize',schedule,{passive:true});
      document.addEventListener('visibilitychange',schedule);
      window.addEventListener('pagehide',() => {active=false;epoch++;controller?.abort();state.busy=false;observer?.disconnect();});
      window.addEventListener('pageshow',() => {active=true;if(observer)observer.observe(sentinel);paint();schedule();});
      if (window.IntersectionObserver) {observer=new IntersectionObserver(schedule,{rootMargin:'80px 0px'});observer.observe(sentinel);}
      layout();schedule();
    });
    root.querySelectorAll('[data-pc-featured-gallery]:not([data-pc-featured-ready])').forEach(gallery => {
      gallery.dataset.pcFeaturedReady = '1';
      const rail = gallery.querySelector('.pc-featured__images');
      const pictures = [...gallery.querySelectorAll('.pc-featured__picture')];
      const buttons = [...gallery.querySelectorAll('[data-pc-featured-image]')];
      if (!rail || !pictures.length) return;
      buttons.forEach(button => { button.disabled = false; });
      let frame;
      const activeIndex = () => {
        const box = rail.getBoundingClientRect();
        return pictures.reduce((best, picture, index) => Math.abs(picture.getBoundingClientRect().left - box.left) < Math.abs(pictures[best].getBoundingClientRect().left - box.left) ? index : best, 0);
      };
      const sync = () => { const current = activeIndex(); buttons.forEach((button, index) => button.setAttribute('aria-current', String(index === current))); };
      const move = index => {
        const picture = pictures[Math.max(0, Math.min(pictures.length - 1, index))];
        const difference = picture.getBoundingClientRect().left - rail.getBoundingClientRect().left;
        rail.scrollBy({left: difference, behavior: matchMedia('(prefers-reduced-motion:reduce)').matches ? 'auto' : 'smooth'});
      };
      buttons.forEach((button, index) => {
        button.addEventListener('click', () => move(index));
        button.addEventListener('keydown', event => {
          const rtl = getComputedStyle(gallery).direction === 'rtl';
          let next = index;
          if (event.key === 'ArrowLeft') next += rtl ? 1 : -1;
          else if (event.key === 'ArrowRight') next += rtl ? -1 : 1;
          else if (event.key === 'Home') next = 0;
          else if (event.key === 'End') next = buttons.length - 1;
          else return;
          event.preventDefault(); next = Math.max(0, Math.min(buttons.length - 1, next)); buttons[next].focus(); move(next);
        });
      });
      rail.addEventListener('scroll', () => { cancelAnimationFrame(frame); frame = requestAnimationFrame(sync); }, {passive:true});
      if (window.ResizeObserver) new ResizeObserver(sync).observe(rail);
      sync();
    });
    root.querySelectorAll('[data-pc-cb-tabs]:not([data-pc-ready])').forEach(group => {
      group.dataset.pcReady = '1';
      const tabs = [...group.querySelectorAll(':scope > [role=tablist] > [role=tab]')];
      const select = tab => {
        tabs.forEach(item => {
          const active = item === tab;
          item.setAttribute('aria-selected', String(active));
          item.tabIndex = active ? 0 : -1;
          const panel = document.getElementById(item.getAttribute('aria-controls'));
          if (panel) panel.hidden = !active;
        });
      };
      tabs.forEach((tab, i) => {
        tab.addEventListener('click', () => select(tab));
        tab.addEventListener('pointerenter', event => { if (group.dataset.switchMode === 'hover' && event.pointerType !== 'touch') select(tab); });
        tab.addEventListener('keydown', event => {
          let next = i;
          if (event.key === 'ArrowRight') next = (i + 1) % tabs.length;
          else if (event.key === 'ArrowLeft') next = (i + tabs.length - 1) % tabs.length;
          else if (event.key === 'Home') next = 0;
          else if (event.key === 'End') next = tabs.length - 1;
          else return;
          event.preventDefault(); select(tabs[next]); tabs[next].focus();
        });
      });
      group.addEventListener('vc:select-item', event => {
        const panel = event.target.closest('[role=tabpanel]');
        const tab = panel && tabs.find(item => item.getAttribute('aria-controls') === panel.id);
        if (tab) { select(tab); event.preventDefault(); }
      });
    });
    root.querySelectorAll('[data-pc-cb-rail]:not([data-pc-ready])').forEach(group => {
      group.dataset.pcReady = '1';
      const rail = group.querySelector('.pc-cb-rail');
      if (!rail) return;
      const reducedMotion = () => matchMedia('(prefers-reduced-motion: reduce)').matches;
      const move = direction => {
        const max = rail.scrollWidth - rail.clientWidth;
        const behavior = reducedMotion() ? 'auto' : 'smooth';
        if (direction > 0 && rail.scrollLeft >= max - 2) rail.scrollTo({ left: 0, behavior });
        else rail.scrollBy({ left: direction * rail.clientWidth, behavior });
      };
      group.querySelector('[data-pc-cb-prev]')?.addEventListener('click', () => move(-1));
      group.querySelector('[data-pc-cb-next]')?.addEventListener('click', () => move(1));
      let userPaused = false;
      group.addEventListener('vc:select-item', event => {
        const item = event.target.closest('[data-module-item]');
        if (!item || !rail.contains(item)) return;
        userPaused = true;
        rail.scrollTo({ left: item.offsetLeft - rail.offsetLeft, behavior: 'instant' });
        event.preventDefault();
      });
      const toggle = group.querySelector('[data-pc-cb-toggle]');
      toggle?.addEventListener('click', () => {
        userPaused = !userPaused; toggle.setAttribute('aria-pressed', String(userPaused));
        toggle.textContent = userPaused ? toggle.dataset.play : toggle.dataset.pause;
      });
      if (group.dataset.autoplay === '1' && !reducedMotion()) {
        let hoverPaused = false;
        let focusPaused = false;
        group.addEventListener('mouseenter', () => { hoverPaused = true; });
        group.addEventListener('mouseleave', () => { hoverPaused = false; });
        group.addEventListener('focusin', () => { focusPaused = true; });
        group.addEventListener('focusout', event => { focusPaused = group.contains(event.relatedTarget); });
        const timer = setInterval(() => {
          if (!group.isConnected) { clearInterval(timer); return; }
          if (!hoverPaused && !focusPaused && !userPaused && !document.hidden && !reducedMotion()) move(1);
        }, Math.max(2000, Math.min(20000, Number(group.dataset.interval) || 5000)));
      }
    });
    root.querySelectorAll('[data-pc-cb-countdown]:not([data-pc-ready])').forEach(output => {
      output.dataset.pcReady = '1';
      const update = () => {
        const seconds = Math.max(0, Math.floor(Number(output.dataset.pcCbCountdown) - Date.now() / 1000));
        if (!seconds) { output.textContent = output.dataset.ended; return false; }
        const days = Math.floor(seconds / 86400);
        const clock = [Math.floor(seconds / 3600) % 24, Math.floor(seconds / 60) % 60, seconds % 60].map(n => String(n).padStart(2, '0')).join(':');
        output.textContent = (days ? days + 'd ' : '') + clock; return true;
      };
      if (update()) { const timer = setInterval(() => { if (!output.isConnected || !update()) clearInterval(timer); }, 1000); }
    });
    root.querySelectorAll('[data-pc-campaign-countdown]:not([data-pc-ready])').forEach(group => {
      group.dataset.pcReady = '1';
      const label = group.querySelector('.pc-campaign__countdown-label');
      const output = group.querySelector('.pc-campaign__clock');
      const campaign = group.closest('[data-pc-campaign]');
      if (!label || !output) return;
      const update = () => {
        const now = Math.floor(Date.now() / 1000), start = Number(group.dataset.start) || 0, end = Number(group.dataset.end) || 0;
        const upcoming = start > now, deadline = upcoming ? start : end;
        if (campaign) campaign.dataset.state = upcoming ? 'upcoming' : (end && end <= now ? 'ended' : 'active');
        if (end && !upcoming && end <= now) {
          label.textContent = group.dataset.endedLabel || 'Offer ended'; output.hidden = true; return false;
        }
        if (!deadline) { group.hidden = true; return false; }
        group.hidden = false;
        const deadlineLabel = new Date(deadline * 1000).toLocaleString();
        if (output.getAttribute('aria-label') !== deadlineLabel) output.setAttribute('aria-label', deadlineLabel);
        const text = upcoming ? group.dataset.upcomingLabel : group.dataset.activeLabel;
        if (label.textContent !== text) label.textContent = text || '';
        const seconds = Math.max(0, deadline - now);
        const parts = group.dataset.format === 'hms'
          ? [Math.floor(seconds / 3600), Math.floor(seconds / 60) % 60, seconds % 60]
          : [Math.floor(seconds / 86400), Math.floor(seconds / 3600) % 24, Math.floor(seconds / 60) % 60, seconds % 60];
        let digits = [...output.querySelectorAll('.pc-campaign__digit')];
        if (digits.length !== parts.length) {
          output.replaceChildren();
          parts.forEach((part, index) => {
            if (index) { const separator = document.createElement('span'); separator.className = 'pc-campaign__separator'; separator.setAttribute('aria-hidden', 'true'); separator.textContent = ':'; output.append(separator); }
            const digit = document.createElement('span'); digit.className = 'pc-campaign__digit'; output.append(digit);
          });
          digits = [...output.querySelectorAll('.pc-campaign__digit')];
        }
        parts.forEach((part, index) => { const value = String(part).padStart(2, '0'); if (digits[index].textContent !== value) digits[index].textContent = value; });
        return true;
      };
      if (update()) { const timer = setInterval(() => { if (!group.isConnected || !update()) clearInterval(timer); }, 1000); }
    });
    root.querySelectorAll('[data-pc-campaign-rail]:not([data-pc-ready])').forEach(group => {
      group.dataset.pcReady = '1';
      const rail = group.querySelector('.pc-campaign__tiers');
      const controls = group.querySelector('.pc-campaign__rail-controls');
      if (!rail || !controls || !rail.children.length) return;
      const cards = [...rail.children];
      const dots = [...group.querySelectorAll('[data-pc-campaign-slide]')];
      const previous = group.querySelector('[data-pc-campaign-prev]'), next = group.querySelector('[data-pc-campaign-next]');
      if (!previous || !next) return;
      const mobile = matchMedia('(max-width:767px)'), reduced = matchMedia('(prefers-reduced-motion:reduce)');
      let selected = 0;
      const carousel = () => (mobile.matches ? group.dataset.mobileLayout : group.dataset.desktopLayout) === 'carousel';
      const indexAt = () => cards.reduce((best, card, index) => Math.abs(card.offsetLeft - cards[0].offsetLeft - rail.scrollLeft) < Math.abs(cards[best].offsetLeft - cards[0].offsetLeft - rail.scrollLeft) ? index : best, 0);
      const sync = () => {
        const overflow = rail.scrollWidth > rail.clientWidth + 2;
        controls.hidden = !carousel() || !overflow;
        selected = indexAt();
        dots.forEach((dot, index) => dot.setAttribute('aria-current', String(index === selected)));
        previous.disabled = rail.scrollLeft <= 2;
        next.disabled = rail.scrollLeft >= rail.scrollWidth - rail.clientWidth - 2;
      };
      const moveTo = index => {
        if (!carousel() || !cards.length) return;
        const target = Math.max(0, Math.min(cards.length - 1, index));
        rail.scrollTo({left:cards[target].offsetLeft - cards[0].offsetLeft,behavior:reduced.matches ? 'auto' : 'smooth'});
      };
      previous.addEventListener('click', () => moveTo(selected - 1));
      next.addEventListener('click', () => moveTo(selected + 1));
      dots.forEach((dot, index) => dot.addEventListener('click', () => moveTo(index)));
      rail.addEventListener('scroll', sync, {passive:true});
      rail.addEventListener('keydown', event => {
        if (!carousel() || !['ArrowLeft','ArrowRight','Home','End'].includes(event.key)) return;
        event.preventDefault(); moveTo(event.key === 'Home' ? 0 : event.key === 'End' ? cards.length - 1 : selected + (event.key === 'ArrowRight' ? 1 : -1));
      });
      rail.tabIndex = 0;
      rail.setAttribute('aria-label', 'Promotion offers');
      if (typeof ResizeObserver === 'function') { const observer = new ResizeObserver(() => { if (!group.isConnected) observer.disconnect(); else sync(); }); observer.observe(rail); }
      mobile.addEventListener('change', () => { rail.scrollLeft = 0; sync(); });
      sync();
    });
    root.querySelectorAll('[data-pc-campaign-effect]:not([data-pc-ready])').forEach(button => {
      button.dataset.pcReady = '1';
      const effect = button.dataset.pcCampaignEffect;
      if (!['bounce','rubberband','shake','swing','wobble','pulse','shine'].includes(effect)) return;
      const duration = Math.max(1000, Math.min(5000, Number(button.dataset.effectDuration) || 1000));
      const interval = Math.max(500, Math.min(5000, Number(button.dataset.effectInterval) || 1500));
      const reduced = matchMedia('(prefers-reduced-motion:reduce)');
      let timer, visible = true;
      const stopInvisible = () => { if(document.hidden || reduced.matches) button.classList.remove('is-animating'); };
      document.addEventListener('visibilitychange',stopInvisible);reduced.addEventListener('change',stopInvisible);
      const observer = typeof IntersectionObserver === 'function' ? new IntersectionObserver(entries => { visible = entries[0].isIntersecting; }) : null;
      if (observer) observer.observe(button);
      const run = () => {
        if (!button.isConnected) { clearTimeout(timer); observer?.disconnect();document.removeEventListener('visibilitychange',stopInvisible);reduced.removeEventListener('change',stopInvisible);return; }
        if (reduced.matches || document.hidden || !visible) { button.classList.remove('is-animating'); timer = setTimeout(run, interval); return; }
        button.classList.add('is-animating');
        timer = setTimeout(() => { button.classList.remove('is-animating'); timer = setTimeout(run, interval); }, duration);
      };
      timer = setTimeout(run, interval);
    });
    root.querySelectorAll('form.pc-cb-search:not([data-pc-ready])').forEach(form => {
      form.dataset.pcReady = '1';
      form.addEventListener('formdata', event => {
        form.querySelectorAll('[name=min_price],[name=max_price]').forEach(input => { if (input.value.trim() === '') event.formData.delete(input.name); });
      });
    });
    root.querySelectorAll('[data-pc-cb-copy]:not([data-pc-ready])').forEach(button => {
      button.dataset.pcReady = '1';
      button.addEventListener('click', async () => {
        if (button.disabled) return;
        const feedback = button.closest('[data-pc-coupon]')?.querySelector('[data-pc-copy-feedback]');
        let copied = false;
        try { if (navigator.clipboard?.writeText) { await navigator.clipboard.writeText(button.dataset.pcCbCopy); copied = true; } } catch (_) {}
        if (!copied) {
          const active = document.activeElement, input = document.createElement('textarea');
          input.value = button.dataset.pcCbCopy; input.readOnly = true; input.setAttribute('aria-hidden', 'true');
          Object.assign(input.style, { position: 'fixed', left: '-9999px', top: '0' });
          document.body.appendChild(input);
          try { input.select(); copied = document.execCommand('copy'); } catch (_) {} finally { input.remove(); active?.focus({ preventScroll: true }); }
        }
        if (copied) {
          if (feedback) { feedback.textContent = button.dataset.copied || 'Code copied'; button.dataset.copyState = 'copied'; }
          else button.textContent = button.dataset.copied;
        } else {
          const code = button.closest('article')?.querySelector('code');
          if (code) { const range = document.createRange(); range.selectNodeContents(code); const selection = getSelection(); selection.removeAllRanges(); selection.addRange(range); }
          const message = button.dataset.copyFallback || 'Select and copy the code';
          if (feedback) { feedback.textContent = message; button.dataset.copyState = 'failed'; }
          else button.textContent = message;
        }
      });
    });
    root.querySelectorAll('[data-pc-coupon-expiry]:not([data-pc-expiry-ready])').forEach(coupon => {
      coupon.dataset.pcExpiryReady = '1';
      const expiry = Number(coupon.dataset.pcCouponExpiry);
      if (!expiry) return;
      const update = () => {
        if (!coupon.isConnected) return false;
        if (Date.now() < expiry * 1000) return true;
        coupon.classList.add('is-expired');
        const copy = coupon.querySelector('[data-pc-cb-copy]'), feedback = coupon.querySelector('[data-pc-copy-feedback]');
        if (copy) copy.disabled = true;
        if (feedback) feedback.textContent = 'This coupon has expired.';
        return false;
      };
      if (update()) { const timer = setInterval(() => { if (!update()) clearInterval(timer); }, 1000); }
    });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => init()); else init();
  new MutationObserver(records => { if (records.some(record => [...record.addedNodes].some(node => node.nodeType === 1))) init(); }).observe(document.documentElement, { childList: true, subtree: true });
})();

// O06 owns its instances and decorative tracks. Existing commerce controllers are unchanged.
(() => {
  'use strict';
  const instances = new Map();
  let suspended = false;
  const create = group => {
    if (instances.has(group)) return;
    const viewport = group.querySelector('.pc-partners__viewport'), list = group.querySelector('.pc-partners__list');
    if (!viewport || !list) return;
    const items = [...list.children], controls = group.querySelector('.pc-partners__controls');
    const previous = group.querySelector('[data-pc-partners-prev]'), next = group.querySelector('[data-pc-partners-next]');
    const toggle = group.querySelector('[data-pc-partners-toggle]'), indicators = group.querySelector('.pc-partners__indicators');
    const dots = group.querySelector('.pc-partners__dots'), scrollbar = group.querySelector('.pc-partners__scrollbar'), thumb = group.querySelector('.pc-partners__scrollbar-thumb');
    const lifetime = new AbortController(), reduced = matchMedia('(prefers-reduced-motion:reduce)');
    const mode = group.dataset.style, automated = mode === 'scroll', direction = group.dataset.direction === 'right' ? -1 : 1;
    const seconds = Math.max(1, Math.min(60, Number(group.dataset.seconds) || 4));
    let selected = 0, count = 1, columns = 1, pitch = 0, deck = 0, phase = 0, seamless = false, thumbWidth = 24;
    let visible = !window.IntersectionObserver, hovered = false, focused = false, userPaused = false, drag = null;
    let timer = 0, motionFrame = 0, layoutFrame = 0, scrollFrame = 0, lastTime = 0, clickBlockedUntil = 0, cloneKey = '';
    const on = (node, name, fn, options = {}) => node?.addEventListener(name, fn, {...options, signal:lifetime.signal});
    const present = () => {
      const rect = viewport.getBoundingClientRect();
      return group.isConnected && rect.width > 0 && rect.height > 0 && rect.bottom > 0 && rect.top < innerHeight && rect.right > 0 && rect.left < innerWidth;
    };
    const stop = () => { clearTimeout(timer); timer = 0; cancelAnimationFrame(motionFrame); motionFrame = 0; lastTime = 0; };
    const paint = () => {
      [...dots.children].forEach((dot, index) => dot.setAttribute('aria-current', index === selected ? 'true' : 'false'));
      scrollbar.value = String(selected); scrollbar.setAttribute('aria-valuetext', `${selected + 1} of ${count}`);
      thumb.style.transform = `translateX(${count>1 ? selected/(count-1)*Math.max(0,viewport.clientWidth-thumbWidth) : 0}px)`;
      previous.disabled = count < 2 || (!automated && selected === 0);
      next.disabled = count < 2 || (!automated && selected === count - 1);
      toggle.setAttribute('aria-pressed', userPaused ? 'true' : 'false');
      const label=userPaused ? 'Resume scrolling' : 'Pause scrolling'; if(toggle.textContent!==label)toggle.textContent=label;
    };
    const transform = () => { list.style.transform = `translate3d(${-phase}px,0,0)`; };
    const move = (index, smooth = true, manual = false, wrap = false) => {
      selected = wrap && automated ? ((index % count) + count) % count : Math.max(0, Math.min(count - 1, index));
      if (manual && automated) userPaused = true;
      if (seamless) { phase = selected * pitch; viewport.scrollLeft = 0; transform(); }
      else viewport.scrollTo({left:selected * pitch, behavior:smooth && !reduced.matches ? 'smooth' : 'auto'});
      paint(); if (manual) update();
    };
    const canRun = () => automated && count > 1 && visible && present() && !suspended && !document.hidden && !reduced.matches && !userPaused && !focused && !drag && !(hovered && group.dataset.hoverPause === '1');
    const animate = timestamp => {
      motionFrame = 0;
      if (!canRun() || !seamless) { update(); return; }
      if (lastTime) phase = (phase + direction * pitch * Math.min(100, timestamp-lastTime)/(seconds*1000) + deck) % deck;
      lastTime = timestamp; transform();
      const current = Math.min(count - 1, Math.max(0, Math.round(phase/pitch))); if(current!==selected){selected=current;paint();}
      motionFrame = requestAnimationFrame(animate);
    };
    const update = () => {
      stop();
      const running = canRun(); group.dataset.pcPartnersState = running ? 'running' : count > 1 && automated ? 'paused' : 'static';
      if (!running) return;
      if (seamless) motionFrame = requestAnimationFrame(animate);
      else timer = setTimeout(() => { timer = 0; if (canRun()) move((selected + direction + count) % count, true); update(); }, seconds*1000);
    };
    const removeCopies = () => list.querySelectorAll('[data-pc-partners-copy]').forEach(copy => copy.remove());
    const copies = () => {
      const key = `${seamless}:${columns}`; if (key === cloneKey) return; cloneKey = key;
      removeCopies();
      if (!seamless) { list.style.transform = ''; return; }
      for (let deckIndex = 0; deckIndex < 2; deckIndex++) items.forEach((item, index) => {
        const copy = item.cloneNode(true); copy.dataset.pcPartnersCopy = String(index); copy.setAttribute('aria-hidden','true'); copy.setAttribute('inert','');
        copy.removeAttribute('data-module-item');
        [copy, ...copy.querySelectorAll('*')].forEach(node => { node.removeAttribute('id'); node.removeAttribute('data-module-item'); if (node.matches('a,button,input,select,textarea,[tabindex]')) { node.removeAttribute('href'); node.setAttribute('tabindex','-1'); } });
        list.appendChild(copy);
      });
    };
    const measure = () => {
      layoutFrame = 0;
      if (!group.isConnected) { cleanup(); return; }
      stop();
      if(seamless&&viewport.scrollLeft)phase=(phase+viewport.scrollLeft+deck)%deck;
      columns = Math.max(1, Number(getComputedStyle(group).getPropertyValue('--pc-partners-visible')) || 1);
      // The resolved custom property may retain var(); item geometry is authoritative.
      const gap = parseFloat(getComputedStyle(list).gap) || 0;
      pitch = (items[0]?.getBoundingClientRect().width || 0) + gap;
      if (pitch > gap) columns = Math.max(1, Math.round((viewport.clientWidth+gap)/pitch));
      count = Math.max(1, items.length-columns+1); selected = Math.min(selected,count-1); deck = items.length*pitch;
      seamless = automated && group.dataset.seamless === '1' && count > 1 && !reduced.matches && pitch > 0;
      copies(); group.dataset.running = seamless ? 'seamless' : 'manual';
      controls.hidden = mode === 'grid' || count < 2;
      indicators.hidden = mode !== 'slider' || count < 2;
      toggle.hidden = !automated || count < 2 || reduced.matches;
      if (dots.children.length !== count) {
        dots.replaceChildren();
        for (let i=0;i<count;i++) {
          const dot = document.createElement('button'); dot.type = 'button'; dot.className = 'pc-partners__dot';
          dot.setAttribute('aria-label',`Show partner position ${i+1}`); dot.setAttribute('aria-controls',viewport.id);
          on(dot,'click',()=>move(i,true,true)); dots.appendChild(dot);
        }
      }
      scrollbar.max = String(count-1);
      const contentWidth = items.length*pitch-gap;
      thumbWidth = Math.max(24,Math.min(viewport.clientWidth,viewport.clientWidth*viewport.clientWidth/Math.max(1,contentWidth)));
      group.style.setProperty('--pc-partners-thumb',`${thumbWidth}px`);
      if (seamless) { phase = (phase + deck) % deck; viewport.scrollLeft=0; transform(); }
      else { list.style.transform=''; viewport.scrollLeft = selected*pitch; }
      paint(); update();
    };
    const schedule = () => { if (!layoutFrame) layoutFrame = requestAnimationFrame(measure); };
    const cleanup = () => {
      stop(); cancelAnimationFrame(layoutFrame); cancelAnimationFrame(scrollFrame); layoutFrame=scrollFrame=0;
      lifetime.abort(); intersection?.disconnect(); resize?.disconnect(); removeCopies(); list.style.transform='';
      delete group.dataset.pcPartnersReady; delete group.dataset.pcPartnersState; delete group.dataset.running; delete group.dataset.dragging;
      instances.delete(group);
    };
    const intersection = window.IntersectionObserver ? new IntersectionObserver(entries => { visible=entries.some(entry=>entry.isIntersecting); update(); },{threshold:0}) : null;
    const resize = window.ResizeObserver ? new ResizeObserver(schedule) : null;
    on(previous,'click',()=>move(selected-1,true,true,true)); on(next,'click',()=>move(selected+1,true,true,true));
    on(toggle,'click',()=>{userPaused=!userPaused;paint();update();});
    on(scrollbar,'input',()=>move(Number(scrollbar.value),false,true));
    on(viewport,'keydown',event=>{
      if (event.target !== viewport || mode==='grid') return;
      const index = event.key==='Home' ? 0 : event.key==='End' ? count-1 : event.key==='ArrowRight' ? selected+1 : event.key==='ArrowLeft' ? selected-1 : null;
      if (index === null) return; event.preventDefault(); move(index,true,true,event.key === 'ArrowRight' || event.key === 'ArrowLeft');
    });
    on(group,'focusin',event=>{
      focused=true;
      if(seamless&&viewport.scrollLeft){phase=(phase+viewport.scrollLeft+deck)%deck;viewport.scrollLeft=0;transform();}
      const item=event.target.closest('.pc-partners__item'), index=items.indexOf(item);
      if (index>=0 && mode!=='grid') {
        const target=item.getBoundingClientRect(), bounds=viewport.getBoundingClientRect();
        if(target.left<bounds.left-1||target.right>bounds.right+1)move(Math.min(index,count-1),false);
      }
      update();
    });
    on(group,'focusout',()=>queueMicrotask(()=>{if(!lifetime.signal.aborted){focused=group.contains(document.activeElement);update();}}));
    on(group,'pointerenter',event=>{if(event.pointerType!=='touch'){hovered=true;update();}});
    on(group,'pointerleave',()=>{hovered=false;update();});
    on(viewport,'scroll',()=>{
      if(seamless){if(viewport.scrollLeft){phase=(phase+viewport.scrollLeft+deck)%deck;viewport.scrollLeft=0;transform();}return;}
      if(!scrollFrame)scrollFrame=requestAnimationFrame(()=>{scrollFrame=0;selected=Math.max(0,Math.min(count-1,Math.round(viewport.scrollLeft/Math.max(1,pitch))));paint();});
    },{passive:true});
    on(viewport,'pointerdown',event=>{
      if(mode==='grid'||count<2||event.button!==0||event.target.closest('button,input'))return;
      drag={id:event.pointerId,x:event.clientX,y:event.clientY,start:seamless?phase:viewport.scrollLeft,moved:false};update();
    });
    on(window,'pointermove',event=>{
      if(!drag||event.pointerId!==drag.id)return;
      const dx=event.clientX-drag.x,dy=event.clientY-drag.y;
      if(!drag.moved&&Math.abs(dy)>Math.abs(dx)&&Math.abs(dy)>6){drag=null;update();return;}
      if(!drag.moved&&Math.abs(dx)<6)return;
      drag.moved=true;group.dataset.dragging='1';event.preventDefault();
      if(seamless){phase=(drag.start-dx+deck*2)%deck;transform();}else viewport.scrollLeft=drag.start-dx;
    },{passive:false});
    const endDrag=event=>{
      if(!drag||event.pointerId!==drag.id)return;
      const moved=drag.moved;drag=null;delete group.dataset.dragging;
      if(moved){clickBlockedUntil=performance.now()+350;userPaused=automated;selected=Math.max(0,Math.min(count-1,Math.round((seamless?phase:viewport.scrollLeft)/Math.max(1,pitch))));if(!seamless)move(selected,true);}
      paint();update();
    };
    on(window,'pointerup',endDrag);on(window,'pointercancel',endDrag);
    on(viewport,'dragstart',event=>event.preventDefault());
    on(viewport,'click',event=>{
      if(performance.now()<clickBlockedUntil){event.preventDefault();event.stopPropagation();return;}
      if(!seamless||event.target.closest('a[href]'))return;
      // Decorative copies cannot receive focus or expose links; a pointer hit delegates to its one real anchor.
      const copy=[...list.querySelectorAll('[data-pc-partners-copy]')].find(node=>{const r=node.getBoundingClientRect();return event.clientX>=r.left&&event.clientX<=r.right&&event.clientY>=r.top&&event.clientY<=r.bottom;});
      if(copy){const anchor=items[Number(copy.dataset.pcPartnersCopy)]?.querySelector('a[href]');if(anchor){event.preventDefault();anchor.click();}}
    },{capture:true});
    on(document,'visibilitychange',update);on(window,'resize',schedule);on(reduced,'change',schedule);
    if(!intersection)on(window,'scroll',()=>{visible=present();update();},{passive:true});
    on(list,'load',schedule,{capture:true});
    group.dataset.pcPartnersReady='1'; instances.set(group,{cleanup});
    intersection?.observe(viewport);resize?.observe(viewport);measure();
  };
  const init = () => {if(!suspended)document.querySelectorAll('[data-pc-partners]').forEach(create);};
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
  new MutationObserver(records=>{
    if(records.some(record=>record.removedNodes.length))for(const [group,instance] of instances)if(!group.isConnected)instance.cleanup();
    if(records.some(record=>[...record.addedNodes].some(node=>node.nodeType===1)))init();
  }).observe(document.documentElement,{childList:true,subtree:true});
  addEventListener('pagehide',()=>{suspended=true;for(const instance of [...instances.values()])instance.cleanup();});
  addEventListener('pageshow',()=>{suspended=false;init();});
})();

/* O16: manual page selection, six real cards per page; no clones, timers, requests or autoplay. */
(() => {
  const instances = new Map(), selections = new WeakMap(); let suspended = false;
  const create = root => {
    if (instances.has(root) || root.dataset.pcFulfillmentReady === '1') return;
    const grid = root.querySelector('[data-pc-fulfillment-grid]'), controls = root.querySelector('[data-pc-fulfillment-controls]');
    if (!grid || !controls) return;
    const cards = [...grid.children].filter(node => node.hasAttribute('data-pc-fulfillment-card'));
    const previous = controls.querySelector('[data-pc-fulfillment-prev]'), next = controls.querySelector('[data-pc-fulfillment-next]'), output = controls.querySelector('[data-pc-fulfillment-page]');
    if (!cards.length || !previous || !next || !output) return;
    const reduced = matchMedia('(prefers-reduced-motion:reduce)'), pages = Math.ceil(cards.length / 6);
    const listeners = []; let selected = Math.min(pages - 1, selections.get(root) || 0), pointer = null, animation = null;
    const on = (target, event, callback, options) => {target.addEventListener(event, callback, options);listeners.push(() => target.removeEventListener(event, callback, options));};
    const paint = () => {
      selections.set(root, selected);
      cards.forEach((card, index) => {card.hidden = Math.floor(index / 6) !== selected;});
      previous.disabled = selected === 0; next.disabled = selected === pages - 1;
      controls.hidden = pages < 2; output.textContent = 'Page ' + (selected + 1) + ' of ' + pages;
      root.dataset.pcFulfillmentReady = '1';
    };
    const cancel = () => {animation?.cancel();animation = null;};
    const show = (index, trigger, motion = true) => {
      const target = Math.max(0, Math.min(pages - 1, index)); if (target === selected) return;
      const active = document.activeElement, activeCard = cards.find(card => card.contains(active));
      selected = target; cancel(); paint();
      if (activeCard && activeCard.hidden) {grid.tabIndex = -1;grid.focus({preventScroll:true});}
      if (trigger && trigger.disabled) {(target === 0 ? next : previous).focus({preventScroll:true});}
      if (motion && !reduced.matches && !document.hidden && grid.animate) {animation = grid.animate([{opacity:.55,transform:'translateX(8px)'},{opacity:1,transform:'translateX(0)'}],{duration:220,easing:'cubic-bezier(.22,1,.36,1)'});}
    };
    on(previous,'click',() => show(selected - 1, previous)); on(next,'click',() => show(selected + 1, next));
    on(root,'keydown',event => {
      if (event.target.closest('input,textarea,select,[contenteditable=true]') || event.altKey || event.ctrlKey || event.metaKey) return;
      let target;
      if (event.key === 'ArrowRight') target = selected + 1; else if (event.key === 'ArrowLeft') target = selected - 1;
      else if (event.key === 'Home') target = 0; else if (event.key === 'End') target = pages - 1; else return;
      if (pages < 2) return; event.preventDefault(); show(target, event.target.closest('button'));
    });
    on(grid,'pointerdown',event => {if (event.isPrimary && event.pointerType !== 'mouse') {pointer = {id:event.pointerId,x:event.clientX,y:event.clientY};try {grid.setPointerCapture(event.pointerId);} catch (_) {}}},{passive:true});
    on(grid,'pointerup',event => {
      if (!pointer || pointer.id !== event.pointerId) return;
      const dx = event.clientX-pointer.x,dy = event.clientY-pointer.y;pointer = null;
      if (Math.abs(dx)>45 && Math.abs(dx)>Math.abs(dy)*1.4) show(selected+(dx<0?1:-1));
    },{passive:true});
    on(grid,'pointercancel',() => {pointer = null;});
    on(window,'resize',() => {pointer = null;cancel();paint();});
    on(document,'visibilitychange',() => {pointer = null;cancel();});
    on(reduced,'change',cancel);
    const cleanup = () => {listeners.splice(0).forEach(remove => remove());cancel();cards.forEach(card => {card.hidden = false;});controls.hidden = true;delete root.dataset.pcFulfillmentReady;instances.delete(root);};
    instances.set(root,{cleanup});paint();
  };
  const init = () => {if (!suspended) document.querySelectorAll('[data-pc-fulfillment]').forEach(create);};
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded',init,{once:true});else init();
  new MutationObserver(records => {
    if (records.some(record => record.removedNodes.length)) for (const [root,instance] of instances) if (!root.isConnected) instance.cleanup();
    if (records.some(record => [...record.addedNodes].some(node => node.nodeType === 1))) init();
  }).observe(document.documentElement,{childList:true,subtree:true});
  addEventListener('pagehide',() => {suspended = true;for (const instance of instances.values()) instance.cleanup();});
  addEventListener('pageshow',() => {suspended = false;init();});
})();

/* O21: one canonical row DOM, independent vertical playback and local resource ownership. */
(() => {
  const instances = new Map(), remembered = new WeakMap(); let suspended = false;
  const create = root => {
    if (instances.has(root)) return;
    const viewport = root.querySelector('.pc-announcement-reference__viewport'), track = root.querySelector('.pc-announcement-reference__track');
    const rows = track ? [...track.children].filter(row => row.hasAttribute('data-pc-announcement-row')) : [];
    const pause = root.querySelector('[data-pc-announcement-pause]'), status = root.querySelector('[data-pc-announcement-status]');
    if (!viewport || !track || !rows.length || !pause || !status) return;
    const reduced = matchMedia('(prefers-reduced-motion:reduce)'), saved = remembered.get(root) || {};
    const clamp = (value, min, max, fallback) => Number.isFinite(Number(value)) ? Math.max(min,Math.min(max,Number(value))) : fallback;
    const interval = clamp(root.dataset.interval,1000,10000,3000), duration = clamp(root.dataset.effectDuration,1000,5000,1000), effectGap = clamp(root.dataset.effectInterval,500,5000,1500);
    const effect = ['bounce','rubberband','shake','swing','wobble'].includes(root.dataset.effect) ? root.dataset.effect : 'none';
    const listeners = [], originalTab = new Map(), originalInert = new Map(), originalAria = new Map();
    rows.forEach(row => {originalInert.set(row,row.inert);originalAria.set(row,row.getAttribute('aria-hidden'));row.querySelectorAll('a[href],button,[tabindex]').forEach(node => originalTab.set(node,node.getAttribute('tabindex')));});
    let selected = Math.min(rows.length-1,Math.max(0,saved.selected || 0)), manualPaused = !!saved.manualPaused, hovered = false, focused = false, inView = false, dead = false;
    let slideTimer = 0, effectTimer = 0, effectEnd = 0, scrollFrame = 0, observer = null, intersecting = typeof IntersectionObserver !== 'function';
    const on = (node,event,callback,options) => {node.addEventListener(event,callback,options);listeners.push(() => node.removeEventListener(event,callback,options));};
    const stop = () => {
      clearTimeout(slideTimer);clearTimeout(effectTimer);clearTimeout(effectEnd);slideTimer=effectTimer=effectEnd=0;if(scrollFrame)cancelAnimationFrame(scrollFrame);scrollFrame=0;
      rows.forEach(row => row.querySelectorAll('.is-animating').forEach(button => button.classList.remove('is-animating')));
    };
    const geometry = () => {const r=root.getBoundingClientRect();return r.width>0 && r.height>0 && r.bottom>0 && r.right>0 && r.top<innerHeight && r.left<innerWidth;};
    const allowed = () => !dead && !suspended && root.isConnected && root.dataset.editor !== '1' && !document.hidden && !reduced.matches && inView && geometry() && !manualPaused && !hovered && !focused;
    const save = () => remembered.set(root,{selected,manualPaused});
    const paint = (motion=false) => {
      const first = rows[0].offsetTop, offset = rows[selected].offsetTop-first;
      track.style.transition = motion && !reduced.matches ? 'transform 240ms cubic-bezier(.22,1,.36,1)' : 'none';
      track.style.transform = 'translateY(-'+offset+'px)';
      rows.forEach((row,index) => {
        const active=index===selected;row.setAttribute('aria-hidden',active?'false':'true');row.inert=!active;
        row.querySelectorAll('a[href],button,[tabindex]').forEach(node => {if(!active)node.tabIndex=-1;else{const original=originalTab.get(node);if(original===null)node.removeAttribute('tabindex');else node.setAttribute('tabindex',original);}});
      });
      const height=rows[selected].getBoundingClientRect().height;if(height>0)viewport.style.height=height+'px';
      pause.hidden=rows.length<2 && effect==='none';pause.setAttribute('aria-pressed',manualPaused?'true':'false');
      pause.setAttribute('aria-label',manualPaused?'Resume announcements':'Pause announcements');
      pause.querySelector('[data-pc-announcement-pause-icon]')?.setAttribute('data-paused',manualPaused?'1':'0');
      root.dataset.pcAnnouncementReady='1';save();
    };
    const scheduleEffect = () => {
      if (!allowed() || effect==='none' || !rows[selected].querySelector('.pc-announcement-reference__button')) return;
      effectTimer=setTimeout(() => {
        effectTimer=0;if(!allowed())return;
        const button=rows[selected].querySelector('.pc-campaign__button');if(!button)return;
        button.classList.add('is-animating');
        effectEnd=setTimeout(() => {effectEnd=0;button.classList.remove('is-animating');scheduleEffect();},duration);
      },effectGap);
    };
    const schedule = () => {
      stop();if(!allowed())return;
      if(rows.length>1)slideTimer=setTimeout(() => {slideTimer=0;if(allowed())show((selected+1)%rows.length,false);},interval);
      scheduleEffect();
    };
    const show = (index,manual=true) => {
      const target=Math.max(0,Math.min(rows.length-1,index));
      if(target!==selected && rows[selected].contains(document.activeElement))viewport.focus({preventScroll:true});
      const motion=target>selected;selected=target;paint(motion);
      if(manual){status.setAttribute('aria-live','polite');status.textContent='Announcement '+(selected+1)+' of '+rows.length;}
      else status.setAttribute('aria-live','off');
      schedule();
    };
    const exposure = () => {const next=intersecting && geometry();if(next!==inView){inView=next;schedule();}};
    on(pause,'click',() => {manualPaused=!manualPaused;paint();schedule();});
    on(root,'mouseenter',() => {hovered=true;stop();});on(root,'mouseleave',() => {hovered=false;schedule();});
    on(root,'focusin',() => {focused=true;stop();});on(root,'focusout',event => {focused=root.contains(event.relatedTarget);if(!focused)schedule();});
    on(root,'keydown',event => {
      if(event.altKey||event.ctrlKey||event.metaKey||event.target.closest('input,textarea,select,[contenteditable=true]'))return;
      let target;if(event.key==='ArrowDown')target=(selected+1)%rows.length;else if(event.key==='ArrowUp')target=(selected+rows.length-1)%rows.length;
      else if(event.key==='Home')target=0;else if(event.key==='End')target=rows.length-1;else return;
      event.preventDefault();show(target,true);
    });
    on(document,'visibilitychange',() => {stop();if(!document.hidden){exposure();schedule();}});
    on(reduced,'change',() => {stop();paint();schedule();});
    on(window,'resize',() => {paint();exposure();schedule();});
    if(typeof IntersectionObserver==='function'){
      observer=new IntersectionObserver(entries => {const entry=entries.find(value=>value.target===root);if(entry){intersecting=entry.isIntersecting;exposure();schedule();}});observer.observe(root);
    }else{
      inView=geometry();on(window,'scroll',() => {if(!scrollFrame)scrollFrame=requestAnimationFrame(()=>{scrollFrame=0;exposure();});},{passive:true});
    }
    const cleanup = () => {
      if(dead)return;dead=true;save();stop();if(scrollFrame)cancelAnimationFrame(scrollFrame);scrollFrame=0;observer?.disconnect();listeners.splice(0).forEach(remove=>remove());
      rows.forEach(row=>{row.inert=originalInert.get(row);const aria=originalAria.get(row);if(aria===null)row.removeAttribute('aria-hidden');else row.setAttribute('aria-hidden',aria);});
      originalTab.forEach((value,node)=>{if(value===null)node.removeAttribute('tabindex');else node.setAttribute('tabindex',value);});
      track.style.removeProperty('transform');track.style.removeProperty('transition');viewport.style.removeProperty('height');pause.hidden=true;delete root.dataset.pcAnnouncementReady;instances.delete(root);
    };
    instances.set(root,{cleanup,exposure});paint();schedule();
  };
  const init = () => {if(!suspended)document.querySelectorAll('[data-pc-announcement-reference]').forEach(create);};
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
  new MutationObserver(records => {
    for(const [root,instance]of instances){if(!root.isConnected)instance.cleanup();else if(records.some(record=>record.type==='attributes'))instance.exposure();}
    if(records.some(record=>[...record.addedNodes].some(node=>node.nodeType===1)))init();
  }).observe(document.documentElement,{childList:true,subtree:true,attributes:true,attributeFilter:['hidden','class','style']});
  addEventListener('pagehide',()=>{suspended=true;for(const instance of [...instances.values()])instance.cleanup();});
  addEventListener('pageshow',()=>{suspended=false;init();});
})();

/* O22 owner: server-time countdown, native scrolling, no business requests/writes. */
(function(){
  'use strict';
  const owners=new Map();
  function clock(seconds,format){
    const s=Math.max(0,Math.floor(seconds)), pad=n=>String(n).padStart(2,'0');
    return (format==='hms'?[Math.floor(s/3600)+'h',pad(Math.floor(s/60)%60)+'m',pad(s%60)+'s']:[Math.floor(s/86400)+'d',pad(Math.floor(s/3600)%24)+'h',pad(Math.floor(s/60)%60)+'m',pad(s%60)+'s']).join(' : ');
  }
  function install(root){
    const start=performance.now(), epoch=Number(root.dataset.serverNow), rail=root.querySelector('.pc-shipping-reference__list');
    if(!Number.isSafeInteger(epoch)||epoch<1||!rail)return null;
    const signature=()=>JSON.stringify([root.dataset.serverNow,root.dataset.clockFormat,root.dataset.upcomingLabel,root.dataset.noEndLabel,root.dataset.mobileLayout,root.dataset.effectDuration,root.dataset.effectInterval]);
    const initialSignature=signature(),life=new AbortController(), reduced=matchMedia('(prefers-reduced-motion:reduce)'), cards=[...rail.children], button=root.querySelector('[data-shipping-effect]');
    let ticker=null,effectTimer=null,animation=null,dead=false,pageHidden=false,intersecting=true;
    const visible=()=>{
      const rect=root.getClientRects()[0],height=window.innerHeight||document.documentElement.clientHeight,width=window.innerWidth||document.documentElement.clientWidth;
      return root.isConnected&&!document.hidden&&!pageHidden&&intersecting&&!!rect&&getComputedStyle(root).visibility!=='hidden'&&(!height||(rect.bottom>0&&rect.top<height))&&(!width||(rect.right>0&&rect.left<width));
    };
    const now=()=>epoch+Math.max(0,Math.floor((performance.now()-start)/1000));
    const hide=(node,value)=>{if(node.hidden!==value)node.hidden=value;};
    const text=(node,value)=>{if(node&&node.textContent!==value)node.textContent=value;};
    function update(){
      const time=now();
      cards.forEach(card=>{
        const a=Number(card.dataset.start),b=Number(card.dataset.end),check=Number(card.dataset.checkAt||0);
        if(!Number.isSafeInteger(a)||!Number.isSafeInteger(b)||a<0||b<0||(b&&b<=a)){hide(card,true);return;}
        if((b&&time>=b)||(check&&time>=check)||(time>=a&&card.dataset.state==='upcoming'&&card.dataset.refreshAtStart==='1')){hide(card,true);return;}
        hide(card,false);
        const pending=time<a,deadline=pending?a:b,out=card.querySelector('[data-shipping-clock]'),label=card.querySelector('[data-shipping-label]'),date=card.querySelector('time');
        const state=pending?'upcoming':'active';if(card.dataset.state!==state)card.dataset.state=state;
        text(label,pending?root.dataset.upcomingLabel+' ':'');
        text(out,deadline?clock(deadline-time,root.dataset.clockFormat):root.dataset.noEndLabel);
        if(date)hide(date,!pending);
      });
      hide(root,!cards.some(card=>!card.hidden));
      if(root.hidden){clearInterval(ticker);ticker=null;stopEffect();}
    }
    const frames={
      bounce:[{transform:'translateY(0)'},{transform:'translateY(-8px)',offset:.35},{transform:'translateY(0)',offset:.6},{transform:'translateY(-3px)',offset:.8},{transform:'translateY(0)'}],
      rubberband:[{transform:'scale(1)'},{transform:'scale(1.12,.9)'},{transform:'scale(.94,1.06)'},{transform:'scale(1)'}],
      shake:[{transform:'translateX(0)'},{transform:'translateX(-6px)'},{transform:'translateX(6px)'},{transform:'translateX(-4px)'},{transform:'translateX(0)'}],
      swing:[{transform:'rotate(0deg)'},{transform:'rotate(7deg)'},{transform:'rotate(-5deg)'},{transform:'rotate(3deg)'},{transform:'rotate(0deg)'}],
      wobble:[{transform:'translateX(0) rotate(0deg)'},{transform:'translateX(-5px) rotate(-4deg)'},{transform:'translateX(5px) rotate(4deg)'},{transform:'translateX(-2px) rotate(-2deg)'},{transform:'translateX(0) rotate(0deg)'}]
    };
    function stopEffect(){clearTimeout(effectTimer);effectTimer=null;if(animation){animation.cancel();animation=null;}}
    function effect(){
      if(dead||!button||!frames[button.dataset.shippingEffect]||reduced.matches||!visible()||typeof button.animate!=='function')return;
      const duration=Math.max(1000,Math.min(5000,Number(root.dataset.effectDuration)||1000)),interval=Math.max(500,Math.min(5000,Number(root.dataset.effectInterval)||1500));
      animation=button.animate(frames[button.dataset.shippingEffect],{duration,easing:'cubic-bezier(.22,1,.36,1)',iterations:1});
      effectTimer=setTimeout(()=>{animation=null;effect();},duration+interval);
    }
    function pause(){clearInterval(ticker);ticker=null;stopEffect();}
    function resume(){
      if(dead)return;if(root.hidden){pause();return;}update();
      if(visible()){if(ticker===null)ticker=setInterval(()=>{if(visible())update();else pause();},1000);if(effectTimer===null&&!animation)effect();}else pause();
    }
    const intersection=typeof IntersectionObserver==='function'?new IntersectionObserver(entries=>{for(const entry of entries)if(entry.target===root){intersecting=entry.isIntersecting;resume();}}):null;
    intersection?.observe(root);
    rail.addEventListener('keydown',event=>{
      if(root.dataset.mobileLayout!=='carousel'||!matchMedia('(max-width:767px)').matches||event.altKey||event.ctrlKey||event.metaKey||event.target!==rail)return;
      const live=cards.filter(card=>!card.hidden);if(!live.length)return;
      let left;
      if(event.key==='Home')left=0;
      else if(event.key==='End')left=rail.scrollWidth-rail.clientWidth;
      else if(event.key==='ArrowRight'||event.key==='ArrowLeft'){const step=live[0].getBoundingClientRect().width+12;left=rail.scrollLeft+(event.key==='ArrowRight'?step:-step);}
      else return;
      event.preventDefault();rail.scrollTo({left:Math.max(0,Math.min(rail.scrollWidth-rail.clientWidth,left)),behavior:reduced.matches?'auto':'smooth'});
    },{signal:life.signal});
    document.addEventListener('visibilitychange',resume,{signal:life.signal});
    window.addEventListener('pageshow',()=>{pageHidden=false;resume();},{signal:life.signal});
    window.addEventListener('pagehide',()=>{pageHidden=true;pause();},{signal:life.signal});
    window.addEventListener('resize',resume,{signal:life.signal});
    reduced.addEventListener('change',()=>{stopEffect();resume();},{signal:life.signal});
    resume();
    return {scan(){if(ticker!==null){if(!visible())pause();}else resume();},valid:()=>initialSignature===signature()&&root.querySelector('.pc-shipping-reference__list')===rail&&rail.children.length===cards.length&&cards.every((card,index)=>rail.children[index]===card),destroy(){dead=true;pause();intersection?.disconnect();life.abort();}};
  }
  function scan(){
    owners.forEach((owner,root)=>{if(!root.isConnected||!owner.valid()){owner.destroy();owners.delete(root);}});
    document.querySelectorAll('[data-pc-shipping-reference]').forEach(root=>{if(!owners.has(root)){const owner=install(root);if(owner)owners.set(root,owner);}else owners.get(root).scan();});
  }
  function boot(){scan();new MutationObserver(scan).observe(document.documentElement,{childList:true,subtree:true,attributes:true,attributeFilter:['class','style','data-server-now','data-clock-format','data-upcoming-label','data-no-end-label','data-mobile-layout','data-effect-duration','data-effect-interval']});}
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});else boot();
})();
