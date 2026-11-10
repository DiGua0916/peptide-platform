<?php
/**
 * 总后台 AI（2026-11-10，店主：AI 统一在总后台设置，并且要有人设）。
 *
 * - 服务商、模型、API 密钥只存在总后台（密钥用 Crypto 加密），店铺和浏览器都拿不到密钥。
 * - 人设库：每个人设 = 名字 + 用途（customer 客户分析 / business 全站分析师）+ 人设正文（作为系统提示词）。
 *   可以粘贴或上传 .txt / .md 导入；每家店按用途选人设，不选就用该用途的第一个。
 * - 店铺用连接密钥签名后请求 /peptide-hq/v1/ai/analyse（验签同邮件中心），只发行为事实，不发姓名邮箱电话。
 * - 不限每天次数（店主 2026-11-10），只按店记每天用量。
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Ai {

	public const OPTION   = 'pchq_ai';
	public const PERSONAS = 'pchq_ai_personas';
	public const USES     = array( 'customer' => '客户分析', 'business' => '全站分析师' );
	/** 内置服务商（店主 2026-11-10：Claude、OpenAI 由我们配好；其他的店主在“服务商设置”里自己加）。 */
	public const BUILTIN = array(
		'anthropic' => array( 'label' => 'Claude（Anthropic）', 'format' => 'anthropic', 'endpoint' => 'https://api.anthropic.com/v1/messages', 'models' => 'https://api.anthropic.com/v1/models' ),
		'openai'    => array( 'label' => 'OpenAI', 'format' => 'openai', 'endpoint' => 'https://api.openai.com/v1/chat/completions', 'models' => 'https://api.openai.com/v1/models' ),
	);
	public const FORMATS   = array( 'openai' => 'OpenAI 兼容（/chat/completions）', 'anthropic' => 'Anthropic 兼容（/messages）' );
	/** 不兼容 OpenAI / Anthropic 格式的服务商用原生接口（店主 2026-11-10：兼容接口要能关掉）。 */
	public const NATIVE    = array( 'gemini' => 'Google Gemini 原生接口' );

	public static function format_label( string $format ): string {
		return self::FORMATS[ $format ] ?? self::NATIVE[ $format ] ?? $format;
	}
	public const LANGUAGES = array( '中文' => '中文', 'English' => 'English' );
	public const CUSTOM    = 'pchq_ai_providers';
	private const MAX_PERSONA = 60000;

	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	// ---------- 服务商 ----------

	/** @return array<string,array{id:string,label:string,format:string,endpoint:string,models:string,builtin:bool}> */
	public static function providers(): array {
		$out = array();
		foreach ( self::BUILTIN as $id => $p ) {
			$out[ $id ] = array( 'id' => $id, 'builtin' => true ) + $p;
		}
		$custom = get_option( self::CUSTOM, array() );
		foreach ( is_array( $custom ) ? $custom : array() as $p ) {
			if ( is_array( $p ) && ! empty( $p['id'] ) && ! isset( $out[ $p['id'] ] ) ) {
				$out[ (string) $p['id'] ] = array(
					'id'       => (string) $p['id'],
					'label'    => (string) $p['label'],
					'format'   => isset( self::FORMATS[ $p['format'] ?? '' ] ) || isset( self::NATIVE[ $p['format'] ?? '' ] ) ? (string) $p['format'] : 'openai',
					'endpoint' => (string) $p['endpoint'],
					'models'   => (string) ( $p['models'] ?? '' ),
					'builtin'  => false,
				);
			}
		}
		return $out;
	}

	private static function https( string $url ): string {
		$url = esc_url_raw( trim( $url ) );
		return '' !== $url && 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) ? $url : '';
	}

	/** 店主自己加的服务商：名字、接口类型、对话接口地址、模型列表地址（可空，按接口地址推）。 */
	public static function add_provider( array $in ): array|\WP_Error {
		$label    = mb_substr( sanitize_text_field( (string) ( $in['label'] ?? '' ) ), 0, 40 );
		// 兼容接口开：OpenAI / Anthropic 格式二选一；关：用原生接口。
		$format   = ! empty( $in['compat'] )
			? ( isset( self::FORMATS[ $in['format'] ?? '' ] ) ? (string) $in['format'] : 'openai' )
			: ( isset( self::NATIVE[ $in['native'] ?? '' ] ) ? (string) $in['native'] : 'gemini' );
		$endpoint = self::https( (string) ( $in['endpoint'] ?? '' ) );
		$models   = self::https( (string) ( $in['models'] ?? '' ) );
		if ( '' === $label || '' === $endpoint ) {
			return new \WP_Error( 'pchq_ai_provider', '名字和接口地址都要填，接口地址必须是 https 开头。' );
		}
		$custom   = get_option( self::CUSTOM, array() );
		$custom   = is_array( $custom ) ? array_values( $custom ) : array();
		$row      = array( 'id' => 'c' . substr( md5( uniqid( '', true ) ), 0, 8 ), 'label' => $label, 'format' => $format, 'endpoint' => $endpoint, 'models' => $models );
		$custom[] = $row;
		update_option( self::CUSTOM, $custom, false );
		return $row;
	}

	public static function delete_provider( string $id ): void {
		if ( isset( self::BUILTIN[ $id ] ) ) {
			return;
		}
		$custom = get_option( self::CUSTOM, array() );
		update_option( self::CUSTOM, array_values( array_filter( is_array( $custom ) ? $custom : array(), static fn( $p ) => ( $p['id'] ?? '' ) !== $id ) ), false );
		$raw = get_option( self::OPTION, array() );
		if ( is_array( $raw ) ) {
			unset( $raw['keys'][ $id ] );
			if ( ( $raw['provider'] ?? '' ) === $id ) {
				$raw['provider'] = 'anthropic';
				$raw['model']    = '';
			}
			update_option( self::OPTION, $raw, false );
		}
	}

	/**
	 * 请求 AI 服务商（2026-11-10）。总后台开着 WP_HTTP_BLOCK_EXTERNAL，白名单那一行由开店脚本维护，不能改格式；
	 * 所以 AI 请求不走 WordPress 的白名单，而是在这里自己把关：只许 https、只许已配置服务商（内置 + 服务商设置里加的）
	 * 的主机、且地址必须解析到公网（wp_http_validate_url），不跟随跳转、限制返回大小。其他出站请求仍受白名单限制。
	 *
	 * @return array{code:int,body:string}|\WP_Error
	 */
	private static function provider_http( string $method, string $url, array $headers, ?string $body, int $timeout ) {
		$host    = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$allowed = array();
		foreach ( self::providers() as $p ) {
			foreach ( array( $p['endpoint'], $p['models'] ) as $u ) {
				if ( '' !== $u ) {
					$allowed[] = strtolower( (string) wp_parse_url( $u, PHP_URL_HOST ) );
				}
			}
		}
		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) || '' === $host || ! in_array( $host, $allowed, true ) || ! wp_http_validate_url( $url ) ) {
			return new \WP_Error( 'pchq_ai_host', '不是已配置的 AI 服务商地址：' . $host );
		}
		try {
			$r = \WpOrg\Requests\Requests::request(
				$url,
				$headers + array( 'User-Agent' => 'VialCove-HQ/1.0' ),
				null === $body ? array() : $body,
				'POST' === $method ? \WpOrg\Requests\Requests::POST : \WpOrg\Requests\Requests::GET,
				array( 'timeout' => $timeout, 'connect_timeout' => 10, 'follow_redirects' => false, 'verify' => ABSPATH . WPINC . '/certificates/ca-bundle.crt', 'max_bytes' => 2097152 )
			);
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'pchq_ai_http', $e->getMessage() );
		}
		return array( 'code' => (int) $r->status_code, 'body' => (string) $r->body );
	}

	// ---------- 设置 ----------

	public static function settings(): array {
		$s    = get_option( self::OPTION, array() );
		$s    = is_array( $s ) ? $s : array();
		$all  = self::providers();
		$p    = isset( $all[ $s['provider'] ?? '' ] ) ? (string) $s['provider'] : 'anthropic';
		$keys = is_array( $s['keys'] ?? null ) ? $s['keys'] : array();
		return array(
			'enabled'    => ! empty( $s['enabled'] ),
			'provider'   => $p,
			'format'     => $all[ $p ]['format'],
			'endpoint'   => $all[ $p ]['endpoint'], // 跟着服务商自动走，不用手填。
			'model'      => (string) ( $s['model'] ?? '' ),
			'language'   => isset( self::LANGUAGES[ $s['language'] ?? '' ] ) ? (string) $s['language'] : '中文',
			'max_tokens' => max( 1000, min( 32000, (int) ( $s['max_tokens'] ?? 8000 ) ) ), // 推理模型先“思考”再回答，思考也占长度，所以默认 8000。
			'has_key'    => '' !== (string) ( $keys[ $p ] ?? '' ),
			'auto_customer' => ! array_key_exists( 'auto_customer', $s ) || ! empty( $s['auto_customer'] ), // 自动分析默认开（店主 2026-11-10）
			'auto_site'     => ! array_key_exists( 'auto_site', $s ) || ! empty( $s['auto_site'] ),
			'key_set'    => array_keys( array_filter( $keys ) ), // 哪些服务商存了密钥（不含密钥本身）。
			'sites'      => is_array( $s['sites'] ?? null ) ? $s['sites'] : array(), // site_id => {enabled, customer, business}
		);
	}

	public static function save_settings( array $in ): void {
		$raw = get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();
		$all = self::providers();
		$p   = isset( $all[ $in['provider'] ?? '' ] ) ? (string) $in['provider'] : 'anthropic';
		$changed           = ( $raw['provider'] ?? 'anthropic' ) !== $p;
		$raw['enabled']    = ! empty( $in['enabled'] );
		$raw['provider']   = $p;
		$raw['model']      = $changed ? '' : substr( preg_replace( '/[^A-Za-z0-9._:\/-]/', '', (string) ( $in['model'] ?? '' ) ), 0, 120 );
		$raw['language']   = isset( self::LANGUAGES[ $in['language'] ?? '' ] ) ? (string) $in['language'] : '中文';
		unset( $raw['daily_cap'] ); // 店主 2026-11-10：不限每天次数，只记用量。
		$raw['auto_customer'] = ! empty( $in['auto_customer'] );
		$raw['auto_site']     = ! empty( $in['auto_site'] );
		$raw['max_tokens'] = (int) ( $in['max_tokens'] ?? 8000 );
		unset( $raw['endpoint'], $raw['key'] );
		$raw['keys'] = is_array( $raw['keys'] ?? null ) ? $raw['keys'] : array();
		$key         = trim( (string) ( $in['key'] ?? '' ) );
		if ( ! empty( $in['clear_key'] ) ) {
			unset( $raw['keys'][ $p ] );
		} elseif ( '' !== $key ) {
			$raw['keys'][ $p ] = Crypto::encrypt( $key ); // 密钥按服务商分开存；空白 = 保留原来的。
		}
		if ( isset( $in['sites'] ) && is_array( $in['sites'] ) ) {
			$ids   = array_column( self::personas(), 'id' );
			$sites = array();
			foreach ( Sites::all() as $site ) {
				$row = (array) ( $in['sites'][ $site['id'] ] ?? array() );
				$sites[ (int) $site['id'] ] = array(
					'enabled'  => ! empty( $row['enabled'] ),
					'customer' => in_array( (string) ( $row['customer'] ?? '' ), $ids, true ) ? (string) $row['customer'] : '',
					'business' => in_array( (string) ( $row['business'] ?? '' ), $ids, true ) ? (string) $row['business'] : '',
				);
			}
			$raw['sites'] = $sites;
		}
		update_option( self::OPTION, $raw, false );
	}

	private static function key(): string {
		$raw = get_option( self::OPTION, array() );
		$p   = self::settings()['provider'];
		return is_array( $raw ) && '' !== (string) ( $raw['keys'][ $p ] ?? '' ) ? Crypto::decrypt( (string) $raw['keys'][ $p ] ) : '';
	}

	/** 某家店：开没开、各用途用哪个人设。新店铺默认开（总开关开着时）。 */
	// ---------- 每家店自己的人设（店主 2026-11-10） ----------
	// 总后台的人设是模板：和店铺模板一样，第一次只下发一份给这家店。之后这家店用自己的那份，
	// 再加上店主在店铺后台写的“人设调优”（只影响这家店）。总后台改模板不影响已下发的店，除非点“重新下发”或换了模板。

	public const SITE_PERSONAS = 'pchq_ai_site_personas';

	public static function site_personas(): array {
		$v = get_option( self::SITE_PERSONAS, array() );
		return is_array( $v ) ? $v : array();
	}

	/** 这家店某种用途的人设副本；还没有（或总后台给它换了模板）就从模板下发一份。 */
	public static function site_persona( int $site_id, string $use, bool $issue = true ): ?array {
		$all  = self::site_personas();
		$copy = $all[ $site_id ][ $use ] ?? null;
		$s    = self::settings();
		$row  = $s['sites'][ $site_id ] ?? null;
		$pick = is_array( $row ) ? (string) ( $row[ $use ] ?? '' ) : '';
		$tpl  = '' !== $pick ? self::persona( $pick ) : null;
		$tpl  = $tpl && $tpl['use'] === $use ? $tpl : self::default_persona( $use );
		$switched = is_array( $copy ) && '' !== $pick && $tpl && $copy['from'] !== $tpl['id'];
		if ( ( ! is_array( $copy ) || $switched ) && $tpl && $issue ) {
			$copy = self::issue( $site_id, $use, $tpl );
		}
		return is_array( $copy ) ? $copy : null;
	}

	/** 把模板下发（或重新下发）给这家店；店主写的人设调优保留。 */
	public static function issue( int $site_id, string $use, ?array $tpl = null ): ?array {
		$tpl = $tpl ?: self::default_persona( $use );
		if ( ! $tpl || ! isset( self::USES[ $use ] ) ) {
			return null;
		}
		$all  = self::site_personas();
		$old  = $all[ $site_id ][ $use ] ?? array();
		$all[ $site_id ][ $use ] = array( 'from' => $tpl['id'], 'name' => $tpl['name'], 'content' => $tpl['content'], 'issued_at' => time(), 'tuning' => (string) ( $old['tuning'] ?? '' ), 'tuned_at' => (int) ( $old['tuned_at'] ?? 0 ) );
		update_option( self::SITE_PERSONAS, $all, false );
		return $all[ $site_id ][ $use ];
	}

	/** 记下这家店最新的人设调优（店铺每次分析时带过来，总后台只用来展示）。 */
	private static function note_tuning( int $site_id, string $use, string $tuning ): void {
		$all = self::site_personas();
		if ( ! isset( $all[ $site_id ][ $use ] ) || ( $all[ $site_id ][ $use ]['tuning'] ?? '' ) === $tuning ) {
			return;
		}
		$all[ $site_id ][ $use ]['tuning']   = $tuning;
		$all[ $site_id ][ $use ]['tuned_at'] = time();
		update_option( self::SITE_PERSONAS, $all, false );
	}

	public static function site_config( int $site_id ): array {
		$s    = self::settings();
		$row  = $s['sites'][ $site_id ] ?? null;
		$on   = $s['enabled'] && $s['has_key'] && '' !== $s['model'] && ( null === $row || ! empty( $row['enabled'] ) );
		$out  = array( 'enabled' => $on, 'auto_customer' => $s['auto_customer'], 'auto_site' => $s['auto_site'] );
		foreach ( array_keys( self::USES ) as $use ) {
			$p = self::site_persona( $site_id, $use, $on );
			if ( ! $p ) {
				$id = is_array( $row ) ? (string) ( $row[ $use ] ?? '' ) : '';
				$t  = '' !== $id ? self::persona( $id ) : null;
				$t  = $t && $t['use'] === $use ? $t : self::default_persona( $use );
				$p  = $t ? array( 'from' => $t['id'], 'name' => $t['name'] ) : null;
			}
			$out[ $use ] = $p ? array( 'id' => $p['from'], 'name' => $p['name'] ) : null;
		}
		return $out;
	}

	// ---------- 模型列表（从服务商接口拉取，2026-11-10 店主：不要手填） ----------

	public const MODELS = 'pchq_ai_models';

	/** 当前服务商上次拉到的模型：{provider, fetched, list:[{id,name}]} */
	public static function models(): array {
		$m = get_option( self::MODELS, array() );
		$p = self::settings()['provider'];
		return is_array( $m ) && is_array( $m[ $p ] ?? null ) ? $m[ $p ] : array( 'fetched' => 0, 'list' => array() );
	}

	/** 用保存的密钥拉模型列表；当前模型不在列表里时自动选一个推荐的。 */
	public static function refresh_models(): array|\WP_Error {
		$s   = self::settings();
		$pv  = self::providers()[ $s['provider'] ];
		$key = self::key();
		if ( '' === $key ) {
			return new \WP_Error( 'pchq_ai_key', '先填' . $pv['label'] . '的 API 密钥并保存，才能拉取模型列表。' );
		}
		if ( 'gemini' === $s['format'] ) {
			$url     = add_query_arg( 'pageSize', 1000, '' !== $pv['models'] ? $pv['models'] : untrailingslashit( preg_replace( '#/models/.*$#', '', $pv['endpoint'] ) ) . '/models' );
			$headers = array( 'x-goog-api-key' => $key );
		} elseif ( 'anthropic' === $s['format'] ) {
			$url     = add_query_arg( 'limit', 1000, '' !== $pv['models'] ? $pv['models'] : preg_replace( '#/messages/?$#', '/models', $pv['endpoint'] ) );
			$headers = array( 'x-api-key' => $key, 'anthropic-version' => '2023-06-01' );
		} else {
			$url     = '' !== $pv['models'] ? $pv['models'] : preg_replace( '#/chat/completions/?$#', '/models', $pv['endpoint'] );
			$headers = array( 'Authorization' => 'Bearer ' . $key );
		}
		$res = self::provider_http( 'GET', $url, $headers, null, 20 );
		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'pchq_ai_http', '连不上 AI 服务：' . $res->get_error_message() );
		}
		$code = (int) $res['code'];
		$json = json_decode( (string) $res['body'], true );
		if ( 200 !== $code || ! is_array( $json ) ) {
			$msg = is_array( $json ) ? (string) ( $json['error']['message'] ?? $json['message'] ?? '' ) : '';
			return new \WP_Error( 'pchq_ai_http', '拉取模型失败（HTTP ' . $code . '）' . ( '' !== $msg ? '：' . mb_substr( $msg, 0, 300 ) : '' ) );
		}
		$list = array();
		foreach ( (array) ( $json['data'] ?? $json['models'] ?? array() ) as $m ) {
			if ( 'gemini' === $s['format'] && is_array( $m ) && ! in_array( 'generateContent', (array) ( $m['supportedGenerationMethods'] ?? array() ), true ) ) {
				continue; // Gemini 只留能生成文字的模型。
			}
			$id = is_array( $m ) ? (string) ( $m['id'] ?? preg_replace( '#^models/#', '', (string) ( $m['name'] ?? '' ) ) ) : '';
			if ( '' === $id || ! preg_match( '/^[A-Za-z0-9._:\/-]{1,120}$/', $id ) ) {
				continue;
			}
			// OpenAI 兼容的列表里常有向量、语音、画图等模型，只留能对话的。
			if ( 'openai' === $s['format'] && preg_match( '/embedding|whisper|tts|dall-e|image|audio|moderation|transcribe|realtime|search|babbage|davinci/i', $id ) ) {
				continue;
			}
			$list[] = array( 'id' => $id, 'name' => (string) ( $m['display_name'] ?? $m['displayName'] ?? $id ) );
		}
		if ( ! $list ) {
			return new \WP_Error( 'pchq_ai_models', '接口没有返回可用的模型。' );
		}
		$all = get_option( self::MODELS, array() );
		$all = is_array( $all ) && ! isset( $all['list'] ) ? $all : array();
		$all[ $s['provider'] ] = array( 'fetched' => time(), 'list' => $list );
		update_option( self::MODELS, $all, false );
		$ids = array_column( $list, 'id' );
		if ( ! in_array( $s['model'], $ids, true ) ) {
			$pick = $ids[0];
			foreach ( array( '/sonnet/i', '/gpt-5(?!.*(nano|mini))/i', '/gpt-4o(?!-mini)/i', '/gemini-.*pro/i' ) as $re ) {
				$hit = preg_grep( $re, $ids );
				if ( $hit ) {
					$pick = reset( $hit );
					break;
				}
			}
			$raw          = get_option( self::OPTION, array() );
			$raw          = is_array( $raw ) ? $raw : array();
			$raw['model'] = $pick;
			update_option( self::OPTION, $raw, false );
		}
		return $list;
	}

	// ---------- 人设 ----------

	/** @return array<int,array{id:string,name:string,use:string,content:string,updated:int}> */
	public static function personas(): array {
		$p = get_option( self::PERSONAS, array() );
		return is_array( $p ) ? array_values( $p ) : array();
	}

	public static function persona( string $id ): ?array {
		foreach ( self::personas() as $p ) {
			if ( $p['id'] === $id ) {
				return $p;
			}
		}
		return null;
	}

	public static function default_persona( string $use ): ?array {
		foreach ( self::personas() as $p ) {
			if ( $p['use'] === $use ) {
				return $p;
			}
		}
		return null;
	}

	/** 新建 / 修改人设。$in: id(可空) name use content */
	public static function save_persona( array $in ): array|\WP_Error {
		$name    = mb_substr( sanitize_text_field( (string) ( $in['name'] ?? '' ) ), 0, 60 );
		$use     = isset( self::USES[ $in['use'] ?? '' ] ) ? (string) $in['use'] : 'customer';
		$content = trim( str_replace( "\r\n", "\n", (string) ( $in['content'] ?? '' ) ) );
		$content = wp_check_invalid_utf8( $content );
		if ( '' === $name || '' === $content ) {
			return new \WP_Error( 'pchq_ai_persona', '人设名字和正文都要填。' );
		}
		if ( strlen( $content ) > self::MAX_PERSONA ) {
			return new \WP_Error( 'pchq_ai_persona', '人设正文太长（最多约 6 万字节）。' );
		}
		$id   = sanitize_key( (string) ( $in['id'] ?? '' ) );
		$list = self::personas();
		$row  = array( 'id' => '' !== $id ? $id : 'p' . substr( md5( uniqid( '', true ) ), 0, 10 ), 'name' => $name, 'use' => $use, 'content' => $content, 'updated' => time() );
		$done = false;
		foreach ( $list as $i => $p ) {
			if ( $p['id'] === $row['id'] ) {
				$list[ $i ] = $row;
				$done       = true;
			}
		}
		if ( ! $done ) {
			$list[] = $row;
		}
		update_option( self::PERSONAS, array_values( $list ), false );
		return $row;
	}

	public static function delete_persona( string $id ): void {
		update_option( self::PERSONAS, array_values( array_filter( self::personas(), static fn( $p ) => $p['id'] !== $id ) ), false );
	}

	// ---------- 用量 ----------

	private static function usage_key( int $site_id ): string {
		return 'pchq_ai_use_' . $site_id . '_' . wp_date( 'Ymd' );
	}

	public static function used_today( int $site_id ): int {
		return (int) get_transient( self::usage_key( $site_id ) );
	}

	private static function count_use( int $site_id ): void {
		set_transient( self::usage_key( $site_id ), self::used_today( $site_id ) + 1, 2 * DAY_IN_SECONDS );
	}

	// ---------- 调用 AI ----------

	/**
	 * @return array{text:string,model:string}|\WP_Error
	 */
	public static function complete( string $system, string $user ) {
		$s   = self::settings();
		$key = self::key();
		if ( '' === $key ) {
			return new \WP_Error( 'pchq_ai_key', '总后台还没有填 AI 的 API 密钥。' );
		}
		if ( '' === $s['model'] ) {
			return new \WP_Error( 'pchq_ai_model', '总后台还没有填模型名称。' );
		}
		$url = $s['endpoint'];
		if ( 'gemini' === $s['format'] ) {
			// Gemini 原生：地址填到 …/v1beta，模型拼在路径里。
			$url     = untrailingslashit( preg_replace( '#/models/.*$#', '', $s['endpoint'] ) ) . '/models/' . rawurlencode( $s['model'] ) . ':generateContent';
			$headers = array( 'Content-Type' => 'application/json', 'x-goog-api-key' => $key );
			$body    = array( 'systemInstruction' => array( 'parts' => array( array( 'text' => $system ) ) ), 'contents' => array( array( 'role' => 'user', 'parts' => array( array( 'text' => $user ) ) ) ), 'generationConfig' => array( 'maxOutputTokens' => $s['max_tokens'] ) );
		} elseif ( 'anthropic' === $s['format'] ) {
			$headers = array( 'Content-Type' => 'application/json', 'x-api-key' => $key, 'anthropic-version' => '2023-06-01' );
			$body    = array( 'model' => $s['model'], 'max_tokens' => $s['max_tokens'], 'system' => $system, 'messages' => array( array( 'role' => 'user', 'content' => $user ) ) );
		} else {
			$headers = array( 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $key );
			// OpenAI 官方新模型只认 max_completion_tokens；其他 OpenAI 兼容服务商一般还是 max_tokens。
			$limit   = 'api.openai.com' === wp_parse_url( $url, PHP_URL_HOST ) ? 'max_completion_tokens' : 'max_tokens';
			$body    = array( 'model' => $s['model'], $limit => $s['max_tokens'], 'messages' => array( array( 'role' => 'system', 'content' => $system ), array( 'role' => 'user', 'content' => $user ) ) );
		}
		$res = self::provider_http( 'POST', $url, $headers, (string) wp_json_encode( $body ), 90 );
		// 服务商不认这个长度参数名时，换另一个名字重试一次。
		if ( ! is_wp_error( $res ) && 400 === $res['code'] && 'openai' === $s['format'] && preg_match( '/max_(completion_)?tokens/', (string) $res['body'] ) ) {
			$swap = isset( $body['max_tokens'] ) ? 'max_completion_tokens' : 'max_tokens';
			unset( $body['max_tokens'], $body['max_completion_tokens'] );
			$body[ $swap ] = $s['max_tokens'];
			$res = self::provider_http( 'POST', $url, $headers, (string) wp_json_encode( $body ), 90 );
		}
		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'pchq_ai_http', '连不上 AI 服务：' . $res->get_error_message() );
		}
		$code = (int) $res['code'];
		$json = json_decode( (string) $res['body'], true );
		if ( 200 !== $code || ! is_array( $json ) ) {
			$msg = is_array( $json ) ? (string) ( $json['error']['message'] ?? $json['message'] ?? '' ) : '';
			return new \WP_Error( 'pchq_ai_http', 'AI 服务返回错误（HTTP ' . $code . '）' . ( '' !== $msg ? '：' . mb_substr( $msg, 0, 300 ) : '' ) );
		}
		$text = '';
		if ( 'gemini' === $s['format'] ) {
			foreach ( (array) ( $json['candidates'][0]['content']['parts'] ?? array() ) as $part ) {
				$text .= (string) ( $part['text'] ?? '' );
			}
		} elseif ( 'anthropic' === $s['format'] ) {
			foreach ( (array) ( $json['content'] ?? array() ) as $part ) {
				if ( 'text' === ( $part['type'] ?? '' ) ) {
					$text .= (string) $part['text'];
				}
			}
		} else {
			$text = (string) ( $json['choices'][0]['message']['content'] ?? '' );
		}
		$text = trim( $text );
		$cut  = in_array( (string) ( $json['choices'][0]['finish_reason'] ?? $json['stop_reason'] ?? $json['candidates'][0]['finishReason'] ?? '' ), array( 'length', 'max_tokens', 'MAX_TOKENS' ), true );
		if ( '' === $text && $cut ) {
			return new \WP_Error( 'pchq_ai_cut', 'AI 还没写完就到了长度上限（推理模型会先思考，思考也占长度）。请在总后台 AI 设置里把“单次回答最长”调大（建议 8000 以上）。' );
		}
		return '' === $text ? new \WP_Error( 'pchq_ai_empty', 'AI 没有返回内容。' ) : array( 'text' => $text, 'model' => (string) ( $json['model'] ?? $s['model'] ) );
	}

	/** 给店铺用：按用途套人设，把事实交给 AI。 */
	public const LEARN = 'pchq_ai_learn';

	/** 记下这家店最新的经验，合成全平台经验（客户分析：各店判断数 / 后来下单数相加）。 */
	private static function experience( int $site_id, string $use, array $mine ): array {
		$all = get_option( self::LEARN, array() );
		$all = is_array( $all ) ? $all : array();
		$all[ $site_id ][ $use ] = $mine;
		update_option( self::LEARN, $all, false );
		$out = array( '本店' => $mine );
		if ( 'customer' !== $use ) {
			return $out;
		}
		$sum   = array( '店铺数' => 0, '已核对' => 0, '按意向' => array(), '按主要卡点' => array(), '按心理类型' => array() );
		foreach ( $all as $exp ) {
			$e = $exp['customer'] ?? null;
			if ( ! is_array( $e ) || empty( $e['已核对'] ) ) {
				continue;
			}
			++$sum['店铺数'];
			$sum['已核对'] += (int) $e['已核对'];
			foreach ( array( '按意向', '按主要卡点', '按心理类型' ) as $k ) {
				foreach ( (array) ( $e[ $k ] ?? array() ) as $bucket => $v ) {
					$sum[ $k ][ $bucket ]['判断数']   = ( $sum[ $k ][ $bucket ]['判断数'] ?? 0 ) + (int) ( $v['判断数'] ?? 0 );
					$sum[ $k ][ $bucket ]['后来下单'] = ( $sum[ $k ][ $bucket ]['后来下单'] ?? 0 ) + (int) ( $v['后来下单'] ?? 0 );
				}
			}
		}
		foreach ( array( '按意向', '按主要卡点', '按心理类型' ) as $k ) {
			foreach ( $sum[ $k ] as $bucket => $v ) {
				$sum[ $k ][ $bucket ]['下单率'] = $v['判断数'] ? round( $v['后来下单'] / $v['判断数'] * 100 ) . '%' : '-';
			}
			uasort( $sum[ $k ], static fn( $a, $b ) => $b['判断数'] <=> $a['判断数'] );
			$sum[ $k ] = array_slice( $sum[ $k ], 0, 10, true );
		}
		$out['全平台'] = $sum;
		return $out;
	}

	public static function analyse( int $site_id, string $use, array $facts, array $experience = array(), string $tuning = '' ) {
		if ( ! isset( self::USES[ $use ] ) ) {
			return new \WP_Error( 'pchq_ai_use', '不支持的分析类型。', array( 'status' => 400 ) );
		}
		$cfg = self::site_config( $site_id );
		if ( ! $cfg['enabled'] ) {
			return new \WP_Error( 'pchq_ai_off', '这家店的 AI 分析没有开启（总后台 → AI 设置）。', array( 'status' => 403 ) );
		}
		if ( ! $cfg[ $use ] ) {
			return new \WP_Error( 'pchq_ai_persona', '总后台还没有“' . self::USES[ $use ] . '”的人设，请先导入。', array( 'status' => 409 ) );
		}
		$s = self::settings();
		$persona = self::site_persona( $site_id, $use );
		if ( ! $persona ) {
			return new \WP_Error( 'pchq_ai_persona', '总后台还没有“' . self::USES[ $use ] . '”的人设，请先导入。', array( 'status' => 409 ) );
		}
		$tuning  = trim( mb_substr( $tuning, 0, 4000 ) );
		self::note_tuning( $site_id, $use, $tuning );
		$site    = Sites::get( $site_id );
		$system  = $persona['content'];
		if ( '' !== $tuning ) {
			// 店主 2026-11-10：每家店把 AI 调成自己喜欢的样子，只影响这家店。
			$system .= "\n\n---\n【本店人设调优】（这家店店主自己写的，优先于上面的通用人设）：\n" . $tuning . "\n\n按上面的调优来：店主要求的回答格式、长短、先后顺序、重点、想看的数据，都以调优为准，和通用人设冲突时听调优；店主写明的事实当作确认过的事实，不要再给出和它矛盾的判断或建议（例如店主说支付已测试正常，就不要再把“支付故障”当原因或建议排查支付）。";
		}
		$system .= "\n\n---\n如果数据里有【店主确认已解决的问题】，这些问题店主已经确认解决：不要再把它们当成原因，也不要再建议排查或修复它们，改从其他方向分析。";
		$system .= "\n\n---\n回答语言：" . $s['language'] . "。只根据提供的数据分析，数据里没有的不要编造；数据是浏览行为线索，不代表个人身份或购买意愿的确定事实。";
		$exp     = self::experience( $site_id, $use, $experience );
		$user    = '店铺：' . ( $site ? $site['name'] : '' ) . "\n分析类型：" . self::USES[ $use ] . "\n以下是店铺记录的行为数据（JSON，已去掉姓名、邮箱、电话）：\n" . wp_json_encode( $facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT )
			. "\n\n【历史经验】（系统按你过去的判断和客户后来是否下单自动统计；“后来下单”= 判断 3 天后核对到已付款）：\n" . wp_json_encode( $exp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		$r = self::complete( $system, mb_substr( $user, 0, 120000 ) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		self::count_use( $site_id );
		return $r + array( 'persona' => $persona['name'], 'used_today' => self::used_today( $site_id ), 'daily_cap' => 0 );
	}

	// ---------- 店铺接口 ----------

	public static function routes(): void {
		register_rest_route( Register::NS, '/ai/status', array( 'methods' => 'POST', 'callback' => array( self::class, 'rest_status' ), 'permission_callback' => array( Mail::class, 'verify' ) ) );
		register_rest_route( Register::NS, '/ai/analyse', array( 'methods' => 'POST', 'callback' => array( self::class, 'rest_analyse' ), 'permission_callback' => array( Mail::class, 'verify' ) ) );
	}

	public static function rest_status( \WP_REST_Request $request ) {
		$site_id = (int) $request->get_param( '_pchq_site' );
		$cfg     = self::site_config( $site_id );
		return new \WP_REST_Response( $cfg + array( 'used_today' => self::used_today( $site_id ), 'daily_cap' => 0 ) );
	}

	public static function rest_analyse( \WP_REST_Request $request ) {
		$p = $request->get_json_params();
		if ( ! is_array( $p ) || strlen( (string) $request->get_body() ) > 400000 ) {
			return new \WP_Error( 'pchq_ai_bad', 'Invalid request.', array( 'status' => 400 ) );
		}
		$r = self::analyse( (int) $request->get_param( '_pchq_site' ), (string) ( $p['use'] ?? '' ), (array) ( $p['facts'] ?? array() ), (array) ( $p['experience'] ?? array() ), (string) ( $p['tuning'] ?? '' ) );
		return is_wp_error( $r ) ? $r : new \WP_REST_Response( $r );
	}
}
