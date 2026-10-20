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

/**
 * 客服浮动按钮的联系方式，等同 GET /peptide/v1/site 的 support 字段（SUPPORT-CHAT-01）。
 * enabled 为 false 时不要显示按钮。
 *
 * @return array{enabled:bool,position:string,channels:array<int,array{type:string,label:string,value:string,url:string}>}
 */
function peptide_core_get_support_contacts(): array {
	return \PeptideCore\Support_Contacts::public_view();
}

/**
 * 当前模板（预览 > 域名指定 > 店铺默认）的某份装修文档是否已经发布并且有模块（EDIT-BLOCKS-05）。
 */
function peptide_core_has_layout( string $doc = 'home' ): bool {
	return (bool) \PeptideCore\Modules\Layout::published_modules( $doc );
}

/**
 * 输出当前请求应该显示的装修内容：员工预览时是草稿，访客看到已发布的版本。返回已转义的 HTML。
 */
function peptide_core_render_layout( string $doc = 'home' ): string {
	return \PeptideCore\Modules\Layout::render_current( $doc );
}

/**
 * 商品卡片悬停时显示的内容（PRODUCT-HOVER-01），在商品编辑页的 Peptide data 面板里设置。
 * mode：placeholder / report / gallery / image / none（缺内容时已退回 placeholder）；
 * image：{src, width, height, srcset, alt}；report：{batch_no, title, type, version, issued_on, issuer_name, is_demo, access}。
 *
 * @param WC_Product|int $product
 * @return array{mode:string,requested:string,image:?array,report:?array}
 */
function peptide_core_get_product_hover( $product ): array {
	$product = $product instanceof WC_Product ? $product : wc_get_product( (int) $product );
	return $product ? \PeptideCore\Product_Hover::for_product( $product ) : array( 'mode' => 'none', 'requested' => 'none', 'image' => null, 'report' => null );
}
