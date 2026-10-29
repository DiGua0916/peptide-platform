(() => {
  'use strict';
  const isRendered = node => node.isConnected && node.getClientRects().length > 0 &&
    getComputedStyle(node).visibility !== 'hidden';
  const initialize = root => {
    if (root.dataset.carouselReady === '1') return;
    const track = root.querySelector('.vc-carousel-track');
    const slides = track ? [...track.children].filter(el => el.classList.contains('vc-slide-panel')) : [];
    if (!slides.length) return;
    root.dataset.carouselReady = '1';
    const mobile = window.matchMedia('(max-width: 767px)');
    const syncDraggableCards = () => {
      const overlayClass = mobile.matches ? 'vc-cards-m-overlay' : 'vc-cards-d-overlay';
      root.querySelectorAll('[data-vc-card]').forEach(card => {
        if (card.closest('[data-vc-module-carousel]') !== root) return;
        card.toggleAttribute('data-pc-drag', card.closest('.vc-slide-cards')?.classList.contains(overlayClass) || false);
      });
    };
    // The editor's capture-phase mousedown handler uses this marker. Below/hidden cards
    // remain selectable, but must not be repositioned as floating cards on either device.
    syncDraggableCards();
    window.addEventListener('resize', syncDraggableCards, { passive: true });
    mobile.addEventListener('change', syncDraggableCards);
    const requestedSlide = event => {
      if (event.defaultPrevented || typeof event.detail?.item !== 'string' || !isRendered(root)) return -1;
      const target = event.target instanceof Element ? event.target : null;
      if (target?.closest('[data-vc-module-carousel]') !== root) return -1;
      // Desktop and mobile share item IDs. Prefer the actual editor target in this instance.
      const item = target.dataset.moduleItem === event.detail.item ? target : [...root.querySelectorAll('[data-module-item]')].find(node =>
        node.dataset.moduleItem === event.detail.item && node.closest('[data-vc-module-carousel]') === root);
      return item ? slides.indexOf(item.closest('.vc-slide-panel')) : -1;
    };
    if (root.dataset.editor === '1') {
      root.dataset.playback = 'paused';
      root.addEventListener('vc:select-item', event => {
        const next = requestedSlide(event);
        if (next < 0) return;
        event.preventDefault();
        root.dataset.slideIndex = String(next);
        root.dataset.selectedItem = event.detail.item;
      });
      return;
    }
    const controls = root.querySelector('.vc-carousel-controls');
    const dots = [...root.querySelectorAll('[data-vc-slide-to]')];
    const status = root.querySelector('.vc-carousel-status');
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
    const interval = Math.max(1000, Math.min(20000, Number(root.dataset.interval) || 3000));
    const enabled = root.dataset.autoplay === '1' && slides.length > 1;
    const events = new AbortController();
    const listen = (target, event, handler, options = {}) =>
      target?.addEventListener(event, handler, { ...options, signal: events.signal });
    let index = 0;
    let timer;
    let focusTimer;
    let visibilityFrame;
    let visible = false;
    let suspended = false;
    let hovered = false;
    let focused = root.contains(document.activeElement);
    let paused = false;
    let gesture = null;
    let observer;

    const stop = () => { clearTimeout(timer); timer = undefined; };
    const canPlay = () => enabled && !paused && !hovered && !focused && !gesture &&
      visible && !document.hidden && !suspended && !reduced.matches && isRendered(root);
    const schedule = () => {
      stop();
      root.dataset.playback = canPlay() ? 'playing' : 'paused';
      if (canPlay()) timer = setTimeout(() => {
        timer = undefined;
        if (canPlay()) show(index + 1, false);
      }, interval);
    };
    const show = (next, manual = true) => {
      if (manual && slides[index].contains(document.activeElement)) root.focus({ preventScroll: true });
      index = (next + slides.length) % slides.length;
      slides.forEach((slide, i) => {
        const active = i === index;
        slide.classList.toggle('is-active', active);
        slide.inert = !active;
        if (active) {
          slide.removeAttribute('aria-hidden');
          slide.querySelectorAll('img').forEach(img => { img.loading = 'eager'; });
        } else slide.setAttribute('aria-hidden', 'true');
      });
      dots.forEach((dot, i) => {
        if (i === index) dot.setAttribute('aria-current', 'true');
        else dot.removeAttribute('aria-current');
      });
      root.dataset.slideIndex = String(index);
      if (manual && status) status.textContent = slides[index].dataset.slideLabel || '';
      schedule();
    };
    const measure = () => {
      const rect = root.getBoundingClientRect();
      const h = Math.max(0, Math.min(rect.bottom, innerHeight) - Math.max(rect.top, 0));
      const w = Math.max(0, Math.min(rect.right, innerWidth) - Math.max(rect.left, 0));
      visible = isRendered(root) && rect.width > 0 && rect.height > 0 && h * w / (rect.width * rect.height) >= .1;
      if (!visible) { hovered = false; gesture = null; }
      focused = root.contains(document.activeElement) && isRendered(document.activeElement);
      schedule();
    };
    const queueMeasure = () => {
      if (visibilityFrame) return;
      visibilityFrame = requestAnimationFrame(() => { visibilityFrame = undefined; measure(); });
    };

    slides.forEach(slide => { slide.hidden = false; });
    root.classList.add('is-enhanced');
    if (controls) controls.hidden = false;
    listen(root, 'vc:select-item', event => {
      const next = requestedSlide(event);
      if (next < 0) return;
      event.preventDefault();
      paused = true;
      root.dataset.selectedItem = event.detail.item;
      show(next, false);
    });
    listen(root, 'click', event => {
      if (event.defaultPrevented || !isRendered(root) || event.target.closest('[data-vc-module-carousel]') !== root) return;
      const dot = event.target.closest('[data-vc-slide-to]');
      if (dot && root.contains(dot)) show(Number(dot.dataset.vcSlideTo));
      else if (event.target.closest('[data-vc-slide-prev]')) show(index - 1);
      else if (event.target.closest('[data-vc-slide-next]')) show(index + 1);
    });
    listen(root, 'keydown', event => {
      if (event.defaultPrevented || !isRendered(root) || event.target.closest('[data-vc-module-carousel]') !== root) return;
      if (event.target.closest('input,textarea,select,[contenteditable="true"]')) return;
      if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
        event.preventDefault();
        show(event.key === 'Home' ? 0 : event.key === 'End' ? slides.length - 1 : index + (event.key === 'ArrowLeft' ? -1 : 1));
      } else if (event.code === 'Space' && event.target === root && enabled) {
        event.preventDefault();
        paused = !paused;
        if (status) status.textContent = paused ? 'Automatic playback paused.' : 'Automatic playback enabled.';
        schedule();
      }
    });
    listen(root, 'focusin', () => { focused = true; schedule(); });
    listen(root, 'focusout', () => {
      clearTimeout(focusTimer);
      focusTimer = setTimeout(() => { focused = root.contains(document.activeElement); schedule(); }, 0);
    });
    listen(root, 'pointerenter', event => { if (event.pointerType === 'mouse') { hovered = true; schedule(); } });
    listen(root, 'pointerleave', event => { if (event.pointerType === 'mouse') { hovered = false; schedule(); } });
    listen(track, 'pointerdown', event => {
      // The design editor owns dragging cards; it must never also advance a slide.
      if (event.defaultPrevented || event.pointerType !== 'touch' || !event.isPrimary ||
        !isRendered(root) || event.target.closest('a,button,input,textarea,select,[contenteditable="true"],[data-pc-drag]')) return;
      gesture = { id: event.pointerId, x: event.clientX, y: event.clientY };
      track.setPointerCapture?.(event.pointerId);
      schedule();
    });
    listen(track, 'pointerup', event => {
      if (!gesture || event.pointerId !== gesture.id) return;
      const dx = event.clientX - gesture.x;
      const dy = event.clientY - gesture.y;
      gesture = null;
      if (Math.abs(dx) >= 50 && Math.abs(dx) > Math.abs(dy) * 1.3) show(index + (dx < 0 ? 1 : -1));
      else schedule();
    });
    const cancelGesture = () => { gesture = null; schedule(); };
    listen(track, 'pointercancel', cancelGesture);
    listen(track, 'lostpointercapture', cancelGesture);
    if ('IntersectionObserver' in window) {
      observer = new IntersectionObserver(measure, { threshold: [0, .1] });
      observer.observe(root);
    } else {
      listen(window, 'scroll', queueMeasure, { passive: true });
    }
    // A breakpoint swaps .vc-v copies. Reset stale hover/focus and resume the visible copy.
    listen(window, 'resize', queueMeasure, { passive: true });
    listen(document, 'visibilitychange', schedule);
    listen(reduced, 'change', schedule);
    listen(window, 'pagehide', event => {
      suspended = true;
      stop();
      root.dataset.playback = 'paused';
      clearTimeout(focusTimer);
      cancelAnimationFrame(visibilityFrame);
      observer?.disconnect();
      if (!event.persisted) events.abort();
    });
    listen(window, 'pageshow', () => {
      suspended = false;
      gesture = null;
      focused = root.contains(document.activeElement);
      observer?.observe(root);
      measure();
    });
    show(0, false);
    measure();
  };
  const init = () => document.querySelectorAll('[data-vc-module-carousel]').forEach(initialize);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
