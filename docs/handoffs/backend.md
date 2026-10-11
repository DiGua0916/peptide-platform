# 后端交接

状态：BE-01 方案已修订为 v2（2026-10-11，采用 WooCommerce），等待 Codex 评审；尚未编写插件实现，没有可调用的接口。当前有效方案：[backend-plan-v2.md](backend-plan-v2.md)（只写变化部分，其余沿用 v1）。

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

### BE-01 v2 修订 · 2026-10-11 · Claude

- **触发**：地瓜在 Claude 对话中确认：之后要真实运营并在线下单；产品类别、客户、真实资料“都有”（尚未提供具体内容）；截止时间不用管；不了解 LocalWP。
- **范围**：只修订方案，未安装任何软件，未写插件代码，未改共享文件和前端。
- **修改文件**：新增 `docs/handoffs/backend-plan-v2.md`；更新本文件。
- **方案变化**：
  - 采用 WooCommerce：商品、购物车、结账、订单、库存、顾客都用 WooCommerce；`pc_product` / `pc_category` 取消。
  - peptide-core 只做批次、报告、订单行 ↔ 批次关联（发货时由管理员指定）、“订单所有者可下载本单批次报告”、Store API 扩展、结账合规确认项。
  - 库存只以 WooCommerce 为准，批次不再记库存；询价降为可选（BE-07）。
  - 角色改用 WooCommerce 的 `customer` / `shop_manager`。
  - 新增上线前合规清单（I 节），由用户负责确认。
- **接口影响**（需同步到共享契约，未改 docs/CONTRACT.md）：
  1. 商品列表 / 详情 / 购物车 / 结账改用 WooCommerce 模板或 Store API；peptide/v1 只保留 `/site`、`/products/{slug}/batches`、`/batches/lookup`、`/reports/{id}/file`、`/me/orders/{order_number}/batches`。
  2. Store API 商品数据附加 `extensions.peptide_core`（is_demo、specs、batch_count、report_state）。
  3. 新前端状态：订单行“发货后可查看批次”（`batch: null`）；结账确认项 `checkout_ack`。
  4. docs/REQUIREMENTS.md 需记录用户的新决定；docs/TASKS.md 建议加入 ENV-01 细化与 BE-02～BE-07（v2 F'2 节）。
- **实际执行的检查**：读取群聊最新记录（Codex 无新消息）；`git status` 干净；用 `winget show Flywheel.Local` 查询 LocalWP 包信息（只查询，未下载）；人工核对 v2 与 v1、AGENTS.md 约束一致。
- **未验证**：所有接口与字段均未实现；Store API 按 slug 取商品的具体方式、可变商品方案未在实际 WooCommerce 中验证。
- **剩余问题**：v2 H' 节三个问题（是否同意安装 LocalWP；目标客户与销售国家；真实资料提供时间）。
- **下一步**：用户同意后执行 ENV-01（安装 LocalWP、建站、装 WooCommerce），然后 BE-02。