---
name: VialCove Admin Workspaces
description: Precise monochrome tools for store operations and site management.
colors:
  primary: "#171717"
  primary-hover: "#333333"
  surface: "#ffffff"
  customer-canvas: "#f7f7f7"
  management-canvas: "#f5f5f5"
  navigation: "#fafafa"
  soft: "#f5f5f5"
  management-soft: "#f2f2f2"
  muted: "#666666"
  line: "#e6e6e6"
  control-line: "#d4d4d4"
  management-control-line: "#d5d5d5"
  status-text: "#404040"
  status-surface: "#f1f1f1"
  error-text: "#933a32"
  error-surface: "#fff6f5"
  error-line: "#e5bfba"
  warning-surface: "#fffaf0"
  warning-line: "#e9d6a7"
typography:
  page-title-customer:
    fontFamily: 'Manrope, "Microsoft YaHei", "PingFang SC", sans-serif'
    fontSize: "26px"
    fontWeight: 650
    lineHeight: "34px"
    letterSpacing: "-0.02em"
  page-title-management:
    fontFamily: '"VialCove Manrope", "Microsoft YaHei", "PingFang SC", sans-serif'
    fontSize: "26px"
    fontWeight: 700
    lineHeight: "34px"
    letterSpacing: "-0.025em"
  section-title-customer:
    fontSize: "16px"
    fontWeight: 600
    lineHeight: "24px"
  section-title-management:
    fontSize: "17px"
    fontWeight: 650
    lineHeight: "26px"
  body:
    fontFamily: 'Manrope, "Microsoft YaHei", "PingFang SC", sans-serif'
    fontSize: "14px"
    fontWeight: 400
    lineHeight: "22px"
  control:
    fontSize: "13px"
    fontWeight: 600
    lineHeight: "20px"
  caption:
    fontSize: "12px"
    fontWeight: 400
    lineHeight: "16px"
  metric-management:
    fontSize: "28px"
    fontWeight: 700
    lineHeight: "36px"
    letterSpacing: "-0.025em"
  login-field:
    fontSize: "16px"
    fontWeight: 400
    lineHeight: "24px"
rounded:
  square: "0px"
  compact: "4px"
  control: "6px"
  feature: "8px"
  panel: "12px"
spacing:
  micro: "4px"
  compact: "8px"
  control: "12px"
  mobile: "16px"
  section: "20px"
  panel: "24px"
  desktop: "28px"
components:
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.surface}"
    typography: "{typography.control}"
    rounded: "{rounded.control}"
    padding: "7px 12px"
  button-primary-hover:
    backgroundColor: "{colors.primary-hover}"
    textColor: "{colors.surface}"
  button-secondary:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.primary}"
    typography: "{typography.control}"
    rounded: "{rounded.control}"
    padding: "7px 12px"
  input-customer:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.primary}"
    rounded: "{rounded.control}"
    padding: "7px 12px"
  input-management:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.primary}"
    rounded: "{rounded.control}"
    padding: "7px 10px"
  feature-card:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.primary}"
    rounded: "{rounded.feature}"
    padding: "24px"
  management-panel:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.primary}"
    rounded: "{rounded.panel}"
    padding: "20px"
  status-chip:
    backgroundColor: "{colors.status-surface}"
    textColor: "{colors.status-text}"
    rounded: "{rounded.compact}"
    padding: "4px 8px"
  login-field:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.primary}"
    typography: "{typography.login-field}"
    rounded: "{rounded.control}"
    padding: "9px 11px"
  login-panel:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.primary}"
    rounded: "{rounded.panel}"
    padding: "28px"
---

# Design System: VialCove Admin Workspaces

## Overview

**Creative North Star: "The Precision Operating Desk"**

VialCove's administrative surfaces are calm, compact workspaces. Light navigation with neutral selection washes, white working surfaces and dark primary actions make location and action easy to read. Alignment, spacing and type weight carry the hierarchy. Black and white are the confirmed brand direction; color remains available for real validation states and the content being edited.

This system applies to the customer backend, which operates and decorates an individual store, and the separate management backend, which manages stores and their infrastructure. It also covers both login pages. It does not govern the public storefront or force its content preview into monochrome. WordPress and WooCommerce retain ownership of their forms, editing controls, mobile navigation and business behavior.

**Key Characteristics:**

- A light navigation rail with neutral current selections and dark text.
- Compact controls and continuous data surfaces.
- Local Manrope with system Chinese fallbacks.
- Responsive records that preserve fields and actions.
- Brief interaction feedback with reduced motion support.

