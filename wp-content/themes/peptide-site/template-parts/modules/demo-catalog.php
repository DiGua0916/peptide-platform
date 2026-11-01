<?php
defined( 'ABSPATH' ) || exit;
$m = $args['module']; $s = $m['settings'];
$products = vc_demo_catalog_products( $m ); $terms = array(); $rows = array();
foreach ( $products as $p ) {
    if ( ! \PeptideCore\Modules\Products::public_catalog_product( $p ) ) { continue; }
    $categories = get_the_terms( $p->get_id(), 'product_cat' ); $ids = array();
    foreach ( is_array( $categories ) ? $categories : array() as $t ) { $ids[] = $t->term_id; $terms[$t->term_id] = $t; }
    $display = vc_card_display_product( $p );
    $specs = $display->is_type( 'variation' ) ? wc_get_formatted_variation( $display, true, false, false ) : '';
    $search = $p->get_name() . ' ' . $p->get_sku() . ' ' . $specs;
    if ( $p->is_type( 'variable' ) ) { foreach ( $p->get_variation_attributes() as $values ) { $search .= ' ' . implode( ' ', $values ); } }
    $rows[] = array( $p, $ids, $search );
}
$uid = 'vc-catalog-' . $m['id'];
?>
<div class="vc-demo-catalog vc-demo-wrap" data-vc-catalog data-page-size="<?php echo (int) $s['demoPageSize']; ?>">
 <header class="vc-demo-catalog__heading"><div><?php if ( $s['eyebrow'] ) : ?><p class="vc-demo-kicker"><?php echo esc_html( $s['eyebrow'] ); ?></p><?php endif; ?><h2><?php echo esc_html( $s['title'] ); ?></h2><?php if ( $s['description'] ) : ?><p><?php echo esc_html( $s['description'] ); ?></p><?php endif; ?></div><?php echo vc_module_button( $s['link'], 'vc-demo-catalog__all' ); ?></header>
 <div class="vc-demo-catalog__toolbar" hidden data-vc-catalog-tools>
  <div class="vc-demo-catalog__filters" role="group" aria-label="Filter products"><button type="button" data-vc-category="all" aria-pressed="true">All peptides</button><?php foreach ( $terms as $t ) : ?><button type="button" data-vc-category="<?php echo (int) $t->term_id; ?>" aria-pressed="false"><?php echo esc_html( $t->name ); ?></button><?php endforeach; ?></div>
  <label class="vc-demo-catalog__search" for="<?php echo esc_attr( $uid ); ?>"><?php echo vc_icon( 'search' ); ?><input id="<?php echo esc_attr( $uid ); ?>" data-vc-catalog-search type="search" placeholder="<?php echo esc_attr( $s['demoSearchLabel'] ); ?>" aria-label="<?php echo esc_attr( $s['demoSearchLabel'] ); ?>"></label>
 </div>
 <p class="vc-demo-catalog__status" role="status" data-vc-catalog-status hidden></p>
 <div class="vc-demo-catalog__grid" style="<?php echo esc_attr( '--vc-demo-cols:' . (int) $s['columns']['desktop'] . ';--vc-demo-cols-mobile:' . (int) ( $s['columns']['mobile'] ?? 2 ) ); ?>">
 <?php foreach ( $rows as [ $p, $ids, $search ] ) : ?>
  <div class="vc-demo-catalog__item" data-vc-catalog-item data-categories="<?php echo esc_attr( implode( ',', $ids ) ); ?>" data-search="<?php echo esc_attr( $search ); ?>">
   <?php
   ob_start(); vc_product_card( $p, false, null, '(max-width:767px) 46vw, 24vw', true ); $card = ob_get_clean();
   $tags = new WP_HTML_Tag_Processor( $card );
   while ( $tags->next_tag( 'A' ) ) {
       if ( ! $tags->get_attribute( 'href' ) ) { continue; }
       $tags->set_attribute( 'data-vc-home-product', (string) $p->get_id() );
       if ( \PeptideCore\Modules\Showcase_Quick_View::supports( $p ) ) { $tags->set_attribute( 'data-vc-quick-open', (string) $p->get_id() ); }
   }
   echo $tags->get_updated_html();
   // Native quick-view forms load on demand; each card retains its full product URL.
   ?>
  </div>
 <?php endforeach; ?>
 </div>
 <?php if ( ! $rows ) : ?><p><?php echo esc_html( $s['emptyText'] ); ?></p><?php endif; ?>
 <div class="vc-demo-catalog__bottom"><p><?php echo esc_html( $s['demoPriceNote'] ); ?></p><button type="button" data-vc-catalog-more hidden><?php echo esc_html( $s['demoMoreLabel'] ); ?> <?php echo vc_icon(); ?></button></div>
</div>
