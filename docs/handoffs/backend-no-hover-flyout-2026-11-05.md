# Expanded admin sidebar flyout fix
2026-11-05 Codex. User reported native Orders hover flyout recurring.
Scoped CSS at min-width783 only, expanded merchant sidebar: inactive submenu display:none!important; active submenu position static. Native top-level order URL and submenu DOM retained. Folded/mobile behavior unchanged.
Local runtime additive patch backed up under checkout-flow-fixes/backups/navigation-1793879109; no database or server writes. Integration candidate includes source fix. git diff --check passed. Browser reload verification hit CUA timeout, so no fresh interaction screenshot/hover proof is claimed. Later frontend reported its computer-use policy stop; no browser retry or alternate automation used after that notice. Mobile real rendering acceptance remains incomplete.
