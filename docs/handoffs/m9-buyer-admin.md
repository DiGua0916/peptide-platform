# M9 投手后台 交接（F5，2026-11-10）

分支 `claude/m9-buyer-admin-940389`，基于主线 `codex/checkout-acceptance-20261105`（已同步到 98493c1）。没有合并 main，没有发布，没有碰生产。

对应需求：PRD 6.2 的投手角色、ST-01、ST-02、ST-03、ST-06、ST-07。接口和需要其他会话配合的条目在 `docs/CONTRACT.md` 的「M9 投手后台 / M11 已购回访 / M14 前台主题（F5）」一节。

## 做了什么

| 提交 | 内容 |
| --- | --- |
| `c8badb0` | 投手角色 `pc_media_buyer`、6 个菜单、隐藏页 403、按账号开关 |
| `00978cd` | ST-01 开店向导、ST-06 广告设置、ST-07 店铺信息 |
| `b184198` | ST-02 投手首页、ST-03 订单只读 |
| `99d23e8` | 首页按 F4 的约定取数；功能声明；测试登记格式（和 M11 在同一个提交里） |
| `cdfa7df` | 店铺信息里后台店铺名称改为只读（不再写 `blogname`） |
| `6dfb5a5` | 独立复查后的 7 项修正；对外英文品牌名可改；和 F2 的约定对齐 |
| `5f7cb0f` | 首页加只读的“当前在跑的活动” |

### 投手角色（`core/class-staff-buyer.php`）

- 给销售账号（`pc_staff`）**加上** `pc_media_buyer` 角色就是投手版，去掉就恢复按模块勾选。原角色和勾选的模块不动，所以总后台“销售账号”列表、专属登录入口、停用开关都照常。默认不改任何账号。
- 开关三处：店铺后台员工管理页（`admin.php?page=pc-staff`，仅管理员）每行的“后台版本”、表单里的“投手版后台”勾选、命令行 `wp peptide buyer on|off|list|default`。
- 角色只存 `read`、`view_admin_dashboard`。页面要用的能力（多数页面共用 `manage_woocommerce`）只在允许的页面和接口上按请求临时给；其他地址上投手没有任何店铺能力。
- 后台页面、`admin-ajax.php`、`admin-post.php` 另有一道白名单，不在名单里的直接 403。`index.php`、`pc-overview` 转到首页，`profile.php` 转到店铺信息。
- 左侧菜单在 `admin_head` 换成 6 项：首页、订单、弃单、数据（下面保留数据子页和实时访客）、广告设置、店铺信息。
- 管理员（含总后台一键登录）不受影响。管理员可以直接打开 `pc-buyer-home`、`pc-buyer-ads`、`pc-store-info`、`pc-onboarding` 看投手看到的页面。

### ST-01 开店向导和 ST-06 广告设置（`core/admin/class-onboarding.php`）

- 5 步：修改密码 → 店铺信息 → 广告像素 → 验证像素 → 生成投放链接（可选）。前 4 步做完才算完成；没完成时投手每个页面顶部有红色提示条“还差 N 步就可以投广告”。
- 强制改密码：账号带 `pc_must_change_password` 时只能打开向导。切到投手版时带 `--must-change-password`、新建账号、管理员或总后台重置密码时写入；本人改完后清除。
- 每步是否完成按真实数据判断，状态按店铺记在选项 `pc_onboarding`（和开发文档 3.3 写的“用户资料”不同，原因见 CONTRACT）。
- 验证像素调用 `POST peptide/v1/admin/pixel-test`（F4 实现，**现在还没有**）。接口没上线时页面提示“验证功能还没上线”，第 4 步过不去，红条不会消失。
- 生成投放链接 `POST peptide/v1/admin/ad-link` 已实现。
- 广告设置页 = 第 3、4、5 步放一页。只管第一个 Facebook 像素；其他平台和其余像素不显示也不改动。

### ST-02 首页（`core/admin/class-buyer-home.php`）

