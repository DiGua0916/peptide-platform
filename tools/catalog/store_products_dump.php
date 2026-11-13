<?php
/**
 * 只读导出一家店的商品，给“各店与主商品库的差异报告”用。
 *
 * 用在还没有部署系列功能的店上（比如现在的生产店）：不用先发布新代码，也不用拉整库快照。
 * 它只把 class-series-sync.php 这一个文件临时载入到这一次命令里（不注册任何钩子、不放进插件目录），
 * 调用里面的只读导出，把结果写成一个 JSON 文件。
 *
 *   wp --path=<店铺目录> eval-file <本文件> <class-series-sync.php 的路径> <输出文件.json>
 *
 * 读什么：商品、款式、分类、商品图片文件（算哈希）、运费方案。
 * 写什么：只写 <输出文件.json>。不写数据库（连缓存都不写），不发任何网络请求。
 * 文件里有什么：商品名称、描述、价格、图片地址和哈希、运费方案、店名和域名。
 * 没有订单、顾客、账号、密钥。
 *
 * 导出的文件：
 * - 母版店的那一份，直接当主商品库：  wp peptide series diff --file=<母版店.json> --store-file=<某店.json> --out=diff-<某店>.json
 * - 其余各店的，当 --store-file。
 * 上面的 diff 命令可以在任何装了本分支代码的 WordPress 上跑（沙盒或本机副本），不需要那家店的数据库。
 *
 * 这个脚本在哪里跑、什么时候跑由 F1 决定；生产店上跑之前要平台所有者同意。
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	fwrite( STDERR, "只能用 wp eval-file 运行。\n" );
	exit( 1 );
}

( static function ( array $argv ): void {
	$class_file = (string) ( $argv[0] ?? '' );
	$out_file   = (string) ( $argv[1] ?? '' );
	if ( '' === $class_file || '' === $out_file ) {
		WP_CLI::error( '用法：wp eval-file store_products_dump.php <class-series-sync.php 的路径> <输出文件.json>' );
	}
	if ( ! function_exists( 'wc_get_products' ) ) {
		WP_CLI::error( '这个站没有启用 WooCommerce。' );
	}
	$class = 'PeptideCore\\Series_Sync';
	if ( ! class_exists( $class ) ) {
		if ( ! is_readable( $class_file ) || 'class-series-sync.php' !== basename( $class_file ) ) {
			WP_CLI::error( '找不到 class-series-sync.php：' . $class_file );
		}
		require_once $class_file; // 只定义一个类，不注册钩子。
	}
	if ( ! is_callable( array( $class, 'export_dump' ) ) ) {
		WP_CLI::error( '这家店上的系列代码太旧，没有只读导出。请把 <class-series-sync.php 的路径> 指向 F3 分支里的那一份。' );
	}
	if ( file_exists( $out_file ) ) {
		WP_CLI::error( '输出文件已经存在，不覆盖：' . $out_file );
	}
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
	global $wpdb;
	$writes = 0;
	$watch  = static function ( $query ) use ( &$writes ) {
		if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE)\b/i', (string) $query ) ) {
			++$writes;
		}
		return $query;
	};
	add_filter( 'query', $watch );
	$data = call_user_func( array( $class, 'export_dump' ) );
	remove_filter( 'query', $watch );

	$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	if ( ! is_string( $json ) || false === file_put_contents( $out_file, $json ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
		WP_CLI::error( '写不了输出文件：' . $out_file );
	}
	WP_CLI::log( sprintf( '店铺：%s（%s）', $data['store']['name'], $data['store']['host'] ) );
	WP_CLI::log( sprintf( '商品：全部 %d 个（含草稿），其中已发布、能进主商品库的 %d 个', count( $data['local'] ), count( $data['products'] ) ) );
	WP_CLI::log( sprintf( '导出期间对数据库的写入语句：%d 条', $writes ) );
	if ( $writes > 0 ) {
		WP_CLI::warning( '导出期间有写入语句（可能来自别的插件的后台任务），请告诉 F3。' );
	}
	WP_CLI::success( '已写到 ' . $out_file . '（' . size_format( strlen( $json ) ) . '）。' );
} )( $args ?? array() );
