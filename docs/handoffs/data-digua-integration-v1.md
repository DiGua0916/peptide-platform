# DiGua 数据源对接说明 v1

基线：d19bc8fb2ccc96d11f9b059ff1dd3deb9323b1da，073 Data 补缝。本文只交付数据接口和业务成功点对接要求；不实现或启用收藏、分享、A/B 分流/选版/发布。所有示例均为 synthetic，不是客户数据或已完成的真实验收。

## 接口与责任

| 责任方 | 文件 / 接口 | 职责 |
|---|---|---|
| Data | wp-content/themes/peptide-site/assets/visitor-adapter.js 的 window.VialCoveVisitorTransport.subscribePageViews(listener) | 仅订阅当前真实 PV 的已接受 ACK；返回 opaque reference，不含数据库 ID |
| Data | wp-content/plugins/peptide-core/includes/tracking/class-experiment-bindings.php；POST /wp-json/peptide/v1/data/experiments/binding | 原 PV 精确只读查询；服务端管理权限认证，返回原 visitor/visit 绑定 |
| Data | POST /wp-json/peptide/v1/data/experiments/events | 复用既有058接收真实实验展示；不选择版本、不制造对照组 |
| Data | window.VialCoveVisitorTransport.pushProductAction(type,facts) | 已有056商品动作队列；匿名既有访客链路与隐私门 |
| DiGua | 自有收藏持久化服务与成功回调、商品分享/复制处理器 | 提供真实业务功能，仅成功新增/完成后接 Data；具体业务文件由 DiGua 回填 |
| DiGua | 自有 A/B 选版、真实渲染及展示观察脚本 | 报告真正显示的实验/版本；选择、分流、发布均不属于 Data |
| DiGua | 自有可信服务器连接器与凭据存储 | 经 HTTPS、原生 WordPress application-password auth 调 lookup 和058；凭据仅服务端保存 |

当前没有可在匿名前台取得 DB visitor/visit ID 的公开 API，也不能调用 Tracker::visitor()/visit()“补出”身份；它们会创建记录。不要伪造 ID、伪造管理员用户、放宽058权限或把管理 application password/nonce 塞入匿名 JS。新的 lookup 是服务端认证入口；原生 WP 业务插件也须通过有真实权限的服务端调用链使用它，不假设匿名请求拥有内部写入权限。

## 一、取得原 PV reference，再报告实际 A/B 展示

公开 track ACK 只提供协议、ACK/rejected、服务端时间及扩展结果，不返回 DBID。此前 data-landing-experiments-v1.md 的“ACK supplies IDs”说法已纠正。

订阅接口只在正确协议、开启采集、非 GPC/未停止条件下工作。监听器最多20个；unsubscribe 清理自己的注册；监听器抛错不影响采集。已接受当前 PV 时，晚订阅会同步收到该 reference。每个监听器对同一 UID 只通知一次；BFCache 新 PV 清旧缓存，旧页迟到 ACK 不变成当前身份。pagehide 暂停通知并清当前缓存；后台隐藏时不通知，仍为当前的已 ACK reference 可在回到前台后交付。stop/拒绝/GPC/关闭会清引用与注册。

~~~js
// Synthetic integration sketch. These business functions belong to DiGua;
// they are not new Data APIs and do not select or invent variants.
const unsubscribe = window.VialCoveVisitorTransport?.subscribePageViews(reference => {
  const display = diGuaCurrentActuallyVisibleDisplay();
  if (!display) return; // no actual variant: report nothing
  const observedAt = Date.now(); // a real current observation, not an invented past time
  diGuaSendToOwnTrustedServer({
    page_view: {
      event_id: reference.event_id,
      page_instance_id: reference.page_instance_id,
      path: reference.path
    },
    occurred_ms: observedAt,
    event_id: display.stableObservationUid,
    experiment_id: display.actualExperimentId,
    variant_id: display.actualVariantId
  });
});
// DiGua calls unsubscribe when this source integration is removed.
~~~

DiGua 必须验证实际版本仍在同一页面真正显示。stableObservationUid 由真实展示操作产生一次（8–64 ASCII opaque，建议随机 UUID 加业务前缀），成功/失败重试复用同一原始记录。重复 callback 不得制造新的 UID；真正的新展示才用新 UID。如果原展示在 PV ACK 前已经关闭，不能补造曝光。若回调延迟，当前重新观察到的可见展示可按“这次观察”的真实时间报告，不能谎称为先前首次显示时间。

DiGua 可信服务端还须核实这次展示属于其真实分配/渲染记录；匿名提交的 experiment/variant 本身不证明实际显示。Data 的 PV 绑定只证明原采集记录，不证明业务选版。

