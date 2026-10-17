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

### BE-03 只读接口 · 2026-10-12 · Claude

- **范围**：Store API 扩展、`peptide/v1` 只读接口、报告受控下载、主题 PHP 读取函数。没有涉及订单、支付、物流。
- **新增文件**：`includes/class-settings.php`、`class-access.php`、`class-catalog.php`、`functions.php`，`includes/api/class-rest-controller.php`、`class-errors.php`、`class-store-api.php`，`tests/smoke-read-api.php`。修改：`includes/class-plugin.php`（注册新模块）、插件 README。
- **接口**（与方案 v1/v2 一致，以下是实现时补充的细节，需要同步到共享契约）：
  1. `GET /products/{slug}/batches` 返回 `{ product: {id, slug, name, is_demo}, batches: [...] }`。
  2. 批次对象包含：`id`、`batch_no`、`product_id`、`variation_ids`、`status`、`is_demo`、`manufactured_on`、`retest_or_expiry_on`、`origin_note`、`report_state`、`reports`。批次查询结果另附 `product`。
  3. 报告对象在 v1 C4 的基础上加了 `batch_id` 和 `access_reason`（public / registered / approved / staff，之后还会有 order_owner）。
  4. `access=admin` 的报告对非员工完全不出现（不是显示为锁定）。
  5. `download_url` 是绝对地址；已登录用户的地址附带 `_wpnonce`，这样普通链接也能带上登录身份。
  6. 字段级原因码新增 `invalid_format`（批次号格式错误）。
  7. 批次查询限流为每个 IP 每 10 分钟 60 次。
  8. 预留过滤器 `peptide_core_report_access`，供 BE-04 加入“订单所有者”规则。
- **实际检查**：
  - 全部 PHP 文件 `php -l` 通过。
  - 用 curl 走真实 HTTP（Apache）测试：`/site` 200；商品批次 200；不存在的商品 404；批次查询 200 / 404 / 400（缺参数 `required`、格式错 `invalid_format`）；公开报告下载 200（application/pdf、attachment、nosniff，文件以 %PDF 开头）；访客下载 registered 报告 401；不存在的报告 404；Store API 商品带 `extensions.peptide_core`；DEMO-B-2401 为 `pending`；连续请求触发 429（带 `retry_after`）。
  - `tests/smoke-read-api.php` 18 项全部通过：访客、顾客、已审核顾客、员工四种身份；旧版本隐藏与 `include_history`；撤回 410、草稿 404、未登录 401、权限不足 403；隐藏批次不可见；测试账号和数据清理后恢复原状。
  - `tests/smoke-save-logic.php` 回归 9 项通过；`debug.log` 没有产生。
- **未验证**：已登录用户在浏览器里点击带 nonce 的下载链接（只验证了地址中带 nonce，以及服务端的权限判断）；Store API 在购物车 / 结账接口中的表现（扩展只挂在商品接口上）。
- **下一步**：BE-04 订单行与批次关联。

### 后台中文化 · 2026-10-12 · Claude

- **用户要求**：后台改成中文。
- **站点操作**（只改本地站点，不在仓库里）：安装 WordPress 核心和 WooCommerce 的 zh_CN 语言包；**只把 DiGua 这个账号**的个人语言设为 zh_CN。站点语言保持英文（WPLANG 为空），海外访客看到的前台和接口返回仍是英文。
- **插件改动**：新增 `languages/peptide-core.pot`、`peptide-core-zh_CN.po`、`peptide-core-zh_CN.mo`（104 条，全部已翻译）。`Plugin::load_textdomain()` 在 init 钩子（优先级 0）上按 `determine_locale()` 加载对应语言文件；用绝对路径加载，因为插件目录是目录联接挂载的。
- **实际检查**：以 DiGua 的语言加载，插件、WooCommerce、WordPress 核心的字符串都显示中文（批次信息 / 检测报告、订单、仪表盘）；站点语言仍是 en_US；访客请求接口的错误信息仍是英文；debug.log 没有产生。
- **以后新增可翻译字符串时**：先 `wp i18n make-pot` 重新生成 .pot，再把新条目补进 zh_CN.po，最后 `wp i18n make-mo` 编译。

### BE-04 订单与批次关联 · 2026-10-12 · Claude

