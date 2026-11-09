<?php
$bac = <<<'HTML'
<div><h3>product description</h3>
<h3>📦 <strong>Full Product Description</strong></h3>
<blockquote><p><strong>Product Name:</strong> Bacteriostatic Water (bac.water)<br><strong>Specification:</strong> 1 box / 10 vials × 10 mL<br><strong>Composition:</strong> Sterile water with 0.9% benzyl alcohol<br><strong>Form:</strong> Clear, colorless liquid<br><strong>Storage:</strong> Store sealed at room temperature, away from light</p></blockquote>
<p><strong>For laboratory research use only. Not for human or veterinary use.</strong></p>
<p>🔬 <strong>Overview:</strong><br>Bacteriostatic water is sterile water containing 0.9% benzyl alcohol as a preservative. The benzyl alcohol limits bacterial growth after the stopper is punctured, which makes it the standard diluent for reconstituting lyophilized research peptides that will be drawn from the same vial several times.</p>
<p>💡 <strong>Typical Research Applications:</strong></p>
<ul><li><p>Reconstituting lyophilized research peptides</p></li><li><p>Multi-draw vials used over several weeks in the lab</p></li><li><p>Preparing working dilutions for analytical and stability testing</p></li></ul>
<h2>How Bacteriostatic Water Is Used</h2>
<p>Let the peptide vial reach room temperature and wipe both stoppers with an alcohol pad. Draw the required volume with a sterile syringe and let the water run slowly down the inside wall of the peptide vial instead of spraying it onto the powder. Gently swirl or roll the vial until the powder has dissolved; do not shake.</p>
<p>Example: adding 2 mL to a 10 mg vial gives a concentration of 5 mg/mL.</p>
<h2>Storage</h2>
<p>Unopened vials can be kept sealed at room temperature, protected from light, until the expiry date on the label. Once the stopper has been punctured, keep the vial refrigerated at 2-8°C and use it within 28 days. Peptides reconstituted with bacteriostatic water are typically stored at 2-8°C and used within 30 days.</p>
<h2>FAQ</h2>
<h3>What is the difference between bacteriostatic water and sterile water?</h3>
<p>Both are sterile, but bacteriostatic water contains 0.9% benzyl alcohol, so a punctured vial can be drawn from repeatedly for up to 28 days. Plain sterile water has no preservative and should be used soon after opening.</p>
<h3>Is bacteriostatic water suitable for cell culture work?</h3>
<p>Benzyl alcohol can affect cultured cells. For cell-based assays, preservative-free sterile water is usually the better choice.</p>
<h3>Can I use it for every peptide?</h3>
<p>It works for most lyophilized peptides. A few peptides dissolve better in a slightly acidic diluent such as 0.6% acetic acid water; check the handling notes for the specific material.</p>
<h3>What if the water looks cloudy or contains particles?</h3>
<p>Do not use it. Bacteriostatic water should always be clear and colorless. Contact support with a photo and the lot number.</p></div>
HTML;
$acetic = <<<'HTML'
<div><h3>product description</h3>
<h3>📦 <strong>Full Product Description</strong></h3>
<blockquote><p><strong>Product Name:</strong> Acetic Acid Water 0.6%<br><strong>Specification:</strong> 1 box / 10 vials × 10 mL<br><strong>Composition:</strong> Sterile water with 0.6% acetic acid<br><strong>Form:</strong> Clear, colorless liquid<br><strong>Storage:</strong> Store sealed at room temperature, away from light</p></blockquote>
<p><strong>For laboratory research use only. Not for human or veterinary use.</strong></p>
<p>🔬 <strong>Overview:</strong><br>Acetic acid water 0.6% is sterile water with a small amount of acetic acid, giving it a mildly acidic pH. Some lyophilized research peptides dissolve slowly or incompletely in neutral water; a slightly acidic diluent helps them go into solution cleanly and can improve stability for those materials.</p>
<p>💡 <strong>Typical Research Applications:</strong></p>
<ul><li><p>Reconstituting peptides that dissolve poorly in neutral water</p></li><li><p>Preparing acidic stock solutions before further dilution</p></li><li><p>Analytical and stability work that calls for a low-pH diluent</p></li></ul>
<h2>How Acetic Acid Water Is Used</h2>
<p>Let the peptide vial reach room temperature and wipe both stoppers with an alcohol pad. Draw the required volume with a sterile syringe and add it slowly down the inside wall of the peptide vial. Gently swirl until dissolved; do not shake. If needed, the acidic stock can then be diluted further with bacteriostatic water.</p>
<h2>Storage</h2>
<p>Unopened vials can be kept sealed at room temperature, protected from light, until the expiry date on the label. Acetic acid water contains no antibacterial preservative, so once the stopper has been punctured keep the vial at 2-8°C, use sterile technique for every draw, and use it promptly.</p>
<h2>FAQ</h2>
<h3>When should I use acetic acid water instead of bacteriostatic water?</h3>
<p>Use it for peptides whose handling notes call for a slightly acidic diluent, or when a peptide does not dissolve fully in bacteriostatic water. For most peptides, bacteriostatic water is the standard choice.</p>
<h3>Will 0.6% acetic acid damage the peptide?</h3>
<p>At 0.6% the solution is only mildly acidic and is a common diluent for acid-soluble peptides. Follow the handling notes for the specific material.</p>
<h3>What if the liquid looks cloudy?</h3>
<p>Do not use it. The solution should be clear and colorless. Contact support with a photo and the lot number.</p></div>
HTML;
foreach ( array( '692429833935912964' => $bac, '692428469203632135' => $acetic ) as $sku => $html ) {
	$id = wc_get_product_id_by_sku( $sku );
	$p  = $id ? wc_get_product( $id ) : null;
	if ( ! $p ) { echo "  找不到 $sku\n"; continue; }
	if ( '' === (string) $p->get_meta( '_pc_desc_backup_20261110' ) ) { $p->update_meta_data( '_pc_desc_backup_20261110', $p->get_description() ); }
	$p->set_description( $html );
	$p->save();
	echo '  ' . html_entity_decode( $p->get_name() ) . ' 描述已更新（' . strlen( wp_strip_all_tags( $html ) ) . " 字）\n";
}
