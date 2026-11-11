<?php
/**
 * CR 素材归因（打地基）总后台端纯逻辑测试：每日素材上报的校验和店铺端用同一份样例
 * （tests/fixtures/creatives/report-sample.json），每日花费推送的校验，词表初版和店铺端一致。
 * 不需要 WordPress、数据库或网络。运行：php -n cr-hq-contract-pure.php
 */
namespace PeptideHq {
	define( 'ABSPATH', __DIR__ . '/' );
	final class Orders {
		public static function valid_day( string $day ): bool { return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) && checkdate( (int) substr( $day, 5, 2 ), (int) substr( $day, 8, 2 ), (int) substr( $day, 0, 4 ) ); }
	}
}
namespace {
	require dirname( __DIR__ ) . '/includes/class-creatives.php';
	require dirname( __DIR__, 2 ) . '/peptide-core/includes/creatives/class-contract.php';
	use PeptideHq\Creatives as H;
	use PeptideCore\Creatives\Contract as C;

	$checks = 0;
	function check( string $name, bool $ok ): void { ++$GLOBALS['checks']; if ( ! $ok ) { throw new RuntimeException( 'FAIL ' . $name ); } }

	// ---------- 每日素材上报 ----------
	$fx    = json_decode( (string) file_get_contents( dirname( __DIR__, 4 ) . '/tests/fixtures/creatives/report-sample.json' ), true );
	$in    = $fx['input'];
	$body  = array( 'day' => $in['day'], 'tz' => $in['tz'], 'rows' => $in['rows'], 'untagged' => array( 'visitors' => $in['untagged'] ) );
	$clean = H::clean_report( $body );
	$exp   = $fx['expected'];
	check( 'rows match the shared fixture', $exp['rows'] === $clean['rows'] );
	check( 'day, tz, untagged match', $exp['day'] === $clean['day'] && $exp['tz'] === $clean['tz'] && $exp['untagged'] === $clean['untagged'] );
	check( 'bad rows counted', $exp['hq_rejected'] === $clean['rejected'] );

	// 店铺端生成的请求体，总后台原样接受（JSON 来回一次）。
	$store = C::report_payload( $in['day'], $in['tz'], $in['rows'], $in['untagged'] );
	$back  = H::clean_report( json_decode( json_encode( $store ), true ) );
	check( 'store payload accepted unchanged', $store['rows'] === $back['rows'] && 0 === $back['rejected'] && $store['untagged'] === $back['untagged'] );

	check( 'missing day refused', null === H::clean_report( array( 'rows' => array() ) ) );
	check( 'bad day refused', null === H::clean_report( array( 'day' => '11/11/2026', 'rows' => array() ) ) );
	check( 'rows not a list refused', null === H::clean_report( array( 'day' => '2026-11-11', 'rows' => 'x' ) ) );
	check( 'too many rows refused', null === H::clean_report( array( 'day' => '2026-11-11', 'rows' => array_fill( 0, H::MAX_ROWS + 1, array( 'code' => 'C0001' ) ) ) ) );
	check( 'empty day accepted', array() === H::clean_report( array( 'day' => '2026-11-11', 'rows' => array() ) )['rows'] );
	check( 'counts and tiers agree with the store', H::COUNTS === C::COUNTS && H::INTENT_TIERS === C::INTENT_TIERS && H::UNREGISTERED === C::UNREGISTERED && H::REQUIRED === C::REQUIRED_ATTRS && H::MAX_ROWS === C::MAX_ROWS );

	// ---------- 词表初版 ----------
	$hq_vocab    = array_map( static fn( $i ) => array( $i[0], $i[1], $i[2] ), H::DEFAULT_VOCAB );
	$store_vocab = array_map( static fn( $i ) => array( $i['attr'], $i['value'], $i['label'] ), C::DEFAULT_VOCAB );
	check( 'default vocab identical on both sides', $hq_vocab === $store_vocab );

	// ---------- 每日花费推送 ----------
	check( 'spend ok', array( '2026-11-10' => 0, '2026-11-11' => 12345 ) === H::clean_spend( array( 'days' => array( array( 'day' => '2026-11-11', 'spend_cents' => 1 ), array( 'day' => '2026-11-10', 'spend_cents' => 0 ), array( 'day' => '2026-11-11', 'spend_cents' => 12345 ) ) ) ) );
	check( 'spend: negative refused', null === H::clean_spend( array( 'days' => array( array( 'day' => '2026-11-11', 'spend_cents' => -1 ) ) ) ) );
	check( 'spend: string amount refused', null === H::clean_spend( array( 'days' => array( array( 'day' => '2026-11-11', 'spend_cents' => '100' ) ) ) ) );
	check( 'spend: impossible date refused', null === H::clean_spend( array( 'days' => array( array( 'day' => '2026-02-30', 'spend_cents' => 1 ) ) ) ) );
	check( 'spend: empty refused', null === H::clean_spend( array( 'days' => array() ) ) );
	check( 'spend: more than 62 days refused', null === H::clean_spend( array( 'days' => array_fill( 0, 63, array( 'day' => '2026-11-11', 'spend_cents' => 1 ) ) ) ) );

	echo "PASS {$checks} CR HQ contract checks; no WordPress, database, or network.\n";
}