- **范围**：发货时给订单行指定批次、订单所有者报告权限、我的订单接口、我的账户和邮件中显示批次。没有涉及支付和物流。
- **新增文件**：`includes/class-order-batches.php`、`includes/admin/class-order-metabox.php`、`tests/smoke-order-batches.php`。修改：`class-plugin.php`、`class-capabilities.php`（新增 `pc_assign_batches`）、`class-installer.php`（`maybe_upgrade`）、`api/class-rest-controller.php`（新增 `/me/orders/{n}/batches`；订单所有者可以下载隐藏批次的报告）、`peptide-core.php`（版本 0.2.0）、翻译文件、README。
- **设计要点**：
  - 订单行元数据 `_pc_batch_id`；订单上每个批次存一条 `_pc_batch_id` 元数据，作为反查索引（HPOS 下用 `wc_get_orders` 的 `meta_query` 查询）。
  - 只有**已付款**订单（`wc_get_is_paid_statuses()`）才算“收到过批次”；待付款、已取消的订单不算。
  - 订单所有者规则通过 BE-03 预留的过滤器 `peptide_core_report_access` 加入，不覆盖“仅员工”级别。
  - 修改批次分配时自动写订单备注，方便追溯谁改了什么。
- **接口影响**（需同步到共享契约）：`GET /me/orders/{order_number}/batches` 的返回形状与 v2 D'2 的示例一致：`{ order_number, items: [{ product: {slug, name}, variation_label, quantity, batch: null | {batch_no, is_demo, report_state, reports} }] }`；报告的 `access_reason` 可能是 `order_owner`。我的账户订单页的商品行下新增 `.pc-order-batch` 区块，Codex 可以按需调整样式。
- **实际检查**：
  - 全部 PHP 文件 `php -l` 通过；版本升级后 shop_manager 自动获得 `pc_assign_batches`。
  - `tests/smoke-order-batches.php` 18 项全部通过：候选批次过滤、错误批次被拒绝、保存与索引、订单备注、订单所有者获得 order_owner 权限、其他顾客仍需审核、仅员工报告不放开、未付款订单不算、接口 200 / 404 / 401、我的账户显示批次和链接、纯文本邮件只显示批次号、隐藏批次对非所有者返回 404、清理后恢复原状。
  - **真实 HTTP**（curl 带测试顾客的登录 Cookie 和 nonce 链接）：订单所有者下载**隐藏批次**的报告返回 200，得到 PDF；同一链接不带 Cookie 返回 403（WordPress 的 nonce 校验）；带 Cookie 但不带 nonce 时按访客处理，返回 404。这也补上了 BE-03 中“已登录用户点击带 nonce 的下载链接”这一项验证。
  - 三套冒烟测试回归：9 + 18 + 18 项全部通过；`debug.log` 没有产生；测试账号已清理（站点只剩 DiGua 一个用户）。
- **未验证**：后台订单页面板的实际显示（需要用户登录查看，HPOS 页面）；真实下单流程（支付未接）。
- **下一步**：BE-04b 后台“VialCove 工作台”；或者等用户提供文案后做 BE-05。

### BE-04b 后台工作台 · 2026-10-12 · Claude

- **用户要求**：先做工作台。
- **新增文件**：`includes/class-workbench.php`（数据查询，只读）、`includes/admin/class-dashboard.php`（面板渲染）、`tests/smoke-workbench.php`。修改：`class-plugin.php`、`class-settings.php`（新增 `brand_name`，默认 VialCove，只在后台使用，不出现在 `/site` 接口里）、翻译文件（新增 zh_CN 复数形式的支持）、README。
- **面板内容**：
  - 待指定批次的订单（处理中，且至少有一件商品没指定批次，列出最早的 5 单）；
  - 缺少已发布报告的公开批次（标出“报告整理中”的批次）；
  - 草稿报告数量；
  - 演示模式状态和演示数据数量（提示上线前要清除）；
  - 常用入口按钮。
  - 只有员工能看到这个面板；它会被放到第一列最上面；同时移除了“快速草稿”和“活动与新闻”面板。
- **实际检查**：`smoke-workbench.php` 13 项全部通过（面板注册与位置、默认面板移除、演示数据状态、订单出现和消失、草稿计数、订阅者看不到、清理）；四套测试回归 9 + 18 + 18 + 13 = 58 项全部通过；debug.log 没有产生；测试数据已清理（只有 1 个用户、0 个订单）；中文翻译（含复数）验证正确。
- **未验证**：浏览器里面板的实际样子（要地瓜刷新后台首页查看）。
- **下一步**：BE-05（需要地瓜提供用途声明和结账确认语）；或者先造一个演示订单，方便地瓜看到“批次分配”面板。

### 演示订单命令 · 2026-10-12 · Claude

- **用户要求**：造一个演示订单看看。
- 新增 `wp peptide demo order`：创建访客演示订单（虚构联系人 Demo Customer，邮箱用 example.com；Demo Peptide A ×2、Demo Peptide B ×1；状态为处理中；没有指定批次；带 `_pc_demo_seed` 标记）；创建时关闭新订单通知邮件。`purge` 会一并删除演示订单。
- **实际执行**：已在本地站点创建演示订单 #54，工作台“待指定批次的订单”计数变为 1；debug.log 没有产生；#54 没有触发邮件。
- **发现**：Mailpit 里有 7 封“新订单”通知邮件，来自之前冒烟测试创建的临时订单。邮件只在本地被拦截，没有真正发出。以后可以让测试脚本在运行时关闭邮件。

