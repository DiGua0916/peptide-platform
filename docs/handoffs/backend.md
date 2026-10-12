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
### BE-02 插件骨架 · 2026-10-11 · Claude

- **用户安排**：支付和物流先由 Codex 出 UI，后端暂时搁置这部分（已在群里转告 Codex），先做其他后端功能。
- **范围**：编写 peptide-core 插件骨架。没有安装任何软件，没有改共享文件和前端，没有涉及支付和物流。
- **修改文件**（均在 `wp-content/plugins/peptide-core/`）：`peptide-core.php`、`uninstall.php`、`README.md`、`includes/` 下 autoload、plugin、installer、capabilities、post-types、meta、batches、reports、private-files、admin-notices，`includes/admin/` 下 product-panel、batch-metabox、report-metabox、list-columns，`includes/cli/` 下 demo-command、demo-pdf。
- **实现内容**：
  - 依赖 WooCommerce（`Requires Plugins` 头 + 运行时检查）；声明 HPOS 兼容。
  - 批次 `pc_batch`、报告 `pc_report` 两种不公开的自定义文章类型，后台挂在“产品”菜单下；独立能力授予 administrator 和 shop_manager。
  - 批次：批次号自动转大写、格式校验（3–40 位字母数字和连字符）、全局唯一；必须关联 WooCommerce 商品（不能是变体）；可选适用包装规格；有错误时退回草稿并提示。
  - 报告：版本号自动分配；发布时同批次同类型的旧版本自动变为 superseded；无批次或无文件不能发布；文件仅限 PDF / PNG / JPEG、20 MB 以内，存入私有目录并使用随机文件名；删除报告时删除文件。
  - 商品编辑页新增 “Peptide data” 面板：演示标记、规格表（每行“名称 | 值 | 单位”）、该商品的批次列表。
  - `wp peptide demo seed / purge`：演示商品、批次和带水印的演示 PDF，全部带演示标记，`purge` 只删除带演示标记的记录。
- **与方案的差异**：报告状态存在元数据 `_pc_report_status` 中，与 WordPress 的发布状态无关；批次号统一大写存储（方案只写了格式）。
- **接口影响**：无。本次没有前台接口，主题暂时取不到批次和报告数据（BE-03 再做）。
- **实际执行的检查**：读取群聊最新记录；`git status` 确认改动范围。**本机没有 PHP，未做语法检查，也没有在 WordPress 中运行，以上功能全部未经验证。**
- **未验证**：插件启用、能力授予、后台表单保存、文件上传、版本与取代逻辑、演示数据命令、生成的 PDF 能否正常打开。
- **剩余问题**：需要本地环境（LocalWP + WooCommerce）才能验证；用户尚未答复是否安装 LocalWP。
- **下一步**：环境就绪后先跑通 BE-02（启用、`demo seed`、后台录入一品多批多报告），再做 BE-03 只读接口。
### 角色交接 · 2026-10-11 · Claude

- **用户安排**：Claude 改做前端；后端工作打包交出，由用户转交给接手方（预计 Codex）。
- **交付包**：`D:\Codex\Tasks\peptide-platform\outputs\Claude后端交付包-2026-10-11\`（含交接说明、方案副本、项目记忆副本和压缩包）。仓库中的文件是唯一正本，交付包里的是副本。
- **后端当前状态**：BE-01 方案 v2 已提交；BE-02 插件骨架代码已提交，但**从未运行过**；LocalWP 已安装，但后端网站还没建；支付和物流等 Codex 出 UI 后再做。
- **最后的后端提交**：`de6c7e3`（claude/backend）。本条记录随交接提交一并入库。
### 交接取消 · 2026-10-11 · Claude

- 用户收回分工调整：**Claude 继续负责后端**，上一条“角色交接”作废。
- 交付包目录 `D:\Codex\Tasks\peptide-platform\outputs\Claude后端交付包-2026-10-11\` 及其 zip 只是当时的快照，不再维护，以仓库文件为准。
- 当前后端状态不变：等用户在 LocalWP 中建好 `peptide-backend` 网站，然后完成 ENV-01、验证 BE-02。
### BE-02 语法检查 · 2026-10-12 · Claude

- 找到了 LocalWP 自带的 PHP 8.2.29（`%APPDATA%\Local\lightning-services\php-8.2.29+0\bin\win64\php.exe`）。
- **实际检查**：对 peptide-core 全部 18 个 PHP 文件运行 `php -n -l`，均无语法错误。单独运行演示 PDF 生成器并核对文件结构：xref 位置、5 个对象的偏移量、流长度均正确，生成文件 791 字节。
- **仍未验证**：插件在 WordPress 和 WooCommerce 中启用与运行、后台表单、文件上传、演示数据命令。需要先在 LocalWP 中建好 `peptide-backend` 网站。

### ENV-01 完成 + BE-02 运行验证 · 2026-10-12 · Claude

- **环境迁移到 D 盘**（用户要求“全部移动到 D 盘，不在 C 盘构建项目”）：
  - 站点 `vialcovepeptides.com` 从 `C:\Users\JingYue\Local Sites\vialcovepeptidescom` 迁到 `D:\Codex\Sites\vialcovepeptidescom`，同时更新了 Local 的 sites.json（备份为 sites.json.bak-2026-10-12）。
  - Local 数据（`%APPDATA%\Local`，约 3.1 GB，含数据库运行目录）迁到 `D:\Codex\LocalWP\data`；Local 程序（`%LOCALAPPDATA%\Programs\Local`，约 2.3 GB）迁到 `D:\Codex\LocalWP\app`。原位置改为目录联接，文件实际都在 D 盘。
  - `C:\Users\JingYue\Local Sites` 改为联接到 `D:\Codex\Sites`，以后用默认位置新建的站点也会落在 D 盘。
  - 迁移前通过 Local GraphQL 正常停止站点，复制后核对文件数和字节数一致，再删除 C 盘原件。迁移后站点由 Local 正常启动，Apache 文档根目录为 D 盘路径，首页返回 200。
- **站点配置**：WordPress 7.1.3、PHP 8.3.29、Apache 2.4.43、MySQL 8.0.35；安装并启用 WooCommerce 11.2.1（WordPress 官方插件库）；peptide-core 通过目录联接挂载；`PEPTIDE_CORE_PRIVATE_DIR` 设在网站根目录之外；开启 `WP_DEBUG_LOG`。
- **实际检查**：
  - `wp plugin activate peptide-core` 成功；administrator、shop_manager 各获得 20 项 pc_ 能力。
  - `wp peptide demo seed` 创建 2 个商品、3 个批次、2 份报告，私有目录里出现 2 个演示 PDF 和防护文件；重复执行 seed 被正确拒绝；purge 删除 7 条记录和对应文件，之后能重新 seed。
  - 新增冒烟测试 `tests/smoke-save-logic.php`，9 项全部通过：重复批次号被拒绝并退回草稿、批次号规范化、标题同步、报告版本号递增、旧版本被取代并指向新版本、无文件报告不能发布、测试清理后演示文件仍在。
  - `wp-content/debug.log` 没有出现任何错误或警告。
- **未验证**：后台界面没有人工点验（需要用户用自己的管理员账号登录）；没有通过浏览器上传文件；Nginx 下的行为。
- **修改文件**：`wp-content/plugins/peptide-core/README.md`、新增 `wp-content/plugins/peptide-core/tests/smoke-save-logic.php`、本文件。
- **下一步**：用户在后台目视检查“产品 → Batches / Test reports”和商品的 Peptide data 面板；然后做 BE-03 只读接口。
