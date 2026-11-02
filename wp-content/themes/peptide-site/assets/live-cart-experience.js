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
    function activityPresentation(state, settings = {}) {
        const activity = state.valid && !state.pending ? state.cart?.extensions?.peptide_core?.activity_listener || null : null;
        return { activity, progress: settings.cart_progress_enabled === false ? null : activity, recommendation: settings.cart_recommendations_enabled === false ? null : activity };
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
            if (destroyed || pending || !valid || !nonce) throw new Error('Your bag is updating. Please try again when it is ready.');
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
    if (typeof module === 'object' && module.exports) module.exports = { validCart, money, totals, createClient, activityPresentation };
    if (!global?.document || !global.VialCoveLiveCartConfig) return;
    const document = global.document, config = global.VialCoveLiveCartConfig, settings = config.settings || {}, roots = [...document.querySelectorAll('[data-vc-live-cart]')];
    if (!roots.length) return;
    const drawer = document.getElementById('vc-live-cart-drawer'), isDesktop = global.matchMedia('(min-width: 768px)'), parentIds = new Map();
    const el = (tag, cls, text) => { const node = document.createElement(tag); node.className = cls || ''; if (text !== undefined) node.textContent = text; return node; };
    const clean = value => { const node = document.createElement('textarea'); node.innerHTML = String(value || ''); return node.value; };
    const safeUrl = value => { try { const url = new URL(value, location.href); return ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password ? url.href : ''; } catch (_) { return ''; } };
    let recent = null, recommendation = null, enrichment = 0, enrichmentTimer = 0, sourceElement = null, quickOpener = null, disposed = false;
    const views = roots.map(root => ({ root, progress: global.createCartProgress?.({ mount: root.querySelector('[data-cart-progress]') }), progressSignature: '', recommendation: global.createCartRecommendation?.({ mount: root.querySelector('[data-cart-recommendation]'), onChoose: candidate => choose(candidate.productId), onAdd: candidate => client.write('add-item', { id: candidate.purchasableId, quantity: candidate.quantity }) }) }));
    const client = createClient(config, { notify(type, detail) {
        if (type === 'state') { render(detail); scheduleEnrichment(detail); }
        else document.dispatchEvent(new CustomEvent(type === 'start' ? 'vc:cart-mutation-start' : 'vc:cart-updated', { detail }));
    } });
    const deviceEnabled = () => (settings.visibility || {})[isDesktop.matches ? 'desktop' : 'mobile'] !== false;
    const canRead = () => deviceEnabled() && !document.hidden && roots.some(root => !root.closest('dialog') || root.closest('dialog').open);
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
        const image = el('img'); image.src = safeUrl(item.images?.[0]?.thumbnail || item.images?.[0]?.src) || ''; image.alt = clean(item.name); image.width = 72; image.height = 80; imageLink.append(image);
        const info = el('div', 'vc-live-cart__info'); info.append(productLink(item));
        const pack = (item.variation || []).map(v => clean(v.value)).filter(Boolean).join(' · '); if (pack) info.append(el('p', 'vc-live-cart__pack', pack));
        info.append(el('p', 'vc-live-cart__unit', money(item.prices?.price, item.prices) + ' / box'));
        const controls = el('div', 'vc-live-cart__controls'), qty = el('div', 'vc-live-cart__quantity');
        const limits = item.quantity_limits || {}, step = Number.isSafeInteger(limits.multiple_of) && limits.multiple_of > 0 ? limits.multiple_of : 1, min = Number.isSafeInteger(limits.minimum) ? Math.max(1, limits.minimum) : 1, max = Number.isSafeInteger(limits.maximum) ? limits.maximum : 9999;
        for (const [action, text, quantity] of [['minus', '−', item.quantity - step], ['plus', '+', item.quantity + step]]) {
            const button = el('button', '', text); button.type = 'button'; button.dataset.cartAction = action;
            button.setAttribute('aria-label', (action === 'minus' ? 'Decrease' : 'Increase') + ' quantity of ' + clean(item.name));
            button.disabled = !state.valid || state.pending || limits.editable === false || quantity < min || quantity > max;
            button.addEventListener('click', () => client.write('update-item', { key: item.key, quantity }).catch(() => {}));
            qty.append(button); if (action === 'minus') { const count = el('span', '', String(item.quantity)); count.setAttribute('aria-label', item.quantity + (item.quantity === 1 ? ' box' : ' boxes')); qty.append(count); }
        }
        const remove = el('button', 'vc-live-cart__remove', 'Remove'); remove.type = 'button'; remove.dataset.cartAction = 'remove'; remove.disabled = !state.valid || state.pending; remove.setAttribute('aria-label', 'Remove ' + clean(item.name));
        remove.addEventListener('click', () => client.write('remove-item', { key: item.key }).catch(() => {})); controls.append(qty, remove); info.append(controls);
        row.append(imageLink, info, el('strong', 'vc-live-cart__line-total', money(item.totals?.line_total, item.totals))); return row;
    }
    function render(state) {
        if (disposed) return;
        const { cart, valid, pending, error } = state;
        if (valid) document.querySelectorAll('.vc-cart-count').forEach(node => { node.textContent = String(cart.items_count); });
        for (const view of views) {
            const root = view.root, firstReady = root.hidden && valid && deviceEnabled();
            if (!deviceEnabled()) { root.hidden = true; const fallback = root.nextElementSibling; if (fallback?.matches('[data-vc-native-cart-fallback]')) fallback.hidden = false; continue; }
            if (firstReady) { root.hidden = false; const fallback = root.nextElementSibling; if (fallback?.matches('[data-vc-native-cart-fallback]')) fallback.hidden = true; }
            const status = root.querySelector('[data-cart-status]'); status.textContent = error ? clean(error) : pending ? 'Updating your bag…' : valid ? '' : 'Loading your bag…';
            root.querySelector('[data-cart-retry]').hidden = !error || pending;
            root.querySelector('[data-cart-count]').textContent = cart ? cart.items_count + (cart.items_count === 1 ? ' box' : ' boxes') : '';
            const presentation = activityPresentation(state, settings), activity = presentation.activity;
            // Pending writes pause the visual but preserve its baseline for the confirmed next DTO.
            const progressMount = root.querySelector('[data-cart-progress]');
            if (pending) { view.progress?.pause(); progressMount.setAttribute('aria-busy', 'true'); }
            else { const signature = JSON.stringify(presentation.progress); if (view.progressSignature !== signature) { view.progressSignature = signature; view.progress?.update(presentation.progress); } progressMount.removeAttribute('aria-busy'); }
            const quoteActivity = activity && recommendation ? { ...activity, recommendation } : activity;
            view.recommendation?.update({ activity: presentation.recommendation ? quoteActivity : null, snapshotKey: activity?.cartSnapshotKey || '', valid, pending });
            const active = document.activeElement, activeRow = active?.closest('[data-cart-key]'), key = root.contains(active) ? activeRow?.dataset.cartKey : '', action = active?.dataset.cartAction, list = root.querySelector('[data-cart-items]');
            const previousIndex = key ? [...list.children].findIndex(row => row.dataset.cartKey === key) : -1;
            if (key && action) view.restoreFocus = { key, action, index: previousIndex };
            if (cart) list.replaceChildren(...cart.items.map(item => cartRow(item, state)));
            if (view.restoreFocus && !pending) { const saved = view.restoreFocus, rows = [...list.children], target = rows.find(row => row.dataset.cartKey === saved.key) || rows[Math.min(Math.max(saved.index, 0), rows.length - 1)]; (target?.querySelector('[data-cart-action="' + saved.action + '"]:not(:disabled)') || target?.querySelector('a') || root.querySelector('h1,h2'))?.focus({ preventScroll: true }); view.restoreFocus = null; }
            root.querySelector('[data-cart-empty]').hidden = !valid || !!cart?.items.length;
            const summary = root.querySelector('[data-cart-summary]'); summary.hidden = !cart?.items.length;
            root.querySelector('[data-cart-totals]').replaceChildren(...totals(cart).map(total => { const row = el('div', 'vc-live-cart__total-row' + (total.total ? ' is-total' : '') + (total.savings ? ' is-saving' : '')); row.append(el('span', '', clean(total.label)), el('strong', '', total.unknown ? 'At checkout' : money(total.minor, cart.totals))); return row; }));
            const checkout = root.querySelector('[data-cart-checkout]'); checkout.setAttribute('aria-disabled', String(!valid || pending || !cart?.items.length));
            root.querySelectorAll('[data-cart-coupon] input,[data-cart-coupon] button').forEach(node => { node.disabled = !valid || pending; });
            root.querySelector('[data-cart-coupons]').replaceChildren(...(cart?.coupons || []).map(coupon => { const button = el('button', '', clean(coupon.code) + ' ×'); button.type = 'button'; button.disabled = !valid || pending; button.setAttribute('aria-label', 'Remove coupon ' + clean(coupon.code)); button.addEventListener('click', () => client.write('remove-coupon', { code: coupon.code }).catch(() => {})); return button; }));
            renderRecent(root, state);
        }
    }
    function renderRecent(root, state) {
        const section = root.querySelector('[data-cart-recent]'); section.hidden = settings.cart_recent_enabled === false || !state.valid || state.pending || !recent?.visible || !recent.items?.length;
        if (section.hidden) return;
        const heading = section.querySelector('h2'); heading.textContent = recent.title || 'Recently viewed';
        root.querySelector('[data-cart-recent-items]').replaceChildren(...recent.items.slice(0, 2).map(item => { const card = el('article', 'vc-live-cart__recent-card'), link = productLink({ id: item.productId, name: item.name, permalink: item.permalink }); link.dataset.vcHomeProduct = String(item.productId); link.dataset.vcQuickOpen = String(item.productId); const image = el('img'); image.src = safeUrl(item.image?.url); image.alt = item.image?.alt || item.name; image.width = 60; image.height = 68; const imageLink = link.cloneNode(false); imageLink.append(image); card.append(imageLink, link, el('p', '', item.variationLabel || ''), el('strong', '', (item.priceIsFrom ? 'From ' : '') + (item.price?.text || ''))); return card; }));
    }
    function scheduleEnrichment(state) {
        clearTimeout(enrichmentTimer); const generation = ++enrichment;
        if (!state.valid || state.pending || !canRead()) { recent = null; recommendation = null; return; }
        enrichmentTimer = setTimeout(async () => {
            const activity = state.cart?.extensions?.peptide_core?.activity_listener;
            const requests = state.cart.items.filter(item => !parentIds.has(item.id)).map(async item => { try { const product = await client.request(config.productsApiUrl + item.id); if (Number.isSafeInteger(product.id)) parentIds.set(item.id, product.parent > 0 ? product.parent : product.id); } catch (_) {} });
            let history = null;
            try { history = await client.request(config.recentItemsUrl); } catch (_) { /* Private history unavailable: no invented recent list or ranking. */ }
            await Promise.all(requests);
            if (disposed || generation !== enrichment) return;
            recent = history; recommendation = null; render(client.snapshot());
            if (settings.cart_recommendations_enabled === false || !activity?.next || !history?.candidates?.length) return;
            try {
                const result = await client.request(config.recommendationsUrl, { cartStateKey: activity.stateKey, cartSnapshotKey: activity.cartSnapshotKey, promotionRevision: activity.promotionRevision, configRevision: activity.revision, candidates: history.candidates.slice(0, 20) });
                if (disposed || generation !== enrichment) return;
                recommendation = result.recommendation; render(client.snapshot());
            } catch (_) { if (generation === enrichment) { recommendation = null; render(client.snapshot()); } }
        }, 250);
    }
    function openDrawer(trigger) {
        if (!drawer || drawer.open || !isDesktop.matches || !deviceEnabled()) return;
        sourceElement = trigger; drawer.showModal(); document.documentElement.classList.add('vc-live-cart-open');
        document.dispatchEvent(new CustomEvent('vc:live-cart-opened', { detail: { dialog: drawer, trigger } }));
        client.refresh();
    }
    function closeDrawer() {
        if (!drawer?.open) return;
        drawer.close(); document.documentElement.classList.remove('vc-live-cart-open');
        sourceElement?.focus?.({ preventScroll: true });
        document.dispatchEvent(new CustomEvent('vc:live-cart-closed', { detail: { dialog: drawer, trigger: sourceElement } }));
    }
    document.addEventListener('click', event => {
        const link = event.target.closest?.('.cart-link,[data-vc-open-cart]');
        if (link && isDesktop.matches && deviceEnabled() && drawer && !event.ctrlKey && !event.metaKey && !event.shiftKey && event.button === 0) { event.preventDefault(); openDrawer(link); }
        if (event.target.closest?.('[data-cart-close]')) closeDrawer();
        const checkout = event.target.closest?.('[data-cart-checkout]'); if (checkout?.getAttribute('aria-disabled') === 'true') event.preventDefault();
    });
    drawer?.addEventListener('click', event => { if (event.target === drawer) closeDrawer(); });
    drawer?.addEventListener('cancel', event => { event.preventDefault(); closeDrawer(); });
    isDesktop.addEventListener('change', () => { if (!isDesktop.matches && drawer?.open) closeDrawer(); render(client.snapshot()); if (canRead()) client.refresh(); });
    for (const root of roots) {
        root.querySelector('[data-cart-retry]').addEventListener('click', () => client.refresh());
        root.querySelector('[data-cart-coupon]').addEventListener('submit', event => { event.preventDefault(); const input = event.currentTarget.elements.coupon, code = input.value.trim(); if (code) client.write('apply-coupon', { code }).then(() => { input.value = ''; }).catch(() => {}); });
    }
    const started = event => { if (event.detail?.owner !== 'live-cart') client.externalStart(); };
    const updated = event => { if (event.detail?.owner !== 'live-cart') client.externalUpdate(event.detail); };
    document.addEventListener('vc:cart-mutation-start', started); document.addEventListener('vc:cart-updated', updated);
    document.addEventListener('vc:recently-viewed-updated', () => { if (!disposed && canRead()) scheduleEnrichment(client.snapshot()); });
    const refreshVisible = () => { if (canRead()) client.refresh(); };
    document.addEventListener('visibilitychange', refreshVisible); global.addEventListener('focus', refreshVisible); global.addEventListener('pageshow', refreshVisible);
    const polling = setInterval(refreshVisible, 30000);
    global.VialCoveLiveCart = { open: trigger => isDesktop.matches && drawer ? openDrawer(trigger) : location.assign(config.cartUrl), close: closeDrawer, refresh: () => client.refresh(), snapshot: () => client.snapshot() };
    global.addEventListener('pagehide', event => { if (event.persisted) return; disposed = true; quickOpener?.remove(); clearInterval(polling); clearTimeout(enrichmentTimer); client.destroy(); views.forEach(view => { view.progress?.destroy(); view.recommendation?.destroy(); }); });
    if (deviceEnabled() && roots.some(root => root.dataset.drawer === '0')) client.refresh();
})(typeof window === 'undefined' ? null : window);
