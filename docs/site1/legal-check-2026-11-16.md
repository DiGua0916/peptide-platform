# 1 号站法务核对（F1，2026-11-16）

所有者让 F1 在发布前代做法务核对。这是按事实做的合规核对，不是律师意见；需要正式法律意见的，下面单独标出。

## 核对依据（生产只读查询，2026-11-16）

6 家店各有 252 份检测报告，都标为“COA 库公开”。报告的送检方（`_pc_report_source_client`）绝大多数是别家公司：Protide Health 148 份、Prime Peptides 9、Oath Research 8、Go Alpha Labs 6、Prime Labs 5、Vericor 4，其余 30 多家各 1–3 份；33 份没写送检方。也就是说，这些是别家样品的参考报告，不是 VialCove 自己在售的批次。

## 结论

| 项目 | 结论 | 处理 |
|---|---|---|
| 商品弹窗 / COA 库改成 “Batch COA”、“Results apply to the lot named in the report”、“Latest published report for this product” | **不通过**。前提（报告对应我们自己的批次）不成立，新说法会让顾客以为拿到的就是报告里那一批，属于误导性陈述。法务待审文档自己也写了：还有别家样品报告公开时不能用新说法。 | 发布分支把 `product-dialog-experience.js`、`product-report-library-viewer.js` 恢复成主线文字（“Reference COA / Third-party reference report… not a verified VialCove stock batch”），和线上一样。 |
| 经销商弹窗要点 “Batch COA with every shipment” | **不通过**，同上，而且是对每单的承诺。 | 去掉这一条，剩 “Dedicated account manager”、“Ships from our U.S. warehouse”。 |
| 18+ 弹窗 “I understand these products are for laboratory research use only.” 勾选框 | **通过**。和店铺已有的研究用途声明一致，不增加任何功效或用途说法。注意：勾选框不能抵消别处暗示人用的内容（见下面第 4 条）。 | 照发。 |
| 标准 FAQ（`tools/site1/lane-c-content-fixes.php`，改商品内容，不随代码发布） | **暂不运行**。前两问（“How do I read the COA for this lot?”、“Which lot will I receive?”）同样以“报告就是你收到的批次”为前提。其余几问可以用。删掉 FAQ 里的配制、混合说明本身是降低风险的方向。 | 改写前两问后再由所有者决定是否运行（生产写库，要单独确认）。 |
| 商品正文里 78 个商品的配制 / 注射步骤（“Reconstitution Instructions” 等） | **风险高，建议尽快处理**。“仅供研究”的商品写人用配制步骤，容易被认定为实际面向人用销售。 | 不在本次发布范围；列为后续。 |
| 运费险划线参考价 $20（设置项 reference） | **建议不用**。从没按 $20 卖过，划线原价属于虚构原价比较。 | 打开运费险时 reference 设为 0（不显示划线价）。 |
| 经销商弹窗 “Dedicated account manager” | 事实问题：要确有专人负责经销商。 | 所有者确认。 |

## 需要正式法律意见的

- 研究用途商品整体的销售方式（美国 FDA / FTC 对 “research use only” 多肽的执法口径），特别是第 4 条的正文内容。
- 如果以后要用 “Batch COA” 说法：先下架别家样品报告，只留自己批次的报告，再恢复新文字。
