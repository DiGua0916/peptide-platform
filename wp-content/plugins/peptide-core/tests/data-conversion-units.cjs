/* Actual shell formatters + Conversion renderers; no copied display implementation. */
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
let passed = 0;
const check = (name, value) => { assert.ok(value, name); ++passed; };
const keys = ['visitors', 'viewed', 'carted', 'checkout', 'address', 'shipping', 'payment', 'paid'];
const metrics = ['initial', 'user', 'automatic', 'attempts', 'attempt_visits', 'attempt_orders', 'succeeded', 'failed', 'success_rate'];

function harness(suffix = '百分点') {
  const strings = new Proxy({percentagePoints:suffix, previousPeriod:'对比期'}, {get:(obj, key) => obj[key] ?? String(key)});
  const document = {readyState:'loading', getElementById:() => ({}), addEventListener() {}};
  const window = {
    pcData:{strings:{vs:'VS'}, range:{}, currency:{code:'USD'}},
    pcDataConversion:{strings},
    wp:{apiFetch() { throw Error('Display tests must not request data'); }, url:{}},
    localStorage:{getItem:() => null}
  };
  const context = vm.createContext({window, document, console});
  vm.runInContext(fs.readFileSync(path.join(__dirname, '../assets/data.js'), 'utf8'), context);
  const source = fs.readFileSync(path.join(__dirname, '../assets/data-conversion.js'), 'utf8');
  const seam = "\tPcData.register( 'conversion', render );";
  assert.equal(source.split(seam).length, 2, 'One actual register seam');
  vm.runInContext(source.replace(seam,
    '\twindow.__test030 = {overall:overall, checkoutSteps:checkoutSteps};\n' + seam), context);
  return {api:window.__test030, ctx:window.PcData};
}

function freeze(value) {
  if (value && typeof value === 'object') {
    Object.values(value).forEach(freeze);
    Object.freeze(value);
  }
  return value;
}

function unchanged(label, input, render) {
  const before = structuredClone(input);
  const html = render(freeze(input));
  assert.deepStrictEqual(input, before, label + ' input stays unchanged');
  ++passed;
  return html;
}

function tiles(html) {
  return [...html.matchAll(/<div class="pc-conv-tile"[\s\S]*?<\/div><\/div>/g)].map(match => match[0]);
}

function checkout(delta) {
  const current = Object.fromEntries(metrics.map(key => [key, key === 'success_rate' ? 55.25 : 3]));
  const previous = Object.fromEntries(metrics.map(key => [key, key === 'success_rate' ? 45.25 : 2]));
  const change = Object.fromEntries(metrics.map(key => [key, key === 'success_rate' ? delta : 50]));
  return {
    labels:{checkout_view:'Checkout visits'}, sources:{checkout_view:'client'},
    current:{checkout_view:3}, previous:{checkout_view:2}, change:{checkout_view:50},
    channels:[{method:'test', current, previous, change}]
  };
}

function channelCells(html) {
  const table = html.split('<table class="pc-conv-channel-table">')[1];
  assert.ok(table, 'Actual payment channel table');
  return [...table.matchAll(/<td>([\s\S]*?)<\/td>/g)].map(match => match[1]);
}

const h = harness();
check('Real shell keeps relative percent formatting', h.ctx.change(50) === '+50%');
const detailed = {
  visitor_funnel:{
    unit:'visitors',
    current:keys.map((key, index) => ({key, count:index === 7 ? 4 : 10, visitor_rate:index === 7 ? 40 : 100})),
    previous:keys.map((key, index) => ({key, count:index === 7 ? 2 : 8, visitor_rate:index === 7 ? 25 : 100})),
    change:{visitors:25, paid:100}
  }
};
let html = unchanged('Detailed overall', detailed, data => h.api.overall(data, h.ctx));
let rows = tiles(html);
check('Detailed overall retains all nine tiles', rows.length === 9);
check('40% versus 25% displays the existing +15 point delta', rows[0].includes('>+15 百分点</span>') && rows[0].includes('pc-conv-tile__change is-up'));
check('Detailed current and previous rates retain percent', rows[0].includes('pc-conv-tile__value">40%</div>') && rows[0].includes('>VS 25%</span>'));
check('Detailed count changes remain relative percent', rows[1].includes('>+100%</span>') && rows[2].includes('>+25%</span>'));
check('Detailed renderer does not append conversion to source change keys', !Object.hasOwn(detailed.visitor_funnel.change, 'conversion'));

