<?php
/**
 * M3（HQ-05、FE-07）店铺端纯逻辑测试：系列状态、同步幂等、商品对象校验、差异报告、商品配对、价格换算、
 * 运费两档换算、快照选择。不需要 WordPress、WooCommerce、数据库或网络。
 * 写商品、下载图片、运费写入需要 WooCommerce，放在装好环境后的冒烟测试里。
 * 运行：php -n -d extension=mbstring m3-series-sync-pure.php
 */
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'MINUTE_IN_SECONDS', 60 );
	class WP_Error {
		public function __construct( public string $code = '', public string $message = '', public $data = array() ) {}
		public function get_error_message(): string { return $this->message; }
	}
	function is_wp_error( $v ): bool { return $v instanceof WP_Error; }
	function get_option( string $k, $default = false ) { return $GLOBALS['options'][ $k ] ?? $default; }
	function update_option( string $k, $v, $autoload = null ): bool { $GLOBALS['options'][ $k ] = $v; return true; }
	function delete_option( string $k ): bool { unset( $GLOBALS['options'][ $k ] ); return true; }
	function add_action( ...$a ): void {}
	function add_filter( ...$a ): void {}
	function apply_filters( string $hook, $value, ...$rest ) { return isset( $GLOBALS['filters'][ $hook ] ) ? $GLOBALS['filters'][ $hook ]( $value, ...$rest ) : $value; }
	function wp_json_encode( $v, int $flags = 0 ) { return json_encode( $v, $flags ); }
	function wc_get_products( array $args ): array { return array(); }
	$GLOBALS['options'] = array();
	$GLOBALS['filters'] = array();
}
namespace {
	require dirname( __DIR__ ) . '/includes/class-series-sync.php';
	use PeptideCore\Series_Sync as S;

	$checks = 0;
	function check( string $name, bool $ok ): void { ++$GLOBALS['checks']; if ( ! $ok ) { throw new RuntimeException( 'FAIL ' . $name ); } }

	// ---- 系列基本信息 ----
	check( 'header accepted', array( 'series_id' => 1, 'slug' => 'series-1', 'name' => '系列 1', 'template_ver' => 2, 'master' => false ) === S::clean_header( array( 'series_id' => 1, 'slug' => 'series-1', 'name' => ' <b>系列 1</b> ', 'template_ver' => 2, 'junk' => 'x' ) ) );
	check( 'header refuses bad id or slug', null === S::clean_header( array( 'series_id' => 0, 'slug' => 'a' ) ) && null === S::clean_header( array( 'series_id' => 1, 'slug' => 'Bad Slug' ) ) && null === S::clean_header( array() ) );
	$empty = S::normalize_state( null );
	check( 'empty state', 0 === $empty['series_id'] && false === $empty['master'] && 0 === $empty['catalog_ver'] );
	$st = S::merge_header( array( 'series_id' => 1, 'catalog_ver' => 5, 'template_ver' => 4, 'campaign_ver' => 2, 'shipping_ver' => 3 ), array( 'series_id' => 1, 'slug' => 'series-1', 'name' => 'A', 'template_ver' => 3, 'master' => false ) );
	check( 'same series keeps versions; template never goes back', 5 === $st['catalog_ver'] && 4 === $st['template_ver'] && 3 === $st['shipping_ver'] );
	$st = S::merge_header( $st, array( 'series_id' => 2, 'slug' => 'series-2', 'name' => 'B', 'template_ver' => 1, 'master' => true ) );
	check( 'new series resets content versions', 2 === $st['series_id'] && 0 === $st['catalog_ver'] && 0 === $st['campaign_ver'] && 0 === $st['shipping_ver'] && 1 === $st['template_ver'] && true === $st['master'] );
	check( 'section due only when newer', S::section_due( array( 'ver' => 3 ), 2 ) && ! S::section_due( array( 'ver' => 2 ), 2 ) && ! S::section_due( array( 'ver' => 0 ), 0 ) && ! S::section_due( 'x', 0 ) );
	check( 'capabilities are added without dropping others', array( 'hq.capabilities' => '1', 'series.sync' => '1', 'series.catalog' => '1', 'series.shipping' => '1' ) === S::capabilities( array( 'hq.capabilities' => '1' ) ) );

