<?php
/**
 * 弃单通知第二阶段（默认 1800 秒后）。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Emails;

defined( 'ABSPATH' ) || exit;

class Cart_Recovery_2 extends Cart_Recovery {

	public const STAGE = 2;

	public function __construct() {
		parent::__construct();
		Chase_Copy::migrate( $this, self::STAGE );
	}

	protected function defaults(): array {
		return Chase_Copy::defaults_for( self::STAGE ); // 店主追单母版（2026-11-09）
	}
}
