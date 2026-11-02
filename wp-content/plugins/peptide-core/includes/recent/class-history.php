<?php
/** One browser cookie shared by product-page history blocks and popup history. No identity, tables or analytics writes. */
namespace PeptideCore\Recent;

use PeptideCore\Modules\Products;

defined( 'ABSPATH' ) || exit;

final class History {
	public const COOKIE = 'pc_recent_history_v1';
	private const MAX_BYTES = 4096;
	private const MAX_ITEMS = 20;
	private const TOKEN_SECONDS = 1800;
	private const WRITES_PER_MINUTE = 20;
	private const MAX_VIEWS = 1000000;
	private const COUNT_COOLDOWN = 10;

	public static function register_hooks(): void {
		add_filter( 'peptide_core_recent_history_enabled', static fn( $enabled ) => (bool) $enabled && Store::effective()['enabled'] );
	}

	private static function context(): string { return get_current_blog_id() . ':' . untrailingslashit( home_url( '/' ) ); }
	private static function signature( string $encoded, int $version ): string { return hash_hmac( 'sha256', 'pc-recent-history:v' . $version . ':' . ( $version >= 2 ? self::context() . ':' : '' ) . $encoded, wp_salt( 'auth' ) ); }
	private static function blank(): array { return array( 'v' => 3, 'iat' => time(), 'exp' => time(), 'browser' => '', 'items' => array(), 'writes' => array() ); }

	/** Legacy signed IDs migrate as hints with their original issue time, never renewed per-product age. */
	private static function read(): array {
		$raw = $_COOKIE[ self::COOKIE ] ?? '';
		if ( ! is_string( $raw ) || strlen( $raw ) > self::MAX_BYTES || ! preg_match( '/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D', $raw, $parts ) ) { return self::blank(); }
		$data = json_decode( base64_decode( strtr( $parts[1], '-_', '+/' ), true ) ?: '', true, 8 );
		if ( ! is_array( $data ) || ! in_array( $data['v'] ?? null, array( 1, 2, 3 ), true ) || ! hash_equals( self::signature( $parts[1], $data['v'] ), $parts[2] ) ) { return self::blank(); }
		$now = time();
		if ( ! is_int( $data['iat'] ?? null ) || ! is_int( $data['exp'] ?? null ) || $data['iat'] < 1 || $data['iat'] > $now + 60 || $data['exp'] < $now || $data['exp'] <= $data['iat'] || $data['exp'] > $data['iat'] + 30 * DAY_IN_SECONDS ) { return self::blank(); }
		if ( 1 === $data['v'] ) {
			if ( array_keys( $data ) !== array( 'v', 'iat', 'exp', 'ids' ) || $data['exp'] !== $data['iat'] + MONTH_IN_SECONDS || ! is_array( $data['ids'] ) || ! array_is_list( $data['ids'] ) || count( $data['ids'] ) > 100 ) { return self::blank(); }
			foreach ( $data['ids'] as $id ) { if ( ! is_int( $id ) || $id < 1 ) { return self::blank(); } }
			if ( count( array_unique( $data['ids'], SORT_NUMERIC ) ) !== count( $data['ids'] ) ) { return self::blank(); }
			$old = self::blank(); $old['browser'] = substr( hash( 'sha256', 'legacy:' . $parts[1] ), 0, 32 );
			// v1 had no per-product view timestamp; iat remains only the conservative retention boundary.
			$old['items'] = array_map( static fn( $id ) => array( 'productId' => $id, 'variationId' => 0, 'seenAt' => $data['iat'], 'viewCount' => 1, 'lastCountedAt' => null ), array_slice( $data['ids'], 0, self::MAX_ITEMS ) );
			return $old;
		}
		if ( array_keys( $data ) !== array( 'v', 'iat', 'exp', 'browser', 'items', 'writes' ) || ! is_string( $data['browser'] ) || ! preg_match( '/^[a-f0-9]{32}$/D', $data['browser'] ) || ! is_array( $data['items'] ) || ! array_is_list( $data['items'] ) || count( $data['items'] ) > self::MAX_ITEMS || ! is_array( $data['writes'] ) || ! array_is_list( $data['writes'] ) || count( $data['writes'] ) > self::WRITES_PER_MINUTE ) { return self::blank(); }
		$ids = array(); $items = array();
		foreach ( $data['items'] as $item ) {
			if ( 3 === $data['v'] ) {
				// Compact signed tuples keep twenty records plus rate metadata within the 4096-byte cookie budget.
				if ( ! is_array( $item ) || ! array_is_list( $item ) || 5 !== count( $item ) ) { return self::blank(); }
				$item = array_combine( array( 'productId', 'variationId', 'seenAt', 'viewCount', 'lastCountedAt' ), $item );
			} elseif ( ! is_array( $item ) || array_keys( $item ) !== array( 'productId', 'variationId', 'seenAt' ) ) { return self::blank(); }
			else { $item['viewCount'] = 1; $item['lastCountedAt'] = $item['seenAt']; }
			if ( ! is_int( $item['productId'] ) || $item['productId'] < 1 || $item['productId'] > 2147483647 || ! is_int( $item['variationId'] ) || $item['variationId'] < 0 || $item['variationId'] > 2147483647 || ! is_int( $item['seenAt'] ) || $item['seenAt'] < 1 || $item['seenAt'] > $now + 60 || ! is_int( $item['viewCount'] ) || $item['viewCount'] < 1 || $item['viewCount'] > self::MAX_VIEWS || ( null !== $item['lastCountedAt'] && ( ! is_int( $item['lastCountedAt'] ) || $item['lastCountedAt'] < 1 || $item['lastCountedAt'] > $item['seenAt'] ) ) || ( null === $item['lastCountedAt'] && 1 !== $item['viewCount'] ) || in_array( $item['productId'], $ids, true ) ) { return self::blank(); }
			$ids[] = $item['productId']; $items[] = $item;
		}
		foreach ( $data['writes'] as $stamp ) { if ( ! is_int( $stamp ) || $stamp < 1 || $stamp > $now + 60 ) { return self::blank(); } }
		$data['items'] = $items;
		return $data;
	}