Recorded from the implemented Core 0.32.0 and HQ 0.3.1 styles on 2026-10-29. Source evidence: `wp-content/plugins/peptide-core/assets/{admin,overview,product,editor,tracking}-workspace.css`, `workspace-login.css`, and `wp-content/plugins/peptide-hq/assets/{hq-workspace,hq-login,hq-operations}.css`. Inherited native component styles remain part of the runtime. This is a manual scan of the code; the Impeccable context engine download did not complete and failed checksum validation. No engine, detector, concept or component review success is implied by this record.

## Colors

The palette is neutral and deliberately quiet, with semantic exceptions for genuine errors and warnings. Frontmatter owns exact primitive values; the names below explain their roles.

### Primary

- **Operating Ink** (`primary`): body text, primary actions, selected navigation text and chart marks.
- **Pressed Charcoal** (`primary-hover`): hovered primary actions and stronger link feedback.

### Neutral

- **White Work Surface** (`surface`): forms, tables, headers and panels.
- **Customer Canvas** (`customer-canvas`): the individual store workspace background.
- **Management Canvas** (`management-canvas`): the site management workspace background.
- **Navigation Paper** (`navigation`): side rails, login canvas, table headings and quiet support regions.
- **Soft Wash** (`soft`) and **Management Soft Wash** (`management-soft`): restrained hover states, secondary surfaces and readonly or disabled regions where specified by their owner.
- **Quiet Text** (`muted`): descriptions, labels, timestamps and secondary navigation.
- **Hairline** (`line`): panel boundaries, table dividers and header separators.
- **Customer Control Stroke** (`control-line`) and **Management Control Stroke** (`management-control-line`): controls retain their respective implementation values.
- **Status Ink** (`status-text`) and **Status Wash** (`status-surface`): neutral operational badges and status chips.

### Semantic Exceptions

- **Error Ink** (`error-text`): management validation and destructive actions. Customer destructive actions retain their existing muted red variants.
- **Error Paper** (`error-surface`) and **Error Stroke** (`error-line`): management and login error notices. Customer notices use their own pale red variants.
- **Warning Paper** (`warning-surface`) and **Warning Stroke** (`warning-line`): management warnings and update notices. Existing warning and error badge pairs remain semantically colored.

**The Operational Contrast Rule.** Use neutral navigation selection washes with dark text and dark primary actions to establish hierarchy; reserve semantic color for a real state or content asset.

## Typography

**Display and Body Font:** the bundled Manrope face, named `Manrope` in the customer workspace and `VialCove Manrope` in management and login styles. Both use Microsoft YaHei, PingFang SC and sans-serif fallbacks. The fonts are local assets; no remote font request is required.

**Character:** compact and legible, with restrained negative tracking on page titles and large numbers. There is no separate editorial display family or global monospace identity. WordPress Dashicons remain native interface icons; they are not the typeface for text.

### Hierarchy

- **Page title:** the frontmatter customer and management title roles share a size but retain different weights and tracking. Customer mobile titles reduce to (20px/28px) at the native mobile boundary; management titles reduce to (24px/32px) below the small-screen boundary.
- **Section title:** customer overview sections use the customer role; management panels use the management role. Smaller nested sections use body-sized semibold text.
- **Body:** the frontmatter body role establishes the shell rhythm. Customer paragraph copy commonly uses (14px/20px), while management paragraphs retain the body line height.
- **Control and label:** buttons and many field labels use the control role. Customer inputs use (14px/20px); management inputs use (13px/20px). Login fields use the larger login-field role.
- **Caption:** the caption role covers small supporting text; longer hints and management descriptions use (19px) or (20px) line height where defined.
- **Metric:** management summary values use the metric role. Customer metrics vary by role, including primary overview values (30px/32px), secondary values (26px/32px) and tracking values (30px/38px). Preserve tabular numerals for metrics and tabular data.

**The Role Before Scale Rule.** Choose the owning surface's established title, body or control role before adding a new size; the two workspaces do not require identical metrics in every component.

## Layout

The user explicitly confirmed compact, efficient density on 2026-10-29: show more products, orders and settings within a viewport while retaining legible text, reachable controls and responsive reflow.

The desktop shell uses a navigation rail (220px), a compact workspace header with minimum height (52px), and content gutters (28px). Customer content starts with a top margin (24px); management uses the same desktop start. Desktop headers sit below the native WordPress toolbar. Page content expands with the available workspace rather than sitting inside a marketing-page maximum width. Explanatory management copy remains bounded near (70–75ch), and longer management forms have a maximum width (880px).

Use the frontmatter spacing rhythm for new shared components. Existing specialized regions also use content-specific values, such as management operations columns with a gap (26px), customer product columns with a gap (24px), and overview regions with a gap (28px). Avoid adding spacing merely to make neighboring sections look equally tall.

