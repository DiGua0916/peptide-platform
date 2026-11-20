<?php
/**
 * Cloudflare CDN 自动配置（2026-11-08）。
 *
 * 新店绑定客户域名时（自动开店里填的客户自有域名），按顺序自动完成：
 *   1. 在 Cloudflare 找到这个域名（允许时自动添加），记下要在域名注册商处改的 NS；
 *   2. 建 @ 和 www 两条 A 记录指向店铺服务器，先用灰色云朵（仅 DNS）——开店程序要看到真实 IP 才会申请 HTTPS 证书；
 *   3. 等店铺开好、服务器证书可用、Cloudflare 边缘证书生效；
 *   4. 两条记录切成橙色（走 CDN），SSL 设为 Full (strict)，加和主店同样的“绕过缓存”规则。
 *
 * 只动这两条记录、这个域名的 SSL 模式和自己那条缓存规则；遇到同名的 AAAA / CNAME 等冲突就停下说明，不删用户的记录。
 * 令牌加密保存，只在总后台服务器上调用 Cloudflare。
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Cdn {

	public const OPTION  = 'pchq_cdn';
	public const DOMAINS = 'pchq_cdn_domains';
	public const CRON    = 'pchq_cdn_tick';
	private const API    = 'https://api.cloudflare.com/client/v4';
	private const RULE   = 'vialcove-storefront-bypass-v2';

	public static function register_hooks(): void {
		add_filter(
			'cron_schedules',
			static function ( array $s ): array {
				$s['pchq_cdn_5min'] = array( 'interval' => 300, 'display' => 'Every 5 minutes (CDN)' );
				return $s;
			}
		);
		add_action( self::CRON, array( self::class, 'tick' ) );
		add_action( 'pchq_cdn_tick_now', array( self::class, 'tick' ) );
		add_action(
			'init',
			static function (): void {
				if ( ! wp_next_scheduled( self::CRON ) ) {
					wp_schedule_event( time() + 120, 'pchq_cdn_5min', self::CRON );
				}
			}
		);
	}

	// ---------- 设置 ----------

	/**
	 * 2026-11-17（用户要求，一人一号）：可以保存多个 Cloudflare 账号的令牌，一个账号一条。
	 *
	 * 域名归哪个账号不用手工维护：第一次处理这个域名时，拿每个账号的令牌去 /zones?name=<域名>
	 * 问一遍，谁能看到就把账号编号记在 pchq_cdn_domains 的这一行上（account），以后固定用它。
	 * 账号换了令牌、或者域名转到别的账号，把这一行的 account 清掉就会重新认一次。
	 */
	public static function accounts(): array {
		$o   = get_option( self::OPTION, array() );
		$o   = is_array( $o ) ? $o : array();
		$out = array();
		foreach ( is_array( $o['accounts'] ?? null ) ? $o['accounts'] : array() as $a ) {
			if ( ! is_array( $a ) || '' === (string) ( $a['token_enc'] ?? '' ) ) {
				continue;
			}
			$id = (string) ( $a['id'] ?? '' );
			if ( '' === $id || isset( $out[ $id ] ) ) {
				continue;
			}
			$out[ $id ] = array(
				'id'          => $id,
				'label'       => (string) ( $a['label'] ?? '' ),
				'token_enc'   => (string) $a['token_enc'],
				'account_id'  => (string) ( $a['account_id'] ?? '' ),
				'verified_at' => (int) ( $a['verified_at'] ?? 0 ),
			);
		}
		// 旧版的单个令牌：没迁移前当成列表里的第一个账号，不用重新配置。
		if ( ! $out && '' !== (string) ( $o['token_enc'] ?? '' ) ) {
			$out['legacy'] = array(
				'id'          => 'legacy',
				'label'       => '默认',
				'token_enc'   => (string) $o['token_enc'],
				'account_id'  => (string) ( $o['account_id'] ?? '' ),
				'verified_at' => (int) ( $o['verified_at'] ?? 0 ),
			);
		}
		return $out;
	}

	public static function settings(): array {
		$o = get_option( self::OPTION, array() );
		$o = is_array( $o ) ? $o : array();
		return array(
			'accounts'     => self::accounts(),
			'enabled'      => ! empty( $o['enabled'] ),
			'create_zones' => ! empty( $o['create_zones'] ),
			// 下面三项是旧版单令牌的遗留字段，只给迁移用，不要在新代码里依赖。
			'token_enc'    => (string) ( $o['token_enc'] ?? '' ),
			'verified_at'  => (int) ( $o['verified_at'] ?? 0 ),
			'account_id'   => (string) ( $o['account_id'] ?? '' ),
		);
	}

	private static function raw(): array {
		$o = get_option( self::OPTION, array() );
		return is_array( $o ) ? $o : array();
	}

	private static function save_settings( array $s ): void {
		update_option( self::OPTION, $s, false );
	}

	/** 把账号列表写回去；写的时候顺手把旧版单令牌字段清掉（已经迁进列表了）。 */
	private static function save_accounts( array $accounts ): void {
		$o              = self::raw();
		$o['accounts']  = array_values( $accounts );
		$o['token_enc'] = '';
		$o['account_id'] = '';
		$o['verified_at'] = 0;
		self::save_settings( $o );
	}

	public static function has_token(): bool {
		return (bool) self::accounts();
	}

	/** 当前这次调用用哪个账号（process() 里按域名绑定）。 */
	private static string $ctx = '';

	private static function token(): string {
		$accounts = self::accounts();
		if ( '' !== self::$ctx && isset( $accounts[ self::$ctx ] ) ) {
			return Crypto::decrypt( $accounts[ self::$ctx ]['token_enc'] );
		}
		// 没有绑定域名的通用调用（费用页、GraphQL）：只有一个账号时用它，多个账号时用第一个。
		$first = $accounts ? reset( $accounts ) : null;
		return $first ? Crypto::decrypt( $first['token_enc'] ) : '';
	}

	/** 当前绑定的账号编号（空 = 没绑定）。 */
	public static function current_account(): string {
		return self::$ctx;
	}

	/**
	 * 认出这个域名属于哪个账号并绑定；认不出来返回 WP_Error。
	 *
	 * @return true|\WP_Error
	 */
	public static function bind_domain( string $domain ) {
		$accounts = self::accounts();
		if ( ! $accounts ) {
			return new \WP_Error( 'pchq_cdn_token', '还没有设置 Cloudflare 令牌。' );
		}
		$known = self::domains()[ $domain ]['account'] ?? '';
		if ( '' !== $known && isset( $accounts[ $known ] ) ) {
			self::$ctx = (string) $known;
			return true;
		}
		// 即便只有一个账号也要问一句：确认它确实看得到这个域名，否则后面会报一个
		// 看不懂的 Cloudflare 错误，而不是"没有账号能访问这个域名"。
		$errors = array();
		foreach ( $accounts as $id => $account ) {
			self::$ctx = (string) $id;
			$found     = self::api( 'GET', '/zones?name=' . rawurlencode( $domain ) );
			if ( is_wp_error( $found ) ) {
				$errors[] = ( '' !== $account['label'] ? $account['label'] : $id ) . '：' . $found->get_error_message();
				continue;
			}
			if ( ! empty( $found[0]['id'] ) ) {
				self::set_domain( $domain, array( 'account' => (string) $id ) );
				return true;
			}
		}
		self::$ctx = '';
		return new \WP_Error(
			'pchq_cdn_no_account',
			'没有账号能访问 ' . $domain . '。请确认这个域名已经加进某个 Cloudflare 账号，并且那个账号的令牌已经保存在这里。' . ( $errors ? '（' . implode( '；', $errors ) . '）' : '' )
		);
	}

	/** 保存并验证一个账号的令牌。$id 为空 = 新增；@return true|\WP_Error */
	public static function save_account( string $id, string $label, string $token ) {
		$accounts = self::accounts();
		$label    = trim( $label );
		if ( '' === $label ) {
			return new \WP_Error( 'pchq_cdn_label', '请给这个账号起个名字（例如投手名），方便以后分辨。' );
		}
		if ( '' === $id && '' === $token ) {
			return new \WP_Error( 'pchq_cdn_token', '请填写 Cloudflare API 令牌。' );
		}
		if ( '' !== $id && ! isset( $accounts[ $id ] ) ) {
			return new \WP_Error( 'pchq_cdn_account', '这个账号已经不在列表里了。' );
		}
		$entry = $accounts[ $id ] ?? array( 'id' => '', 'token_enc' => '', 'account_id' => '', 'verified_at' => 0 );
		if ( '' !== $token ) {
			if ( ! preg_match( '/^[A-Za-z0-9_-]{30,200}$/D', $token ) ) {
				return new \WP_Error( 'pchq_cdn_token', '令牌格式不对，请粘贴 Cloudflare 生成的 API 令牌（不是 Global API Key）。' );
			}
			$check = self::api( 'GET', '/user/tokens/verify', null, $token );
			if ( is_wp_error( $check ) ) {
				return new \WP_Error( 'pchq_cdn_token', '令牌验证没通过：' . $check->get_error_message() );
			}
			if ( 'active' !== ( $check['status'] ?? '' ) ) {
				return new \WP_Error( 'pchq_cdn_token', '这个令牌不是启用状态。' );
			}
			$entry['token_enc']   = Crypto::encrypt( $token );
			$entry['verified_at'] = time();
			$entry['account_id']  = '';
		}
		$entry['label'] = $label;
		if ( '' === $entry['id'] ) {
			$entry['id'] = 'cf' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 10 );
		}
		$accounts[ $entry['id'] ] = $entry;
		self::save_accounts( $accounts );
		// 换了令牌可能换了可见范围：让域名重新认一次账号。
		if ( '' !== $token ) {
			self::unbind_all( $entry['id'] );
		}
		return true;
	}

	public static function remove_account( string $id ): void {
		$accounts = self::accounts();
		if ( ! isset( $accounts[ $id ] ) ) {
			return;
		}
		unset( $accounts[ $id ] );
		self::save_accounts( $accounts );
		self::unbind_all( $id );
		if ( ! $accounts ) {
			$o            = self::raw();
			$o['enabled'] = false;
			$o['accounts'] = array();
			self::save_settings( $o );
		}
	}

	/** 清掉域名行上记的账号归属，下次处理时重新认。 */
	private static function unbind_all( string $id ): void {
		$all     = self::domains();
		$changed = false;
		foreach ( $all as $domain => $row ) {
			if ( (string) ( $row['account'] ?? '' ) === $id ) {
				unset( $all[ $domain ]['account'] );
				$changed = true;
			}
		}
		if ( $changed ) {
			update_option( self::DOMAINS, $all, false );
		}
	}

	/** @return true|\WP_Error */
	public static function update( bool $enabled, bool $create_zones ) {
		if ( $enabled && ! self::accounts() ) {
			return new \WP_Error( 'pchq_cdn_token', '请先添加至少一个 Cloudflare 账号。' );
		}
		$o                 = self::raw();
		$o['enabled']      = $enabled;
		$o['create_zones'] = $create_zones;
		self::save_settings( $o );
		return true;
	}

	// ---------- Cloudflare 接口 ----------

	/**
	 * 给其他模块（费用页）用的只读入口，和 api() 一样返回 result。
	 *
	 * $domain 传了就用那个域名所属账号的令牌；不传就是通用调用（只有一个账号时用它，
	 * 多个账号时用第一个）——多账号下凡是针对某个域名的调用都应该把域名传进来。
	 */
	public static function request( string $method, string $path, ?array $body = null, string $domain = '' ) {
		if ( '' === $domain ) {
			return self::api( $method, $path, $body );
		}
		$before = self::$ctx;
		$bound  = self::bind_domain( $domain );
		if ( is_wp_error( $bound ) ) {
			self::$ctx = $before;
			return $bound;
		}
		try {
			return self::api( $method, $path, $body );
		} finally {
			self::$ctx = $before;
		}
	}

	/**
	 * GraphQL 分析接口（流量字节数等）。@return array|\WP_Error data 部分。
	 */
	public static function graphql( string $query, array $variables ) {
		$token = self::token();
		if ( '' === $token ) {
			return new \WP_Error( 'pchq_cdn_token', '还没有设置 Cloudflare 令牌。' );
		}
		$res = wp_remote_post(
			'https://api.cloudflare.com/client/v4/graphql',
			array(
				'timeout' => 25,
				'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'query' => $query, 'variables' => $variables ) ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'pchq_cdn_network', '连不上 Cloudflare（' . $res->get_error_message() . '）。' );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'pchq_cdn_api', 'Cloudflare 分析接口返回了无法解析的内容（HTTP ' . (int) wp_remote_retrieve_response_code( $res ) . '）。' );
		}
		if ( ! empty( $data['errors'] ) ) {
			$msg = (string) ( $data['errors'][0]['message'] ?? 'unknown' );
			if ( false !== stripos( $msg, 'auth' ) || false !== stripos( $msg, 'permission' ) || false !== stripos( $msg, 'not authorized' ) ) {
				$msg = '令牌缺少 Zone · Analytics · Read 权限（' . $msg . '）。';
			}
			return new \WP_Error( 'pchq_cdn_api', $msg );
		}
		return is_array( $data['data'] ?? null ) ? $data['data'] : array();
	}

	/** @return array|\WP_Error Cloudflare 的 result；分页列表时返回 result 数组。 */
	private static function api( string $method, string $path, ?array $body = null, string $token = '' ) {
		$token = '' !== $token ? $token : self::token();
		if ( '' === $token ) {
			return new \WP_Error( 'pchq_cdn_token', '还没有设置 Cloudflare 令牌。' );
		}
		$base = (string) apply_filters( 'pchq_cdn_api_base', self::API ); // 测试时可指向假的接口。
		$res  = wp_remote_request(
			$base . $path,
			array(
				'method'  => $method,
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
				'body'    => null === $body ? null : wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'pchq_cdn_network', '连不上 Cloudflare（' . $res->get_error_message() . '）。' );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $data ) || empty( $data['success'] ) ) {
			$errors = is_array( $data['errors'] ?? null ) ? $data['errors'] : array();
			$first  = $errors[0] ?? array();
			$msg    = (string) ( $first['message'] ?? ( 'HTTP ' . $code ) );
			if ( 403 === $code || 10000 === (int) ( $first['code'] ?? 0 ) ) {
				$msg = '令牌权限不够（' . $msg . '）。请按页面说明给令牌加上对应权限。';
			}
			return new \WP_Error( 404 === $code ? 'pchq_cdn_404' : 'pchq_cdn_api', $msg, array( 'status' => $code, 'cf_code' => (int) ( $first['code'] ?? 0 ) ) );
		}
		return is_array( $data['result'] ?? null ) ? $data['result'] : array();
	}

	/** @return array|null|\WP_Error 这个域名的 zone；没有时（不允许自动添加）返回 null。 */
	private static function zone( string $domain, bool $create ) {
		$found = self::api( 'GET', '/zones?name=' . rawurlencode( $domain ) );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		if ( ! empty( $found[0]['id'] ) ) {
			return $found[0];
		}
		if ( ! $create ) {
			return null;
		}
		// 自动建 zone 要知道建在哪个 Cloudflare 账户下：按当前绑定的那个账号记，各记各的。
		$stored = self::accounts();
		$key    = '' !== self::$ctx && isset( $stored[ self::$ctx ] ) ? self::$ctx : (string) ( $stored ? reset( $stored )['id'] : '' );
		if ( '' === $key ) {
			return new \WP_Error( 'pchq_cdn_token', '还没有设置 Cloudflare 令牌。' );
		}
		$account_id = (string) $stored[ $key ]['account_id'];
		if ( '' === $account_id ) {
			$list = self::api( 'GET', '/accounts?per_page=5' );
			if ( is_wp_error( $list ) ) {
				return $list;
			}
			if ( 1 !== count( $list ) || empty( $list[0]['id'] ) ) {
				return new \WP_Error( 'pchq_cdn_account', 0 === count( $list ) ? '令牌看不到任何 Cloudflare 账户，不能自动添加域名。' : '令牌能看到多个 Cloudflare 账户，请把令牌限定到一个账户。' );
			}
			$account_id                    = (string) $list[0]['id'];
			$stored[ $key ]['account_id']  = $account_id;
			self::save_accounts( $stored );
		}
		return self::api( 'POST', '/zones', array( 'name' => $domain, 'account' => array( 'id' => $account_id ), 'type' => 'full' ) );
	}

	/** 这个名字下的 A 记录；同名下有 AAAA / CNAME 或多条 A 记录时返回错误（不做任何修改）。@return array|\WP_Error */
	private static function a_records( string $zone_id, string $name ) {
		$list = self::api( 'GET', '/zones/' . rawurlencode( $zone_id ) . '/dns_records?per_page=50&name=' . rawurlencode( $name ) );
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		$a = array();
		foreach ( $list as $r ) {
			$type = (string) ( $r['type'] ?? '' );
			if ( in_array( $type, array( 'AAAA', 'CNAME' ), true ) ) {
				return new \WP_Error( 'pchq_cdn_conflict', $name . ' 已有一条 ' . $type . ' 记录，会让顾客访问到别处。请先在 Cloudflare 里删掉它，再点“立即检查”。' );
			}
			if ( 'A' === $type ) {
				$a[] = $r;
			}
		}
		if ( count( $a ) > 1 ) {
			return new \WP_Error( 'pchq_cdn_conflict', $name . ' 有多条 A 记录，请在 Cloudflare 里只保留一条（或全部删掉）后再点“立即检查”。' );
		}
		return $a;
	}

	/**
	 * 建好或改好一条 A 记录。$proxied = null 时：新建用灰色，已有的保持原来的灰 / 橙（已经走 CDN 的不会被降级）。
	 * 同名下有其他类型的记录（AAAA、CNAME 等）就停下。@return true|\WP_Error
	 */
	private static function record( string $zone_id, string $name, string $ip, ?bool $proxied ) {
		$a = self::a_records( $zone_id, $name );
		if ( is_wp_error( $a ) ) {
			return $a;
		}
		if ( null === $proxied ) {
			$proxied = $a ? (bool) ( $a[0]['proxied'] ?? false ) : false;
		}
		$body = array( 'type' => 'A', 'name' => $name, 'content' => $ip, 'ttl' => 1, 'proxied' => $proxied, 'comment' => 'VialCove store (auto)' );
		if ( ! $a ) {
			$done = self::api( 'POST', '/zones/' . rawurlencode( $zone_id ) . '/dns_records', $body );
			return is_wp_error( $done ) ? $done : true;
		}
		if ( (string) $a[0]['content'] === $ip && (bool) ( $a[0]['proxied'] ?? false ) === $proxied ) {
			return true;
		}
		$done = self::api( 'PATCH', '/zones/' . rawurlencode( $zone_id ) . '/dns_records/' . rawurlencode( (string) $a[0]['id'] ), array( 'content' => $ip, 'proxied' => $proxied, 'ttl' => 1 ) );
		return is_wp_error( $done ) ? $done : true;
	}

	/** Cloudflare 边缘证书是否已生效（没生效就切橙色，顾客会看到证书错误）。 */
	private static function edge_ready( string $zone_id ): bool {
		$packs = self::api( 'GET', '/zones/' . rawurlencode( $zone_id ) . '/ssl/certificate_packs?status=active' );
		if ( is_wp_error( $packs ) ) {
			return false;
		}
		foreach ( $packs as $p ) {
			if ( 'active' === ( $p['status'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/** 店铺服务器上的 HTTPS 证书是否可用（直接连服务器 IP，按正常证书校验）。 */
	private static function origin_ready( string $domain, string $ip ): bool {
		$override = apply_filters( 'pchq_cdn_origin_ready', null, $domain, $ip ); // 只给测试用。
		if ( null !== $override ) {
			return (bool) $override;
		}
		// 直接连店铺服务器 IP 检查 HTTPS 证书。总后台关闭了 WordPress 的外部请求（只允许白名单），
		// 店铺新域名不在白名单里，所以这里用 cURL 直连：只连这个 IP、只用 HTTPS、按正常证书校验。
		if ( ! function_exists( 'curl_init' ) ) {
			return false;
		}
		$ch = curl_init( 'https://www.' . $domain . '/' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init
		curl_setopt_array( // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
			$ch,
			array(
				CURLOPT_RESOLVE        => array( 'www.' . $domain . ':443:' . $ip ),
				CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_TIMEOUT        => 15,
				CURLOPT_CONNECTTIMEOUT => 8,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_USERAGENT      => 'VialCove-HQ-CDN-Check/1.0',
				CURLOPT_RANGE          => '0-1023',
			)
		);
		curl_exec( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec
		$errno = curl_errno( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_errno
		$code  = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo
		curl_close( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close
		return 0 === $errno && $code >= 200 && $code < 500;
	}

	/** 和主店同样的“绕过缓存”规则，只换域名。 */
	public static function bypass_expression( string $domain ): string {
		$file = dirname( __DIR__ ) . '/assets/cdn/storefront-cache-bypass.txt';
		$tpl  = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return trim( str_replace( '{{HOSTS}}', '"' . $domain . '" "www.' . $domain . '"', $tpl ) );
	}

	/** 写入（或更新）自己那条缓存规则，保留这个域名里的其他规则。@return true|\WP_Error */
	private static function cache_rule( string $zone_id, string $domain ) {
		$expr = self::bypass_expression( $domain );
		if ( '' === $expr ) {
			return new \WP_Error( 'pchq_cdn_rule', '缓存规则模板缺失。' );
		}
		$path  = '/zones/' . rawurlencode( $zone_id ) . '/rulesets/phases/http_request_cache_settings/entrypoint';
		$entry = self::api( 'GET', $path );
		$rules = array();
		if ( is_wp_error( $entry ) ) {
			if ( 'pchq_cdn_404' !== $entry->get_error_code() ) {
				return $entry;
			}
		} else {
			foreach ( (array) ( $entry['rules'] ?? array() ) as $r ) {
				if ( self::RULE === ( $r['ref'] ?? '' ) || self::RULE === ( $r['description'] ?? '' ) ) {
					continue;
				}
				$rules[] = array_intersect_key( $r, array_flip( array( 'id', 'ref', 'expression', 'action', 'action_parameters', 'description', 'enabled' ) ) );
			}
		}
		$rules[] = array(
			'ref'               => self::RULE,
			'description'       => self::RULE,
			'expression'        => $expr,
			'action'            => 'set_cache_settings',
			'action_parameters' => array( 'cache' => false ),
			'enabled'           => true,
		);
		$done = self::api( 'PUT', $path, array( 'rules' => $rules ) );
		return is_wp_error( $done ) ? $done : true;
	}

	// ---------- 每个域名的进度 ----------

	public static function domains(): array {
		$d = get_option( self::DOMAINS, array() );
		return is_array( $d ) ? $d : array();
	}

	private static function set_domain( string $domain, array $fields ): void {
		$all            = self::domains();
		$all[ $domain ] = array_merge( $all[ $domain ] ?? array(), $fields, array( 'updated' => time() ) );
		update_option( self::DOMAINS, $all, false );
	}

	public static function forget( string $domain ): void {
		$all = self::domains();
		unset( $all[ $domain ] );
		update_option( self::DOMAINS, $all, false );
	}

	public const STAGES = array(
		'new'       => '等待处理',
		'no_zone'   => '域名还不在 Cloudflare',
		'ns'        => '等你改 NS',
		'grey'      => '已解析（灰色），等店铺开好',
		'edge_wait' => '等 Cloudflare 证书生效',
		'done'      => 'CDN 已开启',
		'error'     => '需要处理',
	);

	/**
	 * 推进一个域名。$store_ready = 店铺已开好（服务器证书已申请）。
	 *
	 * @return array 这个域名最新的进度
	 */
	/**
	 * 机器人拦截（2026-11-08）：Bot Fight Mode 把已知爬虫、扫描器挡在店铺之外。令牌没有 Bot Management 权限时
	 * 只记录提示，不影响 CDN 开通。返回 true=已开启，false=没开成（原因写进 pchq_cdn_bot 选项）。
	 */
	public static function bot_protect( string $zid ): bool {
		// Cloudflare refuses fight_mode unless its JavaScript detection is on; also block AI scrapers.
		$r = self::api( 'PUT', '/zones/' . rawurlencode( $zid ) . '/bot_management', array( 'fight_mode' => true, 'enable_js' => true, 'ai_bots_protection' => 'block' ) );
		$state = get_option( 'pchq_cdn_bot', array() );
		$state = is_array( $state ) ? $state : array();
		if ( is_wp_error( $r ) ) {
			$state[ $zid ] = array( 'ok' => false, 'message' => $r->get_error_message(), 'at' => time() );
			update_option( 'pchq_cdn_bot', $state, false );
			return false;
		}
		$state[ $zid ] = array( 'ok' => true, 'message' => '', 'at' => time() );
		update_option( 'pchq_cdn_bot', $state, false );
		return true;
	}

	/** 给所有已在 Cloudflare 的域名开机器人拦截。@return array<string,bool|string> 域名 => true 或失败原因 */
	public static function bot_protect_all(): array {
		$out    = array();
		$before = self::$ctx;
		try {
			foreach ( array_keys( self::accounts() ) as $id ) {
				self::$ctx = (string) $id;
				$out      += self::bot_protect_account();
			}
		} finally {
			self::$ctx = $before;
		}
		return $out;
	}

	/** 当前绑定账号下的所有 zone。 */
	private static function bot_protect_account(): array {
		$out = array();
		$r   = self::api( 'GET', '/zones?per_page=50' );
		if ( is_wp_error( $r ) ) {
			return array( '*' => $r->get_error_message() );
		}
		// api() already unwraps Cloudflare's {result: ...} envelope.
		$zones = isset( $r['result'] ) && is_array( $r['result'] ) ? $r['result'] : $r;
		foreach ( (array) $zones as $z ) {
			if ( ! is_array( $z ) || empty( $z['id'] ) || 'active' !== ( $z['status'] ?? '' ) ) {
				continue;
			}
			foreach ( array( 'browser_check' => 'on' ) as $setting => $value ) {
				self::api( 'PATCH', '/zones/' . rawurlencode( (string) $z['id'] ) . '/settings/' . $setting, array( 'value' => $value ) );
			}
			$ok = self::bot_protect( (string) $z['id'] );
			$out[ (string) $z['name'] ] = $ok ? true : (string) ( get_option( 'pchq_cdn_bot' )[ $z['id'] ]['message'] ?? '失败' );
		}
		return $out;
	}

	public static function process( string $domain, string $ip, bool $store_ready, array $extra = array() ): array {
		$domain = Admin\Provision_Page::customer_domain( $domain );
		if ( '' === $domain || ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return array( 'stage' => 'error', 'message' => '域名或服务器 IP 无效。' );
		}
		$fail = static function ( \WP_Error $e ) use ( $domain, $ip, $extra ): array {
			self::set_domain( $domain, array_merge( $extra, array( 'ip' => $ip, 'stage' => 'error', 'message' => $e->get_error_message() ) ) );
			return self::domains()[ $domain ];
		};
		// 一人一号：先认出这个域名归哪个 Cloudflare 账号，这一轮的调用都用那个账号的令牌。
		$before = self::$ctx;
		$bound  = self::bind_domain( $domain );
		if ( is_wp_error( $bound ) ) {
			self::$ctx = $before;
			return $fail( $bound );
		}
		try {
			return self::process_bound( $domain, $ip, $store_ready, $extra, $fail );
		} finally {
			self::$ctx = $before;
		}
	}

	/** process() 认完账号之后的正文；拆出来是为了让账号绑定有个干净的 finally。 */
	private static function process_bound( string $domain, string $ip, bool $store_ready, array $extra, callable $fail ): array {
		$zone = self::zone( $domain, self::settings()['create_zones'] );
		if ( is_wp_error( $zone ) ) {
			return $fail( $zone );
		}
		if ( null === $zone ) {
			self::set_domain( $domain, array_merge( $extra, array( 'ip' => $ip, 'stage' => 'no_zone', 'message' => '请先在 Cloudflare 添加这个域名（或在本页打开“自动添加新域名”）。' ) ) );
			return self::domains()[ $domain ];
		}
		$zid = (string) $zone['id'];
		$ns  = array_values( array_filter( array_map( 'strval', (array) ( $zone['name_servers'] ?? array() ) ) ) );
		$done_already = 'done' === ( self::domains()[ $domain ]['stage'] ?? '' );
		// 先检查两个名字都没有冲突，再动任何记录。
		foreach ( array( $domain, 'www.' . $domain ) as $name ) {
			$check = self::a_records( $zid, $name );
			if ( is_wp_error( $check ) ) {
				return $fail( $check );
			}
		}
		// 新建的记录先用灰色；已有记录保持原来的灰 / 橙，已经走 CDN 的域名不会被降级。
		foreach ( array( $domain, 'www.' . $domain ) as $name ) {
			$r = self::record( $zid, $name, $ip, null );
			if ( is_wp_error( $r ) ) {
				return $fail( $r );
			}
		}
		$base = array_merge( $extra, array( 'ip' => $ip, 'zone_id' => $zid, 'ns' => $ns, 'message' => '' ) );
		if ( 'active' !== ( $zone['status'] ?? '' ) ) {
			self::set_domain( $domain, array_merge( $base, array( 'stage' => 'ns', 'message' => '请到域名注册商把 NS 改成：' . implode( '、', $ns ) . '。改好后通常几分钟到几小时生效，生效后会自动继续。' ) ) );
			return self::domains()[ $domain ];
		}
		if ( $done_already ) {
			self::set_domain( $domain, array_merge( $base, array( 'stage' => 'done' ) ) );
			return self::domains()[ $domain ];
		}
		if ( ! $store_ready || ! self::origin_ready( $domain, $ip ) ) {
			self::set_domain( $domain, array_merge( $base, array( 'stage' => 'grey', 'message' => $store_ready ? '店铺已开好，等服务器 HTTPS 证书可用。' : '解析已就绪，开店程序正在申请 HTTPS 证书。' ) ) );
			return self::domains()[ $domain ];
		}
		if ( ! self::edge_ready( $zid ) ) {
			self::set_domain( $domain, array_merge( $base, array( 'stage' => 'edge_wait', 'message' => 'Cloudflare 正在为这个域名签发证书，通常 15 分钟内完成。' ) ) );
			return self::domains()[ $domain ];
		}
		foreach ( array( 'ssl' => 'strict', 'browser_check' => 'on' ) as $setting => $value ) {
			$r = self::api( 'PATCH', '/zones/' . rawurlencode( $zid ) . '/settings/' . $setting, array( 'value' => $value ) );
			if ( is_wp_error( $r ) ) {
				return $fail( $r );
			}
		}
		self::bot_protect( $zid );
		$r = self::cache_rule( $zid, $domain );
		if ( is_wp_error( $r ) ) {
			return $fail( $r );
		}
		foreach ( array( $domain, 'www.' . $domain ) as $name ) {
			$r = self::record( $zid, $name, $ip, true );
			if ( is_wp_error( $r ) ) {
				return $fail( $r );
			}
		}
		self::set_domain( $domain, array_merge( $base, array( 'stage' => 'done', 'message' => '', 'done_at' => time() ) ) );
		// CDN 开好后顺手把这个域名接进 Cloudflare 邮件发送并建 orders@ 发件地址（失败只记状态，不影响开店）。
		if ( class_exists( Mail_Domains::class ) ) {
			Mail_Domains::apply( $domain, $zid );
		}
		return self::domains()[ $domain ];
	}

	/** 开店任务里带客户域名的：域名、服务器 IP、是否已开好。只读开店服务写的公开结果。 */
	public static function provision_jobs(): array {
		$root = defined( 'PEPTIDE_HQ_PROVISION_QUEUE' ) ? rtrim( (string) PEPTIDE_HQ_PROVISION_QUEUE, '/' ) : '';
		if ( '' === $root || ! is_dir( $root . '/results' ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) glob( $root . '/results/*.json' ) as $path ) {
			if ( is_link( $path ) || ! is_file( $path ) || filesize( $path ) > 8192 ) {
				continue;
			}
			$r = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( ! is_array( $r ) ) {
				continue;
			}
			$r = Admin\Provision_Page::safe_result( $r );
			if ( empty( $r['customer_domain'] ) || empty( $r['dns_records'][0]['value'] ) ) {
				continue;
			}
			$out[ $r['customer_domain'] ] = array(
				'ip'      => (string) $r['dns_records'][0]['value'],
				'ready'   => 'complete' === ( $r['state'] ?? '' ),
				'site_id' => (int) ( $r['site_id'] ?? 0 ),
				'job'     => basename( $path, '.json' ),
			);
		}
		return $out;
	}

	/** 定时：自动配置打开时，推进所有开店域名和手动添加的域名（已开启 CDN 的不再反复调用）。 */
	public static function tick(): void {
		$s = self::settings();
		if ( ! $s['enabled'] || ! $s['accounts'] ) {
			return;
		}
		$jobs  = self::provision_jobs();
		$known = self::domains();
		foreach ( $jobs as $domain => $job ) {
			if ( 'done' === ( $known[ $domain ]['stage'] ?? '' ) ) {
				continue;
			}
			self::process( $domain, $job['ip'], $job['ready'], array( 'source' => 'provision', 'site_id' => $job['site_id'] ) );
		}
		foreach ( $known as $domain => $row ) {
			if ( isset( $jobs[ $domain ] ) || 'done' === ( $row['stage'] ?? '' ) || 'manual' !== ( $row['source'] ?? '' ) ) {
				continue;
			}
			self::process( (string) $domain, (string) ( $row['ip'] ?? '' ), true, array( 'source' => 'manual' ) );
		}
		if ( class_exists( Mail_Domains::class ) ) {
			Mail_Domains::backfill();
		}
	}
}
