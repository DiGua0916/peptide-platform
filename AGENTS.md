# Repository collaboration rules

## Scope

This is a WordPress project skeleton for a student competition. Read docs/REQUIREMENTS.md, docs/CONTRACT.md, docs/TASKS.md, and your role's handoff before changing implementation.

The user has approved WordPress and the collaboration repository. Product category, intended customer, competition rules, final branding, commerce scope, and source template are not yet confirmed. Do not treat draft proposals as approved requirements.

## Ownership

- Codex owns wp-content/themes/peptide-site/ and docs/handoffs/frontend.md.
- Claude owns wp-content/plugins/peptide-core/ and docs/handoffs/backend.md.
- Shared requirements, contracts, environment configuration, and task definitions require explicit coordination between roles. Record a proposed shared change in the role handoff before integration.
- Use codex/frontend and claude/backend worktrees for development. The main checkout is the integration copy.
- Keep each worktree on its assigned branch. Do not reset, overwrite, clean, or force-push another role's work.
- Each agent must receive its own task. Shared files do not automatically invoke or synchronize the other agent's chat.

## Architecture

- Use a WordPress theme for presentation and a project plugin for persistent business behavior.
- Keep WordPress and third-party plugin core files unchanged.
- Prefer existing WordPress capabilities for users, permissions, and media.
- WooCommerce is a candidate for commerce, not an approved dependency yet. If selected, use its public APIs and avoid a duplicate order or product system.
- Do not select a separate backend framework without an explicit requirement.
- Add only necessary dependencies. Document why each is needed.

## Product content

Do not invent partners, certifications, efficacy claims, customer reviews, inventory, delivery promises, or laboratory results. Clearly mark synthetic demonstration records. Do not infer intended human use or a research-only classification.

## Security and environments

Keep credentials, database dumps, real customer data, uploads, and third-party commercial archives outside Git. Server-side operations must validate inputs and permissions; escape rendered data. Each development environment should have its own database and runtime configuration.

## Verification and handoff

Run checks appropriate to changed behavior. State which checks actually ran; never report an unrun test as passing. Update your handoff with scope, changed files, contract changes, checks, next actions, and commit reference. Do not claim deployment, Claude integration, or a working WordPress instance until verified.
