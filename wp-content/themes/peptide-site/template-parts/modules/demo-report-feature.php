<?php
defined( 'ABSPATH' ) || exit;
$module = $args['module']; $s = $module['settings'];
$items = vc_demo_home_items( $module, $args['context'] ?? 'public' );
$style = '';
if ( ! empty( $s['bgColor'] ) ) { $style .= '--vc-demo-report-bg:' . $s['bgColor'] . ';'; }
if ( ! empty( $s['textColor'] ) ) { $style .= '--vc-demo-report-fg:' . $s['textColor'] . ';'; }
$papers = array();
foreach ( $items as $item ) {
    $row = $item['settings']; $report_id = (int) ( $row['reportId'] ?? 0 );
    if ( $report_id > 0 ) {
        $report = vc_demo_home_report( $report_id );
        if ( ! $report || empty( $report['view_url'] ) ) { continue; }
        $papers[] = array( 'item' => $item, 'title' => $report['title'], 'href' => $report['view_url'], 'image' => $report['thumbnail_url'] ?? '', 'meta' => trim( ( $report['issuer_name'] ?? '' ) . ' · ' . ( $report['issued_on'] ?? '' ), ' ·' ) );
    } else {
        $image = vc_module_media_ref( $row['image'] ?? null );
        if ( empty( $row['reportLink']['href'] ) ) { continue; }
        $papers[] = array( 'item' => $item, 'title' => $row['label'], 'href' => $row['reportLink']['href'], 'image' => $image['src'] ?? '', 'meta' => $row['value'] );
    }
}
?>
<div class="vc-demo-module vc-demo-report" style="<?php echo esc_attr( $style ); ?>">
    <div class="vc-demo-wrap vc-demo-report-grid<?php echo $papers ? '' : ' vc-demo-report-grid--empty'; ?>">
        <header class="vc-demo-report-heading"><?php if ( '' !== $s['eyebrow'] ) : ?><p class="vc-demo-kicker"><?php echo esc_html( $s['eyebrow'] ); ?></p><?php endif; ?><h2><?php echo nl2br( esc_html( $s['title'] ) ); ?><?php if ( '' !== $s['titleAccent'] ) : ?><br><?php echo esc_html( $s['titleAccent'] ); ?><?php endif; ?></h2></header>
        <?php if ( $papers ) : ?><figure class="vc-demo-report-visual"><div class="vc-demo-report-stack<?php echo 1 === count( $papers ) ? ' vc-demo-report-stack--single' : ( count( $papers ) > 3 ? ' vc-demo-report-stack--many' : '' ); ?>">
            <?php foreach ( $papers as $index => $paper ) : ?>
                <a class="vc-demo-report-paper<?php echo empty( $paper['image'] ) ? ' vc-demo-report-paper--no-image' : ''; ?>" href="<?php echo esc_url( $paper['href'] ); ?>" target="_blank" rel="noopener noreferrer" data-vc-demo-report data-vc-report-title="<?php echo esc_attr( $paper['title'] ); ?>" data-vc-report-meta="<?php echo esc_attr( $paper['meta'] ); ?>" data-vc-report-context="<?php echo esc_attr( $s['cardNote'] ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Open %s', 'peptide-site' ), $paper['title'] ) ); ?>"<?php echo vc_demo_home_item_attrs( $paper['item'] ); ?>><?php if ( ! empty( $paper['image'] ) ) : ?><img src="<?php echo esc_url( $paper['image'] ); ?>" alt="<?php echo esc_attr( $paper['title'] ); ?>" loading="lazy" decoding="async"><?php else : ?><span><?php echo esc_html( $paper['title'] ); ?></span><?php endif; ?></a>
            <?php endforeach; ?>
        </div><?php if ( '' !== ( $s['previewCaption'] ?? '' ) ) : ?><figcaption><?php echo vc_icon( 'file' ); ?><?php echo esc_html( $s['previewCaption'] ); ?></figcaption><?php endif; ?></figure><?php endif; ?>
        <div class="vc-demo-report-copy">
            <?php if ( '' !== $s['description'] ) : ?><p class="vc-demo-report-intro"><?php echo nl2br( esc_html( $s['description'] ) ); ?></p><?php endif; ?>
            <?php $points = array_filter( array_map( 'trim', explode( "\n", $s['points'] ?? '' ) ) ); if ( $points ) : ?><div class="vc-demo-report-points"><?php foreach ( $points as $point ) : ?><span><?php echo esc_html( $point ); ?></span><?php endforeach; ?></div><?php endif; ?>
            <div class="vc-demo-report-actions"><?php echo vc_module_button( $s['button'], 'vc-demo-button vc-demo-button--white' ); ?><?php echo vc_module_button( $s['secondaryLink'] ?? null, 'vc-demo-quiet-link' ); ?></div>
            <?php if ( ( $s['showContext'] ?? true ) && ( '' !== $s['cardEyebrow'] || '' !== $s['cardNote'] ) ) : ?><p class="vc-demo-report-context"><?php if ( '' !== $s['cardEyebrow'] ) : ?><strong><?php echo esc_html( $s['cardEyebrow'] ); ?></strong><?php endif; ?><?php echo nl2br( esc_html( $s['cardNote'] ) ); ?></p><?php endif; ?>
        </div>
    </div>
</div>
