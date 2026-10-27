(function () {
    'use strict';

    var checkout = window.wc && window.wc.blocksCheckout;
    var wp = window.wp;
    if (!checkout || !wp || !wp.element || !wp.plugins) return;

    function itemPricing(extensions, args) {
        var data = extensions && extensions.peptide_core;
        if (!data && args && args.cartItem && args.cartItem.extensions) {
            data = args.cartItem.extensions.peptide_core;
        }
        return data && data.pricing;
    }

    if (typeof checkout.registerCheckoutFilters === 'function') {
        checkout.registerCheckoutFilters('vialcove-sale-pricing', {
            saleBadgePriceFormat: function (value, extensions, args) {
                var pricing = itemPricing(extensions, args);
                if (!pricing || Number(pricing.sale_savings) <= 0) return value;
                var percent = Number(pricing.percent_off);
                var label = Number.isFinite(percent) && percent > 0 && percent <= 100
                    ? Math.floor(percent) + '% (<price/>)' : '<price/>';
                return label + (pricing.compare_is_demo === true ? ' · Demo pricing' : '');
            },
            cartItemClass: function (value, extensions, args) {
                var pricing = itemPricing(extensions, args);
                return pricing && Number(pricing.sale_savings) > 0
                    ? (value + ' vc-cart-sale-item').trim() : value;
            }
        });
    }

    // Subscribe to Woo's official store; never reconstruct totals in the browser.
    if (!wp.data || !wp.data.useSelect || !window.wc.wcBlocksData || !window.wc.wcBlocksData.cartStore) return;
    var h = wp.element.createElement;

    function money(value, currency, decimals) {
        var amount = Number(value);
        if (!Number.isSafeInteger(amount) || amount < 0) return null;
        try {
            return new Intl.NumberFormat(document.documentElement.lang || 'en-US', {
                style: 'currency', currency: currency,
                minimumFractionDigits: decimals, maximumFractionDigits: decimals
            }).format(amount / Math.pow(10, decimals));
        } catch (error) {
            return null;
        }
    }

    function SavingsSummary() {
        var storedCart = wp.data.useSelect(function (select) {
            return select(window.wc.wcBlocksData.cartStore).getCartData();
        }, []);
        var extensions = storedCart && storedCart.extensions;
        var pricing = extensions && extensions.peptide_core && extensions.peptide_core.sale_savings;
        if (!pricing || Number(pricing.sale_savings) <= 0) return null;
        var decimals = Number(pricing.currency_minor_unit);
        if (!Number.isInteger(decimals) || decimals < 0 || decimals > 6) return null;
        var regular = money(pricing.regular_subtotal, pricing.currency_code, decimals);
        var subtotal = money(pricing.sale_subtotal, pricing.currency_code, decimals);
        var savings = money(pricing.sale_savings, pricing.currency_code, decimals);
        if (!regular || !subtotal || !savings) return null;
        var demo = pricing.compare_is_demo === true;
        return h('section', { className: 'vc-cart-savings', 'aria-label': 'Product price savings' },
            h('div', { className: 'vc-cart-savings__comparison' },
                h('span', null, 'Product subtotal'),
                h('span', { className: 'vc-cart-savings__amounts' },
                    h('del', { 'aria-label': (demo ? 'Demo comparison subtotal ' : 'Regular subtotal ') + regular }, regular),
                    h('span', { 'aria-label': 'Sale subtotal ' + subtotal }, subtotal)
                )
            ),
            h('div', { className: 'vc-cart-savings__total' },
                h('span', null, 'You save'),
                h('strong', null, savings)
            ),
            h('p', { className: 'vc-cart-savings__note' }, 'Already included in item prices. Coupons are shown separately.'),
            demo && h('p', { className: 'vc-cart-savings__demo' },
                h('span', null, 'Demo pricing'),
                'Comparison prices are simulated.'
            )
        );
    }

    var checkoutMount = document.getElementById('vc-checkout-pricing');
    if (checkoutMount) {
        // Checkout's responsive duplicate OrderMeta slots lose their portal on
        // breakpoint changes. This theme-owned root stays outside that tree.
        // It renders only the informational summary; native checkout is untouched.
        if (typeof wp.element.createRoot === 'function') {
            wp.element.createRoot(checkoutMount).render(h(SavingsSummary));
        } else if (typeof wp.element.render === 'function') {
            wp.element.render(h(SavingsSummary), checkoutMount);
        }
    } else if (checkout.ExperimentalOrderMeta && typeof wp.plugins.registerPlugin === 'function') {
        wp.plugins.registerPlugin('vialcove-cart-sale-savings', {
            scope: 'woocommerce-checkout',
            render: function () {
                return h(checkout.ExperimentalOrderMeta, null, h(SavingsSummary));
            }
        });
    }
}());
