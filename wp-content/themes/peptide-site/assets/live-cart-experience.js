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
    function totals(cart) {
        if (!validCart(cart)) return [];
        const t = cart.totals, rows = [{ label: 'Product subtotal', minor: t.total_items }];
        if (Number(t.total_discount) > 0) rows.push({ label: 'Coupon savings', minor: String(-Number(t.total_discount)), savings: true });
        for (const fee of cart.fees || []) if (isMinor(fee.totals?.total)) rows.push({ label: fee.name || 'Adjustment', minor: fee.totals.total, savings: Number(fee.totals.total) < 0 });
        if (cart.needs_shipping) rows.push({ label: 'Shipping', minor: t.total_shipping, unknown: !cart.has_calculated_shipping });
        if (Number(t.total_tax) > 0) rows.push({ label: 'Tax', minor: t.total_tax });
        rows.push({ label: 'Estimated total', minor: t.total_price, total: true });
        return rows;
    }
    /** One native dialog on every enabled device; keep its parent product open. */
    function createDrawerController({ dialog, document, window: win, enabled = () => true, refresh = () => {} }) {
        const page = document.documentElement, motion = win.matchMedia('(prefers-reduced-motion: reduce)');
        let source = null, session = false, closeTimer = 0, frame = 0, previousPadding = '', disposed = false;
        const available = () => !disposed && !!dialog && typeof dialog.showModal === 'function' && enabled();
        const notify = (name, type) => {
            document.dispatchEvent(new win.CustomEvent(name, { detail: { dialog, trigger: source } }));
            document.dispatchEvent(new win.CustomEvent('vc:visitor-action', { detail: { type, data: { source: 'drawer' } } }));
        };
        function cleanup(restoreFocus = true, announce = true) {
            win.clearTimeout(closeTimer); closeTimer = 0;
            if (frame) win.cancelAnimationFrame(frame); frame = 0;
            dialog?.classList.remove('is-open');
            if (!session) return;
            session = false; page.classList.remove('vc-live-cart-open'); page.style.paddingRight = previousPadding;
            source?.setAttribute?.('aria-expanded', 'false');
            if (restoreFocus && source?.isConnected) source.focus?.({ preventScroll: true });
            if (announce) notify('vc:live-cart-closed', 'cart_close');
        }
        function finishClose() { if (dialog?.open) dialog.close(); cleanup(); }
        function open(trigger) {
            if (!available()) return false;
            win.clearTimeout(closeTimer); closeTimer = 0;
            if (trigger?.isConnected) source = trigger;
            if (!dialog.open) {
                previousPadding = page.style.paddingRight;
                try { dialog.showModal(); } catch (_) { return false; }
                session = true;
                const gap = Math.max(0, win.innerWidth - page.clientWidth);
                if (gap) page.style.paddingRight = ((parseFloat(win.getComputedStyle(page).paddingRight) || 0) + gap) + 'px';
                page.classList.add('vc-live-cart-open');
                notify('vc:live-cart-opened', 'cart_open');
            }
            source?.setAttribute?.('aria-haspopup', 'dialog'); source?.setAttribute?.('aria-controls', dialog.id); source?.setAttribute?.('aria-expanded', 'true');
            dialog.getBoundingClientRect();
            if (frame) win.cancelAnimationFrame(frame);
            frame = win.requestAnimationFrame(() => { frame = 0; if (dialog.open) dialog.classList.add('is-open'); });
            dialog.querySelector('[data-cart-close]')?.focus({ preventScroll: true });
            refresh(); return true;
        }
        function close(immediate = false) {
            if (!dialog?.open) { cleanup(); return; }
            if (frame) win.cancelAnimationFrame(frame); frame = 0;
            dialog.classList.remove('is-open'); win.clearTimeout(closeTimer);
            if (immediate || motion.matches) finishClose(); else closeTimer = win.setTimeout(finishClose, 320);
        }
        const onClose = () => { if (!dialog.open) cleanup(); };
        dialog?.addEventListener('close', onClose);
        return { available, open, close, destroy() {
            disposed = true; dialog?.removeEventListener('close', onClose);
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
        async function request(url, body, signal) {
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
                return data;
            } catch (failure) {
                if (failure?.name === 'AbortError') throw new Error('The request timed out. Please check your bag before trying again.');
                throw failure;
            } finally { clearTimeout(timer); signal?.removeEventListener('abort', abort); }
        }
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
            invalidate(); const generation = epoch; pending = true; error = ''; errorOperation = ''; change(); notify('start', { source: 'drawer', owner: 'live-cart' });
            try {
                const result = await request(config.cartApiUrl + '/' + action, body);
                if (destroyed || generation !== epoch || !validCart(result)) throw new Error('The cart update could not be confirmed. Review your bag before trying again.');
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
    if (typeof module === 'object' && module.exports) module.exports = { validCart, money, totals, createClient, activityAllowed, activityPresentation, reconcileRows, statusPresentation, createDrawerController, createNativeCheckoutBridge };
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
    const views = roots.map(root => ({ root, rows: new Map(), recentSignature: '', totalsSignature: '', couponsSignature: '', progress: global.createCartProgress?.({ mount: root.querySelector('[data-cart-progress]') }), progressSignature: '', recommendation: global.createCartRecommendation?.({ mount: root.querySelector('[data-cart-recommendation]'), onChoose: candidate => choose(candidate.productId), onAdd: candidate => client.write('add-item', { id: candidate.purchasableId, quantity: candidate.quantity }) }) }));
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
        const info = el('div', 'vc-live-cart__info'), name = productLink(item), pack = el('p', 'vc-live-cart__pack'), unit = el('p', 'vc-live-cart__unit'); info.append(name, pack, unit);
        const controls = el('div', 'vc-live-cart__controls'), qty = el('div', 'vc-live-cart__quantity');
        let current = item, targets = {};
        const count = el('span'), buttons = {};
        for (const [action, text] of [['minus', '−'], ['plus', '+']]) {
            const button = el('button', '', text); button.type = 'button'; button.dataset.cartAction = action;
            button.addEventListener('click', () => client.write('update-item', { key: current.key, quantity: targets[action] }).catch(() => {}));
            buttons[action] = button; qty.append(button); if (action === 'minus') qty.append(count);
        }
        const remove = el('button', 'vc-live-cart__remove', 'Remove'); remove.type = 'button'; remove.dataset.cartAction = 'remove';
        remove.addEventListener('click', () => client.write('remove-item', { key: current.key }).catch(() => {})); controls.append(qty, remove); info.append(controls);
        const total = el('strong', 'vc-live-cart__line-total'); row.append(imageLink, info, total);
        return { node: row, update(next, nextState) {
            current = next;
            const title = clean(next.name), src = safeUrl(next.images?.[0]?.thumbnail || next.images?.[0]?.src) || '';
            if (image.getAttribute('src') !== src) { if (src) image.src = src; else image.removeAttribute('src'); }
            image.alt = title; name.textContent = title;
            for (const link of [name, imageLink]) { link.href = safeUrl(next.permalink) || config.shopUrl; const parent = parentIds.get(next.id); if (parent) { link.dataset.vcHomeProduct = String(parent); link.dataset.vcQuickOpen = String(parent); } }
            pack.textContent = (next.variation || []).map(v => clean(v.value)).filter(Boolean).join(' · '); pack.hidden = !pack.textContent;
            unit.textContent = money(next.prices?.price, next.prices) + ' / box';
            total.textContent = money(next.totals?.line_total, next.totals);
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
            root.querySelector('[data-cart-count]').textContent = cart ? cart.items_count + (cart.items_count === 1 ? ' box' : ' boxes') : '';
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
            if (view.totalsSignature !== totalsSignature) { view.totalsSignature = totalsSignature; root.querySelector('[data-cart-totals]').replaceChildren(...totalRows.map(total => { const row = el('div', 'vc-live-cart__total-row' + (total.total ? ' is-total' : '') + (total.savings ? ' is-saving' : '')); row.append(el('span', '', clean(total.label)), el('strong', '', total.unknown ? 'At checkout' : money(total.minor, cart.totals))); return row; })); }
            const checkout = root.querySelector('[data-cart-checkout]'); checkout.setAttribute('aria-disabled', String(!valid || pending || !cart?.items.length));
            root.querySelectorAll('[data-cart-coupon] input,[data-cart-coupon] button').forEach(node => { node.disabled = !valid || pending; });
            const coupons = root.querySelector('[data-cart-coupons]'), couponsSignature = JSON.stringify((cart?.coupons || []).map(coupon => coupon.code));
            if (view.couponsSignature !== couponsSignature) { view.couponsSignature = couponsSignature; coupons.replaceChildren(...(cart?.coupons || []).map(coupon => { const button = el('button', '', clean(coupon.code) + ' ×'); button.type = 'button'; button.setAttribute('aria-label', 'Remove coupon ' + clean(coupon.code)); button.addEventListener('click', () => client.write('remove-coupon', { code: coupon.code }).catch(() => {})); return button; })); }
            coupons.querySelectorAll('button').forEach(button => { button.disabled = !valid || pending; });
            renderRecent(view, state);
            if (anchor?.isConnected && scrollHost && !root.hidden) { const delta = anchor.getBoundingClientRect().top - anchorTop; if (Number.isFinite(delta) && Math.abs(delta) > 1) scrollHost.scrollTop += delta; }
        }
    }
    function renderRecent(view, state) {
        const root = view.root, section = root.querySelector('[data-cart-recent]'); section.hidden = settings.cart_recent_enabled === false || !recent?.visible || !recent.items?.length;
        section.inert = !state.valid || state.pending; section.setAttribute('aria-busy', String(state.pending));
        if (section.hidden) return;
        const signature = JSON.stringify([recent.title, recent.items.slice(0, 2)]); if (view.recentSignature === signature) return; view.recentSignature = signature;
        const heading = section.querySelector('h2'); heading.textContent = recent.title || 'Recently viewed';
        root.querySelector('[data-cart-recent-items]').replaceChildren(...recent.items.slice(0, 2).map(item => { const card = el('article', 'vc-live-cart__recent-card'), link = productLink({ id: item.productId, name: item.name, permalink: item.permalink }); link.dataset.vcHomeProduct = String(item.productId); link.dataset.vcQuickOpen = String(item.productId); const image = el('img'); image.src = safeUrl(item.image?.url); image.alt = item.image?.alt || item.name; image.width = 60; image.height = 68; const imageLink = link.cloneNode(false); imageLink.append(image); card.append(imageLink, link, el('p', '', item.variationLabel || ''), el('strong', '', (item.priceIsFrom ? 'From ' : '') + (item.price?.text || ''))); return card; }));
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
            await Promise.all(requests);
            if (disposed || generation !== enrichment) return;
            recent = history; recommendation = null;
            if (settings.cart_recommendations_enabled === false || !activityAllowed(activity, appliedActivity) || activity?.enabled !== true || activity?.visible !== true || !activity?.next || !history?.candidates?.length) { enrichmentPending = false; render(client.snapshot()); return; }
            render(client.snapshot());
            try {
                const result = await client.request(config.recommendationsUrl, { cartStateKey: activity.stateKey, cartSnapshotKey: activity.cartSnapshotKey, promotionRevision: activity.promotionRevision, configRevision: activity.revision, candidates: history.candidates.slice(0, 20) });
                if (disposed || generation !== enrichment) return;
                recommendation = activityAllowed(activity, appliedActivity) ? result.recommendation : null; enrichmentPending = false; render(client.snapshot());
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
    global.addEventListener('pagehide', event => { if (event.persisted) return; disposed = true; stopNativeSubscription(); clearTimeout(nativeRefreshTimer); drawerController.destroy(); quickOpener?.remove(); clearInterval(polling); clearTimeout(enrichmentTimer); client.destroy(); views.forEach(view => { view.progress?.destroy(); view.recommendation?.destroy(); }); });
    if (deviceEnabled() && roots.some(root => root.dataset.drawer === '0')) refreshVisible();
})(typeof window === 'undefined' ? null : window);
