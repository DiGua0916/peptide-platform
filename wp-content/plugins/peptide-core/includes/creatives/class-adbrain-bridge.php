<?php
/**
 * Ad Brain 双桥接口（2026-11-21）：外部 Ad Brain 服务用服务端签名调用本店，读词表 / 落地页、发素材编号、改落地页、
 * 未登记广告归位、拉取区间真值数据。只复用现有表和选项，不改任何表结构。
 *
 * 接口（peptide/v1/adbrain/...）：
 *   GET  options                   词表、可选落地页、时区、追踪模板
 *   POST creatives/issue           幂等发号 + 专属投放链接（可带图片）
 *   POST creatives/update-landing  换落地页 / 落地页版本
 *   POST creatives/relink-ad       未登记广告归到某个素材
 *   GET  pull                      区间汇总、人群画像、按天明细、脱敏订单
 *
 * 安全：
 * - 默认关闭：常量 PEPTIDE_ADBRAIN_SECRET 或选项 pc_adbrain_bridge_secret 没配（或短于 32 个字符）时一律 503。
 * - 每个请求在 permission_callback 里验 HMAC-SHA256，不看浏览器登录态：
 *   签名原文 = 方法大写 \n 路由（/peptide/v1/adbrain/...）\n X-AdBrain-Time \n X-AdBrain-Nonce \n sha256(原始请求体)。
 *   时间偏差超过 ±300 秒、签名不对、Nonce 600 秒内用过 → 401。
 * - 出错统一返回 {"ok":false,"code":"...","message":"..."}（rest_post_dispatch 里改写 WordPress 默认的错误格式）。
 * - 订单明细不返回明文姓名、邮箱、电话、IP，只给哈希。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Creatives;

defined( 'ABSPATH' ) || exit;

final class AdBrain_Bridge {

	public const NS         = 'peptide/v1';
	public const BASE       = '/adbrain';
	public const SECRET_OPT = 'pc_adbrain_bridge_secret';
	public const IDEM_OPT   = 'pc_adbrain_idem_map';
	public const IDEM_MAX   = 500;
	public const SKEW       = 300;
	public const NONCE_TTL  = 600;
	public const MIN_SECRET = 32;
	public const MAX_DAYS   = 62;
	public const MAX_ORDERS = 5000;
	public const MAX_IMAGE  = 10485760; // 10 MB（解码后）
	public const EMPTY_SHA  = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

	/** 订单明细要的状态（已付、待付 / 失败、退款）。 */
	public const ORDER_STATUSES = array( 'processing', 'completed', 'pending', 'on-hold', 'failed', 'refunded', 'cancelled' );

	/** 订单状态 → 标准化付款状态（2026-11-26 双桥需求 #2）。退款单付过款，单列 REFUNDED。 */
	public const PAYMENT_NORM = array(
		'processing' => 'PAID',
		'completed'  => 'PAID',
		'refunded'   => 'REFUNDED',
		'pending'    => 'UNPAID_CHECKOUT',
		'on-hold'    => 'UNPAID_CHECKOUT',
		'failed'     => 'FAILED',
		'cancelled'  => 'CANCELLED',
	);

	/** PanaPay 记在订单上的付款尝试（只读，不改 PanaPay）。 */
	public const PANAPAY_SESSIONS_META = '_pc_panapay_sessions';

	private const IMAGE_TYPES = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp' );

	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_filter( 'rest_post_dispatch', array( self::class, 'format_error' ), 10, 3 );
	}

	public static function routes(): void {
		$perm = array( self::class, 'permission' );
		register_rest_route( self::NS, self::BASE . '/options', array( 'methods' => 'GET', 'callback' => array( self::class, 'rest_options' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, self::BASE . '/creatives/issue', array( 'methods' => 'POST', 'callback' => array( self::class, 'rest_issue' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, self::BASE . '/creatives/update-landing', array( 'methods' => 'POST', 'callback' => array( self::class, 'rest_update_landing' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, self::BASE . '/creatives/relink-ad', array( 'methods' => 'POST', 'callback' => array( self::class, 'rest_relink_ad' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, self::BASE . '/pull', array( 'methods' => 'GET', 'callback' => array( self::class, 'rest_pull' ), 'permission_callback' => $perm ) );
		register_rest_route( self::NS, self::BASE . '/creatives/delete', array( 'methods' => 'POST', 'callback' => array( self::class, 'rest_delete' ), 'permission_callback' => $perm ) );
	}

	// ---------- 验签 ----------

	/** 本店密钥；没配或太短 = ''（接口全部 503）。 */
	public static function secret(): string {
		$s = defined( 'PEPTIDE_ADBRAIN_SECRET' ) ? (string) constant( 'PEPTIDE_ADBRAIN_SECRET' ) : '';
		if ( '' === trim( $s ) ) {
			$s = (string) get_option( self::SECRET_OPT, '' );
		}
		$s = trim( $s );
		return strlen( $s ) >= self::MIN_SECRET ? $s : '';
	}

	/** 签名原文（纯函数）。 */
	public static function canonical( string $method, string $route, string $time, string $nonce, string $body ): string {
		return strtoupper( $method ) . "\n" . $route . "\n" . $time . "\n" . $nonce . "\n" . hash( 'sha256', $body );
	}

	public static function sign( string $secret, string $method, string $route, string $time, string $nonce, string $body ): string {
		return hash_hmac( 'sha256', self::canonical( $method, $route, $time, $nonce, $body ), $secret );
	}

	/**
	 * 检查请求头和签名（纯函数，不查 Nonce 是否用过）。
	 *
	 * @param array{site:string,time:string,nonce:string,sign:string} $h
	 * @return array{0:int,1:string}|null 失败时 [HTTP 状态, 错误码]
	 */
	public static function check( array $h, string $method, string $route, string $body, string $secret, int $now ): ?array {
		if ( '' === $secret ) {
			return array( 503, 'bridge_not_configured' );
		}
		$site  = trim( (string) ( $h['site'] ?? '' ) );
		$time  = trim( (string) ( $h['time'] ?? '' ) );
		$nonce = trim( (string) ( $h['nonce'] ?? '' ) );
		$sign  = strtolower( trim( (string) ( $h['sign'] ?? '' ) ) );
		if ( '' === $site || strlen( $site ) > 128 ) {
			return array( 401, 'bad_site' );
		}
		if ( 1 !== preg_match( '/^\d{1,12}$/D', $time ) || abs( $now - (int) $time ) > self::SKEW ) {
			return array( 401, 'bad_time' );
		}
		if ( 1 !== preg_match( '/^[A-Za-z0-9_\-]{16,64}$/D', $nonce ) ) {
			return array( 401, 'bad_nonce' );
		}
		if ( 1 !== preg_match( '/^[0-9a-f]{64}$/D', $sign ) || ! hash_equals( self::sign( $secret, $method, $route, $time, $nonce, $body ), $sign ) ) {
			return array( 401, 'bad_sign' );
		}
		return null;
	}

	/** Nonce 第一次用：记下（600 秒）并返回 true；用过返回 false。 */
	public static function claim_nonce( string $nonce ): bool {
		$key = 'pc_adbrain_n_' . md5( $nonce );
		if ( get_transient( $key ) ) {
			return false;
		}
		set_transient( $key, 1, self::NONCE_TTL );
		return true;
	}

	/** permission_callback：所有 adbrain 接口共用。 @return true|\WP_Error */
	public static function permission( \WP_REST_Request $request ) {
		$h = array(
			'site'  => (string) $request->get_header( 'x_adbrain_site' ),
			'time'  => (string) $request->get_header( 'x_adbrain_time' ),
			'nonce' => (string) $request->get_header( 'x_adbrain_nonce' ),
			'sign'  => (string) $request->get_header( 'x_adbrain_sign' ),
		);
		$bad = self::check( $h, (string) $request->get_method(), (string) $request->get_route(), (string) $request->get_body(), self::secret(), time() );
		if ( null === $bad && ! self::claim_nonce( trim( $h['nonce'] ) ) ) {
			$bad = array( 401, 'replay' );
		}
		return null === $bad ? true : self::error( $bad[1], $bad[0] );
	}

	private const MESSAGES = array(
		'bridge_not_configured' => 'Ad Brain bridge is not configured on this store.',
		'bad_site'              => 'Missing X-AdBrain-Site.',
		'bad_time'              => 'X-AdBrain-Time is missing or too far from the store clock.',
		'bad_nonce'             => 'X-AdBrain-Nonce must be 16-64 characters [A-Za-z0-9_-].',
		'bad_sign'              => 'The signature is not valid.',
		'replay'                => 'This nonce was already used.',
	);

	public static function error( string $code, int $status, string $message = '' ): \WP_Error {
		return new \WP_Error( $code, '' !== $message ? $message : ( self::MESSAGES[ $code ] ?? $code ), array( 'status' => $status ) );
	}

	/** 是不是我们的路由（含 /?rest_route= 形式，路由都已规范成 /peptide/v1/...）。 */
	public static function is_ours( string $route ): bool {
		return 0 === strpos( $route, '/' . self::NS . self::BASE . '/' );
	}

	/** 把 WordPress 的错误体 {code,message,data} 改成 {ok:false,code,message}。 */
	public static function format_error( $response, $server, $request ) {
		if ( ! $response instanceof \WP_REST_Response || ! $request instanceof \WP_REST_Request || ! self::is_ours( (string) $request->get_route() ) || $response->get_status() < 400 ) {
			return $response;
		}
		$data = $response->get_data();
		if ( is_array( $data ) && isset( $data['code'] ) && ! isset( $data['ok'] ) ) {
			$response->set_data( array( 'ok' => false, 'code' => (string) $data['code'], 'message' => (string) ( $data['message'] ?? '' ) ) );
		}
		return $response;
	}

	// ---------- 1. options ----------

	public static function rest_options( \WP_REST_Request $request ) {
		Schema::maybe_install();
		$pages = array();
		foreach ( \PeptideCore\Admin\Onboarding::landing_pages() as $p ) {
			$pages[] = array( 'url' => (string) $p['url'], 'label' => (string) $p['label'], 'group' => (string) $p['group'] );
		}
		$vocab = array();
		foreach ( Schema::vocab() as $v ) {
			$vocab[] = array( 'attr' => (string) $v['attr'], 'value' => (string) $v['value'], 'label' => (string) $v['label'], 'required' => (bool) $v['required'] );
		}
		return new \WP_REST_Response(
			array(
				'ok'             => true,
				'store_timezone' => Board::timezone(),
				'home_url'       => home_url( '/' ),
				'url_template'   => Contract::URL_TEMPLATE,
				'vocab'          => $vocab,
				'landing_pages'  => $pages,
			),
			200
		);
	}

	// ---------- 2. issue ----------

	/**
	 * 传入的属性 → 词表里的取值（纯函数）。取值先按 value 匹配，再按中文 label 匹配；都不匹配（或必填没给）时用词表里这个属性的第一个值，记在 notes 里。
	 * lang 没给就不填；给了但不匹配同样回退。
	 *
	 * @return array{attrs:array<string,string>,notes:list<array{attr:string,given:string,used:string,how:string}>}
	 */
	public static function resolve_attrs( array $input, array $vocab ): array {
		$by = array();
		foreach ( $vocab as $v ) {
			$by[ (string) $v['attr'] ][] = $v;
		}
		$attrs = array();
		$notes = array();
		foreach ( array_merge( Contract::REQUIRED_ATTRS, array( 'lang' ) ) as $attr ) {
			$given = isset( $input[ $attr ] ) && is_scalar( $input[ $attr ] ) ? trim( (string) $input[ $attr ] ) : '';
			if ( '' === $given && 'lang' === $attr ) {
				continue;
			}
			$items = $by[ $attr ] ?? array();
			$used  = '';
			$how   = '';
			foreach ( $items as $v ) {
				if ( strtolower( $given ) === strtolower( (string) $v['value'] ) ) {
					$used = (string) $v['value'];
					break;
				}
			}
			if ( '' === $used && '' !== $given ) {
				foreach ( $items as $v ) {
					if ( '' !== (string) $v['label'] && mb_strtolower( $given ) === mb_strtolower( trim( (string) $v['label'] ) ) ) {
						$used = (string) $v['value'];
						$how  = 'label';
						break;
					}
				}
			}
			if ( '' === $used && $items ) {
				$used = (string) $items[0]['value'];
				$how  = 'fallback';
			}
			if ( '' !== $used ) {
				$attrs[ $attr ] = $used;
			}
			if ( '' !== $how ) {
				$notes[] = array( 'attr' => $attr, 'given' => $given, 'used' => $used, 'how' => $how );
			}
		}
		return array( 'attrs' => $attrs, 'notes' => $notes );
	}

	/** 幂等记录里找（纯函数）。 */
	public static function idem_get( array $map, string $key ): ?array {
		return isset( $map[ $key ] ) && is_array( $map[ $key ] ) ? $map[ $key ] : null;
	}

	/** 记下一条结果，只留最近 $max 条（纯函数）。 */
	public static function idem_put( array $map, string $key, array $result, int $max = self::IDEM_MAX ): array {
		unset( $map[ $key ] );
		$map[ $key ] = $result;
		return count( $map ) > $max ? array_slice( $map, -$max, null, true ) : $map;
	}

	public static function valid_idem_key( string $key ): bool {
		return '' !== $key && strlen( $key ) <= 128 && 1 === preg_match( '/^[\x21-\x7E]+$/D', $key );
	}

	/** 同一个幂等键同时只处理一个（add_option 撞唯一键就是被占着）。 */
	private static function lock( string $key ): bool {
		$name = 'pc_adbrain_lock_' . md5( $key );
		if ( add_option( $name, time(), '', false ) ) {
			return true;
		}
		if ( time() - (int) get_option( $name, 0 ) > 120 ) { // 上次没解锁（进程中途退出）。
			delete_option( $name );
			return (bool) add_option( $name, time(), '', false );
		}
		return false;
	}

	private static function unlock( string $key ): void {
		delete_option( 'pc_adbrain_lock_' . md5( $key ) );
	}

	public static function rest_issue( \WP_REST_Request $request ) {
		$p   = (array) $request->get_json_params();
		$key = is_scalar( $p['idempotency_key'] ?? null ) ? trim( (string) $p['idempotency_key'] ) : '';
		if ( ! self::valid_idem_key( $key ) ) {
			return self::error( 'bad_idempotency_key', 400, 'idempotency_key is required (1-128 printable characters).' );
		}
		if ( ! is_array( $p['attrs'] ?? null ) ) {
			return self::error( 'bad_attrs', 400, 'attrs must be an object.' );
		}
		$hit = self::idem_get( (array) get_option( self::IDEM_OPT, array() ), $key );
		if ( null !== $hit ) {
			return new \WP_REST_Response( array( 'idempotent_replay' => true ) + $hit, 200 );
		}
		if ( ! self::lock( $key ) ) {
			return self::error( 'in_progress', 409, 'A request with this idempotency_key is still being processed.' );
		}
		try {
			$hit = self::idem_get( (array) get_option( self::IDEM_OPT, array() ), $key ); // 拿到锁后再看一次。
			if ( null !== $hit ) {
				return new \WP_REST_Response( array( 'idempotent_replay' => true ) + $hit, 200 );
			}
			$out = self::issue( $p );
			if ( $out instanceof \WP_Error ) {
				return $out;
			}
			update_option( self::IDEM_OPT, self::idem_put( (array) get_option( self::IDEM_OPT, array() ), $key, $out ), false );
			return new \WP_REST_Response( array( 'idempotent_replay' => false ) + $out, 200 );
		} finally {
			self::unlock( $key );
		}
	}

	/** 真正发号。 @return array|\WP_Error */
	public static function issue( array $p ) {
		Schema::maybe_install();
		$vocab    = Schema::vocab();
		$resolved = self::resolve_attrs( (array) $p['attrs'], $vocab );
		$clean    = Creatives::clean_attrs( $resolved['attrs'], $vocab );
		if ( $clean['problems'] ) {
			return self::error( 'bad_attrs', 400, 'Attributes not in this store vocabulary: ' . implode( ', ', $clean['problems'] ) );
		}
		$warnings = array();
		$pages    = \PeptideCore\Admin\Onboarding::landing_pages();
		$landing  = is_string( $p['landing_url'] ?? null ) ? trim( $p['landing_url'] ) : '';
		if ( '' !== $landing && null === Ads_Panel::landing_label( $landing, $pages ) ) {
			$warnings[] = 'landing_url_not_allowed';
			$landing    = '';
		}
		$landing = '' !== $landing ? $landing : home_url( '/' );
		$lp_ver  = is_string( $p['lp_ver'] ?? null ) ? trim( $p['lp_ver'] ) : '';
		if ( '' !== $lp_ver && ! Contract::valid_lp_ver( $lp_ver ) ) {
			$warnings[] = 'lp_ver_invalid';
			$lp_ver     = '';
		}
		$note = is_scalar( $p['note'] ?? null ) ? (string) $p['note'] : '';
		$want = is_string( $p['want_code'] ?? null ) ? strtoupper( trim( $p['want_code'] ) ) : '';
		if ( '' !== $want && ! Contract::valid_code( $want ) ) {
			$warnings[] = 'want_code_invalid';
			$want       = '';
		}
		$from_ad = is_scalar( $p['from_ad_id'] ?? null ) ? trim( (string) $p['from_ad_id'] ) : '';
		if ( '' !== $from_ad && ! Contract::valid_meta_id( $from_ad ) ) {
			$warnings[] = 'from_ad_id_invalid';
			$from_ad    = '';
		}

		$attachment = 0;
		if ( is_string( $p['image_base64'] ?? null ) && '' !== $p['image_base64'] ) {
			$attachment = self::save_image( $p['image_base64'], is_string( $p['image_filename'] ?? null ) ? $p['image_filename'] : '' );
			if ( $attachment < 1 ) {
				$warnings[] = 'image_not_saved';
				$attachment = 0;
			}
		}

		$code = Creatives::create( $clean['attrs'], $attachment, $note, $want );
		if ( $code instanceof \WP_Error ) {
			return self::error( 'issue_failed', 500, $code->get_error_message() );
		}
		if ( '' !== $want && $want !== $code ) {
			$warnings[] = 'want_code_taken';
		}
		if ( home_url( '/' ) !== $landing ) {
			Ads_Panel::set_landing( $code, $landing );
		}
		if ( '' !== $lp_ver ) {
			Ads_Panel::set_lp_ver( $code, $lp_ver );
		}
		$relinked = false;
		if ( '' !== $from_ad ) {
			$relinked = Creatives::link_ad( $from_ad, $code );
			if ( ! $relinked ) {
				$warnings[] = 'from_ad_relink_failed';
			}
		}
		return array(
			'ok'                => true,
			'code'              => $code,
			'tracking_url'      => Ads_Panel::link( $landing, $code, $lp_ver ),
			'suggested_ad_name' => Contract::build_ad_name( $code, $clean['attrs'] ),
			'landing_url'       => $landing,
			'landing_label'     => (string) Ads_Panel::landing_label( $landing, $pages ),
			'lp_ver'            => $lp_ver,
			'attrs'             => $clean['attrs'],
			'attr_notes'        => $resolved['notes'],
			'thumb_url'         => $attachment > 0 ? (string) wp_get_attachment_image_url( $attachment, 'thumbnail' ) : '',
			'from_ad_id'        => $from_ad,
			'relinked'          => $relinked,
			'warnings'          => $warnings,
		);
	}

	/**
	 * Base64 图片 → 媒体库附件（失败返回 0，不挡发号）。只收真图片：jpg / png / gif / webp，解码后最多 10 MB。
	 */
	public static function save_image( string $b64, string $filename ): int {
		$b64   = (string) preg_replace( '/^data:image\/[a-z+]+;base64,/i', '', trim( $b64 ) );
		$bytes = base64_decode( $b64, true );
		if ( false === $bytes || '' === $bytes || strlen( $bytes ) > self::MAX_IMAGE ) {
			return 0;
		}
		$info = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$mime = is_array( $info ) ? (string) ( $info['mime'] ?? '' ) : '';
		if ( ! isset( self::IMAGE_TYPES[ $mime ] ) ) {
			return 0;
		}
		$base = sanitize_file_name( (string) pathinfo( $filename, PATHINFO_FILENAME ) );
		$name = ( '' !== $base ? $base : 'adbrain-' . gmdate( 'Ymd-His' ) ) . '.' . self::IMAGE_TYPES[ $mime ];
		$up   = wp_upload_bits( $name, null, $bytes );
		if ( ! empty( $up['error'] ) || empty( $up['file'] ) ) {
			return 0;
		}
		$id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
				'post_title'     => pathinfo( $name, PATHINFO_FILENAME ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$up['file']
		);
		if ( ! is_int( $id ) || $id < 1 ) {
			return 0;
		}
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $up['file'] ) );
		return $id;
	}

	// ---------- 3. update-landing ----------

	public static function rest_update_landing( \WP_REST_Request $request ) {
		$p    = (array) $request->get_json_params();
		$code = is_string( $p['code'] ?? null ) ? strtoupper( trim( $p['code'] ) ) : '';
		if ( ! Creatives::get( $code ) ) {
			return self::error( 'creative_not_found', 404, 'No creative with this code.' );
		}
		$pages = \PeptideCore\Admin\Onboarding::landing_pages();
		if ( array_key_exists( 'landing_url', $p ) ) {
			$landing = is_string( $p['landing_url'] ) ? trim( $p['landing_url'] ) : '';
			if ( '' !== $landing && null === Ads_Panel::landing_label( $landing, $pages ) ) {
				return self::error( 'landing_url_not_allowed', 400, 'landing_url is not one of this store\'s landing pages.' );
			}
			Ads_Panel::set_landing( $code, '' !== $landing ? $landing : home_url( '/' ) );
		}
		if ( array_key_exists( 'lp_ver', $p ) ) {
			$lp_ver = is_string( $p['lp_ver'] ) ? trim( $p['lp_ver'] ) : '';
			if ( ! Ads_Panel::set_lp_ver( $code, $lp_ver ) ) {
				return self::error( 'lp_ver_invalid', 400, 'lp_ver may only use letters, digits, _ . - (max 32).' );
			}
		}
		$landing = Ads_Panel::landing_of( $code, $pages );
		$lp_ver  = Ads_Panel::lp_ver_of( $code );
		return new \WP_REST_Response(
			array(
				'ok'            => true,
				'code'          => $code,
				'landing_url'   => $landing,
				'landing_label' => (string) Ads_Panel::landing_label( $landing, $pages ),
				'lp_ver'        => $lp_ver,
				'tracking_url'  => Ads_Panel::link( $landing, $code, $lp_ver ),
			),
			200
		);
	}

	// ---------- 4. relink-ad ----------

	public static function rest_relink_ad( \WP_REST_Request $request ) {
		$p     = (array) $request->get_json_params();
		$ad_id = is_scalar( $p['ad_id'] ?? null ) ? trim( (string) $p['ad_id'] ) : '';
		$code  = is_string( $p['code'] ?? null ) ? strtoupper( trim( $p['code'] ) ) : '';
		if ( ! Contract::valid_meta_id( $ad_id ) ) {
			return self::error( 'bad_ad_id', 400, 'ad_id must be a Meta ad id (digits).' );
		}
		if ( ! Creatives::get( $code ) ) {
			return self::error( 'creative_not_found', 404, 'No creative with this code.' );
		}
		if ( ! Creatives::link_ad( $ad_id, $code ) ) {
			return self::error( 'relink_failed', 500, 'Could not update the ad.' );
		}
		return new \WP_REST_Response( array( 'ok' => true, 'ad_id' => $ad_id, 'code' => $code ), 200 );
	}

	// ---------- 5. pull ----------

	/**
	 * 查询参数 → 日期范围和模式（纯函数）。默认最近 7 天（含今天）、mode=last；跨度最多 62 天。
	 *
	 * @return array{from:string,to:string,mode:string}|array{error:string}
	 */
	public static function pull_range( array $q, string $today ): array {
		if ( isset( $q['tz'] ) && '' !== $q['tz'] && null === self::parse_tz( is_string( $q['tz'] ) ? $q['tz'] : '' ) ) {
			return array( 'error' => 'bad_timezone' );
		}
		$to   = is_string( $q['to'] ?? null ) && '' !== $q['to'] ? $q['to'] : $today;
		$from = is_string( $q['from'] ?? null ) && '' !== $q['from'] ? $q['from'] : gmdate( 'Y-m-d', (int) strtotime( $to . ' 00:00:00 UTC' ) - 6 * 86400 );
		$mode = is_string( $q['mode'] ?? null ) && '' !== $q['mode'] ? $q['mode'] : 'last';
		if ( ! Board::valid_day( $from ) || ! Board::valid_day( $to ) || $from > $to ) {
			return array( 'error' => 'bad_range' );
		}
		if ( count( self::days( $from, $to ) ) > self::MAX_DAYS ) {
			return array( 'error' => 'range_too_long' );
		}
		if ( ! in_array( $mode, array( 'last', 'first' ), true ) ) {
			return array( 'error' => 'bad_mode' );
		}
		return array( 'from' => $from, 'to' => $to, 'mode' => $mode );
	}

	/**
	 * 查询参数 tz（2026-11-26 双桥需求 #4，纯函数）：IANA 时区名（America/Los_Angeles）或固定偏移（UTC-8、GMT+8、-08:00、UTC-07:30）。
	 * 返回 DateTimeZone 能用的写法和回给调用方的名字；不认识 = null。
	 *
	 * @return array{zone:string,label:string}|null
	 */
	public static function parse_tz( string $raw ): ?array {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return null;
		}
		if ( preg_match( '/^(?:UTC|GMT)?\s*([+-])(\d{1,2})(?::?(\d{2}))?$/i', $raw, $m ) ) {
			$h   = (int) $m[2];
			$min = isset( $m[3] ) ? (int) $m[3] : 0;
			if ( $h > 14 || $min > 59 || ( 14 === $h && $min > 0 ) ) {
				return null;
			}
			$zone = sprintf( '%s%02d:%02d', $m[1], $h, $min );
			return array( 'zone' => $zone, 'label' => 'UTC' . $zone );
		}
		if ( 0 === strcasecmp( $raw, 'UTC' ) || 0 === strcasecmp( $raw, 'GMT' ) ) {
			return array( 'zone' => 'UTC', 'label' => 'UTC' );
		}
		if ( in_array( $raw, \DateTimeZone::listIdentifiers(), true ) ) {
			return array( 'zone' => $raw, 'label' => $raw );
		}
		return null;
	}

	/** 8 步漏斗里新加的字段（2026-11-26 双桥需求 #1，纯函数）：去重人数 + 事件次数。 */
	public static function funnel_fields( array $r ): array {
		$ev  = (array) ( $r['events'] ?? array() );
		$out = array(
			'view_content'     => (int) ( $r['viewed'] ?? 0 ),
			'add_address'      => (int) ( $r['address'] ?? 0 ),
			'add_shipping'     => (int) ( $r['shipping'] ?? 0 ),
			'add_payment_info' => (int) ( $r['payment'] ?? 0 ),
			'events'           => array(),
		);
		foreach ( Board::EVENT_KEYS as $k ) {
			$out['events'][ $k ] = (int) ( $ev[ $k ] ?? 0 );
		}
		return $out;
	}

	/**
	 * 全店总漏斗 = 广告归因合计 + 未归因（纯函数，逐项相加，所以 rows 汇总 + unattributed == store_total 一定成立）。
	 * 花费类字段只有广告有，照抄广告合计。
	 */
	public static function store_total( array $ads, array $unattr ): array {
		$sum = static function ( $a, $b ) use ( &$sum ) {
			if ( is_array( $a ) || is_array( $b ) ) {
				$out = array();
				foreach ( array_unique( array_merge( array_keys( (array) $a ), array_keys( (array) $b ) ) ) as $k ) {
					$out[ $k ] = $sum( $a[ $k ] ?? 0, $b[ $k ] ?? 0 );
				}
				return $out;
			}
			return (int) $a + (int) $b;
		};
		$out = array( 'code' => 'ALL' );
		foreach ( array( 'visitors', 'valid_visitors', 'intent', 'view_content', 'add_to_cart', 'checkout', 'add_address', 'add_shipping', 'add_payment_info', 'cashier', 'orders', 'sales_cents', 'events' ) as $k ) {
			$out[ $k ] = $sum( $ads[ $k ] ?? 0, $unattr[ $k ] ?? 0 );
		}
		foreach ( array( 'spend_cents', 'impressions', 'link_clicks' ) as $k ) {
			$out[ $k ] = $ads[ $k ] ?? null;
		}
		return $out;
	}

	/** 未归因这一桶（纯函数）：和 rows 同样的漏斗字段。 */
	public static function unattributed_row( array $r, int $returning_ad ): array {
		$row = self::board_rows( array( array( 'key' => 'UNATTRIBUTED' ) + $r ), array() )[0];
		unset( $row['spend_cents'], $row['impressions'], $row['link_clicks'], $row['behavior'] );
		$row['returning_from_ad'] = $returning_ad; // 以前从广告来过、这次没带参回来的访客（也算在 visitors 里）
		return $row;
	}

	/**
	 * 店铺仪表盘自己的 8 步漏斗（同一时间窗，按访问开始时间），给 store_total 对照用。仪表盘口径和看板不同
	 * （按每次访问的阶段、不剔除无效访客），所以两者可能差几个人；读不到时为 null。
	 */
	private static function dashboard_funnel( array $ms ): ?array {
		if ( ! class_exists( \PeptideCore\Data\Visitor_Funnel::class ) ) {
			return null;
		}
		try {
			$res  = ( new \PeptideCore\Data\Visitor_Funnel() )->period( $ms[0], $ms[1] );
			$map  = array( 'visitors' => 'visitors', 'viewed' => 'view_content', 'carted' => 'add_to_cart', 'checkout' => 'checkout', 'address' => 'add_address', 'shipping' => 'add_shipping', 'payment' => 'add_payment_info', 'paid' => 'paid' );
			$out  = array();
			foreach ( (array) ( $res['overall']['layers'] ?? array() ) as $layer ) {
				if ( isset( $map[ $layer['key'] ?? '' ] ) ) {
					$out[ $map[ $layer['key'] ] ] = (int) $layer['count'];
				}
			}
			return $out ?: null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/** @return string[] from..to 的每一天 */
	public static function days( string $from, string $to ): array {
		$out = array();
		$t   = (int) strtotime( $from . ' 00:00:00 UTC' );
		$end = (int) strtotime( $to . ' 00:00:00 UTC' );
		for ( $i = 0; $t <= $end && $i <= self::MAX_DAYS; $i++, $t += 86400 ) {
			$out[] = gmdate( 'Y-m-d', $t );
		}
		return $out;
	}

	/** 看板行 + 画像 → 区间汇总行（纯函数）。 */
	public static function board_rows( array $rows, array $profiles ): array {
		$out = array();
		foreach ( $rows as $r ) {
			$code  = (string) $r['key'];
			$total = (array) ( $profiles[ $code ]['total'] ?? array() );
			$out[] = array(
				'code'           => $code,
				'visitors'       => (int) $r['visitors'],
				'valid_visitors' => (int) $r['valid'],
				'intent'         => array_map( 'intval', (array) $r['intent'] ),
				'add_to_cart'    => (int) $r['carted'],
				'checkout'       => (int) $r['checkout'],
				'cashier'        => (int) $r['cashier'],
				'orders'         => (int) $r['orders'],
				'sales_cents'    => (int) $r['sales_cents'],
				'spend_cents'    => null === $r['spend_cents'] ? null : (int) $r['spend_cents'],
				'impressions'    => null === $r['impressions'] ? null : (int) $r['impressions'],
				'link_clicks'    => null === $r['link_clicks'] ? null : (int) $r['link_clicks'],
				'behavior'       => array(
					'avg_dwell_s'     => $total['dwell_s'] ?? null,
					'avg_pv'          => $total['pv_avg'] ?? null,
					'scroll_75_share' => $total['scroll75_rate'] ?? null,
				),
			) + self::funnel_fields( $r );
		}
		return $out;
	}

	/** 只留某一天的花费（纯函数）。 */
	public static function day_data( array $data, string $day ): array {
		$data['spend'] = array_values( array_filter( (array) ( $data['spend'] ?? array() ), static fn( $s ) => $day === (string) $s['day'] ) );
		return $data;
	}

	/** 邮箱 / 电话 → 客户哈希（纯函数）：邮箱小写去空格 sha256 前 24 位；没有邮箱用电话末 10 位数字。 */
	public static function customer_hash( string $email, string $phone ): string {
		$email = strtolower( (string) preg_replace( '/\s+/', '', $email ) );
		if ( '' !== $email ) {
			return substr( hash( 'sha256', $email ), 0, 24 );
		}
		$digits = (string) preg_replace( '/\D+/', '', $phone );
		return '' !== $digits ? substr( hash( 'sha256', substr( $digits, -10 ) ), 0, 24 ) : '';
	}

	/** 邮箱或姓名里有 test（纯函数）。 */
	public static function test_hint( string $email, string $name ): bool {
		return false !== stripos( $email, 'test' ) || false !== stripos( $name, 'test' );
	}

	public static function postcode_prefix( string $postcode ): string {
		return substr( strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', $postcode ) ), 0, 3 );
	}

	/**
	 * 一张订单归到哪个素材（纯函数）：下单那次访问之前（含）这位访客的付费访问里，last 取最后一次、first 取第一次。
	 * 没有付费访问 = 空。
	 *
	 * @param list<array> $paid  这位访客的付费访问（start_ms, utm_content, creative_code, ad_name）
	 * @return array{code:string,ad_id:string}
	 */
	public static function order_attribution( array $paid, int $order_visit_ms, string $mode, array $ads, array $creatives ): array {
		$list = array_values( array_filter( $paid, static fn( $v ) => (int) $v['start_ms'] <= $order_visit_ms ) );
		if ( ! $list ) {
			return array( 'code' => '', 'ad_id' => '' );
		}
		usort( $list, static fn( $a, $b ) => (int) $a['start_ms'] <=> (int) $b['start_ms'] );
		$v = 'first' === $mode ? $list[0] : $list[ count( $list ) - 1 ];
		return array( 'code' => Board::resolve_code( $v, $ads, $creatives ), 'ad_id' => (string) ( $v['utm_content'] ?? '' ) );
	}

	public static function rest_pull( \WP_REST_Request $request ) {
		$store_tz = Board::timezone();
		$query    = (array) $request->get_query_params();
		$applied  = self::parse_tz( is_string( $query['tz'] ?? null ) ? $query['tz'] : '' );
		$tz       = null !== $applied ? $applied['zone'] : $store_tz; // 2026-11-26：按 tz 切日；不传沿用店铺时区
		$range    = self::pull_range( $query, Board::day( time() * 1000, $tz ) );
		if ( isset( $range['error'] ) ) {
			if ( 'bad_timezone' === $range['error'] ) {
				return self::error( 'bad_timezone', 400, 'tz must be an IANA zone (America/Los_Angeles) or a fixed offset (UTC-8, -08:00).' );
			}
			return self::error( $range['error'], 400, 'from/to must be YYYY-MM-DD (from <= to, at most ' . self::MAX_DAYS . ' days); mode is last or first.' );
		}
		$ms = Board::range_ms( $range['from'], $range['to'], $tz );
		if ( null === $ms ) {
			return self::error( 'bad_range', 400 );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$data = Board::load( $ms, $range['from'], $range['to'], true );
		$opt  = array( 'mode' => $range['mode'], 'dim' => 'creative', 'from_ms' => $ms[0], 'to_ms' => $ms[1], 'tz' => $tz, 'unattributed' => true );
		$agg  = Board::aggregate( $data, $opt );
		$total_row = self::board_rows( array( array( 'key' => '*' ) + $agg['total'] ), $agg['profiles'] )[0];
		$unattr    = self::unattributed_row( (array) $agg['unattributed'], (int) $agg['unattributed_returning_ad'] );

		$daily        = array();
		$daily_totals = array();
		foreach ( self::days( $range['from'], $range['to'] ) as $day ) {
			$dms = Board::range_ms( $day, $day, $tz );
			$dag = Board::aggregate( self::day_data( $data, $day ), array( 'from_ms' => $dms[0], 'to_ms' => $dms[1] ) + $opt );
			$steps = array();
			foreach ( $dag['rows'] as $r ) {
				$steps[ (string) $r['key'] ] = self::funnel_fields( $r );
			}
			foreach ( Daily_Report::report_rows( $dag['rows'], (array) $data['creatives'] ) as $row ) {
				$clean = Contract::report_row( $row );
				if ( null !== $clean ) {
					$daily[] = array( 'day' => $day ) + $clean + ( $steps[ $clean['code'] ] ?? self::funnel_fields( array() ) );
				}
			}
			// 2026-11-26 双桥需求 #5：每天的广告合计 / 未归因 / 全店（daily_rows 只放素材行，新加的三项单独放，不打乱老的消费逻辑）。
			$d_total  = self::board_rows( array( array( 'key' => '*' ) + $dag['total'] ), array() )[0];
			$d_unattr = self::unattributed_row( (array) $dag['unattributed'], (int) $dag['unattributed_returning_ad'] );
			unset( $d_total['behavior'] );
			$daily_totals[] = array( 'day' => $day, 'total' => $d_total, 'unattributed' => $d_unattr, 'store_total' => self::store_total( $d_total, $d_unattr ) );
		}

		$ledger = self::orders_ledger( $ms, $range['mode'], $store_tz, (array) $data['ads'], (array) $data['creatives'], $tz );
		return new \WP_REST_Response(
			array(
				'ok'                => true,
				'store_timezone'    => $store_tz,
				'applied_timezone'  => null !== $applied ? $applied['label'] : $store_tz,
				'from'              => $range['from'],
				'to'                => $range['to'],
				'mode'              => $range['mode'],
				'partial'           => ! empty( $data['error'] ),
				'creatives_catalog' => self::catalog(),
				'ads_map'           => self::ads_map(),
				'unregistered_ads'  => Creatives::unregistered_ads(),
				'range_board'       => array(
					'rows'              => self::board_rows( $agg['rows'], $agg['profiles'] ),
					'total'             => $total_row,
					'unattributed'      => $unattr,
					'store_total'       => self::store_total( $total_row, $unattr ),
					'dashboard_funnel'  => self::dashboard_funnel( $ms ),
					'profiles'          => $agg['profiles'],
					'untagged_visitors' => (int) $agg['untagged'],
					'paid_visitors'     => (int) $agg['paid'],
					'missing_param_ads' => Board::missing_params( (array) $data['spend'], (array) $agg['ad_day_visitors'], Board::MIN_CLICKS, (array) $data['ads'] ),
				),
				'daily_rows'        => $daily,
				'daily_totals'      => $daily_totals,
				'orders_ledger'     => $ledger['orders'],
				'orders_truncated'  => $ledger['truncated'],
			),
			200
		);
	}

	/**
	 * 付款排障字段（2026-11-26 双桥需求 #2，纯函数）。$book 是 PanaPay 存在订单上的付款尝试记录（只读）。
	 * PanaPay 的回调只告诉我们 FAILED，不给拒付原因 / 错误码，所以 failure_reason 只能说我们这边看到的情况，gateway_error_code 一直是 null。
	 *
	 * @param mixed $book
	 */
	public static function payment_fields( string $status, $book ): array {
		$attempts = is_array( $book ) && is_array( $book['attempts'] ?? null ) ? $book['attempts'] : array();
		$active   = is_array( $book ) && is_string( $book['active'] ?? null ) ? $book['active'] : '';
		$state    = '' !== $active && is_array( $attempts[ $active ] ?? null ) ? (string) ( $attempts[ $active ]['state'] ?? '' ) : '';
		$reason   = null;
		if ( 'failed' === $status ) {
			$reason = 'failed' === $state ? 'gateway_reported_failure' : 'order_marked_failed';
		} elseif ( in_array( $status, array( 'pending', 'on-hold' ), true ) && 'unknown' === $state ) {
			$reason = 'payment_result_unknown';
		}
		return array(
			'payment_status_norm' => self::PAYMENT_NORM[ $status ] ?? 'OTHER',
			'failure_reason'      => $reason,
			'gateway_error_code'  => null,
			'payment_attempts'    => count( $attempts ),
			'last_attempt_state'  => '' !== $state ? $state : null,
		);
	}

	// ---------- 6. creatives/delete ----------

	/**
	 * 删素材（2026-11-26 双桥需求 #6）。入参 {code, if_in_use: archive | fail}（默认 fail）。
	 * 规则和素材库的“删除”一样：没有访客、花费、广告用过才真删，删掉的编号不再发给新素材（免得 Meta 里还挂着的旧链接算错）；
	 * 有数据的按 if_in_use 归档（数据保留）或返回 409 creative_in_use。重复删已删掉的编号返回 already_deleted。
	 */
	public static function rest_delete( \WP_REST_Request $request ) {
		$p    = (array) $request->get_json_params();
		$code = strtoupper( trim( is_string( $p['code'] ?? null ) ? $p['code'] : '' ) );
		$mode = is_string( $p['if_in_use'] ?? null ) && '' !== $p['if_in_use'] ? $p['if_in_use'] : 'fail';
		if ( ! Contract::valid_code( $code ) ) {
			return self::error( 'bad_code', 400, 'code must be a creative code like C0006.' );
		}
		if ( ! in_array( $mode, array( 'archive', 'fail' ), true ) ) {
			return self::error( 'bad_if_in_use', 400, 'if_in_use must be archive or fail.' );
		}
		$row = Creatives::get( $code );
		if ( null === $row ) {
			if ( in_array( $code, Creatives::retired_codes(), true ) ) {
				return new \WP_REST_Response( array( 'ok' => true, 'code' => $code, 'result' => 'already_deleted', 'code_retired' => true ), 200 );
			}
			return self::error( 'creative_not_found', 404, 'No creative with this code on this store.' );
		}
		$why = Creatives::delete_blocker( $code );
		if ( '' === $why ) {
			$done = Creatives::delete( $code );
			if ( is_wp_error( $done ) ) {
				return self::error( 'delete_failed', 500, 'The creative could not be deleted; try again.' );
			}
			return new \WP_REST_Response( array( 'ok' => true, 'code' => $code, 'result' => 'deleted', 'code_retired' => true ), 200 );
		}
		if ( 'fail' === $mode ) {
			return self::error( 'creative_in_use', 409, $code . ' already has visitors, spend or a linked ad; pass if_in_use=archive to archive it instead.' );
		}
		if ( Creatives::STATUS_ARCHIVED !== (string) ( $row['status'] ?? '' ) && ! Creatives::set_status( $code, Creatives::STATUS_ARCHIVED ) ) {
			return self::error( 'archive_failed', 500, 'The creative could not be archived; try again.' );
		}
		return new \WP_REST_Response( array( 'ok' => true, 'code' => $code, 'result' => 'archived', 'in_use' => true ), 200 );
	}

	/** 全部已登记素材。 */
	private static function catalog(): array {
		$pages = \PeptideCore\Admin\Onboarding::landing_pages();
		$out   = array();
		foreach ( Creatives::all() as $code => $c ) {
			$out[] = array(
				'code'       => (string) $code,
				'status'     => (string) $c['status'],
				'attrs'      => (array) $c['attrs'],
				'note'       => (string) $c['note'],
				'thumb_url'  => (int) $c['attachment_id'] > 0 ? (string) wp_get_attachment_image_url( (int) $c['attachment_id'], 'thumbnail' ) : '',
				'landing_url' => Ads_Panel::landing_of( (string) $code, $pages ),
				'lp_ver'     => Ads_Panel::lp_ver_of( (string) $code ),
				'created_at' => (int) $c['created_at'],
			);
		}
		return $out;
	}

	/** 广告表全部映射。 */
	private static function ads_map(): array {
		global $wpdb;
		if ( ! Schema::installed() ) {
			return array();
		}
		$t = Schema::table( 'ads' );
		return array_map(
			static fn( array $r ): array => array(
				'ad_id'         => (string) $r['ad_id'],
				'code'          => (string) $r['code'],
				'ad_name'       => (string) $r['ad_name'],
				'adset_id'      => (string) $r['adset_id'],
				'campaign_id'   => (string) $r['campaign_id'],
				'first_seen_ms' => (int) $r['first_seen_ms'],
				'last_seen_ms'  => (int) $r['last_seen_ms'],
			),
			(array) $wpdb->get_results( "SELECT ad_id, code, ad_name, adset_id, campaign_id, first_seen_ms, last_seen_ms FROM {$t} ORDER BY first_seen_ms DESC", ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		);
	}

	/**
	 * 范围内的订单（店铺时区按下单时间），脱敏。超过 MAX_ORDERS 张时截断，最后一项 truncated 标出。
	 *
	 * @param array{0:int,1:int} $ms
	 */
	private static function orders_ledger( array $ms, string $mode, string $tz, array $ads, array $creatives, string $applied_tz = '' ): array {
		global $wpdb;
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array( 'orders' => array(), 'truncated' => false );
		}
		$orders = wc_get_orders(
			array(
				'type'         => 'shop_order',
				'status'       => self::ORDER_STATUSES,
				'date_created' => intdiv( $ms[0], 1000 ) . '...' . ( intdiv( $ms[1], 1000 ) - 1 ),
				'orderby'      => 'date',
				'order'        => 'ASC',
				'limit'        => self::MAX_ORDERS + 1,
			)
		);
		$orders    = is_array( $orders ) ? $orders : array();
		$truncated = count( $orders ) > self::MAX_ORDERS;
		$orders    = array_slice( $orders, 0, self::MAX_ORDERS );

		// 下单访问 → 访客 → 他的付费访问。
		$order_visit = array();
		$paid        = array();
		if ( $orders && \PeptideCore\Tracking\Schema::installed() ) {
			$visits = \PeptideCore\Tracking\Schema::table( 'visits' );
			$ids    = array_map( static fn( $o ) => (int) $o->get_id(), $orders );
			foreach ( array_chunk( $ids, 500 ) as $chunk ) {
				$in = implode( ',', array_map( 'intval', $chunk ) );
				foreach ( (array) $wpdb->get_results( "SELECT order_id, visitor_id, start_ms FROM {$visits} WHERE order_id IN ({$in}) AND stage >= 5 ORDER BY start_ms", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
					$order_visit[ (int) $r['order_id'] ] = $order_visit[ (int) $r['order_id'] ] ?? array( 'visitor_id' => (int) $r['visitor_id'], 'start_ms' => (int) $r['start_ms'] );
				}
			}
			// 2026-11-26（双桥需求 #2）：没付款的订单，访问只到“下单 / 进入结账”（阶段 3–4），上面按阶段 5 找不到 → 以前不带归因。
			// 付过款的订单还按上面那次（结果不变），没找到的再不限阶段找一次。
			$missing = array_values( array_diff( $ids, array_keys( $order_visit ) ) );
			foreach ( array_chunk( $missing, 500 ) as $chunk ) {
				$in = implode( ',', array_map( 'intval', $chunk ) );
				foreach ( (array) $wpdb->get_results( "SELECT order_id, visitor_id, start_ms FROM {$visits} WHERE order_id IN ({$in}) ORDER BY start_ms", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
					$order_visit[ (int) $r['order_id'] ] = $order_visit[ (int) $r['order_id'] ] ?? array( 'visitor_id' => (int) $r['visitor_id'], 'start_ms' => (int) $r['start_ms'] );
				}
			}
			$vids = array_values( array_unique( array_map( static fn( $v ) => $v['visitor_id'], $order_visit ) ) );
			foreach ( array_chunk( $vids, 500 ) as $chunk ) {
				$in = implode( ',', array_map( 'intval', $chunk ) );
				foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT visitor_id, start_ms, utm_content, creative_code, ad_name FROM {$visits} WHERE visitor_id IN ({$in}) AND source_class = %s AND start_ms < %d", Contract::SOURCE_PAID, $ms[1] ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
					$paid[ (int) $r['visitor_id'] ][] = $r;
				}
			}
		}

		$cents = static fn( $v ): int => (int) round( (float) $v * 100 );
		$out   = array();
		foreach ( $orders as $o ) {
			if ( ! $o instanceof \WC_Order ) {
				continue;
			}
			$id      = (int) $o->get_id();
			$created = $o->get_date_created();
			$ts      = $created ? (int) $created->getTimestamp() : 0;
			$email   = (string) $o->get_billing_email();
			$name    = trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() . ' ' . $o->get_shipping_first_name() . ' ' . $o->get_shipping_last_name() );
			$country = (string) ( $o->get_shipping_country() ?: $o->get_billing_country() );
			$state   = (string) ( $o->get_shipping_state() ?: $o->get_billing_state() );
			$post    = (string) ( $o->get_shipping_postcode() ?: $o->get_billing_postcode() );
			$attr    = isset( $order_visit[ $id ] ) ? self::order_attribution( $paid[ $order_visit[ $id ]['visitor_id'] ] ?? array(), $order_visit[ $id ]['start_ms'], $mode, $ads, $creatives ) : array( 'code' => '', 'ad_id' => '' );
			$items   = array();
			foreach ( $o->get_items( 'line_item' ) as $item ) {
				$product = $item->get_product();
				$items[] = array(
					'sku'              => $product ? (string) $product->get_sku() : '',
					'product_id'       => (int) $item->get_product_id(),
					'name'             => (string) $item->get_name(),
					'qty'              => (int) $item->get_quantity(),
					'line_total_cents' => $cents( $item->get_total() ),
				);
			}
			$out[] = array(
				'order_id'           => $id,
				'created_at_gmt'     => $ts > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', $ts ) : '',
				'day_store_tz'       => $ts > 0 ? Board::day( $ts * 1000, $tz ) : '',
				'status'             => (string) $o->get_status(),
				'currency'           => (string) $o->get_currency(),
				'subtotal_cents'     => $cents( $o->get_subtotal() ),
				'discount_cents'     => $cents( $o->get_discount_total() ),
				'shipping_cents'     => $cents( $o->get_shipping_total() ),
				'total_cents'        => $cents( $o->get_total() ),
				'refunded_cents'     => $cents( $o->get_total_refunded() ),
				'payment_method'     => (string) $o->get_payment_method(),
				'country_code'       => $country,
				'state_code'         => $state,
				'postcode_prefix'    => self::postcode_prefix( $post ),
				'customer_hash'      => self::customer_hash( $email, (string) $o->get_billing_phone() ),
				'is_test_hint'       => self::test_hint( $email, $name ) || ( class_exists( \PeptideCore\Test_Orders::class ) && \PeptideCore\Test_Orders::is_test_order( $o ) ),
				'attributed_cr_code' => $attr['code'],
				'attributed_ad_id'   => $attr['ad_id'],
				'items'              => $items,
			) + self::payment_fields( (string) $o->get_status(), is_callable( array( $o, 'get_meta' ) ) ? $o->get_meta( self::PANAPAY_SESSIONS_META, true ) : '' ) + array(
				'day_applied_tz' => $ts > 0 && '' !== $applied_tz ? Board::day( $ts * 1000, $applied_tz ) : ( $ts > 0 ? Board::day( $ts * 1000, $tz ) : '' ),
			);
		}
		return array( 'orders' => $out, 'truncated' => $truncated );
	}
}
