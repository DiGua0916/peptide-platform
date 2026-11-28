<?php
/**
 * Plugin Name:       隐身回传容灾桥接中心
 * Description:       全链路合规脱敏回传、单域名多槽位并行分发（主归属 primary + 旁路养模 shadow）、第一方_fbp/_fbc/external_id三级持久化、安全页零上报隔离与24h实时差额核账中心。
 * Version:           3.9.3
 * Author:            平台研发中心
 * Network:           true
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to:   9.5
 */

defined( 'ABSPATH' ) || exit;
if ( defined( 'STEALTH_CAPI_BRIDGE_VERSION' ) ) {
	return;
}

define( 'STEALTH_CAPI_BRIDGE_VERSION', '3.9.3' );
define( 'STEALTH_CAPI_BRIDGE_FILE', __FILE__ );
define( 'STEALTH_CAPI_BRIDGE_DIR', plugin_dir_path( __FILE__ ) );
define( 'STEALTH_CAPI_BRIDGE_URL', plugin_dir_url( __FILE__ ) );

require_once STEALTH_CAPI_BRIDGE_DIR . 'includes/class-config-manager.php';
require_once STEALTH_CAPI_BRIDGE_DIR . 'includes/class-reconcile-cron.php';
require_once STEALTH_CAPI_BRIDGE_DIR . 'includes/class-context-freezer.php';
require_once STEALTH_CAPI_BRIDGE_DIR . 'includes/class-cashier-listener.php';
require_once STEALTH_CAPI_BRIDGE_DIR . 'includes/class-rest-pulse-api.php';
require_once STEALTH_CAPI_BRIDGE_DIR . 'includes/class-daily-inspector.php';
require_once STEALTH_CAPI_BRIDGE_DIR . 'includes/class-network-hub-ui.php';
require_once STEALTH_CAPI_BRIDGE_DIR . 'includes/class-subsite-buyer-ui.php';
require_once STEALTH_CAPI_BRIDGE_DIR . 'includes/class-safe-shield.php';

// 0. 强制后台与登录页默认使用简体中文（前台访客仍保持英文商城界面不受影响）。
add_filter(
	'determine_locale',
	static function ( string $locale ): string {
		$is_login = isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'];
		if ( ( is_admin() || $is_login ) && ( ! defined( 'WP_CLI' ) || ! WP_CLI ) ) {
			if ( is_readable( WP_LANG_DIR . '/zh_CN.mo' ) || is_readable( WP_CONTENT_DIR . '/plugins/peptide-core/languages/peptide-core-zh_CN.mo' ) || is_readable( WP_CONTENT_DIR . '/plugins/peptide-hq/languages/peptide-hq-zh_CN.mo' ) ) {
				return 'zh_CN';
			}
		}
		return $locale;
	},
	5
);

// 1. Declare WooCommerce HPOS (`custom_order_tables`) compatibility.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				defined( 'PEPTIDE_OPS_FILE' ) ? PEPTIDE_OPS_FILE : STEALTH_CAPI_BRIDGE_FILE,
				true
			);
		}
	}
);

// 2. Lifecycle hooks for 10-minute `batch-check` reconciliation cron.
register_activation_hook(
	STEALTH_CAPI_BRIDGE_FILE,
	static function (): void {
		\StealthCapiBridge\Reconcile_Cron::add_cron_interval( array() );
		\StealthCapiBridge\Reconcile_Cron::ensure_cron_scheduled();
	}
);

register_deactivation_hook(
	STEALTH_CAPI_BRIDGE_FILE,
	array( \StealthCapiBridge\Reconcile_Cron::class, 'deactivate' )
);

