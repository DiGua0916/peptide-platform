<?php
/**
 * 物流方案的 WooCommerce 运费方式（SHIPPING-PLANS-01）。
 *
 * 不支持“运费区域”，属于全局方式：每个包裹都会计算一次，区域由方案自己判断（见 Plans::quote）。
 * 运费 ID 形如 pc_plans:方案ID-运费ID；多方案合并时为 pc_plans:combined。运费图标放在 meta_data 的 _pc_icon 里（下划线开头，不显示在顾客的订单详情里），主题需要时可以显示。
 *
 * @package PeptideCore
 */

namespace PeptideCore\Shipping;

defined( 'ABSPATH' ) || exit;

class Plan_Method extends \WC_Shipping_Method {

	public function __construct( $instance_id = 0 ) {
		parent::__construct( $instance_id );
		$this->id                 = Plans::METHOD_ID;
		$this->method_title       = __( 'VialCove shipping plans', 'peptide-core' );
		$this->method_description = __( 'Rates from Store settings → Shipping settings. Configure them there.', 'peptide-core' );
		$this->enabled            = 'yes';
		$this->title              = $this->method_title;
		$this->supports           = array();
	}

	/** 方案在“物流设置”页面管理，这里不提供 WooCommerce 的设置表单。 */
	public function init_form_fields() {
		$this->form_fields = array();
	}

	public function admin_options() {
		echo '<p>' . esc_html( $this->method_description ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=' . \PeptideCore\Admin\Shipping_Page::SLUG ) ) . '">' . esc_html__( 'Open shipping settings', 'peptide-core' ) . '</a></p>';
	}

	/** @param array $package */
	public function calculate_shipping( $package = array() ) {
		foreach ( Plans::quote( (array) $package ) as $q ) {
			$this->add_rate(
				array(
					'id'        => $this->id . ':' . $q['id'],
					'label'     => $q['label'],
					'cost'      => $q['cost'],
					'package'   => $package,
					'meta_data' => array(
						'_pc_plans' => implode( ',', $q['plan_ids'] ),
						'_pc_icon'  => $q['icon'],
					),
				)
			);
		}
	}
}
