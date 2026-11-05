/* Keep the original native campaign presenter with the dialog's sticky navigation. */
(function (global) {
 'use strict';
 function mountOfferFollow(root, dialog) {
  if (!root || !dialog || !dialog.contains(root) || !root.classList.contains('vc-product-experience')) return null;
  const heading = root.querySelector('.vc-dialog-heading');
  const offers = root.querySelector('.vc-dialog-offers');
  const toolbar = dialog.querySelector(':scope > .vc-home-quick__toolbar');
  if (!heading || !offers || !toolbar || !heading.contains(offers)) return null;
  let destroyed = false;
  toolbar.append(offers);
  toolbar.classList.add('vc-offers-follow');
  return {
   root, dialog, offers, toolbar,
   destroy() {
    if (destroyed) return;
    destroyed = true;
    // Return the same node for native cached-product cleanup on the next open.
    if (offers.parentElement === toolbar) heading.prepend(offers);
    toolbar.classList.remove('vc-offers-follow');
   }
  };
 }
 function bindOfferFollow(document, window) {
  const owners = new WeakMap(), active = new Set();
  let disposed = false;
  const onPresented = event => {
   if (disposed) return;
   const {root,dialog} = event.detail || {};
   if (!root || !dialog || !dialog.contains(root)) return;
   const previous = owners.get(dialog);
   if (previous?.root === root && previous.offers.parentElement === previous.toolbar) return;
   previous?.release();
   const owner = mountOfferFollow(root,dialog);
   if (!owner) return;
   const release = () => {
    dialog.removeEventListener('close',release);
    if (owners.get(dialog) === owner) owners.delete(dialog);
    active.delete(owner); owner.destroy();
   };
   owner.release = release; owners.set(dialog,owner); active.add(owner);
   dialog.addEventListener('close',release,{once:true});
  };
  const onExperienceDisposed = event => {
   const owner = owners.get(event.detail?.dialog);
   if (owner && owner.root === event.detail?.root) owner.release();
  };
  const destroy = () => {
   if (disposed) return;
   disposed = true;
   document.removeEventListener('vc:product-dialog-selection',onPresented);
   document.removeEventListener('vc:product-presented',onPresented);
   document.removeEventListener('vc:product-experience-disposed',onExperienceDisposed);
   window.removeEventListener('pagehide',onHide);
   [...active].forEach(owner => owner.release());
  };
  const onHide = event => { if (!event.persisted) destroy(); };
  document.addEventListener('vc:product-dialog-selection',onPresented);
  document.addEventListener('vc:product-presented',onPresented);
  document.addEventListener('vc:product-experience-disposed',onExperienceDisposed);
  window.addEventListener('pagehide',onHide);
  return {destroy};
 }
 if (typeof module === 'object' && module.exports) module.exports = {mountOfferFollow,bindOfferFollow};
 if (global?.document) bindOfferFollow(global.document,global);
})(typeof window !== 'undefined' ? window : null);
