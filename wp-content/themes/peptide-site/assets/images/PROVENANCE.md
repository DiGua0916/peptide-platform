# 首页图片来源与使用状态

2026-10-17 包装图修正：当前首页/商城改用 `../brand/packaging-corrected-20261017-{768,1536}.webp`。由内置 imagegen 编辑原 VI 应用图，修正盒面 Logo 与标签/瓶盖细节，白色粉末为概念呈现。原图和旧导出保留；与下述三地场景来源无关。细节见 `../brand/README.md` 和任务 `work/packaging-fix-20261017/edit-prompt.txt`。

更新：2026-10-15。当前用于本地设计预览，不代表正式站素材验收通过。

2026-10-16 更新：用户提供正式 VI 包后，首屏已替换为 `../brand/packaging-concept-{768,1536}.webp` 银盖包装示意，来自用户 VI 应用图；原 `vial-powder-hero` 和 `vial-open-base` 仅保留历史、不再被页面请求。三个轮播场景和三张品牌卡片继续使用，来源与标识不变。VI 详细来源见 `../brand/README.md`。

| 文件前缀 | 来源 | 内容与状态 |
| --- | --- | --- |
| `vial-powder-hero` | 本轮 imagegen 生成，蓝白首屏截图作为风格参考 | 透明西林瓶、蓝盖、白色粉末、淡蓝 DNA。包装示意，无真实规格、标签或检测含义。 |
| `vial-open-base` | 内置 imagegen 基于上项编辑 | 历史开盖底图；用户已取消开合动效，当前页面不加载，只保留文件和生成记录。 |
| `israel-research-concept` | 用户素材包 REAL-01 | AI 合成研究团队场景，不能标为实际员工或研究成果。原选择状态未标记。 |
| `china-manufacturing-concept` | 用户素材包 CN-05 | AI 合成洁净生产场景，不能据此声称持有设备、资质或特定产能。原选择状态未标记。 |
| `us-fulfillment-concept` | 用户素材包 US-L03 | AI 合成打包场景，原选择状态 keep；不能据此认定仓库地址、库存或配送时效。 |
| `carousel-israel-research` | 用户素材包 REAL-02：显微观察与同事讨论 | 轮播第二屏，AI 科研概念场景，原选择状态未标记；不构成人员身份或合作关系证明。 |
| `carousel-china-production` | 用户素材包 CN-03：冻干装盘 | 轮播第三屏，AI 制造概念场景，原选择状态未标记；不构成设备、制造能力或资质证明。 |
| `carousel-us-warehouse` | 用户素材包 US-L02：规整冷藏间／标签分区 | 轮播第四屏，AI 仓储概念场景，原选择状态 keep；不构成库存、温控或物流服务证明。 |

原素材包：`D:/Codex/Tasks/2026-10-13/x/outputs/VialCove_素材总交付_实验室_工厂_仓储_20261015/`。

源包使用说明注明：图片根据外部实拍参考合成，参考图片商业授权尚未核实；仅用于内部选图与设计参考。AI 场景中的人物、铭牌、品牌、箱体 SKU 及设备屏幕不是机构或业务证明。公开部署前，应换为已获授权且可核验的实拍，或另行完成素材授权核实并保留示意标识。

网页导出仅等比缩小和 WebP 压缩，无放大、重绘或身份/标识修改；卡片由 CSS 以 3:2 裁切展示，原图完整保留。三地卡片每张提供 600 / 1200 px 两档；西林瓶提供 720 / 1254 px 两档。新增轮播场景为 768 / 1440 px 两档，其中 US-L02 原图宽 1433 px，第二档保留原宽（文件后缀仍为 1440，HTML 宽度与 srcset 描述符使用实际 1433）。轮播按响应式容器裁切，手机为 4:3。

首页共使用 6 张用户交付场景，另加 1 张生成的静态西林瓶图；余下 28 张场景和 1 张团队参考原件未上站。轮播导出记录：`D:/Codex/Tasks/peptide-platform/work/carousel-20261015/exports.json`。新增场景同样仅供本地概念预览，页面逐图标注 AI-generated scene。

生成记录及原图：`D:/Codex/Tasks/peptide-platform/work/asset-audit-20261015/`。验收清单在任务 outputs；主题只存网页使用的小图，不存原始批量交付包。

动效新增底图原件：`D:/Codex/Tasks/peptide-platform/work/vial-motion-20261015/vial-open-base-original.png`；网页仍采用 720 / 1254 px WebP。编辑模式、提示词和交互验证见 `D:/Codex/Tasks/peptide-platform/outputs/VialCove-瓶盖开合动效交付-20261015.md`。开合是视觉交互，不是实际产品开启步骤演示。
