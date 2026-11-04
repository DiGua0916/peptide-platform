# Delivery and WhatsApp support · 2026-11-05

Codex. User explicitly requests a visible bottom-right support launcher, a separate delivery destination, and an Order support entry which scrolls to its own contact section and expands the launcher leftward into an explicit WhatsApp CTA. The user supplied this store's actual international WhatsApp number in the current chat; it must remain in native store settings, not source code, layout links or this handoff.

## Scope and planned native settings change

- Theme owns presentation, small launcher interaction and the standalone native WP page template.
- Create a normal editable Delivery details page; use existing verified FAQ wording and checkout-dependent charges, without inventing delivery times or free-shipping promises.
- Update only home685 service-benefits delivery/support link destinations and add one native editable CTA module (`order-support`). Keep every incumbent module and all other layout documents intact.
- User authorizes enabling the current native support setting with their supplied number. Use the existing Support_Contacts and Settings sanitizers plus WordPress option API, preserve all non-support settings exactly, and leave backend source untouched. Native support toggles remain authoritative; no frontend fallback number.
- Shipping rates, checkout, products, real carts, orders and payments are outside scope. No WhatsApp message is sent. Local preview only; no public-server publication.

## Completed locally

- `storefront-utilities.php/css/js`: one native-configured 56px round headset button. Tapping toggles a leftward reveal containing Contact support now / Add us on WhatsApp. Contact is a real validated native wa.me link, not a copied phone in source; its new-tab behavior is announced accessibly. Escape/outside click collapse, tab order follows expanded state, no automatic send, reduced motion, native bottom navigation offset and BFCache cleanup are preserved. Existing open dialogs/menu and checkout still suppress the launcher.
- Order support now points to the new native editable CTA `#vc-order-support`, just before the existing closing module. Native anchor navigation scrolls there and the matching click/hash expands the launcher. Existing FAQ, product/cart history and every previous home module remain.
- Delivery details now points to native published page11740 at `/delivery-details/`, using `page-delivery-details.php` and scoped CSS. Its title/body use the normal WP editor. Copy explains checkout-dependent options/charges, checking the delivery address, and WhatsApp order follow-up. No fixed shipping time or price was invented. Its contact link returns to the editable home CTA and expands the ball.
- Applied through the normal Layout_Controller save/publish and WordPress page/settings APIs after an exported plan/hash review. Home685 only; seven other layouts' revisions/raw hashes and all non-support store settings verified unchanged. Support enabled with the user-supplied number through existing sanitizers. No backend source edits.

## Actual verification

- Eight production-module Node support lifecycle/keyboard/URL tests and 15 existing PHP presentation guards passed; JS/PHP syntax and diff checks passed. The helper test verifies correct same-origin/same-path hashes without intercepting FAQ/external/other-page links, plus outside/Escape focus, initial hashes, bottom-bar sizing and BFCache teardown.
- Actual phone preview390/content375: help1, dedicated delivery href, support href and contact section present, document scrollWidth375. Clicking Order support gives expanded=true, CTA tabindex0, contact width304 and x54.67 within the375px viewport; destination section top104.79 below sticky header.
- Actual Delivery details click loads the dedicated page, its three headings and support button, width375/scrollWidth375. Its Ask about delivery on WhatsApp link returns to the contact section with expanded=true and valid native WhatsApp URL. No external WhatsApp navigation or message was performed.
- A global button reset initially forced the launcher radius8px; a narrowly scoped circle rule now overrides that existing important reset. Final actual computed size56×56/radius50%/expanded=true verified. Final screenshot `D:/Codex/Tasks/2026-11-05/support-delivery/outputs/support-expanded.jpg`; delivery-page.jpg and delivery-qa/contact-qa.json capture the other checks. contact-qa.json records the earlier8px observation, superseded by final computed50% above.
- Impeccable detector produced advisory palette/radius/type warnings against the repository's ADMIN-only DESIGN.md; that file explicitly excludes the public storefront. Incumbent storefront teal/Geist and the user's requested round launcher are preserved, not replaced by admin monochrome tokens. No hard detector result or real-device FPS claim.
- Local CLI has an existing php_imagick startup warning, but native setup/readback and lint completed. No cart/order/payment/network mutation or public-server deployment.

Runtime plan, backup modules and redacted apply receipt are under `D:/Codex/Tasks/2026-11-05/support-delivery`; actual private contact value remains native settings only. Source and final integration references are recorded in room/project memory after Git delivery.
