<?php
/**
 * Original built-in symbols for the Slogan block.
 *
 * Integration draft only. This file does not register fields or change the
 * existing image icon. The caller owns its explicit image/symbol precedence.
 *
 * @package PeptideCore
 */

namespace PeptideCore\Modules;

defined( 'ABSPATH' ) || exit;

final class Slogan_Symbols {

	/** Fixed, authored geometry. No user-controlled SVG, attributes or URLs. */
	private const SHAPES = array(
		'logistics'         => '<path d="M2.5 5.5h11v10.7h-11zM13.5 9h4.7l3.3 4v3.2h-2.2M13.5 16.2h1.4M7.3 16.2h3.6M16.3 9v4h5.2"/><circle cx="5.2" cy="17.6" r="2"/><circle cx="17.2" cy="17.6" r="2"/>',
		'currency'          => '<ellipse cx="9" cy="5.5" rx="6" ry="2.5"/><path d="M3 5.5v4c0 1.4 2.7 2.5 6 2.5 1.1 0 2.1-.1 3-.3M3 9.5v4c0 1.4 2.7 2.5 6 2.5h1.7M3 13.5v4C3 18.9 5.7 20 9 20h1.8"/><ellipse cx="17" cy="12.8" rx="4" ry="2"/><path d="M13 12.8v5.7c0 1.1 1.8 2 4 2s4-.9 4-2v-5.7M13 15.6c0 1.1 1.8 2 4 2s4-.9 4-2"/>',
		'certification'     => '<path d="m8 14-1 7 5-2.5 5 2.5-1-7"/><path d="m12 2.5 2.1 1 2.3.1 1.3 1.9 1.1 2-.5 2.2-.3 2.3-1.9 1.3-1.8 1.5-2.3-.2-2.3.2-1.8-1.5-1.9-1.3-.3-2.3L5.3 7.5l1.1-2 1.3-1.9 2.3-.1zM9 8.4l2 2 4-4"/>',
		'worldwide-delivery' => '<circle cx="10.8" cy="10.8" r="8.3"/><path d="M2.5 10.8H19M10.8 2.5c-4.3 4.3-4.3 12.3 0 16.6M10.8 2.5c2.6 2.6 3.6 6.7 2.9 10.4M15.5 17.5h6m-2.8-2.8 2.8 2.8-2.8 2.8"/>',
		'buyer-protection'  => '<path d="M12 2.7 20 6v5.8c0 4.4-3.1 7.8-8 9.5-4.9-1.7-8-5.1-8-9.5V6zM8.5 11.9l2.5 2.5 4.9-5"/>',
		'smartphone'        => '<rect x="6.5" y="2.5" width="11" height="19" rx="2"/><path d="M10 5h4M11 18.6h2"/>',
		'chat'              => '<path d="M5 3.5h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H9l-6 4v-16a2 2 0 0 1 2-2zM7 8h10M7 12h6"/>',
		'credit-card'       => '<rect x="2.5" y="4.5" width="19" height="15" rx="2"/><path d="M2.5 9.1h19M6 15h3.7M16.5 15h1"/>',
		'mail'              => '<rect x="2.5" y="4.5" width="19" height="15" rx="2"/><path d="m3.2 6 8.8 7 8.8-7M3.4 18l5.4-5M20.6 18l-5.4-5"/>',
		'location'          => '<path d="M19 9.5c0 5.1-7 11.8-7 11.8S5 14.6 5 9.5a7 7 0 0 1 14 0z"/><circle cx="12" cy="9.3" r="2.5"/>',
		'question'          => '<circle cx="12" cy="12" r="9"/><path d="M8.9 8.6a3.2 3.2 0 0 1 6.2.9c0 2.3-3.1 2.4-3.1 4.5M12 17h.01"/>',
		'smile'             => '<circle cx="12" cy="12" r="9"/><path d="M8.5 9h.01M15.5 9h.01M7.6 13.7a4.7 4.7 0 0 0 8.8 0"/>',
		'store'             => '<path d="m3 8 2-5h14l2 5v2a3 3 0 0 1-4.5 2.6 3 3 0 0 1-4.5 0 3 3 0 0 1-4.5 0A3 3 0 0 1 3 10zM8.8 3 8 8m7.2-5 .8 5M3 8h18M5 13v8h14v-8M10 21v-6h4v6"/>',
		'gift'              => '<rect x="3" y="7.5" width="18" height="4.5" rx="1"/><path d="M5 12v9h14v-9M12 7.5V21M12 7.5H8.2a2.6 2.6 0 1 1 2.3-3.8L12 7.5Zm0 0h3.8a2.6 2.6 0 1 0-2.3-3.8L12 7.5Z"/>',
		'heart'             => '<path d="M20.2 4.8a5 5 0 0 0-7.1 0L12 5.9l-1.1-1.1a5 5 0 0 0-7.1 7.1L12 20l8.2-8.1a5 5 0 0 0 0-7.1z"/>',
		'discount'          => '<path d="M12.2 3H4a1 1 0 0 0-1 1v8.2L12.8 22 22 12.8zM7 7h.01M11.5 15.5l5-5"/><circle cx="11.7" cy="10.7" r=".8"/><circle cx="16.3" cy="15.3" r=".8"/>',
		'lock'              => '<rect x="4.5" y="10" width="15" height="11" rx="2"/><path d="M8 10V6.5a4 4 0 0 1 8 0V10M12 14v3"/>',
		'convert'           => '<path d="M4 8h15l-4-4M20 16H5l4 4M4 8l4 4M20 16l-4-4"/>',
		'cart'              => '<path d="M2.5 3.5h2.7l2.2 12h11.3l2.8-9H5.8M8 12h12"/><circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/>',
		'service-24h'       => '<path d="M19.8 6.5a9 9 0 1 0 1.2 7M20.5 2.5v5h-5M12 6.5V12l3.5 2"/>',
		'product'           => '<path d="M3 7 12 3l9 4v10l-9 4-9-4zM3 7l9 4 9-4M12 11v10M7.5 5l9 4v4"/>',
		'coupon'            => '<path d="M3 5h18v5a2 2 0 0 0 0 4v5H3v-5a2 2 0 0 0 0-4zM15.5 5v2m0 3v1m0 3v1m0 3v1M6.5 9h5M6.5 13h3"/>',
		'customers'         => '<circle cx="12" cy="7" r="3"/><path d="M6.5 21v-2a5.5 5.5 0 0 1 11 0v2M5.5 5a3 3 0 0 0 0 6M2.5 19v-2a4 4 0 0 1 4-4M18.5 5a3 3 0 0 1 0 6M21.5 19v-2a4 4 0 0 0-4-4"/>',
		'edit'              => '<path d="m14.8 4.2 5 5M3 21l1.2-6.2L16 3a2.1 2.1 0 0 1 3 0l2 2a2.1 2.1 0 0 1 0 3L9.2 19.8zM4.2 14.8l5 5"/>',
		'member'            => '<rect x="2.5" y="4.5" width="19" height="15" rx="2"/><circle cx="8" cy="10" r="2"/><path d="M4.5 16a3.5 3.5 0 0 1 7 0M16.7 8.4l.9 1.9 2.1.3-1.5 1.5.4 2.1-1.9-1-1.9 1 .4-2.1-1.5-1.5 2.1-.3z"/>',
		'shipping'          => '<path d="M4 11V7h5V3h6v4h5v4M9 7h6M12 10l9 4-3 5H6l-3-5zM12 10v7M2.5 21c1.6 0 1.6-1 3.2-1s1.6 1 3.2 1 1.6-1 3.2-1 1.6 1 3.2 1 1.6-1 3.2-1 1.6 1 3.2 1"/>',
		'building'          => '<path d="M5 21V3h14v18M2.5 21h19M9 7h1M14 7h1M9 11h1M14 11h1M9 15h1M14 15h1M10 21v-3h4v3"/>',
		'praise'            => '<path d="M8 10h-5v11h5zM8 19.5l2.6 1.5h7.2a2 2 0 0 0 2-1.6l1.5-7a2 2 0 0 0-2-2.4h-5l.7-4.6A2.5 2.5 0 0 0 12.5 2H12l-1 4.5L8 11"/>',
		'video'             => '<rect x="2.5" y="4.5" width="19" height="15" rx="2"/><path d="m10 8 6 4-6 4z"/>',
		'customize'         => '<path d="M3 5h3m4 0h11M3 12h11m4 0h3M3 19h5m4 0h9"/><circle cx="8" cy="5" r="2"/><circle cx="16" cy="12" r="2"/><circle cx="10" cy="19" r="2"/>',
		'growth'            => '<path d="M3 3v18h18M6 15l5-5 4 3 6-8M16 5h5v5"/>',
		'materials'         => '<path d="M3.5 5.5h4v15h-4zM8 3.5h4v17H8zM14.1 5.5l3.8-1 4 14.5-3.9 1zM3.5 16.5h4M8 16.5h4M16.7 15.2l3.8-1"/>',
		'student'           => '<path d="m2 8 10-5 10 5-10 5zM6 10v6c3.6 2.7 8.4 2.7 12 0v-6M21.5 8v8M20.5 19l1-3 1 3"/>',
		'office'            => '<rect x="7.5" y="3.5" width="12" height="9" rx="1"/><path d="M13.5 12.5V16M10.5 16h6M2.5 16h19M4 16v5M20 16v5M3 13V7.5L5.5 5"/>',
		'modules'           => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
		'file-package'      => '<path d="M3 10V6a2 2 0 0 1 2-2h5l2 3h7a2 2 0 0 1 2 2v1M3 10h18l-1.5 10h-15zM10 10V7M14 10V7"/>',
		'file'              => '<path d="M6 2.5h8l4 4v15H6zM14 2.5v4h4M9 11h6M9 15h6M9 18h4"/>',
		'treaty'            => '<path d="M5.5 2.5H14l4.5 4.5v6M14 2.5V7h4.5M8.5 10.5h6M8.5 14h3M5.5 2.5v19H10M12 21l1-4 5.5-5.5 3 3L16 20zM17.2 12.8l3 3"/>',
		'business'          => '<rect x="2.5" y="7" width="19" height="14" rx="2"/><path d="M8 7V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v3M2.5 11.5l8 3h3l8-3M10.5 13.5v3h3v-3"/>',
		'share'             => '<circle cx="5" cy="12" r="2.5"/><circle cx="18.5" cy="5" r="2.5"/><circle cx="18.5" cy="19" r="2.5"/><path d="m7.2 10.8 9.1-4.6M7.2 13.2l9.1 4.6"/>',
	);

