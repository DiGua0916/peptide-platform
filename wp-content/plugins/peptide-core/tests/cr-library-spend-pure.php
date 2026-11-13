<?php
/**
 * CR 素材归因 线 2（素材库与花费导入）纯逻辑测试：Meta 报表 CSV 解析（中英文表头、BOM、UTF-16、分隔符、
 * 金额格式、缺列、跨天行、合计行、重复行）、推送请求体分批、待推送日期合并、编号序号、属性校验、
 * 状态判断、未登记广告筛选、补登记沿用编号、词表回应校验。
 * 不需要 WordPress、数据库或网络。运行：php -n cr-library-spend-pure.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
$inc = dirname( __DIR__ ) . '/includes/creatives/';
require $inc . 'class-contract.php';
require $inc . 'class-creatives.php';
require $inc . 'class-spend-import.php';
require $inc . 'class-vocab-sync.php';

use PeptideCore\Creatives\Contract as C;
use PeptideCore\Creatives\Creatives as Cr;
use PeptideCore\Creatives\Spend_Import as S;
use PeptideCore\Creatives\Vocab_Sync as V;

$checks = 0;
function check( string $name, bool $ok ): void { ++$GLOBALS['checks']; if ( ! $ok ) { throw new RuntimeException( 'FAIL ' . $name ); } }

// ---------- CSV：英文表头 ----------
$en = "Reporting starts,Reporting ends,Ad name,Ad ID,Day,Amount spent (USD),Impressions,Link clicks\n"
	. "2026-11-09,2026-11-10,C0012_hook-lab_fmt-vid_angle-purity_lang-en_v1,120210000000000001,2026-11-09,\"1,234.56\",\"12,345\",67\n"
	. "2026-11-09,2026-11-10,C0012_hook-lab_fmt-vid_angle-purity_lang-en_v1,120210000000000001,2026-11-10,$12.30,800,\n"
	. "2026-11-09,2026-11-10,\"Ad, with comma\",120210000000000002,2026-11-10,0,0,0\n"
	. ",,,,,1246.86,13145,67\n";
$p = S::parse( $en );
check( 'en: ok', $p['ok'] && '' === $p['error'] );
check( 'en: three ad-day rows', 3 === count( $p['rows'] ) );
$r = $p['rows']['120210000000000001|2026-11-09'];
check( 'en: thousands separator in money', 123456 === $r['spend_cents'] );
check( 'en: thousands separator in counts', 12345 === $r['impressions'] && 67 === $r['link_clicks'] );
check( 'en: dollar sign stripped, empty clicks = 0', 1230 === $p['rows']['120210000000000001|2026-11-10']['spend_cents'] && 0 === $p['rows']['120210000000000001|2026-11-10']['link_clicks'] );
check( 'en: quoted name with comma', 'Ad, with comma' === $p['rows']['120210000000000002|2026-11-10']['ad_name'] );
check( 'en: Day column wins over reporting range', '2026-11-10' === $p['rows']['120210000000000002|2026-11-10']['day'] );
check( 'en: total row skipped without error', 4 === $p['total'] && 1 === $p['skipped'] && array() === $p['errors'] );
check( 'en: days sorted', array( '2026-11-09', '2026-11-10' ) === S::days( $p['rows'] ) );

// ---------- CSV：中文表头 + BOM ----------
$zh = "\xEF\xBB\xBF报告开始日期,报告结束日期,广告名称,广告编号,已花费金额 (USD),展示次数,链接点击量\n"
	. "2026/11/10,2026/11/10,C0003_hook-price_fmt-img_angle-price,120210000000000003,9.99,1000,12\n";
$p = S::parse( $zh );
check( 'zh+BOM: ok', $p['ok'] );
$r = $p['rows']['120210000000000003|2026-11-10'] ?? null;
check( 'zh: reporting start used as day, slashes ok', null !== $r && 999 === $r['spend_cents'] && 1000 === $r['impressions'] && 12 === $r['link_clicks'] );
check( 'zh: full-width brackets in header', S::parse( "天,广告编号,已花费金额（USD）\n2026-11-10,120210000000000003,1\n" )['ok'] );

// ---------- 跨天行 ----------
$p = S::parse( "Reporting starts,Reporting ends,Ad ID,Amount spent (USD)\n2026-11-01,2026-11-07,120210000000000003,50\n" );
check( 'range row refused, no rows left', ! $p['ok'] && 1 === $p['skipped'] && str_contains( $p['errors'][0], '按“天”细分' ) );

// ---------- 缺列 ----------
$p = S::parse( "Ad name,Impressions\nx,1\n" );
check( 'missing columns listed', ! $p['ok'] && 3 === count( $p['missing'] ) && str_contains( $p['error'], '广告编号' ) && str_contains( $p['error'], '已花费金额' ) && str_contains( $p['error'], '日期' ) );
$p = S::parse( "Day,Ad ID,Impressions\n2026-11-10,120210000000000003,1\n" );
check( 'only spend missing', ! $p['ok'] && 1 === count( $p['missing'] ) && str_contains( $p['error'], 'Amount spent' ) );
check( 'empty file', ! S::parse( '' )['ok'] && '文件是空的。' === S::parse( "\xEF\xBB\xBF \n" )['error'] );
$p = S::parse( "Day,Ad ID,Amount spent (EUR)\n2026-11-10,120210000000000003,1\n" );
check( 'non-USD refused', ! $p['ok'] && str_contains( $p['error'], 'EUR' ) );
$p = S::parse( "Day,Ad ID,Amount spent (USD)\n2026-11-10,120210000000000003,1\n" );
check( 'no ad name -> warning only', $p['ok'] && 1 === count( $p['warnings'] ) );

// ---------- 重复行、坏行 ----------
$p = S::parse( "Day,Ad ID,Ad name,Amount spent (USD)\n2026-11-10,120210000000000003,a,1.00\n2026-11-10,120210000000000003,a,2.50\n2026-11-11,{{ad.id}},b,1\n2026-13-01,120210000000000003,a,1\n2026-11-11,120210000000000003,a,abc\n" );
check( 'duplicate (ad, day): last wins', 1 === count( $p['rows'] ) && 250 === $p['rows']['120210000000000003|2026-11-10']['spend_cents'] && 1 === $p['duplicates'] );
check( 'bad rows reported with line numbers', 3 === count( $p['errors'] ) && str_starts_with( $p['errors'][0], '第 4 行' ) && str_starts_with( $p['errors'][1], '第 5 行' ) && str_starts_with( $p['errors'][2], '第 6 行' ) );
check( 'duplicate warning', (bool) array_filter( $p['warnings'], static fn( $w ) => str_contains( $w, '重复' ) ) );

// ---------- 分隔符、编码、换行 ----------
$tab = "Day\tAd ID\tAmount spent (USD)\r\n2026-11-10\t120210000000000003\t3.00\r\n";
check( 'tab separated + CRLF', 300 === ( S::parse( $tab )['rows']['120210000000000003|2026-11-10']['spend_cents'] ?? 0 ) );
$utf16 = "\xFF\xFE" . mb_convert_encoding( "天\t广告编号\t已花费金额 (USD)\n2026-11-10\t120210000000000003\t4.5\n", 'UTF-16LE', 'UTF-8' );
check( 'UTF-16LE with BOM', 450 === ( S::parse( $utf16 )['rows']['120210000000000003|2026-11-10']['spend_cents'] ?? 0 ) );
check( 'semicolon separated', S::parse( "Day;Ad ID;Amount spent (USD)\n2026-11-10;120210000000000003;1\n" )['ok'] );
check( 'quoted multiline name', 'a' . "\n" . 'b' === ( S::parse( "Day,Ad ID,Ad name,Amount spent (USD)\n2026-11-10,120210000000000003,\"a\nb\",1\n" )['rows']['120210000000000003|2026-11-10']['ad_name'] ?? '' ) );
check( 'header case and spaces', S::parse( "DAY , AD ID,amount SPENT ( usd )\n2026-11-10,120210000000000003,1\n" )['ok'] );

// ---------- 金额和日期 ----------
check( 'money formats', 123456 === S::money_cents( 'US$1,234.56' ) && 1999 === S::money_cents( ' $19.99 ' ) && 0 === S::money_cents( '' ) && 0 === S::money_cents( '-' ) && 1 === S::money_cents( '0.005' ) );
check( 'money: float rounding', 1999 === S::money_cents( '19.99' ) && 29 === S::money_cents( '0.29' ) );
check( 'money: junk and negative', null === S::money_cents( '12abc' ) && 0 === S::money_cents( '-5' ) );
check( 'counts', 12345 === S::count_value( '12,345' ) && null === S::count_value( '1.5' ) && 7 === S::count_value( '7.0' ) );
check( 'dates', '2026-11-10' === S::parse_day( '2026-11-10' ) && '2026-11-09' === S::parse_day( '11/9/2026' ) && '2026-01-05' === S::parse_day( '2026年1月5日' ) && '2026-11-10' === S::parse_day( '2026.11.10' ) );
check( 'bad dates', '' === S::parse_day( '2026-02-30' ) && '' === S::parse_day( 'yesterday' ) );

// ---------- 新出现的广告 ----------
$ads = S::ads_in( S::parse( $en )['rows'] );
check( 'ads_in: code from ad name', 'C0012' === $ads['120210000000000001']['code'] && '' === $ads['120210000000000002']['code'] );

// ---------- 推送请求体 ----------
$pl = S::hq_payloads( array( '2026-11-10' => 1246, '2026-11-09' => 123456, 'bad' => 5, '2026-11-08' => -3 ) );
check( 'payload: sorted, clean, negative -> 0', array( array( 'days' => array( array( 'day' => '2026-11-08', 'spend_cents' => 0 ), array( 'day' => '2026-11-09', 'spend_cents' => 123456 ), array( 'day' => '2026-11-10', 'spend_cents' => 1246 ) ) ) ) === $pl );
$many = array();
for ( $i = 0; $i < 70; $i++ ) {
	$many[ gmdate( 'Y-m-d', strtotime( '2026-01-01 UTC' ) + $i * 86400 ) ] = $i;
}
$pl = S::hq_payloads( $many );
check( 'payload: chunks of 62 days', 2 === count( $pl ) && 62 === count( $pl[0]['days'] ) && 8 === count( $pl[1]['days'] ) );
check( 'payload: empty -> nothing to send', array() === S::hq_payloads( array() ) );
check( 'pending merge', array( '2026-11-08', '2026-11-09', '2026-11-10' ) === S::merge_pending( array( '2026-11-10', 'x' ), array( '2026-11-08', '2026-11-10', '2026-11-09' ) ) );
check( 'pending cap', S::PENDING_MAX === count( S::merge_pending( array(), array_map( static fn( $i ) => gmdate( 'Y-m-d', $i * 86400 ), range( 1, S::PENDING_MAX + 20 ) ) ) ) );

// ---------- 编号、属性、状态 ----------
check( 'next number: empty', 1 === Cr::next_number( array() ) );
check( 'next number: max + 1, gaps and junk ignored', 13 === Cr::next_number( array( 'C0001', 'C0012', 'UNREG', 'X9', 'C0005' ) ) );
check( 'next number -> code', 'C0013' === C::code_for( Cr::next_number( array( 'C0012' ) ) ) );
$vocab = array_map( static fn( $i ) => $i + array( 'required' => true ), C::DEFAULT_VOCAB );
$a     = Cr::clean_attrs( array( 'hook' => ' LAB ', 'fmt' => 'vid', 'angle' => 'purity', 'lang' => '', 'evil' => 'x' ), $vocab );
check( 'clean attrs: lowercased, empty lang dropped, unknown key dropped', array( 'hook' => 'lab', 'fmt' => 'vid', 'angle' => 'purity' ) === $a['attrs'] && array() === $a['problems'] );
check( 'clean attrs: problems', array( 'fmt', 'lang' ) === Cr::clean_attrs( array( 'hook' => 'lab', 'angle' => 'trust', 'lang' => 'fr' ), $vocab )['problems'] );
check( 'label map', '检测报告' === Cr::label_map( $vocab )['hook']['lab'] );
check( 'status: spend today/2 days ago = live', 'live' === Cr::status_for( '2026-11-11', '2026-11-11' ) && 'live' === Cr::status_for( '2026-11-09', '2026-11-11' ) );
check( 'status: 3 days ago = stopped', 'stopped' === Cr::status_for( '2026-11-08', '2026-11-11' ) && 'stopped' === Cr::status_for( '', '2026-11-11' ) );
check( 'display: no spend yet, no visitors = new', 'new' === Cr::display_state( array( 'last_day' => '', 'visitors' => 0 ), '2026-11-11' ) );
check( 'display: no spend yet, has visitors = pending', 'pending' === Cr::display_state( array( 'last_day' => '', 'visitors' => 3 ), '2026-11-11' ) );
check( 'display: with spend follows status_for', 'live' === Cr::display_state( array( 'last_day' => '2026-11-11', 'visitors' => 0 ), '2026-11-11' ) && 'stopped' === Cr::display_state( array( 'last_day' => '2026-11-01', 'visitors' => 9 ), '2026-11-11' ) );
check( 'money', '$1,234.56' === Cr::money( 123456 ) && '$0.00' === Cr::money( 0 ) );

// ---------- 未登记、补登记 ----------
$ads = array(
	array( 'ad_id' => '1', 'code' => '' ),
	array( 'ad_id' => '2', 'code' => 'C0001' ),
	array( 'ad_id' => '3', 'code' => 'C0099' ),
);
check( 'unregistered: empty or unknown code', array( '1', '3' ) === array_column( Cr::unregistered( $ads, array( 'C0001' ) ), 'ad_id' ) );
check( 'relink: keep code from ad when free', 'C0099' === Cr::code_from_ad( 'C0099', '', array( 'C0001' ) ) );
check( 'relink: code read from name', 'C0007' === Cr::code_from_ad( '', 'c0007_hook-lab', array() ) );
check( 'relink: taken code -> allocate new', '' === Cr::code_from_ad( 'C0001', 'C0001_hook-lab', array( 'C0001' ) ) );
check( 'relink: no code', '' === Cr::code_from_ad( '', 'summer sale', array() ) );

// ---------- 词表 ----------
$remote = array(
	'ver'   => 3,
	'items' => array(
		array( 'attr' => 'hook', 'value' => 'lab', 'label' => '<b>检测</b>报告', 'required' => true, 'position' => 0 ),
		array( 'attr' => 'fmt', 'value' => 'vid', 'label' => '视频', 'required' => true, 'position' => 1 ),
		array( 'attr' => 'angle', 'value' => 'purity', 'label' => '纯度', 'required' => true, 'position' => 2 ),
		array( 'attr' => 'lang', 'value' => 'de', 'label' => '德文', 'required' => false, 'position' => 3 ),
		array( 'attr' => 'Bad Attr', 'value' => 'x' ),
		'junk',
	),
);
$v = V::clean_remote( $remote );
check( 'vocab: valid items kept, junk dropped', null !== $v && 3 === $v['ver'] && 4 === count( $v['items'] ) );
check( 'vocab: label tags stripped', '检测报告' === $v['items'][0]['label'] && 1 === $v['items'][0]['required'] && 0 === $v['items'][3]['required'] );
check( 'vocab: missing required attr refused', null === V::clean_remote( array( 'ver' => 4, 'items' => array_slice( $remote['items'], 0, 2 ) ) ) );
check( 'vocab: bad ver refused', null === V::clean_remote( array( 'ver' => '3', 'items' => $remote['items'] ) ) && null === V::clean_remote( array( 'ver' => 0, 'items' => $remote['items'] ) ) && null === V::clean_remote( 'x' ) );
check( 'vocab: newer only', V::newer( 2, 1 ) && ! V::newer( 1, 1 ) && ! V::newer( 1, 2 ) );
check( 'vocab: due after 24h', V::due( 0, 100 ) && ! V::due( 1000, 1000 + 3600 ) && V::due( 1000, 1000 + 86400 ) );

echo "PASS {$checks} CR line 2 checks (CSV, payloads, numbering, relink, vocab); no WordPress, database, or network.\n";
