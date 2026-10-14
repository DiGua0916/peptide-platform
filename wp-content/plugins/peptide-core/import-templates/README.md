# 批次与报告导入模板

`batches.csv` 是批次和检测报告的导入模板，可以直接用 Excel 打开填写，填好后另存为 CSV（UTF-8 或默认编码都可以）。

**模板里的示例行全是演示数据**（批次号、日期、文件名都是虚构的），填写真实资料前请删掉。

## 每一列的含义

| 列 | 必填 | 说明 |
| --- | --- | --- |
| sku | 是 | 商品编号，和报价表的“编号”一致（例如 TR10）。填某个规格的编号，表示这个批次只对应该规格 |
| batch_no | 是 | 批次号：3–40 位字母、数字、连字符，不能和已有批次重复 |
| manufactured_on | 否 | 生产日期，格式 2026-09-15 |
| retest_or_expiry_on | 否 | 复检或有效期日期，格式同上 |
| visibility | 否 | public（前台可见，默认）或 hidden（仅后台） |
| status | 否 | active（在售，默认）或 depleted（已售完） |
| origin_note | 否 | 来源说明。只填真实、可以公开的信息 |
| report_pending | 否 | yes 表示“报告整理中” |
| is_demo | 否 | yes 表示演示数据。真实资料填 no 或留空 |
| report_file | 否 | 报告文件名（PDF/PNG/JPEG，最大 20 MB），文件放在导入时 `--reports-dir` 指定的文件夹里 |
| report_type | 否 | coa（默认）/ hplc / ms / other |
| report_access | 否 | public（默认）/ registered（登录可见）/ approved（审核后可见）/ admin（仅员工） |
| report_issued_on | 否 | 报告出具日期 |
| report_issuer | 否 | 出具机构，只填真实机构名 |
| report_title | 否 | 报告标题，留空时自动生成，例如“COA TR10-2410-01” |

## 常见写法

- **一个批次对应同一商品的多个规格**：同一个 batch_no 写多行，每行一个规格的 sku（参考模板第 2、3 行）。
- **一个批次有多份报告**：同一个 batch_no 写多行，每行一个 report_file。同类型的第二份会成为新版本，旧版本自动标记为“已取代”。
- **只登记批次、报告还没出**：report_file 留空，report_pending 填 yes（参考模板第 4 行）。

## 导入命令（由 Claude 执行）

```
wp peptide import batches batches.csv --reports-dir=<报告文件夹> --tag=<标签> --dry-run
wp peptide import batches batches.csv --reports-dir=<报告文件夹> --tag=<标签>
wp peptide import delete --tag=<标签> --yes
```

先用 `--dry-run` 检查，每个有问题的行都会列出原因。只要某个批次的任意一行有错，这个批次就整个不导入，不会出现只导入一半的情况。
