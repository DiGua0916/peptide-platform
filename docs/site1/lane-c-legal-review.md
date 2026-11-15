# Site 1 · Wording for legal review (draft, not final)

Status: **draft for legal review. Do not publish it as final wording.** It comes from P1-7 and P1-9 of the site-1 plan signed off on 2026-11-14. After legal approves or edits the text, update the matching strings in `tools/site1/lane-c-content-fixes.php` and in the Spanish translation files, then run the script.

## 1. Standard product FAQ (replaces each product's existing FAQ section)

Currently 36 products have a FAQ section, mostly reconstitution, mixing, refreezing and contamination-prevention instructions. P1-9 replaces them with documentation, lot, shipping, unopened-storage and after-sales answers:

| Question | Answer |
|---|---|
| How do I read the COA for this lot? | Each report lists the lot number, test date, laboratory and HPLC purity. Match the lot number on your vial label with the report shown on this page, or search for it in the COA library. |
| Which lot will I receive? | Orders ship from current stock. The lot number is printed on the vial label, and batch information appears in your order details after shipment. |
| How is my order shipped? | Orders ship with FedEx. The shipping cost and delivery estimate for your address are shown at checkout before you pay. |
| How should unopened vials be stored? | Keep unopened vials sealed at 2–8 °C, dry and protected from light. |
| What if my order arrives damaged or incomplete? | Contact our support team with your order number and photos of the package. We will review it with you. |
| Is this product for human or veterinary use? | No. It is supplied for laboratory research use only and is not intended for human or animal consumption. |

Points for legal to confirm:
- "The lot number is printed on the vial label": confirm every product's label carries a lot number.
- "batch information appears in your order details after shipment": this relies on the existing order-batch feature (`docs/CONTRACT.md` "订单批次"). Confirm warehouse staff actually assign batches.
- The after-sales answer makes no time limit or refund promise. Add the store's after-sales policy if legal wants it stated.

## 2. COA area in the product dialog (P1-7)

| Old wording | New wording |
|---|---|
| REFERENCE DOCUMENT | BATCH COA |
| Third-party reference report. Results apply to the sample named in the document, not a verified VialCove stock batch. | Latest published report for this product and specification. Results apply to the lot named in the report. |
| View reference COA ↗ | View full report ↗ |
| (The info line shows only lab and date) | Lot number · lab · date |

**Premise to confirm:** the new wording assumes reports in the COA library are VialCove's own lots. The owner confirmed this in the plan, and the 33 newly imported reports are labeled as the store's lots. If any third-party "reference" reports for other suppliers' samples are still published, unpublish them first. Otherwise the new wording would be misleading.

## 3. "Research use only" line under the price (P1-9)

Suggested text: **For laboratory research use only. Not for human or animal consumption.**

Not done yet. Project rules (`inc/storefront-identity.php`) say product-use wording must come from store settings and must not be written into code. The site already has the store-configured "Product use statement" (NF-06), shown above the footer and at the end of the product dialog. Showing one line under the price needs two things:
1. legal approval of the text above, and
2. a decision on whether the product dialog layout may get this one line (P1-5 decided to "keep the popup as it is").

Lane C has not changed the dialog layout. This is left to the parent session (lane A) and the owner.

## 4. Usage paragraphs outside the FAQ

78 products have reconstitution or handling steps in the body text outside the FAQ (for example "4. Reconstitution Instructions"). The script does not change them. Per-product passages are listed in `docs/site1/lane-c-content-review.md`. Legal and the owner should decide whether to delete or rewrite them.
