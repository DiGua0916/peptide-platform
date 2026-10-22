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

/**
 * 页头 / 页尾菜单（MENU-NAV-01），在“店铺装修 → 菜单导航”编辑。该位置没有菜单时返回 null，主题继续显示自己的默认链接。
 * 最多两级；已删除或未发布的商品 / 页面 / 分类不会出现。每一项：
 * id, title, description, type（home / shop / category / product / page / post / cart / account / orders / login /
 * register / lost_password / search / custom / none）, object_id, url（none 时为空字符串，只是标题）,
 * target（'' / '_self' / '_blank'）, rel（例如 'nofollow noopener'）, attr_title, style（已整理好的行内样式，可直接放进 style 属性）,
 * icon（null 或 {url, width, height, alt}）, current（是否当前页面）, children（同样结构的二级项）。
 *
 * @param string $location header | footer
 * @return array<int,array<string,mixed>>|null
 */
function peptide_core_get_menu( string $location = 'header' ): ?array {
	return \PeptideCore\Menus::public_tree( $location );
}

/**
 * 设为“结账条款”并已发布的页面（CUSTOM-PAGES-01），在页面编辑页右侧勾选。主题可以把它们链接在结账页。
 *
 * @return array<int,array{id:int,title:string,url:string}>
 */
function peptide_core_get_checkout_terms(): array {
	return \PeptideCore\Custom_Pages::checkout_terms();
}

/**
 * 可以公开展示的优惠码（COUPON-01），在 WooCommerce 优惠券编辑页勾选“在优惠码页面公开展示”。
 * 每张：id, code, title, text, type（percent / fixed_cart / fixed_product）, amount, value（例如 "10% off"）, free_shipping,
 * minimum_spend(_text), maximum_spend(_text), expires（ISO 时间或 null）, expires_text, uses_left, limit_per_customer,
 * individual_use, exclude_sale_items, products / categories / excluded_products / excluded_categories（[{id,name,url}]）,
 * url（详情页）, apply_url（打开后自动使用的分享链接）。不包含邮箱限制等内部信息；限定邮箱的优惠码不会出现。
 *
 * @return array<int,array<string,mixed>>
 */
function peptide_core_get_public_coupons(): array {
	return \PeptideCore\Coupons::public_list();
}

/**
 * 一张可以公开的优惠码，结构同上；不存在、已过期或未公开时返回 null。
 */
function peptide_core_get_public_coupon( string $code ): ?array {
	return \PeptideCore\Coupons::get_public( $code );
}

/**
 * 公开展示的满减活动（PROMO-01），进行中或即将开始。每个：id, title, text, status（active / upcoming / ended）,
 * basis（spend / quantity）, reward（amount / percent）, tiers（[{min, value, text}]，text 例如 "Spend $100.00, get $10.00 off"）,
 * starts / ends（ISO 或 null）, starts_text / ends_text, scope（all / categories / products）, categories / products（[{id,name,url}]）,
 * exclude_sale, with_coupons, url（详情页）。
 *
 * @return array<int,array<string,mixed>>
 */
function peptide_core_get_promotions(): array {
	return \PeptideCore\Promotions::public_list();
}

/**
 * 一个公开的满减活动，结构同上；不公开或已停用时返回 null（已结束的仍返回，status=ended）。
 */
function peptide_core_get_promotion( int $id ): ?array {
	return \PeptideCore\Promotions::get_public( $id );
}

/**
 * 当前购物车的满减情况：
 * applied：null 或 {id, title, tier, discount, discount_text, url}（购物车里已经减掉的那一笔）；
 * next：null 或 {id, title, basis, remaining, remaining_text, reward_text, message, url}
 * （例如 message = "Add $20.00 more to get $25.00 off"）。区块购物车在 Store API 的 extensions.peptide_core.promotion 里有同样的数据。
 *
 * @return array{applied:?array,next:?array}
 */
function peptide_core_get_promotion_progress(): array {
	return \PeptideCore\Promotions::progress();
}

/**
 * 这个商品参加的、进行中的公开满减活动（结构同 peptide_core_get_promotions()），用于商品页 / 商品卡片提示。
 *
 * @param WC_Product|int $product
 */
function peptide_core_get_product_promotions( $product ): array {
	$product = $product instanceof WC_Product ? $product : wc_get_product( (int) $product );
	return $product ? \PeptideCore\Promotions::for_product( $product ) : array();
}