	// ---- 同步：只有基本信息；重复调用结果不变；换系列归零 ----
	$head = S::clean_header( array( 'series_id' => 1, 'slug' => 'series-1', 'name' => '系列 1', 'template_ver' => 2 ) );
	$r1   = S::sync( $head, array(), false );
	check( 'header-only sync writes the option', 1 === S::series_id() && 2 === S::state()['template_ver'] && true === $r1['complete'] && array( 'catalog' => null, 'campaigns' => null, 'shipping' => null ) === $r1['applied'] );
	$saved = S::state();
	$r2    = S::sync( $head, array(), false );
	check( 'same request twice leaves the same state', array_diff_key( $saved, array( 'synced_at' => 1 ) ) === array_diff_key( S::state(), array( 'synced_at' => 1 ) ) );
	check( 'no snapshot for a header-only sync', array() === S::snapshots() );
	check( 'not managed before any content is synced', ! S::manages( 'catalog' ) && ! S::manages( 'campaign' ) && ! S::manages( 'shipping' ) );
	$dry = S::sync( S::clean_header( array( 'series_id' => 9, 'slug' => 'series-9' ) ), array( 'dry_run' => true ), true );
	check( 'dry run writes nothing', 1 === S::series_id() && array() === $dry['diff'] && 1 === $dry['series']['series_id'] );

	// ---- 活动：交给营销模块；没人接手不记版本；版本不新就跳过 ----
	$camp = array( 'campaigns' => array( 'ver' => 1, 'items' => array( array( 'kind' => 'coupon', 'key' => 'welcome10', 'active' => true, 'config' => array( 'code' => 'WELCOME10' ) ), 'junk' ) ) );
	$r    = S::sync( $head, $camp, false );
	check( 'campaigns unhandled without a marketing handler', 'unhandled' === $r['applied']['campaigns'] && 0 === S::state()['campaign_ver'] );
	$GLOBALS['seen']                                 = array();
	$GLOBALS['filters']['pc_series_apply_campaigns'] = static function ( $result, array $items, int $ver, bool $dry ) { $GLOBALS['seen'][] = array( count( $items ), $ver, $dry ); return $dry ? array( 'would_replace' => count( $items ) ) : true; };
	$r = S::sync( $head, $camp, true );
	check( 'campaign dry run returns the handler diff and writes nothing', array( 'would_replace' => 1 ) === $r['diff']['campaigns'] && 0 === S::state()['campaign_ver'] && array( 1, 1, true ) === $GLOBALS['seen'][0] );
	$r = S::sync( $head, $camp, false );
	check( 'campaigns applied once handled', 'applied' === $r['applied']['campaigns'] && 1 === S::state()['campaign_ver'] && S::manages( 'campaign' ) );
	check( 'a snapshot was taken before the change', 1 === count( S::snapshots() ) && 1 === S::snapshots()[0]['to']['campaign_ver'] && 0 === S::snapshots()[0]['from']['campaign_ver'] );
	$calls = count( $GLOBALS['seen'] );
	$r     = S::sync( $head, $camp, false );
	check( 'same version again is skipped', 'skipped' === $r['applied']['campaigns'] && $calls === count( $GLOBALS['seen'] ) && 1 === count( S::snapshots() ) );
	$GLOBALS['filters']['pc_series_apply_campaigns'] = static fn() => new WP_Error( 'm4', 'bad campaign' );
	$r = S::sync( $head, array( 'campaigns' => array( 'ver' => 2, 'items' => array() ) ), false );
	check( 'handler error stops the sync and keeps the old version', is_wp_error( $r ) && 1 === S::state()['campaign_ver'] );
	S::sync( S::clean_header( array( 'series_id' => 2, 'slug' => 'series-2', 'master' => true ) ), array(), false );
	check( 'moving to another series resets versions; master is not locked', 2 === S::series_id() && 0 === S::state()['campaign_ver'] && S::is_master() && ! S::manages( 'campaign' ) );

