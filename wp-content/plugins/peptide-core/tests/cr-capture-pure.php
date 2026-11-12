<?php
/**
 * CR 素材归因线 1（采集补齐）的纯逻辑测试：不加载 WordPress、不连数据库。
 *
 * 覆盖：放宽的参数白名单和可选浏览器事实（Attribution::clean / query）、v5 新列（Visit_Facts::columns）、
 * 换广告开新访问（Tracker::continues）、广告首次出现（Visit_Facts::ad_row / ad_upsert_sql）、
 * 入口快照写入的 SQL（Attribution::capture，用记录 SQL 的假 $wpdb）、访客级来源（after_capture）、
 * IP 地理网络（Ip_Network：记录映射、IP 类型、没有库时留空；本机有 WooCommerce 源码时再用生成的小 mmdb 走一遍真实 Reader）。
 *
 * 运行：php wp-content/plugins/peptide-core/tests/cr-capture-pure.php
 * 可选：环境变量 PC_TEST_WC_DIR 指向 WooCommerce 插件目录（含 vendor/maxmind-db），否则自动找项目上一级的 tools/wc-src/woocommerce。
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	$inc = dirname( __DIR__ ) . '/includes';
	require $inc . '/creatives/class-contract.php';
	require $inc . '/creatives/class-schema.php';
	require $inc . '/tracking/class-schema.php';
	require $inc . '/tracking/class-client-environment.php';
	require $inc . '/tracking/class-country-provider.php';
	require $inc . '/tracking/class-ip-network.php';
	require $inc . '/tracking/class-attribution.php';
	require $inc . '/tracking/class-visit-facts.php';
	require $inc . '/tracking/class-tracker.php';

	use PeptideCore\Creatives\Contract;
	use PeptideCore\Tracking\Attribution;
	use PeptideCore\Tracking\Client_Environment;
	use PeptideCore\Tracking\Ip_Network;
	use PeptideCore\Tracking\Tracker;
	use PeptideCore\Tracking\Visit_Facts;

	$pass  = 0;
	$check = static function ( string $name, bool $ok ) use ( &$pass ): void {
		if ( ! $ok ) {
			fwrite( STDERR, "FAIL {$name}\n" );
			exit( 1 );
		}
		++$pass;
	};

	// ---------- 假的 WordPress / $wpdb ----------
	$GLOBALS['pc_options'] = array( 'peptide_core_creatives_db' => '1' );
	function get_option( $name, $default = false ) { return $GLOBALS['pc_options'][ $name ] ?? $default; }
	final class Fake_Wpdb {
		public string $prefix = 'wp_';
		public string $last_error = '';
		public array $queries = array();
		public $next_query = 1;
		public function prepare( string $sql, ...$args ): string {
			$i = 0;
			return (string) preg_replace_callback( '/%[sd]/', function ( $m ) use ( &$i, $args ) {
				$v = $args[ $i++ ];
				return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
			}, $sql );
		}
		public function query( string $sql ) { $this->queries[] = $sql; return $this->next_query; }
		public function get_row( $sql, $mode = null ) { $this->queries[] = $sql; return null; }
	}
	$GLOBALS['wpdb'] = new Fake_Wpdb();
	define( 'ARRAY_A', 'ARRAY_A' );

	// ---------- 浏览器事实 ----------
	$check( 'timezone ok', 'America/New_York' === Client_Environment::timezone( 'America/New_York' ) && 'UTC' === Client_Environment::timezone( 'UTC' ) && 'Etc/GMT+5' === Client_Environment::timezone( 'Etc/GMT+5' ) && 'America/Argentina/Buenos_Aires' === Client_Environment::timezone( 'America/Argentina/Buenos_Aires' ) );
	foreach ( array( '', 'America/New York', '../etc/passwd', str_repeat( 'A', 65 ), 'a/b/c/d', 5, null, "UTC\n" ) as $i => $bad ) {
		$check( 'timezone rejected ' . $i, '' === Client_Environment::timezone( $bad ) );
	}
	$check( 'language normalized', 'en-US' === Client_Environment::language( 'en-us' ) && 'es-419' === Client_Environment::language( 'es-419' ) && 'zh-Hant-TW' === Client_Environment::language( 'zh-Hant-tw' ) && 'pt' === Client_Environment::language( 'PT' ) );
	foreach ( array( '', 'english', 'en_US', 'e', 'en-US;q=0.9', str_repeat( 'a', 36 ), 1, null ) as $i => $bad ) {
		$check( 'language rejected ' . $i, '' === Client_Environment::language( $bad ) );
	}

	// ---------- 放宽的白名单 ----------
	$template = array(
		'utm_source'   => 'fb',
		'utm_medium'   => 'paid',
		'utm_campaign' => '120200000000001',
		'utm_term'     => '120200000000002',
		'utm_content'  => '120200000000003',
		'placement'    => 'Facebook_Mobile_Feed',
		'ad_name'      => 'C0012_hook-lab_fmt-vid_angle-purity_lang-en_v1',
		'cr'           => 'C0012',
	);
	$wire = array( 'version' => 1, 'host' => 'shop.test', 'landing_query' => array_reverse( $template, true ) + array( 'fbclid' => 'PRIVATE', 'secret' => 'x' ), 'referrer_host' => 'm.facebook.com', 'has_fbclid' => true, 'timezone' => 'America/Chicago', 'language' => 'en-us' );
	$out  = Attribution::clean( $wire );
	$check( 'template accepted', $out['ok'] );
	$check( 'all contract params kept in contract order, others dropped', $template === $out['value']['landing_query'] && array_keys( $out['value']['landing_query'] ) === Contract::PARAMS );
	$check( 'browser facts kept', true === $out['value']['has_fbclid'] && 'America/Chicago' === $out['value']['timezone'] && 'en-US' === $out['value']['language'] );
	$check( 'query string in contract order', 'utm_source=fb&utm_medium=paid&utm_campaign=120200000000001&utm_term=120200000000002&utm_content=120200000000003&placement=Facebook_Mobile_Feed&ad_name=C0012_hook-lab_fmt-vid_angle-purity_lang-en_v1&cr=C0012' === Attribution::query( $out['value'] ) );
	parse_str( Attribution::query( $out['value'] ), $parsed );
	$check( 'abandoned-cart / checkout readers see utm_campaign and utm_content', '120200000000001' === $parsed['utm_campaign'] && '120200000000003' === $parsed['utm_content'] );
	$old = array( 'version' => 1, 'host' => 'shop.test', 'landing_query' => array( 'utm_source' => 'facebook', 'utm_medium' => 'paid' ), 'referrer_host' => '' );
	$check( 'old snapshot unchanged', $old === Attribution::clean( $old )['value'] );
	$bad_extras = $wire;
	$bad_extras['landing_query'] = array( 'utm_source' => 'fb', 'utm_content' => '', 'placement' => str_repeat( 'p', 65 ), 'ad_name' => "a\nb", 'utm_term' => 7, 'utm_campaign' => str_repeat( '中', 120 ) );
	$bad_extras['has_fbclid'] = 'yes';
	$bad_extras['timezone']   = 'Not a zone';
	$r = Attribution::clean( $bad_extras );
	$check( 'bad optional params dropped one by one, source kept', $r['ok'] && array( 'utm_source' => 'fb', 'utm_campaign' => str_repeat( '中', 120 ) ) === $r['value']['landing_query'] );
	$check( 'bad optional facts dropped', ! isset( $r['value']['has_fbclid'] ) && ! isset( $r['value']['timezone'] ) && 'en-US' === $r['value']['language'] );
	$long = $wire;
	$long['landing_query'] = array( 'ad_name' => str_repeat( 'n', 255 ) );
	$check( 'ad_name up to 255', 255 === strlen( Attribution::clean( $long )['value']['landing_query']['ad_name'] ) );
	$strict = $wire;
	$strict['landing_query']['utm_medium'] = array();
	$check( 'utm_medium still strict', ! Attribution::clean( $strict )['ok'] );

	// ---------- v5 新列 ----------
	$meta    = $out['value'];
	$network = array( 'geo_region' => 'Texas', 'geo_city' => 'Austin', 'geo_lat' => '30.2672', 'geo_lon' => '-97.7431', 'isp' => 'Comcast Cable', 'asn' => 7922, 'ip_type' => 'residential' );
	$fb_ua   = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148 [FBAN/FBIOS;FBAV/450.0]';
	$cols    = Visit_Facts::columns( $meta, $fb_ua, '203.0.113.9', $network );
	$check( 'paid visit columns', 'paid' === $cols['source_class'] && 1 === $cols['params_valid'] && 1 === $cols['has_fbclid'] && 'C0012' === $cols['creative_code'] && 'fb' === $cols['in_app'] );
	$cr_meta = $meta;
	$cr_meta['landing_query']['cr']      = 'C0031';
	$cr_meta['landing_query']['ad_name'] = 'Summer test ad';
	$check( 'creative link: cr gives the code even when the ad name has none', 'C0031' === Visit_Facts::columns( $cr_meta, $fb_ua, '203.0.113.9', $network )['creative_code'] );
	$cr_meta['landing_query']['ad_name'] = 'C0012_hook-lab';
	$check( 'creative link: cr wins over the ad name', 'C0031' === Visit_Facts::columns( $cr_meta, $fb_ua, '203.0.113.9', $network )['creative_code'] );
	$check( 'ad params copied', '120200000000003' === $cols['utm_content'] && '120200000000002' === $cols['utm_term'] && '120200000000001' === $cols['utm_campaign'] && 'Facebook_Mobile_Feed' === $cols['placement'] && $template['ad_name'] === $cols['ad_name'] );
	$check( 'network and browser columns', '203.0.113.9' === $cols['ip'] && 'Austin' === $cols['geo_city'] && 7922 === $cols['asn'] && 'residential' === $cols['ip_type'] && 'America/Chicago' === $cols['timezone'] && 'en-US' === $cols['language'] );
	$check( 'every column is one the capture writes', array() === array_diff( array_keys( $cols ), Attribution::FACT_COLUMNS ) && array() === array_diff( Attribution::FACT_COLUMNS, array_keys( $cols ) ) );
	$preview = Attribution::clean( array( 'version' => 1, 'host' => 'shop.test', 'landing_query' => array( 'utm_source' => 'fb', 'utm_medium' => 'paid', 'utm_content' => '{{ad.id}}' ), 'referrer_host' => null ) )['value'];
	$pc      = Visit_Facts::columns( $preview, 'Mozilla/5.0', '10.0.0.1', Ip_Network::EMPTY );
	$check( 'ad preview macro is not valid and not a paid visit', 0 === $pc['params_valid'] && 'other' === $pc['source_class'] && '{{ad.id}}' === $pc['utm_content'] && '' === Visit_Facts::ad_id( $preview ) );
	$check( 'missing optional params are NULL, not empty', null === $pc['utm_term'] && null === $pc['ad_name'] && '' === $pc['creative_code'] );
	$untagged = Attribution::clean( array( 'version' => 1, 'host' => 'shop.test', 'landing_query' => array(), 'referrer_host' => 'l.instagram.com' ) )['value'];
	$check( 'no utm but from instagram = fb_untagged', 'fb_untagged' === Visit_Facts::columns( $untagged, 'Mozilla/5.0', '', array() )['source_class'] );
	$check( 'fbclid alone = fb_untagged', 'fb_untagged' === Visit_Facts::columns( array( 'landing_query' => array(), 'has_fbclid' => true ), 'Mozilla/5.0', '', array() )['source_class'] );
	$check( 'instagram in-app browser', 'ig' === Visit_Facts::columns( $untagged, 'Mozilla/5.0 Instagram 300.0', '', array() )['in_app'] );
	$check( 'direct = other', 'other' === Visit_Facts::columns( array( 'landing_query' => array(), 'referrer_host' => '' ), 'Mozilla/5.0', '', array() )['source_class'] );
	$check( 'bad ip not stored', '' === Visit_Facts::columns( $meta, '', 'not-an-ip', array() )['ip'] && '2001:db8::1' === Visit_Facts::columns( $meta, '', '2001:db8::1', array() )['ip'] );
	$check( 'bad network values fall back to empty', 0 === Visit_Facts::columns( $meta, '', '', array( 'asn' => -3, 'geo_city' => array() ) )['asn'] && '' === Visit_Facts::columns( $meta, '', '', array( 'geo_city' => array() ) )['geo_city'] );

	// ---------- 换广告开新访问 ----------
	$now  = 1_800_000_000_000;
	$last = array( 'last_ms' => $now - 60000, 'utm_content' => '120200000000003' );
	$check( 'same ad continues', Tracker::continues( $last, $now, '120200000000003' ) );
	$check( 'no ad continues', Tracker::continues( $last, $now, '' ) );
	$check( 'different valid ad opens a new visit', ! Tracker::continues( $last, $now, '120200000000999' ) );
	$check( 'ad arriving on an untagged visit opens a new visit', ! Tracker::continues( array( 'last_ms' => $now - 1000, 'utm_content' => null ), $now, '120200000000003' ) );
	$check( '30 minutes still applies', ! Tracker::continues( array( 'last_ms' => $now - Tracker::VISIT_GAP_MS - 1, 'utm_content' => '120200000000003' ), $now, '120200000000003' ) && ! Tracker::continues( null, $now, '' ) );
	$check( 'ad id only from a valid meta id', '120200000000003' === Visit_Facts::ad_id( $meta ) && '' === Visit_Facts::ad_id( array( 'landing_query' => array( 'utm_content' => 'Video 1' ) ) ) );

	// ---------- 广告首次出现 ----------
	$row = Visit_Facts::ad_row( $cols, $now );
	$check( 'ad row', array( 'ad_id' => '120200000000003', 'code' => 'C0012', 'ad_name' => $template['ad_name'], 'adset_id' => '120200000000002', 'campaign_id' => '120200000000001', 'first_seen_ms' => $now, 'last_seen_ms' => $now ) === $row );
	$check( 'no ad row for invalid ad id', null === Visit_Facts::ad_row( $pc, $now ) );
	$half = $cols;
	$half['utm_term'] = '{{adset.id}}';
	$check( 'invalid adset id left empty', '' === Visit_Facts::ad_row( $half, $now )['adset_id'] );
	$sql = Visit_Facts::ad_upsert_sql( 'wp_pc_ad_ads', $row );
	$check( 'upsert inserts the first sighting', str_starts_with( $sql, "INSERT INTO wp_pc_ad_ads (ad_id, code, ad_name, adset_id, campaign_id, first_seen_ms, last_seen_ms) VALUES ('120200000000003', 'C0012'" ) );
	$check( 'upsert never touches first_seen and updates last_seen last', ! str_contains( substr( $sql, strpos( $sql, 'ON DUPLICATE' ) ), 'first_seen_ms =' ) && str_ends_with( $sql, 'last_seen_ms = GREATEST(last_seen_ms, VALUES(last_seen_ms))' ) );
	$check( 'upsert takes the newest ad name only when newer', str_contains( $sql, "ad_name = CASE WHEN VALUES(ad_name) <> '' AND VALUES(last_seen_ms) >= last_seen_ms THEN VALUES(ad_name) ELSE ad_name END" ) );

	// ---------- 入口快照写入（假 $wpdb） ----------
	global $wpdb;
	$wpdb->queries = array();
	$receipt = Attribution::capture( 7, array( 'event_uid' => 'evt-00000001', 'visit_id' => 42, 'visitor_id' => 7, 'path' => '/', 'received_ms' => $now ), $meta, $cols );
	$sql     = $wpdb->queries[0];
	$check( 'capture applied', array( 'event_id' => 'evt-00000001', 'status' => 'applied', 'code' => 'captured', 'retry' => false ) === $receipt );
	$check( 'capture writes v5 columns in the same compare-and-set', str_contains( $sql, "utm_content = '120200000000003'" ) && str_contains( $sql, "creative_code = 'C0012'" ) && str_contains( $sql, 'has_fbclid = 1' ) && str_contains( $sql, "source_class = 'paid'" ) && str_contains( $sql, 'params_valid = 1' ) && str_contains( $sql, "ip = '203.0.113.9'" ) && str_contains( $sql, 'asn = 7922' ) && str_contains( $sql, "timezone = 'America/Chicago'" ) && str_contains( $sql, "in_app = 'fb'" ) && str_ends_with( $sql, 'WHERE id = 42 AND visitor_id = 7 AND attribution_version = 0' ) );
	$check( 'capture writes the full query', str_contains( $sql, "attribution_query = 'utm_source=fb&utm_medium=paid&utm_campaign=120200000000001" ) );
	$wpdb->queries = array();
	Attribution::capture( 7, array( 'event_uid' => 'evt-00000002', 'visit_id' => 43, 'visitor_id' => 7, 'path' => '/', 'received_ms' => $now ), $preview, $pc );
	$check( 'absent optional params written as NULL', str_contains( $wpdb->queries[0], 'utm_term = NULL' ) && str_contains( $wpdb->queries[0], 'ad_name = NULL' ) );
	$wpdb->queries = array();
	Attribution::capture( 7, array( 'event_uid' => 'evt-00000003', 'visit_id' => 44, 'visitor_id' => 7, 'path' => '/', 'received_ms' => $now ), $old );
	$check( 'capture without facts keeps the old statement', ! str_contains( $wpdb->queries[0], 'source_class' ) );

	// ---------- 访客级来源 + 广告表 ----------
	$wpdb->queries = array();
	Visit_Facts::after_capture( 7, 42, $cols, $now );
	$check( 'paid visit updates last_paid_visit_id and writes the ad', 2 === count( $wpdb->queries ) && 'UPDATE wp_pc_track_visitors SET last_paid_visit_id = GREATEST(last_paid_visit_id, 42) WHERE id = 7' === $wpdb->queries[0] && str_starts_with( $wpdb->queries[1], 'INSERT INTO wp_pc_ad_ads' ) );
	$wpdb->queries = array();
	Visit_Facts::after_capture( 7, 43, $pc, $now );
	$check( 'non-paid visit without valid ad writes nothing', array() === $wpdb->queries );
	$GLOBALS['pc_options'] = array();
	$wpdb->queries         = array();
	Visit_Facts::after_capture( 7, 42, $cols, $now );
	$check( 'creative tables not installed: only the visitor update', 1 === count( $wpdb->queries ) );
	$tracker_src = (string) file_get_contents( $inc . '/tracking/class-tracker.php' );
	$check( 'first_visit_id only written when 0', str_contains( $tracker_src, 'first_visit_id = CASE WHEN first_visit_id = 0 THEN %d ELSE first_visit_id END' ) );

	// ---------- IP 类型 ----------
	$types = array(
		array( 16509, 'Amazon.com, Inc.', '', 'hosting' ),
		array( 14061, 'DigitalOcean, LLC', '', 'hosting' ),
		array( 16276, 'OVH SAS', '', 'hosting' ),
		array( 396982, 'Google LLC', '', 'hosting' ),
		array( 9009, 'M247 Europe SRL', '', 'vpn' ),
		array( 99999, 'NordVPN S.A.', '', 'vpn' ),
		array( 21928, 'T-Mobile USA, Inc.', '', 'mobile' ),
		array( 99998, 'Telcel', '', 'mobile' ),
		array( 7922, 'Comcast Cable Communications, LLC', '', 'residential' ),
		array( 99997, 'Some Fiber Broadband', '', 'residential' ),
		array( 99996, 'Random Hosting Ltd', '', 'hosting' ),
		array( 99995, 'Acme Widgets', '', '' ),
		array( 0, '', '', '' ),
		array( 99994, 'Acme Widgets', 'Cellular', 'mobile' ),
		array( 99993, 'Acme Widgets', 'Cable/DSL', 'residential' ),
		array( 16509, 'Amazon', 'Cable/DSL', 'hosting' ),
	);
	foreach ( $types as $i => list( $asn, $isp, $conn, $want ) ) {
		$check( 'ip type ' . $i . ' ' . $isp, $want === Ip_Network::ip_type( $asn, $isp, $conn ) );
	}

	// ---------- 记录映射 ----------
	$city = array(
		'city'         => array( 'names' => array( 'en' => 'Austin', 'de' => 'Austin' ) ),
		'subdivisions' => array( array( 'iso_code' => 'TX', 'names' => array( 'en' => 'Texas' ) ) ),
		'location'     => array( 'latitude' => 30.26715, 'longitude' => -97.74306 ),
	);
	$asn  = array( 'autonomous_system_number' => 7922, 'autonomous_system_organization' => 'Comcast Cable Communications, LLC' );
	$m    = Ip_Network::from_records( $city, $asn );
	$check( 'records mapped', array( 'geo_region' => 'Texas', 'geo_city' => 'Austin', 'geo_lat' => '30.2672', 'geo_lon' => '-97.7431', 'isp' => 'Comcast Cable Communications, LLC', 'asn' => 7922, 'ip_type' => 'residential' ) === $m );
	$check( 'no records = empty', Ip_Network::EMPTY === Ip_Network::from_records( null, null ) );
	$check( 'region falls back to iso code, bad location ignored', 'TX' === Ip_Network::from_records( array( 'subdivisions' => array( array( 'iso_code' => 'TX' ) ), 'location' => array( 'latitude' => 200, 'longitude' => 0 ) ), null )['geo_region'] && '' === Ip_Network::from_records( array( 'location' => array( 'latitude' => 200, 'longitude' => 0 ) ), null )['geo_lat'] );
	$check( 'paid ISP database fields win', 'mobile' === Ip_Network::from_records( null, array( 'autonomous_system_number' => 5, 'isp' => 'Acme', 'connection_type' => 'Cellular' ) )['ip_type'] );
	$check( 'control characters stripped and long names cut', 120 === mb_strlen( Ip_Network::from_records( null, array( 'autonomous_system_organization' => str_repeat( 'x', 200 ) ) )['isp'] ) && 'A B' === Ip_Network::from_records( array( 'city' => array( 'names' => array( 'en' => "A\x00B" ) ) ), null )['geo_city'] );

	// ---------- 没有库时留空、不报错 ----------
	$check( 'private address not looked up', Ip_Network::EMPTY === Ip_Network::lookup( '192.168.1.5' ) && Ip_Network::EMPTY === Ip_Network::lookup( '127.0.0.1' ) && Ip_Network::EMPTY === Ip_Network::lookup( 'junk' ) );
	$check( 'no databases configured: empty', array( 'city' => null, 'asn' => null ) === Ip_Network::databases() && Ip_Network::EMPTY === Ip_Network::lookup( '8.8.8.8' ) );

	// ---------- 真实 Reader + 生成的小 mmdb（本机有 WooCommerce 源码时） ----------
	$wc = getenv( 'PC_TEST_WC_DIR' ) ?: '';
	for ( $up = dirname( __DIR__ ); '' === $wc && dirname( $up ) !== $up; $up = dirname( $up ) ) {
		if ( is_dir( $up . '/tools/wc-src/woocommerce' ) ) { $wc = $up . '/tools/wc-src/woocommerce'; }
	}
	if ( is_file( $wc . '/vendor/maxmind-db/reader/src/MaxMind/Db/Reader.php' ) ) {
		define( 'WC_ABSPATH', rtrim( $wc, '/' ) . '/' );
		$dir = sys_get_temp_dir() . '/pc-cr-mmdb-' . getmypid();
		@mkdir( $dir );
		file_put_contents( $dir . '/city.mmdb', pc_test_mmdb( 'GeoLite2-City', $city ) );
		file_put_contents( $dir . '/asn.mmdb', pc_test_mmdb( 'GeoLite2-ASN', $asn ) );
		define( 'PC_DATA_GEOIP_CITY_DATABASE', $dir . '/city.mmdb' );
		define( 'PC_DATA_GEOIP_ASN_DATABASE', $dir . '/asn.mmdb' );
		$check( 'configured databases found', array( 'city' => $dir . '/city.mmdb', 'asn' => $dir . '/asn.mmdb' ) === Ip_Network::databases() );
		$check( 'real reader lookup 8.8.8.8', $m === Ip_Network::lookup( '8.8.8.8' ) );
		$check( 'address outside the database stays empty', Ip_Network::EMPTY === Ip_Network::lookup( '9.9.9.9' ) );
		array_map( 'unlink', glob( $dir . '/*' ) );
		rmdir( $dir );
		echo "mmdb: real MaxMind Reader exercised\n";
	} else {
		echo "mmdb: WooCommerce source not found, real Reader lookup skipped\n";
	}

	echo "TOTAL {$pass} PASS, 0 FAIL\n";

	/** 生成只含 8.0.0.0/8 一条记录的 IPv4 mmdb（24 位记录），用来测真实 Reader。 */
	function pc_test_mmdb( string $type, array $record ): string {
		$nodes = 8;
		$data  = pc_mm_encode( $record );
		$tree  = '';
		$bits  = '00001000';
		for ( $i = 0; $i < 8; $i++ ) {
			$next  = 7 === $i ? $nodes + 16 : $i + 1; // 最后一位指向数据区开头。
			$left  = '0' === $bits[ $i ] ? $next : $nodes;
			$right = '1' === $bits[ $i ] ? $next : $nodes;
			$tree .= substr( pack( 'N', $left ), 1 ) . substr( pack( 'N', $right ), 1 );
		}
		$meta = pc_mm_encode( array( 'binary_format_major_version' => 2, 'binary_format_minor_version' => 0, 'build_epoch' => 1700000000, 'database_type' => $type, 'description' => array( 'en' => 'test' ), 'ip_version' => 4, 'languages' => array( 'en' ), 'node_count' => $nodes, 'record_size' => 24 ) );
		return $tree . str_repeat( "\0", 16 ) . $data . "\xAB\xCD\xEFMaxMind.com" . $meta;
	}

	function pc_mm_ctrl( int $type, int $size ): string {
		$ext = $type > 7;
		$b   = ( $ext ? 0 : $type << 5 );
		$out = $size < 29 ? chr( $b | $size ) : chr( $b | 29 ) . chr( $size - 29 );
		return $ext ? $out[0] . chr( $type - 7 ) . substr( $out, 1 ) : $out;
	}

	function pc_mm_encode( $v ): string {
		if ( is_array( $v ) && array_is_list( $v ) && $v ) {
			return pc_mm_ctrl( 11, count( $v ) ) . implode( '', array_map( 'pc_mm_encode', $v ) );
		}
		if ( is_array( $v ) ) {
			$out = pc_mm_ctrl( 7, count( $v ) );
			foreach ( $v as $k => $x ) { $out .= pc_mm_encode( (string) $k ) . pc_mm_encode( $x ); }
			return $out;
		}
		if ( is_float( $v ) ) {
			return pc_mm_ctrl( 3, 8 ) . pack( 'E', $v );
		}
		if ( is_int( $v ) ) {
			$bytes = ltrim( pack( 'N', $v ), "\0" );
			return pc_mm_ctrl( 6, strlen( $bytes ) ) . $bytes;
		}
		return pc_mm_ctrl( 2, strlen( (string) $v ) ) . $v;
	}
}