	private static function entries( array $data, array $config ): array {
		$cutoff = time() - $config['retentionDays'] * DAY_IN_SECONDS;
		// Stable same-second order is the signed array order (most recently written first).
		$items = array_values( array_filter( $data['items'], static fn( $item ) => $item['seenAt'] >= $cutoff ) );
		usort( $items, static fn( $a, $b ) => $b['seenAt'] <=> $a['seenAt'] );
		return array_slice( $items, 0, $config['maxStored'] );
	}

	/** Preserve the existing history-block reader without accepting arbitrary client IDs. */
	public static function ids(): array {
		$config = Store::effective();
		return self::allowed() && self::enabled( $config ) ? array_column( self::entries( self::read(), $config ), 'productId' ) : array();
	}
	private static function enabled( array $config ): bool { return $config['enabled'] && (bool) apply_filters( 'peptide_core_recent_history_enabled', true ); }

	private static function write( array $data, array $config ) {
		if ( headers_sent() ) { return self::error( 'storage_unavailable', '浏览器历史暂时无法保存。', 503 ); }
		$now = time(); $data['v'] = 3; $data['iat'] = $now; $data['exp'] = $now + $config['retentionDays'] * DAY_IN_SECONDS;
		if ( '' === $data['browser'] ) { $data['browser'] = bin2hex( random_bytes( 16 ) ); }
		$data['items'] = self::entries( $data, $config );
		$data['writes'] = array_values( array_filter( $data['writes'], static fn( $stamp ) => $stamp > $now - 60 ) );
		$stored = $data;
		$stored['items'] = array_map( static fn( $item ) => array( $item['productId'], $item['variationId'], $item['seenAt'], $item['viewCount'], $item['lastCountedAt'] ), $data['items'] );
		$encoded = rtrim( strtr( base64_encode( wp_json_encode( $stored ) ), '+/', '-_' ), '=' );
		$value = $encoded . '.' . self::signature( $encoded, 3 );
		if ( strlen( $value ) > self::MAX_BYTES ) { return self::error( 'storage_limit', '浏览记录超过浏览器存储上限。', 400 ); }
		$path = wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ?: '/';
		if ( ! setcookie( self::COOKIE, $value, array( 'expires' => $data['exp'], 'path' => $path, 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) ) ) { return self::error( 'storage_unavailable', '浏览器历史暂时无法保存。', 503 ); }
		$_COOKIE[ self::COOKIE ] = $value;
		return $data;
	}

