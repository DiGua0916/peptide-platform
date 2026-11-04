<?php
/** Editable warehouse comparison for the existing inline delivery module. */
defined( 'ABSPATH' ) || exit;
$module = $args['module']; $s = $module['settings'];
$items = vc_demo_home_items( $module, $args['context'] ?? 'public' );
$warehouses = array_filter( $items, static fn( $item ) => 'delivery-support' !== $item['id'] );
$support_rows = array_filter( $items, static fn( $item ) => 'delivery-support' === $item['id'] );
$contact = '';
if ( function_exists( 'peptide_core_get_support_contacts' ) && function_exists( 'vc_storefront_whatsapp_url' ) ) {
    $channels = peptide_core_get_support_contacts();
    $contact = is_array( $channels ) ? vc_storefront_whatsapp_url( $channels ) : '';
}
$contact_label = $s['link']['label'] ?? '';
?>
<div class="vc-demo-module vc-delivery-section"><div class="vc-demo-wrap">
    <header class="vc-delivery-header">
        <h2><?php echo esc_html( $s['title'] ); ?></h2>
        <div class="vc-delivery-intro"><?php echo vc_demo_home_description( $s ); ?></div>
        <?php echo vc_demo_home_image( $s['supportImage'] ?? null, 'vc-delivery-image', '112px' ); ?>
    </header>
    <div class="vc-delivery-warehouses">
        <?php foreach ( $warehouses as $item ) : $row = $item['settings']; ?>
            <article class="vc-delivery-warehouse"<?php echo vc_demo_home_item_attrs( $item ); ?>>
                <h3><?php echo esc_html( $row['question'] ); ?></h3>
                <div class="vc-delivery-timing"><?php echo wp_kses_post( $row['answerHtml'] ); ?></div>
            </article>
        <?php endforeach; ?>
    </div>
    <?php foreach ( $support_rows as $item ) : $row = $item['settings']; ?>
        <article class="vc-delivery-support"<?php echo vc_demo_home_item_attrs( $item ); ?>>
            <h3><?php echo esc_html( $row['question'] ); ?></h3>
            <div class="vc-delivery-support-copy"><?php echo wp_kses_post( $row['answerHtml'] ); ?></div>
            <?php if ( '' !== $contact && '' !== $contact_label ) : ?>
                <a class="vc-delivery-contact" href="<?php echo esc_url( $contact ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr( $contact_label . ' (opens in a new tab)' ); ?>"><?php echo esc_html( $contact_label ); ?> <span aria-hidden="true">↗</span></a>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</div></div>
