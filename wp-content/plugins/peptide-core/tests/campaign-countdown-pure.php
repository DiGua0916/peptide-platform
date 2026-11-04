<?php
/** Native Promotions + announcement + countdown, isolated WP boundaries, no bootstrap/DB. */
declare( strict_types=1 );
namespace {
	if ( 'cli' !== PHP_SAPI ) { exit( 2 ); }
	define( 'ABSPATH', __DIR__ ); define( 'PEPTIDE_CORE_DIR', dirname( __DIR__ ) ); define( 'PEPTIDE_CORE_FILE', dirname( __DIR__ ) . '/peptide-core.php' );
	$GLOBALS['cc_checks'] = 0; $GLOBALS['cc_writes'] = 0; $GLOBALS['cc_options'] = array(); $GLOBALS['cc_posts'] = array(); $GLOBALS['cc_routes'] = array(); $GLOBALS['cc_assets'] = array();
	function cc_check( string $name, bool $okay ): void { if ( ! $okay ) { throw new \RuntimeException( 'FAIL: ' . $name ); } ++$GLOBALS['cc_checks']; }
	function get_option( $name, $default = false ) { return $GLOBALS['cc_options'][ $name ] ?? $default; }
	function get_post_type( $id ) { return $GLOBALS['cc_posts'][ $id ]['type'] ?? false; }
	function get_post_status( $id ) { return $GLOBALS['cc_posts'][ $id ]['status'] ?? false; }
	function get_post_field( $key, $id ) { return $GLOBALS['cc_posts'][ $id ][ $key ] ?? ''; }
	function get_post_meta( $id, $key, $single = false ) { cc_check( 'native promo meta only', \PeptideCore\Promotions::META === $key ); return $GLOBALS['cc_posts'][ $id ]['config'] ?? null; }
	function get_the_title( $id ) { return $GLOBALS['cc_posts'][ $id ]['title'] ?? ''; }
	function get_posts( $args ) { cc_check( 'bounded published candidates', 300 === $args['posts_per_page'] && 'publish' === $args['post_status'] && 'ids' === $args['fields'] ); return array_keys( array_filter( $GLOBALS['cc_posts'], static fn( $p ) => 'publish' === $p['status'] ) ); }
	function __( $text, $domain = '' ) { return $text; }
	function wp_strip_all_tags( $text ) { return strip_tags( $text ); }
	function wp_kses_post( $text ) { return strip_tags( $text, '<strong><span>' ); }
	function wc_price( $value ) { return '$' . number_format( $value, 2, '.', ',' ); }
	function wc_format_localized_decimal( $value ) { return (string) $value; }
	function wp_date( $format, $time ) { return gmdate( $format ?: 'c', $time ); }
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
	function esc_attr( $s ) { return esc_html( $s ); }
	function esc_url_raw( $s ) { return $s; }
	function esc_url( $s ) { return esc_html( $s ); }
	function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
	function rest_url( $s ) { return 'https://store.example.test/wp-json/' . $s; }
	function plugins_url( $s, $file ) { return 'https://store.example.test/plugins/peptide-core/' . $s; }
	function wp_enqueue_style( ...$args ) { $GLOBALS['cc_assets'][] = $args; }
	function wp_enqueue_script( ...$args ) { $GLOBALS['cc_assets'][] = $args; }
	function add_action( ...$args ) {}
	function register_rest_route( ...$args ) { $GLOBALS['cc_routes'][] = $args; }
	function update_option( ...$args ) {
		if ( ! empty( $GLOBALS['cc_allow_save'] ) && \PeptideCore\Campaign_Countdown::OPTION === $args[0] ) { $GLOBALS['cc_options'][ $args[0] ] = $args[1]; ++$GLOBALS['cc_simulated_saves']; return true; }
		++$GLOBALS['cc_writes']; throw new \RuntimeException( 'Forbidden read mutation' );
	}
	function wp_insert_post( ...$args ) { ++$GLOBALS['cc_writes']; throw new \RuntimeException( 'Forbidden post write' ); }
	class WP_REST_Response { public array $headers = array(); public function __construct( public $data ) {} public function header( $k, $v ) { $this->headers[ $k ] = $v; } }
	require dirname( __DIR__ ) . '/includes/autoload.php';
	use PeptideCore\Campaign_Countdown as C;
	$now = time();
	$base = array( 'title' => 'Black Friday <b>Actual</b>', 'type' => 'pc_promotion', 'status' => 'publish', 'post_password' => '', 'config' => array( 'public' => true, 'start' => $now - 60, 'end' => $now + 3600, 'tiers' => array( array( 'min' => 300.0, 'value' => 25.0 ) ) ) );
	$GLOBALS['cc_posts'][37] = $base;
	$config = array_replace( C::defaults(), array( 'enabled' => true, 'promotionId' => 37 ) );
	cc_check( 'unconfigured default disabled', ! C::for_position( 'checkout' )['visible'] );
	cc_check( 'no writes while reading defaults', 0 === $GLOBALS['cc_writes'] );
	foreach ( array( null, false, 'enabled', array( true ), array( 'enabled' => 1 ), array( 'version' => '1' ), array( 'promotionId' => '37' ), array( 'promotionId' => -1 ), array( 'end' => $now + 10 ), array( 'checkout' => 'false' ), array( 'product' => null ) ) as $input ) { cc_check( 'reject malformed config', null === C::normalize( $input ) ); }
	$dto = C::for_position( 'checkout', $config, $now );
	cc_check( 'native active binding', $dto['visible'] && 'active' === $dto['state'] && 37 === $dto['promotionId'] );
	cc_check( 'UTC integer deadline', $dto['ends'] === $now + 3600 && $dto['target'] === $dto['ends'] && $dto['serverNow'] === $now );
	cc_check( 'real tier untouched', 300.0 === $dto['tiers'][0]['min'] && 25.0 === $dto['tiers'][0]['value'] );
	cc_check( 'source title sanitized', 'Black Friday Actual' === $dto['title'] );
	// Use the actual request-scoped locale getter; no bootstrap, locale switch or DB write.
	$language_state = new \ReflectionProperty( \PeptideCore\Storefront_Translation::class, 'state' );
	$original_language_state = $language_state->getValue();
	foreach ( array( 'en' => 'Black Friday', 'es' => 'Viernes Negro' ) as $language => $customer_title ) {
		$language_state->setValue( null, array_replace( $original_language_state, array( 'language' => $language, 'locale' => 'es' === $language ? 'es_ES' : 'en_US', 'active' => true ) ) );
		foreach ( array( '黑色星期五', '黑五' ) as $native_title ) {
			$GLOBALS['cc_posts'][37]['title'] = $native_title;
			foreach ( C::POSITIONS as $position ) { cc_check( 'known customer title follows actual storefront locale', $customer_title === C::for_position( $position, $config, $now )['title'] ); }
			cc_check( 'admin candidate title stays native for both locales', $native_title === C::candidates()[0]['title'] );
			cc_check( 'title mapping leaves native record untouched', $native_title === $GLOBALS['cc_posts'][37]['title'] );
		}
		foreach ( array( 'My Black Friday Special', '自定义黑五活动', 'Viernes Negro' ) as $custom_title ) {
			$GLOBALS['cc_posts'][37]['title'] = $custom_title;
			cc_check( 'unknown custom title is never rewritten', $custom_title === C::for_position( 'checkout', $config, $now )['title'] );
		}
	}
	$language_state->setValue( null, $original_language_state );
	$GLOBALS['cc_posts'][37] = $base;
	cc_check( 'two placements share identity and deadline', C::for_position( 'product', $config, $now )['ends'] === $dto['ends'] );
	cc_check( 'invalid position denied', ! C::for_position( 'admin', $config, $now )['visible'] );
	cc_check( 'position off', ! C::for_position( 'checkout', array_replace( $config, array( 'checkout' => false ) ), $now )['visible'] );
	cc_check( 'master off', ! C::for_position( 'checkout', array_replace( $config, array( 'enabled' => false ) ), $now )['visible'] );
	cc_check( 'no automatic first selection', 'unconfigured' === C::for_position( 'checkout', array_replace( $config, array( 'promotionId' => 0 ) ), $now )['state'] );
	cc_check( 'refresh never resets deadline', C::for_position( 'checkout', $config, $now + 120 )['target'] === $dto['target'] );
	cc_check( 'boundary exactly ended', ! C::for_position( 'checkout', $config, $now + 3600 )['visible'] && 'ended' === C::for_position( 'checkout', $config, $now + 3600 )['state'] );
	$GLOBALS['cc_posts'][37]['config']['start'] = $now + 300;
	$upcoming = C::for_position( 'product', $config, $now );
	cc_check( 'upcoming uses actual start', $upcoming['visible'] && 'upcoming' === $upcoming['state'] && $now + 300 === $upcoming['target'] );
	cc_check( 'start boundary switches to deadline', 'active' === C::for_position( 'checkout', $config, $now + 300 )['state'] );
	$GLOBALS['cc_posts'][37]['config']['end'] = 0;
	$untimed = C::for_position( 'checkout', $config, $now );
	cc_check( 'no invented fallback deadline', ! $untimed['visible'] && 'untimed' === $untimed['state'] && null === $untimed['target'] );
	foreach ( array( 'draft', 'private', 'trash' ) as $status ) {
		$GLOBALS['cc_posts'][37] = array_replace( $base, array( 'status' => $status ) ); $denied = C::for_position( 'checkout', $config, $now );
		cc_check( 'nonpublic status no leak', ! $denied['visible'] && '' === $denied['title'] && null === $denied['promotionId'] && array() === $denied['tiers'] );
	}
	foreach ( array( array( 'post_password' => 'private' ), array( 'type' => 'product' ), array( 'config' => array_replace( $base['config'], array( 'public' => false ) ) ), array( 'config' => array_replace( $base['config'], array( 'tiers' => array() ) ) ), array( 'config' => array_replace( $base['config'], array( 'end' => '1790000000' ) ) ), array( 'config' => array_replace( $base['config'], array( 'end' => $now - 120 ) ) ) ) as $change ) {
		$GLOBALS['cc_posts'][37] = array_replace( $base, $change ); $denied = C::for_position( 'product', $config, $now );
		cc_check( 'unavailable source hidden', ! $denied['visible'] && '' === $denied['title'] );
	}
	foreach ( array( array( array( 'min' => 100 ) ), array( array( 'min' => 100, 'value' => INF ) ), array( array( 'min' => -1, 'value' => 10 ) ), array_fill( 0, 6, array( 'min' => 100, 'value' => 10 ) ), 'bad' ) as $tiers ) {
		$GLOBALS['cc_posts'][37] = array_replace( $base, array( 'config' => array_replace( $base['config'], array( 'tiers' => $tiers ) ) ) );
		$denied = C::for_position( 'checkout', $config, $now );
		cc_check( 'corrupt native tier shape fails closed before view', ! $denied['visible'] && array() === $denied['tiers'] && '' === $denied['title'] );
	}
	$GLOBALS['cc_posts'][37] = $base;
	$GLOBALS['cc_posts'][38] = array_replace( $base, array( 'post_password' => 'secret', 'title' => 'Must never leak' ) );
	$rows = C::candidates();
	cc_check( 'candidate list only eligible public source', 1 === count( $rows ) && 37 === $rows[0]['id'] && true === $rows[0]['hasDeadline'] );
	$GLOBALS['cc_options'][C::OPTION] = $config;
	$response = C::response();
	cc_check( 'endpoint read both positions', $response->data['checkout']['promotionId'] === $response->data['product']['promotionId'] );
	cc_check( 'private no-store response', str_contains( $response->headers['Cache-Control'], 'no-store' ) && 'Cookie' === $response->headers['Vary'] );
	C::register_routes(); cc_check( 'anonymous GET only', 'GET' === $GLOBALS['cc_routes'][0][2]['methods'] && '__return_true' === $GLOBALS['cc_routes'][0][2]['permission_callback'] );
	$html = C::render( 'checkout', $dto );
	cc_check( 'escaped mount config actual source', str_contains( $html, 'data-pc-campaign-countdown="checkout"' ) && str_contains( $html, 'data-countdown-endpoint=' ) && ! str_contains( $html, '<b>' ) );
	cc_check( 'no fake prehydration timer', str_contains( $html, ' hidden>' ) && ! str_contains( $html, '23:59' ) );
	cc_check( 'two scoped assets', 2 === count( $GLOBALS['cc_assets'] ) );
	cc_check( 'all readers mutation-free', 0 === $GLOBALS['cc_writes'] );
	// The real admin POST handler is tested with an in-memory option sink and redirect boundary.
	class SyntheticCountdownStaff { public static function is_staff( $id ) { return $GLOBALS['cc_staff'] ?? false; } public static function granted( $id ) { return $GLOBALS['cc_grants'] ?? array(); } }
	class_alias( SyntheticCountdownStaff::class, 'PeptideCore\\Staff' );
	function is_user_logged_in() { return $GLOBALS['cc_logged'] ?? true; }
	function get_current_user_id() { return 1; }
	function current_user_can( $cap ) { return $GLOBALS['cc_caps'][ $cap ] ?? true; }
	function check_admin_referer( $action ) { cc_check( 'nonce action matches', \PeptideCore\Admin\Campaign_Countdown_Page::ACTION === $action ); if ( empty( $GLOBALS['cc_nonce'] ) ) { throw new \RuntimeException( 'nonce_denied' ); } }
	function wp_die( ...$args ) { throw new \RuntimeException( 'permission_denied' ); }
	function wp_safe_redirect( $url ) { throw new \RuntimeException( 'redirect:' . $url ); }
	function admin_url( $path ) { return 'https://store.test/wp-admin/' . $path; }
	function add_query_arg( $a, $b, $c = null ) { $args = is_array( $a ) ? $a : array( $a => $b ); $url = is_array( $a ) ? $b : $c; return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args ); }
	function checked( $a, $b, $echo = true ) { return $a === $b ? ' checked' : ''; }
	function selected( $a, $b, $echo = true ) { return $a === $b ? ' selected' : ''; }
	function wp_nonce_field( $action ) { echo '<input name="_wpnonce" value="synthetic">'; }
	function submit_button( $label ) { echo '<p class="submit"><button>' . esc_html( $label ) . '</button></p>'; }
	function cc_save( array $post ): string { $_POST = $post; try { \PeptideCore\Admin\Campaign_Countdown_Page::save(); } catch ( \RuntimeException $stop ) { return $stop->getMessage(); } return 'missing_redirect'; }
	$GLOBALS['cc_allow_save'] = true; $GLOBALS['cc_simulated_saves'] = 0; $GLOBALS['cc_nonce'] = true;
	$valid_post = array( 'enabled' => '1', 'promotionId' => '37', 'checkout' => '1', 'product' => '1' );
	$GLOBALS['cc_logged'] = false; cc_check( 'anonymous admin save denied', 'permission_denied' === cc_save( $valid_post ) ); $GLOBALS['cc_logged'] = true;
	$GLOBALS['cc_caps']['manage_woocommerce'] = false; cc_check( 'nonmanager save denied', 'permission_denied' === cc_save( $valid_post ) ); $GLOBALS['cc_caps']['manage_woocommerce'] = true;
	$GLOBALS['cc_staff'] = true; cc_check( 'staff without apps grant denied', 'permission_denied' === cc_save( $valid_post ) );
	ob_start(); \PeptideCore\Admin\Campaign_Countdown_Page::render(); $forbidden_html = ob_get_clean(); cc_check( 'forbidden render has no source or form', ! str_contains( $forbidden_html, '<form' ) && ! str_contains( $forbidden_html, 'Actual' ) );
	$GLOBALS['cc_grants'] = array( 'apps' ); $GLOBALS['cc_nonce'] = false; cc_check( 'CSRF rejected before save', 'nonce_denied' === cc_save( $valid_post ) ); $GLOBALS['cc_nonce'] = true;
	foreach ( array( array( 'promotionId' => array( '37' ) ), array( 'promotionId' => '37abc' ), array( 'enabled' => 'true' ), array( 'checkout' => array( '1' ) ), array( 'promotionId' => '2147483648' ) ) as $bad ) { cc_check( 'malformed POST blocked', str_contains( cc_save( array_replace( $valid_post, $bad ) ), 'countdown_notice=invalid' ) ); }
	cc_check( 'no invalid admin mutation', 0 === $GLOBALS['cc_simulated_saves'] );
	$GLOBALS['cc_posts'][37]['config']['end'] = 0;
	cc_check( 'explicit valid settings saved through native handler', str_contains( cc_save( $valid_post ), 'countdown_notice=saved' ) && 1 === $GLOBALS['cc_simulated_saves'] );
	cc_check( 'save leaves native promotion untimed', 0 === $GLOBALS['cc_posts'][37]['config']['end'] && 'untimed' === C::for_position( 'checkout' )['state'] );
	ob_start(); \PeptideCore\Admin\Campaign_Countdown_Page::render(); $admin_html = ob_get_clean();
	cc_check( 'missing deadline honest recovery link', str_contains( $admin_html, '未设置截止时间' ) && str_contains( $admin_html, 'pc-promotions' ) && str_contains( $admin_html, 'edit=37' ) );
	cc_check( 'admin preserves real promo identity in selector', str_contains( $admin_html, 'value="37" selected' ) && ! str_contains( $admin_html, 'Must never leak' ) );
	$GLOBALS['cc_posts'][37]['status'] = 'draft'; cc_check( 'cannot enable draft', str_contains( cc_save( $valid_post ), 'countdown_notice=selection' ) );
	cc_check( 'disable unavailable binding still possible', str_contains( cc_save( array( 'promotionId' => '37' ) ), 'countdown_notice=saved' ) && ! C::settings()['enabled'] );
	cc_check( 'only independent option sink used', 0 === $GLOBALS['cc_writes'] && 2 === $GLOBALS['cc_simulated_saves'] );
	echo json_encode( array( 'passed' => $GLOBALS['cc_checks'], 'actualDbWrites' => 0, 'publicReadMutations' => $GLOBALS['cc_writes'], 'simulatedAdminOptionSaves' => $GLOBALS['cc_simulated_saves'], 'nativePromotionClass' => true, 'nativeAnnouncementClass' => true, 'actualAdminPostHandler' => true ), JSON_PRETTY_PRINT ) . PHP_EOL;
}
