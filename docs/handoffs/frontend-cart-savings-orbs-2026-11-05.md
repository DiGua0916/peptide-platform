# 首页浮球与购物车优惠排版 · 2026-11-05 · Codex

## 用户确认的范围

先做首页右下角购物车球，位于客服球上方；再处理购物车商品优惠与紧凑推荐。后续明确耳机图以购物车图的视觉大小为准、活动细横条正常/置顶均为胶囊，两端圆润。保留原首页、原生活动和购物车业务。

## 已完成的源码

- `inc/demo-site-parts.php`：首页把原 header cart-link 呈现为浮球，不复制入口；仍受 header 原生 showCart 开关控制，保留 cart URL 与现有 drawer 事件。其它页面不受影响。
- `inc/storefront-utilities.php` / `assets/floating-cart.css` / `.js`：只首页载入。56px 外圆、右距16px、客服球上方12px；避让原生底部导航、菜单或 dialog 打开时隐藏。原 `.vc-cart-count` 由 Woo/现有客户端更新，MutationObserver 只更新可访问名称，无新请求/购物车副本。对语言控件的逻辑尺寸规则做精确选择器覆盖。
- `assets/storefront-cart-orb.webp`：内置 imagegen 生成的透明深青/银色立体购物车，192×170、13,814 bytes。原图保留在 D 盘 task outputs，不入仓库。提示词要点：现代购物车、深青篮身/拉丝银框、四分之三角度、48px可辨认、无文字/数字/商品/平台/背景。43px 耳机图与46px购物车图的实际非透明轮廓高度均约37.5px；两外圆仍56px。
- `assets/live-cart-experience.js/.css`：同一 native cart item 的 unit price/regular price、qty-aware 原价和 payable line_total；红色斜标仅计算商品自身 markdown。订单券与商品优惠分开，不能把订单折扣冒充商品折扣。BigInt 精确计算，币种/小数位/税基不一致时不作虚假行比较。稳定 keyed row、数量/移除与真实成功确认保持。
- `assets/live-cart-recommendation.js/.css`：删除重复 Add-to-unlock 文案，price 与 Add 1 box 同行，按钮右对齐/44px；可选可信 regularUnitPrice 有效时展示划线原价/红现价。原报价、时效、插件开关、重复点击及失败/未确认路径保留。

## 后端字段提案（下述缺口已由主题只读接线解决，不再阻塞）

原 recommendation quote DTO 有 unitPrice/linePrice，没有同 SKU 原价。最初提案让Claude追加可选 `regularUnitPrice: {minor: string, text: string}`。最新已改用现有主题card只读DTO：有效原生报价先显示，然后精确读取其product/variation的当前价与正常价，所有ID/当前单价minor/币种/小数位一致，且同generation/快照/修订、未过期且无加购pending时才补比较价。晚到/失败/不一致保持原生basequote；不覆盖有效服务端regular字段，不改令牌/资格/加购payload。实际推荐236/198与16% OFF已读回。因此后端字段仅是以后省一次GET的可选优化，本轮无需新增插件或等待字段。

## 原生积木清理

用户要求删掉首页 `Your next selection starts here.` 整块。已通过原生 LayoutAPI review/preflight/save/publish 移除 home685 的唯一 closing CTA，保留其余10个模块、7其它 layout 与全部店铺设置 hash；无 CSS 隐藏代替删除。回执在 `D:/Codex/Tasks/2026-11-05/remove-home-closing/outputs/native-receipt.json`。源码/Git 不会自动迁移此数据库修改。

## 验证与界限

63项 Node 购物车检查通过（包含新价格语义及原确认/近期/报价生命周期），15 PHP utilities guard 与14入口检查通过，PHP/JS语法与 diff 检查通过。IAB 实际名义390/360预览：首页外圆56×56/radius50%、与客服gap12px、count1、唯一原header cart-link；345内容=345 scrollWidth，无横溢。实际点击球打开 `vc-live-cart-drawer`，球隐藏，真实车1行显示Retatrutide60mg原价284/现价250.80、Save12%($33.20)，推荐55/box与Add1box同行。未改车/数量/用券/订单/支付/发送WhatsApp。

任务证据位于 `D:/Codex/Tasks/2026-11-05/cart-savings-layout/outputs`。仅LocalWP主题联接即时生效；公网尚未部署。最终活动置顶验证、Git提交/推送与剩余问题以本页后续追加为准。既有 peer `product-dialog-experience.js` 的西语一行 SHA256 4E5C0C22689472162395733A6838F58F07FE1B3C8A9A2DEF94DC67540DDCA452 保持不动、不stage。

## 后续用户修正及检查点

- 活动横条：`campaign-strip-sticky.js`只提升原生首页模块wrapper为sticky，用真实header computed top和高度测偏移，无scroll监听/复制报价。CSS初始/吸顶同一胶囊、width不变、左右auto居中；外层背景透明，深色条本身保留。同步原生anchor scroll-padding；菜单/dialog时隐藏。360实际313px条、左右各16px、rgba(0,0,0,0)、radius9999；下滑header.bottom68.67/pin.top68.67，collection.top132.67在pin.bottom120.67之后，无横溢。8独立生命周期检查与原16公告检查/45PHP呈现检查通过。
- 耳机仅缩为43px，外球56px保持。透明购物车图保留46px呈现，实际非透明轮廓与耳机约等大。
- 用户要求Recently viewed同优惠设计：现有同SKU card DTO追加原生price minor/currency/precision及regularPrice（仅同SKU真实is_on_sale且同税基比较有效）；不接收客户端原价，不改变canAddOne。JS/CSS增加红斜标与原价/现价/box。定向recent21内部检查、PHPcard51及现有价格9通过。实际cart DOM读回Tirzepatide20mg原价78/现价55/Save29%23；GHK-CU50mg无优惠不显示假原价。
- 购物车顶部移除可见1box节点及其JS赋值；小号Shopping cart22px/28px和44px关闭。原生cart文档11727只改cart_title，经正常LayoutAPI计划/核对/发布，7其它布局与全部Settings hash保持；新字段默认也同步，保留后续后台编辑能力。浏览器实际标题Shopping cart且count节点0。回执cart-title-receipt.json。
- 最新联合Node94项、独立sticky8项、PHPcard51/活动45/utilities15/entry14均通过（内部数量与顶层不叠加），PHP/JS语法/diff通过。没有真实改车/加购/下单/付款。

