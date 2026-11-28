<?php
/**
 * 内置同域合规白页守卫 + 斗篷真实完整客户端地址协同护盾 (`class-safe-shield.php`)。
 *
 * 让 `wp-capi-stealth-bridge.zip` 实现 100% 纯插件式零改码安装：
 * 1. 直接在「总后台 → 插件管理 → 第三方插件」上传安装即可生效，无需修改 `peptide-core` 或前台主题任何一行代码；
 * 2. 在 `init` 优先级 `0`（早于 `Store_Config::block_bots` 优先级 `1`）向广告审核爬虫返回同域 HTTP 200 合规护肤白页；
 * 3. 在首毫秒自动从 `Visit_Facts::client_ip()` 提取完整 128 位客户端真实地址注入 `$GLOBALS['wprlo_verified_client_ip']`；
 * 4. 自动拦截并清空旧斗篷配置中跳往外部 `shiyishop` 等域名的危险跳转项。
 */

namespace StealthCapiBridge;

defined( 'ABSPATH' ) || exit;

final class Safe_Shield {

	private const AD_REVIEW_BOTS = array(
		'facebookexternalhit',
		'facebot',
		'meta-externalagent',
		'meta-externalfetcher',
		'adsbot-google',
		'mediapartners-google',
		'apis-google',
		'tiktokspider',
		'twitterbot',
		'pinterestbot',
	);

	private const THEMES = array(
		array(
			'brand'    => 'Lumina Daily Botanicals',
			'tagline'  => 'Plant-Based Daily Hydration & Botanical Rituals',
			'accent'   => '#0f766e',
			'bg'       => '#f0fdfa',
			'products' => array(
				array( 'sku' => 'WLN-101-01', 'title' => 'Botanical Morning Dew Facial Mist', 'price' => '$48.00', 'desc' => 'Refreshing aloe, chamomile, and rosewater mist for daily skin comfort.' ),
				array( 'sku' => 'WLN-110-03', 'title' => 'Velvet Oat & Ceramide Barrier Cream', 'price' => '$64.00', 'desc' => 'Rich daily moisturizer crafted with colloidal oat and plant lipids.' ),
				array( 'sku' => 'CLM-404-01', 'title' => 'Evening Lavender & Cedar Pillow Spray', 'price' => '$32.00', 'desc' => 'Calming aromatic linen spray blended with natural essential oils.' ),
			),
		),
		array(
			'brand'    => 'Velora Home & Living',
			'tagline'  => 'Thoughtful Desk, Travel & Everyday Linen Essentials',
			'accent'   => '#4338ca',
			'bg'       => '#eef2ff',
			'products' => array(
				array( 'sku' => 'ESS-601-01', 'title' => 'Woven Belgian Linen Desk Mat', 'price' => '$54.00', 'desc' => 'Natural textured linen workspace mat with non-slip cork backing.' ),
				array( 'sku' => 'ESS-608-02', 'title' => 'Insulated Ceramic Travel Tumbler', 'price' => '$42.00', 'desc' => 'Double-wall stainless tumbler with matte ceramic interior coating.' ),
				array( 'sku' => 'WLN-105-02', 'title' => 'Cedarwood & Bergamot Reed Diffuser', 'price' => '$46.00', 'desc' => 'Slow-release home fragrance crafted for calm study and living spaces.' ),
			),
		),
		array(
			'brand'    => 'Solace Pure Care',
			'tagline'  => 'Gentle Scalp, Bath & Body Nourishment',
			'accent'   => '#9a3412',
			'bg'       => '#fff7ed',
			'products' => array(
				array( 'sku' => 'RPR-502-01', 'title' => 'Rosemary & Jojoba Scalp Massage Oil', 'price' => '$39.00', 'desc' => 'Lightweight cold-pressed botanical oil blend for weekly scalp care.' ),
				array( 'sku' => 'VTL-212-01', 'title' => 'Shea & Sweet Almond Hand Balm', 'price' => '$28.00', 'desc' => 'Fast-absorbing botanical hand cream with vitamin E and calendula.' ),
				array( 'sku' => 'CLM-401-01', 'title' => 'Mineral Sea Salt & Eucalyptus Bath Soak', 'price' => '$36.00', 'desc' => 'Aromatic bath crystals for post-workout relaxation and skin softness.' ),
			),
		),
		array(
			'brand'    => 'Verdant Kitchen & Tea',
			'tagline'  => 'Artisanal Loose-Leaf Herbal Infusions & Teaware',
			'accent'   => '#166534',
			'bg'       => '#f0fdf4',
			'products' => array(
				array( 'sku' => 'LGV-304-01', 'title' => 'Organic Chamomile & Lemon Balm Tisane', 'price' => '$26.00', 'desc' => 'Caffeine-free whole-flower herbal infusion harvested in small batches.' ),
				array( 'sku' => 'VTL-205-01', 'title' => 'Roasted Barley & Ginger Morning Blend', 'price' => '$29.00', 'desc' => 'Warming toasted grain and root infusion for cozy mornings.' ),
				array( 'sku' => 'ESS-612-01', 'title' => 'Hand-Thrown Stoneware Steep Mug', 'price' => '$44.00', 'desc' => 'Artisan ceramic mug with removable stainless infuser basket and lid.' ),
			),
		),
		array(
			'brand'    => 'Aurelia Studio Goods',
			'tagline'  => 'Minimalist Everyday Carry & Travel Organizers',
			'accent'   => '#0369a1',
			'bg'       => '#f0f9ff',
			'products' => array(
				array( 'sku' => 'ESS-615-01', 'title' => 'Recycled Canvas Weekender Dopp Kit', 'price' => '$58.00', 'desc' => 'Water-resistant travel toiletry organizer with wide flat-lay opening.' ),
				array( 'sku' => 'ESS-619-02', 'title' => 'Full-Grain Leather Cable Folio', 'price' => '$68.00', 'desc' => 'Slim zip organizer keeping chargers, cords, and notebooks tidy.' ),
				array( 'sku' => 'WLN-118-01', 'title' => 'Merino Wool Travel Eye Mask', 'price' => '$34.00', 'desc' => 'Breathable contoured sleep mask for restful flights and hotel stays.' ),
			),
		),
	);

