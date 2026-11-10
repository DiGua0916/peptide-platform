<?php
// TR20+RT40 描述原为空（导入表里也是空），2026-11-10 按两种水同样的版式补写；旧值存 _pc_desc_backup_20261110。
$html = <<<'HTML'
<div><h3>product description</h3>
<h3>📦 <strong>Full Product Description</strong></h3>
<blockquote><p><strong>Product Name:</strong> TR20+RT40 (Tirzepatide 20 mg + Retatrutide 40 mg Blend)<br><strong>Specification:</strong> 1 box / 10 vials, 60 mg per vial (Tirzepatide 20 mg + Retatrutide 40 mg)<br><strong>Form:</strong> White to off-white lyophilized powder<br><strong>Storage:</strong> Unopened at -20°C, sealed and protected from light</p></blockquote>
<p><strong>For laboratory research use only. Not for human or veterinary use.</strong></p>
<p>🔬 <strong>Overview:</strong><br>TR20+RT40 combines two incretin-class research peptides in one vial. Tirzepatide is a dual GIP / GLP-1 receptor agonist. Retatrutide is a triple agonist that also acts on the glucagon receptor. The blend lets researchers study dual and triple incretin signalling side by side in metabolic models without preparing two separate vials.</p>
<p><strong>Shorthand:</strong> TR = Tirzepatide, RT = Retatrutide. The numbers are the milligrams of each peptide per vial.</p>
<h2>Components</h2>
<ul><li><p><strong>Tirzepatide</strong> — CAS 2023788-19-2, molecular weight about 4813 Da. Dual GIP / GLP-1 receptor agonist.</p></li><li><p><strong>Retatrutide</strong> — CAS 2381089-83-2, molecular weight about 4731 Da. Triple GIP / GLP-1 / glucagon receptor agonist.</p></li></ul>
<p>💡 <strong>Typical Research Applications:</strong></p>
<ul><li><p>Metabolic and energy-balance studies in preclinical models</p></li><li><p>Comparing dual (GIP / GLP-1) and triple (GIP / GLP-1 / glucagon) receptor signalling</p></li><li><p>Glucose homeostasis and insulin sensitivity research</p></li><li><p>Lipid metabolism and body-composition research</p></li></ul>
<h2>Reconstitution</h2>
<p>Let the vial reach room temperature and wipe the stopper with an alcohol pad. Add bacteriostatic water slowly down the inside wall of the vial instead of spraying it onto the powder. Gently swirl or roll until fully dissolved; do not shake. The solution should be clear and colorless.</p>
<p>Example: adding 3 mL of bacteriostatic water gives 20 mg/mL in total, which is about 6.67 mg/mL Tirzepatide and 13.33 mg/mL Retatrutide.</p>
<h2>Storage</h2>
<p>Keep unopened vials sealed at -20°C, protected from light and moisture. After reconstitution, keep the vial at 2-8°C, use it within 28 days, and do not freeze the solution.</p>
<h2>FAQ</h2>
<h3>Why is the ratio 20 mg + 40 mg?</h3>
<p>The blend holds twice as much Retatrutide as Tirzepatide, so a single draw contains both peptides at a fixed 1:2 ratio. If your protocol needs a different ratio, use the single-peptide vials instead.</p>
<h3>Which diluent should I use?</h3>
<p>Bacteriostatic water is the standard choice and allows several draws from one vial. It is sold separately in our store.</p>
<h3>What if the solution is cloudy after mixing?</h3>
<p>Do not use it. Contact support with a photo and the lot number.</p></div>
HTML;
$id = wc_get_product_id_by_sku( '692425308128935943' );
$p  = $id ? wc_get_product( $id ) : null;
if ( ! $p ) { echo "  找不到 TR20+RT40\n"; return; }
if ( '' === (string) $p->get_meta( '_pc_desc_backup_20261110' ) ) { $p->update_meta_data( '_pc_desc_backup_20261110', (string) $p->get_description() ); }
$p->set_description( $html );
$p->save();
echo '  #' . $id . ' ' . html_entity_decode( $p->get_name() ) . ' 描述已更新（' . strlen( wp_strip_all_tags( $html ) ) . " 字）\n";
