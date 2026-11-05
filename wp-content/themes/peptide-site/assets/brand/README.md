# VialCovePeptides VI 2.0 — web assets

2026-10-17 修正：当前首页与商城使用 `packaging-corrected-20261017-{768,1536}.webp`。内置 imagegen 以原 Product_Mockup 和正式 Compact PNG 为参考，修正盒面 Logo 位置/留白/透视、标签和瓶盖细节，并将瓶内改为可见白色粉末概念。保留原图与原导出；正式 SVG 标志文件没有改动。新图仍是 AI 包装概念，不是实拍或规格依据。完整编辑提示、原尺寸 PNG、导出记录和验证见 `D:/Codex/Tasks/peptide-platform/work/packaging-fix-20261017/`；Sharp 只做等比缩小/WebP 导出，未用代码重绘画面。页面按 3:2 完整显示，商城说明移到图下。

来源：用户于 2026-10-16 提供 `C:/Users/JingYue/Desktop/VialCovePeptides_Logo_VI_Final(1).zip`。

完整解压资料、说明、8 页 VI PDF 和应用样张保存在：
`D:/Codex/Tasks/peptide-platform/work/vi-20261016/source/VialCovePeptides_Logo_VI_Final/`。

## 使用规则

- Logo 使用原 SVG，不重画、不拉伸、不加阴影。C 为唯一珊瑚红字母，其他字母及四节点符号保持原蓝青色。
- 完整 Horizontal 横式渲染宽度至少 320px；小尺寸用 Compact。favicon 使用包内小尺寸实心端点版本；符号表达抽象连接关系，不代表特定化学结构。
- `Inter-Variable.ttf` 保留为原始来源；网页使用由该文件生成的 400/500/600/700/800 静态 WOFF2，沿用 `VialCoveInter` 字体族及 Inter 造型，完整保留字形与语言映射。`web-fonts.json` 记录来源和各档 SHA-256；使用许可见 `Inter_OFL.txt`。未部署包中未提供的中文字体。
- `brand-tokens.json` 为交付包原件。网页令牌及可读性派生色在 `../brand.css`。主品牌色不变，深色小字和 hover 为可读性应用；Logo 文件字节未改变。
- `packaging-concept-{768,1536}.webp` 来自 `applications/VialCovePeptides_Product_Mockup.png`，仅等比缩小和压缩，无重绘；这是一张 AI 品牌包装示意，不是真实产品照片，不向商品数据库推断规格、用途、纯度或批次。
- 包中的标签、社交头像／封面和打印应用保留为原始资料，没有强行插入不相干页面。

导入记录和 SHA-256：`D:/Codex/Tasks/peptide-platform/work/vi-20261016/asset-import.json`。网站应用范围为主题前台，WordPress 管理后台、业务邮件与产品数据未在本轮改动。

## 静态网页字体构建（2026-11-05）

开发环境使用 Python 的 fontTools、brotli，运行 `tools/build-storefront-fonts.py`；运行时没有新依赖或外部字体请求。光学轴固定为原字体默认的 14，字重逐档实例化，输出按内容哈希命名，`font-display: swap` 保持文字立即可读。不要覆盖已发布的哈希文件。

验证运行 `python tests/storefront-fonts.py`：逐字形核对原始实例化轮廓、advance/LSB、完整 cmap、实际 OS/2 字重及确定性字节。现有 CSS 中间档位按浏览器静态匹配规则落到相邻档，例如 650→700、750→800；这不是原变量字体的连续轴行为。该交付排除了变量轴选择，但不能把文件校验等同于实际 iOS 字体问题已验收。
