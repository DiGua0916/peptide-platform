/* Decorate native confirmed Woo operations; never submit or own availability/cart state. */
(function () {
  'use strict';
  window.createVcNativeProductFeedback = function (options = {}) {
    const buttons = [...new Set(options.buttons || [])].filter(Boolean);
    const counts = [...new Set(options.bagCountElements || [])].filter(Boolean);
    let config = options.config || {}, activeConfig = null, snapshots = [], state = 'idle';
    let destroyed = false, trigger = null, effect = null, resetTimer = 0, pulseTimer = 0;
    const reduced = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    function attribute(element, name, value) {
      if (value === null) element.removeAttribute(name);
      else if (element.getAttribute(name) !== value) element.setAttribute(name, value);
    }
    function clearEffects() {
      clearTimeout(resetTimer); clearTimeout(pulseTimer); resetTimer = pulseTimer = 0;
      counts.forEach(element => element.classList.remove('add-feedback-count'));
      effect?.destroy(); effect = null;
    }
    function cancel() {
      clearEffects(); state = 'idle';
      snapshots.forEach(({button, nodes, busy, label}) => {
        // Disabled/inert/class are owned by native Woo, including uncertain recovery.
        button.replaceChildren(...nodes.map(node => node.cloneNode(true)));
        attribute(button, 'aria-busy', busy); attribute(button, 'aria-label', label);
        button.removeAttribute('data-add-state');
      });
      snapshots = []; activeConfig = null; trigger = null;
    }
    function paint() {
      if (destroyed || !activeConfig || state === 'idle') return;
      const label = String(state === 'pending' ? activeConfig.pendingText || 'Adding…' : activeConfig.successText || 'Added');
      buttons.forEach(button => {
        if (button.dataset.addState === state && button.querySelector('.add-feedback-label')?.textContent === label) return;
        const content = document.createElement('span'); content.className = 'add-feedback-content';
        const text = document.createElement('span'); text.className = 'add-feedback-label'; text.textContent = label; content.append(text);
        if (state === 'success') {
          const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
          svg.setAttribute('class','add-feedback-check'); svg.setAttribute('viewBox','0 0 24 24'); svg.setAttribute('width','24'); svg.setAttribute('height','24'); svg.setAttribute('aria-hidden','true');
          const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
          path.setAttribute('d','M5 12.5 9.5 17 19 7'); path.setAttribute('fill','none'); path.setAttribute('stroke','currentColor'); path.setAttribute('stroke-width','2'); path.setAttribute('stroke-linecap','round'); path.setAttribute('stroke-linejoin','round');
          svg.append(path); content.append(svg);
        }
        button.replaceChildren(content); button.dataset.addState = state;
        attribute(button,'aria-busy',String(state === 'pending')); attribute(button,'aria-label',label);
      });
    }
    function begin(button) {
      if (destroyed || state === 'pending') return false;
      cancel(); if (config.enabled === false) return false;
      activeConfig = {...config}; trigger = button;
      snapshots = buttons.map(button => ({button,nodes:[...button.childNodes].map(node=>node.cloneNode(true)),busy:button.getAttribute('aria-busy'),label:button.getAttribute('aria-label')}));
      state = 'pending'; paint(); return true;
    }
    function confirm() {
      if (destroyed || state !== 'pending') return false;
      if (document.hidden) { cancel(); return true; }
      state = 'success'; paint();
      if (activeConfig.bagPulseEnabled !== false && !reduced()) {
        counts.forEach(element=>element.classList.add('add-feedback-count'));
        pulseTimer=setTimeout(()=>counts.forEach(element=>element.classList.remove('add-feedback-count')),500);
      }
      effect=window.createLiveCartFeedback?.({buttons:[],burstEnabled:activeConfig.burstEnabled !== false,burstDurationMs:activeConfig.burstDurationMs});
      effect?.celebrate(trigger);
      const duration=Number(activeConfig.successDurationMs);
      resetTimer=setTimeout(cancel,Number.isFinite(duration)&&duration>=0?duration:1400);
      return true;
    }
    function onHidden() { if(document.hidden && state === 'success')cancel(); }
    document.addEventListener('visibilitychange',onHidden);
    return {
      begin, confirm, cancel, sync:paint, configure(value) { if(value && typeof value === 'object')config=value; },
      destroy() { if(destroyed)return;cancel();destroyed=true;document.removeEventListener('visibilitychange',onHidden); },
      get presentation() { return activeConfig ? {...activeConfig} : null; },
      get state() { return destroyed?'destroyed':state; }
    };
  };
})();
