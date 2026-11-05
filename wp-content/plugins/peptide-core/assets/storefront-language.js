/* Explicit EN/ES storefront presentation. No translation service, customer capture or cart mutation. */
(function () {
    'use strict';
    const blocked = 'script,style,textarea,input,select,option,pre,code,kbd,samp,address,svg,math,iframe,[translate="no"],[contenteditable],#wpadminbar,.product_title,.vc-product-meta__name,.sku,.woocommerce-Price-amount,.comment-text,.review-content,.woocommerce-review__author,.woocommerce-Address,.woocommerce-customer-details,.woocommerce-MyAccount-content,.wp-block-woocommerce-checkout,.wp-block-woocommerce-cart,.wc-block-checkout,.wc-block-cart,[class*="wc-block-components-"]';
    function validLanguage(value) { return value === 'en' || value === 'es'; }
    function normalizeDictionary(value) {
        const result = Object.create(null);
        if (!value || typeof value !== 'object' || Array.isArray(value)) return result;
        Object.entries(value).forEach(([key, text]) => {
            if (typeof text !== 'string' || !key.trim() || !text.trim()) return;
            result[key] = text;
            const normalized = key.trim().replace(/\s+/gu, ' ');
            if (!Object.prototype.hasOwnProperty.call(result, normalized)) result[normalized] = text.trim();
        });
        return result;
    }
    function translateText(source, dictionary) {
        if (typeof source !== 'string') return source;
        if (Object.prototype.hasOwnProperty.call(dictionary, source)) return dictionary[source];
        const parts = /^(\s*)(.*?)(\s*)$/su.exec(source);
        if (!parts) return source;
        const key = parts[2].replace(/\s+/gu, ' ');
        if (Object.prototype.hasOwnProperty.call(dictionary, key)) return parts[1] + dictionary[key] + parts[3];
        // Display-only templates: copy authoritative amounts/counts verbatim, never calculate discounts.
        const count = /^(\d+) (products?|items?|reviews?|reports?|vials?)$/iu.exec(key);
        if (count) {
            const singular = {product:'producto',item:'artículo',review:'reseña',report:'informe',vial:'vial'};
            const base = count[2].toLowerCase().replace(/s$/, '');
            return parts[1] + count[1] + ' ' + singular[base] + (count[1] === '1' ? '' : base === 'vial' ? 'es' : 's') + parts[3];
        }
        const percent = /^(\d+(?:[.,]\d+)?)%\s+off$/iu.exec(key);
        if (percent) return parts[1] + percent[1] + '% DTO.' + parts[3];
        const per = /^(.+?)(\s*\/\s*)(vial|box)$/iu.exec(key);
        if (per) return parts[1] + per[1] + per[2] + (per[3].toLowerCase() === 'box' ? 'caja' : 'vial') + parts[3];
        const patterns = [
            [/^(\d+) (?:box|boxes)$/u, match => match[1] + (match[1] === '1' ? ' caja' : ' cajas')],
            [/^(\$[0-9,.]+) off$/u, match => match[1] + ' DTO.'],
            [/^(\$[0-9,.]+)\. (\$[0-9,.]+) off(?:\. (Reached)\.)?$/u, match => match[1] + '. ' + match[2] + ' de descuento' + (match[3] ? '. Alcanzado.' : '')],
            [/^Spend (?!.* more to unlock )(.+?)(?:, get)?$/u, match => 'Compra por ' + match[1]],
            [/^(\d+) products\. Find the specification that fits\.$/u, match => match[1] + ' productos. Encuentre la especificación adecuada.'],
            [/^Explore (.+) specifications$/u, match => 'Explore las especificaciones de ' + match[1]],
            [/^Explore (.+)$/u, match => 'Explorar ' + match[1]],
            [/^View (.+)$/u, match => 'Ver ' + match[1]],
            [/^Remove (.+)$/u, match => 'Eliminar ' + match[1]],
            [/^A little more about (.+)\.$/u, match => 'Un poco más sobre ' + match[1] + '.'],
            [/^(.+) quantity$/u, match => 'Cantidad de ' + match[1]],
            [/^Go to offer (\d+)$/u, match => 'Ir a la oferta ' + match[1]],
            [/^Price range: (.+) through (.+)$/u, match => 'Rango de precios: ' + match[1] + ' a ' + match[2]],
            [/^Selected: (.+)$/u, match => 'Seleccionado: ' + match[1]],
            [/^Choose a (.+) option$/u, match => 'Elige una opción de ' + (dictionary[match[1]] || match[1])],
            [/^Page (\d+) of (\d+)$/u, match => 'Página ' + match[1] + ' de ' + match[2]],
            [/^Page (\d+)$/u, match => 'Página ' + match[1]],
            [/^Showing (\d+)([–-])(\d+) of (\d+) results$/u, match => 'Mostrando ' + match[1] + match[2] + match[3] + ' de ' + match[4] + ' resultados'],
            [/^(\d+) published batches$/u, match => match[1] + ' lotes publicados'],
            [/^First page of (.+)$/u, match => 'Primera página de ' + match[1]],
            [/^Loading page (\d+) of (\d+)…$/u, match => 'Cargando página ' + match[1] + ' de ' + match[2] + '…'],
            [/^(.+), page (\d+) of (\d+)$/u, match => match[1] + ', página ' + match[2] + ' de ' + match[3]],
            [/^(Spend|Add) (.+) more to unlock (.+) off your order\.$/u, match => (match[1] === 'Spend' ? 'Compra ' : 'Agrega ') + match[2] + ' más para obtener ' + match[3] + ' de descuento en tu pedido.'],
            [/^Highest tier unlocked\. (.+) off your order\.$/u, match => 'Alcanzaste el nivel más alto. ' + match[1] + ' de descuento en tu pedido.'],
            [/^Add 1 box to unlock (.+) in additional savings\.$/u, match => 'Agrega 1 caja para obtener ' + match[1] + ' de ahorro adicional.'],
            [/^Add 1 box of (.+)$/u, match => 'Agregar 1 caja de ' + match[1]],
            [/^Choose specification and add (.+)$/u, match => 'Elegir presentación y agregar ' + match[1]],
            [/^Decrease quantity of (.+)$/u, match => 'Disminuir la cantidad de ' + match[1]],
            [/^Increase quantity of (.+)$/u, match => 'Aumentar la cantidad de ' + match[1]],
            [/^(.+) · (\d+) box added to your bag\.$/u, match => match[1] + ' · Se agregó ' + match[2] + ' caja a tu carrito.'],
            [/^(.+) · (\d+) boxes added to your bag\.$/u, match => match[1] + ' · Se agregaron ' + match[2] + ' cajas a tu carrito.'],
            [/^Equivalent price per vial; sold as a (\d+)-vial pack\.$/u, match => 'Precio equivalente por vial; se vende en una presentación de ' + match[1] + ' viales.'],
            [/^Manufactured: (.+)$/u, match => 'Fabricado: ' + match[1]],
            [/^Retest \/ expiry: (.+)$/u, match => 'Nuevo análisis / vencimiento: ' + match[1]],
            [/^Version (\d+)$/u, match => 'Versión ' + match[1]]
        ];
        for (const [pattern, render] of patterns) { const match = pattern.exec(key); if (match) return parts[1] + render(match) + parts[3]; }
        return source;
    }
    function languageUrl(href, language, automatic = false) {
        if (!validLanguage(language)) return null;
        let url;
        try { url = new URL(href); } catch (_) { return null; }
        if (!['http:', 'https:'].includes(url.protocol) || /\/(?:wp-admin|wp-json|wp-login\.php|order-pay|order-received)(?:\/|$)/iu.test(url.pathname)) return null;
        if ([...url.searchParams.keys()].some(key => /^(?:rest_route|wc-api|wc-ajax|pc_panapay_return|pc_payment_return|payment-return|key|order_key|_wpnonce)$/iu.test(key))) return null;
        url.searchParams.set('pc_lang', language);
        if (automatic) url.searchParams.set('pc_lang_auto', '1'); else url.searchParams.delete('pc_lang_auto');
        return url.href;
    }
    function cookieLanguage(cookie, name) {
        const entry = String(cookie || '').split(';').map(row => row.trim()).find(row => row.startsWith(name + '='));
        if (!entry) return null;
        try { const value = decodeURIComponent(entry.slice(name.length + 1)); return validLanguage(value) ? value : null; } catch (_) { return null; }
    }
    if (typeof module === 'object' && module.exports) module.exports = {validLanguage, normalizeDictionary, translateText, languageUrl, cookieLanguage};
    if (typeof window !== 'object' || typeof document !== 'object') return;
    const config = window.PCStorefrontI18n;
    if (!config || config.enabled !== true || !validLanguage(config.language)) return;
    const dictionary = normalizeDictionary(config.dictionary);
    let dirty = false;
    const pending = new Set();
    let scheduled = false;
    const translateNode = node => {
        if (!node || node.nodeType !== 3 || !node.parentElement || node.parentElement.closest(blocked)) return;
        const source = node.nodeValue;
        const target = translateText(source, dictionary);
        if (target !== source) node.nodeValue = target;
    };
    const translateElement = element => {
        if (!element || element.nodeType !== 1 || element.closest(blocked) && (!element.matches('input') || element.parentElement?.closest(blocked))) return;
        ['aria-label', 'placeholder', 'title', 'alt'].forEach(name => {
            // Labels may change; typed values and submitted form options never do.
            if (!element.hasAttribute(name)) return;
            const source = element.getAttribute(name), target = translateText(source, dictionary);
            if (target !== source) element.setAttribute(name, target);
        });
    };
    const translateTree = root => {
        if (!root || root.nodeType === 3) { translateNode(root); return; }
        if (root.nodeType !== 1) return;
        if (root.matches('input')) { translateElement(root); return; }
        if (root.closest(blocked)) return;
        translateElement(root);
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT, {
            acceptNode(node) {
                if (node.nodeType === 1 && node.matches(blocked) && !node.matches('input')) return NodeFilter.FILTER_REJECT;
                return NodeFilter.FILTER_ACCEPT;
            }
        });
        while (walker.nextNode()) { const node = walker.currentNode; if (node.nodeType === 3) translateNode(node); else translateElement(node); }
    };
    const flush = () => {
        scheduled = false;
        const work = [...pending]; pending.clear();
        work.forEach(translateTree);
    };
    const queue = root => {
        pending.add(root);
        if (scheduled) return;
        scheduled = true;
        (window.requestAnimationFrame || (callback => setTimeout(callback, 0)))(flush);
    };
    const ready = () => {
        const switcher = document.querySelector('[data-pc-language-switcher]');
        const menu = switcher?.querySelector('[data-pc-language-menu]');
        const trigger = switcher?.querySelector('[data-pc-language-trigger]');
        const choices = Array.from(switcher?.querySelectorAll('[data-pc-language-option]') || []);
        const destination = document.querySelector('.header-actions,.vc-demo-header-actions');
        if (switcher && destination) { destination.prepend(switcher); switcher.classList.add('pc-language-switcher--header'); }
        const closeLanguage = (restoreFocus = false) => {
            if (!menu?.open) return;
            menu.open = false;
            if (restoreFocus) trigger?.focus();
        };
        choices.forEach(link => {
            const language = link.getAttribute('data-pc-language-option');
            const next = languageUrl(window.location.href, language);
            if (next) link.setAttribute('href', next);
            link.addEventListener('click', event => {
                const href = languageUrl(window.location.href, language);
                if (!href || event.defaultPrevented) return;
                link.setAttribute('href', href);
                // Native modified clicks keep new-tab/window navigation and real links.
                if (event.button > 0 || event.ctrlKey || event.metaKey || event.altKey || event.shiftKey) return;
                event.preventDefault();
                if (language === config.language) { closeLanguage(true); return; }
                if (dirty && !window.confirm(config.language === 'es' ? 'Cambiar el idioma recargará la página. Es posible que los campos sin guardar no se conserven. ¿Continuar?' : 'Changing language reloads this page. Unsaved fields may not be kept. Continue?')) return;
                closeLanguage();
                window.location.assign(href);
            });
        });
        menu?.addEventListener('keydown', event => {
            if (event.key === 'Escape' && menu.open) {
                event.preventDefault(); event.stopPropagation(); closeLanguage(true); return;
            }
            if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key) || !choices.length) return;
            event.preventDefault();
            const index = choices.indexOf(event.target);
            menu.open = true;
            const next = event.key === 'Home' ? 0 : event.key === 'End' ? choices.length - 1 : event.key === 'ArrowDown' ? (index + 1) % choices.length : index < 0 ? choices.length - 1 : (index + choices.length - 1) % choices.length;
            choices[next].focus();
        });
        document.addEventListener('pointerdown', event => { if (menu?.open && !switcher.contains(event.target)) closeLanguage(); });
        document.addEventListener('focusin', event => { if (menu?.open && !switcher.contains(event.target)) closeLanguage(); });
        document.addEventListener('input', event => { if ((event.target?.matches('input,textarea,select') || event.target?.closest('[contenteditable]')) && !event.target.closest('[data-pc-language-switcher]')) dirty = true; }, {passive:true});
        if (config.language === 'es') {
            // Woo's React checkout uses its official locale JSON; do not rewrite its managed DOM.
            document.documentElement.lang = 'es';
            translateTree(document.body);
            const observer = new MutationObserver(records => records.forEach(record => {
                if (record.type === 'childList') record.addedNodes.forEach(queue);
                else queue(record.type === 'characterData' ? record.target : record.target);
            }));
            observer.observe(document.body, {subtree:true, childList:true, characterData:true, attributes:true, attributeFilter:['aria-label','placeholder','title','alt']});
            window.addEventListener('pagehide', () => { observer.disconnect(); pending.clear(); }, {once:true});
            window.addEventListener('pageshow', event => { if (event.persisted) { translateTree(document.body); observer.observe(document.body, {subtree:true, childList:true, characterData:true, attributes:true, attributeFilter:['aria-label','placeholder','title','alt']}); } });
        }
        let current;
        try { current = new URL(window.location.href); } catch (_) { return; }
        if (config.autoEnabled !== true || validLanguage(current.searchParams.get('pc_lang')) || cookieLanguage(document.cookie, config.preferenceCookie)) return;
        let resolver;
        try { resolver = new URL(config.resolverUrl, current); if (resolver.origin !== current.origin) return; } catch (_) { return; }
        const controller = new AbortController(); const timeout = setTimeout(() => controller.abort(), 6000);
        fetch(resolver.href, {credentials:'same-origin', cache:'no-store', headers:{Accept:'application/json'}, signal:controller.signal})
            .then(response => response.ok ? response.json() : null).then(value => {
                if (dirty || value?.config?.enabled !== true || value?.selection?.source !== 'geoip' || !validLanguage(value?.selection?.language) || value.selection.language === config.language) return;
                const next = languageUrl(window.location.href, value.selection.language, true);
                if (next) window.location.replace(next);
            }).catch(() => {}).finally(() => clearTimeout(timeout));
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready, {once:true}); else ready();
}());