### 后台页面内对话框 · 2026-10-12 · Claude

- **用户要求**：退款时浏览器在页面顶部弹出的原生确认框（WooCommerce 用 `window.confirm` 实现）改成页面内的对话框。
- **新增文件**：`assets/admin-dialogs.js`、`assets/admin-dialogs.css`、`includes/admin/class-dialogs.php`。修改：`class-plugin.php`、翻译文件。
- **原理**：原生 confirm 是同步的，所以采用“拦截后重放”：点击时在捕获阶段临时替换 `window.confirm`，让它返回 false 并记下提示文字，原操作因此被取消；然后弹出页面内对话框，用户点“确定”后在放行状态下重新点击同一个元素，原操作照常执行。`window.alert` 直接换成页面内提示框。所有后台页面生效。不是由点击触发的 confirm（极少见）仍使用原生对话框。支持 Esc 取消、焦点限制在对话框内、点遮罩取消。
- **实际检查**：在本地站点放了一个临时测试页（模拟 WooCommerce 在冒泡阶段调用 confirm 的处理函数），用内置浏览器真实点击测试：弹出页面内对话框，原操作被拦住；点“确定”后原操作执行且只执行一次，原生 confirm 已恢复；按 Esc 取消后原操作没有执行；alert 显示为页面内提示框，按回车可以关闭。测试页已删除（再访问返回 404）。脚本在后台正常加载，中文文案（请确认 / 提示 / 确定 / 取消）正确。
- **未验证**：在真实的 WooCommerce 订单页里点退款（要地瓜刷新后台再试）。
- **顺带发现**：本地站点的 debug.log 里有一条 Codex 主题 `functions.php:52` 的致命错误（09:36 UTC，`stdClass` 被当成数组使用，因为 Store API 的 extensions 是对象）。Codex 已经修复（改为先 JSON 往返转换），首页现在返回 200。另外，Codex 的主题已经通过目录联接挂到这个后端站点并启用了。

### BE-05 设置页与结账确认 · 2026-10-12 · Claude

- **范围**：插件设置页、结账确认复选框、订单上的确认快照。用户还没提供文案，所以设置页先做好，文案留空，用户以后在后台填写。
- **新增文件**：`includes/admin/class-settings-page.php`、`includes/class-checkout-ack.php`、`tests/smoke-settings-ack.php`。修改：`class-settings.php`（新增 `sanitize()`、`checkout_ack_label()`）、`class-capabilities.php`（新增只授予管理员的 `pc_manage_settings`）、`class-plugin.php`、`peptide-core.php`（版本 0.3.0）、翻译文件、README。
- **设计要点**：
  - 设置页在“WooCommerce → VialCove 设置”，用 Settings API 保存到 `peptide_core_settings`；`option_page_capability` 改为 `pc_manage_settings`，店铺经理看不到这个页面。
  - 结账确认使用 WooCommerce 官方的 Additional Checkout Fields API（必选复选框，位置 order）：前端由结账区块自动渲染，后端由 Store API 自动校验。本站结账页是区块结账（已确认）。
  - 订单快照 `_pc_checkout_ack = { label, accepted_at }` 保存顾客勾选时的原文，以后改文案也不影响旧订单。
  - 确认框文案只能填纯文本（结账复选框标签不支持 HTML）；`/site` 中的 `checkout_ack.label_html` 因此也是纯文本。
- **接口影响**：`/site` 的形状不变。@Codex：如果使用 WooCommerce 结账区块，复选框会自动出现；如果自己调用 Store API 的 `/checkout`，需要传 `additional_fields: { "peptide-core/checkout-ack": true }`。
- **实际检查**：
  - `smoke-settings-ack.php` 17 项全部通过：清理与校验（空品牌名保留原值、过滤 script、没有文案时不能开启确认）、`/site` 正确反映设置、字段注册为必选复选框、订单快照（原文和 UTC 时间）、后台显示、管理员有设置权限而店铺经理没有、清理后恢复原状。
  - **真实 HTTP**（Store API：建购物车 → 加商品 → 结账）：不传确认字段返回 400 `rest_missing_callback_param`；传 false 返回 400 `rest_invalid_param`；传 true 时通过确认校验，只停在“没有支付方式”（支付还没接，这是预期结果）。测试后设置已恢复（option 删除，回到默认值），没有留下测试订单。
  - 五套测试回归，共 75 项全部通过。
