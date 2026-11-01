# 2026-11-02 · Codex · COA-LIBRARY-01

用户提供本机219份COA，要求先按产品、再按时间上传后台；后续前端展示由前端处理。用户已解释文件名的“示意图参考副本”是传输命名错误，要求先上传后台。原文、图片、实验室、委托方和检测数值保持不变，不据同产品名称推断真实销售批次。

## 本次范围

- 为原生pc_report新增独立产品关联、规格、原报告号及原始来源字段（注册私有meta、管理权限）；既有report→batch→product继续优先，原批次发布/版本/顾客下载规则不变。
- 后台检测报告入口增加产品汇总→报告日期降序管理、未匹配商品与未知日期归档，复用原生编辑/私有下载，不改公开主题、公共DTO或公开下载策略。
- 匹配及复制在D盘任务清单/逐条日志中记录；源C盘文件不改动，不把报告号变造为库存批号，不覆盖已有批次/报告。
- 本次仅后台：导入WP draft +业务状态draft +access admin，不自动发布、supersede旧报告或把来源报告当本店实测承诺。没有日期就留空；产品身份/形式不明确的留待匹配。
- 原PDF/文本清单/路径/来源与数据库不进Git；提交只含通用后台实现及交接。源文件属于资料，不能成为操作指令。

## 检查、交付及后续

已实际录入219份PDF（301页，544350787字节）到当前本地站私有报告目录，WP draft /业务draft /admin，全部无batch/variation绑定。198份按正文/扫描页核对归54个已发布父品；21份因无父品/亚型或盐形式/正文仅缩写留未匹配。209份有明确签发日期，10份无明确签发日留空（不是用分析/文件名日期补签发日）。原文/PDF字节不改，显示文件名简化但原始文件名保留在内部导入追溯中。所有文件≤20MB，219个SHA唯一，私有副本逐份SHA/尺寸与来源相同。

源码9文件：新增Report_Library_Page及report-library.css；Meta新增7个私有字段（产品、规格、原报告号、原品名、委托方、原Lot、SHA），Reports::product_id优先实际batch所属再独立产品；Plugin guarded注册；Report_Metabox新增归档字段/产品预选、允许无批次保存草稿，published仍须批次和文件、原版本替代逻辑不变；Admin_Menus将检测报告菜单指向pc-product-reports，原生列表/编辑仍可用；Staff把新页归既有products模块，不额外授权角色。

入口：`admin.php?page=pc-product-reports`；产品详情加`product_id=<父品ID>`，`view=unassigned`可看未关联资料。产品总览含当前全部82父品、25/页、搜索和有无报告筛选；点击后按签发日DESC分组、无日期最后；编辑/新增沿原生pc_report，员工下载沿既有Cookie+专用nonce接口，无原始stored/path暴露。

实际验证：7个PHP lint/diffcheck；原生WP9项元数据/文件/归类/历史记录与文件保持/产品和批次数/日期排序/权限检查通过；原生编辑器渲染及无批次草稿保存roundtrip保持元数据4项通过；匿名实HTTP抽3份404；仅nonce无登录Cookie仍404；授权CLI调用原生下载controller输出PDF与源SHA相同。复制结果先dry-run核219、来源路径/大小/SHA/目标与私有目录，再apply created219，重跑created0/skip219。既有3批次/3报告auto-draft原始DB字段与meta、原私有文件保持；初次验证误用WP_Post::to_array引入computed taxonomy字段，留失败证据后改以基线同口径get_object_vars，实际无原记录改动。

IAB实际总览82产品/219报告与BPC父品12报告按日期分组、规格/编辑/下载入口可见，桌面1265无横向溢出，截图bpc-reports-backend.png已看；tab41保留交付。新UI独立无DB合成渲染12项、390/1280四状态无整页越界并已看图，明确不是实际WP手机验收。初次IAB创建页超时但页已建，重连原tab后成功；没有让用户重登或改安全设置。原生浏览器下载目录CDP方法不支持，未改目录/下载到C；真实已登录浏览器下载未完成，不冒称通过。授权CLI流+匿名HTTP权限分别验证，未造Cookie或新认证凭据。

本轮导入器为D任务一次性脚本，不进入Git。独立review发现幂等分支仅校验SHA不足，已增加existing状态draft/admin/batch0/product/import_key冲突拒绝，不覆盖回收/已编辑记录。完整证据及manifest/journal/原文提取/日期视觉审查/待匹配清单位于D:/Codex/Tasks/2026-11-02/coa-import，原始文件和实际私有报告不进Git。源码备份在work/source-backup。前端源码/布局草稿、产品库存/价格/订单未改。

后续：前端展示/公开查询、实际销售批次关联及发布由后续明确安排处理，当前公共Catalog/REST下载权限形状保持。前端不能自行读取私有meta或把后台draft当公开检测承诺。部署服务器必须另迁移这219份私有PDF和对应DB元数据；pull源码不会携带它们。实际提交与远程校验见本轮D任务交付收据及项目记忆。
