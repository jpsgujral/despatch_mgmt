<?php
require_once '../includes/config.php';
$db = getDB();
$db->query("UPDATE agent_commissions SET status='Pending' WHERE id IN (33,36,38,39)");
echo "Done. Affected: " . $db->affected_rows . "<br>";
$all = $db->query("SELECT id, challan_no, commission_amt, status FROM agent_commissions ORDER BY id")->fetch_all(MYSQLI_ASSOC);
echo "<pre>";
foreach ($all as $r) echo "id={$r['id']} {$r['challan_no']} ₹{$r['commission_amt']} {$r['status']}\n";
echo "</pre>";