- **其他**：
  - debug.log 里新增了一条致命错误，来自我在命令行用来检查订单的临时脚本（对退款对象调用了 `get_billing_email()`），不是插件代码的问题。
  - 订单 #55 是用户在演示订单 #54 上做的 30 美元人工退款（#54 现在的状态是“已退款”）。
- **未验证**：结账页上复选框的实际外观（要在后台开启确认并填写文案后，才能在前台结账页看到）；后台设置页的实际显示。

### INT-01 同步 + PAY-SHIP-01 测试支付与配送 · 2026-10-12 · Claude

- **同步主线**：把 main（00515e7，Codex 的 INT-01 集成）合并进 claude/backend，提交 `e0aac56`，没有冲突。插件代码和后端文档没有被改动；CONTRACT.md 与实现一致。主题启用的情况下，75 项测试全部通过。
- **用户决定（2026-10-12）**：只卖美国；运费先放一个测试运费；测试付款下单后直接进入“处理中”。
- **新增命令** `wp peptide demo commerce [--disable]`（写在 Demo_Command 里，可以重复执行）：
  - 销售国家只限 US，只配送到可销售国家；
  - 配送区域“United States (test shipping)”，固定运费 $0，名称为“Test shipping – rates to be confirmed”，不写任何时效；
  - 借用 WooCommerce 自带的货到付款网关，改名为“Test payment – no money is charged”，下单后订单直接进入“处理中”。
  - `--disable` 会关闭测试付款并删除测试配送区域。
- **工作台**：测试付款开启时，用红字提示“测试付款已开启……正式上线前请关闭”。
- **本地站点现状**：测试结账配置已开启（保留，方便用户和 Codex 下单试用）；结账确认仍是关闭状态（等用户提供文案）。
- **实际检查（真实 HTTP，Store API）**：
  - 建购物车 → 加 2 件 Demo Peptide A → 填写美国地址：`needs_shipping=true`，测试运费 $0 被自动选中，支付方式只有 cod。
  - 改成加拿大地址结账 → 400（没有可用的配送方式）。
  - 美国地址 + 测试付款 + 勾选确认（临时开启了测试文案）→ 200，生成订单 #79，状态为处理中。
  - 核对 #79：支付标题、$0 测试运费、总额 $20、确认快照（原文）、WooCommerce 自己保存的字段值都正确；工作台列出了 #79；指定批次后，工作台中不再出现 #79。
  - Mailpit 拦截到 #79 的顾客邮件和管理员邮件（只在本地）。
  - 测试结束后删除 #79，恢复设置（确认关闭）；订单只剩 #54 和 #55。
  - 五套测试回归共 75 项全部通过。
- **未验证**：浏览器里的完整下单（已请 Codex 在群里一起验证）；真实支付和真实运费（等用户决定）。
- **待办**：Terms / Privacy 政策链接（Codex 提出，要等用户提供政策内容）。

### 同步 PAY-SHIP-01 浏览器验收 · 2026-10-13 · Claude

- Codex 在真实浏览器里完成了访客下单验收：只有 US、$0 测试运费、测试付款、确认框未勾选时会被阻止、成功页、Mailpit 邮件都正常（测试订单 #88 和 #89 已删除）。main 现在是 b5b024b。
- 把 main 合并进 claude/backend（`3a4a534`），没有冲突，插件和后端文档没有被改动；75 项测试回归全部通过，debug.log 没有新增；站点状态：订单 #54 和 #55、测试付款开启、确认关闭。
- 看了 Codex 的同行 UI 对比报告（`outputs/VialCove-同行UI与排版对比报告.md`）。报告建议改版后后端负责“真实字段、商品变体、价格与库存规则、报告状态及权限支持”。其中报告状态、批次与规格的匹配、权限都已经实现；变体、价格、库存要等用户提供真实商品资料（BE-06）。**目前没有新的后端开发任务。**
- Codex 提到：订单邮件目前用的是站点名和 dev-email@wpengine.local，正式品牌、联系信息和外发邮件要在上线时（DEPLOY-01）配置。

### BE-06（第一步）报价表导入 · 2026-10-14 · Claude

- **用户提供**：`C:\Users\JingYue\Desktop\产品报价2.4%.xlsx`（编号 / 产品名称 / 规格 / 价格，208 行）。用户要求价格**按表格原样使用**。
- **合规说明**（已告知用户，群里也通知了 Codex）：清单中有美国处方药或未获批药物（GLP-1 类、HGH、HCG、EPO 等）。在用户拿到专业法律意见之前，这批商品**只作为演示数据**导入本地站点，不作为真实上架商品。
- **新增** `includes/cli/class-import-command.php`：
  - `wp peptide import quote <xlsx> --tag=<tag> [--dry-run]`：直接读取 xlsx（ZipArchive），按产品名合并；只有一个规格时建简单商品，多个规格时建可变商品（属性 Pack，变体 SKU = 编号）；价格原样写入；所有商品都标记 is_demo 和 `_pc_import_tag`。
  - `wp peptide import delete --tag=<tag>`：删除这个标签下的商品和变体。
  - 同一商品内规格文字重复时附上编号；SKU 冲突时附上行号。
