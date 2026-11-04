/* Native cart-link handles opening and native vc-cart-count handles quantities. */
(function (global) {
 'use strict';
 function mountFloatingCart(document, window) {
  const link = document.querySelector('.vc-demo-cart-orb');
  if (!link) return null;
  const navigation = document.querySelector('.pc-mobile-fixed');
  let disposed = false;
  const label = () => {
   const count = link.querySelector('.vc-cart-count')?.textContent.trim();
   if (/^\d+$/.test(count || '') && Number.isSafeInteger(Number(count))) link.setAttribute('aria-label','Open shopping bag, ' + count + (count === '1' ? ' item' : ' items'));
  };
  const position = () => {
   const box = navigation?.getBoundingClientRect();
   const visible = box && box.width > 0 && box.height > 0 && window.getComputedStyle(navigation).display !== 'none';
   link.style.setProperty('--vc-cart-nav-offset',visible ? Math.ceil(box.height) + 'px' : '0px');
  };
  const observer = typeof window.MutationObserver === 'function' ? new window.MutationObserver(label) : null;
  observer?.observe(link,{subtree:true,childList:true,characterData:true});
  const resize = navigation && typeof window.ResizeObserver === 'function' ? new window.ResizeObserver(position) : null;
  resize?.observe(navigation);
  const destroy = () => {
   if (disposed) return; disposed = true;
   observer?.disconnect(); resize?.disconnect();
   window.removeEventListener('resize',position); window.removeEventListener('pagehide',onHide);
  };
  const onHide = event => { if (!event.persisted) destroy(); };
  window.addEventListener('resize',position,{passive:true}); window.addEventListener('pagehide',onHide);
  label(); position(); return {destroy};
 }
 if (typeof module === 'object' && module.exports) module.exports = {mountFloatingCart};
 if (global?.document) mountFloatingCart(global.document,global);
})(typeof window !== 'undefined' ? window : null);
