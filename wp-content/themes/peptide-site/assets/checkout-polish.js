/* Native Woo checkout stays authoritative. Only the duplicate presentation is retired. */
(function () {
    'use strict';
    // Summary line under each native order-summary item: box quantity and the
    // verified vials-per-box count from peptide-core unit pricing. Text only.
    function packLine(item, spanish) {
        const quantity = Number(item?.quantity);
        if (!Number.isSafeInteger(quantity) || quantity < 1) return '';
        const vials = Number(item?.extensions?.peptide_core?.unit_pricing?.vialsPerSalesUnit);
        const boxes = spanish ? (quantity === 1 ? 'caja' : 'cajas') : (quantity === 1 ? 'box' : 'boxes');
        let line = (spanish ? 'Cant.: ' : 'Qty: ') + quantity + ' ' + boxes;
        if (Number.isSafeInteger(vials) && vials > 1) line += spanish ? ' · ' + vials + ' viales por caja' : ' · ' + vials + ' vials per box';
        return line;
    }
    function decodeName(value) {
        if (typeof value !== 'string') return '';
        return new DOMParser().parseFromString(value, 'text/html').body.textContent.replace(/\s+/g, ' ').trim();
    }
    // Native rows render in cart order; a name check prevents labelling a row with another item's data.
    function annotateItems(content, items, spanish) {
        const rows = content.querySelectorAll('.wc-block-components-order-summary-item');
        rows.forEach((row, index) => {
            const description = row.querySelector('.wc-block-components-order-summary-item__description');
            if (!description) return;
            let node = description.querySelector(':scope > .vc-summary-pack');
            const item = rows.length === items.length ? items[index] : null;
            const shown = row.querySelector('.wc-block-components-product-name')?.textContent.replace(/\s+/g, ' ').trim() || '';
            const text = item && shown && decodeName(item.name) === shown ? packLine(item, spanish) : '';
            if (!text) { node?.remove(); return; }
            if (!node) { node = document.createElement('p'); node.className = 'vc-summary-pack'; description.append(node); }
            if (node.textContent !== text) node.textContent = text;
        });
    }
    window.VialCoveCheckoutPack = { packLine, annotateItems };
    const page = document.querySelector('.vc-checkout-page');
    if (!page || !window.wp?.element || !window.VialCoveSavings) return;
    const mounted = new Map();
    let frame = 0;
    function reconcile() {
        frame = 0;
        for (const [node, root] of mounted) if (!node.isConnected) { root.unmount(); mounted.delete(node); }
        const summaryContent = page.querySelector('.wc-block-checkout__sidebar .wc-block-components-checkout-order-summary__content');
        const cart = window.wp?.data?.select?.('wc/store/cart')?.getCartData?.();
        if (summaryContent && Array.isArray(cart?.items)) annotateItems(summaryContent, cart.items, /^es(?:-|$)/i.test(document.documentElement.lang || ''));
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
    window.wp?.data?.subscribe?.(schedule, 'wc/store/cart');
    window.addEventListener('pageshow',()=> { cancelAnimationFrame(frame); frame=0; schedule(); });
    window.addEventListener('pagehide',()=> { cancelAnimationFrame(frame); frame=0; });
    schedule();
}());
