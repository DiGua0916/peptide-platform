# M03 商品窗口与旧入口 · 2026-11-05 · Codex

本模块只新增主题适配层，未修改商品、规格、报告、购物车、订单或追踪实现。没有浏览器操作、数据库写入、部署或提交。

## 新文件与根接线

- `inc/storefront-entry.php`：由主题 functions.php require 一次，注册公开 read-only release endpoint、受限临时旧入口重定向和资源加载。
- `assets/product-dialog-polish.js`：依赖现有 home-quick-view、experience、offer-follow（如已加载则同时依赖 live-commerce-navigation）及 Campaign_Countdown。监听现有 `vc:product-presented` / `vc:product-dialog-selection`。
- `assets/product-dialog-polish.css`：局部样式，不覆盖全站样式。
- `tests/storefront-entry-pure.php`、`tests/product-dialog-polish.cjs`：隔离原生边界测试。

根代理负责共享文件接线：`functions.php require`；`assets/demo-catalog.js` 增加 `vc:catalog-restore` 事件消费，精确建议在 `D:/Codex/Tasks/2026-11-05/storefront-six-module-refresh/work/countdown/catalog-restore.patch`。此子代理没有改这些共享文件。新公开 GET `vialcove/v1/storefront-entry` 只返回 `{version:1, release:<64位内容SHA>}`，无客户/配置数据或翻译内容；若统一 locale 路由检查需要，可增加此精确公开路由。

## 商品窗口

移除 native toolbar 的 Overview/Details/COA/Bag 等导航控件，保留 **同一个原右上关闭 X** 和原焦点/关闭处理器。原商品正文、规格表单、报告画廊与购买控制不搬走、不重建。原 offer-follow 的同一 offers 节点仍留在原 toolbar，背景透明。

标题和 tiers 取 Campaign_Countdown 绑定的实际活动，且必须在当前 SKU 的原生 `vcHomePromotions` 中找到同一 ID、status=active；不会用 `.find(first active)`。无当前 SKU 资格时同时隐藏优惠区和商品位置的计时，不暗示另一个活动可用。原生 for_product 只返回进行中的 owner，因此商品弹窗未获得原生资格前不会自行推断未来活动适用。无截止时间但实际活动仍 active，可以显示真实标题和优惠档位；倒计时本身继续按 untimed 隐藏，不造截止。

窄 MutationObserver 只观察这一个 offers 的 childList/subtree；自身替换节点前断开，恢复观察前比对实际文本，因此旧 native writer 重新写入时会校正，自己的写入不递归。相同 DTO 与资格的 variation 事件仅同步展示，不重复校准 clock。页面初始 DTO 的单调时钟锚固定在 document responseEnd（不可用时为脚本首次接收），晚开/重开按已经过的页面时间老化；即使新 GET 失败，已结束活动也不会复活。SKU 资格改变时传给 clock 的仍是已老化的 serverNow，原 ends 不变。后台新 DTO 读取后才用服务端 epoch 秒及半 RTT 更新时间锚；秒级检查会在真截止时隐藏优惠区，60 秒读取同一个只读配置端点。关闭释放 observer、请求、interval 与 clock。

倒计时 host 在原 broadcast 节点后，留在 heading 内、主图前，复用独立 Campaign_Countdown UI 与 DTO，没有第二套假时钟。根代理已确认本次黑五源为实际 promotion 10980、结束 epoch 1796038061；该信息由根代理只读核对，本子模块不设置或覆盖这些值。

## 旧入口

普通公开 GET / HEAD：

- native Shop 归档 → 首页 `#vc-products`。
- native product category → 首页 `vc_catalog_category=<真实termId>`，恢复当前分类及其子分类 ID（有界最多 300 个），仍使用原首页目录 render/filter。
- native product-only search → 首页 `vc_catalog_q`，不把 `s` 带到首页使其再次变成 WP search。
- 支持新版弹窗、公开且可见的原单品 → 首页 `vc_product=<真实productId>`；保留 EN/ES 手动/自动标记、原生 attribute_* 值和常见 UTM/点击归因参数。

