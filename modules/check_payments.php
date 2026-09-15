<?php
require_once '../includes/config.php';
$db = getDB();

echo "<h3>agent_commission_payments:</h3><pre>";
$rows = $db->query("SELECT p.*, u.full_name FROM agent_commission_payments p JOIN app_users u ON p.agent_id=u.id ORDER BY p.id")->fetch_all(MYSQLI_ASSOC);
foreach ($rows as $r) echo "pay_id={$r['id']} agent={$r['full_name']} amount=₹{$r['amount']} date={$r['paid_date']} ref={$r['reference']}\n";
echo "</pre>";

echo "<h3>agent_payment_commissions (links):</h3><pre>";
$rows = $db->query("SELECT apc.*, ac.challan_no, ac.commission_amt, ac.status FROM agent_payment_commissions apc JOIN agent_commissions ac ON ac.id=apc.commission_id ORDER BY apc.payment_id")->fetch_all(MYSQLI_ASSOC);
foreach ($rows as $r) echo "pay_id={$r['payment_id']} comm_id={$r['commission_id']} challan={$r['challan_no']} amt=₹{$r['commission_amt']} status={$r['status']}\n";
echo "</pre>";

echo "<h3>agent_commissions (all):</h3><pre>";
$rows = $db->query("SELECT ac.*, u.full_name FROM agent_commissions ac JOIN app_users u ON ac.agent_id=u.id ORDER BY ac.id")->fetch_all(MYSQLI_ASSOC);
foreach ($rows as $r) echo "id={$r['id']} challan={$r['challan_no']} amt=₹{$r['commission_amt']} status={$r['status']} agent={$r['full_name']}\n";
echo "</pre>";
