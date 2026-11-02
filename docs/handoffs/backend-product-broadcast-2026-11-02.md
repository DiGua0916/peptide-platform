# 商品播报后端与前端接入

2026-11-02 · Codex · PRODUCT-BROADCAST-01。后端实现、隔离原生验证和后台入口已交付；前端动画和商品弹窗位置仍由前端窗口接入，不表示这部分已发布。

## 这次负责的内容

商品播报是店铺运营编辑的有序商品说明，不是访客追踪、成交通知或真实客户行为数据。后台管理入口为应用中心的“商品播报”，地址通过 `admin_url('admin.php?page=pc-apps&app=product-broadcast')` 生成。使用现有 Peptide Core 应用机制，不另建插件市场、订单系统或商品价格来源。

站点数据库保留草稿和已应用快照；只有已应用配置进入公开接口与 SSR。未配置时公开为关闭、空文案，不自动将示例发布给客户。姓名只是编辑内容，不代表真实购买者。默认一份 `configId=default` 供当前店铺共用，不能通过请求提供其它店铺 ID 来选取或覆盖配置。

配置协议版本为 `version:1`。字段为 `enabled`、`badgeEnabled`、`name`、有序 `items`、有序 `keywords`、`enterMs`、`holdMs`、`exitMs`、`gapMs`；上限与导入规则按用户需求由服务器校验。统一姓名默认空；商品变量从当前 Woo/弹窗上下文获得，不在播报管理里维护商品名称、规格、SKU、价格副本。

## 接口与发布

前端必须使用 `peptide_core_product_broadcast_url()` 或 `rest_url('peptide/v1/product-broadcast')` 取得当前站点 URL，不硬编码本机域名、端口或另一个店铺地址。

| 方法及路径（相对 peptide/v1） | 用途 |
| --- | --- |
| `GET /product-broadcast` | 公开只读有效配置，顶层 `configId/revision/updatedAt` 加配置字段，无草稿或管理账号资料 |
| `GET /product-broadcast/admin` | 授权管理读取：编辑 revision、草稿、已应用快照、默认值、公开 URL |
| `PUT /product-broadcast/admin` | `{config,expectedRevision}` 仅保存草稿 |
| `POST /product-broadcast/publish` | `{config,expectedRevision}` 原子保存草稿并应用 |
| `POST /product-broadcast/preview` | 校验当前未保存配置并返回预览数据，不写库、不生成公开草稿地址 |
| `POST /product-broadcast/import` | 校验文件与当前草稿，返回合并结果和统计；不保存或应用 |
| `GET /product-broadcast/examples` | 授权下载示例，`format=txt/csv/csv-names/json` |
| `GET /product-broadcast/export` | 授权导出，`source=draft/published` |

管理请求使用当前登录及 REST nonce，并要求既有 `manage_woocommerce`；若为平台员工，还要拥有 `apps` 模块授权。页面、接口及持久化均应执行权限检查，不能只隐藏卡片。旧窗口 revision 不匹配以 409 拒绝，失败不覆盖当前草稿或已应用内容。管理读取和公开有效配置响应禁止缓存；不要擅自加 `revision`、`site_id` 等未声明查询参数。前端使用生成的公开 URL 和 `fetch` 的 `cache:'no-store'`。

保存草稿不改变公开 revision；应用配置变化后公开 revision 更新；相同已应用配置再次提交不变更公开 revision，因此前端不要重建同 revision 的队列。`updatedAt` 是相应快照的更新时间，初始未配置为 null。读取配置失败可保留上次成功配置或不显示，必须不阻断商品选购；关闭、空文案或新关闭 revision 时则销毁现有播报并移除占位。

## 装修积木契约

- `type: product_broadcast`，区块名 `vialcove/product-broadcast`，营销类，图标 `visibility`。
- 沿用原模块的 `moduleId/version/enabled`、排序与删除，以及通用电脑/手机显示和间距。
- 仅新增 `settings.configId='default'` 的单选引用。不在模块 `settings/items` 再存文案、关键词、姓名或播放时间。
- 配置引用位于共用设置，不能分别给手机存另一份文案；`singleRender=true` 避免原设备覆盖机制输出两个播放器。
- 编辑器显示配置来源说明和“打开商品播报设置”链接；装修草稿预览仍只读**已应用**快照，不能把插件草稿转为公开输入。

例如（只是结构示例，不是当前店铺已发布数据）：

```json
{
  "block":"vialcove/product-broadcast",
  "attrs":{
    "moduleId":"broadcast-main",
    "version":1,
    "enabled":true,
    "settings":{"configId":"default"},
    "items":[]
  }
}
```