	// ---- 价格换算 ----
	check( 'series → WooCommerce prices', array( 'regular' => '59.99', 'sale' => '49.99' ) === S::wc_prices( 4999, 5999 ) && array( 'regular' => '49.99', 'sale' => '' ) === S::wc_prices( 4999, 0 ) && array( 'regular' => '49.99', 'sale' => '' ) === S::wc_prices( 4999, 4999 ) && array( 'regular' => '', 'sale' => '' ) === S::wc_prices( 0, 0 ) );
	check( 'WooCommerce → series prices', array( 4999, 5999 ) === S::series_prices( '59.99', '49.99' ) && array( 4999, 0 ) === S::series_prices( '49.99', '' ) && array( 1999, 0 ) === S::series_prices( '19.99', '19.99' ) && array( 0, 0 ) === S::series_prices( '', '' ) );
	foreach ( array( array( 4999, 5999 ), array( 1999, 0 ), array( 10000, 12050 ) ) as $pair ) {
		$wc = S::wc_prices( $pair[0], $pair[1] );
		check( 'price round trip ' . $pair[0], $pair === S::series_prices( $wc['regular'], $wc['sale'] ) );
	}
	check( 'attribute key ignores case and order', S::attr_key( array( 'Specifications' => '5MG', 'pack' => 'x10' ) ) === S::attr_key( array( 'pack' => 'X10', 'specifications' => '5mg ' ) ) && S::attr_key( array( 'a' => '1' ) ) !== S::attr_key( array( 'a' => '2' ) ) && 18 === strlen( S::attr_key( array() ) ) );

