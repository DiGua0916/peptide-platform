<?php
/**
 * 2026-11-18：换域名之后支付绑定自己跟上，但只认总后台认过的域名。
 *
 * 行为级：真的载入 VialCove\Panapay\Provider_Bindings，用内存里的 option 层喂各种情况：
 *
 *   1. 新域名在 pchq_cdn_domains 里、site_id 对得上、stage=done  → 自动改绑定，并留审计、开提醒；
 *   2. 没登记 / 还没做完 / 登记在别家店名下                      → 不改，返回 null（调用方照旧 503）；
 *   3. www. 前缀照常处理，商户站点取裸域名；
 *   4. 绑定里的 site_id 和店铺对不上时坚决不修（这是冒充，不是换域名）；
 *   5. 修不了的时候 stuck() 会开一条写清楚怎么办的提醒——昨晚缺的就是这个。
 *
 * 不碰数据库、不需要 WordPress。
 */
namespace {

	define( 'ABSPATH', __DIR__ . '/' );

	$GLOBALS['opts']   = array();
	$GLOBALS['alerts'] = array();

	function check( string $label, bool $ok ): void {
		global $checks;
		if ( ! $ok ) {
			fwrite( STDERR, "FAIL: $label\n" );
			exit( 1 );
		}
		$checks = ( $checks ?? 0 ) + 1;
	}

	function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
	function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
}

namespace PeptideHq {

	final class Alerts {
		public static function raise( int $site_id, string $type, string $ref, string $message ): bool {
			$GLOBALS['alerts'][ $ref ] = $message;
			return true;
		}
		public static function resolve_ref( int $site_id, string $type, string $ref ): void {
			unset( $GLOBALS['alerts'][ $ref ] );
		}
	}
}

namespace {

	require_once dirname( __DIR__ ) . '/includes/class-provider-bindings.php';

	use VialCove\Panapay\Provider_Bindings as PB;

	$UUID = 'f4a1c0de-1111-4222-8333-444455556666';
	$ACCT = '98ade197eebaace1ffc52d1ca01bd663';
	$site = array( 'id' => 16, 'uuid' => $UUID, 'name' => '光辉' );

	/** 把 option 层重置成「绑定还记着旧域名」的状态。 */
	$reset = function ( array $cdn ) use ( $UUID, $ACCT ): array {
		$binding = array( 'site_id' => 16, 'public_host' => 'www.vialcoveofficial.com', 'provider_site' => 'vialcoveofficial.com' );
		$GLOBALS['opts'] = array(
			'pc_panapay_provider_site_bindings' => array( 'version' => 1, 'bindings' => array( $ACCT . ':' . $UUID => $binding ) ),
			'pchq_cdn_domains'                  => $cdn,
		);
		$GLOBALS['alerts'] = array();
		return $binding;
	};

	$done    = array( 'lumornea.com' => array( 'site_id' => 16, 'stage' => 'done', 'account' => 'cfguanghui01' ) );
	$pending = array( 'lumornea.com' => array( 'site_id' => 16, 'stage' => 'dns_ready', 'account' => 'cfguanghui01' ) );
	$other   = array( 'lumornea.com' => array( 'site_id' => 15, 'stage' => 'done', 'account' => 'cflaomao0001' ) );

	// ---------- 0. bare() ----------

	check( 'bare 去掉 www.', 'lumornea.com' === PB::bare( 'www.lumornea.com' ) );
	check( 'bare 保留子域', 's0008.vialcoveresearch.com' === PB::bare( 's0008.vialcoveresearch.com' ) );
	check( 'bare 大写转小写', 'lumornea.com' === PB::bare( 'WWW.Lumornea.COM' ) );
	check( 'bare 拒绝 IP', '' === PB::bare( '34.231.148.231' ) );
	check( 'bare 拒绝空值', '' === PB::bare( '' ) );

	// ---------- 1. 总后台认过的新域名：自动跟上 ----------

	$binding = $reset( $done );
	$healed  = PB::heal( $site, $ACCT, $binding, 'www.lumornea.com' );

	check( '认过的域名能自愈', is_array( $healed ) );
	check( '顾客域名改成了新的', 'www.lumornea.com' === $healed['public_host'] );
	check( '商户站点取的是裸域名', 'lumornea.com' === $healed['provider_site'] );
	check( 'site_id 原样保留', 16 === $healed['site_id'] );

	$stored = $GLOBALS['opts']['pc_panapay_provider_site_bindings']['bindings'][ $ACCT . ':' . $UUID ];
	check( '改动真的落盘了', $stored === $healed );
	check( '落盘的还是三个字段', 3 === count( $stored ) );

