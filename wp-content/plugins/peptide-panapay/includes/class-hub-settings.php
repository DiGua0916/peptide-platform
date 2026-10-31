<?php
/** Provider settings live only on the management WordPress instance. */
namespace VialCove\Panapay;

defined( 'ABSPATH' ) || exit;

final class Hub_Settings {

	public const OPTION = 'pc_panapay_hub_settings';

	public static function raw(): array {
		$value = get_option( self::OPTION, array() );
		if ( ! is_array( $value ) ) { throw new \RuntimeException( 'Payment settings cannot be read.' ); }
		return array_merge( array(
			'enabled' => 'no', 'gateway' => '', 'mchid' => '', 'secret' => '', 'mode' => '2', 'tag' => '',
			'test_mode' => 'yes', 'payment_hosts' => array(), 'sites' => array(),
			'title' => 'Credit card', 'description' => 'Continue to the secure payment page to pay for your order.',
		), $value );
	}

	public static function load(): array {
		$settings = self::raw();
		$config = Configuration::from_settings( $settings );
		$config['enabled'] = self::flag( $settings['enabled'] );
		$config['sites'] = self::site_ids( $settings['sites'] );
		$config['title'] = self::plain( $settings['title'], 120 );
		$config['description'] = self::plain( $settings['description'], 1000 );
		if ( '' === $config['title'] ) { $config['title'] = 'Credit card'; }
		return $config;
	}

	/** New registrations are not automatically authorized to create payments. */
	public static function allows_site( int $site_id ): bool {
		return $site_id > 0 && in_array( $site_id, self::site_ids( self::raw()['sites'] ), true );
	}

	/** Public policy contains no provider credentials or customer information. */
	public static function policy( int $site_id ): array {
		$config = self::load();
		$hosts = $config['payment_hosts'];
		$host = '' !== $config['gateway'] ? wp_parse_url( $config['gateway'], PHP_URL_HOST ) : null;
		if ( is_string( $host ) && '' !== $host ) { $hosts[] = strtolower( $host ); }
		$ready = $config['enabled'] && self::allows_site( $site_id ) && '' !== $config['gateway'] && '' !== $config['mchid'] && '' !== $config['secret'];
		if ( $config['test_mode'] && 'production' === wp_get_environment_type() ) { $ready = false; }
		if ( ! $config['test_mode'] && 'https' !== wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) ) { $ready = false; }
		return array(
			'enabled' => $ready, 'title' => $config['title'], 'description' => $config['description'],
			'test_mode' => $config['test_mode'], 'payment_hosts' => array_values( array_unique( $hosts ) ),
		);
	}

	/** Input is unslashed once by the authenticated admin handler. */
	public static function save( array $input ): void {
		$candidate = self::raw();
		$candidate['enabled'] = ! empty( $input['enabled'] ) ? 'yes' : 'no';
		$candidate['test_mode'] = ! empty( $input['test_mode'] ) ? 'yes' : 'no';
		foreach ( array( 'gateway', 'mchid', 'tag', 'mode', 'title', 'description', 'payment_hosts' ) as $name ) {
			if ( ! array_key_exists( $name, $input ) ) { continue; }
			if ( ! is_string( $input[ $name ] ) ) { throw new \InvalidArgumentException( 'Payment settings are invalid.' ); }
			if ( in_array( $name, array( 'gateway', 'mchid' ), true ) && self::server_override( 'PC_PANAPAY_' . strtoupper( $name ) ) ) { continue; }
			$candidate[ $name ] = trim( $input[ $name ] );
		}
		if ( array_key_exists( 'secret', $input ) ) {
			if ( ! is_string( $input['secret'] ) || strlen( $input['secret'] ) > 1024 || preg_match( '/[\x00-\x1f\x7f]/', $input['secret'] ) ) {
				throw new \InvalidArgumentException( 'Payment signing settings are invalid.' );
			}
			if ( '' !== $input['secret'] && ! self::server_override( 'PC_PANAPAY_SECRET' ) ) {
				$candidate['secret'] = Crypto::encrypt( $input['secret'], 'secret' );
			}
		}
		$candidate['sites'] = self::site_ids( $input['sites'] ?? array() );
		$registered = \PeptideHq\Sites::all();
		foreach ( $candidate['sites'] as $id ) {
			if ( ! isset( $registered[ $id ] ) ) { throw new \InvalidArgumentException( 'Store authorization changed. Reload and select the stores again.' ); }
		}
		$candidate['title'] = self::plain( $candidate['title'], 120 );
		$candidate['description'] = self::plain( $candidate['description'], 1000 );
		if ( '' === $candidate['title'] ) { throw new \InvalidArgumentException( 'Enter the payment method name.' ); }
		$config = Configuration::from_settings( $candidate );
		if ( 'yes' === $candidate['enabled'] ) {
			if ( '' === $config['gateway'] || '' === $config['mchid'] || '' === $config['secret'] ) {
				throw new \InvalidArgumentException( 'Complete the gateway, merchant and signing settings before enabling payment.' );
			}
			if ( ! $candidate['sites'] ) { throw new \InvalidArgumentException( 'Select at least one store before enabling payment.' ); }
			if ( $config['test_mode'] && 'production' === wp_get_environment_type() ) { throw new \InvalidArgumentException( 'Test payments cannot be enabled in production.' ); }
			if ( ! $config['test_mode'] && 'https' !== wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) ) {
				throw new \InvalidArgumentException( 'Use HTTPS on the management site before enabling live payment.' );
			}
		}
		// Keep only this plugin's known settings; never save raw POST or plaintext credentials.
		$names = array( 'enabled', 'gateway', 'mchid', 'secret', 'mode', 'tag', 'test_mode', 'payment_hosts', 'sites', 'title', 'description' );
		$candidate = array_intersect_key( $candidate, array_flip( $names ) );
		if ( ! update_option( self::OPTION, $candidate, false ) && get_option( self::OPTION ) !== $candidate ) {
			throw new \RuntimeException( 'Payment settings were not saved.' );
		}
	}

	public static function server_override( string $name ): bool {
		if ( ! in_array( $name, array( 'PC_PANAPAY_GATEWAY', 'PC_PANAPAY_MCHID', 'PC_PANAPAY_SECRET' ), true ) ) { return false; }
		return defined( $name ) || false !== getenv( $name );
	}

	private static function flag( $value ): bool {
		if ( ! in_array( $value, array( true, false, 'yes', 'no', '1', '0', 1, 0, '' ), true ) ) { throw new \RuntimeException( 'Payment settings are invalid.' ); }
		return in_array( $value, array( true, 'yes', '1', 1 ), true );
	}

	private static function plain( $value, int $maximum ): string {
		if ( ! is_string( $value ) || mb_strlen( $value ) > $maximum ) { throw new \InvalidArgumentException( 'Payment display text is invalid.' ); }
		return trim( wp_strip_all_tags( $value, true ) );
	}

	private static function site_ids( $value ): array {
		if ( ! is_array( $value ) || count( $value ) > 1000 ) { throw new \InvalidArgumentException( 'Store authorization is invalid.' ); }
		$out = array();
		foreach ( $value as $id ) {
			if ( ( ! is_string( $id ) && ! is_int( $id ) ) || ! preg_match( '/^[1-9][0-9]{0,18}$/D', (string) $id )
				|| (string) (int) $id !== (string) $id ) { throw new \InvalidArgumentException( 'Store authorization is invalid.' ); }
			$out[] = (int) $id;
		}
		sort( $out, SORT_NUMERIC );
		return array_values( array_unique( $out ) );
	}
}
