/**
 * P1-1 (排版 A) + P0-6: Slim, privacy-safe top return strip.
 * - Pending/failed orders ALWAYS bypass sessionStorage vcWelcomeShown so returning buyers never lose their rescue CTA.
 * - Shows masked State + ZIP + Coupon from pcCheckoutDraft (30 days) and jumps straight to /checkout/ when address is saved.
 */
(function (window, document) {
  'use strict';
  const cfg = window.vcWelcomeBack || {};
  const SNAP = 'vcBagSnapshot', DRAFT = 'pcCheckoutDraft', DISMISSED = 'vcWelcomeDismissed', DAYS = 30;
  const local = {
    get(k) { try { return JSON.parse(window.localStorage.getItem(k) || 'null'); } catch (_) { return null; } },
    set(k, v) { try { window.localStorage.setItem(k, JSON.stringify(v)); } catch (_) {} }
  };
  const session = {
    get(k) { try { return window.sessionStorage.getItem(k); } catch (_) { return null; } },
    set(k, v) { try { window.sessionStorage.setItem(k, v); } catch (_) {} }
  };
  const money = (minor, unit, symbol) => {
    const n = Number(minor) / Math.pow(10, Number(unit) || 0);
    return Number.isFinite(n) ? (symbol || '$') + n.toFixed(Number(unit) || 0) : '';
  };
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };
  const track = (type, data) => { try { document.dispatchEvent(new CustomEvent('vc:track', { detail: { type, data } })); } catch (_) {} };
  const getJSON = async url => {
    const r = await window.fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
    if (!r.ok) throw new Error('read ' + r.status);
    return r.json();
  };
  const restoreUrl = (items, toCheckout, coupons) => {
    const base = toCheckout ? (cfg.checkoutUrl || '/checkout/') : (cfg.cartUrl || '/cart/');
    const list = items.filter(i => /^\d+$/.test(String(i[0])) && /^\d+$/.test(String(i[1]))).map(i => i[0] + 'x' + i[1]).join(',');
    let url = list ? base + (base.indexOf('?') < 0 ? '?' : '&') + 'pc_cart=' + encodeURIComponent(list) : base;
    if (Array.isArray(coupons) && coupons[0]) {
      url += (url.indexOf('?') < 0 ? '?' : '&') + 'pc_coupon=' + encodeURIComponent(coupons[0]);
    }
    return url;
  };

  function maskedDraftSummary() {
    const d = local.get(DRAFT);
    if (!d || typeof d !== 'object' || !(Date.now() - Number(d.at) < DAYS * 864e5)) return null;
    const ship = d.shipping || {};
    const loc = [ship.state, ship.postcode].filter(Boolean).join(' ');
    const coupon = Array.isArray(d.coupons) && d.coupons[0] ? String(d.coupons[0]).toUpperCase() : '';
    const hasFields = Boolean(d.email || ship.address_1 || ship.postcode);
    return hasFields ? { loc, coupon, coupons: d.coupons || [] } : null;
  }

  function mount(strip) {
    const main = document.getElementById('main-content') || document.querySelector('main');
    if (!main || document.querySelector('.vc-welcome')) return;
    main.insertBefore(strip, main.firstChild);
  }

  function build({ title, line, button, href, waHref, kind, dismissKey, onClick }) {
    const box = el('section', 'vc-welcome vc-welcome--' + kind);
    box.setAttribute('aria-label', title);
    const wrap = el('div', 'vc-welcome__inner');
    const text = el('div', 'vc-welcome__text');
    text.append(el('strong', '', title), el('span', '', line));
    const actions = el('div', 'vc-welcome__actions');
    if (waHref) {
      const wa = el('a', 'vc-welcome__wa', '💬 Pay via WhatsApp');
      wa.href = waHref;
      wa.target = '_blank';
      wa.rel = 'noopener noreferrer';
      actions.append(wa);
    }
    const go = el('a', 'vc-welcome__go', button);
    go.href = href;
    if (onClick) go.addEventListener('click', onClick);
    const close = el('button', 'vc-welcome__x', '×');
    close.type = 'button';
    close.setAttribute('aria-label', 'Dismiss');
    close.addEventListener('click', () => {
      if (dismissKey) session.set(dismissKey, '1');
      box.remove();
    });
    actions.append(go, close);
    wrap.append(text, actions);
    box.append(wrap);
    return box;
  }

  async function run() {
    if (/[?&]pc_paid=/.test(window.location.search)) {
      try { window.localStorage.removeItem(SNAP); window.localStorage.removeItem(DRAFT); } catch (_) {}
      return;
    }
    let cart = null;
    try { cart = await getJSON(cfg.cartApi); } catch (_) { return; }
    const items = (cart.items || []).map(i => [i.id, i.quantity]);
    const unit = cart.totals && cart.totals.currency_minor_unit;
    const symbol = cart.totals && cart.totals.currency_symbol;
    const subtotal = cart.totals && cart.totals.total_items;
    if (items.length) local.set(SNAP, { items, count: cart.items_count, subtotal, unit, symbol, at: Date.now() });

    const draftInfo = maskedDraftSummary();
    const pending = cart.extensions && cart.extensions.peptide_core && cart.extensions.peptide_core.pending_order;
    let strip = null;

    // 1. Pending or failed payment ALWAYS surfaces (never blocked by generic welcome flag)
    if (pending && pending.pay_url) {
      const isFailed = pending.state === 'failed';
      const amtText = money(pending.total_minor, unit, symbol);
      const waHref = cfg.whatsappUrl
        ? cfg.whatsappUrl + (cfg.whatsappUrl.indexOf('?') < 0 ? '?' : '&') + 'text=' + encodeURIComponent(
            'Hi VialCove, I need help completing payment for Order #' + pending.order_id + ' (' + amtText + ').'
          )
        : '';
      strip = build({
        kind: isFailed ? 'failed' : 'pending',
        title: isFailed ? 'Payment interrupted' : 'Order waiting for payment',
        line: '· ' + pending.items + (pending.items === 1 ? ' box' : ' boxes') + ' (' + amtText + ') · details reserved',
        button: 'Resume →',
        href: pending.pay_url,
        waHref,
        onClick: () => track('pending_banner_click', { orderId: pending.order_id, state: pending.state })
      });
    } else if (!session.get(DISMISSED)) {
      const activeCoupon = (cart.coupons && cart.coupons[0] && String(cart.coupons[0].code).toUpperCase()) || (draftInfo && draftInfo.coupon) || '';
      const metaParts = [];
      if (activeCoupon) metaParts.push(activeCoupon);
      if (draftInfo && draftInfo.loc) metaParts.push(draftInfo.loc + ' saved');

      if (items.length) {
        strip = build({
          kind: 'bag',
          title: '🛍 ' + cart.items_count + (cart.items_count === 1 ? ' box' : ' boxes'),
          line: '· ' + money(cart.totals?.total_price || subtotal, unit, symbol) + (metaParts.length ? ' · ' + metaParts.join(' · ') : ''),
          button: draftInfo ? 'Resume →' : 'View bag →',
          href: draftInfo ? (cfg.checkoutUrl || '/checkout/') : (cfg.cartUrl || '/cart/'),
          dismissKey: DISMISSED
        });
      } else {
        const snap = local.get(SNAP);
        if (snap && Array.isArray(snap.items) && snap.items.length && Date.now() - Number(snap.at) < DAYS * 864e5) {
          strip = build({
            kind: 'bag',
            title: '🛍 Saved bag (' + snap.count + (snap.count === 1 ? ' box' : ' boxes') + ')',
            line: (snap.subtotal ? '· ' + money(snap.subtotal, snap.unit, snap.symbol) : '') + (metaParts.length ? ' · ' + metaParts.join(' · ') : ''),
            button: draftInfo ? 'Resume →' : 'Restore bag →',
            href: restoreUrl(snap.items, Boolean(draftInfo), draftInfo ? draftInfo.coupons : []),
            dismissKey: DISMISSED
          });
        }
      }
    }
    if (!strip) return;
    mount(strip);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();
}(window, document));