// 3. Bootstrap runtime modules, Hub UI & Subsite Buyer Read-Only Heatmap UI.
add_action(
	'plugins_loaded',
	static function (): void {
		\StealthCapiBridge\Reconcile_Cron::register_hooks();
		\StealthCapiBridge\Context_Freezer::register_hooks();
		\StealthCapiBridge\Cashier_Listener::register_hooks();
		\StealthCapiBridge\REST_Pulse_API::register_hooks();
		\StealthCapiBridge\Daily_Inspector::register_hooks();
		\StealthCapiBridge\Network_Hub_UI::register_hooks();
		\StealthCapiBridge\Safe_Shield::register_hooks();

		if ( is_admin() ) {
			\StealthCapiBridge\Subsite_Buyer_UI::register_hooks();
		}
	},
	15
);

// 4. Non-destructive runtime bypass of legacy `peptide-core` Facebook Pixel & direct Meta CAPI
//    (ensures 0 browser Meta Pixel & prevents double Purchase while keeping GA4/GAds/TikTok intact).
add_filter(
	'option_peptide_core_pixels',
	static function ( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$cfg    = \StealthCapiBridge\Config_Manager::get_config();
		$tenant = \StealthCapiBridge\Config_Manager::resolve_current_tenant();
		if ( empty( $cfg['suppress_legacy_meta'] ) || ! $tenant ) {
			return $value;
		}
		foreach ( array( 'facebook', 'meta' ) as $fb_key ) {
			if ( isset( $value[ $fb_key ] ) ) {
				unset( $value[ $fb_key ] );
			}
			if ( isset( $value['platforms'][ $fb_key ] ) ) {
				unset( $value['platforms'][ $fb_key ] );
			}
		}
		return $value;
	},
	99
);

