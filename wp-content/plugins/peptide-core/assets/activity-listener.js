/* Cart-only view of authoritative Woo Store API progress. No cart writes or client discount engine. */
(function () {
    'use strict';
    const safeText = value => typeof value === 'string' && value.length <= 1000;
    const minor = value => (typeof value === 'string' && /^\d{1,16}$/.test(value) || Number.isSafeInteger(value)) && Number.isSafeInteger(Number(value)) && Number(value) >= 0;
    function normalize(value) {
        if (!value || value.version !== 1 || value.enabled !== true || value.visible !== true || !['tracking', 'tier_reached', 'complete', 'no_savings'].includes(value.status)) return null;
        if (!safeText(value.stateKey) || !value.stateKey || !safeText(value.promotionRevision) || !value.promotionRevision || !Number.isSafeInteger(value.revision) || !['spend', 'quantity'].includes(value.basis) || !minor(value.measure) || !safeText(value.measureText)) return null;
        if (!Number.isFinite(value.progressPercent) || value.progressPercent < 0 || value.progressPercent > 100 || !Array.isArray(value.nodes) || !value.nodes.length || value.nodes.length > 100) return null;
        if (!value.promotion || !Number.isSafeInteger(value.promotion.id) || value.promotion.id <= 0 || !value.celebration || typeof value.celebration.enabled !== 'boolean' || typeof value.celebration.smileEnabled !== 'boolean' || typeof value.completed !== 'boolean' || typeof value.discountApplied !== 'boolean') return null;
        const seen = new Set(); let last = -1;
        for (const node of value.nodes) {
            if (!node || !safeText(node.key) || !node.key || seen.has(node.key) || !minor(node.threshold) || !safeText(node.thresholdText) || !safeText(node.rewardText) || typeof node.reached !== 'boolean' || typeof node.celebrationEligible !== 'boolean' || !Number.isFinite(node.positionPercent) || node.positionPercent < 0 || node.positionPercent < last || node.positionPercent > 100) return null;
            seen.add(node.key); last = node.positionPercent;
        }
        if (value.next !== null && (!value.next || !seen.has(value.next.key) || !minor(value.next.remaining) || !safeText(value.next.remainingText))) return null;
        return value;
    }
    function decision(previous, current) {
        if (!current) return { baseline: true, celebrate: null };
        const identity = state => [state.revision, state.promotionRevision, state.promotion.id, state.basis].join('|');
        if (!previous || identity(previous) !== identity(current)) return { baseline: true, celebrate: null };
        if (previous.stateKey === current.stateKey || !current.discountApplied || current.celebration.enabled !== true) return { baseline: false, celebrate: null };
        // Merchant choice: every changed qualifying cart state can celebrate again, without a per-tier lock.
        const qualified = current.nodes.filter(node => node.reached && node.celebrationEligible);
        return { baseline: false, celebrate: qualified.length ? qualified[qualified.length - 1].key : null };
    }
    if (typeof module === 'object' && module.exports) module.exports = { normalize, decision };
    if (typeof window === 'undefined' || typeof document === 'undefined') return;
    const prefix = 'pc-activity-listener__';
    function element(tag, name, text) {
        const node = document.createElement(tag); node.className = prefix + name;
        if (text !== undefined) node.textContent = text;
        return node;
    }
    function init(root) {
        if (root.dataset.pcActivityReady) return;
        let settings;
        try { settings = JSON.parse(root.querySelector('.' + prefix + 'config').textContent); } catch (_) { return; }
        let cartUrl, imageUrl;
        try { cartUrl = new URL(settings.cartUrl, location.href); imageUrl = new URL(settings.mascotUrl, location.href); if (cartUrl.origin !== location.origin || imageUrl.origin !== location.origin) return; } catch (_) { return; }
        root.dataset.pcActivityReady = 'true';
        const labels = settings.labels || {}, label = (key, fallback) => safeText(labels[key]) ? labels[key] : fallback;
        const panel = element('section', 'panel'), top = element('div', 'top'), current = element('div', 'current'), gap = element('div', 'gap');
        panel.setAttribute('aria-label', settings.config.title || label('progress', 'Offer progress'));
        const milestones = element('div', 'milestones'), track = element('div', 'track'), fill = element('div', 'fill'), confetti = element('div', 'confetti'), mascot = element('div', 'mascot'), live = element('div', 'live');
        track.setAttribute('role', 'progressbar'); track.setAttribute('aria-label', label('progress', 'Offer progress')); track.setAttribute('aria-valuemin', '0'); track.setAttribute('aria-valuemax', '100');
        confetti.setAttribute('aria-hidden', 'true'); mascot.setAttribute('aria-hidden', 'true'); mascot.hidden = true;
        live.setAttribute('aria-live', 'polite'); live.setAttribute('aria-atomic', 'true');
        const canvas = document.createElement('canvas'); canvas.width = 256; canvas.height = 128; mascot.append(canvas);
        const railViewport = element('div', 'rail-viewport');
        track.append(fill); milestones.append(track, confetti, mascot); railViewport.append(milestones); top.append(current, gap); panel.append(top, railViewport, live); root.append(panel);
        const image = new Image(); image.src = imageUrl.href; image.decoding = 'async';
        const ctx = canvas.getContext('2d'), reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
        let last = null, nodeViews = new Map(), nodeSignature = '', token = 0, frame = 0, hideTimer = 0, animationNode = null;
        let inView = true, destroyed = false, request = null, requestId = 0, storeEpoch = 0, lastStoreKey = null, storePending = false, unsubscribe = null, debounce = 0, poll = 0;
        const smooth = value => { value = Math.max(0, Math.min(1, value)); return value * value * (3 - 2 * value); };
        function stop() { token++; cancelAnimationFrame(frame); clearTimeout(hideTimer); confetti.replaceChildren(); mascot.hidden = true; animationNode = null; if (ctx) ctx.clearRect(0, 0, 256, 128); }
        function direction(view) {
            const node = last && last.nodes.find(item => nodeViews.get(item.key) === view), box = milestones.getBoundingClientRect(), edge = panel.getBoundingClientRect();
            const position = Number(view.dataset.visualPosition || (node ? node.positionPercent : 0));
            const x = Math.max(24, Math.min(box.width, box.width * position / 100));
            mascot.style.setProperty('--pc-node-position', x + 'px'); mascot.dataset.side = edge.right - box.left - x < 112 ? 'left' : 'right';
        }
        function layout() {
            if (!last || root.hidden) return;
            const compact = panel.getBoundingClientRect().width <= 600;
            root.classList.toggle('is-compact', compact);
            milestones.classList.toggle('is-compact', compact);
            milestones.style.setProperty('--pc-compact-min-width', last.nodes.length * 64 + 'px');
            milestones.classList.remove('is-dense');
            last.nodes.forEach((node, index) => { const position = compact ? (index + 1) * 100 / last.nodes.length : node.positionPercent, view = nodeViews.get(node.key); view.dataset.visualPosition = String(position); view.style.setProperty('--pc-node-position', position + '%'); });
            if (compact) {
                // Re-space labels, then map the server percentage to those visual stops. No money/discount calculation.
                let from = 0, to = 0, progress = last.progressPercent;
                for (let i = 0; i < last.nodes.length; i++) {
                    const end = last.nodes[i].positionPercent, target = (i + 1) * 100 / last.nodes.length;
                    if (progress <= end) { progress = end === from ? (last.nodes[i].reached ? target : to) : to + (progress - from) / (end - from) * (target - to); break; }
                    from = end; to = target;
                }
                fill.style.width = Math.min(100, progress) + '%'; return;
            }
            fill.style.width = last.progressPercent + '%';
            let previous = null, dense = false;
            nodeViews.forEach(view => {
                const dot = view.getBoundingClientRect(), caption = view.querySelector('.' + prefix + 'node-label').getBoundingClientRect();
                if (caption.height > 54 || previous && (dot.left - previous.dot < 26 || caption.left < previous.right + 8)) dense = true;
                previous = { dot: dot.left, right: caption.right };
            });
            milestones.classList.toggle('is-dense', dense);
        }
        function paint(progress, still) {
            if (!ctx) return;
            const extension = still ? 1 : smooth((progress - .08) / .16) * (1 - smooth((progress - .66) / .18));
            const breath = still ? 0 : Math.sin(Math.min(1, progress / .42) * Math.PI);
            ctx.setTransform(2, 0, 0, 2, 0, 0); ctx.clearRect(0, 0, 128, 64); ctx.save();
            ctx.globalAlpha = still ? 1 : smooth(progress / .09) * (1 - smooth((progress - .86) / .14));
            if (mascot.dataset.side === 'left') { ctx.translate(128, 0); ctx.scale(-1, 1); }
            ctx.translate(0, -1.5 * breath); ctx.save(); ctx.translate(24, 30); ctx.scale(1 + .025 * breath, 1 - .016 * breath); ctx.translate(-24, -30);
            ctx.drawImage(image, 0, 0, 760, 819, 0, 8, 44.08, 47.502); ctx.restore();
            const length = 55.1 * extension;
            if (length > .1) ctx.drawImage(image, 760, 342, 950, 75, 44.08, 27.836, length, 4.35);
            ctx.drawImage(image, 1710, 225, 210, 205, 44.08 + length, 21.05, 12.18, 11.89); ctx.restore();
        }
        function burst(atTip, view) {
            const layer = confetti.getBoundingClientRect(), box = mascot.getBoundingClientRect(), dot = view.getBoundingClientRect();
            const x = atTip ? box.left + (mascot.dataset.side === 'left' ? 23 : 105) : dot.left;
            const y = atTip ? box.top + 26 : dot.top - 32;
            const colors = ['#e4ba54', '#7ab9a8', '#ac8bc6', '#191919'];
            for (let i = 0; i < 16; i++) {
                const part = element('span', 'particle'), angle = i / 16 * Math.PI * 2;
                part.style.left = x - layer.left + 'px'; part.style.top = y - layer.top + 'px'; part.style.background = colors[i % 4];
                part.style.setProperty('--dx', Math.cos(angle) * (18 + i % 3 * 5) + 'px'); part.style.setProperty('--dy', Math.sin(angle) * (18 + i % 3 * 5) - 8 + 'px'); part.style.setProperty('--turn', angle * 180 / Math.PI + 'deg'); confetti.append(part);
            }
        }
        function celebrate(key, state) {
            stop(); const view = nodeViews.get(key), node = state.nodes.find(item => item.key === key);
            if (!view || !node || document.hidden || !inView || destroyed) return;
            live.textContent = node.thresholdText + ': ' + node.rewardText;
            const generation = token, showBean = state.celebration.smileEnabled === true && !!ctx;
            mascot.style.setProperty('--pc-node-position', node.positionPercent + '%'); animationNode = view; direction(view);
            const start = () => {
                if (generation !== token || destroyed || document.hidden || !inView) return;
                if (showBean && image.naturalWidth) mascot.hidden = false;
                if (reduced.matches) { if (showBean && image.naturalWidth) paint(0, true); hideTimer = setTimeout(stop, 2000); return; }
                let began = null, fired = false;
                const tick = now => {
                    if (generation !== token) return;
                    if (began === null) began = now;
                    const progress = Math.min(1, (now - began) / 2800);
                    if (!mascot.hidden) paint(progress, false);
                    if (progress >= .24 && !fired) { fired = true; burst(!mascot.hidden, view); }
                    if (progress < 1) frame = requestAnimationFrame(tick); else stop();
                };
                frame = requestAnimationFrame(tick);
            };
            if (!showBean || image.complete && image.naturalWidth) start();
            else image.decode().then(start).catch(() => { if (generation === token) start(); });
        }
        function hide() { stop(); root.hidden = true; root.removeAttribute('aria-busy'); last = null; observeVisibility(false); }
        function accept(raw) {
            if (destroyed) return;
            const state = normalize(raw);
            if (!state) { hide(); return; }
            if (last && last.stateKey === state.stateKey) { root.removeAttribute('aria-busy'); return; }
            const action = decision(last, state);
            if (action.baseline || last && Number(state.measure) < Number(last.measure)) stop();
            if (!state.discountApplied || !state.celebration.enabled || animationNode && !state.nodes.some(node => nodeViews.get(node.key) === animationNode && node.celebrationEligible)) stop();
            const signature = state.nodes.map(node => node.key).join('|');
            if (signature !== nodeSignature) {
                stop(); nodeViews.forEach(view => view.remove()); nodeViews = new Map(); nodeSignature = signature;
                state.nodes.forEach((node, index) => {
                    const view = element('div', 'node'), dot = element('span', 'dot'), caption = element('div', 'node-label'), reward = element('strong', 'reward', node.rewardText);
                    view.style.setProperty('--pc-node-position', node.positionPercent + '%'); view.dataset.nodeKey = node.key;
                    if (index === state.nodes.length - 1) view.classList.add('is-last');
                    if (index === 0 && node.positionPercent < 12) view.classList.add('is-first');
                    const check = document.createElementNS('http://www.w3.org/2000/svg', 'svg'), path = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
                    check.setAttribute('viewBox', '0 0 16 16'); check.setAttribute('fill', 'none'); check.setAttribute('stroke', 'currentColor'); path.setAttribute('points', '3,8 6,11 13,4'); check.append(path); dot.append(check);
                    dot.setAttribute('aria-hidden', 'true'); caption.append(document.createTextNode(node.thresholdText), reward); view.append(dot, caption); milestones.append(view); nodeViews.set(node.key, view);
                });
            }
            state.nodes.forEach(node => { const view = nodeViews.get(node.key); view.classList.toggle('is-reached', node.reached && state.discountApplied); view.querySelector('.' + prefix + 'reward').textContent = node.rewardText; });
            current.replaceChildren(document.createTextNode(label('current', 'Current') + ' '), element('strong', 'amount', state.measureText));
            gap.replaceChildren();
            if (state.next) gap.append(document.createTextNode(label('remaining', 'To next tier') + ' '), element('strong', 'remaining', state.next.remainingText));
            else gap.textContent = state.completed ? label('complete', 'Highest tier reached') : '—';
            track.setAttribute('aria-valuenow', String(state.progressPercent)); track.setAttribute('aria-valuetext', state.next ? state.next.remainingText : state.completed ? label('complete', 'Highest tier reached') : label('noSavings', 'No offer savings applied'));
            fill.style.width = state.progressPercent + '%'; root.hidden = false; root.removeAttribute('aria-busy'); last = state; observeVisibility(true); layout();
            if (action.celebrate) celebrate(action.celebrate, state);
        }
        function extension(cart) { return cart && cart.extensions && cart.extensions[settings.extensionNamespace] && cart.extensions[settings.extensionNamespace][settings.extensionKey]; }
        function abort() { requestId++; if (request) request.abort(); request = null; }
        async function refresh() {
            if (destroyed || document.hidden || !inView || storePending) return;
            abort(); const id = requestId, epoch = storeEpoch, controller = new AbortController(); request = controller;
            const timeout = setTimeout(() => controller.abort(), 10000);
            try {
                const options = { url: cartUrl.href, method: 'GET', cache: 'no-store', signal: controller.signal };
                const cart = window.wp && window.wp.apiFetch ? await window.wp.apiFetch(options) : await fetch(cartUrl.href, { credentials: 'same-origin', cache: 'no-store', signal: controller.signal }).then(response => { if (!response.ok) throw new Error('Cart unavailable'); return response.json(); });
                if (id === requestId && epoch === storeEpoch && !destroyed) accept(extension(cart));
            } catch (_) { if (id === requestId && epoch === storeEpoch && !destroyed) hide(); }
            finally { clearTimeout(timeout); if (id === requestId) request = null; }
        }
        function schedule(event) { if (event && event.type === 'vc:cart-updated' && event.detail && (event.detail.stale || event.detail.cart === null)) stop(); clearTimeout(debounce); debounce = setTimeout(refresh, 100); }
        const store = settings.blockCart === true && window.wc && window.wc.wcBlocksData && window.wc.wcBlocksData.cartStore;
        function readStore() {
            if (destroyed || !store || !window.wp || !window.wp.data) return;
            try {
                const selectors = window.wp.data.select(store), cart = selectors.getCartData();
                if (typeof selectors.hasFinishedResolution === 'function' && !selectors.hasFinishedResolution('getCartData')) return;
                const pending = ['isApplyingCoupon', 'isRemovingCoupon', 'isCustomerDataUpdating', 'isCartDataStale'].some(name => typeof selectors[name] === 'function' && selectors[name]()) || (cart.items || []).some(item => ['isItemPendingQuantity', 'isItemPendingDelete'].some(name => typeof selectors[name] === 'function' && selectors[name](item.key)));
                if (pending) { if (!storePending) { storeEpoch++; abort(); } storePending = true; stop(); root.setAttribute('aria-busy', 'true'); return; }
                storePending = false; root.removeAttribute('aria-busy');
                const value = extension(cart);
                if (value && value.stateKey !== lastStoreKey) { lastStoreKey = value.stateKey; storeEpoch++; abort(); accept(value); }
            } catch (_) { schedule(); }
        }
        function visibility() { stop(); abort(); clearInterval(poll); if (!document.hidden && inView) { last = null; refresh(); poll = setInterval(refresh, 30000); } }
        function resize() { layout(); if (animationNode && !mascot.hidden) direction(animationNode); confetti.replaceChildren(); }
        const observer = typeof IntersectionObserver === 'function' ? new IntersectionObserver(entries => { const was = inView; inView = entries[0].isIntersecting; if (!inView) { stop(); abort(); clearInterval(poll); } else if (!was) visibility(); }) : null;
        let observed = null;
        function observeVisibility(shown) { if (!observer) return; const target = shown ? panel : root.parentElement || root; if (target === observed) return; if (observed) observer.unobserve(observed); observed = target; observer.observe(target); }
        observeVisibility(false);
        function destroy(event) { if (event && event.persisted) { stop(); abort(); return; } destroyed = true; stop(); abort(); clearTimeout(debounce); clearInterval(poll); if (unsubscribe) unsubscribe(); if (observer) observer.disconnect(); removal.disconnect(); document.removeEventListener('visibilitychange', visibility); window.removeEventListener('resize', resize); window.removeEventListener('pageshow', visibility); window.removeEventListener('pagehide', destroy); reduced.removeEventListener('change', stop); document.body.removeEventListener('wc-blocks_added_to_cart', schedule); document.body.removeEventListener('wc-blocks_removed_from_cart', schedule); document.removeEventListener('vc:cart-updated', schedule); if (window.jQuery) window.jQuery(document.body).off('.pcActivityListener', schedule); }
        const removal = new MutationObserver(() => { if (!root.isConnected) { destroy(); boot(); } }); if (root.parentNode) removal.observe(root.parentNode, { childList: true });
        if (store && window.wp && window.wp.data) { unsubscribe = window.wp.data.subscribe(readStore, store); readStore(); }
        document.addEventListener('visibilitychange', visibility); window.addEventListener('resize', resize); window.addEventListener('pagehide', destroy); window.addEventListener('pageshow', visibility); reduced.addEventListener('change', stop);
        document.body.addEventListener('wc-blocks_added_to_cart', schedule); document.body.addEventListener('wc-blocks_removed_from_cart', schedule); document.addEventListener('vc:cart-updated', schedule);
        if (window.jQuery) window.jQuery(document.body).on('updated_wc_div.pcActivityListener updated_cart_totals.pcActivityListener applied_coupon.pcActivityListener removed_coupon.pcActivityListener', schedule);
        refresh(); poll = setInterval(refresh, 30000);
    }
    function boot() { document.querySelectorAll('[data-pc-activity-listener][data-cart-only]').forEach(init); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true }); else boot();
}());
