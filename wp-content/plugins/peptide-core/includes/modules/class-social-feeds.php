<?php
/** Explicitly loaded public social widgets. Vendor code never runs in the store/admin document. */
namespace PeptideCore\Modules;

use PeptideCore\Store_Config;
use PeptideCore\Templates;

defined( 'ABSPATH' ) || exit;

final class Social_Feeds {

	private const TYPES = array( 'x_timeline' => 'x', 'pinterest_profile' => 'pinterest', 'facebook_page' => 'facebook' );
	private const DEFAULTS = array(
		'x' => '<p>Embedded timelines are an easy way to embed posts on your website in a compact, linear view. Display the latest posts from an X account, lists, or your curated collections.</p>',
		'pinterest' => '<p>Display the latest updated list from Pinterest account.</p>',
		'facebook' => '<p>With the public homepage plugin, you can easily embed and promote any public Facebook homepage on your website. Just like on Facebook, your visitors can like and share the homepage on the site.</p>',
	);
	private static bool $registered = false;

	/** Standard editor canvas hooks load only our local assets, never vendor scripts. */
	public static function register_hooks(): void {
		if ( self::$registered ) { return; }
		self::$registered = true;
		add_action( 'enqueue_block_assets', array( self::class, 'editor_assets' ) );
	}