The customer overview starts with a continuous metric strip, then working sections and a support rail. Its support rail is (264px) on wide layouts and moves below the main region at (1400px). The product editor uses a flexible main column and publish sidebar (288px), stacking at (1100px); the sidebar's remaining two-column layout becomes a single column at (600px). Management operations use a focused form and context column, stacking at (1100px).

The native mobile boundary is (782px). Content gutters become (16px), forms stack, and navigation uses the existing WordPress menu control. Preserve the shell-specific mobile rail widths: customer (240px), management (220px). Customer folded navigation is (48px); management folded navigation is (64px). Customer auto-fold applies between (783px) and (960px). These differences are native integrations, not new shared token defaults.

Below (600px), the customer workspace keeps the native toolbar fixed at the viewport top and offsets its sticky workspace header by the toolbar height (46px). The content wrapper adds no top padding. Preserve that relationship during scrolling so the breadcrumb remains visible without adding empty space above the first field. The management shell retains its own mobile toolbar integration.

Responsive tables respond to their actual work pane. Tracking records switch to two-column labeled cards below pane width (1040px), then one column below (520px). Management records use container queries at (1280px) and (600px), with viewport fallbacks when container queries are unsupported. Preserve original table headings in the accessibility tree and keep every field and action reachable. Ordinary wide native lists retain bounded horizontal scrolling where their existing adapter requires it.

The full-screen decoration editor retains the actual page preview, block list and properties as distinct working regions with independent scrolling. At narrow widths, use the preview/settings pane switch rather than shrinking the page or stacking a second full work surface underneath it. Keep this workspace switch separate from the storefront's computer/mobile preview choice. The page keeps its real device width and storefront colors; controls and the block library use the neutral workspace palette. Searchable library cards use authored SVGs and clear names in three columns where space permits.

Preview refreshes retain the last successful frame while a candidate loads. A failed candidate exposes retry without removing that usable frame. Save and preview feedback occupy a flat status row in normal flow; they must not cover the pane switch, toolbar or form controls.

Both login forms use a compact centered column with width `min(384px, calc(100% - 40px))`. Their top spacing reduces from (80px) to (44px) below (480px), and panel padding reduces from (28px) to (24px).

**The Reachable Record Rule.** Reflow dense records and controls around the available pane; never hide a field or action to fit a smaller viewport.

## Elevation & Depth

Ordinary cards, tables and form panels are flat. White surfaces, soft washes and hairline boundaries separate regions. Sticky headers use a one-pixel shadow as a divider, not as a floating slab. The customer overview intentionally removes internal card borders and rounding where sections share a continuous working surface.

### Shadow Vocabulary

- **Toolbar dropdown:** customer (`0 8px 24px #1818181a`) and management (`0 8px 24px #00000014`) retain their respective ambient shadows.
- **Customer dialog:** (`0 24px 64px #18181826`) separates a modal from the working surface.
- **Editor publish menu:** (`0 8px 28px rgb(0 0 0 / 14%)`) signals a transient menu.
- **Editor preview frame:** (`0 5px 22px rgb(0 0 0 / 8%)`) separates the actual page preview from its tool canvas.
- **Editor notice:** flat, in-flow status text with a quiet boundary; no floating notice shadow.
- **Marketing save bar:** (`0 4px 16px #1818180a`) is a quiet existing action-bar exception.

**The Flat Work Surface Rule.** Keep ordinary information surfaces flat; use ambient depth for transient layers, preview frames and the existing save-bar exception.

## Shapes

Controls use the frontmatter control radius; compact status tags and some native controls use the compact radius. Reused customer feature cards, Woo cards and customer dialogs use the feature radius. Table wrappers, form panels, product editor boxes, management panels and login forms use the panel radius. Nested overview regions intentionally become square and borderless within their parent surface. A panel token is not a mandate to round every feature card alike.

Preserve functional native shapes: circular radios and account marks, pill switches, and joined publish buttons with complementary corners. The customer brand mark is (8px) rounded; management and login marks use (6px). Hairline borders establish the common edge language.

## Components

### Buttons

Compact and explicit. Primary buttons use Operating Ink with white text; secondary buttons use a white surface and a control stroke. Standard workspace buttons have minimum height (36px), the frontmatter control role and the shared button padding. Login submit buttons are full width with minimum height (40px); management small buttons use (30px).

Primary hover shifts to Pressed Charcoal. Secondary hover uses a soft wash and stronger border. Press feedback moves an enabled action down (1px). Workspace transitions are usually (180ms); the customer shell uses `cubic-bezier(.22,1,.36,1)`, while overview, product and decoration controls use `cubic-bezier(.16,1,.3,1)` for their opacity and press feedback. Native disabled states keep their existing semantics and muted presentation.

### Inputs / Fields

