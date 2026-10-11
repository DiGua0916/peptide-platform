# 后端交接

状态：BE-01 方案已提交评审（2026-10-11）；尚未编写插件实现，没有可调用的接口。

## 首次需要提出的方案

- 产品、批次和检测报告如何存储与关联。
- 是否需要 WooCommerce，以及选择依据。
- 访客、采购用户、管理员分别能读取或修改什么。
- 前端需要哪些查询或写入操作。
- 本地开发运行环境和检查方式的建议。

请明确区分方案、已实现功能和已验证结果。

以上各项已在 [backend-plan-v1.md](backend-plan-v1.md) 中给出方案（见下方 BE-01 记录）。

## 验证

尚未安装 WordPress，未执行后端测试。BE-01 只做了文档层面的检查（见下方记录）。

## 后续每次交接需记录

任务编号、提交、修改文件、接口影响、实际检查结果、剩余问题、下一步。

---

## 交接记录

### BE-01 · 2026-10-11 · Claude

- **范围**：后端架构、数据对象、WooCommerce 取舍、接口草案、权限建议、最小流程、分阶段计划、本地环境建议。只写方案，未安装依赖、未接支付、未发信、未发布、未改主题。
- **工作目录与分支**：`D:\Codex\Projects\peptide-platform-worktrees\backend`，`claude/backend`（读取 `.git` 引用确认；开始时与 main、codex/frontend 同指向 `7037186`）。
- **修改文件**：
  - 新增 `docs/handoffs/backend-plan-v1.md`
  - 更新 `docs/handoffs/backend.md`（本文件）
- **提交**：未提交。本机 PATH 中没有 `git`，常见安装位置也未找到，无法执行 `git status` / `git commit`。两份文件已在工作目录中，待 Git 可用后提交，或由集成负责人提交。
- **方案要点**：
  - 首版不引入 WooCommerce，采用询价流程；需要在线下单 / 支付演示时再接入，主题只经插件服务层取数，切换不改接口字段。
  - 存储：自定义文章类型 `pc_product` / `pc_batch` / `pc_report` / `pc_inquiry` + 分类法 `pc_category`；报告只挂批次，支持版本、状态、四级访问控制，文件走受控下载。
  - 接口命名空间建议 `peptide/v1`，8 个接口（5 个只读 + 询价提交 + 我的询价两个），统一 `WP_Error` 错误格式和字段级原因码；空结果返回 200。
  - 所有记录带 `is_demo`；不设用途 / 剂量 / 功效字段；机构名为空时不显示。
- **接口影响 / 对共享契约的建议**（未改 docs/CONTRACT.md，等集成负责人决定）：
  1. 把 backend-plan-v1.md 第 C、D 节合入 CONTRACT.md 作为 v0.1 草案。
  2. 前端 URL 用 `slug` 和 `batch_no`，不要用数字 ID（各环境 ID 不同）。
  3. 新增前端状态：报告 `restricted`（登录 / 审核后可看）、`pending`（报告整理中）、询价幂等重放、429 限流、nonce 过期。
  4. 在 TASKS.md 增加 BE-02～BE-05（见方案 F2）。
- **实际执行的检查**：
  - 读取 AGENTS.md、CLAUDE.md、docs/ 下全部文档、两个 README、.gitignore。
  - 读取 `.git/worktrees/backend/HEAD` 与 `refs/heads/*`，确认分支。
  - 对比工作目录文件与 Git 索引时间戳：开始时没有比索引更新的文件（近似“工作目录干净”，非 `git status` 结果）。
  - 人工核对方案与 AGENTS.md / REQUIREMENTS.md / CONTRACT.md 的约束一致（未预设用途、WooCommerce 未作为既定依赖、演示数据标注、秘密不进浏览器）。
- **未验证**：所有接口与字段均未实现、未运行；LocalWP 路线、目录联接命令、版本建议未在本机验证；`git status` 未运行。
- **剩余问题**：见方案 H 节三个问题（项目定位与交易范围；产品类别、客户与真实资料；本地环境、Git 与截止时间）。
- **下一步**：Codex 评审方案 → 用户回答 H 节问题 → ENV-01 搭建本地 WordPress → BE-02 插件骨架与数据模型。