const legacy = {overall:{current:{conversion:25, paid:3}, previous:{conversion:37.35, paid:2}, change:{conversion:-12.35, paid:50}}};
html = unchanged('Legacy overall', legacy, data => h.api.overall(data, h.ctx));
rows = tiles(html);
check('Legacy fallback retains its six tiles', rows.length === 6);
check('Legacy conversion delta uses negative points', rows[0].includes('>-12.35 百分点</span>') && rows[0].includes('pc-conv-tile__change is-down'));
check('Legacy current and previous rates retain percent', rows[0].includes('pc-conv-tile__value">25%</div>') && rows[0].includes('>VS 37.35%</span>'));
check('Legacy count delta remains +50%', rows[1].includes('>+50%</span>') && !rows[1].includes('百分点'));

const cases = [
  ['positive', 10, '+10 百分点', ' is-up'],
  ['negative', -10, '-10 百分点', ' is-down'],
  ['zero', 0, '0 百分点', ''],
  ['negative zero', -0, '0 百分点', ''],
  ['null', null, '-', ''],
  ['absent', undefined, '-', ''],
  ['round positive', 12.346, '+12.35 百分点', ' is-up'],
  ['round negative', -12.346, '-12.35 百分点', ' is-down'],
  ['trim zeros', 12.3, '+12.3 百分点', ' is-up'],
  ['rounded positive zero', 0.004, '0 百分点', ' is-up'],
  ['rounded negative zero', -0.004, '0 百分点', ' is-down'],
  ['NaN', NaN, '-', ''],
  ['positive infinity', Infinity, '-', ''],
  ['negative infinity', -Infinity, '-', ''],
  ['nonnumeric', 'broken', '-', '']
];
for (const [label, value, expected, direction] of cases) {
  const data = checkout(value);
  if (label === 'absent') delete data.channels[0].change.success_rate;
  html = unchanged('Channel ' + label, data, input => h.api.checkoutSteps(input, h.ctx));
  const cells = channelCells(html);
  check('Channel ' + label + ' has correct points and direction', cells[8].endsWith('<span class="pc-conv-change' + direction + '">' + expected + '</span>'));
  check('Channel ' + label + ' retains current and previous percent values', cells[8].startsWith('<strong>55.25%</strong><small>VS 45.25%</small>'));
}

html = h.api.checkoutSteps(freeze(checkout(10)), h.ctx);
const cells = channelCells(html);
check('All eight channel count metrics retain +50% and positive class', cells.slice(0, 8).every(cell => cell.includes('<span class="pc-conv-change is-up">+50%</span>') && !cell.includes('百分点')));
check('Observed visit changes retain relative +50%', html.includes('<td><span class="pc-conv-change is-up">+50%</span></td>'));
check('Comparison header uses the supplied Conversion label', html.includes('<th>对比期</th>'));
const observed = checkout(null);
observed.change.checkout_view = null;
html = unchanged('Null observed count', observed, input => h.api.checkoutSteps(input, h.ctx));
check('Null observed count stays absent rather than zero', html.includes('<td><span class="pc-conv-change">-</span></td>'));

for (const value of [0, null, undefined, NaN, Infinity, -Infinity]) {
  const data = {overall:{current:{conversion:40}, previous:{conversion:25}, change:{conversion:value}}};
  html = unchanged('Legacy malformed/zero ' + String(value), data, input => h.api.overall(input, h.ctx));
  const expected = value === 0 ? '0 百分点' : '-';
  check('Legacy zero/absent/nonfinite ' + String(value), tiles(html)[0].includes('<span class="pc-conv-tile__change">' + expected + '</span>'));
}

const unsafe = harness('百分点 <img src=x onerror="bad">&\'');
html = unsafe.api.checkoutSteps(freeze(checkout(10)), unsafe.ctx);
check('Localized suffix is escaped with the complete point value', channelCells(html)[8].includes('>+10 百分点 &lt;img src=x onerror=&quot;bad&quot;&gt;&amp;&#39;</span>') && !html.includes('<img'));
check('Zero output has neither plus sign nor negative zero', channelCells(h.api.checkoutSteps(freeze(checkout(-0)), h.ctx))[8].endsWith('>0 百分点</span>'));
console.log(JSON.stringify({passed, failed:0}));
