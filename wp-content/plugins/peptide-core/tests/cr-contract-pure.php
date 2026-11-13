<?php
/**
 * CR 素材归因（打地基）店铺端纯逻辑测试：Meta ID、素材编号、广告名拆分与生成、属性校验、来源分类、
 * 内置浏览器识别、每日上报格式（和总后台共用 tests/fixtures/creatives/report-sample.json）。
 * 不需要 WordPress、数据库或网络。运行：php -n cr-contract-pure.php
 */
define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/includes/creatives/class-contract.php';

use PeptideCore\Creatives\Contract as C;

$checks = 0;
function check( string $name, bool $ok ): void { ++$GLOBALS['checks']; if ( ! $ok ) { throw new RuntimeException( 'FAIL ' . $name ); } }

// ---------- ID 和编号 ----------
check( 'meta id: digits', C::valid_meta_id( '120210000000000003' ) );
check( 'meta id: unreplaced macro rejected', ! C::valid_meta_id( '{{ad.id}}' ) );
check( 'meta id: too short rejected', ! C::valid_meta_id( '123' ) );
check( 'code: C + 4 digits', C::valid_code( 'C0012' ) && C::valid_code( 'C12345' ) );
check( 'code: lowercase / short rejected', ! C::valid_code( 'c0012' ) && ! C::valid_code( 'C12' ) && ! C::valid_code( 'UNREG' ) );
check( 'code_for pads to 4', 'C0001' === C::code_for( 1 ) && 'C0012' === C::code_for( 12 ) && 'C12345' === C::code_for( 12345 ) && 'C0001' === C::code_for( 0 ) );

// ---------- 广告名 ----------
$p = C::parse_ad_name( 'C0012_hook-lab_fmt-vid_angle-purity_lang-en_v1' );
check( 'parse: code', 'C0012' === $p['code'] );
check( 'parse: attrs', array( 'hook' => 'lab', 'fmt' => 'vid', 'angle' => 'purity', 'lang' => 'en' ) === $p['attrs'] );
check( 'parse: version', 1 === $p['version'] && array() === $p['custom'] );
$p = C::parse_ad_name( '  c0012_HOOK-Lab__fmt-vid_cta-shopnow_bad token_angle-purity ' );
check( 'parse: case and spaces ignored, custom kept, junk skipped', 'C0012' === $p['code'] && 'lab' === $p['attrs']['hook'] && array( 'cta' => 'shopnow' ) === $p['custom'] && 'purity' === $p['attrs']['angle'] );
$p = C::parse_ad_name( 'hook-lab_fmt-vid_angle-purity' );
check( 'parse: no code -> empty code, attrs still read', '' === $p['code'] && 3 === count( $p['attrs'] ) );
check( 'parse: empty name', array( 'code' => '', 'attrs' => array(), 'custom' => array(), 'version' => 0 ) === C::parse_ad_name( '' ) );
$name = C::build_ad_name( 'C0012', array( 'angle' => 'purity', 'hook' => 'LAB', 'fmt' => 'vid', 'lang' => 'en' ), 2 );
check( 'build: fixed order, lowercase, version', 'C0012_hook-lab_fmt-vid_angle-purity_lang-en_v2' === $name );
check( 'build -> parse round trip', 'C0012' === C::parse_ad_name( $name )['code'] && 'lab' === C::parse_ad_name( $name )['attrs']['hook'] && 2 === C::parse_ad_name( $name )['version'] );
check( 'creative link carries every param', ! array_diff( C::PARAMS, array_map( static fn( $kv ) => explode( '=', $kv )[0], explode( '&', C::creative_query( 'C0012' ) ) ) ) );
check( 'creative link starts with cr', str_starts_with( C::creative_query( 'C0012' ), 'cr=C0012&utm_source=' ) );
check( 'creative link without valid code = template only', C::URL_TEMPLATE === C::creative_query( 'X1' ) );
check( 'code: cr wins over ad name', 'C0007' === C::code_from_params( array( 'cr' => 'c0007', 'ad_name' => 'C0012_hook-lab' ) ) );
check( 'code: ad name when no cr', 'C0012' === C::code_from_params( array( 'ad_name' => 'C0012_hook-lab' ) ) );
check( 'code: bad cr falls back to ad name', 'C0012' === C::code_from_params( array( 'cr' => '{{x}}', 'ad_name' => 'C0012_x' ) ) );
check( 'code: none', '' === C::code_from_params( array( 'cr' => '', 'ad_name' => 'summer sale' ) ) );

