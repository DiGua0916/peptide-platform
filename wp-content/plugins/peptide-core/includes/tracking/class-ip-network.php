<?php
/**
 * IP 的地理和网络信息（CR 素材归因线 1，2026-11-11）：省 / 州、城市、经纬度、ISP、ASN、IP 类型。
 *
 * 数据来自本机的 mmdb 库文件（MaxMind GeoLite2 或 DB-IP lite，两家格式相同），库文件不进 Git：
 * - 城市库：常量 PC_DATA_GEOIP_CITY_DATABASE（绝对路径）；没定义时找 uploads/peptide-geoip/ 下的
 *   GeoLite2-City.mmdb、dbip-city-lite.mmdb。
 * - ASN 库：常量 PC_DATA_GEOIP_ASN_DATABASE；没定义时找 uploads/peptide-geoip/ 下的 GeoLite2-ASN.mmdb、dbip-asn-lite.mmdb。
 * - 过滤器 peptide_core_ip_network_databases 可以改这两个路径（array{city:?string,asn:?string}）。
 * 读库用 WooCommerce 自带的 MaxMind Reader（Country_Provider::reader_available()），不新增依赖。
 * 没有库、库读不了、内网地址、查不到：对应的列留空（EMPTY），不报错。
 *
 * ip_type 是简化版：按 ASN 号和 ISP 名判断 vpn（常见 VPN 出口）/ hosting（常见云和主机商）/ mobile（手机网络）/
 * residential（常见家庭宽带）；判断不了留空。付费库带 connection_type 时优先用它。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Tracking;

defined( 'ABSPATH' ) || exit;

final class Ip_Network {

	public const EMPTY = array( 'geo_country' => '', 'geo_region' => '', 'geo_city' => '', 'geo_lat' => '', 'geo_lon' => '', 'isp' => '', 'asn' => 0, 'ip_type' => '' );

	public const TYPE_VPN         = 'vpn';
	public const TYPE_HOSTING     = 'hosting';
	public const TYPE_MOBILE      = 'mobile';
	public const TYPE_RESIDENTIAL = 'residential';

	/** 常见 VPN 出口所在的 ASN（M247、Datacamp / CDN77、PacketHub、Tefincom、31173 Services、Private Layer、Cloudflare WARP）。 */
	private const VPN_ASN = array( 9009, 60068, 212238, 147049, 136787, 39351, 51852, 13335 );
	private const VPN_NAME = '/\b(?:vpn|nordvpn|expressvpn|surfshark|proton ?ag|private internet access|mullvad|windscribe|cyberghost|hide\.me|ipvanish|tefincom|packethub|datacamp|m247)\b/i';

	/** 常见云和主机商的 ASN。 */
	private const HOSTING_ASN = array(
		16509, 14618, 8987,            // Amazon / AWS
		396982, 19527,                 // Google Cloud
		8075, 8068,                    // Microsoft Azure
		14061,                         // DigitalOcean
		16276, 35540,                  // OVH
		24940, 213230,                 // Hetzner
		63949,                         // Linode / Akamai
		20473,                         // Vultr / Choopa
		31898,                         // Oracle Cloud
		45102, 37963,                  // Alibaba Cloud
		132203, 45090,                 // Tencent Cloud
		51167,                         // Contabo
		12876,                         // Scaleway
		60781, 28753, 59253, 7203,     // Leaseweb
		47583,                         // Hostinger
		62240,                         // Clouvider
		36352,                         // ColoCrossing
		46606,                         // Unified Layer / Bluehost
		26496,                         // GoDaddy
		55286, 19318,                  // Server Central / Interserver
		40021,                         // Contabo US
		398324, 399629,                // Censys / BL Networks 扫描器常用
	);
	private const HOSTING_NAME = '/\b(?:amazon|aws|google cloud|google llc|microsoft|azure|digitalocean|ovh|hetzner|linode|akamai|vultr|choopa|oracle|alibaba|aliyun|tencent|contabo|scaleway|leaseweb|hostinger|clouvider|colocrossing|godaddy|hosting|datacenter|data center|server|cloud|vps|colo)\b/i';

	/** 美国 / 常见市场的手机网络。 */
	private const MOBILE_ASN = array( 6167, 22394, 21928, 20057, 10507, 3651, 18779, 1239 );
	private const MOBILE_NAME = '/\b(?:mobile|mobility|wireless|cellular|lte|telcel|movistar|claro|tigo|vodafone|o2|t-mobile|metropcs|cricket)\b/i';

	/** 常见家庭宽带。 */
	private const RESIDENTIAL_ASN = array( 7922, 20115, 22773, 7018, 701, 209, 5650, 6128, 11427, 10796, 11351, 12271, 33363, 20001, 812, 577, 6327, 5089, 2856, 3320, 3215 );
	private const RESIDENTIAL_NAME = '/\b(?:comcast|charter|spectrum|cox commun|frontier|centurylink|lumen|optimum|altice|rogers|bell canada|shaw|telus|virgin media|sky broadband|british telecom|deutsche telekom|orange|windstream|mediacom|wow!|rcn|suddenlink|verizon fios|at&t services|cable|broadband|dsl|fiber|fibre)\b/i';

	/** @var array<string,\MaxMind\Db\Reader|false> 本次请求里已打开的库。 */
	private static array $readers = array();

	/**
	 * 查一个 IP。任何失败都返回 EMPTY（列留空）。
	 *
	 * @return array{geo_region:string,geo_city:string,geo_lat:string,geo_lon:string,isp:string,asn:int,ip_type:string}
	 */
	public static function lookup( string $ip ): array {
		try {
			$flag = defined( 'FILTER_FLAG_GLOBAL_RANGE' ) ? constant( 'FILTER_FLAG_GLOBAL_RANGE' ) : null;
			if ( 'unavailable' !== Country_Provider::classify( $ip, is_int( $flag ) ? $flag : null )['status'] ) {
				return self::EMPTY; // 内网、本机、保留地址不查。
			}
			$paths = self::databases();
			$city  = null !== $paths['city'] ? self::record( $paths['city'], $ip ) : null;
			$asn   = null !== $paths['asn'] ? self::record( $paths['asn'], $ip ) : null;
			return self::from_records( $city, $asn );
		} catch ( \Throwable $error ) {
			return self::EMPTY;
		}
	}

	/**
	 * 库文件路径：常量 > uploads/peptide-geoip/ 里的默认文件名；再经过滤器。不存在的文件返回 null。
	 *
	 * @return array{city:?string,asn:?string}
	 */
	public static function databases(): array {
		$paths = array( 'city' => null, 'asn' => null );
		$names = array(
			'city' => array( 'PC_DATA_GEOIP_CITY_DATABASE', array( 'GeoLite2-City.mmdb', 'dbip-city-lite.mmdb' ) ),
			'asn'  => array( 'PC_DATA_GEOIP_ASN_DATABASE', array( 'GeoLite2-ASN.mmdb', 'dbip-asn-lite.mmdb' ) ),
		);
		$dir = null;
		if ( function_exists( 'wp_upload_dir' ) ) {
			$uploads = wp_upload_dir( null, false );
			if ( is_array( $uploads ) && empty( $uploads['error'] ) && is_string( $uploads['basedir'] ?? null ) ) {
				$dir = rtrim( $uploads['basedir'], '/\\' ) . '/peptide-geoip/';
			}
		}
		foreach ( $names as $kind => list( $constant, $files ) ) {
			if ( defined( $constant ) && '' !== (string) constant( $constant ) ) {
				$paths[ $kind ] = (string) constant( $constant );
				continue;
			}
			foreach ( null === $dir ? array() : $files as $file ) {
				if ( is_file( $dir . $file ) ) {
					$paths[ $kind ] = $dir . $file;
					break;
				}
			}
		}
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'peptide_core_ip_network_databases', $paths );
			$paths    = is_array( $filtered ) ? array_merge( $paths, array_intersect_key( $filtered, $paths ) ) : $paths;
		}
		foreach ( $paths as $kind => $path ) {
			$path            = Country_Provider::local_path( $path );
			$paths[ $kind ] = null !== $path && is_file( $path ) && is_readable( $path ) ? $path : null;
		}
		return $paths;
	}

	/** 从一个库里读这个 IP 的记录；读不了返回 null。 */
	private static function record( string $path, string $ip ): ?array {
		if ( ! array_key_exists( $path, self::$readers ) ) {
			self::$readers[ $path ] = false;
			if ( Country_Provider::reader_available() ) {
				$real = Country_Provider::local_path( realpath( $path ) );
				self::$readers[ $path ] = null === $real ? false : new \MaxMind\Db\Reader( $real );
			}
		}
		$reader = self::$readers[ $path ];
		if ( false === $reader ) {
			return null;
		}
		$data = $reader->get( $ip );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * 把库里的记录变成访问表的列（纯函数）。城市库和 ASN 库的字段名两家相同。
	 *
	 * @param array<string,mixed>|null $city 城市库记录（subdivisions、city、location）
	 * @param array<string,mixed>|null $asn  ASN 库记录（autonomous_system_number、autonomous_system_organization；付费库还有 isp、connection_type）
	 */
	public static function from_records( ?array $city, ?array $asn ): array {
		$out  = self::EMPTY;
		$city = $city ?? array();
		$asn  = $asn ?? array();
		$name = static function ( $node ): string {
			if ( ! is_array( $node ) ) { return ''; }
			$names = is_array( $node['names'] ?? null ) ? $node['names'] : array();
			$value = $names['en'] ?? ( $node['iso_code'] ?? '' );
			return is_string( $value ) ? $value : '';
		};
		// 国家（2026-11-13）：两位国家代码（US、PR、MX）。
		$iso                = is_array( $city['country'] ?? null ) ? ( $city['country']['iso_code'] ?? '' ) : '';
		$out['geo_country'] = is_string( $iso ) && 1 === preg_match( '/\A[A-Za-z]{2}\z/', $iso ) ? strtoupper( $iso ) : '';
		$sub               = is_array( $city['subdivisions'] ?? null ) ? ( $city['subdivisions'][0] ?? null ) : null;
		$out['geo_region'] = self::text( $name( $sub ), 64 );
		$out['geo_city']   = self::text( $name( $city['city'] ?? null ), 80 );
		$loc               = is_array( $city['location'] ?? null ) ? $city['location'] : array();
		$lat               = $loc['latitude'] ?? null;
		$lon               = $loc['longitude'] ?? null;
		if ( is_numeric( $lat ) && is_numeric( $lon ) && abs( (float) $lat ) <= 90 && abs( (float) $lon ) <= 180 ) {
			$out['geo_lat'] = self::coord( (float) $lat );
			$out['geo_lon'] = self::coord( (float) $lon );
		}
		$number     = $asn['autonomous_system_number'] ?? ( $city['traits']['autonomous_system_number'] ?? 0 );
		$out['asn'] = is_int( $number ) && $number > 0 && $number <= 4294967295 ? $number : 0;
		$isp        = $asn['isp'] ?? ( $asn['autonomous_system_organization'] ?? ( $city['traits']['isp'] ?? ( $city['traits']['autonomous_system_organization'] ?? '' ) ) );
		$out['isp'] = self::text( is_string( $isp ) ? $isp : '', 120 );
		$connection = $asn['connection_type'] ?? ( $city['traits']['connection_type'] ?? '' );
		$out['ip_type'] = self::ip_type( $out['asn'], $out['isp'], is_string( $connection ) ? $connection : '' );
		return $out;
	}

	/**
	 * 简化版 IP 类型：vpn > hosting > mobile > residential > ''。
	 * $connection_type 是付费库（GeoIP2 Connection-Type / ISP）的取值，有就优先（Cellular → mobile，Cable/DSL → residential）。
	 */
	public static function ip_type( int $asn, string $isp, string $connection_type = '' ): string {
		if ( in_array( $asn, self::VPN_ASN, true ) || ( '' !== $isp && preg_match( self::VPN_NAME, $isp ) ) ) {
			return self::TYPE_VPN;
		}
		if ( in_array( $asn, self::HOSTING_ASN, true ) ) {
			return self::TYPE_HOSTING;
		}
		if ( 'Cellular' === $connection_type ) {
			return self::TYPE_MOBILE;
		}
		if ( 'Cable/DSL' === $connection_type ) {
			return self::TYPE_RESIDENTIAL;
		}
		if ( in_array( $asn, self::MOBILE_ASN, true ) ) {
			return self::TYPE_MOBILE;
		}
		if ( in_array( $asn, self::RESIDENTIAL_ASN, true ) ) {
			return self::TYPE_RESIDENTIAL;
		}
		if ( '' === $isp ) {
			return '';
		}
		// 名字判断放在 ASN 之后：手机 / 宽带运营商的名字里也可能有 “cloud”“server” 之类的词。
		if ( preg_match( self::MOBILE_NAME, $isp ) ) {
			return self::TYPE_MOBILE;
		}
		if ( preg_match( self::HOSTING_NAME, $isp ) ) {
			return self::TYPE_HOSTING;
		}
		return preg_match( self::RESIDENTIAL_NAME, $isp ) ? self::TYPE_RESIDENTIAL : '';
	}

	/** 去掉控制字符，按字符截断。 */
	private static function text( string $value, int $max ): string {
		$value = trim( (string) preg_replace( '/[\x00-\x1f\x7f]+/', ' ', $value ) );
		if ( ! preg_match( '//u', $value ) ) {
			return '';
		}
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
	}

	/** 经纬度保留 4 位小数（约 11 米，列宽 16）。 */
	private static function coord( float $value ): string {
		return rtrim( rtrim( sprintf( '%.4f', $value ), '0' ), '.' );
	}
}
