<?php
/** Exact new helper capability alongside actual old native-search configuration. */
$argv[1]='enabled';
spl_autoload_register(static function($class){if($class==='PeptideCore\\Tracking\\Product_Actions'){require dirname(__DIR__,3).'/plugins/peptide-core/includes/tracking/class-product-actions.php';}});
require __DIR__.'/visitor-native-search-config.php';
if(($config046['product_actions_protocol']??null)!==1){throw new RuntimeException('Action protocol must be advertised as exact integer one.');}
echo "PASS 1 actual theme product action capability assertion\n";
