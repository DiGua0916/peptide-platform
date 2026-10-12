<?php
/**
 * 给主题用的 PHP 读取函数（方案 v1 D4）。返回与 REST 接口相同形状的数组，失败返回 WP_Error。
 * 主题调用前应先 function_exists() 检查，插件未启用时显示“数据不可用”。
 *
 * @package PeptideCore
 */

defined( 'ABSPATH' ) || exit;

/**
 * 站点级公开配置，等同 GET /peptide/v1/site。
 *
 * @return array<string,mixed>
 */
function peptide_core_get_site_config(): array {
	return \PeptideCore\Settings::public_config();
}

/**
 * 商品的公开批次与报告，等同 GET /peptide/v1/products/{slug}/batches。
 *
 * @return array<string,mixed>|WP_Error
 */
function peptide_core_get_product_batches( string $slug, bool $include_history = false ) {
	return \PeptideCore\Api\Rest_Controller::product_batches( $slug, get_current_user_id(), $include_history );
}

/**
 * 按批次号查询，等同 GET /peptide/v1/batches/lookup（主题直接调用时不限流）。
 *
 * @return array<string,mixed>|WP_Error
 */
function peptide_core_lookup_batch( string $batch_no, bool $include_history = false ) {
	return \PeptideCore\Api\Rest_Controller::batch_by_no( $batch_no, get_current_user_id(), $include_history );
}
