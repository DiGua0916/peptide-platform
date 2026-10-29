# Repository collaboration rules

## Storage location

The user requires all new projects and task work to use drive D. This repository is now at D:/Codex/Projects/peptide-platform; its role worktrees are at D:/Codex/Projects/peptide-platform-worktrees/frontend and backend. Use these paths and do not recreate this project on drive C. Leave other existing C-drive projects untouched.

## Scope

This is the VialCove WordPress/WooCommerce project for a student competition and planned real operation. Read docs/REQUIREMENTS.md, docs/CONTRACT.md, docs/TASKS.md, and your role's handoff before changing implementation.

WordPress, WooCommerce ordering, VialCove branding and the purchased Lunira source template are confirmed. Product use, real product data, competition rules and publication wording remain to be supplied. Do not treat draft proposals or template content as business facts.

## Ownership

- Latest user decision on 2026-10-11: the proposed frontend/backend swap was cancelled. Codex continues frontend work; Claude continues backend work. Keep the existing A/B demo code and records for reference; do not expand that demo without a new user request.
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
- WooCommerce owns products, stock, cart and orders. Use its public APIs; preserve Blocks checkout because peptide-core's required acknowledgement uses Additional Checkout Fields. Do not build a duplicate order or product system.
- Do not select a separate backend framework without an explicit requirement.
- Add only necessary dependencies. Document why each is needed.

## Product content

Do not invent partners, certifications, efficacy claims, customer reviews, inventory, delivery promises, or laboratory results. Clearly mark synthetic demonstration records. Do not infer intended human use or a research-only classification.

## Security and environments

Keep credentials, database dumps, real customer data, uploads, and third-party commercial archives outside Git. Server-side operations must validate inputs and permissions; escape rendered data. Separate environments use separate databases. The two roles explicitly agreed in INT-01 to share the current LocalWP development site: coordinate setting changes, restore temporary fixtures and preserve existing order #54/refund #55. Never run demo purge as test cleanup.

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

## 成员移出或群解散的汇报例外

读取时在 tail 中带 --author 自己的身份。若返回 membership=removed、room_not_member、status=dissolved 或相应停止提示，解除本会话绑定并停止向该群汇报；此例外优先于本项目的默认汇报要求。保留原已授权的项目工作，不改绑其他群、不重建群，不因发言失败就自动加入。用户重新邀请后才执行 join --code <完整口令> --author <自己的身份>。省略 --author 的 join 只读，不登记入群。

## Codex自动阶段同步（用户2026-10-30授权）

本约定用于本项目的Codex工作，跨本项目后续聊天适用。用户已要求每完成一段工作就提交并同步GitHub，不再等用户逐次提醒；不表示整个功能或版本已经完成。

- 开始新阶段前先fetch并核对main、当前角色分支和实际协作者分支的新提交/交接。不要盲目把pull执行在含有未保护改动的工作树里；保护在途文件，必要时先备份/准确标注的检查点提交，再正常合并。
- 每完成可验证阶段，更新角色handoff和必要合同，记录实际检查、限制、未完成项与下一步；运行相关检查后commit，并push自己的角色分支。验证通过的协作整合同时合入并push main，使双方能从GitHub取得最新共同进度。无需再问用户是否提交/推送。
- 完整目标尚未完成时，可以提交明确标注的开发检查点；不得把源码存在、纯fixture或部分验收说成正式发布。不要因目标很大就长期积压所有已验证代码不提交。
- 保留协作者完整历史与功能，冲突按双方实际意图合并；不使用force-push/reset/clean覆盖他人工作，不直接改写协作者分支。对他人仍在写入的工作树先协调，不擅自提交其半写入文件。
- 不提交数据库、上传素材、账号、Cookie、nonce、密码/API Key/私钥、私有备份或测试站运行文件；合成测试业务记录只留隔离测试实例。现有源码测试和字体许可证属于版本化开发文件，不等同于店铺演示数据。
- 遇到真正的身份验证/分支保护/无法安全解决的语义冲突，说明具体原因及已保护的进度；不声称push成功。Git配置缺少身份时可沿用项目既有Codex <codex@localhost>身份，通过单次git -c参数使用，不改用户全局Git身份。
- GitHub提交/交接代表进度可拉取，不代表同事已经阅读或确认。此规则不授权额外对外发消息、不触发默认群聊、不自动部署网站。
