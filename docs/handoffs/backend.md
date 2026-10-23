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

### Shopname 后台对照 · 2026-10-19 · Claude

- 用户提供了一个 Shopname 店铺后台，由用户本人登录，Claude 只读查看后写了查漏补缺报告：`docs/handoffs/shopname-gap-report.md`（提交 `bf3781b`）。用户随后选择先做 P0 里的两项：物流单号追踪和客服按钮（下两节）。

### SHIP-TRACK-01 物流单号追踪 + SUPPORT-CHAT-01 客服按钮（后端）· 2026-10-19 · Claude

- **取舍**：WooCommerce 11.2 自带完整的“订单履约（Fulfillments）”功能，默认关闭。它包括：
  - 订单页“完成商品发货”面板，可以分包裹发货；
  - 自动识别承运商（UPS、USPS、FedEx、DHL 等 80 多家）并生成追踪链接；
  - 订单列表的发货状态、承运商、单号列，以及筛选和批量操作；
  - 顾客邮件（发货 / 更新 / 删除）和“我的账户”订单页的物流显示。

  所以**不另写单号系统**，直接开启原生功能，插件只补充下面列的几项。
- **SHIP-TRACK-01**（新增 `includes/class-shipping-tracking.php`、`cli/class-shipping-command.php`）：
  - **发货前检查批次**：钩子 `woocommerce_fulfillment_before_fulfill`。标记为已发货时，如果这次发货的商品行**所属商品有已发布的批次**、但这一行还没指定批次，就抛出 `FulfillmentException`，后台面板会显示提示。
    - 没有任何批次的商品（例如配套耗材）不受限制；
    - 未发货的草稿不受限制；
    - 设置项 `require_batch_to_ship` 默认开启，可以在“VialCove 设置 → 发货”里关闭。
  - **顾客接口**：`GET /peptide/v1/me/orders/{n}/batches` 顶层新增 `fulfillment`（原生功能关闭时为 null）：
    ```json
    {"status":"fulfilled","shipments":[{"provider":"usps","provider_name":"USPS","tracking_number":"9400…","tracking_url":"https://tools.usps.com/…","shipped_at":"2026-10-19T14:29:59+00:00","items":[{"name":"Demo Peptide A","quantity":1}]}]}
    ```
    - `status` 取 Woo 的订单发货状态：`fulfilled` / `partially_fulfilled` / `unfulfilled` / `no_fulfillments`；
    - 只返回已发货的包裹，草稿不返回；
    - 没有链接时按承运商生成。
  - **工作台**新增“已付款待发货的订单”：处理中、还没全部发货的订单，部分发货的会标注出来。
  - **上线体检**新增两项：
    - `Shipment tracking`：原生功能关闭时 fail；发货邮件关闭时 warn；
    - `Batch check before shipping`。
  - **命令**：`wp peptide shipping enable|disable|status`。数据表由 WooCommerce 在下一次加载时自动创建；关闭时保留已有记录。
  - **本地站点已执行** `wp peptide shipping enable`，表 `wc_order_fulfillments` 和 `wc_order_fulfillment_meta` 已创建，目前没有发货记录。#54 和 #55 不受影响。
- **SUPPORT-CHAT-01**（新增 `includes/class-support-contacts.php`）：
  - 设置页新增“客服按钮”：开关、WhatsApp 号码、Telegram 用户名、客服邮箱、WhatsApp 开场白、位置（右下 / 左下）。
  - 校验规则：
    - WhatsApp 只保留数字，8–15 位，带国家代码；
    - Telegram 可以填 `@name` 或 `t.me/name`，保存为 name（5–32 位）；
    - 邮箱用 `is_email`；
    - 开场白最多 300 字；
    - 无效的值不保存并提示；没有任何有效渠道时不能开启。
  - **`GET /peptide/v1/site` 新增 `support`**，主题也可以调用 `peptide_core_get_support_contacts()`，结果相同：
    ```json
    {"enabled":true,"position":"right","channels":[
      {"type":"whatsapp","label":"WhatsApp","value":"+15551234567","url":"https://wa.me/15551234567?text=Hi%20VialCove"},
      {"type":"telegram","label":"Telegram","value":"@vialcove_support","url":"https://t.me/vialcove_support"},
      {"type":"email","label":"Email","value":"support@example.com","url":"mailto:support@example.com"}]}
    ```
    - 渠道顺序固定；只返回有效渠道；
    - `enabled=false` 时 `channels=[]`，前台不显示按钮。
  - 按钮的样式和交互由主题负责（已在群里请 Codex 做）。
  - 上线体检新增 `Support button`：没有配置时 warn。
- **其他**：插件版本 0.5.0；中文翻译已补齐（28 条）；设置项新增 `require_batch_to_ship` 和 `support`，`sanitize` 在第一次保存时可以重复调用，结果不变。
- **接口影响**（需要同步到 CONTRACT.md）：
  1. `/site` 新增 `support`；
  2. `/me/orders/{n}/batches` 新增 `fulfillment`；
  3. 主题函数 `peptide_core_get_support_contacts()`。

  都只是新增字段，原有字段不变。
- **实际检查**：
  - 新增 `tests/smoke-shipping-support.php` 30 项：
    - 客服字段的清理和校验、重复清理结果不变、链接编码、/site 与主题函数一致；
    - 缺批次不能发货；草稿可以保存，但草稿改成已发货时会拦截；
    - 无批次商品可以发货；指定批次后可以发货；
    - 部分发货出现在工作台，全部发货后从工作台消失；
    - 顾客接口的包裹信息正确（UPS、单号、链接、时间）；
    - 关闭检查后可以发货；体检项存在；功能关闭时接口返回 null；
    - 清理后没有残留的发货记录、订单和顾客。
  - 十套测试共 **188 项全部通过**。
  - 用后台面板实际调用的接口 `POST /wc/v3/orders/{id}/fulfillments`、以中文管理员身份验证：
    - 缺批次时返回 400，提示“发货前请先指定批次：Demo Peptide A。（可以在 VialCove 设置里关闭这项检查。）”；
    - 指定批次后返回 201；
    - 顾客视图生成了 USPS 追踪链接。

    临时订单已删除。
  - `wp peptide doctor`：Shipment tracking ok、Batch check ok、Support button warn（还没填联系方式）。
- **未验证**：
  - 没有在浏览器里点击订单页的“完成商品发货”面板（需要用户登录后台）；
  - 发货邮件的实际发送（本地站点不发邮件，测试里关闭了邮件）；
  - 前台客服按钮（等 Codex）。
- **需要用户提供**：真实的 WhatsApp / Telegram / 客服邮箱（建议用业务号），以及承运商（美国仓发货一般用 USPS 或 UPS）。

### EDIT-BLOCKS-02（第一步）后台“店铺装修”菜单 + 主题装修页 · 2026-10-20 · Claude

- **用户要求**：参照 Shopname，在后台加“店铺装修”菜单，子页面依次是主题装修、菜单导航、自定义页面、首页 SEO、域名管理、活动专题页。其他页面先不做内容；主题装修页参照 Shopname，要有使用中的模板、店铺默认模板 / 域名指定模板、模板库。
- **数据模型**（新增 `includes/class-templates.php`）：
  - 文章类型 `pc_template` = 一套装修模板。字段：名称、备注 `_pc_note`、适用主题 `_pc_theme`、创建人、修改时间。
  - 文章类型 `pc_layout` = 模板里的装修文档，`post_parent` 指向模板，`_pc_document` 取 `home` / `header` / `footer` / `global`。支持区块编辑器和修订。新建模板时四份文档一起建好。
  - 两种文章类型都不公开、不进菜单，能力类型 `pc_layout(s)`。
  - 店铺默认模板存在 `peptide_core_active_template`；域名指定存在 `peptide_core_template_domains`（域名 → 模板，去掉协议、路径、端口，转小写；带 www. 和不带视为同一域名）。
  - 当前模板的优先级：预览 > 域名指定 > 店铺默认（`Templates::current_id()`）。前台请求不会创建模板（`default_id( false )`）。
  - 预览：`?pc_template_preview=<id>&pc_preview_nonce=<nonce>`，需要 `edit_pc_layouts` 能力，nonce 按模板分别生成。预览页发送 nocache 和 noindex 头，底部有提示条；`pc_frame=1` 用于后台缩略图，隐藏工具栏和提示条。
- **后台**（新增 `admin/class-store-design.php`、`admin/class-layout-editor.php`、`assets/store-design.css`）：
  - 顶级菜单“店铺装修”，6 个子页面，其中 5 个是“即将推出”占位页。
  - 主题装修页：
    - 使用中的模板：名称、适用主题标签、修改时间、“装修”和“预览”按钮，以及电脑 + 手机的缩略预览（同源 iframe 缩放显示）；
    - “店铺默认模板 / 域名指定模板”切换：域名表格，可以添加和移除；
    - 我的模板库：名称（带“使用中”和域名标签）、主题、备注、创建人、修改时间；操作有装修、设为默认、复制、编辑信息、删除，另有预览；
    - 新建模板；
    - 推荐应用：客服按钮、物流追踪、结账确认、批次报告，显示是否已启用。
  - **“装修”按钮打开该模板首页文档的区块编辑器**，里面只允许插入 `vialcove/*` 模块（现在还没有注册模块），顶部提示“模块库建设中”和返回链接。
  - 规则：
    - 默认模板和被域名指定的模板不能删除；删除是移到回收站，文档一起移走；
    - 复制会复制每份文档的草稿内容和 `_pc_*` 元数据。
- **权限**：`edit_pc_layouts` 等授予管理员和店长；`pc_publish_layouts`（设为默认、域名指定、删除，以后的发布）只授予管理员。升级时自动补授。
- **本地数据**：已经自动建好默认模板“VialCove”（#684）和 4 份空文档。前台没有变化，因为还没有模块，也没有发布。
- 插件 0.6.0；中文翻译补了 84 条。
- **实际检查**：
  - 新增 `tests/smoke-store-design.php` 36 项：
    - 默认模板和文档；新建时清理名称、限制备注长度；修改信息；复制（新 ID、草稿和元数据都复制、文档相互独立）；
    - 设为默认；默认模板和被域名指定的模板不能删除；
    - 域名规范化和校验、指定、www 视为同一域名、其他域名用默认模板、移除；
    - 预览需要权限和对应模板的 nonce（顾客和访客不行）；
    - 能力；编辑器只允许 vialcove 区块，其他文章类型不受影响；
    - 页面能渲染；移到回收站；清理。
  - 十一套测试共 **224 项全部通过**。
  - **没有在浏览器里看过页面**：本地后台需要登录，Claude 不能输入密码。待用户在内置浏览器登录后再做视觉检查。
- **对 Codex 的影响**：装修文档属于模板，所以前台读取时要先确定当前模板（`Templates::current_id()`）。之前提案里的 `peptide_core_render_layout( 'home' )` 会自动按当前模板读取，主题不需要处理模板和域名。
- **浏览器验收（用户登录后，2026-10-20）**：
  - 主题装修页的布局与 Shopname 一致：使用中的模板 + 电脑和手机缩略预览、默认 / 域名切换、模板库、下拉菜单、推荐应用。域名指定页和占位页显示正常。“装修”按钮能打开区块编辑器（文档 #685），顶部有提示。
  - 根据检查做的修正：
    - 次要按钮改成中性灰，和 Shopname 一致；样式表版本号带上文件修改时间，避免浏览器用旧缓存；
    - 装修文档不能单独删除（`map_meta_cap` 返回 do_not_allow），编辑器里的“移动到回收站”按钮已经不显示；删除整个模板时仍会连同文档移到回收站；
    - 默认模板的 4 份文档标题改为中文（首页 / 页头 / 页脚 / 全局样式）。
  - 测试增加到 37 项，全部通过。

### EDIT-BLOCKS-03 / 04 积木库 + YY 式装修编辑器 · 2026-10-20 · Claude

- **用户要求**：
  1. 先把 Codex 扫描出的 YY 积木加进后台，先做最基本的积木；
  2. 编辑器界面照 YY 的装修页来做（用户提供了截图）。
