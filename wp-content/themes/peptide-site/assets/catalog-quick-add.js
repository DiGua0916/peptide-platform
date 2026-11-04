/* Add the exact SKU pictured on a homepage card through the existing Woo client. */
(function (global) {
    'use strict';
    const positiveId = value => Number.isSafeInteger(value) && value > 0;
    function verifiedCard(expected, response) {
        const card = response?.card;
        if (!expected || !positiveId(expected.productId) || !positiveId(expected.purchaseId) || !Number.isSafeInteger(expected.variationId) || expected.variationId < 0
            || response?.id !== expected.productId || card?.productId !== expected.productId || card.purchaseId !== expected.purchaseId || card.variationId !== expected.variationId
            || card.canAddOne !== true || (card.variationId ? card.purchaseId !== card.variationId : card.purchaseId !== card.productId)
            || !/^\d{1,16}$/.test(expected.price?.minor || '') || !Number.isSafeInteger(Number(expected.price.minor)) || !/^[A-Z]{3}$/.test(expected.price?.currency_code || '')
            || !Number.isInteger(expected.price?.currency_minor_unit) || expected.price.currency_minor_unit < 0 || expected.price.currency_minor_unit > 8
            || card.price?.minor !== expected.price.minor || card.price?.currency_code !== expected.price.currency_code || card.price?.currency_minor_unit !== expected.price.currency_minor_unit) return null;
        return card;
    }
    function cardUrl(base, expected, origin) {
        const url = new URL(base, origin);
        if (url.origin !== new URL(origin).origin || !/^https?:$/.test(url.protocol) || url.username || url.password || !positiveId(expected.productId)) throw new Error('Unavailable product');
        if (url.searchParams.has('rest_route')) url.searchParams.set('rest_route', url.searchParams.get('rest_route').replace(/\/?$/, '/') + expected.productId);
        else url.pathname = url.pathname.replace(/\/?$/, '/') + expected.productId;
        url.searchParams.set('card', 'true');
        if (expected.variationId > 0) url.searchParams.set('variation', String(expected.variationId));
        return url.href;
    }
    if (typeof module === 'object' && module.exports) module.exports = { verifiedCard, cardUrl };
    if (!global?.document) return;
    const document = global.document, config = global.VialCoveLiveCartConfig;
    if (!config || typeof global.VialCoveLiveCart?.addOne !== 'function' || typeof global.createLiveCartFeedback !== 'function') return;
    const records = new Map();
    let disposed = false;
    function init(button) {
        if (records.has(button)) return;
        const host = button.closest('.product-card-bottom'), fallback = host?.querySelector('[data-vc-catalog-add-fallback]'), notice = host?.querySelector('[data-vc-catalog-add-status]');
        if (!fallback || !notice) return;
        const data = button.dataset, expected = { productId: Number(data.productId), purchaseId: Number(data.purchaseId), variationId: Number(data.variationId),
            price: { minor: data.priceMinor, currency_code: data.currency, currency_minor_unit: Number(data.precision) } };
        const name = button.closest('.product-card')?.querySelector('h3')?.textContent.trim() || '';
        const feedbackConfig = global.vcLiveCommerce?.cartFeedback?.config || {};
        const feedback = global.createLiveCartFeedback({ buttons: [button], announce: notice, bagCountElements: [...document.querySelectorAll('.vc-cart-count')],
            timeoutMs: 55000, successMs: feedbackConfig.enabled === false ? 0 : feedbackConfig.successDurationMs || 1200,
            burstEnabled: feedbackConfig.enabled !== false && feedbackConfig.burstEnabled !== false,
            bagPulseEnabled: feedbackConfig.enabled !== false && feedbackConfig.bagPulseEnabled !== false, burstDurationMs: feedbackConfig.burstDurationMs,
            onSuccess: () => { notice.textContent = ''; notice.hidden = true; } });
        button.hidden = false; fallback.hidden = true;
        const click = async () => {
            if (disposed || button.disabled || feedback.state === 'pending' || !button.isConnected) return;
            let changed = false;
            await feedback.run({ quantity: 1, productName: name, triggerButton: button, submit: async ({ signal }) => {
                const controller = new AbortController(), abort = () => controller.abort();
                signal?.addEventListener('abort', abort, { once: true });
                const timer = setTimeout(abort, 8000);
                try {
                    if (signal?.aborted) throw new Error('Cancelled');
                    const headers = { Accept: 'application/json' }; if (config.wpNonce) headers['X-WP-Nonce'] = config.wpNonce;
                    const response = await global.fetch(cardUrl(config.recentProductApiUrl, expected, global.location.href), { credentials: 'same-origin', cache: 'no-store', headers, signal: controller.signal });
                    if (!response.ok) throw new Error('Could not verify this specification.');
                    const card = verifiedCard(expected, await response.json());
                    if (!card) { changed = true; throw new Error('This specification changed.'); }
                    if (disposed || !button.isConnected || signal?.aborted || button.dataset.productId !== String(expected.productId)
                        || button.dataset.purchaseId !== String(expected.purchaseId) || button.dataset.variationId !== String(expected.variationId)) throw new Error('Cancelled');
                    return global.VialCoveLiveCart.addOne(card, { signal });
                } finally { clearTimeout(timer); signal?.removeEventListener('abort', abort); }
            } });
            if (changed && button.isConnected) {
                button.hidden = true; fallback.hidden = false;
                notice.textContent = 'Price or availability changed. Select & add to review.';
            }
        };
        button.addEventListener('click', click);
        records.set(button, { feedback, click });
    }
    function boot() {
        for (const [button, record] of records) if (!button.isConnected) { record.feedback.destroy(); button.removeEventListener('click', record.click); records.delete(button); }
        document.querySelectorAll('[data-vc-catalog] [data-vc-catalog-add]').forEach(init);
    }
    const observer = new MutationObserver(boot);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true }); else boot();
    observer.observe(document.documentElement, { childList: true, subtree: true });
    global.addEventListener('pagehide', event => {
        if (event.persisted) return;
        disposed = true; observer.disconnect(); document.removeEventListener('DOMContentLoaded', boot);
        for (const [button, record] of records) { record.feedback.destroy(); button.removeEventListener('click', record.click); }
        records.clear();
    });
})(typeof window === 'undefined' ? null : window);