	$audit = PB::audit();
	check( '留下了一条审计', 1 === count( $audit ) );
	check( '审计记了从哪个域名来', 'www.vialcoveofficial.com' === $audit[0]['from'] );
	check( '审计记了换到哪个域名', 'www.lumornea.com' === $audit[0]['to'] );
	check( '审计记了店铺编号', 16 === $audit[0]['site_id'] );

	check( '开了一条提醒', isset( $GLOBALS['alerts'][ 'binding-moved:16:' . $ACCT ] ) );
	check( '提醒里点名了新域名', str_contains( $GLOBALS['alerts'][ 'binding-moved:16:' . $ACCT ], 'lumornea.com' ) );
	check( '提醒提到了收单方报备', str_contains( $GLOBALS['alerts'][ 'binding-moved:16:' . $ACCT ], '报备' ) );

	// 第二次进来已经一致了，调用方走的是快路径，不会再调 heal；这里只确认重复调用不会把数据搞坏。
	$again = PB::heal( $site, $ACCT, $stored, 'www.lumornea.com' );
	check( '重复自愈结果一致', $again === $stored );
	check( '重复自愈不会重复堆审计到爆', count( PB::audit() ) <= PB::AUDIT_MAX );

	// ---------- 2. 总后台没认过：不许自己认 ----------

	$binding = $reset( array() );
	check( 'CDN 表里没有这个域名就不自愈', null === PB::heal( $site, $ACCT, $binding, 'www.lumornea.com' ) );
	check( '不自愈时绑定原样不动', $GLOBALS['opts']['pc_panapay_provider_site_bindings']['bindings'][ $ACCT . ':' . $UUID ] === $binding );

	$binding = $reset( $pending );
	check( 'CDN 行还没做完就不自愈', null === PB::heal( $site, $ACCT, $binding, 'www.lumornea.com' ) );

	$binding = $reset( $other );
	check( '域名登记在别家店名下就不自愈', null === PB::heal( $site, $ACCT, $binding, 'www.lumornea.com' ) );
	check( '别家店的域名不会被改进来', 'vialcoveofficial.com' === $GLOBALS['opts']['pc_panapay_provider_site_bindings']['bindings'][ $ACCT . ':' . $UUID ]['provider_site'] );

	// ---------- 3. 冒充：绑定的 site_id 和店铺对不上 ----------

	$reset( $done );
	$foreign = array( 'site_id' => 15, 'public_host' => 'www.vialcoveprime.com', 'provider_site' => 'vialcoveprime.com' );
	check( 'site_id 对不上坚决不修', null === PB::heal( $site, $ACCT, $foreign, 'www.lumornea.com' ) );

	// 店铺报了一个合法但不属于自己的域名。
	$binding = $reset( $other );
	check( '报别家店的域名也不修', null === PB::heal( $site, $ACCT, $binding, 'www.lumornea.com' ) );

	// 没有这条绑定记录时不会凭空造一条。
	$binding = $reset( $done );
	$GLOBALS['opts']['pc_panapay_provider_site_bindings']['bindings'] = array();
	check( '没有绑定记录时不凭空新建', null === PB::heal( $site, $ACCT, $binding, 'www.lumornea.com' ) );
	check( '确实没新建', array() === $GLOBALS['opts']['pc_panapay_provider_site_bindings']['bindings'] );

	// ---------- 4. 乱七八糟的域名 ----------

	foreach ( array( '', '34.231.148.231', 'lumornea', 'lumornea.com:8443', 'www..lumornea.com' ) as $junk ) {
		$binding = $reset( $done );
		check( '不合法的域名不自愈：' . ( '' === $junk ? '（空）' : $junk ), null === PB::heal( $site, $ACCT, $binding, $junk ) );
	}

	// ---------- 5. 修不了的时候要出声 ----------

	$reset( array() );
	PB::stuck( $site, $ACCT, 'www.lumornea.com', 'unauthorized' );
	$msg = $GLOBALS['alerts'][ 'binding-stuck:16:' . $ACCT ] ?? '';
	check( '修不了会开提醒', '' !== $msg );
	check( '提醒点名了店铺', str_contains( $msg, '光辉' ) );
	check( '提醒点名了新域名', str_contains( $msg, 'lumornea.com' ) );
	check( '提醒说了支付已停', str_contains( $msg, 'provider_site_invalid' ) );
	check( '提醒给了自检命令', str_contains( $msg, 'wp pchq-domain verify 16' ) );

	PB::clear( 16, $ACCT );
	check( '恢复之后提醒会被收掉', ! isset( $GLOBALS['alerts'][ 'binding-stuck:16:' . $ACCT ] ) );

	$reset( array() );
	PB::stuck( $site, $ACCT, '', 'structure' );
	check( '结构坏掉也会开提醒', str_contains( $GLOBALS['alerts'][ 'binding-stuck:16:' . $ACCT ] ?? '', '重新绑定' ) );

	echo "PASS {$checks} 支付绑定换域名自愈检查\n";
}
