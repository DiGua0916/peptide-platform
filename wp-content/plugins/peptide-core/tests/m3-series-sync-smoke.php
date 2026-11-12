<?php
/**
 * M3 系列同步（HQ-05、FE-07）冒烟测试：在装了 WooCommerce 的店铺里真正跑一遍
 * 导出 → 差异报告 → 同步 → 重复同步 → 改价 / 新增 / 移出 → 恢复快照 → 母版店只收价格。
 *
 * 会改商品、运费和系列状态，结束时用快照恢复。只能在一次性的本地副本上跑：
 *   PC_SMOKE_THROWAWAY=1 wp eval-file wp-content/plugins/peptide-core/tests/m3-series-sync-smoke.php
 * 不发任何网络请求（图片都是本店已有的）。
 */

use PeptideCore\Series_Sync as S;
use PeptideCore\Shipping\Plans;

if ( 'local' !== wp_get_environment_type() || '1' !== getenv( 'PC_SMOKE_THROWAWAY' ) ) {
	WP_CLI::error( '这个测试会改商品和运费，只能在一次性的本地副本上跑（WP_ENVIRONMENT_TYPE=local 并设 PC_SMOKE_THROWAWAY=1）。' );
}

$checks = 0;
$check  = static function ( string $name, bool $ok, $detail = null ) use ( &$checks ): void {
	++$checks;
	if ( ! $ok ) {
		WP_CLI::error( 'FAIL ' . $name . ( null !== $detail ? ' — ' . wp_json_encode( $detail, JSON_UNESCAPED_UNICODE ) : '' ) );
	}
};
// 本模块代码里的任何警告都算失败。
set_error_handler(
	static function ( int $no, string $msg, string $file, int $line ): bool {
		if ( str_contains( $file, 'class-series-sync.php' ) ) {
			throw new ErrorException( $msg . ' @' . basename( $file ) . ':' . $line, 0, $no, $file, $line );
		}
		return false;
	}
);

$published = static fn(): int => count( wc_get_products( array( 'status' => 'publish', 'type' => array( 'simple', 'variable' ), 'limit' => -1, 'return' => 'ids' ) ) );
$price_of  = static function ( array $p ): array {
	// 第一个款式（或简单商品自己）现在在 WooCommerce 里的价格。
	$local = null;
	foreach ( S::export_local() as $l ) {
		if ( $l['sku'] === $p['sku'] || $l['series_sku'] === $p['sku'] ) {
			$local = $l;
		}
	}
	if ( ! $local ) {
		return array( -1, -1 );
	}
	return 'variable' === $local['type'] ? array( $local['variations'][0]['price_cents'], $local['variations'][0]['compare_cents'] ) : array( $local['price_cents'], $local['compare_cents'] );
};

delete_option( S::OPTION );
delete_option( S::SNAPSHOTS );
$plans_before = get_option( Plans::OPTION, array() );
$count_before = $published();

// ---- 1. 导出：每个已发布的商品都导得出来，而且通过店铺端的校验 ----
$export = S::export_catalog();
$check( 'export lists every published product', count( $export['products'] ) === $count_before && $count_before > 0, array( count( $export['products'] ), $count_before ) );
$check( 'export is valid JSON and a valid catalog', is_string( wp_json_encode( $export ) ) && is_array( S::clean_catalog( array( 'ver' => 1, 'source_host' => $export['source_host'], 'products' => $export['products'] ) ) ) );
$products = json_decode( (string) wp_json_encode( $export['products'] ), true ); // 和总后台转一手之后的样子一致。
$by_key   = array_column( S::export_local(), 'id', 'sku' );
$check( 'series product keys resolve to the local product ids', count( $products ) === count( array_filter( $products, static fn( $p ) => (int) ( $by_key[ $p['sku'] ] ?? -1 ) === S::find_product( $p['sku'] ) ) ) && 0 === S::find_product( 'NO-SUCH-KEY' ) );

