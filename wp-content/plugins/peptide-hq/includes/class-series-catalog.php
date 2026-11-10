<?php
/**
 * 系列主商品库（HQ-05）。一个系列一套商品和价格，存在 pchq_series_products：一行一个商品，
 * payload 是完整的商品对象（格式见 docs/CONTRACT.md “M3 系列与商品目录”），款式和价格都在里面。
 *
 * - 从母版店导入：调用母版店 GET /peptide/v1/hq/catalog-export，整体替换主商品库（没有的标记为 removed）。
 *   商品内容（描述、图片、规格）在母版店编辑，再导入一次；价格可以直接在总后台改。
 * - 任何改动都把系列的 catalog_ver 加一，推送任务（Series_Push）把同系列的店补齐；别的系列不受影响。
 *   内容完全没变时不加版本。
 * - 首次同步前的差异报告：每家店一份，存在选项里；平台所有者确认后才打开这家店的系列同步。
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Series_Catalog {

	public const EXPORT    = '/peptide/v1/hq/catalog-export';
	public const SOURCES   = 'pchq_series_sources';
	public const CONFIRMED = 'pchq_series_confirmed';

	public static function table(): string {
		return Db::table( 'series_products' );
	}

	// ---------- 纯函数 ----------

	/**
	 * 轻量校验一个商品对象，并算出列表上显示的价格（启用的款式里成交价最低的那个，和它的划线价）。
	 * 详细校验在店铺端；这里只保证有商品键、名称，可变商品至少有一个带属性的款式。无效时返回 null。
	 *
	 * @return array{sku:string,name:string,price_cents:int,compare_cents:int,variations:int}|null
	 */
	public static function summarize( $p ): ?array {
		if ( ! is_array( $p ) ) {
			return null;
		}
		$sku  = is_scalar( $p['sku'] ?? null ) ? trim( (string) $p['sku'] ) : '';
		$name = is_scalar( $p['name'] ?? null ) ? trim( (string) $p['name'] ) : '';
		if ( '' === $sku || strlen( $sku ) > 100 || '' === $name ) {
			return null;
		}
		$price   = (int) ( $p['price_cents'] ?? 0 );
		$compare = (int) ( $p['compare_cents'] ?? 0 );
		$count   = 0;
		if ( 'variable' === ( $p['type'] ?? '' ) ) {
			$best = null;
			foreach ( (array) ( $p['variations'] ?? array() ) as $v ) {
				if ( ! is_array( $v ) || ! is_array( $v['attributes'] ?? null ) || ! $v['attributes'] || (int) ( $v['price_cents'] ?? -1 ) < 0 ) {
					return null;
				}
				++$count;
				$enabled = ! array_key_exists( 'enabled', $v ) || ! empty( $v['enabled'] );
				if ( $enabled && ( null === $best || (int) $v['price_cents'] < (int) $best['price_cents'] ) ) {
					$best = $v;
				}
			}
			if ( 0 === $count ) {
				return null;
			}
			$price   = $best ? (int) $best['price_cents'] : 0;
			$compare = $best ? (int) ( $best['compare_cents'] ?? 0 ) : 0;
		}
		return array(
			'sku'           => $sku,
			'name'          => function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 255 ) : substr( $name, 0, 255 ),
			'price_cents'   => max( 0, $price ),
			'compare_cents' => max( 0, $compare ),
			'variations'    => $count,
		);
	}

	/** 款式的键：key，没有就用 SKU。 */
	public static function variation_key( array $v ): string {
		$key = is_scalar( $v['key'] ?? null ) ? trim( (string) $v['key'] ) : '';
		return '' !== $key ? $key : ( is_scalar( $v['sku'] ?? null ) ? trim( (string) $v['sku'] ) : '' );
	}

	/**
	 * 改价（纯函数）。$prices：款式键 => [price_cents, compare_cents]；简单商品用空字符串作键。
	 * 改过价的商品或款式去掉 _pc_pricing（那是母版店记录的旧对照价，已经不适用）。
	 *
	 * @return array{0:array,1:bool} 新的商品对象，以及有没有变化
	 */
	public static function with_prices( array $payload, array $prices ): array {
		$changed = false;
		$apply   = static function ( array $item, array $new ) use ( &$changed ): array {
			$price   = max( 0, (int) ( $new['price_cents'] ?? 0 ) );
			$compare = max( 0, (int) ( $new['compare_cents'] ?? 0 ) );
			$compare = $compare > $price ? $compare : 0;
			if ( (int) ( $item['price_cents'] ?? 0 ) !== $price || (int) ( $item['compare_cents'] ?? 0 ) !== $compare ) {
				$item['price_cents']   = $price;
				$item['compare_cents'] = $compare;
				if ( is_array( $item['meta'] ?? null ) ) {
					unset( $item['meta']['_pc_pricing'] );
				}
				$changed = true;
			}
			return $item;
		};
		if ( 'variable' === ( $payload['type'] ?? '' ) ) {
			foreach ( (array) ( $payload['variations'] ?? array() ) as $i => $v ) {
				$key = is_array( $v ) ? self::variation_key( $v ) : '';
				if ( '' !== $key && isset( $prices[ $key ] ) && is_array( $prices[ $key ] ) ) {
					$payload['variations'][ $i ] = $apply( $v, $prices[ $key ] );
				}
			}
		} elseif ( isset( $prices[''] ) && is_array( $prices[''] ) ) {
			$payload = $apply( $payload, $prices[''] );
		}
		return array( $payload, $changed );
	}

	// ---------- 读取 ----------

	/**
	 * 系列的商品，按导入时的顺序。每个：id、sku、name、price_cents、compare_cents、status、position、ver、payload（数组）、json（原文）。
	 *
	 * @return array<string,array> 商品键 => 商品
	 */
	public static function products( int $series_id, bool $with_removed = false ): array {
		global $wpdb;
		if ( ! Series::ready() ) {
			return array();
		}
		$t   = self::table();
		$out = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE series_id = %d ORDER BY position ASC, id ASC", $series_id ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
			if ( ! $with_removed && 'active' !== $r['status'] ) {
				continue;
			}
			$payload                    = json_decode( (string) $r['payload'], true );
			$out[ (string) $r['sku'] ] = array(
				'id'            => (int) $r['id'],
				'sku'           => (string) $r['sku'],
				'name'          => (string) $r['name'],
				'price_cents'   => (int) $r['price_cents'],
				'compare_cents' => (int) $r['compare_cents'],
				'status'        => (string) $r['status'],
				'position'      => (int) $r['position'],
				'ver'           => (int) $r['ver'],
				'payload'       => is_array( $payload ) ? $payload : array(),
				'json'          => (string) $r['payload'],
			);
		}
		return $out;
	}

	/** 图片从哪个域名下载：导入时母版店报的域名。 */
	public static function source_host( int $series_id ): string {
		$all = get_option( self::SOURCES, array() );
		return is_array( $all ) && is_string( $all[ $series_id ] ?? null ) ? $all[ $series_id ] : '';
	}

	/**
	 * /hq/series/sync 的 catalog 部分：版本号、图片来源域名、在用的商品。
	 *
	 * @return array{ver:int,source_host:string,products:array}
	 */
	public static function payload( int $series_id ): array {
		$series = Series::get( $series_id );
		return array(
			'ver'         => $series ? $series['catalog_ver'] : 0,
			'source_host' => self::source_host( $series_id ),
			'products'    => array_values( array_column( self::products( $series_id ), 'payload' ) ),
		);
	}

	// ---------- 写入 ----------

	/**
	 * 用一批商品对象整体替换系列主商品库：新的插入，已有的更新，不在这批里的标记为 removed（不删行）。
	 * 有一个商品无效就整体拒绝。内容完全没变时不加版本号。
	 *
	 * @return array{ver:int,added:int,updated:int,removed:int,same:int,changed:bool}|\WP_Error
	 */
	public static function replace( int $series_id, array $products, string $source_host = '' ) {
		global $wpdb;
		$series = Series::get( $series_id );
		if ( ! $series ) {
			return new \WP_Error( 'pchq_series_missing', '系列不存在。' );
		}
		$clean = array();
		foreach ( array_values( $products ) as $i => $p ) {
			$s = self::summarize( $p );
			if ( null === $s ) {
				return new \WP_Error( 'pchq_catalog_product', sprintf( '第 %d 个商品无效（缺少商品键、名称，或者款式不完整），没有导入。', $i + 1 ) );
			}
			if ( isset( $clean[ $s['sku'] ] ) ) {
				return new \WP_Error( 'pchq_catalog_product', sprintf( '商品键 %s 重复，没有导入。', $s['sku'] ) );
			}
			$clean[ $s['sku'] ] = array( $s, $p, $i );
		}
		if ( ! $clean ) {
			return new \WP_Error( 'pchq_catalog_empty', '没有可导入的商品（母版店没有已发布的商品）。' );
		}
		$t        = self::table();
		$existing = self::products( $series_id, true );
		$next     = $series['catalog_ver'] + 1;
		$out      = array( 'ver' => $series['catalog_ver'], 'added' => 0, 'updated' => 0, 'removed' => 0, 'same' => 0, 'changed' => false );
		foreach ( $clean as $sku => [ $s, $p, $i ] ) {
			$json = (string) wp_json_encode( $p );
			$old  = $existing[ $sku ] ?? null;
			if ( $old && 'active' === $old['status'] && $old['json'] === $json && $old['position'] === $i ) {
				++$out['same'];
				continue;
			}
			$row = array(
				'series_id'     => $series_id,
				'sku'           => (string) $sku,
				'name'          => $s['name'],
				'payload'       => $json,
				'price_cents'   => $s['price_cents'],
				'compare_cents' => $s['compare_cents'],
				'status'        => 'active',
				'position'      => $i,
				'ver'           => $next,
				'updated_at'    => time(),
			);
			if ( $old ) {
				$wpdb->update( $t, $row, array( 'id' => $old['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				++$out['updated'];
			} else {
				$wpdb->insert( $t, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				++$out['added'];
			}
		}
		foreach ( $existing as $sku => $old ) {
			if ( ! isset( $clean[ $sku ] ) && 'active' === $old['status'] ) {
				$wpdb->update( $t, array( 'status' => 'removed', 'ver' => $next, 'updated_at' => time() ), array( 'id' => $old['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				++$out['removed'];
			}
		}
		$out['changed'] = $out['added'] + $out['updated'] + $out['removed'] > 0;
		if ( '' !== $source_host && preg_match( '/^[a-z0-9.-]{3,253}$/D', $source_host ) ) {
			$all                 = get_option( self::SOURCES, array() );
			$all                 = is_array( $all ) ? $all : array();
			$all[ $series_id ] = $source_host;
			update_option( self::SOURCES, $all, false );
		}
		if ( $out['changed'] ) {
			$out['ver'] = Series::bump( $series_id, 'catalog' );
		}
		return $out;
	}

	/**
	 * 从母版店导入。$parts 可以包含 products（商品）、shipping（运费）、campaigns（活动）。
	 * 导入后这家店记为系列的母版店，并给系列里的店排一次同步。
	 *
	 * @param string[] $parts
	 * @return array{products:?array,shipping:?int,campaigns:?int}|\WP_Error
	 */
	public static function import_from_store( int $series_id, int $site_id, array $parts = array( 'products' ) ) {
		$series = Series::get( $series_id );
		if ( ! $series || ! isset( Series::sites_of( $series_id )[ $site_id ] ) ) {
			return new \WP_Error( 'pchq_catalog_master', '母版店必须是这个系列里的店铺。' );
		}
		$res = Client::request( $site_id, 'GET', self::EXPORT, array(), null, 280 );
		if ( is_wp_error( $res ) ) {
			return Series_Push::is_unsupported( $res ) ? new \WP_Error( 'pchq_series_old', '母版店的程序还不支持系列导出，请先更新店铺程序。' ) : new \WP_Error( $res->get_error_code(), '读取母版店失败：' . Design_Push::friendly( $res ) );
		}
		if ( 1 !== (int) ( $res['version'] ?? 0 ) || ! is_array( $res['products'] ?? null ) ) {
			return new \WP_Error( 'pchq_catalog_reply', '母版店返回的内容无效。' );
		}
		$out = array( 'products' => null, 'shipping' => null, 'campaigns' => null );
		if ( in_array( 'products', $parts, true ) ) {
			$done = self::replace( $series_id, $res['products'], strtolower( (string) ( $res['source_host'] ?? '' ) ) );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
			$out['products'] = $done;
		}
		if ( in_array( 'shipping', $parts, true ) ) {
			$ver = Series::save_shipping( $series_id, (array) ( $res['shipping'] ?? array() ) );
			if ( is_wp_error( $ver ) ) {
				return new \WP_Error( 'pchq_series_shipping', '母版店没有可用的通用运费方案，运费没有导入。' );
			}
			$out['shipping'] = $ver;
		}
		if ( in_array( 'campaigns', $parts, true ) ) {
			$ver = Series_Campaigns::replace( $series_id, (array) ( $res['campaigns'] ?? array() ) );
			if ( is_wp_error( $ver ) ) {
				return $ver;
			}
			$out['campaigns'] = $ver;
		}
		Series::update( $series_id, array( 'master_site_id' => $site_id ) );
		Series_Push::queue_series( $series_id );
		return $out;
	}

	/**
	 * 改一个商品的价格（$prices 见 with_prices）。价格没变时不加版本。
	 *
	 * @return int|\WP_Error 系列的商品版本号
	 */
	public static function set_prices( int $series_id, string $sku, array $prices ) {
		global $wpdb;
		$series  = Series::get( $series_id );
		$product = self::products( $series_id )[ $sku ] ?? null;
		if ( ! $series || ! $product ) {
			return new \WP_Error( 'pchq_catalog_missing', '这个系列里没有这个商品。' );
		}
		[ $payload, $changed ] = self::with_prices( $product['payload'], $prices );
		$summary               = self::summarize( $payload );
		if ( null === $summary ) {
			return new \WP_Error( 'pchq_catalog_product', '改价后的商品无效，没有保存。' );
		}
		if ( ! $changed ) {
			return $series['catalog_ver'];
		}
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'payload'       => (string) wp_json_encode( $payload ),
				'price_cents'   => $summary['price_cents'],
				'compare_cents' => $summary['compare_cents'],
				'ver'           => $series['catalog_ver'] + 1,
				'updated_at'    => time(),
			),
			array( 'id' => $product['id'] )
		);
		$ver = Series::bump( $series_id, 'catalog' );
		Series_Push::queue_series( $series_id );
		return $ver;
	}

	/**
	 * 把商品移出系列（各店同步后下架，不删除）或者放回来。
	 *
	 * @return int|\WP_Error 系列的商品版本号
	 */
	public static function set_status( int $series_id, string $sku, bool $active ) {
		global $wpdb;
		$series  = Series::get( $series_id );
		$product = self::products( $series_id, true )[ $sku ] ?? null;
		if ( ! $series || ! $product ) {
			return new \WP_Error( 'pchq_catalog_missing', '这个系列里没有这个商品。' );
		}
		$status = $active ? 'active' : 'removed';
		if ( $product['status'] === $status ) {
			return $series['catalog_ver'];
		}
		if ( ! $active && count( self::products( $series_id ) ) < 2 ) {
			return new \WP_Error( 'pchq_catalog_last', '系列里至少要留一个商品。' );
		}
		$wpdb->update( self::table(), array( 'status' => $status, 'ver' => $series['catalog_ver'] + 1, 'updated_at' => time() ), array( 'id' => $product['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ver = Series::bump( $series_id, 'catalog' );
		Series_Push::queue_series( $series_id );
		return $ver;
	}

	// ---------- 差异报告与确认 ----------

	private static function diff_option( int $series_id, int $site_id ): string {
		return 'pchq_series_diff_' . $series_id . '_' . $site_id;
	}

	/** 保存店铺返回的差异报告（每家店只留最近一份）。 */
	public static function save_diff( int $series_id, int $site_id, int $ver, array $diff ): array {
		$summary = (array) ( $diff['summary'] ?? array() );
		$report  = array(
			'series_id'   => $series_id,
			'site_id'     => $site_id,
			'ver'         => $ver,
			'at'          => time(),
			'prices_only' => ! empty( $diff['prices_only'] ),
			'summary'     => array(
				'same'      => (int) ( $summary['same'] ?? 0 ),
				'changed'   => (int) ( $summary['changed'] ?? 0 ),
				'create'    => (int) ( $summary['create'] ?? 0 ),
				'unpublish' => (int) ( $summary['unpublish'] ?? 0 ),
			),
			'create'      => array_slice( array_values( array_filter( (array) ( $diff['create'] ?? array() ), 'is_array' ) ), 0, 500 ),
			'unpublish'   => array_slice( array_values( array_filter( (array) ( $diff['unpublish'] ?? array() ), 'is_array' ) ), 0, 500 ),
			'changed'     => array_slice( array_values( array_filter( (array) ( $diff['changed'] ?? array() ), 'is_array' ) ), 0, 500 ),
			'shipping'    => is_array( $diff['shipping'] ?? null ) ? array( 'changed' => ! empty( $diff['shipping']['changed'] ) ) : null,
		);
		update_option( self::diff_option( $series_id, $site_id ), $report, false );
		return $report;
	}

	public static function diff( int $series_id, int $site_id ): ?array {
		$r = get_option( self::diff_option( $series_id, $site_id ), null );
		return is_array( $r ) && isset( $r['summary'], $r['ver'] ) ? $r : null;
	}

	private static function confirmations(): array {
		$all = get_option( self::CONFIRMED, array() );
		return is_array( $all ) ? $all : array();
	}

	/** 这家店（在这个系列里）的差异报告是否已经由平台所有者确认过。换系列后要重新确认。 */
	public static function confirmed( int $series_id, int $site_id ): bool {
		return isset( self::confirmations()[ $series_id . ':' . $site_id ] );
	}

	/** 确认记录：ver（确认时看的商品版本）、by、at；没确认过时为 null。 */
	public static function confirmation( int $series_id, int $site_id ): ?array {
		$c = self::confirmations()[ $series_id . ':' . $site_id ] ?? null;
		return is_array( $c ) ? $c : null;
	}

	/**
	 * 平台所有者看过差异报告后确认：之后这家店接受系列同步。报告必须是针对当前商品版本的，过期的要重新生成。
	 *
	 * @return true|\WP_Error
	 */
	public static function confirm( int $series_id, int $site_id, int $user_id ) {
		$series = Series::get( $series_id );
		$report = self::diff( $series_id, $site_id );
		if ( ! $series || Series::site( $site_id )['series_id'] !== $series_id ) {
			return new \WP_Error( 'pchq_series_assign', '这家店不在这个系列里。' );
		}
		if ( ! $report ) {
			return new \WP_Error( 'pchq_series_diff', '还没有这家店的差异报告，请先生成并查看。' );
		}
		if ( $report['ver'] !== $series['catalog_ver'] ) {
			return new \WP_Error( 'pchq_series_diff', '差异报告是针对旧版本商品的（主商品库之后又改过），请重新生成后再确认。' );
		}
		$all                                    = self::confirmations();
		$all[ $series_id . ':' . $site_id ] = array( 'ver' => $report['ver'], 'by' => $user_id, 'at' => time() );
		update_option( self::CONFIRMED, $all, false );
		Series::set_sync( $site_id, true );
		return true;
	}

	/** 撤回确认并关闭这家店的系列同步（已经同步过去的内容不动）。 */
	public static function unconfirm( int $series_id, int $site_id ): void {
		$all = self::confirmations();
		unset( $all[ $series_id . ':' . $site_id ] );
		update_option( self::CONFIRMED, $all, false );
		Series::set_sync( $site_id, false );
	}
}
