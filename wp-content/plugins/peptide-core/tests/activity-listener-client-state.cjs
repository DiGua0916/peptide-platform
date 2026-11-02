'use strict';
// Independent behavioral checks of production exports; no DOM, Woo writes, or browser access.
// Latest user override: every genuinely changed, qualifying cart state celebrates its highest eligible tier;
// no once-per-tier lock. First load, rule-change baselines and duplicate stateKey remain silent.
const assert = require('node:assert/strict');
const { normalize, decision } = require('../assets/activity-listener.js');
const clone = value => JSON.parse(JSON.stringify(value));
const checks = [];
function check(name, callback) {
    try { callback(); checks.push({ name, pass: true }); }
    catch (error) { checks.push({ name, pass: false, error: error.message.split('\n')[0] }); }
}
function state(amount, options = {}) {
    const quantity = options.quantity === true;
    const thresholds = quantity ? [2, 4, 6] : [30000, 50000, 100000];
    const rule = options.rule || 'rule-1';
    const rewardTexts = quantity ? ['$5 off', '$12 off', '$20 off'] : ['$25 off', '$55 off', '$120 off'];
    const nodes = thresholds.map((threshold, index) => ({
        key: `9:${rule}:tier${index}:${threshold}`, index,
        threshold: quantity ? threshold : String(threshold),
        thresholdText: quantity ? `${threshold} items` : `$${(threshold / 100).toFixed(2)}`,
        rewardText: rewardTexts[index], reached: amount >= threshold,
        positionPercent: threshold / thresholds.at(-1) * 100,
        celebrationEligible: amount >= threshold && options.discount !== false && options.animation !== false
    }));
    const discountApplied = amount >= thresholds[0] && options.discount !== false;
    const nextNode = nodes.find(node => !node.reached);
    const next = nextNode ? { ...nextNode, remaining: quantity ? Number(nextNode.threshold) - amount : String(Number(nextNode.threshold) - amount), remainingText: quantity ? `${Number(nextNode.threshold) - amount} items` : `$${((Number(nextNode.threshold) - amount) / 100).toFixed(2)}` } : null;
    const completed = !next && discountApplied;
    return {
        version: 1, enabled: true, visible: true, revision: options.revision ?? 2,
        title: 'Your offer progress', promotionRevision: rule,
        stateKey: options.stateKey || `state-${rule}-${amount}-${discountApplied}-${options.animation !== false}`,
        status: discountApplied ? completed ? 'complete' : 'tier_reached' : amount >= thresholds[0] ? 'no_savings' : 'tracking',
        reason: '', currency: 'USD', currencyMinorUnit: 2,
        cartSubtotal: { minor: quantity ? '10000' : String(amount), text: '$100.00' },
        eligibleSubtotal: { minor: quantity ? '10000' : String(amount), text: '$100.00' },
        actualDiscount: { minor: discountApplied ? '2500' : '0', text: discountApplied ? '$25.00' : '$0.00' },
        basis: quantity ? 'quantity' : 'spend', measure: quantity ? amount : String(amount),
        measureText: quantity ? `${amount} items` : `$${(amount / 100).toFixed(2)}`,
        progressPercent: Math.min(100, amount / thresholds.at(-1) * 100), completed, discountApplied,
        next, nodes, achievedKeys: discountApplied ? nodes.filter(node => node.reached).map(node => node.key) : [],
        promotion: { id: 9, title: 'Real source contract fixture', type: quantity ? 'qty_amount' : 'spend_amount', status: 'active', url: 'https://example.invalid/offers/9/' },
        celebration: { enabled: options.animation !== false, smileEnabled: true }
    };
}
function repeat(amount) {
    const out = state(amount);
    const cycle = Math.floor(amount / 10000);
    const cycles = cycle > 0 ? [cycle, cycle + 1] : [1];
    const highest = cycles.at(-1) * 10000;
    out.nodes = cycles.map((number, index) => ({ key: `9:rule-1:cycle${number}:${number * 10000}`, index,
        threshold: String(number * 10000), thresholdText: `$${number * 100}`, rewardText: '$10 per set',
        reached: amount >= number * 10000, positionPercent: number * 10000 / highest * 100,
        celebrationEligible: amount >= number * 10000 }));
    out.next = { ...out.nodes.at(-1), remaining: String(highest - amount), remainingText: `$${(highest - amount) / 100}` };
    out.measure = String(amount); out.completed = false; out.discountApplied = cycle > 0;
    out.status = cycle > 0 ? 'tier_reached' : 'tracking';
    out.achievedKeys = out.nodes.filter(node => node.reached).map(node => node.key);
    out.progressPercent = amount / highest * 100;
    return out;
}

