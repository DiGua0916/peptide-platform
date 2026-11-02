# 商品浏览统计交接

2026-11-02 · Codex · VISITOR-PRODUCT-STATS-01

本轮新增访客数据中的只读“商品浏览统计”，用于查看哪些主商品被经常浏览，以及查看对应访客的访问记录。功能直接汇总已有追踪事件，不新增采集事件、不建业务表、不回填历史，也不创建或修改商品、顾客、购物车或订单。浏览行为不代表购买。

## 入口与接口

后台入口：访客数据 → 商品浏览统计，`wp-admin/admin.php?page=pc-visitors-products`。`Tracking_Product_Page::register_hooks()` 在 `admin_menu` 优先级 58 注册子菜单，加载独立页面资源，不改既有访客页脚本或主题。

只读接口：`GET /wp-json/peptide/v1/track/admin/products`。

| 参数 | 含义 |
| --- | --- |
| `from`、`to` | 店铺日历日期 `YYYY-MM-DD`；两项均省略时为最近 7 天 |
| `device` | 空值、`desktop`、`mobile`、`tablet` |
| `q` | 商品名称匹配或完整商品编号；最多 100 字符，不承诺 SKU 搜索 |
| `sort` | `views`、`viewers`、`repeat_viewers`、`returning_viewers`、`last_view_ms`；默认浏览次数降序 |
| `page`、`per_page` | 正整数页码与分页条数；界面每页 20 条，接口最多 100 条 |
| `product_id` | 指定主商品时返回其浏览访客明细，固定最近浏览时间降序 |

列表返回日期、时区、采集状态、摘要、总数与商品行；商品行包含商品编号、名称、可用图片/公开链接、浏览次数、浏览访客数、重复浏览人数、回访浏览人数和最近浏览时间。排序同值时按商品编号稳定排列。

明细返回当前商品资料及访客行：访客编号、显示标签、当前账号类型、浏览次数、浏览此商品的访问次数、最近浏览时间、该次事件对应访问编号和既有访客详情链接。同一时间按访客编号稳定排列。页面支持日期/设备筛选、查询、排序、分页、返回排行、访客访问记录、加载/空/错误重试和浏览器前进后退。全部操作为 GET。

`tracking` 表示追踪表是否可用；为 false 时页面显示“追踪记录尚不可用”，不把缺少记录设施显示为正常零统计。`collection_enabled` 表示是否继续采集；为 false 时仍可查询已有历史。SQL 失败返回 503，可重试，不伪装成空结果。后台响应沿用禁止缓存处理。

## 指标口径

仅计入已采集且通过接收校验的 `source=client`、`type=product_view` 事件，不叠加 `page_view`、商品曝光、心跳或服务端成交事件。事件、访问、访客三者均须 `is_test=0`，且事件必须关联同一访客的真实访问。

日期筛选采用事件发生时间 `occurred_ms`，不是接收时间或访问起始时间。按店铺时区解析开始日零点及结束日次日零点，使用前闭后开范围；夏令时日界不硬算固定 24 小时。

| 指标 | 计算方式 |
| --- | --- |
| 被浏览商品数 | 所选范围内去重主商品编号数 |
| 浏览次数 | 满足条件的商品浏览事件数 |
| 浏览访客数 | 按匿名访客编号/浏览器去重；同一客户换设备可能分别计算 |
| 重复浏览人数 | 所选范围内，同一访客看同一主商品至少 2 次 |
| 回访浏览人数 | 所选范围内，同一访客在至少 2 个不同访问编号中看同一主商品 |
| 最近浏览 | 对应事件发生时间的最大值 |

全局摘要的浏览访客数跨商品去重，不能把各商品行的访客数直接相加当作全局 UV。刷新产生新的浏览事件时会增加次数；同一事件 UID 重传受已有唯一性约束，不会重复计数。已有记录之前未采集的行为不回填。

仍可解析的 `product_variation` 按原生 `post_parent` 归入所属主商品。商品已删除或不可用时保留原观察编号，显示“已删除或不可用商品 #编号”；不凭名称猜测、不重配到新商品，也不补造图片或历史规格归属。

明细中的账号名称及 `registered` / `guest` 类型来自该访客**当前绑定**的账号，不能解释为每次历史浏览发生时使用的账号，更不能解释为已购买。界面已明确说明这一点；邮箱形态的显示标签在界面退回访客编号，不展示邮箱或 IP 字段。

## 权限与隔离

接口、页面 render 和 enqueue 均复用 `Tracking_Controller::can_view_products()`。基础能力为 `manage_woocommerce`；若为 `pc_staff`，还必须拥有 `data` 模块授权。仅拥有其他业务模块而间接具备该能力不够。未登录为 401、无权限为 403；手工直达页面同样执行守卫，不能只依赖菜单隐藏。

