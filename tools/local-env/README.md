# 本地测试环境与脱敏数据快照（F1 / M1）

给所有开发会话用。目标：改代码先在自己这里跑通，再交 F1 走发布流程；任何人手里都不出现真实顾客资料和密钥。

## 三个环境各管什么

| 环境 | 数据 | 能测 | 不能测 |
| --- | --- | --- | --- |
| 本地站（本目录脚本） | 演示数据，或脱敏快照（要 MySQL） | 纯逻辑测试、页面、后台、下单到“本地测试付款”、升级和回填脚本 | 真实支付、真实发信、Cloudflare、Nginx 规则 |
| 沙盒（后台机 `/srv/vialcove-sandbox`） | 脱敏快照 | 冒烟测试、`critical-path` 套件、MySQL 行为、性能 | 真实支付、对外发信（被拦截） |
| 试点店（生产） | 真实数据 | 用测试单（收件信息全填“1”）走到付款页 | — 只由 F1 发布，每次都要平台所有者确认 |

## 建本地站

```bash
tools/local-env/setup.sh
```

默认装在 `~/vialcove-local`（仓库之外），SQLite，地址 `http://127.0.0.1:8080`。版本与生产一致（见 `versions.env`）。插件和主题是指向仓库的软链接，改代码即时生效。

- 启动：`~/vialcove-local/start-store`；命令行：`~/vialcove-local/wp-store <命令>`；演示商品：`~/vialcove-local/wp-store peptide demo seed`。
- 总后台站：`ROLE=hq PORT=8081 tools/local-env/setup.sh`。
- 用 MySQL：`DB_ENGINE=mysql DB_NAME=… DB_USER=… DB_PASSWORD=… tools/local-env/setup.sh`（库要先建好）。
- 多个会话同时用：各自设不同的 `VIALCOVE_LOCAL` 和 `PORT`。

保险（`mu-plugins/vialcove-local-safety.php`，只在本地站生效）：

1. 邮件不外发，写进 `~/vialcove-local/logs/mail/*.json`，测试可以直接读。
2. 对外 HTTP 全部拦截并记到 `logs/http-blocked.log`（支付方、总后台、像素、AI 都连不上）。
3. 库里出现不是 `@example.test` 的账号邮箱就整站停用：防止有人把没脱敏的库导进来。

### 没有 PHP 怎么办

脚本需要 PHP 8.1+。当前这台 Mac 没有 PHP、Node、Docker、Homebrew，**脚本在这台机器上还没有实际跑过**（只做了 `bash -n` 和 `php -l`）。两条路：

- 在这台机器装 PHP（需要平台所有者同意安装软件）；
- 纯逻辑测试改到沙盒服务器上跑（开发文档 11.3 的方向：测试统一在沙盒跑，不依赖个人电脑）。

## 脱敏数据快照

脚本：`snapshot/snapshot.py`（单文件、只用标准库）。**拉生产快照前必须先得到平台所有者同意。**

流程（全部在数据库所在的机器上完成，原始数据不离开服务器）：

1. `mysqldump --single-transaction` 把生产库读出来，导进同一台 MySQL 上的临时库 `vcsnap_<库名>_<时间>`。生产库只被读，不锁表。
2. 在临时库上脱敏：
   - 顾客姓名 → `Test` / `Customer<编号>`；邮箱 → `c<加盐哈希>@example.test`（同一个原邮箱得到同一个假邮箱，所以“同一顾客只算一条弃单”这类逻辑还能测；盐只在内存里，用完即弃，反推不回去）；电话 → `+1555…`；街道、城市、邮编换成假的，**国家和州保留**（运费、税、波多黎各规则要用）。
   - 测试单（收件信息全填“1”）原样保留。
   - 订单备注、顾客备注、IP、浏览器标识清空；登录会话、购物会话、任务队列、邮件记录、操作记录、支付交易表清空。
   - 密钥整条删除：支付（Panapay、PayPal、收银台代理、Hub 账户）、邮件（FluentSMTP）、AI、像素与 CAPI、总后台连接密钥、Cloudflare、注册密钥；另外名字里带 secret / token / api key / password 的选项一律删除。
   - 账号密码全部作废（`user_pass = '!'`），本地用 `localadmin` 登录。
