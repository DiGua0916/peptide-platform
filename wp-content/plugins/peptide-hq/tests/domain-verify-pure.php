<?php
/**
 * 2026-11-18：换域名自检（wp pchq-domain verify）。
 *
 * 行为级：真的载入 PeptideHq\Domain_Verify，用内存里的 option 层摆出「换完域名但某一处漏了」
 * 的几种局面，断言 report() 把那一处标成不通过，并且话说得够具体（点名域名/店铺编号）。
 *
 * 覆盖的局面：
 *   1. 全部同步好了 → 全通过；
 *   2. 支付绑定还记着旧域名，但 CDN 表已认新域名 → 不通过，并说明会自动跟上；
 *   3. 支付绑定还记着旧域名，CDN 表也没认 → 不通过，并说明会 503（昨晚那种）；
 *   4. CDN 表里旧域名还挂在这家店名下 → 旧域名残留不通过；
 *   5. 管理地址和顾客地址没分离 → 不通过。
 *
 * 只跑离线部分（不发网络请求）。不碰数据库、不需要 WordPress。
 */
namespace {

	define( 'ABSPATH', __DIR__ . '/' );

	$GLOBALS['opts'] = array();

	function check( string $label, bool $ok ): void {
		global $checks;
		if ( ! $ok ) {
			fwrite( STDERR, "FAIL: $label\n" );
			exit( 1 );
		}
		$checks = ( $checks ?? 0 ) + 1;
	}

	function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
}

namespace PeptideHq {

	final class Site_Urls {
		public static function public_url( array $site ): string { return (string) ( $site['public_url'] ?? '' ); }
		public static function management( array $site ): string { return (string) ( $site['management_url'] ?? '' ); }
	}
	final class Sites {
		public static function all(): array { return array(); }
	}
}

namespace {

	require_once dirname( __DIR__ ) . '/includes/class-domain-verify.php';

	use PeptideHq\Domain_Verify as DV;

	$UUID = 'f4a1c0de-1111-4222-8333-444455556666';
	$ACCT = '98ade197eebaace1ffc52d1ca01bd663';

	$site = array(
		'id'             => 16,
		'uuid'           => $UUID,
		'name'           => '光辉',
		'public_url'     => 'https://www.lumornea.com/',
		'management_url' => 'https://s0008.vialcoveresearch.com/',
		'dual_domain'    => true,
	);

	/** @return array<string,array> 按检查点编号取结果。 */
	$run = function ( array $site ): array {
		$out = array();
		foreach ( DV::report( $site, false ) as $c ) { $out[ $c['key'] ] = $c; }
		return $out;
	};

	$binding = static fn( string $host, string $provider ): array => array( 'site_id' => 16, 'public_host' => $host, 'provider_site' => $provider );

	$setup = function ( array $cdn, array $bind ) use ( $UUID, $ACCT ): void {
		$GLOBALS['opts'] = array(
			'pchq_cdn_domains'                  => $cdn,
			'pc_panapay_provider_site_bindings' => array( 'version' => 1, 'bindings' => array( $ACCT . ':' . $UUID => $bind ) ),
		);
	};

	$new_cdn = array( 'lumornea.com' => array( 'site_id' => 16, 'stage' => 'done', 'account' => 'cfguanghui01' ) );

	// ---------- 1. 全部同步好了 ----------

	$setup( $new_cdn, $binding( 'www.lumornea.com', 'lumornea.com' ) );
	$r = $run( $site );

	check( '店铺登记通过', true === $r['site']['ok'] );
	check( '地址分离通过', true === $r['dual']['ok'] );
	check( 'CDN 域名表通过', true === $r['cdn']['ok'] );
	check( '没有旧域名残留', true === $r['cdn-stale']['ok'] );
	check( '支付绑定通过', true === $r['pay:' . $ACCT]['ok'] );
	check( '支付绑定说清了商户站点', str_contains( $r['pay:' . $ACCT]['detail'], 'lumornea.com' ) );
	check( '全部通过时没有任何不通过项', array() === array_filter( $r, static fn( array $c ): bool => false === $c['ok'] ) );

	// ---------- 2. 绑定没跟上，但 CDN 表已认 → 会自动跟上 ----------

	$setup( $new_cdn, $binding( 'www.vialcoveofficial.com', 'vialcoveofficial.com' ) );
	$r = $run( $site );