$head    = S::clean_header( array( 'series_id' => 7, 'slug' => 'series-7', 'name' => 'Smoke series' ) );
$catalog = static fn( int $ver, array $list ): array => array( 'ver' => $ver, 'source_host' => $export['source_host'], 'products' => $list );
$ship    = array(
	'ver'   => 1,
	'plans' => array(
		array(
			'name'    => 'Standard shipping',
			'regions' => array( array( 'country' => 'US', 'states' => array() ) ),
			'rates'   => array(
				array( 'name' => 'Standard Shipping', 'rule' => 'subtotal', 'min' => 0, 'max' => 1000, 'fee_cents' => 4500 ),
				array( 'name' => 'Standard Shipping', 'rule' => 'subtotal', 'min' => 1000, 'max' => null, 'fee_cents' => 1500 ),
			),
		),
	),
	'rules' => array( 'combine' => 'sum', 'sort' => 'price' ),
);

// ---- 2. 差异报告：拿自己导出的目录和自己比，应该完全一样；试运行什么都不写 ----
$dry = S::sync( $head, array( 'dry_run' => true, 'catalog' => $catalog( 1, $products ), 'shipping' => $ship ), true );
$check( 'dry run against own export shows no differences', is_array( $dry ) && array( 'same' => $count_before, 'changed' => 0, 'create' => 0, 'unpublish' => 0 ) === $dry['diff']['summary'], is_array( $dry ) ? $dry['diff'] : $dry->get_error_message() );
$check( 'dry run wrote nothing', 0 === S::series_id() && array() === S::snapshots() && $plans_before === get_option( Plans::OPTION, array() ) && ! S::export_local()[0]['managed'] );

// ---- 3. 第一次同步：内容没变，只是标记为系列管理、写入运费两档 ----
$r = S::sync( $head, array( 'catalog' => $catalog( 1, $products ), 'shipping' => $ship ), false );
$check( 'first sync completes', is_array( $r ) && true === $r['complete'], is_array( $r ) ? $r : $r->get_error_message() );
$check( 'first sync changes no product content', 0 === $r['applied']['catalog']['created'] && 0 === $r['applied']['catalog']['updated'] && 0 === $r['applied']['catalog']['unpublished'] && $count_before === $r['applied']['catalog']['same'] && array() === $r['applied']['catalog']['errors'], $r['applied'] );
$check( 'state recorded', 7 === S::series_id() && 1 === S::state()['catalog_ver'] && 1 === S::state()['shipping_ver'] && S::manages( 'catalog' ) && S::manages( 'shipping' ) );
$check( 'every product is marked managed', count( array_filter( S::export_local(), static fn( $l ) => $l['managed'] && '' !== $l['series_sku'] ) ) === count( S::export_local() ) );
$general = array_values( array_filter( Plans::all(), static fn( $p ) => 'general' === $p['type'] ) );
$check( 'two shipping tiers written', 1 === count( $general ) && 2 === count( $general[0]['rates'] ) && 45.0 === (float) $general[0]['rates'][0]['fee'] && 15.0 === (float) $general[0]['rates'][1]['fee'] && 1000.0 === (float) $general[0]['rates'][0]['max'] && null === $general[0]['rates'][1]['max'], $general );
$check( 'one snapshot taken before the first change', 1 === count( S::snapshots() ) && 0 === S::snapshots()[0]['from']['catalog_ver'] && 1 === S::snapshots()[0]['to']['catalog_ver'] );
$first_id = (int) S::export_local()[0]['id'];
// 只读保护看的就是这两样；命令行脚本不受保护限制，所以这里 is_locked() 是 false。
$check( 'managed mark and catalog takeover are in place; WP-CLI stays exempt from the lock', '1' === (string) get_post_meta( $first_id, S::PRODUCT_MANAGED, true ) && S::manages( 'catalog' ) && ! S::is_locked( $first_id ) );

