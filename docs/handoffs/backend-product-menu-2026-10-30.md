# Empty product catalog navigation — 2026-10-30

Author: Codex. The user reported that clicking the Products parent menu returned to the operational dashboard after the catalog had been emptied. An empty catalog must remain a usable product list with the native Add product action.

## Root cause and fix

The menu URL was already correct: `edit.php?post_type=product`. WooCommerce's existing `OnboardingTasks\Tasks\Products` task registers `maybe_redirect_to_add_product_tasklist` on `current_screen` at priority 30 while that task is incomplete. On an `edit`/`product` screen with no non-auto-draft products, the callback redirects to `wc-admin&path=/add-product&task=products`; the onboarding navigation ultimately lands on this project's operational dashboard.

`Admin_Menus::keep_product_list` runs at priority 20, only for the native product list and users who can `edit_products`, outside AJAX/network admin. It obtains WooCommerce's already-created Products task through the public TaskLists API and removes only that task's list-diversion callback. If the task/API is unavailable, it does nothing. Native product permissions, list rendering, creation, editing, taxonomy menus and other onboarding hooks remain in place. It does not mark setup complete, add products, write settings, or modify WooCommerce core. No broad redirect interception or client-side navigation patch was added.

Changed source: `wp-content/plugins/peptide-core/includes/admin/class-admin-menus.php`.

## Validation

PHP 8.3 syntax and source whitespace checks pass. Root reproduced the original parent-menu click returning to `pc-overview` in the signed-in built-in browser before this change. The same real click after the change stayed at `edit.php?post_type=product`, with zero products, the native empty row and the Add product action. The DOM before/after record and reviewed screenshot are under `D:/Codex/Tasks/2026-10-30/product-menu-fix/work/original-browser-click.json` and `outputs/product-list-empty.jpg`.

Six targeted checks also passed in the existing isolated real WordPress/WooCommerce instance, with zero JavaScript exceptions. A request-scoped empty-catalog fixture reproduced the original Woo 302, then returned a normal 200 empty list with this source. SQL still contained the original six synthetic parent products; only that marked request's count/list view was filtered. At screen priority 19 the native task callback existed, and at 21 this fix had removed it. An ordinary populated-list parent click showed six rows; a Pages request retained the same callback; the Add product GET opened the native editor; and a staff account without edit_products remained forbidden. The auto-draft created by the native Add product GET and the two temporary actors were precisely removed. This was a focused navigation regression, not a full-site acceptance run.

This is a narrow navigation fix during the larger unfinished store-builder redesign. Root owns the commit and GitHub synchronization. No business fixtures, authentication material or database dumps belong in this commit.
