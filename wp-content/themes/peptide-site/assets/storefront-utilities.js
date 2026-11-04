/* A single support launcher, driven by the store's configured WhatsApp URL. */
(function (global) {
 'use strict';
 function supportTarget(link, location) {
  try {
   const url = new URL(link.href, location.href);
   return url.origin === location.origin && url.pathname === location.pathname && ['#vc-questions','#vc-contact-support'].includes(url.hash);
  } catch (_) { return false; }
 }
 /** A reading region, not a one-pixel intersection or a URL hash, owns the disclosure. */
 function faqInReadingZone(rect, viewportHeight, headerBottom = 0) {
  if (!rect || ![rect.top,rect.bottom,rect.width,viewportHeight,headerBottom].every(Number.isFinite) || rect.width <= 0 || rect.bottom <= rect.top || viewportHeight <= 0) return false;
  const top = Math.max(0, Math.min(viewportHeight, headerBottom)), usable = viewportHeight - top;
  if (usable <= 0) return false;
  const readingLine = top + usable * .35;
  const visibleHeight = Math.max(0, Math.min(viewportHeight, rect.bottom) - Math.max(top, rect.top));
  return rect.top <= readingLine && rect.bottom > readingLine && visibleHeight >= Math.min(120, usable * .2);
 }
 function whatsappTarget(link, location) {
  try {
   const url = new URL(link.href, location.href);
   return url.protocol === 'https:' && url.hostname.toLowerCase() === 'wa.me' && !url.username && !url.password && !url.port && !url.hash && /^\/[1-9][0-9]{6,14}\/?$/.test(url.pathname);
  } catch (_) { return false; }
 }
 function mountSupport(document, window) {
  const widget = document.querySelector('[data-vc-help]');
  const trigger = widget?.querySelector('.vc-help-float'), contact = widget?.querySelector('.vc-help-contact');
  if (!trigger || !contact) return null;
  const faq = document.querySelector('#vc-questions'), navigation = document.querySelector('.pc-mobile-fixed');
  const header = document.querySelector('[data-vc-demo-header]');
  let expanded = false, inside = false, dismissed = false, near = true, disposed = false, frame = 0;
  const renderExpanded = value => {
   expanded = value;
   widget.classList.toggle('is-expanded', value);
   trigger.setAttribute('aria-expanded', String(value));
   trigger.setAttribute('aria-label', value ? 'Close WhatsApp support' : 'Open WhatsApp support');
   contact.setAttribute('aria-hidden', String(!value));
   contact.tabIndex = value ? 0 : -1;
  };
  const reading = () => {
   if (!faq || window.getComputedStyle(faq).display === 'none') return false;
   const height = window.innerHeight || document.documentElement?.clientHeight || 0;
   let headerBottom = 0;
   // The published layout's section is sticky; its inner header can be relative.
   for (let node = header; node; node = node.parentElement) {
    if (!['sticky','fixed'].includes(window.getComputedStyle(node).position)) continue;
    const box = node.getBoundingClientRect();
    if (box.width > 0 && box.bottom > 0 && box.top < height) headerBottom = box.bottom;
    break;
   }
   return faqInReadingZone(faq.getBoundingClientRect(), height, headerBottom);
  };
  const syncRegion = () => {
   const current = reading();
   if (current !== inside) { inside = current; dismissed = false; }
   renderExpanded(inside && !dismissed);
   return inside;
  };
  const setExpanded = value => {
   if (disposed || !syncRegion()) return;
   dismissed = !value;
   renderExpanded(!!value);
  };
  const dismiss = () => { if (inside) dismissed = true; renderExpanded(false); };
  const onTrigger = () => {
   const wasExpanded = expanded;
   if (syncRegion()) { dismissed = wasExpanded; renderExpanded(!wasExpanded); }
   else if (whatsappTarget(contact, window.location)) contact.click();
  };
  const onClick = event => {
   const link = event.target.closest?.('a[href]');
   if (link && supportTarget(link, window.location)) {
    // Native navigation may scroll after this event. Geometry is checked next frame.
    schedule(); return;
   }
   if (expanded && !widget.contains(event.target)) dismiss();
  };
  const onKey = event => {
   if (event.key !== 'Escape' || !expanded) return;
   const restore = widget.contains(document.activeElement);
   dismiss();
   if (restore) trigger.focus({preventScroll:true});
  };
  const update = () => {
   frame = 0;
   if (disposed) return;
   const box = navigation?.getBoundingClientRect();
   const visible = box && window.getComputedStyle(navigation).display !== 'none' && box.width > 0 && box.height > 0;
   widget.style.setProperty('--vc-help-nav-offset', visible ? Math.ceil(box.height) + 'px' : '0px');
   syncRegion();
  };
  const schedule = () => { if (!disposed && !frame) frame = window.requestAnimationFrame(update); };
  const onScroll = () => { if (near || inside) schedule(); };
  const observed = [...new Set([navigation,faq,header].filter(Boolean))];
  const observer = observed.length && typeof window.ResizeObserver === 'function' ? new window.ResizeObserver(schedule) : null;
  observed.forEach(node => observer?.observe(node));
  const intersection = faq && typeof window.IntersectionObserver === 'function' ? new window.IntersectionObserver(entries => {
   if (disposed) return;
   for (const entry of entries) if (entry.target === faq) near = entry.isIntersecting;
   schedule();
  }, {root:null,rootMargin:'15% 0px 15% 0px',threshold:0}) : null;
  intersection?.observe(faq);
  trigger.addEventListener('click', onTrigger);
  document.addEventListener('click', onClick);
  document.addEventListener('keydown', onKey);
  window.addEventListener('hashchange', schedule);
  if (faq) window.addEventListener('scroll', onScroll, {passive:true});
  window.addEventListener('resize', schedule, {passive:true});
  window.addEventListener('pageshow', schedule);
  const destroy = () => {
   if (disposed) return;
   disposed = true;
   observer?.disconnect(); intersection?.disconnect(); window.cancelAnimationFrame(frame);
   trigger.removeEventListener('click', onTrigger);
   document.removeEventListener('click', onClick);
   document.removeEventListener('keydown', onKey);
   window.removeEventListener('hashchange', schedule);
   window.removeEventListener('scroll', onScroll);
   window.removeEventListener('resize', schedule);
   window.removeEventListener('pageshow', schedule);
   window.removeEventListener('pagehide', onHide);
  };
  const onHide = event => { if (!event.persisted) destroy(); };
  window.addEventListener('pagehide', onHide);
  update();
  return {setExpanded, destroy};
 }
 if (typeof module === 'object' && module.exports) module.exports = {supportTarget, faqInReadingZone, whatsappTarget, mountSupport};
 if (global?.document) mountSupport(global.document, global);
})(typeof window !== 'undefined' ? window : null);
