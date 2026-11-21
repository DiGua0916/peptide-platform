<?php
/**
 * 换公开域名时，店铺里跟域名绑在一起的三处邮箱跟着换（2026-11-21）。
 *
 * 11-17 三家店换域名时，这三处是人工进后台逐个改的。现在：
 *
 * - option `home` 被改掉时（后台、`wp option update home`、换域名脚本），update_option_home 钩子当场同步；
 * - 用 SQL / search-replace 直接改库不会触发钩子：进后台时比一下“上次同步时的域名”和现在的域名，不一样就补做；
 * - 换域名工具也可以显式调用：`wp peptide mail-domain sync [--from=<旧域名>] [--dry-run]`。
 *
 * 只改三处，规则见 plan()：
 *   1. woocommerce_email_from_address：空、测试占位、或属于旧域名 → <原前缀，默认 orders>@<新域名>；外部独立邮箱不动。
 *   2. woocommerce_email_from_name：等于旧域名推出来的品牌名 → 新域名的 Mail_Outbox 品牌名；其他值不动。
 *   3. peptide_core_settings[support][email]：空的保持空（Chase_Copy::store_email() 会自己用新域名）；@旧域名 → @新域名。
 *
 * 幂等：同样的输入再跑一次没有变化。不改邮件模板、品牌设置或其他任何选项。
 *
 * @package PeptideCore
 */

namespace PeptideCore;

defined( 'ABSPATH' ) || exit;

final class Domain_Mail_Sync {

	/** 上次同步时的公开裸域名和结果：{host, at, changes[]}。 */
	public const STATE = 'pc_mail_domain_state';

	private const BARE = '/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?\z/';

	/** 测试 / 安装时留下的占位邮箱，看到就当没填。 */
	private const PLACEHOLDER_DOMAINS = array( 'example.com', 'example.org', 'example.net', 'test.com', 'localhost', 'local', 'test', 'invalid' );

	public static function register_hooks(): void {
		add_action( 'update_option_home', array( self::class, 'on_home_change' ), 20, 2 );
		add_action( 'admin_init', array( self::class, 'catch_up' ), 20 );
	}

	// ---------- 纯函数（测试直接调用） ----------

	/** URL 或主机名 → 去掉 www. 的裸域名；不合法返回空串。 */
	public static function bare( string $url_or_host ): string {
		$s    = strtolower( trim( $url_or_host ) );
		$host = str_contains( $s, '://' ) ? (string) wp_parse_url( $s, PHP_URL_HOST ) : (string) strtok( $s, '/:' );
		$host = rtrim( $host, '.' );
		if ( str_starts_with( $host, 'www.' ) ) { $host = substr( $host, 4 ); }
		return ( '' !== $host && strlen( $host ) <= 253 && preg_match( self::BARE, $host ) && ! filter_var( $host, FILTER_VALIDATE_IP ) ) ? $host : '';
	}

	/** 邮箱的域名部分（小写）；不是邮箱返回空串。 */
	private static function email_domain( string $email ): string {
		$at = strrpos( $email, '@' );
		return false === $at ? '' : strtolower( rtrim( substr( $email, $at + 1 ), '.' ) );
	}

	/** 邮箱是否属于这个裸域名（含 www. 等子域名的写法）。 */
	public static function belongs_to( string $email, string $bare ): bool {
		$d = self::email_domain( $email );
		return '' !== $bare && '' !== $d && ( $d === $bare || str_ends_with( $d, '.' . $bare ) );
	}

