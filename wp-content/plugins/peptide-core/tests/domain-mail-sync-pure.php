<?php
/**
 * 2026-11-21：换公开域名时店铺三处邮箱跟着换（Domain_Mail_Sync）。
 *
 * 行为级：真的载入 Domain_Mail_Sync 和 Mail_Outbox::brand_for_host，option 用内存替身。覆盖：
 *   1. 发件邮箱：旧域名（含 www. 写法）→ 保留前缀换新域名；空 / 占位 → orders@新域名；外部邮箱不动；已是新域名不动；
 *   2. 发件人名称：旧品牌名（VialCove 规则或域名首段）→ 新域名品牌名；自定义名字不动；
 *   3. 客服邮箱：空的保持空；@旧域名 → @新域名；外部邮箱不动；
 *   4. update_option_home 钩子当场同步，并且第二次跑没有变化（幂等）；
 *   5. 直接改库（钩子没触发）→ 进后台 catch_up 按上次记下的域名补做；第一次运行只记域名不改东西；
 *   6. 对外品牌名还是旧域名名字时只提示不改。
 *
 * 不碰数据库、不需要 WordPress。
 */
namespace {

	define( 'ABSPATH', __DIR__ . '/' );

	$GLOBALS['opts']  = array();
	$GLOBALS['fired'] = array();

	function check( string $label, bool $ok ): void {
		global $checks;
		if ( ! $ok ) {
			fwrite( STDERR, "FAIL: $label\n" );
			exit( 1 );
		}
		$checks = ( $checks ?? 0 ) + 1;
	}

	function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
	function update_option( $k, $v, $autoload = null ) {
		$old = $GLOBALS['opts'][ $k ] ?? false;
		$GLOBALS['opts'][ $k ] = $v;
		if ( 'home' === $k && $old !== $v ) { \PeptideCore\Domain_Mail_Sync::on_home_change( $old, $v ); } // 和 WordPress 的 update_option_home 一样
		return true;
	}
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
	function add_action( ...$a ) {}
	function do_action( $hook, ...$a ) { $GLOBALS['fired'][] = $hook; }
	function wp_specialchars_decode( $s ) { return $s; }
	function vc_brand_name(): string { $b = (string) get_option( 'pc_public_brand', '' ); return '' !== $b ? $b : 'VialCovePeptides'; }
}

namespace PeptideCore {
	final class Settings { public const OPTION = 'peptide_core_settings'; }
	final class Management_Host { public static function public_url(): string { return rtrim( (string) get_option( 'home' ), '/' ); } }
}

namespace PeptideCore\Admin {
	final class Store_Settings { public static function public_brand(): string { return trim( (string) get_option( 'pc_public_brand', '' ) ); } }
}

namespace {

	require_once dirname( __DIR__ ) . '/includes/class-mail-outbox.php';
	require_once dirname( __DIR__ ) . '/includes/class-domain-mail-sync.php';

	use PeptideCore\Domain_Mail_Sync as S;
	use PeptideCore\Mail_Outbox;

	$brand = array( Mail_Outbox::class, 'brand_for_host' );
	$cur   = static fn( string $from, string $name = '', string $support = '' ): array => array( 'from_address' => $from, 'from_name' => $name, 'support_email' => $support );

	// bare()
	check( 'bare: url with www', 'lumornea.com' === S::bare( 'https://www.lumornea.com/' ) );
	check( 'bare: host', 'vialcoveplus.com' === S::bare( 'vialcoveplus.com' ) );
	check( 'bare: ip rejected', '' === S::bare( 'https://10.0.0.1/' ) );

	// brand_for_host
	check( 'brand: main store', 'VialCove' === Mail_Outbox::brand_for_host( 'www.vialcovepeptides.com' ) );
	check( 'brand: vialcove family', 'VialCovePlus' === Mail_Outbox::brand_for_host( 'vialcoveplus.com' ) );

