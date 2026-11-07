/*
 * Place Order tap guard (native Woo checkout stays authoritative).
 *
 * Tapping "Place Order" right after typing in a field blurs that field; Woo Blocks then
 * pushes the address and disables the button for a moment, so the browser drops the click
 * and the shopper's tap does nothing. This keeps that one tap: when a completed tap on the
 * button produced no click, the button is clicked once as soon as Woo enables it again.
 *
 * Never more than one replay per tap, never while an order is already being placed, and a
 * real click always cancels the replay (Woo's own state prevents a second submission).
 */
(function () {
    'use strict';
    const SELECTOR = '.wc-block-components-checkout-place-order-button';

    function attach(doc, options) {
        const win = doc.defaultView;
        const settings = Object.assign({ graceMs: 350, maxWaitMs: 10000, pollMs: 100 }, options || {});
        let pending = null; // { pointerId, armed, at, timer, poll }

        // Woo's checkout store: a tap made while an order is being placed (or already placed)
        // is never queued, so a replay can never become a second submission.
        function placing() {
            try {
                const checkout = win.wp?.data?.select?.('wc/store/checkout');
                if (!checkout) return false;
                return !!(checkout.isProcessing?.() || checkout.isBeforeProcessing?.() || checkout.isAfterProcessing?.() || checkout.isComplete?.());
            } catch { return false; }
        }
        const busy = button => placing() || button.classList.contains('wc-block-components-button--loading') || button.getAttribute('aria-busy') === 'true';
        const ready = button => button.isConnected && !button.disabled && button.getAttribute('aria-disabled') !== 'true' && !busy(button);
        const placeButton = target => (target && target.closest ? target.closest(SELECTOR) : null);
        // A disabled button does not receive pointer events (they land on its parent), so
        // decide by position: is the pointer inside the Place Order button's box?
        function buttonAt(event) {
            const direct = placeButton(event.target);
            if (direct) return direct;
            const button = doc.querySelector(SELECTOR);
            if (!button || !Number.isFinite(event.clientX) || !Number.isFinite(event.clientY)) return null;
            const r = button.getBoundingClientRect();
            return event.clientX >= r.left && event.clientX <= r.right && event.clientY >= r.top && event.clientY <= r.bottom ? button : null;
        }

        function clear() {
            if (!pending) return;
            win.clearTimeout(pending.timer);
            win.clearInterval(pending.poll);
            doc.querySelector(SELECTOR)?.classList.remove('vc-place-order-queued');
            pending = null;
        }
        function replay() {
            if (!pending || !pending.armed) return;
            const button = doc.querySelector(SELECTOR);
            if (!button || win.performance.now() - pending.at > settings.maxWaitMs) { clear(); return; }
            if (!ready(button)) return;
            clear();
            button.click();
        }

        doc.addEventListener('pointerdown', event => {
            if (!event.isTrusted || (event.pointerType === 'mouse' && event.button !== 0)) return;
            const button = buttonAt(event);
            if (!button) return; // a press elsewhere leaves an earlier queued tap alone
            if (busy(button)) { clear(); return; }
            if (pending && pending.armed) return; // a second tap while the first waits adds nothing
            clear();
            pending = { pointerId: event.pointerId, armed: false, at: win.performance.now(), timer: 0, poll: 0 };
        }, true);
        doc.addEventListener('pointerup', event => {
            if (!pending || pending.armed || event.pointerId !== pending.pointerId) return;
            if (!buttonAt(event)) { clear(); return; }
            pending.armed = true;
            pending.at = win.performance.now();
            // A normal tap clicks within a few milliseconds of pointerup; only a dropped one waits.
            pending.timer = win.setTimeout(() => {
                if (!pending) return;
                doc.querySelector(SELECTOR)?.classList.add('vc-place-order-queued');
                replay();
                if (pending) pending.poll = win.setInterval(replay, settings.pollMs);
            }, settings.graceMs);
        }, true);
        doc.addEventListener('pointercancel', clear, true);
        // Any click that reaches the button (real or replayed) settles the tap.
        // A click that reaches the button itself (real or replayed) settles the tap. While the
        // button is disabled the browser sends the click to its parent instead, which is the
        // dropped tap this guard keeps.
        doc.addEventListener('click', event => { if (placeButton(event.target)) clear(); }, true);
        win.addEventListener('pagehide', clear);
        return { pending: () => pending, clear };
    }

    window.VialCovePlaceOrderTap = { attach, SELECTOR };
    // Enqueued only on the checkout page; the button renders later, so listen on the document.
    if (!window.VialCovePlaceOrderTapManual) attach(document);
}());
