/**
 * M4 营销弹窗静默开关（前台部分）：assets/marketing-quiet.js 和 assets/exit-intent.js 里的静默判断。
 * 运行：node tests/m4-marketing-quiet.cjs   不需要浏览器。
 */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const assert = require('assert');

// 页头实际输出的是去掉注释和缩进之后的版本（Popups::print_script 的做法），测试用同样处理过的脚本。
const quietSource = fs.readFileSync(path.join(__dirname, '../assets/marketing-quiet.js'), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '').split('\n').map(line => line.trim()).filter(Boolean).join('\n');
const exitSource = fs.readFileSync(path.join(__dirname, '../assets/exit-intent.js'), 'utf8');
let checks = 0;
const check = (label, ok) => { checks++; assert.ok(ok, label); };

function page({ cookie = '', server = false, buyer = null } = {}) {
	const classes = new Set();
	const jar = new Map(cookie ? cookie.split(';').map(p => p.trim().split('=')) : []);
	const events = [];
	const styles = [];
	const document = {
		documentElement: { classList: { toggle(name, on) { on ? classes.add(name) : classes.delete(name); }, contains: n => classes.has(n) } },
		head: { appendChild(node) { styles.push(node.textContent); } },
		createElement: () => ({ textContent: '' }),
		dispatchEvent(event) { events.push(event); return true; },
		get cookie() { return [...jar].map(([k, v]) => k + '=' + v).join('; '); },
		set cookie(value) {
			const [pair, ...attrs] = value.split(';').map(p => p.trim());
			const [name, val] = pair.split('=');
			if (attrs.some(a => a === 'max-age=0')) jar.delete(name); else jar.set(name, val);
			document.lastCookie = value;
		},
	};
	const window = { document, CustomEvent: function (type, init) { this.type = type; this.detail = init.detail; } };
	if (server) window.pcMarketingQuietServer = true;
	if (buyer) window.pcBuyer = buyer;
	vm.runInNewContext(quietSource, { window, document });
	return { window, document, classes, events, styles, api: window.pcMarketingQuiet };
}

// ---------- marketing-quiet.js ----------
let p = page();
check('默认不静默', p.api.suppressed() === false && !p.classes.has('pc-marketing-quiet'));
check('样式只隐藏首单券条和倒计时，不碰模态弹窗', p.styles.length === 1 && p.styles[0].includes('[data-pc-first-order-offer]') && p.styles[0].includes('[data-pc-campaign-countdown]') && !p.styles[0].includes('dialog'));
const seen = [];
p.api.onChange(on => seen.push(on));
check('suppress()：静默、加上 html 类、写 cookie、通知一次', p.api.suppress() === true && p.classes.has('pc-marketing-quiet') && /pc_marketing_quiet=1/.test(p.document.cookie) && seen.join() === 'true' && p.events.at(-1).type === 'pc:marketing-quiet' && p.events.at(-1).detail.suppressed === true);
check('suppress() 不填天数：只到关闭浏览器为止', !/max-age/.test(p.document.lastCookie));
p.api.suppress({ days: 30 });
check('suppress({days:30})：cookie 留 30 天；状态没变不重复通知', /max-age=2592000/.test(p.document.lastCookie) && seen.length === 1);
check('release()：取消静默、去掉类和 cookie、通知', p.api.release() === false && !p.classes.has('pc-marketing-quiet') && !/pc_marketing_quiet/.test(p.document.cookie) && seen.join() === 'true,false');

