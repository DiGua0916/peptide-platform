<?php
/**
 * 换域名之后，支付绑定自己跟上（2026-11-17 换域名事故）。
 *
 * 背景：Hub::provider_site() 会拿店铺当前的顾客域名和 pc_panapay_provider_site_bindings 里
 * 记的那一条对比，不一致就抛 provider_site_invalid（503）。店铺换了域名之后会在下一次
 * admin_init 自己向总后台重新注册，public_url 当场变成新域名——于是支付在没有任何提示的
 * 情况下停掉，只有顾客投诉才会被发现。
 *
 * 但这条绑定本身是有意义的：public_url 是**店铺自己报上来的**（见 PeptideHq\Register），
 * 所以绑定挡的正是「店铺被人改了地址、冒充成另一个商户」。因此这里不能见到新域名就认。
 *
 * 判断标准是：这个新域名总后台自己认不认。总后台的 pchq_cdn_domains 只有管理员能写
 * （换域名流程里由总后台的 CDN 页面落库），店铺改不动它。所以：
 *
 *   新域名在 pchq_cdn_domains 里有一行、site_id 对得上、stage 是 done  →  自动改绑定，留审计、开提醒；
 *   否则                                                              →  照旧 503，但**先开一条说清楚怎么办的提醒**。
 *
 * 旧代码里那个 503 是哑的，这是昨晚没人发现的根本原因。
 */
namespace VialCove\Panapay;

defined( 'ABSPATH' ) || exit;

final class Provider_Bindings {

	public const OPTION = 'pc_panapay_provider_site_bindings';
	public const AUDIT  = 'pc_panapay_binding_audit';
	/** 审计只留最近这么多条，够回溯换域名就行。 */
	public const AUDIT_MAX = 50;

	private const BARE = '/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?\z/';

	/** 去掉开头的 www.，其余原样；不是合法域名就返回空串。 */
	public static function bare( string $host ): string {
		$host = strtolower( trim( $host ) );
		if ( str_starts_with( $host, 'www.' ) ) { $host = substr( $host, 4 ); }
		return ( strlen( $host ) <= 253 && preg_match( self::BARE, $host ) ) ? $host : '';
	}

	/**
	 * 这个域名是不是总后台自己安排给这家店的？
	 *
	 * 只认 pchq_cdn_domains：管理员在总后台写的，店铺改不了。stage 必须是 done——
	 * 还在解析中、或者报错的行不算数，不然一个填错的域名也会被自动信任。
	 */
	public static function authorized( int $site_id, string $host ): bool {
		$bare = self::bare( $host );
		if ( '' === $bare || $site_id < 1 ) { return false; }
		$rows = get_option( 'pchq_cdn_domains', array() );
		if ( ! is_array( $rows ) ) { return false; }
		$row = $rows[ $bare ] ?? null;
		return is_array( $row ) && (int) ( $row['site_id'] ?? 0 ) === $site_id && 'done' === (string) ( $row['stage'] ?? '' );
	}