	check( '绑定没跟上就不通过', false === $r['pay:' . $ACCT]['ok'] );
	check( '说明里点了旧域名', str_contains( $r['pay:' . $ACCT]['detail'], 'vialcoveofficial.com' ) );
	check( '说明里点了新域名', str_contains( $r['pay:' . $ACCT]['detail'], 'www.lumornea.com' ) );
	check( '说明会自动跟上', str_contains( $r['pay:' . $ACCT]['detail'], '自动跟上' ) );
	check( '这种情况 CDN 那项仍然通过', true === $r['cdn']['ok'] );

	// ---------- 3. 绑定没跟上，CDN 表也没认 → 昨晚那种，会 503 ----------

	$setup( array(), $binding( 'www.vialcoveofficial.com', 'vialcoveofficial.com' ) );
	$r = $run( $site );

	check( 'CDN 没登记时不通过', false === $r['cdn']['ok'] );
	check( 'CDN 不通过时点名了域名', str_contains( $r['cdn']['detail'], 'lumornea.com' ) );
	check( '支付绑定不通过', false === $r['pay:' . $ACCT]['ok'] );
	check( '明说会 503', str_contains( $r['pay:' . $ACCT]['detail'], '503' ) );

	// ---------- 4. 旧域名还挂在这家店名下 ----------

	$setup(
		$new_cdn + array( 'vialcoveofficial.com' => array( 'site_id' => 16, 'stage' => 'error', 'account' => '' ) ),
		$binding( 'www.lumornea.com', 'lumornea.com' )
	);
	$r = $run( $site );

	check( '旧域名残留会被抓出来', false === $r['cdn-stale']['ok'] );
	check( '残留项点名了旧域名', str_contains( $r['cdn-stale']['detail'], 'vialcoveofficial.com' ) );
	check( '残留不影响支付绑定判定', true === $r['pay:' . $ACCT]['ok'] );

	// 别家店的域名不算这家店的残留。
	$setup(
		$new_cdn + array( 'korgavex.com' => array( 'site_id' => 15, 'stage' => 'done', 'account' => 'cflaomao0001' ) ),
		$binding( 'www.lumornea.com', 'lumornea.com' )
	);
	check( '别家店的域名不算残留', true === $run( $site )['cdn-stale']['ok'] );

	// ---------- 5. 结构性问题 ----------

	$setup( $new_cdn, array( 'site_id' => 15, 'public_host' => 'www.lumornea.com', 'provider_site' => 'lumornea.com' ) );
	check( '绑定的 site_id 对不上会被抓出来', false === $run( $site )['pay:' . $ACCT]['ok'] );

	$setup( $new_cdn, $binding( 'www.lumornea.com', 'korgavex.com' ) );
	$r = $run( $site );
	check( '顾客域名不属于商户站点会被抓出来', false === $r['pay:' . $ACCT]['ok'] );
	check( '并说明会 503', str_contains( $r['pay:' . $ACCT]['detail'], '503' ) );

	$setup( $new_cdn, $binding( 'www.lumornea.com', 'lumornea.com' ) );
	$single = $site;
	$single['dual_domain'] = false;
	check( '没分离管理地址会被抓出来', false === $run( $single )['dual']['ok'] );

	// 没有任何绑定记录时不算错，只是标成「不适用」。
	$GLOBALS['opts'] = array( 'pchq_cdn_domains' => $new_cdn );
	$r = $run( $site );
	check( '没有绑定记录时标成不适用', null === $r['pay']['ok'] );
	check( '没有绑定记录时不算不通过', ! isset( $r['pay:' . $ACCT] ) );

	// CAPI 桥装了才查。
	check( '没装 CAPI 桥时不查这一项', ! isset( $r['capi'] ) );
	$GLOBALS['opts']['stealth_capi_auto_synced_domains'] = array( 'www.vialcoveofficial.com' );
	check( 'CAPI 桥还记着旧域名会被抓出来', false === $run( $site )['capi']['ok'] );
	$GLOBALS['opts']['stealth_capi_auto_synced_domains'] = array( 'www.lumornea.com' );
	check( 'CAPI 桥同步了就通过', true === $run( $site )['capi']['ok'] );

	echo "PASS {$checks} 换域名自检检查\n";
}
