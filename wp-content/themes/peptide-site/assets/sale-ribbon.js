/* Display only: Woo's server-formatted selected price remains the source. */
(() => {
  'use strict';
  const product = document.querySelector('.single-product div.product');
  const gallery = product?.querySelector('.woocommerce-product-gallery');
  const price = product?.querySelector('.summary > .vc-detail-price');
  if (!gallery || !price) return;
  let ribbon;
  const sync = () => {
    const badge = price.querySelector('.vc-sale-badge');
    const label = badge?.textContent.trim();
    const valid = /^(?:[1-9]\d?|100)% off$/i.test(label || '');
    if (!valid) {
      ribbon?.remove();
      ribbon = null;
      product.classList.remove('vc-detail-has-offer');
      return;
    }
    if (!ribbon) {
      ribbon = document.createElement('span');
      ribbon.className = 'vc-sale-ribbon vc-sale-ribbon--detail';
      ribbon.setAttribute('aria-hidden', 'true');
      ribbon.append(document.createElement('span'));
      gallery.append(ribbon);
    }
    ribbon.firstElementChild.textContent = label.toUpperCase();
    product.classList.add('vc-detail-has-offer');
  };
  // Mirrors initial/simple pricing and found/reset/hide_variation updates from
  // product-options without maintaining a second selected-variation state.
  new MutationObserver(sync).observe(price, {childList: true, subtree: true, characterData: true});
  sync();
})();
