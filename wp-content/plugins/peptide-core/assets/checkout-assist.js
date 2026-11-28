/**
 * Checkout assist for WooCommerce Block Checkout:
 * - P1-1: Saves contact, shipping, billing, active coupons & order note for 30 days (aligned with vcBagSnapshot),
 *         restores fields + re-applies dropped coupons on revisit, and offers ?pc_resume= external browser handoff.
 * - P1-4: Offline sharded 40,979-ZIP US directory auto-populates BOTH City and State as soon as 5-digit ZIP is typed.
 * - P0-1: Mobile sticky bottom Pay bar (<783px) synced with native Place Order button.
 * - P0-6: Emergency WhatsApp Specialist rescue CTA inside payment error banners with pre-filled cart & address details.
 */
(function (global) {
    'use strict';
    const DRAFT = 'pcCheckoutDraft';
    const MAX_AGE = 30 * 24 * 3600 * 1000; // 30 days (aligned with vcBagSnapshot)
    const FIELDS = ['first_name', 'last_name', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone'];

    // Fallback 3-digit US ZIP prefix -> State table (used synchronously while shard JSON loads)
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
        const out = {
            at: Number(raw.at),
            email: typeof raw.email === 'string' ? raw.email.slice(0, 190) : '',
            shipping: {},
            billing: {},
            coupons: Array.isArray(raw.coupons) ? raw.coupons.filter(c => typeof c === 'string' && c.length <= 64).slice(0, 5) : [],
            note: typeof raw.note === 'string' ? raw.note.slice(0, 500) : '',
        };
        FIELDS.forEach(f => {
            const sv = raw.shipping?.[f];
            if (typeof sv === 'string' && sv.length <= 200) out.shipping[f] = sv;
            const bv = raw.billing?.[f];
            if (typeof bv === 'string' && bv.length <= 200) out.billing[f] = bv;
        });
        return out;
    }
    function formatCartTotal(cart) {
        const t = cart?.totals;
        const unit = Number.isInteger(t?.currency_minor_unit) ? t.currency_minor_unit : 2;
        const v = Number(t?.total_price);
        if (!Number.isFinite(v)) return '';
        return (t.currency_prefix || t.currency_symbol || '$') + (v / Math.pow(10, unit)).toFixed(unit);
    }
    function payLabel(cart) {
        const amt = formatCartTotal(cart);
        return amt ? 'Pay ' + amt : '';
    }
    function refPrices(cart) {
        const t = cart?.totals, unit = Number.isInteger(t?.currency_minor_unit) ? t.currency_minor_unit : 2;
        const fmt = minor => (t?.currency_prefix || t?.currency_symbol || '$') + (Number(minor) / Math.pow(10, unit)).toFixed(unit);
        const pc = cart?.extensions?.peptide_core || {}, trace = pc.shipping_savings, protect = pc.shipping_protection;
        const shipping = trace && trace.status === 'applied' && trace.complete === true && Number(trace.shipping_discount) > 0
            && trace.payable_shipping === t?.total_shipping && Number(trace.original_shipping) > Number(trace.payable_shipping) ? fmt(trace.original_shipping) : '';
        const protection = protect && protect.enabled === true && protect.chosen === true && Number(protect.reference_minor) > Number(protect.price_minor) ? fmt(protect.reference_minor) : '';
        return { shipping, protection, protectionLabel: protect?.label || 'Shipping protection', free: shipping !== '' && Number(trace.payable_shipping) === 0 };
    }
    function helpText(others) {
        const list = (Array.isArray(others) ? others : []).filter(Boolean);
        if (!list.length) return 'Your details are saved. Please try again.';
        const named = list.length === 1 ? list[0] : list.slice(0, -1).join(', ') + ' or ' + list[list.length - 1];
        return 'Your details are saved. Try again, or pay another way: ' + named + '.';
    }
    if (typeof module !== 'undefined' && module.exports) module.exports = { stateForZip, phoneOk, cleanDraft, payLabel, helpText, refPrices };

    if (!global || !global.document) return;
    const document = global.document, data = global.wp?.data, KEY = 'wc/store/cart';
    const CHK_KEY = 'wc/store/checkout';
    if (!data?.select || !data?.dispatch || !data?.subscribe) return;
    const cfg = global.PCCheckoutAssist || {};
    const ls = {
        get() { try { return JSON.parse(global.localStorage.getItem(DRAFT) || 'null'); } catch (_) { return null; } },
        set(v) { try { global.localStorage.setItem(DRAFT, JSON.stringify(v)); } catch (_) {} }
    };
    const select = () => data.select(KEY), actions = () => data.dispatch(KEY);
    let restored = false, couponRestored = false, zipFilledFor = '', lastSaved = '';
    const zipShards = new Map(), zipPending = new Map();

    function addresses() {
        const st = select();
        const c = st?.getCustomerData?.() || st?.getCartData?.() || null;
        if (!c) return null;
        return { ship: c.shippingAddress || c.shipping_address || {}, bill: c.billingAddress || c.billing_address || {} };
    }

    function currentOrderNote() {
        const noteEl = document.querySelector('.wc-block-checkout__order-notes textarea');
        return noteEl ? String(noteEl.value || '').trim().slice(0, 500) : '';
    }

    function save() {
        const a = addresses(); if (!a) return;
        const cart = select()?.getCartData?.();
        const { ship, bill } = a;
        const existing = cleanDraft(ls.get(), Date.now());
        const activeCoupons = Array.isArray(cart?.coupons)
            ? cart.coupons.map(c => String(c?.code || '').trim().toLowerCase()).filter(Boolean)
            : [];
        const coupons = activeCoupons.length ? activeCoupons : (existing?.coupons || []);
        const note = currentOrderNote() || (existing?.note || '');
        const draft = { at: Date.now(), email: bill.email || '', shipping: {}, billing: {}, coupons, note };
        FIELDS.forEach(f => {
            if (ship[f]) draft.shipping[f] = String(ship[f]);
            if (bill[f]) draft.billing[f] = String(bill[f]);
        });
        const sig = JSON.stringify([draft.email, draft.shipping, draft.billing, draft.coupons, draft.note]);
        if (sig === lastSaved || !(draft.email || Object.keys(draft.shipping).length || draft.coupons.length)) return;
        lastSaved = sig;
        ls.set(draft);
    }

    function restore() {
        const cart = select()?.getCartData?.();
        const draft = cleanDraft(ls.get(), Date.now());
        // Restore dropped coupon once cart items exist
        if (!couponRestored && cart && Array.isArray(cart.items) && cart.items.length > 0 && draft?.coupons?.length) {
            couponRestored = true;
            const currentCodes = new Set((cart.coupons || []).map(c => String(c?.code || '').toLowerCase()));
            draft.coupons.forEach(code => {
                if (code && !currentCodes.has(code.toLowerCase())) {
                    try { actions().applyCoupon?.(code); } catch (_) {}
                }
            });
        }
        if (restored) return;
        const a = addresses();
        if (!a || !document.querySelector('.wc-block-checkout')) return;
        restored = true;
        if (cfg.resumed) {
            notice('Your bag, discount & shipping details have been restored. Ready to pay.');
        }
        if (!draft) return;
        const { ship, bill } = a;
        const patch = {};
        FIELDS.forEach(f => { if (!ship[f] && draft.shipping[f]) patch[f] = draft.shipping[f]; });
        let changed = false;
        if (Object.keys(patch).length) { actions().setShippingAddress?.({ ...ship, ...patch }); changed = true; }
        const billPatch = {};
        FIELDS.forEach(f => { const v = draft.billing?.[f] || patch[f] || ship[f]; if (!bill[f] && v) billPatch[f] = v; });
        if (!bill.email && draft.email) billPatch.email = draft.email;
        if (Object.keys(billPatch).length) { actions().setBillingAddress?.({ ...bill, ...billPatch }); changed = true; }
        const noteToSet = cfg.resumedNote || draft.note || '';
        if (noteToSet && data.dispatch(CHK_KEY)?.__internalSetOrderNotes) {
            try { data.dispatch(CHK_KEY).__internalSetOrderNotes(noteToSet); } catch (_) {}
        }
        if (changed && !cfg.resumed) notice('We saved what you entered. Check it and finish your order.');
    }

    function notice(text) {
        const form = document.querySelector('.wc-block-checkout__form');
        if (!form || form.querySelector('[data-pc-assist-note]')) return;
        const p = document.createElement('p');
        p.setAttribute('data-pc-assist-note', '');
        p.className = 'pc-assist-note';
        p.textContent = '✓ ' + text;
        form.prepend(p);
    }

    async function lookupZip5(zip5) {
        const digit = zip5[0];
        if (zipShards.has(digit)) return zipShards.get(digit)[zip5] || null;
        if (!cfg.zipBaseUrl || !global.fetch) return null;
        if (!zipPending.has(digit)) {
            const p = global.fetch(cfg.zipBaseUrl + digit + '.json', { credentials: 'same-origin', cache: 'force-cache' })
                .then(r => r.ok ? r.json() : {})
                .then(obj => { zipShards.set(digit, obj || {}); return obj || {}; })
                .catch(() => ({}))
                .finally(() => zipPending.delete(digit));
            zipPending.set(digit, p);
        }
        const shard = await zipPending.get(digit);
        return shard ? (shard[zip5] || null) : null;
    }

    function zipToCityAndState() {
        const addr = addresses();
        const ship = addr?.ship;
        if (!ship) return;
        const country = ship.country || 'US';
        if (country !== 'US' && country !== 'PR') return;
        const m = /^(\d{5})(?:-?\d{4})?$/.exec(String(ship.postcode || '').trim());
        if (!m) return;
        const zip5 = m[1];
        if (zipFilledFor === zip5) return;
        zipFilledFor = zip5;

        const quickState = stateForZip(zip5);
        if (quickState && country === 'US' && quickState !== ship.state) {
            actions().setShippingAddress?.({ ...ship, state: quickState });
        }
        lookupZip5(zip5).then(hit => {
            if (!Array.isArray(hit) || hit.length < 2) return;
            const latest = addresses();
            if (!latest || !String(latest.ship?.postcode || '').trim().startsWith(zip5)) return;
            const [cityName, stateCode] = hit;
            const nextShip = { ...latest.ship };
            let updated = false;
            if (cityName && nextShip.city !== cityName) { nextShip.city = cityName; updated = true; }
            if (country === 'US' && stateCode && nextShip.state !== stateCode) { nextShip.state = stateCode; updated = true; }
            if (updated) {
                actions().setShippingAddress?.(nextShip);
                const useSame = document.querySelector('.wc-block-checkout__use-address-for-billing input[type="checkbox"]');
                if (!useSame || useSame.checked) {
                    actions().setBillingAddress?.({ ...latest.bill, city: nextShip.city, state: nextShip.state, postcode: nextShip.postcode });
                }
            }
        });
    }

    function phone() {
        const input = document.querySelector('#shipping-phone, #billing-phone');
        if (!input) return;
        const wrap = input.closest('.wc-block-components-text-input') || input.parentElement;
        let note = wrap.parentElement.querySelector('[data-pc-phone-note]');
        if (!note) {
            note = document.createElement('p');
            note.setAttribute('data-pc-phone-note', '');
            note.className = 'pc-phone-note';
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

    // Mobile sticky bottom Pay bar (< 783px)
    let mobileBar = null, btnObserver = null, inlineBtnVisible = false;
    function ensureMobilePayBar(btn, cart) {
        if (!btn) return;
        const page = document.querySelector('.vc-checkout-page');
        if (!page) return;
        if (!mobileBar) {
            mobileBar = document.createElement('div');
            mobileBar.className = 'pc-mobile-paybar';
            mobileBar.setAttribute('data-pc-mobile-paybar', '');
            mobileBar.innerHTML = '<div class="pc-mobile-paybar__meta"><strong class="pc-mobile-paybar__total"></strong><span class="pc-mobile-paybar__save"></span></div><button type="button" class="pc-mobile-paybar__btn"></button>';
            mobileBar.querySelector('.pc-mobile-paybar__btn').addEventListener('click', () => {
                const targetBtn = document.querySelector('.wc-block-components-checkout-place-order-button');
                if (targetBtn) targetBtn.click();
            });
            document.body.appendChild(mobileBar);
        }
        if (!btnObserver && global.IntersectionObserver) {
            btnObserver = new global.IntersectionObserver(entries => {
                entries.forEach(e => { inlineBtnVisible = e.isIntersecting; });
                syncMobilePayBar();
            }, { threshold: 0.15 });
            btnObserver.observe(btn);
        }
        syncMobilePayBar(cart, btn);
    }

    function syncMobilePayBar(cart, btn) {
        if (!mobileBar) return;
        cart = cart || select()?.getCartData?.();
        btn = btn || document.querySelector('.wc-block-components-checkout-place-order-button');
        const isMobile = global.matchMedia?.('(max-width: 782px)')?.matches;
        const page = document.querySelector('.vc-checkout-page');
        if (!isMobile || !btn || inlineBtnVisible) {
            mobileBar.hidden = true;
            page?.classList.remove('has-pc-mobile-paybar');
            return;
        }
        const totalAmt = formatCartTotal(cart);
        const label = payLabel(cart) || 'Place Order';
        const saving = global.VialCoveCheckoutSaving || '';
        mobileBar.querySelector('.pc-mobile-paybar__total').textContent = totalAmt || '';
        const saveNode = mobileBar.querySelector('.pc-mobile-paybar__save');
        saveNode.textContent = saving ? ('Save ' + saving) : 'Secure checkout';
        const stickyBtn = mobileBar.querySelector('.pc-mobile-paybar__btn');
        stickyBtn.textContent = label + ' →';
        stickyBtn.disabled = !!btn.disabled || btn.getAttribute('aria-disabled') === 'true';
        mobileBar.hidden = false;
        page?.classList.add('has-pc-mobile-paybar');
    }

    function payButton() {
        const btn = document.querySelector('.wc-block-components-checkout-place-order-button');
        if (!btn) return;
        const cart = select()?.getCartData?.();
        const label = payLabel(cart);
        const text = btn.querySelector('.wc-block-components-button__text');
        if (text && label && text.getAttribute('data-pc-label') !== label) text.setAttribute('data-pc-label', label);
        if (label && btn.getAttribute('aria-label') !== label) btn.setAttribute('aria-label', label);
        let save = btn.parentElement.parentElement.querySelector('[data-pc-pay-save]');
        const amount = global.VialCoveCheckoutSaving;
        if (amount) {
            if (!save) { save = document.createElement('p'); save.setAttribute('data-pc-pay-save', ''); save.className = 'pc-pay-save'; btn.parentElement.insertAdjacentElement('afterend', save); }
            const line = 'You save ' + amount + ' on this order';
            if (save.textContent !== line) save.textContent = line;
        } else save?.remove();
        ensureMobilePayBar(btn, cart);
    }

    function otherPayments() {
        return [...document.querySelectorAll('.wc-block-checkout__payment-method .wc-block-components-radio-control__option')]
            .filter(opt => !opt.querySelector('input:checked'))
            .map(opt => (opt.querySelector('.wc-block-components-radio-control__label, .wc-block-components-payment-method-label') || opt).textContent.trim().replace(/\s+/g, ' ').slice(0, 40))
            .filter(Boolean).slice(0, 3);
    }

    function buildWhatsAppRescueUrl() {
        if (!cfg.whatsappUrl) return '';
        const cart = select()?.getCartData?.();
        const addr = addresses();
        const ship = addr?.ship || {};
        const bill = addr?.bill || {};
        const itemsSummary = Array.isArray(cart?.items)
            ? cart.items.slice(0, 8).map(i => {
                const v = (i.variation || []).map(x => x.value).filter(Boolean).join('/');
                return (i.name || 'Item') + (v ? ' (' + v + ')' : '') + ' x' + i.quantity;
            }).join(', ')
            : '';
        const coupons = Array.isArray(cart?.coupons) && cart.coupons.length
            ? ' [Coupon: ' + cart.coupons.map(c => c.code.toUpperCase()).join(',') + ']'
            : '';
        const total = formatCartTotal(cart);
        const who = [ship.first_name || bill.first_name, ship.last_name || bill.last_name].filter(Boolean).join(' ');
        const loc = [ship.city, ship.state, ship.postcode].filter(Boolean).join(' ');
        const msg = [
            'Hi VialCove, I ran into a payment issue at checkout and would like help completing my order:',
            itemsSummary ? '• Items: ' + itemsSummary : '',
            total ? '• Total: ' + total + coupons : '',
            (who || loc) ? '• Ship to: ' + [who, loc].filter(Boolean).join(', ') : '',
            bill.email ? '• Email: ' + bill.email : ''
        ].filter(Boolean).join('\n');
        return cfg.whatsappUrl + (cfg.whatsappUrl.includes('?') ? '&' : '?') + 'text=' + encodeURIComponent(msg);
    }

    function isInAppWebView() {
        return /FBAN|FBAV|Instagram|Line\/|MicroMessenger|Snapchat|TikTok/i.test(global.navigator?.userAgent || '');
    }

    // P0-6 & P1-1: Inject WhatsApp emergency rescue & external browser handoff inside checkout error notices
    function paymentHelp() {
        document.querySelectorAll('.wc-block-checkout .wc-block-components-notice-banner.is-error').forEach(banner => {
            if (banner.querySelector('[data-pc-pay-help]')) return;
            const rawText = (banner.textContent || '').toLowerCase();
            const isCouponOnly = rawText.includes('coupon') && !rawText.includes('payment') && !rawText.includes('card');
            const host = banner.querySelector('.wc-block-components-notice-banner__content') || banner;
            const wrap = document.createElement('div');
            wrap.setAttribute('data-pc-pay-help', '');
            wrap.className = 'pc-pay-rescue';

            const lead = document.createElement('p');
            lead.className = 'pc-pay-rescue__lead';
            lead.textContent = isCouponOnly
                ? 'Your address details are saved. Need help applying an offer or completing your order directly? Message our specialist on WhatsApp:'
                : (helpText(otherPayments()) + ' Need an instant alternative payment link? Message our order specialist on WhatsApp — your bag and address will be attached automatically:');
            wrap.appendChild(lead);

            const btnRow = document.createElement('div');
            btnRow.className = 'pc-pay-rescue__actions';

            const waHref = buildWhatsAppRescueUrl();
            if (waHref) {
                const waBtn = document.createElement('a');
                waBtn.className = 'pc-pay-rescue__wa';
                waBtn.href = waHref;
                waBtn.target = '_blank';
                waBtn.rel = 'noopener noreferrer';
                const total = formatCartTotal(select()?.getCartData?.());
                waBtn.textContent = 'Complete Order on WhatsApp' + (total ? ' (' + total + ')' : '') + ' →';
                waBtn.addEventListener('click', () => { waBtn.href = buildWhatsAppRescueUrl() || waHref; });
                btnRow.appendChild(waBtn);
            }

            if (cfg.resumeEndpoint && (isInAppWebView() || rawText.includes('payment') || rawText.includes('card'))) {
                const extBtn = document.createElement('button');
                extBtn.type = 'button';
                extBtn.className = 'pc-pay-rescue__ext';
                extBtn.textContent = isInAppWebView() ? 'Open in Safari/Chrome (Keep Details)' : '🔗 Copy Saved Checkout Link';
                extBtn.addEventListener('click', async () => {
                    extBtn.disabled = true;
                    const a = addresses();
                    const draft = cleanDraft(ls.get(), Date.now());
                    try {
                        const res = await global.fetch(cfg.resumeEndpoint, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                            body: JSON.stringify({
                                email: a?.bill?.email || draft?.email || '',
                                shipping: a?.ship || draft?.shipping || {},
                                coupons: draft?.coupons || [],
                                note: currentOrderNote() || draft?.note || ''
                            })
                        });
                        const json = res.ok ? await res.json() : null;
                        if (json?.url) {
                            try { await global.navigator?.clipboard?.writeText(json.url); } catch (_) {}
                            extBtn.textContent = '✓ Saved Link Copied — Open in Browser!';
                            if (isInAppWebView()) global.open(json.url, '_blank');
                        } else {
                            extBtn.textContent = 'Could not copy link';
                        }
                    } catch (_) {
                        extBtn.textContent = 'Could not copy link';
                    } finally {
                        setTimeout(() => { extBtn.disabled = false; }, 2500);
                    }
                });
                btnRow.appendChild(extBtn);
            }

            if (btnRow.children.length) wrap.appendChild(btnRow);
            host.appendChild(wrap);
        });
    }

    function strikes() {
        const ref = refPrices(select()?.getCartData?.());
        const mark = (el, value) => { if (!el) return; if (value) { if (el.getAttribute('data-pc-ref') !== value) el.setAttribute('data-pc-ref', value); } else if (el.hasAttribute('data-pc-ref')) el.removeAttribute('data-pc-ref'); };
        document.querySelectorAll('.wc-block-checkout .wc-block-components-totals-shipping .wc-block-components-totals-item__value').forEach(el => mark(el, ref.shipping));
        document.querySelectorAll('.wc-block-checkout .wc-block-components-totals-item').forEach(item => {
            const label = item.querySelector('.wc-block-components-totals-item__label')?.textContent.trim();
            if (label === ref.protectionLabel) mark(item.querySelector('.wc-block-components-totals-item__value'), ref.protection);
        });
    }

    function postcodeAndSelects() {
        const addr = addresses();
        const shipCountry = addr?.ship?.country || 'US';
        const billCountry = addr?.bill?.country || shipCountry;
        [
            ['#shipping-postcode', shipCountry],
            ['#billing-postcode', billCountry]
        ].forEach(([sel, country]) => {
            const input = document.querySelector(sel);
            if (!input) return;
            const numericZip = country === 'US' || country === 'PR';
            const targetMode = numericZip ? 'numeric' : 'text';
            if (input.getAttribute('inputmode') !== targetMode) input.setAttribute('inputmode', targetMode);
            if (numericZip) {
                // '-' escaped: browsers compile pattern with the v flag, where an unescaped '-' in a class is a syntax error
                if (input.getAttribute('pattern') !== '[0-9\\- ]*') input.setAttribute('pattern', '[0-9\\- ]*');
            } else if (input.hasAttribute('pattern')) {
                input.removeAttribute('pattern');
            }
        });
        document.querySelectorAll('.wc-blocks-components-select__select[size="1"]').forEach(selectEl => {
            selectEl.removeAttribute('size');
        });
    }

    let frame = 0;
    function tick() {
        frame = 0;
        restore(); postcodeAndSelects(); zipToCityAndState(); phone(); payButton(); strikes(); paymentHelp(); save();
    }
    const schedule = () => { if (!frame) frame = setTimeout(tick, 60); };
    function start() {
        if (!document.querySelector('.wc-block-checkout')) { setTimeout(start, 300); return; }
        data.subscribe(schedule, KEY);
        global.addEventListener('resize', () => syncMobilePayBar());
        new global.MutationObserver(schedule).observe(document.querySelector('.wc-block-checkout'), { childList: true, subtree: true });
        schedule();
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true }); else start();
})(typeof window === 'undefined' ? null : window);
