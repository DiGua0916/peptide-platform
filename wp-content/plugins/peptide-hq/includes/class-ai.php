<?php
/**
 * 总后台 AI（2026-11-10，店主：AI 统一在总后台设置，并且要有人设）。
 *
 * - 服务商、模型、API 密钥只存在总后台（密钥用 Crypto 加密），店铺和浏览器都拿不到密钥。
 * - 人设库：每个人设 = 名字 + 用途（customer 客户分析 / business 全站经营分析）+ 人设正文（作为系统提示词）。
 *   可以粘贴或上传 .txt / .md 导入；每家店按用途选人设，不选就用该用途的第一个。
 * - 店铺用连接密钥签名后请求 /peptide-hq/v1/ai/analyse（验签同邮件中心），只发行为事实，不发姓名邮箱电话。
 * - 每家店每天有次数上限；超了当天不再分析。
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Ai {

	public const OPTION   = 'pchq_ai';
	public const PERSONAS = 'pchq_ai_personas';
	public const USES     = array( 'customer' => '客户分析', 'business' => '全站经营分析' );
	public const PROVIDERS = array(
		'anthropic' => array( 'label' => 'Claude（Anthropic）', 'endpoint' => 'https://api.anthropic.com/v1/messages', 'model' => 'claude-sonnet-5-5' ),
		'openai'    => array( 'label' => 'OpenAI / 兼容接口', 'endpoint' => 'https://api.openai.com/v1/chat/completions', 'model' => '' ),
	);
	private const MAX_PERSONA = 60000;

	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	// ---------- 设置 ----------

	public static function settings(): array {
		$s = get_option( self::OPTION, array() );
		$s = is_array( $s ) ? $s : array();
		$p = isset( self::PROVIDERS[ $s['provider'] ?? '' ] ) ? $s['provider'] : 'anthropic';
		return array(
			'enabled'   => ! empty( $s['enabled'] ),
			'provider'  => $p,
			'endpoint'  => (string) ( $s['endpoint'] ?? '' ) ?: self::PROVIDERS[ $p ]['endpoint'],
			'model'     => (string) ( $s['model'] ?? '' ) ?: self::PROVIDERS[ $p ]['model'],
			'language'  => (string) ( $s['language'] ?? '' ) ?: '中文',
			'daily_cap' => max( 1, min( 5000, (int) ( $s['daily_cap'] ?? 100 ) ) ),
			'max_tokens' => max( 200, min( 8000, (int) ( $s['max_tokens'] ?? 1500 ) ) ),
			'has_key'   => '' !== (string) ( $s['key'] ?? '' ),
			'sites'     => is_array( $s['sites'] ?? null ) ? $s['sites'] : array(), // site_id => {enabled, customer, business}
		);
	}

	public static function save_settings( array $in ): void {
		$raw = get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();
		$p   = isset( self::PROVIDERS[ $in['provider'] ?? '' ] ) ? (string) $in['provider'] : 'anthropic';
		$ep  = esc_url_raw( trim( (string) ( $in['endpoint'] ?? '' ) ) );
		if ( '' !== $ep && 'https' !== wp_parse_url( $ep, PHP_URL_SCHEME ) ) {
			$ep = '';
		}
		$raw['enabled']    = ! empty( $in['enabled'] );
		$raw['provider']   = $p;
		$raw['endpoint']   = $ep;
		$raw['model']      = substr( preg_replace( '/[^A-Za-z0-9._:\/-]/', '', (string) ( $in['model'] ?? '' ) ), 0, 120 );
		$raw['language']   = mb_substr( sanitize_text_field( (string) ( $in['language'] ?? '中文' ) ), 0, 20 );
		$raw['daily_cap']  = (int) ( $in['daily_cap'] ?? 100 );
		$raw['max_tokens'] = (int) ( $in['max_tokens'] ?? 1500 );
		$key = trim( (string) ( $in['key'] ?? '' ) );
		if ( ! empty( $in['clear_key'] ) ) {
			$raw['key'] = '';
		} elseif ( '' !== $key ) {
			$raw['key'] = Crypto::encrypt( $key ); // 空白 = 保留原来的密钥。
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
		return is_array( $raw ) && '' !== (string) ( $raw['key'] ?? '' ) ? Crypto::decrypt( (string) $raw['key'] ) : '';
	}

	/** 某家店：开没开、各用途用哪个人设。新店铺默认开（总开关开着时）。 */
	public static function site_config( int $site_id ): array {
		$s    = self::settings();
		$row  = $s['sites'][ $site_id ] ?? null;
		$on   = $s['enabled'] && $s['has_key'] && ( null === $row || ! empty( $row['enabled'] ) );
		$out  = array( 'enabled' => $on );
		foreach ( array_keys( self::USES ) as $use ) {
			$id     = is_array( $row ) ? (string) ( $row[ $use ] ?? '' ) : '';
			$p      = '' !== $id ? self::persona( $id ) : null;
			$p      = $p && $p['use'] === $use ? $p : self::default_persona( $use );
			$out[ $use ] = $p ? array( 'id' => $p['id'], 'name' => $p['name'] ) : null;
		}
		return $out;
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
		if ( 'anthropic' === $s['provider'] ) {
			$headers = array( 'Content-Type' => 'application/json', 'x-api-key' => $key, 'anthropic-version' => '2023-06-01' );
			$body    = array( 'model' => $s['model'], 'max_tokens' => $s['max_tokens'], 'system' => $system, 'messages' => array( array( 'role' => 'user', 'content' => $user ) ) );
		} else {
			$headers = array( 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $key );
			$body    = array( 'model' => $s['model'], 'max_tokens' => $s['max_tokens'], 'messages' => array( array( 'role' => 'system', 'content' => $system ), array( 'role' => 'user', 'content' => $user ) ) );
		}
		$res = wp_safe_remote_post( $s['endpoint'], array( 'timeout' => 90, 'redirection' => 0, 'headers' => $headers, 'body' => wp_json_encode( $body ), 'limit_response_size' => 1048576 ) );
		if ( is_wp_error( $res ) ) {
			return new \WP_Error( 'pchq_ai_http', '连不上 AI 服务：' . $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code || ! is_array( $json ) ) {
			$msg = is_array( $json ) ? (string) ( $json['error']['message'] ?? $json['message'] ?? '' ) : '';
			return new \WP_Error( 'pchq_ai_http', 'AI 服务返回错误（HTTP ' . $code . '）' . ( '' !== $msg ? '：' . mb_substr( $msg, 0, 300 ) : '' ) );
		}
		$text = '';
		if ( 'anthropic' === $s['provider'] ) {
			foreach ( (array) ( $json['content'] ?? array() ) as $part ) {
				if ( 'text' === ( $part['type'] ?? '' ) ) {
					$text .= (string) $part['text'];
				}
			}
		} else {
			$text = (string) ( $json['choices'][0]['message']['content'] ?? '' );
		}
		$text = trim( $text );
		return '' === $text ? new \WP_Error( 'pchq_ai_empty', 'AI 没有返回内容。' ) : array( 'text' => $text, 'model' => (string) ( $json['model'] ?? $s['model'] ) );
	}

	/** 给店铺用：按用途套人设，把事实交给 AI。 */
	public static function analyse( int $site_id, string $use, array $facts ) {
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
		if ( self::used_today( $site_id ) >= $s['daily_cap'] ) {
			return new \WP_Error( 'pchq_ai_cap', '今天这家店的 AI 分析次数已用完（每天 ' . $s['daily_cap'] . ' 次），明天自动恢复。', array( 'status' => 429 ) );
		}
		$persona = self::persona( $cfg[ $use ]['id'] );
		$site    = Sites::get( $site_id );
		$system  = $persona['content'] . "\n\n---\n回答语言：" . $s['language'] . "。只根据提供的数据分析，数据里没有的不要编造；数据是浏览行为线索，不代表个人身份或购买意愿的确定事实。";
		$user    = '店铺：' . ( $site ? $site['name'] : '' ) . "\n分析类型：" . self::USES[ $use ] . "\n以下是店铺记录的行为数据（JSON，已去掉姓名、邮箱、电话）：\n" . wp_json_encode( $facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		$r = self::complete( $system, mb_substr( $user, 0, 120000 ) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		self::count_use( $site_id );
		return $r + array( 'persona' => $persona['name'], 'used_today' => self::used_today( $site_id ), 'daily_cap' => $s['daily_cap'] );
	}

	// ---------- 店铺接口 ----------

	public static function routes(): void {
		register_rest_route( Register::NS, '/ai/status', array( 'methods' => 'POST', 'callback' => array( self::class, 'rest_status' ), 'permission_callback' => array( Mail::class, 'verify' ) ) );
		register_rest_route( Register::NS, '/ai/analyse', array( 'methods' => 'POST', 'callback' => array( self::class, 'rest_analyse' ), 'permission_callback' => array( Mail::class, 'verify' ) ) );
	}

	public static function rest_status( \WP_REST_Request $request ) {
		$site_id = (int) $request->get_param( '_pchq_site' );
		$cfg     = self::site_config( $site_id );
		return new \WP_REST_Response( $cfg + array( 'used_today' => self::used_today( $site_id ), 'daily_cap' => self::settings()['daily_cap'] ) );
	}

	public static function rest_analyse( \WP_REST_Request $request ) {
		$p = $request->get_json_params();
		if ( ! is_array( $p ) || strlen( (string) $request->get_body() ) > 400000 ) {
			return new \WP_Error( 'pchq_ai_bad', 'Invalid request.', array( 'status' => 400 ) );
		}
		$r = self::analyse( (int) $request->get_param( '_pchq_site' ), (string) ( $p['use'] ?? '' ), (array) ( $p['facts'] ?? array() ) );
		return is_wp_error( $r ) ? $r : new \WP_REST_Response( $r );
	}
}
