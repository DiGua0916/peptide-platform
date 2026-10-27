(() => {
  'use strict';
  document.addEventListener('vc:select-item', event => {
    if (!(event.target instanceof Element) || event.defaultPrevented) return;
    const variant = event.target.closest('.vc-v');
    if (variant && getComputedStyle(variant).display === 'none') return;
    const staticItem = event.target.closest('.vc-m--media-text-list [data-module-item], .vc-m--report-feature [data-module-item]');
    if (staticItem && staticItem.dataset.moduleItem === event.detail?.item) {
      // Static rows are already visible. Suppress the editor's carousel fallback.
      event.preventDefault();
      return;
    }
    const details = event.target.closest('.vc-m--faq details[data-module-item]');
    if (!details || details.dataset.moduleItem !== event.detail?.item) return;
    details.open = true;
    event.preventDefault();
  });
  const nodes = [...document.querySelectorAll('[data-vc-module-reveal]')];
  if (!nodes.length || !('IntersectionObserver' in window) || !Element.prototype.animate) return;
  const reduced = matchMedia('(prefers-reduced-motion: reduce)');
  const active = new Map();
  const cancel = () => { active.forEach(animation => animation.cancel()); active.clear(); };
  const observer = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      if (!entry.isIntersecting || !entry.target.getClientRects().length || getComputedStyle(entry.target).visibility === 'hidden') return;
      observer.unobserve(entry.target);
      if (reduced.matches || document.hidden || entry.target.contains(document.activeElement)) return;
      const animation = entry.target.animate(
        [{ opacity: 0, transform: 'translateY(22px)' }, { opacity: 1, transform: 'translateY(0)' }],
        { duration: 650, easing: 'cubic-bezier(.22,1,.36,1)' }
      );
      active.set(entry.target, animation);
      animation.finished.then(() => active.delete(entry.target)).catch(() => {});
    });
  }, { threshold: 0.12 });
  nodes.forEach(node => {
    observer.observe(node);
    node.addEventListener('focusin', () => { active.get(node)?.cancel(); active.delete(node); });
  });
  reduced.addEventListener('change', cancel);
  window.addEventListener('resize', () => {
    active.forEach((animation, node) => {
      if (!node.getClientRects().length || getComputedStyle(node).visibility === 'hidden') {
        animation.cancel(); active.delete(node);
      }
    });
  }, { passive: true });
  document.addEventListener('visibilitychange', () => { if (document.hidden) cancel(); });
  window.addEventListener('pagehide', () => { cancel(); observer.disconnect(); });
  window.addEventListener('pageshow', event => { if (event.persisted) nodes.forEach(node => observer.observe(node)); });
})();

// Button effects are optional, respect a zero duration, and pause outside the viewport.
(() => {
  'use strict';
  if (!Element.prototype.animate || !('IntersectionObserver' in window)) return;
  const reduced = matchMedia('(prefers-reduced-motion: reduce)');
  const effects = [];
  document.querySelectorAll('[data-vc-button-effect],.vc-content-rich .vc-btn--pulse,.vc-content-rich .vc-btn--shine').forEach(node => {
    if (node.closest('.vc-m--editor')) return;
    const button = node.matches('a,button') ? node : node.querySelector('a,button');
    if (!button) return;
    const type = node.dataset.vcButtonEffect || (node.classList.contains('vc-btn--pulse') ? 'pulse' : 'shine');
    const style = getComputedStyle(node);
    const duration = Math.max(0, Math.min(5000, parseFloat(node.dataset.vcEffectDuration ?? style.getPropertyValue('--vc-btn-duration')) || 0));
    const interval = Math.max(0, Math.min(10000, parseFloat(node.dataset.vcEffectInterval ?? style.getPropertyValue('--vc-btn-interval')) || 0));
    button.classList.add('vc-button-effect-ready');
    if (!duration || !['pulse','shine'].includes(type)) return;
    let target = button;
    if (type === 'shine') {
      button.classList.add('vc-button-effect-shine');
      target = document.createElement('span'); target.className = 'vc-button-shine'; target.setAttribute('aria-hidden','true'); button.append(target);
    }
    const active = duration / (duration + interval);
    const frames = type === 'pulse'
      ? [{transform:'scale(1)',offset:0},{transform:'scale(1.035)',offset:active/2},{transform:'scale(1)',offset:active},{transform:'scale(1)',offset:1}]
      : [{transform:'translateX(-110%)',offset:0},{transform:'translateX(110%)',offset:active},{transform:'translateX(110%)',offset:1}];
    const animation = target.animate(frames,{duration:duration+interval,iterations:Infinity,easing:'ease-in-out'});
    animation.pause();
    const item = {node,button,animation,visible:false}; effects.push(item);
    button.addEventListener('focusin', update);
    button.addEventListener('focusout', () => setTimeout(update,0));
  });
  function update() {
    effects.forEach(item => {
      const rect = item.button.getBoundingClientRect();
      const visible = rect.width > 0 && rect.height > 0 && rect.bottom > 0 && rect.top < innerHeight &&
        rect.right > 0 && rect.left < innerWidth && getComputedStyle(item.button).visibility !== 'hidden';
      if (!reduced.matches && !document.hidden && visible && !item.button.contains(document.activeElement)) item.animation.play();
      else { item.animation.pause(); item.animation.currentTime = 0; }
    });
  }
  const observer = new IntersectionObserver(entries => {
    entries.forEach(entry => { const item=effects.find(effect=>effect.node===entry.target); if(item) item.visible=entry.isIntersecting; }); update();
  });
  effects.forEach(item=>observer.observe(item.node));
  reduced.addEventListener('change',update);
  window.addEventListener('resize',update,{passive:true});
  document.addEventListener('visibilitychange',update);
  window.addEventListener('pagehide',()=>{effects.forEach(item=>item.animation.pause());observer.disconnect();});
  window.addEventListener('pageshow',event=>{if(event.persisted)effects.forEach(item=>observer.observe(item.node));});
})();