- 5 个数字（访客、订单、销售额、转化率、弃单），今天 / 昨天 / 近 7 天，每个可点开看解释；待办（向导、支付域名、未跟进弃单）；向导进度。
- 数字来源：这家店的指标开关（M6）是 `on` 时用 F4 的 `Data\Metrics::preset()`，否则沿用现有数据大盘和弃单列表的口径。都是真实数据，没有用假数据。

### ST-03 订单只读（`core/admin/class-admin-menus.php`）、ST-07 店铺信息（`core/admin/class-store-settings.php`）

- 订单列表去掉勾选框、批量操作、行内按钮，加来源广告、运单号、退款状态三列；订单页面隐藏保存、改价、退款、作废、加备注。写请求在服务端拦截（只放行列表和打开单张订单的 GET）。
- 店铺信息：对外英文品牌名（可不填）、客服 WhatsApp、客服邮箱、改自己的密码；后台店铺名称和域名只读。

## 改动文件

- 新增：`core/class-staff-buyer.php`、`core/class-staff-buyer-cli.php`、`core/admin/class-onboarding.php`、`core/admin/class-buyer-home.php`、`assets/buyer-admin.css`、`assets/buyer-admin.js`、`tests/m9-buyer-scope-pure.php`、`tests/m9-onboarding-pure.php`。
- 修改：`core/class-staff.php`、`core/class-capabilities.php`、`core/admin/class-staff-page.php`、`core/admin/class-admin-menus.php`、`core/admin/class-store-settings.php`；`core/class-plugin.php` 追加两行注册（`Staff_Buyer`、`Buyer_Status`）；`tests/data-observations-suites.json` 追加 3 条；`docs/CONTRACT.md` 追加 F5 一节。
- 没有新数据表。新选项：`pc_onboarding`、`pc_media_buyer_default`。新用户资料：`pc_must_change_password`、`pc_media_buyer_since`。新角色：`pc_media_buyer`（首次请求时自动创建）。

## 实际做过的检查

- 本机编译了 PHP 8.3.36 命令行（平台所有者同意后从 php.net 下载源码），另外机器上已有 Node 26。
- `php -l`：这条分支改过的 PHP 文件全部通过。
- 纯逻辑测试：`m9-buyer-scope-pure`（161 项）、`m9-onboarding-pure`（84 项）通过。
- 仓库默认套件（`node tests/run-data-observations.cjs`）：49 通过、9 失败。9 个失败在没有改动的主线上同样失败，和这条分支无关；F5 登记的 7 条全部通过。
- 独立复查：另起一个会话对照 WordPress 7.1.4 和 WooCommerce 11.2.1 的源码逐项核对，确认了 7 个问题（见下），都已修正并补了用例。它同时核对过：隐藏页面的访问判断、`admin_head` 重建菜单后的高亮、工作区外壳（iframe）、REST 能力的进出配对、订单页隐藏按钮用的选择器，结论是和源码一致。
- **仍然没有做的**：没有在真正运行的 WordPress 里登录投手账号点过页面，下面的验收清单一条都还没做。`buyer-admin.js` 只做过语法检查。

### 复查确认并已修正的问题

1. 安全：投手可以用 `delete_all` 参数清空订单回收站（WooCommerce 不看 `action`、也不再查权限）。现在带 `delete_all` 或 `claim-lock` 的订单页请求一律 403，“清空回收站”按钮也隐藏了。
2. 改密码时对密码做了 `wp_unslash`，带引号的密码保存成功后登录不上（首次登录强制改密码时会把账号锁在外面）。改成和 WordPress 自己一致；员工管理页设置密码同样修正。
3. 店铺信息第一次保存时，会把“演示模式”“发货前必须指定批次”等默认开着的开关关掉。改为从完整设置出发再写回。
4. 没改密码的投手仍能调用首页数据和投放链接接口。权限改为 `Staff_Buyer::can_use()`。
5. 未跟进弃单数没有限定近 7 天。
6. 每生成一次投放链接都会让首页缓存失效。现在只在第一次写选项。
7. 投手自己的前台访问不再被当成员工排除（追踪、像素、离开挽回按 `manage_woocommerce` 判断）。**这一条没有在 F5 的文件里解决**，已写进 CONTRACT 请 F4、F2 在判断里加 `Staff::is_staff()`；只影响前后台同域名的环境。

