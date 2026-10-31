<?php
/** Management-only accounts. Credentials never appear in the public methods policy. */
namespace VialCove\Panapay;

defined( 'ABSPATH' ) || exit;

final class Hub_Accounts {

	public const OPTION = 'pc_panapay_hub_accounts';
	private const LIMIT = 20;

	/** Encrypted raw settings, including archived identities and the current edit revision. */
	public static function all(): array {
		$book = self::snapshot()['book'];
		$out = $book['accounts'];
		foreach ( $out as &$row ) { $row['revision'] = $book['revision']; }
		unset( $row );
		return $out;
	}

	public static function revision(): int { return self::snapshot()['book']['revision']; }

	/** An explicit upgrade can commit the virtual legacy account without changing its flags. */
	public static function migrate(): void {
		$snapshot = self::snapshot();
		if ( null !== $snapshot['serialized'] ) { return; }
		try { self::commit( $snapshot, $snapshot['book']['accounts'] ); }
		catch ( \RuntimeException $error ) {
			if ( 'Payment settings changed. Reload before saving again.' === $error->getMessage() && null !== self::snapshot()['serialized'] ) { return; }
			throw $error;
		}
	}

	/** Server-side only: includes the decrypted selected merchant configuration. */
	public static function load( string $id = 'default' ): array {
		$all = self::all();
		if ( ! isset( $all[ $id ] ) || ! self::valid_id( $id ) ) { throw new \InvalidArgumentException( 'Payment account was not found.' ); }
		return self::configuration( $all[ $id ] );
	}

	/** Archived identities remain registered for existing receipts, but cannot start payments. */
	public static function policy( int $site_id ): array {
		$methods = array();
		foreach ( self::all() as $row ) {
			$methods[] = array_merge( array( 'account_id' => $row['id'] ), self::public_method( $row, $site_id ) );
		}
		return array( 'version' => 1, 'methods' => $methods );
	}

	public static function legacy_policy( int $site_id ): array {
		$all = self::all();
		if ( ! isset( $all['default'] ) ) { throw new \RuntimeException( 'Payment settings cannot be read.' ); }
		$policy = self::public_method( $all['default'], $site_id );
		// Old clients prefer a successful CONFIG response to their saved trust snapshot,
		// even when collection is off. Preserve that route's original trust contract.
		// A strict parse failure is intentionally surfaced there so their saved-host
		// continuation fallback remains available; METHODS stays isolated per account.
		$config = Configuration::from_settings( $all['default'], true );
		$hosts = $config['payment_hosts'];
		$host = '' !== $config['gateway'] ? wp_parse_url( $config['gateway'], PHP_URL_HOST ) : null;
		if ( is_string( $host ) && '' !== $host ) { $hosts[] = strtolower( $host ); }
		$hosts = array_values( array_unique( $hosts ) );
		if ( count( $hosts ) > 32 ) { throw new \RuntimeException( 'Payment trusted domains exceed the limit.' ); }
		$policy['payment_hosts'] = $hosts;
		return $policy;
	}

	/** Unslashed, authenticated form input. Revisions are mandatory for edits and additions. */
	public static function save( array $input ): string {
		$snapshot = self::snapshot();
		self::guard_revision( $snapshot['book']['revision'], $input['revision'] ?? null );
		if ( isset( $input['account_id'], $input['id'] ) && $input['account_id'] !== $input['id'] ) { throw new \InvalidArgumentException( 'Payment account was not found.' ); }
		$id = $input['account_id'] ?? $input['id'] ?? '';
		if ( ! is_string( $id ) || ( '' !== $id && ! self::valid_id( $id ) ) ) { throw new \InvalidArgumentException( 'Payment account was not found.' ); }
		$accounts = $snapshot['book']['accounts'];
		if ( '' === $id ) {
			if ( count( $accounts ) >= self::LIMIT ) { throw new \InvalidArgumentException( 'The payment account limit has been reached.' ); }
			do { $id = bin2hex( random_bytes( 16 ) ); } while ( isset( $accounts[ $id ] ) );
			$candidate = self::defaults( $id );
		} else {
			if ( ! isset( $accounts[ $id ] ) ) { throw new \InvalidArgumentException( 'Payment account was not found.' ); }
			$candidate = $accounts[ $id ];
			if ( self::flag( $candidate['archived'] ) ) { throw new \InvalidArgumentException( 'Archived payment accounts cannot be edited.' ); }
		}
		$candidate = self::candidate( $candidate, $input );
		$accounts[ $id ] = $candidate;
		self::commit( $snapshot, $accounts );
		return $id;
	}