- **积木库**（`includes/modules/`，插件 0.7.0）：
  - **31 个基础积木**：YY 基础积木 31 个中的 30 个（不做“捐款/小费”，它的“放大系数”会虚增金额和人数）+ VialCove 首页的“图文行”（Codex 交接的 `media_text_list`，默认左、右、左）。
  - 类型名用下划线（`media_text_list`），区块名用短横线（`vialcove/media-text-list`）。
  - `class-registry.php`：每个积木的字段清单。分组有内容 / 图片与视频 / 布局 / 播放 / 按钮 / 颜色 / 显示与间距；每个积木自动加上电脑显示、手机显示、上下间距（电脑 / 手机分开）和背景色。
  - `class-fields.php`：字段类型有 text / textarea / html / code / url / toggle / select / icon / number / color / image / media（电脑图 + 手机图，含 ALT、填充方式、焦点）/ video / link / menu / category / product / repeater。
    - `device` 字段保存为 `{desktop, mobile}`；手机为 null 表示和电脑一致；0、false 和空字符串不当作空值。
    - 富文本只允许 p、strong、em、a、列表等标签；链接只允许 http(s)、mailto、tel、站内相对路径和 #。
    - 自定义 HTML 只有最后编辑者是管理员时才原样输出，编辑器里从不执行脚本。
  - `class-renderer.php`：外层由插件输出，内容是 `<section id="vc-<id>" class="vc-m vc-m--<type>" data-module>` 加显示、间距、背景的 CSS 变量。**内层优先用主题模板 `template-parts/modules/<type 短横线>.php`**，参数是 `$args['module']`（已清理、已补默认值）和 `$args['context']`（public / preview / editor）；主题没有模板时用插件自带的默认样式（`class-fallback.php`、`assets/modules.css`、`assets/modules.js`）。
  - `class-assets.php`：主题用过滤器 `peptide_core_layout_assets` 登记资产键，引用主题自带的图片。
  - 商品热点只读 WooCommerce 的名称、价格和链接，不保存价格副本。
- **YY 式装修编辑器**（`admin/class-design-editor.php`、`assets/design-editor.js/css`；“主题装修 → 装修”现在打开这里）：
  - 顶栏：返回、页面选择（目前只有首页，其他页面显示“即将推出”）、电脑 / 手机 / 全屏预览、撤销 / 重做（Ctrl+Z）、查看店铺、保存草稿（Ctrl+S）、发布（下一步实现，现在点了会提示）。
  - 左侧：整页实时预览，包括主题的页头和页脚。改动约 0.6 秒后刷新，保留滚动位置；点预览里的积木就能选中；选中的积木有橙色框。
  - 右侧：
    - “积木装修 / 全局配置”两个标签；
    - 积木列表：头部固定在最上面、页脚固定在最下面，可以拖动排序；鼠标移上去有复制、隐藏、删除；隐藏的积木右上角有标记；“添加积木块”打开积木库（卡片网格，新积木加在选中积木的下面）；
    - 设置面板：按分组折叠，条目可以添加、复制、删除、上下移动、隐藏；有电脑 / 手机切换，和预览设备联动。
  - 页头、页脚、全局配置目前显示“下一步”的说明。
  - 字段控件放在 `assets/module-controls.js`（`window.pcModuleUI`），区块编辑器（`module-editor.js`）也用同一份，所以同一份数据在 WordPress 区块编辑器里也能打开。
- **数据与接口**（`modules/class-layout.php`、`api/class-layout-controller.php`）：
  - 文档内容用区块格式保存在 `post_content`（`<!-- wp:vialcove/... {...} /-->`），没有另外一份 JSON。
  - `GET` / `POST /peptide/v1/layouts/{id}`：读取 / 保存草稿；保存时带上 `modified`，对不上返回 **409**；保存时按字段清理、丢弃未知区块、ID 重复时换新；每次保存产生一条修订；设置文章锁。
  - `POST /peptide/v1/layouts/{id}/preview`：短期预览令牌（transient，30 分钟，绑定用户），不产生修订。
  - 预览页：只在员工预览模板时，由插件模板 `templates/layout-page.php`（主题页头 + 模块 + 主题页脚）接管首页；主题可以用 `peptide-layout.php` 替换。**公开首页不变**（还没有发布功能）。
- **实际检查**：
  - 新增测试：
    - `smoke-modules.php` 38 项：31 个积木都在编辑器和公开两种上下文里渲染、字段清理、手机继承规则、隐藏、电脑 / 手机两张图、YouTube 和自定义 HTML 安全、区块预览接口、没有 PHP 警告；
    - `smoke-design-editor.php` 20 项：读取、保存、清理、去重、409 冲突、修订、预览令牌只对本人有效、预览不改动已保存内容、权限、清理。
  - 十三套测试共 **283 项全部通过**。
  - **浏览器实测**（用户已登录）：打开编辑器、积木库、插入轮播和图文行（默认左右左）、点预览选中积木、保存草稿（数据库里 2 个模块、产生修订）、手机预览（图上文下）、从媒体库选图（焦点和 ALT 自动填写）、中文界面，都正常。
- **本地数据**：默认模板首页文档 #685 里有一份测试草稿（轮播 + 图文行，未发布，访客看不到）。
- **未做**：发布到网站（公开首页切换）、页头 / 页脚 / 全局配置编辑、商品块和运营类积木、Codex 的正式渲染模板。

### SYNC-HOME-01（第 1 步）补齐首页需要的积木 · 2026-10-20 · Claude

- 用户同意“首页同步到积木”的方案，让 Claude 先补积木，Codex 按群里的请求写模板。
- **新增 3 个积木**（共 34 个）：
  - `product_collection`（商品块，“专辑商品”）。字段：小标题、标题、简短描述、文字链接；商品来源（全部 / 分类 / 手动选择）、排序（目录顺序 = `menu_order title` 升序 / 最新 / 名称 / 价格）、数量、电脑 / 手机列数；悬停报告预览开关；无商品时显示的文字。
    - **不保存价格**，查询统一用 `\PeptideCore\Modules\Products::for_module( $module )`；
    - 手动选择时按选择的顺序显示；
    - 默认外观在主题有 `vc_product_card()` 时直接调用，所以现在就和首页卡片一样。
  - `report_feature`（批次报告介绍）：小标题、两行标题、描述、按钮、示意卡片（小标题、标题、信息行条目、底部文字）、颜色。只是展示，不读真实报告。
  - `cta`（结尾引导）：小标题、标题、描述、按钮、对齐方式、颜色。
- **补字段**：
  - 轮播的每一张：名称（读屏用）、版式（文字压在图上 / 文字在图旁边）、标题第二行（强调）、文字链接、短信息（最多 4 条）、浮动卡片（最多 3 张：图标、小标签、标题、行动文字、链接）、图片编号、图片说明（加粗 / 备注）。**第一张的标题输出为 H1**，和现在的首页一致。
  - FAQ：小标题、标题第二行、文字链接、版式（标题在左、问答在右 / 标题在上）。
- 修正：排序选项的英文原文由 “Order” 改为 “Sort order”，避免和“订单”的翻译混在一起。
- **检查**：
  - smoke-modules 增加到 46 项，其中商品区默认查询和现有首页的 `wc_get_products( menu_order title ASC, 4 )` **结果和顺序完全一致**；
  - 十三套测试共 **291 项全部通过**；
  - 浏览器（在 Claude 自己新开的标签页里）：积木库的“商品块”分类里有“专辑商品（商品区）”，插入后预览显示主题的商品卡片；测试插入已经撤销，没有保存。

### SYNC-HOME-01（第 3、4 步）导入命令 + 发布到网站 · 2026-10-20 · Claude（插件 0.8.0）

- **发布**（`modules/class-layout.php`、`api/class-layout-controller.php`、编辑器）：
  - 发布 = 把草稿复制成“已发布快照”（meta `_pc_published_content`，另记修订号、时间和发布人）。访客只看快照，继续改草稿不影响网站。
  - **当前模板（预览 > 域名指定 > 店铺默认）的首页有已发布的积木时，首页由插件模板 `templates/layout-page.php` 输出**（主题页头 + 积木 + 主题页脚，主题可以用 `peptide-layout.php` 接管）；从未发布或取消发布时仍是主题原来的 `front-page.php`，可以随时回退。**所以 Codex 不需要在 front-page.php 里加切换点**（原计划的第 4 项取消）。
  - 接口：`POST /layouts/{id}/publish {modified}`（409 = 打开后被改过）、`/unpublish`、`/discard`（草稿恢复成已发布版本，并留下一条修订）。发布和取消发布需要 `pc_publish_layouts`（管理员）；店长可以编辑，但不能发布。
  - 编辑器：顶栏显示状态（未发布 / 已发布 + 时间 / 有未发布的修改）；点“发布”时有未保存的修改会先保存再发布（需要确认）；▾ 菜单里有“放弃未发布的修改”和“取消发布”。
  - 主题函数：`peptide_core_has_layout( 'home' )`、`peptide_core_render_layout( 'home' )`。发布后会触发 `peptide_core_layout_published`，可以用来清缓存。
- **导入**：`wp peptide layout import [--file] [--template] [--dry-run] [--force] [--allow-missing-assets]`。
  - 默认数据文件 `import-templates/home-2026-10-20.json`：2026-10-20 主题首页的全部文字、链接、ALT，以及 AI 图片声明。6 个积木，ID 分别是 hero、collection、our-story、report-feature、questions、closing-cta；轮播里“Explore our story”的锚点是 `#vc-our-story`。
  - 链接标记 `{url:shop}`、`{url:reports}`、`{url:<页面别名>}` 会换成本站相对地址。
  - 资产键没登记时停止；草稿已有内容时需要加 `--force`。
  - 另外还有 `wp peptide layout publish|unpublish|status`。
- **补字段**：图文行的图片说明 `caption`、行下方说明 `footnote`；轮播每一张的脚注 `footnote`；资产可以附带 `srcset`。
- **检查**：smoke-design-editor 增加到 36 项（发布时修改时间过期 409、发布后接管首页、改草稿不影响访客、放弃修改、店长不能发布、取消发布后恢复主题首页并保留草稿、链接标记、首页数据 6 个积木、AI 声明保留）。十三套共 **307 项全部通过**。浏览器（Claude 自己的标签页）：顶栏状态和发布菜单显示正常，没有点发布。
- **还没做**：正式导入（等 Codex 登记 7 个资产键）；在真实网站上发布。用户验收前不发布。

### SYNC-HOME-01 首页已导入为草稿 · 2026-10-20 · Claude

- 用户担心前端 UI 被替换、需要自己重新编辑。Claude 说明：公开首页没有变化，Codex 的首页一直在用；目标正是把现有首页导入后在编辑器里直接改。
- 为了不用等主题登记图片，插件按主题里现有的文件，先登记了 7 个资产键的默认值（`Assets::home_defaults`，过滤器优先级 5；只登记确实存在的文件，并用 `wp_getimagesize` 读出宽高和 srcset）。**主题用同名键登记时会覆盖插件的值。**
- 已执行 `wp peptide layout import --force`：默认模板“VialCove”的首页文档 #685 现在是 6 个积木的**草稿，没有发布**。原来的测试草稿保留在修订记录里。
- 浏览器（Claude 自己的标签页）：编辑器预览里显示了现有首页的文字和图片（例如轮播第 2 张 “Built on curiosity.”、实验室图、AI 声明说明）。积木列表有 6 项。
- 剩下的：Codex 的积木模板（做好一个就会自动替换一块默认样式）→ 用户在三种宽度下对比 → 用户发布。

### 品牌故事拆成独立积木 · 2026-10-20 · Claude

- **用户要求**：品牌故事不应该是一整块。上面的标题是一个积木，下面每一行图文各是一个积木（和 YY 一样）。
- 新增积木 **`section_heading`（标题栏）**：小标题、标题、标题第二行、介绍、文字链接、对齐方式、颜色。积木共 35 个。
- **`media_text`（图文）补字段**：小标题 eyebrow、标签 label、图片说明 caption、滚动渐显 animation。
- 首页数据改为：`our-story`（标题栏，锚点 `#vc-our-story` 不变）→ `story-israel` / `story-china` / `story-united-states`（3 个图文，左 / 右 / 左，上下间距电脑 24、手机 16）→ `story-note`（富文本，AI 图片声明）。首页草稿重新导入后共 10 个积木（仍未发布）。`media_text_list` 积木保留在库里，首页不再使用。
- 编辑器：积木列表里鼠标移上去出现的那组按钮，按钮之间的空白不再挡住点击，点标题就能选中。
- 检查：smoke-modules 48 项、smoke-design-editor 37 项全部通过（包括拆分后的 10 个 ID、左右左顺序）。浏览器（Claude 自己的标签页）：列表中有标题栏和 3 个图文；选中“At the source.”后预览会滚动并高亮，侧栏显示这一行自己的字段。