check('exports are functions', () => { assert.equal(typeof normalize, 'function'); assert.equal(typeof decision, 'function'); });
check('native-like spend DTO accepted', () => assert.equal(normalize(state(28000)).measure, '28000'));
check('native-like quantity DTO accepted', () => assert.equal(normalize(state(3, { quantity: true })).measure, 3));
check('first completed response is silent baseline', () => assert.deepEqual(decision(null, state(100000)), { baseline: true, celebrate: null }));
check('invalid response resets baseline without success', () => assert.deepEqual(decision(state(30000), null), { baseline: true, celebrate: null }));
check('application revision resets silently', () => assert.deepEqual(decision(state(28000), state(50000, { revision: 3 })), { baseline: true, celebrate: null }));
check('promotion rules revision resets silently', () => assert.deepEqual(decision(state(28000), state(50000, { rule: 'rule-2' })), { baseline: true, celebrate: null }));
check('promotion identity resets silently', () => { const changed = state(50000); changed.promotion.id = 10; assert.deepEqual(decision(state(28000), changed), { baseline: true, celebrate: null }); });
check('measure basis change resets silently', () => assert.equal(decision(state(28000), state(4, { quantity: true })).baseline, true));
check('duplicate state key never celebrates', () => { const first = state(28000, { stateKey: 'same' }); const second = state(100000, { stateKey: 'same' }); assert.equal(decision(first, second).celebrate, null); });
check('changed qualifying state with same measure celebrates highest eligible tier', () => { const first = state(50000); const next = state(50000, { stateKey: 'another' }); assert.equal(decision(first, next).celebrate, next.nodes[1].key); });
check('coupon-only changed state may celebrate when final discount becomes positive', () => { const next = state(50000); assert.equal(decision(state(50000, { discount: false }), next).celebrate, next.nodes[1].key); });
check('larger qualified amount after coupon change celebrates current tier again', () => { const next = state(32000); assert.equal(decision(state(31000, { discount: false }), next).celebrate, next.nodes[0].key); });
check('canonical raw threshold crossing can share displayed minor amount', () => {
    // Raw engine measure may cross $300.001 while both formatted measure strings are "30000".
    const previous = state(30000, { discount: false }); previous.nodes[0].reached = false; previous.status = 'tracking';
    previous.next = { ...previous.nodes[0], remaining: '1', remainingText: '$0.01' };
    const current = state(30000);
    assert.equal(decision(previous, current).celebrate, current.nodes[0].key);
});
check('zero final discount cannot celebrate new threshold', () => assert.equal(decision(state(28000), state(50000, { discount: false })).celebrate, null));
check('disabled animation cannot celebrate new threshold', () => assert.equal(decision(state(28000), state(50000, { animation: false })).celebrate, null));
check('smile disabled does not suppress permitted fireworks decision', () => { const next = state(30000); next.celebration.smileEnabled = false; assert.equal(decision(state(28000), next).celebrate, next.nodes[0].key); });
check('exact first threshold crosses once', () => { const next = state(30000); assert.equal(decision(state(29999), next).celebrate, next.nodes[0].key); assert.equal(decision(next, next).celebrate, null); });
check('increase within same reached tier celebrates again', () => { const next = state(49999); assert.equal(decision(state(31000), next).celebrate, next.nodes[0].key); });
check('decrease below first tier does not celebrate', () => assert.equal(decision(state(100000), state(28000)).celebrate, null));
check('decrease remaining qualified celebrates current highest tier', () => { const next = state(50000); assert.equal(decision(state(100000), next).celebrate, next.nodes[1].key); });
check('same tier may repeat on successive increase and qualified decrease', () => { const a = state(50000), b = state(55000), c = state(52000); assert.equal(decision(a, b).celebrate, b.nodes[1].key); assert.equal(decision(b, c).celebrate, c.nodes[1].key); assert.equal(decision(c, c).celebrate, null); });
check('decrease then recross celebrates newly reached node', () => { const reduced = state(28000); const increased = state(30000); assert.equal(decision(reduced, increased).celebrate, increased.nodes[0].key); });
check('multi-node jump only selects highest new tier', () => { const next = state(100000); assert.equal(decision(state(28000), next).celebrate, next.nodes[2].key); });
check('jump from first to highest selects last only', () => { const next = state(100000); assert.equal(decision(state(30000), next).celebrate, next.nodes[2].key); });
check('quantity exact threshold crosses using integer measure', () => { const next = state(4, { quantity: true }); assert.equal(decision(state(3, { quantity: true }), next).celebrate, next.nodes[1].key); });
check('unlimited DTO accepted without artificial completion', () => { const value = repeat(35000); assert.equal(normalize(value), value); assert.equal(value.completed, false); assert.equal(value.nodes.length, 2); });
check('unlimited new cycle has stable cycle identity and celebrates once', () => { const next = repeat(25000); assert.equal(decision(repeat(15000), next).celebrate, next.nodes[0].key); });
check('unlimited multi-cycle jump only selects current new cycle', () => { const next = repeat(55000); assert.equal(decision(repeat(15000), next).celebrate, next.nodes[0].key); });
check('unlimited qualifying decrease and subsequent recross both celebrate', () => { const reduced = repeat(25000), increased = repeat(35000); assert.equal(decision(repeat(35000), reduced).celebrate, reduced.nodes[0].key); assert.equal(decision(reduced, increased).celebrate, increased.nodes[0].key); });

