#!/bin/sh
# 把一个目录里各店只读导出的商品文件（<店铺编号>.json）和主店的那一份逐个比较，汇总成给平台所有者看的报告。
# 只读：不读写任何店铺的数据，只读这些文件；差异文件写在 <目录>/diff/ 里，报告打印到标准输出。
#
#   WP="wp --path=<任何一个装了本分支代码的 WordPress>" tools/catalog/series_diff_all.sh <目录> <主店编号> [--hosts] > 报告.md
#
# 例：WP="wp --path=/srv/sandbox/store" tools/catalog/series_diff_all.sh ~/f3-diff s0001 > series-diff-report.md
# 导出文件怎么来：tools/catalog/store_products_dump.php（见 README）。文件里有各店域名，不要提交进仓库。
set -eu
dir="${1:?用法：series_diff_all.sh <目录> <主店编号> [--hosts]}"
master="${2:?缺少主店编号，例如 s0001}"
hosts="${3:-}"
here="$(cd "$(dirname "$0")" && pwd)"
: "${WP:=wp}"
[ -r "$dir/$master.json" ] || { echo "找不到主店文件：$dir/$master.json" >&2; exit 1; }
mkdir -p "$dir/diff"
rm -f "$dir/diff"/diff-*.json

# 自检：主店和自己比，必须一个差异都没有；否则比较本身有问题，不出报告。
self="$($WP peptide series diff --file="$dir/$master.json" --store-file="$dir/$master.json" --out="$dir/diff/self-$master.json" 2>&1 | grep '一样：' || true)"
echo "自检（主店和自己比）：$self" >&2
case "$self" in
	*"有差异：0；"*"会新建：0；"*"会下架：0"*) ;;
	*) echo "自检没通过，停下。" >&2; exit 1 ;;
esac
rm -f "$dir/diff/self-$master.json"

n=0
for file in "$dir"/*.json; do
	code="$(basename "$file" .json)"
	[ "$code" = "$master" ] && continue
	line="$($WP peptide series diff --file="$dir/$master.json" --store-file="$file" --out="$dir/diff/diff-$code.json" 2>&1 | grep '一样：' || true)"
	[ -s "$dir/diff/diff-$code.json" ] || { echo "$code 比较失败，停下。" >&2; exit 1; }
	echo "$code：$line" >&2
	n=$((n + 1))
done
[ "$n" -gt 0 ] || { echo "目录里除了主店没有别的店铺文件。" >&2; exit 1; }
python3 "$here/series_diff_report.py" --master "$master" $hosts "$dir/diff"/diff-*.json