### 首页间距改用空白积木块（和 YY 一样）· 2026-10-20 · Claude

- 用户要求“拆得细一些”，并在选项里选了“像 YY 一样加空白积木块”。
- 首页数据：10 个内容积木各自的上下间距都设为 0（批次报告介绍有深色背景带，保留内部留白：电脑 80、手机 48）。每个积木后面插入一个空白积木块 `gap-01`…`gap-10`。高度（电脑 / 手机）：轮播后 64 / 40；商品区后 96 / 56；标题栏后 40 / 24；图文之间 56 / 32；最后一个图文后 24 / 16；其余 96 / 56。草稿重新导入后共 20 个积木，仍未发布。
- 修正：空白积木块在手机上会被套上默认的上下留白（`.vc-m` 的手机规则覆盖了 `.vc-m--spacer`）。改为 `.vc-m.vc-m--spacer { padding: 0 }`。
- 编辑器列表里空白积木块显示高度，例如“空白积木块 · 96 / 56px”。
- 检查：smoke-design-editor 39 项通过（新增：每个内容积木后都有空白积木块、内容积木自身间距为 0）。浏览器（Claude 自己的标签页）：列表顺序与 YY 类似。

### 编辑器：高亮正在修改的区域 · 2026-10-21 · Claude

- **用户要求**：点“页头”时，预览要高亮页头；展开某一张轮播（某一个条目）时，要高亮那一张。目的是让用户知道自己正在改哪个地方。
- **高亮框**：原来是给元素加 outline，会被主题里子元素的背景盖住（页头就是这样）。现在改为在预览页面上单独画一个绝对定位的框 `#pc-de-highlight`，左上角显示名称，例如“页头”或“轮播图 · 第 2 项”；框外加一层很淡的遮罩。滚动、缩放、图片加载后会重新定位。
- **条目联动**：
  - 设置面板里展开的条目改为由编辑器控制（`ItemsEditor` 新增 `openId` / `onOpen`，`settingsPanels` 新增 `openItem` / `onOpenItem`；区块编辑器里不受影响）。
  - 预览按 `[data-module-item="<条目 id>"]` 找到对应元素并高亮。
  - 一次只显示一项的积木（轮播）：先发冒泡、可取消的 `vc:select-item` 事件（`detail.item`）；没人处理时，对 `[data-vc-module-carousel]` 发 focusin 暂停自动播放，再用 Home + ArrowRight 切到这一张；还看不见时直接切换 is-active / hidden。
  - 在预览里点某一项，会选中积木并展开这一项的设置。
- 插件默认样式里每个条目也输出 `data-module-item`（18 处），插件默认轮播支持 `vc:select-item`。
- 测试：smoke-modules 改为直接检查插件默认样式（主题已经有 carousel、section-heading、media-text 模板，Renderer 会改用主题模板），并新增 data-module-item 检查。十三套测试全部通过。
- 浏览器：选中页头时，页头被框住并标“页头”；展开第 2 张轮播时，预览切到第 2 张、框住并标“轮播图 · 第 2 项”，8 秒后仍然停在这一张。

### PRODUCT-HOVER-01 商品卡片悬停内容在“编辑商品”里设置 · 2026-10-21 · Claude

- **用户要求**：商品卡片鼠标悬停时显示的那张“图中图”（现在是主题写死的报告占位卡片）要能编辑。用户认为应该放在编辑商品里改。Claude 同意：这是每个商品自己的内容，首页积木只负责开关。
- **数据**（新增 `includes/class-product-hover.php`）：商品 meta `_pc_hover_mode`（placeholder / report / gallery / image / none，默认 placeholder）、`_pc_hover_image`（必须是图片附件）、`_pc_hover_alt`。
  - `report`：读取该商品公开批次里最新的当前报告，只取目录信息（batch_no、title、type、version、issued_on、issuer_name、is_demo、access），不含文件和下载地址；没有时退回 placeholder。
  - `gallery`：商品相册的第一张图；`image`：自定义图片。缺图时都退回 placeholder。
- **后台**：商品编辑页 → 多肽资料 → “卡片悬停”：下拉选择方式；选“自定义图片”时有媒体库选图按钮，并提示不要上传虚构的检测报告；另有 ALT 描述框。
- **主题函数** `peptide_core_get_product_hover( $product )`，返回 `{mode, requested, image:{src, width, height, srcset, alt}|null, report:{...}|null}`。
- 首页商品区积木的开关改名为“显示每个商品自己设置的悬停内容”。
- 检查：新增 `tests/smoke-product-hover.php` 16 项（5 种模式、缺内容时退回、报告摘要不含下载链接、演示报告带标记、保存时的清理、编辑页表单保存）。十四套测试全部通过。浏览器：面板显示正常（只切换了选项看效果，没有保存；演示商品的设置没有变化）。
- **需要 Codex**：`vc_product_card()` 按 mode 渲染悬停层（见群消息）。

### 主题装修页适配宽屏 · 2026-10-22 · Claude

- 用户在宽屏上截图，要求“适配一下 UI”。
- 改动：
  - 页面铺满后台内容区（去掉 max-width 1600）；
  - 缩略预览改为按容器宽度等比缩放：电脑 iframe 按 1440px 渲染，手机按 390px，由页面脚本计算 scale，窗口大小变化时重新计算；
  - 预览区最大宽度 1180px，电脑框占 82%，手机框叠在右下；
  - 过时的说明“模块编辑器正在建设中”换成真实的首页发布状态标签：首页未发布 / 已发布 + 时间 / 已发布 · 有未发布的修改，后面附一句说明；
  - 模板库新增“首页”状态列；
  - 推荐应用改为 auto-fit 铺满一行。
- 检查：smoke-store-design 37 项通过；服务器端渲染确认有状态标签和 2 个缩放 iframe，旧说明已经去掉。第一次跑十四套测试时 smoke-modules 有 2 项失败：Codex 已经交了 product-collection 和 faq 的模板，测试却还在检查插件默认样式的输出。改为直接测默认样式（`$fb`）后，49 项全部通过。主题现在有 9 个积木模板：carousel、cta、faq、media-text-list、media-text、product-collection、report-feature、rich-text、section-heading。**没有做浏览器视觉检查**（内置浏览器的登录已过期）。
- 另外发现：**Local 自动升级到 10.2.0，安装程序把 `%LOCALAPPDATA%\Programs\Local` 的目录联接换成了真实目录**（约 2.4 GB 在 C 盘），D 盘的 `D:\Codex\LocalWP\app` 已经空了。数据目录 `%APPDATA%\Local` → `D:\Codex\LocalWP\data` 的联接仍然正常。Claude 的 wp.sh 暂时改为使用 C 盘的 wp-cli.phar。是否重新迁到 D 盘，等用户决定。

### EDIT-BLOCKS-07 电脑 / 手机参数分开 + 浮动卡片拖动 + 编辑器细节 · 2026-10-22 · Claude

- **用户反馈 4 点**：
  1. 首屏 3 张浮动卡片要能编辑：可以做成图层拖动；但客户主要用手机，要么删掉，要么适配手机；
  2. 去掉预览里的滚动条；
  3. 积木列表里，积木名后面不要带参数（例如“空白积木块 · 64 / 40px”）；
  4. **手机和电脑的参数要完全分开**：之前切换只是换视图，参数是同一份。
- **电脑 / 手机分开**（Renderer、Layout、Blocks、编辑器）：
  - 区块新增属性 `mobile = {settings: {只存手机改过的字段}, items: 整份条目 | null}`。
  - 编辑器切到“手机”后，修改普通字段写进 `mobile.settings`，修改条目时把整份条目复制到 `mobile.items`；没改过的内容继承电脑。原本就分设备的字段（device）和“显示与间距”仍然按原来的方式存。
  - 侧栏在手机模式下显示提示条和“手机已单独修改 N 项”，并提供“全部恢复与电脑一致”；单独改过的字段左侧有蓝条，可以单个恢复。
  - 输出：有手机版设置时，同一个 `<section>` 里输出 `<div class="vc-v vc-v--desktop">…</div><div class="vc-v vc-v--mobile">…</div>`（display: contents，767px 断点切换）；主题模板多收到一个参数 `$args['device']`（all / desktop / mobile）。没有手机版设置的积木输出不变。
  - 保存时保留 mobile；为空时不写进内容。复制积木时，电脑版和手机版的条目编号保持一一对应。
  - 预览高亮时，优先选当前看得见的那一份条目。
- **浮动卡片**：
  - 轮播每张新增 `cardLayout`（电脑 / 手机分开：overlay 压在图上 / below 图片下方 / hidden 不显示），默认电脑 overlay、手机 below；
  - 每张卡片新增 `x`、`y`（%，电脑 / 手机分开，空 = 自动排列）；
  - 预览里可以拖动 `[data-pc-drag]` 元素，在 `[data-pc-drag-area]` 范围内按百分比定位，松手后写入当前设备的 x / y（手机有单独条目时写进手机条目），并自动选中这一张；
  - 插件默认样式已经实现三种放置方式和定位；**Codex 的模板需要支持**（见群消息）。
- **细节**：预览 iframe 隐藏滚动条（仍然可以滚动）；积木列表里空白积木块不再显示高度。其他积木名后面仍然带标题，用来区分几个同名的“图文”。
- 测试：smoke-modules 58 项（新增手机版设置 7 项、浮动卡片 3 项）；十四套共 **338 项全部通过**。**没有做浏览器实测**（内置浏览器的登录已过期）。

### ENV：Local 程序搬回 D 盘 + 以后自动搬回 · 2026-10-22 · Claude

- 原因：Local 自动升级到 10.2.0，安装程序把 `%LOCALAPPDATA%\Programs\Local` 的目录联接换成了 C 盘上的真实目录（约 2.4 GB）。用户要求搬回 D 盘，并且以后检测到时自动搬回，不需要询问。
- 新脚本 `D:\Codex\LocalWP\tools\relocate-local.ps1`（UTF-8 BOM，日志 `relocate-local.log`）：
  - 已经是联接时直接退出；
  - 是真实目录时：robocopy 镜像到 `D:\Codex\LocalWP\app`，核对文件数和总字节数一致 → C 盘目录改名备份 → 建联接并确认能找到 Local.exe → 删除备份。失败时还原。
  - `-Auto` 模式：记下正在运行的网站并通过 GraphQL 停止 → 退出 Local → 等 mysqld、nginx、php-cgi 等后台进程退干净 → 搬家 → **用 explorer.exe 重新打开 Local**（避免随脚本一起退出）→ 启动原来在运行的网站。Local 正在升级（Update / Squirrel）时跳过。
- 计划任务“VialCove - keep Local on D drive”（当前用户，登录时运行 + 每 30 分钟运行一次，参数 `-Auto`）。已经试运行一次，结果 0。
- 这次手动执行的过程：
  - stopSite → 脚本 `-StopLocal` → 3560 个文件、2,365 MB 核对一致，联接已建好；
  - 第一次重新打开 Local 时，我是从工具的 shell 里启动的，工具结束后 Local 跟着退出了，同时数据库报了一次 “ibdata1 must be writable”（上一个进程还没完全退出）；
  - 改为用 explorer.exe 打开后，startSite 成功。
- 核对：首页和商店页 200；96 个商品；订单 #54 refunded、#55 completed，都没有变化；首页草稿 20 个积木。
- wp.sh 已经改回使用 `D:/Codex/LocalWP/app/resources/.../wp-cli.phar`（经联接访问，C 盘入口仍然有效）。

### EDIT-BLOCKS-08：手机不显示浮动卡片 + 去掉轮播圆点 · 2026-10-22 · Claude

- 用户决定：手机屏幕约 390 px，三张浮动卡片压在图上会挡住产品图，排在图下又会让首屏超过一整屏，所以**手机默认不显示**。轮播每张的 `cardLayout` 默认值改为 `{desktop: overlay, mobile: hidden}`。用户仍然可以在手机视图里把某一张改成“压在图片上 / 放在图片下方”。
- 首页草稿 #685 原来没有保存 cardLayout，所以新的默认值直接生效，不需要改数据。
- 轮播下方的圆点：这是轮播图积木自带的切换圆点（播放设置 → 显示圆点），不是写死的；删除轮播图积木时会一起删除。设计器里选中“轮播 · 第 N 张”时高亮框只框住这一张幻灯片，所以圆点在框外；选中整个轮播积木时圆点在框内。按用户要求在草稿 #685 和导入模板 `home-2026-10-20.json` 中把 `pagination` 改为 false。
- 测试：smoke-modules 第 404 项改为检查 `vc-cards-m-hidden`；十四套共 338 项通过。
- 需要 Codex：轮播模板在 mobile 的 cardLayout 为 hidden 时不输出卡片（或者加 class 隐藏）；`pagination` 为 false 时不输出圆点（模板已经支持）。

