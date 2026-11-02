<?php
/** Run directly with PHP. Pure configuration checks; no WordPress/database writes. */
define( 'ABSPATH', __DIR__ . '/' );
final class WP_Error {
	public function __construct( public string $code, public string $message, public array $data ) {}
	public function get_error_data(): array { return $this->data; }
}
require dirname( __DIR__ ) . '/includes/cart_feedback/class-config.php';
use PeptideCore\Cart_Feedback\Config;

$checks = array();
$check = static function ( string $name, bool $pass ) use ( &$checks ): void {
	$checks[] = array( 'name' => $name, 'pass' => $pass );
};
$bad_field = static function ( $result, string $field ): bool {
	return $result instanceof WP_Error && isset( $result->get_error_data()['fields'][ $field ] );
};
$defaults = Config::defaults();
$check( 'new_defaults_have_enabled_850ms_burst', true === $defaults['burstEnabled'] && 850 === $defaults['burstDurationMs'] );
$check( 'default_keys_match_full_contract', array_keys( $defaults ) === Config::KEYS && 8 === count( Config::KEYS ) );
$check( 'complete_defaults_normalize', Config::normalize( $defaults ) === $defaults );
foreach ( array( 600, 850, 1000 ) as $duration ) {
	$input = array_replace( $defaults, array( 'burstDurationMs' => $duration, 'burstEnabled' => false ) );
	$check( 'integer_duration_accepted_' . $duration, Config::normalize( $input ) === $input );
}
foreach ( array( 599, 1001, -1, 0, 850.5, 850.0, '850', null, true, array() ) as $i => $duration ) {
	$check( 'invalid_duration_rejected_' . $i, $bad_field( Config::normalize( array_replace( $defaults, array( 'burstDurationMs' => $duration ) ) ), 'burstDurationMs' ) );
}
foreach ( array( 0, 1, 'true', null, array() ) as $i => $enabled ) {
	$check( 'nonboolean_burst_rejected_' . $i, $bad_field( Config::normalize( array_replace( $defaults, array( 'burstEnabled' => $enabled ) ) ), 'burstEnabled' ) );
}
$legacy = $defaults;
unset( $legacy['burstEnabled'], $legacy['burstDurationMs'] );
$check( 'legacy_is_not_valid_for_new_write', $bad_field( Config::normalize( $legacy ), 'burstEnabled' ) );
$check( 'legacy_storage_upgrades_only_in_memory', Config::normalize_stored( $legacy ) === $defaults && 6 === count( $legacy ) );
foreach ( array( 'burstEnabled', 'burstDurationMs' ) as $key ) {
	$partial = $defaults;
	unset( $partial[ $key ] );
	$check( 'partial_new_storage_rejected_' . $key, $bad_field( Config::normalize_stored( $partial ), $key ) );
}
foreach ( array_keys( $legacy ) as $key ) {
	$damaged = $legacy;
	unset( $damaged[ $key ] );
	$check( 'damaged_legacy_storage_rejected_' . $key, $bad_field( Config::normalize_stored( $damaged ), $key ) );
}
$unknown = $legacy;
$unknown['customerId'] = 1;
$check( 'legacy_unknown_fields_not_silently_removed', $bad_field( Config::normalize_stored( $unknown ), 'customerId' ) );
$off = array_replace( $defaults, array( 'enabled' => false, 'burstEnabled' => false, 'burstDurationMs' => 1000 ) );
$check( 'storage_preserves_explicit_burst_settings', Config::normalize_stored( $off ) === $off );
$old_off = array_replace( $legacy, array( 'enabled' => false ) );
$check( 'legacy_disabled_app_remains_disabled', false === Config::normalize_stored( $old_off )['enabled'] );
$check( 'new_write_requires_both_fields_even_when_app_disabled', $bad_field( Config::normalize( $old_off ), 'burstEnabled' ) );
$check( 'legacy_wrong_version_not_upgraded', $bad_field( Config::normalize_stored( array_replace( $legacy, array( 'version' => 2 ) ) ), 'version' ) );
$check( 'list_storage_rejected', Config::normalize_stored( array( 'a', 'b' ) ) instanceof WP_Error );
$check( 'null_storage_rejected', Config::normalize_stored( null ) instanceof WP_Error );
$check( 'legacy_unsafe_text_still_rejected', $bad_field( Config::normalize_stored( array_replace( $legacy, array( 'successText' => '<img src=x>' ) ) ), 'successText' ) );
$check( 'legacy_success_duration_still_validated', $bad_field( Config::normalize_stored( array_replace( $legacy, array( 'successDurationMs' => 799 ) ) ), 'successDurationMs' ) );
$passed = count( array_filter( $checks, static fn( $check ) => $check['pass'] ) );
echo json_encode( array( 'kind' => 'pure actual Config checks; no database', 'passed' => $passed, 'total' => count( $checks ), 'checks' => $checks ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
exit( $passed === count( $checks ) ? 0 : 1 );
