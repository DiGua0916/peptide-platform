<?php
/**
 * 素材库页面（线 2）：登记素材、上传缩略图、选属性，生成编号和规范广告名。
 * 打地基阶段只有占位。规则见 Contract::code_for()、Contract::build_ad_name()、Contract::attr_problems()。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Creatives;

defined( 'ABSPATH' ) || exit;

final class Library_Page {

	public static function register_hooks(): void {}

	public static function render(): void {
		Module::placeholder( '素材库', Module::PAGE_LIBRARY, '素材登记功能开发中：上传素材、选属性后会生成素材编号和广告名，复制到 Meta 即可。' );
	}
}
