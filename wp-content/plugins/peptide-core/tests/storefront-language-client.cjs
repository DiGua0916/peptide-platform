'use strict';
// Production script in an isolated synthetic DOM. No browser, network or cart is used.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const file = path.resolve(__dirname, '../assets/storefront-language.js');
const source = fs.readFileSync(file, 'utf8');
const { validLanguage, normalizeDictionary, translateText, languageUrl, cookieLanguage } = require(file);
const dictionary = normalizeDictionary({ 'Search reports': 'Buscar informes', 'Offers': 'Ofertas', 'Hello {name}': 'Hola {name}' });

test('only exact supported codes are valid', () => {
    for (const value of ['en', 'es']) assert.equal(validLanguage(value), true);
    for (const value of ['ES', 'es_PR', 'es_ES', '', null, 1, true, ['es']]) assert.equal(validLanguage(value), false);
});
test('dictionary rejects invalid shapes and empty entries without prototype pollution', () => {
    assert.equal(Object.getPrototypeOf(dictionary), null);
    for (const value of [null, [], 'x', 3]) assert.equal(Object.keys(normalizeDictionary(value)).length, 0);
    const special = normalizeDictionary(JSON.parse('{"__proto__":"Seguro","constructor":"Constructor seguro","":"x","A":4,"B":""}'));
    assert.equal(special.__proto__, 'Seguro');
    assert.equal({}.polluted, undefined);
    assert.equal(special.constructor, 'Constructor seguro');
    assert.deepEqual(Object.keys(special).sort(), ['__proto__', 'constructor'].sort());
});
test('exact and normalized whitespace matches preserve surrounding whitespace', () => {
    assert.equal(translateText('Search reports', dictionary), 'Buscar informes');
    assert.equal(translateText(' \nSearch\t reports  ', dictionary), ' \nBuscar informes  ');
    assert.equal(translateText('Search reports for Alex', dictionary), 'Search reports for Alex');
    assert.equal(translateText('Custom new English', dictionary), 'Custom new English');
    assert.equal(translateText('Hello {name}', dictionary), 'Hola {name}');
    assert.equal(translateText(null, dictionary), null);
});
test('dynamic counts use correct singular and plural while preserving source digits', () => {
    for (const [input, output] of [['1 product','1 producto'],['2 products','2 productos'],['1 item','1 artículo'],['02 items','02 artículos'],['1 review','1 reseña'],['5 reviews','5 reseñas'],['1 report','1 informe'],['8 reports','8 informes'],['1 vial','1 vial'],['10 vials','10 viales']]) {
        assert.equal(translateText(input, dictionary), output);
    }
});
test('percent and unit display copies preserve authoritative amounts exactly', () => {
    for (const [input, output] of [['16% off','16% DTO.'],['16.1% off','16.1% DTO.'],[' 16,1% OFF ',' 16,1% DTO. '],['$5.50 / vial','$5.50 / vial'],['$55.00 / box','$55.00 / caja']]) assert.equal(translateText(input, dictionary), output);
    assert.equal(translateText('$55.00', dictionary), '$55.00');
    assert.equal(translateText('CAS 2381089-83-2', dictionary), 'CAS 2381089-83-2');
});
test('eight dynamic storefront templates preserve exact product names, amounts and counts', () => {
    const rows = [
        ['Spend $1,000.00, get', 'Compra por $1,000.00'],
        ['82 products. Find the specification that fits.', '82 productos. Encuentre la especificación adecuada.'],
        ['Explore GLOW Blend (GLOW70) specifications', 'Explore las especificaciones de GLOW Blend (GLOW70)'],
        ['View CJC-1295 no DAC', 'Ver CJC-1295 no DAC'],
        ['Go to offer 3', 'Ir a la oferta 3'],
        ['Price range: $40.00 through $220.00', 'Rango de precios: $40.00 a $220.00'],
        ['Spend $41.10 more to unlock $25.00 off your order.', 'Compra $41.10 más para obtener $25.00 de descuento en tu pedido.'],
        ['Explore GLOW Blend (GLOW70)', 'Explorar GLOW Blend (GLOW70)'],
    ];
    const numbers = value => (value.match(/\d+(?:[.,]\d+)*/g) || []).sort();
    for (const [input, expected] of rows) { const target = translateText(input, dictionary); assert.equal(target, expected); assert.deepEqual(numbers(target), numbers(input)); }
});
test('language URL preserves unrelated query, hash and current path and has one preference', () => {
    const url = new URL(languageUrl('https://shop.example/cart/?coupon=KEEP&pc_lang=en&pc_lang=en#totals', 'es'));
    assert.equal(url.origin, 'https://shop.example');
    assert.equal(url.pathname, '/cart/');
    assert.equal(url.searchParams.get('coupon'), 'KEEP');
    assert.deepEqual(url.searchParams.getAll('pc_lang'), ['es']);
    assert.equal(url.hash, '#totals');
    assert.equal(url.searchParams.has('pc_lang_auto'), false);
    assert.equal(new URL(languageUrl(url.href, 'es', true)).searchParams.get('pc_lang_auto'), '1');
    assert.equal(new URL(languageUrl(languageUrl(url.href, 'es', true), 'en')).searchParams.has('pc_lang_auto'), false);
});
test('navigation refuses privileged, machine, unsupported and payment-return destinations', () => {
    for (const value of [
        'javascript:alert(1)', 'file:///tmp/x', '/cart/', 'invalid',
        'https://shop.example/wp-admin/admin.php', 'https://shop.example/wp-login.php',
        'https://shop.example/wp-json/wc/store/v1/cart', 'https://shop.example/?rest_route=/wc/store/v1/cart',
        'https://shop.example/?wc-api=gateway', 'https://shop.example/?pc_panapay_return=1',
        'https://shop.example/checkout/order-pay/7/?key=synthetic',
        'https://shop.example/checkout/order-received/7/?key=synthetic',
    ]) assert.equal(languageUrl(value, 'es'), null, value);
    assert.equal(languageUrl('https://shop.example/cart/', 'ES'), null);
});
test('cookie parser accepts only exact cookie name and supported preference', () => {
    assert.equal(cookieLanguage('other=es; pc_storefront_language=es; next=en', 'pc_storefront_language'), 'es');
    assert.equal(cookieLanguage('xpc_storefront_language=es', 'pc_storefront_language'), null);
    assert.equal(cookieLanguage('pc_storefront_language=es_ES', 'pc_storefront_language'), null);
    assert.equal(cookieLanguage('pc_storefront_language=%65%73', 'pc_storefront_language'), 'es');
    assert.equal(cookieLanguage('pc_storefront_language=%ZZ', 'pc_storefront_language'), null);
});