p = page({ cookie: 'a=b; pc_marketing_quiet=1' });
check('带着 cookie 打开页面：一上来就是静默（不闪）', p.api.suppressed() === true && p.classes.has('pc-marketing-quiet'));
p = page({ cookie: 'pc_marketing_quiet=0; xpc_marketing_quiet=1' });
check('别的 cookie、别的值不算', p.api.suppressed() === false);
p = page({ server: true });
check('服务端过滤器返回真：静默', p.api.suppressed() === true && p.classes.has('pc-marketing-quiet'));
p = page({ buyer: { suppressPopups: true } });
check('已购回访设了 pcBuyer.suppressPopups：静默', p.api.suppressed() === true);
p = page();
p.window.pcBuyer = { suppressPopups: true };
check('页面加载后才知道是已购：refresh() 之后生效', p.api.suppressed() === true && p.api.refresh() === true && p.classes.has('pc-marketing-quiet'));
p.api.release();
check('release() 也会清掉 pcBuyer 的标记', p.api.suppressed() === false && p.window.pcBuyer.suppressPopups === false);

// ---------- exit-intent.js：静默时不弹、不拦返回键；不静默时照常 ----------
function exitPage({ quiet, coarse }) {
	const handlers = { window: {}, document: {} };
	const pushed = [];
	let dialogs = 0;
	// 弹窗真的弹出时会操作很多页面元素；这里只关心“弹没弹”，元素一律用什么都接受的替身。
	const node = () => new Proxy(function () {}, { get: (t, k) => (k === Symbol.toPrimitive ? () => '' : node()), apply: () => node(), set: () => true });
	const document = {
		addEventListener: (type, fn) => { (handlers.document[type] ||= []).push(fn); },
		querySelector: () => null,
		createElement: tag => { if (tag === 'dialog') dialogs++; return node(); },
		body: { appendChild() {} },
		documentElement: { scrollHeight: 0 },
		visibilityState: 'visible',
	};
	const window = {
		pcExitIntent: { type: 'message', title: 'Wait', text: '', button: '', decline: 'No', image: '', color: '#000', link: '', code: '', prizes: [], triggers: { desktop: true, mobile: ['back'], idle: 20, min: 0 }, frequency: 0, preview: false, ajax: { event: '/e', claim: '/c', spin: '/s' }, strings: {} },
		fetch: () => Promise.resolve({ json: () => Promise.resolve({}) }),
		HTMLDialogElement: function () {},
		CustomEvent: function () {},
		sessionStorage: { getItem: () => null, setItem() {} },
		localStorage: { getItem: () => null, setItem() {} },
		matchMedia: () => ({ matches: coarse }),
		history: { pushState: (...a) => pushed.push(a), back() { pushed.push('back'); } },
		location: { href: 'https://shop.example/' },
		addEventListener: (type, fn) => { (handlers.window[type] ||= []).push(fn); },
		removeEventListener() {},
		setTimeout: fn => { fn(); return 1; },
		clearTimeout() {},
		scrollY: 0, innerHeight: 800,
		pcMarketingQuiet: { suppressed: () => quiet },
	};
	vm.runInNewContext(exitSource, { window, document, Date, Number, Math, String, Promise, URLSearchParams, FormData: function () { this.append = () => {}; } });
	return { handlers, pushed, dialogs: () => dialogs };
}
let e = exitPage({ quiet: false, coarse: false });
e.handlers.document.mouseout.forEach(fn => fn({ relatedTarget: null, clientY: 0 }));
check('不静默：电脑端鼠标移出页面顶部照常弹窗', e.dialogs() === 1);
e = exitPage({ quiet: true, coarse: false });
e.handlers.document.mouseout.forEach(fn => fn({ relatedTarget: null, clientY: 0 }));
check('静默：不弹窗', e.dialogs() === 0);
e = exitPage({ quiet: false, coarse: true });
e.handlers.window.click.forEach(fn => fn());
check('不静默：手机端第一次点页面时照常加一条浏览记录（拦返回键）', e.pushed.length === 1 && e.pushed[0][0].pcExit === 1);
e = exitPage({ quiet: true, coarse: true });
e.handlers.window.click.forEach(fn => fn());
check('静默：不加浏览记录，返回键不被拦', e.pushed.length === 0);

console.log(`RESULT ${checks} 项检查：全部通过`);