重定向是 **临时 302 + private/no-store**，没有永久 301、删产品或改 permalink。公开性/支持类型/实际设备的弹窗开关仍由现有模块决定。

明确不拦截：API/rest_route、后台/登录、editor/design/customizer/preview、feed/embed、cart/checkout/order-pay/order-received、支付返回或带 nonce/token/signature/order_key 等签名 query、wc-api/wc-ajax/add-to-cart/quantity 等动作 query、POST/PUT 等写请求。禁弹窗、private/无效/不支持商品继续由原生页面处理，不强行打开弹窗。普通内容页和全站文字搜索不重定向；产品标签页也未改。

分类/搜索使用已配置首页目录的数据范围、排序和原分页大小，不新造全目录/排序引擎。首页模块本身若只发布某个子集，该范围仍会限制搜索结果；原生 Shop 分页/独立 collection 排序不会被伪称为与首页同一结果集。整合验收应确认当前首页公开完整商品源和分类覆盖（原目录自身上限 200）。

## 深链接与恢复

`VialCoveStorefrontEntry.openProduct(publicId)` 读取既有 native `vialcove/v1/home-product/<id>` 模板，严格核对返回 ID、单一 template 与 product root，再触发原有 `[data-vc-home-product][data-vc-quick-open]` 按钮。没有新 variation matcher、cart endpoint 或写操作。原模板和原生 presenter 继续拥有交易。

失败时显示可重试状态；没有 href 的恢复按钮不会回退到旧 PDP。成功后保留可见 opener 供原关闭焦点返回；重开直接调用原 presenter，不重复递归调用本 helper。原 URL 的规格值只有在真实 native select options 中存在才应用。异步读取被返回/pagehide 取消时，恢复按钮可重新使用。

已有 `__vcCommerceLayers` history 时，交给原 history controller 恢复叠层、选择和 scroll，本模块只提供有界等待及可见重试状态，不再推入第二份历史层。boot 同时核对当时已经打开的 native 商品窗口并补挂一次，覆盖恢复事件发生在此脚本载入前的情况；已挂载实例幂等同步，不重新打开。没有禁用全站 bfcache。

仅 `pageshow.persisted` 执行 no-store release GET。release 内容包含本适配层和依赖 presenter/目录/历史文件 SHA；实际与 HTML 注入的 release 不一致，且不是 checkout/cart、也没有受信任表单输入时，才安全 reload。重复同 release 的自动 reload 有 session 内去重；有未保存输入时仅给明确刷新按钮。网络失败、相同版本不刷新。

限制：**发布前已缓存、没有加载本模块的旧 DOM，不能被新代码追溯注入**。需要一次正常新导航后才具备 release 核对。原截图本身是 Shop 归档，不能仅据它断言缓存故障。当前实现解决明确旧路由和可核对的后续 history 返回。

## 验证

66 项 PHP router/公开边界测试与 41 项实际 JS 模块 + 隔离 DOM/fetch 测试通过：X/焦点/同 offers 节点/表单保留、窄 observer、SKU 资格同时约束计时、真实 deadline、variation 不重置时钟、晚开重开 + GET 失败不复活、deep-link 成功/失败可重试、无 helper 递归、版本核对条件/dirty/checkout 排除、载入前已恢复窗口的初始化。PHP lint、JS syntax 通过。真实数据库写与真实业务请求为 0。

Impeccable detector 已运行，本模块仅公开面色值 `#ddd` 相对 admin DESIGN.md 的 advisory，无阻断项。沿用现有主题字体/品牌；不改 DESIGN.md。

未执行 CUA/Chrome/Windows 或服务器操作，实际手机排版、首页完整目录覆盖、真实活动、公开路由与点击/返回验收由根代理统一完成。本文件不将 fixture 通过写成实网验收完成。
