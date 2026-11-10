<?php
/**
 * M9 开店向导（ST-01）、投手首页（ST-02）、订单列（ST-03）、店铺信息（ST-07）的纯逻辑（不加载 WordPress）。
 *
 * 运行：php -n wp-content/plugins/peptide-core/tests/m9-onboarding-pure.php
 */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	function check( string $label, bool $ok ): void {
		global $checks;
		if ( ! $ok ) {
			fwrite( STDERR, "FAIL: $label\n" );
			exit( 1 );
		}
		$checks = ( $checks ?? 0 ) + 1;
	}
	function sanitize_email( $email ) {
		return trim( (string) $email );
	}
	function is_email( $email ) {
		return 1 === preg_match( '/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i', (string) $email );
	}
	$inc = dirname( __DIR__ ) . '/includes';
	require $inc . '/class-staff-buyer.php';
	require $inc . '/class-support-contacts.php';
	require $inc . '/admin/class-onboarding.php';
	require $inc . '/admin/class-buyer-home.php';
	require $inc . '/admin/class-store-settings.php';
	require $inc . '/admin/class-admin-menus.php';

	// ---------- 向导各步是否完成 ----------
	$facts = array(
		'must_change'     => false,
		'store_name'      => 'VialCove Peptides',
		'whatsapp'        => '15551234567',
		'store_confirmed' => true,
		'pixel_id'        => '1234567890123456',
		'has_token'       => true,
		'test'            => array( 'pixel' => '1234567890123456', 'ok' => true, 'at' => 1 ),
		'link'            => false,
	);
	$eval  = static fn( array $over = array() ): array => \PeptideCore\Admin\Onboarding::evaluate( array_merge( $facts, $over ) );
	$left  = static fn( array $over = array() ): int => \PeptideCore\Admin\Onboarding::remaining( $eval( $over ) );
	$now   = static fn( array $over = array() ): string => \PeptideCore\Admin\Onboarding::current_step( $eval( $over ) );

	check( 'four required steps done', array( 'password' => true, 'store' => true, 'pixel' => true, 'verify' => true, 'link' => false ) === $eval() );
	check( 'complete without the optional link', 0 === $left() && 'link' === $now() );
	check( 'everything done', 'done' === $now( array( 'link' => true ) ) );
	check( 'password first', 'password' === $now( array( 'must_change' => true ) ) && 1 === $left( array( 'must_change' => true ) ) );
	check( 'store needs a name', false === $eval( array( 'store_name' => '' ) )['store'] );
	check( 'store needs WhatsApp', false === $eval( array( 'whatsapp' => '' ) )['store'] );
	check( 'store needs one save', false === $eval( array( 'store_confirmed' => false ) )['store'] && 'store' === $now( array( 'store_confirmed' => false ) ) );
	check( 'pixel needs a token', false === $eval( array( 'has_token' => false ) )['pixel'] );
	check( 'no token means not verified either', false === $eval( array( 'has_token' => false ) )['verify'] && 2 === $left( array( 'has_token' => false ) ) );
	check( 'changed pixel must be verified again', false === $eval( array( 'pixel_id' => '999999' ) )['verify'] && 'verify' === $now( array( 'pixel_id' => '999999' ) ) );
	check( 'failed test is not verified', false === $eval( array( 'test' => array( 'pixel' => '1234567890123456', 'ok' => false ) ) )['verify'] );
	check( 'never tested', false === $eval( array( 'test' => array() ) )['verify'] );
	check( 'fresh store needs all four', 4 === \PeptideCore\Admin\Onboarding::remaining( \PeptideCore\Admin\Onboarding::evaluate( array( 'must_change' => true ) ) ) );
	check( 'five steps, four required', 5 === count( \PeptideCore\Admin\Onboarding::STEPS ) && 4 === count( \PeptideCore\Admin\Onboarding::REQUIRED ) && array_keys( \PeptideCore\Admin\Onboarding::labels() ) === \PeptideCore\Admin\Onboarding::STEPS );

	// ---------- 改密码、像素表单 ----------
	check( 'short password', '' !== \PeptideCore\Admin\Onboarding::password_error( 'abc1234', 'abc1234' ) );
	check( 'passwords differ', '' !== \PeptideCore\Admin\Onboarding::password_error( 'abcd12345', 'abcd12346' ) );
	check( 'password ok', '' === \PeptideCore\Admin\Onboarding::password_error( 'abcd12345', 'abcd12345' ) );
	$token = str_repeat( 'EAAB', 12 );
	check( 'pixel id must be digits', '' !== \PeptideCore\Admin\Onboarding::pixel_error( 'G-ABC123', $token, false ) );
	check( 'pixel id too short', '' !== \PeptideCore\Admin\Onboarding::pixel_error( '1234', $token, false ) );
	check( 'token required the first time', '' !== \PeptideCore\Admin\Onboarding::pixel_error( '1234567890', '', false ) );
	check( 'token may stay when saved', '' === \PeptideCore\Admin\Onboarding::pixel_error( '1234567890', '', true ) );
	check( 'truncated token', '' !== \PeptideCore\Admin\Onboarding::pixel_error( '1234567890', 'EAAB', false ) );
	check( 'token with spaces', '' !== \PeptideCore\Admin\Onboarding::pixel_error( '1234567890', $token . ' ' . $token, false ) );
	check( 'pixel and token ok', '' === \PeptideCore\Admin\Onboarding::pixel_error( '1234567890', $token, false ) );

	// ---------- 主像素写进像素列表 ----------
	$merge = static fn( array $pixels, string $id, string $tok ): array => \PeptideCore\Admin\Onboarding::merge_pixel( $pixels, $id, $tok, 'shop.example' );
	check( 'first pixel', array( array( 'id' => '111', 'note' => '', 'domain' => 'shop.example', 'token' => 'tokA' ) ) === $merge( array(), '111', 'tokA' ) );
	$a = array( 'id' => '111', 'note' => 'main', 'domain' => 'shop.example', 'token' => 'tokA' );
	$c = array( 'id' => '333', 'note' => 'extra', 'domain' => 'shop.example', 'token' => 'tokC' );
	check( 'blank token keeps the saved one', array( $a, $c ) === $merge( array( $a, $c ), '111', '' ) );
	check( 'new token replaces', 'tokNEW' === $merge( array( $a, $c ), '111', 'tokNEW' )[0]['token'] && $c === $merge( array( $a, $c ), '111', 'tokNEW' )[1] );
	check( 'new id without token starts empty', '' === $merge( array( $a ), '222', '' )[0]['token'] && '222' === $merge( array( $a ), '222', '' )[0]['id'] );
	check( 'other pixels are kept', 2 === count( $merge( array( $a, $c ), '222', 'tokB' ) ) && $c === $merge( array( $a, $c ), '222', 'tokB' )[1] );
	check( 'promoting an existing pixel keeps its token and removes the duplicate', array( array( 'id' => '333', 'note' => 'main', 'domain' => 'shop.example', 'token' => 'tokC' ) ) === $merge( array( $a, $c ), '333', '' ) );
	check( 'token is cleaned', 'abcd' === $merge( array(), '111', 'ab c!d' )[0]['token'] );

	// ---------- 投放链接 ----------
	$tail = 'utm_medium=paid&utm_campaign={{campaign.name}}&utm_term={{adset.name}}&utm_content={{ad.name}}';
	check( 'link with Facebook macros', 'https://shop.example/collections/best/?utm_source=facebook&' . $tail === \PeptideCore\Admin\Onboarding::ad_link( 'https://shop.example/collections/best/' ) );
	check( 'instagram source', 'https://shop.example/?utm_source=instagram&' . $tail === \PeptideCore\Admin\Onboarding::ad_link( 'https://shop.example/', '', 'instagram' ) );
	check( 'unknown source falls back', str_contains( \PeptideCore\Admin\Onboarding::ad_link( 'https://shop.example/', '', 'tiktok' ), 'utm_source=facebook&' ) );
	check( 'fixed campaign name is encoded', str_contains( \PeptideCore\Admin\Onboarding::ad_link( 'https://shop.example/', 'BF Sale & More' ), 'utm_campaign=BF%20Sale%20%26%20More&utm_term={{adset.name}}' ) );
	check( 'keeps other parameters, drops old utm and fragment', 'https://shop.example/p/?ref=1&utm_source=facebook&' . $tail === \PeptideCore\Admin\Onboarding::ad_link( 'https://shop.example/p/?ref=1&utm_source=x&utm_campaign=y#top' ) );

	// ---------- 首页 ----------
	check( 'conversion', 0.025 === \PeptideCore\Admin\Buyer_Home::conversion( 5, 200 ) );
	check( 'conversion without visitors', null === \PeptideCore\Admin\Buyer_Home::conversion( 1, 0 ) );
	check( 'five metrics', array( 'visitors', 'orders', 'sales', 'conversion', 'abandoned' ) === array_keys( \PeptideCore\Admin\Buyer_Home::metrics() ) );
	check( 'three ranges', array( 'today', 'yesterday', '7d' ) === array_keys( \PeptideCore\Admin\Buyer_Home::RANGES ) );
	$todos = static fn( int $wizard, bool $pay, ?int $open ): array => \PeptideCore\Admin\Buyer_Home::todos( array( 'wizard_remaining' => $wizard, 'wizard_next' => '广告像素', 'pay_domain_missing' => $pay, 'abandoned_open' => $open ) );
	check( 'no to-dos', array() === $todos( 0, false, 0 ) );
	check( 'wizard to-do', array( 'wizard' ) === array_column( $todos( 2, false, 0 ), 'key' ) && '开店向导还差 2 步，下一步：广告像素' === $todos( 2, false, 0 )[0]['text'] && 'pc-onboarding' === $todos( 2, false, 0 )[0]['page'] );
	check( 'payment domain to-do has no page for the buyer', array( 'pay_domain' ) === array_column( $todos( 0, true, 0 ), 'key' ) && '' === $todos( 0, true, 0 )[0]['page'] );
	check( 'abandoned to-do', '有 3 个弃单还没跟进' === $todos( 0, false, 3 )[0]['text'] && 'pc-recovery' === $todos( 0, false, 3 )[0]['page'] );
	check( 'abandoned count still loading keeps a row', array( 'abandoned' ) === array_column( $todos( 0, false, null ), 'key' ) );
	check( 'all three in order', array( 'wizard', 'pay_domain', 'abandoned' ) === array_column( $todos( 1, true, 5 ), 'key' ) );

	// ---------- 店铺信息 ----------
	$store = static fn( string $name, string $wa, string $email = '' ): array => \PeptideCore\Admin\Store_Settings::clean_buyer_store( array( 'store_name' => $name, 'whatsapp' => $wa, 'email' => $email ) );
	check( 'store info ok', array( array(), array( 'name' => 'VialCove Peptides', 'whatsapp' => '15551234567', 'email' => '' ) ) === $store( ' VialCove   Peptides ', '+1 555 123 4567' ) );
	check( 'support email kept', 'help@shop.example' === $store( 'Shop', '+15551234567', 'help@shop.example' )[1]['email'] );
	check( 'empty name', 1 === count( $store( '', '+15551234567' )[0] ) );
	check( 'name must be English', 1 === count( $store( '多肽商店', '+15551234567' )[0] ) );
	check( 'name too long', 1 === count( $store( str_repeat( 'a', 61 ), '+15551234567' )[0] ) );
	check( 'WhatsApp required', 1 === count( $store( 'Shop', '' )[0] ) );
	check( 'WhatsApp needs country code digits', 1 === count( $store( 'Shop', '12345' )[0] ) );
	check( 'bad email', 1 === count( $store( 'Shop', '+15551234567', 'not-an-email' )[0] ) );
	check( 'several problems at once', 3 === count( $store( '', '', 'x' )[0] ) );

	// ---------- 订单列表的两栏 ----------
	$src = static fn( array $a ): string => \PeptideCore\Admin\Admin_Menus::order_source( $a );
	check( 'ad source with campaign and ad', 'facebook / BF / video1' === $src( array( 'utm_source' => 'facebook', 'utm_campaign' => 'BF', 'utm_content' => 'video1' ) ) );
	check( 'unreplaced macro hidden', 'facebook' === $src( array( 'utm_source' => 'facebook', 'utm_campaign' => '{{campaign.name}}', 'utm_content' => '{{ad.name}}' ) ) );
	check( 'woo placeholders hidden', '搜索引擎' === $src( array( 'utm_source' => '(none)', 'source_type' => 'organic' ) ) );
	check( 'unknown source', '—' === $src( array() ) );
	$refund = static fn( string $state, float $refunded, float $total ): string => \PeptideCore\Admin\Admin_Menus::refund_label( $state, $refunded, $total );
	check( 'HQ full refund', '已全额退款' === $refund( 'full', 0.0, 100.0 ) );
	check( 'HQ partial refund', '部分退款' === $refund( 'partial', 0.0, 100.0 ) );
	check( 'HQ says none', '无' === $refund( 'none', 50.0, 100.0 ) );
	check( 'order fully refunded', '已全额退款' === $refund( '', 100.0, 100.0 ) );
	check( 'order partly refunded', '部分退款' === $refund( '', 30.0, 100.0 ) );
	check( 'no refund', '无' === $refund( '', 0.0, 100.0 ) );

	echo "PASS {$checks} onboarding / home / store-info checks\n";
}
