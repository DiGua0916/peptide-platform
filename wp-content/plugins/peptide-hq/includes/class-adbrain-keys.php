<?php
/**
 * Ad Brain 接口密钥（2026-11-22）：总后台给每家店生成一把密钥，经现有的店铺连接（/peptide/v1/hq/config）下发，
 * 投手什么都不用做。密钥加密存在总后台（Crypto），店铺只回报指纹，用来确认下发成功。
 *
 * @package PeptideHq
 */

namespace PeptideHq;

defined( 'ABSPATH' ) || exit;

final class Adbrain_Keys {

	public const OPTION     = 'pchq_adbrain_keys';
	public const CAPABILITY = 'hq.adbrain_secret';
	public const ROUTE      = '/wp-json/peptide/v1/adbrain/';

	/** 指纹（和店铺 Hq_Connector::adbrain_fp 一致）。 */
	public static function fp( string $secret ): string {
		return substr( hash( 'sha256', $secret ), 0, 12 );
	}

	/** 新密钥：64 位十六进制。 */
	public static function generate_secret(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/** @return array<int,array{secret:string,fp:string,at:int}> 已保存的记录（secret 仍是密文）。 */
	private static function stored(): array {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	/** 这家店在总后台记下的密钥（明文）；没有返回 ''。 */
	public static function secret( int $site_id ): string {
		$row = self::stored()[ $site_id ] ?? null;
		return is_array( $row ) ? Crypto::decrypt( (string) ( $row['secret'] ?? '' ) ) : '';
	}

	/** @return array{fp:string,at:int}|null */
	public static function record( int $site_id ): ?array {
		$row = self::stored()[ $site_id ] ?? null;
		return is_array( $row ) ? array( 'fp' => (string) ( $row['fp'] ?? '' ), 'at' => (int) ( $row['at'] ?? 0 ) ) : null;
	}

	private static function save( int $site_id, string $secret ): void {
		$all = self::stored();
		if ( '' === $secret ) {
			unset( $all[ $site_id ] );
		} else {
			$all[ $site_id ] = array( 'secret' => Crypto::encrypt( $secret ), 'fp' => self::fp( $secret ), 'at' => time() );
		}
		update_option( self::OPTION, $all, false );
	}

	/** 给 Ad Brain 用的接口地址（店铺公开域名）。 */
	public static function endpoint( array $site ): string {
		$url = Site_Urls::public_url( $site );
		return '' === $url ? '' : untrailingslashit( $url ) . self::ROUTE;
	}

	/** X-AdBrain-Site 头建议填的值：店铺公开域名（不含 www.）。 */
	public static function site_label( array $site ): string {
		$host = (string) wp_parse_url( Site_Urls::public_url( $site ), PHP_URL_HOST );
		return (string) preg_replace( '/^www\./i', '', strtolower( $host ) );
	}

	/**
	 * 店铺回报的状态和总后台记录比对（纯函数）。
	 *
	 * @param array|null $remote 店铺 config 里的 adbrain：{enabled, source, fp}；null = 没查到。
	 * @param array|null $local  总后台记录 {fp, at}。
	 * @return array{state:string,label:string}
	 *   state：on（一致）、off（都没开）、mismatch（店铺的和总后台的不一样）、constant（店铺用 wp-config 常量）、
	 *          lost（总后台有、店铺没有）、unknown（查不到店铺）。
	 */
	public static function compare( ?array $remote, ?array $local ): array {
		if ( null === $remote ) {
			return array( 'state' => 'unknown', 'label' => '查不到店铺状态' );
		}
		$enabled = ! empty( $remote['enabled'] );
		$fp      = (string) ( $remote['fp'] ?? '' );
		if ( $enabled && 'constant' === ( $remote['source'] ?? '' ) ) {
			return array( 'state' => 'constant', 'label' => '店铺服务器配置里写死了密钥，这里改不了' );
		}
		if ( ! $enabled ) {
			return null === $local ? array( 'state' => 'off', 'label' => '未开启' ) : array( 'state' => 'lost', 'label' => '店铺上没有密钥，请重新生成' );
		}
		if ( null !== $local && hash_equals( $local['fp'], $fp ) ) {
			return array( 'state' => 'on', 'label' => '已开启' );
		}
		return array( 'state' => 'mismatch', 'label' => '店铺上的密钥和这里记录的不一致，请重新生成' );
	}

	/** 店铺现在的状态（空的 config 请求只读不改）。 @return array|null */
	public static function remote( int $site_id ): ?array {
		if ( ! Client::supports( $site_id, self::CAPABILITY ) ) {
			return null;
		}
		$res = Client::request( $site_id, 'POST', '/peptide/v1/hq/config', array(), array(), 8 );
		return ( is_array( $res ) && isset( $res['adbrain'] ) && is_array( $res['adbrain'] ) ) ? $res['adbrain'] : null;
	}

	/**
	 * 生成新密钥并下发；店铺回报的指纹对上才保存。
	 *
	 * @return true|\WP_Error
	 */
	public static function issue( int $site_id ) {
		if ( ! Client::supports( $site_id, self::CAPABILITY ) ) {
			return new \WP_Error( 'pchq_adbrain_old', '这家店的程序还不支持，先发布最新版本。' );
		}
		$secret = self::generate_secret();
		$res    = Client::config( $site_id, array( 'adbrain_secret' => $secret ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$remote = is_array( $res['adbrain'] ?? null ) ? $res['adbrain'] : array();
		if ( 'constant' === ( $remote['source'] ?? '' ) ) {
			return new \WP_Error( 'pchq_adbrain_constant', '店铺服务器配置（wp-config）里写死了密钥，总后台下发的不生效。' );
		}
		if ( empty( $remote['enabled'] ) || ! hash_equals( self::fp( $secret ), (string) ( $remote['fp'] ?? '' ) ) ) {
			return new \WP_Error( 'pchq_adbrain_verify', '店铺没有确认收到新密钥，请稍后重试。' );
		}
		self::save( $site_id, $secret );
		return true;
	}

	/**
	 * 关闭：删掉店铺上的密钥（接口回到 503）和总后台的记录。
	 *
	 * @return true|\WP_Error
	 */
	public static function revoke( int $site_id ) {
		if ( ! Client::supports( $site_id, self::CAPABILITY ) ) {
			return new \WP_Error( 'pchq_adbrain_old', '这家店的程序还不支持，先发布最新版本。' );
		}
		$res = Client::config( $site_id, array( 'adbrain_secret' => '' ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( ! empty( $res['adbrain']['enabled'] ) && 'constant' !== ( $res['adbrain']['source'] ?? '' ) ) {
			return new \WP_Error( 'pchq_adbrain_verify', '店铺没有确认关闭，请稍后重试。' );
		}
		self::save( $site_id, '' );
		return true;
	}

	/**
	 * 交给 Ad Brain 的配置（已开启的店）：店铺名、站点标识、接口地址、密钥。
	 *
	 * @param array<int,array> $sites
	 * @return list<array{store:string,site:string,base_url:string,secret:string}>
	 */
	public static function export( array $sites ): array {
		$out = array();
		foreach ( $sites as $id => $site ) {
			$secret = self::secret( (int) $id );
			if ( '' === $secret ) {
				continue;
			}
			$out[] = array(
				'store'    => '' !== (string) $site['name'] ? (string) $site['name'] : (string) $site['url'],
				'site'     => self::site_label( $site ),
				'base_url' => self::endpoint( $site ),
				'secret'   => $secret,
			);
		}
		return $out;
	}
}
