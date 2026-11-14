<?php
/**
 * 访客字段说人话（Tracking\Plain_Words）。不连数据库：php -n plain-words-pure.php
 * 用的都是线上真实出现过的值（geo_region / timezone / language / isp 取样自 s0007、s0010）。
 */
define( 'ABSPATH', __DIR__ );
require dirname( __DIR__ ) . '/includes/tracking/class-plain-words.php';

use PeptideCore\Tracking\Plain_Words as P;

$passed = 0;
function check( string $name, bool $ok, string $got = '' ): void {
	global $passed;
	if ( ! $ok ) {
		fwrite( STDERR, "FAIL: {$name}" . ( '' !== $got ? " (got: {$got})" : '' ) . "\n" );
		exit( 1 );
	}
	++$passed;
}

// ---------- 人在哪 ----------
check( '国家代码转中文', '美国' === P::country( 'US' ) && '波多黎各' === P::country( 'PR' ) );
check( '不认识的国家代码原样返回', 'ZZ' === P::country( 'zz' ) && '' === P::country( '' ) );
check( '美国的州翻成中文并带州字', '德州' === P::region( 'Texas' ) && '纽约州' === P::region( 'New York' ) && '加州' === P::region( 'California' ) );
check( '华盛顿特区不加州字', '华盛顿特区' === P::region( 'District of Columbia' ) );
check( '不认识的地区原样返回', 'Trujillo Alto' === P::region( 'Trujillo Alto' ) );
// 城市保留英文（发货地址也是英文，不另译），国家和州给中文就够店主判断了。
check( '国家放最前面，店主一眼看出是哪国的', '美国 德州 · Houston' === P::place( 'US', 'Texas', 'Houston' ), P::place( 'US', 'Texas', 'Houston' ) );
check( '波多黎各的市和地区同名时不重复', '波多黎各 · Trujillo Alto' === P::place( 'PR', 'Trujillo Alto', 'Trujillo Alto' ), P::place( 'PR', 'Trujillo Alto', 'Trujillo Alto' ) );
check( '只有国家', '美国' === P::place( 'US', '', '' ) );
check( '什么都没有就返回空', '' === P::place( '', '', '' ) );

// ---------- 用什么网络 ----------
check( '运营商去掉 ASN 尾巴', 'T-Mobile' === P::carrier( 'T-MOBILE-AS21928' ), P::carrier( 'T-MOBILE-AS21928' ) );
check( '运营商去掉编号尾巴', 'Charter' === P::carrier( 'CHARTER-20115' ) && 'Att-Internet4' === P::carrier( 'ATT-INTERNET4' ) );
check( '本来就有大小写的不动', 'M247 Europe SRL' === P::carrier( 'M247 Europe SRL' ) );
check( '空运营商', '' === P::carrier( '' ) && '' === P::carrier( '-' ) );
check( '家庭宽带不算可疑', array( 'Charter · 家庭宽带', false ) === P::network( 'CHARTER-20115', 'residential' ) );
check( '手机网络不算可疑', array( 'T-Mobile · 手机网络', false ) === P::network( 'T-MOBILE-AS21928', 'mobile' ) );
check( '机房 IP 要标出来', array( 'Fdcservers · 机房 / 服务器', true ) === P::network( 'FDCSERVERS', 'hosting' ), implode( '|', array_map( 'strval', P::network( 'FDCSERVERS', 'hosting' ) ) ) );
check( 'VPN 要标出来', array( 'M247 Europe SRL · VPN', true ) === P::network( 'M247 Europe SRL', 'vpn' ) );
check( '没有 IP 类型时只给运营商', array( 'Pandg', false ) === P::network( 'PANDG', '' ) );

// ---------- 语言 ----------
check( '英语美国', '英语（美国）' === P::language( 'en-US' ) );
check( '西语拉美', '西班牙语（拉美）' === P::language( 'es-419' ) );
check( '西语美国', '西班牙语（美国）' === P::language( 'es-US' ) );
check( '只有语言没有地区', '中文' === P::language( 'zh' ) );
check( '不认识的语言标签原样返回', 'xx-YY' === P::language( 'xx-YY' ) && '' === P::language( '' ) );

// ---------- 时区和当地时间 ----------
check( '美国时区说法', '美西时间' === P::zone( 'America/Los_Angeles' ) && '美东时间' === P::zone( 'America/New_York' ) && '波多黎各时间' === P::zone( 'America/Puerto_Rico' ) );
check( '认不出的时区不显示乱码', '' === P::zone( 'Etc/Unknown' ) && '' === P::zone( 'Etc/GMT-8' ) && '' === P::zone( '' ) );
check( '没收录的时区退回城市名', 'Sao Paulo' === P::zone( 'America/Sao_Paulo' ) );
$noon_utc = ( new DateTimeImmutable( '2026-11-14 20:00:00', new DateTimeZone( 'UTC' ) ) )->getTimestamp() * 1000;
check( '客户那边现在几点', '美西时间 12:00（周六）' === P::clock( 'America/Los_Angeles', $noon_utc ), P::clock( 'America/Los_Angeles', $noon_utc ) );
check( '同一时刻美东是另一个点', '美东时间 15:00（周六）' === P::clock( 'America/New_York', $noon_utc ) );
check( '认不出时区就不显示当地时间', '' === P::clock( 'Etc/Unknown', $noon_utc ) && '' === P::clock( '乱码', $noon_utc ) );

echo "PASS {$passed} plain words assertions\n";
