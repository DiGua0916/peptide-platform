# 1 号站 A 线（首页、进站、顶栏、账户）上线清单

定稿：https://claude.ai/artifact/5MwyE9JgbTGy2AvYngKaDS（2026-11-14 确认）
分支：`claude/site1-ui-optimization`（基于主线 `codex/checkout-acceptance-20261105` 0a100736）

## 代码里已经做好的

| 模块 | 内容 | 提交 |
|---|---|---|
| P0-2 / P1-9 | 进站只弹 18+；原版式加“仅供研究使用”勾选框，勾上才能进入；底部 “Returning customer? Sign in”；注册框不再自动弹；已购顾客（`window.pcBuyer`）和已登录用户不弹；年龄确认没点前，广告带来的商品弹窗等待 `pc:gate-closed`，不再盖在年龄弹窗上 | 471a46d9、68debd65 |
| P0-1 | 读商品失败（缓存页面里 REST nonce 过期导致 403、网络偶发失败）自动重试两次，去掉 nonce；全部失败才显示 “could not load”，并发 `product_load_failed` 事件 | fddc45f6、b4489bd2、a21bbef2 |
| P1-2 / P2-3 | 顶栏没登录时人像图标旁写 “Sign in”，点了打开登录弹窗（邮箱 + 密码；注册输入两次密码）；手机菜单同样；登录弹窗里有 “Track an order without an account”；首页购物车放回顶栏；右下角客服按钮带 “Chat with us” 文字 | b4e1f6ed |
| P1-3 / P1-1 | 首页“欢迎回来”提示条（购物车还有 X 盒）、待付款订单优先显示 “Complete payment”、浏览器另存购物车 30 天并用 `?pc_cart=` 放回、最近浏览一排 | d753527a |

## 店铺后台要改的设置（不是代码，F1 在发布时按店执行）

1. **页头模块**（装修 → Site header）：打开 “Show account”、“Show cart”。
2. **悬浮查订单**：关掉货车图标的查订单浮窗（`Track_Widget` 的选项改为 `0`）。查订单已经放进登录弹窗和账户页。
3. **FE-02 地址同步**：打开开关 `pc_entry_address`。
4. **查订单页**：确认 M11 的 `/track-order` 页面已发布（登录弹窗里的链接依赖它）。
5. **首页第一屏（P0-1）**，在装修里调整：
   - 商品区（热销 4 个以上，带价格）移到第一屏，标语区缩小，视频往下放；
   - 第一屏顶部放一行运费规则（文字与 B 线 P0-5 一致：Shipping $55 · $10 off over $150 · $20 off over $300 · free over $600）；
   - 一行信任信息：Batch COA on every product · U.S. warehouse · 2–5 days（FedEx）。
6. **年龄设置**：保持 18（`pc_accounts.min_age`）；注册开关保持开（注册框只在用户主动点击时出现）。

## 已经跑过的检查

- PHP 语法检查（`tools/php/php -l`）：改动的 6 个 PHP 文件都没有语法错误。
- JS 语法检查（`node --check`）：改动的 4 个 JS 文件都通过。
- 主题测试：`product-dialog-polish.cjs` 通过；`home-quick-pending.cjs` 8/8 通过（已更新为覆盖重试）；`home-quick-selection.cjs` 6/6 通过；`storefront-utilities.php` 15 项通过。

## 还没做的验证

- 没有在真实 WordPress 站点上跑过页面（本机没有运行中的站点）。需要在 F1 的沙盒上，用手机按演示逐项验收：https://claude.ai/artifact/N6VNySi7eAqpeghDrJaPga
- 勾选框文字要等法务审核（和 P1-9 问答一起）。

## B 线（购物车、结账、运费）上线要打开的设置

B 线的功能默认全部关闭，部署代码不会改变收费。F1 按店打开：

1. **运费基础价**：配送方式的运费设为 $55（FedEx）。
2. **分档运费**（选项 `peptide_core_shipping_discounts`，后台“运费折扣”）：三条折扣
   - Orders over $150：减 $10，订单金额门槛 150
   - Orders over $300：减 $20，门槛 300
   - Free shipping over $600：运费 100% 折扣，门槛 600
3. **运费险**（选项 `peptide_core_shipping_protection`）：`enabled = true`，价格 0.99，参考价 20。
4. **经销商弹窗**（选项 `peptide_core_resale_prompt`）：`enabled = true`，门槛 600；并在客服设置里填好 WhatsApp 号码（没有号码时不显示）。
5. **首单券**：后台“首单优惠”一键创建 WELCOME5 并启用。

## 本机整站验收（2026-11-14，本机 WordPress 副本，手机尺寸）

已在本机真站跑通：18+ 弹窗与勾选框、广告商品等弹窗关闭后再打开、注册框不自动弹、欢迎回来提示条、结账（收起的订单摘要、无默认加州、邮编填州、Pay $X、表单刷新后自动回填）、$150 档运费 $45、$600 免运费只收 $0.99、运费险可取消、经销商弹窗只弹一次、待付款订单冒烟测试 9/9。

验收中发现并已修好：
- 购物车页的运费险没有勾选框（主题自己画的摘要只显示费用），客户无法取消；结账页运费险重复显示两行。
- 经销商弹窗盖在商品弹窗上（判断“有没有别的弹窗”的方法对全屏弹窗无效）；“只弹一次”只在当前标签页有效，删商品也可能触发。
- 购物车页“经销商价格”那行链接放进了被隐藏的区块，看不到。
- 结账页有优惠金额时，表单自动保存永远不触发，付款失败后无法回填。
- 结账按钮下 “You save $X” 优惠去掉后不消失。
- 右下角 “Chat with us” 按钮在手机上一半出了屏幕。

还没处理（记下，交给上线打磨）：
- 待付款提示条：Panapay 付完但回调没到时，可能提示“订单待付款”，有重复付款风险。建议只对 failed，或 pending 超过 N 分钟的订单显示。
- 首单券 Claim：服务器拒绝时按钮仍显示 “✓ Applied”。
- 后台客户统计：订单数含失败/取消订单；导出 5000 人时可能超时。
- FAQ 替换脚本：FAQ 之后没有 <h2> 时会删掉后面的内容，先跑 dry-run 检查。
- COA 文案改为 “Batch COA”，要法务确认这些报告确实对应在售批次。
- 最近浏览一排：本机是简易固定链接，接口拒绝 `rest_route` 参数，没法在本机看到；正式站用 /wp-json/ 不受影响，沙盒上确认。
