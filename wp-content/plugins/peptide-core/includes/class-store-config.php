<?php
/**
 * 系统设置 → 基础配置（BASIC-CONFIG-01），对照 Shopname 的“基础配置”页。
 *
 * 能对应到 WordPress / WooCommerce 原有设置的，直接读写原设置（店铺名称、客服邮箱、Favicon、货币、时区、
 * 货币格式、语言、店铺地址、打烊）；其余选项存在 peptide_core_store_config，由这里的钩子在运行时生效：
 *
 * - SPU / SKU 自动编码：商品保存时 SKU 为空才生成，不改已有 SKU。
 * - 回访用户计算时间：仪表盘“回访用户”按这个间隔判断。
 * - 禁止爬虫访问：按 User-Agent 关键词拦截前台请求（403），后台、命令行和定时任务不受影响。
 * - 后台登录验证码：wp-login.php 登录表单加一道算术题。
 * - 前台搜索字段：商品搜索同时匹配 SKU / 分类 / 标签。
 * - 强制注册 / 登录、注册邮箱验证、前台排序字段、iframe、已下架商品、默认款式、售罄款式。
 *
 * Shopname 平台专有、在自建站上没有对应功能的选项（临时域名、静态资源路径、代码模式、缓存时间、
 * 像素延迟、多币种结算等）不做。
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

	private static bool $saving = false;

	public static function defaults(): array {
		return array(
			'spu_mode'         => 'random',
			'sku_auto'         => true,
			'sku_pattern'      => '{SPU}-{SKU_CODE}',
			'returning_hours'  => 24,
			'bot_system'       => true,
			'bot_custom'       => array(),
			'admin_captcha'    => false,
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

	// ---------- 后台登录验证码 ----------

	private static function captcha_sign( int $answer, int $expires ): string {
		return hash_hmac( 'sha256', $answer . '|' . $expires, wp_salt( 'nonce' ) );
	}

	public static function captcha_field(): void {
		if ( ! self::get( 'admin_captcha' ) ) {
			return;
		}
		$a       = wp_rand( 1, 9 );
		$b       = wp_rand( 1, 9 );
		$expires = time() + 15 * MINUTE_IN_SECONDS;
		/* translators: 1, 2: numbers to add */
		echo '<p><label for="pc-captcha">' . esc_html( sprintf( __( 'Security check: what is %1$d + %2$d?', 'peptide-core' ), $a, $b ) ) . '</label>';
		echo '<input type="text" name="' . esc_attr( self::CAPTCHA_FIELD ) . '" id="pc-captcha" class="input" inputmode="numeric" autocomplete="off" size="4" required></p>';
		echo '<input type="hidden" name="pc_captcha_token" value="' . esc_attr( $expires . '.' . self::captcha_sign( $a + $b, $expires ) ) . '">';
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
		$answer = isset( $_POST[ self::CAPTCHA_FIELD ] ) ? (int) wp_unslash( $_POST[ self::CAPTCHA_FIELD ] ) : -1; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$token  = isset( $_POST['pc_captcha_token'] ) ? sanitize_text_field( wp_unslash( $_POST['pc_captcha_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		[ $expires, $sig ] = array_pad( explode( '.', $token, 2 ), 2, '' );
		if ( (int) $expires < time() || ! hash_equals( self::captcha_sign( $answer, (int) $expires ), $sig ) ) {
			return new \WP_Error( 'pc_captcha', __( '<strong>Error:</strong> The security check answer is wrong. Please try again.', 'peptide-core' ) );
		}
		return $user;
	}

	// ---------- 前台搜索字段 ----------

	/**
	 * 前台商品搜索：标题之外，按设置同时匹配 SKU、分类、标签。
	 *
	 * @param string    $search
	 * @param \WP_Query $query
	 */
	public static function product_search( $search, $query ) {
		global $wpdb;
		if ( is_admin() || ! $query instanceof \WP_Query || '' === (string) $query->get( 's' ) ) {
			return $search;
		}
		$types = (array) $query->get( 'post_type' );
		if ( ! in_array( 'product', $types, true ) ) {
			return $search;
		}
		$fields = array_intersect( (array) self::get( 'search_fields' ), array( 'sku', 'category', 'tag' ) );
		if ( ! $fields || '' === trim( (string) $search ) ) {
			return $search;
		}
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
		// 只把额外条件用 OR 并到关键词条件上，密码条件原样保留在外面。
		$search = (string) $search;
		$pw     = '';
		if ( preg_match( "/\s+AND\s+\(\S+\.post_password = ''\)\s*$/", $search, $m ) ) {
			$pw     = $m[0];
			$search = substr( $search, 0, -strlen( $pw ) );
		}
		$terms = preg_replace( '/^\s*AND\s*/', '', $search, 1 );
		return ' AND (' . $terms . ' OR (' . implode( ') OR (', $extra ) . '))' . $pw;
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

	/**
	 * 开启注册邮箱验证时，“我的账户”注册后先不登录，发验证邮件；结账时顺带创建的账户不受影响。
	 *
	 * @param bool $auth
	 * @param int  $customer_id
	 */
	public static function hold_new_customer( $auth, $customer_id ) {
		if ( ! self::get( 'register_verify' ) || ! isset( $_POST['register'] ) || ! $customer_id ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return $auth;
		}
		self::send_verification( (int) $customer_id );
		self::keep_notices();
		wc_add_notice( __( 'Almost done: we sent a verification link to your email. Open it to activate your account, then sign in.', 'peptide-core' ), 'success' );
		return false;
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
		if ( $user instanceof \WP_User && self::is_unverified( $user->ID ) && ! user_can( $user, 'edit_posts' ) ) {
			return new \WP_Error( 'pc_unverified', __( 'Please verify your email first. We sent you a link when you registered; check your inbox and spam folder.', 'peptide-core' ) );
		}
		return $user;
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

	public static function offline_product(): void {
		$mode = (string) self::get( 'offline_product' );
		if ( '404' === $mode || ! is_404() ) {
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
	 * 多款式商品没有设置默认款式时，前台默认选中第一个有货的款式。
	 *
	 * @param array       $defaults
	 * @param \WC_Product $product
	 */
	public static function default_attributes( $defaults, $product ) {
		if ( is_admin() || ! empty( $defaults ) || 'first' !== self::get( 'default_variant' ) || ! $product instanceof \WC_Product_Variable ) {
			return $defaults;
		}
		foreach ( $product->get_children() as $id ) {
			$v = wc_get_product( $id );
			if ( $v && $v->is_purchasable() && $v->is_in_stock() && 'publish' === $v->get_status() ) {
				$attrs = array();
				foreach ( $v->get_variation_attributes( false ) as $name => $value ) {
					if ( '' === $value ) {
						return $defaults;
					}
					$attrs[ $name ] = $value;
				}
				return $attrs;
			}
		}
		return $defaults;
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

	public static function maybe_flush_rewrites(): void {
		if ( get_option( 'peptide_core_flush_rewrites' ) ) {
			delete_option( 'peptide_core_flush_rewrites' );
			flush_rewrite_rules( false );
		}
	}
}
