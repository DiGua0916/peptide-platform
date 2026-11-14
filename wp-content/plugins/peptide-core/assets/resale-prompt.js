/**
 * Dealer prompt (site 1 P1-4). After an add-to-cart that brings the item total to the threshold,
 * show one popup per visit that sends the shopper to WhatsApp with the cart in the message.
 * Popup priority (P0-2): it waits while another dialog (age gate, product) is open.
 * After closing, the block cart page shows a one-line link to the same chat.
 */
(function (global) {
    'use strict';
    const SEEN = 'pcResalePromptSeen';

    // Pure helpers (exported for tests).
    const itemsMinor = cart => {
        const v = Number(cart?.totals?.total_items);
        return Number.isFinite(v) ? v : NaN;
    };
    const crossed = (before, after, threshold) => Number.isFinite(after) && after >= threshold && !(Number.isFinite(before) && before >= threshold);
    function message(cart, cfg, cr) {
        const s = cfg.strings;
        const unit = Number.isInteger(cart?.totals?.currency_minor_unit) ? cart.totals.currency_minor_unit : 2;
        const sym = cart?.totals?.currency_symbol || '$';
        const money = minor => sym + (Number(minor) / Math.pow(10, unit)).toFixed(unit);
        const lines = (Array.isArray(cart?.items) ? cart.items : []).slice(0, 30).map(item => {
            const variant = Array.isArray(item.variation) ? item.variation.map(v => v.value).filter(Boolean).join(' ') : '';
            return '- ' + String(item.name || '').slice(0, 80) + (variant ? ' ' + variant.slice(0, 40) : '') + ' x ' + (Number(item.quantity) || 1);
        });
        const parts = [s.message, ...lines, s.total + ' ' + money(itemsMinor(cart))];
        if (cr && /^[A-Za-z0-9_-]{1,16}$/.test(cr)) parts.push(s.ref + ': cr=' + cr);
        return parts.join('\n');
    }
    const waUrl = (number, text) => 'https://wa.me/' + String(number).replace(/\D/g, '') + '?text=' + encodeURIComponent(text);
    if (typeof module !== 'undefined' && module.exports) module.exports = { itemsMinor, crossed, message, waUrl };

    if (!global || !global.document || !global.PCResalePrompt) return;
    const document = global.document, cfg = global.PCResalePrompt, s = cfg.strings;
    if (!cfg.whatsapp || !Number.isFinite(cfg.thresholdMinor)) return;
    const store = { get(k) { try { return global.sessionStorage.getItem(k); } catch (_) { return null; } }, set(k, v) { try { global.sessionStorage.setItem(k, v); } catch (_) { /* private mode */ } } };
    const cr = (() => { try { return new URL(global.location.href).searchParams.get('cr') || global.sessionStorage.getItem('pcResaleCr') || ''; } catch (_) { return ''; } })();
    if (cr) store.set('pcResaleCr', cr);
    let last = NaN, pending = null, cartNow = null;

    // Lane C tracker (P2-5): whatsapp_click with source dealer_popup / cart_link.
    function track(source) {
        try { document.dispatchEvent(new CustomEvent('vc:track', { detail: { type: 'whatsapp_click', data: { source } } })); } catch (_) { /* no tracker */ }
    }
    function otherDialogOpen() {
        return [...document.querySelectorAll('dialog[open], [role="dialog"][aria-modal="true"]')].some(el => !el.hasAttribute('data-pc-resale') && el.offsetParent !== null);
    }
    function show(cart) {
        if (store.get(SEEN)) return;
        if (otherDialogOpen()) { pending = cart; return; }
        pending = null; store.set(SEEN, 'shown');
        const dialog = document.createElement('dialog');
        dialog.className = 'pc-resale'; dialog.setAttribute('data-pc-resale', ''); dialog.setAttribute('aria-labelledby', 'pc-resale-title');
        const href = waUrl(cfg.whatsapp, message(cart, cfg, cr));
        dialog.innerHTML = '<button type="button" class="pc-resale__x" data-close></button><p class="pc-resale__free"></p><h2 id="pc-resale-title"></h2><p class="pc-resale__body"></p><ul></ul>' +
            '<a class="pc-resale__wa" target="_blank" rel="noopener"></a><button type="button" class="pc-resale__continue" data-close></button>';
        dialog.querySelector('.pc-resale__x').setAttribute('aria-label', s.close); dialog.querySelector('.pc-resale__x').textContent = '×';
        dialog.querySelector('.pc-resale__free').textContent = '🚚 ' + s.free;
        dialog.querySelector('h2').textContent = s.title;
        dialog.querySelector('.pc-resale__body').textContent = s.body;
        s.points.forEach(p => { const li = document.createElement('li'); li.textContent = p; dialog.querySelector('ul').append(li); });
        const wa = dialog.querySelector('.pc-resale__wa'); wa.textContent = s.cta; wa.href = href;
        dialog.querySelector('.pc-resale__continue').textContent = s.continue;
        const close = () => { if (dialog.open) dialog.close(); dialog.remove(); store.set(SEEN, 'closed'); cartLine(); };
        dialog.querySelectorAll('[data-close]').forEach(b => b.addEventListener('click', close));
        wa.addEventListener('click', () => { track('dealer_popup'); setTimeout(close, 0); });
        dialog.addEventListener('cancel', event => { event.preventDefault(); close(); });
        document.body.append(dialog);
        try { dialog.showModal(); } catch (_) { dialog.setAttribute('open', ''); }
    }
    function cartLine() {
        const cart = cartNow;
        const root = document.querySelector('.wc-block-cart');
        if (!root || !cart || !(itemsMinor(cart) >= cfg.thresholdMinor) || !store.get(SEEN)) { document.querySelector('[data-pc-resale-line]')?.remove(); return; }
        let line = root.querySelector('[data-pc-resale-line]');
        if (!line) {
            line = document.createElement('a'); line.setAttribute('data-pc-resale-line', ''); line.className = 'pc-resale-line'; line.target = '_blank'; line.rel = 'noopener';
            line.textContent = s.cartLine;
            line.addEventListener('click', () => track('cart_link'));
            (root.querySelector('.wc-block-cart__submit') || root).insertAdjacentElement('afterend', line);
        }
        line.href = waUrl(cfg.whatsapp, message(cart, cfg, cr));
    }
    function seenCart(cart, fromAdd) {
        const now = itemsMinor(cart);
        cartNow = cart;
        if (fromAdd && crossed(last, now, cfg.thresholdMinor)) show(cart);
        last = now;
        cartLine();
    }
    // Storefront drawer / product dialog adds.
    document.addEventListener('vc:cart-updated', event => { const d = event.detail; if (d && d.cart && !d.stale) seenCart(d.cart, !!d.mutation); });
    // Block cart page: quantity changes and the initial load.
    const data = global.wp?.data;
    if (data?.select && data?.subscribe) {
        const read = () => { const c = data.select('wc/store/cart')?.getCartData?.(); if (c && Array.isArray(c.items)) seenCart(c, Number.isFinite(last)); };
        data.subscribe(read, 'wc/store/cart'); read();
    }
    // A popup that waited for another dialog shows once that dialog closes.
    setInterval(() => { if (pending && !otherDialogOpen()) show(pending); }, 800);
})(typeof window === 'undefined' ? null : window);