const malformed = [
    ['null', null], ['array', []], ['version', { ...state(28000), version: 2 }],
    ['disabled', { ...state(28000), enabled: false }], ['hidden', { ...state(28000), visible: false }],
    ['synthetic preview', { ...state(28000), status: 'preview' }], ['unavailable', { ...state(28000), status: 'unavailable' }],
    ['negative measure', { ...state(28000), measure: '-1' }], ['unsafe measure', { ...state(28000), measure: '9007199254740992' }],
    ['infinite progress', { ...state(28000), progressPercent: Infinity }], ['negative progress', { ...state(28000), progressPercent: -1 }],
    ['overflow progress', { ...state(28000), progressPercent: 101 }], ['empty nodes', { ...state(28000), nodes: [] }],
    ['bad revision', { ...state(28000), revision: 2.5 }], ['absent state key', { ...state(28000), stateKey: '' }],
    ['unknown next key', { ...state(28000), next: { key: 'not-a-node', remaining: '1', remainingText: '$0.01' } }]
];
for (const [name, value] of malformed) check(`malformed ${name} is hidden`, () => assert.equal(normalize(value), null));
check('duplicate node identity rejected', () => { const value = state(28000); value.nodes[1].key = value.nodes[0].key; assert.equal(normalize(value), null); });
check('descending node positions rejected', () => { const value = state(28000); value.nodes[1].positionPercent = 20; assert.equal(normalize(value), null); });
check('invalid next amount rejected', () => { const value = state(28000); value.next.remaining = null; assert.equal(normalize(value), null); });
check('nonboolean completion cannot produce highest-tier UI', () => { const value = state(100000); value.completed = 'false'; assert.equal(normalize(value), null); });
check('rounded equal native positions remain valid distinct tiers', () => {
    // Raw thresholds $100.000001 / $100.000002 both become $100.00 and position 100 after production rounding.
    const value = state(10000); value.nodes = value.nodes.slice(0, 2).map((node, index) => ({ ...node, key: `9:rule-1:tier${index}:10000`, threshold: '10000', thresholdText: '$100.00', positionPercent: 100, reached: index === 0, celebrationEligible: index === 0 }));
    value.next = { ...value.nodes[1], remaining: '1', remainingText: '$0.01' }; value.progressPercent = 100; value.discountApplied = true; value.status = 'tier_reached';
    assert.equal(normalize(value), value);
});
check('rounded zero native position remains valid tiny first tier', () => {
    // $1 versus $100,000,000: production round(100 * 1 / 100000000, 4) is 0.
    const value = state(50); value.nodes = value.nodes.slice(0, 2).map((node, index) => ({ ...node, threshold: index ? '10000000000' : '100', thresholdText: index ? '$100,000,000.00' : '$1.00', positionPercent: index ? 100 : 0 }));
    value.next = { ...value.nodes[0], remaining: '50', remainingText: '$0.50' }; value.progressPercent = 0;
    assert.equal(normalize(value), value);
});

const failed = checks.filter(item => !item.pass);
console.log(JSON.stringify({ kind: 'Production normalize/decision independent Node checks; synthetic DTO only; no cart/browser writes', passed: checks.length - failed.length, total: checks.length, failed }, null, 2));
if (failed.length) process.exitCode = 1;
