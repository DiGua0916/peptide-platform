'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const actual = require('../assets/live-cart-experience.js');

function present(coupon) {
    assert.equal(typeof actual.couponPresentation, 'function', 'the actual live cart must export its couponPresentation API');
    return actual.couponPresentation(coupon);
}
function nativeCoupon(code = 'INTERNAL-COUPON-KEY', amount = '500', metadata = {}) {
    return { code, discount_type: 'fixed_cart', totals: { total_discount: amount, total_discount_tax: '0', currency_code: 'USD', currency_minor_unit: 2, ...metadata } };
}
function noCodeLeak(result, code) {
    assert.ok(!result.label.includes(code), 'visible label must not contain the internal code');
    assert.ok(!result.removeLabel.includes(code), 'accessible removal label must not contain the internal code');
    assert.equal(result.code, code, 'the native removal key is preserved exactly');
}

test('native positive coupon saving has a neutral name and exact native formatted amount', () => {
    const code = 'PRIVATE-KEY-5', result = present(nativeCoupon(code));
    assert.deepEqual(result, { label: 'Order discount · $5.00', removeLabel: 'Remove Order discount · $5.00', code });
    noCodeLeak(result, code);
});

test('internal code with HTML/script characters never enters visible or accessible copy', () => {
    const code = '<img src=x onerror="alert(1)"> & <script>bad()</script>', result = present(nativeCoupon(code));
    assert.equal(result.label, 'Order discount · $5.00');
    assert.equal(result.removeLabel, 'Remove Order discount · $5.00');
    noCodeLeak(result, code);
    assert.doesNotMatch(result.label + result.removeLabel, /<|>|onerror|script/i);
});

test('invalid or absent native money metadata falls back to the neutral label without guessing', () => {
    const variants = [
        { code: 'KEY-NO-TOTALS' },
        nativeCoupon('KEY-INVALID-AMOUNT', 'not-a-minor-amount'),
        nativeCoupon('KEY-DECIMAL-AMOUNT', '5.00'),
        nativeCoupon('KEY-INVALID-CURRENCY', '500', { currency_code: 'US' }),
        nativeCoupon('KEY-INVALID-PRECISION', '500', { currency_minor_unit: 9 }),
        nativeCoupon('KEY-STRING-PRECISION', '500', { currency_minor_unit: '2' }),
        nativeCoupon('KEY-NUMBER-AMOUNT', 500),
        nativeCoupon('KEY-NEGATIVE-DISCOUNT', '-500'),
        nativeCoupon('KEY-UNSAFE-AMOUNT', '9999999999999999')
    ];
    for (const coupon of variants) {
        const result = present(coupon);
        assert.equal(result.label, 'Order discount');
        assert.equal(result.removeLabel, 'Remove Order discount');
        noCodeLeak(result, coupon.code);
    }
});

test('native precision and currency control the amount without a hard-coded cents conversion', () => {
    assert.equal(present(nativeCoupon('JPY-KEY', '500', { currency_code: 'JPY', currency_minor_unit: 0 })).label, 'Order discount · ¥500');
    assert.equal(present(nativeCoupon('THREE-DIGIT-KEY', '12345', { currency_code: 'USD', currency_minor_unit: 3 })).label, 'Order discount · $12.345');
});

test('same native coupon remains neutral and stable across POST, subsequent GET and fresh page objects', () => {
    const coupon = nativeCoupon('REFRESH-STABLE-KEY');
    const postResponse = { coupons: [coupon], extensions: { peptide_core: { activity_recommendation: { requested: true, added: true, couponRequested: true, couponApplied: true } } } };
    const getResponse = JSON.parse(JSON.stringify({ coupons: [coupon] }));
    const freshResponse = JSON.parse(JSON.stringify(getResponse));
    assert.deepEqual(present(postResponse.coupons[0]), present(getResponse.coupons[0]));
    assert.deepEqual(present(getResponse.coupons[0]), present(freshResponse.coupons[0]));
    assert.doesNotMatch(present(freshResponse.coupons[0]).label, /automatic|black friday|refresh-stable-key/i);
});

function rendererHarness() {
    // Execute the actual source renderer block; no DOM library, network, browser, or real cart.
    const source = fs.readFileSync(path.join(__dirname, '../assets/live-cart-experience.js'), 'utf8');
    const start = source.indexOf("            const coupons = root.querySelector('[data-cart-coupons]')");
    const end = source.indexOf("            coupons.querySelectorAll('button')", start);
    assert.ok(start >= 0 && end > start, 'locate the actual native coupon render block');
    const renderBlock = new Function('root', 'cart', 'view', 'el', 'clean', 'client', 'couponPresentation', source.slice(start, end));
    const container = { children: [], replacements: 0, replaceChildren(...nodes) { this.children = nodes; this.replacements++; } };
    const root = { querySelector(selector) { assert.equal(selector, '[data-cart-coupons]'); return container; } };
    const view = { couponsSignature: '' }, calls = [];
    function el(tag, cls = '', text = '') {
        return { tagName: tag, className: cls, textContent: text, attrs: {}, events: {}, setAttribute(key, value) { this.attrs[key] = value; }, getAttribute(key) { return this.attrs[key]; }, addEventListener(type, callback) { this.events[type] = callback; } };
    }
    const client = { write(action, payload) { calls.push({ action, payload }); return Promise.resolve({}); } };
    return { container, calls, render(coupons) { renderBlock(root, { coupons }, view, el, String, client, actual.couponPresentation); } };
}

test('actual renderer exposes neither visible nor aria code, but removes using the original native key', async () => {
    const h = rendererHarness(), code = '<script>INTERNAL-CODE</script>';
    h.render([nativeCoupon(code)]);
    const button = h.container.children[0];
    assert.equal(button.textContent, 'Order discount · $5.00 ×');
    assert.equal(button.getAttribute('aria-label'), 'Remove Order discount · $5.00');
    assert.ok(!button.textContent.includes(code) && !button.getAttribute('aria-label').includes(code));
    button.events.click();
    await Promise.resolve();
    assert.deepEqual(h.calls, [{ action: 'remove-coupon', payload: { code } }]);
});

test('actual renderer refreshes same-code coupons when native discount amount changes', () => {
    const h = rendererHarness(), code = 'SAME-KEY-CHANGED-TOTAL';
    h.render([nativeCoupon(code, '500')]);
    assert.equal(h.container.children[0].textContent, 'Order discount · $5.00 ×');
    h.render([nativeCoupon(code, '750')]);
    assert.equal(h.container.children[0].textContent, 'Order discount · $7.50 ×');
    assert.equal(h.container.replacements, 2, 'signature must include the presentation, not only coupon.code');
    h.render([nativeCoupon(code, '750')]);
    assert.equal(h.container.replacements, 2, 'unchanged native coupons do not churn the DOM');
});