请求使用既有 WordPress REST nonce。参数采用固定白名单、整数/日期界限与预处理查询；排序值不直接接受 SQL 标识符。界面通过文本节点及受限 HTTP(S) URL 渲染数据，不拼接 API HTML，不展示事件原始载荷、邮箱/IP 或订单资料。

## 源码范围

以下六个文件构成本轮实现；主题、原 `tracking-admin.js` 和业务数据不在本轮修改范围：

1. `wp-content/plugins/peptide-core/includes/class-plugin.php`：注册新增后台页面钩子。
2. `wp-content/plugins/peptide-core/includes/api/class-tracking-controller.php`：只读 GET 路由与 `data` 权限守卫。
3. `wp-content/plugins/peptide-core/includes/tracking/class-product-interest.php`：已有事件的只读汇总、明细、筛选和分页。
4. `wp-content/plugins/peptide-core/includes/admin/class-tracking-product-page.php`：页面入口、权限与中文口径。
5. `wp-content/plugins/peptide-core/assets/tracking-products.js`：独立后台查询与状态交互。
6. `wp-content/plugins/peptide-core/assets/tracking-products.css`：本页黑白紧凑控件与自适应完整记录。

## 已验证与证据限制

证据根目录：`D:/Codex/Tasks/2026-11-02/visitor-product-stats/outputs`。

`real-wp-sql.json` 记录独立 WordPress / SQL 原生检查 **64/64**，完成时间 `2026-11-02T08:21:50+00:00`。覆盖事件 UID 去重、主商品/规格汇总、事件时间与店铺日界、全局 UV、重复/回访、三种设备、五种稳定排序及分页、名称/编号/转义搜索、商品访客明细、无邮箱/IP/原始载荷字段、非法参数、夏令时 25 小时日、匿名/订阅者/员工 data 授权/管理员权限、无写方法、无缓存，以及报表只读。

这组是**连接内临时表**验证，没有向持久商品、顾客、订单或追踪表插入测试记录。因数据库临时表不能重复打开同名表的限制，harness 仅将第二个 posts 别名连接指向内容相同的临时 posts 克隆，其余生产查询文本保持；最终 90 次只读查询、68 次该别名适配均在收据中说明。早期 harness 失败留在 `real-wp-sql-harness-same-name-like-limitation.json`，不算生产缺陷或通过项。临时表移除后，收据列出的全部原表数量前后一致，写尝试为 0。

Root 已完成本轮独立源码审查。PHP lint、Node 语法检查通过；UI 机械检查与设计范围见 `ui-mechanical-check.json`、`ui-handoff.md`。技能检测一次所得字体 advisory 已收敛到紧凑既有尺寸，`#737373` 保留既有 DESIGN 中明确的中性焦点色；没有安装新依赖或改设计系统。

Root 在原站通过 AX 实际观察到 **7 个历史已删除商品、23 次商品浏览、13 位去重访客**。这些旧商品先前已由用户删除，页面保留原编号，不回填、不重配。另一个纯访客 QA 站已有 **10 位访客、100 个首页追踪事件**，没有 `product_view`，因此此页为 0 是正确口径；不能为了让排行有数据而改写这些事件或新建商品。

真实中文 QA 后台已用内置浏览器验证空态、手机设备及名称筛选提交和恢复默认条件，查询正常完成，原有数据未动；截图为 `qa-statistics-empty.png`。原站可读到上面的非空历史行，但后续原站截图/控件操作连续超时，因此不宣称原站非空交互已完整验收。

为验证非空交互而不造店铺数据，在独立静态 localhost 页面加载逐字匹配的真实 JS/CSS 与中文配置，顶部明确标为“离线界面验收：合成示例，不是店铺数据”，只用内存响应，无 WordPress/数据库连接，外部连接由 CSP 禁止。浏览器实际通过排行 20+2 分页、商品访客明细、返回保留原排行页、访客 20+4 分页、浏览器返回、回访排序、编号查询和错误重试恢复。`browser-ui-checks.json` 保留实际 DOM 结果；`static-ui-source.json` 是源文件匹配清单，`static-ui-stub-health.json` 的 16/16 仅属于合成响应测试，不能代替原生 SQL 的 64/64。

在 962px 内容区实际 DOM 检查为紧凑表格且无横向超出；390px 视口下为单列记录、字段未超窗。卡片断点仅从 1040px 收窄到 900px，保持约 950px 桌面工作区的表格密度。`offline-ranking-desktop.png` 是明确标识的离线合成界面；手机完整截图失败，`offline-ranking-mobile.png` 为未可靠刷新的截图，不作为视觉签收，手机仅通过 DOM 几何检查。临时视口覆盖已恢复，离线验收标签关闭。未做正式服务器部署、外网真实性能压测或真实客户行为复现。

最终源码、Git 同步和证据清单由 Root 汇总于任务 `outputs/DELIVERY.json`；不把数据库、私有凭据或合成访问数据上传 Git。