另外按复查的建议做了：投手打开订单后去掉自己加的编辑锁；订单页不显示“显示选项”；“投手”角色不出现在 WordPress 自带的角色下拉框里。

## 上沙盒后的验收清单

1. `php -n` 跑 `m9-buyer-scope-pure.php`、`m9-onboarding-pure.php`，应全部 PASS（本机已通过）。
2. 管理员登录：菜单、页面和现在完全一样；员工管理页多一列“后台版本”。
3. 选一个销售账号切到投手版，用它登录：
   - 左侧只有 6 个菜单；首页数字能出来，切换时间段正常。
   - 订单列表和单张订单能打开，看不到任何写操作按钮；用开发者工具重放保存 / 改状态 / 退款请求都是 403。
   - 弃单、数据、实时访客（含 AI 分析）能用。
   - 直接打开 `admin.php?page=pc-promotions`、`pc-system`、`pc-staff`、`wc-settings`、`plugins.php`、`users.php`、`edit.php?post_type=product` 都是 403。
   - `wp-json/wc/v3/orders`、`wp-json/wc/v3/payment_gateways` 对它返回 401 / 403。
4. `wp peptide buyer on <账号> --must-change-password`：登录后只能看到向导，改完密码才放行；走完 4 步（第 4 步需要 F4 的 pixel-test）红条消失。
5. `wp peptide buyer off <账号>`：恢复原来按模块勾选的菜单。
6. 特别留意（这几处没有环境验证不了）：
   - 菜单在 `admin_head` 重建后，当前菜单的高亮是否正确；`pc-data` 走工作区外壳（iframe）时菜单是否正常。
   - WooCommerce 订单页的管理端脚本在投手下会不会因为部分 REST 请求 403 而弹错误提示。
   - 订单页隐藏按钮用的 CSS 选择器是否都对得上当前 WooCommerce 版本。

## 需要其他会话配合（详见 CONTRACT）

- F4：`peptide/v1/admin/pixel-test`（F4 已在自己分支实现）；服务端归因放开 `utm_campaign` / `utm_term` / `utm_content`（主题一侧 F5 已做）；员工判断加 `Staff::is_staff()`。
- F2：员工判断加 `Staff::is_staff()`（离开挽回）。
- F1：`/hq/staff` 带 `media_buyer`；`/hq/status` 带 `Onboarding::store_status()`；新店写 `pc_media_buyer_default = 1`。
- 日常会话：总后台“销售账号”页面显示和切换投手版；`pc_pay_domain_missing`；弃单“已联系”；弃单页对投手隐藏“立即扫描”“作废”按钮；`Mail_Outbox::brand()` 读对外品牌名。

## 没做的和要平台所有者定的

- 现有投手账号一个都没迁移。迁移名单由平台所有者确认后逐个切换。
- 订单导出（PRD ST-03 提到）没做。
- 投手只能管第一个 Facebook 像素；要让投手管多个像素，需要把 F4 的完整像素页加进投手白名单（一行改动，等决定）。
- 对外英文品牌名（平台所有者 2026-11-10 确认投手可以改）存在选项 `pc_public_brand`，主题已经读它（网页标题、Logo 替代文字、页脚版权）；页眉的 Logo 图片是固定的，不会变。邮件落款现在按域名确定，要跟着走需要日常会话改 `Mail_Outbox::brand()`。后台店铺名称（`blogname`）投手改不了。
- 订单页打开时 WooCommerce 管理端脚本的部分请求对投手会是 403，会不会弹错误提示要在沙盒里看。
