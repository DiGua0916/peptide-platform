<?php
namespace VialCove\Panapay;
defined( 'ABSPATH' ) || exit;
/** Only locally authored messages may use this type. Never a remote error body. */
final class Customer_Error extends \RuntimeException {}