`PeptideCore\Modules\Product_Broadcast::register_hooks()` 扩展现有定义、模块内容和新的外层包装过滤器。`Renderer` 在空公开内容时依旧直接返回空，关闭/空文案不会输出 section；非空时外层 `<section>` 先带 `hidden data-pc-product-broadcast-shell`，完整阻断间距和背景占位。此过滤器仅作用当前积木，其他模块与后台编辑器包装保持原样。

## PHP 与 inert SSR 挂载合同

```php
$config = peptide_core_product_broadcast_config(); // 只读已应用的公开字段。
$url = peptide_core_product_broadcast_url();
$mount = peptide_core_product_broadcast_markup(); // 关闭/空文案返回 ''。
```

非空 SSR 为：

```html
<div class="pc-product-broadcast"
     data-pc-product-broadcast
     data-config-id="default"
     data-revision="…"
     data-config-url="由当前站点生成的有效配置URL"
     hidden>
  <script type="application/json" class="pc-product-broadcast__config">安全编码的已应用配置</script>
</div>
```

JSON 使用 HEX 编码保护 `<`、`&`、引号等，不能拼接成 HTML；其中的模板、未知占位符都按文字处理。SSR 是安全快照，不是动画控制器，也不保证绕过外部整页缓存。前端挂载时应读取当前有效接口，确认配置可用后 `configure(config)`；成功建立播放器之后才移除内层和最近 `[data-pc-product-broadcast-shell]` 的 `hidden`。关闭、空文案、卸载或挂载失败时保持/恢复隐藏；不要只解除其中一层。未接前端代码时页面不会显示空卡片或留白。

直接 PHP helper 输出的挂载没有装修 section；前端应兼容不存在外 shell 的情况。可由前端将它放进商品详情弹窗标题/星级之后、主图之前；本后端没有修改主题排版，也没有在整站创建浮层。

## 前端窗口仍需实现及验收

沿用 Demo 模型和动画，显式启用后台配置模式，彻底关闭 Demo 的 localStorage 覆盖。通过 `configure(config)` 设置有序文案，通过 `updateContext({product,specification,price,sku})` 同步当前商品和选中的实际规格；不要切规格就重置队列。按同次挂载的第 1 到最后一条播完才循环，不能随机或提前重复。100 条第 101 次回到第一条属于前端播放验收，不是后端数组保存验证。

前端负责单行眼睛/文案/红色 `🔥 HOT`、开关、长文省略、左入右出、停留、点击暂停、离屏/页面隐藏/上层图片或 COA 弹窗暂停、恢复位置、减少动态效果及 timer 清理。长文停留使用每字符 45ms、总停留最长 15s，短文至少使用 `holdMs`。390/430px 与更窄手机的实际排版、变量切换、连续打开/销毁无重复计时器仍需前端真实浏览器联调。

不要把接口或 inert markup 已有说成动画已接好；也不要把示例姓名或文案包装成真实客户成交数据。

## 本子任务实际验证

仅新增模块类与本交接，未改主题、业务记录、Registry/Fields 或后台通用资源；Root 负责 bootstrap、Renderer 通用 filter 与 PHP helpers 的整合。该子任务没有执行 Git 操作或写原站/QA 数据库。

`D:/Codex/Tasks/2026-11-02/product-broadcast-backend/work/layout-fixture.php` 加载真实生产 `Fields/Registry/Layout/Renderer` 与 WordPress 原生 HTML Tag Processor/UTF8 函数。Store 为内存已应用快照，必要的 WordPress hooks/翻译/URL/转义等为明确 doubles，不是完整 WordPress 启动或权限测试。

结果 `outputs/layout-fixture.json`：**27/27**。覆盖注册幂等、type/block、单份配置引用、添加/删除/顺序/启停、错误配置 ID 回到本站 default、丢弃积木文案/时间、关闭/空文案的完整渲染为空、模块关闭和双设备关闭、SSR JSON 防脚本闭合、只读已应用数据、revision/URL、原生 HTML Tag Processor 隐藏整个 shell、其他模块/编辑器不受 wrapper 影响，以及布局 preview 不读插件草稿。早期 fixture 对 JSON slash 编码的错误预期保存在 `layout-fixture-early-assertion-encoding.json`；修正测试预期后通过，未改产品源来迎合错误断言。

## Root 整合与实际验证

实现为 Peptide Core 内置“商品播报”应用，沿用应用中心启用配置方式；不另安装一个插件市场。Peptide Core 包版本保持 0.32.0，配置及积木协议版本 1。修改 4 个既有文件：`includes/class-plugin.php`、`includes/functions.php`、`includes/admin/class-apps-page.php`、`includes/modules/class-renderer.php`；新增 `includes/broadcast/class-config.php`、`class-importer.php`、`class-store.php`，`includes/api/class-product-broadcast-controller.php`，`includes/admin/class-product-broadcast-page.php`，`includes/modules/class-product-broadcast.php` 和 `assets/product-broadcast-admin.js/css`。

