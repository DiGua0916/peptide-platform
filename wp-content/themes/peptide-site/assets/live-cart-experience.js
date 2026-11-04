/* Real, same-origin WooCommerce session. No cart persistence, price snapshots or discount engine. */
(function (global) {
    'use strict';
    const isMinor = value => typeof value === 'string' && /^-?\d{1,16}$/.test(value) && Number.isSafeInteger(Number(value));
    const validPrecision = value => Number.isInteger(value) && value >= 0 && value <= 8;
    const validCart = cart => !!cart && Array.isArray(cart.items) && Number.isSafeInteger(cart.items_count) && cart.items_count >= 0 && cart.totals && isMinor(cart.totals.total_price) && /^[A-Z]{3}$/.test(cart.totals.currency_code || '') && validPrecision(cart.totals.currency_minor_unit) && cart.items.every(item => typeof item.key === 'string' && Number.isSafeInteger(item.id) && Number.isSafeInteger(item.quantity));
    function money(value, metadata) {
        if (!isMinor(value) || !metadata || !validPrecision(metadata.currency_minor_unit)) return '—';
        try {
            // Keep the native integer amount exact, including the highest supported precision.
            const amount = BigInt(value), absolute = amount < 0n ? -amount : amount, precision = metadata.currency_minor_unit, scale = 10n ** BigInt(precision);
            const whole = new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(absolute / scale), fraction = String(absolute % scale).padStart(precision, '0');
            return new Intl.NumberFormat('en-US', { style: 'currency', currency: metadata.currency_code, minimumFractionDigits: precision, maximumFractionDigits: precision }).formatToParts(amount < 0n ? -1n : 1n).map(part => part.type === 'integer' ? whole : part.type === 'fraction' ? fraction : part.value).join('');
        } catch (_) { return '—'; }
    }
    /** Native SKU markdowns and order discounts are separate amounts. */
    function cartItemPricing(item) {
        const prices = item?.prices, totals = item?.totals;
        const result = { unit: money(prices?.price, prices), regularUnit: '', total: money(totals?.line_total, totals), regularTotal: '', savingsLabel: '', savingsDescription: '', orderDiscount: '' };
        const unsigned = value => isMinor(value) && BigInt(value) >= 0n;
        const metadata = value => value && /^[A-Z]{3}$/.test(value.currency_code || '') && validPrecision(value.currency_minor_unit);
        if (metadata(totals) && unsigned(totals.line_subtotal) && unsigned(totals.line_total) && BigInt(totals.line_subtotal) > BigInt(totals.line_total)) {
            result.orderDiscount = 'Order discount −' + money(String(BigInt(totals.line_subtotal) - BigInt(totals.line_total)), totals);
        }
        if (!metadata(prices) || !unsigned(prices.price) || !unsigned(prices.regular_price) || BigInt(prices.regular_price) <= BigInt(prices.price)) return result;
        result.regularUnit = money(prices.regular_price, prices);
        if (!metadata(totals) || prices.currency_code !== totals.currency_code || prices.currency_minor_unit !== totals.currency_minor_unit
            || !Number.isSafeInteger(item.quantity) || item.quantity < 1 || !unsigned(totals.line_subtotal) || !unsigned(totals.line_total)) return result;
        const quantity = BigInt(item.quantity), regular = BigInt(prices.regular_price), active = BigInt(prices.price), regularLine = regular * quantity, activeLine = active * quantity;
        // Store API line totals exclude tax. A tax-inclusive display price or a
        // different line-price basis cannot safely become its crossed-out total.
        if (activeLine !== BigInt(totals.line_subtotal) || BigInt(totals.line_total) > activeLine || !isMinor(String(regularLine))) return result;
        const savings = (regular - active) * quantity, percentage = ((regular - active) * 100n + regular / 2n) / regular;
        result.regularTotal = money(String(regularLine), totals);
        const percent = percentage === 0n ? '<1%' : percentage === 100n && active > 0n ? '>99%' : String(percentage) + '%';
        result.savingsLabel = 'Save ' + percent + ' (' + money(String(savings), totals) + ')';
        result.savingsDescription = 'Product savings before order discounts: ' + money(String(savings), totals) + ' (' + percent + ').';
        return result;
    }
    // The native Store API does not identify an applied coupon's origin across
    // GETs. A neutral name hides internal codes without guessing an activity.
    function couponPresentation(coupon) {
        const amount = coupon?.totals?.total_discount;
        const formatted = isMinor(amount) && BigInt(amount) >= 0n ? money(amount, coupon.totals) : '—';
        const label = 'Order discount' + (formatted !== '—' ? ' · ' + formatted : '');
        return { label, removeLabel: 'Remove ' + label, code: typeof coupon?.code === 'string' ? coupon.code : '' };
    }
    function confirmedCartAdd(before, after, addition, status) {
        if (status !== 201 || !validCart(before) || !validCart(after) || !Number.isSafeInteger(addition?.id) || addition.id < 1 || !Number.isSafeInteger(addition.quantity) || addition.quantity < 1) return false;
        const quantity = cart => {
            let total = 0;
            for (const item of cart.items) if (item.id === addition.id) {
                if (item.quantity < 0 || !Number.isSafeInteger(total + item.quantity)) return null;
                total += item.quantity;
            }
            return total;
        };
        const previous = quantity(before), current = quantity(after);
        if (previous === null || current === null || !Number.isSafeInteger(previous + addition.quantity) || current !== previous + addition.quantity) return false;
        if (addition.receipt) {
            const receipt = after.extensions?.peptide_core?.activity_recommendation;
            if (receipt?.requested !== true || receipt.added !== true) return false;
        }
        return true;
    }
    function activityAllowed(latest, applied) {
        if (!latest || latest.enabled === false || latest.visible === false) return false;
        // A newly applied setting invalidates an older cart's presentation. A
        // stale config read must never override a more recent native cart DTO.
        if (applied && Number.isSafeInteger(applied.revision) && applied.revision >= (latest.revision || 0)) return applied.enabled === true && applied.revision === latest.revision;
        return true;
    }
    function activityPresentation(state, settings = {}, applied = null) {
        const latest = state.cart?.extensions?.peptide_core?.activity_listener;
        const activity = state.valid && !state.pending && activityAllowed(latest, applied) ? latest : null;
        return { activity, progress: settings.cart_progress_enabled === false ? null : activity, recommendation: settings.cart_recommendations_enabled === false ? null : activity };
    }
    // Reconcile only changed keys. Replacing the entire list loses image state,
    // keyboard focus and the browser's scroll anchor during each native request.
    function reconcileRows(list, records, items, make, state) {
        const keep = new Set(items.map(item => item.key));
        for (const [key, record] of records) if (!keep.has(key)) { record.node.remove(); records.delete(key); }
        items.forEach((item, index) => {
            let record = records.get(item.key);
            if (!record) { record = make(item, state); records.set(item.key, record); }
            record.update(item, state);
            if (list.children[index] !== record.node) list.insertBefore(record.node, list.children[index] || null);
        });
    }
    function statusPresentation(state) {
        return { text: state.error || (state.pending ? 'Updating your bag…' : state.valid ? '' : 'Loading your bag…'), announceOnly: !!state.cart && !state.error };
    }
    function recentPresentationUrl(base, item, origin) {
        const url = new URL(base, origin), id = item?.productId;
        if (url.origin !== new URL(origin).origin || !/^https?:$/.test(url.protocol) || !Number.isSafeInteger(id) || id < 1) throw new Error('Unavailable product');
        if (url.searchParams.has('rest_route')) url.searchParams.set('rest_route', url.searchParams.get('rest_route').replace(/\/?$/, '/') + id);
        else url.pathname = url.pathname.replace(/\/?$/, '/') + id;
        url.searchParams.set('card', 'true');
        if (Number.isSafeInteger(item.variationId) && item.variationId > 0) url.searchParams.set('variation', String(item.variationId));
        return url.href;
    }
    function recentProductSaving(card) {
        const price = card?.price, regular = card?.regularPrice;
        if (!price || !regular || typeof regular.text !== 'string' || !regular.text.trim()
            || price.currency_code !== regular.currency_code || price.currency_minor_unit !== regular.currency_minor_unit) return null;
        const display = cartItemPricing({ quantity: 1,
            prices: { ...price, price: price.minor, regular_price: regular.minor },
            totals: { ...price, line_subtotal: price.minor, line_total: price.minor } });
        if (!display.savingsLabel) return null;
        return { regularText: regular.text, label: display.savingsLabel, description: display.savingsDescription };
    }
    function recentPresentation(item, response) {
        const card = response?.card, id = item.productId;
        // A history parent carries a minimum price, not a selected specification.
        // A failed read keeps an options action; never combine that minimum with a guessed label.
        const fallback = { ...item, variationLabel: item.variationLabel || 'Choose a specification', price: item.variationLabel ? item.price : null, canAddOne: false, productSaving: null };
        if (response?.id !== id || card?.productId !== id || !Number.isSafeInteger(card.purchaseId) || card.purchaseId < 1 || !Number.isSafeInteger(card.variationId) || card.variationId < 0
            || (card.variationId === 0 ? card.purchaseId !== id : card.purchaseId !== card.variationId)
            || (item.variationId > 0 && card.variationId !== item.variationId)
            || typeof card.price?.text !== 'string' || !card.price.text.trim()
            || (card.variationId > 0 && (typeof card.variationLabel !== 'string' || !card.variationLabel.trim()))) return fallback;
        return { ...item, ...card, priceIsFrom: false, variationLabel: card.variationLabel || 'Single specification', canAddOne: card.canAddOne === true, productSaving: recentProductSaving(card) };
    }
    function recentAddPayload(item) {
        if (item?.canAddOne !== true || !Number.isSafeInteger(item.productId) || item.productId < 1 || !Number.isSafeInteger(item.purchaseId) || item.purchaseId < 1 || !Number.isSafeInteger(item.variationId) || item.variationId < 0
            || (item.variationId ? item.purchaseId !== item.variationId : item.purchaseId !== item.productId)) throw new Error('Choose a specification before adding this product.');
        return { id: item.purchaseId, quantity: 1 };
    }
    function totals(cart) {
        if (!validCart(cart)) return [];
        const t = cart.totals, rows = [], unsigned = value => isMinor(value) && BigInt(value) >= 0n;
        const sameCurrency = metadata => metadata?.currency_code === t.currency_code && metadata?.currency_minor_unit === t.currency_minor_unit;
        const positive = value => unsigned(value) && BigInt(value) > 0n;
        const label = value => typeof value === 'string' && value.trim() ? value : '';
        const nativeOfferName = name => name.trim() === '黑色星期五' ? 'Black Friday offer' : name;
        let saved = 0n;

        // This extension contains pre-coupon product comparisons, not another
        // amount to subtract from the already discounted Woo subtotal.
        const sale = cart.extensions?.peptide_core?.sale_savings;
        let productSavings = 0n, regularSubtotal = null;
        if (sameCurrency(sale) && [sale.regular_subtotal, sale.sale_subtotal, sale.sale_savings, t.total_items].every(unsigned)
            && sale.sale_subtotal === t.total_items && BigInt(sale.regular_subtotal) - BigInt(sale.sale_subtotal) === BigInt(sale.sale_savings)) {
            productSavings = BigInt(sale.sale_savings); regularSubtotal = sale.regular_subtotal;
        } else if (unsigned(t.total_items) && cart.items.length) {
            // Conservative fallback only when every exact SKU shares the same
            // tax/currency basis as its native pre-coupon line subtotal.
            let subtotal = 0n, savings = 0n, comparable = true;
            for (const item of cart.items) {
                const p = item.prices, line = item.totals;
                if (!sameCurrency(p) || !sameCurrency(line) || !Number.isSafeInteger(item.quantity) || item.quantity < 1
                    || ![p.price, p.regular_price, line.line_subtotal].every(unsigned)
                    || BigInt(p.price) * BigInt(item.quantity) !== BigInt(line.line_subtotal)) { comparable = false; break; }
                subtotal += BigInt(line.line_subtotal);
                if (BigInt(p.regular_price) > BigInt(p.price)) savings += (BigInt(p.regular_price) - BigInt(p.price)) * BigInt(item.quantity);
            }
            if (comparable && subtotal === BigInt(t.total_items) && isMinor(String(subtotal + savings))) { productSavings = savings; regularSubtotal = String(subtotal + savings); }
        }
        rows.push({ key: 'products', label: productSavings > 0n ? 'Regular product subtotal' : 'Product subtotal', minor: regularSubtotal ?? t.total_items });
        if (productSavings > 0n) { rows.push({ key: 'product-saving', label: 'Product savings', minor: String(-productSavings), savings: true }); saved += productSavings; }

        // Coupon amounts are native per-code allocations; codes remain private
        // in this presentation. The aggregate is a check, never an extra row.
        if (positive(t.total_discount)) {
            const coupons = Array.isArray(cart.coupons) ? cart.coupons : [], seen = new Set();
            let allocated = 0n, complete = true;
            const couponRows = [];
            for (const coupon of coupons) {
                if (typeof coupon?.code !== 'string' || seen.has(coupon.code) || !sameCurrency(coupon.totals) || !unsigned(coupon.totals?.total_discount)) { complete = false; break; }
                seen.add(coupon.code); const amount = BigInt(coupon.totals.total_discount); allocated += amount;
                if (amount > 0n) couponRows.push({ key: 'coupon-' + couponRows.length, label: 'Coupon discount', minor: String(-amount), savings: true });
            }
            if (complete && allocated === BigInt(t.total_discount) && couponRows.length) {
                couponRows.forEach((row, index) => { if (couponRows.length > 1) row.label += ' ' + (index + 1); rows.push(row); });
            } else rows.push({ key: 'coupon-total', label: 'Coupon savings', minor: String(-BigInt(t.total_discount)), savings: true });
            saved += BigInt(t.total_discount);
        }

        // Promotion guidance is an evaluation, while fees are the final applied
        // amounts (Woo can cap them). Associate names by exact native fee key.
        const fees = Array.isArray(cart.fees) ? cart.fees : [], seenFees = new Set(), feeRows = [];
        const applied = cart.extensions?.peptide_core?.promotion?.applied_all;
        let feeSum = 0n, feeSavings = 0n, feesComplete = true;
        for (const fee of fees) {
            const key = typeof fee?.key === 'string' ? fee.key : fee?.id;
            if (typeof key !== 'string' || seenFees.has(key) || !sameCurrency(fee.totals) || !isMinor(fee.totals?.total)) { feesComplete = false; break; }
            seenFees.add(key); const amount = BigInt(fee.totals.total); feeSum += amount;
            const promo = /^pc-promo-([1-9]\d*)$/.exec(key), record = promo && Array.isArray(applied) ? applied.find(entry => String(entry?.id) === promo[1]) : null;
            const name = record ? nativeOfferName(label(record.title) || label(fee.name) || 'Order offer') : label(fee.name) || 'Order adjustment';
            if (amount !== 0n) feeRows.push({ key: 'fee-' + key, label: name, minor: fee.totals.total, savings: amount < 0n });
            if (amount < 0n) feeSavings -= amount;
        }
        if (feesComplete && isMinor(t.total_fees) && feeSum === BigInt(t.total_fees)) { rows.push(...feeRows); saved += feeSavings; }
        else if (isMinor(t.total_fees) && BigInt(t.total_fees) !== 0n) rows.push({ key: 'fee-total', label: 'Order adjustments', minor: t.total_fees, savings: BigInt(t.total_fees) < 0n });

        const shipping = shippingSavingsTrace(cart);
        if (cart.needs_shipping) {
            if (shipping) {
                rows.push({ key: 'shipping-original', label: 'Shipping before offers', minor: shipping.original });
                shipping.discounts.forEach((entry, index) => rows.push({ key: 'shipping-offer-' + entry.id, label: nativeOfferName(entry.name) + ' · Shipping', minor: String(-entry.amount), savings: true,
                    detail: index === shipping.discounts.length - 1 ? 'Shipping after offers: ' + money(shipping.payable, t) : '' }));
                saved += BigInt(shipping.discount);
            } else rows.push({ key: 'shipping', label: 'Shipping', minor: t.total_shipping, unknown: !cart.has_calculated_shipping });
        }
        if (positive(t.total_tax)) rows.push({ key: 'tax', label: 'Tax', minor: t.total_tax });
        rows.push({ key: 'payable', label: cart.needs_shipping && !cart.has_calculated_shipping ? 'Estimated total' : 'Total payable', minor: t.total_price, total: true });
        const taxPresent = [t.total_tax, t.total_items_tax, t.total_discount_tax, t.total_shipping_tax, t.total_fees_tax,
            ...fees.map(fee => fee?.totals?.total_tax)].some(value => isMinor(value) && BigInt(value) !== 0n);
        if (saved > 0n && isMinor(String(saved))) rows.push({ key: 'saved', label: 'You save', minor: String(saved), savedTotal: true, savings: true,
            detail: taxPresent ? 'Before tax' : '' });
        return rows;
    }
    /** Optional future server trace; absent metadata is never reverse-calculated. */
    function shippingSavingsTrace(cart) {
        const trace = cart?.extensions?.peptide_core?.shipping_savings, t = cart?.totals;
        const unsigned = value => isMinor(value) && BigInt(value) >= 0n;
        const sameCurrency = value => value?.currency_code === t?.currency_code && value?.currency_minor_unit === t?.currency_minor_unit;
        const packageKey = value => Number.isSafeInteger(value) && value >= 0 ? String(value) : typeof value === 'string' && value.length > 0 && value.length <= 100 ? value : null;
        if (!cart?.needs_shipping || cart.has_calculated_shipping !== true || !sameCurrency(trace) || trace?.version !== 1 || trace.complete !== true
            || trace.status !== 'applied' || trace.tax_basis !== 'exclusive' || ![trace.original_shipping, trace.shipping_discount, trace.payable_shipping, t?.total_shipping].every(unsigned)
            || trace.payable_shipping !== t.total_shipping || BigInt(trace.shipping_discount) <= 0n || !Array.isArray(trace.packages) || !trace.packages.length || trace.packages.length > 100 || !Array.isArray(cart.shipping_rates)) return null;
        const selected = new Map();
        for (const pack of cart.shipping_rates) {
            const key = packageKey(pack?.package_id), rates = Array.isArray(pack?.shipping_rates) ? pack.shipping_rates.filter(rate => rate?.selected === true) : null;
            if (key === null || !rates || rates.length !== 1 || selected.has(key) || typeof rates[0].rate_id !== 'string' || !rates[0].rate_id || !sameCurrency(rates[0]) || !unsigned(rates[0].price)) return null;
            selected.set(key, rates[0]);
        }
        let original = 0n, discount = 0n, payable = 0n;
        const seen = new Set(), discounts = new Map();
        for (const pack of trace.packages) {
            const key = packageKey(pack?.package_id), rate = selected.get(key);
            if (key === null || !rate || seen.has(key) || pack.rate_id !== rate.rate_id || ![pack.original_shipping, pack.shipping_discount, pack.payable_shipping].every(unsigned)
                || pack.payable_shipping !== rate.price || BigInt(pack.original_shipping) - BigInt(pack.shipping_discount) !== BigInt(pack.payable_shipping)) return null;
            seen.add(key); original += BigInt(pack.original_shipping); discount += BigInt(pack.shipping_discount); payable += BigInt(pack.payable_shipping);
            if (BigInt(pack.shipping_discount) > 0n) {
                if (!Number.isSafeInteger(pack.discount_id) || pack.discount_id < 1 || typeof pack.discount_name !== 'string' || !pack.discount_name.trim() || pack.discount_name.length > 500) return null;
                const entry = discounts.get(pack.discount_id);
                if (entry && entry.name !== pack.discount_name) return null;
                discounts.set(pack.discount_id, { id: pack.discount_id, name: pack.discount_name, amount: (entry?.amount || 0n) + BigInt(pack.shipping_discount) });
            }
        }
        if (seen.size !== selected.size || original !== BigInt(trace.original_shipping) || discount !== BigInt(trace.shipping_discount) || payable !== BigInt(trace.payable_shipping)
            || original - discount !== payable || ![original, discount, payable].every(value => isMinor(String(value)))) return null;
        return { original: String(original), discount: String(discount), payable: String(payable), discounts: [...discounts.values()] };
    }
    /** One native dialog on every enabled device; keep its parent product open. */
    function createDrawerController({ dialog, document, window: win, enabled = () => true, refresh = () => {} }) {
        const page = document.documentElement, motion = win.matchMedia('(prefers-reduced-motion: reduce)');
        let source = null, session = false, closing = false, closeTimer = 0, frame = 0, motionSerial = 0, previousPadding = '', previousInert = false, disposed = false;
        const available = () => !disposed && !!dialog && typeof dialog.showModal === 'function' && enabled();
        const notify = (name, type) => {
            document.dispatchEvent(new win.CustomEvent(name, { detail: { dialog, trigger: source } }));
            document.dispatchEvent(new win.CustomEvent('vc:visitor-action', { detail: { type, data: { source: 'drawer' } } }));
        };
        function cancelMotion() {
            motionSerial++;
            win.clearTimeout(closeTimer); closeTimer = 0;
            if (frame) win.cancelAnimationFrame(frame); frame = 0;
        }
        function cleanup(restoreFocus = true, announce = true) {
            cancelMotion(); closing = false;
            dialog?.classList.remove('is-open', 'is-closing');
            if (!session) return;
            dialog.inert = previousInert;
            session = false; page.classList.remove('vc-live-cart-open'); page.style.paddingRight = previousPadding;
            source?.setAttribute?.('aria-expanded', 'false');
            if (restoreFocus && !document.hidden && source?.isConnected) source.focus?.({ preventScroll: true });
            if (announce) notify('vc:live-cart-closed', 'cart_close');
        }
        function finishClose(restoreFocus = true) { if (dialog?.open) dialog.close(); cleanup(restoreFocus); }
        function open(trigger) {
            if (!available()) return false;
            cancelMotion(); closing = false;
            dialog.classList.remove('is-closing');
            if (trigger?.isConnected) {
                if (source !== trigger) source?.setAttribute?.('aria-expanded', 'false');
                source = trigger;
            }
            if (!dialog.open) {
                previousPadding = page.style.paddingRight;
                previousInert = !!dialog.inert;
                try { dialog.showModal(); } catch (_) { return false; }
                session = true;
                const gap = Math.max(0, win.innerWidth - page.clientWidth);
                if (gap) page.style.paddingRight = ((parseFloat(win.getComputedStyle(page).paddingRight) || 0) + gap) + 'px';
                page.classList.add('vc-live-cart-open');
                notify('vc:live-cart-opened', 'cart_open');
            }
            dialog.inert = previousInert;
            source?.setAttribute?.('aria-haspopup', 'dialog'); source?.setAttribute?.('aria-controls', dialog.id); source?.setAttribute?.('aria-expanded', 'true');
            dialog.getBoundingClientRect();
            if (motion.matches || document.hidden) dialog.classList.add('is-open');
            else {
                const ticket = motionSerial;
                frame = win.requestAnimationFrame(() => { if (ticket !== motionSerial) return; frame = 0; if (dialog.open && !closing) dialog.classList.add('is-open'); });
            }
            if (!document.hidden) dialog.querySelector('[data-cart-close]')?.focus({ preventScroll: true });
            refresh(); return true;
        }
        function close(immediate = false) {
            if (!dialog?.open) { cleanup(); return; }
            if (closing) { if (immediate || motion.matches || document.hidden) finishClose(); return; }
            cancelMotion(); closing = true;
            // Keep native modal focus/Back semantics until the shorter exit ends,
            // but do not allow a fading quantity/checkout control to be activated.
            dialog.inert = true; dialog.classList.add('is-closing'); dialog.classList.remove('is-open');
            if (immediate || motion.matches || document.hidden) finishClose();
            else {
                const ticket = motionSerial;
                closeTimer = win.setTimeout(() => { if (ticket !== motionSerial) return; closeTimer = 0; finishClose(); }, 200);
            }
        }
        function settleMotion(restoreFocus = false) {
            cancelMotion();
            if (!dialog?.open) return;
            if (closing) finishClose(restoreFocus); else dialog.classList.add('is-open');
        }
        const onClose = () => { if (!dialog.open) cleanup(); };
        const onVisibility = () => { if (document.hidden) settleMotion(); };
        const onMotion = () => { if (motion.matches) settleMotion(true); };
        const onPageHide = () => settleMotion();
        dialog?.addEventListener('close', onClose);
        document.addEventListener?.('visibilitychange', onVisibility);
        win.addEventListener?.('pagehide', onPageHide);
        if (motion.addEventListener) motion.addEventListener('change', onMotion); else motion.addListener?.(onMotion);
        return { available, open, close, destroy() {
            disposed = true; dialog?.removeEventListener('close', onClose);
            document.removeEventListener?.('visibilitychange', onVisibility);
            win.removeEventListener?.('pagehide', onPageHide);
            if (motion.removeEventListener) motion.removeEventListener('change', onMotion); else motion.removeListener?.(onMotion);
            if (dialog?.open) dialog.close(); cleanup(false, false);
        } };
    }
    /** Woo's contents-only action deliberately preserves dirty address fields. */
    function createNativeCheckoutBridge(win, enabled = false) {
        let lastContents = '';
        const stores = () => ({ data: win.wp?.data, cart: win.wc?.wcBlocksData?.cartStore, checkout: win.wc?.wcBlocksData?.checkoutStore });
        function ready() {
            if (!enabled) return true;
            try { const { data, cart } = stores(); return !!cart && typeof data?.select(cart) === 'object' && typeof data?.dispatch(cart)?.receiveCartContents === 'function'; } catch (_) { return false; }
        }
        function processing() {
            if (!enabled) return false;
            try { const { data, checkout } = stores(); return !!(checkout && data?.select(checkout)?.isProcessing?.()); } catch (_) { return true; }
        }
        function busy() {
            if (!enabled) return false;
            try {
                const { data, cart, checkout } = stores(), state = cart && data?.select(cart), order = checkout && data?.select(checkout);
                return !state || !!(state.isCustomerDataUpdating?.() || state.isAddressFieldsForShippingRatesUpdating?.() || state.hasPendingItemsOperations?.() || order?.isCalculating?.() || order?.isProcessing?.());
            } catch (_) { return true; }
        }
        function receive(cart) {
            if (!enabled || !validCart(cart) || busy()) return false;
            const { shipping_address, billing_address, ...contents } = cart;
            const fingerprint = JSON.stringify(contents);
            if (lastContents === fingerprint) return false;
            const previous = lastContents; lastContents = fingerprint;
            try {
                const { data, cart: store } = stores(), actions = store && data?.dispatch(store);
                if (typeof actions?.receiveCartContents !== 'function') { lastContents = previous; return false; }
                actions.receiveCartContents(contents); return true;
            } catch (_) { lastContents = previous; return false; }
        }
        function subscribe(callback) {
            if (!enabled) return () => {};
            const { data, cart, checkout } = stores(), stops = [];
            if (typeof data?.subscribe === 'function') for (const store of [cart, checkout].filter(Boolean)) stops.push(data.subscribe(callback, store));
            return () => stops.forEach(stop => stop?.());
        }
        return { enabled, ready, busy, processing, receive, subscribe };
    }
    function createClient(config, environment = {}) {
        const fetcher = environment.fetch || global.fetch.bind(global), notify = environment.notify || (() => {});
        let cart = null, nonce = '', valid = false, pending = false, error = '', errorOperation = '', epoch = 0, readPromise = null, readController = null, externalTimer = null, destroyed = false;
        const snapshot = () => ({ cart, valid, pending, error, errorOperation });
        const change = () => notify('state', snapshot());
        async function requestResult(url, body, signal) {
            const controller = new AbortController(), timer = setTimeout(() => controller.abort(), environment.timeoutMs || 20000);
            const abort = () => controller.abort(); signal?.addEventListener('abort', abort, { once: true });
            try {
                const headers = { Accept: 'application/json' };
                if (config.wpNonce) headers['X-WP-Nonce'] = config.wpNonce;
                if (body !== undefined) { headers['Content-Type'] = 'application/json'; if (nonce) headers.Nonce = nonce; }
                const response = await fetcher(url, { method: body === undefined ? 'GET' : 'POST', credentials: 'same-origin', cache: 'no-store', headers, signal: controller.signal, ...(body === undefined ? {} : { body: JSON.stringify(body) }) });
                const fresh = response.headers?.get('Nonce'); if (fresh) nonce = fresh;
                const data = await response.json();
                if (!response.ok) throw new Error(typeof data?.message === 'string' ? data.message : 'Could not update your bag.');
                return { data, status: response.status };
            } catch (failure) {
                if (failure?.name === 'AbortError') throw new Error('The request timed out. Please check your bag before trying again.');
                throw failure;
            } finally { clearTimeout(timer); signal?.removeEventListener('abort', abort); }
        }
        // Public reads and detached quote requests retain their original DTO API.
        async function request(url, body, signal) { return (await requestResult(url, body, signal)).data; }
        async function refresh() {
            if (destroyed || pending) return snapshot();
            if (readPromise) return readPromise;
            const generation = epoch; readController = new AbortController();
            readPromise = (async () => {
                try {
                    const result = await request(config.cartApiUrl, undefined, readController.signal);
                    if (destroyed || generation !== epoch) return snapshot();
                    if (!validCart(result)) throw new Error('Your bag could not be verified. Please reload it.');
                    cart = result; valid = true;
                    if (errorOperation !== 'write') { error = ''; errorOperation = ''; }
                    change(); return snapshot();
                } catch (failure) {
                    if (!destroyed && generation === epoch) { valid = false; error = failure.message; if (errorOperation !== 'write') errorOperation = 'read'; change(); }
                    return snapshot();
                } finally { if (generation === epoch) { readPromise = null; readController = null; } }
            })();
            return readPromise;
        }
        function invalidate() { epoch++; readController?.abort(); readPromise = null; readController = null; valid = false; }
        async function write(action, body) {
            if (destroyed || pending || !valid || !nonce || (environment.canWrite && !environment.canWrite())) throw new Error('Your bag is updating. Please try again when it is ready.');
            const before = cart;
            const addition = action === 'add-item' ? { id: body?.id, quantity: body?.quantity, receipt: body?.extensions?.peptide_core?.activityRecommendation !== undefined } : null;
            invalidate(); const generation = epoch; pending = true; error = ''; errorOperation = ''; change(); notify('start', { source: 'drawer', owner: 'live-cart' });
            try {
                const response = await requestResult(config.cartApiUrl + '/' + action, body), result = response.data;
                if (destroyed || generation !== epoch || !validCart(result) || (addition && !confirmedCartAdd(before, result, addition, response.status))) throw new Error('The cart update could not be confirmed. Review your bag before trying again.');
                cart = result; valid = true; pending = false; change(); notify('updated', { cart, source: 'drawer', owner: 'live-cart', mutation: action, stale: false });
                return { confirmed: true, cartCount: cart.items_count, rawCart: cart };
            } catch (failure) {
                if (!destroyed && generation === epoch) {
                    pending = false; valid = false; error = failure.message; errorOperation = 'write'; change();
                    notify('updated', { cart: null, source: 'drawer', owner: 'live-cart', mutation: action, stale: true });
                    await refresh(); // Reconcile once; an uncertain POST is never retried or reported as successful.
                }
                throw failure;
            }
        }
        function externalStart() {
            invalidate(); pending = true; change(); clearTimeout(externalTimer);
            externalTimer = setTimeout(() => { if (destroyed) return; pending = false; refresh(); }, 30000);
        }
        function externalUpdate(detail) {
            clearTimeout(externalTimer); invalidate(); pending = false;
            if (detail?.stale !== true && validCart(detail?.cart)) { cart = detail.cart; valid = true; error = ''; errorOperation = ''; change(); }
            else { change(); refresh(); }
        }
        return { snapshot, refresh, request, write, externalStart, externalUpdate, destroy() { destroyed = true; invalidate(); clearTimeout(externalTimer); } };
    }
    if (typeof module === 'object' && module.exports) module.exports = { validCart, money, cartItemPricing, couponPresentation, confirmedCartAdd, totals, createClient, activityAllowed, activityPresentation, reconcileRows, statusPresentation, createDrawerController, createNativeCheckoutBridge, recentPresentationUrl, recentPresentation, recentAddPayload };
    if (!global?.document || !global.VialCoveLiveCartConfig) return;
    const document = global.document, config = global.VialCoveLiveCartConfig, settings = config.settings || {}, roots = [...document.querySelectorAll('[data-vc-live-cart]')];
    if (!roots.length) return;
    const drawer = document.getElementById('vc-live-cart-drawer'), isDesktop = global.matchMedia('(min-width: 768px)'), parentIds = new Map();
    const nativeCheckout = createNativeCheckoutBridge(global, config.nativeCheckout === true);
    const el = (tag, cls, text) => { const node = document.createElement(tag); node.className = cls || ''; if (text !== undefined) node.textContent = text; return node; };
    const clean = value => { const node = document.createElement('textarea'); node.innerHTML = String(value || ''); return node.value; };
    const safeUrl = value => { try { const url = new URL(value, location.href); return ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password ? url.href : ''; } catch (_) { return ''; } };
    let recent = null, recommendation = null, enrichmentPending = false, enrichment = 0, enrichmentTimer = 0, quickOpener = null, disposed = false;
    let appliedActivity = global.vcLiveCommerce?.activity?.config || null, activityRead = null;
    const views = roots.map(root => ({ root, rows: new Map(), recentSignature: '', totalsSignature: '', couponsSignature: '', progress: global.createCartProgress?.({ mount: root.querySelector('[data-cart-progress]') }), progressSignature: '', recommendation: global.createCartRecommendation?.({ mount: root.querySelector('[data-cart-recommendation]'), onChoose: candidate => choose(candidate.productId), onAdd: candidate => client.write('add-item', global.vcRecommendationContract.addPayload(candidate)), onRefresh: () => { refreshActivityConfig(); client.refresh(); } }) }));
    const client = createClient(config, { canWrite: () => !nativeCheckout.busy(), notify(type, detail) {
        if (type === 'state') { if (detail.valid && !detail.pending) nativeCheckout.receive(detail.cart); scheduleEnrichment(detail); render(detail); if (detail.pending) refreshActivityConfig(); }
        else document.dispatchEvent(new CustomEvent(type === 'start' ? 'vc:cart-mutation-start' : 'vc:cart-updated', { detail }));
    } });
    const deviceEnabled = () => (settings.visibility || {})[isDesktop.matches ? 'desktop' : 'mobile'] !== false;
    const canRead = () => deviceEnabled() && !document.hidden && roots.some(root => !root.closest('dialog') || root.closest('dialog').open);
    function refreshActivityConfig() {
        if (disposed || !config.activityConfigUrl || !canRead()) return Promise.resolve();
        if (activityRead) return activityRead;
        activityRead = client.request(config.activityConfigUrl).then(value => {
            if (disposed || typeof value?.enabled !== 'boolean' || !Number.isSafeInteger(value.revision) || value.revision < 0 || value.revision < (appliedActivity?.revision || 0)) return;
            const changed = JSON.stringify([value.enabled, value.revision]) !== JSON.stringify([appliedActivity?.enabled, appliedActivity?.revision]);
            appliedActivity = value;
            if (!changed) return;
            // Disable offer UI without cancelling the independent recent-items read.
            if (!activityAllowed(client.snapshot().cart?.extensions?.peptide_core?.activity_listener, appliedActivity)) { enrichmentPending = false; recommendation = null; }
            render(client.snapshot());
        }).catch(() => { /* Keep the latest confirmed native DTO/config, never enable a failed read. */ }).finally(() => { activityRead = null; });
        return activityRead;
    }
    function productLink(item, cls = 'vc-live-cart__product') {
        const link = el('a', cls, clean(item.name)); link.href = safeUrl(item.permalink) || config.shopUrl;
        const parent = parentIds.get(item.id); if (parent) { link.dataset.vcHomeProduct = String(parent); link.dataset.vcQuickOpen = String(parent); }
        return link;
    }
    function choose(id) {
        // The native quick-view verifies opener connectivity after its async fetch.
        // Keep one inert opener connected for the lifetime of this page.
        if (!quickOpener) { quickOpener = el('a'); quickOpener.hidden = true; document.body.append(quickOpener); }
        const trigger = quickOpener; trigger.href = config.shopUrl; trigger.dataset.vcHomeProduct = String(id); trigger.dataset.vcQuickOpen = String(id);
        trigger.click();
    }
    function cartRow(item, state) {
        const row = el('article', 'vc-live-cart__line'); row.dataset.cartKey = item.key;
        const imageLink = productLink({ ...item, name: '' }, 'vc-live-cart__image');
        const image = el('img'); image.width = 72; image.height = 80; imageLink.append(image);
        const info = el('div', 'vc-live-cart__info'), name = productLink(item), pack = el('p', 'vc-live-cart__pack'), unit = el('p', 'vc-live-cart__unit');
        const regularUnit = el('del', 'vc-live-cart__unit-regular'), currentUnit = el('span', 'vc-live-cart__unit-current'), unitLabel = el('span', 'vc-live-cart__unit-label', '/ box');
        unit.append(regularUnit, currentUnit, unitLabel); info.append(name, pack, unit);
        const controls = el('div', 'vc-live-cart__controls'), qty = el('div', 'vc-live-cart__quantity');
        let current = item, targets = {};
        const count = el('span'), buttons = {};
        for (const [action, text] of [['minus', '−'], ['plus', '+']]) {
            const button = el('button', '', text); button.type = 'button'; button.dataset.cartAction = action;
            button.addEventListener('click', () => client.write('update-item', { key: current.key, quantity: targets[action] }).catch(() => {}));
            buttons[action] = button; qty.append(button); if (action === 'minus') qty.append(count);
        }
        const remove = el('button', 'vc-live-cart__remove', 'Remove'); remove.type = 'button'; remove.dataset.cartAction = 'remove';
        remove.addEventListener('click', () => client.write('remove-item', { key: current.key }).catch(() => {})); controls.append(qty, remove);
        const total = el('strong', 'vc-live-cart__line-total'), regularTotal = el('del', 'vc-live-cart__line-regular'), totalPrice = el('div', 'vc-live-cart__line-price'), purchase = el('div', 'vc-live-cart__line-actions');
        const saving = el('span', 'vc-live-cart__sale-tag'), orderDiscount = el('span', 'vc-live-cart__order-discount');
        totalPrice.append(regularTotal, total); purchase.append(totalPrice, orderDiscount, controls); row.append(imageLink, info, purchase, saving);
        return { node: row, update(next, nextState) {
            current = next;
            const title = clean(next.name), src = safeUrl(next.images?.[0]?.thumbnail || next.images?.[0]?.src) || '';
            if (image.getAttribute('src') !== src) { if (src) image.src = src; else image.removeAttribute('src'); }
            image.alt = title; name.textContent = title;
            for (const link of [name, imageLink]) { link.href = safeUrl(next.permalink) || config.shopUrl; const parent = parentIds.get(next.id); if (parent) { link.dataset.vcHomeProduct = String(parent); link.dataset.vcQuickOpen = String(parent); } }
            pack.textContent = (next.variation || []).map(v => clean(v.value)).filter(Boolean).join(' · '); pack.hidden = !pack.textContent;
            const pricing = cartItemPricing(next);
            regularUnit.textContent = pricing.regularUnit; regularUnit.hidden = !pricing.regularUnit;
            regularUnit.setAttribute('aria-label', 'Regular price per box ' + pricing.regularUnit);
            currentUnit.textContent = pricing.unit; currentUnit.setAttribute('aria-label', 'Current price per box ' + pricing.unit);
            total.textContent = pricing.total; total.setAttribute('aria-label', 'Line total ' + pricing.total);
            regularTotal.textContent = pricing.regularTotal; regularTotal.hidden = !pricing.regularTotal;
            regularTotal.setAttribute('aria-label', 'Regular total for ' + next.quantity + (next.quantity === 1 ? ' box ' : ' boxes ') + pricing.regularTotal);
            saving.textContent = pricing.savingsLabel; saving.hidden = !pricing.savingsLabel; saving.title = pricing.savingsDescription;
            saving.setAttribute('aria-label', pricing.savingsDescription);
            orderDiscount.textContent = pricing.orderDiscount; orderDiscount.hidden = !pricing.orderDiscount;
            row.classList.toggle('has-product-saving', !!pricing.savingsLabel);
            count.textContent = String(next.quantity); count.setAttribute('aria-label', next.quantity + (next.quantity === 1 ? ' box' : ' boxes'));
            const limits = next.quantity_limits || {}, step = Number.isSafeInteger(limits.multiple_of) && limits.multiple_of > 0 ? limits.multiple_of : 1, min = Number.isSafeInteger(limits.minimum) ? Math.max(1, limits.minimum) : 1, max = Number.isSafeInteger(limits.maximum) ? limits.maximum : 9999;
            targets = { minus: next.quantity - step, plus: next.quantity + step };
            for (const action of ['minus', 'plus']) { buttons[action].disabled = !nextState.valid || nextState.pending || limits.editable === false || targets[action] < min || targets[action] > max; buttons[action].setAttribute('aria-label', (action === 'minus' ? 'Decrease' : 'Increase') + ' quantity of ' + title); }
            remove.disabled = !nextState.valid || nextState.pending; remove.setAttribute('aria-label', 'Remove ' + title);
        } };
    }
    function render(state) {
        if (disposed) return;
        if (nativeCheckout.busy()) state = { ...state, pending: true };
        const { cart, valid, pending, error } = state;
        if (valid) document.querySelectorAll('.vc-cart-count').forEach(node => { node.textContent = String(cart.items_count); });
        for (const view of views) {
            const root = view.root, firstReady = root.hidden && valid && deviceEnabled();
            if (!deviceEnabled()) { root.hidden = true; const fallback = root.nextElementSibling; if (fallback?.matches('[data-vc-native-cart-fallback]')) fallback.hidden = false; continue; }
            if (firstReady) { root.hidden = false; const fallback = root.nextElementSibling; if (fallback?.matches('[data-vc-native-cart-fallback]')) fallback.hidden = true; }
            const active = document.activeElement, activeRow = root.contains(active) ? active?.closest('[data-cart-key]') : null, list = root.querySelector('[data-cart-items]');
            // Keep a visible product in place when genuine totals/offer content above
            // it changes. Native scroll anchoring may already have corrected it.
            const scrollHost = root.closest('dialog') || document.scrollingElement;
            const hostTop = root.closest('dialog')?.getBoundingClientRect().top || 0;
            const anchor = root.hidden ? null : [...list.children].find(row => { const rect = row.getBoundingClientRect(); return rect.bottom > hostTop && rect.top < (global.innerHeight || 0); });
            const anchorTop = anchor?.getBoundingClientRect().top;
            if (pending && activeRow && active?.dataset.cartAction) view.restoreFocus = { node: active, key: activeRow.dataset.cartKey, action: active.dataset.cartAction, index: [...list.children].indexOf(activeRow) };
            const status = root.querySelector('[data-cart-status]'), statusState = statusPresentation(state);
            status.classList.toggle('is-announcement', statusState.announceOnly);
            if (status.textContent !== clean(statusState.text)) status.textContent = clean(statusState.text);
            root.querySelector('[data-cart-retry]').hidden = !error || pending;
            const presentation = activityPresentation(state, settings, appliedActivity), activity = presentation.activity;
            // Pending writes pause the visual but preserve its baseline for the confirmed next DTO.
            const progressMount = root.querySelector('[data-cart-progress]');
            const latestActivity = cart?.extensions?.peptide_core?.activity_listener;
            const activityDisabled = settings.cart_progress_enabled === false || !activityAllowed(latestActivity, appliedActivity);
            if (pending && !activityDisabled) { view.progress?.pause(); progressMount.setAttribute('aria-busy', 'true'); }
            else { const signature = JSON.stringify(presentation.progress); if (view.progressSignature !== signature) { view.progressSignature = signature; view.progress?.update(presentation.progress); } progressMount.removeAttribute('aria-busy'); }
            const quoteActivity = activity && recommendation ? { ...activity, recommendation } : activity;
            const quoteMount = root.querySelector('[data-cart-recommendation]');
            const reserveQuote = !error && (pending || enrichmentPending) && activityAllowed(latestActivity, appliedActivity) && latestActivity?.enabled === true && latestActivity?.visible === true && settings.cart_recommendations_enabled !== false;
            if (reserveQuote && !quoteMount.classList.contains('is-refreshing') && !quoteMount.hidden) quoteMount.style.setProperty('--vc-cart-quote-height', quoteMount.getBoundingClientRect().height + 'px');
            quoteMount.classList.toggle('is-refreshing', reserveQuote && !!quoteMount.style.getPropertyValue('--vc-cart-quote-height'));
            if (!reserveQuote) quoteMount.style.removeProperty('--vc-cart-quote-height');
            view.recommendation?.update({ activity: presentation.recommendation ? quoteActivity : null, snapshotKey: activity?.cartSnapshotKey || '', valid, pending });
            list.setAttribute('aria-busy', String(pending));
            if (cart) reconcileRows(list, view.rows, cart.items, cartRow, state);
            if (view.restoreFocus && !pending) {
                const saved = view.restoreFocus, focus = document.activeElement;
                // Never steal focus if the user moved elsewhere during the request.
                if (!focus || focus === document.body || focus === saved.node) { const rows = [...list.children], target = rows.find(row => row.dataset.cartKey === saved.key) || rows[Math.min(Math.max(saved.index, 0), rows.length - 1)]; (target?.querySelector('[data-cart-action="' + saved.action + '"]:not(:disabled)') || target?.querySelector('a') || root.querySelector('h1,h2'))?.focus({ preventScroll: true }); }
                view.restoreFocus = null;
            }
            root.querySelector('[data-cart-empty]').hidden = !valid || !!cart?.items.length;
            const summary = root.querySelector('[data-cart-summary]'); summary.hidden = !cart?.items.length;
            const totalRows = totals(cart), totalsSignature = JSON.stringify([totalRows, cart?.totals?.currency_code, cart?.totals?.currency_minor_unit]);
            if (view.totalsSignature !== totalsSignature) { view.totalsSignature = totalsSignature; root.querySelector('[data-cart-totals]').replaceChildren(...totalRows.map(total => {
                const row = el('div', 'vc-live-cart__total-row' + (total.total ? ' is-total' : '') + (total.savings ? ' is-saving' : '') + (total.savedTotal ? ' is-saved-total' : ''));
                row.dataset.summaryKey = total.key;
                const label = el('span', 'vc-live-cart__total-label', clean(total.label));
                if (total.detail) label.append(el('small', 'vc-live-cart__total-detail', clean(total.detail)));
                row.append(label, el('strong', '', total.unknown ? 'At checkout' : money(total.minor, cart.totals))); return row;
            })); }
            const checkout = root.querySelector('[data-cart-checkout]'); checkout.setAttribute('aria-disabled', String(!valid || pending || !cart?.items.length));
            root.querySelectorAll('[data-cart-coupon] input,[data-cart-coupon] button').forEach(node => { node.disabled = !valid || pending; });
            const coupons = root.querySelector('[data-cart-coupons]'), couponsSignature = JSON.stringify((cart?.coupons || []).map(coupon => { const display = couponPresentation(coupon); return [display.code, display.label, display.removeLabel]; }));
            if (view.couponsSignature !== couponsSignature) { view.couponsSignature = couponsSignature; coupons.replaceChildren(...(cart?.coupons || []).map(coupon => { const display = couponPresentation(coupon), button = el('button', '', display.label + ' ×'); button.type = 'button'; button.setAttribute('aria-label', display.removeLabel); button.addEventListener('click', () => client.write('remove-coupon', { code: display.code }).catch(() => {})); return button; })); }
            coupons.querySelectorAll('button').forEach(button => { button.disabled = !valid || pending; });
            renderRecent(view, state);
            if (anchor?.isConnected && scrollHost && !root.hidden) { const delta = anchor.getBoundingClientRect().top - anchorTop; if (Number.isFinite(delta) && Math.abs(delta) > 1) scrollHost.scrollTop += delta; }
        }
    }
    function renderRecent(view, state) {
        const root = view.root, section = root.querySelector('[data-cart-recent]'); section.hidden = settings.cart_recent_enabled === false || !recent?.visible || !recent.items?.length;
        section.inert = !state.valid || state.pending; section.setAttribute('aria-busy', String(state.pending));
        if (view.recentOperation) { section.hidden = false; return; }
        if (section.hidden) return;
        const signature = JSON.stringify([recent.title, recent.items.slice(0, 2)]); if (view.recentSignature === signature) return; view.recentSignature = signature;
        for (const feedback of view.recentFeedbacks || []) feedback.destroy(); view.recentFeedbacks = [];
        const heading = section.querySelector('h2'); heading.textContent = recent.title || 'Recently viewed';
        root.querySelector('[data-cart-recent-items]').replaceChildren(...recent.items.slice(0, 2).map(item => {
            const card = el('article', 'vc-live-cart__recent-card'), link = productLink({ id: item.productId, name: item.name, permalink: item.permalink });
            link.dataset.vcHomeProduct = String(item.productId); link.dataset.vcQuickOpen = String(item.productId);
            const imageLink = link.cloneNode(false), image = el('img');
            const src = safeUrl(item.image?.url);
            if (src) { image.src = src; image.alt = item.image?.alt || item.name; image.width = 120; image.height = 120; imageLink.append(image); }
            else imageLink.append(el('span', 'vc-live-cart__recent-no-image', 'Image unavailable'));
            if (item.productSaving) {
                const saving = el('span', 'vc-live-cart__recent-sale', clean(item.productSaving.label));
                saving.setAttribute('aria-label', clean(item.productSaving.description)); saving.title = clean(item.productSaving.description); imageLink.append(saving);
            }
            card.append(imageLink, link, el('p', 'vc-live-cart__recent-pack', clean(item.variationLabel)));
            if (item.price?.text) {
                const prices = el('div', 'vc-live-cart__recent-pricing');
                if (item.productSaving) prices.append(el('del', 'vc-live-cart__recent-regular', clean(item.productSaving.regularText)));
                prices.append(el('strong', 'vc-live-cart__recent-price', clean(item.price.text)), el('span', 'vc-live-cart__recent-unit', '/ box')); card.append(prices);
            }
            if (!item.canAddOne) {
                const options = link.cloneNode(false); options.className = 'vc-live-cart__recent-action'; options.textContent = 'Select & add'; options.setAttribute('aria-label', 'Choose specification and add ' + clean(item.name)); card.append(options);
                return card;
            }
            const button = el('button', 'vc-live-cart__recent-action', 'Add 1 box +'), notice = el('p', 'vc-live-cart__recent-status'); button.type = 'button';
            button.setAttribute('aria-label', 'Add 1 box of ' + clean(item.name) + ' ' + clean(item.variationLabel));
            notice.setAttribute('role', 'status'); notice.setAttribute('aria-live', 'polite'); card.append(button, notice);
            const feedbackConfig = global.vcLiveCommerce?.cartFeedback?.config || {}, successMs = feedbackConfig.enabled === false ? 0 : (feedbackConfig.successDurationMs || 1200);
            const feedback = global.createLiveCartFeedback?.({ buttons: [button], announce: notice, bagCountElements: [...document.querySelectorAll('.vc-cart-count')], timeoutMs: 25000, successMs, burstEnabled: feedbackConfig.enabled !== false && feedbackConfig.burstEnabled !== false, bagPulseEnabled: feedbackConfig.enabled !== false && feedbackConfig.bagPulseEnabled !== false, burstDurationMs: feedbackConfig.burstDurationMs });
            if (feedback) view.recentFeedbacks.push(feedback);
            button.addEventListener('click', async () => {
                const latest = client.snapshot(); if (view.recentOperation || button.disabled || !latest.valid || latest.pending) return;
                view.recentOperation = true;
                const submit = () => client.write('add-item', recentAddPayload(item));
                let ok = false;
                try {
                    if (feedback) ok = (await feedback.run({ submit, quantity: 1, productName: clean(item.name), triggerButton: button })).ok === true;
                    else { button.disabled = true; await submit(); ok = true; notice.textContent = '1 box added to your bag.'; }
                } catch (_) { notice.textContent = 'Could not confirm the update. Check your bag before trying again.'; }
                if (disposed) return;
                view.recentResetTimer = setTimeout(() => { view.recentOperation = false; view.recentSignature = ''; render(client.snapshot()); }, ok ? successMs : 0);
            });
            return card;
        }));
    }
    function scheduleEnrichment(state) {
        clearTimeout(enrichmentTimer); const generation = ++enrichment;
        recommendation = null; enrichmentPending = false;
        if (!state.valid || state.pending || !canRead()) return;
        enrichmentPending = true;
        enrichmentTimer = setTimeout(async () => {
            const activity = state.cart?.extensions?.peptide_core?.activity_listener;
            const requests = state.cart.items.filter(item => !parentIds.has(item.id)).map(async item => { try { const product = await client.request(config.productsApiUrl + item.id); if (Number.isSafeInteger(product.id)) parentIds.set(item.id, product.parent > 0 ? product.parent : product.id); } catch (_) {} });
            let history = null;
            try { history = await client.request(config.recentItemsUrl); } catch (_) { /* Private history unavailable: no invented recent list or ranking. */ }
            if (history?.visible && Array.isArray(history.items)) {
                history = { ...history, items: await Promise.all(history.items.slice(0, 2).map(async item => {
                    try { return recentPresentation(item, await client.request(recentPresentationUrl(config.recentProductApiUrl, item, location.origin))); }
                    catch (_) { return recentPresentation(item, null); }
                })) };
            }
            await Promise.all(requests);
            if (disposed || generation !== enrichment) return;
            recent = history; recommendation = null;
            if (settings.cart_recommendations_enabled === false || !activityAllowed(activity, appliedActivity) || activity?.enabled !== true || activity?.visible !== true || !activity?.next || !history?.candidates?.length) { enrichmentPending = false; render(client.snapshot()); return; }
            render(client.snapshot());
            try {
                const body = global.vcRecommendationContract.quoteRequest(activity, activity.cartSnapshotKey, history.candidates, appliedActivity);
                const result = await client.request(config.recommendationsUrl, body);
                if (disposed || generation !== enrichment) return;
                recommendation = activityAllowed(activity, appliedActivity) ? result.recommendation : null; enrichmentPending = false; render(client.snapshot());
                const baseQuote = recommendation;
                const isCurrent = () => {
                    const current = client.snapshot(), live = current.cart?.extensions?.peptide_core?.activity_listener;
                    return !disposed && generation === enrichment && recommendation === baseQuote && current.valid && !current.pending && !nativeCheckout.busy()
                        && settings.cart_recommendations_enabled !== false && activityAllowed(live, appliedActivity)
                        && live?.stateKey === activity.stateKey && live?.cartSnapshotKey === activity.cartSnapshotKey
                        && live?.revision === activity.revision && live?.promotionRevision === activity.promotionRevision;
                };
                // Read this exact SKU only for optional compare-price presentation.
                // Native quote eligibility, totals, token and add payload stay unchanged.
                if (baseQuote && typeof global.vcRecommendationContract.enrichPresentation === 'function') {
                    const decorated = await global.vcRecommendationContract.enrichPresentation(baseQuote, activity, activity.cartSnapshotKey, {
                        isCurrent, read: candidate => client.request(recentPresentationUrl(config.recentProductApiUrl, candidate, location.origin)),
                    });
                    if (decorated && decorated !== baseQuote && isCurrent()) { recommendation = decorated; render(client.snapshot()); }
                }
            } catch (_) { if (generation === enrichment) { recommendation = null; enrichmentPending = false; render(client.snapshot()); } }
        }, 250);
    }
    const drawerController = createDrawerController({ dialog: drawer, document, window: global, enabled: () => deviceEnabled() && nativeCheckout.ready() && !nativeCheckout.processing(), refresh: () => { refreshActivityConfig(); client.refresh(); } });
    const openDrawer = trigger => {
        // A product opened from an already visible bag returns to that bag.
        // Do not reorder/reuse one cart as both the parent and a new child layer.
        const product = drawer?.open && trigger?.closest?.('dialog.vc-showcase-quick[open]');
        if (product) { product.close(); return drawerController.open(); }
        return drawerController.open(trigger);
    };
    const closeDrawer = immediate => drawerController.close(immediate);
    document.addEventListener('click', event => {
        const link = event.target.closest?.('.cart-link,[data-vc-open-cart]');
        if (link && !event.defaultPrevented && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey && event.button === 0 && openDrawer(link)) event.preventDefault();
        if (event.target.closest?.('[data-cart-close]')) closeDrawer();
        const checkout = event.target.closest?.('[data-cart-checkout]'); if (checkout?.getAttribute('aria-disabled') === 'true') event.preventDefault();
    });
    drawer?.addEventListener('click', event => { if (event.target === drawer) closeDrawer(); });
    drawer?.addEventListener('cancel', event => { event.preventDefault(); closeDrawer(); });
    isDesktop.addEventListener('change', () => { if (!deviceEnabled() && drawer?.open) closeDrawer(true); render(client.snapshot()); if (canRead()) client.refresh(); });
    for (const root of roots) {
        root.querySelector('[data-cart-retry]').addEventListener('click', () => client.refresh());
        root.querySelector('[data-cart-coupon]').addEventListener('submit', event => { event.preventDefault(); const input = event.currentTarget.elements.coupon, code = input.value.trim(); if (code) client.write('apply-coupon', { code }).then(() => { input.value = ''; }).catch(() => {}); });
    }
    const started = event => { if (event.detail?.owner !== 'live-cart') client.externalStart(); };
    const updated = event => { if (event.detail?.owner !== 'live-cart') client.externalUpdate(event.detail); };
    document.addEventListener('vc:cart-mutation-start', started); document.addEventListener('vc:cart-updated', updated);
    document.addEventListener('vc:recently-viewed-updated', () => { if (!disposed && canRead()) scheduleEnrichment(client.snapshot()); });
    const refreshVisible = () => { if (canRead()) { refreshActivityConfig(); client.refresh(); } };
    document.addEventListener('visibilitychange', refreshVisible); global.addEventListener('focus', refreshVisible); global.addEventListener('pageshow', refreshVisible);
    const polling = setInterval(refreshVisible, 30000);
    let wasNativeBusy = nativeCheckout.busy(), nativeRefreshTimer = 0;
    const stopNativeSubscription = nativeCheckout.subscribe(() => {
        if (disposed) return;
        const busy = nativeCheckout.busy(); render(client.snapshot());
        if (wasNativeBusy && !busy && drawer?.open) { clearTimeout(nativeRefreshTimer); nativeRefreshTimer = setTimeout(refreshVisible, 0); }
        wasNativeBusy = busy;
    });
    global.VialCoveLiveCart = { canOpen: drawerController.available, open: trigger => openDrawer(trigger) || location.assign(config.cartUrl), close: closeDrawer, refresh: () => client.refresh(), snapshot: () => client.snapshot() };
    global.addEventListener('pagehide', event => { if (event.persisted) return; disposed = true; stopNativeSubscription(); clearTimeout(nativeRefreshTimer); drawerController.destroy(); quickOpener?.remove(); clearInterval(polling); clearTimeout(enrichmentTimer); client.destroy(); views.forEach(view => { view.progress?.destroy(); view.recommendation?.destroy(); clearTimeout(view.recentResetTimer); for (const feedback of view.recentFeedbacks || []) feedback.destroy(); }); });
    if (deviceEnabled() && roots.some(root => root.dataset.drawer === '0')) refreshVisible();
})(typeof window === 'undefined' ? null : window);
