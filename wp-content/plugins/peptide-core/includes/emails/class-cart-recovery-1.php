<?php
/**
 * 弃单通知第一阶段（默认 300 秒后）。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Emails;

defined( 'ABSPATH' ) || exit;

class Cart_Recovery_1 extends Cart_Recovery {

	public const STAGE = 1;

	protected function defaults(): array {
		return array(
			'subject' => 'Complete your purchase at {site_title}',
			'heading' => 'Complete your purchase',
			'message' => "You added items to your shopping cart and haven't completed your purchase.\n\nYou can complete it now while they're still available.",
			'delay'   => 300,
		);
	}
}
