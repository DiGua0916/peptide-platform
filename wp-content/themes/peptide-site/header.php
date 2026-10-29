<?php defined( 'ABSPATH' ) || exit; $vc_config = vc_site_config(); ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><?php wp_head(); ?></head>
<body <?php body_class(); ?>><?php wp_body_open(); ?>
<a class="skip-link" href="#main-content">Skip to content</a>
<?php if ( ! empty( $vc_config['demo_mode'] ) ) : ?><div class="site-notice"><span class="notice-dot"></span><span><?php echo esc_html( $vc_config['demo_notice'] ?: 'Demonstration site' ); ?></span><a href="<?php echo esc_url( vc_url( 'reports' ) ); ?>">Explore batch reports <?php echo vc_icon(); ?></a></div><?php endif; ?>
<header class="site-header"><div class="container header-inner">
    <a class="brand brand--primary" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="VialCovePeptides home"><?php vc_brand_logo( true ); ?></a>
    <?php get_template_part( 'template-parts/navigation' ); ?>
    <div class="header-actions"><a class="icon-link account-link" href="<?php echo esc_url( vc_url( 'account' ) ); ?>" aria-label="My account"><?php echo vc_icon( 'user' ); ?></a><a class="cart-link" href="<?php echo esc_url( vc_url( 'cart' ) ); ?>" aria-label="View cart"><?php echo vc_icon( 'bag' ); ?><span class="vc-cart-count"><?php echo function_exists( 'WC' ) && WC()->cart ? esc_html( (string) WC()->cart->get_cart_contents_count() ) : '0'; ?></span></a><?php if ( vc_site_menu( 'header' ) ) : ?><button class="menu-toggle" aria-label="Open menu" aria-expanded="false" aria-controls="primary-nav"><span></span><span></span></button><?php endif; ?></div>
</div></header>
<?php vc_storefront_notices(); ?>