	public static function register_hooks(): void {
		self::expose_verified_client_ip();
		add_action( 'init', array( self::class, 'expose_verified_client_ip' ), 0 );
		add_filter( 'option_acloak_options', array( self::class, 'neutralize_external_cloak_redirect' ), 99 );

		// 若 peptide-core 已挂载同名守卫则跳过重复注册，否则由本插件独立接管！
		if ( ! class_exists( '\PeptideCore\Safe_White_Page', false ) ) {
			add_action( 'init', array( self::class, 'maybe_render_white_page' ), 0 );
			add_filter( 'robots_txt', array( self::class, 'allow_ad_review_bots_in_robots' ), 99 );
		}
	}

	public static function expose_verified_client_ip(): void {
		if ( ! empty( $GLOBALS['wprlo_verified_client_ip'] ) && is_string( $GLOBALS['wprlo_verified_client_ip'] ) ) {
			return;
		}
		if ( class_exists( '\PeptideCore\Tracking\Visit_Facts' ) && method_exists( '\PeptideCore\Tracking\Visit_Facts', 'client_ip' ) ) {
			$ip = (string) \PeptideCore\Tracking\Visit_Facts::client_ip();
			if ( '' !== $ip && false !== filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				$GLOBALS['wprlo_verified_client_ip'] = $ip;
			}
		}
	}

	public static function neutralize_external_cloak_redirect( $value ) {
		if ( ! is_array( $value ) || is_admin() ) {
			return $value;
		}
		foreach ( $value as $k => $v ) {
			if ( is_string( $v ) && false !== stripos( $v, 'shiyishop' ) ) {
				$value[ $k ] = '';
			}
		}
		return $value;
	}

	public static function allow_ad_review_bots_in_robots( $output ): string {
		$text = (string) $output;
		$text = preg_replace( '/User-agent:\s*facebookexternalhit\s+Disallow:\s*\/\s*/i', '', $text );
		return (string) $text;
	}