White backgrounds, visible neutral strokes and gently rounded corners. Customer and management fields keep their respective frontmatter padding and font sizes. Standard fields have minimum height (36px); login fields have minimum height (44px), and the mobile product title field uses (48px). Widths stay bounded by their working pane.

Keyboard focus remains explicit: a dark (2px) outline with an offset on workspace actions, and either a dark outline or a pale neutral outline plus dark border on fields according to their owning stylesheet. The product title retains its dedicated neutral focus ring. Do not replace the native checkbox, password visibility control, file input, Select2 behavior or editor toolbar with decorative replicas.

Decoration properties use ordered, compact content/layout/style/playback panels. Repeated items may supply their own panels; fields without that metadata retain their existing order. Numeric ranges keep a precise number input, preserve legal legacy values outside a common slider range, and display seconds while retaining millisecond storage where required. Computer/mobile paired numeric rows are opt-in for safely interpretable top-level device values; inheritance stays explicit and quiet, and legacy mixed values retain the existing single-device control. Do not change stored content or item IDs merely by opening a panel.

Site-link selection resolves real public WordPress targets before copying a URL into the existing link field. Keep manual URLs and button labels intact, and make cancellation, failure and retry predictable. Resource selection dialogs use protected keyboard focus, return it to their trigger, and remain usable at narrow widths. Internal review provenance, notes and edit history remain confined to merchant administration.

### Navigation

Both rails stay light with compact (13px/20px) links when open, current, hovered or focused. Menu panels use (#fafafa), open/current parent items use (#eaeaea), selected child items use (#e5e5e5), and hover/focus feedback uses (#f0f0f0); state text is (#171717). Selected items retain their neutral selection wash during hover and focus. Keyboard focus uses a (2px) outline in (#737373) inset with outline-offset (-2px). Preserve each workspace's native navigation structure, folded flyouts and compact density. Primary business buttons remain dark. Page tabs use a thin dark bottom rule; period and device selectors use contained neutral selected states appropriate to their context.

### Chips

Neutral operational chips use Status Ink on Status Wash with a compact radius. The frontmatter chip describes the shared customer order/operations shape. Management badges are slightly denser, with padding (3px 7px) and type (11px/17px). Real failures and waiting states retain their semantic text and pale background pairs. A chip reports a state; it is not an additional primary action.

### Cards / Containers

Customer feature cards use the feature radius and commonly use panel padding. Management cards use the panel radius with section padding. These reduce to mobile padding when the owning stylesheet specifies it. Product editor boxes, tables and settings forms use the panel radius. Operations panels use padding (22px), reducing to (18px) below (600px). Nested operational regions may deliberately remove the outer border, background and rounding to form a continuous surface.

### Metric Strips and Records

Metrics share a single parent surface instead of becoming a row of unrelated elevated cards. Use real data, tabular numerals and subdued labels. Preserve empty states when data is absent. Record cards present original column labels above values and keep their actions attached to the same record.

### Login

Both login pages use the same neutral canvas, white panel and local typography. The management login additionally shows the explicit “管理后台” identity beside VialCove. Password visibility, remember-me, recovery and language controls remain native and keyboard accessible.

Motion follows a deliberate click or opening. Dialogs use (180–220ms) entry feedback; switches may retain their existing (300ms) transform. Do not animate page geometry or repeat entry animation on every preview refresh. Every scoped workspace and login stylesheet honors `prefers-reduced-motion: reduce` by disabling animation and transition feedback.

## Do's and Don'ts

### Do:

- **Do** use the owning workspace's neutral canvas, font alias, title role and native navigation structure.
- **Do** use flat surfaces, alignment and type weight to make real actions and data legible.
- **Do** retain visible keyboard focus and reduced motion support.
- **Do** reflow complete records around the available pane while preserving headings, fields and actions.
- **Do** preserve native WordPress and WooCommerce controls, form semantics and save behavior.
- **Do** show truthful empty states and retain the actual colors of uploaded content and storefront previews.

### Don't:

- **Don't** apply this admin system to the public storefront without a separate request.
- **Don't** invent metrics, inventory, reviews, qualifications or other business evidence for a layout.
- **Don't** standardize every card to the panel radius; feature cards and native components have established exceptions.
- **Don't** add decorative accent colors, gradients or elevated card stacks to ordinary operational surfaces.
- **Don't** animate layout dimensions, data refreshes or full-page entrances.
- **Don't** promote native icon-font usage into a new icon system or add glyph icons to imitate it.

**Not canonized:** inherited native component geometry and icon fonts remain compatibility details, not prescriptions for new custom surfaces. Remaining native variations are not repaired by this documentation. This record also does not certify runtime contrast or complete feature behavior; those belong to the separate browser and business verification evidence.
