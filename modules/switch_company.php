<?php
require_once '../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
$db = getDB();

$cid = (int)($_GET['id'] ?? 0);
$ret = $_GET['ret'] ?? 'companies.php';

if ($cid > 0) {
    $co = $db->query("SELECT id,company_name FROM companies WHERE id=$cid LIMIT 1")->fetch_assoc();
    if ($co) {
        $_SESSION['active_company_id']   = (int)$co['id'];
        $_SESSION['active_company_name'] = $co['company_name'];
        showAlert('success', '✓ Switched to <strong>'.htmlspecialchars($co['company_name']).'</strong>');
    }
}
redirect($ret);