- **实际执行**：本地站点导入了 tag `quote-2026-10-14`：94 个商品（简单商品 37 个、可变商品 57 个、变体 171 个，共 208 个 SKU）。重复的 H15 第二条变成 `H15-R160`（价格 109）。Store API 商品总数 96（含 2 个旧演示商品）；首页和 /shop/ 返回 200；debug.log 没有新增。耗时约 95 秒。
- **数据问题**（按原样导入，等用户确认）：
  - 表头写的是“元/盒”，但店铺货币是 USD，所以数值现在按美元显示，需要用户确认；
  - H15 重复；
  - Mazdutide 有 MZ10 和 MDT10 两个 10mg；
  - Semax 有 SX5 和 XA5 两个 5mg；
  - Lipo-C 有三个 10ml；
  - Melanotan I 组里混了 MT-2 和 ML10；
  - XA10 被合并单元格归到了 Oxytocin；
  - 最后一行的产品名是“10ml*大瓶”（编号 B-12）。
- 报价表原件**没有进 Git**（商业资料）；本地副本放在 scratchpad。
- **未做**：商品没有批次和报告；没有图片、描述和分类；还没有与 Codex 的主题一起做目视检查。

### 不依赖用户输入的后续工作 · 2026-10-14 · Claude

- **用户要求**：“把你能做的都做了”。
- **批次与报告批量导入**（BE-06 第二步）：
  - 新增 `includes/class-batch-importer.php` 和 `wp peptide import batches <csv> --reports-dir=… --tag=… [--update] [--dry-run]`。
  - CSV 按 SKU 对应商品或某个规格；同一批次号可以写多行，用来对应多个规格或附多份报告。
  - 任意一行有错时，整个批次都不导入；已存在的批次需要加 `--update` 才会补充。
  - 支持 Excel 默认的 GB18030 编码。报告文件按内容校验类型。
  - `import delete --tag` 现在会一并删除该标签下的批次、报告和文件。
  - 模板：`import-templates/batches.csv`（示例行是演示数据）和中文填写说明 `import-templates/README.md`。
  - `Private_Files::store_local_file()`：导入本机报告文件，校验规则与后台上传相同。
- **上线体检** `wp peptide doctor`（`includes/class-launch-check.php`、`cli/class-doctor-command.php`）：
  - 检查测试付款、真实支付方式、配送、演示模式和演示数据、标记为 demo 的商品、用途声明和确认语、条款和隐私页面、HTTPS、调试输出、私有目录位置、发件人邮箱和管理员邮箱、店铺是否公开、销售国家。只读。
  - 当前本地结果：11 项必须处理、3 项需要确认、1 项通过（开发阶段，属正常）。
- **上线清单**：`docs/handoffs/backend-deploy-checklist.md`（前置合规条件、服务器要求、搬迁步骤、wp-config、清理开发配置、正式配置、上线后验证）。
- **测试**：
  - 新增 `tests/smoke-import.php`，23 项：报价表导入（合并为可变商品、价格、标记 demo、同一标签不能重复导入）；批次 CSV（有效和错误行、多规格、报告进入私有目录、版本与权限、报告整理中）；伪装成 PDF 的文件被拒绝；`--update` 会生成 v2 并取代 v1；GB18030 编码；按标签删除时文件一并删除；临时目录清理干净。
  - 另外实测：内容是 PHP 代码的“.pdf”文件也会被拒绝。
  - 原有 5 套测试都加了 `pre_wp_mail`，测试不再发邮件。
  - 六套测试共 **98 项全部通过**；Mailpit 邮件数不变（23 → 23）；debug.log 没有新增；站点数据不变（订单 #54 和 #55、1 个用户、96 个商品）。
- **仍然要等用户的**：合规结论、政策内容、真实运费、收款渠道、商品图片和描述、批次与报告资料、用途声明和确认语。

### ENV 修复：Local 启动报 EEXIST · 2026-10-15 · Claude