	// ---- 商品对象校验 ----
	$sha  = static fn( string $c ): string => str_repeat( $c, 64 );
	$var  = static fn( string $sku, string $spec, int $price, int $compare = 0, array $extra = array() ): array => array_merge( array( 'key' => $sku, 'sku' => $sku, 'attributes' => array( 'specifications' => $spec ), 'price_cents' => $price, 'compare_cents' => $compare, 'stock_status' => 'instock', 'enabled' => true, 'image' => null, 'meta' => array() ), $extra );
	$prod = static fn( string $sku, string $name, array $vars, array $extra = array() ): array => array_merge(
		array( 'sku' => $sku, 'wc_sku' => $sku, 'type' => 'variable', 'name' => $name, 'slug' => strtolower( $sku ), 'status' => 'publish', 'description' => '<p>Research use only.</p>', 'short_description' => '', 'categories' => array( 'Peptides' ), 'menu_order' => 0,
			'attributes' => array( array( 'name' => 'Specifications', 'options' => array_map( static fn( $v ) => $v['attributes']['specifications'], $vars ), 'visible' => true, 'variation' => true ) ),
			'default_attributes' => array(), 'images' => array( array( 'url' => 'https://master.example.test/wp-content/uploads/a.webp', 'sha256' => $GLOBALS['sha']( 'a' ), 'name' => 'a.webp', 'alt' => '' ) ),
			'meta' => array( '_pc_spu' => $sku ), 'price_cents' => 0, 'compare_cents' => 0, 'stock_status' => 'instock', 'variations' => $vars ),
		$extra
	);
	$bpc = $prod( 'VC-BPC157', 'BPC-157', array( $var( 'VC-BPC157-5', '5mg', 4999, 5999 ), $var( 'VC-BPC157-10', '10mg', 8999 ) ) );
	$tb  = $prod( 'VC-TB500', 'TB-500', array( $var( 'VC-TB500-5', '5mg', 3999 ) ) );
	$cb  = S::clean_product( $bpc );
	check( 'clean product is stable', $cb === S::clean_product( $cb ) && $cb === S::clean_product( json_decode( json_encode( $cb ), true ) ) );
	check( 'product needs key and name', null === S::clean_product( array( 'sku' => '', 'name' => 'x' ) ) && null === S::clean_product( array( 'sku' => 'x', 'name' => '' ) ) && null === S::clean_product( 'x' ) );
	check( 'variable product needs variations; duplicates refused', null === S::clean_product( $prod( 'A', 'A', array() ) ) && null === S::clean_product( $prod( 'A', 'A', array( $var( 'K', '5mg', 1 ), $var( 'K', '10mg', 1 ) ) ) ) );
	check( 'local products are kept even without variations', is_array( S::clean_product( $prod( 'A', 'A', array() ), false ) ) );
	check( 'only whitelisted meta and valid images survive', array( '_pc_spu' => 'X' ) === S::clean_product( $prod( 'X', 'X', array( $var( 'X-1', '5mg', 1 ) ), array( 'meta' => array( '_pc_spu' => 'X', '_price' => '1', '_pc_secret' => 'no' ), 'images' => array( array( 'url' => 'javascript:alert(1)', 'sha256' => $sha( 'b' ) ), array( 'url' => 'https://x.test/a.png', 'sha256' => 'short' ) ) ) ) )['meta'] && array() === S::clean_product( $prod( 'X', 'X', array( $var( 'X-1', '5mg', 1 ) ), array( 'images' => array( array( 'url' => 'javascript:alert(1)', 'sha256' => $sha( 'b' ) ) ) ) ) )['images'] );
	check( 'variation without sku gets an attribute key', 0 === strpos( S::clean_variation( array( 'attributes' => array( 'specifications' => '5mg' ), 'price_cents' => 100 ) )['key'], 'a:' ) && null === S::clean_variation( array( 'attributes' => array(), 'price_cents' => 1 ) ) && null === S::clean_variation( array( 'attributes' => array( 'a' => 'b' ), 'price_cents' => -1 ) ) );
	$cat = S::clean_catalog( array( 'ver' => 3, 'source_host' => 'Master.Example.Test', 'products' => array( $bpc, $tb ) ) );
	check( 'catalog accepted', is_array( $cat ) && 3 === $cat['ver'] && 'master.example.test' === $cat['source_host'] && 2 === count( $cat['products'] ) );
	check( 'catalog refused as a whole', 'empty_or_too_large' === S::clean_catalog( array( 'ver' => 3, 'products' => array() ) ) && 'empty_or_too_large' === S::clean_catalog( array( 'ver' => 0, 'products' => array( $bpc ) ) ) && 'invalid_product' === S::clean_catalog( array( 'ver' => 1, 'products' => array( $bpc, array( 'sku' => 'x' ) ) ) ) && 'duplicate_sku' === S::clean_catalog( array( 'ver' => 1, 'products' => array( $bpc, $bpc ) ) ) && 'bad_source_host' === S::clean_catalog( array( 'ver' => 1, 'source_host' => 'a b', 'products' => array( $bpc ) ) ) );

