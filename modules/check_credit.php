<?php
require_once '../includes/config.php';
$db = getDB();

// Check column exists
$col = $db->query("SELECT 1 FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transporters' AND COLUMN_NAME='credit_days'")->num_rows;
echo "credit_days column exists: " . ($col ? "YES" : "NO") . "<br>";

// Show current values
$rows = $db->query("SELECT id, transporter_name, credit_days FROM transporters ORDER BY transporter_name")->fetch_all(MYSQLI_ASSOC);
echo "<pre>";
foreach ($rows as $r) echo "id={$r['id']} {$r['transporter_name']}: credit_days={$r['credit_days']}\n";
echo "</pre>";