- **现象**：Local 启动站点时报 `EEXIST: file already exists, mkdir '...\Local\run\router\nginx\conf'`，站点无法启动。Codex 已经起了一个临时 Nginx（`work/local-router-recovery`）恢复 HTTP 访问（ENV-ACCESS-03）。
- **排查**：
  - Local 日志显示，生成路由配置时会先清空 `conf` 目录再复制模板；失败时同一毫秒出现两条错误。
  - 用 Local 自带的 Node（v24.15.0，fs-extra 7.0.1）复现：单次复制在 C 盘联接路径、D 盘真实路径的各种组合下全部成功；**两次复制同时写同一个目录**时，会复现 EEXIST。
  - 直接在 C 路径和 D 路径创建目录也都正常，说明目录本身没有处于“待删除”状态。
  - 结论：目录联接本身不是根因。当时 Local 处于残留状态：站点服务进程还在运行，而 Local 认为站点已停止；另外还有 Codex 的临时路由，Local 是从 D 盘路径直接启动的。在这种状态下，路由配置被并发生成，造成冲突。
- **修复**（没有修改代码和数据）：
  1. 通过 GraphQL 停止站点，正常退出 Local；
  2. 停止 Codex 的临时 Nginx；
  3. 确认没有残留的服务进程；
  4. 用正常入口（`%LOCALAPPDATA%\Programs\Local\Local.exe`）重新启动 Local 并启动站点。
- **验证**：
  - 站点状态为 running；连续两次停止和启动都成功；首页、wp-login、/shop/ 返回 200。
  - 现在运行的 Nginx 是 Local 自己的路由（`-c ...\run\router\nginx\conf`）；路由配置目录已正常生成；日志没有新错误。
  - 数据不变：订单 #54 和 #55、1 个用户、96 个商品、3 个批次、测试付款仍开启；smoke-read-api 18 项通过。
- **以后再遇到时**：先在 Local 里点“Stop all”，完全退出 Local（包括托盘图标），确认没有残留的 nginx、httpd、mysqld 进程后再打开 Local 并启动站点。不要同时从两个入口启动 Local，也不要在 Local 运行时另外占用 80 端口。

### 批次反查订单（溯源与召回）· 2026-10-15 · Claude

- **用户要求**：做批次反查订单。
- **实现**：
  - `Order_Batches::orders_for_batch()`：通过订单上的批次索引查找订单，再以订单行为准核对（索引过期的订单会被排除）。包括取消和退款的订单（召回时也要通知）；按时间从新到旧；返回订单号、日期、状态、顾客、邮箱、电话、国家、相关商品和数量。
  - `Order_Batches::orders_csv()`：带 BOM，Excel 直接打开不乱码；以 = + - @ 开头的单元格前加单引号，防止 CSV 公式注入。
  - 新增 `includes/admin/class-batch-orders.php`：
    - 批次编辑页的“收到此批次的订单”面板：显示订单数、顾客数、总件数，最多列出 50 单，带订单链接；
    - “导出 CSV（用于召回通知）”按钮：走 admin-post，校验 nonce，并要求同时有 `edit_shop_orders` 和该批次的编辑权限；
    - 批次列表新增“订单”数列。
  - 新增 `wp peptide batch orders <批次号> [--format=table|csv|json]`。
  - 中文翻译已补全。
- **实际检查**：新测试 `tests/smoke-batch-orders.php` 14 项全部通过：
  - 三个订单命中，只收到 A2 的订单不在结果里，已取消的订单保留；
  - 数量合计正确，包含邮箱；
  - 索引过期时以订单行为准；
  - CSV 有 BOM 和表头，公式注入已中和；
  - 后台面板有订单链接和带 nonce 的导出链接；
  - shop_manager 能看，顾客和访客不能看；
  - 命令行批次号不区分大小写；
  - 清理后恢复原状。
  - debug.log 没有新增。
- **未验证**：浏览器里点击导出按钮下载 CSV（导出内容由 `orders_csv()` 生成，已经测过）。

### 安全检查与修复 · 2026-10-15～17 · Claude

