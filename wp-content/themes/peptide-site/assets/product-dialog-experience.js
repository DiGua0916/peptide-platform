/* Mobile presentation over native Woo forms. No cart, pricing or report records are written here. */
(function () {
  'use strict';
  const safeURL = value => {
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
  window.vcProductReportMatch = Object.freeze({ specification: spec, latest: latestReport });

  window.createVcProductExperience = function (root, dialog) {
    const settings = json(root.dataset.vcExperience), defaults = json(root.dataset.vcHomeInitial);
    if (!settings.enabled || !settings.mobileDialog) return null;
    if (settings.visibility?.[matchMedia('(max-width: 820px)').matches ? 'mobile' : 'desktop'] === false) return null;
    const summary = root.querySelector('.summary'), media = root.querySelector(':scope > .vc-showcase-quick__media');
    const form = root.querySelector('form.cart'), mainAdd = form?.querySelector('.single_add_to_cart_button');
    const productId = Number(root.dataset.vcProductId), productName = root.dataset.vcProductName || '';
    const lifecycle = new AbortController(), signal = lifecycle.signal;
    let destroyed = false, productImage = root.vcHomeImage === undefined ? defaults.image : root.vcHomeImage;
    let reports = [], report = null, ticker = null, tickerRevision = null, nested = null, viewer = null;
    let reportRequest = null, reportReadPromise = null, lastReportRead = 0, lastSelection = '', frame = 0;
    let reportOpenIntent = 0;
    const knownImages = root.vcDialogImages instanceof Map ? root.vcDialogImages : new Map(); root.vcDialogImages=knownImages;
    root.classList.add('vc-product-experience'); dialog.classList.add('vc-product-dialog');
    const heading = root.querySelector('.vc-dialog-heading') || node('header', 'vc-dialog-heading');
    if (!heading.isConnected) root.prepend(heading);
    const eyebrow = summary.querySelector('.vc-home-quick__eyebrow'); if (eyebrow) heading.append(eyebrow);
    const title = summary.querySelector('.vc-showcase-quick__title'); if (title) heading.append(title);
    let rating = heading.querySelector('.vc-dialog-rating');
    if (!rating && defaults.ratingHtml && defaults.ratingCount > 0) {
      rating = node('div', 'vc-dialog-rating'); rating.innerHTML = defaults.ratingHtml;
      rating.append(node('span', '', defaults.ratingCount + (defaults.ratingCount === 1 ? ' review' : ' reviews'))); heading.append(rating);
    }
    let broadcast = heading.querySelector('[data-vc-product-broadcast]');
    if (!broadcast) {
      broadcast = button('announcement', '', () => {}); broadcast.hidden = true; broadcast.dataset.vcProductBroadcast = '';
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
    root.querySelector('.vc-dialog-documents')?.remove();
    const documents = node('section', 'vc-dialog-documents'); documents.hidden = true;
    root.append(documents);
    const content = root.querySelector('.vc-product-dialog-content');
    if (content) content.querySelector('details[data-vc-dialog-tab="description"]')?.setAttribute('open', '');
    const toolbar = dialog.querySelector('.vc-home-quick__toolbar');
    const toolbarClose = toolbar?.querySelector('.vc-showcase-quick__close');
    const go = target => {
      if (!target) return;
      dialog.scrollTo({top: Math.max(0, target.getBoundingClientRect().top - dialog.getBoundingClientRect().top + dialog.scrollTop - 72), behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth'});
    };
    const reportTab = button('', 'COA', () => go(documents)); reportTab.hidden = true;
    if (toolbar) {
      const restoreCloseFocus = document.activeElement === toolbarClose;
      const overviewTab = button('is-active', 'Overview', () => go(heading));
      const detailsTab = button('', 'Details', () => go(content)); detailsTab.hidden = !content;
      const bag = node('a', 'vc-dialog-bag cart-link', 'Bag'); bag.href = safeURL(window.vcHomeQuickView?.cartUrl) || '#';
      toolbar.replaceChildren(overviewTab, detailsTab, reportTab, bag);
      if (toolbarClose) toolbar.append(toolbarClose);
      if (restoreCloseFocus) toolbarClose.focus({preventScroll:true});
    }
    let support = summary.querySelector('.vc-dialog-support');
    if (!support && settings.whatsappEnabled && /^\d{7,15}$/.test(String(settings.whatsappNumber || '').replace(/\D/g, ''))) {
      support = node('a', 'vc-dialog-support', settings.whatsappLabel || 'A little help');
      const message = String(settings.whatsappMessage || '').replace(/\{product\}/g, productName);
      support.href = 'https://wa.me/' + String(settings.whatsappNumber).replace(/\D/g, '') + (message ? '?text=' + encodeURIComponent(message) : '');
      support.target = '_blank'; support.rel = 'noopener noreferrer'; summary.append(support);
    }
    root.querySelector('.vc-dialog-offers')?.remove();
    const offers = node('section', 'vc-dialog-offers'); offers.hidden = true; heading.prepend(offers);
    let offerSignature = '';
    function renderOffers() {
      const candidates = root.vcHomePromotions === undefined ? defaults.promotions : root.vcHomePromotions;
      const active = Array.isArray(candidates) ? candidates.find(item => item.status === 'active' && Array.isArray(item.tiers) && item.tiers.length
        && (!item.ends || Date.parse(item.ends) > Date.now()) && (!item.starts || Date.parse(item.starts) <= Date.now())) : null;
      const signature = JSON.stringify(active || null); if (signature === offerSignature) return;
      offerSignature = signature; offers.replaceChildren(); offers.hidden = !active;
      if (!active) return;
      const title = node('h2', '', 'Order savings');
      offers.append(title);
      const list = node('ul', 'vc-dialog-offer-tiers');
      active.tiers.forEach(tier => {
        const item = node('li', '');
        if (active.type === 'spend_amount' && !active.unlimited) item.append(node('strong', '', tier.rewardText), node('span', '', 'Spend ' + tier.thresholdText));
        else item.append(node('strong', '', tier.text));
        list.append(item);
      });
      offers.append(list);
      const details = node('details', 'vc-dialog-offer-details'); details.append(node('summary', '', 'Offer details'));
      const rules = node('div', '');
      if (active.text) rules.append(node('p', '', active.text));
      if (active.type === 'spend_amount' && !active.unlimited) rules.append(node('p', '', 'The highest qualifying tier applies to eligible merchandise before coupons, tax and shipping.'));
      rules.append(node('p', '', active.exclude_sale ? 'Sale items are excluded.' : 'Sale items are included.'));
      rules.append(node('p', '', active.with_coupons ? 'Eligible coupons may be combined. Coupon restrictions still apply.' : 'Cannot be combined with coupon codes.'));
      if (active.ends_text) rules.append(node('p', '', 'Offer ends: ' + active.ends_text));
      rules.append(node('p', '', 'Final savings are confirmed in your shopping bag and at checkout.'));
      details.append(rules); offers.append(details);
    }
    let sticky = root.querySelector('.vc-dialog-sticky');
    if (sticky) sticky.remove();
    sticky = node('div', 'vc-dialog-sticky'); sticky.hidden = !settings.stickyPurchaseEnabled || !mainAdd;
    const stickyInfo = node('div', 'vc-dialog-sticky-info'), stickyPrice = node('strong', ''), stickySpec = node('span', '');
    const stickyAdd = button('button vc-dialog-sticky-add', 'Add to bag', () => {
      if (mainAdd && !stickyAdd.disabled && !form.inert) { form.vcSubmitTrigger = stickyAdd; form.requestSubmit(mainAdd); }
    });
    stickyInfo.append(stickyPrice, stickySpec); sticky.append(stickyInfo, stickyAdd); root.append(sticky);
    // The plugin owns submitting, locking, failure/recovery and confirmed 201.
    // This listener only decorates that existing success, never guesses from cart totals.
    const feedbackConfig = window.vcLiveCommerce?.cartFeedback?.config || {};
    const nativeFeedback = window.createVcNativeProductFeedback?.({buttons:[mainAdd,stickyAdd],bagCountElements:[...document.querySelectorAll('.vc-cart-count')],config:feedbackConfig});
    const feedbackURL = safeURL(window.vcLiveCommerce?.cartFeedback?.url);
    if (feedbackURL) fetch(feedbackURL,{credentials:'same-origin',cache:'no-store',signal})
      .then(response=>response.ok?response.json():null).then(config=>{if(!destroyed && config)nativeFeedback?.configure(config);}).catch(()=>{});
    const feedbackNamespace = '.vcProductFeedback' + productId;
    let pendingFeedback = null;
    form?.addEventListener('submit',event=>{
      const trigger = form.vcSubmitTrigger || event.submitter || mainAdd; delete form.vcSubmitTrigger;
      if (destroyed || !dialog.open || !mainAdd || mainAdd.disabled || form.inert || mainAdd.classList.contains('disabled')) return;
      pendingFeedback = {trigger,opener:root.vcHomeOpener,started:false,confirmed:false,stale:false};
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
        pendingFeedback.stale=true; nativeFeedback?.cancel();
      }
    },{signal});
    document.addEventListener('vc:cart-updated',event=>{
      const detail=event.detail;
      if(!pendingFeedback?.started || detail?.source!=='showcase' || detail.mutation!==true)return;
      if(detail.stale===true) { pendingFeedback.stale=true;nativeFeedback?.cancel(); }
      else if(detail.stale===false && Array.isArray(detail.cart?.items) && detail.cart?.totals) pendingFeedback.confirmed=true;
    },{signal});
    window.jQuery?.(document.body).on('added_to_cart' + feedbackNamespace,(_,fragments,hash,$trigger)=>{
      const pending = pendingFeedback;
      if (!pending?.started || destroyed || !dialog.open || !root.isConnected || $trigger?.[0] !== pending.opener) return;
      pendingFeedback = null;
      if(pending.confirmed && !pending.stale)nativeFeedback?.confirm();
      else nativeFeedback?.cancel();
    });
    const selectedLabel = () => [...root.querySelectorAll('.variations select')].map(select => select.value ? select.selectedOptions[0]?.textContent || '' : '').filter(Boolean).join(' / ');
    const publicPriceText = () => summary.querySelector('.vc-price-current .woocommerce-Price-amount')?.textContent || summary.querySelector('.price .woocommerce-Price-amount')?.textContent || '';
    const context = () => ({ product: productName, specification: selectedLabel(), price: publicPriceText(), sku: root.vcHomeSku ?? defaults.sku ?? '' });
    function syncSticky() {
      if (destroyed) return;
      stickyPrice.textContent = publicPriceText(); stickySpec.textContent = selectedLabel();
      stickyAdd.disabled = !mainAdd || mainAdd.disabled || mainAdd.classList.contains('disabled') || form.inert || form.getAttribute('aria-busy') === 'true';
      stickyAdd.setAttribute('aria-busy', String(form?.getAttribute('aria-busy') === 'true'));
      stickyAdd.textContent = mainAdd?.textContent.trim() || 'Add to bag';
      if(pendingFeedback && nativeFeedback?.state==='pending' && form?.getAttribute('aria-busy')!=='true' && !pendingFeedback.confirmed) {
        pendingFeedback=null;nativeFeedback?.cancel();
      }
      nativeFeedback?.sync();
    }
    const formObserver = new MutationObserver(() => { cancelAnimationFrame(frame); frame = requestAnimationFrame(syncSticky); });
    if (form) formObserver.observe(form, {subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ['disabled','inert','class','aria-busy']});
    const priceNode = summary.querySelector(':scope > .price'); if (priceNode) formObserver.observe(priceNode, {subtree: true, childList: true, characterData: true});
    function renderProduct() {
      const url = safeURL(productImage?.src); productImg.hidden = !url; emptyImage.hidden = !!url;
      productSlide.disabled = !url;
      if (url) { knownImages.set(url,{src:url,alt:productImage.alt || productName}); productImg.src = url; productImg.alt = productImage.alt || productName; productImg.decoding = 'async'; }
      const text = summary.querySelector('.vc-sale-badge')?.textContent || '';
      ribbon.textContent = text.toUpperCase(); ribbon.hidden = !/^\s*\d+(?:\.\d+)?%\s*off\s*$/i.test(text);
      caption.textContent = selectedLabel(); caption.hidden = !caption.textContent;
    }
    function renderReport() {
      report = settings.reportGalleryEnabled ? latestReport(reports, productId, selectedLabel()) : null;
      reportTab.hidden = documents.hidden = !report;
      reportSlide.replaceChildren(); reportSlide.hidden = !report || !safeURL(report.thumbnail_url);
      documents.replaceChildren();
      if (!report) return;
      const name = report.source_product || productName;
      const titleText = name + (report.specification ? ' · ' + report.specification : '');
      if (!reportSlide.hidden) {
        const img = node('img', ''); img.src = safeURL(report.thumbnail_url); img.alt = titleText + ' · Reference COA'; img.loading = 'lazy';
        img.addEventListener('error',()=>{reportSlide.hidden=true;track.scrollLeft=0;},{once:true});
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
    async function readReports() {
      if (!settings.reportGalleryEnabled || destroyed || reportRequest || document.hidden || !dialog.open) return;
      const base = safeURL(window.vcHomeQuickView?.reportApi); if (!base) return;
      const abort = new AbortController(); reportRequest = abort;
      const timer = setTimeout(() => abort.abort(), 15000);
      try {
        const items = []; let pages = 1, total = null;
        for (let page = 1; page <= pages; page++) {
          const url = new URL(base); Object.entries({product_id: productId, sort: 'newest', per_page: 24, page}).forEach(([key,value]) => url.searchParams.set(key,String(value)));
          const response = await fetch(url.href, {credentials:'same-origin',cache:'no-store',signal:abort.signal,headers: window.vcHomeQuickView.wpNonce ? {'X-WP-Nonce':window.vcHomeQuickView.wpNonce} : {}});
          if (!response.ok) throw new Error('Report unavailable'); const data = await response.json();
          if (!Array.isArray(data.items) || !Number.isSafeInteger(data.total_pages) || data.total_pages > 100 || data.page !== page || (total !== null && total !== data.total)) throw new Error('Invalid report response');
          pages = Math.max(1, data.total_pages); total = data.total; items.push(...data.items);
        }
        if (items.length !== total || destroyed) return;
        reports = items; lastReportRead = Date.now(); renderReport();
      } catch (_) { /* A transient read failure cannot manufacture a report or block buying. */ }
      finally {
        clearTimeout(timer); if (reportRequest === abort) reportRequest = null;
      }
    }
    function closeNested() { if (nested?.open) nested.close(); }
    function createNested(className, label) {
      closeNested(); nested?.remove(); viewer?.dispose(); viewer = null;
      nested = node('dialog', className + ' vc-product-child-dialog'); nested.setAttribute('aria-label', label);
      nested.dataset.vcProductParent = String(productId);
      const close = button('vc-product-child-close', '×', closeNested); close.setAttribute('aria-label', 'Close ' + label); nested.append(close);
      nested.addEventListener('click', event => { if (event.target === nested) closeNested(); });
      nested.addEventListener('close', () => { viewer?.dispose(); });
      document.body.append(nested); return nested;
    }
    function openImage(requestedURL) {
      if (destroyed || !dialog.open) return false;
      const url = typeof requestedURL === 'string' && requestedURL ? safeURL(requestedURL) : safeURL(productImage?.src);
      const image = knownImages.get(url); if (!image) return false;
      const box = createNested('vc-product-image-dialog', 'Product image'); const img = node('img', ''); img.src = url; img.alt = image.alt || productName;
      box.append(img); box.dataset.vcHistoryImageUrl = url; box.showModal();
      return true;
    }
    async function openReport(requestedURL) {
      if (destroyed || !dialog.open) return false;
      const intent = ++reportOpenIntent;
      const requested = typeof requestedURL === 'string' && requestedURL ? safeURL(requestedURL) : '';
      if (typeof requestedURL === 'string' && requestedURL && !requested) return false;
      if (!report || (requested && !reports.some(item=>safeURL(item.view_url)===requested))) {
        if (!reportRequest) reportReadPromise=readReports();
        await reportReadPromise;
      }
      if (intent !== reportOpenIntent || destroyed || !dialog.open) return false;
      // Forward may restore a report viewed before a different specification was
      // chosen. Restore only that exact, still-public document for this product.
      const opened = requested ? reports.find(item=>Number(item.product?.id)===productId && item.file?.mime==='application/pdf' && safeURL(item.view_url)===requested) : report;
      if (!opened) return false;
      const box = createNested('vc-product-report-dialog', 'Reference COA');
      const titleText = (opened.source_product || productName) + ' ' + (opened.specification || '') + ' · Reference COA';
      box.append(node('p', 'vc-home-quick__eyebrow', 'Third-party reference'), node('h2', '', titleText), node('p', 'vc-dialog-reference-note', 'Results apply to the sample named in the report. Not a verified VialCove stock batch certificate.'));
      const pdf = node('div', 'vc-product-pdf');
      pdf.innerHTML = '<div class="pdf-toolbar"><p class="pdf-status" role="status"></p><div class="pdf-zoom"><button type="button" data-pdf-zoom="out" aria-label="Zoom out">−</button><span class="pdf-zoom-value">100%</span><button type="button" data-pdf-zoom="in" aria-label="Zoom in">+</button></div></div><div class="pdf-pages"></div><button class="pdf-retry" type="button" hidden>Retry</button>';
      box.append(pdf);
      const link = node('a', 'vc-dialog-pdf-download', 'Download reference PDF ↓'); link.href = safeURL(opened.download_url) || safeURL(opened.view_url); box.append(link);
      box.dataset.vcHistoryReportUrl = safeURL(opened.view_url);
      box.showModal(); viewer = window.createProductReportViewer({element: pdf}); viewer.open({pdf:safeURL(opened.view_url),title:titleText});
      document.dispatchEvent(new CustomEvent('vc:product-report-opened',{detail:{dialog:box,url:safeURL(opened.view_url),productId}}));
      return true;
    }
    function configureTicker(config) {
      if (destroyed || !settings.broadcastEnabled || !config || !config.enabled || !config.items?.length) { ticker?.destroy(); ticker=null; broadcast.hidden=true; return; }
      if (!ticker) ticker = window.createAnnouncementTicker({element:broadcast,config,context:context(),scrollRoot:dialog,dialog});
      else if (tickerRevision !== config.revision) ticker.configure(config);
      tickerRevision = config.revision;
    }
    async function readTicker() {
      if (!settings.broadcastEnabled) return;
      const source = window.vcLiveCommerce?.broadcast; if (!source) return;
      configureTicker(source.config);
      const url = safeURL(source.url); if (!url) return;
      try { const response = await fetch(url,{credentials:'same-origin',cache:'no-store',signal}); if(response.ok) configureTicker(await response.json()); } catch (_) {}
    }
    function refresh() {
      if (destroyed) return;
      renderProduct(); syncSticky(); renderOffers(); const selection = selectedLabel();
      if (selection !== lastSelection) { track.scrollLeft=0; lastSelection=selection; renderReport(); }
      ticker?.updateContext(context());
      if (support && settings.whatsappEnabled) {
        const values=context();
        const message=String(settings.whatsappMessage || '').replace(/\{(product|specification|sku)\}/g,(_,key)=>String(values[key] || ''));
        support.href='https://wa.me/'+String(settings.whatsappNumber).replace(/\D/g,'')+(message?'?text='+encodeURIComponent(message):'');
      }
      document.dispatchEvent(new CustomEvent('vc:product-dialog-selection',{detail:{root,dialog,productId,variationId:Number(root.querySelector('input.variation_id')?.value || 0),context:context()}}));
    }
    let pointer = null, dragged = false;
    track.addEventListener('pointerdown', event => { pointer = {x:event.clientX,y:event.clientY}; dragged=false; },{signal});
    track.addEventListener('pointermove', event => { if(pointer && Math.abs(event.clientX-pointer.x)>12) dragged=true; },{signal});
    track.addEventListener('click', event => { if(dragged){event.preventDefault();event.stopImmediatePropagation();} },{capture:true,signal});
    track.addEventListener('pointerup',()=>{pointer=null;},{signal});
    track.addEventListener('keydown',event=>{if(event.key==='ArrowRight'||event.key==='ArrowLeft'){event.preventDefault();track.scrollBy({left:track.clientWidth*(event.key==='ArrowRight'?1:-1),behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'instant':'smooth'});}},{signal});
    document.addEventListener('visibilitychange',()=>{if(!document.hidden && !reportRequest && Date.now()-lastReportRead>60000)reportReadPromise=readReports();},{signal});
    document.addEventListener('vc:product-restore-cancel',()=>{reportOpenIntent++;},{signal});
    dialog.addEventListener('close',()=>api.destroy(),{once:true,signal});
    const api = {
      update: refresh,
      openReport,
      openImage,
      updateMedia(image) { productImage=image; renderProduct(); },
      destroy() { if(destroyed)return; destroyed=true; reportOpenIntent++; pendingFeedback=null; nativeFeedback?.destroy(); window.jQuery?.(document.body).off(feedbackNamespace); lifecycle.abort(); reportRequest?.abort(); formObserver.disconnect(); cancelAnimationFrame(frame); ticker?.destroy(); closeNested(); viewer?.dispose(); nested?.remove(); root.vcExperience=null; }
    };
    refresh(); reportReadPromise=readReports(); readTicker(); return api;
  };
})();
