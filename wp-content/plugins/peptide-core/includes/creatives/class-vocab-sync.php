<?php
/**
 * 素材属性词表同步（线 2）：从总后台 GET /peptide-hq/v1/creatives/vocab 拉取，版本号比本地大时整体覆盖
 * pc_ad_attribute_vocab（本地版本 = 表里最大的 ver，初版是 0）。
 *
 * - 每天一次定时任务；打开素材库页面时，距上次尝试超过 24 小时也同步一次。
 * - 没接总后台、拉取失败、或总后台给的词表不完整（必填属性缺取值）时，继续用本地词表，不报错。
 * - 已登记素材用到、但新词表里没有的取值不会被改掉，页面上原样显示。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Creatives;

defined( 'ABSPATH' ) || exit;

final class Vocab_Sync {

	public const ROUTE = '/peptide-hq/v1/creatives/vocab';
	public const CRON  = 'pc_creatives_vocab_sync';
	public const STATE = 'peptide_core_creatives_vocab_sync';

	/** 打开页面时多久没同步就同步一次。 */
	public const INTERVAL = DAY_IN_SECONDS;

	public static function register_hooks(): void {
		add_action( self::CRON, array( self::class, 'sync' ) );
		add_action( 'init', array( self::class, 'schedule' ), 40 );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 15 * MINUTE_IN_SECONDS, 'daily', self::CRON );
		}
	}

	// ---------- 纯函数 ----------

	/**
	 * 校验总后台回应。属性名、取值的写法同广告名；每个必填属性至少有一个取值。不合格返回 null。
	 *
	 * @param mixed $json
	 * @return array{ver:int,items:array<int,array{attr:string,value:string,label:string,required:int,position:int}>}|null
	 */
	public static function clean_remote( $json ): ?array {
		if ( ! is_array( $json ) || ! is_int( $json['ver'] ?? null ) || $json['ver'] < 1 || ! is_array( $json['items'] ?? null ) ) {
			return null;
		}
		$items = array();
		foreach ( $json['items'] as $i => $it ) {
			if ( ! is_array( $it ) ) {
				continue;
			}
			$attr  = is_string( $it['attr'] ?? null ) ? strtolower( trim( $it['attr'] ) ) : '';
			$value = is_string( $it['value'] ?? null ) ? strtolower( trim( $it['value'] ) ) : '';
			if ( ! preg_match( '/^[a-z][a-z0-9]{0,15}$/', $attr ) || ! preg_match( '/^[a-z0-9]{1,24}$/', $value ) ) {
				continue;
			}
			$label = is_string( $it['label'] ?? null ) ? trim( strip_tags( $it['label'] ) ) : '';
			$items[ $attr . '|' . $value ] = array(
				'attr'     => $attr,
				'value'    => $value,
				'label'    => function_exists( 'mb_substr' ) ? mb_substr( $label, 0, 60 ) : substr( $label, 0, 60 ),
				'required' => in_array( $attr, Contract::REQUIRED_ATTRS, true ) || ! empty( $it['required'] ) ? 1 : 0,
				'position' => is_int( $it['position'] ?? null ) && $it['position'] >= 0 ? $it['position'] : (int) $i,
			);
		}
		$attrs = array_column( $items, 'attr' );
		foreach ( Contract::REQUIRED_ATTRS as $need ) {
			if ( ! in_array( $need, $attrs, true ) ) {
				return null;
			}
		}
		return array( 'ver' => $json['ver'], 'items' => array_values( $items ) );
	}

	/** 该不该覆盖本地词表。 */
	public static function newer( int $remote, int $local ): bool {
		return $remote > $local;
	}

	/** 打开页面时要不要同步：从没试过，或上次尝试已超过 INTERVAL。 */
	public static function due( int $last_try, int $now ): bool {
		return $last_try <= 0 || $now - $last_try >= self::INTERVAL;
	}

	// ---------- 读写 ----------

	public static function local_ver(): int {
		global $wpdb;
		return Schema::installed() ? (int) $wpdb->get_var( 'SELECT MAX(ver) FROM ' . Schema::table( 'attribute_vocab' ) ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/** @return array{at:int,ok:bool,ver:int,message:string} */
	public static function state(): array {
		$s = get_option( self::STATE, array() );
		$s = is_array( $s ) ? $s : array();
		return array( 'at' => (int) ( $s['at'] ?? 0 ), 'ok' => ! empty( $s['ok'] ), 'ver' => (int) ( $s['ver'] ?? 0 ), 'message' => (string) ( $s['message'] ?? '' ) );
	}

	/** 整体覆盖本地词表。 */
	public static function apply( array $clean ): bool {
		global $wpdb;
		$t = Schema::table( 'attribute_vocab' );
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$t}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		foreach ( $clean['items'] as $it ) {
			if ( false === $wpdb->insert( $t, $it + array( 'active' => 1, 'ver' => $clean['ver'] ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return false;
			}
		}
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return true;
	}

	/**
	 * 同步一次。
	 *
	 * @return array{status:string,ver:int,message:string} status：updated / same / not_connected / failed
	 */
	public static function sync(): array {
		Schema::maybe_install();
		$local = self::local_ver();
		$out   = array( 'status' => 'same', 'ver' => $local, 'message' => '' );
		if ( ! Creatives::hq_enabled() ) {
			$out = array( 'status' => 'not_connected', 'ver' => $local, 'message' => '没有接入总后台，使用本店词表。' );
		} else {
			$res   = Creatives::hq_request( 'GET', self::ROUTE, null, 8 );
			$clean = is_wp_error( $res ) ? null : self::clean_remote( $res );
			if ( is_wp_error( $res ) ) {
				$out = array( 'status' => 'failed', 'ver' => $local, 'message' => '拉取词表失败：' . $res->get_error_message() );
			} elseif ( null === $clean ) {
				$out = array( 'status' => 'failed', 'ver' => $local, 'message' => '总后台的词表格式不对或不完整，继续用本店词表。' );
			} elseif ( self::newer( $clean['ver'], $local ) ) {
				$out = self::apply( $clean )
					? array( 'status' => 'updated', 'ver' => $clean['ver'], 'message' => '词表已更新到第 ' . $clean['ver'] . ' 版。' )
					: array( 'status' => 'failed', 'ver' => $local, 'message' => '写入词表失败，继续用本店词表。' );
			}
		}
		update_option( self::STATE, array( 'at' => time(), 'ok' => in_array( $out['status'], array( 'updated', 'same' ), true ), 'ver' => $out['ver'], 'message' => $out['message'] ), false );
		return $out;
	}

	/** 打开素材库时调用：超过 24 小时没同步就同步一次。 */
	public static function maybe_sync(): ?array {
		return self::due( self::state()['at'], time() ) ? self::sync() : null;
	}
}
