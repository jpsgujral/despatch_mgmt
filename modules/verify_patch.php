<?php
$file = __DIR__ . '/fleet_expenses.php';
$c    = file_get_contents($file);

echo "File size: " . strlen($c) . "\n";
echo "Has 'Expense updated WITH': " . (strpos($c, 'Expense updated WITH') !== false ? 'YES' : 'NO') . "\n";
echo "Has 'NO IMAGE SAVED': " . (strpos($c, 'NO IMAGE SAVED') !== false ? 'YES' : 'NO') . "\n";
echo "Has 'INJECT CHECK': " . (strpos($c, 'INJECT CHECK') !== false ? 'YES' : 'NO') . "\n";
echo "Has 'bill_img_sql': " . (strpos($c, 'bill_img_sql') !== false ? 'YES' : 'NO') . "\n";
echo "Last modified: " . date('Y-m-d H:i:s', filemtime($file)) . "\n";
echo "Writable: " . (is_writable($file) ? 'YES' : 'NO') . "\n";
