<?php
/**
 * 弃单通知第三阶段（默认 86400 秒后）。Shopname 默认写“只能再为你保留 24 小时”，这里不写虚构的保留期限。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Emails;

defined( 'ABSPATH' ) || exit;

class Cart_Recovery_3 extends Cart_Recovery {

	public const STAGE = 3;

	public function __construct() {
		parent::__construct();
		Chase_Copy::migrate( $this, self::STAGE );
	}

	/** 第三封默认不开：店主母版只有一封追单信，前两封已够。 */
	protected function default_enabled(): string {
		return 'no';
	}

	protected function defaults(): array {
		return Chase_Copy::defaults_for( self::STAGE ); // 店主追单母版（2026-11-09）
	}
}
