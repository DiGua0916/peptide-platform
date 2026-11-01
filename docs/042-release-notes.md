# Data 042 源码增量包

基线为已冻结并在8091实际采用的039包。本包只准备源码，执行器没有切换junction、重启服务、执行WordPress bootstrap/真实数据库/HTTP/browser、修改设置或提交Git。039包全部532份原文件保持原样；包内039发布说明保留为当批历史记录，本说明描述042新增范围。

## 已接受源码

- 037邮件观察修订1：原生Woo支持邮件与营销HTML的独立message事实、受限目录链接、首次图片/链接请求观察、隐私导出/删除及邮件Data展示。邮件打开请求不等于真实阅读，代理或扫描器也可能访问。仅恢复DataPages两处既定参数/标签接缝，不修改SMTP或原有业务邮件开关。
- 邮件观察默认关闭，schema安装和启用仍待根单独采用。没有init自动安装；未采用/关闭时显示真实not_installed/disabled状态，不用0冒充观察结果。本次没有安装表、启用观察或发送外部邮件。
- 038商品曝光producer：正常observer/adapter队列中，按实际商品/款式/页面实例/保存上下文处理至少40%可见且持续500ms的曝光，沿既有UID、tab、ACK/重试与隐私规则。028 receiver已在039；没有公开发射器、商店业务或布局改动，仅原生商品卡新增观察属性。
- 040本地国家provider：只读取已存在且明确配置的本地MMDB，复用Woo附带Reader并验证全球Woo国家代码；没有下载、配置或文件采用。仅将040的geo函数合入038主题inc，完整保留038能力检查与脚本依赖。loopback/private地址仍unknown；没有可用MMDB时不捏造已知国家，也不借账单/配送/语言或不可信转发头推断。

现有039来源、三个域名/国家筛选、五分组、十九排行、CSV、PageConversion及其它源文件保持原始字节；DataPages除两个邮件接缝外可逐字还原039。共享POT/PO/MO完全未再生成；037仅新增其受支持的customer字典文件。

## 本次源码门

037实际原生WP/Woo/PHPMailer文件在无bootstrap/真实DB/网络的已批准内存边界测试63通过；邮件实际JS18通过。038实际JS52与PHP配置50通过。040 provider139通过，真实MMDB测试1项SKIP，因为没有提供或采用可信数据库。合计322 PASS、0 FAIL、1 SKIP。新增/变化生产PHP13份、JS4份语法逐份通过；没有重跑未变化039的522条测试。

这些结果不等于真实站点端到端验收。根之后才可核对完整hash并将既有8091的两个junction从039切至042；Windows PHP已知junction路径缓存按原进程配置处理，不属于应用补丁。根后续还需正常访客source→event→PageConversion，以及另行明确的邮件schema/开关采用和真实UI验证。

本包不重新运行034失败并停止的私有清理helper，不删除历史QA/业务数据，不运行028 native80 fixture。正常/异常分类、意图/收藏/分享/搜索词及真实A/B assignment/exposure/order来源仍不能据此宣称完成。