	public static function toggle( string $id, bool $enabled, ?int $revision = null ): void {
		$snapshot = self::snapshot();
		if ( null !== $revision ) { self::guard_revision( $snapshot['book']['revision'], $revision ); }
		$accounts = $snapshot['book']['accounts'];
		if ( ! self::valid_id( $id ) || ! isset( $accounts[ $id ] ) ) { throw new \InvalidArgumentException( 'Payment account was not found.' ); }
		if ( self::flag( $accounts[ $id ]['archived'] ) ) { throw new \InvalidArgumentException( 'Archived payment accounts cannot be edited.' ); }
		$accounts[ $id ]['enabled'] = $enabled ? 'yes' : 'no';
		// Disabling must remain possible even if the provider is currently unavailable.
		if ( $enabled ) { self::validate_enabled( $accounts[ $id ], self::configuration( $accounts[ $id ] ) ); }
		self::commit( $snapshot, $accounts );
	}

	public static function archive( string $id, ?int $revision = null ): void {
		$snapshot = self::snapshot();
		if ( null !== $revision ) { self::guard_revision( $snapshot['book']['revision'], $revision ); }
		$accounts = $snapshot['book']['accounts'];
		if ( ! self::valid_id( $id ) || ! isset( $accounts[ $id ] ) ) { throw new \InvalidArgumentException( 'Payment account was not found.' ); }
		$accounts[ $id ]['enabled'] = 'no';
		$accounts[ $id ]['archived'] = 'yes';
		self::commit( $snapshot, $accounts );
	}

	public static function defaults( string $id = '' ): array {
		return array( 'id' => $id, 'protocol' => 'panapay', 'account_name' => '', 'enabled' => 'no', 'title' => 'Credit card',
			'description' => 'Continue to the secure payment page to pay for your order.', 'gateway' => '', 'mchid' => '', 'secret' => '',
			'mode' => '2', 'tag' => '', 'test_mode' => 'yes', 'payment_hosts' => array(), 'payment_icons' => array(), 'iframe_height' => 530,
			'all_sites' => 'yes', 'sites' => array(), 'archived' => 'no' );
	}

	private static function configuration( array $raw ): array {
		$config = Configuration::from_settings( $raw, 'default' === $raw['id'] );
		foreach ( array( 'enabled', 'all_sites', 'archived' ) as $key ) { $config[ $key ] = self::flag( $raw[ $key ] ); }
		$config['sites'] = self::site_ids( $raw['sites'] );
		$config['id'] = $raw['id'];
		$config['account_id'] = $raw['id'];
		$config['protocol'] = 'panapay';
		$config['account_name'] = self::plain( $raw['account_name'], 120 );
		$config['title'] = self::plain( $raw['title'], 120 );
		$config['description'] = self::plain( $raw['description'], 1000 );
		$config['payment_icons'] = self::icons( $raw['payment_icons'] );
		$config['iframe_height'] = self::height( $raw['iframe_height'] );
		$config['revision'] = $raw['revision'] ?? 0;
		return $config;
	}