	// ---- 比较：一样时没有差异；改一个价格只报那一处 ----
	$in    = $cat['products'];
	$local = static fn( array $p, int $id, array $extra = array() ): array => array_merge( S::clean_product( $p, false ), array( 'id' => $id, 'series_sku' => '', 'managed' => false ), $extra );
	check( 'identical products have no diff', array() === S::compare_product( $local( $bpc, 11 ), $in[0] ) );
	$cheaper = $prod( 'VC-BPC157', 'BPC-157', array( $var( 'VC-BPC157-5', '5mg', 4499, 5999 ), $var( 'VC-BPC157-10', '10mg', 8999 ) ) );
	$fields  = S::compare_product( $local( $bpc, 11 ), S::clean_product( $cheaper ) );
	check( 'one price change gives one field', array( array( 'field' => 'price', 'store' => '49.99', 'series' => '44.99', 'variation' => '5mg' ) ) === $fields );
	$odd = $prod( 'VC-BPC157', 'BPC 157 (old name)', array( $var( '', '5mg', 4999, 5999 ), $var( 'VC-BPC157-20', '20mg', 15000 ) ), array( 'description' => '<p>Different   text</p>', 'categories' => array( 'Healing' ), 'images' => array() ) );
	$names = array_column( S::compare_product( $local( $odd, 11 ), $in[0] ), 'field' );
	check( 'many differences are all listed', ! array_diff( array( 'name', 'description', 'categories', 'attributes', 'images', 'variation' ), $names ) && 2 === count( array_keys( $names, 'variation', true ) ) );
	check( 'variation without sku still matches by attributes', ! in_array( 'price', $names, true ) );
	check( 'whitespace-only description change is ignored', array() === S::compare_product( $local( $prod( 'VC-TB500', 'TB-500', array( $var( 'VC-TB500-5', '5mg', 3999 ) ), array( 'description' => "<p>Research   use\nonly.</p>" ) ), 12 ), $in[1] ) );
	$tracked = $local( $prod( 'VC-TB500', 'TB-500', array( $var( 'VC-TB500-5', '5mg', 3999, 0, array( 'stock_status' => 'outofstock', 'manage_stock' => true ) ) ) ), 12 );
	check( 'stock is not compared when the store tracks its own quantity', array() === S::compare_product( $tracked, $in[1] ) );
	$untracked = $local( $prod( 'VC-TB500', 'TB-500', array( $var( 'VC-TB500-5', '5mg', 3999, 0, array( 'stock_status' => 'outofstock' ) ) ) ), 12 );
	check( 'stock status is compared otherwise', array( 'stock' ) === array_column( S::compare_product( $untracked, $in[1] ), 'field' ) );
	check( 'sort order is compared', array( 'menu_order' ) === array_column( S::compare_product( $local( $prod( 'VC-TB500', 'TB-500', array( $var( 'VC-TB500-5', '5mg', 3999 ) ), array( 'menu_order' => 7 ) ), 12 ), $in[1] ), 'field' ) );
	check( 'empty meta values are dropped like the export does', array() === S::clean_product( $prod( 'X', 'X', array( $var( 'X-1', '5mg', 1 ) ), array( 'meta' => array( '_pc_product_subtitle' => '', '_pc_specs' => array() ) ) ) )['meta'] );
	check( 'price filter keeps only price fields', array( 'price' ) === array_column( S::price_fields( array( array( 'field' => 'name' ), array( 'field' => 'price' ), array( 'field' => 'stock' ) ) ), 'field' ) );

	// ---- 配对：_pc_series_sku → _pc_spu → SKU → 别名；一个本地商品只配一次 ----
	$locals = array(
		$local( $prod( 'OLD-KEY', 'BPC-157', array( $var( 'VC-BPC157-5', '5mg', 4999, 5999 ), $var( 'VC-BPC157-10', '10mg', 8999 ) ), array( 'meta' => array( '_pc_spu' => 'VC-BPC157' ) ) ), 11 ),
		$local( $prod( 'VC-TB500', 'TB-500', array( $var( 'VC-TB500-5', '5mg', 3999 ) ), array( 'meta' => array() ) ), 12 ),
		$local( $prod( 'LOCAL-ONLY', 'Store special', array( $var( 'LOCAL-1', '1mg', 100 ) ) ), 13 ),
		$local( $prod( 'DRAFT-ONLY', 'Hidden', array( $var( 'DRAFT-1', '1mg', 100 ) ), array( 'status' => 'draft' ) ), 14 ),
	);
	check( 'match by spu, then sku', array( 0, 1 ) === S::match_products( $in, $locals ) );
	$renamed = $locals;
	$renamed[2]['series_sku'] = 'VC-TB500';
	check( 'series key wins over sku', array( 0, 2 ) === S::match_products( $in, $renamed ) );
	check( 'slug fallback', array( 0 ) === S::match_products( array( array( 'sku' => 'slug:bpc-old' ) ), array( array( 'slug' => 'bpc-old', 'meta' => array() ) ) ) && array( null ) === S::match_products( array( array( 'sku' => 'NEW' ) ), $locals ) );