可信服务器先调用：

~~~json
{"protocol":1,"page_view":{"event_id":"synthetic-pv-0001","page_instance_id":"synthetic-page-0001","path":"/campaign/"}}
~~~

仅允许 protocol 和 page_view；page_view 仅允许上述三个字段。不要直接转发 reference 的其他字段。path 必须本地 canonical 路径，无域名、query、hash、HTML。两个 PV UID 都必须为8–64 ASCII字母/数字/短横线，复用原 tracking UID 格式；上例仅演示 shape，不能作为真实源数据发送。

服务端查询只匹配既已持久化的 non-test client page_view，且原 visitor/visit 均存在、非 test、精确 owner 一致。匹配0条404；跨 owner 等精确匹配多条409，不能选“最新”一条；SQL/存储失败503。唯一匹配返回字段：

~~~js
// Response shape; values below are resolved by Data, never invented by DiGua.
{
  protocol: 1,
  binding: {
    visitor_id, visit_id, page_view_event_id,
    page_instance_id, path, page_view_occurred_ms
  }
}
~~~

这些 DBID 只留可信服务器/有权限管理端，不回传匿名页面。lookup 不创建或修改访客、访问、PV、活动、漏斗、订单或实验。最大8192B，最多300次有效形状请求/认证用户/300秒；400 shape、401未登录、403无权限、413超长、429限流、503不可用。验证权限早于存储访问。不要把读取失败转成伪绑定。

服务端复用现有058 POST /wp-json/peptide/v1/data/experiments/events，映射：

~~~js
const exposure = {
  event_id: source.stableObservationUid,
  visitor_id: binding.visitor_id,
  visit_id: binding.visit_id,
  page_instance_id: binding.page_instance_id,
  occurred_ms: source.actualObservationEpochMs,
  data: {
    version: 1,
    experiment_id: source.actualExperimentId,
    variant_id: source.actualVariantId,
    landing_path: binding.path
  }
};
const body = {protocol: 1, events: [exposure]};
~~~

必须满足 saved PV time ≤ real display-observation time ≤ receipt time，且在24小时内、原 owner/visit/page instance/path一致。058会再次校验原 PV；不信任来源声称的金额、订单或支付成功。实验/版本为1–40 opaque slug；不要放邮箱、标题或联系人。不存在真实发布版本时保持未观察，不虚构 A/B 或0表现对照组。

058成功响应是 {protocol:1,ack:[event_id],rejected:[{index,code}],retry:[event_id]}。只有 ack 是接受；retry 项原封重发；invalid_owner、page_view_required、duplicate_conflict、original_visit_missing 等终止并对账，不能改 UID/owner/path/time“修到通过”。网络/503/429等不确定失败保留原 envelope，退避重试；认证失败先修服务端配置，不降级匿名。绑定404可在确认原采集仍待落库时有界等待原 reference，不创建记录；409需要对账，不能猜 owner。

## 二、收藏与分享：只接真实业务成功点

Data 不提供收藏持久化、移除、列表、分享按钮或复制 UI。DiGua 的业务文件须明确并提供以下成功回调：

| 来源 | 可以上报 | 不可以上报 |
|---|---|---|
| 收藏 | 持久化成功且确为 NEW addition | 点击、仅切换 UI、重复 already-added、移除、失败、最近浏览 |
| 原生分享 | navigator.share 的完成 Promise resolve | 仅打开弹窗、cancel/AbortError、拒绝或失败 |
| 复制分享 | 真实商品永久链接 clipboard.writeText resolve，或明确成功的复制 fallback | 复制优惠码/后台话术、execCommand false、失败或仅点击 |

一次成功业务操作生成一次稳定 producer UID（8–64字母/数字/短横线），不能含个人信息。parent product_id 用原生父商品正安全整数；普通商品 variation_id=0，变体用其真实正安全整数，由 receiver 验证归属。不要把变体当父商品，不猜商品/访问 ID。动作数据只传协议字段：

~~~js
const favoriteFacts = {
  version: 1, event_id: favoriteOperation.stableUid,
  product_id: favoriteOperation.nativeParentId,
  variation_id: favoriteOperation.nativeVariationId || 0,
  action: 'add'
};
const shareFacts = {
  version: 1, event_id: shareOperation.stableUid,
  product_id: shareOperation.nativeParentId,
  variation_id: shareOperation.nativeVariationId || 0,
  method: 'native', // copy_link only for actual product-permalink copy
  result: 'completed'
};
function optionalData(type, facts) {
  try { return window.VialCoveVisitorTransport?.pushProductAction(type, facts) || false; }
  catch { return false; }
}
// Call only in the actual successful business callback, not on click:
// optionalData('product_favorite', favoriteFacts);
// optionalData('product_share', shareFacts);
~~~

