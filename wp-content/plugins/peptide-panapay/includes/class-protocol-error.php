<?php
namespace VialCove\Panapay;
defined( 'ABSPATH' ) || exit;
final class Protocol_Error extends \RuntimeException {
	public function __construct( string $code, int $http_status = 400 ) { parent::__construct( $code, $http_status ); }
}
