<?php
/**
 * 素材看板页面（线 4）：按素材 / 素材属性看有效进站、意向分层、加购、成本；漏参提醒；检查点导出。
 * 打地基阶段只有占位。上报给总后台的格式见 Contract::report_payload()。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Creatives;

defined( 'ABSPATH' ) || exit;

final class Board_Page {

	public static function register_hooks(): void {}

	public static function render(): void {
		Module::placeholder( '素材看板', Module::PAGE_BOARD, '素材看板开发中：登记素材并导入花费后，这里会按素材和属性显示效果。' );
	}
}