- **用户要求**：做安全检查。由一个独立的只读审查代理检查了插件全部代码（约 5,000 行），结论是没有严重问题。下面的问题已全部修复，插件版本升到 0.4.0。
- **修复内容**：
  1. **中：旧版订单存储下 meta_query 被静默忽略。** 原本可能让任意有已付款订单的顾客拿到全部受限报告，`demo purge` 也可能删掉所有订单。
     - 新增 `Order_Batches::order_ids_with_batch()`：HPOS 下用 meta_query 缩小范围，任何模式下都逐个订单核对索引，查不到就不放行。
     - `user_received_batch()`、`orders_for_batch()`、演示订单清理都改用逐个核对（清理只删确认带演示标记的订单）。
     - 新增过滤器 `peptide_core_use_order_meta_query`（测试用来模拟旧版存储）；`wp peptide doctor` 新增“订单存储模式”检查。
  2. **低：下载链接里的通用 wp_rest nonce。** `download_url` 改为只对这一份报告有效的 `pc_nonce`；下载接口自行校验登录 Cookie 和这个 nonce。同时兼容旧的 `_wpnonce`（由 WordPress 核心处理）。
  3. **低：限流。**
     - 新增 `Rest_Controller::client_ip()`：只有当 REMOTE_ADDR 属于 `PEPTIDE_CORE_TRUSTED_PROXIES`（IP 或 CIDR）时，才采用 CF-Connecting-IP 或 X-Forwarded-For；
     - IPv6 按 /64 归并；
     - 报告下载也加了限流（每个来源每 10 分钟 120 次）。
     - 没有做：持久对象缓存下的原子计数（目前没有对象缓存）。
  4. **低：后台确认框。**
     - 重放时只自动同意用户确认过的那一条提示，并且只同意一次，其他确认交给原生对话框；
     - 打开后 400 ms 内不接受“确定”；按住回车不算确认；默认聚焦“取消”。
  5. **低：撤回报告的存在性泄露。** 下载接口先判断可见性（草稿、仅员工、批次不公开且不是订单所有者，都返回 404），可见时才返回 410。
  6. **其他问题：**
     - 设置了密码的商品不再公开批次；
     - 上线体检判断私有目录时也和 DOCUMENT_ROOT 比较；
     - 升级时只补授新增能力（新增 option `peptide_core_granted_caps`），不恢复管理员手动移除的能力；
     - 卸载时删除插件设置。
  7. **普通 bug：**
     - 订单所有者下载隐藏批次的 public 或 registered 报告时返回 404 → 改为订单所有者跳过可见性检查；
     - 设置第一次保存时确认框被吞掉 → `Settings::sanitize()` 现在可以重复调用；
     - 中文商品 slug 返回 404 → 路由放宽为 `[^/]{1,400}`；
     - 批次列表的订单数改为只计数。
- **接口影响（需同步契约）**：`download_url` 里的参数从 `_wpnonce` 改成 `pc_nonce`，前端照常直接使用 `download_url`，不需要改代码。商品 slug 现在可以包含非 ASCII 字符。
- **实际检查**：
  - 新增 `tests/smoke-security.php` 23 项，全部通过。覆盖：两种订单查询模式下订单所有者判断不放错；撤回报告 410 / 404；nonce 只对一份报告有效；设置第一次保存；中文 slug 编码和解码两种形式；密码保护的商品；代理 IP 和 IPv6 /64；升级时不恢复手动移除的能力。
  - **真实 HTTP**：
    - 订单所有者用 Cookie 加 pc_nonce 下载隐藏批次的报告 → 200（PDF）；
    - 不带 Cookie → 404；
    - 用其他报告的 pc_nonce → 401；
    - 伪造的 nonce → 404。
  - **浏览器**：确认框默认聚焦“取消”；点“确定”后原操作只执行一次；原生 confirm 已恢复。临时测试页已删除。
  - 八套测试共 **135 项全部通过**；debug.log 没有新增；站点数据不变；版本升级自动完成（0.4.0，记录了 43 条能力授予）。
- **上线清单**：补充了 `PEPTIDE_CORE_TRUSTED_PROXIES`（Cloudflare 网段）和“保持 HPOS 开启”。

### PRICE-SALE-01 促销对照价（后端）· 2026-10-17 · Claude

- **来源**：Codex 在群里的完整交接（PRICE-SALE-HANDOFF-01），地瓜要求 Claude 按交接执行。
- **价格依据边界**：用户给的“当前价 × 2 + 0.02”对照价（如 59.98）**没有真实原价依据**。按 FTC 16 CFR 233.1，原价必须是真实、近期、持续一段时间的实际售价。所以这些对照价都标记为演示数据（`source = demo-formula`），`wp peptide doctor` 会把它们列为上线前必须处理的项目。
- **实现**（`includes/class-pricing.php`、`cli/class-pricing-command.php`，修改 `api/class-store-api.php`、`class-order-batches.php`、`class-launch-check.php`、`class-plugin.php`、翻译）：
  - 完全使用 Woo 原生的 regular / sale：regular = 对照价，sale = 当前成交价（不变），**不设促销日期**（不会到期）。没有运行时价格过滤器，没有负数费用，也没有另建定价系统。
  - 每个 simple 和 variation 记录 `_pc_pricing = {base, compare, source, formula, applied_at}`。
  - **启停策略**：
    - `wp peptide pricing revert` 撤销：regular 还原为成交价，sale 清空，成交价不变；
    - 有人在后台清空 sale、而 regular 仍是对照价时，保存钩子（product 和 product_variation 两个钩子）会**自动把 regular 还原为成交价**，绝不会翻倍；
    - regular 被人改成其他值时，不再标记为演示来源。
  - 金额全部用“分”的整数计算；百分比按实际差价**向下取整**（50.0167% 显示为 50），无效时为 0，不会除以零。
  - Store API 扩展：
    - 商品 `extensions.peptide_core.price_pair`：规格或 simple 返回自身；variable 父商品返回**当前价最低的那个规格**及该规格自己的常规价；
    - 购物车行 `extensions.peptide_core.pricing`；
    - 购物车 `extensions.peptide_core.sale_savings`。
    - 都与优惠券分开（优惠券仍在 Woo 原生的 `totals.total_discount`）。
  - 下单快照：用 `woocommerce_checkout_create_order_line_item` 写入订单行 `_pc_price_snapshot`（区块结账和经典结账都会触发）。`/me/orders/{n}/batches` 的每行新增 `pricing`（旧订单为 null，不倒推）。
