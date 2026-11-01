<?php
/** Additional Data report translations; existing catalogue entries remain authoritative. */
defined( 'ABSPATH' ) || exit;
return array(
	'View user rate after exposure' => '曝光后查看用户转化率',
	'Cart user rate after exposure' => '曝光后加购用户转化率',
	'Paid user rate after exposure' => '曝光后付款用户转化率',
	'Exposure metrics use received visibility observations for parent products only. They do not measure complete physical reach; variations have no separate exposure measurement.' => '曝光指标仅使用已接收的父商品可见性观察，不能表示完整实际触达；款式没有独立曝光测量。',
	'Keep at least five selected numeric columns. Existing saved choices are retained.' => '至少保留五个已选数值列；现有保存的选择保持不变。',
	'Browser-reported operating system' => '浏览器报告的操作系统',
	'Screen size (CSS pixels)' => '屏幕尺寸（CSS 像素）',
	'CSS pixels' => 'CSS 像素',
	'Operating system unknown' => '操作系统未知',
	'Operating system source unavailable' => '操作系统来源不可用',
	'Screen size unknown' => '屏幕尺寸未知',
	'Screen size source unavailable' => '屏幕尺寸来源不可用',
	'Groups use saved visit facts. Overall covers all visitors; checkout observations cover all visits.' => '分组使用已保存的访问事实。整体统计全部访客，结账观察统计全部访问。',
	'Normal/abnormal classification is not collected. Country values require a configured source. OS and screen facts are browser-reported, not verified hardware.' => '暂未采集正常／异常分类。国家数值需要配置来源。操作系统和屏幕信息由浏览器报告，不代表已经核验的硬件事实。',
	'Native search terms' => '站内搜索词',
	'Search page views: %n; known: %k; empty: %e; filtered: %f; not observed: %u.' => '搜索结果页浏览：%n；已采集词：%k；空词：%e；已过滤：%f；未采集：%u。',
	'Search terms count accepted search result page views. Reloads and pagination count again; retries do not. Only bounded observed terms are retained; missing historical terms are not reconstructed.' => '搜索词按已接收的搜索结果页浏览事件计次；刷新和分页另计，重试不重复。仅保留符合限制且实际采集到的词，不补造历史搜索词。',
	'Source means saved UTM source; domain means saved entry domain. Visitor groups count distinct visitors per group. Country uses saved observations; favorites are not collected.' => '来源指已保存的 UTM 来源，域名指已保存的入口域名；访客按各分组分别去重，国家使用已保存的观察；暂未采集收藏。',
	'Supported search result pages can additionally report a lowercased search term of up to 80 Unicode characters. Control characters, contact-like delimiters and long digit sequences are filtered before collection; rejected raw terms are not stored. These checks cannot guarantee that every sensitive phrase is removed. Missing historical terms are not reconstructed. Search observations follow the same visit retention, collection opt-out and deletion rules.' => '受支持的搜索结果页还可上报已转为小写、最多 80 个 Unicode 字符的搜索词。采集前过滤控制字符、联系信息类分隔符及长数字串，不保存被拒绝的原始词。这些检查无法保证移除所有敏感短语；不补造历史搜索词。搜索观察遵循相同的访问记录保留、退出采集及删除规则。',
	'This report groups search result pages by path, not by search term. Search terms are available in the operations ranking.' => '本表按路径归组搜索结果页，不按搜索词分组。搜索词可在运营排行中查看。',
);
