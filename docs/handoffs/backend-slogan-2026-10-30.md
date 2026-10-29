# Slogan 图标、富文本与手机排列

2026-10-30 · Codex。状态：源码、独立fixture、fresh审查与六组真实WP验收完成；全量装修目标未完成。Root独占参考404和Git整合，未发布、未部署。

## 参考与实际行为

Root实测第19 Slogan：桌面默认三项居中，图标在标题上方；手机轮播开启时一屏一项、gap30和三个真实可点击分页点，关闭时三项纵向全部可见。未确证自动播放，因此没有新增自播。全局标题/富描述、条目40内置符号或自定义图片/标题/富内容；自定义图片覆盖所选符号。没有发现条目独立跳转字段，未编造。

生产改动：

- `includes/modules/class-registry.php`：Slogan专属标题/描述、图标/手机排列、显示/间距面板；重复项图标和内容面板。独立`symbol`默认`none`，40项显式白名单；保留原`icon`媒体对象。新增`descriptionHtml`和条目`textHtml`，nullable并指向原`description`/`text`作为编辑器legacyKey。原字段类型、默认、合法图标16–120/字号12–48、3项默认/12项上限保留；滑块常用范围20–100/14–30不clamp旧值。
- `includes/modules/class-slogan-symbols.php`：40个原创固定SVG，无字体/CDN/第三方路径；未知/none/非字符串空输出。通过已有namespace autoload实际载入，无bootstrap改动。
- `includes/modules/class-fallback.php`：有效自定义图片优先，其次已知符号；staff无图维持占位，公开无图不造内容。nullable富内容为null时保留旧文本，空字符串明确清空；复用既有HTML清理渲染。条目ID/enabled和媒体引用不改。
- `assets/modules.css`：桌面响应式等宽列，合法多条目自然换行；手机false单列，true原生一屏一项横滚；SVG尺寸继承原iconWidthPx。圆点视觉4px、按钮24px可点按和键盘focus；无JS时保留原生滚动条与全部SSR内容。未改信息列表`vc-swipe-m`。
- `assets/modules.js`：仅新Slogan selector启用手机增强，真实scrollTo/手势滚动同步圆点、Home/End/方向键、RTL、条目选择及焦点跟随、活动项自然高度、减少动态偏好。无自动播放；桌面移除高度并隐藏手机分页。图片加载/ResizeObserver同步高度，长文不被固定裁切。

## 合同提案

仅Slogan新增`settings.descriptionHtml: html|null`、条目`symbol: none|40knownIDs`、`textHtml: html|null`。旧字段与范围保持。真实WP清理/保存/设备继承尚待positive，不能据纯fixture宣称接口已完整联通；root验收后同步CONTRACT。

## 实际已运行验证

任务目录`D:/Codex/Tasks/2026-10-30/slogan-reference-ui`：

1. 3生产PHP文件语法、modules.js语法、git diff--check通过。
2. `render-fixture.php`实际require生产源码与原before备份；明示WP/media doubles，无WP/数据库/真实KSES或附件保存声明。通过既有autoload调用helper。对35基础/共享定义比较，其他34完整相同；原Slogan字段默认/type/min/max/device一致。
3. `verify-native.cjs`独立Edge真实DOM共5组通过，JS异常0、外部请求0：1400/768/767/390/320布局、三列/手机一屏/关闭后纵向、图片优先/符号、24px圆点、12条合法极值不溢出；实际点击第2圆点后native scroll位置/aria同步；End到长第3项且root高度等自然全文、Home；rich/null/empty与旧纯文本/未知symbol、focuslink/RTL；禁JS实际ArrowRight横滚与SSR链接仍存在。不可将5组称为真实WP验收。
4. 已亲看`outputs/slogan-pc-1400.png`和`slogan-phone-390.png`，图标/标题/富内容/圆点呈现正常。图中全部为明确合成夹具，不是业务承诺。
5. 图标草稿自己的精确40/XML/几何/无效值验证已在`slogan-symbols/outputs/HANDOFF.md`记录；本批未重跑全部图标资产检查。
6. 新上下文只读fresh指出手机自适应短项85px/离屏长项293px时，默认overflow-y计算auto形成内部纵滚区（P2）。仅增强态追加`overflow-y:hidden`，无JS布局不受影响；定向`verify-vertical-fix.cjs`通过：真实wheel160后页面scrollY0→160、track.scrollTop仍0，focus离屏第三项末端link后scrollTop0、root320≈item319.719、焦点保留，JS0。此项单列增量，不把它并作重跑整批5组。

## 清理与下一步

独立浏览器均关闭，没有参考/原站登录或数据库写入。唯一D盘合成profile首次234条预检无reparse。其PowerShell固定路径递归清理被自动审批拒绝，只返回`rejected: blocked by policy`，命令未执行；已立即告诉root，未换工具绕过。准确命令及状态见`work/profile-cleanup-blocked.md`。后为P2定向验证仅复用本人同一profile并再次关闭，首次preflight不冒称最终清理依据；此profile仍保留，不称已清理。

Fresh只读复核已确认5源SHA匹配、P2关闭，无剩余P1/P2或明显视觉阻断，见`work/scoped-review.md`。源码最终SHA见任务`work/source-pin.json`；root掌握最后整合及Git提交。支付/邮件/搜索留空及AI暂缓保持。

## 最终真实WP结果

独立WP/Woo六组有效6/6、JS0，报告`D:/Codex/Tasks/2026-10-30/slogan-runtime/work/slogan-runtime-check.md/json`。原生新增/41选项及smile/gift实际不同SVG、图片优先和Remove恢复符号；全局/条目键盘编辑保存重载及null/空串；真实PC三列/手机350px轨道、30gap、24px圆点与键盘/焦点/长项自然高度；关闭轮播纵排；100/30及旧120/48、16/12保存不clamp；真实服务器清理及无JS原生横滚均通过。40个helper安全输出单独记录，不称40种浏览器视觉全部验收。

真实WP短85px/离屏长1193.984px时，wheel和可信Touch竖拖使外页滚动，track.scrollTop及当前项相对Y均0；可信Touch横滑实际移动240px。触控发生在同源真实WP顶层390页，编辑器iframe的dots/键盘另验。等待1000ms惯性结束再Home正常，不建立打断未结束惯性的保证。

最终五源与QA副本SHA匹配，shared6a028控件未变；CSS最终8283b3a017a21e7841f4b7868b31c29b552e4b3fe4c6974b74394ee08b6cc286。测试定位/等待误差保留并只补受影响组，没有修改产品源迎合。本人临时布局/模板/revision/账号、18预览transient、凭据和2个QA profiles已精确清，原商品/50评论/用户/Commerce/媒体与原站858/2555只读基线保持，邮件0订单0。独立QA56139及旧夹具继续保留，owner前述受阻profile仍在且未再次删除。
