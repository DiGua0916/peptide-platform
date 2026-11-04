# 首页卡片快捷加购与推荐刷新稳定性

2026-11-05 · Codex · M02.04 / M05.04

## 用户本次明确要求

首页商品卡底部的Choose options改为在外面快捷加购，按卡片正在展示的后台首规格直接加一盒；图片/名称继续打开原商品弹窗换规格。随后用户反馈：275美元距300档差25美元时，有时看不到推荐，要求确认实际网站是否同样受影响。

## 快捷加购实现

- functions.php的vc_product_card仅增量可选第六参数，demo-catalog模板开启。可直接买一盒的同SKU才呈现快捷按钮；原生库存、属性完整度、QuantityLimits沿用现有card资格函数，不私定库存或偷偷换另一规格。
- catalog-quick-add.js在既有live-cart-experience/feedback脚本后载入，按钮显示Add 1 box +。JS未就绪或不允许直接买时保留Select & add原链接；按钮不是quick-open链接，不误开弹窗。
- 点击先读取精确product/variation的card，严格核对全部ID、当前卡片价格/币种/精度及canAddOne。变价/无货/规格变化时停止写入并转为审阅入口，不静默按新价格加购。
- 公开薄addOne包装复用原唯一client：先GET原车取得Nonce，再原生add-item。HTTP201且目标SKU数量恰加一才confirmed；不确定响应不自动重试，GET对账不冒充成功。
- 现有后台cartFeedback开关、时长、烟花/徽标反馈保持；确认后才Added和小烟花，继续停留列表，不自动开车或跳详情。销毁/取消/重复点击/迟到GET均受控。没有新加购端点、购物车存储或虚假像素事件。

## 推荐偶尔消失的已证实原因和修复

1. 原流程每次刷新原车就先清空推荐，包含同车的30秒轮询、窗口聚焦和重新打开抽屉。现在保留同一快照、同修订、未过期且通过原验证器的报价，背景读取不先撤掉。
2. 原推荐等待最近浏览的图片/原价和车内parent元数据后才请求报价。现在历史拿到后，纯展示装饰异步并行，不能挡住原生报价。
3. 活动配置晚于第一轮报价时，原流程不立即重报价。现在配置修订更新或原生历史事件可强制重取，重复同快照通知不取消正在进行的读取。

换购物车、待确认写入、过期、停用或后端明确hidden仍立即移除；旧响应不能复活。没有有效候选、距下一档过远、库存/购买限制、券冲突、最高档等正常后端条件仍尊重，不强显固定推荐。截图那次会话的具体后端hidden reason尚未实际读回，不将源代码诊断冒充该请求实测。

## 验证与限制

- 最新22项专项Node通过（12快捷加购/10推荐调度），联合98项Node通过；PHPcard51与首页template23通过，PHP/JS语法及diff通过。测试HTTP与业务数据均在进程内隔离，没有真实加购。
- 本机公共首页HTML实际回读82个快捷按钮与新脚本；前两卡分别Tirzepatide20mg/5500minor、Retatrutide20mg/8630minor，均绑定原精确variation，说明SSR与资源已接好，不等于完成浏览器点击验收。
- IAB tab接口仍超时；改用现有窗口只读时看到了真实275购物车及最新摘要。用户随后按Esc停止Computer Use，立即停止后续窗口操作。没有最新快捷按钮/推荐修复的浏览器截图或真实加购验收，不虚报完整实测。
- 原peer product-dialog-experience.js哈希4E5C0C22689472162395733A6838F58F07FE1B3C8A9A2DEF94DC67540DDCA452、tools/mobile-preview.html哈希036102F81A6A391069B5A9AA2319CE19F1918C801FF317546C6D6F2A29456ED3保持未动未stage。未改后端/设置/数据库或部署公网。

任务证据：D:/Codex/Tasks/2026-11-05/catalog-quick-add/outputs。Git阶段引用另记；后续可在用户允许窗口操作时核对实际快捷按钮和符合后端资格时的推荐稳定性。

## 用户连续追加与本阶段收尾

- M05.01：购物车关闭按钮改44×44固定触控圆面，20px矢量叉居中；局部限定最小/最大尺寸、圆角和padding，防止全站按钮规则把它拉成竖条。原close/Back/focus协议不改。
- M02.04：卡片加购成功清掉下方额外“box added”句，只保留原确认按钮短反馈/小烟花；请求失败/价格变化仍保留必要提醒。
- M06小计追加：用户要checkout Subtotal也显示划线原价/优惠价/红省钱，正在独立子模块实现，未列为本阶段已完成。
- M01.05追加：用户要完善页脚及圆形联系方式图标，安排在上述收尾之后；先只读核对原生footer模块与真实配置，不编造社交账号。

并行保护补充：现场另有peer在途cart-pricing.js、inc/cart-pricing.php、page-commerce.php、demo-catalog.js以及checkout-polish/product-dialog-polish/storefront-entry新文件。functions.php只stage本窗口card函数三个hunk，明确排除peer的require storefront-entry；不对其在途代码提交/覆盖/清理。