	/**
	 * The 40 explicit symbol choices. The field may prepend its own empty choice.
	 *
	 * @return array<string,string>
	 */
	public static function options(): array {
		return array(
			'logistics'         => __( '物流', 'peptide-core' ),
			'currency'          => __( '货币', 'peptide-core' ),
			'certification'     => __( '认证', 'peptide-core' ),
			'worldwide-delivery' => __( '全球交付', 'peptide-core' ),
			'buyer-protection'  => __( '买家保护', 'peptide-core' ),
			'smartphone'        => __( '手机', 'peptide-core' ),
			'chat'              => __( '聊天', 'peptide-core' ),
			'credit-card'       => __( '信用卡', 'peptide-core' ),
			'mail'              => __( '邮箱', 'peptide-core' ),
			'location'          => __( '定位', 'peptide-core' ),
			'question'          => __( '问题', 'peptide-core' ),
			'smile'             => __( '笑脸', 'peptide-core' ),
			'store'             => __( '店铺/房子', 'peptide-core' ),
			'gift'              => __( '礼物', 'peptide-core' ),
			'heart'             => __( '爱心', 'peptide-core' ),
			'discount'          => __( '折扣', 'peptide-core' ),
			'lock'              => __( '安全锁', 'peptide-core' ),
			'convert'           => __( '转换', 'peptide-core' ),
			'cart'              => __( '购物车', 'peptide-core' ),
			'service-24h'       => __( '24h', 'peptide-core' ),
			'product'           => __( '商品', 'peptide-core' ),
			'coupon'            => __( '优惠券', 'peptide-core' ),
			'customers'         => __( '客户群', 'peptide-core' ),
			'edit'              => __( '编辑', 'peptide-core' ),
			'member'            => __( '会员', 'peptide-core' ),
			'shipping'          => __( '航运', 'peptide-core' ),
			'building'          => __( '建筑', 'peptide-core' ),
			'praise'            => __( '赞美', 'peptide-core' ),
			'video'             => __( '视频', 'peptide-core' ),
			'customize'         => __( '个性定制', 'peptide-core' ),
			'growth'            => __( '增长', 'peptide-core' ),
			'materials'         => __( '资料', 'peptide-core' ),
			'student'           => __( '学生', 'peptide-core' ),
			'office'            => __( '办公', 'peptide-core' ),
			'modules'           => __( '模块', 'peptide-core' ),
			'file-package'      => __( '文件包', 'peptide-core' ),
			'file'              => __( '文件', 'peptide-core' ),
			'treaty'            => __( '条约', 'peptide-core' ),
			'business'          => __( '商务', 'peptide-core' ),
			'share'             => __( '分享', 'peptide-core' ),
		);
	}

	/**
	 * Return only a known, decorative symbol. Empty/unknown IDs render nothing.
	 *
	 * @param mixed $id Exact ASCII choice ID; never interpreted as SVG or a URL.
	 */
	public static function svg( $id ): string {
		if ( ! is_string( $id ) || ! isset( self::SHAPES[ $id ] ) ) {
			return '';
		}

		return '<svg class="vc-slogan__icon vc-slogan-symbol" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
			. self::SHAPES[ $id ]
			. '</svg>';
	}
}
