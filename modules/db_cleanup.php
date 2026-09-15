<?php
require_once '../includes/config.php';
$db = getDB();
$results = [];

function dropIfExists($db, $table, $col) {
    $exists = $db->query("SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table' AND COLUMN_NAME='$col'")->num_rows;
    if ($exists) {
        $db->query("ALTER TABLE `$table` DROP COLUMN `$col`");
        return "✅ Dropped: $table.$col";
    }
    return "⚪ Already gone: $table.$col";
}

// despatch_orders — legacy columns removed from UI
$results[] = dropIfExists($db, 'despatch_orders', 'delivery_type');
$results[] = dropIfExists($db, 'despatch_orders', 'remarks');

// items — already cleaned, just confirm
foreach (['unit_price','gst_rate','reorder_level','stock_qty','weight_per_unit'] as $col)
    $results[] = dropIfExists($db, 'items', $col);

echo "<h3>DB Cleanup Results</h3><ul>";
foreach ($results as $r) echo "<li>$r</li>";
echo "</ul>";

// Show final columns for key tables
echo "<h3>Final columns — despatch_orders</h3><pre>";
foreach ($db->query("SHOW COLUMNS FROM despatch_orders")->fetch_all(MYSQLI_ASSOC) as $r)
    echo $r['Field'] . " — " . $r['Type'] . "\n";
echo "</pre>";

echo "<h3>Final columns — items</h3><pre>";
foreach ($db->query("SHOW COLUMNS FROM items")->fetch_all(MYSQLI_ASSOC) as $r)
    echo $r['Field'] . " — " . $r['Type'] . "\n";
echo "</pre>";