	public static function is_placeholder( string $email ): bool {
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) { return true; }
		$d = self::email_domain( $email );
		if ( '' === $d ) { return true; } // 不是邮箱的东西也当没填
		foreach ( self::PLACEHOLDER_DOMAINS as $p ) {
			if ( $d === $p || str_ends_with( $d, '.' . $p ) ) { return true; }
		}
		return false;
	}

	/**
	 * 算出要改什么，不写库。
	 *
	 * @param string $old     旧裸域名（可以为空：不知道旧域名时只处理空值和占位值）。
	 * @param string $new     新裸域名。
	 * @param array  $current {from_address, from_name, support_email}
	 * @param callable(string):string $brand 裸域名 → 品牌名（Mail_Outbox::brand_for_host）。
	 * @return array{changes:array<string,array{0:string,1:string}>,kept:array<string,string>}
	 */
	public static function plan( string $old, string $new, array $current, callable $brand ): array {
		$changes = array();
		$kept    = array();
		if ( '' === $new || $old === $new ) {
			return array( 'changes' => array(), 'kept' => array() );
		}

		// 1. 发件邮箱。
		$from = trim( (string) ( $current['from_address'] ?? '' ) );
		if ( self::belongs_to( $from, $new ) ) {
			// 已经是新域名，什么都不做（幂等）。
		} elseif ( self::is_placeholder( $from ) || ( '' !== $old && self::belongs_to( $from, $old ) ) ) {
			$prefix = '';
			if ( ! self::is_placeholder( $from ) ) {
				$prefix = strtolower( (string) substr( $from, 0, (int) strrpos( $from, '@' ) ) );
			}
			$prefix = preg_match( '/\A[a-z0-9](?:[a-z0-9._+-]{0,62}[a-z0-9])?\z/', $prefix ) ? $prefix : 'orders';
			$changes['woocommerce_email_from_address'] = array( $from, $prefix . '@' . $new );
		} else {
			$kept['woocommerce_email_from_address'] = '外部独立邮箱，不覆盖';
		}

		// 2. 发件人名称：只换“旧域名推出来的品牌名”。
		$name     = trim( (string) ( $current['from_name'] ?? '' ) );
		$new_name = trim( (string) $brand( $new ) );
		if ( '' !== $old && '' !== $name && '' !== $new_name && $name !== $new_name ) {
			$label    = (string) strtok( $old, '.' );
			$old_like = array_unique( array_filter( array( trim( (string) $brand( $old ) ), ucfirst( $label ), $label, $old, 'www.' . $old ) ) );
			if ( in_array( strtolower( $name ), array_map( 'strtolower', $old_like ), true ) ) {
				$changes['woocommerce_email_from_name'] = array( $name, $new_name );
			} else {
				$kept['woocommerce_email_from_name'] = '不是旧域名的品牌名，不覆盖';
			}
		}

		// 3. 客服联系方式里的邮箱：空的保持空；@旧域名 → @新域名。
		$support = trim( (string) ( $current['support_email'] ?? '' ) );
		if ( '' !== $support && '' !== $old && self::belongs_to( $support, $old ) ) {
			$changes['support_email'] = array( $support, substr( $support, 0, (int) strrpos( $support, '@' ) ) . '@' . $new );
		}

		return array( 'changes' => $changes, 'kept' => $kept );
	}

	// ---------- 读写 ----------

	/** @return array{from_address:string,from_name:string,support_email:string} */
	public static function current(): array {
		$s = get_option( Settings::OPTION, array() );
		return array(
			'from_address'  => (string) get_option( 'woocommerce_email_from_address', '' ),
			'from_name'     => (string) get_option( 'woocommerce_email_from_name', '' ),
			'support_email' => (string) ( is_array( $s ) && is_array( $s['support'] ?? null ) ? ( $s['support']['email'] ?? '' ) : '' ),
		);
	}

	/** 现在的公开裸域名（读原始 home，不受当前请求别名影响）。 */
	public static function current_bare(): string {
		$home = class_exists( Management_Host::class ) ? Management_Host::public_url() : (string) get_option( 'home' );
		return self::bare( $home );
	}

	/**
	 * 执行一次同步。$old 为空时用上次记下的域名。
	 *
	 * @return array{old:string,new:string,changes:array,kept:array,applied:bool,brand_hint:string}
	 */
	public static function sync( string $old = '', string $new = '', bool $dry_run = false ): array {
		$state = get_option( self::STATE, array() );
		$state = is_array( $state ) ? $state : array();
		$new   = '' !== $new ? self::bare( $new ) : self::current_bare();
		$old   = '' !== $old ? self::bare( $old ) : (string) ( $state['host'] ?? '' );
		$plan  = self::plan( $old, $new, self::current(), array( Mail_Outbox::class, 'brand_for_host' ) );

		// 对外品牌名是单独的设置（品牌名不在这次的范围里），只提示，不改。
		$hint   = '';
		$public = class_exists( Admin\Store_Settings::class ) ? Admin\Store_Settings::public_brand() : '';
		if ( '' !== $old && '' !== $public && strtolower( $public ) === strtolower( (string) strtok( $old, '.' ) ) ) {
			$hint = '对外品牌名还是旧域名的名字（' . $public . '），需要的话到店铺设置里改。';
		}

		$out = array( 'old' => $old, 'new' => $new, 'changes' => $plan['changes'], 'kept' => $plan['kept'], 'applied' => false, 'brand_hint' => $hint );
		if ( $dry_run || '' === $new ) {
			return $out;
		}
		foreach ( $plan['changes'] as $key => $pair ) {
			if ( 'support_email' === $key ) {
				$s = get_option( Settings::OPTION, array() );
				$s = is_array( $s ) ? $s : array();
				$s['support'] = is_array( $s['support'] ?? null ) ? $s['support'] : array();
				$s['support']['email'] = $pair[1];
				update_option( Settings::OPTION, $s );
			} else {
				update_option( $key, $pair[1] );
			}
		}
		update_option( self::STATE, array( 'host' => $new, 'from' => $old, 'at' => time(), 'changes' => $plan['changes'] ), false );
		$out['applied'] = true;
		if ( $plan['changes'] ) {
			do_action( 'peptide_core_mail_domain_synced', $out );
		}
		return $out;
	}

	// ---------- 钩子 ----------

	public static function on_home_change( $old_value, $value ): void {
		$old = self::bare( (string) $old_value );
		$new = self::bare( (string) $value );
		if ( '' === $new || $old === $new ) {
			return;
		}
		try { self::sync( $old, $new ); } catch ( \Throwable $e ) { /* 同步失败不影响改域名本身；进后台时会再补。 */ }
	}

	/** 进后台时补：上次同步的域名和现在不一样（例如直接改了库）就做一次；第一次运行只记下当前域名。 */
	public static function catch_up(): void {
		$state = get_option( self::STATE, array() );
		$now   = self::current_bare();
		if ( '' === $now ) {
			return;
		}
		if ( ! is_array( $state ) || '' === (string) ( $state['host'] ?? '' ) ) {
			update_option( self::STATE, array( 'host' => $now, 'from' => '', 'at' => time(), 'changes' => array() ), false );
			return;
		}
		if ( $state['host'] !== $now ) {
			try { self::sync(); } catch ( \Throwable $e ) { /* 下次进后台再试。 */ }
		}
	}
}
