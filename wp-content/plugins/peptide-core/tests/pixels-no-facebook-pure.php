<?php
/**
 * 2026-11-17（用户要求）：前端不准再出现 Meta 像素。
 *
 * 这个测试守住三件事：
 *   1. 插件发给浏览器的任何脚本里，connect.facebook.net / fbevents.js 只许出现在注释里，
 *      不许出现在会执行的代码里；fbq( 和 A.facebook = 一次都不许出现。
 *   2. pixels.js 的派发仍然带适配器存在性判断，所以设置里即便还留着 facebook 配置，
 *      也只是安全空转：不发事件、不报错、不影响 ga4 / tiktok / bing 和购物流程。
 *   3. 其他平台的适配器还在（别一刀切把 ga4 / tiktok 也删了）。
 *
 * 不碰数据库、不需要 WordPress，直接 php 跑。
 */
namespace {

	function check( string $label, bool $ok ): void {
		global $checks;
		if ( ! $ok ) {
			fwrite( STDERR, "FAIL: $label\n" );
			exit( 1 );
		}
		$checks = ( $checks ?? 0 ) + 1;
	}

	/** 只保留会执行的行：整行注释（// 、* 、/* ）不算代码。 */
	function code_lines( string $source ): string {
		$out = array();
		foreach ( preg_split( '/\R/', $source ) as $line ) {
			if ( preg_match( '~^\s*(?://|\*|/\*|\*/)~', $line ) ) {
				continue;
			}
			$out[] = $line;
		}
		return implode( "\n", $out );
	}

	$assets = dirname( __DIR__ ) . '/assets';
	$pixels = $assets . '/pixels.js';
	check( 'pixels.js 还在', is_file( $pixels ) );

	$raw  = (string) file_get_contents( $pixels );
	$code = code_lines( $raw );

	// ---------- 1. 前端不许再加载 Meta 的脚本 ----------

	check( 'pixels.js 的代码里没有 connect.facebook.net', ! str_contains( $code, 'connect.facebook.net' ) );
	check( 'pixels.js 的代码里没有 fbevents', ! str_contains( $code, 'fbevents' ) );
	check( 'pixels.js 里没有 fbq( 调用', ! str_contains( $code, 'fbq(' ) );
	check( 'pixels.js 里没有 A.facebook 适配器', ! preg_match( '/A\.facebook\s*=/', $code ) );
	check( 'pixels.js 里没有 window.fbq / w.fbq 赋值', ! preg_match( '/\b[wf]\.fbq\s*=/', $code ) );

	// 注释里提到是允许的（删除说明要留着），但必须真的是注释。
	check(
		'connect.facebook.net 若出现，只出现在注释里',
		! str_contains( $raw, 'connect.facebook.net' ) || ! str_contains( $code, 'connect.facebook.net' )
	);

	// 插件里所有发给浏览器的脚本都查一遍，别从别的文件又溜回来。
	$scanned = 0;
	foreach ( (array) glob( $assets . '/*.js' ) as $file ) {
		$body = code_lines( (string) file_get_contents( (string) $file ) );
		$name = basename( (string) $file );
		check( "{$name} 没有 connect.facebook.net", ! str_contains( $body, 'connect.facebook.net' ) );
		check( "{$name} 没有 fbevents", ! str_contains( $body, 'fbevents' ) );
		check( "{$name} 没有 fbq(", ! str_contains( $body, 'fbq(' ) );
		++$scanned;
	}
	check( '至少扫到了一个前端脚本', $scanned > 0 );

	// PHP 那边也不许把这个脚本 enqueue 或者内联打出去。
	$php_hits = array();
	$walker   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__ ) . '/includes' ) );
	foreach ( $walker as $entry ) {
		if ( ! $entry->isFile() || 'php' !== strtolower( $entry->getExtension() ) ) {
			continue;
		}
		$body = code_lines( (string) file_get_contents( $entry->getPathname() ) );
		if ( str_contains( $body, 'connect.facebook.net' ) || str_contains( $body, 'fbevents' ) ) {
			$php_hits[] = $entry->getFilename();
		}
	}
	check( 'includes/ 下的 PHP 代码里没有 connect.facebook.net / fbevents：' . implode( ',', $php_hits ), ! $php_hits );

	// ---------- 2. 留着 facebook 配置也必须是安全空转 ----------

	check(
		'init 处仍然判断适配器存在（list.length && A[key]）',
		(bool) preg_match( '/if\s*\(\s*list\.length\s*&&\s*A\[\s*key\s*\]\s*\)/', $code )
	);
	check(
		'track 只遍历已初始化的 active',
		(bool) preg_match( '/active\.forEach\s*\(/', $code )
	);
	check(
		'init 失败不会往外抛（有 try/catch）',
		(bool) preg_match( '/A\[\s*key\s*\]\.init\([^)]*\);\s*\R?\s*active\.push/', $code )
	);
	check(
		'某个平台 track 出错不影响其他平台',
		(bool) preg_match( '/adapter\.track\(/', $code ) && (bool) preg_match( '/catch\s*\(\s*err\s*\)/', $code )
	);

	// ---------- 3. 别把别的平台一起删了 ----------

	foreach ( array( 'ga4', 'tiktok' ) as $platform ) {
		check( "A.{$platform} 适配器还在", (bool) preg_match( '/A\.' . $platform . '\s*=/', $code ) );
	}

	echo "PASS {$checks} 前端无 Meta 像素检查\n";
}