### EDIT-BLOCKS-09：点空白处取消选中 · 2026-10-22 · Claude

- 用户要求：装修页里点空白处取消锁定。`assets/design-editor.js` 新增 `clearSelection()`，以下几种情况会取消选中积木和展开的条目，高亮框随之消失：
  - 点预览四周的灰色空白（包括手机外框）；
  - 点预览页面里不属于任何积木、页头、页脚的地方；
  - 点积木列表下方的空白；
  - 按 Esc（在预览里或编辑器里都可以；打开媒体库或弹窗时不处理）。
- 点同一个积木里不属于任何条目的地方，会取消锁定条目（例如“轮播图 · 第 1 项”），回到选中整个积木。
- 设置面板里的空白不会取消选中，避免正在修改时误点。
- 验证：`node --check` 通过；smoke-design-editor 全部通过。没有浏览器实测（内置浏览器未登录）。

### MENU-NAV-01 菜单导航 + CUSTOM-PAGES-01 自定义页面 · 2026-10-22 · Claude（peptide-core 0.9.0）

用户要求：“页面”移到“店铺装修 → 自定义页面”；菜单导航负责页头和页脚；对照 Shopname 后台（yyy12345 店铺，只读查看，未保存任何修改）把我们没有的功能补上。

**Shopname 对照结果**
- 菜单导航有：
  - 两份固定菜单（顶部导航菜单 / 页尾菜单，不可删除）和自定义菜单，导出 / 导入，指定创建人；
  - 每一项有导航名称、链接页面，以及批量添加、添加二级目录、设置、删除；
  - 设置里有：打开方式、收录 / 不收录、链接 title、字体颜色、导航图标（80×80）、斜体、加粗、装饰线。
- 链接页面类型共 25 种。我们实现了能对应上的 15 种（无、首页、所有商品、分类、商品、页面、文章、购物车、我的账号、我的订单、登录、注册、忘记密码、搜索、自定义 URL）。专题、满减、优惠码、评论列表、博客列表 / 专辑这几种，我们站点没有对应功能，暂不提供。
- 自定义页面有：访问量、“结账条款”标签、生成常见条款、内容变量、AI 生成、单页 SEO。我们做了结账条款、变量和常见条款草稿。访问量等访客统计任务，单页 SEO 和“首页 SEO”一起做，AI 生成不做。

**实现**
- “页面”菜单：去掉左侧顶层“页面”，“店铺装修 → 自定义页面”直接打开页面列表（`edit.php?post_type=page`）。打开列表、新建或编辑页面时，左侧菜单停在“自定义页面”。
- `includes/class-menus.php`：
  - 使用 WordPress 自带的导航菜单。固定菜单的分类项上记 `_pc_fixed = header|footer`，删除时会被拦下（也包括从“外观 → 菜单”删）。
  - 第一次打开时按主题现有的页头 / 页脚链接建好：#29 顶部导航菜单 → 主题位置 primary，#30 页尾菜单 → 插件注册的位置 footer。
  - 站内对象按 ID 保存，显示时取最新网址。首页、购物车等类型存在菜单项元数据 `_pc_link` 里，网址在显示时计算。样式存在 `_pc_style`，nofollow 写进 xfn。
  - 最多两级，每份菜单最多 200 项，保存时带修改标记，冲突返回 409。
  - 导出时站内对象按 slug 记录，导入时一律新建菜单，找不到的对象改为无链接并列出。
- `includes/api/class-menu-controller.php`：`GET|POST /peptide/v1/menus/{id}`，需要 edit_theme_options。
- `includes/admin/class-menu-page.php`、`assets/menu-editor.js/.css`：列表页和编辑页（React），界面按 Shopname 做。
- `includes/class-custom-pages.php`：
  - 页面编辑页右侧有“VialCove 页面选项”：设置为结账条款，以及变量说明。页面列表显示“结账条款”标签。
  - 短代码 `[pc_var name="store_name|store_url|store_domain|support_email|support_whatsapp|support_telegram|year|today" link="1"]`。
  - 页面列表上方有“生成常见条款”：生成 Shipping / Return & refund / Terms / Privacy / Contact 的**草稿**，内容只有标题和方括号提示，已有同类页面时跳过（现有的 privacy-policy、refund_returns 草稿会被认出来）。
- 公共函数（**待写入 CONTRACT**）：
  - `peptide_core_get_menu( 'header'|'footer' )`：返回菜单树或 null。每一项包含 id、title、description、type、object_id、url、target、rel、attr_title、style（行内样式）、icon、current、children。
  - `peptide_core_get_checkout_terms()`：返回 `[{id, title, url}]`。

**验证**
- 新增 smoke-menus 51 项、smoke-custom-pages 18 项；十六套共 407 项通过。
- 菜单编辑器在测试页（使用站点自己的 WordPress 脚本 + 模拟接口）里实际操作过：渲染、设置弹窗、批量添加分类为二级目录、切换链接类型、上下移动、保存。
- 首页、商店页 200；新代码在 debug.log 中没有新警告。
- **没有在真实后台用浏览器看过**（内置浏览器未登录本地后台）。

**需要 Codex**
- 页头和页脚改为用 `peptide_core_get_menu()` 渲染，返回 null 时保留现在写死的链接。
- 结账页显示 `peptide_core_get_checkout_terms()`。

### COUPON-01 公开优惠码 · 2026-10-22 · Claude（peptide-core 0.10.0）

用户问 Shopname 的专题、满减、优惠码链接怎么做，选择先做优惠码。满减、专题页以后再做。

**做法**：优惠码直接用 WooCommerce 自带的优惠券（打折方式、最低 / 最高消费、次数、有效期、适用商品都是它原有的设置）。`includes/class-coupons.php` 补了以下几块：
- **后台**：优惠券编辑页“常规”里加了：在优惠码页面公开展示、展示标题、展示说明、排序。保存后显示网站详情页链接和**分享链接**，不展示时说明原因。优惠券列表多一列“公开”。
- **公开条件**：已发布、勾选公开、未过期、还有剩余次数、**没有限定邮箱**。限定客户的优惠码永远不公开，邮箱也不会出现在任何输出里。
- **前台**：
  - 页面：第一次把优惠码设为公开时，自动建好已发布的 Coupons 页面（短代码 `[pc_coupons]`，页面 ID 存在 option `peptide_core_coupons_page`）。
  - 列表和详情：不带参数时显示列表，`?code=XXX` 显示详情（条件、适用 / 不适用的商品和分类、“用到我的购物车”）。
  - 复制：每张优惠码都有复制按钮。
  - 样式：主题可以用 `template-parts/coupons/list.php`（$args['coupons']）和 `detail.php`（$args['coupon'] 可能为 null、$args['list_url']）覆盖插件默认样式（`assets/coupons.css`）。
- **分享链接**：任何前台网址加 `?pc_coupon=XXX` 都可以。
  - 打开后跳回不带参数的同一页。
  - 购物车满足条件时立刻使用；不满足时先存进 WC 会话，加入商品或改数量后自动再试。
  - 过期、用完、未发布的直接放弃并提示。
  - 没有公开的优惠码也能通过链接使用，方便定向发放。
- **菜单**：新增链接类型“优惠码列表页”和“优惠码详情页”。详情页按优惠码 ID 记录，网址显示时生成。优惠码过期或不再公开时，这一项自动隐藏，导出时按优惠码记录。
- **公共函数**（待写入 CONTRACT）：`peptide_core_get_public_coupons()`、`peptide_core_get_public_coupon( $code )`，返回字段见 functions.php 注释。

**验证**
- 新增 smoke-coupons 36 项，十七套共 443 项通过。
- 通过真实网页请求检查过：打开 `/shop/?pc_coupon=…` 会 302 跳回 `/shop/`；加入 $44.98 的商品后，Store API 购物车里已经带上这张最低消费 $40 的优惠码。测试优惠码已删除。
- debug.log 没有新警告。
- 目前站点没有优惠码，也还没有 Coupons 页面，要等店主第一次把优惠码设为公开时自动生成。
- 没有在真实后台用浏览器看过。

**需要 Codex**：Coupons 页面的样式（可以用上面两个模板覆盖），以及在菜单 / 页头里放入口。

### VISITOR-ANALYTICS-TEST-01 访客追踪后端 + CONTRACT 同步 · 2026-10-22 · Claude（peptide-core 0.11.0）

用户转达：Codex 需要我提供接口。Codex 的前端采集组件（10-21 已完成，默认不启用）在等后端协议；菜单 / 条款 / 优惠码前端已对接，等我同步 CONTRACT。

**实现**（完整协议：`docs/handoffs/tracking-protocol.md`）
- `includes/tracking/`：
  - Schema：四张表 visitors / visits / events / tabs，时间用毫秒，is_test=1；
  - Tracker：开关、HttpOnly 访客 cookie、30 分钟切分访问、关联账号、给主题的配置；
  - Ingest：白名单校验、按访客 + event_uid 去重、按 sent_at 校正时钟、乱序不覆盖状态、限流；
  - Server_Events：Woo 加购 / 下单 / 支付 / 状态 / 登录，订单上记下访客和访问编号；
  - Query：实时访客、访客详情、时间线、统计。
- 接口：`/track/session`、`/track/events`（公开）；`/track/admin/*`（需要 manage_woocommerce）。
- 后台：新菜单“访客数据”，包括实时访客（每 5 秒刷新，多标签页分开显示）、访客详情 / 时间线、转化统计。页面上有开关和清空测试数据（需要 manage_options）。
- 命令：`wp peptide tracking enable|disable|status|purge`。
- 默认关闭，测试后已关闭并清空数据。表已建好（v1）。

**验证**
- 新增 smoke-tracking 45 项，覆盖：协议、重复、乱序、无效、时钟、两个标签页、视频、413 / 400 / 429 / 403、30 分钟新访问、真实加购、登录关联、下单 / 支付只记一次（支付回调没有 cookie 也能关联）、失败支付、查询、权限。
- 十八套共 488 项通过。
- 真实网页请求：首页发了 HttpOnly 的 `pc_vid`；POST 两条事件全部 ack；通过网页加购后，服务端在同一次访问里记了 add_to_cart，阶段 2。
- 后台三个页面用真实数据在测试页里看过（实时、详情时间线、漏斗）。修了两个小问题：页面在后台时首次加载被跳过；漏斗里 0 的进度条显示成一小条。
- smoke-coupons 第 25 项改为也接受 Codex 主题模板的“unavailable”文案。

**CONTRACT**：增补了菜单、结账条款、公开优惠码三组函数，`?pc_coupon=`，以及访客追踪路由和规则。

**需要 Codex**：发送适配器：
- 生成 `vc_tab_id`；
- 用同源 fetch POST，带上 sent_at；
- ack 加上 retry=false 的 rejected；
- 离页用 keepalive；
- 403 时 stop。

做好后开启追踪联调：多标签页、匿名 → 登录、测试订单。

### PROMO-01 满减活动 · 2026-10-22 · Claude（peptide-core 0.12.0）

用户决定：在 Shopname 的专题、满减、优惠码三种链接里，先做优惠码，再做满减（“开始吧”）。专题页还没做。

**实现**：`includes/class-promotions.php`
- **后台**：自定义类型 `pc_promotion`，放在“营销 → 满减活动”（没有营销菜单时放在 WooCommerce 下面），使用优惠券的权限。
  - 规则：按金额或按件数，最多 5 档；减固定金额或打百分比折扣；开始 / 结束时间（店铺时区）；范围是全部商品、指定分类或指定商品；可以排除促销中的商品；可以设置能否和优惠码同时使用；可以在活动页面公开展示，并写说明。
  - 发布 = 启用，草稿 = 停用；规则有错时自动改回草稿并提示。
  - 列表多了档位、时间、状态（未开始 / 进行中 / 已结束）和公开四列。
