# 生产上线记录 2026-11-10（接 2026-11-07 文件）

### 03:25 UTC+8 实时访客页重做（店主：整洁、方块式、看出谁最可能下单、只看正在看的真人）
- 新 `peptide-core/includes/tracking/class-intent.php`：每位在线访客按本店最近 6 小时行为打购买意向分 0–100（结账进度 / 购物车金额 / 看了几个商品详情与商品页停留 / 选规格 / 加购 / 回头客 / 在线状态），给出等级（很可能下单 ≥60、有兴趣 ≥30、随便看看）、理由、最关注的前 3 个商品（含是否已加购、停留时长）、最常点的版块和一句白话建议。不调用任何外部 AI，数据不出站。
- `tracking/class-query.php::live()`：每位访客附 `intent`、`source`（utm 或来路），按意向分排序。
- `assets/tracking-admin.js`：实时列表从大表格改成卡片网格；顶部四个数字（正在浏览 / 后台挂着 / 刚离开 / 很可能下单）；默认只显示“正在浏览的真人”（status=active），绿色描边 + 呼吸灯“正在看 · 页面”；后台挂着、刚离开的可在“在线状态”里切换。
- `assets/tracking-insights.js`：实时页的“全站行为概览”改为“大家对什么感兴趣”：最受关注的商品、最常点的版块、被加进购物车最多，三列热度条；去掉停留时长汇总、页面停留表和“全站分析 AI 尚未接入”框（访客详情页保持原样）。
- 页头说明压成一行。`assets/tracking-insights.css` 新增卡片和热度样式。
- 部署两台主机各 7 份（含新店模板）。阳站实测：9 位在线中 1 位正在看，高亮显示；热度榜正常。
- 03:57 访客归档改为按进站时间（`visits.start_ms`）从新到旧，游标同步改为 start_ms；还在逛的访问也列出，标“正在浏览 / 还没离开”（`reason=ongoing`，`is_ended` 由原结束判定算出）。说明文字同步。阳站实测：#142（还在逛）排第一，其后 #141、#140、#139 按进站时间排。
- 04:13 归档未结束的访问显示和实时访客同一个在线状态（正在浏览 / 停住了 / 刚离开），修正“一律标正在浏览”。归档第一页每 20 秒自动刷新（页面在后台、或已点“加载更多”时不刷新）。阳站核对：#144、#143、#142…按进站时间倒序。
- 04:40 按报价表补两个缺的规格（6 家店都加，数据库直接写，无代码改动）：Acetic Acid water 0.6% + AA3 3ml×10vials $7；bac.water + WA3 3ml×10vials $6。暂用 10ml 规格的图，库存 200、管理库存，排在 10ml 前面；只设售价不设划线原价（报价表没有原价）。变体带 meta `_pc_note`。前台 Store API 6 家店均显示 3ml / 10ml 两个规格。核对结论：报价表 202 个规格其余全部在店，售价全部一致；店里 92 商品 200 规格图片齐全、文件都在。
- 05:00 店主改主意：3ml 规格不要了。6 家店的 AA3 / WA3 已删除（先按订单行项目 `_variation_id` 核对，没有订单用过），属性选项去掉 3ml，只留 10ml。
- 一致性核对：两个水的图（2400×2400 主图、规格图）、价格（$10，划线 $15，和报价表一致）和其他商品一致；文案不一致——Acetic Acid water 0.6% 描述为空（来源表也为空）；bac.water 描述写的是“无菌注射用水 SWFI”，还写“不是 BAC Water”，和商品本身（抑菌水）矛盾，末尾留着内部草稿“Short Version (For Product Listing / Customer Message)”。按其他商品的版式（📦 规格框 / 🔬 概述 / 💡 用途 / 使用方法 / 储存 / FAQ）重写两段英文描述，只写研究用途，不含功效宣称；6 家店都已更新，旧描述存在商品 meta `_pc_desc_backup_20261110`。脚本入库 `tools/catalog-water-descriptions-20261110.php`。前台 6 家店核对：各只有 10ml 一个规格、$10/$15、1 张图、新文案。
- 顺带发现：TR20+RT40 的描述也是空的 → 已补写（见下）。

### TR20+RT40 描述补写（6 店）
- 商品 #11786（SKU 692425308128935943，Tirzepatide 20 mg + Retatrutide 40 mg，60mg×10 vials）原描述为空，导入表中也为空。
- 按两种水的版式补写英文描述：规格框、概述、两种成分（CAS/分子量/受体）、研究用途、配制示例（3 mL → 共 20 mg/mL）、储存、FAQ。只写研究用途，无剂量和功效宣称。
- 脚本 `tools/catalog-tr20rt40-description-20261110.php`；s0001 + s0006–s0010 已更新，旧值（空）存 `_pc_desc_backup_20261110`；6 个域名 Store API 核对通过。
- 顺带发现：主店仍有 10 个商品描述里带内部草稿段落 “Short Version (For Product Page / Customer Direct Message)”：Melanotan I、Tesa10+IP5、Matrixyl、Cardiogen、EPO、bronchogen、AHK-CU、Cartalax、Gonadorelin、P021（未处理）。

### 仪表盘漏斗可点 → “发起结账的客户”页（全部店铺）
- 仪表盘漏斗的 发起结账 / 添加地址 / 添加物流 / 添加支付 / 支付成功（人数 > 0 时）可点，打开隐藏页 `admin.php?page=pc-checkout-dropoff&range=…&step=…`，菜单高亮“仪表盘”。
- 口径同漏斗（Data\Visitor_Funnel）：时间段内开始的访问里 stage ≥ 3 的不同访客，一人一行。每行：进结账时间、客户（会话 / 订单里填过的姓名、邮箱、电话、城市国家，设备、第几次来）、来源和广告系列、购物车明细、运费 / 合计、走到哪一步、结账页停留、为什么没下单（规则判断）、操作（行为记录、弃单、写邮件）。
- 顶部：发起结账 / 没下单 / 地址没填完就走 / 结账页停留中位数；按原因筛选；按步骤筛选；运费占商品金额 ≥ 40% 的人过半时给出运费提示。只读。
- 新文件：`includes/tracking/class-checkout-dropoffs.php`、`includes/admin/class-checkout-dropoff-page.php`、`assets/checkout-dropoff.css`；改：`assets/overview.js`、`assets/overview-workspace.css`、`includes/admin/class-overview-page.php`（links.dropoff）、`includes/class-plugin.php`。web + HQ 各 7 份已部署（备份 ~/vialcove-deploy-backups/trk-plain-*）。
- 核对：阳 今天 13 人、7 天 26 人，填完地址 6，与仪表盘漏斗一致；主店 s0001 渲染正常。
- 发现：6 家店运费方案都是商品金额 < $1000 一律 $45（会员 5% 后 $42.75），阳 今天 13 个发起结账里 7 人运费超过商品金额 40%，11 人没填完地址就走。是否调整运费由店主决定（未改）。
