# 视频图文（参考第 18 块）交接

日期：2026-10-30；作者：Codex（`/root/next_basic_gap_review`）。

本批仅修改 `video_text` 对应的 Registry、Fallback、modules.css、modules.js。主线程报告的起点为 `c8e5dea9a998b134d6be2ce6f0515c359e5fb9b5`；未运行 Git，未操作原站、QA 数据库或参考浏览器，未发布。四个公共源现在冻结，等待主线程合入 shared 控件后安排真实 WordPress 验收。

## 已落地行为

- 设置按标题/富描述/按钮、视频与封面、高度/位置/蒙层、播放、自定义高度、显示间距分组。富描述是 nullable opt-in；`null` 继续显示旧 `description`，空字符串明确清空。标题沿用原 key，改安全 textarea，并在转义后按真实换行显示，未开放标题 HTML。
- 单一设备字段 `heightPreset` 提供 PC 450/550/650/750、手机 250/300/400/500、全屏和自定义；服务端保留完整跨设备白名单。paired 控件通过 `deviceUI:'paired'` 与 `uiOptionsByDevice` 接入 shared owner 的实现，两行分别更新同一字段的单个设备 slot。
- 旧数据默认 `custom`，继续原 `heightPx`（schema 默认 600/420、合法范围均保留）；原 9 位置和字号 16–120 仍有效，常用字号 UI 40–100。新插入才使用 550/300 和四个播放开关 false。`design-editor.js` 与 `module-editor.js` 均调用 shared `initialAttributes()`；这是源码路径核查，尚非两个真实编辑器的端到端验证。
- 全屏以浏览器可用 viewport 为最小高度（100svh，回退 100vh），不扣 header。内容过长自然增长；标题 40px/48px，四周 40px；修正主题通用 header/段落样式覆盖，使位置和描述颜色真正跟随块设置。
- file 来源在 autoplay=false、controls=false 且有效附件 URL 时有真实播放/暂停按钮；使用原生 video 事件与 Promise 更新 busy、ARIA、结束和错误状态。键盘可操作，保留焦点。无 JS 有安全视频链接，失败重新展示链接及 live status。未改变共享 `video_player`、普通 video17、既有自动播放/减弱动态策略。
- 文字整层不截获播放器点击，真实链接和按钮恢复 pointer events；YouTube 使用现有 nocookie helper/cover，不复制参考跟踪参数、不新增 postMessage、不猜双媒体优先级。

## 参考证据边界

主线程实测 block18：PC 全屏约 1195.333px 对应 iframe clientHeight1195；手机全屏约 1135.333px 对应 clientHeight1135，手机初始 300px；PC/手机底右内容距右/底 40px；标题均 40px/48px。参考第 18 块确实生成官方样例 YouTube iframe 和缩略图，但未点击播放，不能称已解码。参考证据位于 `D:/Codex/Tasks/2026-10-29/store-builder-redesign/work/reference-basics/` 下的 video-text 系列文件；不要把第 17 块的空态限制套到本块。

## 本批实际验证

`D:/Codex/Tasks/2026-10-30/video-text-reference-ui/work/verification.json`：8 组独立原生 Edge fixture 通过。覆盖 34 个其他基础定义不变、普通 video17 file/YouTube HTML 逐字不变、nullable/空描述/安全换行、PC1280/手机390 的预设/全屏/旧自定义/继承/9位置/长文增长、本人 Canvas MediaRecorder 生成 WebM 的实际解码与时间前进/暂停/结束、真实坏媒体 error4、YouTube cover 及链接命中、无 JS 回退。JS 错误 0，实际外部请求 0（外部 iframe 在 fixture 中被拦截，未验证 YouTube 服务播放）。

视觉复核发现主题段落颜色覆盖富描述，已仅在 video_text 中修正。`color-verification.json` 聚焦验证 PC/手机富段落、加粗、链接、错误提示均继承白色，550/300、40px 字号/48px 行高、40px 内距仍有效；这不是额外重跑 8 组。最终截图位于任务 `outputs/video-text-1280.png` 和 `video-text-390.png`。图片文字是本人合成 fixture 封面，不是生产内容。

PHP 两文件 lint、modules.js `node --check`、新增行空白检查通过。fixture 使用显式 WordPress/media doubles，`wp_kses_post` 为 test double，因此不声称真实服务端 KSES、附件权限、保存/readback、全局 73 类型或正式站验证。已保留之前失败记录，不累计重试次数当通过项。

## 冻结与下一步

最终四源 SHA256：

| 相对 peptide-core 路径 | SHA256 |
| --- | --- |
| includes/modules/class-registry.php | dea419a2906253e75982c696e3aaa708a0746a406e3e231567cbbb522d81117d |
| includes/modules/class-fallback.php | f93773b4db06c030e770602adfc42fb0821f2551748e64ea124c36edcf0aa8e5 |
| assets/modules.css | 99576345a27db8c5187222c745b501570286542c5426a4540b32c254e2a7c71c |
| assets/modules.js | 6c5060f48ec81615660e31cf3d4cce65973bbb8bacf7e5e73bf3eb0b30ac9028 |

机器可读 pin、原始备份与 diff：任务 `work/source-pin.json`、`work/before-manifest.json`、`work/source.diff`。shared `module-controls.js` 由 `/root/device_choice_controls` 独占；最终 paired select SHA 为 `6a028a98d63841a34b72c9a9f80857c8e84dd3e3c56808ba2552d871b1bba6f7`，本人只读核对相同，详见其 `backend-device-choice-2026-10-30.md`。此前 6fd 中间 pin 已过时。

随后已完成独立真实 WordPress/WooCommerce 的五组限定验收，JavaScript异常0：原生新增默认与旧custom/位置/flags保留；同屏两套高度菜单、单slot保存/null重置、旧合法手机450px、实际viewport屏高与custom620/360；标题换行、nullable富描述的真实键盘/保存/KSES及实际主题颜色；旧WebM16经可达按钮真实播放/暂停/焦点与ARIA、长文自然增高、CTA及富链接真实命中；YouTube现有cover点击、固定nocookie安全输出和当前file预览的无JS链接。外部请求阻断，不称YouTube服务播放或全Gutenberg UI验证。实际布局使用合法块名vialcove/video-text，video_text只是Registry内部key/handler命名。

报告：D:/Codex/Tasks/2026-10-30/video-text-runtime/work/video-text-runtime-check.md及JSON。测试夹具定位、异步事件、重复observer及动态预览URL假设错误均保留原始记录并只补受影响组，未改产品源迎合。本人模板/doc/revision/账号、13个预览transient、私有凭据与2个profile已精确清理；旧商品/评价/用户等指纹、原WebM字节及原站只读指纹不变，邮件/订单0。56139与旧夹具仍保留。其他类型和后续slogan源码未改；root统筹本阶段Git和记忆，不表示全量积木完成。
