/* VialCove visitor cart — admin presentation only; inject an authenticated loader. */
(() => {
  'use strict';
  const defaults = {
    title: '购物车', visitor: '访客', view: '查看', countSuffix: '件', emptyButton: '空购物车',
    unknownButton: '未同步', expiredButton: '已过期', close: '关闭购物车', refresh: '刷新',
    loading: '正在读取购物车…', updating: '正在刷新…', emptyTitle: '购物车为空',
    emptyDescription: '这位访客当前没有购物车商品。', unknownTitle: '购物车尚未同步',
    unknownDescription: '收到这位访客的购物车数据后，会在这里显示。', expiredTitle: '购物车已过期',
    expiredDescription: '这个购物车会话已过期，暂时无法查看当前商品。', errorTitle: '暂时无法读取购物车',
    errorDescription: '请稍后点击刷新重试。', forbiddenTitle: '暂时没有查看权限',
    forbiddenDescription: '请确认登录和查看权限，再点击刷新。', timeoutTitle: '读取超时',
    timeoutDescription: '本次请求未及时响应，请点击刷新重试。', quantity: '数量', unitPrice: '单价',
    subtotal: '商品小计', subtotalNote: '以客户购物车中的实际金额为准', updated: '最后同步', timeUnknown: '同步时间未知', readOnly: '仅查看 · 无法在这里修改客户购物车',
    imageMissing: '暂无商品图片', locale: 'zh-CN'
  };
  const statuses = new Set(['ready', 'empty', 'unknown', 'expired']);
  const escape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const idOf = value => {
    if (typeof value !== 'number' && typeof value !== 'string') return null;
    if (!/^\d+$/.test(String(value))) return null;
    const id = Number(value);
    return Number.isSafeInteger(id) && id > 0 ? id : null;
  };
  const stringsFor = value => Object.assign({}, defaults, value || {});
  function buttonHTML(visitor, summary, strings) {
    const s = stringsFor(strings);
    const id = idOf(visitor?.id);
    if (!id) return '';
    const label = typeof visitor.label === 'string' && visitor.label ? visitor.label : `${s.visitor} #${id}`;
    const status = statuses.has(summary?.status) ? summary.status : 'unknown';
    const count = Number(summary?.item_count);
    const caption = status === 'ready' ? `${s.view}${Number.isFinite(count) && count >= 0 ? ` · ${count} ${s.countSuffix}` : ''}`
      : status === 'empty' ? s.emptyButton : status === 'expired' ? s.expiredButton : s.unknownButton;
    return `<button type="button" class="button pc-cart-trigger" data-pc-cart-visitor="${id}" data-pc-cart-label="${escape(label)}" aria-haspopup="dialog" aria-label="${escape(`${label} · ${s.title} · ${caption}`)}">${escape(caption)}</button>`;
  }
  function safeURL(value) {
    if (typeof value !== 'string' || !value.trim()) return null;
    try {
      const url = new URL(value, document.baseURI);
      return ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password ? url.href : null;
    } catch (_) { return null; }
  }
  function validate(data, id) {
    if (!data || typeof data !== 'object' || idOf(data.visitor_id) !== id || !statuses.has(data.status) || !Array.isArray(data.items)
      || typeof data.item_count !== 'number' || !Number.isFinite(data.item_count) || data.item_count < 0) throw new Error('Invalid cart response');
    if (data.status === 'ready') {
      if (!data.items.length || data.item_count <= 0 || typeof data.subtotal_text !== 'string') throw new Error('Invalid cart contents');
      for (const item of data.items) {
        if (!item || typeof item.name !== 'string' || !item.name.trim() || !Array.isArray(item.specifications)
          || typeof item.quantity !== 'number' || !Number.isFinite(item.quantity) || item.quantity <= 0
          || typeof item.unit_price_text !== 'string' || typeof item.line_total_text !== 'string'
          || item.specifications.some(spec => !spec || typeof spec.label !== 'string' || typeof spec.value !== 'string')) {
          throw new Error('Invalid cart item');
        }
      }
    } else if (data.items.length || (data.status === 'empty' && data.item_count !== 0)) {
      throw new Error('Invalid cart status');
    }
    return data;
  }
  function mount(options) {
    const root = options?.root;
    if (!root || typeof root.addEventListener !== 'function' || typeof options.loadCart !== 'function') throw new TypeError('root and loadCart are required');
    const s = stringsFor(options.strings);
    const refreshMs = Number.isFinite(options.refreshMs) ? Math.max(1000, options.refreshMs) : 5000;
    const uid = `pc-visitor-cart-${Math.random().toString(36).slice(2)}`;
    const dialog = document.createElement('dialog');
    dialog.className = 'pc-cart-dialog';
    dialog.setAttribute('aria-labelledby', `${uid}-title`);
    dialog.innerHTML = `<div class="pc-cart-panel"><header class="pc-cart-header"><div><p class="pc-cart-eyebrow"></p><h2 id="${uid}-title"></h2></div><div class="pc-cart-actions"><button type="button" class="button pc-cart-refresh"></button><button type="button" class="pc-cart-close"><span aria-hidden="true">×</span></button></div></header><div class="pc-cart-body"></div><footer class="pc-cart-footer"><span class="pc-cart-readonly"></span><span class="pc-cart-updated"></span></footer><span class="pc-cart-announcement screen-reader-text" role="status" aria-live="polite"></span></div>`;
    const body = dialog.querySelector('.pc-cart-body');
    const title = dialog.querySelector('h2');
    const eyebrow = dialog.querySelector('.pc-cart-eyebrow');
    const refreshButton = dialog.querySelector('.pc-cart-refresh');
    const closeButton = dialog.querySelector('.pc-cart-close');
    const updated = dialog.querySelector('.pc-cart-updated');
    const announce = dialog.querySelector('.pc-cart-announcement');
    eyebrow.textContent = s.title;
    refreshButton.textContent = s.refresh;
    closeButton.setAttribute('aria-label', s.close);
    dialog.querySelector('.pc-cart-readonly').textContent = s.readOnly;
    document.body.append(dialog);
    let formatter;
    try { formatter = new Intl.DateTimeFormat(s.locale, {timeZone: options.timeZone || 'UTC', year:'numeric', month:'2-digit', day:'2-digit', hour:'2-digit', minute:'2-digit', second:'2-digit', hourCycle:'h23'}); }
    catch (_) { formatter = new Intl.DateTimeFormat('zh-CN', {timeZone:'UTC', dateStyle:'short', timeStyle:'medium'}); }
    let visitor = null, active = null, timer = null, generation = 0, disposed = false, forbidden = false;
    let origin = null, scrollState = null, backdropDown = false;
    const el = (tag, className, text) => {
      const node = document.createElement(tag);
      if (className) node.className = className;
      if (text !== undefined) node.textContent = text;
      return node;
    };
    function stateView(heading, description, loading = false) {
      const box = el('div', 'pc-cart-state');
      const icon = el('span', loading ? 'pc-cart-spinner' : 'pc-cart-state-icon', loading ? '' : '▢');
      icon.setAttribute('aria-hidden','true');
      box.append(icon, el('h3', '', heading));
      if (description) box.append(el('p', '', description));
      body.replaceChildren(box);
    }
    function clearTimer() { if (timer !== null) clearTimeout(timer); timer = null; }
    function abortRequest() {
      generation++;
      if (active) active.controller.abort();
      active = null;
    }
    function schedule() {
      clearTimer();
      if (!disposed && visitor && dialog.open && !document.hidden && !forbidden && !active) timer = setTimeout(() => fetchCart(false), refreshMs);
    }
    function updateTime(value) {
      const date = typeof value === 'string' ? new Date(value) : null;
      updated.textContent = date && Number.isFinite(date.getTime()) ? `${s.updated} ${formatter.format(date)}` : s.timeUnknown;
      updated.title = formatter.resolvedOptions().timeZone;
    }
    function render(data) {
      updateTime(data.updated_at);
      if (data.status !== 'ready') {
        stateView(s[`${data.status}Title`], s[`${data.status}Description`]);
        return;
      }
      const list = el('ul', 'pc-cart-items');
      for (const item of data.items) {
        const row = el('li', 'pc-cart-item');
        const picture = el('div', 'pc-cart-picture');
        const imageURL = safeURL(item.image_url);
        function missing() { const fallback = el('span', 'pc-cart-picture-placeholder', '▧'); fallback.setAttribute('role','img'); fallback.setAttribute('aria-label',s.imageMissing); picture.replaceChildren(fallback); }
        if (imageURL) {
          const img = el('img');
          img.src = imageURL; img.alt = ''; img.loading = 'lazy'; img.decoding = 'async'; img.referrerPolicy = 'no-referrer';
          img.addEventListener('error', missing, {once:true});
          picture.append(img);
        } else missing();
        const info = el('div','pc-cart-item-info');
        const productURL = safeURL(item.product_url);
        const name = el(productURL ? 'a' : 'span', 'pc-cart-product-name', item.name);
        if (productURL) { name.href = productURL; name.target = '_blank'; name.rel = 'noopener noreferrer'; }
        info.append(name);
        if (item.specifications.length) {
          const specs = el('dl','pc-cart-specs');
          for (const spec of item.specifications) {
            const pair = el('div'); pair.append(el('dt','',spec.label),el('dd','',spec.value)); specs.append(pair);
          }
          info.append(specs);
        }
        const details = el('p','pc-cart-quantity');
        details.append(el('span','',`${s.quantity} ${item.quantity}`),el('span','',`${s.unitPrice} ${item.unit_price_text}`));
        info.append(details);
        row.append(picture,info,el('strong','pc-cart-line-price',item.line_total_text));
        list.append(row);
      }
      const subtotal = el('div','pc-cart-subtotal');
      subtotal.append(el('span','',s.subtotal),el('strong','',data.subtotal_text));
      const note = el('p', 'pc-cart-subtotal-note', typeof data.subtotal_note === 'string' && data.subtotal_note.trim() ? data.subtotal_note : s.subtotalNote);
      body.replaceChildren(list,subtotal,note);
    }
    async function fetchCart(manual) {
      clearTimer();
      if (disposed || !visitor || !dialog.open || active || (!manual && (document.hidden || forbidden))) return;
      if (manual) forbidden = false;
      const record = {controller:new AbortController(), generation:++generation, timedOut:false};
      active = record;
      const currentId = visitor.id;
      refreshButton.disabled = true;
      body.setAttribute('aria-busy','true');
      announce.textContent = s.updating;
      let timeout, abortListener;
      try {
        const guard = new Promise((_, reject) => {
          abortListener = () => reject(new DOMException('Cancelled','AbortError'));
          record.controller.signal.addEventListener('abort', abortListener, {once:true});
          timeout = setTimeout(() => { record.timedOut = true; record.controller.abort(); },10000);
        });
        const result = await Promise.race([Promise.resolve().then(() => options.loadCart(currentId,{signal:record.controller.signal})), guard]);
        if (disposed || !visitor || visitor.id !== currentId || generation !== record.generation || !dialog.open) return;
        const data = validate(result,currentId);
        render(data);
        announce.textContent = `${visitor.label} · ${s.title} · ${data.status === 'ready' ? `${data.item_count} ${s.countSuffix}` : s[`${data.status}Title`]}`;
      } catch (error) {
        if (disposed || !visitor || visitor.id !== currentId || generation !== record.generation || !dialog.open) return;
        forbidden = [401, 403].includes(Number(error?.status || error?.response?.status || error?.data?.status));
        const prefix = forbidden ? 'forbidden' : record.timedOut ? 'timeout' : 'error';
        stateView(s[`${prefix}Title`],s[`${prefix}Description`]);
        updated.textContent = '';
        announce.textContent = s[`${prefix}Title`];
      } finally {
        clearTimeout(timeout);
        record.controller.signal.removeEventListener('abort',abortListener);
        if (active === record) {
          active = null;
          refreshButton.disabled = false;
          body.removeAttribute('aria-busy');
          schedule();
        }
      }
    }
    function unlockScroll() {
      if (!scrollState) return;
      document.body.style.overflow = scrollState.overflow;
      document.body.style.paddingRight = scrollState.paddingRight;
      scrollState = null;
    }
    function finishClose() {
      if (!visitor) return;
      const closingId = visitor.id;
      visitor = null;
      clearTimer(); abortRequest(); unlockScroll();
      refreshButton.disabled = false;
      body.removeAttribute('aria-busy');
      announce.textContent = '';
      const replacement = root.querySelector(`[data-pc-cart-visitor="${closingId}"]`);
      const target = origin?.isConnected ? origin : replacement;
      if (target && typeof target.focus === 'function') target.focus({preventScroll:true});
      origin = null;
    }
    function close() {
      if (dialog.open) dialog.close();
      finishClose();
    }
    function open(value, trigger) {
      if (disposed) return;
      const id = idOf(typeof value === 'object' ? value.id : value);
      if (!id) throw new TypeError('A valid visitor ID is required');
      clearTimer(); abortRequest();
      visitor = {id, label:typeof value?.label === 'string' && value.label ? value.label : `${s.visitor} #${id}`};
      origin = trigger || root.querySelector(`[data-pc-cart-visitor="${id}"]`) || document.activeElement;
      forbidden = false;
      title.textContent = visitor.label;
      updated.textContent = '';
      stateView(s.loading,'',true);
      refreshButton.disabled = false;
      if (!dialog.open) {
        scrollState = {overflow:document.body.style.overflow,paddingRight:document.body.style.paddingRight};
        const scrollbar = window.innerWidth - document.documentElement.clientWidth;
        if (scrollbar > 0) document.body.style.paddingRight = `${parseFloat(getComputedStyle(document.body).paddingRight || '0') + scrollbar}px`;
        document.body.style.overflow = 'hidden';
        dialog.showModal();
      }
      closeButton.focus({preventScroll:true});
      return fetchCart(true);
    }
    function handleClick(event) {
      const trigger = event.target.closest?.('[data-pc-cart-visitor]');
      if (!trigger || !root.contains(trigger) || trigger.disabled) return;
      event.preventDefault();
      const id = idOf(trigger.dataset.pcCartVisitor);
      if (id) open({id,label:trigger.dataset.pcCartLabel},trigger);
    }
    function onVisibility() {
      clearTimer();
      if (!document.hidden && visitor && !forbidden) fetchCart(false);
    }
    function onPointerDown(event) { backdropDown = event.target === dialog; }
    function onBackdropClick(event) { if (backdropDown && event.target === dialog) close(); backdropDown = false; }
    function onCancel(event) { event.preventDefault(); close(); }
    function onDialogClose() { if (!dialog.open) finishClose(); }
    function onKeyDown(event) {
      if (event.key !== 'Tab' || !dialog.open) return;
      const focusable = Array.from(dialog.querySelectorAll('button, a[href], input, select, textarea, [tabindex]'))
        .filter(node => !node.disabled && node.tabIndex >= 0 && node.getClientRects().length && getComputedStyle(node).visibility !== 'hidden');
      if (!focusable.length) { event.preventDefault(); dialog.focus(); return; }
      const first = focusable[0], last = focusable[focusable.length - 1];
      const current = document.activeElement;
      if (event.shiftKey && (current === first || !focusable.includes(current))) {
        event.preventDefault(); last.focus();
      } else if (!event.shiftKey && (current === last || !focusable.includes(current))) {
        event.preventDefault(); first.focus();
      }
    }
    const refresh = () => fetchCart(true);
    root.addEventListener('click',handleClick);
    refreshButton.addEventListener('click',refresh);
    closeButton.addEventListener('click',close);
    dialog.addEventListener('cancel',onCancel);
    dialog.addEventListener('close',onDialogClose);
    dialog.addEventListener('pointerdown',onPointerDown);
    dialog.addEventListener('click',onBackdropClick);
    dialog.addEventListener('keydown',onKeyDown);
    document.addEventListener('visibilitychange',onVisibility);
    function dispose() {
      if (disposed) return;
      close(); disposed = true;
      clearTimer(); abortRequest(); unlockScroll();
      root.removeEventListener('click',handleClick);
      refreshButton.removeEventListener('click',refresh);
      closeButton.removeEventListener('click',close);
      dialog.removeEventListener('cancel',onCancel);
      dialog.removeEventListener('close',onDialogClose);
      dialog.removeEventListener('pointerdown',onPointerDown);
      dialog.removeEventListener('click',onBackdropClick);
      dialog.removeEventListener('keydown',onKeyDown);
      document.removeEventListener('visibilitychange',onVisibility);
      dialog.remove();
    }
    return Object.freeze({open,close,refresh,dispose});
  }
  window.PCVisitorCart = Object.freeze({mount,buttonHTML});
})();




