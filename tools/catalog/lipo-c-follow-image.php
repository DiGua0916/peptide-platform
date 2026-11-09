<?php
// 2026-11-09: Lipo-C LC120 description follows the product image (L-Methionine, Inositol, Choline chloride, L-Carnitine);
// the three new Lipo-C products use the image's 10 mL vial size in their specification.
$lc120 = <<<'HTML'
<p><strong>Catalog No.: LC120 | Product Name: Lipo‑C</strong> LC120 is a multi‑component lipotropic research blend supplied in a 10 mL sterile vial. This B12‑free formulation consists of L‑Methionine, Inositol, Choline Chloride and L‑Carnitine, designed for laboratory study of lipid transport, methylation pathways and hepatic metabolic models.</p>
<p>Lipotropic blend:</p>
<ul>
<li>L‑Methionine: 15 mg/mL</li>
<li>Inositol</li>
<li>Choline Chloride: 50 mg/mL</li>
<li>L‑Carnitine: 50 mg/mL</li>
</ul>
<p>This research reagent is strictly for in‑vitro laboratory evaluation. No medical or therapeutic claims apply. Not a drug, not a dietary supplement.</p>
<p><strong>Specifications</strong></p>
<ul>
<li>Format: 10 mL glass vial, 10 vials / box</li>
<li>Form: Sterile solution</li>
<li>Purity: ≥99.5% HPLC</li>
<li>Storage: Refrigerate 2‑8°C, keep sealed, protect from light</li>
</ul>
HTML;
$spec = '10mL*10vials';
foreach ( array( 'lipo-c', 'lipo-c_145f9f18', 'lipo-c_6fac2602' ) as $slug ) {
	$post = get_page_by_path( $slug, OBJECT, 'product' );
	if ( ! $post ) { echo "MISSING $slug\n"; continue; }
	$p = wc_get_product( $post->ID );
	if ( 'lipo-c_6fac2602' === $slug ) { $p->set_description( $lc120 ); }
	// a fresh attribute object: editing the existing one in place is not saved by WooCommerce
	$a = new WC_Product_Attribute(); $a->set_name( 'Specifications' ); $a->set_options( array( $spec ) ); $a->set_visible( true ); $a->set_variation( true ); $a->set_position( 0 );
	$p->set_attributes( array( $a ) );
	$p->set_default_attributes( array( 'specifications' => $spec ) );
	$p->save();
	foreach ( $p->get_children() as $c ) { $v = wc_get_product( $c ); $v->set_attributes( array( 'specifications' => $spec ) ); $v->save(); }
	WC_Product_Variable::sync( $p->get_id() ); wc_delete_product_transients( $p->get_id() );
	echo "updated #{$p->get_id()} {$p->get_name()}\n";
}
if ( class_exists( 'PeptideCore\Page_Cache' ) ) PeptideCore\Page_Cache::flush();