function harness({ language = 'es', enabled = true, autoEnabled = false, cookie = '', href = 'https://shop.example/products/?q=original#top', resolver = null } = {}) {
    const records = [], observers = [], frames = [], events = new Map(), assigns = [], replaces = [], requests = [];
    let activeObserver = null, writes = 0;
    class Text {
        constructor(value) { this.nodeType = 3; this._value = value; this.parentElement = null; }
        get nodeValue() { return this._value; }
        set nodeValue(value) { this._value = value; writes++; if (activeObserver) records.push({ type: 'characterData', target: this }); }
    }
    const tokens = selector => selector.split(',').map(value => value.trim());
    class Element {
        constructor(tag, attrs = {}, children = []) {
            this.nodeType = 1; this.tagName = tag.toUpperCase(); this.attributes = { ...attrs }; this.children = []; this.parentElement = null; this.listeners = {}; this.value = attrs.value || '';
            this.classList = { add: name => { this.attributes.class = [this.attributes.class, name].filter(Boolean).join(' '); } };
            children.forEach(child => this.append(child));
        }
        append(child) { if (typeof child === 'string') child = new Text(child); child.parentElement = this; this.children.push(child); return child; }
        prepend(child) { if (child.parentElement) child.parentElement.children = child.parentElement.children.filter(node => node !== child); child.parentElement = this; this.children.unshift(child); }
        matches(selector) {
            return tokens(selector).some(token => {
                if (token[0] === '.') return String(this.attributes.class || '').split(/\s+/).includes(token.slice(1));
                if (token[0] === '#') return this.attributes.id === token.slice(1);
                const attr = /^\[([^=*\]]+)(\*=|=)?(?:"([^"]*)"|'([^']*)'|([^\]]*))?\]$/.exec(token);
                if (attr) { const key = attr[1], expected = attr[3] ?? attr[4] ?? attr[5] ?? ''; return this.hasAttribute(key) && (!attr[2] || (attr[2] === '*=' ? String(this.attributes[key]).includes(expected) : String(this.attributes[key]) === expected)); }
                return this.tagName.toLowerCase() === token.toLowerCase();
            });
        }
        closest(selector) { for (let node = this; node; node = node.parentElement) if (node.matches(selector)) return node; return null; }
        querySelector(selector) { for (const node of this.children) { if (node.nodeType !== 1) continue; if (node.matches(selector)) return node; const found = node.querySelector(selector); if (found) return found; } return null; }
        hasAttribute(name) { return Object.hasOwn(this.attributes, name); }
        getAttribute(name) { return this.hasAttribute(name) ? this.attributes[name] : null; }
        setAttribute(name, value) { this.attributes[name] = value; writes++; if (activeObserver) records.push({ type: 'attributes', target: this, attributeName: name }); }
        addEventListener(name, callback) { this.listeners[name] = callback; }
    }
    const body = new Element('body');
    const document = {
        body, documentElement: { lang: 'en' }, readyState: 'loading', cookie,
        querySelector: selector => body.querySelector(selector),
        addEventListener: (name, callback) => events.set('document:' + name, callback),
        createTreeWalker(root, mask, filter) {
            const found = [];
            const walk = parent => { for (const node of parent.children || []) { const result = filter.acceptNode(node); if (result !== 2) { if (result === 1) found.push(node); if (node.nodeType === 1) walk(node); } } };
            walk(root); let index = -1;
            return { currentNode: root, nextNode() { if (++index >= found.length) return false; this.currentNode = found[index]; return true; } };
        },
    };
    class Observer {
        constructor(callback) { this.callback = callback; observers.push(this); }
        observe() { activeObserver = this; }
        disconnect() { if (activeObserver === this) activeObserver = null; }
    }
    const window = {
        PCStorefrontI18n: { enabled, language, autoEnabled, dictionary, resolverUrl: 'https://shop.example/wp-json/peptide/v1/storefront-language', preferenceCookie: 'pc_storefront_language' },
        location: { href, assign: value => assigns.push(value), replace: value => replaces.push(value) },
        requestAnimationFrame: callback => frames.push(callback),
        addEventListener: (name, callback) => events.set('window:' + name, callback),
        confirm: () => false,
    };
    vm.runInNewContext(source, { window, document, NodeFilter: { SHOW_ELEMENT: 1, SHOW_TEXT: 4, FILTER_ACCEPT: 1, FILTER_REJECT: 2 }, MutationObserver: Observer, URL, AbortController, setTimeout, clearTimeout, fetch: async (url, options) => { requests.push({ url, options }); return { ok: true, json: async () => resolver }; } }, { filename: file });
    const e = (tag, attrs, children) => new Element(tag, attrs, children);
    const tick = () => { if (records.length && activeObserver) activeObserver.callback(records.splice(0)); while (frames.length) frames.shift()(); };
    return { body, document, window, e, text: value => new Text(value), start: () => events.get('document:DOMContentLoaded')?.(), tick, records, observers, events, assigns, replaces, requests, writes: () => writes };
}
test('Spanish active document translates exact UI while preserving all protected surfaces and typed values', () => {
    const h = harness(); const normal = h.e('button', { 'aria-label': 'Search reports', 'data-id': 'Search reports' }, ['Search reports']); h.body.append(normal);
    const protectedElements = [
        ['script',{}],['style',{}],['textarea',{}],['input',{value:'Search reports'}],['select',{}],['option',{}],['code',{}],['pre',{}],
        ['div',{translate:'no'}],['div',{id:'wpadminbar'}],['div',{class:'product_title'}],['div',{class:'sku'}],['span',{class:'woocommerce-Price-amount'}],
        ['div',{class:'comment-text'}],['div',{class:'review-content'}],['div',{class:'woocommerce-MyAccount-content'}],['div',{class:'wc-block-checkout'}],['div',{class:'wc-block-cart'}],['div',{class:'wc-block-components-address-card'}],['div',{contenteditable:'true'}],
    ].map(([tag,attrs]) => h.e(tag, { ...attrs, title: 'Search reports' }, ['Search reports']));
    protectedElements.forEach(element => h.body.append(element)); h.start();
    assert.equal(normal.children[0].nodeValue, 'Buscar informes'); assert.equal(normal.getAttribute('aria-label'), 'Buscar informes'); assert.equal(normal.getAttribute('data-id'), 'Search reports');
    for (const element of protectedElements) { assert.equal(element.children[0].nodeValue, 'Search reports', JSON.stringify(element.attributes)); assert.equal(element.getAttribute('title'), element.tagName === 'INPUT' ? 'Buscar informes' : 'Search reports', element.tagName); }
    assert.equal(protectedElements.find(node => node.tagName === 'INPUT').value, 'Search reports');
    assert.equal(h.document.documentElement.lang, 'es');
});
test('English active document never applies Spanish dictionary or observer', () => {
    const h = harness({ language: 'en' }); const element = h.e('button', {}, ['Search reports']); h.body.append(element); h.start();
    assert.equal(element.children[0].nodeValue, 'Search reports'); assert.equal(h.observers.length, 0); assert.equal(h.document.documentElement.lang, 'en');
});
test('disabled integration creates no observer or navigation', () => {
    const h = harness({ enabled: false, autoEnabled: true }); h.body.append(h.e('p',{},['Search reports'])); h.start(); assert.equal(h.observers.length, 0); assert.equal(h.requests.length, 0); assert.equal(h.replaces.length, 0);
});
test('app mutations are retranslated and translation-generated records settle without a loop', () => {
    const h = harness(); const element = h.e('button', { title:'Offers' }, ['Offers']); h.body.append(element); h.start();
    const node = element.children[0]; assert.equal(node.nodeValue, 'Ofertas');
    node.nodeValue = 'Search reports'; element.setAttribute('title','Search reports'); h.tick(); h.tick();
    assert.equal(node.nodeValue, 'Buscar informes'); assert.equal(element.getAttribute('title'),'Buscar informes');
    const writes = h.writes(); h.tick(); h.tick(); assert.equal(h.writes(), writes); assert.equal(h.records.length, 0);
    const added = h.e('span',{},['Offers']); element.append(added); h.observers[0].callback([{ type:'childList', addedNodes:[added] }]); h.tick(); h.tick(); assert.equal(added.children[0].nodeValue,'Ofertas');
});
test('pagehide disconnects observer; BFCache pageshow restores translation once', () => {
    const h = harness(); const element=h.e('p',{},['Offers']); h.body.append(element); h.start();
    h.events.get('window:pagehide')(); element.children[0].nodeValue='Search reports'; h.tick(); assert.equal(element.children[0].nodeValue,'Search reports');
    h.events.get('window:pageshow')({ persisted:true }); assert.equal(element.children[0].nodeValue,'Buscar informes');
});
test('language selector uses only user change and preserves unsaved form on declined confirmation', () => {
    const h=harness({language:'en'}); const select=h.e('select',{'data-pc-language-select':'',value:'en'},[]); const switcher=h.e('nav',{'data-pc-language-switcher':'',translate:'no'},[select]); const header=h.e('div',{class:'header-actions'},[]); h.body.append(header); h.body.append(switcher); h.start();
    assert.equal(header.children[0],switcher); assert.equal(h.assigns.length,0);
    h.events.get('document:input')({target:h.e('input',{value:'typing'},[])}); select.value='es'; select.listeners.change(); assert.equal(h.assigns.length,0); assert.equal(select.value,'en');
    h.window.confirm=()=>true; select.value='es'; select.listeners.change(); assert.equal(h.assigns.length,1); assert.equal(new URL(h.assigns[0]).searchParams.get('pc_lang'),'es');
});
const settle=async()=>{for(let i=0;i<12;i++)await Promise.resolve();};
test('anonymous automatic selection calls same-origin resolver once and only honors geoip enabled response', async()=>{
    const h=harness({language:'en',autoEnabled:true,resolver:{config:{enabled:true},selection:{source:'geoip',language:'es'}}}); h.start(); await settle();
    assert.equal(h.requests.length,1); assert.equal(h.requests[0].options.cache,'no-store'); assert.equal(h.requests[0].options.credentials,'same-origin'); assert.equal(h.replaces.length,1);
    const next=new URL(h.replaces[0]); assert.equal(next.origin,'https://shop.example'); assert.equal(next.searchParams.get('pc_lang'),'es'); assert.equal(next.searchParams.get('pc_lang_auto'),'1');
});
test('typing into contenteditable before the geo lookup completes cancels automatic navigation', async()=>{
    const h=harness({language:'en',autoEnabled:true,resolver:{config:{enabled:true},selection:{source:'geoip',language:'es'}}}); h.start();
    const editable=h.e('div',{contenteditable:'true'},[]); h.body.append(editable);
    h.events.get('document:input')({target:editable}); await settle(); assert.equal(h.replaces.length,0);
});
for(const options of [{cookie:'pc_storefront_language=en'},{href:'https://shop.example/?pc_lang=en'}]) test('manual preference prevents automatic selection: '+JSON.stringify(options),async()=>{
    const h=harness({...options,language:'en',autoEnabled:true,resolver:{config:{enabled:true},selection:{source:'geoip',language:'es'}}}); h.start(); await settle(); assert.equal(h.requests.length,0); assert.equal(h.replaces.length,0);
});
for(const resolver of [{config:{enabled:false},selection:{source:'geoip',language:'es'}},{config:{enabled:true},selection:{source:'default',language:'es'}},{config:{enabled:true},selection:{source:'geoip',language:'fr'}},null]) test('failed, default or unsupported resolver never navigates: '+JSON.stringify(resolver),async()=>{
    const h=harness({language:'en',autoEnabled:true,resolver}); h.start(); await settle(); assert.equal(h.replaces.length,0);
});
