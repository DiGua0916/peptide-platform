<?php defined( 'ABSPATH' ) || exit; $vc_config = vc_site_config(); ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><?php wp_head(); ?></head>
<body <?php body_class(); ?>><?php wp_body_open(); ?>
<a class="skip-link" href="#main-content">Skip to content</a>
<?php if ( ! empty( $vc_config['demo_mode'] ) ) : ?><div class="site-notice"><span class="notice-dot"></span><span>VialCove preview · Products, prices and reports are demonstration data.</span><a href="<?php echo esc_url( vc_url( 'reports' ) ); ?>">Explore batch reports <?php echo vc_icon(); ?></a></div><?php endif; ?>
<header class="site-header"><div class="container header-inner">
    <a class="brand" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="VialCove home"><span class="brand-mark" aria-hidden="true"><i></i><i></i><i></i></span>VialCove</a>
    <nav id="primary-nav" class="primary-nav" aria-label="Main navigation">
        <a href="<?php echo esc_url( vc_url( 'shop' ) ); ?>">Shop peptides</a><a href="<?php echo esc_url( vc_url( 'reports' ) ); ?>">Batch reports</a><a href="<?php echo esc_url( home_url( '/#our-story' ) ); ?>">Our story</a><a href="<?php echo esc_url( home_url( '/#questions' ) ); ?>">FAQs</a>
    </nav>
    <div class="header-actions"><a class="icon-link account-link" href="<?php echo esc_url( vc_url( 'account' ) ); ?>" aria-label="My account"><?php echo vc_icon( 'user' ); ?></a><a class="cart-link" href="<?php echo esc_url( vc_url( 'cart' ) ); ?>" aria-label="View cart"><?php echo vc_icon( 'bag' ); ?><span class="vc-cart-count"><?php echo function_exists( 'WC' ) && WC()->cart ? esc_html( (string) WC()->cart->get_cart_contents_count() ) : '0'; ?></span></a><button class="menu-toggle" aria-label="Open menu" aria-expanded="false" aria-controls="primary-nav"><span></span><span></span></button></div>
</div></header>
