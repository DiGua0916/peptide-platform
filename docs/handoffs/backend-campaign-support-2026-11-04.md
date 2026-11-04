# 首页活动公告与客服后端接入

2026-11-04，作者 Codex。来源：用户 `C:/Users/JingYue/Desktop/前端ui.md` 图 `image-20261104015052905.png` / `image-20261104015151087.png`。本轮只做数据和后台接线；主题的顶部公告排版、耳机或加号图标、帮助浮层和手机交互仍由前端窗口实现。

## 已复用的功能

- 现有 `pc_promotion` 活动及 `Promotions::get_public()/progress()` 是优惠唯一来源；活动监听配置已通过 `promotionId` 绑定同一个活动。既有活动详情、层级金额、实际结算、开始结束日期均不重做。
- 普通 `announcement` 装修积木已有 `mode=bound` + `bindings` 引用活动；不创建另一套黑五活动数据库。
- 客服后台已有号码、问候语、启停和左右位置。`peptide_core_get_support_contacts()` / `GET /peptide/v1/site` 的 `support` 返回本站已启用渠道；`channels[].type === 'whatsapp'` 的 `url` 为已规范的 `https://wa.me/...`。关闭、未填写或无有效渠道返回 `enabled:false/channels:[]`。前端必须复用该链接，不硬编码号码，不另建一份配置，不从其他店借用号码。图标、浮层和可见帮助菜单属于前端。

## 本轮补齐的缺口

此前 `site_header` 顶部公告只有手写 HTML，无法跟随活动变更。现新增同一装修 schema 中的两个字段：

| 字段 | 值 | 行为 |
| --- | --- | --- |
| `announcementSource` | `manual`（旧数据默认）或 `promotion` | 手写和绑定显式选择，无自动猜测活动 |
| `announcementPromotion` | 现有 `promotion` record 选择器的 ID/null | 复用原权限、nonce 和记录选择器；不可用 ID 不换成另一个活动 |

原 `announcement` 总开关、`announcementHomeOnly`、颜色、发布/草稿、模板归属、手写内容均保留。仅启用并位于允许页面时读取绑定活动；活动须公开、已发布、无密码、具有真实档位且 `active` 或 `upcoming`。停用、不公开、结束、删除、读取失败时隐藏该公告，不回退过期手写优惠。即将开始会标记 `Starts …` / `Coming soon`，不冒充已生效。

`PeptideCore\Campaign_Announcement::for_header(array $header_settings, bool $is_home): array` 提供只读 DTO。root 整合时可通过同形公共 helper `peptide_core_get_campaign_announcement()` 给主题调用。

```text
version: 1
visible: bool
source: manual | promotion
promotionId: integer | null
title, text: 纯文本
html: 已转义的基本内容，或经 wp_kses_post 清理的旧手写 HTML
status: active | upcoming | null
tiers: [{min, value, text}]，原活动实际档位，不复制计算规则
starts, ends: 原活动 ISO 日期或 null
url: 原活动详情 URL（无页面时可为空）
```

`visible=false` 的全部内容和 `promotionId` 清空；不泄露私密、草稿和停用活动名称。HTML不包括图标、动画、颜色或链接按钮样式。原 `Site_Design` fallback 页头已消费此 DTO 并保留 `.pc-design-announcement`，绑定有效时增加 `data-pc-promotion-id/status`；有主题覆盖的页头必须由前端主动接入 helper，不能因为插件已有 helper 就宣称主题已展示。

## 黑五实际配置步骤及本轮边界

1. 在现有营销活动中找到实际 `$300 → $25` 对应 ID，再确认全部档位；将该活动名称设为 Black Friday，保留 ID 和已配置档位，不重建或套入猜测金额。
2. 活动监听仍绑定该 ID；顶部公告选择“关联现有满减活动”并选择同一 ID，按用户要求可启用“仅首页”。
3. 黑五期间按用户确定的开始/结束时间设置，不能自行发明日期、倒计时或额外优惠券额度。活动绑定本身不发布或启用活动。
4. 保存/发布必须沿用既有装修草稿和修订接口，不能直接覆盖其他未发布编辑。没有新的自动迁移或批量 DB 操作。

本子任务没有修改生产/本地数据库，没有改活动名/金额/期间，没有开关真实客服，没有编辑主题或生产部署。实际配置由 root 核对现状后另行记录，不能把以上步骤当成已完成事实。

## 实际检查

- `tests/campaign-announcement-pure.php`：实际 helper、实际 `Site_Design::definitions()`、实际 `Fields/Records` 及现有 `Support_Contacts` 的 **50/50** 隔离检查通过。覆盖手写兼容、规范字段、明确 ID、首页范围、关闭/草稿/私密/密码/过期/坏 ID/异常读取隐藏、即将开始、HTML 转义、真实档位/日期传递、无内部字段复制，以及独立客服渠道和消息编码。
- 新 helper / header schema 的 PHP 8.3 `-n -l` 与 `git diff --check` 通过。
- 以上为 PHP 隔离 fixture，WordPress 函数为显式 doubles，不是已登录生产浏览器、真实活动发布或端到端黑五结算验收。无永久商品/顾客/订单/设置写入。

文件：`includes/class-campaign-announcement.php`、`includes/modules/class-site-design.php`、`tests/campaign-announcement-pure.php` 及本交接。子任务未 commit/push，由 root 与其他后端条目一起整合。
