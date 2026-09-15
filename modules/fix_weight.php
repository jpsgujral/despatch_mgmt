<?php
// Path: /home/tsgimpex/public_html/despatch_mgmt/modules/fix_weight.php
require_once '../includes/config.php';
require_once '../includes/auth.php';

if (ob_get_level() > 0) ob_clean();
header('Content-Type: application/json');

if (!isset($_GET['ajax']) || $_GET['ajax'] !== 'update_weight' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success'=>false,'error'=>'Invalid request.']);
    exit;
}

if (!isAdmin()) {
    echo json_encode(['success'=>false,'error'=>'Admin only.']);
    exit;
}

$tid = (int)($_POST['trip_id'] ?? 0);
$wt  = (float)($_POST['total_weight'] ?? 0);

if (!$tid || $wt <= 0) {
    echo json_encode(['success'=>false,'error'=>'Invalid trip or weight must be > 0.']);
    exit;
}

$db2 = getDB();

$db2->query("UPDATE fleet_trips SET total_weight=$wt WHERE id=$tid AND status='Completed'");

$res        = $db2->query("SELECT id, unit_price FROM fleet_trip_items WHERE trip_id=$tid ORDER BY id ASC");
$items      = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
$item_count = count($items);

if ($item_count > 0) {
    $per_item_wt = round($wt / $item_count, 3);
    $remaining   = $wt;

    foreach ($items as $idx => $item) {
        $row_wt    = ($idx === $item_count - 1) ? round($remaining, 3) : $per_item_wt;
        $remaining = round($remaining - $per_item_wt, 3);
        $price     = (float)$item['unit_price'];
        $amount    = round($row_wt * $price, 2);
        $iid       = (int)$item['id'];
        $db2->query("UPDATE fleet_trip_items SET weight=$row_wt, qty=$row_wt, amount=$amount WHERE id=$iid");
    }

    $totals    = $db2->query("SELECT SUM(amount) as ta FROM fleet_trip_items WHERE trip_id=$tid")->fetch_assoc();
    $new_total = round((float)($totals['ta'] ?? 0), 2);
    $db2->query("UPDATE fleet_trips SET subtotal=$new_total, total_amount=$new_total WHERE id=$tid");
}

echo json_encode(['success'=>true,'weight'=>$wt,'items_updated'=>$item_count]);
exit;