	public static function editor_assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( is_admin() && $screen && Templates::LAYOUT === $screen->post_type ) { self::assets(); }
	}

	private static function assets(): void {
		$base = plugins_url( 'assets/', PEPTIDE_CORE_FILE );
		wp_enqueue_style( 'pc-social-feeds', $base . 'social-feeds.css', array(), (string) filemtime( PEPTIDE_CORE_DIR . '/assets/social-feeds.css' ) );
		wp_enqueue_script( 'pc-social-feeds', $base . 'social-feeds.js', array(), (string) filemtime( PEPTIDE_CORE_DIR . '/assets/social-feeds.js' ), true );
	}

	public static function definitions(): array {
		$defs = array();
		foreach ( self::TYPES as $type => $provider ) {
			$name = 'x' === $provider ? 'X' : ( 'pinterest' === $provider ? 'Pinterest' : 'Facebook' );
			$block = 'vialcove/' . str_replace( '_', '-', $type );
			$global = array( 'group' => 'general' );
			$fields = array(
				Fields::f( 'text', 'title', '标题', $name, $global + array( 'panel' => 'content' ) ),
				Fields::f( 'html', 'descriptionHtml', '简短描述', null, $global + array( 'ui' => 'richtext', 'richTextMedia' => 'image', 'richMediaBlock' => $block, 'panel' => 'content', 'help' => '电脑和手机共用。正文图片沿用媒体审批；外站图片在点击加载后显示。' ) ),
				Fields::f( 'text', 'account', '账号', '', $global + array( 'panel' => 'account', 'help' => '填写公开主页账号或完整 HTTPS 地址。格式有效不保证第三方允许嵌入；预览不会自动连接平台。' ) ),
				Fields::f( 'number', 'heightPx', '内容高度', 500, $global + array( 'panel' => 'account', 'min' => 60, 'max' => 4000, 'step' => 1, 'precision' => 0, 'unit' => 'px', 'help' => '60–4000 是本站可编辑范围，不保证平台支持每个高度。Pinterest 此值用于内容区，平台头尾可能增加总高度。' ) ),
			);
			$account_fields = array( 'account', 'heightPx' );
			if ( 'pinterest' !== $provider ) {
				$fields[] = Fields::f( 'select', 'descriptionPosition', '描述位置', 'x' === $provider ? 'left' : 'right', $global + array( 'panel' => 'account', 'options' => array( 'left' => '居左', 'right' => '居右' ), 'help' => '仅影响电脑；手机说明始终在内容上方。' ) );
				$account_fields[] = 'descriptionPosition';
			}
			if ( 'facebook' === $provider ) {
				foreach ( array( 'showTimeline' => 'Timeline', 'showEvents' => 'Events', 'showMessages' => 'Messages' ) as $key => $label ) {
					$fields[] = Fields::f( 'toggle', $key, $label, true, $global + array( 'panel' => 'account' ) );
					$account_fields[] = $key;
				}
			}
			$defs[ $type ] = array(
				'title' => 'x' === $provider ? 'X (Twitter)' : $name, 'category' => 'operation', 'icon' => 'share', 'singleRender' => true,
				'editorDefaults' => array( 'descriptionHtml' => self::DEFAULTS[ $provider ], 'paddingTopPx' => array( 'desktop' => 0, 'mobile' => 0 ), 'paddingBottomPx' => array( 'desktop' => 0, 'mobile' => 0 ) ),
				'settings' => $fields,
				'panels' => array(
					array( 'id' => 'content', 'label' => '标题与描述', 'initialOpen' => true, 'fields' => array( 'title', 'descriptionHtml' ) ),
					array( 'id' => 'account', 'label' => '账号与内容设置', 'initialOpen' => true, 'fields' => $account_fields ),
				),
			);
		}
		return $defs;
	}

	/** A conservative local input subset, not an assertion that the remote account exists. */
	public static function parse_account( string $provider, $raw ): ?array {
		if ( ! is_string( $raw ) || strlen( $raw ) > 300 || preg_match( '/[\x00-\x1f\x7f%]/', $raw ) ) { return null; }
		$raw = trim( $raw );
		if ( '' === $raw || str_contains( $raw, ' ' ) ) { return null; }
		$hosts = array(
			'x' => array( 'x.com', 'www.x.com', 'twitter.com', 'www.twitter.com' ),
			'pinterest' => array( 'pinterest.com', 'www.pinterest.com' ),
			'facebook' => array( 'facebook.com', 'www.facebook.com' ),
		);
		if ( ! isset( $hosts[ $provider ] ) ) { return null; }
		$account = $raw;
		if ( str_contains( $raw, '://' ) ) {
			$url = wp_parse_url( $raw );
			if ( ! is_array( $url ) || 'https' !== strtolower( $url['scheme'] ?? '' ) || ! in_array( strtolower( $url['host'] ?? '' ), $hosts[ $provider ], true ) || isset( $url['user'], $url['pass'] ) || isset( $url['user'] ) || isset( $url['pass'] ) || isset( $url['query'] ) || isset( $url['fragment'] ) || ( isset( $url['port'] ) && 443 !== $url['port'] ) || ! preg_match( '#^/([^/]+)/?$#D', $url['path'] ?? '', $match ) ) { return null; }
			$account = $match[1];
		}
		$patterns = array( 'x' => '/^[A-Za-z0-9_]{1,15}$/D', 'pinterest' => '/^[A-Za-z0-9_]{1,64}$/D', 'facebook' => '/^[A-Za-z0-9.]{1,100}$/D' );
		if ( ! preg_match( $patterns[ $provider ], $account ) ) { return null; }
		$reserved = array(
			'x' => array( 'home', 'intent', 'share', 'search', 'settings', 'i', 'login', 'logout' ),
			'pinterest' => array( 'pin', 'pins', 'search', 'settings', 'login', 'logout' ),
			'facebook' => array( 'groups', 'events', 'plugins', 'watch', 'marketplace', 'login', 'logout', 'share', 'posts', 'stories', 'profile.php', 'permalink.php' ),
		);
		if ( in_array( strtolower( $account ), $reserved[ $provider ], true ) || str_contains( $account, '..' ) || '.' === $account[0] || '.' === substr( $account, -1 ) ) { return null; }
		$base = array( 'x' => 'https://x.com/', 'pinterest' => 'https://www.pinterest.com/', 'facebook' => 'https://www.facebook.com/' );
		return array( 'account' => $account, 'url' => $base[ $provider ] . $account . ( 'x' === $provider ? '' : '/' ) );
	}

	/** Defense after Fields normalization; direct malformed values do not become widget parameters. */
	public static function height( $value ): ?int {
		if ( is_string( $value ) ) {
			if ( ! preg_match( '/^[0-9]{1,4}$/D', $value ) ) { return null; }
			$value = (int) $value;
		}
		if ( ( ! is_int( $value ) && ! is_float( $value ) ) || ! is_finite( (float) $value ) || floor( (float) $value ) !== (float) $value || $value < 60 || $value > 4000 ) { return null; }
		return (int) $value;
	}

	private static function same_origin( string $url, string $origin ): bool {
		$a = wp_parse_url( $url ); $b = wp_parse_url( $origin );
		if ( ! is_array( $a ) || ! is_array( $b ) || ! in_array( strtolower( $a['scheme'] ?? '' ), array( 'http', 'https' ), true ) ) { return false; }
		return strtolower( $a['scheme'] ?? '' ) === strtolower( $b['scheme'] ?? '' ) && strtolower( $a['host'] ?? '' ) === strtolower( $b['host'] ?? '' ) && (int) ( $a['port'] ?? ( 'https' === strtolower( $a['scheme'] ?? '' ) ? 443 : 80 ) ) === (int) ( $b['port'] ?? ( 'https' === strtolower( $b['scheme'] ?? '' ) ? 443 : 80 ) );
	}

	/** Local output transform only. Input images have already passed the unchanged Rich_Media policy. */
	public static function defer_images( string $html, string $origin ): string {
		if ( ! preg_match( '/<\s*img\b/i', $html ) ) { return $html; }
		if ( ! class_exists( '\WP_HTML_Tag_Processor' ) ) { return wp_kses( $html, array( 'p' => array(), 'br' => array(), 'strong' => array(), 'em' => array(), 'ul' => array(), 'ol' => array(), 'li' => array(), 'a' => array( 'href' => true, 'target' => true, 'rel' => true ) ) ); }
		$p = new \WP_HTML_Tag_Processor( $html );
		while ( $p->next_tag( 'IMG' ) ) {
			$src = $p->get_attribute( 'src' ); $srcset = $p->get_attribute( 'srcset' );
			$defer = ! is_string( $src ) || ! self::same_origin( $src, $origin );
			if ( is_string( $srcset ) && '' !== $srcset ) {
				foreach ( explode( ',', $srcset ) as $candidate ) {
					if ( ! preg_match( '/^\s*(\S+)(?:\s+[0-9]+(?:\.[0-9]+)?[wx])?\s*$/D', $candidate, $m ) || ! self::same_origin( $m[1], $origin ) ) { $defer = true; break; }
				}
			}
			if ( $defer ) {
				if ( is_string( $src ) ) { $p->set_attribute( 'data-pc-social-image-src', $src ); }
				if ( is_string( $srcset ) && '' !== $srcset ) { $p->set_attribute( 'data-pc-social-image-srcset', $srcset ); }
				$p->remove_attribute( 'src' ); $p->remove_attribute( 'srcset' );
				$p->set_attribute( 'data-pc-social-deferred-image', '1' );
			}
		}
		return $p->get_updated_html();
	}

	public static function render( array $module, string $context ): string {
		$type = $module['type'] ?? ''; $provider = self::TYPES[ $type ] ?? null;
		if ( ! $provider || ( Store_Config::get( 'force_login' ) && ! is_user_logged_in() ) ) { return ''; }
		$doc = ( ( 'editor' === $context && Renderer::$in_editor ) || ( 'preview' === $context && Templates::previewing_id() > 0 ) ) ? Renderer::$document_id : 0;
		$trusted = $doc > 0 && Templates::LAYOUT === get_post_type( $doc ) && current_user_can( 'edit_post', $doc );
		if ( 'public' !== $context && ! $trusted ) { return ''; }
		$active = ! empty( $module['enabled'] ) && ( ! empty( $module['visibility']['desktop'] ) || ! empty( $module['visibility']['mobile'] ) );
		if ( ! $active && ! $trusted ) { return ''; }
		$s = is_array( $module['settings'] ?? null ) ? $module['settings'] : array(); $account = self::parse_account( $provider, $s['account'] ?? null ); $height = self::height( $s['heightPx'] ?? null );
		$name = 'x' === $provider ? 'X' : ( 'pinterest' === $provider ? 'Pinterest' : 'Facebook' );
		$description = self::defer_images( is_string( $s['descriptionHtml'] ?? null ) ? $s['descriptionHtml'] : '', home_url( '/' ) );
		$head = '' !== ( $s['title'] ?? '' ) ? '<h2 class="pc-social__title">' . esc_html( $s['title'] ) . '</h2>' : '';
		$head .= '' !== $description ? '<div class="pc-social__description">' . $description . '</div>' : '';
		$tabs = array();
		foreach ( array( 'showTimeline' => 'timeline', 'showEvents' => 'events', 'showMessages' => 'messages' ) as $key => $tab ) { if ( ! empty( $s[ $key ] ) ) { $tabs[] = $tab; } }
		$loadable = $active && $account && null !== $height && ( 'facebook' !== $provider || $tabs );
		if ( ! $head && ! $account && ! $trusted ) { return ''; }
		self::assets();
		$position = 'right' === ( $s['descriptionPosition'] ?? '' ) ? 'right' : 'left';
		$config = $account && null !== $height ? array( 'provider' => $provider, 'account' => $account['account'], 'url' => $account['url'], 'height' => $height, 'tabs' => 'facebook' === $provider ? implode( ',', $tabs ) : '', 'active' => (bool) $loadable ) : null;
		$html = '<section class="pc-social pc-social--' . esc_attr( $provider ) . ' vc-m__inner" data-pc-social data-position="' . esc_attr( $position ) . '"';
		if ( $config ) { $html .= ' data-pc-social-config="' . esc_attr( wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) ) . '"'; }
		$html .= '><div class="pc-social__copy">' . $head . '</div>';
		if ( $account ) {
			$html .= '<div class="pc-social__media"><div class="pc-social__target" data-pc-social-target style="--pc-social-height:' . (int) ( $height ?? 500 ) . 'px"></div><div class="pc-social__actions">';
			if ( $loadable ) { $html .= '<button type="button" data-pc-social-load>' . esc_html( sprintf( '加载 %s 内容', $name ) ) . '</button><button type="button" data-pc-social-remove hidden>移除外部内容</button>'; }
			$html .= '<a href="' . esc_url( $account['url'] ) . '" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer">' . esc_html( sprintf( '前往 %s', $name ) ) . '</a></div><p class="pc-social__status" data-pc-social-status role="status" aria-live="polite">';
			$html .= esc_html( ! $active ? '此积木当前隐藏；不会连接平台。' : ( null === $height ? '内容高度无效，请检查设置。' : ( 'facebook' === $provider && ! $tabs ? '已关闭全部标签；可前往公开主页。' : sprintf( '加载后会连接 %s。', $name ) ) ) );
			$html .= '</p><noscript><p>启用 JavaScript 后可点击加载；也可直接前往公开主页。外站正文图片仅保留替代文字。</p></noscript></div>';
		} elseif ( $trusted ) { $html .= '<p class="pc-social__status">填写有效的公开主页账号后，可点击加载。不会自动向平台发送账号。</p>'; }
		return $html . '</section>';
	}
}
