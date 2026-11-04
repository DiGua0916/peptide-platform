# 首页商品优先排版交接 · 2026-11-04

作者：Codex。范围：本机 LocalWP 首页、主题呈现及现有装修积木；不修改后端活动规则、商品价格、库存、购物车或付款逻辑。

## 用户批准的结构

用户确认将手机首页改为：**短品牌介绍 → 真实紧凑活动券 → 分类/搜索商品 → 服务**，原 COA、统计、品牌理由、About Us、FAQ 与结尾内容继续保留。此次替代上一轮“完整活动气泡先于大 hero”的首屏顺序；不是删除原有品牌内容或另建静态首页。

home685 的10个原模块身份、类型、开关、条目及来源均保留，只重排前4项：

| 当前顺序 | moduleId | 原生积木类型 |
| --- | --- | --- |
| 1 | `hero` | `vialcove/hero-h1` |
| 2 | `campaign-bubble` | `vialcove/promotion-card` |
| 3 | `collection` | `vialcove/product-collection` |
| 4 | `service-benefits` | `vialcove/info-list` |
| 5 | `approach` | `vialcove/report-feature` |
| 6 | `collection-stats` | `vialcove/counter` |
| 7 | `how-it-works` | `vialcove/card-list` |
| 8 | `about-story` | `vialcove/rich-text` |
| 9 | `questions` | `vialcove/faq` |
| 10 | `closing` | `vialcove/cta` |

后6项内容及顺序未改。页头仍使用原导航与 sticky 设置；活动不放回固定页头。

## 可编辑设置与实际呈现

- Hero 在已注册的 `presentation` 中增加 `product_first` 选项，原 `standard` / `focused` / `demo` 保持可用。此次保存标题为 `Research peptides. Clear choices.`，描述为 `Choose your specification. Order by the box.`，按钮标签为 `Shop peptides`；原 href `/#vc-collection` 与 target `_self` 保留。标题仍使用原 text 字段，由正常排版自然折行，没有临时 HTML 换行或未注册字段。
- 紧凑 hero 不输出原眉标、单独目录数量行、第二 CTA 和大媒体，但这些原设置仍保存在同一积木中，包括媒体引用、`featuredProduct`、`caption`、`eyebrow`、`catalogCaption`、`secondaryLink`。素材未删除，也未为此复制商品数据；切回旧呈现即可恢复这些内容。
- 活动继续引用同一后台真实活动，复用原生档位、资格、税/币种及状态结果，当前用紧凑券样式呈现。只将该模块的 `paddingTopPx` / `paddingBottomPx` 桌面、手机四个值改为0；未改变活动金额、日期、券规则或绑定。
- 商品积木增加已注册 `catalogLayout = standard | product_first`。此次选 `product_first`，`eyebrow` 为空，标题为 `Shop peptides`，重复入口 `link` 设为空。原生字段清理将 null 规范化为空 href 的 link 对象，前台不输出重复 CTA。原 `cardPresentation = demo`、来源、手选优先商品、顺序、过滤、规格、价格及目录加载配置全部保留。
- 商品卡片与原生商品弹窗继续使用当前真实目录；页面搜索与分类不依赖新的占位产品。

## 发布与保护

根代理已执行审阅过的 guarded PLAN/APPLY，仅经正常 `Layout_Controller::save/publish` 更新并发布 home685；草稿与 published 已同步。原 home draft/published 精确哈希、revision、完整候选语义先核对；数值 int/float 的 JSON 往返差异单独兼容，不将字符串、布尔或条目顺序宽松强转。

header686、footer687、style688、旧测试11462/11463、product11725、cart11727共7份文档的 revision、草稿和 published 原文哈希均未改变。未知新修改会使迁移停止，不盲目覆盖。后端 dirty worktree、活动设置、商品价和订单数据均不在本轮修改范围。

迁移导出、原文备份、候选与应用回执保留在 `D:/Codex/Tasks/2026-11-04/home-product-first/work`，不入 Git。该目录包括 `home-snapshot.json`、`home-product-first-plan.json`、`home-product-first-applied.json` 和 `guarded-home-product-first.php`；一次性脚本不可不经复核重跑。

## 实际验证

本节记录根代理完成的集成验证；不是本交接子任务另行操作浏览器所得。

- 名义390px手机预览实际内容宽375px：hero高188px、活动券高约99.33px、商品区顶部约402px、首行商品价格底部约803px。
- 名义375px / 430px预览的实际内容宽分别360px / 415px；均无横向溢出，活动3档完整显示。名义尺寸与实际内容宽差异来自当前预览滚动条，不混作真机宽度。
- 输入 `Retatrutide` 实际筛选出2件商品，清空后回到8件当前显示商品。真实 Tirzepatide 弹窗正常打开，选中 `20mg*10vials`，原生加购按钮启用。
- 未点击 Add、未改变用户购物车、未创建订单或执行付款。上述验证不等于真实加购、活动扣减或支付验收，也未作实体手机 FPS/性能结论。
- 23项新 hero/catalog 检查、33项活动检查、41项商品卡检查、8项 Node 检查以及 PHP lint / `git diff --check` 通过；不同套件不合并宣称一个累计断言数。
- 桌面1280×720实检：文档宽1265px，无横向溢出；hero高136px、活动券约97.33px，商品区top354px。实际四列商品图与分类/搜索正常，截图desktop.jpg。

## 主题文件

- `wp-content/themes/peptide-site/inc/demo-home-modules.php`
- `wp-content/themes/peptide-site/inc/demo-home-catalog.php`
- `wp-content/themes/peptide-site/inc/campaign-bubble.php`
- `wp-content/themes/peptide-site/template-parts/modules/demo-hero-h1.php`
- `wp-content/themes/peptide-site/template-parts/modules/demo-catalog.php`
- `wp-content/themes/peptide-site/assets/demo-home.css`
- `wp-content/themes/peptide-site/assets/demo-catalog.css`
- `wp-content/themes/peptide-site/assets/campaign-bubble.css`
- `wp-content/themes/peptide-site/tests/home-product-first.php`（新增）
- `wp-content/themes/peptide-site/tests/campaign-bubble.php`

当前生效范围是本机 LocalWP 已发布首页。源码与交接通过前端工作树交付，最终 Git 提交引用由根代理在提交后记录；**未公网部署**，不能将此前公网回执当作本轮改动已上线的证据。