// ---------- 属性校验 ----------
$vocab = array_map( static fn( $i ) => array( 'attr' => $i['attr'], 'value' => $i['value'] ), C::DEFAULT_VOCAB );
check( 'attrs ok', array() === C::attr_problems( array( 'hook' => 'lab', 'fmt' => 'vid', 'angle' => 'purity', 'lang' => 'es' ), $vocab ) );
check( 'attrs: missing and unknown reported', array( 'fmt', 'angle', 'lang' ) === C::attr_problems( array( 'hook' => 'lab', 'angle' => 'nope', 'lang' => 'fr' ), $vocab ) );

// ---------- 来源分类 ----------
$paid = array( 'utm_source' => 'ig', 'utm_medium' => 'paid', 'utm_campaign' => '120210000000000001', 'utm_term' => '120210000000000002', 'utm_content' => '120210000000000003' );
check( 'params valid', C::params_valid( $paid ) );
check( 'params: preview macro invalid', ! C::params_valid( array( 'utm_content' => '{{ad.id}}' ) + $paid ) );
check( 'params: unknown platform invalid', ! C::params_valid( array( 'utm_source' => 'facebook' ) + $paid ) );
check( 'source: paid', C::SOURCE_PAID === C::source_class( $paid, false, '', '' ) );
check( 'source: fbclid only -> untagged', C::SOURCE_UNTAGGED === C::source_class( array(), true, '', 'Mozilla/5.0 (iPhone)' ) );
check( 'source: facebook referrer -> untagged', C::SOURCE_UNTAGGED === C::source_class( array(), false, 'l.facebook.com', '' ) );
check( 'source: in-app UA -> untagged', C::SOURCE_UNTAGGED === C::source_class( array(), false, '', 'Mozilla/5.0 [FBAN/FBIOS;FBAV/450.0]' ) );
check( 'source: other utm stays other', C::SOURCE_OTHER === C::source_class( array( 'utm_source' => 'newsletter' ), true, '', '' ) );
check( 'source: google -> other', C::SOURCE_OTHER === C::source_class( array(), false, 'www.google.com', '' ) );
check( 'source: lookalike host is not facebook', C::SOURCE_OTHER === C::source_class( array(), false, 'notfacebook.com', '' ) );
check( 'in_app: instagram', 'ig' === C::in_app( 'Mozilla/5.0 (iPhone) Instagram 300.0' ) );
check( 'in_app: facebook', 'fb' === C::in_app( 'Mozilla/5.0 [FBAN/FBIOS;FBAV/450.0]' ) );
check( 'in_app: safari', '' === C::in_app( 'Mozilla/5.0 (iPhone) Version/18.0 Safari/604.1' ) );

// ---------- 每日上报（和总后台共用样例） ----------
$fx  = json_decode( (string) file_get_contents( dirname( __DIR__, 4 ) . '/tests/fixtures/creatives/report-sample.json' ), true );
$in  = $fx['input'];
$out = C::report_payload( $in['day'], $in['tz'], $in['rows'], $in['untagged'] );
$exp = $fx['expected'];
unset( $exp['hq_rejected'] );
check( 'payload matches the shared fixture', $exp === $out );
check( 'payload is stable through JSON', $out === C::report_payload( $out['day'], $out['tz'], json_decode( json_encode( $out['rows'] ), true ), $out['untagged']['visitors'] ) );
check( 'payload: bad day refused', null === C::report_payload( '2026/11/11', 'UTC', array(), 0 ) );
check( 'payload: too many rows refused', null === C::report_payload( '2026-11-11', 'UTC', array_fill( 0, C::MAX_ROWS + 1, array( 'code' => 'C0001' ) ), 0 ) );
check( 'payload: negative untagged floors at 0', 0 === C::report_payload( '2026-11-11', 'UTC', array(), -4 )['untagged']['visitors'] );
$dup = C::report_payload( '2026-11-11', 'UTC', array( array( 'code' => 'C0001', 'visitors' => 1 ), array( 'code' => 'C0001', 'visitors' => 2 ) ), 0 );
check( 'payload: same code twice keeps the last row', 1 === count( $dup['rows'] ) && 2 === $dup['rows'][0]['visitors'] );

echo "PASS {$checks} CR contract checks; no WordPress, database, or network.\n";
