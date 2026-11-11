<?php
/**
 * 花费导入页面（线 2）：上传 Meta 广告管理工具导出的“按广告、按天”报表 CSV，
 * 按（ad_id, 日期）覆盖写入 pc_ad_daily_spend，再把每日全店花费推给总后台 pchq_ad_spend（source = csv）。
 * 打地基阶段只有占位。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Creatives;

defined( 'ABSPATH' ) || exit;

final class Spend_Page {

	public static function register_hooks(): void {}

	public static function render(): void {
		Module::placeholder( '花费导入', Module::PAGE_SPEND, '花费导入功能开发中：每天从 Meta 导出前一天的报表上传到这里。' );
	}
}
