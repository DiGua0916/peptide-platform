# 首页浮球与购物车优惠排版 · 2026-11-05 · Codex

## 用户确认的范围

先做首页右下角购物车球，位于客服球上方；再处理购物车商品优惠与紧凑推荐。后续明确耳机图以购物车图的视觉大小为准、活动细横条正常/置顶均为胶囊，两端圆润。保留原首页、原生活动和购物车业务。

## 已完成的源码

- `inc/demo-site-parts.php`：首页把原 header cart-link 呈现为浮球，不复制入口；仍受 header 原生 showCart 开关控制，保留 cart URL 与现有 drawer 事件。其它页面不受影响。
- `inc/storefront-utilities.php` / `assets/floating-cart.css` / `.js`：只首页载入。56px 外圆、右距16px、客服球上方12px；避让原生底部导航、菜单或 dialog 打开时隐藏。原 `.vc-cart-count` 由 Woo/现有客户端更新，MutationObserver 只更新可访问名称，无新请求/购物车副本。对语言控件的逻辑尺寸规则做精确选择器覆盖。
- `assets/storefront-cart-orb.webp`：内置 imagegen 生成的透明深青/银色立体购物车，192×170、13,814 bytes。原图保留在 D 盘 task outputs，不入仓库。提示词要点：现代购物车、深青篮身/拉丝银框、四分之三角度、48px可辨认、无文字/数字/商品/平台/背景。43px 耳机图与46px购物车图的实际非透明轮廓高度均约37.5px；两外圆仍56px。
- `assets/live-cart-experience.js/.css`：同一 native cart item 的 unit price/regular price、qty-aware 原价和 payable line_total；红色斜标仅计算商品自身 markdown。订单券与商品优惠分开，不能把订单折扣冒充商品折扣。BigInt 精确计算，币种/小数位/税基不一致时不作虚假行比较。稳定 keyed row、数量/移除与真实成功确认保持。
- `assets/live-cart-recommendation.js/.css`：删除重复 Add-to-unlock 文案，price 与 Add 1 box 同行，按钮右对齐/44px；可选可信 regularUnitPrice 有效时展示划线原价/红现价。原报价、时效、插件开关、重复点击及失败/未确认路径保留。

## 后端对接缺口（提案，不是已实现）

当前 recommendation quote DTO 有 unitPrice/linePrice，没有同 SKU 原价。建议 Claude 在现有 v1 quoted recommendation 中追加可选 `regularUnitPrice: {minor: string, text: string}`，必须与当前 purchasableId/variationId、币种、小数位、单位和计税口径一致，取服务端同一报价快照。无可比原价就省略/null，前端隐藏。保留原 state/revision/expiry/token/一次加购和确认规则；不以推荐产生的订单 incrementalSavings 当商品降价，不改产品售价或活动规则。前端已兼容，当前 live 推荐不会伪造划线原价。

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
