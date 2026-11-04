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
    var checkoutView = !!document.getElementById('vc-checkout-pricing');
    var es = /^es(?:-|$)/i.test(document.documentElement.lang || '');
    var text = (en, spanish) => es ? spanish : en;

    // Geometry-only vector ink frame, with small deterministic wear in the rings.
    // Dynamic money remains real text and is not eroded by the ink mask.
    function SavingsStamp(props) {
        var stampLabel = props.label || text('You saved', 'Ahorraste');
        var ref = wp.element.useRef(null);
        wp.element.useEffect(function () {
            var el = ref.current;
            if (!el || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
            var animations = [];
            el.getAnimations?.().forEach(function (a) { a.cancel(); });
            var impression = el.animate?.([
                { transform: 'perspective(600px) translateY(-24px) rotateX(16deg) rotate(-15deg) scale(1.22)', opacity: 0 },
                { transform: 'perspective(600px) translateY(1px) rotateX(0deg) rotate(-10deg) scale(.97)', opacity: 1, offset: .58 },
                { transform: 'perspective(600px) translateY(0) rotate(-9deg) scale(1)', opacity: 1 }
            ], { duration: 480, easing: 'cubic-bezier(.16,1,.3,1)' });
            if (impression) animations.push(impression);
            el.parentElement?.querySelectorAll('.vc-stamp-dust i').forEach(function (p, i) {
                var angle = (i / 12) * Math.PI * 2;
                var dust = p.animate?.([
                    { transform: 'translate(0,0) scale(.2)', opacity: 0 },
                    { opacity: .25, offset: .18 },
                    { transform: 'translate(' + Math.round(Math.cos(angle) * 78) + 'px,' + Math.round(Math.sin(angle) * 42) + 'px) scale(2.8)', opacity: 0 }
                ], { duration: 540, delay: 160, easing: 'cubic-bezier(.16,1,.3,1)' });
                if (dust) animations.push(dust);
            });
            return function () { animations.forEach(function (a) { a.cancel(); }); };
        }, [props.amount]);
        var wear = Array.from({ length: 38 }, function (_, i) {
            var angle = i * 2.39996, r = i % 3 ? 66 : 59;
            return h('ellipse', { key: i, cx: 74 + Math.cos(angle) * r, cy: 74 + Math.sin(angle) * r, rx: .6 + i % 4 * .35, ry: .35 + i % 3 * .2, fill: '#fff' });
        });
        return h('div', { className: 'vc-stamp-impact' },
            h('span', { className: 'vc-stamp-dust', 'aria-hidden': true }, Array.from({length:12}, function (_, i) { return h('i', { key:i }); })),
            h('div', { ref: ref, className: 'vc-savings-stamp', role: 'img', 'aria-label': stampLabel + ' ' + props.amount },
                h('svg', { viewBox:'0 0 148 148', 'aria-hidden':true, focusable:'false' },
                    h('circle', { cx:74, cy:74, r:67, fill:'none', stroke:'currentColor', strokeWidth:3.2 }),
                    h('circle', { cx:74, cy:74, r:59, fill:'none', stroke:'currentColor', strokeWidth:1.1, strokeDasharray:'116 1.4 47 1 144 1.2 59' }),
                    wear
                ),
                h('span', { className:'vc-savings-stamp__label', 'aria-hidden':true }, stampLabel),
                h('strong', { className:'vc-savings-stamp__amount', 'aria-hidden':true }, props.amount),
                h('span', { className:'vc-savings-stamp__brand', 'aria-hidden':true, translate:'no' }, 'VialCove')
            )
        );
    }

    function useSavings(scope) {
        var storedCart = wp.data.useSelect(function (select) {
            return select(window.wc.wcBlocksData.cartStore).getCartData();
        }, []);
        var pricing = storedCart?.extensions?.peptide_core?.sale_savings;
        if (!pricing) return null;
        if (pricing.savings_available === false) return null;
        var decimals = Number(pricing.currency_minor_unit);
        if (!Number.isInteger(decimals) || decimals < 0 || decimals > 6) return null;
        var total = checkoutView && pricing.total_savings !== undefined ? pricing.total_savings : pricing.sale_savings;
        if (checkoutView && scope === 'items') {
            var regular = Number(pricing.display_regular_subtotal), subtotal = Number(pricing.display_sale_subtotal);
            if (!Number.isSafeInteger(regular) || !Number.isSafeInteger(subtotal) || regular < subtotal || subtotal < 0) return null;
            total = regular - subtotal;
        }
        var amount = money(total, pricing.currency_code, decimals);
        if (!amount || Number(total) <= 0) return null;
        return { pricing:pricing, decimals:decimals, amount:amount };
    }

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
        var data = useSavings('items');
        if (!data) return null;
        var pricing = data.pricing, decimals = data.decimals;
        var regular = money(checkoutView ? pricing.display_regular_subtotal ?? pricing.regular_subtotal : pricing.regular_subtotal, pricing.currency_code, decimals);
        var subtotal = money(checkoutView ? pricing.display_sale_subtotal ?? pricing.sale_subtotal : pricing.sale_subtotal, pricing.currency_code, decimals);
        if (!regular || !subtotal) return null;
        var demo = pricing.compare_is_demo === true;
        return h('section', { className: 'vc-cart-savings', 'aria-label': text('Order savings', 'Ahorro del pedido') },
            h('div', { className: 'vc-cart-savings__comparison' },
                h('span', null, text('Product subtotal', 'Subtotal de productos')),
                h('span', { className: 'vc-cart-savings__amounts' },
                    h('del', { 'aria-label': (demo ? 'Demo comparison subtotal ' : 'Regular subtotal ') + regular }, regular),
                    h('span', { 'aria-label': 'Sale subtotal ' + subtotal }, subtotal)
                )
            ),
            checkoutView ? h(SavingsStamp, { amount:data.amount, label:text('Item savings','En productos') }) : h('div',{className:'vc-cart-savings__total'},h('span',null,text('You save','Ahorras')),h('strong',null,data.amount)),
            !checkoutView && h('p',{className:'vc-cart-savings__note'},'Already included in item prices. Coupons are shown separately.'),
            demo && h('p', { className: 'vc-cart-savings__demo' },
                h('span', null, 'Demo pricing'),
                'Comparison prices are simulated.'
            )
        );
    }

    function FooterSavings() {
        var data = useSavings();
        return data ? h('section', {className:'vc-order-savings', 'aria-label':text('Order savings','Ahorro del pedido')},
            h('p', null, text('Total savings', 'Ahorro total')),
            h(SavingsStamp, {amount:data.amount,label:text('Total saved','Ahorro total')})
        ) : null;
    }
    window.VialCoveSavings = Object.freeze({ Footer:FooterSavings, Stamp:SavingsStamp });

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