	// ---- 差异报告 ----
	$new  = $prod( 'VC-NEW', 'New peptide', array( $var( 'VC-NEW-1', '1mg', 2500 ) ) );
	$cat2 = S::clean_catalog( array( 'ver' => 4, 'products' => array( $cheaper, $tb, $new ) ) );
	$diff = S::diff_catalog( $cat2['products'], $locals, 4 );
	check( 'diff summary (price change on one product, missing spu on the other)', array( 'same' => 0, 'changed' => 2, 'create' => 1, 'unpublish' => 1 ) === $diff['summary'] && 4 === $diff['catalog_ver'] && array( 'meta' ) === array_column( $diff['changed'][1]['fields'], 'field' ) );
	check( 'diff lists what will be created and unpublished', array( array( 'sku' => 'VC-NEW', 'name' => 'New peptide' ) ) === $diff['create'] && array( array( 'id' => 13, 'sku' => 'LOCAL-ONLY', 'name' => 'Store special' ) ) === $diff['unpublish'] );
	check( 'diff shows the changed price with the local product id', 11 === $diff['changed'][0]['id'] && 'price' === $diff['changed'][0]['fields'][0]['field'] && '44.99' === $diff['changed'][0]['fields'][0]['series'] );
	$master = S::diff_catalog( $cat2['products'], $locals, 4, true );
	check( 'master store diff is prices only', array( 'same' => 1, 'changed' => 1, 'create' => 0, 'unpublish' => 0 ) === $master['summary'] && true === $master['prices_only'] );
	check( 'diff is repeatable', $diff === S::diff_catalog( $cat2['products'], $locals, 4 ) );

