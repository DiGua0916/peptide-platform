/**
 * Checkout assist for the block checkout (site 1 P1-1 / P1-6, signed off 2026-11-14).
 *
 * - Saves what the shopper types (contact and shipping fields, never payment data) in this browser for
 *   7 days, and fills it back into empty fields after a refresh or a later visit, with a short notice.
 * - US ZIP fills the state (offline ZIP prefix table). The street and city stay the shopper's own text.
 * - Phone: a note saying what it is for, and inline validation for a 10-digit US number.
 * - The place-order button reads "Pay $X", with "You save $X" under it when there are savings.
 * - After a payment error, a line under it says the details are kept and other payment methods exist.
 * Woo stays authoritative: values go through the wc/store/cart data store actions, never into raw inputs.
 */
(function (global) {
    'use strict';
    const DRAFT = 'pcCheckoutDraft';
    const MAX_AGE = 7 * 24 * 3600 * 1000;
    const FIELDS = ['first_name', 'last_name', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone'];

    // US ZIP prefix (first 3 digits) ranges → state. Exceptions listed before their wider range.
    const ZIP3 = [
        [5, 5, 'NY'], [10, 27, 'MA'], [28, 29, 'RI'], [30, 38, 'NH'], [39, 49, 'ME'], [55, 55, 'MA'], [50, 59, 'VT'],
        [60, 69, 'CT'], [70, 89, 'NJ'], [90, 99, 'AE'], [100, 149, 'NY'], [150, 196, 'PA'], [197, 199, 'DE'], [201, 201, 'VA'],
        [200, 205, 'DC'], [206, 219, 'MD'], [220, 246, 'VA'], [247, 268, 'WV'], [270, 289, 'NC'], [290, 299, 'SC'],
        [300, 319, 'GA'], [398, 399, 'GA'], [340, 340, 'AA'], [320, 349, 'FL'], [350, 369, 'AL'], [370, 385, 'TN'], [386, 397, 'MS'],
        [400, 427, 'KY'], [430, 459, 'OH'], [460, 479, 'IN'], [480, 499, 'MI'], [500, 528, 'IA'], [530, 549, 'WI'],
        [550, 567, 'MN'], [569, 569, 'DC'], [570, 577, 'SD'], [580, 588, 'ND'], [590, 599, 'MT'], [600, 629, 'IL'],
        [630, 658, 'MO'], [660, 679, 'KS'], [680, 693, 'NE'], [700, 714, 'LA'], [716, 729, 'AR'], [733, 733, 'TX'],
        [730, 749, 'OK'], [750, 799, 'TX'], [885, 885, 'TX'], [800, 816, 'CO'], [820, 831, 'WY'], [832, 838, 'ID'],
        [840, 847, 'UT'], [850, 865, 'AZ'], [870, 884, 'NM'], [889, 898, 'NV'], [900, 961, 'CA'], [962, 966, 'AP'],
        [967, 968, 'HI'], [970, 979, 'OR'], [980, 994, 'WA'], [995, 999, 'AK'],
    ];
    function stateForZip(zip) {
        const m = /^(\d{3})\d{2}(?:-?\d{4})?$/.exec(String(zip || '').trim());
        if (!m) return '';
        const p = Number(m[1]);
        const hit = ZIP3.find(([lo, hi]) => p >= lo && p <= hi);
        return hit ? hit[2] : '';
    }
    function phoneOk(value) {
        const d = String(value || '').replace(/\D/g, '');
        return d.length === 10 || (d.length === 11 && d[0] === '1');
    }
    function cleanDraft(raw, now) {
        if (!raw || typeof raw !== 'object' || !(now - Number(raw.at) < MAX_AGE)) return null;
        const out = { at: Number(raw.at), email: typeof raw.email === 'string' ? raw.email.slice(0, 190) : '', shipping: {} };
        FIELDS.forEach(f => { const v = raw.shipping?.[f]; if (typeof v === 'string' && v.length <= 200) out.shipping[f] = v; });
        return out;
    }
    function payLabel(cart) {
        const t = cart?.totals; const unit = Number.isInteger(t?.currency_minor_unit) ? t.currency_minor_unit : 2;
        const v = Number(t?.total_price);
        if (!Number.isFinite(v)) return '';
        return 'Pay ' + (t.currency_prefix || t.currency_symbol || '$') + (v / Math.pow(10, unit)).toFixed(unit);
    }
    function helpText(others) {
        const list = (Array.isArray(others) ? others : []).filter(Boolean);
        if (!list.length) return 'Your details are saved. Please try again.';
        const named = list.length === 1 ? list[0] : list.slice(0, -1).join(', ') + ' or ' + list[list.length - 1];
        return 'Your details are saved. Try again, or pay another way: ' + named + '.';
    }
    if (typeof module !== 'undefined' && module.exports) module.exports = { stateForZip, phoneOk, cleanDraft, payLabel, helpText };

    if (!global || !global.document) return;
    const document = global.document, data = global.wp?.data, KEY = 'wc/store/cart';
    if (!data?.select || !data?.dispatch || !data?.subscribe) return;
    const ls = { get() { try { return JSON.parse(global.localStorage.getItem(DRAFT) || 'null'); } catch (_) { return null; } }, set(v) { try { global.localStorage.setItem(DRAFT, JSON.stringify(v)); } catch (_) { /* storage off */ } } };
    const select = () => data.select(KEY), actions = () => data.dispatch(KEY);
    let restored = false, zipFilledFor = '', lastSaved = '';
    // The data store keeps the live (being typed) addresses in camelCase on getCustomerData().
    function addresses() {
        const st = select(); const c = st?.getCustomerData?.() || st?.getCartData?.() || null;
        if (!c) return null;
        return { ship: c.shippingAddress || c.shipping_address || {}, bill: c.billingAddress || c.billing_address || {} };
    }

    function save() {
        const a = addresses(); if (!a) return;
        const { ship, bill } = a;
        const draft = { at: Date.now(), email: bill.email || '', shipping: {} };
        FIELDS.forEach(f => { if (ship[f]) draft.shipping[f] = String(ship[f]); });
        // Save whenever the entered details change (not on a debounce: checkout's DOM keeps changing, which would postpone it forever).
        const sig = JSON.stringify([draft.email, draft.shipping]);
        if (sig === lastSaved || !(draft.email || Object.keys(draft.shipping).length)) return;
        lastSaved = sig; ls.set(draft);
    }
    function restore() {
        if (restored) return; const a = addresses(); if (!a || !document.querySelector('.wc-block-checkout')) return;
        restored = true;
        const draft = cleanDraft(ls.get(), Date.now()); if (!draft) return;
        const { ship, bill } = a;
        const patch = {};
        FIELDS.forEach(f => { if (!ship[f] && draft.shipping[f]) patch[f] = draft.shipping[f]; });
        let changed = false;
        if (Object.keys(patch).length) { actions().setShippingAddress?.({ ...ship, ...patch }); changed = true; }
        if (!bill.email && draft.email) { actions().setBillingAddress?.({ ...bill, email: draft.email }); changed = true; }
        if (changed) notice('We saved what you entered. Check it and finish your order.');
    }
    function notice(text) {
        const form = document.querySelector('.wc-block-checkout__form'); if (!form || form.querySelector('[data-pc-assist-note]')) return;
        const p = document.createElement('p'); p.setAttribute('data-pc-assist-note', ''); p.className = 'pc-assist-note'; p.textContent = '✓ ' + text;
        form.prepend(p);
    }
    function zipToState() {
        const ship = addresses()?.ship; if (!ship) return;
        if ((ship.country || 'US') !== 'US') return;
        const st = stateForZip(ship.postcode);
        if (!st || st === ship.state || zipFilledFor === ship.postcode) return;
        zipFilledFor = ship.postcode;
        actions().setShippingAddress?.({ ...ship, state: st });
    }
    function phone() {
        const input = document.querySelector('#shipping-phone, #billing-phone'); if (!input) return;
        const wrap = input.closest('.wc-block-components-text-input') || input.parentElement;
        let note = wrap.parentElement.querySelector('[data-pc-phone-note]');
        if (!note) {
            note = document.createElement('p'); note.setAttribute('data-pc-phone-note', ''); note.className = 'pc-phone-note';
            wrap.insertAdjacentElement('afterend', note);
            input.addEventListener('blur', () => phoneState(input, note));
            input.addEventListener('input', () => { if (note.classList.contains('is-error')) phoneState(input, note); });
        }
        if (!note.textContent) note.textContent = 'For delivery updates from FedEx';
    }
    function phoneState(input, note) {
        const bad = input.value.trim() !== '' && !phoneOk(input.value);
        note.classList.toggle('is-error', bad);
        note.textContent = bad ? 'Enter a 10-digit U.S. phone number' : 'For delivery updates from FedEx';
    }
    function payButton() {
        const btn = document.querySelector('.wc-block-components-checkout-place-order-button'); if (!btn) return;
        const label = payLabel(select()?.getCartData?.());
        const text = btn.querySelector('.wc-block-components-button__text') || btn;
        if (label && text.textContent !== label && !btn.classList.contains('wc-block-components-button--loading')) text.textContent = label;
        let save = btn.parentElement.parentElement.querySelector('[data-pc-pay-save]');
        const amount = global.VialCoveCheckoutSaving;
        if (amount) {
            if (!save) { save = document.createElement('p'); save.setAttribute('data-pc-pay-save', ''); save.className = 'pc-pay-save'; btn.parentElement.insertAdjacentElement('afterend', save); }
            const line = 'You save ' + amount + ' on this order';
            if (save.textContent !== line) save.textContent = line; // unchanged writes would retrigger the observer
        } else save?.remove();
    }
    // Labels of the payment options the shopper has not picked, as the checkout shows them.
    function otherPayments() {
        return [...document.querySelectorAll('.wc-block-checkout__payment-method .wc-block-components-radio-control__option')]
            .filter(opt => !opt.querySelector('input:checked'))
            .map(opt => (opt.querySelector('.wc-block-components-radio-control__label, .wc-block-components-payment-method-label') || opt).textContent.trim().replace(/\s+/g, ' ').slice(0, 40))
            .filter(Boolean).slice(0, 3);
    }
    function paymentHelp() {
        document.querySelectorAll('.wc-block-checkout .wc-block-components-notice-banner.is-error').forEach(banner => {
            if (banner.querySelector('[data-pc-pay-help]')) return;
            const p = document.createElement('p'); p.setAttribute('data-pc-pay-help', ''); p.className = 'pc-pay-help';
            p.textContent = helpText(otherPayments());
            (banner.querySelector('.wc-block-components-notice-banner__content') || banner).append(p);
        });
    }
    let frame = 0;
    function tick() {
        frame = 0;
        restore(); zipToState(); phone(); payButton(); paymentHelp(); save();
    }
    const schedule = () => { if (!frame) frame = setTimeout(tick, 60); }; // timer, not rAF: also runs in a background tab
    function start() {
        if (!document.querySelector('.wc-block-checkout')) { setTimeout(start, 300); return; }
        data.subscribe(schedule, KEY);
        new global.MutationObserver(schedule).observe(document.querySelector('.wc-block-checkout'), { childList: true, subtree: true });
        schedule();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true }); else start();
})(typeof window === 'undefined' ? null : window);
