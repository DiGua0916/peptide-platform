/* Mobile presentation over native Woo forms. No cart, pricing or report records are written here. */
(function () {
  'use strict';
  const safeURL = value => {
    if (typeof value !== 'string' || !value.trim()) return '';
    try { const u = new URL(value, location.href); return /^https?:$/.test(u.protocol) && u.origin === location.origin && !u.username && !u.password ? u.href : ''; }
    catch (_) { return ''; }
  };
  const json = value => { try { return JSON.parse(value || '{}'); } catch (_) { return {}; } };
  function node(tag, className, text) { const n = document.createElement(tag); n.className = className; if (text) n.textContent = text; return n; }
  function button(className, label, action) { const n = node('button', className, label); n.type = 'button'; n.addEventListener('click', action); return n; }
  // Match the complete specification, removing only an explicit sales-pack suffix.
  function spec(value) {
    return String(value || '').normalize('NFKC').toLowerCase().trim()
      .replace(/\s*[×x*]\s*\d+\s*vials?\s*$/i, '').replace(/\s+/g, '').replace(/μg|ug/g, 'mcg');
  }
  const date = value => {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '')) return '';
    const parsed = new Date(value + 'T00:00:00Z');
    return Number.isFinite(parsed.getTime()) && parsed.toISOString().slice(0, 10) === value ? value : '';
  };
  function latestReport(items, productId, specification) {
    const key = spec(specification);
    if (!key) return null;
    return items.filter(item => Number(item?.product?.id) === productId && spec(item.specification) === key && item.file?.mime === 'application/pdf' && safeURL(item.view_url))
      .sort((a, b) => date(b.issued_on).localeCompare(date(a.issued_on)) || Number(b.id) - Number(a.id))[0] || null;
  }
  // A queued native close belongs to its own dialog, never a later PDF viewer.
  function createChildDialogs(productId) {
    let current = null;
    const viewers = new WeakMap();
    const dispose = box => { const owner = viewers.get(box); viewers.delete(box); owner?.dispose(); };
    const close = box => { if (box?.open) box.close(); };
    return {
      create(className, label) {
        if (current) { close(current); dispose(current); current.remove(); }
        const box = node('dialog', className + ' vc-product-child-dialog'); current = box;
        box.setAttribute('aria-label', label); box.dataset.vcProductParent = String(productId);
        const dismiss = button('vc-product-child-close', '×', () => close(box));
        dismiss.setAttribute('aria-label', 'Close ' + label); box.append(dismiss);
        box.addEventListener('click', event => { if (event.target === box) close(box); });
        box.addEventListener('close', () => dispose(box), {once:true});
        document.body.append(box); return box;
      },
      attach(box, owner) { dispose(box); viewers.set(box, owner); },
      destroy() { if (current) { close(current); dispose(current); current.remove(); current = null; } }
    };
  }
  function createSectionNavigation(dialog, toolbar, sections, signal) {
    let frame = 0, disposed = false;
    const available = () => sections.filter(item => item.target && !item.target.hidden && !item.button.hidden);
    function update() {
      frame = 0; if (disposed || document.hidden || !dialog.open) return;
      const items = available(); if (!items.length) return;
      const inset = toolbar.getBoundingClientRect().height + (dialog.clientTop || 0) + 8;
      const anchor = dialog.getBoundingClientRect().top + inset;
      let active = items[0];
      for (const item of items) { if (item.target.getBoundingClientRect().top <= anchor + 1) active = item; }
      if (dialog.scrollTop > 0 && dialog.scrollTop + dialog.clientHeight >= dialog.scrollHeight - 2) active = items[items.length - 1];
      for (const item of sections) {
        item.button.classList.toggle('is-active', item === active);
        if (item === active) item.button.setAttribute('aria-current', 'location');
        else item.button.removeAttribute('aria-current');
      }
    }
    const refresh = () => { if (!disposed && !frame && !document.hidden && dialog.open) frame = requestAnimationFrame(update); };
    dialog.addEventListener('scroll', refresh, {passive:true,signal});
    dialog.addEventListener('toggle', refresh, {capture:true,signal});
    window.addEventListener('resize', refresh, {passive:true,signal});
    document.addEventListener('visibilitychange', () => {
      if (document.hidden) { cancelAnimationFrame(frame); frame = 0; } else refresh();
    }, {signal});
    refresh();
    return {
      refresh,
      go(key) {
        const item = available().find(item => item.key === key); if (!item || disposed || !dialog.open) return;
        const top = item.target.getBoundingClientRect().top - dialog.getBoundingClientRect().top + dialog.scrollTop - toolbar.getBoundingClientRect().height - (dialog.clientTop || 0) - 8;
        if (!item.target.hasAttribute('tabindex')) item.target.setAttribute('tabindex', '-1');
        item.target.focus({preventScroll:true});
        dialog.scrollTo({top:Math.max(0,top),behavior:matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth'});
        refresh();
      },
      destroy() { disposed = true; cancelAnimationFrame(frame); frame = 0; }
    };
  }
  function createGalleryPlayback({track,slides,dialog,media,signal,isActive = () => true}) {
    const reduced = matchMedia('(prefers-reduced-motion: reduce)'), holds = new Set();
    let timer = 0, disposed = false, inView = typeof IntersectionObserver !== 'function';
    const available = () => slides.filter(slide => !slide.hidden && !slide.disabled);
    function stop() { if (timer) clearTimeout(timer); timer = 0; }
    function canPlay() { return !disposed && !holds.size && inView && !reduced.matches && !document.hidden && dialog.open && isActive() && available().length > 1; }
    function refresh() {
      if (disposed) return;
      if (!canPlay()) { stop(); return; }
      if (!timer) timer = setTimeout(() => { timer = 0; if (canPlay()) { step(1,false); refresh(); } },6500);
    }
    function step(direction, manual = true) {
      if (disposed || !dialog.open) return;
      const items = available(); if (items.length < 2) return;
      const left = track.getBoundingClientRect().left;
      const current = items.reduce((best,item,index) => Math.abs(item.getBoundingClientRect().left-left) < Math.abs(items[best].getBoundingClientRect().left-left) ? index : best,0);
      const target = items[(current + direction + items.length) % items.length];
      track.scrollTo({left:target.getBoundingClientRect().left-left+track.scrollLeft,behavior:reduced.matches?'instant':'smooth'});
      if (manual) { stop(); refresh(); }
    }
    const hold = key => { holds.add(key); refresh(); };
    const release = key => { holds.delete(key); refresh(); };
    track.addEventListener('pointerdown',() => hold('pointer'),{passive:true,signal});
    window.addEventListener('pointerup',() => release('pointer'),{passive:true,signal});
    window.addEventListener('pointercancel',() => release('pointer'),{passive:true,signal});
    media.addEventListener('pointerenter',event => { if(event.pointerType !== 'touch')hold('hover'); },{signal});
    media.addEventListener('pointerleave',() => release('hover'),{signal});
    media.addEventListener('focusin',() => hold('focus'),{signal});
    media.addEventListener('focusout',event => { if (!media.contains(event.relatedTarget))release('focus'); },{signal});
    track.addEventListener('wheel',() => { stop(); refresh(); },{passive:true,signal});
    track.addEventListener('keydown',() => { stop(); refresh(); },{signal});
    document.addEventListener('visibilitychange',() => { if(document.hidden)holds.delete('pointer'); refresh(); },{signal});
    document.addEventListener('close',refresh,{capture:true,signal});
    window.addEventListener('pagehide',() => hold('page'),{signal});
    window.addEventListener('pageshow',() => release('page'),{signal});
    const changed = () => refresh();
    if (reduced.addEventListener) reduced.addEventListener('change',changed); else reduced.addListener?.(changed);
    const observer = typeof IntersectionObserver === 'function' ? new IntersectionObserver(entries => { const entry=entries[entries.length-1]; inView = !!entry?.isIntersecting && entry.intersectionRatio >= 0.25; refresh(); },{root:dialog,threshold:0.25}) : null;
    observer?.observe(track);
    function destroy() {
      if (disposed) return; disposed=true; stop(); observer?.disconnect(); signal?.removeEventListener('abort',destroy);
      if (reduced.removeEventListener) reduced.removeEventListener('change',changed); else reduced.removeListener?.(changed);
    }
    signal?.addEventListener('abort',destroy,{once:true}); refresh();
    return {refresh,destroy};
  }
  function reportPresentationKey(item, selection, variationId) {
    return JSON.stringify([selection, variationId, item ? [item.id, item.product?.id, item.source_product, item.specification, item.issued_on, item.issuer_name, item.title, item.report_number, item.source_lot, item.file?.mime, item.file?.size_bytes, item.view_url, item.download_url, item.thumbnail_url] : null]);
  }
  // The aggregate DTO only decides whether assets are needed. It never grants
  // this viewport an experience that its own published device row disabled.
  function deviceSettings(config, mobile) {
    const key = mobile ? 'mobile' : 'desktop';
    const devices = config?.devices;
    const row = devices && typeof devices === 'object' ? devices[key] : config;
    if (!row || typeof row !== 'object') return {enabled:false,mobileDialog:false};
    return {...row, enabled:!!row.enabled && !!row.mobileDialog && row.visibility?.[key] !== false};
  }
  // Move and restore the real native nodes, including their handlers/state.
  // No form, quantity, Add or Buy control is cloned when changing viewport.
  function presentationRestore(root, dialog, media, toolbar) {
    const mediaNodes = [...media.childNodes], toolbarNodes = toolbar ? [...toolbar.childNodes] : [];
    const moved = [], states = [], owned = new Set();
    const hadRoot = root.classList.contains('vc-product-experience'), hadDialog = dialog.classList.contains('vc-product-dialog');
    let disposed = false;
    return {
      own(value) { owned.add(value); return value; },
      preserve(value) {
        if (!value) return;
        states.push({value,children:[...value.childNodes],attributes:value.getAttributeNames().map(key=>[key,value.getAttribute(key)]),hidden:value.hidden});
      },
      move(value, parent) {
        if (!value) return;
        const marker = document.createComment('native product presentation'); value.before(marker);
        moved.push({value,marker}); if (parent) parent.append(value); else value.remove();
      },
      destroy() {
        if (disposed) return; disposed = true;
        for (const {value,marker} of moved.reverse()) { if (marker.parentNode) marker.replaceWith(value); }
        media.replaceChildren(...mediaNodes);
        if (toolbar) toolbar.replaceChildren(...toolbarNodes);
        for (const value of owned) value.remove();
        for (const {value,children,attributes,hidden} of states) {
          value.replaceChildren(...children); for (const key of value.getAttributeNames()) value.removeAttribute(key);
          for (const [key,content] of attributes) value.setAttribute(key,content); value.hidden=hidden;
        }
        if (!hadRoot) root.classList.remove('vc-product-experience');
        if (!hadDialog) dialog.classList.remove('vc-product-dialog');
      }
    };
  }
  function nativeMedia(root, image) {
    const area = root.querySelector(':scope > .vc-showcase-quick__media'); if (!area) return;
    const source = safeURL(image?.src);
    if (!source) { area.replaceChildren(node('p','vc-home-quick__missing','Image not available for this specification.')); return; }
    let img = area.querySelector('img'); if (!img) { img = node('img',''); area.replaceChildren(img); }
    for (const key of ['sizes','srcset','width','height']) {
      const raw = image?.[key], value = key === 'width' || key === 'height' ? (Number.isSafeInteger(Number(raw)) && Number(raw) > 0 ? String(Number(raw)) : '') : (typeof raw === 'string' ? raw : '');
      if (value) img.setAttribute(key,value); else img.removeAttribute(key);
    }
    img.alt = String(image?.alt || root.dataset.vcProductName || ''); img.loading = 'eager'; img.decoding = 'async';
    if (img.getAttribute('src') !== source) img.src = source;
  }
  window.vcProductReportMatch = Object.freeze({ specification: spec, latest: latestReport });

  function createExperience(root, dialog, settings) {
    const defaults = json(root.dataset.vcHomeInitial);
    if (!settings.enabled || !settings.mobileDialog) return null;
    const summary = root.querySelector('.summary'), media = root.querySelector(':scope > .vc-showcase-quick__media');
    const form = root.querySelector('form.cart'), mainAdd = form?.querySelector('.single_add_to_cart_button');
    if (!summary || !media || !form || !mainAdd) return null;
    const originalToolbar = dialog.querySelector('.vc-home-quick__toolbar');
    const restore = presentationRestore(root,dialog,media,originalToolbar);
    const productId = Number(root.dataset.vcProductId), productName = root.dataset.vcProductName || '';
    const lifecycle = new AbortController(), signal = lifecycle.signal;
    let destroyed = false, productImage = root.vcHomeImage === undefined ? defaults.image : root.vcHomeImage;
    let reports = [], report = null, ticker = null, tickerRevision = null, reportSignature = '';
    const children = createChildDialogs(productId);
    let reportRequest = null, reportReadPromise = null, lastReportRead = 0, lastSelection = '', frame = 0;
    let reportCursor = {page:0,pages:1,total:null};
    let reportOpenIntent = 0;
    const knownImages = root.vcDialogImages instanceof Map ? root.vcDialogImages : new Map(); root.vcDialogImages=knownImages;
    root.classList.add('vc-product-experience'); dialog.classList.add('vc-product-dialog');
    const heading = root.querySelector('.vc-dialog-heading') || restore.own(node('header', 'vc-dialog-heading'));
    if (!heading.isConnected) root.prepend(heading);
    const eyebrow = summary.querySelector('.vc-home-quick__eyebrow'); if (eyebrow) restore.move(eyebrow,heading);
    const title = summary.querySelector('.vc-showcase-quick__title'); if (title) restore.move(title,heading);
    let rating = heading.querySelector('.vc-dialog-rating');
    if (!rating && defaults.ratingHtml && defaults.ratingCount > 0) {
      rating = restore.own(node('div', 'vc-dialog-rating')); rating.innerHTML = defaults.ratingHtml;
      rating.append(node('span', '', defaults.ratingCount + (defaults.ratingCount === 1 ? ' review' : ' reviews'))); heading.append(rating);
    }
    let broadcast = heading.querySelector('[data-vc-product-broadcast]');
    restore.preserve(broadcast);
    if (!broadcast) {
      broadcast = restore.own(button('announcement', '', () => {})); broadcast.hidden = true; broadcast.dataset.vcProductBroadcast = '';
      broadcast.innerHTML = '<span class="announcement-line"><svg class="announcement-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg><span class="announcement-copy"></span></span>';
      heading.append(broadcast);
    }
    media.replaceChildren();
    const track = node('div', 'vc-dialog-gallery'); track.setAttribute('aria-label', 'Product image and available report');
    const productSlide = button('vc-dialog-slide vc-dialog-slide--product', '', () => openImage());
    const reportSlide = button('vc-dialog-slide vc-dialog-slide--report', '', () => openReport()); reportSlide.hidden = true;
    const ribbon = node('span', 'vc-dialog-ribbon'), productImg = node('img', 'vc-dialog-product-image');
    const emptyImage = node('p', 'vc-home-quick__missing', 'Image not available for this specification.');
    const zoom = node('span', 'vc-dialog-zoom', '↗'); zoom.setAttribute('aria-hidden', 'true');
    productSlide.append(productImg, emptyImage, ribbon, zoom); productSlide.setAttribute('aria-label', 'Enlarge product image');
    track.append(productSlide, reportSlide); media.append(track);
    const caption = node('p', 'vc-dialog-media-caption'); media.append(caption);
    const gallery = createGalleryPlayback({track,slides:[productSlide,reportSlide],dialog,media,signal,isActive:() => !root.vcHomeSelectionPending && ![...document.querySelectorAll('dialog[open]')].some(layer => layer !== dialog)});
    restore.move(root.querySelector('.vc-dialog-documents'),null);
    const documents = restore.own(node('section', 'vc-dialog-documents')); documents.hidden = true;
    root.append(documents);
    const content = root.querySelector('.vc-product-dialog-content');
    if (content) content.querySelector('details[data-vc-dialog-tab="description"]')?.setAttribute('open', '');
    // Keep the current product's real questions after its report, outside the
    // description accordion. Empty products do not acquire another product's FAQ.
    const questions = content?.querySelector('.vc-product-dialog-questions');
    if (questions) restore.move(questions,root);
    content?.querySelectorAll('[data-vc-product-common-questions]').forEach(section => restore.move(section,root));
    const toolbar = dialog.querySelector('.vc-home-quick__toolbar');
    const toolbarClose = toolbar?.querySelector('.vc-showcase-quick__close') || dialog.querySelector('.vc-showcase-quick__close');
    let navigation = null;
    const reportTab = button('', 'COA', () => navigation?.go('coa')); reportTab.hidden = true;
    if (toolbar) {
      const restoreCloseFocus = document.activeElement === toolbarClose;
      const overviewTab = button('is-active', 'Overview', () => navigation?.go('overview'));
      const detailsTab = button('', 'Details', () => navigation?.go('details')); detailsTab.hidden = !content;
      const bag = node('a', 'vc-dialog-bag cart-link', 'Bag'); bag.href = safeURL(window.vcHomeQuickView?.cartUrl) || '#';
      toolbar.replaceChildren(overviewTab, detailsTab, reportTab, bag);
      if (toolbarClose) toolbar.append(toolbarClose);
      navigation = createSectionNavigation(dialog, toolbar, [{key:'overview',target:heading,button:overviewTab},{key:'details',target:content,button:detailsTab},{key:'coa',target:documents,button:reportTab}], signal);
      if (restoreCloseFocus) toolbarClose.focus({preventScroll:true});
    }
    let support = summary.querySelector('.vc-dialog-support');
    restore.preserve(support);
    if (!support && settings.whatsappEnabled && /^\d{7,15}$/.test(String(settings.whatsappNumber || '').replace(/\D/g, ''))) {
      support = restore.own(node('a', 'vc-dialog-support', settings.whatsappLabel || 'A little help'));
      const message = String(settings.whatsappMessage || '').replace(/\{product\}/g, productName);
      support.href = 'https://wa.me/' + String(settings.whatsappNumber).replace(/\D/g, '') + (message ? '?text=' + encodeURIComponent(message) : '');
      support.target = '_blank'; support.rel = 'noopener noreferrer'; summary.append(support);
    }
    if (support) { support.hidden = !settings.whatsappEnabled || !/^\d{7,15}$/.test(String(settings.whatsappNumber || '').replace(/\D/g, '')); support.textContent=settings.whatsappLabel || 'A little help'; }
    restore.move(root.querySelector('.vc-dialog-offers'),null);
    const offers = restore.own(node('section', 'vc-dialog-offers')); offers.hidden = true; heading.prepend(offers);
    let offerSignature = '';
    // 2026-11-11 店主：商品详情里直接列出满减档位（参考“Buy $200 and get $20”样式），放在商品图下面（规格说明前）。
    // 旧的 .vc-dialog-offers 被 polish 样式整个隐藏、并且会被搬进顶部工具栏；这里用单独的节点，不受影响。
    restore.move(root.querySelector('.vc-offer-tiers'),null);
    const tierBox = restore.own(node('section', 'vc-offer-tiers')); tierBox.hidden = true;
    tierBox.setAttribute('aria-label', 'Order savings');
    const placeTierBox = () => {
      const spec = summary.querySelector(':scope > .vc-home-quick__specification');
      const media = root.querySelector(':scope > .vc-showcase-quick__media');
      if (spec) { if (spec.previousElementSibling !== tierBox) spec.before(tierBox); }
      else if (media) { if (media.nextElementSibling !== tierBox) media.after(tierBox); }
      else if (tierBox.parentElement !== heading) heading.append(tierBox);
    };
    function renderOffers() {
      const candidates = root.vcHomePromotions === undefined ? defaults.promotions : root.vcHomePromotions;
      const active = Array.isArray(candidates) ? candidates.find(item => item.status === 'active' && Array.isArray(item.tiers) && item.tiers.length
        && (!item.ends || Date.parse(item.ends) > Date.now()) && (!item.starts || Date.parse(item.starts) <= Date.now())) : null;
      const signature = JSON.stringify(active || null); if (signature === offerSignature) return;
      offerSignature = signature; offers.replaceChildren(); offers.hidden = !active;
      tierBox.replaceChildren(); tierBox.hidden = !active;
      if (!active) return;
      placeTierBox();
      const money = text => String(text || '').replace(/\.00(?=\s|$)/g, '');
      const icon = node('span', 'vc-offer-tiers__icon'); icon.setAttribute('aria-hidden', 'true');
      icon.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="8.5" width="17" height="4" rx="1"/><path d="M5 12.5v7.5h14v-7.5M12 8.5V20M12 8.5c-1.5-3.5-5.5-4-5.5-1.5S10 8.5 12 8.5zm0 0c1.5-3.5 5.5-4 5.5-1.5S14 8.5 12 8.5z"/></svg>';
      const lines = node('ul', 'vc-offer-tiers__list');
      active.tiers.forEach(tier => {
        const li = node('li', '');
        if (active.type === 'spend_amount' && !active.unlimited) {
          li.append(document.createTextNode('Buy '), node('b', '', money(tier.thresholdText)), document.createTextNode(' and get '), node('b', '', money(tier.rewardText)));
        } else li.append(node('b', '', tier.text));
        lines.append(li);
      });
      tierBox.append(icon, lines);
      const title = node('h2', '', 'Order savings');
      offers.append(title);
      const list = node('ul', 'vc-dialog-offer-tiers' + (active.type === 'spend_amount' && !active.unlimited ? ' vc-dialog-offer-tiers--thresholds' : ''));
      active.tiers.forEach(tier => {
        const item = node('li', '');
        if (active.type === 'spend_amount' && !active.unlimited) {
          const compact = text => String(text || '').replace(/\.00(?=\s|$)/g, '');
          item.append(node('span', 'vc-dialog-offer-threshold', 'Spend ' + compact(tier.thresholdText)), node('strong', '', compact(tier.rewardText)));
        }
        else item.append(node('strong', '', tier.text));
        list.append(item);
      });
      offers.append(list);
    }
    const selectedLabel = () => [...root.querySelectorAll('.variations select')].map(select => select.value ? select.selectedOptions[0]?.textContent || '' : '').filter(Boolean).join(' / ');
    const publicPriceText = () => summary.querySelector('.vc-price-current .woocommerce-Price-amount')?.textContent || summary.querySelector('.price .woocommerce-Price-amount')?.textContent || '';
    const context = () => ({ product: productName, specification: selectedLabel(), price: publicPriceText(), sku: root.vcHomeSku ?? defaults.sku ?? '' });
    const metaStrip = window.createVcProductMetaStrip?.({root,summary,form,signal,selectedLabel,productId,unitPricing:defaults.unitPricing});
    restore.move(root.querySelector('.vc-dialog-sticky'),null);
    const mainBuy = root.querySelector('[data-vc-quick-buy]');
    const purchaseDock = window.createVcProductPurchaseDock?.({root,dialog,form,mainAdd,mainBuy,selectedLabel,priceNode:summary.querySelector(':scope > .price'),enabled:settings.stickyPurchaseEnabled,signal});
    const purchaseReturn = window.createVcConfirmedPurchaseReturn?.({dialog,signal,
      isCurrent: ticket => !destroyed && dialog.open && root.isConnected && Number(root.dataset.vcProductId) === ticket.productId && root.vcHomeOpener === ticket.opener
        && ![...document.querySelectorAll('dialog[open]')].some(layer => layer !== dialog),
      close: () => { if (toolbarClose?.isConnected && dialog.open) toolbarClose.click(); }
    });
    // Buy remains the plugin's real checkout action. Never let a previous Add
    // success close this view while the customer starts a different action.
    mainBuy?.addEventListener('click', () => purchaseReturn?.cancel(), {capture:true,signal});
    // An explicit new interaction takes ownership from the short success beat.
    // Pointerdown precedes the original Add submit, which starts a fresh ticket.
    dialog.addEventListener('pointerdown', () => purchaseReturn?.cancel(), {capture:true,signal});
    toolbar?.addEventListener('click', () => purchaseReturn?.cancel(), {capture:true,signal});
    form?.addEventListener('change', () => purchaseReturn?.cancel(), {signal});
    // The plugin owns submitting, locking, failure/recovery and confirmed 201.
    // This listener only decorates that existing success, never guesses from cart totals.
    const feedbackConfig = window.vcLiveCommerce?.cartFeedback?.config || {};
    const nativeFeedback = window.createVcNativeProductFeedback?.({buttons:[mainAdd],bagCountElements:[...document.querySelectorAll('.vc-cart-count')],config:feedbackConfig});
    const feedbackURL = safeURL(window.vcLiveCommerce?.cartFeedback?.url);
    if (feedbackURL) fetch(feedbackURL,{credentials:'same-origin',cache:'no-store',signal})
      .then(response=>response.ok?response.json():null).then(config=>{if(!destroyed && config)nativeFeedback?.configure(config);}).catch(()=>{});
    const feedbackNamespace = '.vcProductFeedback' + productId;
    let pendingFeedback = null;
    form?.addEventListener('submit',event=>{
      const trigger = form.vcSubmitTrigger || event.submitter || mainAdd; delete form.vcSubmitTrigger;
      if (destroyed || !dialog.open || !mainAdd || mainAdd.disabled || form.inert || mainAdd.classList.contains('disabled')) return;
      pendingFeedback = {trigger,opener:root.vcHomeOpener,started:false,confirmed:false,stale:false,returnTicket:purchaseReturn?.begin({mode:'add',opener:root.vcHomeOpener,productId})};
      const submitted=pendingFeedback;
      Promise.resolve().then(()=>{
        // Native validation runs first. Decorate only a request it actually locks.
        if(!destroyed && pendingFeedback===submitted && form.getAttribute('aria-busy')==='true' && nativeFeedback?.state!=='pending')nativeFeedback?.begin(trigger);
      });
    },{capture:true,signal});
    document.addEventListener('vc:cart-mutation-start',event=>{
      if (pendingFeedback && event.detail?.source === 'showcase' && dialog.open && (form.inert || form.getAttribute('aria-busy') === 'true')) {
        if(!pendingFeedback.started && nativeFeedback?.state!=='pending')nativeFeedback?.begin(pendingFeedback.trigger);
        pendingFeedback.started = true;
      } else if(pendingFeedback?.started && ['drawer','live-cart'].includes(event.detail?.source)) {
        pendingFeedback.stale=true; nativeFeedback?.cancel(); purchaseReturn?.cancel();
      }
    },{signal});
    document.addEventListener('vc:cart-updated',event=>{
      const detail=event.detail;
      if(!pendingFeedback?.started || detail?.source!=='showcase' || detail.mutation!==true)return;
      if(detail.stale===true) { pendingFeedback.stale=true;nativeFeedback?.cancel();purchaseReturn?.cancel(); }
      else if(detail.stale===false && Array.isArray(detail.cart?.items) && detail.cart?.totals) pendingFeedback.confirmed=true;
    },{signal});
    window.jQuery?.(document.body).on('added_to_cart' + feedbackNamespace,(_,fragments,hash,$trigger)=>{
      const pending = pendingFeedback;
      if (!pending?.started || destroyed || !dialog.open || !root.isConnected || $trigger?.[0] !== pending.opener) return;
      pendingFeedback = null;
      if(pending.confirmed && !pending.stale) {
        nativeFeedback?.confirm();
        purchaseReturn?.confirm(pending.returnTicket,{confirmed:true,stale:false,presentation:nativeFeedback?.presentation});
      } else { nativeFeedback?.cancel(); purchaseReturn?.cancel(); }
    });
    function syncSticky() {
      if (destroyed) return;
      purchaseDock?.update();
      metaStrip?.update();
      if(pendingFeedback && nativeFeedback?.state==='pending' && form?.getAttribute('aria-busy')!=='true' && !pendingFeedback.confirmed) {
        pendingFeedback=null;nativeFeedback?.cancel();purchaseReturn?.cancel();
      }
      nativeFeedback?.sync();
    }
    const formObserver = new MutationObserver(() => { cancelAnimationFrame(frame); frame = requestAnimationFrame(syncSticky); });
    if (form) formObserver.observe(form, {subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ['disabled','inert','class','aria-busy']});
    const priceNode = summary.querySelector(':scope > .price'); if (priceNode) formObserver.observe(priceNode, {subtree: true, childList: true, characterData: true});
    function renderProduct() {
      const url = safeURL(productImage?.src); productImg.hidden = !url; emptyImage.hidden = !!url;
      productSlide.disabled = !url || root.vcHomeSelectionPending === true;
      productSlide.setAttribute('aria-busy', root.vcHomeSelectionPending ? 'true' : 'false');
      // Preserve the responsive candidates and intrinsic size supplied by WordPress.
      // Compare before writing: repeated native variation refreshes must not restart selection.
      const attributes = {sizes:productImage?.sizes,srcset:productImage?.srcset,width:productImage?.width,height:productImage?.height};
      for (const [key, raw] of Object.entries(attributes)) {
        const value = key === 'width' || key === 'height' ? (Number.isSafeInteger(Number(raw)) && Number(raw) > 0 ? String(Number(raw)) : '') : (typeof raw === 'string' ? raw : '');
        if (value) { if (productImg.getAttribute(key) !== value) productImg.setAttribute(key, value); }
        else if (productImg.hasAttribute(key)) productImg.removeAttribute(key);
      }
      if (url) { knownImages.set(url,{src:url,alt:productImage.alt || productName}); productImg.decoding = 'async'; productImg.alt = productImage.alt || productName; if (productImg.getAttribute('src') !== url) productImg.src = url; }
      // Only the accepted price owns this ribbon. Woo may still have the old
      // single_variation template mounted elsewhere inside the same summary.
      const text = !root.vcHomeSelectionPending ? summary.querySelector(':scope > .price .vc-sale-badge')?.textContent || '' : '';
      ribbon.textContent = text.toUpperCase(); ribbon.hidden = !/^\s*\d+(?:[.,]\d+)?%\s*(?:off|dto\.)\s*$/i.test(text);
      caption.textContent = root.vcHomeSelectionPending ? 'Updating specification…' : window.vcProductSpecification?.format(selectedLabel()) || selectedLabel(); caption.hidden = !caption.textContent;
      gallery.refresh();
    }
    function renderReport() {
      report = settings.reportGalleryEnabled && !root.vcHomeSelectionPending ? latestReport(reports, productId, selectedLabel()) : null;
      const signature = reportPresentationKey(report, selectedLabel(), root.querySelector('input.variation_id')?.value || '');
      if (signature === reportSignature) return;
      reportSignature = signature;
      reportTab.hidden = documents.hidden = !report;
      reportSlide.replaceChildren(); reportSlide.hidden = !report || !safeURL(report.thumbnail_url);
      documents.replaceChildren();
      navigation?.refresh();
      gallery.refresh();
      if (!report) return;
      const name = report.source_product || productName;
      const titleText = name + (report.specification ? ' · ' + report.specification : '');
      if (!reportSlide.hidden) {
        const img = node('img', ''); img.src = safeURL(report.thumbnail_url); img.alt = titleText + ' · Reference COA'; img.loading = 'lazy';
        img.addEventListener('error',()=>{reportSlide.hidden=true;track.scrollLeft=0;gallery.refresh();},{once:true});
        reportSlide.append(img, node('span', 'vc-dialog-report-label', 'REFERENCE DOCUMENT'));
        reportSlide.setAttribute('aria-label', 'Open ' + titleText + ' reference report');
      }
      const copy = node('div', 'vc-dialog-document-copy');
      copy.append(node('p', 'vc-home-quick__eyebrow', 'Certificate of analysis'), node('h2', '', 'The report. In full view.'), node('strong', '', titleText));
      const metadata = [report.issuer_name, date(report.issued_on)].filter(Boolean).join(' · '); if (metadata) copy.append(node('p', '', metadata));
      copy.append(node('p', 'vc-dialog-reference-note', 'Third-party reference report. Results apply to the sample named in the document, not a verified VialCove stock batch.'));
      copy.append(button('vc-dialog-report-open', 'View reference COA ↗', openReport)); documents.append(copy);
      if (safeURL(report.thumbnail_url)) { const art = button('vc-dialog-document-art', '', openReport); const img = node('img', ''); img.src = safeURL(report.thumbnail_url); img.alt = titleText; img.loading = 'lazy'; img.addEventListener('error',()=>art.remove(),{once:true}); art.append(img); documents.prepend(art); }
    }
    async function readReports({resume = false, requested = ''} = {}) {
      if (!settings.reportGalleryEnabled || destroyed || reportRequest || document.hidden || !dialog.open || root.vcHomeSelectionPending) return;
      if (resume && reportCursor.page >= reportCursor.pages) return;
      const base = safeURL(window.vcHomeQuickView?.reportApi); if (!base) return;
      const route = value => { const url = new URL(value); return (url.searchParams.get('rest_route') || url.pathname).replace(/\/$/,''); };
      const configured = safeURL(window.vcProductReports?.reportLibraryBase);
      const publicCollection = /^\/(?:wp-json\/)?peptide\/v1\/report-library$/.test(route(base)) || !!configured && route(base) === route(configured);
      const abort = new AbortController(); reportRequest = abort;
      const timer = setTimeout(() => abort.abort(), 15000);
      try {
        const items = resume ? [...reports] : []; let pages = resume ? reportCursor.pages : 1, total = resume ? reportCursor.total : null;
        for (let page = resume ? reportCursor.page + 1 : 1; page <= pages; page++) {
          const url = new URL(base); Object.entries({product_id: productId, sort: 'newest', per_page: 24, page}).forEach(([key,value]) => url.searchParams.set(key,String(value)));
          // WordPress rejects stale nonces before a public REST callback runs.
          // Only the exact public collection omits it; other resources retain it.
          const response = await fetch(url.href, {credentials:'same-origin',cache:'no-store',signal:abort.signal,headers: !publicCollection && window.vcHomeQuickView.wpNonce ? {'X-WP-Nonce':window.vcHomeQuickView.wpNonce} : {}});
          if (!response.ok) throw new Error('Report unavailable'); const data = await response.json();
          if (!Array.isArray(data.items) || data.items.length > 24 || !Number.isSafeInteger(data.total) || data.total < 0 || !Number.isSafeInteger(data.total_pages) || data.total_pages < 0 || data.total_pages > 100 || data.page !== page || (total !== null && total !== data.total)) throw new Error('Invalid report response');
          pages = Math.max(1, data.total_pages); total = data.total; items.push(...data.items);
          if (items.length > total || (page === pages && items.length !== total)) throw new Error('Invalid report count');
          if (destroyed || abort.signal.aborted) return;
          // Publish the newest page immediately. The global newest/date/id order
          // makes its first matching specification authoritative; older history
          // is only read if a different selection or a restored URL needs it.
          reports = [...items]; reportCursor = {page,pages,total}; lastReportRead = Date.now(); renderReport();
          const enough = requested ? reports.some(item => safeURL(item.view_url) === requested) : latestReport(reports,productId,selectedLabel());
          if (enough || !selectedLabel() || root.vcHomeSelectionPending || document.hidden || !dialog.open) break;
        }
      } catch (_) { /* A transient read failure cannot manufacture a report or block buying. */ }
      finally {
        clearTimeout(timer); if (reportRequest === abort) reportRequest = null;
      }
    }
    function openImage(requestedURL) {
      if (destroyed || !dialog.open) return false;
      const url = typeof requestedURL === 'string' && requestedURL ? safeURL(requestedURL) : safeURL(productImage?.src);
      const image = knownImages.get(url); if (!image) return false;
      const box = children.create('vc-product-image-dialog', 'Product image'); const img = node('img', ''); img.src = url; img.alt = image.alt || productName;
      box.append(img); box.dataset.vcHistoryImageUrl = url; box.showModal(); gallery.refresh();
      return true;
    }
    async function openReport(requestedURL) {
      if (destroyed || !dialog.open) return false;
      const intent = ++reportOpenIntent;
      const requested = typeof requestedURL === 'string' && requestedURL ? safeURL(requestedURL) : '';
      if (typeof requestedURL === 'string' && requestedURL && !requested) return false;
      if (!report || (requested && !reports.some(item=>safeURL(item.view_url)===requested))) {
        if (reportRequest) await reportReadPromise;
        if (!reportRequest && (!report || (requested && !reports.some(item=>safeURL(item.view_url)===requested)))) reportReadPromise=readReports({resume:reportCursor.page>0,requested});
        await reportReadPromise;
      }
      if (intent !== reportOpenIntent || destroyed || !dialog.open) return false;
      // Forward may restore a report viewed before a different specification was
      // chosen. Restore only that exact, still-public document for this product.
      const opened = requested ? reports.find(item=>Number(item.product?.id)===productId && item.file?.mime==='application/pdf' && safeURL(item.view_url)===requested) : report;
      if (!opened) return false;
      const box = children.create('vc-product-report-dialog', 'Reference COA');
      const titleText = (opened.source_product || productName) + ' ' + (opened.specification || '') + ' · Reference COA';
      box.append(node('p', 'vc-home-quick__eyebrow', 'Third-party reference'), node('h2', '', titleText), node('p', 'vc-dialog-reference-note', 'Results apply to the sample named in the report. Not a verified VialCove stock batch certificate.'));
      const pdf = node('div', 'vc-product-pdf');
      pdf.innerHTML = '<div class="pdf-toolbar"><p class="pdf-status" role="status"></p><div class="pdf-zoom"><button type="button" data-pdf-zoom="out" aria-label="Zoom out">−</button><span class="pdf-zoom-value">100%</span><button type="button" data-pdf-zoom="in" aria-label="Zoom in">+</button></div></div><div class="pdf-pages"></div><button class="pdf-retry" type="button" hidden>Retry</button>';
      box.append(pdf);
      const link = node('a', 'vc-dialog-pdf-download', 'Download reference PDF ↓'); link.href = safeURL(opened.download_url) || safeURL(opened.view_url); box.append(link);
      box.dataset.vcHistoryReportUrl = safeURL(opened.view_url);
      box.showModal(); gallery.refresh(); const viewer = window.createProductReportViewer({element: pdf}); children.attach(box, viewer); viewer.open({pdf:safeURL(opened.view_url),title:titleText,sizeBytes:opened.file?.size_bytes});
      document.dispatchEvent(new CustomEvent('vc:product-report-opened',{detail:{dialog:box,url:safeURL(opened.view_url),productId}}));
      return true;
    }
    function configureTicker(config) {
      if (destroyed || !settings.broadcastEnabled || !config || !config.enabled || !config.items?.length) { ticker?.destroy(); ticker=null; broadcast.hidden=true; return; }
      if (!ticker) ticker = window.createAnnouncementTicker({element:broadcast,config,context:context(),scrollRoot:dialog,dialog});
      else if (tickerRevision !== config.revision) ticker.configure(config);
      tickerRevision = config.revision;
    }
    let tickerRead = false, tickerLastRead = 0;
    async function readTicker() {
      if (!settings.broadcastEnabled) return;
      const source = window.vcLiveCommerce?.broadcast; if (!source) return;
      if (tickerRead || destroyed || !dialog.open || document.hidden) return;
      const url = safeURL(source.url); if (!url) return;
      tickerRead = true;
      try {
        const response = await fetch(url,{credentials:'same-origin',cache:'no-store',signal});
        const config = response.ok ? await response.json() : null;
        if (!destroyed) configureTicker(config);
      } catch (_) { if (!destroyed) configureTicker(null); }
      finally { tickerRead = false; tickerLastRead = Date.now(); }
    }
    const tickerPoll = setInterval(()=>{if(Date.now()-tickerLastRead>=30000)readTicker();},30000);
    document.addEventListener('visibilitychange',()=>{if(!document.hidden)readTicker();},{signal});
    window.addEventListener('focus',()=>readTicker(),{signal});
    function refresh() {
      if (destroyed) return;
      renderProduct(); syncSticky(); renderOffers(); navigation?.refresh(); const selection = selectedLabel();
      if (selection !== lastSelection) { track.scrollLeft=0; lastSelection=selection; }
      // Pending and accepted variation events can share the same label. Restore
      // its report when acceptance changes; renderReport's signature avoids
      // rebuilding unchanged document DOM on ordinary refreshes.
      renderReport();
      // A restored form can be pending when the experience mounts, so its
      // initial guarded read never started. Acceptance must start page one as
      // well as resume a partially read collection.
      if (!report && !root.vcHomeSelectionPending && selection && !reportRequest && reportCursor.page < reportCursor.pages) reportReadPromise=readReports({resume:reportCursor.page>0});
      ticker?.updateContext(context());
      if (support && settings.whatsappEnabled) {
        const values=context();
        const message=String(settings.whatsappMessage || '').replace(/\{(product|specification|sku)\}/g,(_,key)=>String(values[key] || ''));
        support.href='https://wa.me/'+String(settings.whatsappNumber).replace(/\D/g,'')+(message?'?text='+encodeURIComponent(message):'');
      }
      document.dispatchEvent(new CustomEvent('vc:product-dialog-selection',{detail:{root,dialog,productId,variationId:Number(root.querySelector('input.variation_id')?.value || 0),context:context()}}));
    }
    let pointer = null, dragged = false;
    track.addEventListener('pointerdown', event => { if (event.isPrimary === false || event.button !== 0) return; pointer = {id:event.pointerId,x:event.clientX,y:event.clientY}; dragged=false; },{signal});
    track.addEventListener('pointermove', event => { if(pointer && event.pointerId===pointer.id){const x=Math.abs(event.clientX-pointer.x),y=Math.abs(event.clientY-pointer.y);if(x>12 && x>y)dragged=true;} },{signal});
    track.addEventListener('click', event => { const suppress=dragged; dragged=false; if(suppress && event.detail!==0){event.preventDefault();event.stopImmediatePropagation();} },{capture:true,signal});
    track.addEventListener('pointerup',event=>{if(pointer?.id===event.pointerId)pointer=null;},{signal});
    const cancelPointer=event=>{if(pointer?.id===event.pointerId){pointer=null;dragged=false;}};
    track.addEventListener('pointercancel',cancelPointer,{signal});track.addEventListener('lostpointercapture',cancelPointer,{signal});
    document.addEventListener('visibilitychange',()=>{if(document.hidden){pointer=null;dragged=false;}},{signal});
    track.addEventListener('keydown',event=>{
      if(event.key!=='ArrowRight' && event.key!=='ArrowLeft')return;
      const slides=[productSlide,reportSlide].filter(slide=>!slide.hidden && !slide.disabled);
      const current=slides.indexOf(document.activeElement);if(current<0)return;
      const target=slides[Math.max(0,Math.min(slides.length-1,current+(event.key==='ArrowRight'?1:-1)))];
      event.preventDefault();const left=target.getBoundingClientRect().left-track.getBoundingClientRect().left+track.scrollLeft;
      target.focus({preventScroll:true});track.scrollTo({left,behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'instant':'smooth'});
    },{signal});
    document.addEventListener('visibilitychange',()=>{if(!document.hidden && !reportRequest && Date.now()-lastReportRead>60000)reportReadPromise=readReports();},{signal});
    document.addEventListener('vc:product-restore-cancel',()=>{reportOpenIntent++;},{signal});
    const api = {
      update: refresh,
      openReport,
      openImage,
      updateMedia(image) { productImage=image; renderProduct(); },
      destroy(reason = 'destroy') { if(destroyed)return; destroyed=true; document.dispatchEvent(new CustomEvent('vc:product-experience-disposed',{detail:{root,dialog,reason}})); clearInterval(tickerPoll); gallery.destroy(); reportOpenIntent++; pendingFeedback=null; purchaseReturn?.destroy(); nativeFeedback?.destroy(); window.jQuery?.(document.body).off(feedbackNamespace); lifecycle.abort(); navigation?.destroy(); reportRequest?.abort(); formObserver.disconnect(); cancelAnimationFrame(frame); metaStrip?.destroy(); purchaseDock?.destroy(); ticker?.destroy(); children.destroy(); restore.destroy(); }
    };
    refresh(); if (!reportRequest) reportReadPromise=readReports(); readTicker(); return api;
  }
  const deviceOwners = new WeakMap();
  window.vcProductDeviceSettings = Object.freeze({resolve:deviceSettings});
  window.createVcProductExperience = function (root, dialog) {
    if (!root || !dialog || !dialog.contains(root)) return null;
    if (deviceOwners.has(root)) return deviceOwners.get(root);
    const config = json(root.dataset.vcExperience), defaults = json(root.dataset.vcHomeInitial);
    const query = matchMedia('(max-width: 820px)');
    let current = null, signature = '', destroyed = false;
    let image = root.vcHomeImage === undefined ? defaults.image : root.vcHomeImage;
    function reconcile() {
      if (destroyed) return false;
      const settings = deviceSettings(config,query.matches), next = JSON.stringify(settings);
      if (signature === next) return false;
      const focus = document.activeElement, hadFocus = dialog.contains(focus);
      current?.destroy('device'); current = null; signature = next;
      nativeMedia(root,image);
      if (settings.enabled) current = createExperience(root,dialog,settings);
      if (hadFocus && dialog.open) {
        const target = focus?.isConnected ? focus : dialog.querySelector('.vc-showcase-quick__close');
        target?.focus({preventScroll:true});
      }
      return true;
    }
    const changed = () => reconcile();
    if (query.addEventListener) query.addEventListener('change',changed); else query.addListener?.(changed);
    // A frozen document may miss its media-query change. Recheck the actual
    // viewport on return; an identical device signature preserves the owner.
    window.addEventListener('pageshow',changed);
    window.addEventListener('focus',changed);
    const api = {
      update() { if (!reconcile()) current?.update(); },
      openReport(...args) { return current?.openReport(...args) ?? false; },
      openImage(...args) { return current?.openImage(...args) ?? false; },
      updateMedia(next) { image=next; if (current) current.updateMedia(next); else nativeMedia(root,next); },
      destroy() {
        if (destroyed) return; destroyed=true;
        if (query.removeEventListener) query.removeEventListener('change',changed); else query.removeListener?.(changed);
        window.removeEventListener('pageshow',changed); window.removeEventListener('focus',changed);
        dialog.removeEventListener('close',closed); current?.destroy('destroy'); current=null;
        nativeMedia(root,image); deviceOwners.delete(root); if (root.vcExperience === api) root.vcExperience=null;
      }
    };
    const closed = () => api.destroy(); dialog.addEventListener('close',closed,{once:true});
    deviceOwners.set(root,api); reconcile(); return api;
  };
})();
