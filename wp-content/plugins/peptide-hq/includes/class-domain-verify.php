<?php
/**
 * 换域名自检（2026-11-17 换域名事故之后加的）。
 *
 * 换一个店铺的顾客域名要动的地方太多：nginx 的 server_name 和两道 444 守卫、证书、
 * WordPress 的 home、总后台的 sites 表、支付绑定、CAPI 桥、CDN 域名表。漏掉任何一处
 * 都不会当场报错，而是过几个小时以顾客投诉的形式出现。
 *
 * 这里把所有总后台看得见的检查点收成一条命令：
 *
 *     wp pchq-domain verify 16          # 一家店
 *     wp pchq-domain verify all         # 所有店
 *     wp pchq-domain verify all --offline   # 只查数据，不发网络请求
 *
 * 有任何一项不通过，退出码是 1，可以直接挂在换域名流程的最后一步。
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Domain_Verify {

	private const BARE = '/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?\z/';

	public static function register_hooks(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'pchq-domain', array( self::class, 'cli' ) );
		}
	}

	/** 去掉开头的 www.；不是合法域名返回空串。 */
	public static function bare( string $host ): string {
		$host = strtolower( trim( $host ) );
		if ( str_starts_with( $host, 'www.' ) ) { $host = substr( $host, 4 ); }
		return ( '' !== $host && strlen( $host ) <= 253 && preg_match( self::BARE, $host ) ) ? $host : '';
	}

	/**
	 * 一家店的全部检查点。
	 *
	 * @param array $site   Sites::row() 出来的那种数组。
	 * @param bool  $online 是否发网络请求。
	 * @return array<int,array{key:string,label:string,ok:?bool,detail:string}>
	 */
	public static function report( array $site, bool $online = true ): array {
		$out     = array();
		$site_id = (int) ( $site['id'] ?? 0 );
		$uuid    = (string) ( $site['uuid'] ?? '' );
		$public  = Site_Urls::public_url( $site );
		$host    = strtolower( (string) wp_parse_url( $public, PHP_URL_HOST ) );
		$bare    = self::bare( $host );
		$add     = static function ( string $key, string $label, ?bool $ok, string $detail ) use ( &$out ): void {
			$out[] = array( 'key' => $key, 'label' => $label, 'ok' => $ok, 'detail' => $detail );
		};

		// 1. 总后台这一行本身。
		$add(
			'site',
			'店铺登记',
			'' !== $uuid && '' !== $public && '' !== $bare,
			'' !== $public ? ( '顾客域名 ' . $host . '；管理地址 ' . ( (string) wp_parse_url( Site_Urls::management( $site ), PHP_URL_HOST ) ) ) : '顾客网址无效或未登记'
		);
		$add(
			'dual',
			'管理与顾客地址分离',
			! empty( $site['dual_domain'] ),
			! empty( $site['dual_domain'] ) ? '分离，换域名不会影响投手登录' : '未分离：换顾客域名会同时换掉登录地址'
		);

		// 2. CDN 域名表：换域名流程的「权威」，支付自愈也认它。
		$rows = get_option( 'pchq_cdn_domains', array() );
		$rows = is_array( $rows ) ? $rows : array();
		$row  = is_array( $rows[ $bare ] ?? null ) ? $rows[ $bare ] : null;
		$add(
			'cdn',
			'CDN 域名表',
			null !== $row && (int) ( $row['site_id'] ?? 0 ) === $site_id && 'done' === (string) ( $row['stage'] ?? '' ),
			null === $row
				? ( $bare . ' 没有登记，支付绑定不会自动跟上' )
				: sprintf( '%s：stage=%s，site_id=%s，账号=%s', $bare, (string) ( $row['stage'] ?? '' ), (string) ( $row['site_id'] ?? '' ), (string) ( $row['account'] ?? '（未记录）' ) )
		);

		// 3. 同一家店名下还挂着别的域名——换域名之后最容易留下的尾巴。
		$stale = array();
		foreach ( $rows as $d => $r ) {
			if ( is_array( $r ) && (int) ( $r['site_id'] ?? 0 ) === $site_id && (string) $d !== $bare ) {
				$stale[] = (string) $d . '(' . (string) ( $r['stage'] ?? '' ) . ')';
			}
		}
		$add( 'cdn-stale', '旧域名残留', ! $stale, $stale ? ( '还挂在这家店名下：' . implode( '、', $stale ) ) : '没有旧域名留在这家店名下' );

		// 4. 支付绑定：用和 Hub::provider_site() 一样的口径算一遍。
		foreach ( self::bindings( $uuid ) as $account => $binding ) {
			$verdict = self::binding_verdict( $site_id, $host, $bare, $binding );
			$add( 'pay:' . $account, '支付绑定 ' . substr( $account, 0, 8 ), $verdict[0], $verdict[1] );
		}
		if ( ! self::bindings( $uuid ) ) {
			$add( 'pay', '支付绑定', null, '这家店没有绑定记录，建单时直接用顾客网址' );
		}

		// 5. CAPI 桥（装了才查）。
		$capi = get_option( 'stealth_capi_auto_synced_domains', null );
		if ( is_array( $capi ) ) {
			$found = in_array( $host, array_map( 'strval', $capi ), true ) || in_array( $bare, array_map( 'strval', $capi ), true );
			$add( 'capi', 'CAPI 桥域名', $found, $found ? ( $host . ' 已同步' ) : ( $host . ' 不在桥的域名表里' ) );
		}

		// 6. 邮件中心的发件地址（2026-11-21）：没有 orders@新域名 的连接时，邮件会悄悄用默认发信邮箱发出；
		// 在线时再核对 Cloudflare 要求的 SPF / DKIM 记录，没齐就明确提示，避免被收件方静默拒收。
		if ( '' !== $bare && class_exists( Mail_Domains::class ) ) {
			try {
				$mail = Mail_Domains::check( $bare, $online );
				if ( $online ) { Mail_Domains::remember_check( $bare, $mail ); }
			} catch ( \Throwable $e ) {
				$mail = array( 'ok' => null, 'message' => '检查出错：' . $e->getMessage() );
			}
			$add( 'mail', '发信域名', $mail['ok'], $mail['message'] );
		}

		if ( ! $online || '' === $host ) {
			return $out;
		}
		// 总后台这台机器可能整体禁止外发请求（WP_HTTP_BLOCK_EXTERNAL）。那样这两项不是「不通过」，
		// 是「查不了」——别让它变成假警报，照实说一句，让人去店铺那台机器上跑。
		if ( self::blocked( $host ) ) {
			$add( 'http', '店面可访问', null, '本机禁止外发 HTTP 请求（WP_HTTP_BLOCK_EXTERNAL），跳过；可在店铺那台机器上访问确认' );
			$add( 'notify', '支付回调入口', null, '同上，跳过' );
			return $out;
		}

		// 6. 真的访问一次：nginx、证书、WordPress 的 home 任何一处没跟上，这里就会掉出来。
		$front = wp_remote_get( 'https://' . $host . '/', array( 'timeout' => 20, 'redirection' => 0, 'sslverify' => true ) );
		$code  = is_wp_error( $front ) ? 0 : (int) wp_remote_retrieve_response_code( $front );
		$skipped = static fn( $r ): bool => is_wp_error( $r ) && 'http_request_not_executed' === $r->get_error_code();
		$add(
			'http',
			'店面可访问',
			$skipped( $front ) ? null : 200 === $code,
			is_wp_error( $front ) ? ( '请求失败：' . $front->get_error_message() ) : ( 'HTTP ' . $code . '（444/421/525 说明 nginx 或证书没跟上）' )
		);

		// 支付回调入口要能落到 PHP：这里期待的是应用自己的 400，不是 nginx 的拦截。
		$notify = wp_remote_post( 'https://' . $host . '/?wc-api=pc_panapay_notify', array( 'timeout' => 20, 'redirection' => 0, 'body' => '{}', 'headers' => array( 'Content-Type' => 'application/json' ) ) );
		$ncode  = is_wp_error( $notify ) ? 0 : (int) wp_remote_retrieve_response_code( $notify );
		$nbody  = is_wp_error( $notify ) ? '' : (string) wp_remote_retrieve_body( $notify );
		$add(
			'notify',
			'支付回调入口',
			$skipped( $notify ) ? null : ( 400 === $ncode && str_contains( $nbody, 'notification_not_accepted' ) ),
			is_wp_error( $notify ) ? ( '请求失败：' . $notify->get_error_message() ) : ( 'HTTP ' . $ncode . '；期待 400 notification_not_accepted' )
		);

		return $out;
	}

	/** 这家店（按 uuid）在支付绑定里的记录，键是账户编号。 */
	private static function bindings( string $uuid ): array {
		$settings = get_option( 'pc_panapay_provider_site_bindings', null );
		if ( '' === $uuid || ! is_array( $settings ) || ! is_array( $settings['bindings'] ?? null ) ) { return array(); }
		$out = array();
		foreach ( $settings['bindings'] as $key => $binding ) {
			if ( is_string( $key ) && str_ends_with( $key, ':' . $uuid ) ) {
				$out[ substr( $key, 0, strlen( $key ) - strlen( $uuid ) - 1 ) ] = $binding;
			}
		}
		return $out;
	}

	/** @return array{0:bool,1:string} */
	private static function binding_verdict( int $site_id, string $host, string $bare, $binding ): array {
		if ( ! is_array( $binding ) || count( $binding ) !== 3 || array_diff( array_keys( $binding ), array( 'site_id', 'public_host', 'provider_site' ) )
			|| ! is_int( $binding['site_id'] ?? null ) || $binding['site_id'] !== $site_id
			|| ! is_string( $binding['provider_site'] ?? null ) || ! preg_match( self::BARE, (string) $binding['provider_site'] ) ) {
			return array( false, '绑定记录结构不对或 site_id 对不上，支付会直接 503' );
		}
		$bound    = (string) $binding['public_host'];
		$provider = (string) $binding['provider_site'];
		if ( $host !== $bound ) {
			$can = ( '' !== $bare ) && self::cdn_authorized( $site_id, $bare );
			return array(
				false,
				sprintf( '绑定记的是 %s，店铺现在是 %s —— %s', $bound, $host, $can ? '新域名已在 CDN 表里，下次有人打开店铺后台会自动跟上' : '新域名不在 CDN 表里，不会自动跟上，支付会 503' ),
			);
		}
		if ( $host !== $provider && ! str_ends_with( $host, '.' . $provider ) ) {
			return array( false, sprintf( '顾客域名 %s 不属于商户站点 %s，支付会 503', $host, $provider ) );
		}
		return array( true, sprintf( '%s → 发给收单方的商户站点是 %s', $bound, $provider ) );
	}

	/** 和 WP_Http::block_request() 同口径：这台机器允不允许请求这个域名。 */
	public static function blocked( string $host ): bool {
		if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) || ! WP_HTTP_BLOCK_EXTERNAL ) { return false; }
		$home = strtolower( (string) wp_parse_url( get_option( 'home' ), PHP_URL_HOST ) );
		if ( '' !== $home && $home === $host ) { return false; }
		$allowed = defined( 'WP_ACCESSIBLE_HOSTS' ) ? explode( ',', (string) WP_ACCESSIBLE_HOSTS ) : array();
		foreach ( $allowed as $rule ) {
			$rule = strtolower( trim( $rule ) );
			if ( '' === $rule ) { continue; }
			if ( $rule === $host ) { return false; }
			if ( str_starts_with( $rule, '*.' ) && str_ends_with( $host, substr( $rule, 1 ) ) ) { return false; }
		}
		return true;
	}

	private static function cdn_authorized( int $site_id, string $bare ): bool {
		$rows = get_option( 'pchq_cdn_domains', array() );
		$row  = is_array( $rows ) && is_array( $rows[ $bare ] ?? null ) ? $rows[ $bare ] : null;
		return null !== $row && (int) ( $row['site_id'] ?? 0 ) === $site_id && 'done' === (string) ( $row['stage'] ?? '' );
	}

	/**
	 * 换域名自检。
	 *
	 * ## OPTIONS
	 *
	 * <command>
	 * : verify
	 *
	 * [<site>]
	 * : 店铺编号，或者 all。
	 *
	 * [--offline]
	 * : 只查数据，不发网络请求。
	 */
	public static function cli( array $args, array $assoc = array() ): void {
		if ( 'verify' !== ( $args[0] ?? '' ) ) { \WP_CLI::error( '用法：wp pchq-domain verify <店铺编号|all> [--offline]' ); }
		$target = (string) ( $args[1] ?? 'all' );
		$online = empty( $assoc['offline'] );
		$sites  = Sites::all();
		if ( 'all' !== $target ) {
			$sites = array_filter( $sites, static fn( array $s ): bool => (int) $s['id'] === (int) $target );
			if ( ! $sites ) { \WP_CLI::error( '没有这家店：' . $target ); }
		}
		$bad = 0;
		foreach ( $sites as $site ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( sprintf( '# %s（#%d）', (string) $site['name'], (int) $site['id'] ) );
			foreach ( self::report( $site, $online ) as $check ) {
				if ( true === $check['ok'] ) { $mark = '  ✓ '; }
				elseif ( null === $check['ok'] ) { $mark = '  — '; }
				else { $mark = '  ✗ '; ++$bad; }
				\WP_CLI::log( $mark . str_pad( $check['label'], 18 ) . ' ' . $check['detail'] );
			}
		}
		\WP_CLI::log( '' );
		if ( $bad ) { \WP_CLI::error( sprintf( '%d 项没通过。', $bad ) ); }
		\WP_CLI::success( '全部通过。' );
	}
}