- **购物车**：每次计算总价时，在所有进行中的活动里选优惠最多的一个，加一笔负数费用（不计税），不叠加。不能和优惠码同时用的活动，在购物车里有优惠码时跳过。订单费用行记下 `_pc_promotion_id`。
- **进度**：`progress()` 给出已用上的活动和下一档提示（例如 “Add $5.02 more to get $5.00 off”）。同样的数据合并进现有的 Store API 购物车扩展 `extensions.peptide_core.promotion`。同一个端点和命名空间只能注册一次，所以写在 `Api\Store_Api` 里，没有另外注册。
- **页面**：第一次公开活动时自动建好 Offers 页面（`[pc_promotions]`），`?promo=ID` 显示详情；默认样式和 Coupons 页面共用。主题模板覆盖方式见 CONTRACT。
- **菜单**：新增链接类型 promotions / promotion。
- **公共函数**：`peptide_core_get_promotions()`、`peptide_core_get_promotion()`、`peptide_core_get_promotion_progress()`、`peptide_core_get_product_promotions()`，已写入 CONTRACT。

**注意**：演示商品都带“对照价”（PRICE-SALE-01），WooCommerce 会把它们都当成“促销中”。所以勾选“促销中的商品不计入”后，现在所有商品都不会计入。

**验证**
- 新增 smoke-promotions 33 项，十九套共 521 项通过。
- 真实网页请求：临时活动“满 $50 减 $5 / 满 $100 减 $15”，购物车 1 件 $44.98 时提示 “Add $5.02 more to get $5.00 off”；3 件 $134.94 时费用行 −$15.00，合计 $119.94。临时活动已删除。
- debug.log 没有新警告。
- 目前站点没有满减活动，也没有 Offers 页面。
- 没有在真实后台用浏览器看过。

**需要 Codex**：
- Offers 页面模板；
- 购物车 / 抽屉显示 `extensions.peptide_core.promotion` 的已省和“再买…”提示；
- 商品页显示参加的活动。

### OVERVIEW-01 后台首页数据大盘 · 2026-10-22 · Claude（peptide-core 0.13.0）

用户要求：后台首页改成和 Shopname 概览一样的数据大盘。

**实现**
- 左侧菜单最上面新增“概览”（需要 manage_woocommerce）。打开后台首页（index.php）时自动跳到概览。WordPress 原来的仪表盘（含 VialCove 工作台）可以从右上角的链接打开（index.php?pc_classic=1）。
- `includes/class-overview.php`：数据口径都按店铺时区。
  - 销售额：已付款订单金额减退款；客单价；订单数。
  - 访问用户、回访用户：来自访客追踪。
  - 注册用户：排除员工角色。
  - 订单转化率 = 订单 / 访客。
  - 漏斗用 Tracking\Query::stats；访客 / 订单趋势：今天 / 昨天按小时，7 / 30 天按天。
  - 商品排名：已付款订单的销量，扣除退款。
  - 待处理：待发货（开了订单履约时按未发货）、保留中、缺批次、缺报告。
  - 未完成订单（7 天）：待付款 / 失败。Shopname 的“弃单召回”我们没有，这里如实说明，请手动跟进。
  - 实时：在线访客、今日订单 / 销售额。
  - 货币。
  - 美国零售节日倒计时：感恩节按 11 月第四个星期四计算，黑五 / 网一由它推出，其他节日按固定日期或第几个星期几。
- `includes/admin/class-overview-page.php` + `assets/overview.js/.css`：版式按 Shopname 做，顶部横幅加 7 个数字，下面是卡片，右侧一列。切换时间段时调用 `GET /peptide/v1/overview?range=today|yesterday|7d|30d`（需要 manage_woocommerce）。顶部数字固定显示今天，下面的漏斗、趋势和商品排名跟着时间段变。趋势图是插件自己画的 SVG，没有引入图表库。
- 追踪关闭时，页面上方会提示访客类数字为 0。

**验证**
- 新增 smoke-overview 21 项（时间段、节日、订单 / 注册 / 访客 / 回访、转化、漏斗、序列、商品排名、权限、清理），二十套共 542 项通过。
- 页面用示例数字在测试页里看过，版式接近 Shopname（截图在对话里）。真实后台没有用浏览器看过。
- 店铺时区现在是 UTC（WordPress 设置 → 常规 → 时区）。如果要按北京时间统计，需要店主修改时区。

### OVERVIEW-03 店铺时区改为美国太平洋时间、概览只统计真实订单 · 2026-10-22 · Claude

用户要求：
- 时区用美国时间（用户记得是 -8，广告投放在美国）；
- 概览只用真实数据，不用虚拟数据；
- 数据每天 0 点准时刷新。

- **时区**：按用户要求把 WordPress 设置改为 `timezone_string = America/Los_Angeles`（gmt_offset 清空）。-8 是太平洋标准时间，夏令时期间是 -7：现在是 PDT −07:00，2026-11-01 起是 PST −08:00，会自动切换。
  - 影响：概览、访客统计、满减活动的开始 / 结束时间、WooCommerce 订单显示时间，都按洛杉矶时间计算。
- **真实数据**：概览的数字一直是打开页面时从数据库实时算的；之前截图里的 155.75 等只是测试页里的示例数字。
  - 现在把**测试订单**排除出销售额、订单、客单价、转化率、趋势和商品排名。测试订单是指：订单上保存的付款方式名称以 “Test payment” 开头，或者包含演示商品（`_pc_demo_seed`）。
  - 页面会显示这个时间段排除了几笔。今天的 #2299（Demo Peptide A，测试付款 $9.98）已经被排除。
  - 待处理的数量仍然包含所有订单，因为它们是要处理的事务。
- **0 点刷新**：“今天”按店铺时区 0 点切分。页面开着时每分钟刷新一次；到店铺时区 0 点会立刻刷新，`summary.next_midnight_ms` 由服务器给出。切回这个浏览器标签时也会刷新。
- **访客数据页**：按天统计和日期筛选改为店铺时区（原来是 UTC）；时间线和列表的时间用浏览器的 Intl 按店铺时区显示。
- **测试**：smoke-overview 增加 3 项（测试订单排除、识别、时区标签和下一个 0 点），二十套共 545 项通过。

### DASHBOARD-01 概览改名为仪表盘、去掉 WordPress 仪表盘、加客户追踪卡片 · 2026-10-22 · Claude

用户要求：
- 在概览右侧空白处加“客户追踪”，进来的客户都单独显示，点进去是单独的追踪页面；
- 删掉 WordPress 原来的仪表盘；
- 把概览改名为仪表盘。

- **菜单**：数据大盘的菜单名改为“仪表盘”（dashicons-dashboard，排在最上面）。对能看到它的人（manage_woocommerce）用 `remove_menu_page( 'index.php' )` 去掉原来的“仪表盘”，同时去掉了右上角“WordPress 仪表盘”的链接和 `pc_classic` 入口。打开后台首页总是来到数据大盘。
  - WordPress 的“更新”子菜单随原仪表盘一起隐藏，需要时打开 update-core.php，或者从插件页面更新。
  - 原来的“VialCove 工作台”小工具已经没有入口，它的待办在数据大盘的“待处理”里。
- **客户追踪卡片**（右侧一列，放在商品排名下面）：
  - 内容：最近 30 分钟的访客，每人一行，显示在线 / 空闲 / 离线、名字（登录账号或“访客 #ID”）、当前页面（商品名）、打开了几个页面、最后信号多久之前。
  - 刷新：每 10 秒刷新一次（`/track/admin/live`），浏览器标签在后台时暂停。
  - 点击：打开“访客数据 → 访客详情”，也就是单独的追踪页面，有访问历史和时间线。
  - 追踪关闭时，卡片里提示到“访客数据”开启。
- **翻译**：这一轮第一次生成翻译时，命令在仓库根目录运行，生成了一个没有用的 `languages/` 目录，已经删除，然后在插件目录重新生成。之前几轮的翻译都抽查过，正常。
- **验证**：在模拟的后台请求里，第一个菜单是 pc-overview（仪表盘），index.php 已经不在菜单里，页面可以访问。卡片用示例访客在测试页里看过。二十套共 545 项通过。

### ADMIN-MENU-01 删掉文章 / 媒体 / 评论 / WooCommerce 菜单 · 2026-10-22 · Claude

用户要求：左侧菜单的“文章、媒体、评论、WooCommerce”直接删掉。

- `includes/admin/class-admin-menus.php`（只对 manage_woocommerce 生效）：
  - 去掉四个顶层菜单。`remove_menu_page()` 每次只去掉第一个匹配项，所以循环执行到没有为止。
  - WooCommerce 菜单下有开店必需的页面，所以移到新的顶层菜单，参照 Shopname：
    - **订单**：wc-orders，带待处理数量；
    - **顾客**：wc-admin 的 /customers；
    - **店铺设置**：WooCommerce 设置、VialCove 设置、系统状态。
  - 这些页面本身都没有变。打开时左侧菜单停在新的位置（parent_file / submenu_file）。
  - 文章、媒体、评论的页面也都还在，只是菜单里没有入口。媒体库在商品图、装修选图时照常可以用。
- 说明文字里的“WooCommerce → VialCove 设置”改为“店铺设置 → VialCove 设置”，CONTRACT 同步修改；launch-check 的 HPOS 提示也改了路径。
- 新增 smoke-admin-menus 8 项：在命令行里手动载入 WC_Admin_Menus 模拟后台菜单，检查四个菜单已去掉、新菜单的位置，以及 4 个移走的页面仍能打开、菜单高亮正确。
- smoke-overview 最后一项改为只检查测试自己建的记录：Codex 已经开启追踪并接通适配器，测试期间有真实访客进来，原来比较今天总数会误报。
- 全部 21 套测试通过。

### VISITOR-CART-01 访客当前购物车 + 追踪并发修复 · 2026-10-22 · Claude（peptide-core 0.14.0，追踪表 v2）

用户让我看 Codex 要的接口：
- VISITOR-CART-01：在“实时访客”加“购物车”列，点开弹窗看客户当前车里的商品，不是历史加购；
- Codex 19:17 指出的两个并发边界。

**购物车**（`includes/tracking/class-carts.php`）
- 来源只有 WooCommerce。`woocommerce_cart_updated` / `woocommerce_cart_emptied` 时标记，请求结束（shutdown 5）时把当前购物车整理成快照，存到 visitors.cart / cart_ms / cart_key（Woo 会话键）。前端不上报。
- 快照内容：每行有 product_id、variation_id、名称、规格（属性：值）、SKU、数量、单价、行金额和缩略图；另有件数、小计、优惠和优惠码、币种。
- 状态：
  - active：有商品，Woo 会话还在；
  - expired：有商品，但 woocommerce_sessions 里的会话已过期或被删除；
  - empty：空车；
  - unknown：还没有同步过。
- 接口：`/track/admin/live` 每位访客带 `cart` 摘要；新增 `GET /track/admin/visitors/{id}/cart` 返回明细（manage_woocommerce）。
- 后台：实时访客表格新增“购物车”列，显示“查看（N 件）+ 小计”或“空车 / 尚未同步 / 会话已失效”。
  - 弹窗挂在 document.body 上，所以列表每 5 秒刷新时不会关掉；有刷新、关闭、Esc，关闭后焦点回到原来的按钮。
  - 弹窗内容：图、名称、规格、SKU、数量、单价、金额、优惠、小计、更新时间。

**并发修复**
1. 标签页状态：tabs 新增 last_page_instance / last_seq。先 `INSERT IGNORE` 保证这一行存在，在线信号用 `GREATEST` 单独更新。状态字段用一条带条件的 UPDATE 原子写入，条件是：同一页面实例 seq 更大，或者不同页面实例 occurred_ms ≥ 已保存的时间。各 SQL 片段分别 prepare 后拼接，不整体再 prepare，避免值里的 % 被当成占位符。
2. 访问创建：`Tracker::visit` 用 MySQL `GET_LOCK('pc_trk_visit_<访客>', 5)` 包住“查找最近访问 / 新建”，沿用已有访问时立即把 last_ms 推到现在。
- 表结构 v2：`Tracker::register_hooks` 在表版本落后时自动升级（只加列），本地站点已经升级，Codex 正在用的数据没有动。

**验证**
- smoke-tracking 增加 11 项（同页低序号晚到不覆盖、% 安全、重复查找沿用访问、购物车四种状态、明细字段、后台接口、live 摘要、钩子在请求结束时记录），共 56 项。
- 全部 21 套共 564 项通过。
- 真实网页请求：新浏览器打开首页，加 3 件 $44.98 的商品后，快照是 active、3 件、$134.94。测试访客已删除，只删了这个编号。
- 另外删了一个之前测试残留的访客行（#39：没有浏览器信息、没有事件）。原来的访客 #21 和它的 50 个事件没有动。