	/** A real public, visible, purchasable parent and (if supplied) its own complete, available variation. */
	private static function selection( int $id, int $variation_id = 0 ): ?array {
		$product = wc_get_product( $id );
		if ( ! $product instanceof \WC_Product ) { return null; }
		if ( $product->is_type( 'variation' ) ) {
			if ( $variation_id && $variation_id !== $id ) { return null; }
			$variation_id = $id; $product = wc_get_product( $product->get_parent_id() );
		}
		if ( ! Products::public_catalog_product( $product ) || ! $product->is_purchasable() || ! $product->is_in_stock() || ! in_array( $product->get_type(), array( 'simple', 'variable' ), true ) ) { return null; }
		$variation = null;
		if ( $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $product->is_type( 'variable' ) || ! $variation instanceof \WC_Product_Variation || 'product_variation' !== get_post_type( $variation->get_id() ) || $variation->get_parent_id() !== $product->get_id() || 'publish' !== $variation->get_status() || '' !== $variation->get_post_password() || '' !== (string) get_post_field( 'post_password', $variation->get_id(), 'raw' ) || ! $variation->variation_is_active() || ! $variation->variation_is_visible() || ! $variation->is_purchasable() || ! $variation->is_in_stock() ) { return null; }
			$attributes = $variation->get_variation_attributes();
			$required = $product->get_variation_attributes(); if ( ! $required ) { return null; }
			foreach ( $required as $name => $values ) { $value = $attributes[ wc_variation_attribute_name( $name ) ] ?? null; if ( ! is_string( $value ) || '' === trim( $value ) ) { return null; } }
			foreach ( $attributes as $value ) { if ( ! is_string( $value ) || '' === trim( $value ) ) { return null; } }
		}
		return array( $product, $variation );
	}

	/** Popup callers invoke only after actual successful load. No prefetch/impression hook is installed. */
	public static function record( int $product_id, int $variation_id = 0, string $kind = 'view' ) {
		if ( ! in_array( $kind, array( 'view', 'selection' ), true ) ) { return Config::error( 'kind', 'invalid_kind', '浏览记录类型仅支持 view 或 selection。' ); }
		$config = Store::effective();
		if ( ! self::enabled( $config ) ) { return self::error( 'disabled', '最近浏览已关闭。', 409 ); }
		if ( ! self::allowed() ) { return self::error( 'login_required', '请先登录。', 401 ); }
		$selection = self::selection( $product_id, $variation_id );
		if ( ! $selection ) { return self::error( 'product_unavailable', '该商品或规格暂不可用于最近浏览。', 404 ); }
		[ $product, $variation ] = $selection; $now = time(); $data = self::read(); $items = self::entries( $data, $config );
		$index = array_search( $product->get_id(), array_column( $items, 'productId' ), true );
		$previous = false === $index ? null : $items[ $index ];
		if ( 'selection' === $kind && null === $previous ) { return self::error( 'view_required', '请先打开商品，再更新已浏览商品的规格。', 409 ); }
		$writes = array_values( array_filter( $data['writes'], static fn( $stamp ) => $stamp > $now - 60 ) );
		if ( count( $writes ) >= self::WRITES_PER_MINUTE ) { return self::error( 'rate_limited', '浏览记录更新过快，请稍后重试。', 429 ); }
		$counted = 'view' === $kind && ( null === $previous || null === $previous['lastCountedAt'] || $now - $previous['lastCountedAt'] >= self::COUNT_COOLDOWN );
		if ( 'selection' === $kind ) { $items[ $index ]['variationId'] = $variation ? $variation->get_id() : 0; }
		else {
			$items = array_values( array_filter( $items, static fn( $item ) => $item['productId'] !== $product->get_id() ) );
			array_unshift( $items, array( 'productId' => $product->get_id(), 'variationId' => $variation ? $variation->get_id() : 0, 'seenAt' => $now, 'viewCount' => min( self::MAX_VIEWS, ( $previous['viewCount'] ?? 0 ) + ( $counted ? 1 : 0 ) ), 'lastCountedAt' => $counted ? $now : $previous['lastCountedAt'] ) );
		}
		$data['items'] = $items; $data['writes'] = array_merge( $writes, array( $now ) );
		$saved = self::write( $data, $config );
		$entry = 'selection' === $kind ? $items[ $index ] : $items[0];
		return is_wp_error( $saved ) ? $saved : array( 'recorded' => true, 'productId' => $product->get_id(), 'variationId' => $variation ? $variation->get_id() : 0, 'kind' => $kind, 'viewCount' => $entry['viewCount'], 'lastViewedAt' => null === $entry['lastCountedAt'] ? null : gmdate( 'Y-m-d\TH:i:s\Z', $entry['seenAt'] ) );
	}

	private static function allowed(): bool { return ! \PeptideCore\Store_Config::get( 'force_login' ) || is_user_logged_in(); }

	private static function money( $amount ): array {
		$decimals = wc_get_price_decimals(); $value = (float) $amount;
		if ( ! is_numeric( $amount ) || ! is_finite( $value ) || $value < 0 || $decimals < 0 || $decimals > 8 || $value * ( 10 ** $decimals ) > 9007199254740991 ) { return array( 'minor' => null, 'text' => '' ); }
		return array( 'minor' => sprintf( '%.0f', round( $value * ( 10 ** $decimals ) ) ), 'text' => html_entity_decode( wp_strip_all_tags( wc_price( $value ) ), ENT_QUOTES, 'UTF-8' ) );
	}

	private static function product_dto( array $selection ): ?array {
		[ $product, $variation ] = $selection; $priced = $variation ?: $product;
		if ( $priced->is_type( 'variable' ) ) {
			$best = null; $lowest = null;
			foreach ( $product->get_visible_children() as $child_id ) {
				$child = self::selection( $product->get_id(), (int) $child_id ); if ( ! $child ) { continue; }
				$value = wc_get_price_to_display( $child[1] ); if ( null !== self::money( $value )['minor'] && ( null === $lowest || $value < $lowest ) ) { $best = $child[1]; $lowest = $value; }
			}
			if ( ! $best ) { return null; } $priced = $best;
		}
		// Both values belong to this same eligible SKU; independent variation minima can invent a false comparison price.
		$price = wc_get_price_to_display( $priced );
		$regular = $priced->is_on_sale() && (float) $priced->get_regular_price() > (float) $priced->get_price() ? wc_get_price_to_display( $priced, array( 'price' => $priced->get_regular_price() ) ) : $price;
		$current = self::money( $price ); $original = self::money( $regular );
		if ( null === $current['minor'] || null === $original['minor'] ) { return null; }
		$image_id = ( $variation ?: $product )->get_image_id() ?: $product->get_image_id(); $url = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : false;
		return array(
			'productId' => $product->get_id(), 'name' => html_entity_decode( wp_strip_all_tags( $product->get_name() ), ENT_QUOTES, 'UTF-8' ), 'permalink' => get_permalink( $product->get_id() ),
			'image' => array( 'id' => $image_id, 'url' => $url ?: '', 'alt' => wp_strip_all_tags( $product->get_name() ) ),
			'variationId' => $variation ? $variation->get_id() : 0, 'variationLabel' => $variation ? html_entity_decode( wp_strip_all_tags( wc_get_formatted_variation( $variation, true, false, true ) ), ENT_QUOTES, 'UTF-8' ) : '',
			'price' => $current, 'regularPrice' => $original, 'currency' => get_woocommerce_currency(), 'currencyMinorUnit' => wc_get_price_decimals(), 'priceIsFrom' => ! $variation && $product->is_type( 'variable' ), 'purchasable' => true,
		);
	}

	private static function tokens( array $data ): array {
		$expiry = time() + self::TOKEN_SECONDS; $tokens = array();
		foreach ( array( 'record', 'clear' ) as $action ) { $tokens[ $action ] = $expiry . '.' . hash_hmac( 'sha256', 'pc-recent-' . $action . ':POST:' . self::context() . ':' . $data['browser'] . ':' . $expiry, wp_salt( 'nonce' ) ); }
		return array( 'writeTokens' => $tokens, 'tokenExpiresAt' => $expiry );
	}
	public static function authorize( string $token, string $action ): bool {
		if ( ! in_array( $action, array( 'record', 'clear' ), true ) || ! preg_match( '/^(\d{10})\.([a-f0-9]{64})$/D', $token, $parts ) || (int) $parts[1] < time() || (int) $parts[1] > time() + self::TOKEN_SECONDS + 60 ) { return false; }
		$data = self::read();
		return '' !== $data['browser'] && hash_equals( hash_hmac( 'sha256', 'pc-recent-' . $action . ':POST:' . self::context() . ':' . $data['browser'] . ':' . $parts[1], wp_salt( 'nonce' ) ), $parts[2] );
	}

	/** Personalized no-store DTO; prices and cart exclusions are always freshly read. */
	public static function listing( ?\WC_Cart $cart = null, bool $initialize_cart = true ) {
		if ( ! self::allowed() ) { return self::error( 'login_required', '请先登录。', 401 ); }
		$config = Store::effective(); $data = self::read();
		$enabled = self::enabled( $config );
		if ( $enabled && '' === $data['browser'] ) { $data = self::write( $data, $config ); if ( is_wp_error( $data ) ) { return $data; } }
		$out = array_merge( array( 'version' => 1, 'revision' => $config['revision'] ?? 0, 'enabled' => $enabled, 'title' => $config['title'], 'visible' => false, 'items' => array(), 'count' => 0, 'storage' => 'browser_cookie' ), '' !== $data['browser'] ? self::tokens( $data ) : array( 'writeTokens' => null, 'tokenExpiresAt' => null ) );
		if ( ! $enabled ) { return $out; }
		$entries = self::entries( $data, $config ); if ( ! $entries ) { return $out; }
		$excluded = array();
		if ( $config['excludeCartProducts'] ) {
			$cart = $cart ?? ( function_exists( 'WC' ) ? WC()->cart : null );
			if ( ! $cart instanceof \WC_Cart && $initialize_cart && function_exists( 'wc_load_cart' ) ) { wc_load_cart(); $cart = WC()->cart; }
			if ( ! $cart instanceof \WC_Cart ) { return self::error( 'cart_unavailable', '暂时无法读取购物车以过滤商品。', 503 ); }
			foreach ( $cart->get_cart() as $item ) { $product = $item['data'] ?? null; if ( $product instanceof \WC_Product ) { $excluded[] = $product->get_parent_id() ?: $product->get_id(); } elseif ( is_numeric( $item['product_id'] ?? null ) ) { $excluded[] = (int) $item['product_id']; } }
		}
		foreach ( $entries as $entry ) {
			if ( in_array( $entry['productId'], $excluded, true ) ) { continue; }
			$selection = self::selection( $entry['productId'], $entry['variationId'] );
			// A stale variation must not make an otherwise valid parent disappear or silently choose a different variation.
			if ( ! $selection && $entry['variationId'] ) { $selection = self::selection( $entry['productId'] ); }
			if ( ! $selection ) { continue; } $dto = self::product_dto( $selection ); if ( ! $dto ) { continue; }
			$dto['viewCount'] = $entry['viewCount'];
			$dto['lastViewedAt'] = null === $entry['lastCountedAt'] ? null : gmdate( 'Y-m-d\TH:i:s\Z', $entry['seenAt'] );
			$out['items'][] = $dto;
			if ( count( $out['items'] ) >= $config['maxVisible'] ) { break; }
		}
		$out['count'] = count( $out['items'] ); $out['visible'] = $out['count'] > 0;
		return $out;
	}

	/** Clear remains available when the application is disabled; the old unrelated 24-item cookie is untouched. */
	public static function clear() {
		if ( headers_sent() ) { return self::error( 'storage_unavailable', '浏览器历史暂时无法清除。', 503 ); }
		$path = wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ?: '/';
		if ( ! setcookie( self::COOKIE, '', array( 'expires' => time() - DAY_IN_SECONDS, 'path' => $path, 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) ) ) { return self::error( 'storage_unavailable', '浏览器历史暂时无法清除。', 503 ); }
		unset( $_COOKIE[ self::COOKIE ] );
		return array( 'version' => 1, 'cleared' => true, 'visible' => false, 'items' => array(), 'count' => 0, 'writeTokens' => null );
	}
	private static function error( string $reason, string $message, int $status ): \WP_Error { return new \WP_Error( 'pc_recently_viewed_' . $reason, $message, array( 'status' => $status ) ); }
}