	// 1. 发件邮箱
	$p = S::plan( 'vialcoveplus.com', 'dalhoben.com', $cur( 'orders@vialcoveplus.com' ), $brand );
	check( 'from: old domain → new, prefix kept', array( 'orders@vialcoveplus.com', 'orders@dalhoben.com' ) === $p['changes']['woocommerce_email_from_address'] );
	$p = S::plan( 'vialcoveplus.com', 'dalhoben.com', $cur( 'Support@www.vialcoveplus.com' ), $brand );
	check( 'from: www. form and custom prefix', 'support@dalhoben.com' === $p['changes']['woocommerce_email_from_address'][1] );
	foreach ( array( '', 'admin@example.com', 'test@localhost', 'not-an-email' ) as $ph ) {
		$p = S::plan( 'vialcoveplus.com', 'dalhoben.com', $cur( $ph ), $brand );
		check( 'from: placeholder "' . $ph . '" → orders@new', 'orders@dalhoben.com' === ( $p['changes']['woocommerce_email_from_address'][1] ?? '' ) );
	}
	$p = S::plan( 'vialcoveplus.com', 'dalhoben.com', $cur( 'shop.help@gmail.com' ), $brand );
	check( 'from: external mailbox kept', ! isset( $p['changes']['woocommerce_email_from_address'] ) && isset( $p['kept']['woocommerce_email_from_address'] ) );
	$p = S::plan( 'vialcoveplus.com', 'dalhoben.com', $cur( 'orders@dalhoben.com' ), $brand );
	check( 'from: already new → no change', ! isset( $p['changes']['woocommerce_email_from_address'] ) );
	$p = S::plan( 'vialcoveplus.com', 'vialcoveplus.com', $cur( 'a@example.com' ), $brand );
	check( 'same domain → nothing', array() === $p['changes'] );

	// 2. 发件人名称
	$GLOBALS['opts']['pc_public_brand'] = 'Dalhoben';
	$p = S::plan( 'vialcoveplus.com', 'dalhoben.com', $cur( 'orders@vialcoveplus.com', 'VialCovePlus' ), $brand );
	check( 'name: vialcove brand → new brand', array( 'VialCovePlus', 'Dalhoben' ) === $p['changes']['woocommerce_email_from_name'] );
	$p = S::plan( 'lumornea.com', 'vialcoveprime.com', $cur( 'orders@lumornea.com', 'Lumornea' ), $brand );
	check( 'name: domain-label brand → new brand', array( 'Lumornea', 'VialCovePrime' ) === $p['changes']['woocommerce_email_from_name'] );
	$p = S::plan( 'vialcoveplus.com', 'dalhoben.com', $cur( 'orders@vialcoveplus.com', 'Research Support Desk' ), $brand );
	check( 'name: custom name kept', ! isset( $p['changes']['woocommerce_email_from_name'] ) && isset( $p['kept']['woocommerce_email_from_name'] ) );
	$p = S::plan( 'vialcoveplus.com', 'dalhoben.com', $cur( 'orders@vialcoveplus.com', '' ), $brand );
	check( 'name: empty stays empty', ! isset( $p['changes']['woocommerce_email_from_name'] ) );

	// 3. 客服邮箱
	$p = S::plan( 'vialcoveplus.com', 'dalhoben.com', $cur( 'orders@vialcoveplus.com', '', '' ), $brand );
	check( 'support: empty stays empty', ! isset( $p['changes']['support_email'] ) );
	$p = S::plan( 'vialcoveplus.com', 'dalhoben.com', $cur( 'orders@vialcoveplus.com', '', 'help@vialcoveplus.com' ), $brand );
	check( 'support: old domain → new', array( 'help@vialcoveplus.com', 'help@dalhoben.com' ) === $p['changes']['support_email'] );
	$p = S::plan( 'vialcoveplus.com', 'dalhoben.com', $cur( 'orders@vialcoveplus.com', '', 'desk@gmail.com' ), $brand );
	check( 'support: external kept', ! isset( $p['changes']['support_email'] ) );