	/**
	 * 域名变了：能自动跟上就改绑定并返回新的那一条，不能就返回 null（调用方照旧 503）。
	 *
	 * 进来之前调用方已经确认过绑定的结构是好的、site_id 对得上，这里只处理「域名对不上」。
	 */
	public static function heal( array $site, string $account, array $binding, string $public_host ): ?array {
		$site_id = (int) ( $site['id'] ?? 0 );
		$uuid    = (string) ( $site['uuid'] ?? '' );
		$bare    = self::bare( $public_host );
		if ( '' === $bare || '' === $uuid || $site_id < 1 || (int) ( $binding['site_id'] ?? 0 ) !== $site_id ) { return null; }
		// 顾客域名必须等于、或者是这个裸域名的子域（www. 就是这么过的）。
		if ( strtolower( $public_host ) !== $bare && ! str_ends_with( strtolower( $public_host ), '.' . $bare ) ) { return null; }
		if ( ! self::authorized( $site_id, $bare ) ) { return null; }

		$settings = get_option( self::OPTION, null );
		if ( ! is_array( $settings ) || 1 !== ( $settings['version'] ?? null ) || ! is_array( $settings['bindings'] ?? null ) ) { return null; }
		$key = $account . ':' . $uuid;
		if ( ! array_key_exists( $key, $settings['bindings'] ) ) { return null; }

		$was   = $binding;
		$fixed = array( 'site_id' => $site_id, 'public_host' => strtolower( $public_host ), 'provider_site' => $bare );
		$settings['bindings'][ $key ] = $fixed;
		update_option( self::OPTION, $settings, false );

		self::record(
			array(
				'at'       => time(),
				'site_id'  => $site_id,
				'account'  => $account,
				'from'     => (string) ( $was['public_host'] ?? '' ),
				'to'       => $fixed['public_host'],
				'provider' => $bare,
				'was'      => (string) ( $was['provider_site'] ?? '' ),
			)
		);
		self::alert(
			'moved:' . $site_id . ':' . $account,
			sprintf(
				'店铺 #%1$d 的顾客域名换成了 %2$s，支付绑定已自动跟上（原来是 %3$s）。发给收单方的商户站点现在是 %4$s——如果收单方那边需要报备域名，请确认 %4$s 已经报备，否则建单会在他们那边被拒。',
				$site_id,
				$fixed['public_host'],
				(string) ( $was['public_host'] ?? '（空）' ),
				$bare
			)
		);
		return $fixed;
	}

	/**
	 * 没能自动跟上：开一条提醒，把该做什么写清楚。
	 *
	 * 同一个店铺+账户只开一条，加好之后调用 clear() 收掉。
	 */
	public static function stuck( array $site, string $account, string $public_host, string $why ): void {
		$site_id = (int) ( $site['id'] ?? 0 );
		$name    = (string) ( $site['name'] ?? '' ) ?: ( '店铺 #' . $site_id );
		$shown   = '' !== $public_host ? $public_host : '（地址无效）';
		$message = 'structure' === $why
			? sprintf( '%1$s 的支付绑定记录本身坏了，支付已停（provider_site_invalid）。处理：总后台 → 支付账户 → 重新绑定这家店。', $name )
			: sprintf(
				'%1$s 的顾客域名现在是 %2$s，和支付绑定里记的对不上，支付已停（provider_site_invalid）。总后台没有认过这个域名，所以没有自动跟上。处理：先在总后台 → 域名与 CDN 里把 %3$s 这一行做到「完成」，再打开店铺后台任意一页，绑定会自动跟上；也可以直接跑 wp pchq-domain verify %4$d 看还差哪一步。',
				$name,
				$shown,
				self::bare( $public_host ) ?: $shown,
				$site_id
			);
		self::alert( 'stuck:' . $site_id . ':' . $account, $message );
	}

	/** 绑定恢复正常了，把这家店的「支付绑定」提醒收掉。 */
	public static function clear( int $site_id, string $account ): void {
		if ( ! class_exists( '\PeptideHq\Alerts' ) ) { return; }
		try { \PeptideHq\Alerts::resolve_ref( 0, 'payment', 'binding-stuck:' . $site_id . ':' . $account ); }
		catch ( \Throwable $ignored ) { /* 提醒失败不能影响支付流程。 */ }
	}

	/** @return array<int,array> 最近的自动改绑记录，新的在前。 */
	public static function audit(): array {
		$rows = get_option( self::AUDIT, array() );
		return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : array();
	}

	private static function record( array $entry ): void {
		$rows = self::audit();
		array_unshift( $rows, $entry );
		update_option( self::AUDIT, array_slice( $rows, 0, self::AUDIT_MAX ), false );
	}

	private static function alert( string $ref, string $message ): void {
		if ( ! class_exists( '\PeptideHq\Alerts' ) ) { return; }
		try { \PeptideHq\Alerts::raise( 0, 'payment', 'binding-' . $ref, $message ); }
		catch ( \Throwable $ignored ) { /* 同上：提醒失败不能影响支付流程。 */ }
	}
}
