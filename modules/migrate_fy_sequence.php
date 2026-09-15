<?php
require_once '../includes/config.php';
$db = getDB();

$m = (int)date('m'); $y = (int)date('Y');
$fs = $m >= 4 ? $y : $y - 1; $fe = $fs + 1;
$fy = str_pad($fs%100,2,'0',STR_PAD_LEFT).str_pad($fe%100,2,'0',STR_PAD_LEFT);
$key = "challan_fy{$fy}";

$db->query("CREATE TABLE IF NOT EXISTS doc_sequences (seq_key VARCHAR(50) PRIMARY KEY, last_val INT UNSIGNED NOT NULL DEFAULT 0)");

$mx = $db->query("SELECT MAX(CAST(SUBSTRING_INDEX(challan_no,'/',-1) AS UNSIGNED)) mx FROM despatch_orders WHERE challan_no LIKE 'DC/%'")->fetch_assoc()['mx'];
$seed = (int)($mx ?? 0);

$db->query("INSERT INTO doc_sequences (seq_key,last_val) VALUES ('$key',$seed) ON DUPLICATE KEY UPDATE last_val=GREATEST(last_val,$seed)");

// Safe column add
$exists = $db->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='company_settings' AND COLUMN_NAME='fy_start_no'")->num_rows;
if (!$exists) $db->query("ALTER TABLE company_settings ADD COLUMN fy_start_no INT DEFAULT 1");

echo "<strong>Migration Done</strong><br>";
echo "FY: 20{$fy}<br>";
echo "Seeded to: {$seed}<br>";
echo "Next challan: DC/{$fy}/" . str_pad($seed+1,4,'0',STR_PAD_LEFT) . "<br>";
$seq = $db->query("SELECT * FROM doc_sequences WHERE seq_key='$key'")->fetch_assoc();
echo "doc_sequences: {$seq['seq_key']} = {$seq['last_val']}";
