/* No independent cart writer, price arithmetic or customer capture. Uses the existing native cart client. */
(function (global) {
    'use strict';
    const canonical = value => typeof value === 'string' ? value.trim().toLowerCase() : '';
    const minor = value => typeof value === 'string' && /^\d{1,24}$/u.test(value);
    function validCart(cart) {
        return !!cart && Array.isArray(cart.items) && Number.isSafeInteger(cart.items_count) && cart.items_count >= 0 &&
            Array.isArray(cart.coupons) && /^[A-Z]{3}$/u.test(cart.totals?.currency_code || '') &&
            Number.isInteger(cart.totals?.currency_minor_unit) && cart.totals.currency_minor_unit >= 0 && cart.totals.currency_minor_unit <= 8 && minor(cart.totals.total_price);
    }
    function couponResult(cart, code) {
        if (!validCart(cart)) return null;
        const matches = cart.coupons.filter(row => canonical(row.code) === canonical(code));
        if (matches.length !== 1) return null;
        const row = matches[0], totals = row.totals;
        if (!totals || totals.currency_code !== cart.totals.currency_code || totals.currency_minor_unit !== cart.totals.currency_minor_unit || !minor(totals.total_discount)) return null;
        return { couponMinor: totals.total_discount, totalMinor: cart.totals.total_price, currency: cart.totals.currency_code, precision: cart.totals.currency_minor_unit };
    }
    function formatMinor(value, result, locale = 'en') {
        if (!minor(value) || !result || !/^[A-Z]{3}$/u.test(result.currency || '') || !Number.isInteger(result.precision) || result.precision < 0 || result.precision > 8) return null;
        const amount = BigInt(value), scale = 10n ** BigInt(result.precision);
        // The native minor-unit amount is authoritative. Formatting does not derive a discount.
        try {
            const whole = new Intl.NumberFormat(locale, { maximumFractionDigits:0 }).format(amount / scale), fraction = String(amount % scale).padStart(result.precision,'0');
            return new Intl.NumberFormat(locale, { style:'currency',currency:result.currency,minimumFractionDigits:result.precision,maximumFractionDigits:result.precision }).formatToParts(1n).map(part=>part.type==='integer'?whole:part.type==='fraction'?fraction:part.value).join('');
        } catch (_) { return result.currency + ' ' + (result.precision ? value.padStart(result.precision + 1,'0').slice(0,-result.precision) + '.' + value.padStart(result.precision + 1,'0').slice(-result.precision) : value); }
    }
    function existingCouponsRetained(before, after, incoming) {
        const codes = new Set(after.coupons.map(row => canonical(row.code)));
        return before.coupons.every(row => canonical(row.code) === canonical(incoming) || codes.has(canonical(row.code)));
    }
    function createController({ code, client, copy, show, strings, preview = false }) {
        let busy = false;
        async function copyCode(empty = false) {
            let copied = false;
            try { copied = await copy(code) === true; } catch (_) { /* Report failure without claiming clipboard success. */ }
            show({ kind: copied ? 'copy' : 'error', message: copied ? (empty ? strings.empty : strings.copied) : strings.copyFailed });
            return copied;
        }
        async function apply() {
            if (busy || preview) return { state: 'blocked' };
            busy = true;
            try {
                const bridge = client();
                if (!bridge || typeof bridge.refresh !== 'function' || typeof bridge.snapshot !== 'function' || typeof bridge.applyCoupon !== 'function') { show({ kind:'error',message:strings.notReady }); return { state:'unavailable' }; }
                await bridge.refresh();
                const before = bridge.snapshot();
                if (before?.pending || before?.valid !== true || !validCart(before.cart)) { show({kind:'error',message:strings.notReady}); return {state:'unavailable'}; }
                if (before.cart.items_count === 0) { await copyCode(true); return {state:'empty'}; }
                const already = couponResult(before.cart, code);
                if (already) { show({ kind:'already',message:strings.already,result:already }); return {state:'already'}; }
                // Never repeat a POST after a timeout. The shared writer handles its single reconciliation read.
                const reply = await bridge.applyCoupon(code);
                const result = reply?.confirmed === true ? couponResult(reply.rawCart, code) : null;
                if (!result || !existingCouponsRetained(before.cart, reply.rawCart, code)) { show({kind:'error',message:strings.uncertain}); return {state:'uncertain'}; }
                show({ kind:'success',message:strings.success,result }); return {state:'applied'};
            } catch (error) {
                const raw=typeof error?.message==='string'?error.message:'';
                // Spanish owns its failure copy too; do not leak an untranslated native transport error.
                const translated=strings.errors&&Object.prototype.hasOwnProperty.call(strings.errors,raw)?strings.errors[raw]:null;
                const owned=raw&&(raw===strings.unavailable||raw===strings.conflict)?raw:null;
                show({kind:'error',message:translated||owned||(strings.errors?strings.failed:(raw||strings.failed||strings.uncertain))});
                return {state:'error'};
            } finally { busy = false; }
        }
        return { apply, copy:copyCode, busy:()=>busy };
    }
    function popupTimer(close, environment = {}) {
        const set = environment.setTimeout || setTimeout, clear = environment.clearTimeout || clearTimeout;
        let timer = null;
        return { start() { if (timer !== null) clear(timer); timer = set(() => { timer = null; close(); },15000); }, cancel() { if (timer !== null) clear(timer); timer = null; } };
    }
    if (typeof module === 'object' && module.exports) module.exports = { canonical, validCart, couponResult, formatMinor, existingCouponsRetained, createController, popupTimer };
    if (!global?.document) return;
    const document = global.document;
    function mount(root) {
        if (root.dataset.pcFirstMounted) return;
        let config;
        try { config = JSON.parse(root.dataset.offerConfig); } catch (_) { return; }
        if (!config || typeof config.code !== 'string' || !config.code.trim() || config.code.length > 255 || typeof config.strings !== 'object' || !config.strings) return;
        root.dataset.pcFirstMounted = '1';
        const strings = config.strings, applyButton = root.querySelector('[data-offer-apply]'), copyButton = root.querySelector('[data-offer-copy]'), status = root.querySelector('[data-offer-status]');
        if (!applyButton || !copyButton || !status) return;
        const dialog = document.createElement('dialog'); dialog.className = 'pc-first-offer-result'; dialog.setAttribute('aria-labelledby', (root.id || root.querySelector('h2')?.id || 'pc-offer') + '-result');
        const closeButton = document.createElement('button'); closeButton.type = 'button'; closeButton.className = 'pc-first-offer-result__close'; closeButton.setAttribute('aria-label',strings.close);
        closeButton.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>';
        const face = document.createElement('div'); face.className = 'pc-first-offer-result__face'; face.setAttribute('aria-hidden','true');
        face.innerHTML = '<svg viewBox="0 0 64 64" fill="none"><circle cx="32" cy="32" r="28" fill="currentColor"/><path d="M23 24v2m18-2v2M23 36q9 11 18 0" stroke="#fff" stroke-width="3" stroke-linecap="round"/></svg>';
        const heading = document.createElement('h2'); heading.id = dialog.getAttribute('aria-labelledby');
        const amount = document.createElement('dl'), note = document.createElement('p'), timerNote = document.createElement('small'), link = document.createElement('a');
        link.textContent = strings.viewCart;
        try { const url = new URL(config.cartUrl, global.location.href); if (url.origin !== global.location.origin || !['http:','https:'].includes(url.protocol)) throw new Error(); link.href = url.href; } catch (_) { link.hidden = true; }
        note.textContent = strings.pending; timerNote.textContent = strings.timer;
        dialog.append(closeButton,face,heading,amount,note,link,timerNote); document.body.append(dialog);
        let opener = null;
        const close = () => { clock.cancel(); if (dialog.open) dialog.close(); opener?.focus?.({preventScroll:true}); };
        const clock = popupTimer(close);
        closeButton.addEventListener('click',close);
        dialog.addEventListener('cancel',event=>{event.preventDefault();close();});
        dialog.addEventListener('close',()=>clock.cancel());
        const show = message => {
            status.textContent = message.message;
            heading.textContent = message.message; dialog.dataset.kind = message.kind; amount.replaceChildren();
            if (message.result) for (const [label,value] of [[strings.discount,message.result.couponMinor],[strings.orderTotal,message.result.totalMinor]]) {
                const formatted = formatMinor(value,message.result,document.documentElement.lang || 'en'); if (!formatted) continue;
                const dt=document.createElement('dt'),dd=document.createElement('dd');dt.textContent=label;dd.textContent=formatted;amount.append(dt,dd);
            }
            if (typeof dialog.showModal === 'function' && !dialog.open) {
                try { opener=document.activeElement;dialog.showModal();closeButton.focus({preventScroll:true}); }
                catch (_) { /* An unavailable modal leaves the honest inline status available. */ }
            }
            clock.start();
        };
        const copy = async code => {
            try { if (global.isSecureContext && global.navigator?.clipboard?.writeText) { await global.navigator.clipboard.writeText(code); return true; } } catch (_) { /* Explicit manual-select fallback. */ }
            const codeNode = root.querySelector('[data-offer-code]');
            if (!codeNode || !global.getSelection || !document.createRange) return false;
            const range = document.createRange();range.selectNodeContents(codeNode); const selection=global.getSelection();selection.removeAllRanges();selection.addRange(range);
            try { return typeof document.execCommand === 'function' && document.execCommand('copy') === true; } catch (_) { return false; }
        };
        const controller = createController({code:config.code,client:()=>global.VialCoveLiveCart,copy,show,strings,preview:config.preview===true});
        copyButton.addEventListener('click',()=>{if(!controller.busy())controller.copy();});
        applyButton.addEventListener('click',async()=>{
            if (controller.busy() || config.preview === true) return;
            applyButton.disabled=true;applyButton.textContent=strings.working;root.setAttribute('aria-busy','true');
            try { await controller.apply(); } finally { applyButton.disabled=false;applyButton.textContent=strings.apply;root.removeAttribute('aria-busy'); }
        });
        global.addEventListener('pagehide',()=>{clock.cancel();if(dialog.open)dialog.close();},{once:true});
    }
    const ready=()=>document.querySelectorAll('[data-pc-first-order-offer]').forEach(mount);
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',ready,{once:true});else ready();
})(typeof window==='undefined'?null:window);