	// ---- 两份导出文件互相比较：出差异报告不用碰店铺 ----
	$dump = json_decode( (string) json_encode( array( 'source_host' => 'store-b.example.test', 'store' => array( 'name' => 'Store B', 'host' => 'Store-B.Example.Test' ), 'local' => $locals ) ), true );
	$file = S::diff_dump( array( 'source_host' => 'master.example.test', 'products' => array( $cheaper, $tb, $new ) ), $dump );
	$want = S::diff_catalog( $cat2['products'], $locals, 0 );
	check( 'comparing two export files gives the same report as comparing on the store', is_array( $file ) && $want['summary'] === $file['summary'] && array_column( $want['changed'], 'id', 'sku' ) === array_column( $file['changed'], 'id', 'sku' ) && $want['changed'][0]['fields'] === $file['changed'][0]['fields'] && $want['create'] === $file['create'] && $want['unpublish'] === $file['unpublish'] );
	// 给人看的细节：附加信息按项拆开并带上两边的值；描述截出第一处不同；图片列出各自独有的文件。
	check( 'meta differences are listed per item with both values', array( array( 'field' => 'meta', 'key' => '_pc_spu', 'store' => '（没有）', 'series' => 'VC-TB500' ) ) === $file['changed'][1]['fields'] );
	check( 'first difference is cut out of both texts', array( 'Research use only.', 'Research use only. Ships cold.' ) === S::first_difference( 'Research use only.', 'Research use only. Ships cold.' ) && array( '…90123456789012345678Xtail', '…90123456789012345678Ytail' ) === S::first_difference( str_repeat( '1234567890', 3 ) . '12345678Xtail', str_repeat( '1234567890', 3 ) . '12345678Ytail' ) && array( '（到这里就没有了）', '…研究用途，冷链发货' ) === S::first_difference( '', '…研究用途，冷链发货' ) );
	$img2    = array( 'url' => 'https://master.example.test/wp-content/uploads/b.webp', 'sha256' => $sha( 'b' ), 'name' => 'b.webp', 'alt' => '' );
	$img1    = $tb['images'][0];
	$edited  = $local( $prod( 'VC-TB500', 'TB-500', array( $var( 'VC-TB500-5', '5mg', 3999, 0, array( 'meta' => array( '_pc_pricing' => array( 'tier' => 2 ) ) ) ) ), array( 'description' => '<p>Research use only.</p><p>Ships <b>cold</b>.</p>', 'short_description' => '<div>Short</div>', 'images' => array( $img2 ) ) ), 12 );
	$master2 = $prod( 'VC-TB500', 'TB-500', array( $var( 'VC-TB500-5', '5mg', 3999 ) ), array( 'short_description' => '<p>Short</p>', 'images' => array( $img1 ) ) );
	$rows    = S::explain_changes( S::diff_catalog( array( S::clean_product( $master2 ) ), array( $edited ), 0 )['changed'], array( $edited ), array( S::clean_product( $master2 ) ) )[0]['fields'];
	$row     = static function ( string $field ) use ( $rows ): array { foreach ( $rows as $r ) { if ( $field === $r['field'] ) { return $r; } } return array(); };
	check( 'description difference shows where the texts part', 'Research use only. Ships cold.' === $row( 'description' )['store_excerpt'] && 'Research use only.' === $row( 'description' )['series_excerpt'] );
	check( 'same words with different markup are flagged as markup only', 'markup' === ( $row( 'short_description' )['note'] ?? '' ) );
	check( 'image difference names the files on each side', array( 'b.webp' ) === $row( 'images' )['store_only'] && array( 'a.webp' ) === $row( 'images' )['series_only'] );
	check( 'variation meta shows both values', '5mg' === $row( 'meta' )['variation'] && '_pc_pricing' === $row( 'meta' )['key'] && '{"tier":2}' === $row( 'meta' )['store'] && '（没有）' === $row( 'meta' )['series'] );
	$swapped = $local( $prod( 'VC-TB500', 'TB-500', array( $var( 'VC-TB500-5', '5mg', 3999 ) ), array( 'images' => array( $img2, $img1 ) ) ), 12 );
	$master3 = S::clean_product( $prod( 'VC-TB500', 'TB-500', array( $var( 'VC-TB500-5', '5mg', 3999 ) ), array( 'images' => array( $img1, $img2 ) ) ) );
	check( 'same images in another order are flagged as order only', 'order' === S::explain_changes( S::diff_catalog( array( $master3 ), array( $swapped ), 0 )['changed'], array( $swapped ), array( $master3 ) )[0]['fields'][0]['note'] );
	$many = array();
	for ( $i = 1; $i <= 30; $i++ ) { $many[] = $var( 'M-' . $i, $i . 'mg', 1000 + $i, 2000 ); }
	$pricey = array_map( static fn( array $v ): array => array_merge( $v, array( 'price_cents' => $v['price_cents'] + 100, 'compare_cents' => 2500 ) ), $many );
	$big    = S::diff_dump( array( 'products' => array( $prod( 'M', 'Many', $pricey ) ) ), array( 'local' => array( $local( $prod( 'M', 'Many', $many ), 21 ) ) ) );
	check( 'the file report lists every difference, the sync preview stays short', 60 === count( $big['changed'][0]['fields'] ) && 40 === count( S::diff_catalog( array( S::clean_product( $prod( 'M', 'Many', $pricey ) ) ), array( $local( $prod( 'M', 'Many', $many ), 21 ) ), 0 )['changed'][0]['fields'] ) );
	$plan_a = array( array( 'name' => 'General', 'regions' => array( array( 'country' => 'US', 'states' => array() ) ), 'rates' => array( array( 'name' => 'Standard', 'rule' => 'subtotal', 'min' => 0.0, 'max' => 1000.0, 'fee_cents' => 4500 ), array( 'name' => 'Standard', 'rule' => 'subtotal', 'min' => 1000.0, 'max' => null, 'fee_cents' => 1500 ) ) ) );
	$plan_b = array( array( 'name' => 'General', 'regions' => array(), 'rates' => array( array( 'name' => 'Flat', 'rule' => 'subtotal', 'min' => 0.0, 'max' => null, 'fee_cents' => 2250 ) ) ) );
	check( 'shipping plans read as plain lines', array( 'General｜US｜Standard：订单金额不到 $1000，运费 $45', 'General｜US｜Standard：订单金额满 $1000，运费 $15' ) === S::shipping_lines( $plan_a ) && array( 'General｜全部地区｜Flat：不限金额，运费 $22.50' ) === S::shipping_lines( $plan_b ) && array() === S::shipping_lines( array() ) );
	$ship = S::diff_dump( array( 'products' => array( $tb ), 'shipping' => array( 'plans' => $plan_a ) ), array( 'local' => $locals, 'shipping' => array( 'plans' => $plan_b ) ) );
	check( 'the file report compares shipping plans when both files carry them', true === $ship['shipping']['changed'] && 1 === count( $ship['shipping']['store'] ) && 2 === count( $ship['shipping']['series'] ) && false === S::diff_dump( array( 'products' => array( $tb ), 'shipping' => array( 'plans' => $plan_a ) ), array( 'local' => $locals, 'shipping' => array( 'plans' => $plan_a ) ) )['shipping']['changed'] && ! isset( $file['shipping'] ) );
	check( 'the report names the store from the file', array( 'name' => 'Store B', 'host' => 'store-b.example.test' ) === $file['store'] );
	check( 'files that are not exports are refused with a reason', is_string( S::diff_dump( array(), $dump ) ) && is_string( S::diff_dump( array( 'products' => array( $cheaper ) ), array( 'products' => array( $cheaper ) ) ) ) && is_string( S::diff_dump( array( 'products' => array() ), $dump ) ) );

