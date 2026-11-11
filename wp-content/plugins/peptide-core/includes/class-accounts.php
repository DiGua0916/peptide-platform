<?php
/**
 * 顾客注册系统（店主 2026-11-11）。
 *
 * 1. 进站先确认 18+（年龄确认 + 使用条款 + 隐私政策，条款内容直接在弹窗里展开看）；同意后弹注册（邮箱 / 密码 / 确认密码），
 *    顾客可以“Skip for now”不注册，也可以切换成登录。
 * 2. 没注册就下单的顾客，付款成功时自动建账户：账号 = 邮箱，密码随机（店主 2026-11-11 改：原来密码 = 邮箱，知道邮箱就能登录），
 *    WooCommerce 发“账户已创建”邮件，顾客点里面的链接自己设密码；付款成功弹窗里告诉他去邮箱看。
 *    这个邮箱已经有账户的，订单直接归到那个账户。只处理功能上线之后付款的新订单，已有订单不动。
 * 3. 顾客登录后在“我的账户”首页看到自己的订单和物流（承运商、单号、追踪链接，来自后台订单里添加的发货记录）。
 *
 * 弹窗都在浏览器里判断显示与否（localStorage，不写 cookie），首页静态缓存照常生效。
 * 后台“店铺设置 → 注册与年龄确认”里可以分别关掉。顾客看到的文字都是英文。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Accounts {

	public const OPTION = 'pc_accounts';
	public const META   = '_pc_auto_account';

	public static function register_hooks(): void {
		// 付款完成（Panapay 的完成保护在 PHP_INT_MIN）之后、注册邀请邮件（40）之前建账户，这样不会再发“邀请注册”。
		add_action( 'woocommerce_payment_complete', array( self::class, 'ensure_for_order' ), 5 );
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'assets' ), 160 );
		add_action( 'wp_footer', array( self::class, 'render' ), 45 );
		add_action( 'woocommerce_login_form_end', array( self::class, 'login_form_signup' ) );
		add_action( 'woocommerce_account_dashboard', array( self::class, 'dashboard_orders' ), 5 );
		add_filter( 'woocommerce_my_account_my_orders_actions', array( self::class, 'order_actions' ), 10, 2 );
		// 审查 2026-11-11：改账户邮箱必须输入当前密码（初始密码就是邮箱，防止别人登录后改邮箱把账户占走）。
		add_action( 'woocommerce_save_account_details_errors', array( self::class, 'guard_email_change' ), 10, 2 );
	}

	public static function defaults(): array {
		return array(
			'gate'    => true,
			'min_age' => 18,
			'signup'  => true,
			'auto'    => true,
		);
	}

	public static function settings(): array {
		$s = get_option( self::OPTION, array() );
		$s = is_array( $s ) ? $s : array();
		$d = self::defaults();
		return array(
			'gate'    => array_key_exists( 'gate', $s ) ? ! empty( $s['gate'] ) : $d['gate'],
			'min_age' => isset( $s['min_age'] ) ? max( 13, min( 25, (int) $s['min_age'] ) ) : $d['min_age'],
			'signup'  => array_key_exists( 'signup', $s ) ? ! empty( $s['signup'] ) : $d['signup'],
			'auto'    => array_key_exists( 'auto', $s ) ? ! empty( $s['auto'] ) : $d['auto'],
		);
	}

	public static function save( array $in ): void {
		update_option(
			self::OPTION,
			array(
				'gate'    => ! empty( $in['gate'] ),
				'min_age' => max( 13, min( 25, (int) ( $in['min_age'] ?? 18 ) ) ),
				'signup'  => ! empty( $in['signup'] ),
				'auto'    => ! empty( $in['auto'] ),
			),
			false
		);
	}

	/* ---------------------------------------------------------------- 账户 */

	/** 新建顾客账户；地址等资料从订单带过来。 */
	private static function create_user( string $email, string $password, ?\WC_Order $order = null ) {
		$login = sanitize_user( $email, true );
		if ( '' === $login || strlen( $login ) > 60 || username_exists( $login ) ) {
			$login = function_exists( 'wc_create_new_customer_username' ) ? wc_create_new_customer_username( $email ) : sanitize_user( strstr( $email, '@', true ) . wp_rand( 100, 999 ), true );
		}
		$data = array(
			'user_login' => $login,
			'user_email' => $email,
			'user_pass'  => $password,
			'role'       => 'customer',
		);
		if ( $order ) {
			$data['first_name']   = $order->get_billing_first_name();
			$data['last_name']    = $order->get_billing_last_name();
			$data['display_name'] = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ?: $login;
		}
		$uid = wp_insert_user( $data );
		if ( is_wp_error( $uid ) ) {
			return $uid;
		}
		update_user_meta( $uid, '_pc_account_source', $order ? 'auto_checkout' : 'signup' );
		if ( $order && class_exists( '\WC_Customer' ) ) {
			try {
				$c = new \WC_Customer( $uid );
				foreach ( array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone', 'email' ) as $f ) {
					$get = 'get_billing_' . $f;
					$set = 'set_billing_' . $f;
					$c->$set( (string) $order->$get() );
				}
				foreach ( array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ) as $f ) {
					$get = 'get_shipping_' . $f;
					$set = 'set_shipping_' . $f;
					$c->$set( (string) $order->$get() );
				}
				$c->save();
			} catch ( \Throwable $e ) {
				// 地址复制失败不影响账户本身。
			}
		}
		return (int) $uid;
	}

	/**
	 * 付款后：游客订单自动建账户（账号、密码都是邮箱），已有账户的邮箱直接把订单归过去。
	 *
	 * @param int|\WC_Order $order_id
	 */
	public static function ensure_for_order( $order_id ): void {
		if ( ! self::settings()['auto'] ) {
			return;
		}
		$order = $order_id instanceof \WC_Order ? $order_id : wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || 'shop_order' !== $order->get_type() || $order->get_customer_id() || '' !== (string) $order->get_meta( self::META ) || ! $order->is_paid() ) {
			return;
		}
		if ( class_exists( Test_Orders::class ) && Test_Orders::is_test_order( $order ) ) {
			return;
		}
		// 只处理功能上线之后创建的订单（店主：已经产生的订单不要改）。
		$since = (int) get_option( 'pc_accounts_since', 0 );
		if ( ! $since ) {
			update_option( 'pc_accounts_since', time(), false );
			return;
		}
		$created = $order->get_date_created();
		if ( ! $created || $created->getTimestamp() < $since ) {
			return;
		}
		$email = strtolower( trim( (string) $order->get_billing_email() ) );
		if ( ! is_email( $email ) ) {
			return;
		}
		$user = get_user_by( 'email', $email );
		if ( $user && ! self::trusted_owner( $user ) ) {
			// 审查 2026-11-11：自己注册的账户没验证过邮箱，别人可能拿顾客的邮箱先注册。不自动归单，免得把别人的订单和地址给他看。
			$order->update_meta_data( self::META, 'unverified' );
			$order->add_order_note( 'An account with this email exists but was not verified; the order was not added to it automatically.' );
			$order->save();
			return;
		}
		if ( $user ) {
			$order->set_customer_id( (int) $user->ID );
			$order->update_meta_data( self::META, 'linked' );
			$order->add_order_note( 'Order added to the existing customer account for ' . $email . '.' );
			$order->save();
			return;
		}
		$uid = self::create_user( $email, wp_generate_password( 24, true, true ), $order );
		if ( is_wp_error( $uid ) ) {
			$again = get_user_by( 'email', $email ); // 付款回调和付款成功页同时到达：另一个已经建好了。
			if ( $again && 'auto_checkout' === get_user_meta( $again->ID, '_pc_account_source', true ) ) {
				$order->set_customer_id( (int) $again->ID );
				$order->update_meta_data( self::META, 'created' );
				$order->save();
				return;
			}
			$order->update_meta_data( self::META, 'failed' );
			$order->add_order_note( 'Automatic customer account could not be created: ' . $uid->get_error_message() );
			$order->save();
			return;
		}
		$order->set_customer_id( $uid );
		$order->update_meta_data( self::META, 'created' );
		$order->add_order_note( 'Customer account created automatically for ' . $email . '. A “set your password” email was sent to the customer.' );
		$order->save();
		self::send_set_password( $uid );
	}

	/**
	 * 可以把游客订单自动归过去的账户：付款时自动建的（证明过这个邮箱能下单付款），
	 * 或者已经有过付款订单的老顾客、店铺员工。弹窗里自己注册、从没下过单的不算。
	 */
	private static function trusted_owner( \WP_User $user ): bool {
		if ( 'signup' !== get_user_meta( $user->ID, '_pc_account_source', true ) ) {
			return true;
		}
		$paid = wc_get_orders( array( 'customer_id' => $user->ID, 'status' => wc_get_is_paid_statuses(), 'limit' => 1, 'return' => 'ids' ) );
		return ! empty( $paid );
	}

	/** 改账户邮箱必须填当前密码。 */
	public static function guard_email_change( $errors, $user ): void {
		if ( ! is_object( $user ) || empty( $user->ID ) || ! isset( $user->user_email ) ) {
			return;
		}
		$old = get_userdata( (int) $user->ID );
		if ( ! $old || strtolower( (string) $old->user_email ) === strtolower( (string) $user->user_email ) ) {
			return;
		}
		$current = isset( $_POST['password_current'] ) ? (string) wp_unslash( $_POST['password_current'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- WooCommerce 已校验表单 nonce；密码原样比对。
		if ( '' === $current || ! wp_check_password( $current, $old->user_pass, $old->ID ) ) {
			$errors->add( 'pc_email_password', 'Please enter your current password to change your email address.' );
		}
	}

	/** WooCommerce 自带的“账户已创建”邮件，带“设置密码”链接（不触发注册广告事件：买家不算新注册转化）。 */
	private static function send_set_password( int $uid ): void {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}
		$emails = WC()->mailer()->get_emails();
		if ( isset( $emails['WC_Email_Customer_New_Account'] ) ) {
			try {
				$emails['WC_Email_Customer_New_Account']->trigger( $uid, '', true );
			} catch ( \Throwable $e ) {
				// 邮件失败不影响账户和订单；顾客也可以在登录页点 Lost your password 自己设。
			}
		}
	}

	/** 付款成功弹窗里显示的账户信息（Paid_Popup 调用）。 */
	public static function popup_block( \WC_Order $order ): string {
		if ( ! self::settings()['auto'] ) {
			return '';
		}
		self::ensure_for_order( $order );
		$state = (string) $order->get_meta( self::META );
		if ( ! in_array( $state, array( 'created', 'linked' ), true ) ) {
			return '';
		}
		$email = (string) $order->get_billing_email();
		$url   = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : wp_login_url();
		$out   = '<div class="pc-paid__acct">';
		if ( 'created' === $state ) {
			$out .= '<p class="pc-paid__acct-title">We created an account for you</p>';
			$out .= '<dl><dt>Account</dt><dd>' . esc_html( $email ) . '</dd></dl>';
			$out .= '<p class="pc-paid__acct-sub">We sent a link to this email to set your password. Check your inbox (and spam folder).</p>';
		} else {
			$out .= '<p class="pc-paid__acct-title">This order is saved to your account</p>';
			$out .= '<p class="pc-paid__acct-sub">Sign in with ' . esc_html( $email ) . ' to see all your orders.</p>';
		}
		// “Track my order”直接打开右下角物流球（这台设备已记住这张订单，不用登录）；物流球没开时去账户页。
		$out .= '<a class="pc-paid__acct-btn" href="' . esc_url( $url ) . '" data-pc-open-track>Track my order</a></div>';
		return $out;
	}

	/* ---------------------------------------------------------------- 接口 */

	public static function routes(): void {
		foreach ( array( 'register', 'login' ) as $r ) {
			register_rest_route(
				'peptide/v1',
				'/account/' . $r,
				array(
					'methods'             => 'POST',
					'permission_callback' => '__return_true', // 公开的注册 / 登录，按 IP 限频。
					'callback'            => array( self::class, 'rest_' . $r ),
				)
			);
		}
	}

	/** 审查 2026-11-11：只接受本站页面发来的登录 / 注册（防止别的网站偷偷把访客登录到别人的账户）。 */
	private static function same_origin( \WP_REST_Request $r ): bool {
		$src = (string) ( $r->get_header( 'origin' ) ?: $r->get_header( 'referer' ) );
		if ( '' === $src ) {
			return false;
		}
		$host = strtolower( (string) wp_parse_url( $src, PHP_URL_HOST ) );
		$mine = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$bare = static fn( string $h ): string => preg_replace( '/^www\./', '', $h );
		return '' !== $host && $bare( $host ) === $bare( $mine );
	}

	private static function limited( string $what ): bool {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'pc_acct_' . $what . '_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= 10 ) {
			return true;
		}
		set_transient( $key, $n + 1, 10 * MINUTE_IN_SECONDS );
		return false;
	}

	public static function rest_register( \WP_REST_Request $r ) {
		if ( ! self::settings()['signup'] ) {
			return new \WP_Error( 'pc_acct_off', 'Registration is currently closed.', array( 'status' => 403 ) );
		}
		if ( ! self::same_origin( $r ) ) {
			return new \WP_Error( 'pc_acct_origin', 'Please register on our website.', array( 'status' => 403 ) );
		}
		if ( is_user_logged_in() ) {
			return rest_ensure_response( array( 'ok' => true ) );
		}
		if ( self::limited( 'reg' ) ) {
			return new \WP_Error( 'pc_acct_rate', 'Too many attempts. Please try again in a few minutes.', array( 'status' => 429 ) );
		}
		$email = strtolower( trim( sanitize_email( (string) $r['email'] ) ) );
		$pass  = (string) $r['password'];
		$pass2 = (string) $r['password2'];
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'pc_acct_email', 'Please enter a valid email address.', array( 'status' => 400 ) );
		}
		if ( strlen( $pass ) < 6 ) {
			return new \WP_Error( 'pc_acct_pass', 'Your password needs at least 6 characters.', array( 'status' => 400 ) );
		}
		if ( $pass !== $pass2 ) {
			return new \WP_Error( 'pc_acct_match', 'The two passwords do not match.', array( 'status' => 400 ) );
		}
		if ( email_exists( $email ) ) {
			return new \WP_Error( 'pc_acct_exists', 'An account with this email already exists. Please sign in.', array( 'status' => 409 ) );
		}
		$uid = self::create_user( $email, $pass );
		if ( is_wp_error( $uid ) ) {
			return new \WP_Error( 'pc_acct_fail', 'We could not create your account. Please try again.', array( 'status' => 500 ) );
		}
		// 和 WooCommerce 正常注册一样：广告的注册事件（Pixel / CAPI）、浏览提醒邮件、欢迎邮件都靠这个动作。
		do_action( 'woocommerce_created_customer', $uid, array( 'user_login' => $email, 'user_email' => $email ), false );
		// 店铺开了“注册验证邮箱 / 店主审核”时，照样先不登录（Store_Config::hold_new_customer）。
		if ( ! apply_filters( 'woocommerce_registration_auth_new_customer', true, $uid ) ) {
			return rest_ensure_response( array( 'ok' => true, 'held' => true, 'message' => 'Your account was created. Please check your email to verify it before signing in.' ) );
		}
		wp_set_current_user( $uid );
		wp_set_auth_cookie( $uid, true, is_ssl() );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function rest_login( \WP_REST_Request $r ) {
		if ( ! self::same_origin( $r ) ) {
			return new \WP_Error( 'pc_acct_origin', 'Please sign in on our website.', array( 'status' => 403 ) );
		}
		if ( is_user_logged_in() ) {
			return rest_ensure_response( array( 'ok' => true ) );
		}
		if ( self::limited( 'login' ) ) {
			return new \WP_Error( 'pc_acct_rate', 'Too many attempts. Please try again in a few minutes.', array( 'status' => 429 ) );
		}
		$email = trim( sanitize_text_field( (string) $r['email'] ) );
		$user  = wp_signon(
			array(
				'user_login'    => $email,
				'user_password' => (string) $r['password'],
				'remember'      => true,
			),
			is_ssl()
		);
		if ( is_wp_error( $user ) ) {
			// 待验证 / 待审核的账户把原因告诉顾客，其他一律“邮箱或密码不对”。
			$msg = in_array( $user->get_error_code(), array( 'pc_unverified', 'pc_pending' ), true ) ? $user->get_error_message() : 'The email or password is incorrect.';
			return new \WP_Error( 'pc_acct_login', $msg, array( 'status' => 403 ) );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/* ---------------------------------------------------------------- 前台弹窗 */

	private static function front_page_ok(): bool {
		if ( is_admin() || wp_doing_ajax() || is_user_logged_in() || is_customize_preview() ) {
			return false;
		}
		$s = self::settings();
		if ( ! $s['gate'] && ! $s['signup'] ) {
			return false;
		}
		// 付款成功回来的那一页只显示付款成功弹窗。
		return empty( $_GET['pc_paid'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	public static function assets(): void {
		if ( ! self::front_page_ok() ) {
			return;
		}
		$v = static fn( string $f ): string => PEPTIDE_CORE_VERSION . '.' . (int) @filemtime( PEPTIDE_CORE_DIR . '/assets/' . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_enqueue_style( 'pc-account-gate', plugins_url( 'assets/account-gate.css', PEPTIDE_CORE_FILE ), array(), $v( 'account-gate.css' ) );
		wp_enqueue_script( 'pc-account-gate', plugins_url( 'assets/account-gate.js', PEPTIDE_CORE_FILE ), array(), $v( 'account-gate.js' ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
		$s = self::settings();
		wp_add_inline_script(
			'pc-account-gate',
			'window.pcAccounts=' . wp_json_encode(
				array(
					'gate'     => $s['gate'],
					// 审查 2026-11-11：购物车、结账、付款页不弹注册（广告进来直接到结账的顾客不被打断）；年龄确认照常。
					'signup'   => $s['signup'] && ! ( function_exists( 'is_checkout' ) && ( is_checkout() || is_cart() ) ),
					'register' => rest_url( 'peptide/v1/account/register' ),
					'login'    => rest_url( 'peptide/v1/account/login' ),
					'account'  => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : '',
				)
			) . ';',
			'before'
		);
	}

	private static function contact_email(): string {
		$e = (string) get_option( 'woocommerce_email_from_address', '' );
		return is_email( $e ) ? $e : (string) get_option( 'admin_email' );
	}

	/** 条款和隐私政策正文（顾客看到的英文）。 */
	public static function policies(): array {
		$site  = wp_specialchars_decode( (string) wp_parse_url( home_url(), PHP_URL_HOST ), ENT_QUOTES );
		$site  = preg_replace( '/^www\./', '', $site );
		$age   = (int) self::settings()['min_age'];
		$email = self::contact_email();
		$terms = array(
			array( 'Eligibility', "You must be at least {$age} years old to use {$site} and to place an order. By entering the site you confirm that you meet this requirement and that you are legally able to purchase these products where you live." ),
			array( 'Research use only', 'All products are sold strictly for laboratory and research purposes. They are not drugs, food, dietary supplements or cosmetics, are not intended for human or animal use, and are not meant to diagnose, treat, cure or prevent any disease. Products must be handled by qualified persons only.' ),
			array( 'Information on this site', 'Product descriptions, reports and other content are provided for informational and research reference only and are not medical advice. We work to keep information accurate but do not guarantee that it is complete or current.' ),
			array( 'Orders and payment', 'Prices, promotions and availability may change without notice. We may refuse or cancel an order, for example when a product is unavailable, information is incorrect or the order appears to break these terms. Paid orders that we cancel are refunded.' ),
			array( 'Your account', 'You are responsible for keeping your login details safe and for activity on your account. Please change the initial password we give you to one only you know.' ),
			array( 'Liability', 'To the extent permitted by law, we are not liable for any misuse of products or for indirect or consequential losses. Your use of the products is at your own responsibility and must comply with the laws that apply to you.' ),
			array( 'Changes', 'We may update these terms from time to time. The version shown on the site when you place an order applies to that order.' ),
		);
		$privacy = array(
			array( 'What we collect', 'The details you give us (such as your name, email, phone number and shipping address), your order and payment status (card details are handled by our payment provider, not stored by us), and technical data such as device, browser, pages viewed and referring ads, collected with cookies and similar tools.' ),
			array( 'How we use it', 'To process and ship your orders, provide customer support, keep your account and order history, prevent fraud, improve the site, and, where allowed, send you order updates and offers. You can unsubscribe from marketing emails at any time.' ),
			array( 'Who we share it with', 'Only with service providers that help us run the store: payment processors, shipping carriers, email services, and analytics and advertising partners such as Meta. We do not sell your personal information.' ),
			array( 'Cookies and advertising', 'We use cookies and pixels to keep your cart, measure visits and show relevant ads. You can block or delete cookies in your browser settings; some features may then not work.' ),
			array( 'How long we keep it', 'We keep order and account information for as long as needed to provide our services and to meet legal, tax and accounting obligations.' ),
			array( 'Your choices', "You can ask to access, correct or delete your personal information by contacting us at {$email}." ),
			array( 'Security', 'We use reasonable technical and organisational measures to protect your information, but no online service is completely secure.' ),
		);
		return array( 'terms' => $terms, 'privacy' => $privacy, 'age' => $age, 'email' => $email );
	}

	private static function policy_html( array $sections ): string {
		$out = '';
		foreach ( $sections as $s ) {
			$out .= '<h4>' . esc_html( $s[0] ) . '</h4><p>' . esc_html( $s[1] ) . '</p>';
		}
		return $out;
	}

	public static function render(): void {
		if ( ! self::front_page_ok() ) {
			return;
		}
		$s   = self::settings();
		$p   = self::policies();
		$age = (int) $p['age'];
		?>
<?php if ( $s['gate'] ) : ?>
<dialog class="pc-acc pc-acc--gate" data-pc-gate aria-labelledby="pc-gate-title">
	<div class="pc-acc__box">
		<div class="pc-acc__badge" aria-hidden="true"><?php echo (int) $age; ?>+</div>
		<h2 id="pc-gate-title">Are you <?php echo (int) $age; ?> or older?</h2>
		<p class="pc-acc__lead">This website is for adults aged <?php echo (int) $age; ?> and over. All products are sold for laboratory research use only and are not intended for human or animal consumption.</p>
		<p class="pc-acc__small">By entering, you confirm you are at least <?php echo (int) $age; ?> years old and agree to our
			<button type="button" class="pc-acc__inline" data-pc-doc="terms" aria-expanded="false">Terms of Use</button> and
			<button type="button" class="pc-acc__inline" data-pc-doc="privacy" aria-expanded="false">Privacy Policy</button>, including the use of cookies.</p>
		<div class="pc-acc__doc" data-pc-doc-body="terms" hidden><h3>Terms of Use</h3><?php echo self::policy_html( $p['terms'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 逐段转义。 ?></div>
		<div class="pc-acc__doc" data-pc-doc-body="privacy" hidden><h3>Privacy Policy</h3><?php echo self::policy_html( $p['privacy'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 逐段转义。 ?></div>
		<div class="pc-acc__actions">
			<button type="button" class="pc-acc__btn pc-acc__btn--primary" data-pc-gate-yes>I'm <?php echo (int) $age; ?> or older, enter</button>
			<button type="button" class="pc-acc__btn pc-acc__btn--ghost" data-pc-gate-no>I'm under <?php echo (int) $age; ?>, leave</button>
		</div>
	</div>
</dialog>
<?php endif; ?>
<dialog class="pc-acc pc-acc--signup" data-pc-signup aria-labelledby="pc-signup-title">
	<div class="pc-acc__box">
		<button type="button" class="pc-acc__x" data-pc-signup-skip aria-label="Close">&times;</button>
		<h2 id="pc-signup-title" data-pc-mode-title>Create your account</h2>
		<p class="pc-acc__lead" data-pc-mode-lead>Track your orders and shipments in one place, and check out faster next time.</p>
		<form class="pc-acc__form" data-pc-signup-form novalidate>
			<label>Email<input type="email" name="email" autocomplete="email" inputmode="email" required></label>
			<label>Password<input type="password" name="password" autocomplete="new-password" minlength="6" required></label>
			<label data-pc-signup-only>Confirm password<input type="password" name="password2" autocomplete="new-password" minlength="6" required></label>
			<p class="pc-acc__err" data-pc-err role="alert" hidden></p>
			<button type="submit" class="pc-acc__btn pc-acc__btn--primary" data-pc-submit>Create account</button>
		</form>
		<button type="button" class="pc-acc__btn pc-acc__btn--ghost" data-pc-switch>I already have an account</button>
		<button type="button" class="pc-acc__btn pc-acc__btn--text" data-pc-signup-skip>Skip for now</button>
	</div>
</dialog>
		<?php
	}

	/* ---------------------------------------------------------------- 我的账户 */

	/** 账户页未登录时，登录表单下面给“没有账户？注册”按钮（打开注册弹窗）。 */
	public static function login_form_signup(): void {
		if ( ! self::settings()['signup'] ) {
			return;
		}
		echo '<p class="pc-acc-login-signup"><button type="button" class="button pc-acc-open-signup" data-pc-open-signup>New here? Create an account</button></p>';
	}

	/** 订单列表加“Track”按钮：已发货且有追踪链接的直接去承运商网站。 */
	public static function order_actions( array $actions, $order ): array {
		if ( ! $order instanceof \WC_Order || ! class_exists( Shipping_Tracking::class ) ) {
			return $actions;
		}
		$view = Shipping_Tracking::customer_view( $order );
		foreach ( (array) ( $view['shipments'] ?? array() ) as $sh ) {
			if ( ! empty( $sh['tracking_url'] ) ) {
				$actions = array( 'pc-track' => array( 'url' => $sh['tracking_url'], 'name' => 'Track package' ) ) + $actions;
				break;
			}
		}
		return $actions;
	}

	/** 物流状态的一句话（英文）。 */
	private static function ship_line( \WC_Order $order, ?array $view ): string {
		if ( $order->has_status( array( 'cancelled', 'refunded', 'failed' ) ) ) {
			return wc_get_order_status_name( $order->get_status() );
		}
		if ( ! $order->is_paid() ) {
			return 'Awaiting payment';
		}
		if ( ! empty( $view['shipments'] ) ) {
			return 'Shipped';
		}
		return $order->has_status( 'completed' ) ? 'Completed' : 'Preparing your shipment';
	}

	/** “我的账户”首页：最近的订单和物流。 */
	public static function dashboard_orders(): void {
		$uid = get_current_user_id();
		if ( ! $uid || ! function_exists( 'wc_get_orders' ) ) {
			return;
		}
		$orders = wc_get_orders( array( 'customer_id' => $uid, 'limit' => 5, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'objects' ) );
		$user   = wp_get_current_user();
		echo '<section class="pc-acc-orders" aria-labelledby="pc-acc-orders-title"><h2 id="pc-acc-orders-title">Your orders &amp; shipments</h2>';
		if ( $user && strtolower( (string) $user->user_email ) && wp_check_password( (string) $user->user_email, (string) $user->user_pass, $uid ) ) {
			echo '<p class="pc-acc-orders__warn">Your password is still your email address. <a class="button" href="' . esc_url( wc_get_account_endpoint_url( 'edit-account' ) ) . '">Change password</a></p>';
		}
		if ( ! $orders ) {
			echo '<p class="pc-acc-orders__empty">No orders yet. <a class="button" href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '">Start shopping</a></p></section>';
			return;
		}
		echo '<ul class="pc-acc-orders__list">';
		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			$view  = class_exists( Shipping_Tracking::class ) ? Shipping_Tracking::customer_view( $order ) : null;
			$items = array();
			foreach ( $order->get_items( 'line_item' ) as $it ) {
				$items[] = $it->get_name() . ' x' . (int) $it->get_quantity();
			}
			$date = $order->get_date_created();
			echo '<li class="pc-acc-orders__item">';
			echo '<div class="pc-acc-orders__head"><strong>Order #' . esc_html( $order->get_order_number() ) . '</strong><span>' . esc_html( $date ? wc_format_datetime( $date ) : '' ) . '</span></div>';
			echo '<p class="pc-acc-orders__items">' . esc_html( implode( ', ', $items ) ) . ' &middot; ' . wp_kses_post( $order->get_formatted_order_total() ) . '</p>';
			echo '<p class="pc-acc-orders__status"><span class="pc-acc-orders__dot" aria-hidden="true"></span>' . esc_html( self::ship_line( $order, $view ) ) . '</p>';
			foreach ( (array) ( $view['shipments'] ?? array() ) as $sh ) {
				echo '<div class="pc-acc-orders__ship">';
				echo '<span>' . esc_html( (string) ( $sh['provider_name'] ?? 'Carrier' ) ) . '</span>';
				if ( ! empty( $sh['tracking_number'] ) ) {
					echo '<code>' . esc_html( (string) $sh['tracking_number'] ) . '</code>';
				}
				if ( ! empty( $sh['tracking_url'] ) ) {
					echo '<a class="button" href="' . esc_url( (string) $sh['tracking_url'] ) . '" target="_blank" rel="noopener">Track package</a>';
				}
				echo '</div>';
			}
			echo '<a class="button pc-acc-orders__view" href="' . esc_url( $order->get_view_order_url() ) . '">View order</a>';
			echo '</li>';
		}
		echo '</ul></section>';
		echo '<style>.pc-acc-orders{margin:0 0 28px}.pc-acc-orders h2{font-size:1.25rem;margin:0 0 12px}.pc-acc-orders__warn{padding:12px 14px;border-radius:10px;background:#fff7e6;color:#7a4b00;display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between}.pc-acc-orders__list{list-style:none;margin:0;padding:0;display:grid;gap:12px}.pc-acc-orders__item{border:1px solid rgba(0,0,0,.12);border-radius:12px;padding:14px 16px;display:grid;gap:6px}.pc-acc-orders__head{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap}.pc-acc-orders__head span{opacity:.7;font-size:.9em}.pc-acc-orders__items{margin:0;opacity:.85}.pc-acc-orders__status{margin:0;font-weight:600;display:flex;align-items:center;gap:8px}.pc-acc-orders__dot{width:8px;height:8px;border-radius:50%;background:#16a34a}.pc-acc-orders__ship{display:flex;flex-wrap:wrap;gap:8px 12px;align-items:center;padding:10px 12px;border-radius:8px;background:rgba(0,0,0,.04)}.pc-acc-orders__ship code{word-break:break-all}.pc-acc-orders__view{justify-self:start}</style>';
	}
}
