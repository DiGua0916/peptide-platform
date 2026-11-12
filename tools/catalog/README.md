# tools/catalog

商品目录相关的脚本。系列上线前，各店的商品靠这里的一次性脚本逐店批量改；系列上线后只在总后台“系列管理”里改，自动同步到同系列的店。

## 系列（M3）

| 文件 | 用途 |
| --- | --- |
| `series_diff_report.py` | 把各店的系列差异报告（JSON）汇总成一份 Markdown，给平台所有者在首次同步前确认。只读，只用 Python 标准库 |
| `store_products_dump.php` | 在一家**还没部署系列代码**的店上只读导出商品文件（`wp eval-file`），拿回来离线做差异比较。不写数据库，不放进插件目录 |

首次同步前的差异报告有三种做法，结果一样：

1. **总后台**：系列管理 → 从母版店导入商品 → 每家店点“生成差异报告” → 看过后“确认并开启同步”。
2. **命令行**（不经过总后台，适合在脱敏快照上预演）：

   ```
   # 母版店
   wp peptide series export --file=/tmp/series.json
   # 每家店（只比较，不改任何东西）
   wp peptide series diff --file=/tmp/series.json --out=/tmp/diff-s0006.json
   # 汇总成一份报告
   python3 tools/catalog/series_diff_report.py /tmp/diff-*.json > series-diff-report.md
   ```

3. **只读导出商品文件，离线比较**（适合现在的生产店：不用先发布新代码，也不用拉整库快照；在哪里跑由 F1 决定，生产店上跑之前要平台所有者同意）：

   ```
   # 每家店（含母版店）：只读，写入语句应为 0 条，脚本会打印出来
   wp --path=<店铺目录> eval-file store_products_dump.php <本分支的 class-series-sync.php> out/<店铺编号>.json
   # 在任何装了本分支代码的 WordPress 上（沙盒或本机副本），不读那个站点的数据
   wp peptide series diff --file=out/<母版店>.json --store-file=out/<店铺编号>.json --out=diff-<店铺编号>.json
   python3 tools/catalog/series_diff_report.py diff-*.json > series-diff-report.md
   ```

   已部署系列代码的店可以直接 `wp peptide series export --local --file=out/<店铺编号>.json`，文件一样。导出文件里只有商品、图片地址和哈希、运费方案、店名和域名；没有订单、顾客、账号、密钥。**文件里有各店域名，不要提交进仓库。** 详细步骤和通过标准见 `docs/handoffs/m3-m10-sandbox-rehearsal.md` 第 5b 节。

命令行的 `export` 和 `diff` 都是只读的，一条数据库写入都没有。

同步之后要回退：`wp peptide series status` 看有哪些快照，`wp peptide series restore [<ver>]`（`ver` 是同步到的商品版本号或快照编号，不填用最近一份） 恢复（或在总后台系列管理里点“恢复到同步前”）。

## 一次性脚本（历史）

`import-missing-4.php`、`import-missing-from-sheet.php`、`lipo-c-follow-image.php` 是系列上线前按店执行过的导入脚本，留作记录。系列同步开启后，不要再对已开启同步的店运行它们：下一次同步会把改动覆盖回系列的内容。