// ---- 4. 同一个请求再发一遍：全部跳过 ----
$state = S::state();
$again = S::sync( $head, array( 'catalog' => $catalog( 1, $products ), 'shipping' => $ship ), false );
$check( 'same request again is skipped', 'skipped' === $again['applied']['catalog'] && 'skipped' === $again['applied']['shipping'] && 1 === count( S::snapshots() ) && array_diff_key( $state, array( 'synced_at' => 1 ) ) === array_diff_key( S::state(), array( 'synced_at' => 1 ) ) );

// ---- 5. 第 2 版：改一个价格、新增一个商品、移出一个商品 ----
$v2                 = $products;
$removed            = array_pop( $v2 );
$target             = $v2[0];
[ $old_p, $old_c ]  = $price_of( $target );
$new_price          = $old_p + 123;
if ( 'variable' === $target['type'] ) {
	$v2[0]['variations'][0]['price_cents']   = $new_price;
	$v2[0]['variations'][0]['compare_cents'] = $new_price + 1000;
} else {
	$v2[0]['price_cents']   = $new_price;
	$v2[0]['compare_cents'] = $new_price + 1000;
}
$extra            = $target;
$extra['sku']     = 'SMOKE-NEW-1';
$extra['wc_sku']  = '';
$extra['name']    = 'Smoke new product & more';
$extra['slug']    = 'smoke-new-product';
$extra['images']  = array();
$extra['meta']    = array( '_pc_spu' => 'SMOKE-NEW-1' );
foreach ( $extra['variations'] as $i => $v ) {
	$extra['variations'][ $i ]['sku']   = '';
	$extra['variations'][ $i ]['key']   = 'SMOKE-NEW-1-' . $i;
	$extra['variations'][ $i ]['image'] = null;
}
$v2[] = $extra;

$dry2 = S::sync( $head, array( 'dry_run' => true, 'catalog' => $catalog( 2, $v2 ) ), true );
$check( 'diff shows exactly one change, one new, one to unpublish', array( 'same' => $count_before - 2, 'changed' => 1, 'create' => 1, 'unpublish' => 1 ) === $dry2['diff']['summary'], $dry2['diff'] );
$check( 'the change is the price only', array( 'compare_price', 'price' ) === array_values( array_unique( array_map( static fn( $f ) => $f['field'], $dry2['diff']['changed'][0]['fields'] ) ) ) || array( 'price', 'compare_price' ) === array_values( array_unique( array_map( static fn( $f ) => $f['field'], $dry2['diff']['changed'][0]['fields'] ) ) ), $dry2['diff']['changed'][0]['fields'] );
$check( 'dry run left the price alone', array( $old_p, $old_c ) === $price_of( $target ) );

$r2 = S::sync( $head, array( 'catalog' => $catalog( 2, $v2 ) ), false );
$check( 'second version applied', is_array( $r2 ) && true === $r2['complete'] && 1 === $r2['applied']['catalog']['created'] && 1 === $r2['applied']['catalog']['updated'] && 1 === $r2['applied']['catalog']['unpublished'] && array() === $r2['applied']['catalog']['errors'], is_array( $r2 ) ? $r2['applied'] : $r2->get_error_message() );
$check( 'price changed in WooCommerce', array( $new_price, $new_price + 1000 ) === $price_of( $target ), $price_of( $target ) );
$new_id = 0;
foreach ( S::export_local() as $l ) {
	if ( 'SMOKE-NEW-1' === $l['series_sku'] ) {
		$new_id = (int) $l['id'];
		$check( 'new product created as sent (name not altered by content filters)', 'publish' === $l['status'] && 'Smoke new product & more' === $l['name'] && count( $extra['variations'] ) === count( $l['variations'] ), $l['name'] );
	}
	if ( $l['sku'] === $removed['sku'] || $l['series_sku'] === $removed['sku'] ) {
		$check( 'product not in the series is now a draft, not deleted', 'draft' === $l['status'] );
	}
}
$check( 'new product exists', $new_id > 0 );
$check( 'published count unchanged (one in, one out)', $count_before === $published() );
$check( 'two snapshots', 2 === count( S::snapshots() ) && 2 === S::state()['catalog_ver'] );

