<?php
require_once '../includes/config.php';
$db = getDB();

echo "<h3>All agent_commissions rows:</h3><pre>";
$rows = $db->query("SELECT ac.*, u.full_name FROM agent_commissions ac LEFT JOIN app_users u ON ac.agent_id=u.id ORDER BY ac.agent_id, ac.id")->fetch_all(MYSQLI_ASSOC);
foreach ($rows as $r) {
    echo "id={$r['id']} despatch_id={$r['despatch_id']} agent={$r['full_name']} challan={$r['challan_no']} amt={$r['commission_amt']} status={$r['status']}\n";
}
echo "</pre>";

echo "<h3>Summary per agent:</h3><pre>";
$sum = $db->query("SELECT u.full_name, COUNT(ac.id) AS cnt, SUM(ac.commission_amt) AS total FROM app_users u JOIN agent_commissions ac ON ac.agent_id=u.id AND ac.status='Pending' GROUP BY u.id")->fetch_all(MYSQLI_ASSOC);
foreach ($sum as $s) echo "{$s['full_name']}: {$s['cnt']} records, total=₹{$s['total']}\n";
echo "</pre>";
