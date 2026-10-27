<?php
/**
 * 域名管理（照 YY 后台“店铺装修 → 域名管理”，用户 2026-10-27）。后台页面见 Admin\Domains_Page。
 *
 * - 绑定的域名存在一个选项里，最多 100 个。添加时默认同时绑定一级域名和它的 www 子域名。
 * - 访客打开的是已绑定的域名时，home / siteurl 换成这个域名，页面里的链接留在这个域名上。
 * - 连接状态：服务器请求 http(s)://<域名>/?pc_domain_check=… ，返回本站给这个域名算出的校验码才算连上，
 *   证明域名确实指到了这个网站，而不只是解析出了地址。
 * - HTTPS 状态：同样的请求走 https 并验证证书。生效后，这个域名的 http 访问 301 到 https。
 * - 新添加的域名 7 天内每小时检查一次，之后每天一次。YY 7 天没连上会自动删除，这里不删，只显示“未连接”。
 * - 域名跳转：一个域名的前台访问 301 到另一个已绑定的域名（例如 www → @），不允许连环跳转。
 * - 主域名就是 WordPress 的网站地址（home / siteurl）。只有已连接的域名才能切换为主域名。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Domains {

	public const OPTION    = 'peptide_core_domains';
	public const CRON      = 'pc_domains_check';
	public const CHECK_ARG = 'pc_domain_check';
	public const MAX       = 100;

	/** 新域名每小时检查的天数（YY：7 天内每小时自动检查）。 */
	public const FAST_DAYS = 7;

	/** 每次定时检查最多检查几个域名。 */
	private const PER_RUN = 10;

	public const CDNS        = array( 'cloudflare', 'other', 'none' );
	public const CONN_STATES = array( 'checking', 'connected', 'failed' );
	public const SSL_STATES  = array( 'checking', 'active', 'failed' );

	/** 常见的二级公共后缀，用来找一级域名（example.co.uk）。 */
	private const SECOND_LEVEL = array( 'co.uk', 'org.uk', 'me.uk', 'ac.uk', 'com.au', 'net.au', 'org.au', 'co.nz', 'co.jp', 'com.br', 'com.cn', 'net.cn', 'org.cn', 'com.hk', 'com.tw', 'com.sg', 'com.my', 'co.za', 'com.mx', 'co.in', 'co.kr', 'com.tr' );

	/** 只在本机或内网能用的后缀：网站在这些地址上时，外面的域名还连不上。 */
	private const LOCAL_TLDS = array( 'local', 'test', 'localhost', 'lan', 'internal', 'invalid', 'example', 'home', 'corp' );

	/** 读写 home / siteurl 原值时为 true，不换成访客打开的域名。 */
	private static bool $raw = false;

	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'answer_check' ), 0 );
		add_filter( 'option_home', array( self::class, 'filter_url' ), 99 );
		add_filter( 'option_siteurl', array( self::class, 'filter_url' ), 99 );
		add_action( 'template_redirect', array( self::class, 'redirect' ), -10 );
		add_action( self::CRON, array( self::class, 'run_checks' ) );
		if ( self::stored_hosts() ) {
			self::schedule();
		}
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CRON );
		}
	}

	// ---------- 数据 ----------

	/** 选项里的域名（只看键，前台每个链接都会用到，保持便宜）。 */
	private static function stored_hosts(): array {
		$data = get_option( self::OPTION, array() );
		return is_array( $data ) && is_array( $data['items'] ?? null ) ? $data['items'] : array();
	}

	/**
	 * @return array<string,array> 域名 => 记录
	 */
	public static function items(): array {
		$out = array();
		foreach ( self::stored_hosts() as $host => $item ) {
			if ( is_string( $host ) && is_array( $item ) ) {
				$out[ $host ] = self::fill( array( 'host' => $host ) + $item );
			}
		}
		return $out;
	}

	public static function hosts(): array {
		return array_keys( self::stored_hosts() );
	}

	private static function fill( array $item ): array {
		return array(
			'id'          => (int) ( $item['id'] ?? 0 ),
			'host'        => (string) ( $item['host'] ?? '' ),
			'cdn'         => in_array( $item['cdn'] ?? '', self::CDNS, true ) ? $item['cdn'] : 'none',
			'redirect'    => (string) ( $item['redirect'] ?? '' ),
			'created'     => (int) ( $item['created'] ?? 0 ),
			'checked'     => (int) ( $item['checked'] ?? 0 ),
			'conn'        => in_array( $item['conn'] ?? '', self::CONN_STATES, true ) ? $item['conn'] : 'checking',
			'https'       => in_array( $item['https'] ?? '', self::SSL_STATES, true ) ? $item['https'] : 'checking',
			'dns'         => is_array( $item['dns'] ?? null ) ? $item['dns'] : array(),
			'error'       => (string) ( $item['error'] ?? '' ),
			'https_error' => (string) ( $item['https_error'] ?? '' ),
		);
	}

	private static function data(): array {
		$data = get_option( self::OPTION, array() );
		$data = is_array( $data ) ? $data : array();
		return array(
			'items'     => self::items(),
			'next_id'   => max( 1, (int) ( $data['next_id'] ?? 1 ) ),
			'server_ip' => (string) ( $data['server_ip'] ?? '' ),
		);
	}

	private static function save( array $data ): void {
		update_option( self::OPTION, $data, true );
	}

	private static function new_item( string $host, int $id, string $cdn ): array {
		return self::fill(
			array(
				'id'      => $id,
				'host'    => $host,
				'cdn'     => $cdn,
				'created' => time(),
			)
		);
	}

	// ---------- 域名规则 ----------

	/** 一级域名：shop.example.com → example.com，www.example.co.uk → example.co.uk。 */
	public static function root( string $host ): string {
		$labels = explode( '.', $host );
		$keep   = count( $labels ) >= 3 && in_array( implode( '.', array_slice( $labels, -2 ) ), self::SECOND_LEVEL, true ) ? 3 : 2;
		return implode( '.', array_slice( $labels, -$keep ) );
	}

	/** 解析记录里的“主机记录”：一级域名是 @，子域名是前面的部分。 */
	public static function record_name( string $host ): string {
		$root = self::root( $host );
		return $host === $root ? '@' : substr( $host, 0, -strlen( $root ) - 1 );
	}

	/** 和它一起绑定的域名：example.com ↔ www.example.com；其他子域名没有。 */
	public static function pair( string $host ): string {
		if ( str_starts_with( $host, 'www.' ) ) {
			return substr( $host, 4 );
		}
		return self::root( $host ) === $host ? 'www.' . $host : '';
	}

	/**
	 * 输入 → 要绑定的域名（不勾“仅绑定填写域名”时带上 www 配对）。
	 *
	 * @param string[] $inputs
	 * @return string[]|\WP_Error
	 */
	public static function expand( array $inputs, bool $only ) {
		$out = array();
		foreach ( $inputs as $input ) {
			$input = trim( (string) $input );
			if ( '' === $input ) {
				continue;
			}
			$host = Templates::normalize_host( $input );
			if ( null === $host || ! str_contains( $host, '.' ) ) {
				/* translators: %s: what the user typed */
				return new \WP_Error( 'pc_domain_invalid', sprintf( __( '“%s” is not a valid domain. Enter it like example.com.', 'peptide-core' ), $input ) );
			}
			$out[] = $host;
			$pair  = $only ? '' : self::pair( $host );
			if ( '' !== $pair ) {
				$out[] = $pair;
			}
		}
		$out = array_values( array_unique( $out ) );
		return $out ? $out : new \WP_Error( 'pc_domain_empty', __( 'Enter a domain.', 'peptide-core' ) );
	}

	// ---------- 添加 / 删除 / 跳转 / 主域名 ----------

	/**
	 * @param string[] $hosts 已经规范化的域名（expand 的结果）
	 * @return string[]|\WP_Error 新添加的域名
	 */
	public static function add( array $hosts, string $cdn ) {
		$data = self::data();
		$new  = array();
		foreach ( $hosts as $host ) {
			$host = (string) Templates::normalize_host( (string) $host );
			if ( '' !== $host && str_contains( $host, '.' ) && ! isset( $data['items'][ $host ] ) ) {
				$new[] = $host;
			}
		}
		$new = array_values( array_unique( $new ) );
		if ( ! $new ) {
			return new \WP_Error( 'pc_domain_exists', __( 'These domains are already bound.', 'peptide-core' ) );
		}
		if ( count( $data['items'] ) + count( $new ) > self::MAX ) {
			/* translators: %d: maximum number of domains */
			return new \WP_Error( 'pc_domain_limit', sprintf( __( 'You can bind up to %d domains.', 'peptide-core' ), self::MAX ) );
		}
		$cdn = in_array( $cdn, self::CDNS, true ) ? $cdn : 'none';
		foreach ( $new as $host ) {
			$data['items'][ $host ] = self::new_item( $host, $data['next_id']++, $cdn );
		}
		self::save( $data );
		self::schedule();
		return $new;
	}

	/** 网站地址本身也列在域名里（YY 的“免费域名”那一行），不能删除。 */
	public static function ensure_primary(): void {
		$host = self::primary_host();
		$data = self::data();
		if ( '' === $host || isset( $data['items'][ $host ] ) ) {
			return;
		}
		$data['items'][ $host ] = self::new_item( $host, $data['next_id']++, 'none' );
		self::save( $data );
		self::schedule();
	}

	/**
	 * 删除域名。主域名不能删；指向被删域名的跳转一起取消。
	 *
	 * @param string[] $hosts
	 */
	public static function remove( array $hosts ): int {
		$data    = self::data();
		$primary = self::primary_host();
		$removed = 0;
		foreach ( $hosts as $host ) {
			if ( $host !== $primary && isset( $data['items'][ $host ] ) ) {
				unset( $data['items'][ $host ] );
				++$removed;
			}
		}
		foreach ( $data['items'] as $host => $item ) {
			if ( '' !== $item['redirect'] && ! isset( $data['items'][ $item['redirect'] ] ) ) {
				$data['items'][ $host ]['redirect'] = '';
			}
		}
		if ( $removed ) {
			self::save( $data );
		}
		return $removed;
	}

	/**
	 * 设置域名跳转。$target 为空时取消。
	 *
	 * @return true|\WP_Error
	 */
	public static function set_redirect( string $host, string $target ) {
		$data  = self::data();
		$items = $data['items'];
		if ( ! isset( $items[ $host ] ) ) {
			return new \WP_Error( 'pc_domain_missing', __( 'This domain is not bound.', 'peptide-core' ) );
		}
		if ( '' !== $target ) {
			if ( $target === $host || ! isset( $items[ $target ] ) ) {
				return new \WP_Error( 'pc_redirect_target', __( 'Choose another bound domain to redirect to.', 'peptide-core' ) );
			}
			if ( $host === self::primary_host() ) {
				return new \WP_Error( 'pc_redirect_primary', __( 'The primary domain cannot redirect. Switch the primary domain first.', 'peptide-core' ) );
			}
			if ( '' !== $items[ $target ]['redirect'] ) {
				/* translators: %s: domain */
				return new \WP_Error( 'pc_redirect_chain', sprintf( __( '%s already redirects to another domain. Choose a domain that does not redirect.', 'peptide-core' ), $target ) );
			}
			foreach ( $items as $other ) {
				if ( $other['redirect'] === $host ) {
					/* translators: %s: domain */
					return new \WP_Error( 'pc_redirect_chain', sprintf( __( '%s redirects to this domain. Remove that redirect first.', 'peptide-core' ), $other['host'] ) );
				}
			}
		}
		$data['items'][ $host ]['redirect'] = $target;
		self::save( $data );
		return true;
	}

	/** WordPress 的网站地址（不受访客打开的域名影响）。 */
	public static function primary_host(): string {
		self::$raw = true;
		$host      = (string) Templates::normalize_host( (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_HOST ) );
		self::$raw = false;
		return $host;
	}

	/**
	 * 切换主域名：把 home / siteurl 换成这个域名。旧的网站地址留在列表里，照样能打开后台。
	 *
	 * @return true|\WP_Error
	 */
	public static function set_primary( string $host ) {
		if ( defined( 'WP_HOME' ) || defined( 'WP_SITEURL' ) ) {
			return new \WP_Error( 'pc_primary_locked', __( 'The site address is fixed in wp-config.php (WP_HOME / WP_SITEURL), so it cannot be switched here.', 'peptide-core' ) );
		}
		$items = self::items();
		if ( ! isset( $items[ $host ] ) ) {
			return new \WP_Error( 'pc_domain_missing', __( 'This domain is not bound.', 'peptide-core' ) );
		}
		if ( self::primary_host() === $host ) {
			return true;
		}
		if ( 'connected' !== $items[ $host ]['conn'] ) {
			return new \WP_Error( 'pc_primary_unconnected', __( 'Only a connected domain can become the primary domain. Check the domain first.', 'peptide-core' ) );
		}
		if ( '' !== $items[ $host ]['redirect'] ) {
			return new \WP_Error( 'pc_primary_redirect', __( 'This domain redirects to another domain. Remove the redirect first.', 'peptide-core' ) );
		}
		self::ensure_primary();
		$scheme    = 'active' === $items[ $host ]['https'] ? 'https' : 'http';
		$swap      = static fn( string $url ): string => $scheme . '://' . $host . untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		self::$raw = true;
		update_option( 'home', $swap( (string) get_option( 'home' ) ) );
		update_option( 'siteurl', $swap( (string) get_option( 'siteurl' ) ) );
		self::$raw = false;
		return true;
	}

	// ---------- 服务器 IP（解析记录的目标） ----------

	public static function server_ip(): string {
		return self::data()['server_ip'];
	}

	/** 服务器自己的公网地址（本机或内网地址不算）。 */
	public static function suggested_ip(): string {
		$addr = isset( $_SERVER['SERVER_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) ) : '';
		return filter_var( $addr, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ? $addr : '';
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function save_server_ip( string $ip ) {
		$ip = trim( $ip );
		if ( '' !== $ip && ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return new \WP_Error( 'pc_ip_invalid', __( 'Enter an IP address, for example 203.0.113.10.', 'peptide-core' ) );
		}
		$data              = self::data();
		$data['server_ip'] = $ip;
		self::save( $data );
		return true;
	}

	/**
	 * 要在域名服务商（或 Cloudflare / CDN）那里添加的解析记录。value 为空表示还不知道（没填服务器 IP，或要用 CDN 给的地址）。
	 *
	 * @return array{type:string,name:string,value:string}
	 */
	public static function record( string $host, string $cdn ): array {
		$name = self::record_name( $host );
		if ( 'other' === $cdn ) {
			return array( 'type' => 'CNAME', 'name' => $name, 'value' => '' );
		}
		$bare = str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : '';
		if ( '' !== $bare && in_array( $bare, self::hosts(), true ) ) {
			return array( 'type' => 'CNAME', 'name' => $name, 'value' => $bare );
		}
		return array( 'type' => 'A', 'name' => $name, 'value' => self::server_ip() );
	}

	/** 网站现在在本机或内网地址上（例如 .local），外面的域名还连不上。 */
	public static function is_local_site(): bool {
		$host = self::primary_host();
		$tld  = (string) substr( (string) strrchr( '.' . $host, '.' ), 1 );
		return in_array( $tld, self::LOCAL_TLDS, true );
	}

	// ---------- 检查 ----------

	/** 这个网站给某个域名的校验码。 */
	public static function token( string $host ): string {
		return substr( hash_hmac( 'sha256', 'pc-domain|' . $host, wp_salt( 'nonce' ) ), 0, 32 );
	}

	/** 回答检查请求：输出本站给当前域名的校验码。 */
	public static function answer_check(): void {
		if ( ! isset( $_GET[ self::CHECK_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$host = self::request_host();
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo esc_html( '' !== $host ? self::token( $host ) : '' );
		exit;
	}

	/**
	 * 检查一个域名：当前解析、http 能否连到本站、https（验证证书）。保存并返回新的记录。
	 */
	public static function check( string $host ): ?array {
		$data = self::data();
		if ( ! isset( $data['items'][ $host ] ) ) {
			return null;
		}
		$item  = $data['items'][ $host ];
		$now   = time();
		$young = $now - $item['created'] < self::FAST_DAYS * DAY_IN_SECONDS;
		$http  = self::probe( 'http', $host );
		$https = self::probe( 'https', $host );

		$item['dns']         = self::lookup( $host );
		$item['checked']     = $now;
		$item['conn']        = ( $http['ok'] || $https['ok'] ) ? 'connected' : ( $young && 'checking' === $item['conn'] ? 'checking' : 'failed' );
		$item['https']       = $https['ok'] ? 'active' : ( $young && 'checking' === $item['https'] ? 'checking' : 'failed' );
		$item['error']       = ( $http['ok'] || $https['ok'] ) ? '' : $http['error'];
		$item['https_error'] = $https['ok'] ? '' : $https['error'];
		if ( $http['cloudflare'] || $https['cloudflare'] ) {
			$item['cdn'] = 'cloudflare';
		}

		$data = self::data(); // 请求期间可能有别的改动（例如域名被删了），重新读一次再写。
		if ( ! isset( $data['items'][ $host ] ) ) {
			return null;
		}
		$data['items'][ $host ] = array_merge( $data['items'][ $host ], array_intersect_key( $item, array_flip( array( 'dns', 'checked', 'conn', 'https', 'error', 'https_error', 'cdn' ) ) ) );
		self::save( $data );
		return $data['items'][ $host ];
	}

	/**
	 * @return array{ok:bool,cloudflare:bool,error:string}
	 */
	private static function probe( string $scheme, string $host ): array {
		$res = wp_remote_get(
			$scheme . '://' . $host . '/?' . self::CHECK_ARG . '=' . wp_generate_password( 8, false ),
			array(
				'timeout'     => 8,
				'redirection' => 3,
				'sslverify'   => true,
				'user-agent'  => 'PeptideCore-DomainCheck/' . PEPTIDE_CORE_VERSION,
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return array( 'ok' => false, 'cloudflare' => false, 'error' => $res->get_error_message() );
		}
		$code   = (int) wp_remote_retrieve_response_code( $res );
		$server = strtolower( (string) wp_remote_retrieve_header( $res, 'server' ) );
		$cf     = '' !== (string) wp_remote_retrieve_header( $res, 'cf-ray' ) || str_contains( $server, 'cloudflare' );
		$ok     = 200 === $code && hash_equals( self::token( $host ), trim( (string) wp_remote_retrieve_body( $res ) ) );
		if ( $ok ) {
			$error = '';
		} elseif ( 200 === $code ) {
			$error = __( 'The domain opens a different website, not this store.', 'peptide-core' );
		} else {
			/* translators: %d: HTTP status code */
			$error = sprintf( __( 'The domain answered with HTTP status %d.', 'peptide-core' ), $code );
		}
		return array( 'ok' => $ok, 'cloudflare' => $cf, 'error' => $error );
	}

	/**
	 * 当前解析：A 记录的地址和 CNAME 目标。
	 *
	 * @return array{a:string[],cname:string[]}
	 */
	public static function lookup( string $host ): array {
		$out = array( 'a' => array(), 'cname' => array() );
		if ( ! function_exists( 'dns_get_record' ) ) {
			return $out;
		}
		$records = @dns_get_record( $host, DNS_A | DNS_CNAME ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- 查不到时 PHP 会报警告，这里只要结果。
		foreach ( is_array( $records ) ? $records : array() as $r ) {
			if ( 'A' === ( $r['type'] ?? '' ) && ! empty( $r['ip'] ) ) {
				$out['a'][] = (string) $r['ip'];
			} elseif ( 'CNAME' === ( $r['type'] ?? '' ) && ! empty( $r['target'] ) ) {
				$out['cname'][] = (string) $r['target'];
			}
		}
		return array(
			'a'     => array_slice( array_values( array_unique( $out['a'] ) ), 0, 8 ),
			'cname' => array_slice( array_values( array_unique( $out['cname'] ) ), 0, 4 ),
		);
	}

	/** 该不该检查：新域名没全部生效时每小时，其余每天。 */
	public static function due( array $item, int $now ): bool {
		$settled = 'connected' === $item['conn'] && 'active' === $item['https'];
		$young   = $now - $item['created'] < self::FAST_DAYS * DAY_IN_SECONDS;
		$every   = $young && ! $settled ? HOUR_IN_SECONDS : DAY_IN_SECONDS;
		return $now - $item['checked'] >= $every - MINUTE_IN_SECONDS;
	}

	/** 定时检查（每小时）。 */
	public static function run_checks(): void {
		self::ensure_primary();
		$now   = time();
		$items = self::items();
		uasort( $items, static fn( $a, $b ) => $a['checked'] <=> $b['checked'] );
		$done = 0;
		foreach ( $items as $host => $item ) {
			if ( $done >= self::PER_RUN ) {
				break;
			}
			if ( self::due( $item, $now ) ) {
				self::check( $host );
				++$done;
			}
		}
	}

	// ---------- 前台 ----------

	/** 访客打开的域名（去掉端口）。 */
	public static function request_host(): string {
		if ( empty( $_SERVER['HTTP_HOST'] ) ) {
			return '';
		}
		return (string) Templates::normalize_host( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) );
	}

	/** 访客打开的是另一个已绑定的域名时，home / siteurl 换成这个域名，链接就留在这个域名上。 */
	public static function filter_url( $value ) {
		if ( self::$raw || ! is_string( $value ) || '' === $value || empty( $_SERVER['HTTP_HOST'] ) ) {
			return $value;
		}
		$host = self::request_host();
		if ( '' === $host || ! isset( self::stored_hosts()[ $host ] ) ) {
			return $value;
		}
		$parts = wp_parse_url( $value );
		if ( empty( $parts['host'] ) || strtolower( $parts['host'] ) === $host ) {
			return $value;
		}
		$port = preg_match( '/:(\d{1,5})$/', sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ), $m ) ? ':' . $m[1] : '';
		return ( self::is_secure() ? 'https' : 'http' ) . '://' . $host . $port . ( $parts['path'] ?? '' );
	}

	/** https 访问（包括 Cloudflare 等代理在前面转成 http 的情况）。 */
	public static function is_secure(): bool {
		if ( is_ssl() ) {
			return true;
		}
		$proto = strtolower( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) : '' );
		if ( 'https' === trim( explode( ',', $proto )[0] ) ) {
			return true;
		}
		return str_contains( isset( $_SERVER['HTTP_CF_VISITOR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_VISITOR'] ) ) : '', '"https"' );
	}

	/** 前台：域名跳转，或 HTTPS 生效后 http → https。 */
	public static function redirect(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || isset( $_GET['wc-ajax'] ) || is_preview() || is_customize_preview() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$method = strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET' );
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- 下面的 wp_redirect 会清理。
		$to  = self::redirect_target( self::request_host(), (string) $uri, self::is_secure() );
		if ( null !== $to ) {
			wp_redirect( $to, 301, 'Peptide Core' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- 目标只会是已绑定的域名。
			exit;
		}
	}

	/** 某个请求该跳到哪里；不用跳时返回 null。 */
	public static function redirect_target( string $host, string $uri, bool $secure ): ?string {
		$items = self::items();
		if ( '' === $host || ! isset( $items[ $host ] ) ) {
			return null;
		}
		$uri    = '/' . ltrim( $uri, '/' );
		$target = $items[ $host ]['redirect'];
		if ( '' !== $target && $target !== $host && isset( $items[ $target ] ) ) {
			return ( 'active' === $items[ $target ]['https'] ? 'https' : 'http' ) . '://' . $target . $uri;
		}
		if ( 'active' === $items[ $host ]['https'] && ! $secure ) {
			return 'https://' . $host . $uri;
		}
		return null;
	}
}