// 5. Server-side first-party cookie capture on Money Page landing (`_fbp`, `_fbc`, `_capi_eid`).
//    Strictly disabled on Safe Page, crawlers, or `ORGANIC_RETENTION_HUB`.
add_action(
	'init',
	static function (): void {
		if ( is_admin() || headers_sent() || \StealthCapiBridge\Config_Manager::is_safe_or_bot_request() ) {
			return;
		}
		$tenant = \StealthCapiBridge\Config_Manager::resolve_current_tenant();
		if ( ! $tenant || \StealthCapiBridge\Config_Manager::is_organic_retention_hub( $tenant ) ) {
			return;
		}

		$now_ms      = (int) round( microtime( true ) * 1000 );
		$cookie_opts = array(
			'expires'  => time() + 7776000,
			'path'     => '/',
			'domain'   => '',
			'secure'   => is_ssl(),
			'httponly' => false,
			'samesite' => 'Lax',
		);

		$existing_fbp = \StealthCapiBridge\Config_Manager::sanitize_fbp_cookie( (string) ( $_COOKIE['_capi_fbp'] ?? $_COOKIE['_fbp'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' === $existing_fbp ) {
			$rand10       = (string) wp_rand( 1000000000, 9999999999 );
			$existing_fbp = 'fb.1.' . $now_ms . '.' . $rand10;
			setcookie( '_fbp', $existing_fbp, $cookie_opts );
			setcookie( '_capi_fbp', $existing_fbp, $cookie_opts );
			$_COOKIE['_fbp']      = $existing_fbp;
			$_COOKIE['_capi_fbp'] = $existing_fbp;
		} elseif ( empty( $_COOKIE['_fbp'] ) || $_COOKIE['_fbp'] !== $existing_fbp ) {
			setcookie( '_fbp', $existing_fbp, $cookie_opts );
			$_COOKIE['_fbp'] = $existing_fbp;
		}

		$raw_fbclid   = \StealthCapiBridge\Config_Manager::extract_raw_fbclid();
		$existing_fbc = \StealthCapiBridge\Config_Manager::sanitize_fbc_cookie( (string) ( $_COOKIE['_capi_fbc'] ?? $_COOKIE['_fbc'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' !== $raw_fbclid ) {
			$fbc_parts  = '' !== $existing_fbc ? explode( '.', $existing_fbc, 4 ) : array();
			$cur_suffix = isset( $fbc_parts[3] ) ? $fbc_parts[3] : '';
			if ( $cur_suffix !== $raw_fbclid ) {
				$candidate = \StealthCapiBridge\Config_Manager::sanitize_fbc_cookie( 'fb.1.' . $now_ms . '.' . $raw_fbclid );
				if ( '' !== $candidate ) {
					setcookie( '_fbc', $candidate, $cookie_opts );
					setcookie( '_capi_fbc', $candidate, $cookie_opts );
					$_COOKIE['_fbc']      = $candidate;
					$_COOKIE['_capi_fbc'] = $candidate;
				}
			}
		} elseif ( '' !== $existing_fbc && empty( $_COOKIE['_fbc'] ) ) {
			setcookie( '_fbc', $existing_fbc, $cookie_opts );
			$_COOKIE['_fbc'] = $existing_fbc;
		}

		$existing_eid = \StealthCapiBridge\Config_Manager::sanitize_external_id( (string) ( $_COOKIE['_capi_eid'] ?? $_COOKIE['pc_vid'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' === $existing_eid ) {
			try {
				$existing_eid = bin2hex( random_bytes( 16 ) );
			} catch ( \Throwable $e ) {
				$existing_eid = 'eid_' . $now_ms . '_' . wp_rand( 1000000000, 9999999999 );
			}
			setcookie( '_capi_eid', $existing_eid, $cookie_opts );
			$_COOKIE['_capi_eid'] = $existing_eid;
		}
	},
	1
);

// 6. Lossless tracking query preservation across internal storefront redirects (e.g. `/product/xxx/` -> `/?vc_product=ID#vc-products`).
add_filter(
	'wp_redirect',
	static function ( string $location, int $status = 302 ): string {
		if ( is_admin() || '' === $location ) {
			return $location;
		}
		$raw_qs = isset( $_SERVER['QUERY_STRING'] ) ? (string) $_SERVER['QUERY_STRING'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( '' === $raw_qs ) {
			return $location;
		}

		$loc_host = strtolower( (string) wp_parse_url( $location, PHP_URL_HOST ) );
		$req_host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) preg_replace( '/:\d+$/', '', sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) ) : '';
		$pub_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		if ( '' !== $loc_host && $loc_host !== $req_host && $loc_host !== $pub_host ) {
			return $location;
		}

		$preserve_keys = array(
			'fbclid',
			'cr',
			'lp_ver',
			'placement',
			'ad_name',
			'utm_source',
			'utm_medium',
			'utm_campaign',
			'utm_term',
			'utm_content',
			'utm_id',
			'utm_source_platform',
			'gclid',
			'ttclid',
			'msclkid',
		);

		$frag     = '';
		$hash_pos = strpos( $location, '#' );
		if ( false !== $hash_pos ) {
			$frag     = substr( $location, $hash_pos );
			$location = substr( $location, 0, $hash_pos );
		}

		$loc_qs   = (string) wp_parse_url( $location, PHP_URL_QUERY );
		$missing  = array();
		foreach ( explode( '&', $raw_qs ) as $pair ) {
			if ( '' === $pair || false === strpos( $pair, '=' ) ) {
				continue;
			}
			list( $k_raw, $v_raw ) = explode( '=', $pair, 2 );
			$k_clean = rawurldecode( $k_raw );
			if ( ! in_array( $k_clean, $preserve_keys, true ) || '' === $v_raw || strlen( $v_raw ) > 2048 ) {
				continue;
			}
			if ( ! preg_match( '/(?:^|&)' . preg_quote( $k_clean, '/' ) . '=/', $loc_qs ) ) {
				$missing[] = rawurlencode( $k_clean ) . '=' . $v_raw;
			}
		}

		if ( ! empty( $missing ) ) {
			$sep      = ( false === strpos( $location, '?' ) ) ? '?' : '&';
			$location = $location . $sep . implode( '&', $missing );
		}

		return $location . $frag;
	},
	1,
	2
);

// 7. First-paint synchronous `<head>` first-party cookie + Store API / AJAX header bootstrapper on Money Page ONLY (zero third-party SDK, zero Safe Page scripts).
add_action(
	'wp_head',
	static function (): void {
		if ( is_admin() || \StealthCapiBridge\Config_Manager::is_safe_or_bot_request() ) {
			return;
		}
		$cfg    = \StealthCapiBridge\Config_Manager::get_config();
		$tenant = \StealthCapiBridge\Config_Manager::resolve_current_tenant();
		if ( empty( $cfg['auto_enqueue_sdk'] ) || ! $tenant || \StealthCapiBridge\Config_Manager::is_organic_retention_hub( $tenant ) ) {
			return;
		}
		echo "<script id=\"stealth-capi-first-paint\">((w,d)=>{try{var l=w.location,ls=w.localStorage,t=Date.now(),s=l.protocol==='https:'?';Secure':'',r=k=>{var m=d.cookie.match('(?:^|; )'+k+'=([^;]*)');if(m)try{return decodeURIComponent(m[1])}catch(e){return m[1]}try{return(ls&&ls.getItem(k))||''}catch(e){return''}},w1=(k,v)=>{if(!v)return;d.cookie=k+'='+encodeURIComponent(v)+';path=/;max-age=7776000;SameSite=Lax'+s;try{ls&&ls.setItem(k,v)}catch(e){}},rn=()=>''+Math.floor(1e9+Math.random()*9e9),pr=/^fb\\.[012]\\.\\d{10,16}\\.\\d{8,16}$/,cr=/^fb\\.[012]\\.\\d{10,16}\\.[A-Za-z0-9._\\-]{1,512}$/,er=/^[A-Za-z0-9_.\\-]{6,128}$/,p=r('_capi_fbp')||r('_fbp');if(!pr.test(p))p='fb.1.'+t+'.'+rn();w1('_fbp',p);w1('_capi_fbp',p);var qm=l.search.match(/(?:^\\?|&)fbclid=([^&#]+)/),q=qm?decodeURIComponent(qm[1]).trim():'',c=r('_capi_fbc')||r('_fbc');if(q&&/^[A-Za-z0-9._\\-]{1,512}$/.test(q)&&(!cr.test(c)||c.split('.').slice(3).join('.')!==q))c='fb.1.'+t+'.'+q;if(cr.test(c)){w1('_fbc',c);w1('_capi_fbc',c);}var e=r('_capi_eid')||r('pc_vid');if(!er.test(e))e='eid_'+t+'_'+rn()+rn().slice(0,6);w1('_capi_eid',e);var mu=u=>/(\\/wc\\/store\\/|wc%2Fstore|wc-ajax=|\\/wp-json\\/stealth-capi\\/)/i.test(''+u);if(w.fetch&&!w.fetch._cp){var of=w.fetch,pf=function(a,o){var u=(a&&a.url)||a||'';if(mu(u)){var h=new Headers((o&&o.headers)||{});[['Fbp',r('_capi_fbp')||p],['Fbc',r('_capi_fbc')||c],['Eid',r('_capi_eid')||e],['Page-Url',l.href]].forEach(x=>x[1]&&h.set('X-Capi-'+x[0],x[1]));o={...o,headers:h};}return of.call(this,a,o);};pf._cp=1;w.fetch=pf;}if(w.XMLHttpRequest&&!w.XMLHttpRequest._cp){var xo=w.XMLHttpRequest.prototype.open,xs=w.XMLHttpRequest.prototype.send;w.XMLHttpRequest.prototype.open=function(m,u){this._cu=u;return xo.apply(this,arguments);};w.XMLHttpRequest.prototype.send=function(){try{if(mu(this._cu)){[['Fbp',r('_capi_fbp')||p],['Fbc',r('_capi_fbc')||c],['Eid',r('_capi_eid')||e],['Page-Url',l.href]].forEach(x=>x[1]&&this.setRequestHeader('X-Capi-'+x[0],x[1]));}}catch(err){}return xs.apply(this,arguments);};w.XMLHttpRequest._cp=1;}}catch(err){}})(window,document);</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	},
	1
);

// 8. Auto-enqueue first-party full-funnel `fp-pulse.js` on `AD_ACQUISITION` Money Pages ONLY (0 scripts on Safe Page or `ORGANIC_RETENTION_HUB`).
add_action(
	'wp_enqueue_scripts',
	static function (): void {
		if ( \StealthCapiBridge\Config_Manager::is_safe_or_bot_request() ) {
			return;
		}
		$cfg    = \StealthCapiBridge\Config_Manager::get_config();
		$tenant = \StealthCapiBridge\Config_Manager::resolve_current_tenant();
		if ( empty( $cfg['auto_enqueue_sdk'] ) || ! $tenant || \StealthCapiBridge\Config_Manager::is_organic_retention_hub( $tenant ) ) {
			return;
		}
		wp_register_script(
			'stealth-capi-fp-pulse',
			STEALTH_CAPI_BRIDGE_URL . 'assets/fp-pulse.js',
			array(),
			STEALTH_CAPI_BRIDGE_VERSION,
			true
		);
		$currency   = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';
		$inline_cfg = array(
			'endpoint'     => esc_url_raw( rest_url( 'stealth-capi/v1/pulse' ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'currency'     => $currency,
			'autoPageView' => true,
		);
		if ( function_exists( 'is_product' ) && is_product() && function_exists( 'wc_get_product' ) ) {
			$prod_id = function_exists( 'get_queried_object_id' ) ? (int) get_queried_object_id() : 0;
			$product = $prod_id > 0 ? wc_get_product( $prod_id ) : null;
			if ( $product instanceof \WC_Product ) {
				$raw_sku                = (string) $product->get_sku();
				$raw_sku                = '' !== $raw_sku ? $raw_sku : 'WC_' . $product->get_id();
				$price                  = $product->is_type( 'variable' )
					? round( (float) $product->get_variation_price( 'min' ), 2 )
					: round( (float) $product->get_price(), 2 );
				$inline_cfg['viewItem'] = array(
					'product_id'  => (int) $product->get_id(),
					'currency'    => $currency,
					'total_value' => $price,
					'items'       => array(
						array(
							'raw_sku'    => $raw_sku,
							'quantity'   => 1,
							'unit_price' => $price,
						),
					),
				);
			}
		}
		if ( ! empty( $_GET['vc_product'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$vc_pid = absint( wp_unslash( $_GET['vc_product'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $vc_pid > 0 ) {
				$inline_cfg['vcProductId'] = $vc_pid;
			}
		}
		if ( function_exists( 'is_search' ) && is_search() ) {
			$inline_cfg['isSearch'] = true;
		}
		$is_real_checkout = function_exists( 'is_checkout' )
			&& is_checkout()
			&& ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() )
			&& ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-pay' ) );
		if ( $is_real_checkout ) {
			$cart_snap                = \StealthCapiBridge\Context_Freezer::extract_cart_snapshot();
			$inline_cfg['isCheckout'] = true;
			if ( $cart_snap['has_items'] ) {
				$inline_cfg['checkoutCart'] = array(
					'currency'    => $cart_snap['currency'],
					'total_value' => $cart_snap['total_value'],
					'items'       => $cart_snap['items'],
					'cart_sig'    => $cart_snap['cart_sig'],
				);
			}
		}
		wp_add_inline_script(
			'stealth-capi-fp-pulse',
			'window.STEALTH_CAPI_CFG=' . wp_json_encode( $inline_cfg, JSON_UNESCAPED_SLASHES ) . ';',
			'before'
		);
		wp_enqueue_script( 'stealth-capi-fp-pulse' );
	},
	5
);
