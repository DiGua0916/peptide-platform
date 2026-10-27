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

	protected function defaults(): array {
		return array(
			'subject' => 'Your cart is still waiting',
			'heading' => 'Still interested?',
			'message' => "We noticed you haven't finished your order. Your cart is still saved, so you can pick up right where you left off.\n\nIf anything went wrong at checkout, just reply to this email.",
			'delay'   => 86400,
		);
	}
}