	// ---- 运费两档（FE-07）：系列格式 ↔ 店铺运费方案 ----
	$series_plans = array( array( 'name' => 'Standard shipping', 'regions' => array( array( 'country' => 'US', 'states' => array() ) ), 'rates' => array(
		array( 'name' => 'Standard Shipping', 'rule' => 'subtotal', 'min' => 0, 'max' => 1000, 'fee_cents' => 4500 ),
		array( 'name' => 'Standard Shipping', 'rule' => 'subtotal', 'min' => 1000, 'max' => null, 'fee_cents' => 1500 ),
	) ) );
	$store_plans = S::plans_from_series( $series_plans, 7 );
	check( 'two tiers become two rates', 7 === $store_plans[0]['id'] && 'general' === $store_plans[0]['type'] && 45.0 === $store_plans[0]['rates'][0]['fee'] && 1000.0 === $store_plans[0]['rates'][0]['max'] && 15.0 === $store_plans[0]['rates'][1]['fee'] && null === $store_plans[0]['rates'][1]['max'] && true === $store_plans[0]['rates'][1]['enabled'] );
	check( 'rate ids are fixed so repeat syncs match', array( 's1r1', 's1r2' ) === array_column( $store_plans[0]['rates'], 'id' ) && $store_plans === S::plans_from_series( $series_plans, 7 ) );
	$back = S::plans_to_series( $store_plans );
	check( 'round trip keeps tiers and fees', 4500 === $back[0]['rates'][0]['fee_cents'] && 1500 === $back[0]['rates'][1]['fee_cents'] && 1000.0 === $back[0]['rates'][1]['min'] && 'US' === $back[0]['regions'][0]['country'] );
	$custom = $store_plans;
	$custom[0]['type'] = 'custom';
	$custom[0]['rates'][0]['enabled'] = false;
	check( 'product-specific plans and disabled rates are not exported', array() === S::plans_to_series( $custom ) && 1 === count( S::plans_to_series( array( array_replace( $custom[0], array( 'type' => 'general' ) ) ) )[0]['rates'] ) );

	// ---- 快照选择 ----
	$list = array( array( 'id' => 4, 'to' => array( 'catalog_ver' => 3 ) ), array( 'id' => 3, 'to' => array( 'catalog_ver' => 4 ) ) );
	check( 'latest snapshot by default', 4 === S::pick_snapshot( $list, 0 ) );
	$later = array( array( 'id' => 2, 'from' => array( 'catalog_ver' => 5 ), 'to' => array( 'catalog_ver' => 5 ) ), array( 'id' => 1, 'from' => array( 'catalog_ver' => 4 ), 'to' => array( 'catalog_ver' => 5 ) ) );
	check( 'a later shipping-only snapshot does not stand in for the catalog one', 1 === S::pick_snapshot( $later, 5 ) && 2 === S::pick_snapshot( $later, 0 ) );
	check( 'pick by synced catalog version first, then by id', 4 === S::pick_snapshot( $list, 3 ) && 3 === S::pick_snapshot( $list, 4 ) && 0 === S::pick_snapshot( $list, 9 ) && 0 === S::pick_snapshot( array(), 0 ) );

	echo 'PASS ', $checks, " store-side M3 series sync checks; no WordPress, database, or network.\n";
}