	public static function is_ad_review_crawler( string $ua ): bool {
		$lower = strtolower( $ua );
		if ( '' === $lower ) {
			return false;
		}
		foreach ( self::AD_REVIEW_BOTS as $kw ) {
			if ( false !== strpos( $lower, $kw ) ) {
				return true;
			}
		}
		return false;
	}

	public static function maybe_render_white_page(): void {
		if ( is_admin() || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( 0 === strpos( $uri, '/wp-json/' ) || 0 === strpos( $uri, '/wp-login.php' ) || 0 === strpos( $uri, '/wp-admin' ) || false !== strpos( $uri, 'wc-ajax=' ) || false !== strpos( $uri, '/pc-panapay/' ) ) {
			return;
		}

		$preview = isset( $_GET['capi_safe_preview'] ) && '1' === (string) $_GET['capi_safe_preview']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ua      = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		// 若当前店铺已启用「区域路由与边缘渲染策略 (WPRLO v2.6+)」，主动让权给 WPRLO 渲染该投手专属导入的 A 站母版，
		// 同时摘除旧版 peptide-core 在 init:1 的 403 爬虫拦截钩子，确保广告审核爬虫拿到同域 HTTP 200 专属 A 站！
		if ( ! $preview && function_exists( 'wprlo_get_options' ) ) {
			$wprlo_opts = wprlo_get_options();
			if ( is_array( $wprlo_opts ) && ! empty( $wprlo_opts['enabled'] ) ) {
				if ( ! empty( $GLOBALS['wprlo_blocked_request'] ) || self::is_ad_review_crawler( $ua ) ) {
					remove_action( 'init', array( 'PeptideCore\\Store_Config', 'block_bots' ), 1 );
					remove_action( 'init', array( '\PeptideCore\Store_Config', 'block_bots' ), 1 );
				}
				return;
			}
		}

		if ( ! $preview && ! self::is_ad_review_crawler( $ua ) ) {
			return;
		}

		// Mark request as Safe Page intercepted and strip any Set-Cookie headers for strict 0-cookie / 0-reporting isolation.
		$GLOBALS['wprlo_blocked_request'] = true;
		if ( ! headers_sent() ) {
			@header_remove( 'Set-Cookie' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$host  = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( preg_replace( '/:\d+$/', '', sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) ) : 'localhost';
		$idx   = isset( $_GET['safe_theme'] ) ? absint( $_GET['safe_theme'] ) % count( self::THEMES ) : ( abs( crc32( $host ) ) % count( self::THEMES ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$theme = self::THEMES[ $idx ];
		$path  = rtrim( (string) parse_url( $uri, PHP_URL_PATH ), '/' );
		$path  = '' === $path ? '/' : $path;

		if ( function_exists( 'status_header' ) ) {
			status_header( 200 );
		}
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}
		header( 'Content-Type: text/html; charset=utf-8' );
		echo self::render_html( $theme, $path, $host, $preview ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	private static function render_html( array $theme, string $path, string $host, bool $preview ): string {
		$brand   = htmlspecialchars( $theme['brand'], ENT_QUOTES, 'UTF-8' );
		$tagline = htmlspecialchars( $theme['tagline'], ENT_QUOTES, 'UTF-8' );
		$accent  = htmlspecialchars( $theme['accent'], ENT_QUOTES, 'UTF-8' );
		$bg      = htmlspecialchars( $theme['bg'], ENT_QUOTES, 'UTF-8' );
		$qs      = $preview ? '?capi_safe_preview=1' : '';

		$page_heading = $brand . ' — ' . $tagline;
		if ( '/cart' === $path ) {
			$page_heading = 'Your Shopping Bag — ' . $brand;
			$main_html    = '<div class="card"><h2>Shopping Bag</h2><p>Your shopping bag is currently ready for selection. Complimentary standard US shipping on orders over $75.</p><p><a class="btn" href="/shop/catalog' . $qs . '">Browse Collection</a> <a class="btn" style="margin-left:8px;" href="/checkout' . $qs . '">Proceed to Checkout</a></p></div>';
		} elseif ( '/checkout' === $path ) {
			$page_heading = 'Secure Checkout — ' . $brand;
			$main_html    = '<div class="card"><h2>Guest &amp; Member Checkout</h2><p>All orders ship within 1–2 business days from our US fulfillment center with 30-day hassle-free returns.</p><form onsubmit="return false;" style="display:grid;gap:12px;max-width:460px;">'
				. '<input type="email" placeholder="Email address" style="padding:10px;border:1px solid #cbd5e1;border-radius:6px;" />'
				. '<input type="text" placeholder="Full name" style="padding:10px;border:1px solid #cbd5e1;border-radius:6px;" />'
				. '<input type="text" placeholder="Street address, City, State, ZIP" style="padding:10px;border:1px solid #cbd5e1;border-radius:6px;" />'
				. '<button class="btn" type="submit">Continue to Shipping Method</button></form></div>';
		} else {
			if ( '/shop' === $path || '/shop/catalog' === $path ) {
				$page_heading = 'All Collections — ' . $brand;
			}
			$cards = '';
			foreach ( $theme['products'] as $prod ) {
				$cards .= '<article class="card">'
					. '<div style="font-size:12px;color:#64748b;margin-bottom:6px;">Catalog ID: ' . htmlspecialchars( $prod['sku'], ENT_QUOTES, 'UTF-8' ) . '</div>'
					. '<h3 style="margin:0 0 8px;">' . htmlspecialchars( $prod['title'], ENT_QUOTES, 'UTF-8' ) . '</h3>'
					. '<p style="color:#475569;font-size:14px;min-height:42px;">' . htmlspecialchars( $prod['desc'], ENT_QUOTES, 'UTF-8' ) . '</p>'
					. '<div style="display:flex;justify-content:space-between;align-items:center;margin-top:14px;">'
					. '<strong style="font-size:18px;">' . htmlspecialchars( $prod['price'], ENT_QUOTES, 'UTF-8' ) . '</strong>'
					. '<a class="btn" href="/cart' . $qs . '">Add to Bag</a>'
					. '</div></article>';
			}
			$main_html = '<section class="hero"><h1>' . $brand . '</h1><p>' . $tagline . '</p><a class="btn" href="/shop/catalog' . $qs . '">Explore Catalog</a></section>'
				. '<section class="grid">' . $cards . '</section>';
		}

		return '<!doctype html><html lang="en-US"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<title>' . $page_heading . '</title>'
			. '<style>body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#0f172a;background:#f8fafc;line-height:1.6}'
			. 'header{background:#fff;border-bottom:1px solid #e2e8f0;padding:16px 24px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap}'
			. 'nav a{color:#334155;text-decoration:none;margin-left:18px;font-weight:500}'
			. '.wrap{max-width:1040px;margin:0 auto;padding:28px 20px}'
			. '.hero{background:' . $bg . ';border-radius:14px;padding:36px 28px;margin-bottom:26px}'
			. '.hero h1{margin:0 0 8px;font-size:28px;color:' . $accent . '}'
			. '.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(270px,1fr));gap:18px}'
			. '.card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px}'
			. '.btn{display:inline-block;background:' . $accent . ';color:#fff;padding:9px 16px;border-radius:8px;text-decoration:none;border:0;cursor:pointer;font-size:14px}'
			. 'footer{border-top:1px solid #e2e8f0;margin-top:44px;padding:24px;text-align:center;font-size:13px;color:#64748b;background:#fff}</style></head>'
			. '<body><header><strong>' . $brand . '</strong><nav>'
			. '<a href="/' . $qs . '">Home</a>'
			. '<a href="/shop/catalog' . $qs . '">Catalog</a>'
			. '<a href="/cart' . $qs . '">Bag</a>'
			. '<a href="/checkout' . $qs . '">Checkout</a>'
			. '</nav></header><main class="wrap">' . $main_html . '</main>'
			. '<footer>&copy; ' . gmdate( 'Y' ) . ' ' . $brand . ' (' . htmlspecialchars( $host, ENT_QUOTES, 'UTF-8' ) . ') &middot; Shipping &amp; Returns &middot; Privacy Policy &middot; Terms of Service</footer></body></html>';
	}
}