3. 泄漏检查：扫描临时库每一个文本列，只要还有不是示例域名的邮箱就**不出文件**，并列出是哪张表哪一列。店铺自己的发件域名用 `--allow-domain` 放行，个别公开地址用 `--allow-email`。
4. 通过后导出 `<库名>-sanitized-<时间>.sql.gz`（权限 0600），删除临时库。
5. 不认识的表直接拒绝导出：新模块加了表，先在 `snapshot.py` 的表清单里登记 keep / truncate / scrub（这一条请各会话在 CONTRACT.md 自己那节写明新表有没有顾客资料，F1 来登记）。

```bash
# 在前台机（店铺库）上，以 root：
sudo python3 snapshot.py export --db vc_s0010 --kind store --out /root/vialcove-snapshots --allow-domain vialcoveplus.com
# 在后台机（总后台库）上：
sudo python3 snapshot.py export --db vialcove_hq --kind hq --out /root/vialcove-snapshots
# 只看泄漏检查结果、不出文件：加 --report-only
```

导入：沙盒由 F1 导入（M1-2）；本地 MySQL 站用 `tools/local-env/import-snapshot.sh <文件> <原站地址>`，没有脱敏标记的文件会被拒绝。

商品图片不在快照里。需要图片时单独同步 `uploads` 里的商品图（公开内容，没有顾客资料）；COA 私有报告不同步。

### 验证状态（如实）

- `python3 tools/local-env/snapshot/test_snapshot.py`：11 项通过。覆盖：2026-11-10 生产库的全部表（店铺 74 张、总后台 23 张，只取了表名）都已登记；未登记的表会拒绝；语句只碰存在且非“保留”的表；泄漏检查规则。
- 2026-11-10 经平台所有者同意，在后台机对沙盒库 `vc_sandbox_store`（66 张表）和 `vc_sandbox_hq` 用 `--report-only` 试跑：全部脱敏语句在 MySQL 8.0.46 上执行成功，临时库用完已删除，没有出文件。第一次试跑查出 1 处遗漏（`wp_usermeta` 里的 `_wc_email_verified_*`），已补规则，复跑两库都干净。
- 同日对真实库正式导出：子店 s0010 和总后台。真实数据又查出 2 处遗漏并补上；导出后在服务器上核对了邮箱、已知顾客姓名、电话、密钥选项，均未命中（详见 `docs/handoffs/m1-release.md`）。
- 姓名和地址没有通用的自动检查，只能靠表清单和抽查；每家店导出前都先跑 `--report-only`。

## 升级和回填脚本怎么验（开发文档 12.2）

1. 导入脱敏快照，记下：订单数、销售额、弃单数、三个固定购物车的总价。
2. 切到新分支，访问一次后台触发升级（或跑回填脚本，先试运行）。
3. 再记一次同样的数字。全部一致，或差异能解释，才交 F1 上生产。

## 本机没有 PHP 时跑纯逻辑测试

`bundle-pure-test.py` 把一个 `*-pure.php` 测试和它 `require` 的类文件拼成一个 PHP 流，可以交给任何有 PHP 的机器从标准输入执行，不在那台机器上写文件：

```bash
python3 tools/local-env/bundle-pure-test.py wp-content/plugins/peptide-hq/tests/m1-monitor-checks-pure.php | ssh <有 PHP 的机器> 'php -n -d extension=mbstring'
```

只适用于 `require dirname( __DIR__ ) . '/includes/….php';` 这种写法的纯逻辑测试。这是临时办法；M1-3 之后测试统一在沙盒跑。
