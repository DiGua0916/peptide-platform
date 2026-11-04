/* Theme view of authoritative Woo Store API progress. No cart writes, polling, or client discount engine. */
(function () {
    'use strict';
    const safeText = value => typeof value === 'string' && value.length <= 1000;
    const minor = value => (typeof value === 'string' && /^\d{1,16}$/.test(value) || Number.isSafeInteger(value)) && Number.isSafeInteger(Number(value)) && Number(value) >= 0;
    function normalize(value) {
        if (!value || value.version !== 1 || value.enabled !== true || value.visible !== true || !['tracking', 'tier_reached', 'complete', 'no_savings'].includes(value.status)) return null;
        if (!safeText(value.stateKey) || !value.stateKey || !safeText(value.promotionRevision) || !value.promotionRevision || !Number.isSafeInteger(value.revision) || !['spend', 'quantity'].includes(value.basis) || !minor(value.measure) || !safeText(value.measureText)) return null;
        if (!Number.isFinite(value.progressPercent) || value.progressPercent < 0 || value.progressPercent > 100 || !Array.isArray(value.nodes) || !value.nodes.length || value.nodes.length > 100) return null;
        if (!value.promotion || !Number.isSafeInteger(value.promotion.id) || value.promotion.id <= 0 || !value.celebration || typeof value.celebration.enabled !== 'boolean' || typeof value.celebration.smileEnabled !== 'boolean' || typeof value.completed !== 'boolean' || typeof value.discountApplied !== 'boolean' || !minor(value.actualDiscount?.minor) || !safeText(value.actualDiscount?.text)) return null;
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
        if (previous.stateKey === current.stateKey || !current.discountApplied || !minor(current.actualDiscount?.minor) || Number(current.actualDiscount.minor) <= 0 || current.celebration.enabled !== true) return { baseline: false, celebrate: null };
        // The applied backend behavior celebrates every changed qualifying state, not only the first crossing.
        const qualified = current.nodes.filter(node => node.reached && node.celebrationEligible);
        return { baseline: false, celebrate: qualified.length ? qualified[qualified.length - 1].key : null };
    }
    function labelLanes(rectangles, gap = 8) {
        const ends = [];
        return rectangles.map(rect => { let lane = ends.findIndex(end => end + gap <= rect.left); if (lane < 0) lane = ends.length; ends[lane] = rect.right; return lane; });
    }
    // Compact whole-dollar captions only; the server values and full accessible labels stay intact.
    const compactCaption = text => text.replace(/^(\$[\d,]+)\.00(?=\s|$)/, '$1');
    function progressMessage(state) {
        if (state.next) {
            const reward = state.nodes.find(node => node.key === state.next.key).rewardText.trim();
            const action = state.basis === 'quantity' ? 'Add' : 'Spend';
            const lead = action + ' ' + state.next.remainingText + ' more to unlock ';
            return /\boff$/i.test(reward) ? lead + reward + ' your order.' : lead + 'your next order offer: ' + reward + '.';
        }
        if (state.completed && state.discountApplied && Number(state.actualDiscount.minor) > 0) return 'Highest tier unlocked. ' + state.actualDiscount.text + ' off your order.';
        return 'No offer savings applied.';
    }
    if (typeof module === 'object' && module.exports) module.exports = { normalize, decision, labelLanes, progressMessage };
    if (typeof window === 'undefined' || typeof document === 'undefined') return;
    window.cartProgressMessage = progressMessage;
    const prefix = 'pc-activity-listener__';
    function element(tag, name, text) {
        const node = document.createElement(tag); node.className = prefix + name;
        if (text !== undefined) node.textContent = text;
        return node;
    }
    window.createCartProgress = ({ mount: root, title = 'Your offer progress' }) => {
        root.classList.add('pc-activity-listener');
        const imageUrl = new URL(window.VialCoveLiveCartConfig.mascotUrl, location.href);
        const panel = element('section', 'panel'), message = element('p', 'message');
        panel.setAttribute('aria-label', title);
        const milestones = element('div', 'milestones'), track = element('div', 'track'), fill = element('div', 'fill'), confetti = element('div', 'confetti'), mascot = element('div', 'mascot'), live = element('div', 'live');
        track.setAttribute('role', 'progressbar'); track.setAttribute('aria-label', title); track.setAttribute('aria-valuemin', '0'); track.setAttribute('aria-valuemax', '100');
        confetti.setAttribute('aria-hidden', 'true'); mascot.setAttribute('aria-hidden', 'true'); mascot.hidden = true;
        live.setAttribute('aria-live', 'polite'); live.setAttribute('aria-atomic', 'true');
        const canvas = document.createElement('canvas'); canvas.width = 256; canvas.height = 128; mascot.append(canvas);
        track.append(fill); milestones.append(track, confetti, mascot); panel.append(milestones, message, live); root.replaceChildren(panel); root.hidden = true;
        const image = new Image(); image.src = imageUrl.href; image.decoding = 'async';
        let ctx = null; try { ctx = canvas.getContext('2d'); } catch (_) {}
        const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
        let last = null, nodeViews = new Map(), nodeSignature = '', token = 0, frame = 0, hideTimer = 0, animationNode = null;
        let destroyed = false;
        function visible() { const r = root.getBoundingClientRect(); return !root.hidden && !document.hidden && r.bottom > 0 && r.top < window.innerHeight && r.right > 0 && r.left < window.innerWidth; }
        const smooth = value => { value = Math.max(0, Math.min(1, value)); return value * value * (3 - 2 * value); };
        function stop() { token++; cancelAnimationFrame(frame); clearTimeout(hideTimer); confetti.replaceChildren(); mascot.hidden = true; animationNode = null; root.classList.remove('has-mascot'); root.dataset.celebrating = 'false'; if (ctx) { ctx.setTransform(1, 0, 0, 1, 0, 0); ctx.clearRect(0, 0, 256, 128); } }
        function direction(view) {
            const node = last && last.nodes.find(item => nodeViews.get(item.key) === view), box = milestones.getBoundingClientRect(), edge = panel.getBoundingClientRect();
            const x = Math.max(24, Math.min(box.width, node ? box.width * node.positionPercent / 100 : view.getBoundingClientRect().left - box.left));
            mascot.style.setProperty('--pc-node-position', x + 'px'); mascot.dataset.side = edge.right - box.left - x < 112 ? 'left' : 'right';
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
            if (!view || !node || !visible() || destroyed) return;
            live.textContent = node.thresholdText + ': ' + node.rewardText;
            const generation = token, showBean = state.celebration.smileEnabled === true && !!ctx;
            mascot.style.setProperty('--pc-node-position', node.positionPercent + '%'); animationNode = view; direction(view);
            const start = () => {
                if (generation !== token || destroyed || !visible()) return;
                if (showBean && image.naturalWidth) { mascot.hidden = false; root.classList.add('has-mascot'); }
                root.dataset.celebrating = 'true'; root.dataset.celebratedTier = key;
                if (reduced.matches) { if (showBean && image.naturalWidth) paint(0, true); hideTimer = setTimeout(stop, 2000); return; }
                let began = null, fired = false;
                const tick = now => {
                    if (generation !== token) return;
                    if (!visible()) { stop(); return; }
                    if (began === null) began = now;
                    const progress = Math.min(1, (now - began) / 2800);
                    if (!mascot.hidden) paint(progress, false);
                    if (progress >= .24 && !fired) { fired = true; burst(!mascot.hidden, view); }
                    if (progress < 1) frame = requestAnimationFrame(tick); else stop();
                };
                frame = requestAnimationFrame(tick);
            };
            if (!showBean || image.complete && image.naturalWidth) start();
            else if (typeof image.decode === 'function') image.decode().then(start).catch(() => { if (generation === token) start(); });
            else start();
        }
        function hide() { stop(); root.hidden = true; root.removeAttribute('aria-busy'); last = null; live.textContent = ''; }
        function update(raw, config) {
            if (destroyed) return;
            if (safeText(config?.title) && config.title) title = config.title;
            panel.setAttribute('aria-label', title); track.setAttribute('aria-label', title);
            const state = normalize(raw);
            if (!state) { hide(); return; }
            const action = decision(last, state);
            if (action.baseline || Number(state.actualDiscount.minor) <= 0 || animationNode && !state.nodes.some(node => nodeViews.get(node.key) === animationNode && node.reached && node.celebrationEligible)
                || last && (Number(state.measure) < Number(last.measure) || last.discountApplied && !state.discountApplied || state.celebration.enabled !== true || state.celebration.smileEnabled !== last.celebration.smileEnabled)) stop();
            const signature = JSON.stringify(state.nodes.map(node => [node.key, node.positionPercent, node.thresholdText, node.rewardText]));
            if (signature !== nodeSignature) {
                stop(); nodeViews.forEach(view => view.remove()); nodeViews = new Map(); nodeSignature = signature;
                state.nodes.forEach((node, index) => {
                    const view = element('div', 'node'), dot = element('span', 'dot'), caption = element('div', 'node-label'), reward = element('strong', 'reward', compactCaption(node.rewardText));
                    view.style.setProperty('--pc-node-position', node.positionPercent + '%'); view.dataset.nodeKey = node.key;
                    if (index === state.nodes.length - 1) view.classList.add('is-last');
                    if (index === 0 && node.positionPercent < 12) view.classList.add('is-first');
                    const check = document.createElementNS('http://www.w3.org/2000/svg', 'svg'), path = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
                    check.setAttribute('viewBox', '0 0 16 16'); check.setAttribute('fill', 'none'); check.setAttribute('stroke', 'currentColor'); path.setAttribute('points', '3,8 6,11 13,4'); check.append(path); dot.append(check);
                    dot.setAttribute('aria-hidden', 'true'); caption.append(document.createTextNode(compactCaption(node.thresholdText)), reward); view.append(dot, caption); milestones.append(view); nodeViews.set(node.key, view);
                });
            }
            state.nodes.forEach(node => { const view = nodeViews.get(node.key); view.classList.toggle('is-reached', node.reached && state.discountApplied && Number(state.actualDiscount?.minor) > 0); view.setAttribute('aria-label', node.thresholdText + '. ' + node.rewardText + (node.reached && state.discountApplied ? '. Reached.' : '')); view.querySelector('.' + prefix + 'reward').textContent = compactCaption(node.rewardText); });
            message.textContent = progressMessage(state);
            track.setAttribute('aria-valuenow', String(state.progressPercent)); track.setAttribute('aria-valuetext', message.textContent);
            fill.style.width = state.progressPercent + '%'; root.hidden = false; root.removeAttribute('aria-busy'); last = state; layoutLabels();
            if (action.celebrate) celebrate(action.celebrate, state);
        }
        function layoutLabels() {
            if (root.hidden || destroyed) return;
            const captions = [...nodeViews.values()].map(view => view.querySelector('.' + prefix + 'node-label'));
            captions.forEach(caption => caption.style.top = '22px');
            const rects = captions.map(caption => caption.getBoundingClientRect()), lanes = labelLanes(rects), height = Math.max(32, ...rects.map(rect => rect.height));
            captions.forEach((caption, index) => caption.style.top = 22 + lanes[index] * (height + 8) + 'px');
            const padding = 22 + (Math.max(0, ...lanes) + 1) * (height + 8) + 'px';
            if (milestones.style.paddingBottom !== padding) milestones.style.paddingBottom = padding;
        }
        function resize() { layoutLabels(); if (animationNode && !mascot.hidden) direction(animationNode); confetti.replaceChildren(); }
        function visibility() { stop(); last = null; if (!document.hidden) layoutLabels(); }
        function cartChanging() { stop(); }
        const observer = typeof IntersectionObserver === 'function' ? new IntersectionObserver(entries => { if (!entries[0].isIntersecting) { stop(); last = null; } else layoutLabels(); }) : null;
        if (observer) observer.observe(root);
        const sizeObserver = typeof ResizeObserver === 'function' ? new ResizeObserver(resize) : null;
        if (sizeObserver) sizeObserver.observe(panel);
        document.addEventListener('visibilitychange', visibility); window.addEventListener('resize', resize); window.addEventListener('pagehide', stop); reduced.addEventListener('change', stop); document.addEventListener('vc:cart-mutation-start', cartChanging);
        function destroy() {
            if (destroyed) return;
            destroyed = true; stop(); if (observer) observer.disconnect(); if (sizeObserver) sizeObserver.disconnect();
            document.removeEventListener('visibilitychange', visibility); window.removeEventListener('resize', resize); window.removeEventListener('pagehide', stop); reduced.removeEventListener('change', stop); document.removeEventListener('vc:cart-mutation-start', cartChanging);
            root.replaceChildren(); root.hidden = true; last = null;
        }
        return { update, pause: stop, clear: hide, destroy };
    };
}());