### VISITOR-CART-01 后续 + VISITOR-ARCHIVE-01 访客归档 · 2026-10-22 · Claude（peptide-core 0.15.0，追踪表 v3）

来源：
- Codex 20:56 / 21:32 的复核：购物车接口 23/23 联调通过，指出弹窗竞态 / 焦点问题、共享会话和会话迁移的漏洞，并交付了增强弹窗组件；
- 用户新要求 VISITOR-ARCHIVE-01（经 Codex 转达，审查见 `D:/Codex/Tasks/peptide-platform/outputs/visitor-cart-20261022/archive-audit.md`）。
- 另外按用户要求，从群记录中删除了我 20:38 的“讨论摘要：访客追踪转正式版”。room.py 没有撤回命令，所以在 room 的文件锁下用它的 replace_atomic 删掉了这一条，删除前做了备份。

**购物车改为直接读 Woo 会话**（`class-carts.php` 重写）
- 唯一来源是 woocommerce_sessions。后台查看时按访客绑定的会话键读会话里的 cart / cart_totals / applied_coupons。同一个会话被多个访客编号共用时看到的是同一份当前购物车。规格取客户实际选择的值（item['variation']）。
- 绑定只来自服务器端已校验的 `WC()->session->get_customer_id()`，在购物车从会话载入、改动、清空时更新（请求结束时写一次，内容没变不写库）。没有访客行且车是空的时不建行，避免爬虫每次请求都写库。
- 游客登录：`woocommerce_guest_session_to_user_id` 时把绑定从游客会话改到账号会话。查找顺序是先用浏览器最后绑定的会话，失效后再试账号会话。
- 会话失效时退回最后快照，状态 expired。
- **弹窗换成 Codex 的组件**：`assets/visitor-cart.js`、`visitor-cart.css`、`visitor-cart-adapter.js`，从他的交付目录原样复制。我审过代码，内容都用 textContent 写入，请求有代次和取消，焦点会回到重绘后的按钮，Tab 键不会跳出弹窗。我原来的弹窗代码删掉了。组件自带中文文案，没有走插件翻译。

**访客归档**
- `Query::presence()` 按访客的全部标签页聚合，状态有 active / idle / away / left / gone，规则写在 CONTRACT。实时列表先按访客取最近信号，再取这些访客的所有标签页，不会因为条数上限漏掉标签页。
- `Query::archive()`：已结束的访问。结束的条件是最后活动超过 30 分钟，或者这位访客全部标签页都离开（离页信号）。每行有离站原因、最后页面、开始 → 最后信号、浏览数、阶段、订单、购物车摘要。支持游标分页，以及日期 / 设备 / 访客 #ID、姓名、邮箱筛选。这只是查询视图，不移动、不删除数据；访客回来后重新出现在实时列表。
- 访问历史和时间线都改成游标分页（原来分别固定 100 条和 2000 条）。心跳在 SQL 里过滤，隐藏心跳后仍然能翻到末尾。
- 后台：“访客数据”下新增“访客归档”子页；访客详情加了状态和购物车卡片，访问和时间线都有“加载更多”。实时列表的统计卡片改为 在线 / 空闲 / 暂离。仪表盘的“在线访客”只算 active / idle。
- 表 v3：visits 增加 `KEY last_ms (last_ms,id)`，启动时自动升级。
- 没做：Codex 建议的“D 盘导出”（可选）。数据仍只在 Local 的 MySQL 里，库文件在 C 盘的 Local 运行目录，没有迁移。

**验证**
- smoke-tracking 77 项。新增的覆盖：
  - 离站判断 6 项；
  - 宽限期内仍在线、过了宽限移出实时列表、归档原因和最后页面、30 分钟内回来是同一次访问并重新进入实时列表、超时归档且事件保留、筛选；
  - 时间线分页和心跳过滤、访问分页；
  - 共享会话、会话迁移、会话失效、空车不建行；
  - 后台接口和权限。
- 全部 21 套共 585 项通过。
- 归档页和弹窗在测试页里用真实归档数据（访客 #21）看过，购物车用的是示例数据。
- 本地数据：访客 #21 的 50 个事件保留，表从 v2 自动升级到 v3。

### CART-RECOVERY-01 弃单召回 · 2026-10-22 · Claude（peptide-core 0.16.0）

用户在“下一步做什么”里选了弃单召回（Shopname 的“弃单激活 / 购物车召回 阶段一、二”）。

- **数据来源**：woocommerce_sessions。用真实网页请求核对过：区块结账填邮箱（Store API `cart/update-customer`）后，邮箱会立刻写进会话的 customer.email。不依赖访客追踪。
- **记录**：`includes/class-recovery.php`，表 `wp_pc_recovery`（DB 选项 `peptide_core_recovery_db`）。
  - 弃单 = 有效会话里有商品、有邮箱、不是员工。
  - 购物车有变化时重新计时，已经发过的阶段不重发。
  - 结束状态：清空购物车 → closed，会话过期 → expired，退订 → unsubscribed；在第一封提醒之后付款 → recovered（记订单号和金额），之前付款 → converted。
- **发送**：
  - WP-Cron 每 10 分钟扫描（`peptide_core_recovery_scan`，只在开启时排程），或 `wp peptide recovery scan|status`。
  - 默认 60 分钟后发第一封，再过 24 小时发第二封，可以关掉第二封。
  - 邮件用 WC 邮件模板，内容：商品清单、小计、可选的优惠码、“完成订单”按钮、店铺名和实体地址、退订链接，以及 List-Unsubscribe 头。
  - **WooCommerce 设置里没有店铺地址时不发**（CAN-SPAM）。退订只保存邮箱的 sha256。
  - 发送前再查一次这个邮箱有没有付款。
- **链接**：`?pc_recover=<id>.<token>` 恢复购物车（已有的商品不重复添加），没填邮箱时预填，按设置用优惠码（走 Coupons::remember），然后进结账页。`?pc_unsub=<id>.<token>` 退订。令牌是 32 位随机数，用 hash_equals 校验。
- **后台**：“订单 → 弃单召回”（订单菜单新增“所有订单”子项）：
  - 30 天统计：弃单、已提醒、点击、召回订单、召回率、召回金额；
  - 设置、发示例邮件给自己、立即扫描；
  - 按状态筛选的列表。
  - 默认关闭。
- **测试**：smoke-recovery 25 项，邮件用 pre_wp_mail 拦截，不会真的发出。全部 22 套共 610 项通过。
- **注意**：
  - 本地目前**没有填店铺地址**，所以开了也不会发；本地邮件只会发到 Mailpit；正式上线要配置真正的发信服务。
  - 测试曾经两次用真实管理员账号的会话键写入、删除测试会话：管理员前台购物车如果有东西，已经被清空。之后改成用临时店长账号测试。
  - 我用 curl 探测时产生了两个追踪访客（#64、#65）和两个 Woo 会话，都已删除。

### OVERVIEW-TIDY 仪表盘排版整理 · 2026-10-22 · Claude（peptide-core 0.16.0，只改了后台）

用户反馈“这里的排版规整一些，好多都是乱的”。只动了 `assets/overview.css`、`assets/overview.js` 和 `includes/admin/class-overview-page.php` 里的文字和链接，数据接口没有变。

- **顶部数字面板**：去掉 82% 居中，和下方卡片左右对齐；标签在上、数字在下，各格高度一致。手机上 2 列，第 7 格占一整行。
- **数字格**（待处理 4 格 / 未完成订单 2 格 / 实时数据 3 格）：
  - 用 `--pc-ov-cols` 固定列数，每格是浅底色块，同宽同高；
  - 标签过长时只显示一行，鼠标悬停显示全文；
  - 待处理一行比例改成 3:2，两张卡片一样高；
  - 实时数据的销售额改成“$0.00”，不再在标签里写 (USD)，避免被截断。
- **未完成订单**：
  - 原来写着“还没有弃单召回邮件”，已过时，改成按设置显示“弃单召回已开启…”或“未开启…”；
  - 右上角加了“弃单召回 ›”链接（`links.recovery`、`cfg.recovery`）。
- **转化漏斗**：
  - 原来每行宽度递减，导致右侧百分比位置不齐；
  - 改成每行同宽，按占访客比例填充；名称、人数、占访客、上一步转化四列对齐，加了“上一步转化”列名；
  - 手机上隐藏“占访客”列。
- **右侧栏**：
  - 货币放在卡片标题行，节日表日期和“还有 N 天”右对齐；
  - 空状态改成紧凑的灰底块；
  - 宽度 ≤1200 时四张卡片排成 2×2，≤782 时单列。
- **漏斗和趋势图**：宽度 ≤782 才改为上下排列，笔记本上的图表不会被拉得很高。
- **检查**：
  - 本地测试页在 1900、1100、375 宽度下截图核对过，手机没有横向滚动；
  - 22 套 610 项测试全部通过，首页返回 200。
  - 没有在登录后的真实后台截图：内置浏览器里的后台是未登录状态。

### OVERVIEW-CHART 趋势图指标切换 · 2026-10-23 · Claude（提交 fe8697d）

用户发来 Shopname 截图，要求趋势图能切换指标，并在鼠标悬停时显示数值。

- **指标下拉**：访客数 / 浏览量 / 订单数 / 销售额 / 订单转化率。选择会记在浏览器本地（localStorage `pcOvMetric`）。
- **标题**：显示所选指标在整个时间段的合计。访客数是去重后的人数。
- **悬停效果**：竖线、圆点和提示框（完整时间 + 数值）。提示框靠右边缘时会翻到左侧。
- **纵轴**：最多 5 格，刻度取 1 / 2 / 5 × 10ⁿ。
- **接口**：`/peptide/v1/overview` 的 `range.series[]` 新增 `start`、`page_views`、`sales`，`range` 新增 `page_views`。只增加字段，原有字段不变。
  - `page_views` 来自 page_view 事件，在 SQL 里按小时聚合；
  - `sales` 是订单金额减去退款。

### STORE-SETTINGS-01 店铺设置菜单与系统设置 · 2026-10-23 · Claude（peptide-core 0.17.0）

用户要求按 Shopname 做店铺设置下面的几项，并在系统设置里先为框出的三项做占位，具体内容以后由用户说明。

- **店铺设置子菜单**：
  - 物流设置 → WooCommerce 设置的“配送”标签页；
  - 税费设置 → `pc-tax`；
  - 支付设置 → WooCommerce 设置的“支付”标签页；
  - 系统设置 → `pc-system`。
  - 顶层菜单打开物流设置。
  - Shopname 的“员工管理”是锁定项，没有做。
- **税费设置**：WooCommerce 关闭税费时会隐藏“税费”标签页。
  - 关闭时（本地目前就是关闭的）显示说明和“开启税费并设置税率”按钮。按钮是 admin-post `pc_enable_tax`，带 nonce，需要 manage_woocommerce 权限；点击后设置 `woocommerce_calc_taxes=yes`，并跳转到税率表。
  - 已开启时直接跳到 WooCommerce 的税费页。
- **系统设置**（`includes/admin/class-store-settings.php`、`assets/store-settings.css`）：
  - 卡片页，宽屏 3 列、≤1280 2 列、≤782 1 列。
  - 三张占位卡片：基础配置、邮件通知、交易条款，标“即将推出”。点开是占位页，列出目前可以先用的现有设置。
  - 底部“其他设置”：WooCommerce 设置、VialCove 设置、系统状态，原来是菜单项。
- **菜单高亮**：
  - WooCommerce 设置按标签页高亮：shipping → 物流，tax → 税费，checkout → 支付，其他 → 系统设置；
  - VialCove 设置、系统状态归在系统设置下。
- **测试**：smoke-admin-menus 新增菜单结构、高亮、占位页、权限测试。全部 22 套 625 项通过。
- **页面检查**：页面用测试页在 1900 / 940 / 375 宽度核对过，没有在登录后的真实后台截图。

### OVERVIEW-FUNNEL 漏斗按 Shopname 样式 · 2026-10-23 · Claude

