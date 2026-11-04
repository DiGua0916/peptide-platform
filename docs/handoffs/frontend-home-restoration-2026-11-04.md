# 首页恢复与小横条公告 · 2026-11-04

作者：Codex。**本记录是最新生效决定，取代同日 `frontend-home-product-first-2026-11-04.md` 的商品优先首页。** 用户否决上一版，要求恢复原首页，并在上方保留一个小横条活动公告；不回滚整批已提交主题源码。

## 已完成与本机发布

- home685 恢复10个原积木及顺序：`campaign-bubble` → `hero` → `service-benefits` → `collection` → `approach` → `collection-stats` → `how-it-works` → `about-story` → `questions` → `closing`。
- Hero 恢复 `presentation=demo`、标题 `Research peptides. A clearer choice.`、描述 `Explore peptides and research essentials, with clear specifications and transparent pricing.`、按钮 `Explore peptides`，URL/target不变。原媒体、眉标、数量说明、次入口等设置恢复原呈现；素材一直保留，没有删除重建。
- 商品积木恢复原眉标 `Find your next selection`、标题 `The peptide collection.`、原 `Explore all peptides` 入口；`catalogLayout` 使用原生旧布局默认 `standard`。商品来源、优先商品、排序、过滤、规格与价格数据未改。
- 活动积木只改为已注册的 `presentation=campaign_announcement`，四个外间距仍为0，继续读取原生真实活动10980。新外层名为 `.vc-campaign-strip` / `data-vc-campaign-strip`，避免旧 utilities 的 `.vc-campaign-announcement` padding 规则影响高度；没有另造前端档位或金额。
- 旧 `product_first` opt-in schema/CSS/模板保留但当前未启用，不影响恢复后的布局，也可供以后明确选择。

根代理已通过正常 Layout API save/publish home685，draft/published同步。迁移先验证当前首页与上轮已应用候选完全一致，未知新修改会拒绝；随后只恢复审阅字段与顺序。header686、footer687、style688、旧测试11462/11463、product11725、cart11727共7份文档的revision、草稿和published原文哈希均未改变。未改后端dirtytree、活动规则、商品价格或业务数据。

## 已实测与限制

以下为根代理实际浏览器验证，本交接子任务没有另行操作浏览器：

- 名义390px / 375px手机预览实际内容宽375px / 360px，均无横向溢出。原 `A clearer choice.` hero及媒体恢复，紧凑模式节点数量为0。
- 新公告横条实测高36px，正常文档流。点击原生 `Explore peptides` 后，商品区Y约104.67px、导航Y=0、公告底部Y约−944px；公告随正文滚走，固定导航保留。
- 只读PLAN、PHP语法检查和根代理APPLY成功；实际回执确认 `protectedDocumentsUnchanged=true`、`draftPublishedSynchronized=true`、`promotionId=10980`、`businessRulesChanged=false`。
- 本轮未实际加购、创建订单或付款；上述预览不是实体手机、真实支付或新一轮桌面性能验收。

## 用户追加：左侧标题固定、右侧优惠循环

用户在恢复后另要求左侧活动标题保持固定，右侧真实优惠自动循环滚动。已新增主题 campaign-announcement.js，依赖原生 pc-commerce-blocks 后加载。左标题固定，仅右侧真实三档以26px/s连续循环；内容装得下时静止。悬停、焦点、触摸、滚轮暂停，手动滚动后保留2秒阅读；离屏、页面隐藏、BFCache暂停，减少动态效果时移除装饰副本，原三档仍可手滑。装饰副本 inert/aria-hidden、去ID/链接/关联属性/原生事件数据；不生成新活动或重复可点控件。独立小数位置避免浏览器scrollLeft舍入导致卡顿。原生金额与资格仍由同一活动输出提供。实际手机两次DOM读取标题X均20px、横条高36px，而右侧scrollLeft由274变为122.67（期间循环），原档位3+装饰副本3；副本inert属性及aria-hidden=true、无重复ID/链接已核对。首次只读DOM代理的inert属性getter不可用，修用真实HTML属性检查，不把该代理false当实际可交互。

## 工件与交付边界

任务文件：`D:/Codex/Tasks/2026-11-04/home-restore-announcement`。只读原文、差异审计、候选及guarded迁移位于 `work`；本机成功回执为 `outputs/home-restore-applied.json`，不入Git，也不盲目重复执行一次性迁移。

当前是LocalWP首页已发布；源码与交接通过前端工作树正常Git交付，最终提交引用由根代理记录。**未公网部署。**

## 最终检查与源码

16项实际生产JS隔离测试、45项campaign PHP检查、JS check、PHP lint、diff check通过。检查覆盖溢出才滚动、平滑小数速度、hover/focus/touch、隐藏/离屏、reduced motion、resize、重复bootstrap不叠副本以及销毁/BFCache。Impeccable单CSS layout扫描归档任务outputs/layout-scan.json；没有将源扫描当实体手机FPS结论。

新增assets/campaign-announcement.js和tests/campaign-announcement.cjs；修改inc/campaign-bubble.php、assets/campaign-bubble.css、tests/campaign-bubble.php。没有改插件/商品/购物车/付款，现有原页头公告class也未改。本次仅新独立strip class与原promotion积木样式选项；旧compact演示源码留存未启用。