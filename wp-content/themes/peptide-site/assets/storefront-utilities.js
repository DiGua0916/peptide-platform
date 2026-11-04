/* A single support launcher, driven by the store's configured WhatsApp URL. */
(function (global) {
 'use strict';
 function supportTarget(link, location) {
  try {
   const url = new URL(link.href, location.href);
   return url.origin === location.origin && url.pathname === location.pathname && ['#vc-order-support','#vc-contact-support'].includes(url.hash);
  } catch (_) { return false; }
 }
 function mountSupport(document, window) {
  const widget = document.querySelector('[data-vc-help]');
  const trigger = widget?.querySelector('.vc-help-float'), contact = widget?.querySelector('.vc-help-contact');
  if (!trigger || !contact) return null;
  let expanded = false, frame = 0;
  const setExpanded = value => {
   expanded = value;
   widget.classList.toggle('is-expanded', value);
   trigger.setAttribute('aria-expanded', String(value));
   trigger.setAttribute('aria-label', value ? 'Close WhatsApp support' : 'Open WhatsApp support');
   contact.setAttribute('aria-hidden', String(!value));
   contact.tabIndex = value ? 0 : -1;
  };
  const onTrigger = () => setExpanded(!expanded);
  const onClick = event => {
   const link = event.target.closest?.('a[href]');
   if (link && supportTarget(link, window.location)) {
    // The normal anchor still scrolls to the editable contact section.
    setExpanded(true); return;
   }
   if (!widget.contains(event.target)) setExpanded(false);
  };
  const onKey = event => {
   if (event.key !== 'Escape' || !expanded) return;
   const restore = widget.contains(document.activeElement);
   setExpanded(false);
   if (restore) trigger.focus({preventScroll:true});
  };
  const onHash = () => { if (['#vc-order-support','#vc-contact-support'].includes(window.location.hash)) setExpanded(true); };
  const navigation = document.querySelector('.pc-mobile-fixed');
  const update = () => {
   frame = 0;
   const box = navigation?.getBoundingClientRect();
   const visible = box && window.getComputedStyle(navigation).display !== 'none' && box.width > 0 && box.height > 0;
   widget.style.setProperty('--vc-help-nav-offset', visible ? Math.ceil(box.height) + 'px' : '0px');
  };
  const schedule = () => { if (!frame) frame = window.requestAnimationFrame(update); };
  const observer = navigation && typeof window.ResizeObserver === 'function' ? new window.ResizeObserver(schedule) : null;
  observer?.observe(navigation);
  trigger.addEventListener('click', onTrigger);
  document.addEventListener('click', onClick);
  document.addEventListener('keydown', onKey);
  window.addEventListener('hashchange', onHash);
  window.addEventListener('resize', schedule, {passive:true});
  window.addEventListener('pageshow', schedule);
  const destroy = () => {
   observer?.disconnect(); window.cancelAnimationFrame(frame);
   trigger.removeEventListener('click', onTrigger);
   document.removeEventListener('click', onClick);
   document.removeEventListener('keydown', onKey);
   window.removeEventListener('hashchange', onHash);
   window.removeEventListener('resize', schedule);
   window.removeEventListener('pageshow', schedule);
   window.removeEventListener('pagehide', onHide);
  };
  const onHide = event => { if (!event.persisted) destroy(); };
  window.addEventListener('pagehide', onHide);
  update(); onHash();
  return {setExpanded, destroy};
 }
 if (typeof module === 'object' && module.exports) module.exports = {supportTarget, mountSupport};
 if (global?.document) mountSupport(global.document, global);
})(typeof window !== 'undefined' ? window : null);