- 用户要求漏斗照 Shopname 的样式做，每一步显示两个数据。只改了 overview.js / overview.css。
- **条形**：逐级变窄，宽度 = 轨道宽 × (1 − 0.36 × i / (n − 1))，右侧留 5.5em 放箭头和数字。条内显示“阶段: 人数”和橙色的“访客转化：x%”（占第一步访客的比例）。
- **连线**：两条之间画折线，从上一条右端向右、向下，再用箭头指回下一条右端，旁边显示相对上一步的转化率。
- **检查**：用 JS 核对过连线端点和上下两条右端完全对齐（误差 0–1px），数字不会超出卡片，375px 宽度下没有横向滚动。
- **待用户决定**：Shopname 有 8 步（多出“添加地址 / 添加物流 / 添加支付”），我们目前是 6 步。要增加这几步，需要新的追踪事件和阶段编号，会影响 Codex 那边的访客页面。

### BASIC-CONFIG-01 系统设置 → 基础配置 · 2026-10-23 · Claude

按用户提供的 Shopname 截图（1–3 图）实现。页面在 `pc-system&section=basic`（`includes/admin/class-basic-config-page.php`），运行逻辑在 `includes/class-store-config.php`（选项 `peptide_core_store_config`）。

- **店铺信息**
  - 店铺名称 → blogname。
  - 管理员邮箱 → 按 WordPress 原有流程，先写 new_admin_email、发确认邮件，确认后才生效。
  - 客服邮箱 / 客服名称 → WooCommerce 发件地址和发件人；发件人为空时用店铺名称。
  - Favicon → site_icon。**主题目前去掉了 wp_site_icon，固定输出自己的图标，需要 Codex 适配。**
  - 财务邮箱 → 接收付款类提醒。
  - WordPress 自身的设置只有 manage_options 能改，店长看到的是只读。
- **基础设置**
  - 店铺货币：有订单后锁定。
  - 时区。
  - 自动生成 SPU：18 位随机数或商品 ID，存 `_pc_spu`。
  - 自动生成 SKU：保存时 SKU 为空才生成，单款式商品用 SPU，多款式商品的款式按 `{SPU}-{SKU_CODE}`。
  - 货币格式：预设组合。
  - 回访用户计算时间：仪表盘的“回访用户”改为“距离第一次访问超过 N 小时的访问”。
  - 禁止爬虫：自定义 UA 关键词，加内置的扫描器 / 采集器名单；前台返回 403，后台、命令行、定时任务不受影响。
  - 后台登录验证码：wp-login.php 上的算术题，只检查表单登录。
- **店铺前台设置**
  - 语言：WPLANG。
  - 前台搜索字段：用 posts_search 额外匹配 SKU（含款式 SKU）、分类、标签。
  - 强制登录：未登录访客跳到账户页；隐私政策和结账条款页仍公开。
  - 注册邮箱验证：只针对账户页注册，结账时创建的账户不受影响。用户在验证前不能登录，验证链接 3 天有效，通过设置密码链接也算验证。验证邮件由后面的邮件模块发送（动作 `peptide_core_send_registration_verification`）。
  - 前台排序字段：可勾选、可拖动顺序，第一项为默认排序。
  - iframe：关闭时发送 X-Frame-Options: SAMEORIGIN。
  - 店铺经营状态：打烊 = WooCommerce coming soon，全站生效。
  - 商品链接结构：/product、/shop、/shop/%product_cat%，改完自动刷新固定链接。
  - 已下架商品：可选 404、跳首页或跳商店页。
  - 默认选中第一个有货的款式。
  - 售罄款式：可选隐藏。
- **公开联系方式**：公司名称和电话存在我们的选项里；国家、州、市、地址、邮编写入 WooCommerce 店铺地址（弃单邮件的实体地址也用这里）。新增 `[pc_var]` 变量 company_name、company_phone、company_address。
- **没有做的 Shopname 平台专有项**：临时域名、临时域名访问密码、静态资源路径、代码模式、页面缓存时间、广告像素延迟、多币种结算、防爬 / 高风险 IP 限制、商品意向统计、标题搜索方式、前台图形验证码、已删除款式。
- **测试**：smoke-basic-config 42 项。全部 23 套 667 项通过。实测首页带 X-Frame-Options，sqlmap 的 UA 返回 403，Googlebot 的 UA 返回 200。

### SHIPPING-PLANS-01 物流设置 · 2026-10-23 · Claude

按用户截图（4–7 图）实现。店铺设置 → 物流设置改为我们的页面 `pc-shipping`（`includes/admin/class-shipping-page.php`），共用编辑部件在 `class-plans-editor.php` / `assets/plans-editor.js` / `assets/plans.css`。

- **数据**：选项 `peptide_core_shipping_plans`（`includes/shipping/class-plans.php`）。
  - 方案 = 名称 + 区域（国家，可细到州 / 省）+ 运费；自定义方案另外选择商品。
  - 一个商品只能属于一个自定义方案，保存时会从其他方案移出。
  - 运费包含：名称、启用、图标、邮编范围（90001 / 900* / 90000-90999）、费用规则（订单价格 / 件数 / 重量，区间含下限不含上限，上限为空表示不限）、费用。
- **计算**：`Shipping\Plan_Method`（全局运费方式 `pc_plans`，不属于任何运费区域）。
  - 购物车按方案分组：不在自定义方案里的商品走通用方案。
  - 只有一组时，列出全部符合条件的运费。
  - 多组时，每组取最便宜的一项，再按“运费排序与计算规则”相加或取最高，合成一项。
  - 任意一组在收货地址没有可用运费 → 不给运费，结账页提示无法配送，和 Shopname 一样。
  - 只要有一个方案选择了区域，方案就接管运费：`woocommerce_package_rates` 去掉 WooCommerce 原有运费区域给出的运费。没有方案时原有区域照常使用。
  - 运费 meta 带 `_pc_plans`、`_pc_icon`，下划线开头，不显示给顾客。
- **后台**：
  - 列表页：通用 / 自定义两个标签；自定义物流可以按商品标题搜索；顶部有“运费排序与计算规则”弹窗。
  - 编辑页：用 WooCommerce 的商品搜索选择商品；“添加国家”可搜索、可全选；可以只选部分州 / 省；运费弹窗可选媒体库图标；支持复制其他方案的运费、删除方案。
  - 页面会提示两种情况：方案中有国家不在 WooCommerce 的“销售地区”里；还没有方案时仍在使用原有运费区域。
- **注意**：本地 WooCommerce 只卖美国（allowed=specific: US）。要卖到其他国家，需要先在 WooCommerce 设置 → 常规里加上。
- **测试**：smoke-shipping-plans 31 项，包括用游客购物车实际计算运费。全部 24 套 698 项通过。

### TAX-PLANS-01 税费设置 · 2026-10-23 · Claude

按用户截图（8 图）实现。店铺设置 → 税费设置为 `pc-tax`（`includes/admin/class-tax-page.php`），数据在 `includes/tax/class-plans.php`，选项为 `peptide_core_tax_plans`。原来的“税费未开启”占位页已删除。

- **方案内容**：名称 + 区域（国家，可细到州 / 省，每个区域一个税率 %）+ 适用商品（所有需收税商品 / 仅特定商品）+ 是否对运费征税。
- **同步到 WooCommerce 税率表**，结账、订单税额、退款都由 WooCommerce 自己计算：
  - 适用所有商品的方案写入标准税率类；
  - 仅特定商品的方案建立税率类 `pc-tax-<id>`，并把选中商品的税率类改成它；
  - 每个区域写一条税率，选了州时每个州一条；
  - 方案记录自己写入的税率 ID，修改或删除时只动这些税率，手工建的税率不受影响；
  - 商品移出方案时税率类恢复为标准。
- **开关**：列表页可以开启 / 关闭 WooCommerce 税费（`woocommerce_calc_taxes`，admin-post `pc_enable_tax`，需要 nonce）。本地目前是关闭的。
- **测试**：smoke-tax-plans 21 项，包括临时开启税费后用游客购物车实际计算加州 8% 和特定商品 2.5%，测试后恢复原样。全部 25 套 719 项通过。

### EMAIL-CENTER-01 邮件通知 + CART-RECOVERY-02 三阶段弃单召回 · 2026-10-23 · Claude（peptide-core 0.18.0）

按用户截图（9–14 图和最后一张“弃单”列表）实现。

**邮件通知**：系统设置 → 邮件通知（`includes/admin/class-email-center.php`）。

- **页面**：四个标签，顾客通知 / 弃单通知 / 购物发现 / 管理员通知，另有“邮件详情数据”。
  - 每张卡片对应一封 WooCommerce 邮件，缩略图用 WooCommerce 自带的邮件预览（iframe）。
  - 开关写回这封邮件的 enabled 设置（admin-post `pc_email_toggle`）。
  - “编辑”打开 WooCommerce 的邮件设置页。
- **顾客通知里映射的 WooCommerce 原有邮件**：新账户、处理中、保留、取消、Fulfillment 创建 / 更新、完成、退款、失败、重置密码、游客邮箱确认、发票、备注。
  - 邀请评价是 WooCommerce 的测试功能，从卡片开启时会同时打开这个功能。
- **新增的插件邮件**：`includes/emails/`，继承 WC_Email。注册名是不带命名空间的 `PC_Email_*`（`wc-classes.php`），因为 WooCommerce 的 section 和预览参数会去掉反斜杠。
  - 弃单通知 1 / 2 / 3：每封有开关、标题、正文、按钮、优惠券、延迟秒数，默认 300 / 1800 / 86400 秒，默认开启。
  - 购物发现：注册后延迟 1800 秒仍没下单，就发最近加购 / 浏览过的商品（账户购物车加上访客追踪），每人一次，默认开启。
  - 顾客注册邀请：游客付款后、账户页开放注册时发一次。
  - 注册邮箱验证：配合基础配置的开关。
  - 管理员邮件（默认关闭）：弃单通知（延迟 300 秒，每条弃单一次）、潜在重复支付（同一邮箱 24 小时内付了金额相同的订单，发到财务邮箱）、线下付款订单通知（订单进入保留时发；开启后 WooCommerce“新订单”不再为保留订单另发一封）。
  - 营销邮件（弃单提醒、购物发现）带实体地址、退订链接和 List-Unsubscribe。
- **邮件详情数据**：所有 WooCommerce 邮件发出后（`woocommerce_email_sent`）写入表 `wp_pc_email_log`，按类型统计 7 / 30 天的发送数和失败数，列出最近 50 封。保留 180 天。不统计打开率。
- **没做**：Shopname 的积分邮件（本店没有积分功能）、财务通知（Shopname 平台自己的账单）。

**弃单召回改版**：`includes/class-recovery.php`，表 v2，从 v1 自动升级。

- **触发条件**：顾客在结账页填了邮箱 **和地址**，购物车有商品，但没付款。
- **状态**：
  - 待召回 pending：还没发提醒；
  - 召回中 recalling：已发提醒，三封没发完；
  - 召回成功 success：通过邮件链接回来，或收到提醒后付了款；
  - 召回失败 failed：开启的提醒都发完，最后一封后 24 小时没回来，或会话已过期；
  - 已作废 void：没等提醒就付款、清空购物车、发提醒前会话过期、退订，或手动作废。
- **新增字段**：姓名、国家、配送方式、支付方式、草稿订单（用于显示订单编号）、合计、三封邮件的发送时间、邮件数、管理员通知时间、付款时间。
- **扫描**：WP-Cron 改为每 5 分钟一次（`pc_five_minutes`），只要有一封提醒是开启的就会排程。`scan()` 新增 `$only` 会话前缀参数，只给测试用。
- **订单 → 弃单**（`includes/admin/class-recovery-page.php`）：
  - 状态标签带数量，可按创建时间、订单编号 / 邮箱筛选；
  - 列：订单编号、顾客、国家、配送、总价、支付方式、支付时间、流水号、订单状态、召回状态、邮件发送时间、召回次数；
  - 可展开查看购物车和时间线，可手动作废，有立即扫描按钮，每页 50 条；
  - 顶部链接到“弃单通知”设置和“支付失败汇总”。
- **原有的** `?pc_recover=` / `?pc_unsub=` 链接不变。购物发现的退订用 `?pc_optout=`（邮箱 + HMAC 签名）。
- **测试**：smoke-recovery 43 项、smoke-emails 31 项；smoke-admin-menus 按新页面调整。全部 28 套 769 项通过。
- **注意**：
  - 本地没有店铺地址，所以提醒和购物发现不会发出；正式上线前要填写，并配置 SMTP。
  - 顾客收到的邮件文案默认是英文，可以在每封邮件的编辑页修改。