	// 4. update_option_home 钩子 + 幂等
	$GLOBALS['opts'] = array(
		'home'                           => 'https://www.vialcoveplus.com',
		'woocommerce_email_from_address' => 'orders@vialcoveplus.com',
		'woocommerce_email_from_name'    => 'VialCovePlus',
		'peptide_core_settings'          => array( 'support' => array( 'enabled' => true, 'whatsapp' => '1303', 'email' => 'help@vialcoveplus.com' ), 'demo_mode' => false ),
		'pc_public_brand'                => 'Dalhoben',
	);
	S::catch_up(); // 第一次：只记下现在的域名
	check( 'first catch_up only records', 'vialcoveplus.com' === get_option( S::STATE )['host'] && 'orders@vialcoveplus.com' === get_option( 'woocommerce_email_from_address' ) );
	update_option( 'home', 'https://www.dalhoben.com' );
	check( 'hook: from address synced', 'orders@dalhoben.com' === get_option( 'woocommerce_email_from_address' ) );
	check( 'hook: from name synced', 'Dalhoben' === get_option( 'woocommerce_email_from_name' ) );
	check( 'hook: support email synced, other support fields kept', 'help@dalhoben.com' === get_option( 'peptide_core_settings' )['support']['email'] && '1303' === get_option( 'peptide_core_settings' )['support']['whatsapp'] && false === get_option( 'peptide_core_settings' )['demo_mode'] );
	check( 'hook: state records new domain', 'dalhoben.com' === get_option( S::STATE )['host'] && 'vialcoveplus.com' === get_option( S::STATE )['from'] );
	check( 'hook: synced action fired', in_array( 'peptide_core_mail_domain_synced', $GLOBALS['fired'], true ) );
	$snap = $GLOBALS['opts'];
	$r    = S::sync( 'vialcoveplus.com' );
	check( 'idempotent: second run has no changes', array() === $r['changes'] );
	unset( $snap[ S::STATE ] );
	$now = $GLOBALS['opts']; unset( $now[ S::STATE ] );
	check( 'idempotent: options unchanged', $snap === $now );
	S::catch_up();
	check( 'catch_up after hook: nothing to do', 'orders@dalhoben.com' === get_option( 'woocommerce_email_from_address' ) );

	// 5. 直接改库（钩子没触发）→ catch_up 补做
	$GLOBALS['opts']['home'] = 'https://www.korgavex.com'; // 绕过 update_option
	$GLOBALS['opts']['pc_public_brand'] = 'Korgavex';
	S::catch_up();
	check( 'catch_up: from address synced', 'orders@korgavex.com' === get_option( 'woocommerce_email_from_address' ) );
	check( 'catch_up: from name synced', 'Korgavex' === get_option( 'woocommerce_email_from_name' ) );
	check( 'catch_up: support synced', 'help@korgavex.com' === get_option( 'peptide_core_settings' )['support']['email'] );

	// 6. dry-run 和品牌提示
	$GLOBALS['opts']['woocommerce_email_from_address'] = 'orders@korgavex.com';
	$GLOBALS['opts']['pc_public_brand'] = 'Korgavex';
	$GLOBALS['opts']['home'] = 'https://www.vialcovenova.com';
	$r = S::sync( '', '', true );
	check( 'dry-run: reports change', 'orders@vialcovenova.com' === $r['changes']['woocommerce_email_from_address'][1] && false === $r['applied'] );
	check( 'dry-run: writes nothing', 'orders@korgavex.com' === get_option( 'woocommerce_email_from_address' ) );
	check( 'brand hint when public brand is the old domain name', str_contains( $r['brand_hint'], 'Korgavex' ) );

	echo "domain-mail-sync-pure: {$checks} checks passed\n";
}
