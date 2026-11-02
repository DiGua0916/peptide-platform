/* Plain-text parameter sessions shared by merchant preview and the existing storefront ticker. */
(function (root, factory) {
  'use strict';
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root) {
    root.PCProductBroadcastParameters = api;
    if (root.VialCoveTickerModel) root.VialCoveTickerModel = api.install(root.VialCoveTickerModel);
  }
})(typeof window !== 'undefined' ? window : null, function () {
  'use strict';

  const nativeTokens = new Set(['product', 'specification', 'price', 'sku']);
  const sessions = new WeakMap();
  const frames = new WeakMap();
  const installed = new WeakMap();
  const tokenPattern = /\{([^{}]+)\}/gu;

  function text(value, limit = 500) {
    if (typeof value !== 'string') throw new TypeError('Parameter text must be a string.');
    if (/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]|<(?:\/?[a-z][^>]*>|!--|!doctype\b|\?)/iu.test(value)) {
      throw new TypeError('Only plain parameter text is supported.');
    }
    const result = value.normalize('NFC').replace(/\s+/gu, ' ').trim();
    if ([...result].length > limit) throw new RangeError('Parameter text is too long.');
    return result;
  }

  function list(input) {
    if (!Array.isArray(input) || input.length > 1000) throw new TypeError('A bounded text list is required.');
    return input.map(value => text(value)).filter(Boolean);
  }

  function contains(template, key) { return template.includes('{' + key + '}'); }

  /** Exactly one replacement pass: custom values and native context are literal insertions. */
  function render(record, context) {
    const values = context && typeof context === 'object' ? context : {};
    return record.template.replace(tokenPattern, (whole, key) => {
      if (record.draw.has(key)) return record.draw.get(key);
      return nativeTokens.has(key) && Object.prototype.hasOwnProperty.call(values, key) && typeof values[key] === 'string'
        ? values[key] : whole;
    });
  }

  function opaque(template, draw) {
    const frame = Object.freeze({});
    frames.set(frame, {template, draw});
    return frame;
  }

  /**
   * Random is independent sampling with replacement on each explicit step.
   * current(), keywords(), index(), count() and rerendering never redraw an existing frame.
   */
  function create(config, options = {}) {
    if (!config || typeof config !== 'object' || Array.isArray(config)) throw new TypeError('Configuration must be an object.');
    const mode = config.parameterMode === undefined ? 'random' : config.parameterMode;
    if (!['random', 'ordered'].includes(mode)) throw new TypeError('Unknown parameter selection mode.');
    const templates = list(config.templates === undefined ? (config.items || []) : config.templates);
    const keywords = list(config.keywordTemplates === undefined ? (config.keywords || []) : config.keywordTemplates);
    if (!Array.isArray(config.parameters) || config.parameters.length > 32) throw new TypeError('A bounded parameter table is required.');
    const columns = new Map();
    let total = 0;
    for (const entry of config.parameters) {
      if (!entry || typeof entry !== 'object' || Array.isArray(entry)) throw new TypeError('Invalid parameter column.');
      const key = text(entry.key, 42).replace(/^\{([^{}]+)\}$/u, '$1');
      if (!/^[\p{L}_][\p{L}\p{N}_-]{0,39}$/u.test(key) || ['__proto__', 'constructor', 'prototype'].includes(key.toLowerCase()) || columns.has(key)) {
        throw new TypeError('Invalid or duplicate parameter key.');
      }
      const unique = new Map();
      for (const value of list(entry.values)) if (!unique.has(value.toLowerCase())) unique.set(value.toLowerCase(), value);
      const values = [...unique.values()];
      if (!values.length || (total += values.length) > 1000) throw new RangeError('Invalid parameter value count.');
      columns.set(key, values);
    }
    const random = options.random === undefined ? Math.random : options.random;
    if (typeof random !== 'function') throw new TypeError('The random source must be a function.');
    let count = templates.length;
    if (mode === 'ordered' && count) {
      for (const [key, values] of columns) if (templates.some(template => contains(template, key))) count = Math.max(count, values.length);
    }
    let cursor = count ? 0 : -1;
    let cached = null;

    function drawAt(position) {
      if (position < 0) return null;
      const template = templates[position % templates.length];
      const draw = new Map();
      for (const [key, values] of columns) {
        if (!contains(template, key) && !keywords.some(keyword => contains(keyword, key))) continue;
        let selected = position % values.length;
        // A singleton has no random decision; it remains available on every frame.
        if (mode === 'random' && values.length > 1) {
          const sample = random();
          if (typeof sample !== 'number' || !Number.isFinite(sample) || sample < 0 || sample >= 1) throw new RangeError('Random values must be in [0, 1).');
          selected = Math.floor(sample * values.length);
        }
        draw.set(key, values[selected]);
      }
      return {message: opaque(template, draw), keywords: keywords.map(keyword => opaque(keyword, draw))};
    }

    function ensure() { if (!cached && cursor >= 0) cached = drawAt(cursor); return cached; }
    function current(context) { const frame = ensure(); return frame ? render(frames.get(frame.message), context) : null; }
    function step(direction, context) {
      if (cursor < 0) return null;
      const next = (cursor + direction + count) % count;
      const candidate = drawAt(next); // Failed validation/RNG never partially advances a session.
      cursor = next; cached = candidate;
      return current(context);
    }
    const api = Object.freeze({
      current,
      next: context => step(1, context),
      previous: context => step(-1, context),
      index: () => cursor,
      count: () => count,
      keywords: context => { const frame = ensure(); return frame ? frame.keywords.map(keyword => render(frames.get(keyword), context)) : []; }
    });
    sessions.set(api, {
      current: () => { const frame = ensure(); return frame ? frame.message : null; },
      // Measurement may ask for keywords before any visible frame exists. It must not draw.
      peekKeywords: () => cached ? cached.keywords.slice() : []
    });
    return api;
  }

  /** Extend the frozen public model without mutating the theme or replacing its controller. */
  function install(base) {
    if (!base || typeof base.normalizeConfig !== 'function' || typeof base.createSequence !== 'function' || typeof base.renderText !== 'function') {
      throw new TypeError('A compatible ticker model is required.');
    }
    if (installed.has(base)) return installed.get(base);
    const byItems = new WeakMap();
    const adapter = Object.freeze(Object.assign({}, base, {
      normalizeConfig(input) {
        const isRandom = input && input.parameterMode === 'random' && Array.isArray(input.parameters) && input.parameters.length > 0;
        // These strings are height probes only in the random session. The old controller
        // rejects a literal {name} in its legacy list before consulting our frame renderer.
        const fallback = isRandom && Array.isArray(input.items)
          ? Object.assign({}, input, {items: input.items.map(value => typeof value === 'string' ? value.replace(/\{name\}/g, '｛name｝') : value)})
          : input;
        const result = base.normalizeConfig(fallback);
        if (!isRandom) return result;
        const session = create(input);
        if (!session.count()) return result;
        byItems.set(result.items, session);
        Object.defineProperty(result, 'keywords', {enumerable: true, get: () => sessions.get(session).peekKeywords()});
        return result;
      },
      createSequence(items) {
        const session = byItems.get(items);
        if (!session) return base.createSequence(items);
        const privateSession = sessions.get(session);
        return Object.freeze({
          current: privateSession.current,
          next: () => { session.next(); return privateSession.current(); },
          index: session.index
        });
      },
      renderText(template, context) {
        const record = template && typeof template === 'object' ? frames.get(template) : null;
        return record ? render(record, context) : base.renderText(template, context);
      }
    }));
    installed.set(base, adapter);
    installed.set(adapter, adapter);
    return adapter;
  }

  return Object.freeze({create, install});
});
