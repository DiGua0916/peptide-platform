<?php
/**
 * 系统设置 → 基础配置（BASIC-CONFIG-01），对照 Shopname 的“基础配置”页。
 *
 * 能对应到 WordPress / WooCommerce 原有设置的，直接读写原设置（店铺名称、客服邮箱、Favicon、货币、时区、
 * 货币格式、语言、店铺地址、打烊）；其余选项存在 peptide_core_store_config，由这里的钩子在运行时生效：
 *
 * - SPU / SKU 自动编码：商品保存时 SKU 为空才生成，不改已有 SKU；SPU 也可以在商品编辑页手动填写。复制商品时不带 SPU。
 * - 回访用户计算时间：按 Shopname 的算法，第一次访问当天结束（店铺时区 23:59:59）之后再过 N 小时，再来的算回访。
 * - 禁止爬虫访问：勾选的常见爬虫 + 自定义关键词；防爬 / 高风险 IP 限制 = 系统内置名单。前台返回 403，并写进 robots.txt。
 *   后台、命令行和定时任务不受影响。
 * - 后台登录验证码：wp-login.php 登录表单加一道算术题；前台图形验证码：“我的账户”登录 / 注册表单加同样的算术题。
 * - 商品标题搜索方式：智能搜索（不足 2 个词全部包含，超过则 80% 的词匹配）或全部包含；前台搜索字段另外匹配 SKU / 分类 / 标签。
 * - 强制注册 / 登录：未登录访客跳到账户页，新注册账户需要管理员在“用户”里批准后才能登录。
 * - 注册邮箱验证、前台排序字段、iframe、已下架商品（404 / 可见但不可购买 / 可见且可购买 / 跳转）、
 *   多款式默认选中（首个 / 价格最低 / 价格最高 / 库存最多 / 不选中）、售罄款式、公开联系方式的 JSON-LD。
 *
 * Shopname 平台专有、在自建站上没有对应功能的选项（临时域名、静态资源路径、代码模式、缓存时间、
 * 像素延迟、多币种结算、商品意向统计、已删除款式）不做，页面上列出了原因。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Store_Config {

	public const OPTION = 'peptide_core_store_config';

	/** 系统内置的拦截名单：安全扫描器和不遵守 robots 的采集爬虫。不含搜索引擎和常用 SEO 工具。 */
	public const SYSTEM_BOTS = array(
		'sqlmap', 'nikto', 'masscan', 'zgrab', 'nmap', 'wpscan', 'acunetix', 'netsparker', 'nuclei', 'dirbuster',
		'gobuster', 'wfuzz', 'feroxbuster', 'nessus', 'openvas', 'whatweb', 'jaeles', 'httpx', 'censysinspect',
		'expanseinc', 'internet-measurement', 'l9explore', 'l9tcpid', 'odin', 'paloaltonetworks', 'xpanse',
		'bytespider', 'mj12bot', 'dotbot', 'blexbot', 'megaindex', 'serpstatbot', 'dataforseobot', 'barkrowler',
		'seekportbot', 'imagesiftbot', 'claudebot-scraper', 'go-http-client/1.1', 'scrapy', 'petalbot',
	);

	/** “禁止爬虫访问”可以勾选的常见爬虫：显示名称 => User-Agent 关键词（也是 robots.txt 里的名称）。 */
	public const KNOWN_BOTS = array(
		'Googlebot'          => 'googlebot',
		'Bingbot'            => 'bingbot',
		'Baiduspider'        => 'baiduspider',
		'YandexBot'          => 'yandexbot',
		'DuckDuckBot'        => 'duckduckbot',
		'Applebot'           => 'applebot',
		'AhrefsBot'          => 'ahrefsbot',
		'SemrushBot'         => 'semrushbot',
		'GPTBot'             => 'gptbot',
		'ClaudeBot'          => 'claudebot',
		'CCBot'              => 'ccbot',
		'PerplexityBot'      => 'perplexitybot',
		'Amazonbot'          => 'amazonbot',
		'Meta / Facebook'    => 'facebookexternalhit',
	);

	/** 商品标题搜索方式。 */
	public const SEARCH_MODES = array( 'smart', 'all', 'front_smart' );

	/** 多款式商品默认选中。 */
	public const DEFAULT_VARIANTS = array( 'first', 'lowest', 'highest', 'stock', 'none' );

	/** 商品详情页，已下架商品展示。 */
	public const OFFLINE_MODES = array( '404', 'visible', 'visible_buy', 'home', 'shop' );

	/** 前台排序字段（键 = WooCommerce orderby 值）。 */
	public const SORTS = array( 'menu_order', 'popularity', 'title', 'title-desc', 'price', 'price-desc', 'date-asc', 'date' );

	/** 货币格式：千分位、小数点、小数位数。 */
	public const PRICE_FORMATS = array(
		'123,456.78' => array( ',', '.', 2 ),
		'123.456,78' => array( '.', ',', 2 ),
		'123 456,78' => array( ' ', ',', 2 ),
		'123456.78'  => array( '', '.', 2 ),
		'123,456'    => array( ',', '.', 0 ),
		'123.456'    => array( '.', ',', 0 ),
	);

	/** 回访用户计算时间可选值（小时）。 */
	public const RETURNING_HOURS = array( 1, 6, 12, 24, 72, 168 );

	private const CAPTCHA_FIELD = 'pc_login_captcha';

	private const VERIFY_META = '_pc_email_unverified';

	public const APPROVAL_META = '_pc_pending_approval';

	private static bool $saving = false;

	public static function defaults(): array {
		return array(
			'spu_mode'         => 'random',
			'sku_auto'         => true,
			'sku_pattern'      => '{SPU}-{SKU_CODE}',
			'returning_hours'  => 24,
			'bot_system'       => true,
			'bot_known'        => array(),
			'bot_custom'       => array(),
			'admin_captcha'    => false,
			'front_captcha'    => false,
			'search_mode'      => 'smart',
			'search_fields'    => array( 'title', 'sku', 'category' ),
			'force_login'      => false,
			'register_verify'  => false,
			'sorts'            => self::SORTS,
			'allow_iframe'     => false,
			'product_link'     => 'product',
			'offline_product'  => '404',
			'default_variant'  => 'first',
			'soldout_variants' => 'show',
			'finance_email'    => '',
			'company_name'     => '',
			'company_phone'    => '',
		);
	}

	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		$out   = array_replace( self::defaults(), is_array( $saved ) ? $saved : array() );
		return $out;
	}

	public static function get( string $key ) {
		return self::all()[ $key ] ?? null;
	}

	public static function update( array $values ): void {
		update_option( self::OPTION, array_replace( self::all(), array_intersect_key( $values, self::defaults() ) ), false );
	}

	public static function register_hooks(): void {
		add_filter( 'woocommerce_email_from_name', array( self::class, 'from_name_fallback' ), 20 );
		add_action( 'woocommerce_new_product', array( self::class, 'maybe_generate_codes' ), 20 );
		add_action( 'woocommerce_update_product', array( self::class, 'maybe_generate_codes' ), 20 );
		add_action( 'woocommerce_new_product_variation', array( self::class, 'maybe_generate_codes' ), 20 );
		add_action( 'woocommerce_update_product_variation', array( self::class, 'maybe_generate_codes' ), 20 );
		add_action( 'init', array( self::class, 'block_bots' ), 1 );
		add_action( 'login_form', array( self::class, 'captcha_field' ) );
		add_filter( 'authenticate', array( self::class, 'check_captcha' ), 30, 3 );
		add_filter( 'posts_search', array( self::class, 'product_search' ), 20, 2 );
		add_action( 'template_redirect', array( self::class, 'force_login' ), 5 );
		add_action( 'template_redirect', array( self::class, 'offline_product' ), 6 );
		add_filter( 'woocommerce_registration_auth_new_customer', array( self::class, 'hold_new_customer' ), 20, 2 );
		add_filter( 'wp_authenticate_user', array( self::class, 'block_unverified' ), 20 );
		add_action( 'template_redirect', array( self::class, 'handle_verify_link' ), 4 );
		add_action( 'after_password_reset', array( self::class, 'verified_by_reset' ) );
		add_filter( 'woocommerce_catalog_orderby', array( self::class, 'catalog_orderby' ), 20 );
		add_filter( 'woocommerce_default_catalog_orderby', array( self::class, 'default_orderby' ), 20 );
		add_action( 'send_headers', array( self::class, 'frame_header' ) );
		add_filter( 'woocommerce_product_get_default_attributes', array( self::class, 'default_attributes' ), 20, 2 );
		add_filter( 'woocommerce_variation_is_visible', array( self::class, 'variation_visible' ), 20, 4 );
		add_action( 'init', array( self::class, 'maybe_flush_rewrites' ), 99 );
		add_filter( 'robots_txt', array( self::class, 'robots_txt' ), 20, 2 );
		add_filter( 'woocommerce_product_pre_search_products', array( self::class, 'admin_product_search' ), 20, 8 );
		add_action( 'woocommerce_login_form', array( self::class, 'front_captcha_field' ), 15 );
		add_action( 'woocommerce_register_form', array( self::class, 'front_captcha_field' ), 15 );
		add_filter( 'woocommerce_process_login_errors', array( self::class, 'check_front_captcha' ), 15 );
		add_filter( 'woocommerce_registration_errors', array( self::class, 'check_front_captcha' ), 15 );
		add_action( 'pre_get_posts', array( self::class, 'show_offline_products' ) );
		add_filter( 'woocommerce_is_purchasable', array( self::class, 'offline_purchasable' ), 20, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', array( self::class, 'offline_variation_purchasable' ), 20, 2 );
		add_filter( 'wp_robots', array( self::class, 'offline_noindex' ) );
		add_action( 'wp_head', array( self::class, 'json_ld' ), 30 );
		add_filter( 'woocommerce_duplicate_product_exclude_meta', array( self::class, 'duplicate_exclude_meta' ) );
		add_action( 'woocommerce_product_options_sku', array( self::class, 'spu_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( self::class, 'save_spu_field' ) );
		add_filter( 'user_row_actions', array( self::class, 'user_row_actions' ), 10, 2 );
		add_action( 'admin_post_pc_approve_user', array( self::class, 'handle_approve' ) );
		add_filter( 'bulk_actions-users', array( self::class, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-users', array( self::class, 'handle_bulk' ), 10, 3 );
		add_filter( 'manage_users_columns', array( self::class, 'users_column' ) );
		add_filter( 'manage_users_custom_column', array( self::class, 'users_column_value' ), 10, 3 );
		add_action( 'admin_notices', array( self::class, 'approval_notice' ) );
	}

	// ---------- 店铺信息 ----------

	/** 客服名称为空时，发件人名称用店铺名称。 */
	public static function from_name_fallback( $name ): string {
		$name = trim( (string) $name );
		return '' !== $name ? $name : wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/** 已有订单（草稿以外）时不能再改店铺货币。 */
	public static function currency_locked(): bool {
		$ids = wc_get_orders(
			array(
				'limit'  => 1,
				'return' => 'ids',
				'type'   => 'shop_order',
				'status' => array_diff( array_keys( wc_get_order_statuses() ), array( 'wc-checkout-draft' ) ),
			)
		);
		return ! empty( $ids );
	}

	public static function price_format_key(): string {
		$cur = array( (string) get_option( 'woocommerce_price_thousand_sep', ',' ), (string) get_option( 'woocommerce_price_decimal_sep', '.' ), (int) get_option( 'woocommerce_price_num_decimals', 2 ) );
		foreach ( self::PRICE_FORMATS as $key => $f ) {
			if ( $f === $cur ) {
				return $key;
			}
		}
		return '';
	}

	/** 公开联系方式（邮件页脚、[pc_var] 用）。 */
	public static function contact(): array {
		$country = (string) get_option( 'woocommerce_default_country', '' );
		[ $cc, $state ] = array_pad( explode( ':', $country ), 2, '' );
		return array(
			'company'  => (string) self::get( 'company_name' ),
			'phone'    => (string) self::get( 'company_phone' ),
			'country'  => $cc,
			'state'    => $state,
			'city'     => (string) get_option( 'woocommerce_store_city', '' ),
			'address'  => (string) get_option( 'woocommerce_store_address', '' ),
			'postcode' => (string) get_option( 'woocommerce_store_postcode', '' ),
		);
	}

	/** 一行地址：地址, 市, 州 邮编, 国家。 */
	public static function address_line(): string {
		$c       = self::contact();
		$states  = $c['country'] ? (array) WC()->countries->get_states( $c['country'] ) : array();
		$country = WC()->countries->get_countries()[ $c['country'] ] ?? $c['country'];
		$region  = trim( ( $states[ $c['state'] ] ?? $c['state'] ) . ' ' . $c['postcode'] );
		return implode( ', ', array_filter( array( $c['address'], $c['city'], $region, $country ) ) );
	}

	// ---------- SPU / SKU 自动编码 ----------

	/** 商品的 SPU：保存在 _pc_spu，第一次需要时生成。 */
	public static function spu( \WC_Product $product ): string {
		$spu = (string) $product->get_meta( '_pc_spu' );
		if ( '' !== $spu ) {
			return $spu;
		}
		if ( 'id' === self::get( 'spu_mode' ) ) {
			$spu = (string) $product->get_id();
		} else {
			do {
				$spu = (string) wp_rand( 1, 9 );
				for ( $i = 0; $i < 17; $i++ ) {
					$spu .= (string) wp_rand( 0, 9 );
				}
			} while ( wc_get_product_id_by_sku( $spu ) );
		}
		update_post_meta( $product->get_id(), '_pc_spu', $spu );
		$product->update_meta_data( '_pc_spu', $spu );
		return $spu;
	}

	public static function maybe_generate_codes( $product_id ): void {
		if ( self::$saving || ! self::get( 'sku_auto' ) ) {
			return;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product || '' !== (string) $product->get_sku( 'edit' ) || in_array( $product->get_status(), array( 'auto-draft', 'trash' ), true ) ) {
			return;
		}
		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( ! $parent ) {
				return;
			}
			$ids  = $parent->get_children();
			sort( $ids );
			$pos  = array_search( $product->get_id(), $ids, true );
			$code = str_pad( (string) ( false === $pos ? count( $ids ) + 1 : $pos + 1 ), 2, '0', STR_PAD_LEFT );
			$sku  = strtr( (string) self::get( 'sku_pattern' ), array( '{SPU}' => self::spu( $parent ), '{SKU_CODE}' => $code ) );
		} else {
			$sku = self::spu( $product );
		}
		$base = $sku;
		$n    = 1;
		while ( ! wc_product_has_unique_sku( $product->get_id(), $sku ) ) {
			$sku = $base . '-' . ( ++$n );
		}
		self::$saving = true;
		try {
			$product->set_sku( $sku );
			$product->save();
		} catch ( \Throwable $e ) {
			// SKU 冲突等情况不影响商品保存。
			unset( $e );
		} finally {
			self::$saving = false;
		}
	}

	// ---------- 爬虫拦截 ----------

	/** @return string[] 当前生效的拦截关键词（小写）。 */
	public static function bot_keywords(): array {
		$list = self::get( 'bot_system' ) ? self::SYSTEM_BOTS : array();
		foreach ( (array) self::get( 'bot_known' ) as $k ) {
			if ( in_array( $k, self::KNOWN_BOTS, true ) ) {
				$list[] = $k;
			}
		}
		foreach ( (array) self::get( 'bot_custom' ) as $k ) {
			$k = strtolower( trim( (string) $k ) );
			if ( '' !== $k ) {
				$list[] = $k;
			}
		}
		return array_values( array_unique( $list ) );
	}

	public static function is_blocked_agent( string $ua ): bool {
		$ua = strtolower( $ua );
		if ( '' === $ua ) {
			return false;
		}
		foreach ( self::bot_keywords() as $k ) {
			if ( str_contains( $ua, $k ) ) {
				return true;
			}
		}
		return false;
	}

	public static function block_bots(): void {
		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return;
		}
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( self::is_blocked_agent( $ua ) ) {
			status_header( 403 );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'Forbidden';
			exit;
		}
	}

	/**
	 * robots.txt：勾选的常见爬虫和自定义关键词各加一条 Disallow（守规矩的爬虫会直接不来，不守规矩的由 403 拦截）。
	 *
	 * @param string $output
	 */
	public static function robots_txt( $output, $public = true ): string {
		$names = array();
		foreach ( (array) self::get( 'bot_known' ) as $k ) {
			$label = array_search( $k, self::KNOWN_BOTS, true );
			if ( false !== $label ) {
				$names[] = 'Meta / Facebook' === $label ? 'facebookexternalhit' : $label;
			}
		}
		foreach ( (array) self::get( 'bot_custom' ) as $k ) {
			if ( preg_match( '/^[a-z0-9._-]+$/i', (string) $k ) ) {
				$names[] = (string) $k;
			}
		}
		$out = (string) $output;
		foreach ( array_unique( $names ) as $n ) {
			$out .= "\nUser-agent: {$n}\nDisallow: /\n";
		}
		return $out;
	}

	// ---------- 后台登录验证码 ----------

	private static function captcha_sign( int $answer, int $expires ): string {
		return hash_hmac( 'sha256', $answer . '|' . $expires, wp_salt( 'nonce' ) );
	}

	public static function captcha_field(): void {
		if ( ! self::get( 'admin_captcha' ) ) {
			return;
		}
		echo self::captcha_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 已转义。
	}

	private static function captcha_html( string $class = 'input' ): string {
		$a       = wp_rand( 1, 9 );
		$b       = wp_rand( 1, 9 );
		$expires = time() + 15 * MINUTE_IN_SECONDS;
		/* translators: 1, 2: numbers to add */
		return '<p class="pc-captcha form-row"><label for="pc-captcha">' . esc_html( sprintf( __( 'Security check: what is %1$d + %2$d?', 'peptide-core' ), $a, $b ) ) . '</label>'
			. '<input type="text" name="' . esc_attr( self::CAPTCHA_FIELD ) . '" id="pc-captcha" class="' . esc_attr( $class ) . '" inputmode="numeric" autocomplete="off" size="4" required></p>'
			. '<input type="hidden" name="pc_captcha_token" value="' . esc_attr( $expires . '.' . self::captcha_sign( $a + $b, $expires ) ) . '">';
	}

	/** 提交的算术题答案是否正确（15 分钟内有效）。 */
	public static function captcha_ok(): bool {
		$answer = isset( $_POST[ self::CAPTCHA_FIELD ] ) ? (int) wp_unslash( $_POST[ self::CAPTCHA_FIELD ] ) : -1; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$token  = isset( $_POST['pc_captcha_token'] ) ? sanitize_text_field( wp_unslash( $_POST['pc_captcha_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		[ $expires, $sig ] = array_pad( explode( '.', $token, 2 ), 2, '' );
		return (int) $expires >= time() && hash_equals( self::captcha_sign( $answer, (int) $expires ), $sig );
	}

	/** 前台图形验证码：“我的账户”的登录、注册表单。 */
	public static function front_captcha_field(): void {
		if ( self::get( 'front_captcha' ) ) {
			echo self::captcha_html( 'woocommerce-Input input-text' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 已转义。
		}
	}

	/** @param \WP_Error $errors */
	public static function check_front_captcha( $errors ) {
		if ( self::get( 'front_captcha' ) && $errors instanceof \WP_Error && ! self::captcha_ok() ) {
			$errors->add( 'pc_captcha', __( 'The security check answer is wrong. Please try again.', 'peptide-core' ) );
		}
		return $errors;
	}

	/**
	 * 只检查 wp-login.php 的表单登录；应用密码、REST、XML-RPC 不受影响。
	 *
	 * @param mixed $user
	 */
	public static function check_captcha( $user, $username = '', $password = '' ) {
		if ( ! self::get( 'admin_captcha' ) || ! isset( $_POST['log'] ) || 'wp-login.php' !== ( $GLOBALS['pagenow'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return $user;
		}
		if ( ! self::captcha_ok() ) {
			return new \WP_Error( 'pc_captcha', __( '<strong>Error:</strong> The security check answer is wrong. Please try again.', 'peptide-core' ) );
		}
		return $user;
	}

	// ---------- 前台搜索字段 ----------

	/** 搜索词拆成词（最多 8 个，去重）。 */
	public static function search_words( string $s ): array {
		$words = preg_split( '/\s+/u', trim( $s ) );
		return array_slice( array_values( array_unique( array_filter( array_map( 'strval', (array) $words ), static fn( $w ) => '' !== $w ) ) ), 0, 8 );
	}

	/** 智能搜索需要匹配的词数：不足 2 个词（含 2 个）全部包含，超过则 80%（向上取整）。 */
	public static function words_needed( int $n, string $mode ): int {
		return 'all' === $mode || $n <= 2 ? $n : (int) ceil( $n * 0.8 );
	}

	/**
	 * 商品标题条件：标题里包含的搜索词数量 >= 需要的词数。
	 *
	 * @param string $column 标题字段，例如 wp_posts.post_title
	 */
	public static function title_condition( string $s, string $mode, string $column ): string {
		global $wpdb;
		$words = self::search_words( $s );
		if ( ! $words ) {
			return '1=1';
		}
		$parts = array();
		foreach ( $words as $w ) {
			$parts[] = $wpdb->prepare( "({$column} LIKE %s)", '%' . $wpdb->esc_like( $w ) . '%' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return '(' . implode( ' + ', $parts ) . ') >= ' . self::words_needed( count( $words ), $mode );
	}

	/** 前台用的搜索方式（“前台智能、后台全包含”在前台也是智能搜索）。 */
	private static function front_mode(): string {
		return 'all' === self::get( 'search_mode' ) ? 'all' : 'smart';
	}

	/**
	 * 前台商品搜索：按“商品标题搜索方式”匹配标题，并按“前台搜索字段”同时匹配 SKU、分类、标签。
	 *
	 * @param string    $search
	 * @param \WP_Query $query
	 */
	public static function product_search( $search, $query ) {
		global $wpdb;
		if ( is_admin() || ! $query instanceof \WP_Query || '' === (string) $query->get( 's' ) || '' === trim( (string) $search ) ) {
			return $search;
		}
		$types = (array) $query->get( 'post_type' );
		if ( ! in_array( 'product', $types, true ) || count( array_diff( $types, array( 'product', 'product_variation' ) ) ) ) {
			return $search;
		}
		$fields = array_intersect( (array) self::get( 'search_fields' ), array( 'sku', 'category', 'tag' ) );
		$like   = '%' . $wpdb->esc_like( trim( (string) $query->get( 's' ) ) ) . '%';
		$lookup = $wpdb->prefix . 'wc_product_meta_lookup';
		$extra  = array();
		if ( in_array( 'sku', $fields, true ) ) {
			// 商品自己的 SKU，或它某个款式的 SKU。
			$extra[] = $wpdb->prepare( "{$wpdb->posts}.ID IN (SELECT product_id FROM {$lookup} WHERE sku LIKE %s)", $like ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$extra[] = $wpdb->prepare( "{$wpdb->posts}.ID IN (SELECT v.post_parent FROM {$wpdb->posts} v INNER JOIN {$lookup} l ON l.product_id = v.ID WHERE v.post_type = 'product_variation' AND l.sku LIKE %s)", $like ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$tax = array();
		if ( in_array( 'category', $fields, true ) ) {
			$tax[] = 'product_cat';
		}
		if ( in_array( 'tag', $fields, true ) ) {
			$tax[] = 'product_tag';
		}
		if ( $tax ) {
			$in      = implode( ',', array_fill( 0, count( $tax ), '%s' ) );
			$extra[] = $wpdb->prepare( "{$wpdb->posts}.ID IN (SELECT tr.object_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tt.taxonomy IN ({$in}) AND t.name LIKE %s)", array_merge( $tax, array( $like ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		// WordPress 生成的条件形如 " AND ((关键词条件))"，未登录时后面还有 " AND (post_password = '')"。
		// 关键词条件换成标题条件，额外条件用 OR 并上去，密码条件原样保留在外面。
		$search = (string) $search;
		$pw     = '';
		if ( preg_match( "/\s+AND\s+\(\S+\.post_password = ''\)\s*$/", $search, $m ) ) {
			$pw = $m[0];
		}
		$terms = '(' . self::title_condition( (string) $query->get( 's' ), self::front_mode(), "{$wpdb->posts}.post_title" ) . ')';
		return ' AND (' . $terms . ( $extra ? ' OR (' . implode( ') OR (', $extra ) . ')' : '' ) . ')' . $pw;
	}

	/**
	 * 后台商品搜索（商品列表、选择商品的下拉框）。“前后台一致”时用同样的标题匹配，另外匹配 SKU 和商品 ID；
	 * “前台智能、后台全包含”时交给 WooCommerce 原来的搜索。
	 *
	 * @param mixed $custom
	 */
	public static function admin_product_search( $custom, $term = '', $type = '', $include_variations = false, $all_statuses = false, $limit = null, $include = null, $exclude = null ) {
		global $wpdb;
		$mode = (string) self::get( 'search_mode' );
		if ( false !== $custom || 'front_smart' === $mode || '' === trim( (string) $term ) || '' !== (string) $type ) {
			return $custom;
		}
		$types    = $include_variations ? array( 'product', 'product_variation' ) : array( 'product' );
		$statuses = $all_statuses ? array( 'publish', 'draft', 'pending', 'private', 'future' ) : array( 'publish', 'private' );
		$lookup   = $wpdb->prefix . 'wc_product_meta_lookup';
		$like     = '%' . $wpdb->esc_like( trim( (string) $term ) ) . '%';
		$where    = array(
			'p.post_type IN (' . implode( ',', array_map( static fn( $t ) => "'" . esc_sql( $t ) . "'", $types ) ) . ')',
			'p.post_status IN (' . implode( ',', array_map( static fn( $t ) => "'" . esc_sql( $t ) . "'", $statuses ) ) . ')',
			'(' . self::title_condition( (string) $term, $mode, 'p.post_title' ) . $wpdb->prepare( " OR p.ID IN (SELECT product_id FROM {$lookup} WHERE sku LIKE %s) OR p.ID = %d", $like, absint( $term ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		if ( $include ) {
			$where[] = 'p.ID IN (' . implode( ',', array_map( 'absint', (array) $include ) ) . ')';
		}
		if ( $exclude ) {
			$where[] = 'p.ID NOT IN (' . implode( ',', array_map( 'absint', (array) $exclude ) ) . ')';
		}
		$sql = "SELECT DISTINCT p.ID FROM {$wpdb->posts} p WHERE " . implode( ' AND ', $where ) . ' ORDER BY p.post_title ASC' . ( $limit ? ' LIMIT ' . absint( $limit ) : '' );
		return array_map( 'intval', $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	// ---------- 强制注册 / 登录 ----------

	public static function force_login(): void {
		if ( ! self::get( 'force_login' ) || is_user_logged_in() || ! function_exists( 'is_account_page' ) ) {
			return;
		}
		if ( is_account_page() || is_page( self::open_pages() ) ) {
			return;
		}
		wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
		exit;
	}

	/** 强制登录时仍然公开的页面：隐私政策和标记为结账条款的政策页。 */
	public static function open_pages(): array {
		$ids = array( (int) get_option( 'wp_page_for_privacy_policy' ), (int) wc_terms_and_conditions_page_id() );
		foreach ( Custom_Pages::checkout_terms() as $p ) {
			$ids[] = (int) ( $p['id'] ?? 0 );
		}
		return array_values( array_filter( $ids ) );
	}

	// ---------- 注册邮箱验证 ----------

	/** “注册邮箱验证”邮件开着时才能要求验证，否则顾客收不到链接、永远登录不了。 */
	public static function verification_available(): bool {
		$o = get_option( 'woocommerce_pc_register_verify_settings', array() );
		return 'yes' === ( is_array( $o ) && isset( $o['enabled'] ) ? $o['enabled'] : 'yes' );
	}

	/**
	 * “我的账户”注册后：
	 * - 开启注册邮箱验证（且验证邮件开着）：先不登录，发验证邮件；
	 * - 开启强制注册 / 登录：新账户等待管理员批准，批准前不能登录。
	 * 结账时顺带创建的账户不受影响。
	 *
	 * @param bool $auth
	 * @param int  $customer_id
	 */
	public static function hold_new_customer( $auth, $customer_id ) {
		if ( ! isset( $_POST['register'] ) || ! $customer_id ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return $auth;
		}
		$verify  = self::get( 'register_verify' ) && self::verification_available();
		$approve = (bool) self::get( 'force_login' );
		if ( ! $verify && ! $approve ) {
			return $auth;
		}
		self::keep_notices();
		if ( $approve ) {
			update_user_meta( (int) $customer_id, self::APPROVAL_META, time() );
		}
		if ( $verify ) {
			self::send_verification( (int) $customer_id );
			wc_add_notice( __( 'Almost done: we sent a verification link to your email. Open it to activate your account, then sign in.', 'peptide-core' ), 'success' );
		}
		if ( $approve ) {
			wc_add_notice( __( 'Thank you for registering. Your account can be used after the store approves it.', 'peptide-core' ), 'notice' );
		}
		return false;
	}

	public static function is_pending_approval( int $user_id ): bool {
		return '' !== (string) get_user_meta( $user_id, self::APPROVAL_META, true );
	}

	public static function approve( int $user_id ): void {
		delete_user_meta( $user_id, self::APPROVAL_META );
	}

	/** 生成验证链接（3 天有效）并交给“注册邮箱验证”邮件发送，返回链接。 */
	public static function send_verification( int $user_id ): string {
		$token = wp_generate_password( 32, false );
		update_user_meta( $user_id, self::VERIFY_META, wp_hash_password( $token ) . '|' . ( time() + 3 * DAY_IN_SECONDS ) );
		$url = add_query_arg( 'pc_verify', $user_id . '.' . $token, wc_get_page_permalink( 'myaccount' ) );
		do_action( 'peptide_core_send_registration_verification', $user_id, $url );
		return $url;
	}

	/** 校验链接里的 “用户ID.令牌”，通过时清除未验证标记。 */
	public static function verify_token( string $value ): bool {
		[ $uid, $token ] = array_pad( explode( '.', $value, 2 ), 2, '' );
		$stored          = (string) get_user_meta( (int) $uid, self::VERIFY_META, true );
		[ $hash, $exp ]  = array_pad( explode( '|', $stored, 2 ), 2, '0' );
		if ( '' === $stored || '' === $token || (int) $exp < time() || ! wp_check_password( $token, $hash ) ) {
			return false;
		}
		delete_user_meta( (int) $uid, self::VERIFY_META );
		return true;
	}

	public static function is_unverified( int $user_id ): bool {
		return '' !== (string) get_user_meta( $user_id, self::VERIFY_META, true );
	}

	/** 游客还没有会话 Cookie 时，跳转前的提示会丢失，先建立会话。 */
	private static function keep_notices(): void {
		if ( function_exists( 'WC' ) && WC()->session && ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}
	}

	/** 通过邮件里的设置密码 / 重置密码链接设好密码，同样证明了邮箱属于本人。 */
	public static function verified_by_reset( $user ): void {
		if ( $user instanceof \WP_User ) {
			delete_user_meta( $user->ID, self::VERIFY_META );
		}
	}

	/** @param \WP_User|\WP_Error $user */
	public static function block_unverified( $user ) {
		if ( ! $user instanceof \WP_User || user_can( $user, 'edit_posts' ) ) {
			return $user;
		}
		if ( self::is_unverified( $user->ID ) ) {
			return new \WP_Error( 'pc_unverified', __( 'Please verify your email first. We sent you a link when you registered; check your inbox and spam folder.', 'peptide-core' ) );
		}
		if ( self::is_pending_approval( $user->ID ) ) {
			return new \WP_Error( 'pc_pending', __( 'Your account is waiting for approval by the store. You can sign in once it is approved.', 'peptide-core' ) );
		}
		return $user;
	}

	// ---------- 后台：批准新账户（用户列表） ----------

	/** @param array $actions */
	public static function user_row_actions( $actions, $user ) {
		if ( $user instanceof \WP_User && self::is_pending_approval( $user->ID ) && current_user_can( 'edit_user', $user->ID ) ) {
			$actions['pc_approve'] = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pc_approve_user&user=' . $user->ID ), 'pc_approve_user_' . $user->ID ) ) . '">' . esc_html__( 'Approve', 'peptide-core' ) . '</a>';
		}
		return $actions;
	}

	public static function handle_approve(): void {
		$uid = absint( $_GET['user'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! current_user_can( 'edit_user', $uid ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'peptide-core' ), 403 );
		}
		check_admin_referer( 'pc_approve_user_' . $uid );
		self::approve( $uid );
		wp_safe_redirect( add_query_arg( 'pc_approved', 1, admin_url( 'users.php' ) ) );
		exit;
	}

	/** @param array $actions */
	public static function bulk_actions( $actions ): array {
		$actions['pc_approve'] = __( 'Approve accounts', 'peptide-core' );
		return (array) $actions;
	}

	public static function handle_bulk( $redirect, $action, $ids ) {
		if ( 'pc_approve' !== $action ) {
			return $redirect;
		}
		$n = 0;
		foreach ( (array) $ids as $id ) {
			if ( current_user_can( 'edit_user', (int) $id ) && self::is_pending_approval( (int) $id ) ) {
				self::approve( (int) $id );
				++$n;
			}
		}
		return add_query_arg( 'pc_approved', $n, $redirect );
	}

	/** @param array $cols */
	public static function users_column( $cols ): array {
		$cols = (array) $cols;
		if ( self::get( 'force_login' ) || self::pending_count() ) {
			$cols['pc_status'] = __( 'Account status', 'peptide-core' );
		}
		return $cols;
	}

	public static function users_column_value( $value, $column, $user_id ) {
		if ( 'pc_status' !== $column ) {
			return $value;
		}
		if ( self::is_pending_approval( (int) $user_id ) ) {
			return '<span style="color:#b45309">' . esc_html__( 'Waiting for approval', 'peptide-core' ) . '</span>';
		}
		return self::is_unverified( (int) $user_id ) ? esc_html__( 'Email not verified', 'peptide-core' ) : esc_html__( 'Active', 'peptide-core' );
	}

	public static function pending_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s", self::APPROVAL_META ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public static function approval_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'users' !== $screen->id || ! current_user_can( 'list_users' ) ) {
			return;
		}
		if ( isset( $_GET['pc_approved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			/* translators: %d: number of accounts */
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( _n( '%d account approved.', '%d accounts approved.', absint( $_GET['pc_approved'] ), 'peptide-core' ), absint( $_GET['pc_approved'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$n = self::pending_count();
		if ( $n ) {
			/* translators: %d: number of accounts */
			echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( _n( '%d new account is waiting for approval. Use “Approve” under the name, or the bulk action.', '%d new accounts are waiting for approval. Use “Approve” under the name, or the bulk action.', $n, 'peptide-core' ), $n ) ) . '</p></div>';
		}
	}

	public static function handle_verify_link(): void {
		if ( ! isset( $_GET['pc_verify'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		self::keep_notices();
		if ( self::verify_token( sanitize_text_field( wp_unslash( $_GET['pc_verify'] ) ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wc_add_notice( __( 'Your email is verified. You can sign in now.', 'peptide-core' ), 'success' );
		} else {
			wc_add_notice( __( 'This verification link is invalid or has expired.', 'peptide-core' ), 'error' );
		}
		wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
		exit;
	}

	// ---------- 前台排序 ----------

	public static function sort_labels(): array {
		return array(
			'menu_order' => __( 'Recommended', 'peptide-core' ),
			'popularity' => __( 'Best selling', 'peptide-core' ),
			'title'      => __( 'Name: A to Z', 'peptide-core' ),
			'title-desc' => __( 'Name: Z to A', 'peptide-core' ),
			'price'      => __( 'Price: low to high', 'peptide-core' ),
			'price-desc' => __( 'Price: high to low', 'peptide-core' ),
			'date-asc'   => __( 'Oldest first', 'peptide-core' ),
			'date'       => __( 'Newest first', 'peptide-core' ),
		);
	}

	/** @param array $options */
	public static function catalog_orderby( $options ): array {
		$labels = self::sort_labels();
		$out    = array();
		foreach ( (array) self::get( 'sorts' ) as $key ) {
			if ( isset( $labels[ $key ] ) ) {
				$out[ $key ] = $options[ $key ] ?? $labels[ $key ];
			}
		}
		return $out ? $out : (array) $options;
	}

	public static function default_orderby( $default ): string {
		$sorts = array_values( array_intersect( (array) self::get( 'sorts' ), self::SORTS ) );
		return $sorts ? $sorts[0] : (string) $default;
	}

	// ---------- iframe ----------

	public static function frame_header(): void {
		if ( ! is_admin() && ! self::get( 'allow_iframe' ) && ! headers_sent() ) {
			header( 'X-Frame-Options: SAMEORIGIN' );
		}
	}

	// ---------- 已下架商品 ----------

	/** 已下架（草稿）的商品详情页可以打开：可见但不可购买、可见且可购买。商品列表里仍然看不到。 */
	public static function show_offline_products( $query ): void {
		if ( is_admin() || ! $query instanceof \WP_Query || ! $query->is_main_query() || ! in_array( self::get( 'offline_product' ), array( 'visible', 'visible_buy' ), true ) ) {
			return;
		}
		if ( '' !== (string) $query->get( 'product' ) || ( 'product' === $query->get( 'post_type' ) && '' !== (string) $query->get( 'name' ) ) ) {
			$query->set( 'post_status', array( 'publish', 'draft' ) );
		}
	}

	private static function is_offline( \WC_Product $product ): bool {
		return 'draft' === $product->get_status();
	}

	/** “可见且可购买”时，已下架商品可以加入购物车和下单（有价格、库存允许时）。 */
	public static function offline_purchasable( $purchasable, $product ) {
		if ( $purchasable || 'visible_buy' !== self::get( 'offline_product' ) || ! $product instanceof \WC_Product || ! self::is_offline( $product ) ) {
			return $purchasable;
		}
		return '' !== (string) $product->get_price();
	}

	public static function offline_variation_purchasable( $purchasable, $variation ) {
		if ( $purchasable || 'visible_buy' !== self::get( 'offline_product' ) || ! $variation instanceof \WC_Product_Variation ) {
			return $purchasable;
		}
		$parent = wc_get_product( $variation->get_parent_id() );
		return $parent && self::is_offline( $parent ) && $variation->variation_is_visible() && '' !== (string) $variation->get_price() && 'publish' === $variation->get_status();
	}

	/** 已下架商品的页面不让搜索引擎收录。 */
	public static function offline_noindex( $robots ) {
		if ( is_singular( 'product' ) && 'draft' === get_post_status() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	public static function offline_product(): void {
		$mode = (string) self::get( 'offline_product' );
		if ( ! in_array( $mode, array( 'home', 'shop' ), true ) || ! is_404() ) {
			return;
		}
		$slug = (string) get_query_var( 'product' );
		if ( '' === $slug ) {
			return;
		}
		$found = get_posts(
			array(
				'post_type'   => 'product',
				'name'        => $slug,
				'post_status' => array( 'draft', 'pending', 'private', 'trash' ),
				'numberposts' => 1,
				'fields'      => 'ids',
			)
		);
		if ( ! $found ) {
			return;
		}
		wp_safe_redirect( 'shop' === $mode ? wc_get_page_permalink( 'shop' ) : home_url( '/' ), 302 );
		exit;
	}

	// ---------- 款式 ----------

	/**
	 * 多款式商品没有设置默认款式时，前台默认选中：首个可售 / 价格最低 / 价格最高 / 库存最多的款式；“不选中”保持 WooCommerce 原样。
	 * 只考虑已发布、可购买、有货的款式；属性里有“任意”的款式无法作为默认值，跳过。
	 *
	 * @param array       $defaults
	 * @param \WC_Product $product
	 */
	public static function default_attributes( $defaults, $product ) {
		$mode = (string) self::get( 'default_variant' );
		if ( is_admin() || ! empty( $defaults ) || 'none' === $mode || ! in_array( $mode, self::DEFAULT_VARIANTS, true ) || ! $product instanceof \WC_Product_Variable ) {
			return $defaults;
		}
		$best = null;
		$key  = null;
		foreach ( $product->get_children() as $id ) {
			$v = wc_get_product( $id );
			if ( ! $v || ! $v->is_purchasable() || ! $v->is_in_stock() || 'publish' !== $v->get_status() ) {
				continue;
			}
			$attrs = $v->get_variation_attributes( false );
			if ( in_array( '', $attrs, true ) ) {
				continue;
			}
			switch ( $mode ) {
				case 'lowest':
					$score = -(float) $v->get_price();
					break;
				case 'highest':
					$score = (float) $v->get_price();
					break;
				case 'stock':
					$score = $v->managing_stock() ? (float) $v->get_stock_quantity() : -1.0;
					break;
				default:
					return $attrs;
			}
			if ( null === $key || $score > $key ) {
				$key  = $score;
				$best = $attrs;
			}
		}
		return null === $best ? $defaults : $best;
	}

	/**
	 * @param bool $visible
	 */
	public static function variation_visible( $visible, $variation_id = 0, $parent_id = 0, $variation = null ) {
		if ( ! $visible || is_admin() || 'hide' !== self::get( 'soldout_variants' ) ) {
			return $visible;
		}
		$v = $variation instanceof \WC_Product ? $variation : wc_get_product( $variation_id );
		return $v ? $v->is_in_stock() : $visible;
	}

	// ---------- 商品链接结构 ----------

	public static function product_link_bases(): array {
		$shop = get_post( wc_get_page_id( 'shop' ) );
		$base = $shop ? $shop->post_name : 'shop';
		return array(
			'product'  => '/product',
			'shop'     => '/' . $base,
			'shop_cat' => '/' . $base . '/%product_cat%',
		);
	}

	public static function apply_product_link( string $mode ): void {
		$bases = self::product_link_bases();
		if ( ! isset( $bases[ $mode ] ) ) {
			return;
		}
		$p = (array) get_option( 'woocommerce_permalinks', array() );
		if ( trim( (string) ( $p['product_base'] ?? '' ), '/' ) === trim( $bases[ $mode ], '/' ) ) {
			return;
		}
		$p['product_base'] = $bases[ $mode ];
		update_option( 'woocommerce_permalinks', $p );
		update_option( 'peptide_core_flush_rewrites', 1, false );
	}

	public static function current_product_link(): string {
		$p    = (array) get_option( 'woocommerce_permalinks', array() );
		$base = trim( (string) ( $p['product_base'] ?? '' ), '/' );
		foreach ( self::product_link_bases() as $mode => $b ) {
			if ( trim( $b, '/' ) === $base ) {
				return $mode;
			}
		}
		return '' === $base ? 'product' : 'custom';
	}

	// ---------- 公开联系方式：JSON-LD ----------

	/** 首页输出 Organization 结构化数据（填了公司名称或地址时）。可以用 peptide_core_json_ld 过滤器关闭或修改。 */
	public static function json_ld(): void {
		if ( ! is_front_page() ) {
			return;
		}
		$data = self::json_ld_data();
		if ( $data ) {
			echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n";
		}
	}

	/** Organization 数据；没填公司名称和地址时为空数组。 */
	public static function json_ld_data(): array {
		$c = self::contact();
		if ( '' === $c['company'] && '' === $c['address'] ) {
			return array();
		}
		$data = array(
			'@context' => 'https://schema.org',
			'@type'    => 'Organization',
			'name'     => '' !== $c['company'] ? $c['company'] : wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'url'      => home_url( '/' ),
		);
		$logo = get_site_icon_url( 512 );
		if ( $logo ) {
			$data['logo'] = $logo;
		}
		if ( '' !== $c['phone'] ) {
			$data['telephone'] = $c['phone'];
		}
		if ( '' !== $c['address'] ) {
			$data['address'] = array_filter(
				array(
					'@type'           => 'PostalAddress',
					'streetAddress'   => $c['address'],
					'addressLocality' => $c['city'],
					'addressRegion'   => $c['state'],
					'postalCode'      => $c['postcode'],
					'addressCountry'  => $c['country'],
				)
			);
		}
		return (array) apply_filters( 'peptide_core_json_ld', $data );
	}

	// ---------- 商品编辑页：手动 SPU ----------

	/** @param array $meta */
	public static function duplicate_exclude_meta( $meta ): array {
		$meta   = (array) $meta;
		$meta[] = '_pc_spu';
		return $meta;
	}

	public static function spu_field(): void {
		global $product_object;
		if ( ! function_exists( 'woocommerce_wp_text_input' ) ) {
			return;
		}
		woocommerce_wp_text_input(
			array(
				'id'          => '_pc_spu',
				'value'       => $product_object instanceof \WC_Product ? (string) $product_object->get_meta( '_pc_spu' ) : '',
				'label'       => __( 'SPU', 'peptide-core' ),
				'desc_tip'    => true,
				'description' => __( 'Unique code of this product in the store. Leave empty to generate it automatically (System settings → Basic settings).', 'peptide-core' ),
			)
		);
	}

	/** 手动填写的 SPU：只允许字母、数字、- 和 _，不能和其他商品重复。 */
	public static function save_spu_field( $product ): void {
		if ( ! $product instanceof \WC_Product || ! isset( $_POST['_pc_spu'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce 保存商品时已校验。
			return;
		}
		$spu = strtoupper( preg_replace( '/[^A-Za-z0-9_-]/', '', sanitize_text_field( wp_unslash( $_POST['_pc_spu'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' === $spu ) {
			return;
		}
		$taken = get_posts( array( 'post_type' => 'product', 'post_status' => 'any', 'meta_key' => '_pc_spu', 'meta_value' => $spu, 'exclude' => array( $product->get_id() ), 'fields' => 'ids', 'numberposts' => 1 ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( $taken ) {
			if ( class_exists( 'WC_Admin_Meta_Boxes' ) ) {
				\WC_Admin_Meta_Boxes::add_error( __( 'This SPU is already used by another product; it was not changed.', 'peptide-core' ) );
			}
			return;
		}
		$product->update_meta_data( '_pc_spu', substr( $spu, 0, 40 ) );
	}

	public static function maybe_flush_rewrites(): void {
		if ( get_option( 'peptide_core_flush_rewrites' ) ) {
			delete_option( 'peptide_core_flush_rewrites' );
			flush_rewrite_rules( false );
		}
	}
}