facade 返回 queued UID 或 false，queued≠服务器 ACK，更不代表收藏/分享业务成功。成功业务在前，Data 是后续可选观察；Data 不可用/抛错/满队列/关闭均不得回滚业务或改变其成功提示。false 后若同一文档内重试，保持原 UID/字段；成功 queued 后源端也应避免重复调用。collector 提供真实当前 page/path/page instance/seq/time，冻结完整重试记录；不要传 URL、姓名、收件人、属性、价格、数量、凭据或自己捏造时间/身份。

队列是内存/document/BFCache 范围，无跨刷新持久化保证。不能刷新后仅凭旧 UID 重建新页面 envelope；那会改变身份。Data 遵守 opt-out/GPC/员工及服务端隐私门；不绕过关闭采集。已有原生 quick_view_open 和 buy_now 已接入，不得再为同次操作手动推第二 UID。quick-view 的既有 product_view 配对也由 Data 完成，业务方不再补 page_view/product_view。

## 三、可用挂载缝与 DiGua 文件清单

以下只是现有扩展点，不表示收藏/分享已存在：

- 单品页 wp-content/themes/peptide-site/woocommerce/single-product.php 委派原生 Woo 内容；DiGua 自有业务插件可使用 woocommerce_single_product_summary。无需改布局/支付/物流。
- 商品卡 wp-content/themes/peptide-site/functions.php 的 vc_product_card() 调 peptide_core_product_card_extras($product)；wp-content/plugins/peptide-core/includes/modules/class-commerce-blocks.php fallback卡也调用同钩子。DiGua 可在自己的插件注册处理器；不要为 Data 修改所有模块或发布设计。
- DiGua 交回：实际收藏持久化文件+成功回调行、实际 share/copy处理文件+成功回调行、实际 A/B显示文件+稳定展示操作 UID 来源、可信服务器连接器文件及凭据位置类型（只报位置/类型，不报值）、已发布可验收页面路径。
- 当前源码中的 coupon copy 只复制优惠码，不是商品分享；recent-products cookie 是浏览历史，不是收藏。

## 四、交付与本地验收清单

Data 提供接口实现/协议/纯内存 gate，DiGua 提供业务成功事实与服务端连接。真实客户验收由获准的 owner/Root 在现有8091执行，不由本次源代码执行者发请求、写 DB、开浏览器、发布按钮或制造事件：

本次本地验收由 Root 提供的条件：站点时区 America/Los_Angeles，跨当地午夜时选择2026-10-31..2026-11-01覆盖访问和订单。财务统计只用**已存在且未标 Meta::DEMO_SEED 的本地测试商品**，并仍遵守全部原生 test-order 排除规则。演示商品仅验访问/加购；演示订单被排除而 paid/order 为0属预期，不可填成真实销售。不要新增/发布商品或设计、修改 demo 标志或改排除规则。沿用已有本地 gateway，不安装 Panapay/新服务；未来验收按站点当时的真实配置确定日期范围。

1. 使用现有真实发布入口；记录路径、功能 owner、实际业务成功标准。缺入口则标“尚未对接/验收”，不造按钮凑数据。
2. 真实 NEW 收藏持久化后收到1条 addition；重复点击 already-added/移除/失败不增加。验证刷新后业务状态按其合同保持。
3. 真正分享/复制商品链接成功后增加1条相应method；取消、拒绝和复制失败不增加，不收集接收者/URL。
4. 实际已显示版本收到当前 PV reference；可信服务器 lookup 唯一原PV后提交058。核对原 owner/path/page instance/time，绝不人工选择别的visit。
5. 对每条操作重放原UID/envelope验证幂等；错误shape/权限/不存在PV/歧义必须拒绝。source是否成功与Data是否接受分开记录。
6. 校验产品、收藏排行、A/B分析和完整CSV的对应增量及partial coverage；未观察的kind/period仍空，不补0、不制造control。
7. 检查 GPC/opt-out/停止时无回调/新观察，Data失败不影响业务；报告实际执行结果、失败及未做项。接口完成不等于DiGua已接、真实外部邮件已送达或whole Data完成。

对应英文原协议：data-product-actions-v1.md、data-landing-experiments-v1.md。073 focused tests执行实际handler/route与collector/adapter，属于source验证；本次没有真实客户、邮件、UI或业务发布验收。
