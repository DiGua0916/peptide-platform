(function (root, factory) {
  'use strict';
  const model = factory();
  if (typeof module === 'object' && module.exports) module.exports = model;
  if (root) root.VialCoveTickerModel = model;
})(typeof window !== 'undefined' ? window : null, function () {
  'use strict';

  const MAX_ITEMS = 1000;
  const MAX_TEXT_LENGTH = 500;
  const MAX_IMPORT_BYTES = 1024 * 1024;
  const TOKENS = new Set(['name', 'product', 'specification', 'price', 'sku']);
  const TIMINGS = {
    enterMs: [550, 150, 2000],
    holdMs: [3200, 1500, 15000],
    exitMs: [450, 150, 2000],
    gapMs: [200, 0, 2000]
  };

  function cleanText(value) {
    return value.normalize('NFC').replace(/\s+/gu, ' ').trim();
  }

  function normalizeName(value) {
    if (typeof value !== 'string') throw new TypeError('Name must be a string.');
    const name = value.normalize('NFC').trim();
    if ([...name].length > 120) throw new RangeError('Name may contain at most 120 characters.');
    return name;
  }

  function normalizeList(input, label) {
    if (!Array.isArray(input)) throw new TypeError(label + ' must be an array.');
    if (input.length > MAX_ITEMS) throw new RangeError(label + ' may contain at most 1000 entries.');
    const seen = new Set();
    const result = [];
    for (const value of input) {
      if (typeof value !== 'string') throw new TypeError(label + ' entries must be strings.');
      const text = cleanText(value);
      if ([...text].length > MAX_TEXT_LENGTH) throw new RangeError(label + ' entries may contain at most 500 characters.');
      if (!text) continue;
      const key = text.toLowerCase();
      if (seen.has(key)) continue;
      seen.add(key);
      result.push(text);
    }
    return result;
  }

  function normalizeConfig(input) {
    const source = input == null ? {} : input;
    if (typeof source !== 'object' || Array.isArray(source)) throw new TypeError('Configuration must be an object.');
    const config = {
      version: 1,
      enabled: source.enabled !== false,
      badgeEnabled: source.badgeEnabled !== false,
      name: normalizeName(source.name === undefined ? '' : source.name),
      items: normalizeList(source.items === undefined ? [] : source.items, 'Items'),
      keywords: normalizeList(source.keywords === undefined ? [] : source.keywords, 'Keywords')
    };
    for (const [key, [fallback, min, max]] of Object.entries(TIMINGS)) {
      const value = source[key];
      const number = typeof value === 'number' || (typeof value === 'string' && value.trim()) ? Number(value) : NaN;
      config[key] = Number.isFinite(number) ? Math.min(max, Math.max(min, Math.round(number))) : fallback;
    }
    return config;
  }

  function createSequence(items) {
    const list = normalizeList(items, 'Items');
    let cursor = list.length ? 0 : -1;
    return Object.freeze({
      current: function () { return cursor < 0 ? null : list[cursor]; },
      next: function () {
        if (cursor < 0) return null;
        cursor = (cursor + 1) % list.length;
        return list[cursor];
      },
      index: function () { return cursor; }
    });
  }

  function renderText(template, context) {
    if (typeof template !== 'string') throw new TypeError('Template must be a string.');
    const values = context && typeof context === 'object' ? context : {};
    return template.replace(/\{([^{}]+)\}/g, function (token, key) {
      return TOKENS.has(key) && Object.prototype.hasOwnProperty.call(values, key) && typeof values[key] === 'string'
        ? values[key]
        : token;
    });
  }

  function utf8Length(text) {
    if (typeof TextEncoder !== 'undefined') return new TextEncoder().encode(text).length;
    // Counts UTF-8 bytes without relying on Node APIs in the browser build.
    let length = 0;
    for (const character of text) {
      const point = character.codePointAt(0);
      length += point <= 0x7f ? 1 : point <= 0x7ff ? 2 : point <= 0xffff ? 3 : 4;
    }
    return length;
  }

  function parseCsv(text) {
    const rows = [];
    let row = [];
    let field = '';
    let quoted = false;
    let closedQuote = false;
    function pushField() {
      row.push(field);
      field = '';
      closedQuote = false;
    }
    function pushRow() {
      pushField();
      rows.push(row);
      row = [];
    }
    for (let index = 0; index < text.length; index += 1) {
      const char = text[index];
      if (quoted) {
        if (char === '"') {
          if (text[index + 1] === '"') { field += '"'; index += 1; }
          else { quoted = false; closedQuote = true; }
        } else field += char;
      } else if (char === ',') {
        pushField();
      } else if (char === '\r' || char === '\n') {
        if (char === '\r' && text[index + 1] === '\n') index += 1;
        pushRow();
      } else if (char === '"') {
        if (field !== '' || closedQuote) throw new SyntaxError('Malformed CSV: unexpected quote.');
        quoted = true;
      } else {
        if (closedQuote) throw new SyntaxError('Malformed CSV: characters after a closing quote.');
        field += char;
      }
    }
    if (quoted) throw new SyntaxError('Malformed CSV: unclosed quoted field.');
    if (field !== '' || row.length || closedQuote) pushRow();
    const nonblankRows = rows.filter(function (entry) { return entry.some(function (value) { return value.trim() !== ''; }); });
    if (!nonblankRows.length) throw new SyntaxError('CSV must include a text or content column.');
    const header = nonblankRows.shift().map(function (value) { return cleanText(value).toLowerCase(); });
    let column = header.indexOf('text');
    if (column < 0) column = header.indexOf('content');
    if (column < 0) throw new SyntaxError('CSV must include a text or content column.');
    const nameColumn = header.indexOf('name');
    const items = nonblankRows.map(function (entry, index) {
      if (entry.length !== header.length) throw new SyntaxError('Malformed CSV: inconsistent column count at data row ' + (index + 1) + '.');
      return nameColumn < 0 ? entry[column] : renderText(entry[column], { name: normalizeName(entry[nameColumn]) });
    });
    return { items: items, nameColumnApplied: nameColumn >= 0 };
  }

  function jsonItems(items) {
    if (!Array.isArray(items)) throw new TypeError('JSON items must be an array.');
    return items.map(function (item) {
      if (typeof item === 'string') return item;
      if (item && typeof item === 'object' && !Array.isArray(item) && typeof item.text === 'string') return item.text;
      throw new TypeError('JSON entries must be strings or objects with a string text property.');
    });
  }

  function parseImport(text, filename) {
    if (typeof text !== 'string') throw new TypeError('Import content must be text.');
    if (utf8Length(text) > MAX_IMPORT_BYTES) throw new RangeError('Import file must be 1 MiB or smaller.');
    const match = typeof filename === 'string' ? /\.([^.\\/]+)$/.exec(filename) : null;
    const extension = match ? match[1].toLowerCase() : '';
    if (!['txt', 'csv', 'json'].includes(extension)) throw new TypeError('Unsupported file type. Choose TXT, CSV, or JSON.');
    const content = text.replace(/^\uFEFF/, '');
    let items;
    let keywords;
    let name;
    let badgeEnabled;
    let nameColumnApplied = false;
    if (extension === 'txt') {
      items = content.split(/\r\n|\n|\r/).filter(function (line) { return line.trim() !== ''; });
    } else if (extension === 'csv') {
      const csv = parseCsv(content);
      items = csv.items;
      nameColumnApplied = csv.nameColumnApplied;
    } else {
      let data;
      try { data = JSON.parse(content); }
      catch (error) { throw new SyntaxError('Malformed JSON: ' + error.message); }
      if (Array.isArray(data)) items = jsonItems(data);
      else if (data && typeof data === 'object' && Array.isArray(data.items)) {
        items = jsonItems(data.items);
        if (data.keywords !== undefined) keywords = data.keywords;
        if (data.name !== undefined) name = normalizeName(data.name);
        if (data.badgeEnabled !== undefined) {
          if (typeof data.badgeEnabled !== 'boolean') throw new TypeError('Badge enabled must be a boolean.');
          badgeEnabled = data.badgeEnabled;
        }
      } else throw new TypeError('JSON must contain an array, or an object with an items array.');
    }
    const normalizedItems = normalizeList(items, 'Items');
    const sourceItemCount = items.filter(function (item) { return cleanText(item) !== ''; }).length;
    const result = {
      items: normalizedItems,
      sourceItemCount: sourceItemCount,
      duplicateItemCount: sourceItemCount - normalizedItems.length
    };
    if (keywords !== undefined) {
      result.keywords = normalizeList(keywords, 'Keywords');
      result.sourceKeywordCount = keywords.filter(function (keyword) { return cleanText(keyword) !== ''; }).length;
      result.duplicateKeywordCount = result.sourceKeywordCount - result.keywords.length;
    }
    if (name !== undefined) result.name = name;
    if (badgeEnabled !== undefined) result.badgeEnabled = badgeEnabled;
    if (nameColumnApplied) result.nameColumnApplied = true;
    return result;
  }

  return Object.freeze({ normalizeConfig: normalizeConfig, createSequence: createSequence, renderText: renderText, parseImport: parseImport });
});
