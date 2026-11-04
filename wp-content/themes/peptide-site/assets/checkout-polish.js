/* Native Woo checkout stays authoritative. Only the duplicate presentation is retired. */
(function () {
    'use strict';
    const page = document.querySelector('.vc-checkout-page');
    if (!page || !window.wp?.element || !window.VialCoveSavings) return;
    const mounted = new Map();
    let frame = 0;
    function reconcile() {
        frame = 0;
        for (const [node, root] of mounted) if (!node.isConnected) { root.unmount(); mounted.delete(node); }
        const summary = page.querySelector('.wc-block-checkout__sidebar .wp-block-woocommerce-checkout-order-summary-block');
        const title = summary?.querySelector('.wc-block-components-checkout-order-summary__title');
        const content = summary?.querySelector('.wc-block-components-checkout-order-summary__content');
        const price = title?.querySelector('.wc-block-components-checkout-order-summary__title-price');
        if (!title || !content || !price || !price.textContent.trim()) { page.classList.remove('vc-checkout-summary-ready'); return; }
        summary.classList.add('vc-order-summary-permanent');
        title.removeAttribute('role'); title.removeAttribute('tabindex'); title.removeAttribute('aria-expanded'); title.removeAttribute('aria-controls');
        content.removeAttribute('aria-hidden');
        const total = content.querySelector('.wc-block-components-totals-footer-item');
        const label = total?.querySelector('.wc-block-components-totals-item__label')?.textContent.trim() || 'Total';
        let caption = title.querySelector('.vc-order-total-caption');
        if (!caption) { caption = document.createElement('span'); caption.className='vc-order-total-caption'; price.before(caption); }
        if (caption.textContent !== label) caption.textContent=label;
        const accessible = label + ' ' + price.textContent.trim();
        if (price.getAttribute('aria-label') !== accessible) price.setAttribute('aria-label', accessible);
        page.classList.add('vc-checkout-summary-ready');
        let host = content.querySelector('[data-vc-footer-savings]');
        if (!host) {
            host=document.createElement('div'); host.dataset.vcFooterSavings=''; content.append(host);
            const root=wp.element.createRoot(host); root.render(wp.element.createElement(window.VialCoveSavings.Footer)); mounted.set(host,root);
        }
    }
    const schedule = () => { if (!frame) frame=requestAnimationFrame(reconcile); };
    page.addEventListener('click',event=> {
        if (event.target.closest('.vc-order-summary-permanent .wc-block-components-checkout-order-summary__title')) {
            event.preventDefault(); event.stopImmediatePropagation();
        }
    },true);
    new MutationObserver(schedule).observe(page,{childList:true,subtree:true,characterData:true});
    window.addEventListener('pageshow',()=> { cancelAnimationFrame(frame); frame=0; schedule(); });
    window.addEventListener('pagehide',()=> { cancelAnimationFrame(frame); frame=0; });
    schedule();
}());
