"""Real-input browser checks for assets/checkout-place-order-tap.js.

Run: python3 tests/checkout-place-order-tap-browser.py   (needs `pip install playwright`
and Google Chrome; uses channel="chrome"). Uses trusted mouse and touch input on a small
harness page that imitates Woo Blocks disabling the button while it saves a field.
"""
import pathlib, sys
from playwright.sync_api import sync_playwright

SCRIPT = (pathlib.Path(__file__).resolve().parent.parent / "assets/checkout-place-order-tap.js").read_text()
PAGE = """<!doctype html><meta name=viewport content="width=device-width"><style>body{margin:0;font:16px sans-serif}
button{position:absolute;left:40px;top:200px;width:240px;height:56px}#away{position:absolute;left:40px;top:400px;width:240px;height:40px}</style>
<button class="wc-block-components-checkout-place-order-button">Place Order</button><div id=away>away</div>
<script>window.VialCovePlaceOrderTapManual = true;</script><script>%s</script>
<script>
const b = document.querySelector('button'); window.clicks = 0; window.mode = {};
b.addEventListener('click', () => { window.clicks++; if (window.mode.busyAfterClick) { b.disabled = true; b.classList.add('wc-block-components-button--loading'); } });
// Imitate Woo: the blur caused by pressing disables the button for `disableMs`.
document.addEventListener('pointerdown', e => { if (e.target === b && window.mode.disableMs) { b.disabled = true; setTimeout(() => { if (!window.mode.stuck) b.disabled = false; }, window.mode.disableMs); } }, true);
window.api = VialCovePlaceOrderTap.attach(document, {graceMs: 350, maxWaitMs: window.maxWait || 3000, pollMs: 50});
</script>""" % SCRIPT

failures, checks = [], 0
def check(name, ok):
    global checks; checks += 1
    if not ok: failures.append(name)

with sync_playwright() as p:
    browser = p.chromium.launch(channel="chrome", headless=True)
    for label, ctxopts in (("mouse", {}), ("touch", dict(p.devices["iPhone 13"]))):
        def run(mode, action, wait=1500, prep=None):
            ctx = browser.new_context(**ctxopts); pg = ctx.new_page(); pg.set_content(PAGE)
            pg.evaluate("m => { window.mode = m }", mode)
            if prep: pg.evaluate(prep)
            box = pg.locator("button").bounding_box(); x, y = box["x"] + box["width"] / 2, box["y"] + box["height"] / 2
            action(pg, x, y); pg.wait_for_timeout(wait)
            n = pg.evaluate("window.clicks"); ctx.close(); return n
        def tap(pg, x, y):
            if label == "touch": pg.touchscreen.tap(x, y)
            else: pg.mouse.click(x, y)
        def double(pg, x, y):
            tap(pg, x, y); pg.wait_for_timeout(120); tap(pg, x, y)
        def drag_away(pg, x, y):
            pg.mouse.move(x, y); pg.mouse.down(); pg.mouse.move(x, y + 200, steps=5); pg.mouse.up()
        check(f"{label}: normal tap clicks once", run({}, tap) == 1)
        check(f"{label}: tap dropped by disable is replayed once", run({"disableMs": 600}, tap) == 1)
        check(f"{label}: long disable (2.5s) still replayed once", run({"disableMs": 2500}, tap, wait=3500) == 1)
        check(f"{label}: never re-enabled -> no click", run({"disableMs": 600, "stuck": True}, tap, wait=4000) == 0)
        check(f"{label}: double tap while disabled -> one click", run({"disableMs": 600}, double) == 1)
        check(f"{label}: double tap normal -> second ignored once busy", run({"busyAfterClick": True}, double) == 1)
        check(f"{label}: busy button is not queued", run({}, tap, prep="document.querySelector('button').classList.add('wc-block-components-button--loading'); document.querySelector('button').disabled = true; setTimeout(()=>{const b=document.querySelector('button'); b.classList.remove('wc-block-components-button--loading'); b.disabled=false}, 500)") == 0)
        check(f"{label}: tap while Woo is placing the order is not queued", run({"disableMs": 600}, tap, prep="window.wp = {data: {select: () => ({isProcessing: () => true})}}") == 0)
        check(f"{label}: idle Woo store still replays a dropped tap", run({"disableMs": 600}, tap, prep="window.wp = {data: {select: () => ({isProcessing: () => false, isComplete: () => false})}}") == 1)
        if label == "mouse":
            check("mouse: press then drag away -> no click", run({"disableMs": 600}, drag_away) == 0)
            check("mouse: right click ignored", run({"disableMs": 600}, lambda pg, x, y: pg.mouse.click(x, y, button="right")) == 0)
    browser.close()

print(f"{checks - len(failures)}/{checks} place-order tap checks passed")
for f in failures: print("FAIL:", f)
sys.exit(1 if failures else 0)