	private static function public_method( array $row, int $site_id ): array {
		// Display registration must survive a retired merchant's broken key or DNS.
		// Host trust is published only after the selected, eligible configuration passes.
		$method = array( 'enabled' => false, 'title' => 'Credit card', 'description' => '', 'test_mode' => true,
			'payment_hosts' => array(), 'payment_icons' => array(), 'iframe_height' => 530 );
		$valid = true;
		$display = array(
			'title' => static function ( $value ) { $title = self::plain( $value, 120 ); if ( '' === $title ) { throw new \InvalidArgumentException(); } return $title; },
			'description' => static function ( $value ) { return self::plain( $value, 1000 ); },
			'test_mode' => static function ( $value ) { return self::flag( $value ); },
			'payment_icons' => static function ( $value ) { return self::icons( $value ); },
			'iframe_height' => static function ( $value ) { return self::height( $value ); },
		);
		foreach ( $display as $key => $sanitize ) {
			try { $method[ $key ] = $sanitize( $row[ $key ] ); }
			catch ( \Throwable $error ) { $valid = false; }
		}
		try {
			if ( ! $valid || $site_id < 1 || self::flag( $row['archived'] ) || ! self::flag( $row['enabled'] )
				|| ( ! self::flag( $row['all_sites'] ) && ! in_array( $site_id, self::site_ids( $row['sites'] ), true ) ) ) { return $method; }
			$config = self::configuration( $row );
			self::validate_enabled( $row, $config );
			$method['payment_hosts'] = self::trusted_hosts( $config );
			$method['enabled'] = true;
		} catch ( \Throwable $error ) {
			// One unusable account fails closed without removing healthy payment methods.
		}
		return $method;
	}

	private static function candidate( array $candidate, array $input ): array {
		if ( isset( $input['protocol'] ) && 'panapay' !== $input['protocol'] ) { throw new \InvalidArgumentException( 'Payment protocol is invalid.' ); }
		foreach ( array( 'enabled', 'test_mode', 'all_sites' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) { $candidate[ $key ] = self::flag( $input[ $key ] ) ? 'yes' : 'no'; }
		}
		foreach ( array( 'gateway', 'mchid', 'tag', 'mode' ) as $key ) {
			if ( ! array_key_exists( $key, $input ) ) { continue; }
			if ( ! is_string( $input[ $key ] ) ) { throw new \InvalidArgumentException( 'Payment settings are invalid.' ); }
			if ( 'default' === $candidate['id'] && in_array( $key, array( 'gateway', 'mchid' ), true ) && Hub_Settings::server_override( 'PC_PANAPAY_' . strtoupper( $key ) ) ) { continue; }
			$candidate[ $key ] = trim( $input[ $key ] );
		}
		foreach ( array( 'account_name' => 120, 'title' => 120, 'description' => 1000 ) as $key => $limit ) {
			if ( array_key_exists( $key, $input ) ) { $candidate[ $key ] = self::plain( $input[ $key ], $limit ); }
		}
		if ( '' === $candidate['title'] ) { throw new \InvalidArgumentException( 'Enter the payment method name.' ); }
		if ( array_key_exists( 'payment_hosts', $input ) ) {
			if ( ! is_string( $input['payment_hosts'] ) && ! is_array( $input['payment_hosts'] ) ) { throw new \InvalidArgumentException( 'Payment settings are invalid.' ); }
			$candidate['payment_hosts'] = $input['payment_hosts'];
		}
		if ( array_key_exists( 'sites', $input ) ) { $candidate['sites'] = self::site_ids( $input['sites'] ); }
		$registered = \PeptideHq\Sites::all();
		foreach ( $candidate['sites'] as $site_id ) {
			if ( ! isset( $registered[ $site_id ] ) ) { throw new \InvalidArgumentException( 'Store authorization changed. Reload and select the stores again.' ); }
		}
		if ( array_key_exists( 'payment_icons_present', $input ) && ! in_array( $input['payment_icons_present'], array( '1', 1 ), true ) ) { throw new \InvalidArgumentException( 'Payment display icons are invalid.' ); }
		if ( array_key_exists( 'payment_icons', $input ) ) { $candidate['payment_icons'] = self::icons( $input['payment_icons'] ); }
		elseif ( isset( $input['payment_icons_present'] ) ) { $candidate['payment_icons'] = array(); }
		if ( array_key_exists( 'iframe_height', $input ) ) { $candidate['iframe_height'] = self::height( $input['iframe_height'] ); }
		if ( array_key_exists( 'secret', $input ) ) {
			if ( ! is_string( $input['secret'] ) || strlen( $input['secret'] ) > 1024 || preg_match( '/[\x00-\x1f\x7f]/', $input['secret'] ) ) { throw new \InvalidArgumentException( 'Payment signing settings are invalid.' ); }
			if ( '' !== $input['secret'] && ! ( 'default' === $candidate['id'] && Hub_Settings::server_override( 'PC_PANAPAY_SECRET' ) ) ) { $candidate['secret'] = Crypto::encrypt( $input['secret'], 'secret' ); }
		}
		$config = self::configuration( $candidate );
		$candidate['gateway'] = 'default' === $candidate['id'] && Hub_Settings::server_override( 'PC_PANAPAY_GATEWAY' ) ? $candidate['gateway'] : $config['gateway'];
		$candidate['payment_hosts'] = $config['payment_hosts'];
		if ( $config['enabled'] ) { self::validate_enabled( $candidate, $config ); }
		return array_intersect_key( $candidate, self::defaults( $candidate['id'] ) );
	}

