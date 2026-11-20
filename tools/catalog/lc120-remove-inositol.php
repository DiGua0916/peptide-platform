<?php
// 2026-11-20: owner confirmed LC120 contains no Inositol — drop it from the description on every store.
$post = get_page_by_path( 'lipo-c_6fac2602', OBJECT, 'product' );
if ( ! $post ) { echo "MISSING lipo-c_6fac2602\n"; return; }
$p   = wc_get_product( $post->ID );
$old = $p->get_description();
$new = str_replace(
	array( "<li>Inositol</li>\n", '<li>Inositol</li>', 'L‑Methionine, Inositol, Choline Chloride and L‑Carnitine' ),
	array( '', '', 'L‑Methionine, Choline Chloride and L‑Carnitine' ),
	$old
);
if ( $new === $old ) { echo "#{$p->get_id()} unchanged\n"; }
else { $p->set_description( $new ); $p->save(); wc_delete_product_transients( $p->get_id() ); echo "#{$p->get_id()} updated\n"; }
echo 'inositol left: ' . substr_count( strtolower( $p->get_description() ), 'inositol' ) . "\n";
if ( class_exists( 'PeptideCore\Page_Cache' ) ) PeptideCore\Page_Cache::flush();