**未完：M05.03进度条固定预留76px导致空白、达标鸭子的静态呈现与真实跨档动画；M05.04推荐原价可信字段/接线；最终全部cart视觉及Git整合。**用户随后要求先细分本次全部模块给其它窗口，已交付[模块工作台](frontend-module-workboard-2026-11-05.md)，包含所有涉及编号/文件边界/状态；本窗口继续独占M05，当前M05.03。未把部分完成说成整轮完成。

以上为4b1331f开发检查点时的剩余项，**后续已完成M05.03/M05.04及真实cart视觉检查**：

- progress无鸭子时固定预留76px改12px；真实确认已达标/正数已用优惠/eligible节点/celebration和smile启用时，首屏显示最高达标节点的静态鸭子。动画只给真实changed qualifying state，结束回静态；离屏/隐藏/BFCache/取消/降档/无资格/图片坏均正确收束，first IOtrue不吞掉有效baseline。10项新生命周期检查通过。实际车未达标12px；独立HTTP渲染页用501.60 fixture实际画出鸭子、celebrating=false、无真实车写入，截图duck-isolated-render.png。
- 推荐同SKU卡片补原价接线已完成（见上），小红斜标来自商品价差，不取订单incrementalSavings。价格+Add同行，44px按钮；pending期间不重建正在操作的Add按钮，失败/过期/切换快照不把basequote清掉。
- 最新联合110项Node顶层通过；8独立sticky、PHPcard51/活动45/utilities15/entry14、JS/PHP语法与diff检查均通过。实际名义360/content345，drawer333=scroll333，Shopping cart顶部60px/count节点0，推荐236/198/16% OFF，近期78/55/29%与GHK无假优惠均已读回。未执行真实加购、数量/券写入、订单/支付或公网部署。最终提交/远端结果另记。

## 本机源码交付引用

源码检查点`4b1331f`、收尾实现`4efe2ce63e0825e4c7160f9c7aca78ec656ad69a`；干净main已正常整合至`a47420163190a78ecf2357cc4b4a46d7556a0bad`，保留已fetch的语言后端5ecf886历史。此引用指本轮功能源码，后续文档提交不改变其验证范围。FE只剩原peer experience.js一行dirty/hash未变，未stage/覆盖；backend worktree未动。atomic push发生curl55/RPC连接reset，虽尾部打印Everything up-to-date，但没有成功ACK，不认作远端同步；独立ls-remote核验结果记群与项目记忆。最终手机预览tab8保留购物车，实际截图cart-savings-final.png；隔离鸭子fixture只作渲染检查，其HTTP helper已停止。

## 最新明确纠正：鸭子仅一次动效，新增优惠汇总

本节取代前文“动画结束回静态鸭子”及“本轮全部事项已验收”的中间状态。用户看到静态鸭子后明确要求：只播放一次，结束必须消失。

- M05.03：删除全部静态驻留、图片加载/滚回/页面恢复时重新显示逻辑。原生真实 qualifying state 变化才播放原2.8秒动效，结束stop隐藏鸭子、清粒子及has-mascot，空间回12px。首次已经达标与重复快照静默；减少动态偏好只给2秒有界反馈后隐藏。离屏/页面隐藏/pending/失去资格/销毁均停止，迟到图片decode不能复活动效。10项生命周期检查通过，未修改真实客户车来触发。
- M05.06/M05.07：totals helper与摘要renderer展示原价小计、商品价差、每张真实券、每笔实际活动费用、运费/税、原生Total payable和底部红色You save。中文既定黑五按精确pc-promo-ID关联applied_all显示Black Friday offer；不重复累计promotion预测额或activity_listener实际额。商品差严格币种/税口径对账，分配失配保守回退；有税组件时总省注明Before tax。
- 未来运费优惠：后端目前只在下划线私有rate metadata保留部分原值，Woo不公开。前端不能反推，当前仍显示真实运费。新增严格可选shipping_savings消费者：同响应、同币种/税基、实际selected package/rate、原额-减免=实付、全包裹对账才展示痕迹；缺字段不伪造。后端增量提案见[订单汇总/运费契约](frontend-cart-summary-shipping-2026-11-05.md)，不代表后端已实现。

最新联合124项Node顶层全过，JS语法/diff通过；日志outputs/final-transient-summary-tests.txt。3个本机静态资产HTTP200并与源文件逐字一致，inc原有filemtime版本化保持。未改后端、真实车/券/订单/支付或公网部署。peer product-dialog-experience.js哈希仍4E5C0C22689472162395733A6838F58F07FE1B3C8A9A2DEF94DC67540DDCA452，未stage。

**浏览器核对限制：**最新阶段原tab8读回/刷新连续超时；重置连接及新开手机预览亦在30秒超时，无法获取最新实际截图。cart-savings-final.png只证明前一检查点的商品/推荐/近期样式；duck-isolated-render.png是已废止静态方案，不能当最新一次性动效证据。最新鸭子/摘要的浏览器画面仍待连接恢复复验，不虚报已完成。Git最终引用另记。