	private static function validate_enabled( array $raw, array $config ): void {
		if ( '' === $config['gateway'] || '' === $config['mchid'] || '' === $config['secret'] ) { throw new \InvalidArgumentException( 'Complete the gateway, merchant and signing settings before enabling payment.' ); }
		if ( count( self::trusted_hosts( $config ) ) > 32 ) { throw new \InvalidArgumentException( 'Payment host trust must contain no more than 32 unique hosts including the gateway.' ); }
		if ( ! $config['all_sites'] && ! $config['sites'] ) { throw new \InvalidArgumentException( 'Select at least one store before enabling payment.' ); }
		if ( $config['test_mode'] && 'production' === wp_get_environment_type() ) { throw new \InvalidArgumentException( 'Test payments cannot be enabled in production.' ); }
		if ( ! $config['test_mode'] && 'https' !== wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) ) { throw new \InvalidArgumentException( 'Use HTTPS on the management site before enabling live payment.' ); }
	}

	private static function trusted_hosts( array $config ): array {
		$hosts = $config['payment_hosts'];
		$host = wp_parse_url( $config['gateway'], PHP_URL_HOST );
		if ( is_string( $host ) && '' !== $host ) { $hosts[] = strtolower( $host ); }
		return array_values( array_unique( $hosts ) );
	}

	private static function snapshot(): array {
		$db = Database::open();
		try {
			$serialized = $db->get_var( $db->prepare( "SELECT option_value FROM {$db->options} WHERE option_name=%s", self::OPTION ) );
			if ( '' !== (string) $db->last_error ) { throw new \RuntimeException( 'Payment settings cannot be read.' ); }
		} finally { $db->close(); }
		if ( null === $serialized ) {
			$legacy = array_intersect_key( Hub_Settings::raw(), self::defaults( 'default' ) );
			$legacy = array_merge( self::defaults( 'default' ), $legacy, array( 'id' => 'default', 'protocol' => 'panapay', 'all_sites' => 'no', 'archived' => 'no' ) );
			return array( 'serialized' => null, 'book' => array( 'version' => 1, 'revision' => 0, 'accounts' => array( 'default' => $legacy ) ) );
		}
		$book = @unserialize( $serialized, array( 'allowed_classes' => false ) );
		if ( ! is_array( $book ) || 1 !== ( $book['version'] ?? null ) || ! is_int( $book['revision'] ?? null ) || $book['revision'] < 1
			|| ! is_array( $book['accounts'] ?? null ) || count( $book['accounts'] ) > self::LIMIT || ! isset( $book['accounts']['default'] ) ) { throw new \RuntimeException( 'Payment settings cannot be read.' ); }
		foreach ( $book['accounts'] as $id => &$raw ) {
			if ( ! is_string( $id ) || ! self::valid_id( $id ) || ! is_array( $raw ) || $id !== ( $raw['id'] ?? null ) || 'panapay' !== ( $raw['protocol'] ?? null ) ) { throw new \RuntimeException( 'Payment settings cannot be read.' ); }
			$raw = array_merge( self::defaults( $id ), array_intersect_key( $raw, self::defaults( $id ) ) );
		}
		unset( $raw );
		return array( 'serialized' => $serialized, 'book' => $book );
	}

	/** A dedicated autocommit connection keeps edits out of unrelated request transactions. */
	private static function commit( array $snapshot, array $accounts ): void {
		$next = serialize( array( 'version' => 1, 'revision' => $snapshot['book']['revision'] + 1, 'accounts' => $accounts ) );
		$db = Database::open();
		try {
			if ( null === $snapshot['serialized'] ) {
				$changed = $db->query( $db->prepare( "INSERT IGNORE INTO {$db->options} (option_name,option_value,autoload) VALUES (%s,%s,'no')", self::OPTION, $next ) );
			} else {
				$changed = $db->query( $db->prepare( "UPDATE {$db->options} SET option_value=%s,autoload='no' WHERE option_name=%s AND BINARY option_value=BINARY %s", $next, self::OPTION, $snapshot['serialized'] ) );
			}
			if ( false === $changed || '' !== (string) $db->last_error ) { throw new \RuntimeException( 'Payment settings were not saved.' ); }
			if ( 1 !== $changed ) { throw new \RuntimeException( 'Payment settings changed. Reload before saving again.' ); }
		} finally { $db->close(); }
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	private static function guard_revision( int $current, $value ): void {
		if ( ! is_int( $value ) && ( ! is_string( $value ) || ! preg_match( '/\A(?:0|[1-9][0-9]{0,14})\z/', $value ) ) ) { throw new \InvalidArgumentException( 'Payment settings changed. Reload before saving again.' ); }
		if ( (string) $current !== (string) $value ) { throw new \InvalidArgumentException( 'Payment settings changed. Reload before saving again.' ); }
	}
	private static function valid_id( string $id ): bool { return 'default' === $id || 1 === preg_match( '/\A[a-f0-9]{32}\z/', $id ); }
	private static function flag( $value ): bool {
		if ( ! in_array( $value, array( true, false, 'yes', 'no', '1', '0', 1, 0, '' ), true ) ) { throw new \InvalidArgumentException( 'Payment settings are invalid.' ); }
		return in_array( $value, array( true, 'yes', '1', 1 ), true );
	}
	private static function plain( $value, int $limit ): string {
		if ( ! is_string( $value ) || mb_strlen( $value ) > $limit ) { throw new \InvalidArgumentException( 'Payment display text is invalid.' ); }
		return trim( wp_strip_all_tags( $value, true ) );
	}
	private static function icons( $value ): array {
		if ( ! is_array( $value ) || count( $value ) > 6 ) { throw new \InvalidArgumentException( 'Payment display icons are invalid.' ); }
		$out = array();
		foreach ( $value as $id ) {
			if ( ! is_string( $id ) || ! isset( Payment_Icons::catalog()[ $id ] ) ) { throw new \InvalidArgumentException( 'Payment display icons are invalid.' ); }
			if ( ! in_array( $id, $out, true ) ) { $out[] = $id; }
		}
		return $out;
	}
	private static function height( $value ): int {
		if ( ! is_int( $value ) && ( ! is_string( $value ) || ! preg_match( '/\A[0-9]{3,4}\z/', $value ) ) ) { throw new \InvalidArgumentException( 'Payment frame height must be an integer from 320 to 1600.' ); }
		$value = (int) $value;
		if ( $value < 320 || $value > 1600 ) { throw new \InvalidArgumentException( 'Payment frame height must be an integer from 320 to 1600.' ); }
		return $value;
	}
	private static function site_ids( $value ): array {
		if ( ! is_array( $value ) || count( $value ) > 1000 ) { throw new \InvalidArgumentException( 'Store authorization is invalid.' ); }
		$out = array();
		foreach ( $value as $id ) {
			if ( ( ! is_int( $id ) && ! is_string( $id ) ) || ! preg_match( '/\A[1-9][0-9]{0,18}\z/', (string) $id ) || (string) (int) $id !== (string) $id ) { throw new \InvalidArgumentException( 'Store authorization is invalid.' ); }
			$out[] = (int) $id;
		}
		sort( $out, SORT_NUMERIC );
		return array_values( array_unique( $out ) );
	}
}
