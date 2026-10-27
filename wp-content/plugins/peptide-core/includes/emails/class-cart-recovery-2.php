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

	protected function defaults(): array {
		return array(
			'subject' => 'Did you face any problems?',
			'heading' => 'Did you face any problems?',
			'message' => "You added items to your shopping cart and haven't completed your purchase.\n\nDid you run into a problem during checkout? If so, just reply to this email and we will help right away. If you are still interested, you can complete your order below.",
			'delay'   => 1800,
		);
	}
}
