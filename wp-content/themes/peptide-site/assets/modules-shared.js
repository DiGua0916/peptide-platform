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
  if (!Element.prototype.animate) return;
  const reduced = matchMedia('(prefers-reduced-motion: reduce)');
  const effects = [];
  const gestures = {
    bounce: [[0,'translateY(0)'],[.30,'translateY(0)'],[.42,'translateY(-7px)'],[.60,'translateY(0)'],[.75,'translateY(-3px)'],[1,'translateY(0)']],
    rubberband: [[0,'scale(1)'],[.28,'scale(1.12,.90)'],[.44,'scale(.93,1.08)'],[.60,'scale(1.05,.97)'],[.78,'scale(.99,1.01)'],[1,'scale(1)']],
    shake: [[0,'translateX(0)'],[.20,'translateX(-5px)'],[.40,'translateX(5px)'],[.60,'translateX(-5px)'],[.80,'translateX(5px)'],[1,'translateX(0)']],
    swing: [[0,'rotate(0)'],[.22,'rotate(7deg)'],[.44,'rotate(-5deg)'],[.66,'rotate(3deg)'],[.84,'rotate(-1deg)'],[1,'rotate(0)']],
    wobble: [[0,'translateX(0) rotate(0)'],[.22,'translateX(-6%) rotate(-4deg)'],[.44,'translateX(5%) rotate(3deg)'],[.66,'translateX(-3%) rotate(-2deg)'],[.84,'translateX(1%) rotate(1deg)'],[1,'translateX(0) rotate(0)']],
    pulse: [[0,'scale(1)'],[.5,'scale(1.035)'],[1,'scale(1)']],
    shine: [[0,'translateX(-110%)'],[1,'translateX(110%)']]
  };
  const selectors = ['[data-vc-button-effect]', ...Object.keys(gestures).map(type => '.vc-content-rich .vc-btn--' + type)];
  document.querySelectorAll(selectors.join(',')).forEach(node => {
    if (node.closest('.vc-m--editor')) return;
    const button = node.matches('a,button') ? node : node.querySelector('a,button');
    if (!button || button.dataset.vcEffectOwner) return;
    const type = node.dataset.vcButtonEffect || Object.keys(gestures).find(key => node.classList.contains('vc-btn--' + key));
    if (!Object.hasOwn(gestures, type)) return;
    const style = getComputedStyle(node);
    const duration = Math.max(0, Math.min(5000, parseFloat(node.dataset.vcEffectDuration ?? style.getPropertyValue('--vc-btn-duration')) || 0));
    const interval = Math.max(0, Math.min(10000, parseFloat(node.dataset.vcEffectInterval ?? style.getPropertyValue('--vc-btn-interval')) || 0));
    if (!duration) return;
    button.dataset.vcEffectOwner = 'theme';
    button.classList.add('vc-button-effect-ready');
    if (type === 'swing') button.classList.add('vc-button-effect-swing');
    let target = button;
    if (type === 'shine') {
      button.classList.add('vc-button-effect-shine');
      target = document.createElement('span'); target.className = 'vc-button-shine'; target.setAttribute('aria-hidden','true'); button.append(target);
    }
    const active = duration / (duration + interval);
    const frames = gestures[type].map(([offset,transform]) => ({ transform, offset: offset * active, easing: 'ease-in-out' }));
    if (interval) frames.push({ transform: gestures[type].at(-1)[1], offset: 1 });
    const animation = target.animate(frames,{duration:duration+interval,iterations:Infinity,easing:'linear'});
    animation.pause();
    const item = {node,button,animation,hovered:false,focused:false}; effects.push(item);
    button.addEventListener('mouseenter', () => { item.hovered = true; update(); });
    button.addEventListener('mouseleave', () => { item.hovered = false; update(); });
    button.addEventListener('focusin', () => { item.focused = true; update(); });
    button.addEventListener('focusout', event => { item.focused = !!(event.relatedTarget && button.contains(event.relatedTarget)); update(); });
  });
  let suspended = false;
  function update() {
    effects.forEach(item => {
      const rect = item.button.getBoundingClientRect();
      const visible = item.button.isConnected && rect.width > 0 && rect.height > 0 && rect.bottom > 0 && rect.top < innerHeight &&
        rect.right > 0 && rect.left < innerWidth && getComputedStyle(item.button).visibility !== 'hidden';
      if (!reduced.matches && !document.hidden && !suspended && visible && !item.hovered && !item.focused && !item.button.disabled && item.button.getAttribute('aria-disabled') !== 'true') item.animation.play();
      else { item.animation.pause(); item.animation.currentTime = 0; }
    });
  }
  const observer = 'IntersectionObserver' in window ? new IntersectionObserver(update) : null;
  effects.forEach(item=>observer?.observe(item.node));
  reduced.addEventListener('change',update);
  window.addEventListener('resize',update,{passive:true});
  window.addEventListener('scroll',update,{passive:true});
  document.addEventListener('visibilitychange',update);
  window.addEventListener('pagehide',()=>{suspended=true;update();observer?.disconnect();});
  window.addEventListener('pageshow',()=>{suspended=false;effects.forEach(item=>observer?.observe(item.node));update();});
  update();
})();
