<?php
/**
 * M11 已购回访（FE-10）和查订单（FE-11）的纯逻辑（不加载 WordPress）：
 * cookie 的签名和有效期、订单状态、运单信息、订单号和邮箱的核对、限流。
 *
 * 运行：php -n wp-content/plugins/peptide-core/tests/m11-buyer-status-pure.php
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
	$inc = dirname( __DIR__ ) . '/includes';
	require $inc . '/class-buyer-status.php';
	require $inc . '/class-order-lookup.php';

	// ---------- cookie ----------
	$secret = 'store-secret';
	$issued = 1800000000;
	$encode = static fn( array $ids, int $at = 1800000000, string $key = 'store-secret' ): string => \PeptideCore\Buyer_Status::encode( $ids, $at, $key );
	$decode = static fn( string $value, int $now = 1800000100, string $key = 'store-secret' ): ?array => \PeptideCore\Buyer_Status::decode( $value, $now, $key );

	$cookie = $encode( array( 5, 9 ) );
	check( 'cookie format', 1 === preg_match( '/^v1\.5-9\.1800000000\.[a-f0-9]{64}$/', $cookie ) );
	check( 'round trip', array( 'orders' => array( 5, 9 ), 'issued' => $issued ) === $decode( $cookie ) );
	check( 'wrong secret', null === $decode( $cookie, 1800000100, 'other-store' ) );
	check( 'changed order number', null === $decode( str_replace( 'v1.5-9.', 'v1.5-10.', $cookie ) ) );
	check( 'changed issue time', null === $decode( str_replace( '.1800000000.', '.1800000001.', $cookie ) ) );
	check( 'changed signature', null === $decode( substr( $cookie, 0, -1 ) . ( 'a' === substr( $cookie, -1 ) ? 'b' : 'a' ) ) );
	check( 'valid on the last second of the year', null !== $decode( $cookie, $issued + \PeptideCore\Buyer_Status::LIFETIME ) );
	check( 'expired after one year', null === $decode( $cookie, $issued + \PeptideCore\Buyer_Status::LIFETIME + 1 ) );
	check( 'issued in the future', null === $decode( $cookie, $issued - 301 ) );
	check( 'small clock difference tolerated', null !== $decode( $cookie, $issued - 60 ) );
	foreach ( array( '', 'v1', 'v1.5.1800000000.abc', 'v2.5.1800000000.' . str_repeat( 'a', 64 ), 'v1.5-.1800000000.' . str_repeat( 'a', 64 ), "v1.5.1800000000." . str_repeat( 'a', 64 ) . "\n", 'v1.<script>.1.' . str_repeat( 'a', 64 ) ) as $junk ) {
		check( 'junk cookie rejected', null === $decode( $junk ) );
	}
	check( 'duplicates and bad ids dropped', 1 === preg_match( '/^v1\.3-7\./', $encode( array( 3, 3, 0, -1, 7 ) ) ) );
	check( 'nothing to remember', '' === $encode( array() ) && '' === $encode( array( 0 ) ) );
	check( 'keeps the latest ten orders', array( 6, 7, 8, 9, 10, 11, 12, 13, 14, 15 ) === $decode( $encode( range( 1, 15 ) ) )['orders'] );
	check( 'more than ten ids is not a valid cookie', null === $decode( 'v1.' . implode( '-', range( 1, 11 ) ) . '.1800000000.' . hash_hmac( 'sha256', 'pc-buyer-v1|' . implode( '-', range( 1, 11 ) ) . '|1800000000', $secret ) ) );

	check( 'new order goes last', array( 1, 3, 2 ) === \PeptideCore\Buyer_Status::merge_ids( array( 1, 2, 3 ), 2 ) );
	check( 'first order', array( 5 ) === \PeptideCore\Buyer_Status::merge_ids( array(), 5 ) );
	check( 'oldest order drops off', range( 2, 11 ) === \PeptideCore\Buyer_Status::merge_ids( range( 1, 10 ), 11 ) );

	// ---------- 订单状态 ----------
	$state = static fn( string $status, bool $paid, bool $shipped, string $refund = '' ): string => \PeptideCore\Buyer_Status::state( $status, $paid, $shipped, $refund )[0];
	check( 'paid, not shipped', 'awaiting_shipment' === $state( 'processing', true, false ) );
	check( 'awaiting label', 'Awaiting shipment' === \PeptideCore\Buyer_Status::state( 'processing', true, false )[1] );
	check( 'shipped', 'shipped' === $state( 'completed', true, true ) && 'Shipped' === \PeptideCore\Buyer_Status::state( 'completed', true, true )[1] );
	check( 'refunded order', 'refunded' === $state( 'refunded', true, true ) );
	check( 'refund marked by HQ', 'refunded' === $state( 'processing', true, false, 'full' ) );
	check( 'partial refund keeps shipping state', 'awaiting_shipment' === $state( 'processing', true, false, 'partial' ) );
	check( 'cancelled', 'cancelled' === $state( 'cancelled', false, false ) && 'cancelled' === $state( 'failed', false, false ) );
	check( 'unpaid', 'unpaid' === $state( 'pending', false, false ) && 'unpaid' === $state( 'on-hold', false, false ) );

	// ---------- 运单 ----------
	$ship  = static fn( $meta, ?array $native, string $status ): array => \PeptideCore\Buyer_Status::shipment_from( $meta, $native, $status );
	$empty = array( 'carrier' => '', 'number' => '', 'url' => '', 'shipped' => false, 'shipped_at' => '' );
	check( 'no shipment yet', $empty === $ship( '', null, 'processing' ) && $empty === $ship( array(), null, 'processing' ) );
	check( 'completed without a tracking number counts as shipped', array_merge( $empty, array( 'shipped' => true ) ) === $ship( '', null, 'completed' ) );
	$meta = array( 'carrier' => 'USPS', 'number' => '9400111899223344556677', 'url' => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=9400111899223344556677', 'shipped_at' => '2026-11-12T10:00:00+00:00' );
	check( 'shipment from order meta', array( 'carrier' => 'USPS', 'number' => '9400111899223344556677', 'url' => $meta['url'], 'shipped' => true, 'shipped_at' => '2026-11-12T10:00:00+00:00' ) === $ship( $meta, null, 'processing' ) );
	check( 'unsafe tracking link dropped', '' === $ship( array_merge( $meta, array( 'url' => 'javascript:alert(1)' ) ), null, 'processing' )['url'] );
	check( 'shipped without a number yet', true === $ship( array( 'shipped_at' => '2026-11-12T10:00:00+00:00' ), null, 'processing' )['shipped'] && '' === $ship( array( 'shipped_at' => '2026-11-12T10:00:00+00:00' ), null, 'processing' )['number'] );
	$native = array( 'status' => 'fulfilled', 'shipments' => array( array( 'provider' => 'ups', 'provider_name' => 'UPS', 'tracking_number' => '1Z999', 'tracking_url' => 'https://www.ups.com/track?tracknum=1Z999', 'shipped_at' => '2026-11-13T00:00:00+00:00', 'items' => array() ) ) );
	check( 'shipment from WooCommerce fulfilment', array( 'carrier' => 'UPS', 'number' => '1Z999', 'url' => 'https://www.ups.com/track?tracknum=1Z999', 'shipped' => true, 'shipped_at' => '2026-11-13T00:00:00+00:00' ) === $ship( '', $native, 'processing' ) );
	check( 'order meta wins over fulfilment', 'USPS' === $ship( $meta, $native, 'processing' )['carrier'] );
	check( 'fulfilment without shipments', $empty === $ship( '', array( 'status' => 'unfulfilled', 'shipments' => array() ), 'processing' ) );

	// ---------- 查订单 ----------
	$number = static fn( string $raw ): int => \PeptideCore\Order_Lookup::clean_order_number( $raw );
	check( 'order number with hash', 1234 === $number( '#1234' ) && 1234 === $number( '  # 1234 ' ) );
	check( 'order number must be digits', 0 === $number( '12a4' ) && 0 === $number( '' ) && 0 === $number( '-5' ) && 0 === $number( '1234567890123' ) && 0 === $number( '12 34' ) );
	$same = static fn( string $a, string $b ): bool => \PeptideCore\Order_Lookup::same_email( $a, $b );
	check( 'email ignores case and spaces', $same( ' Jane@Example.com ', 'jane@example.com' ) );
	check( 'different email', ! $same( 'jane@example.com', 'john@example.com' ) );
	check( 'empty email never matches', ! $same( '', '' ) && ! $same( 'jane@example.com', '' ) );

	// 限流：每分钟 5 次，第 6 次拒绝；一分钟后重新计数。
	$bucket = null;
	$oks    = array();
	for ( $i = 0; $i < 6; $i++ ) {
		[ $ok, $bucket ] = \PeptideCore\Order_Lookup::rate_step( $bucket, 1000 + $i );
		$oks[] = $ok;
	}
	check( 'five attempts allowed, sixth refused', array( true, true, true, true, true, false ) === $oks );
	check( 'refused attempt does not extend the window', array( 'count' => 5, 'reset' => 1060 ) === $bucket );
	check( 'still refused inside the window', false === \PeptideCore\Order_Lookup::rate_step( $bucket, 1059 )[0] );
	[ $ok, $next ] = \PeptideCore\Order_Lookup::rate_step( $bucket, 1060 );
	check( 'allowed again after a minute', true === $ok && array( 'count' => 1, 'reset' => 1120 ) === $next );
	check( 'limit is five per minute', 5 === \PeptideCore\Order_Lookup::LIMIT && 60 === \PeptideCore\Order_Lookup::WINDOW );
	check( 'one message for every mismatch', '' !== \PeptideCore\Order_Lookup::NOT_FOUND && false === stripos( \PeptideCore\Order_Lookup::NOT_FOUND, 'exist' ) );

	echo "PASS {$checks} buyer-status / order-lookup checks\n";
}
