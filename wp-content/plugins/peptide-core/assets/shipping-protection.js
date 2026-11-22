/**
 * Shipping protection row for the block cart and checkout (site 1 P0-5).
 *
 * Shows one row under shipping: a checkbox (on by default), the label, the crossed-out
 * reference price and the real price. Toggling it sends a Store API cart extension update
 * (namespace from the cart data), so WooCommerce recalculates and the native totals refresh.
 * The native fee row with the same label is hidden so the fee is not listed twice.
 */
(function (global) {
    'use strict';
    if (!global || !global.document) return;
    const document = global.document;
    const KEY = 'wc/store/cart';
    const ROW = 'data-pc-ship-protect';
    let busy = false;
    let frame = 0;

    const money = (minor, cart) => {
        const unit = Number.isInteger(cart?.totals?.currency_minor_unit) ? cart.totals.currency_minor_unit : 2;
        const symbol = typeof cart?.totals?.currency_symbol === 'string' ? cart.totals.currency_symbol : '$';
        return symbol + (Number(minor) / Math.pow(10, unit)).toFixed(unit);
    };
    const info = cart => {
        const p = cart?.extensions?.peptide_core?.shipping_protection;
        return p && p.enabled === true && typeof p.namespace === 'string' ? p : null;
    };
    const setText = (node, value) => { if (node && node.textContent !== value) node.textContent = value; }; // no-op writes would loop the observer
    const cartData = () => global.wp?.data?.select?.(KEY)?.getCartData?.();

    function hideNativeFee(root, label) {
        root.querySelectorAll('.wc-block-components-totals-fees, .wc-block-components-totals-item').forEach(item => {
            if (item.hasAttribute(ROW)) return;
            const name = item.querySelector('.wc-block-components-totals-item__label');
            if (name && name.textContent.trim() === label && !item.hidden) item.hidden = true;
        });
    }

    function render() {
        frame = 0;
        const cart = cartData();
        const p = info(cart);
        document.querySelectorAll('.wc-block-cart, .wc-block-checkout').forEach(root => {
            let row = root.querySelector('[' + ROW + ']');
            if (!p) { row?.remove(); return; }
            const anchor = root.querySelector('.wc-block-components-totals-shipping') || root.querySelector('.wc-block-components-totals-footer-item');
            if (!anchor) return;
            if (!row) {
                row = document.createElement('div');
                row.setAttribute(ROW, '');
                row.className = 'wc-block-components-totals-item pc-ship-protect';
                row.innerHTML = '<label class="pc-ship-protect__label"><input type="checkbox" class="pc-ship-protect__box"><span class="pc-ship-protect__text"><span class="pc-ship-protect__name"></span><small class="pc-ship-protect__note"></small></span></label>' +
                    '<span class="pc-ship-protect__price"><s class="pc-ship-protect__ref"></s> <span class="pc-ship-protect__now"></span></span>';
                row.querySelector('input').addEventListener('change', event => toggle(event.target, p.namespace));
                anchor.insertAdjacentElement(anchor.classList.contains('wc-block-components-totals-footer-item') ? 'beforebegin' : 'afterend', row);
            }
            const box = row.querySelector('input');
            if (!busy) box.checked = p.chosen === true;
            box.disabled = busy;
            setText(row.querySelector('.pc-ship-protect__name'), p.label);
            const ref = row.querySelector('.pc-ship-protect__ref');
            setText(ref, p.reference_minor > p.price_minor ? money(p.reference_minor, cart) : '');
            // P1-5: Clear fulfillment guarantee copy instead of vague fee wording.
            setText(row.querySelector('.pc-ship-protect__note'), 'Free reshipment if lost or damaged in transit');
            setText(row.querySelector('.pc-ship-protect__now'), money(p.price_minor, cart));
            row.classList.toggle('is-off', p.chosen !== true);
            hideNativeFee(root, p.label);
        });
    }

    async function toggle(box, namespace) {
        const update = global.wc?.blocksCheckout?.extensionCartUpdate;
        if (busy || typeof update !== 'function') { box.checked = !box.checked; return; }
        busy = true; box.disabled = true;
        try { await update({ namespace, data: { on: box.checked === true } }); }
        catch (_) { box.checked = !box.checked; }
        finally { busy = false; schedule(); }
    }

    function schedule() { if (!frame) frame = global.requestAnimationFrame ? global.requestAnimationFrame(render) : setTimeout(render, 16); }

    function start() {
        schedule();
        global.wp?.data?.subscribe?.(schedule, KEY);
        if (global.MutationObserver) new global.MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true }); else start();

    if (typeof module !== 'undefined' && module.exports) module.exports = { info, money };
})(typeof window === 'undefined' ? null : window);
