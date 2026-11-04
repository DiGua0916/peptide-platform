/* Informational product-price comparison. Woo still owns the subtotal and order. */
(function (browser, factory) {
    'use strict';
    var api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (browser && browser.document) {
        browser.VialCoveCheckoutSubtotalSavings = Object.freeze(api);
        api.start(browser);
    }
}(typeof window !== 'undefined' ? window : null, function () {
    'use strict';

    function minor(value) {
        if (typeof value === 'string' && !/^(?:0|[1-9]\d*)$/.test(value)) return null;
        if (typeof value !== 'string' && typeof value !== 'number') return null;
        var amount = Number(value);
        return Number.isSafeInteger(amount) && amount >= 0 ? amount : null;
    }

    /* The extension aggregates the actual cart-line WC_Product/variation and
       quantity in Woo's cart tax-display context. Do not use total_savings here:
       that field additionally includes coupons and promotion fees. */
    function compute(cart) {
        var pricing = cart && cart.extensions && cart.extensions.peptide_core && cart.extensions.peptide_core.sale_savings;
        var totals = cart && cart.totals;
        if (!pricing || !totals || !Array.isArray(cart.items) || !cart.items.length) return null;
        if (pricing.savings_available !== true || pricing.compare_is_demo === true) return null;
        var includesTax = pricing.tax_display === 'incl';
        var basis = includesTax
            ? 'native-cart-display-including-tax-excluding-shipping'
            : 'native-cart-display-excluding-tax-and-shipping';
        if ((!includesTax && pricing.tax_display !== 'excl') || pricing.savings_basis !== basis) return null;
        var decimals = minor(pricing.currency_minor_unit);
        if (decimals === null || decimals > 6 || minor(totals.currency_minor_unit) !== decimals) return null;
        var currency = pricing.currency_code;
        if (typeof currency !== 'string' || !/^[A-Z]{3}$/.test(currency) || totals.currency_code !== currency) return null;
        var regular = minor(pricing.display_regular_subtotal);
        var sale = minor(pricing.display_sale_subtotal);
        var nativeItems = minor(totals.total_items);
        var nativeTax = includesTax ? minor(totals.total_items_tax) : 0;
        if (regular === null || sale === null || nativeItems === null || nativeTax === null) return null;
        var nativeSubtotal = nativeItems + nativeTax;
        if (!Number.isSafeInteger(nativeSubtotal) || nativeSubtotal !== sale || regular <= sale) return null;
        return { regular: regular, sale: sale, savings: regular - sale, currency: currency, decimals: decimals };
    }

    /* Format integer minor units without losing a final cent to division's
       floating-point rounding at large values. Intl retains locale placement. */
    function money(value, currency, decimals, locale) {
        if (minor(value) === null || minor(decimals) === null || decimals > 6) return null;
        try {
            var divisor = Math.pow(10, decimals);
            var integer = Math.floor(value / divisor);
            var fraction = decimals ? new Intl.NumberFormat(locale || 'en-US', {
                useGrouping: false, minimumIntegerDigits: decimals, maximumFractionDigits: 0
            }).format(value % divisor) : '';
            return new Intl.NumberFormat(locale || 'en-US', {
                style: 'currency', currency: currency,
                minimumFractionDigits: decimals, maximumFractionDigits: decimals
            }).formatToParts(integer).map(function (part) {
                return part.type === 'fraction' ? fraction : part.value;
            }).join('');
        } catch (error) { return null; }
    }

    function start(win) {
        var page = win.document.querySelector('.vc-checkout-page');
        var wp = win.wp;
        var store = win.wc && win.wc.wcBlocksData && win.wc.wcBlocksData.cartStore;
        if (!page || !store || !wp || !wp.data || !wp.data.useSelect || !wp.element || !wp.element.createRoot) return;
        if (page.dataset.vcSubtotalSavingsBound === 'true') return;
        page.dataset.vcSubtotalSavingsBound = 'true';
        var h = wp.element.createElement;
        var mounted = new Map();
        var frame = 0;
        var suspended = false;
        var selector = '.wp-block-woocommerce-checkout-order-summary-subtotal-block .wc-block-components-totals-item';
        var readyClass = 'vc-subtotal-has-savings';

        function SubtotalSavings(props) {
            var data = wp.data.useSelect(function (select) {
                return compute(select(store).getCartData());
            }, []);
            var locale = win.document.documentElement.lang || 'en-US';
            var original = data && money(data.regular, data.currency, data.decimals, locale);
            var saved = data && money(data.savings, data.currency, data.decimals, locale);
            var valid = !!(original && saved);
            var spanish = /^es(?:-|$)/i.test(locale);
            wp.element.useLayoutEffect(function () {
                props.node.classList.toggle(readyClass, valid);
                return function () { props.node.classList.remove(readyClass); };
            }, [props.node, valid]);
            if (!valid) return null;
            return h('span', { className: 'vc-subtotal-savings__content' },
                h('del', {
                    className: 'vc-subtotal-savings__regular',
                    'aria-label': (spanish ? 'Subtotal habitual de productos ' : 'Regular product subtotal ') + original
                }, original),
                h('span', {
                    className: 'vc-subtotal-savings__saved',
                    'aria-label': (spanish ? 'Ahorro en el precio de productos ' : 'Product price savings ') + saved
                }, (spanish ? 'Ahorra ' : 'Save ') + saved)
            );
        }

        function remove(node, entry) {
            mounted.delete(node);
            entry.root.unmount();
            entry.host.remove();
            node.classList.remove(readyClass);
        }
        function visible(node) {
            return node.isConnected && !node.closest('[hidden], [aria-hidden="true"]') && node.getBoundingClientRect().width > 0;
        }
        function reconcile() {
            frame = 0;
            if (suspended) return;
            mounted.forEach(function (entry, node) {
                if (!visible(node) || entry.host.parentElement !== node || !entry.price.isConnected || entry.price.parentElement !== node) {
                    remove(node, entry);
                }
            });
            page.querySelectorAll(selector).forEach(function (node) {
                var entry = mounted.get(node);
                if (entry) {
                    // Woo may replace the native parent's class list without
                    // changing the subscribed amounts. Restore only our flag;
                    // leave a stable class list untouched to stop observer loops.
                    var hasContent = !!entry.host.querySelector('.vc-subtotal-savings__content');
                    if (node.classList.contains(readyClass) !== hasContent) node.classList.toggle(readyClass, hasContent);
                    return;
                }
                if (!visible(node)) return;
                var price = node.querySelector(':scope > .wc-block-components-totals-item__value');
                if (!price || !price.textContent.trim()) return;
                // A separate theme-owned root beside native children. Never
                // overwrite/clone the native price, label or tax description.
                var host = win.document.createElement('span');
                host.className = 'vc-subtotal-savings';
                host.dataset.vcSubtotalSavings = '';
                node.insertBefore(host, price);
                var root = wp.element.createRoot(host);
                mounted.set(node, { host: host, price: price, root: root });
                root.render(h(SubtotalSavings, { node: node }));
            });
        }
        function schedule() {
            if (!suspended && !frame) frame = win.requestAnimationFrame(reconcile);
        }
        var observer = new win.MutationObserver(schedule);
        function observe() {
            observer.observe(page, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['class', 'style', 'hidden', 'aria-hidden'] });
        }
        observe();
        win.addEventListener('resize', schedule);
        win.addEventListener('pagehide', function () {
            suspended = true;
            observer.disconnect();
            win.cancelAnimationFrame(frame);
            frame = 0;
            mounted.forEach(function (entry, node) { remove(node, entry); });
        });
        win.addEventListener('pageshow', function () {
            suspended = false;
            observe();
            schedule();
        });
        schedule();
    }

    return { compute: compute, money: money, start: start };
}));