// ---- 6. 写入之后再比一遍：应该完全一样（导出、写入、再导出是稳定的） ----
$dry3 = S::sync( $head, array( 'dry_run' => true, 'catalog' => $catalog( 2, $v2 ) ), true );
$check( 'after the sync the store matches the series exactly', 0 === $dry3['diff']['summary']['changed'] && 0 === $dry3['diff']['summary']['create'] && 0 === $dry3['diff']['summary']['unpublish'], $dry3['diff']['changed'] );
$again2 = S::sync( $head, array( 'catalog' => $catalog( 2, $v2 ) ), false );
$check( 'version 2 again is skipped', 'skipped' === $again2['applied']['catalog'] && 2 === count( S::snapshots() ) );

// ---- 7. 恢复到第 2 版之前：价格回来，新建的变草稿，移出的重新上架 ----
$back = S::restore( 2 );
$check( 'restore before version 2', is_array( $back ) && 0 === $back['counts']['failed'] && 1 === $back['counts']['unpublished'], is_array( $back ) ? $back : $back->get_error_message() );
$check( 'price restored', array( $old_p, $old_c ) === $price_of( $target ), $price_of( $target ) );
$check( 'state rolled back to version 1 but still in the series', 1 === S::state()['catalog_ver'] && 7 === S::series_id() );
$check( 'published products are the original ones', $count_before === $published() && 'draft' === get_post_status( $new_id ) );

// ---- 8. 恢复到第 1 版之前：不再是系列管理，运费回到原样 ----
$back1 = S::restore( 1 );
$check( 'restore before version 1', is_array( $back1 ) && 0 === $back1['counts']['failed'], is_array( $back1 ) ? $back1 : $back1->get_error_message() );
$check( 'products are no longer managed', '' === (string) get_post_meta( $first_id, S::PRODUCT_MANAGED, true ) && ! S::manages( 'catalog' ) && 0 === count( array_filter( S::export_local(), static fn( $l ) => $l['managed'] && 'publish' === $l['status'] ) ) );
$check( 'shipping plans back to what they were', $plans_before === get_option( Plans::OPTION, array() ) && 0 === S::state()['shipping_ver'] && 0 === S::state()['catalog_ver'] );
$dry4 = S::sync( $head, array( 'dry_run' => true, 'catalog' => $catalog( 1, $products ) ), true );
$check( 'after both restores the store equals its original export', 0 === $dry4['diff']['summary']['changed'] && 0 === $dry4['diff']['summary']['create'] && 0 === $dry4['diff']['summary']['unpublish'], $dry4['diff'] );

// ---- 9. 母版店：只接收价格，不新建、不下架、不锁定 ----
$master = S::clean_header( array( 'series_id' => 7, 'slug' => 'series-7', 'name' => 'Smoke series', 'master' => true ) );
$m      = S::sync( $master, array( 'catalog' => $catalog( 5, $v2 ) ), false );
$check( 'master store takes the price only', is_array( $m ) && 1 === $m['applied']['catalog']['updated'] && 0 === $m['applied']['catalog']['created'] && 0 === $m['applied']['catalog']['unpublished'], is_array( $m ) ? $m['applied'] : $m->get_error_message() );
$check( 'master price updated, nothing unpublished, nothing locked', array( $new_price, $new_price + 1000 ) === $price_of( $target ) && $count_before === $published() && ! S::manages( 'catalog' ) && S::is_master() );

// ---- 收尾：回到测试前的样子 ----
$final = S::restore( 5 );
$check( 'final restore', is_array( $final ) && array( $old_p, $old_c ) === $price_of( $target ) );
wp_delete_post( $new_id, true );
foreach ( S::snapshots() as $snap ) {
	delete_option( S::SNAPSHOT_PREFIX . (int) $snap['id'] );
}
delete_option( S::SNAPSHOTS );
delete_option( S::OPTION );
delete_option( S::MEDIA_MAP );

WP_CLI::success( 'PASS ' . $checks . ' M3 series sync smoke checks on a real WooCommerce store copy (' . $count_before . ' products).' );