- **数据迁移**：
  - 写入前逐项核对现场价格与 Codex 的 `price-adjust-20261017/after.json`：210 / 210 一致，没有税。
  - 备份：`D:/Codex/Tasks/peptide-platform/work/price-sale-20261017/before.json`。
  - 执行 `wp peptide pricing apply-demo`：applied 210；再执行一次全部是 already-applied。
  - 核对结果（`price-sale-20261017/verification.json`）：成交价未变 210、对照价公式正确 210、sale 等于成交价 210、没有促销日期 210、演示记录 210、267 条记录的非价格字段哈希不变、57 个父商品最低价正确、订单 #54 和 #55 的哈希不变。
- **实际检查**：
  - 新增 `tests/smoke-pricing.php` 23 项（用临时商品）：公式、百分比边界、未促销、基准不一致时跳过、重复执行不变、成交价最低规格与非最低规格的价格对、数量 2（11996 / 5996 / 节省 6000）、10% 优惠券单独计算（Woo 折扣 600，应付 5396，促销节省仍是 6000）、订单快照、清空 sale 不翻倍、改价后旧订单快照不变、手动改 regular 后去掉演示标记、撤销、已有促销不碰、清理。
  - **真实 HTTP**（Store API）：
    - Tirzepatide 卡片：price_pair 为 TR5 规格，2998 / 5998，50%，demo；
    - 规格 #100：4498 / 8998；
    - 购物车 TR5 ×2：原生 price 2998、regular 5998，line_subtotal 5996；扩展里是 11996 / 5996 / 6000；total_discount 0；total_price 5996；测试商品已移出购物车。
  - 九套测试共 **158 项全部通过**；debug.log 没有新增。
- **未做 / 未验证**：
  - 前端展示（Codex 负责）；
  - 含税情况（本站没有开启税费；扩展里带 `tax_display` 字段）；
  - 真实依据的对照价（需要用户提供，届时 source 记为 documented）。

### 商品分类 · 2026-10-17 · Claude

- **用户要求**：参照截图（GLP-1 & Metabolic、Anti-Aging & Longevity、Repair & Regeneration、Growth Peptides、Immune & Neural）给商品分类。
- **合规取舍**：截图里的“抗衰老”“修复再生”“免疫与神经”属于功效说法，网站上的说法会被美国监管当作“预期用途”的证据。经用户确认（2026-10-17），**改为按物质类别命名**，只说明产品是什么。B733、PT-10、PT-20、PI-20 不确定是什么，按用户选择先放进 Other。
- **分类（slug / 名称 / 排序 / 商品数）**：
  - `glp1-metabolic` / GLP-1 & Metabolic Compounds / 1 / 23
  - `bioregulators-nad` / Bioregulators & NAD+ / 2 / 15（也包括 GHK-CU、AHK-CU、Snap-8、Matrixyl）
  - `bpc-157-tb-500-blends` / BPC-157, TB-500 & Blends / 3 / 12
  - `gh-secretagogues-hormones` / GH Secretagogues & Hormones / 4 / 20
  - `neuropeptides-immunopeptides` / Neuropeptides & Immunopeptides / 5 / 16
  - `reconstitution-supplies` / Reconstitution Supplies / 6 / 4
  - `other` / Other / 7 / 4
  - Uncategorized 只剩 2 个旧的演示商品。
- **执行**：脚本和方案在 `D:/Codex/Tasks/peptide-platform/work/claude/category-plan/`（`plan.json`、`apply.php`，可以重复执行；归类前的分类备份在 `before.json`）。用 WooCommerce 原生分类 `product_cat`，排序用 term meta `order`。没有改插件代码。
- **实际检查**：
  - 先 dry run 再写入：94 / 94 全部归类，没有遗漏，重复执行结果相同；
  - Store API `?category=<slug>` 返回的数量正确，分类页面返回 200；
  - 210 个价格全部没变；非价格字段只有这 94 个商品的分类变了，其余没有变化；
  - debug.log 只有 WordPress 自动更新的记录。
