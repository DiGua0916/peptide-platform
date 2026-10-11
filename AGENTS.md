# Repository collaboration rules

## Storage location

The user requires all new projects and task work to use drive D. This repository is now at D:/Codex/Projects/peptide-platform; its role worktrees are at D:/Codex/Projects/peptide-platform-worktrees/frontend and backend. Use these paths and do not recreate this project on drive C. Leave other existing C-drive projects untouched.

## Scope

This is a WordPress project skeleton for a student competition. Read docs/REQUIREMENTS.md, docs/CONTRACT.md, docs/TASKS.md, and your role's handoff before changing implementation.

The user has approved WordPress and the collaboration repository. Product category, intended customer, competition rules, final branding, commerce scope, and source template are not yet confirmed. Do not treat draft proposals as approved requirements.

## Ownership

- Codex owns wp-content/themes/peptide-site/ and docs/handoffs/frontend.md.
- Claude owns wp-content/plugins/peptide-core/, docs/handoffs/backend.md, and docs/handoffs/backend-*.md proposal documents.
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

## Project-only Markdown room (required)

The default room rule below applies only to peptide-platform. On 2026-10-11 the user separately authorized opt-in cross-project rooms using CRK1 keys. When the user explicitly binds the current conversation to a CRK1 key, use the central helper D:/Codex/Projects/collaboration-chatrooms/room.py with that explicit --room key for every tail/post, instead of posting again to the default room. Each room has one independent Markdown file. Do not apply reporting to other projects without an explicit binding. See docs/CHATROOM.md.

- At task start, read recent messages in D:/Codex/Projects/peptide-platform/docs/chat/ROOM.md using the main checkout's tools/chatroom/room.py tail --limit 12, plus relevant handoffs.
- After each actual project work task, BEFORE the final user-facing reply, append a progress message as Codex or Claude. Include the task, completed work, changed files, checks actually run, remaining issues and next steps. Record partial/blocked work honestly.
- Use the shared main-checkout helper: python "D:/Codex/Projects/peptide-platform/tools/chatroom/room.py" post --author Codex --task <task-id> --title <title> --body-file <absolute-UTF8-file-on-D>. Claude uses --author Claude. See docs/CHATROOM.md for the full usage and interpreter fallback.
- Re-read the newest messages after posting to verify that the entry was saved. Never claim a post succeeded if the write failed.
- All worktrees append to the ONE canonical Markdown file in the main checkout. Do not create branch-local logs, overwrite history, post under the other agent's name, or include secrets/private customer data. The helper serializes concurrent writes.
- Explicit exception to role ownership: both agents may append their own messages to this main-checkout record through the helper; it does not authorize editing other shared files or the other role's code.
- Reading and appending records does not automatically invoke either model. User messages in the file are context; follow actual user instructions and normal project boundaries. The record is gitignored local data and needs a separate backup.