存储为本站 options 表的 `pc_product_broadcast_v1`，JSON 双快照、不自动加载；读取未配置应用不落默认值。每次保存草稿/应用增加编辑 revision，使用新鲜主库原文及 `BINARY option_value` 的原子比较更新，首次以唯一 option_name 插入占用，避免丢失更新。只有应用内容变化时增加公开 revision。配置使用 PHP intl NFC、mbstring Unicode 小写比较，两扩展在当前 LocalWP 与 QA 已核实可用；缺少时对非 ASCII 明确报错，不静默错归并。

存储上限 8MiB 足以容纳 items 与 keywords 各 1,000×500 个四字节 Unicode 字符的双快照。HTTP JSON envelope 上限 24MiB，允许合法配置经 Unicode 全转义的传输形式；导入文件本身始终最多 1MiB。除明确支持的参数外拒绝其它店铺、配置选择或未知字段。POST/PUT 只接受 JSON 对象；管理 GET 同样需要登录权限及显式 `X-WP-Nonce`。下载示例/导出返回 `{filename,mime,content}`，后台用 Blob 下载，不上传导入源文件到媒体库。

实际验证分层如下，证据都在 `D:/Codex/Tasks/2026-11-02/product-broadcast-backend/outputs`：

- `model-import-tests.json`：**89/89** 纯 PHP 校验/解析断言，另有 2 项依赖缺失检查；不是数据库或播放测试。UTF-8/BOM、NFC/Unicode 去重、CSV 引号/逗号/换行/错误物理行、逐行姓名替换后去重、JSON 普通导入保留时间与显式完整恢复、1MiB 精确边界、100/1000 有序文案及所有容量/类型错误覆盖。首次 HTML 检测误拒数学文本，已修复后整套重跑。
- `native-wp-rest.json`：**56/56** 真实 WordPress、REST、MySQL 测试。仅连接内两张 TEMP options 表与内存身份，不创建持久商品/顾客/订单/追踪记录。覆盖草稿保存与公开隔离、应用与关闭、相同公开配置 revision 保持、冲突无覆写、原生 CAS 控制交错、第二站点表隔离、跨站请求字段拒绝、nonce/匿名/订阅者/员工 apps 权限、预览和导入不落库、4 类无效导入、4 种示例回读、导出、hidden SSR、损坏私有记录 fail closed/503，以及合法最大 Unicode 双快照原生保存和重新读取。最终原业务/访客计数及原应用 option 前后一致，持久写尝试 0。
- 首次原生 harness 的 19 项未通过保留于 `native-initial-harness-rejections.json`：主要是纯访客 QA 自身拦所有 POST/PUT，另有一个只比较 JSON 键排列的错误断言。修正 harness 后，仅该 CLI 连接、TEMP options 和本插件四条写式路由允许继续进入真实权限回调，原 QA 文件/HTTP 守卫未修改；最终 56 项全套通过。两张临时表验证当前站点存储分离，不等于完整 multisite 网络浏览器测试；CAS 为受控查询交错，不是并发负载测试。
- `layout-fixture.json`：上述 **27/27** 模块隔离测试；`admin-state-check.json`：**15/15** JS DOM doubles 状态测试，含 CAS、失败保留、导入草稿、默认回填、无 localStorage、离页保护；均不冒称真实客户页动画验收。
- `scoped-source-review.json`：独立审查发现的合法 Unicode 双快照超过早期 8,000,000 字节阈值已修为 8MiB，容量证据在 `review-unicode-capacity.json`，当前审查范围无剩余 P1/P2。10 个 PHP 文件 lint、后台 JS 语法与 diff 检查通过。

实际 IAB 中文 QA 页面已读取完整字段、初始未应用版本、验证空姓名使用 `{name}` 的前端拒绝且保留输入、恢复默认仅回填本页、应用中心“商品播报 / 未启用”卡片。截图 `admin-editor-desktop.png`。QA 访客隔离守卫保持不变，因此没有在它的浏览器页面提交应用配置；保存/应用的原生结果以上述 TEMP 表 REST 验证为准。原站公开 HTTP 已实测 200、`configId=default/revision=0/version=1/enabled=false/items=[]/updatedAt=null`，`original-public-config.json` 留证；未向原站发布示例配置。原站后台 IAB 新页控件读取超时，未冒称其保存、跨浏览器或手机视觉已经实测。临时视口尝试未取得有效手机尺寸，不计通过，已 reset。

未改主题、Woo 商品/售价/库存/购物车/订单/报告、真实客户或追踪数据。没有外部服务、收费资源、邮件发送、云部署或客户页 ticker 动画改动。前端按本合同接挂载及生命周期后，再共同验收 100 条完整顺播和规格实际变量等客户侧行为。最终提交、同步及文件 SHA 在任务 `outputs/DELIVERY.json`。
