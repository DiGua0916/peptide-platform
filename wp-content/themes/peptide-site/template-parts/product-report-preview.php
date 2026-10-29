<?php
defined( 'ABSPATH' ) || exit;
// Presentation placeholder only. Never implies a published or downloadable report.
?>
<span class="product-report-preview" aria-hidden="true">
    <span class="product-report-sheet">
        <span class="product-report-logo"><?php vc_brand_logo( true ); ?></span>
        <span class="product-report-title">Certificate<br>of analysis</span>
        <span class="product-report-name"><?php echo esc_html( $args['name'] ?? '' ); ?></span>
        <span class="product-report-rows">
            <span><span>Test</span><span>Result</span></span>
            <span><span>Identity</span><span>—</span></span>
            <span><span>Purity</span><span>—</span></span>
        </span>
        <span class="product-report-disclosure"><strong>Preview only</strong><span>No test data</span></span>
    </span>
    <span class="product-report-caption">Report placeholder</span>
</span>
