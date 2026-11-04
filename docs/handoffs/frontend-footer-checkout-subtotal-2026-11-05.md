# 页脚配置与结算小计优惠展示

2026-11-05 · Codex · M01.05 / M06.04，延续057bfd0的快捷加购/推荐/关闭入口阶段。

## 用户要求与完成范围

- 购物车关闭入口的44px圆面、20px居中SVG叉号及卡片成功后不留“box added”句已在057bfd0；短暂按钮确认/烟花与失败提示保留。
- 结算Order summary的Subtotal旁边补划线商品原价，保留Woo原生现价，下方紧凑红色Save金额。全部只读官方cartStore，不改付款、地址、订单、优惠规则或应付额。
- 页脚完善商品/帮助/资料/联系分组；手机两列、44px圆形官方平台图标。用户明确“你先做，我自己配置”，已在原生footer687积木预留十个空账号栏，实际URL不硬写主题。仅已填有效账号或已启用客服显示；当前真实WhatsApp复用店铺Support_Contacts，关闭/改号随后台生效。

## 代码与原生积木

- 新assets/checkout-subtotal-savings.js/css：仅正常checkout经inc/live-cart-experience.php priority95注册，依赖原生Blocks/store/pricing。保留原Subtotal值节点，主题独立React root只管理自己的span；双响应区域、隐藏/重建、BFCache和原生class重写均处理。观察器合并RAF，稳定状态不反复写class。
- 小计只用服务端display_regular_subtotal/display_sale_subtotal，严格对savings_available、tax_display/savings_basis、币种/精度和native total_items(+incl时items_tax)。红Save只取两者差，绝不使用包含券/活动的total_savings。缺证据/过期/无优惠/模拟原价时回退原生小计。
- 新inc/footer-details.php与assets/footer-details.css，经inc/demo-site-parts.php增量接线。原演示footer以footerExpanded选择新排版，旧footer未自动改版，页头不改。
- 可编辑字段：footerExpanded、footerShopTitle、footerHelpTitle、footerResourcesTitle、footerContactTitle、footerServiceLinks、footerResourceLinks、footerUseSupport；沿用原items、菜单/demoLinks、Logo桌面/手机宽度、简介、版权、demoNotice、订阅与mobileNav。
- 图标：WhatsApp、Instagram、Facebook、Telegram、X、YouTube、TikTok、LinkedIn、Email、Phone。精确平台主机或mailto/tel决定图形，不把假相似域名画成官方品牌；无效/空/关闭项不输出，手工账号优先并与自动客服去重。超过三个账号时手机横向排圆图标，避免纵向十行。
- 隐私/条款只在真实配置的已发布页面存在时显示，删除原Woo terms缺失时回首页的错误入口；不编写/发布未经提供的政策，也保留DB-IP归因。

## 本机装修发布与验证

正常Layout_Controller save/publish只更改footer687，guard确认没有未发布草稿/编辑锁，并以revision/modified/草稿及发布hash保护。增加Shop/Help/Resources和已有真实页面链接，copyright为© {year} {store_name}；十个原生social items空URL供用户填写，未改店铺联系号码。用既有全站用途原句作footer notice。全部其它装修文档与店铺设置hash保持。

plan、旧模块备份及实际receipt：D:/Codex/Tasks/2026-11-05/footer-contacts/work和outputs/footer-receipt.json。数据库布局不在Git里，线上或新站需要正常原生模块迁移。

- 16组生产compute金额/税基/币种/安全分值检查通过；17项入口检查通过（普通checkout有注解，order-pay/received与普通首页无注解脚本）。
- 39项生产footer字段/渲染/URL/政策/去重/旧能力检查通过。接线后测试因旧测试再require同文件发生重复声明，已改require_once，重跑39项全过；不是站点运行故障。PHP/JS语法、diff检查通过。
- 真实公共首页HTTP读回四标题、十条真实站内入口、仅WhatsApp联系图标、动态2026版权和不存在错误Terms；82个快捷按钮及新圆形关闭SVG仍在。新静态资产HTTP200且字节与源一致。脱敏结果outputs/public-readback.json，不保存号码/nonce/客户数据。
- 用户先前Esc停止Computer Use后全程未再操控窗口，因此没有最新浏览器截图/实际滑动、React画面或真实加购验收；金额测试为隔离数据。未实际改车、用券、填地址、下单、付款或发送WhatsApp。

## 协作与交付边界

只提交本窗口独立资产/接线/测试/交接。cart-pricing.js、inc/cart-pricing.php、page-commerce.php、demo-catalog.js、product-dialog-experience.js、tools/mobile-preview.html与checkout-polish/product-dialog-polish/storefront-entry均是peer在途，未编辑/覆盖/纳入本次提交。functions.php只留下peer require未stage。

此前057bfd0已正常在main整合为b46e29e并atomic push、ls-remote确认。FE向main fast-forward因原peer product-dialog-experience.js在途被Git保护拒绝，未stash/reset，保留角色source与main综合历史不同。当前阶段最终引用另记于记忆/群。仅本机UI与Git交付，不等于公网部署。
