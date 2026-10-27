<?php
/** Editorial report introduction; never fabricates batch or test values. */
defined( 'ABSPATH' ) || exit;
$vc_content_module = $args['module'];
$vc_content_s = $vc_content_module['settings'];
$vc_content_colors = array();
if ( '' !== $vc_content_s['bgColor'] ) { $vc_content_colors[] = '--vc-content-bg:' . $vc_content_s['bgColor']; }
if ( '' !== $vc_content_s['textColor'] ) { $vc_content_colors[] = '--vc-content-fg:' . $vc_content_s['textColor']; }
?>
<div class="vc-content-report"<?php if ( $vc_content_colors ) { echo ' style="' . esc_attr( implode( ';', $vc_content_colors ) ) . '"'; } ?>>
    <div class="container vc-content-report-inner">
        <div class="vc-content-report-copy">
            <?php if ( '' !== $vc_content_s['eyebrow'] ) : ?><p class="eyebrow"><?php echo esc_html( $vc_content_s['eyebrow'] ); ?></p><?php endif; ?>
            <?php if ( '' !== $vc_content_s['title'] || '' !== $vc_content_s['titleAccent'] ) : ?><h2><?php echo esc_html( $vc_content_s['title'] ); ?><?php if ( '' !== $vc_content_s['titleAccent'] ) : ?><?php if ( '' !== $vc_content_s['title'] ) : ?><br><?php endif; ?><em><?php echo esc_html( $vc_content_s['titleAccent'] ); ?></em><?php endif; ?></h2><?php endif; ?>
            <?php if ( '' !== $vc_content_s['description'] ) : ?><p class="vc-content-report-description"><?php echo nl2br( esc_html( $vc_content_s['description'] ) ); ?></p><?php endif; ?>
            <?php echo vc_module_button( $vc_content_s['button'], 'button button-white' ); ?>
        </div>
        <div class="vc-content-report-card">
            <div class="vc-content-report-card-top"><span class="brand"><?php vc_brand_logo( true ); ?></span><?php echo vc_icon( 'file' ); ?></div>
            <?php if ( '' !== $vc_content_s['cardEyebrow'] ) : ?><p class="eyebrow"><?php echo esc_html( $vc_content_s['cardEyebrow'] ); ?></p><?php endif; ?>
            <?php if ( '' !== $vc_content_s['cardTitle'] ) : ?><h3><?php echo esc_html( $vc_content_s['cardTitle'] ); ?></h3><?php endif; ?>
            <?php foreach ( $vc_content_module['items'] as $vc_content_item ) : ?>
                <?php if ( ! $vc_content_item['enabled'] && 'editor' !== ( $args['context'] ?? 'public' ) ) { continue; } $vc_content_row = $vc_content_item['settings']; ?>
                <div class="vc-content-report-row<?php echo $vc_content_item['enabled'] ? '' : ' vc-content-item-disabled'; ?>" data-module-item="<?php echo esc_attr( $vc_content_item['id'] ); ?>"><span><?php echo esc_html( $vc_content_row['label'] ); ?></span><strong><?php echo esc_html( $vc_content_row['value'] ); ?></strong></div>
            <?php endforeach; ?>
            <?php if ( '' !== $vc_content_s['cardNote'] ) : ?><p class="vc-content-report-note"><?php echo esc_html( $vc_content_s['cardNote'] ); ?></p><?php endif; ?>
        </div>
    </div>
</div>
