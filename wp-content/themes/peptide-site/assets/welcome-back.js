/**
 * 1 号站定稿 P1-3 / P1-1：首页 “欢迎回来” / “订单待付款” 提示条 + 最近浏览。配置 window.vcWelcomeBack（inc/welcome-back.php）。
 * 只在浏览器里读写 localStorage / sessionStorage；读不到时当作首次访问，什么都不显示。
 */
(function (window, document) {
  'use strict';
  const cfg = window.vcWelcomeBack || {};
  const SNAP = 'vcBagSnapshot', SHOWN = 'vcWelcomeShown', DAYS = 30;
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
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text) n.textContent = text; return n; };
  const track = (type, data) => { try { document.dispatchEvent(new CustomEvent('vc:track', {detail: {type, data}})); } catch (_) {} };
  const getJSON = async url => {
    const r = await window.fetch(url, {credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json'}});
    if (!r.ok) throw new Error('read ' + r.status);
    return r.json();
  };
  const restoreUrl = items => {
    const base = cfg.cartUrl || '/cart/';
    const list = items.filter(i => /^\d+$/.test(String(i[0])) && /^\d+$/.test(String(i[1]))).map(i => i[0] + 'x' + i[1]).join(',');
    return list ? base + (base.indexOf('?') < 0 ? '?' : '&') + 'pc_cart=' + encodeURIComponent(list) : base;
  };

  function mount(strip) {
    const main = document.getElementById('main-content') || document.querySelector('main');
    if (!main) return;
    main.insertBefore(strip, main.firstChild);
  }

  function build({title, line, button, href, kind, onClick}) {
    const box = el('section', 'vc-welcome vc-welcome--' + kind);
    box.setAttribute('aria-label', title);
    const wrap = el('div', 'vc-welcome__inner');
    const text = el('div', 'vc-welcome__text');
    text.append(el('strong', '', title), el('span', '', line));
    const go = el('a', 'vc-welcome__go', button);
    go.href = href;
    if (onClick) go.addEventListener('click', onClick);
    const close = el('button', 'vc-welcome__x', '×');
    close.type = 'button';
    close.setAttribute('aria-label', 'Dismiss');
    close.addEventListener('click', () => box.remove());
    wrap.append(text, go, close);
    box.append(wrap);
    return box;
  }

  async function recent(strip) {
    if (!cfg.recentApi) return;
    try {
      const body = await getJSON(cfg.recentApi);
      if (body && body.visible === false) return; // the store's own on/off and minimum for this list
      const items = (Array.isArray(body) ? body : body && body.items || []).filter(i => i && i.name && i.permalink).slice(0, 6);
      if (!items.length) return;
      const row = el('div', 'vc-welcome__recent');
      row.append(el('span', 'vc-welcome__recent-title', 'Recently viewed'));
      const list = el('div', 'vc-welcome__recent-list');
      items.forEach(i => {
        const a = el('a', 'vc-welcome__chip');
        a.href = i.permalink;
        // Same hooks as the bag's recently viewed cards: the home page opens the product dialog in place.
        if (Number.isSafeInteger(i.productId) && i.productId > 0) { a.dataset.vcHomeProduct = String(i.productId); a.dataset.vcQuickOpen = String(i.productId); }
        if (i.image && i.image.url) { const img = el('img'); img.src = i.image.url; img.alt = ''; img.width = 40; img.height = 40; img.loading = 'lazy'; a.append(img); }
        const t = el('span', 'vc-welcome__chip-text');
        t.append(el('b', '', i.name), el('span', '', (i.priceIsFrom ? 'From ' : '') + (i.price && i.price.text || '')));
        a.append(t);
        list.append(a);
      });
      row.append(list);
      strip.querySelector('.vc-welcome__inner').after(row);
    } catch (_) { /* Recently viewed is optional. */ }
  }

  async function run() {
    // 付款成功回到首页（/?pc_paid=…）：购物车已经买过了，丢掉另存的那份。
    if (/[?&]pc_paid=/.test(window.location.search)) { try { window.localStorage.removeItem(SNAP); } catch (_) {} }
    let cart = null;
    try { cart = await getJSON(cfg.cartApi); } catch (_) { return; }
    const items = (cart.items || []).map(i => [i.id, i.quantity]);
    const unit = cart.totals && cart.totals.currency_minor_unit;
    const symbol = cart.totals && cart.totals.currency_symbol;
    const subtotal = cart.totals && cart.totals.total_items;
    if (items.length) local.set(SNAP, {items, count: cart.items_count, subtotal, unit, symbol, at: Date.now()});
    if (session.get(SHOWN)) return;

    const pending = cart.extensions && cart.extensions.peptide_core && cart.extensions.peptide_core.pending_order;
    let strip = null;
    if (pending && pending.pay_url) {
      strip = build({
        kind: 'pending',
        title: pending.state === 'failed' ? 'Your last payment didn’t go through' : 'Your order is waiting for payment',
        line: pending.items + (pending.items === 1 ? ' item' : ' items') + ' · ' + money(pending.total_minor, unit, symbol) + ' · your details are saved',
        button: 'Complete payment',
        href: pending.pay_url,
        onClick: () => track('pending_banner_click', {orderId: pending.order_id, state: pending.state})
      });
    } else if (items.length) {
      strip = build({
        kind: 'bag',
        title: 'Welcome back',
        line: 'Your bag still has ' + cart.items_count + (cart.items_count === 1 ? ' box' : ' boxes') + ' · ' + money(subtotal, unit, symbol),
        button: 'View bag',
        href: cfg.cartUrl || '/cart/'
      });
    } else {
      const snap = local.get(SNAP);
      if (snap && Array.isArray(snap.items) && snap.items.length && Date.now() - Number(snap.at) < DAYS * 864e5) {
        strip = build({
          kind: 'bag',
          title: 'Welcome back',
          line: 'We saved your bag: ' + snap.count + (snap.count === 1 ? ' box' : ' boxes') + (snap.subtotal ? ' · ' + money(snap.subtotal, snap.unit, snap.symbol) : ''),
          button: 'View bag',
          href: restoreUrl(snap.items)
        });
      }
    }
    if (!strip) return;
    session.set(SHOWN, '1');
    mount(strip);
    recent(strip);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();
}(window, document));