### TERMS-01 交易条款 · 2026-10-23 · Claude

按用户截图（15 图）实现：系统设置 → 交易条款（`includes/admin/class-terms-page.php` + `assets/terms-admin.js`），运行逻辑在 `includes/class-terms.php`，选项为 `peptide_core_terms`。

- **设置**：
  - 开关（开启后买家必须勾选全部条款）；
  - 标题名称（40 字）；
  - 多个方案：条款描述 / 复选框文字（100 字）、显示页面（结账 / 注册 / 登录）、是否默认勾选；
  - 每个方案有多个条款，名称 30 字；内容可加粗、斜体、链接、代码、颜色，可拖动排序，服务端用 wp_kses_post 过滤；
  - 效果预览、清空所有数据。
- **结账页**：
  - 每个方案是 WooCommerce 的必选附加字段，默认勾选用 `woocommerce_get_default_value_for_*`；
  - 条款链接和弹窗由 `assets/terms.js` 插入，用 MutationObserver 防止区块重新渲染后丢失；
  - 勾选后订单保存 `_pc_terms` 快照，后台订单页显示。
- **注册页 / 登录页**：服务端输出复选框和弹窗，`woocommerce_registration_errors` / `woocommerce_process_login_errors` 校验。
- **实测**（本地区块结账，游客）：
  - 复选框出现，条款链接在其下方，弹窗显示内容；
  - Store API 不勾选时返回 400（`rest_not_in_enum`），勾选后只剩“未选支付方式”。
  - 测试产生的会话、草稿订单 #4242、访客 #122 / #123 都已删除，设置已恢复为未配置。
- **测试**：smoke-terms 20 项。全部 29 套 788 项通过。
- **和原有功能的关系**：VialCove 设置里的“结账确认”和自定义页面的“结账条款”链接仍然保留，没有合并。

### BASIC-CONFIG-02 基础配置：按 Shopname 的说明补全 · 2026-10-23 · Claude（peptide-core 0.19.0）

用户发来 Shopname 基础配置每一项的问号说明，要求都补进去。

- **问号说明**：每项都有深色提示框（`.pc-bc-help`，鼠标移上去或键盘聚焦时显示，支持换行）。文字按 Shopname 的意思写，并按本站实际行为调整。
- **按说明补齐的功能**（`includes/class-store-config.php`）：
  - 商品标题搜索方式：智能搜索（不超过 2 个词全部包含，超过则 80% 的词匹配，向上取整）/ 全部包含 / 仅前台智能。前台搜索和后台商品搜索都生效，后台用 `woocommerce_product_pre_search_products`，同时匹配 SKU 和 ID。
  - 多款式默认选中：首个 / 价格最低 / 价格最高 / 库存最多 / 不选中。
  - 已下架商品：404 / 可见但不可购买 / 可见且可购买 / 跳首页 / 跳商店。可见时草稿商品页能打开，带 noindex，商品列表仍然隐藏；可购买时放开 `woocommerce_is_purchasable`。
  - 强制注册 / 登录：新注册账户需要管理员在“用户”里批准（单个或批量），用户列表新增“账户状态”列。
  - 注册邮箱验证：“注册邮箱验证”邮件关闭时不再要求验证，避免顾客永远登录不了。
  - 前台图形验证码：“我的账户”的登录、注册表单加算术题。
  - 禁止爬虫访问：可勾选的常见爬虫（Googlebot、Bingbot、GPTBot 等）加自定义关键词，同时写入 robots.txt；“防爬 / 高风险 IP 限制”就是系统内置名单的开关，系统名单只读展示。
  - 回访用户计算时间：改成 Shopname 的算法，第一次访问当天结束后再过 N 小时（`class-overview.php`）。
  - 公开联系方式：首页输出 Organization JSON-LD（过滤器 `peptide_core_json_ld`）。
  - SPU：商品编辑页可以手动填写（只允许字母、数字、- 和 _，不能重复）；复制商品时不带 SPU。
- **页面底部**列出本站不适用的 Shopname 选项及原因：临时域名、缓存时间、像素延迟、代码模式、静态资源路径、商品意向统计、已删除款式、订单货币结算。
- **翻译脚本**：新增多行 msgid 的支持（含换行的字符串以前会被漏掉）。
- **实测**（真实 HTTP）：
  - 草稿商品在 404 模式返回 404；“可见但不可购买”返回 200 且带 noindex；“可见且可购买”返回 200 且有购买按钮；跳首页模式返回 302。
  - robots.txt 里出现勾选的 GPTBot。测试商品已删除。
- **弃单真实链路**也跑了一遍：Store API 加购、填邮箱和地址、扫描记录为待召回 → 临时填店铺地址后第一封真的发出（Mailpit 收到）→ 点链接 302 到结账，记录变成召回成功。测试数据已清理，店铺地址已恢复为空。
  - 顺手修了一处：没有草稿订单时，配送方式改为显示会话里的运费名称。
- **测试**：smoke-basic-config 67 项。全部 29 套 813 项通过。

### MARKETING-01 营销活动：满减活动 / 优惠码 / 运费折扣 · 2026-10-23 · Claude（peptide-core 0.20.0）

用户要求删掉“营销”下面的全部内容，按 Shopname 的 7 张截图重做三个页面。

- **菜单**：去掉 WooCommerce 的营销菜单（概览、优惠券和原来的满减活动），新的顶层菜单“营销活动”有三个子菜单：满减活动（`pc-promotions`）、优惠码（`pc-coupons`）、运费折扣（`pc-shipping-discounts`）。旧地址（优惠券列表 / 编辑页、满减活动文章列表 / 编辑页、wc-admin 营销概览）自动跳到新页面；优惠码编辑页底部可以进 WooCommerce 原编辑页（`pc_classic=1`）设置排除商品、最高消费等。
- **列表页**（三个一样）：全部 / 未开始 / 进行中 / 已结束标签，搜索（0/32），勾选后批量启用 / 停用 / 删除，每行编辑和停用 / 启用，状态标签，分页（每页条数、前往第几页）。满减和优惠码有“设置”（选择前台页面，没有短代码时自动加上）；运费折扣有“同时满足多个折扣活动时取最低折扣值”的提示。
- **满减活动**（`includes/class-promotions.php`，页面 `includes/admin/class-promotions-page.php`）：
  - 7 种优惠类型：满额减元、满额打折、满件减元、满件打折、第 X 件 Y% off（多个层级时累加，例如第 2 件 50%、第 3 件免费）、满件一口价（最贵的 N 件按一口价）、满 X 免 Y（最便宜的 Y 件免费）；最多 5 个层级；上不封顶（一个层级，按门槛重复）。
  - 时间段 / 长期，显示店铺时区和“设置时区”链接；适用范围：全部商品 / 部分商品（商品搜索 + 表格）/ 专辑（商品分类）；活动商品默认排序；右上角 SEO 卡片（活动页地址、编辑页面、查看）；底部“使用规则”照 Shopname 原文。
  - Shopname 规则全部实装：同一时间同一商品 / 专辑只能有一个活动、只能有一个全部商品的活动（保存和启用时检查）；下单时每个商品只参加一个活动，优先级指定商品 > 指定专辑 > 全部商品，没达到门槛不改用别的活动，商品在多个专辑里用最新创建的专辑活动；不同商品的活动优惠相加（多笔费用）。
  - 活动详情页列出参加活动的商品（按排序），全部商品 / 专辑的活动会排除参加了更优先活动的商品。旧数据（basis / reward）照常工作。
  - 顺手修了进度提示的英文：“Add 1 more item more to get …”改为“Add 1 more item to get …”。
- **优惠码**（`includes/admin/class-coupons-page.php` + 规则 `includes/class-coupon-rules.php`）：
  - 活动名称（= 优惠券描述）、优惠码（0/15，随机生成）、优惠规则策略（不叠加 / 叠加 / 替换满减活动）、活动时间（开始时间是新字段）、优惠券数量、每人限用（无限制 / 一定数量 / 全局首单）、是否在优惠码列表显示。
  - 优惠条件：无门槛 / 满金额 / 满件数（只算适用商品）；优惠类型：折扣（%）/ 固定金额。适用顾客：所有人 / 指定顾客（邮箱）；适用范围：全部 / 部分商品 / 专辑；默认排序。
  - 列表的“使用状态”是“已用 / 剩余”。WooCommerce 会把优惠码存成小写，这里另外记下商家输入的大小写用来显示。没有策略的旧优惠码按“叠加”处理。
- **运费折扣**（`includes/shipping/class-discounts.php`，页面 `includes/admin/class-shipping-discounts-page.php`，选项 `peptide_core_shipping_discounts`）：
  - 固定折扣 / 限时阶段折扣（最多 5 段，各自折扣，不能重叠，段与段之间没有折扣）；运费减元（立减 X）/ 运费打折（X% off，100% = 免运费）。
  - 仅会员日有效：选星期几（店铺时区），只对已登录的注册顾客有效。适用顾客：所有人 / 指定顾客（邮箱）。
  - 多个折扣同时满足时，每个运费取折后最低的；税按比例减少；运费行记下折扣名称和原运费。
- **本站没有的选项**显示为不可选并写明原因：指定顾客标签、指定顾客级别（本站没有）、指定访客 ID（访客追踪还是测试功能）。截图里的“推荐应用”是 Shopname 应用市场，没有做。
- **实测**（真实 HTTP，游客走 Store API）：1 件时没有优惠、提示“再买 1 件”；2 件出现 −$5 的满减费用；“不叠加”的优惠码被拒绝并说明是哪个活动；“叠加”的优惠码和满减同时生效。本站现在只有 $0 的测试运费，运费折扣改用测试里的结账计算验证（$12 → $9，指定 VIP 邮箱 → 免运费）。测试数据、测试购物车会话和临时建的 Offers 页面都已删除。
- **测试**：新增 smoke-marketing 81 项；smoke-promotions、smoke-admin-menus 按新规则更新。全部 28 套通过（见提交说明）。
- **给 Codex**：一份购物车可能有多笔满减费用；运费折扣直接体现在运费金额上（meta 里有原运费）。详见 CONTRACT“满减活动”和“营销活动”。

### ADMIN-MENU-02 分析只留顶层菜单、去掉“支付设置”、精简 WooCommerce 设置标签 · 2026-10-23 · Claude

- **分析**：WooCommerce 分析下面的概述、产品、收入、订单、变量、分类、优惠券、税费、下载、库存、设置子菜单全部去掉，只留顶层“分析”菜单，点开是分析概览。从概览里的链接打开其他报表时，左侧菜单仍停在“分析”。
- **支付设置**：店铺设置下面的“支付设置”去掉了（WooCommerce 自己已有顶层“支付”菜单）。店铺设置现在是物流设置、税费设置、系统设置。打开支付设置页时，左侧菜单停在“支付”。
- **WooCommerce 设置的标签栏**：去掉用不上或已经有了的标签——配送（= 物流设置）、付款（= 支付菜单）、电子邮件（= 邮件通知）、站点可见性（= 基础配置的店铺经营状态）、集成、销售点、Multi-currency。保留常规（可销售国家、优惠码开关等，基础配置里没有）、产品（单位、库存、评价）、帐户和隐私（游客结账、账户创建）、高级（购物车 / 结账页面、接口、功能开关）。
  - 只是不显示标签，设置值不变。WooCommerce 判断标签是否存在时也看设置动作，所以邮件通知里的“设置”、物流设置里的“运费区域”等直接链接仍然能打开。
- 只改 `includes/admin/class-admin-menus.php`（系统设置卡片的说明文字顺带更新），页面地址都没变。smoke-admin-menus 29 项通过。

### MULTISTORE-PLAN-01 多店铺（投手店铺）方案 · 2026-10-23 · Claude

用户要求每个投手有自己的店铺，用积木各自装修（参照 Shopname 的多租户：同一个 IP，按域名区分店铺，只能登录自己的店，母版跟着店铺走）。

- 用户的选择：店铺完全独立；投手管装修、营销、只读数据、广告像素；先在新的本地站点试。
- 方案：WordPress 多站点（子域名模式）。站点 1 是母版，新店从母版复制。分 5 个阶段：本地跑通、开店与权限、广告像素、域名管理、总后台汇总。详见 [[multistore-plan-v1]]（`docs/handoffs/multistore-plan-v1.md`）。
- 还没开始实现，等用户在 LocalWP 建好多站点测试站。
